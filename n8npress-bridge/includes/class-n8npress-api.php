<?php
/**
 * n8nPress API Handler
 */

if (!defined('ABSPATH')) {
    exit;
}

class N8nPress_API {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('rest_api_init', array($this, 'register_endpoints'));
    }
    
    public function register_endpoints() {
        // Webhook endpoint
        register_rest_route('n8npress/v1', '/webhook', array(
            'methods' => array('POST', 'GET'),
            'callback' => array($this, 'handle_webhook'),
            'permission_callback' => array($this, 'check_webhook_permission'),
            'args' => $this->get_webhook_args()
        ));
        
        // Workflow result callback — n8n sends success/error feedback here
        register_rest_route( 'n8npress/v1', '/workflow/result', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_workflow_result' ),
            'permission_callback' => array( $this, 'check_api_token_permission' ),
        ) );

        // Token usage stats
        register_rest_route( 'n8npress/v1', '/token-usage', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'handle_get_token_usage' ),
            'permission_callback' => array( $this, 'check_api_token_permission' ),
        ) );

        // Token limit check — n8n calls this before making AI calls
        register_rest_route( 'n8npress/v1', '/token-usage/check', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'handle_check_limit' ),
            'permission_callback' => array( $this, 'check_api_token_permission' ),
        ) );

        // Token usage report — n8n POSTs consumption data here after each AI call
        register_rest_route( 'n8npress/v1', '/token-usage', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_token_usage_report' ),
            'permission_callback' => array( $this, 'check_api_token_permission' ),
        ) );

        // Token stats for admin dashboard (30-day summary + workflow breakdown)
        register_rest_route( 'n8npress/v1', '/token-stats', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'handle_get_token_stats' ),
            'permission_callback' => array( $this, 'check_admin_permission' ),
        ) );

        // Recent token calls for admin dashboard table
        register_rest_route( 'n8npress/v1', '/token-recent', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'handle_get_token_recent' ),
            'permission_callback' => array( $this, 'check_admin_permission' ),
        ) );

        // Status endpoint
        register_rest_route('n8npress/v1', '/status', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_status'),
            'permission_callback' => '__return_true'
        ));
        
        // Health check endpoint
        register_rest_route('n8npress/v1', '/health', array(
            'methods' => 'GET',
            'callback' => array($this, 'health_check'),
            'permission_callback' => '__return_true'
        ));
    }
    
    private function get_webhook_args() {
        return array(
            'action' => array(
                'required' => true,
                'type' => 'string',
                'enum' => array('create_post', 'update_post', 'delete_post', 'get_posts'),
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => array($this, 'validate_action')
            ),
            'auth_token' => array(
                'required' => true,
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field'
            ),
            'title' => array(
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field'
            ),
            'content' => array(
                'type' => 'string',
                'sanitize_callback' => 'wp_kses_post'
            ),
            'status' => array(
                'type' => 'string',
                'enum' => array('draft', 'publish', 'private', 'pending'),
                'sanitize_callback' => 'sanitize_text_field'
            )
        );
    }
    
    public function validate_action($value, $request, $param) {
        $allowed_actions = array('create_post', 'update_post', 'delete_post', 'get_posts');
        return in_array($value, $allowed_actions);
    }
    
    public function check_webhook_permission($request) {
        // Rate limiting check
        if (!$this->check_rate_limit()) {
            return new WP_Error('rate_limit', 'Rate limit exceeded', array('status' => 429));
        }
        
        // IP whitelist check (if configured)
        if (!$this->check_ip_whitelist()) {
            return new WP_Error('ip_blocked', 'IP address not allowed', array('status' => 403));
        }
        
        return true;
    }
    
    private function check_rate_limit() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $limit = n8npress_get_option('rate_limit', 1000);
        
        $transient_key = 'n8npress_rate_limit_' . md5($ip);
        $requests = get_transient($transient_key);
        
        if ($requests === false) {
            set_transient($transient_key, 1, HOUR_IN_SECONDS);
            return true;
        }
        
        if ($requests >= $limit) {
            N8nPress_Logger::log("Rate limit exceeded for IP: $ip", 'warning');
            return false;
        }
        
        set_transient($transient_key, $requests + 1, HOUR_IN_SECONDS);
        return true;
    }
    
    private function check_ip_whitelist() {
        $whitelist = n8npress_get_option('ip_whitelist', '');
        
        if (empty($whitelist)) {
            return true; // No whitelist configured
        }
        
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $allowed_ips = array_map('trim', explode(',', $whitelist));
        
        return in_array($ip, $allowed_ips);
    }
    
    public function handle_webhook($request) {
        $start_time = microtime(true);
        
        try {
            // Get request data
            $data = $request->get_json_params();
            if (empty($data)) {
                $data = $request->get_params();
            }
            
            // Add request metadata
            $data['request_id'] = uniqid('req_');
            $data['timestamp'] = current_time('mysql');
            $data['ip'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $data['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            
            // Log request
            N8nPress_Logger::log('Webhook request received', 'info', array(
                'action' => $data['action'] ?? 'unknown',
                'request_id' => $data['request_id'],
                'ip' => $data['ip']
            ));
            
            // Validate and authenticate
            $user = $this->authenticate_request($data);
            if (is_wp_error($user)) {
                return $user;
            }
            
            // Process the request
            $result = $this->process_webhook_action($data, $user);
            
            // Calculate execution time
            $execution_time = round((microtime(true) - $start_time) * 1000, 2);
            
            // Log success
            N8nPress_Logger::log('Webhook processed successfully', 'info', array(
                'action' => $data['action'],
                'request_id' => $data['request_id'],
                'execution_time' => $execution_time
            ));
            
            return array(
                'success' => true,
                'data' => $result,
                'meta' => array(
                    'request_id' => $data['request_id'],
                    'timestamp' => $data['timestamp'],
                    'execution_time_ms' => $execution_time,
                    'version' => N8NPRESS_VERSION
                )
            );
            
        } catch (Exception $e) {
            $execution_time = round((microtime(true) - $start_time) * 1000, 2);
            
            N8nPress_Logger::log('Webhook error: ' . $e->getMessage(), 'error', array(
                'request_id' => $data['request_id'] ?? 'unknown',
                'execution_time' => $execution_time,
                'trace' => $e->getTraceAsString()
            ));
            
            return new WP_Error(
                'webhook_error',
                $e->getMessage(),
                array(
                    'status' => 500,
                    'request_id' => $data['request_id'] ?? 'unknown'
                )
            );
        }
    }
    
    private function authenticate_request($data) {
        if (empty($data['auth_token'])) {
            throw new Exception('Authentication token is required');
        }
        
        return N8nPress_Auth::validate_token($data['auth_token']);
    }
    
    private function process_webhook_action($data, $user) {
        switch ($data['action']) {
            case 'create_post':
                return $this->create_post($data, $user);
                
            case 'update_post':
                return $this->update_post($data, $user);
                
            case 'delete_post':
                return $this->delete_post($data, $user);
                
            case 'get_posts':
                return $this->get_posts($data, $user);
                
            default:
                throw new Exception('Unsupported action: ' . $data['action']);
        }
    }
    
    private function create_post($data, $user) {
        // Validate required fields
        if (empty($data['title'])) {
            throw new Exception('Post title is required');
        }
        
        // Prepare post data
        $post_data = array(
            'post_title' => $data['title'],
            'post_content' => $data['content'] ?? '',
            'post_excerpt' => $data['excerpt'] ?? '',
            'post_status' => $data['status'] ?? 'draft',
            'post_type' => $data['post_type'] ?? 'post',
            'post_author' => $user->ID,
            'post_date' => $data['post_date'] ?? current_time('mysql'),
            'meta_input' => array()
        );
        
        // Add categories
        if (!empty($data['categories'])) {
            if (is_string($data['categories'])) {
                $data['categories'] = explode(',', $data['categories']);
            }
            $post_data['post_category'] = array_map('intval', $data['categories']);
        }
        
        // Add custom fields
        if (!empty($data['meta']) && is_array($data['meta'])) {
            foreach ($data['meta'] as $key => $value) {
                $post_data['meta_input'][sanitize_key($key)] = sanitize_text_field($value);
            }
        }
        
        // Add request metadata
        $post_data['meta_input']['_n8npress_request_id'] = $data['request_id'];
        $post_data['meta_input']['_n8npress_created_via'] = 'webhook';
        
        // Create the post
        $post_id = wp_insert_post($post_data, true);
        
        if (is_wp_error($post_id)) {
            throw new Exception('Failed to create post: ' . $post_id->get_error_message());
        }
        
        // Handle tags
        if (!empty($data['tags'])) {
            if (is_string($data['tags'])) {
                $data['tags'] = explode(',', $data['tags']);
            }
            wp_set_post_tags($post_id, $data['tags']);
        }
        
        // Set featured image
        if (!empty($data['featured_media'])) {
            set_post_thumbnail($post_id, intval($data['featured_media']));
        }
        
        // Get the created post
        $post = get_post($post_id);
        
        return array(
            'id' => $post_id,
            'title' => $post->post_title,
            'url' => get_permalink($post_id),
            'status' => $post->post_status,
            'date_created' => $post->post_date,
            'edit_url' => get_edit_post_link($post_id, 'raw')
        );
    }
    
    private function update_post($data, $user) {
        if (empty($data['id'])) {
            throw new Exception('Post ID is required for update');
        }
        
        $post_id = intval($data['id']);
        $post = get_post($post_id);
        
        if (!$post) {
            throw new Exception('Post not found');
        }
        
        // Check permissions
        if (!current_user_can('edit_post', $post_id)) {
            throw new Exception('Insufficient permissions to edit this post');
        }
        
        // Prepare update data
        $update_data = array('ID' => $post_id);
        
        // Update fields if provided
        if (isset($data['title'])) {
            $update_data['post_title'] = $data['title'];
        }
        
        if (isset($data['content'])) {
            $update_data['post_content'] = $data['content'];
        }
        
        if (isset($data['excerpt'])) {
            $update_data['post_excerpt'] = $data['excerpt'];
        }
        
        if (isset($data['status'])) {
            $update_data['post_status'] = $data['status'];
        }
        
        // Update the post
        $result = wp_update_post($update_data, true);
        
        if (is_wp_error($result)) {
            throw new Exception('Failed to update post: ' . $result->get_error_message());
        }
        
        // Update metadata
        update_post_meta($post_id, '_n8npress_last_updated_via', 'webhook');
        update_post_meta($post_id, '_n8npress_last_request_id', $data['request_id']);
        
        return array(
            'id' => $post_id,
            'updated' => true,
            'url' => get_permalink($post_id),
            'edit_url' => get_edit_post_link($post_id, 'raw')
        );
    }
    
    private function delete_post($data, $user) {
        if (empty($data['id'])) {
            throw new Exception('Post ID is required for deletion');
        }
        
        $post_id = intval($data['id']);
        $post = get_post($post_id);
        
        if (!$post) {
            throw new Exception('Post not found');
        }
        
        // Check permissions
        if (!current_user_can('delete_post', $post_id)) {
            throw new Exception('Insufficient permissions to delete this post');
        }
        
        // Determine if we should force delete or move to trash
        $force_delete = isset($data['force_delete']) && $data['force_delete'];
        
        $result = wp_delete_post($post_id, $force_delete);
        
        if (!$result) {
            throw new Exception('Failed to delete post');
        }
        
        return array(
            'id' => $post_id,
            'deleted' => true,
            'force_deleted' => $force_delete
        );
    }
    
    private function get_posts($data, $user) {
        // Default query parameters
        $args = array(
            'post_type' => $data['post_type'] ?? 'post',
            'post_status' => $data['status'] ?? 'any',
            'posts_per_page' => min(intval($data['per_page'] ?? 10), 100),
            'paged' => intval($data['page'] ?? 1),
            'meta_query' => array()
        );
        
        // Filter by author if specified
        if (!empty($data['author'])) {
            $args['author'] = intval($data['author']);
        }
        
        // Filter by date range
        if (!empty($data['date_after']) || !empty($data['date_before'])) {
            $args['date_query'] = array();
            
            if (!empty($data['date_after'])) {
                $args['date_query']['after'] = $data['date_after'];
            }
            
            if (!empty($data['date_before'])) {
                $args['date_query']['before'] = $data['date_before'];
            }
        }
        
        // Search by title/content
        if (!empty($data['search'])) {
            $args['s'] = $data['search'];
        }
        
        // Execute query
        $query = new WP_Query($args);
        $posts = array();
        
        foreach ($query->posts as $post) {
            $posts[] = array(
                'id' => $post->ID,
                'title' => $post->post_title,
                'content' => $post->post_content,
                'excerpt' => $post->post_excerpt,
                'status' => $post->post_status,
                'date' => $post->post_date,
                'url' => get_permalink($post->ID),
                'edit_url' => get_edit_post_link($post->ID, 'raw'),
                'author' => get_the_author_meta('display_name', $post->post_author)
            );
        }
        
        return array(
            'posts' => $posts,
            'pagination' => array(
                'total' => $query->found_posts,
                'per_page' => $args['posts_per_page'],
                'current_page' => $args['paged'],
                'total_pages' => $query->max_num_pages
            )
        );
    }
    
    public function get_status() {
        global $wpdb;
        
        return array(
            'plugin' => 'n8nPress',
            'version' => N8NPRESS_VERSION,
            'status' => 'active',
            'endpoints' => array(
                'webhook' => rest_url('n8npress/v1/webhook'),
                'status' => rest_url('n8npress/v1/status'),
                'health' => rest_url('n8npress/v1/health')
            ),
            'wordpress' => array(
                'version' => get_bloginfo('version'),
                'multisite' => is_multisite(),
                'debug' => defined('WP_DEBUG') && WP_DEBUG
            ),
            'server' => array(
                'php_version' => PHP_VERSION,
                'mysql_version' => $wpdb->db_version(),
                'max_execution_time' => ini_get('max_execution_time'),
                'memory_limit' => ini_get('memory_limit')
            ),
            'features' => array(
                'rest_api' => true,
                'jwt_enabled' => function_exists('firebase\jwt\JWT::decode'),
                'logging_enabled' => n8npress_get_option('enable_logging', 1),
                'rate_limiting' => n8npress_get_option('rate_limit', 1000) > 0
            ),
            'timestamp' => current_time('mysql')
        );
    }
    
    public function health_check() {
        $health = array(
            'status' => 'healthy',
            'checks' => array()
        );
        
        // Check database connection
        try {
            global $wpdb;
            $wpdb->get_var("SELECT 1");
            $health['checks']['database'] = 'ok';
        } catch (Exception $e) {
            $health['checks']['database'] = 'error';
            $health['status'] = 'unhealthy';
        }
        
        // Check file system
        $upload_dir = wp_upload_dir();
        if (wp_is_writable($upload_dir['basedir'])) {
            $health['checks']['filesystem'] = 'ok';
        } else {
            $health['checks']['filesystem'] = 'warning';
        }
        
        // Check memory usage
        $memory_limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        $memory_usage = memory_get_usage(true);
        $memory_percent = ($memory_usage / $memory_limit) * 100;
        
        if ($memory_percent < 80) {
            $health['checks']['memory'] = 'ok';
        } elseif ($memory_percent < 95) {
            $health['checks']['memory'] = 'warning';
        } else {
            $health['checks']['memory'] = 'critical';
            $health['status'] = 'unhealthy';
        }
        
        $health['memory_usage'] = array(
            'used' => size_format($memory_usage),
            'limit' => size_format($memory_limit),
            'percent' => round($memory_percent, 2)
        );
        
        return $health;
    }

    /**
     * Permission check: API token via Authorization header or X-n8nPress-Token
     */
    public function check_api_token_permission( $request ) {
        $stored = get_option( 'n8npress_seo_api_token', '' );
        if ( empty( $stored ) ) {
            return false;
        }

        $auth = $request->get_header( 'authorization' );
        if ( ! empty( $auth ) ) {
            $token = str_replace( 'Bearer ', '', $auth );
            if ( hash_equals( $stored, $token ) ) {
                return true;
            }
        }

        $custom = $request->get_header( 'x-n8npress-token' );
        if ( ! empty( $custom ) && hash_equals( $stored, $custom ) ) {
            return true;
        }

        return false;
    }

    /**
     * POST /workflow/result — Receives workflow execution feedback from n8n
     *
     * Expected payload:
     * {
     *   "workflow": "translation-pipeline",
     *   "status": "success" | "error",
     *   "message": "Translated 5 products to French",
     *   "details": { ... optional context ... },
     *   "execution_id": "123"
     * }
     */
    public function handle_workflow_result( $request ) {
        $data = $request->get_json_params();

        $workflow     = sanitize_text_field( $data['workflow'] ?? 'unknown' );
        $status       = sanitize_text_field( $data['status'] ?? 'info' );
        $message      = sanitize_text_field( $data['message'] ?? '' );
        $details      = $data['details'] ?? array();
        $execution_id = sanitize_text_field( $data['execution_id'] ?? '' );

        // Token usage tracking
        $token_data = $data['token_usage'] ?? array();
        if ( ! empty( $token_data ) && class_exists( 'N8nPress_Token_Tracker' ) ) {
            N8nPress_Token_Tracker::record( array(
                'workflow'      => $workflow,
                'provider'      => sanitize_text_field( $token_data['provider'] ?? 'openai' ),
                'model'         => sanitize_text_field( $token_data['model'] ?? 'gpt-4o-mini' ),
                'input_tokens'  => absint( $token_data['input_tokens'] ?? 0 ),
                'output_tokens' => absint( $token_data['output_tokens'] ?? 0 ),
                'execution_id'  => $execution_id,
            ) );
        }

        if ( empty( $message ) ) {
            return new WP_Error( 'missing_message', 'message is required', array( 'status' => 400 ) );
        }

        // Map status to log level
        $level = 'info';
        if ( 'error' === $status || 'failed' === $status ) {
            $level = 'error';
        } elseif ( 'warning' === $status ) {
            $level = 'warning';
        }

        // Build log message with cost info
        $cost_info = '';
        if ( ! empty( $token_data['input_tokens'] ) ) {
            $total_tokens = absint( $token_data['input_tokens'] ) + absint( $token_data['output_tokens'] ?? 0 );
            $cost_info = sprintf( ' [%s tokens]', number_format( $total_tokens ) );
        }

        N8nPress_Logger::log(
            sprintf( 'Workflow [%s]: %s%s', $workflow, $message, $cost_info ),
            $level,
            array(
                'workflow'     => $workflow,
                'status'       => $status,
                'execution_id' => $execution_id,
                'details'      => $details,
                'token_usage'  => $token_data,
            )
        );

        return rest_ensure_response( array(
            'success' => true,
            'logged'  => true,
        ) );
    }

    /**
     * GET /token-usage — Returns token usage stats for dashboard
     */
    public function handle_get_token_usage( $request ) {
        if ( ! class_exists( 'N8nPress_Token_Tracker' ) ) {
            return new WP_Error( 'not_available', 'Token tracking not available', array( 'status' => 500 ) );
        }

        $days = absint( $request->get_param( 'days' ) ?: 30 );
        return rest_ensure_response( N8nPress_Token_Tracker::get_stats( $days ) );
    }

    /**
     * GET /token-usage/check — Check if daily limit allows more API calls
     */
    public function handle_check_limit( $request ) {
        $exceeded = class_exists( 'N8nPress_Token_Tracker' ) && N8nPress_Token_Tracker::is_limit_exceeded();
        $today    = class_exists( 'N8nPress_Token_Tracker' ) ? N8nPress_Token_Tracker::get_today_cost() : 0;
        $limit    = floatval( get_option( 'n8npress_daily_token_limit', 0 ) );

        return rest_ensure_response( array(
            'allowed'    => ! $exceeded,
            'today_cost' => round( $today, 4 ),
            'limit'      => $limit,
            'remaining'  => $limit > 0 ? max( 0, round( $limit - $today, 4 ) ) : null,
        ) );
    }

    /**
     * POST /token-usage — Receive token consumption report from n8n workflow
     */
    public function handle_token_usage_report( $request ) {
        if ( ! class_exists( 'N8nPress_Token_Tracker' ) ) {
            return new WP_Error( 'not_available', 'Token tracking not available', array( 'status' => 500 ) );
        }

        $data = $request->get_json_params();
        $cost = N8nPress_Token_Tracker::record( array(
            'workflow'      => sanitize_text_field( $data['workflow'] ?? 'unknown' ),
            'provider'      => sanitize_text_field( $data['provider'] ?? 'anthropic' ),
            'model'         => sanitize_text_field( $data['model'] ?? 'claude-sonnet-4-20250514' ),
            'input_tokens'  => absint( $data['input_tokens'] ?? 0 ),
            'output_tokens' => absint( $data['output_tokens'] ?? 0 ),
            'execution_id'  => sanitize_text_field( $data['execution_id'] ?? '' ),
        ) );

        return rest_ensure_response( array( 'success' => true, 'estimated_cost' => $cost ) );
    }

    /**
     * GET /token-stats — 30-day usage stats for admin dashboard
     */
    public function handle_get_token_stats( $request ) {
        if ( ! class_exists( 'N8nPress_Token_Tracker' ) ) {
            return new WP_Error( 'not_available', 'Token tracking not available', array( 'status' => 500 ) );
        }
        return rest_ensure_response( N8nPress_Token_Tracker::get_stats( 30 ) );
    }

    /**
     * GET /token-recent — Recent call records for admin dashboard table
     */
    public function handle_get_token_recent( $request ) {
        if ( ! class_exists( 'N8nPress_Token_Tracker' ) ) {
            return new WP_Error( 'not_available', 'Token tracking not available', array( 'status' => 500 ) );
        }
        return rest_ensure_response( N8nPress_Token_Tracker::get_recent_calls( 20 ) );
    }

    /**
     * Permission check: WordPress admin (manage_options)
     */
    public function check_admin_permission() {
        return current_user_can( 'manage_options' );
    }
}