<?php
/**
 * LuwiPress Multi-language Taxonomy Editor admin page.
 *
 * Single-screen matrix editor: every term group (trid) becomes one
 * accordion row, expand it to see a (5 fields × N languages) cell grid.
 * Click any cell to edit; Save All batches every dirty cell into one
 * REST POST to /taxonomy-editor/save (which fans out into the bulk SEO
 * meta write + WPML-aware term name/description updates).
 *
 * UI is rendered by assets/js/taxonomy-editor.js; this template only
 * carries the toolbar + skeleton container. The class file enqueues
 * the JS/CSS and wires the data payload via wp_localize_script.
 *
 * @package LuwiPress
 * @since   3.5.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'luwipress' ) );
}

// Detection up-front so we can render an honest empty-state when the
// site has no translation plugin active — the editor still works, just
// with one language column.
$detector = class_exists( 'LuwiPress_Plugin_Detector' )
	? LuwiPress_Plugin_Detector::get_instance()->detect_translation()
	: array( 'plugin' => 'none', 'active_languages' => array( 'en' ), 'default_language' => 'en' );
$seo_info = class_exists( 'LuwiPress_Plugin_Detector' )
	? LuwiPress_Plugin_Detector::get_instance()->detect_seo()
	: array( 'plugin' => 'none' );

$translation_plugin = $detector['plugin'] ?? 'none';
$seo_plugin         = $seo_info['plugin'] ?? 'none';

// Get the editable taxonomy list from the class (filter-aware).
$taxonomies = array();
if ( class_exists( 'LuwiPress_Taxonomy_Editor' ) ) {
	$taxonomies = LuwiPress_Taxonomy_Editor::get_instance()->get_editable_taxonomies();
}

$default_taxonomy = ! empty( $taxonomies ) ? $taxonomies[0]['slug'] : 'category';
$requested_tax    = isset( $_GET['taxonomy'] ) ? sanitize_key( $_GET['taxonomy'] ) : $default_taxonomy;
?>
<?php $luwipress_hub_mode = defined( 'LUWIPRESS_HUB_INCLUDED' ); ?>
<?php if ( ! $luwipress_hub_mode ) : ?>
<div class="wrap luwipress-tax-editor">
<?php else : ?>
<?php // Hub mode drops the .wrap chrome but still needs the .luwipress-tax-editor scope so the toolbar's flex/spacing CSS applies. ?>
<div class="luwipress-tax-editor">
<?php endif; ?>

	<?php if ( ! $luwipress_hub_mode ) : ?>
	<div class="lp-header">
		<div class="lp-header__inner">
			<h1 class="lp-header__title">
				<span class="dashicons dashicons-translation"></span>
				<?php esc_html_e( 'Multi-language Taxonomy Editor', 'luwipress' ); ?>
			</h1>
			<p class="lp-header__subtitle">
				<?php esc_html_e( 'Edit term name, description, and SEO meta across every active language in one place. Save All batches every changed cell into a single API call.', 'luwipress' ); ?>
			</p>
			<div class="lp-header__meta">
				<?php if ( 'none' === $translation_plugin ) : ?>
					<span class="lp-pill pill-neutral">
						<?php esc_html_e( 'No translation plugin detected — single-language mode', 'luwipress' ); ?>
					</span>
				<?php else : ?>
					<span class="lp-pill pill-success">
						<?php echo esc_html( sprintf(
							/* translators: %s: translation plugin name (WPML, Polylang, TranslatePress) */
							__( 'Translation: %s', 'luwipress' ),
							strtoupper( $translation_plugin )
						) ); ?>
					</span>
				<?php endif; ?>

				<?php if ( 'none' !== $seo_plugin ) : ?>
					<span class="lp-pill pill-info">
						<?php echo esc_html( sprintf(
							/* translators: %s: SEO plugin name (Rank Math, Yoast, etc.) */
							__( 'SEO: %s', 'luwipress' ),
							ucfirst( str_replace( '_', ' ', $seo_plugin ) )
						) ); ?>
					</span>
				<?php endif; ?>
			</div>
		</div>
	</div>
	<?php endif; ?>

	<div class="lwp-tx-toolbar luwipress-card">
		<div class="lwp-tx-toolbar__left">
			<label for="lwp-tx-taxonomy" class="lwp-tx-label">
				<?php esc_html_e( 'Taxonomy:', 'luwipress' ); ?>
			</label>
			<select id="lwp-tx-taxonomy" class="lwp-tx-select" data-default="<?php echo esc_attr( $requested_tax ); ?>">
				<?php foreach ( $taxonomies as $tx ) : ?>
					<option value="<?php echo esc_attr( $tx['slug'] ); ?>" <?php selected( $tx['slug'], $requested_tax ); ?>>
						<?php echo esc_html( $tx['label'] ); ?> (<code><?php echo esc_html( $tx['slug'] ); ?></code>)
					</option>
				<?php endforeach; ?>
			</select>

			<input type="search" id="lwp-tx-search" class="lwp-tx-search" placeholder="<?php esc_attr_e( 'Search terms…', 'luwipress' ); ?>" />
		</div>

		<div class="lwp-tx-toolbar__right">
			<span class="lwp-tx-dirty-count" aria-live="polite"><?php esc_html_e( 'No changes', 'luwipress' ); ?></span>
			<button type="button" id="lwp-tx-save-all" class="button button-primary button-large" disabled>
				<span class="dashicons dashicons-saved"></span>
				<?php esc_html_e( 'Save all changes', 'luwipress' ); ?>
			</button>
		</div>
	</div>

	<div id="lwp-tx-status" class="lwp-tx-status" aria-live="polite"></div>

	<div id="lwp-tx-skeleton" class="lwp-tx-skeleton" hidden>
		<div class="lwp-tx-skeleton__row"></div>
		<div class="lwp-tx-skeleton__row"></div>
		<div class="lwp-tx-skeleton__row"></div>
	</div>

	<div id="lwp-tx-empty" class="lwp-tx-empty" hidden>
		<p><?php esc_html_e( 'No term groups returned for this taxonomy.', 'luwipress' ); ?></p>
	</div>

	<div id="lwp-tx-list" class="lwp-tx-list" aria-live="polite"></div>

	<div class="lwp-tx-footer">
		<p class="description">
			<?php esc_html_e( 'Tip:', 'luwipress' ); ?>
			<?php esc_html_e( 'Click any cell value to edit. Press Enter to confirm or Esc to revert.', 'luwipress' ); ?>
			<?php esc_html_e( 'WPML sibling terms are resolved via wpml_object_id; missing translations show "(no translation)" — create them in WP Admin first.', 'luwipress' ); ?>
		</p>
	</div>

</div><?php // close .luwipress-tax-editor (.wrap in standalone, plain scope in hub) ?>
