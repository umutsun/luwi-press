<?php
/**
 * LuwiPress Translation Manager
 *
 * Modern step-based translation dashboard:
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
$processing_mode = LuwiPress_AI_Engine::get_mode();
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

// ── Handle content translation trigger ──
if ( isset( $_POST['luwipress_trigger_translation'] ) && check_admin_referer( 'luwipress_translation_nonce' ) ) {
	$lang  = sanitize_text_field( $_POST['translate_language'] ?? '' );
	$type  = sanitize_text_field( $_POST['translate_post_type'] ?? 'product' );
	$limit = absint( $_POST['translate_limit'] ?? 10 );

	if ( ! empty( $lang ) ) {
		if ( 'local' === $processing_mode ) {
			$type_label = get_post_type_object( $type )->labels->name ?? $type;
			echo '<div class="notice notice-success is-dismissible"><p>' . sprintf( esc_html__( 'Translation started: %s %s, up to %d items via Local AI.', 'luwipress' ), strtoupper( $lang ), esc_html( $type_label ), $limit ) . '</p></div>';
		} elseif ( ! empty( $webhook_url ) ) {
			$url = trailingslashit( $webhook_url ) . 'translation-request';
			$response = wp_remote_post( $url, array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . get_option( 'luwipress_seo_api_token', '' ) ),
				'body'    => wp_json_encode( array( 'event' => 'translate_missing', 'target_languages' => $lang, 'post_type' => $type, 'limit' => $limit, 'fetch_pending' => true, 'site_url' => get_site_url() ) ),
			) );
			$code = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
			if ( ! is_wp_error( $response ) && $code >= 200 && $code < 300 ) {
				$type_label = get_post_type_object( $type )->labels->name ?? $type;
				echo '<div class="notice notice-success is-dismissible"><p>' . sprintf( esc_html__( 'Translation started: %s %s, up to %d items.', 'luwipress' ), strtoupper( $lang ), esc_html( $type_label ), $limit ) . '</p></div>';
			} else {
				$err = is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . $code;
				echo '<div class="notice notice-error is-dismissible"><p>' . sprintf( esc_html__( 'Translation trigger failed: %s', 'luwipress' ), esc_html( $err ) ) . '</p></div>';
			}
		}
	}
}

// ── Taxonomy types ──
$tax_types = array( 'product_cat' => 'Product Categories', 'product_tag' => 'Product Tags', 'category' => 'Post Categories', 'post_tag' => 'Post Tags' );

// ── Handle taxonomy translation trigger ──
if ( isset( $_POST['luwipress_trigger_taxonomy_translation'] ) && check_admin_referer( 'luwipress_translation_nonce' ) ) {
	$tax_slug      = sanitize_text_field( $_POST['translate_taxonomy'] ?? '' );
	$tax_languages = sanitize_text_field( $_POST['translate_tax_languages'] ?? '' );

	if ( ! empty( $tax_slug ) ) {
		if ( 'local' === $processing_mode ) {
			$tax_label = $tax_types[ $tax_slug ] ?? $tax_slug;
			echo '<div class="notice notice-success is-dismissible"><p>' . sprintf( esc_html__( 'Taxonomy translation started: %s via Local AI.', 'luwipress' ), esc_html( $tax_label ) ) . '</p></div>';
		} elseif ( ! empty( $webhook_url ) ) {
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
					'callback_url'     => rest_url( 'luwipress/v1/translation/taxonomy-callback' ),
					'api_token'        => get_option( 'luwipress_seo_api_token', '' ),
					'fetch_endpoint'   => rest_url( 'luwipress/v1/translation/taxonomy-missing' ),
				) ),
			) );
			$code = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
			if ( ! is_wp_error( $response ) && $code >= 200 && $code < 300 ) {
				$tax_label = $tax_types[ $tax_slug ] ?? $tax_slug;
				echo '<div class="notice notice-success is-dismissible"><p>' . sprintf( esc_html__( 'Taxonomy translation started: %s for all languages.', 'luwipress' ), esc_html( $tax_label ) ) . '</p></div>';
			} else {
				$err = is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . $code;
				echo '<div class="notice notice-error is-dismissible"><p>' . sprintf( esc_html__( 'Taxonomy translation failed: %s', 'luwipress' ), esc_html( $err ) ) . '</p></div>';
			}
		}
	}
}

// ── Calculate coverage ──
global $wpdb;
$content_types = array( 'product' => 'var(--n8n-primary)', 'post' => 'var(--n8n-blue)', 'page' => 'var(--n8n-success)' );
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
		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}icl_translations t
			 INNER JOIN {$wpdb->term_taxonomy} tt ON t.element_id = tt.term_id AND tt.taxonomy = %s
			 WHERE t.element_type = %s AND t.language_code = %s AND t.source_language_code IS NULL",
			$tax, $el_type, $default_lang
		) );

		$langs = array();
		foreach ( $target_langs as $lang ) {
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
$missing_count = $total_possible - $total_translated;
?>

<div class="wrap n8n-tm">

	<!-- ═══ HEADER ═══ -->
	<div class="tm-header">
		<div class="tm-header-left">
			<h1 class="tm-title">
				<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--n8n-primary)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
					<path d="M5 8l6 6"/><path d="M4 14l6 6"/><path d="M2 5h12"/><path d="M7 2h1"/>
					<path d="M22 22l-5-10-5 10"/><path d="M14 18h6"/>
				</svg>
				<?php esc_html_e( 'Translation Manager', 'luwipress' ); ?>
			</h1>
			<span class="tm-engine-badge">
				<?php if ( 'local' === $processing_mode ) : ?>
					<span class="dashicons dashicons-desktop" style="font-size:14px;width:14px;height:14px;"></span> <?php esc_html_e( 'Local AI', 'luwipress' ); ?>
				<?php else : ?>
					<span class="dashicons dashicons-cloud" style="font-size:14px;width:14px;height:14px;"></span> n8n
				<?php endif; ?>
			</span>
		</div>
		<div class="tm-header-right">
			<span class="tm-backend-pill">
				<span class="dashicons dashicons-translation" style="font-size:16px;width:16px;height:16px;"></span>
				<?php echo esc_html( $plugin_name ); ?>
			</span>
			<span class="tm-lang-flow">
				<?php echo esc_html( strtoupper( $default_lang ) ); ?>
				<svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M3 8h10M10 5l3 3-3 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
				<?php echo esc_html( implode( ' ', array_map( 'strtoupper', $target_langs ) ) ); ?>
			</span>
		</div>
	</div>

	<?php if ( empty( $target_langs ) ) : ?>
		<div class="tm-empty-state">
			<svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="var(--n8n-border)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
				<path d="M5 8l6 6"/><path d="M4 14l6 6"/><path d="M2 5h12"/><path d="M7 2h1"/>
				<path d="M22 22l-5-10-5 10"/><path d="M14 18h6"/>
			</svg>
			<h3><?php esc_html_e( 'No target languages configured', 'luwipress' ); ?></h3>
			<p><?php printf( esc_html__( 'Configure target languages in %s to start translating.', 'luwipress' ), '<strong>' . esc_html( $plugin_name ) . '</strong>' ); ?></p>
		</div>
	<?php else : ?>

	<!-- ═══ OVERVIEW STATS ═══ -->
	<div class="tm-stats">
		<?php
		$stat_items = array(
			array(
				'icon'  => 'dashicons-chart-pie',
				'label' => __( 'Overall Coverage', 'luwipress' ),
				'value' => $overall_pct . '%',
				'color' => $overall_pct >= 80 ? 'var(--n8n-success)' : ( $overall_pct >= 50 ? 'var(--n8n-warning)' : 'var(--n8n-error)' ),
				'bar'   => $overall_pct,
			),
			array(
				'icon'  => 'dashicons-admin-page',
				'label' => __( 'Content Items', 'luwipress' ),
				'value' => number_format_i18n( $total_content ),
				'sub'   => count( $target_langs ) . ' ' . __( 'languages', 'luwipress' ),
			),
			array(
				'icon'  => 'dashicons-yes-alt',
				'label' => __( 'Translated', 'luwipress' ),
				'value' => number_format_i18n( $total_translated ),
				'color' => 'var(--n8n-success)',
				'sub'   => sprintf( __( '%s total needed', 'luwipress' ), number_format_i18n( $total_possible ) ),
			),
			array(
				'icon'  => 'dashicons-warning',
				'label' => __( 'Missing', 'luwipress' ),
				'value' => number_format_i18n( $missing_count ),
				'color' => $missing_count > 0 ? 'var(--n8n-error)' : 'var(--n8n-success)',
			),
		);
		foreach ( $stat_items as $si => $stat ) :
		?>
		<div class="tm-stat-card" style="animation-delay:<?php echo $si * 80; ?>ms;">
			<div class="tm-stat-icon" style="color:<?php echo $stat['color'] ?? 'var(--n8n-primary)'; ?>;">
				<span class="dashicons <?php echo esc_attr( $stat['icon'] ); ?>"></span>
			</div>
			<div class="tm-stat-body">
				<span class="tm-stat-label"><?php echo esc_html( $stat['label'] ); ?></span>
				<span class="tm-stat-value" style="color:<?php echo $stat['color'] ?? 'var(--n8n-text)'; ?>;"><?php echo esc_html( $stat['value'] ); ?></span>
				<?php if ( ! empty( $stat['bar'] ) ) : ?>
					<div class="tm-stat-bar"><div class="tm-stat-bar-fill" style="width:<?php echo (int) $stat['bar']; ?>%;background:<?php echo $stat['color']; ?>;"></div></div>
				<?php elseif ( ! empty( $stat['sub'] ) ) : ?>
					<span class="tm-stat-sub"><?php echo esc_html( $stat['sub'] ); ?></span>
				<?php endif; ?>
			</div>
		</div>
		<?php endforeach; ?>
	</div>

	<!-- ═══ STEP 1: TAXONOMIES ═══ -->
	<?php if ( ! empty( $tax_coverage ) ) : ?>
	<div class="tm-step <?php echo $tax_complete ? 'tm-step-done' : 'tm-step-active'; ?>">
		<div class="tm-step-header">
			<div class="tm-step-number <?php echo $tax_complete ? 'step-done' : 'step-active'; ?>">
				<?php if ( $tax_complete ) : ?>
					<span class="dashicons dashicons-yes"></span>
				<?php else : ?>
					1
				<?php endif; ?>
			</div>
			<div class="tm-step-info">
				<h2 class="tm-step-title"><?php esc_html_e( 'Translate Taxonomies', 'luwipress' ); ?></h2>
				<p class="tm-step-desc"><?php esc_html_e( 'Translate categories and tags first for correct product assignments.', 'luwipress' ); ?></p>
			</div>
			<?php if ( $tax_complete ) : ?>
				<span class="tm-complete-badge"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Complete', 'luwipress' ); ?></span>
			<?php else : ?>
				<span class="tm-pending-badge"><?php echo $tax_total_missing_all; ?> <?php esc_html_e( 'missing', 'luwipress' ); ?></span>
			<?php endif; ?>
		</div>
		<div class="tm-step-body">
			<?php foreach ( $tax_coverage as $tax_slug => $tax_data ) :
				$tmiss = 0;
				foreach ( $target_langs as $lang ) { $tmiss += max( 0, $tax_data['total'] - ( $tax_data['languages'][ $lang ] ?? 0 ) ); }
			?>
			<div class="tm-tax-row">
				<div class="tm-tax-name">
					<strong><?php echo esc_html( $tax_data['label'] ); ?></strong>
					<span><?php echo $tax_data['total']; ?> <?php esc_html_e( 'terms', 'luwipress' ); ?></span>
				</div>
				<div class="tm-lang-pills">
					<?php foreach ( $target_langs as $lang ) :
						$d = $tax_data['languages'][ $lang ] ?? 0;
						$p = $tax_data['total'] > 0 ? round( ( $d / $tax_data['total'] ) * 100 ) : 0;
						$is_done = $p >= 100;
					?>
					<div class="tm-lang-pill <?php echo $is_done ? 'pill-done' : 'pill-pending'; ?>">
						<span class="tm-flag"><?php echo $language_flags[ $lang ] ?? ''; ?></span>
						<span class="tm-lang-code"><?php echo esc_html( strtoupper( $lang ) ); ?></span>
						<span class="tm-lang-pct"><?php echo $p; ?>%</span>
						<div class="tm-micro-bar"><div class="tm-micro-fill" style="width:<?php echo $p; ?>%;"></div></div>
					</div>
					<?php endforeach; ?>
				</div>
				<?php if ( $tmiss > 0 ) : ?>
				<form method="post" class="tm-action-form">
					<?php wp_nonce_field( 'luwipress_translation_nonce' ); ?>
					<input type="hidden" name="translate_taxonomy" value="<?php echo esc_attr( $tax_slug ); ?>" />
					<input type="hidden" name="translate_tax_languages" value="<?php echo esc_attr( implode( ',', $target_langs ) ); ?>" />
					<button type="submit" name="luwipress_trigger_taxonomy_translation" class="tm-btn tm-btn-primary">
						<span class="dashicons dashicons-translation"></span>
						<?php printf( esc_html__( 'Translate %d', 'luwipress' ), $tmiss ); ?>
					</button>
				</form>
				<?php else : ?>
					<span class="tm-check"><span class="dashicons dashicons-yes-alt"></span></span>
				<?php endif; ?>
			</div>
			<?php endforeach; ?>
		</div>
	</div>
	<?php endif; ?>

	<!-- ═══ STEP 2: CONTENT ═══ -->
	<?php foreach ( $coverage as $pt => $data ) :
		$all_done = true;
		foreach ( $target_langs as $lang ) { if ( ( $data['languages'][ $lang ] ?? 0 ) < $data['total'] ) { $all_done = false; break; } }
		$step_num = ! empty( $tax_coverage ) ? 2 : 1;
	?>
	<div class="tm-step <?php echo $all_done ? 'tm-step-done' : ''; ?>">
		<div class="tm-step-header">
			<div class="tm-step-number <?php echo $all_done ? 'step-done' : ''; ?>" style="<?php echo ! $all_done ? 'background:' . esc_attr( $data['color'] ) . ';' : ''; ?>">
				<?php if ( $all_done ) : ?>
					<span class="dashicons dashicons-yes"></span>
				<?php else : ?>
					<?php echo $step_num; ?>
				<?php endif; ?>
			</div>
			<div class="tm-step-info">
				<h2 class="tm-step-title"><?php echo esc_html( $data['label'] ); ?></h2>
				<p class="tm-step-desc"><?php echo $data['total']; ?> <?php esc_html_e( 'published items', 'luwipress' ); ?></p>
			</div>
			<?php if ( $all_done ) : ?>
				<span class="tm-complete-badge"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Complete', 'luwipress' ); ?></span>
			<?php endif; ?>
		</div>

		<?php if ( $data['total'] === 0 ) : ?>
		<div class="tm-step-body">
			<p class="tm-muted"><?php printf( esc_html__( 'No published %s found in the default language.', 'luwipress' ), strtolower( $data['label'] ) ); ?></p>
		</div>
		<?php else : ?>
		<div class="tm-step-body tm-step-body-table">
			<table class="tm-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Language', 'luwipress' ); ?></th>
						<th class="tm-col-num"><?php esc_html_e( 'Done', 'luwipress' ); ?></th>
						<th class="tm-col-num"><?php esc_html_e( 'Missing', 'luwipress' ); ?></th>
						<th class="tm-col-progress"><?php esc_html_e( 'Coverage', 'luwipress' ); ?></th>
						<th class="tm-col-action"></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $target_langs as $lang ) :
					$done    = $data['languages'][ $lang ] ?? 0;
					$missing = max( 0, $data['total'] - $done );
					$pct     = $data['total'] > 0 ? round( ( $done / $data['total'] ) * 100 ) : 0;
				?>
				<tr class="tm-lang-row">
					<td class="tm-lang-cell">
						<span class="tm-flag-lg"><?php echo $language_flags[ $lang ] ?? ''; ?></span>
						<div>
							<strong><?php echo esc_html( strtoupper( $lang ) ); ?></strong>
							<span class="tm-lang-name"><?php echo esc_html( $language_names[ $lang ] ?? $lang ); ?></span>
						</div>
					</td>
					<td class="tm-col-num"><span class="tm-num-done"><?php echo $done; ?></span></td>
					<td class="tm-col-num">
						<?php if ( $missing > 0 ) : ?>
							<span class="tm-num-miss"><?php echo $missing; ?></span>
						<?php else : ?>
							<span class="tm-num-ok"><span class="dashicons dashicons-yes-alt"></span></span>
						<?php endif; ?>
					</td>
					<td class="tm-col-progress">
						<div class="tm-progress">
							<div class="tm-progress-track">
								<div class="tm-progress-fill" style="width:<?php echo $pct; ?>%;<?php
									if ( $pct >= 100 ) echo 'background:var(--n8n-success);';
									elseif ( $pct >= 60 ) echo 'background:var(--n8n-primary);';
									else echo 'background:var(--n8n-warning);';
								?>"></div>
							</div>
							<span class="tm-progress-pct"><?php echo $pct; ?>%</span>
						</div>
					</td>
					<td class="tm-col-action">
						<?php if ( $missing > 0 ) : ?>
						<form method="post" class="tm-action-form">
							<?php wp_nonce_field( 'luwipress_translation_nonce' ); ?>
							<input type="hidden" name="translate_language" value="<?php echo esc_attr( $lang ); ?>" />
							<input type="hidden" name="translate_post_type" value="<?php echo esc_attr( $pt ); ?>" />
							<input type="hidden" name="translate_limit" value="<?php echo min( $missing, 20 ); ?>" />
							<button type="submit" name="luwipress_trigger_translation" class="tm-btn tm-btn-primary tm-btn-sm">
								<span class="dashicons dashicons-translation"></span>
								<?php printf( esc_html__( 'Translate %d', 'luwipress' ), min( $missing, 20 ) ); ?>
							</button>
						</form>
						<?php elseif ( $pct >= 100 ) : ?>
							<span class="tm-check"><span class="dashicons dashicons-yes-alt"></span></span>
						<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php endif; ?>
	</div>
	<?php endforeach; ?>

	<!-- ═══ MAINTENANCE ═══ -->
	<div class="tm-step tm-step-maint">
		<div class="tm-step-header">
			<div class="tm-step-number" style="background:var(--n8n-gray);">
				<span class="dashicons dashicons-admin-tools" style="font-size:16px;width:16px;height:16px;"></span>
			</div>
			<div class="tm-step-info">
				<h2 class="tm-step-title"><?php esc_html_e( 'Maintenance Tools', 'luwipress' ); ?></h2>
				<p class="tm-step-desc"><?php esc_html_e( 'Fix common translation issues and clean up data.', 'luwipress' ); ?></p>
			</div>
		</div>
		<div class="tm-step-body tm-maint-tools">
			<div class="tm-tool-card">
				<div class="tm-tool-info">
					<strong><?php esc_html_e( 'Fix Category Assignments', 'luwipress' ); ?></strong>
					<span><?php esc_html_e( 'Re-assigns translated products to their correct translated categories.', 'luwipress' ); ?></span>
				</div>
				<button type="button" id="luwipress-fix-categories" class="tm-btn tm-btn-secondary">
					<span class="dashicons dashicons-category"></span> <?php esc_html_e( 'Fix', 'luwipress' ); ?>
				</button>
				<span id="luwipress-fix-categories-result" class="tm-tool-result"></span>
			</div>
			<div class="tm-tool-card">
				<div class="tm-tool-info">
					<strong><?php esc_html_e( 'Fix Translation Images', 'luwipress' ); ?></strong>
					<span><?php esc_html_e( 'Copies original product images to all translated products.', 'luwipress' ); ?></span>
				</div>
				<button type="button" id="luwipress-fix-images" class="tm-btn tm-btn-secondary">
					<span class="dashicons dashicons-format-image"></span> <?php esc_html_e( 'Fix', 'luwipress' ); ?>
				</button>
				<span id="luwipress-fix-images-result" class="tm-tool-result"></span>
			</div>
			<div class="tm-tool-card">
				<div class="tm-tool-info">
					<strong><?php esc_html_e( 'Clean Orphan Translations', 'luwipress' ); ?></strong>
					<span><?php esc_html_e( 'Removes WPML records with no matching original. Fixes inflated coverage.', 'luwipress' ); ?></span>
				</div>
				<button type="button" id="luwipress-clean-orphans" class="tm-btn tm-btn-danger">
					<span class="dashicons dashicons-trash"></span> <?php esc_html_e( 'Clean', 'luwipress' ); ?>
				</button>
				<span id="luwipress-clean-orphans-result" class="tm-tool-result"></span>
			</div>
		</div>
	</div>

	<script>
	document.getElementById('luwipress-fix-categories')?.addEventListener('click', function() {
		var btn = this, result = document.getElementById('luwipress-fix-categories-result');
		btn.disabled = true; btn.classList.add('tm-btn-loading');
		result.textContent = '';
		fetch(ajaxurl + '?action=luwipress_fix_category_assignments&nonce=<?php echo wp_create_nonce( 'luwipress_fix_categories' ); ?>', {method:'POST'})
			.then(function(r){return r.json()}).then(function(d){
				btn.disabled = false; btn.classList.remove('tm-btn-loading');
				result.textContent = d.success ? d.data.fixed + ' products fixed' : (d.data || 'Error');
				result.className = 'tm-tool-result ' + (d.success ? 'result-ok' : 'result-err');
			}).catch(function(){ btn.disabled = false; btn.classList.remove('tm-btn-loading'); result.textContent = 'Network error'; result.className = 'tm-tool-result result-err'; });
	});
	document.getElementById('luwipress-fix-images')?.addEventListener('click', function() {
		var btn = this, result = document.getElementById('luwipress-fix-images-result');
		btn.disabled = true; btn.classList.add('tm-btn-loading');
		result.textContent = '';
		fetch(ajaxurl + '?action=luwipress_fix_translation_images&nonce=<?php echo wp_create_nonce( 'luwipress_fix_images' ); ?>', {method:'POST'})
			.then(function(r){return r.json()}).then(function(d){
				btn.disabled = false; btn.classList.remove('tm-btn-loading');
				result.textContent = d.success ? d.data.fixed + ' fixed' : (d.data || 'Error');
				result.className = 'tm-tool-result ' + (d.success ? 'result-ok' : 'result-err');
			}).catch(function(){ btn.disabled = false; btn.classList.remove('tm-btn-loading'); result.textContent = 'Network error'; result.className = 'tm-tool-result result-err'; });
	});
	document.getElementById('luwipress-clean-orphans')?.addEventListener('click', function() {
		if (!confirm(<?php echo wp_json_encode( __( 'This will delete orphan WPML translation records. Continue?', 'luwipress' ) ); ?>)) return;
		var btn = this, result = document.getElementById('luwipress-clean-orphans-result');
		btn.disabled = true; btn.classList.add('tm-btn-loading');
		result.textContent = '';
		fetch(ajaxurl + '?action=luwipress_clean_orphan_translations&nonce=<?php echo wp_create_nonce( 'luwipress_clean_orphans' ); ?>', {method:'POST'})
			.then(function(r){return r.json()}).then(function(d){
				btn.disabled = false; btn.classList.remove('tm-btn-loading');
				if (d.success) {
					var msg = d.data.terms_removed + ' orphan terms, ' + d.data.posts_removed + ' orphan posts removed';
					result.textContent = msg;
					result.className = 'tm-tool-result ' + (d.data.terms_removed + d.data.posts_removed > 0 ? 'result-ok' : 'result-muted');
					if (d.data.terms_removed + d.data.posts_removed > 0) setTimeout(function(){ location.reload(); }, 1500);
				} else {
					result.textContent = d.data || 'Error';
					result.className = 'tm-tool-result result-err';
				}
			}).catch(function(){ btn.disabled = false; btn.classList.remove('tm-btn-loading'); result.textContent = 'Network error'; result.className = 'tm-tool-result result-err'; });
	});
	</script>

	<?php endif; ?>
</div>
