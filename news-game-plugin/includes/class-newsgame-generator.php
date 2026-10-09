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

    // Maior posição aceita como pedida no salvamento: o teto da meta do conjunto.
    // As posições são preenchidas a partir da menor livre, então nenhuma viva
    // legítima passa disso; acima, a pedida é tratada como inválida.
    const POSICAO_MAXIMA    = 120;

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

        // Déficit = meta − (aprovadas + pendentes). Descartadas não contam: não vão
        // ao jogo. Antes só as aprovadas contavam, e cada clique com pendentes na
        // Mesa gerava a meta inteira de novo — medido em 02/10 na instância 8:
        // 3 pendentes, 4 chamadas na segunda rodada, posição 1 repetida.
        $contagem = self::contar_vivas( $instance_id );
        if ( null === $contagem ) {
            wp_send_json_error( array( 'msg' => 'Não foi possível ler as perguntas da instância. Verifique o log do servidor.' ) );
        }
        $density = (int) $instance->density;
        $deficit = $density - $contagem['approved'] - $contagem['pending'];

        if ( $deficit <= 0 ) {
            wp_send_json_error( array(
                'msg' => "Meta já preenchida: {$contagem['approved']} aprovadas + {$contagem['pending']} pendentes de {$density}. Revise na Mesa Editorial ou aumente a meta.",
            ) );
        }

        // As duas listas saem do banco e não do browser. Lista vazia por erro de
        // SELECT faria o lote reusar posições ocupadas e repetir fontes — por isso
        // o null aborta, em vez de virar array() em silêncio.
        $posicoes = self::posicoes_livres( $instance_id, $deficit );
        $fontes   = self::fontes_da_instancia( $instance_id );
        if ( null === $posicoes || null === $fontes ) {
            wp_send_json_error( array( 'msg' => 'Não foi possível ler as perguntas da instância. Verifique o log do servidor.' ) );
        }

        // Devolve o payload ao browser; o JS chama ajax_generate_one() em loop.
        // backend_url e api_key NÃO saem do servidor — o proxy resolve ambos.
        wp_send_json_success( array(
            'approved_count'  => $contagem['approved'],
            'pending_count'   => $contagem['pending'],
            'deficit'         => $deficit,
            'posicoes_livres' => $posicoes,
            'fontes_usadas'   => $fontes,
            'payload'         => array(
                'site_id'       => home_url(),
                'instance_id'   => $instance_id,
                'theme_prompt'  => $instance->theme_prompt,
                'density'       => $deficit,
                'characters'    => $characters,
                'num_options'   => (int) ( $instance->num_options   ?? 2 ),
                'nivel_padrao'  => $instance->nivel_padrao ?? 'pedagogico',
                'axis_matrix'   => $instance->axis_matrix
                                       ? json_decode( $instance->axis_matrix, true )
                                       : null,
            ),
        ) );
    }

    /**
     * Aprovadas e pendentes da instância — as que contam para a meta.
     * Devolve null em erro de SELECT, para o chamador abortar.
     */
    private static function contar_vivas( int $instance_id ): ?array {
        global $wpdb;
        $linhas = $wpdb->get_results( $wpdb->prepare(
            "SELECT review_status, COUNT(*) AS n FROM {$wpdb->prefix}newsgame_dilemas
              WHERE instance_id = %d AND review_status IN ('approved', 'pending')
              GROUP BY review_status",
            $instance_id
        ) );
        if ( $wpdb->last_error ) {
            error_log( '[Newsgame] contar_vivas: ' . $wpdb->last_error );
            return null;
        }
        $contagem = array( 'approved' => 0, 'pending' => 0 );
        foreach ( $linhas as $l ) {
            $contagem[ $l->review_status ] = (int) $l->n;
        }
        return $contagem;
    }

    /**
     * As $quantas menores posições (≥ 1) sem aprovada nem pendente: preenche os
     * buracos primeiro e depois continua depois da última. Descartada não ocupa.
     *
     * ⚠️ Não corrige posições JÁ duplicadas — elas contam como uma posição
     * ocupada. Renumerar é outro passo (levantamento de 02/10, instâncias 4 e 8).
     *
     * Pública desde 02/10: a Mesa (Newsgame_Review) usa a mesma regra para a
     * pergunta devolvida e para a substituta, em vez de reimplementá-la.
     */
    public static function posicoes_livres( int $instance_id, int $quantas ): ?array {
        global $wpdb;
        $ocupadas = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT position FROM {$wpdb->prefix}newsgame_dilemas
              WHERE instance_id = %d AND review_status IN ('approved', 'pending') AND position > 0",
            $instance_id
        ) );
        if ( $wpdb->last_error ) {
            error_log( '[Newsgame] posicoes_livres: ' . $wpdb->last_error );
            return null;
        }
        $ocupadas = array_map( 'intval', $ocupadas );
        $livres   = array();
        for ( $p = 1; count( $livres ) < $quantas; $p++ ) {
            if ( ! in_array( $p, $ocupadas, true ) ) {
                $livres[] = $p;
            }
        }
        return $livres;
    }

    /**
     * Posição em que uma pergunta deve ser gravada: a pedida, se for válida
     * (1 a POSICAO_MAXIMA) e nenhuma OUTRA viva (aprovada ou pendente) estiver
     * nela; senão a menor livre. Descartada não ocupa. `$ignorar_id` tira a
     * própria pergunta da conferência — é o que permite à Mesa devolver uma
     * aprovada para a vaga que ela mesma ocupa. Devolve null em erro de SELECT.
     *
     * Usada pelo salvamento da geração (a posição vem do navegador e pode ter
     * sido ocupada por uma devolução na Mesa durante o lote) e pela Mesa
     * (Newsgame_Review::posicao_para_devolver).
     */
    public static function posicao_para_gravar( int $instance_id, int $pedida, int $ignorar_id = 0 ): ?int {
        global $wpdb;
        if ( $pedida >= 1 && $pedida <= self::POSICAO_MAXIMA ) {
            $ocupada = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}newsgame_dilemas
                  WHERE instance_id = %d AND position = %d AND id <> %d
                    AND review_status IN ('approved', 'pending')",
                $instance_id, $pedida, $ignorar_id
            ) );
            if ( $wpdb->last_error ) {
                error_log( '[Newsgame] posicao_para_gravar: ' . $wpdb->last_error );
                return null;
            }
            if ( 0 === $ocupada ) {
                return $pedida;
            }
        }
        $livres = self::posicoes_livres( $instance_id, 1 );
        return $livres ? $livres[0] : null;
    }

    /**
     * Fontes já usadas pela instância, em TODOS os status — descartadas incluídas,
     * para a pergunta rejeitada pelo editor não voltar da mesma matéria. Semeia o
     * `fontes_usadas` do lote; antes ele começava vazio a cada clique.
     *
     * ⚠️ É o `source_url` que o modelo CITOU, comparado no backend com o do
     * ChromaDB. URL alterada pelo modelo não é excluída.
     */
    private static function fontes_da_instancia( int $instance_id ): ?array {
        global $wpdb;
        $fontes = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT source_url FROM {$wpdb->prefix}newsgame_dilemas
              WHERE instance_id = %d AND source_url <> ''",
            $instance_id
        ) );
        if ( $wpdb->last_error ) {
            error_log( '[Newsgame] fontes_da_instancia: ' . $wpdb->last_error );
            return null;
        }
        return array_values( $fontes );
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

        // Teto alto de propósito: o loop legítimo é sequencial, uma chamada por vez
        // e cada uma leva segundos, então fica bem abaixo de 60 por minuto mesmo
        // com a meta de 120. Serve contra loop de JS com bug, que faria centenas
        // por segundo — não contra uso normal.
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

        /*
         * A posição que vem do navegador é só um PEDIDO: foi calculada no clique
         * (ajax_start), e uma devolução na Mesa no meio do lote pode tê-la
         * ocupado. O servidor confere e, se preciso, usa a menor livre. Cada
         * INSERT já aparece na conferência do dilema seguinte, então duas
         * perguntas com a mesma posição pedida no mesmo POST não colidem.
         * A sentinela continua o retorno de save_dilema(), nunca o insert_id.
         */
        $saved    = 0;
        $failed   = 0;
        $posicoes = array();
        foreach ( $dilemas as $d ) {
            if ( ! is_array( $d ) ) continue;
            $pedida  = (int) ( $d['position'] ?? 0 );
            $gravada = self::posicao_para_gravar( $instance_id, $pedida );
            if ( null !== $gravada ) {
                $d['position'] = $gravada;
                if ( false === self::save_dilema( $d, $instance_id ) ) {
                    $gravada = null;
                }
            }
            if ( null === $gravada ) {
                $failed++;
            } else {
                $saved++;
            }
            $posicoes[] = array( 'pedida' => $pedida, 'gravada' => $gravada );
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

        // Sem promoção de status desde 05/10: o status é derivado
        // (Newsgame_Status) e passa a "Em revisão" sozinho quando houver
        // perguntas e matriz. Com isso saiu a terceira escrita do C13.
        wp_send_json_success( array(
            'saved'     => $saved,
            'failed'    => $failed,
            'posicoes'  => $posicoes,
            'contagens' => self::contagens_para_painel( $instance_id ),
        ) );
    }

    /**
     * Contagens no formato do evento `ng:contagens` (as chaves são as abas da
     * Mesa) mais o déficit da meta, para o lote atualizar o painel sem
     * recarregar. Null em erro de SELECT ou instância inexistente.
     */
    private static function contagens_para_painel( int $instance_id ): ?array {
        global $wpdb;
        $instance = Newsgame_Admin_Instances::get( $instance_id );
        $linhas   = $wpdb->get_results( $wpdb->prepare(
            "SELECT review_status, COUNT(*) AS n FROM {$wpdb->prefix}newsgame_dilemas
              WHERE instance_id = %d GROUP BY review_status",
            $instance_id
        ) );
        if ( ! $instance || $wpdb->last_error ) {
            return null;
        }
        $c = array( 'pending' => 0, 'approved' => 0, 'discarded' => 0 );
        foreach ( $linhas as $l ) {
            if ( isset( $c[ $l->review_status ] ) ) {
                $c[ $l->review_status ] = (int) $l->n;
            }
        }
        $c['deficit'] = max( 0, (int) $instance->density - $c['approved'] - $c['pending'] );
        return $c;
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
