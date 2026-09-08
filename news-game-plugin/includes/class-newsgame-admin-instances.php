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
     * esta constante (dado), o mapa `$rotulos_skin` em
     * `page-instance-form.php` (UI — sem ele o <select> mostra o slug cru) e a
     * folha `public/css/skins/skin-<slug>.css` (aparência — sem ela o
     * enfileiramento devolve 404 e o jogo renderiza com a paleta base). A
     * `pixel` acrescenta um quarto: as artes em `public/images/skins/pixel/`,
     * referenciadas pela própria folha e por mais nada.
     *
     * `default` NÃO tem folha, de propósito: é o próprio arquivo base, e um
     * `skin-default.css` vazio seria uma requisição por render para não mudar
     * nada.
     *
     * NOTA DE 29/08: o texto que vivia aqui dizia que estas entradas eram "o
     * suporte de schema deste passo" e que "nenhuma tem folha de estilo ainda".
     * Estava DEFASADO desde 27/08, quando as quatro folhas e o mecanismo de
     * enfileiramento entraram. Corrigido de passagem, não em silêncio.
     */
    const SKINS = array( 'default', 'neutro', 'mono', 'colorido', 'futurista', 'pixel' );

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
     * mudança de código. O formato de fábrica é WebP para as seis (arte de 16:9
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
            NEWSGAME_VERSION,
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
            NEWSGAME_VERSION
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
        $enable_levels = (bool) ( $inst->enable_levels ?? false );
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
        wp_enqueue_script( 'newsgame-admin-generation', $base . 'newsgame-admin-generation.js', array(), NEWSGAME_VERSION, true );
        wp_localize_script( 'newsgame-admin-generation', 'ngGenCfg', array(
            'instanceId'        => $id,
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
        wp_enqueue_script( 'newsgame-admin-axis', $base . 'newsgame-admin-axis.js', array(), NEWSGAME_VERSION, true );
        wp_localize_script( 'newsgame-admin-axis', 'ngAxisCfg', array(
            'instanceId'   => $id,
            'axisNonce'    => wp_create_nonce( self::NONCE_AXIS ),
            'analyzeNonce' => wp_create_nonce( self::NONCE_ANALYZE ),
        ) );

        /* ── Mesa Editorial ──────────────────────────────────────── */
        wp_enqueue_script( 'newsgame-admin-review', $base . 'newsgame-admin-review.js', array(), NEWSGAME_VERSION, true );
        wp_localize_script( 'newsgame-admin-review', 'ngReviewCfg', array(
            'instanceId'       => $id,
            // Booleano vira "1" ou "" no localize; o .js normaliza com `!!`.
            'enableLevels'     => $enable_levels,
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
            NEWSGAME_VERSION,
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
            NEWSGAME_VERSION,
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
        $density       = min( 15, max( 4, absint( $_POST['density'] ?? 10 ) ) );
        $num_options   = in_array( (int) ( $_POST['num_options'] ?? 2 ), array( 2, 3 ), true ) ? (int) $_POST['num_options'] : 2;
        $enable_levels = isset( $_POST['enable_levels'] ) ? 1 : 0;
        $nivel_padrao  = in_array( $_POST['nivel_padrao'] ?? '', array( 'ludico', 'pedagogico', 'complexo' ), true )
                            ? sanitize_key( $_POST['nivel_padrao'] )
                            : 'pedagogico';
        $status        = sanitize_key( $_POST['status'] ?? 'draft' );
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

        $slug = self::unique_slug( $title, $id );

        // Publicar com aprovados sem peso entregaria veredito genérico ao jogador,
        // em silêncio. Em vez de rejeitar o save inteiro — que descartaria as demais
        // edições do formulário —, rebaixa o status para 'review' e avisa. O editor
        // não perde trabalho e a publicação não acontece.
        $pendentes_sem_peso = 0;
        if ( $id && 'published' === $status ) {
            $pendentes_sem_peso = self::count_unweighted_approved( $id );
            if ( $pendentes_sem_peso > 0 ) {
                $status = 'review';
            }
        }

        $row = array(
            'title'         => $title,
            'slug'          => $slug,
            'theme_prompt'  => $theme_prompt,
            'density'       => $density,
            'num_options'   => $num_options,
            'enable_levels' => $enable_levels,
            'nivel_padrao'  => $nivel_padrao,
            'status'        => $status,
            'characters'    => wp_json_encode( $characters, JSON_UNESCAPED_UNICODE ),
        );
        $fmt = array( '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s' );

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

        if ( $id ) {
            $wpdb->update( $table, $row, array( 'id' => $id ), $fmt, array( '%d' ) );
        } else {
            $wpdb->insert( $table, $row, $fmt );
            $id = (int) $wpdb->insert_id;
        }

        // Com pendência de pesos, volta para o formulário: é onde estão o aviso e o
        // botão que resolve. Sem pendência, mantém o destino histórico (a listagem).
        if ( $pendentes_sem_peso > 0 ) {
            wp_safe_redirect( add_query_arg( array(
                'page'   => 'newsgame-instances',
                'action' => 'edit',
                'id'     => $id,
                'saved'  => 1,
                'error'  => 'unweighted_approved',
            ), admin_url( 'admin.php' ) ) );
            exit;
        }

        wp_safe_redirect( add_query_arg( array(
            'page'  => 'newsgame-instances',
            'saved' => 1,
            'id'    => $id,
        ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function handle_delete() {
        $id = (int) ( $_GET['id'] ?? 0 );
        check_admin_referer( "newsgame_delete_{$id}" );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Permissão insuficiente.' );

        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'newsgame_instances', array( 'id' => $id ), array( '%d' ) );
        $wpdb->delete( $wpdb->prefix . 'newsgame_dilemas',   array( 'instance_id' => $id ), array( '%d' ) );

        wp_safe_redirect( add_query_arg( array(
            'page'    => 'newsgame-instances',
            'deleted' => 1,
        ), admin_url( 'admin.php' ) ) );
        exit;
    }

    /* ── Queries ────────────────────────────────────────────────── */

    public static function get_all() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT i.*, COUNT(d.id) AS dilema_count
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
            $clean[] = array(
                'slug'        => sanitize_key( $c['slug'] ?? '' ) ?: sanitize_key( $label ),
                'label'       => $label,
                'description' => sanitize_textarea_field( $c['description'] ?? '' ),
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
        $result = $wpdb->update(
            $wpdb->prefix . 'newsgame_instances',
            array( 'axis_matrix' => wp_json_encode( $decoded, JSON_UNESCAPED_UNICODE ) ),
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
     * Usado pelo aviso persistente da tela de edição e pelo bloqueio de
     * publicação em handle_save().
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
                'timeout' => 120,
                'headers' => array_merge(
                    array(
                        'Content-Type' => 'application/json',
                        'X-Api-Key'    => (string) get_option( 'newsgame_backend_api_key', '' ),
                    ),
                    Newsgame_Settings::provider_headers()
                ),
                'body' => wp_json_encode( array(
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
            wp_send_json_error( array( 'msg' => 'Muitas atribuições em sequência. Aguarde um minuto — cada chamada consome cota da chave Gemini.' ) );
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
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'msg' => 'Permissão insuficiente.' ) );
        }
        // Um clique por instância no uso normal — teto baixo basta.
        if ( Newsgame_Settings::rate_limited( 'analyze_domain', 10 ) ) {
            wp_send_json_error( array( 'msg' => 'Muitas análises em sequência. Aguarde um minuto — cada análise consome cota da chave Gemini.' ) );
        }

        $instance_id  = (int) ( $_POST['instance_id'] ?? 0 );
        $context_text = sanitize_textarea_field( wp_unslash( $_POST['context_text'] ?? '' ) );

        if ( ! $instance_id ) {
            wp_send_json_error( array( 'msg' => 'ID de instância inválido.' ) );
        }
        if ( ! $context_text ) {
            wp_send_json_error( array( 'msg' => 'context_text não pode estar vazio.' ) );
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
            wp_send_json_error( array( 'msg' => 'Falha de conexão com o backend: ' . $response->get_error_message() ) );
        }

        $status_code = (int) wp_remote_retrieve_response_code( $response );
        if ( $status_code !== 200 ) {
            $body = wp_remote_retrieve_body( $response );
            wp_send_json_error( array( 'msg' => "Backend retornou HTTP {$status_code}: " . substr( $body, 0, 300 ) ) );
        }

        $result = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $result ) ) {
            wp_send_json_error( array( 'msg' => 'Resposta inesperada do backend.' ) );
        }

        wp_send_json_success( $result );
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
