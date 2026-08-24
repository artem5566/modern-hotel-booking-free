( function ( $ ) {
	'use strict';

	// Debug logger
	const debugLog = ( function () {
		const isDebug =
			( typeof mhbo_vars !== 'undefined' && mhbo_vars.debug ) ||
			( typeof localStorage !== 'undefined' &&
				localStorage.getItem( 'mhbo_debug' ) );
		return isDebug
			? console.error.bind( console, '[MHBO]' )
			: function () {};
	} )();

	/**
	 * Initialize a single booking form wrapper instance.
	 * Called on DOMContentLoaded for page-embedded forms and on
	 * mhboModalContent for dynamically injected modal forms.
	 * A data attribute guards against double-initialization.
	 * @param wrapper
	 */
	function initBookingFormWrapper( wrapper ) {
		// Guard: skip if already initialized
		if ( wrapper.dataset.mhboFormInit === '1' ) {
			return;
		}
		wrapper.dataset.mhboFormInit = '1';
		const form = wrapper.querySelector( '.mhbo-booking-form' );
		if ( ! form ) {
			return;
		}

		const childrenSelect = wrapper.querySelector(
			'.mhbo-booking-children'
		);
		const agesContainer = wrapper.querySelector(
			'.mhbo-child-ages-container'
		);
		const agesInputs = wrapper.querySelector( '.mhbo-child-ages-inputs' );
		const guestsSelect = wrapper.querySelector( '.mhbo-booking-guests' );
		const totalDisplay = wrapper.querySelector( '.mhbo-display-total' );
		const totalHidden = form.querySelector( 'input[name="total_price"]' );
		const arrivalTotalEl = wrapper.querySelector(
			'.mhbo-arrival-total-price'
		);
		const submitBtn = wrapper.querySelector( '.mhbo-submit-btn' );
		const consentCheckbox = wrapper.querySelector( '.mhbo-consent' );
		const errorBox = wrapper.querySelector( '.mhbo-booking-errors' );

		let debounceTimer;
		let recalcAbortController = null; // Cancel in-flight requests to prevent 429

		/**
		 * Recalculate price via REST API
		 */
		function recalculatePrice() {
			if ( ! totalDisplay ) {
				return;
			}

			// Cancel any in-flight request immediately so it doesn't count against rate limits.
			if ( recalcAbortController ) {
				recalcAbortController.abort();
				recalcAbortController = null;
			}

			clearTimeout( debounceTimer );
			totalDisplay.classList.add( 'mhbo_faded' );
			if ( arrivalTotalEl ) {
				arrivalTotalEl.classList.add( 'mhbo_faded' );
			}
			if ( submitBtn ) {
				submitBtn.disabled = true;
			}

			debounceTimer = setTimeout( async function () {
				recalcAbortController = new AbortController();
				window.dispatchEvent(
					new CustomEvent( 'mhbo_processing_start', {
						detail: { source: 'booking_form', instance: wrapper },
					} )
				);

				try {
					const roomIdInput = form.querySelector(
						'input[name="mhbo_room_id"]'
					);
					const roomId = roomIdInput ? roomIdInput.value : 0;
					const checkInInput = form.querySelector(
						'input[name="check_in"]'
					);
					const checkIn = checkInInput ? checkInInput.value : '';
					const checkOutInput = form.querySelector(
						'input[name="check_out"]'
					);
					const checkOut = checkOutInput ? checkOutInput.value : '';
					const guests = guestsSelect ? guestsSelect.value : 1;
					const children = childrenSelect ? childrenSelect.value : 0;

					const extras = {};
					form.querySelectorAll( '.mhbo-extra-input' ).forEach(
						function ( input ) {
							let val = 0;
							if ( input.type === 'checkbox' ) {
								if ( input.checked ) {
									val = 1;
								}
							} else {
								val = input.value;
							}

							if ( val && parseFloat( val ) > 0 ) {
								// Priority: data-extra-id, then id from name
								const extraId = input.dataset.extraId;
								if ( extraId ) {
									extras[ extraId ] = val;
								} else {
									const match =
										input.name.match( /\[(.*?)\]/ );
									if ( match ) {
										extras[ match[ 1 ] ] = val;
									}
								}
							}
						}
					);

					const paymentTypeInput = form.querySelector(
						'input[name="mhbo_payment_type"]:checked'
					);
					const paymentType = paymentTypeInput
						? paymentTypeInput.value
						: 'full';

					const childrenAges = [];
					form.querySelectorAll(
						'input[name="child_ages[]"]'
					).forEach( function ( input ) {
						const v = input.value.trim();
						// Only include ages that have been explicitly entered.
						// Missing ages are treated as chargeable by the backend (safe default).
						if ( v !== '' ) {
							childrenAges.push( parseInt( v, 10 ) );
						}
					} );

					const couponAppliedField = form.querySelector(
						'[name="mhbo_coupon_applied"]'
					);
					const activeCouponCode = couponAppliedField
						? couponAppliedField.value.trim()
						: '';

					const response = await fetch(
						mhbo_vars.rest_url + '/recalculate-price',
						{
							method: 'POST',
							signal: recalcAbortController.signal,
							headers: {
								'Content-Type': 'application/json',
								'X-WP-Nonce': mhbo_vars.nonce,
							},
							body: JSON.stringify( {
								room_id: roomId,
								check_in: checkIn,
								check_out: checkOut,
								guests,
								children,
								child_ages: childrenAges,
								extras,
								mhbo_payment_type: paymentType,
								coupon_code: activeCouponCode,
							} ),
						}
					);

					const data = await response.json();

					if ( data.success ) {
						totalDisplay.textContent = data.total_formatted;
						if ( totalHidden ) {
							totalHidden.value = data.total;
						}

						// Children cost row — show when children contribute to the total
						const childrenRow = wrapper.querySelector(
							'.mhbo-children-cost-row'
						);
						const childrenTotalEl = wrapper.querySelector(
							'.mhbo-children-total-display'
						);
						if ( childrenRow && childrenTotalEl ) {
							if (
								data.children_total > 0 &&
								data.children_total_formatted
							) {
								childrenTotalEl.textContent =
									data.children_total_formatted;
								childrenRow.style.display = '';
							} else {
								childrenRow.style.display = 'none';
								childrenTotalEl.textContent = '';
							}
						}
						if ( arrivalTotalEl ) {
							arrivalTotalEl.textContent = data.total_formatted;
						}

						// Update tax breakdown (2026 BP: Robust detection)
						const taxContainer = wrapper.querySelector(
							'.mhbo-tax-breakdown-container, .mhbo-tax-summary, .mhbo-price-summary-totals'
						);
						if (
							taxContainer &&
							typeof data.tax_breakdown_html !== 'undefined'
						) {
							taxContainer.innerHTML = data.tax_breakdown_html;
							if ( data.tax_breakdown_html === '' ) {
								taxContainer.style.display = 'none';
							} else {
								taxContainer.style.display = 'block';
							}
							// Re-apply deposit row visibility after HTML replacement —
							// the server renders deposit rows hidden by default and relies
							// on the client to show them when deposit payment is selected.
							updateDepositVisibility();

							// Re-insert the coupon discount row if the server confirmed the coupon is still valid.
							// taxContainer.innerHTML wipes the row that mhbo-coupons.js inserted; this event
							// lets mhbo-coupons.js re-add it without triggering another AJAX validation.
							if (
								data.coupon_applied &&
								data.coupon_discount_formatted
							) {
								wrapper.dispatchEvent(
									new CustomEvent( 'mhboCouponDisplay', {
										bubbles: true,
										detail: {
											code: data.coupon_applied,
											discount_formatted:
												data.coupon_discount_formatted,
											new_total_formatted:
												data.total_formatted,
										},
									} )
								);
							}
						}

						// Update Payment Cards if deposit info returned
						if ( data.deposit_amount_formatted ) {
							// Reveal the deposit wrapper if it was hidden pending first price load.
							const depositWrapper = form.querySelector(
								'.mhbo-deposit-options-wrapper'
							);
							if (
								depositWrapper &&
								depositWrapper.style.display === 'none'
							) {
								depositWrapper.style.display = '';
							}

							const depositCard = form.querySelector(
								'.mhbo-payment-card[data-payment-type="deposit"]'
							);
							if ( depositCard ) {
								const amountEl = depositCard.querySelector(
									'.mhbo-deposit-amount'
								);
								const balanceEl = depositCard.querySelector(
									'.mhbo-deposit-balance-display'
								);
								if ( amountEl ) {
									amountEl.textContent =
										data.deposit_amount_formatted;
								}
								if ( balanceEl ) {
									balanceEl.textContent =
										data.remaining_balance_formatted;
								}

								// Update data attributes for gateways
								depositCard.dataset.amount =
									data.deposit_amount;
								depositCard.dataset.balance =
									data.remaining_balance;
							}

							// 2026 BP: Also update the Reservation Summary deposit rows so the
							// in-summary breakdown matches the payment card and the charged amount.
							const depositAmountDisplay = wrapper.querySelector(
								'#mhbo-deposit-amount-display'
							);
							const remainingBalanceDisplay =
								wrapper.querySelector(
									'#mhbo-remaining-balance-display'
								);
							if ( depositAmountDisplay ) {
								depositAmountDisplay.textContent =
									data.deposit_amount_formatted;
							}
							if ( remainingBalanceDisplay ) {
								remainingBalanceDisplay.textContent =
									data.remaining_balance_formatted;
							}

							const fullCard = form.querySelector(
								'.mhbo-payment-card[data-payment-type="full"]'
							);
							if ( fullCard ) {
								const fullAmountEl =
									fullCard.querySelector(
										'.mhbo-full-amount'
									);
								if ( fullAmountEl ) {
									fullAmountEl.textContent =
										data.total_formatted;
								}
								fullCard.dataset.amount = data.total;
							}
						}

						// [Premium] Update individual extra pricing display
						if ( data.extras_breakdown ) {
							Object.entries( data.extras_breakdown ).forEach(
								( [ id, impact ] ) => {
									const card = form.querySelector(
										`.mhbo-extra-card[data-extra-id="${ id }"]`
									);
									if ( ! card ) {
										return;
									}

									const tag = card.querySelector(
										'.mhbo-extra-price-tag'
									);
									const breakdown = card.querySelector(
										'.mhbo-extra-price-breakdown'
									);

									// Unit price always shows the per-unit cost (large display)
									if ( tag && impact.unit_price_formatted ) {
										tag.textContent =
											impact.unit_price_formatted;
										tag.classList.add(
											'mhbo-impact-highlight'
										);
									}

									// Breakdown line: "× N = RON 40" when multiplier > 1
									if ( breakdown ) {
										const mult = impact.multiplier || 1;
										if ( mult > 1 && impact.value > 0 ) {
											breakdown.textContent =
												'× ' +
												mult +
												' = ' +
												impact.formatted;
										} else {
											breakdown.textContent = '';
										}
									}
								}
							);
						}

						// Toggle deposit/balance rows based on selection
						updateDepositVisibility();

						window.dispatchEvent(
							new CustomEvent( 'mhbo_price_updated', {
								detail: { data, instance: wrapper },
							} )
						);

						// Sync Stripe intent if active
						if (
							typeof window.mhboRefreshStripeIntent === 'function'
						) {
							window.mhboRefreshStripeIntent();
						}
					} else {
						debugLog( 'Recalculate failed:', data.message );
						// [CRITICAL 2026 FIX] Show error to user in a consistent way
						const errorContainer = wrapper.querySelector(
							'.mhbo-booking-errors, .mhbo-errors-container'
						);
						if ( errorContainer ) {
							errorContainer.innerHTML =
								'<div class="mhbo-error">' +
								( data.message ||
									'Error recalculating price.' ) +
								'</div>';
							errorContainer.style.display = 'block';
							setTimeout( () => {
								errorContainer.style.display = 'none';
							}, 5000 );
						}
					}
				} catch ( err ) {
					if ( err.name === 'AbortError' ) {
						return;
					} // Superseded by a newer request — not an error.
					debugLog( 'Recalculate error:', err );
				} finally {
					recalcAbortController = null;
					totalDisplay.classList.remove( 'mhbo_faded' );
					if ( arrivalTotalEl ) {
						arrivalTotalEl.classList.remove( 'mhbo_faded' );
					}

					window.dispatchEvent(
						new CustomEvent( 'mhbo_processing_end', {
							detail: {
								source: 'booking_form',
								instance: wrapper,
							},
						} )
					);

					updateSubmitState();
				}
			}, 400 );
		}

		/**
		 * Update visibility of deposit-related summary rows and wrapper.
		 * 2026 BP: Also toggles .mhbo-deposit-breakdown-summary wrapper — required because
		 * Tax.php renders the wrapper hidden by default on the booking form (payment_type='full').
		 */
		function updateDepositVisibility() {
			const paymentType = form.querySelector(
				'input[name="mhbo_payment_type"]:checked'
			);
			const isDeposit = paymentType && paymentType.value === 'deposit';

			wrapper
				.querySelectorAll(
					'.mhbo-deposit-amount-row, .mhbo-remaining-balance-row'
				)
				.forEach( ( row ) => {
					row.style.display = isDeposit ? 'table-row' : 'none';
				} );

			// Show/hide the deposit summary section wrapper
			wrapper
				.querySelectorAll( '.mhbo-deposit-breakdown-summary' )
				.forEach( ( el ) => {
					el.style.display = isDeposit ? '' : 'none';
				} );
		}

		/**
		 * GDPR Consent Enforcement & Button State
		 */
		function updateSubmitState() {
			if ( ! submitBtn ) {
				return;
			}

			let isAllowed = true;
			if ( consentCheckbox ) {
				isAllowed = consentCheckbox.checked;
			}

			submitBtn.disabled = ! isAllowed;
			submitBtn.style.opacity = isAllowed ? '1' : '0.5';
			submitBtn.style.cursor = isAllowed ? 'pointer' : 'not-allowed';
		}

		if ( childrenSelect ) {
			childrenSelect.addEventListener( 'change', function () {
				const count = parseInt( this.value );
				if ( count > 0 ) {
					agesContainer.style.display = 'block';
					let html = '';
					for ( let i = 0; i < count; i++ ) {
						const label = mhbo_vars.label_child_n_age.replace(
							'%d',
							i + 1
						);
						html +=
							'<div class="mhbo-child-age-group">' +
							'<label>' +
							label +
							' <span class="required">*</span></label>' +
							'<input type="number" name="child_ages[]" value="" placeholder="0" min="0" max="17" required class="mhbo-child-age-input">' +
							'</div>';
					}
					agesInputs.innerHTML = html;
				} else {
					agesContainer.style.display = 'none';
					agesInputs.innerHTML = '';
				}
				recalculatePrice();
			} );

			$( wrapper ).on(
				'change',
				'.mhbo-child-age-input',
				recalculatePrice
			);
		}

		if ( guestsSelect ) {
			guestsSelect.addEventListener( 'change', recalculatePrice );
		}

		// Quantity stepper buttons — update the input directly (it IS the form field).
		$( wrapper ).on( 'click', '.mhbo-qty-btn', function ( e ) {
			e.preventDefault();
			e.stopPropagation();
			const $card = $( this ).closest( '.mhbo-extra-card' );
			const $input = $card.find( '.mhbo-extra-input' );
			let val = parseFloat( $input.val() ) || 0;
			val = $( this ).hasClass( 'plus' )
				? val + 1
				: Math.max( 0, val - 1 );
			$input.val( val ).trigger( 'change' );
		} );

		// Sync card selected state + trigger price recalculation on any extra change.
		$( wrapper ).on( 'change', '.mhbo-extra-input', function () {
			const $input = $( this );
			const $card = $input.closest( '.mhbo-extra-card' );
			const selected =
				$input.attr( 'type' ) === 'checkbox'
					? $input.prop( 'checked' )
					: parseFloat( $input.val() ) > 0;
			$card.toggleClass( 'selected', selected );
			recalculatePrice();
		} );

		// [CRITICAL 2026 FIX] Add listeners for check-in/out to trigger recalculation when calendar updates them
		const dateInputs = wrapper.querySelectorAll(
			'input[name="check_in"], input[name="check_out"]'
		);
		dateInputs.forEach( ( input ) => {
			input.addEventListener( 'change', recalculatePrice );
		} );

		// Payment Type Change
		$( wrapper ).on(
			'change',
			'input[name="mhbo_payment_type"]',
			function () {
				// UI feedback
				wrapper
					.querySelectorAll( '.mhbo-payment-card' )
					.forEach( ( c ) => c.classList.remove( 'active' ) );
				const card = this.closest( '.mhbo-payment-card' );
				if ( card ) {
					card.classList.add( 'active' );
				}

				// Immediately update deposit row visibility so the summary reflects
				// the selection before the async recalculate response arrives.
				updateDepositVisibility();
				recalculatePrice();
			}
		);

		if ( consentCheckbox ) {
			consentCheckbox.addEventListener( 'change', updateSubmitState );
			updateSubmitState();
		} else {
			updateSubmitState();
		}

		// Initial visibility
		updateDepositVisibility();

		/**
		 * Form submission validation
		 */
		form.addEventListener( 'submit', function ( e ) {
			if ( errorBox ) {
				errorBox.style.display = 'none';
			}

			if ( consentCheckbox && ! consentCheckbox.checked ) {
				e.preventDefault();
				showInstanceError(
					mhbo_vars.msg_gdpr_required ||
						'Please accept the privacy policy to continue.'
				);
				return;
			}

			const method = form.querySelector(
				'input[name="mhbo_payment_method"]:checked'
			);
			const methodValue = method ? method.value : 'arrival';

			if ( methodValue === 'stripe' ) {
				const piInput = form.querySelector(
					'input[name="mhbo_stripe_payment_intent"]'
				);
				const isModalCtxStripe =
					wrapper && wrapper.dataset.modalContext === '1';
				if ( ! isModalCtxStripe || ! piInput || ! piInput.value ) {
					return; // Hand off to PaymentGateways.js
				}
				// PI confirmed in modal — fall through to REST path
			}

			if ( methodValue === 'paypal' ) {
				const paypalOrderInput = form.querySelector(
					'input[name="mhbo_paypal_order_id"]'
				);
				if ( ! paypalOrderInput || ! paypalOrderInput.value ) {
					e.preventDefault();
					showInstanceError(
						mhbo_vars.msg_paypal_required ||
							'Please use the PayPal button to complete payment.'
					);
					return;
				}
				const isModalCtxPP =
					wrapper && wrapper.dataset.modalContext === '1';
				if ( ! isModalCtxPP ) {
					return; // Standard path: native form POST
				}
			}

			if ( methodValue === 'braintree' ) {
				e.preventDefault();
				if ( typeof e.stopPropagation === 'function' ) {
					e.stopPropagation();
				}
				return; // Hand off exclusively to PaymentGateways.js
			}

			// [2026 BP] REST API intercept for all remaining payment methods (arrival, bank transfer, etc.)
			e.preventDefault();

			// Clear ALL previous error boxes in wrapper/form/modal before processing
			const searchCtx = ( typeof form.closest === 'function' ? form.closest( '.mhbo-booking-form-wrapper' ) : null ) || ( typeof document.querySelector === 'function' ? document : null );
			if ( searchCtx && searchCtx.querySelectorAll ) {
				searchCtx.querySelectorAll( '#mhbo-booking-error, .mhbo-booking-error, .mhbo-error-notification, .mhbo-booking-errors, .mhbo-inline-errors, .mhbo-message.mhbo-error' ).forEach( function ( box ) {
					box.style.display = 'none';
					box.innerHTML = '';
				} );
			}

			if ( submitBtn ) {
				submitBtn.disabled = true;
				submitBtn.textContent = mhbo_vars.processing || 'Processing...';
			}

			const roomIdInput = form.querySelector(
				'input[name="mhbo_room_id"]'
			);
			const roomId = roomIdInput ? roomIdInput.value : 0;
			const typeIdInput = form.querySelector(
				'input[name="mhbo_type_id"]'
			);
			const typeId = typeIdInput ? typeIdInput.value : 0;
			const checkInInput = form.querySelector( 'input[name="check_in"]' );
			const checkIn = checkInInput ? checkInInput.value : '';
			const checkOutInput = form.querySelector(
				'input[name="check_out"]'
			);
			const checkOut = checkOutInput ? checkOutInput.value : '';
			const guests = guestsSelect ? guestsSelect.value : 1;
			const children = childrenSelect ? childrenSelect.value : 0;

			const extras = {};
			form.querySelectorAll( '.mhbo-extra-input' ).forEach(
				function ( input ) {
					let val = 0;
					if ( input.type === 'checkbox' ) {
						if ( input.checked ) {
							val = 1;
						}
					} else {
						val = input.value;
					}
					if ( val && parseFloat( val ) > 0 ) {
						const extraId = input.dataset.extraId;
						if ( extraId ) {
							extras[ extraId ] = val;
						} else {
							const match = input.name.match( /\[(.*?)\]/ );
							if ( match ) {
								extras[ match[ 1 ] ] = val;
							}
						}
					}
				}
			);

			const paymentTypeInput = form.querySelector(
				'input[name="mhbo_payment_type"]:checked'
			);
			const paymentType = paymentTypeInput
				? paymentTypeInput.value
				: 'full';

			const childrenAges = [];
			form.querySelectorAll( 'input[name="child_ages[]"]' ).forEach(
				function ( input ) {
					const v = input.value.trim();
					if ( v !== '' ) {
						childrenAges.push( parseInt( v, 10 ) );
					}
				}
			);

			const customFields = {};
			form.querySelectorAll(
				'input[name^="mhbo_custom["], textarea[name^="mhbo_custom["]'
			).forEach( function ( input ) {
				const match = input.name.match( /mhbo_custom\[(.*?)\]/ );
				if ( match ) {
					customFields[ match[ 1 ] ] = input.value;
				}
			} );

			const consentEl = form.querySelector(
				'input[name="mhbo_consent"]'
			);
			const consent = consentEl ? consentEl.checked : false;

			const body = {
				room_id: roomId,
				type_id: typeId,
				check_in: checkIn,
				check_out: checkOut,
				customer_name:
					form.querySelector( 'input[name="customer_name"]' )
						?.value || '',
				customer_email:
					form.querySelector( 'input[name="customer_email"]' )
						?.value || '',
				customer_phone:
					form.querySelector( 'input[name="customer_phone"]' )
						?.value || '',
				guests,
				children,
				child_ages: childrenAges,
				extras,
				payment_method: methodValue,
				payment_type: paymentType,
				custom_fields: customFields,
				admin_notes:
					form.querySelector( 'textarea[name="admin_notes"]' )
						?.value || '',
				update_id:
					form.querySelector( 'input[name="mhbo_update_id"]' )
						?.value || 0,
				consent,
				booking_language: mhbo_vars.language || 'en',
				page_url: window.location.href,
				stripe_pi: ( function () {
					const el = form.querySelector(
						'input[name="mhbo_stripe_payment_intent"]'
					);
					return el ? el.value : '';
				} )(),
				paypal_order_id: ( function () {
					const el = form.querySelector(
						'input[name="mhbo_paypal_order_id"]'
					);
					return el ? el.value : '';
				} )(),
				paypal_capture_id: ( function () {
					const el = form.querySelector(
						'input[name="mhbo_paypal_capture_id"]'
					);
					return el ? el.value : '';
				} )(),
				mhbo_coupon_code: ( function () {
					const el = form.querySelector(
						'[name="mhbo_coupon_applied"]'
					);
					return el ? el.value.trim() : '';
				} )(),
			};

			fetch( mhbo_vars.rest_url + '/booking/complete', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': mhbo_vars.nonce,
				},
				body: JSON.stringify( body ),
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( result ) {
					// Normalize between legacy AJAX (result.success+result.data) and direct REST response
					const successData =
						result.success && result.data ? result.data : result;

					if ( successData.redirect_url ) {
						const isModalContext =
							wrapper.dataset.modalContext === '1';
						if ( isModalContext ) {
							// Modal path: parse token + status from redirect URL,
							// hand to Phase 5 confirmation handler.
							let bookingToken = '';
							let bookingStatus = 'confirmed';
							try {
								const u = new URL(
									successData.redirect_url,
									window.location.href
								);
								bookingToken =
									u.searchParams.get( 'reference' ) || '';
								bookingStatus =
									u.searchParams.get( 'mhbo_status' ) ||
									'confirmed';
							} catch ( _ ) {}
							document.dispatchEvent(
								new CustomEvent( 'mhboBookingComplete', {
									detail: {
										booking_token: bookingToken,
										status: bookingStatus,
									},
								} )
							);
						} else {
							window.location.href = successData.redirect_url;
						}
					} else {
						const msg =
							successData.message ||
							mhbo_vars.label_generic_error ||
							'Booking failed. Please try again.';
						showInstanceError( msg );
						if ( submitBtn ) {
							submitBtn.disabled = false;
							submitBtn.textContent =
								mhbo_vars.confirm || 'Confirm Booking';
						}
					}
				} )
				.catch( function ( err ) {
					console.error( '[MHBO] REST API Error:', err );
					showInstanceError(
						mhbo_vars.label_network_error ||
							'A network error occurred. Please try again.'
					);
					if ( submitBtn ) {
						submitBtn.disabled = false;
						submitBtn.textContent =
							mhbo_vars.confirm || 'Confirm Booking';
					}
				} );
		} );

		function showInstanceError( message ) {
			if ( errorBox ) {
				errorBox.innerHTML =
					'<div class="mhbo-error-notification"><span class="mhbo-error-icon">⚠️</span> ' +
					message +
					'</div>';
				errorBox.style.display = 'block';
				errorBox.scrollIntoView( {
					behavior: 'smooth',
					block: 'center',
				} );
			} else {
				alert( message );
			}
		}
	} // end initBookingFormWrapper

	// Initialize forms present on page load
	document.addEventListener( 'DOMContentLoaded', function () {
		if ( typeof mhbo_vars === 'undefined' ) {
			debugLog( 'mhbo_vars is not defined.' );
		}
		document
			.querySelectorAll( '.mhbo-booking-form-wrapper' )
			.forEach( initBookingFormWrapper );
	} );

	// Re-initialize when the modal injects new booking form content
	document.addEventListener( 'mhboModalContent', function () {
		document
			.querySelectorAll( '.mhbo-booking-form-wrapper' )
			.forEach( initBookingFormWrapper );
	} );
} )( jQuery );
