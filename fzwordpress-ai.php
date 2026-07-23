<?php
/**
 * Plugin Name:       FZ WordPress AI — Atendimento Inteligente
 * Plugin URI:        https://github.com/RLuf/fzwordpress-ai
 * Description:       Bot de pré-atendimento com IA: entende a dúvida do visitante, responde com base em uma base de conhecimento própria (RAG em SQLite), abre um protocolo e encaminha para um técnico via WhatsApp. Backends: Ollama, llama.cpp embarcado ou API online.
 * Version:           1.1.3
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            Webstorage / FazAI
 * Author URI:        https://webstorage.com.br
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       fzwordpress-ai
 * Domain Path:       /languages
 *
 * @package FZWordPressAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Sem acesso direto.
}

define( 'FZWAI_VERSION', '1.1.3' );
define( 'FZWAI_FILE', __FILE__ );
define( 'FZWAI_DIR', plugin_dir_path( __FILE__ ) );
define( 'FZWAI_URL', plugin_dir_url( __FILE__ ) );
define( 'FZWAI_BASENAME', plugin_basename( __FILE__ ) );

// Diretório de dados privado (SQLite + modelos + índices), fora do docroot público quando possível.
if ( ! defined( 'FZWAI_DATA_DIR' ) ) {
	$upload = wp_upload_dir( null, false );
	define( 'FZWAI_DATA_DIR', trailingslashit( $upload['basedir'] ) . 'fzwai-data' );
}

require_once FZWAI_DIR . 'includes/class-fzwai-db.php';
require_once FZWAI_DIR . 'includes/class-fzwai-settings.php';
require_once FZWAI_DIR . 'includes/class-fzwai-embeddings.php';
require_once FZWAI_DIR . 'includes/class-fzwai-llm.php';
require_once FZWAI_DIR . 'includes/class-fzwai-rag.php';
require_once FZWAI_DIR . 'includes/class-fzwai-protocol.php';
require_once FZWAI_DIR . 'includes/class-fzwai-support.php';
require_once FZWAI_DIR . 'includes/class-fzwai-chat.php';
require_once FZWAI_DIR . 'includes/class-fzwai-rest.php';
require_once FZWAI_DIR . 'includes/class-fzwai-widget.php';
require_once FZWAI_DIR . 'includes/class-fzwai-admin.php';
require_once FZWAI_DIR . 'includes/class-fzwai-llama.php';
require_once FZWAI_DIR . 'includes/class-fzwai-plugin.php';

/**
 * Ativação: cria diretório de dados, protege-o e monta o schema SQLite.
 */
function fzwai_activate() {
	if ( ! is_dir( FZWAI_DATA_DIR ) ) {
		wp_mkdir_p( FZWAI_DATA_DIR );
	}
	// Impede acesso web ao diretório de dados (SQLite/modelos).
	$htaccess = trailingslashit( FZWAI_DATA_DIR ) . '.htaccess';
	if ( ! file_exists( $htaccess ) ) {
		@file_put_contents( $htaccess, "Require all denied\nDeny from all\n" );
	}
	$index = trailingslashit( FZWAI_DATA_DIR ) . 'index.php';
	if ( ! file_exists( $index ) ) {
		@file_put_contents( $index, "<?php // Silence is golden.\n" );
	}

	if ( ! FZWAI_DB::available() ) {
		// Sem PDO SQLite: registra aviso, mas não aborta (o admin verá o diagnóstico).
		update_option( 'fzwai_sqlite_missing', 1 );
		return;
	}
	delete_option( 'fzwai_sqlite_missing' );
	FZWAI_DB::instance()->migrate();

	// Semente de configurações padrão (não sobrescreve se já existirem).
	FZWAI_Settings::seed_defaults();
}
register_activation_hook( __FILE__, 'fzwai_activate' );

/**
 * Desativação: nada destrutivo (dados e configurações permanecem).
 */
function fzwai_deactivate() {
	// Limpa cron agendado de indexação, se houver.
	$ts = wp_next_scheduled( 'fzwai_reindex_event' );
	if ( $ts ) {
		wp_unschedule_event( $ts, 'fzwai_reindex_event' );
	}
}
register_deactivation_hook( __FILE__, 'fzwai_deactivate' );

// Boot.
add_action( 'plugins_loaded', function () {
	load_plugin_textdomain( 'fzwordpress-ai', false, dirname( FZWAI_BASENAME ) . '/languages' );

	// Migração de schema para instalações já ativas (o migrate() de ativação não
	// roda em update de arquivos). migrate() é idempotente (CREATE ... IF NOT EXISTS).
	if ( FZWAI_DB::available() ) {
		try {
			$db = FZWAI_DB::instance();
			if ( (int) $db->get_meta( 'schema_version', '0' ) < FZWAI_DB::SCHEMA_VERSION ) {
				$db->migrate();
			}
		} catch ( \Throwable $e ) {
			// Não bloqueia o boot; o admin verá o diagnóstico se o SQLite falhar.
		}
	}

	FZWAI_Plugin::instance()->boot();
} );
