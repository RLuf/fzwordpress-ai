<?php
/**
 * FZ WordPress AI — backend STANDALONE (sem WordPress).
 *
 * Para sites estáticos. Mesma experiência do plugin: entende a dúvida, responde
 * da base de conhecimento (RAG léxico em SQLite), abre protocolo e encaminha ao
 * técnico via WhatsApp. Backend de IA: endpoint Ollama (ou OpenAI-compat).
 *
 * Config por constantes abaixo ou por fzwai-config.php ao lado (não versionado).
 *
 * @package FZWordPressAI
 */

// ------------------------------------------------------------------ Config
$CFG = array(
	'backend'        => 'ollama',                              // ollama | openai
	'ollama_url'     => 'http://home.rogerluft.com.br:11444',
	'ollama_model'   => 'qwen2.5:1.5b',
	'ollama_num_gpu' => 0,                                     // 0 = CPU
	'openai_base'    => '',
	'openai_key'     => '',
	'openai_model'   => '',
	'assistant_name' => 'Bia',
	'business_name'  => 'Webstorage',
	'topic_scope'    => 'hospedagem de sites, dominios, e-mail e suporte da Webstorage',
	'temperature'    => 0.2,
	'max_tokens'     => 240,
	'whatsapp'       => '5551995826179',
	'protocol_prefix'=> 'WS',
	'data_dir'       => __DIR__ . '/data',
	'kb_file'        => __DIR__ . '/knowledge.txt',
	'allow_origin'   => '*',
);
if ( is_file( __DIR__ . '/fzwai-config.php' ) ) {
	$CFG = array_merge( $CFG, (array) include __DIR__ . '/fzwai-config.php' );
}

// ------------------------------------------------------------------ HTTP guard
header( 'Content-Type: application/json; charset=utf-8' );
header( 'Access-Control-Allow-Origin: ' . $CFG['allow_origin'] );
header( 'Access-Control-Allow-Methods: POST, OPTIONS' );
header( 'Access-Control-Allow-Headers: Content-Type, X-WP-Nonce' );
if ( ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) === 'OPTIONS' ) {
	http_response_code( 204 );
	exit;
}
if ( ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) !== 'POST' ) {
	echo json_encode( array( 'reply' => 'Envie uma pergunta.', 'protocol' => null, 'handoff' => null, 'need_contact' => false ) );
	exit;
}

$raw = file_get_contents( 'php://input' );
$in  = json_decode( $raw, true );
if ( ! is_array( $in ) ) {
	$in = $_POST;
}
$message = trim( strip_tags( (string) ( $in['message'] ?? '' ) ) );
$message = substr( $message, 0, 2000 );
$session = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) ( $in['session_id'] ?? '' ) );
$name    = trim( strip_tags( (string) ( $in['name'] ?? '' ) ) );
$contact = trim( strip_tags( (string) ( $in['contact'] ?? '' ) ) );
$pageUrl = filter_var( (string) ( $in['page_url'] ?? '' ), FILTER_SANITIZE_URL );

if ( '' === $message ) {
	echo json_encode( array( 'reply' => 'Pode me dizer em que posso ajudar?', 'protocol' => null, 'handoff' => null, 'need_contact' => false ) );
	exit;
}

// ------------------------------------------------------------------ Rate limit (arquivo)
$ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ( $_SERVER['REMOTE_ADDR'] ?? '0' );
@mkdir( $CFG['data_dir'], 0755, true );
$rlFile = $CFG['data_dir'] . '/rl_' . md5( $ip );
$now    = time();
$hits   = array_filter( is_file( $rlFile ) ? (array) json_decode( file_get_contents( $rlFile ), true ) : array(), function ( $t ) use ( $now ) {
	return $t > $now - 60;
} );
if ( count( $hits ) > 20 ) {
	http_response_code( 429 );
	echo json_encode( array( 'reply' => 'Muitas mensagens em pouco tempo. Aguarde um instante.', 'protocol' => null, 'handoff' => null, 'need_contact' => false ) );
	exit;
}
$hits[] = $now;
@file_put_contents( $rlFile, json_encode( array_values( $hits ) ) );

// ------------------------------------------------------------------ SQLite
$pdo = null;
try {
	$pdo = new PDO( 'sqlite:' . $CFG['data_dir'] . '/fzwai-standalone.sqlite' );
	$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
	$pdo->exec( 'CREATE TABLE IF NOT EXISTS protocols (id INTEGER PRIMARY KEY AUTOINCREMENT, protocol_no TEXT UNIQUE, name TEXT, contact TEXT, question TEXT, answer TEXT, page_url TEXT, ip TEXT, created_at TEXT)' );
} catch ( Throwable $e ) {
	$pdo = null;
}

// ------------------------------------------------------------------ RAG léxico simples
$context = fzwai_rag_context( $CFG['kb_file'], $message );
$hasContext = '' !== $context;

// ------------------------------------------------------------------ LLM
$sys = 'Você é ' . $CFG['assistant_name'] . ', atendente virtual da ' . $CFG['business_name'] . '. '
	. 'Responda de forma cordial e objetiva, como uma pessoa real. Seu escopo é: ' . $CFG['topic_scope'] . '. '
	. 'Use SOMENTE o CONTEXTO fornecido. Se não houver resposta no contexto ou a pergunta fugir do escopo, '
	. 'diga que vai registrar a solicitação e encaminhar para um técnico. Seja breve (2 a 4 frases) e não invente dados.';
$userBlock = $hasContext
	? "CONTEXTO:\n" . $context . "\n\nPERGUNTA:\n" . $message . "\n\nResponda usando apenas o CONTEXTO."
	: "PERGUNTA:\n" . $message . "\n\n(Sem contexto na base de conhecimento.)";

$answer = fzwai_llm_chat( $CFG, array(
	array( 'role' => 'system', 'content' => $sys ),
	array( 'role' => 'user', 'content' => $userBlock ),
) );

$needHandoff = ! $hasContext || '' === $answer || fzwai_is_refusal( $answer );
if ( '' === $answer ) {
	$answer = 'Deixa eu confirmar isso com nossa equipe para te passar a informação correta.';
}

$protocol = null;
$handoff  = null;
if ( $needHandoff ) {
	$protocol = fzwai_open_protocol( $pdo, $CFG, $message, $answer, $name, $contact, $pageUrl, $ip );
	$num  = preg_replace( '/\D/', '', $CFG['whatsapp'] );
	$wa   = 'https://wa.me/' . $num . '?text=' . rawurlencode( 'Olá! Abri o protocolo ' . $protocol . ' no site. Minha dúvida: ' . substr( $message, 0, 300 ) );
	$handoff = array(
		'type'    => 'whatsapp',
		'url'     => $wa,
		'message' => 'Seu protocolo ' . $protocol . ' foi aberto. Em alguns minutos um técnico entrará em contato com você pelo WhatsApp.',
	);
	$answer .= "\n\n" . $handoff['message'];
}

echo json_encode( array(
	'reply'        => $answer,
	'protocol'     => $protocol,
	'handoff'      => $handoff,
	'need_contact' => false,
	'session_id'   => $session,
), JSON_UNESCAPED_UNICODE );
exit;

// ================================================================= funções
function fzwai_rag_context( $kbFile, $query ) {
	if ( ! is_file( $kbFile ) ) {
		return '';
	}
	$text = file_get_contents( $kbFile );
	// Chunk por parágrafo/linha em branco.
	$chunks = preg_split( '/\n\s*\n/', $text );
	$qTerms = array_filter( preg_split( '/\W+/u', mb_strtolower( $query ) ), function ( $t ) {
		return mb_strlen( $t ) >= 3;
	} );
	$best = array( 'score' => 0, 'text' => '' );
	$scored = array();
	foreach ( $chunks as $c ) {
		$c = trim( $c );
		if ( '' === $c ) {
			continue;
		}
		$lc = mb_strtolower( $c );
		$score = 0;
		foreach ( $qTerms as $t ) {
			$score += substr_count( $lc, $t );
		}
		$score = $score / sqrt( max( 1, str_word_count( $c ) ) );
		if ( $score > 0 ) {
			$scored[] = array( 'score' => $score, 'text' => $c );
		}
	}
	if ( empty( $scored ) ) {
		return '';
	}
	usort( $scored, function ( $a, $b ) {
		return $b['score'] <=> $a['score'];
	} );
	// Gate de relevância mínimo.
	if ( $scored[0]['score'] < 0.3 ) {
		return '';
	}
	$out = '';
	foreach ( array_slice( $scored, 0, 3 ) as $s ) {
		$out .= $s['text'] . "\n\n";
		if ( mb_strlen( $out ) > 2500 ) {
			break;
		}
	}
	return trim( $out );
}

function fzwai_llm_chat( $CFG, $messages ) {
	if ( 'openai' === $CFG['backend'] && '' !== $CFG['openai_base'] ) {
		$url  = rtrim( $CFG['openai_base'], '/' ) . '/chat/completions';
		$body = array( 'model' => $CFG['openai_model'], 'messages' => $messages, 'temperature' => $CFG['temperature'], 'max_tokens' => $CFG['max_tokens'], 'stream' => false );
		$hdr  = array( 'Content-Type: application/json' );
		if ( '' !== $CFG['openai_key'] ) {
			$hdr[] = 'Authorization: Bearer ' . $CFG['openai_key'];
		}
		$resp = fzwai_http( $url, $body, $hdr, 120 );
		return isset( $resp['choices'][0]['message']['content'] ) ? trim( $resp['choices'][0]['message']['content'] ) : '';
	}
	// Ollama
	$url  = rtrim( $CFG['ollama_url'], '/' ) . '/api/chat';
	$opts = array( 'temperature' => $CFG['temperature'], 'num_predict' => $CFG['max_tokens'] );
	if ( '' !== (string) $CFG['ollama_num_gpu'] ) {
		$opts['num_gpu'] = (int) $CFG['ollama_num_gpu'];
	}
	$body = array( 'model' => $CFG['ollama_model'], 'messages' => $messages, 'stream' => false, 'options' => $opts );
	$resp = fzwai_http( $url, $body, array( 'Content-Type: application/json' ), 120 );
	return isset( $resp['message']['content'] ) ? trim( $resp['message']['content'] ) : '';
}

function fzwai_http( $url, $body, $headers, $timeout ) {
	$ch = curl_init( $url );
	curl_setopt_array( $ch, array(
		CURLOPT_POST           => true,
		CURLOPT_POSTFIELDS     => json_encode( $body, JSON_UNESCAPED_UNICODE ),
		CURLOPT_HTTPHEADER     => $headers,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT        => $timeout,
	) );
	$out = curl_exec( $ch );
	curl_close( $ch );
	return $out ? json_decode( $out, true ) : array();
}

function fzwai_is_refusal( $answer ) {
	$lc = mb_strtolower( $answer );
	foreach ( array( 'não sei', 'nao sei', 'encaminhar', 'técnico', 'tecnico', 'não posso ajudar', 'nao posso ajudar', 'entre em contato', 'fora do', 'não faz parte', 'nao faz parte', 'não posso fornecer', 'nao posso fornecer', 'não inclui', 'nao inclui' ) as $sig ) {
		if ( false !== mb_strpos( $lc, $sig ) ) {
			return true;
		}
	}
	return false;
}

function fzwai_open_protocol( $pdo, $CFG, $q, $a, $name, $contact, $pageUrl, $ip ) {
	$prefix = preg_replace( '/[^A-Za-z0-9]/', '', $CFG['protocol_prefix'] ) ?: 'WS';
	$day    = gmdate( 'Ymd' );
	if ( ! $pdo ) {
		return $prefix . $day . substr( (string) abs( crc32( uniqid( '', true ) ) ), 0, 4 );
	}
	for ( $i = 0; $i < 6; $i++ ) {
		$seq  = (int) $pdo->query( "SELECT COUNT(*) FROM protocols WHERE protocol_no LIKE '{$prefix}{$day}%'" )->fetchColumn() + 1 + $i;
		$cand = sprintf( '%s%s%04d', $prefix, $day, $seq );
		try {
			$st = $pdo->prepare( 'INSERT INTO protocols (protocol_no,name,contact,question,answer,page_url,ip,created_at) VALUES (?,?,?,?,?,?,?,?)' );
			$st->execute( array( $cand, mb_substr( $name, 0, 120 ), mb_substr( $contact, 0, 120 ), $q, $a, $pageUrl, $ip, gmdate( 'Y-m-d H:i:s' ) ) );
			return $cand;
		} catch ( Throwable $e ) {
			if ( false !== stripos( $e->getMessage(), 'unique' ) ) {
				continue;
			}
			break;
		}
	}
	return $prefix . $day . substr( (string) abs( crc32( uniqid( '', true ) ) ), 0, 4 );
}
