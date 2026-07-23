=== FZ AI Atendimento ===
Contributors: webstorage, fazai
Tags: chatbot, atendimento, ai, rag, whatsapp, suporte, ollama, llama
Requires at least: 5.6
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.1.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bot de pré-atendimento com IA: entende a dúvida do visitante, responde com base no seu conhecimento (RAG em SQLite), abre protocolo e encaminha para um técnico via WhatsApp.

== Description ==

O FZ AI Atendimento coloca um atendente virtual no seu site que conversa como uma pessoa de verdade, responde dúvidas **apenas dentro do assunto que você definir** (ex.: corretagem de imóveis — não fala sobre carros) e, quando não sabe ou o visitante pede, **abre um protocolo** e encaminha para um humano pelo **WhatsApp**.

Tudo roda com os seus dados: a base de conhecimento e os protocolos ficam num banco **SQLite local**, no seu servidor. Você escolhe o motor de IA:

* **Ollama** (padrão) — aponte para um servidor Ollama (local ou remoto) e escolha o modelo (ex.: qwen, gemma, llama).
* **llama.cpp embarcado** — rode 100% offline com um binário compilado e um modelo GGUF.
* **API online compatível com OpenAI** — use uma chave de um provedor de sua escolha.

= Principais recursos =

* Widget de chat flutuante, responsivo e acessível, com a identidade do seu negócio.
* RAG simples e eficiente: indexa URLs, arquivos e textos que você cadastra; busca semântica (embeddings) com fallback léxico quando não há embeddings.
* Respostas curtas, com pouca criatividade e presas ao contexto — sem inventar preços ou promessas.
* Protocolos numerados, gravados em SQLite, com encaminhamento para o WhatsApp do atendimento.
* Painel administrativo: persona/prompt, fontes de conhecimento, número de WhatsApp, backend e modelo, cores do widget.

= Privacidade =

Nenhum dado do visitante é enviado a terceiros, exceto ao motor de IA que **você** configurar. Com Ollama ou llama.cpp, a operação pode ser 100% no seu ambiente.

== Installation ==

1. Envie a pasta do plugin para `wp-content/plugins/` e ative em **Plugins**.
2. Em **FZ AI Atendimento → Configurações**, escolha o backend:
   * **Ollama**: informe a URL (ex.: `http://SEU-SERVIDOR:11434`) e o modelo.
   * **llama.cpp**: rode `bin/install.sh --llama -v v1.0` (baixa um binário pronto do GitHub Releases, ou compila localmente, ou dispara o build por tag) e informe os caminhos do binário e do modelo `.gguf`.
   * **API online**: informe a base, a chave e o modelo.
3. Em **Base de conhecimento**, cadastre suas fontes (URLs, arquivos ou texto) e clique em **Indexar**.
4. Defina a persona, o assunto (escopo) e o **número de WhatsApp** do atendimento.
5. Ative o widget. Pronto: o atendente aparece no site.

== Frequently Asked Questions ==

= Preciso de uma chave de API paga? =
Não. Com **Ollama** ou **llama.cpp** você roda sem nenhuma chave e sem custo por requisição. A opção de API online existe para quem preferir um provedor externo.

= Roda 100% local/offline? =
Sim, com llama.cpp embarcado (ou um Ollama na sua própria rede). Nesse caso nenhum dado sai do seu ambiente.

= Que dados saem do meu site? =
Apenas o texto necessário para o motor de IA que você configurar gerar a resposta. Base de conhecimento e protocolos ficam sempre no seu SQLite local.

= Como o assunto é limitado? =
Pelo prompt de sistema e pela recusa configurável de temas fora do escopo. O modelo é instruído a responder somente com base no contexto recuperado da sua base.

= Como funciona o encaminhamento por WhatsApp? =
Ao abrir um protocolo, o plugin gera um link `wa.me` com o número do atendimento e uma mensagem pré-preenchida citando o protocolo. O visitante toca e fala com um humano.

== Screenshots ==

1. Widget de chat no site (visitante conversando com o atendente virtual).
2. Abertura de protocolo e botão de WhatsApp.
3. Painel de configurações (backend de IA, persona, WhatsApp).
4. Base de conhecimento com fontes indexadas.
5. Lista de protocolos abertos.

== Changelog ==

= 1.1.3 =
* Remove nonce e cookies de sessão das chamadas públicas de chat e suporte.
* Mantém capacidade e nonce obrigatórios em todas as ações administrativas.
* Adiciona teste de regressão do limite entre REST público e AJAX administrativo.

= 1.1.2 =
* Adiciona identificação inicial do visitante e solicitação de suporte por e-mail com protocolo e foto opcional.
* Adiciona limites de sessão, histórico e mensagens mais claras para falhas do backend.

= 1.0.0 =
* Versão inicial: widget de chat, RAG em SQLite, três backends de IA (Ollama, llama.cpp, API online), protocolos e encaminhamento por WhatsApp.

== Upgrade Notice ==

= 1.1.3 =
Evita falhas do widget público causadas por autenticação expirada em páginas cacheadas.
