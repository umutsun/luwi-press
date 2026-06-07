<?php
/**
 * LuwiPress Content Types (CPT Manager) admin page.
 *
 * The operator UI for LuwiPress_CPT_Engine — the generic custom-post-type
 * registry. Until now the engine was reachable only via REST
 * (`/cpt-engine/types`) + the `cpt_type_*` WebMCP tools: there was no way to
 * SEE the registered types, their FIELD SCHEMA (attributes), or to ADD a
 * custom content type from wp-admin. This page closes that gap.
 *
 * What it does:
 *  - Lists every registered type — code presets (Vendors, Events) AND
 *    operator-defined types — with their fields, taxonomies, enabled state and
 *    WooCommerce attribution.
 *  - Presets are read-only here; their settings live on their own hub tabs
 *    (Vendors / Events) — this page links out to them.
 *  - Operator-defined types are fully editable: an Add/Edit form with a FIELD
 *    (attribute) builder over the 10 engine field types, a taxonomy builder,
 *    Schema.org @type mapping, and (when WooCommerce is active) a product
 *    attribution-meta binding. Delete is supported too.
 *  - Surfaces the WPML/Polylang config (pasteable wpml-config.xml) for
 *    operator-defined CPTs on WPML.
 *
 * All writes go through the engine's REST CRUD (cookie + X-WP-Nonce auth); the
 * engine validates + persists to its option store and registers the CPT on the
 * next request. No hardcoded colors — `--lp-*` tokens + canonical classes only.
 *
 * @package LuwiPress
 * @since   3.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'luwipress' ) );
}

if ( ! class_exists( 'LuwiPress_CPT_Engine' ) ) {
	echo '<div class="luwipress-card luwipress-card--warning"><p>' . esc_html__( 'CPT Engine not available.', 'luwipress' ) . '</p></div>';
	return;
}

$rest_base  = esc_url_raw( rest_url( 'luwipress/v1/' ) );
$rest_nonce = wp_create_nonce( 'wp_rest' );

$engine = LuwiPress_CPT_Engine::get_instance();
$types  = $engine->get_types(); // key => normalized definition

// Engine field types (kept in sync with clean_def_for_storage()'s allowlist).
$field_types = array( 'text', 'textarea', 'number', 'url', 'email', 'image', 'date', 'datetime', 'select', 'relationship' );

// Preset key → its dedicated hub settings tab.
$preset_setting_tabs = array(
	'vendors' => admin_url( 'admin.php?page=luwipress-site&tab=vendors' ),
	'events'  => admin_url( 'admin.php?page=luwipress-site&tab=events' ),
);

$wc_active = class_exists( 'WooCommerce' );

// Stats + custom-type map (for JS edit prefill).
$total_types   = count( $types );
$custom_count  = 0;
$enabled_count = 0;
$custom_defs   = array();
foreach ( $types as $tk => $def ) {
	if ( ! empty( $def['enabled'] ) ) {
		$enabled_count++;
	}
	if ( isset( $def['source'] ) && 'option' === $def['source'] ) {
		$custom_count++;
		$custom_defs[ $tk ] = $def;
	}
}

/**
 * Human display name for a type — prefer the live registered CPT label (so a
 * Vendor renamed to "Luthiers" shows as Luthiers), fall back to the definition
 * label, then the key.
 *
 * @param array $def
 * @return string
 */
$display_name = static function ( array $def ) {
	$pt_obj = get_post_type_object( $def['post_type'] );
	if ( $pt_obj && isset( $pt_obj->labels->name ) && '' !== $pt_obj->labels->name ) {
		return (string) $pt_obj->labels->name;
	}
	if ( ! empty( $def['labels']['plural'] ) ) {
		return (string) $def['labels']['plural'];
	}
	if ( ! empty( $def['labels']['singular'] ) ) {
		return (string) $def['labels']['singular'];
	}
	return ucfirst( (string) $def['key'] );
};

$luwipress_hub_mode = defined( 'LUWIPRESS_HUB_INCLUDED' );
?>
<?php if ( ! $luwipress_hub_mode ) : ?>
<div class="wrap luwipress-cpt-manager">
	<div class="lp-header">
		<div class="lp-header-left">
			<h1 class="lp-title">
				<img class="lp-logo" width="28" height="28"
				     src="<?php echo esc_url( LUWIPRESS_PLUGIN_URL . 'assets/images/luwi-logo.png' ); ?>"
				     alt="LuwiPress" />
				<?php esc_html_e( 'Content Types', 'luwipress' ); ?>
			</h1>
		</div>
		<div class="lp-header-actions">
			<span class="lp-pill pill-neutral">v<?php echo esc_html( LUWIPRESS_VERSION ); ?></span>
		</div>
	</div>
<?php endif; ?>

	<p class="lp-page-intro">
		<?php esc_html_e( 'Content Types are the engine behind Vendors, Events and any custom directory your store needs (Team, Venues, Recipes, Artists…). Each type carries a field schema (its attributes), optional taxonomies, a Schema.org mapping and — when WooCommerce is active — a product attribution binding. Presets are configured on their own tabs; add your own types below. New types become translatable and Elementor-ready automatically.', 'luwipress' ); ?>
	</p>

	<!-- Hero stats -->
	<div class="lp-stat-row lwp-ct-hero">
		<div class="lp-stat lp-stat--info">
			<div class="lp-stat-label"><?php esc_html_e( 'Registered types', 'luwipress' ); ?></div>
			<div class="lp-stat-value"><?php echo esc_html( (string) $total_types ); ?></div>
		</div>
		<div class="lp-stat lp-stat--success">
			<div class="lp-stat-label"><?php esc_html_e( 'Enabled', 'luwipress' ); ?></div>
			<div class="lp-stat-value"><?php echo esc_html( (string) $enabled_count ); ?></div>
		</div>
		<div class="lp-stat">
			<div class="lp-stat-label"><?php esc_html_e( 'Custom (yours)', 'luwipress' ); ?></div>
			<div class="lp-stat-value"><?php echo esc_html( (string) $custom_count ); ?></div>
		</div>
		<div class="lp-stat lp-stat--muted">
			<div class="lp-stat-label"><?php esc_html_e( 'WooCommerce', 'luwipress' ); ?></div>
			<div class="lp-stat-value lwp-ct-wc"><?php echo $wc_active ? esc_html__( 'Active', 'luwipress' ) : esc_html__( 'Off', 'luwipress' ); ?></div>
		</div>
	</div>

	<div class="lwp-ct-toolbar">
		<button type="button" class="lp-btn lp-btn--primary" id="lwp-ct-add">
			<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
			<?php esc_html_e( 'Add content type', 'luwipress' ); ?>
		</button>
		<span id="lwp-ct-toolbar-status" class="lwp-ct-status" aria-live="polite"></span>
	</div>

	<!-- ─────────── Editor panel (hidden until Add/Edit) ─────────── -->
	<div class="luwipress-card luwipress-card--primary lwp-ct-editor" id="lwp-ct-editor" hidden>
		<h2 class="lwp-ct-editor-title"><?php esc_html_e( 'Add content type', 'luwipress' ); ?></h2>

		<table class="form-table" role="presentation">
			<tr>
				<th><label for="lwp-ct-key"><?php esc_html_e( 'Key', 'luwipress' ); ?></label></th>
				<td>
					<input type="text" id="lwp-ct-key" class="regular-text lwp-ct-mono" placeholder="team" maxlength="20">
					<p class="description"><?php esc_html_e( 'Stable internal identifier (a–z, 0–9, _). Cannot be changed after creation.', 'luwipress' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="lwp-ct-post_type"><?php esc_html_e( 'Post type slug', 'luwipress' ); ?></label></th>
				<td>
					<input type="text" id="lwp-ct-post_type" class="regular-text lwp-ct-mono" placeholder="lwp_team" maxlength="20">
					<p class="description"><?php esc_html_e( 'WordPress post_type name (max 20 chars). Defaults to the key. Reserved names (post, page, product…) are rejected.', 'luwipress' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="lwp-ct-singular"><?php esc_html_e( 'Singular label', 'luwipress' ); ?></label></th>
				<td><input type="text" id="lwp-ct-singular" class="regular-text" placeholder="Team member"></td>
			</tr>
			<tr>
				<th><label for="lwp-ct-plural"><?php esc_html_e( 'Plural label', 'luwipress' ); ?></label></th>
				<td><input type="text" id="lwp-ct-plural" class="regular-text" placeholder="Team"></td>
			</tr>
			<tr>
				<th><label for="lwp-ct-archive_slug"><?php esc_html_e( 'Archive URL slug', 'luwipress' ); ?></label></th>
				<td>
					<input type="text" id="lwp-ct-archive_slug" class="regular-text lwp-ct-mono" placeholder="team">
					<p class="description"><?php esc_html_e( 'Public archive path — /team/, /artists/, etc. Defaults to the post type slug.', 'luwipress' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="lwp-ct-menu_icon"><?php esc_html_e( 'Menu icon', 'luwipress' ); ?></label></th>
				<td><input type="text" id="lwp-ct-menu_icon" class="regular-text lwp-ct-mono" placeholder="dashicons-groups"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Status', 'luwipress' ); ?></th>
				<td>
					<label><input type="checkbox" id="lwp-ct-enabled" checked> <?php esc_html_e( 'Enabled (register the CPT, taxonomies and fields)', 'luwipress' ); ?></label>
					<label class="lwp-ct-inline"><input type="checkbox" id="lwp-ct-with_front"> <?php esc_html_e( 'Prepend permalink base', 'luwipress' ); ?></label>
				</td>
			</tr>
		</table>

		<!-- Fields (attributes) -->
		<h3 class="lwp-ct-sub"><span class="dashicons dashicons-list-view" aria-hidden="true"></span> <?php esc_html_e( 'Fields (attributes)', 'luwipress' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Each field is registered as post meta + exposed to REST. "Translatable" fields are sent to translation; "Elementor" fields become dynamic tags. "Schema prop" maps the value onto the type\'s Schema.org JSON-LD (needs a Schema.org @type set in Advanced) — e.g. jobTitle. Use sameAs on several fields (Facebook, Instagram…) to collect them into one array; knowsAbout / keywords comma-split into an array; address.addressLocality nests into a PostalAddress.', 'luwipress' ); ?></p>
		<table class="widefat lwp-ct-fields">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Key', 'luwipress' ); ?></th>
					<th><?php esc_html_e( 'Label', 'luwipress' ); ?></th>
					<th><?php esc_html_e( 'Type', 'luwipress' ); ?></th>
					<th class="lwp-ct-c"><?php esc_html_e( 'Transl.', 'luwipress' ); ?></th>
					<th class="lwp-ct-c"><?php esc_html_e( 'Elementor', 'luwipress' ); ?></th>
					<th><?php esc_html_e( 'Schema prop', 'luwipress' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody id="lwp-ct-fields-body"></tbody>
		</table>
		<button type="button" class="button lwp-ct-mt8" id="lwp-ct-add-field">+ <?php esc_html_e( 'Add field', 'luwipress' ); ?></button>

		<!-- Taxonomies -->
		<h3 class="lwp-ct-sub"><span class="dashicons dashicons-category" aria-hidden="true"></span> <?php esc_html_e( 'Taxonomies', 'luwipress' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Optional groupings (e.g. a "department" for Team, a "genre" for Artists). Translatable taxonomies surface in the Translation Manager.', 'luwipress' ); ?></p>
		<table class="widefat lwp-ct-taxes">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Slug', 'luwipress' ); ?></th>
					<th><?php esc_html_e( 'Label', 'luwipress' ); ?></th>
					<th class="lwp-ct-c"><?php esc_html_e( 'Hierarchical', 'luwipress' ); ?></th>
					<th class="lwp-ct-c"><?php esc_html_e( 'Transl.', 'luwipress' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody id="lwp-ct-taxes-body"></tbody>
		</table>
		<button type="button" class="button lwp-ct-mt8" id="lwp-ct-add-tax">+ <?php esc_html_e( 'Add taxonomy', 'luwipress' ); ?></button>

		<!-- Advanced -->
		<details class="lp-collapse lwp-ct-mt16">
			<summary><span class="dashicons dashicons-admin-settings"></span> <span><?php esc_html_e( 'Advanced — Schema.org & WooCommerce', 'luwipress' ); ?></span></summary>
			<div class="lp-collapse-body">
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="lwp-ct-schema_type"><?php esc_html_e( 'Schema.org @type', 'luwipress' ); ?></label></th>
						<td>
							<input type="text" id="lwp-ct-schema_type" class="regular-text lwp-ct-mono" placeholder="Person">
							<p class="description"><?php esc_html_e( 'Optional. Maps this type to a Schema Registry type (e.g. Person, Organization, Event).', 'luwipress' ); ?></p>
						</td>
					</tr>
					<?php if ( $wc_active ) : ?>
					<tr>
						<th><label for="lwp-ct-wc_meta"><?php esc_html_e( 'Product attribution meta key', 'luwipress' ); ?></label></th>
						<td>
							<input type="text" id="lwp-ct-wc_meta" class="regular-text lwp-ct-mono" placeholder="_lwp_team_ids">
							<p class="description"><?php esc_html_e( 'Optional. Bind products to this type via a JSON-array meta key. The engine auto-creates a hidden product_<type> taxonomy and keeps meta ↔ terms in sync.', 'luwipress' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="lwp-ct-wc_role"><?php esc_html_e( 'Attribution role / label', 'luwipress' ); ?></label></th>
						<td><input type="text" id="lwp-ct-wc_role" class="regular-text" placeholder="Makers"></td>
					</tr>
					<?php else : ?>
					<tr>
						<th><?php esc_html_e( 'WooCommerce', 'luwipress' ); ?></th>
						<td><p class="description"><?php esc_html_e( 'WooCommerce is not active — product attribution binding is unavailable.', 'luwipress' ); ?></p></td>
					</tr>
					<?php endif; ?>
				</table>
			</div>
		</details>

		<div class="lwp-ct-editor-actions">
			<button type="button" class="lp-btn lp-btn--primary" id="lwp-ct-save">
				<span class="dashicons dashicons-saved" aria-hidden="true"></span>
				<?php esc_html_e( 'Save content type', 'luwipress' ); ?>
			</button>
			<button type="button" class="lp-btn lp-btn--outline" id="lwp-ct-cancel"><?php esc_html_e( 'Cancel', 'luwipress' ); ?></button>
			<span id="lwp-ct-editor-status" class="lwp-ct-status" aria-live="polite"></span>
		</div>
	</div>

	<!-- ─────────── Types list ─────────── -->
	<div class="lwp-ct-list">
		<?php foreach ( $types as $tk => $def ) :
			$is_custom = isset( $def['source'] ) && 'option' === $def['source'];
			$name      = $display_name( $def );
			$icon      = ! empty( $def['labels']['menu_icon'] ) ? (string) $def['labels']['menu_icon'] : 'dashicons-admin-post';
			$fields    = isset( $def['field_schema'] ) ? $def['field_schema'] : array();
			$taxes     = isset( $def['taxonomies'] ) ? $def['taxonomies'] : array();
			$wc        = ( isset( $def['woocommerce'] ) && is_array( $def['woocommerce'] ) ) ? $def['woocommerce'] : array();
			$wc_meta   = ! empty( $wc['attribution_meta'] ) ? (string) $wc['attribution_meta'] : '';
			$schema_t  = ! empty( $def['schema_mapping']['type'] ) ? (string) $def['schema_mapping']['type'] : '';
			$pt_exists = post_type_exists( $def['post_type'] );
		?>
		<div class="luwipress-card lwp-ct-card" data-key="<?php echo esc_attr( $tk ); ?>">
			<div class="lwp-ct-card-head">
				<span class="dashicons <?php echo esc_attr( $icon ); ?> lwp-ct-card-icon" aria-hidden="true"></span>
				<div class="lwp-ct-card-titles">
					<h2 class="lwp-ct-card-name"><?php echo esc_html( $name ); ?></h2>
					<code class="lwp-ct-card-pt"><?php echo esc_html( $def['post_type'] ); ?></code>
				</div>
				<div class="lwp-ct-card-badges">
					<?php if ( $is_custom ) : ?>
						<span class="lp-pill pill-info"><?php esc_html_e( 'Custom', 'luwipress' ); ?></span>
					<?php else : ?>
						<span class="lp-pill pill-neutral"><?php esc_html_e( 'Preset', 'luwipress' ); ?></span>
					<?php endif; ?>
					<?php if ( ! empty( $def['enabled'] ) ) : ?>
						<span class="lp-pill pill-success"><?php esc_html_e( 'Enabled', 'luwipress' ); ?></span>
					<?php else : ?>
						<span class="lp-pill pill-warning"><?php esc_html_e( 'Disabled', 'luwipress' ); ?></span>
					<?php endif; ?>
				</div>
			</div>

			<div class="lwp-ct-card-meta">
				<span><?php echo esc_html( sprintf( /* translators: %d field count */ _n( '%d field', '%d fields', count( $fields ), 'luwipress' ), count( $fields ) ) ); ?></span>
				<span>·</span>
				<span><?php echo esc_html( sprintf( /* translators: %d taxonomy count */ _n( '%d taxonomy', '%d taxonomies', count( $taxes ), 'luwipress' ), count( $taxes ) ) ); ?></span>
				<?php if ( $schema_t ) : ?>
					<span>·</span><span><?php esc_html_e( 'Schema:', 'luwipress' ); ?> <code><?php echo esc_html( $schema_t ); ?></code></span>
				<?php endif; ?>
				<?php if ( $wc_meta ) : ?>
					<span>·</span><span class="lwp-ct-wc-bind"><span class="dashicons dashicons-cart" aria-hidden="true"></span> <code><?php echo esc_html( $wc_meta ); ?></code></span>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $fields ) ) : ?>
			<div class="lwp-ct-chips">
				<?php foreach ( $fields as $f ) :
					$fk = isset( $f['key'] ) ? (string) $f['key'] : '';
					$ft = isset( $f['type'] ) ? (string) $f['type'] : 'text';
					if ( '' === $fk ) { continue; }
				?>
					<span class="lwp-ct-chip" title="<?php echo esc_attr( $ft ); ?>"><?php echo esc_html( $fk ); ?> <span class="lwp-ct-chip-t"><?php echo esc_html( $ft ); ?></span></span>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>

			<div class="lwp-ct-card-actions">
				<?php if ( $is_custom ) : ?>
					<button type="button" class="button lwp-ct-edit" data-key="<?php echo esc_attr( $tk ); ?>">
						<span class="dashicons dashicons-edit" aria-hidden="true"></span> <?php esc_html_e( 'Edit', 'luwipress' ); ?>
					</button>
					<button type="button" class="button button-link-delete lwp-ct-delete" data-key="<?php echo esc_attr( $tk ); ?>" data-name="<?php echo esc_attr( $name ); ?>">
						<?php esc_html_e( 'Delete', 'luwipress' ); ?>
					</button>
				<?php elseif ( isset( $preset_setting_tabs[ $tk ] ) ) : ?>
					<a class="button" href="<?php echo esc_url( $preset_setting_tabs[ $tk ] ); ?>">
						<span class="dashicons dashicons-admin-generic" aria-hidden="true"></span> <?php esc_html_e( 'Settings →', 'luwipress' ); ?>
					</a>
				<?php endif; ?>
				<?php if ( $pt_exists && ! empty( $def['enabled'] ) ) : ?>
					<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . $def['post_type'] ) ); ?>">
						<span class="dashicons dashicons-list-view" aria-hidden="true"></span> <?php esc_html_e( 'Open posts', 'luwipress' ); ?>
					</a>
				<?php endif; ?>
			</div>
		</div>
		<?php endforeach; ?>
	</div>

	<!-- ─────────── WPML / Polylang config ─────────── -->
	<details class="lp-collapse lwp-ct-mt16" id="lwp-ct-wpml">
		<summary><span class="dashicons dashicons-translation"></span> <span><?php esc_html_e( 'WPML / Polylang configuration', 'luwipress' ); ?></span></summary>
		<div class="lp-collapse-body">
			<p class="description" id="lwp-ct-wpml-note"><?php esc_html_e( 'Vendors + Events presets ship a static wpml-config.xml read by both WPML and Polylang. Polylang also auto-registers every enabled custom type. For custom types on WPML, paste the XML below into WPML → Settings → Custom XML Configuration.', 'luwipress' ); ?></p>
			<textarea id="lwp-ct-wpml-xml" class="lwp-ct-mono lwp-ct-xml" readonly rows="10" spellcheck="false"></textarea>
			<div class="lwp-ct-mt8">
				<button type="button" class="button" id="lwp-ct-wpml-copy"><span class="dashicons dashicons-clipboard" aria-hidden="true"></span> <?php esc_html_e( 'Copy XML', 'luwipress' ); ?></button>
				<span id="lwp-ct-wpml-status" class="lwp-ct-status"></span>
			</div>
		</div>
	</details>

<?php if ( ! $luwipress_hub_mode ) : ?>
</div>
<?php endif; ?>

<style>
.lwp-ct-hero { margin: 0 0 16px; }
.lwp-ct-wc { font-size: 16px; }
.lwp-ct-toolbar { display: flex; align-items: center; gap: 12px; margin: 0 0 16px; }
.lwp-ct-status { font-size: 13px; color: var(--lp-text-secondary); }
.lwp-ct-status.is-ok  { color: var(--lp-success); }
.lwp-ct-status.is-err { color: var(--lp-error); }
.lwp-ct-mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
.lwp-ct-inline { margin-left: 20px; }
.lwp-ct-mt8 { margin-top: 8px; }
.lwp-ct-mt16 { margin-top: 16px; }

/* Editor */
.lwp-ct-editor-title { margin-top: 0; }
.lwp-ct-sub { margin: 18px 0 4px; display: flex; align-items: center; gap: 6px; font-size: 14px; }
.lwp-ct-fields th.lwp-ct-c, .lwp-ct-taxes th.lwp-ct-c { width: 70px; text-align: center; }
.lwp-ct-fields td, .lwp-ct-taxes td { vertical-align: middle; }
.lwp-ct-fields input[type=text], .lwp-ct-taxes input[type=text] { width: 100%; }
.lwp-ct-fields .lwp-ct-c, .lwp-ct-taxes .lwp-ct-c { text-align: center; }
.lwp-ct-fields select { width: 100%; }
.lwp-ct-editor-actions { display: flex; align-items: center; gap: 12px; margin-top: 18px; flex-wrap: wrap; }

/* List cards */
.lwp-ct-list { display: grid; gap: 14px; }
.lwp-ct-card { margin: 0; }
.lwp-ct-card-head { display: flex; align-items: center; gap: 12px; }
.lwp-ct-card-icon { font-size: 26px; width: 26px; height: 26px; color: var(--lp-primary); }
.lwp-ct-card-titles { flex: 1 1 auto; min-width: 0; }
.lwp-ct-card-name { margin: 0; font-size: 16px; line-height: 1.2; }
.lwp-ct-card-pt { font-size: 12px; color: var(--lp-text-secondary); }
.lwp-ct-card-badges { display: flex; gap: 6px; flex-wrap: wrap; }
.lwp-ct-card-meta { margin: 8px 0 0; font-size: 12px; color: var(--lp-text-secondary); display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
.lwp-ct-card-meta code { font-size: 11px; }
.lwp-ct-wc-bind .dashicons { font-size: 14px; width: 14px; height: 14px; vertical-align: text-bottom; }
.lwp-ct-chips { display: flex; gap: 6px; flex-wrap: wrap; margin: 10px 0 0; }
.lwp-ct-chip {
	display: inline-flex; align-items: center; gap: 5px;
	padding: 2px 8px; border-radius: 4px;
	background: var(--lp-bg-subtle, #f0f0f1);
	border: 1px solid var(--lp-border);
	font-size: 11px; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}
.lwp-ct-chip-t { color: var(--lp-text-secondary); font-size: 10px; }
.lwp-ct-card-actions { display: flex; gap: 8px; margin-top: 12px; flex-wrap: wrap; }

.lwp-ct-xml { width: 100%; font-size: 12px; }

@media (max-width: 782px) {
	.lwp-ct-card-head { flex-wrap: wrap; }
}
</style>

<script>
(function () {
	'use strict';
	var REST_BASE  = <?php echo wp_json_encode( $rest_base ); ?>;
	var REST_NONCE = <?php echo wp_json_encode( $rest_nonce ); ?>;
	var FIELD_TYPES = <?php echo wp_json_encode( $field_types ); ?>;
	var CT_CUSTOM   = <?php echo wp_json_encode( (object) $custom_defs ); ?>;
	var WC_ACTIVE   = <?php echo $wc_active ? 'true' : 'false'; ?>;
	var I18N = {
		add:    <?php echo wp_json_encode( __( 'Add content type', 'luwipress' ) ); ?>,
		edit:   <?php echo wp_json_encode( __( 'Edit content type', 'luwipress' ) ); ?>,
		saving: <?php echo wp_json_encode( __( 'Saving…', 'luwipress' ) ); ?>,
		saved:  <?php echo wp_json_encode( __( 'Saved — reloading…', 'luwipress' ) ); ?>,
		delok:  <?php echo wp_json_encode( __( 'Deleted — reloading…', 'luwipress' ) ); ?>,
		delq:   <?php echo wp_json_encode( __( 'Delete this content type? Posts of this type are NOT deleted; the type just stops being registered.', 'luwipress' ) ); ?>,
		needkey:<?php echo wp_json_encode( __( 'Key is required (a–z, 0–9, _).', 'luwipress' ) ); ?>,
		copied: <?php echo wp_json_encode( __( 'Copied.', 'luwipress' ) ); ?>
	};

	function api(path, opts) {
		opts = opts || {};
		opts.headers = opts.headers || {};
		opts.headers['X-WP-Nonce'] = REST_NONCE;
		opts.headers['Accept'] = 'application/json';
		if (opts.body && typeof opts.body !== 'string') {
			opts.headers['Content-Type'] = 'application/json';
			opts.body = JSON.stringify(opts.body);
		}
		return fetch(REST_BASE + path, opts).then(function (r) {
			return r.json().then(function (j) {
				if (!r.ok) throw new Error((j && (j.message || j.code)) || ('HTTP ' + r.status));
				return j;
			});
		});
	}

	function $(id) { return document.getElementById(id); }
	function setStatus(elm, msg, cls) {
		elm.textContent = msg || '';
		elm.className = 'lwp-ct-status' + (cls ? ' ' + cls : '');
	}

	/* ─────────── Field / taxonomy row builders ─────────── */
	function fieldRow(f) {
		f = f || {};
		var tr = document.createElement('tr');
		var typeOpts = FIELD_TYPES.map(function (t) {
			return '<option value="' + t + '"' + (f.type === t ? ' selected' : '') + '>' + t + '</option>';
		}).join('');
		tr.innerHTML =
			'<td><input type="text" class="lwp-ct-mono" data-k="key" value="' + esc(f.key) + '" placeholder="name"></td>' +
			'<td><input type="text" data-k="label" value="' + esc(f.label) + '" placeholder="Name"></td>' +
			'<td><select data-k="type">' + typeOpts + '</select></td>' +
			'<td class="lwp-ct-c"><input type="checkbox" data-k="translatable"' + (f.translatable ? ' checked' : '') + '></td>' +
			'<td class="lwp-ct-c"><input type="checkbox" data-k="in_elementor"' + (f.in_elementor === false ? '' : ' checked') + '></td>' +
			'<td><input type="text" class="lwp-ct-mono" data-k="schema_prop" value="' + esc(f.schema_prop) + '" placeholder=""></td>' +
			'<td><button type="button" class="button button-link-delete lwp-ct-rm">×</button></td>';
		return tr;
	}
	function taxRow(t) {
		t = t || {};
		var tr = document.createElement('tr');
		tr.innerHTML =
			'<td><input type="text" class="lwp-ct-mono" data-k="slug" value="' + esc(t.slug) + '" placeholder="department"></td>' +
			'<td><input type="text" data-k="label" value="' + esc(t.label) + '" placeholder="Department"></td>' +
			'<td class="lwp-ct-c"><input type="checkbox" data-k="hierarchical"' + (t.hierarchical ? ' checked' : '') + '></td>' +
			'<td class="lwp-ct-c"><input type="checkbox" data-k="translatable"' + (t.translatable === false ? '' : ' checked') + '></td>' +
			'<td><button type="button" class="button button-link-delete lwp-ct-rm">×</button></td>';
		return tr;
	}
	function esc(v) {
		return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
	}

	var fieldsBody = $('lwp-ct-fields-body');
	var taxesBody  = $('lwp-ct-taxes-body');
	$('lwp-ct-add-field').addEventListener('click', function () { fieldsBody.appendChild(fieldRow()); });
	$('lwp-ct-add-tax').addEventListener('click', function () { taxesBody.appendChild(taxRow()); });
	[fieldsBody, taxesBody].forEach(function (body) {
		body.addEventListener('click', function (e) {
			if (e.target.classList.contains('lwp-ct-rm')) {
				var row = e.target.closest('tr');
				if (row) row.remove();
			}
		});
	});

	/* ─────────── Editor open / fill / collect ─────────── */
	var editor = $('lwp-ct-editor');

	function openEditor(def) {
		def = def || null;
		var editing = !!def;
		$('lwp-ct-editor').querySelector('.lwp-ct-editor-title').textContent = editing ? I18N.edit : I18N.add;

		$('lwp-ct-key').value         = editing ? (def.key || '') : '';
		$('lwp-ct-key').readOnly       = editing;
		$('lwp-ct-post_type').value    = editing ? (def.post_type || '') : '';
		$('lwp-ct-post_type').readOnly = editing;
		var labels = def && def.labels ? def.labels : {};
		$('lwp-ct-singular').value  = labels.singular || '';
		$('lwp-ct-plural').value    = labels.plural || '';
		$('lwp-ct-menu_icon').value = labels.menu_icon || '';
		var perm = def && def.permalink ? def.permalink : {};
		$('lwp-ct-archive_slug').value = perm.archive_slug || '';
		$('lwp-ct-with_front').checked = !!perm.with_front;
		$('lwp-ct-enabled').checked    = def ? (def.enabled !== false) : true;
		$('lwp-ct-schema_type').value  = (def && def.schema_mapping && def.schema_mapping.type) ? def.schema_mapping.type : '';
		if (WC_ACTIVE) {
			var wc = def && def.woocommerce ? def.woocommerce : {};
			if ($('lwp-ct-wc_meta')) $('lwp-ct-wc_meta').value = wc.attribution_meta || '';
			if ($('lwp-ct-wc_role')) $('lwp-ct-wc_role').value = wc.attribution_role || '';
		}

		fieldsBody.innerHTML = '';
		(def && def.field_schema ? def.field_schema : []).forEach(function (f) { fieldsBody.appendChild(fieldRow(f)); });
		if (!fieldsBody.children.length) fieldsBody.appendChild(fieldRow());

		taxesBody.innerHTML = '';
		(def && def.taxonomies ? def.taxonomies : []).forEach(function (t) { taxesBody.appendChild(taxRow(t)); });

		setStatus($('lwp-ct-editor-status'), '');
		editor.hidden = false;
		editor.scrollIntoView({ behavior: 'smooth', block: 'start' });
	}

	function collect() {
		var key = ($('lwp-ct-key').value || '').trim().toLowerCase().replace(/[^a-z0-9_]/g, '');
		var pt  = ($('lwp-ct-post_type').value || '').trim().toLowerCase().replace(/[^a-z0-9_]/g, '') || key;
		var body = {
			key: key,
			post_type: pt,
			enabled: $('lwp-ct-enabled').checked ? 1 : 0,
			labels: {
				singular:  ($('lwp-ct-singular').value || '').trim(),
				plural:    ($('lwp-ct-plural').value || '').trim(),
				menu_icon: ($('lwp-ct-menu_icon').value || '').trim()
			},
			permalink: {
				archive_slug: ($('lwp-ct-archive_slug').value || '').trim(),
				with_front:   $('lwp-ct-with_front').checked ? 1 : 0
			},
			field_schema: [],
			taxonomies: []
		};
		Array.prototype.forEach.call(fieldsBody.querySelectorAll('tr'), function (tr) {
			var k = (tr.querySelector('[data-k="key"]').value || '').trim();
			if (!k) return;
			body.field_schema.push({
				key:          k,
				label:        (tr.querySelector('[data-k="label"]').value || '').trim(),
				type:         tr.querySelector('[data-k="type"]').value,
				translatable: tr.querySelector('[data-k="translatable"]').checked,
				in_elementor: tr.querySelector('[data-k="in_elementor"]').checked,
				schema_prop:  (tr.querySelector('[data-k="schema_prop"]').value || '').trim()
			});
		});
		Array.prototype.forEach.call(taxesBody.querySelectorAll('tr'), function (tr) {
			var s = (tr.querySelector('[data-k="slug"]').value || '').trim();
			if (!s) return;
			body.taxonomies.push({
				slug:         s,
				label:        (tr.querySelector('[data-k="label"]').value || '').trim(),
				hierarchical: tr.querySelector('[data-k="hierarchical"]').checked,
				translatable: tr.querySelector('[data-k="translatable"]').checked
			});
		});
		var st = ($('lwp-ct-schema_type').value || '').trim();
		if (st) body.schema_mapping = { type: st };
		if (WC_ACTIVE && $('lwp-ct-wc_meta') && ($('lwp-ct-wc_meta').value || '').trim()) {
			body.woocommerce = {
				attribution_meta: ($('lwp-ct-wc_meta').value || '').trim(),
				attribution_role: ($('lwp-ct-wc_role').value || '').trim()
			};
		}
		return body;
	}

	/* ─────────── Wire buttons ─────────── */
	$('lwp-ct-add').addEventListener('click', function () { openEditor(null); });
	$('lwp-ct-cancel').addEventListener('click', function () { editor.hidden = true; });

	document.querySelectorAll('.lwp-ct-edit').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var def = CT_CUSTOM[btn.getAttribute('data-key')];
			if (def) openEditor(def);
		});
	});

	document.querySelectorAll('.lwp-ct-delete').forEach(function (btn) {
		btn.addEventListener('click', function () {
			if (!window.confirm(I18N.delq)) return;
			var key = btn.getAttribute('data-key');
			setStatus($('lwp-ct-toolbar-status'), I18N.saving);
			api('cpt-engine/types/' + encodeURIComponent(key), { method: 'DELETE' })
				.then(function () {
					setStatus($('lwp-ct-toolbar-status'), I18N.delok, 'is-ok');
					window.location.reload();
				})
				.catch(function (e) { setStatus($('lwp-ct-toolbar-status'), e.message, 'is-err'); });
		});
	});

	$('lwp-ct-save').addEventListener('click', function () {
		var body = collect();
		if (!body.key) { setStatus($('lwp-ct-editor-status'), I18N.needkey, 'is-err'); return; }
		setStatus($('lwp-ct-editor-status'), I18N.saving);
		api('cpt-engine/types', { method: 'POST', body: body })
			.then(function () {
				setStatus($('lwp-ct-editor-status'), I18N.saved, 'is-ok');
				window.location.reload();
			})
			.catch(function (e) { setStatus($('lwp-ct-editor-status'), e.message, 'is-err'); });
	});

	/* ─────────── WPML config XML ─────────── */
	api('cpt-engine/wpml-config').then(function (j) {
		$('lwp-ct-wpml-xml').value = j.xml || '';
		if (j.wpml_active === false && j.polylang_active === false) {
			$('lwp-ct-wpml-note').textContent = <?php echo wp_json_encode( __( 'No translation plugin (WPML / Polylang) detected — this config applies only on multilingual sites.', 'luwipress' ) ); ?>;
		}
	}).catch(function () { /* non-fatal */ });

	$('lwp-ct-wpml-copy').addEventListener('click', function () {
		var ta = $('lwp-ct-wpml-xml');
		ta.select();
		var done = function () { setStatus($('lwp-ct-wpml-status'), I18N.copied, 'is-ok'); };
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(ta.value).then(done, function () { try { document.execCommand('copy'); done(); } catch (e) {} });
		} else {
			try { document.execCommand('copy'); done(); } catch (e) {}
		}
	});
})();
</script>
