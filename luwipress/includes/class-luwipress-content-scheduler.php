<?php
/**
 * LuwiPress Content Scheduler
 *
 * Allows users to enter topics and schedule AI-generated content
 * (articles + images) for automatic publishing via the LuwiPress AI engine.
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
    const STATUS_PENDING         = 'pending';
    const STATUS_GENERATING      = 'generating';
    const STATUS_OUTLINE_PENDING = 'outline_pending';
    const STATUS_READY           = 'ready';
    const STATUS_PUBLISHED       = 'published';
    const STATUS_FAILED          = 'failed';

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
        add_action('wp_ajax_luwipress_bulk_schedule_content', array($this, 'ajax_bulk_schedule_content'));
        add_action('wp_ajax_luwipress_delete_schedule', array($this, 'ajax_delete_schedule'));
        add_action('wp_ajax_luwipress_run_pending_now', array($this, 'ajax_run_pending_now'));
        add_action('wp_ajax_luwipress_retry_schedule', array($this, 'ajax_retry_schedule'));
        add_action('wp_ajax_luwipress_estimate_batch_cost', array($this, 'ajax_estimate_batch_cost'));
        add_action('wp_ajax_luwipress_enrich_schedule_draft', array($this, 'ajax_enrich_schedule_draft'));
        add_action('wp_ajax_luwipress_bulk_schedule_action', array($this, 'ajax_bulk_schedule_action'));
        add_action('wp_ajax_luwipress_get_outline', array($this, 'ajax_get_outline'));
        add_action('wp_ajax_luwipress_save_outline', array($this, 'ajax_save_outline'));
        add_action('wp_ajax_luwipress_regenerate_outline', array($this, 'ajax_regenerate_outline'));
        add_action('wp_ajax_luwipress_brainstorm_topics', array($this, 'ajax_brainstorm_topics'));
        add_action('wp_ajax_luwipress_save_recurring_plan', array($this, 'ajax_save_recurring_plan'));
        add_action('wp_ajax_luwipress_delete_recurring_plan', array($this, 'ajax_delete_recurring_plan'));
        add_action('wp_ajax_luwipress_toggle_recurring_plan', array($this, 'ajax_toggle_recurring_plan'));

        // Recurring plan cron — runs once an hour; each plan has its own next_run_at check.
        add_action('luwipress_recurring_plans_tick', array($this, 'cron_tick_recurring_plans'));
        if (!wp_next_scheduled('luwipress_recurring_plans_tick')) {
            wp_schedule_event(time() + 300, 'hourly', 'luwipress_recurring_plans_tick');
        }
        add_action('luwipress_publish_scheduled', array($this, 'publish_scheduled_content'));
        add_action('luwipress_generate_single', array($this, 'cron_generate_single'), 10, 1);

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
     * REST API routes for async AI callbacks
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

        // Delta polling — returns only rows whose status has changed since a given timestamp.
        // Used by the admin UI to update in-place without full page reload.
        register_rest_route('luwipress/v1', '/schedule/delta', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'handle_delta'),
            'permission_callback' => array($this, 'check_admin_permission'),
            'args'                => array(
                'since' => array( 'required' => false, 'type' => 'integer', 'default' => 0 ),
                'ids'   => array( 'required' => false, 'type' => 'string' ),
            ),
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
     * AJAX: Create a new scheduled content item and queue it for generation
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
     * AJAX: Bulk-queue a batch of topics.
     *
     * Body:
     *   topics[]           — one topic string per line (max 50 per call)
     *   interval_unit      — 'day' | 'hour'
     *   interval_value     — spacing between items (e.g. 1 day, 6 hours)
     *   start_date         — YYYY-MM-DD — first item publish date (defaults to tomorrow)
     *   start_time         — HH:MM      — publish hour (e.g. "09:00")
     *   generate_offset    — minutes between AI generation runs (rate limit friendly; default 5)
     *   Same shared fields as single-item form: tone, language, word_count, generate_image, target_post_type
     *
     * Behaviour:
     *   - Each topic becomes a `pending` schedule row immediately.
     *   - Each row gets a `wp_schedule_single_event('luwipress_generate_single')` offset staggered
     *     by `generate_offset` minutes so AI calls don't all fire at once.
     *   - `publish_date` is spread across rows using `interval_unit`/`interval_value`.
     *   - Existing publish cron (every 15 min) picks rows up when their `publish_date` arrives.
     */
    public function ajax_bulk_schedule_content() {
        check_ajax_referer('luwipress_scheduler_nonce', '_wpnonce');

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( __( 'Unauthorized', 'luwipress' ) );
        }

        // Parse topics (textarea with optional "topic | keywords" pipe syntax per line)
        $topics_raw = wp_unslash( $_POST['topics'] ?? '' );
        $topics_raw = is_array( $topics_raw ) ? implode( "\n", $topics_raw ) : (string) $topics_raw;
        $lines = array_filter( array_map( 'trim', explode( "\n", $topics_raw ) ) );

        if ( empty( $lines ) ) {
            wp_send_json_error( __( 'No topics provided.', 'luwipress' ) );
        }
        if ( count( $lines ) > 50 ) {
            wp_send_json_error( __( 'Maximum 50 topics per bulk request. Split your list and try again.', 'luwipress' ) );
        }

        // Shared options
        $post_type       = sanitize_text_field( $_POST['target_post_type'] ?? 'post' );
        $generate_image  = isset( $_POST['generate_image'] ) ? 1 : 0;
        $language        = sanitize_text_field( $_POST['language'] ?? get_option( 'luwipress_target_language', 'tr' ) );
        $tone            = sanitize_text_field( $_POST['tone'] ?? 'professional' );
        $word_count      = absint( $_POST['word_count'] ?? 1500 );
        $depth           = in_array( ( $_POST['depth'] ?? 'standard' ), array( 'standard', 'deep', 'editorial' ), true ) ? $_POST['depth'] : 'standard';
        $interval_unit   = in_array( ( $_POST['interval_unit'] ?? 'day' ), array( 'hour', 'day' ), true ) ? $_POST['interval_unit'] : 'day';
        $interval_value  = max( 1, absint( $_POST['interval_value'] ?? 1 ) );
        $generate_offset = max( 0, absint( $_POST['generate_offset'] ?? 5 ) ); // minutes
        $publish_mode    = in_array( ( $_POST['publish_mode'] ?? 'draft' ), array( 'draft', 'auto' ), true ) ? $_POST['publish_mode'] : 'draft';
        $use_outline     = ! empty( $_POST['use_outline_approval'] ) ? 1 : 0;

        // Multilingual duplicate — each extra language spawns a sibling schedule row per topic,
        // linked via WPML/Polylang after posts are created. Only kept if a translation plugin is active.
        $additional_langs = array();
        if ( ! empty( $_POST['additional_languages'] ) && is_array( $_POST['additional_languages'] ) ) {
            $raw_langs = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['additional_languages'] ) );
            $raw_langs = array_values( array_unique( array_filter( $raw_langs ) ) );
            if ( class_exists( 'LuwiPress_Plugin_Detector' ) ) {
                $t_info = LuwiPress_Plugin_Detector::get_instance()->detect_translation();
                if ( in_array( ( $t_info['plugin'] ?? 'none' ), array( 'wpml', 'polylang' ), true ) ) {
                    $active = array_map( 'strval', (array) ( $t_info['active_languages'] ?? array() ) );
                    $raw_langs = array_values( array_intersect( $raw_langs, $active ) );
                    $additional_langs = $raw_langs;
                }
            }
        }

        // Brand voice — batch-level override (empty string = fall back to site-level default at generation time).
        $brand_voice = isset( $_POST['brand_voice'] ) ? wp_kses_post( wp_unslash( $_POST['brand_voice'] ) ) : '';
        $brand_voice = trim( $brand_voice );
        // Optional: operator can promote this batch's brand voice to the site-level default in one click.
        if ( ! empty( $_POST['save_brand_voice_as_default'] ) && '' !== $brand_voice && current_user_can( 'manage_options' ) ) {
            update_option( 'luwipress_brand_voice_card', $brand_voice );
        }

        // Start date/time — default: tomorrow at specified hour
        $start_date = sanitize_text_field( $_POST['start_date'] ?? '' );
        $start_time = sanitize_text_field( $_POST['start_time'] ?? '09:00' );
        if ( empty( $start_date ) ) {
            $start_date = date_i18n( 'Y-m-d', strtotime( '+1 day', current_time( 'timestamp' ) ) );
        }
        $cursor_ts = strtotime( $start_date . ' ' . $start_time );
        if ( ! $cursor_ts ) {
            wp_send_json_error( __( 'Invalid start date/time.', 'luwipress' ) );
        }

        $created  = array();
        $skipped  = array();
        $step_sec = ( 'hour' === $interval_unit ) ? $interval_value * HOUR_IN_SECONDS : $interval_value * DAY_IN_SECONDS;

        foreach ( $lines as $idx => $line ) {
            // Pipe syntax: "topic | keywords" (legacy) OR "topic | keywords | depth=editorial | words=3000 | tone=creative | lang=tr | image=0"
            $parts    = array_map( 'trim', explode( '|', $line ) );
            $topic    = sanitize_text_field( $parts[0] ?? '' );
            $row      = self::parse_row_overrides( array_slice( $parts, 1 ) );
            $keywords = $row['keywords'];

            if ( empty( $topic ) ) {
                $skipped[] = array( 'row' => $idx, 'error' => 'empty topic' );
                continue;
            }

            // Merge batch defaults with per-topic overrides
            $r_depth    = $row['depth']          ?? $depth;
            $r_tone     = $row['tone']           ?? $tone;
            $r_words    = $row['word_count']     ?? $word_count;
            $r_lang     = $row['language']       ?? $language;
            $r_image    = $row['generate_image'] ?? $generate_image;
            $r_type     = $row['post_type']      ?? $post_type;

            $publish_ts   = $cursor_ts + ( $idx * $step_sec );
            $publish_date = date( 'Y-m-d H:i:s', $publish_ts );

            // If multilingual duplicate is active, every topic gets a shared translation group UUID
            // that links the source row to its sibling rows after posts are created.
            $siblings = array_values( array_diff( $additional_langs, array( $r_lang ) ) );
            $translation_group = ! empty( $siblings ) ? wp_generate_uuid4() : '';

            // Build the "per row" meta (used for source + each sibling, with language swapped).
            $make_meta = function( $lang_code, $is_source ) use ( $topic, $keywords, $r_type, $publish_date, $r_image, $r_tone, $r_words, $r_depth, $publish_mode, $use_outline, $brand_voice, $translation_group ) {
                return array(
                    '_luwipress_schedule_status'   => self::STATUS_PENDING,
                    '_luwipress_schedule_topic'    => $topic,
                    '_luwipress_schedule_keywords' => $keywords,
                    '_luwipress_schedule_type'     => $r_type,
                    '_luwipress_schedule_date'     => $publish_date,
                    '_luwipress_schedule_image'    => $r_image,
                    '_luwipress_schedule_language' => $lang_code,
                    '_luwipress_schedule_tone'     => $r_tone,
                    '_luwipress_schedule_words'    => $r_words,
                    '_luwipress_schedule_depth'    => $r_depth,
                    '_luwipress_schedule_publish_mode' => $publish_mode,
                    '_luwipress_schedule_use_outline'  => $use_outline,
                    '_luwipress_schedule_brand_voice'  => $brand_voice,
                    '_luwipress_schedule_translation_group'  => $translation_group,
                    '_luwipress_schedule_translation_source' => $is_source ? 1 : 0,
                    '_luwipress_schedule_created'  => current_time( 'mysql' ),
                    '_luwipress_schedule_user'     => get_current_user_id(),
                    '_luwipress_schedule_batch'    => 1,
                );
            };

            $to_insert = array();
            $to_insert[] = array( 'lang' => $r_lang, 'is_source' => true );
            foreach ( $siblings as $sib ) {
                $to_insert[] = array( 'lang' => $sib, 'is_source' => false );
            }

            foreach ( $to_insert as $row_spec ) {
                $schedule_id = wp_insert_post( array(
                    'post_type'   => self::POST_TYPE,
                    'post_title'  => $topic,
                    'post_status' => 'publish',
                    'meta_input'  => $make_meta( $row_spec['lang'], $row_spec['is_source'] ),
                ), true );

                if ( is_wp_error( $schedule_id ) ) {
                    $skipped[] = array( 'row' => $idx, 'error' => $schedule_id->get_error_message() );
                    continue;
                }

                // Stagger AI generation across the whole batch — each row offset from the previous.
                $gen_at = time() + ( count( $created ) * $generate_offset * MINUTE_IN_SECONDS );
                wp_schedule_single_event( $gen_at, 'luwipress_generate_single', array( (int) $schedule_id ) );

                $created[] = array(
                    'schedule_id'      => (int) $schedule_id,
                    'topic'            => $topic,
                    'language'         => $row_spec['lang'],
                    'translation_role' => $row_spec['is_source'] ? 'source' : 'sibling',
                    'publish_date'     => $publish_date,
                    'generate_at'      => date( 'Y-m-d H:i:s', $gen_at ),
                );
            }
        }

        spawn_cron();

        LuwiPress_Logger::log( 'Bulk content queued', 'info', array(
            'created' => count( $created ),
            'skipped' => count( $skipped ),
        ) );

        wp_send_json_success( array(
            'queued'  => count( $created ),
            'skipped' => count( $skipped ),
            'items'   => $created,
            'errors'  => $skipped,
        ) );
    }

    /**
     * Cron handler — generate AI content for a single scheduled item.
     * Called by `wp_schedule_single_event('luwipress_generate_single', ..., [$schedule_id])`.
     */
    public function cron_generate_single( $schedule_id ) {
        $schedule_id = absint( $schedule_id );
        if ( ! $schedule_id ) return;

        $status = get_post_meta( $schedule_id, '_luwipress_schedule_status', true );
        if ( self::STATUS_PENDING !== $status ) {
            return; // already processed, failed, or deleted
        }

        // Budget guard — if we're over budget, defer by 1 hour and retry.
        $budget = LuwiPress_Token_Tracker::check_budget( 'content-scheduler' );
        if ( is_wp_error( $budget ) ) {
            wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'luwipress_generate_single', array( $schedule_id ) );
            LuwiPress_Logger::log( 'Content generation deferred (budget): #' . $schedule_id, 'warning' );
            return;
        }

        $topic       = get_post_meta( $schedule_id, '_luwipress_schedule_topic', true );
        $keywords    = get_post_meta( $schedule_id, '_luwipress_schedule_keywords', true );
        $language    = get_post_meta( $schedule_id, '_luwipress_schedule_language', true );
        $tone        = get_post_meta( $schedule_id, '_luwipress_schedule_tone', true );
        $word_count  = (int) get_post_meta( $schedule_id, '_luwipress_schedule_words', true );
        $gen_image   = (bool) get_post_meta( $schedule_id, '_luwipress_schedule_image', true );
        $depth       = get_post_meta( $schedule_id, '_luwipress_schedule_depth', true ) ?: 'standard';
        $brand_voice = (string) get_post_meta( $schedule_id, '_luwipress_schedule_brand_voice', true );

        // Two-phase flow: outline approval
        // Phase 1 — produce outline for human review (only if enabled AND depth is deep/editorial).
        // Phase 2 — full article using approved outline (fires when _outline_approved meta is 1).
        $use_outline        = (bool) get_post_meta( $schedule_id, '_luwipress_schedule_use_outline', true );
        $outline_approved   = (bool) get_post_meta( $schedule_id, '_luwipress_schedule_outline_approved', true );
        $phase_1_eligible   = $use_outline && in_array( $depth, array( 'deep', 'editorial' ), true ) && ! $outline_approved;

        if ( $phase_1_eligible ) {
            update_post_meta( $schedule_id, '_luwipress_schedule_status', self::STATUS_GENERATING );

            $outline_prompt = LuwiPress_Prompts::outline_generation( array(
                'topic'       => $topic,
                'keywords'    => $keywords,
                'language'    => $language,
                'tone'        => $tone,
                'depth'       => $depth,
                'site_name'   => get_bloginfo( 'name' ),
                'brand_voice' => $brand_voice,
            ) );
            $messages = LuwiPress_AI_Engine::build_messages( $outline_prompt );
            $outline  = LuwiPress_AI_Engine::dispatch_json( 'scheduler-outline', $messages, array(
                'max_tokens' => 1800,
            ) );

            if ( is_wp_error( $outline ) ) {
                update_post_meta( $schedule_id, '_luwipress_schedule_status', self::STATUS_FAILED );
                update_post_meta( $schedule_id, '_luwipress_schedule_error', $outline->get_error_message() );
                LuwiPress_Logger::log( 'Outline generation failed: ' . $outline->get_error_message(), 'error', array( 'schedule_id' => $schedule_id ) );
                return;
            }

            // Basic shape validation — empty outlines are a silent failure we must surface.
            $sections = isset( $outline['sections'] ) && is_array( $outline['sections'] ) ? $outline['sections'] : array();
            if ( empty( $sections ) ) {
                update_post_meta( $schedule_id, '_luwipress_schedule_status', self::STATUS_FAILED );
                update_post_meta( $schedule_id, '_luwipress_schedule_error', __( 'Outline returned no sections. Try again or rewrite the topic.', 'luwipress' ) );
                return;
            }

            update_post_meta( $schedule_id, '_luwipress_schedule_outline', wp_json_encode( $outline ) );
            update_post_meta( $schedule_id, '_luwipress_schedule_outline_generated_at', current_time( 'mysql' ) );
            update_post_meta( $schedule_id, '_luwipress_schedule_status', self::STATUS_OUTLINE_PENDING );

            LuwiPress_Logger::log( 'Outline produced — awaiting human review', 'info', array(
                'schedule_id' => $schedule_id,
                'sections'    => count( $sections ),
            ) );
            return; // waits for human approval → ajax_save_outline will re-queue this row
        }

        update_post_meta( $schedule_id, '_luwipress_schedule_status', self::STATUS_GENERATING );

        // Deep/editorial content needs more tokens — scale max_tokens accordingly
        $max_tokens = 4096;
        if ( 'deep' === $depth )      $max_tokens = 6000;
        if ( 'editorial' === $depth ) $max_tokens = 8000;

        // Phase 2 path: if we have an approved outline, build the prompt from it; otherwise use the single-shot prompt.
        if ( $outline_approved ) {
            $outline_json = (string) get_post_meta( $schedule_id, '_luwipress_schedule_outline', true );
            $outline_arr  = $outline_json ? json_decode( $outline_json, true ) : array();
            $prompt = LuwiPress_Prompts::content_from_outline( array(
                'topic'       => $topic,
                'keywords'    => $keywords,
                'language'    => $language,
                'tone'        => $tone,
                'word_count'  => $word_count,
                'depth'       => $depth,
                'site_name'   => get_bloginfo( 'name' ),
                'brand_voice' => $brand_voice,
                'outline'     => is_array( $outline_arr ) ? $outline_arr : array(),
            ) );
        } else {
            $prompt = LuwiPress_Prompts::content_generation( array(
                'topic'       => $topic,
                'keywords'    => $keywords,
                'language'    => $language,
                'tone'        => $tone,
                'word_count'  => $word_count,
                'depth'       => $depth,
                'site_name'   => get_bloginfo( 'name' ),
                'brand_voice' => $brand_voice,
            ) );
        }

        $messages  = LuwiPress_AI_Engine::build_messages( $prompt );
        $ai_result = LuwiPress_AI_Engine::dispatch_json( 'content-scheduler', $messages, array(
            'max_tokens' => $max_tokens,
        ) );

        if ( is_wp_error( $ai_result ) ) {
            update_post_meta( $schedule_id, '_luwipress_schedule_status', self::STATUS_FAILED );
            update_post_meta( $schedule_id, '_luwipress_schedule_error', $ai_result->get_error_message() );
            LuwiPress_Logger::log( 'Content generation failed: ' . $ai_result->get_error_message(), 'error', array( 'schedule_id' => $schedule_id ) );
            return;
        }

        $callback_data = array(
            'schedule_id'      => $schedule_id,
            'title'            => $ai_result['title'] ?? $topic,
            'content'          => $ai_result['content'] ?? '',
            'excerpt'          => $ai_result['excerpt'] ?? '',
            'meta_title'       => $ai_result['meta_title'] ?? '',
            'meta_description' => $ai_result['meta_description'] ?? '',
            'tags'             => $ai_result['tags'] ?? array(),
        );

        if ( $gen_image && class_exists( 'LuwiPress_Image_Handler' ) ) {
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

    /**
     * AJAX: Manually trigger the queue worker — processes ALL pending items right now.
     * Useful for operators who don't want to wait for wp-cron.
     */
    public function ajax_run_pending_now() {
        check_ajax_referer( 'luwipress_scheduler_nonce', '_wpnonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( __( 'Unauthorized', 'luwipress' ) );
        }

        $pending = get_posts( array(
            'post_type'      => self::POST_TYPE,
            'posts_per_page' => 10, // safety cap per click
            'meta_query'     => array(
                array( 'key' => '_luwipress_schedule_status', 'value' => self::STATUS_PENDING ),
            ),
        ) );

        $processed = 0;
        foreach ( $pending as $p ) {
            $this->cron_generate_single( $p->ID );
            $processed++;
        }

        wp_send_json_success( array( 'processed' => $processed ) );
    }

    /**
     * Parse per-row override segments from the bulk textarea.
     * Accepts bare keywords as the first segment (legacy) and `key=value` for any segment.
     */
    private static function parse_row_overrides( array $segments ) {
        $out = array(
            'keywords' => '',
        );

        $allowed_depth = array( 'standard', 'deep', 'editorial' );
        $allowed_tone  = array( 'professional', 'casual', 'academic', 'creative', 'persuasive', 'informative' );

        foreach ( $segments as $i => $seg ) {
            $seg = trim( $seg );
            if ( '' === $seg ) continue;

            if ( false === strpos( $seg, '=' ) ) {
                // Bare segment — legacy keywords support (first segment only).
                if ( 0 === $i && '' === $out['keywords'] ) {
                    $out['keywords'] = sanitize_text_field( $seg );
                }
                continue;
            }

            list( $k, $v ) = array_map( 'trim', explode( '=', $seg, 2 ) );
            $k = strtolower( $k );
            $v = trim( $v, " \"'" );

            switch ( $k ) {
                case 'keywords':
                case 'kw':
                    $out['keywords'] = sanitize_text_field( $v );
                    break;
                case 'depth':
                    $vlow = strtolower( $v );
                    if ( in_array( $vlow, $allowed_depth, true ) ) $out['depth'] = $vlow;
                    break;
                case 'words':
                case 'wc':
                case 'word_count':
                    $out['word_count'] = max( 300, min( 5000, absint( $v ) ) );
                    break;
                case 'tone':
                    $vlow = strtolower( $v );
                    if ( in_array( $vlow, $allowed_tone, true ) ) $out['tone'] = $vlow;
                    break;
                case 'lang':
                case 'language':
                    $out['language'] = sanitize_text_field( $v );
                    break;
                case 'image':
                case 'img':
                    $out['generate_image'] = in_array( strtolower( $v ), array( '1', 'yes', 'true', 'on' ), true ) ? 1 : 0;
                    break;
                case 'type':
                case 'post_type':
                    $out['post_type'] = sanitize_text_field( $v );
                    break;
            }
        }

        return $out;
    }

    /**
     * AJAX: Retry a failed scheduled item.
     * Clears the error and schedules a new generation run 30s out.
     */
    public function ajax_retry_schedule() {
        check_ajax_referer( 'luwipress_scheduler_nonce', '_wpnonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( __( 'Unauthorized', 'luwipress' ) );
        }

        $schedule_id = absint( $_POST['schedule_id'] ?? 0 );
        if ( ! $schedule_id ) {
            wp_send_json_error( __( 'Invalid schedule ID', 'luwipress' ) );
        }

        $post = get_post( $schedule_id );
        if ( ! $post || $post->post_type !== self::POST_TYPE ) {
            wp_send_json_error( __( 'Schedule not found', 'luwipress' ) );
        }

        $status = get_post_meta( $schedule_id, '_luwipress_schedule_status', true );
        if ( self::STATUS_FAILED !== $status ) {
            wp_send_json_error( __( 'Only failed items can be retried.', 'luwipress' ) );
        }

        delete_post_meta( $schedule_id, '_luwipress_schedule_error' );
        update_post_meta( $schedule_id, '_luwipress_schedule_status', self::STATUS_PENDING );
        update_post_meta( $schedule_id, '_luwipress_schedule_retried_at', current_time( 'mysql' ) );

        wp_schedule_single_event( time() + 30, 'luwipress_generate_single', array( $schedule_id ) );
        spawn_cron();

        LuwiPress_Logger::log( 'Content schedule retried', 'info', array( 'schedule_id' => $schedule_id ) );

        wp_send_json_success( array( 'schedule_id' => $schedule_id, 'status' => self::STATUS_PENDING ) );
    }

    /**
     * AJAX: Fetch an outline for the review modal.
     */
    public function ajax_get_outline() {
        check_ajax_referer( 'luwipress_scheduler_nonce', '_wpnonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( __( 'Unauthorized', 'luwipress' ) );
        }

        $schedule_id = absint( $_POST['schedule_id'] ?? 0 );
        $post = $schedule_id ? get_post( $schedule_id ) : null;
        if ( ! $post || $post->post_type !== self::POST_TYPE ) {
            wp_send_json_error( __( 'Schedule not found', 'luwipress' ) );
        }

        $status       = get_post_meta( $schedule_id, '_luwipress_schedule_status', true );
        $outline_json = (string) get_post_meta( $schedule_id, '_luwipress_schedule_outline', true );
        $outline      = $outline_json ? json_decode( $outline_json, true ) : array();
        $topic        = get_post_meta( $schedule_id, '_luwipress_schedule_topic', true );
        $depth        = get_post_meta( $schedule_id, '_luwipress_schedule_depth', true );
        $word_count   = (int) get_post_meta( $schedule_id, '_luwipress_schedule_words', true );

        wp_send_json_success( array(
            'schedule_id' => $schedule_id,
            'status'      => $status,
            'topic'       => $topic,
            'depth'       => $depth,
            'word_count'  => $word_count,
            'outline'     => is_array( $outline ) ? $outline : array(),
        ) );
    }

    /**
     * AJAX: Save the edited outline and queue Phase 2 (full article generation).
     * The editor's edits ARE the approved outline — AI will follow them verbatim.
     */
    public function ajax_save_outline() {
        check_ajax_referer( 'luwipress_scheduler_nonce', '_wpnonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( __( 'Unauthorized', 'luwipress' ) );
        }

        $schedule_id = absint( $_POST['schedule_id'] ?? 0 );
        $post = $schedule_id ? get_post( $schedule_id ) : null;
        if ( ! $post || $post->post_type !== self::POST_TYPE ) {
            wp_send_json_error( __( 'Schedule not found', 'luwipress' ) );
        }

        $raw = wp_unslash( $_POST['outline'] ?? '' );
        $outline = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
        if ( ! is_array( $outline ) ) {
            wp_send_json_error( __( 'Invalid outline payload', 'luwipress' ) );
        }

        // Sanitize deeply — outline lives in AI prompts so we strip tags but keep punctuation.
        $clean = array(
            'title'            => sanitize_text_field( (string) ( $outline['title'] ?? '' ) ),
            'hook'             => sanitize_textarea_field( (string) ( $outline['hook'] ?? '' ) ),
            'sections'         => array(),
            'faq'              => array(),
            'closing_approach' => sanitize_textarea_field( (string) ( $outline['closing_approach'] ?? '' ) ),
        );
        if ( ! empty( $outline['sections'] ) && is_array( $outline['sections'] ) ) {
            foreach ( $outline['sections'] as $section ) {
                if ( ! is_array( $section ) ) continue;
                $heading = sanitize_text_field( (string) ( $section['heading'] ?? '' ) );
                if ( '' === $heading ) continue;
                $points = array();
                if ( ! empty( $section['points'] ) && is_array( $section['points'] ) ) {
                    foreach ( $section['points'] as $p ) {
                        $p = sanitize_text_field( (string) $p );
                        if ( '' !== $p ) $points[] = $p;
                    }
                }
                $clean['sections'][] = array( 'heading' => $heading, 'points' => $points );
            }
        }
        if ( ! empty( $outline['faq'] ) && is_array( $outline['faq'] ) ) {
            foreach ( $outline['faq'] as $q ) {
                $q = sanitize_text_field( (string) $q );
                if ( '' !== $q ) $clean['faq'][] = $q;
            }
        }

        if ( empty( $clean['sections'] ) ) {
            wp_send_json_error( __( 'Outline must have at least one section.', 'luwipress' ) );
        }

        update_post_meta( $schedule_id, '_luwipress_schedule_outline', wp_json_encode( $clean ) );
        update_post_meta( $schedule_id, '_luwipress_schedule_outline_approved', 1 );
        update_post_meta( $schedule_id, '_luwipress_schedule_outline_approved_at', current_time( 'mysql' ) );
        update_post_meta( $schedule_id, '_luwipress_schedule_status', self::STATUS_PENDING );
        delete_post_meta( $schedule_id, '_luwipress_schedule_error' );

        wp_schedule_single_event( time() + 15, 'luwipress_generate_single', array( $schedule_id ) );
        spawn_cron();

        LuwiPress_Logger::log( 'Outline approved — Phase 2 queued', 'info', array(
            'schedule_id' => $schedule_id,
            'sections'    => count( $clean['sections'] ),
        ) );

        wp_send_json_success( array(
            'schedule_id' => $schedule_id,
            'status'      => self::STATUS_PENDING,
            'phase'       => 2,
        ) );
    }

    /**
     * AJAX: Regenerate an outline from scratch (user rejected the current one).
     */
    public function ajax_regenerate_outline() {
        check_ajax_referer( 'luwipress_scheduler_nonce', '_wpnonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( __( 'Unauthorized', 'luwipress' ) );
        }

        $schedule_id = absint( $_POST['schedule_id'] ?? 0 );
        $post = $schedule_id ? get_post( $schedule_id ) : null;
        if ( ! $post || $post->post_type !== self::POST_TYPE ) {
            wp_send_json_error( __( 'Schedule not found', 'luwipress' ) );
        }

        // Reset to pending, clear approval (outline will be regenerated on next cron tick).
        delete_post_meta( $schedule_id, '_luwipress_schedule_outline' );
        delete_post_meta( $schedule_id, '_luwipress_schedule_outline_approved' );
        delete_post_meta( $schedule_id, '_luwipress_schedule_error' );
        update_post_meta( $schedule_id, '_luwipress_schedule_status', self::STATUS_PENDING );

        wp_schedule_single_event( time() + 10, 'luwipress_generate_single', array( $schedule_id ) );
        spawn_cron();

        wp_send_json_success( array( 'schedule_id' => $schedule_id, 'status' => self::STATUS_PENDING ) );
    }

    /**
     * AJAX: Topic brainstorm — AI generates a list of publishable article titles from a theme.
     */
    public function ajax_brainstorm_topics() {
        check_ajax_referer( 'luwipress_scheduler_nonce', '_wpnonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( __( 'Unauthorized', 'luwipress' ) );
        }

        // Budget guard — brainstorm is cheap but still an AI call.
        $budget = LuwiPress_Token_Tracker::check_budget( 'scheduler-brainstorm' );
        if ( is_wp_error( $budget ) ) {
            wp_send_json_error( $budget->get_error_message() );
        }

        $theme = sanitize_text_field( wp_unslash( $_POST['theme'] ?? '' ) );
        if ( '' === $theme ) {
            wp_send_json_error( __( 'Give me a theme to brainstorm around.', 'luwipress' ) );
        }
        $count      = max( 1, min( 20, absint( $_POST['count'] ?? 10 ) ) );
        $style_hint = sanitize_text_field( wp_unslash( $_POST['style_hint'] ?? '' ) );
        $language   = sanitize_text_field( $_POST['language'] ?? get_option( 'luwipress_target_language', 'en' ) );

        // Pull the 30 most-recent post titles so the AI doesn't propose dupes.
        $recent = get_posts( array(
            'post_type'      => 'post',
            'posts_per_page' => 30,
            'post_status'    => array( 'publish', 'draft', 'future', 'pending' ),
            'fields'         => 'ids',
        ) );
        $existing_titles = array_map( function( $id ) { return get_the_title( $id ); }, $recent );

        $prompt = LuwiPress_Prompts::topic_brainstorm( array(
            'theme'           => $theme,
            'count'           => $count,
            'style_hint'      => $style_hint,
            'language'        => $language,
            'existing_titles' => $existing_titles,
        ) );

        $messages = LuwiPress_AI_Engine::build_messages( $prompt );
        $result   = LuwiPress_AI_Engine::dispatch_json( 'scheduler-brainstorm', $messages, array(
            'max_tokens' => 1200,
        ) );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        $topics = isset( $result['topics'] ) && is_array( $result['topics'] ) ? $result['topics'] : array();
        $clean  = array();
        foreach ( $topics as $t ) {
            if ( ! is_array( $t ) ) continue;
            $title = sanitize_text_field( (string) ( $t['title'] ?? '' ) );
            if ( '' === $title ) continue;
            $clean[] = array(
                'title' => $title,
                'angle' => sanitize_text_field( (string) ( $t['angle'] ?? '' ) ),
                'depth' => in_array( ( $t['depth'] ?? '' ), array( 'standard', 'deep', 'editorial' ), true ) ? $t['depth'] : '',
            );
        }

        LuwiPress_Logger::log( 'Topic brainstorm', 'info', array( 'theme' => $theme, 'count' => count( $clean ) ) );

        wp_send_json_success( array(
            'theme'  => $theme,
            'topics' => $clean,
        ) );
    }

    /**
     * Recurring plans live in a single option as an array of plan dicts.
     * Each plan runs on its own cadence and auto-queues new topics via the brainstorm AI.
     */
    const PLANS_OPTION = 'luwipress_recurring_plans';

    public static function get_recurring_plans() {
        $raw = get_option( self::PLANS_OPTION, array() );
        return is_array( $raw ) ? array_values( $raw ) : array();
    }

    private static function save_recurring_plans( array $plans ) {
        update_option( self::PLANS_OPTION, array_values( $plans ), false );
    }

    /**
     * AJAX: Create or update a recurring plan.
     */
    public function ajax_save_recurring_plan() {
        check_ajax_referer( 'luwipress_scheduler_nonce', '_wpnonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( __( 'Unauthorized', 'luwipress' ) );
        }

        $id            = sanitize_text_field( $_POST['plan_id'] ?? '' );
        $name          = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
        $theme         = sanitize_text_field( wp_unslash( $_POST['theme'] ?? '' ) );
        $style_hint    = sanitize_text_field( wp_unslash( $_POST['style_hint'] ?? '' ) );
        $cadence       = in_array( ( $_POST['cadence'] ?? 'weekly' ), array( 'daily', 'weekly', 'biweekly', 'monthly' ), true ) ? $_POST['cadence'] : 'weekly';
        $count         = max( 1, min( 10, absint( $_POST['count'] ?? 3 ) ) );
        $depth         = in_array( ( $_POST['depth'] ?? 'standard' ), array( 'standard', 'deep', 'editorial' ), true ) ? $_POST['depth'] : 'standard';
        $tone          = sanitize_text_field( $_POST['tone'] ?? 'informative' );
        $word_count    = max( 300, min( 5000, absint( $_POST['word_count'] ?? 1500 ) ) );
        $language      = sanitize_text_field( $_POST['language'] ?? get_option( 'luwipress_target_language', 'en' ) );
        $post_type     = sanitize_text_field( $_POST['target_post_type'] ?? 'post' );
        $publish_mode  = in_array( ( $_POST['publish_mode'] ?? 'draft' ), array( 'draft', 'auto' ), true ) ? $_POST['publish_mode'] : 'draft';
        $generate_image = ! empty( $_POST['generate_image'] ) ? 1 : 0;

        if ( '' === $name || '' === $theme ) {
            wp_send_json_error( __( 'Name and theme are required.', 'luwipress' ) );
        }

        $plans = self::get_recurring_plans();
        $now   = time();

        $plan = array(
            'id'              => $id !== '' ? $id : wp_generate_uuid4(),
            'name'            => $name,
            'theme'           => $theme,
            'style_hint'      => $style_hint,
            'cadence'         => $cadence,
            'count'           => $count,
            'depth'           => $depth,
            'tone'            => $tone,
            'word_count'      => $word_count,
            'language'        => $language,
            'target_post_type'=> $post_type,
            'publish_mode'    => $publish_mode,
            'generate_image'  => $generate_image,
            'paused'          => ! empty( $_POST['paused'] ) ? 1 : 0,
            'last_run_at'     => 0,
            'next_run_at'     => $now + self::cadence_seconds( $cadence ),
            'created_at'      => $now,
        );

        $found = false;
        foreach ( $plans as $k => $existing ) {
            if ( isset( $existing['id'] ) && $existing['id'] === $plan['id'] ) {
                // Preserve run history on update.
                $plan['last_run_at'] = (int) ( $existing['last_run_at'] ?? 0 );
                $plan['next_run_at'] = (int) ( $existing['next_run_at'] ?? $plan['next_run_at'] );
                $plan['created_at']  = (int) ( $existing['created_at']  ?? $plan['created_at']  );
                // If cadence changed, recompute next_run_at from last_run_at (or now if never ran).
                if ( ( $existing['cadence'] ?? '' ) !== $cadence ) {
                    $base = $plan['last_run_at'] > 0 ? $plan['last_run_at'] : $now;
                    $plan['next_run_at'] = $base + self::cadence_seconds( $cadence );
                }
                $plans[ $k ] = $plan;
                $found = true;
                break;
            }
        }
        if ( ! $found ) {
            $plans[] = $plan;
        }

        self::save_recurring_plans( $plans );

        wp_send_json_success( array( 'plan' => $plan ) );
    }

    /**
     * AJAX: Delete a recurring plan by id.
     */
    public function ajax_delete_recurring_plan() {
        check_ajax_referer( 'luwipress_scheduler_nonce', '_wpnonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( __( 'Unauthorized', 'luwipress' ) );
        }
        $id = sanitize_text_field( $_POST['plan_id'] ?? '' );
        if ( '' === $id ) wp_send_json_error( __( 'Missing plan id', 'luwipress' ) );

        $plans = self::get_recurring_plans();
        $kept = array_values( array_filter( $plans, function( $p ) use ( $id ) { return ( $p['id'] ?? '' ) !== $id; } ) );
        self::save_recurring_plans( $kept );
        wp_send_json_success( array( 'deleted' => $id ) );
    }

    /**
     * AJAX: Pause / resume a plan.
     */
    public function ajax_toggle_recurring_plan() {
        check_ajax_referer( 'luwipress_scheduler_nonce', '_wpnonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( __( 'Unauthorized', 'luwipress' ) );
        }
        $id = sanitize_text_field( $_POST['plan_id'] ?? '' );
        if ( '' === $id ) wp_send_json_error( __( 'Missing plan id', 'luwipress' ) );

        $plans = self::get_recurring_plans();
        $updated_plan = null;
        foreach ( $plans as $k => $p ) {
            if ( ( $p['id'] ?? '' ) === $id ) {
                $plans[ $k ]['paused'] = empty( $p['paused'] ) ? 1 : 0;
                $updated_plan = $plans[ $k ];
                break;
            }
        }
        if ( ! $updated_plan ) wp_send_json_error( __( 'Plan not found', 'luwipress' ) );

        self::save_recurring_plans( $plans );
        wp_send_json_success( array( 'plan' => $updated_plan ) );
    }

    /**
     * Convert a cadence string to seconds between runs.
     */
    private static function cadence_seconds( $cadence ) {
        switch ( $cadence ) {
            case 'daily':    return DAY_IN_SECONDS;
            case 'biweekly': return 14 * DAY_IN_SECONDS;
            case 'monthly':  return 30 * DAY_IN_SECONDS;
            case 'weekly':
            default:         return 7 * DAY_IN_SECONDS;
        }
    }

    /**
     * Cron: iterate recurring plans, fire brainstorm + ingest for any whose next_run_at has passed.
     */
    public function cron_tick_recurring_plans() {
        $plans = self::get_recurring_plans();
        if ( empty( $plans ) ) return;

        $now     = time();
        $changed = false;

        foreach ( $plans as $k => $plan ) {
            if ( ! empty( $plan['paused'] ) ) continue;
            $next = (int) ( $plan['next_run_at'] ?? 0 );
            if ( $next > $now ) continue;

            // Budget guard — if we're over the cap, push the plan out an hour and try next tick.
            $budget = LuwiPress_Token_Tracker::check_budget( 'scheduler-recurring' );
            if ( is_wp_error( $budget ) ) {
                $plans[ $k ]['next_run_at'] = $now + HOUR_IN_SECONDS;
                $changed = true;
                continue;
            }

            // Brainstorm N topics for this plan's theme (filter against recent titles).
            $recent_ids = get_posts( array(
                'post_type'      => 'post',
                'posts_per_page' => 30,
                'post_status'    => array( 'publish', 'draft', 'future', 'pending' ),
                'fields'         => 'ids',
            ) );
            $existing_titles = array_map( function( $id ) { return get_the_title( $id ); }, $recent_ids );

            $prompt = LuwiPress_Prompts::topic_brainstorm( array(
                'theme'           => $plan['theme'],
                'count'           => (int) $plan['count'],
                'style_hint'      => $plan['style_hint'] ?? '',
                'language'        => $plan['language'],
                'existing_titles' => $existing_titles,
            ) );
            $messages = LuwiPress_AI_Engine::build_messages( $prompt );
            $result   = LuwiPress_AI_Engine::dispatch_json( 'scheduler-recurring', $messages, array( 'max_tokens' => 1200 ) );

            if ( is_wp_error( $result ) ) {
                $plans[ $k ]['next_run_at'] = $now + HOUR_IN_SECONDS; // retry sooner on failure
                $plans[ $k ]['last_error']  = $result->get_error_message();
                $changed = true;
                LuwiPress_Logger::log( 'Recurring plan brainstorm failed: ' . $result->get_error_message(), 'error', array( 'plan_id' => $plan['id'] ) );
                continue;
            }

            $topics = isset( $result['topics'] ) && is_array( $result['topics'] ) ? $result['topics'] : array();
            if ( empty( $topics ) ) {
                $plans[ $k ]['next_run_at'] = $now + self::cadence_seconds( $plan['cadence'] );
                $plans[ $k ]['last_error']  = 'Brainstorm returned no topics';
                $changed = true;
                continue;
            }

            // Queue each topic as a schedule row, spaced one day apart starting tomorrow 09:00.
            $start_ts = strtotime( 'tomorrow 09:00', $now );
            $staggered = 0;

            foreach ( $topics as $t ) {
                $title = sanitize_text_field( (string) ( $t['title'] ?? '' ) );
                if ( '' === $title ) continue;

                $row_depth = in_array( ( $t['depth'] ?? '' ), array( 'standard', 'deep', 'editorial' ), true ) ? $t['depth'] : $plan['depth'];
                $publish_ts = $start_ts + ( $staggered * DAY_IN_SECONDS );
                $publish_date = date( 'Y-m-d H:i:s', $publish_ts );

                $schedule_id = wp_insert_post( array(
                    'post_type'   => self::POST_TYPE,
                    'post_title'  => $title,
                    'post_status' => 'publish',
                    'meta_input'  => array(
                        '_luwipress_schedule_status'   => self::STATUS_PENDING,
                        '_luwipress_schedule_topic'    => $title,
                        '_luwipress_schedule_keywords' => '',
                        '_luwipress_schedule_type'     => $plan['target_post_type'],
                        '_luwipress_schedule_date'     => $publish_date,
                        '_luwipress_schedule_image'    => (int) $plan['generate_image'],
                        '_luwipress_schedule_language' => $plan['language'],
                        '_luwipress_schedule_tone'     => $plan['tone'],
                        '_luwipress_schedule_words'    => (int) $plan['word_count'],
                        '_luwipress_schedule_depth'    => $row_depth,
                        '_luwipress_schedule_publish_mode' => $plan['publish_mode'],
                        '_luwipress_schedule_use_outline'  => 0,
                        '_luwipress_schedule_brand_voice'  => '',
                        '_luwipress_schedule_created'      => current_time( 'mysql' ),
                        '_luwipress_schedule_user'         => 1,
                        '_luwipress_schedule_batch'        => 1,
                        '_luwipress_schedule_recurring_plan' => $plan['id'],
                    ),
                ), true );
                if ( is_wp_error( $schedule_id ) ) continue;

                // Stagger AI generation 10 min apart so we don't burst the provider.
                $gen_at = $now + ( $staggered * 10 * MINUTE_IN_SECONDS );
                wp_schedule_single_event( $gen_at, 'luwipress_generate_single', array( (int) $schedule_id ) );
                $staggered++;
            }

            $plans[ $k ]['last_run_at']   = $now;
            $plans[ $k ]['last_queued']   = $staggered;
            $plans[ $k ]['next_run_at']   = $now + self::cadence_seconds( $plan['cadence'] );
            $plans[ $k ]['last_error']    = '';
            $changed = true;

            LuwiPress_Logger::log( 'Recurring plan queued topics', 'info', array(
                'plan_id' => $plan['id'],
                'queued'  => $staggered,
            ) );
        }

        if ( $changed ) {
            self::save_recurring_plans( $plans );
        }
    }

    /**
     * AJAX: Enrich a draft that was produced by this scheduler.
     * Runs existing modules against the linked post: internal link resolution + taxonomy suggestion.
     * Safe to call repeatedly — each sub-step is idempotent.
     */
    public function ajax_enrich_schedule_draft() {
        check_ajax_referer( 'luwipress_scheduler_nonce', '_wpnonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( __( 'Unauthorized', 'luwipress' ) );
        }

        $schedule_id = absint( $_POST['schedule_id'] ?? 0 );
        if ( ! $schedule_id ) {
            wp_send_json_error( __( 'Invalid schedule ID', 'luwipress' ) );
        }

        $published_id = (int) get_post_meta( $schedule_id, '_luwipress_published_post_id', true );
        if ( ! $published_id ) {
            wp_send_json_error( __( 'No draft attached yet — wait for generation to finish.', 'luwipress' ) );
        }

        $post = get_post( $published_id );
        if ( ! $post ) {
            wp_send_json_error( __( 'Linked post not found.', 'luwipress' ) );
        }

        $changes = array();

        // 1. Internal-link marker resolution.
        if ( class_exists( 'LuwiPress_Internal_Linker' ) ) {
            $before = $post->post_content;
            $after  = LuwiPress_Internal_Linker::get_instance()->filter_resolve_markers( $before );
            if ( $after !== $before ) {
                wp_update_post( array( 'ID' => $published_id, 'post_content' => $after ) );
                $before_count = preg_match_all( '/\[INTERNAL_LINK:/', $before );
                $after_count  = preg_match_all( '/\[INTERNAL_LINK:/', $after );
                $resolved_n   = max( 0, $before_count - $after_count );
                if ( $resolved_n > 0 ) {
                    $changes[] = sprintf( _n( '%d internal link resolved', '%d internal links resolved', $resolved_n, 'luwipress' ), $resolved_n );
                }
                $post = get_post( $published_id ); // refresh for next steps
            }
        }

        // 2. Taxonomy suggestion — pick from existing terms on this post's taxonomies.
        $tax_result = $this->suggest_existing_terms( $post );
        if ( ! is_wp_error( $tax_result ) && ! empty( $tax_result['applied'] ) ) {
            foreach ( $tax_result['applied'] as $taxonomy => $names ) {
                $changes[] = sprintf( '%s: %s', $taxonomy, implode( ', ', $names ) );
            }
        }

        update_post_meta( $published_id, '_luwipress_enriched_at', current_time( 'mysql' ) );
        update_post_meta( $schedule_id, '_luwipress_schedule_enriched', 1 );

        LuwiPress_Logger::log( 'Draft enriched', 'info', array(
            'schedule_id' => $schedule_id,
            'post_id'     => $published_id,
            'changes'     => $changes,
        ) );

        wp_send_json_success( array(
            'schedule_id' => $schedule_id,
            'post_id'     => $published_id,
            'changes'     => $changes,
            'summary'     => empty( $changes ) ? __( 'Nothing to enrich — content already clean.', 'luwipress' ) : implode( ' · ', $changes ),
        ) );
    }

    /**
     * Ask AI to pick 1-3 best categories and 3-6 tags for a post from the site's EXISTING taxonomy terms only.
     * Does not invent new terms — keeps the taxonomy clean.
     */
    private function suggest_existing_terms( $post ) {
        $post_type  = get_post_type( $post );
        $taxonomies = get_object_taxonomies( $post_type, 'objects' );
        if ( empty( $taxonomies ) ) return array( 'applied' => array() );

        $applied = array();

        foreach ( $taxonomies as $slug => $tax ) {
            if ( empty( $tax->public ) && empty( $tax->show_ui ) ) continue;

            $terms = get_terms( array( 'taxonomy' => $slug, 'hide_empty' => false, 'number' => 120 ) );
            if ( is_wp_error( $terms ) || empty( $terms ) ) continue;

            $term_list = array_map( function( $t ) { return $t->name; }, $terms );

            $existing_ids = wp_get_object_terms( $post->ID, $slug, array( 'fields' => 'ids' ) );
            if ( ! empty( $existing_ids ) && ! is_wp_error( $existing_ids ) ) {
                continue; // already assigned — don't overwrite operator choices
            }

            $prompt = array(
                'system' => "You classify content against a fixed taxonomy. You MUST only pick from the provided list — never invent new terms. Return strict JSON.",
                'user'   => sprintf(
                    "Taxonomy: %s\nAvailable terms (pick only from this list):\n%s\n\nPost title: %s\nPost excerpt: %s\n\nReturn JSON: { \"picks\": [\"term name\", \"term name\"] } — pick %d-%d most relevant. If none fit, return { \"picks\": [] }.",
                    $slug,
                    implode( ' | ', $term_list ),
                    $post->post_title,
                    wp_trim_words( wp_strip_all_tags( $post->post_content ), 60 ),
                    $tax->hierarchical ? 1 : 3,
                    $tax->hierarchical ? 2 : 6
                ),
            );

            $messages = LuwiPress_AI_Engine::build_messages( $prompt );
            $result   = LuwiPress_AI_Engine::dispatch_json( 'scheduler-enrich-taxonomy', $messages, array( 'max_tokens' => 300 ) );

            if ( is_wp_error( $result ) ) continue;

            $picks = isset( $result['picks'] ) && is_array( $result['picks'] ) ? $result['picks'] : array();
            if ( empty( $picks ) ) continue;

            // Resolve picks → existing term IDs (case-insensitive match).
            $lookup = array();
            foreach ( $terms as $t ) {
                $lookup[ mb_strtolower( $t->name ) ] = $t->term_id;
            }
            $ids   = array();
            $names = array();
            foreach ( $picks as $pick ) {
                $key = mb_strtolower( trim( (string) $pick ) );
                if ( isset( $lookup[ $key ] ) ) {
                    $ids[]   = (int) $lookup[ $key ];
                    $names[] = $pick;
                }
            }
            if ( ! empty( $ids ) ) {
                wp_set_object_terms( $post->ID, $ids, $slug, false );
                $applied[ $slug ] = $names;
            }
        }

        return array( 'applied' => $applied );
    }

    /**
     * AJAX: Bulk action on multiple schedule rows (approve+publish drafts, delete, retry).
     * Input: action (publish|delete|retry), ids[] — array of schedule IDs.
     */
    public function ajax_bulk_schedule_action() {
        check_ajax_referer( 'luwipress_scheduler_nonce', '_wpnonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( __( 'Unauthorized', 'luwipress' ) );
        }

        $op  = sanitize_text_field( $_POST['bulk_action'] ?? '' );
        $ids = array_map( 'absint', (array) ( $_POST['ids'] ?? array() ) );
        $ids = array_filter( $ids );

        if ( empty( $ids ) ) {
            wp_send_json_error( __( 'No items selected.', 'luwipress' ) );
        }
        if ( ! in_array( $op, array( 'publish', 'delete', 'retry' ), true ) ) {
            wp_send_json_error( __( 'Unknown action.', 'luwipress' ) );
        }

        $results = array( 'done' => 0, 'skipped' => 0 );

        foreach ( $ids as $id ) {
            $post = get_post( $id );
            if ( ! $post || $post->post_type !== self::POST_TYPE ) {
                $results['skipped']++;
                continue;
            }

            if ( 'publish' === $op ) {
                $published_id = (int) get_post_meta( $id, '_luwipress_published_post_id', true );
                if ( $published_id && 'draft' === get_post_status( $published_id ) ) {
                    wp_update_post( array( 'ID' => $published_id, 'post_status' => 'publish' ) );
                    update_post_meta( $id, '_luwipress_schedule_status', self::STATUS_PUBLISHED );
                    update_post_meta( $id, '_luwipress_published_at', current_time( 'mysql' ) );
                    $results['done']++;
                } else {
                    $results['skipped']++;
                }
            } elseif ( 'delete' === $op ) {
                wp_delete_post( $id, true );
                $results['done']++;
            } elseif ( 'retry' === $op ) {
                $status = get_post_meta( $id, '_luwipress_schedule_status', true );
                if ( self::STATUS_FAILED === $status ) {
                    delete_post_meta( $id, '_luwipress_schedule_error' );
                    update_post_meta( $id, '_luwipress_schedule_status', self::STATUS_PENDING );
                    wp_schedule_single_event( time() + 30, 'luwipress_generate_single', array( $id ) );
                    $results['done']++;
                } else {
                    $results['skipped']++;
                }
            }
        }

        if ( 'retry' === $op ) spawn_cron();

        LuwiPress_Logger::log( 'Bulk schedule action: ' . $op, 'info', $results );

        wp_send_json_success( array(
            'action'  => $op,
            'done'    => $results['done'],
            'skipped' => $results['skipped'],
        ) );
    }

    /**
     * AJAX: Estimate batch cost for the review step.
     * Input: topic_count, word_count, depth, generate_image (all per-row defaults).
     * Output: estimated $ cost based on current provider/model pricing.
     */
    public function ajax_estimate_batch_cost() {
        check_ajax_referer( 'luwipress_scheduler_nonce', '_wpnonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( __( 'Unauthorized', 'luwipress' ) );
        }

        $topic_count    = max( 1, min( 50, absint( $_POST['topic_count'] ?? 1 ) ) );
        $word_count     = max( 300, min( 5000, absint( $_POST['word_count'] ?? 1500 ) ) );
        $depth          = in_array( ( $_POST['depth'] ?? 'standard' ), array( 'standard', 'deep', 'editorial' ), true ) ? $_POST['depth'] : 'standard';
        $generate_image = ! empty( $_POST['generate_image'] );

        $provider = get_option( 'luwipress_ai_provider', 'openai' );
        $model    = get_option( 'luwipress_ai_model', 'gpt-4o-mini' );

        // Token heuristics:
        //   input  ≈ 450 (base prompt) + 150 (deep) / 300 (editorial)
        //   output ≈ word_count × 1.35 (typical English/Turkish ratio, small buffer for JSON envelope)
        $input_base = 450;
        if ( 'deep' === $depth )      $input_base += 150;
        if ( 'editorial' === $depth ) $input_base += 300;

        $input_tokens_per  = $input_base;
        $output_tokens_per = (int) round( $word_count * 1.35 );

        $per_topic_cost = LuwiPress_Token_Tracker::estimate_cost( $provider, $model, $input_tokens_per, $output_tokens_per );
        $total_cost     = $per_topic_cost * $topic_count;

        // Image cost — DALL-E 3 std 1024×1024 is ~$0.04/image. Keep rough; configurable via filter.
        $image_cost_per = $generate_image ? (float) apply_filters( 'luwipress_image_cost_estimate', 0.04 ) : 0.0;
        $image_total    = $image_cost_per * $topic_count;

        wp_send_json_success( array(
            'per_topic'   => round( $per_topic_cost, 4 ),
            'text_total'  => round( $total_cost, 4 ),
            'image_total' => round( $image_total, 4 ),
            'grand_total' => round( $total_cost + $image_total, 4 ),
            'provider'    => $provider,
            'model'       => $model,
            'topic_count' => $topic_count,
            'assumptions' => array(
                'input_tokens_per'  => $input_tokens_per,
                'output_tokens_per' => $output_tokens_per,
                'image_cost_per'    => $image_cost_per,
            ),
        ) );
    }

    /**
     * REST: delta polling — return rows that changed since a timestamp.
     *
     * Replaces full-page reload polling with targeted row updates.
     * The admin UI calls this every 20s while `generating` or `outline_pending`
     * rows are present, and patches the DOM in place.
     */
    public function handle_delta( $request ) {
        $since = (int) $request->get_param( 'since' );
        $ids_param = (string) $request->get_param( 'ids' );
        $only_ids  = array();
        if ( '' !== $ids_param ) {
            $only_ids = array_filter( array_map( 'absint', explode( ',', $ids_param ) ) );
        }

        $args = array(
            'post_type'      => self::POST_TYPE,
            'posts_per_page' => 100,
            'post_status'    => 'publish',
            'orderby'        => 'modified',
            'order'          => 'DESC',
        );
        if ( $since > 0 ) {
            $args['date_query'] = array(
                'column' => 'post_modified_gmt',
                'after'  => gmdate( 'Y-m-d H:i:s', $since ),
            );
        }
        if ( ! empty( $only_ids ) ) {
            $args['post__in'] = $only_ids;
        }

        $posts = get_posts( $args );
        $now   = time();

        $items = array();
        foreach ( $posts as $p ) {
            $id       = (int) $p->ID;
            $status   = (string) get_post_meta( $id, '_luwipress_schedule_status', true );
            $pub_id   = (int)    get_post_meta( $id, '_luwipress_published_post_id', true );
            $error    = (string) get_post_meta( $id, '_luwipress_schedule_error', true );
            $mode     = (string) get_post_meta( $id, '_luwipress_schedule_publish_mode', true );
            $is_draft = false;
            $post_status_str = '';
            if ( $pub_id ) {
                $ps = get_post_status( $pub_id );
                $post_status_str = $ps ?: '';
                $is_draft = ( 'draft' === $mode ) && ( 'draft' === $ps );
            }
            $items[] = array(
                'id'            => $id,
                'status'        => $status,
                'published_id'  => $pub_id,
                'post_status'   => $post_status_str,
                'is_draft'      => $is_draft,
                'error'         => $error,
                'modified_gmt'  => get_post_modified_time( 'U', true, $p ),
            );
        }

        return rest_ensure_response( array(
            'now'   => $now,
            'items' => $items,
        ) );
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
     * REST callback: async AI pipeline sends generated content back
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

        // Draft-first mode: materialize the draft immediately after generation.
        // Auto mode: leaves the row as READY; the publish cron promotes it at publish_date.
        $publish_mode = get_post_meta($schedule_id, '_luwipress_schedule_publish_mode', true) ?: 'auto';
        if ('draft' === $publish_mode) {
            $this->do_publish($schedule_id);
        }

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
     * Publish / materialize the scheduled item. Mode = `auto` creates a live post, `draft` creates a reviewable draft.
     */
    private function do_publish($schedule_id) {
        $title   = get_post_meta($schedule_id, '_luwipress_generated_title', true);
        $content = get_post_meta($schedule_id, '_luwipress_generated_content', true);
        $excerpt = get_post_meta($schedule_id, '_luwipress_generated_excerpt', true);
        $type    = get_post_meta($schedule_id, '_luwipress_schedule_type', true) ?: 'post';
        $user_id = get_post_meta($schedule_id, '_luwipress_schedule_user', true) ?: 1;
        $mode    = get_post_meta($schedule_id, '_luwipress_schedule_publish_mode', true) ?: 'auto';

        if (empty($title) || empty($content)) {
            update_post_meta($schedule_id, '_luwipress_schedule_status', self::STATUS_FAILED);
            update_post_meta($schedule_id, '_luwipress_schedule_error', __('Missing title or content', 'luwipress'));
            return;
        }

        $post_status = ('draft' === $mode) ? 'draft' : 'publish';

        $post_data = array(
            'post_title'   => $title,
            'post_content' => $content,
            'post_excerpt' => $excerpt ?: '',
            'post_status'  => $post_status,
            'post_type'    => $type,
            'post_author'  => $user_id,
            'meta_input'   => array(
                '_luwipress_generated'   => 1,
                '_luwipress_schedule_id' => $schedule_id,
            ),
        );

        // Seed the draft with the scheduled publish date so the editor sees it.
        if ('draft' === $mode) {
            $publish_date = get_post_meta($schedule_id, '_luwipress_schedule_date', true);
            if ($publish_date) {
                $post_data['post_date']     = $publish_date;
                $post_data['post_date_gmt'] = get_gmt_from_date($publish_date);
            }
        }

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

        // Set the post's language + link translation siblings if this row belongs to a translation group.
        $this->link_translation_group( $schedule_id );

        LuwiPress_Logger::log('Scheduled content published', 'info', array(
            'schedule_id' => $schedule_id,
            'post_id'     => $post_id,
            'title'       => $title,
        ));
    }

    /**
     * If a schedule row belongs to a translation group, set its post's language and link it
     * to the source row's post via the detected translation plugin (WPML/Polylang).
     * Safe to call repeatedly — calls are idempotent on both plugins.
     */
    private function link_translation_group( $schedule_id ) {
        $group = (string) get_post_meta( $schedule_id, '_luwipress_schedule_translation_group', true );
        $post_id = (int) get_post_meta( $schedule_id, '_luwipress_published_post_id', true );
        $language = (string) get_post_meta( $schedule_id, '_luwipress_schedule_language', true );

        if ( ! $post_id || ! $language ) return;
        if ( ! class_exists( 'LuwiPress_Plugin_Detector' ) ) return;

        $detector = LuwiPress_Plugin_Detector::get_instance();
        $t_info   = $detector->detect_translation();
        $plugin   = $t_info['plugin'] ?? 'none';
        if ( ! in_array( $plugin, array( 'wpml', 'polylang' ), true ) ) return;

        // Solo post (no translation group): just set its language so WPML/Polylang know what it is.
        if ( '' === $group ) {
            if ( 'polylang' === $plugin && function_exists( 'pll_set_post_language' ) ) {
                pll_set_post_language( $post_id, $language );
            } elseif ( 'wpml' === $plugin ) {
                $post_type = get_post_type( $post_id );
                do_action( 'wpml_set_element_language_details', array(
                    'element_id'    => $post_id,
                    'element_type'  => 'post_' . $post_type,
                    'trid'          => null,
                    'language_code' => $language,
                ) );
            }
            return;
        }

        // Multilingual group — find the source row in this group.
        $group_rows = get_posts( array(
            'post_type'      => self::POST_TYPE,
            'posts_per_page' => 20,
            'meta_query'     => array(
                array( 'key' => '_luwipress_schedule_translation_group', 'value' => $group ),
            ),
            'fields'         => 'ids',
        ) );

        if ( empty( $group_rows ) ) return;

        $source_id      = 0;
        $source_post_id = 0;
        foreach ( $group_rows as $row_id ) {
            if ( (int) get_post_meta( $row_id, '_luwipress_schedule_translation_source', true ) === 1 ) {
                $source_id      = (int) $row_id;
                $source_post_id = (int) get_post_meta( $row_id, '_luwipress_published_post_id', true );
                break;
            }
        }

        // Always set this post's language first (needed before WPML can link it).
        if ( 'polylang' === $plugin && function_exists( 'pll_set_post_language' ) ) {
            pll_set_post_language( $post_id, $language );
        } elseif ( 'wpml' === $plugin ) {
            $post_type = get_post_type( $post_id );
            do_action( 'wpml_set_element_language_details', array(
                'element_id'    => $post_id,
                'element_type'  => 'post_' . $post_type,
                'trid'          => null,
                'language_code' => $language,
            ) );
        }

        if ( ! $source_post_id ) {
            // Source's post not created yet — source will link when it publishes.
            return;
        }

        if ( $schedule_id === $source_id ) {
            // This IS the source — walk siblings and link any whose post exists.
            foreach ( $group_rows as $row_id ) {
                if ( (int) $row_id === (int) $source_id ) continue;
                $sib_post = (int) get_post_meta( $row_id, '_luwipress_published_post_id', true );
                $sib_lang = (string) get_post_meta( $row_id, '_luwipress_schedule_language', true );
                if ( ! $sib_post || ! $sib_lang ) continue;
                $detector->save_translation( $source_post_id, $sib_post, $sib_lang );
            }
            return;
        }

        // This is a sibling — link it to the source.
        $detector->save_translation( $source_post_id, $post_id, $language );
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
     * REST: Get schedule list (for automation clients)
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
