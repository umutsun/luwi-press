<?php
/**
 * n8nPress Logs Page
 *
 * View and manage webhook/workflow activity logs.
 */

if (!defined('ABSPATH')) {
    exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'n8npress' ) );
}

// Handle log actions
if (isset($_POST['n8npress_clear_logs']) && check_admin_referer('n8npress_logs_nonce')) {
    $days = absint($_POST['n8npress_clear_days'] ?? 30);
    N8nPress_Logger::cleanup($days);
    echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(__('Logs older than %d days cleared.', 'n8npress'), $days) . '</p></div>';
}

if (isset($_POST['n8npress_clear_all_logs']) && check_admin_referer('n8npress_logs_nonce')) {
    N8nPress_Logger::cleanup(0);
    echo '<div class="notice notice-success is-dismissible"><p>' . __('All logs cleared.', 'n8npress') . '</p></div>';
}

// Filters
$filter_level = isset($_GET['level']) ? sanitize_text_field($_GET['level']) : '';
$filter_search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
$per_page = 50;
$current_page = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;

// Get logs
$logs = N8nPress_Logger::get_logs($per_page * 10, $filter_level ?: null);

// Apply search filter
if (!empty($filter_search)) {
    $logs = array_filter($logs, function($log) use ($filter_search) {
        return stripos($log->message, $filter_search) !== false ||
               stripos($log->context ?? '', $filter_search) !== false;
    });
}

$total_logs = count($logs);
$total_pages = ceil($total_logs / $per_page);
$logs = array_slice($logs, ($current_page - 1) * $per_page, $per_page);

// Log level counts for filter badges
$all_logs = N8nPress_Logger::get_logs(500);
$level_counts = array('info' => 0, 'warning' => 0, 'error' => 0, 'debug' => 0);
foreach ($all_logs as $log) {
    if (isset($level_counts[$log->level])) {
        $level_counts[$log->level]++;
    }
}
?>

<div class="wrap n8npress-logs">
    <h1><span class="dashicons dashicons-list-view"></span> <?php _e('n8nPress Logs', 'n8npress'); ?></h1>

    <!-- Filter Bar -->
    <div class="n8npress-filter-bar">
        <div class="n8npress-filter-levels">
            <a href="<?php echo admin_url('admin.php?page=n8npress-logs'); ?>"
               class="n8npress-filter-badge <?php echo empty($filter_level) ? 'badge-active-filter' : ''; ?>">
                <?php _e('All', 'n8npress'); ?> <span class="count">(<?php echo array_sum($level_counts); ?>)</span>
            </a>
            <a href="<?php echo admin_url('admin.php?page=n8npress-logs&level=error'); ?>"
               class="n8npress-filter-badge badge-error <?php echo $filter_level === 'error' ? 'badge-active-filter' : ''; ?>">
                <?php _e('Error', 'n8npress'); ?> <span class="count">(<?php echo $level_counts['error']; ?>)</span>
            </a>
            <a href="<?php echo admin_url('admin.php?page=n8npress-logs&level=warning'); ?>"
               class="n8npress-filter-badge badge-warning <?php echo $filter_level === 'warning' ? 'badge-active-filter' : ''; ?>">
                <?php _e('Warning', 'n8npress'); ?> <span class="count">(<?php echo $level_counts['warning']; ?>)</span>
            </a>
            <a href="<?php echo admin_url('admin.php?page=n8npress-logs&level=info'); ?>"
               class="n8npress-filter-badge badge-info <?php echo $filter_level === 'info' ? 'badge-active-filter' : ''; ?>">
                <?php _e('Info', 'n8npress'); ?> <span class="count">(<?php echo $level_counts['info']; ?>)</span>
            </a>
            <a href="<?php echo admin_url('admin.php?page=n8npress-logs&level=debug'); ?>"
               class="n8npress-filter-badge badge-debug <?php echo $filter_level === 'debug' ? 'badge-active-filter' : ''; ?>">
                <?php _e('Debug', 'n8npress'); ?> <span class="count">(<?php echo $level_counts['debug']; ?>)</span>
            </a>
        </div>

        <form method="get" class="n8npress-search-form">
            <input type="hidden" name="page" value="n8npress-logs" />
            <?php if ($filter_level) : ?>
                <input type="hidden" name="level" value="<?php echo esc_attr($filter_level); ?>" />
            <?php endif; ?>
            <input type="search" name="search" value="<?php echo esc_attr($filter_search); ?>"
                   placeholder="<?php _e('Search logs...', 'n8npress'); ?>" class="n8npress-search-input" />
            <button type="submit" class="button"><?php _e('Search', 'n8npress'); ?></button>
        </form>
    </div>

    <!-- Logs Table -->
    <?php if (empty($logs)) : ?>
        <div class="n8npress-empty-state">
            <span class="dashicons dashicons-media-text"></span>
            <h3><?php _e('No logs found', 'n8npress'); ?></h3>
            <p><?php _e('Logs will appear here as webhooks and workflows are processed.', 'n8npress'); ?></p>
        </div>
    <?php else : ?>
        <table class="n8npress-table n8npress-logs-table">
            <thead>
                <tr>
                    <th class="column-id"><?php _e('ID', 'n8npress'); ?></th>
                    <th class="column-time"><?php _e('Time', 'n8npress'); ?></th>
                    <th class="column-level"><?php _e('Level', 'n8npress'); ?></th>
                    <th class="column-message"><?php _e('Message', 'n8npress'); ?></th>
                    <th class="column-context"><?php _e('Context', 'n8npress'); ?></th>
                    <th class="column-ip"><?php _e('IP', 'n8npress'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log) : ?>
                <tr class="log-row level-row-<?php echo esc_attr($log->level); ?>">
                    <td class="column-id"><?php echo intval($log->id); ?></td>
                    <td class="column-time">
                        <span class="log-date"><?php echo esc_html(wp_date('Y-m-d', strtotime($log->timestamp))); ?></span>
                        <span class="log-time-detail"><?php echo esc_html(wp_date('H:i:s', strtotime($log->timestamp))); ?></span>
                    </td>
                    <td class="column-level">
                        <span class="log-level level-<?php echo esc_attr($log->level); ?>">
                            <?php echo esc_html(strtoupper($log->level)); ?>
                        </span>
                    </td>
                    <td class="column-message"><?php echo esc_html($log->message); ?></td>
                    <td class="column-context">
                        <?php
                        if (!empty($log->context)) {
                            $ctx = json_decode($log->context, true);
                            if (is_array($ctx)) {
                                echo '<button type="button" class="button button-small n8npress-toggle-context" data-context="' . esc_attr($log->context) . '">';
                                echo '<span class="dashicons dashicons-editor-code"></span>';
                                echo '</button>';
                            }
                        } else {
                            echo '—';
                        }
                        ?>
                    </td>
                    <td class="column-ip">
                        <?php echo !empty($log->ip_address) ? esc_html($log->ip_address) : '—'; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Pagination -->
        <?php if ($total_pages > 1) : ?>
        <div class="n8npress-pagination">
            <?php
            $base_url = admin_url('admin.php?page=n8npress-logs');
            if ($filter_level) $base_url .= '&level=' . urlencode($filter_level);
            if ($filter_search) $base_url .= '&search=' . urlencode($filter_search);

            for ($i = 1; $i <= $total_pages; $i++) : ?>
                <?php if ($i === $current_page) : ?>
                    <span class="page-number current"><?php echo $i; ?></span>
                <?php else : ?>
                    <a href="<?php echo esc_url($base_url . '&paged=' . $i); ?>" class="page-number"><?php echo $i; ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Log Maintenance -->
    <div class="n8npress-section n8npress-maintenance">
        <h2><?php _e('Log Maintenance', 'n8npress'); ?></h2>
        <form method="post">
            <?php wp_nonce_field('n8npress_logs_nonce'); ?>
            <div class="n8npress-maintenance-actions">
                <div class="maintenance-action">
                    <label>
                        <?php _e('Clear logs older than', 'n8npress'); ?>
                        <input type="number" name="n8npress_clear_days" value="30" min="1" max="365" class="small-text" />
                        <?php _e('days', 'n8npress'); ?>
                    </label>
                    <button type="submit" name="n8npress_clear_logs" class="button"><?php _e('Clear Old Logs', 'n8npress'); ?></button>
                </div>
                <div class="maintenance-action">
                    <button type="submit" name="n8npress_clear_all_logs" class="button button-link-delete"
                            onclick="return confirm('<?php _e('Are you sure? This will delete ALL logs.', 'n8npress'); ?>');">
                        <?php _e('Clear All Logs', 'n8npress'); ?>
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Context Modal -->
    <div id="n8npress-context-modal" class="n8npress-modal" style="display:none;">
        <div class="n8npress-modal-content">
            <div class="n8npress-modal-header">
                <h3><?php _e('Log Context', 'n8npress'); ?></h3>
                <button type="button" class="n8npress-modal-close">&times;</button>
            </div>
            <pre id="n8npress-context-data" class="n8npress-json-view"></pre>
        </div>
    </div>
</div>
