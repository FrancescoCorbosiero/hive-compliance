/**
 * hive/business-identity block editor script. Plain JS, no build step.
 *
 * @package Hive\Compliance
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var data = window.hiveComplianceSiteBlocks || { fields: [], languages: [] };
	var languageOptions = [ { value: '', label: __( 'Current language', 'hive-compliance' ) } ].concat( data.languages );

	wp.blocks.registerBlockType( 'hive/business-identity', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var selected = attributes.fields || [];

			function toggle( field, checked ) {
				var next = data.fields.map( function ( option ) {
					return option.value;
				} ).filter( function ( value ) {
					return value === field ? checked : selected.indexOf( value ) !== -1;
				} );
				props.setAttributes( { fields: next } );
			}

			var inspector = el(
				wp.blockEditor.InspectorControls,
				null,
				el(
					wp.components.PanelBody,
					{ title: __( 'Fields', 'hive-compliance' ) },
					data.fields.map( function ( option ) {
						return el( wp.components.CheckboxControl, {
							key: option.value,
							label: option.label,
							checked: selected.indexOf( option.value ) !== -1,
							onChange: function ( checked ) {
								toggle( option.value, checked );
							},
							__nextHasNoMarginBottom: true
						} );
					} ),
					el( wp.components.SelectControl, {
						label: __( 'Language of the labels', 'hive-compliance' ),
						value: attributes.lang,
						options: languageOptions,
						onChange: function ( value ) {
							props.setAttributes( { lang: value } );
						},
						__nextHasNoMarginBottom: true
					} )
				)
			);

			var body = el( wp.serverSideRender, {
				block: 'hive/business-identity',
				attributes: attributes,
				EmptyResponsePlaceholder: function () {
					return el( wp.components.Placeholder, {
						icon: 'id',
						label: __( 'Business identity', 'hive-compliance' ),
						instructions: __( 'Nothing to show yet: fill in Hive Compliance → Business Data.', 'hive-compliance' )
					} );
				}
			} );

			return el( 'div', wp.blockEditor.useBlockProps(), inspector, body );
		},

		save: function () {
			return null;
		}
	} );
} )( window.wp );
