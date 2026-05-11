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
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Marketplace settings saved.', 'luwipress-marketplace-sync' ) . '</p></div>';
    }

    // Load current values (option keys are unchanged from the pre-split layout).
    $mp_slugs = array( 'amazon', 'ebay', 'trendyol', 'alibaba', 'hepsiburada', 'n11', 'etsy', 'walmart' );
    $mp_cfg   = array();
    foreach ( $mp_slugs as $_s ) {
        $mp_cfg[ $_s ]['enabled'] = get_option( 'luwipress_marketplace_' . $_s . '_enabled', 0 );
    }
    $mp_cfg['amazon']['api_key']      = get_option( 'luwipress_amazon_api_key', '' );
    $mp_cfg['amazon']['seller_id']    = get_option( 'luwipress_amazon_seller_id', '' );
    $mp_cfg['amazon']['region']       = get_option( 'luwipress_amazon_region', 'na' );
    $mp_cfg['ebay']['api_key']        = get_option( 'luwipress_ebay_api_key', '' );
    $mp_cfg['ebay']['environment']    = get_option( 'luwipress_ebay_environment', 'sandbox' );
    $mp_cfg['ebay']['marketplace']    = get_option( 'luwipress_ebay_marketplace_id', 'EBAY_US' );
    $mp_cfg['trendyol']['api_key']    = get_option( 'luwipress_trendyol_api_key', '' );
    $mp_cfg['trendyol']['api_secret'] = get_option( 'luwipress_trendyol_api_secret', '' );
    $mp_cfg['trendyol']['seller_id']  = get_option( 'luwipress_trendyol_seller_id', '' );
    $mp_cfg['trendyol']['cargo']      = get_option( 'luwipress_trendyol_cargo_company_id', 10 );
    $mp_cfg['hepsiburada']['api_key']     = get_option( 'luwipress_hepsiburada_api_key', '' );
    $mp_cfg['hepsiburada']['api_secret']  = get_option( 'luwipress_hepsiburada_api_secret', '' );
    $mp_cfg['hepsiburada']['merchant_id'] = get_option( 'luwipress_hepsiburada_merchant_id', '' );
    $mp_cfg['n11']['api_key']    = get_option( 'luwipress_n11_api_key', '' );
    $mp_cfg['n11']['api_secret'] = get_option( 'luwipress_n11_api_secret', '' );
    $mp_cfg['alibaba']['app_key']      = get_option( 'luwipress_alibaba_app_key', '' );
    $mp_cfg['alibaba']['app_secret']   = get_option( 'luwipress_alibaba_app_secret', '' );
    $mp_cfg['alibaba']['access_token'] = get_option( 'luwipress_alibaba_access_token', '' );
    $mp_cfg['etsy']['api_key'] = get_option( 'luwipress_etsy_api_key', '' );
    $mp_cfg['etsy']['shop_id'] = get_option( 'luwipress_etsy_shop_id', '' );
    $mp_cfg['walmart']['client_id']     = get_option( 'luwipress_walmart_client_id', '' );
    $mp_cfg['walmart']['client_secret'] = get_option( 'luwipress_walmart_client_secret', '' );

    $logo_url = defined( 'LUWIPRESS_PLUGIN_URL' ) ? LUWIPRESS_PLUGIN_URL . 'assets/images/luwi-logo.png' : '';

    // Aggregate counters for the hero stat row so the operator sees coverage at a glance.
    $count_live = 0;
    $count_ready = 0;
    foreach ( $mp_slugs as $sl ) {
        $en = ! empty( $mp_cfg[ $sl ]['enabled'] );
        $any_key = false;
        foreach ( array_keys( $mp_cfg[ $sl ] ) as $k ) {
            if ( $k === 'enabled' ) { continue; }
            if ( ! empty( $mp_cfg[ $sl ][ $k ] ) ) { $any_key = true; break; }
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
                    <span class="stat-number"><?php echo (int) ( count( $mp_slugs ) - $count_live - $count_ready ); ?></span>
                    <span class="stat-label"><?php esc_html_e( 'Untouched marketplaces', 'luwipress-marketplace-sync' ); ?></span>
                </div>
            </div>
            <div class="luwipress-stat-card">
                <div class="stat-icon"><span class="dashicons dashicons-rest-api"></span></div>
                <div class="stat-content">
                    <span class="stat-number"><?php echo (int) count( $mp_slugs ); ?></span>
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
            $mp_defs = array(
                'amazon'      => array( 'Amazon',      '#ff9900', array(
                    array( 'luwipress_amazon_api_key',   'API Key',   'password', $mp_cfg['amazon']['api_key'] ),
                    array( 'luwipress_amazon_seller_id', 'Seller ID', 'text',     $mp_cfg['amazon']['seller_id'] ),
                    array( 'luwipress_amazon_region',    'Region',    'select',   $mp_cfg['amazon']['region'], array( 'na' => 'NA', 'eu' => 'EU', 'fe' => 'FE' ) ),
                )),
                'ebay'        => array( 'eBay',        '#e53238', array(
                    array( 'luwipress_ebay_api_key',        'OAuth Token', 'password', $mp_cfg['ebay']['api_key'] ),
                    array( 'luwipress_ebay_environment',    'Environment', 'select',   $mp_cfg['ebay']['environment'], array( 'sandbox' => 'Sandbox', 'production' => 'Production' ) ),
                    array( 'luwipress_ebay_marketplace_id', 'Market',      'select',   $mp_cfg['ebay']['marketplace'], array( 'EBAY_US' => 'US', 'EBAY_GB' => 'UK', 'EBAY_DE' => 'DE', 'EBAY_FR' => 'FR', 'EBAY_IT' => 'IT', 'EBAY_ES' => 'ES', 'EBAY_AU' => 'AU' ) ),
                )),
                'trendyol'    => array( 'Trendyol',    '#f27a1a', array(
                    array( 'luwipress_trendyol_api_key',    'API Key',    'password', $mp_cfg['trendyol']['api_key'] ),
                    array( 'luwipress_trendyol_api_secret', 'API Secret', 'password', $mp_cfg['trendyol']['api_secret'] ),
                    array( 'luwipress_trendyol_seller_id',  'Seller ID',  'text',     $mp_cfg['trendyol']['seller_id'] ),
                    array( 'luwipress_trendyol_cargo_company_id', 'Cargo ID', 'number', $mp_cfg['trendyol']['cargo'] ),
                )),
                'hepsiburada' => array( 'Hepsiburada', '#ff6000', array(
                    array( 'luwipress_hepsiburada_api_key',     'API Key',     'password', $mp_cfg['hepsiburada']['api_key'] ),
                    array( 'luwipress_hepsiburada_api_secret',  'API Secret',  'password', $mp_cfg['hepsiburada']['api_secret'] ),
                    array( 'luwipress_hepsiburada_merchant_id', 'Merchant ID', 'text',     $mp_cfg['hepsiburada']['merchant_id'] ),
                )),
                'n11'         => array( 'N11',         '#1a237e', array(
                    array( 'luwipress_n11_api_key',    'API Key',    'password', $mp_cfg['n11']['api_key'] ),
                    array( 'luwipress_n11_api_secret', 'API Secret', 'password', $mp_cfg['n11']['api_secret'] ),
                )),
                'alibaba'     => array( 'Alibaba',     '#ff6a00', array(
                    array( 'luwipress_alibaba_app_key',      'App Key',      'text',     $mp_cfg['alibaba']['app_key'] ),
                    array( 'luwipress_alibaba_app_secret',   'App Secret',   'password', $mp_cfg['alibaba']['app_secret'] ),
                    array( 'luwipress_alibaba_access_token', 'Access Token', 'password', $mp_cfg['alibaba']['access_token'] ),
                )),
                'etsy'        => array( 'Etsy',        '#f1641e', array(
                    array( 'luwipress_etsy_api_key', 'API Key', 'password', $mp_cfg['etsy']['api_key'] ),
                    array( 'luwipress_etsy_shop_id', 'Shop ID', 'text',     $mp_cfg['etsy']['shop_id'] ),
                )),
                'walmart'     => array( 'Walmart',     '#0071dc', array(
                    array( 'luwipress_walmart_client_id',     'Client ID',     'text',     $mp_cfg['walmart']['client_id'] ),
                    array( 'luwipress_walmart_client_secret', 'Client Secret', 'password', $mp_cfg['walmart']['client_secret'] ),
                )),
            );

            foreach ( $mp_defs as $slug => $mp ) :
                $label   = $mp[0];
                $color   = $mp[1];
                $fields  = $mp[2];
                $enabled = ! empty( $mp_cfg[ $slug ]['enabled'] );
                $has_key = false;
                foreach ( $fields as $f ) {
                    if ( in_array( $f[2], array( 'password', 'text' ), true ) && ! empty( $f[3] ) ) { $has_key = true; break; }
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
                        <?php foreach ( $fields as $f ) :
                            $fn = $f[0]; $fl = $f[1]; $ft = $f[2]; $fv = $f[3]; $fo = $f[4] ?? array();
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

            <div class="lp-mp-empty" id="lp-mp-empty"><?php esc_html_e( 'No marketplaces match your search.', 'luwipress-marketplace-sync' ); ?></div>

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
 * Persist marketplace settings (option keys mirror the pre-split layout —
 * any credentials saved by core 3.1.43 or earlier are read directly).
 */
function luwipress_marketplace_save_settings() {
    $mp_keys = array(
        'amazon_api_key', 'amazon_seller_id', 'amazon_region',
        'ebay_api_key', 'ebay_environment', 'ebay_marketplace_id',
        'trendyol_api_key', 'trendyol_api_secret', 'trendyol_seller_id', 'trendyol_cargo_company_id',
        'alibaba_app_key', 'alibaba_app_secret', 'alibaba_access_token',
        'hepsiburada_api_key', 'hepsiburada_api_secret', 'hepsiburada_merchant_id',
        'n11_api_key', 'n11_api_secret',
        'etsy_api_key', 'etsy_shop_id',
        'walmart_client_id', 'walmart_client_secret',
    );
    foreach ( $mp_keys as $k ) {
        $opt = 'luwipress_' . $k;
        if ( ! isset( $_POST[ $opt ] ) ) {
            continue;
        }
        if ( $k === 'trendyol_cargo_company_id' ) {
            update_option( $opt, absint( wp_unslash( $_POST[ $opt ] ) ) );
        } else {
            update_option( $opt, sanitize_text_field( wp_unslash( $_POST[ $opt ] ) ) );
        }
    }
    $mp_slugs = array( 'amazon', 'ebay', 'trendyol', 'alibaba', 'hepsiburada', 'n11', 'etsy', 'walmart' );
    foreach ( $mp_slugs as $slug ) {
        update_option( 'luwipress_marketplace_' . $slug . '_enabled', isset( $_POST[ 'luwipress_marketplace_' . $slug . '_enabled' ] ) ? 1 : 0 );
    }
}
