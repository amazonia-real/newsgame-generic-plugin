<?php
defined( 'ABSPATH' ) || exit;

class Newsgame_Admin_Instances {

    const NONCE_AXIS = 'newsgame_save_axis';

    public static function init() {
        add_action( 'admin_menu',                                      array( __CLASS__, 'register_submenu' ) );
        add_action( 'admin_post_newsgame_save_instance',               array( __CLASS__, 'handle_save' ) );
        add_action( 'admin_post_newsgame_delete_instance',             array( __CLASS__, 'handle_delete' ) );
        add_action( 'wp_ajax_newsgame_save_axis_matrix',               array( __CLASS__, 'handle_save_axis_matrix' ) );
    }

    public static function register_submenu() {
        add_submenu_page(
            'newsgame',
            'Instâncias — News-Game',
            'Instâncias',
            'manage_options',
            'newsgame-instances',
            array( __CLASS__, 'render' )
        );
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

        if ( $id ) {
            $wpdb->update( $table, $row, array( 'id' => $id ), $fmt, array( '%d' ) );
        } else {
            $wpdb->insert( $table, $row, $fmt );
            $id = (int) $wpdb->insert_id;
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
