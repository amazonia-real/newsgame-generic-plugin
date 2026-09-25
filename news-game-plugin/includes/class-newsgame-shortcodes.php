<?php
defined( 'ABSPATH' ) || exit;

class Newsgame_Shortcodes {

    /** Tamanho servido no fundo do dilema. Ver register_image_size(). */
    const BG_SIZE = 'newsgame-bg';

    public static function init() {
        add_shortcode( 'news-game',    array( __CLASS__, 'render' ) );
        add_shortcode( 'jogo_amazonia', array( __CLASS__, 'render_legacy' ) ); // retrocompatibilidade
        add_filter( 'body_class',      array( __CLASS__, 'body_class' ) );
        add_action( 'wp_head',         array( __CLASS__, 'force_viewport' ), 0 );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_public' ) );
        add_action( 'after_setup_theme', array( __CLASS__, 'register_image_size' ) );
    }

    /**
     * Tamanho próprio para o fundo do dilema: 1600px de largura, altura livre,
     * sem crop.
     *
     * Nem `large` nem `full`. O `large` padrão do WP (1024px) fica mole em fundo
     * sangrado em tela larga; o `full` entrega o original, que pode passar de
     * 1,5MB — e o público real é leitor de celular com conexão ruim.
     *
     * RESSALVA: tamanho registrado só vale para imagem enviada DEPOIS do
     * registro. Quem já tem biblioteca precisa regenerar as miniaturas
     * (plugin de regeneração ou WP-CLI). Sem isso, `wp_get_attachment_image_url`
     * cai no original — funciona, mas sem o ganho de peso. Documentado, não
     * resolvido: regenerar biblioteca de terceiro não é decisão nossa.
     */
    public static function register_image_size() {
        add_image_size( self::BG_SIZE, 1600, 0, false );
    }

    /**
     * Resolve o anexo do dilema para o que o cliente precisa: URL e crédito.
     *
     * O ID é resolvido AQUI, no servidor, e não no cliente, por três razões: o
     * JS não sabe resolver ID de anexo; o JSON passa a carregar URL simples e o
     * jogo não precisa saber nada de WordPress; e se o anexo foi excluído a
     * resolução falha aqui, a chave é omitida do payload e a cascata de fundo
     * assume sem o cliente ter de adivinhar.
     *
     * O crédito vem da LEGENDA do anexo (`post_excerpt`), não de coluna própria:
     * uma imagem reusada por N dilemas tem um crédito só, editado na Biblioteca
     * de Mídia, sem deriva possível entre os dilemas.
     */
    private static function image_payload( $image_id ) {
        $image_id = (int) $image_id;
        if ( ! $image_id ) {
            return null;
        }

        $url = wp_get_attachment_image_url( $image_id, self::BG_SIZE );
        if ( ! $url ) {
            return null; // anexo excluído ou não é imagem — cai no fallback
        }

        $payload = array( 'url' => esc_url_raw( $url ) );

        $credito = wp_get_attachment_caption( $image_id );
        if ( $credito ) {
            $payload['credito'] = sanitize_text_field( $credito );
        }

        return $payload;
    }

    /**
     * Enfileira CSS e JS do jogador no `wp_enqueue_scripts` — e não de dentro do
     * shortcode, que era A CAUSA RAIZ do FOUC corrigido em 2026-08-29.
     *
     * O `render()` roda durante `the_content()`, depois de o `wp_head` já ter
     * impresso as folhas de estilo. O WordPress então imprimia a folha no
     * RODAPÉ (`print_late_styles()`), e o primeiro paint da página acontecia sem
     * uma linha do CSS do plugin: o jogo aparecia confinado no container do
     * tema, sem fundo, e o botão de tela cheia renderizava como item de flex
     * comum em vez de no canto. Enquanto o layout confinado ERA o desenho isso
     * passava como um piscar; com o full-bleed de 29/08 virou salto visível.
     *
     * O `render()` CONTINUA enfileirando, de propósito: `wp_enqueue_style` é
     * idempotente por handle, e a duplicidade é a rede para o caso em que este
     * hook não alcança — shortcode vindo de construtor de página ou de template,
     * que não está no `post_content` e portanto não passa no `has_shortcode()`. É
     * exatamente o mesmo limite que `body_class()` e `force_viewport()` já têm.
     */
    public static function enqueue_public() {
        global $post;
        if ( ! $post || ! has_shortcode( $post->post_content, 'news-game' ) ) {
            return;
        }

        self::enqueue_base();

        /*
         * A skin depende do ID da instância, que só existe nos atributos do
         * shortcode — daí a extração abaixo. Sem ela o base viria no <head> e a
         * skin no rodapé, e o jogo piscaria a paleta padrão antes de assumir a
         * da instância, que é o mesmo defeito num grau menor.
         */
        foreach ( self::instance_ids_in( $post->post_content ) as $id ) {
            $instance = Newsgame_Admin_Instances::get( $id );
            if ( $instance ) {
                self::enqueue_skin( Newsgame_Admin_Instances::sanitize_skin( $instance->skin ?? '' ) );
            }
        }
    }

    /**
     * IDs de instância declarados nos shortcodes `[news-game]` do conteúdo.
     *
     * Usa as APIs do próprio WordPress (`get_shortcode_regex` +
     * `shortcode_parse_atts`) em vez de regex própria: o formato de shortcode
     * tem casos que uma regex à mão erra — atributo entre aspas simples, forma
     * fechada, shortcode escapado com colchete duplo.
     *
     * Exercitada em 2026-08-29 com o WordPress carregado, em 9 formas de
     * conteúdo. Oito saíram como esperado (aspas duplas, sem aspas, aspas
     * simples, duas instâncias, duplicada deduplicada, sem `id`, outro
     * shortcode, conteúdo vazio).
     *
     * ⚠️ O nono é desvio conhecido e ACEITO: shortcode **escapado**
     * (`[[news-game id="9"]]`) devolve o ID, embora o WordPress imprima o
     * shortcode literal e não renderize o jogo. Custo: uma requisição de folha
     * de skin numa página onde o jogo não aparece — sem efeito visual. Não é
     * defeito introduzido aqui: o `has_shortcode()` logo acima tem o mesmo
     * comportamento, e por isso `body_class()` e `force_viewport()` já agem
     * nesse caso desde antes. Distinguir escapado exigiria não usar
     * `has_shortcode()`, o que trocaria um custo irrelevante por divergência
     * entre os três pontos.
     *
     * @return int[] IDs únicos, já convertidos para inteiro.
     */
    private static function instance_ids_in( $content ) {
        $ids     = array();
        $pattern = get_shortcode_regex( array( 'news-game' ) );

        if ( ! preg_match_all( '/' . $pattern . '/', $content, $matches, PREG_SET_ORDER ) ) {
            return $ids;
        }

        foreach ( $matches as $m ) {
            $atts = shortcode_parse_atts( $m[3] );
            $id   = ( is_array( $atts ) && isset( $atts['id'] ) ) ? (int) $atts['id'] : 0;
            if ( $id ) {
                $ids[] = $id;
            }
        }

        return array_unique( $ids );
    }

    /** Folha base e lógica do jogo. Idempotente: o handle é sempre o mesmo. */
    private static function enqueue_base() {
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
    }

    /**
     * Skin de fábrica — H2, degrau 2.
     *
     * Arquivo enfileirado pelo wp_enqueue_style nativo, e nunca <style>
     * inline: inline exigiria 'unsafe-inline' em style-src no site hospedeiro e
     * desfaria o ganho do A1. Ver FLAG 007 e o A5.
     *
     * 'default' NÃO tem arquivo — é a própria folha base. Daí o `if`, em vez de
     * um skin-default.css vazio: arquivo vazio seria uma requisição por render
     * para não mudar nada.
     *
     * A dependência garante que o base esteja na página antes; quem garante que
     * a skin VENÇA é a especificidade do seletor dela (0-2-0 contra 0-1-0), não
     * esta ordem — ver o cabeçalho dos arquivos de skin.
     *
     * Sem file_exists() de propósito: o slug vem de whitelist fechada e as
     * cinco folhas são versionadas com o plugin — o mesmo vale para as artes da
     * skin `pixel`, que a folha dela referencia por caminho relativo. Num
     * deploy quebrado, o 404 aparece no devtools e o jogo renderiza com a
     * paleta base; um skip silencioso renderizaria igual e não apareceria em
     * lugar nenhum.
     */
    private static function enqueue_skin( $skin ) {
        if ( Newsgame_Admin_Instances::SKIN_DEFAULT === $skin ) {
            return;
        }

        wp_enqueue_style(
            'newsgame-skin-' . $skin,
            NEWSGAME_URL . 'public/css/skins/skin-' . $skin . '.css',
            array( 'newsgame-game' ),
            NEWSGAME_VERSION
        );
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
            // O estilo desta mensagem mora em `.newsgame-error-vazio`, no CSS base,
            // e não mais em atributo de estilo inline (A5). O `enqueue_base()` garante a folha
            // também quando o shortcode está fora do `post_content`, onde o
            // `enqueue_public()` não a trouxe: o enfileiramento normal do `render()`
            // fica mais abaixo, depois deste `return`. Idempotente por handle.
            self::enqueue_base();
            return '<p class="newsgame-error newsgame-error-vazio">'
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
            // Chave omitida quando não há imagem — mesmo padrão condicional do
            // option_c. No cliente, "tem imagem?" é teste de verdade simples.
            $imagem = self::image_payload( $d->image_id ?? 0 );
            if ( $imagem ) {
                $item['imagem'] = $imagem;
            }
            $dilemas[] = $item;
        }

        $axis_matrix = $instance->axis_matrix ? json_decode( $instance->axis_matrix, true ) : null;

        /*
         * Skin da instância, normalizada na leitura pelo mesmo helper que
         * normaliza na escrita: linha gravada por SQL à mão, ou anterior à
         * migração 1.6, devolve 'default' — então nada abaixo precisa de guarda
         * própria, e o valor NUNCA é concatenado cru no caminho do arquivo.
         *
         * O `?? ''` cobre a linha sem a coluna: sem ele, acesso a propriedade
         * inexistente emite warning no PHP 8.
         */
        $skin = Newsgame_Admin_Instances::sanitize_skin( $instance->skin ?? '' );

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

        /*
         * Enfileiramento de rede: o caminho principal é o `wp_enqueue_scripts`
         * (enqueue_public()), que coloca a folha no <head>. Estas chamadas ficam
         * como fallback para o caso em que aquele hook não alcança — shortcode
         * fora do `post_content`. `wp_enqueue_style` é idempotente por handle,
         * então quando os dois caminhos rodam nada é duplicado.
         */
        self::enqueue_base();
        self::enqueue_skin( $skin );
        // Dados do jogo num bloco de MIME não-executável — a CSP `script-src` não
        // governa `application/json`, o navegador trata como dado e não como código.
        // Não usar wp_add_inline_script aqui: exigiria 'unsafe-inline' no site
        // hospedeiro. Ver TECH_FLAGS FLAG 007.
        // A classe da skin é o que dá especificidade ao seletor do arquivo dela.
        // 'default' também recebe a sua (`ng-skin-default`), por uniformidade e
        // porque vira gancho de CSS sem custo.
        return '<div id="ng-game-' . $id . '" class="ng-game-wrap ng-skin-' . esc_attr( $skin ) . '">'
             . '<script type="application/json" id="ng-game-data-' . $id . '">'
             . $json  // JSON_HEX_TAG no wp_json_encode impede fechar </script> de dentro do JSON
             . '</script>'
             . '<div class="ng-game-inner"></div>'
             // Crédito da foto de fundo. Vem vazio e com `hidden`: o JS preenche
             // e revela quando o dilema tem imagem com legenda. Usa o atributo
             // `hidden` em vez de esconder por atributo de estilo, que somaria
             // ao A5. (Sem escrever o atributo aqui nem em comentário: a
             // auditoria do A5 é um grep, e comentário sujaria a contagem.)
             . '<div class="ng-photo-credit" hidden></div>'
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
