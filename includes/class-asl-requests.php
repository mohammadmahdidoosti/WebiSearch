<?php
/**
 * Product requests: "We don't have it yet – leave your number and we will call you".
 *
 * When a search finds nothing (even after the automatic rescue), the visitor sees a box
 * instead of a dead end and can leave a phone number. Sales staff see every request in
 * WebiSearch → Product requests, can mark it contacted/done and export the list.
 *
 * Integrations (SMS, CRM...): do_action( 'asl_request_created', $id, $row ).
 *
 * @package WebiSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name is internal; values go through prepare().

class ASL_Requests {

	/**
	 * Has the box been printed on this page?
	 *
	 * @var bool
	 */
	private static $rendered = false;

	/**
	 * Has the CSS been printed on this page?
	 *
	 * @var bool
	 */
	private static $styled = false;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'wp_ajax_asl_request', array( __CLASS__, 'ajax_submit' ) );
		add_action( 'wp_ajax_nopriv_asl_request', array( __CLASS__, 'ajax_submit' ) );
		add_shortcode( 'webisearch_request_form', array( __CLASS__, 'shortcode' ) );

		if ( ! ASL_Settings::on( 'request_enabled' ) ) {
			return;
		}
		// WooCommerce (and themes built on it, e.g. Woodmart): before the "No products were found" message.
		add_action( 'woocommerce_no_products_found', array( __CLASS__, 'print_box' ), 5 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_replace_wc_message' ) );
		// Classic themes: empty main search query.
		add_action( 'loop_no_results', array( __CLASS__, 'print_box_generic' ) );
		// Block themes.
		add_filter( 'render_block', array( __CLASS__, 'block_box' ), 10, 2 );
	}

	/* ------------------------------------------------------------------ *
	 * Statuses
	 * ------------------------------------------------------------------ */

	/**
	 * Status labels.
	 *
	 * @return array
	 */
	public static function statuses() {
		return array(
			'new'       => __( 'New', 'advanced-search-logger' ),
			'contacted' => __( 'Contacted', 'advanced-search-logger' ),
			'done'      => __( 'Done', 'advanced-search-logger' ),
			'canceled'  => __( 'Canceled', 'advanced-search-logger' ),
		);
	}

	/* ------------------------------------------------------------------ *
	 * Front end
	 * ------------------------------------------------------------------ */

	/**
	 * What the visitor typed (before normalization / rescue).
	 *
	 * @return string
	 */
	public static function current_term() {
		if ( ASL_Smart::$notice ) {
			return (string) ASL_Smart::$notice[0];
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of the search term.
		$raw = isset( $_GET['s'] ) && is_string( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		if ( '' === $raw ) {
			$raw = get_search_query( false );
		}
		return trim( mb_substr( $raw, 0, 150 ) );
	}

	/**
	 * Hide the theme's "No products were found" text when our box replaces it.
	 */
	public static function maybe_replace_wc_message() {
		if ( is_search() && ASL_Settings::on( 'request_replace' ) ) {
			remove_action( 'woocommerce_no_products_found', 'wc_no_products_found' );
		}
	}

	/**
	 * WooCommerce empty result page.
	 */
	public static function print_box() {
		if ( ! is_search() ) {
			return;
		}
		echo self::box_html( self::current_term() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
	}

	/**
	 * Classic themes: the main search query found nothing.
	 *
	 * @param WP_Query $q Query.
	 */
	public static function print_box_generic( $q ) {
		if ( ! did_action( 'wp_head' ) || ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) ) {
			return;
		}
		if ( ! $q->is_main_query() || ! $q->is_search() || is_admin() || wp_doing_ajax() ) {
			return;
		}
		// WooCommerce pages get the box from woocommerce_no_products_found (better position).
		if ( function_exists( 'is_woocommerce' ) && is_woocommerce() ) {
			return;
		}
		echo self::box_html( self::current_term() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
	}

	/**
	 * Block themes: put the box inside the "No results" block.
	 *
	 * @param string $content Block HTML.
	 * @param array  $block   Block.
	 * @return string
	 */
	public static function block_box( $content, $block ) {
		if ( 'core/query-no-results' !== $block['blockName'] || ! is_search() || self::$rendered ) {
			return $content;
		}
		return self::box_html( self::current_term() ) . $content;
	}

	/**
	 * [webisearch_request_form term="..." compact="no"]
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'term'    => '',
				'compact' => 'no',
			),
			$atts,
			'webisearch_request_form'
		);
		$term = '' !== $atts['term'] ? sanitize_text_field( $atts['term'] ) : self::current_term();
		return self::box_html( $term, 'yes' === $atts['compact'], true );
	}

	/**
	 * Setting text with a translated fallback and {term} placeholder.
	 *
	 * @param string $key  Setting key.
	 * @param string $term Term (already escaped for HTML or plain).
	 * @return string Plain text (not escaped).
	 */
	public static function text( $key, $term = '' ) {
		$defaults = array(
			'request_title'   => __( '"{term}" is not available on the site right now', 'advanced-search-logger' ),
			'request_text'    => __( 'Leave your phone number. As soon as it is available, our experts will call you.', 'advanced-search-logger' ),
			'request_button'  => __( 'Notify me', 'advanced-search-logger' ),
			'request_success' => __( 'Thank you! Your number has been registered. Our experts will call you as soon as the product is available.', 'advanced-search-logger' ),
		);
		$val = trim( (string) ASL_Settings::get( $key, '' ) );
		if ( '' === $val ) {
			$val = isset( $defaults[ $key ] ) ? $defaults[ $key ] : '';
		}
		if ( '' === $term ) {
			// No term (e.g. shortcode on a normal page): drop the quotes around the placeholder.
			$val = preg_replace( '/[«"“]?\{term\}[»"”]?\s*/u', '', $val );
			if ( 'request_title' === $key && '' === trim( $val ) ) {
				$val = __( 'Didn\'t find what you were looking for?', 'advanced-search-logger' );
			}
		}
		return str_replace( '{term}', $term, $val );
	}

	/**
	 * The request box.
	 *
	 * @param string $term    What the visitor searched.
	 * @param bool   $compact One-line version that expands on click (used above "close" results).
	 * @param bool   $force   Print even if the box was already printed (shortcode).
	 * @return string
	 */
	public static function box_html( $term, $compact = false, $force = false ) {
		if ( ( self::$rendered && ! $force ) || ! apply_filters( 'asl_show_request_box', true, $term, $compact ) ) {
			return '';
		}
		self::$rendered = true;
		$style          = self::enqueue();

		$uid   = 'asl-req-' . wp_rand( 1000, 999999 );
		$name  = '';
		$phone = '';
		if ( is_user_logged_in() ) {
			$user  = wp_get_current_user();
			$name  = trim( $user->first_name . ' ' . $user->last_name );
			$name  = '' !== $name ? $name : $user->display_name;
			$phone = (string) get_user_meta( $user->ID, 'billing_phone', true );
		}
		$t     = time();
		$token = $t . '.' . substr( wp_hash( 'asl_req|' . $t ), 0, 12 );

		$form  = '<form class="asl-req__form" method="post" novalidate>';
		$form .= '<input type="hidden" name="action" value="asl_request">';
		$form .= '<input type="hidden" name="term" value="' . esc_attr( $term ) . '">';
		$form .= '<input type="hidden" name="token" value="' . esc_attr( $token ) . '">';
		$form .= '<div class="asl-req__fields">';
		if ( ASL_Settings::on( 'request_name' ) ) {
			$form .= '<label class="screen-reader-text asl-req__sr" for="' . esc_attr( $uid ) . '-name">' . esc_html__( 'Your name', 'advanced-search-logger' ) . '</label>';
			$form .= '<input class="asl-req__input asl-req__input--name" id="' . esc_attr( $uid ) . '-name" type="text" name="name" maxlength="80" autocomplete="name" placeholder="' . esc_attr__( 'Your name (optional)', 'advanced-search-logger' ) . '" value="' . esc_attr( $name ) . '">';
		}
		$form .= '<label class="screen-reader-text asl-req__sr" for="' . esc_attr( $uid ) . '-phone">' . esc_html__( 'Phone number', 'advanced-search-logger' ) . '</label>';
		$form .= '<input class="asl-req__input asl-req__input--phone" id="' . esc_attr( $uid ) . '-phone" type="tel" name="phone" inputmode="tel" maxlength="20" autocomplete="tel" dir="ltr" required placeholder="' . esc_attr( asl_is_persian() ? '09xxxxxxxxx' : __( 'Phone number', 'advanced-search-logger' ) ) . '" value="' . esc_attr( $phone ) . '">';
		$form .= '<button type="submit" class="asl-req__btn button">' . esc_html( self::text( 'request_button' ) ) . '</button>';
		$form .= '</div>';
		// Honeypot: bots fill every field, people never see this one.
		$form .= '<div class="asl-req__hp" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off" value=""></div>';
		$form .= '<p class="asl-req__msg" role="status" aria-live="polite"></p>';
		$form .= '</form>';
		$form .= '<div class="asl-req__done" hidden><span class="asl-req__check" aria-hidden="true">&#10003;</span><span>' . esc_html( self::text( 'request_success', $term ) ) . '</span></div>';

		$bell = '<svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 22a2.5 2.5 0 0 0 2.45-2h-4.9A2.5 2.5 0 0 0 12 22Zm7-6V11a7 7 0 0 0-5.5-6.84V3.5a1.5 1.5 0 0 0-3 0v.66A7 7 0 0 0 5 11v5l-1.7 1.7A1 1 0 0 0 4 19.4h16a1 1 0 0 0 .7-1.7L19 16Z"/></svg>';

		if ( $compact ) {
			/* translators: %s: what the visitor searched */
			$line = '' !== $term ? sprintf( __( 'Not exactly "%s"? Leave your number and we will call you when it is available.', 'advanced-search-logger' ), $term ) : __( 'Didn\'t find what you were looking for? Leave your number and we will call you.', 'advanced-search-logger' );
			return $style . '<details class="asl-req asl-req--compact" id="' . esc_attr( $uid ) . '"><summary class="asl-req__summary"><span class="asl-req__icon">' . $bell . '</span><span>' . esc_html( $line ) . '</span></summary><div class="asl-req__body">' . $form . '</div></details>';
		}

		$html  = $style . '<div class="asl-req" id="' . esc_attr( $uid ) . '">';
		$html .= '<div class="asl-req__icon">' . $bell . '</div>';
		$html .= '<div class="asl-req__body">';
		$html .= '<p class="asl-req__title">' . esc_html( self::text( 'request_title', $term ) ) . '</p>';
		$html .= '<p class="asl-req__text">' . esc_html( self::text( 'request_text', $term ) ) . '</p>';
		$html .= $form;
		$html .= '</div></div>';
		return $html;
	}

	/**
	 * Enqueue the script; returns the inline CSS the first time (printed once, inline so it works
	 * with any optimizer or late render).
	 *
	 * @return string
	 */
	private static function enqueue() {
		wp_enqueue_script( 'asl-request', ASL_PLUGIN_URL . 'public/assets/js/asl-request.js', array(), ASL_VERSION, array( 'in_footer' => true ) );
		wp_localize_script(
			'asl-request',
			'aslRequest',
			array(
				'ajax'    => admin_url( 'admin-ajax.php' ),
				'sending' => __( 'Sending…', 'advanced-search-logger' ),
				'invalid' => __( 'Please enter a valid phone number.', 'advanced-search-logger' ),
				'error'   => __( 'Something went wrong. Please try again.', 'advanced-search-logger' ),
			)
		);
		if ( self::$styled ) {
			return '';
		}
		self::$styled = true;
		$css = '.asl-req{--asl-req-accent:#2271b1;display:flex;gap:16px;align-items:flex-start;margin:0 0 24px;padding:20px 22px;border:1px solid rgba(0,0,0,.09);border-inline-start:4px solid var(--asl-req-accent);border-radius:10px;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.04);color:inherit;box-sizing:border-box;max-width:100%}'
			. '.asl-req *{box-sizing:border-box}'
			. '.asl-req__icon{flex:0 0 auto;display:inline-flex;align-items:center;justify-content:center;width:46px;height:46px;border-radius:50%;background:rgba(34,113,177,.1);color:var(--asl-req-accent)}'
			. '.asl-req__body{flex:1;min-width:0}'
			. '.asl-req__title{margin:2px 0 4px;font-size:1.1em;font-weight:700;line-height:1.5}'
			. '.asl-req__text{margin:0 0 14px;opacity:.8;line-height:1.7}'
			. '.asl-req__fields{display:flex;flex-wrap:wrap;gap:8px}'
			. '.asl-req .asl-req__input{flex:1 1 180px;min-width:0;height:44px;margin:0;padding:0 12px;border:1px solid rgba(0,0,0,.18);border-radius:8px;background:#fff;font:inherit}'
			. '.asl-req .asl-req__input--phone{text-align:left;letter-spacing:.5px}'
			. '.asl-req .asl-req__input:focus{outline:2px solid var(--asl-req-accent);outline-offset:0;border-color:transparent}'
			. '.asl-req .asl-req__btn{flex:0 0 auto;height:44px;margin:0;padding:0 22px;border:0;border-radius:8px;background:var(--asl-req-accent);color:#fff;font:inherit;font-weight:600;cursor:pointer;line-height:44px}'
			. '.asl-req .asl-req__btn:hover{filter:brightness(1.08)}'
			. '.asl-req .asl-req__btn[disabled]{opacity:.6;cursor:wait}'
			. '.asl-req__msg{margin:8px 0 0;font-size:.9em}'
			. '.asl-req__msg:empty{display:none}'
			. '.asl-req__msg.is-error{color:#d63638}'
			. '.asl-req__done{display:flex;gap:10px;align-items:center;padding:12px 14px;border-radius:8px;background:#edfaef;color:#00701a;font-weight:600;line-height:1.7}'
			. '.asl-req__done[hidden]{display:none}'
			. '.asl-req__check{flex:0 0 auto;display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:50%;background:#00a32a;color:#fff;font-size:15px}'
			. '.asl-req__hp{position:absolute!important;inset-inline-start:-9999px;width:1px;height:1px;overflow:hidden}'
			. '.asl-req__sr{position:absolute!important;width:1px;height:1px;overflow:hidden;clip:rect(1px,1px,1px,1px);white-space:nowrap}'
			. '.asl-req--compact{display:block;padding:0;margin:0 0 18px}'
			. '.asl-req__summary{display:flex;gap:10px;align-items:center;padding:12px 16px;cursor:pointer;list-style:none;font-weight:600}'
			. '.asl-req__summary::-webkit-details-marker{display:none}'
			. '.asl-req--compact .asl-req__icon{width:34px;height:34px}'
			. '.asl-req--compact .asl-req__icon svg{width:20px;height:20px}'
			. '.asl-req--compact .asl-req__body{padding:0 16px 16px}'
			. '@media (max-width:520px){.asl-req{flex-direction:column;gap:10px;padding:16px}.asl-req .asl-req__btn{flex:1 1 100%}}';
		$css = (string) apply_filters( 'asl_request_css', $css );
		// Printed with the first box: a footer style would flash unstyled.
		return '' !== $css ? '<style id="asl-request-css">' . $css . '</style>' : '';
	}

	/* ------------------------------------------------------------------ *
	 * Submit
	 * ------------------------------------------------------------------ */

	/**
	 * Normalize a phone number. Persian/Arabic digits are converted; Iranian numbers become 09xxxxxxxxx.
	 *
	 * @param string $raw Input.
	 * @return string Normalized number or '' when invalid.
	 */
	public static function normalize_phone( $raw ) {
		$raw   = strtr( (string) $raw, array_combine( array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' ), array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' ) ) );
		$plus  = 0 === strpos( ltrim( $raw ), '+' );
		$d     = preg_replace( '/\D+/', '', $raw );
		$valid = '';

		// Iran: +98 / 0098 / 98 / 9xxxxxxxxx → 09xxxxxxxxx; landlines 0xx xxxxxxxx.
		if ( preg_match( '/^(?:0098|98)(9\d{9})$/', $d, $m ) || preg_match( '/^(9\d{9})$/', $d, $m ) ) {
			$valid = '0' . $m[1];
		} elseif ( preg_match( '/^09\d{9}$/', $d ) ) {
			$valid = $d;
		} elseif ( preg_match( '/^(?:0098|98)([1-8]\d{9})$/', $d, $m ) ) {
			$valid = '0' . $m[1];
		} elseif ( preg_match( '/^0[1-8]\d{9}$/', $d ) ) {
			$valid = $d;
		} elseif ( ! asl_is_persian() && strlen( $d ) >= 7 && strlen( $d ) <= 15 ) {
			$valid = ( $plus ? '+' : '' ) . $d;
		}
		return (string) apply_filters( 'asl_request_phone', $valid, $raw );
	}

	/**
	 * AJAX: save a request.
	 */
	public static function ajax_submit() {
		// Public form on (possibly cached) pages: protected by honeypot, a signed time token and a rate limit
		// instead of a nonce, which would expire inside cached HTML.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$hp    = isset( $_POST['website'] ) ? sanitize_text_field( wp_unslash( $_POST['website'] ) ) : '';
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$term  = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';
		$name  = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$raw   = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		// phpcs:enable

		$success = array( 'message' => self::text( 'request_success', $term ) );

		if ( '' !== $hp ) {
			wp_send_json_success( $success ); // Pretend it worked; do not teach the bot.
		}
		$parts = explode( '.', $token );
		$t     = isset( $parts[0] ) ? (int) $parts[0] : 0;
		if ( 2 !== count( $parts ) || ! hash_equals( substr( wp_hash( 'asl_req|' . $t ), 0, 12 ), $parts[1] ) || time() - $t > 30 * DAY_IN_SECONDS ) {
			wp_send_json_error( array( 'message' => __( 'The page has expired. Please reload it and try again.', 'advanced-search-logger' ) ), 400 );
		}
		if ( time() - $t < 2 ) {
			wp_send_json_success( $success ); // Filled faster than a human can type.
		}

		$phone = self::normalize_phone( $raw );
		if ( '' === $phone ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid phone number.', 'advanced-search-logger' ) ), 400 );
		}

		// Rate limit: 6 requests per hour per IP.
		$key  = 'asl_rq_' . substr( md5( asl_client_ip() . wp_salt( 'nonce' ) ), 0, 16 );
		$hits = (int) get_transient( $key );
		if ( $hits >= (int) apply_filters( 'asl_request_rate_limit', 6 ) ) {
			wp_send_json_error( array( 'message' => __( 'Too many requests. Please try again later.', 'advanced-search-logger' ) ), 429 );
		}
		set_transient( $key, $hits + 1, HOUR_IN_SECONDS );

		$term = mb_substr( $term, 0, 150 );
		$norm = ASL_Normalizer::normalize( $term );

		// Same number for the same product still open: do not create a duplicate.
		$existing = self::find_open( $phone, $norm );
		if ( $existing ) {
			wp_send_json_success( $success );
		}

		// The page URL is rebuilt here: posting a full URL with a query string trips server firewalls
		// (LiteSpeed / ModSecurity / Imunify360 answer 403).
		$page = add_query_arg(
			array_filter(
				array(
					's'         => rawurlencode( $term ),
					'post_type' => post_type_exists( 'product' ) ? 'product' : '',
				)
			),
			home_url( '/' )
		);

		$log_id = 0;
		$sid    = isset( $_COOKIE[ ASL_Clicks::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ ASL_Clicks::COOKIE ] ) ) : '';
		if ( $sid ) {
			$log_id = (int) ASL_Clicks::verify( $sid );
		}
		$row = array(
			'term'       => $term,
			'term_norm'  => mb_substr( $norm, 0, 191 ),
			'name'       => mb_substr( $name, 0, 80 ),
			'phone'      => $phone,
			'user_id'    => get_current_user_id(),
			'log_id'     => $log_id,
			'page_url'   => mb_substr( $page, 0, 500 ),
			'status'     => 'new',
			'note'       => '',
			'created'    => asl_now(),
			'updated'    => asl_now(),
		);
		$id = self::insert( $row );
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Something went wrong. Please try again.', 'advanced-search-logger' ) ), 500 );
		}
		$row['id'] = $id;
		self::notify( $row );
		do_action( 'asl_request_created', $id, $row );
		wp_send_json_success( $success );
	}

	/**
	 * E-mail the sales team.
	 *
	 * @param array $row Request.
	 */
	private static function notify( array $row ) {
		if ( ! ASL_Settings::on( 'request_notify' ) ) {
			return;
		}
		$to = trim( (string) ASL_Settings::get( 'request_notify_email', '' ) );
		if ( '' === $to ) {
			$to = get_option( 'admin_email' );
		}
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: 1: product searched, 2: site name */
		$subject = sprintf( __( 'New product request: %1$s (%2$s)', 'advanced-search-logger' ), '' !== $row['term'] ? $row['term'] : '-', $site );
		$dir     = is_rtl() || asl_is_persian() ? 'rtl' : 'ltr';
		$lines   = array(
			__( 'Product searched', 'advanced-search-logger' ) => esc_html( $row['term'] ),
			__( 'Name', 'advanced-search-logger' )             => esc_html( $row['name'] ? $row['name'] : '-' ),
			__( 'Phone', 'advanced-search-logger' )            => '<a href="tel:' . esc_attr( $row['phone'] ) . '" dir="ltr">' . esc_html( $row['phone'] ) . '</a>',
			__( 'Date', 'advanced-search-logger' )             => esc_html( asl_format_date( $row['created'] ) ),
		);
		$body = '<div dir="' . $dir . '" style="font-family:Tahoma,Arial,sans-serif;font-size:14px;line-height:1.8">';
		$body .= '<p>' . esc_html__( 'A visitor searched for a product you do not have and left a phone number:', 'advanced-search-logger' ) . '</p><table cellpadding="6" style="border-collapse:collapse">';
		foreach ( $lines as $k => $v ) {
			$body .= '<tr><th style="text-align:start;background:#f6f7f7">' . esc_html( $k ) . '</th><td>' . $v . '</td></tr>';
		}
		$body .= '</table><p><a href="' . esc_url( admin_url( 'admin.php?page=asl-requests' ) ) . '">' . esc_html__( 'Open product requests', 'advanced-search-logger' ) . '</a></p></div>';
		wp_mail( $to, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	/* ------------------------------------------------------------------ *
	 * Storage
	 * ------------------------------------------------------------------ */

	/**
	 * Insert.
	 *
	 * @param array $row Row.
	 * @return int
	 */
	public static function insert( array $row ) {
		global $wpdb;
		$ok = $wpdb->insert( asl_table( 'requests' ), $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Open request for a phone + term.
	 *
	 * @param string $phone Phone.
	 * @param string $norm  Normalized term.
	 * @return int
	 */
	public static function find_open( $phone, $norm ) {
		global $wpdb;
		$t = asl_table( 'requests' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE phone = %s AND term_norm = %s AND status IN ('new','contacted') LIMIT 1", $phone, $norm ) );
	}

	/**
	 * One request.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$t = asl_table( 'requests' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ), ARRAY_A );
	}

	/**
	 * Update status / note.
	 *
	 * @param int[]  $ids    IDs.
	 * @param string $status Status ('' = unchanged).
	 * @param string $note   Note (null = unchanged).
	 * @return int Rows changed.
	 */
	public static function update( array $ids, $status = '', $note = null ) {
		global $wpdb;
		$ids  = array_filter( array_map( 'absint', $ids ) );
		$data = array( 'updated' => asl_now() );
		if ( '' !== $status && isset( self::statuses()[ $status ] ) ) {
			$data['status'] = $status;
		}
		if ( null !== $note ) {
			$data['note'] = $note;
		}
		$n = 0;
		foreach ( $ids as $id ) {
			$n += (int) $wpdb->update( asl_table( 'requests' ), $data, array( 'id' => $id ) );
		}
		return $n;
	}

	/**
	 * Mark every open request of a product.
	 *
	 * @param string $norm   Normalized term.
	 * @param string $status Status.
	 * @return int
	 */
	public static function update_term( $norm, $status ) {
		global $wpdb;
		if ( ! isset( self::statuses()[ $status ] ) ) {
			return 0;
		}
		$t = asl_table( 'requests' );
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE $t SET status = %s, updated = %s WHERE term_norm = %s AND status IN ('new','contacted')", $status, asl_now(), $norm ) );
	}

	/**
	 * Delete.
	 *
	 * @param int[] $ids IDs.
	 * @return int
	 */
	public static function delete( array $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( ! $ids ) {
			return 0;
		}
		$t = asl_table( 'requests' );
		return (int) $wpdb->query( "DELETE FROM $t WHERE id IN (" . implode( ',', $ids ) . ')' );
	}

	/**
	 * WHERE clause from filters.
	 *
	 * @param array $f status, q (phone/name/term), term (normalized).
	 * @return string
	 */
	private static function where( array $f ) {
		global $wpdb;
		$w = array( '1=1' );
		if ( ! empty( $f['status'] ) && 'all' !== $f['status'] ) {
			if ( 'open' === $f['status'] ) {
				$w[] = "status IN ('new','contacted')";
			} else {
				$w[] = $wpdb->prepare( 'status = %s', $f['status'] );
			}
		}
		if ( ! empty( $f['term'] ) ) {
			$w[] = $wpdb->prepare( 'term_norm = %s', $f['term'] );
		}
		if ( ! empty( $f['q'] ) ) {
			$like  = '%' . $wpdb->esc_like( $f['q'] ) . '%';
			$phone = self::normalize_phone( $f['q'] );
			$w[]   = $wpdb->prepare( '(term LIKE %s OR name LIKE %s OR phone LIKE %s OR phone = %s)', $like, $like, $like, $phone );
		}
		return implode( ' AND ', $w );
	}

	/**
	 * List.
	 *
	 * @param array $f        Filters.
	 * @param int   $per_page Per page (0 = all).
	 * @param int   $page     Page.
	 * @return array
	 */
	public static function query( array $f, $per_page = 30, $page = 1 ) {
		global $wpdb;
		$t     = asl_table( 'requests' );
		$sql   = "SELECT * FROM $t WHERE " . self::where( $f ) . ' ORDER BY id DESC';
		if ( $per_page > 0 ) {
			$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $per_page, max( 0, ( $page - 1 ) * $per_page ) );
		}
		return (array) $wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * Count.
	 *
	 * @param array $f Filters.
	 * @return int
	 */
	public static function count( array $f ) {
		global $wpdb;
		$t = asl_table( 'requests' );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE " . self::where( $f ) );
	}

	/**
	 * Count per status.
	 *
	 * @return array
	 */
	public static function status_counts() {
		global $wpdb;
		$t   = asl_table( 'requests' );
		$out = array_fill_keys( array_keys( self::statuses() ), 0 );
		$suppress = $wpdb->suppress_errors( true );
		$rows     = (array) $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM $t GROUP BY status", ARRAY_A );
		$wpdb->suppress_errors( $suppress );
		foreach ( $rows as $r ) {
			$out[ $r['status'] ] = (int) $r['n'];
		}
		$out['all'] = array_sum( $out );
		return $out;
	}

	/**
	 * Most requested products (open requests).
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public static function top_terms( $limit = 10 ) {
		global $wpdb;
		$t = asl_table( 'requests' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT term_norm, MAX(term) AS term, COUNT(*) AS n, MAX(created) AS last FROM $t WHERE status IN ('new','contacted') AND term_norm <> '' GROUP BY term_norm ORDER BY n DESC, last DESC LIMIT %d", $limit ), ARRAY_A );
	}

	/**
	 * Open requests per normalized term (for the failed-searches review).
	 *
	 * @return array term_norm => count
	 */
	public static function open_by_term() {
		global $wpdb;
		$t        = asl_table( 'requests' );
		$suppress = $wpdb->suppress_errors( true );
		$rows     = (array) $wpdb->get_results( "SELECT term_norm, COUNT(*) AS n FROM $t WHERE status IN ('new','contacted') GROUP BY term_norm", ARRAY_A );
		$wpdb->suppress_errors( $suppress );
		return wp_list_pluck( $rows, 'n', 'term_norm' );
	}

	/**
	 * Is a requested product findable on the site now (from the last failed-searches review)?
	 *
	 * @param string $norm Normalized term.
	 * @return array|null Review item when found/fixed.
	 */
	public static function available_now( $norm ) {
		static $data = null;
		if ( null === $data ) {
			$data = ASL_Review::data();
		}
		if ( '' === $norm || empty( $data['items'][ $norm ] ) ) {
			return null;
		}
		$decision = isset( $data['decisions'][ $norm ] ) ? $data['decisions'][ $norm ] : '';
		$item     = $data['items'][ $norm ];
		return ( 'missing' !== $decision && in_array( $item['status'], array( 'found', 'fixed' ), true ) ) ? $item : null;
	}
}
