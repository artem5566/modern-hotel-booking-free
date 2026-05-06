/**
 * Modern Hotel Booking — Admin JavaScript
 *
 * Provides admin UI enhancements: confirm dialogs for status changes,
 * copy-to-clipboard for iCal URLs, and tabbed interface support.
 *
 * @package MHBO
 * @since   2.0.1
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        initConfirmDialogs();
        initCopyButtons();
        initTabs();
        initBulkActions();
    });

    /**
     * Confirm dialogs for destructive actions.
     */
    function initConfirmDialogs() {
        // Booking status changes (confirm/cancel)
        document.querySelectorAll('.mhbo-confirm-action').forEach(function (el) {
            el.addEventListener('click', function (e) {
                var message = el.getAttribute('data-confirm') || 'Are you sure?';
                if (!confirm(message)) {
                    e.preventDefault();
                }
            });
        });

        // Delete confirmations
        document.querySelectorAll('.mhbo-delete-action').forEach(function (el) {
            el.addEventListener('click', function (e) {
                if (!confirm('Are you sure you want to delete this item? This action cannot be undone.')) {
                    e.preventDefault();
                }
            });
        });
    }

    /**
     * Copy-to-clipboard for iCal URLs and API keys.
     */
    function initCopyButtons() {
        document.querySelectorAll('.mhbo-copy-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var targetId = btn.getAttribute('data-copy-target');
                var target = document.getElementById(targetId);

                if (!target) return;

                var text = target.value || target.textContent;

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(function () {
                        showCopyFeedback(btn, true);
                    }).catch(function () {
                        fallbackCopy(target);
                        showCopyFeedback(btn, true);
                    });
                } else {
                    fallbackCopy(target);
                    showCopyFeedback(btn, true);
                }
            });
        });
    }

    /**
     * Fallback copy method for older browsers.
     */
    function fallbackCopy(element) {
        if (element.select) {
            element.select();
            element.setSelectionRange(0, 99999);
        }
        try {
            document.execCommand('copy');
        } catch (err) {
            // Silently fail
        }
    }

    /**
     * Show visual feedback after copy action.
     */
    function showCopyFeedback(btn, success) {
        var originalText = btn.textContent;
        btn.textContent = success ? '✓ Copied!' : '✗ Failed';
        btn.disabled = true;

        setTimeout(function () {
            btn.textContent = originalText;
            btn.disabled = false;
        }, 2000);
    }

    /**
     * Tabbed interface for Settings page.
     */
    function initTabs() {
        var tabNav = document.querySelectorAll('.mhbo-tab-nav a, .mhbo-tab-nav button');
        if (tabNav.length === 0) return;

        tabNav.forEach(function (tab) {
            tab.addEventListener('click', function (e) {
                e.preventDefault();

                var targetId = tab.getAttribute('data-tab') || tab.getAttribute('href');
                if (targetId && targetId.startsWith('#')) {
                    targetId = targetId.substring(1);
                }

                // Remove active states
                tabNav.forEach(function (t) {
                    t.classList.remove('mhbo-tab-active');
                });
                document.querySelectorAll('.mhbo-tab-content').forEach(function (panel) {
                    panel.style.display = 'none';
                });

                // Activate clicked tab
                tab.classList.add('mhbo-tab-active');
                var targetPanel = document.getElementById(targetId);
                if (targetPanel) {
                    targetPanel.style.display = 'block';
                }
            });
        });
    }

    /**
     * Bulk action select-all checkboxes.
     */
    function initBulkActions() {
        var selectAll = document.getElementById('mhbo-select-all');
        if (!selectAll) return;

        selectAll.addEventListener('change', function () {
            var checkboxes = document.querySelectorAll('.mhbo-row-checkbox');
            checkboxes.forEach(function (cb) {
                cb.checked = selectAll.checked;
            });
        });
    }
})();
