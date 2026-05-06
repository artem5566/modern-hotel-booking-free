/**
 * Room Calendar Block: View Script Module
 *
 * WP 2026 BP: Script Module (ESM) for viewScriptModule in block.json.
 * Uses @wordpress/interactivity store for reactive frontend behaviour.
 * Bridges to legacy jQuery/flatpickr initialisation via custom event.
 *
 * @since 2.3.0
 */
import { store, getElement } from '@wordpress/interactivity';

store( 'modern-hotel-booking/room-calendar', {
	callbacks: {
		init: () => {
			const { ref } = getElement();

			// Trigger legacy flatpickr initialisation via custom jQuery event.
			// This bridges the ESM module world with the existing jQuery calendar code.
			if ( window.jQuery ) {
				window.jQuery( ref ).trigger( 'mhbo_init_calendar' );
			}
		},
	},
} );
