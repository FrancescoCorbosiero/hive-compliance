/**
 * hive/legal-links block editor script. Plain JS, no build step.
 *
 * @package Hive\Compliance
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var data = window.hiveComplianceSiteBlocks || { fields: [], languages: [] };
	var languageOptions = [ { value: '', label: __( 'Current language', 'hive-compliance' ) } ].concat( data.languages );
	var groupOptions = [
		{ value: 'legal', label: __( 'Legal', 'hive-compliance' ) },
		{ value: 'assistance', label: __( 'Customer assistance', 'hive-compliance' ) },
		{ value: '', label: __( 'All legal pages', 'hive-compliance' ) }
	];

	wp.blocks.registerBlockType( 'hive/legal-links', {
		edit: function ( props ) {
			var attributes = props.attributes;

			var inspector = el(
				wp.blockEditor.InspectorControls,
				null,
				el(
					wp.components.PanelBody,
					{ title: __( 'Links', 'hive-compliance' ) },
					el( wp.components.SelectControl, {
						label: __( 'Group', 'hive-compliance' ),
						value: attributes.group,
						options: groupOptions,
						onChange: function ( value ) {
							props.setAttributes( { group: value } );
						},
						__nextHasNoMarginBottom: true
					} ),
					el( wp.components.SelectControl, {
						label: __( 'Language', 'hive-compliance' ),
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
				block: 'hive/legal-links',
				attributes: attributes,
				EmptyResponsePlaceholder: function () {
					return el( wp.components.Placeholder, {
						icon: 'list-view',
						label: __( 'Legal links', 'hive-compliance' ),
						instructions: __( 'No links yet: bind the documents of this group to pages and publish both.', 'hive-compliance' )
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
