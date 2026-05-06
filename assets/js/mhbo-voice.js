/**
 * MHBO Voice Input / TTS
 *
 * Web Speech API wrapper with graceful degradation.
 *
 * @package modern-hotel-booking
 * @since   2.4.0
 */

/* global mhboChat */

(function () {
    'use strict';

    const cfg      = (typeof mhboChat !== 'undefined') ? mhboChat : {};
    const settings = cfg.settings || {};
    const s        = cfg.strings || {};

    // ─────────────────────────────────────────────────────────────────────────
    // VoiceInput
    // ─────────────────────────────────────────────────────────────────────────

    class VoiceInput {
        constructor() {
            this.SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition || null;
            this.SpeechSynthesis   = window.speechSynthesis || null;
            this.recognition       = null;
            this.isListening       = false;
            this.currentAudio      = null; // Track active ElevenLabs/external audio
            this.ttsEnabled        = false;
            this.silenceTimer      = null;
        }

        /**
         * Check if Speech Recognition is supported.
         * @returns {boolean}
         */
        isSupported() {
            return !!this.SpeechRecognition;
        }

        /**
         * Start voice input.
         * @param {function(string): void} onResult  Called with the final transcript.
         * @param {function(string): void} [onError] Called with error message.
         */
        start(onResult, onError) {
            // Barge-in (2026 BP): Always stop bot speech when guest starts speaking.
            this.stopSpeaking();

            if (this.silenceTimer) {
                clearTimeout(this.silenceTimer);
                this.silenceTimer = null;
            }

            if (!this.SpeechRecognition) {
                onError && onError(s.voiceNotSupported || 'Voice input is not supported in this browser.');
                return;
            }

            if (this.isListening) {
                this.stop();
                return;
            }

            const r = new this.SpeechRecognition();
            r.lang           = this._getLanguage();
            r.interimResults = true;
            r.maxAlternatives = 1;
            r.continuous     = true;

            this.recognition = r;
            this.isListening = true;
            this._setRecordingUI(true);

            let finalTranscript = '';

            r.onresult = (event) => {
                // Clear existing silence timer.
                if (this.silenceTimer) clearTimeout(this.silenceTimer);

                let interimTranscript = '';
                for (let i = event.resultIndex; i < event.results.length; ++i) {
                    if (event.results[i].isFinal) {
                        finalTranscript += event.results[i][0].transcript;
                    } else {
                        interimTranscript += event.results[i][0].transcript;
                    }
                }

                const fullResult = (finalTranscript + interimTranscript).trim();
                if (fullResult) {
                    onResult(fullResult);

                    // Silence detection: If no more speech for 3 seconds, stop and submit.
                    this.silenceTimer = setTimeout(() => {
                        this.stop();
                        const form = document.querySelector('.mhbo-chat-input-row');
                        if (form) {
                            form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
                        }
                    }, 3000); // 3-second grace period as requested.
                }
            };

            r.onerror = (event) => {
                this._setRecordingUI(false);
                this.isListening = false;
                let msg = s.voiceError || 'Voice input error.';
                if (event.error === 'not-allowed') {
                    msg = s.voicePermissionDenied || 'Microphone access was denied. Please allow microphone access in your browser settings.';
                } else if (event.error === 'network') {
                    msg = s.voiceNetworkError || 'Network error during voice recognition.';
                }
                onError && onError(msg);
            };

            r.onend = () => {
                this._setRecordingUI(false);
                this.isListening = false;
            };

            r.start();
        }

        /**
         * Stop active voice recognition.
         */
        stop() {
            if (this.silenceTimer) {
                clearTimeout(this.silenceTimer);
                this.silenceTimer = null;
            }
            if (this.recognition) {
                this.recognition.stop();
                this.recognition = null;
            }
            this.isListening = false;
            this._setRecordingUI(false);
        }

        /**
         * Stop all active speech output (Browser TTS and ElevenLabs).
         */
        stopSpeaking() {
            if (this.SpeechSynthesis) {
                this.SpeechSynthesis.cancel();
            }
            if (this.currentAudio) {
                this.currentAudio.pause();
                this.currentAudio = null;
            }
        }

        /**
         * Speak text via TTS (browser SpeechSynthesis or ElevenLabs Pro).
         * @param {string} text
         */
        speak(text) {

this._speakBrowser(text);
        }

        /**
         * Browser SpeechSynthesis TTS.
         * @param {string} text
         */
        /**
         * Resolve the active BCP-47 language tag at call-time.
         * Reads document.documentElement.lang (set by Polylang / WPML / qTranslate)
         * and falls back to the admin-configured default.
         * @returns {string}
         */
        _getLanguage() {
            const htmlLang = document.documentElement.lang || '';
            if (htmlLang) return htmlLang.replace('_', '-');
            return (settings.pageLocale || settings.language || 'en-US').replace('_', '-');
        }

        _speakBrowser(text) {
            if (!this.SpeechSynthesis) return;
            this.SpeechSynthesis.cancel();
            const utter = new SpeechSynthesisUtterance(text);
            utter.lang = this._getLanguage();
            this.SpeechSynthesis.speak(utter);
        }

/**
         * Toggle the recording UI indicator on the voice button.
         * @param {boolean} active
         */
        _setRecordingUI(active) {
            const btn = document.querySelector('.mhbo-chat-voice-btn');
            if (!btn) return;
            btn.classList.toggle('mhbo-chat-voice-btn--recording', active);
            btn.setAttribute('aria-pressed', String(active));
            btn.setAttribute('aria-label', active
                ? (s.stopVoice || 'Stop recording')
                : (s.startVoice || 'Start voice input')
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Boot
    // ─────────────────────────────────────────────────────────────────────────

    const instance = new VoiceInput();

    // Hide voice buttons if not supported.
    if (!instance.isSupported()) {
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.mhbo-chat-voice-btn').forEach(btn => {
                btn.hidden = true;
            });
        });
    }

    // Expose globally so ChatWidget can use it.
    window.MhboVoiceInput = instance;

})();
