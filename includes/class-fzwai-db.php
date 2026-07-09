<?php
/**
 * Camada SQLite (PDO). Guarda protocolos, base de conhecimento (chunks +
 * vetores) e conversas. Tudo em um único arquivo, no diretório de dados privado.
 *
 * @package FZWordPressAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FZWAI_DB {

	const SCHEMA_VERSION = 1;

	/** @var FZWAI_DB|null */
	private static $instance = null;

	/** @var PDO|null */
	private $pdo = null;

	public static function available() {
		return class_exists( 'PDO' ) && in_array( 'sqlite', PDO::getAvailableDrivers(), true );
	}

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function path() {
		return trailingslashit( FZWAI_DATA_DIR ) . 'fzwai.sqlite';
	}

	/**
	 * @return PDO
	 * @throws RuntimeException
	 */
	public function pdo() {
		if ( null !== $this->pdo ) {
			return $this->pdo;
		}
		if ( ! self::available() ) {
			throw new RuntimeException( 'PDO SQLite indisponível neste servidor.' );
		}
		if ( ! is_dir( FZWAI_DATA_DIR ) ) {
			wp_mkdir_p( FZWAI_DATA_DIR );
		}
		$this->pdo = new PDO( 'sqlite:' . $this->path() );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->pdo->setAttribute( PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC );
		$this->pdo->exec( 'PRAGMA journal_mode=WAL' );
		$this->pdo->exec( 'PRAGMA busy_timeout=5000' );
		$this->pdo->exec( 'PRAGMA foreign_keys=ON' );
		return $this->pdo;
	}

	/**
	 * Cria/atualiza o schema.
	 */
	public function migrate() {
		$db = $this->pdo();

		$db->exec( 'CREATE TABLE IF NOT EXISTS fzwai_settings (
			key TEXT PRIMARY KEY,
			value TEXT
		)' );

		// Fontes de conhecimento cadastradas pelo admin (URL, arquivo ou texto).
		$db->exec( 'CREATE TABLE IF NOT EXISTS fzwai_sources (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			type TEXT NOT NULL,            -- url | file | text
			label TEXT NOT NULL DEFAULT "",
			location TEXT NOT NULL DEFAULT "",
			status TEXT NOT NULL DEFAULT "pending",  -- pending | indexed | error
			last_error TEXT,
			chunk_count INTEGER NOT NULL DEFAULT 0,
			indexed_at TEXT,
			created_at TEXT NOT NULL
		)' );

		// Pedaços indexados + embedding (JSON de floats) para busca semântica.
		$db->exec( 'CREATE TABLE IF NOT EXISTS fzwai_chunks (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			source_id INTEGER NOT NULL,
			seq INTEGER NOT NULL DEFAULT 0,
			content TEXT NOT NULL,
			tokens INTEGER NOT NULL DEFAULT 0,
			embedding TEXT,                -- JSON array de floats (ou NULL se fallback léxico)
			embed_model TEXT DEFAULT "",
			created_at TEXT NOT NULL,
			FOREIGN KEY (source_id) REFERENCES fzwai_sources(id) ON DELETE CASCADE
		)' );
		$db->exec( 'CREATE INDEX IF NOT EXISTS idx_chunks_source ON fzwai_chunks(source_id)' );

		// Protocolos de atendimento.
		$db->exec( 'CREATE TABLE IF NOT EXISTS fzwai_protocols (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			protocol_no TEXT NOT NULL UNIQUE,
			visitor_name TEXT DEFAULT "",
			visitor_contact TEXT DEFAULT "",
			question TEXT NOT NULL,
			ai_answer TEXT DEFAULT "",
			status TEXT NOT NULL DEFAULT "open",   -- open | forwarded | closed
			handoff TEXT DEFAULT "",               -- whatsapp | none
			page_url TEXT DEFAULT "",
			ip TEXT DEFAULT "",
			created_at TEXT NOT NULL,
			updated_at TEXT NOT NULL
		)' );
		$db->exec( 'CREATE INDEX IF NOT EXISTS idx_prot_status ON fzwai_protocols(status)' );

		// Log de conversas (cada turno).
		$db->exec( 'CREATE TABLE IF NOT EXISTS fzwai_messages (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			session_id TEXT NOT NULL,
			protocol_id INTEGER,
			role TEXT NOT NULL,            -- user | assistant | system
			content TEXT NOT NULL,
			sources TEXT DEFAULT "",       -- JSON dos chunks citados
			created_at TEXT NOT NULL
		)' );
		$db->exec( 'CREATE INDEX IF NOT EXISTS idx_msg_session ON fzwai_messages(session_id)' );

		$this->set_meta( 'schema_version', (string) self::SCHEMA_VERSION );
	}

	// --- Helpers de meta (schema/versão) ---
	public function set_meta( $key, $value ) {
		$stmt = $this->pdo()->prepare( 'INSERT INTO fzwai_settings(key,value) VALUES(:k,:v)
			ON CONFLICT(key) DO UPDATE SET value=:v2' );
		$stmt->execute( array( ':k' => 'meta_' . $key, ':v' => $value, ':v2' => $value ) );
	}

	public function get_meta( $key, $default = '' ) {
		$stmt = $this->pdo()->prepare( 'SELECT value FROM fzwai_settings WHERE key=:k' );
		$stmt->execute( array( ':k' => 'meta_' . $key ) );
		$v = $stmt->fetchColumn();
		return false === $v ? $default : $v;
	}

	/** Utilitário: timestamp UTC. */
	public static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}
}
