<?php
defined( 'ABSPATH' ) || exit;

class Newsgame_Admin_Instances {

    const NONCE_AXIS    = 'newsgame_save_axis';
    const NONCE_ASSIGN  = 'newsgame_assign_weights';
    const NONCE_ANALYZE = 'newsgame_analyze_domain';

    /**
     * Skins de fábrica permitidas — H2, degrau 2.
     *
     * Whitelist FECHADA, e não lista extensível por filtro: a skin externa —
     * upload de skin de terceiro — está DESCARTADA como caminho do H2 (decisão
     * registrada em 2026-08-24, ver §7 do roadmap). Acrescentar entrada aqui é
     * decisão de escopo do projeto, não configuração do editor.
     *
     * ⚠️ ACRESCENTAR SKIN EXIGE TRÊS ARQUIVOS, e faltar qualquer um degrada:
     * esta constante (dado), os mapas `$rotulos_skin` e `$resumos_skin` em
     * `page-instance-form.php` (UI — sem eles o seletor mostra o slug cru e
     * nenhum resumo) e a folha `public/css/skins/skin-<slug>.css` (aparência —
     * sem ela o enfileiramento devolve 404 e o jogo renderiza com a paleta
     * base). A miniatura `admin/images/skins/skin-<slug>.webp` é opcional: a
     * falta dela é avisada na própria tela. Skins com cenários acrescentam as
     * artes em `public/images/skins/<slug>/`, referenciadas pela própria folha
     * e por mais nada.
     *
     * Desde 07/10 a regra vale também para `default`: ela tem folha própria
     * (`skin-default.css`) e passa pelo mesmo enfileiramento das outras, sem
     * exceção no loader.
     *
     * NOTA DE 29/08: o texto que vivia aqui dizia que estas entradas eram "o
     * suporte de schema deste passo" e que "nenhuma tem folha de estilo ainda".
     * Estava DEFASADO desde 27/08, quando as quatro folhas e o mecanismo de
     * enfileiramento entraram. Corrigido de passagem, não em silêncio.
     */
    const SKINS = array( 'default', 'mono', 'pixel', 'drama', 'games', 'popart', 'minimal' );

    /** Valor de queda quando a skin recebida não está na whitelist acima. */
    const SKIN_DEFAULT = 'default';

    /**
     * Miniaturas do seletor visual de skins — diretório e ordem de extensões.
     *
     * O diretório é RELATIVO a NEWSGAME_DIR/NEWSGAME_URL, e é do admin: a arte
     * aparece só na tela de edição de instância, nunca na superfície do jogador.
     *
     * ⚠️ A ORDEM DE `SKIN_THUMB_EXTS` É A REGRA DE RESOLUÇÃO, e é ela que faz a
     * troca de formato de UMA skin ser um arquivo solto no disco em vez de uma
     * mudança de código. O formato de fábrica é WebP para todas (arte de 16:9
     * comprime bem e o arquivo fica pequeno).
     *
     * A exceção PREVISTA — e não tomada — é a `pixel`: pixel art tem transição de
     * 1px entre cores chapadas, que é justamente a borda que a compressão com
     * perda do WebP suja. É um TESTE DE ACEITE: se a miniatura da `pixel` mostrar
     * serrilhado sujo ou halo em volta dos blocos, só ela vira `skin-pixel.png`,
     * e este `foreach` já resolve. Enquanto não houver miniatura real o teste não
     * pode ser feito; o que existe aqui é o caminho pronto para ele.
     *
     * As outras cinco são fotografia e degradê, sem esse risco.
     */
    const SKIN_THUMB_DIR  = 'admin/images/skins/';
    const SKIN_THUMB_EXTS = array( 'webp', 'png' );

    /** Hook suffix devolvido por add_submenu_page — usado para enfileirar o JS só nesta tela */
    private static $hook_suffix = '';

    public static function init() {
        add_action( 'admin_menu',                                      array( __CLASS__, 'register_submenu' ) );
        add_action( 'admin_enqueue_scripts',                           array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'admin_post_newsgame_save_instance',               array( __CLASS__, 'handle_save' ) );
        add_action( 'admin_post_newsgame_delete_instance',             array( __CLASS__, 'handle_delete' ) );
        add_action( 'wp_ajax_newsgame_save_axis_matrix',               array( __CLASS__, 'handle_save_axis_matrix' ) );
        add_action( 'wp_ajax_newsgame_assign_weights',                 array( __CLASS__, 'handle_assign_weights' ) );
        add_action( 'wp_ajax_newsgame_analyze_domain',                 array( __CLASS__, 'handle_analyze_domain' ) );
    }

    public static function register_submenu() {
        self::$hook_suffix = add_submenu_page(
            'newsgame',
            'Instâncias — News-Game',
            'Instâncias',
            'manage_options',
            'newsgame-instances',
            array( __CLASS__, 'render' )
        );
    }

    /**
     * Enfileira o JS da listagem de instâncias.
     * Arquivo externo em vez de onclick inline: CSP estrita bloqueia atributo
     * de evento. Ver TECH_FLAGS FLAG 007.
     */
    public static function enqueue_assets( $hook ) {
        if ( $hook !== self::$hook_suffix ) {
            return;
        }

        // render() despacha listagem e formulário no mesmo slug.
        $action    = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';
        $is_form   = ( 'new' === $action || 'edit' === $action );

        /*
         * A guarda antiga dava `return` aqui e o método não fazia mais nada nas
         * telas de formulário. Agora ela apenas separa os dois destinos: o JS da
         * listagem continua exclusivo da listagem, e o formulário ganha o seu.
         *
         * O A3 (FLAG 007 passo 3) herda esta guarda já corrigida — era a
         * armadilha registrada na nota dele ("sai fora quando `action` é
         * new/edit; a guarda vai precisar mudar"). Quem for mover os 5 <script>
         * inline do admin não precisa mais mexer nisto.
         */
        if ( $is_form ) {
            // Seletor nativo de mídia, para a imagem de fundo do dilema.
            // Sem esta chamada `wp.media` é undefined na tela.
            wp_enqueue_media();
            self::enqueue_form_styles();
            self::enqueue_form_scripts();
            return;
        }

        wp_enqueue_script(
            'newsgame-admin-instances',
            NEWSGAME_URL . 'admin/js/newsgame-admin-instances.js',
            array(),
            newsgame_versao_do_arquivo( 'admin/js/newsgame-admin-instances.js' ),
            true  // footer
        );
    }

    /**
     * Enfileira o CSS da tela de formulário de instância.
     *
     * ⚠️ PRIMEIRO `wp_enqueue_style` DO ADMIN EM TODO O PLUGIN. Até aqui a área
     * administrativa não tinha pipeline de CSS nenhum — o mesmo buraco que o A2
     * encontrou no JS, e pela mesma razão: todo estilo do painel nasceu dentro do
     * `<style>` inline do topo de `page-instance-form.php`, que é a única view
     * com bloco de estilo.
     *
     * O INLINE DE `page-instance-form.php` NÃO FOI MIGRADO, por decisão: a tela
     * passa a ter duas casas de CSS ao mesmo tempo. Ver TECH_FLAGS FLAG 010, que
     * registra o custo e a regra que fica — CSS novo do admin nasce em arquivo
     * enfileirado, um por bloco, e o inline não recebe acréscimo.
     *
     * Método separado do `enqueue_form_scripts()` em vez de uma linha dentro
     * dele: o nome do método é o que diz a um leitor futuro que existe pipeline
     * de estilo, e não só de script, nesta tela.
     *
     * O handle `newsgame-admin-skins` é o MESMO do arquivo de JS do componente, e
     * isso é legal e deliberado: o WordPress mantém dois registros separados
     * (`WP_Styles` e `WP_Scripts`), então não há colisão — o próprio core repete
     * handle entre os dois (`thickbox`). Conferido nas duas filas com o hook suffix
     * real da tela. Não "corrigir" renomeando um dos dois.
     */
    private static function enqueue_form_styles() {
        wp_enqueue_style(
            'newsgame-admin-skins',
            NEWSGAME_URL . 'admin/css/newsgame-admin-skins.css',
            array(),
            newsgame_versao_do_arquivo( 'admin/css/newsgame-admin-skins.css' )
        );
    }

    /**
     * Enfileira o JS da tela de formulário de instância — A3, FLAG 007 passo 3.
     *
     * Os arquivos entram SEM reproduzir aqui as condições da view. Cada um sai
     * fora sozinho quando a sua âncora não está no DOM. Repetir as condições
     * colocaria a mesma regra em dois lugares que rodam em momentos diferentes do
     * request — `admin_enqueue_scripts` acontece antes de a view existir — e
     * divergência silenciosa entre as duas cópias é a família do C13. O preço de
     * enfileirar sempre é uma requisição de arquivo estático a mais numa tela
     * autenticada.
     *
     * Dados dinâmicos vão por wp_localize_script, um objeto por arquivo, e não por
     * string de JS montada em PHP: o JSON é gerado e escapado pelo WordPress, o
     * que faz o esc_js() dos blocos inline desaparecer junto com eles.
     *
     * ⚠️ WP_Scripts::localize() aplica `(string)` em TODO escalar de topo — 5
     * chega ao JS como "5", true como "1", false como "". Valores não-escalares
     * (arrays) passam intactos. Onde o tipo importa, a conversão é explícita nas
     * duas pontas: aqui e no arquivo .js.
     */
    private static function enqueue_form_scripts() {
        $id   = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
        $inst = $id ? self::get( $id ) : null;

        // Defaults espelhando os da view. O `??` tem semântica de isset(), então
        // não emite warning com $inst nulo (tela de criação) e preserva o 2
        // quando a coluna vem NULL.
        $num_options   = (int) ( $inst->num_options ?? 2 );
        $chars         = (array) json_decode( $inst->characters ?? '[]', true );

        // A view roda as suas próprias contagens, depois. Esta existe porque o
        // enfileiramento precisa do total ANTES de a view existir. Unificar as
        // duas leituras é trabalho de outro item — o A3 não mexe nas queries da
        // view.
        $review_total = 0;
        if ( $id ) {
            global $wpdb;
            $review_total = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}newsgame_dilemas WHERE instance_id = %d",
                $id
            ) );
        }

        $base = NEWSGAME_URL . 'admin/js/';

        /* ── Geração de perguntas e reset ─────────────────────────── */
        wp_enqueue_script( 'newsgame-admin-generation', $base . 'newsgame-admin-generation.js', array(), newsgame_versao_do_arquivo( 'admin/js/newsgame-admin-generation.js' ), true );
        wp_localize_script( 'newsgame-admin-generation', 'ngGenCfg', array(
            'instanceId'        => $id,
            // Sem `meta` desde o item 58: o déficit é por grupo, e cada botão o
            // pede ao ajax_start() com o grupo dele.
            'nonce'             => wp_create_nonce( Newsgame_Generator::NONCE_GENERATE ),
            // O reset é da Mesa Editorial (AJAX_CLEAR), e por isso viaja com o
            // nonce dela, e não com o da geração. Era assim no bloco inline.
            'reviewNonce'       => wp_create_nonce( Newsgame_Review::NONCE ),
            'actionStart'       => Newsgame_Generator::AJAX_START,
            'actionGenerateOne' => Newsgame_Generator::AJAX_GENERATE_ONE,
            'actionSave'        => Newsgame_Generator::AJAX_SAVE,
            'actionClear'       => Newsgame_Review::AJAX_CLEAR,
        ) );

        /* ── Analisar acervo e matriz de eixos ────────────────────── */
        wp_enqueue_script( 'newsgame-admin-axis', $base . 'newsgame-admin-axis.js', array(), newsgame_versao_do_arquivo( 'admin/js/newsgame-admin-axis.js' ), true );
        // A matriz gravada viaja para o JS pedir confirmação antes de
        // sobrescrevê-la (C11). Vazio quando não há matriz ou instância.
        $inst_axis = $id ? self::get( $id ) : null;

        wp_localize_script( 'newsgame-admin-axis', 'ngAxisCfg', array(
            'instanceId'   => $id,
            'axisNonce'    => wp_create_nonce( self::NONCE_AXIS ),
            'analyzeNonce' => wp_create_nonce( self::NONCE_ANALYZE ),
            'savedMatrix'  => (string) ( $inst_axis->axis_matrix ?? '' ),
        ) );

        /* ── Mesa Editorial ──────────────────────────────────────── */
        wp_enqueue_script( 'newsgame-admin-review', $base . 'newsgame-admin-review.js', array(), newsgame_versao_do_arquivo( 'admin/js/newsgame-admin-review.js' ), true );
        wp_localize_script( 'newsgame-admin-review', 'ngReviewCfg', array(
            'instanceId'       => $id,
            'totalDilemas'     => $review_total,
            'nonce'            => wp_create_nonce( Newsgame_Review::NONCE ),
            'assignNonce'      => wp_create_nonce( self::NONCE_ASSIGN ),
            'actionLoad'       => Newsgame_Review::AJAX_LOAD,
            'actionApprove'    => Newsgame_Review::AJAX_APPROVE,
            'actionApproveAll' => Newsgame_Review::AJAX_APPROVE_ALL,
            'actionDiscard'    => Newsgame_Review::AJAX_DISCARD,
            'actionRestore'    => Newsgame_Review::AJAX_RESTORE,
            'actionRegen'      => Newsgame_Review::AJAX_REGEN,
        ) );

        /* ── Formulário: personagens e botões de salvar ───────────── */
        wp_enqueue_script(
            'newsgame-admin-form',
            $base . 'newsgame-admin-form.js',
            array(),
            newsgame_versao_do_arquivo( 'admin/js/newsgame-admin-form.js' ),
            true  // footer
        );

        /* ── Seletor visual de skins ──────────────────────────────── */
        /*
         * SEM wp_localize_script, e é o único arquivo desta tela nessa situação: o
         * rótulo e o resumo de cada skin viajam em `data-ng-rotulo` /
         * `data-ng-resumo` no próprio input da view. Os dois são texto de UI e
         * moram no mapa da view por convenção do projeto ("slug é dado, rótulo é
         * UI"); trazê-los para cá criaria uma terceira cópia dos textos dentro
         * desta classe, porque `admin_enqueue_scripts` roda ANTES de a view
         * existir. O arquivo sai fora sozinho pela guarda de âncora no DOM.
         */
        wp_enqueue_script(
            'newsgame-admin-skins',
            $base . 'newsgame-admin-skins.js',
            array(),
            newsgame_versao_do_arquivo( 'admin/js/newsgame-admin-skins.js' ),
            true  // footer
        );

        wp_localize_script( 'newsgame-admin-form', 'ngFormCfg', array(
            // Array: o localize NÃO converte valores não-escalares, então a
            // estrutura chega ao JS como array de objetos, não como string.
            'characters'  => array_values( $chars ),
            // String de propósito: o JS compara com `radio.value`, que é sempre
            // string. Com número, o `===` da trava de troca de opções daria
            // sempre falso e o "Cancelar" do confirm deixaria os dois radios
            // desmarcados.
            'numOptions'  => (string) $num_options,
            'reviewTotal' => $review_total,
        ) );
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';

        if ( 'new' === $action || 'edit' === $action ) {
            require NEWSGAME_DIR . 'admin/views/page-instance-form.php';
        } else {
            require NEWSGAME_DIR . 'admin/views/page-instances.php';
        }
    }

    /* ── CRUD ──────────────────────────────────────────────────── */

    public static function handle_save() {
        check_admin_referer( 'newsgame_save_instance', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Permissão insuficiente.' );

        global $wpdb;
        $table = $wpdb->prefix . 'newsgame_instances';

        $id            = (int) ( $_POST['instance_id'] ?? 0 );
        $title         = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
        $theme_prompt  = sanitize_textarea_field( wp_unslash( $_POST['theme_prompt'] ?? '' ) );
        // N de perguntas sorteadas por partida (4 a 15) e a receita (item 58):
        // % de gerais (0 a 50) e fator de Pronto (1,6 a 5,0, gravado com uma casa).
        $round_size    = min( 15, max( 4, absint( $_POST['round_size'] ?? 10 ) ) );
        $gerais_pct    = min( Newsgame_Status::GERAIS_PCT_MAX, absint( $_POST['gerais_pct'] ?? Newsgame_Status::GERAIS_PCT_PADRAO ) );
        $fator_decimos = Newsgame_Status::fator_decimos( str_replace( ',', '.', wp_unslash( $_POST['pronto_fator'] ?? '' ) ) );
        $num_options   = in_array( (int) ( $_POST['num_options'] ?? 2 ), array( 2, 3 ), true ) ? (int) $_POST['num_options'] : 2;
        $nivel_padrao  = in_array( $_POST['nivel_padrao'] ?? '', array( 'ludico', 'pedagogico', 'complexo' ), true )
                            ? sanitize_key( $_POST['nivel_padrao'] )
                            : 'pedagogico';
        $characters    = self::sanitize_characters( wp_unslash( $_POST['characters_json'] ?? '[]' ) );

        if ( ! $title ) {
            wp_safe_redirect( add_query_arg( array(
                'page'   => 'newsgame-instances',
                'action' => $id ? 'edit' : 'new',
                'id'     => $id ?: null,
                'error'  => 'title_required',
            ), admin_url( 'admin.php' ) ) );
            exit;
        }

        // A meta do conjunto deixou de ser campo (item 58): é DERIVADA, a soma das
        // metas dos grupos, com o teto de 120 de antes. A soma nunca fica abaixo
        // de N (fator >= 1,6), então a antiga trava "meta menor que a partida"
        // não tem mais como disparar e saiu.
        $receita = Newsgame_Status::receita( $round_size, $gerais_pct, $fator_decimos, array_column( $characters, 'slug' ) );
        $density = min( Newsgame_Status::CONJUNTO_MAX, max( 4, $receita['total'] ) );

        $slug = self::unique_slug( $title, $id );

        /*
         * SEM `status` no write, desde 05/10: o status é DERIVADO
         * (Newsgame_Status), calculado na leitura. A coluna fica no schema como
         * legado — o INSERT cai no DEFAULT dela e nada a lê. Saiu junto a trava
         * "publicar com aprovadas sem peso": sem 'published' não há o que travar,
         * e a pendência de peso virou item do "o que falta".
         * `$fmt` é posicional: o '%s' do status saiu da mesma posição.
         */
        $row = array(
            'title'         => $title,
            'slug'          => $slug,
            'theme_prompt'  => $theme_prompt,
            'density'       => $density,
            'round_size'    => $round_size,
            'num_options'   => $num_options,
            'nivel_padrao'  => $nivel_padrao,
            'characters'    => wp_json_encode( $characters, JSON_UNESCAPED_UNICODE ),
            'gerais_pct'    => $gerais_pct,
            'pronto_fator'  => sprintf( '%d.%d', intdiv( $fator_decimos, 10 ), $fator_decimos % 10 ),
        );
        $fmt = array( '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%d', '%s' );

        /*
         * A skin entra no write SÓ quando o formulário enviou o campo.
         *
         * Este passo não cria o campo na tela, por decisão de escopo. Se a chave
         * fosse incluída incondicionalmente, todo save de instância existente
         * gravaria 'default' e rebaixaria em silêncio a skin de quem já tivesse
         * uma — mesma família do C11 (sobrescrita sem aviso e sem histórico). O
         * custo de evitar é este `isset`, e ele continua correto depois de o
         * campo existir.
         *
         * No INSERT a ausência é inofensiva: o DEFAULT da coluna resolve.
         * `$fmt` é posicional no $wpdb — acrescentar nas duas pontas, no fim das
         * duas, é o que mantém o alinhamento.
         */
        if ( isset( $_POST['skin'] ) ) {
            $row['skin'] = self::sanitize_skin( wp_unslash( $_POST['skin'] ) );
            $fmt[]       = '%s';
        }

        // Capa e regras (H3, rodada A): mesma guarda da skin — campo ausente do
        // POST não entra no write, para não zerar o que está gravado.
        foreach ( self::campos_da_capa() as $campo => $valor ) {
            $row[ $campo ] = $valor;
            $fmt[]         = is_int( $valor ) ? '%d' : '%s';
        }

        // Interruptores: a tela de personagem (H3, rodada B) e a página inteira
        // (item 75). O formulário manda um oculto "0" antes de cada checkbox:
        // desmarcado também chega, e ausente do POST não entra no write.
        foreach ( array( 'escolher_personagem', 'pagina_inteira' ) as $chave ) {
            if ( isset( $_POST[ $chave ] ) ) {
                $row[ $chave ] = '1' === (string) wp_unslash( $_POST[ $chave ] ) ? 1 : 0;
                $fmt[]         = '%d';
            }
        }

        /*
         * Retorno conferido nos dois ramos — C13. Antes, escrita falhada
         * redirecionava com `saved=1` e a listagem dizia "Instância salva com
         * sucesso." com o valor antigo no banco.
         *
         * No UPDATE só `false` é falha: 0 é "nada mudou", o save de um formulário
         * sem edição. Diferente da Mesa Editorial, aqui NÃO há owns() antes, então
         * 0 também cobriria um id inexistente — caso que só um POST forjado
         * produz, e que fica fora deste passo.
         *
         * No INSERT a sentinela é o retorno, e não o $wpdb->insert_id: insert
         * falhado não zera o insert_id, e o valor remanescente pode vir de
         * qualquer INSERT anterior do mesmo request (o WordPress grava em
         * wp_options num carregamento de admin). É a armadilha do C15. Só com
         * exatamente 1 linha afetada o insert_id é desta instância.
         */
        $gravou = true;
        if ( $id ) {
            $gravou = false !== $wpdb->update( $table, $row, array( 'id' => $id ), $fmt, array( '%d' ) );
        } elseif ( 1 === $wpdb->insert( $table, $row, $fmt ) ) {
            $id = (int) $wpdb->insert_id;
        } else {
            $gravou = false;
        }

        // Volta ao formulário, que é onde o notice de `db_write` é renderizado.
        // A causa vai para o error_log e não para a URL: mensagem do MySQL não é
        // coisa de query string.
        if ( ! $gravou ) {
            $erro = $wpdb->last_error ?: 'o banco não informou a causa.';
            error_log( "[Newsgame] handle_save: escrita da instância falhou — MySQL: {$erro}" );
            // A causa também vai à tela, por transiente de 1 minuto do próprio usuário
            // (não pela URL). Cortada em 300 caracteres: a mensagem do MySQL pode
            // citar um trecho do texto enviado.
            set_transient( 'newsgame_db_write_' . get_current_user_id(), mb_substr( $erro, 0, 300 ), MINUTE_IN_SECONDS );
            wp_safe_redirect( add_query_arg( array(
                'page'   => 'newsgame-instances',
                'action' => $id ? 'edit' : 'new',
                'id'     => $id ?: null,
                'error'  => 'db_write',
            ), admin_url( 'admin.php' ) ) );
            exit;
        }

        /*
         * Destino do save: só 'stay' ou 'list', padrão 'stay'. 'stay' volta ao
         * formulário com `saved=1` — o editor segue trabalhando, e na criação a
         * tela volta já com o ID recém-inserido, o que libera "Analisar Acervo"
         * e a seção de geração. 'list' é o "Salvar e voltar à lista", o destino
         * histórico. Valor ausente ou fora da lista vale 'stay'.
         *
         * `saved` e `error` estão em wp_removable_query_args(): o core os tira da
         * barra de endereço depois da carga, e um F5 não repete o aviso.
         */
        $ng_after = sanitize_key( $_POST['ng_after'] ?? '' );
        if ( ! in_array( $ng_after, array( 'stay', 'list' ), true ) ) {
            $ng_after = 'stay';
        }

        $destino = array(
            'page'   => 'newsgame-instances',
            'action' => 'edit',
            'id'     => $id,
            'saved'  => 1,
        );
        if ( 'list' === $ng_after ) {
            unset( $destino['action'] );
        }

        wp_safe_redirect( add_query_arg( $destino, admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function handle_delete() {
        $id = (int) ( $_GET['id'] ?? 0 );
        check_admin_referer( "newsgame_delete_{$id}" );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Permissão insuficiente.' );

        global $wpdb;

        /*
         * Os dois DELETE numa transação — C13. Antes eram independentes e sem
         * conferência: a falha PARCIAL deixava dilemas órfãos (instância apagada)
         * ou instância viva e vazia (o inverso), e os dois casos redirecionavam
         * com `deleted=1`.
         *
         * Depende de as duas tabelas serem InnoDB. Em MyISAM o START TRANSACTION
         * é aceito e o ROLLBACK não desfaz nada — sem erro e sem aviso.
         *
         * $wpdb->query() devolve 0 em sucesso para START/COMMIT/ROLLBACK, então
         * só `false` é falha, como nas escritas.
         */
        if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
            self::abortar_delete( $id, 'db_write', 'START TRANSACTION' );
        }

        $apagada = $wpdb->delete( $wpdb->prefix . 'newsgame_instances', array( 'id' => $id ), array( '%d' ) );
        if ( false === $apagada ) {
            self::abortar_delete( $id, 'db_write', 'DELETE da instância' );
        }

        // 0 linhas = a instância não existia (link velho, aba duplicada, POST
        // forjado). Não é sucesso, e não há dilemas de quem não existe a apagar.
        if ( 0 === $apagada ) {
            self::abortar_delete( $id, 'not_found' );
        }

        // 0 aqui é legítimo: instância sem perguntas.
        if ( false === $wpdb->delete( $wpdb->prefix . 'newsgame_dilemas', array( 'instance_id' => $id ), array( '%d' ) ) ) {
            self::abortar_delete( $id, 'db_write', 'DELETE dos dilemas' );
        }

        if ( false === $wpdb->query( 'COMMIT' ) ) {
            self::abortar_delete( $id, 'db_write', 'COMMIT' );
        }

        // Backup da matriz de eixos (C11) sai junto com a instância, para não
        // ficar órfão no wp_options. Só quando a linha da instância saiu de fato:
        // apagar o backup de uma instância que sobreviveu à falha do DELETE
        // tiraria a rede justamente de quem ainda a tem.
        //
        // DEPOIS do COMMIT, e não dentro da transação: o DELETE em wp_options
        // seria desfeito por um ROLLBACK, mas o cache de options do WordPress
        // não — o cache diria "sem backup" com a linha ainda no banco.
        if ( $apagada ) {
            delete_option( 'newsgame_axis_backup_' . $id );
        }

        wp_safe_redirect( add_query_arg( array(
            'page'    => 'newsgame-instances',
            'deleted' => 1,
        ), admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * Desfaz a transação do handle_delete() e volta à listagem com o erro.
     *
     * O last_error é lido ANTES do ROLLBACK: o $wpdb o zera a cada query, e
     * depois do ROLLBACK ele diria "sem erro". A causa vai para o error_log e
     * não para a URL, mesmo critério do `db_write` do handle_save().
     *
     * Não retorna: redireciona e encerra o request.
     *
     * @param int    $id     Instância que se tentou excluir.
     * @param string $codigo 'db_write' ou 'not_found' — renderizados em page-instances.php.
     * @param string $etapa  Qual passo falhou, para o log. Vazio em 'not_found'.
     */
    private static function abortar_delete( $id, $codigo, $etapa = '' ) {
        global $wpdb;

        $erro = $wpdb->last_error ?: 'o banco não informou a causa.';
        $wpdb->query( 'ROLLBACK' );

        if ( 'db_write' === $codigo ) {
            error_log( "[Newsgame] handle_delete: {$etapa} falhou na instância {$id}, transação desfeita — MySQL: {$erro}" );
        }

        wp_safe_redirect( add_query_arg( array(
            'page'  => 'newsgame-instances',
            'error' => $codigo,
        ), admin_url( 'admin.php' ) ) );
        exit;
    }

    /* ── Queries ────────────────────────────────────────────────── */

    public static function get_all() {
        global $wpdb;
        return $wpdb->get_results(
            // As três últimas contagens alimentam Newsgame_Status::avaliar() na
            // listagem — o selo sai desta query, sem uma consulta por linha.
            "SELECT i.*, COUNT(d.id) AS dilema_count,
                    COALESCE(SUM(d.review_status = 'approved'), 0)                          AS aprovadas,
                    COALESCE(SUM(d.review_status = 'pending'), 0)                           AS pendentes,
                    COALESCE(SUM(d.review_status = 'approved' AND d.axis_weights IS NULL), 0) AS sem_peso
             FROM {$wpdb->prefix}newsgame_instances i
             LEFT JOIN {$wpdb->prefix}newsgame_dilemas d ON d.instance_id = i.id
             GROUP BY i.id
             ORDER BY i.created_at DESC"
        );
    }

    public static function get( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}newsgame_instances WHERE id = %d",
            (int) $id
        ) );
    }

    /* ── Helpers ────────────────────────────────────────────────── */

    /**
     * Campos da capa e das regras vindos do POST, sanitizados, SÓ os que vieram.
     * Texto simples em todos (o jogo escapa de novo na saída); imagem só se o ID
     * for de um anexo de imagem, senão NULL; link só http(s).
     *
     * @return array<string, string|int|null>
     */
    private static function campos_da_capa() {
        $textos = array(
            'capa_titulo'     => 200,
            'capa_linha_fina' => 300,
            'apoio_texto'     => 200,
        );
        $out = array();
        foreach ( $textos as $campo => $max ) {
            if ( isset( $_POST[ $campo ] ) ) {
                $out[ $campo ] = mb_substr( sanitize_text_field( wp_unslash( $_POST[ $campo ] ) ), 0, $max );
            }
        }
        if ( isset( $_POST['regras'] ) ) {
            $out['regras'] = sanitize_textarea_field( wp_unslash( $_POST['regras'] ) );
        }
        if ( isset( $_POST['apoio_url'] ) ) {
            $out['apoio_url'] = esc_url_raw( wp_unslash( $_POST['apoio_url'] ), array( 'http', 'https' ) );
        }
        foreach ( array( 'capa_imagem_id', 'logo_id' ) as $campo ) {
            if ( isset( $_POST[ $campo ] ) ) {
                $anexo         = absint( $_POST[ $campo ] );
                $out[ $campo ] = $anexo && wp_attachment_is_image( $anexo ) ? $anexo : null;
            }
        }
        return $out;
    }

    /**
     * Valida e sanitiza o JSON de personagens vindo do hidden input.
     * Garante que só campos conhecidos passam para o DB.
     */
    private static function sanitize_characters( $json_string ) {
        $raw = json_decode( $json_string, true );
        if ( ! is_array( $raw ) ) return array();

        $clean = array();
        foreach ( $raw as $c ) {
            $label = sanitize_text_field( $c['label'] ?? '' );
            if ( ! $label ) continue;
            $slug = sanitize_key( $c['slug'] ?? '' ) ?: sanitize_key( $label );
            // 'geral' é o grupo das perguntas gerais (item 58), e não pode ser de
            // personagem: um "Geral" juntaria as perguntas dele com as gerais.
            if ( Newsgame_Status::GRUPO_GERAL === $slug ) {
                $slug = 'personagem-geral';
            }
            $clean[] = array(
                'slug'         => $slug,
                'label'        => $label,
                'description'  => sanitize_textarea_field( $c['description'] ?? '' ),
                // Para o JOGADOR (H3, rodada B); a descrição acima é só da IA e
                // nunca vai ao JSON do jogo.
                'apresentacao' => mb_substr( sanitize_text_field( $c['apresentacao'] ?? '' ), 0, 200 ),
            );
        }
        return $clean;
    }

    /**
     * Normaliza uma skin vinda de fora para um valor garantido da whitelist.
     *
     * Público, ao contrário do sanitize_characters(), porque o valor também vai
     * precisar de normalização na LEITURA e não só na escrita: uma linha gravada
     * por SQL à mão, ou anterior à remoção de uma entrada da whitelist, chega ao
     * render com um valor que não existe mais. Quem construir o degrau 2 passa o
     * `$instance->skin` por aqui antes de montar caminho de arquivo, e nunca
     * concatena o valor cru — é o que impede que a coluna se torne vetor de
     * path traversal.
     *
     * `is_scalar` antes do cast porque o valor vem do $_POST: `skin[]=x` chega
     * como array, e `(string) array()` emitiria warning e produziria "Array".
     *
     * Falha em silêncio, de propósito: skin inválida cai para 'default', que é
     * degradação visual e não perda de dado — não é caso de aviso ao editor.
     */
    public static function sanitize_skin( $value ) {
        $skin = is_scalar( $value ) ? sanitize_key( (string) $value ) : '';

        return in_array( $skin, self::SKINS, true ) ? $skin : self::SKIN_DEFAULT;
    }

    /**
     * URL da miniatura de uma skin, ou string vazia quando não há arquivo.
     *
     * Resolve por EXTENSÃO, na ordem de `SKIN_THUMB_EXTS`: o primeiro arquivo que
     * existir em disco ganha. É o que permite trocar o formato de uma skin
     * soltando arquivo, sem tocar em código — ver a nota da constante.
     *
     * String vazia é RETORNO ESPERADO, e não erro: a view desenha o placeholder
     * tracejado e a skin continua selecionável. Foi assim que o componente subiu,
     * com as seis sem miniatura.
     *
     * `sanitize_skin()` antes de compor o caminho, sempre: é a regra escrita na
     * doc daquele método, e aqui ela é o que impede que um valor vindo de fora
     * (linha gravada por SQL à mão, whitelist encurtada) se torne caminho de
     * arquivo. Slug fora da whitelist cai para 'default', o que devolve a
     * miniatura da `default` — mesma degradação silenciosa que o resto da classe
     * aplica à skin inválida, e por isso não recebe aviso próprio.
     *
     * Custo: um `file_exists()` por extensão e por skin a cada render do
     * formulário — no máximo 12 stats numa tela autenticada, sem cache a
     * invalidar. Não vale a complexidade de memorizar.
     */
    public static function skin_thumb_url( $slug ) {
        $slug = self::sanitize_skin( $slug );

        foreach ( self::SKIN_THUMB_EXTS as $ext ) {
            $rel = self::SKIN_THUMB_DIR . 'skin-' . $slug . '.' . $ext;
            if ( file_exists( NEWSGAME_DIR . $rel ) ) {
                return NEWSGAME_URL . $rel;
            }
        }

        return '';
    }

    /* ── AJAX: salva matriz de eixos ───────────────────────────── */

    public static function handle_save_axis_matrix() {
        check_ajax_referer( self::NONCE_AXIS, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'msg' => 'Permissão insuficiente.' ) );
        }

        $instance_id = (int) ( $_POST['instance_id'] ?? 0 );
        $json_raw    = wp_unslash( $_POST['axis_matrix_json'] ?? '' );

        if ( ! $instance_id ) {
            wp_send_json_error( array( 'msg' => 'ID de instância inválido.' ) );
        }

        $decoded = json_decode( $json_raw, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            wp_send_json_error( array( 'msg' => 'JSON inválido: ' . json_last_error_msg() ) );
        }

        // Sanitiza os nomes de eixo na origem: eles chegam crus da resposta
        // do LLM (/analyze-domain) e depois são interpolados no HTML público.
        if ( isset( $decoded['eixos_principais'] ) && is_array( $decoded['eixos_principais'] ) ) {
            foreach ( $decoded['eixos_principais'] as $key => $eixo ) {
                if ( is_array( $eixo ) && isset( $eixo['nome'] ) ) {
                    $decoded['eixos_principais'][ $key ]['nome'] = sanitize_text_field( $eixo['nome'] );
                }
            }
        }

        global $wpdb;
        $nova = wp_json_encode( $decoded, JSON_UNESCAPED_UNICODE );

        /*
         * Backup da matriz ATUAL antes de sobrescrever — C11.
         *
         * Um nível só: a option guarda a versão imediatamente anterior, e cada
         * troca efetiva substitui o backup. Não é histórico, é rede contra a
         * sobrescrita acidental que o C11 descreve. Restaurar ainda é manual (não
         * há botão de desfazer).
         *
         * ORDEM IMPORTA, pela lição do C15: o backup precisa estar confirmado
         * ANTES do UPDATE. Se não entrar, a matriz principal não é tocada.
         *
         * Sem backup quando não há o que perder (primeiro save) e quando o valor
         * não muda: gravar o mesmo valor por cima apagaria o backup da troca
         * anterior, que é justamente o único que vale guardar.
         */
        $atual = $wpdb->get_var( $wpdb->prepare(
            "SELECT axis_matrix FROM {$wpdb->prefix}newsgame_instances WHERE id = %d",
            $instance_id
        ) );

        // get_var devolve null tanto em erro quanto em coluna NULL. Só o
        // last_error separa os dois, e ele é zerado a cada query do $wpdb.
        if ( null === $atual && $wpdb->last_error ) {
            error_log( "[Newsgame] handle_save_axis_matrix: leitura da matriz atual falhou — MySQL: {$wpdb->last_error}" );
            wp_send_json_error( array( 'msg' => 'Não foi possível ler a matriz atual para guardar a cópia, então ela NÃO foi substituída. Erro do banco: ' . $wpdb->last_error ) );
        }

        if ( $atual && $atual !== $nova ) {
            $chave = 'newsgame_axis_backup_' . $instance_id;
            update_option( $chave, $atual, false );

            // O retorno de update_option() NÃO serve de sentinela: ele é `false`
            // também quando o valor gravado é igual ao que já estava lá. A prova
            // é ler de volta — em falha, o cache de options fica com o valor
            // anterior, diferente de $atual.
            if ( get_option( $chave ) !== $atual ) {
                $erro = $wpdb->last_error ?: 'o banco não informou a causa.';
                error_log( "[Newsgame] handle_save_axis_matrix: backup da matriz da instância {$instance_id} falhou — MySQL: {$erro}" );
                wp_send_json_error( array( 'msg' => 'Não foi possível guardar a cópia da matriz atual, então ela NÃO foi substituída. Erro do banco: ' . $erro ) );
            }
        }

        $result = $wpdb->update(
            $wpdb->prefix . 'newsgame_instances',
            array( 'axis_matrix' => $nova ),
            array( 'id' => $instance_id ),
            array( '%s' ),
            array( '%d' )
        );

        if ( false === $result ) {
            wp_send_json_error( array( 'msg' => 'Falha ao salvar no banco: ' . $wpdb->last_error ) );
        }

        wp_send_json_success( array( 'msg' => 'Matriz de eixos salva.' ) );
    }

    /* ── Motor de pesagem de eixos ────────────────────────────── */

    /**
     * Conta dilemas aprovados sem pesos de eixo.
     *
     * É a pendência que faz o jogo entregar veredito genérico: o gate de
     * newsgame-game.js:342 exige pesoCount > 0 e degrada em silêncio sem ele.
     * Usado pelo aviso persistente da tela de edição. O bloqueio de publicação
     * que também o usava saiu em 05/10, com o status derivado.
     */
    public static function count_unweighted_approved( $instance_id ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}newsgame_dilemas
              WHERE instance_id = %d AND review_status = 'approved' AND axis_weights IS NULL",
            (int) $instance_id
        ) );
    }

    /**
     * Atribui pesos de eixo a um conjunto específico de dilemas.
     *
     * Extraído de handle_assign_weights() para que o handler de aprovação em lote
     * da Mesa Editorial reuse o mesmo caminho. Uma única chamada ao backend cobre
     * todos os IDs: o /assign-weights pontua cada dilema contra os polos dos
     * eixos de forma independente — o lote é eficiência de token, não requisito
     * de contexto. Por isso lote de 1 é perfeitamente válido.
     *
     * NÃO envia resposta HTTP e NÃO aplica throttle: quem chama decide as duas
     * coisas, porque o custo aceitável difere entre o botão manual e a aprovação.
     *
     * @param  int   $instance_id
     * @param  int[] $dilema_ids  IDs a pesar. Vazio = no-op.
     * @param  bool  $force       true reprocessa quem já tem peso (botão manual
     *                            "Reatribuir"); false só pesa axis_weights NULL.
     * @return array{updated:int, skipped:int, error:?string}
     */
    public static function assign_weights_for( $instance_id, array $dilema_ids, $force = false ) {
        global $wpdb;

        $instance_id = (int) $instance_id;
        $dilema_ids  = array_values( array_unique( array_filter( array_map( 'intval', $dilema_ids ) ) ) );

        if ( ! $instance_id || ! $dilema_ids ) {
            return array( 'updated' => 0, 'skipped' => 0, 'error' => null );
        }

        $instance = self::get( $instance_id );
        if ( ! $instance || ! $instance->axis_matrix ) {
            return array( 'updated' => 0, 'skipped' => count( $dilema_ids ),
                'error' => 'instância sem matriz de eixos — rode "Analisar Domínio" antes de pesar.' );
        }

        $axis_data = json_decode( $instance->axis_matrix, true );
        if ( ! isset( $axis_data['eixos_principais'] ) ) {
            return array( 'updated' => 0, 'skipped' => count( $dilema_ids ),
                'error' => 'matriz de eixos inválida ou sem eixos_principais.' );
        }

        $table_dilemas = $wpdb->prefix . 'newsgame_dilemas';
        $placeholders  = implode( ',', array_fill( 0, count( $dilema_ids ), '%d' ) );

        // Sem $force, só quem ainda não tem peso: reaprovar dilema já pesado não
        // gasta cota. Quem editou o texto zera o campo antes de chamar.
        $filtro_peso = $force ? '' : ' AND axis_weights IS NULL';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, body, option_a, option_b, option_c FROM {$table_dilemas}
              WHERE instance_id = %d AND id IN ({$placeholders}){$filtro_peso}
              ORDER BY id ASC",
            array_merge( array( $instance_id ), $dilema_ids )
        ), ARRAY_A );

        // Todos já tinham peso — caminho idempotente, não é erro
        if ( empty( $rows ) ) {
            return array( 'updated' => 0, 'skipped' => count( $dilema_ids ), 'error' => null );
        }

        // Monta payload: dilema_id é campo extra para rastrear o UPDATE de retorno.
        // O backend Python ignora campos desconhecidos — dilema_id volta intacto.
        $dilemmas = array();
        foreach ( $rows as $row ) {
            $opt_a = json_decode( $row['option_a'] ?? '{}', true ) ?: array();
            $opt_b = json_decode( $row['option_b'] ?? '{}', true ) ?: array();
            $opt_c = ! empty( $row['option_c'] ) ? json_decode( $row['option_c'], true ) : null;

            $alternativas = array(
                array( 'letra' => 'A', 'texto' => $opt_a['texto'] ?? '' ),
                array( 'letra' => 'B', 'texto' => $opt_b['texto'] ?? '' ),
            );
            if ( $opt_c ) {
                $alternativas[] = array( 'letra' => 'C', 'texto' => $opt_c['texto'] ?? '' );
            }

            $dilemmas[] = array(
                'dilema_id'    => (int) $row['id'],
                'situacao'     => $row['body'] ?? '',
                'alternativas' => $alternativas,
            );
        }

        $backend_url = rtrim( Newsgame_Settings::backend_url(), '/' );

        /*
         * ⚠️ A CHAVE SAIU DO CORPO NO B6, e não é limpeza cosmética. O campo
         * `gemini_api_key` que vivia aqui era código morto em duas frentes: o
         * backend resolve `header > body > env` (`_resolve_api_key()`,
         * `main.py:305`), então o header sempre venceu; e com provedor DeepSeek o
         * corpo consultado passa a ser `deepseek_api_key`, então o campo antigo
         * nunca seria lido de jeito nenhum. Sobrava um segredo trafegando por um
         * caminho que ninguém lê, num campo cujo NOME mente sobre o provedor.
         * Seguro por contrato: `AssignWeightsRequest.gemini_api_key`
         * (`models.py:244`) tem `default=""`.
         */
        $response = wp_remote_post(
            $backend_url . '/assign-weights',
            array(
                'timeout'   => 120,
                // Linha IDÊNTICA à das outras quatro chamadas ao backend, e ela
                // faltava só aqui — inconsistência pré-existente, corrigida em
                // 07/09. Em produção é no-op: com WP_DEBUG desligado a expressão
                // dá `true`, que é o default do WordPress. O único caso que muda
                // é dev com WP_DEBUG e 'localhost' na URL, onde a verificação é
                // pulada de propósito para aceitar certificado self-signed.
                'sslverify' => ! ( defined( 'WP_DEBUG' ) && WP_DEBUG && str_contains( $backend_url, 'localhost' ) ),
                'headers'   => array_merge(
                    array(
                        'Content-Type' => 'application/json',
                        'X-Api-Key'    => (string) get_option( 'newsgame_backend_api_key', '' ),
                    ),
                    Newsgame_Settings::provider_headers()
                ),
                'body'      => wp_json_encode( array(
                    'dilemmas' => $dilemmas,
                    'eixos'    => $axis_data['eixos_principais'],
                ), JSON_UNESCAPED_UNICODE ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return array( 'updated' => 0, 'skipped' => count( $rows ),
                'error' => 'falha de conexão com o backend: ' . $response->get_error_message() );
        }

        $status_code = (int) wp_remote_retrieve_response_code( $response );
        if ( 200 !== $status_code ) {
            return array( 'updated' => 0, 'skipped' => count( $rows ),
                'error' => "backend retornou HTTP {$status_code}: "
                    . substr( wp_remote_retrieve_body( $response ), 0, 300 ) );
        }

        $result = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $result ) || ! isset( $result['dilemmas_weighted'] ) ) {
            return array( 'updated' => 0, 'skipped' => count( $rows ),
                'error' => 'resposta inesperada do backend.' );
        }

        $updated           = 0;
        $falhas_de_escrita = 0;
        $erro_db           = '';

        foreach ( $result['dilemmas_weighted'] as $item ) {
            $dilema_id = (int) ( $item['dilema_id'] ?? 0 );
            if ( ! $dilema_id ) continue;

            $pesos = array();
            foreach ( $item['alternativas'] ?? array() as $alt ) {
                $letra = $alt['letra'] ?? '';
                if ( $letra && isset( $alt['pesos'] ) ) {
                    $pesos[ $letra ] = $alt['pesos'];
                }
            }

            if ( $pesos ) {
                $gravado = $wpdb->update(
                    $table_dilemas,
                    array( 'axis_weights' => wp_json_encode( $pesos, JSON_UNESCAPED_UNICODE ) ),
                    array( 'id' => $dilema_id, 'instance_id' => $instance_id ),
                    array( '%s' ),
                    array( '%d', '%d' )
                );

                // $updated conta LINHAS GRAVADAS, não alternativas parseadas da
                // resposta do backend. O ++ era incondicional dentro deste if,
                // então uma falha de escrita em TODOS os dilemas ainda devolvia
                // updated = N e error = null — "pesos atribuídos". O estado real
                // era salvo pelo count_unweighted_approved() do C5, que lê o
                // banco depois da operação; a CAUSA reportada é que ficava errada.
                //
                // 0 continua contando como gravado: significa que a linha já
                // tinha exatamente estes pesos (reprocessamento com $force).
                if ( false === $gravado ) {
                    $erro_db = $wpdb->last_error ?: 'causa não informada';
                    error_log( "[Newsgame] axis_weights não gravado no dilema {$dilema_id} — MySQL: {$erro_db}" );
                    $falhas_de_escrita++;
                    continue;
                }

                $updated++;
            }
        }

        // Falha de escrita entra no `error` mesmo com $updated > 0: atribuição
        // parcial não foi "concluída", e quem chama precisa da causa. O ESTADO
        // — quantos ficaram sem peso — continua vindo do
        // count_unweighted_approved(), nunca inferido daqui (C5).
        //
        // $erro_db é capturado no laço, e não lido aqui: $wpdb->last_error é
        // limpo pela primeira query bem-sucedida depois da que falhou.
        $erro = null;
        if ( $falhas_de_escrita ) {
            $erro = sprintf(
                'o backend respondeu, mas a gravação de %d peso(s) falhou no banco: %s',
                $falhas_de_escrita,
                $erro_db
            );
        } elseif ( ! $updated ) {
            $erro = 'o backend respondeu, mas nenhum peso válido voltou.';
        }

        return array(
            'updated' => $updated,
            'skipped' => max( 0, count( $rows ) - $updated ),
            'error'   => $erro,
        );
    }

    /* ── AJAX: atribui pesos de eixo via /assign-weights ──────── */

    public static function handle_assign_weights() {
        check_ajax_referer( self::NONCE_ASSIGN, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'msg' => 'Permissão insuficiente.' ) );
        }
        // Um clique processa o lote de uma vez — chamada única e caríssima no
        // modo "reatribuir". Teto baixo, e compartilhado pelos dois modos: o
        // barato também consome cota, só menos.
        if ( Newsgame_Settings::rate_limited( 'assign_weights', 10 ) ) {
            wp_send_json_error( array( 'msg' => 'Muitas atribuições em sequência. Aguarde um minuto — cada chamada consome cota da chave do provedor configurado.' ) );
        }

        $instance_id = (int) ( $_POST['instance_id'] ?? 0 );
        if ( ! $instance_id ) {
            wp_send_json_error( array( 'msg' => 'ID de instância inválido.' ) );
        }

        // Busca todos os dilemas da instância, independente de review_status
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}newsgame_dilemas WHERE instance_id = %d ORDER BY id ASC",
            $instance_id
        ) );

        if ( empty( $ids ) ) {
            wp_send_json_error( array( 'msg' => 'Nenhum dilema encontrado para esta instância.' ) );
        }

        /*
         * MODO DA OPERAÇÃO — dois botões, uma action.
         *
         *   force = false  "⚖ Pesar os que faltam": assign_weights_for() filtra
         *                  `axis_weights IS NULL` e processa só quem precisa.
         *                  É o caso comum — um dilema teve o texto editado, o
         *                  peso zerou, e repor não deve custar a instância toda.
         *   force = true   "reatribuir todos": reprocessa inclusive quem já tem
         *                  peso. Comportamento anterior, preservado, e ele tem
         *                  um uso INSUBSTITUÍVEL: quando o `axis_matrix` é
         *                  regerado, TODOS os pesos precisam ser refeitos —
         *                  `axis_weights` usa chaves posicionais e não guarda o
         *                  nome do eixo, então a matriz nova deixa os pesos
         *                  antigos descrevendo eixos que não os produziram, sem
         *                  nenhum sintoma. É o C11.
         *
         * Action única, e não uma nova: criar outra duplicaria nonce, guard e
         * throttle para trocar um booleano.
         *
         * ⚠️ DEFAULT FALSE, deliberado: POST malformado, ou chamador futuro que
         * não conheça o parâmetro, cai na operação BARATA. O contrário gastaria
         * a cota inteira da instância por omissão.
         */
        $force = ! empty( $_POST['force'] ) && '0' !== (string) $_POST['force'];

        $res = self::assign_weights_for( $instance_id, $ids, $force );

        if ( $res['error'] ) {
            wp_send_json_error( array( 'msg' => ucfirst( $res['error'] ) ) );
        }

        /*
         * ESTADO lido do banco DEPOIS da operação, nunca inferido do retorno.
         * É a lição do C5, e aqui ela é obrigatória: com $force = false,
         * `updated: 0` é AMBÍGUO — pode ser "não havia nada sem peso" (caminho
         * idempotente, sucesso) e pode ser "havia e não foi gravado". Só o
         * count resolve, e é ele que alimenta o mesmo aviso da tela de edição.
         */
        $restam = self::count_unweighted_approved( $instance_id );

        if ( $force ) {
            $msg = "Pesos reatribuídos para {$res['updated']} dilema(s).";
        } elseif ( $res['updated'] ) {
            $msg = "Pesos atribuídos para {$res['updated']} dilema(s) que estavam sem.";
        } else {
            $msg = 'Nenhum dilema estava sem peso — nada a fazer.';
        }

        if ( $restam > 0 ) {
            $msg .= " Ainda restam {$restam} aprovada(s) sem peso.";
        }

        wp_send_json_success( array(
            'msg'     => $msg,
            'updated' => $res['updated'],
            'restam'  => $restam,
        ) );
    }

    /* ── AJAX: proxy para /analyze-domain ─────────────────────── */

    public static function handle_analyze_domain() {
        check_ajax_referer( self::NONCE_ANALYZE, 'nonce' );
        /*
         * Todo erro desta rota leva um `tipo`, e é ele que decide a caixa na tela
         * (newsgame-admin-axis.js, `mostrarErro`):
         *   validacao — o pedido nem saiu do WordPress (permissão, throttle, sem
         *               ID, acervo vazio). Nada de servidor Python a conferir;
         *   conexao   — o WordPress não alcançou o backend, ou o backend recusou
         *               o token. Só aqui aparece o roteiro uvicorn/token/URL;
         *   backend   — o backend respondeu e recusou, com `detail` próprio.
         * Erro sem `tipo` (outro handler, resposta não-JSON) cai no genérico do JS.
         */
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'tipo' => 'validacao', 'msg' => 'Permissão insuficiente.' ) );
        }
        // Um clique por instância no uso normal — teto baixo basta.
        if ( Newsgame_Settings::rate_limited( 'analyze_domain', 10 ) ) {
            wp_send_json_error( array( 'tipo' => 'validacao', 'msg' => 'Muitas análises em sequência. Aguarde um minuto — cada análise consome cota da chave do provedor configurado.' ) );
        }

        $instance_id  = (int) ( $_POST['instance_id'] ?? 0 );
        $context_text = sanitize_textarea_field( wp_unslash( $_POST['context_text'] ?? '' ) );

        // O botão já nasce desabilitado sem ID (page-instance-form.php). Esta
        // guarda fica para o POST que não passa pela tela.
        if ( ! $instance_id ) {
            wp_send_json_error( array( 'tipo' => 'validacao', 'msg' => 'Salve o rascunho da instância antes de analisar o acervo.' ) );
        }
        if ( ! $context_text ) {
            wp_send_json_error( array( 'tipo' => 'validacao', 'msg' => 'Preencha a Descrição do Acervo antes de analisar.' ) );
        }

        $backend_url = rtrim( Newsgame_Settings::backend_url(), '/' );
        $api_key     = (string) get_option( 'newsgame_backend_api_key', '' );

        // A chave saiu do corpo no B6 — mesmo motivo do `/assign-weights` acima.
        // `DomainAnalysisRequest.gemini_api_key` (`models.py:227`) tem `default=""`.
        $response = wp_remote_post(
            $backend_url . '/analyze-domain',
            array(
                'timeout'   => 120,
                'sslverify' => ! ( defined( 'WP_DEBUG' ) && WP_DEBUG && str_contains( $backend_url, 'localhost' ) ),
                'headers'   => array_merge(
                    array(
                        'Content-Type' => 'application/json',
                        'X-Api-Key'    => $api_key,
                    ),
                    Newsgame_Settings::provider_headers()
                ),
                'body' => wp_json_encode( array(
                    'context_text' => $context_text,
                ), JSON_UNESCAPED_UNICODE ),
            )
        );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array( 'tipo' => 'conexao', 'msg' => 'Falha de conexão com o backend: ' . $response->get_error_message() ) );
        }

        $status_code = (int) wp_remote_retrieve_response_code( $response );
        if ( $status_code !== 200 ) {
            wp_send_json_error( self::erro_do_backend( $status_code, wp_remote_retrieve_body( $response ) ) );
        }

        $result = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $result ) ) {
            wp_send_json_error( array( 'tipo' => 'backend', 'msg' => 'Resposta inesperada do backend.' ) );
        }

        wp_send_json_success( $result );
    }

    /**
     * Texto EXATO do 401 do `_verify_token()` em backend-python/app/main.py.
     *
     * ⚠️ DEPENDÊNCIA DE CONTRATO POR TEXTO. É ele que separa os dois 401 do
     * backend, e não há outro sinal: os dois chegam com o mesmo código. Se o
     * texto mudar lá e não aqui, o 401 de token passa a cair em 'backend' — a
     * tela mostra o `detail`, mas perde o roteiro uvicorn/token/URL. Degrada,
     * não quebra. A ponta do backend está travada por
     * `tests/test_verify_token_contrato.py`, que falha se o texto mudar.
     */
    const DETAIL_TOKEN_INVALIDO = 'Invalid API key';

    /**
     * Classifica a resposta não-200 do backend para a tela do editor.
     *
     * O backend tem DOIS 401 com causas diferentes: o do `_verify_token()`
     * (o Token do Backend não bate com o API_SECRET_TOKEN) e o de chave do
     * PROVEDOR ausente, cujo `detail` já diz qual chave falta. Só o primeiro é
     * problema de conexão e merece o roteiro; mandar conferir uvicorn/token/URL
     * no segundo apontaria para o lugar errado. Ver DETAIL_TOKEN_INVALIDO.
     *
     * O `detail` vem do JSON do FastAPI quando é string. Quando não é (o 422 de
     * validação do Pydantic devolve lista) ou o corpo não é JSON, vai o começo
     * do corpo cru, como antes.
     *
     * @param  int    $status_code
     * @param  string $body
     * @return array{tipo:string, msg:string}
     */
    private static function erro_do_backend( $status_code, $body ) {
        $json   = json_decode( (string) $body, true );
        $detail = ( is_array( $json ) && isset( $json['detail'] ) && is_string( $json['detail'] ) )
            ? $json['detail']
            : substr( (string) $body, 0, 300 );

        if ( 401 === $status_code && self::DETAIL_TOKEN_INVALIDO === $detail ) {
            return array( 'tipo' => 'conexao', 'msg' => 'O backend recusou o Token do Backend (HTTP 401).' );
        }

        return array( 'tipo' => 'backend', 'msg' => "O backend respondeu HTTP {$status_code}: {$detail}" );
    }

    /** Gera um slug único para a instância, evitando colisão no DB. */
    private static function unique_slug( $title, $exclude_id = 0 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'newsgame_instances';

        $base = substr( sanitize_title( $title ), 0, 180 );
        $slug = $base;
        $i    = 1;

        while ( $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE slug = %s AND id != %d",
            $slug, (int) $exclude_id
        ) ) ) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }
}
