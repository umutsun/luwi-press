<?php
/**
 * LuwiPress Agentic — Admin Page
 *
 * Uniform chat surface + sidebar with backend runtime picker. Backend is
 * pluggable: Open Claw (oc.luwi.dev) and Hermes (hermes.luwi.dev) ship by
 * default; users can point either at a self-hosted endpoint.
 *
 * @package LuwiPress
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( __( 'You do not have permission to access this page.', 'luwipress' ) );
}

$current_user = wp_get_current_user();
$logo_url     = defined( 'LUWIPRESS_PLUGIN_URL' ) ? LUWIPRESS_PLUGIN_URL . 'assets/images/luwi-logo.png' : '';

$agent_host     = class_exists( 'LuwiPress_Agent_Host' ) ? LuwiPress_Agent_Host::get_instance() : null;
$active_adapter = $agent_host ? $agent_host->get_active_adapter() : null;
$active_label   = $active_adapter ? $active_adapter->get_label() : __( 'Open Claw', 'luwipress-agentic' );
$is_configured  = $active_adapter ? $active_adapter->is_configured() : false;
$settings_url   = admin_url( 'admin.php?page=luwipress-settings&tab=agentic' );

// ─── Hub area dispatch (1.3.2) ──────────────────────────────────────
// The Agentic page is now a two-area hub: "Agents" (the chat surface +
// Hermes / Open Claw runtime) and "Commerce" (Google UCP + AP2). Commerce
// only appears when its core-side modules loaded.
$lwp_commerce_available = class_exists( 'LuwiPress_UCP' );
$lwp_area_raw = isset( $_GET['area'] ) ? sanitize_key( wp_unslash( $_GET['area'] ) ) : 'agents';
$lwp_area     = ( 'commerce' === $lwp_area_raw && $lwp_commerce_available ) ? 'commerce' : 'agents';

$lwp_area_tabs = array(
	'agents' => array( 'label' => __( 'Agents', 'luwipress-agentic' ), 'icon' => 'dashicons-superhero-alt' ),
);
if ( $lwp_commerce_available ) {
	$lwp_area_tabs['commerce'] = array( 'label' => __( 'Commerce', 'luwipress-agentic' ), 'icon' => 'dashicons-cart' );
}
$lwp_area_url = function ( $area ) {
	return esc_url( add_query_arg( array( 'page' => 'luwipress-agentic', 'area' => $area ), admin_url( 'admin.php' ) ) );
};
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
				<?php esc_html_e( 'Agentic', 'luwipress' ); ?>
			</h1>
			<p class="lp-subtitle"><?php esc_html_e( 'Uniform admin chat surface. Pluggable agent backend — Open Claw, Hermes, or your own endpoint.', 'luwipress' ); ?></p>
		</div>
		<div class="lp-header-actions">
			<span class="lp-pill <?php echo $is_configured ? 'pill-success' : 'pill-warning'; ?>" id="agentic-active-pill" title="<?php esc_attr_e( 'Active agent backend', 'luwipress-agentic' ); ?>">
				<span class="dashicons dashicons-superhero-alt"></span> <?php echo esc_html( $active_label ); ?>
				<?php if ( ! $is_configured ) : ?>
					— <?php esc_html_e( 'token needed', 'luwipress-agentic' ); ?>
				<?php endif; ?>
			</span>
			<a href="<?php echo esc_url( $settings_url ); ?>"
			   class="lp-pill lp-pill--action pill-neutral"
			   title="<?php esc_attr_e( 'Configure backend runtime', 'luwipress-agentic' ); ?>">
				<span class="dashicons dashicons-admin-generic"></span>
				<?php esc_html_e( 'Settings', 'luwipress-agentic' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=luwipress' ) ); ?>"
			   class="lp-pill lp-pill--action pill-neutral lp-pill--icon"
			   title="<?php esc_attr_e( 'Back to LuwiPress Dashboard', 'luwipress-agentic' ); ?>">
				<span class="dashicons dashicons-admin-home"></span>
				<span class="screen-reader-text"><?php esc_html_e( 'Dashboard', 'luwipress-agentic' ); ?></span>
			</a>
		</div>
	</div>

	<?php if ( $lwp_commerce_available ) : ?>
	<nav class="lp-hub-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Agentic areas', 'luwipress-agentic' ); ?>">
		<?php foreach ( $lwp_area_tabs as $area_key => $area_meta ) :
			$is_act = ( $area_key === $lwp_area );
			?>
			<a class="lp-hub-tab<?php echo $is_act ? ' lp-hub-tab--active' : ''; ?>"
			   href="<?php echo $lwp_area_url( $area_key ); // phpcs:ignore ?>"
			   role="tab" aria-selected="<?php echo $is_act ? 'true' : 'false'; ?>">
				<span class="dashicons <?php echo esc_attr( $area_meta['icon'] ); ?>"></span>
				<span><?php echo esc_html( $area_meta['label'] ); ?></span>
			</a>
		<?php endforeach; ?>
	</nav>
	<?php endif; ?>

	<?php if ( 'commerce' === $lwp_area ) : ?>
		<div class="lp-hub-body">
			<?php
			if ( ! defined( 'LUWIPRESS_AGENTIC_HUB_INCLUDED' ) ) {
				define( 'LUWIPRESS_AGENTIC_HUB_INCLUDED', true );
			}
			include LUWIPRESS_AGENTIC_PLUGIN_DIR . 'admin/agentic-commerce-page.php';
			?>
		</div>
	<?php else : ?>
	<div class="claw-layout">
		<!-- Chat Panel -->
		<div class="claw-chat-panel">
			<div class="claw-chat-header">
				<div class="claw-header-left">
					<span class="claw-status-dot"></span>
					<strong><?php esc_html_e( 'Agentic', 'luwipress-agentic' ); ?></strong>
					<span class="claw-model-label" id="agentic-active-label"><?php echo esc_html( $active_label ); ?></span>
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
					<p>I'm your LuwiPress Agentic assistant. Ask me anything about your store — the request will route to whichever backend you've picked on the right.</p>
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
					<textarea id="claw-input" placeholder="Ask the active agent anything about your store..." rows="1"></textarea>
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
				<h4><span class="dashicons dashicons-superhero-alt"></span> <?php esc_html_e( 'Backend', 'luwipress-agentic' ); ?></h4>
				<p class="description" style="margin-top:0;font-size:12px;">
					<?php
					if ( $is_configured ) {
						printf(
							/* translators: %s: active backend label */
							esc_html__( 'Active: %s — chat goes to this runtime.', 'luwipress-agentic' ),
							'<strong>' . esc_html( $active_label ) . '</strong>'
						);
					} else {
						printf(
							/* translators: %s: active backend label */
							esc_html__( '%s is selected but has no token yet. Add one in Agentic Settings.', 'luwipress-agentic' ),
							'<strong>' . esc_html( $active_label ) . '</strong>'
						);
					}
					?>
				</p>
				<a href="<?php echo esc_url( $settings_url ); ?>" class="button button-small" style="margin-top:6px;">
					<span class="dashicons dashicons-admin-generic" style="vertical-align:text-bottom;"></span>
					<?php esc_html_e( 'Configure backends', 'luwipress-agentic' ); ?>
				</a>
			</div>

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

			<div class="claw-sidebar-section claw-conversation-info" id="claw-conversation-info" style="display:none;">
				<h4><span class="dashicons dashicons-admin-comments"></span> Conversation</h4>
				<div class="claw-conv-meta">
					<span class="claw-conv-id" id="claw-conv-id"></span>
					<span class="claw-conv-count" id="claw-conv-count">0 messages</span>
				</div>
			</div>
		</div>
	</div>
	<?php endif; ?>
</div>

