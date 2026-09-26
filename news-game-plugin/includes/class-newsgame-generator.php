<?php
/**
 * Newsgame_Generator
 *
 * Ponte WordPress → Backend Python.
 * Fluxo de geração (proxy server-to-server):
 *   1. ajax_start()         — valida instância e retorna o payload ao browser
 *   2. ajax_generate_one()  — proxy: repassa cada chamada ao /generate-one do backend
 *   3. ajax_save_matrix()   — recebe dilemas do browser após o loop e salva no DB
 *   4. ajax_push_ingest()   — empurra vault local para o endpoint /ingest do backend
 *
 * O JS do painel chama ajax_generate_one() em loop (uma chamada por dilema).
 * A URL do backend e a X-Api-Key ficam só no servidor — nunca chegam ao browser.
 */
defined( 'ABSPATH' ) || exit;

class Newsgame_Generator {

    const AJAX_START        = 'newsgame_start_generation';
    const AJAX_GENERATE_ONE = 'newsgame_generate_one';
    const AJAX_SAVE         = 'newsgame_save_matrix';
    const AJAX_PUSH         = 'newsgame_push_ingest';
    const NONCE_GENERATE    = 'newsgame_generate';
    const NONCE_PUSH        = 'newsgame_push_ingest';

    public static function init() {
        add_action( 'wp_ajax_' . self::AJAX_START,        array( __CLASS__, 'ajax_start' ) );
        add_action( 'wp_ajax_' . self::AJAX_GENERATE_ONE, array( __CLASS__, 'ajax_generate_one' ) );
        add_action( 'wp_ajax_' . self::AJAX_SAVE,         array( __CLASS__, 'ajax_save_matrix' ) );
        add_action( 'wp_ajax_' . self::AJAX_PUSH,         array( __CLASS__, 'ajax_push_ingest' ) );
    }

    /* ── 1. Inicia a geração — valida instância e retorna o payload ── */

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

        // Devolve o payload ao browser; o JS chama ajax_generate_one() em loop.
        // backend_url e api_key NÃO saem do servidor — o proxy resolve ambos.
        wp_send_json_success( array(
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
                'axis_matrix'   => $instance->axis_matrix
                                       ? json_decode( $instance->axis_matrix, true )
                                       : null,
            ),
        ) );
    }

    /* ── 2. Proxy server-to-server para /generate-one ────────── */

    /**
     * Repassa uma chamada de geração ao backend Python, preservando o
     * status HTTP original (o JS depende de 429 para retry e 422 para skip).
     * O payload JSON viaja cru no body; action e nonce vão na query string.
     */
    public static function ajax_generate_one() {
        check_ajax_referer( self::NONCE_GENERATE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json( array( 'detail' => 'Permissão insuficiente.' ), 403 );
        }

        // Teto alto de propósito: o loop legítimo faz até ~15 chamadas (limite de
        // density do formulário) mais as retentativas. Serve contra loop de JS com
        // bug, que faria centenas por segundo — não contra uso normal.
        // O 429 já tem tratamento no JS: espera 60 s e retenta o mesmo slot.
        if ( Newsgame_Settings::rate_limited( 'generate_one', 60 ) ) {
            wp_send_json( array( 'detail' => 'Limite de geração por minuto atingido. Aguarde e tente novamente.' ), 429 );
        }

        $backend_url = Newsgame_Settings::backend_url();
        if ( ! $backend_url ) {
            wp_send_json( array( 'detail' => 'URL do backend não configurada em News-Game › Configurações.' ), 500 );
        }

        $payload = json_decode( file_get_contents( 'php://input' ), true );
        if ( ! is_array( $payload ) ) {
            wp_send_json( array( 'detail' => 'Payload JSON inválido.' ), 400 );
        }

        /*
         * A CHAVE DO PROVEDOR ATIVO VAI EM HEADER, E SÓ EM HEADER — fase 0 do H1,
         * e desde o B6 o provedor sai do painel em vez de ser sempre o Gemini.
         *
         * Antes deste passo o proxy não mandava chave nenhuma, e o backend caía
         * no `GEMINI_API_KEY` do `.env` dele. Ou seja: a chave configurada em
         * News-Game › Configurações **não governava a geração de dilemas**, que
         * é o miolo do produto. Medido em 2026-09-04: com `.env` inválido e
         * painel válido, esta rota devolvia 422 "não foi possível gerar um
         * dilema válido"; com o header, gerou normalmente.
         *
         * ⚠️ NÃO PÔR A CHAVE NO CORPO AQUI. O `$payload` desta requisição vem
         * do browser (`php://input`, linha 118) e é repassado verbatim —
         * escrever segredo nele exigiria decodificar e recodificar, e poria a
         * chave num campo que o cliente controla. O header é acrescentado
         * server-side, neste ponto, e não trafega para o browser em momento
         * nenhum. O backend aceita as duas formas (header > body > env); esta
         * rota usa a primeira, de propósito.
         *
         * ⚠️ E NÃO ACRESCENTAR A CHAVE AO PAYLOAD DE `ajax_start()` (:76-88),
         * que é justamente o que sai daqui para o JS. Ver o comentário do campo
         * `gemini_api_key` em `backend-python/app/models.py`.
         *
         * ⚠️ O MESMO VALE PARA O `X-LLM-Provider` que `provider_headers()`
         * acrescenta: ele é header, e o provedor NÃO tem campo no corpo em
         * `models.py` — por decisão do lado Python, e não por esquecimento. Ver
         * `_resolve_provedor()` em `main.py:245`.
         */
        $response = wp_remote_post(
            rtrim( $backend_url, '/' ) . '/generate-one',
            array(
                'timeout'   => 120,
                'sslverify' => ! ( defined( 'WP_DEBUG' ) && WP_DEBUG && str_contains( $backend_url, 'localhost' ) ),
                'headers'   => array_merge(
                    array(
                        'Content-Type' => 'application/json',
                        'X-Api-Key'    => (string) get_option( 'newsgame_backend_api_key', '' ),
                    ),
                    Newsgame_Settings::provider_headers()
                ),
                'body' => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
            )
        );

        if ( is_wp_error( $response ) ) {
            wp_send_json( array( 'detail' => 'Falha de conexão com o backend: ' . $response->get_error_message() ), 502 );
        }

        // Body e status do Python repassados sem alteração ao browser.
        $status = (int) wp_remote_retrieve_response_code( $response );
        $body   = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( null === $body ) {
            $body = array( 'detail' => substr( wp_remote_retrieve_body( $response ), 0, 300 ) );
        }
        wp_send_json( $body, $status ?: 502 );
    }

    /* ── 3. Recebe dilemas do browser e persiste no DB ──────── */

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
        $promovida = $wpdb->update(
            $wpdb->prefix . 'newsgame_instances',
            array( 'status' => 'review' ),
            array( 'id'     => $instance_id ),
            array( '%s' ),
            array( '%d' )
        );

        // Retorno conferido — C13, a terceira escrita. Só `false` é falha: 0 é a
        // instância que já estava em 'review' (segunda geração seguida).
        //
        // A mensagem diz que as perguntas FORAM salvas porque foram: os inserts
        // acima já estão no banco, e "nada foi salvo" seria mentira no sentido
        // oposto. O que ficou errado é só o status, recuperável salvando o
        // formulário — mesmo critério do reset de peso no ajax_approve().
        if ( false === $promovida ) {
            $erro = $wpdb->last_error ?: 'o banco não informou a causa.';
            error_log( "[Newsgame] ajax_save_matrix: promoção da instância {$instance_id} para review falhou — MySQL: {$erro}" );
            wp_send_json_error( array(
                'msg'    => "As $saved pergunta(s) FORAM salvas, mas o status da instância não passou para"
                    . ' "Em Revisão". Recarregue a página e use "Salvar e Preparar para Geração ›". Erro do banco: ' . $erro,
                'saved'  => $saved,
                'failed' => $failed,
            ) );
        }

        wp_send_json_success( array( 'saved' => $saved, 'failed' => $failed ) );
    }

    /* ── 4. Push vault → /ingest ─────────────────────────────── */

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
                'sslverify' => ! ( defined( 'WP_DEBUG' ) && WP_DEBUG && str_contains( $backend_url, 'localhost' ) ),
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
