<?php
defined( 'ABSPATH' ) || exit;

class Newsgame_DB {

    /**
     * Executado no activation hook — cria as tabelas SQL do motor.
     * dbDelta() é idempotente: seguro de rodar em re-ativações.
     */
    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Tabela de instâncias (cada "jogo" é uma instância)
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}newsgame_instances (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug         VARCHAR(200)    NOT NULL DEFAULT '',
            title        VARCHAR(500)    NOT NULL DEFAULT '',
            theme_prompt TEXT,
            characters   LONGTEXT        COMMENT 'JSON: lista de personagens com slug, label e queries RAG',
            density      SMALLINT        NOT NULL DEFAULT 20 COMMENT 'Número de dilemas a gerar (20–120)',
            status       VARCHAR(20)     NOT NULL DEFAULT 'draft',
            created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY   slug (slug)
        ) $charset;" );

        // Tabela de dilemas vinculados a cada instância
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}newsgame_dilemas (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            instance_id    BIGINT UNSIGNED NOT NULL,
            level          TINYINT         NOT NULL DEFAULT 1,
            position       SMALLINT        NOT NULL DEFAULT 0,
            title          VARCHAR(500)    NOT NULL DEFAULT '',
            body           TEXT,
            option_a       LONGTEXT        COMMENT 'JSON: {label, body, outcome, eixo_status}',
            option_b       LONGTEXT        COMMENT 'JSON: {label, body, outcome, eixo_status}',
            veredicto      LONGTEXT        COMMENT 'JSON: {title, body, eixos[]}',
            source_url     VARCHAR(2048)   DEFAULT NULL,
            review_status  VARCHAR(20)     NOT NULL DEFAULT 'pending' COMMENT 'pending | approved | published',
            created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY instance_id (instance_id)
        ) $charset;" );
    }
}
