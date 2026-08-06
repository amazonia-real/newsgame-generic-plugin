<?php
defined( 'ABSPATH' ) || exit;

class Newsgame_Admin_Instances {

    const NONCE_AXIS    = 'newsgame_save_axis';
    const NONCE_ASSIGN  = 'newsgame_assign_weights';
    const NONCE_ANALYZE = 'newsgame_analyze_domain';

    public static function init() {
        add_action( 'admin_menu',                                      array( __CLASS__, 'register_submenu' ) );
        add_action( 'admin_post_newsgame_save_instance',               array( __CLASS__, 'handle_save' ) );
        add_action( 'admin_post_newsgame_delete_instance',             array( __CLASS__, 'handle_delete' ) );
        add_action( 'wp_ajax_newsgame_save_axis_matrix',               array( __CLASS__, 'handle_save_axis_matrix' ) );
        add_action( 'wp_ajax_newsgame_assign_weights',                 array( __CLASS__, 'handle_assign_weights' ) );
        add_action( 'wp_ajax_newsgame_analyze_domain',                 array( __CLASS__, 'handle_analyze_domain' ) );
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

    /* ── AJAX: atribui pesos de eixo via /assign-weights ──────── */

    public static function handle_assign_weights() {
        check_ajax_referer( self::NONCE_ASSIGN, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'msg' => 'Permissão insuficiente.' ) );
        }
        // Um clique processa todos os dilemas da instância de uma vez — chamada
        // única e caríssima. Teto baixo.
        if ( Newsgame_Settings::rate_limited( 'assign_weights', 10 ) ) {
            wp_send_json_error( array( 'msg' => 'Muitas reatribuições em sequência. Aguarde um minuto — cada reatribuição consome cota da chave Gemini.' ) );
        }

        $instance_id = (int) ( $_POST['instance_id'] ?? 0 );
        if ( ! $instance_id ) {
            wp_send_json_error( array( 'msg' => 'ID de instância inválido.' ) );
        }

        // Carrega instância e valida presença de axis_matrix
        $instance = self::get( $instance_id );
        if ( ! $instance || ! $instance->axis_matrix ) {
            wp_send_json_error( array( 'msg' => 'Instância sem matriz de eixos. Execute /analyze-domain primeiro.' ) );
        }

        $axis_data = json_decode( $instance->axis_matrix, true );
        if ( ! isset( $axis_data['eixos_principais'] ) ) {
            wp_send_json_error( array( 'msg' => 'Matriz de eixos inválida ou sem eixos_principais.' ) );
        }

        // Busca todos os dilemas da instância, independente de review_status
        global $wpdb;
        $table_dilemas = $wpdb->prefix . 'newsgame_dilemas';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, body, option_a, option_b, option_c FROM {$table_dilemas} WHERE instance_id = %d ORDER BY id ASC",
            $instance_id
        ), ARRAY_A );

        if ( empty( $rows ) ) {
            wp_send_json_error( array( 'msg' => 'Nenhum dilema encontrado para esta instância.' ) );
        }

        // Monta payload: inclui dilema_id como campo extra para rastrear o UPDATE de retorno.
        // O backend Python ignora campos desconhecidos no dict — dilema_id volta na resposta intacto.
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

        // Chama /assign-weights no backend Python
        $backend_url = rtrim( Newsgame_Settings::backend_url(), '/' );
        $gemini_key  = Newsgame_Settings::gemini_key();

        $response = wp_remote_post(
            $backend_url . '/assign-weights',
            array(
                'timeout' => 120,
                'headers' => array(
                    'Content-Type'     => 'application/json',
                    'X-Gemini-Api-Key' => $gemini_key,
                    'X-Api-Key'        => (string) get_option( 'newsgame_backend_api_key', '' ),
                ),
                'body' => wp_json_encode( array(
                    'dilemmas'       => $dilemmas,
                    'eixos'          => $axis_data['eixos_principais'],
                    'gemini_api_key' => $gemini_key,
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
        if ( ! is_array( $result ) || ! isset( $result['dilemmas_weighted'] ) ) {
            wp_send_json_error( array( 'msg' => 'Resposta inesperada do backend.' ) );
        }

        // Salva os pesos de volta — um UPDATE por dilema.
        //
        // Nota intencional: todos os dilemas são reprocessados no "Reatribuir", incluindo
        // os que não mudaram. Comportamento deliberado por simplicidade — garante consistência
        // sem controle de estado entre chamadas. Otimização futura: enviar apenas dilemas com
        // axis_weights IS NULL ou com updated_at posterior ao último assign, reduzindo chamadas
        // ao Gemini em lotes grandes.
        $updated = 0;
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
                $wpdb->update(
                    $table_dilemas,
                    array( 'axis_weights' => wp_json_encode( $pesos, JSON_UNESCAPED_UNICODE ) ),
                    array( 'id' => $dilema_id, 'instance_id' => $instance_id ),
                    array( '%s' ),
                    array( '%d', '%d' )
                );
                $updated++;
            }
        }

        wp_send_json_success( array(
            'msg'     => "Pesos atribuídos para {$updated} dilema(s).",
            'updated' => $updated,
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
        $gemini_key  = Newsgame_Settings::gemini_key();
        $api_key     = (string) get_option( 'newsgame_backend_api_key', '' );

        $response = wp_remote_post(
            $backend_url . '/analyze-domain',
            array(
                'timeout'   => 120,
                'sslverify' => ! ( defined( 'WP_DEBUG' ) && WP_DEBUG && str_contains( $backend_url, 'localhost' ) ),
                'headers'   => array(
                    'Content-Type'     => 'application/json',
                    'X-Gemini-Api-Key' => $gemini_key,
                    'X-Api-Key'        => $api_key,
                ),
                'body' => wp_json_encode( array(
                    'context_text'   => $context_text,
                    'gemini_api_key' => $gemini_key,
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
