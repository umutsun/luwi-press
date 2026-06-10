<?php
/**
 * LuwiPress Agentic — Settings fragment (header-less).
 *
 * Renders the per-adapter card grid + AJAX save script ONLY. Designed to be
 * embedded inside another admin page wrapper (e.g. core's Settings page as a
 * registered tab via `luwipress_settings_render_tab_content`). The standalone
 * settings page (settings-page.php) wraps this fragment with its own lp-header.
 *
 * Required caller capability: `manage_options` (caller MUST verify).
 *
 * @package LuwiPress
 * @since 1.1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$agent_host = class_exists( 'LuwiPress_Agent_Host' ) ? LuwiPress_Agent_Host::get_instance() : null;
$active_id  = $agent_host ? $agent_host->get_active_id() : 'open-claw';
$adapters   = $agent_host ? $agent_host->get_adapters() : array();

$option_key_for = static function ( $id ) {
	return 'luwipress_agent_' . str_replace( '-', '_', sanitize_key( $id ) );
};
$default_endpoint_for = static function ( $id ) {
	if ( 'open-claw' === $id ) {
		return 'https://oc.luwi.dev/agent';
	}
	if ( 'hermes' === $id ) {
		return 'https://hermes.luwi.dev/agent';
	}
	return '';
};
$tagline_for = static function ( $id ) {
	$map = array(
		'open-claw' => __( 'Agent backend slot — point it at your own agent runtime\'s endpoint (URL + token).', 'luwipress-agentic' ),
		'hermes'    => __( 'Agent backend slot — point it at your own agent runtime\'s endpoint (URL + token).', 'luwipress-agentic' ),
	);
	return isset( $map[ $id ] ) ? $map[ $id ] : __( 'Third-party agent runtime — register via the luwipress_agent_register action.', 'luwipress-agentic' );
};

$rows = array();
foreach ( $adapters as $id => $adapter ) {
	$option_key = $option_key_for( $id );
	$opt        = get_option( $option_key, array() );
	$opt        = is_array( $opt ) ? $opt : array();
	$rows[ $id ] = array(
		'label'         => $adapter->get_label(),
		'tagline'       => $tagline_for( $id ),
		'default_url'   => $default_endpoint_for( $id ),
		'endpoint'      => isset( $opt['endpoint'] ) ? $opt['endpoint'] : '',
		'has_token'     => ! empty( $opt['token'] ),
		'is_configured' => $adapter->is_configured(),
	);
}
?>
<div id="agentic-backends" class="agentic-settings-grid">
	<?php if ( empty( $rows ) ) : ?>
		<div class="notice notice-warning inline">
			<p><?php esc_html_e( 'No agent adapters registered. The Agent Host is loaded but no backend plugged in yet.', 'luwipress-agentic' ); ?></p>
		</div>
	<?php endif; ?>

	<?php foreach ( $rows as $id => $row ) :
		$is_active   = ( $id === $active_id );
		$placeholder = $row['default_url']
			? sprintf( __( 'Default: %s', 'luwipress-agentic' ), $row['default_url'] )
			: __( 'No default endpoint — enter the URL your adapter expects.', 'luwipress-agentic' );
	?>
	<div class="agentic-backend-card<?php echo $is_active ? ' is-active' : ''; ?>" data-adapter-id="<?php echo esc_attr( $id ); ?>">
		<div class="agentic-backend-head">
			<label class="agentic-backend-active">
				<input type="radio" name="agentic_active" value="<?php echo esc_attr( $id ); ?>"<?php checked( $is_active ); ?> />
				<strong><?php echo esc_html( $row['label'] ); ?></strong>
			</label>
			<?php if ( $row['is_configured'] ) : ?>
				<span class="lp-pill pill-success"><?php esc_html_e( 'Configured', 'luwipress-agentic' ); ?></span>
			<?php else : ?>
				<span class="lp-pill pill-warning"><?php esc_html_e( 'Token needed', 'luwipress-agentic' ); ?></span>
			<?php endif; ?>
			<?php if ( $is_active ) : ?>
				<span class="lp-pill pill-info"><?php esc_html_e( 'Active', 'luwipress-agentic' ); ?></span>
			<?php endif; ?>
		</div>
		<p class="agentic-backend-tagline"><?php echo esc_html( $row['tagline'] ); ?></p>

		<div class="agentic-backend-fields">
			<label class="agentic-field-label" for="agentic-endpoint-<?php echo esc_attr( $id ); ?>">
				<?php esc_html_e( 'Endpoint', 'luwipress-agentic' ); ?>
				<span class="agentic-field-hint"><?php esc_html_e( '(leave empty to use the default)', 'luwipress-agentic' ); ?></span>
			</label>
			<input type="url"
			       id="agentic-endpoint-<?php echo esc_attr( $id ); ?>"
			       class="agentic-endpoint regular-text"
			       value="<?php echo esc_attr( $row['endpoint'] ); ?>"
			       placeholder="<?php echo esc_attr( $placeholder ); ?>" />

			<label class="agentic-field-label" for="agentic-token-<?php echo esc_attr( $id ); ?>">
				<?php esc_html_e( 'Access token', 'luwipress-agentic' ); ?>
				<?php if ( $row['has_token'] ) : ?>
					<span class="agentic-field-hint"><?php esc_html_e( '(stored — leave empty to keep)', 'luwipress-agentic' ); ?></span>
				<?php endif; ?>
			</label>
			<input type="password"
			       id="agentic-token-<?php echo esc_attr( $id ); ?>"
			       class="agentic-token regular-text"
			       value=""
			       placeholder="<?php echo esc_attr( $row['has_token'] ? '••••••••' : __( 'Paste token from your agent dashboard', 'luwipress-agentic' ) ); ?>"
			       autocomplete="off" />

			<div class="agentic-save-row">
				<button type="button" class="button button-primary agentic-save-btn">
					<?php esc_html_e( 'Save', 'luwipress-agentic' ); ?>
				</button>
				<span class="agentic-save-status"></span>
			</div>
		</div>
	</div>
	<?php endforeach; ?>
</div>

<div class="agentic-settings-footnote">
	<p class="description">
		<?php
		printf(
			/* translators: %s: code snippet for registering a new adapter */
			esc_html__( 'Want to add another runtime? Hook %s and call $host->register( new LuwiPress_Agent_Adapter_HTTP( id, label, option_key, default_endpoint ) ). The card for it will appear here automatically.', 'luwipress-agentic' ),
			'<code>luwipress_agent_register</code>'
		);
		?>
	</p>
</div>

<style>
	.agentic-settings-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(420px, 1fr)); gap:16px; margin-top:16px; }
	.agentic-backend-card { border:1px solid var(--lp-border,#dcdcde); border-radius:8px; padding:18px 20px; background:var(--lp-bg,#fff); box-shadow:0 1px 1px rgba(0,0,0,0.04); }
	.agentic-backend-card.is-active { border-color:var(--lp-primary,#2271b1); box-shadow:0 2px 6px rgba(34,113,177,0.12); }
	.agentic-backend-head { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
	.agentic-backend-active { display:flex; align-items:center; gap:8px; cursor:pointer; }
	.agentic-backend-active strong { font-size:15px; }
	.agentic-backend-tagline { margin:8px 0 14px; color:var(--lp-muted,#646970); font-size:12px; }
	.agentic-backend-fields .agentic-field-label { display:block; font-weight:600; font-size:12px; margin:6px 0 4px; }
	.agentic-backend-fields .agentic-field-hint { font-weight:400; color:var(--lp-muted,#646970); }
	.agentic-backend-fields input.regular-text { width:100%; font-size:13px; }
	.agentic-save-row { display:flex; gap:10px; align-items:center; margin-top:12px; }
	.agentic-save-status { font-size:12px; color:var(--lp-muted,#646970); }
	.agentic-settings-footnote { margin-top:24px; padding:12px 14px; border-left:3px solid var(--lp-border,#dcdcde); background:var(--lp-bg-soft,#f6f7f7); border-radius:0 4px 4px 0; }
	.agentic-settings-footnote .description { margin:0; font-size:12px; }
	.agentic-settings-footnote code { background:rgba(0,0,0,0.06); padding:1px 6px; border-radius:3px; }
</style>

<script>
(function(){
	if (typeof window.jQuery === 'undefined') return;
	jQuery(function($){
		var ajaxUrl = (window.luwipress && window.luwipress.ajax_url) || (window.ajaxurl) || '';
		var nonce   = (window.luwipress && window.luwipress.claw_nonce) || '';
		if (!ajaxUrl || !nonce) return;

		$('#agentic-backends').on('click', '.agentic-save-btn', function(){
			var $card    = $(this).closest('.agentic-backend-card');
			var adapter  = $card.data('adapter-id');
			var endpoint = $card.find('.agentic-endpoint').val();
			var token    = $card.find('.agentic-token').val();
			var setActive = $card.find('input[name="agentic_active"]').is(':checked');
			var $status  = $card.find('.agentic-save-status');
			var $btn     = $(this);

			$btn.prop('disabled', true);
			$status.text('Saving…').css('color','');

			$.post(ajaxUrl, {
				action:     'luwipress_agentic_save_backend',
				nonce:      nonce,
				adapter_id: adapter,
				endpoint:   endpoint,
				token:      token,
				set_active: setActive ? 1 : 0
			}).done(function(resp){
				if (resp && resp.success) {
					$status.text('Saved ✓').css('color','var(--lp-success,#1e8e3e)');
					$card.find('.agentic-token').val('').attr('placeholder', resp.data.has_token ? '••••••••' : 'Paste token from your agent dashboard');
					// Reflect the normalized endpoint the server saved (defensive normalizer in 1.1.1
					// may have appended /agent or trimmed a trailing slash).
					if (typeof resp.data.endpoint_saved === 'string') {
						$card.find('.agentic-endpoint').val(resp.data.endpoint_saved);
					}
					if (setActive) {
						$('.agentic-backend-card').removeClass('is-active').find('.lp-pill.pill-info').remove();
						$card.addClass('is-active');
						$card.find('.agentic-backend-head').append('<span class="lp-pill pill-info">Active</span>');

						// Live update of chat-page elements when settings-fragment is embedded
						// on the same screen as the chat (no full page reload required).
						// Selectors are no-ops on the standalone settings page.
						var newLabel = ($card.find('.agentic-backend-head strong').first().text() || adapter).trim();
						var isConfigured = !!resp.data.configured;

						// Chat header pill (top-right)
						var $pill = $('#agentic-active-pill');
						if ($pill.length) {
							$pill.contents().filter(function(){ return this.nodeType === 3; }).remove();
							$pill.append(' ' + newLabel);
							$pill.removeClass('pill-warning pill-success')
							     .addClass(isConfigured ? 'pill-success' : 'pill-warning');
						}
						// Chat panel model label
						var $label = $('#agentic-active-label');
						if ($label.length) { $label.text(newLabel); }

						// Sidebar "Active: X — chat goes to this runtime."
						$('.claw-sidebar-section .description strong').each(function(){
							var $s = $(this);
							if ($s.text().trim().length) { $s.text(newLabel); }
						});
					}
				} else {
					$status.text('Error: ' + ((resp && resp.data) || 'unknown')).css('color','var(--lp-danger,#c00)');
				}
			}).fail(function(){
				$status.text('Network error').css('color','var(--lp-danger,#c00)');
			}).always(function(){
				$btn.prop('disabled', false);
				setTimeout(function(){ if ($status.text().indexOf('Saved') === 0) $status.text(''); }, 4000);
			});
		});

		$('#agentic-backends').on('change', 'input[name="agentic_active"]', function(){
			$('.agentic-backend-card').removeClass('is-active');
			$(this).closest('.agentic-backend-card').addClass('is-active');
		});
	});
})();
</script>
