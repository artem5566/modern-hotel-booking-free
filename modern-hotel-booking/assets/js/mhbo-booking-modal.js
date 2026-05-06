/**
 * MHBO Booking Modal — Controller
 *
 * Public API (on window.MhboModal):
 *   MhboModal.open(title)       — open drawer with loading spinner
 *   MhboModal.close()           — close and destroy content
 *   MhboModal.setContent(html)  — replace body with HTML, re-fire init hooks
 *   MhboModal.setLoading(msg?)  — show spinner (optional message)
 *   MhboModal.setError(msg)     — show inline error panel
 *   MhboModal.isOpen()          — boolean
 *
 * Events dispatched on document:
 *   mhboModalOpened   — after open animation
 *   mhboModalClosed   — after close animation
 *   mhboModalContent  — after setContent() injects HTML (use to re-init payment JS)
 */
(function () {
    'use strict';

    let overlay = null;
    let drawer = null;
    let body = null;
    let titleEl = null;
    let _isOpen = false;
    let _previousFocus = null;

    /* ── Build DOM (once) ───────────────────────────────────── */
    function build() {
        if (overlay) return;

        overlay = document.createElement('div');
        overlay.className = 'mhbo-modal-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'mhbo-modal-title');

        drawer = document.createElement('div');
        drawer.className = 'mhbo-modal-drawer';

        const header = document.createElement('div');
        header.className = 'mhbo-modal-header';

        titleEl = document.createElement('h2');
        titleEl.className = 'mhbo-modal-title';
        titleEl.id = 'mhbo-modal-title';

        const closeBtn = document.createElement('button');
        closeBtn.className = 'mhbo-modal-close';
        closeBtn.setAttribute('aria-label', (window.mhboModalI18n && window.mhboModalI18n.close) || 'Close');
        closeBtn.innerHTML = '&#10005;';
        closeBtn.addEventListener('click', close);

        header.appendChild(titleEl);
        header.appendChild(closeBtn);

        body = document.createElement('div');
        body.className = 'mhbo-modal-body';

        drawer.appendChild(header);
        drawer.appendChild(body);
        overlay.appendChild(drawer);
        document.body.appendChild(overlay);

        /* Close on overlay click (outside drawer) */
        overlay.addEventListener('click', function (e) {
            if (!drawer.contains(e.target)) {
                close();
            }
        });

        /* Escape key */
        document.addEventListener('keydown', function (e) {
            if (_isOpen && e.key === 'Escape') {
                close();
            }
        });
    }

    /* ── Focus trap ─────────────────────────────────────────── */
    function getFocusable() {
        return Array.from(drawer.querySelectorAll(
            'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), ' +
            'textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        ));
    }

    function trapFocus(e) {
        if (!_isOpen || !drawer) return;
        const focusable = getFocusable();
        if (!focusable.length) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (e.key !== 'Tab') return;
        if (e.shiftKey) {
            if (document.activeElement === first) {
                e.preventDefault();
                last.focus();
            }
        } else {
            if (document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        }
    }

    /* ── Open ────────────────────────────────────────────────── */
    function open(title) {
        build();
        if (_isOpen) return;

        _previousFocus = document.activeElement;
        titleEl.textContent = title || (window.mhboModalI18n && window.mhboModalI18n.bookNow) || 'Book Now';

        setLoading();

        document.body.style.overflow = 'hidden';
        overlay.classList.add('is-open');
        _isOpen = true;

        document.addEventListener('keydown', trapFocus);

        /* Focus first focusable element after transition */
        overlay.addEventListener('transitionend', function onEnd() {
            overlay.removeEventListener('transitionend', onEnd);
            const focusable = getFocusable();
            if (focusable.length) focusable[0].focus();
            document.dispatchEvent(new CustomEvent('mhboModalOpened'));
        }, { once: true });
    }

    /* ── Close ───────────────────────────────────────────────── */
    function close() {
        if (!_isOpen) return;

        overlay.classList.remove('is-open');
        _isOpen = false;

        document.removeEventListener('keydown', trapFocus);
        document.body.style.overflow = '';

        overlay.addEventListener('transitionend', function onEnd() {
            overlay.removeEventListener('transitionend', onEnd);
            body.innerHTML = '';
            if (_previousFocus && _previousFocus.focus) {
                _previousFocus.focus();
            }
            document.dispatchEvent(new CustomEvent('mhboModalClosed'));
        }, { once: true });
    }

    /* ── setLoading ──────────────────────────────────────────── */
    function setLoading(msg) {
        if (!body) return;
        const label = msg || (window.mhboModalI18n && window.mhboModalI18n.loading) || 'Loading…';
        body.innerHTML =
            '<div class="mhbo-modal-loading">' +
            '<div class="mhbo-modal-spinner" aria-hidden="true"></div>' +
            '<span>' + escHtml(label) + '</span>' +
            '</div>';
    }

    /* ── setContent ──────────────────────────────────────────── */
    function setContent(html) {
        if (!body) return;
        body.innerHTML = html;
        body.scrollTop = 0;
        document.dispatchEvent(new CustomEvent('mhboModalContent', { detail: { body: body } }));
    }

    /* ── setError ────────────────────────────────────────────── */
    function setError(msg) {
        if (!body) return;
        body.innerHTML = '<div class="mhbo-modal-error">' + escHtml(msg) + '</div>';
    }

    /* ── isOpen ──────────────────────────────────────────────── */
    function isOpen() {
        return _isOpen;
    }

    /* ── Utility ─────────────────────────────────────────────── */
    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /* ── Expose public API ───────────────────────────────────── */
    window.MhboModal = { open, close, setContent, setLoading, setError, isOpen };

    /* ── Calendar trigger → fetch booking form ───────────────── */
    document.addEventListener('mhboBookNow', function (e) {
        const title = (window.mhboModalI18n && window.mhboModalI18n.bookNow) || 'Book Now';
        if (_isOpen) {
            /* Second calendar on same page — swap content without closing */
            if (titleEl) titleEl.textContent = title;
            setLoading();
        } else {
            open(title);
        }

        const d = e.detail || {};
        const restBase = (window.mhbo_vars && window.mhbo_vars.rest_url)
            || (window.mhbo_calendar && window.mhbo_calendar.rest_url && window.mhbo_calendar.rest_url.replace(/\/[^/]+$/, ''))
            || '/wp-json/mhbo/v1';

        const params = new URLSearchParams({
            room_id:        d.room_id        || '',
            check_in:       d.check_in       || '',
            check_out:      d.check_out      || '',
            guests:         d.guests         || 2,
            children:       d.children       || 0,
            total_price:    d.total_price    || '0',
            page_url:       window.location.href,
            customer_name:  d.customer_name  || '',
            customer_email: d.customer_email || '',
            customer_phone: d.customer_phone || '',
        });

        /* 2026 BP: Public endpoint (permission_callback => __return_true) — do NOT
           send X-WP-Nonce.  A stale nonce baked into a Cloudflare/Redis-cached page
           triggers WP core rest_cookie_check_errors() → 403 "Cookie check failed"
           even though no auth is needed.  Omitting the header lets the public
           permission_callback pass without cookie-auth interference. */
        _fetchModalEndpoint(
            restBase.replace(/\/$/, '') + '/modal/booking-form?' + params.toString(),
            restBase
        )
        .then(function (data) {
            if (!data || !data.html) {
                throw new Error('Empty response from server.');
            }
            setContent(data.html);
            document.dispatchEvent(new CustomEvent('mhboModalFormReady', {
                detail: { body: body, nonce: data.nonce, params: d }
            }));
        })
        .catch(function (err) {
            const label = (window.mhboModalI18n && window.mhboModalI18n.errorLoading)
                || 'Could not load booking form. Please try again.';
            setError(label + (err && err.message ? ' (' + err.message + ')' : ''));
        });
    });

    /* ── Shared fetch helper for public modal endpoints ─────────── */
    /* 2026 BP: No X-WP-Nonce header for public endpoints.  On 403 (e.g. a
       WAF rule or edge-case cookie-auth mismatch) retry once with cache-busted
       headers to recover without a page reload. */
    var _modalRetried = false;
    function _fetchModalEndpoint(url, restBase) {
        return fetch(url, {
            method:  'GET',
            headers: { 'Accept': 'application/json' },
        })
        .then(function (res) {
            if (!res.ok) {
                if (res.status === 403 && !_modalRetried) {
                    _modalRetried = true;
                    /* Attempt a nonce-refresh: even though these endpoints are
                       public, some WAF/security plugins may require a valid
                       session.  Refresh then retry with credentials. */
                    return fetch((restBase || '/wp-json/mhbo/v1').replace(/\/$/, '') + '/nonce', {
                        cache: 'no-store',
                        credentials: 'same-origin',
                    })
                    .then(function () { return fetch(url, {
                        method:  'GET',
                        headers: { 'Accept': 'application/json' },
                        credentials: 'same-origin',
                    }); })
                    .then(function (r2) {
                        _modalRetried = false;
                        if (!r2.ok) {
                            return r2.json().then(function (err) {
                                throw new Error((err && err.message) || r2.statusText);
                            });
                        }
                        return r2.json();
                    });
                }
                _modalRetried = false;
                return res.json().then(function (err) {
                    throw new Error((err && err.message) || res.statusText);
                });
            }
            _modalRetried = false;
            return res.json();
        });
    }

    /* ── Booking complete → fetch confirmation panel ─────────────── */
    document.addEventListener('mhboBookingComplete', function (e) {
        const d = e.detail || {};

        setLoading((window.mhboModalI18n && window.mhboModalI18n.confirming) || 'Confirming your booking');

        const restBase = (window.mhbo_vars && window.mhbo_vars.rest_url)
            || '/wp-json/mhbo/v1';

        const params = new URLSearchParams({
            booking_token: d.booking_token || '',
            status:        d.status        || 'confirmed',
        });

        /* 2026 BP: Public endpoint — no X-WP-Nonce needed. */
        _fetchModalEndpoint(
            restBase.replace(/\/$/, '') + '/modal/confirmation?' + params.toString(),
            restBase
        )
        .then(function (data) {
            if (!data || !data.html) {
                throw new Error('Empty confirmation response.');
            }
            setContent(data.html);
            document.dispatchEvent(new CustomEvent('mhboConfirmationShown'));
        })
        .catch(function (err) {
            const label = (window.mhboModalI18n && window.mhboModalI18n.errorConfirmation)
                || 'Your booking was received but we could not load the confirmation. Please check your email.';
            setError(label);
            console.error('[MHBO Modal] Confirmation fetch failed:', err);
        });
    });

    /* ── Stripe 3DS modal re-entry ──────────────────────────────── */
    /* After 3DS redirect, page reloads with ?mhbo_modal_booking=TOKEN.  */
    /* Re-open the drawer and show the confirmation panel.               */
    document.addEventListener('DOMContentLoaded', function () {
        var params = new URLSearchParams(window.location.search);
        var token  = params.get('mhbo_modal_booking');
        if (!token) return;
        var status = params.get('mhbo_modal_status') || 'confirmed';
        var clean  = new URL(window.location.href);
        clean.searchParams.delete('mhbo_modal_booking');
        clean.searchParams.delete('mhbo_modal_status');
        window.history.replaceState({}, '', clean.toString());
        var title = (window.mhboModalI18n && window.mhboModalI18n.bookNow) || 'Book Now';
        open(title);
        document.dispatchEvent(new CustomEvent('mhboBookingComplete', {
            detail: { booking_token: token, status: status }
        }));
    });

})();
