<?php
defined( 'ABSPATH' ) || exit;

/**
 * Newsgame_Ingestor
 *
 * Consome a WP REST API local (/wp/v2/posts e /wp/v2/pages), extrai
 * título, excerpt e conteúdo limpo, e persiste cada item como JSON
 * em {uploads}/newsgame-vault/{id}.json.
 *
 * O vault é o acervo bruto que alimenta o pipeline RAG (ChromaDB).
 * O editor pode acionar a sincronização pelo painel Admin a qualquer momento.
 */
class Newsgame_Ingestor {

    const AJAX_ACTION = 'newsgame_sync_vault';
    const NONCE_KEY   = 'newsgame_ingestor';

    /**
     * Tamanhos de lote. Cada chamada AJAX processa um lote e termina bem abaixo do
     * limite de tempo típico de hospedagem comum (~20 s), sem set_time_limit.
     * LOTE_SYNC: itens por página lida do REST do próprio site.
     * Envio ao /ingest: o lote fecha no PRIMEIRO destes limites, e tem sempre ao
     * menos 1 item. O tempo do backend cresce com o texto (cada 1000 caracteres
     * viram um trecho), e não com o número de posts.
     *   LOTE_PUSH_MAX_ITENS:      no máximo estes artigos por lote;
     *   LOTE_PUSH_MAX_CARACTERES: soma do conteúdo dos artigos do lote;
     *   LIMITE_ITEM_CARACTERES:   um artigo maior que isto é enviado só até aqui
     *                             (corte no fim de uma palavra).
     */
    const LOTE_SYNC = 25;
    const LOTE_PUSH_MAX_ITENS = 10;
    const LOTE_PUSH_MAX_CARACTERES = 20000;
    const LIMITE_ITEM_CARACTERES = 30000;

    public static function init() {
        add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'handle_ajax' ) );
    }

    /**
     * Persiste um único item REST no vault como JSON.
     * Sobrescreve sempre — a sincronização é idempotente.
     *
     * @return int  1 se salvou, 0 em caso de falha.
     */
    private static function save_item( $item, $type, $vault_dir ) {
        $id      = (int) ( $item['id'] ?? 0 );
        $title   = wp_strip_all_tags( $item['title']['rendered'] ?? '' );
        $excerpt = wp_strip_all_tags( $item['excerpt']['rendered'] ?? '' );
        $content = wp_strip_all_tags( $item['content']['rendered'] ?? '' );
        $url     = $item['link'] ?? '';
        $date    = $item['date'] ?? '';

        if ( ! $id || ! $title ) return 0;

        $record = array(
            'id'      => $id,
            'type'    => $type,
            'title'   => $title,
            'excerpt' => $excerpt,
            'content' => $content,
            'url'     => $url,
            'date'    => $date,
            'synced'  => current_time( 'c' ),
        );

        $file   = trailingslashit( $vault_dir ) . "{$type}_{$id}.json";
        $result = file_put_contents( $file, wp_json_encode( $record, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );

        return $result !== false ? 1 : 0;
    }

    /* ── Cursor de progresso (uma option por coleção) ──────────
       Gravado no servidor ao fim de cada lote bem-sucedido. O nome leva um hash
       curto do home_url(): o site_id do backend é o mesmo, e é ele que separa as
       coleções. Sem autoload — só a tela de Configurações lê. */

    private static function chave_cursor( $nome ) {
        return 'newsgame_cursor_' . $nome . '_' . substr( md5( home_url() ), 0, 8 );
    }

    public static function cursor_get( $nome ) {
        $c = get_option( self::chave_cursor( $nome ), null );
        return is_array( $c ) ? $c : null;
    }

    public static function cursor_set( $nome, array $cursor ) {
        update_option( self::chave_cursor( $nome ), $cursor, false );
    }

    public static function cursor_clear( $nome ) {
        delete_option( self::chave_cursor( $nome ) );
    }

    /** Parada da sincronização para a tela: { processados, total } ou null. */
    public static function estado_cursor_sync() {
        $c = self::cursor_get( 'sync' );
        if ( ! $c ) {
            return null;
        }
        $total = (int) ( $c['total_itens'] ?? 0 );
        return array(
            'processados' => min( (int) $c['pagina'] * self::LOTE_SYNC, $total ),
            'total'       => $total,
        );
    }

    /**
     * Um passo da sincronização: UMA página do REST (LOTE_SYNC itens), em ordem de
     * ID crescente. Ordem dos tipos: posts, depois pages. O cursor guarda a última
     * página concluída; sincronizar é idempotente (sobrescreve o JSON do item).
     *
     * @return array  Dados do passo, ou array( 'erro' => array( codigo, http, detalhe, msg ) ).
     */
    public static function passo_sync( $recomecar ) {
        $vault = self::vault_dir();
        if ( is_wp_error( $vault ) ) {
            return array( 'erro' => array( 'codigo' => 'cofre', 'msg' => $vault->get_error_message() ) );
        }
        if ( $recomecar ) {
            self::cursor_clear( 'sync' );
        }

        $c = self::cursor_get( 'sync' );
        if ( ! $c ) {
            $tipo = 'posts'; $pagina = 1; $salvos = 0;
        } elseif ( (int) $c['pagina'] < (int) $c['total_paginas'] ) {
            $tipo = $c['tipo']; $pagina = (int) $c['pagina'] + 1; $salvos = (int) $c['salvos'];
        } else {
            $tipo = 'pages'; $pagina = 1; $salvos = (int) $c['salvos'];
        }

        $r = self::ler_pagina_rest( $tipo, $pagina );
        if ( isset( $r['erro'] ) ) {
            return $r;
        }

        $lote = 0;
        foreach ( $r['itens'] as $item ) {
            $lote += self::save_item( $item, $tipo, $vault );
        }
        $salvos += $lote;

        $acabou_tipo = $pagina >= $r['total_paginas'];
        $feito       = $acabou_tipo && 'pages' === $tipo;
        if ( $feito ) {
            self::cursor_clear( 'sync' );
        } else {
            self::cursor_set( 'sync', array(
                'tipo' => $tipo, 'pagina' => $pagina, 'total_paginas' => $r['total_paginas'],
                'total_itens' => $r['total_itens'], 'salvos' => $salvos,
            ) );
        }

        return array(
            'feito'       => $feito,
            'tipo'        => $tipo,
            'processados' => min( $pagina * self::LOTE_SYNC, $r['total_itens'] ),
            'total'       => $r['total_itens'],
            'salvos_lote' => $lote,
            'salvos'      => $salvos,
            'vault_total' => count( self::vault_paths() ),
        );
    }

    /** Lê uma página do REST do próprio site. Devolve itens/totais ou 'erro'. */
    private static function ler_pagina_rest( $tipo, $pagina ) {
        $url = add_query_arg( array(
            'per_page' => self::LOTE_SYNC,
            'page'     => $pagina,
            'orderby'  => 'id',
            'order'    => 'asc',
            '_fields'  => 'id,title,excerpt,content,link,date',
        ), rest_url( "/wp/v2/{$tipo}" ) );

        $response = wp_remote_get( $url, array(
            'timeout'   => 15,
            'sslverify' => ! ( defined( 'WP_DEBUG' ) && WP_DEBUG && str_contains( $url, 'localhost' ) ),
        ) );

        if ( is_wp_error( $response ) ) {
            error_log( "[Newsgame] sincronização: {$tipo} página {$pagina} sem resposta da API REST." );
            return array( 'erro' => array( 'codigo' => 'rest_inacessivel', 'detalhe' => mb_substr( $response->get_error_message(), 0, 200 ) ) );
        }

        $http = (int) wp_remote_retrieve_response_code( $response );
        // Página além do fim (a lista encolheu desde a anterior): fim do tipo, não erro.
        if ( 400 === $http && $pagina > 1 ) {
            return array( 'itens' => array(), 'total_paginas' => $pagina - 1, 'total_itens' => ( $pagina - 1 ) * self::LOTE_SYNC );
        }
        if ( 200 !== $http ) {
            error_log( "[Newsgame] sincronização: {$tipo} página {$pagina} devolveu HTTP {$http}." );
            return array( 'erro' => array( 'codigo' => 'rest_http', 'http' => $http ) );
        }

        $itens = json_decode( wp_remote_retrieve_body( $response ), true );
        return array(
            'itens'         => is_array( $itens ) ? $itens : array(),
            'total_paginas' => (int) wp_remote_retrieve_header( $response, 'x-wp-totalpages' ),
            'total_itens'   => (int) wp_remote_retrieve_header( $response, 'x-wp-total' ),
        );
    }

    /** Handler AJAX — um lote por chamada; o navegador repete até `feito`. */
    public static function handle_ajax() {
        check_ajax_referer( self::NONCE_KEY, 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'codigo' => 'permissao', 'msg' => 'Permissão insuficiente.' ) );
        }

        $recomecar = ! empty( $_POST['recomecar'] ) && '0' !== (string) wp_unslash( $_POST['recomecar'] );
        $r         = self::passo_sync( $recomecar );

        if ( isset( $r['erro'] ) ) {
            wp_send_json_error( $r['erro'] );
        }
        wp_send_json_success( $r );
    }

    /**
     * Garante que o diretório vault existe e é gravável.
     *
     * @return string|WP_Error  Caminho absoluto ou erro.
     */
    private static function vault_dir() {
        $upload = wp_upload_dir();
        if ( ! empty( $upload['error'] ) ) {
            return new WP_Error( 'upload_dir', $upload['error'] );
        }

        $dir = trailingslashit( $upload['basedir'] ) . 'newsgame-vault';

        if ( ! file_exists( $dir ) && ! wp_mkdir_p( $dir ) ) {
            return new WP_Error( 'mkdir', "Não foi possível criar: {$dir}" );
        }

        if ( ! is_writable( $dir ) ) {
            return new WP_Error( 'writable', "Sem permissão de escrita em: {$dir}" );
        }

        // Protege o vault de acesso HTTP direto
        $htaccess = $dir . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            file_put_contents( $htaccess, "deny from all\n" );
        }

        return $dir;
    }

    /** Retorna basenames dos arquivos no vault (para contagem no painel). */
    public static function vault_index() {
        $files = self::vault_paths();
        return $files ? array_map( 'basename', $files ) : array();
    }

    /**
     * Arquivos do vault em ordem de ID crescente: array de { id, path }.
     * O ID vem do nome `{tipo}_{id}.json`; posts e páginas dividem o mesmo espaço
     * de IDs do WordPress, então não há colisão.
     */
    public static function vault_paths_por_id() {
        $lista = array();
        foreach ( self::vault_paths() as $path ) {
            $id      = preg_match( '/_(\d+)\.json$/', $path, $m ) ? (int) $m[1] : 0;
            $lista[] = array( 'id' => $id, 'path' => $path );
        }
        usort( $lista, function ( $a, $b ) {
            return $a['id'] <=> $b['id'] ?: strcmp( $a['path'], $b['path'] );
        } );
        return $lista;
    }

    /** Retorna caminhos absolutos de todos os JSONs no vault. */
    public static function vault_paths() {
        $upload = wp_upload_dir();
        $dir    = trailingslashit( $upload['basedir'] ) . 'newsgame-vault';
        if ( ! is_dir( $dir ) ) return array();
        $files = glob( $dir . '/*.json' );
        return $files ?: array();
    }
}
