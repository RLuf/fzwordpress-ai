<?php
/**
 * Solicitação de suporte por e-mail (fluxo da Ana).
 *
 * Recebe assunto + mensagem + dados do gate (nome/celular/e-mail) e, opcional,
 * 1 foto. A foto é validada por ASSINATURA DE BYTES (magic bytes) e RE-ENCODADA
 * via GD antes de anexar — o que segue no e-mail é uma imagem re-renderizada,
 * sem qualquer payload embutido (substitui o filtro anti-malware do SpamExperts,
 * que não existe neste servidor). O e-mail sai por Exim local via mail(), SEM
 * passar por wp_mail() (o Easy WP SMTP intercepta wp_mail e roteia para um Gmail
 * cuja autenticação está falhando). Nenhuma config de WHM/cPanel é tocada.
 *
 * NÃO grava ticket: a entrega é o e-mail; o banco guarda logs de sessão
 * (fzwai_messages) e o contato de prospecção (fzwai_contacts).
 *
 * @package FZWordPressAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FZWAI_Support {

	const SUPPORT_EMAIL   = 'suporte@webstorage.com.br';
	const MAX_PHOTO_BYTES = 5242880;   // 5 MB
	const MAX_DIM         = 2000;      // lado máximo após re-encode
	const MAX_MEGAPIXELS  = 40;        // teto de área antes do decode (anti decompression bomb)
	const MAX_SIDE        = 12000;     // teto por lado antes do decode
	const RATE_PER_HOUR   = 5;         // solicitações por IP (REMOTE_ADDR) por hora
	const RATE_GLOBAL_HR  = 60;        // teto de envios do site inteiro por hora

	/**
	 * @param array      $d    session_id, name, contact, email, subject, message, page_url, ip
	 * @param array|null $file entrada de $_FILES['photo'] (ou null)
	 * @return array {ok:bool, protocol?:string, error?:string, status?:int}
	 */
	public static function handle( array $d, $file = null ) {
		try {
			return self::do_handle( $d, $file );
		} catch ( \Throwable $e ) {
			// Qualquer falha inesperada (ex.: OOM parcial no GD, PDO) vira JSON,
			// nunca um 500 branco (WSOD) para o visitante.
			error_log( 'fzwai support: ' . $e->getMessage() );
			return array( 'ok' => false, 'status' => 500, 'error' => __( 'Não conseguimos processar sua solicitação agora. Tente novamente em instantes.', 'fzwordpress-ai' ) );
		}
	}

	private static function do_handle( array $d, $file = null ) {
		$name    = trim( (string) ( $d['name'] ?? '' ) );
		$contact = trim( (string) ( $d['contact'] ?? '' ) );
		$email   = trim( (string) ( $d['email'] ?? '' ) );
		$subject = trim( (string) ( $d['subject'] ?? '' ) );
		$message = trim( (string) ( $d['message'] ?? '' ) );

		// Origem: bloqueia POST cross-site óbvio (defesa em profundidade; um
		// script sem Origin correto não passa). Se o header não vier, tolera.
		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? (string) $_SERVER['HTTP_ORIGIN'] : '';
		if ( '' !== $origin ) {
			$oHost = wp_parse_url( $origin, PHP_URL_HOST );
			$sHost = wp_parse_url( home_url(), PHP_URL_HOST );
			if ( $oHost && $sHost && strcasecmp( $oHost, $sHost ) !== 0 ) {
				return array( 'ok' => false, 'status' => 403, 'error' => __( 'Origem não autorizada.', 'fzwordpress-ai' ) );
			}
		}

		// Dados do gate são obrigatórios (nome completo + celular + e-mail).
		if ( '' === $name || '' === $contact || ! is_email( $email ) ) {
			return array( 'ok' => false, 'status' => 400, 'error' => __( 'Preencha nome, celular e um e-mail válido antes de solicitar suporte.', 'fzwordpress-ai' ) );
		}
		if ( '' === $subject ) {
			return array( 'ok' => false, 'status' => 400, 'error' => __( 'Informe o assunto da solicitação.', 'fzwordpress-ai' ) );
		}
		if ( '' === $message ) {
			return array( 'ok' => false, 'status' => 400, 'error' => __( 'Escreva a mensagem para o suporte.', 'fzwordpress-ai' ) );
		}
		$subject = mb_substr( $subject, 0, 120 );
		$message = mb_substr( $message, 0, 2000 );

		// Cota anti-abuso: chave por REMOTE_ADDR (NÃO forjável — o session_id vem
		// do cliente e os headers X-Forwarded/CF são forjáveis), mais um teto
		// global do site para conter mail bomb distribuído.
		$addr    = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
		$rlKey   = 'fzwai_sup_' . md5( $addr );
		$count   = (int) get_transient( $rlKey );
		if ( $count >= self::RATE_PER_HOUR ) {
			return array( 'ok' => false, 'status' => 429, 'error' => __( 'Você enviou muitas solicitações em pouco tempo. Tente novamente mais tarde.', 'fzwordpress-ai' ) );
		}
		$gCount = (int) get_transient( 'fzwai_sup_global' );
		if ( $gCount >= self::RATE_GLOBAL_HR ) {
			return array( 'ok' => false, 'status' => 429, 'error' => __( 'Atendimento com alta demanda no momento. Tente novamente em instantes.', 'fzwordpress-ai' ) );
		}

		// Foto opcional: valida por assinatura e re-encoda. Se não der para
		// processar com segurança, NÃO anexa o arquivo cru.
		$attach = null;
		if ( self::has_upload( $file ) ) {
			$res = self::validate_and_reencode( $file );
			if ( is_wp_error( $res ) ) {
				return array( 'ok' => false, 'status' => 400, 'error' => $res->get_error_message() );
			}
			$attach = $res; // ['filename','mime','bytes']
		}

		$protocol = self::gen_number();
		$sent     = self::send_email( $protocol, $name, $contact, $email, $subject, $message, (string) ( $d['page_url'] ?? '' ), (string) ( $d['ip'] ?? '' ), $attach );

		if ( ! $sent ) {
			return array( 'ok' => false, 'status' => 500, 'error' => __( 'Não conseguimos enviar sua solicitação agora. Tente novamente em instantes.', 'fzwordpress-ai' ) );
		}

		set_transient( $rlKey, $count + 1, HOUR_IN_SECONDS );
		set_transient( 'fzwai_sup_global', $gCount + 1, HOUR_IN_SECONDS );
		return array( 'ok' => true, 'protocol' => $protocol );
	}

	/** Há um arquivo realmente enviado? */
	private static function has_upload( $file ) {
		return is_array( $file )
			&& isset( $file['tmp_name'] ) && '' !== $file['tmp_name']
			&& isset( $file['error'] ) && UPLOAD_ERR_NO_FILE !== (int) $file['error'];
	}

	/**
	 * Valida a foto por magic bytes + getimagesize e re-encoda via GD para JPEG
	 * limpo (descarta qualquer payload). Retorna ['filename','mime','bytes'] ou WP_Error.
	 */
	private static function validate_and_reencode( array $file ) {
		if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error( 'upload', __( 'Falha no envio da imagem. Tente novamente.', 'fzwordpress-ai' ) );
		}
		$tmp = (string) $file['tmp_name'];
		if ( ! is_uploaded_file( $tmp ) ) {
			return new WP_Error( 'upload', __( 'Arquivo de imagem inválido.', 'fzwordpress-ai' ) );
		}
		if ( (int) $file['size'] > self::MAX_PHOTO_BYTES || filesize( $tmp ) > self::MAX_PHOTO_BYTES ) {
			return new WP_Error( 'too_big', __( 'A imagem excede o limite de 5 MB.', 'fzwordpress-ai' ) );
		}

		$bytes = file_get_contents( $tmp );
		if ( false === $bytes || strlen( $bytes ) < 12 ) {
			return new WP_Error( 'read', __( 'Não foi possível ler a imagem.', 'fzwordpress-ai' ) );
		}

		// 1) Assinatura de bytes (magic bytes): só JPEG, PNG ou WebP passam.
		$isJpeg = ( "\xFF\xD8\xFF" === substr( $bytes, 0, 3 ) );
		$isPng  = ( "\x89PNG\r\n\x1a\n" === substr( $bytes, 0, 8 ) );
		$isWebp = ( 'RIFF' === substr( $bytes, 0, 4 ) && 'WEBP' === substr( $bytes, 8, 4 ) );
		if ( ! $isJpeg && ! $isPng && ! $isWebp ) {
			return new WP_Error( 'not_image', __( 'O anexo precisa ser uma imagem (JPG, PNG ou WebP).', 'fzwordpress-ai' ) );
		}

		// 2) getimagesize confirma que o cabeçalho é de imagem real.
		$info = @getimagesizefromstring( $bytes );
		if ( false === $info || empty( $info[0] ) || empty( $info[1] ) ) {
			return new WP_Error( 'not_image', __( 'O anexo não parece uma imagem válida.', 'fzwordpress-ai' ) );
		}
		$allowed = array( IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP );
		if ( ! in_array( (int) $info[2], $allowed, true ) ) {
			return new WP_Error( 'not_image', __( 'Formato de imagem não suportado.', 'fzwordpress-ai' ) );
		}

		// 2b) Anti decompression bomb: rejeita ANTES de decodar se as dimensões
		// declaradas no cabeçalho forem absurdas (arquivo pequeno em bytes, mas
		// gigantesco em pixels alocaria GBs no imagecreatefromstring e mataria o
		// worker por OOM).
		$srcW = (int) $info[0];
		$srcH = (int) $info[1];
		if ( $srcW > self::MAX_SIDE || $srcH > self::MAX_SIDE
			|| ( $srcW * $srcH ) > ( self::MAX_MEGAPIXELS * 1000000 ) ) {
			return new WP_Error( 'too_big', __( 'A imagem tem dimensões muito grandes. Envie uma foto menor.', 'fzwordpress-ai' ) );
		}

		// 3) Re-encode via GD: re-renderiza a imagem, eliminando qualquer payload.
		if ( ! function_exists( 'imagecreatefromstring' ) || ! function_exists( 'imagejpeg' ) ) {
			return new WP_Error( 'no_gd', __( 'Não foi possível processar a imagem com segurança. Envie sem anexo, por favor.', 'fzwordpress-ai' ) );
		}
		$img = @imagecreatefromstring( $bytes );
		if ( false === $img ) {
			return new WP_Error( 'decode', __( 'Não foi possível processar a imagem.', 'fzwordpress-ai' ) );
		}

		$w = imagesx( $img );
		$h = imagesy( $img );
		$scale = ( $w > self::MAX_DIM || $h > self::MAX_DIM ) ? ( self::MAX_DIM / max( $w, $h ) ) : 1.0;
		$nw    = max( 1, (int) round( $w * $scale ) );
		$nh    = max( 1, (int) round( $h * $scale ) );

		// SEMPRE re-renderiza sobre um canvas truecolor com fundo branco (mesmo
		// sem redimensionar): JPEG não tem alpha, então PNG/WebP transparente
		// não vira fundo preto, e o achatamento elimina camadas/metadados.
		$dst = imagecreatetruecolor( $nw, $nh );
		if ( false === $dst ) {
			imagedestroy( $img );
			return new WP_Error( 'encode', __( 'Não foi possível processar a imagem.', 'fzwordpress-ai' ) );
		}
		$white = imagecolorallocate( $dst, 255, 255, 255 );
		imagefilledrectangle( $dst, 0, 0, $nw, $nh, $white );
		imagecopyresampled( $dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h );
		imagedestroy( $img );

		ob_start();
		$ok  = imagejpeg( $dst, null, 85 );
		$out = ob_get_clean();
		imagedestroy( $dst );

		if ( ! $ok || '' === $out ) {
			return new WP_Error( 'encode', __( 'Não foi possível processar a imagem.', 'fzwordpress-ai' ) );
		}
		return array( 'filename' => 'anexo.jpg', 'mime' => 'image/jpeg', 'bytes' => $out );
	}

	/** Número de referência único (não depende da tabela de protocolos). */
	private static function gen_number() {
		$prefix = preg_replace( '/[^A-Za-z0-9]/', '', (string) FZWAI_Settings::get( 'protocol_prefix', 'FZ' ) );
		if ( '' === $prefix ) {
			$prefix = 'FZ';
		}
		$rand = strtoupper( substr( (string) abs( crc32( uniqid( '', true ) ) ), 0, 4 ) );
		return $prefix . gmdate( 'YmdHis' ) . $rand;
	}

	/**
	 * Encoda um texto para uso seguro em cabeçalho de e-mail (RFC 2047, UTF-8
	 * base64). Remove CR/LF por garantia antes de encodar (anti header injection).
	 */
	private static function enc_header( $text ) {
		$text = str_replace( array( "\r", "\n" ), ' ', (string) $text );
		return '=?UTF-8?B?' . base64_encode( $text ) . '?=';
	}

	/**
	 * Envia o e-mail MIME (corpo + anexo re-encodado) por Exim local via mail().
	 * NÃO usa wp_mail (que é interceptado pelo Easy WP SMTP → Gmail com falha).
	 */
	private static function send_email( $protocol, $name, $contact, $email, $subject, $message, $pageUrl, $ip, $attach ) {
		$siteHost = wp_parse_url( home_url(), PHP_URL_HOST );
		$siteHost = $siteHost ? $siteHost : 'imovelsite.com.br';
		$from     = 'no-reply@' . $siteHost;

		$corpo  = "Nova solicitação de suporte pela Ana ({$siteHost}).\n\n";
		$corpo .= "Protocolo: {$protocol}\n";
		$corpo .= "Nome completo: {$name}\n";
		$corpo .= "Celular: {$contact}\n";
		$corpo .= "E-mail de contato: {$email}\n";
		if ( '' !== $pageUrl ) {
			$corpo .= "Página de origem: {$pageUrl}\n";
		}
		if ( '' !== $ip ) {
			$corpo .= "IP: {$ip}\n";
		}
		$corpo .= "\nAssunto: {$subject}\n\nMensagem:\n{$message}\n";
		if ( ! $attach ) {
			$corpo .= "\n(Sem anexo.)\n";
		}

		$subjectHeader = '[Imóvel Site Suporte] ' . $subject;
		$encSubject    = self::enc_header( $subjectHeader );

		$boundary = 'fzwai_' . md5( uniqid( (string) mt_rand(), true ) );

		// Display-names encodados em RFC2047 (bytes UTF-8 crus do nome do visitante
		// quebrariam o cabeçalho). O e-mail já passou por is_email() e o nome por
		// sanitize_text_field (sem CRLF), mas encodar é a forma correta.
		$headers  = 'From: ' . self::enc_header( 'Imóvel Site' ) . " <{$from}>\r\n";
		$headers .= 'Reply-To: ' . self::enc_header( $name ) . " <{$email}>\r\n";
		$headers .= "MIME-Version: 1.0\r\n";
		$headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";
		$headers .= "X-Mailer: fzwordpress-ai\r\n";

		$body  = "--{$boundary}\r\n";
		$body .= "Content-Type: text/plain; charset=UTF-8\r\n";
		$body .= "Content-Transfer-Encoding: base64\r\n\r\n";
		$body .= chunk_split( base64_encode( $corpo ) ) . "\r\n";

		if ( $attach ) {
			$body .= "--{$boundary}\r\n";
			$body .= "Content-Type: {$attach['mime']}; name=\"{$attach['filename']}\"\r\n";
			$body .= "Content-Transfer-Encoding: base64\r\n";
			$body .= "Content-Disposition: attachment; filename=\"{$attach['filename']}\"\r\n\r\n";
			$body .= chunk_split( base64_encode( $attach['bytes'] ) ) . "\r\n";
		}
		$body .= "--{$boundary}--\r\n";

		// mail() entrega ao Exim local; -f define o envelope From (domínio com DKIM).
		$ok = @mail( self::SUPPORT_EMAIL, $encSubject, $body, $headers, '-f ' . $from );
		if ( ! $ok ) {
			error_log( 'fzwai: falha ao enviar solicitação de suporte via mail() (protocolo ' . $protocol . ')' );
		}
		return (bool) $ok;
	}
}
