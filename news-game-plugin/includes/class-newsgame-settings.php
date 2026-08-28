<?php
defined( 'ABSPATH' ) || exit;

class Newsgame_Settings {

    /** Hook suffix da tela de configurações — usado para enfileirar só nela */
    private static $hook_suffix = '';

    public static function init() {
        add_action( 'admin_menu',            array( __CLASS__, 'register_menu' ) );
        add_action( 'admin_init',            array( __CLASS__, 'register_fields' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
    }

    public static function register_menu() {
        self::$hook_suffix = add_menu_page(
            'News-Game',
            'News-Game',
            'manage_options',
            'newsgame',
            array( __CLASS__, 'render_page' ),
            'dashicons-editor-table',
            58
        );
        add_submenu_page(
            'newsgame',
            'Configurações — News-Game',
            'Configurações',
            'manage_options',
            'newsgame',
            array( __CLASS__, 'render_page' )
        );
    }

    /**
     * Enfileira o JS da tela de configurações — A3, FLAG 007 passo 3.
     *
     * A comparação é com o hook devolvido pelo add_menu_page(), e não com uma
     * string escrita à mão ('toplevel_page_newsgame'): renomear o slug do menu
     * passa a quebrar o enfileiramento de forma visível, e não em silêncio.
     *
     * Os dados vão por wp_localize_script, e não por JS montado em PHP: o JSON é
     * gerado e escapado pelo WordPress, o que dispensa o esc_js() que existia nas
     * quatro linhas de action/nonce do bloco inline.
     *
     * ⚠️ WP_Scripts::localize() aplica `(string)` em todo escalar de topo. Aqui os
     * quatro valores já são string, então não há conversão a compensar no JS.
     */
    public static function enqueue_assets( $hook ) {
        if ( ! self::$hook_suffix || $hook !== self::$hook_suffix ) {
            return;
        }

        wp_enqueue_script(
            'newsgame-admin-settings',
            NEWSGAME_URL . 'admin/js/newsgame-admin-settings.js',
            array(),
            NEWSGAME_VERSION,
            true  // footer
        );

        wp_localize_script( 'newsgame-admin-settings', 'ngSettingsCfg', array(
            'actionSync' => Newsgame_Ingestor::AJAX_ACTION,
            'nonceSync'  => wp_create_nonce( Newsgame_Ingestor::NONCE_KEY ),
            'actionPush' => Newsgame_Generator::AJAX_PUSH,
            'noncePush'  => wp_create_nonce( Newsgame_Generator::NONCE_PUSH ),
        ) );
    }

    public static function register_fields() {
        register_setting( 'newsgame_settings', 'newsgame_gemini_api_key', array(
            'sanitize_callback' => 'sanitize_text_field',
        ) );
        register_setting( 'newsgame_settings', 'newsgame_organization_name', array(
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'News-Game',
        ) );
        register_setting( 'newsgame_settings', 'newsgame_backend_url', array(
            'sanitize_callback' => 'esc_url_raw',
        ) );
        register_setting( 'newsgame_settings', 'newsgame_backend_api_key', array(
            'sanitize_callback' => 'sanitize_text_field',
        ) );
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        require NEWSGAME_DIR . 'admin/views/page-settings.php';
    }

    /* Acessores estáticos — use em qualquer ponto do plugin */

    public static function gemini_key() {
        return (string) get_option( 'newsgame_gemini_api_key', '' );
    }

    public static function org_name() {
        return (string) get_option( 'newsgame_organization_name', 'News-Game' );
    }

    public static function backend_url() {
        return (string) get_option( 'newsgame_backend_url', '' );
    }

    /* ── Throttle de custo — protege a cota da chave Gemini ─────── */

    /**
     * Contador de janela fixa por usuário, incrementado atomicamente no banco.
     *
     * Protege contra erro operacional (clique duplo numa UI que parece
     * travada, loop de JS com bug), não contra ataque: as rotas protegidas
     * já exigem editor autenticado com manage_options. Por isso a chave é o
     * usuário, e não o IP — dois editores atrás do mesmo NAT não disputam
     * a mesma cota.
     *
     * A implementação anterior usava get_transient() + set_transient(), o que
     * é um read-modify-write: N requisições simultâneas liam o mesmo contador
     * antes de qualquer uma escrever, e todas passavam. Medido com 12 chamadas
     * paralelas em /analyze-domain — nenhuma bloqueada. Justamente o cenário
     * que motiva o throttle (loop de JS sem await), então o incremento agora
     * acontece dentro de uma única instrução SQL.
     *
     * A janela é fixa e alinhada ao minuto do relógio (floor(time()/60) entra
     * na chave), não deslizante — permite rajada de até 2x na virada. Suficiente
     * para erro operacional; não serviria contra ataque.
     *
     * Sob rajada simultânea o contador lido de volta pode já incluir chamadas
     * irmãs, bloqueando algumas a mais do que o limite estrito. Para uma trava
     * de custo, errar para o lado de bloquear é o lado certo. Em uso sequencial
     * — o caso normal — a contagem é exata.
     *
     * @param  string $bucket        Identificador da rota (ex: 'generate_one').
     * @param  int    $limit_per_min Máximo de chamadas por minuto por usuário.
     * @return bool   true se o limite foi excedido — o chamador deve recusar.
     */
    public static function rate_limited( $bucket, $limit_per_min ) {
        global $wpdb;

        $prefixo = 'newsgame_rl_' . sanitize_key( $bucket ) . '_' . get_current_user_id() . '_';
        $chave   = $prefixo . (int) floor( time() / 60 );

        // Atômico: o MySQL resolve o incremento dentro da própria instrução,
        // então não há janela entre ler e escrever. Depende do índice único
        // em wp_options.option_name, que faz parte do schema do WordPress.
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
             VALUES (%s, '1', 'no')
             ON DUPLICATE KEY UPDATE option_value = option_value + 1",
            $chave
        ) );

        // rows_affected é 1 quando a linha foi criada — ou seja, primeira
        // chamada desta janela. É o momento barato de varrer as janelas
        // velhas deste mesmo usuário/rota, sem cron nem coleta probabilística.
        if ( 1 === $wpdb->rows_affected ) {
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name <> %s",
                $wpdb->esc_like( $prefixo ) . '%',
                $chave
            ) );
        }

        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $chave
        ) );

        return $count > (int) $limit_per_min;
    }
}
