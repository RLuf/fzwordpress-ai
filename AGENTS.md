# AGENTS.md — fzwordpress-ai

Instruções para manter o bot de pré-atendimento da família FZ.

## 1. Papel

Plugin WordPress independente para atendimento, RAG, protocolos e handoff por
WhatsApp. Ele não participa do provisionamento nem do MCP. A integração com a
Plataforma ImovelSite 2.0 deve permanecer opcional.

## 2. Estado da autenticação pública

Desde a versão `1.1.3`, o widget público não recebe nem envia `X-WP-Nonce`
ou cookies de sessão.
Isso é intencional: um nonce REST em página cacheada expira e pode ser recusado
pelo núcleo do WordPress antes do callback público.

Preservar obrigatoriamente:

1. rota pública sem dependência de sessão ou nonce;
2. rate limit por IP de origem e limite global;
3. capacidade e nonce em todo AJAX administrativo;
4. teste de regressão `tests/PublicWidgetSecurityTest.php`;
5. purge do cache de página/CDN ao atualizar instalações anteriores à `1.1.3`.

## 3. Regras de segurança

1. Rotas públicas não dependem de sessão ou nonce.
2. Admin AJAX exige capacidade e nonce.
3. Rate limit usa janela fixa e chave confiável (`REMOTE_ADDR` no origin).
4. Headers encaminhados servem apenas para registro, salvo proxy validado.
5. Entrada livre não pode perder texto após `<`; não aplicar remoção cega.
6. Erro de SQLite/LLM/RAG precisa ser diagnosticável sem expor segredo.
7. Chaves de API não entram em Git, HTML público ou logs.
8. Conteúdo do visitante deve seguir a política de privacidade documentada.
9. URLs ingeridas pelo RAG exigem controles contra SSRF e tamanho excessivo.
10. Uninstall só remove dados após confirmação e validação de caminho.

## 4. Fluxo de mudança

1. Ler `README.md`, `docs/` e este arquivo.
2. Preservar mudanças existentes.
3. Atualizar código, `readme.txt`, changelog e guia na mesma entrega.
4. Rodar lint PHP/shell e testes disponíveis.
5. Testar WordPress limpo e instalação com dados existentes.
6. Validar backends configurados sem registrar prompts/chaves.
7. Testar widget anônimo atrás de cache.
8. Gerar ZIP e inspecionar conteúdo.
9. Commitar e publicar somente após o gate.

## 5. Testes mínimos

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
bash -n bin/install.sh bin/build-llama.sh
php tests/PublicWidgetSecurityTest.php
git diff --check
```

Se Composer/WPCS não estiver disponível, registrar a limitação; não declarar
compliance formal sem executar a ferramenta.

## 6. Documentação

Documentar backends, dados enviados, persistência, retenção, requisitos,
instalação, configuração, erros, backup, upgrade e uninstall. Diferenciar:

- processamento local Ollama/llama.cpp;
- API externa compatível com OpenAI;
- conteúdo armazenado em SQLite;
- dados enviados ao WhatsApp pelo usuário.
