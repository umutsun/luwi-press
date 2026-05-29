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
	// AI Provider
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
	$primary_lang = sanitize_text_field( $_POST['luwipress_target_language'] ?? '' );
	if ( class_exists( 'LuwiPress_Plugin_Detector' ) ) {
		$t_info = LuwiPress_Plugin_Detector::get_instance()->detect_translation();
		$active = $t_info['active_languages'] ?? array();
		if ( 'none' !== ( $t_info['plugin'] ?? 'none' ) && ! empty( $active ) && ! in_array( $primary_lang, $active, true ) ) {
			// Submitted language isn't active on the site — snap to translation plugin's default.
			$primary_lang = $t_info['default_language'] ?? ( $active[0] ?? 'en' );
		}
	}
	if ( empty( $primary_lang ) ) {
		$primary_lang = 'en';
	}
	update_option( 'luwipress_target_language', $primary_lang );

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

	// AI API Keys — guard with isset() so the WP 7.0 Connectors-managed view
	// (where these inputs aren't rendered at all) doesn't wipe stored keys on
	// every Settings save. Absent POST field = leave existing option untouched.
	if ( isset( $_POST['luwipress_openai_api_key'] ) ) {
		update_option( 'luwipress_openai_api_key', sanitize_text_field( wp_unslash( $_POST['luwipress_openai_api_key'] ) ) );
	}
	if ( isset( $_POST['luwipress_anthropic_api_key'] ) ) {
		update_option( 'luwipress_anthropic_api_key', sanitize_text_field( wp_unslash( $_POST['luwipress_anthropic_api_key'] ) ) );
	}
	if ( isset( $_POST['luwipress_google_ai_api_key'] ) ) {
		update_option( 'luwipress_google_ai_api_key', sanitize_text_field( wp_unslash( $_POST['luwipress_google_ai_api_key'] ) ) );
	}
	update_option( 'luwipress_ai_provider', sanitize_text_field( $_POST['luwipress_ai_provider'] ?? 'openai' ) );
	update_option( 'luwipress_ai_model', sanitize_text_field( $_POST['luwipress_ai_model'] ?? 'gpt-4o-mini' ) );

	// OpenAI-Compatible provider (DeepSeek, Kimi, Groq, Together, custom)
	$oai_preset_allowed = array( 'deepseek', 'kimi', 'groq', 'together', 'custom' );
	$oai_preset         = sanitize_text_field( $_POST['luwipress_oai_compat_preset'] ?? 'deepseek' );
	if ( ! in_array( $oai_preset, $oai_preset_allowed, true ) ) {
		$oai_preset = 'deepseek';
	}
	update_option( 'luwipress_oai_compat_preset', $oai_preset );
	update_option( 'luwipress_oai_compat_api_key', sanitize_text_field( $_POST['luwipress_oai_compat_api_key'] ?? '' ) );
	update_option( 'luwipress_oai_compat_base_url', esc_url_raw( $_POST['luwipress_oai_compat_base_url'] ?? '' ) );
	update_option( 'luwipress_oai_compat_model', sanitize_text_field( $_POST['luwipress_oai_compat_model'] ?? '' ) );
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

	// Per-CPT word count target bands (3.5.4+) — drives the Content
	// Depth Health Score pillar and the Content Audit "Word Count" tab.
	// Posted as nested array keyed by CPT slug; only known CPT keys
	// are persisted to keep the option clean.
	if ( isset( $_POST['luwipress_word_count_targets'] ) && is_array( $_POST['luwipress_word_count_targets'] ) && class_exists( 'LuwiPress_Health_Score' ) ) {
		$raw_bands = $_POST['luwipress_word_count_targets'];
		$defaults  = LuwiPress_Health_Score::default_word_count_targets();
		$persist   = array();
		foreach ( $defaults as $cpt => $row ) {
			if ( ! isset( $raw_bands[ $cpt ] ) || ! is_array( $raw_bands[ $cpt ] ) ) {
				continue;
			}
			$persist[ $cpt ] = array(
				'min'    => max( 0, min( 10000, (int) ( $raw_bands[ $cpt ]['min']    ?? $row['min'] ) ) ),
				'target' => max( 0, min( 10000, (int) ( $raw_bands[ $cpt ]['target'] ?? $row['target'] ) ) ),
				'max'    => max( 0, min( 10000, (int) ( $raw_bands[ $cpt ]['max']    ?? $row['max'] ) ) ),
			);
		}
		update_option( 'luwipress_word_count_targets', $persist, false );
		LuwiPress_Health_Score::get_instance()->invalidate_cache();
	}

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

	// Marketplace credentials moved to the LuwiPress Marketplace Sync
	// companion plugin (3.1.44). Settings UI is rendered by that plugin's
	// own submenu page — see luwipress-marketplace-sync/admin/.

	// Security
	update_option( 'luwipress_security_headers', isset( $_POST['luwipress_security_headers'] ) ? 1 : 0 );
	update_option( 'luwipress_ip_whitelist', sanitize_text_field( $_POST['luwipress_ip_whitelist'] ?? '' ) );
	if ( ! empty( $_POST['luwipress_regenerate_hmac'] ) ) {
		LuwiPress_HMAC::ensure_secret( true );
	}

	// Content Health pillar overrides (3.5.4+). Posted as nested array keyed
	// by pillar key. Empty checkbox → enabled=false (browsers don't send
	// unchecked checkboxes, so the key is absent rather than "0").
	if ( isset( $_POST['luwipress_health_pillars'] ) && class_exists( 'LuwiPress_Health_Score' ) ) {
		$raw = (array) $_POST['luwipress_health_pillars'];
		// Translate the form shape into the API shape — every pillar gets
		// an `enabled` boolean explicitly so unchecked boxes are persisted.
		$normalized = array();
		$health     = LuwiPress_Health_Score::get_instance();
		foreach ( $health->get_pillars() as $pkey => $_pdef ) {
			$row = isset( $raw[ $pkey ] ) && is_array( $raw[ $pkey ] ) ? $raw[ $pkey ] : array();
			$normalized[ $pkey ] = array(
				'enabled'          => ! empty( $row['enabled'] ),
				'weight'           => isset( $row['weight'] )           ? (int) $row['weight']           : null,
				'target'           => isset( $row['target'] )           ? (int) $row['target']           : null,
				'action_threshold' => isset( $row['action_threshold'] ) ? (int) $row['action_threshold'] : null,
			);
			// Strip null entries so we don't overwrite stored values with null.
			foreach ( $normalized[ $pkey ] as $k => $v ) {
				if ( $v === null ) {
					unset( $normalized[ $pkey ][ $k ] );
				}
			}
		}
		$health->save_pillar_overrides( $normalized );
	}

	// Reset request — also handled inside the save flow so the operator can
	// hit "Reset to defaults" without bouncing through the REST endpoint.
	if ( ! empty( $_POST['luwipress_health_reset'] ) && class_exists( 'LuwiPress_Health_Score' ) ) {
		LuwiPress_Health_Score::get_instance()->reset_pillars();
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
$oai_compat_preset  = get_option( 'luwipress_oai_compat_preset', 'deepseek' );
$oai_compat_key     = get_option( 'luwipress_oai_compat_api_key', '' );
$oai_compat_base    = get_option( 'luwipress_oai_compat_base_url', '' );
$oai_compat_model   = get_option( 'luwipress_oai_compat_model', '' );
$oai_compat_presets = class_exists( 'LuwiPress_Provider_OpenAI_Compatible' )
	? LuwiPress_Provider_OpenAI_Compatible::get_presets()
	: array();
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

// Marketplace credential loader removed in 3.1.44 — see the LuwiPress
// Marketplace Sync companion plugin's own settings page.

$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'connection';

// Detected plugins for info boxes
$detector    = LuwiPress_Plugin_Detector::get_instance();
$env         = $detector->get_environment();
$seo_plugin  = $env['seo']['plugin'] ?? 'none';
$trans_plugin = $env['translation']['plugin'] ?? 'none';
$email_plugin = $env['email']['plugin'] ?? 'wp_mail';
?>

<div class="wrap luwipress-settings">

	<!-- Branded header mirrors Dashboard / Knowledge Graph chrome -->
	<div class="lp-header">
		<div class="lp-header-left">
			<h1 class="lp-title">
				<img class="lp-logo" width="28" height="28"
				     src="<?php echo esc_url( LUWIPRESS_PLUGIN_URL . 'assets/images/luwi-logo.png' ); ?>"
				     alt="LuwiPress" />
				<?php esc_html_e( 'Settings', 'luwipress' ); ?>
			</h1>
		</div>
		<div class="lp-header-actions">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=luwipress' ) ); ?>"
			   class="lp-pill lp-pill--action pill-neutral lp-pill--icon"
			   title="<?php esc_attr_e( 'Dashboard', 'luwipress' ); ?>">
				<span class="dashicons dashicons-dashboard"></span>
				<span class="screen-reader-text"><?php esc_html_e( 'Dashboard', 'luwipress' ); ?></span>
			</a>
			<span class="lp-pill pill-neutral" title="<?php esc_attr_e( 'Plugin version', 'luwipress' ); ?>">
				v<?php echo esc_html( LUWIPRESS_VERSION ); ?>
			</span>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=luwipress-usage' ) ); ?>"
			   class="lp-pill lp-pill--action pill-neutral lp-pill--icon"
			   title="<?php esc_attr_e( 'Usage & Logs', 'luwipress' ); ?>">
				<span class="dashicons dashicons-chart-bar"></span>
				<span class="screen-reader-text"><?php esc_html_e( 'Usage & Logs', 'luwipress' ); ?></span>
			</a>
		</div>
	</div>

	<nav class="lp-hub-tabs lwp-settings-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Settings sections', 'luwipress' ); ?>">
		<?php
		$tabs_def = array(
			'connection'     => array( 'label' => __( 'Connection', 'luwipress' ),     'icon' => 'dashicons-admin-links' ),
			'general'        => array( 'label' => __( 'General', 'luwipress' ),        'icon' => 'dashicons-admin-settings' ),
			'api-keys'       => array( 'label' => __( 'AI API Keys', 'luwipress' ),    'icon' => 'dashicons-admin-network' ),
			'ai'             => array( 'label' => __( 'AI Content', 'luwipress' ),     'icon' => 'dashicons-edit-large' ),
			'translation'    => array( 'label' => __( 'Translation', 'luwipress' ),    'icon' => 'dashicons-translation' ),
			'crm'            => array( 'label' => __( 'CRM', 'luwipress' ),            'icon' => 'dashicons-groups' ),
			'customer-chat'  => array( 'label' => __( 'Customer Chat', 'luwipress' ),  'icon' => 'dashicons-format-chat' ),
			'security'       => array( 'label' => __( 'Security', 'luwipress' ),       'icon' => 'dashicons-shield-alt' ),
			'bot'            => array( 'label' => __( 'Bot', 'luwipress' ),            'icon' => 'dashicons-shield' ),
			'content-health' => array( 'label' => __( 'Content Health', 'luwipress' ), 'icon' => 'dashicons-chart-area' ),
		);
		foreach ( $tabs_def as $slug => $cfg ) :
			$is_active = ( $slug === $active_tab );
		?>
		<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'luwipress-settings', 'tab' => $slug ), admin_url( 'admin.php' ) ) ); ?>"
		   class="lp-hub-tab <?php echo $is_active ? 'lp-hub-tab--active' : ''; ?>"
		   role="tab"
		   aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>">
			<span class="dashicons <?php echo esc_attr( $cfg['icon'] ); ?>"></span>
			<span><?php echo esc_html( $cfg['label'] ); ?></span>
		</a>
		<?php endforeach; ?>
		<?php
		/**
		 * Companion plugins can hook this action to register their own tab nav
		 * links inside the LuwiPress Settings page. Each hook should echo an
		 * `<a class="lp-hub-tab">` element. The current active tab is passed in
		 * so the hook can apply `lp-hub-tab--active`. (Pre-3.5.8 hooks emitting
		 * `<a class="nav-tab">` continue to render — they just won't pick up
		 * the new token styling without an update.)
		 *
		 * @param string $active_tab Currently selected tab id.
		 * @since 3.2.4
		 */
		do_action( 'luwipress_settings_render_tab_nav', $active_tab );
		?>
	</nav>

	<form method="post" class="luwipress-settings-form">
		<?php wp_nonce_field( 'luwipress_settings_nonce' ); ?>

		<!-- CONNECTION -->
		<div class="luwipress-tab-content <?php echo 'connection' === $active_tab ? 'tab-active' : ''; ?>" id="tab-connection">

			<?php
			$webmcp_active   = class_exists( 'LuwiPress_WebMCP' ) || defined( 'LUWIPRESS_WEBMCP_VERSION' );
			// Mirror the companion's default (enabled when the option isn't yet persisted).
			// Companion default is `true`; core default when companion absent is `false`.
			$webmcp_enabled = (bool) get_option( 'luwipress_webmcp_enabled', $webmcp_active ? 1 : 0 );
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
						<?php esc_html_e( 'AI-agent integration requires the separate "LuwiPress WebMCP" plugin. Core LuwiPress exposes REST only; MCP tooling lives in the companion.', 'luwipress' ); ?>
					</div>
				<?php else : ?>
					<div class="luwipress-info-box" style="border-left:3px solid var(--lp-success,#16a34a);">
						<span class="dashicons dashicons-yes-alt" style="color:var(--lp-success);"></span>
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
			<?php
			// WordPress 7.0 Connectors detection — when active, API keys live
			// in the native WP Connectors UI. We keep the model picker + budget
			// + per-workflow override (LuwiPress-specific moats) but hide the
			// key inputs and offer a one-click migration of any legacy keys.
			$wp7_connectors_active = class_exists( 'LuwiPress_Connectors' ) && LuwiPress_Connectors::is_active();
			$wp7_state             = $wp7_connectors_active ? LuwiPress_Connectors::list_active_connectors() : array();
			$wp7_has_legacy_keys   = false;
			if ( $wp7_connectors_active ) {
				foreach ( $wp7_state as $row ) {
					if ( ! empty( $row['has_legacy'] ) && empty( $row['in_connectors'] ) ) {
						$wp7_has_legacy_keys = true;
						break;
					}
				}
			}
			?>

			<?php if ( $wp7_connectors_active ) : ?>
				<div class="luwipress-card lp-wp7-banner" data-wp7-banner>
					<div class="lp-wp7-banner-head">
						<span class="dashicons dashicons-info-outline"></span>
						<strong><?php esc_html_e( 'WordPress 7.0 Connectors detected', 'luwipress' ); ?></strong>
					</div>
					<p class="description">
						<?php
						echo wp_kses(
							sprintf(
								/* translators: %s: link to Settings → Connectors */
								__( 'API keys are now managed centrally under %s. LuwiPress reads keys from Connectors first, falling back to its own settings only when a provider is not configured natively. Model selection, per-workflow routing, and budget enforcement remain here.', 'luwipress' ),
								'<a href="' . esc_url( admin_url( 'options-general.php' ) ) . '"><strong>' . esc_html__( 'Settings → Connectors', 'luwipress' ) . '</strong></a>'
							),
							array( 'a' => array( 'href' => array() ), 'strong' => array() )
						);
						?>
					</p>

					<div class="lp-wp7-pills" role="list">
						<?php
						$labels = array(
							'openai'    => 'OpenAI',
							'anthropic' => 'Anthropic',
							'google'    => 'Google',
						);
						foreach ( $labels as $provider => $label ) :
							$row    = isset( $wp7_state[ $provider ] ) ? $wp7_state[ $provider ] : array();
							$source = isset( $row['source'] ) ? $row['source'] : 'none';
							$pill   = 'pill-neutral';
							$icon   = 'dashicons-marker';
							$state_label = __( 'Not configured', 'luwipress' );
							if ( 'connectors' === $source ) {
								$pill        = 'pill-success';
								$icon        = 'dashicons-yes-alt';
								$state_label = __( 'Connectors', 'luwipress' );
							} elseif ( 'legacy' === $source ) {
								$pill        = 'pill-warning';
								$icon        = 'dashicons-warning';
								$state_label = __( 'Legacy (LuwiPress)', 'luwipress' );
							}
							?>
							<span class="lp-pill <?php echo esc_attr( $pill ); ?>" role="listitem" data-provider-pill="<?php echo esc_attr( $provider ); ?>">
								<span class="dashicons <?php echo esc_attr( $icon ); ?>"></span>
								<strong><?php echo esc_html( $label ); ?></strong>
								<span class="lp-pill-state"><?php echo esc_html( $state_label ); ?></span>
							</span>
						<?php endforeach; ?>
					</div>

					<?php if ( $wp7_has_legacy_keys ) : ?>
						<div class="lp-wp7-actions">
							<button type="button" class="button button-primary" id="luwipress-wp7-migrate-open" data-rest-url="<?php echo esc_attr( get_rest_url( null, 'luwipress/v1/connectors/migrate-preview' ) ); ?>">
								<span class="dashicons dashicons-migrate"></span>
								<?php esc_html_e( 'Move legacy keys into Connectors…', 'luwipress' ); ?>
							</button>
							<span class="description" style="margin-left:8px;">
								<?php esc_html_e( 'A confirmation modal lists exactly which keys will move before any change is made.', 'luwipress' ); ?>
							</span>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php
			// Provider config map — drives the unified provider card.
			// Each provider owns its model catalog so users never pick a model from a vendor they haven't selected.
			$providers = array(
				'anthropic' => array(
					'label'       => 'Anthropic',
					'vendor'      => 'Anthropic',
					'desc'        => __( 'Best for nuanced product descriptions and multilingual content.', 'luwipress' ),
					'key_field'   => 'luwipress_anthropic_api_key',
					'key_value'   => $anthropic_key,
					'placeholder' => 'sk-ant-…',
					'help_url'    => 'https://console.anthropic.com/settings/keys',
					'models'      => array(
						'claude-haiku-4-5'  => array( 'label' => __( 'Claude Haiku — fast &amp; affordable', 'luwipress' ), 'cost' => '$0.80 / $4.00 per 1M', 'recommended' => true ),
						'claude-sonnet-4-6' => array( 'label' => __( 'Claude Sonnet — balanced', 'luwipress' ), 'cost' => '$3.00 / $15.00 per 1M' ),
					),
					'default_model' => 'claude-haiku-4-5',
				),
				'openai' => array(
					'label'       => 'GPT',
					'vendor'      => 'OpenAI',
					'desc'        => __( 'GPT-4o for content, DALL·E for images.', 'luwipress' ),
					'key_field'   => 'luwipress_openai_api_key',
					'key_value'   => $openai_key,
					'placeholder' => 'sk-…',
					'help_url'    => 'https://platform.openai.com/api-keys',
					'models'      => array(
						'gpt-4o-mini' => array( 'label' => __( 'GPT-4o Mini — affordable', 'luwipress' ), 'cost' => '$0.15 / $0.60 per 1M', 'recommended' => true ),
						'gpt-4o'      => array( 'label' => __( 'GPT-4o — powerful', 'luwipress' ), 'cost' => '$2.50 / $10.00 per 1M' ),
					),
					'default_model' => 'gpt-4o-mini',
				),
				'google' => array(
					'label'       => 'Gemini',
					'vendor'      => 'Google',
					'desc'        => __( 'Cost-effective content at scale.', 'luwipress' ),
					'key_field'   => 'luwipress_google_ai_api_key',
					'key_value'   => $google_ai_key,
					'placeholder' => 'AIza…',
					'help_url'    => 'https://aistudio.google.com/apikey',
					'models'      => array(
						'gemini-2.0-flash' => array( 'label' => __( 'Gemini 2.0 Flash — fastest', 'luwipress' ), 'cost' => '$0.10 / $0.40 per 1M', 'recommended' => true ),
						'gemini-2.5-flash' => array( 'label' => __( 'Gemini 2.5 Flash', 'luwipress' ), 'cost' => '$0.15 / $0.60 per 1M' ),
						'gemini-2.5-pro'   => array( 'label' => __( 'Gemini 2.5 Pro — powerful', 'luwipress' ), 'cost' => '$1.25 / $10.00 per 1M' ),
					),
					'default_model' => 'gemini-2.0-flash',
				),
				'openai-compatible' => array(
					'label'       => 'Custom',
					'vendor'      => __( 'OpenAI-Compatible', 'luwipress' ),
					'desc'        => __( 'DeepSeek, Kimi, Groq, Together.ai, or self-hosted.', 'luwipress' ),
					'key_field'   => 'luwipress_oai_compat_api_key',
					'key_value'   => $oai_compat_key,
					'placeholder' => 'sk-…',
					'help_url'    => '',
					// Custom provider manages its model list via preset-driven dropdown below,
					// so we don't put a static model list here.
					'models'      => array(),
					'default_model' => '',
				),
			);
			$active_provider = isset( $providers[ $ai_provider ] ) ? $ai_provider : 'anthropic';
			?>

			<!-- Provider + key — one card, provider-driven -->
			<div class="luwipress-card lp-provider-card">
				<h2>
					<span class="dashicons dashicons-admin-network"></span>
					<?php esc_html_e( 'AI Provider', 'luwipress' ); ?>
				</h2>
				<p class="description" style="margin:-8px 0 16px;">
					<?php esc_html_e( 'Pick one vendor. LuwiPress uses this provider for content generation, translation, and review responses. You can switch anytime.', 'luwipress' ); ?>
				</p>

				<fieldset class="lp-provider-pills" role="radiogroup" aria-label="<?php esc_attr_e( 'Primary AI provider', 'luwipress' ); ?>">
					<?php foreach ( $providers as $key => $p ) :
						$has_key = ! empty( $p['key_value'] );
					?>
					<label class="lp-provider-pill <?php echo $active_provider === $key ? 'is-active' : ''; ?>" data-provider="<?php echo esc_attr( $key ); ?>">
						<input type="radio" name="luwipress_ai_provider" value="<?php echo esc_attr( $key ); ?>" <?php checked( $active_provider, $key ); ?> />
						<span class="lp-provider-pill-body">
							<span class="lp-provider-pill-label">
								<strong><?php echo esc_html( $p['label'] ); ?></strong>
								<?php if ( $has_key ) : ?>
									<span class="lp-provider-pill-check" title="<?php esc_attr_e( 'Key saved', 'luwipress' ); ?>"><span class="dashicons dashicons-yes-alt"></span></span>
								<?php endif; ?>
							</span>
							<span class="lp-provider-pill-vendor"><?php echo esc_html( $p['vendor'] ); ?></span>
						</span>
					</label>
					<?php endforeach; ?>
				</fieldset>

				<?php
				// This hidden input carries the actual saved model id on submit.
				// JS syncs it whenever the provider pill or model radio changes, so the user never
				// has to pick a model from a vendor they didn't select.
				$submit_model = $ai_model;
				$active_models = $providers[ $active_provider ]['models'];
				if ( ! empty( $active_models ) && ! array_key_exists( $ai_model, $active_models ) ) {
					$submit_model = $providers[ $active_provider ]['default_model'];
				}
				?>
				<input type="hidden" name="luwipress_ai_model" id="luwipress_ai_model" value="<?php echo esc_attr( $submit_model ); ?>" />

				<?php // Per-provider key input (only the active one is visible). ?>
				<div class="lp-provider-keys">
				<?php foreach ( $providers as $key => $p ) : ?>
					<div class="lp-provider-key-block" data-provider="<?php echo esc_attr( $key ); ?>" data-default-model="<?php echo esc_attr( $p['default_model'] ); ?>" <?php echo $active_provider !== $key ? 'hidden' : ''; ?>>
						<?php if ( 'openai-compatible' === $key ) : ?>
							<?php // Custom provider: preset picker first, then base URL (if custom), key, model. ?>
							<div class="lp-field">
								<label for="luwipress_oai_compat_preset"><?php esc_html_e( 'Preset', 'luwipress' ); ?></label>
								<select name="luwipress_oai_compat_preset" id="luwipress_oai_compat_preset" class="regular-text">
									<?php foreach ( $oai_compat_presets as $preset_key => $preset_info ) : ?>
										<option value="<?php echo esc_attr( $preset_key ); ?>" <?php selected( $oai_compat_preset, $preset_key ); ?>>
											<?php echo esc_html( $preset_info['label'] ); ?>
											<?php if ( ! empty( $preset_info['base_url'] ) ) : ?> — <?php echo esc_html( $preset_info['base_url'] ); ?><?php endif; ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Switches base URL + model list automatically.', 'luwipress' ); ?></p>
							</div>
							<div class="lp-field lp-oai-compat-custom-row" <?php echo 'custom' !== $oai_compat_preset ? 'hidden' : ''; ?>>
								<label for="luwipress_oai_compat_base_url"><?php esc_html_e( 'Base URL', 'luwipress' ); ?></label>
								<input type="url" id="luwipress_oai_compat_base_url" name="luwipress_oai_compat_base_url"
								       value="<?php echo esc_attr( $oai_compat_base ); ?>" class="regular-text"
								       placeholder="http://localhost:11434/v1" />
								<p class="description"><?php esc_html_e( 'Self-hosted servers only (Ollama, vLLM, LM Studio). Include /v1 if needed.', 'luwipress' ); ?></p>
							</div>
						<?php endif; ?>

						<?php
						// WP 7.0 Connectors managed-mode: hide native-scope key input
						// (OpenAI/Anthropic/Google) when Connectors layer is active.
						// OpenAI-Compatible (DeepSeek/Kimi/Groq/Together/self-hosted)
						// always shows its own key input — out of Connectors scope.
						$native_scope        = in_array( $key, array( 'openai', 'anthropic', 'google' ), true );
						$hide_legacy_key_row = $wp7_connectors_active && $native_scope;
						?>
						<?php if ( $hide_legacy_key_row ) : ?>
							<div class="lp-field lp-field-wp7-managed">
								<label>
									<?php
									/* translators: %s: provider vendor name (Anthropic, OpenAI, etc.) */
									echo esc_html( sprintf( __( '%s API Key', 'luwipress' ), $p['vendor'] ) );
									?>
								</label>
								<div class="lp-wp7-managed-row" data-provider-row="<?php echo esc_attr( $key ); ?>">
									<?php
									$row    = isset( $wp7_state[ $key ] ) ? $wp7_state[ $key ] : array();
									$source = isset( $row['source'] ) ? $row['source'] : 'none';
									if ( 'connectors' === $source ) {
										echo '<span class="lp-pill pill-success"><span class="dashicons dashicons-yes-alt"></span> ' . esc_html__( 'Managed by WordPress Connectors', 'luwipress' ) . '</span>';
									} elseif ( 'legacy' === $source ) {
										echo '<span class="lp-pill pill-warning"><span class="dashicons dashicons-warning"></span> ' . esc_html__( 'Stored in legacy LuwiPress option — migrate to Connectors above', 'luwipress' ) . '</span>';
									} else {
										echo '<span class="lp-pill pill-neutral"><span class="dashicons dashicons-marker"></span> ' . esc_html__( 'Not configured', 'luwipress' ) . '</span>';
									}
									?>
								</div>
								<p class="description">
									<a href="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>">
										<?php esc_html_e( 'Open Settings → Connectors to manage this key →', 'luwipress' ); ?>
									</a>
								</p>
							</div>
						<?php else : ?>
							<div class="lp-field">
								<label for="<?php echo esc_attr( $p['key_field'] ); ?>">
									<?php
									/* translators: %s: provider vendor name (Anthropic, OpenAI, etc.) */
									echo esc_html( sprintf( __( '%s API Key', 'luwipress' ), $p['vendor'] ) );
									?>
									<?php if ( ! empty( $p['key_value'] ) ) : ?>
										<span class="lp-field-saved"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Saved', 'luwipress' ); ?></span>
									<?php endif; ?>
								</label>
								<div class="lp-field-input-row">
									<input type="password" id="<?php echo esc_attr( $p['key_field'] ); ?>" name="<?php echo esc_attr( $p['key_field'] ); ?>"
									       value="<?php echo esc_attr( $p['key_value'] ); ?>" class="regular-text" autocomplete="off"
									       placeholder="<?php echo esc_attr( $p['placeholder'] ); ?>" />
									<button type="button" class="button button-small luwipress-toggle-password" data-target="<?php echo esc_attr( $p['key_field'] ); ?>" aria-label="<?php esc_attr_e( 'Show/hide key', 'luwipress' ); ?>">
										<span class="dashicons dashicons-visibility"></span>
									</button>
								</div>
								<?php if ( ! empty( $p['help_url'] ) ) : ?>
									<p class="description">
										<a href="<?php echo esc_url( $p['help_url'] ); ?>" target="_blank" rel="noopener">
											<?php esc_html_e( 'Get your API key →', 'luwipress' ); ?>
										</a>
									</p>
								<?php endif; ?>
							</div>
						<?php endif; ?>

						<?php if ( ! empty( $p['models'] ) ) : ?>
						<?php
						// Model picker as visual cards — only the active provider's models are present in the DOM.
						// If the saved $ai_model belongs to another provider, fall back to this provider's default
						// so the UI never shows a nonsensical cross-provider model when the pill is active.
						$model_for_this_provider = array_key_exists( $ai_model, $p['models'] ) ? $ai_model : $p['default_model'];
						?>
						<div class="lp-field">
							<label><?php esc_html_e( 'Model', 'luwipress' ); ?></label>
							<div class="lp-model-grid">
								<?php foreach ( $p['models'] as $model_id => $model_meta ) :
									$is_checked = ( $active_provider === $key ) && ( $model_for_this_provider === $model_id );
								?>
								<label class="lp-model-card <?php echo ! empty( $model_meta['recommended'] ) ? 'is-recommended' : ''; ?>">
									<input type="radio" name="luwipress_ai_model_<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $model_id ); ?>" <?php checked( $is_checked ); ?> />
									<span class="lp-model-card-body">
										<span class="lp-model-card-top">
											<strong><?php echo esc_html( $model_meta['label'] ); ?></strong>
											<?php if ( ! empty( $model_meta['recommended'] ) ) : ?>
												<span class="lp-model-card-badge"><?php esc_html_e( 'Recommended', 'luwipress' ); ?></span>
											<?php endif; ?>
										</span>
										<span class="lp-model-card-cost"><?php echo esc_html( $model_meta['cost'] ); ?></span>
									</span>
								</label>
								<?php endforeach; ?>
							</div>
							<p class="description"><?php esc_html_e( 'Per-million-token pricing (input / output). All Luwi workflows use the selected model.', 'luwipress' ); ?></p>
						</div>
						<?php endif; ?>

						<?php if ( 'openai-compatible' === $key ) : ?>
							<div class="lp-field">
								<label for="luwipress_oai_compat_model"><?php esc_html_e( 'Model', 'luwipress' ); ?></label>
								<?php
								$current_preset_models = $oai_compat_presets[ $oai_compat_preset ]['models'] ?? array();
								if ( ! empty( $current_preset_models ) ) :
									?>
									<select name="luwipress_oai_compat_model" id="luwipress_oai_compat_model" class="regular-text">
										<option value=""><?php esc_html_e( '— Use preset default —', 'luwipress' ); ?></option>
										<?php foreach ( $current_preset_models as $model_id => $model_label ) : ?>
											<option value="<?php echo esc_attr( $model_id ); ?>" <?php selected( $oai_compat_model, $model_id ); ?>>
												<?php echo esc_html( $model_label ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								<?php else : ?>
									<input type="text" id="luwipress_oai_compat_model" name="luwipress_oai_compat_model"
									       value="<?php echo esc_attr( $oai_compat_model ); ?>" class="regular-text"
									       placeholder="llama-3.1-8b-instruct" />
								<?php endif; ?>
								<?php
								$off_peak = $oai_compat_presets[ $oai_compat_preset ]['off_peak_utc'] ?? null;
								if ( is_array( $off_peak ) && count( $off_peak ) === 2 ) :
									?>
									<p class="description">
										<?php
										/* translators: 1: preset label, 2: start time UTC, 3: end time UTC */
										printf(
											esc_html__( 'Tip: %1$s offers a ~50%% off-peak discount between %2$s–%3$s UTC.', 'luwipress' ),
											esc_html( $oai_compat_presets[ $oai_compat_preset ]['label'] ),
											esc_html( $off_peak[0] ),
											esc_html( $off_peak[1] )
										);
										?>
									</p>
								<?php endif; ?>
							</div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
				</div>
			</div>

			<?php
			// Secondary keys — advanced, collapsed by default.
			// Shown only if the user has keys saved for providers other than the active one.
			$secondary = array();
			foreach ( $providers as $key => $p ) {
				if ( $key !== $active_provider && ! empty( $p['key_value'] ) ) {
					$secondary[ $key ] = $p;
				}
			}
			if ( ! empty( $secondary ) ) :
			?>
			<details class="lp-secondary-keys">
				<summary>
					<span class="dashicons dashicons-admin-generic"></span>
					<?php
					/* translators: %d: count of additional saved keys */
					echo esc_html( sprintf( _n( '%d other saved key', '%d other saved keys', count( $secondary ), 'luwipress' ), count( $secondary ) ) );
					?>
					<span class="lp-secondary-keys-hint"><?php esc_html_e( '(fallback providers — not active)', 'luwipress' ); ?></span>
				</summary>
				<div class="lp-secondary-keys-body">
					<p class="description" style="margin-top:12px;">
						<?php esc_html_e( 'Keys you saved for other providers are kept here so you can switch back without re-entering them. Only the active provider above is used for AI calls.', 'luwipress' ); ?>
					</p>
					<?php foreach ( $secondary as $key => $p ) : ?>
						<div class="lp-secondary-key-row">
							<span class="lp-secondary-key-vendor"><?php echo esc_html( $p['vendor'] ); ?></span>
							<code><?php echo esc_html( substr( $p['key_value'], 0, 7 ) . '…' . substr( $p['key_value'], -4 ) ); ?></code>
							<span class="lp-secondary-key-check"><span class="dashicons dashicons-yes-alt"></span></span>
						</div>
					<?php endforeach; ?>
				</div>
			</details>
			<?php endif; ?>

			<script>
			(function () {
				var hiddenModel = document.getElementById('luwipress_ai_model');
				var pills = document.querySelectorAll('.lp-provider-pill input[type="radio"]');
				var blocks = document.querySelectorAll('.lp-provider-key-block');

				// When provider changes: show its key block, and sync the hidden
				// `luwipress_ai_model` to whatever radio is selected inside that block
				// (falls back to data-default-model if none is checked yet).
				function syncBlocks(active) {
					blocks.forEach(function (b) {
						b.hidden = b.getAttribute('data-provider') !== active;
					});
					document.querySelectorAll('.lp-provider-pill').forEach(function (p) {
						p.classList.toggle('is-active', p.getAttribute('data-provider') === active);
					});
					syncHiddenModel();
				}

				function syncHiddenModel() {
					if (!hiddenModel) return;
					var activeBlock = document.querySelector('.lp-provider-key-block:not([hidden])');
					if (!activeBlock) return;
					var provider = activeBlock.getAttribute('data-provider');
					var fallback = activeBlock.getAttribute('data-default-model') || '';

					if (provider === 'openai-compatible') {
						// Custom provider uses the preset-driven <select>; model id is already
						// submitted via its own name="luwipress_oai_compat_model". Keep the hidden
						// luwipress_ai_model empty so the backend knows to use the custom slot.
						hiddenModel.value = '';
						return;
					}

					var selectedRadio = activeBlock.querySelector('input[name^="luwipress_ai_model_"]:checked');
					if (selectedRadio) {
						hiddenModel.value = selectedRadio.value;
					} else if (fallback) {
						hiddenModel.value = fallback;
						// Auto-check the default radio so the UI reflects the fallback.
						var defaultRadio = activeBlock.querySelector('input[name^="luwipress_ai_model_"][value="' + fallback + '"]');
						if (defaultRadio) defaultRadio.checked = true;
					}
				}

				pills.forEach(function (input) {
					input.addEventListener('change', function () {
						if (this.checked) syncBlocks(this.value);
					});
				});

				// Any model radio change anywhere → resync hidden field.
				document.querySelectorAll('input[name^="luwipress_ai_model_"]').forEach(function (r) {
					r.addEventListener('change', syncHiddenModel);
				});

				// Preset dropdown → toggle base URL row.
				var presetEl = document.getElementById('luwipress_oai_compat_preset');
				var customRow = document.querySelector('.lp-oai-compat-custom-row');
				if (presetEl && customRow) {
					presetEl.addEventListener('change', function () {
						customRow.hidden = this.value !== 'custom';
					});
				}

				// Ensure hidden model is correct on initial load (fallback if saved model
				// doesn't match active provider's catalog).
				syncHiddenModel();
			})();
			</script>

			<!-- Cost & Limits (model selection lives inside the provider card above) -->
			<div class="luwipress-card">
				<h2><span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e( 'Cost &amp; Limits', 'luwipress' ); ?></h2>
				<p class="description" style="margin:-8px 0 16px;">
					<?php esc_html_e( 'Daily cap protects you from surprise bills; max output tokens caps the length of each AI response.', 'luwipress' ); ?>
				</p>
				<table class="form-table">
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
								<div style="display:inline-block;width:200px;height:6px;background:var(--lp-border);border-radius:3px;overflow:hidden;vertical-align:middle;">
									<div style="height:100%;width:<?php echo (int) $pct; ?>%;background:<?php echo $pct >= 90 ? 'var(--lp-error,#dc2626)' : ( $pct >= 70 ? 'var(--lp-warning)' : 'var(--lp-success)' ); ?>;"></div>
								</div>
								<span style="font-size:12px;color:var(--lp-text-secondary);margin-left:8px;">
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

		</div>

		<!-- AI CONTENT -->
		<div class="luwipress-tab-content <?php echo 'ai' === $active_tab ? 'tab-active' : ''; ?>" id="tab-ai">
			<?php if ( 'luwipress-native' === $seo_plugin ) : ?>
			<div class="luwipress-info-box">
				<span class="dashicons dashicons-yes-alt" style="color:var(--lp-success);"></span>
				<?php esc_html_e( 'LuwiPress is handling SEO directly — no third-party SEO plugin detected. AI-generated titles and meta descriptions are stored in LuwiPress meta keys and output in the site <head> automatically.', 'luwipress' ); ?>
			</div>
			<?php elseif ( 'none' !== $seo_plugin ) : ?>
			<div class="luwipress-info-box">
				<span class="dashicons dashicons-yes-alt" style="color:var(--lp-success);"></span>
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
							<?php
							// Fallback dictionary for display names — used when we need a label for any code.
							$lang_names = array(
								'tr' => 'Turkish', 'en' => 'English', 'de' => 'German', 'fr' => 'French',
								'ar' => 'Arabic',  'es' => 'Spanish', 'it' => 'Italian', 'nl' => 'Dutch',
								'ru' => 'Russian', 'ja' => 'Japanese', 'zh' => 'Chinese',  'pt' => 'Portuguese',
								'pl' => 'Polish',  'sv' => 'Swedish',  'fi' => 'Finnish',  'da' => 'Danish',
								'no' => 'Norwegian', 'cs' => 'Czech',  'el' => 'Greek',    'he' => 'Hebrew',
								'ko' => 'Korean',  'hi' => 'Hindi',    'uk' => 'Ukrainian', 'ro' => 'Romanian',
								'hu' => 'Hungarian', 'bg' => 'Bulgarian', 'sk' => 'Slovak', 'hr' => 'Croatian',
							);

							$lang_source  = 'fallback';
							$lang_options = array();
							$t_detected   = null;

							if ( class_exists( 'LuwiPress_Plugin_Detector' ) ) {
								$t_detected = LuwiPress_Plugin_Detector::get_instance()->detect_translation();
								if ( 'none' !== ( $t_detected['plugin'] ?? 'none' ) && ! empty( $t_detected['active_languages'] ) ) {
									$lang_source = $t_detected['plugin'];
									foreach ( (array) $t_detected['active_languages'] as $code ) {
										$code = strtolower( substr( (string) $code, 0, 2 ) );
										if ( '' === $code || isset( $lang_options[ $code ] ) ) {
											continue;
										}
										$lang_options[ $code ] = $lang_names[ $code ] ?? strtoupper( $code );
									}
								}
							}

							if ( empty( $lang_options ) ) {
								$lang_options = array(
									'en' => 'English', 'tr' => 'Turkish', 'de' => 'German', 'fr' => 'French',
									'ar' => 'Arabic',  'es' => 'Spanish', 'it' => 'Italian', 'nl' => 'Dutch',
									'ru' => 'Russian', 'ja' => 'Japanese', 'zh' => 'Chinese',
								);
							}

							// If the saved target is not in the detected list, show it as a ghost option so
							// the user can see the mismatch and switch. Save handler will snap it to a valid value.
							$saved_lang_missing = ! isset( $lang_options[ $target_language ] );
							?>
							<select id="luwipress_target_language" name="luwipress_target_language">
								<?php foreach ( $lang_options as $code => $name ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $target_language, $code ); ?>><?php echo esc_html( $name . ' (' . $code . ')' ); ?></option>
								<?php endforeach; ?>
								<?php if ( $saved_lang_missing && ! empty( $target_language ) ) : ?>
									<option value="<?php echo esc_attr( $target_language ); ?>" selected disabled>
										<?php echo esc_html( ( $lang_names[ $target_language ] ?? strtoupper( $target_language ) ) . ' (' . $target_language . ') — not active on site' ); ?>
									</option>
								<?php endif; ?>
							</select>

							<?php if ( 'fallback' !== $lang_source ) : ?>
								<span class="badge-active" style="margin-left:8px;">
									<?php
									/* translators: 1: translation plugin name (e.g. WPML, Polylang, TranslatePress), 2: number of active languages */
									printf(
										esc_html__( 'Detected from %1$s — %2$d active language(s)', 'luwipress' ),
										esc_html( strtoupper( $lang_source ) ),
										count( $lang_options )
									);
									?>
								</span>
								<?php if ( ! empty( $t_detected['default_language'] ) ) : ?>
									<p class="description">
										<?php
										/* translators: 1: translation plugin name, 2: default language code */
										printf(
											esc_html__( '%1$s default language: %2$s. AI-generated content will be written in the language selected above.', 'luwipress' ),
											esc_html( strtoupper( $lang_source ) ),
											'<code>' . esc_html( $t_detected['default_language'] ) . '</code>'
										);
										?>
									</p>
								<?php endif; ?>
								<?php if ( $saved_lang_missing ) : ?>
									<p class="description" style="color:var(--lp-warning);">
										<span class="dashicons dashicons-warning" style="color:var(--lp-warning);"></span>
										<?php
										/* translators: %s: currently-saved language code */
										printf(
											esc_html__( 'The saved language (%s) is not active in your translation plugin. Pick an active language above — saving will snap to a valid one automatically.', 'luwipress' ),
											'<code>' . esc_html( $target_language ) . '</code>'
										);
										?>
									</p>
								<?php endif; ?>
							<?php else : ?>
								<p class="description">
									<?php esc_html_e( 'No translation plugin detected — showing the default language list. Install WPML, Polylang, or TranslatePress to tailor this list to your site.', 'luwipress' ); ?>
								</p>
							<?php endif; ?>
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

			<?php if ( class_exists( 'LuwiPress_Health_Score' ) ) :
				$wc_targets = LuwiPress_Health_Score::get_word_count_targets();
			?>
			<div class="luwipress-card">
				<h3><?php esc_html_e( 'Word Count Targets (per content type)', 'luwipress' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Drives the Content Depth pillar of the Content Health score and the Content Audit → Word Count tab. Posts whose body falls within [min, max] count as on-band; below min is thin, above max is bloat.', 'luwipress' ); ?>
				</p>
				<table class="widefat striped" style="margin-top:8px;max-width:780px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Content type', 'luwipress' ); ?></th>
							<th style="width:110px;"><?php esc_html_e( 'Min', 'luwipress' ); ?></th>
							<th style="width:110px;"><?php esc_html_e( 'Target', 'luwipress' ); ?></th>
							<th style="width:110px;"><?php esc_html_e( 'Max', 'luwipress' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $wc_targets as $cpt => $band ) : ?>
							<tr>
								<td>
									<strong><?php echo esc_html( $band['label'] ); ?></strong><br>
									<code style="font-size:11px;color:#666;"><?php echo esc_html( $cpt ); ?></code>
								</td>
								<td>
									<input type="number" min="0" max="10000" step="50"
										name="luwipress_word_count_targets[<?php echo esc_attr( $cpt ); ?>][min]"
										value="<?php echo esc_attr( (string) $band['min'] ); ?>"
										style="width:90px;" />
								</td>
								<td>
									<input type="number" min="0" max="10000" step="50"
										name="luwipress_word_count_targets[<?php echo esc_attr( $cpt ); ?>][target]"
										value="<?php echo esc_attr( (string) $band['target'] ); ?>"
										style="width:90px;" />
								</td>
								<td>
									<input type="number" min="0" max="10000" step="50"
										name="luwipress_word_count_targets[<?php echo esc_attr( $cpt ); ?>][max]"
										value="<?php echo esc_attr( (string) $band['max'] ); ?>"
										style="width:90px;" />
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description" style="margin-top:8px;">
					<?php esc_html_e( 'Reference bands (Tapadum SEO writing guide §1.10): Product 500-650-800, Blog post 1200-1500-2200, Static page 300-400-600. Per-vertical defaults can be filtered via', 'luwipress' ); ?>
					<code>luwipress_word_count_targets</code>.
				</p>
			</div>
			<?php endif; ?>
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
								<span style="color:var(--lp-text-secondary);"><?php esc_html_e( 'No translation plugin detected. Install WPML or Polylang to enable multilingual support.', 'luwipress' ); ?></span>
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

		<!-- MARKETPLACES — moved to LuwiPress Marketplace Sync companion (3.1.44) -->

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

		<?php
		// ============================ BOT TAB (Account Cleaner + Shield) ============================
		// Uses its own REST endpoints — fields below carry no `name=` so they do NOT
		// participate in the main form POST. Independent JS handler at the bottom.
		$bot_cleaner_ready = class_exists( 'LuwiPress_Bot_Account_Cleaner' );
		$bot_shield_ready  = class_exists( 'LuwiPress_Bot_Shield' );
		$bot_rest_nonce    = '';
		$bot_rest_acct     = '';
		$bot_rest_sh       = '';
		if ( $bot_cleaner_ready && $bot_shield_ready ) :
			$bot_acct_s     = LuwiPress_Bot_Account_Cleaner::get_instance()->get_settings();
			$bot_sh_s       = LuwiPress_Bot_Shield::get_instance()->get_settings();
			$bot_sh_stats   = LuwiPress_Bot_Shield::get_instance()->get_stats();
			$bot_rest_nonce = wp_create_nonce( 'wp_rest' );
			$bot_rest_acct  = esc_url_raw( rest_url( 'luwipress/v1/bot-accounts/' ) );
			$bot_rest_sh    = esc_url_raw( rest_url( 'luwipress/v1/bot-shield/' ) );
		?>
		<div class="luwipress-tab-content <?php echo 'bot' === $active_tab ? 'tab-active' : ''; ?>" id="tab-bot">
			<div class="luwipress-card">
				<h2><span class="dashicons dashicons-admin-users" style="vertical-align:middle;"></span> <?php esc_html_e( 'Account detection', 'luwipress' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Score-based fake-user detection. Lower threshold = more aggressive flagging.', 'luwipress' ); ?>
				</p>
				<table class="form-table">
					<tr>
						<th><label for="lwp-threshold"><?php esc_html_e( 'Score threshold', 'luwipress' ); ?></label></th>
						<td>
							<input type="number" id="lwp-threshold" min="0" max="100" value="<?php echo (int) $bot_acct_s['threshold']; ?>" />
							<p class="description"><?php esc_html_e( 'Accounts with score ≥ this value are flagged. Range 0–100.', 'luwipress' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="lwp-min-age"><?php esc_html_e( 'Minimum age (days)', 'luwipress' ); ?></label></th>
						<td>
							<input type="number" id="lwp-min-age" min="0" max="3650" value="<?php echo (int) $bot_acct_s['min_age_days']; ?>" />
							<p class="description"><?php esc_html_e( 'Accounts younger than this get a grace period before zero-activity penalties apply.', 'luwipress' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="lwp-batch"><?php esc_html_e( 'Scan batch size', 'luwipress' ); ?></label></th>
						<td>
							<input type="number" id="lwp-batch" min="50" max="5000" value="<?php echo (int) $bot_acct_s['scan_batch_size']; ?>" />
						</td>
					</tr>
				</table>
				<p>
					<button type="button" id="lwp-bot-acct-save" class="button button-primary"><?php esc_html_e( 'Save account settings', 'luwipress' ); ?></button>
					<span id="lwp-bot-acct-status" style="margin-left:12px; color:var(--lp-text-muted);"></span>
				</p>
			</div>

			<div class="luwipress-card">
				<h2><span class="dashicons dashicons-shield" style="vertical-align:middle;"></span> <?php esc_html_e( 'Shield rules', 'luwipress' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Enable shield', 'luwipress' ); ?></th>
						<td><label><input type="checkbox" id="bs-enabled" <?php checked( $bot_sh_s['enabled'] ); ?> /> <?php esc_html_e( 'Activate edge filter on every front-end request.', 'luwipress' ); ?></label></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Block scraper user-agents', 'luwipress' ); ?></th>
						<td><label><input type="checkbox" id="bs-ua" <?php checked( $bot_sh_s['block_ua_scrapers'] ); ?> /> <?php esc_html_e( 'Deny requests matching the UA blocklist below.', 'luwipress' ); ?></label></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Rate limit', 'luwipress' ); ?></th>
						<td>
							<label><input type="checkbox" id="bs-rl" <?php checked( $bot_sh_s['rate_limit_enabled'] ); ?> /> <?php esc_html_e( 'Throttle login + xmlrpc + REST users.', 'luwipress' ); ?></label><br>
							<label style="margin-top:8px; display:inline-block;">
								<?php esc_html_e( 'Threshold:', 'luwipress' ); ?>
								<input type="number" id="bs-rl-thresh" value="<?php echo (int) $bot_sh_s['rate_limit_threshold']; ?>" min="1" max="10000" style="width:90px;" />
								<?php esc_html_e( 'requests in', 'luwipress' ); ?>
								<input type="number" id="bs-rl-window" value="<?php echo (int) $bot_sh_s['rate_limit_window']; ?>" min="1" max="3600" style="width:90px;" />
								<?php esc_html_e( 'seconds', 'luwipress' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Block duration', 'luwipress' ); ?></th>
						<td><input type="number" id="bs-ttl" value="<?php echo (int) $bot_sh_s['block_ttl_minutes']; ?>" min="1" max="43200" style="width:120px;" /> <?php esc_html_e( 'minutes', 'luwipress' ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'REST user enumeration', 'luwipress' ); ?></th>
						<td><label><input type="checkbox" id="bs-enum" <?php checked( $bot_sh_s['block_user_enumeration'] ); ?> /> <?php esc_html_e( 'Block /wp-json/wp/v2/users and ?author=N for anonymous callers.', 'luwipress' ); ?></label></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'XML-RPC', 'luwipress' ); ?></th>
						<td><label><input type="checkbox" id="bs-xmlrpc" <?php checked( $bot_sh_s['disable_xmlrpc'] ); ?> /> <?php esc_html_e( 'Fully disable XML-RPC + pingback multicall.', 'luwipress' ); ?></label></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Honeypot trap', 'luwipress' ); ?></th>
						<td><label><input type="checkbox" id="bs-honey" <?php checked( $bot_sh_s['honeypot_enabled'] ); ?> /> <?php esc_html_e( 'Auto-ban any IP that probes common vulnerability paths (24h).', 'luwipress' ); ?></label></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Verify search engines', 'luwipress' ); ?></th>
						<td><label><input type="checkbox" id="bs-verify-se" <?php checked( $bot_sh_s['verify_search_engines'] ); ?> /> <?php esc_html_e( 'Reverse-DNS verify Googlebot / Bingbot (defeats UA spoofing).', 'luwipress' ); ?></label></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'UA blocklist', 'luwipress' ); ?></th>
						<td><textarea id="bs-ua-list" rows="6" class="large-text code"><?php echo esc_textarea( implode( "\n", (array) $bot_sh_s['ua_blocklist'] ) ); ?></textarea><p class="description"><?php esc_html_e( 'One UA substring per line.', 'luwipress' ); ?></p></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Sensitive paths', 'luwipress' ); ?></th>
						<td><textarea id="bs-sensitive" rows="4" class="large-text code"><?php echo esc_textarea( implode( "\n", (array) $bot_sh_s['sensitive_paths'] ) ); ?></textarea></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Honeypot paths', 'luwipress' ); ?></th>
						<td><textarea id="bs-honey-paths" rows="4" class="large-text code"><?php echo esc_textarea( implode( "\n", (array) $bot_sh_s['honeypot_paths'] ) ); ?></textarea></td>
					</tr>
				</table>
				<p>
					<button type="button" id="bs-save" class="button button-primary"><?php esc_html_e( 'Save shield settings', 'luwipress' ); ?></button>
					<span id="bs-status" style="margin-left:12px; color:var(--lp-text-muted);"></span>
				</p>
			</div>

			<div class="luwipress-card">
				<h2><?php esc_html_e( 'Allowlist', 'luwipress' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Entries here bypass every shield check. Add your office IP or a trusted partner UA.', 'luwipress' ); ?></p>
				<div style="display:flex; gap:8px; align-items:center;">
					<select id="bs-allow-type"><option value="ip">IP</option><option value="ua">UA</option></select>
					<input id="bs-allow-value" type="text" placeholder="<?php esc_attr_e( 'value', 'luwipress' ); ?>" />
					<button type="button" id="bs-allow-add" class="button"><?php esc_html_e( 'Add', 'luwipress' ); ?></button>
				</div>
				<div style="margin-top:12px;">
					<strong>IPs:</strong>
					<?php foreach ( (array) $bot_sh_stats['allowlist']['ips'] as $ip ) : ?>
						<span class="lp-pill pill-success" style="margin:2px;"><?php echo esc_html( $ip ); ?> <a href="#" data-allow-remove data-type="ip" data-value="<?php echo esc_attr( $ip ); ?>" style="margin-left:6px; text-decoration:none;">×</a></span>
					<?php endforeach; ?>
					<br><strong style="margin-top:8px; display:inline-block;">UAs:</strong>
					<?php foreach ( (array) $bot_sh_stats['allowlist']['uas'] as $ua ) : ?>
						<span class="lp-pill pill-success" style="margin:2px;"><?php echo esc_html( $ua ); ?> <a href="#" data-allow-remove data-type="ua" data-value="<?php echo esc_attr( $ua ); ?>" style="margin-left:6px; text-decoration:none;">×</a></span>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="luwipress-card">
				<h2><?php esc_html_e( 'Safety invariants', 'luwipress' ); ?></h2>
				<ul style="list-style:disc; padding-left:24px;">
					<li><?php esc_html_e( 'Administrators, editors, shop managers, authors, and contributors are NEVER scored.', 'luwipress' ); ?></li>
					<li><?php esc_html_e( 'Any user with at least one WooCommerce order is automatically excluded.', 'luwipress' ); ?></li>
					<li><?php esc_html_e( 'Whitelisted users are hidden from scans permanently until removed.', 'luwipress' ); ?></li>
					<li><?php esc_html_e( 'Deleted accounts have their content reassigned to user ID 1.', 'luwipress' ); ?></li>
					<li><?php esc_html_e( 'Shield never blocks RFC1918 (LAN) IPs or logged-in administrators.', 'luwipress' ); ?></li>
				</ul>
			</div>
		</div>
		<?php endif; /* bot tab guard */ ?>

		<!-- CONTENT HEALTH -->
		<div class="luwipress-tab-content <?php echo 'content-health' === $active_tab ? 'tab-active' : ''; ?>" id="tab-content-health">
			<?php
			$health_ready = class_exists( 'LuwiPress_Health_Score' );
			if ( $health_ready ) :
				$health         = LuwiPress_Health_Score::get_instance();
				$health_pillars = $health->get_pillars();
				$health_score   = $health->compute();
				$overall        = isset( $health_score['overall'] ) ? (int) $health_score['overall'] : 0;
				$overall_band   = $overall >= 80 ? 'good' : ( $overall >= 50 ? 'warn' : 'bad' );
				$measured_index = array();
				foreach ( ( $health_score['pillars'] ?? array() ) as $mp ) {
					if ( isset( $mp['key'] ) ) {
						$measured_index[ $mp['key'] ] = $mp;
					}
				}
			endif;
			?>

			<div class="luwipress-card">
				<h2><?php esc_html_e( 'Content Health Score', 'luwipress' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'A single 0-100 number that summarises store content quality. Configure how much each pillar contributes below — the KG Store Health hero, Action Queue cards, and achievement badges all read from this rubric.', 'luwipress' ); ?>
				</p>

				<?php if ( ! $health_ready ) : ?>
					<p><em><?php esc_html_e( 'Health Score module not loaded. Reload after upgrade.', 'luwipress' ); ?></em></p>
				<?php else : ?>

					<!-- Live score preview -->
					<div class="luwipress-health-preview" style="display:flex;align-items:center;gap:24px;padding:16px;border:1px solid #ddd;border-radius:8px;margin:12px 0;background:#fafafa;">
						<div style="text-align:center;min-width:120px;">
							<div style="font-size:48px;font-weight:700;line-height:1;color:<?php echo $overall_band === 'good' ? '#2c7a2c' : ( $overall_band === 'warn' ? '#a86b00' : '#c33'); ?>;">
								<?php echo (int) $overall; ?><span style="font-size:18px;color:#999;font-weight:400;">%</span>
							</div>
							<div style="font-size:12px;color:#666;margin-top:4px;">
								<?php echo esc_html( $overall_band === 'good' ? __( 'Healthy', 'luwipress' ) : ( $overall_band === 'warn' ? __( 'Needs work', 'luwipress' ) : __( 'Critical', 'luwipress' ) ) ); ?>
							</div>
						</div>
						<div style="flex:1;">
							<p style="margin:0 0 8px 0;font-size:13px;color:#444;">
								<?php
								/* translators: %s = ISO date of last compute */
								printf(
									esc_html__( 'Last computed: %s. Cached for 15 minutes; saving below recalculates immediately.', 'luwipress' ),
									esc_html( ! empty( $health_score['computed_at'] ) ? wp_date( 'Y-m-d H:i', (int) $health_score['computed_at'] ) : '—' )
								);
								?>
							</p>
							<div style="display:flex;flex-wrap:wrap;gap:6px;">
								<?php foreach ( ( $health_score['pillars'] ?? array() ) as $mp ) :
									if ( ! is_array( $mp ) || empty( $mp['key'] ) ) continue;
									if ( $mp['value'] === null ) continue;
									$band = $mp['status'] ?? 'warn';
									$bcolor = $band === 'good' ? '#2c7a2c' : ( $band === 'warn' ? '#a86b00' : '#c33' );
									?>
									<span style="display:inline-flex;align-items:center;gap:6px;padding:4px 10px;background:#fff;border:1px solid #ddd;border-radius:999px;font-size:12px;">
										<strong><?php echo esc_html( $mp['label'] ); ?></strong>
										<span style="color:<?php echo esc_attr( $bcolor ); ?>;font-weight:600;"><?php echo (int) round( (float) $mp['value'] ); ?>%</span>
									</span>
								<?php endforeach; ?>
							</div>
						</div>
					</div>

					<!-- Pillar configuration table -->
					<table class="widefat striped" style="margin-top:12px;">
						<thead>
							<tr>
								<th style="width:24px;"></th>
								<th><?php esc_html_e( 'Pillar', 'luwipress' ); ?></th>
								<th style="width:80px;text-align:center;"><?php esc_html_e( 'Current', 'luwipress' ); ?></th>
								<th style="width:120px;"><?php esc_html_e( 'Weight (%)', 'luwipress' ); ?></th>
								<th style="width:120px;"><?php esc_html_e( 'Target (%)', 'luwipress' ); ?></th>
								<th style="width:160px;"><?php esc_html_e( 'Action threshold (%)', 'luwipress' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $health_pillars as $pkey => $pdef ) :
								$measured = $measured_index[ $pkey ] ?? null;
								$current  = ( $measured && $measured['value'] !== null ) ? (int) round( (float) $measured['value'] ) : null;
								$cband    = $measured['status'] ?? 'n_a';
								$ccolor   = $cband === 'good' ? '#2c7a2c' : ( $cband === 'warn' ? '#a86b00' : ( $cband === 'bad' ? '#c33' : '#999' ) );
								?>
								<tr>
									<td>
										<input type="checkbox"
											name="luwipress_health_pillars[<?php echo esc_attr( $pkey ); ?>][enabled]"
											value="1"
											<?php checked( ! empty( $pdef['enabled'] ) ); ?> />
									</td>
									<td>
										<strong><?php echo esc_html( $pdef['label'] ); ?></strong><br>
										<span class="description" style="font-size:12px;color:#666;"><?php echo esc_html( $pdef['description'] ); ?></span>
									</td>
									<td style="text-align:center;">
										<?php if ( $current !== null ) : ?>
											<span style="color:<?php echo esc_attr( $ccolor ); ?>;font-weight:600;"><?php echo (int) $current; ?>%</span>
										<?php else : ?>
											<span style="color:#999;">—</span>
										<?php endif; ?>
									</td>
									<td>
										<input type="number" min="0" max="100" step="1"
											name="luwipress_health_pillars[<?php echo esc_attr( $pkey ); ?>][weight]"
											value="<?php echo esc_attr( $pdef['weight'] ); ?>"
											style="width:80px;" />
									</td>
									<td>
										<input type="number" min="0" max="100" step="1"
											name="luwipress_health_pillars[<?php echo esc_attr( $pkey ); ?>][target]"
											value="<?php echo esc_attr( $pdef['target'] ); ?>"
											style="width:80px;" />
									</td>
									<td>
										<input type="number" min="0" max="100" step="1"
											name="luwipress_health_pillars[<?php echo esc_attr( $pkey ); ?>][action_threshold]"
											value="<?php echo esc_attr( $pdef['action_threshold'] ); ?>"
											style="width:80px;" />
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<p class="description" style="margin-top:12px;">
						<?php esc_html_e( 'Weights are relative — they get renormalised when computing the overall score. Disabling a pillar removes it from the formula entirely. Target = good tier (badge gold). Action threshold = below this surfaces an Action Queue card.', 'luwipress' ); ?>
					</p>

					<p style="margin-top:16px;">
						<label style="display:inline-flex;align-items:center;gap:6px;color:#c33;">
							<input type="checkbox" name="luwipress_health_reset" value="1" />
							<?php esc_html_e( 'Reset all pillar overrides to defaults on save', 'luwipress' ); ?>
						</label>
					</p>

				<?php endif; ?>
			</div>
		</div>

		<?php
		/**
		 * Companion plugins can hook this to inject their own tab content. The
		 * hooked function should echo a wrapper like:
		 *   <div class="luwipress-tab-content <?php echo 'foo' === $active_tab ? 'tab-active' : ''; ?>" id="tab-foo">…</div>
		 * Inputs inside should NOT carry `name=` (they are not part of the main
		 * settings POST — companions must persist via their own AJAX/REST flow
		 * with `type="button"` save controls, same pattern the Bot tab uses).
		 *
		 * @param string $active_tab Currently selected tab id.
		 * @since 3.2.4
		 */
		do_action( 'luwipress_settings_render_tab_content', $active_tab );
		?>

		<p class="submit">
			<input type="submit" name="luwipress_save_settings" class="button-primary" value="<?php esc_attr_e( 'Save Settings', 'luwipress' ); ?>" />
		</p>
	</form>

	<?php if ( $bot_cleaner_ready && $bot_shield_ready ) : ?>
	<script>
	(function () {
		var REST_ACCT = <?php echo wp_json_encode( $bot_rest_acct ); ?>;
		var REST_SH   = <?php echo wp_json_encode( $bot_rest_sh ); ?>;
		var NONCE     = <?php echo wp_json_encode( $bot_rest_nonce ); ?>;

		function api(root, path, opts) {
			opts = opts || {};
			opts.headers = Object.assign({ 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE }, opts.headers || {});
			opts.credentials = 'same-origin';
			return fetch(root + path, opts).then(function (r) {
				return r.json().then(function (data) { return { ok: r.ok, data: data }; });
			});
		}

		var acctBtn = document.getElementById('lwp-bot-acct-save');
		if (acctBtn) acctBtn.addEventListener('click', function () {
			var status = document.getElementById('lwp-bot-acct-status');
			var payload = {
				threshold:       parseInt(document.getElementById('lwp-threshold').value, 10),
				min_age_days:    parseInt(document.getElementById('lwp-min-age').value, 10),
				scan_batch_size: parseInt(document.getElementById('lwp-batch').value, 10)
			};
			status.textContent = '<?php echo esc_js( __( 'Saving…', 'luwipress' ) ); ?>';
			api(REST_ACCT, 'settings', { method: 'POST', body: JSON.stringify(payload) }).then(function (r) {
				status.textContent = r.ok ? '<?php echo esc_js( __( 'Saved.', 'luwipress' ) ); ?>' : '<?php echo esc_js( __( 'Save failed.', 'luwipress' ) ); ?>';
			});
		});

		var shBtn = document.getElementById('bs-save');
		if (shBtn) shBtn.addEventListener('click', function () {
			var p = {
				enabled:                document.getElementById('bs-enabled').checked,
				block_ua_scrapers:      document.getElementById('bs-ua').checked,
				rate_limit_enabled:     document.getElementById('bs-rl').checked,
				rate_limit_threshold:   parseInt(document.getElementById('bs-rl-thresh').value, 10),
				rate_limit_window:      parseInt(document.getElementById('bs-rl-window').value, 10),
				block_ttl_minutes:      parseInt(document.getElementById('bs-ttl').value, 10),
				block_user_enumeration: document.getElementById('bs-enum').checked,
				disable_xmlrpc:         document.getElementById('bs-xmlrpc').checked,
				honeypot_enabled:       document.getElementById('bs-honey').checked,
				verify_search_engines:  document.getElementById('bs-verify-se').checked,
				ua_blocklist:    document.getElementById('bs-ua-list').value.split('\n').map(function (s) { return s.trim(); }).filter(Boolean),
				sensitive_paths: document.getElementById('bs-sensitive').value.split('\n').map(function (s) { return s.trim(); }).filter(Boolean),
				honeypot_paths:  document.getElementById('bs-honey-paths').value.split('\n').map(function (s) { return s.trim(); }).filter(Boolean)
			};
			document.getElementById('bs-status').textContent = '<?php echo esc_js( __( 'Saving…', 'luwipress' ) ); ?>';
			api(REST_SH, 'settings', { method: 'POST', body: JSON.stringify(p) }).then(function (r) {
				document.getElementById('bs-status').textContent = r.ok ? '<?php echo esc_js( __( 'Saved.', 'luwipress' ) ); ?>' : '<?php echo esc_js( __( 'Save failed.', 'luwipress' ) ); ?>';
			});
		});

		var allowAdd = document.getElementById('bs-allow-add');
		if (allowAdd) allowAdd.addEventListener('click', function () {
			var t = document.getElementById('bs-allow-type').value;
			var v = document.getElementById('bs-allow-value').value;
			if (!v) return;
			api(REST_SH, 'allowlist', { method: 'POST', body: JSON.stringify({ type: t, value: v, action: 'add' }) }).then(function () { location.reload(); });
		});

		document.addEventListener('click', function (e) {
			if (e.target && e.target.getAttribute && e.target.getAttribute('data-allow-remove') !== null) {
				e.preventDefault();
				var t = e.target.getAttribute('data-type'); var v = e.target.getAttribute('data-value');
				api(REST_SH, 'allowlist', { method: 'POST', body: JSON.stringify({ type: t, value: v, action: 'remove' }) }).then(function () { location.reload(); });
			}
		});
	})();
	</script>
	<?php endif; ?>
</div>
