<?php
/**
 * Raw search log table.
 *
 * @package WebiSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class ASL_Logs_List_Table extends WP_List_Table {

	/**
	 * Active filters.
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
				'singular' => 'asl_log',
				'plural'   => 'asl_logs',
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
			'cb'           => '<input type="checkbox" />',
			'term'         => __( 'Search Term', 'advanced-search-logger' ),
			'result_count' => __( 'Results', 'advanced-search-logger' ),
			'clicks'       => __( 'Clicks', 'advanced-search-logger' ),
			'source'       => __( 'Source', 'advanced-search-logger' ),
			'user_id'      => __( 'User', 'advanced-search-logger' ),
			'timestamp'    => __( 'Date', 'advanced-search-logger' ),
		);
		if ( ! ASL_Settings::on( 'track_clicks' ) ) {
			unset( $cols['clicks'] );
		}
		return $cols;
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	public function get_sortable_columns() {
		return array(
			'term'         => array( 'term_norm', false ),
			'user_id'      => array( 'user_id', false ),
			'result_count' => array( 'result_count', false ),
			'clicks'       => array( 'clicks', true ),
			'source'       => array( 'source', false ),
			'timestamp'    => array( 'timestamp', true ),
		);
	}

	/**
	 * Checkbox column (v2 declared it but never rendered it).
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="ids[]" value="%d" />', (int) $item['id'] );
	}

	/**
	 * Default column.
	 *
	 * @param array  $item        Row.
	 * @param string $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '';
	}

	/**
	 * Term + actions.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_term( $item ) {
		$out = '<strong dir="auto">' . esc_html( $item['term'] ) . '</strong>';
		if ( '' !== $item['rewritten'] ) {
			$label = 'redirect' === $item['source'] ? __( 'redirected to', 'advanced-search-logger' ) : ( ! empty( $item['auto_fixed'] ) ? __( 'auto-fixed to', 'advanced-search-logger' ) : __( 'searched as', 'advanced-search-logger' ) );
			$out  .= '<br><small class="asl-muted">' . esc_html( $label ) . ': <span dir="auto">' . esc_html( urldecode( $item['rewritten'] ) ) . '</span></small>';
		}
		$actions = array(
			'filter' => sprintf( '<a href="%s">%s</a>', esc_url( ASL_Admin::logs_url( $item['term_norm'] ) ), esc_html__( 'View all for this term', 'advanced-search-logger' ) ),
			'rule'   => sprintf( '<a href="%s">%s</a>', esc_url( ASL_Admin::new_rule_url( $item['term_norm'] ) ), esc_html__( 'Add rule', 'advanced-search-logger' ) ),
			'view'   => sprintf( '<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url( ASL_Admin::front_search_url( $item['term'] ) ), esc_html__( 'Try on site', 'advanced-search-logger' ) ),
		);
		return $out . $this->row_actions( $actions );
	}

	/**
	 * User.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_user_id( $item ) {
		if ( (int) $item['user_id'] > 0 ) {
			$user = get_userdata( (int) $item['user_id'] );
			return $user ? '<a href="' . esc_url( get_edit_user_link( $user->ID ) ) . '">' . esc_html( $user->display_name ) . '</a>' : esc_html__( 'Deleted User', 'advanced-search-logger' );
		}
		return '<em class="asl-muted">' . esc_html__( 'Guest', 'advanced-search-logger' ) . '</em>';
	}

	/**
	 * Results.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_result_count( $item ) {
		$count = (int) $item['result_count'];
		if ( $count < 0 ) {
			return '<span class="asl-muted">&mdash;</span>';
		}
		if ( 0 === $count ) {
			return '<span class="asl-badge asl-badge--bad">0</span>';
		}
		return esc_html( asl_num( $count ) );
	}

	/**
	 * Clicks.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_clicks( $item ) {
		$out = (int) $item['clicks'] ? esc_html( asl_num( $item['clicks'] ) ) : '<span class="asl-muted">0</span>';
		if ( (int) $item['click_position'] ) {
			/* translators: %s: position number */
			$out .= ' <small class="asl-muted">' . esc_html( sprintf( __( '(#%s)', 'advanced-search-logger' ), asl_num( $item['click_position'] ) ) ) . '</small>';
		}
		if ( (int) $item['add_to_cart'] ) {
			$out .= ' <span class="dashicons dashicons-cart asl-good" title="' . esc_attr__( 'Added to cart', 'advanced-search-logger' ) . '"></span>';
		}
		if ( (float) $item['revenue'] > 0 && function_exists( 'wc_price' ) ) {
			$out .= '<br><small class="asl-good">' . wp_kses_post( wc_price( $item['revenue'] ) ) . '</small>';
		}
		return $out;
	}

	/**
	 * Source.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_source( $item ) {
		$out = '<span class="asl-source asl-source--' . esc_attr( $item['source'] ) . '">' . esc_html( asl_source_label( $item['source'] ) ) . '</span>';
		if ( $item['post_type'] && 'any' !== $item['post_type'] ) {
			$pto  = get_post_type_object( $item['post_type'] );
			$out .= '<br><small class="asl-muted">' . esc_html( $pto ? $pto->labels->name : $item['post_type'] ) . '</small>';
		}
		return $out;
	}

	/**
	 * Date.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_timestamp( $item ) {
		return '<span title="' . esc_attr( $item['timestamp'] ) . '">' . esc_html( asl_format_date( $item['timestamp'] ) ) . '</span>';
	}

	/**
	 * Bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array( 'delete' => __( 'Delete', 'advanced-search-logger' ) );
	}

	/**
	 * Query.
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'term' );
		$per_page              = $this->get_items_per_page( 'asl_per_page', 20 );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'timestamp';
		$order   = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc';
		// phpcs:enable
		$this->items = ASL_DB::get_logs( $this->filters, $per_page, $this->get_pagenum(), $orderby, $order );
		$total       = ASL_DB::count_logs( $this->filters );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);
	}

	/**
	 * Empty message.
	 */
	public function no_items() {
		esc_html_e( 'No searches match these filters.', 'advanced-search-logger' );
	}

	/**
	 * Filters above the table.
	 *
	 * @param string $which top|bottom.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		asl_render_filters( $this->filters );
	}
}

/**
 * Shared filter bar for both log views.
 *
 * @param array $f Filters.
 */
function asl_render_filters( array $f ) {
	echo '<div class="alignleft actions asl-filters">';
	printf( '<label class="screen-reader-text" for="asl-date-start">%s</label><input type="date" id="asl-date-start" name="date_start" value="%s" title="%s">', esc_html__( 'From', 'advanced-search-logger' ), esc_attr( $f['date_start'] ), esc_attr__( 'From', 'advanced-search-logger' ) );
	printf( '<label class="screen-reader-text" for="asl-date-end">%s</label><input type="date" id="asl-date-end" name="date_end" value="%s" title="%s">', esc_html__( 'To', 'advanced-search-logger' ), esc_attr( $f['date_end'] ), esc_attr__( 'To', 'advanced-search-logger' ) );

	$selects = array(
		'results' => array(
			''        => __( 'All results', 'advanced-search-logger' ),
			'zero'    => __( 'No results only', 'advanced-search-logger' ),
			'with'    => __( 'With results', 'advanced-search-logger' ),
			'clicked' => __( 'With a click', 'advanced-search-logger' ),
			'fixed'   => __( 'Rescued automatically', 'advanced-search-logger' ),
		),
		'source'  => array(
			''         => __( 'All sources', 'advanced-search-logger' ),
			'standard' => asl_source_label( 'standard' ),
			'ajax'     => asl_source_label( 'ajax' ),
			'redirect' => asl_source_label( 'redirect' ),
			'rest'     => asl_source_label( 'rest' ),
			'api'      => asl_source_label( 'api' ),
		),
		'user'    => array(
			''       => __( 'All visitors', 'advanced-search-logger' ),
			'guest'  => __( 'Guests', 'advanced-search-logger' ),
			'member' => __( 'Logged-in users', 'advanced-search-logger' ),
		),
	);
	foreach ( $selects as $name => $options ) {
		echo '<select name="' . esc_attr( $name ) . '">';
		foreach ( $options as $value => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $value ), selected( $f[ $name ], $value, false ), esc_html( $label ) );
		}
		echo '</select>';
	}
	if ( '' !== $f['term'] ) {
		printf( '<input type="hidden" name="term" value="%s">', esc_attr( $f['term'] ) );
	}
	submit_button( __( 'Filter', 'advanced-search-logger' ), '', 'filter_action', false );

	$active = array_filter( $f );
	if ( $active ) {
		$view = isset( $_GET['view'] ) && 'terms' === $_GET['view'] ? 'terms' : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		printf( ' <a class="button-link asl-reset" href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=asl-all-logs' . ( $view ? '&view=terms' : '' ) ) ), esc_html__( 'Reset filters', 'advanced-search-logger' ) );
	}
	echo '</div>';
}
