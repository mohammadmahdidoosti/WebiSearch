<?php
/**
 * Remove all plugin data on uninstall (Plugins > Delete). Deactivating keeps the data.
 *
 * Define ASL_KEEP_DATA_ON_UNINSTALL as true in wp-config.php to keep the logs.
 *
 * @package WebiSearch
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die;
}
if ( defined( 'ASL_KEEP_DATA_ON_UNINSTALL' ) && ASL_KEEP_DATA_ON_UNINSTALL ) {
	return;
}

/**
 * Remove data for the current site.
 */
function asl_uninstall_site() {
	global $wpdb;
	foreach ( array( 'asl_search_logs', 'asl_synonyms', 'asl_clicks', 'asl_requests' ) as $t ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$t}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
	foreach ( array( 'asl_settings', 'asl_db_version', 'asl_cache_ver', 'asl_tracking_since', 'asl_resolved_terms', 'asl_backfill_pending', 'asl_vocab', 'asl_vocab_ver', 'asl_vocab_digits', 'asl_vocab_docs', 'asl_review', 'asl_review_queue' ) as $o ) {
		delete_option( $o );
	}
	delete_transient( 'asl_rules' );
	// Cached statistics (asl_stats_*, asl_pop_*).
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_asl\\_%' OR option_name LIKE '\\_transient\\_timeout\\_asl\\_%'" );
	foreach ( array( 'asl_daily_cleanup', 'asl_weekly_report', 'asl_backfill', 'asl_rebuild_vocab', 'asl_review_cron', 'asl_review_continue' ) as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $blog_id ) {
		switch_to_blog( $blog_id );
		asl_uninstall_site();
		restore_current_blog();
	}
} else {
	asl_uninstall_site();
}
