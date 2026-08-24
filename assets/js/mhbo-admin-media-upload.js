/**
 * MHBO Admin Media Upload
 *
 * Handles WordPress media modal for logo and QR code uploads in Business Info settings.
 *
 * @param $
 * @package
 * @since   2.1.0
 */

( function ( $ ) {
	'use strict';

	$( function () {
		$( '.mhbo-upload-btn, .mhbo-upload-button' ).on(
			'click',
			function ( e ) {
				e.preventDefault();

				const button = $( this );
				const id_target = button.data( 'target-id' )
					? $( '#' + button.data( 'target-id' ) )
					: null;
				const url_target = button.data( 'target-url' )
					? $( '#' + button.data( 'target-url' ) )
					: button.data( 'target' )
					? $( button.data( 'target' ) )
					: null;
				const preview = button.data( 'preview' )
					? $( '#' + button.data( 'preview' ) )
					: null;
				const title = button.data( 'title' ) || 'Select Image';

				const frame = wp.media( {
					title,
					button: {
						text: title,
					},
					multiple: false,
				} );

				frame.on( 'select', function () {
					const attachment = frame
						.state()
						.get( 'selection' )
						.first()
						.toJSON();
					if ( id_target ) {
						id_target.val( attachment.id );
					}
					if ( url_target ) {
						url_target.val( attachment.url );
					}

					if ( preview && preview.length ) {
						preview
							.html(
								'<img src="' +
									attachment.url +
									'" alt="" style="max-width:200px;height:auto;" />'
							)
							.show();
					}
					button.siblings( '.mhbo-remove-btn' ).show();
				} );

				frame.open();
			}
		);

		$( '.mhbo-remove-btn' ).on( 'click', function ( e ) {
			e.preventDefault();
			const button = $( this );
			const wrap = button.closest( '.mhbo-media-upload-wrap' );

			wrap.find( 'input[type="hidden"]' ).val( '' );
			wrap.find( '.mhbo-image-preview' ).empty().hide();
			button.hide();
		} );
	} );
} )( jQuery );
