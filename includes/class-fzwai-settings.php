<?php
/**
 * Configurações do plugin (armazenadas via options do WordPress).
 * O administrador define aqui: persona/prompt, backend de IA, modelo, WhatsApp,
 * escopo do tópico e limites de criatividade.
 *
 * @package FZWordPressAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FZWAI_Settings {

	const OPTION = 'fzwai_settings';

	public static function defaults() {
		return array(
			// Backend: ollama | llamacpp | openai
			'backend'          => 'ollama',
			'ollama_url'       => 'http://localhost:11434',
			'ollama_model'     => 'qwen2.5:1.5b',
			'ollama_num_gpu'   => '',            // vazio = automático; 0 = força CPU (útil se a GPU estiver cheia)
			'embed_model'      => '',            // vazio = usa o mesmo backend p/ embeddings, ou fallback léxico
			'llamacpp_bin'     => '',            // caminho do binário llama-server/llama-cli
			'llamacpp_model'   => '',            // caminho do .gguf
			'openai_base'      => 'https://api.openai.com/v1',
			'openai_key'       => '',
			'openai_model'     => 'gpt-4o-mini',

			// Persona / comportamento
			'assistant_name'   => 'Ana',
			'business_name'    => get_bloginfo( 'name' ),
			'topic_scope'      => 'imóveis e corretagem',
			'system_prompt'    => '',            // vazio = gerado a partir dos campos acima
			'temperature'      => '0.3',
			'max_tokens'       => '700',         // respostas mais completas (antes 350)
			'refuse_offtopic'  => 1,             // recusa educadamente assuntos fora do escopo

			// Atendimento / handoff
			'whatsapp_number'  => '5551995826179',
			'handoff_message'  => 'Seu protocolo {protocolo} foi aberto. Em alguns minutos um técnico entrará em contato com você pelo WhatsApp.',
			'ask_contact'      => 1,             // pede nome/telefone antes de abrir protocolo
			'protocol_prefix'  => 'FZ',

			// Widget
			'widget_enabled'   => 1,
			'widget_title'     => 'Posso ajudar?',
			'widget_greeting'  => 'Olá! Sou a {assistente} da {empresa}. Como posso ajudar você hoje?',
			'widget_color'     => '#2f7ec4',
			'widget_position'  => 'right',
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return array_merge( self::defaults(), $saved );
	}

	public static function get( $key, $fallback = '' ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : $fallback;
	}

	public static function update( array $values ) {
		$current = self::all();
		$merged  = array_merge( $current, $values );
		update_option( self::OPTION, $merged );
		return $merged;
	}

	public static function seed_defaults() {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, self::defaults() );
		}
	}

	/**
	 * Monta o system prompt efetivo (usa o customizado se houver).
	 */
	public static function effective_system_prompt() {
		$s = self::all();
		if ( ! empty( $s['system_prompt'] ) ) {
			return $s['system_prompt'];
		}
		$name    = $s['assistant_name'];
		$empresa = $s['business_name'];
		$escopo  = $s['topic_scope'];
		$prompt  = "Você é {$name}, atendente virtual da {$empresa}. "
			. "Fale de forma cordial, humana e profissional, como uma pessoa real do atendimento. "
			. "Seu escopo é estritamente: {$escopo}. "
			. "RESPONDA COM QUALIDADE: seja completa e bem organizada — explique o passo a passo quando fizer sentido, "
			. "use listas curtas ou tópicos para instruções, e dê o contexto necessário para a pessoa resolver sozinha. "
			. "Use frases claras; de 1 a 3 parágrafos curtos conforme o assunto exigir (não responda em uma linha só quando a pergunta pede mais). "
			. "Use SOMENTE as informações do CONTEXTO abaixo. NUNCA invente dados, preços, prazos ou promessas. "
			. "Se a informação necessária não estiver no CONTEXTO, NÃO invente: peça educadamente para a pessoa "
			. "descrever a dúvida novamente em poucas palavras. Se ainda assim não houver base para responder, "
			. "diga que aquilo é um caso de suporte e que você vai encaminhar a solicitação.";
		if ( ! empty( $s['refuse_offtopic'] ) ) {
			$prompt .= " Não fale sobre assuntos fora de {$escopo}; nesses casos, redirecione gentilmente para o tema.";
		}
		return $prompt;
	}
}
