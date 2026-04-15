(function() {
    'use strict';

    var LuwiPressChat = {
        sessionId: null,
        sending: false,
        hasMessages: false,
        opened: false,

        init: function() {
            if (!window.lpChat) return;
            if (lpChat.enabled === false) return;

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

            widget.innerHTML =
                '<button id="lp-chat-toggle" class="lp-chat-toggle" aria-label="Chat with us">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor">' +
                        '<path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"></path>' +
                    '</svg>' +
                '</button>' +
                '<div class="lp-chat-window">' +
                    '<div class="lp-chat-header">' +
                        '<div class="lp-chat-header-info">' +
                            '<span class="lp-chat-title">' + this.escapeHtml(storeName) + '</span>' +
                            '<span class="lp-chat-subtitle">AI Assistant</span>' +
                        '</div>' +
                        '<div class="lp-chat-header-actions">' +
                            '<button class="lp-chat-escalate-btn" title="Talk to our team">' +
                                '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="currentColor">' +
                                    '<path d="M20.01 15.38c-1.23 0-2.42-.2-3.53-.56a.977.977 0 0 0-1.01.24l-1.57 1.97c-2.83-1.35-5.48-3.9-6.89-6.83l1.95-1.66c.27-.28.35-.67.24-1.02-.37-1.11-.56-2.3-.56-3.53 0-.54-.45-.99-.99-.99H4.19C3.65 3 3 3.24 3 3.99 3 13.28 10.73 21 20.01 21c.71 0 .99-.63.99-1.18v-3.45c0-.54-.45-.99-.99-.99z"></path>' +
                                '</svg>' +
                            '</button>' +
                            '<button class="lp-chat-close-btn" aria-label="Close chat">&times;</button>' +
                        '</div>' +
                    '</div>' +
                    '<div class="lp-chat-body" id="lp-chat-body"></div>' +
                    '<div class="lp-chat-input-area">' +
                        '<input type="text" id="lp-chat-input" class="lp-chat-input" placeholder="Type your question..." maxlength="1000" autocomplete="off">' +
                        '<button id="lp-chat-send" class="lp-chat-send-btn" disabled>' +
                            '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="currentColor">' +
                                '<path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"></path>' +
                            '</svg>' +
                        '</button>' +
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

            document.querySelector('.lp-chat-escalate-btn').addEventListener('click', function() {
                self.escalateToAgent();
            });

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

        sendMessage: async function() {
            var input = document.getElementById('lp-chat-input');
            var message = input.value.trim();
            if (!message || this.sending) return;

            this.sending = true;
            input.value = '';
            input.disabled = true;
            document.getElementById('lp-chat-send').disabled = true;

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
                    this.renderMessage('assistant', 'You\'re sending messages too quickly. Please wait a moment.');
                    return;
                }

                var data = await res.json();

                if (data.response) {
                    this.renderMessage('assistant', data.response);
                }

                if (data.suggest_escalation) {
                    this.showEscalationSuggestion();
                }
            } catch (err) {
                this.hideTyping();
                this.renderMessage('assistant', 'Sorry, something went wrong. Please try again.');
            } finally {
                this.sending = false;
                input.disabled = false;
                input.focus();
            }
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

            this.renderMessage('assistant', 'Connecting you with our team. A new window will open shortly.');
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
