<?php
/**
 * Geração de embeddings (vetores) para a busca semântica do RAG.
 *
 * Suporta dois backends de vetores:
 *   - ollama : POST {ollama_url}/api/embeddings  {model, prompt} -> json.embedding
 *   - openai : POST {openai_base}/embeddings     {model, input}  -> json.data[0].embedding
 *
 * Para o backend llamacpp (ou quando o serviço está indisponível/falha), retorna
 * null de forma silenciosa — o RAG cai automaticamente no ranqueamento léxico.
 * Nunca lança exceção para fora.
 *
 * @package FZWordPressAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FZWAI_Embeddings {

	/** Modelo de embedding padrão para endpoints compatíveis com OpenAI. */
	const OPENAI_DEFAULT_MODEL = 'text-embedding-3-small';

	/**
	 * Gera o vetor de embedding de um texto.
	 *
	 * @param string $text Texto a vetorizar.
	 * @return array<int,float>|null Vetor de floats, ou null em falha/indisponibilidade.
	 */
	public static function embed( $text ) {
		$text = is_string( $text ) ? trim( $text ) : '';
		if ( '' === $text ) {
			return null;
		}

		$s       = FZWAI_Settings::all();
		$backend = isset( $s['backend'] ) ? $s['backend'] : 'ollama';

		try {
			if ( 'ollama' === $backend ) {
				return self::embed_ollama( $text, $s );
			}
			if ( 'openai' === $backend ) {
				return self::embed_openai( $text, $s );
			}
		} catch ( Throwable $e ) {
			// Qualquer erro inesperado vira fallback léxico.
			return null;
		}

		// llamacpp ou backend desconhecido: sem vetores.
		return null;
	}

	/**
	 * Embedding via Ollama.
	 *
	 * @param string $text
	 * @param array  $s Configurações.
	 * @return array<int,float>|null
	 */
	private static function embed_ollama( $text, array $s ) {
		$base = isset( $s['ollama_url'] ) ? trim( (string) $s['ollama_url'] ) : '';
		if ( '' === $base ) {
			return null;
		}
		$model = ! empty( $s['embed_model'] )
			? $s['embed_model']
			: ( isset( $s['ollama_model'] ) ? $s['ollama_model'] : '' );
		if ( '' === trim( (string) $model ) ) {
			return null;
		}

		$url  = trailingslashit( $base ) . 'api/embeddings';
		$body = array(
			'model'  => $model,
			'prompt' => $text,
		);

		$resp = FZWAI_LLM::http_json( $url, $body, 60 );
		if ( empty( $resp['ok'] ) || ! isset( $resp['json']['embedding'] ) || ! is_array( $resp['json']['embedding'] ) ) {
			return null;
		}
		return self::sanitize_vector( $resp['json']['embedding'] );
	}

	/**
	 * Embedding via endpoint compatível com OpenAI.
	 *
	 * @param string $text
	 * @param array  $s Configurações.
	 * @return array<int,float>|null
	 */
	private static function embed_openai( $text, array $s ) {
		$key = isset( $s['openai_key'] ) ? trim( (string) $s['openai_key'] ) : '';
		if ( '' === $key ) {
			return null;
		}
		$base  = ! empty( $s['openai_base'] ) ? $s['openai_base'] : 'https://api.openai.com/v1';
		$model = ! empty( $s['embed_model'] ) ? $s['embed_model'] : self::OPENAI_DEFAULT_MODEL;

		$url     = rtrim( (string) $base, '/' ) . '/embeddings';
		$body    = array(
			'model' => $model,
			'input' => $text,
		);
		$headers = array( 'Authorization' => 'Bearer ' . $key );

		$resp = FZWAI_LLM::http_json( $url, $body, 60, $headers );
		if ( empty( $resp['ok'] ) || ! isset( $resp['json']['data'][0]['embedding'] ) || ! is_array( $resp['json']['data'][0]['embedding'] ) ) {
			return null;
		}
		return self::sanitize_vector( $resp['json']['data'][0]['embedding'] );
	}

	/**
	 * Converte um array bruto em vetor de floats. Retorna null se nada aproveitável.
	 *
	 * @param array $raw
	 * @return array<int,float>|null
	 */
	private static function sanitize_vector( array $raw ) {
		$vec = array();
		foreach ( $raw as $v ) {
			if ( is_int( $v ) || is_float( $v ) || ( is_string( $v ) && is_numeric( $v ) ) ) {
				$vec[] = (float) $v;
			}
		}
		return empty( $vec ) ? null : $vec;
	}

	/**
	 * Similaridade de cosseno entre dois vetores.
	 *
	 * Segura contra vetores nulos/vazios e dimensões incompatíveis: nesses casos
	 * retorna 0.0 em vez de lançar ou produzir NaN. O intervalo normal é [-1, 1].
	 *
	 * @param array $a
	 * @param array $b
	 * @return float
	 */
	public static function cosine( array $a, array $b ) {
		$len = count( $a );
		// Dimensões diferentes normalmente indicam modelos diferentes: sem comparação.
		if ( $len === 0 || $len !== count( $b ) ) {
			return 0.0;
		}

		$dot = 0.0;
		$na  = 0.0;
		$nb  = 0.0;

		// Reindexa para acesso posicional seguro.
		$a = array_values( $a );
		$b = array_values( $b );

		for ( $i = 0; $i < $len; $i++ ) {
			$va   = (float) $a[ $i ];
			$vb   = (float) $b[ $i ];
			$dot += $va * $vb;
			$na  += $va * $va;
			$nb  += $vb * $vb;
		}

		if ( $na <= 0.0 || $nb <= 0.0 ) {
			return 0.0; // Um dos vetores é nulo.
		}

		$denom = sqrt( $na ) * sqrt( $nb );
		if ( $denom <= 0.0 ) {
			return 0.0;
		}
		return $dot / $denom;
	}

	/**
	 * Há um backend de vetores utilizável (Ollama ou OpenAI configurado)?
	 *
	 * Não faz chamada de rede: verifica apenas a configuração. Se o serviço estiver
	 * fora do ar, embed() retornará null e o RAG usa o fallback léxico.
	 *
	 * @return bool
	 */
	public static function available() {
		$s       = FZWAI_Settings::all();
		$backend = isset( $s['backend'] ) ? $s['backend'] : '';

		if ( 'ollama' === $backend ) {
			$url   = isset( $s['ollama_url'] ) ? trim( (string) $s['ollama_url'] ) : '';
			$model = ! empty( $s['embed_model'] )
				? $s['embed_model']
				: ( isset( $s['ollama_model'] ) ? $s['ollama_model'] : '' );
			return '' !== $url && '' !== trim( (string) $model );
		}

		if ( 'openai' === $backend ) {
			return '' !== ( isset( $s['openai_key'] ) ? trim( (string) $s['openai_key'] ) : '' );
		}

		// llamacpp não expõe embeddings neste plugin.
		return false;
	}
}
