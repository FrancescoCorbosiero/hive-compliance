/**
 * hive/document block editor script. Plain JS, no build step.
 *
 * @package Hive\Compliance
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var data = window.hiveComplianceBlock || { documents: [], languages: [] };

	var documentOptions = [ { value: '', label: __( '— Select a document —', 'hive-compliance' ) } ].concat( data.documents );
	var languageOptions = [ { value: '', label: __( 'Current language', 'hive-compliance' ) } ].concat( data.languages );

	function placeholder( instructions, child ) {
		return el(
			wp.components.Placeholder,
			{ icon: 'shield', label: __( 'Compliance document', 'hive-compliance' ), instructions: instructions },
			child || null
		);
	}

	wp.blocks.registerBlockType( 'hive/document', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var blockProps = wp.blockEditor.useBlockProps();

			function setDocument( value ) {
				props.setAttributes( { docId: value } );
			}

			function setLanguage( value ) {
				props.setAttributes( { lang: value } );
			}

			var inspector = el(
				wp.blockEditor.InspectorControls,
				null,
				el(
					wp.components.PanelBody,
					{ title: __( 'Document', 'hive-compliance' ) },
					el( wp.components.SelectControl, {
						label: __( 'Document', 'hive-compliance' ),
						value: attributes.docId,
						options: documentOptions,
						onChange: setDocument,
						__nextHasNoMarginBottom: true
					} ),
					el( wp.components.SelectControl, {
						label: __( 'Language', 'hive-compliance' ),
						value: attributes.lang,
						options: languageOptions,
						onChange: setLanguage,
						__nextHasNoMarginBottom: true
					} )
				)
			);

			var body;
			if ( ! attributes.docId ) {
				body = placeholder(
					__( 'Choose the document to show. Edit its text in Hive Compliance → Documents.', 'hive-compliance' ),
					el( wp.components.SelectControl, {
						value: attributes.docId,
						options: documentOptions,
						onChange: setDocument,
						__nextHasNoMarginBottom: true
					} )
				);
			} else {
				body = el( wp.serverSideRender, {
					block: 'hive/document',
					attributes: attributes,
					EmptyResponsePlaceholder: function () {
						return placeholder( __( 'Nothing to show: the document is empty or not visible to you.', 'hive-compliance' ) );
					}
				} );
			}

			return el( 'div', blockProps, inspector, body );
		},

		save: function () {
			return null;
		}
	} );
} )( window.wp );
