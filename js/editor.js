/**
 * Ryadel Extensions for Polylang: "Languages" panel for every block.
 *
 * No build step: plain ES5 with the wp.* globals. Adds the `efplLanguages` attribute to every block type and a
 * panel in the block settings to choose the languages in which the block is shown. No language selected means
 * every language. When the module is switched off (`config.panel` false), only the attribute is added, so that the
 * languages already saved in the blocks are kept.
 */
( function ( wp, config ) {
	'use strict';

	if ( ! wp || ! wp.hooks || ! wp.compose || ! wp.element || ! wp.blockEditor || ! wp.components || ! wp.blocks ) {
		return;
	}

	var el        = wp.element.createElement;
	var Fragment  = wp.element.Fragment;
	var __        = wp.i18n.__;
	var ATTRIBUTE = 'efplLanguages';
	var languages = ( config && config.languages ) || [];

	// The attribute, also for blocks registered only in JavaScript. Server-side blocks already get it from PHP.
	wp.hooks.addFilter(
		'blocks.registerBlockType',
		'ryadel-extensions-for-polylang/languages-attribute',
		function ( settings ) {
			var attributes = settings.attributes || {};
			if ( attributes[ ATTRIBUTE ] ) {
				return settings;
			}
			var added = {};
			added[ ATTRIBUTE ] = { type: 'array', items: { type: 'string' }, default: [] };
			return Object.assign( {}, settings, { attributes: Object.assign( {}, attributes, added ) } );
		}
	);

	var withLanguagesPanel = wp.compose.createHigherOrderComponent( function ( BlockEdit ) {
		return function ( props ) {
			var type = wp.blocks.getBlockType( props.name );
			if ( ! props.isSelected || ! languages.length || ! type || ! type.attributes || ! type.attributes[ ATTRIBUTE ] ) {
				return el( BlockEdit, props );
			}

			var selected = props.attributes[ ATTRIBUTE ] || [];

			var toggle = function ( slug, checked ) {
				// Kept in the order of the site languages.
				var next = languages
					.map( function ( language ) {
						return language.slug;
					} )
					.filter( function ( s ) {
						return s === slug ? checked : selected.indexOf( s ) !== -1;
					} );
				var change = {};
				change[ ATTRIBUTE ] = next;
				props.setAttributes( change );
			};

			return el(
				Fragment,
				null,
				el( BlockEdit, props ),
				el(
					wp.blockEditor.InspectorControls,
					null,
					el(
						wp.components.PanelBody,
						{
							title: __( 'Languages', 'ryadel-extensions-for-polylang' ),
							initialOpen: selected.length > 0,
						},
						el(
							'p',
							{ className: 'components-base-control__help' },
							__( 'Show this block only in the selected languages. With none selected, it is shown in every language.', 'ryadel-extensions-for-polylang' )
						),
						languages.map( function ( language ) {
							return el( wp.components.CheckboxControl, {
								key: language.slug,
								label: language.name,
								checked: selected.indexOf( language.slug ) !== -1,
								onChange: function ( checked ) {
									toggle( language.slug, checked );
								},
							} );
						} )
					)
				)
			);
		};
	}, 'withEfplLanguagesPanel' );

	if ( config && false === config.panel ) {
		return;
	}

	wp.hooks.addFilter( 'editor.BlockEdit', 'ryadel-extensions-for-polylang/languages-panel', withLanguagesPanel );
}( window.wp, window.ryadelExtensionsForPolylangBlocks ) );
