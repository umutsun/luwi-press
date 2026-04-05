<?php
/**
 * Settings class for n8nPress plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class N8nPress_Settings {

    private static $instance = null;

    private static $defaults = array(
        'enable_logging'   => 1,
        'log_level'        => 'info',
        'rate_limit'       => 1000,
        'security_headers' => 1,
        'webhook_timeout'  => 30,
        'ip_whitelist'     => '',
        'jwt_secret'       => '',
    );

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_init', array($this, 'register_settings'));
    }

    /**
     * Get a setting value
     *
     * @param string $key     Setting key without the n8npress_ prefix
     * @param mixed  $default
     * @return mixed
     */
    public static function get($key, $default = null) {
        if (null === $default && isset(self::$defaults[$key])) {
            $default = self::$defaults[$key];
        }
        return get_option('n8npress_' . $key, $default);
    }

    /**
     * Update a setting value
     *
     * @param string $key
     * @param mixed  $value
     */
    public static function set($key, $value) {
        update_option('n8npress_' . $key, $value);
    }

    /**
     * Register settings with WordPress Settings API
     */
    public function register_settings() {
        foreach (self::$defaults as $key => $default) {
            register_setting('n8npress_settings', 'n8npress_' . $key);
        }

        add_settings_section(
            'n8npress_general',
            __('General Settings', 'n8npress'),
            null,
            'n8npress'
        );

        add_settings_field(
            'n8npress_enable_logging',
            __('Enable Logging', 'n8npress'),
            array($this, 'checkbox_field'),
            'n8npress',
            'n8npress_general',
            array('name' => 'n8npress_enable_logging')
        );

        add_settings_field(
            'n8npress_log_level',
            __('Log Level', 'n8npress'),
            array($this, 'select_field'),
            'n8npress',
            'n8npress_general',
            array(
                'name'    => 'n8npress_log_level',
                'options' => array('debug' => 'Debug', 'info' => 'Info', 'warning' => 'Warning', 'error' => 'Error'),
            )
        );

        add_settings_field(
            'n8npress_rate_limit',
            __('Rate Limit (requests/hour)', 'n8npress'),
            array($this, 'number_field'),
            'n8npress',
            'n8npress_general',
            array('name' => 'n8npress_rate_limit')
        );

        add_settings_field(
            'n8npress_ip_whitelist',
            __('IP Whitelist (comma-separated)', 'n8npress'),
            array($this, 'text_field'),
            'n8npress',
            'n8npress_general',
            array('name' => 'n8npress_ip_whitelist')
        );

        add_settings_field(
            'n8npress_webhook_timeout',
            __('Webhook Timeout (seconds)', 'n8npress'),
            array($this, 'number_field'),
            'n8npress',
            'n8npress_general',
            array('name' => 'n8npress_webhook_timeout')
        );
    }

    public function checkbox_field($args) {
        $value = get_option($args['name'], 1);
        echo '<input type="checkbox" name="' . esc_attr($args['name']) . '" value="1"' . checked(1, $value, false) . '>';
    }

    public function select_field($args) {
        $value = get_option($args['name'], '');
        echo '<select name="' . esc_attr($args['name']) . '">';
        foreach ($args['options'] as $k => $label) {
            echo '<option value="' . esc_attr($k) . '"' . selected($k, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    public function number_field($args) {
        $value = get_option($args['name'], '');
        echo '<input type="number" name="' . esc_attr($args['name']) . '" value="' . esc_attr($value) . '" class="small-text">';
    }

    public function text_field($args) {
        $value = get_option($args['name'], '');
        echo '<input type="text" name="' . esc_attr($args['name']) . '" value="' . esc_attr($value) . '" class="regular-text">';
    }
}
