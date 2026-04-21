<?php
/**
 * LuwiPress Content Scheduler
 *
 * Allows users to enter topics and schedule AI-generated content
 * (articles + images) for automatic publishing via n8n workflows.
 *
 * @package LuwiPress
 * @since 1.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class LuwiPress_Content_Scheduler {

    private static $instance = null;

    /**
     * Custom post type for scheduled content
     */
    const POST_TYPE = 'luwipress_schedule';

    /**
     * Statuses for scheduled content
     */
    const STATUS_PENDING   = 'pending';
    const STATUS_GENERATING = 'generating';
    const STATUS_READY     = 'ready';
    const STATUS_PUBLISHED = 'published';
    const STATUS_FAILED    = 'failed';

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', array($this, 'register_post_type'));
        add_action('rest_api_init', array($this, 'register_routes'));
        add_action('admin_menu', array($this, 'add_submenu'));
        add_action('wp_ajax_luwipress_schedule_content', array($this, 'ajax_schedule_content'));
        add_action('wp_ajax_luwipress_delete_schedule', array($this, 'ajax_delete_schedule'));
        add_action('luwipress_publish_scheduled', array($this, 'publish_scheduled_content'));

        // Check for ready-to-publish content every 15 minutes
        if (!wp_next_scheduled('luwipress_publish_scheduled')) {
            wp_schedule_event(time(), 'fifteen_minutes', 'luwipress_publish_scheduled');
        }

        add_filter('cron_schedules', array($this, 'add_cron_interval'));
    }

    /**
     * Add 15-minute cron interval
     */
    public function add_cron_interval($schedules) {
        $schedules['fifteen_minutes'] = array(
            'interval' => 900,
            'display'  => __('Every 15 Minutes', 'luwipress'),
        );
        return $schedules;
    }

    /**
     * Register custom post type for tracking scheduled content
     */
    public function register_post_type() {
        register_post_type(self::POST_TYPE, array(
            'labels' => array(
                'name' => __('Scheduled Content', 'luwipress'),
            ),
            'public'  => false,
            'show_ui' => false,
        ));
    }

    /**
     * REST API routes for n8n callbacks
     */
    public function register_routes() {
        register_rest_route('luwipress/v1', '/schedule/callback', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_callback'),
            'permission_callback' => array($this, 'verify_token'),
        ));

        register_rest_route('luwipress/v1', '/schedule/list', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'get_schedule_list'),
            'permission_callback' => array($this, 'verify_token'),
        ));

        // Read scheduler defaults (what UI pre-fills when a new item is created)
        register_rest_route('luwipress/v1', '/schedule/settings', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'handle_get_settings'),
            'permission_callback' => array($this, 'check_admin_permission'),
        ));

        // Write scheduler defaults — partial update
        register_rest_route('luwipress/v1', '/schedule/settings', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_set_settings'),
            'permission_callback' => array($this, 'check_admin_permission'),
            'args'                => array(
                'default_language'       => array('required' => false, 'type' => 'string'),
                'default_tone'           => array('required' => false, 'type' => 'string'),
                'default_word_count'     => array('required' => false, 'type' => 'integer'),
                'default_generate_image' => array('required' => false, 'type' => 'boolean'),
                'default_post_type'      => array('required' => false, 'type' => 'string'),
            ),
        ));
    }

    /**
     * Admin-only permission for scheduler settings.
     */
    public function check_admin_permission( $request ) {
        return LuwiPress_Permission::check_token_or_admin( $request );
    }

    /**
     * GET /schedule/settings — default values pre-filled when creating a new scheduled item.
     * These are site-wide defaults; per-item values can override at create time.
     */
    public function handle_get_settings( $request ) {
        return array(
            'default_language'       => (string) get_option( 'luwipress_scheduler_default_language', get_option( 'luwipress_target_language', 'en' ) ),
            'default_tone'           => (string) get_option( 'luwipress_scheduler_default_tone', 'professional' ),
            'default_word_count'     => absint( get_option( 'luwipress_scheduler_default_word_count', 1500 ) ),
            'default_generate_image' => (bool) get_option( 'luwipress_scheduler_default_generate_image', 1 ),
            'default_post_type'      => (string) get_option( 'luwipress_scheduler_default_post_type', 'post' ),
        );
    }

    /**
     * POST /schedule/settings — partial update.
     */
    public function handle_set_settings( $request ) {
        $data = $request->get_json_params();
        if ( empty( $data ) ) {
            $data = $request->get_body_params();
        }
        $updated = array();

        if ( array_key_exists( 'default_language', $data ) ) {
            update_option( 'luwipress_scheduler_default_language', sanitize_text_field( (string) $data['default_language'] ) );
            $updated[] = 'default_language';
        }

        if ( array_key_exists( 'default_tone', $data ) ) {
            update_option( 'luwipress_scheduler_default_tone', sanitize_text_field( (string) $data['default_tone'] ) );
            $updated[] = 'default_tone';
        }

        if ( array_key_exists( 'default_word_count', $data ) ) {
            update_option( 'luwipress_scheduler_default_word_count', max( 100, min( 5000, absint( $data['default_word_count'] ) ) ) );
            $updated[] = 'default_word_count';
        }

        if ( array_key_exists( 'default_generate_image', $data ) ) {
            update_option( 'luwipress_scheduler_default_generate_image', ! empty( $data['default_generate_image'] ) ? 1 : 0 );
            $updated[] = 'default_generate_image';
        }

        if ( array_key_exists( 'default_post_type', $data ) ) {
            update_option( 'luwipress_scheduler_default_post_type', sanitize_text_field( (string) $data['default_post_type'] ) );
            $updated[] = 'default_post_type';
        }

        LuwiPress_Logger::log( 'Scheduler settings updated via REST: ' . implode( ', ', $updated ), 'info' );

        return array(
            'success'  => true,
            'updated'  => $updated,
            'settings' => $this->handle_get_settings( $request ),
        );
    }

    /**
     * Token verification for REST endpoints
     */
    public function verify_token( $request ) {
        return LuwiPress_Permission::check_token( $request );
    }

    /**
     * Add submenu under LuwiPress
     */
    public function add_submenu() {
        add_submenu_page(
            'luwipress',
            __('Content Scheduler', 'luwipress'),
            __('Content Scheduler', 'luwipress'),
            'edit_posts',
            'luwipress-scheduler',
            array($this, 'render_page')
        );
    }

    /**
     * Render the scheduler admin page
     */
    public function render_page() {
        include LUWIPRESS_PLUGIN_DIR . 'admin/scheduler-page.php';
    }

    /**
     * AJAX: Create a new scheduled content item and trigger n8n
     */
    public function ajax_schedule_content() {
        check_ajax_referer('luwipress_scheduler_nonce', '_wpnonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(__('Unauthorized', 'luwipress'));
        }

        $topic = sanitize_text_field($_POST['topic'] ?? '');
        $keywords = sanitize_text_field($_POST['keywords'] ?? '');
        $post_type = sanitize_text_field($_POST['target_post_type'] ?? 'post');
        $publish_date = sanitize_text_field($_POST['publish_date'] ?? '');
        $publish_time = sanitize_text_field($_POST['publish_time'] ?? '09:00');
        $generate_image = isset($_POST['generate_image']) ? 1 : 0;
        $language = sanitize_text_field($_POST['language'] ?? get_option('luwipress_target_language', 'tr'));
        $tone = sanitize_text_field($_POST['tone'] ?? 'professional');
        $word_count = absint($_POST['word_count'] ?? 1500);

        if (empty($topic)) {
            wp_send_json_error(__('Topic is required.', 'luwipress'));
        }

        if (empty($publish_date)) {
            wp_send_json_error(__('Publish date is required.', 'luwipress'));
        }

        // Create schedule record
        $schedule_id = wp_insert_post(array(
            'post_type'   => self::POST_TYPE,
            'post_title'  => $topic,
            'post_status' => 'publish',
            'meta_input'  => array(
                '_luwipress_schedule_status'   => self::STATUS_PENDING,
                '_luwipress_schedule_topic'    => $topic,
                '_luwipress_schedule_keywords' => $keywords,
                '_luwipress_schedule_type'     => $post_type,
                '_luwipress_schedule_date'     => $publish_date . ' ' . $publish_time,
                '_luwipress_schedule_image'    => $generate_image,
                '_luwipress_schedule_language' => $language,
                '_luwipress_schedule_tone'     => $tone,
                '_luwipress_schedule_words'    => $word_count,
                '_luwipress_schedule_created'  => current_time('mysql'),
                '_luwipress_schedule_user'     => get_current_user_id(),
            ),
        ), true);

        if (is_wp_error($schedule_id)) {
            wp_send_json_error($schedule_id->get_error_message());
        }

        // Budget guard
        $budget = LuwiPress_Token_Tracker::check_budget( 'content-scheduler' );
        if ( is_wp_error( $budget ) ) {
            wp_send_json_error( $budget->get_error_message() );
        }

        update_post_meta( $schedule_id, '_luwipress_schedule_status', self::STATUS_GENERATING );

        // Generate content via AI Engine
        $prompt   = LuwiPress_Prompts::content_generation( array(
                'topic'     => $topic,
                'keywords'  => $keywords,
                'language'  => $language,
                'tone'      => $tone,
                'word_count' => $word_count,
                'site_name' => get_bloginfo( 'name' ),
            ) );
            $messages  = LuwiPress_AI_Engine::build_messages( $prompt );
            $ai_result = LuwiPress_AI_Engine::dispatch_json( 'content-scheduler', $messages, array(
                'max_tokens' => 4096,
            ) );

            if ( is_wp_error( $ai_result ) ) {
                update_post_meta( $schedule_id, '_luwipress_schedule_status', self::STATUS_FAILED );
                LuwiPress_Logger::log( 'Content generation failed: ' . $ai_result->get_error_message(), 'error', array( 'schedule_id' => $schedule_id ) );
            } else {
                // Feed result into existing callback handler
                $callback_data = array(
                    'schedule_id'    => $schedule_id,
                    'title'          => $ai_result['title'] ?? $topic,
                    'content'        => $ai_result['content'] ?? '',
                    'excerpt'        => $ai_result['excerpt'] ?? '',
                    'meta_title'     => $ai_result['meta_title'] ?? '',
                    'meta_description' => $ai_result['meta_description'] ?? '',
                    'tags'           => $ai_result['tags'] ?? array(),
                );

                // Generate featured image if requested
                if ( $generate_image && class_exists( 'LuwiPress_Image_Handler' ) ) {
                    $image_prompt = LuwiPress_Prompts::image_generation( $ai_result['title'] ?? $topic, $keywords );
                    $attachment_id = LuwiPress_Image_Handler::generate_and_attach( $image_prompt, $schedule_id );
                    if ( ! is_wp_error( $attachment_id ) ) {
                        $callback_data['image_id']  = $attachment_id;
                        $callback_data['image_url'] = wp_get_attachment_url( $attachment_id );
                    }
                }

                $request = new WP_REST_Request( 'POST', '/luwipress/v1/schedule/callback' );
                $request->set_body_params( $callback_data );
                $this->handle_callback( $request );
            }

        LuwiPress_Logger::log( 'Content generation queued', 'info', array(
            'schedule_id' => $schedule_id,
            'topic'       => $topic,
        ) );

        wp_send_json_success(array(
            'schedule_id' => $schedule_id,
            'status'      => get_post_meta($schedule_id, '_luwipress_schedule_status', true),
        ));
    }

    /**
     * AJAX: Delete a scheduled content item
     */
    public function ajax_delete_schedule() {
        check_ajax_referer('luwipress_scheduler_nonce', '_wpnonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(__('Unauthorized', 'luwipress'));
        }

        $schedule_id = absint($_POST['schedule_id'] ?? 0);
        if (!$schedule_id) {
            wp_send_json_error(__('Invalid schedule ID', 'luwipress'));
        }

        $post = get_post($schedule_id);
        if (!$post || $post->post_type !== self::POST_TYPE) {
            wp_send_json_error(__('Schedule not found', 'luwipress'));
        }

        // Don't delete if already published
        $status = get_post_meta($schedule_id, '_luwipress_schedule_status', true);
        if ($status === self::STATUS_PUBLISHED) {
            wp_send_json_error(__('Cannot delete published content', 'luwipress'));
        }

        wp_delete_post($schedule_id, true);
        wp_send_json_success();
    }

    /**
     * REST callback: n8n sends generated content back
     */
    public function handle_callback($request) {
        $data = $request->get_json_params();
        if ( empty( $data ) ) {
            $data = $request->get_body_params();
        }
        $schedule_id = absint($data['schedule_id'] ?? 0);

        if (!$schedule_id || !get_post($schedule_id)) {
            return new WP_Error('invalid_schedule', __('Schedule not found', 'luwipress'), array('status' => 404));
        }

        // Save generated content
        if (!empty($data['title'])) {
            update_post_meta($schedule_id, '_luwipress_generated_title', sanitize_text_field($data['title']));
        }
        if (!empty($data['content'])) {
            update_post_meta($schedule_id, '_luwipress_generated_content', wp_kses_post($data['content']));
        }
        if (!empty($data['excerpt'])) {
            update_post_meta($schedule_id, '_luwipress_generated_excerpt', sanitize_text_field($data['excerpt']));
        }
        if (!empty($data['meta_title'])) {
            update_post_meta($schedule_id, '_luwipress_generated_meta_title', sanitize_text_field($data['meta_title']));
        }
        if (!empty($data['meta_description'])) {
            update_post_meta($schedule_id, '_luwipress_generated_meta_desc', sanitize_text_field($data['meta_description']));
        }
        if (!empty($data['tags'])) {
            update_post_meta($schedule_id, '_luwipress_generated_tags', array_map('sanitize_text_field', (array) $data['tags']));
        }
        if (!empty($data['image_url'])) {
            update_post_meta($schedule_id, '_luwipress_generated_image_url', sanitize_url($data['image_url']));
        }
        if (!empty($data['image_id'])) {
            update_post_meta($schedule_id, '_luwipress_generated_image_id', absint($data['image_id']));
        }

        // Mark as ready
        update_post_meta($schedule_id, '_luwipress_schedule_status', self::STATUS_READY);
        update_post_meta($schedule_id, '_luwipress_generated_at', current_time('mysql'));

        LuwiPress_Logger::log('Content generated successfully', 'info', array(
            'schedule_id' => $schedule_id,
            'topic'       => get_post_meta($schedule_id, '_luwipress_schedule_topic', true),
        ));

        return array('success' => true, 'schedule_id' => $schedule_id, 'status' => 'ready');
    }

    /**
     * Cron: Publish ready content when scheduled time arrives
     */
    public function publish_scheduled_content() {
        $schedules = get_posts(array(
            'post_type'      => self::POST_TYPE,
            'posts_per_page' => 20,
            'meta_query'     => array(
                array(
                    'key'   => '_luwipress_schedule_status',
                    'value' => self::STATUS_READY,
                ),
            ),
        ));

        $now = current_time('mysql');

        foreach ($schedules as $schedule) {
            $publish_date = get_post_meta($schedule->ID, '_luwipress_schedule_date', true);

            if (empty($publish_date) || $publish_date > $now) {
                continue;
            }

            $this->do_publish($schedule->ID);
        }
    }

    /**
     * Actually publish a scheduled item as a WordPress post
     */
    private function do_publish($schedule_id) {
        $title   = get_post_meta($schedule_id, '_luwipress_generated_title', true);
        $content = get_post_meta($schedule_id, '_luwipress_generated_content', true);
        $excerpt = get_post_meta($schedule_id, '_luwipress_generated_excerpt', true);
        $type    = get_post_meta($schedule_id, '_luwipress_schedule_type', true) ?: 'post';
        $user_id = get_post_meta($schedule_id, '_luwipress_schedule_user', true) ?: 1;

        if (empty($title) || empty($content)) {
            update_post_meta($schedule_id, '_luwipress_schedule_status', self::STATUS_FAILED);
            update_post_meta($schedule_id, '_luwipress_schedule_error', __('Missing title or content', 'luwipress'));
            return;
        }

        $post_data = array(
            'post_title'   => $title,
            'post_content' => $content,
            'post_excerpt' => $excerpt ?: '',
            'post_status'  => 'publish',
            'post_type'    => $type,
            'post_author'  => $user_id,
            'meta_input'   => array(
                '_luwipress_generated'   => 1,
                '_luwipress_schedule_id' => $schedule_id,
            ),
        );

        $post_id = wp_insert_post($post_data, true);

        if (is_wp_error($post_id)) {
            update_post_meta($schedule_id, '_luwipress_schedule_status', self::STATUS_FAILED);
            update_post_meta($schedule_id, '_luwipress_schedule_error', $post_id->get_error_message());
            return;
        }

        // Set featured image
        $image_id = get_post_meta($schedule_id, '_luwipress_generated_image_id', true);
        if ($image_id) {
            set_post_thumbnail($post_id, absint($image_id));
        }

        // Set tags
        $tags = get_post_meta($schedule_id, '_luwipress_generated_tags', true);
        if (!empty($tags) && is_array($tags)) {
            wp_set_post_tags($post_id, $tags);
        }

        // Set SEO meta via Plugin Detector (writes to correct SEO plugin)
        $meta_title = get_post_meta($schedule_id, '_luwipress_generated_meta_title', true);
        $meta_desc  = get_post_meta($schedule_id, '_luwipress_generated_meta_desc', true);

        if ( $meta_title || $meta_desc ) {
            $seo_data = array();
            if ( $meta_title ) $seo_data['title'] = $meta_title;
            if ( $meta_desc )  $seo_data['description'] = $meta_desc;
            LuwiPress_Plugin_Detector::get_instance()->set_seo_meta( $post_id, $seo_data );
        }

        // Mark schedule as published
        update_post_meta($schedule_id, '_luwipress_schedule_status', self::STATUS_PUBLISHED);
        update_post_meta($schedule_id, '_luwipress_published_post_id', $post_id);
        update_post_meta($schedule_id, '_luwipress_published_at', current_time('mysql'));

        LuwiPress_Logger::log('Scheduled content published', 'info', array(
            'schedule_id' => $schedule_id,
            'post_id'     => $post_id,
            'title'       => $title,
        ));
    }

    /**
     * Get all scheduled items for admin display
     */
    public static function get_scheduled_items( $status = '' ) {
        $args = array(
            'post_type'      => self::POST_TYPE,
            'posts_per_page' => 100,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'update_post_meta_cache' => true,
        );

        if ( $status ) {
            $args['meta_query'] = array(
                array(
                    'key'   => '_luwipress_schedule_status',
                    'value' => $status,
                ),
            );
        }

        $items = get_posts( $args );

        // Prime meta cache for all items in one query
        if ( ! empty( $items ) ) {
            update_postmeta_cache( wp_list_pluck( $items, 'ID' ) );
        }

        return $items;
    }

    /**
     * REST: Get schedule list (for n8n)
     */
    public function get_schedule_list($request) {
        $status = sanitize_text_field($request->get_param('status') ?? '');
        $items = self::get_scheduled_items($status);

        // Prime post meta cache for all schedule items in one query
        $item_ids = wp_list_pluck( $items, 'ID' );
        if ( ! empty( $item_ids ) ) {
            update_postmeta_cache( $item_ids );
        }

        $result = array();
        foreach ($items as $item) {
            $result[] = array(
                'schedule_id'  => $item->ID,
                'topic'        => get_post_meta($item->ID, '_luwipress_schedule_topic', true),
                'status'       => get_post_meta($item->ID, '_luwipress_schedule_status', true),
                'publish_date' => get_post_meta($item->ID, '_luwipress_schedule_date', true),
                'language'     => get_post_meta($item->ID, '_luwipress_schedule_language', true),
                'created'      => $item->post_date,
            );
        }

        return array('items' => $result, 'total' => count($result));
    }
}
