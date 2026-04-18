<?php
/**
 * LuwiPress Settings Page
 *
 * Centralized settings — Connection, AI Content, Translation, Security.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'luwipress' ) );
}

// Handle settings save
if ( isset( $_POST['luwipress_save_settings'] ) && check_admin_referer( 'luwipress_settings_nonce' ) ) {
	// Processing Mode
	update_option( 'luwipress_processing_mode', 'local' );
	update_option( 'luwipress_default_provider', sanitize_text_field( $_POST['luwipress_default_provider'] ?? 'anthropic' ) );

	// API Token
	update_option( 'luwipress_seo_api_token', sanitize_text_field( $_POST['luwipress_api_token'] ?? '' ) );

	// Provider model selections
	update_option( 'luwipress_anthropic_model', sanitize_text_field( $_POST['luwipress_anthropic_model'] ?? 'claude-haiku-4-5-20241022' ) );
	update_option( 'luwipress_openai_model', sanitize_text_field( $_POST['luwipress_openai_model'] ?? 'gpt-4o-mini' ) );
	update_option( 'luwipress_google_model', sanitize_text_field( $_POST['luwipress_google_model'] ?? 'gemini-2.0-flash' ) );

	// General
	update_option( 'luwipress_enable_logging', isset( $_POST['luwipress_enable_logging'] ) ? 1 : 0 );
	update_option( 'luwipress_log_level', sanitize_text_field( $_POST['luwipress_log_level'] ?? 'info' ) );
	update_option( 'luwipress_rate_limit', absint( $_POST['luwipress_rate_limit'] ?? 1000 ) );
	update_option( 'luwipress_api_timeout', absint( $_POST['luwipress_api_timeout'] ?? 30 ) );

	// AI Content
	update_option( 'luwipress_auto_enrich', isset( $_POST['luwipress_auto_enrich'] ) ? 1 : 0 );
	update_option( 'luwipress_target_language', sanitize_text_field( $_POST['luwipress_target_language'] ?? 'tr' ) );

	// Thin content auto-enrichment
	$thin_was_enabled = get_option( 'luwipress_auto_enrich_thin', false );
	$thin_now_enabled = isset( $_POST['luwipress_auto_enrich_thin'] ) ? 1 : 0;
	update_option( 'luwipress_auto_enrich_thin', $thin_now_enabled );
	update_option( 'luwipress_thin_content_threshold', absint( $_POST['luwipress_thin_content_threshold'] ?? 300 ) );
	update_option( 'luwipress_auto_enrich_batch_size', absint( $_POST['luwipress_auto_enrich_batch_size'] ?? 10 ) );

	// Schedule or unschedule thin content cron
	if ( $thin_now_enabled && ! $thin_was_enabled ) {
		if ( ! wp_next_scheduled( 'luwipress_auto_enrich_thin_cron' ) ) {
			wp_schedule_event( time(), 'daily', 'luwipress_auto_enrich_thin_cron' );
		}
	} elseif ( ! $thin_now_enabled && $thin_was_enabled ) {
		wp_clear_scheduled_hook( 'luwipress_auto_enrich_thin_cron' );
	}

	// AI API Keys
	update_option( 'luwipress_openai_api_key', sanitize_text_field( $_POST['luwipress_openai_api_key'] ?? '' ) );
	update_option( 'luwipress_anthropic_api_key', sanitize_text_field( $_POST['luwipress_anthropic_api_key'] ?? '' ) );
	update_option( 'luwipress_google_ai_api_key', sanitize_text_field( $_POST['luwipress_google_ai_api_key'] ?? '' ) );
	update_option( 'luwipress_ai_provider', sanitize_text_field( $_POST['luwipress_ai_provider'] ?? 'openai' ) );
	update_option( 'luwipress_ai_model', sanitize_text_field( $_POST['luwipress_ai_model'] ?? 'gpt-4o-mini' ) );
	update_option( 'luwipress_daily_token_limit', floatval( $_POST['luwipress_daily_token_limit'] ?? 1.00 ) );
	update_option( 'luwipress_max_output_tokens', absint( $_POST['luwipress_max_output_tokens'] ?? 1024 ) );

	// Image generation
	update_option( 'luwipress_image_provider', sanitize_text_field( $_POST['luwipress_image_provider'] ?? 'dall-e-3' ) );
	update_option( 'luwipress_enrich_generate_image', isset( $_POST['luwipress_enrich_generate_image'] ) ? 1 : 0 );

	// Translation
	update_option( 'luwipress_hreflang_mode', sanitize_text_field( $_POST['luwipress_hreflang_mode'] ?? 'auto' ) );
	$languages  = sanitize_text_field( $_POST['luwipress_translation_languages_text'] ?? '' );
	$lang_array = array_filter( array_map( 'trim', explode( ',', $languages ) ) );
	update_option( 'luwipress_translation_languages', $lang_array );

	// CRM Bridge thresholds
	update_option( 'luwipress_crm_vip_threshold', floatval( $_POST['luwipress_crm_vip_threshold'] ?? 1000 ) );
	update_option( 'luwipress_crm_active_days', absint( $_POST['luwipress_crm_active_days'] ?? 90 ) );
	update_option( 'luwipress_crm_at_risk_days', absint( $_POST['luwipress_crm_at_risk_days'] ?? 180 ) );
	update_option( 'luwipress_crm_loyal_orders', absint( $_POST['luwipress_crm_loyal_orders'] ?? 3 ) );

	// Open Claw
	update_option( 'luwipress_openclaw_url', esc_url_raw( $_POST['luwipress_openclaw_url'] ?? '' ) );

	// Open Claw channels
	update_option( 'luwipress_telegram_bot_token', sanitize_text_field( $_POST['luwipress_telegram_bot_token'] ?? '' ) );
	update_option( 'luwipress_telegram_admin_ids', sanitize_text_field( $_POST['luwipress_telegram_admin_ids'] ?? '' ) );
	update_option( 'luwipress_whatsapp_number', sanitize_text_field( $_POST['luwipress_whatsapp_number'] ?? '' ) );
	update_option( 'luwipress_whatsapp_admin_ids', sanitize_text_field( $_POST['luwipress_whatsapp_admin_ids'] ?? '' ) );

	// Customer Chat
	update_option( 'luwipress_chat_enabled', isset( $_POST['luwipress_chat_enabled'] ) ? 1 : 0 );
	update_option( 'luwipress_chat_greeting', sanitize_textarea_field( $_POST['luwipress_chat_greeting'] ?? 'Hi! How can I help you today?' ) );
	update_option( 'luwipress_chat_tone', sanitize_text_field( $_POST['luwipress_chat_tone'] ?? 'friendly' ) );
	update_option( 'luwipress_chat_custom_instructions', sanitize_textarea_field( $_POST['luwipress_chat_custom_instructions'] ?? '' ) );
	update_option( 'luwipress_chat_shipping_policy', sanitize_textarea_field( $_POST['luwipress_chat_shipping_policy'] ?? '' ) );
	update_option( 'luwipress_chat_returns_policy', sanitize_textarea_field( $_POST['luwipress_chat_returns_policy'] ?? '' ) );
	update_option( 'luwipress_chat_color_primary', sanitize_hex_color( $_POST['luwipress_chat_color_primary'] ?? '#6366f1' ) );
	update_option( 'luwipress_chat_color_text', sanitize_hex_color( $_POST['luwipress_chat_color_text'] ?? '#ffffff' ) );
	update_option( 'luwipress_chat_position', sanitize_text_field( $_POST['luwipress_chat_position'] ?? 'bottom-right' ) );
	update_option( 'luwipress_chat_escalation_channel', sanitize_text_field( $_POST['luwipress_chat_escalation_channel'] ?? 'whatsapp' ) );
	update_option( 'luwipress_telegram_username', sanitize_text_field( $_POST['luwipress_telegram_username'] ?? '' ) );
	update_option( 'luwipress_chat_daily_budget', floatval( $_POST['luwipress_chat_daily_budget'] ?? 0.50 ) );
	update_option( 'luwipress_chat_max_messages', absint( $_POST['luwipress_chat_max_messages'] ?? 10 ) );
	update_option( 'luwipress_chat_rate_limit', absint( $_POST['luwipress_chat_rate_limit'] ?? 30 ) );

	// Marketplaces — save all marketplace credentials as a single serialised option
	$mp_keys = array(
		'amazon_api_key', 'amazon_seller_id', 'amazon_region',
		'ebay_api_key', 'ebay_environment', 'ebay_marketplace_id',
		'trendyol_api_key', 'trendyol_api_secret', 'trendyol_seller_id', 'trendyol_cargo_company_id',
		'alibaba_app_key', 'alibaba_app_secret', 'alibaba_access_token',
		'hepsiburada_api_key', 'hepsiburada_api_secret', 'hepsiburada_merchant_id',
		'n11_api_key', 'n11_api_secret',
		'etsy_api_key', 'etsy_shop_id',
		'walmart_client_id', 'walmart_client_secret',
	);
	foreach ( $mp_keys as $k ) {
		$opt = 'luwipress_' . $k;
		if ( $k === 'trendyol_cargo_company_id' ) {
			update_option( $opt, absint( $_POST[ $opt ] ?? 10 ) );
		} else {
			update_option( $opt, sanitize_text_field( $_POST[ $opt ] ?? '' ) );
		}
	}
	// Enable toggles
	$mp_slugs = array( 'amazon', 'ebay', 'trendyol', 'alibaba', 'hepsiburada', 'n11', 'etsy', 'walmart' );
	foreach ( $mp_slugs as $slug ) {
		update_option( 'luwipress_marketplace_' . $slug . '_enabled', isset( $_POST[ 'luwipress_marketplace_' . $slug . '_enabled' ] ) ? 1 : 0 );
	}

	// Advertising — Google Ads
	update_option( 'luwipress_google_ads_customer_id', sanitize_text_field( $_POST['luwipress_google_ads_customer_id'] ?? '' ) );
	update_option( 'luwipress_google_merchant_id', sanitize_text_field( $_POST['luwipress_google_merchant_id'] ?? '' ) );
	update_option( 'luwipress_google_ads_conversion_id', sanitize_text_field( $_POST['luwipress_google_ads_conversion_id'] ?? '' ) );
	update_option( 'luwipress_google_ads_conversion_label', sanitize_text_field( $_POST['luwipress_google_ads_conversion_label'] ?? '' ) );

	// Advertising — Meta (Facebook/Instagram)
	update_option( 'luwipress_meta_pixel_id', sanitize_text_field( $_POST['luwipress_meta_pixel_id'] ?? '' ) );
	update_option( 'luwipress_meta_access_token', sanitize_text_field( $_POST['luwipress_meta_access_token'] ?? '' ) );
	update_option( 'luwipress_meta_catalog_id', sanitize_text_field( $_POST['luwipress_meta_catalog_id'] ?? '' ) );
	update_option( 'luwipress_meta_business_id', sanitize_text_field( $_POST['luwipress_meta_business_id'] ?? '' ) );

	// Security
	update_option( 'luwipress_security_headers', isset( $_POST['luwipress_security_headers'] ) ? 1 : 0 );
	update_option( 'luwipress_ip_whitelist', sanitize_text_field( $_POST['luwipress_ip_whitelist'] ?? '' ) );
	if ( ! empty( $_POST['luwipress_regenerate_hmac'] ) ) {
		LuwiPress_HMAC::ensure_secret( true );
	}

	echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'luwipress' ) . '</p></div>';
}

// Load current values
$api_token          = get_option( 'luwipress_seo_api_token', '' );
$enable_logging     = get_option( 'luwipress_enable_logging', 1 );
$log_level          = get_option( 'luwipress_log_level', 'info' );
$rate_limit         = get_option( 'luwipress_rate_limit', 1000 );
$api_timeout        = get_option( 'luwipress_api_timeout', 30 );
$auto_enrich        = get_option( 'luwipress_auto_enrich', 0 );
$target_language    = get_option( 'luwipress_target_language', 'tr' );
$auto_thin          = get_option( 'luwipress_auto_enrich_thin', 0 );
$thin_threshold     = get_option( 'luwipress_thin_content_threshold', 300 );
$thin_batch_size    = get_option( 'luwipress_auto_enrich_batch_size', 10 );
$translation_langs  = get_option( 'luwipress_translation_languages', array() );
$hreflang_mode      = get_option( 'luwipress_hreflang_mode', 'auto' );
$openai_key         = get_option( 'luwipress_openai_api_key', '' );
$anthropic_key      = get_option( 'luwipress_anthropic_api_key', '' );
$google_ai_key      = get_option( 'luwipress_google_ai_api_key', '' );
$ai_provider        = get_option( 'luwipress_ai_provider', 'openai' );
$ai_model           = get_option( 'luwipress_ai_model', 'gpt-4o-mini' );
$daily_token_limit  = floatval( get_option( 'luwipress_daily_token_limit', 1.00 ) );
$max_output_tokens  = absint( get_option( 'luwipress_max_output_tokens', 1024 ) );
$image_provider     = get_option( 'luwipress_image_provider', 'dall-e-3' );
$enrich_gen_image   = get_option( 'luwipress_enrich_generate_image', 0 );
$crm_vip_threshold  = get_option( 'luwipress_crm_vip_threshold', 1000 );
$crm_active_days    = get_option( 'luwipress_crm_active_days', 90 );
$crm_at_risk_days   = get_option( 'luwipress_crm_at_risk_days', 180 );
$crm_loyal_orders   = get_option( 'luwipress_crm_loyal_orders', 3 );
$openclaw_url       = get_option( 'luwipress_openclaw_url', '' );
$tg_bot_token       = get_option( 'luwipress_telegram_bot_token', '' );
$tg_admin_ids       = get_option( 'luwipress_telegram_admin_ids', '' );
$wa_number          = get_option( 'luwipress_whatsapp_number', '' );
$wa_admin_ids       = get_option( 'luwipress_whatsapp_admin_ids', '' );
$security_headers   = get_option( 'luwipress_security_headers', 1 );
$ip_whitelist       = get_option( 'luwipress_ip_whitelist', '' );
$hmac_secret        = get_option( 'luwipress_hmac_secret', '' );

// Marketplace settings — compact loader
$mp_cfg = array();
$mp_all_slugs = array( 'amazon', 'ebay', 'trendyol', 'alibaba', 'hepsiburada', 'n11', 'etsy', 'walmart' );
foreach ( $mp_all_slugs as $_s ) {
	$mp_cfg[ $_s ]['enabled'] = get_option( 'luwipress_marketplace_' . $_s . '_enabled', 0 );
}
$mp_cfg['amazon']['api_key']    = get_option( 'luwipress_amazon_api_key', '' );
$mp_cfg['amazon']['seller_id']  = get_option( 'luwipress_amazon_seller_id', '' );
$mp_cfg['amazon']['region']     = get_option( 'luwipress_amazon_region', 'eu' );
$mp_cfg['ebay']['api_key']      = get_option( 'luwipress_ebay_api_key', '' );
$mp_cfg['ebay']['environment']  = get_option( 'luwipress_ebay_environment', 'production' );
$mp_cfg['ebay']['marketplace']  = get_option( 'luwipress_ebay_marketplace_id', 'EBAY_US' );
$mp_cfg['trendyol']['api_key']  = get_option( 'luwipress_trendyol_api_key', '' );
$mp_cfg['trendyol']['api_secret'] = get_option( 'luwipress_trendyol_api_secret', '' );
$mp_cfg['trendyol']['seller_id'] = get_option( 'luwipress_trendyol_seller_id', '' );
$mp_cfg['trendyol']['cargo']    = get_option( 'luwipress_trendyol_cargo_company_id', 10 );
$mp_cfg['alibaba']['app_key']   = get_option( 'luwipress_alibaba_app_key', '' );
$mp_cfg['alibaba']['app_secret'] = get_option( 'luwipress_alibaba_app_secret', '' );
$mp_cfg['alibaba']['access_token'] = get_option( 'luwipress_alibaba_access_token', '' );
$mp_cfg['hepsiburada']['api_key']    = get_option( 'luwipress_hepsiburada_api_key', '' );
$mp_cfg['hepsiburada']['api_secret'] = get_option( 'luwipress_hepsiburada_api_secret', '' );
$mp_cfg['hepsiburada']['merchant_id'] = get_option( 'luwipress_hepsiburada_merchant_id', '' );
$mp_cfg['n11']['api_key']       = get_option( 'luwipress_n11_api_key', '' );
$mp_cfg['n11']['api_secret']    = get_option( 'luwipress_n11_api_secret', '' );
$mp_cfg['etsy']['api_key']      = get_option( 'luwipress_etsy_api_key', '' );
$mp_cfg['etsy']['shop_id']      = get_option( 'luwipress_etsy_shop_id', '' );
$mp_cfg['walmart']['client_id'] = get_option( 'luwipress_walmart_client_id', '' );
$mp_cfg['walmart']['client_secret'] = get_option( 'luwipress_walmart_client_secret', '' );

$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'connection';

// Detected plugins for info boxes
$detector    = LuwiPress_Plugin_Detector::get_instance();
$env         = $detector->get_environment();
$seo_plugin  = $env['seo']['plugin'] ?? 'none';
$trans_plugin = $env['translation']['plugin'] ?? 'none';
$email_plugin = $env['email']['plugin'] ?? 'wp_mail';
?>

<div class="wrap luwipress-settings">
	<h1><span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e( 'LuwiPress Settings', 'luwipress' ); ?></h1>

	<nav class="nav-tab-wrapper luwipress-tabs">
		<a href="?page=luwipress-settings&tab=connection" class="nav-tab <?php echo 'connection' === $active_tab ? 'nav-tab-active' : ''; ?>">
			<span class="dashicons dashicons-admin-links"></span> <?php esc_html_e( 'Connection', 'luwipress' ); ?>
		</a>
		<a href="?page=luwipress-settings&tab=theme-setup" class="nav-tab <?php echo 'theme-setup' === $active_tab ? 'nav-tab-active' : ''; ?>">
			<span class="dashicons dashicons-welcome-widgets-menus"></span> <?php esc_html_e( 'Theme Setup', 'luwipress' ); ?>
		</a>
		<a href="?page=luwipress-settings&tab=general" class="nav-tab <?php echo 'general' === $active_tab ? 'nav-tab-active' : ''; ?>">
			<span class="dashicons dashicons-admin-settings"></span> <?php esc_html_e( 'General', 'luwipress' ); ?>
		</a>
		<a href="?page=luwipress-settings&tab=api-keys" class="nav-tab <?php echo 'api-keys' === $active_tab ? 'nav-tab-active' : ''; ?>">
			<span class="dashicons dashicons-admin-network"></span> <?php esc_html_e( 'AI API Keys', 'luwipress' ); ?>
		</a>
		<a href="?page=luwipress-settings&tab=ai" class="nav-tab <?php echo 'ai' === $active_tab ? 'nav-tab-active' : ''; ?>">
			<span class="dashicons dashicons-edit-large"></span> <?php esc_html_e( 'AI Content', 'luwipress' ); ?>
		</a>
		<a href="?page=luwipress-settings&tab=translation" class="nav-tab <?php echo 'translation' === $active_tab ? 'nav-tab-active' : ''; ?>">
			<span class="dashicons dashicons-translation"></span> <?php esc_html_e( 'Translation', 'luwipress' ); ?>
		</a>
		<a href="?page=luwipress-settings&tab=crm" class="nav-tab <?php echo 'crm' === $active_tab ? 'nav-tab-active' : ''; ?>">
			<span class="dashicons dashicons-groups"></span> <?php esc_html_e( 'CRM', 'luwipress' ); ?>
		</a>
		<a href="?page=luwipress-settings&tab=open-claw" class="nav-tab <?php echo 'open-claw' === $active_tab ? 'nav-tab-active' : ''; ?>">
			<span class="dashicons dashicons-superhero-alt"></span> <?php esc_html_e( 'Open Claw', 'luwipress' ); ?>
		</a>
		<a href="?page=luwipress-settings&tab=customer-chat" class="nav-tab <?php echo 'customer-chat' === $active_tab ? 'nav-tab-active' : ''; ?>">
			<span class="dashicons dashicons-format-chat"></span> <?php esc_html_e( 'Customer Chat', 'luwipress' ); ?>
		</a>
		<a href="?page=luwipress-settings&tab=marketplaces" class="nav-tab <?php echo 'marketplaces' === $active_tab ? 'nav-tab-active' : ''; ?>">
			<span class="dashicons dashicons-store"></span> <?php esc_html_e( 'Marketplaces', 'luwipress' ); ?>
		</a>
		<a href="?page=luwipress-settings&tab=advertising" class="nav-tab <?php echo 'advertising' === $active_tab ? 'nav-tab-active' : ''; ?>">
			<span class="dashicons dashicons-megaphone"></span> <?php esc_html_e( 'Advertising', 'luwipress' ); ?>
		</a>
		<a href="?page=luwipress-settings&tab=security" class="nav-tab <?php echo 'security' === $active_tab ? 'nav-tab-active' : ''; ?>">
			<span class="dashicons dashicons-shield-alt"></span> <?php esc_html_e( 'Security', 'luwipress' ); ?>
		</a>
	</nav>

	<form method="post" class="luwipress-settings-form">
		<?php wp_nonce_field( 'luwipress_settings_nonce' ); ?>

		<!-- CONNECTION -->
		<div class="luwipress-tab-content <?php echo 'connection' === $active_tab ? 'tab-active' : ''; ?>" id="tab-connection">

			<?php
			$processing_mode = get_option( 'luwipress_processing_mode', 'local' );
			?>

			<!-- API Authentication -->
			<div class="luwipress-card">
				<h2><?php esc_html_e( 'API Authentication', 'luwipress' ); ?></h2>
				<p class="description" style="margin-bottom:8px;"><?php esc_html_e( 'Token for REST API and MCP Server authentication.', 'luwipress' ); ?></p>
				<table class="form-table">
					<tr>
						<th><label for="luwipress_api_token"><?php esc_html_e( 'API Token', 'luwipress' ); ?></label></th>
						<td>
							<input type="password" id="luwipress_api_token" name="luwipress_api_token"
							       value="<?php echo esc_attr( $api_token ); ?>" class="regular-text" autocomplete="off" />
							<button type="button" class="button button-small luwipress-toggle-password" data-target="luwipress_api_token">
								<span class="dashicons dashicons-visibility"></span>
							</button>
							<button type="button" class="button button-small" id="luwipress-generate-token" title="<?php esc_attr_e( 'Generate a secure random token', 'luwipress' ); ?>">
								<span class="dashicons dashicons-randomize"></span> <?php esc_html_e( 'Generate', 'luwipress' ); ?>
							</button>
							<p class="description"><?php esc_html_e( 'Used for REST API & MCP Server authentication. Click Generate for a secure random token.', 'luwipress' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Connection Test', 'luwipress' ); ?></th>
						<td>
							<button type="button" class="button" id="luwipress-test-connection">
								<span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Test Connection', 'luwipress' ); ?>
							</button>
							<span id="luwipress-connection-result"></span>
						</td>
					</tr>
				</table>
			</div>

			<div class="luwipress-card">
				<h2><?php esc_html_e( 'REST API Endpoints', 'luwipress' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Base URL', 'luwipress' ); ?></th>
						<td><code><?php echo esc_html( get_rest_url( null, 'luwipress/v1/' ) ); ?></code></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Site Config', 'luwipress' ); ?></th>
						<td>
							<code><?php echo esc_html( get_rest_url( null, 'luwipress/v1/site-config' ) ); ?></code>
							<p class="description"><?php esc_html_e( 'Returns full site environment: WP, WooCommerce, plugin settings.', 'luwipress' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Email Proxy', 'luwipress' ); ?></th>
						<td>
							<code><?php echo esc_html( get_rest_url( null, 'luwipress/v1/send-email' ) ); ?></code>
							<p class="description">
								<?php printf(
									esc_html__( 'Sends via wp_mail() using %s.', 'luwipress' ),
									'<strong>' . esc_html( ucwords( str_replace( '-', ' ', $email_plugin ) ) ) . '</strong>'
								); ?>
							</p>
						</td>
					</tr>
				</table>
			</div>
		</div>

		<!-- THEME SETUP -->
		<div class="luwipress-tab-content <?php echo 'theme-setup' === $active_tab ? 'tab-active' : ''; ?>" id="tab-theme-setup">

			<style>
				.lp-ts{--ts-radius:var(--radius-lg);--ts-gap:var(--space-lg)}
				/* Theme catalog grid */
				.lp-tc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:var(--space-lg);margin-bottom:var(--space-2xl)}
				.lp-tc-card{background:var(--lp-surface);border:1px solid var(--lp-border);border-radius:var(--radius-lg);padding:var(--space-xl);transition:border-color var(--duration-fast),box-shadow var(--duration-fast);position:relative;display:flex;flex-direction:column}
				.lp-tc-card:hover{border-color:var(--lp-primary-light);box-shadow:var(--lp-shadow-md)}
				.lp-tc-card.is-active{border-color:var(--lp-success);background:var(--lp-success-bg)}
				.lp-tc-card.is-coming{opacity:.65;pointer-events:auto}
				.lp-tc-head{display:flex;align-items:center;gap:var(--space-md);margin-bottom:var(--space-md)}
				.lp-tc-icon{width:48px;height:48px;border-radius:var(--radius-md);background:var(--lp-primary-50);display:flex;align-items:center;justify-content:center;color:var(--lp-primary);font-size:24px;flex-shrink:0}
				.lp-tc-card.is-coming .lp-tc-icon{background:var(--lp-surface-secondary);color:var(--lp-gray-light)}
				.lp-tc-name{font-weight:700;font-size:var(--text-md);color:var(--lp-text);margin:0}
				.lp-tc-ver{font-size:var(--text-xs);color:var(--lp-text-secondary)}
				.lp-tc-update-badge{display:inline-block;font-size:9px;font-weight:700;padding:1px 6px;background:var(--lp-warning);color:#fff;border-radius:var(--radius-full);vertical-align:middle;white-space:nowrap}
				.lp-tc-desc{font-size:var(--text-sm);color:var(--lp-text-secondary);line-height:1.6;margin-bottom:var(--space-md);display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
				.lp-tc-features{display:flex;flex-wrap:wrap;gap:4px;margin-bottom:var(--space-lg)}
				.lp-tc-feat{font-size:var(--text-xs);padding:2px 8px;background:var(--lp-surface-secondary);border-radius:var(--radius-full);color:var(--lp-text-secondary)}
				/* Badge float removed — palette swatches in header instead */
				.lp-tc-status{display:flex;flex-direction:column;gap:2px;margin-bottom:var(--space-md)}
				.lp-tc-status-row{display:flex;align-items:center;gap:var(--space-xs);font-size:var(--text-xs);font-weight:500}
				.lp-tc-status-row .dashicons{font-size:14px;width:14px;height:14px}
				.lp-tc-status-row.ok{color:var(--lp-success)}
				.lp-tc-status-row.warn{color:var(--lp-warning)}
				.lp-tc-status-row.err{color:var(--lp-error)}
				.lp-tc-status-row.neutral{color:var(--lp-text-secondary)}
				.lp-tc-log:empty{display:none}
				.lp-tc-log{margin-bottom:var(--space-sm);padding:var(--space-sm) var(--space-md);background:var(--lp-surface-secondary);border-radius:var(--radius-sm);font-size:var(--text-xs)}
				.lp-tc-actions{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:auto}
				.lp-tc-actions .button{font-size:12px !important;padding:4px 12px !important;line-height:1.6 !important;height:auto !important}
				.lp-tc-palette{display:flex;gap:3px;margin-left:auto;flex-shrink:0}
				.lp-tc-swatch{width:16px;height:16px;border-radius:50%;border:1px solid rgba(0,0,0,.1);flex-shrink:0}
				/* Wizard steps */
				.lp-ts-steps{display:flex;gap:var(--space-xs);margin-bottom:var(--ts-gap);padding:0}
				.lp-ts-step{flex:1;display:flex;align-items:center;gap:var(--space-sm);padding:var(--space-md);background:var(--lp-surface-secondary);border-radius:var(--radius-md);font-size:var(--text-sm);color:var(--lp-text-secondary);transition:all var(--duration-fast)}
				.lp-ts-step.done{background:var(--lp-success-bg);color:var(--lp-success)}
				.lp-ts-step.current{background:var(--lp-primary-50);color:var(--lp-primary);font-weight:600}
				.lp-ts-step .num{width:24px;height:24px;border-radius:50%;background:var(--lp-border);color:#fff;display:flex;align-items:center;justify-content:center;font-size:var(--text-xs);font-weight:700;flex-shrink:0}
				.lp-ts-step.done .num{background:var(--lp-success)}
				.lp-ts-step.current .num{background:var(--lp-primary)}
				.lp-ts-card{background:var(--lp-surface);border:1px solid var(--lp-border);border-radius:var(--ts-radius);padding:var(--space-xl);margin-bottom:var(--ts-gap)}
				.lp-ts-card h3{margin:0 0 var(--space-sm);font-size:var(--text-lg);font-weight:700}
				.lp-ts-card p{margin:0 0 var(--space-md);color:var(--lp-text-secondary);font-size:var(--text-sm)}
				.lp-ts-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:var(--radius-full);font-size:var(--text-xs);font-weight:600;text-transform:uppercase;letter-spacing:.5px}
				.lp-ts-badge.ok{background:#dcfce7;color:var(--lp-success)}
				.lp-ts-badge.warn{background:var(--lp-warning-bg);color:#b45309}
				.lp-ts-badge.err{background:#fef2f2;color:var(--lp-error)}
				.lp-ts-badge.info{background:var(--lp-primary-50);color:var(--lp-primary)}
				.lp-ts-row{display:flex;gap:var(--space-md);align-items:center;flex-wrap:wrap;margin-bottom:var(--space-md)}
				.lp-ts-btn{padding:10px 24px;font-size:var(--text-md);display:inline-flex;align-items:center;gap:var(--space-sm)}
				.lp-ts-btn .dashicons{font-size:18px;width:18px;height:18px}
				.lp-ts-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:var(--space-sm);margin-bottom:var(--space-md)}
				.lp-ts-pg{background:var(--lp-surface-secondary);border-radius:var(--radius-sm);padding:var(--space-sm) var(--space-md);display:flex;align-items:center;gap:var(--space-sm);font-size:var(--text-sm)}
				.lp-ts-pg.ok{color:var(--lp-success)}
				.lp-ts-pg.miss{color:var(--lp-warning)}
				.lp-ts-pg .dashicons{font-size:14px;width:14px;height:14px}
				.lp-ts-log{background:var(--lp-surface-secondary);border:1px solid var(--lp-border-light);border-radius:var(--radius-sm);padding:var(--space-md);font-family:var(--font-mono);font-size:var(--text-sm);max-height:240px;overflow-y:auto;line-height:1.8;margin-top:var(--space-md)}
				.lp-ts-log .ok{color:var(--lp-success)}
				.lp-ts-log .skip{color:var(--lp-warning)}
				.lp-ts-log .fail{color:var(--lp-error)}
				.lp-ts-cleanup{margin-top:var(--space-xl);padding-top:var(--space-lg);border-top:1px solid var(--lp-border)}
				.lp-ts-log-step{display:flex;align-items:center;gap:var(--space-sm);padding:var(--space-sm) 0;font-size:var(--text-sm);animation:lp-fade-up .3s ease both}
				.lp-ts-log-step .dashicons{font-size:18px;width:18px;height:18px;flex-shrink:0}
				.lp-ts-log-step.ok{color:var(--lp-success)}
				.lp-ts-log-step.ok .dashicons{color:var(--lp-success)}
				.lp-ts-log-step.fail{color:var(--lp-error)}
				.lp-ts-log-step.fail .dashicons{color:var(--lp-error)}
				.lp-ts-log-step.pending{color:var(--lp-text-secondary)}
				.lp-ts-log-step.pending .dashicons{color:var(--lp-primary)}
				.spin{animation:rotation 1s linear infinite}
				@keyframes rotation{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}
				.lp-tc-store-status{background:var(--lp-surface);border:1px solid var(--lp-border);border-radius:var(--radius-lg);padding:var(--space-xl);margin-bottom:var(--space-xl)}
				.lp-tc-detect-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:var(--space-xl)}
				.lp-tc-detect-group{display:flex;flex-direction:column;gap:2px}
				.lp-tc-detect-label{font-size:var(--text-xs);font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--lp-text-secondary);margin-bottom:var(--space-sm);padding-bottom:var(--space-xs);border-bottom:1px solid var(--lp-border-light)}
				.lp-tc-detect-row{display:flex;align-items:center;gap:var(--space-xs);font-size:var(--text-sm);padding:3px 0}
				.lp-tc-detect-row .dashicons{font-size:14px;width:14px;height:14px;flex-shrink:0}
				.lp-tc-detect-row.ok{color:var(--lp-success)}
				.lp-tc-detect-row.ok .dashicons{color:var(--lp-success)}
				.lp-tc-detect-row.miss{color:var(--lp-text-secondary)}
				.lp-tc-detect-row.miss .dashicons{color:var(--lp-border)}
				.lp-tc-detect-row.err{color:var(--lp-error)}
				.lp-tc-detect-row.err .dashicons{color:var(--lp-error)}
				.lp-tc-detect-row.neutral{color:var(--lp-text)}
				.lp-tc-detect-row.neutral .dashicons{color:var(--lp-text-secondary)}
				.lp-tc-will-create{font-size:var(--text-xs);color:var(--lp-primary);font-weight:600;margin-left:auto}
				.lp-tc-ver-inline{font-size:var(--text-xs);color:var(--lp-text-secondary);font-weight:400}
				@media(max-width:782px){.lp-tc-detect-grid{grid-template-columns:1fr}}
				.btn-danger{color:var(--lp-error)!important;border-color:var(--lp-error)!important}
				.btn-danger:hover{background:var(--lp-error)!important;color:#fff!important}
			</style>

			<?php
			$tm      = LuwiPress_Theme_Manager::get_instance();
			$catalog = $tm->get_catalog();

			// Determine which theme to show wizard for.
			// Priority: active Luwi theme > installed Luwi theme > first available.
			$selected_slug = '';
			foreach ( $catalog as $sl => $ct ) {
				if ( $ct['active'] && ! $ct['coming_soon'] ) { $selected_slug = $sl; break; }
			}
			if ( ! $selected_slug ) {
				foreach ( $catalog as $sl => $ct ) {
					if ( $ct['installed'] && ! $ct['coming_soon'] ) { $selected_slug = $sl; break; }
				}
			}
			if ( ! $selected_slug ) {
				foreach ( $catalog as $sl => $ct ) {
					if ( ! $ct['coming_soon'] ) { $selected_slug = $sl; break; }
				}
			}

			// Allow URL override: ?luwi_theme=slug
			if ( ! empty( $_GET['luwi_theme'] ) && isset( $catalog[ sanitize_text_field( $_GET['luwi_theme'] ) ] ) ) {
				$selected_slug = sanitize_text_field( $_GET['luwi_theme'] );
			}

			$status = $tm->get_status( $selected_slug );
			$def    = $status['theme'] ?? array();

			// Step states.
			$s_installed = $status['installed'] ?? false;
			$s_active    = $status['active'] ?? false;
			$s_elementor = $status['has_elementor'] ?? false;
			$s_wc        = $status['has_wc'] ?? false;
			$s_setup     = $status['setup_done'] ?? false;
			$s_hp        = $status['has_homepage'] ?? false;
			$s_menu      = $status['has_menu'] ?? false;

			// Current step.
			if ( ! $s_elementor )      { $step = 0; }
			elseif ( ! $s_installed )   { $step = 1; }
			elseif ( ! $s_active )      { $step = 2; }
			elseif ( ! $s_setup )       { $step = 3; }
			else                        { $step = 4; }

			$demo_pages     = $status['demo_pages'] ?? array();
			$wc_pages       = $status['wc_pages'] ?? array();
			$missing_count  = $status['demo_missing'] ?? 0;
			$existing_count = $status['demo_existing'] ?? 0;

			$page_icons = array(
				'home'             => 'dashicons-admin-home',
				'about'            => 'dashicons-info-outline',
				'contact'          => 'dashicons-email-alt',
				'faq'              => 'dashicons-editor-help',
				'blog'             => 'dashicons-edit-large',
				'terms-conditions' => 'dashicons-media-document',
				'privacy-policy'   => 'dashicons-shield',
			);
			?>

			<!-- Theme Catalog -->
			<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--space-sm)">
				<div>
					<h2 style="font-size:var(--text-lg);font-weight:700;margin:0 0 4px"><?php esc_html_e( 'Theme Library', 'luwipress' ); ?></h2>
					<p style="color:var(--lp-text-secondary);font-size:var(--text-sm);margin:0"><?php esc_html_e( 'Free premium themes included with your LuwiPress license.', 'luwipress' ); ?></p>
				</div>
				<button type="button" class="button button-secondary" id="lp-check-updates-btn" style="font-size:var(--text-xs);white-space:nowrap">
					<span class="dashicons dashicons-update" style="font-size:14px;width:14px;height:14px;margin-top:2px"></span>
					<?php esc_html_e( 'Check for Updates', 'luwipress' ); ?>
				</button>
			</div>

			<div class="lp-tc-grid">
				<?php foreach ( $catalog as $t_slug => $t ) :
					$card_class = 'lp-tc-card';
					if ( $t['active'] )       $card_class .= ' is-active';
					if ( $t['coming_soon'] )  $card_class .= ' is-coming';
				?>
				<div class="<?php echo esc_attr( $card_class ); ?>">

					<!-- Header -->
					<div class="lp-tc-head">
						<div class="lp-tc-icon"><span class="dashicons <?php echo esc_attr( $t['icon'] ); ?>"></span></div>
						<div>
							<h3 class="lp-tc-name"><?php echo esc_html( $t['name'] ); ?></h3>
							<span class="lp-tc-ver">v<?php echo esc_html( $t['installed_ver'] ?? $t['version'] ); ?></span>
							<?php if ( ! empty( $t['update_available'] ) ) : ?>
								<span class="lp-tc-update-badge">
									<?php echo esc_html( sprintf( __( 'Update: v%s', 'luwipress' ), $t['latest_version'] ) ); ?>
								</span>
							<?php endif; ?>
						</div>
						<?php if ( ! empty( $t['palette'] ) ) : ?>
						<span class="lp-tc-palette">
							<?php foreach ( $t['palette'] as $pval ) : ?>
								<span class="lp-tc-swatch" style="background:<?php echo esc_attr( $pval ); ?>"></span>
							<?php endforeach; ?>
						</span>
						<?php endif; ?>
					</div>

					<p class="lp-tc-desc"><?php echo esc_html( $t['description'] ); ?></p>

					<!-- Status: what's detected -->
					<?php if ( ! $t['coming_soon'] ) : ?>
					<div class="lp-tc-status">
						<?php
						$t_folder_exists = is_dir( get_theme_root() . '/' . $t_slug );
						if ( $t['active'] ) : ?>
							<div class="lp-tc-status-row ok"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Active theme', 'luwipress' ); ?></div>
						<?php elseif ( $t['installed'] ) : ?>
							<div class="lp-tc-status-row warn"><span class="dashicons dashicons-info-outline"></span> <?php esc_html_e( 'Installed — not active', 'luwipress' ); ?></div>
						<?php elseif ( $t_folder_exists ) : ?>
							<div class="lp-tc-status-row warn"><span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Needs update — folder exists', 'luwipress' ); ?></div>
						<?php else : ?>
							<div class="lp-tc-status-row neutral"><span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Not installed', 'luwipress' ); ?></div>
						<?php endif; ?>
						<?php if ( ! defined( 'ELEMENTOR_VERSION' ) ) : ?>
							<div class="lp-tc-status-row err"><span class="dashicons dashicons-warning"></span> <?php esc_html_e( 'Elementor required', 'luwipress' ); ?></div>
						<?php endif; ?>
					</div>
					<?php endif; ?>

					<!-- Live progress log -->
					<div class="lp-tc-log" id="lp-card-log-<?php echo esc_attr( $t_slug ); ?>"></div>

					<!-- Actions -->
					<div class="lp-tc-actions">
						<?php if ( $t['coming_soon'] ) : ?>
							<span class="button button-secondary" disabled style="opacity:.5"><?php esc_html_e( 'Coming Soon', 'luwipress' ); ?></span>
						<?php elseif ( ! defined( 'ELEMENTOR_VERSION' ) ) : ?>
							<span class="button button-secondary" disabled style="opacity:.5"><?php esc_html_e( 'Elementor Required', 'luwipress' ); ?></span>
						<?php elseif ( $t['active'] ) : ?>
							<?php if ( ! empty( $t['update_available'] ) ) : ?>
								<button type="button" class="button button-primary lp-theme-update-btn" data-slug="<?php echo esc_attr( $t_slug ); ?>">
									<span class="dashicons dashicons-update" style="font-size:13px;width:13px;height:13px;margin-top:3px"></span>
									<?php echo esc_html( sprintf( __( 'Update to v%s', 'luwipress' ), $t['latest_version'] ) ); ?>
								</button>
							<?php endif; ?>
						<?php else :
							$folder_exists = is_dir( get_theme_root() . '/' . $t_slug );
							if ( $t['installed'] && ! empty( $t['update_available'] ) ) :
						?>
							<button type="button" class="button button-primary lp-theme-update-btn" data-slug="<?php echo esc_attr( $t_slug ); ?>">
								<span class="dashicons dashicons-update" style="font-size:13px;width:13px;height:13px;margin-top:3px"></span>
								<?php echo esc_html( sprintf( __( 'Update to v%s', 'luwipress' ), $t['latest_version'] ) ); ?>
							</button>
						<?php else : ?>
							<button type="button" class="button button-primary lp-card-wizard-btn" data-slug="<?php echo esc_attr( $t_slug ); ?>" data-installed="<?php echo $t['installed'] ? '1' : '0'; ?>">
								<?php if ( $t['installed'] ) : ?>
									<span class="dashicons dashicons-controls-play" style="font-size:13px;width:13px;height:13px;margin-top:3px"></span>
									<?php esc_html_e( 'Activate & Setup', 'luwipress' ); ?>
								<?php else : ?>
									<span class="dashicons dashicons-download" style="font-size:13px;width:13px;height:13px;margin-top:3px"></span>
									<?php esc_html_e( 'Install & Setup', 'luwipress' ); ?>
								<?php endif; ?>
							</button>
						<?php endif; ?>
						<?php endif; ?>
					</div>
				</div>
				<?php endforeach; ?>
			</div>

			<!-- Store Status Panel -->
			<?php
			$current_theme   = wp_get_theme();
			$is_luwi_active  = strpos( get_template(), 'luwi-' ) === 0;
			$has_elementor   = defined( 'ELEMENTOR_VERSION' );
			$has_wc          = class_exists( 'WooCommerce' );
			$product_count   = $has_wc ? ( wp_count_posts( 'product' )->publish ?? 0 ) : 0;
			$front_page      = get_option( 'show_on_front' );
			$hp_id           = absint( get_option( 'page_on_front' ) );
			$has_homepage    = ( 'page' === $front_page && $hp_id > 0 );
			$locations       = get_nav_menu_locations();
			$has_menu        = ! empty( $locations['primary'] );
			$has_snapshot    = ! empty( $tm->get_snapshot() );

			// Demo pages check.
			$demo_pages_check = array(
				'home'    => get_page_by_path( 'home' ),
				'about'   => get_page_by_path( 'about' ),
				'contact' => get_page_by_path( 'contact' ),
				'faq'     => get_page_by_path( 'faq' ),
				'blog'    => get_page_by_path( 'blog' ),
			);

			// WC pages.
			$wc_pages_check = array();
			if ( $has_wc && function_exists( 'wc_get_page_id' ) ) {
				$wc_pages_check = array(
					'Shop'       => wc_get_page_id( 'shop' ) > 0,
					'Cart'       => wc_get_page_id( 'cart' ) > 0,
					'Checkout'   => wc_get_page_id( 'checkout' ) > 0,
					'My Account' => wc_get_page_id( 'myaccount' ) > 0,
				);
			}
			?>
			<div class="lp-tc-store-status">
				<h3 style="font-size:var(--text-md);font-weight:700;margin:0 0 var(--space-md);display:flex;align-items:center;gap:var(--space-sm)">
					<span class="dashicons dashicons-welcome-view-site" style="color:var(--lp-primary)"></span>
					<?php esc_html_e( 'Current Store Status', 'luwipress' ); ?>
				</h3>

				<div class="lp-tc-detect-grid">
					<!-- Environment -->
					<div class="lp-tc-detect-group">
						<span class="lp-tc-detect-label"><?php esc_html_e( 'Environment', 'luwipress' ); ?></span>
						<div class="lp-tc-detect-row <?php echo $is_luwi_active ? 'ok' : 'neutral'; ?>">
							<span class="dashicons <?php echo $is_luwi_active ? 'dashicons-yes-alt' : 'dashicons-info-outline'; ?>"></span>
							<?php printf( esc_html__( 'Theme: %s', 'luwipress' ), '<strong>' . esc_html( $current_theme->get( 'Name' ) ) . '</strong>' ); ?>
						</div>
						<div class="lp-tc-detect-row <?php echo $has_elementor ? 'ok' : 'err'; ?>">
							<span class="dashicons <?php echo $has_elementor ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
							Elementor <?php echo $has_elementor ? '<span class="lp-tc-ver-inline">v' . esc_html( defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '' ) . '</span>' : '— <strong>' . esc_html__( 'Required', 'luwipress' ) . '</strong>'; ?>
						</div>
						<div class="lp-tc-detect-row <?php echo $has_wc ? 'ok' : 'neutral'; ?>">
							<span class="dashicons <?php echo $has_wc ? 'dashicons-yes-alt' : 'dashicons-info-outline'; ?>"></span>
							WooCommerce <?php echo $has_wc ? '— <strong>' . intval( $product_count ) . '</strong> ' . esc_html__( 'products', 'luwipress' ) : '— ' . esc_html__( 'optional', 'luwipress' ); ?>
						</div>
					</div>

					<!-- Pages -->
					<div class="lp-tc-detect-group">
						<span class="lp-tc-detect-label"><?php esc_html_e( 'Store Pages', 'luwipress' ); ?></span>
						<?php foreach ( $demo_pages_check as $pg_slug => $pg_post ) :
							$exists = ( $pg_post && 'publish' === $pg_post->post_status );
						?>
						<div class="lp-tc-detect-row <?php echo $exists ? 'ok' : 'miss'; ?>">
							<span class="dashicons <?php echo $exists ? 'dashicons-yes-alt' : 'dashicons-marker'; ?>"></span>
							<?php echo esc_html( ucfirst( $pg_slug ) ); ?>
							<?php if ( ! $exists ) : ?><span class="lp-tc-will-create"><?php esc_html_e( 'will create', 'luwipress' ); ?></span><?php endif; ?>
						</div>
						<?php endforeach; ?>
					</div>

					<!-- Store Setup -->
					<div class="lp-tc-detect-group">
						<span class="lp-tc-detect-label"><?php esc_html_e( 'Store Setup', 'luwipress' ); ?></span>
						<div class="lp-tc-detect-row <?php echo $has_homepage ? 'ok' : 'miss'; ?>">
							<span class="dashicons <?php echo $has_homepage ? 'dashicons-yes-alt' : 'dashicons-marker'; ?>"></span>
							<?php echo $has_homepage ? esc_html__( 'Homepage set', 'luwipress' ) : esc_html__( 'Homepage — will set', 'luwipress' ); ?>
						</div>
						<div class="lp-tc-detect-row <?php echo $has_menu ? 'ok' : 'miss'; ?>">
							<span class="dashicons <?php echo $has_menu ? 'dashicons-yes-alt' : 'dashicons-marker'; ?>"></span>
							<?php echo $has_menu ? esc_html__( 'Navigation menu', 'luwipress' ) : esc_html__( 'Menu — will create', 'luwipress' ); ?>
						</div>
						<?php if ( $has_wc ) : foreach ( $wc_pages_check as $wc_label => $wc_ok ) : ?>
						<div class="lp-tc-detect-row <?php echo $wc_ok ? 'ok' : 'miss'; ?>">
							<span class="dashicons <?php echo $wc_ok ? 'dashicons-yes-alt' : 'dashicons-marker'; ?>"></span>
							<?php echo esc_html( $wc_label ); ?>
						</div>
						<?php endforeach; endif; ?>
						<?php if ( $has_snapshot ) : ?>
						<div class="lp-tc-detect-row ok">
							<span class="dashicons dashicons-backup"></span>
							<?php esc_html_e( 'Backup snapshot saved', 'luwipress' ); ?>
						</div>
						<?php endif; ?>
					</div>
				</div>

				<?php if ( $has_snapshot ) : ?>
				<div style="margin-top:var(--space-md);padding-top:var(--space-md);border-top:1px solid var(--lp-border-light)">
					<button type="button" class="button" id="lp-rollback-btn" style="color:var(--lp-error);border-color:var(--lp-error);font-size:var(--text-xs)">
						<span class="dashicons dashicons-undo" style="font-size:14px;width:14px;height:14px;margin-top:2px"></span>
						<?php esc_html_e( 'Rollback to Previous State', 'luwipress' ); ?>
					</button>
					<span id="lp-rollback-result" style="margin-left:var(--space-sm);font-size:var(--text-xs)"></span>
				</div>
				<?php endif; ?>
			</div>

			<script>
			(function(){
				var nonce = '<?php echo esc_js( wp_create_nonce( 'luwipress_dashboard_nonce' ) ); ?>';

				function wizardStep(action, data, logEl, label) {
					return new Promise(function(resolve, reject) {
						data.action = action;
						data.nonce  = nonce;
						logEl.innerHTML += '<div class="lp-ts-log-step pending"><span class="dashicons dashicons-update spin"></span> ' + label + '...</div>';
						var stepEl = logEl.querySelector('.lp-ts-log-step.pending:last-child');

						jQuery.post(ajaxurl, data, function(res) {
							if (res.success) {
								stepEl.className = 'lp-ts-log-step ok';
								stepEl.innerHTML = '<span class="dashicons dashicons-yes-alt"></span> ' + label;
								resolve(res.data);
							} else {
								stepEl.className = 'lp-ts-log-step fail';
								stepEl.innerHTML = '<span class="dashicons dashicons-warning"></span> ' + label + ' — ' + (res.data || 'Failed');
								reject(res.data);
							}
						}).fail(function() {
							stepEl.className = 'lp-ts-log-step fail';
							stepEl.innerHTML = '<span class="dashicons dashicons-warning"></span> ' + label + ' — Request failed';
							reject('Request failed');
						});
					});
				}

					/* ── Rollback button ── */
				var rbBtn = document.getElementById('lp-rollback-btn');
				if (rbBtn) rbBtn.addEventListener('click', function() {
					if (!confirm('<?php echo esc_js( __( 'Restore previous theme and remove starter pages?', 'luwipress' ) ); ?>')) return;
					var me = this;
					me.disabled = true;
					me.innerHTML = '<span class="dashicons dashicons-update spin" style="font-size:14px;width:14px;height:14px"></span> <?php esc_html_e( 'Rolling back...', 'luwipress' ); ?>';
					jQuery.post(ajaxurl, {action: 'luwipress_theme_rollback', nonce: nonce}, function(res) {
						var rd = document.getElementById('lp-rollback-result');
						if (res.success) {
							rd.innerHTML = '<span style="color:var(--lp-success)">✓ <?php esc_html_e( 'Restored', 'luwipress' ); ?></span>';
							setTimeout(function(){ location.reload(); }, 1500);
						} else {
							rd.innerHTML = '<span style="color:var(--lp-error)">' + (res.data || 'Failed') + '</span>';
							me.disabled = false;
							me.innerHTML = '<span class="dashicons dashicons-undo"></span> <?php esc_html_e( 'Retry', 'luwipress' ); ?>';
						}
					});
				});

				/* ── Card Install/Activate buttons (inline wizard) ── */
				document.querySelectorAll('.lp-card-wizard-btn').forEach(function(btn) {
					btn.addEventListener('click', function() {
						var cardSlug = this.getAttribute('data-slug');
						var isInstalled = this.getAttribute('data-installed') === '1';
						var log = document.getElementById('lp-card-log-' + cardSlug);
						var me = this;

						// Hide button, show progress in log area.
						me.style.display = 'none';
						log.innerHTML = '';

						var steps = [];
						if (!isInstalled) {
							steps.push(['luwipress_theme_install', {slug: cardSlug}, '<?php esc_html_e( 'Downloading & installing theme', 'luwipress' ); ?>']);
						}
						steps.push(['luwipress_theme_activate', {slug: cardSlug}, '<?php esc_html_e( 'Activating theme & saving backup', 'luwipress' ); ?>']);
						steps.push(['luwipress_theme_setup', {slug: cardSlug, color_preset: ''}, '<?php esc_html_e( 'Creating pages, menus & homepage', 'luwipress' ); ?>']);

						var chain = Promise.resolve();
						steps.forEach(function(s) {
							chain = chain.then(function() {
								return wizardStep(s[0], s[1], log, s[2]);
							});
						});

						chain.then(function() {
							log.innerHTML += '<div class="lp-ts-log-step ok" style="font-weight:700;margin-top:var(--space-xs)"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Store ready! Reloading...', 'luwipress' ); ?></div>';
							setTimeout(function(){ location.reload(); }, 2000);
						}).catch(function() {
							me.style.display = '';
							me.innerHTML = '<?php esc_html_e( 'Retry', 'luwipress' ); ?>';
						});
					});
				});

				/* ── Check for Updates button ── */
				var checkBtn = document.getElementById('lp-check-updates-btn');
				if (checkBtn) checkBtn.addEventListener('click', function() {
					var me = this;
					me.disabled = true;
					me.innerHTML = '<span class="dashicons dashicons-update spin" style="font-size:14px;width:14px;height:14px"></span> <?php esc_html_e( 'Checking...', 'luwipress' ); ?>';
					jQuery.post(ajaxurl, {action: 'luwipress_theme_check_updates', nonce: nonce}, function(res) {
						if (res.success) {
							var updates = res.data;
							var count = 0;
							for (var s in updates) { if (updates[s].has_update) count++; }
							if (count > 0) {
								me.innerHTML = '<span class="dashicons dashicons-yes-alt" style="font-size:14px;width:14px;height:14px;margin-top:2px"></span> ' + count + ' <?php esc_html_e( 'update(s) found — reloading...', 'luwipress' ); ?>';
								setTimeout(function(){ location.reload(); }, 1500);
							} else {
								me.innerHTML = '<span class="dashicons dashicons-yes-alt" style="font-size:14px;width:14px;height:14px;margin-top:2px"></span> <?php esc_html_e( 'All themes up to date', 'luwipress' ); ?>';
								me.disabled = false;
								setTimeout(function(){
									me.innerHTML = '<span class="dashicons dashicons-update" style="font-size:14px;width:14px;height:14px;margin-top:2px"></span> <?php esc_html_e( 'Check for Updates', 'luwipress' ); ?>';
								}, 3000);
							}
						} else {
							me.innerHTML = '<span class="dashicons dashicons-warning" style="font-size:14px;width:14px;height:14px;margin-top:2px"></span> <?php esc_html_e( 'Check failed', 'luwipress' ); ?>';
							me.disabled = false;
						}
					}).fail(function() {
						me.innerHTML = '<?php esc_html_e( 'Check failed', 'luwipress' ); ?>';
						me.disabled = false;
					});
				});

				/* ── Update Theme button ── */
				document.querySelectorAll('.lp-theme-update-btn').forEach(function(btn) {
					btn.addEventListener('click', function() {
						var slug = this.getAttribute('data-slug');
						var me = this;
						var log = document.getElementById('lp-card-log-' + slug);

						if (!confirm('<?php echo esc_js( __( 'Update this theme? Your customizations in Elementor will be preserved.', 'luwipress' ) ); ?>')) return;

						me.disabled = true;
						me.innerHTML = '<span class="dashicons dashicons-update spin" style="font-size:14px;width:14px;height:14px"></span> <?php esc_html_e( 'Updating...', 'luwipress' ); ?>';
						if (log) log.innerHTML = '';

						jQuery.post(ajaxurl, {action: 'luwipress_theme_update', slug: slug, nonce: nonce}, function(res) {
							if (res.success) {
								me.innerHTML = '<span class="dashicons dashicons-yes-alt" style="font-size:14px;width:14px;height:14px"></span> <?php esc_html_e( 'Updated! Reloading...', 'luwipress' ); ?>';
								if (log) log.innerHTML = '<div class="lp-ts-log-step ok"><span class="dashicons dashicons-yes-alt"></span> v' + res.data.old_version + ' → v' + res.data.new_version + '</div>';
								setTimeout(function(){ location.reload(); }, 2000);
							} else {
								me.innerHTML = '<span class="dashicons dashicons-warning" style="font-size:14px;width:14px;height:14px"></span> ' + (res.data || '<?php esc_html_e( 'Update failed', 'luwipress' ); ?>');
								me.disabled = false;
							}
						}).fail(function() {
							me.innerHTML = '<?php esc_html_e( 'Update failed — retry', 'luwipress' ); ?>';
							me.disabled = false;
						});
					});
				});
			})();
			</script>

		</div>

		<!-- GENERAL -->
		<div class="luwipress-tab-content <?php echo 'general' === $active_tab ? 'tab-active' : ''; ?>" id="tab-general">
			<div class="luwipress-card">
				<h2><?php esc_html_e( 'Logging', 'luwipress' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="luwipress_enable_logging"><?php esc_html_e( 'Enable Logging', 'luwipress' ); ?></label></th>
						<td>
							<label>
								<input type="checkbox" id="luwipress_enable_logging" name="luwipress_enable_logging" value="1" <?php checked( $enable_logging, 1 ); ?> />
								<?php esc_html_e( 'Record API and AI processing events', 'luwipress' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><label for="luwipress_log_level"><?php esc_html_e( 'Log Level', 'luwipress' ); ?></label></th>
						<td>
							<select id="luwipress_log_level" name="luwipress_log_level">
								<option value="debug" <?php selected( $log_level, 'debug' ); ?>><?php esc_html_e( 'Debug (all)', 'luwipress' ); ?></option>
								<option value="info" <?php selected( $log_level, 'info' ); ?>><?php esc_html_e( 'Info', 'luwipress' ); ?></option>
								<option value="warning" <?php selected( $log_level, 'warning' ); ?>><?php esc_html_e( 'Warning', 'luwipress' ); ?></option>
								<option value="error" <?php selected( $log_level, 'error' ); ?>><?php esc_html_e( 'Error only', 'luwipress' ); ?></option>
							</select>
						</td>
					</tr>
				</table>
			</div>
			<div class="luwipress-card">
				<h2><?php esc_html_e( 'Performance', 'luwipress' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="luwipress_rate_limit"><?php esc_html_e( 'Rate Limit', 'luwipress' ); ?></label></th>
						<td>
							<input type="number" id="luwipress_rate_limit" name="luwipress_rate_limit"
							       value="<?php echo esc_attr( $rate_limit ); ?>" min="10" max="10000" class="small-text" />
							<span class="description"><?php esc_html_e( 'requests/hour/IP', 'luwipress' ); ?></span>
						</td>
					</tr>
					<tr>
						<th><label for="luwipress_api_timeout"><?php esc_html_e( 'API Timeout', 'luwipress' ); ?></label></th>
						<td>
							<input type="number" id="luwipress_api_timeout" name="luwipress_api_timeout"
							       value="<?php echo esc_attr( $api_timeout ); ?>" min="5" max="120" class="small-text" />
							<span class="description"><?php esc_html_e( 'seconds', 'luwipress' ); ?></span>
						</td>
					</tr>
				</table>
			</div>
		</div>

		<!-- AI API KEYS -->
		<div class="luwipress-tab-content <?php echo 'api-keys' === $active_tab ? 'tab-active' : ''; ?>" id="tab-api-keys">
			<div class="luwipress-info-box">
				<span class="dashicons dashicons-info" style="color:#6366f1"></span>
				<?php esc_html_e( 'LuwiPress uses these API keys for AI content generation, translation, and review responses. Select your preferred provider and enter its key.', 'luwipress' ); ?>
			</div>

			<div class="luwipress-card">
				<h2><?php esc_html_e( 'AI Provider', 'luwipress' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Primary Provider', 'luwipress' ); ?></th>
						<td>
							<fieldset class="luwipress-provider-select">
								<label class="luwipress-provider-option <?php echo 'anthropic' === $ai_provider ? 'provider-selected' : ''; ?>">
									<input type="radio" name="luwipress_ai_provider" value="anthropic" <?php checked( $ai_provider, 'anthropic' ); ?> />
									<span class="provider-card">
										<strong>Claude (Anthropic)</strong>
										<span class="provider-desc"><?php esc_html_e( 'Best for nuanced product descriptions and multilingual content', 'luwipress' ); ?></span>
									</span>
								</label>
								<label class="luwipress-provider-option <?php echo 'openai' === $ai_provider ? 'provider-selected' : ''; ?>">
									<input type="radio" name="luwipress_ai_provider" value="openai" <?php checked( $ai_provider, 'openai' ); ?> />
									<span class="provider-card">
										<strong>OpenAI (GPT)</strong>
										<span class="provider-desc"><?php esc_html_e( 'GPT-4o for content generation, DALL-E for images', 'luwipress' ); ?></span>
									</span>
								</label>
								<label class="luwipress-provider-option <?php echo 'google' === $ai_provider ? 'provider-selected' : ''; ?>">
									<input type="radio" name="luwipress_ai_provider" value="google" <?php checked( $ai_provider, 'google' ); ?> />
									<span class="provider-card">
										<strong>Google Gemini</strong>
										<span class="provider-desc"><?php esc_html_e( 'Gemini Pro for cost-effective content at scale', 'luwipress' ); ?></span>
									</span>
								</label>
							</fieldset>
						</td>
					</tr>
				</table>
			</div>

			<div class="luwipress-card">
				<h2><?php esc_html_e( 'API Keys', 'luwipress' ); ?></h2>
				<p class="description" style="margin-bottom:16px;">
					<?php esc_html_e( 'Enter the API key for your selected provider.', 'luwipress' ); ?>
				</p>
				<table class="form-table">
					<tr>
						<th>
							<label for="luwipress_anthropic_api_key">
								<?php esc_html_e( 'Anthropic API Key', 'luwipress' ); ?>
								<?php if ( 'anthropic' === $ai_provider ) : ?>
									<span class="badge-active" style="margin-left:6px;"><?php esc_html_e( 'Active', 'luwipress' ); ?></span>
								<?php endif; ?>
							</label>
						</th>
						<td>
							<input type="password" id="luwipress_anthropic_api_key" name="luwipress_anthropic_api_key"
							       value="<?php echo esc_attr( $anthropic_key ); ?>" class="regular-text" autocomplete="off"
							       placeholder="sk-ant-..." />
							<button type="button" class="button button-small luwipress-toggle-password" data-target="luwipress_anthropic_api_key">
								<span class="dashicons dashicons-visibility"></span>
							</button>
							<?php if ( ! empty( $anthropic_key ) ) : ?>
								<span style="color:#16a34a;margin-left:8px;"><span class="dashicons dashicons-yes-alt"></span></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th>
							<label for="luwipress_openai_api_key">
								<?php esc_html_e( 'OpenAI API Key', 'luwipress' ); ?>
								<?php if ( 'openai' === $ai_provider ) : ?>
									<span class="badge-active" style="margin-left:6px;"><?php esc_html_e( 'Active', 'luwipress' ); ?></span>
								<?php endif; ?>
							</label>
						</th>
						<td>
							<input type="password" id="luwipress_openai_api_key" name="luwipress_openai_api_key"
							       value="<?php echo esc_attr( $openai_key ); ?>" class="regular-text" autocomplete="off"
							       placeholder="sk-..." />
							<button type="button" class="button button-small luwipress-toggle-password" data-target="luwipress_openai_api_key">
								<span class="dashicons dashicons-visibility"></span>
							</button>
							<?php if ( ! empty( $openai_key ) ) : ?>
								<span style="color:#16a34a;margin-left:8px;"><span class="dashicons dashicons-yes-alt"></span></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th>
							<label for="luwipress_google_ai_api_key">
								<?php esc_html_e( 'Google AI API Key', 'luwipress' ); ?>
								<?php if ( 'google' === $ai_provider ) : ?>
									<span class="badge-active" style="margin-left:6px;"><?php esc_html_e( 'Active', 'luwipress' ); ?></span>
								<?php endif; ?>
							</label>
						</th>
						<td>
							<input type="password" id="luwipress_google_ai_api_key" name="luwipress_google_ai_api_key"
							       value="<?php echo esc_attr( $google_ai_key ); ?>" class="regular-text" autocomplete="off"
							       placeholder="AIza..." />
							<button type="button" class="button button-small luwipress-toggle-password" data-target="luwipress_google_ai_api_key">
								<span class="dashicons dashicons-visibility"></span>
							</button>
							<?php if ( ! empty( $google_ai_key ) ) : ?>
								<span style="color:#16a34a;margin-left:8px;"><span class="dashicons dashicons-yes-alt"></span></span>
							<?php endif; ?>
						</td>
					</tr>
				</table>
			</div>

			<!-- Model Selection & Cost Control -->
			<div class="luwipress-card">
				<h2><span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e( 'AI Model & Cost Control', 'luwipress' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="luwipress_ai_model"><?php esc_html_e( 'AI Model', 'luwipress' ); ?></label></th>
						<td>
							<select name="luwipress_ai_model" id="luwipress_ai_model">
								<optgroup label="Anthropic">
									<option value="claude-haiku-4-5" <?php selected( $ai_model, 'claude-haiku-4-5' ); ?>>Claude Haiku — Hızlı &amp; Ucuz ✅ Önerilen ($0.80/$4.00/M)</option>
									<option value="claude-sonnet-4-6" <?php selected( $ai_model, 'claude-sonnet-4-6' ); ?>>Claude Sonnet — Dengeli ($3.00/$15.00/M)</option>
								</optgroup>
								<optgroup label="OpenAI">
									<option value="gpt-4o-mini" <?php selected( $ai_model, 'gpt-4o-mini' ); ?>>GPT-4o Mini — Ucuz ($0.15/$0.60/M)</option>
									<option value="gpt-4o" <?php selected( $ai_model, 'gpt-4o' ); ?>>GPT-4o — Güçlü ($2.50/$10.00/M)</option>
								</optgroup>
								<optgroup label="Google">
									<option value="gemini-2.0-flash" <?php selected( $ai_model, 'gemini-2.0-flash' ); ?>>Gemini 2.0 Flash ($0.10/$0.40/M)</option>
									<option value="gemini-2.5-flash" <?php selected( $ai_model, 'gemini-2.5-flash' ); ?>>Gemini 2.5 Flash ($0.15/$0.60/M)</option>
									<option value="gemini-2.5-pro" <?php selected( $ai_model, 'gemini-2.5-pro' ); ?>>Gemini 2.5 Pro ($1.25/$10.00/M)</option>
								</optgroup>
							</select>
							<p class="description"><?php esc_html_e( 'Selected model is used for AI content enrichment.', 'luwipress' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="luwipress_daily_token_limit"><?php esc_html_e( 'Daily Budget Limit ($)', 'luwipress' ); ?></label></th>
						<td>
							<input type="number" name="luwipress_daily_token_limit" id="luwipress_daily_token_limit"
							       value="<?php echo esc_attr( $daily_token_limit ); ?>"
							       min="0" max="100" step="0.10" style="width:100px;" />
							<span class="description"><?php esc_html_e( 'AI features auto-pause when reached. 0 = unlimited.', 'luwipress' ); ?></span>
							<?php
							$today_cost = class_exists( 'LuwiPress_Token_Tracker' ) ? LuwiPress_Token_Tracker::get_today_cost() : 0;
							if ( $daily_token_limit > 0 ) :
								$pct = min( 100, round( ( $today_cost / $daily_token_limit ) * 100 ) );
							?>
							<div style="margin-top:8px;">
								<div style="display:inline-block;width:200px;height:6px;background:#e2e8f0;border-radius:3px;overflow:hidden;vertical-align:middle;">
									<div style="height:100%;width:<?php echo $pct; ?>%;background:<?php echo $pct >= 90 ? '#dc2626' : ( $pct >= 70 ? '#f59e0b' : '#16a34a' ); ?>;"></div>
								</div>
								<span style="font-size:12px;color:#6b7280;margin-left:8px;">
									$<?php echo number_format( $today_cost, 4 ); ?> / $<?php echo number_format( $daily_token_limit, 2 ); ?> today
								</span>
							</div>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><label for="luwipress_max_output_tokens"><?php esc_html_e( 'Max Output Tokens', 'luwipress' ); ?></label></th>
						<td>
							<input type="number" name="luwipress_max_output_tokens" id="luwipress_max_output_tokens"
							       value="<?php echo esc_attr( $max_output_tokens ); ?>"
							       min="256" max="8000" step="128" style="width:100px;" />
							<span class="description"><?php esc_html_e( 'Max tokens per AI call. Lower = cheaper. Recommended: 1024–2000.', 'luwipress' ); ?></span>
						</td>
					</tr>
				</table>
			</div>

			<div class="luwipress-card luwipress-card--muted">
				<h2><?php esc_html_e( 'Cost Protection', 'luwipress' ); ?></h2>
				<ul class="luwipress-feature-list">
					<li><span class="dashicons dashicons-shield"></span> <?php esc_html_e( 'Daily budget limit auto-pauses AI when reached — no surprise charges', 'luwipress' ); ?></li>
					<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Local commands (/scan, /seo, etc.) always work with zero AI cost', 'luwipress' ); ?></li>
					<li><span class="dashicons dashicons-chart-bar"></span> <?php esc_html_e( 'Token usage tracked per workflow — see exactly what costs money', 'luwipress' ); ?></li>
					<li><span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Switch models anytime — GPT-4o Mini is 20x cheaper than Claude Sonnet', 'luwipress' ); ?></li>
				</ul>
			</div>
		</div>

		<!-- AI CONTENT -->
		<div class="luwipress-tab-content <?php echo 'ai' === $active_tab ? 'tab-active' : ''; ?>" id="tab-ai">
			<?php if ( 'none' !== $seo_plugin ) : ?>
			<div class="luwipress-info-box">
				<span class="dashicons dashicons-yes-alt" style="color:#16a34a"></span>
				<?php printf(
					esc_html__( 'SEO plugin detected: %s — AI-generated meta will be saved to its fields automatically.', 'luwipress' ),
					'<strong>' . esc_html( ucwords( str_replace( '-', ' ', $seo_plugin ) ) ) . '</strong>'
				); ?>
			</div>
			<?php endif; ?>

			<div class="luwipress-card">
				<h2><?php esc_html_e( 'AI Content Pipeline', 'luwipress' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="luwipress_auto_enrich"><?php esc_html_e( 'Auto-Enrich', 'luwipress' ); ?></label></th>
						<td>
							<label>
								<input type="checkbox" id="luwipress_auto_enrich" name="luwipress_auto_enrich" value="1" <?php checked( $auto_enrich, 1 ); ?> />
								<?php esc_html_e( 'Automatically enrich new products with AI-generated content', 'luwipress' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><label for="luwipress_target_language"><?php esc_html_e( 'Primary Language', 'luwipress' ); ?></label></th>
						<td>
							<select id="luwipress_target_language" name="luwipress_target_language">
								<?php
								$languages = array(
									'tr' => 'Turkish', 'en' => 'English', 'de' => 'German', 'fr' => 'French',
									'ar' => 'Arabic', 'es' => 'Spanish', 'it' => 'Italian', 'nl' => 'Dutch',
									'ru' => 'Russian', 'ja' => 'Japanese', 'zh' => 'Chinese',
								);
								foreach ( $languages as $code => $name ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $target_language, $code ); ?>><?php echo esc_html( "$name ($code)" ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Language for AI-generated descriptions, meta, and FAQ content.', 'luwipress' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<div class="luwipress-card">
				<h2><?php esc_html_e( 'Thin Content Auto-Enrichment', 'luwipress' ); ?></h2>
				<p class="description" style="margin-bottom:12px;">
					<?php esc_html_e( 'Automatically detect products with thin descriptions and enrich them daily via AI.', 'luwipress' ); ?>
				</p>
				<table class="form-table">
					<tr>
						<th><label for="luwipress_auto_enrich_thin"><?php esc_html_e( 'Enable', 'luwipress' ); ?></label></th>
						<td>
							<label>
								<input type="checkbox" id="luwipress_auto_enrich_thin" name="luwipress_auto_enrich_thin" value="1" <?php checked( $auto_thin, 1 ); ?> />
								<?php esc_html_e( 'Daily scan and auto-enrich products with thin content', 'luwipress' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><label for="luwipress_thin_content_threshold"><?php esc_html_e( 'Thin Threshold', 'luwipress' ); ?></label></th>
						<td>
							<input type="number" id="luwipress_thin_content_threshold" name="luwipress_thin_content_threshold"
							       value="<?php echo esc_attr( $thin_threshold ); ?>" min="50" max="2000" class="small-text" />
							<span class="description"><?php esc_html_e( 'characters — products below this are considered thin', 'luwipress' ); ?></span>
						</td>
					</tr>
					<tr>
						<th><label for="luwipress_auto_enrich_batch_size"><?php esc_html_e( 'Batch Size', 'luwipress' ); ?></label></th>
						<td>
							<input type="number" id="luwipress_auto_enrich_batch_size" name="luwipress_auto_enrich_batch_size"
							       value="<?php echo esc_attr( $thin_batch_size ); ?>" min="1" max="50" class="small-text" />
							<span class="description"><?php esc_html_e( 'products per daily run', 'luwipress' ); ?></span>
						</td>
					</tr>
				</table>

				<h3 style="margin-top:20px;"><?php esc_html_e( 'Image Generation', 'luwipress' ); ?></h3>
				<table class="form-table">
					<tr>
						<th><label for="luwipress_enrich_generate_image"><?php esc_html_e( 'Generate Images', 'luwipress' ); ?></label></th>
						<td>
							<label>
								<input type="checkbox" id="luwipress_enrich_generate_image" name="luwipress_enrich_generate_image" value="1" <?php checked( $enrich_gen_image, 1 ); ?> />
								<?php esc_html_e( 'Generate AI images for products and posts during enrichment', 'luwipress' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><label for="luwipress_image_provider"><?php esc_html_e( 'Image Provider', 'luwipress' ); ?></label></th>
						<td>
							<select id="luwipress_image_provider" name="luwipress_image_provider">
								<option value="dall-e-3" <?php selected( $image_provider, 'dall-e-3' ); ?>>OpenAI DALL-E 3 ($0.040/image)</option>
								<option value="dall-e-2" <?php selected( $image_provider, 'dall-e-2' ); ?>>OpenAI DALL-E 2 ($0.020/image)</option>
								<option value="gemini-imagen" <?php selected( $image_provider, 'gemini-imagen' ); ?>>Google Gemini Imagen 3 ($0.020/image)</option>
							</select>
							<p class="description"><?php esc_html_e( 'DALL-E 3: highest quality. Gemini Imagen 3: fast and cost-effective.', 'luwipress' ); ?></p>
						</td>
					</tr>
				</table>
			</div>
		</div>

		<!-- TRANSLATION -->
		<div class="luwipress-tab-content <?php echo 'translation' === $active_tab ? 'tab-active' : ''; ?>" id="tab-translation">
			<div class="luwipress-info-box">
				<span class="dashicons <?php echo 'none' !== $trans_plugin ? 'dashicons-yes-alt' : 'dashicons-info'; ?>"
				      style="color:<?php echo 'none' !== $trans_plugin ? '#16a34a' : '#3b82f6'; ?>"></span>
				<?php if ( 'none' !== $trans_plugin ) :
					$trans_display = ucwords( str_replace( '-', ' ', $trans_plugin ) );
					$trans_version = $env['translation']['version'] ?? '';
					printf(
						esc_html__( 'Translation plugin: %s — LuwiPress will save translations through its API.', 'luwipress' ),
						'<strong>' . esc_html( $trans_display . ( $trans_version ? " v$trans_version" : '' ) ) . '</strong>'
					);
				else :
					esc_html_e( 'No translation plugin detected. Install WPML or Polylang for multi-language support.', 'luwipress' );
				endif; ?>
			</div>

			<?php if ( 'none' !== $trans_plugin && ! empty( $env['translation']['active_languages'] ) ) : ?>
			<div class="luwipress-card">
				<h2><?php esc_html_e( 'Active Languages', 'luwipress' ); ?></h2>
				<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;">
					<?php foreach ( $env['translation']['active_languages'] as $lang ) : ?>
						<span class="lang-tag <?php echo $lang === ( $env['translation']['default_language'] ?? '' ) ? 'lang-default' : ''; ?>">
							<?php echo esc_html( strtoupper( $lang ) ); ?>
							<?php if ( $lang === ( $env['translation']['default_language'] ?? '' ) ) : ?>
								<small>(default)</small>
							<?php endif; ?>
						</span>
					<?php endforeach; ?>
				</div>
			</div>
			<?php endif; ?>

			<div class="luwipress-card">
				<h2><?php esc_html_e( 'Translation Pipeline Settings', 'luwipress' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Target Languages', 'luwipress' ); ?></th>
						<td>
							<?php
							$detector = LuwiPress_Plugin_Detector::get_instance();
							$t_env    = $detector->detect_translation();
							$t_langs  = array_diff( $t_env['active_languages'] ?? array(), array( $t_env['default_language'] ?? '' ) );
							if ( ! empty( $t_langs ) ) :
								foreach ( $t_langs as $tl ) : ?>
									<code class="lang-tag" style="margin-right:4px;"><?php echo esc_html( strtoupper( $tl ) ); ?></code>
								<?php endforeach; ?>
								<p class="description"><?php printf( esc_html__( 'Auto-detected from %s. Add or remove languages in your translation plugin settings.', 'luwipress' ), esc_html( ucwords( $t_env['plugin'] ) ) ); ?></p>
							<?php else : ?>
								<span style="color:#6b7280;"><?php esc_html_e( 'No translation plugin detected. Install WPML or Polylang to enable multilingual support.', 'luwipress' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<h3><?php esc_html_e( 'hreflang Tags', 'luwipress' ); ?></h3>
				<table class="form-table">
					<tr>
						<th><label for="luwipress_hreflang_mode"><?php esc_html_e( 'Mode', 'luwipress' ); ?></label></th>
						<td>
							<select id="luwipress_hreflang_mode" name="luwipress_hreflang_mode">
								<option value="auto" <?php selected( $hreflang_mode, 'auto' ); ?>><?php esc_html_e( 'Auto — generate only if WPML/Polylang doesn\'t', 'luwipress' ); ?></option>
								<option value="always" <?php selected( $hreflang_mode, 'always' ); ?>><?php esc_html_e( 'Always — force LuwiPress hreflang output', 'luwipress' ); ?></option>
								<option value="never" <?php selected( $hreflang_mode, 'never' ); ?>><?php esc_html_e( 'Never — disable hreflang generation', 'luwipress' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'hreflang tags tell search engines which language version to show. Critical for multilingual SEO.', 'luwipress' ); ?></p>
						</td>
					</tr>
				</table>

				<h3><?php esc_html_e( 'What Gets Translated', 'luwipress' ); ?></h3>
				<ul class="luwipress-feature-list">
					<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Product titles and descriptions', 'luwipress' ); ?></li>
					<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'SEO meta titles (<60 chars) and descriptions (<160 chars)', 'luwipress' ); ?></li>
					<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'FAQ and schema content', 'luwipress' ); ?></li>
					<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Blog post content', 'luwipress' ); ?></li>
				</ul>
			</div>
		</div>

		<!-- CRM -->
		<div class="luwipress-tab-content <?php echo 'crm' === $active_tab ? 'tab-active' : ''; ?>" id="tab-crm">
			<?php
			$detector   = LuwiPress_Plugin_Detector::get_instance();
			$crm_plugin = $detector->detect_crm();
			?>
			<?php if ( 'none' !== $crm_plugin['plugin'] ) : ?>
			<div class="luwipress-info-box">
				<span class="dashicons dashicons-info-outline"></span>
				<?php printf(
					esc_html__( 'Detected CRM: %s. LuwiPress reads its data and adds AI-powered customer intelligence — no duplication.', 'luwipress' ),
					'<strong>' . esc_html( ucwords( str_replace( '-', ' ', $crm_plugin['plugin'] ) ) ) . '</strong>'
				); ?>
			</div>
			<?php else : ?>
			<div class="luwipress-info-box">
				<span class="dashicons dashicons-info-outline"></span>
				<?php esc_html_e( 'No CRM plugin detected. LuwiPress will compute customer segments from WooCommerce order data and provide lifecycle automation.', 'luwipress' ); ?>
			</div>
			<?php endif; ?>

			<div class="luwipress-card">
				<h2><?php esc_html_e( 'Customer Segmentation Thresholds', 'luwipress' ); ?></h2>
				<p class="description" style="margin-bottom:12px;">
					<?php esc_html_e( 'These thresholds control how customers are segmented. Segments are refreshed weekly via WP Cron.', 'luwipress' ); ?>
				</p>
				<table class="form-table">
					<tr>
						<th><label for="luwipress_crm_vip_threshold"><?php esc_html_e( 'VIP Spend Threshold', 'luwipress' ); ?></label></th>
						<td>
							<input type="number" id="luwipress_crm_vip_threshold" name="luwipress_crm_vip_threshold"
							       value="<?php echo esc_attr( $crm_vip_threshold ); ?>" class="small-text" min="0" step="50" />
							<p class="description"><?php esc_html_e( 'Minimum total spend to be considered VIP (combined with loyal order count)', 'luwipress' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="luwipress_crm_loyal_orders"><?php esc_html_e( 'Loyal Order Count', 'luwipress' ); ?></label></th>
						<td>
							<input type="number" id="luwipress_crm_loyal_orders" name="luwipress_crm_loyal_orders"
							       value="<?php echo esc_attr( $crm_loyal_orders ); ?>" class="small-text" min="2" />
							<p class="description"><?php esc_html_e( 'Minimum orders to be considered Loyal', 'luwipress' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="luwipress_crm_active_days"><?php esc_html_e( 'Active Window (days)', 'luwipress' ); ?></label></th>
						<td>
							<input type="number" id="luwipress_crm_active_days" name="luwipress_crm_active_days"
							       value="<?php echo esc_attr( $crm_active_days ); ?>" class="small-text" min="7" />
							<p class="description"><?php esc_html_e( 'Customers with orders within this window are Active', 'luwipress' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="luwipress_crm_at_risk_days"><?php esc_html_e( 'At-Risk Window (days)', 'luwipress' ); ?></label></th>
						<td>
							<input type="number" id="luwipress_crm_at_risk_days" name="luwipress_crm_at_risk_days"
							       value="<?php echo esc_attr( $crm_at_risk_days ); ?>" class="small-text" min="30" />
							<p class="description"><?php esc_html_e( 'No order within this window = At Risk. Beyond this = Dormant.', 'luwipress' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<div class="luwipress-card">
				<h2><?php esc_html_e( 'Lifecycle Automation', 'luwipress' ); ?></h2>
				<?php if ( 'none' !== $crm_plugin['plugin'] ) : ?>
				<p class="description">
					<?php printf(
						esc_html__( '%s handles email automation. LuwiPress lifecycle events are disabled to avoid duplication.', 'luwipress' ),
						'<strong>' . esc_html( ucwords( str_replace( '-', ' ', $crm_plugin['plugin'] ) ) ) . '</strong>'
					); ?>
				</p>
				<?php else : ?>
				<p class="description" style="margin-bottom:12px;">
					<?php esc_html_e( 'LuwiPress processes lifecycle events automatically:', 'luwipress' ); ?>
				</p>
				<ul class="luwipress-feature-list">
					<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Post-purchase thank you emails', 'luwipress' ); ?></li>
					<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Review request emails (7 days after order)', 'luwipress' ); ?></li>
					<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Win-back campaigns for at-risk customers', 'luwipress' ); ?></li>
				</ul>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Lifecycle Queue', 'luwipress' ); ?></th>
						<td>
							<code>GET <?php echo esc_html( rest_url( 'luwipress/v1/crm/lifecycle-queue' ) ); ?></code>
							<p class="description"><?php esc_html_e( 'Endpoint for pending lifecycle events', 'luwipress' ); ?></p>
						</td>
					</tr>
				</table>
				<?php endif; ?>
			</div>
		</div>

		<!-- OPEN CLAW -->
		<div class="luwipress-tab-content <?php echo 'open-claw' === $active_tab ? 'tab-active' : ''; ?>" id="tab-open-claw">
			<div class="luwipress-info-box">
				<span class="dashicons dashicons-info-outline"></span>
				<?php esc_html_e( 'Open Claw is your AI assistant for managing WordPress + WooCommerce. Connect Telegram or WhatsApp so you can manage your store from anywhere.', 'luwipress' ); ?>
			</div>

			<div class="luwipress-card">
				<h2><span class="dashicons dashicons-superhero-alt" style="color:#6366f1;"></span> <?php esc_html_e( 'Open Claw Connection', 'luwipress' ); ?></h2>
				<p class="description" style="margin-bottom:12px;">
					<?php esc_html_e( 'Enter your Open Claw instance URL to enable AI-powered store management. You can get this from your Open Claw provider.', 'luwipress' ); ?>
				</p>
				<table class="form-table">
					<tr>
						<th><label for="luwipress_openclaw_url"><?php esc_html_e( 'Open Claw URL', 'luwipress' ); ?></label></th>
						<td>
							<input type="url" id="luwipress_openclaw_url" name="luwipress_openclaw_url"
							       value="<?php echo esc_attr( $openclaw_url ); ?>" class="regular-text"
							       placeholder="https://your-openclaw-instance.com" />
							<button type="button" class="button" id="luwipress-test-openclaw">
								<span class="dashicons dashicons-update" style="margin-top:4px;"></span>
								<?php esc_html_e( 'Test Connection', 'luwipress' ); ?>
							</button>
							<span id="luwipress-openclaw-status" style="margin-left:8px;"></span>
							<p class="description"><?php esc_html_e( 'Your Open Claw instance URL. Save settings before testing.', 'luwipress' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<div class="luwipress-card">
				<h2><span class="dashicons dashicons-telegram" style="color:#229ED9;"></span> <?php esc_html_e( 'Telegram Bot', 'luwipress' ); ?></h2>
				<p class="description" style="margin-bottom:12px;">
					<?php esc_html_e( 'Create a Telegram bot via @BotFather, paste the token below. Messages will be processed by Open Claw.', 'luwipress' ); ?>
				</p>
				<table class="form-table">
					<tr>
						<th><label for="luwipress_telegram_bot_token"><?php esc_html_e( 'Bot Token', 'luwipress' ); ?></label></th>
						<td>
							<input type="password" id="luwipress_telegram_bot_token" name="luwipress_telegram_bot_token"
							       value="<?php echo esc_attr( $tg_bot_token ); ?>" class="regular-text"
							       placeholder="123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11" />
							<button type="button" class="button luwipress-toggle-password" data-target="luwipress_telegram_bot_token">
								<span class="dashicons dashicons-visibility"></span>
							</button>
							<p class="description"><?php esc_html_e( 'Get this from Telegram @BotFather', 'luwipress' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="luwipress_telegram_admin_ids"><?php esc_html_e( 'Authorized User IDs', 'luwipress' ); ?></label></th>
						<td>
							<input type="text" id="luwipress_telegram_admin_ids" name="luwipress_telegram_admin_ids"
							       value="<?php echo esc_attr( $tg_admin_ids ); ?>" class="regular-text"
							       placeholder="123456789, 987654321" />
							<p class="description"><?php esc_html_e( 'Comma-separated Telegram user IDs allowed to use Open Claw. Use @userinfobot to find your ID.', 'luwipress' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<div class="luwipress-card">
				<h2><span class="dashicons dashicons-phone" style="color:#25D366;"></span> <?php esc_html_e( 'WhatsApp', 'luwipress' ); ?></h2>
				<p class="description" style="margin-bottom:12px;">
					<?php esc_html_e( 'Connect WhatsApp Business API. Messages from authorized numbers will be processed by Open Claw.', 'luwipress' ); ?>
				</p>
				<table class="form-table">
					<tr>
						<th><label for="luwipress_whatsapp_number"><?php esc_html_e( 'Business Number', 'luwipress' ); ?></label></th>
						<td>
							<input type="text" id="luwipress_whatsapp_number" name="luwipress_whatsapp_number"
							       value="<?php echo esc_attr( $wa_number ); ?>" class="regular-text"
							       placeholder="+905551234567" />
							<p class="description"><?php esc_html_e( 'WhatsApp Business phone number (used for reference)', 'luwipress' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="luwipress_whatsapp_admin_ids"><?php esc_html_e( 'Authorized Numbers', 'luwipress' ); ?></label></th>
						<td>
							<input type="text" id="luwipress_whatsapp_admin_ids" name="luwipress_whatsapp_admin_ids"
							       value="<?php echo esc_attr( $wa_admin_ids ); ?>" class="regular-text"
							       placeholder="+905551234567, +905559876543" />
							<p class="description"><?php esc_html_e( 'Comma-separated phone numbers allowed to use Open Claw via WhatsApp.', 'luwipress' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<div class="luwipress-card">
				<h2><?php esc_html_e( 'API Endpoints', 'luwipress' ); ?></h2>
				<p class="description" style="margin-bottom:12px;">
					<?php esc_html_e( 'Telegram/WhatsApp message processing endpoints:', 'luwipress' ); ?>
				</p>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Channel Message', 'luwipress' ); ?></th>
						<td>
							<code>POST <?php echo esc_html( rest_url( 'luwipress/v1/claw/channel-message' ) ); ?></code>
							<p class="description"><?php esc_html_e( 'Send incoming Telegram/WhatsApp messages here. Requires: channel, sender_id, message', 'luwipress' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Channel Execute', 'luwipress' ); ?></th>
						<td>
							<code>POST <?php echo esc_html( rest_url( 'luwipress/v1/claw/channel-execute' ) ); ?></code>
							<p class="description"><?php esc_html_e( 'Execute actions from callback buttons. Requires: channel, sender_id, action_type, action_data', 'luwipress' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Auth Header', 'luwipress' ); ?></th>
						<td>
							<code>Authorization: Bearer &lt;your-api-token&gt;</code>
							<p class="description"><?php esc_html_e( 'Use the API token from the Connection tab', 'luwipress' ); ?></p>
						</td>
					</tr>
				</table>
			</div>
		</div>

		<!-- CUSTOMER CHAT -->
		<div class="luwipress-tab-content <?php echo 'customer-chat' === $active_tab ? 'tab-active' : ''; ?>" id="tab-customer-chat">
			<?php
			$chat_enabled    = get_option( 'luwipress_chat_enabled', 0 );
			$chat_greeting   = get_option( 'luwipress_chat_greeting', 'Hi! How can I help you today?' );
			$chat_tone       = get_option( 'luwipress_chat_tone', 'friendly' );
			$chat_custom_instructions = get_option( 'luwipress_chat_custom_instructions', '' );
			$chat_shipping   = get_option( 'luwipress_chat_shipping_policy', '' );
			$chat_returns    = get_option( 'luwipress_chat_returns_policy', '' );
			$chat_primary    = get_option( 'luwipress_chat_color_primary', '#6366f1' );
			$chat_text_color = get_option( 'luwipress_chat_color_text', '#ffffff' );
			$chat_position   = get_option( 'luwipress_chat_position', 'bottom-right' );
			$chat_escalation = get_option( 'luwipress_chat_escalation_channel', 'whatsapp' );
			$tg_username     = get_option( 'luwipress_telegram_username', '' );
			$chat_budget     = get_option( 'luwipress_chat_daily_budget', 0.50 );
			$chat_max_msgs   = get_option( 'luwipress_chat_max_messages', 10 );
			$chat_rate       = get_option( 'luwipress_chat_rate_limit', 30 );
			?>

			<div class="luwipress-card luwipress-card--info">
				<h3><?php esc_html_e( 'Customer Chat Widget', 'luwipress' ); ?></h3>
				<p class="description"><?php esc_html_e( 'AI-powered chat widget for your customers. Answers product questions, checks order status, and escalates to WhatsApp/Telegram when needed.', 'luwipress' ); ?></p>

				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Enable Chat Widget', 'luwipress' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="luwipress_chat_enabled" value="1" <?php checked( $chat_enabled, 1 ); ?>>
								<?php esc_html_e( 'Show chat widget on frontend', 'luwipress' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><label for="luwipress_chat_greeting"><?php esc_html_e( 'Greeting Message', 'luwipress' ); ?></label></th>
						<td>
							<input type="text" id="luwipress_chat_greeting" name="luwipress_chat_greeting"
								value="<?php echo esc_attr( $chat_greeting ); ?>" class="regular-text">
						</td>
					</tr>
					<tr>
						<th><label for="luwipress_chat_tone"><?php esc_html_e( 'Chat Tone', 'luwipress' ); ?></label></th>
						<td>
							<select id="luwipress_chat_tone" name="luwipress_chat_tone">
								<option value="friendly" <?php selected( $chat_tone, 'friendly' ); ?>><?php esc_html_e( 'Friendly & Warm', 'luwipress' ); ?></option>
								<option value="professional" <?php selected( $chat_tone, 'professional' ); ?>><?php esc_html_e( 'Professional & Formal', 'luwipress' ); ?></option>
								<option value="casual" <?php selected( $chat_tone, 'casual' ); ?>><?php esc_html_e( 'Casual & Playful', 'luwipress' ); ?></option>
								<option value="expert" <?php selected( $chat_tone, 'expert' ); ?>><?php esc_html_e( 'Expert & Knowledgeable', 'luwipress' ); ?></option>
								<option value="luxury" <?php selected( $chat_tone, 'luxury' ); ?>><?php esc_html_e( 'Luxury & Refined', 'luwipress' ); ?></option>
								<option value="custom" <?php selected( $chat_tone, 'custom' ); ?>><?php esc_html_e( 'Custom (write your own)', 'luwipress' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Sets the personality and communication style of the chat assistant.', 'luwipress' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="luwipress_chat_custom_instructions"><?php esc_html_e( 'Custom Instructions', 'luwipress' ); ?></label></th>
						<td>
							<textarea id="luwipress_chat_custom_instructions" name="luwipress_chat_custom_instructions"
								rows="3" class="large-text" placeholder="<?php esc_attr_e( 'e.g. Always mention we offer free shipping on orders over €200. Use music-related metaphors.', 'luwipress' ); ?>"><?php echo esc_textarea( $chat_custom_instructions ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Extra instructions appended to the AI system prompt. Works with any tone.', 'luwipress' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="luwipress_chat_color_primary"><?php esc_html_e( 'Primary Color', 'luwipress' ); ?></label></th>
						<td>
							<input type="color" id="luwipress_chat_color_primary" name="luwipress_chat_color_primary"
								value="<?php echo esc_attr( $chat_primary ); ?>">
							<input type="color" name="luwipress_chat_color_text"
								value="<?php echo esc_attr( $chat_text_color ); ?>">
							<span class="description"><?php esc_html_e( 'Primary / Text color', 'luwipress' ); ?></span>
						</td>
					</tr>
					<tr>
						<th><label for="luwipress_chat_position"><?php esc_html_e( 'Widget Position', 'luwipress' ); ?></label></th>
						<td>
							<select id="luwipress_chat_position" name="luwipress_chat_position">
								<option value="bottom-right" <?php selected( $chat_position, 'bottom-right' ); ?>><?php esc_html_e( 'Bottom Right', 'luwipress' ); ?></option>
								<option value="bottom-left" <?php selected( $chat_position, 'bottom-left' ); ?>><?php esc_html_e( 'Bottom Left', 'luwipress' ); ?></option>
							</select>
						</td>
					</tr>
				</table>
			</div>

			<div class="luwipress-card luwipress-card--success">
				<h3><?php esc_html_e( 'Escalation — Connect to Team', 'luwipress' ); ?></h3>
				<p class="description"><?php esc_html_e( 'When AI cannot answer or customer requests, they are redirected to your team via WhatsApp or Telegram.', 'luwipress' ); ?></p>

				<table class="form-table">
					<tr>
						<th><label for="luwipress_chat_escalation_channel"><?php esc_html_e( 'Escalation Channel', 'luwipress' ); ?></label></th>
						<td>
							<select id="luwipress_chat_escalation_channel" name="luwipress_chat_escalation_channel">
								<option value="whatsapp" <?php selected( $chat_escalation, 'whatsapp' ); ?>><?php esc_html_e( 'WhatsApp', 'luwipress' ); ?></option>
								<option value="telegram" <?php selected( $chat_escalation, 'telegram' ); ?>><?php esc_html_e( 'Telegram', 'luwipress' ); ?></option>
								<option value="both" <?php selected( $chat_escalation, 'both' ); ?>><?php esc_html_e( 'Both (customer chooses)', 'luwipress' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="luwipress_whatsapp_number"><?php esc_html_e( 'WhatsApp Number', 'luwipress' ); ?></label></th>
						<td>
							<input type="text" id="luwipress_whatsapp_number_chat" name="luwipress_whatsapp_number"
								value="<?php echo esc_attr( get_option( 'luwipress_whatsapp_number', '' ) ); ?>" class="regular-text"
								placeholder="905xxxxxxxxx">
							<p class="description"><?php esc_html_e( 'International format without + sign', 'luwipress' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="luwipress_telegram_username"><?php esc_html_e( 'Telegram Username', 'luwipress' ); ?></label></th>
						<td>
							<input type="text" id="luwipress_telegram_username" name="luwipress_telegram_username"
								value="<?php echo esc_attr( $tg_username ); ?>" class="regular-text"
								placeholder="username">
							<p class="description"><?php esc_html_e( 'Without @ sign. Used for t.me/username deep link.', 'luwipress' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="luwipress_chat_max_messages"><?php esc_html_e( 'Auto-Escalate After', 'luwipress' ); ?></label></th>
						<td>
							<input type="number" id="luwipress_chat_max_messages" name="luwipress_chat_max_messages"
								value="<?php echo esc_attr( $chat_max_msgs ); ?>" min="3" max="50" style="width:80px;">
							<span class="description"><?php esc_html_e( 'messages (suggest connecting to team)', 'luwipress' ); ?></span>
						</td>
					</tr>
				</table>
			</div>

			<div class="luwipress-card luwipress-card--warning">
				<h3><?php esc_html_e( 'Store Policies (RAG Context)', 'luwipress' ); ?></h3>
				<p class="description"><?php esc_html_e( 'These texts are fed to the AI so it can answer shipping and return questions accurately.', 'luwipress' ); ?></p>

				<table class="form-table">
					<tr>
						<th><label for="luwipress_chat_shipping_policy"><?php esc_html_e( 'Shipping Policy', 'luwipress' ); ?></label></th>
						<td>
							<textarea id="luwipress_chat_shipping_policy" name="luwipress_chat_shipping_policy"
								rows="4" class="large-text"><?php echo esc_textarea( $chat_shipping ); ?></textarea>
						</td>
					</tr>
					<tr>
						<th><label for="luwipress_chat_returns_policy"><?php esc_html_e( 'Returns Policy', 'luwipress' ); ?></label></th>
						<td>
							<textarea id="luwipress_chat_returns_policy" name="luwipress_chat_returns_policy"
								rows="4" class="large-text"><?php echo esc_textarea( $chat_returns ); ?></textarea>
						</td>
					</tr>
				</table>
			</div>

			<div class="luwipress-card luwipress-card--error">
				<h3><?php esc_html_e( 'Budget & Rate Limiting', 'luwipress' ); ?></h3>

				<table class="form-table">
					<tr>
						<th><label for="luwipress_chat_daily_budget"><?php esc_html_e( 'Daily AI Budget', 'luwipress' ); ?></label></th>
						<td>
							<input type="number" id="luwipress_chat_daily_budget" name="luwipress_chat_daily_budget"
								value="<?php echo esc_attr( $chat_budget ); ?>" min="0" max="100" step="0.10" style="width:100px;">
							<span class="description"><?php esc_html_e( 'USD per day (0 = unlimited). Separate from main AI budget.', 'luwipress' ); ?></span>
						</td>
					</tr>
					<tr>
						<th><label for="luwipress_chat_rate_limit"><?php esc_html_e( 'Rate Limit', 'luwipress' ); ?></label></th>
						<td>
							<input type="number" id="luwipress_chat_rate_limit" name="luwipress_chat_rate_limit"
								value="<?php echo esc_attr( $chat_rate ); ?>" min="5" max="500" style="width:80px;">
							<span class="description"><?php esc_html_e( 'messages per hour per visitor', 'luwipress' ); ?></span>
						</td>
					</tr>
				</table>
			</div>
		</div>

		<!-- MARKETPLACES -->
		<div class="luwipress-tab-content <?php echo 'marketplaces' === $active_tab ? 'tab-active' : ''; ?>" id="tab-marketplaces">

			<style>
				/* ── Marketplace Tab — Design System ── */
				.lp-mp-search{position:relative;margin-bottom:var(--space-lg)}
				.lp-mp-search input{width:100%;padding:10px 14px 10px 38px;border:1px solid var(--lp-border);border-radius:var(--radius-md);font-size:var(--text-md);font-family:var(--font-sans);background:var(--lp-surface);transition:border-color var(--duration-fast) var(--ease-out),box-shadow var(--duration-fast) var(--ease-out);outline:none}
				.lp-mp-search input:focus{border-color:var(--lp-primary);box-shadow:0 0 0 3px var(--lp-primary-50)}
				.lp-mp-search input::placeholder{color:var(--lp-gray-light)}
				.lp-mp-search .dashicons{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--lp-gray-light);font-size:16px}

				.lp-mp-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:var(--space-md)}
				.lp-mp-card{background:var(--lp-surface);border:1px solid var(--lp-border);border-radius:var(--radius-md);overflow:hidden;transition:border-color var(--duration-fast) var(--ease-out),box-shadow var(--duration-fast) var(--ease-out)}
				.lp-mp-card:hover{border-color:var(--lp-primary-light);box-shadow:var(--lp-shadow-md)}
				.lp-mp-card.lp-mp-connected{border-color:var(--lp-success)}
				.lp-mp-card[hidden]{display:none}

				.lp-mp-head{display:flex;align-items:center;padding:var(--space-md) var(--space-lg);gap:var(--space-md);cursor:pointer;user-select:none}
				.lp-mp-dot{width:10px;height:10px;border-radius:var(--radius-full);flex-shrink:0}
				.lp-mp-label{font-weight:600;font-size:var(--text-md);color:var(--lp-text);flex:1}
				.lp-mp-status{font-size:var(--text-xs);font-weight:600;text-transform:uppercase;letter-spacing:.5px;padding:2px 8px;border-radius:var(--radius-full)}
				.lp-mp-status.on{background:#dcfce7;color:var(--lp-success)}
				.lp-mp-status.cfg{background:var(--lp-primary-50);color:var(--lp-primary)}
				.lp-mp-status.off{background:var(--lp-border-light);color:var(--lp-gray-light)}
				.lp-mp-chevron{color:var(--lp-gray-light);transition:transform var(--duration-fast) var(--ease-out);font-size:16px}
				.lp-mp-card.open .lp-mp-chevron{transform:rotate(180deg)}

				.lp-mp-fields{display:none;padding:0 var(--space-lg) var(--space-lg);border-top:1px solid var(--lp-border-light)}
				.lp-mp-card.open .lp-mp-fields{display:block}
				.lp-mp-field{display:flex;align-items:center;gap:var(--space-sm);margin-top:var(--space-md)}
				.lp-mp-field label{min-width:100px;font-size:var(--text-sm);color:var(--lp-text-secondary);font-weight:500}
				.lp-mp-field input[type="text"],.lp-mp-field input[type="password"],.lp-mp-field select{flex:1;padding:7px 10px;border:1px solid var(--lp-border);border-radius:var(--radius-sm);font-size:var(--text-sm);font-family:var(--font-sans);background:var(--lp-surface);transition:border-color var(--duration-fast);outline:none;max-width:280px}
				.lp-mp-field input:focus,.lp-mp-field select:focus{border-color:var(--lp-primary)}
				.lp-mp-field input[type="number"]{width:80px;flex:unset}
				.lp-mp-field .lp-mp-ok{color:var(--lp-success);font-size:14px;flex-shrink:0}
				.lp-mp-enable{margin-top:var(--space-md);display:flex;align-items:center;gap:var(--space-sm)}
				.lp-mp-enable label{font-size:var(--text-sm);color:var(--lp-text);font-weight:500;cursor:pointer}

				.lp-mp-empty{text-align:center;padding:var(--space-3xl);color:var(--lp-gray-light);font-size:var(--text-md);display:none}
			</style>

			<!-- Search -->
			<div class="lp-mp-search">
				<span class="dashicons dashicons-search"></span>
				<input type="text" id="lp-mp-filter" placeholder="<?php esc_attr_e( 'Search marketplaces...', 'luwipress' ); ?>" autocomplete="off" />
			</div>

			<!-- Grid -->
			<div class="lp-mp-grid" id="lp-mp-grid">
			<?php
			$mp_defs = array(
				'amazon'      => array( 'Amazon',      '#ff9900', array(
					array( 'luwipress_amazon_api_key',   'API Key',   'password', $mp_cfg['amazon']['api_key'] ),
					array( 'luwipress_amazon_seller_id', 'Seller ID', 'text',     $mp_cfg['amazon']['seller_id'] ),
					array( 'luwipress_amazon_region',    'Region',    'select',   $mp_cfg['amazon']['region'], array( 'na' => 'NA', 'eu' => 'EU', 'fe' => 'FE' ) ),
				)),
				'ebay'        => array( 'eBay',        '#e53238', array(
					array( 'luwipress_ebay_api_key',        'OAuth Token', 'password', $mp_cfg['ebay']['api_key'] ),
					array( 'luwipress_ebay_environment',    'Environment', 'select',   $mp_cfg['ebay']['environment'], array( 'sandbox' => 'Sandbox', 'production' => 'Production' ) ),
					array( 'luwipress_ebay_marketplace_id', 'Market',      'select',   $mp_cfg['ebay']['marketplace'], array( 'EBAY_US' => 'US', 'EBAY_GB' => 'UK', 'EBAY_DE' => 'DE', 'EBAY_FR' => 'FR', 'EBAY_IT' => 'IT', 'EBAY_ES' => 'ES', 'EBAY_AU' => 'AU' ) ),
				)),
				'trendyol'    => array( 'Trendyol',    '#f27a1a', array(
					array( 'luwipress_trendyol_api_key',    'API Key',    'password', $mp_cfg['trendyol']['api_key'] ),
					array( 'luwipress_trendyol_api_secret', 'API Secret', 'password', $mp_cfg['trendyol']['api_secret'] ),
					array( 'luwipress_trendyol_seller_id',  'Seller ID',  'text',     $mp_cfg['trendyol']['seller_id'] ),
					array( 'luwipress_trendyol_cargo_company_id', 'Cargo ID', 'number', $mp_cfg['trendyol']['cargo'] ),
				)),
				'hepsiburada' => array( 'Hepsiburada', '#ff6000', array(
					array( 'luwipress_hepsiburada_api_key',     'API Key',     'password', $mp_cfg['hepsiburada']['api_key'] ),
					array( 'luwipress_hepsiburada_api_secret',  'API Secret',  'password', $mp_cfg['hepsiburada']['api_secret'] ),
					array( 'luwipress_hepsiburada_merchant_id', 'Merchant ID', 'text',     $mp_cfg['hepsiburada']['merchant_id'] ),
				)),
				'n11'         => array( 'N11',         '#1a237e', array(
					array( 'luwipress_n11_api_key',    'API Key',    'password', $mp_cfg['n11']['api_key'] ),
					array( 'luwipress_n11_api_secret', 'API Secret', 'password', $mp_cfg['n11']['api_secret'] ),
				)),
				'alibaba'     => array( 'Alibaba',     '#ff6a00', array(
					array( 'luwipress_alibaba_app_key',      'App Key',      'text',     $mp_cfg['alibaba']['app_key'] ),
					array( 'luwipress_alibaba_app_secret',   'App Secret',   'password', $mp_cfg['alibaba']['app_secret'] ),
					array( 'luwipress_alibaba_access_token', 'Access Token', 'password', $mp_cfg['alibaba']['access_token'] ),
				)),
				'etsy'        => array( 'Etsy',        '#f1641e', array(
					array( 'luwipress_etsy_api_key', 'API Key', 'password', $mp_cfg['etsy']['api_key'] ),
					array( 'luwipress_etsy_shop_id', 'Shop ID', 'text',     $mp_cfg['etsy']['shop_id'] ),
				)),
				'walmart'     => array( 'Walmart',     '#0071dc', array(
					array( 'luwipress_walmart_client_id',     'Client ID',     'text',     $mp_cfg['walmart']['client_id'] ),
					array( 'luwipress_walmart_client_secret', 'Client Secret', 'password', $mp_cfg['walmart']['client_secret'] ),
				)),
			);

			foreach ( $mp_defs as $slug => $mp ) :
				$label   = $mp[0];
				$color   = $mp[1];
				$fields  = $mp[2];
				$enabled = ! empty( $mp_cfg[ $slug ]['enabled'] );
				$has_key = false;
				foreach ( $fields as $f ) {
					if ( in_array( $f[2], array( 'password', 'text' ), true ) && ! empty( $f[3] ) ) { $has_key = true; break; }
				}
				$state_class = ( $enabled && $has_key ) ? 'lp-mp-connected' : '';
				$state_class .= $enabled ? ' open' : '';
			?>
				<div class="lp-mp-card <?php echo esc_attr( trim( $state_class ) ); ?>" data-mp="<?php echo esc_attr( $slug ); ?>" data-name="<?php echo esc_attr( strtolower( $label ) ); ?>">
					<div class="lp-mp-head" onclick="this.parentElement.classList.toggle('open')">
						<span class="lp-mp-dot" style="background:<?php echo esc_attr( $color ); ?>"></span>
						<span class="lp-mp-label"><?php echo esc_html( $label ); ?></span>
						<?php if ( $enabled && $has_key ) : ?>
							<span class="lp-mp-status on"><?php esc_html_e( 'Live', 'luwipress' ); ?></span>
						<?php elseif ( $has_key ) : ?>
							<span class="lp-mp-status cfg"><?php esc_html_e( 'Ready', 'luwipress' ); ?></span>
						<?php else : ?>
							<span class="lp-mp-status off"><?php esc_html_e( 'Off', 'luwipress' ); ?></span>
						<?php endif; ?>
						<span class="lp-mp-chevron dashicons dashicons-arrow-down-alt2"></span>
					</div>
					<div class="lp-mp-fields">
						<div class="lp-mp-enable">
							<input type="checkbox" id="lp_en_<?php echo esc_attr( $slug ); ?>" name="luwipress_marketplace_<?php echo esc_attr( $slug ); ?>_enabled" value="1" <?php checked( $enabled ); ?> />
							<label for="lp_en_<?php echo esc_attr( $slug ); ?>"><?php printf( esc_html__( 'Enable %s', 'luwipress' ), esc_html( $label ) ); ?></label>
						</div>
						<?php foreach ( $fields as $f ) :
							$fn = $f[0]; $fl = $f[1]; $ft = $f[2]; $fv = $f[3]; $fo = $f[4] ?? array();
						?>
						<div class="lp-mp-field">
							<label for="<?php echo esc_attr( $fn ); ?>"><?php echo esc_html( $fl ); ?></label>
							<?php if ( 'select' === $ft ) : ?>
								<select id="<?php echo esc_attr( $fn ); ?>" name="<?php echo esc_attr( $fn ); ?>">
									<?php foreach ( $fo as $ov => $ol ) : ?>
										<option value="<?php echo esc_attr( $ov ); ?>" <?php selected( $fv, $ov ); ?>><?php echo esc_html( $ol ); ?></option>
									<?php endforeach; ?>
								</select>
							<?php elseif ( 'number' === $ft ) : ?>
								<input type="number" id="<?php echo esc_attr( $fn ); ?>" name="<?php echo esc_attr( $fn ); ?>" value="<?php echo esc_attr( $fv ); ?>" min="1" />
							<?php else : ?>
								<input type="<?php echo esc_attr( $ft ); ?>" id="<?php echo esc_attr( $fn ); ?>" name="<?php echo esc_attr( $fn ); ?>" value="<?php echo esc_attr( $fv ); ?>" autocomplete="off" />
								<?php if ( 'password' === $ft && ! empty( $fv ) ) : ?>
									<span class="lp-mp-ok dashicons dashicons-yes-alt"></span>
								<?php endif; ?>
							<?php endif; ?>
						</div>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endforeach; ?>
			</div>

			<div class="lp-mp-empty" id="lp-mp-empty"><?php esc_html_e( 'No marketplaces match your search.', 'luwipress' ); ?></div>

			<script>
			(function(){
				var input = document.getElementById('lp-mp-filter');
				var grid  = document.getElementById('lp-mp-grid');
				var empty = document.getElementById('lp-mp-empty');
				if (!input || !grid) return;

				input.addEventListener('input', function(){
					var q = this.value.toLowerCase().trim();
					var cards = grid.querySelectorAll('.lp-mp-card');
					var visible = 0;
					cards.forEach(function(c){
						var match = !q || c.getAttribute('data-name').indexOf(q) !== -1 || c.getAttribute('data-mp').indexOf(q) !== -1;
						c.hidden = !match;
						if (match) visible++;
					});
					empty.style.display = visible === 0 ? 'block' : 'none';
				});
			})();
			</script>

		</div>

		<!-- ADVERTISING -->
		<div class="luwipress-tab-content <?php echo 'advertising' === $active_tab ? 'tab-active' : ''; ?>" id="tab-advertising">
			<?php
			$detector   = LuwiPress_Plugin_Detector::get_instance();
			$ad_gads    = $detector->detect_google_ads();
			$ad_meta    = $detector->detect_meta_ads();
			$ad_analytics = $detector->detect_analytics();
			?>

			<!-- Detected Plugins Status -->
			<div class="luwipress-card">
				<h2><?php esc_html_e( 'Detected Advertising Plugins', 'luwipress' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Analytics', 'luwipress' ); ?></th>
						<td>
							<?php if ( 'none' !== $ad_analytics['plugin'] ) : ?>
								<span class="lp-pill pill-ok"><span class="dashicons dashicons-yes-alt"></span> <?php echo esc_html( ucwords( str_replace( '-', ' ', $ad_analytics['plugin'] ) ) . ' v' . $ad_analytics['version'] ); ?></span>
							<?php else : ?>
								<span class="lp-pill pill-neutral"><?php esc_html_e( 'Not detected', 'luwipress' ); ?></span>
								<p class="description"><?php esc_html_e( 'Recommended: Google Site Kit or GTM4WP', 'luwipress' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Google Ads', 'luwipress' ); ?></th>
						<td>
							<?php if ( 'none' !== $ad_gads['plugin'] ) : ?>
								<span class="lp-pill pill-ok"><span class="dashicons dashicons-yes-alt"></span> <?php echo esc_html( ucwords( str_replace( '-', ' ', $ad_gads['plugin'] ) ) . ' v' . $ad_gads['version'] ); ?></span>
								<?php if ( ! empty( $ad_gads['features']['merchant_center'] ) ) : ?>
									<span class="lp-pill pill-ok"><span class="dashicons dashicons-store"></span> <?php esc_html_e( 'Merchant Center', 'luwipress' ); ?></span>
								<?php endif; ?>
							<?php else : ?>
								<span class="lp-pill pill-neutral"><?php esc_html_e( 'Not detected', 'luwipress' ); ?></span>
								<p class="description"><?php esc_html_e( 'Recommended: Google for WooCommerce', 'luwipress' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Meta (Facebook)', 'luwipress' ); ?></th>
						<td>
							<?php if ( 'none' !== $ad_meta['plugin'] ) : ?>
								<span class="lp-pill pill-ok"><span class="dashicons dashicons-yes-alt"></span> <?php echo esc_html( ucwords( str_replace( '-', ' ', $ad_meta['plugin'] ) ) . ' v' . $ad_meta['version'] ); ?></span>
								<?php if ( ! empty( $ad_meta['features']['conversion_api'] ) ) : ?>
									<span class="lp-pill pill-ok"><span class="dashicons dashicons-cloud"></span> <?php esc_html_e( 'CAPI', 'luwipress' ); ?></span>
								<?php endif; ?>
							<?php else : ?>
								<span class="lp-pill pill-neutral"><?php esc_html_e( 'Not detected', 'luwipress' ); ?></span>
								<p class="description"><?php esc_html_e( 'Recommended: Meta Pixel for WordPress', 'luwipress' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				</table>
			</div>

			<!-- Google Ads Configuration -->
			<div class="luwipress-card">
				<h2><span class="dashicons dashicons-megaphone"></span> <?php esc_html_e( 'Google Ads', 'luwipress' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Store your Google Ads identifiers for AI-powered ad copy generation and conversion tracking reference.', 'luwipress' ); ?></p>
				<table class="form-table">
					<tr>
						<th><label for="luwipress_google_ads_customer_id"><?php esc_html_e( 'Customer ID', 'luwipress' ); ?></label></th>
						<td>
							<input type="text" id="luwipress_google_ads_customer_id" name="luwipress_google_ads_customer_id" class="regular-text" value="<?php echo esc_attr( get_option( 'luwipress_google_ads_customer_id', '' ) ); ?>" placeholder="123-456-7890" />
							<p class="description"><?php esc_html_e( 'Your Google Ads account ID (format: XXX-XXX-XXXX)', 'luwipress' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="luwipress_google_merchant_id"><?php esc_html_e( 'Merchant Center ID', 'luwipress' ); ?></label></th>
						<td>
							<input type="text" id="luwipress_google_merchant_id" name="luwipress_google_merchant_id" class="regular-text" value="<?php echo esc_attr( get_option( 'luwipress_google_merchant_id', '' ) ); ?>" placeholder="123456789" />
						</td>
					</tr>
					<tr>
						<th><label for="luwipress_google_ads_conversion_id"><?php esc_html_e( 'Conversion ID', 'luwipress' ); ?></label></th>
						<td>
							<input type="text" id="luwipress_google_ads_conversion_id" name="luwipress_google_ads_conversion_id" class="regular-text" value="<?php echo esc_attr( get_option( 'luwipress_google_ads_conversion_id', '' ) ); ?>" placeholder="AW-XXXXXXXXX" />
						</td>
					</tr>
					<tr>
						<th><label for="luwipress_google_ads_conversion_label"><?php esc_html_e( 'Conversion Label', 'luwipress' ); ?></label></th>
						<td>
							<input type="text" id="luwipress_google_ads_conversion_label" name="luwipress_google_ads_conversion_label" class="regular-text" value="<?php echo esc_attr( get_option( 'luwipress_google_ads_conversion_label', '' ) ); ?>" placeholder="AbCdEfGhIjKlMn" />
						</td>
					</tr>
				</table>
			</div>

			<!-- Meta (Facebook) Configuration -->
			<div class="luwipress-card">
				<h2><span class="dashicons dashicons-share"></span> <?php esc_html_e( 'Meta (Facebook / Instagram)', 'luwipress' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Store your Meta Business identifiers for catalog sync and conversion API reference.', 'luwipress' ); ?></p>
				<table class="form-table">
					<tr>
						<th><label for="luwipress_meta_pixel_id"><?php esc_html_e( 'Pixel ID', 'luwipress' ); ?></label></th>
						<td>
							<input type="text" id="luwipress_meta_pixel_id" name="luwipress_meta_pixel_id" class="regular-text" value="<?php echo esc_attr( get_option( 'luwipress_meta_pixel_id', '' ) ); ?>" placeholder="1234567890123456" />
							<p class="description"><?php esc_html_e( 'Found in Meta Events Manager > Data Sources', 'luwipress' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="luwipress_meta_access_token"><?php esc_html_e( 'Conversions API Token', 'luwipress' ); ?></label></th>
						<td>
							<input type="password" id="luwipress_meta_access_token" name="luwipress_meta_access_token" class="regular-text" value="<?php echo esc_attr( get_option( 'luwipress_meta_access_token', '' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'System User access token from Meta Business Settings', 'luwipress' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="luwipress_meta_catalog_id"><?php esc_html_e( 'Catalog ID', 'luwipress' ); ?></label></th>
						<td>
							<input type="text" id="luwipress_meta_catalog_id" name="luwipress_meta_catalog_id" class="regular-text" value="<?php echo esc_attr( get_option( 'luwipress_meta_catalog_id', '' ) ); ?>" placeholder="1234567890123456" />
							<p class="description"><?php esc_html_e( 'Commerce Manager > Catalog ID (for product sync)', 'luwipress' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="luwipress_meta_business_id"><?php esc_html_e( 'Business ID', 'luwipress' ); ?></label></th>
						<td>
							<input type="text" id="luwipress_meta_business_id" name="luwipress_meta_business_id" class="regular-text" value="<?php echo esc_attr( get_option( 'luwipress_meta_business_id', '' ) ); ?>" placeholder="1234567890123456" />
						</td>
					</tr>
				</table>
			</div>
		</div>

		<!-- SECURITY -->
		<div class="luwipress-tab-content <?php echo 'security' === $active_tab ? 'tab-active' : ''; ?>" id="tab-security">
			<div class="luwipress-card">
				<h2><?php esc_html_e( 'Security Headers', 'luwipress' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="luwipress_security_headers"><?php esc_html_e( 'Enable', 'luwipress' ); ?></label></th>
						<td>
							<label>
								<input type="checkbox" id="luwipress_security_headers" name="luwipress_security_headers" value="1" <?php checked( $security_headers, 1 ); ?> />
								<?php esc_html_e( 'Add X-Content-Type-Options, X-Frame-Options, Referrer-Policy', 'luwipress' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><label for="luwipress_ip_whitelist"><?php esc_html_e( 'IP Whitelist', 'luwipress' ); ?></label></th>
						<td>
							<input type="text" id="luwipress_ip_whitelist" name="luwipress_ip_whitelist"
							       value="<?php echo esc_attr( $ip_whitelist ); ?>" class="regular-text"
							       placeholder="1.2.3.4, 5.6.7.8" />
							<p class="description"><?php esc_html_e( 'Comma-separated IPs. Leave empty to allow all.', 'luwipress' ); ?></p>
						</td>
					</tr>
				</table>
			</div>
			<div class="luwipress-card">
				<h2><?php esc_html_e( 'HMAC Webhook Signing', 'luwipress' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'HMAC Secret', 'luwipress' ); ?></th>
						<td>
							<?php if ( ! empty( $hmac_secret ) ) : ?>
								<code class="luwipress-hmac-preview"><?php echo esc_html( substr( $hmac_secret, 0, 8 ) . '...' . substr( $hmac_secret, -4 ) ); ?></code>
								<span class="badge-active"><?php esc_html_e( 'Active', 'luwipress' ); ?></span>
							<?php else : ?>
								<span class="badge-inactive"><?php esc_html_e( 'Not generated', 'luwipress' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Regenerate', 'luwipress' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="luwipress_regenerate_hmac" value="1" />
								<?php esc_html_e( 'Generate new HMAC secret (invalidates existing API signatures)', 'luwipress' ); ?>
							</label>
						</td>
					</tr>
				</table>
			</div>
		</div>

		<p class="submit">
			<input type="submit" name="luwipress_save_settings" class="button-primary" value="<?php esc_attr_e( 'Save Settings', 'luwipress' ); ?>" />
		</p>
	</form>
</div>
