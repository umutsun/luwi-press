/**
 * Luwi Elementor Theme — Minimal vanilla JS.
 *
 * No jQuery dependency. Handles:
 * - Color mode toggle (light/dark)
 * - Mobile menu toggle
 * - Sticky header
 * - Countdown timers
 * - Accessibility enhancements
 *
 * @package Luwi_Elementor
 */

(function () {
	'use strict';

	/* -----------------------------------------------------------------------
	 * Color Mode Toggle
	 * --------------------------------------------------------------------- */
	function initColorMode() {
		var toggles = document.querySelectorAll('.luwi-color-mode-toggle');
		if (!toggles.length) return;

		toggles.forEach(function (btn) {
			btn.addEventListener('click', function () {
				var current = document.documentElement.getAttribute('data-color-mode');
				var next;

				if (!current || current === '') {
					// Auto mode — check system preference to determine current
					var isDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
					next = isDark ? 'light' : 'dark';
				} else {
					next = current === 'dark' ? 'light' : 'dark';
				}

				document.documentElement.setAttribute('data-color-mode', next);
				localStorage.setItem('luwi-color-mode', next);

				// Update aria label
				var label = next === 'dark' ? 'Switch to light mode' : 'Switch to dark mode';
				btn.setAttribute('aria-label', label);
			});
		});
	}

	/* -----------------------------------------------------------------------
	 * Mobile Menu Toggle
	 * --------------------------------------------------------------------- */
	function initMobileMenu() {
		var toggle = document.querySelector('.menu-toggle');
		var nav = document.querySelector('.main-navigation');
		if (!toggle || !nav) return;

		toggle.addEventListener('click', function () {
			var expanded = toggle.getAttribute('aria-expanded') === 'true';
			toggle.setAttribute('aria-expanded', !expanded);
			nav.classList.toggle('is-open');

			// Prevent body scroll when menu is open
			document.body.classList.toggle('menu-open');

			// Trap focus inside menu when open
			if (!expanded) {
				var firstLink = nav.querySelector('.menu a');
				if (firstLink) firstLink.focus();
			}
		});

		// Close menu on Escape
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && nav.classList.contains('is-open')) {
				toggle.setAttribute('aria-expanded', 'false');
				nav.classList.remove('is-open');
				document.body.classList.remove('menu-open');
				toggle.focus();
			}
		});
	}

	/* -----------------------------------------------------------------------
	 * Sticky Header
	 * --------------------------------------------------------------------- */
	function initStickyHeader() {
		var header = document.querySelector('.site-header');
		if (!header) return;

		var observer = new IntersectionObserver(
			function (entries) {
				entries.forEach(function (entry) {
					header.classList.toggle('site-header--sticky', !entry.isIntersecting);
				});
			},
			{ threshold: 0, rootMargin: '-1px 0px 0px 0px' }
		);

		// Create a sentinel element at the top of the page
		var sentinel = document.createElement('div');
		sentinel.style.height = '1px';
		sentinel.setAttribute('aria-hidden', 'true');
		header.parentNode.insertBefore(sentinel, header);
		observer.observe(sentinel);
	}

	/* -----------------------------------------------------------------------
	 * Countdown Timer (Luwi Countdown widget)
	 * --------------------------------------------------------------------- */
	function initCountdowns() {
		var countdowns = document.querySelectorAll('[data-luwi-countdown]');
		if (!countdowns.length) return;

		countdowns.forEach(function (el) {
			var target = new Date(el.getAttribute('data-target')).getTime();
			var expiredText = el.getAttribute('data-expired') || 'Expired';

			function tick() {
				var now = Date.now();
				var diff = target - now;

				if (diff <= 0) {
					var timer = el.querySelector('.luwi-countdown__timer');
					var label = el.querySelector('.luwi-countdown__label');
					if (timer) timer.innerHTML = '<span class="luwi-countdown__expired">' + expiredText + '</span>';
					if (label) label.style.display = 'none';
					return;
				}

				var d = Math.floor(diff / 86400000);
				var h = Math.floor((diff % 86400000) / 3600000);
				var m = Math.floor((diff % 3600000) / 60000);
				var s = Math.floor((diff % 60000) / 1000);

				var days = el.querySelector('[data-days]');
				var hours = el.querySelector('[data-hours]');
				var minutes = el.querySelector('[data-minutes]');
				var seconds = el.querySelector('[data-seconds]');

				if (days) days.textContent = String(d).padStart(2, '0');
				if (hours) hours.textContent = String(h).padStart(2, '0');
				if (minutes) minutes.textContent = String(m).padStart(2, '0');
				if (seconds) seconds.textContent = String(s).padStart(2, '0');

				requestAnimationFrame(function () {
					setTimeout(tick, 1000);
				});
			}

			tick();
		});
	}

	/* -----------------------------------------------------------------------
	 * Elementor Widget Color Toggles (data-luwi-color-toggle attribute)
	 * --------------------------------------------------------------------- */
	function initWidgetColorToggles() {
		var toggles = document.querySelectorAll('[data-luwi-color-toggle]');
		toggles.forEach(function (btn) {
			btn.addEventListener('click', function () {
				var current = document.documentElement.getAttribute('data-color-mode');
				var next;
				if (!current || current === '') {
					next = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'light' : 'dark';
				} else {
					next = current === 'dark' ? 'light' : 'dark';
				}
				document.documentElement.setAttribute('data-color-mode', next);
				localStorage.setItem('luwi-color-mode', next);
			});
		});
	}

	/* -----------------------------------------------------------------------
	 * Stitch: Lazy Image Reveal (fade-in on scroll)
	 * --------------------------------------------------------------------- */
	function initLazyReveal() {
		var images = document.querySelectorAll('.luwi-reveal, .woocommerce ul.products li.product');
		if (!images.length || !('IntersectionObserver' in window)) return;

		var observer = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				if (entry.isIntersecting) {
					entry.target.classList.add('luwi-revealed');
					observer.unobserve(entry.target);
				}
			});
		}, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });

		images.forEach(function (el) {
			el.classList.add('luwi-reveal-pending');
			observer.observe(el);
		});
	}

	/* -----------------------------------------------------------------------
	 * Smooth Scroll for Anchor Links
	 * Stitch: weighted easing, skip if user prefers reduced motion
	 * --------------------------------------------------------------------- */
	function initSmoothScroll() {
		document.addEventListener('click', function (e) {
			var link = e.target.closest('a[href^="#"]');
			if (!link) return;

			var hash = link.getAttribute('href');
			if (hash.length <= 1) return;

			var target = document.querySelector(hash);
			if (!target) return;

			// Skip smooth scroll if user prefers reduced motion
			if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

			e.preventDefault();
			var headerHeight = parseInt(
				getComputedStyle(document.documentElement).getPropertyValue('--luwi-header-height') || '72',
				10
			);

			var top = target.getBoundingClientRect().top + window.pageYOffset - headerHeight - 16;
			window.scrollTo({ top: top, behavior: 'smooth' });

			// Update URL hash without jump
			if (history.pushState) {
				history.pushState(null, null, hash);
			}
		});
	}

	/* -----------------------------------------------------------------------
	 * Quantity +/- Buttons (WooCommerce)
	 * Injects -/+ buttons around .qty inputs, Stitch-styled
	 * --------------------------------------------------------------------- */
	function initQuantityButtons() {
		var quantities = document.querySelectorAll('.woocommerce .quantity');
		if (!quantities.length) return;

		quantities.forEach(function (wrapper) {
			var input = wrapper.querySelector('.qty');
			if (!input || wrapper.querySelector('.luwi-qty-btn')) return;

			var min = parseInt(input.getAttribute('min') || '1', 10);
			var max = parseInt(input.getAttribute('max') || '9999', 10);
			var step = parseInt(input.getAttribute('step') || '1', 10);

			var btnMinus = document.createElement('button');
			btnMinus.type = 'button';
			btnMinus.className = 'luwi-qty-btn luwi-qty-btn--minus';
			btnMinus.textContent = '\u2212'; // minus sign
			btnMinus.setAttribute('aria-label', 'Decrease quantity');

			var btnPlus = document.createElement('button');
			btnPlus.type = 'button';
			btnPlus.className = 'luwi-qty-btn luwi-qty-btn--plus';
			btnPlus.textContent = '+';
			btnPlus.setAttribute('aria-label', 'Increase quantity');

			wrapper.insertBefore(btnMinus, input);
			wrapper.appendChild(btnPlus);

			btnMinus.addEventListener('click', function () {
				var val = parseInt(input.value, 10) || min;
				var newVal = Math.max(min, val - step);
				input.value = newVal;
				input.dispatchEvent(new Event('change', { bubbles: true }));
			});

			btnPlus.addEventListener('click', function () {
				var val = parseInt(input.value, 10) || min;
				var newVal = Math.min(max, val + step);
				input.value = newVal;
				input.dispatchEvent(new Event('change', { bubbles: true }));
			});
		});
	}

	/* -----------------------------------------------------------------------
	 * Product Gallery Keyboard Navigation
	 * Arrow keys cycle through WC gallery thumbnails
	 * --------------------------------------------------------------------- */
	function initGalleryKeyboard() {
		var gallery = document.querySelector('.woocommerce-product-gallery');
		if (!gallery) return;

		gallery.setAttribute('tabindex', '0');
		gallery.setAttribute('role', 'region');
		gallery.setAttribute('aria-label', 'Product gallery');

		gallery.addEventListener('keydown', function (e) {
			var thumbs = gallery.querySelectorAll('.flex-control-thumbs li img');
			if (!thumbs.length) return;

			if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
				e.preventDefault();
				var active = gallery.querySelector('.flex-control-thumbs li img.flex-active');
				var idx = Array.prototype.indexOf.call(thumbs, active);
				var next = thumbs[(idx + 1) % thumbs.length];
				if (next) next.click();
			} else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
				e.preventDefault();
				var active = gallery.querySelector('.flex-control-thumbs li img.flex-active');
				var idx = Array.prototype.indexOf.call(thumbs, active);
				var prev = thumbs[(idx - 1 + thumbs.length) % thumbs.length];
				if (prev) prev.click();
			}
		});
	}

	/* -----------------------------------------------------------------------
	 * Back to Top Button
	 * Appears after scrolling 600px, smooth scrolls to top
	 * --------------------------------------------------------------------- */
	function initBackToTop() {
		var btn = document.querySelector('.luwi-back-to-top');
		if (!btn) return;

		var observer = new IntersectionObserver(
			function (entries) {
				btn.classList.toggle('is-visible', !entries[0].isIntersecting);
			},
			{ rootMargin: '-600px 0px 0px 0px' }
		);

		var sentinel = document.createElement('div');
		sentinel.style.height = '1px';
		sentinel.setAttribute('aria-hidden', 'true');
		document.body.prepend(sentinel);
		observer.observe(sentinel);

		btn.addEventListener('click', function (e) {
			e.preventDefault();
			window.scrollTo({ top: 0, behavior: 'smooth' });
		});
	}

	/* -----------------------------------------------------------------------
	 * Accessibility: announce page transitions for screen readers
	 * --------------------------------------------------------------------- */
	function initA11y() {
		// Add aria-current to current menu items
		var currentItems = document.querySelectorAll('.current-menu-item > a');
		currentItems.forEach(function (link) {
			link.setAttribute('aria-current', 'page');
		});

		// Create live region for dynamic announcements (cart updates, etc.)
		if (!document.getElementById('luwi-live-region')) {
			var live = document.createElement('div');
			live.id = 'luwi-live-region';
			live.setAttribute('role', 'status');
			live.setAttribute('aria-live', 'polite');
			live.setAttribute('aria-atomic', 'true');
			live.className = 'screen-reader-text';
			document.body.appendChild(live);
		}

		// Announce WooCommerce AJAX add-to-cart success
		document.body.addEventListener('added_to_cart', function () {
			var live = document.getElementById('luwi-live-region');
			if (live) {
				live.textContent = 'Product added to cart';
			}
		});
	}

	/* -----------------------------------------------------------------------
	 * Initialize all on DOMContentLoaded
	 * --------------------------------------------------------------------- */
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	function init() {
		initColorMode();
		initMobileMenu();
		initStickyHeader();
		initCountdowns();
		initWidgetColorToggles();
		initLazyReveal();
		initSmoothScroll();
		initQuantityButtons();
		initGalleryKeyboard();
		initBackToTop();
		initA11y();
	}
})();
