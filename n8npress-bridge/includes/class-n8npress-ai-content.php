<?php
/**
 * n8nPress AI Content Pipeline
 *
 * Handles product enrichment, schema generation, FAQ generation
 * by triggering n8n workflows and receiving results back.
 *
 * @package n8nPress
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class N8nPress_AI_Content {

    private static $instance = null;

    /** n8n webhook base URL */
    private $n8n_webhook_url;

    /** API token for n8n auth */
    private $n8n_api_token;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->n8n_webhook_url = get_option('n8npress_seo_webhook_url', '');
        $this->n8n_api_token   = get_option('n8npress_seo_api_token', '');

        add_action('rest_api_init', array($this, 'register_endpoints'));
        add_action('add_meta_boxes', array($this, 'add_product_meta_box'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_product_scripts'));

        // Auto-enrich hook: fires when a product is first published (WooCommerce required)
        if ( class_exists( 'WooCommerce' ) ) {
            add_action('woocommerce_new_product', array($this, 'maybe_auto_enrich'), 20, 2);
        }

        // Thin content auto-enrichment cron
        add_action('n8npress_auto_enrich_thin_cron', array($this, 'run_thin_content_enrichment'));
        $thin_enabled = get_option('n8npress_auto_enrich_thin', false);
        if ( $thin_enabled && ! wp_next_scheduled('n8npress_auto_enrich_thin_cron') ) {
            wp_schedule_event(time(), 'daily', 'n8npress_auto_enrich_thin_cron');
        } elseif ( ! $thin_enabled && wp_next_scheduled('n8npress_auto_enrich_thin_cron') ) {
            wp_clear_scheduled_hook('n8npress_auto_enrich_thin_cron');
        }

        // AJAX handler for batch enrichment
        add_action('wp_ajax_n8npress_batch_enrich', array($this, 'ajax_batch_enrich'));
    }

    /**
     * Register REST API endpoints
     */
    public function register_endpoints() {
        // Trigger product enrichment → sends to n8n
        register_rest_route('n8npress/v1', '/product/enrich', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_enrich_request'),
            'permission_callback' => array($this, 'check_admin_permission'),
            'args'                => array(
                'product_id' => array('required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint'),
                'options'    => array('required' => false, 'type' => 'object', 'default' => array()),
            ),
        ));

        // Callback from n8n: receives enriched data
        register_rest_route('n8npress/v1', '/product/enrich-callback', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_enrich_callback'),
            'permission_callback' => array($this, 'check_n8n_token'),
        ));

        // Save/update schema markup for any post/product
        register_rest_route('n8npress/v1', '/seo/schema', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_save_schema'),
            'permission_callback' => array($this, 'check_n8n_token'),
        ));

        // Save FAQ data for any post/product
        register_rest_route('n8npress/v1', '/seo/faq', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_save_faq'),
            'permission_callback' => array($this, 'check_n8n_token'),
        ));

        // Batch product enrichment → sends multiple products to n8n
        register_rest_route('n8npress/v1', '/product/enrich-batch', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_batch_enrich_request'),
            'permission_callback' => array($this, 'check_admin_permission'),
            'args'                => array(
                'product_ids' => array('required' => true, 'type' => 'array', 'items' => array('type' => 'integer')),
                'options'     => array('required' => false, 'type' => 'object', 'default' => array()),
            ),
        ));

        // Batch enrichment status check
        register_rest_route('n8npress/v1', '/product/enrich-batch/status', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'handle_batch_status'),
            'permission_callback' => array($this, 'check_admin_permission'),
            'args'                => array(
                'batch_id' => array('required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'),
            ),
        ));

        // List stale content (not updated in X days)
        register_rest_route('n8npress/v1', '/content/stale', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'handle_stale_content'),
            'permission_callback' => array($this, 'check_admin_permission'),
            'args'                => array(
                'days'      => array('default' => 90, 'sanitize_callback' => 'absint'),
                'post_type' => array('default' => 'product', 'sanitize_callback' => 'sanitize_text_field'),
                'per_page'  => array('default' => 50, 'sanitize_callback' => 'absint'),
            ),
        ));
    }

    // ─── TRIGGER: Send product to n8n for enrichment ────────────────────

    /**
     * Handle enrich request from WP admin
     */
    public function handle_enrich_request($request) {
        $product_id = $request->get_param('product_id');
        $options    = $request->get_param('options');

        $product = wc_get_product($product_id);
        if (!$product) {
            return new WP_Error('not_found', 'Product not found.', array('status' => 404));
        }

        $result = $this->send_to_n8n_for_enrichment($product, $options);

        if (is_wp_error($result)) {
            return $result;
        }

        // Mark product as "enrichment in progress"
        update_post_meta($product_id, '_n8npress_enrich_status', 'processing');
        update_post_meta($product_id, '_n8npress_enrich_requested', current_time('mysql'));

        N8nPress_Logger::log('Product enrichment triggered', 'info', array(
            'product_id' => $product_id,
            'product_title' => $product->get_name(),
        ));

        return array(
            'success'    => true,
            'product_id' => $product_id,
            'status'     => 'processing',
            'message'    => 'Product sent to AI pipeline for enrichment.',
        );
    }

    /**
     * Build product payload and POST to n8n webhook
     */
    private function send_to_n8n_for_enrichment($product, $options = array()) {
        $budget = N8nPress_Token_Tracker::check_budget( 'product-enricher' );
        if ( is_wp_error( $budget ) ) {
            return $budget;
        }

        if (empty($this->n8n_webhook_url)) {
            return new WP_Error('no_webhook', 'n8n webhook URL is not configured.', array('status' => 500));
        }

        $product_id = $product->get_id();

        // Atomic lock: prevent concurrent enrichment of same product
        $lock_key = 'n8npress_enrich_lock_' . $product_id;
        if ( get_transient( $lock_key ) ) {
            return new WP_Error( 'already_processing', 'Product #' . $product_id . ' is already being enriched.' );
        }
        set_transient( $lock_key, true, 300 ); // 5-minute lock
        $image_url  = wp_get_attachment_url($product->get_image_id());
        $gallery    = array_map('wp_get_attachment_url', $product->get_gallery_image_ids());

        $payload = array(
            'event'   => 'product_enrich',
            '_meta'   => n8npress_build_meta_block( rest_url( 'n8npress/v1/product/enrich-callback' ) ),
            'product' => array(
                'id'                => $product_id,
                'name'              => $product->get_name(),
                'short_description' => $product->get_short_description(),
                'description'       => $product->get_description(),
                'sku'               => $product->get_sku(),
                'price'             => $product->get_price(),
                'regular_price'     => $product->get_regular_price(),
                'sale_price'        => $product->get_sale_price(),
                'categories'        => wp_get_post_terms($product_id, 'product_cat', array('fields' => 'names')),
                'tags'              => wp_get_post_terms($product_id, 'product_tag', array('fields' => 'names')),
                'attributes'        => $this->get_product_attributes($product),
                'image_url'         => $image_url ?: '',
                'gallery_urls'      => array_filter($gallery),
                'weight'            => $product->get_weight(),
                'dimensions'        => array(
                    'length' => $product->get_length(),
                    'width'  => $product->get_width(),
                    'height' => $product->get_height(),
                ),
                'stock_status'      => $product->get_stock_status(),
                'permalink'         => get_permalink($product_id),
            ),
            'options' => wp_parse_args($options, array(
                'generate_description'       => true,
                'generate_short_description' => true,
                'generate_meta_title'        => true,
                'generate_meta_description'  => true,
                'generate_faq'               => true,
                'generate_schema'            => true,
                'generate_alt_text'          => true,
                'generate_image'             => (bool) get_option( 'n8npress_enrich_generate_image', false ),
                'image_provider'             => get_option( 'n8npress_image_provider', 'dall-e-3' ),
                'target_language'            => get_option('n8npress_target_language', 'tr'),
            )),
        );

        $headers = array(
            'Content-Type'      => 'application/json',
            'X-n8nPress-Event'  => 'product_enrich',
            'X-n8nPress-Source' => get_site_url(),
        );

        if (!empty($this->n8n_api_token)) {
            $headers['Authorization'] = 'Bearer ' . $this->n8n_api_token;
        }

        // n8n webhook URL + path for product enricher
        $url = trailingslashit($this->n8n_webhook_url) . 'product-enrich';

        $response = wp_remote_post($url, array(
            'headers' => $headers,
            'body'    => wp_json_encode($payload),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            N8nPress_Logger::log('Failed to send product to n8n', 'error', array(
                'product_id' => $product->get_id(),
                'error'      => $response->get_error_message(),
            ));
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('n8n_error', 'n8n returned HTTP ' . $code, array('status' => 502));
        }

        return true;
    }

    // ─── CALLBACK: Receive enriched data from n8n ───────────────────────

    /**
     * n8n calls this endpoint with AI-generated content
     */
    public function handle_enrich_callback($request) {
        $data       = $request->get_json_params();
        $product_id = isset($data['product_id']) ? absint($data['product_id']) : 0;

        if (!$product_id) {
            return new WP_Error('missing_id', 'product_id is required.', array('status' => 400));
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return new WP_Error('not_found', 'Product not found.', array('status' => 404));
        }

        $updated_fields = array();

        // Update description
        if (!empty($data['description'])) {
            $product->set_description(wp_kses_post($data['description']));
            $updated_fields[] = 'description';
        }

        // Update short description
        if (!empty($data['short_description'])) {
            $product->set_short_description(wp_kses_post($data['short_description']));
            $updated_fields[] = 'short_description';
        }

        $product->save();

        // Meta title & description via Plugin Detector (writes to active SEO plugin's keys)
        if (!empty($data['meta_title']) || !empty($data['meta_description'])) {
            $detector = N8nPress_Plugin_Detector::get_instance();
            $seo_data = array();
            if (!empty($data['meta_title'])) {
                $seo_data['title'] = $data['meta_title'];
                $updated_fields[] = 'meta_title';
            }
            if (!empty($data['meta_description'])) {
                $seo_data['description'] = $data['meta_description'];
                $updated_fields[] = 'meta_description';
            }
            if (!empty($data['focus_keyword'])) {
                $seo_data['focus_keyword'] = $data['focus_keyword'];
                $updated_fields[] = 'focus_keyword';
            }
            $detector->set_seo_meta($product_id, $seo_data);
        }

        // FAQ data
        if (!empty($data['faq']) && is_array($data['faq'])) {
            update_post_meta($product_id, '_n8npress_faq', $this->sanitize_faq($data['faq']));
            $updated_fields[] = 'faq';
        }

        // Schema markup
        if (!empty($data['schema'])) {
            update_post_meta($product_id, '_n8npress_schema', wp_kses_post($data['schema']));
            $updated_fields[] = 'schema';
        }

        // Image alt texts
        if (!empty($data['alt_texts']) && is_array($data['alt_texts'])) {
            $this->update_image_alt_texts($product, $data['alt_texts']);
            $updated_fields[] = 'alt_texts';
        }

        // AI-generated image (from DALL-E, Gemini Imagen, etc.)
        if (!empty($data['generated_image_url'])) {
            $image_id = $this->sideload_image($data['generated_image_url'], $product_id, $product->get_name());
            if ($image_id && !is_wp_error($image_id)) {
                set_post_thumbnail($product_id, $image_id);
                update_post_meta($product_id, '_n8npress_generated_image_id', $image_id);
                $updated_fields[] = 'generated_image';
            }
        }

        // Mark enrichment complete
        update_post_meta($product_id, '_n8npress_enrich_status', 'completed');
        delete_transient( 'n8npress_enrich_lock_' . $product_id );
        update_post_meta($product_id, '_n8npress_enrich_completed', current_time('mysql'));
        update_post_meta($product_id, '_n8npress_enrich_fields', $updated_fields);

        // Purge cache so enriched content is visible immediately
        $detector = N8nPress_Plugin_Detector::get_instance();
        $detector->purge_post_cache($product_id);

        N8nPress_Logger::log('Product enrichment completed', 'info', array(
            'product_id'     => $product_id,
            'updated_fields' => $updated_fields,
        ));

        return array(
            'success'        => true,
            'product_id'     => $product_id,
            'updated_fields' => $updated_fields,
        );
    }

    // ─── SCHEMA endpoint ────────────────────────────────────────────────

    public function handle_save_schema($request) {
        $data    = $request->get_json_params();
        $post_id = isset($data['post_id']) ? absint($data['post_id']) : 0;

        if (!$post_id || !get_post($post_id)) {
            return new WP_Error('invalid_post', 'Valid post_id required.', array('status' => 400));
        }

        if (empty($data['schema'])) {
            return new WP_Error('no_schema', 'schema field is required.', array('status' => 400));
        }

        // Validate JSON-LD
        $schema = is_string($data['schema']) ? $data['schema'] : wp_json_encode($data['schema']);
        $decoded = json_decode($schema);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('invalid_json', 'Schema must be valid JSON-LD.', array('status' => 400));
        }

        update_post_meta($post_id, '_n8npress_schema', $schema);

        return array('success' => true, 'post_id' => $post_id);
    }

    // ─── FAQ endpoint ───────────────────────────────────────────────────

    public function handle_save_faq($request) {
        $data    = $request->get_json_params();
        $post_id = isset($data['post_id']) ? absint($data['post_id']) : 0;

        if (!$post_id || !get_post($post_id)) {
            return new WP_Error('invalid_post', 'Valid post_id required.', array('status' => 400));
        }

        if (empty($data['faq']) || !is_array($data['faq'])) {
            return new WP_Error('no_faq', 'faq array is required.', array('status' => 400));
        }

        $faq = $this->sanitize_faq($data['faq']);
        update_post_meta($post_id, '_n8npress_faq', $faq);

        return array('success' => true, 'post_id' => $post_id, 'faq_count' => count($faq));
    }

    // ─── STALE CONTENT endpoint ─────────────────────────────────────────

    public function handle_stale_content($request) {
        $days      = $request->get_param('days');
        $post_type = $request->get_param('post_type');
        $per_page  = min($request->get_param('per_page'), 200);

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $args = array(
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'date_query'     => array(
                array('column' => 'post_modified', 'before' => $cutoff),
            ),
            'orderby'        => 'modified',
            'order'          => 'ASC',
        );

        $query = new WP_Query($args);
        $items = array();

        foreach ($query->posts as $post) {
            $modified = $post->post_modified;
            $items[] = array(
                'id'            => $post->ID,
                'title'         => $post->post_title,
                'url'           => get_permalink($post->ID),
                'last_modified' => $modified,
                'days_stale'    => (int) ((time() - strtotime($modified)) / DAY_IN_SECONDS),
            );
        }

        return array(
            'total'   => $query->found_posts,
            'cutoff'  => $cutoff,
            'items'   => $items,
        );
    }

    // ─── BATCH ENRICHMENT ─────────────────────────────────────────────

    /**
     * Handle batch enrichment request — sends multiple products to n8n
     */
    public function handle_batch_enrich_request($request) {
        $product_ids = $request->get_param('product_ids');
        $options     = $request->get_param('options');

        if (empty($product_ids) || !is_array($product_ids)) {
            return new WP_Error('invalid_ids', 'product_ids array is required.', array('status' => 400));
        }

        $product_ids = array_map('absint', array_slice($product_ids, 0, 50));
        $batch_id    = 'batch_' . wp_generate_uuid4();
        $queued      = array();
        $errors      = array();

        foreach ($product_ids as $pid) {
            $product = wc_get_product($pid);
            if (!$product) {
                $errors[] = array('product_id' => $pid, 'error' => 'Product not found');
                continue;
            }

            $status = get_post_meta($pid, '_n8npress_enrich_status', true);
            if ('processing' === $status) {
                $errors[] = array('product_id' => $pid, 'error' => 'Already processing');
                continue;
            }

            update_post_meta($pid, '_n8npress_enrich_status', 'queued');
            update_post_meta($pid, '_n8npress_enrich_batch_id', $batch_id);
            $queued[] = $pid;
        }

        // Send batch to n8n as a single payload
        if (!empty($queued)) {
            $this->send_batch_to_n8n($queued, $options, $batch_id);
        }

        N8nPress_Logger::log('Batch enrichment started', 'info', array(
            'batch_id' => $batch_id,
            'queued'   => count($queued),
            'errors'   => count($errors),
        ));

        return array(
            'success'  => true,
            'batch_id' => $batch_id,
            'queued'   => count($queued),
            'errors'   => $errors,
        );
    }

    /**
     * Send batch of product IDs to n8n for sequential processing
     */
    private function send_batch_to_n8n($product_ids, $options, $batch_id) {
        $budget = N8nPress_Token_Tracker::check_budget( 'batch-enricher' );
        if ( is_wp_error( $budget ) ) {
            return $budget;
        }

        if (empty($this->n8n_webhook_url)) {
            return new WP_Error('no_webhook', 'n8n webhook URL is not configured.');
        }

        $products_data = array();
        foreach ($product_ids as $pid) {
            $product = wc_get_product($pid);
            if (!$product) {
                continue;
            }

            $image_url = wp_get_attachment_url($product->get_image_id());
            $gallery   = array_map('wp_get_attachment_url', $product->get_gallery_image_ids());

            $products_data[] = array(
                'id'                => $pid,
                'name'              => $product->get_name(),
                'short_description' => $product->get_short_description(),
                'description'       => $product->get_description(),
                'sku'               => $product->get_sku(),
                'price'             => $product->get_price(),
                'categories'        => wp_get_post_terms($pid, 'product_cat', array('fields' => 'names')),
                'tags'              => wp_get_post_terms($pid, 'product_tag', array('fields' => 'names')),
                'attributes'        => $this->get_product_attributes($product),
                'image_url'         => $image_url ?: '',
                'gallery_urls'      => array_filter($gallery),
                'permalink'         => get_permalink($pid),
            );

            update_post_meta($pid, '_n8npress_enrich_status', 'processing');
            update_post_meta($pid, '_n8npress_enrich_requested', current_time('mysql'));
        }

        $payload = array(
            'event'    => 'product_enrich_batch',
            'batch_id' => $batch_id,
            '_meta'    => n8npress_build_meta_block( rest_url( 'n8npress/v1/product/enrich-callback' ) ),
            'products' => $products_data,
            'options'      => wp_parse_args($options, array(
                'generate_description'       => true,
                'generate_short_description' => true,
                'generate_meta_title'        => true,
                'generate_meta_description'  => true,
                'generate_faq'               => true,
                'generate_schema'            => true,
                'generate_alt_text'          => true,
                'target_language'            => get_option('n8npress_target_language', 'tr'),
            )),
        );

        $headers = array(
            'Content-Type'      => 'application/json',
            'X-n8nPress-Event'  => 'product_enrich_batch',
            'X-n8nPress-Source' => get_site_url(),
        );

        if (!empty($this->n8n_api_token)) {
            $headers['Authorization'] = 'Bearer ' . $this->n8n_api_token;
        }

        $url = trailingslashit($this->n8n_webhook_url) . 'product-enrich-batch';

        $response = wp_remote_post($url, array(
            'headers' => $headers,
            'body'    => wp_json_encode($payload),
            'timeout' => 15,
        ));

        if ( is_wp_error( $response ) ) {
            N8nPress_Logger::log( 'Batch enrichment send failed: ' . $response->get_error_message(), 'error', array(
                'batch_id' => $batch_id,
                'count'    => count( $product_ids ),
            ) );
            return;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            N8nPress_Logger::log( 'Batch enrichment n8n returned HTTP ' . $code, 'error', array(
                'batch_id' => $batch_id,
            ) );
        } else {
            N8nPress_Logger::log( 'Batch enrichment sent: ' . count( $product_ids ) . ' products', 'info', array(
                'batch_id' => $batch_id,
            ) );
        }
    }

    /**
     * Check batch enrichment status
     */
    public function handle_batch_status($request) {
        $batch_id = $request->get_param('batch_id');

        global $wpdb;
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value as status
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->postmeta} pm2
               ON pm.post_id = pm2.post_id AND pm2.meta_key = '_n8npress_enrich_batch_id' AND pm2.meta_value = %s
             WHERE pm.meta_key = '_n8npress_enrich_status'",
            $batch_id
        ));

        $statuses = array('queued' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0);
        $products = array();

        foreach ($results as $row) {
            $s = $row->status;
            if (isset($statuses[$s])) {
                $statuses[$s]++;
            }
            $products[] = array(
                'product_id' => (int) $row->post_id,
                'title'      => get_the_title($row->post_id),
                'status'     => $s,
            );
        }

        $total     = count($products);
        $completed = $statuses['completed'] + $statuses['failed'];

        return array(
            'batch_id'  => $batch_id,
            'total'     => $total,
            'completed' => $completed,
            'progress'  => $total > 0 ? round(($completed / $total) * 100) : 0,
            'statuses'  => $statuses,
            'products'  => $products,
        );
    }

    // ─── THIN CONTENT AUTO-ENRICHMENT ──────────────────────────────────

    /**
     * Cron job: find thin content products and trigger enrichment
     */
    public function run_thin_content_enrichment() {
        if (!get_option('n8npress_auto_enrich_thin', false)) {
            return;
        }

        // Budget guard
        if ( class_exists( 'N8nPress_Token_Tracker' ) && N8nPress_Token_Tracker::is_limit_exceeded() ) {
            N8nPress_Logger::log( 'Auto-enrich cron skipped: daily budget limit reached', 'warning' );
            return;
        }

        $threshold  = absint(get_option('n8npress_thin_content_threshold', 300));
        $batch_size = absint(get_option('n8npress_auto_enrich_batch_size', 10));

        global $wpdb;
        $thin_products = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_n8npress_enrich_status'
             WHERE p.post_type = 'product'
               AND p.post_status = 'publish'
               AND LENGTH(p.post_content) < %d
               AND (pm.meta_value IS NULL OR pm.meta_value NOT IN ('processing', 'queued'))
             ORDER BY LENGTH(p.post_content) ASC
             LIMIT %d",
            $threshold,
            $batch_size
        ));

        if (empty($thin_products)) {
            N8nPress_Logger::log('Thin content scan: no products to enrich', 'info');
            return;
        }

        $batch_id = 'auto_thin_' . wp_generate_uuid4();
        foreach ($thin_products as $pid) {
            update_post_meta($pid, '_n8npress_enrich_batch_id', $batch_id);
        }

        $this->send_batch_to_n8n($thin_products, array(), $batch_id);

        N8nPress_Logger::log('Thin content auto-enrichment triggered', 'info', array(
            'batch_id' => $batch_id,
            'count'    => count($thin_products),
            'threshold' => $threshold,
        ));
    }

    // ─── AJAX: Batch enrichment from admin dashboard ───────────────────

    /**
     * AJAX handler for bulk enrichment from dashboard
     */
    public function ajax_batch_enrich() {
        check_ajax_referer('n8npress_dashboard_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        $product_ids = isset($_POST['product_ids']) ? array_map('absint', (array) $_POST['product_ids']) : array();

        if (empty($product_ids)) {
            wp_send_json_error('No products selected');
        }

        $batch_id = 'manual_' . wp_generate_uuid4();
        $queued   = array();

        foreach (array_slice($product_ids, 0, 50) as $pid) {
            $product = wc_get_product($pid);
            if (!$product) {
                continue;
            }

            $status = get_post_meta($pid, '_n8npress_enrich_status', true);
            if ('processing' === $status || 'queued' === $status) {
                continue;
            }

            update_post_meta($pid, '_n8npress_enrich_batch_id', $batch_id);
            $queued[] = $pid;
        }

        if (!empty($queued)) {
            $this->send_batch_to_n8n($queued, array(), $batch_id);
        }

        wp_send_json_success(array(
            'batch_id' => $batch_id,
            'queued'   => count($queued),
        ));
    }

    // ─── ADMIN UI: "AI Enrich" button on product page ─────────

    public function add_product_meta_box() {
        if (!class_exists('WooCommerce')) {
            return;
        }

        add_meta_box(
            'n8npress_ai_enrich',
            'n8nPress AI',
            array($this, 'render_product_meta_box'),
            'product',
            'side',
            'high'
        );
    }

    public function render_product_meta_box($post) {
        $status    = get_post_meta($post->ID, '_n8npress_enrich_status', true);
        $completed = get_post_meta($post->ID, '_n8npress_enrich_completed', true);
        $fields    = get_post_meta($post->ID, '_n8npress_enrich_fields', true);

        wp_nonce_field('n8npress_enrich', 'n8npress_enrich_nonce');
        ?>
        <div id="n8npress-ai-panel">
            <?php if ($status === 'completed' && $completed) : ?>
                <p style="color:#46b450;">&#10003; AI zenginleştirme tamamlandı</p>
                <p class="description"><?php echo esc_html($completed); ?></p>
                <?php if ($fields && is_array($fields)) : ?>
                    <p class="description">Güncellenen: <?php echo esc_html(implode(', ', $fields)); ?></p>
                <?php endif; ?>
            <?php elseif ($status === 'processing') : ?>
                <p style="color:#f0ad4e;">&#9889; AI işleniyor...</p>
            <?php endif; ?>

            <button type="button" id="n8npress-enrich-btn" class="button button-primary" style="width:100%;margin-top:8px;">
                &#9889; AI Enrich
            </button>

            <div style="margin-top:8px;">
                <label><input type="checkbox" name="n8npress_gen_desc" checked> Açıklama</label><br>
                <label><input type="checkbox" name="n8npress_gen_meta" checked> Meta Title/Desc</label><br>
                <label><input type="checkbox" name="n8npress_gen_faq" checked> FAQ</label><br>
                <label><input type="checkbox" name="n8npress_gen_schema" checked> Schema</label><br>
                <label><input type="checkbox" name="n8npress_gen_alt" checked> Alt Text</label>
            </div>

            <div id="n8npress-enrich-result" style="margin-top:8px;"></div>
        </div>
        <?php
    }

    public function enqueue_product_scripts($hook) {
        if (!in_array($hook, array('post.php', 'post-new.php'), true)) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'product') {
            return;
        }

        wp_enqueue_script(
            'n8npress-ai-enrich',
            N8NPRESS_PLUGIN_URL . 'assets/js/ai-enrich.js',
            array('jquery'),
            N8NPRESS_VERSION,
            true
        );

        wp_localize_script('n8npress-ai-enrich', 'n8npressAI', array(
            'rest_url' => rest_url('n8npress/v1/product/enrich'),
            'nonce'    => wp_create_nonce('wp_rest'),
        ));
    }

    // ─── FRONTEND: Inject schema + FAQ into <head> ──────────────────────

    public static function init_frontend_hooks() {
        add_action('wp_head', array(__CLASS__, 'output_schema_markup'), 1);
        add_action('woocommerce_after_single_product_summary', array(__CLASS__, 'output_faq_block'), 25);
    }

    /**
     * Output JSON-LD schema in <head>
     */
    public static function output_schema_markup() {
        if (!is_singular()) {
            return;
        }

        $schema = get_post_meta(get_the_ID(), '_n8npress_schema', true);
        if (empty($schema)) {
            return;
        }

        // Validate JSON before output
        $decoded = json_decode($schema);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return;
        }

        echo '<script type="application/ld+json">' . $schema . '</script>' . "\n";
    }

    /**
     * Output FAQ accordion below product summary
     */
    public static function output_faq_block() {
        if (!is_singular('product')) {
            return;
        }

        $faq = get_post_meta(get_the_ID(), '_n8npress_faq', true);
        if (empty($faq) || !is_array($faq)) {
            return;
        }

        // Output FAQ HTML
        echo '<div class="n8npress-faq" style="margin-top:2em;">';
        echo '<h2>' . esc_html__( 'Frequently Asked Questions', 'n8npress' ) . '</h2>';

        foreach ($faq as $item) {
            $q = esc_html($item['question']);
            $a = wp_kses_post($item['answer']);
            echo '<details style="margin-bottom:1em;border:1px solid #ddd;padding:12px;border-radius:4px;">';
            echo '<summary style="cursor:pointer;font-weight:bold;">' . $q . '</summary>';
            echo '<div style="margin-top:8px;">' . $a . '</div>';
            echo '</details>';
        }

        echo '</div>';

        // Also output FAQ schema
        $schema = array(
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => array(),
        );

        foreach ($faq as $item) {
            $schema['mainEntity'][] = array(
                '@type' => 'Question',
                'name'  => $item['question'],
                'acceptedAnswer' => array(
                    '@type' => 'Answer',
                    'text'  => $item['answer'],
                ),
            );
        }

        echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
    }

    // ─── HELPERS ────────────────────────────────────────────────────────

    private function get_product_attributes($product) {
        $attrs = array();
        foreach ($product->get_attributes() as $key => $attr) {
            if (is_a($attr, 'WC_Product_Attribute')) {
                $attrs[$attr->get_name()] = $attr->get_options();
            } else {
                $attrs[$key] = $attr;
            }
        }
        return $attrs;
    }

    private function sanitize_faq($faq) {
        $clean = array();
        foreach ($faq as $item) {
            if (!empty($item['question']) && !empty($item['answer'])) {
                $clean[] = array(
                    'question' => sanitize_text_field($item['question']),
                    'answer'   => wp_kses_post($item['answer']),
                );
            }
        }
        return $clean;
    }

    private function update_image_alt_texts($product, $alt_texts) {
        // Main image
        $image_id = $product->get_image_id();
        if ($image_id && !empty($alt_texts['main'])) {
            update_post_meta($image_id, '_wp_attachment_image_alt', sanitize_text_field($alt_texts['main']));
        }

        // Gallery images
        if (!empty($alt_texts['gallery']) && is_array($alt_texts['gallery'])) {
            $gallery_ids = $product->get_gallery_image_ids();
            foreach ($gallery_ids as $i => $gid) {
                if (isset($alt_texts['gallery'][$i])) {
                    update_post_meta($gid, '_wp_attachment_image_alt', sanitize_text_field($alt_texts['gallery'][$i]));
                }
            }
        }
    }

    /**
     * Auto-enrich new products (if option is enabled)
     */
    public function maybe_auto_enrich($product_id, $product) {
        if (!get_option('n8npress_auto_enrich', false)) {
            return;
        }

        // Only if product has a title but empty description
        if ($product->get_name() && empty($product->get_description())) {
            $this->send_to_n8n_for_enrichment($product);
            update_post_meta($product_id, '_n8npress_enrich_status', 'processing');
        }
    }

    /**
     * Download an external image and add it to the WordPress media library.
     *
     * @param string $url       Image URL.
     * @param int    $post_id   Post to attach the image to.
     * @param string $desc      Image description / title.
     * @return int|WP_Error     Attachment ID or error.
     */
    private function sideload_image( $url, $post_id, $desc = '' ) {
        if ( ! function_exists( 'media_sideload_image' ) ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        // Download to temp file
        $tmp = download_url( $url );
        if ( is_wp_error( $tmp ) ) {
            return $tmp;
        }

        // Validate file size (max 5 MB)
        $max_size = 5 * MB_IN_BYTES;
        $file_size = filesize( $tmp );
        if ( $file_size > $max_size ) {
            unlink( $tmp );
            return new WP_Error( 'file_too_large', 'Downloaded image exceeds 5 MB limit.' );
        }

        // Validate MIME type — only allow safe image formats
        $allowed_mimes = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
        $filetype = wp_check_filetype_and_ext( $tmp, basename( wp_parse_url( $url, PHP_URL_PATH ) ) ?: 'ai-generated.png' );
        $mime = $filetype['type'] ?: mime_content_type( $tmp );

        if ( ! in_array( $mime, $allowed_mimes, true ) ) {
            unlink( $tmp );
            return new WP_Error( 'invalid_mime', 'File type not allowed: ' . $mime );
        }

        $file_array = array(
            'name'     => sanitize_file_name( basename( wp_parse_url( $url, PHP_URL_PATH ) ) ) ?: 'ai-generated.png',
            'tmp_name' => $tmp,
        );

        $attachment_id = media_handle_sideload( $file_array, $post_id, sanitize_text_field( $desc ) );

        // Clean up temp file on error
        if ( is_wp_error( $attachment_id ) && file_exists( $file_array['tmp_name'] ) ) {
            unlink( $file_array['tmp_name'] );
        }

        return $attachment_id;
    }

    // ─── PERMISSION CALLBACKS ───────────────────────────────────────────

    public function check_admin_permission( $request ) {
        return N8nPress_Permission::check_token_or_admin( $request );
    }

    public function check_n8n_token( $request ) {
        return N8nPress_Permission::check_token( $request );
    }
}
