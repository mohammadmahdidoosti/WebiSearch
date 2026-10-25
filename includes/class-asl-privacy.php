<?php
/**
 * WordPress privacy tools integration (Tools > Export/Erase Personal Data) and policy text.
 *
 * @package WebiSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASL_Privacy {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
		add_action( 'admin_init', array( $this, 'policy_text' ) );
	}

	/**
	 * Register exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public function register_exporter( $exporters ) {
		$exporters['webisearch'] = array(
			'exporter_friendly_name' => __( 'Site search history', 'advanced-search-logger' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Register eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public function register_eraser( $erasers ) {
		$erasers['webisearch'] = array(
			'eraser_friendly_name' => __( 'Site search history', 'advanced-search-logger' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Export searches of a registered user.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public function export( $email, $page = 1 ) {
		$user = get_user_by( 'email', $email );
		$data = array();
		$done = true;
		if ( $user ) {
			$rows = ASL_DB::get_logs( array( 'user_id' => $user->ID ), 500, (int) $page, 'id', 'DESC' );
			foreach ( $rows as $row ) {
				$data[] = array(
					'group_id'    => 'webisearch',
					'group_label' => __( 'Site search history', 'advanced-search-logger' ),
					'item_id'     => 'asl-' . $row['id'],
					'data'        => array(
						array(
							'name'  => __( 'Search term', 'advanced-search-logger' ),
							'value' => $row['term'],
						),
						array(
							'name'  => __( 'Date', 'advanced-search-logger' ),
							'value' => $row['timestamp'],
						),
					),
				);
			}
			$done = count( $rows ) < 500;
			if ( 1 === (int) $page ) {
				global $wpdb;
				$reqs = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . asl_table( 'requests' ) . ' WHERE user_id = %d', $user->ID ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				foreach ( (array) $reqs as $req ) {
					$data[] = array(
						'group_id'    => 'webisearch-requests',
						'group_label' => __( 'Product requests', 'advanced-search-logger' ),
						'item_id'     => 'asl-req-' . $req['id'],
						'data'        => array(
							array(
								'name'  => __( 'Product searched', 'advanced-search-logger' ),
								'value' => $req['term'],
							),
							array(
								'name'  => __( 'Phone', 'advanced-search-logger' ),
								'value' => $req['phone'],
							),
							array(
								'name'  => __( 'Date', 'advanced-search-logger' ),
								'value' => $req['created'],
							),
						),
					);
				}
			}
		}
		return array(
			'data' => $data,
			'done' => $done,
		);
	}

	/**
	 * Erase searches of a registered user.
	 *
	 * @param string $email Email.
	 * @return array
	 */
	public function erase( $email ) {
		global $wpdb;
		$user    = get_user_by( 'email', $email );
		$removed = 0;
		if ( $user ) {
			$removed = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . asl_table( 'logs' ) . ' WHERE user_id = %d', $user->ID ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$removed += (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . asl_table( 'requests' ) . ' WHERE user_id = %d', $user->ID ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ASL_Activator::bump_cache();
		}
		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Suggested privacy policy text.
	 */
	public function policy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			'WebiSearch',
			'<p>' . esc_html__( 'When you use the search on this site we record the words you searched for, the number of results, which result you clicked and, for logged-in customers, your user account. We do not store your IP address; an anonymous, one-way identifier is used to count unique searchers. A first-party cookie (asl_sid, 1 hour) links a search to the result you open, and a second cookie (asl_cart, 7 days) links it to products you buy. This data is used only to improve our search and catalogue and is deleted automatically after a limited period.', 'advanced-search-logger' ) . '</p>'
				. '<p>' . esc_html__( 'If a product you searched for is not available and you leave your phone number (and optionally your name), we store it with the product you searched for so our staff can call you when it becomes available. You can ask us to delete it at any time.', 'advanced-search-logger' ) . '</p>'
		);
	}
}
