<?php
/**
 * Bootstrap central: liga REST, widget e admin.
 *
 * @package FZWordPressAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FZWAI_Plugin {

	/** @var FZWAI_Plugin|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot() {
		// REST sempre ativo (frente pública do atendimento).
		if ( class_exists( 'FZWAI_REST' ) ) {
			FZWAI_REST::boot();
		}

		// Widget no front-end.
		if ( class_exists( 'FZWAI_Widget' ) && is_callable( array( 'FZWAI_Widget', 'boot' ) ) ) {
			FZWAI_Widget::boot();
		}

		// Admin.
		if ( is_admin() && class_exists( 'FZWAI_Admin' ) && is_callable( array( 'FZWAI_Admin', 'boot' ) ) ) {
			FZWAI_Admin::boot();
		}

		// Aviso se faltar SQLite.
		if ( is_admin() && get_option( 'fzwai_sqlite_missing' ) ) {
			add_action( 'admin_notices', function () {
				echo '<div class="notice notice-error"><p><strong>FZ WordPress AI:</strong> '
					. esc_html__( 'A extensão PDO SQLite não está disponível neste servidor. O plugin precisa dela para armazenar protocolos e a base de conhecimento.', 'fzwordpress-ai' )
					. '</p></div>';
			} );
		}
	}
}
