<?php
/**
 * Hooks into WP_Query to apply rules and log searches (results page, AJAX/live search, REST).
 *
 * Works with any theme or plugin whose search runs through WP_Query: default WordPress search,
 * WooCommerce, WoodMart, Flatsome, Astra, Elementor, FiboSearch (WP_Query mode), Relevanssi, etc.
 *
 * @package WebiSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASL_Tracker {

	/**
	 * Queries waiting for their results, keyed by spl_object_id.
	 *
	 * @var array
	 */
	private $pending = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'pre_get_posts', array( $this, 'on_pre_get_posts' ), 5 );
		add_filter( 'the_posts', array( $this, 'on_the_posts' ), 999, 2 );
		add_action( 'template_redirect', array( $this, 'maybe_disable_cache' ), 0 );
		// Queries run with suppress_filters skip the_posts; log them at the end of the request.
		add_action( 'shutdown', array( $this, 'flush_pending' ), 0 );
	}

	/**
	 * Where does this request come from?
	 *
	 * @return string standard|ajax|rest
	 */
	public static function request_source() {
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( function_exists( 'wp_is_serving_rest_request' ) && wp_is_serving_rest_request() ) ) {
			return 'rest';
		}
		if ( wp_doing_ajax() || isset( $_GET['wc-ajax'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return 'ajax';
		}
		if ( isset( $_SERVER['HTTP_X_REQUESTED_WITH'] ) && 'xmlhttprequest' === strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REQUESTED_WITH'] ) ) ) ) {
			return 'ajax';
		}
		return 'standard';
	}

	/**
	 * Is this a request made from inside wp-admin (editor link search, menus, product pickers...)?
	 * Those searches are by staff, not visitors, and must not be logged or rewritten.
	 *
	 * @return bool
	 */
	private static function is_backend_request() {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return true;
		}
		if ( wp_doing_ajax() ) {
			$action  = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$backend = apply_filters( 'asl_backend_ajax_actions', array( 'wp-link-ajax', 'menu-quick-search', 'query-attachments', 'find_posts', 'inline-save', 'heartbeat', 'woocommerce_json_search_products', 'woocommerce_json_search_products_and_variations', 'woocommerce_json_search_pages', 'elementor_ajax' ) );
			if ( in_array( $action, $backend, true ) ) {
				return true;
			}
			$referer = wp_get_raw_referer();
			if ( $referer && 0 === strpos( $referer, admin_url() ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Should a query with a search term be tracked?
	 *
	 * @param WP_Query $q      Query.
	 * @param string   $source Request source.
	 * @return bool
	 */
	private function is_trackable( $q, $source ) {
		if ( $q->is_feed() || self::is_backend_request() ) {
			return false;
		}
		if ( 'rest' === $source && ! ASL_Settings::on( 'log_rest' ) ) {
			return false;
		}
		// On normal page loads only the main query is the visitor's search; in AJAX/REST the theme's query is.
		$ok = 'standard' === $source ? $q->is_main_query() : true;
		return (bool) apply_filters( 'asl_track_query', $ok, $q, $source );
	}

	/**
	 * Apply rules and remember the query for logging.
	 *
	 * @param WP_Query $q Query.
	 */
	public function on_pre_get_posts( $q ) {
		$s = $q->get( 's' );
		if ( ! is_string( $s ) || '' === trim( $s ) || $q->get( 'asl_skip' ) ) {
			return;
		}
		$source = self::request_source();
		if ( ! $this->is_trackable( $q, $source ) ) {
			return;
		}

		$original = wp_unslash( $s );
		$search   = $original;
		$rewrite  = '';
		$paged    = max( 1, (int) $q->get( 'paged' ) );

		// 1. Fix Arabic ي/ك, tatweel, diacritics and invisible characters so Persian content is found.
		if ( ASL_Settings::on( 'normalize_query' ) ) {
			$fixed = ASL_Smart::fix_madda( ASL_Normalizer::fix_query( $search ) );
			if ( $fixed !== $search && '' !== $fixed ) {
				$search = $fixed;
			}
		}

		// 2. Synonyms & redirects.
		$rule = ASL_Rules::match( $search );
		if ( $rule ) {
			if ( 'redirect' === $rule['type'] ) {
				if ( 'standard' === $source && $q->is_main_query() && ! headers_sent() ) {
					ASL_DB::rule_hit( $rule['id'] );
					if ( 1 === $paged ) {
						ASL_Logger::instance()->log(
							$original,
							-1,
							array(
								'source'    => 'redirect',
								'rewritten' => $rule['result'],
							)
						);
					}
					// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- admins may redirect to other domains on purpose.
					wp_redirect( esc_url_raw( $rule['result'] ), (int) apply_filters( 'asl_redirect_status', 302 ), 'WebiSearch' );
					exit;
				}
				// Live search cannot redirect; let it show normal results.
			} else {
				$search  = $rule['result'];
				$rewrite = $rule['result'];
				if ( 1 === $paged ) {
					ASL_DB::rule_hit( $rule['id'] );
				}
			}
		}

		if ( $search !== $original ) {
			$q->set( 's', $search );
		}

		$post_type = $q->get( 'post_type' );
		$this->pending[ spl_object_id( $q ) ] = array(
			'term'       => $original,
			'rewritten'  => $rewrite,
			'source'     => $source,
			'post_type'  => is_array( $post_type ) ? implode( ',', $post_type ) : (string) $post_type,
			'query'      => $q,
			// Page 2, 3... of the same search is not logged again (but may still need the smart rescue).
			'nolog'      => $paged > 1,
			'auto_fixed' => 0,
		);
	}

	/**
	 * Log once results are known.
	 *
	 * @param WP_Post[] $posts Posts.
	 * @param WP_Query  $q     Query.
	 * @return WP_Post[]
	 */
	public function on_the_posts( $posts, $q ) {
		if ( ! $q instanceof WP_Query ) {
			return $posts;
		}
		$key = spl_object_id( $q );
		if ( ! isset( $this->pending[ $key ] ) ) {
			return $posts;
		}
		$ctx = $this->pending[ $key ];
		unset( $this->pending[ $key ] );

		// Nothing found: try the smart rescue (clean-up, spelling, fewer words).
		if ( empty( $posts ) ) {
			$rescued = ASL_Smart::rescue( $q, $ctx['source'] );
			if ( $rescued ) {
				$posts             = $rescued['posts'];
				$ctx['rewritten']  = $rescued['term'];
				$ctx['auto_fixed'] = 1;
			}
		}
		$this->log_query( $ctx, $q, $posts, true );
		return $posts;
	}

	/**
	 * Log searches whose the_posts filter never ran.
	 */
	public function flush_pending() {
		foreach ( $this->pending as $key => $ctx ) {
			unset( $this->pending[ $key ] );
			$q = $ctx['query'];
			if ( is_array( $q->posts ) ) {
				$this->log_query( $ctx, $q, $q->posts, false );
			}
		}
	}

	/**
	 * Write the log for a finished query.
	 *
	 * @param array    $ctx         Context from pre_get_posts.
	 * @param WP_Query $q           Query.
	 * @param array    $posts       Posts.
	 * @param bool     $can_cookie  Headers can still be sent.
	 */
	private function log_query( $ctx, $q, $posts, $can_cookie ) {
		unset( $ctx['query'] );
		if ( ! empty( $ctx['nolog'] ) ) {
			return;
		}

		// found_posts is 0 when the query used no_found_rows (common in live search); fall back to the visible count.
		$count = max( (int) $q->found_posts, is_array( $posts ) ? count( $posts ) : 0 );
		$count = (int) apply_filters( 'asl_result_count', $count, $q, $posts );

		$id = ASL_Logger::instance()->log( $ctx['term'], $count, $ctx );
		if ( $id && $can_cookie ) {
			ASL_Clicks::remember_search( $id );
		}
	}

	/**
	 * Page caches (LiteSpeed, WP Rocket, W3TC, WP Super Cache, WP Fastest Cache...) would serve
	 * search pages without running PHP, so those searches would never be logged.
	 */
	public function maybe_disable_cache() {
		if ( ! is_search() || ! ASL_Settings::on( 'nocache_search' ) ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		do_action( 'litespeed_control_set_nocache', 'WebiSearch: search results are logged' );
		if ( ! headers_sent() ) {
			nocache_headers();
		}
	}
}
