/**
 * Hotel: Company Profile – Gutenberg Block
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

	registerBlockType( 'modern-hotel-booking/company-info', {
		title: __( 'Hotel: Company Profile', 'modern-hotel-booking' ),
		icon: 'building',
		category: 'hotel-booking',
		attributes: {
			showLogo: { type: 'boolean', default: true },
			showAddress: { type: 'boolean', default: true },
			showContact: { type: 'boolean', default: true },
			showRegistration: { type: 'boolean', default: false },
			layout: { type: 'string', default: 'vertical' },
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
							label: __( 'Show Logo', 'modern-hotel-booking' ),
							help: __(
								'Display your business logo if uploaded.',
								'modern-hotel-booking'
							),
							checked: props.attributes.showLogo,
							onChange( val ) {
								props.setAttributes( { showLogo: val } );
							},
						} ),
						el( ToggleControl, {
							label: __( 'Show Address', 'modern-hotel-booking' ),
							help: __(
								'Include your business physical address.',
								'modern-hotel-booking'
							),
							checked: props.attributes.showAddress,
							onChange( val ) {
								props.setAttributes( { showAddress: val } );
							},
						} ),
						el( ToggleControl, {
							label: __(
								'Show Contact Details',
								'modern-hotel-booking'
							),
							checked: props.attributes.showContact,
							onChange( val ) {
								props.setAttributes( { showContact: val } );
							},
						} ),
						el( ToggleControl, {
							label: __(
								'Show Registration Info',
								'modern-hotel-booking'
							),
							help: __(
								'Display Tax ID and Registration number.',
								'modern-hotel-booking'
							),
							checked: props.attributes.showRegistration,
							onChange( val ) {
								props.setAttributes( {
									showRegistration: val,
								} );
							},
						} ),
						el( SelectControl, {
							label: __( 'Layout', 'modern-hotel-booking' ),
							value: props.attributes.layout,
							options: [
								{
									label: __(
										'Vertical',
										'modern-hotel-booking'
									),
									value: 'vertical',
								},
								{
									label: __(
										'Horizontal',
										'modern-hotel-booking'
									),
									value: 'horizontal',
								},
							],
							onChange( val ) {
								props.setAttributes( { layout: val } );
							},
						} )
					)
				),
				el( ServerSideRender, {
					block: 'modern-hotel-booking/company-info',
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
