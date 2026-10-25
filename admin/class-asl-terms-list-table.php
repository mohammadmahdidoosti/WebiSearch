<?php
/**
 * Aggregated per-term report.
 *
 * @package WebiSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class ASL_Terms_List_Table extends WP_List_Table {

	/**
	 * Filters.
	 *
	 * @var array
	 */
	public $filters = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'asl_term',
				'plural'   => 'asl_terms',
				'ajax'     => false,
			)
		);
		$this->filters = ASL_DB::filters_from_request( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		$cols = array(
			'term_norm'   => __( 'Search Term', 'advanced-search-logger' ),
			'searches'    => __( 'Searches', 'advanced-search-logger' ),
			'visitors'    => __( 'Unique searchers', 'advanced-search-logger' ),
			'zero'        => __( 'No results', 'advanced-search-logger' ),
			'avg_results' => __( 'Average results', 'advanced-search-logger' ),
			'ctr'         => __( 'CTR', 'advanced-search-logger' ),
			'revenue'     => __( 'Revenue', 'advanced-search-logger' ),
			'last_search' => __( 'Last searched', 'advanced-search-logger' ),
		);
		if ( ! ASL_Settings::on( 'track_clicks' ) ) {
			unset( $cols['ctr'] );
		}
		if ( ! class_exists( 'WooCommerce' ) || ! ASL_Settings::on( 'track_conversions' ) ) {
			unset( $cols['revenue'] );
		}
		return $cols;
	}

	/**
	 * Sortable.
	 *
	 * @return array
	 */
	public function get_sortable_columns() {
		return array(
			'term_norm'   => array( 'term_norm', false ),
			'searches'    => array( 'searches', true ),
			'visitors'    => array( 'visitors', true ),
			'zero'        => array( 'zero', true ),
			'avg_results' => array( 'avg_results', false ),
			'ctr'         => array( 'ctr', true ),
			'revenue'     => array( 'revenue', true ),
			'last_search' => array( 'last_search', true ),
		);
	}

	/**
	 * Default.
	 *
	 * @param array  $item Row.
	 * @param string $col  Column.
	 * @return string
	 */
	protected function column_default( $item, $col ) {
		return isset( $item[ $col ] ) ? esc_html( asl_num( $item[ $col ] ) ) : '';
	}

	/**
	 * Term.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_term_norm( $item ) {
		$out = '<strong dir="auto">' . esc_html( $item['term_norm'] ) . '</strong>';
		if ( '' !== $item['rewritten'] ) {
			$out .= '<br><small class="asl-muted">&rarr; <span dir="auto">' . esc_html( urldecode( $item['rewritten'] ) ) . '</span></small>';
		}
		$actions = array(
			'filter' => sprintf( '<a href="%s">%s</a>', esc_url( ASL_Admin::logs_url( $item['term_norm'] ) ), esc_html__( 'Show searches', 'advanced-search-logger' ) ),
			'rule'   => sprintf( '<a href="%s">%s</a>', esc_url( ASL_Admin::new_rule_url( $item['term_norm'] ) ), esc_html__( 'Add rule', 'advanced-search-logger' ) ),
			'view'   => sprintf( '<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url( ASL_Admin::front_search_url( $item['sample'] ) ), esc_html__( 'Try on site', 'advanced-search-logger' ) ),
			'delete' => sprintf( '<a href="%s" class="asl-confirm" data-confirm="%s">%s</a>', esc_url( ASL_Admin::action_url( 'asl_delete_term', array( 'term' => $item['term_norm'] ) ) ), esc_attr__( 'Delete every log of this term?', 'advanced-search-logger' ), esc_html__( 'Delete logs', 'advanced-search-logger' ) ),
		);
		return $out . $this->row_actions( $actions );
	}

	/**
	 * No results.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_zero( $item ) {
		$zero = (int) $item['zero'];
		if ( ! $zero ) {
			return '<span class="asl-muted">0</span>';
		}
		$pct = round( 100 * $zero / max( 1, (int) $item['searches'] ) );
		return '<span class="asl-badge asl-badge--bad">' . esc_html( asl_num( $zero ) ) . '</span> <small class="asl-muted">' . esc_html( asl_num( $pct ) ) . '%</small>';
	}

	/**
	 * Avg results.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_avg_results( $item ) {
		return null === $item['avg_results'] ? '&mdash;' : esc_html( asl_num( $item['avg_results'], (float) $item['avg_results'] < 10 ? 1 : 0 ) );
	}

	/**
	 * CTR.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_ctr( $item ) {
		return esc_html( asl_num( $item['ctr'], 1 ) ) . '%';
	}

	/**
	 * Revenue.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_revenue( $item ) {
		$out = (float) $item['revenue'] > 0 ? wp_kses_post( wc_price( $item['revenue'] ) ) : '<span class="asl-muted">&mdash;</span>';
		if ( (int) $item['carts'] ) {
			/* translators: %s: number of add-to-cart events */
			$out .= '<br><small class="asl-muted">' . esc_html( sprintf( __( '%s carts', 'advanced-search-logger' ), asl_num( $item['carts'] ) ) ) . '</small>';
		}
		return $out;
	}

	/**
	 * Last.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_last_search( $item ) {
		return esc_html( asl_format_date( $item['last_search'] ) );
	}

	/**
	 * Query.
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'term_norm' );
		$per_page              = $this->get_items_per_page( 'asl_per_page', 20 );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'searches';
		$order   = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc';
		// phpcs:enable
		$this->items = ASL_DB::get_terms_report( $this->filters, $per_page, $this->get_pagenum(), $orderby, $order );
		$total       = ASL_DB::count_terms_report( $this->filters );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);
	}

	/**
	 * Empty.
	 */
	public function no_items() {
		esc_html_e( 'No searches match these filters.', 'advanced-search-logger' );
	}

	/**
	 * Filters.
	 *
	 * @param string $which Position.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' === $which ) {
			asl_render_filters( $this->filters );
		}
	}
}
