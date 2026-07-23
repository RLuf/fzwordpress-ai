<?php
/**
 * Orquestração do atendimento: entende a dúvida, busca no conhecimento (RAG),
 * responde com o LLM limitado ao tópico, e — quando não sabe ou o visitante
 * pede atendimento — abre protocolo e encaminha para o técnico (WhatsApp).
 *
 * @package FZWordPressAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FZWAI_Chat {

	/**
	 * Pontuação mínima do RAG para considerar o contexto utilizável.
	 * Atenção à escala: sem embeddings o RAG devolve score léxico
	 * (freq. de termos / sqrt(tokens do chunk)), tipicamente 0.05–0.2 —
	 * bem abaixo da escala de cosseno. 0.35 descartava contexto válido.
	 */
	const RELEVANCE_MIN = 0.05;

	/** Quantas mensagens anteriores da sessão vão junto no prompt. */
	const HISTORY_TURNS = 8;

	/** Cota anti-abuso: máximo de mensagens do visitante por sessão em 12h. */
	const SESSION_MSG_LIMIT = 20;
	const SESSION_WINDOW_HOURS = 12;

	/** Tentativas de compreensão antes de abrir o formulário de suporte. */
	const MAX_UNDERSTAND_TRIES = 2;

	/**
	 * Processa uma mensagem do visitante.
	 *
	 * @param array $req session_id, message, name, contact, page_url, ip
	 * @return array reply, protocol, handoff, need_contact
	 */
	public static function handle( array $req ) {
		$s       = FZWAI_Settings::all();
		$session = isset( $req['session_id'] ) ? sanitize_text_field( $req['session_id'] ) : wp_generate_uuid4();
		// Remove só o que parece tag HTML de verdade: wp_strip_all_tags/strip_tags
		// engoliria tudo de um '<' solto em diante ("apartamento < 300 mil").
		$message = isset( $req['message'] ) ? trim( preg_replace( '#<[a-zA-Z/!][^>]*>#', '', (string) $req['message'] ) ) : '';
		$name    = isset( $req['name'] ) ? sanitize_text_field( $req['name'] ) : '';
		$contact = isset( $req['contact'] ) ? sanitize_text_field( $req['contact'] ) : '';
		$email   = isset( $req['email'] ) ? sanitize_email( $req['email'] ) : '';
		$pageUrl = isset( $req['page_url'] ) ? esc_url_raw( $req['page_url'] ) : '';
		$ip      = isset( $req['ip'] ) ? $req['ip'] : '';

		// Gate: com os três dados (base de prospecção), grava/atualiza o contato.
		if ( '' !== $name && '' !== $contact && '' !== $email && FZWAI_DB::available() ) {
			try {
				FZWAI_DB::instance()->save_contact( $session, array(
					'name'     => $name,
					'phone'    => $contact,
					'email'    => $email,
					'page_url' => $pageUrl,
					'ip'       => $ip,
				) );
			} catch ( \Throwable $e ) {
				// prospecção é best-effort; não bloqueia o atendimento
			}
		}

		if ( '' === $message ) {
			return self::response( __( 'Pode me dizer em que posso ajudar?', 'fzwordpress-ai' ), null, null, false, $session );
		}

		// Cota anti-abuso: 20 mensagens do visitante por sessão em 12h.
		if ( FZWAI_DB::available() ) {
			try {
				$used = FZWAI_DB::instance()->count_user_messages( $session, self::SESSION_WINDOW_HOURS );
				if ( $used >= self::SESSION_MSG_LIMIT ) {
					return self::response(
						__( 'Você atingiu o limite de mensagens desta conversa (20 em 12 horas). Por favor, tente novamente mais tarde ou fale com o nosso suporte.', 'fzwordpress-ai' ),
						null, null, false, $session
					);
				}
			} catch ( \Throwable $e ) {
				// se a contagem falhar, não trava o atendimento
			}
		}

		// Histórico da sessão ANTES de gravar a mensagem atual (senão ela duplica).
		$history = self::recent_history( $session, self::HISTORY_TURNS );

		self::log_message( $session, 'user', $message );

		// 1) Recupera contexto do conhecimento (RAG).
		$results = array();
		if ( class_exists( 'FZWAI_RAG' ) ) {
			$results = FZWAI_RAG::search( $message, 4 );
		}
		// Só consideramos "com contexto" se a melhor pontuação for relevante — evita
		// responder com pedaços fracos a perguntas fora do assunto.
		$topScore   = ! empty( $results ) ? (float) $results[0]['score'] : 0.0;
		$hasContext = ! empty( $results ) && $topScore >= self::RELEVANCE_MIN;
		$context    = $hasContext ? FZWAI_RAG::build_context( $results, 3000 ) : '';

		// 2) Monta as mensagens para o LLM.
		$system = FZWAI_Settings::effective_system_prompt();
		$userBlock = $message;
		if ( $hasContext ) {
			$userBlock = "CONTEXTO:\n" . $context . "\n\nPERGUNTA DO VISITANTE:\n" . $message
				. "\n\nResponda usando apenas o CONTEXTO. Se não houver resposta no contexto, diga que vai registrar e encaminhar para um técnico.";
		} else {
			$userBlock = "PERGUNTA DO VISITANTE:\n" . $message
				. "\n\n(Não há contexto disponível na base de conhecimento para esta pergunta.)";
		}

		$messages = array_merge(
			array( array( 'role' => 'system', 'content' => $system ) ),
			$history,
			array( array( 'role' => 'user', 'content' => $userBlock ) )
		);

		// 3) Chama o LLM.
		$llm     = FZWAI_LLM::chat( $messages );
		$answer  = $llm['ok'] ? $llm['text'] : '';
		if ( ! $llm['ok'] ) {
			// Sem isso a falha real (ex.: connection refused) some sem rastro.
			error_log( 'fzwai: LLM falhou (backend=' . $llm['backend'] . '): ' . $llm['error'] );
		}

		// 4) Decide se precisa encaminhar (sem contexto, LLM falhou, ou sinal de "não sei").
		$needsHandoff = self::should_handoff( $hasContext, $llm, $answer );

		if ( '' === $answer ) {
			$answer = $hasContext
				? __( 'Deixa eu confirmar isso com nossa equipe para te passar a informação correta.', 'fzwordpress-ai' )
				: __( 'Essa eu prefiro confirmar com um técnico para não te passar informação errada.', 'fzwordpress-ai' );
		}

		self::log_message( $session, 'assistant', $answer, $results );

		// 5) Escalonamento educado (2 tentativas → formulário de suporte).
		//    Em vez de abrir chamado direto, a Ana tenta entender; na 1ª falha
		//    pede para reformular; na 2ª falha consecutiva abre o formulário de
		//    solicitação de suporte (que é enviado por e-mail). O WhatsApp segue
		//    disponível como opção paralela.
		$failKey = 'fzwai_fail_' . md5( $session );

		if ( $needsHandoff ) {
			$fails = (int) get_transient( $failKey ) + 1;

			if ( $fails < self::MAX_UNDERSTAND_TRIES ) {
				// 1ª falha: pede para descrever de novo, em poucas palavras.
				set_transient( $failKey, $fails, self::SESSION_WINDOW_HOURS * HOUR_IN_SECONDS );
				$ask = __( 'Desculpe, não consegui entender bem. Você pode descrever sua dúvida novamente, em poucas palavras?', 'fzwordpress-ai' );
				return self::response( $answer . "\n\n" . $ask, null, null, false, $session );
			}

			// 2ª falha consecutiva: encaminha para o suporte (abre o formulário).
			delete_transient( $failKey );
			$msg     = __( 'Essa informação é dedicada exclusivamente ao nosso suporte. Vou encaminhar a sua solicitação — é só preencher os campos abaixo.', 'fzwordpress-ai' );
			$handoff = self::whatsapp_handoff( $s, $message ); // WhatsApp continua como opção
			return self::response( $answer . "\n\n" . $msg, null, $handoff, false, $session, true );
		}

		// Respondeu bem: zera o contador de falhas da sessão.
		delete_transient( $failKey );
		return self::response( $answer, null, null, false, $session );
	}

	/**
	 * Monta o handoff de WhatsApp sem abrir protocolo (opção paralela ao suporte
	 * por e-mail). Retorna null se não houver número configurado.
	 */
	private static function whatsapp_handoff( array $s, $question ) {
		$num = isset( $s['whatsapp_number'] ) ? preg_replace( '/\D/', '', (string) $s['whatsapp_number'] ) : '';
		if ( '' === $num ) {
			return null;
		}
		$text = sprintf(
			/* translators: %s: dúvida do visitante */
			__( 'Olá! Preciso de suporte. Minha dúvida: %s', 'fzwordpress-ai' ),
			mb_substr( (string) $question, 0, 300 )
		);
		return array(
			'type'    => 'whatsapp',
			'url'     => 'https://wa.me/' . $num . '?text=' . rawurlencode( $text ),
			'message' => __( 'Se preferir, você também pode falar com o suporte pelo WhatsApp.', 'fzwordpress-ai' ),
		);
	}

	/**
	 * Heurística para decidir encaminhamento a um humano.
	 */
	private static function should_handoff( $hasContext, $llm, $answer ) {
		if ( ! $llm['ok'] || '' === trim( $answer ) ) {
			return true;
		}
		// Sinais linguísticos de "não sei / vou encaminhar". O system prompt já
		// instrui o modelo a se declarar sem resposta quando o contexto não cobre
		// a pergunta — forçar handoff em TODA mensagem sem contexto abria um
		// protocolo por turno e tornava o bot inútil com a base ainda pequena.
		$lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( $answer ) : strtolower( $answer );
		$signals = array(
			'não sei', 'nao sei', 'não tenho essa informação', 'nao tenho essa informacao',
			'encaminhar', 'técnico', 'tecnico', 'não posso responder', 'nao posso responder',
			'não posso ajudar', 'nao posso ajudar', 'não posso te ajudar', 'nao posso te ajudar',
			'entre em contato', 'fale com', 'fora do meu escopo', 'não faz parte', 'nao faz parte',
			'não é meu', 'nao e meu', 'não consigo ajudar', 'nao consigo ajudar',
			'infelizmente não', 'infelizmente nao', 'não posso fornecer', 'nao posso fornecer',
		);
		foreach ( $signals as $sig ) {
			if ( false !== strpos( $lc, $sig ) ) {
				return true;
			}
		}
		return false;
	}

	private static function response( $reply, $protocol, $handoff, $needContact, $session, $supportForm = false ) {
		return array(
			'reply'         => $reply,
			'protocol'      => $protocol,
			'handoff'       => $handoff,
			'need_contact'  => (bool) $needContact,
			'support_form'  => (bool) $supportForm,
			'session_id'    => $session,
		);
	}

	/**
	 * Últimas mensagens da sessão, em ordem cronológica, no formato do LLM.
	 * Dá memória de conversa ao bot ("e ele inclui domínio?" após falar do plano).
	 */
	private static function recent_history( $session, $limit ) {
		try {
			$db   = FZWAI_DB::instance()->pdo();
			$stmt = $db->prepare( 'SELECT role, content FROM fzwai_messages WHERE session_id = :s ORDER BY id DESC LIMIT :l' );
			$stmt->bindValue( ':s', $session );
			$stmt->bindValue( ':l', (int) $limit, PDO::PARAM_INT );
			$stmt->execute();
			$rows = $stmt->fetchAll( PDO::FETCH_ASSOC );
			$out  = array();
			foreach ( array_reverse( $rows ? $rows : array() ) as $r ) {
				if ( in_array( $r['role'], array( 'user', 'assistant' ), true ) && '' !== trim( (string) $r['content'] ) ) {
					$out[] = array( 'role' => $r['role'], 'content' => (string) $r['content'] );
				}
			}
			return $out;
		} catch ( \Throwable $e ) {
			// Histórico é best-effort; sem ele a conversa segue, só sem memória.
			return array();
		}
	}

	private static function log_message( $session, $role, $content, $results = array() ) {
		try {
			$db = FZWAI_DB::instance()->pdo();
			$stmt = $db->prepare( 'INSERT INTO fzwai_messages (session_id, protocol_id, role, content, sources, created_at)
				VALUES (:s, NULL, :r, :c, :src, :ca)' );
			$stmt->execute( array(
				':s'   => $session,
				':r'   => $role,
				':c'   => (string) $content,
				':src' => $results ? wp_json_encode( array_map( function ( $r ) {
					return array( 'source_id' => $r['source_id'], 'label' => isset( $r['label'] ) ? $r['label'] : '', 'score' => round( $r['score'], 3 ) );
				}, $results ) ) : '',
				':ca'  => FZWAI_DB::now(),
			) );
		} catch ( \Throwable $e ) {
			// Log é best-effort; nunca quebra o atendimento.
		}
	}
}
