<?php
/**
 * n8nPress Open Claw — AI Assistant Admin Page
 *
 * @package n8nPress
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$current_user = wp_get_current_user();
?>

<div class="wrap n8npress-claw-wrap">
	<h1 class="n8npress-title">
		<span class="dashicons dashicons-superhero-alt"></span>
		Open Claw
		<span class="n8npress-version">AI Assistant</span>
	</h1>

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
				<h4><span class="dashicons dashicons-admin-site-alt3"></span> Connected Channels</h4>
				<?php
				$tg_token    = get_option( 'n8npress_telegram_bot_token', '' );
				$tg_admins   = get_option( 'n8npress_telegram_admin_ids', '' );
				$wa_number   = get_option( 'n8npress_whatsapp_number', '' );
				$wa_admins   = get_option( 'n8npress_whatsapp_admin_ids', '' );
				?>
				<div class="claw-channels-list">
					<div class="claw-channel-item">
						<span class="dashicons dashicons-laptop" style="color:#6366f1;"></span>
						<span>Admin Panel</span>
						<span class="claw-channel-status claw-status-active">Active</span>
					</div>
					<div class="claw-channel-item">
						<span class="dashicons dashicons-format-chat" style="color:#229ED9;"></span>
						<span>Telegram</span>
						<?php if ( ! empty( $tg_token ) && ! empty( $tg_admins ) ) : ?>
							<span class="claw-channel-status claw-status-active">Connected</span>
						<?php else : ?>
							<span class="claw-channel-status claw-status-inactive">Not configured</span>
						<?php endif; ?>
					</div>
					<div class="claw-channel-item">
						<span class="dashicons dashicons-phone" style="color:#25D366;"></span>
						<span>WhatsApp</span>
						<?php if ( ! empty( $wa_number ) && ! empty( $wa_admins ) ) : ?>
							<span class="claw-channel-status claw-status-active">Connected</span>
						<?php else : ?>
							<span class="claw-channel-status claw-status-inactive">Not configured</span>
						<?php endif; ?>
					</div>
				</div>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=n8npress-settings&tab=open-claw' ) ); ?>" class="claw-configure-link">
					Configure channels &rarr;
				</a>
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
