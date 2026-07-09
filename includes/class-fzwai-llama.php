<?php
/**
 * FZWAI_Llama — camada de provisionamento e diagnóstico do backend llama.cpp.
 *
 * Descobre se existe um binário `llama-cli` utilizável, lista os modelos .gguf
 * disponíveis e — somente quando o administrador pedir explicitamente — baixa e
 * extrai um pacote pré-compilado publicado nas Releases do repositório do plugin.
 *
 * Nada aqui roda automaticamente no carregamento da página: os métodos que fazem
 * rede ou execução de processo só são chamados quando a interface administrativa
 * os aciona. Todo caminho é validado para permanecer dentro das pastas do plugin.
 *
 * @package FZWordPressAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FZWAI_Llama {

	/** Repositório GitHub do plugin (owner/repo) — origem das Releases. */
	const REPO = 'RLuf/fzwordpress-ai';

	/** Nome do binário CLI do llama.cpp. */
	const BIN_NAME = 'llama-cli';

	// --------------------------------------------------------------- caminhos

	/**
	 * Diretório onde o installer deposita o binário embarcado (bin/dist).
	 *
	 * @return string
	 */
	public static function dist_dir() {
		return FZWAI_DIR . 'bin/dist';
	}

	/**
	 * Caminho do binário embarcado (pode ainda não existir).
	 *
	 * @return string
	 */
	public static function bundled_bin() {
		return self::dist_dir() . '/' . self::BIN_NAME;
	}

	/**
	 * Diretório de modelos .gguf gerido pelo plugin.
	 *
	 * @return string
	 */
	public static function models_dir() {
		return trailingslashit( FZWAI_DATA_DIR ) . 'models';
	}

	// -------------------------------------------------------------- OS / arch

	/**
	 * OS/arch normalizados para o padrão de nome dos artefatos de Release.
	 *
	 * @return array{os:string,arch:string}
	 */
	public static function os_arch() {
		$s = strtolower( (string) php_uname( 's' ) );
		$m = strtolower( (string) php_uname( 'm' ) );

		if ( false !== strpos( $s, 'darwin' ) ) {
			$os = 'macos';
		} elseif ( false !== strpos( $s, 'linux' ) ) {
			$os = 'linux';
		} else {
			$os = preg_replace( '/[^a-z0-9]+/', '', $s );
		}

		if ( 'x86_64' === $m || 'amd64' === $m ) {
			$arch = 'x86_64';
		} elseif ( 'arm64' === $m || 'aarch64' === $m ) {
			$arch = 'arm64';
		} else {
			$arch = preg_replace( '/[^a-z0-9_]+/', '', $m );
		}

		return array( 'os' => $os, 'arch' => $arch );
	}

	/**
	 * Nome esperado do tarball de Release para este OS/arch e uma dada tag.
	 *
	 * @param string $tag Tag da release (ex.: v1.0).
	 * @return string
	 */
	public static function asset_name( $tag ) {
		$oa  = self::os_arch();
		$tag = self::sanitize_tag( $tag );
		return sprintf( '%s-%s-%s-%s.tar.gz', self::BIN_NAME, $oa['os'], $oa['arch'], $tag );
	}

	// ----------------------------------------------------------------- detect

	/**
	 * Situação do backend llama.cpp: binário, execução e modelos.
	 *
	 * Estrutura pensada para alimentar a página administrativa. Nunca é fatal.
	 *
	 * @return array
	 */
	public static function detect() {
		$settings_bin   = (string) FZWAI_Settings::get( 'llamacpp_bin', '' );
		$settings_model = (string) FZWAI_Settings::get( 'llamacpp_model', '' );
		$bundled        = self::bundled_bin();

		// Ordem de preferência: binário configurado, depois o embarcado.
		$candidates = array();
		if ( '' !== $settings_bin ) {
			$candidates[] = array( 'path' => $settings_bin, 'source' => 'configured' );
		}
		$candidates[] = array( 'path' => $bundled, 'source' => 'bundled' );

		$checked        = array();
		$active         = '';
		$active_source  = 'none';
		$active_version = '';
		$active_runs    = false;

		foreach ( $candidates as $c ) {
			$path   = $c['path'];
			$exists = ( '' !== $path && @is_file( $path ) );
			$info   = array(
				'path'    => $path,
				'source'  => $c['source'],
				'exists'  => $exists,
				'runs'    => false,
				'version' => '',
			);
			if ( $exists ) {
				$probe           = self::probe_version( $path );
				$info['runs']    = $probe['runs'];
				$info['version'] = $probe['version'];
				if ( $probe['runs'] && '' === $active ) {
					$active         = $path;
					$active_source  = $c['source'];
					$active_version = $probe['version'];
					$active_runs    = true;
				}
			}
			$checked[] = $info;
		}

		// Se nada "roda", ainda reporta o primeiro binário existente (diagnóstico).
		if ( '' === $active ) {
			foreach ( $checked as $info ) {
				if ( $info['exists'] ) {
					$active         = $info['path'];
					$active_source  = $info['source'];
					$active_version = $info['version'];
					$active_runs    = $info['runs'];
					break;
				}
			}
		}

		$models = self::list_models();
		$oa     = self::os_arch();

		return array(
			'os'               => $oa['os'],
			'arch'             => $oa['arch'],
			'proc_open'        => function_exists( 'proc_open' ),
			'bin'              => $active,
			'bin_source'       => $active_source,
			'runs'             => $active_runs,
			'version'          => $active_version,
			'candidates'       => $checked,
			'dist_dir'         => self::dist_dir(),
			'models_dir'       => self::models_dir(),
			'models'           => $models,
			'configured_bin'   => $settings_bin,
			'configured_model' => $settings_model,
			// "Pronto" = binário executa E existe ao menos um modelo .gguf.
			'ready'            => ( $active_runs && ! empty( $models ) ),
		);
	}

	/**
	 * Executa `<bin> --version` de forma segura e detecta se rodou de fato.
	 *
	 * @param string $bin Caminho do binário.
	 * @return array{runs:bool,version:string}
	 */
	private static function probe_version( $bin ) {
		$out = array( 'runs' => false, 'version' => '' );

		if ( ! function_exists( 'proc_open' ) ) {
			return $out; // Sem proc_open não há como executar o binário.
		}
		if ( '' === (string) $bin || ! @is_file( $bin ) ) {
			return $out;
		}

		// 2>&1 é obrigatório: o llama-cli imprime a versão em stderr e o
		// FZWAI_LLM::run() só lê stdout.
		$cmd = escapeshellarg( $bin ) . ' --version 2>&1';
		$res = FZWAI_LLM::run( $cmd, 10 );

		if ( ! is_string( $res ) || '' === trim( $res ) ) {
			return $out;
		}

		// Formato típico: "version: 4589 (abc1234)".
		if ( preg_match( '/version[:\s]+([^\r\n]+)/i', $res, $m ) ) {
			$out['runs']    = true;
			$out['version'] = 'version: ' . trim( $m[1] );
		} elseif ( false !== stripos( $res, 'llama' )
			&& false === stripos( $res, 'denied' )
			&& false === stripos( $res, 'not found' )
			&& false === stripos( $res, 'no such file' ) ) {
			$out['runs']    = true;
			$out['version'] = trim( substr( $res, 0, 120 ) );
		}

		return $out;
	}

	/**
	 * Lista os modelos .gguf no diretório de dados e no bin/dist/models.
	 *
	 * @return array Lista de array{name,path,size,size_h}.
	 */
	public static function list_models() {
		$dirs = array( self::models_dir(), self::dist_dir() . '/models' );
		$seen = array();
		$list = array();

		foreach ( $dirs as $dir ) {
			$pattern = trailingslashit( $dir ) . '*.gguf';
			$found   = glob( $pattern, GLOB_NOSORT );
			if ( ! is_array( $found ) ) {
				continue;
			}
			foreach ( $found as $f ) {
				$real = realpath( $f );
				$key  = $real ? $real : $f;
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$size         = @filesize( $f );
				$list[]       = array(
					'name'   => basename( $f ),
					'path'   => $f,
					'size'   => ( false !== $size ) ? (int) $size : 0,
					'size_h' => ( false !== $size && function_exists( 'size_format' ) ) ? size_format( $size ) : '',
				);
			}
		}

		return $list;
	}

	// -------------------------------------------------------- URLs / hints

	/**
	 * URL da página de Releases do plugin (para o admin abrir no navegador).
	 *
	 * @param string $version 'latest' ou uma tag (ex.: v1.0).
	 * @return string
	 */
	public static function suggested_release_url( $version = 'latest' ) {
		$base = 'https://github.com/' . self::REPO . '/releases';
		if ( '' === (string) $version || 'latest' === strtolower( (string) $version ) ) {
			return $base . '/latest';
		}
		return $base . '/tag/' . rawurlencode( self::sanitize_tag( $version ) );
	}

	/**
	 * Comandos git exatos para disparar o build no GitHub Actions.
	 *
	 * @param string $version Tag desejada (ex.: v1.0).
	 * @return string
	 */
	public static function trigger_build_hint( $version ) {
		$v = self::sanitize_tag( $version );
		if ( '' === $v ) {
			$v = 'v1.0';
		}
		$lines = array(
			'# Dispara o workflow de compilacao (GitHub Actions) para a versao ' . $v . ':',
			'git tag ' . $v,
			'git push origin ' . $v,
			'',
			'# Alternativa: aba Actions -> "build-llama" -> Run workflow (workflow_dispatch).',
			'# Depois: bin/install.sh --llama -v ' . $v,
		);
		return implode( "\n", $lines );
	}

	/**
	 * Higieniza uma tag/versão (permite letras, dígitos, ponto, _ e -).
	 *
	 * @param string $tag Valor bruto.
	 * @return string
	 */
	public static function sanitize_tag( $tag ) {
		return preg_replace( '/[^A-Za-z0-9._\-]/', '', (string) $tag );
	}

	// ------------------------------------------------------- download release

	/**
	 * Baixa e extrai o pacote pré-compilado correspondente a este OS/arch.
	 *
	 * Fluxo defensivo (nunca fatal):
	 *   1. valida o diretório de destino (deve ficar sob o plugin/dados);
	 *   2. resolve a tag (latest via API do GitHub);
	 *   3. baixa o tarball via WP HTTP API (wp_remote_get, streaming);
	 *   4. verifica o sha256 se houver SHA256SUMS ou <asset>.sha256;
	 *   5. extrai (PharData nativo; fallback: tar do sistema);
	 *   6. confirma execução com --version.
	 *
	 * @param string $version  'latest' ou uma tag (ex.: v1.0).
	 * @param string $dest_dir Diretório de destino (ex.: FZWAI_DIR.'bin/dist').
	 * @return array{ok:bool,bin:string,error:string,tag:string,verified:bool}
	 */
	public static function download_release( $version, $dest_dir ) {
		$result = array( 'ok' => false, 'bin' => '', 'error' => '', 'tag' => '', 'verified' => false );

		// 1) valida o destino.
		$dest_dir = self::safe_dir( $dest_dir );
		if ( '' === $dest_dir ) {
			$result['error'] = 'diretorio de destino invalido (fora das pastas do plugin)';
			return $result;
		}
		if ( ! wp_mkdir_p( $dest_dir ) ) {
			$result['error'] = 'nao foi possivel criar o diretorio de destino';
			return $result;
		}

		// 2) resolve a tag.
		$tag = self::sanitize_tag( $version );
		if ( '' === $tag || 'latest' === strtolower( (string) $version ) ) {
			$tag = self::resolve_latest_tag();
			if ( '' === $tag ) {
				$result['error'] = 'nao foi possivel resolver a ultima release (rede/API do GitHub)';
				return $result;
			}
		}
		$result['tag'] = $tag;

		$asset     = self::asset_name( $tag );
		$base      = 'https://github.com/' . self::REPO . '/releases/download/' . rawurlencode( $tag );
		$asset_url = $base . '/' . $asset;

		// 3) baixa o tarball para arquivo temporário dentro do destino.
		$tmp = trailingslashit( $dest_dir ) . $asset . '.part';
		$dl  = self::http_download( $asset_url, $tmp );
		if ( ! $dl['ok'] ) {
			@unlink( $tmp );
			$result['error'] = 'falha ao baixar ' . $asset . ': ' . $dl['error'];
			return $result;
		}

		// 4) verifica sha256, se disponível.
		$verify = self::verify_checksum( $base, $asset, $tmp );
		if ( 'mismatch' === $verify ) {
			@unlink( $tmp );
			$result['error'] = 'checksum sha256 nao confere para ' . $asset;
			return $result;
		}
		$result['verified'] = ( 'ok' === $verify );

		// 5) extrai.
		$ex = self::extract_targz( $tmp, $dest_dir );
		@unlink( $tmp );
		if ( ! $ex['ok'] ) {
			$result['error'] = 'falha ao extrair o pacote: ' . $ex['error'];
			return $result;
		}

		$bin = trailingslashit( $dest_dir ) . self::BIN_NAME;
		if ( ! @is_file( $bin ) ) {
			$result['error'] = 'binario ' . self::BIN_NAME . ' nao encontrado dentro do pacote';
			return $result;
		}
		@chmod( $bin, 0755 );

		// 6) confirma execução.
		$probe         = self::probe_version( $bin );
		$result['bin'] = $bin;
		$result['ok']  = $probe['runs'];
		if ( ! $probe['runs'] ) {
			$result['error'] = function_exists( 'proc_open' )
				? 'binario extraido nao executou (--version). Verifique arquitetura/glibc; considere compilar localmente.'
				: 'proc_open desabilitado no PHP: nao ha como validar/usar o binario.';
		}

		return $result;
	}

	// --------------------------------------------------------------- helpers

	/**
	 * Resolve a tag da última release via API do GitHub. Vazio em falha.
	 *
	 * @return string
	 */
	private static function resolve_latest_tag() {
		$url  = 'https://api.github.com/repos/' . self::REPO . '/releases/latest';
		$resp = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'fzwordpress-ai',
				),
			)
		);
		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			return '';
		}
		$json = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( is_array( $json ) && ! empty( $json['tag_name'] ) ) {
			return self::sanitize_tag( $json['tag_name'] );
		}
		return '';
	}

	/**
	 * Baixa uma URL para arquivo local via WP HTTP API (streaming).
	 *
	 * @param string $url       URL a baixar.
	 * @param string $dest_file Caminho local de saída.
	 * @return array{ok:bool,error:string}
	 */
	private static function http_download( $url, $dest_file ) {
		$resp = wp_remote_get(
			$url,
			array(
				'timeout'  => 300,
				'stream'   => true,
				'filename' => $dest_file,
				'headers'  => array(
					'User-Agent' => 'fzwordpress-ai',
					'Accept'     => 'application/octet-stream',
				),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return array( 'ok' => false, 'error' => $resp->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( 200 !== $code ) {
			return array( 'ok' => false, 'error' => 'HTTP ' . $code );
		}
		if ( ! @is_file( $dest_file ) || (int) @filesize( $dest_file ) <= 0 ) {
			return array( 'ok' => false, 'error' => 'arquivo vazio' );
		}
		return array( 'ok' => true, 'error' => '' );
	}

	/**
	 * GET simples que devolve o corpo (string vazia em falha).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function http_get_body( $url ) {
		$resp = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array( 'User-Agent' => 'fzwordpress-ai' ),
			)
		);
		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			return '';
		}
		return (string) wp_remote_retrieve_body( $resp );
	}

	/**
	 * Verifica o sha256 do arquivo contra SHA256SUMS ou <asset>.sha256.
	 *
	 * @param string $base_url Base .../releases/download/<tag>.
	 * @param string $asset    Nome do tarball.
	 * @param string $file     Caminho local do tarball.
	 * @return string 'ok' | 'mismatch' | 'absent'
	 */
	private static function verify_checksum( $base_url, $asset, $file ) {
		$hash = @hash_file( 'sha256', $file );
		if ( ! $hash ) {
			return 'absent';
		}
		$hash     = strtolower( $hash );
		$expected = '';

		// (a) SHA256SUMS combinado.
		$sums = self::http_get_body( $base_url . '/SHA256SUMS' );
		if ( '' !== $sums ) {
			$expected = self::find_hash_in_sums( $sums, $asset );
		}
		// (b) arquivo por-asset <asset>.sha256.
		if ( '' === $expected ) {
			$single = self::http_get_body( $base_url . '/' . $asset . '.sha256' );
			if ( '' !== $single && preg_match( '/\b([a-f0-9]{64})\b/i', $single, $m ) ) {
				$expected = strtolower( $m[1] );
			}
		}

		if ( '' === $expected ) {
			return 'absent';
		}
		return hash_equals( $expected, $hash ) ? 'ok' : 'mismatch';
	}

	/**
	 * Procura o hash de um asset dentro de um texto estilo SHA256SUMS.
	 *
	 * @param string $sums  Conteúdo do arquivo de somas.
	 * @param string $asset Nome do asset a casar (basename).
	 * @return string Hash minúsculo, ou vazio.
	 */
	private static function find_hash_in_sums( $sums, $asset ) {
		$lines = preg_split( '/\r?\n/', $sums );
		foreach ( (array) $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			// Formato coreutils: "<hash>  <name>" (opcional '*' antes do nome).
			if ( preg_match( '/^([a-f0-9]{64})\s+\*?(.+)$/i', $line, $m ) ) {
				if ( basename( trim( $m[2] ) ) === $asset ) {
					return strtolower( $m[1] );
				}
			}
		}
		return '';
	}

	/**
	 * Extrai um .tar.gz usando PharData (nativo). Fallback: tar do sistema.
	 *
	 * @param string $tar_gz   Caminho do tarball.
	 * @param string $dest_dir Diretório de destino.
	 * @return array{ok:bool,error:string}
	 */
	private static function extract_targz( $tar_gz, $dest_dir ) {
		// Preferência: PharData — sem shell, sem download externo.
		if ( class_exists( 'PharData' ) ) {
			try {
				$phar = new PharData( $tar_gz );
				$phar->extractTo( $dest_dir, null, true );
				return array( 'ok' => true, 'error' => '' );
			} catch ( Exception $e ) {
				// Cai para o tar do sistema.
				$phar_err = $e->getMessage();
			}
		} else {
			$phar_err = 'PharData indisponivel';
		}

		// Fallback: tar local (arquivo já baixado; argumentos escapados).
		if ( function_exists( 'proc_open' ) ) {
			$cmd = 'tar -xzf ' . escapeshellarg( $tar_gz ) . ' -C ' . escapeshellarg( $dest_dir ) . ' 2>&1';
			$out = FZWAI_LLM::run( $cmd, 60 );
			$bin = trailingslashit( $dest_dir ) . self::BIN_NAME;
			if ( @is_file( $bin ) ) {
				return array( 'ok' => true, 'error' => '' );
			}
			return array( 'ok' => false, 'error' => 'tar: ' . ( is_string( $out ) ? trim( $out ) : 'sem saida' ) );
		}

		return array( 'ok' => false, 'error' => $phar_err . '; proc_open desabilitado' );
	}

	/**
	 * Garante que um diretório de destino fica sob FZWAI_DIR ou FZWAI_DATA_DIR.
	 * Resolve o ancestral existente (o destino pode ainda não existir).
	 *
	 * @param string $dir Diretório candidato.
	 * @return string O diretório original se válido; string vazia se recusado.
	 */
	private static function safe_dir( $dir ) {
		$dir = (string) $dir;
		if ( '' === $dir || false !== strpos( $dir, '..' ) ) {
			return '';
		}

		$allowed = array(
			rtrim( FZWAI_DIR, '/\\' ),
			rtrim( FZWAI_DATA_DIR, '/\\' ),
		);

		// Resolve o ancestral existente mais próximo (destino pode não existir).
		$probe = $dir;
		$real  = realpath( $probe );
		while ( false === $real && '' !== $probe && '.' !== $probe && $probe !== dirname( $probe ) ) {
			$probe = dirname( $probe );
			$real  = realpath( $probe );
		}
		if ( false === $real ) {
			return '';
		}

		foreach ( $allowed as $base ) {
			$rbase = realpath( $base );
			if ( false === $rbase ) {
				continue;
			}
			if ( $real === $rbase || 0 === strpos( $real, $rbase . DIRECTORY_SEPARATOR ) ) {
				return $dir;
			}
		}
		return '';
	}
}
