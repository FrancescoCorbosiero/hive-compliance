/**
 * Warns in the block editor when a page bound to a compliance document contains anything
 * besides the hive/document block (one source of truth).
 *
 * @package Hive\Compliance
 */
( function ( wp, config ) {
	'use strict';

	if ( ! wp || ! wp.data || ! config ) {
		return;
	}

	var NOTICE_ID = 'hive-compliance-page-guard';
	var shortcodePattern = /^\s*\[hive_document\b[^\]]*\]\s*$/;
	var lastState = null;

	function isEmptyParagraph( block ) {
		if ( 'core/paragraph' !== block.name ) {
			return false;
		}
		var content = block.attributes.content ? String( block.attributes.content ) : '';
		return '' === content.replace( /<[^>]*>|&nbsp;|\s/g, '' );
	}

	function isShell( block ) {
		if ( 'hive/document' === block.name ) {
			return block.attributes.docId === config.docId;
		}
		return 'core/shortcode' === block.name && shortcodePattern.test( block.attributes.text || '' );
	}

	function onlyShell( blocks ) {
		var meaningful = blocks.filter( function ( block ) {
			return ! isEmptyParagraph( block );
		} );
		return 1 === meaningful.length && isShell( meaningful[ 0 ] );
	}

	wp.data.subscribe( function () {
		var editor = wp.data.select( 'core/block-editor' );
		if ( ! editor ) {
			return;
		}
		var ok = onlyShell( editor.getBlocks() );
		if ( ok === lastState ) {
			return;
		}
		lastState = ok;
		var notices = wp.data.dispatch( 'core/notices' );
		if ( ok ) {
			notices.removeNotice( NOTICE_ID );
		} else {
			notices.createWarningNotice( config.message, { id: NOTICE_ID, isDismissible: true } );
		}
	} );
} )( window.wp, window.hiveCompliancePageGuard );
