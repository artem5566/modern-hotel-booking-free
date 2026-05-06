(function (blocks, element, blockEditor) {
    var el = element.createElement;
    var registerBlockType = blocks.registerBlockType;
    var InspectorControls = blockEditor.InspectorControls;
    var TextControl = wp.components.TextControl;
    var PanelBody = wp.components.PanelBody;

    registerBlockType('modern-hotel-booking/booking-form', {
        title: wp.i18n.__('Hotel: Booking Form', 'modern-hotel-booking'),
        icon: 'building',
        category: 'hotel-booking',
        transforms: {
            from: [
                {
                    type: 'shortcode',
                    tag: ['modern_hotel_booking', 'mhbo_booking_form'],
                    attributes: {
                        roomId: {
                            type: 'number',
                            shortcode: function (attributes) {
                                return attributes.named.room_id ? parseInt(attributes.named.room_id, 10) : 0;
                            },
                        },
                    },
                },
            ],
        },
        attributes: {
            roomId: {
                type: 'number',
                default: 0
            }
        },
        edit: function (props) {
            var attributes = props.attributes;

            function onChangeRoomId(newRoomId) {
                props.setAttributes({ roomId: parseInt(newRoomId, 10) || 0 });
            }

            return [
                el('div', { className: props.className + ' mhbo-booking-bar-preview' },
                    el('div', { className: 'mhbo-bar-item' },
                        el('div', { className: 'mhbo-bar-label' }, wp.i18n.__('Check-in', 'modern-hotel-booking')),
                        el('div', { className: 'mhbo-bar-value' }, '— — —')
                    ),
                    el('div', { className: 'mhbo-bar-item' },
                        el('div', { className: 'mhbo-bar-label' }, wp.i18n.__('Check-out', 'modern-hotel-booking')),
                        el('div', { className: 'mhbo-bar-value' }, '— — —')
                    ),
                    el('div', { className: 'mhbo-bar-item' },
                        el('div', { className: 'mhbo-bar-label' }, wp.i18n.__('Guests', 'modern-hotel-booking')),
                        el('div', { className: 'mhbo-bar-value' }, wp.i18n.__('2 Adults', 'modern-hotel-booking'))
                    ),
                    el('div', { className: 'mhbo-bar-button' }, wp.i18n.__('Book Now', 'modern-hotel-booking')),
                    el('div', { className: 'mhbo-preview-info-overlay' },
                        el('span', { className: 'dashicons dashicons-building' }),
                        el('span', {}, attributes.roomId > 0
                            ? wp.i18n.sprintf(/* translators: %d: room ID number */ wp.i18n.__('Room ID: %d', 'modern-hotel-booking'), attributes.roomId)
                            : wp.i18n.__('General Booking Form', 'modern-hotel-booking')
                        )
                    )
                ),
                el(InspectorControls, {},
                    el(PanelBody, { title: wp.i18n.__('Booking Settings', 'modern-hotel-booking'), initialOpen: true },
                        el(TextControl, {
                            label: wp.i18n.__('Room ID (optional)', 'modern-hotel-booking'),
                            value: attributes.roomId,
                            onChange: onChangeRoomId,
                            help: wp.i18n.__('Leave as 0 to show the general search form.', 'modern-hotel-booking')
                        })
                    )
                )
            ];
        },
        save: function () {
            return null; // Rendered via PHP
        },
    });
})(
    window.wp.blocks,
    window.wp.element,
    window.wp.blockEditor
);
