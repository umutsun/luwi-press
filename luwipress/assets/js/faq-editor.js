/**
 * LuwiPress FAQ Editor — metabox JS controller.
 *
 * Vanilla DOM API only (no jQuery dependency) so the editor keeps loading
 * if the host site disables WP's bundled jQuery or swaps in a slim core.
 * Designed to be additive to the standard WP post-save flow: every row's
 * <input> / <textarea> carries `name="luwipress_faq_q[]"` / `_a[]` so
 * when the editor or "Update" button fires, the row pairs land in $_POST
 * exactly as the PHP save handler expects.
 *
 * Responsibilities:
 *   - Add / remove / reorder rows
 *   - Live word count per answer
 *   - "Generate with AI" — triggers the existing /aeo/generate-faq async
 *     pipeline, then nudges the operator to reload (no inline polling —
 *     keeping the contract simple beats a flaky live-refresh)
 *   - Single source of truth: row index is implicit (position in DOM)
 *
 * Note: this script never writes to the post meta directly. It only
 * shapes the form so WP's post-save mechanism handles persistence.
 *
 * @package LuwiPress
 * @since   3.5.5
 */
(function () {
	'use strict';

	var CFG = window.LuwiPressFAQEditor || {};
	var I18N = CFG.i18n || {};

	function $(sel, root) { return (root || document).querySelector(sel); }
	function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

	function init() {
		var editor = $('.lwp-faq-editor');
		if (!editor) return;

		var rowsContainer = editor.querySelector('[data-bind="rows"]');
		if (!rowsContainer) return;

		bindRowEvents(editor);
		updateRowNumbers(editor);
		updateRowCount(editor);
		bindAddButton(editor);
		bindAIButton(editor);
		bindWordCount(editor);
	}

	// ─── ROW MANAGEMENT ────────────────────────────────────────────────

	function bindRowEvents(editor) {
		var rowsContainer = editor.querySelector('[data-bind="rows"]');
		rowsContainer.addEventListener('click', function (e) {
			var btn = e.target.closest('button[data-bind]');
			if (!btn) return;
			var row = btn.closest('[data-bind="row"]');
			if (!row) return;
			var action = btn.getAttribute('data-bind');

			if (action === 'remove') {
				removeRow(editor, row);
			} else if (action === 'move-up') {
				moveRow(editor, row, -1);
			} else if (action === 'move-down') {
				moveRow(editor, row, 1);
			}
		});
	}

	function removeRow(editor, row) {
		// Only confirm if the row has content — empty rows can be nuked
		// silently for a snappier editor feel.
		var q = row.querySelector('[data-bind="question"]');
		var a = row.querySelector('[data-bind="answer"]');
		var hasContent = (q && q.value.trim() !== '') || (a && a.value.trim() !== '');
		if (hasContent && !window.confirm(I18N.confirmClear || 'Remove this question?')) {
			return;
		}

		var rows = $$('[data-bind="row"]', editor);
		if (rows.length <= 1) {
			// Always keep at least one empty row available so the editor
			// never collapses to "nothing to type in".
			if (q) q.value = '';
			if (a) a.value = '';
			updateWordCount(row);
		} else {
			row.parentNode.removeChild(row);
		}

		updateRowNumbers(editor);
		updateRowCount(editor);
	}

	function moveRow(editor, row, dir) {
		var rows = $$('[data-bind="row"]', editor);
		var idx = rows.indexOf(row);
		if (idx === -1) return;

		var target = dir < 0 ? idx - 1 : idx + 1;
		if (target < 0 || target >= rows.length) return;

		var sibling = rows[target];
		if (dir < 0) {
			sibling.parentNode.insertBefore(row, sibling);
		} else {
			sibling.parentNode.insertBefore(row, sibling.nextSibling);
		}
		updateRowNumbers(editor);
	}

	function addRow(editor) {
		var rowsContainer = editor.querySelector('[data-bind="rows"]');
		var rows          = $$('[data-bind="row"]', editor);
		var template      = rows[0]; // First row is the template — clone with reset content.
		if (!template) return;

		var clone = template.cloneNode(true);
		var q = clone.querySelector('[data-bind="question"]');
		var a = clone.querySelector('[data-bind="answer"]');
		if (q) q.value = '';
		if (a) a.value = '';

		// Make sure IDs are unique even though only `name` attributes
		// matter for $_POST — clean DOM aids accessibility.
		var newIdx = rows.length;
		if (q && q.id) { q.id = 'lwp-faq-q-' + newIdx; }
		if (a && a.id) { a.id = 'lwp-faq-a-' + newIdx; }

		rowsContainer.appendChild(clone);
		updateRowNumbers(editor);
		updateWordCount(clone);
		updateRowCount(editor);

		// Focus the new question input so the operator can start typing
		// without an extra click.
		if (q) q.focus();
	}

	function bindAddButton(editor) {
		var btn = editor.querySelector('[data-bind="add-row"]');
		if (!btn) return;
		btn.addEventListener('click', function () { addRow(editor); });
	}

	function updateRowNumbers(editor) {
		$$('[data-bind="row"]', editor).forEach(function (row, i) {
			var n = row.querySelector('[data-bind="row-num"]');
			if (n) n.textContent = String(i + 1);
		});
	}

	function updateRowCount(editor) {
		var count = $$('[data-bind="row"]', editor).filter(function (row) {
			var q = row.querySelector('[data-bind="question"]');
			return q && q.value.trim() !== '';
		}).length;
		var label = editor.querySelector('[data-bind="row-count"]');
		if (label) {
			// Use a singular/plural pair the WP i18n machinery would have
			// translated — we don't have access to _n() at runtime so this
			// is a soft fallback. Reasonable enough for what the count says.
			label.textContent = count === 1 ? '1 question' : (count + ' questions');
		}
	}

	// ─── WORD COUNT ────────────────────────────────────────────────────

	function bindWordCount(editor) {
		editor.addEventListener('input', function (e) {
			if (e.target && e.target.matches('[data-bind="answer"]')) {
				var row = e.target.closest('[data-bind="row"]');
				if (row) updateWordCount(row);
			}
			if (e.target && e.target.matches('[data-bind="question"]')) {
				updateRowCount(editor);
			}
		});
		$$('[data-bind="row"]', editor).forEach(updateWordCount);
	}

	function updateWordCount(row) {
		var a = row.querySelector('[data-bind="answer"]');
		var meta = row.querySelector('[data-bind="word-count"]');
		if (!a || !meta) return;

		var text = (a.value || '').trim();
		if (!text) {
			meta.textContent = '—';
			meta.removeAttribute('data-band');
			return;
		}
		// Use the same word-boundary heuristic as the PHP word counter so
		// the inline indicator and the Health Score Content Depth pillar
		// agree to within a token or two. Accented chars + hyphenated
		// words count as one each.
		var words = text.split(/\s+/).filter(Boolean).length;

		// Soft band hints — green inside 50-80, amber 30-49 or 81-110,
		// red outside.
		var band = 'bad';
		if (words >= 50 && words <= 80) band = 'good';
		else if ((words >= 30 && words < 50) || (words > 80 && words <= 110)) band = 'warn';

		meta.textContent = words + ' words';
		meta.setAttribute('data-band', band);
	}

	// ─── AI GENERATION ─────────────────────────────────────────────────

	function bindAIButton(editor) {
		var btn = editor.querySelector('[data-bind="ai-generate"]');
		if (!btn) return;

		btn.addEventListener('click', function () {
			var postId = parseInt(editor.getAttribute('data-post-id'), 10);
			var isProduct = editor.getAttribute('data-is-product') === '1';
			var statusEl  = editor.querySelector('[data-bind="ai-status"]');

			if (!isProduct) {
				showStatus(statusEl, I18N.aiOnlyWoo || 'AI generation is only available for WooCommerce products.', 'warn');
				return;
			}
			if (!postId) {
				return;
			}

			btn.disabled = true;
			showStatus(statusEl, '⏳ ' + (I18N.aiQueued || 'AI generation queued…'), 'info');

			var url = CFG.restBase + 'aeo/generate-faq';
			fetch(url, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce':   CFG.restNonce,
					'Accept':       'application/json'
				},
				credentials: 'same-origin',
				body: JSON.stringify({ product_id: postId })
			})
				.then(function (r) {
					if (!r.ok) { throw new Error('HTTP ' + r.status); }
					return r.json();
				})
				.then(function (data) {
					if (data && data.status === 'queued') {
						showStatus(statusEl, '✓ ' + (I18N.aiQueued || 'Queued — reload the page in ~30s to see results.'), 'info');
					} else if (data && data.status === 'completed') {
						showStatus(statusEl, '✓ ' + (I18N.aiCompleted || 'AI generation completed. Reload to see results.'), 'good');
					} else {
						showStatus(statusEl, '⚠ ' + JSON.stringify(data), 'warn');
					}
				})
				.catch(function (err) {
					showStatus(statusEl, '✗ ' + (I18N.aiFailed || 'AI generation failed') + ': ' + err.message, 'bad');
				})
				.then(function () {
					// Re-enable after a short cooldown so the operator can
					// retry if needed but doesn't accidentally double-fire.
					setTimeout(function () { btn.disabled = false; }, 3000);
				});
		});
	}

	function showStatus(el, msg, band) {
		if (!el) return;
		el.textContent = msg;
		el.style.display = '';
		el.setAttribute('data-band', band || 'info');
	}

	// Boot — DOMContentLoaded if the metabox is already mounted, otherwise
	// fall back to immediate run (Gutenberg loads later).
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
