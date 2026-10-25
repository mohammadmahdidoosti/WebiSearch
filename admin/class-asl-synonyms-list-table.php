<?php
/**
 * Synonyms & redirects table.
 *
 * @package WebiSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class ASL_Synonyms_List_Table extends WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'asl_rule',
				'plural'   => 'asl_rules',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'       => '<input type="checkbox" />',
			'term'     => __( 'Search Term', 'advanced-search-logger' ),
			'type'     => __( 'Type', 'advanced-search-logger' ),
			'target'   => __( 'Target (Synonym or URL)', 'advanced-search-logger' ),
			'hits'     => __( 'Used', 'advanced-search-logger' ),
			'last_hit' => __( 'Last used', 'advanced-search-logger' ),
		);
	}

	/**
	 * Sortable.
	 *
	 * @return array
	 */
	public function get_sortable_columns() {
		return array(
			'term'     => array( 'term', false ),
			'type'     => array( 'type', false ),
			'hits'     => array( 'hits', true ),
			'last_hit' => array( 'last_hit', true ),
		);
	}

	/**
	 * Checkbox.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="ids[]" value="%d" />', (int) $item['id'] );
	}

	/**
	 * Default.
	 *
	 * @param array  $item Row.
	 * @param string $col  Column.
	 * @return string
	 */
	protected function column_default( $item, $col ) {
		return isset( $item[ $col ] ) ? esc_html( $item[ $col ] ) : '';
	}

	/**
	 * Term.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_term( $item ) {
		$on      = (int) $item['status'];
		$out     = '<strong dir="auto">' . esc_html( $item['term'] ) . '</strong>';
		$out    .= $on ? '' : ' <span class="asl-badge">' . esc_html__( 'Disabled', 'advanced-search-logger' ) . '</span>';
		$actions = array(
			'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=asl-synonyms&edit=' . (int) $item['id'] . '#asl-rule-form' ) ), esc_html__( 'Edit', 'advanced-search-logger' ) ),
			'toggle' => sprintf(
				'<a href="%s">%s</a>',
				esc_url(
					ASL_Admin::action_url(
						'asl_rule',
						array(
							'rule' => $item['id'],
							'do'   => $on ? 'disable' : 'enable',
						)
					)
				),
				$on ? esc_html__( 'Disable', 'advanced-search-logger' ) : esc_html__( 'Enable', 'advanced-search-logger' )
			),
			'logs'   => sprintf( '<a href="%s">%s</a>', esc_url( ASL_Admin::logs_url( ASL_Normalizer::normalize( $item['term'] ) ) ), esc_html__( 'Searches', 'advanced-search-logger' ) ),
			'delete' => sprintf(
				'<a href="%s" class="asl-confirm asl-danger-link" data-confirm="%s">%s</a>',
				esc_url(
					ASL_Admin::action_url(
						'asl_rule',
						array(
							'rule' => $item['id'],
							'do'   => 'delete',
						)
					)
				),
				esc_attr__( 'Delete this rule?', 'advanced-search-logger' ),
				esc_html__( 'Delete', 'advanced-search-logger' )
			),
		);
		return $out . $this->row_actions( $actions );
	}

	/**
	 * Type.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_type( $item ) {
		$types   = array(
			'synonym'  => __( 'Synonym', 'advanced-search-logger' ),
			'redirect' => __( 'Redirect', 'advanced-search-logger' ),
		);
		$matches = array(
			'exact'    => __( 'Exact match', 'advanced-search-logger' ),
			'contains' => __( 'Contains the word', 'advanced-search-logger' ),
			'starts'   => __( 'Starts with', 'advanced-search-logger' ),
		);
		$type    = isset( $types[ $item['type'] ] ) ? $types[ $item['type'] ] : $item['type'];
		$match   = isset( $matches[ $item['match_type'] ] ) ? $matches[ $item['match_type'] ] : $item['match_type'];
		return '<span class="asl-source asl-source--' . esc_attr( $item['type'] ) . '">' . esc_html( $type ) . '</span><br><small class="asl-muted">' . esc_html( $match ) . '</small>';
	}

	/**
	 * Target.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_target( $item ) {
		if ( 'redirect' === $item['type'] ) {
			$url = ASL_Rules::redirect_url( $item['target'], $item['term'] );
			return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener" dir="ltr">' . esc_html( urldecode( $item['target'] ) ) . '</a>';
		}
		return '<span dir="auto">' . esc_html( $item['target'] ) . '</span>';
	}

	/**
	 * Hits.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_hits( $item ) {
		return esc_html( asl_num( $item['hits'] ) );
	}

	/**
	 * Last hit.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_last_hit( $item ) {
		return $item['last_hit'] ? esc_html( asl_format_date( $item['last_hit'] ) ) : '<span class="asl-muted">&mdash;</span>';
	}

	/**
	 * Bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array(
			'enable'  => __( 'Enable', 'advanced-search-logger' ),
			'disable' => __( 'Disable', 'advanced-search-logger' ),
			'delete'  => __( 'Delete', 'advanced-search-logger' ),
		);
	}

	/**
	 * Query.
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'term' );
		$per_page              = 30;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$args = array(
			'search'   => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'type'     => isset( $_GET['rule_type'] ) ? sanitize_key( wp_unslash( $_GET['rule_type'] ) ) : '',
			'orderby'  => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'id',
			'order'    => isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc',
			'per_page' => $per_page,
			'page'     => $this->get_pagenum(),
		);
		// phpcs:enable
		$this->items = ASL_DB::list_rules( $args );
		$total       = ASL_DB::count_rules( $args );
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
		esc_html_e( 'No rules yet. Start with the searches that returned nothing on the dashboard.', 'advanced-search-logger' );
	}

	/**
	 * Type filter.
	 *
	 * @param string $which Position.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$type = isset( $_GET['rule_type'] ) ? sanitize_key( wp_unslash( $_GET['rule_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="alignleft actions"><select name="rule_type">';
		foreach ( array(
			''         => __( 'All types', 'advanced-search-logger' ),
			'synonym'  => __( 'Synonym', 'advanced-search-logger' ),
			'redirect' => __( 'Redirect', 'advanced-search-logger' ),
		) as $value => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $value ), selected( $type, $value, false ), esc_html( $label ) );
		}
		echo '</select>';
		submit_button( __( 'Filter', 'advanced-search-logger' ), '', 'filter_action', false );
		echo '</div>';
	}
}
