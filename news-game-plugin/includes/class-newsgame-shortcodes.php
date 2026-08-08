<?php
defined( 'ABSPATH' ) || exit;

class Newsgame_Shortcodes {

    public static function init() {
        add_shortcode( 'news-game',    array( __CLASS__, 'render' ) );
        add_shortcode( 'jogo_amazonia', array( __CLASS__, 'render_legacy' ) ); // retrocompatibilidade
        add_filter( 'body_class',      array( __CLASS__, 'body_class' ) );
        add_action( 'wp_head',         array( __CLASS__, 'force_viewport' ), 0 );
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
        // Dados do jogo num bloco de MIME não-executável — a CSP `script-src` não
        // governa `application/json`, o navegador trata como dado e não como código.
        // Não usar wp_add_inline_script aqui: exigiria 'unsafe-inline' no site
        // hospedeiro. Ver TECH_FLAGS FLAG 007.
        return '<div id="ng-game-' . $id . '" class="ng-game-wrap">'
             . '<script type="application/json" id="ng-game-data-' . $id . '">'
             . $json  // JSON_HEX_TAG no wp_json_encode impede fechar </script> de dentro do JSON
             . '</script>'
             . '<div class="ng-game-inner"></div>'
             . '</div>';
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
