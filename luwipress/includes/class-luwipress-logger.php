<?php
/**
 * Logger class for LuwiPress plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class LuwiPress_Logger {

    /**
     * Create the logs database table.
     */
    public static function create_table() {
        global $wpdb;
        $table   = $wpdb->prefix . 'luwipress_logs';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            timestamp datetime NOT NULL,
            level varchar(10) NOT NULL DEFAULT 'info',
            message text NOT NULL,
            context longtext DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            user_agent varchar(255) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY level_idx (level),
            KEY timestamp_idx (timestamp)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Log a message to the wp_luwipress_logs table
     *
     * @param string $message
     * @param string $level   info|warning|error|debug
     * @param array  $context Additional context data
     */
    public static function log($message, $level = 'info', $context = array()) {
        if (!get_option('luwipress_enable_logging', 1)) {
            return;
        }

        $configured_level = get_option('luwipress_log_level', 'info');
        $levels = array('debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3);

        $min = isset($levels[$configured_level]) ? $levels[$configured_level] : 1;
        $cur = isset($levels[$level]) ? $levels[$level] : 1;

        if ($cur < $min) {
            return;
        }

        global $wpdb;

        $table = $wpdb->prefix . 'luwipress_logs';

        $wpdb->insert(
            $table,
            array(
                'timestamp'   => current_time('mysql'),
                'level'       => sanitize_text_field($level),
                'message'     => sanitize_text_field($message),
                'context'     => !empty($context) ? wp_json_encode($context) : null,
                'ip_address'  => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : null,
                'user_agent'  => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : null,
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s')
        );
    }

    /**
     * Get recent log entries
     *
     * @param int    $limit
     * @param string $level  Filter by level (empty = all)
     * @return array
     */
    public static function get_logs($limit = 100, $level = '') {
        global $wpdb;

        $table = $wpdb->prefix . 'luwipress_logs';

        if ($level) {
            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE level = %s ORDER BY timestamp DESC LIMIT %d",
                    $level,
                    $limit
                )
            );
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY timestamp DESC LIMIT %d",
                $limit
            )
        );
    }

    /**
     * Delete logs older than given days
     *
     * @param int $days
     */
    public static function cleanup($days = 30) {
        global $wpdb;

        $table = $wpdb->prefix . 'luwipress_logs';

        if ( 0 === $days ) {
            $wpdb->query( "TRUNCATE TABLE {$table}" );
        } else {
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$table} WHERE timestamp < DATE_SUB(NOW(), INTERVAL %d DAY)",
                    $days
                )
            );
        }
    }
}
