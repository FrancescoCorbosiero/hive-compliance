/**
 * Document editor: CodeMirror (Markdown), live preview and checks through the REST API,
 * placeholder insertion. Plain JS, no build step.
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
	var form = document.getElementById( 'hive-document-form' );
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

	function renderFindings( findings ) {
		findingsBox.textContent = '';
		if ( ! findings.length ) {
			findingsBox.appendChild( element( 'p', 'hive-ok', config.i18n.noFindings ) );
			return;
		}
		var list = element( 'ul', 'hive-findings' );
		findings.forEach( function ( finding ) {
			var item = element( 'li', 'hive-finding hive-finding--' + finding.severity );
			item.appendChild( element( 'strong', '', finding.severity ) );
			item.appendChild( document.createTextNode( ' ' + finding.message ) );
			if ( finding.details && finding.details.length ) {
				var details = element( 'ul' );
				finding.details.forEach( function ( detail ) {
					var li = element( 'li' );
					li.appendChild( element( 'code', '', detail ) );
					details.appendChild( li );
				} );
				item.appendChild( details );
			}
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
			preview.insertBefore( element( 'p', 'hive-finding hive-finding--error', config.i18n.previewError + ' ' + ( error && error.message ? error.message : '' ) ), preview.firstChild );
		} );
	}

	function onChange() {
		dirty = true;
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

	if ( form ) {
		form.addEventListener( 'submit', function () {
			if ( codemirror ) {
				codemirror.save();
			}
			dirty = false;
		} );
	}

	window.addEventListener( 'beforeunload', function ( event ) {
		if ( dirty ) {
			event.preventDefault();
			event.returnValue = config.i18n.unsaved;
		}
	} );
} )( window.wp, window.hiveComplianceEditor );
