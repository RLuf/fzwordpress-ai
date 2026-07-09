#!/usr/bin/env bash
#
# FZ WordPress AI — installer do backend llama.cpp.
#
# Deixa o binário `llama-cli` disponível em bin/dist/llama-cli usando, nesta
# ordem, a primeira estratégia que funcionar ("como for, tem que funcionar"):
#
#   (a) binário pré-compilado já presente em bin/dist/llama-cli que rode;
#   (b) download do asset de Release do GitHub (RLuf/fzwordpress-ai) casando
#       este OS/arch, com verificação sha256;
#   (c) compilação local via build-llama.sh;
#   (d) se tudo falhar, imprime os comandos exatos para disparar o build no
#       GitHub Actions (git tag vX && git push origin vX).
#
# Também instala (opcional) um modelo .gguf e imprime a configuração a colar
# nos Ajustes do plugin.
#
# Seguro para reexecutar. Não usa sudo.
#
set -euo pipefail

# --------------------------------------------------------------- localização
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd -P)"
BIN_DIR="$SCRIPT_DIR"
PLUGIN_DIR="$(cd -- "$BIN_DIR/.." >/dev/null 2>&1 && pwd -P)"
DIST_DIR="$BIN_DIR/dist"
MODELS_DIR_DEFAULT="$DIST_DIR/models"
BIN_NAME="llama-cli"

# ---------------------------------------------------------------- parâmetros
PLUGIN_REPO="${FZWAI_PLUGIN_REPO:-RLuf/fzwordpress-ai}"
DO_LLAMA=0
VERSION="latest"
MODEL=""
PRINT_CONFIG=0
FORCE=0
MODELS_DIR="$MODELS_DIR_DEFAULT"

# estado (preenchido pelas estratégias)
INSTALLED_BIN=""
INSTALLED_MODEL=""
INSTALLED_TAG=""

# ------------------------------------------------------------------- helpers
# Cores só quando stdout é um terminal (evita lixo em logs/pipes) e sem NO_COLOR.
if [ -t 1 ] && [ -z "${NO_COLOR:-}" ]; then
	C_B=$'\033[1;34m'; C_Y=$'\033[1;33m'; C_R=$'\033[1;31m'; C_0=$'\033[0m'
else
	C_B=''; C_Y=''; C_R=''; C_0=''
fi
log()  { printf '%s[install]%s %s\n' "$C_B" "$C_0" "$*"; }
warn() { printf '%s[install] AVISO:%s %s\n' "$C_Y" "$C_0" "$*" >&2; }
die()  { printf '%s[install] ERRO:%s %s\n' "$C_R" "$C_0" "$*" >&2; exit 1; }

usage() {
	cat <<'EOF'
FZ WordPress AI — installer do backend llama.cpp

Uso: bin/install.sh [opções]

  --llama              Provisiona o llama-cli (pré-compilado -> download -> build).
  -v, --version <tag>  Versão/tag da Release a baixar (ex.: v1.0). Padrão: latest.
  --model <url|path>   Instala um modelo .gguf (baixa se URL, copia se caminho).
  --models-dir <path>  Diretório destino dos modelos (padrão: bin/dist/models).
  --print-config       Mostra os caminhos p/ colar nos Ajustes do plugin.
  --force              Ignora binário existente e refaz (download/build).
  --repo <owner/repo>  Repositório das Releases (padrão: RLuf/fzwordpress-ai).
  -h, --help           Esta ajuda.

Variáveis de ambiente:
  LLAMA_CPP_REF   ref do llama.cpp p/ o build local (padrão: b4589)
  LLAMA_VARIANT   portable | avx2 | native (build local; padrão: portable)

Exemplos:
  bin/install.sh --llama -v v1.0 --print-config
  bin/install.sh --llama --model https://exemplo/modelo.gguf
  bin/install.sh --model /root/modelos/qwen2.5-1.5b-q4_k_m.gguf --print-config

Sugestão de modelo pequeno (não é baixado automaticamente):
  Qwen2.5-1.5B-Instruct  Q4_K_M  -> https://huggingface.co/Qwen/Qwen2.5-1.5B-Instruct-GGUF
  Gemma-2-2b-it          Q4_K_M  -> (procure um .gguf Q4 no Hugging Face)
EOF
}

detect_os() {
	case "$(uname -s)" in
		Linux*)  echo linux ;;
		Darwin*) echo macos ;;
		*)       uname -s | tr '[:upper:]' '[:lower:]' ;;
	esac
}

detect_arch() {
	case "$(uname -m)" in
		x86_64|amd64)  echo x86_64 ;;
		arm64|aarch64) echo arm64 ;;
		*)             uname -m ;;
	esac
}

asset_name() { # $1 = tag
	echo "${BIN_NAME}-$(detect_os)-$(detect_arch)-$1.tar.gz"
}

sha256_of() { # $1 = arquivo -> imprime o hash
	if command -v sha256sum >/dev/null 2>&1; then
		sha256sum "$1" | awk '{print $1}'
	elif command -v shasum >/dev/null 2>&1; then
		shasum -a 256 "$1" | awk '{print $1}'
	else
		return 1
	fi
}

# --------------------------------------------------------------- parse args
parse_args() {
	while [ "$#" -gt 0 ]; do
		case "$1" in
			--llama)        DO_LLAMA=1; shift ;;
			-v|--version)   VERSION="${2:-latest}"; shift 2 || shift ;;
			--model)        MODEL="${2:-}"; shift 2 || shift ;;
			--models-dir)   MODELS_DIR="${2:-$MODELS_DIR_DEFAULT}"; shift 2 || shift ;;
			--repo)         PLUGIN_REPO="${2:-$PLUGIN_REPO}"; shift 2 || shift ;;
			--print-config) PRINT_CONFIG=1; shift ;;
			--force)        FORCE=1; shift ;;
			-h|--help)      usage; exit 0 ;;
			--)             shift; break ;;
			*)              warn "argumento desconhecido: $1"; shift ;;
		esac
	done
}

# ---------------------------------------------------- diagnóstico proc_open
detect_php() {
	local c
	for c in php /usr/local/bin/php \
		/opt/cpanel/ea-php74/root/usr/bin/php \
		/opt/cpanel/ea-php81/root/usr/bin/php \
		/opt/cpanel/ea-php82/root/usr/bin/php; do
		if command -v "$c" >/dev/null 2>&1 || [ -x "$c" ]; then
			echo "$c"
			return 0
		fi
	done
	return 1
}

check_proc_open() {
	local php
	if ! php="$(detect_php)"; then
		warn "PHP CLI não encontrado; não deu para checar proc_open (o backend precisa dele)."
		return 0
	fi
	if "$php" -r 'exit(function_exists("proc_open") ? 0 : 1);' >/dev/null 2>&1; then
		log "PHP proc_open: habilitado ($php)"
	else
		warn "PHP proc_open DESABILITADO em $php — o backend llama.cpp NÃO funcionará."
		warn "Remova 'proc_open' de disable_functions no php.ini do site e recarregue."
	fi
}

# ------------------------------------------------ estratégia (a): pré-existe
strategy_prebuilt() {
	local bin="$DIST_DIR/$BIN_NAME"
	[ "$FORCE" -eq 0 ] || { log "--force: ignorando binário pré-existente."; return 1; }
	[ -x "$bin" ] || return 1
	if "$bin" --version >/dev/null 2>&1; then
		INSTALLED_BIN="$bin"
		log "Binário pré-existente OK: $bin"
		return 0
	fi
	warn "Binário em $bin não executou; tentando outras vias."
	return 1
}

# --------------------------------------------------- estratégia (b): download
verify_sums() { # $1 sums, $2 arquivo, $3 asset ; 0=ok 1=mismatch 2=indeterminado
	local sums="$1" file="$2" asset="$3" want got
	command -v sha256sum >/dev/null 2>&1 || command -v shasum >/dev/null 2>&1 || return 2
	want="$(awk -v a="$asset" '{n=$2; sub(/^\*/,"",n); sub(/.*\//,"",n); if(n==a){print $1; exit}}' "$sums" 2>/dev/null || true)"
	[ -n "$want" ] || return 2
	got="$(sha256_of "$file")" || return 2
	[ "$want" = "$got" ] && return 0
	return 1
}

strategy_download() {
	command -v curl >/dev/null 2>&1 || { warn "curl ausente; pulando download."; return 1; }

	local tag="$VERSION"
	if [ "$tag" = "latest" ]; then
		log "Resolvendo a última release de $PLUGIN_REPO…"
		local api="https://api.github.com/repos/${PLUGIN_REPO}/releases/latest"
		local json
		json="$(curl -fsSL -H 'Accept: application/vnd.github+json' -H 'User-Agent: fzwordpress-ai' "$api" 2>/dev/null || true)"
		tag="$(printf '%s' "$json" | grep -oE '"tag_name"[[:space:]]*:[[:space:]]*"[^"]+"' | head -n1 | sed -E 's/.*"([^"]+)".*/\1/')"
		[ -n "$tag" ] || { warn "não foi possível resolver a última release."; return 1; }
		log "Última release: $tag"
	fi

	local asset base url tmpd tarball
	asset="$(asset_name "$tag")"
	base="https://github.com/${PLUGIN_REPO}/releases/download/${tag}"
	url="${base}/${asset}"
	tmpd="$(mktemp -d)"
	tarball="${tmpd}/${asset}"

	log "Baixando $url"
	if ! curl -fL --retry 3 --connect-timeout 20 -o "$tarball" "$url" 2>/dev/null; then
		warn "asset não encontrado: $asset (tag $tag). Talvez o build ainda não exista."
		rm -rf "$tmpd"
		return 1
	fi

	# verifica sha256 (SHA256SUMS combinado ou <asset>.sha256)
	local sums="${tmpd}/SHA256SUMS" rc
	if curl -fsSL --connect-timeout 20 -o "$sums" "${base}/SHA256SUMS" 2>/dev/null; then
		rc=0; verify_sums "$sums" "$tarball" "$asset" || rc=$?
		case "$rc" in
			0) log "Checksum sha256 verificado (SHA256SUMS)." ;;
			1) warn "checksum sha256 NÃO confere para $asset"; rm -rf "$tmpd"; return 1 ;;
			*) warn "não foi possível verificar o checksum; prosseguindo." ;;
		esac
	elif curl -fsSL --connect-timeout 20 -o "${sums}.one" "${base}/${asset}.sha256" 2>/dev/null; then
		local want got
		want="$(grep -oE '[a-f0-9]{64}' "${sums}.one" | head -n1 || true)"
		got="$(sha256_of "$tarball" || true)"
		if [ -n "$want" ] && [ -n "$got" ] && [ "$want" != "$got" ]; then
			warn "checksum sha256 NÃO confere para $asset"; rm -rf "$tmpd"; return 1
		fi
		log "Checksum sha256 verificado (${asset}.sha256)."
	else
		warn "sem arquivo de checksums na release; prosseguindo sem verificação."
	fi

	mkdir -p "$DIST_DIR"
	if ! tar -xzf "$tarball" -C "$DIST_DIR" 2>/dev/null; then
		warn "falha ao extrair $asset"; rm -rf "$tmpd"; return 1
	fi
	rm -rf "$tmpd"

	local bin="$DIST_DIR/$BIN_NAME"
	[ -f "$bin" ] || { warn "pacote não contém $BIN_NAME"; return 1; }
	chmod +x "$bin"
	if "$bin" --version >/dev/null 2>&1; then
		INSTALLED_BIN="$bin"; INSTALLED_TAG="$tag"
		log "Download OK: $bin (tag $tag)"
		return 0
	fi
	warn "binário baixado não executou (glibc/arquitetura?). Vou tentar compilar localmente."
	return 1
}

# ---------------------------------------------------- estratégia (c): build
strategy_build() {
	[ -f "$BIN_DIR/build-llama.sh" ] || { warn "build-llama.sh ausente; não dá para compilar."; return 1; }
	log "Compilando localmente via build-llama.sh…"
	if bash "$BIN_DIR/build-llama.sh" "$DIST_DIR/$BIN_NAME"; then
		local bin="$DIST_DIR/$BIN_NAME"
		if [ -x "$bin" ] && "$bin" --version >/dev/null 2>&1; then
			INSTALLED_BIN="$bin"
			log "Build local OK: $bin"
			return 0
		fi
	fi
	warn "compilação local falhou."
	return 1
}

# ---------------------------------------------- estratégia (d): dica de tag
print_build_hint() {
	local v="$VERSION"
	[ "$v" = "latest" ] && v="v1.0"
	cat <<EOF

------------------------------------------------------------------------------
Nenhuma estratégia automática funcionou. Para gerar os pacotes no GitHub
Actions, crie e envie a tag (isso dispara .github/workflows/build-llama.yml):

    git tag ${v}
    git push origin ${v}

O workflow compila o llama-cli e publica os tarballs na Release ${v}.
Depois, rode novamente:

    bin/install.sh --llama -v ${v}

Ou compile localmente agora mesmo:

    LLAMA_VARIANT=native bin/build-llama.sh
------------------------------------------------------------------------------
EOF
}

provision_llama() {
	mkdir -p "$DIST_DIR"
	if   strategy_prebuilt; then :
	elif strategy_download; then :
	elif strategy_build;    then :
	else
		warn "Falha ao provisionar o llama.cpp por todas as vias automáticas."
		print_build_hint
		return 1
	fi
	log "llama-cli pronto em: $INSTALLED_BIN"
	return 0
}

# ------------------------------------------------------------------ modelo
provision_model() {
	if [ -z "$MODEL" ]; then
		return 0
	fi
	mkdir -p "$MODELS_DIR"
	local dest
	if printf '%s' "$MODEL" | grep -qE '^https?://'; then
		command -v curl >/dev/null 2>&1 || die "curl necessário para baixar o modelo."
		local fn
		fn="$(basename "${MODEL%%\?*}")"
		case "$fn" in
			*.gguf) : ;;
			*)      fn="model.gguf" ;;
		esac
		dest="$MODELS_DIR/$fn"
		log "Baixando modelo: $MODEL"
		curl -fL --retry 3 -o "$dest" "$MODEL" || die "falha ao baixar o modelo."
	else
		[ -f "$MODEL" ] || die "modelo não encontrado: $MODEL"
		dest="$MODELS_DIR/$(basename "$MODEL")"
		cp -f "$MODEL" "$dest"
	fi

	# sanidade: magic "GGUF" nos primeiros bytes
	if ! head -c 4 "$dest" 2>/dev/null | grep -q 'GGUF'; then
		warn "o arquivo não parece um GGUF válido (magic ausente): $dest"
	fi
	INSTALLED_MODEL="$dest"
	log "Modelo em: $dest"
}

# ----------------------------------------------------------- imprime config
print_config() {
	local bin model
	bin="${INSTALLED_BIN:-$DIST_DIR/$BIN_NAME}"
	model="${INSTALLED_MODEL:-}"
	if [ -z "$model" ]; then
		model="$(ls -1 "$MODELS_DIR"/*.gguf 2>/dev/null | head -n1 || true)"
	fi
	echo ""
	echo "==================== CONFIGURAÇÃO DO PLUGIN ===================="
	echo "Backend:          llamacpp"
	echo "llamacpp_bin:     $bin"
	if [ -n "$model" ]; then
		echo "llamacpp_model:   $model"
	else
		echo "llamacpp_model:   (defina — aponte para um .gguf; use --model para instalar um)"
	fi
	echo ""
	echo "No admin: FZ WordPress AI -> Ajustes -> Backend = llama.cpp,"
	echo "e cole os caminhos acima. Diretório do plugin: $PLUGIN_DIR"
	echo "==============================================================="
}

# -------------------------------------------------------------------- main
main() {
	parse_args "$@"

	if [ "$DO_LLAMA" -eq 0 ] && [ -z "$MODEL" ] && [ "$PRINT_CONFIG" -eq 0 ]; then
		usage
		exit 0
	fi

	log "Plugin: $PLUGIN_DIR"
	log "Alvo:   $(detect_os)/$(detect_arch) | repo: $PLUGIN_REPO | versão: $VERSION"
	check_proc_open

	local rc=0
	if [ "$DO_LLAMA" -eq 1 ]; then
		provision_llama || rc=1
	fi

	provision_model

	if [ "$PRINT_CONFIG" -eq 1 ] || [ "$DO_LLAMA" -eq 1 ]; then
		print_config
	fi

	exit "$rc"
}

main "$@"
