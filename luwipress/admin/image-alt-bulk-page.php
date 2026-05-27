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
<div class="wrap luwipress-image-alt-bulk">
	<h1><span class="dashicons dashicons-format-image"></span> <?php esc_html_e( 'Image Alt Bulk', 'luwipress' ); ?></h1>
	<p class="description" style="max-width:820px;">
		<?php esc_html_e( 'Scan every image in your Media Library, fill in alt text inline, and save 50+ rows in one click. Missing-alt images are highlighted on first load. Use the "From parent title" button to fast-fill alt with the post/product title.', 'luwipress' ); ?>
	</p>

	<!-- Hero stat row -->
	<div class="luwipress-card" style="display:flex;gap:24px;flex-wrap:wrap;align-items:stretch;">
		<div style="flex:1;min-width:160px;">
			<div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#666;"><?php esc_html_e( 'Total images', 'luwipress' ); ?></div>
			<div style="font-size:28px;font-weight:600;color:#222;margin-top:4px;"><?php echo esc_html( number_format_i18n( $total_all ) ); ?></div>
		</div>
		<div style="flex:1;min-width:160px;">
			<div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#666;"><?php esc_html_e( 'Missing alt', 'luwipress' ); ?></div>
			<div style="font-size:28px;font-weight:600;color:<?php echo $total_missing > 0 ? '#c33' : '#2c7a2c'; ?>;margin-top:4px;"><?php echo esc_html( number_format_i18n( $total_missing ) ); ?></div>
		</div>
		<div style="flex:1;min-width:160px;">
			<div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#666;"><?php esc_html_e( 'Has alt', 'luwipress' ); ?></div>
			<div style="font-size:28px;font-weight:600;color:#222;margin-top:4px;"><?php echo esc_html( number_format_i18n( $total_has_alt ) ); ?></div>
		</div>
		<div style="flex:1;min-width:160px;">
			<div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#666;"><?php esc_html_e( 'Coverage', 'luwipress' ); ?></div>
			<div style="font-size:28px;font-weight:600;color:#222;margin-top:4px;">
				<?php
				$cov = $total_all > 0 ? round( ( $total_has_alt / $total_all ) * 100 ) : 0;
				echo esc_html( $cov ) . '%';
				?>
			</div>
		</div>
	</div>

	<!-- Filter + search toolbar -->
	<div class="luwipress-card" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="display:flex;gap:6px;align-items:center;flex:1;flex-wrap:wrap;">
			<input type="hidden" name="page" value="luwipress-image-alt-bulk">
			<input type="hidden" name="filter" value="<?php echo esc_attr( $filter ); ?>">
			<div style="display:inline-flex;gap:4px;">
				<a class="button <?php echo $filter === 'missing' ? 'button-primary' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'filter' => 'missing', 's' => $search ), $base_url ) ); ?>">
					<?php esc_html_e( 'Missing', 'luwipress' ); ?> <span class="count" style="opacity:0.6;">(<?php echo esc_html( number_format_i18n( $total_missing ) ); ?>)</span>
				</a>
				<a class="button <?php echo $filter === 'has_alt' ? 'button-primary' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'filter' => 'has_alt', 's' => $search ), $base_url ) ); ?>">
					<?php esc_html_e( 'Has alt', 'luwipress' ); ?> <span class="count" style="opacity:0.6;">(<?php echo esc_html( number_format_i18n( $total_has_alt ) ); ?>)</span>
				</a>
				<a class="button <?php echo $filter === 'all' ? 'button-primary' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'filter' => 'all', 's' => $search ), $base_url ) ); ?>">
					<?php esc_html_e( 'All', 'luwipress' ); ?> <span class="count" style="opacity:0.6;">(<?php echo esc_html( number_format_i18n( $total_all ) ); ?>)</span>
				</a>
			</div>
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search filename / title…', 'luwipress' ); ?>" class="regular-text">
			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'luwipress' ); ?></button>
		</form>
		<div style="display:flex;gap:8px;align-items:center;">
			<span id="lwp-alt-dirty-count" style="color:#666;font-size:13px;"><?php esc_html_e( '0 unsaved', 'luwipress' ); ?></span>
			<button type="button" class="button button-primary button-hero" id="lwp-alt-save-all" disabled>
				<span class="dashicons dashicons-saved" style="line-height:1.6;"></span>
				<?php esc_html_e( 'Save all changes', 'luwipress' ); ?>
			</button>
		</div>
	</div>

	<!-- Bulk table -->
	<div class="luwipress-card" style="padding:0;">
		<table class="wp-list-table widefat striped" id="lwp-alt-table">
			<thead>
				<tr>
					<th style="width:90px;"><?php esc_html_e( 'Image', 'luwipress' ); ?></th>
					<th style="width:30%;"><?php esc_html_e( 'File / Title', 'luwipress' ); ?></th>
					<th style="width:20%;"><?php esc_html_e( 'Attached to', 'luwipress' ); ?></th>
					<th><?php esc_html_e( 'Alt text', 'luwipress' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $items ) ) : ?>
				<tr><td colspan="4" style="text-align:center;color:#999;font-style:italic;padding:32px;">
					<?php
					if ( $filter === 'missing' ) {
						esc_html_e( 'Nothing missing alt text — every image is covered.', 'luwipress' );
					} else {
						esc_html_e( 'No images match.', 'luwipress' );
					}
					?>
				</td></tr>
				<?php else : foreach ( $items as $it ) : ?>
				<tr data-id="<?php echo esc_attr( $it['id'] ); ?>">
					<td>
						<?php if ( $it['thumb'] ) : ?>
						<a href="<?php echo esc_url( wp_get_attachment_url( $it['id'] ) ); ?>" target="_blank" rel="noopener">
							<img src="<?php echo esc_url( $it['thumb'] ); ?>" alt="" style="max-width:80px;max-height:80px;border:1px solid #ddd;border-radius:4px;">
						</a>
						<?php else : ?>
						<span style="color:#999;">—</span>
						<?php endif; ?>
					</td>
					<td>
						<strong style="display:block;"><?php echo esc_html( $it['title'] ?: $it['filename'] ); ?></strong>
						<code style="font-size:11px;color:#666;display:block;margin-top:2px;"><?php echo esc_html( $it['filename'] ); ?></code>
						<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $it['id'] . '&action=edit' ) ); ?>" style="font-size:11px;"><?php esc_html_e( 'Open in Media Library →', 'luwipress' ); ?></a>
					</td>
					<td>
						<?php if ( $it['parent_id'] && $it['parent_edit'] ) : ?>
						<a href="<?php echo esc_url( $it['parent_edit'] ); ?>"><?php echo esc_html( $it['parent_title'] ?: ( '#' . $it['parent_id'] ) ); ?></a>
						<button type="button" class="button button-small lwp-alt-from-parent" data-parent-title="<?php echo esc_attr( $it['parent_title'] ); ?>" style="display:block;margin-top:4px;">
							<?php esc_html_e( 'Use parent title', 'luwipress' ); ?>
						</button>
						<?php else : ?>
						<span style="color:#999;font-size:12px;font-style:italic;"><?php esc_html_e( '(unattached)', 'luwipress' ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<textarea class="lwp-alt-input" rows="2" style="width:100%;font-family:inherit;font-size:13px;" placeholder="<?php esc_attr_e( 'Describe what the image shows (one short sentence)…', 'luwipress' ); ?>" data-original="<?php echo esc_attr( $it['alt'] ); ?>"><?php echo esc_textarea( $it['alt'] ); ?></textarea>
						<div class="lwp-alt-meta" style="font-size:11px;color:#999;margin-top:2px;display:flex;justify-content:space-between;">
							<span class="lwp-alt-length">
								<?php
								$len = strlen( $it['alt'] );
								if ( $len === 0 ) {
									echo '<span style="color:#c33;font-weight:600;">' . esc_html__( 'Empty', 'luwipress' ) . '</span>';
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
	<div class="luwipress-card" style="display:flex;justify-content:space-between;align-items:center;">
		<span><?php echo esc_html( sprintf( /* translators: %d page count */ __( 'Page %1$d of %2$d', 'luwipress' ), $paged, $total_pages ) ); ?></span>
		<div>
			<?php if ( $paged > 1 ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $paged - 1, $pag_base ) ); ?>">← <?php esc_html_e( 'Previous', 'luwipress' ); ?></a>
			<?php endif; ?>
			<?php if ( $paged < $total_pages ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $paged + 1, $pag_base ) ); ?>"><?php esc_html_e( 'Next', 'luwipress' ); ?> →</a>
			<?php endif; ?>
		</div>
	</div>
	<?php endif; ?>
</div>

<style>
.luwipress-image-alt-bulk .luwipress-card {
	background:#fff;border:1px solid #ddd;border-radius:6px;padding:14px 18px;margin:16px 0;
}
.luwipress-image-alt-bulk .luwipress-card h2 { margin-top:0;font-size:17px; }
.luwipress-image-alt-bulk tr.dirty { background:#fffbe6; }
.luwipress-image-alt-bulk tr.saved-ok { background:#e9f5e9; transition:background 1s; }
.luwipress-image-alt-bulk tr.saved-fail { background:#fde2e2; }
.luwipress-image-alt-bulk .lwp-alt-status { font-weight:600; }
.luwipress-image-alt-bulk .lwp-alt-status.dirty { color:#a86b00; }
.luwipress-image-alt-bulk .lwp-alt-status.saved { color:#2c7a2c; }
.luwipress-image-alt-bulk .lwp-alt-status.failed { color:#c33; }
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
