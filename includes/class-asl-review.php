<?php
/**
 * Review of past failed searches against the current catalog.
 *
 * Every search that ever returned nothing is re-tested with today's products, rules and automatic
 * fixes, and put in one of four groups:
 *  - found:        today it finds products directly (product added since, rule, spelling-insensitive match);
 *  - fixed:        found after automatic clean-up / spelling correction;
 *  - approximate:  only found with fewer words (the visitor sees close products);
 *  - missing:      nothing in the catalog — the list of products you do not sell.
 * Found and fixed searches are marked resolved automatically, so the dashboard only shows real gaps.
 *
 * @package WebiSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASL_Review {

	const OPTION = 'asl_review';
	const QUEUE  = 'asl_review_queue';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'asl_review_cron', array( __CLASS__, 'cron' ) );
		add_action( 'asl_review_continue', array( __CLASS__, 'cron_continue' ) );
		add_action( 'wp_ajax_asl_review_batch', array( __CLASS__, 'ajax_batch' ) );
	}

	/**
	 * Stored review.
	 *
	 * @return array
	 */
	public static function data() {
		$d = get_option( self::OPTION );
		if ( ! is_array( $d ) ) {
			$d = array();
		}
		return wp_parse_args(
			$d,
			array(
				'items'     => array(),
				'decisions' => array(),
				'updated'   => '',
			)
		);
	}

	/**
	 * Save review.
	 *
	 * @param array $d Data.
	 */
	private static function save( array $d ) {
		update_option( self::OPTION, $d, false );
	}

	/**
	 * All distinct failed searches (most searched first).
	 *
	 * @return array
	 */
	public static function failed_terms() {
		global $wpdb;
		$t = asl_table( 'logs' );
		return $wpdb->get_results( "SELECT term_norm, MAX(term) AS sample, COUNT(*) AS searches, MAX(timestamp) AS last FROM $t WHERE result_count = 0 AND term_norm <> '' AND term_norm <> '-' GROUP BY term_norm ORDER BY searches DESC, last DESC LIMIT 3000", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Start a full review: queue every failed search.
	 *
	 * @return int Number of terms queued.
	 */
	public static function start() {
		$rows = self::failed_terms();
		update_option( self::QUEUE, $rows, false );
		// Fresh vocabulary so new products are taken into account.
		ASL_Smart::rebuild_vocab();
		return count( $rows );
	}

	/**
	 * Process queued terms for up to $seconds.
	 *
	 * @param float $seconds Time budget.
	 * @return array [remaining, processed]
	 */
	public static function process( $seconds = 8.0 ) {
		$queue = get_option( self::QUEUE );
		if ( ! is_array( $queue ) || ! $queue ) {
			delete_option( self::QUEUE );
			return array( 0, 0 );
		}
		$d       = self::data();
		$start   = microtime( true );
		$done    = 0;
		$resolve = array();
		while ( $queue && microtime( true ) - $start < $seconds ) {
			$row  = array_shift( $queue );
			$norm = $row['term_norm'];
			$c    = ASL_Smart::classify( $row['sample'] );

			$d['items'][ $norm ] = array(
				'term'     => $row['sample'],
				'searches' => (int) $row['searches'],
				'last'     => $row['last'],
				'status'   => $c['status'],
				'to'       => $c['to'],
				'found'    => (int) $c['found'],
				'kind'     => $c['kind'],
				'checked'  => asl_now(),
			);
			$decision = isset( $d['decisions'][ $norm ] ) ? $d['decisions'][ $norm ] : '';
			if ( 'ignore' === $decision || ( in_array( $c['status'], array( 'found', 'fixed', 'approximate' ), true ) && 'missing' !== $decision ) ) {
				$resolve[] = $norm;
			}
			$done++;
		}
		if ( $resolve ) {
			ASL_DB::resolve_terms( $resolve );
		}
		$d['updated'] = asl_now();
		self::save( $d );
		if ( $queue ) {
			update_option( self::QUEUE, $queue, false );
		} else {
			delete_option( self::QUEUE );
		}
		ASL_Activator::bump_cache();
		return array( count( $queue ), $done );
	}

	/**
	 * Daily cron: review everything again in time-boxed slices.
	 */
	public static function cron() {
		if ( ! get_option( self::QUEUE ) ) {
			self::start();
		}
		self::cron_continue();
	}

	/**
	 * Continue a long review in the background.
	 */
	public static function cron_continue() {
		list( $remaining ) = self::process( 25 );
		if ( $remaining > 0 && ! wp_next_scheduled( 'asl_review_continue' ) ) {
			wp_schedule_single_event( time() + 120, 'asl_review_continue' );
		}
	}

	/**
	 * AJAX: start / continue a review from the admin screen.
	 */
	public static function ajax_batch() {
		check_ajax_referer( 'asl_review', 'nonce' );
		if ( ! current_user_can( asl_capability() ) ) {
			wp_send_json_error( null, 403 );
		}
		$total = 0;
		if ( ! empty( $_POST['start'] ) ) {
			$total = self::start();
		}
		list( $remaining, $done ) = self::process( 6 );
		wp_send_json_success(
			array(
				'remaining' => $remaining,
				'done'      => $done,
				'total'     => $total,
				'counts'    => self::counts(),
			)
		);
	}

	/**
	 * Items of one group, most searched first.
	 *
	 * @param string $status missing|approximate|fixed|found|ignored.
	 * @return array
	 */
	public static function items( $status ) {
		$d   = self::data();
		$out = array();
		foreach ( $d['items'] as $norm => $item ) {
			$decision = isset( $d['decisions'][ $norm ] ) ? $d['decisions'][ $norm ] : '';
			$group    = $item['status'];
			if ( 'ignore' === $decision ) {
				$group = 'ignored';
			} elseif ( 'missing' === $decision ) {
				$group = 'missing';
			}
			if ( $group === $status ) {
				$out[ $norm ] = $item;
			}
		}
		uasort(
			$out,
			function ( $a, $b ) {
				return $b['searches'] <=> $a['searches'];
			}
		);
		return $out;
	}

	/**
	 * Counts per group (terms and searches).
	 *
	 * @return array
	 */
	public static function counts() {
		$out = array();
		foreach ( array( 'missing', 'approximate', 'fixed', 'found', 'ignored' ) as $s ) {
			$items     = self::items( $s );
			$out[ $s ] = array(
				'terms'    => count( $items ),
				'searches' => array_sum( wp_list_pluck( $items, 'searches' ) ),
			);
		}
		return $out;
	}

	/**
	 * Admin decision for a term: missing (we do not sell it), ignore (junk), or '' (automatic).
	 *
	 * @param string $norm     Normalized term.
	 * @param string $decision Decision.
	 */
	public static function decide( $norm, $decision ) {
		$d = self::data();
		if ( '' === $decision ) {
			unset( $d['decisions'][ $norm ] );
		} else {
			$d['decisions'][ $norm ] = $decision;
		}
		self::save( $d );
		if ( 'ignore' === $decision ) {
			ASL_DB::resolve_terms( array( $norm ) );
		} elseif ( 'missing' === $decision ) {
			ASL_DB::resolve_terms( array( $norm ), true );
		}
	}
}
