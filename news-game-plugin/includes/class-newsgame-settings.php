<?php
defined( 'ABSPATH' ) || exit;

class Newsgame_Settings {

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
        add_action( 'admin_init', array( __CLASS__, 'register_fields' ) );
    }

    public static function register_menu() {
        add_menu_page(
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
     * Contador de janela fixa por usuário, gravado em transient.
     *
     * Protege contra erro operacional (clique duplo numa UI que parece
     * travada, loop de JS com bug), não contra ataque: as rotas protegidas
     * já exigem editor autenticado com manage_options. Por isso a chave é o
     * usuário, e não o IP — dois editores atrás do mesmo NAT não disputam
     * a mesma cota.
     *
     * A janela é gravada dentro do valor (campo 'reset') em vez de depender
     * do TTL do transient: incrementar com set_transient() renovaria o TTL a
     * cada chamada, virando "60 s de silêncio obrigatório" e quebrando o
     * loop legítimo de /generate-one.
     *
     * Janela fixa, não deslizante — permite rajada de até 2x na virada da
     * janela. Suficiente para erro operacional; não serviria contra ataque.
     *
     * @param  string $bucket        Identificador da rota (ex: 'generate_one').
     * @param  int    $limit_per_min Máximo de chamadas por minuto por usuário.
     * @return bool   true se o limite foi excedido — o chamador deve recusar.
     */
    public static function rate_limited( $bucket, $limit_per_min ) {
        $key   = 'newsgame_rl_' . sanitize_key( $bucket ) . '_' . get_current_user_id();
        $now   = time();
        $state = get_transient( $key );

        if ( ! is_array( $state ) || $now >= (int) ( $state['reset'] ?? 0 ) ) {
            $state = array( 'count' => 0, 'reset' => $now + 60 );
        }

        $state['count']++;
        // TTL com folga: a expiração real é o campo 'reset'; o TTL só coleta lixo.
        set_transient( $key, $state, 120 );

        return $state['count'] > (int) $limit_per_min;
    }
}
