<?php
/**
 * LuwiPress ACP Attribution Bridge
 *
 * Bridges Stripe Agentic Commerce Protocol (ACP) orders into the
 * conventional ad/analytics ecosystem. ACP orders bypass the browser —
 * no GA4 gtag, no Meta Pixel, no Google Ads gclid cookie — so server-to-
 * server attribution must be reconstructed from order metadata.
 *
 * What this dispatches per order:
 *  - GA4 Measurement Protocol `purchase` event
 *  - Meta Conversions API (CAPI) `Purchase` event with event_id dedup
 *  - Google Ads Enhanced Conversions (3.1.44 — OAuth flow, not yet here)
 *
 * What it reads from the order:
 *  - ACP `affiliate_attribution` (provider, publisher_id, campaign_id, source.url)
 *  - ACP `buyer` (email, name, phone) — fallback to WC billing
 *  - WC order totals, line items, currency
 *
 * Where it hooks:
 *  - `woocommerce_payment_complete` — primary trigger (fires after Stripe confirms)
 *  - `woocommerce_order_status_completed` — fallback for sync orders
 *  - `woocommerce_order_status_processing` — alt trigger when shipment-pending
 *
 * Sigorta:
 *  - Default OFF. Operator opts in via /attribution/settings POST.
 *  - Debug mode logs payloads instead of dispatching (test before flip).
 *  - Idempotency: per-order `_luwipress_attribution_dispatched` meta
 *    prevents double-fire on hook overlap.
 *
 * @package    LuwiPress
 * @subpackage Attribution
 * @since      3.1.43
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LuwiPress_ACP_Attribution {

    /** @var self|null */
    private static $instance = null;

    const OPTION_SETTINGS    = 'luwipress_attribution_settings';
    const OPTION_AUDIT_TABLE = 'luwipress_attribution_log';
    const META_DISPATCHED    = '_luwipress_attribution_dispatched';
    const META_EVENT_ID      = '_luwipress_attribution_event_id';
    const AUDIT_RETENTION    = 200; // last N events kept in option (rolling)

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    const CRON_HOOK = 'luwipress_attribution_dispatch';

    private function __construct() {
        // REST endpoints register regardless of WC presence so the operator
        // can configure secrets and read the audit log even on a WC-less
        // staging install. Dispatch hooks below are WC-only.
        add_action( 'rest_api_init', array( $this, 'register_endpoints' ) );

        // Cron consumer: runs the actual dispatch off the request thread.
        // Registered globally so a cron tick on a WC-less site is a harmless
        // no-op (the consumer itself guards on wc_get_order existence).
        add_action( self::CRON_HOOK, array( $this, 'cron_dispatch' ), 10, 1 );

        // WC may not be active — soft-skip dispatch hooks only.
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        // Multiple triggers cover sync/async/Stripe-confirm timings.
        // The handler enqueues a single-event cron job rather than firing
        // synchronously, so customer-facing pages (thank-you redirect,
        // Stripe webhook ack) don't wait on outbound HTTP to GA4 / Meta /
        // Google Ads — those add up to 15-20s in worst case.
        // Idempotency meta keeps each order dispatched once.
        add_action( 'woocommerce_payment_complete',         array( $this, 'enqueue_dispatch' ), 20, 1 );
        add_action( 'woocommerce_order_status_completed',   array( $this, 'enqueue_dispatch' ), 20, 1 );
        add_action( 'woocommerce_order_status_processing',  array( $this, 'enqueue_dispatch' ), 20, 1 );
    }

    /**
     * Hook handler: enqueue an async dispatch instead of firing inline.
     *
     * We schedule it ~3 seconds out so multi-hook chains (payment_complete +
     * status_processing on the same order) collapse into one cron job
     * (wp_schedule_single_event de-dupes by hook+args+timestamp window).
     */
    public function enqueue_dispatch( $order_id ) {
        $order_id = (int) $order_id;
        if ( $order_id <= 0 ) {
            return;
        }
        // Quick early-exit so we don't even schedule a cron if the bridge
        // is disabled — keeps the cron table clean on default-OFF installs.
        $settings = $this->get_settings();
        if ( empty( $settings['enabled'] ) ) {
            return;
        }
        if ( get_post_meta( $order_id, self::META_DISPATCHED, true ) ) {
            return;
        }
        if ( ! wp_next_scheduled( self::CRON_HOOK, array( $order_id ) ) ) {
            wp_schedule_single_event( time() + 3, self::CRON_HOOK, array( $order_id ) );
        }
    }

    /**
     * Cron consumer — runs `maybe_dispatch` off the request thread.
     */
    public function cron_dispatch( $order_id ) {
        $this->maybe_dispatch( (int) $order_id );
    }

    /* ───────────────────── REST Endpoints ───────────────────────────── */

    public function register_endpoints() {
        register_rest_route( 'luwipress/v1', '/attribution/settings', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'rest_get_settings' ),
                'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_save_settings' ),
                'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
            ),
        ) );

        register_rest_route( 'luwipress/v1', '/attribution/log', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_get_log' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
            'args'                => array(
                'limit' => array(
                    'default'           => 50,
                    'sanitize_callback' => 'absint',
                ),
            ),
        ) );

        register_rest_route( 'luwipress/v1', '/attribution/test', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_test' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        register_rest_route( 'luwipress/v1', '/attribution/dispatch', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_manual_dispatch' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
            'args'                => array(
                'order_id' => array(
                    'required'          => true,
                    'sanitize_callback' => 'absint',
                ),
                'force'    => array( 'default' => false ),
            ),
        ) );
    }

    /**
     * GET /attribution/settings — secrets masked.
     */
    public function rest_get_settings() {
        $s = $this->get_settings();
        return rest_ensure_response( $this->mask_settings( $s ) );
    }

    /**
     * POST /attribution/settings — partial-update.
     */
    public function rest_save_settings( $request ) {
        $body = $request->get_json_params();
        if ( ! is_array( $body ) ) {
            $body = $request->get_params();
        }
        $saved = $this->save_settings( $body );
        return rest_ensure_response( $this->mask_settings( $saved ) );
    }

    /**
     * GET /attribution/log — last N audit entries.
     */
    public function rest_get_log( $request ) {
        $limit = (int) $request->get_param( 'limit' );
        if ( $limit <= 0 ) {
            $limit = 50;
        }
        return rest_ensure_response( array(
            'entries' => $this->get_audit_log( $limit ),
        ) );
    }

    /**
     * POST /attribution/test — operator triggers a synthetic dispatch.
     */
    public function rest_test() {
        return rest_ensure_response( $this->test_dispatch() );
    }

    /**
     * POST /attribution/dispatch — manual re-dispatch for a specific order.
     * `force=true` clears the idempotency meta first (use with care — will
     * double-count in GA4/Meta if event_id is also rotated).
     */
    public function rest_manual_dispatch( $request ) {
        $order_id = (int) $request->get_param( 'order_id' );
        $force    = (bool) $request->get_param( 'force' );

        if ( $order_id <= 0 ) {
            return new WP_Error( 'invalid_order_id', 'order_id is required and must be positive.', array( 'status' => 400 ) );
        }
        if ( ! function_exists( 'wc_get_order' ) || ! wc_get_order( $order_id ) ) {
            return new WP_Error( 'order_not_found', 'Order not found or WooCommerce not active.', array( 'status' => 404 ) );
        }

        if ( $force ) {
            delete_post_meta( $order_id, self::META_DISPATCHED );
            delete_post_meta( $order_id, self::META_EVENT_ID );
        }

        $this->maybe_dispatch( $order_id );

        return rest_ensure_response( array(
            'order_id'   => $order_id,
            'dispatched' => (string) get_post_meta( $order_id, self::META_DISPATCHED, true ),
            'event_id'   => (string) get_post_meta( $order_id, self::META_EVENT_ID, true ),
        ) );
    }

    /**
     * Mask secret-bearing fields for REST output. Same defence-in-depth
     * pattern as /site-config (see feedback_never_leak_secrets_in_rest.md).
     */
    private function mask_settings( $s ) {
        $secret_keys = array(
            'ga4_api_secret',
            'meta_capi_token',
            'google_ads_developer_token',
            'google_ads_oauth_client_secret',
            'google_ads_oauth_refresh_token',
        );
        $out = $s;
        foreach ( $secret_keys as $k ) {
            $val = isset( $s[ $k ] ) ? (string) $s[ $k ] : '';
            $out[ $k ]              = ! empty( $val );
            $out[ $k . '_last4' ]   = ! empty( $val ) ? substr( $val, -4 ) : '';
        }
        return $out;
    }

    /**
     * Public read of settings (sanitized, secrets masked at the API layer
     * — full secrets here for internal dispatch use).
     */
    public function get_settings() {
        $defaults = array(
            'enabled'                 => false,
            'debug_mode'              => true,  // log only, don't fire
            'ga4_measurement_id'      => '',
            'ga4_api_secret'          => '',
            'meta_pixel_id'           => '',
            'meta_capi_token'         => '',
            'meta_test_event_code'    => '', // for Events Manager test mode
            // Google Ads Enhanced Conversions (3.1.44)
            'google_ads_customer_id'         => '',
            'google_ads_conversion_action'   => '', // resource name customers/X/conversionActions/Y
            'google_ads_developer_token'     => '',
            'google_ads_login_customer_id'   => '', // manager account, optional
            'google_ads_oauth_client_id'     => '',
            'google_ads_oauth_client_secret' => '',
            'google_ads_oauth_refresh_token' => '',
        );
        $stored = get_option( self::OPTION_SETTINGS, array() );
        if ( ! is_array( $stored ) ) {
            $stored = array();
        }
        return array_merge( $defaults, $stored );
    }

    /**
     * Save settings (partial-update — only present keys are touched, per
     * the canonical /enrich/settings pattern).
     */
    public function save_settings( $input ) {
        $current = $this->get_settings();
        if ( ! is_array( $input ) ) {
            return $current;
        }
        $allowed = array(
            'enabled'                        => 'bool',
            'debug_mode'                     => 'bool',
            'ga4_measurement_id'             => 'text',
            'ga4_api_secret'                 => 'text',
            'meta_pixel_id'                  => 'text',
            'meta_capi_token'                => 'text',
            'meta_test_event_code'           => 'text',
            'google_ads_customer_id'         => 'text',
            'google_ads_conversion_action'   => 'text',
            'google_ads_developer_token'     => 'text',
            'google_ads_login_customer_id'   => 'text',
            'google_ads_oauth_client_id'     => 'text',
            'google_ads_oauth_client_secret' => 'text',
            'google_ads_oauth_refresh_token' => 'text',
        );
        foreach ( $allowed as $key => $type ) {
            if ( ! array_key_exists( $key, $input ) ) {
                continue;
            }
            if ( 'bool' === $type ) {
                $current[ $key ] = ! empty( $input[ $key ] );
            } else {
                $current[ $key ] = sanitize_text_field( (string) $input[ $key ] );
            }
        }
        update_option( self::OPTION_SETTINGS, $current, false );
        return $current;
    }

    /**
     * Decide whether to dispatch and route to all enabled channels.
     *
     * @param int $order_id
     */
    public function maybe_dispatch( $order_id ) {
        $order_id = (int) $order_id;
        if ( $order_id <= 0 ) {
            return;
        }

        $settings = $this->get_settings();
        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        // Idempotency — once dispatched, never again.
        if ( get_post_meta( $order_id, self::META_DISPATCHED, true ) ) {
            return;
        }

        if ( ! function_exists( 'wc_get_order' ) ) {
            return;
        }
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $results = array();
        try {
            $payload  = $this->build_payload( $order );
            $event_id = $this->ensure_event_id( $order_id, $payload );

            if ( ! empty( $settings['ga4_measurement_id'] ) && ! empty( $settings['ga4_api_secret'] ) ) {
                $results['ga4'] = $this->dispatch_ga4( $payload, $event_id, $settings );
            }
            if ( ! empty( $settings['meta_pixel_id'] ) && ! empty( $settings['meta_capi_token'] ) ) {
                $results['meta_capi'] = $this->dispatch_meta_capi( $payload, $event_id, $settings );
            }
            if ( $this->google_ads_configured( $settings ) ) {
                $results['google_ads'] = $this->dispatch_google_ads( $payload, $event_id, $settings );
            }
        } catch ( Exception $e ) {
            $results['error'] = array(
                'status'  => 'exception',
                'message' => $e->getMessage(),
            );
            // Synthesize event_id if build_payload threw before reaching it.
            if ( ! isset( $event_id ) ) {
                $event_id = 'err_' . $order_id . '_' . time();
            }
            // Synthesize a minimal payload so audit log has SOMETHING to show.
            if ( ! isset( $payload ) ) {
                $payload = array(
                    'order_id'        => $order_id,
                    'value'           => 0,
                    'currency'        => '',
                    'agent_provider'  => '',
                    'agent_publisher' => '',
                );
            }
        }

        $this->record_audit( $order_id, $event_id, $payload, $results, ! empty( $settings['debug_mode'] ) );

        // Mark dispatched (idempotency). In debug mode we still mark so
        // operator can flip-and-test — re-dispatching the SAME order in
        // production would double-count GA4/Meta anyway.
        update_post_meta( $order_id, self::META_DISPATCHED, current_time( 'mysql' ) );
    }

    /**
     * Build a unified payload from a WC order — extracts ACP-specific
     * affiliate_attribution + buyer fields if the Stripe gateway saved
     * them as meta, falls back to WC billing data otherwise.
     */
    private function build_payload( $order ) {
        $order_id = (int) $order->get_id();
        $total    = (float) $order->get_total();
        $currency = (string) $order->get_currency();

        // ACP affiliate attribution. WooCommerce Stripe plugin stores ACP
        // order context in order meta with these candidate keys (the exact
        // key name has shifted between Stripe plugin versions, so we scan
        // a small list of fallbacks).
        $acp_provider = $this->first_meta( $order, array(
            '_stripe_acp_attribution_provider',
            '_acp_affiliate_attribution_provider',
            '_acp_attribution_provider',
        ) );
        $acp_publisher = $this->first_meta( $order, array(
            '_stripe_acp_attribution_publisher_id',
            '_acp_affiliate_attribution_publisher_id',
            '_acp_attribution_publisher_id',
        ) );
        $acp_campaign = $this->first_meta( $order, array(
            '_stripe_acp_attribution_campaign_id',
            '_acp_affiliate_attribution_campaign_id',
        ) );
        $acp_source_url = $this->first_meta( $order, array(
            '_stripe_acp_attribution_source_url',
            '_acp_affiliate_attribution_source_url',
        ) );

        $buyer_email = $order->get_billing_email();
        $buyer_first = $order->get_billing_first_name();
        $buyer_last  = $order->get_billing_last_name();
        $buyer_phone = $order->get_billing_phone();

        // Override with ACP buyer block when present (more authoritative
        // than the WC billing form, which may be auto-prefilled by Stripe).
        $acp_buyer_email = $this->first_meta( $order, array( '_stripe_acp_buyer_email', '_acp_buyer_email' ) );
        if ( ! empty( $acp_buyer_email ) ) {
            $buyer_email = $acp_buyer_email;
        }

        $items = array();
        foreach ( $order->get_items() as $item ) {
            if ( ! is_object( $item ) || ! method_exists( $item, 'get_product_id' ) ) {
                continue;
            }
            $name     = method_exists( $item, 'get_name' )     ? (string) $item->get_name()     : '';
            $quantity = method_exists( $item, 'get_quantity' ) ? (int)    $item->get_quantity() : 1;
            $items[] = array(
                'item_id'    => (string) $item->get_product_id(),
                'item_name'  => $name,
                'price'      => (float) $order->get_item_subtotal( $item, false ),
                'quantity'   => $quantity,
            );
        }

        return array(
            'order_id'        => $order_id,
            'transaction_id'  => 'wc_' . $order_id,
            'value'           => $total,
            'currency'        => $currency,
            'items'           => $items,
            'agent_provider'  => $acp_provider,    // e.g. 'chatgpt', 'perplexity'
            'agent_publisher' => $acp_publisher,
            'agent_campaign'  => $acp_campaign,
            'source_url'      => $acp_source_url,
            'buyer_email'     => $buyer_email,
            'buyer_first'     => $buyer_first,
            'buyer_last'      => $buyer_last,
            'buyer_phone'     => $buyer_phone,
            'client_ip'       => $this->client_ip( $order ),
            'user_agent'      => '', // ACP orders have no UA — left empty
            'event_time'      => time(),
        );
    }

    /**
     * Stable event_id for cross-channel deduplication. Stored on the order
     * so a re-dispatch (if ever forced) can carry the same id and Meta
     * CAPI / GA4 won't double-count.
     */
    private function ensure_event_id( $order_id, $payload ) {
        $existing = get_post_meta( $order_id, self::META_EVENT_ID, true );
        if ( ! empty( $existing ) ) {
            return $existing;
        }
        $event_id = sprintf( 'wc_%d_%d', $order_id, $payload['event_time'] );
        update_post_meta( $order_id, self::META_EVENT_ID, $event_id );
        return $event_id;
    }

    /* ───────────────────── GA4 Measurement Protocol ─────────────────── */

    /**
     * Send a `purchase` event via GA4 Measurement Protocol.
     *
     * Spec: https://developers.google.com/analytics/devguides/collection/protocol/ga4
     */
    private function dispatch_ga4( $payload, $event_id, $settings ) {
        $endpoint = add_query_arg(
            array(
                'measurement_id' => $settings['ga4_measurement_id'],
                'api_secret'     => $settings['ga4_api_secret'],
            ),
            'https://www.google-analytics.com/mp/collect'
        );

        // GA4 needs a stable client_id. ACP orders have no browser cookie,
        // so we synthesize one from the buyer email hash (or order id).
        $client_id = ! empty( $payload['buyer_email'] )
            ? hash( 'sha256', strtolower( trim( $payload['buyer_email'] ) ) )
            : 'wc_order_' . $payload['order_id'];

        $items_for_ga4 = array_map( function ( $i ) {
            return array(
                'item_id'    => $i['item_id'],
                'item_name'  => $i['item_name'],
                'price'      => $i['price'],
                'quantity'   => $i['quantity'],
            );
        }, $payload['items'] );

        $body = array(
            'client_id'        => $client_id,
            'timestamp_micros' => $payload['event_time'] * 1000000,
            'events' => array(
                array(
                    'name'   => 'purchase',
                    'params' => array(
                        'transaction_id' => $payload['transaction_id'],
                        'value'          => $payload['value'],
                        'currency'       => $payload['currency'],
                        'items'          => $items_for_ga4,
                        // Custom params for ACP attribution:
                        'agent_provider'  => $payload['agent_provider'] ?? '',
                        'agent_publisher' => $payload['agent_publisher'] ?? '',
                        'engagement_time_msec' => 1, // GA4 needs >0 to register
                    ),
                ),
            ),
        );

        if ( ! empty( $settings['debug_mode'] ) ) {
            return array( 'status' => 'debug_only', 'body' => $body );
        }

        $response = wp_remote_post( $endpoint, array(
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( $body ),
            'timeout' => 5,
        ) );

        if ( is_wp_error( $response ) ) {
            return array( 'status' => 'error', 'message' => $response->get_error_message() );
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        return array( 'status' => $code >= 200 && $code < 300 ? 'sent' : 'http_error', 'http_code' => $code );
    }

    /* ───────────────────── Meta Conversions API ─────────────────────── */

    /**
     * Send a `Purchase` event via Meta Conversions API.
     *
     * Spec: https://developers.facebook.com/docs/marketing-api/conversions-api/
     *
     * Includes event_id for browser/server deduplication if Pixel is also
     * firing (it isn't for ACP orders, but no harm in sending the id).
     */
    private function dispatch_meta_capi( $payload, $event_id, $settings ) {
        $endpoint = sprintf(
            'https://graph.facebook.com/v18.0/%s/events?access_token=%s',
            rawurlencode( $settings['meta_pixel_id'] ),
            rawurlencode( $settings['meta_capi_token'] )
        );

        $user_data = array();
        if ( ! empty( $payload['buyer_email'] ) ) {
            $user_data['em'] = array( hash( 'sha256', strtolower( trim( $payload['buyer_email'] ) ) ) );
        }
        if ( ! empty( $payload['buyer_phone'] ) ) {
            $user_data['ph'] = array( hash( 'sha256', preg_replace( '/[^0-9]/', '', $payload['buyer_phone'] ) ) );
        }
        if ( ! empty( $payload['buyer_first'] ) ) {
            $user_data['fn'] = array( hash( 'sha256', strtolower( trim( $payload['buyer_first'] ) ) ) );
        }
        if ( ! empty( $payload['buyer_last'] ) ) {
            $user_data['ln'] = array( hash( 'sha256', strtolower( trim( $payload['buyer_last'] ) ) ) );
        }
        if ( ! empty( $payload['client_ip'] ) ) {
            $user_data['client_ip_address'] = $payload['client_ip'];
        }

        $contents = array_map( function ( $i ) {
            return array(
                'id'         => $i['item_id'],
                'quantity'   => $i['quantity'],
                'item_price' => $i['price'],
            );
        }, $payload['items'] );

        $event = array(
            'event_name'       => 'Purchase',
            'event_time'       => $payload['event_time'],
            'event_id'         => $event_id,
            'event_source_url' => $payload['source_url'] ?? home_url( '/' ),
            'action_source'    => 'website',
            'user_data'        => $user_data,
            'custom_data'      => array(
                'currency'      => $payload['currency'],
                'value'         => $payload['value'],
                'contents'      => $contents,
                'order_id'      => (string) $payload['order_id'],
                'agent_provider'  => $payload['agent_provider'] ?? '',
                'agent_publisher' => $payload['agent_publisher'] ?? '',
            ),
        );

        $body = array( 'data' => array( $event ) );
        if ( ! empty( $settings['meta_test_event_code'] ) ) {
            $body['test_event_code'] = $settings['meta_test_event_code'];
        }

        if ( ! empty( $settings['debug_mode'] ) ) {
            return array( 'status' => 'debug_only', 'body' => $body );
        }

        $response = wp_remote_post( $endpoint, array(
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( $body ),
            'timeout' => 5,
        ) );

        if ( is_wp_error( $response ) ) {
            return array( 'status' => 'error', 'message' => $response->get_error_message() );
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        return array( 'status' => $code >= 200 && $code < 300 ? 'sent' : 'http_error', 'http_code' => $code );
    }

    /* ───────────────────── Google Ads Enhanced Conversions ─────────── */

    /**
     * Are all Google Ads dispatch requirements present?
     *
     * Minimum: customer_id + conversion_action + developer_token + OAuth
     * client_id + client_secret + refresh_token. login_customer_id is
     * optional (only needed for manager-account API access).
     */
    private function google_ads_configured( $settings ) {
        $required = array(
            'google_ads_customer_id',
            'google_ads_conversion_action',
            'google_ads_developer_token',
            'google_ads_oauth_client_id',
            'google_ads_oauth_client_secret',
            'google_ads_oauth_refresh_token',
        );
        foreach ( $required as $k ) {
            if ( empty( $settings[ $k ] ) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Send a click conversion (with enhanced conversion user_identifiers
     * fallback when no GCLID is present, which is always the case for
     * ACP orders) to Google Ads via the v17 REST API.
     *
     * Endpoint: POST https://googleads.googleapis.com/v17/customers/{cid}:uploadClickConversions
     * Auth:     Bearer access_token (refreshed from refresh_token)
     * Headers:  developer-token, login-customer-id (optional)
     *
     * Spec: https://developers.google.com/google-ads/api/rest/reference/rest/v17/customers/uploadClickConversions
     */
    private function dispatch_google_ads( $payload, $event_id, $settings ) {
        if ( ! empty( $settings['debug_mode'] ) ) {
            return array(
                'status' => 'debug_only',
                'body'   => $this->build_google_ads_body( $payload, $event_id, $settings ),
            );
        }

        $token = $this->refresh_google_oauth_token( $settings );
        if ( is_wp_error( $token ) ) {
            return array( 'status' => 'oauth_error', 'message' => $token->get_error_message() );
        }

        $customer_id = preg_replace( '/[^0-9]/', '', (string) $settings['google_ads_customer_id'] );
        $endpoint    = sprintf( 'https://googleads.googleapis.com/v17/customers/%s:uploadClickConversions', $customer_id );

        $headers = array(
            'Authorization'   => 'Bearer ' . $token,
            'developer-token' => (string) $settings['google_ads_developer_token'],
            'Content-Type'    => 'application/json',
        );
        if ( ! empty( $settings['google_ads_login_customer_id'] ) ) {
            $headers['login-customer-id'] = preg_replace( '/[^0-9]/', '', (string) $settings['google_ads_login_customer_id'] );
        }

        $body = $this->build_google_ads_body( $payload, $event_id, $settings );

        $response = wp_remote_post( $endpoint, array(
            'headers' => $headers,
            'body'    => wp_json_encode( $body ),
            'timeout' => 8,
        ) );

        if ( is_wp_error( $response ) ) {
            return array( 'status' => 'error', 'message' => $response->get_error_message() );
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $resp_body = wp_remote_retrieve_body( $response );
        if ( $code >= 200 && $code < 300 ) {
            return array( 'status' => 'sent', 'http_code' => $code );
        }
        return array(
            'status'    => 'http_error',
            'http_code' => $code,
            'response'  => substr( (string) $resp_body, 0, 500 ),
        );
    }

    /**
     * Build the uploadClickConversions request body.
     *
     * For ACP orders we have NO gclid (no browser click), so we lean on
     * Enhanced Conversions user_identifiers (hashed email/phone/name).
     * Per Google docs: "you can, and should, send all relevant data for
     * a given conversion, even if you don't have a GCLID for it."
     */
    private function build_google_ads_body( $payload, $event_id, $settings ) {
        $user_identifiers = array();
        if ( ! empty( $payload['buyer_email'] ) ) {
            $user_identifiers[] = array(
                'hashedEmail' => hash( 'sha256', strtolower( trim( $payload['buyer_email'] ) ) ),
            );
        }
        if ( ! empty( $payload['buyer_phone'] ) ) {
            $user_identifiers[] = array(
                'hashedPhoneNumber' => hash( 'sha256', preg_replace( '/[^0-9+]/', '', $payload['buyer_phone'] ) ),
            );
        }
        if ( ! empty( $payload['buyer_first'] ) || ! empty( $payload['buyer_last'] ) ) {
            $address = array();
            if ( ! empty( $payload['buyer_first'] ) ) {
                $address['hashedFirstName'] = hash( 'sha256', strtolower( trim( $payload['buyer_first'] ) ) );
            }
            if ( ! empty( $payload['buyer_last'] ) ) {
                $address['hashedLastName'] = hash( 'sha256', strtolower( trim( $payload['buyer_last'] ) ) );
            }
            if ( ! empty( $address ) ) {
                $user_identifiers[] = array( 'addressInfo' => $address );
            }
        }

        // RFC 3339 with timezone offset (Google Ads strict: must include offset).
        $tz = wp_timezone();
        $dt = new DateTimeImmutable( '@' . $payload['event_time'] );
        $dt = $dt->setTimezone( $tz );
        $conversion_date_time = $dt->format( 'Y-m-d H:i:sP' );

        $conversion = array(
            'conversionAction'   => (string) $settings['google_ads_conversion_action'],
            'conversionDateTime' => $conversion_date_time,
            'conversionValue'    => (float) $payload['value'],
            'currencyCode'       => (string) $payload['currency'],
            'orderId'            => (string) $payload['order_id'],
            'userIdentifiers'    => $user_identifiers,
        );

        // If somehow we ARE handed a gclid (some hybrid flows pass one
        // through ACP source.url), use it — Enhanced Conversions can
        // combine GCLID + user_identifiers for highest match quality.
        if ( ! empty( $payload['gclid'] ) ) {
            $conversion['gclid'] = (string) $payload['gclid'];
        }

        return array(
            'conversions'         => array( $conversion ),
            'partialFailure'      => true,
            'validateOnly'        => false,
        );
    }

    /**
     * Exchange the long-lived refresh_token for a short-lived access token.
     *
     * Cache hit window: 50 minutes (Google access tokens are valid 1h;
     * we leave 10min headroom).
     *
     * @return string|WP_Error Access token on success.
     */
    private function refresh_google_oauth_token( $settings ) {
        $cache_key = 'luwipress_gads_access_token';
        $cached    = get_transient( $cache_key );
        if ( ! empty( $cached ) ) {
            return $cached;
        }

        $response = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
            'timeout' => 8,
            'body'    => array(
                'client_id'     => $settings['google_ads_oauth_client_id'],
                'client_secret' => $settings['google_ads_oauth_client_secret'],
                'refresh_token' => $settings['google_ads_oauth_refresh_token'],
                'grant_type'    => 'refresh_token',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) || empty( $body['access_token'] ) ) {
            $msg = is_array( $body ) && ! empty( $body['error_description'] )
                ? $body['error_description']
                : 'OAuth token refresh returned no access_token';
            return new WP_Error( 'gads_oauth_failed', $msg );
        }

        set_transient( $cache_key, $body['access_token'], 50 * MINUTE_IN_SECONDS );
        return $body['access_token'];
    }

    /* ───────────────────── Audit Log ─────────────────────────────────── */

    /**
     * Append to the rolling audit log (last N events kept). Stored as an
     * option (no new DB table needed for sprint 3.1.43; if volume warrants
     * we promote to a custom table in 3.1.44+).
     */
    private function record_audit( $order_id, $event_id, $payload, $results, $debug ) {
        $log = get_option( self::OPTION_AUDIT_TABLE, array() );
        if ( ! is_array( $log ) ) {
            $log = array();
        }
        $log[] = array(
            'order_id'        => (int) $order_id,
            'event_id'        => $event_id,
            'agent_provider'  => $payload['agent_provider'] ?? '',
            'agent_publisher' => $payload['agent_publisher'] ?? '',
            'value'           => $payload['value'],
            'currency'        => $payload['currency'],
            'channels'        => $results,
            'debug'           => (bool) $debug,
            'time'            => current_time( 'mysql' ),
        );
        if ( count( $log ) > self::AUDIT_RETENTION ) {
            $log = array_slice( $log, -1 * self::AUDIT_RETENTION );
        }
        update_option( self::OPTION_AUDIT_TABLE, $log, false );
    }

    public function get_audit_log( $limit = 50 ) {
        $log = get_option( self::OPTION_AUDIT_TABLE, array() );
        if ( ! is_array( $log ) ) {
            return array();
        }
        $limit = max( 1, min( (int) $limit, self::AUDIT_RETENTION ) );
        return array_slice( $log, -1 * $limit );
    }

    /**
     * Manual test dispatch — operator triggers from settings UI to verify
     * credentials before going live. Builds a synthetic payload.
     */
    public function test_dispatch() {
        $settings = $this->get_settings();
        $payload = array(
            'order_id'        => 0,
            'transaction_id'  => 'luwipress_test_' . time(),
            'value'           => 1.00,
            'currency'        => function_exists( 'get_woocommerce_currency' ) ? ( get_woocommerce_currency() ?: 'USD' ) : 'USD',
            'items'           => array(
                array( 'item_id' => 'TEST', 'item_name' => 'LuwiPress test event', 'price' => 1.00, 'quantity' => 1 ),
            ),
            'agent_provider'  => 'luwipress-test',
            'agent_publisher' => 'luwipress-test',
            'agent_campaign'  => '',
            'source_url'      => home_url( '/' ),
            'buyer_email'     => 'test+luwipress@example.com',
            'buyer_first'     => 'Test',
            'buyer_last'      => 'User',
            'buyer_phone'     => '',
            'client_ip'       => '127.0.0.1',
            'user_agent'      => '',
            'event_time'      => time(),
        );
        $event_id = 'test_' . time();
        $results = array();
        if ( ! empty( $settings['ga4_measurement_id'] ) && ! empty( $settings['ga4_api_secret'] ) ) {
            $results['ga4'] = $this->dispatch_ga4( $payload, $event_id, $settings );
        }
        if ( ! empty( $settings['meta_pixel_id'] ) && ! empty( $settings['meta_capi_token'] ) ) {
            $results['meta_capi'] = $this->dispatch_meta_capi( $payload, $event_id, $settings );
        }
        if ( $this->google_ads_configured( $settings ) ) {
            $results['google_ads'] = $this->dispatch_google_ads( $payload, $event_id, $settings );
        }
        return array(
            'event_id' => $event_id,
            'channels' => $results,
            'debug'    => ! empty( $settings['debug_mode'] ),
        );
    }

    /* ───────────────────── Helpers ──────────────────────────────────── */

    /**
     * Return the first non-empty meta value from a list of candidate keys.
     */
    private function first_meta( $order, $keys ) {
        foreach ( $keys as $k ) {
            $v = $order->get_meta( $k, true );
            if ( ! empty( $v ) ) {
                return (string) $v;
            }
        }
        return '';
    }

    /**
     * Best-effort client IP for CAPI. ACP orders are server-to-server so
     * we record the request IP at the time of order creation if WC stored
     * it; otherwise blank. Meta CAPI tolerates empty IP — match quality
     * just drops slightly.
     */
    private function client_ip( $order ) {
        if ( method_exists( $order, 'get_customer_ip_address' ) ) {
            $ip = $order->get_customer_ip_address();
            if ( ! empty( $ip ) && filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                return $ip;
            }
        }
        return '';
    }
}
