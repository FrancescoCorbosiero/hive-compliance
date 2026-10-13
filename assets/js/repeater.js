/**
 * "Add row" buttons for the Business Data repeaters. Without JavaScript the form still
 * offers one empty row.
 *
 * @package Hive\Compliance
 */
( function () {
	'use strict';

	document.querySelectorAll( '[data-hive-add-row]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var name = button.getAttribute( 'data-hive-add-row' );
			var table = document.querySelector( '[data-hive-repeater="' + name + '"] tbody' );
			if ( ! table || ! table.lastElementChild ) {
				return;
			}
			var index = table.children.length;
			var row = table.lastElementChild.cloneNode( true );
			row.querySelectorAll( 'input' ).forEach( function ( input ) {
				input.value = '';
				input.name = input.name.replace( /\[\d+\]/, '[' + index + ']' );
			} );
			row.querySelectorAll( '.hive-field-message' ).forEach( function ( message ) {
				message.remove();
			} );
			table.appendChild( row );
			row.querySelector( 'input' ).focus();
		} );
	} );
} )();
