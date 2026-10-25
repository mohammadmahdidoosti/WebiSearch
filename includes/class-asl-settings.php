<?php
/**
 * Settings storage with defaults (option name kept from v2 for backward compatibility).
 *
 * @package WebiSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASL_Settings {

	const OPTION = 'asl_settings';

	/**
	 * Request-level cache.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default values.
	 *
	 * @return array
	 */
	public static function defaults() {
		$persian = asl_is_persian();
		return array(
			// Logging.
			'logging_enabled'   => 'on',
			'min_length'        => 2,
			'max_length'        => 100,
			'exclude_roles'     => array( 'administrator' ),
			'exclude_ips'       => '',
			'exclude_terms'     => '',
			'ignore_bots'       => 'on',
			'ignore_spam'       => 'on',
			'dedupe_window'     => 30,
			'log_rest'          => 'off',
			'store_user'        => 'on',
			// Search improvements.
			'normalize_query'   => $persian ? 'on' : 'off',
			'nocache_search'    => 'on',
			'variant_search'    => $persian ? 'on' : 'off',
			'search_in'         => 'all',
			'smart_fallback'    => 'on',
			'fallback_notice'   => 'on',
			'no_results_help'   => 'on',
			'no_results_text'   => '',
			// Click & conversion tracking.
			'track_clicks'      => 'on',
			'track_conversions' => 'on',
			'live_selectors'    => '',
			// Display.
			'jalali_dates'      => $persian ? 'on' : 'off',
			// Cleanup.
			'cleanup_days'      => 180,
			// Email.
			'email_enable'      => 'off',
			'email_recipient'   => get_option( 'admin_email' ),
			'email_subject'     => __( 'Weekly search report for {site_name}', 'advanced-search-logger' ),
			'email_body'        => '',
		);
	}

	/**
	 * All settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}
		return self::$cache;
	}

	/**
	 * Single setting.
	 *
	 * @param string $key     Key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Is a checkbox setting on?
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public static function on( $key ) {
		return 'on' === self::get( $key );
	}

	/**
	 * Clear request cache (after saving).
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Split a textarea setting into clean lines.
	 *
	 * @param string $key Key.
	 * @return string[]
	 */
	public static function lines( $key ) {
		$raw = (string) self::get( $key, '' );
		$out = array();
		foreach ( preg_split( '/[\r\n,]+/', $raw ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	/**
	 * Default HTML email template (translatable).
	 *
	 * @return string
	 */
	public static function default_email_template() {
		return '<h2>' . __( 'Search report for {site_name}', 'advanced-search-logger' ) . '</h2>'
			. '<p>' . __( 'Summary of the last 7 days, ending {report_date}.', 'advanced-search-logger' ) . '</p>'
			. '{kpis}'
			. '<h3>' . __( 'Top searches', 'advanced-search-logger' ) . '</h3>{top_searches}'
			. '<h3>' . __( 'Searches with no results (fix these first)', 'advanced-search-logger' ) . '</h3>{no_result_searches}'
			. '<h3>' . __( 'Trending searches', 'advanced-search-logger' ) . '</h3>{trending_searches}'
			. '<p><a href="{dashboard_url}">' . __( 'Open the full dashboard', 'advanced-search-logger' ) . '</a></p>';
	}
}
