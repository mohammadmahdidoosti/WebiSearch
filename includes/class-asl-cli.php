<?php
/**
 * WP-CLI commands.
 *
 * @package WebiSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage WebiSearch search logs.
 */
class ASL_CLI {

	/**
	 * Show search statistics.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Period length. Default 30.
	 *
	 * ## EXAMPLES
	 *     wp webisearch stats --days=7
	 *
	 * @param array $args       Args.
	 * @param array $assoc_args Assoc args.
	 */
	public function stats( $args, $assoc_args ) {
		$days  = isset( $assoc_args['days'] ) ? (int) $assoc_args['days'] : 30;
		$stats = ASL_DB::get_stats( $days );
		$k     = $stats['kpis'];
		WP_CLI::line( sprintf( 'Period: %s .. %s', $stats['period']['start'], $stats['period']['end'] ) );
		WP_CLI::line( sprintf( 'Searches: %d | unique searchers: %d | no-result rate: %s%% | CTR: %s%%', $k['total'], $k['visitors'], $k['zero_rate'], $k['ctr'] ) );
		WP_CLI::line( '' );
		WP_CLI::line( 'Top searches:' );
		WP_CLI\Utils\format_items( 'table', $stats['top'], array( 'term', 'count', 'zero', 'clicked' ) );
		WP_CLI::line( 'Searches with no results:' );
		WP_CLI\Utils\format_items( 'table', $stats['zero'], array( 'term', 'count', 'suggestion' ) );
	}

	/**
	 * Export logs to a CSV file.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Output path.
	 *
	 * [--days=<days>]
	 * : Only the last N days.
	 *
	 * @param array $args       Args.
	 * @param array $assoc_args Assoc args.
	 */
	public function export( $args, $assoc_args ) {
		require_once ASL_PLUGIN_DIR . 'admin/class-asl-export.php';
		$filters = array();
		if ( ! empty( $assoc_args['days'] ) ) {
			$filters['date_start'] = asl_date( 'Y-m-d', time() - ( (int) $assoc_args['days'] - 1 ) * DAY_IN_SECONDS );
		}
		$fh = fopen( $args[0], 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			WP_CLI::error( 'Cannot write ' . $args[0] );
		}
		$n = ASL_Export::write_logs( $fh, $filters );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		WP_CLI::success( sprintf( '%d rows written to %s', $n, $args[0] ) );
	}

	/**
	 * Delete logs older than N days (default: the retention setting).
	 *
	 * [--days=<days>]
	 * : Days to keep.
	 *
	 * @param array $args       Args.
	 * @param array $assoc_args Assoc args.
	 */
	public function cleanup( $args, $assoc_args ) {
		$days = isset( $assoc_args['days'] ) ? (int) $assoc_args['days'] : (int) ASL_Settings::get( 'cleanup_days', 180 );
		ASL_DB::delete_older_than( $days );
		WP_CLI::success( 'Done.' );
	}

	/**
	 * Send the e-mail report now.
	 *
	 * [--to=<email>]
	 * : Recipient.
	 *
	 * @param array $args       Args.
	 * @param array $assoc_args Assoc args.
	 */
	public function report( $args, $assoc_args ) {
		$ok = ASL_Reports::send_report( isset( $assoc_args['to'] ) ? $assoc_args['to'] : '' );
		$ok ? WP_CLI::success( 'Report sent.' ) : WP_CLI::error( 'wp_mail() failed.' );
	}
}
