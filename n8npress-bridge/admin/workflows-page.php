<?php
/**
 * n8nPress Workflow Template Gallery
 */

if (!defined('ABSPATH')) {
    exit;
}

$grouped = N8nPress_Workflow_Templates::get_templates_by_category();
$category_labels = N8nPress_Workflow_Templates::get_category_labels();
$all_templates = N8nPress_Workflow_Templates::get_templates();
$total = count($all_templates);

$category_icons = array(
    'content'     => 'dashicons-edit-large',
    'seo'         => 'dashicons-search',
    'translation' => 'dashicons-translation',
    'woocommerce' => 'dashicons-cart',
    'integration' => 'dashicons-admin-links',
);
?>

<div class="wrap n8npress-dashboard">
    <h1>
        <span class="dashicons dashicons-database-export"></span>
        <?php _e('Workflow Templates', 'n8npress'); ?>
        <span class="n8npress-count-badge"><?php echo intval($total); ?></span>
    </h1>

    <p class="n8npress-subtitle">
        <?php _e('Ready-to-use n8n workflow templates. Download the JSON and import into your n8n instance.', 'n8npress'); ?>
    </p>

    <!-- Category Filter -->
    <div class="n8npress-filter-bar">
        <div class="n8npress-filter-levels">
            <a href="#" class="n8npress-filter-badge badge-active-filter" data-filter="all">
                <?php _e('All', 'n8npress'); ?> <span class="count">(<?php echo $total; ?>)</span>
            </a>
            <?php foreach ($category_labels as $cat_key => $cat_label) :
                $count = isset($grouped[$cat_key]) ? count($grouped[$cat_key]) : 0;
                if ($count === 0) continue;
            ?>
            <a href="#" class="n8npress-filter-badge" data-filter="<?php echo esc_attr($cat_key); ?>">
                <span class="dashicons <?php echo esc_attr($category_icons[$cat_key] ?? 'dashicons-admin-generic'); ?>"></span>
                <?php echo esc_html($cat_label); ?> <span class="count">(<?php echo $count; ?>)</span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Template Grid -->
    <div class="n8npress-workflow-grid">
        <?php foreach ($all_templates as $template_id => $template) : ?>
        <div class="n8npress-workflow-card" data-category="<?php echo esc_attr($template['category']); ?>">
            <div class="workflow-card-header">
                <span class="dashicons <?php echo esc_attr($template['icon']); ?> workflow-icon"></span>
                <div class="workflow-card-title">
                    <h3><?php echo esc_html($template['name']); ?></h3>
                    <span class="workflow-trigger-badge trigger-<?php echo esc_attr($template['trigger']); ?>">
                        <?php echo $template['trigger'] === 'webhook' ? '⚡ Webhook' : '⏰ Schedule'; ?>
                    </span>
                </div>
            </div>

            <p class="workflow-description"><?php echo esc_html($template['description']); ?></p>

            <div class="workflow-services">
                <?php foreach ($template['services'] as $service) : ?>
                <span class="service-tag"><?php echo esc_html($service); ?></span>
                <?php endforeach; ?>
            </div>

            <div class="workflow-card-actions">
                <button type="button" class="button button-primary n8npress-download-workflow" data-template="<?php echo esc_attr($template_id); ?>">
                    <span class="dashicons dashicons-download"></span> <?php _e('Download JSON', 'n8npress'); ?>
                </button>
                <button type="button" class="button n8npress-preview-workflow" data-template="<?php echo esc_attr($template_id); ?>">
                    <span class="dashicons dashicons-visibility"></span> <?php _e('Preview', 'n8npress'); ?>
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- How to Import -->
    <div class="n8npress-section" style="margin-top: 24px;">
        <h2><?php _e('How to Import Workflows', 'n8npress'); ?></h2>
        <div class="n8npress-import-steps">
            <div class="import-step">
                <span class="step-number">1</span>
                <div>
                    <strong><?php _e('Download', 'n8npress'); ?></strong>
                    <p><?php _e('Click "Download JSON" on the workflow template you want to use.', 'n8npress'); ?></p>
                </div>
            </div>
            <div class="import-step">
                <span class="step-number">2</span>
                <div>
                    <strong><?php _e('Import to n8n', 'n8npress'); ?></strong>
                    <p><?php _e('In your n8n dashboard, click the menu icon and select "Import from File".', 'n8npress'); ?></p>
                </div>
            </div>
            <div class="import-step">
                <span class="step-number">3</span>
                <div>
                    <strong><?php _e('Configure Credentials', 'n8npress'); ?></strong>
                    <p><?php _e('Set up the required credentials (API keys, WordPress auth) in each workflow node.', 'n8npress'); ?></p>
                </div>
            </div>
            <div class="import-step">
                <span class="step-number">4</span>
                <div>
                    <strong><?php _e('Set Environment Variables', 'n8npress'); ?></strong>
                    <p><?php _e('Add $env variables: N8NPRESS_API_TOKEN, SITE_URL, OPENAI_API_KEY (if using AI).', 'n8npress'); ?></p>
                </div>
            </div>
            <div class="import-step">
                <span class="step-number">5</span>
                <div>
                    <strong><?php _e('Activate', 'n8npress'); ?></strong>
                    <p><?php _e('Toggle the workflow to active. Scheduled workflows will run automatically.', 'n8npress'); ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Preview Modal -->
    <div id="n8npress-workflow-modal" class="n8npress-modal" style="display:none;">
        <div class="n8npress-modal-content" style="max-width: 800px;">
            <div class="n8npress-modal-header">
                <h3 id="workflow-modal-title"></h3>
                <button type="button" class="n8npress-modal-close">&times;</button>
            </div>
            <pre id="workflow-modal-json" class="n8npress-json-view"></pre>
        </div>
    </div>
</div>

<?php wp_nonce_field('n8npress_workflows_nonce', '_n8npress_wf_nonce'); ?>

<style>
.n8npress-subtitle {
    font-size: 14px;
    color: #6b7280;
    margin-bottom: 20px;
}
.n8npress-count-badge {
    display: inline-block;
    background: #6366f1;
    color: #fff;
    font-size: 13px;
    font-weight: 600;
    padding: 2px 10px;
    border-radius: 12px;
    vertical-align: middle;
    margin-left: 8px;
}

/* Workflow Grid */
.n8npress-workflow-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
    gap: 16px;
    margin-top: 16px;
}
.n8npress-workflow-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 20px;
    transition: border-color 0.2s, box-shadow 0.2s;
    display: flex;
    flex-direction: column;
}
.n8npress-workflow-card:hover {
    border-color: #6366f1;
    box-shadow: 0 2px 8px rgba(99,102,241,0.12);
}
.n8npress-workflow-card.hidden { display: none; }

.workflow-card-header {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 12px;
}
.workflow-icon {
    font-size: 24px;
    width: 24px;
    height: 24px;
    color: #6366f1;
    margin-top: 2px;
}
.workflow-card-title {
    flex: 1;
}
.workflow-card-title h3 {
    margin: 0 0 4px 0;
    font-size: 15px;
    font-weight: 600;
}
.workflow-trigger-badge {
    display: inline-block;
    font-size: 11px;
    font-weight: 500;
    padding: 1px 8px;
    border-radius: 10px;
}
.trigger-webhook { background: #dbeafe; color: #1d4ed8; }
.trigger-schedule { background: #fef3c7; color: #b45309; }

.workflow-description {
    font-size: 13px;
    color: #6b7280;
    line-height: 1.5;
    flex: 1;
    margin: 0 0 12px 0;
}

.workflow-services {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    margin-bottom: 16px;
}
.service-tag {
    display: inline-block;
    background: #f3f4f6;
    color: #374151;
    font-size: 11px;
    padding: 2px 8px;
    border-radius: 3px;
}

.workflow-card-actions {
    display: flex;
    gap: 8px;
}
.workflow-card-actions .button {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 12px;
}
.workflow-card-actions .dashicons {
    font-size: 14px;
    width: 14px;
    height: 14px;
}

/* Import Steps */
.n8npress-import-steps {
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
}
.import-step {
    flex: 1;
    min-width: 180px;
    display: flex;
    gap: 12px;
    align-items: flex-start;
}
.step-number {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    min-width: 32px;
    background: #6366f1;
    color: #fff;
    border-radius: 50%;
    font-weight: 600;
    font-size: 14px;
}
.import-step strong { font-size: 14px; }
.import-step p { font-size: 12px; color: #6b7280; margin: 4px 0 0 0; }

/* Filter active states */
.n8npress-filter-badge .dashicons {
    font-size: 14px;
    width: 14px;
    height: 14px;
    vertical-align: text-bottom;
}
</style>

<script>
(function($) {
    var nonce = $('#_n8npress_wf_nonce').val();

    // Category filter
    $('.n8npress-filter-badge[data-filter]').on('click', function(e) {
        e.preventDefault();
        var filter = $(this).data('filter');

        $('.n8npress-filter-badge[data-filter]').removeClass('badge-active-filter');
        $(this).addClass('badge-active-filter');

        if (filter === 'all') {
            $('.n8npress-workflow-card').removeClass('hidden');
        } else {
            $('.n8npress-workflow-card').each(function() {
                $(this).toggleClass('hidden', $(this).data('category') !== filter);
            });
        }
    });

    // Download workflow
    $('.n8npress-download-workflow').on('click', function() {
        var templateId = $(this).data('template');
        var $btn = $(this);
        $btn.prop('disabled', true);

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'n8npress_download_workflow',
                template_id: templateId,
                _wpnonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    var blob = new Blob([response.data.content], { type: 'application/json' });
                    var url = URL.createObjectURL(blob);
                    var a = document.createElement('a');
                    a.href = url;
                    a.download = response.data.filename;
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    URL.revokeObjectURL(url);
                } else {
                    alert(response.data || 'Download failed');
                }
            },
            error: function() { alert('Request failed'); },
            complete: function() { $btn.prop('disabled', false); }
        });
    });

    // Preview workflow
    $('.n8npress-preview-workflow').on('click', function() {
        var templateId = $(this).data('template');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'n8npress_download_workflow',
                template_id: templateId,
                _wpnonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    try {
                        var formatted = JSON.stringify(JSON.parse(response.data.content), null, 2);
                        $('#workflow-modal-json').text(formatted);
                    } catch(e) {
                        $('#workflow-modal-json').text(response.data.content);
                    }
                    $('#workflow-modal-title').text(response.data.filename);
                    $('#n8npress-workflow-modal').show();
                }
            }
        });
    });

    // Close modal
    $('.n8npress-modal-close, .n8npress-modal').on('click', function(e) {
        if (e.target === this) {
            $('#n8npress-workflow-modal').hide();
        }
    });
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape') $('#n8npress-workflow-modal').hide();
    });
})(jQuery);
</script>
