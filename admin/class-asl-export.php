<?php
/**
 * CSV export (logs, per-term report, rules) and rules import.
 *
 * v2 bug: export_to_csv() was an empty stub, so the button only reloaded the page.
 * Files are UTF-8 with BOM so Excel shows Persian text correctly, streamed in batches
 * (works with hundreds of thousands of rows) and protected against CSV formula injection.
 *
 * @package WebiSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASL_Export {

	/**
	 * Handle admin-post.php?action=asl_export.
	 */
	public static function handle() {
		if ( ! current_user_can( asl_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to export.', 'advanced-search-logger' ), 403 );
		}
		check_admin_referer( 'asl_export' );

		$type    = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : 'logs';
		$filters = ASL_DB::filters_from_request( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked above.
		$names   = array(
			'logs'  => 'search-logs',
			'terms' => 'search-terms',
			'rules' => 'search-rules',
			'review' => 'failed-searches-review',
			'requests' => 'product-requests',
		);
		if ( ! isset( $names[ $type ] ) ) {
			$type = 'logs';
		}

		self::send_headers( 'webisearch-' . $names[ $type ] . '-' . asl_date( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( 'terms' === $type ) {
			self::write_terms( $out, $filters );
		} elseif ( 'rules' === $type ) {
			self::write_rules( $out );
		} elseif ( 'requests' === $type ) {
			self::write_requests( $out, isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all' );
		} elseif ( 'review' === $type ) {
			self::write_review( $out, isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'missing' );
		} else {
			self::write_logs( $out, $filters );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Download headers. Clears any output buffers so stray whitespace/notices from other
	 * plugins cannot corrupt the file.
	 *
	 * @param string $filename File name.
	 */
	private static function send_headers( $filename ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
	}

	/**
	 * Prevent spreadsheet formula injection: search terms are typed by anonymous visitors.
	 *
	 * @param mixed $value Cell.
	 * @return string
	 */
	public static function cell( $value ) {
		$value = (string) $value;
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) && ! is_numeric( $value ) ) {
			$value = "'" . $value;
		}
		return $value;
	}

	/**
	 * Write one row.
	 *
	 * @param resource $fh  Handle.
	 * @param array    $row Values.
	 */
	private static function put( $fh, array $row ) {
		fputcsv( $fh, array_map( array( __CLASS__, 'cell' ), $row ), ',', '"', '\\' );
	}

	/**
	 * Product requests.
	 *
	 * @param resource $fh     Handle.
	 * @param string   $status Status filter.
	 */
	public static function write_requests( $fh, $status ) {
		fwrite( $fh, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		$statuses = ASL_Requests::statuses();
		self::put(
			$fh,
			array(
				'ID',
				__( 'Product searched', 'advanced-search-logger' ),
				__( 'Name', 'advanced-search-logger' ),
				__( 'Phone', 'advanced-search-logger' ),
				__( 'Status', 'advanced-search-logger' ),
				__( 'Note', 'advanced-search-logger' ),
				__( 'Date', 'advanced-search-logger' ),
				__( 'Page', 'advanced-search-logger' ),
			)
		);
		foreach ( ASL_Requests::query( array( 'status' => $status ), 0 ) as $r ) {
			self::put(
				$fh,
				array(
					$r['id'],
					$r['term'],
					$r['name'],
					$r['phone'],
					isset( $statuses[ $r['status'] ] ) ? $statuses[ $r['status'] ] : $r['status'],
					$r['note'],
					asl_format_date( $r['created'], true, true ),
					$r['page_url'],
				)
			);
		}
	}

	/**
	 * Raw logs.
	 *
	 * @param resource $fh      Handle.
	 * @param array    $filters Filters.
	 * @return int Rows written.
	 */
	public static function write_logs( $fh, array $filters ) {
		fwrite( $fh, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		$woo     = class_exists( 'WooCommerce' );
		$jalali  = asl_is_persian() && ASL_Settings::on( 'jalali_dates' );
		$headers = array(
			'ID',
			__( 'Search term', 'advanced-search-logger' ),
			__( 'Normalized term', 'advanced-search-logger' ),
			__( 'Searched as (synonym/redirect)', 'advanced-search-logger' ),
			__( 'Rescued automatically', 'advanced-search-logger' ),
			__( 'Results', 'advanced-search-logger' ),
			__( 'Source', 'advanced-search-logger' ),
			__( 'Post type', 'advanced-search-logger' ),
			__( 'User', 'advanced-search-logger' ),
			__( 'Date', 'advanced-search-logger' ),
		);
		if ( $jalali ) {
			$headers[] = __( 'Date (Jalali)', 'advanced-search-logger' );
		}
		$headers[] = __( 'Clicks', 'advanced-search-logger' );
		$headers[] = __( 'First click position', 'advanced-search-logger' );
		if ( $woo ) {
			$headers[] = __( 'Add to cart', 'advanced-search-logger' );
			$headers[] = __( 'Order ID', 'advanced-search-logger' );
			$headers[] = __( 'Revenue', 'advanced-search-logger' );
		}
		self::put( $fh, $headers );

		$users = array();
		$count = 0;
		ASL_DB::each_log(
			$filters,
			function ( $r ) use ( $fh, $woo, $jalali, &$users, &$count ) {
				$uid = (int) $r['user_id'];
				if ( $uid && ! isset( $users[ $uid ] ) ) {
					$u             = get_userdata( $uid );
					$users[ $uid ] = $u ? $u->user_login : '#' . $uid;
				}
				$row = array(
					$r['id'],
					$r['term'],
					$r['term_norm'],
					$r['rewritten'],
					! empty( $r['auto_fixed'] ) ? 1 : 0,
					(int) $r['result_count'] < 0 ? '' : $r['result_count'],
					asl_source_label( $r['source'] ),
					$r['post_type'],
					$uid ? $users[ $uid ] : __( 'Guest', 'advanced-search-logger' ),
					$r['timestamp'],
				);
				if ( $jalali ) {
					$row[] = asl_format_date( $r['timestamp'], true, true );
				}
				$row[] = $r['clicks'];
				$row[] = $r['click_position'] ? $r['click_position'] : '';
				if ( $woo ) {
					$row[] = $r['add_to_cart'];
					$row[] = $r['order_id'] ? $r['order_id'] : '';
					$row[] = (float) $r['revenue'] ? wc_format_decimal( $r['revenue'], wc_get_price_decimals() ) : '';
				}
				self::put( $fh, $row );
				$count++;
				if ( 0 === $count % 2000 ) {
					flush();
				}
			}
		);
		return $count;
	}

	/**
	 * Aggregated per-term report.
	 *
	 * @param resource $fh      Handle.
	 * @param array    $filters Filters.
	 */
	public static function write_terms( $fh, array $filters ) {
		fwrite( $fh, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		$woo = class_exists( 'WooCommerce' );
		$h   = array(
			__( 'Search term', 'advanced-search-logger' ),
			__( 'Searches', 'advanced-search-logger' ),
			__( 'Unique searchers', 'advanced-search-logger' ),
			__( 'Searches with no results', 'advanced-search-logger' ),
			__( 'Average results', 'advanced-search-logger' ),
			__( 'Searches with a click', 'advanced-search-logger' ),
			__( 'CTR %', 'advanced-search-logger' ),
		);
		if ( $woo ) {
			$h[] = __( 'Add to cart', 'advanced-search-logger' );
			$h[] = __( 'Revenue', 'advanced-search-logger' );
		}
		$h[] = __( 'Last searched', 'advanced-search-logger' );
		self::put( $fh, $h );
		foreach ( ASL_DB::get_terms_report( $filters, 0 ) as $r ) {
			$row = array( $r['term_norm'], $r['searches'], $r['visitors'], $r['zero'], null === $r['avg_results'] ? '' : round( (float) $r['avg_results'], 1 ), $r['clicked'], $r['ctr'] );
			if ( $woo ) {
				$row[] = $r['carts'];
				$row[] = (float) $r['revenue'] ? wc_format_decimal( $r['revenue'], wc_get_price_decimals() ) : '';
			}
			$row[] = $r['last_search'];
			self::put( $fh, $row );
		}
	}

	/**
	 * Failed-searches review list.
	 *
	 * @param resource $fh     Handle.
	 * @param string   $status Group.
	 */
	public static function write_review( $fh, $status ) {
		fwrite( $fh, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		$names = array(
			'missing'     => __( 'Not in your catalog', 'advanced-search-logger' ),
			'approximate' => __( 'Close matches', 'advanced-search-logger' ),
			'fixed'       => __( 'Fixed automatically', 'advanced-search-logger' ),
			'found'       => __( 'Found now', 'advanced-search-logger' ),
			'ignored'     => __( 'Ignored', 'advanced-search-logger' ),
		);
		self::put( $fh, array( __( 'Search term', 'advanced-search-logger' ), __( 'Searches', 'advanced-search-logger' ), __( 'Status', 'advanced-search-logger' ), __( 'What they see now', 'advanced-search-logger' ), __( 'Results', 'advanced-search-logger' ), __( 'Last searched', 'advanced-search-logger' ) ) );
		foreach ( ASL_Review::items( $status ) as $it ) {
			self::put( $fh, array( $it['term'], $it['searches'], isset( $names[ $status ] ) ? $names[ $status ] : $status, urldecode( $it['to'] ), $it['found'] > 0 ? $it['found'] : '', $it['last'] ) );
		}
	}

	/**
	 * Rules (re-importable).
	 *
	 * @param resource $fh Handle.
	 */
	public static function write_rules( $fh ) {
		fwrite( $fh, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		self::put( $fh, array( 'term', 'type', 'match_type', 'target', 'status', 'hits', 'last_hit' ) );
		foreach ( ASL_DB::list_rules( array( 'per_page' => 0 ) ) as $r ) {
			self::put( $fh, array( $r['term'], $r['type'], $r['match_type'], $r['target'], $r['status'], $r['hits'], $r['last_hit'] ) );
		}
	}

	/**
	 * Import rules from an uploaded CSV (term,type,match_type,target[,status]).
	 * Lines like "term,target" are accepted too (treated as exact synonyms).
	 *
	 * @param string $path Uploaded file.
	 * @return array [added, updated, skipped]
	 */
	public static function import_rules( $path ) {
		$added   = 0;
		$updated = 0;
		$skipped = 0;
		$fh      = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return array( 0, 0, 0 );
		}
		$first = true;
		while ( false !== ( $row = fgetcsv( $fh, 0, ',', '"', '\\' ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			if ( $first ) {
				$first   = false;
				$row[0]  = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $row[0] );
				if ( 'term' === strtolower( trim( $row[0] ) ) ) {
					continue;
				}
			}
			$row = array_map( 'trim', array_map( 'strval', $row ) );
			if ( count( $row ) < 2 || '' === $row[0] ) {
				$skipped++;
				continue;
			}
			if ( count( $row ) < 4 ) {
				$row = array( $row[0], 'synonym', 'exact', $row[1] );
			}
			$term   = sanitize_text_field( ltrim( $row[0], "'" ) );
			$type   = 'redirect' === $row[1] ? 'redirect' : 'synonym';
			$target = 'redirect' === $type ? ASL_Rules::sanitize_redirect_target( $row[3] ) : sanitize_text_field( $row[3] );
			$data   = array(
				'term'       => $term,
				'type'       => $type,
				'match_type' => $row[2],
				'target'     => $target,
				'status'     => isset( $row[4] ) && '0' === $row[4] ? 0 : 1,
			);
			$exists = ASL_DB::get_rule_by_term( $term );
			$res    = ASL_DB::save_rule( $data, $exists ? (int) $exists['id'] : 0 );
			if ( is_wp_error( $res ) ) {
				$skipped++;
			} elseif ( $exists ) {
				$updated++;
			} else {
				$added++;
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return array( $added, $updated, $skipped );
	}
}
