/**
 * MHBO Admin Media Upload
 *
 * Handles WordPress media modal for logo and QR code uploads in Business Info settings.
 *
 * @package ModernHotelBooking
 * @since   2.1.0
 */

(function($) {
    'use strict';

    $(function() {
        $('.mhbo-upload-btn, .mhbo-upload-button').on('click', function(e) {
            e.preventDefault();

            var button = $(this);
            var id_target = button.data('target-id') ? $('#' + button.data('target-id')) : null;
            var url_target = button.data('target-url') ? $('#' + button.data('target-url')) : (button.data('target') ? $(button.data('target')) : null);
            var preview = button.data('preview') ? $('#' + button.data('preview')) : null;
            var title = button.data('title') || 'Select Image';

            var frame = wp.media({
                title: title,
                button: {
                    text: title
                },
                multiple: false
            });

            frame.on('select', function() {
                var attachment = frame.state().get('selection').first().toJSON();
                if (id_target) id_target.val(attachment.id);
                if (url_target) url_target.val(attachment.url);

                if (preview && preview.length) {
                    preview.html('<img src="' + attachment.url + '" alt="" style="max-width:200px;height:auto;" />').show();
                }
                button.siblings('.mhbo-remove-btn').show();
            });

            frame.open();
        });

        $('.mhbo-remove-btn').on('click', function(e) {
            e.preventDefault();
            var button = $(this);
            var wrap = button.closest('.mhbo-media-upload-wrap');

            wrap.find('input[type="hidden"]').val('');
            wrap.find('.mhbo-image-preview').empty().hide();
            button.hide();
        });
    });

})(jQuery);
