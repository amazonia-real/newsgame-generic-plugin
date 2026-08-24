<?php
defined( 'ABSPATH' ) || exit;

class Newsgame_Admin_Instances {

    const NONCE_AXIS    = 'newsgame_save_axis';
    const NONCE_ASSIGN  = 'newsgame_assign_weights';
    const NONCE_ANALYZE = 'newsgame_analyze_domain';

    /** Hook suffix devolvido por add_submenu_page — usado para enfileirar o JS só nesta tela */
    private static $hook_suffix = '';

    public static function init() {
        add_action( 'admin_menu',                                      array( __CLASS__, 'register_submenu' ) );
        add_action( 'admin_enqueue_scripts',                           array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'admin_post_newsgame_save_instance',               array( __CLASS__, 'handle_save' ) );
        add_action( 'admin_post_newsgame_delete_instance',             array( __CLASS__, 'handle_delete' ) );
        add_action( 'wp_ajax_newsgame_save_axis_matrix',               array( __CLASS__, 'handle_save_axis_matrix' ) );
        add_action( 'wp_ajax_newsgame_assign_weights',                 array( __CLASS__, 'handle_assign_weights' ) );
        add_action( 'wp_ajax_newsgame_analyze_domain',                 array( __CLASS__, 'handle_analyze_domain' ) );
    }

    public static function register_submenu() {
        self::$hook_suffix = add_submenu_page(
            'newsgame',
            'Instâncias — News-Game',
            'Instâncias',
            'manage_options',
            'newsgame-instances',
            array( __CLASS__, 'render' )
        );
    }

    /**
     * Enfileira o JS da listagem de instâncias.
     * Arquivo externo em vez de onclick inline: CSP estrita bloqueia atributo
     * de evento. Ver TECH_FLAGS FLAG 007.
     */
    public static function enqueue_assets( $hook ) {
        if ( $hook !== self::$hook_suffix ) {
            return;
        }

        // render() despacha listagem e formulário no mesmo slug.
        $action    = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';
        $is_form   = ( 'new' === $action || 'edit' === $action );

        /*
         * A guarda antiga dava `return` aqui e o método não fazia mais nada nas
         * telas de formulário. Agora ela apenas separa os dois destinos: o JS da
         * listagem continua exclusivo da listagem, e o formulário ganha o seu.
         *
         * O A3 (FLAG 007 passo 3) herda esta guarda já corrigida — era a
         * armadilha registrada na nota dele ("sai fora quando `action` é
         * new/edit; a guarda vai precisar mudar"). Quem for mover os 5 <script>
         * inline do admin não precisa mais mexer nisto.
         */
        if ( $is_form ) {
            // Seletor nativo de mídia, para a imagem de fundo do dilema.
            // Sem esta chamada `wp.media` é undefined na tela.
            wp_enqueue_media();
            return;
        }

        wp_enqueue_script(
            'newsgame-admin-instances',
            NEWSGAME_URL . 'admin/js/newsgame-admin-instances.js',
            array(),
            NEWSGAME_VERSION,
            true  // footer
        );
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';

        if ( 'new' === $action || 'edit' === $action ) {
            require NEWSGAME_DIR . 'admin/views/page-instance-form.php';
        } else {
            require NEWSGAME_DIR . 'admin/views/page-instances.php';
        }
    }

    /* ── CRUD ──────────────────────────────────────────────────── */

    public static function handle_save() {
        check_admin_referer( 'newsgame_save_instance', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Permissão insuficiente.' );

        global $wpdb;
        $table = $wpdb->prefix . 'newsgame_instances';

        $id            = (int) ( $_POST['instance_id'] ?? 0 );
        $title         = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
        $theme_prompt  = sanitize_textarea_field( wp_unslash( $_POST['theme_prompt'] ?? '' ) );
        $density       = min( 15, max( 4, absint( $_POST['density'] ?? 10 ) ) );
        $num_options   = in_array( (int) ( $_POST['num_options'] ?? 2 ), array( 2, 3 ), true ) ? (int) $_POST['num_options'] : 2;
        $enable_levels = isset( $_POST['enable_levels'] ) ? 1 : 0;
        $nivel_padrao  = in_array( $_POST['nivel_padrao'] ?? '', array( 'ludico', 'pedagogico', 'complexo' ), true )
                            ? sanitize_key( $_POST['nivel_padrao'] )
                            : 'pedagogico';
        $status        = sanitize_key( $_POST['status'] ?? 'draft' );
        $characters    = self::sanitize_characters( wp_unslash( $_POST['characters_json'] ?? '[]' ) );

        if ( ! $title ) {
            wp_safe_redirect( add_query_arg( array(
                'page'   => 'newsgame-instances',
                'action' => $id ? 'edit' : 'new',
                'id'     => $id ?: null,
                'error'  => 'title_required',
            ), admin_url( 'admin.php' ) ) );
            exit;
        }

        $slug = self::unique_slug( $title, $id );

        // Publicar com aprovados sem peso entregaria veredito genérico ao jogador,
        // em silêncio. Em vez de rejeitar o save inteiro — que descartaria as demais
        // edições do formulário —, rebaixa o status para 'review' e avisa. O editor
        // não perde trabalho e a publicação não acontece.
        $pendentes_sem_peso = 0;
        if ( $id && 'published' === $status ) {
            $pendentes_sem_peso = self::count_unweighted_approved( $id );
            if ( $pendentes_sem_peso > 0 ) {
                $status = 'review';
            }
        }

        $row = array(
            'title'         => $title,
            'slug'          => $slug,
            'theme_prompt'  => $theme_prompt,
            'density'       => $density,
            'num_options'   => $num_options,
            'enable_levels' => $enable_levels,
            'nivel_padrao'  => $nivel_padrao,
            'status'        => $status,
            'characters'    => wp_json_encode( $characters, JSON_UNESCAPED_UNICODE ),
        );
        $fmt = array( '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s' );

        if ( $id ) {
            $wpdb->update( $table, $row, array( 'id' => $id ), $fmt, array( '%d' ) );
        } else {
            $wpdb->insert( $table, $row, $fmt );
            $id = (int) $wpdb->insert_id;
        }

        // Com pendência de pesos, volta para o formulário: é onde estão o aviso e o
        // botão que resolve. Sem pendência, mantém o destino histórico (a listagem).
        if ( $pendentes_sem_peso > 0 ) {
            wp_safe_redirect( add_query_arg( array(
                'page'   => 'newsgame-instances',
                'action' => 'edit',
                'id'     => $id,
                'saved'  => 1,
                'error'  => 'unweighted_approved',
            ), admin_url( 'admin.php' ) ) );
            exit;
        }

        wp_safe_redirect( add_query_arg( array(
            'page'  => 'newsgame-instances',
            'saved' => 1,
            'id'    => $id,
        ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function handle_delete() {
        $id = (int) ( $_GET['id'] ?? 0 );
        check_admin_referer( "newsgame_delete_{$id}" );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Permissão insuficiente.' );

        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'newsgame_instances', array( 'id' => $id ), array( '%d' ) );
        $wpdb->delete( $wpdb->prefix . 'newsgame_dilemas',   array( 'instance_id' => $id ), array( '%d' ) );

        wp_safe_redirect( add_query_arg( array(
            'page'    => 'newsgame-instances',
            'deleted' => 1,
        ), admin_url( 'admin.php' ) ) );
        exit;
    }

    /* ── Queries ────────────────────────────────────────────────── */

    public static function get_all() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT i.*, COUNT(d.id) AS dilema_count
             FROM {$wpdb->prefix}newsgame_instances i
             LEFT JOIN {$wpdb->prefix}newsgame_dilemas d ON d.instance_id = i.id
             GROUP BY i.id
             ORDER BY i.created_at DESC"
        );
    }

    public static function get( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}newsgame_instances WHERE id = %d",
            (int) $id
        ) );
    }

    /* ── Helpers ────────────────────────────────────────────────── */

    /**
     * Valida e sanitiza o JSON de personagens vindo do hidden input.
     * Garante que só campos conhecidos passam para o DB.
     */
    private static function sanitize_characters( $json_string ) {
        $raw = json_decode( $json_string, true );
        if ( ! is_array( $raw ) ) return array();

        $clean = array();
        foreach ( $raw as $c ) {
            $label = sanitize_text_field( $c['label'] ?? '' );
            if ( ! $label ) continue;
            $clean[] = array(
                'slug'        => sanitize_key( $c['slug'] ?? '' ) ?: sanitize_key( $label ),
                'label'       => $label,
                'description' => sanitize_textarea_field( $c['description'] ?? '' ),
            );
        }
        return $clean;
    }

    /* ── AJAX: salva matriz de eixos ───────────────────────────── */

    public static function handle_save_axis_matrix() {
        check_ajax_referer( self::NONCE_AXIS, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'msg' => 'Permissão insuficiente.' ) );
        }

        $instance_id = (int) ( $_POST['instance_id'] ?? 0 );
        $json_raw    = wp_unslash( $_POST['axis_matrix_json'] ?? '' );

        if ( ! $instance_id ) {
            wp_send_json_error( array( 'msg' => 'ID de instância inválido.' ) );
        }

        $decoded = json_decode( $json_raw, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            wp_send_json_error( array( 'msg' => 'JSON inválido: ' . json_last_error_msg() ) );
        }

        // Sanitiza os nomes de eixo na origem: eles chegam crus da resposta
        // do LLM (/analyze-domain) e depois são interpolados no HTML público.
        if ( isset( $decoded['eixos_principais'] ) && is_array( $decoded['eixos_principais'] ) ) {
            foreach ( $decoded['eixos_principais'] as $key => $eixo ) {
                if ( is_array( $eixo ) && isset( $eixo['nome'] ) ) {
                    $decoded['eixos_principais'][ $key ]['nome'] = sanitize_text_field( $eixo['nome'] );
                }
            }
        }

        global $wpdb;
        $result = $wpdb->update(
            $wpdb->prefix . 'newsgame_instances',
            array( 'axis_matrix' => wp_json_encode( $decoded, JSON_UNESCAPED_UNICODE ) ),
            array( 'id' => $instance_id ),
            array( '%s' ),
            array( '%d' )
        );

        if ( false === $result ) {
            wp_send_json_error( array( 'msg' => 'Falha ao salvar no banco: ' . $wpdb->last_error ) );
        }

        wp_send_json_success( array( 'msg' => 'Matriz de eixos salva.' ) );
    }

    /* ── Motor de pesagem de eixos ────────────────────────────── */

    /**
     * Conta dilemas aprovados sem pesos de eixo.
     *
     * É a pendência que faz o jogo entregar veredito genérico: o gate de
     * newsgame-game.js:342 exige pesoCount > 0 e degrada em silêncio sem ele.
     * Usado pelo aviso persistente da tela de edição e pelo bloqueio de
     * publicação em handle_save().
     */
    public static function count_unweighted_approved( $instance_id ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}newsgame_dilemas
              WHERE instance_id = %d AND review_status = 'approved' AND axis_weights IS NULL",
            (int) $instance_id
        ) );
    }

    /**
     * Atribui pesos de eixo a um conjunto específico de dilemas.
     *
     * Extraído de handle_assign_weights() para que o handler de aprovação em lote
     * da Mesa Editorial reuse o mesmo caminho. Uma única chamada ao backend cobre
     * todos os IDs: o /assign-weights pontua cada dilema contra os polos dos
     * eixos de forma independente — o lote é eficiência de token, não requisito
     * de contexto. Por isso lote de 1 é perfeitamente válido.
     *
     * NÃO envia resposta HTTP e NÃO aplica throttle: quem chama decide as duas
     * coisas, porque o custo aceitável difere entre o botão manual e a aprovação.
     *
     * @param  int   $instance_id
     * @param  int[] $dilema_ids  IDs a pesar. Vazio = no-op.
     * @param  bool  $force       true reprocessa quem já tem peso (botão manual
     *                            "Reatribuir"); false só pesa axis_weights NULL.
     * @return array{updated:int, skipped:int, error:?string}
     */
    public static function assign_weights_for( $instance_id, array $dilema_ids, $force = false ) {
        global $wpdb;

        $instance_id = (int) $instance_id;
        $dilema_ids  = array_values( array_unique( array_filter( array_map( 'intval', $dilema_ids ) ) ) );

        if ( ! $instance_id || ! $dilema_ids ) {
            return array( 'updated' => 0, 'skipped' => 0, 'error' => null );
        }

        $instance = self::get( $instance_id );
        if ( ! $instance || ! $instance->axis_matrix ) {
            return array( 'updated' => 0, 'skipped' => count( $dilema_ids ),
                'error' => 'instância sem matriz de eixos — rode "Analisar Domínio" antes de pesar.' );
        }

        $axis_data = json_decode( $instance->axis_matrix, true );
        if ( ! isset( $axis_data['eixos_principais'] ) ) {
            return array( 'updated' => 0, 'skipped' => count( $dilema_ids ),
                'error' => 'matriz de eixos inválida ou sem eixos_principais.' );
        }

        $table_dilemas = $wpdb->prefix . 'newsgame_dilemas';
        $placeholders  = implode( ',', array_fill( 0, count( $dilema_ids ), '%d' ) );

        // Sem $force, só quem ainda não tem peso: reaprovar dilema já pesado não
        // gasta cota. Quem editou o texto zera o campo antes de chamar.
        $filtro_peso = $force ? '' : ' AND axis_weights IS NULL';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, body, option_a, option_b, option_c FROM {$table_dilemas}
              WHERE instance_id = %d AND id IN ({$placeholders}){$filtro_peso}
              ORDER BY id ASC",
            array_merge( array( $instance_id ), $dilema_ids )
        ), ARRAY_A );

        // Todos já tinham peso — caminho idempotente, não é erro
        if ( empty( $rows ) ) {
            return array( 'updated' => 0, 'skipped' => count( $dilema_ids ), 'error' => null );
        }

        // Monta payload: dilema_id é campo extra para rastrear o UPDATE de retorno.
        // O backend Python ignora campos desconhecidos — dilema_id volta intacto.
        $dilemmas = array();
        foreach ( $rows as $row ) {
            $opt_a = json_decode( $row['option_a'] ?? '{}', true ) ?: array();
            $opt_b = json_decode( $row['option_b'] ?? '{}', true ) ?: array();
            $opt_c = ! empty( $row['option_c'] ) ? json_decode( $row['option_c'], true ) : null;

            $alternativas = array(
                array( 'letra' => 'A', 'texto' => $opt_a['texto'] ?? '' ),
                array( 'letra' => 'B', 'texto' => $opt_b['texto'] ?? '' ),
            );
            if ( $opt_c ) {
                $alternativas[] = array( 'letra' => 'C', 'texto' => $opt_c['texto'] ?? '' );
            }

            $dilemmas[] = array(
                'dilema_id'    => (int) $row['id'],
                'situacao'     => $row['body'] ?? '',
                'alternativas' => $alternativas,
            );
        }

        $backend_url = rtrim( Newsgame_Settings::backend_url(), '/' );
        $gemini_key  = Newsgame_Settings::gemini_key();

        $response = wp_remote_post(
            $backend_url . '/assign-weights',
            array(
                'timeout' => 120,
                'headers' => array(
                    'Content-Type'     => 'application/json',
                    'X-Gemini-Api-Key' => $gemini_key,
                    'X-Api-Key'        => (string) get_option( 'newsgame_backend_api_key', '' ),
                ),
                'body' => wp_json_encode( array(
                    'dilemmas'       => $dilemmas,
                    'eixos'          => $axis_data['eixos_principais'],
                    'gemini_api_key' => $gemini_key,
                ), JSON_UNESCAPED_UNICODE ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return array( 'updated' => 0, 'skipped' => count( $rows ),
                'error' => 'falha de conexão com o backend: ' . $response->get_error_message() );
        }

        $status_code = (int) wp_remote_retrieve_response_code( $response );
        if ( 200 !== $status_code ) {
            return array( 'updated' => 0, 'skipped' => count( $rows ),
                'error' => "backend retornou HTTP {$status_code}: "
                    . substr( wp_remote_retrieve_body( $response ), 0, 300 ) );
        }

        $result = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $result ) || ! isset( $result['dilemmas_weighted'] ) ) {
            return array( 'updated' => 0, 'skipped' => count( $rows ),
                'error' => 'resposta inesperada do backend.' );
        }

        $updated           = 0;
        $falhas_de_escrita = 0;
        $erro_db           = '';

        foreach ( $result['dilemmas_weighted'] as $item ) {
            $dilema_id = (int) ( $item['dilema_id'] ?? 0 );
            if ( ! $dilema_id ) continue;

            $pesos = array();
            foreach ( $item['alternativas'] ?? array() as $alt ) {
                $letra = $alt['letra'] ?? '';
                if ( $letra && isset( $alt['pesos'] ) ) {
                    $pesos[ $letra ] = $alt['pesos'];
                }
            }

            if ( $pesos ) {
                $gravado = $wpdb->update(
                    $table_dilemas,
                    array( 'axis_weights' => wp_json_encode( $pesos, JSON_UNESCAPED_UNICODE ) ),
                    array( 'id' => $dilema_id, 'instance_id' => $instance_id ),
                    array( '%s' ),
                    array( '%d', '%d' )
                );

                // $updated conta LINHAS GRAVADAS, não alternativas parseadas da
                // resposta do backend. O ++ era incondicional dentro deste if,
                // então uma falha de escrita em TODOS os dilemas ainda devolvia
                // updated = N e error = null — "pesos atribuídos". O estado real
                // era salvo pelo count_unweighted_approved() do C5, que lê o
                // banco depois da operação; a CAUSA reportada é que ficava errada.
                //
                // 0 continua contando como gravado: significa que a linha já
                // tinha exatamente estes pesos (reprocessamento com $force).
                if ( false === $gravado ) {
                    $erro_db = $wpdb->last_error ?: 'causa não informada';
                    error_log( "[Newsgame] axis_weights não gravado no dilema {$dilema_id} — MySQL: {$erro_db}" );
                    $falhas_de_escrita++;
                    continue;
                }

                $updated++;
            }
        }

        // Falha de escrita entra no `error` mesmo com $updated > 0: atribuição
        // parcial não foi "concluída", e quem chama precisa da causa. O ESTADO
        // — quantos ficaram sem peso — continua vindo do
        // count_unweighted_approved(), nunca inferido daqui (C5).
        //
        // $erro_db é capturado no laço, e não lido aqui: $wpdb->last_error é
        // limpo pela primeira query bem-sucedida depois da que falhou.
        $erro = null;
        if ( $falhas_de_escrita ) {
            $erro = sprintf(
                'o backend respondeu, mas a gravação de %d peso(s) falhou no banco: %s',
                $falhas_de_escrita,
                $erro_db
            );
        } elseif ( ! $updated ) {
            $erro = 'o backend respondeu, mas nenhum peso válido voltou.';
        }

        return array(
            'updated' => $updated,
            'skipped' => max( 0, count( $rows ) - $updated ),
            'error'   => $erro,
        );
    }

    /* ── AJAX: atribui pesos de eixo via /assign-weights ──────── */

    public static function handle_assign_weights() {
        check_ajax_referer( self::NONCE_ASSIGN, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'msg' => 'Permissão insuficiente.' ) );
        }
        // Um clique processa todos os dilemas da instância de uma vez — chamada
        // única e caríssima. Teto baixo.
        if ( Newsgame_Settings::rate_limited( 'assign_weights', 10 ) ) {
            wp_send_json_error( array( 'msg' => 'Muitas reatribuições em sequência. Aguarde um minuto — cada reatribuição consome cota da chave Gemini.' ) );
        }

        $instance_id = (int) ( $_POST['instance_id'] ?? 0 );
        if ( ! $instance_id ) {
            wp_send_json_error( array( 'msg' => 'ID de instância inválido.' ) );
        }

        // Busca todos os dilemas da instância, independente de review_status
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}newsgame_dilemas WHERE instance_id = %d ORDER BY id ASC",
            $instance_id
        ) );

        if ( empty( $ids ) ) {
            wp_send_json_error( array( 'msg' => 'Nenhum dilema encontrado para esta instância.' ) );
        }

        // force = true: este botão é o "Reatribuir" e reprocessa tudo, inclusive quem
        // já tem peso. Comportamento deliberado, preservado da versão anterior — garante
        // consistência sem controle de estado entre chamadas.
        $res = self::assign_weights_for( $instance_id, $ids, true );

        if ( $res['error'] ) {
            wp_send_json_error( array( 'msg' => ucfirst( $res['error'] ) ) );
        }

        wp_send_json_success( array(
            'msg'     => "Pesos atribuídos para {$res['updated']} dilema(s).",
            'updated' => $res['updated'],
        ) );
    }

    /* ── AJAX: proxy para /analyze-domain ─────────────────────── */

    public static function handle_analyze_domain() {
        check_ajax_referer( self::NONCE_ANALYZE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'msg' => 'Permissão insuficiente.' ) );
        }
        // Um clique por instância no uso normal — teto baixo basta.
        if ( Newsgame_Settings::rate_limited( 'analyze_domain', 10 ) ) {
            wp_send_json_error( array( 'msg' => 'Muitas análises em sequência. Aguarde um minuto — cada análise consome cota da chave Gemini.' ) );
        }

        $instance_id  = (int) ( $_POST['instance_id'] ?? 0 );
        $context_text = sanitize_textarea_field( wp_unslash( $_POST['context_text'] ?? '' ) );

        if ( ! $instance_id ) {
            wp_send_json_error( array( 'msg' => 'ID de instância inválido.' ) );
        }
        if ( ! $context_text ) {
            wp_send_json_error( array( 'msg' => 'context_text não pode estar vazio.' ) );
        }

        $backend_url = rtrim( Newsgame_Settings::backend_url(), '/' );
        $gemini_key  = Newsgame_Settings::gemini_key();
        $api_key     = (string) get_option( 'newsgame_backend_api_key', '' );

        $response = wp_remote_post(
            $backend_url . '/analyze-domain',
            array(
                'timeout'   => 120,
                'sslverify' => ! ( defined( 'WP_DEBUG' ) && WP_DEBUG && str_contains( $backend_url, 'localhost' ) ),
                'headers'   => array(
                    'Content-Type'     => 'application/json',
                    'X-Gemini-Api-Key' => $gemini_key,
                    'X-Api-Key'        => $api_key,
                ),
                'body' => wp_json_encode( array(
                    'context_text'   => $context_text,
                    'gemini_api_key' => $gemini_key,
                ), JSON_UNESCAPED_UNICODE ),
            )
        );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array( 'msg' => 'Falha de conexão com o backend: ' . $response->get_error_message() ) );
        }

        $status_code = (int) wp_remote_retrieve_response_code( $response );
        if ( $status_code !== 200 ) {
            $body = wp_remote_retrieve_body( $response );
            wp_send_json_error( array( 'msg' => "Backend retornou HTTP {$status_code}: " . substr( $body, 0, 300 ) ) );
        }

        $result = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $result ) ) {
            wp_send_json_error( array( 'msg' => 'Resposta inesperada do backend.' ) );
        }

        wp_send_json_success( $result );
    }

    /** Gera um slug único para a instância, evitando colisão no DB. */
    private static function unique_slug( $title, $exclude_id = 0 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'newsgame_instances';

        $base = substr( sanitize_title( $title ), 0, 180 );
        $slug = $base;
        $i    = 1;

        while ( $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE slug = %s AND id != %d",
            $slug, (int) $exclude_id
        ) ) ) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }
}
