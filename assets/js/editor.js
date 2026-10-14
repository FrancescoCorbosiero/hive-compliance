/**
 * Document editor: CodeMirror (Markdown), live preview and checks through the REST API,
 * placeholder insertion, unsaved-changes indicator, Ctrl+S. Plain JS, no build step.
 *
 * @package Hive\Compliance
 */
( function ( wp, config ) {
	'use strict';

	var textarea = document.getElementById( 'hive-markdown' );
	if ( ! textarea || ! config ) {
		return;
	}

	var preview = document.getElementById( 'hive-preview' );
	var findingsBox = document.getElementById( 'hive-findings' );
	var findingsCount = document.getElementById( 'hive-findings-count' );
	var form = document.getElementById( 'hive-document-form' );
	var editor = textarea.closest( '.hive-editor' );
	var codemirror = null;
	var dirty = false;
	var timer = null;
	var requestId = 0;

	if ( config.codeEditor && wp.codeEditor ) {
		codemirror = wp.codeEditor.initialize( textarea, config.codeEditor ).codemirror;
	}

	function getValue() {
		return codemirror ? codemirror.getValue() : textarea.value;
	}

	function insert( text ) {
		if ( codemirror ) {
			codemirror.replaceSelection( text );
			codemirror.focus();
			return;
		}
		var start = textarea.selectionStart || 0;
		var end = textarea.selectionEnd || 0;
		textarea.value = textarea.value.slice( 0, start ) + text + textarea.value.slice( end );
		textarea.selectionStart = textarea.selectionEnd = start + text.length;
		textarea.focus();
		onChange();
	}

	function element( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text ) {
			node.textContent = text;
		}
		return node;
	}

	function icon( name ) {
		var span = element( 'span', 'dashicons dashicons-' + name );
		span.setAttribute( 'aria-hidden', 'true' );
		return span;
	}

	var ICONS = { error: 'dismiss', warning: 'warning', conflict: 'randomize' };

	function renderFindings( findings ) {
		findingsBox.textContent = '';
		var errors = findings.filter( function ( finding ) {
			return 'error' === finding.severity;
		} ).length;
		if ( findingsCount ) {
			findingsCount.textContent = String( findings.length );
			findingsCount.className = 'hive-badge hive-badge--plain hive-badge--' + ( findings.length ? ( errors ? 'error' : 'warning' ) : 'ok' );
		}
		if ( ! findings.length ) {
			var ok = element( 'p', 'hive-ok' );
			ok.appendChild( icon( 'yes-alt' ) );
			ok.appendChild( document.createTextNode( ' ' + config.i18n.noFindings ) );
			findingsBox.appendChild( ok );
			return;
		}
		var list = element( 'ul', 'hive-findings' );
		findings.forEach( function ( finding ) {
			var item = element( 'li', 'hive-finding hive-finding--' + finding.severity );
			var iconBox = element( 'span', 'hive-finding__icon' );
			iconBox.appendChild( icon( ICONS[ finding.severity ] || 'info-outline' ) );
			item.appendChild( iconBox );
			var body = element( 'div', 'hive-finding__body' );
			body.appendChild( element( 'span', 'hive-finding__severity', finding.severity ) );
			body.appendChild( document.createTextNode( finding.message ) );
			if ( finding.details && finding.details.length ) {
				var details = element( 'ul' );
				finding.details.forEach( function ( detail ) {
					var li = element( 'li' );
					li.appendChild( element( 'code', '', detail ) );
					details.appendChild( li );
				} );
				body.appendChild( details );
			}
			item.appendChild( body );
			list.appendChild( item );
		} );
		findingsBox.appendChild( list );
	}

	function refresh() {
		var current = ++requestId;
		wp.apiFetch( {
			path: config.previewPath,
			method: 'POST',
			data: { doc_id: config.docId, lang: config.lang, markdown: getValue() }
		} ).then( function ( response ) {
			if ( current !== requestId ) {
				return;
			}
			// The HTML comes from the server pipeline and is already sanitised with wp_kses_post.
			preview.innerHTML = response.html;
			renderFindings( response.findings || [] );
		} ).catch( function ( error ) {
			var note = element( 'p', 'hive-finding hive-finding--error' );
			note.appendChild( element( 'span', 'hive-finding__body', config.i18n.previewError + ' ' + ( error && error.message ? error.message : '' ) ) );
			preview.insertBefore( note, preview.firstChild );
		} );
	}

	function setDirty( value ) {
		dirty = value;
		if ( editor ) {
			editor.classList.toggle( 'is-dirty', value );
		}
	}

	function onChange() {
		setDirty( true );
		window.clearTimeout( timer );
		timer = window.setTimeout( refresh, 600 );
	}

	if ( codemirror ) {
		codemirror.on( 'change', onChange );
	} else {
		textarea.addEventListener( 'input', onChange );
	}

	document.querySelectorAll( '[data-hive-insert]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			insert( button.getAttribute( 'data-hive-insert' ) );
		} );
	} );

	// Sidebar fields belong to the form through form="…"; changing them counts as unsaved work.
	document.querySelectorAll( '[form="hive-document-form"]' ).forEach( function ( field ) {
		field.addEventListener( 'change', function () {
			setDirty( true );
		} );
	} );

	function submit() {
		if ( ! form ) {
			return;
		}
		if ( form.requestSubmit ) {
			form.requestSubmit();
		} else {
			form.submit();
		}
	}

	if ( form ) {
		form.addEventListener( 'submit', function () {
			if ( codemirror ) {
				codemirror.save();
			}
			setDirty( false );
		} );
	}

	document.addEventListener( 'keydown', function ( event ) {
		if ( ( event.ctrlKey || event.metaKey ) && ! event.altKey && 's' === event.key.toLowerCase() ) {
			event.preventDefault();
			submit();
		}
	} );

	window.addEventListener( 'beforeunload', function ( event ) {
		if ( dirty ) {
			event.preventDefault();
			event.returnValue = config.i18n.unsaved;
		}
	} );
} )( window.wp, window.hiveComplianceEditor );
