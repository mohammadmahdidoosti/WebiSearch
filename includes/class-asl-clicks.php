<?php
/**
 * Result click tracking (CTR, click position) and WooCommerce add-to-cart / revenue attribution.
 *
 * How it works, theme-agnostic:
 * 1. When a search is logged, a short-lived first-party cookie "asl_sid" holds the log ID (signed).
 * 2. A tiny script (no jQuery) listens for clicks on links inside live-search dropdowns or on the
 *    search results page and sends them with navigator.sendBeacon.
 * 3. WooCommerce add-to-cart and paid orders are attributed to the search that preceded them.
 *
 * No personal data is stored: the cookie only contains a random log ID.
 *
 * @package WebiSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASL_Clicks {

	const COOKIE      = 'asl_sid';
	const CART_COOKIE = 'asl_cart';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_asl_click', array( $this, 'ajax_click' ) );
		add_action( 'wp_ajax_nopriv_asl_click', array( $this, 'ajax_click' ) );

		// WooCommerce.
		add_action( 'woocommerce_add_to_cart', array( $this, 'on_add_to_cart' ), 10, 2 );
		add_action( 'woocommerce_checkout_order_created', array( $this, 'on_order_created' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'on_order_created' ) );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_order_status_changed' ), 10, 4 );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'order_admin_box' ) );
	}

	/* ------------------------------------------------------------------ *
	 * Signed log reference
	 * ------------------------------------------------------------------ */

	/**
	 * Sign a log ID.
	 *
	 * @param int $id Log ID.
	 * @return string
	 */
	public static function sign( $id ) {
		return (int) $id . '.' . substr( hash_hmac( 'sha256', 'asl' . (int) $id, wp_salt( 'nonce' ) ), 0, 12 );
	}

	/**
	 * Verify a signed reference.
	 *
	 * @param string $value Signed value.
	 * @return int Log ID or 0.
	 */
	public static function verify( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^(\d+)\.([a-f0-9]{12})$/', $value, $m ) ) {
			return 0;
		}
		return hash_equals( self::sign( (int) $m[1] ), $value ) ? (int) $m[1] : 0;
	}

	/**
	 * Set a first-party cookie (SameSite=Lax).
	 *
	 * @param string $name   Name.
	 * @param string $value  Value.
	 * @param int    $expire Expiry timestamp.
	 */
	private static function set_cookie( $name, $value, $expire ) {
		if ( headers_sent() ) {
			return;
		}
		$path = defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/';
		if ( PHP_VERSION_ID >= 70300 ) {
			setcookie(
				$name,
				$value,
				array(
					'expires'  => $expire,
					'path'     => $path,
					'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
					'secure'   => is_ssl(),
					'httponly' => false,
					'samesite' => 'Lax',
				)
			);
		} else {
			setcookie( $name, $value, $expire, $path . '; samesite=Lax', defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '', is_ssl(), false );
		}
		$_COOKIE[ $name ] = $value;
	}

	/**
	 * Remember the latest search of this visitor (called by the tracker).
	 *
	 * @param int $log_id Log ID.
	 */
	public static function remember_search( $log_id ) {
		if ( ! ASL_Settings::on( 'track_clicks' ) && ! ASL_Settings::on( 'track_conversions' ) ) {
			return;
		}
		self::set_cookie( self::COOKIE, self::sign( $log_id ), time() + HOUR_IN_SECONDS );
	}

	/**
	 * Current search log ID from the cookie.
	 *
	 * @return int
	 */
	private static function current_log_id() {
		$value = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		return self::verify( $value );
	}

	/* ------------------------------------------------------------------ *
	 * Front-end script
	 * ------------------------------------------------------------------ */

	/**
	 * Enqueue the click tracker.
	 */
	public function enqueue() {
		if ( ! ASL_Settings::on( 'track_clicks' ) || ! ASL_Settings::on( 'logging_enabled' ) ) {
			return;
		}
		$live = array(
			'[class*="search-result"]',
			'[class*="search_result"]',
			'[class*="autocomplete"]',
			'[class*="suggestion"]',
			'[class*="search-dropdown"]',
			'[class*="live-search"]',
			'[class*="ajax-search"]',
			'[class*="searchform-results"]',
			'.dgwt-wcas-suggestions-wrapp',
			'.dgwt-wcas-details-wrapp',
			'.asp_r',
			'.aws-search-result',
			'.is-ajax-search-result',
		);
		$custom = ASL_Settings::lines( 'live_selectors' );
		$live   = apply_filters( 'asl_live_search_selectors', array_merge( $live, $custom ) );

		global $wp_query;
		wp_enqueue_script( 'asl-front', ASL_PLUGIN_URL . 'public/assets/js/asl-front.js', array(), ASL_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
		wp_localize_script(
			'asl-front',
			'aslFront',
			array(
				'ajax'     => admin_url( 'admin-ajax.php' ),
				'live'     => implode( ',', $live ),
				'results'  => apply_filters( 'asl_results_container_selector', 'main,#main,#primary,.site-main,#content,.content-area,.main-page-wrapper,.products,ul.products' ),
				'isSearch' => is_search() ? 1 : 0,
				'paged'    => max( 1, (int) get_query_var( 'paged' ) ),
				'perPage'  => is_search() && $wp_query ? max( 1, (int) $wp_query->get( 'posts_per_page' ) ) : 10,
			)
		);
	}

	/**
	 * AJAX endpoint receiving clicks.
	 */
	public function ajax_click() {
		// No nonce on purpose: nonces break on cached pages, and the signed log ID already prevents forgery.
		$sid    = isset( $_POST['sid'] ) ? sanitize_text_field( wp_unslash( $_POST['sid'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$url    = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$pos    = isset( $_POST['pos'] ) ? absint( $_POST['pos'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$log_id = self::verify( $sid );

		if ( ! $log_id || ! $url || ! ASL_Settings::on( 'track_clicks' ) ) {
			wp_send_json_error( null, 400 );
		}
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( wp_parse_url( $url, PHP_URL_HOST ) !== $home_host ) {
			wp_send_json_error( null, 400 );
		}
		$log = ASL_DB::get_log( $log_id );
		if ( ! $log || strtotime( $log->timestamp ) < strtotime( asl_now( -2 * HOUR_IN_SECONDS ) ) || ASL_DB::clicks_for_log( $log_id ) >= 20 ) {
			wp_send_json_error( null, 400 );
		}
		$post_id = url_to_postid( $url );
		ASL_DB::add_click( $log_id, $post_id, $url, $pos );
		do_action( 'asl_result_clicked', $log_id, $post_id, $url, $pos );
		wp_send_json_success();
	}

	/* ------------------------------------------------------------------ *
	 * WooCommerce attribution
	 * ------------------------------------------------------------------ */

	/**
	 * Pairs "log:product" stored in the cart cookie.
	 *
	 * @return array
	 */
	private static function cart_pairs() {
		$raw   = isset( $_COOKIE[ self::CART_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::CART_COOKIE ] ) ) : '';
		$pairs = array();
		foreach ( array_filter( explode( '|', $raw ) ) as $chunk ) {
			list( $signed, $product ) = array_pad( explode( '~', $chunk, 2 ), 2, 0 );
			$log_id                   = self::verify( $signed );
			if ( $log_id && absint( $product ) ) {
				$pairs[] = array( $log_id, absint( $product ) );
			}
		}
		return $pairs;
	}

	/**
	 * Add to cart after a search.
	 *
	 * @param string $cart_item_key Key.
	 * @param int    $product_id    Product.
	 */
	public function on_add_to_cart( $cart_item_key, $product_id ) {
		if ( ! ASL_Settings::on( 'track_conversions' ) ) {
			return;
		}
		$log_id = self::current_log_id();
		if ( ! $log_id ) {
			return;
		}
		$log = ASL_DB::get_log( $log_id );
		if ( ! $log || strtotime( $log->timestamp ) < strtotime( asl_now( -2 * HOUR_IN_SECONDS ) ) ) {
			return;
		}
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . asl_table( 'logs' ) . ' SET add_to_cart = LEAST(add_to_cart + 1, 65535) WHERE id = %d', $log_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$pairs   = self::cart_pairs();
		$pairs[] = array( $log_id, absint( $product_id ) );
		$pairs   = array_slice( $pairs, -15 );
		$value   = implode(
			'|',
			array_map(
				function ( $p ) {
					return self::sign( $p[0] ) . '~' . $p[1];
				},
				$pairs
			)
		);
		self::set_cookie( self::CART_COOKIE, $value, time() + 7 * DAY_IN_SECONDS );
	}

	/**
	 * At checkout, store which searches led to the products of this order.
	 *
	 * @param WC_Order $order Order.
	 */
	public function on_order_created( $order ) {
		if ( ! ASL_Settings::on( 'track_conversions' ) || ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
			return;
		}
		$pairs = self::cart_pairs();
		if ( ! $pairs || $order->get_meta( '_asl_pairs' ) ) {
			return;
		}
		$order->update_meta_data( '_asl_pairs', $pairs );
		$order->save();
		self::set_cookie( self::CART_COOKIE, '', time() - DAY_IN_SECONDS );

		if ( $order->is_paid() ) {
			$this->attribute_revenue( $order );
		}
	}

	/**
	 * When the order is paid, credit its revenue to the searches.
	 *
	 * @param int      $order_id Order ID.
	 * @param string   $from     Old status.
	 * @param string   $to       New status.
	 * @param WC_Order $order    Order.
	 */
	public function on_order_status_changed( $order_id, $from, $to, $order = null ) {
		if ( ! $order && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
		}
		if ( $order && function_exists( 'wc_get_is_paid_statuses' ) && in_array( $to, wc_get_is_paid_statuses(), true ) ) {
			$this->attribute_revenue( $order );
		}
	}

	/**
	 * Credit revenue once per order.
	 *
	 * @param WC_Order $order Order.
	 */
	private function attribute_revenue( $order ) {
		$pairs = $order->get_meta( '_asl_pairs' );
		if ( ! is_array( $pairs ) || ! $pairs || $order->get_meta( '_asl_attributed' ) ) {
			return;
		}
		$by_product = array();
		foreach ( $pairs as $p ) {
			$by_product[ (int) $p[1] ] = (int) $p[0]; // The latest search for a product wins.
		}
		$revenue = array();
		$terms   = array();
		foreach ( $order->get_items() as $item ) {
			$ids = array( (int) $item->get_product_id(), (int) $item->get_variation_id() );
			foreach ( $ids as $pid ) {
				if ( $pid && isset( $by_product[ $pid ] ) ) {
					$log_id               = $by_product[ $pid ];
					$revenue[ $log_id ]   = ( isset( $revenue[ $log_id ] ) ? $revenue[ $log_id ] : 0 ) + (float) $item->get_total() + (float) $item->get_total_tax();
					break;
				}
			}
		}
		global $wpdb;
		foreach ( $revenue as $log_id => $amount ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . asl_table( 'logs' ) . ' SET order_id = %d, revenue = revenue + %f WHERE id = %d', $order->get_id(), $amount, $log_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$log = ASL_DB::get_log( $log_id );
			if ( $log ) {
				$terms[] = $log->term;
			}
		}
		$order->update_meta_data( '_asl_attributed', 1 );
		$order->update_meta_data( '_asl_search_terms', array_values( array_unique( $terms ) ) );
		$order->save();
		ASL_Activator::bump_cache();
		do_action( 'asl_order_attributed', $order, $revenue );
	}

	/**
	 * Show the searches behind an order on the order screen.
	 *
	 * @param WC_Order $order Order.
	 */
	public function order_admin_box( $order ) {
		$terms = $order->get_meta( '_asl_search_terms' );
		if ( ! is_array( $terms ) || ! $terms ) {
			return;
		}
		echo '<p class="form-field form-field-wide"><strong>' . esc_html__( 'Found through site search:', 'advanced-search-logger' ) . '</strong> ' . esc_html( implode( ', ', $terms ) ) . '</p>';
	}
}
