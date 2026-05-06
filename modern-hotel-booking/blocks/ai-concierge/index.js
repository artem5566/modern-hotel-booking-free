/**
 * AI Concierge block — editor script.
 *
 * @package modern-hotel-booking
 * @since   2.4.0
 */

/* global wp */

const { registerBlockType } = wp.blocks;
const { InspectorControls, useBlockProps } = wp.blockEditor;
const { PanelBody, SelectControl, TextControl } = wp.components;
const { __ } = wp.i18n;
const el = wp.element.createElement;

const robotIcon = el( 'svg',
    { xmlns: 'http://www.w3.org/2000/svg', viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: '1.5', strokeLinecap: 'round', strokeLinejoin: 'round' },
    el( 'line',   { x1: '12', y1: '1', x2: '12', y2: '4' } ),
    el( 'circle', { cx: '12', cy: '1', r: '0.8', fill: 'currentColor', stroke: 'none' } ),
    el( 'rect',   { x: '3', y: '4', width: '18', height: '14', rx: '2' } ),
    el( 'circle', { cx: '8.5', cy: '10', r: '1.5', fill: 'currentColor', stroke: 'none' } ),
    el( 'circle', { cx: '15.5', cy: '10', r: '1.5', fill: 'currentColor', stroke: 'none' } ),
    el( 'path',   { d: 'M8 14h8', strokeWidth: '2' } ),
    el( 'line',   { x1: '3', y1: '10', x2: '1', y2: '10' } ),
    el( 'line',   { x1: '21', y1: '10', x2: '23', y2: '10' } )
);

registerBlockType( 'modern-hotel-booking/ai-concierge', {
    icon: robotIcon,

    edit( { attributes, setAttributes } ) {
        const { variant, position, welcomeMessage } = attributes;
        const blockProps = useBlockProps();

        return el( 'div', blockProps,
            el( InspectorControls, null,
                el( PanelBody, {
                    title: __( 'AI Concierge Settings', 'modern-hotel-booking' ),
                    initialOpen: true,
                },
                    el( SelectControl, {
                        label:    __( 'Widget Variant', 'modern-hotel-booking' ),
                        value:    variant,
                        options:  [
                            { label: __( 'Floating (FAB button)', 'modern-hotel-booking' ), value: 'floating' },
                            { label: __( 'Inline (embedded)',     'modern-hotel-booking' ), value: 'inline'   },
                        ],
                        onChange: ( val ) => setAttributes( { variant: val } ),
                        __nextHasNoMarginBottom: true,
                    } ),
                    el( SelectControl, {
                        label:    __( 'Position (floating only)', 'modern-hotel-booking' ),
                        value:    position,
                        options:  [
                            { label: __( 'Bottom Right', 'modern-hotel-booking' ), value: 'bottom-right' },
                            { label: __( 'Bottom Left',  'modern-hotel-booking' ), value: 'bottom-left'  },
                        ],
                        onChange: ( val ) => setAttributes( { position: val } ),
                        __nextHasNoMarginBottom: true,
                    } ),
                    el( TextControl, {
                        label:    __( 'Welcome Message', 'modern-hotel-booking' ),
                        value:    welcomeMessage,
                        help:     __( 'Leave blank to use the global setting.', 'modern-hotel-booking' ),
                        onChange: ( val ) => setAttributes( { welcomeMessage: val } ),
                        __nextHasNoMarginBottom: true,
                    } )
                )
            ),
            el( 'div', { className: 'mhbo-ai-concierge-preview' },
                el( 'div', { className: 'mhbo-ai-concierge-preview__icon' }, '💬' ),
                el( 'strong', { className: 'mhbo-ai-concierge-preview__title' },
                    __( 'Hotel: AI Concierge', 'modern-hotel-booking' )
                ),
                el( 'p', { className: 'mhbo-ai-concierge-preview__meta' },
                    __( 'Variant', 'modern-hotel-booking' ) + ': ' + variant +
                    ( variant === 'floating'
                        ? ' · ' + __( 'Position', 'modern-hotel-booking' ) + ': ' + position
                        : '' )
                ),
                el( 'span', { className: 'mhbo-ai-concierge-preview__note' },
                    __( 'Chat widget renders on the frontend.', 'modern-hotel-booking' )
                )
            )
        );
    },

    // Dynamic block — PHP render_callback handles frontend output.
    save() {
        return null;
    },
} );
