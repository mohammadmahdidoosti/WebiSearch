<?php
/**
 * Scheduled jobs: cleanup and e-mail reports.
 *
 * @package WebiSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASL_Reports {

	/**
	 * Delete old logs.
	 */
	public static function run_daily_cleanup() {
		$days = (int) ASL_Settings::get( 'cleanup_days', 180 );
		if ( $days > 0 ) {
			ASL_DB::delete_older_than( $days );
		}
	}

	/**
	 * Cron callback.
	 */
	public static function send_weekly_report() {
		if ( ! ASL_Settings::on( 'email_enable' ) ) {
			return;
		}
		self::send_report();
	}

	/**
	 * Build and send the report.
	 *
	 * @param string $recipient Override recipient (for test e-mails).
	 * @return bool
	 */
	public static function send_report( $recipient = '' ) {
		$stats = ASL_DB::get_stats( 7 );
		$k     = $stats['kpis'];

		$list = function ( $rows, $empty, $with_link = false ) {
			if ( empty( $rows ) ) {
				return '<p><em>' . esc_html( $empty ) . '</em></p>';
			}
			$html = '<ol>';
			foreach ( array_slice( $rows, 0, 10 ) as $r ) {
				$extra = '';
				if ( $with_link ) {
					$url    = admin_url( 'admin.php?page=asl-synonyms&term=' . rawurlencode( $r['term'] ) . ( ! empty( $r['suggestion'] ) ? '&target=' . rawurlencode( $r['suggestion'] ) : '' ) );
					$extra .= ' &mdash; <a href="' . esc_url( $url ) . '">' . esc_html__( 'fix', 'advanced-search-logger' ) . '</a>';
					if ( ! empty( $r['suggestion'] ) ) {
						/* translators: %s: suggested search term */
						$extra .= ' <small>(' . esc_html( sprintf( __( 'did you mean "%s"?', 'advanced-search-logger' ), $r['suggestion'] ) ) . ')</small>';
					}
				}
				$html .= sprintf( '<li>%s <strong>(%s)</strong>%s</li>', esc_html( $r['term'] ), esc_html( asl_num( $r['count'] ) ), $extra );
			}
			return $html . '</ol>';
		};

		$kpi_html = '<table cellpadding="6" style="border-collapse:collapse">'
			. self::kpi_row( __( 'Searches', 'advanced-search-logger' ), asl_num( $k['total'] ) )
			. self::kpi_row( __( 'Unique searchers', 'advanced-search-logger' ), asl_num( $k['visitors'] ) )
			. self::kpi_row( __( 'No-result rate', 'advanced-search-logger' ), asl_num( $k['zero_rate'], 1 ) . '%' )
			. self::kpi_row( __( 'Click-through rate', 'advanced-search-logger' ), asl_num( $k['ctr'], 1 ) . '%' );
		if ( class_exists( 'WooCommerce' ) && $k['revenue'] > 0 ) {
			$kpi_html .= self::kpi_row( __( 'Revenue from search', 'advanced-search-logger' ), wp_strip_all_tags( wc_price( $k['revenue'] ) ) );
		}
		$kpi_html .= '</table>';

		$placeholders = array(
			'{site_name}'          => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'{report_date}'        => asl_format_date( asl_now(), false ),
			'{kpis}'               => $kpi_html,
			'{total_searches}'     => asl_num( $k['total'] ),
			'{no_result_rate}'     => asl_num( $k['zero_rate'], 1 ) . '%',
			'{top_searches}'       => $list( $stats['top'], __( 'No data.', 'advanced-search-logger' ) ),
			'{no_result_searches}' => $list( $stats['zero'], __( 'No failed searches. Great!', 'advanced-search-logger' ), true ),
			'{trending_searches}'  => $list( $stats['trending'], __( 'Nothing unusual this week.', 'advanced-search-logger' ) ),
			'{dashboard_url}'      => admin_url( 'admin.php?page=asl-dashboard' ),
		);

		$subject = (string) ASL_Settings::get( 'email_subject' );
		$body    = (string) ASL_Settings::get( 'email_body' );
		if ( '' === trim( wp_strip_all_tags( $body ) ) ) {
			$body = ASL_Settings::default_email_template();
		}
		$subject = str_replace( array_keys( $placeholders ), array_values( $placeholders ), $subject );
		$body    = str_replace( array_keys( $placeholders ), array_values( $placeholders ), $body );
		$dir     = is_rtl() || asl_is_persian() ? 'rtl' : 'ltr';
		$body    = '<div dir="' . $dir . '" style="font-family:Tahoma,Arial,sans-serif;line-height:1.7">' . $body . '</div>';

		$to = $recipient ? $recipient : (string) ASL_Settings::get( 'email_recipient' );
		$to = array_filter( array_map( 'sanitize_email', array_map( 'trim', explode( ',', $to ) ) ) );
		if ( ! $to ) {
			$to = array( get_option( 'admin_email' ) );
		}
		return wp_mail( $to, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	/**
	 * One KPI table row.
	 *
	 * @param string $label Label.
	 * @param string $value Value.
	 * @return string
	 */
	private static function kpi_row( $label, $value ) {
		return '<tr><td style="border:1px solid #ddd">' . esc_html( $label ) . '</td><td style="border:1px solid #ddd"><strong>' . esc_html( $value ) . '</strong></td></tr>';
	}
}
