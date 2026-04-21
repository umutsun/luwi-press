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

	// Enrichment prompt overrides (see LuwiPress_Prompts::product_enrichment)
	$custom_prompt = wp_kses_post( wp_unslash( $_POST['luwipress_enrich_system_prompt'] ?? '' ) );
	if ( mb_strlen( $custom_prompt ) > 4000 ) {
		$custom_prompt = mb_substr( $custom_prompt, 0, 4000 );
	}
	update_option( 'luwipress_enrich_system_prompt', $custom_prompt );
	update_option( 'luwipress_enrich_target_words', max( 0, min( 3000, absint( $_POST['luwipress_enrich_target_words'] ?? 0 ) ) ) );
	update_option( 'luwipress_enrich_meta_title_max', max( 40, min( 80, absint( $_POST['luwipress_enrich_meta_title_max'] ?? 60 ) ) ) );
	update_option( 'luwipress_enrich_meta_desc_max', max( 120, min( 200, absint( $_POST['luwipress_enrich_meta_desc_max'] ?? 160 ) ) ) );
	update_option( 'luwipress_enrich_meta_desc_cta', sanitize_text_field( wp_unslash( $_POST['luwipress_enrich_meta_desc_cta'] ?? '' ) ) );

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

	// Customer Chat escalation (WhatsApp number used by the widget's escalation button)
	update_option( 'luwipress_whatsapp_number', sanitize_text_field( $_POST['luwipress_whatsapp_number'] ?? '' ) );

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
$wa_number          = get_option( 'luwipress_whatsapp_number', '' );
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
		<a href="?page=luwipress-settings&tab=customer-chat" class="nav-tab <?php echo 'customer-chat' === $active_tab ? 'nav-tab-active' : ''; ?>">
			<span class="dashicons dashicons-format-chat"></span> <?php esc_html_e( 'Customer Chat', 'luwipress' ); ?>
		</a>
		<a href="?page=luwipress-settings&tab=marketplaces" class="nav-tab <?php echo 'marketplaces' === $active_tab ? 'nav-tab-active' : ''; ?>">
			<span class="dashicons dashicons-store"></span> <?php esc_html_e( 'Marketplaces', 'luwipress' ); ?>
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
			$webmcp_active   = class_exists( 'LuwiPress_WebMCP' ) || defined( 'LUWIPRESS_WEBMCP_VERSION' );
			$webmcp_enabled  = (bool) get_option( 'luwipress_webmcp_enabled', 0 );
			$mcp_endpoint    = get_rest_url( null, 'luwipress/v1/mcp' );
			$rest_base       = get_rest_url( null, 'luwipress/v1/' );
			?>

			<!-- API Authentication -->
			<div class="luwipress-card">
				<h2><?php esc_html_e( 'API Authentication', 'luwipress' ); ?></h2>
				<p class="description" style="margin-bottom:8px;"><?php esc_html_e( 'Single Bearer token authenticates every REST call and every MCP tool call.', 'luwipress' ); ?></p>
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
							<p class="description"><?php esc_html_e( 'Pass this token as a Bearer header: Authorization: Bearer <token>. Click Generate to rotate to a fresh secure random value.', 'luwipress' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'REST Health Check', 'luwipress' ); ?></th>
						<td>
							<button type="button" class="button" id="luwipress-rest-health-check">
								<span class="dashicons dashicons-heart"></span> <?php esc_html_e( 'Ping /health', 'luwipress' ); ?>
							</button>
							<span id="luwipress-rest-health-result" style="margin-left:8px;"></span>
							<p class="description"><?php esc_html_e( 'Calls the REST health endpoint with the current token. Verifies that routing, auth, and the token are all working end-to-end.', 'luwipress' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<!-- MCP Server -->
			<div class="luwipress-card">
				<h2><?php esc_html_e( 'MCP Server', 'luwipress' ); ?></h2>

				<?php if ( ! $webmcp_active ) : ?>
					<div class="luwipress-info-box" style="border-left:3px solid var(--lp-warning,#f59e0b);">
						<span class="dashicons dashicons-info-outline"></span>
						<strong><?php esc_html_e( 'WebMCP companion plugin is not installed.', 'luwipress' ); ?></strong><br>
						<?php esc_html_e( 'AI-agent integration (Claude Code, OpenAI, custom MCP clients) requires the separate "LuwiPress WebMCP" plugin. Core LuwiPress exposes REST only; MCP tooling lives in the companion.', 'luwipress' ); ?>
					</div>
				<?php else : ?>
					<div class="luwipress-info-box" style="border-left:3px solid var(--lp-success,#16a34a);">
						<span class="dashicons dashicons-yes-alt" style="color:#16a34a"></span>
						<strong><?php esc_html_e( 'WebMCP companion plugin is active.', 'luwipress' ); ?></strong>
						<?php if ( defined( 'LUWIPRESS_WEBMCP_VERSION' ) ) : ?>
							<?php printf( esc_html__( 'Version %s.', 'luwipress' ), esc_html( LUWIPRESS_WEBMCP_VERSION ) ); ?>
						<?php endif; ?>
					</div>

					<table class="form-table">
						<tr>
							<th><?php esc_html_e( 'Endpoint URL', 'luwipress' ); ?></th>
							<td>
								<code><?php echo esc_html( $mcp_endpoint ); ?></code>
								<button type="button" class="button button-small luwipress-copy-btn" data-copy="<?php echo esc_attr( $mcp_endpoint ); ?>" style="margin-left:6px;">
									<span class="dashicons dashicons-admin-page"></span> <?php esc_html_e( 'Copy', 'luwipress' ); ?>
								</button>
								<p class="description"><?php esc_html_e( 'Configure this as the Streamable HTTP URL in your MCP client. Auth: same Bearer token above.', 'luwipress' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Server Status', 'luwipress' ); ?></th>
							<td>
								<?php if ( $webmcp_enabled ) : ?>
									<span class="lp-pill pill-ok"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Enabled', 'luwipress' ); ?></span>
								<?php else : ?>
									<span class="lp-pill pill-neutral"><?php esc_html_e( 'Disabled', 'luwipress' ); ?></span>
									<p class="description"><?php esc_html_e( 'Companion installed but MCP endpoint is turned off. Toggle it on from LuwiPress → WebMCP.', 'luwipress' ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'MCP Health Check', 'luwipress' ); ?></th>
							<td>
								<button type="button" class="button" id="luwipress-mcp-ping">
									<span class="dashicons dashicons-rest-api"></span> <?php esc_html_e( 'List tools', 'luwipress' ); ?>
								</button>
								<span id="luwipress-mcp-ping-result" style="margin-left:8px;"></span>
								<p class="description"><?php esc_html_e( 'Calls tools/list over MCP and reports tool count. Verifies transport + auth + tool registration.', 'luwipress' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Admin', 'luwipress' ); ?></th>
							<td>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=luwipress-webmcp' ) ); ?>" class="button"><?php esc_html_e( 'Open WebMCP admin', 'luwipress' ); ?></a>
							</td>
						</tr>
					</table>
				<?php endif; ?>
			</div>

			<!-- REST API Surface (collapsed summary) -->
			<div class="luwipress-card">
				<h2><?php esc_html_e( 'REST API Surface', 'luwipress' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Base URL', 'luwipress' ); ?></th>
						<td>
							<code><?php echo esc_html( $rest_base ); ?></code>
							<button type="button" class="button button-small luwipress-copy-btn" data-copy="<?php echo esc_attr( $rest_base ); ?>" style="margin-left:6px;">
								<span class="dashicons dashicons-admin-page"></span> <?php esc_html_e( 'Copy', 'luwipress' ); ?>
							</button>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Key endpoints', 'luwipress' ); ?></th>
						<td>
							<ul style="margin:0;padding-left:1.2em;">
								<li><code>POST /product/enrich</code> &middot; <code>POST /product/enrich-batch</code></li>
								<li><code>GET/POST /enrich/settings</code> &middot; <code>GET/POST /translation/settings</code> &middot; <code>GET/POST /chat/settings</code> &middot; <code>GET/POST /schedule/settings</code></li>
								<li><code>POST /translation/batch</code> &middot; <code>POST /translation/request</code></li>
								<li><code>POST /cache/purge</code></li>
								<li><code>GET /knowledge-graph</code> &middot; <code>GET /site-config</code> &middot; <code>GET /health</code></li>
							</ul>
							<p class="description"><?php esc_html_e( 'Every route listed above is also exposed as an MCP tool when the WebMCP companion is active.', 'luwipress' ); ?></p>
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
			<?php if ( 'luwipress-native' === $seo_plugin ) : ?>
			<div class="luwipress-info-box">
				<span class="dashicons dashicons-yes-alt" style="color:#16a34a"></span>
				<?php esc_html_e( 'LuwiPress is handling SEO directly — no third-party SEO plugin detected. AI-generated titles and meta descriptions are stored in LuwiPress meta keys and output in the site <head> automatically.', 'luwipress' ); ?>
			</div>
			<?php elseif ( 'none' !== $seo_plugin ) : ?>
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

			<?php
			$enrich_prompt         = (string) get_option( 'luwipress_enrich_system_prompt', '' );
			$enrich_target_words   = (string) absint( get_option( 'luwipress_enrich_target_words', 0 ) );
			$enrich_meta_title_max = (string) absint( get_option( 'luwipress_enrich_meta_title_max', 60 ) );
			$enrich_meta_desc_max  = (string) absint( get_option( 'luwipress_enrich_meta_desc_max', 160 ) );
			$enrich_meta_desc_cta  = (string) get_option( 'luwipress_enrich_meta_desc_cta', '' );
			?>
			<div class="luwipress-card">
				<h2><?php esc_html_e( 'Enrichment Prompt & Constraints', 'luwipress' ); ?></h2>
				<p class="description" style="margin-bottom:12px;">
					<?php esc_html_e( 'Override the default product enrichment system prompt and enforce store-specific formatting. Leave the prompt blank to use the built-in template.', 'luwipress' ); ?>
				</p>
				<table class="form-table">
					<tr>
						<th><label for="luwipress_enrich_system_prompt"><?php esc_html_e( 'Custom System Prompt', 'luwipress' ); ?></label></th>
						<td>
							<textarea id="luwipress_enrich_system_prompt" name="luwipress_enrich_system_prompt"
								rows="10" class="large-text code"
								placeholder="<?php esc_attr_e( "You are writing SEO-optimized product descriptions for {site_name}. Structure: opening paragraph, material section, spec table, audience section, FAQ. Use <strong> for proper nouns and materials. Target 700-800 words.", 'luwipress' ); ?>"><?php echo esc_textarea( $enrich_prompt ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'Available variables:', 'luwipress' ); ?>
								<code>{product_title}</code>
								<code>{category}</code>
								<code>{focus_keyword}</code>
								<code>{price}</code>
								<code>{currency}</code>
								<code>{site_name}</code>
								<code>{target_language}</code>
								<br>
								<?php esc_html_e( 'Max 4000 characters. When set, replaces the default system prompt for /product/enrich.', 'luwipress' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th><label for="luwipress_enrich_target_words"><?php esc_html_e( 'Target Word Count', 'luwipress' ); ?></label></th>
						<td>
							<input type="number" id="luwipress_enrich_target_words" name="luwipress_enrich_target_words"
							       value="<?php echo esc_attr( $enrich_target_words ); ?>" min="0" max="3000" class="small-text" />
							<p class="description"><?php esc_html_e( 'Description length hint sent to the model. 0 = no constraint (uses built-in minimum).', 'luwipress' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="luwipress_enrich_meta_title_max"><?php esc_html_e( 'Meta Title Max Chars', 'luwipress' ); ?></label></th>
						<td>
							<input type="number" id="luwipress_enrich_meta_title_max" name="luwipress_enrich_meta_title_max"
							       value="<?php echo esc_attr( $enrich_meta_title_max ); ?>" min="40" max="80" class="small-text" />
							<span class="description"><?php esc_html_e( 'Trimmed on save if the model exceeds this. Default 60.', 'luwipress' ); ?></span>
						</td>
					</tr>
					<tr>
						<th><label for="luwipress_enrich_meta_desc_max"><?php esc_html_e( 'Meta Description Max Chars', 'luwipress' ); ?></label></th>
						<td>
							<input type="number" id="luwipress_enrich_meta_desc_max" name="luwipress_enrich_meta_desc_max"
							       value="<?php echo esc_attr( $enrich_meta_desc_max ); ?>" min="120" max="200" class="small-text" />
							<span class="description"><?php esc_html_e( 'Trimmed on save if the model exceeds this. Default 160.', 'luwipress' ); ?></span>
						</td>
					</tr>
					<tr>
						<th><label for="luwipress_enrich_meta_desc_cta"><?php esc_html_e( 'Meta Description CTA', 'luwipress' ); ?></label></th>
						<td>
							<input type="text" id="luwipress_enrich_meta_desc_cta" name="luwipress_enrich_meta_desc_cta"
							       value="<?php echo esc_attr( $enrich_meta_desc_cta ); ?>" class="regular-text"
							       placeholder="<?php esc_attr_e( 'e.g. Free EU shipping & 15-day return.', 'luwipress' ); ?>" />
							<p class="description"><?php esc_html_e( 'Appended to every AI-generated meta description (if there is room within the max char limit).', 'luwipress' ); ?></p>
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
			<div class="luwipress-info-box">
				<span class="dashicons dashicons-info-outline"></span>
				<?php esc_html_e( 'Customer segments are computed from your WooCommerce order history. Configure segment thresholds below; segments refresh weekly via WP-Cron.', 'luwipress' ); ?>
			</div>

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
				<p class="description" style="margin-bottom:12px;">
					<?php esc_html_e( 'LuwiPress generates lifecycle events automatically:', 'luwipress' ); ?>
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

			<div class="luwipress-info-box" style="border-left:3px solid var(--lp-warning,#f59e0b);">
				<span class="dashicons dashicons-info-outline"></span>
				<strong><?php esc_html_e( 'Planned companion split (3.2.0).', 'luwipress' ); ?></strong>
				<?php esc_html_e( 'Marketplace integration will move to a separate "LuwiPress Marketplace Sync" plugin in a future release. Your credentials below will be preserved automatically when the split ships.', 'luwipress' ); ?>
			</div>

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
