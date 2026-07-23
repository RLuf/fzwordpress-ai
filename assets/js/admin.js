/**
 * FZ WordPress AI — scripts do painel administrativo.
 *
 * Vanilla JS, sem dependência de jQuery. Lê a URL do AJAX e o nonce do objeto
 * localizado `FZWAI_ADMIN` (injetado via wp_localize_script). Todas as chamadas
 * enviam o nonce e verificam a resposta JSON de wp_send_json_success/error.
 *
 * @package FZWordPressAI
 */
( function () {
	'use strict';

	var CFG = window.FZWAI_ADMIN || {};
	var I18N = CFG.i18n || {};
	var LABELS = CFG.labels || {};

	function qs( sel, ctx ) {
		return ( ctx || document ).querySelector( sel );
	}

	function qsa( sel, ctx ) {
		return Array.prototype.slice.call( ( ctx || document ).querySelectorAll( sel ) );
	}

	/**
	 * POST para admin-ajax. Retorna uma Promise que resolve com o JSON.
	 */
	function ajax( action, data ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', CFG.nonce || '' );
		if ( data ) {
			Object.keys( data ).forEach( function ( k ) {
				body.append( k, null === data[ k ] || undefined === data[ k ] ? '' : data[ k ] );
			} );
		}
		return fetch( CFG.ajax_url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} ).then( function ( r ) {
			return r.json();
		} );
	}

	/**
	 * Escreve um resultado inline com estado visual (ok/erro/ocupado).
	 * Usa textContent — nunca innerHTML — para evitar injeção.
	 */
	function setResult( el, state, text ) {
		if ( ! el ) {
			return;
		}
		el.classList.remove( 'is-ok', 'is-error', 'is-busy' );
		if ( state ) {
			el.classList.add( 'is-' + state );
		}
		el.textContent = text || '';
	}

	function serverMessage( res, fallback ) {
		if ( res && res.data && res.data.message ) {
			return res.data.message;
		}
		if ( res && res.data && res.data.detail ) {
			return res.data.detail;
		}
		return fallback || I18N.error || 'Erro';
	}

	/* -------------------------------------------------- Backend show/hide */

	function initBackendToggle() {
		var radios = qsa( '.fzwai-backend-radio' );
		if ( ! radios.length ) {
			return;
		}
		function apply() {
			var selected = radios.filter( function ( r ) {
				return r.checked;
			} )[ 0 ];
			var val = selected ? selected.value : '';
			qsa( '.fzwai-backend-group' ).forEach( function ( g ) {
				g.classList.toggle( 'is-hidden', g.getAttribute( 'data-backend' ) !== val );
			} );
		}
		radios.forEach( function ( r ) {
			r.addEventListener( 'change', apply );
		} );
		apply();
	}

	/* ------------------------------------------------------ Color sync */

	function initColorSync() {
		qsa( '.fzwai-color' ).forEach( function ( picker ) {
			var text = qs( '[data-color-text="' + picker.id + '"]' );
			if ( ! text ) {
				return;
			}
			picker.addEventListener( 'input', function () {
				text.value = picker.value;
			} );
			text.addEventListener( 'input', function () {
				if ( /^#[0-9a-fA-F]{6}$/.test( text.value ) ) {
					picker.value = text.value;
				}
			} );
		} );
	}

	/* ------------------------------------------------ Add-source type toggle */

	function initSourceTypeToggle() {
		var typeSel = qs( '#fzwai-source-type' );
		if ( ! typeSel ) {
			return;
		}
		function apply() {
			var isText = 'text' === typeSel.value;
			var loc = qs( '.fzwai-loc-field' );
			var txt = qs( '.fzwai-text-field' );
			if ( loc ) {
				loc.classList.toggle( 'is-hidden', isText );
			}
			if ( txt ) {
				txt.classList.toggle( 'is-hidden', ! isText );
			}
		}
		typeSel.addEventListener( 'change', apply );
		apply();
	}

	/* ---------------------------------------------------- Test connection */

	function initTestBackend() {
		var btn = qs( '#fzwai-test-backend' );
		if ( ! btn ) {
			return;
		}
		var out = qs( '#fzwai-test-result' );
		btn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			btn.disabled = true;
			setResult( out, 'busy', I18N.testing || 'Testando…' );
			ajax( 'fzwai_test_backend', {} ).then( function ( res ) {
				btn.disabled = false;
				if ( res && res.success ) {
					setResult( out, 'ok', ( res.data && res.data.detail ) || I18N.ok || 'OK' );
				} else {
					setResult( out, 'error', serverMessage( res, I18N.error ) );
				}
			} ).catch( function () {
				btn.disabled = false;
				setResult( out, 'error', I18N.network_error || I18N.error );
			} );
		} );
	}

	/* --------------------------------------------------------- Add source */

	function initAddSource() {
		var form = qs( '#fzwai-add-source-form' );
		if ( ! form ) {
			return;
		}
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var out = qs( '.fzwai-inline-result', form );
			var btn = qs( 'button[type="submit"]', form );
			var typeSel = qs( '#fzwai-source-type' );
			var payload = {
				type: typeSel ? typeSel.value : '',
				label: ( qs( '[name="label"]', form ) || {} ).value || '',
				location: ( qs( '[name="location"]', form ) || {} ).value || '',
				content: ( qs( '[name="content"]', form ) || {} ).value || ''
			};
			if ( btn ) {
				btn.disabled = true;
			}
			setResult( out, 'busy', I18N.working || '' );
			ajax( 'fzwai_add_source', payload ).then( function ( res ) {
				if ( res && res.success ) {
					window.location.reload();
				} else {
					if ( btn ) {
						btn.disabled = false;
					}
					setResult( out, 'error', serverMessage( res, I18N.error ) );
				}
			} ).catch( function () {
				if ( btn ) {
					btn.disabled = false;
				}
				setResult( out, 'error', I18N.network_error || I18N.error );
			} );
		} );
	}

	/* -------------------------------------------- Row-level source actions */

	function setPill( cell, status, label ) {
		if ( ! cell ) {
			return;
		}
		var pill = qs( '.fzwai-pill', cell );
		if ( ! pill ) {
			pill = document.createElement( 'span' );
			cell.appendChild( pill );
		}
		pill.className = 'fzwai-pill fzwai-pill-' + status;
		pill.textContent = label || LABELS[ status ] || status;
	}

	function updateSourceRow( row, data ) {
		if ( ! row || ! data ) {
			return;
		}
		setPill( qs( '.fzwai-status-cell', row ), data.status, data.status_label );
		var chunkCell = qs( '.fzwai-chunk-cell', row );
		if ( chunkCell && 'undefined' !== typeof data.chunk_count ) {
			chunkCell.textContent = String( data.chunk_count );
		}
		// Atualiza/insere o detalhe de erro no bloco primário.
		var primary = qs( '.column-primary', row );
		if ( primary ) {
			var errBox = qs( '.fzwai-error-detail', primary );
			if ( 'error' === data.status && data.last_error ) {
				if ( ! errBox ) {
					errBox = document.createElement( 'div' );
					errBox.className = 'fzwai-error-detail';
					primary.appendChild( errBox );
				}
				errBox.textContent = data.last_error;
			} else if ( errBox ) {
				errBox.parentNode.removeChild( errBox );
			}
		}
	}

	function handleIndex( btn ) {
		var row = btn.closest( '[data-source-id]' );
		if ( ! row ) {
			return;
		}
		var id = row.getAttribute( 'data-source-id' );
		btn.disabled = true;
		setPill( qs( '.fzwai-status-cell', row ), 'pending', I18N.indexing || '…' );
		ajax( 'fzwai_index_source', { source_id: id } ).then( function ( res ) {
			btn.disabled = false;
			if ( res && res.success ) {
				updateSourceRow( row, res.data );
			} else {
				updateSourceRow( row, ( res && res.data ) || { status: 'error' } );
			}
		} ).catch( function () {
			btn.disabled = false;
			setPill( qs( '.fzwai-status-cell', row ), 'error', I18N.error );
		} );
	}

	function handleDelete( btn ) {
		var row = btn.closest( '[data-source-id]' );
		if ( ! row ) {
			return;
		}
		if ( ! window.confirm( I18N.confirm_delete || 'Remover?' ) ) {
			return;
		}
		var id = row.getAttribute( 'data-source-id' );
		btn.disabled = true;
		ajax( 'fzwai_delete_source', { source_id: id } ).then( function ( res ) {
			if ( res && res.success ) {
				row.parentNode.removeChild( row );
			} else {
				btn.disabled = false;
				window.alert( serverMessage( res, I18N.error ) );
			}
		} ).catch( function () {
			btn.disabled = false;
			window.alert( I18N.network_error || I18N.error );
		} );
	}

	function handleReindex( btn ) {
		if ( ! window.confirm( I18N.confirm_reindex || 'Reindexar?' ) ) {
			return;
		}
		btn.disabled = true;
		btn.classList.add( 'fzwai-spin' );
		ajax( 'fzwai_reindex_all', {} ).then( function () {
			window.location.reload();
		} ).catch( function () {
			btn.disabled = false;
			btn.classList.remove( 'fzwai-spin' );
			window.alert( I18N.network_error || I18N.error );
		} );
	}

	/**
	 * Delegação única de clique para os botões de ação por linha.
	 */
	function initDelegatedActions() {
		document.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest ? e.target.closest( '[data-fzwai-action]' ) : null;
			if ( ! btn ) {
				return;
			}
			e.preventDefault();
			switch ( btn.getAttribute( 'data-fzwai-action' ) ) {
				case 'index':
					handleIndex( btn );
					break;
				case 'delete':
					handleDelete( btn );
					break;
				case 'reindex':
					handleReindex( btn );
					break;
			}
		} );
	}

	function init() {
		initBackendToggle();
		initColorSync();
		initSourceTypeToggle();
		initTestBackend();
		initAddSource();
		initDelegatedActions();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
