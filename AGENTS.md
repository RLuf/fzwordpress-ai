# AGENTS.md — fzwordpress-ai

Instruções para manter o bot de pré-atendimento da família FZ.

## 1. Papel

Plugin WordPress independente para atendimento, RAG, protocolos e handoff por
WhatsApp. Ele não participa do provisionamento nem do MCP. A integração com a
Plataforma ImovelSite 2.0 deve permanecer opcional.

## 2. Estado e risco conhecido

O widget público ainda envia `X-WP-Nonce`. Em página cacheada, nonce expirado
pode ser rejeitado pelo WordPress antes do callback público. Antes de declarar
produção:

1. remover nonce do widget/objeto público;
2. manter nonce apenas no AJAX administrativo;
3. validar visitante anônimo com página cacheada e nonce antigo;
4. atualizar código, testes e documentação juntos.

Não ocultar esse risco em README ou release.

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
