/**
 * MHBO Business Settings Admin
 *
 * Handles media upload for Business Logo, WhatsApp QR, and Banking QR.
 *
 * @package ModernHotelBooking
 * @since   2.1.0
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        
        // Generic Media Upload Handler
        $('.mhbo-business-upload-button').on('click', function(e) {
            e.preventDefault();
            
            var $button     = $(this);
            var $container  = $button.closest('.mhbo-business-media-control');
            var $input      = $container.find('.mhbo-business-media-url');
            var $preview    = $container.find('.mhbo-business-media-preview');
            var customFrame = wp.media({
                title: $button.data('title') || 'Select Media',
                button: { text: $button.data('button') || 'Use this media' },
                multiple: false
            });

            customFrame.on('select', function() {
                var attachment = customFrame.state().get('selection').first().toJSON();
                $input.val(attachment.url);
                
                if ($preview.length) {
                    $preview.html('<img src="' + attachment.url + '" style="max-width:150px;height:auto;margin-top:10px;">');
                }
                
                // Trigger change for any listeners
                $input.trigger('change');
            });

            customFrame.open();
        });

        // Tab Navigation (if handled via JS instead of just page refreshes)
        $('.mhbo-settings-tabs-nav a').on('click', function(e) {
             // Currently handled via PHP/GET but this allows smooth transitions if needed later
        });
    });

})(jQuery);
