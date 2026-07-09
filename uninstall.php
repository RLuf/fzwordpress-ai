<?php
/**
 * Desinstalação limpa do FZ WordPress AI.
 *
 * Remove opções e (opcionalmente) o diretório de dados com o SQLite.
 * Para preservar os dados na desinstalação, defina a option `fzwai_keep_data`.
 *
 * @package FZWordPressAI
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove opções do plugin.
delete_option( 'fzwai_settings' );
delete_option( 'fzwai_sqlite_missing' );
delete_option( 'fzwai_db_name' );

// Limpa evento agendado de reindexação, se houver.
$ts = wp_next_scheduled( 'fzwai_reindex_event' );
if ( $ts ) {
	wp_unschedule_event( $ts, 'fzwai_reindex_event' );
}

// Preserva dados se o operador pediu.
if ( get_option( 'fzwai_keep_data' ) ) {
	delete_option( 'fzwai_keep_data' );
	return;
}
delete_option( 'fzwai_keep_data' );

// Remove o diretório de dados — SOMENTE dentro de uploads, com verificação de realpath.
$upload = wp_upload_dir( null, false );
if ( empty( $upload['basedir'] ) ) {
	return;
}
$base = realpath( $upload['basedir'] );
$data = realpath( trailingslashit( $upload['basedir'] ) . 'fzwai-data' );

if ( false === $base || false === $data ) {
	return;
}
// Garante que $data está estritamente dentro de $base (anti path-traversal).
if ( 0 !== strpos( $data, $base . DIRECTORY_SEPARATOR ) ) {
	return;
}

fzwai_uninstall_rrmdir( $data, $base );

/**
 * Remove recursivamente $dir, nunca escapando de $guardRoot.
 *
 * @param string $dir       Diretório a remover.
 * @param string $guardRoot Raiz permitida (uploads).
 */
function fzwai_uninstall_rrmdir( $dir, $guardRoot ) {
	$real = realpath( $dir );
	if ( false === $real || 0 !== strpos( $real, $guardRoot . DIRECTORY_SEPARATOR ) ) {
		return;
	}
	if ( ! is_dir( $real ) ) {
		@unlink( $real );
		return;
	}
	$items = scandir( $real );
	if ( false === $items ) {
		return;
	}
	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$path = $real . DIRECTORY_SEPARATOR . $item;
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			fzwai_uninstall_rrmdir( $path, $guardRoot );
		} else {
			@unlink( $path );
		}
	}
	@rmdir( $real );
}
