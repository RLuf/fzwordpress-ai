<?php
/**
 * Widget de chat flutuante (front-end).
 *
 * Responsável apenas pela camada de apresentação no site: enfileira o CSS/JS,
 * imprime o ponto de montagem no rodapé e injeta a configuração pública
 * (FZWAI_WIDGET) com textos, cores, WhatsApp e a URL/nonce do endpoint REST.
 * A conversa em si é resolvida pelo endpoint POST /wp-json/fzwai/v1/chat
 * (construído em FZWAI_REST); aqui nada de IA, só a "cara" do produto.
 *
 * @package FZWordPressAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FZWAI_Widget {

	/** Handle único usado no enqueue de CSS e JS. */
	const HANDLE = 'fzwai-widget';

	/** Cor padrão do acento, caso a configuração venha inválida. */
	const DEFAULT_COLOR = '#2f7ec4';

	/**
	 * Marca se um mount inline (shortcode) já foi renderizado nesta página,
	 * para não duplicar a bolha flutuante do rodapé.
	 *
	 * @var bool
	 */
	private static $has_inline = false;

	/**
	 * Registra os hooks de front-end.
	 *
	 * Chamado pelo carregador principal (FZWAI_Plugin::boot()).
	 * Não faz nada se o widget estiver desativado nas configurações.
	 *
	 * @return void
	 */
	public static function boot() {
		// O shortcode [fzwai_chat] e seus assets funcionam mesmo com a bolha
		// flutuante desligada (permite embutir o chat só numa página).
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_shortcode( 'fzwai_chat', array( __CLASS__, 'shortcode' ) );

		// A bolha flutuante em todas as páginas depende do widget_enabled.
		if ( FZWAI_Settings::get( 'widget_enabled', 1 ) ) {
			add_action( 'wp_footer', array( __CLASS__, 'render_float' ), 100 );
		}
	}

	/**
	 * Enfileira o CSS/JS do widget e injeta a configuração pública.
	 *
	 * @return void
	 */
	public static function enqueue() {
		wp_enqueue_style(
			self::HANDLE,
			FZWAI_URL . 'assets/css/widget.css',
			array(),
			FZWAI_VERSION
		);

		wp_enqueue_script(
			self::HANDLE,
			FZWAI_URL . 'assets/js/widget.js',
			array(),
			FZWAI_VERSION,
			true
		);

		wp_localize_script( self::HANDLE, 'FZWAI_WIDGET', self::config() );
	}

	/**
	 * Monta o objeto de configuração exposto ao JS.
	 *
	 * Tudo aqui é público (o front-end o lê). Nenhum segredo é incluído: só
	 * textos de exibição, cores, número de WhatsApp e a URL/nonce do REST.
	 *
	 * @return array
	 */
	private static function config() {
		$s = FZWAI_Settings::all();

		return array(
			'rest'      => esc_url_raw( rest_url( 'fzwai/v1/chat' ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'version'   => FZWAI_VERSION,
			'title'     => (string) self::val( $s, 'widget_title', __( 'Posso ajudar?', 'fzwordpress-ai' ) ),
			'greeting'  => (string) self::val( $s, 'widget_greeting', '' ),
			'assistant' => (string) self::val( $s, 'assistant_name', '' ),
			'business'  => (string) self::val( $s, 'business_name', get_bloginfo( 'name' ) ),
			'color'     => self::sanitize_color( self::val( $s, 'widget_color', self::DEFAULT_COLOR ) ),
			'position'  => self::sanitize_position( self::val( $s, 'widget_position', 'right' ) ),
			'whatsapp'  => self::sanitize_phone( self::val( $s, 'whatsapp_number', '' ) ),
			'i18n'      => self::i18n(),
		);
	}

	/**
	 * Strings traduzíveis usadas pela interface JS.
	 *
	 * Passadas por localize (e não hard-coded no JS) para respeitar o
	 * text domain 'fzwordpress-ai' e permitir tradução via .mo padrão.
	 *
	 * @return array
	 */
	private static function i18n() {
		return array(
			'open'          => __( 'Abrir atendimento', 'fzwordpress-ai' ),
			'close'         => __( 'Fechar', 'fzwordpress-ai' ),
			'send'          => __( 'Enviar', 'fzwordpress-ai' ),
			'placeholder'   => __( 'Escreva sua mensagem…', 'fzwordpress-ai' ),
			'message_label' => __( 'Mensagem', 'fzwordpress-ai' ),
			'online'        => __( 'Online agora', 'fzwordpress-ai' ),
			'typing'        => __( 'digitando…', 'fzwordpress-ai' ),
			'error'         => __( 'Estamos com instabilidade, tente novamente.', 'fzwordpress-ai' ),
			'need_contact'  => __( 'Para eu registrar seu atendimento, poderia me informar seu nome e telefone?', 'fzwordpress-ai' ),
			'name_label'    => __( 'Seu nome', 'fzwordpress-ai' ),
			'name_ph'       => __( 'Como podemos te chamar?', 'fzwordpress-ai' ),
			'contact_label' => __( 'Telefone / WhatsApp', 'fzwordpress-ai' ),
			'contact_ph'    => __( '(00) 00000-0000', 'fzwordpress-ai' ),
			'confirm'       => __( 'Confirmar', 'fzwordpress-ai' ),
			'protocol'      => __( 'Protocolo', 'fzwordpress-ai' ),
			'whatsapp'      => __( 'Falar no WhatsApp', 'fzwordpress-ai' ),
			'restart'       => __( 'Iniciar nova conversa', 'fzwordpress-ai' ),
			'powered'       => __( 'Atendimento inteligente', 'fzwordpress-ai' ),
		);
	}

	/**
	 * Imprime o ponto de montagem da bolha flutuante (hook wp_footer).
	 *
	 * O JS constrói toda a UI dentro deste container. Definimos o acento e a
	 * posição já no HTML para evitar "flash" antes do JS assumir.
	 *
	 * @return void
	 */
	public static function render_float() {
		// Evita duplicar quando a página já embutiu o chat via shortcode.
		if ( self::$has_inline ) {
			return;
		}

		$s        = FZWAI_Settings::all();
		$color    = self::sanitize_color( self::val( $s, 'widget_color', self::DEFAULT_COLOR ) );
		$position = self::sanitize_position( self::val( $s, 'widget_position', 'right' ) );

		printf(
			'<div id="fzwai-widget-root" class="fzwai-widget fzwai-widget--float" data-mode="float" data-position="%1$s" style="--fzwai-accent:%2$s;"></div>%3$s',
			esc_attr( $position ),
			esc_attr( $color ),
			"\n"
		);
	}

	/**
	 * Shortcode [fzwai_chat]: imprime um mount inline (chat embutido na página).
	 *
	 * Atributos suportados:
	 *   - title  : sobrescreve o título do cabeçalho.
	 *   - height : altura do painel (ex.: 520, 520px, 70vh).
	 *
	 * @param array|string $atts    Atributos do shortcode.
	 * @param string|null  $content Conteúdo interno (ignorado).
	 * @param string       $tag     Nome do shortcode.
	 * @return string HTML do mount inline.
	 */
	public static function shortcode( $atts = array(), $content = null, $tag = 'fzwai_chat' ) {
		if ( is_feed() ) {
			return '';
		}

		// Marca a página como "tem chat embutido" e garante os assets carregados.
		self::$has_inline = true;
		if ( ! wp_script_is( self::HANDLE, 'enqueued' ) ) {
			self::enqueue();
		}

		$atts = shortcode_atts(
			array(
				'title'  => '',
				'height' => '',
			),
			$atts,
			$tag
		);

		$s     = FZWAI_Settings::all();
		$color = self::sanitize_color( self::val( $s, 'widget_color', self::DEFAULT_COLOR ) );
		$style = '--fzwai-accent:' . $color . ';';

		$height = self::sanitize_length( $atts['height'] );
		if ( '' !== $height ) {
			$style .= '--fzwai-inline-height:' . $height . ';';
		}

		$title_attr = '';
		if ( '' !== trim( (string) $atts['title'] ) ) {
			$title_attr = sprintf( ' data-title="%s"', esc_attr( wp_strip_all_tags( $atts['title'] ) ) );
		}

		return sprintf(
			'<div class="fzwai-widget fzwai-widget--inline" data-mode="inline"%1$s style="%2$s"></div>',
			$title_attr,
			esc_attr( $style )
		);
	}

	/* ------------------------------------------------------------------ *
	 *  Sanitizadores / utilidades
	 * ------------------------------------------------------------------ */

	/**
	 * Lê uma chave do array de settings com fallback (evita índices ausentes).
	 *
	 * @param array  $s       Settings.
	 * @param string $key     Chave.
	 * @param mixed  $default Valor padrão.
	 * @return mixed
	 */
	private static function val( $s, $key, $default = '' ) {
		return ( isset( $s[ $key ] ) && '' !== $s[ $key ] ) ? $s[ $key ] : $default;
	}

	/**
	 * Valida uma cor hex (#rgb ou #rrggbb); devolve a padrão se inválida.
	 *
	 * Não dependemos de sanitize_hex_color() por ela não estar garantida no
	 * front-end (é definida no admin).
	 *
	 * @param string $color Cor candidata.
	 * @return string
	 */
	private static function sanitize_color( $color ) {
		$color = trim( (string) $color );
		if ( preg_match( '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $color ) ) {
			return $color;
		}
		return self::DEFAULT_COLOR;
	}

	/**
	 * Normaliza a posição para 'left' ou 'right'.
	 *
	 * @param string $pos Posição candidata.
	 * @return string
	 */
	private static function sanitize_position( $pos ) {
		return ( 'left' === $pos ) ? 'left' : 'right';
	}

	/**
	 * Reduz o número de WhatsApp a apenas dígitos (formato aceito por wa.me).
	 *
	 * @param string $phone Número candidato.
	 * @return string
	 */
	private static function sanitize_phone( $phone ) {
		return preg_replace( '/\D+/', '', (string) $phone );
	}

	/**
	 * Valida um comprimento CSS simples (px, vh, rem ou número puro → px).
	 *
	 * @param string $len Valor candidato.
	 * @return string String vazia se inválido.
	 */
	private static function sanitize_length( $len ) {
		$len = trim( (string) $len );
		if ( '' === $len ) {
			return '';
		}
		if ( preg_match( '/^\d{1,4}$/', $len ) ) {
			return $len . 'px';
		}
		if ( preg_match( '/^\d{1,4}(px|vh|rem)$/', $len ) ) {
			return $len;
		}
		return '';
	}
}
