<?php
/**
 * n8nPress Workflow Execution Tracker
 *
 * Tracks workflow executions (outbound webhooks + inbound callbacks)
 * for dashboard statistics and reporting.
 *
 * @package n8nPress
 * @since 1.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class N8nPress_Workflow_Tracker {

    private static $instance = null;

    const TABLE_SUFFIX = 'n8npress_workflow_stats';

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Hook into REST API responses to track inbound calls
        add_filter('rest_pre_echo_response', array($this, 'track_inbound_request'), 10, 3);
    }

    /**
     * Create tracking table
     */
    public static function create_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_SUFFIX;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            direction varchar(10) NOT NULL DEFAULT 'outbound',
            workflow_type varchar(100) NOT NULL,
            action_name varchar(100) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'success',
            http_code int DEFAULT 0,
            execution_time_ms int DEFAULT 0,
            payload_size int DEFAULT 0,
            error_message text,
            PRIMARY KEY (id),
            KEY timestamp (timestamp),
            KEY workflow_type (workflow_type),
            KEY direction (direction),
            KEY status (status)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Track an outbound webhook execution
     */
    public static function track_outbound($workflow_type, $action, $status = 'success', $http_code = 0, $execution_time_ms = 0, $payload_size = 0, $error = '') {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_SUFFIX;

        $wpdb->insert($table, array(
            'timestamp'         => current_time('mysql'),
            'direction'         => 'outbound',
            'workflow_type'     => sanitize_text_field($workflow_type),
            'action_name'       => sanitize_text_field($action),
            'status'            => sanitize_text_field($status),
            'http_code'         => intval($http_code),
            'execution_time_ms' => intval($execution_time_ms),
            'payload_size'      => intval($payload_size),
            'error_message'     => $error ? sanitize_text_field($error) : null,
        ), array('%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s'));
    }

    /**
     * Track an inbound webhook/callback execution
     */
    public static function track_inbound($workflow_type, $action, $status = 'success', $execution_time_ms = 0, $error = '') {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_SUFFIX;

        $wpdb->insert($table, array(
            'timestamp'         => current_time('mysql'),
            'direction'         => 'inbound',
            'workflow_type'     => sanitize_text_field($workflow_type),
            'action_name'       => sanitize_text_field($action),
            'status'            => sanitize_text_field($status),
            'execution_time_ms' => intval($execution_time_ms),
            'error_message'     => $error ? sanitize_text_field($error) : null,
        ), array('%s', '%s', '%s', '%s', '%s', '%d', '%s'));
    }

    /**
     * Auto-track inbound n8npress REST API calls
     */
    public function track_inbound_request($result, $server, $request) {
        $route = $request->get_route();

        // Only track n8npress routes
        if (strpos($route, '/n8npress/v1/') === false) {
            return $result;
        }

        // Determine workflow type from route
        $workflow_type = 'core';
        if (strpos($route, '/translation/') !== false) $workflow_type = 'translation';
        elseif (strpos($route, '/schedule/') !== false) $workflow_type = 'content-scheduler';
        elseif (strpos($route, '/stock/') !== false) $workflow_type = 'stock';
        elseif (strpos($route, '/seo/') !== false) $workflow_type = 'seo';
        elseif (strpos($route, '/aeo/') !== false) $workflow_type = 'aeo';
        elseif (strpos($route, '/hub/') !== false) $workflow_type = 'multisite';
        elseif (strpos($route, '/product/') !== false) $workflow_type = 'product';
        elseif (strpos($route, '/content/') !== false) $workflow_type = 'content';
        elseif (strpos($route, '/customer/') !== false) $workflow_type = 'customer';

        $action = trim(str_replace('/n8npress/v1/', '', $route), '/');
        $status = is_wp_error($result) ? 'error' : 'success';

        self::track_inbound($workflow_type, $action, $status);

        return $result;
    }

    /**
     * Get statistics for a given period
     */
    public static function get_stats($days = 7) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;

        // Check if table exists
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(1) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
            DB_NAME,
            $table
        ));

        if (!$table_exists) {
            return self::empty_stats();
        }

        $since = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

        // Total counts
        $totals = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_count,
                SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as error_count,
                SUM(CASE WHEN direction = 'outbound' THEN 1 ELSE 0 END) as outbound_count,
                SUM(CASE WHEN direction = 'inbound' THEN 1 ELSE 0 END) as inbound_count,
                AVG(execution_time_ms) as avg_time_ms
            FROM $table
            WHERE timestamp >= %s",
            $since
        ));

        // By workflow type
        $by_type = $wpdb->get_results($wpdb->prepare(
            "SELECT workflow_type, COUNT(*) as count,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_count,
                SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as error_count
            FROM $table
            WHERE timestamp >= %s
            GROUP BY workflow_type
            ORDER BY count DESC",
            $since
        ));

        // Daily breakdown
        $daily = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(timestamp) as day, COUNT(*) as count,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_count,
                SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as error_count
            FROM $table
            WHERE timestamp >= %s
            GROUP BY DATE(timestamp)
            ORDER BY day ASC",
            $since
        ));

        // Recent errors
        $recent_errors = $wpdb->get_results($wpdb->prepare(
            "SELECT timestamp, workflow_type, action_name, error_message
            FROM $table
            WHERE status = 'error' AND timestamp >= %s
            ORDER BY timestamp DESC
            LIMIT 10",
            $since
        ));

        return array(
            'totals'        => $totals ?: (object) self::empty_stats()['totals'],
            'by_type'       => $by_type ?: array(),
            'daily'         => $daily ?: array(),
            'recent_errors' => $recent_errors ?: array(),
        );
    }

    /**
     * Empty stats structure
     */
    private static function empty_stats() {
        return array(
            'totals' => array(
                'total'          => 0,
                'success_count'  => 0,
                'error_count'    => 0,
                'outbound_count' => 0,
                'inbound_count'  => 0,
                'avg_time_ms'    => 0,
            ),
            'by_type'       => array(),
            'daily'         => array(),
            'recent_errors' => array(),
        );
    }

    /**
     * Cleanup old tracking data
     */
    public static function cleanup($days = 90) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;

        $wpdb->query($wpdb->prepare(
            "DELETE FROM $table WHERE timestamp < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
    }
}
