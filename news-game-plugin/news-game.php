<?php
/**
 * Plugin Name:  News-Game
 * Plugin URI:   https://github.com/amazonia-real/news-game
 * Description:  Motor de dilemas jornalísticos gamificados. Shortcode: [news-game id="X"]
 * Version:      1.1.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author:       Eduardo Nunomura
 * License:      GPL-2.0-or-later
 * Text Domain:  news-game-plugin
 */
defined( 'ABSPATH' ) || exit;

define( 'NEWSGAME_VERSION', '1.1.0' ); // Versão do plugin: só o ?ver= dos arquivos do admin. A migração do banco usa Newsgame_DB::SCHEMA_VERSION.
define( 'NEWSGAME_DIR',     plugin_dir_path( __FILE__ ) );
define( 'NEWSGAME_URL',     plugin_dir_url( __FILE__ ) );

require_once NEWSGAME_DIR . 'includes/class-newsgame-db.php';
require_once NEWSGAME_DIR . 'includes/class-newsgame-settings.php';
require_once NEWSGAME_DIR . 'includes/class-newsgame-ingestor.php';
require_once NEWSGAME_DIR . 'includes/class-newsgame-shortcodes.php';
require_once NEWSGAME_DIR . 'includes/class-newsgame-admin-instances.php';
require_once NEWSGAME_DIR . 'includes/class-newsgame-generator.php';
require_once NEWSGAME_DIR . 'includes/class-newsgame-review.php';
require_once NEWSGAME_DIR . 'includes/class-newsgame-status.php';

// Instalação do zero: cria tabelas no activation hook
register_activation_hook( __FILE__, array( 'Newsgame_DB', 'create_tables' ) );

// Migração automática: garante colunas novas sem exigir reativação do plugin.
// Roda apenas na área administrativa e só executa queries SQL se a versão
// do schema registrada no banco divergir de Newsgame_DB::SCHEMA_VERSION.
add_action( 'admin_init', array( 'Newsgame_DB', 'maybe_upgrade' ) );

// Avisa o administrador quando uma tabela do plugin não pôde ser criada.
add_action( 'admin_notices', array( 'Newsgame_DB', 'aviso_tabela_faltando' ) );

Newsgame_Settings::init();
Newsgame_Ingestor::init();
Newsgame_Shortcodes::init();
Newsgame_Admin_Instances::init();
Newsgame_Generator::init();
Newsgame_Review::init();
