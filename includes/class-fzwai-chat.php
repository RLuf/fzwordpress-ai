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

	/** Pontuação mínima do RAG para considerar o contexto utilizável. */
	const RELEVANCE_MIN = 0.35;

	/**
	 * Processa uma mensagem do visitante.
	 *
	 * @param array $req session_id, message, name, contact, page_url, ip
	 * @return array reply, protocol, handoff, need_contact
	 */
	public static function handle( array $req ) {
		$s       = FZWAI_Settings::all();
		$session = isset( $req['session_id'] ) ? sanitize_text_field( $req['session_id'] ) : wp_generate_uuid4();
		$message = isset( $req['message'] ) ? trim( wp_strip_all_tags( (string) $req['message'] ) ) : '';
		$name    = isset( $req['name'] ) ? sanitize_text_field( $req['name'] ) : '';
		$contact = isset( $req['contact'] ) ? sanitize_text_field( $req['contact'] ) : '';
		$pageUrl = isset( $req['page_url'] ) ? esc_url_raw( $req['page_url'] ) : '';
		$ip      = isset( $req['ip'] ) ? $req['ip'] : '';

		if ( '' === $message ) {
			return self::response( __( 'Pode me dizer em que posso ajudar?', 'fzwordpress-ai' ), null, null, false, $session );
		}

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

		$messages = array(
			array( 'role' => 'system', 'content' => $system ),
			array( 'role' => 'user', 'content' => $userBlock ),
		);

		// 3) Chama o LLM.
		$llm     = FZWAI_LLM::chat( $messages );
		$answer  = $llm['ok'] ? $llm['text'] : '';

		// 4) Decide se precisa encaminhar (sem contexto, LLM falhou, ou sinal de "não sei").
		$needsHandoff = self::should_handoff( $hasContext, $llm, $answer );

		if ( '' === $answer ) {
			$answer = $hasContext
				? __( 'Deixa eu confirmar isso com nossa equipe para te passar a informação correta.', 'fzwordpress-ai' )
				: __( 'Essa eu prefiro confirmar com um técnico para não te passar informação errada.', 'fzwordpress-ai' );
		}

		self::log_message( $session, 'assistant', $answer, $results );

		// 5) Handoff: se precisa e (já temos contato OU não exigimos contato) → abre protocolo.
		if ( $needsHandoff ) {
			$askContact = ! empty( $s['ask_contact'] );
			if ( $askContact && '' === $contact && '' === $name ) {
				// Pede dados antes de abrir o protocolo.
				$ask = __( 'Posso te encaminhar para um de nossos técnicos. Para isso, me diz seu nome e um telefone/WhatsApp para contato?', 'fzwordpress-ai' );
				return self::response( $answer . "\n\n" . $ask, null, null, true, $session );
			}

			try {
				$protocol = FZWAI_Protocol::open( array(
					'question'        => $message,
					'ai_answer'       => $answer,
					'visitor_name'    => $name,
					'visitor_contact' => $contact,
					'page_url'        => $pageUrl,
					'ip'              => $ip,
				) );
				$handoffMsg = $protocol['handoff']['message'];
				$reply      = $answer . "\n\n" . $handoffMsg;
				return self::response( $reply, $protocol['protocol_no'], $protocol['handoff'], false, $session );
			} catch ( \Throwable $e ) {
				// Nunca deixa o endpoint quebrar por falha ao abrir protocolo:
				// degrada para resposta com WhatsApp direto (sem número de protocolo).
				$wa = '';
				if ( ! empty( $s['whatsapp_number'] ) ) {
					$num = preg_replace( '/\D/', '', (string) $s['whatsapp_number'] );
					$wa  = "\n\n" . sprintf( __( 'Você pode falar direto com nosso atendimento pelo WhatsApp: https://wa.me/%s', 'fzwordpress-ai' ), $num );
				}
				return self::response( $answer . $wa, null, null, false, $session );
			}
		}

		return self::response( $answer, null, null, false, $session );
	}

	/**
	 * Heurística para decidir encaminhamento a um humano.
	 */
	private static function should_handoff( $hasContext, $llm, $answer ) {
		if ( ! $llm['ok'] || '' === trim( $answer ) ) {
			return true;
		}
		// Sinais linguísticos de "não sei / vou encaminhar".
		// Sem contexto relevante, qualquer resposta é palpite → encaminha.
		if ( ! $hasContext ) {
			return true;
		}
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

	private static function response( $reply, $protocol, $handoff, $needContact, $session ) {
		return array(
			'reply'        => $reply,
			'protocol'     => $protocol,
			'handoff'      => $handoff,
			'need_contact' => (bool) $needContact,
			'session_id'   => $session,
		);
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
