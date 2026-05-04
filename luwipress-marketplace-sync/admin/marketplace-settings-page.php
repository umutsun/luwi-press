<?php
/**
 * Marketplace settings page — standalone version, attached as a submenu
 * under the LuwiPress parent menu.
 *
 * Extracted from core's settings-page.php "Marketplaces" tab in the 3.1.44
 * companion split. All option keys preserved (still `luwipress_*`) so any
 * credentials saved before the split keep working without migration.
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

    ?>
    <div class="wrap luwipress-settings">
        <h1><?php esc_html_e( 'LuwiPress Marketplaces', 'luwipress-marketplace-sync' ); ?></h1>

        <p class="description" style="max-width:780px;line-height:1.6;">
            <?php esc_html_e( 'Connect your WooCommerce catalog to multi-channel marketplaces. Credentials saved here are used by the LuwiPress Marketplace REST endpoints (/wp-json/luwipress/v1/marketplace/*) and the publishing pipeline.', 'luwipress-marketplace-sync' ); ?>
        </p>

        <form method="post" class="luwipress-settings-form">
            <?php wp_nonce_field( 'luwipress_marketplace_nonce' ); ?>

            <style>
                .lp-mp-search{position:relative;margin-bottom:16px;max-width:480px}
                .lp-mp-search input{width:100%;padding:10px 14px 10px 38px;border:1px solid #d0d5dd;border-radius:8px;font-size:14px;background:#fff;outline:none}
                .lp-mp-search input:focus{border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.12)}
                .lp-mp-search .dashicons{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:16px}
                .lp-mp-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:12px}
                .lp-mp-card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;transition:border-color .15s ease,box-shadow .15s ease}
                .lp-mp-card:hover{border-color:#93c5fd;box-shadow:0 1px 3px rgba(0,0,0,.06)}
                .lp-mp-card.lp-mp-connected{border-color:#16a34a}
                .lp-mp-card[hidden]{display:none}
                .lp-mp-head{display:flex;align-items:center;padding:12px 16px;gap:12px;cursor:pointer;user-select:none}
                .lp-mp-dot{width:10px;height:10px;border-radius:9999px;flex-shrink:0}
                .lp-mp-label{font-weight:600;font-size:14px;color:#1f2937;flex:1}
                .lp-mp-status{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;padding:2px 8px;border-radius:9999px}
                .lp-mp-status.on{background:#dcfce7;color:#16a34a}
                .lp-mp-status.cfg{background:#dbeafe;color:#2563eb}
                .lp-mp-status.off{background:#f3f4f6;color:#6b7280}
                .lp-mp-chevron{color:#9ca3af;transition:transform .15s ease;font-size:16px}
                .lp-mp-card.open .lp-mp-chevron{transform:rotate(180deg)}
                .lp-mp-fields{display:none;padding:0 16px 16px;border-top:1px solid #f3f4f6}
                .lp-mp-card.open .lp-mp-fields{display:block}
                .lp-mp-field{display:flex;align-items:center;gap:8px;margin-top:12px}
                .lp-mp-field label{min-width:100px;font-size:13px;color:#6b7280;font-weight:500}
                .lp-mp-field input[type="text"],.lp-mp-field input[type="password"],.lp-mp-field select{flex:1;padding:7px 10px;border:1px solid #d0d5dd;border-radius:6px;font-size:13px;background:#fff;outline:none;max-width:280px}
                .lp-mp-field input:focus,.lp-mp-field select:focus{border-color:#3b82f6}
                .lp-mp-field input[type="number"]{width:80px;flex:unset}
                .lp-mp-field .lp-mp-ok{color:#16a34a;font-size:14px;flex-shrink:0}
                .lp-mp-enable{margin-top:12px;display:flex;align-items:center;gap:8px}
                .lp-mp-enable label{font-size:13px;color:#1f2937;font-weight:500;cursor:pointer}
                .lp-mp-empty{text-align:center;padding:48px;color:#9ca3af;font-size:14px;display:none}
            </style>

            <div class="lp-mp-search">
                <span class="dashicons dashicons-search"></span>
                <input type="text" id="lp-mp-filter" placeholder="<?php esc_attr_e( 'Search marketplaces...', 'luwipress-marketplace-sync' ); ?>" autocomplete="off" />
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
                <div class="lp-mp-card <?php echo esc_attr( trim( $state_class ) ); ?>" data-mp="<?php echo esc_attr( $slug ); ?>" data-name="<?php echo esc_attr( strtolower( $label ) ); ?>">
                    <div class="lp-mp-head" onclick="this.parentElement.classList.toggle('open')">
                        <span class="lp-mp-dot" style="background:<?php echo esc_attr( $color ); ?>"></span>
                        <span class="lp-mp-label"><?php echo esc_html( $label ); ?></span>
                        <?php if ( $enabled && $has_key ) : ?>
                            <span class="lp-mp-status on"><?php esc_html_e( 'Live', 'luwipress-marketplace-sync' ); ?></span>
                        <?php elseif ( $has_key ) : ?>
                            <span class="lp-mp-status cfg"><?php esc_html_e( 'Ready', 'luwipress-marketplace-sync' ); ?></span>
                        <?php else : ?>
                            <span class="lp-mp-status off"><?php esc_html_e( 'Off', 'luwipress-marketplace-sync' ); ?></span>
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

            <p class="submit">
                <input type="submit" name="luwipress_marketplace_save" class="button-primary" value="<?php esc_attr_e( 'Save Marketplace Settings', 'luwipress-marketplace-sync' ); ?>" />
            </p>
        </form>
    </div>
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
