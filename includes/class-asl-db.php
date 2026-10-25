<?php
/**
 * Database access layer.
 *
 * All timestamps are stored in the site's local time (as in v1/v2) so existing data stays valid.
 *
 * @package WebiSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table names are internal; values go through prepare().

class ASL_DB {

	/* ------------------------------------------------------------------ *
	 * Logs: write
	 * ------------------------------------------------------------------ */

	/**
	 * Insert a log row.
	 *
	 * @param array $data Columns.
	 * @return int Insert ID (0 on failure).
	 */
	public static function insert_log( array $data ) {
		global $wpdb;
		$defaults = array(
			'term'         => '',
			'term_norm'    => '',
			'rewritten'    => '',
			'user_id'      => 0,
			'visitor'      => '',
			'timestamp'    => asl_now(),
			'result_count' => 0,
			'source'       => 'standard',
			'post_type'    => '',
			'auto_fixed'   => 0,
		);
		$data     = array_intersect_key( wp_parse_args( $data, $defaults ), $defaults );
		$ok       = $wpdb->insert( asl_table( 'logs' ), $data, array( '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%d' ) );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Update a log row.
	 *
	 * @param int   $id   Log ID.
	 * @param array $data Columns.
	 */
	public static function update_log( $id, array $data ) {
		global $wpdb;
		$wpdb->update( asl_table( 'logs' ), $data, array( 'id' => (int) $id ) );
	}

	/**
	 * Most recent log of a visitor since a moment (used to merge as-you-type searches).
	 *
	 * @param string $visitor Visitor hash.
	 * @param string $since   Datetime.
	 * @return object|null
	 */
	public static function last_log_of_visitor( $visitor, $since ) {
		global $wpdb;
		if ( '' === $visitor ) {
			return null;
		}
		$t = asl_table( 'logs' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT id, term_norm, source, result_count FROM $t WHERE visitor = %s AND timestamp >= %s ORDER BY id DESC LIMIT 1", $visitor, $since ) );
	}

	/**
	 * Get one log row.
	 *
	 * @param int $id Log ID.
	 * @return object|null
	 */
	public static function get_log( $id ) {
		global $wpdb;
		$t = asl_table( 'logs' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ) );
	}

	/**
	 * Delete logs by IDs.
	 *
	 * @param int[] $ids IDs.
	 * @return int Rows deleted.
	 */
	public static function delete_logs( array $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( ! $ids ) {
			return 0;
		}
		$in = implode( ',', $ids );
		$wpdb->query( 'DELETE FROM ' . asl_table( 'clicks' ) . " WHERE log_id IN ($in)" );
		$n = (int) $wpdb->query( 'DELETE FROM ' . asl_table( 'logs' ) . " WHERE id IN ($in)" );
		ASL_Activator::bump_cache();
		return $n;
	}

	/**
	 * Delete all logs of one (normalized) term.
	 *
	 * @param string $term_norm Term.
	 * @return int
	 */
	public static function delete_logs_by_term( $term_norm ) {
		global $wpdb;
		$t   = asl_table( 'logs' );
		$c   = asl_table( 'clicks' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM $c WHERE log_id IN (SELECT id FROM $t WHERE term_norm = %s)", $term_norm ) );
		$n = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE term_norm = %s", $term_norm ) );
		ASL_Activator::bump_cache();
		return $n;
	}

	/**
	 * Delete logs older than N days.
	 *
	 * @param int $days Days.
	 */
	public static function delete_older_than( $days ) {
		global $wpdb;
		$days = (int) $days;
		if ( $days <= 0 ) {
			return;
		}
		$limit = asl_now( -$days * DAY_IN_SECONDS );
		$t     = asl_table( 'logs' );
		$c     = asl_table( 'clicks' );
		// Batched to avoid long table locks on big sites.
		do {
			$n = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE timestamp < %s LIMIT 5000", $limit ) );
		} while ( $n >= 5000 );
		$wpdb->query( $wpdb->prepare( "DELETE FROM $c WHERE timestamp < %s", $limit ) );
		ASL_Activator::bump_cache();
	}

	/**
	 * Remove every log and click.
	 */
	public static function clear_all() {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . asl_table( 'logs' ) );
		$wpdb->query( 'TRUNCATE TABLE ' . asl_table( 'clicks' ) );
		update_option( 'asl_tracking_since', asl_now(), false );
		delete_option( 'asl_resolved_terms' );
		ASL_Activator::bump_cache();
	}

	/* ------------------------------------------------------------------ *
	 * Logs: read
	 * ------------------------------------------------------------------ */

	/**
	 * Normalize list filters coming from a request.
	 *
	 * @param array $src Usually $_GET.
	 * @return array
	 */
	public static function filters_from_request( array $src ) {
		$f = array(
			'search'     => isset( $src['s'] ) ? sanitize_text_field( wp_unslash( $src['s'] ) ) : '',
			'term'       => isset( $src['term'] ) ? sanitize_text_field( wp_unslash( $src['term'] ) ) : '',
			'date_start' => isset( $src['date_start'] ) ? sanitize_text_field( wp_unslash( $src['date_start'] ) ) : '',
			'date_end'   => isset( $src['date_end'] ) ? sanitize_text_field( wp_unslash( $src['date_end'] ) ) : '',
			'source'     => isset( $src['source'] ) ? sanitize_key( wp_unslash( $src['source'] ) ) : '',
			'results'    => isset( $src['results'] ) ? sanitize_key( wp_unslash( $src['results'] ) ) : '',
			'user'       => isset( $src['user'] ) ? sanitize_key( wp_unslash( $src['user'] ) ) : '',
		);
		// Backward compatibility with the v2 "no_results" checkbox.
		if ( ! empty( $src['no_results'] ) ) {
			$f['results'] = 'zero';
		}
		foreach ( array( 'date_start', 'date_end' ) as $k ) {
			if ( $f[ $k ] && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $f[ $k ] ) ) {
				$f[ $k ] = '';
			}
		}
		return $f;
	}

	/**
	 * WHERE clause for log filters.
	 *
	 * @param array $f Filters.
	 * @return string
	 */
	private static function logs_where( array $f ) {
		global $wpdb;
		$w = array( '1=1' );
		if ( ! empty( $f['search'] ) ) {
			$like = '%' . $wpdb->esc_like( ASL_Normalizer::normalize( $f['search'] ) ) . '%';
			$raw  = '%' . $wpdb->esc_like( $f['search'] ) . '%';
			$w[]  = $wpdb->prepare( '(term_norm LIKE %s OR term LIKE %s OR rewritten LIKE %s)', $like, $raw, $raw );
		}
		if ( ! empty( $f['term'] ) ) {
			$w[] = $wpdb->prepare( 'term_norm = %s', ASL_Normalizer::normalize( $f['term'] ) );
		}
		if ( ! empty( $f['date_start'] ) ) {
			$w[] = $wpdb->prepare( 'timestamp >= %s', $f['date_start'] . ' 00:00:00' );
		}
		if ( ! empty( $f['date_end'] ) ) {
			$w[] = $wpdb->prepare( 'timestamp <= %s', $f['date_end'] . ' 23:59:59' );
		}
		if ( ! empty( $f['source'] ) ) {
			$w[] = $wpdb->prepare( 'source = %s', $f['source'] );
		}
		if ( isset( $f['results'] ) && 'zero' === $f['results'] ) {
			$w[] = 'result_count = 0';
		} elseif ( isset( $f['results'] ) && 'with' === $f['results'] ) {
			$w[] = 'result_count > 0';
		} elseif ( isset( $f['results'] ) && 'clicked' === $f['results'] ) {
			$w[] = 'clicks > 0';
		} elseif ( isset( $f['results'] ) && 'fixed' === $f['results'] ) {
			$w[] = 'auto_fixed = 1';
		}
		if ( isset( $f['user'] ) && 'guest' === $f['user'] ) {
			$w[] = 'user_id = 0';
		} elseif ( isset( $f['user'] ) && 'member' === $f['user'] ) {
			$w[] = 'user_id > 0';
		}
		if ( ! empty( $f['user_id'] ) ) {
			$w[] = $wpdb->prepare( 'user_id = %d', $f['user_id'] );
		}
		if ( ! empty( $f['min_id'] ) ) {
			$w[] = $wpdb->prepare( 'id < %d', $f['min_id'] );
		}
		return ' WHERE ' . implode( ' AND ', $w );
	}

	/**
	 * Raw logs page.
	 *
	 * @param array $f        Filters.
	 * @param int   $per_page Per page (0 = all).
	 * @param int   $page     Page.
	 * @param string $orderby Column.
	 * @param string $order   ASC|DESC.
	 * @return array
	 */
	public static function get_logs( array $f, $per_page = 20, $page = 1, $orderby = 'timestamp', $order = 'DESC' ) {
		global $wpdb;
		$allowed = array( 'timestamp', 'result_count', 'user_id', 'term_norm', 'source', 'clicks', 'id' );
		$orderby = in_array( $orderby, $allowed, true ) ? $orderby : 'timestamp';
		$order   = 'ASC' === strtoupper( (string) $order ) ? 'ASC' : 'DESC';
		$sql     = 'SELECT * FROM ' . asl_table( 'logs' ) . self::logs_where( $f ) . " ORDER BY $orderby $order, id $order";
		if ( $per_page > 0 ) {
			$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $per_page, max( 0, ( $page - 1 ) * $per_page ) );
		}
		return $wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * Count raw logs.
	 *
	 * @param array $f Filters.
	 * @return int
	 */
	public static function count_logs( array $f ) {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . asl_table( 'logs' ) . self::logs_where( $f ) );
	}

	/**
	 * Walk all matching logs in batches (newest first) without loading everything in memory.
	 *
	 * @param array    $f        Filters.
	 * @param callable $callback Called with each row (array).
	 * @param int      $batch    Batch size.
	 */
	public static function each_log( array $f, $callback, $batch = 2000 ) {
		global $wpdb;
		$f['min_id'] = 0;
		do {
			$sql  = 'SELECT * FROM ' . asl_table( 'logs' ) . self::logs_where( $f ) . $wpdb->prepare( ' ORDER BY id DESC LIMIT %d', $batch );
			$rows = $wpdb->get_results( $sql, ARRAY_A );
			foreach ( $rows as $row ) {
				call_user_func( $callback, $row );
				$f['min_id'] = (int) $row['id'];
			}
		} while ( count( $rows ) === $batch );
	}

	/**
	 * Aggregated per-term report.
	 *
	 * @param array  $f        Filters.
	 * @param int    $per_page Per page (0 = all).
	 * @param int    $page     Page.
	 * @param string $orderby  searches|zero|avg_results|ctr|last|visitors|revenue.
	 * @param string $order    ASC|DESC.
	 * @return array
	 */
	public static function get_terms_report( array $f, $per_page = 20, $page = 1, $orderby = 'searches', $order = 'DESC' ) {
		global $wpdb;
		$allowed = array( 'searches', 'zero', 'avg_results', 'ctr', 'last_search', 'visitors', 'revenue', 'term_norm' );
		$orderby = in_array( $orderby, $allowed, true ) ? $orderby : 'searches';
		$order   = 'ASC' === strtoupper( (string) $order ) ? 'ASC' : 'DESC';
		$sql     = 'SELECT term_norm, MAX(term) AS sample, COUNT(*) AS searches,'
			. " COUNT(DISTINCT NULLIF(visitor, '')) AS visitors,"
			. ' SUM(CASE WHEN result_count = 0 THEN 1 ELSE 0 END) AS zero,'
			. ' AVG(CASE WHEN result_count >= 0 THEN result_count END) AS avg_results,'
			. ' SUM(CASE WHEN clicks > 0 THEN 1 ELSE 0 END) AS clicked,'
			. ' ROUND(100 * SUM(CASE WHEN clicks > 0 THEN 1 ELSE 0 END) / COUNT(*), 1) AS ctr,'
			. ' SUM(CASE WHEN add_to_cart > 0 THEN 1 ELSE 0 END) AS carts,'
			. ' SUM(revenue) AS revenue,'
			. ' MAX(rewritten) AS rewritten,'
			. ' MAX(timestamp) AS last_search'
			. ' FROM ' . asl_table( 'logs' ) . self::logs_where( $f )
			. " GROUP BY term_norm ORDER BY $orderby $order, term_norm ASC";
		if ( $per_page > 0 ) {
			$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $per_page, max( 0, ( $page - 1 ) * $per_page ) );
		}
		return $wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * Count distinct terms.
	 *
	 * @param array $f Filters.
	 * @return int
	 */
	public static function count_terms_report( array $f ) {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(DISTINCT term_norm) FROM ' . asl_table( 'logs' ) . self::logs_where( $f ) );
	}

	/* ------------------------------------------------------------------ *
	 * Statistics
	 * ------------------------------------------------------------------ */

	/**
	 * Period boundaries for "last N days" (including today), plus the previous period.
	 *
	 * @param int $days Days.
	 * @return array
	 */
	public static function period( $days ) {
		$days  = max( 1, (int) $days );
		// Pure date arithmetic on Gregorian Y-m-d strings (UTC noon avoids DST edge cases).
		$shift = function ( $ymd, $delta_days ) {
			return gmdate( 'Y-m-d', strtotime( $ymd . ' 12:00:00 UTC' ) + $delta_days * DAY_IN_SECONDS );
		};
		$today = asl_date( 'Y-m-d' );
		$start = $shift( $today, -( $days - 1 ) );
		$pend  = $shift( $start, -1 );
		$pst   = $shift( $pend, -( $days - 1 ) );
		return array(
			'days'       => $days,
			'start'      => $start,
			'end'        => $today,
			'prev_start' => $pst,
			'prev_end'   => $pend,
		);
	}

	/**
	 * Key performance indicators for a date range.
	 *
	 * @param string $start Y-m-d.
	 * @param string $end   Y-m-d.
	 * @return array
	 */
	public static function kpis( $start, $end ) {
		global $wpdb;
		$t     = asl_table( 'logs' );
		$since = get_option( 'asl_tracking_since', '1970-01-01 00:00:00' );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS total,
				COUNT(DISTINCT NULLIF(visitor, '')) AS visitors,
				COUNT(DISTINCT term_norm) AS terms,
				SUM(CASE WHEN result_count = 0 THEN 1 ELSE 0 END) AS zero,
				AVG(CASE WHEN result_count >= 0 THEN result_count END) AS avg_results,
				SUM(CASE WHEN source = 'redirect' THEN 1 ELSE 0 END) AS redirected,
				SUM(CASE WHEN source = 'ajax' THEN 1 ELSE 0 END) AS live,
				SUM(CASE WHEN timestamp >= %s AND source <> 'redirect' THEN 1 ELSE 0 END) AS trackable,
				SUM(CASE WHEN timestamp >= %s AND clicks > 0 THEN 1 ELSE 0 END) AS clicked,
				AVG(CASE WHEN click_position > 0 THEN click_position END) AS avg_position,
				SUM(CASE WHEN add_to_cart > 0 THEN 1 ELSE 0 END) AS carts,
				COUNT(DISTINCT CASE WHEN order_id > 0 THEN order_id END) AS orders,
				SUM(revenue) AS revenue,
				SUM(auto_fixed) AS rescued
				FROM $t WHERE timestamp >= %s AND timestamp <= %s",
				$since,
				$since,
				$start . ' 00:00:00',
				$end . ' 23:59:59'
			),
			ARRAY_A
		);
		$row              = array_map( 'floatval', (array) $row );
		$row['zero_rate'] = $row['total'] > 0 ? round( 100 * $row['zero'] / $row['total'], 1 ) : 0;
		$row['ctr']       = $row['trackable'] > 0 ? round( 100 * $row['clicked'] / $row['trackable'], 1 ) : 0;
		$row['live_rate'] = $row['total'] > 0 ? round( 100 * $row['live'] / $row['total'], 1 ) : 0;
		return $row;
	}

	/**
	 * Full dashboard statistics (cached for 10 minutes).
	 *
	 * @param int $days Days.
	 * @return array
	 */
	public static function get_stats( $days = 30 ) {
		global $wpdb;
		$p   = self::period( $days );
		$key = 'asl_stats_' . md5( wp_json_encode( $p ) . get_option( 'asl_cache_ver', '' ) );
		$hit = get_transient( $key );
		if ( is_array( $hit ) ) {
			return $hit;
		}

		$t     = asl_table( 'logs' );
		$range = $wpdb->prepare( 'timestamp >= %s AND timestamp <= %s', $p['start'] . ' 00:00:00', $p['end'] . ' 23:59:59' );
		$prevr = $wpdb->prepare( 'timestamp >= %s AND timestamp <= %s', $p['prev_start'] . ' 00:00:00', $p['prev_end'] . ' 23:59:59' );

		$kpis = self::kpis( $p['start'], $p['end'] );
		$prev = self::kpis( $p['prev_start'], $p['prev_end'] );

		// Daily trend with zero-filled gaps.
		$rows  = $wpdb->get_results( "SELECT DATE(timestamp) AS d, COUNT(*) AS c, SUM(CASE WHEN result_count = 0 THEN 1 ELSE 0 END) AS z FROM $t WHERE $range GROUP BY DATE(timestamp)", ARRAY_A );
		$by    = array();
		foreach ( $rows as $r ) {
			$by[ substr( $r['d'], 0, 10 ) ] = $r;
		}
		$daily = array();
		for ( $i = 0; $i < $p['days']; $i++ ) {
			$d       = gmdate( 'Y-m-d', strtotime( $p['start'] . ' 12:00:00 UTC' ) + $i * DAY_IN_SECONDS );
			$daily[] = array(
				'date'  => $d,
				'count' => isset( $by[ $d ] ) ? (int) $by[ $d ]['c'] : 0,
				'zero'  => isset( $by[ $d ] ) ? (int) $by[ $d ]['z'] : 0,
			);
		}

		// Hour of day.
		$hours = array_fill( 0, 24, 0 );
		foreach ( $wpdb->get_results( "SELECT HOUR(timestamp) AS h, COUNT(*) AS c FROM $t WHERE $range GROUP BY HOUR(timestamp)", ARRAY_A ) as $r ) {
			$hours[ (int) $r['h'] ] = (int) $r['c'];
		}

		// Sources.
		$sources = array();
		foreach ( $wpdb->get_results( "SELECT source, COUNT(*) AS c FROM $t WHERE $range GROUP BY source ORDER BY c DESC", ARRAY_A ) as $r ) {
			$sources[] = array(
				'source' => $r['source'],
				'label'  => asl_source_label( $r['source'] ),
				'count'  => (int) $r['c'],
			);
		}

		// Top terms.
		$top = $wpdb->get_results(
			"SELECT term_norm AS term, COUNT(*) AS count,
			AVG(CASE WHEN result_count >= 0 THEN result_count END) AS avg_results,
			SUM(CASE WHEN result_count = 0 THEN 1 ELSE 0 END) AS zero,
			SUM(CASE WHEN clicks > 0 THEN 1 ELSE 0 END) AS clicked,
			SUM(revenue) AS revenue
			FROM $t WHERE $range GROUP BY term_norm ORDER BY count DESC LIMIT 15",
			ARRAY_A
		);

		// No-result terms (minus the ones marked as resolved, unless they failed again afterwards).
		$resolved = (array) get_option( 'asl_resolved_terms', array() );
		$zero     = array();
		foreach ( $wpdb->get_results( "SELECT term_norm AS term, COUNT(*) AS count, MAX(timestamp) AS last FROM $t WHERE $range AND result_count = 0 GROUP BY term_norm ORDER BY count DESC LIMIT 60", ARRAY_A ) as $r ) {
			if ( isset( $resolved[ $r['term'] ] ) && $r['last'] <= $resolved[ $r['term'] ] ) {
				continue;
			}
			$zero[] = $r;
			if ( count( $zero ) >= 15 ) {
				break;
			}
		}
		$suggest = self::suggest_alternatives( wp_list_pluck( $zero, 'term' ) );
		foreach ( $zero as &$z ) {
			$z['suggestion'] = isset( $suggest[ $z['term'] ] ) ? $suggest[ $z['term'] ] : '';
		}
		unset( $z );

		// Trending: compared with the previous period of equal length.
		$cur_counts  = $wpdb->get_results( "SELECT term_norm AS term, COUNT(*) AS c FROM $t WHERE $range GROUP BY term_norm HAVING c >= 3 ORDER BY c DESC LIMIT 200", ARRAY_A );
		$prev_counts = array();
		if ( $cur_counts ) {
			$terms        = wp_list_pluck( $cur_counts, 'term' );
			$placeholders = implode( ',', array_fill( 0, count( $terms ), '%s' ) );
			$sql          = $wpdb->prepare( "SELECT term_norm AS term, COUNT(*) AS c FROM $t WHERE $prevr AND term_norm IN ($placeholders) GROUP BY term_norm", $terms );
			foreach ( $wpdb->get_results( $sql, ARRAY_A ) as $r ) {
				$prev_counts[ $r['term'] ] = (int) $r['c'];
			}
		}
		$trending = array();
		foreach ( $cur_counts as $r ) {
			$now  = (int) $r['c'];
			$then = isset( $prev_counts[ $r['term'] ] ) ? $prev_counts[ $r['term'] ] : 0;
			if ( $now >= 3 && $now >= $then * 1.5 && $now - $then >= 2 ) {
				$trending[] = array(
					'term'   => $r['term'],
					'count'  => $now,
					'prev'   => $then,
					'growth' => $then > 0 ? round( 100 * ( $now - $then ) / $then ) : null,
					'score'  => ( $now - $then ) * ( $then > 0 ? 1 : 0.8 ),
				);
			}
		}
		usort(
			$trending,
			function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);
		$trending = array_slice( $trending, 0, 10 );

		// Most clicked results.
		$c       = asl_table( 'clicks' );
		$clicked = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, MAX(url) AS url, COUNT(*) AS count, AVG(position) AS avg_position FROM $c WHERE timestamp >= %s AND timestamp <= %s GROUP BY post_id, CASE WHEN post_id = 0 THEN url ELSE '' END ORDER BY count DESC LIMIT 10",
				$p['start'] . ' 00:00:00',
				$p['end'] . ' 23:59:59'
			),
			ARRAY_A
		);
		foreach ( $clicked as &$row ) {
			$row['title'] = $row['post_id'] ? get_the_title( (int) $row['post_id'] ) : '';
			if ( '' === $row['title'] ) {
				$row['title'] = urldecode( (string) wp_parse_url( $row['url'], PHP_URL_PATH ) );
			}
		}
		unset( $row );

		$stats = array(
			'period'   => $p,
			'kpis'     => $kpis,
			'prev'     => $prev,
			'daily'    => $daily,
			'hours'    => $hours,
			'sources'  => $sources,
			'top'      => $top,
			'zero'     => $zero,
			'trending' => $trending,
			'clicked'  => $clicked,
		);
		set_transient( $key, $stats, 10 * MINUTE_IN_SECONDS );
		return $stats;
	}

	/**
	 * For failed searches, find a similar search that did return results ("did you mean").
	 *
	 * @param string[] $terms Normalized failed terms.
	 * @return array term => suggestion
	 */
	public static function suggest_alternatives( array $terms ) {
		global $wpdb;
		if ( ! $terms ) {
			return array();
		}
		$t    = asl_table( 'logs' );
		$good = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT term_norm AS term, COUNT(*) AS c FROM $t WHERE timestamp >= %s AND result_count > 0 GROUP BY term_norm ORDER BY c DESC LIMIT 600",
				asl_now( -120 * DAY_IN_SECONDS )
			),
			ARRAY_A
		);
		$out  = array();
		foreach ( $terms as $bad ) {
			$len       = ASL_Normalizer::length( $bad );
			$max       = $len <= 4 ? 1 : ( $len <= 9 ? 2 : 3 );
			$best      = '';
			$best_dist = PHP_INT_MAX;
			$bad_nosp  = str_replace( ' ', '', $bad );
			foreach ( $good as $g ) {
				$cand = $g['term'];
				if ( $cand === $bad ) {
					continue;
				}
				// Same words written with/without spaces (e.g. "شیرآلات" vs "شیر آلات").
				if ( str_replace( ' ', '', $cand ) === $bad_nosp ) {
					$best = $cand;
					break;
				}
				if ( abs( ASL_Normalizer::length( $cand ) - $len ) > $max ) {
					continue;
				}
				$d = ASL_Normalizer::distance( $bad, $cand );
				if ( $d <= $max && $d < $best_dist ) {
					$best      = $cand;
					$best_dist = $d;
				}
			}
			if ( '' !== $best ) {
				$out[ $bad ] = $best;
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ *
	 * Rules (synonyms & redirects)
	 * ------------------------------------------------------------------ */

	/**
	 * All active rules (cached).
	 *
	 * @return array
	 */
	public static function active_rules() {
		global $wpdb;
		$rules = wp_cache_get( 'asl_rules', 'asl' );
		if ( false === $rules ) {
			$rules = get_transient( 'asl_rules' );
		}
		if ( ! is_array( $rules ) ) {
			$rules = $wpdb->get_results( 'SELECT id, term, type, match_type, target FROM ' . asl_table( 'rules' ) . ' WHERE status = 1', ARRAY_A );
			$rules = is_array( $rules ) ? $rules : array();
			set_transient( 'asl_rules', $rules, DAY_IN_SECONDS );
		}
		wp_cache_set( 'asl_rules', $rules, 'asl' );
		return $rules;
	}

	/**
	 * Flush the rules cache.
	 */
	public static function flush_rules() {
		delete_transient( 'asl_rules' );
		wp_cache_delete( 'asl_rules', 'asl' );
	}

	/**
	 * Get a rule.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function get_rule( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . asl_table( 'rules' ) . ' WHERE id = %d', $id ), ARRAY_A );
	}

	/**
	 * Find a rule by term (exact, case-insensitive per collation).
	 *
	 * @param string $term Term.
	 * @return array|null
	 */
	public static function get_rule_by_term( $term ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . asl_table( 'rules' ) . ' WHERE term = %s', $term ), ARRAY_A );
	}

	/**
	 * Insert or update a rule.
	 *
	 * @param array $data term, type, match_type, target, status.
	 * @param int   $id   Rule ID for updates.
	 * @return int|WP_Error Rule ID.
	 */
	public static function save_rule( array $data, $id = 0 ) {
		global $wpdb;
		$row = array(
			'term'       => ASL_Normalizer::truncate( trim( (string) $data['term'] ), 191 ),
			'type'       => in_array( $data['type'], array( 'synonym', 'redirect' ), true ) ? $data['type'] : 'synonym',
			'match_type' => in_array( $data['match_type'], array( 'exact', 'contains', 'starts' ), true ) ? $data['match_type'] : 'exact',
			'target'     => (string) $data['target'],
			'status'     => empty( $data['status'] ) ? 0 : 1,
		);
		if ( '' === $row['term'] || '' === $row['target'] ) {
			return new WP_Error( 'asl_empty', __( 'Both the search term and the target are required.', 'advanced-search-logger' ) );
		}
		$existing = self::get_rule_by_term( $row['term'] );
		if ( $existing && (int) $existing['id'] !== (int) $id ) {
			/* translators: %s: search term */
			return new WP_Error( 'asl_duplicate', sprintf( __( 'A rule for "%s" already exists. Edit that rule instead.', 'advanced-search-logger' ), $row['term'] ) );
		}
		if ( $id ) {
			$ok = $wpdb->update( asl_table( 'rules' ), $row, array( 'id' => (int) $id ) );
		} else {
			$row['created'] = asl_now();
			$ok             = $wpdb->insert( asl_table( 'rules' ), $row );
			$id             = (int) $wpdb->insert_id;
		}
		self::flush_rules();
		if ( false === $ok ) {
			return new WP_Error( 'asl_db', __( 'The rule could not be saved.', 'advanced-search-logger' ) . ' ' . $wpdb->last_error );
		}
		// A rule for a failing search means it has been dealt with.
		self::resolve_terms( array( ASL_Normalizer::normalize( $row['term'] ) ) );
		return (int) $id;
	}

	/**
	 * Delete rules.
	 *
	 * @param int[] $ids IDs.
	 */
	public static function delete_rules( array $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( $ids ) {
			$wpdb->query( 'DELETE FROM ' . asl_table( 'rules' ) . ' WHERE id IN (' . implode( ',', $ids ) . ')' );
		}
		self::flush_rules();
	}

	/**
	 * Enable/disable rules.
	 *
	 * @param int[] $ids    IDs.
	 * @param int   $status 1|0.
	 */
	public static function set_rules_status( array $ids, $status ) {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( $ids ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . asl_table( 'rules' ) . ' SET status = %d WHERE id IN (' . implode( ',', $ids ) . ')', $status ? 1 : 0 ) );
		}
		self::flush_rules();
	}

	/**
	 * Count a rule hit.
	 *
	 * @param int $id Rule ID.
	 */
	public static function rule_hit( $id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . asl_table( 'rules' ) . ' SET hits = hits + 1, last_hit = %s WHERE id = %d', asl_now(), $id ) );
	}

	/**
	 * Rules for the admin list.
	 *
	 * @param array $args search, type, orderby, order, per_page, page.
	 * @return array
	 */
	public static function list_rules( array $args ) {
		global $wpdb;
		$args    = wp_parse_args(
			$args,
			array(
				'search'   => '',
				'type'     => '',
				'orderby'  => 'id',
				'order'    => 'DESC',
				'per_page' => 20,
				'page'     => 1,
			)
		);
		$allowed = array( 'id', 'term', 'type', 'hits', 'last_hit', 'status', 'match_type' );
		$orderby = in_array( $args['orderby'], $allowed, true ) ? $args['orderby'] : 'id';
		$order   = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';
		$sql     = 'SELECT * FROM ' . asl_table( 'rules' ) . self::rules_where( $args ) . " ORDER BY $orderby $order";
		if ( $args['per_page'] > 0 ) {
			$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $args['per_page'], max( 0, ( $args['page'] - 1 ) * $args['per_page'] ) );
		}
		return $wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * Count rules.
	 *
	 * @param array $args Args.
	 * @return int
	 */
	public static function count_rules( array $args = array() ) {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . asl_table( 'rules' ) . self::rules_where( $args ) );
	}

	/**
	 * Rules WHERE.
	 *
	 * @param array $args Args.
	 * @return string
	 */
	private static function rules_where( array $args ) {
		global $wpdb;
		$w = array( '1=1' );
		if ( ! empty( $args['search'] ) ) {
			$like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$w[]  = $wpdb->prepare( '(term LIKE %s OR target LIKE %s)', $like, $like );
		}
		if ( ! empty( $args['type'] ) ) {
			$w[] = $wpdb->prepare( 'type = %s', $args['type'] );
		}
		return ' WHERE ' . implode( ' AND ', $w );
	}

	/* ------------------------------------------------------------------ *
	 * Resolved failed searches
	 * ------------------------------------------------------------------ */

	/**
	 * Mark failed terms as handled. They come back if they fail again later.
	 *
	 * @param string[] $terms Normalized terms.
	 * @param bool     $undo  Remove the mark instead.
	 */
	public static function resolve_terms( array $terms, $undo = false ) {
		$resolved = (array) get_option( 'asl_resolved_terms', array() );
		foreach ( $terms as $term ) {
			if ( $undo ) {
				unset( $resolved[ $term ] );
			} else {
				$resolved[ $term ] = asl_now();
			}
		}
		// Keep the option small.
		if ( count( $resolved ) > 2000 ) {
			asort( $resolved );
			$resolved = array_slice( $resolved, -2000, null, true );
		}
		update_option( 'asl_resolved_terms', $resolved, false );
		ASL_Activator::bump_cache();
	}

	/* ------------------------------------------------------------------ *
	 * Clicks
	 * ------------------------------------------------------------------ */

	/**
	 * Store a click and update the log.
	 *
	 * @param int    $log_id   Log ID.
	 * @param int    $post_id  Post ID (0 if unknown).
	 * @param string $url      URL.
	 * @param int    $position Position in the result list (1-based, 0 unknown).
	 */
	public static function add_click( $log_id, $post_id, $url, $position ) {
		global $wpdb;
		$wpdb->insert(
			asl_table( 'clicks' ),
			array(
				'log_id'    => (int) $log_id,
				'post_id'   => (int) $post_id,
				'url'       => substr( (string) $url, 0, 500 ),
				'position'  => min( 65535, max( 0, (int) $position ) ),
				'timestamp' => asl_now(),
			),
			array( '%d', '%d', '%s', '%d', '%s' )
		);
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . asl_table( 'logs' ) . ' SET clicks = LEAST(clicks + 1, 65535), click_position = CASE WHEN click_position = 0 THEN %d ELSE click_position END WHERE id = %d',
				min( 65535, max( 0, (int) $position ) ),
				$log_id
			)
		);
	}

	/**
	 * Clicks recorded for a log (to rate-limit).
	 *
	 * @param int $log_id Log ID.
	 * @return int
	 */
	public static function clicks_for_log( $log_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . asl_table( 'clicks' ) . ' WHERE log_id = %d', $log_id ) );
	}

	/**
	 * Most popular successful searches (for the front-end shortcode).
	 *
	 * @param int $days      Days.
	 * @param int $limit     Limit.
	 * @param int $min_count Minimum searches.
	 * @return array
	 */
	public static function popular_terms( $days, $limit, $min_count = 2 ) {
		global $wpdb;
		$t = asl_table( 'logs' );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT term_norm AS term, MAX(term) AS sample, COUNT(*) AS c FROM $t WHERE timestamp >= %s AND result_count > 0 AND source <> 'redirect' GROUP BY term_norm HAVING c >= %d ORDER BY c DESC LIMIT %d",
				asl_now( -$days * DAY_IN_SECONDS ),
				$min_count,
				$limit
			),
			ARRAY_A
		);
	}
}
