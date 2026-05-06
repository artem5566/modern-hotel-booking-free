/**
 * MHBO AI Concierge Chat Widget
 *
 * Vanilla JS — no jQuery, no React. Fully accessible.
 *
 * @package modern-hotel-booking
 * @since   2.4.0
 */

/* global mhboChat */

(function () {
    'use strict';

    if (typeof mhboChat === 'undefined') {
        return;
    }

    const cfg = mhboChat;
    const s = cfg.strings || {};

    // ─────────────────────────────────────────────────────────────────────────
    // ChatWidget
    // ─────────────────────────────────────────────────────────────────────────

    class ChatWidget {
        constructor(container) {
            this.container      = container;
            this.isOpen         = false;
            this.isSending      = false;
            this.sessionId      = sessionStorage.getItem('mhbo_session_id') || null;
            this.evtSource      = null;
            this.panel          = null;
            this.fab            = null;
            this.messagesEl     = null;
            this.inputEl        = null;
            this.sendBtn        = null;
            this._nonceRetried  = false; // Guard: only refresh stale nonce once per send.
        }

        // ── Initialise ────────────────────────────────────────────────────────

        init() {
            this._buildFab();
            this._buildPanel();
            this._attachEvents();
            this._injectIntoContainer();

            // Default: closed unless autoopen is explicitly true.
            if (this.container.dataset.autoopen === 'true') {
                this.open();
            } else {
                this.close();
            }
        }

        // ── FAB (floating action button) ──────────────────────────────────────

        _buildFab() {
            const btn = document.createElement('button');
            btn.className    = 'mhbo-chat-fab';
            btn.type         = 'button';
            btn.setAttribute('aria-label', s.openChat || 'Open chat with AI concierge');
            btn.setAttribute('aria-expanded', 'false');
            btn.innerHTML = `
                <svg aria-hidden="true" focusable="false" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                </svg>
                <span class="mhbo-chat-fab-badge" aria-hidden="true"></span>`;
            this.fab = btn;
        }

        // ── Panel ─────────────────────────────────────────────────────────────

        _buildPanel() {
            const panel = document.createElement('div');
            panel.className = 'mhbo-chat-panel';
            panel.setAttribute('role', 'dialog');
            panel.setAttribute('aria-modal', 'true');
            panel.setAttribute('aria-label', s.chatDialogLabel || 'Hotel AI Concierge Chat');
            panel.hidden = true;

            const hotelName  = cfg.settings?.hotelName || s.hotelName || 'Hotel Concierge';
            const personaName = cfg.settings?.personaName || s.personaName || 'AI Concierge';

            panel.innerHTML = `
                <div class="mhbo-chat-header">
                    <div class="mhbo-chat-avatar" aria-hidden="true">🤖</div>
                    <div class="mhbo-chat-header-text">
                        <strong>${this._esc(personaName)}</strong>
                        <span class="mhbo-chat-subtitle">${this._esc(hotelName)}</span>
                    </div>
                    <div class="mhbo-chat-header-actions">
                        <button type="button" class="mhbo-chat-tts-toggle" aria-label="${s.toggleVoice || 'Toggle voice'}" aria-pressed="false" title="${s.toggleVoice || 'Toggle voice output'}">
                            <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/></svg>
                        </button>
                        <button type="button" class="mhbo-chat-minimize" aria-label="${s.minimize || 'Minimize chat'}">
                            <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        </button>
                        <button type="button" class="mhbo-chat-close" aria-label="${s.close || 'Close chat'}">
                            <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    </div>
                </div>
                <div class="mhbo-chat-messages" role="log" aria-live="polite" aria-label="${s.messageHistory || 'Chat messages'}"></div>
                <div class="mhbo-chat-suggestions" role="list" aria-label="${s.suggestions || 'Quick replies'}"></div>
                <form class="mhbo-chat-input-row" novalidate>
                    <input type="text" class="mhbo-chat-input" placeholder="${s.inputPlaceholder || 'Ask me anything…'}" aria-label="${s.inputLabel || 'Chat message'}" maxlength="1000" autocomplete="off">
                    <button type="button" class="mhbo-chat-voice-btn" aria-label="${s.startVoice || 'Start voice input'}" title="${s.startVoice || 'Voice input'}">
                        <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
                    </button>
                    <button type="submit" class="mhbo-chat-send" aria-label="${s.send || 'Send message'}">
                        <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    </button>
                </form>`;

            this.panel      = panel;
            this.messagesEl = panel.querySelector('.mhbo-chat-messages');
            this.inputEl    = panel.querySelector('.mhbo-chat-input');
            this.sendBtn    = panel.querySelector('.mhbo-chat-send');
            this.voiceBtn   = panel.querySelector('.mhbo-chat-voice-btn');
            this.ttsToggle  = panel.querySelector('.mhbo-chat-tts-toggle');
        }

        _injectIntoContainer() {
            this.container.appendChild(this.fab);
            this.container.appendChild(this.panel);

            // Apply position from settings.
            const pos = cfg.settings?.position || this.container.dataset.position || 'bottom-right';
            this.container.classList.add('mhbo-chat-widget--' + pos.replace('-', '-'));
        }

        // ── Toggle Visibility ────────────────────────────────────────────────

        toggle() {
            if (this.isOpen) {
                this.close();
            } else {
                this.open();
            }
        }

        open() {
            if (this.isOpen) return;
            this.isOpen = true;
            this.panel.hidden = false;
            this.container.classList.add('mhbo-chat-widget--open');
            this.fab.setAttribute('aria-expanded', 'true');
            this.fab.classList.add('mhbo-chat-fab--active');

            // Focus input on open.
            if (this.inputEl) {
                setTimeout(() => this.inputEl.focus(), 300);
            }

            const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            if (!prefersReduced) {
                this.panel.classList.add('mhbo-chat-panel--opening');
                this.panel.addEventListener('animationend', () => {
                    this.panel.classList.remove('mhbo-chat-panel--opening');
                }, { once: true });
            }

            // Show welcome message on first open.
            if (!this.hasShownWelcome) {
                this.hasShownWelcome = true;
                const welcome = cfg.settings?.welcomeMessage || this.container.dataset.welcomeMessage || s.welcomeMessage;
                if (welcome) {
                    this.renderBubble('assistant', welcome);
                    this._addInitialSuggestions();
                }
            }
        }

        close() {
            this.isOpen = false;
            this.panel.hidden = true;
            this.container.classList.remove('mhbo-chat-widget--open');
            this.fab.setAttribute('aria-expanded', 'false');
            this.fab.classList.remove('mhbo-chat-fab--active');

            if (this.evtSource) {
                this.evtSource.close();
                this.evtSource = null;
            }
        }

        // ── Events ────────────────────────────────────────────────────────────

        _attachEvents() {
            // FAB toggle.
            this.fab.addEventListener('click', () => this.toggle());

            // Close.
            this.panel.querySelector('.mhbo-chat-close').addEventListener('click', () => this.close());

            // Form submit.
            this.panel.querySelector('.mhbo-chat-input-row').addEventListener('submit', (e) => {
                e.preventDefault();
                this.sendMessage();
            });

            // Keyboard: send on Enter (without shift).
            this.inputEl.addEventListener('keydown', (e) => {
                // Barge-in: Stop speech if user starts typing.
                if (window.MhboVoiceInput) {
                    window.MhboVoiceInput.stopSpeaking();
                }

                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    this.sendMessage();
                }
            });

            // Keyboard: Escape to close.
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && this.isOpen) {
                    this.close();
                    this.fab.focus();
                }
            });

            // TTS toggle.
            this.ttsToggle.addEventListener('click', () => {
                const isCurrentlyEnabled = this.ttsToggle.getAttribute('aria-pressed') === 'true';
                this._setTtsEnabled(!isCurrentlyEnabled);
            });

            // Voice input (delegated to VoiceInput if available).
            this.voiceBtn.addEventListener('click', () => {
                if (window.MhboVoiceInput) {
                    // Switch audio on as likely the guest wants audible responses when chatting to bot.
                    this._setTtsEnabled(true);

                    window.MhboVoiceInput.start((transcript) => {
                        this.inputEl.value = transcript;
                        this.inputEl.focus();
                        // Scroll to end of input text
                        this.inputEl.setSelectionRange(transcript.length, transcript.length);
                    });
                }
            });
        }

        /**
         * Enable or disable Text-to-Speech output.
         *
         * @param {boolean} enabled
         */
        _setTtsEnabled(enabled) {
            this.ttsEnabled = !!enabled;
            this.ttsToggle.setAttribute('aria-pressed', String(this.ttsEnabled));
        }

        // ── Messaging ─────────────────────────────────────────────────────────

        sendMessage() {
            const text = this.inputEl.value.trim();
            if (!text || this.isSending) return;

            this.inputEl.value = '';
            this.renderBubble('user', text);
            this.clearSuggestions();
            this.showTypingIndicator();
            this.isSending = true;
            this.sendBtn.disabled = true;

            this._postMessage(text)
                .then(async response => {
                    this.hideTypingIndicator();

                    // 1. Handle Streaming Response (SSE).
                    if (response instanceof ReadableStream) {
                        const reader = response.getReader();
                        const decoder = new TextDecoder();
                        let activeBubble = null;
                        let fullText = '';

                        try {
                            while (true) {
                                const { done, value } = await reader.read();
                                if (done) break;

                                const chunk = decoder.decode(value, { stream: true });
                                const lines = chunk.split('\n');

                                for (const line of lines) {
                                    if (!line.startsWith('data: ')) continue;
                                    const dataStr = line.replace('data: ', '').trim();
                                    if (dataStr === '[DONE]') break;

                                    try {
                                        const data = JSON.parse(dataStr);

                                        // Delta: Typing content.
                                        if (data.delta) {
                                            if (!activeBubble) {
                                                activeBubble = this.renderBubble('assistant', '', true);
                                            }
                                            fullText += data.delta;
                                            this._updateBubble(activeBubble, fullText);
                                        }

                                        // Thought: Progress status.
                                        if (data.type === 'thought') {
                                            this.showStatusNotification(data.content);
                                        }

                                        // Done: Final metadata.
                                        if (data.done) {
                                            this.hideStatusNotification();
                                            if (data.session_id) {
                                                this.sessionId = data.session_id;
                                                sessionStorage.setItem('mhbo_session_id', this.sessionId);
                                            }
                                            if (data.suggestions) {
                                                this.addSuggestions(data.suggestions);
                                            }
                                            if (data.tool_calls_made) {
                                                data.tool_calls_made.forEach(tc => {
                                                    if (tc.result && !tc.result.error) {
                                                        this._renderToolCard(tc.tool, tc.result);
                                                    }
                                                });
                                            }
                                        }
                                    } catch (e) {
                                        console.warn('Malformed stream chunk:', dataStr);
                                    }
                                }
                            }
                        } catch (err) {
                            this.renderBubble('error', err.message || s.errorMessage);
                        } finally {
                            this.isSending = false;
                            this.sendBtn.disabled = false;
                            this.inputEl.focus();
                        }
                        return;
                    }

                    // 2. Handle Standard JSON Response.
                    const data = response;

                    // Always update session ID unconditionally.
                    if (data && data.session_id) {
                        this.sessionId = data.session_id;
                        sessionStorage.setItem('mhbo_session_id', this.sessionId);
                    }

                    if (data && typeof data.response === 'string' && data.response.length > 0) {
                        this.receiveMessage(data.response);
                        if (this.ttsEnabled && window.MhboVoiceInput) {
                            window.MhboVoiceInput.speak(data.response);
                        }
                        if (data.suggestions && data.suggestions.length) {
                            this.addSuggestions(data.suggestions);
                        }
                        if (data.booking_intent && data.booking_intent.score >= 60) {
                            this._renderBookNowCta(data.booking_intent.score);
                        }
                        if (data.handoff && Object.keys(data.handoff).length) {
                            this._storeHandoff(data.handoff);
                            const resp = (data.response || '').toLowerCase();
                            if (/contact|call us|email us|whatsapp|speak with|reach us/.test(resp)) {
                                this._renderHandoffBar();
                            }
                        }
                    } else if (!data || (!data.tool_calls_made || !data.tool_calls_made.length)) {
                        const msg = data?.message || s.errorMessage || 'Sorry, something went wrong.';
                        this.renderBubble('error', msg);
                    }

                    // Render tool cards after the text response so the card is the last
                    // thing the guest sees — more prominent and easier to act on.
                    if (data && data.tool_calls_made && data.tool_calls_made.length) {
                        data.tool_calls_made.forEach(tc => {
                            if (tc.result && !tc.result.error) {
                                this._renderToolCard(tc.tool, tc.result);
                            }
                        });
                    }
                })
                .catch(err => {
                    this.hideTypingIndicator();
                    this.renderBubble('error', err?.message || s.errorMessage);
                })
                .finally(() => {
                    // Note: If streaming, finally is handled in the try/catch loop above.
                    if (!cfg.settings?.streamingEnabled) {
                        this.isSending = false;
                        this.sendBtn.disabled = false;
                        this.inputEl.focus();
                    }
                });
        }

        async _postMessage(text) {
            const body = new FormData();
            body.append('message', text);
            body.append('nonce', cfg.nonce);
            if (this.sessionId) {
                body.append('session_id', this.sessionId);
            }
            if (cfg.settings?.streamingEnabled) {
                body.append('stream', 'true');
            }
            
            const pageLang = document.documentElement.lang || cfg.settings?.pageLocale || '';
            if (pageLang) {
                body.append('lang', pageLang.replace('_', '-'));
            }

            const headers = { 'X-WP-Nonce': cfg.restNonce || '' };
            if (cfg.settings?.streamingEnabled) {
                headers['Accept'] = 'text/event-stream';
            }

            const res = await fetch(cfg.restUrl + '/mhbo/v1/chat', {
                method: 'POST',
                credentials: 'same-origin',
                headers: headers,
                body: body,
            });

            if (!res.ok) {
                // 403 = stale nonce (page cache, Cloudflare HTML cache, etc.).
                // Refresh both nonces from the server and retry exactly once.
                if (res.status === 403 && !this._nonceRetried) {
                    this._nonceRetried = true;
                    await this._refreshNonce();
                    return this._postMessage(text);
                }
                this._nonceRetried = false;
                const err = await res.json().catch(() => ({}));
                throw new Error(err?.message || ('HTTP ' + res.status));
            }
            this._nonceRetried = false;

            if (cfg.settings?.streamingEnabled && res.headers.get('Content-Type')?.includes('text/event-stream')) {
                return res.body; // Return the ReadableStream.
            }

            const json = await res.json();
            return json.data ?? json;
        }

        // Fetch fresh nonces from the server — called automatically when a 403 is received.
        // This recovers from stale nonces baked into cached pages (Cloudflare, WP Rocket, etc.)
        // without requiring the guest to reload the page.
        async _refreshNonce() {
            try {
                const r = await fetch(cfg.restUrl + '/mhbo/v1/nonce', {
                    credentials: 'same-origin',
                    cache: 'no-store',
                });
                if (!r.ok) return;
                const d = await r.json().catch(() => ({}));
                const data = d.data ?? d;
                if (data.nonce)      cfg.nonce      = data.nonce;
                if (data.rest_nonce) cfg.restNonce  = data.rest_nonce;
            } catch (_) {
                // Silently ignore — the subsequent request will surface its own error.
            }
        }

        receiveMessage(text) {
            this.renderBubble('assistant', text);
        }

        // ── Rendering ─────────────────────────────────────────────────────────

        renderBubble(role, text, deferred = false) {
            const wrap = document.createElement('div');
            wrap.className = 'mhbo-chat-bubble mhbo-chat-bubble--' + role;

            const inner = document.createElement('div');
            inner.className = 'mhbo-chat-bubble-inner';

            if (role === 'error') {
                inner.innerHTML = `<span class="mhbo-chat-error-icon" aria-hidden="true">⚠️</span> ` + this._esc(text);
                wrap.setAttribute('role', 'alert');
            } else {
                inner.innerHTML = deferred ? '' : this._renderMarkdownLite(text);
            }

            // Timestamp.
            const time = document.createElement('time');
            time.className = 'mhbo-chat-bubble-time';
            time.setAttribute('aria-hidden', 'true');
            time.textContent = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            inner.appendChild(time);

            wrap.appendChild(inner);
            this.messagesEl.appendChild(wrap);
            this.scrollToBottom();
            return wrap;
        }

        _updateBubble(wrap, text) {
            const inner = wrap.querySelector('.mhbo-chat-bubble-inner');
            const time = inner.querySelector('time');
            inner.innerHTML = this._renderMarkdownLite(text);
            if (time) inner.appendChild(time);
            this.scrollToBottom();
        }

        showStatusNotification(text) {
            let statusEl = this.container.querySelector('.mhbo-chat-status-bar');
            if (!statusEl) {
                statusEl = document.createElement('div');
                statusEl.className = 'mhbo-chat-status-bar';
                this.messagesEl.appendChild(statusEl);
            }
            statusEl.textContent = text;
            statusEl.classList.add('mhbo-chat-status-bar--active');
            this.scrollToBottom();
        }

        hideStatusNotification() {
            const statusEl = this.container.querySelector('.mhbo-chat-status-bar');
            if (statusEl) {
                statusEl.classList.remove('mhbo-chat-status-bar--active');
                setTimeout(() => statusEl.remove(), 500);
            }
        }

        _renderMarkdownLite(text) {
            // Escape HTML first.
            let html = this._esc(text);

            // 1. Process isolated URLs (on their own line) as Buttons for high-visibility CTAs (Pay Now, Resume).
            const lines = html.split('\n');
            const urlOnlyRegex = /^(https?:\/\/[^\s<]+[^<.,:;"')\]\s])$/;
            
            const processedLines = lines.map(line => {
                const trimmed = line.trim();
                if (urlOnlyRegex.test(trimmed)) {
                    return `<a href="${trimmed}" target="_blank" rel="noopener noreferrer" class="mhbo-chat-link-button">${trimmed}</a>`;
                }
                
                // 2. Standard Auto-link for embedded URLs
                const urlRegex = /(https?:\/\/[^\s<]+[^<.,:;"')\]\s])/g;
                return line.replace(urlRegex, '<a href="$1" target="_blank" rel="noopener noreferrer" class="mhbo-chat-link">$1</a>');
            });

            html = processedLines.join('\n');

            // Bold: **text**
            html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
            // Newlines to br.
            html = html.replace(/\n/g, '<br>');
            return html;
        }

        _esc(str) {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        // ── Suggestions ───────────────────────────────────────────────────────

        addSuggestions(items) {
            this.clearSuggestions();
            const sugEl = this.panel.querySelector('.mhbo-chat-suggestions');
            items.forEach(text => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'mhbo-chat-suggestion';
                btn.setAttribute('role', 'listitem');
                btn.textContent = text;
                btn.addEventListener('click', () => {
                    // Barge-in: Stop speech if suggestion clicked.
                    if (window.MhboVoiceInput) {
                        window.MhboVoiceInput.stopSpeaking();
                    }
                    this.inputEl.value = text;
                    this.sendMessage();
                });
                sugEl.appendChild(btn);
            });
        }

        clearSuggestions() {
            this.panel.querySelector('.mhbo-chat-suggestions').innerHTML = '';
        }

        _addInitialSuggestions() {
            const defaults = [
                s.suggCheckAvail  || 'Check availability',
                s.suggRoomTypes   || 'What room types do you have?',
                s.suggPolicies    || 'What are your policies?',
            ];
            this.addSuggestions(defaults);
        }

        // ── Typing indicator ──────────────────────────────────────────────────

        showTypingIndicator() {
            if (this.typingEl) return;
            const el = document.createElement('div');
            el.className = 'mhbo-chat-bubble mhbo-chat-bubble--assistant mhbo-chat-typing';
            el.setAttribute('aria-label', s.typing || 'AI is typing');
            el.setAttribute('aria-live', 'polite');
            el.innerHTML = '<div class="mhbo-chat-bubble-inner"><span class="mhbo-typing-dot"></span><span class="mhbo-typing-dot"></span><span class="mhbo-typing-dot"></span></div>';
            this.typingEl = el;
            this.messagesEl.appendChild(el);
            this.scrollToBottom();
        }

        hideTypingIndicator() {
            if (this.typingEl) {
                this.typingEl.remove();
                this.typingEl = null;
            }
        }

        // ── Scroll ────────────────────────────────────────────────────────────

        scrollToBottom() {
            this.messagesEl.scrollTop = this.messagesEl.scrollHeight;
        }

        // ── Einstein NBA: Book Now CTA ────────────────────────────────────────
        // Shown when booking_intent.score >= 60. Removed after one click or
        // when the next user message is sent.

        _renderBookNowCta(score) {
            // Only one CTA at a time.
            this.messagesEl.querySelectorAll('.mhbo-book-now-cta').forEach(el => el.remove());

            const bookingUrl = cfg.settings?.bookingUrl || cfg.bookingUrl || '';
            if (!bookingUrl) return;

            const wrap = document.createElement('div');
            wrap.className = 'mhbo-book-now-cta';
            wrap.setAttribute('role', 'complementary');
            wrap.setAttribute('aria-label', s.bookNowLabel || 'Book now');

            const label = score >= 80
                ? (s.ctaHighIntent  || '🔥 Ready to book? Secure your room now')
                : (s.ctaMedIntent   || '✅ Looks good! Book your stay today');

            const btn = document.createElement('a');
            btn.href      = bookingUrl;
            btn.className = 'mhbo-book-now-btn';
            btn.textContent = label;

            /* When the booking modal is active and the URL carries a room_id,
               intercept the click so the drawer opens instead of navigating. */
            let ctaRoomId = 0;
            if (typeof window.MhboModal !== 'undefined' && bookingUrl) {
                try {
                    const u = new URL(bookingUrl, window.location.href);
                    ctaRoomId = parseInt(u.searchParams.get('room_id') || '0', 10) || 0;
                } catch (_) {}
            }
            if (ctaRoomId > 0) {
                btn.addEventListener('click', function (ev) {
                    ev.preventDefault();
                    wrap.remove();
                    try {
                        const u = new URL(bookingUrl, window.location.href);
                        document.dispatchEvent(new CustomEvent('mhboBookNow', {
                            detail: {
                                room_id:   ctaRoomId,
                                check_in:  u.searchParams.get('check_in')  || '',
                                check_out: u.searchParams.get('check_out') || '',
                                guests:    parseInt(u.searchParams.get('guests') || '2', 10),
                            }
                        }));
                    } catch (_) {}
                });
            } else {
                btn.setAttribute('target', '_blank');
                btn.setAttribute('rel', 'noopener noreferrer');
            }

            // Dismiss link.
            const dismiss = document.createElement('button');
            dismiss.type = 'button';
            dismiss.className = 'mhbo-book-now-dismiss';
            dismiss.setAttribute('aria-label', s.dismiss || 'Dismiss');
            dismiss.textContent = '×';
            dismiss.addEventListener('click', () => wrap.remove());

            wrap.appendChild(btn);
            wrap.appendChild(dismiss);
            this.messagesEl.appendChild(wrap);
            this.scrollToBottom();
        }

        // ── Handoff: store contact options, show on escalation ────────────────

        _storeHandoff(handoff) {
            this._handoff = handoff;
        }

        /**
         * Render WhatsApp / email / phone escalation bar.
         * Called by the AI response renderer when it detects "contact us" language,
         * or can be triggered externally.
         */
        _renderHandoffBar() {
            if (!this._handoff || !Object.keys(this._handoff).length) return;
            if (this.messagesEl.querySelector('.mhbo-handoff-bar')) return;

            const bar = document.createElement('div');
            bar.className = 'mhbo-handoff-bar';

            const intro = document.createElement('p');
            intro.className = 'mhbo-handoff-intro';
            intro.textContent = s.handoffIntro || 'Need to speak with someone?';
            bar.appendChild(intro);

            const buttons = document.createElement('div');
            buttons.className = 'mhbo-handoff-buttons';

            if (this._handoff.whatsapp) {
                const wa = document.createElement('a');
                const waNum = this._handoff.whatsapp.replace(/\D/g, '');
                wa.href = `https://wa.me/${waNum}`;
                wa.className = 'mhbo-handoff-btn mhbo-handoff-btn--whatsapp';
                wa.target = '_blank';
                wa.rel = 'noopener noreferrer';
                wa.textContent = s.handoffWhatsapp || '💬 WhatsApp';
                buttons.appendChild(wa);
            }

            if (this._handoff.email) {
                const em = document.createElement('a');
                em.href = `mailto:${this._handoff.email}`;
                em.className = 'mhbo-handoff-btn mhbo-handoff-btn--email';
                em.textContent = s.handoffEmail || '✉️ Email us';
                buttons.appendChild(em);
            }

            if (this._handoff.phone) {
                const ph = document.createElement('a');
                ph.href = `tel:${this._handoff.phone}`;
                ph.className = 'mhbo-handoff-btn mhbo-handoff-btn--phone';
                ph.textContent = s.handoffPhone || '📞 Call us';
                buttons.appendChild(ph);
            }

            if (!buttons.children.length) return;
            bar.appendChild(buttons);
            this.messagesEl.appendChild(bar);
            this.scrollToBottom();
        }

        // ── Tool Result Rendering ─────────────────────────────────────────────

        _renderToolCard(toolName, result) {
            const wrap = document.createElement('div');
            wrap.className = 'mhbo-chat-bubble mhbo-chat-bubble--tool-result';

            const card = document.createElement('div');
            card.className = 'mhbo-tool-card';

            if (toolName === 'get_business_card') {
                card.innerHTML = this._buildBusinessCardHtml(result);
            } else if (toolName === 'create_booking_link') {
                card.innerHTML = this._buildBookingSummaryHtml(result);
            } else if (toolName === 'check_availability') {
                if (!result.rooms || !result.rooms.length) return;
                card.innerHTML = this._buildAvailabilityCardsHtml(result);
            } else {
                return;
            }

            wrap.appendChild(card);

            if (toolName === 'create_booking_link' && result.multi_room_remaining > 0) {
                const hint = document.createElement('div');
                hint.className = 'mhbo-chat-multi-room-hint';
                hint.style.fontSize = '12px';
                hint.style.color = 'var(--mhbo-chat-text-muted, #666)';
                hint.style.marginTop = '8px';
                hint.style.textAlign = 'center';
                hint.innerHTML = `<em>Complete this booking, then type <strong>"next room"</strong> to continue.</em>`;
                wrap.appendChild(hint);
            }

            this.messagesEl.appendChild(wrap);
            this._attachCardEvents(card);
            this.scrollToBottom();
        }

        _buildBusinessCardHtml(data) {
            let html = `<div class="mhbo-business-card">`;

            // Banking
            if (data.banking && data.banking.enabled && data.banking.bank_name) {
                html += `
                <div class="mhbo-biz-section">
                    <div class="mhbo-biz-section-title">🏦 ${s.bankingHeader || 'Bank Transfer'}</div>
                    <div class="mhbo-biz-row"><span>Bank</span><strong>${this._esc(data.banking.bank_name)}</strong></div>
                    ${data.banking.account_name ? `<div class="mhbo-biz-row"><span>Holder</span><strong>${this._esc(data.banking.account_name)}</strong></div>` : ''}
                    ${data.banking.iban ? `<div class="mhbo-biz-row"><span>IBAN</span><code class="mhbo-copyable">${this._esc(data.banking.iban)}</code><div class="mhbo-copy-hint">Copy</div></div>` : ''}
                    ${data.banking.swift_bic ? `<div class="mhbo-biz-row"><span>SWIFT</span><code class="mhbo-copyable">${this._esc(data.banking.swift_bic)}</code><div class="mhbo-copy-hint">Copy</div></div>` : ''}
                </div>`;
            }

            // Revolut
            if (data.revolut && data.revolut.enabled && data.revolut.revolut_tag) {
                html += `
                <div class="mhbo-biz-section">
                    <div class="mhbo-biz-section-title">💳 Revolut</div>
                    <div class="mhbo-biz-row"><span>Tag</span><code class="mhbo-copyable">@${this._esc(data.revolut.revolut_tag.replace('@',''))}</code><div class="mhbo-copy-hint">Copy</div></div>
                    ${data.revolut.qr_code_url ? `<div class="mhbo-biz-row"><span>QR Code</span><img src="${this._esc(data.revolut.qr_code_url)}" class="mhbo-qr-thumb" alt="QR Code"></div>` : ''}
                    ${data.revolut.revolut_link ? `<a href="${this._esc(data.revolut.revolut_link)}" target="_blank" class="mhbo-booking-link-btn" style="margin-top:8px">Pay with Revolut Link</a>` : ''}
                </div>`;
            }

            // WhatsApp
            if (data.whatsapp && data.whatsapp.enabled && data.whatsapp.url) {
                html += `
                <div class="mhbo-biz-section">
                    <div class="mhbo-biz-section-title">💬 WhatsApp</div>
                    <a href="${this._esc(data.whatsapp.url)}" target="_blank" class="mhbo-handoff-btn mhbo-handoff-btn--whatsapp" style="width:100%; justify-content:center">
                        ${s.chatOnWhatsapp || 'Chat on WhatsApp'}
                    </a>
                </div>`;
            }

            html += `</div>`;
            return html;
        }

        _buildBookingSummaryHtml(data) {
            const formattedDates = `${data.check_in} — ${data.check_out}`;
            const depositMsg = data.deposit && data.deposit.required
                ? `${s.depositLabel || 'Deposit required'}: ${data.deposit.label}`
                : s.noDeposit || 'No immediate deposit required';
            const roomLabel = data.room_number
                ? `${this._esc(data.room_name)} · Room ${this._esc(data.room_number)}`
                : this._esc(data.room_name);
            const guestLine = data.children > 0
                ? `${data.adults} adults, ${data.children} children`
                : `${data.adults} adults`;

            const multiBadgeHtml = data.multi_room_label 
                ? `<div style="font-size:11px; font-weight:600; color:var(--mhbo-color-primary, #005f73); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.5px;">🏨 ${this._esc(data.multi_room_label)}</div>` 
                : '';

            return `
            <div class="mhbo-booking-summary">
                <div class="mhbo-booking-summary-header">
                    <div>
                        ${multiBadgeHtml}
                        <span class="mhbo-booking-summary-title">${roomLabel}</span>
                        <span class="mhbo-booking-summary-dates">${formattedDates}</span>
                    </div>
                    <div class="mhbo-chat-avatar" style="font-size:16px">🗓️</div>
                </div>
                <div class="mhbo-booking-summary-grid">
                    <div class="mhbo-summary-item"><label>Stay</label><span>${data.nights} nights</span></div>
                    <div class="mhbo-summary-item"><label>Guests</label><span>${guestLine}</span></div>
                    <div class="mhbo-summary-item"><label>Total</label><span>${data.price_formatted}</span></div>
                    <div class="mhbo-summary-item"><label>Payment</label><span>${depositMsg}</span></div>
                </div>
                <div class="mhbo-booking-summary-footer">
                    <a href="${this._esc(data.booking_url)}"
                       class="mhbo-booking-link-btn mhbo-complete-booking-btn"
                       data-room-id="${data.room_id || ''}"
                       data-check-in="${this._esc(data.check_in || '')}"
                       data-check-out="${this._esc(data.check_out || '')}"
                       data-guests="${data.adults || 2}"
                       data-children="${data.children || 0}"
                       data-total-price="${data.total_price || '0'}"
                       data-guest-name="${this._esc(data.guest_name || '')}"
                       data-guest-email="${this._esc(data.guest_email || '')}"
                       data-guest-phone="${this._esc(data.guest_phone || '')}">
                        ${s.completeBooking || 'Complete Booking'}
                    </a>
                    ${data.payment_methods ? `<small style="font-size:10px; color:var(--mhbo-chat-text-muted); text-align:center">Accepting: ${this._esc(data.payment_methods)}</small>` : ''}
                </div>
            </div>`;
        }

        _buildAvailabilityCardsHtml(data) {
            const nights        = data.nights_count || 1;
            const rooms         = data.rooms || [];
            const checkIn       = this._esc(data.check_in  || '');
            const checkOut      = this._esc(data.check_out || '');
            const totalGuests   = data.adults   || 2;
            const totalChildren = data.children || 0;

            let html = `<div class="mhbo-availability-results">`;
            html += `<div class="mhbo-avail-header">
                <span class="mhbo-avail-label">${rooms.length} ${rooms.length === 1 ? (s.roomAvail || 'option') : (s.roomsAvail || 'options')} &middot; ${nights} ${nights === 1 ? (s.night || 'night') : (s.nights || 'nights')}</span>
            </div>`;

            rooms.forEach(room => {
                const isMulti     = !!room.is_multi_room;
                const numRooms    = parseInt(room.num_rooms    || 1, 10) || 1;
                const unitsLeft   = parseInt(room.units_remaining || 0, 10);
                const priceFmt    = room.price_formatted || '';
                const perNight    = room.price_per_night;
                const thumbUrl    = room.thumbnail_url   || '';
                const maxAdults   = room.max_adults      || 0;
                const maxChildren = room.max_children    || 0;
                const roomId      = isMulti ? 0 : (room.room_id || 0);

                const scarcityBadge = unitsLeft <= 1
                    ? `<span class="mhbo-room-badge mhbo-room-badge--hot">${s.lastRoom || 'Last room!'}</span>`
                    : unitsLeft <= 3
                    ? `<span class="mhbo-room-badge mhbo-room-badge--low">${unitsLeft} ${s.left || 'left'}</span>`
                    : '';

                const multiBadge = isMulti
                    ? `<span class="mhbo-room-badge mhbo-room-badge--multi">${numRooms}× ${s.rooms || 'rooms'}</span>`
                    : '';

                const thumbHtml = thumbUrl
                    ? `<div class="mhbo-room-thumb-wrap"><img class="mhbo-room-thumb" src="${this._esc(thumbUrl)}" alt="${this._esc(room.room_name)}" loading="lazy"></div>`
                    : '';

                const capHtml = (maxAdults || maxChildren)
                    ? `<div class="mhbo-room-capacity">👤 ${maxAdults}${maxChildren ? ' + ' + maxChildren + ' ' + (s.childrenShort || 'ch') : ''}</div>`
                    : '';

                const priceHtml = priceFmt
                    ? `<div class="mhbo-room-price"><strong>${this._esc(priceFmt)}</strong>${perNight && nights > 1 ? `<small>${this._esc(String(perNight))} ${s.perNightShort || '/ night'}</small>` : ''}</div>`
                    : '';

                if (isMulti && room.distribution && room.distribution.length) {
                    // Render a distribution breakdown card — the AI will call create_booking_link
                    // once per slot and generate individual booking cards with pre-filled guest info.
                    let distRows = room.distribution.map((slot, i) => {
                        const label = slot.room_number ? `Room ${this._esc(String(slot.room_number))}` : `Room ${i + 1}`;
                        const guests = slot.children > 0
                            ? `${slot.adults} adults, ${slot.children} children`
                            : `${slot.adults} adults`;
                        return `<div class="mhbo-dist-row"><span class="mhbo-dist-label">${label}</span><span class="mhbo-dist-guests">${guests}</span></div>`;
                    }).join('');

                    html += `
                    <div class="mhbo-room-avail-card mhbo-room-avail-card--multi">
                        ${thumbHtml}
                        <div class="mhbo-room-avail-body">
                            <div class="mhbo-room-avail-name-row">
                                <span class="mhbo-room-avail-name">${this._esc(room.room_name)}</span>
                                <div class="mhbo-room-badges">${multiBadge}${scarcityBadge}</div>
                            </div>
                            ${capHtml}
                            ${priceHtml}
                            <div class="mhbo-dist-breakdown">${distRows}</div>
                            <p class="mhbo-multi-notice">${numRooms} separate bookings required — provide your Full Name, Email, and Phone so individual booking cards can be prepared for you.</p>
                        </div>
                    </div>`;
                } else {
                    const btnLabel = isMulti ? (s.viewOptions || 'View Options') : (s.bookNow || 'Book Now');
                    html += `
                    <div class="mhbo-room-avail-card${isMulti ? ' mhbo-room-avail-card--multi' : ''}">
                        ${thumbHtml}
                        <div class="mhbo-room-avail-body">
                            <div class="mhbo-room-avail-name-row">
                                <span class="mhbo-room-avail-name">${this._esc(room.room_name)}</span>
                                <div class="mhbo-room-badges">${multiBadge}${scarcityBadge}</div>
                            </div>
                            ${capHtml}
                            ${priceHtml}
                        </div>
                        <a href="${this._esc(room.booking_url || '')}"
                           class="mhbo-booking-link-btn mhbo-complete-booking-btn"
                           data-room-id="${roomId}"
                           data-check-in="${checkIn}"
                           data-check-out="${checkOut}"
                           data-guests="${totalGuests}"
                           data-children="${totalChildren}"
                           data-total-price="${room.total_price || 0}"
                           rel="noopener noreferrer">
                            ${btnLabel}
                        </a>
                    </div>`;
                }
            });

            html += `</div>`;
            return html;
        }

        _attachCardEvents(card) {
            // Copy to clipboard for code elements
            card.querySelectorAll('.mhbo-copyable').forEach(el => {
                el.addEventListener('click', () => {
                    const text = el.textContent;
                    navigator.clipboard.writeText(text).then(() => {
                        const hint = el.nextElementSibling;
                        if (hint && hint.classList.contains('mhbo-copy-hint')) {
                            const oldText = hint.textContent;
                            hint.textContent = s.copied || 'Copied!';
                            hint.style.opacity = '1';
                            el.style.background = 'rgba(46, 125, 50, 0.1)';
                            setTimeout(() => {
                                hint.textContent = oldText;
                                hint.style.opacity = '';
                                el.style.background = '';
                            }, 2000);
                        }
                    });
                });
            });

            // Zoom QR code
            card.querySelectorAll('.mhbo-qr-thumb').forEach(img => {
                img.addEventListener('click', () => {
                    window.open(img.src, '_blank');
                });
            });

            // Modal-mode intercept for booking buttons (supports multiple buttons per card)
            const modalActive = (window.mhboChat && window.mhboChat.settings && window.mhboChat.settings.modalEnabled)
                || typeof window.MhboModal !== 'undefined';
            card.querySelectorAll('.mhbo-complete-booking-btn').forEach(btn => {
                btn.addEventListener('click', function (ev) {
                    const roomId = parseInt(btn.dataset.roomId || '0', 10);
                    if (roomId > 0 && modalActive) {
                        ev.preventDefault();
                        document.dispatchEvent(new CustomEvent('mhboBookNow', {
                            detail: {
                                room_id:        roomId,
                                check_in:       btn.dataset.checkIn    || '',
                                check_out:      btn.dataset.checkOut   || '',
                                guests:         parseInt(btn.dataset.guests   || '2', 10),
                                children:       parseInt(btn.dataset.children || '0', 10),
                                total_price:    parseFloat(btn.dataset.totalPrice || '0'),
                                customer_name:  btn.dataset.guestName  || '',
                                customer_email: btn.dataset.guestEmail || '',
                                customer_phone: btn.dataset.guestPhone || '',
                            }
                        }));
                    }
                });
            });
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Boot
    // ─────────────────────────────────────────────────────────────────────────

    function boot() {
        const widgets = [];

        document.querySelectorAll('.mhbo-chat-widget').forEach(el => {
            if (!el.dataset.mhboInitialized) {
                el.dataset.mhboInitialized = 'true';
                const widget = new ChatWidget(el);
                widget.init();
                widgets.push(widget);
            }
        });

        // ── Proactive dwell-time trigger (Einstein Proactive Service) ─────────
        // After the configured dwell time on a page that contains booking-relevant
        // content, auto-open the first widget with a context-aware greeting.
        // Respects: already-open widgets, dismissed flag (sessionStorage), data-proactive="false".
        const proactiveSecs = cfg.settings?.proactiveTriggerSeconds ?? 45;
        const proactiveEl   = document.querySelector('.mhbo-chat-widget:not([data-proactive="false"])');

        if ( proactiveEl && proactiveSecs > 0 && !sessionStorage.getItem('mhbo_proactive_done') ) {
            const targetWidget = widgets.find(w => w.container === proactiveEl);

            if (targetWidget && !targetWidget.isOpen) {
                // Detect page context to choose the right greeting.
                const pageHasBookingForm = !!document.querySelector('.mhbo-booking-form, [data-mhbo-booking]');
                const pageHasRooms       = !!document.querySelector('.mhbo-rooms, .mhbo-room-card');

                let greeting = s.proactiveDefault || 'Hi there! 👋 Can I help you plan your stay?';
                if (pageHasBookingForm) {
                    greeting = s.proactiveBooking || 'Hi! I can answer questions about availability or pricing. Just ask!';
                } else if (pageHasRooms) {
                    greeting = s.proactiveRooms || 'Looking for the perfect room? I can help you choose!';
                }

                setTimeout(() => {
                    if (!targetWidget.isOpen) {
                        sessionStorage.setItem('mhbo_proactive_done', '1');
                        targetWidget.open();
                        // Small delay so the panel animation completes before the greeting appears.
                        setTimeout(() => targetWidget.receiveMessage(greeting), 400);
                    }
                }, proactiveSecs * 1000);
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    // Expose for Gutenberg block / external control.
    window.MhboChatWidget = { boot };

})();
