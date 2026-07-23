# FZ WordPress AI — Atendimento Inteligente

Plugin WordPress de **pré-atendimento com IA**: entende a dúvida do visitante, responde com base na sua própria base de conhecimento (**RAG em SQLite**), abre um **protocolo** e encaminha para um técnico via **WhatsApp** — falando como uma pessoa de verdade e **restrito ao assunto** que você definir (ex.: corretagem de imóveis, sem falar de carros).

Comercializável sob **GPL-2.0-or-later** (venda suporte, hospedagem e serviços em cima).

> **Estado:** versão `1.1.3` em homologação. Não faz parte do caminho crítico de
> provisionamento do ImovelSite.

## Navegação

- [Documentação](docs/README.md)
- [Operação e diagnóstico](docs/OPERATIONS.md)
- [Compliance](docs/COMPLIANCE.md)
- [Build do llama.cpp](bin/README.md)
- [Regras para agentes](AGENTS.md)

## Arquitetura

```
Visitante ─▶ Widget de chat (JS)  ─▶  REST /wp-json/fzwai/v1/chat
                                   └▶  REST /wp-json/fzwai/v1/support
                                          │
                                          ▼
                            FZWAI_Chat (orquestração)
                             │            │            │
                             ▼            ▼            ▼
                        RAG (SQLite)   LLM backend   Protocolo (SQLite)
                        embeddings +   ├ Ollama       └▶ WhatsApp (wa.me)
                        busca léxica   ├ llama.cpp
                                       └ API OpenAI-compat
```

- **Dados no seu servidor**: base de conhecimento e protocolos vivem num arquivo **SQLite** dentro de `wp-content/uploads/fzwai-data/` (protegido por `.htaccess`).
- **Você escolhe o motor**: Ollama (padrão), llama.cpp embarcado (offline) ou uma API online compatível com OpenAI.

## Instalação rápida

1. Copie a pasta para `wp-content/plugins/fzwordpress-ai/` e ative.
2. **FZ AI Atendimento → Configurações**: escolha o backend e informe URL/modelo (ou chave).
3. **Base de conhecimento**: cadastre URLs/arquivos/textos e clique em **Indexar**.
4. Defina persona, escopo e o **WhatsApp** do atendimento. Ative o widget.

### llama.cpp embarcado (três caminhos, sempre funciona)

```bash
# 1) usa binário já compilado em bin/dist/ se existir
# 2) senão baixa o release do GitHub para o seu SO/arch (com verificação sha256)
# 3) senão compila localmente
# 4) se nada rolar, mostra como disparar o build por tag no GitHub Actions
bin/install.sh --llama -v v1.0 --model <url-ou-caminho-do-gguf> --print-config
```

O build por tag: `git tag v1.0 && git push origin v1.0` aciona `.github/workflows/build-llama.yml`, que compila o `llama-cli` (CPU portável + variante AVX2) e publica os pacotes no **Releases**.

## Configuração (resumo)

| Grupo | Campos |
|---|---|
| Backend | `ollama_url`, `ollama_model` · `llamacpp_bin`, `llamacpp_model` · `openai_base`, `openai_key`, `openai_model` |
| Persona | `assistant_name`, `business_name`, `topic_scope`, `system_prompt`, `temperature`, `max_tokens`, `refuse_offtopic` |
| Atendimento | identificação do visitante, `whatsapp_number`, `handoff_message`, `ask_contact`, `protocol_prefix` e solicitação de suporte por e-mail |
| Widget | `widget_enabled`, `widget_title`, `widget_greeting`, `widget_color`, `widget_position` |

## Privacidade e segurança

- Nenhum dado do visitante vai a terceiros além do motor de IA que **você** configurar. Com Ollama/llama.cpp, 100% no seu ambiente.
- Diretório de dados bloqueado ao acesso web; SQLite fora do alcance público.
- Endpoint público com rate limit; operações administrativas exigem capacidade
  e nonce; entradas são validadas e consultas usam prepared statements.
- O widget público não envia nonce ou credencial de sessão. Isso evita falhas
  `403` causadas por nonces expirados em páginas cacheadas. O AJAX
  administrativo continua protegido por capacidade e nonce.

## Comercialização

Licença GPL-2.0-or-later. O código é aberto; o modelo de negócio é venda de **suporte, hospedagem gerenciada, curadoria da base de conhecimento e integração** (mesmo modelo de plugins profissionais do ecossistema WordPress).

## Estrutura

```
fzwordpress-ai/
├── fzwordpress-ai.php          # bootstrap, ativação (schema SQLite)
├── uninstall.php               # remoção limpa (guardada por realpath)
├── includes/                   # DB, Settings, LLM, RAG, Protocol, Support, Chat, REST, Widget e Admin
├── assets/                     # widget.js/css, admin.js/css
├── bin/                        # install.sh, build-llama.sh
├── .github/workflows/          # build-llama.yml (tag v*), lint.yml
├── languages/                  # fzwordpress-ai.pot
└── readme.txt                  # readme padrão WordPress
```

## Licença

GPL-2.0-or-later. Veja `LICENSE`.
