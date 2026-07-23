# Changelog

Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.0.0/);
este projeto adere ao [Versionamento Semântico](https://semver.org/lang/pt-BR/).

## [1.1.3] — 2026-07-23

### Corrigido

- Removidos nonce REST e cookies de sessão das requisições públicas de chat e
  suporte. Autenticação expirada em HTML cacheado podia ser recusada pelo
  WordPress antes do callback público do plugin.
- Removido o nonce do objeto público `FZWAI_WIDGET` e do exemplo standalone.
- Mantida a proteção por capacidade e nonce em todas as ações administrativas.
- Adicionado teste de regressão para preservar essa separação.

### Sincronizado

- Incorporado ao repositório o código `1.1.2` que já estava instalado no
  WordPress principal, evitando que um deploy futuro removesse recursos ativos.

## [1.1.2] — histórico reconciliado em 2026-07-23

Esta versão existia na instalação ativa antes de ser reconciliada com o
repositório. Os itens abaixo são o conjunto observado desde `1.0.0`:

- identificação inicial por nome, celular e e-mail;
- solicitação de suporte por e-mail, com protocolo e uma foto opcional;
- histórico de conversa e contatos persistidos no SQLite;
- limite de mensagens por sessão e expiração no widget;
- respostas de erro e rate limit apresentadas ao visitante;
- migração idempotente de schema durante atualizações de arquivo.

## [1.0.0] — 2026-07-09

### Adicionado
- Widget de chat de pré-atendimento (flutuante, responsivo, acessível).
- Orquestração de atendimento: entende a dúvida → RAG → resposta limitada ao escopo → protocolo → WhatsApp.
- RAG em SQLite: indexação de fontes (URL, arquivo, texto), embeddings com fallback léxico, busca semântica.
- Três backends de IA: Ollama, llama.cpp embarcado e API online compatível com OpenAI.
- Protocolos numerados persistidos em SQLite com encaminhamento por `wa.me`.
- Painel administrativo (persona/prompt, backend, base de conhecimento, WhatsApp, widget).
- Endpoints REST `fzwai/v1/chat` e `fzwai/v1/greeting` com rate-limit por IP.
- Provisionamento do llama.cpp em três caminhos (binário pronto, download do Release, build local) + workflow de build por tag `v*`.
- Instalador `bin/install.sh`, `readme.txt` no padrão WordPress, i18n (`fzwordpress-ai`), desinstalação limpa.
