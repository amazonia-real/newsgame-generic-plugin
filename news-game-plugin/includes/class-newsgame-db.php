<?php
defined( 'ABSPATH' ) || exit;

class Newsgame_DB {

    /**
     * Versão interna do schema — incrementar aqui ao adicionar colunas.
     * Independente da versão do plugin: só muda quando o banco muda.
     */
    const SCHEMA_VERSION = '1.4';

    /* ── Ponto de entrada público ──────────────────────────── */

    /**
     * Chamado no activation_hook (nova instalação) e no admin_init
     * (atualização sem reativação). Idempotente: não faz nada se
     * o schema já estiver na versão correta.
     */
    public static function maybe_upgrade() {
        if ( get_option( 'newsgame_schema_version' ) === self::SCHEMA_VERSION ) {
            return;
        }
        self::ensure_schema();
        update_option( 'newsgame_schema_version', self::SCHEMA_VERSION );
    }

    /**
     * Chamado apenas no activation_hook para instalações do zero.
     * Delega para ensure_schema() que é o único ponto de verdade.
     */
    public static function create_tables() {
        self::ensure_schema();
        update_option( 'newsgame_schema_version', self::SCHEMA_VERSION );
    }

    /* ── Núcleo: cria tabelas e garante colunas ────────────── */

    private static function ensure_schema() {
        global $wpdb;

        $dilemas   = $wpdb->prefix . 'newsgame_dilemas';
        $instances = $wpdb->prefix . 'newsgame_instances';

        // Se as tabelas ainda não existem, cria tudo via dbDelta
        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '$dilemas'" ) ) {
            self::run_dbdelta();
            return;
        }

        // Tabelas existem: garante colunas novas com ALTER TABLE direto
        // wp_newsgame_instances
        self::add_column_if_missing(
            $instances,
            'num_options',
            "TINYINT NOT NULL DEFAULT 2 COMMENT '2 ou 3 opcoes de resposta' AFTER `density`"
        );
        self::add_column_if_missing(
            $instances,
            'enable_levels',
            "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0=nivel unico, 1=Aprendiz/Pro' AFTER `num_options`"
        );
        self::add_column_if_missing(
            $instances,
            'nivel_padrao',
            "VARCHAR(20) NOT NULL DEFAULT 'pedagogico' COMMENT 'ludico | pedagogico | complexo' AFTER `enable_levels`"
        );
        self::add_column_if_missing(
            $instances,
            'axis_matrix',
            "LONGTEXT NULL COMMENT 'JSON: eixos e perfis propostos por /analyze-domain' AFTER `nivel_padrao`"
        );

        // wp_newsgame_dilemas
        self::add_column_if_missing(
            $dilemas,
            'character_slug',
            "VARCHAR(100) NOT NULL DEFAULT 'geral' COMMENT 'slug do personagem' AFTER `level`"
        );
        self::add_column_if_missing(
            $dilemas,
            'option_c',
            "LONGTEXT NULL COMMENT 'JSON: {texto, consequencia} - presente se num_options=3' AFTER `option_b`"
        );
        self::add_column_if_missing(
            $dilemas,
            'axis_weights',
            "LONGTEXT NULL COMMENT 'JSON: {A:{eixo1,eixo2,eixo3}, B:{...}} — pesos por alternativa via /assign-weights' AFTER `source_url`"
        );
    }

    /* ── dbDelta: cria as tabelas do zero ──────────────────── */

    private static function run_dbdelta() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}newsgame_instances (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug         VARCHAR(200)    NOT NULL DEFAULT '',
            title        VARCHAR(500)    NOT NULL DEFAULT '',
            theme_prompt TEXT,
            characters    LONGTEXT        COMMENT 'JSON: lista de personagens com slug, label e queries RAG',
            density       SMALLINT        NOT NULL DEFAULT 20 COMMENT 'Numero de perguntas a gerar (20-120)',
            num_options   TINYINT         NOT NULL DEFAULT 2 COMMENT '2 ou 3 opcoes de resposta por pergunta',
            enable_levels TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '0=nivel unico, 1=Aprendiz/Pro',
            nivel_padrao  VARCHAR(20)     NOT NULL DEFAULT 'pedagogico' COMMENT 'ludico | pedagogico | complexo',
            axis_matrix   LONGTEXT        NULL COMMENT 'JSON: eixos e perfis propostos por /analyze-domain',
            status        VARCHAR(20)     NOT NULL DEFAULT 'draft',
            created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY   slug (slug)
        ) $charset;" );

        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}newsgame_dilemas (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            instance_id    BIGINT UNSIGNED NOT NULL,
            level          TINYINT         NOT NULL DEFAULT 1,
            character_slug VARCHAR(100)    NOT NULL DEFAULT 'geral' COMMENT 'slug do personagem que gerou este dilema',
            position       SMALLINT        NOT NULL DEFAULT 0,
            title          VARCHAR(500)    NOT NULL DEFAULT '',
            body           TEXT,
            option_a       LONGTEXT        COMMENT 'JSON: {texto, consequencia}',
            option_b       LONGTEXT        COMMENT 'JSON: {texto, consequencia}',
            option_c       LONGTEXT        NULL COMMENT 'JSON: {texto, consequencia} - presente se num_options=3',
            veredicto      LONGTEXT        COMMENT 'JSON: {sintese, desfecho, licao}',
            source_url     VARCHAR(2048)   DEFAULT NULL,
            axis_weights   LONGTEXT        NULL COMMENT 'JSON: {A:{eixo1,eixo2,eixo3}, B:{...}} — pesos por alternativa via /assign-weights',
            review_status  VARCHAR(20)     NOT NULL DEFAULT 'pending' COMMENT 'pending | approved | discarded',
            created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY instance_id (instance_id)
        ) $charset;" );
    }

    /* ── Helper: ADD COLUMN se não existir ─────────────────── */

    private static function add_column_if_missing( $table, $column, $definition ) {
        global $wpdb;

        $exists = $wpdb->get_var(
            $wpdb->prepare( "SHOW COLUMNS FROM `$table` LIKE %s", $column )
        );

        if ( ! $exists ) {
            $wpdb->query( "ALTER TABLE `$table` ADD COLUMN `$column` $definition" );

            if ( $wpdb->last_error ) {
                error_log( "[Newsgame_DB] ALTER TABLE $table ADD $column FALHOU: " . $wpdb->last_error );
            } else {
                error_log( "[Newsgame_DB] Coluna '$column' adicionada a $table com sucesso." );
            }
        }
    }
}
