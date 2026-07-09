<?php
/**
 * Protocolos de atendimento. Gera número único, persiste no SQLite e monta o
 * encaminhamento para o técnico (WhatsApp).
 *
 * @package FZWordPressAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FZWAI_Protocol {

	/** substr seguro para UTF-8 (mb_substr é opcional em alguns servidores). */
	private static function sub( $str, $start, $len ) {
		$str = (string) $str;
		return function_exists( 'mb_substr' ) ? mb_substr( $str, $start, $len ) : substr( $str, $start, $len );
	}

	/**
	 * Abre um protocolo e retorna os dados (inclui handoff de WhatsApp).
	 * Concorrência-segura: tenta gerar/inserir com número novo se houver colisão
	 * na constraint UNIQUE (duas aberturas simultâneas no mesmo dia).
	 *
	 * @param array $data question, ai_answer, visitor_name, visitor_contact, page_url, ip
	 * @return array ['protocol_no','id','handoff'=>['type','url','message'],'created_at']
	 */
	public static function open( array $data ) {
		$s   = FZWAI_Settings::all();
		$db  = FZWAI_DB::instance()->pdo();
		$now = FZWAI_DB::now();

		$stmt = $db->prepare( 'INSERT INTO fzwai_protocols
			(protocol_no, visitor_name, visitor_contact, question, ai_answer, status, handoff, page_url, ip, created_at, updated_at)
			VALUES (:pn,:vn,:vc,:q,:aa,:st,:ho,:pu,:ip,:ca,:ua)' );
		$handoffType = ! empty( $s['whatsapp_number'] ) ? 'whatsapp' : 'none';

		$protocol = '';
		$id       = 0;
		$attempts = 0;
		while ( $attempts < 6 ) {
			$attempts++;
			$protocol = self::generate_number( $s['protocol_prefix'] );
			try {
				$stmt->execute( array(
					':pn' => $protocol,
					':vn' => self::sub( isset( $data['visitor_name'] ) ? $data['visitor_name'] : '', 0, 120 ),
					':vc' => self::sub( isset( $data['visitor_contact'] ) ? $data['visitor_contact'] : '', 0, 120 ),
					':q'  => (string) $data['question'],
					':aa' => isset( $data['ai_answer'] ) ? (string) $data['ai_answer'] : '',
					':st' => 'open',
					':ho' => $handoffType,
					':pu' => isset( $data['page_url'] ) ? esc_url_raw( $data['page_url'] ) : '',
					':ip' => isset( $data['ip'] ) ? (string) $data['ip'] : '',
					':ca' => $now,
					':ua' => $now,
				) );
				$id = (int) $db->lastInsertId();
				break;
			} catch ( \PDOException $e ) {
				// Colisão de UNIQUE por concorrência → tenta outro número.
				if ( false !== stripos( $e->getMessage(), 'unique' ) && $attempts < 6 ) {
					continue;
				}
				throw $e; // outro erro (ou esgotou tentativas) → sobe para o chamador tratar
			}
		}

		$handoff = self::build_handoff( $s, $protocol, $data );

		return array(
			'id'          => $id,
			'protocol_no' => $protocol,
			'handoff'     => $handoff,
			'created_at'  => $now,
		);
	}

	/**
	 * Número de protocolo único: PREFIXO + AAAAMMDD + sequência curta.
	 */
	public static function generate_number( $prefix ) {
		$prefix = preg_replace( '/[^A-Za-z0-9]/', '', (string) $prefix );
		if ( '' === $prefix ) {
			$prefix = 'FZ';
		}
		$db = FZWAI_DB::instance()->pdo();
		// Sequência do dia.
		$day  = gmdate( 'Ymd' );
		$like = $prefix . $day . '%';
		$stmt = $db->prepare( 'SELECT COUNT(*) FROM fzwai_protocols WHERE protocol_no LIKE :l' );
		$stmt->execute( array( ':l' => $like ) );
		$seq = (int) $stmt->fetchColumn() + 1;
		$candidate = sprintf( '%s%s%04d', $prefix, $day, $seq );
		// Garante unicidade (colisão improvável, mas protege).
		$guard = 0;
		while ( $guard < 50 ) {
			$check = $db->prepare( 'SELECT 1 FROM fzwai_protocols WHERE protocol_no = :p' );
			$check->execute( array( ':p' => $candidate ) );
			if ( ! $check->fetchColumn() ) {
				return $candidate;
			}
			$seq++;
			$candidate = sprintf( '%s%s%04d', $prefix, $day, $seq );
			$guard++;
		}
		return $prefix . $day . substr( (string) abs( crc32( uniqid( '', true ) ) ), 0, 6 );
	}

	/**
	 * Monta o link de WhatsApp e a mensagem de encaminhamento.
	 */
	private static function build_handoff( $s, $protocol, $data ) {
		if ( empty( $s['whatsapp_number'] ) ) {
			return array( 'type' => 'none', 'url' => '', 'message' => '' );
		}
		$msgTemplate = $s['handoff_message'];
		$message     = str_replace(
			array( '{protocolo}', '{protocol}', '{assistente}', '{empresa}' ),
			array( $protocol, $protocol, $s['assistant_name'], $s['business_name'] ),
			$msgTemplate
		);

		// Mensagem pré-preenchida para o WhatsApp (do visitante para o atendimento).
		$waText = sprintf(
			'Olá! Abri o protocolo %s no site. Minha dúvida: %s',
			$protocol,
			isset( $data['question'] ) ? self::sub( $data['question'], 0, 300 ) : ''
		);
		$number = preg_replace( '/\D/', '', (string) $s['whatsapp_number'] );
		$url    = 'https://wa.me/' . $number . '?text=' . rawurlencode( $waText );

		return array(
			'type'    => 'whatsapp',
			'url'     => $url,
			'message' => $message,
		);
	}

	/**
	 * Atualiza status (open|forwarded|closed).
	 */
	public static function set_status( $id, $status ) {
		$db = FZWAI_DB::instance()->pdo();
		$stmt = $db->prepare( 'UPDATE fzwai_protocols SET status = :s, updated_at = :u WHERE id = :id' );
		$stmt->execute( array( ':s' => $status, ':u' => FZWAI_DB::now(), ':id' => (int) $id ) );
	}
}
