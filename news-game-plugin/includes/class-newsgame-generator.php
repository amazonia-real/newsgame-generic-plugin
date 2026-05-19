<?php
/**
 * Newsgame_Generator
 *
 * Ponte WordPress → Backend Python.
 * Fluxo de geração (browser-direct SSE):
 *   1. ajax_start()        — valida instância e retorna config ao browser (sem loopback)
 *   2. ajax_save_matrix()  — recebe dilemas do browser após o stream e salva no DB
 *   3. ajax_poll()         — mantido para compatibilidade; retorna transient de progresso
 *   4. ajax_push_ingest()  — empurra vault local para o endpoint /ingest do backend
 *
 * O JS do painel abre um fetch() SSE direto contra o backend Python,
 * lê os chunks em tempo real e, ao receber "done", chama ajax_save_matrix().
 */
defined( 'ABSPATH' ) || exit;

class Newsgame_Generator {

    const AJAX_START      = 'newsgame_start_generation';
    const AJAX_BG         = 'newsgame_bg_generate';
    const AJAX_POLL       = 'newsgame_poll_progress';
    const AJAX_SAVE       = 'newsgame_save_matrix';
    const AJAX_PUSH       = 'newsgame_push_ingest';
    const NONCE_GENERATE  = 'newsgame_generate';
    const NONCE_PUSH      = 'newsgame_push_ingest';

    public static function init() {
        add_action( 'wp_ajax_' . self::AJAX_START, array( __CLASS__, 'ajax_start' ) );
        add_action( 'wp_ajax_' . self::AJAX_BG,    array( __CLASS__, 'ajax_background' ) );
        add_action( 'wp_ajax_' . self::AJAX_POLL,  array( __CLASS__, 'ajax_poll' ) );
        add_action( 'wp_ajax_' . self::AJAX_SAVE,  array( __CLASS__, 'ajax_save_matrix' ) );
        add_action( 'wp_ajax_' . self::AJAX_PUSH,  array( __CLASS__, 'ajax_push_ingest' ) );
    }

    /* ── 1. Inicia a geração — retorna config ao browser para SSE direto ── */

    public static function ajax_start() {
        check_ajax_referer( self::NONCE_GENERATE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'msg' => 'Permissão insuficiente.' ) );
        }

        $instance_id = (int) ( $_POST['instance_id'] ?? 0 );
        $instance    = Newsgame_Admin_Instances::get( $instance_id );

        if ( ! $instance ) {
            wp_send_json_error( array( 'msg' => 'Instância não encontrada.' ) );
        }

        $backend_url = Newsgame_Settings::backend_url();
        if ( ! $backend_url ) {
            wp_send_json_error( array( 'msg' => 'URL do backend não configurada em News-Game › Configurações.' ) );
        }

        $characters = json_decode( $instance->characters ?? '[]', true ) ?: array();

        // Cálculo de déficit: só gera o que falta para atingir a density alvo
        global $wpdb;
        $approved_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}newsgame_dilemas
             WHERE instance_id = %d AND review_status = 'approved'",
            $instance_id
        ) );
        $density = (int) $instance->density;
        $deficit = $density - $approved_count;

        if ( $deficit <= 0 ) {
            wp_send_json_error( array(
                'msg' => "Meta já atingida: {$approved_count} de {$density} perguntas aprovadas. Aumente a densidade ou descarte algumas para gerar mais.",
            ) );
        }

        // Devolve a configuração ao browser; o JS abre o stream SSE diretamente
        wp_send_json_success( array(
            'backend_url'    => rtrim( $backend_url, '/' ),
            'api_key'        => (string) get_option( 'newsgame_backend_api_key', '' ),
            'approved_count' => $approved_count,
            'deficit'        => $deficit,
            'payload'        => array(
                'site_id'       => home_url(),
                'instance_id'   => $instance_id,
                'theme_prompt'  => $instance->theme_prompt,
                'density'       => $deficit,
                'characters'    => $characters,
                'num_options'   => (int) ( $instance->num_options   ?? 2 ),
                'enable_levels' => (bool) ( $instance->enable_levels ?? false ),
                'nivel_padrao'  => $instance->nivel_padrao ?? 'pedagogico',
            ),
        ) );
    }

    /* ── 2. Background handler — conecta ao Python e processa SSE ── */

    public static function ajax_background() {
        $instance_id = (int) ( $_POST['instance_id'] ?? 0 );
        $token       = sanitize_text_field( $_POST['token'] ?? '' );

        if ( ! $instance_id || get_transient( "newsgame_token_{$instance_id}" ) !== $token ) {
            wp_die( 'Token inválido.' );
        }
        delete_transient( "newsgame_token_{$instance_id}" );

        ignore_user_abort( true );
        set_time_limit( 600 );

        $instance    = Newsgame_Admin_Instances::get( $instance_id );
        $backend_url = Newsgame_Settings::backend_url();
        $api_key     = (string) get_option( 'newsgame_backend_api_key', '' );

        if ( ! $instance || ! $backend_url ) {
            set_transient( "newsgame_progress_{$instance_id}", array(
                'status' => 'error',
                'msg'    => 'Configuração inválida.',
            ), 300 );
            wp_die();
        }

        $characters = json_decode( $instance->characters ?? '[]', true ) ?: array();
        $payload    = array(
            'site_id'       => home_url(),
            'instance_id'   => $instance_id,
            'theme_prompt'  => $instance->theme_prompt,
            'density'       => (int) $instance->density,
            'characters'    => $characters,
            'num_options'   => (int) ( $instance->num_options   ?? 2 ),
            'enable_levels' => (bool) ( $instance->enable_levels ?? false ),
        );

        self::stream_and_save( $instance_id, $backend_url, $api_key, $payload );
        wp_die();
    }

    /**
     * Abre conexão cURL com /generate, processa o SSE stream e salva cada dilema no DB.
     * O cURL WRITEFUNCTION é chamado a cada chunk de dados recebido do servidor Python.
     */
    private static function stream_and_save( $instance_id, $backend_url, $api_key, $payload ) {
        $buffer = '';

        $ch = curl_init( rtrim( $backend_url, '/' ) . '/generate' );
        curl_setopt_array( $ch, array(
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => wp_json_encode( $payload ),
            CURLOPT_HTTPHEADER     => array(
                'Content-Type: application/json',
                'X-Api-Key: ' . $api_key,
            ),
            CURLOPT_TIMEOUT        => 600,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_WRITEFUNCTION  => function( $ch, $data ) use ( $instance_id, &$buffer ) {
                $buffer .= $data;
                self::flush_sse_buffer( $instance_id, $buffer );
                return strlen( $data );
            },
        ) );

        curl_exec( $ch );
        $errno = curl_errno( $ch );

        if ( $errno ) {
            set_transient( "newsgame_progress_{$instance_id}", array(
                'status' => 'error',
                'msg'    => 'Erro cURL ' . $errno . ': ' . curl_error( $ch ),
            ), 300 );
        }

        curl_close( $ch );
    }

    /** Extrai eventos SSE completos do buffer e os despacha. */
    private static function flush_sse_buffer( $instance_id, &$buffer ) {
        while ( ( $pos = strpos( $buffer, "\n\n" ) ) !== false ) {
            $raw    = substr( $buffer, 0, $pos );
            $buffer = substr( $buffer, $pos + 2 );

            if ( strpos( $raw, 'data: ' ) !== 0 ) continue;

            $event = json_decode( substr( $raw, 6 ), true );
            if ( ! $event || ! isset( $event['type'] ) ) continue;

            switch ( $event['type'] ) {

                case 'start':
                    set_transient( "newsgame_progress_{$instance_id}", array(
                        'status'  => 'generating',
                        'current' => 0,
                        'total'   => (int) $event['total'],
                    ), 600 );
                    break;

                case 'dilema':
                    self::save_dilema( $event['dilema'], $instance_id );
                    set_transient( "newsgame_progress_{$instance_id}", array(
                        'status'     => 'generating',
                        'current'    => (int) $event['current'],
                        'total'      => (int) $event['total'],
                        'last_title' => $event['dilema']['titulo'] ?? '',
                    ), 600 );
                    break;

                case 'done':
                    global $wpdb;
                    $wpdb->update(
                        $wpdb->prefix . 'newsgame_instances',
                        array( 'status' => 'review' ),
                        array( 'id'     => $instance_id ),
                        array( '%s' ),
                        array( '%d' )
                    );
                    set_transient( "newsgame_progress_{$instance_id}", array(
                        'status'  => 'done',
                        'current' => (int) $event['total_generated'],
                        'total'   => (int) $event['total_generated'],
                    ), 600 );
                    break;

                case 'error':
                    set_transient( "newsgame_progress_{$instance_id}", array(
                        'status' => 'error',
                        'msg'    => sanitize_text_field( $event['msg'] ?? 'Erro desconhecido.' ),
                    ), 300 );
                    break;

                case 'rate_limit':
                    $progress = get_transient( "newsgame_progress_{$instance_id}" ) ?: array();
                    $progress['rate_limit_sleep'] = (int) ( $event['sleep'] ?? 60 );
                    set_transient( "newsgame_progress_{$instance_id}", $progress, 600 );
                    break;
            }
        }
    }

    /* ── 3. Polling ──────────────────────────────────────────── */

    public static function ajax_poll() {
        check_ajax_referer( self::NONCE_GENERATE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'msg' => 'Permissão insuficiente.' ) );
        }

        $instance_id = (int) ( $_POST['instance_id'] ?? 0 );
        $progress    = get_transient( "newsgame_progress_{$instance_id}" );

        wp_send_json_success( $progress ?: array( 'status' => 'idle' ) );
    }

    /* ── 4. Recebe dilemas do browser e persiste no DB ──────── */

    public static function ajax_save_matrix() {
        check_ajax_referer( self::NONCE_GENERATE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'msg' => 'Permissão insuficiente.' ) );
        }

        $instance_id = (int) ( $_POST['instance_id'] ?? 0 );
        $dilemas     = json_decode( wp_unslash( $_POST['dilemas'] ?? '' ), true );

        if ( ! $instance_id || ! is_array( $dilemas ) || empty( $dilemas ) ) {
            wp_send_json_error( array( 'msg' => 'Dados inválidos ou lista de dilemas vazia.' ) );
        }

        $saved  = 0;
        $failed = 0;
        foreach ( $dilemas as $d ) {
            if ( ! is_array( $d ) ) continue;
            if ( false !== self::save_dilema( $d, $instance_id ) ) {
                $saved++;
            } else {
                $failed++;
            }
        }

        if ( $failed > 0 ) {
            error_log( "[Newsgame] ajax_save_matrix: $failed inserts falharam de " . count( $dilemas ) . ' recebidos. Verifique error_log acima.' );
        }

        if ( $saved === 0 && $failed > 0 ) {
            wp_send_json_error( array(
                'msg'    => "Nenhuma pergunta foi salva ($failed erros de INSERT). Verifique o log do servidor.",
                'failed' => $failed,
            ) );
        }

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'newsgame_instances',
            array( 'status' => 'review' ),
            array( 'id'     => $instance_id ),
            array( '%s' ),
            array( '%d' )
        );

        wp_send_json_success( array( 'saved' => $saved, 'failed' => $failed ) );
    }

    /* ── 5. Push vault → /ingest ─────────────────────────────── */

    public static function ajax_push_ingest() {
        check_ajax_referer( self::NONCE_PUSH, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'msg' => 'Permissão insuficiente.' ) );
        }

        $backend_url = Newsgame_Settings::backend_url();
        if ( ! $backend_url ) {
            wp_send_json_error( array( 'msg' => 'URL do backend não configurada.' ) );
        }

        $vault_files = Newsgame_Ingestor::vault_paths();
        if ( empty( $vault_files ) ) {
            wp_send_json_error( array( 'msg' => 'Vault vazio. Sincronize o acervo primeiro.' ) );
        }

        $api_key    = (string) get_option( 'newsgame_backend_api_key', '' );
        $site_id    = home_url();
        $articles   = array();
        $batch_size = 50;
        $sent       = 0;
        $errors     = array();

        foreach ( $vault_files as $path ) {
            $data = json_decode( file_get_contents( $path ), true );
            if ( ! $data ) continue;

            $articles[] = array(
                'id'      => (int) ( $data['id']      ?? 0 ),
                'type'    => $data['type']    ?? 'posts',
                'title'   => $data['title']   ?? '',
                'excerpt' => $data['excerpt'] ?? '',
                'content' => $data['content'] ?? '',
                'url'     => $data['url']     ?? '',
                'date'    => $data['date']    ?? '',
            );

            if ( count( $articles ) >= $batch_size ) {
                $r = self::post_batch_ingest( $backend_url, $api_key, $site_id, $articles );
                $sent   += $r['indexed'] ?? 0;
                if ( ! empty( $r['error'] ) ) $errors[] = $r['error'];
                $articles = array();
            }
        }

        if ( $articles ) {
            $r = self::post_batch_ingest( $backend_url, $api_key, $site_id, $articles );
            $sent   += $r['indexed'] ?? 0;
            if ( ! empty( $r['error'] ) ) $errors[] = $r['error'];
        }

        wp_send_json_success( array( 'sent' => $sent, 'errors' => $errors ) );
    }

    private static function post_batch_ingest( $backend_url, $api_key, $site_id, $articles ) {
        $response = wp_remote_post(
            rtrim( $backend_url, '/' ) . '/ingest',
            array(
                'timeout'   => 60,
                'sslverify' => false,
                'headers'   => array(
                    'Content-Type' => 'application/json',
                    'X-Api-Key'    => $api_key,
                ),
                'body' => wp_json_encode( array(
                    'site_id'  => $site_id,
                    'articles' => $articles,
                ) ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return array( 'error' => $response->get_error_message() );
        }
        return json_decode( wp_remote_retrieve_body( $response ), true ) ?: array( 'error' => 'Resposta inválida.' );
    }

    /* ── Persistência de dilema no DB ────────────────────────── */

    /**
     * Persiste um dilema no banco. Retorna o número de linhas inseridas (1) ou false em falha.
     *
     * @param array $d           Dilema deserializado do payload Python.
     * @param int   $instance_id ID da instância.
     * @return int|false
     */
    public static function save_dilema( array $d, int $instance_id ) {
        global $wpdb;

        $alts     = $d['alternativas'] ?? array();
        $option_a = wp_json_encode( array(
            'texto'        => $alts[0]['texto']        ?? '',
            'consequencia' => $alts[0]['consequencia'] ?? '',
            'impacto'      => $alts[0]['impacto']      ?: array( 'popularidade' => 0, 'recursos' => 0 ),
        ), JSON_UNESCAPED_UNICODE );

        $option_b = wp_json_encode( array(
            'texto'        => $alts[1]['texto']        ?? '',
            'consequencia' => $alts[1]['consequencia'] ?? '',
            'impacto'      => $alts[1]['impacto']      ?: array( 'popularidade' => 0, 'recursos' => 0 ),
        ), JSON_UNESCAPED_UNICODE );

        // option_c: presente apenas quando num_options=3
        $option_c = isset( $alts[2] ) ? wp_json_encode( array(
            'texto'        => $alts[2]['texto']        ?? '',
            'consequencia' => $alts[2]['consequencia'] ?? '',
            'impacto'      => $alts[2]['impacto']      ?: array( 'popularidade' => 0, 'recursos' => 0 ),
        ), JSON_UNESCAPED_UNICODE ) : null;

        $veredicto = wp_json_encode( array(
            'sintese'             => $d['info_contextual']['sintese'] ?? '',
            'desfecho'            => $d['desfecho_real']              ?? '',
            'licao'               => $d['licao']                      ?? '',
            'alternativa_correta' => $d['alternativa_correta']        ?? '',
        ), JSON_UNESCAPED_UNICODE );

        $result = $wpdb->insert(
            $wpdb->prefix . 'newsgame_dilemas',
            array(
                'instance_id'    => $instance_id,
                'level'          => (int) ( $d['nivel']           ?? 1 ),
                'character_slug' => sanitize_key( $d['personagem_tipo'] ?? 'geral' ),
                'position'       => (int) ( $d['position']        ?? 0 ),
                'title'          => sanitize_text_field( $d['titulo']   ?? '' ),
                'body'           => sanitize_textarea_field( $d['situacao'] ?? '' ),
                'option_a'       => $option_a,
                'option_b'       => $option_b,
                'option_c'       => $option_c,
                'veredicto'      => $veredicto,
                'source_url'     => esc_url_raw( $d['info_contextual']['link_original'] ?? '' ),
                'review_status'  => 'pending',
            ),
            array( '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
        );

        if ( false === $result ) {
            error_log( '[Newsgame] save_dilema FALHOU — MySQL: ' . $wpdb->last_error );
            error_log( '[Newsgame] save_dilema titulo="' . ( $d['titulo'] ?? '' ) . '" instance=' . $instance_id );
        }

        return $result;
    }
}
