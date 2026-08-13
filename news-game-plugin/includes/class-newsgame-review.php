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
        $wpdb->update( $table, $data, array( 'id' => $id ), null, array( '%d' ) );

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
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$table} SET axis_weights = NULL WHERE id = %d",
                $id
            ) );
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

        $wpdb->update(
            $wpdb->prefix . 'newsgame_dilemas',
            array( 'review_status' => 'discarded' ),
            array( 'id' => $id ),
            array( '%s' ), array( '%d' )
        );

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

        $wpdb->update(
            $wpdb->prefix . 'newsgame_dilemas',
            array( 'review_status' => 'pending' ),
            array( 'id' => $id ),
            array( '%s' ), array( '%d' )
        );

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

        $response = wp_remote_post(
            rtrim( $backend_url, '/' ) . '/regenerate',
            array(
                'timeout'   => 120,
                'sslverify' => ! ( defined( 'WP_DEBUG' ) && WP_DEBUG && str_contains( $backend_url, 'localhost' ) ),
                'headers'   => array(
                    'Content-Type' => 'application/json',
                    'X-Api-Key'    => $api_key,
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

        // Salva a substituta como pending e descarta a original
        Newsgame_Generator::save_dilema( $body, $instance_id );
        $new_id = (int) $wpdb->insert_id;

        $wpdb->update(
            $wpdb->prefix . 'newsgame_dilemas',
            array( 'review_status' => 'discarded' ),
            array( 'id' => $question_id ),
            array( '%s' ), array( '%d' )
        );

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

        // UMA chamada cobre os N recém-aprovados. Fatiar por dilema não traria ganho
        // (o backend pontua cada um independentemente) e transformaria uma aprovação
        // de 12 em 12 acertos de throttle, estourando o teto de 10/min.
        //
        // A aprovação é decisão editorial e NÃO é desfeita se a IA falhar — mas a
        // falha é sempre reportada, nunca silenciosa.
        $aviso = null;
        if ( $ids ) {
            if ( Newsgame_Settings::rate_limited( 'assign_weights', 10 ) ) {
                $aviso = 'limite de atribuições por minuto atingido';
            } else {
                $res = Newsgame_Admin_Instances::assign_weights_for( $instance_id, $ids );
                if ( $res['error'] ) {
                    $aviso = $res['error'];
                } elseif ( $res['updated'] < count( $ids ) ) {
                    $aviso = sprintf(
                        'apenas %d de %d receberam peso',
                        $res['updated'],
                        count( $ids )
                    );
                }
            }
        }

        wp_send_json_success( array(
            'msg'     => $aviso
                ? 'Aprovadas, mas com pendência de pesos.'
                : 'Todas as pendentes foram aprovadas.',
            'updated' => (int) $updated,
            'warning' => $aviso
                ? 'Aprovadas, mas sem pesos de eixo: ' . $aviso
                  . '. Use "⚖ Atribuir Pesos" antes de publicar — sem pesos o jogo '
                  . 'entrega veredito genérico.'
                : null,
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

        $wpdb->update(
            $wpdb->prefix . 'newsgame_instances',
            array( 'status' => 'draft' ),
            array( 'id'     => $instance_id ),
            array( '%s' ), array( '%d' )
        );

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
        );

        if ( ! empty( $row->option_c ) ) {
            $opt_c = json_decode( $row->option_c, true ) ?: array();
            $data['opt_c_texto']   = $opt_c['texto']        ?? '';
            $data['opt_c_cons']    = $opt_c['consequencia'] ?? '';
            $data['opt_c_impacto'] = $opt_c['impacto']      ?? $impacto_vazio;
        }

        return $data;
    }
}
