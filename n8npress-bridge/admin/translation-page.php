<?php
/**
 * n8nPress Translation Manager
 *
 * Unified translation dashboard — coverage overview, one-click translate,
 * image fix, bulk operations. Reads languages from WPML/Polylang.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Environment ──
$detector       = N8nPress_Plugin_Detector::get_instance();
$translation    = $detector->detect_translation();
$plugin_name    = ucwords( str_replace( '-', ' ', $translation['plugin'] ) );
$plugin_ver     = $translation['version'] ?? '';
$default_lang   = $translation['default_language'] ?? 'en';
$active_langs   = $translation['active_languages'] ?? array();
$target_langs   = array_diff( $active_langs, array( $default_lang ) );
$has_wc_support = ! empty( $translation['features']['woocommerce'] );
$webhook_url    = get_option( 'n8npress_seo_webhook_url', '' );
$is_wpml        = 'wpml' === $translation['plugin'];

$language_names = array(
	'tr' => 'Türkçe', 'en' => 'English', 'de' => 'Deutsch', 'fr' => 'Français',
	'ar' => 'العربية', 'es' => 'Español', 'it' => 'Italiano', 'nl' => 'Nederlands',
	'ru' => 'Русский', 'ja' => '日本語', 'zh' => '中文', 'pt-pt' => 'Português',
	'ko' => '한국어', 'sv' => 'Svenska', 'pl' => 'Polski', 'uk' => 'Українська',
);

$language_flags = array(
	'tr' => '🇹🇷', 'en' => '🇬🇧', 'de' => '🇩🇪', 'fr' => '🇫🇷',
	'ar' => '🇸🇦', 'es' => '🇪🇸', 'it' => '🇮🇹', 'nl' => '🇳🇱',
	'ru' => '🇷🇺', 'ja' => '🇯🇵', 'zh' => '🇨🇳', 'pt-pt' => '🇵🇹',
	'ko' => '🇰🇷', 'sv' => '🇸🇪', 'pl' => '🇵🇱', 'uk' => '🇺🇦',
);

// ── Handle translation trigger ──
if ( isset( $_POST['n8npress_trigger_translation'] ) && check_admin_referer( 'n8npress_translation_nonce' ) ) {
	$lang  = sanitize_text_field( $_POST['translate_language'] ?? '' );
	$type  = sanitize_text_field( $_POST['translate_post_type'] ?? 'product' );
	$limit = absint( $_POST['translate_limit'] ?? 10 );

	if ( ! empty( $lang ) && ! empty( $webhook_url ) ) {
		$url = trailingslashit( $webhook_url ) . 'translation-request';
		$response = wp_remote_post( $url, array(
			'timeout' => 15,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . get_option( 'n8npress_seo_api_token', '' ),
			),
			'body' => wp_json_encode( array(
				'event'            => 'translate_missing',
				'target_languages' => $lang,
				'post_type'        => $type,
				'limit'            => $limit,
				'fetch_pending'    => true,
				'site_url'         => get_site_url(),
			) ),
		) );

		$code = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
		if ( ! is_wp_error( $response ) && $code >= 200 && $code < 300 ) {
			$type_label = get_post_type_object( $type )->labels->name ?? $type;
			echo '<div class="notice notice-success is-dismissible"><p>';
			printf( __( 'Translation started: %s %s, up to %d items.', 'n8npress' ), strtoupper( $lang ), $type_label, $limit );
			echo '</p></div>';
			N8nPress_Logger::log( 'Bulk translation triggered: ' . strtoupper( $lang ), 'info', array( 'language' => $lang, 'type' => $type, 'limit' => $limit ) );
		} else {
			$err = is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . $code;
			echo '<div class="notice notice-error is-dismissible"><p>' . sprintf( __( 'Translation trigger failed: %s', 'n8npress' ), esc_html( $err ) ) . '</p></div>';
		}
	}
}

// ── Calculate coverage for all content types ──
global $wpdb;
$content_types = array(
	'product' => array( 'icon' => 'dashicons-cart', 'color' => '#6366f1' ),
	'post'    => array( 'icon' => 'dashicons-admin-post', 'color' => '#2563eb' ),
	'page'    => array( 'icon' => 'dashicons-admin-page', 'color' => '#16a34a' ),
);
$coverage = array();

foreach ( $content_types as $pt => $pt_meta ) {
	$pt_obj = get_post_type_object( $pt );
	if ( ! $pt_obj ) continue;

	if ( $is_wpml ) {
		$element_type = 'post_' . $pt;
		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT t.element_id) FROM {$wpdb->prefix}icl_translations t
			 WHERE t.element_type = %s AND t.language_code = %s AND t.source_language_code IS NULL
			   AND t.element_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish')",
			$element_type, $default_lang, $pt
		) );
	} else {
		$total = absint( wp_count_posts( $pt )->publish ?? 0 );
	}

	$langs = array();
	if ( $total > 0 && $is_wpml ) {
		foreach ( $target_langs as $lang ) {
			$langs[ $lang ] = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(DISTINCT t.element_id) FROM {$wpdb->prefix}icl_translations t
				 WHERE t.element_type = %s AND t.language_code = %s
				   AND t.element_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish')",
				$element_type, $lang, $pt
			) );
		}
	}

	$coverage[ $pt ] = array( 'label' => $pt_obj->labels->name, 'total' => $total, 'languages' => $langs, 'icon' => $pt_meta['icon'], 'color' => $pt_meta['color'] );
}

// ── Taxonomy coverage ──
$tax_types = array( 'product_cat' => 'Product Categories', 'product_tag' => 'Product Tags', 'category' => 'Post Categories' );
$tax_coverage = array();
foreach ( $tax_types as $tax => $label ) {
	if ( ! taxonomy_exists( $tax ) ) continue;
	if ( $is_wpml ) {
		$el_type = 'tax_' . $tax;
		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}icl_translations WHERE element_type = %s AND language_code = %s AND source_language_code IS NULL",
			$el_type, $default_lang
		) );
		$langs = array();
		foreach ( $target_langs as $lang ) {
			$langs[ $lang ] = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}icl_translations WHERE element_type = %s AND language_code = %s AND source_language_code IS NOT NULL",
				$el_type, $lang
			) );
		}
		if ( $total > 0 ) {
			$tax_coverage[ $tax ] = array( 'label' => $label, 'total' => $total, 'languages' => $langs );
		}
	}
}

// ── Overall stats ──
$total_content = 0;
$total_translated = 0;
$total_possible = 0;
foreach ( $coverage as $c ) {
	$total_content += $c['total'];
	foreach ( $c['languages'] as $count ) {
		$total_translated += $count;
	}
	$total_possible += $c['total'] * count( $target_langs );
}
$overall_pct = $total_possible > 0 ? round( ( $total_translated / $total_possible ) * 100 ) : 0;
?>

<div class="wrap n8npress-dashboard">
	<h1><span class="dashicons dashicons-translation"></span> <?php esc_html_e( 'Translation Manager', 'n8npress' ); ?></h1>

	<?php if ( empty( $target_langs ) ) : ?>
		<div class="notice notice-info"><p><?php printf( __( 'Configure target languages in %s to start translating.', 'n8npress' ), $plugin_name ); ?></p></div>
	<?php else : ?>

	<!-- ── OVERVIEW CARDS ── -->
	<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin:16px 0 24px;">
		<div class="postbox" style="margin:0;padding:14px 18px;border-left:4px solid #6366f1;">
			<div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;"><?php esc_html_e( 'Overall Coverage', 'n8npress' ); ?></div>
			<div style="font-size:28px;font-weight:700;color:<?php echo $overall_pct >= 80 ? '#16a34a' : ( $overall_pct >= 50 ? '#f59e0b' : '#dc2626' ); ?>;"><?php echo $overall_pct; ?>%</div>
			<div style="margin-top:6px;height:6px;background:#e5e7eb;border-radius:3px;overflow:hidden;">
				<div style="height:100%;width:<?php echo $overall_pct; ?>%;background:<?php echo $overall_pct >= 80 ? '#16a34a' : ( $overall_pct >= 50 ? '#f59e0b' : '#dc2626' ); ?>;border-radius:3px;"></div>
			</div>
		</div>
		<div class="postbox" style="margin:0;padding:14px 18px;">
			<div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;"><?php esc_html_e( 'Content Items', 'n8npress' ); ?></div>
			<div style="font-size:28px;font-weight:700;"><?php echo $total_content; ?></div>
			<div style="font-size:12px;color:#6b7280;"><?php echo count( $target_langs ); ?> <?php esc_html_e( 'languages', 'n8npress' ); ?></div>
		</div>
		<div class="postbox" style="margin:0;padding:14px 18px;">
			<div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;"><?php esc_html_e( 'Translated', 'n8npress' ); ?></div>
			<div style="font-size:28px;font-weight:700;color:#16a34a;"><?php echo $total_translated; ?></div>
			<div style="font-size:12px;color:#6b7280;"><?php esc_html_e( 'of', 'n8npress' ); ?> <?php echo $total_possible; ?> <?php esc_html_e( 'needed', 'n8npress' ); ?></div>
		</div>
		<div class="postbox" style="margin:0;padding:14px 18px;">
			<div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;"><?php esc_html_e( 'Backend', 'n8npress' ); ?></div>
			<div style="font-size:16px;font-weight:600;margin-top:4px;"><?php echo esc_html( $plugin_name ); ?></div>
			<div style="font-size:12px;color:#6b7280;">
				<?php echo esc_html( strtoupper( $default_lang ) ); ?> →
				<?php foreach ( $target_langs as $lang ) : ?>
					<span style="font-weight:600;"><?php echo esc_html( strtoupper( $lang ) ); ?></span>
				<?php endforeach; ?>
			</div>
		</div>
	</div>

	<!-- ── CONTENT COVERAGE ── -->
	<?php foreach ( $coverage as $pt => $data ) :
		if ( $data['total'] === 0 ) continue;
	?>
	<div class="postbox" style="margin-bottom:16px;">
		<div class="postbox-header" style="padding:12px 16px;border-bottom:1px solid #e5e7eb;">
			<h2 style="margin:0;font-size:14px;display:flex;align-items:center;gap:8px;">
				<span class="dashicons <?php echo esc_attr( $data['icon'] ); ?>" style="color:<?php echo esc_attr( $data['color'] ); ?>;"></span>
				<?php echo esc_html( $data['label'] ); ?>
				<span style="font-weight:400;color:#6b7280;font-size:13px;"><?php echo $data['total']; ?> <?php esc_html_e( 'published', 'n8npress' ); ?></span>
			</h2>
		</div>
		<div style="padding:0;">
			<table class="wp-list-table widefat fixed" style="border:0;font-size:13px;">
				<thead>
					<tr style="background:#f8fafc;">
						<th style="padding:10px 16px;"><?php esc_html_e( 'Language', 'n8npress' ); ?></th>
						<th style="padding:10px 16px;text-align:center;width:100px;"><?php esc_html_e( 'Done', 'n8npress' ); ?></th>
						<th style="padding:10px 16px;text-align:center;width:100px;"><?php esc_html_e( 'Missing', 'n8npress' ); ?></th>
						<th style="padding:10px 16px;width:200px;"><?php esc_html_e( 'Coverage', 'n8npress' ); ?></th>
						<th style="padding:10px 16px;width:120px;"></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $target_langs as $lang ) :
					$done    = $data['languages'][ $lang ] ?? 0;
					$missing = max( 0, $data['total'] - $done );
					$pct     = $data['total'] > 0 ? round( ( $done / $data['total'] ) * 100 ) : 0;
					$bar_color = $pct >= 100 ? '#16a34a' : ( $pct >= 60 ? '#6366f1' : '#f59e0b' );
				?>
				<tr>
					<td style="padding:10px 16px;">
						<strong><?php echo esc_html( strtoupper( $lang ) ); ?></strong>
						<span style="color:#6b7280;"><?php echo esc_html( $language_names[ $lang ] ?? $lang ); ?></span>
					</td>
					<td style="padding:10px 16px;text-align:center;font-weight:600;"><?php echo $done; ?></td>
					<td style="padding:10px 16px;text-align:center;">
						<?php if ( $missing > 0 ) : ?>
							<span style="color:#dc2626;font-weight:600;"><?php echo $missing; ?></span>
						<?php else : ?>
							<span style="color:#16a34a;font-weight:600;">0</span>
						<?php endif; ?>
					</td>
					<td style="padding:10px 16px;">
						<div style="display:flex;align-items:center;gap:8px;">
							<div style="flex:1;height:8px;background:#e5e7eb;border-radius:4px;overflow:hidden;">
								<div style="height:100%;width:<?php echo $pct; ?>%;background:<?php echo esc_attr( $bar_color ); ?>;border-radius:4px;transition:width .3s;"></div>
							</div>
							<span style="font-size:12px;font-weight:600;color:#374151;min-width:36px;"><?php echo $pct; ?>%</span>
						</div>
					</td>
					<td style="padding:10px 16px;">
						<?php if ( $missing > 0 && ! empty( $webhook_url ) ) : ?>
						<form method="post" style="display:inline;">
							<?php wp_nonce_field( 'n8npress_translation_nonce' ); ?>
							<input type="hidden" name="translate_language" value="<?php echo esc_attr( $lang ); ?>" />
							<input type="hidden" name="translate_post_type" value="<?php echo esc_attr( $pt ); ?>" />
							<input type="hidden" name="translate_limit" value="<?php echo min( $missing, 20 ); ?>" />
							<button type="submit" name="n8npress_trigger_translation" class="button button-primary button-small">
								<?php printf( __( 'Translate %d', 'n8npress' ), min( $missing, 20 ) ); ?>
							</button>
						</form>
						<?php elseif ( $pct >= 100 ) : ?>
							<span style="color:#16a34a;font-size:12px;font-weight:600;">Complete</span>
						<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
	<?php endforeach; ?>

	<!-- ── TAXONOMY COVERAGE ── -->
	<?php if ( ! empty( $tax_coverage ) ) : ?>
	<div class="postbox" style="margin-bottom:16px;">
		<div class="postbox-header" style="padding:12px 16px;border-bottom:1px solid #e5e7eb;">
			<h2 style="margin:0;font-size:14px;display:flex;align-items:center;gap:8px;">
				<span class="dashicons dashicons-tag" style="color:#f59e0b;"></span>
				<?php esc_html_e( 'Taxonomies', 'n8npress' ); ?>
			</h2>
		</div>
		<div style="padding:16px;">
			<?php foreach ( $tax_coverage as $tax_slug => $tax_data ) : ?>
			<div style="margin-bottom:16px;">
				<h4 style="margin:0 0 8px;font-size:13px;font-weight:600;color:#374151;">
					<?php echo esc_html( $tax_data['label'] ); ?>
					<span style="font-weight:400;color:#9ca3af;">(<?php echo $tax_data['total']; ?>)</span>
				</h4>
				<div style="display:flex;gap:12px;flex-wrap:wrap;">
					<?php foreach ( $target_langs as $lang ) :
						$done    = $tax_data['languages'][ $lang ] ?? 0;
						$missing = max( 0, $tax_data['total'] - $done );
						$pct     = $tax_data['total'] > 0 ? round( ( $done / $tax_data['total'] ) * 100 ) : 0;
					?>
					<div style="display:flex;align-items:center;gap:6px;font-size:12px;">
						<strong><?php echo esc_html( strtoupper( $lang ) ); ?></strong>
						<div style="width:60px;height:6px;background:#e5e7eb;border-radius:3px;overflow:hidden;">
							<div style="height:100%;width:<?php echo $pct; ?>%;background:<?php echo $pct >= 100 ? '#16a34a' : '#6366f1'; ?>;border-radius:3px;"></div>
						</div>
						<span style="color:<?php echo $missing > 0 ? '#dc2626' : '#16a34a'; ?>;font-weight:600;"><?php echo $pct; ?>%</span>
					</div>
					<?php endforeach; ?>
				</div>
			</div>
			<?php endforeach; ?>
		</div>
	</div>
	<?php endif; ?>

	<!-- ── BULK TRANSLATION ── -->
	<div class="postbox" style="margin-bottom:16px;">
		<div class="postbox-header" style="padding:12px 16px;border-bottom:1px solid #e5e7eb;">
			<h2 style="margin:0;font-size:14px;"><?php esc_html_e( 'Bulk Translation', 'n8npress' ); ?></h2>
		</div>
		<div style="padding:16px;">
			<?php if ( empty( $webhook_url ) ) : ?>
				<p style="color:#dc2626;">
					<span class="dashicons dashicons-warning"></span>
					<?php esc_html_e( 'n8n webhook URL not configured.', 'n8npress' ); ?>
					<a href="<?php echo admin_url( 'admin.php?page=n8npress-settings' ); ?>"><?php esc_html_e( 'Configure', 'n8npress' ); ?></a>
				</p>
			<?php else : ?>
			<form method="post" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
				<?php wp_nonce_field( 'n8npress_translation_nonce' ); ?>
				<label style="display:flex;flex-direction:column;gap:4px;font-size:13px;font-weight:500;">
					<?php esc_html_e( 'Content Type', 'n8npress' ); ?>
					<select name="translate_post_type" style="min-width:140px;">
						<option value="product"><?php esc_html_e( 'Products', 'n8npress' ); ?></option>
						<option value="post"><?php esc_html_e( 'Posts', 'n8npress' ); ?></option>
						<option value="page"><?php esc_html_e( 'Pages', 'n8npress' ); ?></option>
					</select>
				</label>
				<label style="display:flex;flex-direction:column;gap:4px;font-size:13px;font-weight:500;">
					<?php esc_html_e( 'Language', 'n8npress' ); ?>
					<select name="translate_language" style="min-width:140px;">
						<?php foreach ( $target_langs as $lang ) : ?>
						<option value="<?php echo esc_attr( $lang ); ?>"><?php echo esc_html( strtoupper( $lang ) . ' — ' . ( $language_names[ $lang ] ?? $lang ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label style="display:flex;flex-direction:column;gap:4px;font-size:13px;font-weight:500;">
					<?php esc_html_e( 'Limit', 'n8npress' ); ?>
					<select name="translate_limit" style="min-width:80px;">
						<option value="5">5</option>
						<option value="10" selected>10</option>
						<option value="20">20</option>
						<option value="50">50</option>
					</select>
				</label>
				<button type="submit" name="n8npress_trigger_translation" class="button button-primary" style="height:32px;">
					<?php esc_html_e( 'Start Translation', 'n8npress' ); ?>
				</button>
			</form>
			<?php endif; ?>
		</div>
	</div>

	<!-- ── MAINTENANCE ── -->
	<div class="postbox">
		<div class="postbox-header" style="padding:12px 16px;border-bottom:1px solid #e5e7eb;">
			<h2 style="margin:0;font-size:14px;"><?php esc_html_e( 'Maintenance', 'n8npress' ); ?></h2>
		</div>
		<div style="padding:16px;display:flex;gap:16px;align-items:center;flex-wrap:wrap;">
			<button type="button" id="n8npress-fix-images" class="button">
				<span class="dashicons dashicons-format-image" style="margin-top:4px;"></span>
				<?php esc_html_e( 'Fix Translation Images', 'n8npress' ); ?>
			</button>
			<span id="n8npress-fix-images-result" style="font-size:13px;"></span>
			<span style="color:#6b7280;font-size:12px;"><?php esc_html_e( 'Copies original product images to all translated products.', 'n8npress' ); ?></span>
		</div>
	</div>

	<script>
	document.getElementById('n8npress-fix-images')?.addEventListener('click', function() {
		var btn = this, result = document.getElementById('n8npress-fix-images-result');
		btn.disabled = true; btn.textContent = <?php echo wp_json_encode( __( 'Fixing...', 'n8npress' ) ); ?>;
		result.textContent = '';
		fetch(ajaxurl + '?action=n8npress_fix_translation_images&nonce=<?php echo wp_create_nonce( 'n8npress_fix_images' ); ?>', {method:'POST'})
			.then(function(r){return r.json()}).then(function(d){
				btn.disabled = false;
				btn.innerHTML = '<span class="dashicons dashicons-format-image" style="margin-top:4px;"></span> ' + <?php echo wp_json_encode( __( 'Fix Translation Images', 'n8npress' ) ); ?>;
				result.innerHTML = d.success
					? '<span style="color:#16a34a;">' + d.data.fixed + ' ' + <?php echo wp_json_encode( __( 'fixed', 'n8npress' ) ); ?> + '</span>'
					: '<span style="color:#dc2626;">' + (d.data || 'Error') + '</span>';
			});
	});
	</script>

	<?php endif; ?>
</div>
