<?php
/**
 * LuwiPress Schema Picker admin page
 *
 * Operator-facing UI over the Schema Registry's `aeo_save_schema` /
 * `aeo_get_schema` / `aeo_delete_schema` endpoints (shipped 3.4.0). FAQ
 * already has its own dedicated metabox; this page handles the other
 * seven types that previously had no UI surface:
 *
 *   - HowTo
 *   - Speakable
 *   - LocalBusiness
 *   - Service
 *   - Course
 *   - Review
 *   - AggregateRating
 *
 * Workflow: pick a post (or term) → see existing schemas → add/edit/delete.
 * Each type ships with a starter template the operator edits inline.
 *
 * @package LuwiPress
 * @since   3.5.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'luwipress' ) );
}

$rest_base  = esc_url_raw( rest_url( 'luwipress/v1/' ) );
$rest_nonce = wp_create_nonce( 'wp_rest' );

// Type catalog for the picker — slug, label, starter JSON template, brief
// help string. itemlist is auto-generated on category archives (no
// operator input) and FAQ has its own metabox, so both are excluded.
$type_catalog = array(
	'howto' => array(
		'label'    => 'HowTo',
		'help'     => __( 'Step-by-step instructions. Each step gets a name and text; optional images. Used for tutorials, recipes, assembly guides.', 'luwipress' ),
		'template' => array(
			'name'           => 'How to …',
			'description'    => 'Short summary of the process.',
			'totalTime'      => 'PT15M',
			'estimatedCost'  => array( '@type' => 'MonetaryAmount', 'currency' => 'USD', 'value' => '0' ),
			'supply'         => array( array( '@type' => 'HowToSupply', 'name' => 'Item 1' ) ),
			'tool'           => array( array( '@type' => 'HowToTool',   'name' => 'Tool 1' ) ),
			'step'           => array(
				array( '@type' => 'HowToStep', 'name' => 'Step 1', 'text' => 'Do the first thing.' ),
				array( '@type' => 'HowToStep', 'name' => 'Step 2', 'text' => 'Do the second thing.' ),
			),
		),
	),
	'speakable' => array(
		'label'    => 'Speakable',
		'help'     => __( 'CSS selectors that voice assistants should read aloud. Pin them to your lead paragraph / answer block.', 'luwipress' ),
		'template' => array(
			'cssSelector' => array( '.entry-title', '.entry-content > p:first-of-type' ),
		),
	),
	'localbusiness' => array(
		'label'    => 'LocalBusiness',
		'help'     => __( 'Physical-store / atelier with an address, opening hours, geo coordinates. Strong local-SEO signal.', 'luwipress' ),
		'template' => array(
			'name'        => 'Your business name',
			'image'       => 'https://example.com/storefront.jpg',
			'address'     => array(
				'@type'           => 'PostalAddress',
				'streetAddress'   => '',
				'addressLocality' => '',
				'addressRegion'   => '',
				'postalCode'      => '',
				'addressCountry'  => 'TR',
			),
			'geo'         => array( '@type' => 'GeoCoordinates', 'latitude' => 0, 'longitude' => 0 ),
			'telephone'   => '',
			'openingHoursSpecification' => array(
				array(
					'@type'     => 'OpeningHoursSpecification',
					'dayOfWeek' => array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday' ),
					'opens'     => '09:00',
					'closes'    => '18:00',
				),
			),
			'priceRange'  => '$$',
		),
	),
	'service' => array(
		'label'    => 'Service',
		'help'     => __( 'Standalone service offering — consulting, installation, custom builds. Links to provider + area-served.', 'luwipress' ),
		'template' => array(
			'name'        => 'Service name',
			'description' => 'Short summary of what the service includes.',
			'provider'    => array( '@type' => 'Organization', 'name' => 'Your business' ),
			'areaServed'  => array( '@type' => 'Country', 'name' => 'Turkey' ),
			'serviceType' => 'Custom build',
		),
	),
	'course' => array(
		'label'    => 'Course',
		'help'     => __( 'Educational offering — workshop, masterclass, online tutorial series.', 'luwipress' ),
		'template' => array(
			'name'        => 'Course title',
			'description' => 'What students learn.',
			'provider'    => array( '@type' => 'Organization', 'name' => 'Your business' ),
		),
	),
	'review' => array(
		'label'    => 'Review',
		'help'     => __( 'Author-written review of a product or item. ratingValue / bestRating drive the star display in Google.', 'luwipress' ),
		'template' => array(
			'itemReviewed' => array( '@type' => 'Product', 'name' => 'Product reviewed' ),
			'reviewRating' => array( '@type' => 'Rating', 'ratingValue' => 5, 'bestRating' => 5 ),
			'author'       => array( '@type' => 'Person', 'name' => 'Reviewer name' ),
			'reviewBody'   => 'Review prose.',
		),
	),
	'aggregaterating' => array(
		'label'    => 'AggregateRating',
		'help'     => __( 'Average rating across multiple reviews. Pair with Review schemas or attach standalone (e.g. on a Service page).', 'luwipress' ),
		'template' => array(
			'ratingValue' => 4.7,
			'bestRating'  => 5,
			'worstRating' => 1,
			'ratingCount' => 27,
		),
	),
);
?>
<?php $luwipress_hub_mode = defined( 'LUWIPRESS_HUB_INCLUDED' ); ?>
<?php if ( ! $luwipress_hub_mode ) : ?>
<div class="wrap luwipress-schema-picker">
<?php endif; ?>
	<?php if ( ! $luwipress_hub_mode ) : ?>
	<h1><span class="dashicons dashicons-category"></span> <?php esc_html_e( 'Schema Picker', 'luwipress' ); ?></h1>
	<?php endif; ?>
	<p class="lp-page-intro">
		<?php esc_html_e( 'Add Schema.org JSON-LD blocks (HowTo, LocalBusiness, Service, Review, Course, AggregateRating, Speakable) to any post or term. FAQ has its own dedicated metabox on the post edit screen. ItemList auto-generates on category archives.', 'luwipress' ); ?>
	</p>

	<!-- Object selector -->
	<div class="luwipress-card luwipress-card--primary">
		<h2><span class="dashicons dashicons-search" aria-hidden="true"></span> <?php esc_html_e( 'Pick target', 'luwipress' ); ?></h2>
		<div class="lwp-sp-search" role="combobox" aria-expanded="false" aria-haspopup="listbox" aria-owns="lwp-sp-results">
			<input class="lp-form-input lwp-sp-q" type="search" id="lwp-sp-q" autocomplete="off" role="searchbox" aria-autocomplete="list" aria-controls="lwp-sp-results" placeholder="<?php esc_attr_e( 'Search by title — product, page, post or category…', 'luwipress' ); ?>">
			<ul id="lwp-sp-results" class="lwp-sp-results" role="listbox" hidden></ul>
		</div>
		<div id="lwp-sp-chip" class="lwp-sp-chip-slot"></div>
		<div id="lwp-sp-target-info" class="lp-form-hint lwp-sp-target-info"></div>
	</div>

	<!-- Existing schemas -->
	<div class="luwipress-card" id="lwp-sp-existing-card" hidden>
		<h2><?php esc_html_e( 'Existing schemas on this target', 'luwipress' ); ?></h2>
		<div id="lwp-sp-existing-status" class="lp-form-hint"></div>
		<div id="lwp-sp-existing"></div>
	</div>

	<!-- Add new -->
	<div class="luwipress-card luwipress-card--info" id="lwp-sp-add-card" hidden>
		<h2><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> <?php esc_html_e( 'Add or replace schema', 'luwipress' ); ?></h2>
		<p class="lp-form-hint"><?php esc_html_e( 'Pick a schema type to start from a starter template, edit JSON, then save.', 'luwipress' ); ?></p>
		<div class="lwp-sp-type-row">
			<?php foreach ( $type_catalog as $slug => $cfg ) : ?>
			<button type="button" class="lp-btn lp-btn--outline lwp-sp-type-pick" data-slug="<?php echo esc_attr( $slug ); ?>">
				<?php echo esc_html( $cfg['label'] ); ?>
			</button>
			<?php endforeach; ?>
		</div>
		<div id="lwp-sp-editor" class="lwp-sp-editor" hidden>
			<div class="lwp-sp-editor-head">
				<strong id="lwp-sp-editor-title" class="lwp-sp-editor-title"></strong>
				<button type="button" class="lp-btn lp-btn--ghost lp-btn--sm" id="lwp-sp-reset-template">
					<span class="dashicons dashicons-undo" aria-hidden="true"></span>
					<?php esc_html_e( 'Reset to template', 'luwipress' ); ?>
				</button>
			</div>
			<p id="lwp-sp-editor-help" class="lp-form-hint"></p>
			<div class="lp-form-row">
				<textarea id="lwp-sp-editor-json" class="lp-form-textarea lwp-sp-editor-json" rows="20"></textarea>
			</div>
			<div class="lp-btn-row">
				<button class="lp-btn lp-btn--primary" id="lwp-sp-save">
					<span class="dashicons dashicons-saved" aria-hidden="true"></span>
					<?php esc_html_e( 'Save schema', 'luwipress' ); ?>
				</button>
				<button class="lp-btn lp-btn--outline" id="lwp-sp-validate">
					<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
					<?php esc_html_e( 'Validate JSON', 'luwipress' ); ?>
				</button>
				<span id="lwp-sp-status" class="lp-form-hint"></span>
			</div>
		</div>
	</div>

	<!-- Reference card (collapsible — pure static docs, default-collapsed to declutter) -->
	<details class="lp-collapse lwp-sp-reference">
		<summary>
			<span class="dashicons dashicons-book" aria-hidden="true"></span>
			<span><?php esc_html_e( 'Reference', 'luwipress' ); ?></span>
			<span class="lp-collapse-hint"><?php esc_html_e( 'schema.org docs', 'luwipress' ); ?></span>
		</summary>
		<div class="lp-collapse-body">
		<p class="lp-form-hint"><?php esc_html_e( 'Documentation links per schema type — pin alongside the editor when filling unfamiliar fields:', 'luwipress' ); ?></p>
		<ul class="lwp-sp-ref-list">
			<li><strong>HowTo</strong> — <a href="https://schema.org/HowTo" target="_blank" rel="noopener">schema.org/HowTo</a></li>
			<li><strong>Speakable</strong> — <a href="https://schema.org/SpeakableSpecification" target="_blank" rel="noopener">schema.org/SpeakableSpecification</a></li>
			<li><strong>LocalBusiness</strong> — <a href="https://schema.org/LocalBusiness" target="_blank" rel="noopener">schema.org/LocalBusiness</a></li>
			<li><strong>Service</strong> — <a href="https://schema.org/Service" target="_blank" rel="noopener">schema.org/Service</a></li>
			<li><strong>Course</strong> — <a href="https://schema.org/Course" target="_blank" rel="noopener">schema.org/Course</a></li>
			<li><strong>Review</strong> — <a href="https://schema.org/Review" target="_blank" rel="noopener">schema.org/Review</a></li>
			<li><strong>AggregateRating</strong> — <a href="https://schema.org/AggregateRating" target="_blank" rel="noopener">schema.org/AggregateRating</a></li>
		</ul>
		<p class="lp-form-hint">
			<?php esc_html_e( 'After saving, use', 'luwipress' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=luwipress-site&tab=schema' ) ); ?>"><?php esc_html_e( 'Schema Preview', 'luwipress' ); ?></a>
			<?php esc_html_e( 'to confirm the JSON-LD block renders on the live URL, then', 'luwipress' ); ?>
			<a href="https://search.google.com/test/rich-results" target="_blank" rel="noopener"><?php esc_html_e( 'Google Rich Results Test', 'luwipress' ); ?></a>
			<?php esc_html_e( 'to validate it.', 'luwipress' ); ?>
		</p>
		</div><!-- .lp-collapse-body -->
	</details>
<?php if ( ! $luwipress_hub_mode ) : ?>
</div>
<?php endif; ?>

<style>
.lwp-sp-search { position: relative; max-width: 560px; margin: 10px 0 0; }
.lwp-sp-q { width: 100%; }
.lwp-sp-results {
	position: absolute; z-index: 30; left: 0; right: 0; top: calc(100% + 2px);
	margin: 0; padding: 4px; list-style: none;
	background: var(--lp-surface, #fff); border: 1px solid var(--lp-border); border-radius: 8px;
	box-shadow: 0 6px 24px rgba(0,0,0,.12); max-height: 320px; overflow: auto;
}
.lwp-sp-result { display: flex; align-items: baseline; gap: 8px; padding: 8px 10px; border-radius: 6px; cursor: pointer; }
.lwp-sp-result:hover { background: var(--lp-surface-hover); }
.lwp-sp-result--empty { color: var(--lp-gray); cursor: default; }
.lwp-sp-result-title { font-weight: 500; }
.lwp-sp-result-meta { color: var(--lp-gray); font-size: 11.5px; }
.lwp-sp-chip-slot { margin-top: 10px; }
.lwp-sp-chip {
	display: inline-flex; align-items: center; gap: 8px;
	padding: 6px 10px; border-radius: 999px;
	background: var(--lp-surface-hover); border: 1px solid var(--lp-border); font-size: 13px;
}
.lwp-sp-chip-label { font-weight: 600; color: var(--lp-text); }
.lwp-sp-chip-meta { color: var(--lp-gray); font-size: 11.5px; }
.lwp-sp-chip-x { border: 0; background: none; cursor: pointer; font-size: 16px; line-height: 1; color: var(--lp-gray); padding: 0 2px; }
.lwp-sp-chip-x:hover { color: var(--lp-error, #c33); }
.lwp-sp-target-info { margin-top: 10px; }

.lwp-sp-type-row {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
	margin: 10px 0 14px;
}
.lwp-sp-type-pick.is-active {
	background: var(--lp-primary);
	color: #fff;
	border-color: var(--lp-primary);
}
.lwp-sp-type-pick.is-active:hover {
	background: var(--lp-primary-dark);
	color: #fff;
	border-color: var(--lp-primary-dark);
}

.lwp-sp-editor { margin-top: 4px; }
.lwp-sp-editor-head {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 6px;
	gap: 12px;
	flex-wrap: wrap;
}
.lwp-sp-editor-title { font-size: 15px; color: var(--lp-text); }
.lwp-sp-editor-json {
	font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
	font-size: 12px;
	line-height: 1.5;
	min-height: 280px;
}

.lwp-sp-existing-row {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 10px 12px;
	border: 1px solid var(--lp-border);
	border-radius: 6px;
	margin-bottom: 8px;
	background: var(--lp-surface-secondary);
}
.lwp-sp-existing-row code {
	background: var(--lp-gray-100);
	padding: 2px 6px;
	border-radius: 3px;
	font-size: 12px;
}

.lwp-sp-ref-list { line-height: 2; margin: 6px 0 8px; }
</style>

<script>
(function () {
	'use strict';
	var REST_BASE   = <?php echo wp_json_encode( $rest_base ); ?>;
	var REST_NONCE  = <?php echo wp_json_encode( $rest_nonce ); ?>;
	var TYPE_CAT    = <?php echo wp_json_encode( $type_catalog ); ?>;
	var WP_ROOT     = <?php echo wp_json_encode( esc_url_raw( rest_url( 'wp/v2/' ) ) ); ?>;

	function api(path, opts) {
		opts = opts || {};
		opts.headers = opts.headers || {};
		opts.headers['X-WP-Nonce'] = REST_NONCE;
		opts.headers['Accept'] = 'application/json';
		if (opts.body && typeof opts.body !== 'string') {
			opts.headers['Content-Type'] = 'application/json';
			opts.body = JSON.stringify(opts.body);
		}
		return fetch(REST_BASE + path, opts).then(function (r) {
			return r.json().then(function (j) {
				if (!r.ok) throw new Error((j && (j.message || j.code)) || ('HTTP ' + r.status));
				return j;
			});
		});
	}

	var qInput     = document.getElementById('lwp-sp-q');
	var resultsEl  = document.getElementById('lwp-sp-results');
	var chipSlot   = document.getElementById('lwp-sp-chip');
	var searchWrap = qInput ? qInput.closest('.lwp-sp-search') : null;
	var targetInfo = document.getElementById('lwp-sp-target-info');
	var existingCard = document.getElementById('lwp-sp-existing-card');
	var existingDiv  = document.getElementById('lwp-sp-existing');
	var existingStat = document.getElementById('lwp-sp-existing-status');
	var addCard     = document.getElementById('lwp-sp-add-card');
	var editor      = document.getElementById('lwp-sp-editor');
	var editorTitle = document.getElementById('lwp-sp-editor-title');
	var editorHelp  = document.getElementById('lwp-sp-editor-help');
	var editorJson  = document.getElementById('lwp-sp-editor-json');
	var saveBtn     = document.getElementById('lwp-sp-save');
	var validateBtn = document.getElementById('lwp-sp-validate');
	var resetBtn    = document.getElementById('lwp-sp-reset-template');
	var statusEl    = document.getElementById('lwp-sp-status');
	var typePicks   = document.querySelectorAll('.lwp-sp-type-pick');

	var currentTarget = null;  // { object_type, object_id, label }
	var currentType   = null;  // schema type slug

	function setStatus(msg, color) {
		statusEl.textContent = msg || '';
		statusEl.style.color = color || '#666';
	}

	var searchTimer = null;

	// Reads currentTarget (set by selectTarget). The save/get/delete REST
	// contract downstream is unchanged — only how object_type+object_id get
	// chosen moved from raw inputs to the typeahead.
	function loadTarget() {
		if (!currentTarget) { return; }
		var ot = currentTarget.object_type;
		var id = currentTarget.object_id;
		targetInfo.textContent = 'Loading…';
		targetInfo.style.color = '#666';

		var query = 'aeo/get-schema?object_type=' + encodeURIComponent(ot) + '&object_id=' + id;
		api(query).then(function (j) {
			targetInfo.innerHTML = 'Loaded <code>' + ot + ' #' + id + '</code> — ' + (j.count || 0) + ' existing schemas.';
			targetInfo.style.color = '#2c7a2c';
			existingCard.hidden = false;
			addCard.hidden = false;
			renderExisting(j.schemas || {});
		}).catch(function (e) {
			targetInfo.textContent = 'Load failed: ' + e.message;
			targetInfo.style.color = '#c33';
		});
	}

	function closeResults() {
		resultsEl.hidden = true;
		resultsEl.innerHTML = '';
		if (searchWrap) { searchWrap.setAttribute('aria-expanded', 'false'); }
	}

	function selectTarget(ot, id, label) {
		currentTarget = { object_type: ot, object_id: id, label: label };
		renderChip();
		qInput.value = '';
		closeResults();
		loadTarget();
	}

	function renderChip() {
		chipSlot.innerHTML = '';
		if (!currentTarget) { return; }
		var c = currentTarget;
		var chip = document.createElement('span');
		chip.className = 'lwp-sp-chip';
		chip.innerHTML = '<span class="lwp-sp-chip-label"></span> <span class="lwp-sp-chip-meta"></span>' +
			'<button type="button" class="lwp-sp-chip-x" aria-label="Clear selection">&times;</button>';
		chip.querySelector('.lwp-sp-chip-label').textContent = c.label || (c.object_type + ' #' + c.object_id);
		chip.querySelector('.lwp-sp-chip-meta').textContent = c.object_type + ' #' + c.object_id;
		chip.querySelector('.lwp-sp-chip-x').addEventListener('click', function () {
			currentTarget = null;
			chipSlot.innerHTML = '';
			existingCard.hidden = true;
			addCard.hidden = true;
			targetInfo.textContent = '';
			qInput.focus();
		});
		chipSlot.appendChild(chip);
	}

	function wpSearch(type, q) {
		var url = WP_ROOT + 'search?per_page=8&search=' + encodeURIComponent(q) + (type === 'term' ? '&type=term&subtype=any' : '');
		return fetch(url, { headers: { 'X-WP-Nonce': REST_NONCE, 'Accept': 'application/json' } })
			.then(function (r) { return r.ok ? r.json() : []; })
			.catch(function () { return []; });
	}

	function searchTargets(q) {
		if (!q || q.length < 2) { closeResults(); return; }
		Promise.all([wpSearch('post', q), wpSearch('term', q)]).then(function (res) {
			renderResults([].concat(res[0] || [], res[1] || []));
		});
	}

	function renderResults(rows) {
		resultsEl.innerHTML = '';
		if (!rows.length) {
			resultsEl.innerHTML = '<li class="lwp-sp-result lwp-sp-result--empty" role="option">No matches.</li>';
			resultsEl.hidden = false;
			if (searchWrap) { searchWrap.setAttribute('aria-expanded', 'true'); }
			return;
		}
		rows.slice(0, 12).forEach(function (row) {
			var ot = (row.type === 'term') ? 'term' : 'post';
			var li = document.createElement('li');
			li.className = 'lwp-sp-result';
			li.setAttribute('role', 'option');
			li.innerHTML = '<span class="lwp-sp-result-title"></span> <small class="lwp-sp-result-meta"></small>';
			li.querySelector('.lwp-sp-result-title').textContent = (row.title || '(no title)');
			li.querySelector('.lwp-sp-result-meta').textContent = (row.subtype || ot) + ' · #' + row.id;
			li.addEventListener('click', function () {
				selectTarget(ot, row.id, row.title || (ot + ' #' + row.id));
			});
			resultsEl.appendChild(li);
		});
		resultsEl.hidden = false;
		if (searchWrap) { searchWrap.setAttribute('aria-expanded', 'true'); }
	}

	function renderExisting(schemas) {
		existingDiv.innerHTML = '';
		var keys = Object.keys(schemas || {});
		if (!keys.length) {
			existingStat.textContent = 'No schemas attached yet — use the Add panel below to create one.';
			existingStat.style.color = '#a86b00';
			return;
		}
		existingStat.textContent = keys.length + ' schema(s) attached.';
		existingStat.style.color = '#666';
		keys.forEach(function (slug) {
			var row = document.createElement('div');
			row.className = 'lwp-sp-existing-row';
			var meta = TYPE_CAT[slug] || { label: slug };
			row.innerHTML =
				'<div><strong>' + (meta.label || slug) + '</strong> ' +
				'<code>' + slug + '</code></div>' +
				'<div>' +
				'  <button type="button" class="button button-small lwp-sp-row-edit" data-slug="' + slug + '">Edit</button> ' +
				'  <button type="button" class="button button-small button-link-delete lwp-sp-row-delete" data-slug="' + slug + '">Delete</button>' +
				'</div>';
			existingDiv.appendChild(row);
			row.querySelector('.lwp-sp-row-edit').addEventListener('click', function () {
				openEditor(slug, schemas[slug]);
			});
			row.querySelector('.lwp-sp-row-delete').addEventListener('click', function () {
				if (!confirm('Delete the ' + (meta.label || slug) + ' schema from this object? This cannot be undone.')) return;
				deleteSchema(slug);
			});
		});
	}

	function openEditor(slug, existingData) {
		currentType = slug;
		var cfg = TYPE_CAT[slug] || { label: slug, help: '', template: {} };
		editorTitle.textContent = cfg.label;
		editorHelp.textContent = cfg.help || '';
		var data = existingData != null ? existingData : cfg.template;
		try {
			editorJson.value = JSON.stringify(data, null, 2);
		} catch (e) {
			editorJson.value = '{}';
		}
		editor.hidden = false;
		setStatus('');
		typePicks.forEach(function (p) {
			p.classList.toggle('is-active', p.getAttribute('data-slug') === slug);
		});
	}

	function deleteSchema(slug) {
		if (!currentTarget) return;
		setStatus('Deleting ' + slug + '…');
		api('aeo/delete-schema?object_type=' + encodeURIComponent(currentTarget.object_type) +
			'&object_id=' + currentTarget.object_id +
			'&schema_type=' + encodeURIComponent(slug),
			{ method: 'DELETE' }
		).then(function () {
			setStatus(slug + ' deleted.', '#2c7a2c');
			loadTarget();
		}).catch(function (e) {
			setStatus('Delete failed: ' + e.message, '#c33');
		});
	}

	function validateJson() {
		try {
			JSON.parse(editorJson.value);
			setStatus('JSON valid.', '#2c7a2c');
			return true;
		} catch (e) {
			setStatus('Invalid JSON: ' + e.message, '#c33');
			return false;
		}
	}

	function saveSchema() {
		if (!currentTarget || !currentType) {
			setStatus('Pick a target + type first.', '#c33');
			return;
		}
		var parsed;
		try {
			parsed = JSON.parse(editorJson.value);
		} catch (e) {
			setStatus('Invalid JSON: ' + e.message, '#c33');
			return;
		}
		setStatus('Saving…');
		api('aeo/save-schema', {
			method: 'POST',
			body: {
				object_type: currentTarget.object_type,
				object_id:   currentTarget.object_id,
				schema_type: currentType,
				data:        parsed,
			}
		}).then(function () {
			setStatus('Saved.', '#2c7a2c');
			loadTarget();
		}).catch(function (e) {
			setStatus('Save failed: ' + e.message, '#c33');
		});
	}

	qInput.addEventListener('input', function () {
		clearTimeout(searchTimer);
		var q = qInput.value.trim();
		searchTimer = setTimeout(function () { searchTargets(q); }, 250);
	});
	qInput.addEventListener('keydown', function (e) {
		if (e.key === 'Escape') { closeResults(); }
		else if (e.key === 'Enter') {
			e.preventDefault();
			var first = resultsEl.querySelector('.lwp-sp-result:not(.lwp-sp-result--empty)');
			if (first) { first.click(); }
		}
	});
	document.addEventListener('click', function (e) {
		if (searchWrap && !searchWrap.contains(e.target)) { closeResults(); }
	});
	saveBtn.addEventListener('click', saveSchema);
	validateBtn.addEventListener('click', validateJson);
	resetBtn.addEventListener('click', function () {
		if (!currentType) return;
		var cfg = TYPE_CAT[currentType];
		if (cfg) {
			editorJson.value = JSON.stringify(cfg.template, null, 2);
			setStatus('Template reset.', '#666');
		}
	});
	typePicks.forEach(function (p) {
		p.addEventListener('click', function () {
			openEditor(p.getAttribute('data-slug'), null);
		});
	});
})();
</script>
