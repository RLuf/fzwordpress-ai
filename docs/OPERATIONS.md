# Operação do FZ WordPress AI

## Dados e dependências

| Componente | Local/destino |
|---|---|
| Configurações | WordPress options |
| Base RAG e protocolos | SQLite em diretório protegido de uploads |
| Ollama/llama.cpp | Ambiente configurado pelo operador |
| API online | Provedor e endpoint configurados |
| Handoff | Link `wa.me`, iniciado pelo visitante |

## Backup

Antes de atualizar:

1. exportar configurações sem expor chaves;
2. copiar o SQLite com consistência;
3. guardar versão do plugin, WordPress e PHP;
4. registrar backend/modelo ativo;
5. confirmar restauração numa cópia.

## Gate

- ativação e tela administrativa sem warning;
- backend responde ao teste;
- indexação cria resultados consultáveis;
- pergunta dentro do escopo usa a base;
- pergunta fora do escopo é recusada;
- protocolo e handoff funcionam;
- widget anônimo funciona com cache;
- HTML antigo foi purgado do cache após instalar uma versão que remove nonce;
- rate limit recupera após a janela;
- erro externo aparece no diagnóstico sem segredo.

## Diagnóstico

| Sintoma | Verificar |
|---|---|
| widget `403` após upgrade de versão anterior | purgar cache de página/CDN e confirmar que o JS carregado não envia `X-WP-Nonce` |
| resposta genérica repetida | log sanitizado do backend, SQLite e modelo |
| RAG vazio | fontes, indexação, permissões e extensão SQLite |
| `429` permanente | bucket/janela e transients antigos |
| llama não inicia | arquitetura, binário, modelo, permissões e memória |

Nunca aumentar limites ou desligar segurança antes de identificar a causa.
