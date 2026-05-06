/**
 * Hotel: Pay via Revolut – Gutenberg Block
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
    var ToggleControl     = wp.components.ToggleControl;
    var SelectControl     = wp.components.SelectControl;
    var __                = wp.i18n.__;
    var ServerSideRender  = wp.serverSideRender;

    registerBlockType( 'modern-hotel-booking/revolut-details', {
        title: __( 'Hotel: Pay via Revolut', 'modern-hotel-booking' ),
        icon: 'money',
        category: 'hotel-booking',
        attributes: {
            showQR:   { type: 'boolean', default: true },
            showLink: { type: 'boolean', default: true },
            layout:   { type: 'string',  default: 'card' },
        },
        edit: function ( props ) {
            return [
                el( InspectorControls, { key: 'inspector' },
                    el( PanelBody, { title: __( 'Display Settings', 'modern-hotel-booking' ) },
                        el( ToggleControl, {
                            label:    __( 'Show QR Code', 'modern-hotel-booking' ),
                            help:     __( 'Display the Revolut.me payment QR code.', 'modern-hotel-booking' ),
                            checked:  props.attributes.showQR,
                            onChange: function ( val ) { props.setAttributes( { showQR: val } ); }
                        } ),
                        el( ToggleControl, {
                            label:    __( 'Show Link', 'modern-hotel-booking' ),
                            help:     __( 'Display the Revolut.me text link.', 'modern-hotel-booking' ),
                            checked:  props.attributes.showLink,
                            onChange: function ( val ) { props.setAttributes( { showLink: val } ); }
                        } ),
                        el( SelectControl, {
                            label:   __( 'Layout', 'modern-hotel-booking' ),
                            value:   props.attributes.layout,
                            options: [
                                { label: __( 'Card', 'modern-hotel-booking' ),   value: 'card' },
                                { label: __( 'Inline', 'modern-hotel-booking' ), value: 'inline' },
                            ],
                            onChange: function ( val ) { props.setAttributes( { layout: val } ); }
                        } )
                    )
                ),
                el( ServerSideRender, {
                    block:      'modern-hotel-booking/revolut-details',
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
