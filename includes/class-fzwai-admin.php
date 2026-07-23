<?php
/**
 * Interface administrativa do plugin (wp-admin).
 * Registra o menu "FZ AI Atendimento", as abas (Configurações, Base de
 * conhecimento), o Settings API e os endpoints AJAX usados pelo JS.
 *
 * Regras de segurança aplicadas em todo o arquivo:
 *   - Saída sempre escapada (esc_html/esc_attr/esc_url/esc_textarea/wp_kses_post).
 *   - Entrada sempre sanitizada (sanitize_text_field/sanitize_textarea_field/absint/esc_url_raw).
 *   - Nonce verificado em todo formulário e chamada AJAX.
 *   - Capacidade `manage_options` exigida em toda operação.
 *   - Acesso ao banco somente via PDO com prepared statements.
 *
 * @package FZWordPressAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FZWAI_Admin {

	/** Slug do menu de topo (também é o slug da aba Configurações). */
	const MENU_SLUG = 'fzwai';

	/** Ação do nonce compartilhado por formulários e AJAX. */
	const NONCE = 'fzwai_admin';

	/** Grupo do Settings API. */
	const GROUP = 'fzwai_settings_group';

	/** Sufixos de hook das nossas páginas (para restringir o enqueue). */
	private static $hooks = array();

	/**
	 * Ponto de entrada chamado pelo plugin principal.
	 */
	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );

		// Endpoints AJAX (todos admin-only, nonce + capacidade verificados).
		add_action( 'wp_ajax_fzwai_test_backend', array( __CLASS__, 'ajax_test_backend' ) );
		add_action( 'wp_ajax_fzwai_add_source', array( __CLASS__, 'ajax_add_source' ) );
		add_action( 'wp_ajax_fzwai_index_source', array( __CLASS__, 'ajax_index_source' ) );
		add_action( 'wp_ajax_fzwai_reindex_all', array( __CLASS__, 'ajax_reindex_all' ) );
		add_action( 'wp_ajax_fzwai_delete_source', array( __CLASS__, 'ajax_delete_source' ) );
	}

	/* ------------------------------------------------------------------ Menu */

	public static function register_menu() {
		$cap = 'manage_options';

		self::$hooks['main'] = add_menu_page(
			__( 'FZ AI Atendimento', 'fzwordpress-ai' ),
			__( 'FZ AI Atendimento', 'fzwordpress-ai' ),
			$cap,
			self::MENU_SLUG,
			array( __CLASS__, 'render_settings_page' ),
			'dashicons-format-chat',
			58
		);

		self::$hooks['settings'] = add_submenu_page(
			self::MENU_SLUG,
			__( 'Configurações', 'fzwordpress-ai' ),
			__( 'Configurações', 'fzwordpress-ai' ),
			$cap,
			self::MENU_SLUG,
			array( __CLASS__, 'render_settings_page' )
		);

		self::$hooks['knowledge'] = add_submenu_page(
			self::MENU_SLUG,
			__( 'Base de conhecimento', 'fzwordpress-ai' ),
			__( 'Base de conhecimento', 'fzwordpress-ai' ),
			$cap,
			self::MENU_SLUG . '-knowledge',
			array( __CLASS__, 'render_knowledge_page' )
		);
	}

	/* --------------------------------------------------------------- Assets */

	public static function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, self::$hooks, true ) ) {
			return;
		}

		wp_enqueue_style(
			'fzwai-admin',
			FZWAI_URL . 'assets/css/admin.css',
			array(),
			FZWAI_VERSION
		);

		wp_enqueue_script(
			'fzwai-admin',
			FZWAI_URL . 'assets/js/admin.js',
			array(),
			FZWAI_VERSION,
			true
		);

		wp_localize_script(
			'fzwai-admin',
			'FZWAI_ADMIN',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( self::NONCE ),
				'i18n'     => array(
					'testing'         => __( 'Testando conexão…', 'fzwordpress-ai' ),
					'indexing'        => __( 'Indexando…', 'fzwordpress-ai' ),
					'working'         => __( 'Processando…', 'fzwordpress-ai' ),
					'confirm_delete'  => __( 'Remover esta fonte e todos os trechos indexados dela?', 'fzwordpress-ai' ),
					'confirm_reindex' => __( 'Reindexar todas as fontes agora? Isso pode levar alguns minutos.', 'fzwordpress-ai' ),
					'error'           => __( 'Ocorreu um erro.', 'fzwordpress-ai' ),
					'network_error'   => __( 'Falha de comunicação com o servidor.', 'fzwordpress-ai' ),
					'ok'              => __( 'Conexão OK', 'fzwordpress-ai' ),
					'chunks'          => __( 'trechos', 'fzwordpress-ai' ),
				),
				'labels'   => array(
					'pending' => __( 'pendente', 'fzwordpress-ai' ),
					'indexed' => __( 'indexado', 'fzwordpress-ai' ),
					'error'   => __( 'erro', 'fzwordpress-ai' ),
				),
			)
		);
	}

	/* ------------------------------------------------------ Settings API */

	public static function register_settings() {
		register_setting(
			self::GROUP,
			FZWAI_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => FZWAI_Settings::defaults(),
			)
		);
	}

	/**
	 * Sanitiza TODOS os campos do formulário de configurações.
	 * O options.php já aplica wp_unslash antes de chamar este callback.
	 *
	 * @param mixed $input Valor cru do POST (array esperado).
	 * @return array
	 */
	public static function sanitize_settings( $input ) {
		// Defesa em profundidade: sem capacidade, não altera nada.
		if ( ! current_user_can( 'manage_options' ) ) {
			return FZWAI_Settings::all();
		}

		$in  = is_array( $input ) ? $input : array();
		$out = FZWAI_Settings::all(); // Parte do estado atual e sobrescreve o que é conhecido.

		// --- Backend de IA ---
		$backend = isset( $in['backend'] ) ? sanitize_key( $in['backend'] ) : $out['backend'];
		if ( ! in_array( $backend, array( 'ollama', 'llamacpp', 'openai' ), true ) ) {
			$backend = 'ollama';
		}
		$out['backend'] = $backend;

		$out['ollama_url']     = isset( $in['ollama_url'] ) ? esc_url_raw( trim( $in['ollama_url'] ) ) : $out['ollama_url'];
		$out['ollama_model']   = isset( $in['ollama_model'] ) ? sanitize_text_field( $in['ollama_model'] ) : $out['ollama_model'];
		$out['embed_model']    = isset( $in['embed_model'] ) ? sanitize_text_field( $in['embed_model'] ) : $out['embed_model'];
		$out['llamacpp_bin']   = isset( $in['llamacpp_bin'] ) ? sanitize_text_field( $in['llamacpp_bin'] ) : $out['llamacpp_bin'];
		$out['llamacpp_model'] = isset( $in['llamacpp_model'] ) ? sanitize_text_field( $in['llamacpp_model'] ) : $out['llamacpp_model'];
		$out['openai_base']    = isset( $in['openai_base'] ) ? esc_url_raw( trim( $in['openai_base'] ) ) : $out['openai_base'];
		$out['openai_key']     = isset( $in['openai_key'] ) ? sanitize_text_field( $in['openai_key'] ) : $out['openai_key'];
		$out['openai_model']   = isset( $in['openai_model'] ) ? sanitize_text_field( $in['openai_model'] ) : $out['openai_model'];

		// --- Persona / comportamento ---
		$out['assistant_name'] = isset( $in['assistant_name'] ) ? sanitize_text_field( $in['assistant_name'] ) : $out['assistant_name'];
		$out['business_name']  = isset( $in['business_name'] ) ? sanitize_text_field( $in['business_name'] ) : $out['business_name'];
		$out['topic_scope']    = isset( $in['topic_scope'] ) ? sanitize_text_field( $in['topic_scope'] ) : $out['topic_scope'];
		$out['system_prompt']  = isset( $in['system_prompt'] ) ? sanitize_textarea_field( $in['system_prompt'] ) : $out['system_prompt'];

		$temp = isset( $in['temperature'] ) ? (float) $in['temperature'] : (float) $out['temperature'];
		$temp = max( 0.0, min( 2.0, $temp ) );
		$out['temperature'] = (string) $temp;

		$maxtok = isset( $in['max_tokens'] ) ? absint( $in['max_tokens'] ) : (int) $out['max_tokens'];
		$maxtok = max( 1, min( 8192, $maxtok ) );
		$out['max_tokens'] = (string) $maxtok;

		$out['refuse_offtopic'] = empty( $in['refuse_offtopic'] ) ? 0 : 1;

		// --- Atendimento / handoff ---
		$out['whatsapp_number'] = isset( $in['whatsapp_number'] ) ? preg_replace( '/\D+/', '', $in['whatsapp_number'] ) : $out['whatsapp_number'];
		$out['handoff_message'] = isset( $in['handoff_message'] ) ? sanitize_textarea_field( $in['handoff_message'] ) : $out['handoff_message'];
		$out['ask_contact']     = empty( $in['ask_contact'] ) ? 0 : 1;
		$out['protocol_prefix'] = isset( $in['protocol_prefix'] ) ? sanitize_text_field( $in['protocol_prefix'] ) : $out['protocol_prefix'];

		// --- Widget ---
		$out['widget_enabled']  = empty( $in['widget_enabled'] ) ? 0 : 1;
		$out['widget_title']    = isset( $in['widget_title'] ) ? sanitize_text_field( $in['widget_title'] ) : $out['widget_title'];
		$out['widget_greeting'] = isset( $in['widget_greeting'] ) ? sanitize_textarea_field( $in['widget_greeting'] ) : $out['widget_greeting'];

		$color = isset( $in['widget_color'] ) ? sanitize_hex_color( $in['widget_color'] ) : $out['widget_color'];
		$out['widget_color'] = $color ? $color : $out['widget_color'];

		$pos = isset( $in['widget_position'] ) ? sanitize_key( $in['widget_position'] ) : $out['widget_position'];
		$out['widget_position'] = in_array( $pos, array( 'left', 'right' ), true ) ? $pos : 'right';

		add_settings_error( 'fzwai_settings', 'fzwai_saved', __( 'Configurações salvas.', 'fzwordpress-ai' ), 'updated' );

		return $out;
	}

	/* ------------------------------------------------------ Página: Config */

	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = FZWAI_Settings::all();
		?>
		<div class="wrap fzwai-wrap">
			<h1 class="fzwai-title">
				<span class="dashicons dashicons-format-chat"></span>
				<?php esc_html_e( 'FZ AI Atendimento', 'fzwordpress-ai' ); ?>
			</h1>
			<?php
			self::render_tabs( self::MENU_SLUG );
			settings_errors( 'fzwai_settings' );
			?>

			<form method="post" action="options.php" class="fzwai-form">
				<?php settings_fields( self::GROUP ); ?>

				<!-- Backend de IA -->
				<div class="fzwai-card">
					<h2><?php esc_html_e( 'Backend de IA', 'fzwordpress-ai' ); ?></h2>
					<p class="fzwai-card-desc"><?php esc_html_e( 'Escolha o motor que gera as respostas. Salve antes de testar a conexão.', 'fzwordpress-ai' ); ?></p>

					<div class="fzwai-radios">
						<?php
						$backends = array(
							'ollama'   => __( 'Ollama (servidor local)', 'fzwordpress-ai' ),
							'llamacpp' => __( 'llama.cpp (binário embarcado)', 'fzwordpress-ai' ),
							'openai'   => __( 'API online (compatível OpenAI)', 'fzwordpress-ai' ),
						);
						foreach ( $backends as $val => $label ) {
							?>
							<label class="fzwai-radio">
								<input type="radio" class="fzwai-backend-radio"
									name="<?php echo esc_attr( self::field( 'backend' ) ); ?>"
									value="<?php echo esc_attr( $val ); ?>" <?php checked( $s['backend'], $val ); ?> />
								<span><?php echo esc_html( $label ); ?></span>
							</label>
							<?php
						}
						?>
					</div>

					<div class="fzwai-backend-group<?php echo 'ollama' === $s['backend'] ? '' : ' is-hidden'; ?>" data-backend="ollama">
						<table class="form-table" role="presentation">
							<?php
							self::text_row( 'ollama_url', __( 'URL do Ollama', 'fzwordpress-ai' ), $s['ollama_url'], 'url', 'https://localhost:11434' );
							self::text_row( 'ollama_model', __( 'Modelo de chat', 'fzwordpress-ai' ), $s['ollama_model'], 'text', 'qwen3.5:latest' );
							?>
						</table>
					</div>

					<div class="fzwai-backend-group<?php echo 'llamacpp' === $s['backend'] ? '' : ' is-hidden'; ?>" data-backend="llamacpp">
						<table class="form-table" role="presentation">
							<?php
							self::text_row( 'llamacpp_bin', __( 'Binário llama.cpp', 'fzwordpress-ai' ), $s['llamacpp_bin'], 'text', '/opt/llama/llama-cli', __( 'Caminho absoluto do executável llama-cli (NÃO o llama-server — este adaptador executa o binário por linha de comando; para o llama-server use o backend OpenAI-compatível apontando para http://127.0.0.1:8080/v1).', 'fzwordpress-ai' ) );
							self::text_row( 'llamacpp_model', __( 'Modelo GGUF', 'fzwordpress-ai' ), $s['llamacpp_model'], 'text', '/opt/llama/model.gguf', __( 'Caminho absoluto do arquivo .gguf.', 'fzwordpress-ai' ) );
							?>
						</table>
					</div>

					<div class="fzwai-backend-group<?php echo 'openai' === $s['backend'] ? '' : ' is-hidden'; ?>" data-backend="openai">
						<table class="form-table" role="presentation">
							<?php
							self::text_row( 'openai_base', __( 'Endpoint base', 'fzwordpress-ai' ), $s['openai_base'], 'url', 'https://api.openai.com/v1' );
							self::text_row( 'openai_key', __( 'Chave de API', 'fzwordpress-ai' ), $s['openai_key'], 'password', 'sk-…', __( 'Guardada nas opções do site. Deixe em branco para endpoints sem autenticação.', 'fzwordpress-ai' ) );
							self::text_row( 'openai_model', __( 'Modelo', 'fzwordpress-ai' ), $s['openai_model'], 'text', 'gpt-4o-mini' );
							?>
						</table>
					</div>

					<table class="form-table" role="presentation">
						<?php self::text_row( 'embed_model', __( 'Modelo de embeddings', 'fzwordpress-ai' ), $s['embed_model'], 'text', '', __( 'Opcional. Vazio = usa o backend ativo ou o fallback léxico para indexar a base.', 'fzwordpress-ai' ) ); ?>
					</table>

					<p class="fzwai-test-line">
						<button type="button" class="button button-secondary" id="fzwai-test-backend">
							<span class="dashicons dashicons-admin-plugins"></span>
							<?php esc_html_e( 'Testar conexão', 'fzwordpress-ai' ); ?>
						</button>
						<span id="fzwai-test-result" class="fzwai-inline-result" role="status" aria-live="polite"></span>
					</p>
				</div>

				<!-- Persona -->
				<div class="fzwai-card">
					<h2><?php esc_html_e( 'Persona e comportamento', 'fzwordpress-ai' ); ?></h2>
					<table class="form-table" role="presentation">
						<?php
						self::text_row( 'assistant_name', __( 'Nome do assistente', 'fzwordpress-ai' ), $s['assistant_name'] );
						self::text_row( 'business_name', __( 'Nome da empresa', 'fzwordpress-ai' ), $s['business_name'] );
						self::text_row( 'topic_scope', __( 'Escopo dos assuntos', 'fzwordpress-ai' ), $s['topic_scope'], 'text', 'imóveis e corretagem', __( 'Do que o atendente pode falar.', 'fzwordpress-ai' ) );
						?>
						<tr>
							<th scope="row"><label for="fzwai-system_prompt"><?php esc_html_e( 'Prompt de sistema', 'fzwordpress-ai' ); ?></label></th>
							<td>
								<textarea id="fzwai-system_prompt" name="<?php echo esc_attr( self::field( 'system_prompt' ) ); ?>" rows="5" class="large-text code" placeholder="<?php esc_attr_e( 'Deixe em branco para gerar automaticamente a partir dos campos acima.', 'fzwordpress-ai' ); ?>"><?php echo esc_textarea( $s['system_prompt'] ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Instrução mestra enviada ao modelo. Vazio = gerada a partir do nome, empresa e escopo.', 'fzwordpress-ai' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="fzwai-temperature"><?php esc_html_e( 'Temperatura', 'fzwordpress-ai' ); ?></label></th>
							<td>
								<input type="number" id="fzwai-temperature" name="<?php echo esc_attr( self::field( 'temperature' ) ); ?>" value="<?php echo esc_attr( $s['temperature'] ); ?>" step="0.1" min="0" max="2" class="small-text" />
								<p class="description"><?php esc_html_e( 'Criatividade (0 = objetivo, 2 = livre). Recomendado 0.2.', 'fzwordpress-ai' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="fzwai-max_tokens"><?php esc_html_e( 'Máx. de tokens', 'fzwordpress-ai' ); ?></label></th>
							<td>
								<input type="number" id="fzwai-max_tokens" name="<?php echo esc_attr( self::field( 'max_tokens' ) ); ?>" value="<?php echo esc_attr( $s['max_tokens'] ); ?>" step="1" min="1" max="8192" class="small-text" />
								<p class="description"><?php esc_html_e( 'Tamanho máximo da resposta gerada.', 'fzwordpress-ai' ); ?></p>
							</td>
						</tr>
						<?php self::checkbox_row( 'refuse_offtopic', __( 'Recusar assuntos fora do escopo', 'fzwordpress-ai' ), $s['refuse_offtopic'], __( 'Recusa educadamente perguntas que fogem do escopo definido.', 'fzwordpress-ai' ) ); ?>
					</table>
				</div>

				<!-- Atendimento -->
				<div class="fzwai-card">
					<h2><?php esc_html_e( 'Atendimento e encaminhamento', 'fzwordpress-ai' ); ?></h2>
					<table class="form-table" role="presentation">
						<?php self::text_row( 'whatsapp_number', __( 'WhatsApp (só números)', 'fzwordpress-ai' ), $s['whatsapp_number'], 'text', '5551999999999', __( 'Com código do país e DDD, apenas dígitos.', 'fzwordpress-ai' ) ); ?>
						<tr>
							<th scope="row"><label for="fzwai-handoff_message"><?php esc_html_e( 'Mensagem de handoff', 'fzwordpress-ai' ); ?></label></th>
							<td>
								<textarea id="fzwai-handoff_message" name="<?php echo esc_attr( self::field( 'handoff_message' ) ); ?>" rows="3" class="large-text"><?php echo esc_textarea( $s['handoff_message'] ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Use {protocolo} para inserir o número do protocolo.', 'fzwordpress-ai' ); ?></p>
							</td>
						</tr>
						<?php
						self::text_row( 'protocol_prefix', __( 'Prefixo do protocolo', 'fzwordpress-ai' ), $s['protocol_prefix'], 'text', 'FZ' );
						self::checkbox_row( 'ask_contact', __( 'Pedir contato antes de abrir protocolo', 'fzwordpress-ai' ), $s['ask_contact'], __( 'Solicita nome e telefone do visitante antes do encaminhamento.', 'fzwordpress-ai' ) );
						?>
					</table>
				</div>

				<!-- Widget -->
				<div class="fzwai-card">
					<h2><?php esc_html_e( 'Widget de chat', 'fzwordpress-ai' ); ?></h2>
					<table class="form-table" role="presentation">
						<?php self::checkbox_row( 'widget_enabled', __( 'Exibir o widget no site', 'fzwordpress-ai' ), $s['widget_enabled'], __( 'Mostra a bolha de chat nas páginas públicas.', 'fzwordpress-ai' ) ); ?>
						<?php self::text_row( 'widget_title', __( 'Título do widget', 'fzwordpress-ai' ), $s['widget_title'] ); ?>
						<tr>
							<th scope="row"><label for="fzwai-widget_greeting"><?php esc_html_e( 'Saudação', 'fzwordpress-ai' ); ?></label></th>
							<td>
								<textarea id="fzwai-widget_greeting" name="<?php echo esc_attr( self::field( 'widget_greeting' ) ); ?>" rows="2" class="large-text"><?php echo esc_textarea( $s['widget_greeting'] ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Use {assistente} e {empresa} como marcadores.', 'fzwordpress-ai' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="fzwai-widget_color-text"><?php esc_html_e( 'Cor de destaque', 'fzwordpress-ai' ); ?></label></th>
							<td>
								<span class="fzwai-color-wrap">
									<input type="color" id="fzwai-widget_color" class="fzwai-color" value="<?php echo esc_attr( $s['widget_color'] ); ?>" aria-label="<?php esc_attr_e( 'Seletor de cor', 'fzwordpress-ai' ); ?>" />
									<input type="text" id="fzwai-widget_color-text" name="<?php echo esc_attr( self::field( 'widget_color' ) ); ?>" value="<?php echo esc_attr( $s['widget_color'] ); ?>" class="regular-text code fzwai-color-text" data-color-text="fzwai-widget_color" maxlength="7" pattern="#[0-9A-Fa-f]{6}" />
								</span>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="fzwai-widget_position"><?php esc_html_e( 'Posição', 'fzwordpress-ai' ); ?></label></th>
							<td>
								<select id="fzwai-widget_position" name="<?php echo esc_attr( self::field( 'widget_position' ) ); ?>">
									<option value="right" <?php selected( $s['widget_position'], 'right' ); ?>><?php esc_html_e( 'Direita', 'fzwordpress-ai' ); ?></option>
									<option value="left" <?php selected( $s['widget_position'], 'left' ); ?>><?php esc_html_e( 'Esquerda', 'fzwordpress-ai' ); ?></option>
								</select>
							</td>
						</tr>
					</table>
				</div>

				<?php submit_button( __( 'Salvar configurações', 'fzwordpress-ai' ) ); ?>
			</form>
		</div>
		<?php
	}

	/* --------------------------------------------------- Página: Base RAG */

	public static function render_knowledge_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$db      = self::db();
		$sources = array();
		$chunks  = 0;

		if ( $db ) {
			$stmt = $db->prepare( 'SELECT id, type, label, location, status, last_error, chunk_count, indexed_at, created_at FROM fzwai_sources ORDER BY id DESC' );
			$stmt->execute();
			$sources = $stmt->fetchAll();

			$cstmt = $db->prepare( 'SELECT COALESCE(SUM(chunk_count),0) FROM fzwai_sources' );
			$cstmt->execute();
			$chunks = (int) $cstmt->fetchColumn();
		}
		?>
		<div class="wrap fzwai-wrap">
			<h1 class="fzwai-title">
				<span class="dashicons dashicons-format-chat"></span>
				<?php esc_html_e( 'FZ AI Atendimento', 'fzwordpress-ai' ); ?>
			</h1>
			<?php self::render_tabs( self::MENU_SLUG . '-knowledge' ); ?>

			<?php if ( ! $db ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'PDO SQLite indisponível neste servidor. A base de conhecimento não pode ser usada.', 'fzwordpress-ai' ); ?></p></div>
			<?php else : ?>

				<div class="fzwai-stats">
					<div class="fzwai-stat"><span class="fzwai-stat-num"><?php echo esc_html( number_format_i18n( count( $sources ) ) ); ?></span><span class="fzwai-stat-label"><?php esc_html_e( 'fontes', 'fzwordpress-ai' ); ?></span></div>
					<div class="fzwai-stat"><span class="fzwai-stat-num"><?php echo esc_html( number_format_i18n( $chunks ) ); ?></span><span class="fzwai-stat-label"><?php esc_html_e( 'trechos indexados', 'fzwordpress-ai' ); ?></span></div>
				</div>

				<div class="fzwai-card">
					<h2><?php esc_html_e( 'Adicionar fonte', 'fzwordpress-ai' ); ?></h2>
					<form id="fzwai-add-source-form" class="fzwai-add-form">
						<div class="fzwai-field">
							<label for="fzwai-source-type"><?php esc_html_e( 'Tipo', 'fzwordpress-ai' ); ?></label>
							<select id="fzwai-source-type" name="type">
								<option value="url"><?php esc_html_e( 'URL (página web)', 'fzwordpress-ai' ); ?></option>
								<option value="file"><?php esc_html_e( 'Arquivo (caminho no servidor)', 'fzwordpress-ai' ); ?></option>
								<option value="text"><?php esc_html_e( 'Texto colado', 'fzwordpress-ai' ); ?></option>
							</select>
						</div>
						<div class="fzwai-field">
							<label for="fzwai-source-label"><?php esc_html_e( 'Rótulo', 'fzwordpress-ai' ); ?></label>
							<input type="text" id="fzwai-source-label" name="label" class="regular-text" placeholder="<?php esc_attr_e( 'Ex.: Perguntas frequentes', 'fzwordpress-ai' ); ?>" />
						</div>
						<div class="fzwai-field fzwai-loc-field">
							<label for="fzwai-source-location"><?php esc_html_e( 'URL / caminho', 'fzwordpress-ai' ); ?></label>
							<input type="text" id="fzwai-source-location" name="location" class="regular-text" placeholder="https://exemplo.com/faq" />
						</div>
						<div class="fzwai-field fzwai-text-field is-hidden">
							<label for="fzwai-source-content"><?php esc_html_e( 'Conteúdo', 'fzwordpress-ai' ); ?></label>
							<textarea id="fzwai-source-content" name="content" rows="5" class="large-text" placeholder="<?php esc_attr_e( 'Cole aqui o texto que o atendente deve conhecer…', 'fzwordpress-ai' ); ?>"></textarea>
						</div>
						<div class="fzwai-actions">
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Adicionar', 'fzwordpress-ai' ); ?></button>
							<span class="fzwai-inline-result" role="status" aria-live="polite"></span>
						</div>
					</form>
				</div>

				<div class="fzwai-card">
					<div class="fzwai-card-head">
						<h2><?php esc_html_e( 'Fontes cadastradas', 'fzwordpress-ai' ); ?></h2>
						<button type="button" class="button" data-fzwai-action="reindex">
							<span class="dashicons dashicons-update"></span>
							<?php esc_html_e( 'Reindexar tudo', 'fzwordpress-ai' ); ?>
						</button>
					</div>

					<table class="wp-list-table widefat fixed striped fzwai-sources">
						<thead>
							<tr>
								<th class="column-primary"><?php esc_html_e( 'Rótulo', 'fzwordpress-ai' ); ?></th>
								<th><?php esc_html_e( 'Tipo', 'fzwordpress-ai' ); ?></th>
								<th><?php esc_html_e( 'Status', 'fzwordpress-ai' ); ?></th>
								<th><?php esc_html_e( 'Trechos', 'fzwordpress-ai' ); ?></th>
								<th><?php esc_html_e( 'Indexado em', 'fzwordpress-ai' ); ?></th>
								<th><?php esc_html_e( 'Ações', 'fzwordpress-ai' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php if ( empty( $sources ) ) : ?>
							<tr class="fzwai-empty-row"><td colspan="6"><?php esc_html_e( 'Nenhuma fonte cadastrada ainda.', 'fzwordpress-ai' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $sources as $src ) : ?>
								<tr data-source-id="<?php echo esc_attr( (int) $src['id'] ); ?>">
									<td class="column-primary">
										<strong><?php echo esc_html( '' !== $src['label'] ? $src['label'] : __( '(sem rótulo)', 'fzwordpress-ai' ) ); ?></strong>
										<?php if ( '' !== $src['location'] && 'text' !== $src['type'] ) : ?>
											<div class="fzwai-muted"><?php echo esc_html( self::shorten( $src['location'], 70 ) ); ?></div>
										<?php endif; ?>
										<?php if ( 'error' === $src['status'] && ! empty( $src['last_error'] ) ) : ?>
											<div class="fzwai-error-detail"><?php echo esc_html( self::shorten( $src['last_error'], 140 ) ); ?></div>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( $src['type'] ); ?></td>
									<td class="fzwai-status-cell"><?php echo self::status_pill( $src['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup escapado no método. ?></td>
									<td class="fzwai-chunk-cell"><?php echo esc_html( number_format_i18n( (int) $src['chunk_count'] ) ); ?></td>
									<td><?php echo esc_html( $src['indexed_at'] ? self::local_date( $src['indexed_at'] ) : '—' ); ?></td>
									<td class="fzwai-row-actions">
										<button type="button" class="button button-small" data-fzwai-action="index"><?php esc_html_e( 'Indexar', 'fzwordpress-ai' ); ?></button>
										<button type="button" class="button button-small button-link-delete" data-fzwai-action="delete"><?php esc_html_e( 'Remover', 'fzwordpress-ai' ); ?></button>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
						</tbody>
					</table>
				</div>

			<?php endif; ?>
		</div>
		<?php
	}

	/* --------------------------------------------------------------- AJAX */

	public static function ajax_test_backend() {
		self::verify_ajax();
		$res = FZWAI_LLM::ping();
		if ( ! empty( $res['ok'] ) ) {
			wp_send_json_success( array( 'detail' => (string) $res['detail'] ) );
		}
		wp_send_json_error( array( 'detail' => (string) $res['detail'] ) );
	}

	public static function ajax_add_source() {
		self::verify_ajax();
		$db = self::db();
		if ( ! $db ) {
			wp_send_json_error( array( 'message' => __( 'Banco de dados indisponível.', 'fzwordpress-ai' ) ) );
		}

		$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		if ( ! in_array( $type, array( 'url', 'file', 'text' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Tipo de fonte inválido.', 'fzwordpress-ai' ) ) );
		}

		$label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';

		if ( 'text' === $type ) {
			$location = isset( $_POST['content'] ) ? sanitize_textarea_field( wp_unslash( $_POST['content'] ) ) : '';
		} elseif ( 'url' === $type ) {
			$location = isset( $_POST['location'] ) ? esc_url_raw( trim( wp_unslash( $_POST['location'] ) ) ) : '';
		} else {
			$location = isset( $_POST['location'] ) ? sanitize_text_field( wp_unslash( $_POST['location'] ) ) : '';
		}

		if ( '' === $location ) {
			wp_send_json_error( array( 'message' => __( 'Informe a URL, o caminho ou o conteúdo da fonte.', 'fzwordpress-ai' ) ) );
		}
		if ( '' === $label ) {
			$label = 'url' === $type ? $location : __( 'Fonte', 'fzwordpress-ai' );
		}

		try {
			$stmt = $db->prepare(
				'INSERT INTO fzwai_sources (type, label, location, status, chunk_count, created_at)
				 VALUES (:type, :label, :location, :status, 0, :created)'
			);
			$stmt->execute(
				array(
					':type'     => $type,
					':label'    => $label,
					':location' => $location,
					':status'   => 'pending',
					':created'  => FZWAI_DB::now(),
				)
			);
			$id = (int) $db->lastInsertId();
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => __( 'Não foi possível salvar a fonte.', 'fzwordpress-ai' ) ) );
		}

		wp_send_json_success(
			array(
				'id'      => $id,
				'message' => __( 'Fonte adicionada.', 'fzwordpress-ai' ),
			)
		);
	}

	public static function ajax_index_source() {
		self::verify_ajax();
		$id = isset( $_POST['source_id'] ) ? absint( wp_unslash( $_POST['source_id'] ) ) : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Fonte inválida.', 'fzwordpress-ai' ) ) );
		}
		if ( ! class_exists( 'FZWAI_RAG' ) ) {
			wp_send_json_error( array( 'message' => __( 'Módulo de indexação (RAG) indisponível.', 'fzwordpress-ai' ) ) );
		}

		$res = FZWAI_RAG::ingest_source( $id );
		$row = self::source_state( $id );

		if ( empty( $res['ok'] ) ) {
			wp_send_json_error(
				array_merge(
					$row,
					array( 'message' => isset( $res['error'] ) ? (string) $res['error'] : __( 'Falha ao indexar.', 'fzwordpress-ai' ) )
				)
			);
		}

		wp_send_json_success(
			array_merge(
				$row,
				array(
					'chunks'  => isset( $res['chunks'] ) ? (int) $res['chunks'] : (int) $row['chunk_count'],
					'message' => __( 'Fonte indexada.', 'fzwordpress-ai' ),
				)
			)
		);
	}

	public static function ajax_reindex_all() {
		self::verify_ajax();
		if ( ! class_exists( 'FZWAI_RAG' ) ) {
			wp_send_json_error( array( 'message' => __( 'Módulo de indexação (RAG) indisponível.', 'fzwordpress-ai' ) ) );
		}
		$summary = FZWAI_RAG::reindex_all();
		wp_send_json_success( array( 'summary' => $summary ) );
	}

	public static function ajax_delete_source() {
		self::verify_ajax();
		$db = self::db();
		if ( ! $db ) {
			wp_send_json_error( array( 'message' => __( 'Banco de dados indisponível.', 'fzwordpress-ai' ) ) );
		}
		$id = isset( $_POST['source_id'] ) ? absint( wp_unslash( $_POST['source_id'] ) ) : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Fonte inválida.', 'fzwordpress-ai' ) ) );
		}

		try {
			// Remove os trechos primeiro (segurança caso FK não esteja ativa).
			$c = $db->prepare( 'DELETE FROM fzwai_chunks WHERE source_id = :id' );
			$c->execute( array( ':id' => $id ) );
			$s = $db->prepare( 'DELETE FROM fzwai_sources WHERE id = :id' );
			$s->execute( array( ':id' => $id ) );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => __( 'Não foi possível remover a fonte.', 'fzwordpress-ai' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Fonte removida.', 'fzwordpress-ai' ) ) );
	}

	/* ------------------------------------------------------------- Helpers */

	/**
	 * Verifica capacidade + nonce em toda chamada AJAX. Encerra com JSON em erro.
	 */
	private static function verify_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permissão negada.', 'fzwordpress-ai' ) ), 403 );
		}
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Sessão expirada. Recarregue a página.', 'fzwordpress-ai' ) ), 403 );
		}
	}

	/**
	 * Retorna o PDO da base ou null se indisponível.
	 *
	 * @return PDO|null
	 */
	private static function db() {
		if ( ! class_exists( 'FZWAI_DB' ) || ! FZWAI_DB::available() ) {
			return null;
		}
		try {
			return FZWAI_DB::instance()->pdo();
		} catch ( Exception $e ) {
			return null;
		}
	}

	/**
	 * Lê o estado atual de uma fonte para devolver ao JS após indexar.
	 *
	 * @param int $id
	 * @return array
	 */
	private static function source_state( $id ) {
		$db = self::db();
		$out = array(
			'status'      => 'error',
			'status_label' => __( 'erro', 'fzwordpress-ai' ),
			'chunk_count' => 0,
			'indexed_at'  => '',
			'last_error'  => '',
		);
		if ( ! $db ) {
			return $out;
		}
		$stmt = $db->prepare( 'SELECT status, chunk_count, indexed_at, last_error FROM fzwai_sources WHERE id = :id' );
		$stmt->execute( array( ':id' => (int) $id ) );
		$row = $stmt->fetch();
		if ( $row ) {
			$labels = array(
				'pending' => __( 'pendente', 'fzwordpress-ai' ),
				'indexed' => __( 'indexado', 'fzwordpress-ai' ),
				'error'   => __( 'erro', 'fzwordpress-ai' ),
			);
			$out['status']       = $row['status'];
			$out['status_label'] = isset( $labels[ $row['status'] ] ) ? $labels[ $row['status'] ] : $row['status'];
			$out['chunk_count']  = (int) $row['chunk_count'];
			$out['indexed_at']   = $row['indexed_at'] ? self::local_date( $row['indexed_at'] ) : '';
			$out['last_error']   = (string) $row['last_error'];
		}
		return $out;
	}

	/**
	 * Nome do campo dentro da opção de settings.
	 */
	private static function field( $key ) {
		return FZWAI_Settings::OPTION . '[' . $key . ']';
	}

	/**
	 * Linha padrão de campo de texto no form-table.
	 */
	private static function text_row( $key, $label, $value, $type = 'text', $placeholder = '', $desc = '' ) {
		$id = 'fzwai-' . $key;
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input type="<?php echo esc_attr( $type ); ?>" id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( self::field( $key ) ); ?>"
					value="<?php echo esc_attr( $value ); ?>"
					class="regular-text"
					<?php if ( '' !== $placeholder ) : ?>placeholder="<?php echo esc_attr( $placeholder ); ?>"<?php endif; ?>
					autocomplete="off" />
				<?php if ( '' !== $desc ) : ?>
					<p class="description"><?php echo esc_html( $desc ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Linha padrão de checkbox 0/1 no form-table.
	 */
	private static function checkbox_row( $key, $label, $value, $desc = '' ) {
		$id = 'fzwai-' . $key;
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td>
				<label for="<?php echo esc_attr( $id ); ?>">
					<input type="checkbox" id="<?php echo esc_attr( $id ); ?>"
						name="<?php echo esc_attr( self::field( $key ) ); ?>"
						value="1" <?php checked( ! empty( $value ) ); ?> />
					<?php echo '' !== $desc ? esc_html( $desc ) : esc_html( $label ); ?>
				</label>
			</td>
		</tr>
		<?php
	}

	/**
	 * Navegação em abas (nav-tab-wrapper) compartilhada pelas três páginas.
	 */
	private static function render_tabs( $current ) {
		$tabs = array(
			self::MENU_SLUG               => __( 'Configurações', 'fzwordpress-ai' ),
			self::MENU_SLUG . '-knowledge' => __( 'Base de conhecimento', 'fzwordpress-ai' ),
		);
		echo '<nav class="nav-tab-wrapper fzwai-tabs">';
		foreach ( $tabs as $slug => $label ) {
			$url    = add_query_arg( 'page', $slug, admin_url( 'admin.php' ) );
			$active = ( $current === $slug ) ? ' nav-tab-active' : '';
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( $url ),
				esc_attr( $active ),
				esc_html( $label )
			);
		}
		echo '</nav>';
	}

	/**
	 * Pílula de status de fonte (pending/indexed/error). Retorna markup escapado.
	 */
	private static function status_pill( $status ) {
		$status = $status ? $status : 'pending';
		$labels = array(
			'pending' => __( 'pendente', 'fzwordpress-ai' ),
			'indexed' => __( 'indexado', 'fzwordpress-ai' ),
			'error'   => __( 'erro', 'fzwordpress-ai' ),
		);
		$label = isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
		return '<span class="fzwai-pill fzwai-pill-' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Formata um timestamp UTC (armazenado como "Y-m-d H:i:s") no fuso do site.
	 */
	private static function local_date( $utc ) {
		if ( empty( $utc ) ) {
			return '';
		}
		$ts = strtotime( $utc . ' UTC' );
		if ( ! $ts ) {
			return $utc;
		}
		return wp_date( 'd/m/Y H:i', $ts );
	}

	/**
	 * Encurta uma string preservando multibyte.
	 */
	private static function shorten( $text, $len ) {
		$text = trim( (string) $text );
		if ( function_exists( 'mb_strlen' ) ) {
			if ( mb_strlen( $text ) <= $len ) {
				return $text;
			}
			return mb_substr( $text, 0, $len ) . '…';
		}
		return strlen( $text ) <= $len ? $text : substr( $text, 0, $len ) . '…';
	}
}
