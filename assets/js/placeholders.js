/**
 * Placeholders screen: search and filter across every namespace table. Without JavaScript
 * every row is shown. Copy buttons are handled by admin.js.
 *
 * @package Hive\Compliance
 */
( function () {
	'use strict';

	var search = document.getElementById( 'hive-ph-search' );
	var filter = document.getElementById( 'hive-ph-filter' );
	if ( ! search || ! filter ) {
		return;
	}

	var buttons = Array.prototype.slice.call( filter.querySelectorAll( '[data-hive-ph-filter]' ) );
	var empty = document.querySelector( '.hive-ph-empty' );
	var nav = document.querySelector( '.hive-ph-nav' );

	function mode() {
		var current = buttons.filter( function ( button ) {
			return button.classList.contains( 'is-current' );
		} )[ 0 ];
		return current ? current.getAttribute( 'data-hive-ph-filter' ) : 'all';
	}

	function fold( text ) {
		text = String( text || '' ).toLowerCase();
		try {
			text = text.normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' );
		} catch ( error ) {
			// Older engines: match accents literally.
		}
		return text.trim();
	}

	function apply() {
		var term = fold( search.value );
		var current = mode();
		var shown = 0;
		document.querySelectorAll( '.hive-ph-table tbody tr' ).forEach( function ( row ) {
			var match = ! term || -1 !== fold( row.getAttribute( 'data-search' ) ).indexOf( term );
			if ( 'attention' === current ) {
				match = match && '1' === row.getAttribute( 'data-attention' );
			} else if ( 'used' === current ) {
				match = match && '1' === row.getAttribute( 'data-used' );
			} else if ( 'editable' === current ) {
				match = match && '1' === row.getAttribute( 'data-editable' );
			}
			if ( row.classList.contains( 'hive-ph-new' ) ) {
				match = ! term && ( 'all' === current || 'editable' === current );
			}
			row.hidden = ! match;
			if ( match ) {
				shown++;
			}
		} );
		document.querySelectorAll( '.hive-ph-group' ).forEach( function ( group ) {
			var visible = Array.prototype.some.call( group.querySelectorAll( 'tbody tr' ), function ( row ) {
				return ! row.hidden;
			} );
			group.classList.toggle( 'is-empty', ! visible );
			if ( nav ) {
				var link = nav.querySelector( 'a[href="#' + group.id + '"]' );
				if ( link ) {
					link.hidden = ! visible;
				}
			}
		} );
		if ( empty ) {
			empty.classList.toggle( 'is-visible', 0 === shown );
		}
	}

	buttons.forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			buttons.forEach( function ( other ) {
				var on = other === button;
				other.classList.toggle( 'is-current', on );
				other.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
			} );
			apply();
		} );
	} );

	search.addEventListener( 'input', apply );
	search.addEventListener( 'search', apply );
	search.addEventListener( 'keydown', function ( event ) {
		if ( 'Enter' === event.key ) {
			event.preventDefault();
		}
	} );
	apply();
} )();
