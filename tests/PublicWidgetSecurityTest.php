<?php
/**
 * Guardas estáticas da separação entre o widget público e o painel.
 *
 * @package FZWordPressAI
 */

$root = dirname( __DIR__ );
$fail = 0;

/**
 * Registra uma asserção sem depender de PHPUnit.
 *
 * @param bool   $condition Resultado esperado.
 * @param string $message   Descrição da guarda.
 * @return void
 */
function fzwai_test_assert( $condition, $message ) {
	global $fail;

	if ( $condition ) {
		echo 'PASS  ' . $message . PHP_EOL;
		return;
	}

	echo 'FAIL  ' . $message . PHP_EOL;
	$fail++;
}

/**
 * Lê um arquivo obrigatório do projeto.
 *
 * @param string $path Caminho absoluto.
 * @return string
 */
function fzwai_test_read( $path ) {
	$contents = file_get_contents( $path );
	if ( false === $contents ) {
		fwrite( STDERR, 'Não foi possível ler: ' . $path . PHP_EOL );
		exit( 2 );
	}
	return $contents;
}

$widget_js  = fzwai_test_read( $root . '/assets/js/widget.js' );
$widget_php = fzwai_test_read( $root . '/includes/class-fzwai-widget.php' );
$rest_php   = fzwai_test_read( $root . '/includes/class-fzwai-rest.php' );
$admin_php  = fzwai_test_read( $root . '/includes/class-fzwai-admin.php' );
$standalone = fzwai_test_read( $root . '/standalone/chat.php' );
$embed      = fzwai_test_read( $root . '/standalone/embed-snippet.html' );
$bootstrap  = fzwai_test_read( $root . '/fzwordpress-ai.php' );
$readme     = fzwai_test_read( $root . '/readme.txt' );

fzwai_test_assert(
	false === strpos( $widget_js, "'X-WP-Nonce':" )
	&& false === strpos( $widget_js, '"X-WP-Nonce":' ),
	'Widget público não envia X-WP-Nonce'
);
fzwai_test_assert( false === strpos( $widget_js, 'CFG.nonce' ), 'Widget público não depende de nonce localizado' );
fzwai_test_assert( 2 === substr_count( $widget_js, "credentials: 'omit'" ), 'Chat e suporte não enviam cookies de sessão' );
fzwai_test_assert( false === strpos( $widget_php, "wp_create_nonce( 'wp_rest' )" ), 'Objeto público não gera nonce REST' );
fzwai_test_assert( false === strpos( $standalone, 'X-WP-Nonce' ), 'Backend standalone não anuncia header de nonce' );
fzwai_test_assert( false === strpos( $embed, 'nonce:' ), 'Exemplo standalone não expõe campo nonce' );
fzwai_test_assert( false !== strpos( $rest_php, "'permission_callback' => array( __CLASS__, 'public_permission' )" ), 'Chat público preserva callback de rate limit' );
fzwai_test_assert( false !== strpos( $rest_php, "self::rl_allow( 'fzwai_rl_global', 300 )" ), 'Chat público preserva limite global' );
fzwai_test_assert( false !== strpos( $admin_php, "current_user_can( 'manage_options' )" ), 'Painel preserva verificação de capacidade' );
fzwai_test_assert( false !== strpos( $admin_php, 'check_ajax_referer' ), 'AJAX administrativo preserva nonce' );
fzwai_test_assert( false !== strpos( $bootstrap, "define( 'FZWAI_VERSION', '1.1.3' )" ), 'Código anuncia versão 1.1.3' );
fzwai_test_assert( false !== strpos( $readme, 'Stable tag: 1.1.3' ), 'readme anuncia versão estável 1.1.3' );

if ( $fail > 0 ) {
	echo $fail . ' TESTE(S) FALHARAM' . PHP_EOL;
	exit( 1 );
}

echo 'TODOS OS TESTES DE SEGURANÇA DO WIDGET PASSARAM' . PHP_EOL;
