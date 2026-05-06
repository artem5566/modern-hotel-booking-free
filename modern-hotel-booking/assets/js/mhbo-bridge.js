/**
 * MHBO Bridge Module (2026 BP)
 * 
 * Acts as a bridge between the modern Interactivity API / Script Modules
 * and the legacy jQuery-based calendar logic.
 */
import { store, getElement } from '@wordpress/interactivity';

export const init = () => {
    // This module can be imported by other block modules
    // to trigger jQuery events or shared logic.
    if (window.jQuery) {
        window.jQuery(document).trigger('mhbo_bridge_initialized');
    }
};

store('modern-hotel-booking', {
    actions: {
        initCalendar: () => {
             const { ref } = getElement();
             if (window.jQuery && ref) {
                 // Trigger the global init function from mhbo-calendar.js
                 window.jQuery(document).trigger('mhbo_init_calendar', { element: ref });
             }
        }
    }
});
