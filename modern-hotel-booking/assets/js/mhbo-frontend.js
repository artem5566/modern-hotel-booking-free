(function () {
    document.addEventListener('DOMContentLoaded', function () {
        // Initialize all booking forms (Shortcodes and Widgets)
        initBookingForms();
    });

    function initBookingForms() {
        // Handle both Shortcode and Widget Forms
        const rangePickers = document.querySelectorAll('.mhbo-range-datepicker');
        rangePickers.forEach(pickerInput => {
            const form = pickerInput.closest('form');
            if (!form) return;

            const checkInInput = form.querySelector('[name="check_in"]');
            const checkOutInput = form.querySelector('[name="check_out"]');

            if (checkInInput && checkOutInput) {
                initFlatpickrRange(pickerInput, checkInInput, checkOutInput);
            }
        });
    }

    function initFlatpickrRange(pickerInput, checkInInput, checkOutInput) {
        const prefix = (typeof mhbo_vars !== 'undefined' && mhbo_vars.storage_prefix) ? mhbo_vars.storage_prefix : 'mhbo_';

        // Restore from localStorage if empty
        if (!checkInInput.value) {
            const savedIn = localStorage.getItem(prefix + 'check_in');
            const savedOut = localStorage.getItem(prefix + 'check_out');
            if (savedIn && savedOut) {
                checkInInput.value = savedIn;
                checkOutInput.value = savedOut;
                const toLabel = (typeof mhbo_vars !== 'undefined' && mhbo_vars.to) ? mhbo_vars.to : 'to';
                pickerInput.value = savedIn + ' ' + toLabel + ' ' + savedOut;
            }
        }

        // Get room_id from form if available
        const form = pickerInput.closest('form');
        const roomIdInput = form ? form.querySelector('[name="mhbo_room_id"]') : null;
        const roomId = roomIdInput ? roomIdInput.value : null;

        // Initialize flatpickr with disabled dates
        if (roomId && typeof mhbo_vars !== 'undefined' && mhbo_vars.rest_url) {
            // Show loading state
            pickerInput.placeholder = (typeof mhbo_vars !== 'undefined' && mhbo_vars.loading) ? mhbo_vars.loading : 'Loading availability...';
            pickerInput.disabled = true;

            fetch(mhbo_vars.rest_url + '/calendar-data?room_id=' + encodeURIComponent(roomId))
                .then(function (response) {
                    return response.json();
                })
                .then(function (data) {
                    const disabledDates = extractDisabledDates(data);
                    initFlatpickrWithDisabled(pickerInput, checkInInput, checkOutInput, prefix, disabledDates);
                })
                .catch(function () {
                    // On error, initialize without disabled dates (server-side validation will catch issues)
                    initFlatpickrWithDisabled(pickerInput, checkInInput, checkOutInput, prefix, []);
                })
                .finally(function () {
                    pickerInput.disabled = false;
                    pickerInput.placeholder = '';
                });
        } else {
            // No room_id available, initialize without disabled dates
            initFlatpickrWithDisabled(pickerInput, checkInInput, checkOutInput, prefix, []);
        }
    }

    /**
     * Extract disabled (booked or unbookable) dates from calendar data.
     * Unbookable dates are those with 0 or no price set.
     *
     * @param {Array} data Calendar data from REST API.
     * @return {Array} Array of date strings to disable.
     */
    function extractDisabledDates(data) {
        if (!Array.isArray(data)) {
            return [];
        }
        const disabled = [];
        data.forEach(function (item) {
            // Disable both booked dates and unbookable dates (no price)
            if (item.status === 'booked' || item.status === 'unbookable') {
                disabled.push(item.date);
            }
        });
        return disabled;
    }

    /**
     * Initialize flatpickr with disabled dates.
     *
     * @param {HTMLElement} pickerInput The input element.
     * @param {HTMLElement} checkInInput Hidden check-in input.
     * @param {HTMLElement} checkOutInput Hidden check-out input.
     * @param {string} prefix LocalStorage prefix.
     * @param {Array} disabledDates Array of disabled date strings.
     */
    function initFlatpickrWithDisabled(pickerInput, checkInInput, checkOutInput, prefix, disabledDates) {
        if (typeof flatpickr === 'undefined') {
            console.error('Flatpickr library not loaded');
            // Retry after a short delay
            setTimeout(function () {
                initFlatpickrWithDisabled(pickerInput, checkInInput, checkOutInput, prefix, disabledDates);
            }, 100);
            return;
        }

        let pendingCheckIn = null; // Track first date click to detect backwards selection

        flatpickr(pickerInput, {
            mode: "range",
            dateFormat: "Y-m-d",
            minDate: "today",
            disable: disabledDates,
            disableMobile: true,
            onChange: function (selectedDates, dateStr, instance) {
                // Backwards-selection guard: if the user clicks a date before their
                // selected check-in, flatpickr reorders the pair. Detect this and
                // restart selection from the earlier date as a fresh check-in.
                if (selectedDates.length === 2 && pendingCheckIn) {
                    if (selectedDates[0].getTime() !== pendingCheckIn.getTime()) {
                        pendingCheckIn = selectedDates[0];
                        instance.setDate([selectedDates[0]], true); // re-fires onChange with length=1
                        return;
                    }
                }

                // dynamically allow the next booked date to be a checkout date
                if (selectedDates.length === 1) {
                    pendingCheckIn = selectedDates[0];
                    const checkIn = selectedDates[0];
                    let firstBookedAfter = null;
                    let minDiff = Infinity;

                    for (let i = 0; i < disabledDates.length; i++) {
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

                    let newDisabled = [...disabledDates];
                    if (firstBookedAfter) {
                        // For 2026 BP: Always un-disable firstBookedAfter.
                        // The REST API implicitly shifts firstBookedAfter back by 1 day ("dead day") when turnover is prevented.
                        newDisabled = newDisabled.filter(d => d !== firstBookedAfter);
                    }
                    instance.set('disable', newDisabled);
                }
                else if (selectedDates.length === 2) {
                    // If both dates are the same, auto-advance checkout to the next available day (min 1 night)
                    if (selectedDates[0].getTime() === selectedDates[1].getTime()) {
                        var nextDay = new Date(selectedDates[0]);
                        // Find the next non-disabled date for checkout
                        for (var i = 0; i < 30; i++) {
                            nextDay.setDate(nextDay.getDate() + 1);
                            var checkStr = instance.formatDate(nextDay, "Y-m-d");
                            if (disabledDates.indexOf(checkStr) === -1) {
                                break;
                            }
                        }
                        // Silently update visual selection
                        instance.setDate([selectedDates[0], nextDay], false);
                        selectedDates = [selectedDates[0], nextDay];

                        // Keep checkout date un-disabled if turnover is allowed
                        const preventTurnover = (typeof mhbo_vars !== 'undefined' && mhbo_vars.prevent_turnover) || false;
                        let checkOutStr = instance.formatDate(nextDay, "Y-m-d");
                        let newDisabledFinal = disabledDates;
                        if (!preventTurnover) {
                            newDisabledFinal = disabledDates.filter(d => d !== checkOutStr);
                        }
                        instance.set('disable', newDisabledFinal);
                    } else {
                        // Keep checkout date un-disabled if turnover is allowed
                        const preventTurnover = (typeof mhbo_vars !== 'undefined' && mhbo_vars.prevent_turnover) || false;
                        let checkOutStr2 = instance.formatDate(selectedDates[1], "Y-m-d");
                        let newDisabledFinal2 = disabledDates;
                        if (!preventTurnover) {
                            newDisabledFinal2 = disabledDates.filter(d => d !== checkOutStr2);
                        }
                        instance.set('disable', newDisabledFinal2);
                    }

                    const start = instance.formatDate(selectedDates[0], "Y-m-d");
                    const end = instance.formatDate(selectedDates[1], "Y-m-d");

                    checkInInput.value = start;
                    checkOutInput.value = end;

                    // Persistence
                    localStorage.setItem(prefix + 'check_in', start);
                    localStorage.setItem(prefix + 'check_out', end);
                } else {
                    pendingCheckIn = null;
                    instance.set('disable', disabledDates);
                }
            }
        });
    }
})();
