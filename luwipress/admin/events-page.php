<?php
/**
 * LuwiPress Events — settings page (CPT Engine preset #2).
 *
 * Self-contained view rendered by LuwiPress_Events::render_settings_page().
 * Always reachable (even while the module is dormant) so the operator can enable
 * it. Intentionally NOT a tab in admin/settings-page.php — kept standalone to
 * avoid coupling with that shared file.
 *
 * Expects in scope: $settings (array), $enabled (bool), $cpt_url (string).
 *
 * @package LuwiPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var array  $settings Always provided by LuwiPress_Events::render_settings_page(). */
/** @var bool   $enabled */
/** @var string $cpt_url */

$val = function ( $key, $default = '' ) use ( $settings ) {
	return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
};
$on = function ( $key ) use ( $settings ) {
	return ! empty( $settings[ $key ] ) ? 'checked' : '';
};
?>
<div class="wrap luwipress-events-settings">
	<h1><?php esc_html_e( 'Events', 'luwipress' ); ?>
		<?php if ( $enabled ) : ?>
			<span class="lp-pill pill-success"><?php esc_html_e( 'Active', 'luwipress' ); ?></span>
		<?php else : ?>
			<span class="lp-pill pill-neutral"><?php esc_html_e( 'Dormant', 'luwipress' ); ?></span>
		<?php endif; ?>
	</h1>

	<?php settings_errors( 'luwipress_events' ); ?>

	<div class="luwipress-card" style="max-width:820px;">
		<p>
			<?php esc_html_e( 'Events is a CPT Engine preset that turns concerts, workshops and classes into a first-class content type (lwp_event) with structured fields, organizer / performer links to your Vendors, Schema.org Event JSON-LD, and downloadable .ics calendar files.', 'luwipress' ); ?>
		</p>
		<?php if ( $enabled ) : ?>
			<p>
				<a class="button button-secondary" href="<?php echo esc_url( $cpt_url ); ?>"><?php esc_html_e( 'Manage Events →', 'luwipress' ); ?></a>
			</p>
		<?php else : ?>
			<p><em><?php esc_html_e( 'The module is dormant: no Events menu, post type, archive or rewrite rules are registered yet. Enable it below to start scheduling events.', 'luwipress' ); ?></em></p>
		<?php endif; ?>
	</div>

	<form method="post" action="">
		<?php wp_nonce_field( 'lwp_events_settings_save', 'lwp_events_settings_nonce' ); ?>

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Events', 'luwipress' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="lwp_events_enabled" value="1" <?php echo esc_attr( $on( 'enabled' ) ); ?> />
							<?php esc_html_e( 'Register the Events post type and surface its menu.', 'luwipress' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="lwp_events_singular_label"><?php esc_html_e( 'Singular label', 'luwipress' ); ?></label></th>
					<td><input type="text" id="lwp_events_singular_label" name="lwp_events_singular_label" class="regular-text" value="<?php echo esc_attr( $val( 'singular_label', 'Event' ) ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="lwp_events_plural_label"><?php esc_html_e( 'Plural label', 'luwipress' ); ?></label></th>
					<td><input type="text" id="lwp_events_plural_label" name="lwp_events_plural_label" class="regular-text" value="<?php echo esc_attr( $val( 'plural_label', 'Events' ) ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="lwp_events_archive_slug"><?php esc_html_e( 'Archive slug (URL base)', 'luwipress' ); ?></label></th>
					<td>
						<input type="text" id="lwp_events_archive_slug" name="lwp_events_archive_slug" class="regular-text" value="<?php echo esc_attr( $val( 'archive_slug', 'events' ) ); ?>" />
						<p class="description"><?php esc_html_e( 'Events appear at /<slug>/ and /<slug>/<event>/. Changing it flushes rewrite rules automatically.', 'luwipress' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="lwp_events_menu_icon"><?php esc_html_e( 'Menu icon', 'luwipress' ); ?></label></th>
					<td>
						<input type="text" id="lwp_events_menu_icon" name="lwp_events_menu_icon" class="regular-text" value="<?php echo esc_attr( $val( 'menu_icon', 'dashicons-calendar-alt' ) ); ?>" />
						<p class="description"><?php esc_html_e( 'A dashicons-* class, e.g. dashicons-calendar-alt.', 'luwipress' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Options', 'luwipress' ); ?></th>
					<td>
						<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="lwp_events_archive_enabled" value="1" <?php echo esc_attr( $on( 'archive_enabled' ) ); ?> /> <?php esc_html_e( 'Enable the /events/ archive', 'luwipress' ); ?></label>
						<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="lwp_events_category_enabled" value="1" <?php echo esc_attr( $on( 'category_enabled' ) ); ?> /> <?php esc_html_e( 'Enable the Event Categories taxonomy', 'luwipress' ); ?></label>
						<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="lwp_events_with_front" value="1" <?php echo esc_attr( $on( 'with_front' ) ); ?> /> <?php esc_html_e( 'Prepend the permalink front base', 'luwipress' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Editor fields', 'luwipress' ); ?></th>
					<td>
						<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="lwp_events_show_venue" value="1" <?php echo esc_attr( $on( 'show_venue' ) ); ?> /> <?php esc_html_e( 'Venue (name + address)', 'luwipress' ); ?></label>
						<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="lwp_events_show_online" value="1" <?php echo esc_attr( $on( 'show_online' ) ); ?> /> <?php esc_html_e( 'Online URL', 'luwipress' ); ?></label>
						<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="lwp_events_show_offers" value="1" <?php echo esc_attr( $on( 'show_offers' ) ); ?> /> <?php esc_html_e( 'Ticketing (URL + price)', 'luwipress' ); ?></label>
						<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="lwp_events_show_organizer" value="1" <?php echo esc_attr( $on( 'show_organizer' ) ); ?> /> <?php esc_html_e( 'Organizers (vendor link)', 'luwipress' ); ?></label>
						<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="lwp_events_show_performer" value="1" <?php echo esc_attr( $on( 'show_performer' ) ); ?> /> <?php esc_html_e( 'Performers (vendor link)', 'luwipress' ); ?></label>
					</td>
				</tr>
			</tbody>
		</table>

		<?php submit_button( __( 'Save Events settings', 'luwipress' ) ); ?>
	</form>
</div>
