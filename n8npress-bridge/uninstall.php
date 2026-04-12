<?php
/**
 * n8nPress Uninstall
 *
 * Fired when the plugin is deleted through the WordPress admin.
 * Removes all database tables, options, post meta, comment meta,
 * transients, and scheduled cron hooks created by the plugin.
 *
 * @package N8nPress
 * @since   2.0.0
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// ─── Remove custom database tables ───────────────────────────

$tables = array(
	$wpdb->prefix . 'n8npress_token_usage',
	$wpdb->prefix . 'n8npress_logs',
	$wpdb->prefix . 'n8npress_workflow_stats',
);

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

// ─── Remove all plugin options ───────────────────────────────

$options = $wpdb->get_col(
	"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'n8npress\_%'"
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// ─── Remove all post meta ────────────────────────────────────

$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_n8npress\\_%'" );

// ─── Remove all comment meta ─────────────────────────────────

$wpdb->query( "DELETE FROM {$wpdb->commentmeta} WHERE meta_key LIKE '\\_n8npress\\_%'" );

// ─── Remove transients ──────────────────────────────────────

$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_n8npress\\_%' OR option_name LIKE '\\_transient\\_timeout\\_n8npress\\_%'"
);

// ─── Remove scheduled cron hooks ─────────────────────────────

$cron_hooks = array(
	'n8npress_daily_cleanup',
	'n8npress_process_batch',
	'n8npress_thin_content_enrichment',
	'n8npress_stale_content_check',
);

foreach ( $cron_hooks as $hook ) {
	wp_clear_scheduled_hook( $hook );
}

// ─── Remove custom post type posts ──────────────────────────

$schedule_posts = get_posts( array(
	'post_type'      => 'n8npress_schedule',
	'posts_per_page' => -1,
	'post_status'    => 'any',
	'fields'         => 'ids',
) );

foreach ( $schedule_posts as $post_id ) {
	wp_delete_post( $post_id, true );
}
