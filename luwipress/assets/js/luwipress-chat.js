(function() {
    'use strict';

    var LuwiPressChat = {
        sessionId: null,
        sending: false,
        hasMessages: false,
        opened: false,

        /**
         * Minimal critical CSS guarantees the launcher is visible even if the
         * main stylesheet was stripped / deferred by a cache or optimizer plugin.
         * Covers: button pill, icon, position, z-index. Full styles still come
         * from luwipress-chat.css when available.
         */
        criticalCss:
            '#lp-chat-widget{position:fixed;bottom:20px;right:20px;z-index:2147483000;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}' +
            '#lp-chat-widget.lp-chat-left{right:auto;left:20px}' +
            '#lp-chat-widget .lp-chat-toggle{display:inline-flex;align-items:center;justify-content:center;width:56px;height:56px;padding:0;border:0;border-radius:50%;cursor:pointer;background:var(--lp-primary,#6366f1);color:var(--lp-text,#fff);font-size:0;line-height:0;box-shadow:0 6px 20px rgba(0,0,0,.18);animation:lp-chat-pulse 3.6s ease-in-out infinite}' +
            '#lp-chat-widget .lp-chat-toggle::before{content:"";display:inline-block;width:24px;height:24px;background-image:url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 24 24\' fill=\'%23ffffff\'%3E%3Cpath d=\'M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z\'/%3E%3C/svg%3E");background-size:contain;background-repeat:no-repeat;background-position:center}' +
            '@keyframes lp-chat-pulse{0%{box-shadow:0 6px 20px rgba(0,0,0,.18),0 0 0 0 rgba(99,102,241,.55)}60%{box-shadow:0 6px 20px rgba(0,0,0,.18),0 0 0 14px rgba(99,102,241,0)}100%{box-shadow:0 6px 20px rgba(0,0,0,.18),0 0 0 0 rgba(99,102,241,0)}}' +
            '@media (prefers-reduced-motion: reduce){#lp-chat-widget .lp-chat-toggle{animation:none}}' +
            '#lp-chat-widget:not(.lp-chat-closed) .lp-chat-toggle{display:none}' +
            '#lp-chat-widget.lp-chat-closed .lp-chat-window{display:none}',

        ensureCss: function() {
            if (document.getElementById('lp-chat-critical-css')) return;
            var style = document.createElement('style');
            style.id = 'lp-chat-critical-css';
            style.setAttribute('data-no-optimize', '1');
            style.textContent = this.criticalCss;
            // Prepend so full stylesheet (when present) can still override.
            var head = document.head || document.getElementsByTagName('head')[0];
            if (head.firstChild) {
                head.insertBefore(style, head.firstChild);
            } else {
                head.appendChild(style);
            }
        },

        init: function() {
            if (!window.lpChat) return;
            if (lpChat.enabled === false) return;

            this.ensureCss();

            var stored = localStorage.getItem('lp_chat_session');
            if (stored) {
                this.sessionId = stored;
            } else {
                this.sessionId = this.generateSessionId();
                localStorage.setItem('lp_chat_session', this.sessionId);
            }

            this.createWidget();
            this.bindEvents();

            if (stored) {
                this.restoreSession();
            }

            // Self-check: if the launcher didn't mount within 3 s, log a warning.
            setTimeout(function() {
                if (!document.getElementById('lp-chat-widget')) {
                    if (window.console && console.warn) {
                        console.warn('[LuwiPress] Chat widget failed to mount — check optimizer/cache exclusions for luwipress-chat assets.');
                    }
                }
            }, 3000);
        },

        generateSessionId: function() {
            var arr = new Uint8Array(16);
            crypto.getRandomValues(arr);
            return Array.from(arr, function(b) {
                return b.toString(16).padStart(2, '0');
            }).join('');
        },

        createWidget: function() {
            var primary = lpChat.primary || '#6366f1';
            var textColor = lpChat.text_color || '#ffffff';
            var storeName = lpChat.store_name || 'Store';
            var position = lpChat.position || 'bottom-right';

            var widget = document.createElement('div');
            widget.id = 'lp-chat-widget';
            widget.className = 'lp-chat-widget lp-chat-closed';
            if (position === 'bottom-left') {
                widget.classList.add('lp-chat-left');
            }
            widget.style.setProperty('--lp-primary', primary);
            widget.style.setProperty('--lp-text', textColor);

            // Minimal WhatsApp entry: icon-only circular button next to close.
            // No persistent bottom bar — the header icon + title tooltip are enough.
            var hasWa = !!lpChat.whatsapp_number;
            var waHeaderBtn = hasWa
                ? '<button class="lp-chat-wa-icon-btn" title="Chat on WhatsApp" aria-label="Chat on WhatsApp"><span class="lp-chat-wa-icon-glyph"></span></button>'
                : '';

            widget.innerHTML =
                '<button id="lp-chat-toggle" class="lp-chat-toggle" aria-label="Chat with us"></button>' +
                '<div class="lp-chat-window">' +
                    '<div class="lp-chat-header">' +
                        '<div class="lp-chat-header-info">' +
                            '<span class="lp-chat-title">' + this.escapeHtml(storeName) + '</span>' +
                            '<span class="lp-chat-subtitle">AI Assistant</span>' +
                        '</div>' +
                        '<div class="lp-chat-header-actions">' +
                            waHeaderBtn +
                            '<button class="lp-chat-close-btn" aria-label="Close chat">&times;</button>' +
                        '</div>' +
                    '</div>' +
                    '<div class="lp-chat-body" id="lp-chat-body"></div>' +
                    '<div class="lp-chat-input-area">' +
                        '<input type="text" id="lp-chat-input" class="lp-chat-input" placeholder="Type your question..." maxlength="1000" autocomplete="off">' +
                        '<button id="lp-chat-send" class="lp-chat-send-btn" disabled></button>' +
                    '</div>' +
                    '<div class="lp-chat-footer">' +
                        '<span>Powered by LuwiPress</span>' +
                    '</div>' +
                '</div>';

            document.body.appendChild(widget);
        },

        bindEvents: function() {
            var self = this;

            document.getElementById('lp-chat-toggle').addEventListener('click', function() {
                self.toggleWidget();
            });

            document.querySelector('.lp-chat-close-btn').addEventListener('click', function() {
                self.toggleWidget();
            });

            // WhatsApp escalation — single icon button in header, minimal footprint
            var waBtn = document.querySelector('.lp-chat-wa-icon-btn');
            if (waBtn) waBtn.addEventListener('click', function() { self.escalateToAgent(); });

            document.getElementById('lp-chat-input').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    self.sendMessage();
                }
            });

            document.getElementById('lp-chat-send').addEventListener('click', function() {
                self.sendMessage();
            });

            document.getElementById('lp-chat-input').addEventListener('input', function() {
                document.getElementById('lp-chat-send').disabled = !this.value.trim();
            });
        },

        toggleWidget: function() {
            var widget = document.getElementById('lp-chat-widget');
            widget.classList.toggle('lp-chat-closed');

            var isOpen = !widget.classList.contains('lp-chat-closed');

            if (isOpen) {
                if (!this.opened && !this.hasMessages) {
                    var greeting = lpChat.greeting || 'Hello! How can I help you today?';
                    this.renderMessage('assistant', greeting);
                    this.hasMessages = true;
                    this.opened = true;
                }
                var input = document.getElementById('lp-chat-input');
                setTimeout(function() { input.focus(); }, 100);
            }
        },

        sendMessage: async function(overrideText) {
            var input = document.getElementById('lp-chat-input');
            var message = (typeof overrideText === 'string' && overrideText)
                ? overrideText
                : input.value.trim();
            if (!message || this.sending) return;

            this.sending = true;
            input.value = '';
            input.disabled = true;
            document.getElementById('lp-chat-send').disabled = true;

            // Active chips always belong to the LAST assistant turn; remove
            // them when the customer commits a new message so stale choices
            // don't pile up alongside fresh context.
            this.clearChips();

            this.renderMessage('customer', message);
            this.showTyping();

            try {
                var headers = { 'Content-Type': 'application/json' };
                if (lpChat.nonce) {
                    headers['X-WP-Nonce'] = lpChat.nonce;
                }

                var res = await fetch(lpChat.rest_url + 'message', {
                    method: 'POST',
                    headers: headers,
                    body: JSON.stringify({
                        session_id: this.sessionId,
                        message: message,
                        page_url: window.location.href
                    })
                });

                this.hideTyping();

                if (res.status === 429) {
                    this.renderMessage('assistant', this.chipText('msg_rate_limit'));
                    this.renderChips(this.getFallbackChips('clarify'), 'clarify');
                    return;
                }

                var data = await res.json();

                if (data.response) {
                    this.renderMessage('assistant', data.response);
                }

                if (data.chips && data.chips.length) {
                    this.renderChips(data.chips, data.chip_kind || 'follow_up');
                }

                if (data.suggest_escalation) {
                    this.showEscalationSuggestion();
                }
            } catch (err) {
                this.hideTyping();
                this.renderMessage('assistant', this.chipText('msg_error'));
                this.renderChips(this.getFallbackChips('clarify'), 'clarify');
            } finally {
                this.sending = false;
                input.disabled = false;
                input.focus();
            }
        },

        /**
         * Resolve a vocab key against the server-supplied chip pack. Falls
         * back to English defaults so the chat never renders an empty
         * pill, even if the pack failed to load. Supports %s substitution
         * for one positional argument.
         */
        chipText: function(key, arg) {
            var pack = (window.lpChat && lpChat.chip_pack) ? lpChat.chip_pack : {};
            var fallback = {
                shipping: 'How long is shipping?',
                returns: 'What about returns?',
                talk_to_team: 'Talk to our team',
                premium_picks: 'Show premium picks',
                top_rated: 'Best picks',
                whats_new: "What's new?",
                did_you_mean: 'Did you mean %s?',
                show_me: 'Show me %s',
                more_like: 'Show similar to %s',
                other_options: 'More from %s',
                top_rated_x: 'Best %s',
                whats_new_in: "What's new in %s?",
                yes_show_me: 'Yes, show me %s',
                clarify_label: 'Pick one to clarify:',
                followup_label: 'You can also ask:',
                msg_rate_limit: "You're sending messages too quickly. Please wait a moment.",
                msg_error: 'Sorry, something went wrong. Please try again.',
                msg_connecting: 'Connecting you with our team. A new window will open shortly.'
            };
            var tmpl = pack[key] || fallback[key] || '';
            if (typeof arg === 'string') {
                tmpl = tmpl.replace('%s', arg);
            }
            return tmpl;
        },

        /**
         * Render a row of quick-reply pills below the latest assistant
         * message. `kind` is "clarify" or "follow_up" and only affects the
         * surrounding label so the customer understands what tapping does.
         */
        renderChips: function(chips, kind) {
            if (!Array.isArray(chips) || !chips.length) return;
            this.clearChips();
            var body = document.getElementById('lp-chat-body');
            var wrap = document.createElement('div');
            wrap.className = 'lp-chat-chips lp-chat-chips-' + (kind === 'clarify' ? 'clarify' : 'followup');
            wrap.id = 'lp-chat-chips';

            var label = document.createElement('div');
            label.className = 'lp-chat-chips-label';
            label.textContent = this.chipText(kind === 'clarify' ? 'clarify_label' : 'followup_label');
            wrap.appendChild(label);

            var row = document.createElement('div');
            row.className = 'lp-chat-chips-row';

            // Key-based escalation detection — works in every language
            // because we compare against the localized pack value, not a
            // regex on English text.
            var escapeChipText = this.chipText('talk_to_team');

            var self = this;
            chips.forEach(function(text) {
                if (typeof text !== 'string' || !text.trim()) return;
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'lp-chat-chip';
                btn.textContent = text.trim();
                btn.addEventListener('click', function() {
                    if (self.sending) return;
                    if (text.trim() === escapeChipText) {
                        self.clearChips();
                        self.escalateToAgent();
                        return;
                    }
                    self.sendMessage(text);
                });
                row.appendChild(btn);
            });

            wrap.appendChild(row);
            body.appendChild(wrap);
            body.scrollTop = body.scrollHeight;
        },

        clearChips: function() {
            var existing = document.getElementById('lp-chat-chips');
            if (existing) existing.remove();
        },

        getFallbackChips: function(kind) {
            var chips = [];
            var cats = (window.lpChat && Array.isArray(lpChat.fallback_categories))
                ? lpChat.fallback_categories
                : [];
            var self = this;
            cats.slice(0, 3).forEach(function(name) {
                if (name) chips.push(self.chipText('show_me', name));
            });
            if (kind === 'follow_up') {
                chips.push(this.chipText('shipping'));
                chips.push(this.chipText('returns'));
            } else {
                chips.push(this.chipText('talk_to_team'));
            }
            // Dedupe and cap
            var seen = {}, out = [];
            chips.forEach(function(c) {
                if (c && !seen[c]) { seen[c] = 1; out.push(c); }
            });
            return out.slice(0, 4);
        },

        renderMessage: function(role, content) {
            var body = document.getElementById('lp-chat-body');
            var wrapper = document.createElement('div');
            wrapper.className = 'lp-chat-msg lp-chat-msg-' + role;

            var bubble = document.createElement('div');
            bubble.className = 'lp-chat-bubble';

            var html = this.escapeHtml(content);
            html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
            html = html.replace(/\n/g, '<br>');

            bubble.innerHTML = html;
            wrapper.appendChild(bubble);

            var time = document.createElement('span');
            time.className = 'lp-chat-time';
            time.textContent = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            wrapper.appendChild(time);

            body.appendChild(wrapper);
            body.scrollTop = body.scrollHeight;
        },

        escapeHtml: function(text) {
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },

        showTyping: function() {
            var body = document.getElementById('lp-chat-body');
            var typing = document.createElement('div');
            typing.id = 'lp-chat-typing';
            typing.className = 'lp-chat-msg lp-chat-msg-assistant';
            typing.innerHTML = '<div class="lp-chat-bubble lp-chat-typing"><span></span><span></span><span></span></div>';
            body.appendChild(typing);
            body.scrollTop = body.scrollHeight;
        },

        hideTyping: function() {
            var typing = document.getElementById('lp-chat-typing');
            if (typing) typing.remove();
        },

        escalateToAgent: function() {
            var messages = document.querySelectorAll('.lp-chat-msg');
            var summary = '';
            var recent = Array.from(messages).slice(-6);
            recent.forEach(function(msg) {
                var role = msg.classList.contains('lp-chat-msg-customer') ? 'Customer' : 'Assistant';
                var text = msg.querySelector('.lp-chat-bubble').textContent;
                summary += role + ': ' + text + '\n';
            });

            var channel = lpChat.escalation_channel || 'whatsapp';
            var greeting = encodeURIComponent('Hi, I was chatting on your website and would like to speak with someone.\n\nChat summary:\n' + summary.substring(0, 500));

            if (channel === 'whatsapp' && lpChat.whatsapp_number) {
                window.open('https://wa.me/' + lpChat.whatsapp_number + '?text=' + greeting, '_blank');
            } else if (channel === 'telegram' && lpChat.telegram_username) {
                window.open('https://t.me/' + lpChat.telegram_username, '_blank');
            } else if (channel === 'both') {
                this.showChannelChoice(greeting);
            }

            fetch(lpChat.rest_url + 'session/escalate', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ session_id: this.sessionId, channel: channel === 'both' ? 'whatsapp' : channel })
            }).catch(function() {});

            this.renderMessage('assistant', this.chipText('msg_connecting'));
        },

        showChannelChoice: function(greeting) {
            var self = this;
            var body = document.getElementById('lp-chat-body');
            var wrapper = document.createElement('div');
            wrapper.className = 'lp-chat-msg lp-chat-msg-assistant';

            var bubble = document.createElement('div');
            bubble.className = 'lp-chat-bubble lp-chat-channel-choice';
            bubble.innerHTML = '<p>How would you like to contact us?</p>';

            if (lpChat.whatsapp_number) {
                var waBtn = document.createElement('button');
                waBtn.className = 'lp-chat-channel-btn lp-chat-wa-btn';
                waBtn.textContent = 'WhatsApp';
                waBtn.onclick = function() {
                    window.open('https://wa.me/' + lpChat.whatsapp_number + '?text=' + greeting, '_blank');
                };
                bubble.appendChild(waBtn);
            }

            if (lpChat.telegram_username) {
                var tgBtn = document.createElement('button');
                tgBtn.className = 'lp-chat-channel-btn lp-chat-tg-btn';
                tgBtn.textContent = 'Telegram';
                tgBtn.onclick = function() {
                    window.open('https://t.me/' + lpChat.telegram_username, '_blank');
                };
                bubble.appendChild(tgBtn);
            }

            wrapper.appendChild(bubble);
            body.appendChild(wrapper);
            body.scrollTop = body.scrollHeight;
        },

        showEscalationSuggestion: function() {
            var self = this;
            var body = document.getElementById('lp-chat-body');
            var wrapper = document.createElement('div');
            wrapper.className = 'lp-chat-msg lp-chat-msg-assistant';

            var bubble = document.createElement('div');
            bubble.className = 'lp-chat-bubble';
            bubble.innerHTML = 'Would you like to continue chatting with our team directly? <button class="lp-chat-inline-escalate">Talk to our team</button>';

            bubble.querySelector('.lp-chat-inline-escalate').onclick = function() {
                self.escalateToAgent();
            };

            wrapper.appendChild(bubble);
            body.appendChild(wrapper);
            body.scrollTop = body.scrollHeight;
        },

        restoreSession: async function() {
            try {
                var res = await fetch(lpChat.rest_url + 'session/' + this.sessionId);
                var data = await res.json();

                if (data.exists && data.messages && data.messages.length > 0) {
                    var self = this;
                    data.messages.forEach(function(msg) {
                        self.renderMessage(msg.role === 'customer' ? 'customer' : 'assistant', msg.content);
                    });
                    this.hasMessages = true;
                    this.opened = true;
                }
            } catch (err) {
                // Silently fail — new session will be created
            }
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() { LuwiPressChat.init(); });
    } else {
        LuwiPressChat.init();
    }
})();
