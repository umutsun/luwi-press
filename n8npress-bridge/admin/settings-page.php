<?php
/**
 * n8nPress Settings Page
 *
 * Centralized settings — Connection, AI Content, Translation, Security.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'n8npress' ) );
}

// Handle settings save
if ( isset( $_POST['n8npress_save_settings'] ) && check_admin_referer( 'n8npress_settings_nonce' ) ) {
	// Processing Mode
	$mode = sanitize_text_field( $_POST['n8npress_processing_mode'] ?? 'local' );
	if ( in_array( $mode, array( 'local', 'n8n' ), true ) ) {
		update_option( 'n8npress_processing_mode', $mode );
	}
	update_option( 'n8npress_default_provider', sanitize_text_field( $_POST['n8npress_default_provider'] ?? 'anthropic' ) );

	// Connection (n8n webhook)
	update_option( 'n8npress_seo_webhook_url', sanitize_url( $_POST['n8npress_webhook_url'] ?? '' ) );
	update_option( 'n8npress_seo_api_token', sanitize_text_field( $_POST['n8npress_api_token'] ?? '' ) );

	// Provider model selections
	update_option( 'n8npress_anthropic_model', sanitize_text_field( $_POST['n8npress_anthropic_model'] ?? 'claude-haiku-4-5-20241022' ) );
	update_option( 'n8npress_openai_model', sanitize_text_field( $_POST['n8npress_openai_model'] ?? 'gpt-4o-mini' ) );
	update_option( 'n8npress_google_model', sanitize_text_field( $_POST['n8npress_google_model'] ?? 'gemini-2.0-flash' ) );

	// General
	update_option( 'n8npress_enable_logging', isset( $_POST['n8npress_enable_logging'] ) ? 1 : 0 );
	update_option( 'n8npress_log_level', sanitize_text_field( $_POST['n8npress_log_level'] ?? 'info' ) );
	update_option( 'n8npress_rate_limit', absint( $_POST['n8npress_rate_limit'] ?? 1000 ) );
	update_option( 'n8npress_webhook_timeout', absint( $_POST['n8npress_webhook_timeout'] ?? 30 ) );

	// AI Content
	update_option( 'n8npress_auto_enrich', isset( $_POST['n8npress_auto_enrich'] ) ? 1 : 0 );
	update_option( 'n8npress_target_language', sanitize_text_field( $_POST['n8npress_target_language'] ?? 'tr' ) );

	// Thin content auto-enrichment
	$thin_was_enabled = get_option( 'n8npress_auto_enrich_thin', false );
	$thin_now_enabled = isset( $_POST['n8npress_auto_enrich_thin'] ) ? 1 : 0;
	update_option( 'n8npress_auto_enrich_thin', $thin_now_enabled );
	update_option( 'n8npress_thin_content_threshold', absint( $_POST['n8npress_thin_content_threshold'] ?? 300 ) );
	update_option( 'n8npress_auto_enrich_batch_size', absint( $_POST['n8npress_auto_enrich_batch_size'] ?? 10 ) );

	// Schedule or unschedule thin content cron
	if ( $thin_now_enabled && ! $thin_was_enabled ) {
		if ( ! wp_next_scheduled( 'n8npress_auto_enrich_thin_cron' ) ) {
			wp_schedule_event( time(), 'daily', 'n8npress_auto_enrich_thin_cron' );
		}
	} elseif ( ! $thin_now_enabled && $thin_was_enabled ) {
		wp_clear_scheduled_hook( 'n8npress_auto_enrich_thin_cron' );
	}

	// AI API Keys
	update_option( 'n8npress_openai_api_key', sanitize_text_field( $_POST['n8npress_openai_api_key'] ?? '' ) );
	update_option( 'n8npress_anthropic_api_key', sanitize_text_field( $_POST['n8npress_anthropic_api_key'] ?? '' ) );
	update_option( 'n8npress_google_ai_api_key', sanitize_text_field( $_POST['n8npress_google_ai_api_key'] ?? '' ) );
	update_option( 'n8npress_ai_provider', sanitize_text_field( $_POST['n8npress_ai_provider'] ?? 'openai' ) );
	update_option( 'n8npress_ai_model', sanitize_text_field( $_POST['n8npress_ai_model'] ?? 'gpt-4o-mini' ) );
	update_option( 'n8npress_daily_token_limit', floatval( $_POST['n8npress_daily_token_limit'] ?? 1.00 ) );
	update_option( 'n8npress_max_output_tokens', absint( $_POST['n8npress_max_output_tokens'] ?? 1024 ) );

	// Image generation
	update_option( 'n8npress_image_provider', sanitize_text_field( $_POST['n8npress_image_provider'] ?? 'dall-e-3' ) );
	update_option( 'n8npress_enrich_generate_image', isset( $_POST['n8npress_enrich_generate_image'] ) ? 1 : 0 );

	// Translation
	update_option( 'n8npress_hreflang_mode', sanitize_text_field( $_POST['n8npress_hreflang_mode'] ?? 'auto' ) );
	$languages  = sanitize_text_field( $_POST['n8npress_translation_languages_text'] ?? '' );
	$lang_array = array_filter( array_map( 'trim', explode( ',', $languages ) ) );
	update_option( 'n8npress_translation_languages', $lang_array );

	// CRM Bridge thresholds
	update_option( 'n8npress_crm_vip_threshold', floatval( $_POST['n8npress_crm_vip_threshold'] ?? 1000 ) );
	update_option( 'n8npress_crm_active_days', absint( $_POST['n8npress_crm_active_days'] ?? 90 ) );
	update_option( 'n8npress_crm_at_risk_days', absint( $_POST['n8npress_crm_at_risk_days'] ?? 180 ) );
	update_option( 'n8npress_crm_loyal_orders', absint( $_POST['n8npress_crm_loyal_orders'] ?? 3 ) );

	// Open Claw
	update_option( 'n8npress_openclaw_url', esc_url_raw( $_POST['n8npress_openclaw_url'] ?? '' ) );

	// Open Claw channels
	update_option( 'n8npress_telegram_bot_token', sanitize_text_field( $_POST['n8npress_telegram_bot_token'] ?? '' ) );
	update_option( 'n8npress_telegram_admin_ids', sanitize_text_field( $_POST['n8npress_telegram_admin_ids'] ?? '' ) );
	update_option( 'n8npress_whatsapp_number', sanitize_text_field( $_POST['n8npress_whatsapp_number'] ?? '' ) );
	update_option( 'n8npress_whatsapp_admin_ids', sanitize_text_field( $_POST['n8npress_whatsapp_admin_ids'] ?? '' ) );

	// Security
	update_option( 'n8npress_security_headers', isset( $_POST['n8npress_security_headers'] ) ? 1 : 0 );
	update_option( 'n8npress_ip_whitelist', sanitize_text_field( $_POST['n8npress_ip_whitelist'] ?? '' ) );
	if ( ! empty( $_POST['n8npress_regenerate_hmac'] ) ) {
		N8nPress_HMAC::ensure_secret( true );
	}

	echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'n8npress' ) . '</p></div>';
}

// Load current values
$webhook_url        = get_option( 'n8npress_seo_webhook_url', '' );
$api_token          = get_option( 'n8npress_seo_api_token', '' );
$enable_logging     = get_option( 'n8npress_enable_logging', 1 );
$log_level          = get_option( 'n8npress_log_level', 'info' );
$rate_limit         = get_option( 'n8npress_rate_limit', 1000 );
$webhook_timeout    = get_option( 'n8npress_webhook_timeout', 30 );
$auto_enrich        = get_option( 'n8npress_auto_enrich', 0 );
$target_language    = get_option( 'n8npress_target_language', 'tr' );
$auto_thin          = get_option( 'n8npress_auto_enrich_thin', 0 );
$thin_threshold     = get_option( 'n8npress_thin_content_threshold', 300 );
$thin_batch_size    = get_option( 'n8npress_auto_enrich_batch_size', 10 );
$translation_langs  = get_option( 'n8npress_translation_languages', array() );
$hreflang_mode      = get_option( 'n8npress_hreflang_mode', 'auto' );
$openai_key         = get_option( 'n8npress_openai_api_key', '' );
$anthropic_key      = get_option( 'n8npress_anthropic_api_key', '' );
$google_ai_key      = get_option( 'n8npress_google_ai_api_key', '' );
$ai_provider        = get_option( 'n8npress_ai_provider', 'openai' );
$ai_model           = get_option( 'n8npress_ai_model', 'gpt-4o-mini' );
$daily_token_limit  = floatval( get_option( 'n8npress_daily_token_limit', 1.00 ) );
$max_output_tokens  = absint( get_option( 'n8npress_max_output_tokens', 1024 ) );
$image_provider     = get_option( 'n8npress_image_provider', 'dall-e-3' );
$enrich_gen_image   = get_option( 'n8npress_enrich_generate_image', 0 );
$crm_vip_threshold  = get_option( 'n8npress_crm_vip_threshold', 1000 );
$crm_active_days    = get_option( 'n8npress_crm_active_days', 90 );
$crm_at_risk_days   = get_option( 'n8npress_crm_at_risk_days', 180 );
$crm_loyal_orders   = get_option( 'n8npress_crm_loyal_orders', 3 );
$openclaw_url       = get_option( 'n8npress_openclaw_url', '' );
$tg_bot_token       = get_option( 'n8npress_telegram_bot_token', '' );
$tg_admin_ids       = get_option( 'n8npress_telegram_admin_ids', '' );
$wa_number          = get_option( 'n8npress_whatsapp_number', '' );
$wa_admin_ids       = get_option( 'n8npress_whatsapp_admin_ids', '' );
$security_headers   = get_option( 'n8npress_security_headers', 1 );
$ip_whitelist       = get_option( 'n8npress_ip_whitelist', '' );
$hmac_secret        = get_option( 'n8npress_hmac_secret', '' );

$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'connection';

// Detected plugins for info boxes
$detector    = N8nPress_Plugin_Detector::get_instance();
$env         = $detector->get_environment();
$seo_plugin  = $env['seo']['plugin'] ?? 'none';
$trans_plugin = $env['translation']['plugin'] ?? 'none';
$email_plugin = $env['email']['plugin'] ?? 'wp_mail';
?>

<div class="wrap n8npress-settings">
	<h1><span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e( 'n8nPress Settings', 'n8npress' ); ?></h1>

	<nav class="nav-tab-wrapper n8npress-tabs">
		<a href="?page=n8npress-settings&tab=connection" class="nav-tab <?php echo 'connection' === $active_tab ? 'nav-tab-active' : ''; ?>">
			<span class="dashicons dashicons-admin-links"></span> <?php esc_html_e( 'Connection', 'n8npress' ); ?>
		</a>
		<a href="?page=n8npress-settings&tab=general" class="nav-tab <?php echo 'general' === $active_tab ? 'nav-tab-active' : ''; ?>">
			<span class="dashicons dashicons-admin-settings"></span> <?php esc_html_e( 'General', 'n8npress' ); ?>
		</a>
		<a href="?page=n8npress-settings&tab=api-keys" class="nav-tab <?php echo 'api-keys' === $active_tab ? 'nav-tab-active' : ''; ?>">
			<span class="dashicons dashicons-admin-network"></span> <?php esc_html_e( 'AI API Keys', 'n8npress' ); ?>
		</a>
		<a href="?page=n8npress-settings&tab=ai" class="nav-tab <?php echo 'ai' === $active_tab ? 'nav-tab-active' : ''; ?>">
			<span class="dashicons dashicons-edit-large"></span> <?php esc_html_e( 'AI Content', 'n8npress' ); ?>
		</a>
		<a href="?page=n8npress-settings&tab=translation" class="nav-tab <?php echo 'translation' === $active_tab ? 'nav-tab-active' : ''; ?>">
			<span class="dashicons dashicons-translation"></span> <?php esc_html_e( 'Translation', 'n8npress' ); ?>
		</a>
		<a href="?page=n8npress-settings&tab=crm" class="nav-tab <?php echo 'crm' === $active_tab ? 'nav-tab-active' : ''; ?>">
			<span class="dashicons dashicons-groups"></span> <?php esc_html_e( 'CRM', 'n8npress' ); ?>
		</a>
		<a href="?page=n8npress-settings&tab=open-claw" class="nav-tab <?php echo 'open-claw' === $active_tab ? 'nav-tab-active' : ''; ?>">
			<span class="dashicons dashicons-superhero-alt"></span> <?php esc_html_e( 'Open Claw', 'n8npress' ); ?>
		</a>
		<a href="?page=n8npress-settings&tab=security" class="nav-tab <?php echo 'security' === $active_tab ? 'nav-tab-active' : ''; ?>">
			<span class="dashicons dashicons-shield-alt"></span> <?php esc_html_e( 'Security', 'n8npress' ); ?>
		</a>
	</nav>

	<form method="post" class="n8npress-settings-form">
		<?php wp_nonce_field( 'n8npress_settings_nonce' ); ?>

		<!-- CONNECTION -->
		<div class="n8npress-tab-content <?php echo 'connection' === $active_tab ? 'tab-active' : ''; ?>" id="tab-connection">

			<?php
			$processing_mode  = get_option( 'n8npress_processing_mode', 'local' );
			$default_provider = get_option( 'n8npress_default_provider', 'anthropic' );
			$anthropic_key    = get_option( 'n8npress_anthropic_api_key', '' );
			$openai_key       = get_option( 'n8npress_openai_api_key', '' );
			$google_key       = get_option( 'n8npress_google_ai_api_key', '' );
			$anthropic_model  = get_option( 'n8npress_anthropic_model', 'claude-haiku-4-5-20241022' );
			$openai_model_sel = get_option( 'n8npress_openai_model', 'gpt-4o-mini' );
			$google_model     = get_option( 'n8npress_google_model', 'gemini-2.0-flash' );
			?>

			<!-- Processing Mode -->
			<div class="n8npress-card" style="border-left:4px solid #0073aa;">
				<h2><?php esc_html_e( 'Processing Mode', 'n8npress' ); ?></h2>
				<p class="description" style="margin-bottom:12px;"><?php esc_html_e( 'Choose how n8nPress processes AI tasks. Local AI calls your AI provider directly from WordPress. n8n Webhook sends tasks to your n8n instance for processing.', 'n8npress' ); ?></p>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Mode', 'n8npress' ); ?></th>
						<td>
							<fieldset>
								<label style="display:block;margin-bottom:8px;">
									<input type="radio" name="n8npress_processing_mode" value="local" <?php checked( $processing_mode, 'local' ); ?> />
									<strong><?php esc_html_e( 'Local AI', 'n8npress' ); ?></strong> — <?php esc_html_e( 'Self-contained. Calls AI APIs (Claude, OpenAI, Gemini) directly from WordPress. No external server needed.', 'n8npress' ); ?>
								</label>
								<label style="display:block;">
									<input type="radio" name="n8npress_processing_mode" value="n8n" <?php checked( $processing_mode, 'n8n' ); ?> />
									<strong><?php esc_html_e( 'n8n Webhook', 'n8npress' ); ?></strong> — <?php esc_html_e( 'Advanced. Sends tasks to your n8n instance for AI processing. Requires n8n setup.', 'n8npress' ); ?>
								</label>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th><label for="n8npress_default_provider"><?php esc_html_e( 'Default AI Provider', 'n8npress' ); ?></label></th>
						<td>
							<select name="n8npress_default_provider" id="n8npress_default_provider">
								<option value="anthropic" <?php selected( $default_provider, 'anthropic' ); ?>>Anthropic (Claude)</option>
								<option value="openai" <?php selected( $default_provider, 'openai' ); ?>>OpenAI (GPT)</option>
								<option value="google" <?php selected( $default_provider, 'google' ); ?>>Google (Gemini)</option>
							</select>
							<p class="description"><?php esc_html_e( 'Used for Local AI mode. Each workflow can also use a specific provider.', 'n8npress' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<!-- AI Provider API Keys -->
			<div class="n8npress-card">
				<h2><?php esc_html_e( 'AI Provider API Keys', 'n8npress' ); ?></h2>
				<p class="description" style="margin-bottom:12px;"><?php esc_html_e( 'Enter API keys for the AI providers you want to use. At least one key is required for Local AI mode.', 'n8npress' ); ?></p>
				<table class="form-table">
					<tr>
						<th><label for="n8npress_anthropic_api_key"><?php esc_html_e( 'Anthropic API Key', 'n8npress' ); ?></label></th>
						<td>
							<input type="password" id="n8npress_anthropic_api_key" name="n8npress_anthropic_api_key"
							       value="<?php echo esc_attr( $anthropic_key ); ?>" class="regular-text" autocomplete="off"
							       placeholder="sk-ant-..." />
							<button type="button" class="button button-small n8npress-toggle-password" data-target="n8npress_anthropic_api_key">
								<span class="dashicons dashicons-visibility"></span>
							</button>
							<?php if ( ! empty( $anthropic_key ) ) : ?>
								<span class="dashicons dashicons-yes-alt" style="color:#46b450;margin-left:4px;line-height:30px;" title="<?php esc_attr_e( 'Configured', 'n8npress' ); ?>"></span>
							<?php endif; ?>
							<div style="margin-top:6px;">
								<select name="n8npress_anthropic_model" style="width:300px;">
									<option value="claude-haiku-4-5-20241022" <?php selected( $anthropic_model, 'claude-haiku-4-5-20241022' ); ?>>Claude Haiku 4.5 (fast, cheap)</option>
									<option value="claude-sonnet-4-20250514" <?php selected( $anthropic_model, 'claude-sonnet-4-20250514' ); ?>>Claude Sonnet 4 (balanced)</option>
									<option value="claude-opus-4-20250514" <?php selected( $anthropic_model, 'claude-opus-4-20250514' ); ?>>Claude Opus 4 (powerful)</option>
								</select>
							</div>
						</td>
					</tr>
					<tr>
						<th><label for="n8npress_openai_api_key"><?php esc_html_e( 'OpenAI API Key', 'n8npress' ); ?></label></th>
						<td>
							<input type="password" id="n8npress_openai_api_key" name="n8npress_openai_api_key"
							       value="<?php echo esc_attr( $openai_key ); ?>" class="regular-text" autocomplete="off"
							       placeholder="sk-..." />
							<button type="button" class="button button-small n8npress-toggle-password" data-target="n8npress_openai_api_key">
								<span class="dashicons dashicons-visibility"></span>
							</button>
							<?php if ( ! empty( $openai_key ) ) : ?>
								<span class="dashicons dashicons-yes-alt" style="color:#46b450;margin-left:4px;line-height:30px;" title="<?php esc_attr_e( 'Configured', 'n8npress' ); ?>"></span>
							<?php endif; ?>
							<div style="margin-top:6px;">
								<select name="n8npress_openai_model" style="width:300px;">
									<option value="gpt-4o-mini" <?php selected( $openai_model_sel, 'gpt-4o-mini' ); ?>>GPT-4o Mini (fast, cheap)</option>
									<option value="gpt-4o" <?php selected( $openai_model_sel, 'gpt-4o' ); ?>>GPT-4o (balanced)</option>
									<option value="gpt-4-turbo" <?php selected( $openai_model_sel, 'gpt-4-turbo' ); ?>>GPT-4 Turbo (powerful)</option>
								</select>
							</div>
						</td>
					</tr>
					<tr>
						<th><label for="n8npress_google_ai_api_key"><?php esc_html_e( 'Google AI API Key', 'n8npress' ); ?></label></th>
						<td>
							<input type="password" id="n8npress_google_ai_api_key" name="n8npress_google_ai_api_key"
							       value="<?php echo esc_attr( $google_key ); ?>" class="regular-text" autocomplete="off"
							       placeholder="AIza..." />
							<button type="button" class="button button-small n8npress-toggle-password" data-target="n8npress_google_ai_api_key">
								<span class="dashicons dashicons-visibility"></span>
							</button>
							<?php if ( ! empty( $google_key ) ) : ?>
								<span class="dashicons dashicons-yes-alt" style="color:#46b450;margin-left:4px;line-height:30px;" title="<?php esc_attr_e( 'Configured', 'n8npress' ); ?>"></span>
							<?php endif; ?>
							<div style="margin-top:6px;">
								<select name="n8npress_google_model" style="width:300px;">
									<option value="gemini-2.0-flash" <?php selected( $google_model, 'gemini-2.0-flash' ); ?>>Gemini 2.0 Flash (fast, cheap)</option>
									<option value="gemini-2.5-flash" <?php selected( $google_model, 'gemini-2.5-flash' ); ?>>Gemini 2.5 Flash (balanced)</option>
									<option value="gemini-2.5-pro" <?php selected( $google_model, 'gemini-2.5-pro' ); ?>>Gemini 2.5 Pro (powerful)</option>
								</select>
							</div>
						</td>
					</tr>
				</table>
			</div>

			<!-- n8n Connection (for n8n mode) -->
			<div class="n8npress-card">
				<h2><?php esc_html_e( 'n8n Connection', 'n8npress' ); ?></h2>
				<p class="description" style="margin-bottom:8px;"><?php esc_html_e( 'Only required when using n8n Webhook mode. n8nPress sends your site URL, API token, and AI settings in every webhook payload.', 'n8npress' ); ?></p>
				<table class="form-table">
					<tr>
						<th><label for="n8npress_webhook_url"><?php esc_html_e( 'n8n Webhook URL', 'n8npress' ); ?></label></th>
						<td>
							<input type="url" id="n8npress_webhook_url" name="n8npress_webhook_url"
							       value="<?php echo esc_attr( $webhook_url ); ?>" class="regular-text"
							       placeholder="https://n8n.example.com/webhook/..." />
							<p class="description"><?php esc_html_e( 'The webhook URL from your n8n workflow.', 'n8npress' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="n8npress_api_token"><?php esc_html_e( 'API Bearer Token', 'n8npress' ); ?></label></th>
						<td>
							<input type="password" id="n8npress_api_token" name="n8npress_api_token"
							       value="<?php echo esc_attr( $api_token ); ?>" class="regular-text" autocomplete="off" />
							<button type="button" class="button button-small n8npress-toggle-password" data-target="n8npress_api_token">
								<span class="dashicons dashicons-visibility"></span>
							</button>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Connection Test', 'n8npress' ); ?></th>
						<td>
							<button type="button" class="button" id="n8npress-test-connection">
								<span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Test Connection', 'n8npress' ); ?>
							</button>
							<span id="n8npress-connection-result"></span>
						</td>
					</tr>
				</table>
			</div>

			<div class="n8npress-card">
				<h2><?php esc_html_e( 'REST API Endpoints', 'n8npress' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Base URL', 'n8npress' ); ?></th>
						<td><code><?php echo esc_html( get_rest_url( null, 'n8npress/v1/' ) ); ?></code></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Site Config', 'n8npress' ); ?></th>
						<td>
							<code><?php echo esc_html( get_rest_url( null, 'n8npress/v1/site-config' ) ); ?></code>
							<p class="description"><?php esc_html_e( 'n8n workflows call this first to get all WP/WC/plugin settings.', 'n8npress' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Email Proxy', 'n8npress' ); ?></th>
						<td>
							<code><?php echo esc_html( get_rest_url( null, 'n8npress/v1/send-email' ) ); ?></code>
							<p class="description">
								<?php printf(
									esc_html__( 'Sends via wp_mail() using %s.', 'n8npress' ),
									'<strong>' . esc_html( ucwords( str_replace( '-', ' ', $email_plugin ) ) ) . '</strong>'
								); ?>
							</p>
						</td>
					</tr>
				</table>
			</div>
		</div>

		<!-- GENERAL -->
		<div class="n8npress-tab-content <?php echo 'general' === $active_tab ? 'tab-active' : ''; ?>" id="tab-general">
			<div class="n8npress-card">
				<h2><?php esc_html_e( 'Logging', 'n8npress' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="n8npress_enable_logging"><?php esc_html_e( 'Enable Logging', 'n8npress' ); ?></label></th>
						<td>
							<label>
								<input type="checkbox" id="n8npress_enable_logging" name="n8npress_enable_logging" value="1" <?php checked( $enable_logging, 1 ); ?> />
								<?php esc_html_e( 'Record webhook and workflow events', 'n8npress' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><label for="n8npress_log_level"><?php esc_html_e( 'Log Level', 'n8npress' ); ?></label></th>
						<td>
							<select id="n8npress_log_level" name="n8npress_log_level">
								<option value="debug" <?php selected( $log_level, 'debug' ); ?>><?php esc_html_e( 'Debug (all)', 'n8npress' ); ?></option>
								<option value="info" <?php selected( $log_level, 'info' ); ?>><?php esc_html_e( 'Info', 'n8npress' ); ?></option>
								<option value="warning" <?php selected( $log_level, 'warning' ); ?>><?php esc_html_e( 'Warning', 'n8npress' ); ?></option>
								<option value="error" <?php selected( $log_level, 'error' ); ?>><?php esc_html_e( 'Error only', 'n8npress' ); ?></option>
							</select>
						</td>
					</tr>
				</table>
			</div>
			<div class="n8npress-card">
				<h2><?php esc_html_e( 'Performance', 'n8npress' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="n8npress_rate_limit"><?php esc_html_e( 'Rate Limit', 'n8npress' ); ?></label></th>
						<td>
							<input type="number" id="n8npress_rate_limit" name="n8npress_rate_limit"
							       value="<?php echo esc_attr( $rate_limit ); ?>" min="10" max="10000" class="small-text" />
							<span class="description"><?php esc_html_e( 'requests/hour/IP', 'n8npress' ); ?></span>
						</td>
					</tr>
					<tr>
						<th><label for="n8npress_webhook_timeout"><?php esc_html_e( 'Webhook Timeout', 'n8npress' ); ?></label></th>
						<td>
							<input type="number" id="n8npress_webhook_timeout" name="n8npress_webhook_timeout"
							       value="<?php echo esc_attr( $webhook_timeout ); ?>" min="5" max="120" class="small-text" />
							<span class="description"><?php esc_html_e( 'seconds', 'n8npress' ); ?></span>
						</td>
					</tr>
				</table>
			</div>
		</div>

		<!-- AI API KEYS -->
		<div class="n8npress-tab-content <?php echo 'api-keys' === $active_tab ? 'tab-active' : ''; ?>" id="tab-api-keys">
			<div class="n8npress-info-box">
				<span class="dashicons dashicons-info" style="color:#6366f1"></span>
				<?php esc_html_e( 'n8n workflows use these API keys for AI content generation, translation, and review responses. Select your preferred provider and enter its key.', 'n8npress' ); ?>
			</div>

			<div class="n8npress-card">
				<h2><?php esc_html_e( 'AI Provider', 'n8npress' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Primary Provider', 'n8npress' ); ?></th>
						<td>
							<fieldset class="n8npress-provider-select">
								<label class="n8npress-provider-option <?php echo 'anthropic' === $ai_provider ? 'provider-selected' : ''; ?>">
									<input type="radio" name="n8npress_ai_provider" value="anthropic" <?php checked( $ai_provider, 'anthropic' ); ?> />
									<span class="provider-card">
										<strong>Claude (Anthropic)</strong>
										<span class="provider-desc"><?php esc_html_e( 'Best for nuanced product descriptions and multilingual content', 'n8npress' ); ?></span>
									</span>
								</label>
								<label class="n8npress-provider-option <?php echo 'openai' === $ai_provider ? 'provider-selected' : ''; ?>">
									<input type="radio" name="n8npress_ai_provider" value="openai" <?php checked( $ai_provider, 'openai' ); ?> />
									<span class="provider-card">
										<strong>OpenAI (GPT)</strong>
										<span class="provider-desc"><?php esc_html_e( 'GPT-4o for content generation, DALL-E for images', 'n8npress' ); ?></span>
									</span>
								</label>
								<label class="n8npress-provider-option <?php echo 'google' === $ai_provider ? 'provider-selected' : ''; ?>">
									<input type="radio" name="n8npress_ai_provider" value="google" <?php checked( $ai_provider, 'google' ); ?> />
									<span class="provider-card">
										<strong>Google Gemini</strong>
										<span class="provider-desc"><?php esc_html_e( 'Gemini Pro for cost-effective content at scale', 'n8npress' ); ?></span>
									</span>
								</label>
							</fieldset>
						</td>
					</tr>
				</table>
			</div>

			<div class="n8npress-card">
				<h2><?php esc_html_e( 'API Keys', 'n8npress' ); ?></h2>
				<p class="description" style="margin-bottom:16px;">
					<?php esc_html_e( 'Enter the API key for your selected provider. n8n workflows will read the active key from the /site-config endpoint.', 'n8npress' ); ?>
				</p>
				<table class="form-table">
					<tr>
						<th>
							<label for="n8npress_anthropic_api_key">
								<?php esc_html_e( 'Anthropic API Key', 'n8npress' ); ?>
								<?php if ( 'anthropic' === $ai_provider ) : ?>
									<span class="badge-active" style="margin-left:6px;"><?php esc_html_e( 'Active', 'n8npress' ); ?></span>
								<?php endif; ?>
							</label>
						</th>
						<td>
							<input type="password" id="n8npress_anthropic_api_key" name="n8npress_anthropic_api_key"
							       value="<?php echo esc_attr( $anthropic_key ); ?>" class="regular-text" autocomplete="off"
							       placeholder="sk-ant-..." />
							<button type="button" class="button button-small n8npress-toggle-password" data-target="n8npress_anthropic_api_key">
								<span class="dashicons dashicons-visibility"></span>
							</button>
							<?php if ( ! empty( $anthropic_key ) ) : ?>
								<span style="color:#16a34a;margin-left:8px;"><span class="dashicons dashicons-yes-alt"></span></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th>
							<label for="n8npress_openai_api_key">
								<?php esc_html_e( 'OpenAI API Key', 'n8npress' ); ?>
								<?php if ( 'openai' === $ai_provider ) : ?>
									<span class="badge-active" style="margin-left:6px;"><?php esc_html_e( 'Active', 'n8npress' ); ?></span>
								<?php endif; ?>
							</label>
						</th>
						<td>
							<input type="password" id="n8npress_openai_api_key" name="n8npress_openai_api_key"
							       value="<?php echo esc_attr( $openai_key ); ?>" class="regular-text" autocomplete="off"
							       placeholder="sk-..." />
							<button type="button" class="button button-small n8npress-toggle-password" data-target="n8npress_openai_api_key">
								<span class="dashicons dashicons-visibility"></span>
							</button>
							<?php if ( ! empty( $openai_key ) ) : ?>
								<span style="color:#16a34a;margin-left:8px;"><span class="dashicons dashicons-yes-alt"></span></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th>
							<label for="n8npress_google_ai_api_key">
								<?php esc_html_e( 'Google AI API Key', 'n8npress' ); ?>
								<?php if ( 'google' === $ai_provider ) : ?>
									<span class="badge-active" style="margin-left:6px;"><?php esc_html_e( 'Active', 'n8npress' ); ?></span>
								<?php endif; ?>
							</label>
						</th>
						<td>
							<input type="password" id="n8npress_google_ai_api_key" name="n8npress_google_ai_api_key"
							       value="<?php echo esc_attr( $google_ai_key ); ?>" class="regular-text" autocomplete="off"
							       placeholder="AIza..." />
							<button type="button" class="button button-small n8npress-toggle-password" data-target="n8npress_google_ai_api_key">
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
			<div class="n8npress-card">
				<h2><span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e( 'AI Model & Cost Control', 'n8npress' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="n8npress_ai_model"><?php esc_html_e( 'AI Model', 'n8npress' ); ?></label></th>
						<td>
							<select name="n8npress_ai_model" id="n8npress_ai_model">
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
							<p class="description"><?php esc_html_e( 'Selected model is sent to n8n workflows via the enrichment payload.', 'n8npress' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="n8npress_daily_token_limit"><?php esc_html_e( 'Daily Budget Limit ($)', 'n8npress' ); ?></label></th>
						<td>
							<input type="number" name="n8npress_daily_token_limit" id="n8npress_daily_token_limit"
							       value="<?php echo esc_attr( $daily_token_limit ); ?>"
							       min="0" max="100" step="0.10" style="width:100px;" />
							<span class="description"><?php esc_html_e( 'AI features auto-pause when reached. 0 = unlimited.', 'n8npress' ); ?></span>
							<?php
							$today_cost = class_exists( 'N8nPress_Token_Tracker' ) ? N8nPress_Token_Tracker::get_today_cost() : 0;
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
						<th><label for="n8npress_max_output_tokens"><?php esc_html_e( 'Max Output Tokens', 'n8npress' ); ?></label></th>
						<td>
							<input type="number" name="n8npress_max_output_tokens" id="n8npress_max_output_tokens"
							       value="<?php echo esc_attr( $max_output_tokens ); ?>"
							       min="256" max="8000" step="128" style="width:100px;" />
							<span class="description"><?php esc_html_e( 'Max tokens per AI call. Lower = cheaper. Recommended: 1024–2000.', 'n8npress' ); ?></span>
						</td>
					</tr>
				</table>
			</div>

			<div class="n8npress-card" style="background:#f9fafb;">
				<h2><?php esc_html_e( 'Cost Protection', 'n8npress' ); ?></h2>
				<ul class="n8npress-feature-list">
					<li><span class="dashicons dashicons-shield"></span> <?php esc_html_e( 'Daily budget limit auto-pauses AI when reached — no surprise charges', 'n8npress' ); ?></li>
					<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Local commands (/scan, /seo, etc.) always work with zero AI cost', 'n8npress' ); ?></li>
					<li><span class="dashicons dashicons-chart-bar"></span> <?php esc_html_e( 'Token usage tracked per workflow — see exactly what costs money', 'n8npress' ); ?></li>
					<li><span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Switch models anytime — GPT-4o Mini is 20x cheaper than Claude Sonnet', 'n8npress' ); ?></li>
				</ul>
			</div>
		</div>

		<!-- AI CONTENT -->
		<div class="n8npress-tab-content <?php echo 'ai' === $active_tab ? 'tab-active' : ''; ?>" id="tab-ai">
			<?php if ( 'none' !== $seo_plugin ) : ?>
			<div class="n8npress-info-box">
				<span class="dashicons dashicons-yes-alt" style="color:#16a34a"></span>
				<?php printf(
					esc_html__( 'SEO plugin detected: %s — AI-generated meta will be saved to its fields automatically.', 'n8npress' ),
					'<strong>' . esc_html( ucwords( str_replace( '-', ' ', $seo_plugin ) ) ) . '</strong>'
				); ?>
			</div>
			<?php endif; ?>

			<div class="n8npress-card">
				<h2><?php esc_html_e( 'AI Content Pipeline', 'n8npress' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="n8npress_auto_enrich"><?php esc_html_e( 'Auto-Enrich', 'n8npress' ); ?></label></th>
						<td>
							<label>
								<input type="checkbox" id="n8npress_auto_enrich" name="n8npress_auto_enrich" value="1" <?php checked( $auto_enrich, 1 ); ?> />
								<?php esc_html_e( 'Automatically enrich new products with AI-generated content', 'n8npress' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><label for="n8npress_target_language"><?php esc_html_e( 'Primary Language', 'n8npress' ); ?></label></th>
						<td>
							<select id="n8npress_target_language" name="n8npress_target_language">
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
							<p class="description"><?php esc_html_e( 'Language for AI-generated descriptions, meta, and FAQ content.', 'n8npress' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<div class="n8npress-card">
				<h2><?php esc_html_e( 'Thin Content Auto-Enrichment', 'n8npress' ); ?></h2>
				<p class="description" style="margin-bottom:12px;">
					<?php esc_html_e( 'Automatically detect products with thin descriptions and enrich them daily via AI.', 'n8npress' ); ?>
				</p>
				<table class="form-table">
					<tr>
						<th><label for="n8npress_auto_enrich_thin"><?php esc_html_e( 'Enable', 'n8npress' ); ?></label></th>
						<td>
							<label>
								<input type="checkbox" id="n8npress_auto_enrich_thin" name="n8npress_auto_enrich_thin" value="1" <?php checked( $auto_thin, 1 ); ?> />
								<?php esc_html_e( 'Daily scan and auto-enrich products with thin content', 'n8npress' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><label for="n8npress_thin_content_threshold"><?php esc_html_e( 'Thin Threshold', 'n8npress' ); ?></label></th>
						<td>
							<input type="number" id="n8npress_thin_content_threshold" name="n8npress_thin_content_threshold"
							       value="<?php echo esc_attr( $thin_threshold ); ?>" min="50" max="2000" class="small-text" />
							<span class="description"><?php esc_html_e( 'characters — products below this are considered thin', 'n8npress' ); ?></span>
						</td>
					</tr>
					<tr>
						<th><label for="n8npress_auto_enrich_batch_size"><?php esc_html_e( 'Batch Size', 'n8npress' ); ?></label></th>
						<td>
							<input type="number" id="n8npress_auto_enrich_batch_size" name="n8npress_auto_enrich_batch_size"
							       value="<?php echo esc_attr( $thin_batch_size ); ?>" min="1" max="50" class="small-text" />
							<span class="description"><?php esc_html_e( 'products per daily run', 'n8npress' ); ?></span>
						</td>
					</tr>
				</table>

				<h3 style="margin-top:20px;"><?php esc_html_e( 'Image Generation', 'n8npress' ); ?></h3>
				<table class="form-table">
					<tr>
						<th><label for="n8npress_enrich_generate_image"><?php esc_html_e( 'Generate Images', 'n8npress' ); ?></label></th>
						<td>
							<label>
								<input type="checkbox" id="n8npress_enrich_generate_image" name="n8npress_enrich_generate_image" value="1" <?php checked( $enrich_gen_image, 1 ); ?> />
								<?php esc_html_e( 'Generate AI images for products and posts during enrichment', 'n8npress' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><label for="n8npress_image_provider"><?php esc_html_e( 'Image Provider', 'n8npress' ); ?></label></th>
						<td>
							<select id="n8npress_image_provider" name="n8npress_image_provider">
								<option value="dall-e-3" <?php selected( $image_provider, 'dall-e-3' ); ?>>OpenAI DALL-E 3 ($0.040/image)</option>
								<option value="dall-e-2" <?php selected( $image_provider, 'dall-e-2' ); ?>>OpenAI DALL-E 2 ($0.020/image)</option>
								<option value="gemini-imagen" <?php selected( $image_provider, 'gemini-imagen' ); ?>>Google Gemini Imagen 3 ($0.020/image)</option>
							</select>
							<p class="description"><?php esc_html_e( 'DALL-E 3: highest quality. Gemini Imagen 3: fast and cost-effective.', 'n8npress' ); ?></p>
						</td>
					</tr>
				</table>
			</div>
		</div>

		<!-- TRANSLATION -->
		<div class="n8npress-tab-content <?php echo 'translation' === $active_tab ? 'tab-active' : ''; ?>" id="tab-translation">
			<div class="n8npress-info-box">
				<span class="dashicons <?php echo 'none' !== $trans_plugin ? 'dashicons-yes-alt' : 'dashicons-info'; ?>"
				      style="color:<?php echo 'none' !== $trans_plugin ? '#16a34a' : '#3b82f6'; ?>"></span>
				<?php if ( 'none' !== $trans_plugin ) :
					$trans_display = ucwords( str_replace( '-', ' ', $trans_plugin ) );
					$trans_version = $env['translation']['version'] ?? '';
					printf(
						esc_html__( 'Translation plugin: %s — n8nPress will save translations through its API.', 'n8npress' ),
						'<strong>' . esc_html( $trans_display . ( $trans_version ? " v$trans_version" : '' ) ) . '</strong>'
					);
				else :
					esc_html_e( 'No translation plugin detected. Install WPML or Polylang for multi-language support.', 'n8npress' );
				endif; ?>
			</div>

			<?php if ( 'none' !== $trans_plugin && ! empty( $env['translation']['active_languages'] ) ) : ?>
			<div class="n8npress-card">
				<h2><?php esc_html_e( 'Active Languages', 'n8npress' ); ?></h2>
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

			<div class="n8npress-card">
				<h2><?php esc_html_e( 'Translation Pipeline Settings', 'n8npress' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Target Languages', 'n8npress' ); ?></th>
						<td>
							<?php
							$detector = N8nPress_Plugin_Detector::get_instance();
							$t_env    = $detector->detect_translation();
							$t_langs  = array_diff( $t_env['active_languages'] ?? array(), array( $t_env['default_language'] ?? '' ) );
							if ( ! empty( $t_langs ) ) :
								foreach ( $t_langs as $tl ) : ?>
									<code class="lang-tag" style="margin-right:4px;"><?php echo esc_html( strtoupper( $tl ) ); ?></code>
								<?php endforeach; ?>
								<p class="description"><?php printf( esc_html__( 'Auto-detected from %s. Add or remove languages in your translation plugin settings.', 'n8npress' ), esc_html( ucwords( $t_env['plugin'] ) ) ); ?></p>
							<?php else : ?>
								<span style="color:#6b7280;"><?php esc_html_e( 'No translation plugin detected. Install WPML or Polylang to enable multilingual support.', 'n8npress' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<h3><?php esc_html_e( 'hreflang Tags', 'n8npress' ); ?></h3>
				<table class="form-table">
					<tr>
						<th><label for="n8npress_hreflang_mode"><?php esc_html_e( 'Mode', 'n8npress' ); ?></label></th>
						<td>
							<select id="n8npress_hreflang_mode" name="n8npress_hreflang_mode">
								<option value="auto" <?php selected( $hreflang_mode, 'auto' ); ?>><?php esc_html_e( 'Auto — generate only if WPML/Polylang doesn\'t', 'n8npress' ); ?></option>
								<option value="always" <?php selected( $hreflang_mode, 'always' ); ?>><?php esc_html_e( 'Always — force n8nPress hreflang output', 'n8npress' ); ?></option>
								<option value="never" <?php selected( $hreflang_mode, 'never' ); ?>><?php esc_html_e( 'Never — disable hreflang generation', 'n8npress' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'hreflang tags tell search engines which language version to show. Critical for multilingual SEO.', 'n8npress' ); ?></p>
						</td>
					</tr>
				</table>

				<h3><?php esc_html_e( 'What Gets Translated', 'n8npress' ); ?></h3>
				<ul class="n8npress-feature-list">
					<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Product titles and descriptions', 'n8npress' ); ?></li>
					<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'SEO meta titles (<60 chars) and descriptions (<160 chars)', 'n8npress' ); ?></li>
					<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'FAQ and schema content', 'n8npress' ); ?></li>
					<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Blog post content', 'n8npress' ); ?></li>
				</ul>
			</div>
		</div>

		<!-- CRM -->
		<div class="n8npress-tab-content <?php echo 'crm' === $active_tab ? 'tab-active' : ''; ?>" id="tab-crm">
			<?php
			$detector   = N8nPress_Plugin_Detector::get_instance();
			$crm_plugin = $detector->detect_crm();
			?>
			<?php if ( 'none' !== $crm_plugin['plugin'] ) : ?>
			<div class="n8npress-info-box">
				<span class="dashicons dashicons-info-outline"></span>
				<?php printf(
					esc_html__( 'Detected CRM: %s. n8nPress reads its data and adds AI-powered customer intelligence — no duplication.', 'n8npress' ),
					'<strong>' . esc_html( ucwords( str_replace( '-', ' ', $crm_plugin['plugin'] ) ) ) . '</strong>'
				); ?>
			</div>
			<?php else : ?>
			<div class="n8npress-info-box">
				<span class="dashicons dashicons-info-outline"></span>
				<?php esc_html_e( 'No CRM plugin detected. n8nPress will compute customer segments from WooCommerce order data and provide lifecycle automation via n8n.', 'n8npress' ); ?>
			</div>
			<?php endif; ?>

			<div class="n8npress-card">
				<h2><?php esc_html_e( 'Customer Segmentation Thresholds', 'n8npress' ); ?></h2>
				<p class="description" style="margin-bottom:12px;">
					<?php esc_html_e( 'These thresholds control how customers are segmented. Segments are refreshed weekly via WP Cron.', 'n8npress' ); ?>
				</p>
				<table class="form-table">
					<tr>
						<th><label for="n8npress_crm_vip_threshold"><?php esc_html_e( 'VIP Spend Threshold', 'n8npress' ); ?></label></th>
						<td>
							<input type="number" id="n8npress_crm_vip_threshold" name="n8npress_crm_vip_threshold"
							       value="<?php echo esc_attr( $crm_vip_threshold ); ?>" class="small-text" min="0" step="50" />
							<p class="description"><?php esc_html_e( 'Minimum total spend to be considered VIP (combined with loyal order count)', 'n8npress' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="n8npress_crm_loyal_orders"><?php esc_html_e( 'Loyal Order Count', 'n8npress' ); ?></label></th>
						<td>
							<input type="number" id="n8npress_crm_loyal_orders" name="n8npress_crm_loyal_orders"
							       value="<?php echo esc_attr( $crm_loyal_orders ); ?>" class="small-text" min="2" />
							<p class="description"><?php esc_html_e( 'Minimum orders to be considered Loyal', 'n8npress' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="n8npress_crm_active_days"><?php esc_html_e( 'Active Window (days)', 'n8npress' ); ?></label></th>
						<td>
							<input type="number" id="n8npress_crm_active_days" name="n8npress_crm_active_days"
							       value="<?php echo esc_attr( $crm_active_days ); ?>" class="small-text" min="7" />
							<p class="description"><?php esc_html_e( 'Customers with orders within this window are Active', 'n8npress' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="n8npress_crm_at_risk_days"><?php esc_html_e( 'At-Risk Window (days)', 'n8npress' ); ?></label></th>
						<td>
							<input type="number" id="n8npress_crm_at_risk_days" name="n8npress_crm_at_risk_days"
							       value="<?php echo esc_attr( $crm_at_risk_days ); ?>" class="small-text" min="30" />
							<p class="description"><?php esc_html_e( 'No order within this window = At Risk. Beyond this = Dormant.', 'n8npress' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<div class="n8npress-card">
				<h2><?php esc_html_e( 'Lifecycle Automation', 'n8npress' ); ?></h2>
				<?php if ( 'none' !== $crm_plugin['plugin'] ) : ?>
				<p class="description">
					<?php printf(
						esc_html__( '%s handles email automation. n8nPress lifecycle events are disabled to avoid duplication.', 'n8npress' ),
						'<strong>' . esc_html( ucwords( str_replace( '-', ' ', $crm_plugin['plugin'] ) ) ) . '</strong>'
					); ?>
				</p>
				<?php else : ?>
				<p class="description" style="margin-bottom:12px;">
					<?php esc_html_e( 'n8n workflows process lifecycle events. Connect your n8n instance to automate:', 'n8npress' ); ?>
				</p>
				<ul class="n8npress-feature-list">
					<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Post-purchase thank you emails', 'n8npress' ); ?></li>
					<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Review request emails (7 days after order)', 'n8npress' ); ?></li>
					<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Win-back campaigns for at-risk customers', 'n8npress' ); ?></li>
				</ul>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Lifecycle Queue', 'n8npress' ); ?></th>
						<td>
							<code>GET <?php echo esc_html( rest_url( 'n8npress/v1/crm/lifecycle-queue' ) ); ?></code>
							<p class="description"><?php esc_html_e( 'n8n polls this endpoint for pending lifecycle events to process', 'n8npress' ); ?></p>
						</td>
					</tr>
				</table>
				<?php endif; ?>
			</div>
		</div>

		<!-- OPEN CLAW -->
		<div class="n8npress-tab-content <?php echo 'open-claw' === $active_tab ? 'tab-active' : ''; ?>" id="tab-open-claw">
			<div class="n8npress-info-box">
				<span class="dashicons dashicons-info-outline"></span>
				<?php esc_html_e( 'Open Claw is your AI assistant for managing WordPress + WooCommerce. Connect Telegram or WhatsApp so you can manage your store from anywhere.', 'n8npress' ); ?>
			</div>

			<div class="n8npress-card">
				<h2><span class="dashicons dashicons-superhero-alt" style="color:#6366f1;"></span> <?php esc_html_e( 'Open Claw Connection', 'n8npress' ); ?></h2>
				<p class="description" style="margin-bottom:12px;">
					<?php esc_html_e( 'Enter your Open Claw instance URL to enable AI-powered store management. You can get this from your Open Claw provider.', 'n8npress' ); ?>
				</p>
				<table class="form-table">
					<tr>
						<th><label for="n8npress_openclaw_url"><?php esc_html_e( 'Open Claw URL', 'n8npress' ); ?></label></th>
						<td>
							<input type="url" id="n8npress_openclaw_url" name="n8npress_openclaw_url"
							       value="<?php echo esc_attr( $openclaw_url ); ?>" class="regular-text"
							       placeholder="https://your-openclaw-instance.com" />
							<button type="button" class="button" id="n8npress-test-openclaw">
								<span class="dashicons dashicons-update" style="margin-top:4px;"></span>
								<?php esc_html_e( 'Test Connection', 'n8npress' ); ?>
							</button>
							<span id="n8npress-openclaw-status" style="margin-left:8px;"></span>
							<p class="description"><?php esc_html_e( 'Your Open Claw instance URL. Save settings before testing.', 'n8npress' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<div class="n8npress-card">
				<h2><span class="dashicons dashicons-telegram" style="color:#229ED9;"></span> <?php esc_html_e( 'Telegram Bot', 'n8npress' ); ?></h2>
				<p class="description" style="margin-bottom:12px;">
					<?php esc_html_e( 'Create a Telegram bot via @BotFather, paste the token below, and connect it to your n8n Telegram Trigger node.', 'n8npress' ); ?>
				</p>
				<table class="form-table">
					<tr>
						<th><label for="n8npress_telegram_bot_token"><?php esc_html_e( 'Bot Token', 'n8npress' ); ?></label></th>
						<td>
							<input type="password" id="n8npress_telegram_bot_token" name="n8npress_telegram_bot_token"
							       value="<?php echo esc_attr( $tg_bot_token ); ?>" class="regular-text"
							       placeholder="123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11" />
							<button type="button" class="button n8npress-toggle-password" data-target="n8npress_telegram_bot_token">
								<span class="dashicons dashicons-visibility"></span>
							</button>
							<p class="description"><?php esc_html_e( 'Get this from Telegram @BotFather', 'n8npress' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="n8npress_telegram_admin_ids"><?php esc_html_e( 'Authorized User IDs', 'n8npress' ); ?></label></th>
						<td>
							<input type="text" id="n8npress_telegram_admin_ids" name="n8npress_telegram_admin_ids"
							       value="<?php echo esc_attr( $tg_admin_ids ); ?>" class="regular-text"
							       placeholder="123456789, 987654321" />
							<p class="description"><?php esc_html_e( 'Comma-separated Telegram user IDs allowed to use Open Claw. Use @userinfobot to find your ID.', 'n8npress' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<div class="n8npress-card">
				<h2><span class="dashicons dashicons-phone" style="color:#25D366;"></span> <?php esc_html_e( 'WhatsApp', 'n8npress' ); ?></h2>
				<p class="description" style="margin-bottom:12px;">
					<?php esc_html_e( 'Connect WhatsApp Business API through n8n. Messages from authorized numbers will be processed by Open Claw.', 'n8npress' ); ?>
				</p>
				<table class="form-table">
					<tr>
						<th><label for="n8npress_whatsapp_number"><?php esc_html_e( 'Business Number', 'n8npress' ); ?></label></th>
						<td>
							<input type="text" id="n8npress_whatsapp_number" name="n8npress_whatsapp_number"
							       value="<?php echo esc_attr( $wa_number ); ?>" class="regular-text"
							       placeholder="+905551234567" />
							<p class="description"><?php esc_html_e( 'WhatsApp Business phone number (used for reference)', 'n8npress' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="n8npress_whatsapp_admin_ids"><?php esc_html_e( 'Authorized Numbers', 'n8npress' ); ?></label></th>
						<td>
							<input type="text" id="n8npress_whatsapp_admin_ids" name="n8npress_whatsapp_admin_ids"
							       value="<?php echo esc_attr( $wa_admin_ids ); ?>" class="regular-text"
							       placeholder="+905551234567, +905559876543" />
							<p class="description"><?php esc_html_e( 'Comma-separated phone numbers allowed to use Open Claw via WhatsApp.', 'n8npress' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<div class="n8npress-card">
				<h2><?php esc_html_e( 'n8n Workflow Setup', 'n8npress' ); ?></h2>
				<p class="description" style="margin-bottom:12px;">
					<?php esc_html_e( 'Use these endpoints in your n8n Telegram/WhatsApp workflows:', 'n8npress' ); ?>
				</p>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Channel Message', 'n8npress' ); ?></th>
						<td>
							<code>POST <?php echo esc_html( rest_url( 'n8npress/v1/claw/channel-message' ) ); ?></code>
							<p class="description"><?php esc_html_e( 'Send incoming Telegram/WhatsApp messages here. Requires: channel, sender_id, message', 'n8npress' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Channel Execute', 'n8npress' ); ?></th>
						<td>
							<code>POST <?php echo esc_html( rest_url( 'n8npress/v1/claw/channel-execute' ) ); ?></code>
							<p class="description"><?php esc_html_e( 'Execute actions from callback buttons. Requires: channel, sender_id, action_type, action_data', 'n8npress' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Auth Header', 'n8npress' ); ?></th>
						<td>
							<code>Authorization: Bearer &lt;your-api-token&gt;</code>
							<p class="description"><?php esc_html_e( 'Use the API token from the Connection tab', 'n8npress' ); ?></p>
						</td>
					</tr>
				</table>
			</div>
		</div>

		<!-- SECURITY -->
		<div class="n8npress-tab-content <?php echo 'security' === $active_tab ? 'tab-active' : ''; ?>" id="tab-security">
			<div class="n8npress-card">
				<h2><?php esc_html_e( 'Security Headers', 'n8npress' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="n8npress_security_headers"><?php esc_html_e( 'Enable', 'n8npress' ); ?></label></th>
						<td>
							<label>
								<input type="checkbox" id="n8npress_security_headers" name="n8npress_security_headers" value="1" <?php checked( $security_headers, 1 ); ?> />
								<?php esc_html_e( 'Add X-Content-Type-Options, X-Frame-Options, Referrer-Policy', 'n8npress' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><label for="n8npress_ip_whitelist"><?php esc_html_e( 'IP Whitelist', 'n8npress' ); ?></label></th>
						<td>
							<input type="text" id="n8npress_ip_whitelist" name="n8npress_ip_whitelist"
							       value="<?php echo esc_attr( $ip_whitelist ); ?>" class="regular-text"
							       placeholder="1.2.3.4, 5.6.7.8" />
							<p class="description"><?php esc_html_e( 'Comma-separated IPs. Leave empty to allow all.', 'n8npress' ); ?></p>
						</td>
					</tr>
				</table>
			</div>
			<div class="n8npress-card">
				<h2><?php esc_html_e( 'HMAC Webhook Signing', 'n8npress' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'HMAC Secret', 'n8npress' ); ?></th>
						<td>
							<?php if ( ! empty( $hmac_secret ) ) : ?>
								<code class="n8npress-hmac-preview"><?php echo esc_html( substr( $hmac_secret, 0, 8 ) . '...' . substr( $hmac_secret, -4 ) ); ?></code>
								<span class="badge-active"><?php esc_html_e( 'Active', 'n8npress' ); ?></span>
							<?php else : ?>
								<span class="badge-inactive"><?php esc_html_e( 'Not generated', 'n8npress' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Regenerate', 'n8npress' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="n8npress_regenerate_hmac" value="1" />
								<?php esc_html_e( 'Generate new HMAC secret (invalidates existing webhook signatures)', 'n8npress' ); ?>
							</label>
						</td>
					</tr>
				</table>
			</div>
		</div>

		<p class="submit">
			<input type="submit" name="n8npress_save_settings" class="button-primary" value="<?php esc_attr_e( 'Save Settings', 'n8npress' ); ?>" />
		</p>
	</form>
</div>
