<?php
/**
 * Adaptadores de LLM. Três backends, mesma interface:
 *   - ollama    : servidor Ollama (/api/chat, /api/embeddings)
 *   - llamacpp  : binário llama.cpp local (llama-cli) embarcado
 *   - openai    : endpoint compatível com OpenAI (chat/completions) — modelos online
 *
 * @package FZWordPressAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FZWAI_LLM {

	/**
	 * Gera uma resposta de chat.
	 *
	 * @param array  $messages [['role'=>'system|user|assistant','content'=>...], ...]
	 * @param array  $opts      temperature, max_tokens
	 * @return array ['ok'=>bool, 'text'=>string, 'error'=>string, 'backend'=>string]
	 */
	public static function chat( array $messages, array $opts = array() ) {
		$s        = FZWAI_Settings::all();
		$backend  = $s['backend'];
		$temp     = isset( $opts['temperature'] ) ? (float) $opts['temperature'] : (float) $s['temperature'];
		$maxtok   = isset( $opts['max_tokens'] ) ? (int) $opts['max_tokens'] : (int) $s['max_tokens'];

		switch ( $backend ) {
			case 'llamacpp':
				return self::chat_llamacpp( $messages, $temp, $maxtok, $s );
			case 'openai':
				return self::chat_openai( $messages, $temp, $maxtok, $s );
			case 'ollama':
			default:
				return self::chat_ollama( $messages, $temp, $maxtok, $s );
		}
	}

	// ------------------------------------------------------------------ Ollama
	private static function chat_ollama( $messages, $temp, $maxtok, $s ) {
		$url  = trailingslashit( $s['ollama_url'] ) . 'api/chat';
		$options = array(
			'temperature' => $temp,
			'num_predict' => $maxtok,
		);
		// Força CPU quando configurado (ex.: GPU ocupada por outro modelo).
		if ( isset( $s['ollama_num_gpu'] ) && '' !== $s['ollama_num_gpu'] && is_numeric( $s['ollama_num_gpu'] ) ) {
			$options['num_gpu'] = (int) $s['ollama_num_gpu'];
		}
		$body = array(
			'model'    => $s['ollama_model'],
			'messages' => $messages,
			'stream'   => false,
			'options'  => $options,
		);
		$resp = self::http_json( $url, $body, 120 );
		if ( ! $resp['ok'] ) {
			return array( 'ok' => false, 'text' => '', 'error' => $resp['error'], 'backend' => 'ollama' );
		}
		$text = isset( $resp['json']['message']['content'] ) ? $resp['json']['message']['content'] : '';
		return array( 'ok' => $text !== '', 'text' => trim( $text ), 'error' => $text === '' ? 'resposta vazia' : '', 'backend' => 'ollama' );
	}

	// ---------------------------------------------------------------- llama.cpp
	private static function chat_llamacpp( $messages, $temp, $maxtok, $s ) {
		$bin   = $s['llamacpp_bin'];
		$model = $s['llamacpp_model'];
		if ( $bin === '' || ! is_file( $bin ) || $model === '' || ! is_file( $model ) ) {
			return array( 'ok' => false, 'text' => '', 'error' => 'llama.cpp não configurado (binário/modelo ausente)', 'backend' => 'llamacpp' );
		}
		if ( ! function_exists( 'proc_open' ) ) {
			return array( 'ok' => false, 'text' => '', 'error' => 'proc_open desabilitado no PHP', 'backend' => 'llamacpp' );
		}
		// Monta um prompt simples estilo chat (compatível com a maioria dos GGUF instruct).
		$prompt = '';
		foreach ( $messages as $m ) {
			$role = $m['role'] === 'assistant' ? 'Assistente' : ( $m['role'] === 'system' ? 'Sistema' : 'Usuário' );
			$prompt .= $role . ': ' . $m['content'] . "\n";
		}
		$prompt .= "Assistente:";

		// -no-cnv = geração única (sem modo conversa). O llama-cli ecoa o prompt
		// antes da resposta; removemos esse eco em PHP (robusto a mudanças de flag).
		$cmd = escapeshellarg( $bin )
			. ' -m ' . escapeshellarg( $model )
			. ' -p ' . escapeshellarg( $prompt )
			. ' -n ' . (int) $maxtok
			. ' --temp ' . escapeshellarg( (string) $temp )
			. ' -no-cnv 2>/dev/null';

		$out = self::run( $cmd, 150 );
		if ( $out === null ) {
			return array( 'ok' => false, 'text' => '', 'error' => 'falha ao executar llama.cpp', 'backend' => 'llamacpp' );
		}
		$out = (string) $out;
		// Remove o eco do prompt, se presente no início da saída.
		$pos = strpos( $out, $prompt );
		if ( 0 === $pos ) {
			$out = substr( $out, strlen( $prompt ) );
		} else {
			// Alguns builds ecoam só o texto do prompt sem o rótulo final; corta após "Assistente:".
			$marker = strrpos( $out, 'Assistente:' );
			if ( false !== $marker ) {
				$out = substr( $out, $marker + strlen( 'Assistente:' ) );
			}
		}
		$text = trim( $out );
		return array( 'ok' => $text !== '', 'text' => $text, 'error' => $text === '' ? 'resposta vazia' : '', 'backend' => 'llamacpp' );
	}

	// ------------------------------------------------------- OpenAI-compatível
	private static function chat_openai( $messages, $temp, $maxtok, $s ) {
		$url  = rtrim( $s['openai_base'], '/' ) . '/chat/completions';
		$body = array(
			'model'       => $s['openai_model'],
			'messages'    => $messages,
			'temperature' => $temp,
			'max_tokens'  => $maxtok,
			'stream'      => false,
		);
		$headers = array();
		if ( ! empty( $s['openai_key'] ) ) {
			$headers['Authorization'] = 'Bearer ' . $s['openai_key'];
		}
		$resp = self::http_json( $url, $body, 120, $headers );
		if ( ! $resp['ok'] ) {
			return array( 'ok' => false, 'text' => '', 'error' => $resp['error'], 'backend' => 'openai' );
		}
		$text = isset( $resp['json']['choices'][0]['message']['content'] ) ? $resp['json']['choices'][0]['message']['content'] : '';
		return array( 'ok' => $text !== '', 'text' => trim( $text ), 'error' => $text === '' ? 'resposta vazia' : '', 'backend' => 'openai' );
	}

	// -------------------------------------------------------------- utilidades
	/**
	 * POST JSON via wp_remote_post. Retorna ['ok','json','error','code'].
	 */
	public static function http_json( $url, array $body, $timeout = 60, array $headers = array() ) {
		$args = array(
			'timeout' => $timeout,
			'headers' => array_merge( array( 'Content-Type' => 'application/json' ), $headers ),
			'body'    => wp_json_encode( $body ),
		);
		$resp = wp_remote_post( $url, $args );
		if ( is_wp_error( $resp ) ) {
			return array( 'ok' => false, 'json' => null, 'error' => $resp->get_error_message(), 'code' => 0 );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$json = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = isset( $json['error'] ) ? ( is_array( $json['error'] ) ? wp_json_encode( $json['error'] ) : $json['error'] ) : ( 'HTTP ' . $code );
			return array( 'ok' => false, 'json' => $json, 'error' => $msg, 'code' => $code );
		}
		return array( 'ok' => true, 'json' => $json, 'error' => '', 'code' => $code );
	}

	/**
	 * Executa um comando local com timeout. Retorna stdout ou null.
	 */
	public static function run( $cmd, $timeout = 60 ) {
		$descriptors = array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
		$proc = @proc_open( $cmd, $descriptors, $pipes );
		if ( ! is_resource( $proc ) ) {
			return null;
		}
		stream_set_blocking( $pipes[1], false );
		$output = '';
		$start  = time();
		while ( true ) {
			$status = proc_get_status( $proc );
			$output .= stream_get_contents( $pipes[1] );
			if ( ! $status['running'] ) {
				break;
			}
			if ( ( time() - $start ) > $timeout ) {
				proc_terminate( $proc, 9 );
				break;
			}
			usleep( 100000 );
		}
		foreach ( $pipes as $p ) {
			@fclose( $p );
		}
		proc_close( $proc );
		return $output;
	}

	/**
	 * Teste de conectividade do backend ativo. Retorna ['ok','detail'].
	 */
	public static function ping() {
		$s = FZWAI_Settings::all();
		if ( 'ollama' === $s['backend'] ) {
			$resp = wp_remote_get( trailingslashit( $s['ollama_url'] ) . 'api/tags', array( 'timeout' => 10 ) );
			if ( is_wp_error( $resp ) ) {
				return array( 'ok' => false, 'detail' => $resp->get_error_message() );
			}
			$json   = json_decode( wp_remote_retrieve_body( $resp ), true );
			$models = isset( $json['models'] ) ? wp_list_pluck( $json['models'], 'name' ) : array();
			return array( 'ok' => true, 'detail' => count( $models ) . ' modelos: ' . implode( ', ', array_slice( $models, 0, 8 ) ) );
		}
		if ( 'llamacpp' === $s['backend'] ) {
			$ok = $s['llamacpp_bin'] && is_file( $s['llamacpp_bin'] ) && $s['llamacpp_model'] && is_file( $s['llamacpp_model'] );
			return array( 'ok' => $ok, 'detail' => $ok ? 'binário e modelo encontrados' : 'binário/modelo ausente' );
		}
		$r = self::chat( array( array( 'role' => 'user', 'content' => 'ping' ) ), array( 'max_tokens' => 5 ) );
		return array( 'ok' => $r['ok'], 'detail' => $r['ok'] ? 'ok' : $r['error'] );
	}
}
