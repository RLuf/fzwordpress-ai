# FZ AI Atendimento — versão standalone (sites estáticos)

Para sites **sem WordPress**. Mesma experiência do plugin (entende, responde da base,
abre protocolo, encaminha ao WhatsApp), num único `chat.php` + o widget.

## Arquivos

```
fzwai/
├── chat.php          # backend (SQLite + RAG léxico + LLM + protocolo + WhatsApp)
├── knowledge.txt     # base de conhecimento (um assunto por parágrafo)
├── widget.js         # copiado de ../assets/js/widget.js
├── widget.css        # copiado de ../assets/css/widget.css
├── fzwai-config.php  # (opcional) sobrescreve o $CFG de chat.php — NÃO versionar
└── data/             # criado em runtime (SQLite + rate-limit); proteja o acesso web
```

## Instalar

1. Copie a pasta `fzwai/` para o docroot do site (ex.: `/fzwai/`).
2. Copie `widget.js` e `widget.css` do plugin (`assets/js`, `assets/css`) para dentro de `fzwai/`.
3. Ajuste o topo de `chat.php` (`$CFG`): backend (`ollama` ou `openai`), URL/modelo, WhatsApp, escopo, persona. Ou crie `fzwai/fzwai-config.php` retornando um array com as chaves a sobrescrever.
4. Edite `knowledge.txt` com o conteúdo do seu negócio (um tópico por parágrafo).
5. Cole o conteúdo de `embed-snippet.html` antes de `</body>` nas páginas.
6. Proteja `data/` do acesso web (um `.htaccess` com `Require all denied` já é criado; em nginx, bloqueie `location ~ /fzwai/data/`).

## Backend de IA

- **Ollama**: `backend=ollama`, `ollama_url`, `ollama_model`. Use `ollama_num_gpu=0` para forçar CPU quando a GPU estiver ocupada.
- **OpenAI-compatível** (llama-server, OpenRouter, etc.): `backend=openai`, `openai_base`, `openai_key`, `openai_model`.

Sem resposta na base ou fora do escopo → o bot abre um protocolo e devolve um link
`wa.me` com a mensagem pré-preenchida para falar com um humano.
