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
}
