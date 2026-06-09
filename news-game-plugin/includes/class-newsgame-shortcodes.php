<?php
defined( 'ABSPATH' ) || exit;

class Newsgame_Shortcodes {

    const NONCE_VERDICT = 'newsgame_verdict';
    const AJAX_VERDICT  = 'newsgame_generate_verdict';

    public static function init() {
        add_shortcode( 'news-game',    array( __CLASS__, 'render' ) );
        add_shortcode( 'jogo_amazonia', array( __CLASS__, 'render_legacy' ) ); // retrocompatibilidade
        add_filter( 'body_class',      array( __CLASS__, 'body_class' ) );
        add_action( 'wp_head',         array( __CLASS__, 'force_viewport' ), 0 );
        add_action( 'wp_ajax_'        . self::AJAX_VERDICT, array( __CLASS__, 'ajax_generate_verdict' ) );
        add_action( 'wp_ajax_nopriv_' . self::AJAX_VERDICT, array( __CLASS__, 'ajax_generate_verdict' ) );
    }

    /**
     * [news-game id="X"] — carrega dilemas do DB e renderiza o jogo.
     */
    public static function render( $atts ) {
        $atts = shortcode_atts( array( 'id' => 0 ), $atts, 'news-game' );
        $id   = (int) $atts['id'];

        if ( ! $id ) {
            return '<p class="newsgame-error">News-Game: informe o ID da instância. Exemplo: [news-game id="1"]</p>';
        }

        $instance = Newsgame_Admin_Instances::get( $id );
        if ( ! $instance ) {
            return '<p class="newsgame-error">News-Game: instância #' . esc_html( $id ) . ' não encontrada.</p>';
        }

        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}newsgame_dilemas
              WHERE instance_id = %d AND review_status = 'approved'
              ORDER BY position ASC, id ASC",
            $id
        ) );

        if ( empty( $rows ) ) {
            return '<p class="newsgame-error" style="padding:1.5rem;font-family:monospace;">'
                 . 'News-Game: instância <strong>' . esc_html( $instance->title ) . '</strong> '
                 . 'não tem perguntas aprovadas para o jogo. Acesse o painel para revisar e aprovar as perguntas geradas.</p>';
        }

        $dilemas = array();
        foreach ( $rows as $d ) {
            $item = array(
                'id'           => (int) $d->id,
                'title'        => $d->title,
                'body'         => $d->body,
                'option_a'     => json_decode( $d->option_a  ?? '{}', true ) ?: array(),
                'option_b'     => json_decode( $d->option_b  ?? '{}', true ) ?: array(),
                'veredicto'    => json_decode( $d->veredicto ?? '{}', true ) ?: array(),
                'source_url'   => $d->source_url ?? '',
                'level'        => (int) $d->level,
                'axis_weights' => $d->axis_weights ? json_decode( $d->axis_weights, true ) : null,
            );
            if ( ! empty( $d->option_c ) ) {
                $item['option_c'] = json_decode( $d->option_c, true ) ?: array();
            }
            $dilemas[] = $item;
        }

        $axis_matrix = $instance->axis_matrix ? json_decode( $instance->axis_matrix, true ) : null;

        $json = wp_json_encode(
            array(
                'title'               => $instance->title,
                'dilemas'             => $dilemas,
                'num_options'         => (int) ( $instance->num_options   ?? 2 ),
                'enable_levels'       => (bool) ( $instance->enable_levels ?? false ),
                'perfis'              => $axis_matrix['perfis_sugeridos'] ?? null,
                'eixos'               => $axis_matrix['eixos_principais']  ?? null,
                'verdict_url'         => admin_url( 'admin-ajax.php' ),
                'verdict_nonce'       => wp_create_nonce( self::NONCE_VERDICT ),
                'verdict_instance_id' => $id,
            ),
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        wp_enqueue_style(
            'newsgame-game',
            NEWSGAME_URL . 'public/css/newsgame-game.css',
            array(),
            NEWSGAME_VERSION
        );
        wp_enqueue_script(
            'newsgame-game',
            NEWSGAME_URL . 'public/js/newsgame-game.js',
            array(),
            NEWSGAME_VERSION,
            true  // footer
        );
        // Injeta os dados antes do script (window._ngGame_N é lido pelo JS ao inicializar)
        wp_add_inline_script(
            'newsgame-game',
            'window._ngGame_' . $id . ' = ' . $json . ';',
            'before'
        );

        return '<div id="ng-game-' . $id . '" class="ng-game-wrap">'
             . '<div class="ng-game-inner"></div>'
             . '</div>';
    }

    /**
     * AJAX — chama /generate-verdict no backend Python e retorna perfil narrativo.
     * Funciona para visitantes logados e anônimos (wp_ajax_nopriv_).
     */
    public static function ajax_generate_verdict() {
        check_ajax_referer( self::NONCE_VERDICT, 'nonce' );

        $instance_id = (int) ( $_POST['instance_id'] ?? 0 );
        $vetor       = json_decode( wp_unslash( $_POST['vetor_jogador'] ?? '{}' ), true );

        if ( ! $instance_id || ! is_array( $vetor ) ) {
            wp_send_json_error( array( 'msg' => 'Dados inválidos.' ) );
        }

        $instance    = Newsgame_Admin_Instances::get( $instance_id );
        $axis_matrix = ( $instance && $instance->axis_matrix )
            ? json_decode( $instance->axis_matrix, true )
            : null;

        if ( ! $axis_matrix
            || empty( $axis_matrix['perfis_sugeridos'] )
            || empty( $axis_matrix['eixos_principais'] )
        ) {
            wp_send_json_error( array( 'msg' => 'Instância sem perfis ou eixos configurados.' ) );
        }

        $backend_url = Newsgame_Settings::backend_url();
        $gemini_key  = (string) Newsgame_Settings::gemini_key();

        if ( ! $backend_url ) {
            wp_send_json_error( array( 'msg' => 'URL do backend não configurada.' ) );
        }

        $payload = array(
            'vetor_jogador'      => $vetor,
            'perfis_disponiveis' => array_values( $axis_matrix['perfis_sugeridos'] ),
            'eixos'              => $axis_matrix['eixos_principais'],
            'gemini_api_key'     => $gemini_key,
        );

        $response = wp_remote_post(
            rtrim( $backend_url, '/' ) . '/generate-verdict',
            array(
                'timeout' => 35,
                'headers' => array(
                    'Content-Type'     => 'application/json',
                    'X-Gemini-Api-Key' => $gemini_key,
                ),
                'body' => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
            )
        );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array( 'msg' => 'Falha de conexão com o backend.' ) );
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        if ( $status !== 200 ) {
            wp_send_json_error( array( 'msg' => "Backend retornou HTTP {$status}." ) );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! $body || ! isset( $body['narrativa'] ) ) {
            wp_send_json_error( array( 'msg' => 'Resposta inválida do backend.' ) );
        }

        wp_send_json_success( $body );
    }

    /**
     * [jogo_amazonia] — delega para o plugin legado se disponível.
     * Mantém 100% de compatibilidade para sites já usando o shortcode antigo.
     */
    public static function render_legacy( $atts ) {
        if ( function_exists( 'jda_shortcode_render' ) ) {
            return jda_shortcode_render( $atts );
        }
        return '<p class="newsgame-error">Plugin legado (jogo-amazonia) não está ativo.</p>';
    }

    public static function body_class( $classes ) {
        global $post;
        if ( $post && has_shortcode( $post->post_content, 'news-game' ) ) {
            $classes[] = 'newsgame-fullscreen';
        }
        return $classes;
    }

    public static function force_viewport() {
        global $post;
        if ( ! $post ) return;
        if ( has_shortcode( $post->post_content, 'news-game' ) ) {
            echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
        }
    }
}
