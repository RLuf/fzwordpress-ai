# Release 1.1.3 — autenticação do widget público

Data: 2026-07-23.

## Objetivo

Separar definitivamente os endpoints públicos de chat/suporte da autenticação
administrativa:

- chat e suporte não enviam `X-WP-Nonce`;
- chat e suporte usam `credentials: 'omit'` e não enviam cookies;
- o objeto público `FZWAI_WIDGET` não contém nonce;
- rate limit por IP e global permanecem ativos;
- AJAX administrativo mantém `manage_options` e `check_ajax_referer`.

## Reconciliação da fonte

A instalação ativa do WordPress principal estava em `1.1.2`, enquanto o
repositório público ainda continha a base `1.0.0`. Antes do hotfix, o código
ativo `1.1.2` foi reconciliado no repositório, incluindo:

- identificação inicial do visitante;
- suporte por e-mail com protocolo e foto opcional;
- histórico/contatos no SQLite;
- limites de sessão e mensagens;
- migração idempotente de schema.

Isso evita que um deploy futuro da fonte remova recursos ativos.

## Backup e rollback

Backup anterior à mudança:

```text
/root/backups/fzwordpress-ai-live-1.1.2-before-public-auth-2026-07-23.tar.gz
SHA-256 b49ee64a6f3a106db70cf867106a92257cb3259cc46776a872bc619ad489f1a8
```

O hotfix não alterou opções, SQLite ou schema. Para rollback:

1. confirmar que o alvo é exclusivamente
   `wp-content/plugins/fzwordpress-ai`;
2. restaurar o arquivo acima preservando owner/permissões;
3. purgar WP Fastest Cache/CDN;
4. confirmar plugin ativo em `1.1.2`;
5. repetir os testes REST anônimos.

## Gate executado

- teste de regressão: 12 guardas aprovadas;
- lint PHP de todos os arquivos: aprovado;
- lint shell dos dois scripts: aprovado;
- plugin ativo no WordPress principal: versão `1.1.3`;
- homepage publicou `widget.js?ver=1.1.3`;
- configuração pública não apresentou nonce;
- `GET /wp-json/fzwai/v1/greeting`: HTTP `200`;
- `POST /wp-json/fzwai/v1/chat`, sem cookie/nonce: HTTP `200`;
- `POST /wp-json/fzwai/v1/support`, sem cookie/nonce e com campos vazios:
  HTTP `400` de validação, comprovando que não houve bloqueio `401/403`;
- cache do WP Fastest Cache e cache integrado/CDN: purgados.

Node.js não existe no host, portanto `node --check` não foi executado. O
JavaScript foi coberto pela guarda estática e pelo teste HTTP real.

O PHPCS/WPCS formal, executado com o binário disponível no repositório do
provisionador, ainda aponta dívida anterior de estilo/documentação e regras
incompatíveis com o backend standalone. Portanto esta release **não declara
conformidade WPCS integral**; a aprovação acima cobre sintaxe, regressão de
autenticação e comportamento HTTP.
