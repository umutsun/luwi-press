<?php
/**
 * LuwiPress Events admin page (CPT Engine preset #2).
 *
 * Self-contained tool page rendered by the Site hub (LuwiPress → Site → Events).
 * Like the Vendors page it computes its own data and saves over REST (POST
 * /events/settings) — no menu-load PHP handler — so it works whether it is
 * included by the hub or reached directly. The Events CPT itself uses WP's
 * native post-type screens for create/edit; this page configures the shell
 * (enable, archive slug, labels, editor-field toggles).
 *
 * @package LuwiPress
 * @since   3.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'luwipress' ) );
}
if ( ! class_exists( 'LuwiPress_Events' ) ) {
	echo '<div class="wrap"><h1>' . esc_html__( 'Events', 'luwipress' ) . '</h1><p>' . esc_html__( 'Events module not available.', 'luwipress' ) . '</p></div>';
	return;
}

$settings   = LuwiPress_Events::get_all_settings();
$enabled    = LuwiPress_Events::is_enabled();
$cpt_url    = admin_url( 'edit.php?post_type=' . LuwiPress_Events::POST_TYPE );
$rest_base  = esc_url_raw( rest_url( 'luwipress/v1/' ) );
$rest_nonce = wp_create_nonce( 'wp_rest' );

$val = function ( $key, $default = '' ) use ( $settings ) {
	return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
};
$on = function ( $key ) use ( $settings ) {
	return ! empty( $settings[ $key ] ) ? 'checked' : '';
};
?>
<div class="wrap luwipress-events-settings">
	<div class="lp-header">
		<div class="lp-header-left">
			<h1 class="lp-title">
				<span class="dashicons dashicons-calendar-alt" style="font-size:26px;width:26px;height:26px;"></span>
				<?php esc_html_e( 'Events', 'luwipress' ); ?>
				<?php if ( $enabled ) : ?>
					<span class="lp-pill pill-success"><?php esc_html_e( 'Active', 'luwipress' ); ?></span>
				<?php else : ?>
					<span class="lp-pill pill-neutral"><?php esc_html_e( 'Dormant', 'luwipress' ); ?></span>
				<?php endif; ?>
			</h1>
		</div>
		<div class="lp-header-actions">
			<span id="lwp-events-status" class="lp-pill pill-neutral" style="display:none;"></span>
			<?php if ( $enabled ) : ?>
				<a class="lp-pill lp-pill--action pill-neutral" href="<?php echo esc_url( $cpt_url ); ?>"><?php esc_html_e( 'Manage Events →', 'luwipress' ); ?></a>
			<?php endif; ?>
		</div>
	</div>

	<div class="luwipress-card" style="max-width:820px;">
		<p><?php esc_html_e( 'Events is a CPT Engine preset that turns concerts, workshops and classes into a first-class content type (lwp_event) with structured fields, organizer / performer links to your Vendors, Schema.org Event JSON-LD, and downloadable .ics calendar files.', 'luwipress' ); ?></p>
		<?php if ( ! $enabled ) : ?>
			<p><em><?php esc_html_e( 'The module is dormant: no Events menu, post type, archive or rewrite rules are registered yet. Enable it below to start scheduling events.', 'luwipress' ); ?></em></p>
		<?php endif; ?>
	</div>

	<form id="lwp-events-form" autocomplete="off">
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Events', 'luwipress' ); ?></th>
					<td><label><input type="checkbox" name="enabled" value="1" <?php echo esc_attr( $on( 'enabled' ) ); ?> /> <?php esc_html_e( 'Register the Events post type and surface its menu.', 'luwipress' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="lwp-evt-singular"><?php esc_html_e( 'Singular label', 'luwipress' ); ?></label></th>
					<td><input type="text" id="lwp-evt-singular" name="singular_label" class="regular-text" value="<?php echo esc_attr( $val( 'singular_label', 'Event' ) ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="lwp-evt-plural"><?php esc_html_e( 'Plural label', 'luwipress' ); ?></label></th>
					<td><input type="text" id="lwp-evt-plural" name="plural_label" class="regular-text" value="<?php echo esc_attr( $val( 'plural_label', 'Events' ) ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="lwp-evt-slug"><?php esc_html_e( 'Archive slug (URL base)', 'luwipress' ); ?></label></th>
					<td>
						<input type="text" id="lwp-evt-slug" name="archive_slug" class="regular-text" value="<?php echo esc_attr( $val( 'archive_slug', 'events' ) ); ?>" />
						<p class="description"><?php esc_html_e( 'Events appear at /<slug>/ and /<slug>/<event>/. Changing it flushes rewrite rules automatically.', 'luwipress' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="lwp-evt-icon"><?php esc_html_e( 'Menu icon', 'luwipress' ); ?></label></th>
					<td><input type="text" id="lwp-evt-icon" name="menu_icon" class="regular-text" value="<?php echo esc_attr( $val( 'menu_icon', 'dashicons-calendar-alt' ) ); ?>" /> <span class="description"><?php esc_html_e( 'A dashicons-* class.', 'luwipress' ); ?></span></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Options', 'luwipress' ); ?></th>
					<td>
						<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="archive_enabled" value="1" <?php echo esc_attr( $on( 'archive_enabled' ) ); ?> /> <?php esc_html_e( 'Enable the /events/ archive', 'luwipress' ); ?></label>
						<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="category_enabled" value="1" <?php echo esc_attr( $on( 'category_enabled' ) ); ?> /> <?php esc_html_e( 'Enable the Event Categories taxonomy', 'luwipress' ); ?></label>
						<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="with_front" value="1" <?php echo esc_attr( $on( 'with_front' ) ); ?> /> <?php esc_html_e( 'Prepend the permalink front base', 'luwipress' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Editor fields', 'luwipress' ); ?></th>
					<td>
						<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="show_venue" value="1" <?php echo esc_attr( $on( 'show_venue' ) ); ?> /> <?php esc_html_e( 'Venue (name + address)', 'luwipress' ); ?></label>
						<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="show_online" value="1" <?php echo esc_attr( $on( 'show_online' ) ); ?> /> <?php esc_html_e( 'Online URL', 'luwipress' ); ?></label>
						<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="show_offers" value="1" <?php echo esc_attr( $on( 'show_offers' ) ); ?> /> <?php esc_html_e( 'Ticketing (URL + price)', 'luwipress' ); ?></label>
						<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="show_organizer" value="1" <?php echo esc_attr( $on( 'show_organizer' ) ); ?> /> <?php esc_html_e( 'Organizers (vendor link)', 'luwipress' ); ?></label>
						<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="show_performer" value="1" <?php echo esc_attr( $on( 'show_performer' ) ); ?> /> <?php esc_html_e( 'Performers (vendor link)', 'luwipress' ); ?></label>
					</td>
				</tr>
			</tbody>
		</table>

		<p><button type="submit" class="button button-primary" id="lwp-events-save"><?php esc_html_e( 'Save Events settings', 'luwipress' ); ?></button></p>
	</form>
</div>
<script>
(function () {
	var REST  = <?php echo wp_json_encode( $rest_base ); ?>;
	var NONCE = <?php echo wp_json_encode( $rest_nonce ); ?>;
	var form  = document.getElementById('lwp-events-form');
	var pill  = document.getElementById('lwp-events-status');
	if (!form) return;
	function status(txt, cls) {
		if (!pill) return;
		pill.textContent = txt;
		pill.className = 'lp-pill ' + (cls || 'pill-neutral');
		pill.style.display = '';
	}
	var BOOLS = ['enabled','archive_enabled','category_enabled','with_front','show_venue','show_online','show_offers','show_organizer','show_performer'];
	var TEXTS = ['singular_label','plural_label','archive_slug','menu_icon'];
	form.addEventListener('submit', function (e) {
		e.preventDefault();
		var btn = document.getElementById('lwp-events-save');
		if (btn) btn.disabled = true;
		status('<?php echo esc_js( __( 'Saving…', 'luwipress' ) ); ?>', 'pill-neutral');
		var payload = {};
		BOOLS.forEach(function (k) { var el = form.querySelector('[name="' + k + '"]'); payload[k] = el && el.checked ? 1 : 0; });
		TEXTS.forEach(function (k) { var el = form.querySelector('[name="' + k + '"]'); if (el) payload[k] = el.value; });
		fetch(REST + 'events/settings', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
			body: JSON.stringify(payload)
		}).then(function (r) { return r.ok ? r.json() : Promise.reject(r); }).then(function () {
			status('<?php echo esc_js( __( 'Saved — reloading…', 'luwipress' ) ); ?>', 'pill-success');
			// Reload so the WP admin menu + CPT registration reflect the new state.
			setTimeout(function () { window.location.reload(); }, 600);
		}).catch(function () {
			status('<?php echo esc_js( __( 'Save failed.', 'luwipress' ) ); ?>', 'pill-warning');
			if (btn) btn.disabled = false;
		});
	});
})();
</script>
