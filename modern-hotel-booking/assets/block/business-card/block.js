/**
 * Hotel: Business Contact Card – Gutenberg Block
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
    var TextControl       = wp.components.TextControl;
    var __                = wp.i18n.__;
    var ServerSideRender  = wp.serverSideRender;

    registerBlockType( 'modern-hotel-booking/business-card', {
        title: __( 'Hotel: Business Contact Card', 'modern-hotel-booking' ),
        icon: 'id',
        category: 'hotel-booking',
        attributes: {
            sections: { type: 'string', default: 'company,whatsapp,banking,revolut' },
        },
        edit: function ( props ) {
            return [
                el( InspectorControls, { key: 'inspector' },
                    el( PanelBody, { title: __( 'Card Settings', 'modern-hotel-booking' ) },
                        el( TextControl, {
                            label:       __( 'Visible Sections (comma-separated)', 'modern-hotel-booking' ),
                            help:        __( 'Available: company, whatsapp, banking, revolut. Use "all" for everything.', 'modern-hotel-booking' ),
                            value:       props.attributes.sections,
                            placeholder: 'company,whatsapp,banking,revolut',
                            onChange:    function ( val ) { props.setAttributes( { sections: val } ); }
                        } )
                    )
                ),
                el( ServerSideRender, {
                    block:      'modern-hotel-booking/business-card',
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
