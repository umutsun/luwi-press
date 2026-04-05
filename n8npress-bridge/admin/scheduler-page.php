<?php
/**
 * n8nPress Content Scheduler Page
 *
 * Admin UI for scheduling AI-generated content.
 */

if (!defined('ABSPATH')) {
    exit;
}

$scheduled_items = N8nPress_Content_Scheduler::get_scheduled_items();
$target_language = get_option('n8npress_target_language', 'tr');
$webhook_url = get_option('n8npress_seo_webhook_url', '');

$status_labels = array(
    'pending'    => __('Pending', 'n8npress'),
    'generating' => __('Generating...', 'n8npress'),
    'ready'      => __('Ready', 'n8npress'),
    'published'  => __('Published', 'n8npress'),
    'failed'     => __('Failed', 'n8npress'),
);

$status_classes = array(
    'pending'    => 'level-info',
    'generating' => 'level-warning',
    'ready'      => 'level-info',
    'published'  => 'badge-active',
    'failed'     => 'level-error',
);

$tone_options = array(
    'professional' => __('Professional', 'n8npress'),
    'casual'       => __('Casual', 'n8npress'),
    'academic'     => __('Academic', 'n8npress'),
    'creative'     => __('Creative', 'n8npress'),
    'persuasive'   => __('Persuasive', 'n8npress'),
    'informative'  => __('Informative', 'n8npress'),
);

$language_options = array(
    'tr' => 'Türkçe', 'en' => 'English', 'de' => 'Deutsch', 'fr' => 'Français',
    'ar' => 'العربية', 'es' => 'Español', 'it' => 'Italiano', 'nl' => 'Nederlands',
    'ru' => 'Русский', 'ja' => '日本語', 'zh' => '中文',
);
?>

<div class="wrap n8npress-dashboard">
    <h1>
        <span class="dashicons dashicons-calendar-alt"></span>
        <?php _e('Content Scheduler', 'n8npress'); ?>
    </h1>

    <?php if (empty($webhook_url)) : ?>
    <div class="notice notice-warning">
        <p>
            <strong><?php _e('n8n connection not configured.', 'n8npress'); ?></strong>
            <?php _e('Content generation requires an active n8n webhook connection.', 'n8npress'); ?>
            <a href="<?php echo admin_url('admin.php?page=n8npress-settings&tab=connection'); ?>"><?php _e('Configure now', 'n8npress'); ?></a>
        </p>
    </div>
    <?php endif; ?>

    <!-- New Content Form -->
    <div class="n8npress-section">
        <h2><?php _e('Schedule New Content', 'n8npress'); ?></h2>
        <form id="n8npress-schedule-form">
            <?php wp_nonce_field('n8npress_scheduler_nonce'); ?>

            <div class="n8npress-scheduler-grid">
                <div class="scheduler-main">
                    <table class="form-table">
                        <tr>
                            <th><label for="schedule-topic"><?php _e('Topic / Title', 'n8npress'); ?> <span class="required">*</span></label></th>
                            <td>
                                <input type="text" id="schedule-topic" name="topic" class="large-text"
                                       placeholder="<?php _e('e.g., Best practices for acoustic guitar maintenance', 'n8npress'); ?>" required />
                                <p class="description"><?php _e('The main topic or title for the AI-generated content.', 'n8npress'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="schedule-keywords"><?php _e('SEO Keywords', 'n8npress'); ?></label></th>
                            <td>
                                <input type="text" id="schedule-keywords" name="keywords" class="large-text"
                                       placeholder="<?php _e('guitar maintenance, acoustic guitar care, string changing', 'n8npress'); ?>" />
                                <p class="description"><?php _e('Comma-separated keywords to optimize the content for SEO.', 'n8npress'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="schedule-tone"><?php _e('Tone', 'n8npress'); ?></label></th>
                            <td>
                                <select id="schedule-tone" name="tone">
                                    <?php foreach ($tone_options as $val => $label) : ?>
                                    <option value="<?php echo esc_attr($val); ?>"><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="schedule-words"><?php _e('Word Count', 'n8npress'); ?></label></th>
                            <td>
                                <input type="number" id="schedule-words" name="word_count" value="1500" min="300" max="5000" class="small-text" />
                                <span class="description"><?php _e('approximate target', 'n8npress'); ?></span>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="scheduler-sidebar">
                    <div class="n8npress-card">
                        <h3><?php _e('Publishing', 'n8npress'); ?></h3>
                        <p>
                            <label for="schedule-date"><?php _e('Date', 'n8npress'); ?> <span class="required">*</span></label><br>
                            <input type="date" id="schedule-date" name="publish_date" required
                                   min="<?php echo esc_attr(wp_date('Y-m-d')); ?>" />
                        </p>
                        <p>
                            <label for="schedule-time"><?php _e('Time', 'n8npress'); ?></label><br>
                            <input type="time" id="schedule-time" name="publish_time" value="09:00" />
                        </p>
                        <p>
                            <label for="schedule-type"><?php _e('Post Type', 'n8npress'); ?></label><br>
                            <select id="schedule-type" name="target_post_type" style="width:100%;">
                                <option value="post"><?php _e('Blog Post', 'n8npress'); ?></option>
                                <option value="page"><?php _e('Page', 'n8npress'); ?></option>
                                <?php if (post_type_exists('product')) : ?>
                                <option value="product"><?php _e('Product', 'n8npress'); ?></option>
                                <?php endif; ?>
                            </select>
                        </p>
                        <p>
                            <label for="schedule-language"><?php _e('Language', 'n8npress'); ?></label><br>
                            <select id="schedule-language" name="language" style="width:100%;">
                                <?php foreach ($language_options as $code => $name) : ?>
                                <option value="<?php echo esc_attr($code); ?>" <?php selected($target_language, $code); ?>>
                                    <?php echo esc_html($name); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </p>
                        <p>
                            <label>
                                <input type="checkbox" name="generate_image" value="1" checked />
                                <?php _e('Generate featured image', 'n8npress'); ?>
                            </label>
                        </p>
                        <p>
                            <button type="submit" class="button button-primary button-large" style="width:100%;" <?php echo empty($webhook_url) ? 'disabled' : ''; ?>>
                                <span class="dashicons dashicons-controls-play" style="margin-top:4px;"></span>
                                <?php _e('Schedule & Generate', 'n8npress'); ?>
                            </button>
                        </p>
                    </div>
                </div>
            </div>
        </form>
        <div id="n8npress-schedule-result" style="display:none;"></div>
    </div>

    <!-- Scheduled Items List -->
    <div class="n8npress-section">
        <h2><?php _e('Scheduled Content', 'n8npress'); ?></h2>

        <?php if (empty($scheduled_items)) : ?>
            <div class="n8npress-empty-state">
                <span class="dashicons dashicons-calendar-alt"></span>
                <h3><?php _e('No scheduled content yet', 'n8npress'); ?></h3>
                <p><?php _e('Schedule your first AI-generated content above.', 'n8npress'); ?></p>
            </div>
        <?php else : ?>
            <table class="n8npress-table">
                <thead>
                    <tr>
                        <th><?php _e('Topic', 'n8npress'); ?></th>
                        <th><?php _e('Status', 'n8npress'); ?></th>
                        <th><?php _e('Type', 'n8npress'); ?></th>
                        <th><?php _e('Language', 'n8npress'); ?></th>
                        <th><?php _e('Publish Date', 'n8npress'); ?></th>
                        <th><?php _e('Created', 'n8npress'); ?></th>
                        <th><?php _e('Actions', 'n8npress'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($scheduled_items as $item) :
                        $status = get_post_meta($item->ID, '_n8npress_schedule_status', true);
                        $topic = get_post_meta($item->ID, '_n8npress_schedule_topic', true);
                        $type = get_post_meta($item->ID, '_n8npress_schedule_type', true);
                        $lang = get_post_meta($item->ID, '_n8npress_schedule_language', true);
                        $pub_date = get_post_meta($item->ID, '_n8npress_schedule_date', true);
                        $published_id = get_post_meta($item->ID, '_n8npress_published_post_id', true);
                        $error = get_post_meta($item->ID, '_n8npress_schedule_error', true);
                        $status_label = $status_labels[$status] ?? $status;
                        $status_class = $status_classes[$status] ?? '';
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($topic); ?></strong>
                            <?php if ($error) : ?>
                                <br><small style="color:#dc2626;"><?php echo esc_html($error); ?></small>
                            <?php endif; ?>
                        </td>
                        <td><span class="log-level <?php echo esc_attr($status_class); ?>"><?php echo esc_html($status_label); ?></span></td>
                        <td><?php echo esc_html($type); ?></td>
                        <td><code class="lang-tag"><?php echo esc_html($lang); ?></code></td>
                        <td><?php echo esc_html($pub_date ? wp_date('d M Y H:i', strtotime($pub_date)) : '—'); ?></td>
                        <td class="log-time"><?php echo esc_html(human_time_diff(strtotime($item->post_date), current_time('timestamp')) . ' ' . __('ago', 'n8npress')); ?></td>
                        <td>
                            <?php if ($published_id) : ?>
                                <a href="<?php echo esc_url(get_edit_post_link($published_id)); ?>" class="button button-small"><?php _e('Edit Post', 'n8npress'); ?></a>
                                <a href="<?php echo esc_url(get_permalink($published_id)); ?>" class="button button-small" target="_blank"><?php _e('View', 'n8npress'); ?></a>
                            <?php elseif ($status !== 'published') : ?>
                                <button type="button" class="button button-small button-link-delete n8npress-delete-schedule" data-id="<?php echo esc_attr($item->ID); ?>">
                                    <?php _e('Delete', 'n8npress'); ?>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<style>
.n8npress-scheduler-grid {
    display: grid;
    grid-template-columns: 1fr 300px;
    gap: 20px;
}
.scheduler-sidebar .n8npress-card {
    position: sticky;
    top: 40px;
}
.scheduler-sidebar label {
    font-weight: 500;
}
.required { color: #dc2626; }
@media (max-width: 960px) {
    .n8npress-scheduler-grid { grid-template-columns: 1fr; }
}
</style>

<script>
(function($) {
    // Schedule form submit
    $('#n8npress-schedule-form').on('submit', function(e) {
        e.preventDefault();
        var $form = $(this);
        var $btn = $form.find('button[type="submit"]');
        var $result = $('#n8npress-schedule-result');

        $btn.prop('disabled', true).text('<?php _e('Sending to n8n...', 'n8npress'); ?>');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: $form.serialize() + '&action=n8npress_schedule_content',
            success: function(response) {
                if (response.success) {
                    $result.html('<div class="notice notice-success"><p><?php _e('Content scheduled and sent to n8n for generation!', 'n8npress'); ?></p></div>').show();
                    setTimeout(function() { location.reload(); }, 1500);
                } else {
                    $result.html('<div class="notice notice-error"><p>' + (response.data || '<?php _e('Error', 'n8npress'); ?>') + '</p></div>').show();
                }
            },
            error: function() {
                $result.html('<div class="notice notice-error"><p><?php _e('Request failed', 'n8npress'); ?></p></div>').show();
            },
            complete: function() {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-controls-play" style="margin-top:4px;"></span> <?php _e('Schedule & Generate', 'n8npress'); ?>');
            }
        });
    });

    // Delete schedule
    $('.n8npress-delete-schedule').on('click', function() {
        if (!confirm('<?php _e('Are you sure?', 'n8npress'); ?>')) return;
        var $btn = $(this);
        var id = $btn.data('id');
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'n8npress_delete_schedule',
                schedule_id: id,
                _wpnonce: $('input[name="_wpnonce"]').val()
            },
            success: function(response) {
                if (response.success) {
                    $btn.closest('tr').fadeOut(300, function() { $(this).remove(); });
                }
            }
        });
    });
})(jQuery);
</script>
