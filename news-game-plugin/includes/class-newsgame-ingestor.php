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

    public static function init() {
        add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'handle_ajax' ) );
    }

    /**
     * Sincroniza posts (e opcionalmente páginas) para o vault local.
     *
     * @param  array $types  Post types a indexar. Padrão: ['posts'].
     * @param  int   $per_page  Posts por página na REST API.
     * @return array  { success, saved, skipped, errors, vault_dir }
     */
    public static function sync( $types = array( 'posts' ), $per_page = 50 ) {
        $vault = self::vault_dir();
        if ( is_wp_error( $vault ) ) {
            return array(
                'success' => false,
                'error'   => $vault->get_error_message(),
            );
        }

        $saved  = 0;
        $errors = array();

        foreach ( $types as $type ) {
            $endpoint = rest_url( "/wp/v2/{$type}" );
            $page     = 1;

            do {
                $url      = add_query_arg( array( 'per_page' => $per_page, 'page' => $page ), $endpoint );
                $response = wp_remote_get( $url, array( 'timeout' => 30, 'sslverify' => false ) );

                if ( is_wp_error( $response ) ) {
                    $errors[] = "[{$type} p{$page}] " . $response->get_error_message();
                    break;
                }

                $code = (int) wp_remote_retrieve_response_code( $response );
                if ( 200 !== $code ) {
                    $errors[] = "[{$type} p{$page}] HTTP {$code}";
                    break;
                }

                $items = json_decode( wp_remote_retrieve_body( $response ), true );
                if ( empty( $items ) ) break;

                foreach ( $items as $item ) {
                    $saved += self::save_item( $item, $type, $vault );
                }

                $total_pages = (int) wp_remote_retrieve_header( $response, 'x-wp-totalpages' );
                $page++;

            } while ( $page <= $total_pages );
        }

        return array(
            'success'   => true,
            'saved'     => $saved,
            'errors'    => $errors,
            'vault_dir' => $vault,
        );
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

    /** Handler AJAX — chamado pelo botão "Sincronizar" no painel admin. */
    public static function handle_ajax() {
        check_ajax_referer( self::NONCE_KEY, 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'error' => 'Permissão insuficiente.' ) );
        }

        $types = array( 'posts', 'pages' );
        $result = self::sync( $types );

        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
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

    /** Retorna caminhos absolutos de todos os JSONs no vault. */
    public static function vault_paths() {
        $upload = wp_upload_dir();
        $dir    = trailingslashit( $upload['basedir'] ) . 'newsgame-vault';
        if ( ! is_dir( $dir ) ) return array();
        $files = glob( $dir . '/*.json' );
        return $files ?: array();
    }
}
