<?php
/**
 * Endpoints REST do atendimento (frente pública).
 *   POST /wp-json/fzwai/v1/chat      — conversa
 *   GET  /wp-json/fzwai/v1/greeting  — dados de exibição do widget
 *
 * @package FZWordPressAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FZWAI_REST {

	const NS = 'fzwai/v1';

	public static function boot() {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		register_rest_route( self::NS, '/chat', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'chat' ),
			'permission_callback' => array( __CLASS__, 'public_permission' ),
			'args'                => array(
				'message'    => array(
					'required'          => true,
					'type'              => 'string',
					// Limite anti-abuso: mensagens gigantes inflam custo/tokens e o banco.
					'validate_callback' => function ( $v ) {
						return is_string( $v ) && strlen( $v ) <= 2000;
					},
					'sanitize_callback' => function ( $v ) {
						return substr( (string) $v, 0, 2000 );
					},
				),
				// Campos livres com teto de tamanho: sem isso, payloads de vários MB
				// iam parar no SQLite a cada mensagem (enchimento de disco).
				'session_id' => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => function ( $v ) { return substr( (string) $v, 0, 64 ); } ),
				'name'       => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => function ( $v ) { return substr( (string) $v, 0, 120 ); } ),
				'contact'    => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => function ( $v ) { return substr( (string) $v, 0, 160 ); } ),
				'email'      => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => function ( $v ) { return substr( (string) $v, 0, 160 ); } ),
				'page_url'   => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => function ( $v ) { return substr( (string) $v, 0, 500 ); } ),
			),
		) );

		// Solicitação de suporte (multipart: assunto, mensagem e 1 foto opcional).
		// Enviada por e-mail ao suporte; NÃO grava ticket (só logs de sessão + contato).
		register_rest_route( self::NS, '/support', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'support' ),
			'permission_callback' => array( __CLASS__, 'public_permission' ),
		) );

		register_rest_route( self::NS, '/greeting', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'greeting' ),
			'permission_callback' => '__return_true',
		) );
	}

	/**
	 * Autoriza os endpoints públicos aplicando limites de taxa.
	 *
	 * As rotas não usam nonce nem sessão: um nonce REST embutido em página
	 * cacheada expira e pode ser recusado pelo núcleo do WordPress antes deste
	 * callback. Operações administrativas continuam protegidas separadamente
	 * por capacidade e nonce.
	 *
	 * @param WP_REST_Request $request Requisição REST pública.
	 * @return true|WP_Error
	 */
	public static function public_permission( $request ) {
		// Rate limit por IP + backstop global. A chave usa REMOTE_ADDR (não
		// forjável), NÃO os headers X-Forwarded-For/CF-Connecting-IP — girar o
		// header dava uma chave nova a cada request e anulava o limite.
		$addr = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
		if ( ! self::rl_allow( 'fzwai_rl_' . md5( $addr ), 30 ) ) { // 30 req/min por IP
			return new WP_Error( 'fzwai_rate', __( 'Muitas mensagens em pouco tempo. Aguarde um instante.', 'fzwordpress-ai' ), array( 'status' => 429 ) );
		}
		if ( ! self::rl_allow( 'fzwai_rl_global', 300 ) ) { // 300 req/min no site inteiro
			return new WP_Error( 'fzwai_rate', __( 'Atendimento com alta demanda no momento. Tente novamente em instantes.', 'fzwordpress-ai' ), array( 'status' => 429 ) );
		}
		return true;
	}

	/**
	 * Janela FIXA de 60s: {contagem, início}. O modelo antigo renovava o TTL a
	 * cada request (janela deslizante), então o contador global só zerava após
	 * 60s de silêncio TOTAL no site — com tráfego contínuo, 429 permanente.
	 */
	private static function rl_allow( $key, $limit ) {
		$bucket = get_transient( $key );
		if ( ! is_array( $bucket ) || ! isset( $bucket['t'] ) || ( time() - (int) $bucket['t'] ) >= MINUTE_IN_SECONDS ) {
			$bucket = array( 'n' => 0, 't' => time() );
		}
		$bucket['n']++;
		set_transient( $key, $bucket, 2 * MINUTE_IN_SECONDS );
		return $bucket['n'] <= $limit;
	}

	public static function chat( WP_REST_Request $request ) {
		if ( ! FZWAI_DB::available() ) {
			return new WP_REST_Response( array(
				'reply'        => __( 'Atendimento indisponível no momento. Por favor, tente novamente mais tarde.', 'fzwordpress-ai' ),
				'protocol'     => null,
				'handoff'      => null,
				'need_contact' => false,
			), 200 );
		}

		$result = FZWAI_Chat::handle( array(
			'session_id' => (string) $request->get_param( 'session_id' ),
			'message'    => (string) $request->get_param( 'message' ),
			'name'       => (string) $request->get_param( 'name' ),
			'contact'    => (string) $request->get_param( 'contact' ),
			'email'      => (string) $request->get_param( 'email' ),
			'page_url'   => (string) $request->get_param( 'page_url' ),
			'ip'         => self::client_ip(),
		) );

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Solicitação de suporte por e-mail. Recebe multipart (assunto, mensagem,
	 * dados do gate e 1 foto opcional). A validação/entrega fica em FZWAI_Support.
	 */
	public static function support( WP_REST_Request $request ) {
		$data = array(
			'session_id' => substr( (string) $request->get_param( 'session_id' ), 0, 64 ),
			'name'       => substr( sanitize_text_field( (string) $request->get_param( 'name' ) ), 0, 120 ),
			'contact'    => substr( sanitize_text_field( (string) $request->get_param( 'contact' ) ), 0, 160 ),
			'email'      => substr( sanitize_email( (string) $request->get_param( 'email' ) ), 0, 160 ),
			'subject'    => substr( sanitize_text_field( (string) $request->get_param( 'subject' ) ), 0, 120 ),
			'message'    => substr( sanitize_textarea_field( (string) $request->get_param( 'message' ) ), 0, 2000 ),
			'page_url'   => substr( esc_url_raw( (string) $request->get_param( 'page_url' ) ), 0, 500 ),
			'ip'         => self::client_ip(),
		);
		$file = isset( $_FILES['photo'] ) ? $_FILES['photo'] : null;

		$res = FZWAI_Support::handle( $data, $file );
		$code = ! empty( $res['ok'] ) ? 200 : ( isset( $res['status'] ) ? (int) $res['status'] : 400 );
		return new WP_REST_Response( $res, $code );
	}

	public static function greeting( WP_REST_Request $request ) {
		$s = FZWAI_Settings::all();
		$greeting = str_replace(
			array( '{assistente}', '{empresa}' ),
			array( $s['assistant_name'], $s['business_name'] ),
			$s['widget_greeting']
		);
		return new WP_REST_Response( array(
			'enabled'   => (bool) $s['widget_enabled'],
			'greeting'  => $greeting,
			'title'     => $s['widget_title'],
			'assistant' => $s['assistant_name'],
			'business'  => $s['business_name'],
			'color'     => $s['widget_color'],
			'position'  => $s['widget_position'],
			'whatsapp'  => preg_replace( '/\D/', '', (string) $s['whatsapp_number'] ),
		), 200 );
	}

	/**
	 * Melhor palpite do IP real do visitante — SÓ para registro/forense
	 * (fzwai_protocols.ip). Nunca usar como chave de rate limit: os headers
	 * são forjáveis por quem fala direto com o origin.
	 */
	public static function client_ip() {
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ) as $h ) {
			if ( ! empty( $_SERVER[ $h ] ) ) {
				$ip = trim( explode( ',', wp_unslash( $_SERVER[ $h ] ) )[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( (string) $_SERVER['REMOTE_ADDR'] ) : '';
	}
}
