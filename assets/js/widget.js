/**
 * FZ WordPress AI — widget de chat (front-end).
 *
 * Vanilla JS, sem dependências. Constrói uma bolha flutuante (ou um painel
 * embutido via shortcode) que conversa com o endpoint REST /fzwai/v1/chat.
 * Toda a configuração vem de window.FZWAI_WIDGET (injetada por PHP/localize).
 *
 * Objetivos: parecer um atendente humano (indicador de "digitando", pequeno
 * atraso natural, digitação progressiva), ser acessível (foco, ARIA, teclado,
 * prefers-reduced-motion) e nunca injetar HTML de terceiros (tudo textContent).
 *
 * @package FZWordPressAI
 */
( function () {
	'use strict';

	var CFG = window.FZWAI_WIDGET;
	if ( ! CFG || ! CFG.rest ) {
		return;
	}

	var I18N = CFG.i18n || {};
	var reduceMotion = !! ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches );

	// Chaves de localStorage (contexto persiste entre recarregamentos).
	var LS_SESSION = 'fzwai_session';
	var LS_HISTORY = 'fzwai_history';
	var LS_NAME    = 'fzwai_name';
	var LS_CONTACT = 'fzwai_contact';
	var LS_EMAIL   = 'fzwai_email';
	var LS_SEEN    = 'fzwai_seen';

	var MAX_HISTORY = 100;
	var SEQ = 0;

	/* ---------------------------------------------------------------- *
	 *  Ícones (SVG estáticos — strings confiáveis, nunca dados do usuário)
	 * ---------------------------------------------------------------- */
	var ICON_CHAT  = '<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>';
	var ICON_CLOSE = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>';
	var ICON_SEND  = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 2 11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>';
	var ICON_RESET = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>';
	var ICON_WA    = '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M17.5 14.4c-.3-.15-1.7-.83-1.96-.93-.26-.1-.46-.15-.65.15-.19.28-.74.92-.9 1.1-.17.2-.33.22-.62.08-1.7-.85-2.82-1.52-3.94-3.44-.3-.51.3-.48.85-1.58.1-.19.05-.35-.02-.5-.08-.14-.65-1.57-.9-2.15-.24-.57-.48-.49-.65-.5h-.56c-.19 0-.5.07-.77.36-.26.28-1 .98-1 2.4s1.03 2.78 1.17 2.97c.14.19 2.02 3.08 4.9 4.32.68.29 1.22.47 1.63.6.69.22 1.31.19 1.8.12.55-.08 1.7-.7 1.94-1.36.24-.67.24-1.24.17-1.36-.07-.12-.26-.19-.55-.34z"/><path d="M12 2A10 10 0 0 0 3.48 17.2L2 22l4.9-1.45A10 10 0 1 0 12 2zm0 18.2a8.2 8.2 0 0 1-4.2-1.15l-.3-.18-2.9.9.87-2.83-.2-.3A8.2 8.2 0 1 1 12 20.2z"/></svg>';

	/* ---------------------------------------------------------------- *
	 *  Utilidades genéricas
	 * ---------------------------------------------------------------- */

	function t( key, fallback ) {
		return ( I18N[ key ] != null && I18N[ key ] !== '' ) ? I18N[ key ] : fallback;
	}

	function lsGet( k ) {
		try { return window.localStorage.getItem( k ); } catch ( e ) { return null; }
	}
	function lsSet( k, v ) {
		try { window.localStorage.setItem( k, v ); } catch ( e ) {}
	}
	function lsDel( k ) {
		try { window.localStorage.removeItem( k ); } catch ( e ) {}
	}

	function uuid() {
		if ( window.crypto && window.crypto.randomUUID ) {
			try { return window.crypto.randomUUID(); } catch ( e ) {}
		}
		return 'fz-' + Date.now().toString( 36 ) + '-' + Math.random().toString( 36 ).slice( 2, 10 );
	}

	// Cria elemento com atributos/filhos. 'html' só recebe SVG estático nosso.
	function el( tag, attrs, kids ) {
		var n = document.createElement( tag );
		if ( attrs ) {
			for ( var k in attrs ) {
				if ( ! Object.prototype.hasOwnProperty.call( attrs, k ) ) { continue; }
				var v = attrs[ k ];
				if ( v == null || v === false ) { continue; }
				if ( k === 'class' ) { n.className = v; }
				else if ( k === 'text' ) { n.textContent = v; }
				else if ( k === 'html' ) { n.innerHTML = v; }
				else if ( k.slice( 0, 2 ) === 'on' && typeof v === 'function' ) { n.addEventListener( k.slice( 2 ), v ); }
				else if ( v === true ) { n.setAttribute( k, '' ); }
				else { n.setAttribute( k, v ); }
			}
		}
		if ( kids != null ) {
			if ( ! Array.isArray( kids ) ) { kids = [ kids ]; }
			for ( var i = 0; i < kids.length; i++ ) {
				var c = kids[ i ];
				if ( c == null ) { continue; }
				n.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c );
			}
		}
		return n;
	}

	function interpolate( str, map ) {
		return String( str == null ? '' : str ).replace( /\{(\w+)\}/g, function ( m, key ) {
			return ( map[ key ] != null ) ? map[ key ] : '';
		} );
	}

	function isValidUrl( u ) {
		return typeof u === 'string' && /^https?:\/\//i.test( u );
	}

	function initial( s ) {
		s = ( s || '' ).trim();
		return s ? s.charAt( 0 ).toUpperCase() : 'A';
	}

	// hex → [r,g,b] ou null.
	function hexRgb( hex ) {
		var c = String( hex || '' ).replace( '#', '' );
		if ( c.length === 3 ) { c = c.replace( /(.)/g, '$1$1' ); }
		if ( ! /^[0-9a-fA-F]{6}$/.test( c ) ) { return null; }
		return [ parseInt( c.substr( 0, 2 ), 16 ), parseInt( c.substr( 2, 2 ), 16 ), parseInt( c.substr( 4, 2 ), 16 ) ];
	}

	// Escolhe texto escuro/claro para contraste sobre o acento (WCAG).
	function contrastColor( hex ) {
		var rgb = hexRgb( hex );
		if ( ! rgb ) { return '#ffffff'; }
		function lin( x ) { x /= 255; return x <= 0.03928 ? x / 12.92 : Math.pow( ( x + 0.055 ) / 1.055, 2.4 ); }
		var L = 0.2126 * lin( rgb[ 0 ] ) + 0.7152 * lin( rgb[ 1 ] ) + 0.0722 * lin( rgb[ 2 ] );
		return L > 0.55 ? '#12151b' : '#ffffff';
	}

	function softColor( hex ) {
		var rgb = hexRgb( hex );
		if ( ! rgb ) { return 'rgba(47,126,196,.16)'; }
		return 'rgba(' + rgb[ 0 ] + ',' + rgb[ 1 ] + ',' + rgb[ 2 ] + ',.16)';
	}

	/* ---------------------------------------------------------------- *
	 *  Estado compartilhado (sessão/contato entre float e inline)
	 * ---------------------------------------------------------------- */

	function getSession() {
		var s = lsGet( LS_SESSION );
		if ( ! s ) { s = uuid(); lsSet( LS_SESSION, s ); }
		return s;
	}

	function loadHistory() {
		try {
			var raw = lsGet( LS_HISTORY );
			var arr = raw ? JSON.parse( raw ) : [];
			return Array.isArray( arr ) ? arr : [];
		} catch ( e ) { return []; }
	}

	function saveHistory( history ) {
		try {
			if ( history.length > MAX_HISTORY ) {
				history = history.slice( history.length - MAX_HISTORY );
			}
			lsSet( LS_HISTORY, JSON.stringify( history ) );
		} catch ( e ) {}
		return history;
	}

	/* ---------------------------------------------------------------- *
	 *  Instância do widget (uma por ponto de montagem)
	 * ---------------------------------------------------------------- */

	function initWidget( mount ) {
		var uid      = 'fzwai-' + ( ++SEQ );
		var isInline = mount.getAttribute( 'data-mode' ) === 'inline';

		// Aplica acento/contraste/suave via variáveis CSS (uma fonte de verdade).
		var accent = CFG.color || '#2f7ec4';
		mount.style.setProperty( '--fzwai-accent', accent );
		mount.style.setProperty( '--fzwai-accent-contrast', contrastColor( accent ) );
		mount.style.setProperty( '--fzwai-accent-soft', softColor( accent ) );

		if ( ! isInline ) {
			mount.classList.add( 'fzwai-widget--float' );
			mount.setAttribute( 'data-position', CFG.position === 'left' ? 'left' : 'right' );
		}

		var title     = mount.getAttribute( 'data-title' ) || CFG.title || '';
		var assistant = CFG.assistant || '';
		var business  = CFG.business || '';

		// --- Estado da instância ---
		var session        = getSession();
		var history        = loadHistory();
		var visitor        = { name: lsGet( LS_NAME ) || '', contact: lsGet( LS_CONTACT ) || '', email: lsGet( LS_EMAIL ) || '' };
		var pendingMessage = null;
		var busy           = false;
		var greeted        = history.length > 0;
		var gated          = false; // formulário de gate exibido nesta instância
		var typingEl       = null;
		var idleTimer      = null;
		var IDLE_MS        = 25 * 60 * 1000; // limpa a sessão após 25 min sem atividade
		var gen            = 0;  // token de geração: invalida callbacks de sessões já limpas

		/* ---------------- DOM: cabeçalho, lista, input ---------------- */

		function avatarNode() {
			return el( 'div', { 'class': 'fzwai-avatar', 'aria-hidden': 'true', text: initial( assistant || business ) } );
		}

		var list = el( 'div', {
			'class': 'fzwai-messages',
			'id': uid + '-log',
			'role': 'log',
			'aria-live': 'polite',
			'aria-relevant': 'additions',
			'aria-label': t( 'message_label', 'Mensagem' )
		} );

		var titleEl = el( 'div', { 'class': 'fzwai-title', text: title } );
		var statusText = assistant ? assistant + ' · ' + t( 'online', 'Online agora' ) : t( 'online', 'Online agora' );
		var subEl = el( 'div', { 'class': 'fzwai-sub' }, [
			el( 'span', { 'class': 'fzwai-status-dot', 'aria-hidden': 'true' } ),
			el( 'span', { text: statusText } )
		] );

		var resetBtn = el( 'button', {
			'class': 'fzwai-icon-btn fzwai-reset', 'type': 'button', 'aria-label': t( 'restart', 'Iniciar nova conversa' ), 'title': t( 'restart', 'Iniciar nova conversa' ), html: ICON_RESET
		} );

		var headActions = [ resetBtn ];
		var closeBtn = null;
		if ( ! isInline ) {
			closeBtn = el( 'button', {
				'class': 'fzwai-icon-btn fzwai-close', 'type': 'button', 'aria-label': t( 'close', 'Fechar' ), 'title': t( 'close', 'Fechar' ), html: ICON_CLOSE
			} );
			headActions.push( closeBtn );
		}

		var header = el( 'div', { 'class': 'fzwai-header' }, [
			avatarNode(),
			el( 'div', { 'class': 'fzwai-head-text' }, [ titleEl, subEl ] ),
			el( 'div', { 'class': 'fzwai-head-actions' }, headActions )
		] );

		var input = el( 'textarea', {
			'class': 'fzwai-text', 'rows': '1', 'aria-label': t( 'message_label', 'Mensagem' ),
			'placeholder': t( 'placeholder', 'Escreva sua mensagem…' ), 'autocomplete': 'off'
		} );

		var sendBtn = el( 'button', {
			'class': 'fzwai-send', 'type': 'submit', 'aria-label': t( 'send', 'Enviar' ), 'title': t( 'send', 'Enviar' ), html: ICON_SEND
		} );

		var inputForm = el( 'form', { 'class': 'fzwai-input' }, [ input, sendBtn ] );

		var foot = el( 'div', { 'class': 'fzwai-foot' }, [ el( 'span', { text: t( 'powered', 'Atendimento inteligente' ) } ) ] );

		var panel = el( 'div', {
			'class': 'fzwai-panel', 'id': uid + '-panel',
			'role': isInline ? 'region' : 'dialog',
			'aria-label': title || t( 'open', 'Atendimento' )
		}, [ header, list, inputForm, foot ] );

		if ( ! isInline ) {
			panel.setAttribute( 'aria-modal', 'false' );
		}

		/* ---------------- Bolha flutuante (só no modo float) ---------------- */

		var bubble = null;
		var isOpen = isInline;

		if ( ! isInline ) {
			var showDot = ( ! greeted && ! lsGet( LS_SEEN ) );
			bubble = el( 'button', {
				'class': 'fzwai-bubble', 'type': 'button',
				'aria-label': t( 'open', 'Abrir atendimento' ),
				'aria-expanded': 'false', 'aria-controls': uid + '-panel',
				html: '<span class="fzwai-ic fzwai-ic-chat">' + ICON_CHAT + '</span>' +
					'<span class="fzwai-ic fzwai-ic-close">' + ICON_CLOSE + '</span>' +
					'<span class="fzwai-bubble-dot' + ( showDot ? '' : ' is-hidden' ) + '" aria-hidden="true"></span>'
			} );
			mount.appendChild( bubble );
			mount.appendChild( panel );
		} else {
			mount.appendChild( panel );
		}

		/* ---------------- Render de mensagens ---------------- */

		function scrollBottom() {
			window.requestAnimationFrame( function () { list.scrollTop = list.scrollHeight; } );
		}

		function makeRow( role ) {
			var isUser = role === 'user';
			var row = el( 'div', { 'class': 'fzwai-msg fzwai-msg--' + ( isUser ? 'user' : 'bot' ) } );
			if ( ! isUser ) { row.appendChild( avatarNode() ); }
			var content = el( 'div', { 'class': 'fzwai-content' } );
			var bubbleMsg = el( 'div', { 'class': 'fzwai-bubble-msg' } );
			content.appendChild( bubbleMsg );
			row.appendChild( content );
			return { row: row, content: content, bubble: bubbleMsg };
		}

		function renderUser( text ) {
			var r = makeRow( 'user' );
			r.bubble.textContent = text;
			list.appendChild( r.row );
			scrollBottom();
		}

		function protocolChip( no ) {
			return el( 'div', { 'class': 'fzwai-protocol' }, [
				el( 'span', { 'class': 'fzwai-protocol-label', text: t( 'protocol', 'Protocolo' ) } ),
				el( 'strong', { 'class': 'fzwai-protocol-no', text: no } )
			] );
		}

		function waButton( handoff ) {
			// Rótulo curto e fixo: handoff.message é a frase inteira do protocolo,
			// que o servidor já colocou no corpo da resposta — usá-la aqui duplicava
			// a mensagem e virava um botão de um parágrafo.
			var label = t( 'whatsapp', 'Falar no WhatsApp' );
			var a = el( 'a', {
				'class': 'fzwai-wa', 'href': handoff.url, 'target': '_blank', 'rel': 'noopener noreferrer', html: ICON_WA
			} );
			a.appendChild( el( 'span', { text: label } ) );
			return a;
		}

		// Preenche um elemento com texto, transformando URLs http(s) em links seguros.
		function setLinkified( node, text ) {
			node.textContent = '';
			var re = /(https?:\/\/[^\s<]+)/g;
			var last = 0, m;
			while ( ( m = re.exec( text ) ) ) {
				if ( m.index > last ) { node.appendChild( document.createTextNode( text.slice( last, m.index ) ) ); }
				var url = m[ 0 ];
				node.appendChild( el( 'a', { 'class': 'fzwai-link', 'href': url, 'target': '_blank', 'rel': 'noopener noreferrer', text: url } ) );
				last = re.lastIndex;
			}
			if ( last < text.length ) { node.appendChild( document.createTextNode( text.slice( last ) ) ); }
		}

		// Digitação progressiva (palavra a palavra). Instantânea se reduzir movimento.
		function typeInto( node, text, done ) {
			if ( reduceMotion ) { node.textContent = text; if ( done ) { done(); } return; }
			var words = String( text ).split( /(\s+)/ );
			var i = 0;
			node.textContent = '';
			var per = Math.max( 12, Math.min( 45, Math.floor( 900 / Math.max( 6, words.length ) ) ) );
			( function step() {
				if ( i >= words.length ) { if ( done ) { done(); } return; }
				node.textContent += words[ i++ ];
				scrollBottom();
				window.setTimeout( step, per );
			} )();
		}

		function normHandoff( h ) {
			if ( h && typeof h === 'object' && h.type === 'whatsapp' && isValidUrl( h.url ) ) {
				return { type: 'whatsapp', url: h.url, message: ( h.message ? String( h.message ) : '' ) };
			}
			return null;
		}

		// m = { role:'assistant', text, protocol, handoff }
		function renderBot( m, opts ) {
			opts = opts || {};
			var r = makeRow( 'bot' );
			if ( opts.cls ) { r.bubble.classList.add( opts.cls ); }
			var textSpan = el( 'span', { 'class': 'fzwai-msg-text' } );
			r.bubble.appendChild( textSpan );
			list.appendChild( r.row );
			scrollBottom();

			function finish() {
				setLinkified( textSpan, m.text );
				if ( m.protocol ) { r.content.appendChild( protocolChip( m.protocol ) ); }
				if ( m.handoff ) { r.content.appendChild( waButton( m.handoff ) ); }
				scrollBottom();
				if ( opts.done ) { opts.done(); }
			}

			if ( opts.instant ) { finish(); }
			else { typeInto( textSpan, m.text, finish ); }
		}

		function pushHistory( m ) {
			history.push( { role: m.role, text: m.text, protocol: m.protocol || null, handoff: m.handoff || null } );
			history = saveHistory( history );
		}

		function renderFromHistory( m ) {
			if ( m.role === 'user' ) { renderUser( m.text ); }
			else { renderBot( { text: m.text, protocol: m.protocol || null, handoff: normHandoff( m.handoff ) }, { instant: true } ); }
		}

		/* ---------------- Indicador de "digitando" ---------------- */

		function showTyping() {
			if ( typingEl ) { return; }
			typingEl = el( 'div', { 'class': 'fzwai-msg fzwai-msg--bot fzwai-typing-row' }, [
				avatarNode(),
				el( 'div', { 'class': 'fzwai-content' }, [
					el( 'div', { 'class': 'fzwai-bubble-msg fzwai-typing', 'aria-label': t( 'typing', 'digitando…' ), 'role': 'status' }, [
						el( 'span', { 'class': 'fzwai-dot' } ), el( 'span', { 'class': 'fzwai-dot' } ), el( 'span', { 'class': 'fzwai-dot' } )
					] )
				] )
			] );
			list.appendChild( typingEl );
			scrollBottom();
		}

		function hideTyping() {
			if ( typingEl && typingEl.parentNode ) { typingEl.parentNode.removeChild( typingEl ); }
			typingEl = null;
		}

		function humanDelay() {
			return 700 + Math.floor( Math.random() * 800 );
		}

		/* ---------------- Formulário de contato (need_contact) ---------------- */

		function showContactForm() {
			var nameInput = el( 'input', { 'type': 'text', 'class': 'fzwai-field', 'id': uid + '-name', 'autocomplete': 'name', 'placeholder': t( 'name_ph', '' ) } );
			nameInput.value = visitor.name || '';
			var contactInput = el( 'input', { 'type': 'tel', 'class': 'fzwai-field', 'id': uid + '-contact', 'autocomplete': 'tel', 'placeholder': t( 'contact_ph', '' ) } );
			contactInput.value = visitor.contact || '';

			var form = el( 'form', { 'class': 'fzwai-bubble-msg fzwai-contact' }, [
				el( 'label', { 'for': uid + '-name', text: t( 'name_label', 'Seu nome' ) } ),
				nameInput,
				el( 'label', { 'for': uid + '-contact', text: t( 'contact_label', 'Telefone / WhatsApp' ) } ),
				contactInput,
				el( 'button', { 'type': 'submit', 'class': 'fzwai-confirm', text: t( 'confirm', 'Confirmar' ) } )
			] );

			var wrap = el( 'div', { 'class': 'fzwai-msg fzwai-msg--bot' }, [ avatarNode(), el( 'div', { 'class': 'fzwai-content' }, [ form ] ) ] );
			list.appendChild( wrap );
			scrollBottom();
			window.setTimeout( function () { nameInput.focus(); }, reduceMotion ? 0 : 60 );

			form.addEventListener( 'input', bumpIdle );

			form.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var name = nameInput.value.trim();
				var contact = contactInput.value.trim();
				if ( ! contact ) { contactInput.focus(); return; }

				visitor.name = name;
				visitor.contact = contact;
				lsSet( LS_NAME, name );
				lsSet( LS_CONTACT, contact );

				var summary = name ? ( name + ' — ' + contact ) : contact;
				renderUser( summary );
				pushHistory( { role: 'user', text: summary } );

				if ( wrap.parentNode ) { wrap.parentNode.removeChild( wrap ); }

				var msg = pendingMessage || '';
				pendingMessage = null;
				doSend( msg, { silentUser: true, name: name, contact: contact } );
			} );
		}

		/* ---------------- Gate de pré-cadastro (nome/celular/e-mail) ---------------- */

		function emailValid( v ) {
			return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( String( v || '' ) );
		}
		function phoneValid( v ) {
			return ( String( v || '' ).replace( /\D/g, '' ).length >= 10 );
		}
		function gateOk() {
			return !! ( visitor.name && phoneValid( visitor.contact ) && emailValid( visitor.email ) );
		}

		// Exibe o formulário obrigatório de abertura e bloqueia o input até
		// o visitante informar nome completo + celular + e-mail.
		function requireGate() {
			if ( gated || gateOk() ) { return; }
			gated = true;
			setBusy( true ); // trava input/enviar enquanto o gate não é satisfeito

			var nameInput = el( 'input', { 'type': 'text', 'class': 'fzwai-field', 'id': uid + '-g-name', 'autocomplete': 'name', 'placeholder': t( 'name_ph', '' ) } );
			nameInput.value = visitor.name || '';
			var phoneInput = el( 'input', { 'type': 'tel', 'class': 'fzwai-field', 'id': uid + '-g-phone', 'autocomplete': 'tel', 'placeholder': t( 'contact_ph', '' ) } );
			phoneInput.value = visitor.contact || '';
			var mailInput = el( 'input', { 'type': 'email', 'class': 'fzwai-field', 'id': uid + '-g-mail', 'autocomplete': 'email', 'placeholder': t( 'email_ph', '' ) } );
			mailInput.value = visitor.email || '';
			var errEl = el( 'div', { 'class': 'fzwai-form-err', 'aria-live': 'polite' } );

			var form = el( 'form', { 'class': 'fzwai-bubble-msg fzwai-contact fzwai-gate' }, [
				el( 'p', { 'class': 'fzwai-form-intro', text: t( 'gate_intro', '' ) } ),
				el( 'label', { 'for': uid + '-g-name', text: t( 'name_label', 'Nome completo' ) } ),
				nameInput,
				el( 'label', { 'for': uid + '-g-phone', text: t( 'contact_label', 'Celular / WhatsApp' ) } ),
				phoneInput,
				el( 'label', { 'for': uid + '-g-mail', text: t( 'email_label', 'E-mail' ) } ),
				mailInput,
				errEl,
				el( 'button', { 'type': 'submit', 'class': 'fzwai-confirm', text: t( 'gate_start', 'Iniciar atendimento' ) } )
			] );

			var wrap = el( 'div', { 'class': 'fzwai-msg fzwai-msg--bot' }, [ avatarNode(), el( 'div', { 'class': 'fzwai-content' }, [ form ] ) ] );
			list.appendChild( wrap );
			scrollBottom();
			window.setTimeout( function () { nameInput.focus(); }, reduceMotion ? 0 : 60 );

			form.addEventListener( 'input', bumpIdle ); // digitar mantém a sessão viva

			form.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var name = nameInput.value.trim();
				var phone = phoneInput.value.trim();
				var mail = mailInput.value.trim();
				if ( ! name ) { errEl.textContent = t( 'gate_invalid_name', 'Informe seu nome completo.' ); nameInput.focus(); return; }
				if ( ! phoneValid( phone ) ) { errEl.textContent = t( 'gate_invalid_phone', 'Informe um celular válido.' ); phoneInput.focus(); return; }
				if ( ! emailValid( mail ) ) { errEl.textContent = t( 'gate_invalid_email', 'Informe um e-mail válido.' ); mailInput.focus(); return; }

				visitor.name = name;
				visitor.contact = phone;
				visitor.email = mail;
				lsSet( LS_NAME, name );
				lsSet( LS_CONTACT, phone );
				lsSet( LS_EMAIL, mail );

				if ( wrap.parentNode ) { wrap.parentNode.removeChild( wrap ); }
				gated = false;
				setBusy( false );
				if ( ! greeted ) { greet(); }
				window.setTimeout( function () { input.focus(); }, reduceMotion ? 0 : 60 );
			} );
		}

		/* ---------------- Formulário de solicitação de suporte ---------------- */

		function showSupportForm() {
			var subjInput = el( 'input', { 'type': 'text', 'class': 'fzwai-field', 'id': uid + '-s-subj', 'maxlength': '120', 'placeholder': t( 'support_subject_ph', '' ) } );
			var msgInput = el( 'textarea', { 'class': 'fzwai-field fzwai-textarea', 'id': uid + '-s-msg', 'maxlength': '2000', 'rows': '4', 'placeholder': t( 'support_message_ph', '' ) } );
			var fileInput = el( 'input', { 'type': 'file', 'class': 'fzwai-file', 'id': uid + '-s-file', 'accept': 'image/*' } );
			var errEl = el( 'div', { 'class': 'fzwai-form-err', 'aria-live': 'polite' } );
			var sendBtnS = el( 'button', { 'type': 'submit', 'class': 'fzwai-confirm', text: t( 'support_send', 'Enviar solicitação' ) } );
			var cancelBtn = el( 'button', { 'type': 'button', 'class': 'fzwai-cancel', text: t( 'cancel', 'Cancelar' ) } );

			var form = el( 'form', { 'class': 'fzwai-bubble-msg fzwai-contact fzwai-support' }, [
				el( 'label', { 'for': uid + '-s-subj', text: t( 'support_subject_label', 'Assunto' ) } ),
				subjInput,
				el( 'label', { 'for': uid + '-s-msg', text: t( 'support_message_label', 'Mensagem' ) } ),
				msgInput,
				el( 'label', { 'for': uid + '-s-file', text: t( 'support_photo_label', 'Anexar 1 foto (opcional)' ) } ),
				fileInput,
				errEl,
				el( 'div', { 'class': 'fzwai-form-actions' }, [ sendBtnS, cancelBtn ] )
			] );

			var wrap = el( 'div', { 'class': 'fzwai-msg fzwai-msg--bot' }, [ avatarNode(), el( 'div', { 'class': 'fzwai-content' }, [ form ] ) ] );
			list.appendChild( wrap );
			scrollBottom();
			window.setTimeout( function () { subjInput.focus(); }, reduceMotion ? 0 : 60 );

			cancelBtn.addEventListener( 'click', function () {
				if ( wrap.parentNode ) { wrap.parentNode.removeChild( wrap ); }
			} );

			form.addEventListener( 'input', bumpIdle ); // digitar/anexar mantém a sessão viva

			form.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				errEl.textContent = '';
				var subject = subjInput.value.trim();
				var message = msgInput.value.trim();
				if ( ! subject ) { errEl.textContent = t( 'support_need_subject', 'Informe o assunto.' ); subjInput.focus(); return; }
				if ( ! message ) { errEl.textContent = t( 'support_need_message', 'Escreva a mensagem.' ); msgInput.focus(); return; }

				var file = ( fileInput.files && fileInput.files.length ) ? fileInput.files[0] : null;
				if ( file ) {
					if ( file.size > 5 * 1024 * 1024 ) { errEl.textContent = t( 'support_photo_big', 'A imagem excede 5 MB.' ); return; }
					if ( ! /^image\//.test( file.type ) ) { errEl.textContent = t( 'support_photo_type', 'O anexo precisa ser uma imagem (JPG, PNG ou WebP).' ); return; }
				}

				var fd = new FormData();
				fd.append( 'session_id', session );
				fd.append( 'name', visitor.name );
				fd.append( 'contact', visitor.contact );
				fd.append( 'email', visitor.email );
				fd.append( 'subject', subject );
				fd.append( 'message', message );
				fd.append( 'page_url', window.location.href );
				if ( file ) { fd.append( 'photo', file ); }

				sendBtnS.disabled = true;
				cancelBtn.disabled = true;
				sendBtnS.textContent = t( 'support_sending', 'Enviando…' );

				window.fetch( CFG.support, {
					method: 'POST',
					credentials: 'omit',
					body: fd
				} ).then( function ( res ) {
					return res.json().then( function ( d ) { return { ok: res.ok, data: d }; }, function () { return { ok: res.ok, data: {} }; } );
				} ).then( function ( r ) {
					if ( r.ok && r.data && r.data.ok ) {
						if ( wrap.parentNode ) { wrap.parentNode.removeChild( wrap ); }
						var proto = r.data.protocol || '';
						var okText = t( 'support_ok', 'Solicitação enviada! Protocolo {protocolo}.' ).replace( '{protocolo}', proto );
						renderBot( { text: okText, protocol: null, handoff: null }, { instant: true } );
						// Chamado enviado: limpa a sessão (mantém a confirmação na tela;
						// a próxima abertura começa do zero).
						clearSession( false );
					} else {
						var em = ( r.data && r.data.error ) ? r.data.error : t( 'error', 'Estamos com instabilidade, tente novamente.' );
						errEl.textContent = em;
						sendBtnS.disabled = false;
						cancelBtn.disabled = false;
						sendBtnS.textContent = t( 'support_send', 'Enviar solicitação' );
					}
				} ).catch( function () {
					errEl.textContent = t( 'error', 'Estamos com instabilidade, tente novamente.' );
					sendBtnS.disabled = false;
					cancelBtn.disabled = false;
					sendBtnS.textContent = t( 'support_send', 'Enviar solicitação' );
				} );
			} );
		}

		/* ---------------- Fluxo de envio ---------------- */

		function setBusy( b ) {
			busy = b;
			input.disabled = b;
			sendBtn.disabled = b;
		}

		function onError( serverMsg ) {
			hideTyping();
			// Mostra a mensagem que o servidor mandou (ex.: aviso de rate limit,
			// que orienta a esperar) em vez de reduzir tudo a "instabilidade".
			var text = ( serverMsg && typeof serverMsg === 'string' ) ? serverMsg : t( 'error', 'Estamos com instabilidade, tente novamente.' );
			renderBot( { text: text, protocol: null, handoff: null }, { instant: true, cls: 'fzwai-error' } );
			setBusy( false );
		}

		function onReply( data, originalMessage ) {
			hideTyping();
			data = data || {};
			var reply = ( typeof data.reply === 'string' ) ? data.reply : '';
			var protocol = ( typeof data.protocol === 'string' && data.protocol ) ? data.protocol : null;
			var handoff = normHandoff( data.handoff );
			var needContact = !! data.need_contact;
			var supportForm = !! data.support_form;

			var finalize = function () {
				if ( supportForm ) { showSupportForm(); }
				else if ( needContact ) { pendingMessage = originalMessage; showContactForm(); }
				setBusy( false );
			};

			if ( reply ) {
				var m = { role: 'assistant', text: reply, protocol: protocol, handoff: handoff };
				pushHistory( m );
				renderBot( m, { instant: reduceMotion, done: finalize } );
			} else if ( needContact ) {
				renderBot( { text: t( 'need_contact', '' ), protocol: null, handoff: null }, { instant: reduceMotion, done: finalize } );
			} else {
				finalize();
			}
		}

		function doSend( message, opts ) {
			opts = opts || {};
			message = ( message == null ? '' : String( message ) ).trim();
			if ( busy ) { return; }
			bumpIdle();

			if ( ! opts.silentUser ) {
				if ( ! message ) { return; }
				renderUser( message );
				pushHistory( { role: 'user', text: message } );
				input.value = '';
				autoGrow();
			}

			setBusy( true );
			showTyping();

			var myGen = gen; // se a sessão for limpa antes da resposta, descarta o callback
			var body = { session_id: session, message: message, page_url: window.location.href };
			var name = ( opts.name != null ) ? opts.name : visitor.name;
			var contact = ( opts.contact != null ) ? opts.contact : visitor.contact;
			if ( name ) { body.name = name; }
			if ( contact ) { body.contact = contact; }
			if ( visitor.email ) { body.email = visitor.email; }

			var tStart = Date.now();

			// Sem X-WP-Nonce: o endpoint é público (a permissão não valida nonce)
			// e um nonce vencido embutido em página cacheada fazia o WP devolver
			// 403 rest_cookie_invalid_nonce para TODO request do visitante.
			window.fetch( CFG.rest, {
				method: 'POST',
				credentials: 'omit',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( body )
			} ).then( function ( res ) {
				if ( ! res.ok ) {
					// Tenta aproveitar a mensagem de erro do WP (ex.: rate limit).
					return res.json().then( function ( err ) {
						var msg = ( err && typeof err.message === 'string' ) ? err.message.replace( /<[^>]*>/g, '' ) : '';
						throw new Error( msg || ( 'HTTP ' + res.status ) );
					}, function () { throw new Error( 'HTTP ' + res.status ); } );
				}
				return res.json();
			} ).then( function ( data ) {
				if ( myGen !== gen ) { return; } // sessão limpa durante a requisição
				var wait = reduceMotion ? 0 : Math.max( 0, humanDelay() - ( Date.now() - tStart ) );
				window.setTimeout( function () { if ( myGen === gen ) { onReply( data, message ); } }, wait );
			} ).catch( function ( e ) {
				if ( myGen !== gen ) { return; }
				var wait = reduceMotion ? 0 : Math.max( 0, 400 - ( Date.now() - tStart ) );
				var serverMsg = ( e && e.message && ! /^HTTP \d+$/.test( e.message ) ) ? e.message : null;
				window.setTimeout( function () { if ( myGen === gen ) { onError( serverMsg ); } }, wait );
			} );
		}

		/* ---------------- Saudação inicial ---------------- */

		function greet() {
			greeted = true;
			var text = interpolate( CFG.greeting || '', { assistente: assistant, empresa: business, assistant: assistant, business: business } );
			if ( ! text ) { return; }
			var myGen = gen;
			showTyping();
			var delay = reduceMotion ? 0 : humanDelay();
			window.setTimeout( function () {
				if ( myGen !== gen ) { return; } // sessão limpa antes da saudação aparecer
				hideTyping();
				var m = { role: 'assistant', text: text, protocol: null, handoff: null };
				pushHistory( m );
				renderBot( m, { instant: reduceMotion } );
			}, delay );
		}

		/* ---------------- Abrir / fechar (float) ---------------- */

		function open() {
			isOpen = true;
			mount.classList.add( 'fzwai-widget--open' );
			if ( bubble ) { bubble.setAttribute( 'aria-expanded', 'true' ); }
			lsSet( LS_SEEN, '1' );
			var dot = mount.querySelector( '.fzwai-bubble-dot' );
			if ( dot ) { dot.classList.add( 'is-hidden' ); }
			// Gate obrigatório: sem nome/celular/e-mail, pede os dados antes de tudo.
			if ( ! gateOk() ) { requireGate(); }
			else if ( ! greeted ) { greet(); }
			window.setTimeout( function () { input.focus(); }, reduceMotion ? 0 : 160 );
			scrollBottom();
			bumpIdle();
		}

		// Limpa a conversa (histórico + sessão). Mantém os dados do gate
		// (nome/celular/e-mail) para o visitante recorrente não recadastrar.
		// wipeDom=true zera também as bolhas na tela.
		function clearSession( wipeDom ) {
			gen++; // invalida qualquer callback (reply/greet/typing) em voo da sessão anterior
			history = [];
			saveHistory( history );
			lsDel( LS_SESSION );
			lsDel( LS_HISTORY );
			session = getSession();
			greeted = false;
			pendingMessage = null;
			gated = false;
			hideTyping();
			setBusy( false ); // uma requisição em voo não pode deixar o input travado
			if ( idleTimer ) { window.clearTimeout( idleTimer ); idleTimer = null; }
			if ( wipeDom ) { while ( list.firstChild ) { list.removeChild( list.firstChild ); } }
		}

		// Reinicia o cronômetro de inatividade a cada interação.
		function bumpIdle() {
			if ( idleTimer ) { window.clearTimeout( idleTimer ); }
			idleTimer = window.setTimeout( function () {
				clearSession( true );
				if ( isOpen ) {
					if ( ! gateOk() ) { requireGate(); }
					else { greet(); }
				}
			}, IDLE_MS );
		}

		function close() {
			isOpen = false;
			mount.classList.remove( 'fzwai-widget--open' );
			if ( bubble ) { bubble.setAttribute( 'aria-expanded', 'false' ); bubble.focus(); }
			// Limpa ao fechar: a próxima abertura começa do zero (sem texto antigo).
			clearSession( true );
		}

		function reset() {
			clearSession( true );
			if ( ! gateOk() ) { requireGate(); }
			else { greet(); window.setTimeout( function () { input.focus(); }, reduceMotion ? 0 : 60 ); }
		}

		/* ---------------- Auto-grow do textarea ---------------- */

		function autoGrow() {
			input.style.height = 'auto';
			input.style.height = Math.min( input.scrollHeight, 120 ) + 'px';
		}

		/* ---------------- Ligações de eventos ---------------- */

		function submit() {
			if ( busy ) { return; }
			if ( ! input.value.trim() ) { return; }
			doSend( input.value );
		}

		inputForm.addEventListener( 'submit', function ( e ) { e.preventDefault(); submit(); } );

		input.addEventListener( 'keydown', function ( e ) {
			if ( ( e.key === 'Enter' || e.keyCode === 13 ) && ! e.shiftKey ) {
				e.preventDefault();
				submit();
			}
		} );
		input.addEventListener( 'input', autoGrow );

		if ( resetBtn ) { resetBtn.addEventListener( 'click', reset ); }

		if ( ! isInline ) {
			bubble.addEventListener( 'click', function () { isOpen ? close() : open(); } );
			if ( closeBtn ) { closeBtn.addEventListener( 'click', close ); }
			panel.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Escape' || e.keyCode === 27 ) { e.preventDefault(); close(); }
			} );
		}

		/* ---------------- Boot da instância ---------------- */

		// Restaura o histórico persistido (contexto após reload).
		for ( var h = 0; h < history.length; h++ ) { renderFromHistory( history[ h ] ); }
		scrollBottom();

		// Inline já está aberto: gate obrigatório antes da saudação.
		if ( isInline ) {
			if ( ! gateOk() ) { requireGate(); }
			else if ( ! greeted ) { greet(); }
		}
	}

	/* ---------------------------------------------------------------- *
	 *  Boot global: inicializa todos os pontos de montagem
	 * ---------------------------------------------------------------- */

	function boot() {
		var mounts = document.querySelectorAll( '.fzwai-widget[data-mode]' );
		if ( ! mounts.length ) { return; }
		Array.prototype.forEach.call( mounts, function ( mt ) {
			if ( mt.__fzwaiDone ) { return; }
			mt.__fzwaiDone = true;
			try { initWidget( mt ); } catch ( e ) { /* falha silenciosa: nunca quebrar a página */ }
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
