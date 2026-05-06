/**
 * MHBO Business Frontend Interactions
 *
 * Handles client-side actions like 'Copy to Clipboard' for business components.
 */

(function () {
    document.addEventListener('DOMContentLoaded', function () {
        initCopyButtons();
    });

    function initCopyButtons() {
        const copyButtons = document.querySelectorAll('.mhbo-copy-btn');

        copyButtons.forEach(button => {
            button.addEventListener('click', function (e) {
                e.preventDefault();
                const textToCopy = this.getAttribute('data-copy');
                if (!textToCopy) return;

                copyToClipboard(textToCopy);

                // Visual Feedback
                const originalHTML = this.innerHTML;
                this.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>';
                this.classList.add('mhbo-copied');

                setTimeout(() => {
                    this.innerHTML = originalHTML;
                    this.classList.remove('mhbo-copied');
                }, 2000);
            });
        });
    }

    /**
     * Copy text to clipboard using the modern API or fallback.
     */
    function copyToClipboard(text) {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text);
        } else {
            // Fallback for non-HTTPS or older browsers
            let textArea = document.createElement("textarea");
            textArea.value = text;
            textArea.style.position = "fixed";
            textArea.style.left = "-9999px";
            textArea.style.top = "0";
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();
            try {
                document.execCommand('copy');
            } catch (err) {
                console.error('Fallback copy failed', err);
            }
            document.body.removeChild(textArea);
        }
    }
})();
