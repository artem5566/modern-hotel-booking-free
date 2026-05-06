/**
 * Hotel: Bank Transfer Details – Gutenberg Block
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

    registerBlockType( 'modern-hotel-booking/banking-details', {
        title: __( 'Hotel: Bank Transfer Details', 'modern-hotel-booking' ),
        icon: 'money-alt',
        category: 'hotel-booking',
        attributes: {
            showInstructions: { type: 'boolean', default: true },
            layout:           { type: 'string',  default: 'card' },
        },
        edit: function ( props ) {
            return [
                el( InspectorControls, { key: 'inspector' },
                    el( PanelBody, { title: __( 'Display Settings', 'modern-hotel-booking' ) },
                        el( ToggleControl, {
                            label:    __( 'Show Instructions', 'modern-hotel-booking' ),
                            help:     __( 'Show text instructions for the guest below the account details.', 'modern-hotel-booking' ),
                            checked:  props.attributes.showInstructions,
                            onChange: function ( val ) { props.setAttributes( { showInstructions: val } ); }
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
                    block:      'modern-hotel-booking/banking-details',
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
