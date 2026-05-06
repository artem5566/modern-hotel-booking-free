jQuery(document).ready(function ($) {

    // Debug logger - only logs when mhbo_calendar.debug is true or localStorage flag is set
    const debugLog = (function () {
        const isDebug = (typeof mhbo_calendar !== 'undefined' && mhbo_calendar.debug) ||
            (typeof localStorage !== 'undefined' && localStorage.getItem('mhbo_debug'));
        return isDebug ? console.error.bind(console, '[MHBO]') : function () { };
    })();

    // Create shared tooltip element (2026 BP: Single tooltip per page for performance)
    let $tooltip = $('.mhbo-calendar-tooltip');
    if (!$tooltip.length) {
        $tooltip = $('<div class="mhbo-calendar-tooltip"><span class="mhbo-tooltip-nights"></span><span class="mhbo-tooltip-price"></span><span class="mhbo-tooltip-constraint" style="display:none"></span></div>').appendTo('body');
    }

    // Mobile: mouseleave never fires on touch, so dismiss tooltip on any touch outside the calendar
    if (!$(document).data('mhbo-tooltip-touch-attached')) {
        $(document).on('touchstart pointerup', function (e) {
            if (!$(e.target).closest('.mhbo-calendar-inline').length) {
                $tooltip.removeClass('mhbo-visible mhbo-tooltip-warn');
                $tooltip.find('.mhbo-tooltip-constraint').hide();
                $('.mhbo-range-hover').removeClass('mhbo-range-hover');
            }
        });
        $(document).data('mhbo-tooltip-touch-attached', true);
    }

    function initAllCalendars() {
        $('.mhbo-calendar-container').each(function () {
            const $wrapper = $(this);
            
            // Double-initialization protection (2026 best practice)
            if ($wrapper.data('mhbo-initialized')) return;
            $wrapper.data('mhbo-initialized', true);
            $wrapper.addClass('mhbo-initialized');

            const roomId = $wrapper.data('room-id');
            const $selectionBox = $wrapper.find('.mhbo-selection-box');
            const $guide = $wrapper.find('.mhbo-calendar-guide');
            const $inlineContainer = $wrapper.find('.mhbo-calendar-inline');
            const $errorBox = $wrapper.find('.mhbo-calendar-errors');
            const showPrice = String($wrapper.data('show-price')) === '1';

            let picker = null;
            let disabledDates = [];
            let priceData = {};
            let bookingStatusData = {}; // Track booking status per date
            let changeoverData = {}; // Track changeover status (checkin/checkout/both)
            let eligibilityData = {}; // Track selection eligibility (can_checkin/can_checkout)
            let minStayData = {}; // PRO: Track minimum stay per check-in date
            let maxStayData = {}; // PRO: Track maximum stay per check-in date
            let reasonData = {}; // Track block reason per disabled date (booked/manual/maintenance)
            let pendingCheckIn = null; // Track the first date clicked to detect backwards selection

            function showInlineError(message) {
                if ($errorBox.length) {
                    $errorBox.removeClass('mhbo-constraint-hint')
                             .text(message).addClass('mhbo-visible').fadeIn();
                    // Auto-hide after 5s for generic errors
                    setTimeout(() => {
                        $errorBox.fadeOut(() => {
                            $errorBox.removeClass('mhbo-visible').text('');
                        });
                    }, 5000);
                } else {
                    console.error('[MHBO] Validation Error:', message);
                }
            }

            // Persistent hint for min/max stay violations — stays visible until user corrects
            // their selection. Styled differently (warning, not error) so it reads as guidance.
            function showConstraintHint(message) {
                if ($errorBox.length) {
                    $errorBox.addClass('mhbo-constraint-hint')
                             .text(message).addClass('mhbo-visible').stop(true).fadeIn();
                } else {
                    console.warn('[MHBO] Stay constraint:', message);
                }
            }

            function hideConstraintHint() {
                if ($errorBox.length && $errorBox.hasClass('mhbo-constraint-hint')) {
                    $errorBox.fadeOut(() => {
                        $errorBox.removeClass('mhbo-visible mhbo-constraint-hint').text('');
                    });
                }
            }

            function initFlatpickr() {
                // Explicitly ensure hidden states on init (defense-in-depth)
                $selectionBox.removeClass('mhbo-visible').hide();
                $errorBox.removeClass('mhbo-visible').hide();

                $.ajax({
                    url: mhbo_calendar.rest_url,
                    method: 'GET',
                    data: {
                        room_id: roomId,
                        year: new Date().getFullYear(),
                        month: new Date().getMonth() + 1
                    },
                    beforeSend: function (xhr) {
                        xhr.setRequestHeader('X-WP-Nonce', mhbo_calendar.nonce);
                    },
                    success: function (data) {
                        if (data && Array.isArray(data)) {
                            processCalendarData(data);
                            // Expose stay-restriction maps to the submit handler (which runs
                            // outside this closure) so it can perform a final validation guard.
                            $wrapper.data('mhbo-min-stay-data', minStayData);
                            $wrapper.data('mhbo-max-stay-data', maxStayData);
                            renderFlatpickr();
                        }
                    },
                    error: function (xhr) {
                        debugLog('REST Error:', xhr.responseText);
                    }
                });
            }

            function processCalendarData(data) {
                data.forEach(item => {
                    if (item.status === 'booked' || item.price === 0 || item.price === '0' || !item.price) {
                        disabledDates.push(item.date);
                    }
                    priceData[item.date] = {
                        price: item.price,
                        formatted: item.price_formatted
                    };
                    // Store booking status (pending/confirmed) for color coding
                    if (item.booking_status) {
                        bookingStatusData[item.date] = item.booking_status;
                    }
                    // Determine changeover status for split-day styling
                    if (item.is_checkin && item.is_checkout) {
                        changeoverData[item.date] = 'both';
                    } else if (item.is_checkin) {
                        changeoverData[item.date] = 'checkin';
                    } else if (item.is_checkout) {
                        changeoverData[item.date] = 'checkout';
                    }

                    // Store eligibility for selection guards
                    eligibilityData[item.date] = {
                        checkin: item.can_checkin !== false,
                        checkout: item.can_checkout !== false
                    };

                    /* BUILD_PRO_START */
                    // Store stay restrictions (only if they exist)
                    if (item.min_stay) {
                        minStayData[item.date] = parseInt(item.min_stay);
                    }
                    if (item.max_stay) {
                        maxStayData[item.date] = parseInt(item.max_stay);
                    }
                    // Store block reason for tooltip display
                    if (item.reason) {
                        reasonData[item.date] = item.reason;
                    }
                    /* BUILD_PRO_END */
                });
            }

            function renderFlatpickr() {
                if (!$inlineContainer.length) return;

                // Determine locale dynamically based on loaded scripts
                // Fallback to English if the requested locale isn't loaded
                let locale = 'en';
                if (mhbo_calendar.current_lang && typeof flatpickr !== 'undefined' && flatpickr.l10ns && flatpickr.l10ns[mhbo_calendar.current_lang]) {
                    locale = mhbo_calendar.current_lang;
                }

                picker = flatpickr($inlineContainer[0], {
                    inline: true,
                    mode: "range",
                    minDate: "today",
                    maxDate: new Date().fp_incr(365), // Limit selectable dates to 1 year in the future
                    dateFormat: "Y-m-d",
                    disable: disabledDates,
                    disableMobile: true,
                    showMonths: 1,
                    locale: locale,
                    onChange: function (selectedDates, dateStr, instance) {
                        // Clear errors on change
                        $errorBox.hide();

                        const dateStrClicked = selectedDates.length > 0 ? instance.formatDate(selectedDates[selectedDates.length - 1], "Y-m-d") : null;

                        // Backwards-selection guard: flatpickr range mode auto-sorts selected dates,
                        // so clicking a date BEFORE the current check-in swaps it into position [0].
                        // This would shift the check-in to an earlier date with a different (possibly
                        // lower) min-stay rule, bypassing the rule for the originally intended check-in.
                        // Fix: detect the swap and restart selection from the earlier date, requiring
                        // the user to pick a new checkout — min-stay always applies to the real check-in.
                        if (selectedDates.length === 2 && pendingCheckIn) {
                            if (selectedDates[0].getTime() !== pendingCheckIn.getTime()) {
                                // Earlier date was clicked — treat it as a fresh check-in
                                pendingCheckIn = selectedDates[0];
                                instance.setDate([selectedDates[0]], true); // re-fires onChange with length=1
                                return;
                            }
                        }

                        // 1. Guard: Check-In Eligibility
                        if (selectedDates.length === 1 && dateStrClicked && eligibilityData[dateStrClicked]) {
                            if (!eligibilityData[dateStrClicked].checkin) {
                                showInlineError(mhbo_calendar.i18n.checkout_only_error || 'This date is restricted to check-outs only.');
                                return;
                            }
                        }

                        // 3. Guard: Min/Max Stay Enforcement (PRO)
                        /* BUILD_PRO_START */
                        if (selectedDates.length === 2) {
                            const checkIn    = selectedDates[0];
                            const checkOut   = selectedDates[1];
                            const checkInStr = instance.formatDate(checkIn, "Y-m-d");

                            const nights     = Math.round((checkOut - checkIn) / (1000 * 60 * 60 * 24));
                            const minRequired = minStayData[checkInStr];
                            const maxAllowed  = maxStayData[checkInStr];

                            if (minRequired && nights < minRequired) {
                                // Build actionable message: tell user the earliest valid checkout date
                                const earliest = new Date(checkIn);
                                earliest.setDate(earliest.getDate() + minRequired);
                                const earliestStr = earliest.toLocaleDateString(
                                    document.documentElement.lang || 'en',
                                    { month: 'short', day: 'numeric' }
                                );
                                // translators: %1$d = number of nights, %2$s = earliest checkout date
                                const base = (mhbo_calendar.i18n.api_err_min_stay || 'Minimum stay is %d nights.');
                                const hint = (mhbo_calendar.i18n.min_stay_hint || 'Please select %s or later as your check-out date.');
                                const msg  = base.replace('%d', minRequired).replace('%1$d', minRequired)
                                           + ' ' + hint.replace('%s', earliestStr).replace('%2$s', earliestStr);
                                showConstraintHint(msg);
                                // Keep check-in selected so user just needs to pick a different checkout
                                instance.setDate([checkIn], false);
                                return;
                            }

                            if (maxAllowed && nights > maxAllowed) {
                                // Build actionable message: tell user the latest valid checkout date
                                const latest = new Date(checkIn);
                                latest.setDate(latest.getDate() + maxAllowed);
                                const latestStr = latest.toLocaleDateString(
                                    document.documentElement.lang || 'en',
                                    { month: 'short', day: 'numeric' }
                                );
                                // translators: %1$d = number of nights, %2$s = latest checkout date
                                const base = (mhbo_calendar.i18n.api_err_max_stay || 'Maximum stay is %d nights.');
                                const hint = (mhbo_calendar.i18n.max_stay_hint || 'Please select %s or earlier as your check-out date.');
                                const msg  = base.replace('%d', maxAllowed).replace('%1$d', maxAllowed)
                                           + ' ' + hint.replace('%s', latestStr).replace('%2$s', latestStr);
                                showConstraintHint(msg);
                                // Keep check-in selected so user just needs to pick a different checkout
                                instance.setDate([checkIn], false);
                                return;
                            }

                            // Valid selection — clear any lingering constraint hint
                            hideConstraintHint();
                        }
                        /* BUILD_PRO_END */

                        // 2. Guard: Check-Out Eligibility 
                        if (selectedDates.length === 2 && dateStrClicked && eligibilityData[dateStrClicked]) {
                            if (!eligibilityData[dateStrClicked].checkout) {
                                showInlineError(mhbo_calendar.i18n.checkin_only_error || 'This date is restricted to check-ins only.');
                                // Keep the check-in date, but clear the check-out
                                instance.setDate([selectedDates[0]], false);
                                return;
                            }
                        }

                        // dynamically allow the next booked date to be a checkout date
                        if (selectedDates.length === 1) {
                            pendingCheckIn = selectedDates[0]; // record intended check-in for backwards guard
                            const checkIn = selectedDates[0];
                            let firstBookedAfter = null;
                            let minDiff = Infinity;

                            // Find the first disabled date that occurs AFTER the check-in date
                            for (let i = 0; i < disabledDates.length; i++) {
                                // Prevent timezone shift issues
                                const bdParts = disabledDates[i].split('-');
                                const bd = new Date(bdParts[0], bdParts[1] - 1, bdParts[2]);

                                if (bd > checkIn) {
                                    const diff = bd - checkIn;
                                    if (diff < minDiff) {
                                        minDiff = diff;
                                        firstBookedAfter = disabledDates[i];
                                    }
                                }
                            }

                            // Enable the first booked date AFTER check-in so it can be checked out of
                            // AND restrict maxDate to prevent "jumping over" existing bookings
                            let newDisabled = [...disabledDates];
                            if (firstBookedAfter) {
                                // For 2026 BP: Always un-disable firstBookedAfter.
                                // The REST API implicitly shifts firstBookedAfter back by 1 day ("dead day") when turnover is prevented.
                                newDisabled = newDisabled.filter(d => d !== firstBookedAfter);
                                instance.set('maxDate', firstBookedAfter);
                            } else {
                                instance.set('maxDate', new Date().fp_incr(365));
                            }

                            // Only update if changed to avoid unnecessary re-renders
                            if (JSON.stringify(instance.config.disable) !== JSON.stringify(newDisabled)) {
                                instance.set('disable', newDisabled);
                            }
                        }
                        else if (selectedDates.length === 2) {
                            pendingCheckIn = null; // valid forward range completed
                            // Reset maxDate limit once range is selected or auto-advanced
                            instance.set('maxDate', new Date().fp_incr(365));

                            // If both dates are the same, auto-advance checkout to the next day (min 1 night)
                            // We allow the next day even if it's "booked" because it's a valid check-out.
                            if (selectedDates[0].getTime() === selectedDates[1].getTime()) {
                                var nextDay = new Date(selectedDates[0]);
                                nextDay.setDate(nextDay.getDate() + 1);

                                // Silently update visual selection
                                instance.setDate([selectedDates[0], nextDay], false);

                                // Always keep checkout date un-disabled conceptually (dead day logic handles restrictions natively)
                                let checkOutStr = instance.formatDate(nextDay, "Y-m-d");
                                let newDisabledFinal = disabledDates.filter(d => d !== checkOutStr);
                                instance.set('disable', newDisabledFinal);

                                updateBookingForm([selectedDates[0], nextDay]);
                                return;
                            }

                            // Always keep the checkout date un-disabled conceptually so flatpickr doesn't erase it
                            let checkOutStr2 = instance.formatDate(selectedDates[1], "Y-m-d");
                            let newDisabledFinal2 = disabledDates.filter(d => d !== checkOutStr2);
                            instance.set('disable', newDisabledFinal2);

                            updateBookingForm(selectedDates);
                        }
                        else {
                            // length 0 or cleared
                            pendingCheckIn = null;
                            instance.set('maxDate', new Date().fp_incr(365));
                            instance.set('disable', disabledDates);
                            $selectionBox.removeClass('mhbo-visible').hide();
                            $guide.text(mhbo_calendar.i18n.select_checkout || 'Now select your check-out date');
                        }
                    },
                    onDayCreate: function (dObj, dStr, fp, dayElem) {
                        const date = fp.formatDate(dayElem.dateObj, "Y-m-d");
                        const dayNum = dayElem.dateObj.getDate();
                        dayElem.innerHTML = '';
                        const $num = $('<span class="mhbo-day-number">' + dayNum + '</span>');
                        $(dayElem).append($num);

                        // Apply half-day styling for checkin/checkout changeover dates
                        if (changeoverData[date]) {
                            $(dayElem).addClass('mhbo-half-booked-' + changeoverData[date]);
                        }

                        // Apply booking status CSS class for color coding
                        // Allow styling for disabled dates OR changeover dates (even if enabled/selectable)
                        if (bookingStatusData[date] && (dayElem.classList.contains('flatpickr-disabled') || changeoverData[date])) {
                            const today = new Date();
                            today.setHours(0, 0, 0, 0);
                            // Only apply status color if the date is not in the past
                            if (dayElem.dateObj >= today) {
                                $(dayElem).addClass('mhbo-booking-' + bookingStatusData[date]);
                            }
                        }

                        // Only render prices for room-specific calendars (not aggregated views).
                        if (showPrice && priceData[date] && !dayElem.classList.contains('flatpickr-disabled')) {
                            const pos = mhbo_calendar.settings.currency_pos;
                            const symbol = mhbo_calendar.settings.currency_symbol;
                            const priceVal = priceData[date].price;
                            const decimals = (typeof mhbo_calendar.settings.currency_decimals !== 'undefined') ? parseInt(mhbo_calendar.settings.currency_decimals) : 0;
                            
                            const formattedPrice = parseFloat(priceVal).toFixed(decimals);
                            
                            let priceHtml = '';
                            const spanCurrency = '<span class="mhbo-price-part-currency">' + symbol + '</span>';
                            const spanAmount = '<span class="mhbo-price-part-amount">' + formattedPrice + '</span>';
                            
                            if (pos === 'before') {
                                priceHtml = spanCurrency + ' ' + spanAmount;
                            } else {
                                priceHtml = spanAmount + ' ' + spanCurrency;
                            }
                            
                            const $priceTag = $('<span class="mhbo-fp-price">' + priceHtml + '</span>');
                            $(dayElem).append($priceTag);
                            $(dayElem).addClass('has-price');
                        }

                        /* BUILD_PRO_START */
                        // Stay restriction data is stored in minStayData/maxStayData but NOT
                        // rendered as badges — constraints are enforced via the onChange validator
                        // and communicated through the inline error box and hover tooltip instead.
                        /* BUILD_PRO_END */
                    }
                });

                // ATTACH INSTANCE TO WRAPPER (CRITICAL FOR HARDENING)
                $wrapper.data('mhbo-picker', picker);

                // --- INTERACTIVE PRICE-ON-HOVER (2026 BP) ---
                $wrapper.on('mousemove', '.flatpickr-day', function (e) {
                    /* BUILD_PRO_START */
                    // Show block reason tooltip for disabled dates (works with or without check-in selected)
                    if ($(this).hasClass('flatpickr-disabled') && this.dateObj) {
                        const disabledDateStr = picker ? picker.formatDate(this.dateObj, "Y-m-d") : '';
                        const reason = disabledDateStr ? reasonData[disabledDateStr] : null;
                        if (reason) {
                            const reasonLabels = {
                                booked:      mhbo_calendar.i18n.reason_booked      || 'Booked',
                                manual:      mhbo_calendar.i18n.reason_manual      || 'Unavailable',
                                maintenance: mhbo_calendar.i18n.reason_maintenance || 'Maintenance'
                            };
                            $tooltip.find('.mhbo-tooltip-nights').text('');
                            $tooltip.find('.mhbo-tooltip-price').hide();
                            $tooltip.find('.mhbo-tooltip-constraint')
                                .text(reasonLabels[reason] || reason)
                                .show();
                            $tooltip.css({ left: e.clientX, top: e.clientY })
                                .addClass('mhbo-visible')
                                .removeClass('mhbo-tooltip-warn');
                            return;
                        }
                    }
                    /* BUILD_PRO_END */



                    if (!picker || picker.selectedDates.length !== 1) {
                        $tooltip.removeClass('mhbo-visible');
                        $wrapper.find('.mhbo-range-hover').removeClass('mhbo-range-hover');
                        return;
                    }

                    const hoverDate = this.dateObj;
                    if (!hoverDate || hoverDate < picker.selectedDates[0] || $(this).hasClass('flatpickr-disabled')) {
                        $tooltip.removeClass('mhbo-visible');
                        $wrapper.find('.mhbo-range-hover').removeClass('mhbo-range-hover');
                        return;
                    }

                    // Calculate range from selected check-in to hovered date
                    const start = picker.selectedDates[0];
                    const end = hoverDate;
                    
                    // Don't show for same day
                    if (start.getTime() === end.getTime()) {
                        $tooltip.removeClass('mhbo-visible');
                        $wrapper.find('.mhbo-range-hover').removeClass('mhbo-range-hover');
                        return;
                    }

                    // Calculate total and night count
                    let total = 0;
                    let nights = 0;
                    let d = new Date(start);
                    
                    // Clear previous hover glow
                    $wrapper.find('.mhbo-range-hover').removeClass('mhbo-range-hover');

                    while (d < end) {
                        const dStr = picker.formatDate(d, "Y-m-d");
                        if (priceData[dStr]) {
                            total += parseFloat(priceData[dStr].price);
                        }
                        
                        // Apply hover glow to valid days in range
                        // Find the element for this date
                        const dayElem = picker.daysContainer.querySelector(`[aria-label="${picker.formatDate(d, picker.config.ariaDateFormat)}"]`);
                        if (dayElem) {
                            $(dayElem).addClass('mhbo-range-hover');
                        }

                        nights++;
                        d.setDate(d.getDate() + 1);
                    }

                    const formattedTotal = formatCurrency(total);
                    const nightsLabel = nights === 1 ? (mhbo_calendar.i18n.night || 'Night') : (mhbo_calendar.i18n.nights || 'Nights');
                    const nightsFull = nights + ' ' + nightsLabel;

                    const showPrice = $wrapper.data('show-price') === 1;

                    // Update and position tooltip
                    $tooltip.find('.mhbo-tooltip-nights').text(nightsFull);

                    if (showPrice && total > 0) {
                        $tooltip.find('.mhbo-tooltip-price').text(formattedTotal).show();
                    } else {
                        $tooltip.find('.mhbo-tooltip-price').hide();
                    }

                    /* BUILD_PRO_START */
                    // Show live constraint info/warning in tooltip so user knows the rule before clicking.
                    // — Violating (nights < min or nights > max): show ⚠ warning style.
                    // — Complying/No constraint: hide the span.
                    const checkInStr = picker.formatDate(start, "Y-m-d");
                    const minReqHover = minStayData[checkInStr];
                    const maxAllHover = maxStayData[checkInStr];
                    const $constraintSpan = $tooltip.find('.mhbo-tooltip-constraint');
                    
                    if (minReqHover && nights < minReqHover) {
                        $constraintSpan
                            .text((mhbo_calendar.i18n.tooltip_min_hint || '⚠ Min stay: %d nights').replace('%d', minReqHover))
                            .show();
                        $tooltip.addClass('mhbo-tooltip-warn');
                    } else if (maxAllHover && nights > maxAllHover) {
                        $constraintSpan
                            .text((mhbo_calendar.i18n.tooltip_max_hint || '⚠ Max stay: %d nights').replace('%d', maxAllHover))
                            .show();
                        $tooltip.addClass('mhbo-tooltip-warn');
                    } else {
                        // Meets constraint OR no constraint: hide the span
                        $constraintSpan.hide();
                        $tooltip.removeClass('mhbo-tooltip-warn');
                    }
                    /* BUILD_PRO_END */

                    // Use clientX/Y for position:fixed elements to avoid scroll offsets
                    $tooltip.css({
                        left: e.clientX,
                        top: e.clientY
                    }).addClass('mhbo-visible');
                });

                $wrapper.on('mouseleave pointerleave', '.mhbo-calendar-inline', function () {
                    $tooltip.removeClass('mhbo-visible mhbo-tooltip-warn');
                    $tooltip.find('.mhbo-tooltip-constraint').hide();
                    $wrapper.find('.mhbo-range-hover').removeClass('mhbo-range-hover');
                });
            }

            function updateBookingForm(dates) {
                const start = dates[0];
                const end = dates[1];
                const startStr = picker.formatDate(start, "Y-m-d");
                const endStr = picker.formatDate(end, "Y-m-d");

                $wrapper.find('.mhbo-cal-check-in').val(startStr);
                $wrapper.find('.mhbo-cal-check-out').val(endStr);
                $wrapper.find('.mhbo-display-check-in').text(startStr);
                $wrapper.find('.mhbo-display-check-out').text(endStr);

                // CROSS-SYNC: If we are on the booking form page already, find the main form inputs too
                const $mainForm = $('#mhbo-booking-form');
                if ($mainForm.length) {
                    $mainForm.find('input[name="check_in"]').val(startStr);
                    $mainForm.find('input[name="check_out"]').val(endStr);
                    // Trigger change to update prices if mhbo-booking-form.js is active
                    $mainForm.find('input[name="check_in"]').trigger('change');
                }

                // Update summary box labels if they have translation tags
                if ($selectionBox.find('.mhbo-selection-header h3').length) {
                    $selectionBox.find('.mhbo-selection-header h3').text(mhbo_calendar.i18n.your_selection || 'Your Selection');
                }
                const $dateLabels = $selectionBox.find('.mhbo-selection-dates .mhbo-label');
                if ($dateLabels.length >= 2) {
                    $($dateLabels[0]).text(mhbo_calendar.i18n.check_in || 'Check-in');
                    $($dateLabels[1]).text(mhbo_calendar.i18n.check_out || 'Check-out');
                }

                // Update button text if translation is available
                const $submitBtn = $wrapper.find('.mhbo-booking-btn-submit');
                if ($submitBtn.length && mhbo_calendar.i18n.continue_booking) {
                    $submitBtn.text(mhbo_calendar.i18n.continue_booking);
                }

                // Update pricing summary only for room-specific calendars
                if (showPrice && typeof calculateTotalPrice === 'function') {
                    $selectionBox.find('.mhbo-selection-price .mhbo-label').text(mhbo_calendar.i18n.total || 'Total');
                    calculateTotalPrice(start, end);
                }

                $guide.text(mhbo_calendar.i18n.dates_selected || 'Dates selected. Complete the form below.');
                $selectionBox.addClass('mhbo-visible').fadeIn();
            }

            function calculateTotalPrice(start, end) {
                let total = 0;
                let d = new Date(start);
                while (d < end) {
                    const dateStr = picker.formatDate(d, "Y-m-d");
                    if (priceData[dateStr]) {
                        total += parseFloat(priceData[dateStr].price);
                    }
                    d.setDate(d.getDate() + 1);
                }
                const formatted = formatCurrency(total);
                $wrapper.find('.mhbo-display-price').text(formatted);
                $wrapper.find('.mhbo-cal-total-price').val(total);
                
                // Also sync to main form total hidden field if it exists
                const $mainTotal = $('#mhbo-booking-form').find('input[name="total_price"]');
                if ($mainTotal.length) {
                    $mainTotal.val(total);
                }
            }

            function formatCurrency(amount) {
                const pos = mhbo_calendar.settings.currency_pos;
                const symbol = mhbo_calendar.settings.currency_symbol;
                const decimals = (typeof mhbo_calendar.settings.currency_decimals !== 'undefined') ? parseInt(mhbo_calendar.settings.currency_decimals) : 2;
                const formatted = amount.toFixed(decimals);
                return pos === 'before' ? (symbol + ' ' + formatted) : (formatted + ' ' + symbol);
            }

            // Expose error handler to wrapper
            $wrapper.data('mhbo-error-handler', showInlineError);

            initFlatpickr();
        });
    }

    // Capture the button click globally for better resilience
    // AND check for double-attachment
    if (!$(document).data('mhbo-click-attached')) {
        $(document).on('click', '.mhbo-booking-btn-submit', function (e) {
            e.preventDefault();
            const $btn = $(this);
            const $wrapper = $btn.closest('.mhbo-calendar-container');
            
            // Safety check: if we somehow clicked a button without a wrapper, abort
            if (!$wrapper.length) return;

            const $form = $wrapper.find('.mhbo-selection-form');
            const roomId = $wrapper.data('room-id');
            const action = $form.attr('action');
            const showError = $wrapper.data('mhbo-error-handler') || alert;
            
            // HARDENING: Try Flatpickr instance FIRST (most reliable)
            const picker = $wrapper.data('mhbo-picker');
            let checkIn, checkOut, totalPrice;

            if (picker && picker.selectedDates.length === 2) {
                checkIn = picker.formatDate(picker.selectedDates[0], "Y-m-d");
                checkOut = picker.formatDate(picker.selectedDates[1], "Y-m-d");
                // Get totalPrice from the internal hidden input as it's calculated in updateBookingForm
                totalPrice = $wrapper.find('.mhbo-cal-total-price').val() || '0';
            } else {
                // FALLBACK: Try wrapper hidden inputs
                checkIn = $wrapper.find('.mhbo-cal-check-in').val();
                checkOut = $wrapper.find('.mhbo-cal-check-out').val();
                totalPrice = $wrapper.find('.mhbo-cal-total-price').val() || '0';
            }

            if (!checkIn || !checkOut) {
                showError(mhbo_calendar.i18n.select_dates_error || 'Please select check-in and check-out dates.');
                return;
            }

            /* BUILD_PRO_START */
            // Final min/max stay guard — prevents bypassing the calendar validator by, e.g.,
            // typing dates directly or arriving via a deep-link with pre-filled values.
            const minStayFinal = $wrapper.data('mhbo-min-stay-data') || {};
            const maxStayFinal = $wrapper.data('mhbo-max-stay-data') || {};
            if (minStayFinal[checkIn] !== undefined || maxStayFinal[checkIn] !== undefined) {
                const ciParts = checkIn.split('-');
                const coParts = checkOut.split('-');
                const nightsFinal = Math.round(
                    (new Date(coParts[0], coParts[1] - 1, coParts[2]) -
                     new Date(ciParts[0], ciParts[1] - 1, ciParts[2])) / 86400000
                );
                const minReq = minStayFinal[checkIn];
                const maxAll = maxStayFinal[checkIn];
                if (minReq && nightsFinal < minReq) {
                    showError((mhbo_calendar.i18n.api_err_min_stay || 'Minimum stay is %d nights.').replace('%d', minReq).replace('%1$d', minReq));
                    return;
                }
                if (maxAll && nightsFinal > maxAll) {
                    showError((mhbo_calendar.i18n.api_err_max_stay || 'Maximum stay is %d nights.').replace('%d', maxAll).replace('%1$d', maxAll));
                    return;
                }
            }
            /* BUILD_PRO_END */

            // Modal mode: dispatch event instead of submitting the form
            const modalMode = $wrapper.data('modal-mode') === '1' || $wrapper.data('modal-mode') === 1;
            if (modalMode && typeof window.MhboModal !== 'undefined') {
                document.dispatchEvent(new CustomEvent('mhboBookNow', {
                    detail: {
                        room_id:     roomId,
                        check_in:    checkIn,
                        check_out:   checkOut,
                        total_price: totalPrice
                    }
                }));
                return;
            }

            // Sync values to the form's hidden inputs before submitting
            if ($form.length) {
                $form.find('input[name="check_in"]').val(checkIn);
                $form.find('input[name="check_out"]').val(checkOut);
                $form.find('input[name="total_price"]').val(totalPrice);

                // Native form submission will automatically include type_id and guests
                $form[0].submit();
            } else {
                // Fallback for cases without a formal form wrapper
                let finalUrl;
                try {
                    const base = window.location.origin;
                    const url = (action && action !== '#' && action !== '') ? new URL(action, base) : new URL(window.location.href);

                    url.searchParams.set('room_id', roomId);
                    url.searchParams.set('check_in', checkIn);
                    url.searchParams.set('check_out', checkOut);
                    url.searchParams.set('total_price', totalPrice);
                    url.searchParams.set('mhbo_auto_book', '1');
                    
                    var autoNonce = (typeof mhbo_calendar !== 'undefined' && mhbo_calendar.auto_nonce) || '';
                    if (autoNonce) {
                        url.searchParams.set('mhbo_nonce', autoNonce);
                    }

                    finalUrl = url.toString();
                } catch (err) {
                    debugLog('Redirect error:', err);
                    const sep = (action && action.indexOf('?') !== -1) ? '&' : '?';
                    const baseUrl = (action && action !== '#' && action !== '') ? action : window.location.href.split('?')[0];
                    finalUrl = baseUrl + sep + 'room_id=' + roomId + '&check_in=' + checkIn + '&check_out=' + checkOut + '&total_price=' + totalPrice + '&mhbo_auto_book=1' + (autoNonce ? '&mhbo_nonce=' + encodeURIComponent(autoNonce) : '');
                }

                window.location.href = finalUrl;
            }
        });
        $(document).data('mhbo-click-attached', true);
    }

    initAllCalendars();
});
