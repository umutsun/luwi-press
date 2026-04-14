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
		<a href="?page=luwipress-settings&tab=open-claw" class="nav-tab <?php echo 'open-claw' === $active_tab ? 'nav-tab-active' : ''; ?>">
			<span class="dashicons dashicons-superhero-alt"></span> <?php esc_html_e( 'Open Claw', 'luwipress' ); ?>
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
			$processing_mode  = get_option( 'luwipress_processing_mode', 'local' );
			$default_provider = get_option( 'luwipress_default_provider', 'anthropic' );
			?>

			<!-- AI Provider -->
			<div class="luwipress-card" style="border-left:4px solid #0073aa;">
				<h2><?php esc_html_e( 'AI Provider', 'luwipress' ); ?></h2>
				<p class="description" style="margin-bottom:12px;"><?php esc_html_e( 'LuwiPress calls AI APIs directly from WordPress. Select your preferred provider.', 'luwipress' ); ?></p>
				<table class="form-table">
					<tr>
						<th><label for="luwipress_default_provider"><?php esc_html_e( 'Default AI Provider', 'luwipress' ); ?></label></th>
						<td>
							<select name="luwipress_default_provider" id="luwipress_default_provider">
								<option value="anthropic" <?php selected( $default_provider, 'anthropic' ); ?>>Anthropic (Claude)</option>
								<option value="openai" <?php selected( $default_provider, 'openai' ); ?>>OpenAI (GPT)</option>
								<option value="google" <?php selected( $default_provider, 'google' ); ?>>Google (Gemini)</option>
							</select>
							<p class="description"><?php esc_html_e( 'Each task can also use a specific provider.', 'luwipress' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

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

			<div class="luwipress-card" style="background:#f9fafb;">
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
