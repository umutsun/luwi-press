<?php
/**
 * LuwiPress Agentic — Standalone settings page (back-compat).
 *
 * As of 1.1.1 the agentic settings live inside the core LuwiPress Settings
 * page as the "Agentic" tab. The standalone URL still resolves so any old
 * bookmarks / deep links don't 404, but it just wraps the same fragment
 * inside an lp-header for visual continuity.
 *
 * @package LuwiPress
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( __( 'You do not have permission to access this page.', 'luwipress-agentic' ) );
}

$logo_url = defined( 'LUWIPRESS_PLUGIN_URL' ) ? LUWIPRESS_PLUGIN_URL . 'assets/images/luwi-logo.png' : '';
?>
<div class="wrap luwipress-admin luwipress-dashboard luwipress-agentic-settings-wrap">

	<div class="lp-header">
		<div class="lp-header-left">
			<h1 class="lp-title">
				<?php if ( $logo_url ) : ?>
					<img class="lp-logo" width="28" height="28" src="<?php echo esc_url( $logo_url ); ?>" alt="LuwiPress" />
				<?php else : ?>
					<span class="dashicons dashicons-superhero-alt" style="font-size:24px;width:24px;height:24px;color:var(--lp-primary);"></span>
				<?php endif; ?>
				<?php esc_html_e( 'Agentic Settings', 'luwipress-agentic' ); ?>
			</h1>
			<p class="lp-subtitle">
				<?php esc_html_e( 'Backend runtime configuration. Two HTTP adapters ship by default; more can register via the luwipress_agent_register action.', 'luwipress-agentic' ); ?>
			</p>
		</div>
		<div class="lp-header-actions">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=luwipress-settings&tab=agentic' ) ); ?>"
			   class="lp-pill lp-pill--action pill-info"
			   title="<?php esc_attr_e( 'These settings now live under the main Settings page', 'luwipress-agentic' ); ?>">
				<span class="dashicons dashicons-admin-generic"></span>
				<?php esc_html_e( 'Open in main Settings', 'luwipress-agentic' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=luwipress-agentic' ) ); ?>"
			   class="lp-pill lp-pill--action pill-neutral">
				<span class="dashicons dashicons-admin-comments"></span>
				<?php esc_html_e( 'Open Chat', 'luwipress-agentic' ); ?>
			</a>
		</div>
	</div>

	<?php include LUWIPRESS_AGENTIC_PLUGIN_DIR . 'admin/settings-fragment.php'; ?>
</div>
