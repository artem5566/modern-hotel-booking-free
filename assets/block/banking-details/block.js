/**
 * Hotel: Bank Transfer Details – Gutenberg Block
 *
 * @param blocks
 * @param element
 * @param blockEditor
 * @package
 * @since   2.3.0
 */
( function ( blocks, element, blockEditor ) {
	'use strict';

	const el = element.createElement;
	const registerBlockType = blocks.registerBlockType;
	const InspectorControls = blockEditor.InspectorControls;
	const PanelBody = wp.components.PanelBody;
	const ToggleControl = wp.components.ToggleControl;
	const SelectControl = wp.components.SelectControl;
	const __ = wp.i18n.__;
	const ServerSideRender = wp.serverSideRender;

	registerBlockType( 'modern-hotel-booking/banking-details', {
		title: __( 'Hotel: Bank Transfer Details', 'modern-hotel-booking' ),
		icon: 'money-alt',
		category: 'hotel-booking',
		attributes: {
			showInstructions: { type: 'boolean', default: true },
			layout: { type: 'string', default: 'card' },
		},
		edit( props ) {
			return [
				el(
					InspectorControls,
					{ key: 'inspector' },
					el(
						PanelBody,
						{
							title: __(
								'Display Settings',
								'modern-hotel-booking'
							),
						},
						el( ToggleControl, {
							label: __(
								'Show Instructions',
								'modern-hotel-booking'
							),
							help: __(
								'Show text instructions for the guest below the account details.',
								'modern-hotel-booking'
							),
							checked: props.attributes.showInstructions,
							onChange( val ) {
								props.setAttributes( {
									showInstructions: val,
								} );
							},
						} ),
						el( SelectControl, {
							label: __( 'Layout', 'modern-hotel-booking' ),
							value: props.attributes.layout,
							options: [
								{
									label: __( 'Card', 'modern-hotel-booking' ),
									value: 'card',
								},
								{
									label: __(
										'Inline',
										'modern-hotel-booking'
									),
									value: 'inline',
								},
							],
							onChange( val ) {
								props.setAttributes( { layout: val } );
							},
						} )
					)
				),
				el( ServerSideRender, {
					block: 'modern-hotel-booking/banking-details',
					attributes: props.attributes,
					key: 'preview',
				} ),
			];
		},
		save() {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor );
