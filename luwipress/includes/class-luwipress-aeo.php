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
        // Pull params from every channel — internal MCP callers populate via
        // set_param() (proxy_rest_post in 3.1.42), real HTTP clients via JSON body.
        $json_data = $request->get_json_params() ?: array();
        $product_id = absint( $request->get_param('product_id') ?: ( $json_data['product_id'] ?? 0 ) );

        $wc_check = $this->require_woocommerce();
        if ( is_wp_error( $wc_check ) ) {
            return $wc_check;
        }

        if (!$product_id || !wc_get_product($product_id)) {
            return new WP_Error('invalid_product', 'Invalid product ID', ['status' => 400]);
        }

        // Accept both 'faq' (singular legacy) and 'faqs' (plural — matches the MCP
        // tool schema in WebMCP). Either works; whichever arrives first wins.
        $faq_items = $request->get_param('faqs')
            ?: $request->get_param('faq')
            ?: ( $json_data['faqs'] ?? $json_data['faq'] ?? array() );
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
        // 3.1.42: support both internal MCP callers (set_param) and HTTP JSON.
        $json_data = $request->get_json_params() ?: array();
        $product_id = absint( $request->get_param('product_id') ?: ( $json_data['product_id'] ?? 0 ) );

        $wc_check = $this->require_woocommerce();
        if ( is_wp_error( $wc_check ) ) {
            return $wc_check;
        }

        if (!$product_id || !wc_get_product($product_id)) {
            return new WP_Error('invalid_product', 'Invalid product ID', ['status' => 400]);
        }

        $howto = $request->get_param('howto') ?: $request->get_param('steps') ?: ( $json_data['howto'] ?? $json_data['steps'] ?? array() );
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

        $wc_check = $this->require_woocommerce();
        if ( is_wp_error( $wc_check ) ) {
            return $wc_check;
        }

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

        $all_total = intval($wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'product' AND post_status = 'publish'"
        ));

        if (0 === $all_total) {
            $empty_block = [
                'total_products'     => 0,
                'with_faq'           => 0,
                'with_howto'         => 0,
                'with_schema'        => 0,
                'with_speakable'     => 0,
                'faq_coverage'       => 0,
                'howto_coverage'     => 0,
                'schema_coverage'    => 0,
                'speakable_coverage' => 0,
            ];
            $result = array_merge( $empty_block, [
                'primary_language'   => $empty_block,
                'all_languages'      => $empty_block,
                'uncovered_products' => [],
            ] );
            set_transient( 'luwipress_aeo_coverage', $result, HOUR_IN_SECONDS );
            return rest_ensure_response( $result );
        }

        // Primary-language IDs (matches Knowledge Graph counting): on WPML use
        // icl_translations source rows, on Polylang use the default-language
        // term, otherwise fall back to all products. The KG dashboard uses the
        // same logic, so AEO numbers now line up with KG instead of being 4×
        // higher because translations are counted as separate posts.
        $primary_ids = $this->get_primary_language_product_ids();

        $all_counts = $wpdb->get_row(
            "SELECT
                COUNT(DISTINCT CASE WHEN meta_key = '_luwipress_faq' AND meta_value != '' AND meta_value != 'a:0:{}' THEN post_id END) AS with_faq,
                COUNT(DISTINCT CASE WHEN meta_key = '_luwipress_howto' AND meta_value != '' THEN post_id END) AS with_howto,
                COUNT(DISTINCT CASE WHEN meta_key = '_luwipress_schema' AND meta_value != '' THEN post_id END) AS with_schema,
                COUNT(DISTINCT CASE WHEN meta_key = '_luwipress_speakable' AND meta_value != '' THEN post_id END) AS with_speakable
             FROM {$wpdb->postmeta}
             WHERE meta_key IN ('_luwipress_faq', '_luwipress_howto', '_luwipress_schema', '_luwipress_speakable')"
        );

        $all_block = $this->build_coverage_block(
            $all_total,
            intval( $all_counts->with_faq ),
            intval( $all_counts->with_howto ),
            intval( $all_counts->with_schema ),
            intval( $all_counts->with_speakable )
        );

        if ( null !== $primary_ids ) {
            $primary_total = count( $primary_ids );
            if ( 0 === $primary_total ) {
                $primary_block = $this->build_coverage_block( 0, 0, 0, 0, 0 );
            } else {
                $primary_counts = $this->count_aeo_meta_for_ids( $primary_ids );
                $primary_block  = $this->build_coverage_block(
                    $primary_total,
                    $primary_counts['with_faq'],
                    $primary_counts['with_howto'],
                    $primary_counts['with_schema'],
                    $primary_counts['with_speakable']
                );
            }
        } else {
            // Single-language site — primary == all.
            $primary_block = $all_block;
        }

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

        // Top-level fields stay identical to pre-3.1.36 (back-compat for existing
        // dashboards) but mirror the all_languages block, which is what the old
        // numbers were computing. New consumers should read primary_language.*
        $result = array_merge( $all_block, [
            'primary_language'   => $primary_block,
            'all_languages'      => $all_block,
            'uncovered_products' => $uncovered,
        ] );

        set_transient( 'luwipress_aeo_coverage', $result, HOUR_IN_SECONDS );
        return rest_ensure_response( $result );
    }

    /**
     * Returns the post_ids of primary-language products only, or null when no
     * translation plugin is active (caller treats null as "primary == all").
     *
     * @return array<int>|null
     */
    private function get_primary_language_product_ids() {
        global $wpdb;

        if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
            $rows = $wpdb->get_col(
                "SELECT p.ID
                 FROM {$wpdb->posts} p
                 JOIN {$wpdb->prefix}icl_translations t
                   ON p.ID = t.element_id AND t.element_type = 'post_product'
                 WHERE p.post_type = 'product'
                   AND p.post_status = 'publish'
                   AND t.source_language_code IS NULL"
            );
            return array_map( 'intval', (array) $rows );
        }

        if ( defined( 'POLYLANG_VERSION' ) && function_exists( 'pll_default_language' ) ) {
            $default = pll_default_language();
            if ( $default ) {
                $rows = get_posts( array(
                    'post_type'      => 'product',
                    'post_status'    => 'publish',
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                    'lang'           => $default,
                ) );
                return array_map( 'intval', (array) $rows );
            }
        }

        return null;
    }

    /**
     * Count AEO meta rows scoped to a specific product ID set.
     *
     * @param array<int> $product_ids
     * @return array{with_faq:int,with_howto:int,with_schema:int,with_speakable:int}
     */
    private function count_aeo_meta_for_ids( $product_ids ) {
        global $wpdb;

        if ( empty( $product_ids ) ) {
            return array( 'with_faq' => 0, 'with_howto' => 0, 'with_schema' => 0, 'with_speakable' => 0 );
        }

        $placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );

        $sql = $wpdb->prepare(
            "SELECT
                COUNT(DISTINCT CASE WHEN meta_key = '_luwipress_faq' AND meta_value != '' AND meta_value != 'a:0:{}' THEN post_id END) AS with_faq,
                COUNT(DISTINCT CASE WHEN meta_key = '_luwipress_howto' AND meta_value != '' THEN post_id END) AS with_howto,
                COUNT(DISTINCT CASE WHEN meta_key = '_luwipress_schema' AND meta_value != '' THEN post_id END) AS with_schema,
                COUNT(DISTINCT CASE WHEN meta_key = '_luwipress_speakable' AND meta_value != '' THEN post_id END) AS with_speakable
             FROM {$wpdb->postmeta}
             WHERE meta_key IN ('_luwipress_faq', '_luwipress_howto', '_luwipress_schema', '_luwipress_speakable')
               AND post_id IN ($placeholders)",
            ...$product_ids
        );
        $row = $wpdb->get_row( $sql );

        return array(
            'with_faq'       => intval( $row->with_faq ),
            'with_howto'     => intval( $row->with_howto ),
            'with_schema'    => intval( $row->with_schema ),
            'with_speakable' => intval( $row->with_speakable ),
        );
    }

    /**
     * Build the standard coverage block shape used by both primary_language
     * and all_languages views.
     */
    private function build_coverage_block( $total, $faq, $howto, $schema, $speakable ) {
        $pct = function ( $count ) use ( $total ) {
            return $total > 0 ? round( ( $count / $total ) * 100, 1 ) : 0;
        };

        return array(
            'total_products'     => $total,
            'with_faq'           => $faq,
            'with_howto'         => $howto,
            'with_schema'        => $schema,
            'with_speakable'     => $speakable,
            'faq_coverage'       => $pct( $faq ),
            'howto_coverage'     => $pct( $howto ),
            'schema_coverage'    => $pct( $schema ),
            'speakable_coverage' => $pct( $speakable ),
        );
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
