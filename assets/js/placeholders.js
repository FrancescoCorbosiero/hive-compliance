/**
 * Placeholders screen: copy buttons, search and filter. Without JavaScript every row is shown.
 *
 * @package Hive\Compliance
 */
( function () {
	'use strict';

	var __ = window.wp && window.wp.i18n ? window.wp.i18n.__ : function ( text ) {
		return text;
	};

	function copy( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text );
		}
		var field = document.createElement( 'textarea' );
		field.value = text;
		field.setAttribute( 'readonly', '' );
		field.style.position = 'absolute';
		field.style.left = '-9999px';
		document.body.appendChild( field );
		field.select();
		document.execCommand( 'copy' );
		document.body.removeChild( field );
		return Promise.resolve();
	}

	document.querySelectorAll( '.hive-copy' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			copy( button.getAttribute( 'data-copy' ) ).then( function () {
				var label = button.textContent;
				button.textContent = __( 'Copied', 'hive-compliance' );
				setTimeout( function () {
					button.textContent = label;
				}, 1500 );
			} );
		} );
	} );

	var search = document.getElementById( 'hive-ph-search' );
	var filter = document.getElementById( 'hive-ph-filter' );
	if ( ! search || ! filter ) {
		return;
	}

	function apply() {
		var term = search.value.trim().toLowerCase();
		var mode = filter.value;
		document.querySelectorAll( '.hive-ph-table tbody tr' ).forEach( function ( row ) {
			var match = ! term || ( row.getAttribute( 'data-search' ) || '' ).indexOf( term ) !== -1;
			if ( 'attention' === mode ) {
				match = match && '1' === row.getAttribute( 'data-attention' );
			} else if ( 'used' === mode ) {
				match = match && '1' === row.getAttribute( 'data-used' );
			} else if ( 'editable' === mode ) {
				match = match && '1' === row.getAttribute( 'data-editable' );
			}
			if ( row.classList.contains( 'hive-ph-new' ) ) {
				match = ! term && ( 'all' === mode || 'editable' === mode );
			}
			row.hidden = ! match;
		} );
	}

	search.addEventListener( 'input', apply );
	filter.addEventListener( 'change', apply );
	apply();
} )();
