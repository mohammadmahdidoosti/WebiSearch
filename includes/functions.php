<?php
/**
 * Helper functions and the public API.
 *
 * @package WebiSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capability required to see and manage WebiSearch screens.
 * Shop managers can be allowed with: add_filter( 'asl_capability', fn() => 'manage_woocommerce' );
 *
 * @return string
 */
function asl_capability() {
	return apply_filters( 'asl_capability', 'manage_options' );
}

/**
 * Full table name.
 *
 * @param string $name logs|rules|clicks.
 * @return string
 */
function asl_table( $name ) {
	global $wpdb;
	$map = array(
		'logs'   => 'asl_search_logs',
		'rules'  => 'asl_synonyms',
		'clicks' => 'asl_clicks',
	);
	return $wpdb->prefix . ( isset( $map[ $name ] ) ? $map[ $name ] : $name );
}

/**
 * Current site-local time as MySQL datetime (the format logs are stored in).
 *
 * @param int $seconds_offset Seconds to add (negative for the past).
 * @return string
 */
function asl_now( $seconds_offset = 0 ) {
	return asl_date( 'Y-m-d H:i:s', time() + (int) $seconds_offset );
}

/**
 * Format a timestamp in the site's timezone for storage/queries (always Gregorian).
 *
 * Do NOT use wp_date()/date_i18n() for machine dates: Persian date plugins (WP-Parsidate,
 * Persian WooCommerce, ...) filter them and return Jalali years like 1405, which broke
 * every date range in 3.0.0.
 *
 * @param string   $format    PHP date format.
 * @param int|null $timestamp Unix timestamp (default now).
 * @return string
 */
function asl_date( $format, $timestamp = null ) {
	$dt = new DateTime( '@' . ( null === $timestamp ? time() : (int) $timestamp ) );
	$dt->setTimezone( wp_timezone() );
	return $dt->format( $format );
}

/**
 * Convert a Jalali (Shamsi) date to Gregorian.
 *
 * @param int $jy Year.
 * @param int $jm Month.
 * @param int $jd Day.
 * @return int[] [year, month, day]
 */
function asl_jalali_to_gregorian( $jy, $jm, $jd ) {
	$jy  += 1595;
	$days = -355668 + ( 365 * $jy ) + ( intdiv( $jy, 33 ) * 8 ) + intdiv( ( $jy % 33 ) + 3, 4 ) + $jd + ( ( $jm < 7 ) ? ( $jm - 1 ) * 31 : ( ( $jm - 7 ) * 30 ) + 186 );
	$gy   = 400 * intdiv( $days, 146097 );
	$days = $days % 146097;
	if ( $days > 36524 ) {
		$days--;
		$gy  += 100 * intdiv( $days, 36524 );
		$days = $days % 36524;
		if ( $days >= 365 ) {
			$days++;
		}
	}
	$gy  += 4 * intdiv( $days, 1461 );
	$days = $days % 1461;
	if ( $days > 365 ) {
		$gy  += intdiv( $days - 1, 365 );
		$days = ( $days - 1 ) % 365;
	}
	$gd     = $days + 1;
	$leap   = ( 0 === $gy % 4 && 0 !== $gy % 100 ) || 0 === $gy % 400;
	$months = array( 0, 31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 );
	for ( $gm = 0; $gm < 13 && $gd > $months[ $gm ]; $gm++ ) {
		$gd -= $months[ $gm ];
	}
	return array( $gy, $gm, $gd );
}

/**
 * Repair a datetime string that was stored with a Jalali year (e.g. "1405-07-21 19:55:00").
 *
 * @param string $value Datetime.
 * @return string Gregorian datetime (unchanged if it was already Gregorian).
 */
function asl_fix_jalali_datetime( $value ) {
	if ( ! is_string( $value ) || ! preg_match( '/^(1[2-4]\d\d)-(\d\d)-(\d\d)(.*)$/', $value, $m ) ) {
		return $value;
	}
	list( $gy, $gm, $gd ) = asl_jalali_to_gregorian( (int) $m[1], (int) $m[2], (int) $m[3] );
	return sprintf( '%04d-%02d-%02d%s', $gy, $gm, $gd, $m[4] );
}

/**
 * Is the site (or current admin user) using Persian?
 *
 * @return bool
 */
function asl_is_persian() {
	$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
	return 0 === strpos( (string) $locale, 'fa' );
}

/**
 * Format a stored (site-local) MySQL datetime for display.
 * Uses the Jalali (Persian) calendar on Persian sites when the intl extension is available.
 *
 * @param string $mysql     Datetime.
 * @param bool   $with_time Include time.
 * @param bool   $latin     Force Latin digits (for CSV files).
 * @return string
 */
function asl_format_date( $mysql, $with_time = true, $latin = false ) {
	if ( empty( $mysql ) || '0000-00-00 00:00:00' === $mysql ) {
		return '';
	}
	$use_jalali = asl_is_persian() && 'on' === ASL_Settings::get( 'jalali_dates' ) && class_exists( 'IntlDateFormatter' );
	if ( $use_jalali ) {
		try {
			$tz = wp_timezone();
			$dt = new DateTime( $mysql, $tz );
			$fm = new IntlDateFormatter(
				( $latin ? 'en_US' : 'fa_IR' ) . '@calendar=persian',
				IntlDateFormatter::NONE,
				IntlDateFormatter::NONE,
				$tz->getName(),
				IntlDateFormatter::TRADITIONAL,
				$with_time ? 'yyyy/MM/dd HH:mm' : 'yyyy/MM/dd'
			);
			$out = $fm->format( $dt );
			if ( false !== $out ) {
				return $out;
			}
		} catch ( Exception $e ) {
			// Fall back to the WordPress formatter below.
			unset( $e );
		}
	}
	if ( $latin ) {
		return $with_time ? substr( $mysql, 0, 16 ) : substr( $mysql, 0, 10 );
	}
	$ts     = strtotime( $mysql );
	$format = get_option( 'date_format' ) . ( $with_time ? ' ' . get_option( 'time_format' ) : '' );
	return date_i18n( $format, $ts );
}

/**
 * Human label for a log source.
 *
 * @param string $source Source key.
 * @return string
 */
function asl_source_label( $source ) {
	$labels = array(
		'standard' => __( 'Results page', 'advanced-search-logger' ),
		'ajax'     => __( 'Live (AJAX) search', 'advanced-search-logger' ),
		'rest'     => __( 'REST API', 'advanced-search-logger' ),
		'redirect' => __( 'Redirected', 'advanced-search-logger' ),
		'api'      => __( 'Custom integration', 'advanced-search-logger' ),
	);
	return isset( $labels[ $source ] ) ? $labels[ $source ] : $source;
}

/**
 * Best-effort client IP (only used for exclusion rules and the anonymous visitor hash; never stored).
 *
 * @return string
 */
function asl_client_ip() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	// Behind Cloudflare / a reverse proxy the real address is in a header. Only trust it when enabled.
	if ( apply_filters( 'asl_trust_proxy_headers', true ) ) {
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR' ) as $h ) {
			if ( ! empty( $_SERVER[ $h ] ) ) {
				$parts = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $h ] ) ) );
				$cand  = trim( $parts[0] );
				if ( filter_var( $cand, FILTER_VALIDATE_IP ) ) {
					$ip = $cand;
					break;
				}
			}
		}
	}
	return (string) apply_filters( 'asl_client_ip', $ip );
}

/**
 * Format a number for display.
 *
 * @param float $n        Number.
 * @param int   $decimals Decimals.
 * @return string
 */
function asl_num( $n, $decimals = 0 ) {
	return number_format_i18n( (float) $n, $decimals );
}

/**
 * Public API: log a search from a custom search engine that does not use WP_Query
 * (e.g. an external index, a headless front end or a custom AJAX endpoint).
 *
 * Example: webisearch_log_search( 'pump', 12, array( 'source' => 'api', 'post_type' => 'product' ) );
 *
 * @param string $term         What the visitor typed.
 * @param int    $result_count Number of results shown.
 * @param array  $args         Optional: source, post_type, rewritten, user_id.
 * @return int|false Log ID or false when the search was not logged (excluded, too short, ...).
 */
function webisearch_log_search( $term, $result_count, $args = array() ) {
	$args = wp_parse_args(
		$args,
		array(
			'source'    => 'api',
			'post_type' => '',
			'rewritten' => '',
		)
	);
	return ASL_Logger::instance()->log( $term, (int) $result_count, $args );
}
