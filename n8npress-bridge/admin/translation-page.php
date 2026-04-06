<?php
/**
 * n8nPress Translation Manager Page
 *
 * Admin UI for viewing translation status and triggering bulk translations.
 * Reads active languages from WPML/Polylang Plugin Detector — no manual config needed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Get translation environment from Plugin Detector
$detector    = N8nPress_Plugin_Detector::get_instance();
$translation = $detector->detect_translation();
$plugin_name = ucwords( str_replace( '-', ' ', $translation['plugin'] ) );
$plugin_ver  = $translation['version'] ?? '';
$default_lang    = $translation['default_language'] ?? 'tr';
$active_langs    = $translation['active_languages'] ?? array();
$target_langs    = array_diff( $active_langs, array( $default_lang ) );
$has_wc_support  = ! empty( $translation['features']['woocommerce'] );
$webhook_url     = get_option( 'n8npress_seo_webhook_url', '' );

$language_names = array(
	'tr' => 'Türkçe', 'en' => 'English', 'de' => 'Deutsch', 'fr' => 'Français',
	'ar' => 'العربية', 'es' => 'Español', 'it' => 'Italiano', 'nl' => 'Nederlands',
	'ru' => 'Русский', 'ja' => '日本語', 'zh' => '中文', 'pt-pt' => 'Português',
	'ko' => '한국어', 'sv' => 'Svenska', 'pl' => 'Polski', 'uk' => 'Українська',
);

// Handle bulk translation trigger
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
			echo '<div class="notice notice-success is-dismissible"><p>' .
				sprintf( __( 'Translation pipeline triggered for %s (%s, up to %d items).', 'n8npress' ),
					strtoupper( $lang ), $type, $limit ) .
				'</p></div>';
			N8nPress_Logger::log( 'Bulk translation triggered: ' . strtoupper( $lang ), 'info', array(
				'language' => $lang, 'type' => $type, 'limit' => $limit,
			) );
		} else {
			$err = is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . $code;
			echo '<div class="notice notice-error is-dismissible"><p>' .
				sprintf( __( 'Translation trigger failed: %s', 'n8npress' ), esc_html( $err ) ) .
				'</p></div>';
		}
	}
}

// Calculate real translation coverage using WPML/Polylang data
$post_types = array( 'product', 'post', 'page' );
$coverage   = array();

// Category/Tag coverage
$taxonomy_coverage = array();
$taxonomies_to_check = array( 'product_cat' => 'Product Categories', 'product_tag' => 'Product Tags' );
foreach ( $taxonomies_to_check as $tax_slug => $tax_label ) {
	$terms = get_terms( array( 'taxonomy' => $tax_slug, 'hide_empty' => false ) );
	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		continue;
	}

	if ( 'wpml' === $translation['plugin'] ) {
		// WPML: use icl_translations table directly for accurate counts
		global $wpdb;
		$element_type = 'tax_' . $tax_slug;

		// Count default language terms
		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}icl_translations
			 WHERE element_type = %s AND language_code = %s",
			$element_type, $default_lang
		) );

		$taxonomy_coverage[ $tax_slug ] = array( 'label' => $tax_label, 'total' => $total, 'languages' => array() );

		foreach ( $target_langs as $lang ) {
			$translated_count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}icl_translations
				 WHERE element_type = %s AND language_code = %s
				   AND source_language_code IS NOT NULL",
				$element_type, $lang
			) );
			$taxonomy_coverage[ $tax_slug ]['languages'][ $lang ] = $translated_count;
		}
	} else {
		// Polylang or no translation plugin
		$total = count( $terms );
		$taxonomy_coverage[ $tax_slug ] = array( 'label' => $tax_label, 'total' => $total, 'languages' => array() );
	}
}

foreach ( $post_types as $pt ) {
	$total_obj = wp_count_posts( $pt );
	$total     = absint( $total_obj->publish ?? 0 );
	$coverage[ $pt ] = array( 'total' => $total, 'languages' => array() );

	if ( $total === 0 || empty( $target_langs ) ) {
		continue;
	}

	global $wpdb;

	if ( 'wpml' === $translation['plugin'] ) {
		$element_type = 'post_' . $pt;
		foreach ( $target_langs as $lang ) {
			$translated = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(DISTINCT t.element_id)
				 FROM {$wpdb->prefix}icl_translations t
				 WHERE t.element_type = %s
				   AND t.language_code = %s
				   AND t.element_id IN (
				       SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'
				   )",
				$element_type, $lang, $pt
			) );
			$coverage[ $pt ]['languages'][ $lang ] = intval( $translated );
		}
	} elseif ( 'polylang' === $translation['plugin'] && function_exists( 'pll_count_posts' ) ) {
		foreach ( $target_langs as $lang ) {
			$count = pll_count_posts( $lang, array( 'post_type' => $pt ) );
			$coverage[ $pt ]['languages'][ $lang ] = intval( $count );
		}
	} else {
		// Fallback: n8nPress own tracking via post_meta
		foreach ( $target_langs as $lang ) {
			$translated = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				 WHERE p.post_type = %s AND p.post_status = 'publish'
				   AND pm.meta_key = %s AND pm.meta_value = 'completed'",
				$pt, '_n8npress_translation_' . $lang . '_status'
			) );
			$coverage[ $pt ]['languages'][ $lang ] = intval( $translated );
		}
	}
}
?>

<div class="wrap n8npress-dashboard">
	<h1>
		<span class="dashicons dashicons-translation"></span>
		<?php _e( 'Translation Manager', 'n8npress' ); ?>
	</h1>

	<!-- Status Bar -->
	<div class="n8npress-status-bar">
		<div class="n8npress-status-item status-ok">
			<span class="dashicons dashicons-admin-plugins"></span>
			<strong><?php _e( 'Backend:', 'n8npress' ); ?></strong>
			<span class="status-text"><?php echo esc_html( $plugin_name . ( $plugin_ver ? ' v' . $plugin_ver : '' ) ); ?></span>
		</div>
		<div class="n8npress-status-item status-ok">
			<span class="dashicons dashicons-admin-site-alt3"></span>
			<strong><?php _e( 'Default:', 'n8npress' ); ?></strong>
			<code class="lang-tag"><?php echo esc_html( strtoupper( $default_lang ) ); ?></code>
			<span class="status-text"><?php echo esc_html( $language_names[ $default_lang ] ?? $default_lang ); ?></span>
		</div>
		<div class="n8npress-status-item <?php echo count( $target_langs ) > 0 ? 'status-ok' : 'status-warning'; ?>">
			<span class="dashicons dashicons-translation"></span>
			<strong><?php _e( 'Targets:', 'n8npress' ); ?></strong>
			<?php if ( ! empty( $target_langs ) ) : ?>
				<?php foreach ( $target_langs as $lang ) : ?>
					<code class="lang-tag"><?php echo esc_html( strtoupper( $lang ) ); ?></code>
				<?php endforeach; ?>
			<?php else : ?>
				<span class="status-text"><?php _e( 'No target languages configured', 'n8npress' ); ?></span>
			<?php endif; ?>
		</div>
		<?php if ( class_exists( 'WooCommerce' ) ) : ?>
		<div class="n8npress-status-item <?php echo $has_wc_support ? 'status-ok' : 'status-warning'; ?>">
			<span class="dashicons <?php echo $has_wc_support ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
			<strong><?php _e( 'WooCommerce:', 'n8npress' ); ?></strong>
			<span class="status-text">
				<?php echo $has_wc_support
					? __( 'Product translation supported', 'n8npress' )
					: sprintf( __( '%s for WooCommerce required', 'n8npress' ), $plugin_name ); ?>
			</span>
		</div>
		<?php endif; ?>
	</div>

	<?php if ( empty( $target_langs ) ) : ?>
		<div class="notice notice-info">
			<p><?php printf( __( 'Add target languages in %s settings to start translating.', 'n8npress' ), $plugin_name ); ?></p>
		</div>
	<?php else : ?>

	<!-- Coverage Stats -->
	<div class="n8npress-section">
		<h2><?php _e( 'Translation Coverage', 'n8npress' ); ?></h2>

		<?php foreach ( $post_types as $pt ) :
			$pt_obj = get_post_type_object( $pt );
			if ( ! $pt_obj || $coverage[ $pt ]['total'] === 0 ) {
				continue;
			}
		?>
		<div class="n8npress-translation-coverage">
			<h3>
				<?php echo esc_html( $pt_obj->labels->name ); ?>
				<span style="font-weight:400;color:#6b7280;"> — <?php echo $coverage[ $pt ]['total']; ?> <?php _e( 'published', 'n8npress' ); ?></span>
			</h3>
			<table class="n8npress-table">
				<thead>
					<tr>
						<th><?php _e( 'Language', 'n8npress' ); ?></th>
						<th><?php _e( 'Translated', 'n8npress' ); ?></th>
						<th><?php _e( 'Missing', 'n8npress' ); ?></th>
						<th><?php _e( 'Coverage', 'n8npress' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $target_langs as $lang ) :
						$translated = $coverage[ $pt ]['languages'][ $lang ] ?? 0;
						$missing    = max( 0, $coverage[ $pt ]['total'] - $translated );
						$percent    = $coverage[ $pt ]['total'] > 0 ? round( ( $translated / $coverage[ $pt ]['total'] ) * 100 ) : 0;
					?>
					<tr>
						<td>
							<code class="lang-tag"><?php echo esc_html( strtoupper( $lang ) ); ?></code>
							<?php echo esc_html( $language_names[ $lang ] ?? $lang ); ?>
						</td>
						<td><strong><?php echo $translated; ?></strong></td>
						<td>
							<?php if ( $missing > 0 ) : ?>
								<span style="color:#dc2626;font-weight:600;"><?php echo $missing; ?></span>
							<?php else : ?>
								<span style="color:#16a34a;">✓ 0</span>
							<?php endif; ?>
						</td>
						<td>
							<div class="n8npress-progress-bar">
								<div class="progress-fill <?php echo $percent === 100 ? 'progress-complete' : ''; ?>"
									 style="width:<?php echo $percent; ?>%;"></div>
							</div>
							<span class="progress-text"><?php echo $percent; ?>%</span>
						</td>
						<td>
							<?php if ( $missing > 0 ) : ?>
							<form method="post" style="display:inline;">
								<?php wp_nonce_field( 'n8npress_translation_nonce' ); ?>
								<input type="hidden" name="translate_language" value="<?php echo esc_attr( $lang ); ?>" />
								<input type="hidden" name="translate_post_type" value="<?php echo esc_attr( $pt ); ?>" />
								<input type="hidden" name="translate_limit" value="<?php echo min( $missing, 20 ); ?>" />
								<button type="submit" name="n8npress_trigger_translation" class="button button-small"
										<?php echo empty( $webhook_url ) ? 'disabled title="Configure n8n webhook first"' : ''; ?>>
									<?php printf( __( 'Translate %d', 'n8npress' ), min( $missing, 20 ) ); ?>
								</button>
							</form>
							<?php endif; ?>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php endforeach; ?>

		<!-- Taxonomy Translation Coverage -->
		<?php foreach ( $taxonomy_coverage as $tax_slug => $tax_data ) :
			if ( $tax_data['total'] === 0 ) continue;
		?>
		<div class="n8npress-translation-coverage">
			<h3>
				<?php echo esc_html( $tax_data['label'] ); ?>
				<span style="font-weight:400;color:#6b7280;"> — <?php echo $tax_data['total']; ?> <?php _e( 'terms', 'n8npress' ); ?></span>
			</h3>
			<table class="n8npress-table">
				<thead>
					<tr>
						<th><?php _e( 'Language', 'n8npress' ); ?></th>
						<th><?php _e( 'Translated', 'n8npress' ); ?></th>
						<th><?php _e( 'Missing', 'n8npress' ); ?></th>
						<th><?php _e( 'Coverage', 'n8npress' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $target_langs as $lang ) :
						$t_count = $tax_data['languages'][ $lang ] ?? 0;
						$missing = max( 0, $tax_data['total'] - $t_count );
						$percent = $tax_data['total'] > 0 ? round( ( $t_count / $tax_data['total'] ) * 100 ) : 0;
					?>
					<tr>
						<td>
							<code class="lang-tag"><?php echo esc_html( strtoupper( $lang ) ); ?></code>
							<?php echo esc_html( $language_names[ $lang ] ?? $lang ); ?>
						</td>
						<td><strong><?php echo $t_count; ?></strong></td>
						<td>
							<?php if ( $missing > 0 ) : ?>
								<span style="color:#dc2626;font-weight:600;"><?php echo $missing; ?></span>
							<?php else : ?>
								<span style="color:#16a34a;">0</span>
							<?php endif; ?>
						</td>
						<td>
							<div class="n8npress-progress-bar">
								<div class="progress-fill <?php echo $percent === 100 ? 'progress-complete' : ''; ?>" style="width:<?php echo $percent; ?>%;"></div>
							</div>
							<span class="progress-text"><?php echo $percent; ?>%</span>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php endforeach; ?>
	</div>

	<!-- Bulk Translation Form -->
	<div class="n8npress-section">
		<h2><?php _e( 'Bulk Translation', 'n8npress' ); ?></h2>
		<p class="description"><?php _e( 'Trigger AI translation for multiple items at once. Content is sent to n8n workflow for AI-powered translation.', 'n8npress' ); ?></p>

		<form method="post" class="n8npress-bulk-form">
			<?php wp_nonce_field( 'n8npress_translation_nonce' ); ?>
			<div class="n8npress-bulk-row">
				<label>
					<?php _e( 'Post Type:', 'n8npress' ); ?>
					<select name="translate_post_type">
						<option value="product"><?php _e( 'Products', 'n8npress' ); ?></option>
						<option value="post"><?php _e( 'Posts', 'n8npress' ); ?></option>
						<option value="page"><?php _e( 'Pages', 'n8npress' ); ?></option>
					</select>
				</label>
				<label>
					<?php _e( 'Language:', 'n8npress' ); ?>
					<select name="translate_language">
						<?php foreach ( $target_langs as $lang ) : ?>
						<option value="<?php echo esc_attr( $lang ); ?>">
							<?php echo esc_html( strtoupper( $lang ) . ' — ' . ( $language_names[ $lang ] ?? $lang ) ); ?>
						</option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<?php _e( 'Limit:', 'n8npress' ); ?>
					<select name="translate_limit">
						<option value="5">5</option>
						<option value="10" selected>10</option>
						<option value="20">20</option>
						<option value="50">50</option>
					</select>
				</label>
				<button type="submit" name="n8npress_trigger_translation" class="button button-primary"
						<?php echo empty( $webhook_url ) ? 'disabled' : ''; ?>>
					<span class="dashicons dashicons-controls-play" style="margin-top:4px;"></span>
					<?php _e( 'Start Translation', 'n8npress' ); ?>
				</button>
			</div>
		</form>

		<?php if ( empty( $webhook_url ) ) : ?>
		<div class="n8npress-info-box" style="margin-top:12px;">
			<span class="dashicons dashicons-warning" style="color:#eab308;"></span>
			<?php _e( 'n8n webhook URL not configured.', 'n8npress' ); ?>
			<a href="<?php echo admin_url( 'admin.php?page=n8npress-settings&tab=connection' ); ?>"><?php _e( 'Configure', 'n8npress' ); ?></a>
		</div>
		<?php endif; ?>
	</div>

	<?php endif; // target_langs check ?>

</div>

<style>
.n8npress-translation-coverage { margin-bottom: 24px; }
.n8npress-translation-coverage h3 {
	font-size: 14px;
	font-weight: 600;
	margin: 0 0 12px 0;
	padding-bottom: 8px;
	border-bottom: 1px solid #f3f4f6;
}
.n8npress-progress-bar {
	display: inline-block;
	width: 120px;
	height: 8px;
	background: #f3f4f6;
	border-radius: 4px;
	overflow: hidden;
	vertical-align: middle;
}
.progress-fill {
	height: 100%;
	background: #6366f1;
	border-radius: 4px;
	transition: width 0.3s;
}
.progress-fill.progress-complete { background: #16a34a; }
.progress-text {
	font-size: 12px;
	font-weight: 600;
	margin-left: 8px;
	color: #6b7280;
}
.n8npress-bulk-form { margin-top: 12px; }
.n8npress-bulk-row {
	display: flex;
	gap: 16px;
	align-items: flex-end;
	flex-wrap: wrap;
}
.n8npress-bulk-row label {
	display: flex;
	flex-direction: column;
	gap: 4px;
	font-size: 13px;
	font-weight: 500;
}
.n8npress-bulk-row select { min-width: 140px; }
.n8npress-bulk-row .button { margin-bottom: 1px; }
</style>
