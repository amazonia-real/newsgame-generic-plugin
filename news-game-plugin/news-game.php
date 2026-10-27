<?php
/**
 * Plugin Name:  News-Game
 * Plugin URI:   https://github.com/amazonia-real/newsgame-generic-plugin
 * Description:  Motor de dilemas jornalísticos gamificados. Shortcode: [news-game id="X"]
 * Version:      1.2.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author:       Eduardo Nunomura
 * License:      GPL-2.0-or-later
 * Text Domain:  news-game-plugin
 */
defined( 'ABSPATH' ) || exit;

define( 'NEWSGAME_VERSION', '1.2.0' ); // Versão do plugin; reserva do ?ver= quando o arquivo não existe (ver newsgame_versao_do_arquivo). A migração do banco usa Newsgame_DB::SCHEMA_VERSION.
// Nível mínimo de compatibilidade que o plugin exige do backend (campo `api_compat`
// do /health; backend sem o campo conta como 1). Subir junto com o API_COMPAT do
// backend (backend-python/app/main.py) quando o plugin passar a exigir backend novo.
define( 'NEWSGAME_BACKEND_COMPAT_MIN', 3 );
define( 'NEWSGAME_DIR',     plugin_dir_path( __FILE__ ) );
define( 'NEWSGAME_URL',     plugin_dir_url( __FILE__ ) );

/**
 * Valor do `?ver=` de um arquivo do plugin (CSS ou JS, admin ou público): a data de
 * modificação do arquivo, e não o número da versão. Com a versão parada (reinstalar
 * a mesma versão), a URL não mudava e o navegador ou o cache da hospedagem serviam o
 * arquivo antigo. Arquivo ausente cai na versão do plugin.
 *
 * @param string $rel Caminho relativo à pasta do plugin, ex.: 'admin/js/x.js'.
 */
function newsgame_versao_do_arquivo( $rel ) {
    $mtime = @filemtime( NEWSGAME_DIR . $rel );
    return $mtime ? (string) $mtime : NEWSGAME_VERSION;
}

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
