# Conformidade — Diretrizes de Plugins WordPress

Mapeamento das boas práticas do WordPress Plugin Handbook e das diretrizes de
distribuição para este plugin. Objetivo: poder distribuir/comercializar com
segurança jurídica e técnica.

| Diretriz | Como o plugin atende | Verificar antes de vender |
|---|---|---|
| **Licença compatível GPL** | GPL-2.0-or-later declarada no header e em `LICENSE`. | — |
| **Sem código ofuscado** | Todo o PHP/JS é legível; nada minificado-sem-fonte. | — |
| **Sanitização de entrada** | `sanitize_text_field`, `esc_url_raw`, `absint`, `wp_strip_all_tags` nas entradas; REST com `args` tipados. | Revisar o admin (campos novos). |
| **Escape de saída** | `esc_html`, `esc_attr`, `esc_url`, `wp_kses_post` na UI; respostas REST via `WP_REST_Response`. | — |
| **Nonces + capabilities** | Admin sob `manage_options` + nonce; REST público sem sessão/nonce e com rate-limit por IP. | Confirmar nonce em todas as ações AJAX do admin e ausência de `X-WP-Nonce` no widget. |
| **Prefixação** | Tudo prefixado `fzwai_` / `FZWAI_` (funções, classes, options, tabelas, hooks). | — |
| **Text domain = slug** | `fzwordpress-ai` em todo `__()/esc_html__()`; `.pot` incluído. | Rodar `wp i18n make-pot` para cobertura total. |
| **Sem "phone home" sem consentimento** | Nenhuma telemetria. Dados só vão ao backend de IA que o operador configurar. | — |
| **`readme.txt` válido** | Header, seções e Stable tag presentes. | `Tested up to` a cada release do WP. |
| **Desinstalação limpa** | `uninstall.php` remove options e o diretório de dados com verificação de `realpath` (anti-traversal); opção `fzwai_keep_data` preserva. | — |
| **Sem binários pré-compilados no zip** | O `llama.cpp` NÃO é embarcado no pacote: é baixado do GitHub Releases sob demanda ou compilado localmente. O zip permanece 100% código-fonte legível. | Não commitar `bin/dist/` (está no `.gitignore`). |
| **Segurança de shell** | `escapeshellarg` em todo argumento; `proc_open` com timeout; caminhos validados dentro de uploads/plugin. | — |

## Marca "WordPress" no nome (importante)

As diretrizes do repositório oficial **restringem o uso de "WordPress" no nome de
exibição** de plugins. Por isso:

- **Nome de exibição** usado: **"FZ AI Atendimento"** (no menu e no `readme.txt`),
  sem "WordPress" no início.
- O **slug do repositório** `fzwordpress-ai` e a marca do produto podem manter a
  referência para SEO/organização própria (distribuição fora do repositório
  oficial), mas para publicar no WordPress.org troque o display name e evite
  "WordPress" no título do `readme.txt` (já feito).

## Recomendações antes de comercializar

1. Rodar `Plugin Check` (PCP) e `wp i18n make-pot` para cobertura de i18n.
2. Definir política de privacidade (quais dados vão ao backend de IA escolhido).
3. Se vender pelo WordPress.org, revisar o display name (acima) e remover qualquer
   asset baixado do zip final.
4. Assinar/checar o build do `llama.cpp` (sha256 já previsto no workflow).
