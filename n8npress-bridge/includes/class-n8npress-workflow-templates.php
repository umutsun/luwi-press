<?php
/**
 * n8nPress Workflow Templates
 *
 * Registry of all available n8n workflow templates that ship with the plugin.
 * Provides admin gallery UI, JSON export, and n8n API import capability.
 *
 * @package n8nPress
 * @since 1.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class N8nPress_Workflow_Templates {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'add_submenu'));
        add_action('wp_ajax_n8npress_download_workflow', array($this, 'ajax_download_workflow'));
    }

    /**
     * Add submenu under n8nPress
     */
    public function add_submenu() {
        add_submenu_page(
            'n8npress',
            __('Workflow Templates', 'n8npress'),
            __('Workflow Templates', 'n8npress'),
            'manage_options',
            'n8npress-workflows',
            array($this, 'render_page')
        );
    }

    /**
     * Render template gallery page
     */
    public function render_page() {
        include N8NPRESS_PLUGIN_DIR . 'admin/workflows-page.php';
    }

    /**
     * AJAX: Download a workflow JSON file
     */
    public function ajax_download_workflow() {
        check_ajax_referer('n8npress_workflows_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'n8npress'));
        }

        $template_id = sanitize_text_field($_POST['template_id'] ?? '');
        $templates = self::get_templates();

        if (!isset($templates[$template_id])) {
            wp_send_json_error(__('Template not found', 'n8npress'));
        }

        $file_path = $templates[$template_id]['file'];
        if (!file_exists($file_path)) {
            wp_send_json_error(__('Template file not found', 'n8npress'));
        }

        $content = file_get_contents($file_path);
        wp_send_json_success(array(
            'filename' => basename($file_path),
            'content'  => $content,
        ));
    }

    /**
     * Get all registered workflow templates with metadata
     */
    public static function get_templates() {
        $workflow_dir = N8NPRESS_PLUGIN_DIR . 'n8n-workflows';

        $templates = array(
            'product-enricher' => array(
                'name'        => __('Product Enricher', 'n8npress'),
                'description' => __('AI-powered product descriptions, meta titles, FAQ generation for WooCommerce products.', 'n8npress'),
                'category'    => 'content',
                'icon'        => 'dashicons-edit-large',
                'trigger'     => 'webhook',
                'services'    => array('AI', 'WooCommerce'),
                'file'        => $workflow_dir . '/workflow-product-enricher.json',
            ),
            'product-enricher-batch' => array(
                'name'        => __('Batch Product Enricher', 'n8npress'),
                'description' => __('Bulk AI enrichment for multiple products at once. Processes in batches of 3 with rate limiting.', 'n8npress'),
                'category'    => 'content',
                'icon'        => 'dashicons-screenoptions',
                'trigger'     => 'webhook',
                'services'    => array('AI', 'WooCommerce'),
                'file'        => $workflow_dir . '/workflow-product-enricher-batch.json',
            ),
            'content-scheduler' => array(
                'name'        => __('Content Scheduler', 'n8npress'),
                'description' => __('AI-generated blog posts with DALL-E images, scheduled publishing via WordPress.', 'n8npress'),
                'category'    => 'content',
                'icon'        => 'dashicons-calendar-alt',
                'trigger'     => 'webhook',
                'services'    => array('AI', 'DALL-E', 'WordPress'),
                'file'        => $workflow_dir . '/workflow-content-scheduler.json',
            ),
            'aeo-generator' => array(
                'name'        => __('AEO Content Generator', 'n8npress'),
                'description' => __('Daily scan for products missing FAQ/HowTo/Speakable schema, auto-generates via AI.', 'n8npress'),
                'category'    => 'seo',
                'icon'        => 'dashicons-microphone',
                'trigger'     => 'schedule',
                'services'    => array('AI', 'WordPress'),
                'file'        => $workflow_dir . '/workflow-aeo-generator.json',
            ),
            'internal-linker' => array(
                'name'        => __('AI Internal Link Resolver', 'n8npress'),
                'description' => __('Resolves [INTERNAL_LINK: topic] markers using AI. Matches to existing product/category/blog URLs.', 'n8npress'),
                'category'    => 'seo',
                'icon'        => 'dashicons-admin-links',
                'trigger'     => 'schedule',
                'services'    => array('AI', 'WordPress'),
                'file'        => $workflow_dir . '/workflow-internal-linker.json',
            ),
            'translation-pipeline' => array(
                'name'        => __('Translation Pipeline', 'n8npress'),
                'description' => __('SEO-aware AI translation with keyword density, meta length limits, brand name preservation.', 'n8npress'),
                'category'    => 'translation',
                'icon'        => 'dashicons-translation',
                'trigger'     => 'webhook',
                'services'    => array('AI', 'WordPress', 'WPML/Polylang'),
                'file'        => $workflow_dir . '/workflow-translation-pipeline.json',
            ),
            'ai-review-responder' => array(
                'name'        => __('AI Review Responder', 'n8npress'),
                'description' => __('Auto-respond to product reviews with sentiment analysis. 4-5 star auto-publish, 1-3 star approval queue.', 'n8npress'),
                'category'    => 'woocommerce',
                'icon'        => 'dashicons-star-filled',
                'trigger'     => 'schedule',
                'services'    => array('AI', 'WooCommerce'),
                'file'        => $workflow_dir . '/workflow-ai-review-responder.json',
            ),
            'crm-lifecycle' => array(
                'name'        => __('CRM Lifecycle Automation', 'n8npress'),
                'description' => __('Customer lifecycle emails: post-purchase thank you, review requests (7 days), win-back for at-risk customers.', 'n8npress'),
                'category'    => 'woocommerce',
                'icon'        => 'dashicons-email-alt',
                'trigger'     => 'schedule',
                'services'    => array('WooCommerce', 'Email'),
                'file'        => $workflow_dir . '/workflow-crm-lifecycle.json',
            ),
            'open-claw' => array(
                'name'        => __('Open Claw AI Assistant', 'n8npress'),
                'description' => __('AI-powered store management via admin panel, Telegram, or WhatsApp. Processes complex queries with your selected AI model.', 'n8npress'),
                'category'    => 'integration',
                'icon'        => 'dashicons-superhero-alt',
                'trigger'     => 'webhook',
                'services'    => array('AI', 'WordPress', 'Telegram', 'WhatsApp'),
                'file'        => $workflow_dir . '/workflow-open-claw.json',
            ),
        );

        // Filter out templates whose files don't exist
        return array_filter($templates, function($t) {
            return file_exists($t['file']);
        });
    }

    /**
     * Get templates grouped by category
     */
    public static function get_templates_by_category() {
        $templates = self::get_templates();
        $grouped = array();

        foreach ($templates as $id => $template) {
            $cat = $template['category'];
            if (!isset($grouped[$cat])) {
                $grouped[$cat] = array();
            }
            $grouped[$cat][$id] = $template;
        }

        return $grouped;
    }

    /**
     * Category labels
     */
    public static function get_category_labels() {
        return array(
            'content'     => __('Content & AI', 'n8npress'),
            'seo'         => __('SEO & AEO', 'n8npress'),
            'translation' => __('Translation', 'n8npress'),
            'woocommerce' => __('WooCommerce', 'n8npress'),
            'integration' => __('Integrations', 'n8npress'),
        );
    }
}
