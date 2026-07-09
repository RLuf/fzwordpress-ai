<?php
/**
 * Motor de RAG (Retrieval-Augmented Generation).
 *
 * Responsável por indexar fontes de conhecimento (URL, arquivo local no diretório
 * de uploads, ou texto puro) em pedaços ("chunks") com embeddings opcionais, e por
 * recuperar os trechos mais relevantes para uma pergunta — via similaridade de
 * cosseno quando há vetores, com fallback léxico (TF) sempre disponível.
 *
 * Toda a persistência usa PDO SQLite via FZWAI_DB com prepared statements.
 * As operações públicas jamais lançam exceção para fora.
 *
 * @package FZWordPressAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FZWAI_RAG {

	/** Tamanho-alvo do chunk em caracteres (~800 tokens ~ 3200 chars). */
	const CHUNK_CHARS = 3200;

	/** Sobreposição entre chunks, em caracteres. */
	const CHUNK_OVERLAP = 120;

	/** Aproximação de caracteres por token. */
	const CHARS_PER_TOKEN = 4;

	/** Similaridade mínima de cosseno para considerar o resultado vetorial "relevante". */
	const SIM_THRESHOLD = 0.15;

	/** Teto defensivo de chunks carregados por busca (memória). */
	const SEARCH_CAP = 5000;

	/** Tamanho máximo de arquivo local lido (bytes). */
	const MAX_FILE_BYTES = 8388608; // 8 MB

	// ---------------------------------------------------------------- Indexação

	/**
	 * Indexa (ou reindexa) uma fonte de conhecimento.
	 *
	 * @param int $source_id
	 * @return array{ok:bool,chunks:int,error:string}
	 */
	public static function ingest_source( $source_id ) {
		$source_id = (int) $source_id;
		$result    = array( 'ok' => false, 'chunks' => 0, 'error' => '' );

		try {
			$pdo = FZWAI_DB::instance()->pdo();

			// 1) Carrega a fonte.
			$stmt = $pdo->prepare( 'SELECT id, type, label, location FROM fzwai_sources WHERE id = :id' );
			$stmt->execute( array( ':id' => $source_id ) );
			$source = $stmt->fetch();
			if ( ! $source ) {
				$result['error'] = __( 'Fonte não encontrada.', 'fzwordpress-ai' );
				return $result;
			}

			// Marca como "em processamento" e limpa o erro anterior.
			$upd = $pdo->prepare( 'UPDATE fzwai_sources SET status = :st, last_error = :err WHERE id = :id' );
			$upd->execute( array( ':st' => 'pending', ':err' => '', ':id' => $source_id ) );

			// 2) Busca o conteúdo conforme o tipo.
			$type    = isset( $source['type'] ) ? (string) $source['type'] : 'text';
			$content = self::fetch_content( $type, (string) $source['location'] );
			if ( '' === trim( $content ) ) {
				throw new RuntimeException( __( 'Conteúdo vazio ou ilegível.', 'fzwordpress-ai' ) );
			}

			// 3) Divide em chunks.
			$chunks = self::chunk_text( $content );
			if ( empty( $chunks ) ) {
				throw new RuntimeException( __( 'Nenhum trecho gerado a partir do conteúdo.', 'fzwordpress-ai' ) );
			}

			// 4) Remove chunks antigos (reindexação segura) e insere os novos.
			$del = $pdo->prepare( 'DELETE FROM fzwai_chunks WHERE source_id = :id' );
			$del->execute( array( ':id' => $source_id ) );

			$embed_model = self::current_embed_model();
			$ins         = $pdo->prepare(
				'INSERT INTO fzwai_chunks (source_id, seq, content, tokens, embedding, embed_model, created_at)
				 VALUES (:sid, :seq, :content, :tokens, :embedding, :model, :created)'
			);

			$count = 0;
			foreach ( $chunks as $chunk ) {
				$chunk = trim( $chunk );
				if ( '' === $chunk ) {
					continue;
				}

				$vector         = FZWAI_Embeddings::embed( $chunk );
				$has_vec        = ( is_array( $vector ) && ! empty( $vector ) );
				$embedding_json = $has_vec ? wp_json_encode( $vector ) : null;

				$ins->execute(
					array(
						':sid'       => $source_id,
						':seq'       => $count,
						':content'   => $chunk,
						':tokens'    => self::approx_tokens( $chunk ),
						':embedding' => $embedding_json,
						':model'     => $has_vec ? $embed_model : '',
						':created'   => FZWAI_DB::now(),
					)
				);
				$count++;
			}

			// 5) Fecha a fonte como indexada.
			$fin = $pdo->prepare(
				'UPDATE fzwai_sources SET status = :st, last_error = :err, chunk_count = :cc, indexed_at = :at WHERE id = :id'
			);
			$fin->execute(
				array(
					':st'  => 'indexed',
					':err' => '',
					':cc'  => $count,
					':at'  => FZWAI_DB::now(),
					':id'  => $source_id,
				)
			);

			$result['ok']     = true;
			$result['chunks'] = $count;
			return $result;

		} catch ( Throwable $e ) {
			$result['error'] = $e->getMessage();
		}

		// Registra o erro na fonte (best-effort, sem propagar).
		self::mark_error( $source_id, $result['error'] );
		return $result;
	}

	/**
	 * Reindexa todas as fontes cadastradas.
	 *
	 * @return array{sources:int,ok:int,failed:int,chunks:int,errors:array}
	 */
	public static function reindex_all() {
		$summary = array(
			'sources' => 0,
			'ok'      => 0,
			'failed'  => 0,
			'chunks'  => 0,
			'errors'  => array(),
		);

		try {
			$pdo  = FZWAI_DB::instance()->pdo();
			$stmt = $pdo->query( 'SELECT id FROM fzwai_sources ORDER BY id ASC' );
			$ids  = $stmt ? $stmt->fetchAll( PDO::FETCH_COLUMN ) : array();
		} catch ( Throwable $e ) {
			$summary['errors'][] = $e->getMessage();
			return $summary;
		}

		foreach ( $ids as $id ) {
			$summary['sources']++;
			$res = self::ingest_source( (int) $id );
			if ( ! empty( $res['ok'] ) ) {
				$summary['ok']++;
				$summary['chunks'] += (int) $res['chunks'];
			} else {
				$summary['failed']++;
				if ( '' !== (string) $res['error'] ) {
					$summary['errors'][ (int) $id ] = (string) $res['error'];
				}
			}
		}

		return $summary;
	}

	// ------------------------------------------------------------------- Busca

	/**
	 * Recupera os trechos mais relevantes para uma consulta.
	 *
	 * @param string $query
	 * @param int    $k Número máximo de resultados.
	 * @return array<int,array{content:string,source_id:int,label:string,score:float}>
	 */
	public static function search( $query, $k = 4 ) {
		$query = is_string( $query ) ? trim( $query ) : '';
		$k     = max( 1, (int) $k );
		if ( '' === $query ) {
			return array();
		}

		try {
			$pdo = FZWAI_DB::instance()->pdo();
		} catch ( Throwable $e ) {
			return array();
		}

		// Vetor da consulta — só se há backend de vetores E ao menos um chunk vetorizado.
		$q_vec = null;
		if ( FZWAI_Embeddings::available() ) {
			$has_vectors = false;
			try {
				$has_vectors = ( (int) $pdo->query(
					'SELECT COUNT(*) FROM fzwai_chunks WHERE embedding IS NOT NULL AND embedding != ""'
				)->fetchColumn() ) > 0;
			} catch ( Throwable $e ) {
				$has_vectors = false;
			}
			if ( $has_vectors ) {
				$vec = FZWAI_Embeddings::embed( $query );
				if ( is_array( $vec ) && ! empty( $vec ) ) {
					$q_vec = $vec;
				}
			}
		}

		$q_tokens = self::tokenize( $query );
		if ( empty( $q_tokens ) && null === $q_vec ) {
			return array();
		}

		$vector_hits  = array();
		$lexical_hits = array();

		try {
			$stmt = $pdo->prepare(
				'SELECT c.id AS id, c.source_id AS source_id, c.content AS content, c.embedding AS embedding, s.label AS label
				 FROM fzwai_chunks c
				 LEFT JOIN fzwai_sources s ON s.id = c.source_id
				 ORDER BY c.id ASC
				 LIMIT :cap'
			);
			$stmt->bindValue( ':cap', self::SEARCH_CAP, PDO::PARAM_INT );
			$stmt->execute();
		} catch ( Throwable $e ) {
			return array();
		}

		// Percorre em streaming para manter a memória sob controle.
		while ( ( $row = $stmt->fetch() ) !== false ) {
			$content = isset( $row['content'] ) ? (string) $row['content'] : '';
			if ( '' === trim( $content ) ) {
				continue;
			}
			$cid   = (int) $row['id'];
			$sid   = (int) $row['source_id'];
			$label = isset( $row['label'] ) ? (string) $row['label'] : '';

			// Score léxico (sempre calculado).
			$lex = self::lexical_score( $q_tokens, $content );
			if ( $lex > 0.0 ) {
				$lexical_hits[ $cid ] = array(
					'content'   => $content,
					'source_id' => $sid,
					'label'     => $label,
					'score'     => $lex,
				);
			}

			// Score vetorial (quando há vetor de consulta e chunk vetorizado).
			if ( null !== $q_vec && ! empty( $row['embedding'] ) ) {
				$vec = json_decode( (string) $row['embedding'], true );
				if ( is_array( $vec ) && ! empty( $vec ) ) {
					$vector_hits[ $cid ] = array(
						'content'   => $content,
						'source_id' => $sid,
						'label'     => $label,
						'score'     => (float) FZWAI_Embeddings::cosine( $q_vec, $vec ),
					);
				}
			}
		}
		$stmt->closeCursor();

		return self::merge_hits( $vector_hits, $lexical_hits, $k );
	}

	/**
	 * Combina resultados vetoriais e léxicos.
	 *
	 * Prefere os scores vetoriais quando existe pelo menos um acima do limiar;
	 * completa vagas restantes (chunks sem vetor) com os melhores léxicos. Sem
	 * match vetorial relevante — ou sem vetores — usa apenas o ranking léxico.
	 * De-duplica por id do chunk e nunca devolve mais que $k itens.
	 *
	 * @param array $vector_hits  Mapa cid => hit.
	 * @param array $lexical_hits Mapa cid => hit.
	 * @param int   $k
	 * @return array<int,array{content:string,source_id:int,label:string,score:float}>
	 */
	private static function merge_hits( array $vector_hits, array $lexical_hits, $k ) {
		$vector_active = false;
		if ( ! empty( $vector_hits ) ) {
			$max_sim = 0.0;
			foreach ( $vector_hits as $hit ) {
				if ( $hit['score'] > $max_sim ) {
					$max_sim = $hit['score'];
				}
			}
			$vector_active = ( $max_sim >= self::SIM_THRESHOLD );
		}

		$merged = array();

		if ( $vector_active ) {
			// Camada 1: chunks com score vetorial, do maior cosseno ao menor.
			uasort( $vector_hits, array( __CLASS__, 'cmp_score_desc' ) );
			foreach ( $vector_hits as $cid => $hit ) {
				$merged[ $cid ] = $hit;
			}
			// Camada 2: completa com léxicos ainda não incluídos (chunks sem vetor).
			if ( count( $merged ) < $k && ! empty( $lexical_hits ) ) {
				uasort( $lexical_hits, array( __CLASS__, 'cmp_score_desc' ) );
				foreach ( $lexical_hits as $cid => $hit ) {
					if ( isset( $merged[ $cid ] ) ) {
						continue;
					}
					$merged[ $cid ] = $hit;
					if ( count( $merged ) >= $k ) {
						break;
					}
				}
			}
		} else {
			// Fallback léxico puro.
			uasort( $lexical_hits, array( __CLASS__, 'cmp_score_desc' ) );
			$merged = $lexical_hits;
		}

		return array_slice( array_values( $merged ), 0, $k );
	}

	/**
	 * Comparador desc por 'score' para uasort.
	 *
	 * @param array $a
	 * @param array $b
	 * @return int
	 */
	public static function cmp_score_desc( $a, $b ) {
		$sa = isset( $a['score'] ) ? (float) $a['score'] : 0.0;
		$sb = isset( $b['score'] ) ? (float) $b['score'] : 0.0;
		return $sb <=> $sa;
	}

	/**
	 * Monta uma string de contexto a partir dos resultados, com marcadores de fonte.
	 *
	 * @param array $results   Saída de search().
	 * @param int   $max_chars Limite de caracteres do contexto final.
	 * @return string
	 */
	public static function build_context( array $results, $max_chars = 3000 ) {
		$max_chars = (int) $max_chars;
		if ( $max_chars <= 0 || empty( $results ) ) {
			return '';
		}

		$parts = array();
		$used  = 0;

		foreach ( $results as $r ) {
			$content = isset( $r['content'] ) ? trim( (string) $r['content'] ) : '';
			if ( '' === $content ) {
				continue;
			}

			$label = ( isset( $r['label'] ) && '' !== trim( (string) $r['label'] ) )
				? trim( (string) $r['label'] )
				: __( 'Base de conhecimento', 'fzwordpress-ai' );

			/* translators: %s: rótulo (nome) da fonte de conhecimento. */
			$marker    = '[' . sprintf( __( 'Fonte: %s', 'fzwordpress-ai' ), $label ) . "]\n";
			$sep_cost  = ( $used > 0 ) ? 2 : 0; // "\n\n" entre blocos.
			$block     = $marker . $content;
			$block_len = self::clen( $block ) + $sep_cost;

			if ( $used + $block_len <= $max_chars ) {
				$parts[] = $block;
				$used   += $block_len;
				continue;
			}

			// Não coube inteiro: tenta truncar o conteúdo para preencher o restante.
			$remaining = $max_chars - $used - $sep_cost;
			$marker_len = self::clen( $marker );
			if ( $remaining <= $marker_len + 20 ) {
				break; // Sem espaço útil para um bloco novo.
			}
			$avail        = $remaining - $marker_len - 1; // -1 reserva o caractere "…".
			$content_trim = rtrim( self::csub( $content, 0, $avail ) );
			if ( '' === $content_trim ) {
				break;
			}
			$parts[] = $marker . $content_trim . '…';
			break;
		}

		return implode( "\n\n", $parts );
	}

	// ------------------------------------------------- Aquisição de conteúdo

	/**
	 * Obtém o texto de uma fonte conforme o tipo.
	 *
	 * @param string $type url|file|text
	 * @param string $location
	 * @return string
	 */
	private static function fetch_content( $type, $location ) {
		switch ( $type ) {
			case 'url':
				return self::fetch_url( $location );
			case 'file':
				return self::fetch_file( $location );
			case 'text':
			default:
				return self::normalize_ws( (string) $location );
		}
	}

	/**
	 * Baixa e limpa o texto de uma URL (sem crawling — só a URL informada).
	 *
	 * @param string $url
	 * @return string
	 * @throws RuntimeException
	 */
	private static function fetch_url( $url ) {
		$url = esc_url_raw( trim( (string) $url ) );
		if ( '' === $url ) {
			throw new RuntimeException( __( 'URL inválida.', 'fzwordpress-ai' ) );
		}

		$ua   = 'FZWordPressAI/' . ( defined( 'FZWAI_VERSION' ) ? FZWAI_VERSION : '1.0' ) . '; +' . home_url( '/' );
		$resp = wp_remote_get(
			$url,
			array(
				'timeout'     => 30,
				'redirection' => 3,
				'user-agent'  => $ua,
				'headers'     => array( 'Accept' => 'text/html,text/plain,application/xhtml+xml,*/*' ),
			)
		);
		if ( is_wp_error( $resp ) ) {
			throw new RuntimeException(
				sprintf( /* translators: %s: mensagem de erro HTTP. */ __( 'Falha ao buscar a URL: %s', 'fzwordpress-ai' ), $resp->get_error_message() )
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( $code < 200 || $code >= 300 ) {
			throw new RuntimeException(
				sprintf( /* translators: %d: código de status HTTP. */ __( 'A URL respondeu HTTP %d.', 'fzwordpress-ai' ), $code )
			);
		}
		return self::html_to_text( wp_remote_retrieve_body( $resp ) );
	}

	/**
	 * Lê um arquivo local — restrito ao diretório de uploads do WordPress.
	 *
	 * Bloqueia traversal validando que o realpath resolvido está dentro de
	 * wp_upload_dir()['basedir']. Aceita caminho absoluto ou relativo ao basedir.
	 *
	 * @param string $path
	 * @return string
	 * @throws RuntimeException
	 */
	private static function fetch_file( $path ) {
		$path = trim( (string) $path );
		if ( '' === $path ) {
			throw new RuntimeException( __( 'Caminho de arquivo vazio.', 'fzwordpress-ai' ) );
		}

		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			throw new RuntimeException( __( 'Diretório de uploads indisponível.', 'fzwordpress-ai' ) );
		}
		$basedir = realpath( $uploads['basedir'] );
		if ( false === $basedir ) {
			throw new RuntimeException( __( 'Diretório de uploads inválido.', 'fzwordpress-ai' ) );
		}

		// Resolve: caminho absoluto é usado direto; relativo é ancorado no basedir.
		$candidate = str_replace( '\\', '/', $path );
		if ( ! path_is_absolute( $candidate ) ) {
			$candidate = trailingslashit( $basedir ) . ltrim( $candidate, '/' );
		}
		$real = realpath( $candidate );
		if ( false === $real ) {
			throw new RuntimeException( __( 'Arquivo não encontrado.', 'fzwordpress-ai' ) );
		}

		// Precisa estar DENTRO do basedir (defesa contra path traversal).
		$basedir_n = trailingslashit( wp_normalize_path( $basedir ) );
		$real_n    = wp_normalize_path( $real );
		if ( 0 !== strpos( $real_n, $basedir_n ) ) {
			throw new RuntimeException( __( 'Acesso negado: arquivo fora do diretório de uploads.', 'fzwordpress-ai' ) );
		}
		if ( ! is_file( $real ) || ! is_readable( $real ) ) {
			throw new RuntimeException( __( 'Arquivo ilegível.', 'fzwordpress-ai' ) );
		}

		$size = filesize( $real );
		if ( false !== $size && $size > self::MAX_FILE_BYTES ) {
			throw new RuntimeException( __( 'Arquivo excede o limite de 8 MB.', 'fzwordpress-ai' ) );
		}

		$raw = file_get_contents( $real );
		if ( false === $raw ) {
			throw new RuntimeException( __( 'Falha ao ler o arquivo.', 'fzwordpress-ai' ) );
		}

		$ext = strtolower( pathinfo( $real, PATHINFO_EXTENSION ) );
		switch ( $ext ) {
			case 'html':
			case 'htm':
				return self::html_to_text( $raw );
			case 'txt':
			case 'md':
			case 'csv':
				return self::normalize_ws( $raw );
			default:
				// Outros tipos: aceita se for texto legível (rejeita binário).
				if ( self::looks_binary( $raw ) ) {
					throw new RuntimeException( __( 'Tipo de arquivo não suportado (conteúdo binário).', 'fzwordpress-ai' ) );
				}
				return self::normalize_ws( $raw );
		}
	}

	/**
	 * Converte HTML em texto legível: remove script/style, decodifica entidades e
	 * colapsa espaços, preservando quebras de parágrafo para o chunking.
	 *
	 * @param string $html
	 * @return string
	 */
	private static function html_to_text( $html ) {
		$html = (string) $html;
		if ( '' === $html ) {
			return '';
		}
		// Remove blocos não-textuais e seu conteúdo.
		$html = self::re_replace( '#<(script|style|noscript|template|svg)\b[^>]*>.*?</\1>#is', ' ', $html );
		// Tags de bloco viram quebras de linha (preserva parágrafos).
		$html = self::re_replace( '#<(br|/p|/div|/h[1-6]|/li|/tr|/table|/section|/article|/header|/footer)\s*>#i', "\n", $html );
		// Remove as tags restantes.
		$text = wp_strip_all_tags( $html );
		// Decodifica entidades HTML.
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return self::normalize_ws( $text );
	}

	/**
	 * Normaliza espaços em branco: unifica quebras, colapsa espaços/tabs e limita
	 * as linhas em branco a no máximo uma (mantendo parágrafos). Corrige UTF-8.
	 *
	 * @param string $text
	 * @return string
	 */
	private static function normalize_ws( $text ) {
		$text = (string) $text;
		if ( '' === $text ) {
			return '';
		}
		// Sanea UTF-8 inválido para não contaminar JSON/consultas posteriores.
		if ( function_exists( 'mb_convert_encoding' ) ) {
			$text = (string) mb_convert_encoding( $text, 'UTF-8', 'UTF-8' );
		}
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$text = self::re_replace( '/[ \t\x{00A0}]+/u', ' ', $text ); // espaços/tabs/nbsp -> espaço
		$text = self::re_replace( '/[ \t]*\n[ \t]*/', "\n", $text );  // apara ao redor das quebras
		$text = self::re_replace( '/\n{3,}/', "\n\n", $text );        // no máximo uma linha em branco
		return trim( $text );
	}

	/**
	 * Heurística simples: o conteúdo parece binário?
	 *
	 * @param string $raw
	 * @return bool
	 */
	private static function looks_binary( $raw ) {
		$sample = substr( (string) $raw, 0, 8000 );
		if ( '' === $sample ) {
			return false;
		}
		// A presença de byte NUL é forte indício de binário.
		return ( false !== strpos( $sample, "\0" ) );
	}

	// -------------------------------------------------------------- Chunking

	/**
	 * Divide o texto em chunks de ~CHUNK_CHARS com sobreposição, respeitando
	 * limites de parágrafo e frase sempre que possível.
	 *
	 * @param string $text
	 * @return array<int,string>
	 */
	private static function chunk_text( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return array();
		}

		$max   = self::CHUNK_CHARS;
		$units = self::split_units( $text, $max );

		$chunks  = array();
		$current = '';

		foreach ( $units as $unit ) {
			$unit = trim( $unit );
			if ( '' === $unit ) {
				continue;
			}
			if ( '' === $current ) {
				$current = $unit;
				continue;
			}
			if ( self::clen( $current ) + 2 + self::clen( $unit ) <= $max ) {
				$current .= "\n\n" . $unit;
			} else {
				$chunks[] = $current;
				// Novo chunk começa com a sobreposição do final do anterior.
				$tail    = self::tail_overlap( $current, self::CHUNK_OVERLAP );
				$current = ( '' !== $tail ) ? $tail . "\n\n" . $unit : $unit;
			}
		}
		if ( '' !== trim( $current ) ) {
			$chunks[] = $current;
		}

		// Filtra vazios.
		$out = array();
		foreach ( $chunks as $c ) {
			$c = trim( $c );
			if ( '' !== $c ) {
				$out[] = $c;
			}
		}
		return $out;
	}

	/**
	 * Quebra o texto em unidades por parágrafo; parágrafos maiores que $max são
	 * subdivididos em frases, e frases gigantes em cortes de tamanho fixo.
	 *
	 * @param string $text
	 * @param int    $max
	 * @return array<int,string>
	 */
	private static function split_units( $text, $max ) {
		$paras = preg_split( '/\n{2,}/', $text );
		if ( false === $paras || null === $paras ) {
			$paras = array( $text );
		}

		$units = array();
		foreach ( $paras as $p ) {
			$p = trim( $p );
			if ( '' === $p ) {
				continue;
			}
			if ( self::clen( $p ) <= $max ) {
				$units[] = $p;
				continue;
			}

			// Parágrafo grande: agrupa frases até o limite.
			$buf = '';
			foreach ( self::split_sentences( $p ) as $sent ) {
				$sent = trim( $sent );
				if ( '' === $sent ) {
					continue;
				}
				if ( self::clen( $sent ) > $max ) {
					if ( '' !== $buf ) {
						$units[] = $buf;
						$buf     = '';
					}
					foreach ( self::hard_split( $sent, $max ) as $piece ) {
						$units[] = $piece;
					}
					continue;
				}
				if ( '' === $buf ) {
					$buf = $sent;
				} elseif ( self::clen( $buf ) + 1 + self::clen( $sent ) <= $max ) {
					$buf .= ' ' . $sent;
				} else {
					$units[] = $buf;
					$buf     = $sent;
				}
			}
			if ( '' !== trim( $buf ) ) {
				$units[] = $buf;
			}
		}
		return $units;
	}

	/**
	 * Quebra um bloco em frases mantendo o delimitador de pontuação.
	 *
	 * @param string $text
	 * @return array<int,string>
	 */
	private static function split_sentences( $text ) {
		$parts = preg_split( '/(?<=[.!?…])\s+/u', $text );
		if ( false === $parts || null === $parts ) {
			// Fallback: quebra por linhas se o PCRE/UTF-8 falhar.
			$parts = preg_split( '/\n+/', $text );
			if ( false === $parts || null === $parts ) {
				return array( $text );
			}
		}
		return $parts;
	}

	/**
	 * Corte de tamanho fixo (UTF-8 aware) para trechos sem limite natural.
	 *
	 * @param string $text
	 * @param int    $max
	 * @return array<int,string>
	 */
	private static function hard_split( $text, $max ) {
		$max    = max( 1, (int) $max );
		$pieces = array();
		$len    = self::clen( $text );
		for ( $i = 0; $i < $len; $i += $max ) {
			$piece = self::csub( $text, $i, $max );
			if ( '' !== trim( $piece ) ) {
				$pieces[] = $piece;
			}
		}
		return $pieces;
	}

	/**
	 * Extrai a "cauda" de sobreposição do final de um chunk, preferindo iniciar
	 * num limite de palavra.
	 *
	 * @param string $text
	 * @param int    $overlap
	 * @return string
	 */
	private static function tail_overlap( $text, $overlap ) {
		$overlap = (int) $overlap;
		if ( $overlap <= 0 ) {
			return '';
		}
		$len = self::clen( $text );
		if ( $len <= $overlap ) {
			return trim( $text );
		}
		$tail = self::csub( $text, $len - $overlap, $overlap );
		// Um espaço é sempre ASCII: seguro fatiar por bytes para não cortar palavra.
		$space = strpos( $tail, ' ' );
		if ( false !== $space && $space < ( strlen( $tail ) / 2 ) ) {
			$tail = substr( $tail, $space + 1 );
		}
		return trim( $tail );
	}

	// --------------------------------------------------- Léxico / tokenização

	/**
	 * Score léxico TF: soma das frequências dos termos (únicos) da consulta no
	 * chunk, normalizada por sqrt(nº de tokens do chunk).
	 *
	 * @param array  $q_tokens Tokens da consulta.
	 * @param string $content  Texto do chunk.
	 * @return float
	 */
	private static function lexical_score( array $q_tokens, $content ) {
		if ( empty( $q_tokens ) ) {
			return 0.0;
		}
		$c_tokens = self::tokenize( $content );
		$n        = count( $c_tokens );
		if ( 0 === $n ) {
			return 0.0;
		}

		$freq = array();
		foreach ( $c_tokens as $t ) {
			$freq[ $t ] = isset( $freq[ $t ] ) ? $freq[ $t ] + 1 : 1;
		}

		$sum = 0;
		foreach ( array_unique( $q_tokens ) as $qt ) {
			if ( isset( $freq[ $qt ] ) ) {
				$sum += $freq[ $qt ];
			}
		}
		if ( 0 === $sum ) {
			return 0.0;
		}
		return (float) $sum / sqrt( (float) $n );
	}

	/**
	 * Tokeniza em minúsculas, quebrando em não-alfanuméricos (Unicode-aware, com
	 * fallback ASCII \W+).
	 *
	 * @param string $text
	 * @return array<int,string>
	 */
	private static function tokenize( $text ) {
		$text  = self::to_lower( (string) $text );
		$parts = preg_split( '/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $parts || null === $parts ) {
			$parts = preg_split( '/\W+/', $text, -1, PREG_SPLIT_NO_EMPTY );
			if ( false === $parts || null === $parts ) {
				return array();
			}
		}
		return $parts;
	}

	// -------------------------------------------------------------- Utilidades

	/**
	 * Nome do modelo de embedding em uso, conforme o backend ativo.
	 *
	 * @return string
	 */
	private static function current_embed_model() {
		$s       = FZWAI_Settings::all();
		$backend = isset( $s['backend'] ) ? $s['backend'] : '';
		if ( 'ollama' === $backend ) {
			return ! empty( $s['embed_model'] )
				? (string) $s['embed_model']
				: ( isset( $s['ollama_model'] ) ? (string) $s['ollama_model'] : '' );
		}
		if ( 'openai' === $backend ) {
			return ! empty( $s['embed_model'] ) ? (string) $s['embed_model'] : FZWAI_Embeddings::OPENAI_DEFAULT_MODEL;
		}
		return '';
	}

	/**
	 * Aproxima o número de tokens de um texto.
	 *
	 * @param string $text
	 * @return int
	 */
	private static function approx_tokens( $text ) {
		return (int) max( 1, (int) ceil( self::clen( $text ) / self::CHARS_PER_TOKEN ) );
	}

	/**
	 * Marca a fonte com status de erro (best-effort, não lança).
	 *
	 * @param int    $source_id
	 * @param string $message
	 * @return void
	 */
	private static function mark_error( $source_id, $message ) {
		try {
			$pdo  = FZWAI_DB::instance()->pdo();
			$stmt = $pdo->prepare( 'UPDATE fzwai_sources SET status = :st, last_error = :err WHERE id = :id' );
			$stmt->execute(
				array(
					':st'  => 'error',
					':err' => self::csub( (string) $message, 0, 500 ),
					':id'  => (int) $source_id,
				)
			);
		} catch ( Throwable $e ) {
			// Silêncio: já estamos no caminho de erro.
			unset( $e );
		}
	}

	/**
	 * preg_replace seguro: devolve o texto original se o PCRE falhar (retorno null).
	 *
	 * @param string $pattern
	 * @param string $replacement
	 * @param string $subject
	 * @return string
	 */
	private static function re_replace( $pattern, $replacement, $subject ) {
		$out = preg_replace( $pattern, $replacement, $subject );
		return ( null === $out ) ? $subject : $out;
	}

	/**
	 * strtolower ciente de UTF-8.
	 *
	 * @param string $text
	 * @return string
	 */
	private static function to_lower( $text ) {
		if ( function_exists( 'mb_strtolower' ) ) {
			return mb_strtolower( (string) $text, 'UTF-8' );
		}
		return strtolower( (string) $text );
	}

	/**
	 * strlen ciente de UTF-8.
	 *
	 * @param string $text
	 * @return int
	 */
	private static function clen( $text ) {
		if ( function_exists( 'mb_strlen' ) ) {
			return (int) mb_strlen( (string) $text, 'UTF-8' );
		}
		return strlen( (string) $text );
	}

	/**
	 * substr ciente de UTF-8.
	 *
	 * @param string   $text
	 * @param int      $start
	 * @param int|null $length
	 * @return string
	 */
	private static function csub( $text, $start, $length = null ) {
		if ( function_exists( 'mb_substr' ) ) {
			return (string) mb_substr( (string) $text, $start, $length, 'UTF-8' );
		}
		if ( null === $length ) {
			return (string) substr( (string) $text, $start );
		}
		return (string) substr( (string) $text, $start, $length );
	}
}
