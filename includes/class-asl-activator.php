<?php
/**
 * Install, upgrade and cron scheduling.
 *
 * @package WebiSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASL_Activator {

	/**
	 * Activation hook (handles network activation too).
	 *
	 * @param bool $network_wide Network wide.
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			$sites = get_sites(
				array(
					'fields' => 'ids',
					'number' => 500,
				)
			);
			foreach ( $sites as $blog_id ) {
				switch_to_blog( $blog_id );
				self::install();
				restore_current_blog();
			}
			return;
		}
		self::install();
	}

	/**
	 * Deactivation: stop cron jobs (data is kept until uninstall).
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'asl_daily_cleanup' );
		wp_clear_scheduled_hook( 'asl_weekly_report' );
		wp_clear_scheduled_hook( 'asl_backfill' );
		wp_clear_scheduled_hook( 'asl_rebuild_vocab' );
		wp_clear_scheduled_hook( 'asl_review_cron' );
		wp_clear_scheduled_hook( 'asl_review_continue' );
	}

	/**
	 * Run install when the stored DB version is older than the code.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'asl_db_version' ) !== ASL_DB_VERSION ) {
			self::install();
		}
		// Continue backfilling normalized terms for large upgraded tables.
		if ( get_option( 'asl_backfill_pending' ) ) {
			add_action( 'asl_backfill', array( __CLASS__, 'backfill_normalized_terms' ) );
		}
	}

	/**
	 * Create/upgrade tables and schedule jobs.
	 */
	public static function install() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$logs            = asl_table( 'logs' );
		$rules           = asl_table( 'rules' );
		$clicks          = asl_table( 'clicks' );
		$previous        = get_option( 'asl_db_version' );

		// dbDelta needs two spaces after PRIMARY KEY and one field per line.
		$sql_logs = "CREATE TABLE $logs (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  term text NOT NULL,
  term_norm varchar(191) NOT NULL DEFAULT '',
  rewritten varchar(191) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  visitor char(16) NOT NULL DEFAULT '',
  timestamp datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  result_count int(11) NOT NULL DEFAULT 0,
  source varchar(20) NOT NULL DEFAULT 'standard',
  post_type varchar(60) NOT NULL DEFAULT '',
  clicks smallint(5) unsigned NOT NULL DEFAULT 0,
  click_position smallint(5) unsigned NOT NULL DEFAULT 0,
  add_to_cart smallint(5) unsigned NOT NULL DEFAULT 0,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  revenue decimal(19,4) NOT NULL DEFAULT 0,
  auto_fixed tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY term_norm (term_norm),
  KEY user_id (user_id),
  KEY timestamp (timestamp),
  KEY visitor (visitor,timestamp)
) $charset_collate;";

		$sql_rules = "CREATE TABLE $rules (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  term varchar(191) NOT NULL,
  type varchar(20) NOT NULL DEFAULT 'synonym',
  match_type varchar(20) NOT NULL DEFAULT 'exact',
  target text NOT NULL,
  status tinyint(1) NOT NULL DEFAULT 1,
  hits bigint(20) unsigned NOT NULL DEFAULT 0,
  last_hit datetime DEFAULT NULL,
  created datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY term (term)
) $charset_collate;";

		$sql_clicks = "CREATE TABLE $clicks (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  log_id bigint(20) unsigned NOT NULL,
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  url varchar(500) NOT NULL DEFAULT '',
  position smallint(5) unsigned NOT NULL DEFAULT 0,
  timestamp datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY log_id (log_id),
  KEY post_id (post_id),
  KEY timestamp (timestamp)
) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// v2 had an index on term(255): heavy to maintain and too long for some MySQL setups.
		// Grouping now uses the term_norm index instead.
		if ( $previous && version_compare( $previous, '3.0.0', '<' ) ) {
			self::drop_index_if_exists( $logs, 'term' );
		}
		// A v2 install has no asl_db_version option but the logs table already exists.
		if ( ! $previous && self::table_exists( $logs ) ) {
			self::drop_index_if_exists( $logs, 'term' );
		}

		dbDelta( $sql_logs );
		dbDelta( $sql_rules );
		dbDelta( $sql_clicks );

		// Fill term_norm for rows logged by older versions (a short first pass, cron finishes the rest).
		self::backfill_normalized_terms( ( defined( 'WP_CLI' ) && WP_CLI ) ? 600 : 3 );

		// 3.0.0 stored Jalali years (e.g. 1405-07-21) on sites running a Persian date plugin. Repair them.
		self::repair_jalali_dates();

		// When click tracking started (CTR is only computed from this point).
		if ( ! get_option( 'asl_tracking_since' ) ) {
			update_option( 'asl_tracking_since', asl_now(), false );
		}

		update_option( 'asl_db_version', ASL_DB_VERSION );
		self::schedule_cron_jobs();
		self::bump_cache();
	}

	/**
	 * Does a table exist?
	 *
	 * @param string $table Table.
	 * @return bool
	 */
	private static function table_exists( $table ) {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/**
	 * Drop an index when present.
	 *
	 * @param string $table Table.
	 * @param string $index Index name.
	 */
	private static function drop_index_if_exists( $table, $index ) {
		global $wpdb;
		if ( ! self::table_exists( $table ) ) {
			return;
		}
		$suppress = $wpdb->suppress_errors( true );
		$rows     = $wpdb->get_results( "SHOW INDEX FROM `$table`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $rows as $row ) {
			if ( isset( $row->Key_name ) && $row->Key_name === $index ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName
				$wpdb->query( "ALTER TABLE `$table` DROP INDEX `$index`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				break;
			}
		}
		$wpdb->suppress_errors( $suppress );
	}

	/**
	 * Fill term_norm in batches; reschedules itself for very large tables.
	 */
	public static function backfill_normalized_terms( $max_seconds = 20 ) {
		global $wpdb;
		$max_seconds = max( 1, (int) $max_seconds );
		$logs  = asl_table( 'logs' );
		$start = microtime( true );
		do {
			$rows = $wpdb->get_results( "SELECT id, term FROM `$logs` WHERE term_norm = '' LIMIT 1000" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( empty( $rows ) ) {
				delete_option( 'asl_backfill_pending' );
				wp_clear_scheduled_hook( 'asl_backfill' );
				return;
			}
			foreach ( $rows as $row ) {
				$norm = ASL_Normalizer::normalize( $row->term );
				$wpdb->update( $logs, array( 'term_norm' => '' === $norm ? '-' : $norm ), array( 'id' => $row->id ), array( '%s' ), array( '%d' ) );
			}
		} while ( microtime( true ) - $start < $max_seconds );

		update_option( 'asl_backfill_pending', 1, false );
		if ( ! wp_next_scheduled( 'asl_backfill' ) ) {
			wp_schedule_single_event( time() + 60, 'asl_backfill' );
		}
	}

	/**
	 * Convert datetimes written with a Jalali year back to Gregorian.
	 */
	public static function repair_jalali_dates() {
		global $wpdb;
		$targets = array(
			array( asl_table( 'logs' ), 'timestamp' ),
			array( asl_table( 'clicks' ), 'timestamp' ),
			array( asl_table( 'rules' ), 'last_hit' ),
			array( asl_table( 'rules' ), 'created' ),
		);
		foreach ( $targets as $t ) {
			list( $table, $col ) = $t;
			$rows = $wpdb->get_results( "SELECT id, `$col` AS d FROM `$table` WHERE `$col` IS NOT NULL AND `$col` > '1200-01-01' AND `$col` < '1700-01-01'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( (array) $rows as $row ) {
				$wpdb->update( $table, array( $col => asl_fix_jalali_datetime( $row->d ) ), array( 'id' => $row->id ) );
			}
		}
		$since = get_option( 'asl_tracking_since' );
		if ( $since && asl_fix_jalali_datetime( $since ) !== $since ) {
			update_option( 'asl_tracking_since', asl_fix_jalali_datetime( $since ), false );
		}
		$resolved = get_option( 'asl_resolved_terms' );
		if ( is_array( $resolved ) && $resolved ) {
			update_option( 'asl_resolved_terms', array_map( 'asl_fix_jalali_datetime', $resolved ), false );
		}
	}

	/**
	 * Schedule cron jobs (idempotent).
	 */
	public static function schedule_cron_jobs() {
		if ( ! wp_next_scheduled( 'asl_daily_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'asl_daily_cleanup' );
		}
		if ( ! wp_next_scheduled( 'asl_rebuild_vocab' ) ) {
			wp_schedule_event( time() + 2 * HOUR_IN_SECONDS, 'daily', 'asl_rebuild_vocab' );
		}
		if ( ! wp_next_scheduled( 'asl_review_cron' ) ) {
			wp_schedule_event( time() + 3 * HOUR_IN_SECONDS, 'daily', 'asl_review_cron' );
		}
		if ( ! wp_next_scheduled( 'asl_weekly_report' ) ) {
			// Next Saturday/Sunday morning in the site's timezone (Saturday for Persian sites, start of the Iranian week).
			$day  = asl_is_persian() ? 'saturday' : 'sunday';
			$next = new DateTime( 'next ' . $day . ' 09:00', wp_timezone() );
			wp_schedule_event( $next->getTimestamp(), 'weekly', 'asl_weekly_report' );
		}
	}

	/**
	 * Invalidate cached statistics.
	 */
	public static function bump_cache() {
		update_option( 'asl_cache_ver', (string) microtime( true ), false );
	}
}
