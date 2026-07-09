# Changelog

Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.0.0/);
este projeto adere ao [Versionamento Semântico](https://semver.org/lang/pt-BR/).

## [1.0.0] — 2026-07-09

### Adicionado
- Widget de chat de pré-atendimento (flutuante, responsivo, acessível).
- Orquestração de atendimento: entende a dúvida → RAG → resposta limitada ao escopo → protocolo → WhatsApp.
- RAG em SQLite: indexação de fontes (URL, arquivo, texto), embeddings com fallback léxico, busca semântica.
- Três backends de IA: Ollama, llama.cpp embarcado e API online compatível com OpenAI.
- Protocolos numerados persistidos em SQLite com encaminhamento por `wa.me`.
- Painel administrativo (persona/prompt, backend, base de conhecimento, WhatsApp, widget).
- Endpoints REST `fzwai/v1/chat` e `fzwai/v1/greeting` com nonce e rate-limit por IP.
- Provisionamento do llama.cpp em três caminhos (binário pronto, download do Release, build local) + workflow de build por tag `v*`.
- Instalador `bin/install.sh`, `readme.txt` no padrão WordPress, i18n (`fzwordpress-ai`), desinstalação limpa.
