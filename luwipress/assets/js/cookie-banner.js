/**
 * LuwiPress Cookie Consent — frontend banner + preferences modal + script unblocker.
 *
 * Activates third-party tags wrapped in:
 *   <script type="text/plain" data-luwipress-consent="analytics">…</script>
 *   <script type="text/plain" data-luwipress-consent="marketing" data-src="…"></script>
 *
 * After the visitor consents to a category, every matching script tag is
 * rewritten (type → text/javascript, data-src → src) and re-inserted so the
 * browser parses + executes it.
 *
 * Cookie format: a JSON object base64-encoded into one cookie. Keys:
 *   v   : schema version (1)
 *   ts  : ISO timestamp of decision
 *   id  : server-issued consent_id (uuid)
 *   c   : { necessary, analytics, marketing, personalization } booleans
 */
(function () {
	'use strict';

	var cfg = window.LuwiPressConsent;
	if (!cfg || !cfg.rest_url) return;

	var COOKIE_NAME = cfg.cookie_name || 'luwipress_consent';
	var TTL_DAYS    = cfg.cookie_ttl_days || 365;

	function readCookie(name) {
		var m = document.cookie.match('(^|;)\\s*' + name + '=([^;]+)');
		if (!m) return null;
		try { return JSON.parse(atob(decodeURIComponent(m[2]))); } catch (e) { return null; }
	}

	function writeCookie(name, value) {
		var b64 = btoa(JSON.stringify(value));
		var d = new Date();
		d.setTime(d.getTime() + TTL_DAYS * 86400000);
		document.cookie = name + '=' + encodeURIComponent(b64) + '; expires=' + d.toUTCString() + '; path=/; SameSite=Lax';
	}

	function unblockScripts(choices) {
		var nodes = document.querySelectorAll('script[type="text/plain"][data-luwipress-consent]');
		for (var i = 0; i < nodes.length; i++) {
			var node = nodes[i];
			var cat = node.getAttribute('data-luwipress-consent');
			if (!choices[cat]) continue;
			var fresh = document.createElement('script');
			// copy attributes except type
			for (var a = 0; a < node.attributes.length; a++) {
				var attr = node.attributes[a];
				if (attr.name === 'type') continue;
				if (attr.name === 'data-src') { fresh.setAttribute('src', attr.value); continue; }
				fresh.setAttribute(attr.name, attr.value);
			}
			fresh.setAttribute('type', 'text/javascript');
			if (node.textContent) fresh.textContent = node.textContent;
			node.parentNode.insertBefore(fresh, node);
			node.parentNode.removeChild(node);
		}

		// Dispatch a custom event so plugin/theme code can react to consent.
		window.dispatchEvent(new CustomEvent('luwipress:consent', { detail: choices }));
	}

	function saveConsent(choices, source) {
		var body = JSON.stringify({ choices: choices, source: source || 'banner' });
		fetch(cfg.rest_url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce || ''
			},
			body: body
		}).then(function (r) {
			return r.json();
		}).then(function (data) {
			writeCookie(COOKIE_NAME, {
				v: 1,
				ts: new Date().toISOString(),
				id: (data && data.consent_id) || null,
				c: choices
			});
			unblockScripts(choices);
			hideBanner();
		}).catch(function () {
			// Even if the server call fails, honour the visitor's choice client-side.
			writeCookie(COOKIE_NAME, { v: 1, ts: new Date().toISOString(), id: null, c: choices });
			unblockScripts(choices);
			hideBanner();
		});
	}

	function el(tag, attrs, children) {
		var n = document.createElement(tag);
		if (attrs) for (var k in attrs) {
			if (k === 'class') n.className = attrs[k];
			else if (k === 'html') n.innerHTML = attrs[k];
			else n.setAttribute(k, attrs[k]);
		}
		if (children) for (var i = 0; i < children.length; i++) {
			if (children[i]) n.appendChild(typeof children[i] === 'string' ? document.createTextNode(children[i]) : children[i]);
		}
		return n;
	}

	var rootEl = null;
	function hideBanner() { if (rootEl) rootEl.style.display = 'none'; }
	function showBanner() { if (rootEl) rootEl.style.display = ''; }

	function renderPreferencesModal() {
		var existing = document.getElementById('lwp-consent-modal');
		if (existing) { existing.style.display = 'flex'; return; }

		var modal = el('div', { id: 'lwp-consent-modal', class: 'lwp-consent-modal', role: 'dialog', 'aria-modal': 'true' });
		var card  = el('div', { class: 'lwp-consent-modal__card' });
		var title = el('h2', { class: 'lwp-consent-modal__title' }, [cfg.texts.title || 'Cookie preferences']);
		card.appendChild(title);

		var form = el('form', { class: 'lwp-consent-modal__form' });
		var checkboxes = {};

		for (var i = 0; i < cfg.categories.length; i++) {
			var cat = cfg.categories[i];
			var row = el('div', { class: 'lwp-consent-cat' });
			var lbl = el('label', { class: 'lwp-consent-cat__label' });
			var cb  = el('input', { type: 'checkbox', value: cat, class: 'lwp-consent-cat__cb' });
			if (cat === 'necessary') {
				cb.checked = true;
				cb.disabled = true;
			} else if (cfg.mode === 'opt-out') {
				cb.checked = true;
			}
			checkboxes[cat] = cb;
			var head = el('span', { class: 'lwp-consent-cat__head' });
			head.appendChild(cb);
			head.appendChild(el('strong', null, [cfg.texts['cat_' + cat] || cat]));
			lbl.appendChild(head);
			lbl.appendChild(el('p', { class: 'lwp-consent-cat__desc' }, [cfg.texts['cat_' + cat + '_desc'] || '']));
			row.appendChild(lbl);
			form.appendChild(row);
		}

		var actions = el('div', { class: 'lwp-consent-modal__actions' });
		var saveBtn = el('button', { type: 'button', class: 'lwp-consent-btn lwp-consent-btn--primary' }, [cfg.texts.save || 'Save preferences']);
		var closeBtn = el('button', { type: 'button', class: 'lwp-consent-btn lwp-consent-btn--secondary' }, [cfg.texts.accept_all || 'Accept all']);
		actions.appendChild(saveBtn);
		actions.appendChild(closeBtn);

		saveBtn.addEventListener('click', function () {
			var choices = {};
			for (var k in checkboxes) choices[k] = !!checkboxes[k].checked;
			saveConsent(choices, 'preferences');
			modal.style.display = 'none';
		});
		closeBtn.addEventListener('click', function () {
			var choices = {};
			for (var k in checkboxes) choices[k] = true;
			saveConsent(choices, 'preferences-accept-all');
			modal.style.display = 'none';
		});

		card.appendChild(form);
		card.appendChild(actions);
		modal.appendChild(card);
		document.body.appendChild(modal);
	}

	function renderBanner() {
		rootEl = el('div', { id: 'lwp-consent-banner', class: 'lwp-consent-banner lwp-consent-banner--' + (cfg.position || 'bottom') + ' lwp-consent-banner--theme-' + (cfg.theme || 'auto') });
		var inner = el('div', { class: 'lwp-consent-banner__inner' });

		var copy = el('div', { class: 'lwp-consent-banner__copy' });
		copy.appendChild(el('strong', null, [cfg.texts.title || 'We value your privacy']));
		copy.appendChild(el('p', { html: cfg.texts.body || '' }));
		if (cfg.policy_url) {
			var more = el('p', { class: 'lwp-consent-banner__links' });
			more.innerHTML = '<a href="' + cfg.policy_url + '" target="_blank" rel="noopener">' + (cfg.texts.preferences || 'Cookie policy') + '</a>';
			copy.appendChild(more);
		}

		var btns = el('div', { class: 'lwp-consent-banner__buttons' });
		if (cfg.show_preferences) {
			var prefBtn = el('button', { type: 'button', class: 'lwp-consent-btn lwp-consent-btn--ghost' }, [cfg.texts.preferences || 'Preferences']);
			prefBtn.addEventListener('click', function () { renderPreferencesModal(); });
			btns.appendChild(prefBtn);
		}
		if (cfg.show_reject_button && cfg.mode === 'opt-in') {
			var rejBtn = el('button', { type: 'button', class: 'lwp-consent-btn lwp-consent-btn--secondary' }, [cfg.texts.reject_all || 'Reject non-essential']);
			rejBtn.addEventListener('click', function () {
				var choices = { necessary: true, analytics: false, marketing: false, personalization: false };
				saveConsent(choices, 'banner-reject');
			});
			btns.appendChild(rejBtn);
		}
		var accBtn = el('button', { type: 'button', class: 'lwp-consent-btn lwp-consent-btn--primary' }, [cfg.texts.accept_all || 'Accept all']);
		accBtn.addEventListener('click', function () {
			var choices = { necessary: true, analytics: true, marketing: true, personalization: true };
			saveConsent(choices, 'banner-accept');
		});
		btns.appendChild(accBtn);

		inner.appendChild(copy);
		inner.appendChild(btns);
		rootEl.appendChild(inner);
		document.body.appendChild(rootEl);
	}

	function init() {
		var existing = readCookie(COOKIE_NAME);
		if (existing && existing.c) {
			unblockScripts(existing.c);
			// "info" mode: no banner once decision is made.
			return;
		}
		// Opt-out mode: pre-fire scripts immediately; show banner so visitor can change mind.
		if (cfg.mode === 'opt-out') {
			var allOn = { necessary: true, analytics: true, marketing: true, personalization: true };
			unblockScripts(allOn);
		}
		renderBanner();
	}

	// Public API for theme/plugin authors who want a "manage cookies" link.
	window.LuwiPressConsentAPI = {
		open: function () { renderPreferencesModal(); },
		current: function () { return readCookie(COOKIE_NAME); },
		reset: function () {
			document.cookie = COOKIE_NAME + '=; expires=Thu, 01 Jan 1970 00:00:01 GMT; path=/';
			location.reload();
		}
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
