<?php
/**
 * LuwiPress Image Alt Bulk admin page
 *
 * Scans every image attachment in the Media Library and surfaces the ones
 * missing alt text. Operator edits inline, clicks Save All, and 500 rows
 * fan into a single `/media/alt-bulk` REST POST.
 *
 * Why this page exists: WordPress's native Media Library makes alt-text
 * editing a per-attachment modal pop. With a store carrying ~300+ product
 * images, a brand-voice or GMC sweep means opening every item and editing
 * one at a time. WebMCP customers can script this; non-WebMCP customers
 * can't. This page is their parity surface.
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

// Initial scan filter — default to "missing" so the page opens directly on
// the work that needs doing. Operator can flip to "all" via filter pills.
$filter = isset( $_GET['filter'] ) ? sanitize_key( $_GET['filter'] ) : 'missing';
if ( ! in_array( $filter, array( 'missing', 'has_alt', 'all' ), true ) ) {
	$filter = 'missing';
}

$per_page = isset( $_GET['per_page'] ) ? max( 10, min( 200, absint( $_GET['per_page'] ) ) ) : 50;
$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

// Build the scan query. We default-sort newest-first so recently uploaded
// images (most likely to lack alt text) bubble to the top.
$args = array(
	'post_type'      => 'attachment',
	'post_status'    => 'inherit',
	'post_mime_type' => 'image',
	'posts_per_page' => $per_page,
	'paged'          => $paged,
	'orderby'        => 'date',
	'order'          => 'DESC',
);
if ( $search !== '' ) {
	$args['s'] = $search;
}

// Filter "missing" / "has_alt" via meta_query — single-key lookup is fast
// thanks to the existing `wp_postmeta.meta_key` index.
if ( $filter === 'missing' ) {
	$args['meta_query'] = array(
		'relation' => 'OR',
		array( 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ),
		array( 'key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '=' ),
	);
} elseif ( $filter === 'has_alt' ) {
	$args['meta_query'] = array(
		array( 'key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '!=' ),
	);
}

$q     = new WP_Query( $args );
$total = (int) $q->found_posts;

// Counts for the filter pills — independent quick queries.
$count_args = array(
	'post_type'      => 'attachment',
	'post_status'    => 'inherit',
	'post_mime_type' => 'image',
	'posts_per_page' => 1,
	'no_found_rows'  => false,
);
$total_all      = (int) ( new WP_Query( $count_args + array( 'fields' => 'ids' ) ) )->found_posts;
$total_missing  = (int) ( new WP_Query( $count_args + array( 'fields' => 'ids', 'meta_query' => array(
	'relation' => 'OR',
	array( 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ),
	array( 'key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '=' ),
) ) ) )->found_posts;
$total_has_alt  = max( 0, $total_all - $total_missing );

// Pre-shape the items for render so the template stays simple.
$items = array();
foreach ( $q->posts as $att ) {
	$thumb  = wp_get_attachment_image_url( $att->ID, array( 80, 80 ) );
	$alt    = (string) get_post_meta( $att->ID, '_wp_attachment_image_alt', true );
	$parent_title = $att->post_parent ? get_the_title( $att->post_parent ) : '';
	$parent_edit  = $att->post_parent ? get_edit_post_link( $att->post_parent, '' ) : '';
	$items[] = array(
		'id'           => $att->ID,
		'title'        => $att->post_title,
		'filename'     => wp_basename( get_attached_file( $att->ID ) ?: '' ),
		'thumb'        => $thumb,
		'alt'          => $alt,
		'parent_id'    => $att->post_parent,
		'parent_title' => $parent_title,
		'parent_edit'  => $parent_edit,
		'date'         => $att->post_date,
	);
}

$total_pages = max( 1, (int) $q->max_num_pages );
$base_url    = add_query_arg( array( 'page' => 'luwipress-image-alt-bulk' ), admin_url( 'admin.php' ) );
?>
<?php $luwipress_hub_mode = defined( 'LUWIPRESS_HUB_INCLUDED' ); ?>
<?php if ( ! $luwipress_hub_mode ) : ?>
<div class="wrap luwipress-image-alt-bulk">
<?php endif; ?>
	<?php if ( ! $luwipress_hub_mode ) : ?>
	<h1><span class="dashicons dashicons-format-image"></span> <?php esc_html_e( 'Image Alt Bulk', 'luwipress' ); ?></h1>
	<?php endif; ?>
	<p class="lp-page-intro">
		<?php esc_html_e( 'Scan every image in your Media Library, fill in alt text inline, and save 50+ rows in one click. Missing-alt images are highlighted on first load. Use the "From parent title" button to fast-fill alt with the post/product title.', 'luwipress' ); ?>
	</p>

	<!-- Hero stat row -->
	<?php $cov = $total_all > 0 ? round( ( $total_has_alt / $total_all ) * 100 ) : 0; ?>
	<div class="lp-stat-row lwp-ab-stats">
		<div class="lp-stat lp-stat--info">
			<div class="lp-stat-label"><?php esc_html_e( 'Total images', 'luwipress' ); ?></div>
			<div class="lp-stat-value"><?php echo esc_html( number_format_i18n( $total_all ) ); ?></div>
		</div>
		<div class="lp-stat <?php echo $total_missing > 0 ? 'lp-stat--error' : 'lp-stat--success'; ?>">
			<div class="lp-stat-label"><?php esc_html_e( 'Missing alt', 'luwipress' ); ?></div>
			<div class="lp-stat-value <?php echo $total_missing > 0 ? 'lp-stat-value--error' : 'lp-stat-value--success'; ?>">
				<?php echo esc_html( number_format_i18n( $total_missing ) ); ?>
			</div>
		</div>
		<div class="lp-stat lp-stat--success">
			<div class="lp-stat-label"><?php esc_html_e( 'Has alt', 'luwipress' ); ?></div>
			<div class="lp-stat-value"><?php echo esc_html( number_format_i18n( $total_has_alt ) ); ?></div>
		</div>
		<div class="lp-stat <?php echo $cov >= 80 ? 'lp-stat--success' : ( $cov >= 50 ? 'lp-stat--warning' : 'lp-stat--error' ); ?>">
			<div class="lp-stat-label"><?php esc_html_e( 'Coverage', 'luwipress' ); ?></div>
			<div class="lp-stat-value <?php echo $cov >= 80 ? 'lp-stat-value--success' : ( $cov >= 50 ? 'lp-stat-value--warning' : 'lp-stat-value--error' ); ?>">
				<?php echo esc_html( (string) $cov ); ?>%
			</div>
		</div>
	</div>

	<!-- Filter + search toolbar -->
	<div class="luwipress-card lwp-ab-toolbar">
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="lwp-ab-filter-form">
			<input type="hidden" name="page" value="luwipress-image-alt-bulk">
			<input type="hidden" name="filter" value="<?php echo esc_attr( $filter ); ?>">
			<div class="lwp-ab-filter-pills">
				<a class="lp-btn lp-btn--sm <?php echo $filter === 'missing' ? 'lp-btn--primary' : 'lp-btn--outline'; ?>"
				   href="<?php echo esc_url( add_query_arg( array( 'filter' => 'missing', 's' => $search ), $base_url ) ); ?>">
					<?php esc_html_e( 'Missing', 'luwipress' ); ?>
					<span class="lwp-ab-filter-count">(<?php echo esc_html( number_format_i18n( $total_missing ) ); ?>)</span>
				</a>
				<a class="lp-btn lp-btn--sm <?php echo $filter === 'has_alt' ? 'lp-btn--primary' : 'lp-btn--outline'; ?>"
				   href="<?php echo esc_url( add_query_arg( array( 'filter' => 'has_alt', 's' => $search ), $base_url ) ); ?>">
					<?php esc_html_e( 'Has alt', 'luwipress' ); ?>
					<span class="lwp-ab-filter-count">(<?php echo esc_html( number_format_i18n( $total_has_alt ) ); ?>)</span>
				</a>
				<a class="lp-btn lp-btn--sm <?php echo $filter === 'all' ? 'lp-btn--primary' : 'lp-btn--outline'; ?>"
				   href="<?php echo esc_url( add_query_arg( array( 'filter' => 'all', 's' => $search ), $base_url ) ); ?>">
					<?php esc_html_e( 'All', 'luwipress' ); ?>
					<span class="lwp-ab-filter-count">(<?php echo esc_html( number_format_i18n( $total_all ) ); ?>)</span>
				</a>
			</div>
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>"
			       placeholder="<?php esc_attr_e( 'Search filename / title…', 'luwipress' ); ?>"
			       class="lp-form-input lwp-ab-search">
			<button type="submit" class="lp-btn lp-btn--outline">
				<span class="dashicons dashicons-filter" aria-hidden="true"></span>
				<?php esc_html_e( 'Filter', 'luwipress' ); ?>
			</button>
		</form>
		<div class="lwp-ab-save-row">
			<span id="lwp-alt-dirty-count" class="lp-form-hint"><?php esc_html_e( '0 unsaved', 'luwipress' ); ?></span>
			<button type="button" class="lp-btn lp-btn--primary lp-btn--lg" id="lwp-alt-save-all" disabled>
				<span class="dashicons dashicons-saved" aria-hidden="true"></span>
				<?php esc_html_e( 'Save all changes', 'luwipress' ); ?>
			</button>
		</div>
	</div>

	<!-- Bulk table -->
	<div class="luwipress-card lwp-ab-table-card">
		<table class="wp-list-table widefat striped lwp-ab-table" id="lwp-alt-table">
			<thead>
				<tr>
					<th class="lwp-ab-col-img"><?php esc_html_e( 'Image', 'luwipress' ); ?></th>
					<th class="lwp-ab-col-file"><?php esc_html_e( 'File / Title', 'luwipress' ); ?></th>
					<th class="lwp-ab-col-parent"><?php esc_html_e( 'Attached to', 'luwipress' ); ?></th>
					<th><?php esc_html_e( 'Alt text', 'luwipress' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $items ) ) : ?>
				<tr><td colspan="4" class="lwp-ab-empty">
					<?php
					if ( $filter === 'missing' ) {
						esc_html_e( 'Nothing missing alt text — every image is covered.', 'luwipress' );
					} else {
						esc_html_e( 'No images match.', 'luwipress' );
					}
					?>
				</td></tr>
				<?php else : foreach ( $items as $it ) : ?>
				<tr data-id="<?php echo esc_attr( (string) $it['id'] ); ?>">
					<td>
						<?php if ( $it['thumb'] ) : ?>
						<a href="<?php echo esc_url( wp_get_attachment_url( $it['id'] ) ); ?>" target="_blank" rel="noopener">
							<img src="<?php echo esc_url( $it['thumb'] ); ?>" alt="" class="lwp-ab-thumb">
						</a>
						<?php else : ?>
						<span class="lwp-ab-no-thumb">—</span>
						<?php endif; ?>
					</td>
					<td>
						<strong class="lwp-ab-title"><?php echo esc_html( $it['title'] ?: $it['filename'] ); ?></strong>
						<code class="lwp-ab-filename"><?php echo esc_html( $it['filename'] ); ?></code>
						<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $it['id'] . '&action=edit' ) ); ?>" class="lwp-ab-media-link">
							<?php esc_html_e( 'Open in Media Library →', 'luwipress' ); ?>
						</a>
					</td>
					<td>
						<?php if ( $it['parent_id'] && $it['parent_edit'] ) : ?>
						<a href="<?php echo esc_url( $it['parent_edit'] ); ?>"><?php echo esc_html( $it['parent_title'] ?: ( '#' . $it['parent_id'] ) ); ?></a>
						<button type="button" class="lp-btn lp-btn--ghost lp-btn--sm lwp-alt-from-parent" data-parent-title="<?php echo esc_attr( $it['parent_title'] ); ?>">
							<?php esc_html_e( 'Use parent title', 'luwipress' ); ?>
						</button>
						<?php else : ?>
						<span class="lwp-ab-unattached"><?php esc_html_e( '(unattached)', 'luwipress' ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<textarea class="lp-form-textarea lwp-alt-input" rows="2"
						          placeholder="<?php esc_attr_e( 'Describe what the image shows (one short sentence)…', 'luwipress' ); ?>"
						          data-original="<?php echo esc_attr( $it['alt'] ); ?>"><?php echo esc_textarea( $it['alt'] ); ?></textarea>
						<div class="lwp-alt-meta">
							<span class="lwp-alt-length">
								<?php
								$len = strlen( $it['alt'] );
								if ( $len === 0 ) {
									echo '<span class="lwp-ab-empty-flag">' . esc_html__( 'Empty', 'luwipress' ) . '</span>';
								} else {
									echo esc_html( sprintf( /* translators: %d characters */ __( '%d chars', 'luwipress' ), $len ) );
								}
								?>
							</span>
							<span class="lwp-alt-status"></span>
						</div>
					</td>
				</tr>
				<?php endforeach; endif; ?>
			</tbody>
		</table>
	</div>

	<!-- Pagination -->
	<?php if ( $total_pages > 1 ) :
		$pag_base = add_query_arg( array( 'filter' => $filter, 's' => $search ), $base_url );
	?>
	<div class="luwipress-card lwp-ab-pagination">
		<span class="lp-form-hint"><?php echo esc_html( sprintf( /* translators: %d page count */ __( 'Page %1$d of %2$d', 'luwipress' ), $paged, $total_pages ) ); ?></span>
		<div class="lp-btn-row">
			<?php if ( $paged > 1 ) : ?>
				<a class="lp-btn lp-btn--outline" href="<?php echo esc_url( add_query_arg( 'paged', $paged - 1, $pag_base ) ); ?>">
					<span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
					<?php esc_html_e( 'Previous', 'luwipress' ); ?>
				</a>
			<?php endif; ?>
			<?php if ( $paged < $total_pages ) : ?>
				<a class="lp-btn lp-btn--outline" href="<?php echo esc_url( add_query_arg( 'paged', $paged + 1, $pag_base ) ); ?>">
					<?php esc_html_e( 'Next', 'luwipress' ); ?>
					<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
				</a>
			<?php endif; ?>
		</div>
	</div>
	<?php endif; ?>
<?php if ( ! $luwipress_hub_mode ) : ?>
</div>
<?php endif; ?>

<style>
.lwp-ab-stats { margin: 0 0 16px; }

.lwp-ab-toolbar {
	display: flex;
	gap: 12px;
	align-items: center;
	flex-wrap: wrap;
}
.lwp-ab-filter-form {
	display: flex;
	gap: 8px;
	align-items: center;
	flex: 1;
	flex-wrap: wrap;
}
.lwp-ab-filter-pills {
	display: inline-flex;
	gap: 6px;
	flex-wrap: wrap;
}
.lwp-ab-filter-count {
	opacity: 0.7;
	margin-left: 4px;
	font-weight: 400;
}
.lwp-ab-search { width: 240px; }
.lwp-ab-save-row {
	display: flex;
	gap: 10px;
	align-items: center;
}

.lwp-ab-table-card { padding: 0; }
.lwp-ab-table { margin: 0; }
.lwp-ab-table .lwp-ab-col-img    { width: 90px; }
.lwp-ab-table .lwp-ab-col-file   { width: 30%; }
.lwp-ab-table .lwp-ab-col-parent { width: 20%; }
.lwp-ab-empty {
	text-align: center;
	color: var(--lp-text-secondary);
	font-style: italic;
	padding: 32px;
}
.lwp-ab-thumb {
	max-width: 80px;
	max-height: 80px;
	border: 1px solid var(--lp-border);
	border-radius: 4px;
}
.lwp-ab-no-thumb { color: var(--lp-text-secondary); }
.lwp-ab-title { display: block; color: var(--lp-text); }
.lwp-ab-filename {
	font-size: 11px;
	color: var(--lp-text-secondary);
	display: block;
	margin-top: 2px;
	background: transparent;
	padding: 0;
}
.lwp-ab-media-link { font-size: 11px; }
.lwp-ab-unattached {
	color: var(--lp-text-secondary);
	font-size: 12px;
	font-style: italic;
}
.lwp-alt-from-parent { display: block; margin-top: 4px; }

.lwp-alt-input { min-height: 56px; }
.lwp-alt-meta {
	font-size: 11px;
	color: var(--lp-text-secondary);
	margin-top: 4px;
	display: flex;
	justify-content: space-between;
}
.lwp-ab-empty-flag { color: var(--lp-error); font-weight: 600; }

.lwp-ab-pagination {
	display: flex;
	justify-content: space-between;
	align-items: center;
	flex-wrap: wrap;
	gap: 12px;
}

/* Row state colours — all token-driven for dark mode + brand override. */
.luwipress-image-alt-bulk tr.dirty,
.luwipress-hub-content tr.dirty       { background: var(--lp-warning-bg); }
.luwipress-image-alt-bulk tr.saved-ok,
.luwipress-hub-content tr.saved-ok    { background: var(--lp-success-bg); transition: background 1s; }
.luwipress-image-alt-bulk tr.saved-fail,
.luwipress-hub-content tr.saved-fail  { background: var(--lp-error-bg); }
.lwp-alt-status { font-weight: 600; }
.lwp-alt-status.dirty  { color: var(--lp-warning); }
.lwp-alt-status.saved  { color: var(--lp-success); }
.lwp-alt-status.failed { color: var(--lp-error); }
</style>

<script>
(function () {
	'use strict';
	var REST_BASE  = <?php echo wp_json_encode( $rest_base ); ?>;
	var REST_NONCE = <?php echo wp_json_encode( $rest_nonce ); ?>;

	function api(path, opts) {
		opts = opts || {};
		opts.headers = opts.headers || {};
		opts.headers['X-WP-Nonce'] = REST_NONCE;
		opts.headers['Accept']     = 'application/json';
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

	var rows = Array.prototype.slice.call(document.querySelectorAll('#lwp-alt-table tbody tr[data-id]'));
	var dirtyCountEl = document.getElementById('lwp-alt-dirty-count');
	var saveAllBtn   = document.getElementById('lwp-alt-save-all');

	function dirtyRows() {
		return rows.filter(function (r) {
			var input = r.querySelector('.lwp-alt-input');
			if (!input) return false;
			return (input.value || '') !== (input.getAttribute('data-original') || '');
		});
	}

	function updateDirtyCount() {
		var n = dirtyRows().length;
		dirtyCountEl.textContent = n + ' unsaved';
		dirtyCountEl.style.color = n > 0 ? '#a86b00' : '#666';
		saveAllBtn.disabled = n === 0;
	}

	function reflectRowDirty(row) {
		var input  = row.querySelector('.lwp-alt-input');
		var status = row.querySelector('.lwp-alt-status');
		var lenEl  = row.querySelector('.lwp-alt-length');
		var dirty  = (input.value || '') !== (input.getAttribute('data-original') || '');
		row.classList.toggle('dirty', dirty);
		if (status) {
			status.textContent  = dirty ? 'unsaved' : '';
			status.className    = 'lwp-alt-status' + (dirty ? ' dirty' : '');
		}
		if (lenEl) {
			var len = (input.value || '').length;
			lenEl.innerHTML = len === 0
				? '<span style="color:#c33;font-weight:600;">Empty</span>'
				: (len + ' chars');
		}
		updateDirtyCount();
	}

	rows.forEach(function (row) {
		var input = row.querySelector('.lwp-alt-input');
		if (input) {
			input.addEventListener('input', function () { reflectRowDirty(row); });
		}
		var parentBtn = row.querySelector('.lwp-alt-from-parent');
		if (parentBtn) {
			parentBtn.addEventListener('click', function () {
				var parentTitle = parentBtn.getAttribute('data-parent-title');
				if (parentTitle && input) {
					input.value = parentTitle;
					reflectRowDirty(row);
				}
			});
		}
	});

	saveAllBtn.addEventListener('click', function () {
		var changed = dirtyRows();
		if (!changed.length) return;
		saveAllBtn.disabled = true;
		var orig = saveAllBtn.innerHTML;
		saveAllBtn.innerHTML = '<span class="dashicons dashicons-update spin" style="line-height:1.6;"></span> Saving…';

		var payload = changed.map(function (r) {
			return {
				attachment_id: parseInt(r.getAttribute('data-id'), 10),
				alt_text:      r.querySelector('.lwp-alt-input').value || '',
			};
		});

		api('media/alt-bulk', { method: 'POST', body: { rows: payload } }).then(function (j) {
			changed.forEach(function (r) {
				var input  = r.querySelector('.lwp-alt-input');
				var status = r.querySelector('.lwp-alt-status');
				input.setAttribute('data-original', input.value || '');
				r.classList.remove('dirty');
				r.classList.add('saved-ok');
				setTimeout(function () { r.classList.remove('saved-ok'); }, 1500);
				if (status) {
					status.textContent = 'saved';
					status.className = 'lwp-alt-status saved';
					setTimeout(function () { status.textContent = ''; }, 1500);
				}
			});
			updateDirtyCount();
			saveAllBtn.innerHTML = '<span class="dashicons dashicons-yes" style="line-height:1.6;"></span> Saved ' + (j.applied || changed.length);
			setTimeout(function () { saveAllBtn.innerHTML = orig; }, 1800);
		}).catch(function (e) {
			changed.forEach(function (r) {
				r.classList.add('saved-fail');
				var status = r.querySelector('.lwp-alt-status');
				if (status) {
					status.textContent = 'failed';
					status.className = 'lwp-alt-status failed';
				}
			});
			saveAllBtn.innerHTML = orig;
			saveAllBtn.disabled = false;
			alert('Save failed: ' + e.message);
		});
	});
})();
</script>
