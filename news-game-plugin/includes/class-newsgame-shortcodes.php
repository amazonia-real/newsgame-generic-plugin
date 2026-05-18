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
                'id'         => (int) $d->id,
                'title'      => $d->title,
                'body'       => $d->body,
                'option_a'   => json_decode( $d->option_a  ?? '{}', true ) ?: array(),
                'option_b'   => json_decode( $d->option_b  ?? '{}', true ) ?: array(),
                'veredicto'  => json_decode( $d->veredicto ?? '{}', true ) ?: array(),
                'source_url' => $d->source_url ?? '',
                'level'      => (int) $d->level,
            );
            if ( ! empty( $d->option_c ) ) {
                $item['option_c'] = json_decode( $d->option_c, true ) ?: array();
            }
            $dilemas[] = $item;
        }

        $json = wp_json_encode(
            array(
                'title'         => $instance->title,
                'dilemas'       => $dilemas,
                'num_options'   => (int) ( $instance->num_options   ?? 2 ),
                'enable_levels' => (bool) ( $instance->enable_levels ?? false ),
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
