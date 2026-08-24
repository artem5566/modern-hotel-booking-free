/**
 * Hotel: Chat on WhatsApp – Gutenberg Block
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
	const SelectControl = wp.components.SelectControl;
	const TextControl = wp.components.TextControl;
	const __ = wp.i18n.__;
	const ServerSideRender = wp.serverSideRender;

	registerBlockType( 'modern-hotel-booking/whatsapp-button', {
		title: __( 'Hotel: Chat on WhatsApp', 'modern-hotel-booking' ),
		icon: 'phone',
		category: 'hotel-booking',
		attributes: {
			style: { type: 'string', default: 'button' },
			text: { type: 'string', default: '' },
			message: { type: 'string', default: '' },
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
								'Button Settings',
								'modern-hotel-booking'
							),
						},
						el( SelectControl, {
							label: __( 'Style', 'modern-hotel-booking' ),
							value: props.attributes.style,
							options: [
								{
									label: __(
										'Inline Button',
										'modern-hotel-booking'
									),
									value: 'button',
								},
								{
									label: __(
										'Floating Button',
										'modern-hotel-booking'
									),
									value: 'floating',
								},
								{
									label: __(
										'Text Link',
										'modern-hotel-booking'
									),
									value: 'link',
								},
							],
							onChange( val ) {
								props.setAttributes( { style: val } );
							},
						} ),
						el( TextControl, {
							label: __(
								'Override Button Text',
								'modern-hotel-booking'
							),
							help: __(
								'Leave empty to use the default text from settings.',
								'modern-hotel-booking'
							),
							value: props.attributes.text,
							onChange( val ) {
								props.setAttributes( { text: val } );
							},
						} ),
						el( TextControl, {
							label: __(
								'Pre-filled Message',
								'modern-hotel-booking'
							),
							help: __(
								'The message shown to the guest when they start the chat.',
								'modern-hotel-booking'
							),
							value: props.attributes.message,
							onChange( val ) {
								props.setAttributes( { message: val } );
							},
						} )
					)
				),
				el( ServerSideRender, {
					block: 'modern-hotel-booking/whatsapp-button',
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
