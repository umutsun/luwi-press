<?php
/**
 * LuwiPress AEO (Answer Engine Optimization)
 *
 * Provides REST API endpoints for FAQ generation, HowTo schema,
 * speakable markup, and AEO coverage tracking.
 *
 * @package LuwiPress
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class LuwiPress_AEO {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('rest_api_init', [$this, 'register_endpoints']);
        // Schema output works without WooCommerce (reads from post meta)
        add_action('wp_head', [$this, 'output_aeo_schema'], 5);
    }

    /**
     * Check if WooCommerce is available (guard for wc_get_product calls)
     */
    private function require_woocommerce() {
        if ( ! function_exists( 'wc_get_product' ) ) {
            return new WP_Error( 'wc_required', 'WooCommerce is required for this endpoint.', array( 'status' => 501 ) );
        }
        return true;
    }

    /**
     * Register REST API endpoints
     */
    public function register_endpoints() {
        $namespace = 'luwipress/v1';

        register_rest_route($namespace, '/aeo/generate-faq', [
            'methods'             => 'POST',
            'callback'            => [$this, 'trigger_faq_generation'],
            'permission_callback' => [$this, 'check_admin_permission'],
            'args' => [
                'product_id' => ['required' => true, 'sanitize_callback' => 'absint'],
            ],
        ]);

        register_rest_route($namespace, '/aeo/generate-howto', [
            'methods'             => 'POST',
            'callback'            => [$this, 'trigger_howto_generation'],
            'permission_callback' => [$this, 'check_admin_permission'],
            'args' => [
                'product_id' => ['required' => true, 'sanitize_callback' => 'absint'],
            ],
        ]);

        register_rest_route($namespace, '/aeo/save-faq', [
            'methods'             => 'POST',
            'callback'            => [$this, 'save_faq_data'],
            'permission_callback' => [$this, 'check_token_permission'],
        ]);

        register_rest_route($namespace, '/aeo/save-howto', [
            'methods'             => 'POST',
            'callback'            => [$this, 'save_howto_data'],
            'permission_callback' => [$this, 'check_token_permission'],
        ]);

        register_rest_route($namespace, '/aeo/save-speakable', [
            'methods'             => 'POST',
            'callback'            => [$this, 'save_speakable_data'],
            'permission_callback' => [$this, 'check_token_permission'],
        ]);

        register_rest_route($namespace, '/aeo/coverage', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_aeo_coverage'],
            'permission_callback' => [$this, 'check_admin_permission'],
        ]);
    }

    /**
     * Admin permission check
     */
    public function check_admin_permission( $request ) {
        return LuwiPress_Permission::check_token_or_admin( $request );
    }

    public function check_token_permission( $request ) {
        return LuwiPress_Permission::check_token( $request );
    }

    /**
     * POST /aeo/generate-faq — Trigger FAQ generation
     */
    public function trigger_faq_generation( $request ) {
        $wc_check = $this->require_woocommerce();
        if ( is_wp_error( $wc_check ) ) return $wc_check;

        $product_id = $request->get_param( 'product_id' );
        $product    = wc_get_product( $product_id );
        if ( ! $product ) {
            return new WP_Error( 'not_found', 'Product not found', array( 'status' => 404 ) );
        }

        update_post_meta( $product_id, '_luwipress_aeo_faq_status', 'processing' );

        $result = $this->dispatch_aeo_generation( $product, array( 'types' => array( 'faq' ) ) );

        if ( is_wp_error( $result ) ) {
            update_post_meta( $product_id, '_luwipress_aeo_faq_status', 'failed' );
            return $result;
        }

        return rest_ensure_response( array(
            'status'     => ! empty( $result['async_forwarded'] ) ? 'queued' : 'completed',
            'product_id' => $product_id,
        ) );
    }

    /**
     * POST /aeo/generate-howto — Trigger HowTo generation
     */
    public function trigger_howto_generation( $request ) {
        $wc_check = $this->require_woocommerce();
        if ( is_wp_error( $wc_check ) ) return $wc_check;

        $product_id = $request->get_param( 'product_id' );
        $product    = wc_get_product( $product_id );
        if ( ! $product ) {
            return new WP_Error( 'not_found', 'Product not found', array( 'status' => 404 ) );
        }

        update_post_meta( $product_id, '_luwipress_aeo_howto_status', 'processing' );

        $result = $this->dispatch_aeo_generation( $product, array( 'types' => array( 'howto' ) ) );

        if ( is_wp_error( $result ) ) {
            update_post_meta( $product_id, '_luwipress_aeo_howto_status', 'failed' );
            return $result;
        }

        return rest_ensure_response( array(
            'status'     => ! empty( $result['async_forwarded'] ) ? 'queued' : 'completed',
            'product_id' => $product_id,
        ) );
    }

    /**
     * Dispatch AEO generation via the local AI Engine.
     */
    private function dispatch_aeo_generation( $product, $options = array() ) {
        $product_id = $product->get_id();

        $context = array(
            'name'        => $product->get_name(),
            'description' => wp_strip_all_tags( $product->get_description() ),
            'categories'  => wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'names' ) ),
        );

        $prompt   = LuwiPress_Prompts::aeo_faq( $context, $options );
        $messages = LuwiPress_AI_Engine::build_messages( $prompt );
        $ai_result = LuwiPress_AI_Engine::dispatch_json( 'aeo-generator', $messages, array(
            'max_tokens' => 2000,
        ) );

        if ( is_wp_error( $ai_result ) ) {
            return $ai_result;
        }

        // Feed into existing save handlers
        $types = $options['types'] ?? array( 'faq', 'howto' );

        if ( in_array( 'faq', $types, true ) && ! empty( $ai_result['faqs'] ) ) {
            $faq_request = new WP_REST_Request( 'POST', '/luwipress/v1/aeo/save-faq' );
            $faq_request->set_body_params( array(
                'product_id' => $product_id,
                'faq'        => $ai_result['faqs'],
            ) );
            $this->save_faq_data( $faq_request );
        }

        if ( in_array( 'howto', $types, true ) && ! empty( $ai_result['howto'] ) ) {
            $howto_request = new WP_REST_Request( 'POST', '/luwipress/v1/aeo/save-howto' );
            $howto_request->set_body_params( array(
                'product_id' => $product_id,
                'howto'      => $ai_result['howto'],
            ) );
            $this->save_howto_data( $howto_request );
        }

        if ( ! empty( $ai_result['speakable'] ) ) {
            $speak_request = new WP_REST_Request( 'POST', '/luwipress/v1/aeo/save-speakable' );
            $speak_request->set_body_params( array(
                'product_id' => $product_id,
                'speakable'  => $ai_result['speakable'],
            ) );
            $this->save_speakable_data( $speak_request );
        }

        return $ai_result;
    }

    /**
     * POST /aeo/save-faq — Async callback with generated FAQ data
     */
    public function save_faq_data($request) {
        $data = $request->get_json_params();
        $product_id = isset($data['product_id']) ? absint($data['product_id']) : 0;

        if (!$product_id || !wc_get_product($product_id)) {
            return new WP_Error('invalid_product', 'Invalid product ID', ['status' => 400]);
        }

        $faq_items = isset($data['faq']) ? $data['faq'] : [];
        if (empty($faq_items) || !is_array($faq_items)) {
            return new WP_Error('invalid_data', 'FAQ data is required', ['status' => 400]);
        }

        // Sanitize FAQ items
        $sanitized = [];
        foreach ($faq_items as $item) {
            if (!empty($item['question']) && !empty($item['answer'])) {
                $sanitized[] = [
                    'question' => sanitize_text_field($item['question']),
                    'answer'   => wp_kses_post($item['answer']),
                ];
            }
        }

        update_post_meta($product_id, '_luwipress_faq', $sanitized);
        update_post_meta($product_id, '_luwipress_aeo_faq_status', 'completed');
        update_post_meta($product_id, '_luwipress_aeo_faq_updated', current_time('c'));

        delete_transient( 'luwipress_aeo_coverage' );
        LuwiPress_Plugin_Detector::get_instance()->purge_post_cache($product_id);

        return rest_ensure_response([
            'status'     => 'saved',
            'product_id' => $product_id,
            'faq_count'  => count($sanitized),
        ]);
    }

    /**
     * POST /aeo/save-howto — Async callback with HowTo schema
     */
    public function save_howto_data($request) {
        $data = $request->get_json_params();
        $product_id = isset($data['product_id']) ? absint($data['product_id']) : 0;

        if (!$product_id || !wc_get_product($product_id)) {
            return new WP_Error('invalid_product', 'Invalid product ID', ['status' => 400]);
        }

        $howto = isset($data['howto']) ? $data['howto'] : [];
        if (empty($howto)) {
            return new WP_Error('invalid_data', 'HowTo data is required', ['status' => 400]);
        }

        // Sanitize HowTo steps
        if (isset($howto['steps']) && is_array($howto['steps'])) {
            foreach ($howto['steps'] as &$step) {
                $step['name'] = sanitize_text_field($step['name'] ?? '');
                $step['text'] = wp_kses_post($step['text'] ?? '');
                if (isset($step['image'])) {
                    $step['image'] = esc_url_raw($step['image']);
                }
            }
            unset($step);
        }

        $howto['name'] = sanitize_text_field($howto['name'] ?? '');
        $howto['description'] = wp_kses_post($howto['description'] ?? '');

        update_post_meta($product_id, '_luwipress_howto', $howto);
        update_post_meta($product_id, '_luwipress_aeo_howto_status', 'completed');
        update_post_meta($product_id, '_luwipress_aeo_howto_updated', current_time('c'));

        delete_transient( 'luwipress_aeo_coverage' );
        LuwiPress_Plugin_Detector::get_instance()->purge_post_cache($product_id);

        return rest_ensure_response([
            'status'     => 'saved',
            'product_id' => $product_id,
        ]);
    }

    /**
     * POST /aeo/save-speakable — Async callback with speakable content
     */
    public function save_speakable_data($request) {
        $data = $request->get_json_params();
        $product_id = isset($data['product_id']) ? absint($data['product_id']) : 0;

        if (!$product_id || !wc_get_product($product_id)) {
            return new WP_Error('invalid_product', 'Invalid product ID', ['status' => 400]);
        }

        $speakable = isset($data['speakable']) ? sanitize_text_field($data['speakable']) : '';
        if (empty($speakable)) {
            return new WP_Error('invalid_data', 'Speakable content is required', ['status' => 400]);
        }

        update_post_meta($product_id, '_luwipress_speakable', $speakable);
        update_post_meta($product_id, '_luwipress_aeo_speakable_updated', current_time('c'));

        delete_transient( 'luwipress_aeo_coverage' );
        LuwiPress_Plugin_Detector::get_instance()->purge_post_cache($product_id);

        return rest_ensure_response([
            'status'     => 'saved',
            'product_id' => $product_id,
        ]);
    }

    /**
     * GET /aeo/coverage — AEO coverage statistics
     */
    public function get_aeo_coverage($request) {
        // Return cached result if available (1 hour TTL)
        $cached = get_transient( 'luwipress_aeo_coverage' );
        if ( false !== $cached ) {
            return rest_ensure_response( $cached );
        }

        global $wpdb;

        $total_products = intval($wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'product' AND post_status = 'publish'"
        ));

        if (0 === $total_products) {
            $result = [
                'total_products' => 0,
                'faq_coverage'   => 0,
                'howto_coverage' => 0,
                'schema_coverage' => 0,
                'speakable_coverage' => 0,
            ];
            set_transient( 'luwipress_aeo_coverage', $result, HOUR_IN_SECONDS );
            return rest_ensure_response( $result );
        }

        // Consolidate 4 separate COUNT queries into 1
        $counts = $wpdb->get_row(
            "SELECT
                COUNT(DISTINCT CASE WHEN meta_key = '_luwipress_faq' AND meta_value != '' AND meta_value != 'a:0:{}' THEN post_id END) AS with_faq,
                COUNT(DISTINCT CASE WHEN meta_key = '_luwipress_howto' AND meta_value != '' THEN post_id END) AS with_howto,
                COUNT(DISTINCT CASE WHEN meta_key = '_luwipress_schema' AND meta_value != '' THEN post_id END) AS with_schema,
                COUNT(DISTINCT CASE WHEN meta_key = '_luwipress_speakable' AND meta_value != '' THEN post_id END) AS with_speakable
             FROM {$wpdb->postmeta}
             WHERE meta_key IN ('_luwipress_faq', '_luwipress_howto', '_luwipress_schema', '_luwipress_speakable')"
        );

        $with_faq       = intval( $counts->with_faq );
        $with_howto     = intval( $counts->with_howto );
        $with_schema    = intval( $counts->with_schema );
        $with_speakable = intval( $counts->with_speakable );

        // Get products that have NONE of the AEO elements — use sample_permalink for batch
        $products_without_any = $wpdb->get_results(
            "SELECT p.ID, p.post_title, p.post_name, p.post_type
             FROM {$wpdb->posts} p
             WHERE p.post_type = 'product' AND p.post_status = 'publish'
               AND p.ID NOT IN (
                   SELECT DISTINCT post_id FROM {$wpdb->postmeta}
                   WHERE meta_key IN ('_luwipress_faq', '_luwipress_howto', '_luwipress_schema', '_luwipress_speakable')
                     AND meta_value != '' AND meta_value != 'a:0:{}'
               )
             ORDER BY p.post_date DESC
             LIMIT 50"
        );

        // Prime post cache so get_permalink() uses cached objects
        update_post_caches( $products_without_any );

        $uncovered = [];
        foreach ($products_without_any as $p) {
            $uncovered[] = [
                'product_id' => $p->ID,
                'name'       => $p->post_title,
                'permalink'  => get_permalink($p->ID),
            ];
        }

        $result = [
            'total_products'     => $total_products,
            'with_faq'           => $with_faq,
            'with_howto'         => $with_howto,
            'with_schema'        => $with_schema,
            'with_speakable'     => $with_speakable,
            'faq_coverage'       => round(($with_faq / $total_products) * 100, 1),
            'howto_coverage'     => round(($with_howto / $total_products) * 100, 1),
            'schema_coverage'    => round(($with_schema / $total_products) * 100, 1),
            'speakable_coverage' => round(($with_speakable / $total_products) * 100, 1),
            'uncovered_products' => $uncovered,
        ];

        set_transient( 'luwipress_aeo_coverage', $result, HOUR_IN_SECONDS );
        return rest_ensure_response( $result );
    }

    /**
     * Output AEO schema markup in <head>
     */
    public function output_aeo_schema() {
        if (!is_singular('product')) {
            return;
        }

        global $post;
        $product_id = $post->ID;

        // Output FAQPage schema
        $faq = get_post_meta($product_id, '_luwipress_faq', true);
        if (!empty($faq) && is_array($faq)) {
            $faq_entities = [];
            foreach ($faq as $item) {
                if (!empty($item['question']) && !empty($item['answer'])) {
                    $faq_entities[] = [
                        '@type'          => 'Question',
                        'name'           => $item['question'],
                        'acceptedAnswer' => [
                            '@type' => 'Answer',
                            'text'  => $item['answer'],
                        ],
                    ];
                }
            }
            if (!empty($faq_entities)) {
                $faq_schema = [
                    '@context'   => 'https://schema.org',
                    '@type'      => 'FAQPage',
                    'mainEntity' => $faq_entities,
                ];
                echo '<script type="application/ld+json">' . wp_json_encode($faq_schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
            }
        }

        // Output HowTo schema
        $howto = get_post_meta($product_id, '_luwipress_howto', true);
        if (!empty($howto) && is_array($howto)) {
            $howto_schema = [
                '@context' => 'https://schema.org',
                '@type'    => 'HowTo',
                'name'     => $howto['name'] ?? '',
                'description' => $howto['description'] ?? '',
            ];

            if (!empty($howto['steps'])) {
                $howto_schema['step'] = [];
                foreach ($howto['steps'] as $i => $step) {
                    $s = [
                        '@type' => 'HowToStep',
                        'name'  => $step['name'],
                        'text'  => $step['text'],
                        'position' => $i + 1,
                    ];
                    if (!empty($step['image'])) {
                        $s['image'] = $step['image'];
                    }
                    $howto_schema['step'][] = $s;
                }
            }

            echo '<script type="application/ld+json">' . wp_json_encode($howto_schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
        }

        // Output Speakable schema
        $speakable = get_post_meta($product_id, '_luwipress_speakable', true);
        if (!empty($speakable)) {
            $speakable_schema = [
                '@context' => 'https://schema.org',
                '@type'    => 'WebPage',
                'name'     => get_the_title($product_id),
                'speakable' => [
                    '@type'    => 'SpeakableSpecification',
                    'xpath'    => ['/html/head/title', "/html/body//div[@class='luwipress-speakable']"],
                ],
            ];

            echo '<script type="application/ld+json">' . wp_json_encode($speakable_schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
        }
    }

}
