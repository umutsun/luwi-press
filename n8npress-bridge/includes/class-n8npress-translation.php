<?php
/**
 * n8nPress Translation Manager
 *
 * REST API endpoints for managing product translations.
 * Integrates with WPML/Polylang and n8n AI translation workflows.
 *
 * @package n8nPress
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class N8nPress_Translation {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('rest_api_init', [$this, 'register_endpoints']);
        add_action('admin_menu', [$this, 'add_submenu']);
    }

    /**
     * Add submenu under n8nPress
     */
    public function add_submenu() {
        add_submenu_page(
            'n8npress',
            __('Translations', 'n8npress'),
            __('Translations', 'n8npress'),
            'manage_options',
            'n8npress-translations',
            [$this, 'render_page']
        );
    }

    /**
     * Render the translation admin page
     */
    public function render_page() {
        include N8NPRESS_PLUGIN_DIR . 'admin/translation-page.php';
    }

    /**
     * Register REST API endpoints
     */
    public function register_endpoints() {
        $namespace = 'n8npress/v1';

        register_rest_route($namespace, '/translation/missing', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_missing_translations'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'target_language' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'post_type'       => ['default' => 'product', 'sanitize_callback' => 'sanitize_text_field'],
                'limit'           => ['default' => 50, 'sanitize_callback' => 'absint'],
            ],
        ]);

        register_rest_route($namespace, '/translation/request', [
            'methods'             => 'POST',
            'callback'            => [$this, 'request_translation'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'product_id'       => ['required' => true, 'sanitize_callback' => 'absint'],
                'target_languages' => ['required' => true],
            ],
        ]);

        register_rest_route($namespace, '/translation/callback', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_translation_callback'],
            'permission_callback' => [$this, 'check_token_permission'],
        ]);

        register_rest_route($namespace, '/translation/status', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_translation_status'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route($namespace, '/translation/quality-check', [
            'methods'             => 'POST',
            'callback'            => [$this, 'trigger_quality_check'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    /**
     * Permission checks
     */
    public function check_permission($request) {
        $auth = $request->get_header('Authorization');
        if ($auth) {
            $token = str_replace('Bearer ', '', $auth);
            $stored = get_option('n8npress_seo_api_token', '');
            if (!empty($stored) && hash_equals($stored, $token)) {
                return true;
            }
        }
        return current_user_can('manage_options');
    }

    public function check_token_permission($request) {
        $auth = $request->get_header('Authorization');
        if (!$auth) {
            return false;
        }
        $token = str_replace('Bearer ', '', $auth);
        $stored = get_option('n8npress_seo_api_token', '');
        return !empty($stored) && hash_equals($stored, $token);
    }

    /**
     * GET /translation/missing — Products missing translations
     */
    public function get_missing_translations($request) {
        $target_lang = $request->get_param('target_language');
        $post_type   = $request->get_param('post_type');
        $limit       = min($request->get_param('limit'), 200);

        $translation_plugin = $this->detect_translation_plugin();

        if ('wpml' === $translation_plugin) {
            return $this->get_missing_wpml($target_lang, $post_type, $limit);
        } elseif ('polylang' === $translation_plugin) {
            return $this->get_missing_polylang($target_lang, $post_type, $limit);
        }

        // Fallback: use n8nPress own translation tracking
        return $this->get_missing_n8npress($target_lang, $post_type, $limit);
    }

    /**
     * POST /translation/request — Send product for translation via n8n
     */
    public function request_translation($request) {
        $product_id = $request->get_param('product_id');
        $target_languages = $request->get_param('target_languages');

        if (is_string($target_languages)) {
            $target_languages = array_map('trim', explode(',', $target_languages));
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return new WP_Error('not_found', 'Product not found', ['status' => 404]);
        }

        $source_language = get_option('n8npress_target_language', 'tr');

        $payload = [
            'product_id'       => $product_id,
            'source_language'  => $source_language,
            'target_languages' => $target_languages,
            'content' => [
                'name'              => $product->get_name(),
                'description'       => $product->get_description(),
                'short_description' => $product->get_short_description(),
                'meta_title'        => $this->get_seo_meta($product_id, 'title'),
                'meta_description'  => $this->get_seo_meta($product_id, 'description'),
                'faq'               => get_post_meta($product_id, '_n8npress_faq', true) ?: [],
            ],
            'categories' => wp_list_pluck(get_the_terms($product_id, 'product_cat') ?: [], 'name'),
            'permalink'  => get_permalink($product_id),
        ];

        $result = $this->send_to_n8n('translation_request', $payload, rest_url('n8npress/v1/translation/callback'));
        if (is_wp_error($result)) {
            N8nPress_Logger::log('Translation request failed for product #' . $product_id, 'error', array(
                'product_id' => $product_id,
                'languages'  => $target_languages,
                'error'      => $result->get_error_message(),
            ));
            return $result;
        }

        N8nPress_Logger::log('Translation requested for product: ' . $product->get_name(), 'info', array(
            'product_id' => $product_id,
            'languages'  => $target_languages,
        ));

        // Track translation status
        foreach ($target_languages as $lang) {
            update_post_meta($product_id, '_n8npress_translation_' . $lang . '_status', 'processing');
            update_post_meta($product_id, '_n8npress_translation_' . $lang . '_requested', current_time('c'));
        }

        return rest_ensure_response([
            'status'           => 'processing',
            'product_id'       => $product_id,
            'target_languages' => $target_languages,
        ]);
    }

    /**
     * POST /translation/callback — Receive translated content from n8n
     */
    public function handle_translation_callback($request) {
        $data = $request->get_json_params();

        $product_id = isset($data['product_id']) ? absint($data['product_id']) : 0;
        $language   = isset($data['language']) ? sanitize_text_field($data['language']) : '';
        $content    = isset($data['content']) ? $data['content'] : [];

        if (!$product_id || empty($language) || empty($content)) {
            return new WP_Error('invalid_data', 'Missing required fields', ['status' => 400]);
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return new WP_Error('not_found', 'Product not found', ['status' => 404]);
        }

        // Store translated content
        $translated = [
            'name'              => sanitize_text_field($content['name'] ?? ''),
            'description'       => wp_kses_post($content['description'] ?? ''),
            'short_description' => wp_kses_post($content['short_description'] ?? ''),
            'meta_title'        => sanitize_text_field($content['meta_title'] ?? ''),
            'meta_description'  => sanitize_text_field($content['meta_description'] ?? ''),
            'faq'               => $content['faq'] ?? [],
        ];

        update_post_meta($product_id, '_n8npress_translation_' . $language, $translated);
        update_post_meta($product_id, '_n8npress_translation_' . $language . '_status', 'completed');
        update_post_meta($product_id, '_n8npress_translation_' . $language . '_completed', current_time('c'));

        N8nPress_Logger::log('Translation completed: ' . $product->get_name() . ' → ' . strtoupper( $language ), 'info', array(
            'product_id' => $product_id,
            'language'   => $language,
        ));

        // If WPML/Polylang is active, try to create/update the translated post
        $translation_plugin = $this->detect_translation_plugin();
        if ('wpml' === $translation_plugin) {
            $this->create_wpml_translation($product_id, $language, $translated);
        } elseif ('polylang' === $translation_plugin) {
            $this->create_polylang_translation($product_id, $language, $translated);
        }

        return rest_ensure_response([
            'status'     => 'saved',
            'product_id' => $product_id,
            'language'   => $language,
        ]);
    }

    /**
     * GET /translation/status — Translation queue status
     */
    public function get_translation_status($request) {
        global $wpdb;

        $raw_langs = get_option( 'n8npress_translation_languages', array() );
        $target_languages = is_array( $raw_langs ) ? $raw_langs : array_map( 'trim', explode( ',', $raw_langs ) );
        if ( empty( $target_languages ) ) {
            // Fallback: get from translation plugin
            $detector = N8nPress_Plugin_Detector::get_instance();
            $t = $detector->detect_translation();
            $target_languages = array_diff( $t['active_languages'] ?? array(), array( $t['default_language'] ?? 'tr' ) );
        }

        $stats = [];
        foreach ($target_languages as $lang) {
            $processing = intval($wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta}
                 WHERE meta_key = %s AND meta_value = 'processing'",
                '_n8npress_translation_' . $lang . '_status'
            )));

            $completed = intval($wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta}
                 WHERE meta_key = %s AND meta_value = 'completed'",
                '_n8npress_translation_' . $lang . '_status'
            )));

            $stats[$lang] = [
                'processing' => $processing,
                'completed'  => $completed,
            ];
        }

        return rest_ensure_response([
            'translation_plugin' => $this->detect_translation_plugin(),
            'languages'          => $stats,
        ]);
    }

    /**
     * POST /translation/quality-check — Trigger quality audit
     */
    public function trigger_quality_check($request) {
        $data = $request->get_json_params();
        $product_id = isset($data['product_id']) ? absint($data['product_id']) : 0;
        $language   = isset($data['language']) ? sanitize_text_field($data['language']) : '';

        if (!$product_id || empty($language)) {
            return new WP_Error('invalid_data', 'product_id and language required', ['status' => 400]);
        }

        $translated = get_post_meta($product_id, '_n8npress_translation_' . $language, true);
        if (empty($translated)) {
            return new WP_Error('no_translation', 'No translation found', ['status' => 404]);
        }

        $product = wc_get_product($product_id);
        $payload = [
            'product_id'      => $product_id,
            'language'        => $language,
            'source_content'  => [
                'name'        => $product->get_name(),
                'description' => $product->get_description(),
            ],
            'translated_content' => $translated,
            'type' => 'quality_check',
        ];

        $result = $this->send_to_n8n('translation_quality_check', $payload, rest_url('n8npress/v1/translation/callback'));
        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response(['status' => 'quality_check_triggered']);
    }

    /**
     * Detect active translation plugin
     */
    private function detect_translation_plugin() {
        if (defined('ICL_SITEPRESS_VERSION')) {
            return 'wpml';
        }
        if (function_exists('pll_languages_list')) {
            return 'polylang';
        }
        return 'none';
    }

    /**
     * Get SEO meta value (Yoast, RankMath, or n8nPress)
     */
    private function get_seo_meta($post_id, $type) {
        $keys = [
            'title'       => ['_yoast_wpseo_title', 'rank_math_title', '_n8npress_meta_title'],
            'description' => ['_yoast_wpseo_metadesc', 'rank_math_description', '_n8npress_meta_description'],
        ];

        foreach ($keys[$type] ?? [] as $key) {
            $val = get_post_meta($post_id, $key, true);
            if (!empty($val)) {
                return $val;
            }
        }
        return '';
    }

    /**
     * Get missing translations using n8nPress tracking
     */
    private function get_missing_n8npress($target_lang, $post_type, $limit) {
        global $wpdb;

        $meta_key = '_n8npress_translation_' . $target_lang . '_status';

        $products = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title
             FROM {$wpdb->posts} p
             WHERE p.post_type = %s AND p.post_status = 'publish'
               AND p.ID NOT IN (
                   SELECT post_id FROM {$wpdb->postmeta}
                   WHERE meta_key = %s AND meta_value = 'completed'
               )
             ORDER BY p.post_date DESC
             LIMIT %d",
            $post_type,
            $meta_key,
            $limit
        ));

        $missing = [];
        foreach ($products as $p) {
            $missing[] = [
                'product_id' => $p->ID,
                'name'       => $p->post_title,
                'permalink'  => get_permalink($p->ID),
            ];
        }

        return rest_ensure_response([
            'target_language' => $target_lang,
            'count'           => count($missing),
            'products'        => $missing,
        ]);
    }

    /**
     * Get missing WPML translations
     */
    private function get_missing_wpml($target_lang, $post_type, $limit) {
        global $wpdb;

        if (!defined('ICL_SITEPRESS_VERSION')) {
            return $this->get_missing_n8npress($target_lang, $post_type, $limit);
        }

        $default_lang = apply_filters('wpml_default_language', 'tr');

        $products = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title
             FROM {$wpdb->posts} p
             JOIN {$wpdb->prefix}icl_translations t ON p.ID = t.element_id
             WHERE p.post_type = %s
               AND p.post_status = 'publish'
               AND t.language_code = %s
               AND t.element_type = CONCAT('post_', %s)
               AND t.trid NOT IN (
                   SELECT trid FROM {$wpdb->prefix}icl_translations
                   WHERE language_code = %s AND element_type = CONCAT('post_', %s)
               )
             ORDER BY p.post_date DESC
             LIMIT %d",
            $post_type, $default_lang, $post_type, $target_lang, $post_type, $limit
        ));

        $missing = [];
        foreach ($products as $p) {
            $missing[] = [
                'product_id' => $p->ID,
                'name'       => $p->post_title,
                'permalink'  => get_permalink($p->ID),
            ];
        }

        return rest_ensure_response([
            'target_language'    => $target_lang,
            'translation_plugin' => 'wpml',
            'count'              => count($missing),
            'products'           => $missing,
        ]);
    }

    /**
     * Get missing Polylang translations
     */
    private function get_missing_polylang($target_lang, $post_type, $limit) {
        if (!function_exists('pll_get_post_translations')) {
            return $this->get_missing_n8npress($target_lang, $post_type, $limit);
        }

        $products = get_posts([
            'post_type'   => $post_type,
            'post_status' => 'publish',
            'numberposts' => $limit * 2,
            'lang'        => pll_default_language(),
        ]);

        $missing = [];
        foreach ($products as $p) {
            $translations = pll_get_post_translations($p->ID);
            if (!isset($translations[$target_lang])) {
                $missing[] = [
                    'product_id' => $p->ID,
                    'name'       => $p->post_title,
                    'permalink'  => get_permalink($p->ID),
                ];
            }
            if (count($missing) >= $limit) {
                break;
            }
        }

        return rest_ensure_response([
            'target_language'    => $target_lang,
            'translation_plugin' => 'polylang',
            'count'              => count($missing),
            'products'           => $missing,
        ]);
    }

    /**
     * Create WPML translation
     */
    private function create_wpml_translation($product_id, $language, $translated) {
        if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
            N8nPress_Logger::log( 'WPML not active, cannot save translation', 'warning' );
            return;
        }

        $trid = apply_filters( 'wpml_element_trid', null, $product_id, 'post_product' );
        if ( ! $trid ) {
            N8nPress_Logger::log( 'No WPML trid for product #' . $product_id, 'warning' );
            return;
        }

        $translated_id = apply_filters( 'wpml_object_id', $product_id, 'product', false, $language );

        if ( $translated_id && $translated_id !== $product_id ) {
            // Update existing translation post
            wp_update_post( array(
                'ID'           => $translated_id,
                'post_title'   => $translated['name'],
                'post_content' => $translated['description'],
                'post_excerpt' => $translated['short_description'],
            ) );

            N8nPress_Logger::log( 'WPML translation updated: product #' . $translated_id . ' (' . $language . ')', 'info' );
        } else {
            // Create new translation post
            $original = get_post( $product_id );
            if ( ! $original ) {
                return;
            }

            $new_post = array(
                'post_title'   => $translated['name'],
                'post_content' => $translated['description'],
                'post_excerpt' => $translated['short_description'],
                'post_type'    => 'product',
                'post_status'  => $original->post_status,
                'post_author'  => $original->post_author,
            );

            $new_id = wp_insert_post( $new_post );
            if ( is_wp_error( $new_id ) ) {
                N8nPress_Logger::log( 'Failed to create translation post: ' . $new_id->get_error_message(), 'error' );
                return;
            }

            // Link to WPML translation group
            $default_lang = apply_filters( 'wpml_default_language', 'tr' );
            do_action( 'wpml_set_element_language_details', array(
                'element_id'           => $new_id,
                'element_type'         => 'post_product',
                'trid'                 => $trid,
                'language_code'        => $language,
                'source_language_code' => $default_lang,
            ) );

            // Copy product meta from original
            $meta_keys = array( '_price', '_regular_price', '_sale_price', '_sku', '_stock_status', '_manage_stock', '_stock', '_weight', '_length', '_width', '_height', '_thumbnail_id' );
            foreach ( $meta_keys as $key ) {
                $val = get_post_meta( $product_id, $key, true );
                if ( '' !== $val ) {
                    update_post_meta( $new_id, $key, $val );
                }
            }

            // Copy product gallery
            $gallery = get_post_meta( $product_id, '_product_image_gallery', true );
            if ( $gallery ) {
                update_post_meta( $new_id, '_product_image_gallery', $gallery );
            }

            // Set product type
            $terms = wp_get_object_terms( $product_id, 'product_type', array( 'fields' => 'slugs' ) );
            if ( ! empty( $terms ) ) {
                wp_set_object_terms( $new_id, $terms, 'product_type' );
            }

            N8nPress_Logger::log( 'WPML translation created: product #' . $new_id . ' (' . $language . ') from #' . $product_id, 'info', array(
                'original_id'   => $product_id,
                'translated_id' => $new_id,
                'language'      => $language,
            ) );
        }

        // Save SEO meta if available
        if ( ! empty( $translated['meta_title'] ) || ! empty( $translated['meta_description'] ) ) {
            $target_id = $translated_id ?: $new_id ?? 0;
            if ( $target_id ) {
                $detector = N8nPress_Plugin_Detector::get_instance();
                $seo = $detector->detect_seo();
                if ( ! empty( $seo['meta_keys']['title'] ) ) {
                    update_post_meta( $target_id, $seo['meta_keys']['title'], $translated['meta_title'] ?? '' );
                }
                if ( ! empty( $seo['meta_keys']['description'] ) ) {
                    update_post_meta( $target_id, $seo['meta_keys']['description'], $translated['meta_description'] ?? '' );
                }
            }
        }
    }

    /**
     * Create Polylang translation
     */
    private function create_polylang_translation($product_id, $language, $translated) {
        if (!function_exists('pll_set_post_language')) {
            return;
        }

        $translations = pll_get_post_translations($product_id);
        if (isset($translations[$language])) {
            // Update existing
            wp_update_post([
                'ID'           => $translations[$language],
                'post_title'   => $translated['name'],
                'post_content' => $translated['description'],
                'post_excerpt' => $translated['short_description'],
            ]);
        }
    }

    /**
     * Send payload to n8n
     */
    private function send_to_n8n($event, $payload, $callback_url = '') {
        $budget = N8nPress_Token_Tracker::check_budget( 'translation-pipeline' );
        if ( is_wp_error( $budget ) ) {
            return $budget;
        }

        $url = get_option('n8npress_seo_webhook_url', '');
        if (empty($url)) {
            return new WP_Error('no_webhook_url', 'n8n webhook URL not configured', ['status' => 500]);
        }

        $body = wp_json_encode(array_merge(
            ['event' => $event, '_meta' => n8npress_build_meta_block($callback_url)],
            $payload
        ));
        $headers = [
            'Content-Type'     => 'application/json',
            'X-n8nPress-Event' => $event,
        ];

        $token = get_option('n8npress_seo_api_token', '');
        if (!empty($token)) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        if (class_exists('N8nPress_HMAC')) {
            N8nPress_HMAC::add_signature_headers($headers, $body);
        }

        $response = wp_remote_post($url, [
            'headers' => $headers,
            'body'    => $body,
            'timeout' => 10,
        ]);

        return is_wp_error($response) ? $response : true;
    }
}
