#!/usr/bin/env bash
#
# FZ WordPress AI — compilador LOCAL do llama.cpp (alvo: llama-cli).
#
# Detecta o sistema, clona/atualiza o llama.cpp numa ref fixada e compila o
# `llama-cli` com CMake usando flags portáveis. O binário é copiado para
# `bin/dist/llama-cli` (ou para o caminho passado como $1).
#
# Idempotente e seguro para reexecutar. Não usa sudo.
#
# Uso:
#   bin/build-llama.sh [caminho-do-binario-de-saida]
#
# Variáveis de ambiente (sobrepõem os padrões):
#   LLAMA_CPP_REPO   URL git do llama.cpp  (padrão: ggml-org/llama.cpp)
#   LLAMA_CPP_REF    tag/branch a compilar (padrão: b4589)
#   LLAMA_VARIANT    portable | avx2 | native  (padrão: portable)
#
set -euo pipefail

# --------------------------------------------------------------- localização
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd -P)"
BIN_DIR="$SCRIPT_DIR"
DIST_DIR="$BIN_DIR/dist"
SRC_DIR="$BIN_DIR/.llama-src"

# ---------------------------------------------------------------- parâmetros
LLAMA_CPP_REPO="${LLAMA_CPP_REPO:-https://github.com/ggml-org/llama.cpp.git}"
LLAMA_CPP_REF="${LLAMA_CPP_REF:-b4589}"
LLAMA_VARIANT="${LLAMA_VARIANT:-portable}"
DEST="${1:-$DIST_DIR/${FZWAI_BIN_NAME:-llama-cli}}"

# ------------------------------------------------------------------- helpers
# Cores só quando stdout é um terminal (evita lixo em logs/pipes) e sem NO_COLOR.
if [ -t 1 ] && [ -z "${NO_COLOR:-}" ]; then
	C_B=$'\033[1;34m'; C_Y=$'\033[1;33m'; C_R=$'\033[1;31m'; C_0=$'\033[0m'
else
	C_B=''; C_Y=''; C_R=''; C_0=''
fi
log()  { printf '%s[build-llama]%s %s\n' "$C_B" "$C_0" "$*"; }
warn() { printf '%s[build-llama] AVISO:%s %s\n' "$C_Y" "$C_0" "$*" >&2; }
die()  { printf '%s[build-llama] ERRO:%s %s\n' "$C_R" "$C_0" "$*" >&2; exit 1; }

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

jobs_count() {
	if command -v nproc >/dev/null 2>&1; then
		nproc
	elif command -v sysctl >/dev/null 2>&1; then
		sysctl -n hw.ncpu 2>/dev/null || echo 2
	else
		echo 2
	fi
}

have_cc() {
	command -v cc >/dev/null 2>&1 || command -v gcc >/dev/null 2>&1 || command -v clang >/dev/null 2>&1
}

have_cxx() {
	command -v c++ >/dev/null 2>&1 || command -v g++ >/dev/null 2>&1 || command -v clang++ >/dev/null 2>&1
}

install_hint() {
	local os
	os="$(detect_os)"
	warn "Ferramentas de compilação ausentes. Instale-as e rode novamente:"
	if [ "$os" = "macos" ]; then
		warn "  xcode-select --install && brew install cmake git"
	elif command -v apt-get >/dev/null 2>&1; then
		warn "  sudo apt-get update && sudo apt-get install -y git cmake build-essential"
	elif command -v dnf >/dev/null 2>&1; then
		warn "  sudo dnf install -y git cmake gcc gcc-c++ make"
	elif command -v yum >/dev/null 2>&1; then
		warn "  sudo yum install -y git cmake gcc gcc-c++ make"
	elif command -v zypper >/dev/null 2>&1; then
		warn "  sudo zypper install -y git cmake gcc gcc-c++ make"
	elif command -v pacman >/dev/null 2>&1; then
		warn "  sudo pacman -S --noconfirm git cmake base-devel"
	else
		warn "  instale: git, cmake, make e um compilador C/C++ (gcc/clang)"
	fi
}

# --------------------------------------------------------- checagem de tools
check_tools() {
	local missing=0
	command -v git   >/dev/null 2>&1 || { warn "faltando: git";   missing=1; }
	command -v cmake >/dev/null 2>&1 || { warn "faltando: cmake"; missing=1; }
	command -v make  >/dev/null 2>&1 || { warn "faltando: make (ou ninja)"; missing=1; }
	have_cc  || { warn "faltando: compilador C (cc/gcc/clang)";     missing=1; }
	have_cxx || { warn "faltando: compilador C++ (c++/g++/clang++)"; missing=1; }
	if [ "$missing" -ne 0 ]; then
		install_hint
		die "pré-requisitos ausentes."
	fi
}

# ------------------------------------------------------------- obtém a fonte
fetch_src() {
	if [ -d "$SRC_DIR/.git" ]; then
		log "Atualizando fonte em $SRC_DIR (ref: $LLAMA_CPP_REF)"
		git -C "$SRC_DIR" fetch --depth 1 origin "refs/tags/$LLAMA_CPP_REF:refs/tags/$LLAMA_CPP_REF" 2>/dev/null \
			|| git -C "$SRC_DIR" fetch --depth 1 origin "$LLAMA_CPP_REF" 2>/dev/null || true
		git -C "$SRC_DIR" checkout -q "$LLAMA_CPP_REF" 2>/dev/null \
			|| git -C "$SRC_DIR" checkout -q FETCH_HEAD 2>/dev/null \
			|| die "não foi possível posicionar na ref $LLAMA_CPP_REF"
	else
		log "Clonando $LLAMA_CPP_REPO @ $LLAMA_CPP_REF"
		rm -rf "$SRC_DIR"
		git clone --depth 1 --branch "$LLAMA_CPP_REF" "$LLAMA_CPP_REPO" "$SRC_DIR" \
			|| die "falha ao clonar $LLAMA_CPP_REPO na ref $LLAMA_CPP_REF (a ref existe?)"
	fi
}

# ---------------------------------------------------------------- compila
build() {
	local extra=()
	case "$LLAMA_VARIANT" in
		portable) extra=( -DGGML_NATIVE=OFF ) ;;
		avx2)     extra=( -DGGML_NATIVE=OFF -DGGML_AVX=ON -DGGML_AVX2=ON -DGGML_FMA=ON ) ;;
		native)   extra=( -DGGML_NATIVE=ON ) ;;
		*)        die "LLAMA_VARIANT inválido: '$LLAMA_VARIANT' (use portable|avx2|native)" ;;
	esac

	local build_dir="$SRC_DIR/build"
	log "Configurando (CMake) variante '$LLAMA_VARIANT'…"
	cmake -S "$SRC_DIR" -B "$build_dir" \
		-DCMAKE_BUILD_TYPE=Release \
		-DLLAMA_CURL=OFF \
		-DBUILD_SHARED_LIBS=OFF \
		"${extra[@]}"

	log "Compilando llama-cli (-j $(jobs_count))…"
	cmake --build "$build_dir" --config Release -j "$(jobs_count)" --target llama-cli
}

# ------------------------------------------------------------- instala/copia
install_bin() {
	local build_dir="$SRC_DIR/build" bin_src=""
	local c
	for c in "$build_dir/bin/llama-cli" "$build_dir/llama-cli" "$build_dir/bin/Release/llama-cli"; do
		if [ -x "$c" ]; then
			bin_src="$c"
			break
		fi
	done
	[ -n "$bin_src" ] || die "binário llama-cli não encontrado após o build (procurei em $build_dir)"

	mkdir -p "$(dirname -- "$DEST")"
	cp -f "$bin_src" "$DEST"
	chmod +x "$DEST"
	log "Instalado: $DEST"
}

# -------------------------------------------------------------------- main
main() {
	log "OS/arch: $(detect_os)/$(detect_arch) | ref: $LLAMA_CPP_REF | variante: $LLAMA_VARIANT"
	check_tools
	fetch_src
	build
	install_bin

	local ver
	ver="$("$DEST" --version 2>&1 | head -n1 || true)"
	log "Binário: $DEST"
	log "Versão:  ${ver:-<não confirmada>}"
	# Última linha = caminho do binário (facilita scripting/pipe).
	echo "$DEST"
}

main "$@"
