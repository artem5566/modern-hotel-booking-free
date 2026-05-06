/**
 * Hotel: Chat on WhatsApp – Gutenberg Block
 *
 * @package ModernHotelBooking
 * @since   2.3.0
 */
( function ( blocks, element, blockEditor ) {
    'use strict';

    var el                = element.createElement;
    var registerBlockType = blocks.registerBlockType;
    var InspectorControls = blockEditor.InspectorControls;
    var PanelBody         = wp.components.PanelBody;
    var SelectControl     = wp.components.SelectControl;
    var TextControl       = wp.components.TextControl;
    var __                = wp.i18n.__;
    var ServerSideRender  = wp.serverSideRender;

    registerBlockType( 'modern-hotel-booking/whatsapp-button', {
        title: __( 'Hotel: Chat on WhatsApp', 'modern-hotel-booking' ),
        icon: 'phone',
        category: 'hotel-booking',
        attributes: {
            style:   { type: 'string', default: 'button' },
            text:    { type: 'string', default: '' },
            message: { type: 'string', default: '' },
        },
        edit: function ( props ) {
            return [
                el( InspectorControls, { key: 'inspector' },
                    el( PanelBody, { title: __( 'Button Settings', 'modern-hotel-booking' ) },
                        el( SelectControl, {
                            label:   __( 'Style', 'modern-hotel-booking' ),
                            value:   props.attributes.style,
                            options: [
                                { label: __( 'Inline Button', 'modern-hotel-booking' ),   value: 'button' },
                                { label: __( 'Floating Button', 'modern-hotel-booking' ), value: 'floating' },
                                { label: __( 'Text Link', 'modern-hotel-booking' ),       value: 'link' },
                            ],
                            onChange: function ( val ) { props.setAttributes( { style: val } ); }
                        } ),
                        el( TextControl, {
                            label:    __( 'Override Button Text', 'modern-hotel-booking' ),
                            help:     __( 'Leave empty to use the default text from settings.', 'modern-hotel-booking' ),
                            value:    props.attributes.text,
                            onChange: function ( val ) { props.setAttributes( { text: val } ); }
                        } ),
                        el( TextControl, {
                            label:    __( 'Pre-filled Message', 'modern-hotel-booking' ),
                            help:     __( 'The message shown to the guest when they start the chat.', 'modern-hotel-booking' ),
                            value:    props.attributes.message,
                            onChange: function ( val ) { props.setAttributes( { message: val } ); }
                        } )
                    )
                ),
                el( ServerSideRender, {
                    block:      'modern-hotel-booking/whatsapp-button',
                    attributes: props.attributes,
                    key:        'preview'
                } )
            ];
        },
        save: function () { return null; }
    } );
} )(
    window.wp.blocks,
    window.wp.element,
    window.wp.blockEditor
);
