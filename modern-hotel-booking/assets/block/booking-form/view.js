/**
 * Booking Form Block: View Module (2026 BP)
 */
import { store, getElement } from '@wordpress/interactivity';

store('modern-hotel-booking/booking-form', {
    callbacks: {
        init: () => {
            const { ref } = getElement();
            console.log('[MHBO] Booking Form Module Initializing:', ref);
            
            // Trigger legacy initialization via jQuery if available
            if (window.jQuery) {
                window.jQuery(ref).trigger('mhbo_init_form');
            }
        }
    }
});
