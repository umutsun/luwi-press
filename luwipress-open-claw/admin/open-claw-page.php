<?php
/**
 * LuwiPress Open Claw — AI Assistant Admin Page
 *
 * @package LuwiPress
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( __( 'You do not have permission to access this page.', 'luwipress' ) );
}

$current_user = wp_get_current_user();
$logo_url     = defined( 'LUWIPRESS_PLUGIN_URL' ) ? LUWIPRESS_PLUGIN_URL . 'assets/images/luwi-logo.png' : '';
$ai_provider  = get_option( 'luwipress_ai_provider', 'openai' );
$ai_model     = get_option( 'luwipress_ai_model', 'gpt-4o-mini' );
$provider_lbl = array( 'openai' => 'OpenAI', 'anthropic' => 'Anthropic', 'google' => 'Google AI', 'openai_compatible' => 'OpenAI-Compatible' );
$ai_label     = ( $provider_lbl[ $ai_provider ] ?? ucfirst( $ai_provider ) ) . ' · ' . $ai_model;
?>

<div class="wrap luwipress-admin luwipress-dashboard luwipress-claw-wrap">

	<!-- ═══ HEADER ═══ -->
	<div class="lp-header">
		<div class="lp-header-left">
			<h1 class="lp-title">
				<?php if ( $logo_url ) : ?>
					<img class="lp-logo" width="28" height="28" src="<?php echo esc_url( $logo_url ); ?>" alt="LuwiPress" />
				<?php else : ?>
					<span class="dashicons dashicons-superhero-alt" style="font-size:24px;width:24px;height:24px;color:var(--lp-primary);"></span>
				<?php endif; ?>
				<?php esc_html_e( 'Open Claw', 'luwipress' ); ?>
			</h1>
			<p class="lp-subtitle"><?php esc_html_e( 'AI assistant for managing WordPress and WooCommerce — admin-only conversations.', 'luwipress' ); ?></p>
		</div>
		<div class="lp-header-actions">
			<span class="lp-pill pill-success" title="<?php esc_attr_e( 'AI provider currently configured', 'luwipress' ); ?>">
				<span class="dashicons dashicons-admin-generic"></span> <?php echo esc_html( $ai_label ); ?>
			</span>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=luwipress' ) ); ?>"
			   class="lp-pill lp-pill--action pill-neutral lp-pill--icon"
			   title="<?php esc_attr_e( 'Back to LuwiPress Dashboard', 'luwipress' ); ?>">
				<span class="dashicons dashicons-admin-home"></span>
				<span class="screen-reader-text"><?php esc_html_e( 'Dashboard', 'luwipress' ); ?></span>
			</a>
		</div>
	</div>

	<div class="claw-layout">
		<!-- Chat Panel -->
		<div class="claw-chat-panel">
			<div class="claw-chat-header">
				<div class="claw-header-left">
					<span class="claw-status-dot"></span>
					<strong>Open Claw</strong>
					<span class="claw-model-label">AI-powered WP/WC assistant</span>
				</div>
				<div class="claw-header-actions">
					<button type="button" id="claw-new-chat" class="button button-small" title="New conversation">
						<span class="dashicons dashicons-plus-alt2"></span> New Chat
					</button>
				</div>
			</div>

			<div class="claw-messages" id="claw-messages">
				<div class="claw-welcome">
					<div class="claw-welcome-icon">
						<span class="dashicons dashicons-superhero-alt"></span>
					</div>
					<h3>Welcome, <?php echo esc_html( $current_user->display_name ); ?>!</h3>
					<p>I'm Open Claw, your AI assistant for managing WordPress and WooCommerce. Ask me anything about your store.</p>
					<div class="claw-suggestions">
						<button type="button" class="claw-suggestion" data-message="How many products have thin content?">
							<span class="dashicons dashicons-editor-paste-text"></span> Thin content report
						</button>
						<button type="button" class="claw-suggestion" data-message="Show me products that need translation">
							<span class="dashicons dashicons-translation"></span> Missing translations
						</button>
						<button type="button" class="claw-suggestion" data-message="What's the AEO coverage for my store?">
							<span class="dashicons dashicons-chart-bar"></span> AEO coverage
						</button>
						<button type="button" class="claw-suggestion" data-message="Show me recent negative reviews">
							<span class="dashicons dashicons-star-half"></span> Review sentiment
						</button>
						<button type="button" class="claw-suggestion" data-message="What plugins are detected on this site?">
							<span class="dashicons dashicons-admin-plugins"></span> Plugin environment
						</button>
						<button type="button" class="claw-suggestion" data-message="Enrich all thin content products">
							<span class="dashicons dashicons-admin-page"></span> Batch enrich
						</button>
					</div>
				</div>
			</div>

			<div class="claw-input-area">
				<div class="claw-input-wrapper">
					<textarea id="claw-input" placeholder="Ask Open Claw anything about your store..." rows="1"></textarea>
					<button type="button" id="claw-send" class="claw-send-btn" disabled>
						<span class="dashicons dashicons-arrow-up-alt"></span>
					</button>
				</div>
				<div class="claw-input-hints">
					<span>Press <kbd>Enter</kbd> to send, <kbd>Shift+Enter</kbd> for new line</span>
				</div>
			</div>
		</div>

		<!-- Sidebar -->
		<div class="claw-sidebar">
			<div class="claw-sidebar-section">
				<h4><span class="dashicons dashicons-info-outline"></span> Quick Actions</h4>
				<div class="claw-quick-actions">
					<button type="button" class="claw-action-btn" data-message="Run a content opportunity scan">
						<span class="dashicons dashicons-search"></span> Scan Opportunities
					</button>
					<button type="button" class="claw-action-btn" data-message="List stale content that needs updating">
						<span class="dashicons dashicons-calendar-alt"></span> Stale Content
					</button>
					<button type="button" class="claw-action-btn" data-message="Show products without SEO meta">
						<span class="dashicons dashicons-visibility"></span> Missing SEO Meta
					</button>
					<button type="button" class="claw-action-btn" data-message="Generate a blog post about our best-selling products">
						<span class="dashicons dashicons-edit"></span> Generate Content
					</button>
					<button type="button" class="claw-action-btn" data-message="Translate all untranslated products">
						<span class="dashicons dashicons-admin-site-alt3"></span> Translate Content
					</button>
				</div>
			</div>

			<div class="claw-sidebar-section">
				<h4><span class="dashicons dashicons-clock"></span> Capabilities</h4>
				<ul class="claw-capabilities-list">
					<li><span class="dashicons dashicons-yes-alt"></span> Product & content analytics</li>
					<li><span class="dashicons dashicons-yes-alt"></span> AI batch enrichment</li>
					<li><span class="dashicons dashicons-yes-alt"></span> Translation management</li>
					<li><span class="dashicons dashicons-yes-alt"></span> Review sentiment analysis</li>
					<li><span class="dashicons dashicons-yes-alt"></span> Content scheduling</li>
					<li><span class="dashicons dashicons-yes-alt"></span> SEO meta generation</li>
					<li><span class="dashicons dashicons-yes-alt"></span> Plugin environment info</li>
				</ul>
			</div>

			<div class="claw-sidebar-section">
				<h4><span class="dashicons dashicons-admin-site-alt3"></span> Runs in</h4>
				<div class="claw-channels-list">
					<div class="claw-channel-item">
						<span class="dashicons dashicons-laptop" style="color:var(--lp-primary);"></span>
						<span>Admin Panel</span>
						<span class="claw-channel-status claw-status-active">Active</span>
					</div>
				</div>
				<p class="description" style="margin-top:8px;font-size:11px;">Open Claw is admin-only. For front-end customer conversations use the Customer Chat widget.</p>
			</div>

			<div class="claw-sidebar-section claw-conversation-info" id="claw-conversation-info" style="display:none;">
				<h4><span class="dashicons dashicons-admin-comments"></span> Conversation</h4>
				<div class="claw-conv-meta">
					<span class="claw-conv-id" id="claw-conv-id"></span>
					<span class="claw-conv-count" id="claw-conv-count">0 messages</span>
				</div>
			</div>
		</div>
	</div>
</div>
