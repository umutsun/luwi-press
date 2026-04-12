<?php
/**
 * LuwiPress Translation Manager
 *
 * Step-based translation dashboard:
 *   Step 1 — Translate taxonomies (categories, tags)
 *   Step 2 — Translate content (products, posts, pages)
 *
 * Reads languages from WPML/Polylang via Plugin Detector.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'luwipress' ) );
}

// ── Environment ──
$detector       = LuwiPress_Plugin_Detector::get_instance();
$translation    = $detector->detect_translation();
$plugin_name    = ucwords( str_replace( '-', ' ', $translation['plugin'] ) );
$default_lang   = $translation['default_language'] ?? 'en';
$active_langs   = $translation['active_languages'] ?? array();
$target_langs   = array_diff( $active_langs, array( $default_lang ) );
$webhook_url    = get_option( 'luwipress_seo_webhook_url', '' );
$is_wpml        = 'wpml' === $translation['plugin'];

$language_names = array(
	'tr' => 'Türkçe', 'en' => 'English', 'de' => 'Deutsch', 'fr' => 'Français',
	'ar' => 'العربية', 'es' => 'Español', 'it' => 'Italiano', 'nl' => 'Nederlands',
	'ru' => 'Русский', 'ja' => '日本語', 'zh' => '中文', 'pt-pt' => 'Português',
	'ko' => '한국어', 'sv' => 'Svenska', 'pl' => 'Polski', 'uk' => 'Українська',
);

// ── Handle content translation trigger ──
if ( isset( $_POST['luwipress_trigger_translation'] ) && check_admin_referer( 'luwipress_translation_nonce' ) ) {
	$lang  = sanitize_text_field( $_POST['translate_language'] ?? '' );
	$type  = sanitize_text_field( $_POST['translate_post_type'] ?? 'product' );
	$limit = absint( $_POST['translate_limit'] ?? 10 );

	if ( ! empty( $lang ) && ! empty( $webhook_url ) ) {
		$url = trailingslashit( $webhook_url ) . 'translation-request';
		$response = wp_remote_post( $url, array(
			'timeout' => 15,
			'headers' => array( 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . get_option( 'luwipress_seo_api_token', '' ) ),
			'body'    => wp_json_encode( array( 'event' => 'translate_missing', 'target_languages' => $lang, 'post_type' => $type, 'limit' => $limit, 'fetch_pending' => true, 'site_url' => get_site_url() ) ),
		) );
		$code = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
		if ( ! is_wp_error( $response ) && $code >= 200 && $code < 300 ) {
			$type_label = get_post_type_object( $type )->labels->name ?? $type;
			echo '<div class="notice notice-success is-dismissible"><p>' . sprintf( __( 'Translation started: %s %s, up to %d items.', 'luwipress' ), strtoupper( $lang ), $type_label, $limit ) . '</p></div>';
			LuwiPress_Logger::log( 'Bulk translation triggered: ' . strtoupper( $lang ), 'info', array( 'language' => $lang, 'type' => $type, 'limit' => $limit ) );
		} else {
			$err = is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . $code;
			echo '<div class="notice notice-error is-dismissible"><p>' . sprintf( __( 'Translation trigger failed: %s', 'luwipress' ), esc_html( $err ) ) . '</p></div>';
		}
	}
}

// ── Taxonomy types (needed before handler) ──
$tax_types = array( 'product_cat' => 'Product Categories', 'product_tag' => 'Product Tags', 'category' => 'Post Categories', 'post_tag' => 'Post Tags' );

// ── Handle taxonomy translation trigger ──
if ( isset( $_POST['luwipress_trigger_taxonomy_translation'] ) && check_admin_referer( 'luwipress_translation_nonce' ) ) {
	$tax_slug      = sanitize_text_field( $_POST['translate_taxonomy'] ?? '' );
	$tax_languages = sanitize_text_field( $_POST['translate_tax_languages'] ?? '' );

	if ( ! empty( $tax_slug ) && ! empty( $webhook_url ) ) {
		$url = trailingslashit( $webhook_url ) . 'translation-request';
		$response = wp_remote_post( $url, array(
			'timeout' => 15,
			'headers' => array( 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . get_option( 'luwipress_seo_api_token', '' ) ),
			'body'    => wp_json_encode( array(
				'event'            => 'taxonomy_translation_request',
				'taxonomy'         => $tax_slug,
				'target_languages' => $tax_languages,
				'source_language'  => $default_lang,
				'site_url'         => get_site_url(),
				'callback_url'     => get_site_url( null, '/wp-json/luwipress/v1/translation/taxonomy-callback' ),
				'api_token'        => get_option( 'luwipress_seo_api_token', '' ),
				'fetch_endpoint'   => get_site_url( null, '/wp-json/luwipress/v1/translation/taxonomy-missing' ),
			) ),
		) );
		$code = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
		if ( ! is_wp_error( $response ) && $code >= 200 && $code < 300 ) {
			$tax_label = $tax_types[ $tax_slug ] ?? $tax_slug;
			echo '<div class="notice notice-success is-dismissible"><p>' . sprintf( __( 'Taxonomy translation started: %s for all languages.', 'luwipress' ), esc_html( $tax_label ) ) . '</p></div>';
			LuwiPress_Logger::log( 'Taxonomy translation triggered: ' . $tax_slug, 'info', array( 'taxonomy' => $tax_slug, 'languages' => $tax_languages ) );
		} else {
			$err = is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . $code;
			echo '<div class="notice notice-error is-dismissible"><p>' . sprintf( __( 'Taxonomy translation failed: %s', 'luwipress' ), esc_html( $err ) ) . '</p></div>';
		}
	}
}

// ── Calculate coverage ──
global $wpdb;
$content_types = array( 'product' => 'var(--n8n-primary, #6366f1)', 'post' => 'var(--n8n-blue, #2563eb)', 'page' => 'var(--n8n-success, #16a34a)' );
$coverage = array();

foreach ( $content_types as $pt => $color ) {
	$pt_obj = get_post_type_object( $pt );
	if ( ! $pt_obj ) continue;

	$total = 0;
	$langs = array();

	if ( $is_wpml ) {
		$element_type = 'post_' . $pt;
		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT t.element_id) FROM {$wpdb->prefix}icl_translations t
			 WHERE t.element_type = %s AND t.language_code = %s AND t.source_language_code IS NULL
			   AND t.element_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish')",
			$element_type, $default_lang, $pt
		) );

		// Fallback: if WPML shows 0 but WP has published content, use WP count
		// (happens when pages were created before WPML activation)
		if ( 0 === $total ) {
			$wp_total = absint( wp_count_posts( $pt )->publish ?? 0 );
			if ( $wp_total > 0 ) {
				$total = $wp_total;
			}
		}

		if ( $total > 0 ) {
			foreach ( $target_langs as $lang ) {
				$langs[ $lang ] = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(DISTINCT t.element_id) FROM {$wpdb->prefix}icl_translations t
					 WHERE t.element_type = %s AND t.language_code = %s
					   AND t.element_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ('publish','draft','private'))",
					$element_type, $lang, $pt
				) );
			}
		}
	} else {
		$total = absint( wp_count_posts( $pt )->publish ?? 0 );
	}

	$coverage[ $pt ] = array( 'label' => $pt_obj->labels->name, 'total' => $total, 'languages' => $langs, 'color' => $color );
}

// ── Taxonomy coverage ──
$tax_coverage = array();
foreach ( $tax_types as $tax => $label ) {
	if ( ! taxonomy_exists( $tax ) ) continue;
	if ( $is_wpml ) {
		$el_type = 'tax_' . $tax;
		// Count only originals that actually exist as valid terms
		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}icl_translations t
			 INNER JOIN {$wpdb->term_taxonomy} tt ON t.element_id = tt.term_id AND tt.taxonomy = %s
			 WHERE t.element_type = %s AND t.language_code = %s AND t.source_language_code IS NULL",
			$tax, $el_type, $default_lang
		) );

		$langs = array();
		foreach ( $target_langs as $lang ) {
			// Count only translations whose trid matches a real original term
			$langs[ $lang ] = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}icl_translations t
				 INNER JOIN {$wpdb->term_taxonomy} tt ON t.element_id = tt.term_id AND tt.taxonomy = %s
				 WHERE t.element_type = %s AND t.language_code = %s AND t.source_language_code IS NOT NULL
				   AND t.trid IN (
				       SELECT trid FROM {$wpdb->prefix}icl_translations
				       WHERE element_type = %s AND language_code = %s AND source_language_code IS NULL
				   )",
				$tax, $el_type, $lang, $el_type, $default_lang
			) );
		}
		if ( $total > 0 ) {
			$tax_coverage[ $tax ] = array( 'label' => $label, 'total' => $total, 'languages' => $langs );
		}
	}
}

// ── Check taxonomy completeness ──
$tax_total_missing_all = 0;
foreach ( $tax_coverage as $td ) {
	foreach ( $target_langs as $lang ) {
		$tax_total_missing_all += max( 0, $td['total'] - ( $td['languages'][ $lang ] ?? 0 ) );
	}
}
$tax_complete = ( $tax_total_missing_all === 0 );

// ── Overall stats ──
$total_content = 0;
$total_translated = 0;
$total_possible = 0;
foreach ( $coverage as $c ) {
	$total_content += $c['total'];
	foreach ( $c['languages'] as $count ) { $total_translated += $count; }
	$total_possible += $c['total'] * count( $target_langs );
}
$overall_pct = $total_possible > 0 ? round( ( $total_translated / $total_possible ) * 100 ) : 0;
$pct_color = $overall_pct >= 80 ? '#16a34a' : ( $overall_pct >= 50 ? '#f59e0b' : '#dc2626' );
?>

<style>
.n8n-tm { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
.n8n-tm h1 { font-size: 22px; font-weight: 600; margin: 0 0 20px; padding: 0; }
.n8n-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 24px; }
.n8n-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; }
.n8n-card-label { font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 4px; }
.n8n-card-value { font-size: 28px; font-weight: 700; line-height: 1.2; }
.n8n-card-sub { font-size: 12px; color: #6b7280; margin-top: 2px; }
.n8n-bar { height: 6px; background: #e5e7eb; border-radius: 3px; overflow: hidden; margin-top: 8px; }
.n8n-bar-fill { height: 100%; border-radius: 3px; transition: width .3s; }
.n8n-step { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 16px; overflow: hidden; }
.n8n-step-header { padding: 14px 20px; border-bottom: 1px solid #f3f4f6; display: flex; align-items: center; gap: 10px; }
.n8n-badge { font-size: 11px; font-weight: 700; color: #fff; padding: 3px 10px; border-radius: 12px; white-space: nowrap; }
.n8n-step-title { font-size: 15px; font-weight: 600; margin: 0; }
.n8n-step-count { font-size: 13px; font-weight: 400; color: #6b7280; }
.n8n-hint { padding: 10px 20px; font-size: 13px; border-bottom: 1px solid #f3f4f6; }
.n8n-hint-warn { background: #fffbeb; color: #92400e; }
.n8n-hint-info { background: #f8fafc; color: #6b7280; }
.n8n-step-body { padding: 16px 20px; }
.n8n-tax-row { display: flex; align-items: center; gap: 14px; margin-bottom: 14px; flex-wrap: wrap; }
.n8n-tax-name { font-size: 13px; font-weight: 600; color: #1f2937; min-width: 180px; }
.n8n-tax-name span { font-weight: 400; color: #9ca3af; }
.n8n-lang-bars { display: flex; gap: 14px; flex-wrap: wrap; }
.n8n-lang-bar { display: flex; align-items: center; gap: 5px; font-size: 12px; }
.n8n-lang-bar strong { min-width: 20px; }
.n8n-mini-bar { width: 50px; height: 5px; background: #e5e7eb; border-radius: 3px; overflow: hidden; }
.n8n-mini-fill { height: 100%; border-radius: 3px; }
.n8n-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.n8n-table th { padding: 10px 20px; text-align: left; background: #f8fafc; font-weight: 500; color: #6b7280; font-size: 12px; text-transform: uppercase; letter-spacing: .3px; }
.n8n-table td { padding: 12px 20px; border-top: 1px solid #f3f4f6; }
.n8n-table .center { text-align: center; }
.n8n-progress { display: flex; align-items: center; gap: 8px; }
.n8n-progress-bar { flex: 1; height: 7px; background: #e5e7eb; border-radius: 4px; overflow: hidden; }
.n8n-progress-fill { height: 100%; border-radius: 4px; transition: width .3s; }
.n8n-progress-label { font-size: 12px; font-weight: 600; color: #374151; min-width: 36px; text-align: right; }
.n8n-check { color: #16a34a; font-weight: 600; font-size: 12px; }
.n8n-miss { color: #dc2626; font-weight: 600; }
.n8n-tools { padding: 16px 20px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
.n8n-tools-hint { font-size: 12px; color: #9ca3af; }
</style>

<div class="wrap n8n-tm">
	<h1><?php esc_html_e( 'Translation Manager', 'luwipress' ); ?></h1>

	<?php if ( empty( $target_langs ) ) : ?>
		<div class="notice notice-info"><p><?php printf( __( 'Configure target languages in %s to start translating.', 'luwipress' ), $plugin_name ); ?></p></div>
	<?php else : ?>

	<!-- OVERVIEW -->
	<div class="n8n-cards">
		<div class="n8n-card" style="border-left: 3px solid <?php echo $pct_color; ?>;">
			<div class="n8n-card-label"><?php esc_html_e( 'Overall Coverage', 'luwipress' ); ?></div>
			<div class="n8n-card-value" style="color:<?php echo $pct_color; ?>;"><?php echo $overall_pct; ?>%</div>
			<div class="n8n-bar"><div class="n8n-bar-fill" style="width:<?php echo $overall_pct; ?>%;background:<?php echo $pct_color; ?>;"></div></div>
		</div>
		<div class="n8n-card">
			<div class="n8n-card-label"><?php esc_html_e( 'Content Items', 'luwipress' ); ?></div>
			<div class="n8n-card-value"><?php echo $total_content; ?></div>
			<div class="n8n-card-sub"><?php echo count( $target_langs ); ?> <?php esc_html_e( 'languages', 'luwipress' ); ?></div>
		</div>
		<div class="n8n-card">
			<div class="n8n-card-label"><?php esc_html_e( 'Translated', 'luwipress' ); ?></div>
			<div class="n8n-card-value" style="color:#16a34a;"><?php echo $total_translated; ?></div>
			<div class="n8n-card-sub"><?php echo $total_possible; ?> <?php esc_html_e( 'needed', 'luwipress' ); ?></div>
		</div>
		<div class="n8n-card">
			<div class="n8n-card-label"><?php esc_html_e( 'Backend', 'luwipress' ); ?></div>
			<div style="font-size:15px;font-weight:600;margin-top:6px;"><?php echo esc_html( $plugin_name ); ?></div>
			<div class="n8n-card-sub"><?php echo esc_html( strtoupper( $default_lang ) ); ?> &rarr; <?php echo esc_html( implode( ' ', array_map( 'strtoupper', $target_langs ) ) ); ?></div>
		</div>
	</div>

	<!-- ─── STEP 1: TAXONOMIES ─── -->
	<?php if ( ! empty( $tax_coverage ) ) : ?>
	<div class="n8n-step" style="border-left: 3px solid <?php echo $tax_complete ? '#16a34a' : '#f59e0b'; ?>;">
		<div class="n8n-step-header">
			<span class="n8n-badge" style="background:<?php echo $tax_complete ? '#16a34a' : '#f59e0b'; ?>;">1</span>
			<h2 class="n8n-step-title"><?php esc_html_e( 'Translate Taxonomies', 'luwipress' ); ?></h2>
			<?php if ( $tax_complete ) : ?>
				<span class="n8n-check"><?php esc_html_e( 'Complete', 'luwipress' ); ?></span>
			<?php endif; ?>
		</div>
		<?php if ( ! $tax_complete ) : ?>
		<div class="n8n-hint n8n-hint-warn"><?php esc_html_e( 'Translate categories and tags first so products are assigned to the correct translated categories.', 'luwipress' ); ?></div>
		<?php endif; ?>
		<div class="n8n-step-body">
			<?php foreach ( $tax_coverage as $tax_slug => $tax_data ) :
				$tmiss = 0;
				foreach ( $target_langs as $lang ) { $tmiss += max( 0, $tax_data['total'] - ( $tax_data['languages'][ $lang ] ?? 0 ) ); }
			?>
			<div class="n8n-tax-row">
				<div class="n8n-tax-name"><?php echo esc_html( $tax_data['label'] ); ?> <span>(<?php echo $tax_data['total']; ?>)</span></div>
				<?php if ( $tmiss > 0 && ! empty( $webhook_url ) ) : ?>
				<form method="post" style="display:inline;">
					<?php wp_nonce_field( 'luwipress_translation_nonce' ); ?>
					<input type="hidden" name="translate_taxonomy" value="<?php echo esc_attr( $tax_slug ); ?>" />
					<input type="hidden" name="translate_tax_languages" value="<?php echo esc_attr( implode( ',', $target_langs ) ); ?>" />
					<button type="submit" name="luwipress_trigger_taxonomy_translation" class="button button-small button-primary">
						<?php printf( __( 'Translate All (%d)', 'luwipress' ), $tmiss ); ?>
					</button>
				</form>
				<?php elseif ( $tmiss === 0 ) : ?>
					<span class="n8n-check"><?php esc_html_e( 'Complete', 'luwipress' ); ?></span>
				<?php endif; ?>
				<div class="n8n-lang-bars">
					<?php foreach ( $target_langs as $lang ) :
						$d = $tax_data['languages'][ $lang ] ?? 0;
						$m = max( 0, $tax_data['total'] - $d );
						$p = $tax_data['total'] > 0 ? round( ( $d / $tax_data['total'] ) * 100 ) : 0;
					?>
					<div class="n8n-lang-bar">
						<strong><?php echo esc_html( strtoupper( $lang ) ); ?></strong>
						<div class="n8n-mini-bar"><div class="n8n-mini-fill" style="width:<?php echo $p; ?>%;background:<?php echo $p >= 100 ? '#16a34a' : '#6366f1'; ?>;"></div></div>
						<span style="color:<?php echo $m > 0 ? '#dc2626' : '#16a34a'; ?>;font-weight:600;"><?php echo $p; ?>%</span>
					</div>
					<?php endforeach; ?>
				</div>
			</div>
			<?php endforeach; ?>
		</div>
	</div>
	<?php endif; ?>

	<!-- ─── STEP 2: CONTENT ─── -->
	<?php foreach ( $coverage as $pt => $data ) :
		$all_done = true;
		foreach ( $target_langs as $lang ) { if ( ( $data['languages'][ $lang ] ?? 0 ) < $data['total'] ) { $all_done = false; break; } }
	?>
	<div class="n8n-step" style="border-left: 3px solid <?php echo $all_done ? '#16a34a' : $data['color']; ?>;">
		<div class="n8n-step-header">
			<span class="n8n-badge" style="background:<?php echo $tax_complete ? $data['color'] : '#9ca3af'; ?>;">2</span>
			<h2 class="n8n-step-title"><?php echo esc_html( $data['label'] ); ?></h2>
			<span class="n8n-step-count"><?php echo $data['total']; ?> <?php esc_html_e( 'published', 'luwipress' ); ?></span>
			<?php if ( $all_done ) : ?>
				<span class="n8n-check"><?php esc_html_e( 'Complete', 'luwipress' ); ?></span>
			<?php endif; ?>
		</div>
		<?php if ( ! $tax_complete && $data['total'] > 0 ) : ?>
		<div class="n8n-hint n8n-hint-info"><?php esc_html_e( 'Complete Step 1 (Taxonomies) first for correct category assignments.', 'luwipress' ); ?></div>
		<?php endif; ?>
		<?php if ( $data['total'] === 0 ) : ?>
		<div class="n8n-step-body">
			<p style="color:#6b7280;margin:0;"><?php printf( __( 'No published %s found in the default language.', 'luwipress' ), strtolower( $data['label'] ) ); ?></p>
		</div>
		<?php else : ?>
		<table class="n8n-table">
			<thead><tr>
				<th><?php esc_html_e( 'Language', 'luwipress' ); ?></th>
				<th class="center" style="width:80px;"><?php esc_html_e( 'Done', 'luwipress' ); ?></th>
				<th class="center" style="width:80px;"><?php esc_html_e( 'Missing', 'luwipress' ); ?></th>
				<th style="width:200px;"><?php esc_html_e( 'Coverage', 'luwipress' ); ?></th>
				<th style="width:120px;"></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $target_langs as $lang ) :
				$done    = $data['languages'][ $lang ] ?? 0;
				$missing = max( 0, $data['total'] - $done );
				$pct     = $data['total'] > 0 ? round( ( $done / $data['total'] ) * 100 ) : 0;
				$bar_c   = $pct >= 100 ? '#16a34a' : ( $pct >= 60 ? '#6366f1' : '#f59e0b' );
			?>
			<tr>
				<td><strong><?php echo esc_html( strtoupper( $lang ) ); ?></strong> <span style="color:#6b7280;"><?php echo esc_html( $language_names[ $lang ] ?? $lang ); ?></span></td>
				<td class="center" style="font-weight:600;"><?php echo $done; ?></td>
				<td class="center"><?php echo $missing > 0 ? '<span class="n8n-miss">' . $missing . '</span>' : '<span class="n8n-check">0</span>'; ?></td>
				<td>
					<div class="n8n-progress">
						<div class="n8n-progress-bar"><div class="n8n-progress-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $bar_c; ?>;"></div></div>
						<span class="n8n-progress-label"><?php echo $pct; ?>%</span>
					</div>
				</td>
				<td>
					<?php if ( $missing > 0 && ! empty( $webhook_url ) ) : ?>
					<form method="post" style="display:inline;">
						<?php wp_nonce_field( 'luwipress_translation_nonce' ); ?>
						<input type="hidden" name="translate_language" value="<?php echo esc_attr( $lang ); ?>" />
						<input type="hidden" name="translate_post_type" value="<?php echo esc_attr( $pt ); ?>" />
						<input type="hidden" name="translate_limit" value="<?php echo min( $missing, 20 ); ?>" />
						<button type="submit" name="luwipress_trigger_translation" class="button button-primary button-small"><?php printf( __( 'Translate %d', 'luwipress' ), min( $missing, 20 ) ); ?></button>
					</form>
					<?php elseif ( $pct >= 100 ) : ?>
						<span class="n8n-check"><?php esc_html_e( 'Complete', 'luwipress' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
	</div>
	<?php endforeach; ?>

	<!-- ─── MAINTENANCE ─── -->
	<div class="n8n-step">
		<div class="n8n-step-header">
			<h2 class="n8n-step-title"><?php esc_html_e( 'Maintenance', 'luwipress' ); ?></h2>
		</div>
		<div class="n8n-tools" style="flex-direction:column;align-items:flex-start;gap:14px;">
			<div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
				<button type="button" id="luwipress-fix-categories" class="button button-primary"><?php esc_html_e( 'Fix Category Assignments', 'luwipress' ); ?></button>
				<span id="luwipress-fix-categories-result" style="font-size:13px;"></span>
				<span class="n8n-tools-hint"><?php esc_html_e( 'Re-assigns translated products to their correct translated categories.', 'luwipress' ); ?></span>
			</div>
			<div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
				<button type="button" id="luwipress-fix-images" class="button"><?php esc_html_e( 'Fix Translation Images', 'luwipress' ); ?></button>
				<span id="luwipress-fix-images-result" style="font-size:13px;"></span>
				<span class="n8n-tools-hint"><?php esc_html_e( 'Copies original product images to all translated products.', 'luwipress' ); ?></span>
			</div>
			<div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
				<button type="button" id="luwipress-clean-orphans" class="button" style="color:#dc2626;border-color:#dc2626;"><?php esc_html_e( 'Clean Orphan Translations', 'luwipress' ); ?></button>
				<span id="luwipress-clean-orphans-result" style="font-size:13px;"></span>
				<span class="n8n-tools-hint"><?php esc_html_e( 'Removes WPML translation records that have no matching original. Fixes inflated coverage counts.', 'luwipress' ); ?></span>
			</div>
		</div>
	</div>

	<script>
	document.getElementById('luwipress-fix-categories')?.addEventListener('click', function() {
		var btn = this, result = document.getElementById('luwipress-fix-categories-result');
		btn.disabled = true; btn.textContent = 'Fixing...';
		result.textContent = '';
		fetch(ajaxurl + '?action=luwipress_fix_category_assignments&nonce=<?php echo wp_create_nonce( 'luwipress_fix_categories' ); ?>', {method:'POST'})
			.then(function(r){return r.json()}).then(function(d){
				btn.disabled = false;
				btn.textContent = <?php echo wp_json_encode( __( 'Fix Category Assignments', 'luwipress' ) ); ?>;
				result.textContent = d.success
					? d.data.fixed + ' products fixed'
					: (d.data || 'Error');
				result.style.color = d.success ? '#16a34a' : '#dc2626';
			});
	});
	document.getElementById('luwipress-fix-images')?.addEventListener('click', function() {
		var btn = this, result = document.getElementById('luwipress-fix-images-result');
		btn.disabled = true; btn.textContent = <?php echo wp_json_encode( __( 'Fixing...', 'luwipress' ) ); ?>;
		result.textContent = '';
		fetch(ajaxurl + '?action=luwipress_fix_translation_images&nonce=<?php echo wp_create_nonce( 'luwipress_fix_images' ); ?>', {method:'POST'})
			.then(function(r){return r.json()}).then(function(d){
				btn.disabled = false;
				btn.textContent = <?php echo wp_json_encode( __( 'Fix Translation Images', 'luwipress' ) ); ?>;
				result.textContent = d.success
					? d.data.fixed + ' fixed'
					: (d.data || 'Error');
				result.style.color = d.success ? '#16a34a' : '#dc2626';
			});
	});
	document.getElementById('luwipress-clean-orphans')?.addEventListener('click', function() {
		if (!confirm(<?php echo wp_json_encode( __( 'This will delete orphan WPML translation records (terms and posts with no matching original). Affected content can be re-translated. Continue?', 'luwipress' ) ); ?>)) return;
		var btn = this, result = document.getElementById('luwipress-clean-orphans-result');
		btn.disabled = true; btn.textContent = <?php echo wp_json_encode( __( 'Cleaning...', 'luwipress' ) ); ?>;
		result.textContent = '';
		fetch(ajaxurl + '?action=luwipress_clean_orphan_translations&nonce=<?php echo wp_create_nonce( 'luwipress_clean_orphans' ); ?>', {method:'POST'})
			.then(function(r){return r.json()}).then(function(d){
				btn.disabled = false;
				btn.textContent = <?php echo wp_json_encode( __( 'Clean Orphan Translations', 'luwipress' ) ); ?>;
				if (d.success) {
					var msg = d.data.terms_removed + ' orphan terms, ' + d.data.posts_removed + ' orphan posts removed';
					result.textContent = msg;
					result.style.color = d.data.terms_removed + d.data.posts_removed > 0 ? '#16a34a' : '#6b7280';
					if (d.data.terms_removed + d.data.posts_removed > 0) setTimeout(function(){ location.reload(); }, 1500);
				} else {
					result.textContent = d.data || 'Error';
					result.style.color = '#dc2626';
				}
			});
	});
	</script>

	<?php endif; ?>
</div>
