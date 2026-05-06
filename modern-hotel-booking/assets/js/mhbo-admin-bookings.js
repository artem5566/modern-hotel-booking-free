/**
 * Modern Hotel Booking - Admin Bookings
 * 
 * Handles admin booking management interactions including:
 * - Price calculation for add/edit booking forms
 * - Dynamic child ages inputs
 * - Calendar integration
 * - Extras management
 * 
 * @package MHBO
 * @since 2.0.1
 */

(function ($) {
    'use strict';

    // Debug logger - only logs when debug mode is enabled
    const debugLog = (function () {
        const isDebug = (window.mhboAdminBookingsConfig && window.mhboAdminBookingsConfig.debug) ||
            (typeof localStorage !== 'undefined' && localStorage.getItem('mhbo_debug'));
        return isDebug ? console.error.bind(console, '[MHBO Admin]') : function () { };
    })();

    // Configuration is injected via wp_add_inline_script()
    const config = window.mhboAdminBookingsConfig || {};

    /**
     * Price Calculation Setup
     */
    function setupCalculation(prefix) {
        const roomSelect = document.getElementById(prefix + '_room_id');
        const checkInInput = document.getElementById(prefix + '_check_in');
        const checkOutInput = document.getElementById(prefix + '_check_out');
        const totalPriceInput = document.getElementById(prefix + '_total_price');
        const discountInput = document.getElementById(prefix + '_discount_amount');
        const depositInput = document.getElementById(prefix + '_deposit_amount');
        const depositReceivedInput = document.getElementById(prefix + '_deposit_received');
        const paymentReceivedInput = document.getElementById(prefix + '_payment_received');
        const paymentAmountInput = document.getElementById(prefix + '_payment_amount');
        const outstandingInput = document.getElementById(prefix + '_amount_outstanding');

        if (!totalPriceInput) return; // Not on Add or Edit page for this prefix

        const form = totalPriceInput.closest('form');
        let calculationTimeout = null;

        // List for changes in child ages (dynamically added inputs)
        const childAgesContainers = ['mhbo_add_child_ages_container', 'mhbo_edit_child_ages_container'];
        childAgesContainers.forEach(id => {
            const container = document.getElementById(id);
            if (container) {
                container.addEventListener('change', function (e) {
                    if (e.target && e.target.name === 'child_ages[]') {
                        updatePrices();
                    }
                });
                // Also listen for input event for more real-time feedback
                container.addEventListener('input', function (e) {
                    if (e.target && e.target.name === 'child_ages[]') {
                        // Debounce price update for typing
                        clearTimeout(window.mhboPriceTimer);
                        window.mhboPriceTimer = setTimeout(updatePrices, 300);
                    }
                });
            }
        });

        /**
         * Helper to perform the calculation
         */
        function updatePrices() {
            if (calculationTimeout) clearTimeout(calculationTimeout);

            // Debounce to avoid too many requests while typing
            calculationTimeout = setTimeout(function () {
                const roomId = roomSelect ? roomSelect.value : '';
                const checkIn = checkInInput ? checkInInput.value : '';
                const checkOut = checkOutInput ? checkOutInput.value : '';
                const guestsInput = document.getElementById(prefix + '_guests');
                const guests = guestsInput ? (parseInt(guestsInput.value) || 1) : 1;
                const childrenInput = document.getElementById(prefix + '_children');
                const children = childrenInput ? (parseInt(childrenInput.value) || 0) : 0;

                const childrenAges = [];
                if (form) {
                    form.querySelectorAll('input[name="child_ages[]"]').forEach(function (inp) {
                        childrenAges.push(parseInt(inp.value) || 0);
                    });
                }

                if (!roomId || !checkIn || !checkOut) {
                    totalPriceInput.value = '';
                    if (outstandingInput) outstandingInput.value = '';
                    return;
                }

                const start = new Date(checkIn + 'T00:00:00');
                const end = new Date(checkOut + 'T00:00:00');

                if (isNaN(start.getTime()) || isNaN(end.getTime()) || end <= start) {
                    totalPriceInput.value = '';
                    if (outstandingInput) outstandingInput.value = '';
                    return;
                }

                // Gather extras
                const extras = {};
                if (form) {
                    form.querySelectorAll('.mhbo-extra-input').forEach(function (input) {
                        const qty = input.type === 'checkbox' ? (input.checked ? 1 : 0) : (parseInt(input.value) || 0);
                        if (qty > 0) {
                            extras[input.dataset.extraId] = qty;
                        }
                    });
                }

                // Visual feedback
                totalPriceInput.style.opacity = '0.5';

                fetch('/wp-json/mhbo/v1/recalculate-price', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': config.nonce || ''
                    },
                    body: JSON.stringify({
                        room_id: roomId,
                        check_in: checkIn,
                        check_out: checkOut,
                        guests: guests,
                        children: children,
                        children_ages: childrenAges,
                        extras: extras
                    })
                })
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        totalPriceInput.style.opacity = '1';
                        if (data.success) {
                            const discount = parseFloat(discountInput ? discountInput.value : 0) || 0;
                            const total = data.total - discount;
                            totalPriceInput.value = total.toFixed(2);

                            if (outstandingInput && depositInput) {
                                if (paymentReceivedInput && paymentReceivedInput.checked) {
                                    // Full payment received - everything is paid
                                    outstandingInput.value = '0.00';
                                } else {
                                    const deposit = parseFloat(depositInput.value) || 0;
                                    const depositReceived = depositReceivedInput ? depositReceivedInput.checked : false;
                                    const paymentAmount = parseFloat(paymentAmountInput ? paymentAmountInput.value : 0) || 0;
                                    debugLog('Outstanding calculation for', prefix, { paymentReceived: paymentReceivedInput?.checked, deposit: deposit, depositReceived: depositReceived, paymentAmount: paymentAmount, total: total });
                                    // Start with total
                                    let outstanding = total;
                                    // Only subtract deposit if Deposit Received checkbox is checked
                                    if (depositReceived) {
                                        outstanding = outstanding - deposit;
                                    }
                                    // Subtract manually entered payment amount (partial payment)
                                    outstanding = outstanding - paymentAmount;
                                    outstandingInput.value = Math.max(0, outstanding).toFixed(2);
                                }
                            } else {
                                debugLog('Missing required fields for outstanding calculation:', { outstandingInput: !!outstandingInput, depositInput: !!depositInput, prefix: prefix });
                            }
                        }
                    })
                    .catch(function (err) {
                        totalPriceInput.style.opacity = '1';
                        debugLog('Calculation Error:', err);
                    });
            }, 300);
        }

        // Attach Listeners
        const guestsInput = document.getElementById(prefix + '_guests');
        const childrenInput = document.getElementById(prefix + '_children');
        const listeners = [roomSelect, checkInInput, checkOutInput, guestsInput, childrenInput, discountInput, depositInput, depositReceivedInput, paymentReceivedInput, paymentAmountInput];
        debugLog('Setting up listeners for', prefix, { depositInput: !!depositInput, depositReceivedInput: !!depositReceivedInput, paymentAmountInput: !!paymentAmountInput, paymentReceivedInput: !!paymentReceivedInput, outstandingInput: !!outstandingInput });
        listeners.forEach(function (el) {
            if (el) {
                el.addEventListener('change', updatePrices);
                if (el.tagName === 'INPUT') el.addEventListener('input', updatePrices);
            }
        });

        if (form) {
            form.querySelectorAll('.mhbo-extra-input').forEach(function (el) {
                el.addEventListener('change', updatePrices);
                el.addEventListener('input', updatePrices);
            });
        }

        // Initial run
        updatePrices();
    }

    /**
     * Dynamic Child Ages Setup
     */
    function setupChildAges(prefix) {
        const childrenInput = document.getElementById(prefix + '_children');
        const agesRow = document.getElementById(prefix + '_child_ages_row');
        const agesContainer = document.getElementById(prefix + '_child_ages_container');

        if (!childrenInput || !agesRow || !agesContainer) return;

        childrenInput.addEventListener('change', function () {
            const count = parseInt(this.value) || 0;
            if (count > 0) {
                agesRow.style.display = '';
                // Preserve existing values
                const existing = agesContainer.querySelectorAll('input[name="child_ages[]"]');
                const vals = [];
                existing.forEach(function (inp) { vals.push(inp.value); });
                agesContainer.innerHTML = '';
                for (let i = 0; i < count; i++) {
                    const lbl = document.createElement('label');
                    lbl.style.display = 'inline-block';
                    lbl.style.marginRight = '10px';
                    lbl.style.marginBottom = '5px';
                    lbl.textContent = 'Child ' + (i + 1) + ': ';
                    const inp = document.createElement('input');
                    inp.type = 'number';
                    inp.name = 'child_ages[]';
                    inp.value = vals[i] || 0;
                    inp.min = 0;
                    inp.max = 17;
                    inp.style.width = '60px';
                    lbl.appendChild(inp);
                    agesContainer.appendChild(lbl);
                }
            } else {
                agesRow.style.display = 'none';
                agesContainer.innerHTML = '';
            }
        });
    }

    /**
     * Extras Management
     */
    function initExtrasManagement() {
        const $addBtn = $('#mhbo-add-extra');
        const $extrasList = $('#mhbo-extras-list');
        const $template = $('#tmpl-mhbo-extra');

        if (!$addBtn.length || !$template.length) return;

        let extraCount = window.mhboExtrasCount || config.extrasCount || 0;
        const tmpl = $template.html();

        $addBtn.on('click', function () {
            let html = tmpl.replace(/{{index}}/g, extraCount++)
                .replace(/{{id}}/g, '')
                .replace(/{{name}}/g, '')
                .replace(/{{price}}/g, '')
                .replace(/{{description}}/g, '')
                .replace(/{{selected_fixed}}/g, '')
                .replace(/{{selected_pp}}/g, '')
                .replace(/{{selected_pn}}/g, '')
                .replace(/{{selected_pppn}}/g, '')
                .replace(/{{selected_checkbox}}/g, '')
                .replace(/{{selected_quantity}}/g, '');
            $extrasList.append(html);
        });

        $(document).on('click', '.mhbo-remove-extra', function () {
            $(this).closest('.mhbo-extra-item').remove();
        });
    }

    // Initialize on DOM ready
    document.addEventListener('DOMContentLoaded', function () {
        // Run Price Setup for both Add and Edit forms
        setupCalculation('mhbo_add');
        setupCalculation('mhbo_edit');

        // Run Child Ages Setup
        setupChildAges('mhbo_add');
        setupChildAges('mhbo_edit');
    });

    // Initialize jQuery-dependent features
    $(document).ready(function () {
        initExtrasManagement();

        // Mark Balance Collected (Pro)
        $(document).on('click', '.mhbo-mark-collected', function (e) {
            e.preventDefault();
            const $btn = $(this);
            const id = $btn.data('id');
            const nonce = $btn.data('nonce');

            if (!confirm('Are you sure you want to mark this balance as collected?')) return;

            $btn.prop('disabled', true).text('Updating...');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'mhbo_mark_balance_collected',
                    booking_id: id,
                    _wpnonce: nonce
                },
                success: function (response) {
                    if (response.success) {
                        location.reload(); // Simplest way to update all UI bits
                    } else {
                        alert(response.data || 'Error updating balance.');
                        $btn.prop('disabled', false).text('Mark Balance as Collected');
                    }
                },
                error: function () {
                    alert('Server error. Please try again.');
                    $btn.prop('disabled', false).text('Mark Balance as Collected');
                }
            });
        });
    });

    /**
     * FullCalendar Initialization for Bookings Page
     */
    function initFullCalendar() {
        const calendarEl = document.getElementById('mhbo-calendar');
        if (!calendarEl || typeof FullCalendar === 'undefined') {
            return;
        }

        const config = window.mhboCalendarConfig || {};
        const events = config.events || [];

        // eslint-disable-next-line no-undef
        const calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,timeGridWeek,listWeek'
            },
            events: events,
            navLinks: true,
            nowIndicator: true,
            eventClick: function (info) {
                if (info.event.url) {
                    window.location.href = info.event.url;
                    info.jsEvent.preventDefault();
                }
            },
            height: 'auto',
            aspectRatio: 1.8
        });

        calendar.render();
    }

    // Initialize FullCalendar on DOM ready
    document.addEventListener('DOMContentLoaded', function () {
        initFullCalendar();
    });

})(jQuery);
