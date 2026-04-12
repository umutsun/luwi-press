<?php
/**
 * LuwiPress Uninstall
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
	$wpdb->prefix . 'luwipress_token_usage',
	$wpdb->prefix . 'luwipress_logs',
	$wpdb->prefix . 'luwipress_workflow_stats',  // legacy, may not exist
	$wpdb->prefix . 'luwipress_jobs',            // legacy, may not exist
);

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

// ─── Remove all plugin options ───────────────────────────────

$options = $wpdb->get_col(
	"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'luwipress\_%'"
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// ─── Remove all post meta ────────────────────────────────────

$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_luwipress\\_%'" );

// ─── Remove all comment meta ─────────────────────────────────

$wpdb->query( "DELETE FROM {$wpdb->commentmeta} WHERE meta_key LIKE '\\_luwipress\\_%'" );

// ─── Remove transients ──────────────────────────────────────

$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_luwipress\\_%' OR option_name LIKE '\\_transient\\_timeout\\_luwipress\\_%'"
);

// ─── Remove scheduled cron hooks ─────────────────────────────

$cron_hooks = array(
	'luwipress_daily_cleanup',
	'luwipress_process_batch',
	'luwipress_thin_content_enrichment',
	'luwipress_stale_content_check',
);

foreach ( $cron_hooks as $hook ) {
	wp_clear_scheduled_hook( $hook );
}

// ─── Remove custom post type posts ──────────────────────────

$schedule_posts = get_posts( array(
	'post_type'      => 'luwipress_schedule',
	'posts_per_page' => -1,
	'post_status'    => 'any',
	'fields'         => 'ids',
) );

foreach ( $schedule_posts as $post_id ) {
	wp_delete_post( $post_id, true );
}
