# FZ WordPress AI — camada llama.cpp (build / download / install)

Este diretório deixa o backend **llama.cpp** do plugin fácil e robusto de
provisionar. O plugin executa um binário CLI do llama.cpp (`llama-cli`) sobre um
modelo `.gguf`; aqui estão as ferramentas para obter esse binário de **três
formas**, mais o workflow que gera os pacotes por **tag**.

```
bin/
├── install.sh        # installer one-shot (pré-compilado -> download -> build)
├── build-llama.sh    # compilador local (CMake) do llama-cli
├── dist/             # saída: dist/llama-cli e dist/models/*.gguf (criado em runtime)
└── README.md         # este arquivo
```

O código PHP do plugin que conversa com esta camada está em
`includes/class-fzwai-llama.php` (classe `FZWAI_Llama`).

---

## TL;DR — as três formas de fazer o llama.cpp funcionar

Rode a partir da raiz do plugin. **Qualquer uma** deixa `bin/dist/llama-cli`
pronto; o installer tenta as três em ordem automaticamente.

```bash
# 1) TUDO AUTOMÁTICO: usa binário existente, senão baixa a Release, senão compila.
bash bin/install.sh --llama -v v1.0 --print-config

# 2) SÓ COMPILAR LOCALMENTE (melhor desempenho na própria máquina):
LLAMA_VARIANT=native bash bin/build-llama.sh

# 3) SÓ BAIXAR um pacote publicado (feito por dentro do install.sh, etapa "b"):
bash bin/install.sh --llama -v v1.0
```

E a **quarta** forma — gerar os pacotes no GitHub (dispara `build-llama.yml`):

```bash
git tag v1.0
git push origin v1.0
# ...aguarde o Actions publicar a Release, depois:
bash bin/install.sh --llama -v v1.0 --print-config
```

---

## `install.sh` — o installer one-shot

Estratégia do `--llama`, na ordem, até **uma** funcionar ("como for, tem que
funcionar"):

| Etapa | O que faz |
|------:|-----------|
| **a** | Se `bin/dist/llama-cli` já existe e roda (`--version`), usa. |
| **b** | Baixa o asset da Release (`RLuf/fzwordpress-ai`) que casa este OS/arch, verifica **sha256** e extrai em `bin/dist/`. |
| **c** | Compila localmente via `build-llama.sh`. |
| **d** | Se tudo falhar, imprime os comandos exatos para disparar o build no GitHub Actions. |

### Flags

```
--llama              Provisiona o llama-cli (a -> b -> c -> d).
-v, --version <tag>  Tag da Release a baixar (ex.: v1.0). Padrão: latest.
--model <url|path>   Instala um modelo .gguf (baixa se URL, copia se caminho).
--models-dir <path>  Destino dos modelos (padrão: bin/dist/models).
--print-config       Imprime os caminhos p/ colar nos Ajustes do plugin.
--force              Ignora binário existente e refaz (download/build).
--repo <owner/repo>  Repositório das Releases (padrão: RLuf/fzwordpress-ai).
-h, --help           Ajuda.
```

O installer é **idempotente** (seguro para reexecutar) e **detecta `proc_open`**
no PHP CLI — o backend não funciona sem ele. Se estiver em `disable_functions`,
o installer avisa.

### Modelo (`.gguf`)

Nenhum modelo é baixado automaticamente (não embutimos URL que pode morrer). Se
quiser um modelo pequeno para começar, sugestões:

- **Qwen2.5-1.5B-Instruct** (Q4_K_M) — <https://huggingface.co/Qwen/Qwen2.5-1.5B-Instruct-GGUF>
- **Gemma-2-2b-it** (Q4_K_M) — procure um `.gguf` Q4 no Hugging Face

Instale um com:

```bash
bash bin/install.sh --model https://huggingface.co/.../modelo.q4_k_m.gguf
# ou a partir de um arquivo local:
bash bin/install.sh --model /root/modelos/qwen2.5-1.5b-instruct-q4_k_m.gguf
```

### Configurar o plugin

`--print-config` imprime exatamente o que colar em **FZ WordPress AI → Ajustes**:

```
Backend:          llamacpp
llamacpp_bin:     /caminho/do/plugin/bin/dist/llama-cli
llamacpp_model:   /caminho/do/plugin/bin/dist/models/<modelo>.gguf
```

---

## `build-llama.sh` — compilador local

Detecta OS/arch, clona/atualiza o llama.cpp na ref fixada e compila `llama-cli`
com CMake. Copia o resultado para `bin/dist/llama-cli` (ou para o caminho em
`$1`). `set -euo pipefail`, idempotente, sem `sudo`.

```bash
bash bin/build-llama.sh                       # portátil -> bin/dist/llama-cli
LLAMA_VARIANT=native bash bin/build-llama.sh  # otimizado p/ esta CPU (recomendado local)
bash bin/build-llama.sh /opt/llama/llama-cli  # destino customizado
```

Variáveis de ambiente:

```
LLAMA_CPP_REPO   URL git do llama.cpp   (padrão: https://github.com/ggml-org/llama.cpp.git)
LLAMA_CPP_REF    tag/branch a compilar  (padrão: b4589)
LLAMA_VARIANT    portable | avx2 | native (padrão: portable)
```

Pré-requisitos: `git`, `cmake`, `make` e um compilador C/C++. Se faltar algo, o
script **diz exatamente** como instalar na sua distro (apt/dnf/yum/zypper/pacman
ou Xcode+brew no macOS).

Flags de portabilidade usadas: `-DGGML_NATIVE=OFF` (sem `-march=native`),
`-DLLAMA_CURL=OFF` (sem dependência de libcurl) e `-DBUILD_SHARED_LIBS=OFF`
(binário autocontido — ggml/llama linkados estaticamente). A variante `native`
troca por `-DGGML_NATIVE=ON` para o melhor desempenho na própria máquina.

---

## GitHub Actions

### `build-llama.yml` — gera os pacotes

- **Gatilhos:** push de tag `v*` (ex.: `v1.0`) **e** `workflow_dispatch`.
- **Push de tag** compila **e publica** a Release. `workflow_dispatch` só compila
  e guarda os artefatos do run (útil para testar sem publicar).
- **Linux x86_64** é compilado dentro do container **`manylinux_2_28`**
  (**glibc 2.28**) — assim o binário roda em **RHEL/CloudLinux/AlmaLinux 8+** e
  qualquer distro mais recente. Matriz de variantes: `portable` e `avx2`.
- **macOS arm64** (`macos-latest`) compila a variante `portable` (CPU, sem Metal).
- Cada job gera `llama-cli-<os>-<arch>[-<variante>]-<tag>.tar.gz` + `.sha256`.
  O job `release` reúne tudo, gera um **`SHA256SUMS`** combinado e anexa à Release
  via `softprops/action-gh-release@v2`.
- **Permissões:** o workflow declara `permissions: contents: write` (necessário
  para criar a Release e anexar assets).

Nomes de asset (o que o `install.sh` procura):

```
llama-cli-linux-x86_64-<tag>.tar.gz         # Linux portátil (canônico, glibc 2.28)
llama-cli-linux-x86_64-avx2-<tag>.tar.gz    # Linux AVX2/FMA
llama-cli-macos-arm64-<tag>.tar.gz          # macOS Apple Silicon
SHA256SUMS                                  # somas de todos os assets
```

Disparar manualmente:

```bash
git tag v1.0 && git push origin v1.0
# ou: aba Actions -> "build-llama" -> Run workflow (workflow_dispatch)
```

### `lint.yml` — sanidade

Em `push`/`pull_request`: `php -l` em todos os `.php` (matriz **PHP 7.4 + 8.2**)
e `bash -n` em todos os `.sh`. Roda `shellcheck` de forma informativa
(não bloqueia).

---

## Ref fixada do llama.cpp — `b4589`

O padrão fixado é **`b4589`** (série de builds tagueados do `ggml-org/llama.cpp`).
Motivos:

- É posterior à renomeação do binário para **`llama-cli`** (o alvo CMake que
  compilamos) e à migração das opções de CPU para o prefixo **`GGML_*`**
  (`GGML_NATIVE`, `GGML_AVX2`, `GGML_FMA`), usadas aqui.
- Suporta **`-DLLAMA_CURL=OFF`**, o que remove a dependência de libcurl.
- É baixo o suficiente para existir com folga e alto o suficiente para ser
  estável — e, principalmente, é **sobreponível**: ajuste `LLAMA_CPP_REF`
  (env, nos scripts) ou o input `llama_ref` (no workflow) para o build vigente.

> Recomendação: confira o build atual em
> <https://github.com/ggml-org/llama.cpp/releases> e fixe/atualize conforme testado.
> O repositório `ggerganov/llama.cpp` redireciona para `ggml-org/llama.cpp`.

---

## Segurança / decisões

- **Sem `curl | bash` da internet dentro do PHP.** O download no PHP usa a HTTP
  API do WordPress (`wp_remote_get`), verifica **sha256** quando há `SHA256SUMS`
  e extrai com `PharData` (nativo), caindo para `tar` local só se preciso.
- **Nada roda no `load` da página.** Rede/execução no PHP só quando o admin
  aciona explicitamente (a UI chama `FZWAI_Llama::download_release()` etc.).
- **Caminhos validados.** Downloads/extrações ficam sob `FZWAI_DIR` ou
  `FZWAI_DATA_DIR`; `..` é recusado.
- **Argumentos de shell escapados** (`escapeshellarg`) em toda execução de binário.
- **Versões fixadas** de Actions (`@v4`, `@v2`) e da ref do llama.cpp. Para
  máxima segurança de cadeia de suprimentos, é possível fixar as Actions por SHA.

---

## Solução de problemas

| Sintoma | Causa provável / ação |
|--------|------------------------|
| `proc_open DESABILITADO` | Remova `proc_open` de `disable_functions` no `php.ini` do site. |
| binário baixado não executa | Incompatibilidade glibc/arquitetura. Rode `bash bin/build-llama.sh` (compila para a máquina). |
| `asset não encontrado` | A Release/tag ainda não existe: `git tag v1.0 && git push origin v1.0`. |
| `cmake`/compilador ausente | O `build-llama.sh` imprime o comando de instalação da sua distro. |
| `--version` não confirma | Confirme a arquitetura e permissões (`chmod +x bin/dist/llama-cli`). |
