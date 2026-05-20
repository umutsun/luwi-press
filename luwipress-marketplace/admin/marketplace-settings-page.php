<?php
/**
 * Marketplace settings page — standalone version, attached as a submenu
 * under the LuwiPress parent menu.
 *
 * Extracted from core's settings-page.php "Marketplaces" tab in the 3.1.44
 * companion split. All option keys preserved (still `luwipress_*`) so any
 * credentials saved before the split keep working without migration.
 *
 * Design language: matches the LuwiPress Dashboard — `lp-header` + `lp-pill`
 * semantic badges + `--lp-*` design tokens. The per-marketplace dot keeps the
 * brand identity colour (Amazon orange, eBay red, etc.) on purpose since
 * recognisability matters; everything else routes through the token system.
 *
 * @package LuwiPress_Marketplace_Sync
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Render the standalone Marketplaces page.
 */
function luwipress_marketplace_render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'You do not have permission to access this page.', 'luwipress-marketplace-sync' ) );
    }

    // Save handler — runs first so the rendered values reflect the new state.
    if ( isset( $_POST['luwipress_marketplace_save'] ) ) {
        if ( ! check_admin_referer( 'luwipress_marketplace_nonce' ) ) {
            wp_die( 'Invalid nonce.' );
        }
        luwipress_marketplace_save_settings();
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Marketplace settings saved.', 'luwipress-marketplace' ) . '</p></div>';
    }

    if ( isset( $_POST['luwipress_marketplace_add_custom'] ) ) {
        if ( ! check_admin_referer( 'luwipress_marketplace_nonce' ) ) {
            wp_die( 'Invalid nonce.' );
        }
        $custom_name = sanitize_text_field( wp_unslash( $_POST['custom_mp_name'] ?? '' ) );
        $custom_color = sanitize_text_field( wp_unslash( $_POST['custom_mp_color'] ?? '#6366f1' ) );
        if ( ! empty( $custom_name ) ) {
            $custom_markets = get_option( 'luwipress_custom_marketplaces', array() );
            $custom_id = 'custom_' . strtolower( wp_generate_password( 6, false, false ) );
            $custom_markets[ $custom_id ] = array(
                'name' => $custom_name,
                'color' => $custom_color,
            );
            update_option( 'luwipress_custom_marketplaces', $custom_markets );
            wp_safe_redirect( add_query_arg( array( 'page' => 'luwipress-marketplaces', 'added' => '1' ), admin_url( 'admin.php' ) ) );
            exit;
        }
    }

    if ( isset( $_GET['added'] ) && '1' === $_GET['added'] ) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Custom marketplace added.', 'luwipress-marketplace' ) . '</p></div>';
    }

    // Load current values dynamically from adapters
    $adapters = LuwiPress_Marketplace::get_instance()->get_all_adapters();
    $mp_cfg   = array();
    
    foreach ( $adapters as $slug => $adapter ) {
        $mp_cfg[ $slug ]['enabled'] = get_option( 'luwipress_marketplace_' . $slug . '_enabled', 0 );
        $schema = $adapter->get_settings_schema();
        foreach ( $schema as $field ) {
            $mp_cfg[ $slug ][ $field['id'] ] = get_option( $field['id'], $field['default'] ?? '' );
        }
    }

    $logo_url = defined( 'LUWIPRESS_PLUGIN_URL' ) ? LUWIPRESS_PLUGIN_URL . 'assets/images/luwi-logo.png' : '';

    // Aggregate counters for the hero stat row
    $count_live = 0;
    $count_ready = 0;
    foreach ( $adapters as $slug => $adapter ) {
        $en = ! empty( $mp_cfg[ $slug ]['enabled'] );
        $any_key = false;
        foreach ( $adapter->get_settings_schema() as $field ) {
            if ( in_array( $field['type'], array( 'password', 'text' ), true ) && ! empty( $mp_cfg[ $slug ][ $field['id'] ] ) ) {
                $any_key = true;
                break;
            }
        }
        if ( $en && $any_key ) { $count_live++; }
        elseif ( $any_key )    { $count_ready++; }
    }
    ?>
    <div class="wrap luwipress-admin luwipress-dashboard luwipress-marketplaces-page">

        <!-- ═══ HEADER ═══ -->
        <div class="lp-header">
            <div class="lp-header-left">
                <h1 class="lp-title">
                    <?php if ( $logo_url ) : ?>
                        <img class="lp-logo" width="28" height="28" src="<?php echo esc_url( $logo_url ); ?>" alt="LuwiPress" />
                    <?php endif; ?>
                    <?php esc_html_e( 'Marketplaces', 'luwipress-marketplace-sync' ); ?>
                </h1>
                <p class="lp-subtitle"><?php esc_html_e( 'Multi-channel publishing — connect your WooCommerce catalog to Amazon, eBay, Trendyol and more.', 'luwipress-marketplace-sync' ); ?></p>
            </div>
            <div class="lp-header-actions">
                <span class="lp-pill <?php echo $count_live > 0 ? 'pill-success' : 'pill-neutral'; ?>" title="<?php esc_attr_e( 'Marketplaces with credentials AND enabled flag', 'luwipress-marketplace-sync' ); ?>">
                    <?php
                    /* translators: %d: number of live marketplaces */
                    printf( esc_html__( '%d live', 'luwipress-marketplace-sync' ), (int) $count_live );
                    ?>
                </span>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=luwipress' ) ); ?>"
                   class="lp-pill lp-pill--action pill-neutral lp-pill--icon"
                   title="<?php esc_attr_e( 'Back to LuwiPress Dashboard', 'luwipress-marketplace-sync' ); ?>">
                    <span class="dashicons dashicons-admin-home"></span>
                    <span class="screen-reader-text"><?php esc_html_e( 'Dashboard', 'luwipress-marketplace-sync' ); ?></span>
                </a>
            </div>
        </div>

        <!-- ═══ STAT ROW ═══ -->
        <div class="luwipress-stats-row">
            <div class="luwipress-stat-card <?php echo $count_live > 0 ? 'stat-success' : ''; ?>">
                <div class="stat-icon"><span class="dashicons dashicons-yes-alt"></span></div>
                <div class="stat-content">
                    <span class="stat-number"><?php echo (int) $count_live; ?></span>
                    <span class="stat-label"><?php esc_html_e( 'Live channels', 'luwipress-marketplace-sync' ); ?></span>
                </div>
            </div>
            <div class="luwipress-stat-card <?php echo $count_ready > 0 ? 'stat-translation' : ''; ?>">
                <div class="stat-icon"><span class="dashicons dashicons-clock"></span></div>
                <div class="stat-content">
                    <span class="stat-number"><?php echo (int) $count_ready; ?></span>
                    <span class="stat-label"><?php esc_html_e( 'Configured but disabled', 'luwipress-marketplace-sync' ); ?></span>
                </div>
            </div>
            <div class="luwipress-stat-card">
                <div class="stat-icon"><span class="dashicons dashicons-cart"></span></div>
                <div class="stat-content">
                    <span class="stat-number"><?php echo (int) ( count( $adapters ) - $count_live - $count_ready ); ?></span>
                    <span class="stat-label"><?php esc_html_e( 'Untouched marketplaces', 'luwipress-marketplace-sync' ); ?></span>
                </div>
            </div>
            <div class="luwipress-stat-card">
                <div class="stat-icon"><span class="dashicons dashicons-rest-api"></span></div>
                <div class="stat-content">
                    <span class="stat-number"><?php echo (int) count( $adapters ); ?></span>
                    <span class="stat-label"><?php esc_html_e( 'Adapters available', 'luwipress-marketplace-sync' ); ?></span>
                </div>
            </div>
        </div>

        <!-- ═══ INTRO CARD ═══ -->
        <div class="luwipress-card luwipress-card--info">
            <p class="description" style="margin:0;line-height:1.6;">
                <?php esc_html_e( 'Credentials saved here drive the LuwiPress Marketplace REST endpoints (/wp-json/luwipress/v1/marketplace/*) and the publishing pipeline. Search to filter the catalog; click a card to expand its credential fields.', 'luwipress-marketplace-sync' ); ?>
            </p>
        </div>

        <form method="post" class="luwipress-settings-form">
            <?php wp_nonce_field( 'luwipress_marketplace_nonce' ); ?>

            <div class="lp-mp-search">
                <span class="dashicons dashicons-search"></span>
                <input type="text" id="lp-mp-filter" placeholder="<?php esc_attr_e( 'Search marketplaces…', 'luwipress-marketplace-sync' ); ?>" autocomplete="off" />
            </div>

            <div class="lp-mp-grid" id="lp-mp-grid">
            <?php
            foreach ( $adapters as $slug => $adapter ) :
                $label   = $adapter->get_label();
                $color   = $adapter->get_brand_color();
                $schema  = $adapter->get_settings_schema();
                $enabled = ! empty( $mp_cfg[ $slug ]['enabled'] );
                $has_key = false;
                foreach ( $schema as $field ) {
                    if ( in_array( $field['type'], array( 'password', 'text' ), true ) && ! empty( $mp_cfg[ $slug ][ $field['id'] ] ) ) {
                        $has_key = true; 
                        break;
                    }
                }
                $state_class = ( $enabled && $has_key ) ? 'lp-mp-connected' : '';
                $state_class .= $enabled ? ' open' : '';
            ?>
                <div class="lp-mp-card luwipress-card <?php echo esc_attr( trim( $state_class ) ); ?>" data-mp="<?php echo esc_attr( $slug ); ?>" data-name="<?php echo esc_attr( strtolower( $label ) ); ?>">
                    <div class="lp-mp-head" onclick="this.parentElement.classList.toggle('open')">
                        <span class="lp-mp-dot" style="background:<?php echo esc_attr( $color ); ?>"></span>
                        <span class="lp-mp-label"><?php echo esc_html( $label ); ?></span>
                        <?php if ( $enabled && $has_key ) : ?>
                            <span class="lp-pill pill-success"><?php esc_html_e( 'Live', 'luwipress-marketplace-sync' ); ?></span>
                        <?php elseif ( $has_key ) : ?>
                            <span class="lp-pill pill-info"><?php esc_html_e( 'Ready', 'luwipress-marketplace-sync' ); ?></span>
                        <?php else : ?>
                            <span class="lp-pill pill-neutral"><?php esc_html_e( 'Off', 'luwipress-marketplace-sync' ); ?></span>
                        <?php endif; ?>
                        <span class="lp-mp-chevron dashicons dashicons-arrow-down-alt2"></span>
                    </div>
                    <div class="lp-mp-fields">
                        <div class="lp-mp-enable">
                            <input type="checkbox" id="lp_en_<?php echo esc_attr( $slug ); ?>" name="luwipress_marketplace_<?php echo esc_attr( $slug ); ?>_enabled" value="1" <?php checked( $enabled ); ?> />
                            <label for="lp_en_<?php echo esc_attr( $slug ); ?>"><?php printf( esc_html__( 'Enable %s', 'luwipress-marketplace-sync' ), esc_html( $label ) ); ?></label>
                        </div>
                        <?php foreach ( $schema as $field ) :
                            $fn = $field['id']; $fl = $field['label']; $ft = $field['type']; 
                            $fv = $mp_cfg[ $slug ][ $fn ]; $fo = $field['options'] ?? array();
                        ?>
                        <div class="lp-mp-field">
                            <label for="<?php echo esc_attr( $fn ); ?>"><?php echo esc_html( $fl ); ?></label>
                            <?php if ( 'select' === $ft ) : ?>
                                <select id="<?php echo esc_attr( $fn ); ?>" name="<?php echo esc_attr( $fn ); ?>">
                                    <?php foreach ( $fo as $ov => $ol ) : ?>
                                        <option value="<?php echo esc_attr( $ov ); ?>" <?php selected( $fv, $ov ); ?>><?php echo esc_html( $ol ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif ( 'number' === $ft ) : ?>
                                <input type="number" id="<?php echo esc_attr( $fn ); ?>" name="<?php echo esc_attr( $fn ); ?>" value="<?php echo esc_attr( $fv ); ?>" min="1" />
                            <?php else : ?>
                                <input type="<?php echo esc_attr( $ft ); ?>" id="<?php echo esc_attr( $fn ); ?>" name="<?php echo esc_attr( $fn ); ?>" value="<?php echo esc_attr( $fv ); ?>" autocomplete="off" />
                                <?php if ( 'password' === $ft && ! empty( $fv ) ) : ?>
                                    <span class="lp-mp-ok dashicons dashicons-yes-alt"></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>

            <div class="lp-mp-empty" id="lp-mp-empty"><?php esc_html_e( 'No marketplaces match your search.', 'luwipress-marketplace' ); ?></div>

            <!-- Custom Marketplace Form -->
            <div class="luwipress-card luwipress-card--custom" style="margin-top: 24px;">
                <h3 style="margin-top:0; font-size:15px;"><?php esc_html_e( 'Add Custom Integration', 'luwipress-marketplace' ); ?></h3>
                <p class="description" style="margin-bottom:12px;"><?php esc_html_e( 'Define a custom webhook or REST API endpoint to sync your products.', 'luwipress-marketplace' ); ?></p>
                <div style="display:flex; gap:12px; align-items:flex-end;">
                    <div>
                        <label for="custom_mp_name" style="display:block; font-size:13px; font-weight:500; margin-bottom:4px;"><?php esc_html_e( 'Marketplace Name', 'luwipress-marketplace' ); ?></label>
                        <input type="text" id="custom_mp_name" name="custom_mp_name" placeholder="e.g. My Local Shop" style="width:200px; padding:7px 10px; border:1px solid var(--lp-border); border-radius:6px;" />
                    </div>
                    <div>
                        <label for="custom_mp_color" style="display:block; font-size:13px; font-weight:500; margin-bottom:4px;"><?php esc_html_e( 'Brand Color', 'luwipress-marketplace' ); ?></label>
                        <input type="color" id="custom_mp_color" name="custom_mp_color" value="#6366f1" style="height:35px; border:1px solid var(--lp-border); border-radius:6px; background:#fff; cursor:pointer;" />
                    </div>
                    <div>
                        <button type="submit" name="luwipress_marketplace_add_custom" class="button button-secondary"><?php esc_html_e( 'Add Integration', 'luwipress-marketplace' ); ?></button>
                    </div>
                </div>
            </div>

            <p class="submit">
                <button type="submit" name="luwipress_marketplace_save" class="button button-primary button-hero">
                    <span class="dashicons dashicons-saved"></span>
                    <?php esc_html_e( 'Save Marketplace Settings', 'luwipress-marketplace-sync' ); ?>
                </button>
            </p>
        </form>
    </div>

    <style>
        /* Marketplace cards — chrome built on top of .luwipress-card from admin.css */
        .luwipress-marketplaces-page .lp-subtitle { margin: 4px 0 0; color: var(--lp-text-secondary); font-size: 13px; }
        .luwipress-marketplaces-page .lp-mp-search { position: relative; margin: 18px 0 14px; max-width: 480px; }
        .luwipress-marketplaces-page .lp-mp-search input {
            width: 100%; padding: 10px 14px 10px 38px;
            border: 1px solid var(--lp-border); border-radius: var(--radius-md, 8px);
            font-size: 14px; background: var(--lp-surface); outline: none;
            color: var(--lp-text); transition: border-color .15s ease, box-shadow .15s ease;
        }
        .luwipress-marketplaces-page .lp-mp-search input:focus {
            border-color: var(--lp-primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.18);
        }
        .luwipress-marketplaces-page .lp-mp-search .dashicons {
            position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
            color: var(--lp-text-secondary); font-size: 16px;
        }
        .luwipress-marketplaces-page .lp-mp-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 12px;
        }
        .luwipress-marketplaces-page .lp-mp-card {
            margin: 0; padding: 0; overflow: hidden;
            transition: border-color .15s ease, box-shadow .15s ease;
        }
        .luwipress-marketplaces-page .lp-mp-card:hover { border-color: var(--lp-primary-light, #818cf8); }
        .luwipress-marketplaces-page .lp-mp-card.lp-mp-connected { border-left: 3px solid var(--lp-success); }
        .luwipress-marketplaces-page .lp-mp-card[hidden] { display: none; }
        .luwipress-marketplaces-page .lp-mp-head {
            display: flex; align-items: center; padding: 14px 16px; gap: 12px;
            cursor: pointer; user-select: none;
        }
        .luwipress-marketplaces-page .lp-mp-head:hover { background: var(--lp-surface-secondary, #f9fafb); }
        .luwipress-marketplaces-page .lp-mp-dot {
            width: 12px; height: 12px; border-radius: 9999px; flex-shrink: 0;
            box-shadow: inset 0 0 0 1px rgba(0,0,0,0.08);
        }
        .luwipress-marketplaces-page .lp-mp-label {
            font-weight: 600; font-size: 14px; color: var(--lp-text); flex: 1;
        }
        .luwipress-marketplaces-page .lp-mp-chevron {
            color: var(--lp-text-secondary); transition: transform .15s ease; font-size: 16px;
        }
        .luwipress-marketplaces-page .lp-mp-card.open .lp-mp-chevron { transform: rotate(180deg); }
        .luwipress-marketplaces-page .lp-mp-fields {
            display: none; padding: 0 16px 16px;
            border-top: 1px solid var(--lp-border-light);
        }
        .luwipress-marketplaces-page .lp-mp-card.open .lp-mp-fields { display: block; }
        .luwipress-marketplaces-page .lp-mp-field {
            display: flex; align-items: center; gap: 8px; margin-top: 12px;
        }
        .luwipress-marketplaces-page .lp-mp-field label {
            min-width: 110px; font-size: 13px; color: var(--lp-text-secondary); font-weight: 500;
        }
        .luwipress-marketplaces-page .lp-mp-field input[type="text"],
        .luwipress-marketplaces-page .lp-mp-field input[type="password"],
        .luwipress-marketplaces-page .lp-mp-field select {
            flex: 1; padding: 7px 10px;
            border: 1px solid var(--lp-border); border-radius: 6px;
            font-size: 13px; background: var(--lp-surface); outline: none;
            color: var(--lp-text); max-width: 280px;
        }
        .luwipress-marketplaces-page .lp-mp-field input:focus,
        .luwipress-marketplaces-page .lp-mp-field select:focus {
            border-color: var(--lp-primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
        }
        .luwipress-marketplaces-page .lp-mp-field input[type="number"] { width: 90px; flex: unset; }
        .luwipress-marketplaces-page .lp-mp-field .lp-mp-ok {
            color: var(--lp-success); font-size: 14px; flex-shrink: 0;
        }
        .luwipress-marketplaces-page .lp-mp-enable {
            margin-top: 14px; display: flex; align-items: center; gap: 8px;
        }
        .luwipress-marketplaces-page .lp-mp-enable label {
            font-size: 13px; color: var(--lp-text); font-weight: 500; cursor: pointer;
        }
        .luwipress-marketplaces-page .lp-mp-empty {
            text-align: center; padding: 48px;
            color: var(--lp-text-secondary); font-size: 14px; display: none;
        }
        .luwipress-marketplaces-page .submit { margin-top: 22px; }
        .luwipress-marketplaces-page .submit .button-hero .dashicons {
            font-size: 18px; width: 18px; height: 18px;
            vertical-align: middle; margin-right: 6px;
        }
        /* pill-info patch — mirror admin.css helper */
        .luwipress-marketplaces-page .lp-pill.pill-info {
            background: var(--lp-primary-50); color: var(--lp-primary-dark);
            border: 1px solid var(--lp-primary-100);
        }
    </style>

    <script>
    (function(){
        var input = document.getElementById('lp-mp-filter');
        var grid  = document.getElementById('lp-mp-grid');
        var empty = document.getElementById('lp-mp-empty');
        if (!input || !grid) return;

        input.addEventListener('input', function(){
            var q = this.value.toLowerCase().trim();
            var cards = grid.querySelectorAll('.lp-mp-card');
            var visible = 0;
            cards.forEach(function(c){
                var match = !q || c.getAttribute('data-name').indexOf(q) !== -1 || c.getAttribute('data-mp').indexOf(q) !== -1;
                c.hidden = !match;
                if (match) visible++;
            });
            empty.style.display = visible === 0 ? 'block' : 'none';
        });
    })();
    </script>
    <?php
}

/**
 * Persist marketplace settings dynamically.
 */
function luwipress_marketplace_save_settings() {
    $adapters = LuwiPress_Marketplace::get_instance()->get_all_adapters();
    
    foreach ( $adapters as $slug => $adapter ) {
        $enabled_key = 'luwipress_marketplace_' . $slug . '_enabled';
        update_option( $enabled_key, isset( $_POST[ $enabled_key ] ) ? 1 : 0 );
        
        $schema = $adapter->get_settings_schema();
        foreach ( $schema as $field ) {
            $opt = $field['id'];
            if ( ! isset( $_POST[ $opt ] ) ) {
                continue;
            }
            if ( 'number' === $field['type'] ) {
                update_option( $opt, absint( wp_unslash( $_POST[ $opt ] ) ) );
            } else {
                update_option( $opt, sanitize_text_field( wp_unslash( $_POST[ $opt ] ) ) );
            }
        }
    }
}
