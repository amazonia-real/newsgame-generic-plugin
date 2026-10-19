<?php
defined( 'ABSPATH' ) || exit;

class Newsgame_DB {

    /**
     * Versão interna do schema — incrementar aqui ao adicionar colunas.
     * Independente da versão do plugin: só muda quando o banco muda.
     */
    const SCHEMA_VERSION = '1.10';

    /* ── Ponto de entrada público ──────────────────────────── */

    /**
     * Chamado no activation_hook (nova instalação) e no admin_init
     * (atualização sem reativação). Idempotente: não faz nada se
     * o schema já estiver na versão correta.
     */
    public static function maybe_upgrade() {
        if ( get_option( 'newsgame_schema_version' ) === self::SCHEMA_VERSION ) {
            return;
        }
        self::ensure_schema();
        update_option( 'newsgame_schema_version', self::SCHEMA_VERSION );
    }

    /**
     * Chamado apenas no activation_hook para instalações do zero.
     * Delega para ensure_schema() que é o único ponto de verdade.
     */
    public static function create_tables() {
        self::ensure_schema();
        update_option( 'newsgame_schema_version', self::SCHEMA_VERSION );
    }

    /* ── Núcleo: cria tabelas e garante colunas ────────────── */

    private static function ensure_schema() {
        global $wpdb;

        $dilemas   = $wpdb->prefix . 'newsgame_dilemas';
        $instances = $wpdb->prefix . 'newsgame_instances';

        // Se as tabelas ainda não existem, cria tudo via dbDelta
        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '$dilemas'" ) ) {
            self::run_dbdelta();
            return;
        }

        // Tabelas existem: garante colunas novas com ALTER TABLE direto
        // wp_newsgame_instances
        self::add_column_if_missing(
            $instances,
            'num_options',
            "TINYINT NOT NULL DEFAULT 2 COMMENT '2 ou 3 opcoes de resposta' AFTER `density`"
        );
        // LEGADO desde 09/10 (ver o comentário do CREATE): continua sendo criada
        // porque o `AFTER enable_levels` do nivel_padrao, logo abaixo, depende dela.
        self::add_column_if_missing(
            $instances,
            'enable_levels',
            "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0=nivel unico, 1=Aprendiz/Pro' AFTER `num_options`"
        );
        self::add_column_if_missing(
            $instances,
            'nivel_padrao',
            "VARCHAR(20) NOT NULL DEFAULT 'pedagogico' COMMENT 'ludico | pedagogico | complexo' AFTER `enable_levels`"
        );
        self::add_column_if_missing(
            $instances,
            'axis_matrix',
            "LONGTEXT NULL COMMENT 'JSON: eixos e perfis propostos por /analyze-domain' AFTER `nivel_padrao`"
        );
        /*
         * Skin da instância — H2, degrau 2 (suporte de schema apenas).
         *
         * Escalar VARCHAR com slug, e não JSON: o valor é um item de whitelist
         * fechada, e coluna opaca tornaria a inspeção de banco — que é o método
         * de validação deste projeto — mais difícil. Mesmo critério que decidiu
         * o `image_id` escalar no degrau 1.
         *
         * A whitelist vive em Newsgame_Admin_Instances::SKINS. O DEFAULT abaixo
         * repete o literal 'default' porque schema é texto SQL e não alcança
         * constante de PHP — as duas pontas têm de ser mudadas juntas.
         *
         * ORDEM IMPORTA: o `AFTER axis_matrix` exige que aquela coluna já
         * exista. Numa base que não tinha nenhuma das duas, ambas nascem no
         * mesmo carregamento do admin, nesta ordem — por isso esta chamada vem
         * depois da de axis_matrix, e não antes.
         */
        self::add_column_if_missing(
            $instances,
            'skin',
            "VARCHAR(64) NOT NULL DEFAULT 'default' COMMENT 'Slug da skin de fabrica: whitelist em Newsgame_Admin_Instances::SKINS' AFTER `axis_matrix`"
        );
        /*
         * N de perguntas sorteadas por partida (etapa 1, schema 1.7). O `density`
         * passa a ser só a meta do conjunto de aprovadas.
         *
         * As instâncias que já existem recebem round_size = density, para o jogo
         * não mudar, limitado a 4..15: o density antigo pode estar fora dessa
         * faixa (o CREATE tinha DEFAULT 20), e acima de 255 o UPDATE inteiro
         * falharia no TINYINT. O preenchimento roda SÓ quando o ALTER acabou de
         * criar a coluna: add_column_if_missing() devolve true apenas nesse caso.
         * Uma segunda execução encontra a coluna e não toca nas linhas — nem nas
         * editadas depois.
         */
        $round_size_nova = self::add_column_if_missing(
            $instances,
            'round_size',
            "TINYINT UNSIGNED NOT NULL DEFAULT 10 COMMENT 'Perguntas sorteadas por partida' AFTER `density`"
        );
        if ( $round_size_nova ) {
            $wpdb->query( "UPDATE `$instances` SET round_size = LEAST(15, GREATEST(4, density))" );
            if ( $wpdb->last_error ) {
                error_log( "[Newsgame_DB] Preenchimento de round_size FALHOU: " . $wpdb->last_error );
            }
        }
        /*
         * Receita da partida (item 58, schema 1.8). `gerais_pct` é a fração de
         * gerais numa partida de instância COM personagens (0 a 50); `pronto_fator`
         * é quantas vezes o que um grupo põe na partida ele precisa ter aprovado
         * para ficar Pronto (mínimo 1,6). A conta está em Newsgame_Status::receita().
         * Sem preenchimento: o DEFAULT já é o valor decidido para todas.
         */
        self::add_column_if_missing(
            $instances,
            'gerais_pct',
            "TINYINT UNSIGNED NOT NULL DEFAULT 50 COMMENT 'Gerais na partida, em % (0 a 50); so vale com personagens' AFTER `round_size`"
        );
        self::add_column_if_missing(
            $instances,
            'pronto_fator',
            "DECIMAL(3,1) NOT NULL DEFAULT 1.6 COMMENT 'Aprovadas por grupo = teto(o que ele poe na partida x fator)' AFTER `gerais_pct`"
        );
        /*
         * Capa e regras (H3, rodada A, schema 1.9). Todos opcionais: vazio (ou
         * NULL nas imagens) = a capa de antes, sem tela de regras. As imagens são
         * IDs de anexo da Biblioteca de Mídia, como o `image_id` do dilema; o
         * shortcode resolve a URL na leitura. Sem preenchimento: o DEFAULT é o
         * "vazio" para todas as linhas que já existem.
         */
        $capa = array(
            'capa_titulo'     => "VARCHAR(200) NOT NULL DEFAULT '' COMMENT 'Titulo da capa; vazio = titulo da instancia'",
            'capa_linha_fina' => "VARCHAR(300) NOT NULL DEFAULT '' COMMENT 'Linha-fina da capa'",
            'capa_imagem_id'  => "BIGINT UNSIGNED NULL COMMENT 'Anexo do WP: foto decorativa da capa'",
            'regras'          => "TEXT NULL COMMENT 'Texto simples da tela Regras; vazio = sem tela'",
            'logo_id'         => "BIGINT UNSIGNED NULL COMMENT 'Anexo do WP: logo do apoio'",
            'apoio_texto'     => "VARCHAR(200) NOT NULL DEFAULT '' COMMENT 'Texto curto do apoio; alt do logo'",
            'apoio_url'       => "VARCHAR(2048) NOT NULL DEFAULT '' COMMENT 'Link opcional do apoio'",
        );
        $depois = 'pronto_fator';
        foreach ( $capa as $coluna => $definicao ) {
            self::add_column_if_missing( $instances, $coluna, "$definicao AFTER `$depois`" );
            $depois = $coluna;
        }
        /*
         * Interruptor da tela de personagem (H3, rodada B, schema 1.10). Desligado
         * por padrão: o jogo sorteia o personagem entre os Prontos, como antes. A
         * apresentação de cada personagem NÃO tem coluna: mora no JSON de
         * `characters`, ao lado do nome e da descrição para a IA.
         */
        self::add_column_if_missing(
            $instances,
            'escolher_personagem',
            "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = o jogador escolhe o personagem numa tela' AFTER `apoio_url`"
        );

        // wp_newsgame_dilemas
        self::add_column_if_missing(
            $dilemas,
            'character_slug',
            "VARCHAR(100) NOT NULL DEFAULT 'geral' COMMENT 'slug do personagem' AFTER `level`"
        );
        self::add_column_if_missing(
            $dilemas,
            'option_c',
            "LONGTEXT NULL COMMENT 'JSON: {texto, consequencia} - presente se num_options=3' AFTER `option_b`"
        );
        self::add_column_if_missing(
            $dilemas,
            'axis_weights',
            "LONGTEXT NULL COMMENT 'JSON: {A:{eixo1,eixo2,eixo3}, B:{...}} — pesos por alternativa via /assign-weights' AFTER `source_url`"
        );
        // ATENÇÃO: acrescentar coluna aqui exige incrementar SCHEMA_VERSION acima.
        // Sem o incremento, maybe_upgrade() retorna na primeira linha e a coluna
        // nunca nasce — os arquivos ficam atualizados e todo write falha.
        self::add_column_if_missing(
            $dilemas,
            'image_id',
            "BIGINT UNSIGNED NULL COMMENT 'ID de anexo do WP: imagem de fundo deste dilema, escolhida pelo editor' AFTER `axis_weights`"
        );
    }

    /* ── dbDelta: cria as tabelas do zero ──────────────────── */

    private static function run_dbdelta() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        /*
         * ⚠️ A coluna `status` abaixo é LEGADO desde 05/10: nada a lê e nada a
         * escreve. O status da instância é DERIVADO, calculado na leitura por
         * Newsgame_Status. Ela fica no schema porque removê-la não traria ganho e
         * exigiria migração; o INSERT cai no DEFAULT. Os valores que sobraram
         * nas linhas antigas (draft/review) não significam nada.
         *
         * ⚠️ A coluna `enable_levels` também é LEGADO, desde 09/10: nada a lê e
         * nada a escreve. A opção "Ativar Níveis (Aprendiz / Pro)" prometia uma
         * calibragem que a geração nunca fez — o nível vem do `nivel_padrao`. Não
         * usar em consulta; os valores que sobraram (ex.: 1 na instância 8) não
         * significam nada. Fica pela mesma razão do `status`, e o `AFTER
         * enable_levels` do `nivel_padrao`, na migração, depende dela.
         *
         * Comentário em PHP, e não COMMENT de SQL, para não mudar a definição.
         */
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}newsgame_instances (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug         VARCHAR(200)    NOT NULL DEFAULT '',
            title        VARCHAR(500)    NOT NULL DEFAULT '',
            theme_prompt TEXT,
            characters    LONGTEXT        COMMENT 'JSON: lista de personagens com slug, label e queries RAG',
            density       SMALLINT        NOT NULL DEFAULT 20 COMMENT 'Meta do conjunto: soma das metas dos grupos, ate 120',
            round_size    TINYINT UNSIGNED NOT NULL DEFAULT 10 COMMENT 'Perguntas sorteadas por partida',
            gerais_pct    TINYINT UNSIGNED NOT NULL DEFAULT 50 COMMENT 'Gerais na partida, em % (0 a 50); so vale com personagens',
            pronto_fator  DECIMAL(3,1)    NOT NULL DEFAULT 1.6 COMMENT 'Aprovadas por grupo = teto(o que ele poe na partida x fator)',
            capa_titulo     VARCHAR(200)    NOT NULL DEFAULT '' COMMENT 'Titulo da capa; vazio = titulo da instancia',
            capa_linha_fina VARCHAR(300)    NOT NULL DEFAULT '' COMMENT 'Linha-fina da capa',
            capa_imagem_id  BIGINT UNSIGNED NULL COMMENT 'Anexo do WP: foto decorativa da capa',
            regras          TEXT            NULL COMMENT 'Texto simples da tela Regras; vazio = sem tela',
            logo_id         BIGINT UNSIGNED NULL COMMENT 'Anexo do WP: logo do apoio',
            apoio_texto     VARCHAR(200)    NOT NULL DEFAULT '' COMMENT 'Texto curto do apoio; alt do logo',
            apoio_url       VARCHAR(2048)   NOT NULL DEFAULT '' COMMENT 'Link opcional do apoio',
            escolher_personagem TINYINT(1)  NOT NULL DEFAULT 0 COMMENT '1 = o jogador escolhe o personagem numa tela',
            num_options   TINYINT         NOT NULL DEFAULT 2 COMMENT '2 ou 3 opcoes de resposta por pergunta',
            enable_levels TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '0=nivel unico, 1=Aprendiz/Pro',
            nivel_padrao  VARCHAR(20)     NOT NULL DEFAULT 'pedagogico' COMMENT 'ludico | pedagogico | complexo',
            axis_matrix   LONGTEXT        NULL COMMENT 'JSON: eixos e perfis propostos por /analyze-domain',
            skin          VARCHAR(64)     NOT NULL DEFAULT 'default' COMMENT 'Slug da skin de fabrica (H2 degrau 2)',
            status        VARCHAR(20)     NOT NULL DEFAULT 'draft',
            created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY   slug (slug)
        ) $charset;" );

        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}newsgame_dilemas (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            instance_id    BIGINT UNSIGNED NOT NULL,
            level          TINYINT         NOT NULL DEFAULT 1,
            character_slug VARCHAR(100)    NOT NULL DEFAULT 'geral' COMMENT 'slug do personagem que gerou este dilema',
            position       SMALLINT        NOT NULL DEFAULT 0,
            title          VARCHAR(500)    NOT NULL DEFAULT '',
            body           TEXT,
            option_a       LONGTEXT        COMMENT 'JSON: {texto, consequencia}',
            option_b       LONGTEXT        COMMENT 'JSON: {texto, consequencia}',
            option_c       LONGTEXT        NULL COMMENT 'JSON: {texto, consequencia} - presente se num_options=3',
            veredicto      LONGTEXT        COMMENT 'JSON: {sintese, desfecho, licao}',
            source_url     VARCHAR(2048)   DEFAULT NULL,
            axis_weights   LONGTEXT        NULL COMMENT 'JSON: {A:{eixo1,eixo2,eixo3}, B:{...}} — pesos por alternativa via /assign-weights',
            image_id       BIGINT UNSIGNED NULL COMMENT 'ID de anexo do WP: imagem de fundo deste dilema, escolhida pelo editor',
            review_status  VARCHAR(20)     NOT NULL DEFAULT 'pending' COMMENT 'pending | approved | discarded',
            created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY instance_id (instance_id)
        ) $charset;" );
    }

    /* ── Helper: ADD COLUMN se não existir ─────────────────── */

    /**
     * Devolve true só quando ESTA chamada criou a coluna. Quem precisa preencher
     * as linhas existentes uma única vez (ex.: round_size) depende disso.
     */
    private static function add_column_if_missing( $table, $column, $definition ) {
        global $wpdb;

        $exists = $wpdb->get_var(
            $wpdb->prepare( "SHOW COLUMNS FROM `$table` LIKE %s", $column )
        );

        if ( $exists ) {
            return false;
        }

        $wpdb->query( "ALTER TABLE `$table` ADD COLUMN `$column` $definition" );

        if ( $wpdb->last_error ) {
            error_log( "[Newsgame_DB] ALTER TABLE $table ADD $column FALHOU: " . $wpdb->last_error );
            return false;
        }

        error_log( "[Newsgame_DB] Coluna '$column' adicionada a $table com sucesso." );
        return true;
    }
}
