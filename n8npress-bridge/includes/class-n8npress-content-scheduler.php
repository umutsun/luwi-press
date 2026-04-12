<?php
/**
 * n8nPress Content Scheduler
 *
 * Allows users to enter topics and schedule AI-generated content
 * (articles + images) for automatic publishing via n8n workflows.
 *
 * @package n8nPress
 * @since 1.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class N8nPress_Content_Scheduler {

    private static $instance = null;

    /**
     * Custom post type for scheduled content
     */
    const POST_TYPE = 'n8npress_schedule';

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
        add_action('wp_ajax_n8npress_schedule_content', array($this, 'ajax_schedule_content'));
        add_action('wp_ajax_n8npress_delete_schedule', array($this, 'ajax_delete_schedule'));
        add_action('n8npress_publish_scheduled', array($this, 'publish_scheduled_content'));

        // Check for ready-to-publish content every 15 minutes
        if (!wp_next_scheduled('n8npress_publish_scheduled')) {
            wp_schedule_event(time(), 'fifteen_minutes', 'n8npress_publish_scheduled');
        }

        add_filter('cron_schedules', array($this, 'add_cron_interval'));
    }

    /**
     * Add 15-minute cron interval
     */
    public function add_cron_interval($schedules) {
        $schedules['fifteen_minutes'] = array(
            'interval' => 900,
            'display'  => __('Every 15 Minutes', 'n8npress'),
        );
        return $schedules;
    }

    /**
     * Register custom post type for tracking scheduled content
     */
    public function register_post_type() {
        register_post_type(self::POST_TYPE, array(
            'labels' => array(
                'name' => __('Scheduled Content', 'n8npress'),
            ),
            'public'  => false,
            'show_ui' => false,
        ));
    }

    /**
     * REST API routes for n8n callbacks
     */
    public function register_routes() {
        register_rest_route('n8npress/v1', '/schedule/callback', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_callback'),
            'permission_callback' => array($this, 'verify_token'),
        ));

        register_rest_route('n8npress/v1', '/schedule/list', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'get_schedule_list'),
            'permission_callback' => array($this, 'verify_token'),
        ));
    }

    /**
     * Token verification for REST endpoints
     */
    public function verify_token( $request ) {
        return N8nPress_Permission::check_token( $request );
    }

    /**
     * Add submenu under n8nPress
     */
    public function add_submenu() {
        add_submenu_page(
            'n8npress',
            __('Content Scheduler', 'n8npress'),
            __('Content Scheduler', 'n8npress'),
            'edit_posts',
            'n8npress-scheduler',
            array($this, 'render_page')
        );
    }

    /**
     * Render the scheduler admin page
     */
    public function render_page() {
        include N8NPRESS_PLUGIN_DIR . 'admin/scheduler-page.php';
    }

    /**
     * AJAX: Create a new scheduled content item and trigger n8n
     */
    public function ajax_schedule_content() {
        check_ajax_referer('n8npress_scheduler_nonce', '_wpnonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(__('Unauthorized', 'n8npress'));
        }

        $topic = sanitize_text_field($_POST['topic'] ?? '');
        $keywords = sanitize_text_field($_POST['keywords'] ?? '');
        $post_type = sanitize_text_field($_POST['target_post_type'] ?? 'post');
        $publish_date = sanitize_text_field($_POST['publish_date'] ?? '');
        $publish_time = sanitize_text_field($_POST['publish_time'] ?? '09:00');
        $generate_image = isset($_POST['generate_image']) ? 1 : 0;
        $language = sanitize_text_field($_POST['language'] ?? get_option('n8npress_target_language', 'tr'));
        $tone = sanitize_text_field($_POST['tone'] ?? 'professional');
        $word_count = absint($_POST['word_count'] ?? 1500);

        if (empty($topic)) {
            wp_send_json_error(__('Topic is required.', 'n8npress'));
        }

        if (empty($publish_date)) {
            wp_send_json_error(__('Publish date is required.', 'n8npress'));
        }

        // Create schedule record
        $schedule_id = wp_insert_post(array(
            'post_type'   => self::POST_TYPE,
            'post_title'  => $topic,
            'post_status' => 'publish',
            'meta_input'  => array(
                '_n8npress_schedule_status'   => self::STATUS_PENDING,
                '_n8npress_schedule_topic'    => $topic,
                '_n8npress_schedule_keywords' => $keywords,
                '_n8npress_schedule_type'     => $post_type,
                '_n8npress_schedule_date'     => $publish_date . ' ' . $publish_time,
                '_n8npress_schedule_image'    => $generate_image,
                '_n8npress_schedule_language' => $language,
                '_n8npress_schedule_tone'     => $tone,
                '_n8npress_schedule_words'    => $word_count,
                '_n8npress_schedule_created'  => current_time('mysql'),
                '_n8npress_schedule_user'     => get_current_user_id(),
            ),
        ), true);

        if (is_wp_error($schedule_id)) {
            wp_send_json_error($schedule_id->get_error_message());
        }

        // Budget guard
        $budget = N8nPress_Token_Tracker::check_budget( 'content-scheduler' );
        if ( is_wp_error( $budget ) ) {
            wp_send_json_error( $budget->get_error_message() );
        }

        // Queue content generation via built-in AI Engine
        N8nPress_Job_Queue::add( 'generate_content', array(
            'schedule_id'    => $schedule_id,
            'topic'          => $topic,
            'keywords'       => $keywords,
            'target_type'    => $post_type,
            'publish_date'   => $publish_date . ' ' . $publish_time,
            'generate_image' => (bool) $generate_image,
            'language'       => $language,
            'tone'           => $tone,
            'word_count'     => $word_count,
        ) );

        update_post_meta( $schedule_id, '_n8npress_schedule_status', self::STATUS_GENERATING );

        N8nPress_Logger::log( 'Content generation queued', 'info', array(
            'schedule_id' => $schedule_id,
            'topic'       => $topic,
        ) );

        wp_send_json_success(array(
            'schedule_id' => $schedule_id,
            'status'      => get_post_meta($schedule_id, '_n8npress_schedule_status', true),
        ));
    }

    /**
     * AJAX: Delete a scheduled content item
     */
    public function ajax_delete_schedule() {
        check_ajax_referer('n8npress_scheduler_nonce', '_wpnonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(__('Unauthorized', 'n8npress'));
        }

        $schedule_id = absint($_POST['schedule_id'] ?? 0);
        if (!$schedule_id) {
            wp_send_json_error(__('Invalid schedule ID', 'n8npress'));
        }

        $post = get_post($schedule_id);
        if (!$post || $post->post_type !== self::POST_TYPE) {
            wp_send_json_error(__('Schedule not found', 'n8npress'));
        }

        // Don't delete if already published
        $status = get_post_meta($schedule_id, '_n8npress_schedule_status', true);
        if ($status === self::STATUS_PUBLISHED) {
            wp_send_json_error(__('Cannot delete published content', 'n8npress'));
        }

        wp_delete_post($schedule_id, true);
        wp_send_json_success();
    }

    /**
     * REST callback: n8n sends generated content back
     */
    public function handle_callback($request) {
        $data = $request->get_json_params();
        $schedule_id = absint($data['schedule_id'] ?? 0);

        if (!$schedule_id || !get_post($schedule_id)) {
            return new WP_Error('invalid_schedule', __('Schedule not found', 'n8npress'), array('status' => 404));
        }

        // Save generated content
        if (!empty($data['title'])) {
            update_post_meta($schedule_id, '_n8npress_generated_title', sanitize_text_field($data['title']));
        }
        if (!empty($data['content'])) {
            update_post_meta($schedule_id, '_n8npress_generated_content', wp_kses_post($data['content']));
        }
        if (!empty($data['excerpt'])) {
            update_post_meta($schedule_id, '_n8npress_generated_excerpt', sanitize_text_field($data['excerpt']));
        }
        if (!empty($data['meta_title'])) {
            update_post_meta($schedule_id, '_n8npress_generated_meta_title', sanitize_text_field($data['meta_title']));
        }
        if (!empty($data['meta_description'])) {
            update_post_meta($schedule_id, '_n8npress_generated_meta_desc', sanitize_text_field($data['meta_description']));
        }
        if (!empty($data['tags'])) {
            update_post_meta($schedule_id, '_n8npress_generated_tags', array_map('sanitize_text_field', (array) $data['tags']));
        }
        if (!empty($data['image_url'])) {
            update_post_meta($schedule_id, '_n8npress_generated_image_url', sanitize_url($data['image_url']));
        }
        if (!empty($data['image_id'])) {
            update_post_meta($schedule_id, '_n8npress_generated_image_id', absint($data['image_id']));
        }

        // Mark as ready
        update_post_meta($schedule_id, '_n8npress_schedule_status', self::STATUS_READY);
        update_post_meta($schedule_id, '_n8npress_generated_at', current_time('mysql'));

        N8nPress_Logger::log('Content generated successfully', 'info', array(
            'schedule_id' => $schedule_id,
            'topic'       => get_post_meta($schedule_id, '_n8npress_schedule_topic', true),
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
                    'key'   => '_n8npress_schedule_status',
                    'value' => self::STATUS_READY,
                ),
            ),
        ));

        $now = current_time('mysql');

        foreach ($schedules as $schedule) {
            $publish_date = get_post_meta($schedule->ID, '_n8npress_schedule_date', true);

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
        $title   = get_post_meta($schedule_id, '_n8npress_generated_title', true);
        $content = get_post_meta($schedule_id, '_n8npress_generated_content', true);
        $excerpt = get_post_meta($schedule_id, '_n8npress_generated_excerpt', true);
        $type    = get_post_meta($schedule_id, '_n8npress_schedule_type', true) ?: 'post';
        $user_id = get_post_meta($schedule_id, '_n8npress_schedule_user', true) ?: 1;

        if (empty($title) || empty($content)) {
            update_post_meta($schedule_id, '_n8npress_schedule_status', self::STATUS_FAILED);
            update_post_meta($schedule_id, '_n8npress_schedule_error', __('Missing title or content', 'n8npress'));
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
                '_n8npress_generated'   => 1,
                '_n8npress_schedule_id' => $schedule_id,
            ),
        );

        $post_id = wp_insert_post($post_data, true);

        if (is_wp_error($post_id)) {
            update_post_meta($schedule_id, '_n8npress_schedule_status', self::STATUS_FAILED);
            update_post_meta($schedule_id, '_n8npress_schedule_error', $post_id->get_error_message());
            return;
        }

        // Set featured image
        $image_id = get_post_meta($schedule_id, '_n8npress_generated_image_id', true);
        if ($image_id) {
            set_post_thumbnail($post_id, absint($image_id));
        }

        // Set tags
        $tags = get_post_meta($schedule_id, '_n8npress_generated_tags', true);
        if (!empty($tags) && is_array($tags)) {
            wp_set_post_tags($post_id, $tags);
        }

        // Set SEO meta (Yoast / RankMath / n8nPress)
        $meta_title = get_post_meta($schedule_id, '_n8npress_generated_meta_title', true);
        $meta_desc  = get_post_meta($schedule_id, '_n8npress_generated_meta_desc', true);

        if ($meta_title) {
            update_post_meta($post_id, '_yoast_wpseo_title', $meta_title);
            update_post_meta($post_id, 'rank_math_title', $meta_title);
            update_post_meta($post_id, '_n8npress_meta_title', $meta_title);
        }
        if ($meta_desc) {
            update_post_meta($post_id, '_yoast_wpseo_metadesc', $meta_desc);
            update_post_meta($post_id, 'rank_math_description', $meta_desc);
            update_post_meta($post_id, '_n8npress_meta_description', $meta_desc);
        }

        // Mark schedule as published
        update_post_meta($schedule_id, '_n8npress_schedule_status', self::STATUS_PUBLISHED);
        update_post_meta($schedule_id, '_n8npress_published_post_id', $post_id);
        update_post_meta($schedule_id, '_n8npress_published_at', current_time('mysql'));

        N8nPress_Logger::log('Scheduled content published', 'info', array(
            'schedule_id' => $schedule_id,
            'post_id'     => $post_id,
            'title'       => $title,
        ));
    }

    /**
     * Get all scheduled items for admin display
     */
    public static function get_scheduled_items($status = '') {
        $args = array(
            'post_type'      => self::POST_TYPE,
            'posts_per_page' => 100,
            'orderby'        => 'date',
            'order'          => 'DESC',
        );

        if ($status) {
            $args['meta_query'] = array(
                array(
                    'key'   => '_n8npress_schedule_status',
                    'value' => $status,
                ),
            );
        }

        return get_posts($args);
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
                'topic'        => get_post_meta($item->ID, '_n8npress_schedule_topic', true),
                'status'       => get_post_meta($item->ID, '_n8npress_schedule_status', true),
                'publish_date' => get_post_meta($item->ID, '_n8npress_schedule_date', true),
                'language'     => get_post_meta($item->ID, '_n8npress_schedule_language', true),
                'created'      => $item->post_date,
            );
        }

        return array('items' => $result, 'total' => count($result));
    }
}
