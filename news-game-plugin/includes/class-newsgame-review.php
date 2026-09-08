<?php
/**
 * Newsgame_Review — Mesa Editorial HIL (Human-in-the-Loop)
 *
 * Endpoints AJAX para carregar, aprovar, descartar, restaurar
 * e pedir substituta de perguntas geradas pela IA.
 */
defined( 'ABSPATH' ) || exit;

class Newsgame_Review {

    const NONCE            = 'newsgame_review';
    const AJAX_LOAD        = 'newsgame_review_load';
    const AJAX_APPROVE     = 'newsgame_review_approve';
    const AJAX_APPROVE_ALL = 'newsgame_review_approve_all';
    const AJAX_DISCARD     = 'newsgame_review_discard';
    const AJAX_RESTORE     = 'newsgame_review_restore';
    const AJAX_REGEN       = 'newsgame_review_regen';
    const AJAX_CLEAR       = 'newsgame_review_clear_instance';
    const PER_PAGE         = 10;

    public static function init() {
        add_action( 'wp_ajax_' . self::AJAX_LOAD,        array( __CLASS__, 'ajax_load' ) );
        add_action( 'wp_ajax_' . self::AJAX_APPROVE,     array( __CLASS__, 'ajax_approve' ) );
        add_action( 'wp_ajax_' . self::AJAX_APPROVE_ALL, array( __CLASS__, 'ajax_approve_all' ) );
        add_action( 'wp_ajax_' . self::AJAX_DISCARD,     array( __CLASS__, 'ajax_discard' ) );
        add_action( 'wp_ajax_' . self::AJAX_RESTORE,     array( __CLASS__, 'ajax_restore' ) );
        add_action( 'wp_ajax_' . self::AJAX_REGEN,       array( __CLASS__, 'ajax_regen' ) );
        add_action( 'wp_ajax_' . self::AJAX_CLEAR,       array( __CLASS__, 'ajax_clear_instance' ) );
    }

    /* ── Segurança centralizada ─────────────────────────────── */

    private static function guard() {
        check_ajax_referer( self::NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'msg' => 'Permissão insuficiente.' ) );
        }
    }

    /**
     * Aborta a resposta AJAX quando a escrita falhou de verdade.
     *
     * SÓ `false` é falha. $wpdb->update() devolve 0 quando achou a linha e
     * nenhum valor mudou — aprovar um dilema sem editar nada, descartar o que
     * já estava descartado. Testar `! $resultado` trataria esses casos como
     * erro e produziria alarme falso: é o defeito do C5 em outra roupa.
     *
     * O 0 é seguro de ignorar aqui por uma razão LOCAL, não por convenção
     * geral: self::owns() já confirmou que a linha existe antes de cada escrita
     * deste arquivo, então "0 linhas afetadas" não pode significar "linha não
     * encontrada". Quem copiar este padrão para um handler SEM owns() perde essa
     * garantia e precisa decidir por conta própria o que 0 significa lá — nos
     * dois handlers de lote daqui, significa "conjunto vazio", e está anotado
     * em cada um.
     *
     * Não retorna quando falha: wp_send_json_error() encerra o request.
     *
     * @param mixed  $resultado Retorno de $wpdb->update/insert/delete/query.
     * @param string $o_que     Trecho da mensagem ("a aprovação").
     */
    private static function abort_on_db_error( $resultado, $o_que ) {
        global $wpdb;

        if ( false !== $resultado ) {
            return;
        }

        $erro = $wpdb->last_error ?: 'o banco não informou a causa.';
        error_log( "[Newsgame] escrita falhou em {$o_que} — MySQL: {$erro}" );
        wp_send_json_error( array(
            'msg' => "Não foi possível gravar {$o_que}: {$erro}",
        ) );
    }

    /* ── 1. Carrega lista paginada de perguntas ─────────────── */

    public static function ajax_load() {
        self::guard();
        global $wpdb;

        $instance_id = (int) ( $_POST['instance_id'] ?? 0 );
        $status      = sanitize_key( $_POST['status'] ?? 'pending' );
        $page        = max( 1, (int) ( $_POST['page'] ?? 1 ) );

        if ( ! in_array( $status, array( 'pending', 'approved', 'discarded' ), true ) ) {
            wp_send_json_error( array( 'msg' => 'Status inválido.' ) );
        }

        $table  = $wpdb->prefix . 'newsgame_dilemas';
        $total  = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE instance_id = %d AND review_status = %s",
            $instance_id, $status
        ) );
        $pages  = max( 1, (int) ceil( $total / self::PER_PAGE ) );
        $offset = ( min( $page, $pages ) - 1 ) * self::PER_PAGE;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE instance_id = %d AND review_status = %s
             ORDER BY position ASC, id ASC
             LIMIT %d OFFSET %d",
            $instance_id, $status, self::PER_PAGE, $offset
        ) );

        wp_send_json_success( array(
            'questions' => array_map( array( __CLASS__, 'row_to_array' ), $rows ),
            'total'     => $total,
            'pages'     => $pages,
            'page'      => $page,
        ) );
    }

    /* ── 2. Aprovar — salva edições e muda status ───────────── */

    public static function ajax_approve() {
        self::guard();
        global $wpdb;

        $id          = (int) ( $_POST['question_id'] ?? 0 );
        $instance_id = (int) ( $_POST['instance_id'] ?? 0 );

        if ( ! $id || ! self::owns( $id, $instance_id ) ) {
            wp_send_json_error( array( 'msg' => 'Pergunta não encontrada.' ) );
        }

        $table = $wpdb->prefix . 'newsgame_dilemas';

        // Texto ANTES da edição: edited_fields() sobrescreve body/option_a/option_b,
        // que são exatamente as entradas do /assign-weights. Se mudaram, o peso
        // existente descreve um texto que não existe mais.
        $antes = $wpdb->get_row( $wpdb->prepare(
            "SELECT body, option_a, option_b FROM {$table} WHERE id = %d",
            $id
        ), ARRAY_A );

        $data = array_merge( self::edited_fields(), array( 'review_status' => 'approved' ) );
        $gravado = $wpdb->update( $table, $data, array( 'id' => $id ), null, array( '%d' ) );
        self::abort_on_db_error( $gravado, 'a aprovação' );

        // Zera o peso obsoleto. O recálculo NÃO acontece aqui: acontece na próxima
        // vez que "Aprovar Todas" ou "⚖ Atribuir Pesos" rodar. Enquanto isso o dilema
        // conta como pendência e aparece no aviso da tela de edição.
        //
        // Query direta em vez de incluir no $wpdb->update acima: aquela chamada passa
        // $format = null, o que trataria tudo como %s e gravaria '' no lugar de NULL.
        // String vazia passaria pelo filtro axis_weights IS NULL e mentiria no
        // contador ⚖.
        $texto_mudou = $antes && (
            $antes['body']     !== ( $data['body']     ?? null ) ||
            $antes['option_a'] !== ( $data['option_a'] ?? null ) ||
            $antes['option_b'] !== ( $data['option_b'] ?? null )
        );
        if ( $texto_mudou ) {
            $zerado = $wpdb->query( $wpdb->prepare(
                "UPDATE {$table} SET axis_weights = NULL WHERE id = %d",
                $id
            ) );

            // Falha AQUI é diferente das outras e não passa pelo helper: a
            // aprovação já está gravada, então "não foi possível gravar" seria
            // meia verdade. O peso velho sobrevive descrevendo um texto que não
            // existe mais, e o contador ⚖ não vai acusar a pendência porque
            // axis_weights não está NULL. Sem aviso explícito, é exatamente a
            // corrupção silenciosa do C11 — pesos apontando para um enunciado
            // que não os produziu.
            if ( false === $zerado ) {
                $erro = $wpdb->last_error ?: 'o banco não informou a causa.';
                error_log( "[Newsgame] reset de axis_weights falhou no dilema {$id} — MySQL: {$erro}" );
                wp_send_json_error( array(
                    'msg' => 'A aprovação FOI gravada, mas o peso de eixo antigo não foi'
                        . ' zerado e agora descreve o texto anterior. Use "⚖ Atribuir'
                        . ' Pesos" antes de publicar. Erro do banco: ' . $erro,
                ) );
            }
        }

        wp_send_json_success( array( 'msg' => 'Aprovada.' ) );
    }

    /* ── 3. Descartar ───────────────────────────────────────── */

    public static function ajax_discard() {
        self::guard();
        global $wpdb;

        $id          = (int) ( $_POST['question_id'] ?? 0 );
        $instance_id = (int) ( $_POST['instance_id'] ?? 0 );

        if ( ! $id || ! self::owns( $id, $instance_id ) ) {
            wp_send_json_error( array( 'msg' => 'Pergunta não encontrada.' ) );
        }

        $gravado = $wpdb->update(
            $wpdb->prefix . 'newsgame_dilemas',
            array( 'review_status' => 'discarded' ),
            array( 'id' => $id ),
            array( '%s' ), array( '%d' )
        );
        self::abort_on_db_error( $gravado, 'o descarte' );

        wp_send_json_success( array( 'msg' => 'Descartada.' ) );
    }

    /* ── 4. Restaurar para pendente ─────────────────────────── */

    public static function ajax_restore() {
        self::guard();
        global $wpdb;

        $id          = (int) ( $_POST['question_id'] ?? 0 );
        $instance_id = (int) ( $_POST['instance_id'] ?? 0 );

        if ( ! $id || ! self::owns( $id, $instance_id ) ) {
            wp_send_json_error( array( 'msg' => 'Pergunta não encontrada.' ) );
        }

        $gravado = $wpdb->update(
            $wpdb->prefix . 'newsgame_dilemas',
            array( 'review_status' => 'pending' ),
            array( 'id' => $id ),
            array( '%s' ), array( '%d' )
        );
        self::abort_on_db_error( $gravado, 'a devolução para revisão' );

        wp_send_json_success( array( 'msg' => 'Devolvida para revisão.' ) );
    }

    /* ── 5. Pedir Substituta — chama /regenerate no backend ── */

    public static function ajax_regen() {
        self::guard();

        // Um botão por dilema na Mesa Editorial — o mais exposto a clique duplo
        // entre as rotas que consomem cota Gemini.
        if ( Newsgame_Settings::rate_limited( 'regenerate', 10 ) ) {
            wp_send_json_error( array( 'msg' => 'Muitas substituições em sequência. Aguarde um minuto — cada substituta consome cota da chave Gemini.' ) );
        }

        global $wpdb;

        $question_id = (int) ( $_POST['question_id'] ?? 0 );
        $instance_id = (int) ( $_POST['instance_id'] ?? 0 );

        $original = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}newsgame_dilemas WHERE id = %d AND instance_id = %d",
            $question_id, $instance_id
        ) );

        if ( ! $original ) {
            wp_send_json_error( array( 'msg' => 'Pergunta original não encontrada.' ) );
        }

        $instance    = Newsgame_Admin_Instances::get( $instance_id );
        $backend_url = Newsgame_Settings::backend_url();

        if ( ! $instance || ! $backend_url ) {
            wp_send_json_error( array( 'msg' => 'URL do backend não configurada em Configurações.' ) );
        }

        $api_key         = (string) get_option( 'newsgame_backend_api_key', '' );
        $characters      = json_decode( $instance->characters ?? '[]', true ) ?: array();
        $personagem_tipo = sanitize_key( $_POST['personagem_tipo'] ?? ( $original->character_slug ?? 'geral' ) );
        $nivel           = max( 1, min( 2, (int) ( $_POST['nivel'] ?? $original->level ) ) );

        $payload = array(
            'site_id'         => home_url(),
            'instance_id'     => $instance_id,
            'theme_prompt'    => $instance->theme_prompt,
            'personagem_tipo' => $personagem_tipo,
            'nivel'           => $nivel,
            'characters'      => $characters,
            'num_options'     => (int) ( $instance->num_options   ?? 2 ),
            'enable_levels'   => (bool) ( $instance->enable_levels ?? false ),
        );

        /*
         * A chave do PROVEDOR ATIVO em header — fase 0 do H1, mesmo padrão do
         * `/generate-one`. Antes daquele passo o "Pedir Substituta" usava a chave
         * do `.env` do backend, e não a que o editor configurou no painel.
         *
         * Aqui o `$payload` é montado inteiramente no servidor (:266-275) e não
         * passa pelo browser, então o corpo também serviria. Fica no header por
         * consistência com a outra rota e para que só exista uma forma de o
         * plugin mandar chave.
         *
         * ⚠️ `provider_headers()` E NÃO `gemini_key()` — desde o B6. Ele devolve
         * `X-LLM-Provider` mais o header de chave do provedor escolhido no painel,
         * com o nome do header saindo da tabela `PROVEDORES`. Trocar isto por um
         * `if ( 'deepseek' === … )` aqui replicaria a mesma decisão nos quatro
         * pontos que falam com o backend.
         */
        $response = wp_remote_post(
            rtrim( $backend_url, '/' ) . '/regenerate',
            array(
                'timeout'   => 120,
                'sslverify' => ! ( defined( 'WP_DEBUG' ) && WP_DEBUG && str_contains( $backend_url, 'localhost' ) ),
                'headers'   => array_merge(
                    array(
                        'Content-Type' => 'application/json',
                        'X-Api-Key'    => $api_key,
                    ),
                    Newsgame_Settings::provider_headers()
                ),
                'body' => wp_json_encode( $payload ),
            )
        );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array( 'msg' => 'Erro ao contatar backend: ' . $response->get_error_message() ) );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body ) || ! isset( $body['titulo'] ) ) {
            $detail = $body['detail'] ?? 'Resposta inválida do backend.';
            wp_send_json_error( array( 'msg' => (string) $detail ) );
        }

        // Salva a substituta como pending e SÓ ENTÃO descarta a original.
        //
        // A ordem já era esta; o que faltava era a confirmação no meio. Sem ela,
        // um insert falhado descartava a original de qualquer jeito e o editor
        // perdia o dilema sem receber nada em troca — perda de dado, não
        // mensagem errada. Inverter a ordem não resolveria: o que protege é não
        // descartar sem saber que a substituta entrou.
        //
        // O retorno de save_dilema() é a única sentinela confiável aqui.
        // $wpdb->insert_id NÃO serve: um insert que falha não o zera, e o
        // rate_limited() no topo deste handler já fez o seu próprio INSERT em
        // wp_options no mesmo request — o valor remanescente pode ser não-zero e
        // apontar para nada. save_dilema() já loga a causa no error_log.
        $inserida = Newsgame_Generator::save_dilema( $body, $instance_id );
        if ( false === $inserida ) {
            $erro = $wpdb->last_error ?: 'o banco não informou a causa.';
            wp_send_json_error( array(
                'msg' => 'A substituta voltou do backend mas não foi gravada, então a'
                    . ' pergunta original foi MANTIDA. Erro do banco: ' . $erro,
            ) );
        }

        $new_id = (int) $wpdb->insert_id;

        // Se o descarte falhar agora, a substituta fica no banco como pending:
        // resíduo visível na Mesa e corrigível com um clique, ao contrário da
        // perda da original.
        $descartada = $wpdb->update(
            $wpdb->prefix . 'newsgame_dilemas',
            array( 'review_status' => 'discarded' ),
            array( 'id' => $question_id ),
            array( '%s' ), array( '%d' )
        );
        self::abort_on_db_error( $descartada, 'o descarte da pergunta original' );

        $new_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}newsgame_dilemas WHERE id = %d",
            $new_id
        ) );

        wp_send_json_success( array(
            'msg'      => 'Substituta gerada com sucesso.',
            'question' => self::row_to_array( $new_row ),
        ) );
    }

    /* ── 6. Aprovar todas as pendentes de uma vez ──────────── */

    public static function ajax_approve_all() {
        self::guard();
        global $wpdb;

        $instance_id = (int) ( $_POST['instance_id'] ?? 0 );
        if ( ! $instance_id ) {
            wp_send_json_error( array( 'msg' => 'ID de instância inválido.' ) );
        }

        $table = $wpdb->prefix . 'newsgame_dilemas';

        // Os IDs precisam ser capturados ANTES do UPDATE: $wpdb->update devolve
        // contagem, não IDs, e depois não há como distinguir os recém-aprovados
        // dos que já estavam aprovados.
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE instance_id = %d AND review_status = 'pending'",
            $instance_id
        ) );

        $updated = $wpdb->update(
            $table,
            array( 'review_status' => 'approved' ),
            array( 'instance_id' => $instance_id, 'review_status' => 'pending' ),
            array( '%s' ),
            array( '%d', '%s' )
        );

        // Testa ANTES do (int) da linha do payload: `(int) false` é 0, o mesmo
        // valor de "não havia nada pendente", e lá embaixo os dois casos ficam
        // indistinguíveis.
        //
        // Este handler não passa por owns() — 0 aqui significa "conjunto vazio",
        // não "linha não encontrada", e é legítimo: aprovar em lote sem
        // pendentes é no-op, não erro.
        self::abort_on_db_error( $updated, 'a aprovação em lote' );

        // Apura só a CAUSA de a atribuição não ter rodado. O estado — quantos ficaram
        // sem peso — é lido do banco mais abaixo, e não deduzido daqui.
        //
        // UMA chamada cobre os N recém-aprovados. Fatiar por dilema não traria ganho
        // (o backend pontua cada um independentemente) e transformaria uma aprovação
        // de 12 em 12 acertos de throttle, estourando o teto de 10/min.
        $erro = null;
        if ( $ids ) {
            if ( Newsgame_Settings::rate_limited( 'assign_weights', 10 ) ) {
                $erro = 'limite de atribuições por minuto atingido';
            } else {
                $res  = Newsgame_Admin_Instances::assign_weights_for( $instance_id, $ids );
                $erro = $res['error'];
            }
        }

        // Estado real, perguntado ao banco DEPOIS da operação — não inferido do
        // retorno dela. Inferir foi o que produziu o falso alarme do C5:
        // assign_weights_for() não seleciona quem já tem peso e devolve updated = 0,
        // que a versão anterior lia como falha e anunciava ao editor. O custo é uma
        // query indexada por instance_id, uma vez por aprovação em lote.
        $sem_peso = Newsgame_Admin_Instances::count_unweighted_approved( $instance_id );

        // Causa e estado ficam distinguíveis de propósito: "não deu para tentar"
        // (throttle, backend fora) não é a mesma coisa que "sobrou peso faltando", e
        // "nada a fazer" não é nenhuma das duas. A aprovação é decisão editorial e
        // NÃO é desfeita se a IA falhar — mas a falha é sempre reportada.
        $pedido = ' Use "⚖ Atribuir Pesos" antes de publicar, porque sem pesos o jogo'
            . ' entrega veredito genérico.';

        $warning = null;
        if ( $erro && $sem_peso > 0 ) {
            $warning = sprintf(
                'A atribuição automática de pesos não foi concluída (%s). %d pergunta(s)'
                    . ' aprovada(s) seguem sem peso de eixo.%s',
                $erro,
                $sem_peso,
                $pedido
            );
        } elseif ( $erro ) {
            $warning = sprintf(
                'A atribuição automática de pesos não foi concluída (%s), mas nenhuma'
                    . ' pergunta aprovada está sem peso — nada a corrigir agora.',
                $erro
            );
        } elseif ( $sem_peso > 0 ) {
            $warning = sprintf(
                '%d pergunta(s) aprovada(s) seguem sem peso de eixo.%s',
                $sem_peso,
                $pedido
            );
        }

        wp_send_json_success( array(
            'msg'     => $sem_peso > 0
                ? 'Aprovadas, mas com pendência de pesos.'
                : 'Todas as pendentes foram aprovadas.',
            'updated' => (int) $updated,
            'warning' => $warning,
        ) );
    }

    /* ── 7. Limpar todas as perguntas da instância ──────────── */

    public static function ajax_clear_instance() {
        self::guard();
        global $wpdb;

        $instance_id = (int) ( $_POST['instance_id'] ?? 0 );
        if ( ! $instance_id ) {
            wp_send_json_error( array( 'msg' => 'ID de instância inválido.' ) );
        }

        $deleted = $wpdb->delete(
            $wpdb->prefix . 'newsgame_dilemas',
            array( 'instance_id' => $instance_id ),
            array( '%d' )
        );

        // Mesma razão do lote acima: `(int) false` viraria 0 no payload, e 0 é
        // o valor legítimo de "a instância já estava vazia". Sem owns() aqui
        // também, e também sem precisar dele.
        self::abort_on_db_error( $deleted, 'a remoção das perguntas' );

        $rebaixada = $wpdb->update(
            $wpdb->prefix . 'newsgame_instances',
            array( 'status' => 'draft' ),
            array( 'id'     => $instance_id ),
            array( '%s' ), array( '%d' )
        );
        self::abort_on_db_error( $rebaixada, 'o rebaixamento da instância para rascunho' );

        wp_send_json_success( array(
            'msg'     => 'Todas as perguntas foram removidas.',
            'deleted' => (int) $deleted,
        ) );
    }

    /* ── Helpers ────────────────────────────────────────────── */

    private static function owns( $question_id, $instance_id ) {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}newsgame_dilemas WHERE id = %d AND instance_id = %d",
            $question_id, $instance_id
        ) );
    }

    private static function edited_fields() {
        // Preserva impacto dos campos ocultos (não editável na interface)
        $impacto_a = json_decode( wp_unslash( $_POST['opt_a_impacto'] ?? '{}' ), true )
                     ?: array( 'popularidade' => 0, 'recursos' => 0 );
        $impacto_b = json_decode( wp_unslash( $_POST['opt_b_impacto'] ?? '{}' ), true )
                     ?: array( 'popularidade' => 0, 'recursos' => 0 );

        $opt_a = array(
            'texto'        => sanitize_textarea_field( wp_unslash( $_POST['opt_a_texto'] ?? '' ) ),
            'consequencia' => sanitize_textarea_field( wp_unslash( $_POST['opt_a_cons']  ?? '' ) ),
            'impacto'      => $impacto_a,
        );
        $opt_b = array(
            'texto'        => sanitize_textarea_field( wp_unslash( $_POST['opt_b_texto'] ?? '' ) ),
            'consequencia' => sanitize_textarea_field( wp_unslash( $_POST['opt_b_cons']  ?? '' ) ),
            'impacto'      => $impacto_b,
        );
        $verd = array(
            'desfecho' => sanitize_textarea_field( wp_unslash( $_POST['verd_desfecho'] ?? '' ) ),
            'licao'    => sanitize_textarea_field( wp_unslash( $_POST['verd_licao']    ?? '' ) ),
            'sintese'  => sanitize_textarea_field( wp_unslash( $_POST['verd_sintese']  ?? '' ) ),
        );

        $fields = array(
            'title'      => sanitize_text_field( wp_unslash( $_POST['q_title']    ?? '' ) ),
            'body'       => sanitize_textarea_field( wp_unslash( $_POST['q_body'] ?? '' ) ),
            'option_a'   => wp_json_encode( $opt_a, JSON_UNESCAPED_UNICODE ),
            'option_b'   => wp_json_encode( $opt_b, JSON_UNESCAPED_UNICODE ),
            'veredicto'  => wp_json_encode( $verd,  JSON_UNESCAPED_UNICODE ),
            'source_url' => esc_url_raw( wp_unslash( $_POST['source_url'] ?? '' ) ),
        );

        if ( isset( $_POST['opt_c_texto'] ) ) {
            $impacto_c = json_decode( wp_unslash( $_POST['opt_c_impacto'] ?? '{}' ), true )
                         ?: array( 'popularidade' => 0, 'recursos' => 0 );
            $opt_c = array(
                'texto'        => sanitize_textarea_field( wp_unslash( $_POST['opt_c_texto'] ?? '' ) ),
                'consequencia' => sanitize_textarea_field( wp_unslash( $_POST['opt_c_cons']  ?? '' ) ),
                'impacto'      => $impacto_c,
            );
            $fields['option_c'] = wp_json_encode( $opt_c, JSON_UNESCAPED_UNICODE );
        }

        /*
         * Imagem de fundo do dilema.
         *
         * Só entra em $fields quando o campo foi realmente enviado — mesmo
         * critério do option_c. Se usasse `?? ''`, um chamador que não conhece
         * o campo apagaria a imagem escolhida sem querer.
         *
         * `0` vira NULL de propósito: é assim que o editor REMOVE a imagem.
         *
         * NOTA DE PROJETO — não cabear isto no zeramento do axis_weights.
         * O ajax_approve() zera os pesos quando o texto foi editado, porque
         * peso é derivado do texto e descreveria um texto que não existe mais.
         * Imagem NÃO é derivada do texto: editar o enunciado não deve apagar a
         * escolha do editor. A simetria é tentadora e estaria errada.
         */
        if ( isset( $_POST['image_id'] ) ) {
            $image_id = absint( wp_unslash( $_POST['image_id'] ) );
            $fields['image_id'] = $image_id ? $image_id : null;
        }

        return $fields;
    }

    public static function row_to_array( $row ) {
        if ( ! $row ) return array();

        $opt_a = json_decode( $row->option_a  ?? '{}', true ) ?: array();
        $opt_b = json_decode( $row->option_b  ?? '{}', true ) ?: array();
        $verd  = json_decode( $row->veredicto ?? '{}', true ) ?: array();

        $impacto_vazio = array( 'popularidade' => 0, 'recursos' => 0 );

        $data = array(
            'id'            => (int) $row->id,
            'title'         => $row->title         ?? '',
            'body'          => $row->body          ?? '',
            'opt_a_texto'   => $opt_a['texto']        ?? '',
            'opt_a_cons'    => $opt_a['consequencia'] ?? '',
            'opt_a_impacto' => $opt_a['impacto']      ?? $impacto_vazio,
            'opt_b_texto'   => $opt_b['texto']        ?? '',
            'opt_b_cons'    => $opt_b['consequencia'] ?? '',
            'opt_b_impacto' => $opt_b['impacto']      ?? $impacto_vazio,
            'verd_desfecho'        => $verd['desfecho']            ?? '',
            'verd_licao'           => $verd['licao']               ?? '',
            'verd_sintese'         => $verd['sintese']             ?? '',
            'alternativa_correta'  => $verd['alternativa_correta'] ?? '',
            'source_url'    => $row->source_url    ?? '',
            'axis_weights'  => $row->axis_weights ? json_decode( $row->axis_weights, true ) : null,
            'level'         => (int) $row->level,
            'character_slug'=> $row->character_slug ?? 'geral',
            'review_status' => $row->review_status ?? 'pending',
            // Imagem de fundo: o ID alimenta o campo oculto, e miniatura e
            // crédito alimentam a pré-visualização ao lado do seletor. O
            // crédito aparece ali para que a AUSÊNCIA dele seja visível na hora
            // de escolher, e não só quando o jogo já estiver publicado.
            'image_id'      => (int) ( $row->image_id ?? 0 ),
            'image_thumb'   => '',
            'image_credito' => '',
        );

        if ( $data['image_id'] ) {
            $thumb = wp_get_attachment_image_url( $data['image_id'], 'thumbnail' );
            $data['image_thumb']   = $thumb ? esc_url_raw( $thumb ) : '';
            $data['image_credito'] = (string) wp_get_attachment_caption( $data['image_id'] );
        }

        if ( ! empty( $row->option_c ) ) {
            $opt_c = json_decode( $row->option_c, true ) ?: array();
            $data['opt_c_texto']   = $opt_c['texto']        ?? '';
            $data['opt_c_cons']    = $opt_c['consequencia'] ?? '';
            $data['opt_c_impacto'] = $opt_c['impacto']      ?? $impacto_vazio;
        }

        return $data;
    }
}
