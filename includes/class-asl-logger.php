<?php
/**
 * Decides whether a search is logged, merges as-you-type searches and writes the log.
 *
 * @package WebiSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASL_Logger {

	/**
	 * Singleton.
	 *
	 * @var ASL_Logger|null
	 */
	private static $instance = null;

	/**
	 * Log IDs written in this request, by normalized term (avoids double logging when a page runs several search queries).
	 *
	 * @var array
	 */
	private $request_logs = array();

	/**
	 * Last log ID of this request.
	 *
	 * @var int
	 */
	public $last_log_id = 0;

	/**
	 * Get instance.
	 *
	 * @return ASL_Logger
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Log a search.
	 *
	 * @param string $term         Typed term.
	 * @param int    $result_count Results (-1 = not applicable, e.g. redirected).
	 * @param array  $args         source, post_type, rewritten.
	 * @return int|false Log ID or false.
	 */
	public function log( $term, $result_count, array $args = array() ) {
		$term = ASL_Normalizer::fix_query( sanitize_text_field( (string) $term ) );
		$norm = ASL_Normalizer::normalize( $term );
		$src  = isset( $args['source'] ) ? sanitize_key( $args['source'] ) : 'standard';

		if ( isset( $this->request_logs[ $norm ] ) ) {
			return $this->request_logs[ $norm ];
		}
		if ( ! $this->should_log( $term, $norm ) ) {
			return false;
		}

		$user_id = get_current_user_id();
		$visitor = $this->visitor_hash();
		$data    = array(
			'term'         => ASL_Normalizer::truncate( $term, (int) ASL_Settings::get( 'max_length', 100 ) ),
			'term_norm'    => $norm,
			'rewritten'    => isset( $args['rewritten'] ) ? self::clean_rewritten( $args['rewritten'] ) : '',
			'user_id'      => ASL_Settings::on( 'store_user' ) ? $user_id : 0,
			'visitor'      => $visitor,
			'timestamp'    => asl_now(),
			'result_count' => (int) $result_count,
			'source'       => $src,
			'post_type'    => isset( $args['post_type'] ) ? substr( sanitize_key( $args['post_type'] ), 0, 60 ) : '',
			'auto_fixed'   => empty( $args['auto_fixed'] ) ? 0 : 1,
		);
		$data    = apply_filters( 'asl_log_data', $data, $args );
		if ( ! $data ) {
			return false;
		}

		$id = $this->merge_with_recent( $data );
		if ( ! $id ) {
			$id = ASL_DB::insert_log( $data );
		}
		if ( $id ) {
			$this->request_logs[ $norm ] = $id;
			$this->last_log_id           = $id;
			do_action( 'asl_search_logged', $id, $data );
		}
		return $id ? $id : false;
	}

	/**
	 * Clean the rewritten term / redirect URL (sanitize_text_field() would strip %xx from Persian URLs).
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function clean_rewritten( $value ) {
		$value = (string) $value;
		// URLs are stored decoded (display only) so Persian slugs fit in 191 characters.
		$value = preg_match( '#^https?://#i', $value ) ? rawurldecode( esc_url_raw( $value ) ) : sanitize_text_field( $value );
		return ASL_Normalizer::truncate( $value, 191 );
	}

	/**
	 * As-you-type aggregation (same idea as Algolia's 30s query aggregation):
	 * - the same term from the same visitor within the window is counted once;
	 * - a live-search keystroke sequence ("پم" > "پمپ" > "پمپ آب") is folded into one row holding the final term.
	 *
	 * @param array $data Log data.
	 * @return int Existing log ID that absorbed this search, or 0.
	 */
	private function merge_with_recent( array $data ) {
		$window = (int) ASL_Settings::get( 'dedupe_window', 30 );
		if ( $window <= 0 || '' === $data['visitor'] ) {
			return 0;
		}
		$last = ASL_DB::last_log_of_visitor( $data['visitor'], asl_now( -$window ) );
		if ( ! $last ) {
			return 0;
		}
		if ( $last->term_norm === $data['term_norm'] ) {
			// Pressing Enter after a live search: keep one row, but prefer the results page numbers.
			if ( 'standard' === $data['source'] && 'ajax' === $last->source ) {
				ASL_DB::update_log(
					$last->id,
					array(
						'source'       => 'standard',
						'result_count' => $data['result_count'],
						'rewritten'    => $data['rewritten'],
					)
				);
			}
			return (int) $last->id;
		}
		$typing = 'ajax' === $data['source'] && 'ajax' === $last->source;
		$prefix = 0 === strpos( $data['term_norm'], $last->term_norm ) || 0 === strpos( $last->term_norm, $data['term_norm'] );
		if ( $typing && $prefix ) {
			ASL_DB::update_log( $last->id, $data );
			return (int) $last->id;
		}
		return 0;
	}

	/**
	 * Exclusion rules.
	 *
	 * @param string $term Term.
	 * @param string $norm Normalized term.
	 * @return bool
	 */
	public function should_log( $term, $norm ) {
		$ok = true;
		if ( ! ASL_Settings::on( 'logging_enabled' ) || '' === $norm || '-' === $norm ) {
			$ok = false;
		} elseif ( ASL_Normalizer::length( $norm ) < max( 1, (int) ASL_Settings::get( 'min_length', 2 ) ) ) {
			$ok = false;
		} elseif ( ASL_Settings::on( 'ignore_spam' ) && ASL_Normalizer::looks_like_spam( $term ) ) {
			$ok = false;
		} elseif ( ASL_Settings::on( 'ignore_bots' ) && $this->is_bot() ) {
			$ok = false;
		} elseif ( $this->is_excluded_user() ) {
			$ok = false;
		} elseif ( $this->is_excluded_ip() ) {
			$ok = false;
		} else {
			foreach ( ASL_Settings::lines( 'exclude_terms' ) as $pattern ) {
				if ( ASL_Normalizer::wildcard_match( $pattern, $norm ) ) {
					$ok = false;
					break;
				}
			}
		}
		/**
		 * Final say on logging a search.
		 *
		 * @param bool   $ok   Log it?
		 * @param string $term Term as typed.
		 * @param string $norm Normalized term.
		 */
		return (bool) apply_filters( 'asl_should_log', $ok, $term, $norm );
	}

	/**
	 * Crawlers, uptime monitors, scripts.
	 *
	 * @return bool
	 */
	public function is_bot() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) ) : '';
		if ( '' === $ua ) {
			return true;
		}
		$pattern = '/bot|crawl|spider|slurp|mediapartners|bingpreview|facebookexternalhit|embedly|quora link|outbrain|pinterest|semrush|ahrefs|mj12|dotbot|petalbot|yandex|baidu|bytespider|gptbot|claude|perplexity|headless|phantom|lighthouse|pagespeed|gtmetrix|pingdom|uptime|monitor|python|curl|wget|java\/|go-http|okhttp|axios|node-fetch|scrapy|httpclient|libwww|feed|preview/';
		return (bool) apply_filters( 'asl_is_bot', (bool) preg_match( $pattern, $ua ), $ua );
	}

	/**
	 * Excluded roles.
	 *
	 * @return bool
	 */
	private function is_excluded_user() {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$roles = (array) ASL_Settings::get( 'exclude_roles', array() );
		if ( ! $roles ) {
			return false;
		}
		$user = wp_get_current_user();
		return (bool) array_intersect( $roles, (array) $user->roles );
	}

	/**
	 * Excluded IPs (exact, wildcard "1.2.3.*" or CIDR "1.2.3.0/24").
	 *
	 * @return bool
	 */
	private function is_excluded_ip() {
		$list = ASL_Settings::lines( 'exclude_ips' );
		if ( ! $list ) {
			return false;
		}
		$ip = asl_client_ip();
		foreach ( $list as $rule ) {
			if ( $rule === $ip ) {
				return true;
			}
			if ( false !== strpos( $rule, '*' ) && preg_match( '/^' . str_replace( '\*', '.*', preg_quote( $rule, '/' ) ) . '$/', $ip ) ) {
				return true;
			}
			if ( false !== strpos( $rule, '/' ) && self::ip_in_cidr( $ip, $rule ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * IPv4/IPv6 CIDR check.
	 *
	 * @param string $ip   IP.
	 * @param string $cidr Range.
	 * @return bool
	 */
	private static function ip_in_cidr( $ip, $cidr ) {
		list( $subnet, $bits ) = array_pad( explode( '/', $cidr, 2 ), 2, '' );
		$ip_bin                = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$net_bin               = @inet_pton( $subnet ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $ip_bin || false === $net_bin || strlen( $ip_bin ) !== strlen( $net_bin ) ) {
			return false;
		}
		$bits = (int) $bits;
		$len  = strlen( $ip_bin );
		for ( $i = 0; $i < $len && $bits > 0; $i++, $bits -= 8 ) {
			$mask = $bits >= 8 ? 0xFF : ( 0xFF << ( 8 - $bits ) ) & 0xFF;
			if ( ( ord( $ip_bin[ $i ] ) & $mask ) !== ( ord( $net_bin[ $i ] ) & $mask ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Anonymous, non-reversible visitor ID (IP and user agent are never stored).
	 *
	 * @return string 16 hex chars.
	 */
	public function visitor_hash() {
		$uid  = get_current_user_id();
		$seed = $uid ? 'user:' . $uid : 'ip:' . asl_client_ip() . '|' . ( isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '' );
		return substr( hash_hmac( 'sha256', $seed, wp_salt( 'nonce' ) ), 0, 16 );
	}
}
