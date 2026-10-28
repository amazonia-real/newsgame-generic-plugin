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

        // Ano mínimo ativo + backend de nível antigo: ele ignoraria o filtro em
        // silêncio, então o lote NÃO começa. Backend fora do ar não entra aqui.
        $ano_minimo = ! empty( $instance->ano_minimo ) ? (int) $instance->ano_minimo : null;
        if ( null !== $ano_minimo && Newsgame_Settings::backend_ignoraria_ano_minimo() ) {
            wp_send_json_error( array( 'msg' => Newsgame_Settings::MSG_BACKEND_ANTIGO_ANO ) );
        }

        $characters = json_decode( $instance->characters ?? '[]', true ) ?: array();

        // Grupo do lote (item 58): 'geral' ou o slug de um personagem da
        // instância. Sem ele, a meta é a do conjunto inteiro, como antes.
        $grupo = isset( $_POST['grupo'] ) ? sanitize_key( wp_unslash( $_POST['grupo'] ) ) : null;
        $metas = Newsgame_Status::receita_da_instancia( $instance )['metas'];
        if ( null !== $grupo && ! isset( $metas[ $grupo ] ) ) {
            wp_send_json_error( array( 'msg' => "Grupo desconhecido nesta instância: '$grupo'. Recarregue a tela." ) );
        }

        // Déficit = meta − (aprovadas + pendentes). Descartadas não contam: não vão
        // ao jogo. Antes só as aprovadas contavam, e cada clique com pendentes na
        // Mesa gerava a meta inteira de novo — medido em outubro na instância 8:
        // 3 pendentes, 4 chamadas na segunda rodada, posição 1 repetida.
        // Com grupo, a conta é só das perguntas dele, contra a meta dele.
        $contagem = self::contar_vivas( $instance_id, $grupo );
        if ( null === $contagem ) {
            wp_send_json_error( array( 'msg' => 'Não foi possível ler as perguntas da instância. Verifique o log do servidor.' ) );
        }
        $density = null === $grupo ? (int) $instance->density : $metas[ $grupo ];
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
        $payload = array(
            'site_id'       => home_url(),
            'instance_id'   => $instance_id,
            'theme_prompt'  => $instance->theme_prompt,
            'density'       => $deficit,
            'characters'    => $characters,
            // Ausente (null) = rodízio por slot_index no backend, como antes.
            'grupo'         => $grupo,
            'num_options'   => (int) ( $instance->num_options   ?? 2 ),
            'nivel_padrao'  => $instance->nivel_padrao ?? 'pedagogico',
            'axis_matrix'   => $instance->axis_matrix
                                   ? json_decode( $instance->axis_matrix, true )
                                   : null,
        );
        // Só quando a instância tem valor: sem ele o payload é o de antes.
        if ( null !== $ano_minimo ) {
            $payload['ano_minimo'] = $ano_minimo;
        }

        wp_send_json_success( array(
            'approved_count'  => $contagem['approved'],
            'pending_count'   => $contagem['pending'],
            'deficit'         => $deficit,
            'posicoes_livres' => $posicoes,
            'fontes_usadas'   => $fontes,
            'payload'         => $payload,
        ) );
    }

    /**
     * Aprovadas e pendentes da instância — as que contam para a meta. Com
     * `$grupo`, só as daquele grupo (`character_slug`).
     * Devolve null em erro de SELECT, para o chamador abortar.
     */
    private static function contar_vivas( int $instance_id, ?string $grupo = null ): ?array {
        global $wpdb;
        $sql  = "SELECT review_status, COUNT(*) AS n FROM {$wpdb->prefix}newsgame_dilemas
                  WHERE instance_id = %d AND review_status IN ('approved', 'pending')";
        $args = array( $instance_id );
        if ( null !== $grupo ) {
            $sql   .= ' AND character_slug = %s';
            $args[] = $grupo;
        }
        $linhas = $wpdb->get_results( $wpdb->prepare( $sql . ' GROUP BY review_status', $args ) );
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
     * ocupada. Renumerar é outro passo (levantamento de outubro, instâncias 4 e 8).
     *
     * Pública desde outubro: a Mesa (Newsgame_Review) usa a mesma regra para a
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
            wp_send_json( array( 'codigo' => 'permissao', 'detail' => 'Permissão insuficiente.' ), 403 );
        }

        // Teto alto de propósito: o loop legítimo é sequencial, uma chamada por vez
        // e cada uma leva segundos, então fica bem abaixo de 60 por minuto mesmo
        // com a meta de 120. Serve contra loop de JS com bug, que faria centenas
        // por segundo — não contra uso normal.
        // O 429 já tem tratamento no JS: espera 60 s e retenta o mesmo slot.
        if ( Newsgame_Settings::rate_limited( 'generate_one', 60 ) ) {
            wp_send_json( array( 'codigo' => 'throttle', 'detail' => 'Limite de geração por minuto atingido. Aguarde e tente novamente.' ), 429 );
        }

        $backend_url = Newsgame_Settings::backend_url();
        if ( ! $backend_url ) {
            wp_send_json( array( 'codigo' => 'sem_url', 'detail' => 'URL do backend não configurada em News-Game › Configurações.' ), 500 );
        }

        $payload = json_decode( file_get_contents( 'php://input' ), true );
        if ( ! is_array( $payload ) ) {
            wp_send_json( array( 'codigo' => 'payload', 'detail' => 'Payload JSON inválido.' ), 400 );
        }

        /*
         * A CHAVE DO PROVEDOR ATIVO VAI EM HEADER, E SÓ EM HEADER — fase 0 do H1,
         * e desde o B6 o provedor sai do painel em vez de ser sempre o Gemini.
         *
         * Antes deste passo o proxy não mandava chave nenhuma, e o backend caía
         * no `GEMINI_API_KEY` do `.env` dele. Ou seja: a chave configurada em
         * News-Game › Configurações **não governava a geração de dilemas**, que
         * é o miolo do produto. Medido em setembro de 2026: com `.env` inválido e
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

        // O JS decide por `codigo`, e não pelo texto do detail. Estes dois são os
        // que o PRÓPRIO proxy decide; o 502 do backend (recusa do provedor) não
        // leva `codigo` e é reconhecido pelo detail, na tabela do JS.
        if ( is_wp_error( $response ) ) {
            $msg    = $response->get_error_message();
            $tempo  = false !== stripos( $msg, 'timed out' ) || false !== stripos( $msg, 'cURL error 28' );
            $codigo = $tempo ? 'tempo_esgotado' : 'backend_inacessivel';
            error_log( "[Newsgame] geração: {$codigo} ao chamar o /generate-one." );
            wp_send_json( array( 'codigo' => $codigo, 'detail' => 'Falha de conexão com o backend: ' . $msg ), 502 );
        }

        // Body e status do Python repassados sem alteração ao browser.
        $status = (int) wp_remote_retrieve_response_code( $response );
        if ( $status >= 400 ) {
            // Só o status: o corpo pode citar o provedor e não vai para o log.
            error_log( "[Newsgame] geração: o backend respondeu HTTP {$status} no /generate-one." );
        }
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

        // Grupo pedido no lote (item 58). Pergunta que volta de OUTRO grupo não é
        // gravada: entraria na meta errada sem ninguém ver. Sem grupo, nada muda.
        $grupo = isset( $_POST['grupo'] ) ? sanitize_key( wp_unslash( $_POST['grupo'] ) ) : null;

        /*
         * A posição que vem do navegador é só um PEDIDO: foi calculada no clique
         * (ajax_start), e uma devolução na Mesa no meio do lote pode tê-la
         * ocupado. O servidor confere e, se preciso, usa a menor livre. Cada
         * INSERT já aparece na conferência do dilema seguinte, então duas
         * perguntas com a mesma posição pedida no mesmo POST não colidem.
         * A sentinela continua o retorno de save_dilema(), nunca o insert_id.
         */
        $saved          = 0;
        $failed         = 0;
        $grupo_recusado = 0;
        $posicoes       = array();
        foreach ( $dilemas as $d ) {
            if ( ! is_array( $d ) ) continue;
            if ( null !== $grupo && sanitize_key( $d['personagem_tipo'] ?? 'geral' ) !== $grupo ) {
                error_log( "[Newsgame] ajax_save_matrix: pergunta do grupo '" . sanitize_key( $d['personagem_tipo'] ?? 'geral' ) . "' recusada num lote de '$grupo'." );
                $grupo_recusado++;
                $failed++;
                continue;
            }
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

        if ( $saved === 0 && $grupo_recusado > 0 ) {
            wp_send_json_error( array(
                'msg'            => "Nenhuma pergunta foi salva: $grupo_recusado voltou de outro grupo, e não de '$grupo'.",
                'failed'         => $failed,
                'grupo_recusado' => $grupo_recusado,
            ) );
        }
        if ( $saved === 0 && $failed > 0 ) {
            wp_send_json_error( array(
                'msg'    => "Nenhuma pergunta foi salva ($failed erros de INSERT). Verifique o log do servidor.",
                'failed' => $failed,
            ) );
        }

        // Sem promoção de status desde outubro: o status é derivado
        // (Newsgame_Status) e passa a "Em revisão" sozinho quando houver
        // perguntas e matriz. Com isso saiu a terceira escrita do C13.
        wp_send_json_success( array(
            'saved'          => $saved,
            'failed'         => $failed,
            'grupo_recusado' => $grupo_recusado,
            'posicoes'       => $posicoes,
            'contagens'      => self::contagens_para_painel( $instance_id, $grupo ),
        ) );
    }

    /**
     * Contagens no formato do evento `ng:contagens` (as chaves são as abas da
     * Mesa) mais o déficit da meta, para o lote atualizar o painel sem
     * recarregar. Com `$grupo`, o déficit é o do grupo, contra a meta dele: é ele
     * que faz o lote parar. As abas seguem contando a instância inteira.
     * Null em erro de SELECT ou instância inexistente.
     */
    private static function contagens_para_painel( int $instance_id, ?string $grupo = null ): ?array {
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
        if ( null !== $grupo ) {
            $metas = Newsgame_Status::receita_da_instancia( $instance )['metas'];
            $vivas = self::contar_vivas( $instance_id, $grupo );
            if ( null === $vivas || ! isset( $metas[ $grupo ] ) ) {
                return null;
            }
            $c['deficit'] = max( 0, $metas[ $grupo ] - $vivas['approved'] - $vivas['pending'] );
        }
        return $c;
    }

    /* ── 4. Push vault → /ingest, em lotes ────────────────────── */

    /**
     * Tempo máximo de UMA chamada ao /ingest. O resto do trabalho do PHP por lote
     * (listar e ordenar o cofre, ler até LOTE_PUSH_MAX_ITENS arquivos, gravar o
     * cursor) leva milissegundos; o prazo é todo do backend.
     */
    const TIMEOUT_INGEST_S = 45;

    /** Parada do envio para a tela: { processados, total } ou null. */
    public static function estado_cursor_push() {
        $c = Newsgame_Ingestor::cursor_get( 'push' );
        if ( ! $c ) {
            return null;
        }
        $arquivos = Newsgame_Ingestor::vault_paths_por_id();
        $feitos   = count( array_filter( $arquivos, function ( $a ) use ( $c ) {
            return $a['id'] <= (int) $c['ultimo_id'];
        } ) );
        return array( 'processados' => $feitos, 'total' => count( $arquivos ) );
    }

    /**
     * UM lote por chamada, em ordem de ID crescente: fecha no primeiro de
     * LOTE_PUSH_MAX_ITENS itens ou LOTE_PUSH_MAX_CARACTERES caracteres (sempre com ao
     * menos 1 item). O navegador repete até `feito`. O cursor (último ID enviado com
     * sucesso) é gravado ao fim de cada lote bem-sucedido; o backend apaga por
     * wp_id antes de regravar, então reenviar um lote é seguro.
     */
    public static function ajax_push_ingest() {
        check_ajax_referer( self::NONCE_PUSH, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'codigo' => 'permissao', 'msg' => 'Permissão insuficiente.' ) );
        }

        $backend_url = Newsgame_Settings::backend_url();
        if ( ! $backend_url ) {
            wp_send_json_error( array( 'codigo' => 'config', 'msg' => 'URL do backend não configurada.' ) );
        }

        if ( ! empty( $_POST['recomecar'] ) && '0' !== (string) wp_unslash( $_POST['recomecar'] ) ) {
            Newsgame_Ingestor::cursor_clear( 'push' );
        }

        $arquivos = Newsgame_Ingestor::vault_paths_por_id();
        if ( empty( $arquivos ) ) {
            wp_send_json_error( array( 'codigo' => 'cofre_vazio', 'msg' => 'Vault vazio. Sincronize o acervo primeiro.' ) );
        }

        $c         = Newsgame_Ingestor::cursor_get( 'push' ) ?: array( 'ultimo_id' => 0, 'enviados' => 0, 'pulados' => 0 );
        $ultimo_id = (int) $c['ultimo_id'];
        $pendentes = array_values( array_filter( $arquivos, function ( $a ) use ( $ultimo_id ) {
            return $a['id'] > $ultimo_id;
        } ) );
        $lote           = self::montar_lote( $pendentes );
        $feitos_no_lote = count( $lote['arquivos'] );

        $enviados_lote = 0;
        $pulados_lote  = 0;
        if ( $feitos_no_lote ) {
            // Arquivo ilegível não vai ao backend: conta como pulado, para que
            // enviados + pulados feche com o total.
            $pulados_lote = $feitos_no_lote - count( $lote['artigos'] );
            if ( $lote['artigos'] ) {
                $r = self::post_batch_ingest( $backend_url, (string) get_option( 'newsgame_backend_api_key', '' ), home_url(), $lote['artigos'] );
                if ( isset( $r['erro'] ) ) {
                    error_log( '[Newsgame] indexação: lote falhou (codigo=' . $r['erro']['codigo'] . ', http=' . ( $r['erro']['http'] ?? '-' ) . ').' );
                    wp_send_json_error( $r['erro'] );
                }
                $enviados_lote += $r['indexed'];
                $pulados_lote  += $r['skipped'];
            }
            $ultimo_id = $lote['arquivos'][ $feitos_no_lote - 1 ]['id'];
        }

        $feito = count( $pendentes ) <= $feitos_no_lote;
        $c['ultimo_id'] = $ultimo_id;
        $c['enviados']  = (int) $c['enviados'] + $enviados_lote;
        $c['pulados']   = (int) $c['pulados'] + $pulados_lote;
        $c['cortados']  = (int) ( $c['cortados'] ?? 0 ) + $lote['cortados'];
        if ( $feito ) {
            Newsgame_Ingestor::cursor_clear( 'push' );
        } else {
            Newsgame_Ingestor::cursor_set( 'push', $c );
        }

        wp_send_json_success( array(
            'feito'         => $feito,
            'processados'   => count( $arquivos ) - ( count( $pendentes ) - $feitos_no_lote ),
            'total'         => count( $arquivos ),
            'enviados_lote' => $enviados_lote,
            'enviados'      => $c['enviados'],
            'pulados'       => $c['pulados'],
            'cortados'      => $c['cortados'],
            'vault_total'   => count( $arquivos ),
        ) );
    }

    /**
     * Monta o lote percorrendo os pendentes em ordem de ID, até o PRIMEIRO dos
     * limites: LOTE_PUSH_MAX_ITENS itens ou LOTE_PUSH_MAX_CARACTERES caracteres
     * (soma do conteúdo, já com o corte de cada artigo). Todo lote tem ao menos 1
     * item: o limite de caracteres só fecha um lote que já tem algo. O cursor é o
     * ID do último arquivo INCLUÍDO.
     *
     * @return array { arquivos: [{id,path}], artigos: [corpo do /ingest], cortados: int }
     */
    private static function montar_lote( array $pendentes ) {
        $arquivos = array();
        $artigos  = array();
        $chars    = 0;
        $cortados = 0;
        foreach ( $pendentes as $a ) {
            if ( count( $arquivos ) >= Newsgame_Ingestor::LOTE_PUSH_MAX_ITENS ) {
                break;
            }
            $lido = self::ler_artigo( $a['path'] );
            $tam  = $lido ? mb_strlen( $lido['dados']['content'] ) : 0;
            if ( $arquivos && $chars + $tam > Newsgame_Ingestor::LOTE_PUSH_MAX_CARACTERES ) {
                break;
            }
            $arquivos[] = $a;
            $chars     += $tam;
            if ( $lido ) {
                $artigos[] = $lido['dados'];
                $cortados += $lido['cortado'] ? 1 : 0;
            }
        }
        return array( 'arquivos' => $arquivos, 'artigos' => $artigos, 'cortados' => $cortados );
    }

    /**
     * Lê um JSON do cofre e monta o artigo do /ingest. Conteúdo acima de
     * LIMITE_ITEM_CARACTERES é cortado no fim de uma palavra. Arquivo ilegível: null.
     */
    private static function ler_artigo( $path ) {
        $data = json_decode( (string) file_get_contents( $path ), true );
        if ( ! $data ) {
            return null;
        }
        $content = (string) ( $data['content'] ?? '' );
        $cortado = mb_strlen( $content ) > Newsgame_Ingestor::LIMITE_ITEM_CARACTERES;
        if ( $cortado ) {
            $content = self::cortar_no_fim_da_palavra( $content, Newsgame_Ingestor::LIMITE_ITEM_CARACTERES );
        }
        return array(
            'cortado' => $cortado,
            'dados'   => array(
                'id'      => (int) ( $data['id']      ?? 0 ),
                'type'    => $data['type']    ?? 'posts',
                'title'   => $data['title']   ?? '',
                'excerpt' => $data['excerpt'] ?? '',
                'content' => $content,
                'url'     => $data['url']     ?? '',
                'date'    => $data['date']    ?? '',
            ),
        );
    }

    /** Primeiros $limite caracteres, sem deixar uma palavra cortada ao meio no fim. */
    private static function cortar_no_fim_da_palavra( $texto, $limite ) {
        $corte = mb_substr( $texto, 0, $limite );
        // Se o caractere seguinte não é espaço, o corte caiu no meio de uma palavra.
        if ( ! preg_match( '/\s/u', mb_substr( $texto, $limite, 1 ) ) ) {
            $inteiro = preg_replace( '/\S+$/u', '', $corte );
            if ( '' !== trim( (string) $inteiro ) ) {
                $corte = $inteiro;
            }
        }
        return rtrim( $corte );
    }

    /**
     * POST /ingest de um lote. Devolve { indexed, skipped } ou
     * { erro: { codigo, http?, detalhe? } } — o código diz a causa provável e a
     * tela escolhe o texto. A chave nunca sai daqui. O `detalhe` (só em resposta
     * que não é JSON) leva os 200 primeiros caracteres do corpo, para a tela do
     * administrador; o log registra apenas código e HTTP.
     */
    private static function post_batch_ingest( $backend_url, $api_key, $site_id, $articles ) {
        $response = wp_remote_post(
            rtrim( $backend_url, '/' ) . '/ingest',
            array(
                'timeout'   => self::TIMEOUT_INGEST_S,
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
            $msg    = $response->get_error_message();
            $tempo  = false !== stripos( $msg, 'timed out' ) || false !== stripos( $msg, 'cURL error 28' );
            return array( 'erro' => array( 'codigo' => $tempo ? 'tempo_esgotado' : 'inacessivel' ) );
        }

        $http = (int) wp_remote_retrieve_response_code( $response );
        $erro = self::classificar_http_ingest( $http );
        if ( $erro ) {
            return array( 'erro' => array( 'codigo' => $erro, 'http' => $http ) );
        }

        $corpo = wp_remote_retrieve_body( $response );
        $json  = json_decode( $corpo, true );
        if ( ! is_array( $json ) || ! isset( $json['indexed'] ) ) {
            // Início da resposta, para o editor ver o que veio. A tela o mostra por
            // textContent (escapado); aqui só se corta e se tiram os controles.
            $trecho = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', mb_substr( $corpo, 0, 200 ) );
            // Bytes que não são UTF-8 quebrariam o JSON da resposta ao navegador.
            $trecho = (string) wp_check_invalid_utf8( $trecho, true );
            return array( 'erro' => array( 'codigo' => 'nao_json', 'http' => $http, 'detalhe' => $trecho ) );
        }
        return array( 'indexed' => (int) $json['indexed'], 'skipped' => (int) ( $json['skipped'] ?? 0 ) );
    }

    /** Código de falha para o status HTTP do /ingest, ou null quando é 2xx. */
    private static function classificar_http_ingest( $http ) {
        if ( $http >= 200 && $http < 300 ) {
            return null;
        }
        if ( 401 === $http || 403 === $http ) {
            return 'token';
        }
        if ( 429 === $http ) {
            return 'cota';
        }
        return $http >= 500 ? 'backend_5xx' : 'backend_http';
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
