<?php
/**
 * Admin screens, actions and settings.
 *
 * @package WebiSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASL_Admin {

	/**
	 * Our page slugs.
	 *
	 * @var string[]
	 */
	private $pages = array( 'asl-dashboard', 'asl-review', 'asl-requests', 'asl-all-logs', 'asl-synonyms', 'asl-settings' );

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'add_dashboard_widget' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( ASL_PLUGIN_FILE ), array( $this, 'action_links' ) );
		add_filter( 'set-screen-option', array( $this, 'set_screen_option' ), 10, 3 );
		add_filter( 'set_screen_option_asl_per_page', array( $this, 'set_screen_option' ), 10, 3 );

		add_action( 'admin_post_asl_export', array( 'ASL_Export', 'handle' ) );
		add_action( 'admin_post_asl_clear_logs', array( $this, 'action_clear_logs' ) );
		add_action( 'admin_post_asl_save_rule', array( $this, 'action_save_rule' ) );
		add_action( 'admin_post_asl_rule', array( $this, 'action_rule' ) );
		add_action( 'admin_post_asl_import_rules', array( $this, 'action_import_rules' ) );
		add_action( 'admin_post_asl_resolve', array( $this, 'action_resolve' ) );
		add_action( 'admin_post_asl_delete_term', array( $this, 'action_delete_term' ) );
		add_action( 'admin_post_asl_test_email', array( $this, 'action_test_email' ) );
		add_action( 'admin_post_asl_review_decide', array( $this, 'action_review_decide' ) );
		add_action( 'admin_post_asl_request_update', array( $this, 'action_request_update' ) );
		add_action( 'admin_post_asl_request_delete', array( $this, 'action_request_delete' ) );
		add_action( 'admin_post_asl_request_term', array( $this, 'action_request_term' ) );

		// v2 links used admin.php?asl_action=...; keep them working.
		add_action( 'admin_init', array( $this, 'legacy_actions' ) );
	}

	/* ------------------------------------------------------------------ *
	 * Menu & assets
	 * ------------------------------------------------------------------ */

	/**
	 * Register menu.
	 */
	public function add_admin_menu() {
		$cap = asl_capability();
		add_menu_page( __( 'Search Logger', 'advanced-search-logger' ), __( 'Search Logger', 'advanced-search-logger' ), $cap, 'asl-dashboard', array( $this, 'render_dashboard_page' ), 'dashicons-search', 25 );
		add_submenu_page( 'asl-dashboard', __( 'Search Analytics Dashboard', 'advanced-search-logger' ), __( 'Dashboard', 'advanced-search-logger' ), $cap, 'asl-dashboard', array( $this, 'render_dashboard_page' ) );
		add_submenu_page( 'asl-dashboard', __( 'Failed searches review', 'advanced-search-logger' ), __( 'Failed searches', 'advanced-search-logger' ), $cap, 'asl-review', array( $this, 'render_review_page' ) );
		$new_requests = ASL_Requests::status_counts()['new'];
		$badge        = $new_requests ? ' <span class="awaiting-mod count-' . (int) $new_requests . '"><span class="pending-count">' . esc_html( asl_num( $new_requests ) ) . '</span></span>' : '';
		add_submenu_page( 'asl-dashboard', __( 'Product requests', 'advanced-search-logger' ), __( 'Product requests', 'advanced-search-logger' ) . $badge, $cap, 'asl-requests', array( $this, 'render_requests_page' ) );
		$logs = add_submenu_page( 'asl-dashboard', __( 'All Search Logs', 'advanced-search-logger' ), __( 'All Logs', 'advanced-search-logger' ), $cap, 'asl-all-logs', array( $this, 'render_logs_page' ) );
		$syn  = add_submenu_page( 'asl-dashboard', __( 'Synonyms & Redirects', 'advanced-search-logger' ), __( 'Synonyms & Redirects', 'advanced-search-logger' ), $cap, 'asl-synonyms', array( $this, 'render_synonyms_page' ) );
		add_submenu_page( 'asl-dashboard', __( 'Search Logger Settings', 'advanced-search-logger' ), __( 'Settings', 'advanced-search-logger' ), 'manage_options', 'asl-settings', array( $this, 'render_settings_page' ) );

		add_action( 'load-' . $logs, array( $this, 'load_logs_page' ) );
		add_action( 'load-' . $syn, array( $this, 'load_synonyms_page' ) );
	}

	/**
	 * Is the current screen one of ours?
	 *
	 * @return bool
	 */
	private function is_our_page() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return in_array( $page, $this->pages, true );
	}

	/**
	 * Enqueue CSS/JS (ApexCharts is bundled; no CDN needed).
	 *
	 * @param string $hook Hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'index.php' === $hook ) {
			wp_enqueue_style( 'asl-admin-styles', ASL_PLUGIN_URL . 'admin/assets/css/admin-styles.css', array(), ASL_VERSION );
			return;
		}
		if ( ! $this->is_our_page() ) {
			return;
		}
		wp_enqueue_style( 'asl-admin-styles', ASL_PLUGIN_URL . 'admin/assets/css/admin-styles.css', array(), ASL_VERSION );
		$page = sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'asl-dashboard' === $page ) {
			wp_enqueue_script( 'apexcharts', ASL_PLUGIN_URL . 'admin/assets/vendor/apexcharts.min.js', array(), '3.54.1', true );
		}
		wp_enqueue_script( 'asl-admin-scripts', ASL_PLUGIN_URL . 'admin/assets/js/admin-scripts.js', 'asl-dashboard' === $page ? array( 'apexcharts' ) : array(), ASL_VERSION, true );
	}

	/**
	 * Plugin list links.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=asl-dashboard' ) ) . '">' . esc_html__( 'Dashboard', 'advanced-search-logger' ) . '</a>',
			'<a href="' . esc_url( admin_url( 'admin.php?page=asl-settings' ) ) . '">' . esc_html__( 'Settings', 'advanced-search-logger' ) . '</a>'
		);
		return $links;
	}

	/**
	 * Save the "items per page" screen option.
	 *
	 * @param mixed  $status Status.
	 * @param string $option Option.
	 * @param int    $value  Value.
	 * @return mixed
	 */
	public function set_screen_option( $status, $option, $value ) {
		return 'asl_per_page' === $option ? min( 500, max( 1, (int) $value ) ) : $status;
	}

	/* ------------------------------------------------------------------ *
	 * Notices
	 * ------------------------------------------------------------------ */

	/**
	 * Redirect back with a message.
	 *
	 * @param string $page Page slug or full URL.
	 * @param string $msg  Message key.
	 * @param array  $args Extra args.
	 */
	private function back( $page, $msg, $args = array() ) {
		$url = preg_match( '#^(https?:|/)#', $page ) ? $page : admin_url( 'admin.php?page=' . $page );
		$url = remove_query_arg( array( 'asl_msg', 'asl_n', 'asl_err', '_wpnonce', 'action', 'action2', 'ids', 'id', 'rule', 'asl_action' ), $url );
		wp_safe_redirect( add_query_arg( array_merge( array( 'asl_msg' => $msg ), $args ), $url ) );
		exit;
	}

	/**
	 * Show messages.
	 */
	public function notices() {
		if ( ! $this->is_our_page() ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$msg = isset( $_GET['asl_msg'] ) ? sanitize_key( wp_unslash( $_GET['asl_msg'] ) ) : '';
		$n   = isset( $_GET['asl_n'] ) ? absint( $_GET['asl_n'] ) : 0;
		$err = isset( $_GET['asl_err'] ) ? sanitize_text_field( wp_unslash( $_GET['asl_err'] ) ) : '';
		// phpcs:enable
		$map = array(
			/* translators: %s: number */
			'deleted'       => sprintf( _n( '%s log deleted.', '%s logs deleted.', $n, 'advanced-search-logger' ), asl_num( $n ) ),
			'cleared'       => __( 'All search logs have been deleted.', 'advanced-search-logger' ),
			'rule_saved'    => __( 'Rule saved. It applies to new searches immediately.', 'advanced-search-logger' ),
			/* translators: %s: number */
			'rules_deleted' => sprintf( _n( '%s rule deleted.', '%s rules deleted.', $n, 'advanced-search-logger' ), asl_num( $n ) ),
			'rules_updated' => __( 'Rules updated.', 'advanced-search-logger' ),
			'imported'      => $err,
			'resolved'      => __( 'Marked as resolved. It will reappear only if it fails again.', 'advanced-search-logger' ),
			'unresolved'    => __( 'The term is back in the list.', 'advanced-search-logger' ),
			'mail_ok'       => __( 'Test report sent. Check the inbox (and spam folder).', 'advanced-search-logger' ),
			'mail_fail'     => __( 'WordPress could not send the e-mail. Check your SMTP/mail settings.', 'advanced-search-logger' ),
			'req_saved'     => __( 'Request updated.', 'advanced-search-logger' ),
			/* translators: %s: number */
			'req_deleted'   => sprintf( _n( '%s request deleted.', '%s requests deleted.', $n, 'advanced-search-logger' ), asl_num( $n ) ),
			/* translators: %s: number */
			'req_bulk'      => sprintf( _n( '%s request updated.', '%s requests updated.', $n, 'advanced-search-logger' ), asl_num( $n ) ),
			'error'         => $err,
		);
		if ( ! $msg || ! isset( $map[ $msg ] ) || '' === $map[ $msg ] ) {
			return;
		}
		$class = in_array( $msg, array( 'error', 'mail_fail' ), true ) ? 'notice-error' : 'notice-success';
		printf( '<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr( $class ), esc_html( $map[ $msg ] ) );
	}

	/* ------------------------------------------------------------------ *
	 * Actions
	 * ------------------------------------------------------------------ */

	/**
	 * Permission + nonce check.
	 *
	 * @param string $nonce Nonce action.
	 */
	private function guard( $nonce ) {
		if ( ! current_user_can( asl_capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'advanced-search-logger' ), 403 );
		}
		check_admin_referer( $nonce );
	}

	/**
	 * Delete everything.
	 */
	public function action_clear_logs() {
		$this->guard( 'asl_clear_logs' );
		ASL_DB::clear_all();
		$this->back( 'asl-all-logs', 'cleared' );
	}

	/**
	 * Add/edit a rule. Several terms may be entered at once (comma or new line).
	 */
	public function action_save_rule() {
		$this->guard( 'asl_save_rule' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard().
		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$type   = isset( $_POST['type'] ) && 'redirect' === $_POST['type'] ? 'redirect' : 'synonym';
		$match  = isset( $_POST['match_type'] ) ? sanitize_key( wp_unslash( $_POST['match_type'] ) ) : 'exact';
		$raw_t  = isset( $_POST['target'] ) ? wp_unslash( $_POST['target'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below by type.
		$terms  = isset( $_POST['term'] ) ? sanitize_textarea_field( wp_unslash( $_POST['term'] ) ) : '';
		$status = empty( $_POST['status'] ) ? 0 : 1;
		// phpcs:enable

		// v2 bug: sanitize_text_field() stripped %xx sequences and broke Persian (percent-encoded) URLs.
		$target = 'redirect' === $type ? ASL_Rules::sanitize_redirect_target( $raw_t ) : sanitize_text_field( $raw_t );
		if ( 'redirect' === $type && '' === $target ) {
			$this->back( 'asl-synonyms', 'error', array( 'asl_err' => __( 'Please enter a valid URL (https://...) or a path starting with /.', 'advanced-search-logger' ) ) );
		}

		$list = $id ? array( $terms ) : preg_split( '/[\r\n,،]+/u', $terms );
		$list = array_filter( array_map( 'trim', $list ) );
		if ( ! $list ) {
			$this->back( 'asl-synonyms', 'error', array( 'asl_err' => __( 'Both the search term and the target are required.', 'advanced-search-logger' ) ) );
		}
		$errors = array();
		foreach ( $list as $term ) {
			$res = ASL_DB::save_rule(
				array(
					'term'       => $term,
					'type'       => $type,
					'match_type' => $match,
					'target'     => $target,
					'status'     => $status,
				),
				$id
			);
			if ( is_wp_error( $res ) ) {
				$errors[] = $res->get_error_message();
			}
		}
		if ( $errors ) {
			$this->back( 'asl-synonyms', 'error', array( 'asl_err' => implode( ' ', $errors ) ) );
		}
		$this->back( 'asl-synonyms', 'rule_saved' );
	}

	/**
	 * Single-rule actions: delete, enable, disable.
	 */
	public function action_rule() {
		$this->guard( 'asl_rule' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$id = isset( $_GET['rule'] ) ? absint( $_GET['rule'] ) : 0;
		$do = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		// phpcs:enable
		if ( 'delete' === $do ) {
			ASL_DB::delete_rules( array( $id ) );
			$this->back( 'asl-synonyms', 'rules_deleted', array( 'asl_n' => 1 ) );
		}
		ASL_DB::set_rules_status( array( $id ), 'enable' === $do ? 1 : 0 );
		$this->back( 'asl-synonyms', 'rules_updated' );
	}

	/**
	 * Import rules CSV.
	 */
	public function action_import_rules() {
		$this->guard( 'asl_import_rules' );
		if ( empty( $_FILES['rules_csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['rules_csv']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$this->back( 'asl-synonyms', 'error', array( 'asl_err' => __( 'Please choose a CSV file.', 'advanced-search-logger' ) ) );
		}
		list( $added, $updated, $skipped ) = ASL_Export::import_rules( $_FILES['rules_csv']['tmp_name'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		/* translators: 1: added, 2: updated, 3: skipped */
		$text = sprintf( __( 'Import finished: %1$d added, %2$d updated, %3$d skipped.', 'advanced-search-logger' ), $added, $updated, $skipped );
		$this->back( 'asl-synonyms', 'imported', array( 'asl_err' => $text ) );
	}

	/**
	 * Mark a failed term as resolved (or undo).
	 */
	public function action_resolve() {
		$this->guard( 'asl_resolve' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
		$undo = ! empty( $_GET['undo'] );
		$ref  = wp_get_referer();
		// phpcs:enable
		ASL_DB::resolve_terms( array( ASL_Normalizer::normalize( $term ) ), $undo );
		$this->back( $ref ? $ref : 'asl-dashboard', $undo ? 'unresolved' : 'resolved' );
	}

	/**
	 * Delete all logs of a term.
	 */
	public function action_delete_term() {
		$this->guard( 'asl_delete_term' );
		$term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$n    = ASL_DB::delete_logs_by_term( ASL_Normalizer::normalize( $term ) );
		$ref  = wp_get_referer();
		$this->back( $ref ? $ref : 'asl-all-logs', 'deleted', array( 'asl_n' => $n ) );
	}

	/**
	 * Review page decisions (mark missing / ignore / automatic).
	 */
	public function action_review_decide() {
		$this->guard( 'asl_review_decide' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$term     = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
		$decision = isset( $_GET['decision'] ) ? sanitize_key( wp_unslash( $_GET['decision'] ) ) : '';
		// phpcs:enable
		ASL_Review::decide( ASL_Normalizer::normalize( $term ), in_array( $decision, array( 'missing', 'ignore' ), true ) ? $decision : '' );
		$ref = wp_get_referer();
		$this->back( $ref ? $ref : 'asl-review', 'rules_updated' );
	}

	/**
	 * Product requests: change status / note of one request.
	 */
	public function action_request_update() {
		$this->guard( 'asl_request_update' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard().
		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		$note   = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : null;
		// phpcs:enable
		ASL_Requests::update( array( $id ), $status, $note );
		$ref = wp_get_referer();
		$this->back( $ref ? $ref : 'asl-requests', 'req_saved' );
	}

	/**
	 * Product requests: delete one.
	 */
	public function action_request_delete() {
		$this->guard( 'asl_request_delete' );
		$id  = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$n   = ASL_Requests::delete( array( $id ) );
		$ref = wp_get_referer();
		$this->back( $ref ? $ref : 'asl-requests', 'req_deleted', array( 'asl_n' => $n ) );
	}

	/**
	 * Product requests: mark every open request of a product (e.g. after calling everyone).
	 */
	public function action_request_term() {
		$this->guard( 'asl_request_term' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$term   = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		// phpcs:enable
		$n   = ASL_Requests::update_term( ASL_Normalizer::normalize( $term ), $status );
		$ref = wp_get_referer();
		$this->back( $ref ? $ref : 'asl-requests', 'req_bulk', array( 'asl_n' => $n ) );
	}

	/**
	 * Product requests page.
	 */
	public function render_requests_page() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'open';
		$status  = in_array( $status, array_merge( array( 'open', 'all' ), array_keys( ASL_Requests::statuses() ) ), true ) ? $status : 'open';
		$q       = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$term    = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable
		$filters  = array(
			'status' => $status,
			'q'      => $q,
			'term'   => '' !== $term ? ASL_Normalizer::normalize( $term ) : '',
		);
		$per_page = 30;
		$total    = ASL_Requests::count( $filters );
		$rows     = ASL_Requests::query( $filters, $per_page, $paged );
		$counts   = ASL_Requests::status_counts();
		$top      = ASL_Requests::top_terms( 10 );
		require ASL_PLUGIN_DIR . 'admin/views/view-requests.php';
	}

	/**
	 * Failed searches review page.
	 */
	public function render_review_page() {
		$tab    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'missing'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tabs   = array( 'missing', 'approximate', 'fixed', 'found', 'ignored' );
		$tab    = in_array( $tab, $tabs, true ) ? $tab : 'missing';
		$data   = ASL_Review::data();
		$counts = ASL_Review::counts();
		$items  = ASL_Review::items( $tab );
		wp_add_inline_script(
			'asl-admin-scripts',
			'window.aslReview = ' . wp_json_encode(
				array(
					'ajax'    => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'asl_review' ),
					/* translators: 1: checked so far, 2: total */
					'running' => __( 'Checking… %1$s of %2$s', 'advanced-search-logger' ),
					'done'    => __( 'Done. Reloading…', 'advanced-search-logger' ),
					'auto'    => empty( $data['updated'] ) && ! empty( ASL_Review::failed_terms() ),
				)
			) . ';',
			'before'
		);
		require ASL_PLUGIN_DIR . 'admin/views/view-review.php';
	}

	/**
	 * Send a test report now.
	 */
	public function action_test_email() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '', 403 );
		}
		check_admin_referer( 'asl_test_email' );
		$this->back( 'asl-settings', ASL_Reports::send_report() ? 'mail_ok' : 'mail_fail' );
	}

	/**
	 * v2 URLs (asl_action=export_csv / delete_synonym / clear_logs).
	 */
	public function legacy_actions() {
		// phpcs:disable WordPress.Security.NonceVerification
		$action = isset( $_REQUEST['asl_action'] ) ? sanitize_key( wp_unslash( $_REQUEST['asl_action'] ) ) : '';
		if ( ! $action || ! current_user_can( asl_capability() ) ) {
			return;
		}
		if ( 'export_csv' === $action && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'asl_export_nonce' ) ) {
			$_REQUEST['_wpnonce'] = wp_create_nonce( 'asl_export' );
			ASL_Export::handle();
		}
		if ( 'delete_synonym' === $action && isset( $_GET['_wpnonce'], $_GET['id'] ) && wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'asl_delete_synonym_nonce' ) ) {
			ASL_DB::delete_rules( array( absint( $_GET['id'] ) ) );
			$this->back( 'asl-synonyms', 'rules_deleted', array( 'asl_n' => 1 ) );
		}
		// phpcs:enable
	}

	/* ------------------------------------------------------------------ *
	 * List-table bulk actions (run before output so we can redirect)
	 * ------------------------------------------------------------------ */

	/**
	 * Logs page: screen options + bulk delete.
	 */
	public function load_logs_page() {
		add_screen_option(
			'per_page',
			array(
				'default' => 20,
				'option'  => 'asl_per_page',
			)
		);
		$action = $this->current_bulk_action();
		if ( 'delete' === $action && ! empty( $_GET['ids'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			check_admin_referer( 'bulk-asl_logs' );
			if ( ! current_user_can( asl_capability() ) ) {
				wp_die( '', 403 );
			}
			$n = ASL_DB::delete_logs( array_map( 'absint', (array) wp_unslash( $_GET['ids'] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$this->back( remove_query_arg( array( 'ids', '_wp_http_referer' ), wp_unslash( $_SERVER['REQUEST_URI'] ) ), 'deleted', array( 'asl_n' => $n ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}
		// Strip the long _wp_http_referer from filter submissions (keeps URLs short and shareable).
		if ( ! empty( $_GET['_wp_http_referer'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_safe_redirect( remove_query_arg( array( '_wp_http_referer', '_wpnonce', 'action', 'action2', 'filter_action' ), wp_unslash( $_SERVER['REQUEST_URI'] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			exit;
		}
	}

	/**
	 * Rules page: bulk actions.
	 */
	public function load_synonyms_page() {
		$action = $this->current_bulk_action();
		if ( $action && ! empty( $_GET['ids'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			check_admin_referer( 'bulk-asl_rules' );
			if ( ! current_user_can( asl_capability() ) ) {
				wp_die( '', 403 );
			}
			$ids = array_map( 'absint', (array) wp_unslash( $_GET['ids'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( 'delete' === $action ) {
				ASL_DB::delete_rules( $ids );
				$this->back( 'asl-synonyms', 'rules_deleted', array( 'asl_n' => count( $ids ) ) );
			}
			if ( in_array( $action, array( 'enable', 'disable' ), true ) ) {
				ASL_DB::set_rules_status( $ids, 'enable' === $action ? 1 : 0 );
				$this->back( 'asl-synonyms', 'rules_updated' );
			}
		}
		if ( ! empty( $_GET['_wp_http_referer'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_safe_redirect( remove_query_arg( array( '_wp_http_referer', '_wpnonce', 'action', 'action2' ), wp_unslash( $_SERVER['REQUEST_URI'] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			exit;
		}
	}

	/**
	 * Selected bulk action.
	 *
	 * @return string
	 */
	private function current_bulk_action() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		foreach ( array( 'action', 'action2' ) as $k ) {
			if ( isset( $_GET[ $k ] ) && '-1' !== $_GET[ $k ] && '' !== $_GET[ $k ] ) {
				return sanitize_key( wp_unslash( $_GET[ $k ] ) );
			}
		}
		// phpcs:enable
		return '';
	}

	/* ------------------------------------------------------------------ *
	 * Pages
	 * ------------------------------------------------------------------ */

	/**
	 * Dashboard.
	 */
	public function render_dashboard_page() {
		$allowed = array( 7, 30, 90, 365 );
		$days    = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : 30; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$days    = in_array( $days, $allowed, true ) ? $days : 30;
		$stats   = ASL_DB::get_stats( $days );
		$chart   = array(
			'daily'   => $stats['daily'],
			'hours'   => $stats['hours'],
			'sources' => $stats['sources'],
			'top'     => array_slice( $stats['top'], 0, 10 ),
			'locale'  => str_replace( '_', '-', determine_locale() ),
			'jalali'  => asl_is_persian() && ASL_Settings::on( 'jalali_dates' ),
			'rtl'     => is_rtl(),
			'i18n'    => array(
				'searches'  => __( 'Searches', 'advanced-search-logger' ),
				'noResults' => __( 'No results', 'advanced-search-logger' ),
				'hour'      => __( 'Hour of day', 'advanced-search-logger' ),
				'noData'    => __( 'No data for this period yet.', 'advanced-search-logger' ),
			),
		);
		wp_add_inline_script( 'asl-admin-scripts', 'window.aslDashboard = ' . wp_json_encode( $chart ) . ';', 'before' );
		require ASL_PLUGIN_DIR . 'admin/views/view-dashboard.php';
	}

	/**
	 * Logs (raw + per-term report).
	 */
	public function render_logs_page() {
		require_once ASL_PLUGIN_DIR . 'admin/class-asl-logs-list-table.php';
		require_once ASL_PLUGIN_DIR . 'admin/class-asl-terms-list-table.php';
		$view    = isset( $_GET['view'] ) && 'terms' === $_GET['view'] ? 'terms' : 'logs'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$table   = 'terms' === $view ? new ASL_Terms_List_Table() : new ASL_Logs_List_Table();
		$filters = ASL_DB::filters_from_request( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$table->prepare_items();
		require ASL_PLUGIN_DIR . 'admin/views/view-logs-page.php';
	}

	/**
	 * Synonyms & redirects.
	 */
	public function render_synonyms_page() {
		require_once ASL_PLUGIN_DIR . 'admin/class-asl-synonyms-list-table.php';
		$table = new ASL_Synonyms_List_Table();
		$table->prepare_items();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$edit = isset( $_GET['edit'] ) ? ASL_DB::get_rule( absint( $_GET['edit'] ) ) : null;
		$pre  = array(
			'term'   => isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '',
			'target' => isset( $_GET['target'] ) ? sanitize_text_field( wp_unslash( $_GET['target'] ) ) : '',
		);
		$test_term = isset( $_GET['asl_test'] ) ? sanitize_text_field( wp_unslash( $_GET['asl_test'] ) ) : '';
		$test      = '' !== $test_term ? ASL_Smart::explain( $test_term ) : null;
		// phpcs:enable
		require ASL_PLUGIN_DIR . 'admin/views/view-synonyms.php';
	}

	/**
	 * Settings.
	 */
	public function render_settings_page() {
		require ASL_PLUGIN_DIR . 'admin/views/view-settings.php';
	}

	/* ------------------------------------------------------------------ *
	 * Settings API
	 * ------------------------------------------------------------------ */

	/**
	 * Register settings.
	 */
	public function register_settings() {
		register_setting( 'asl_settings_group', ASL_Settings::OPTION, array( 'sanitize_callback' => array( $this, 'sanitize_settings' ) ) );
		$p = 'asl-settings-page';

		add_settings_section( 'asl_general_section', __( 'What gets logged', 'advanced-search-logger' ), null, $p );
		$this->field( 'logging_enabled', __( 'Enable logging', 'advanced-search-logger' ), 'checkbox', 'asl_general_section' );
		$this->field( 'min_length', __( 'Minimum search length (characters)', 'advanced-search-logger' ), 'number', 'asl_general_section', __( 'Shorter searches are not logged.', 'advanced-search-logger' ) );
		$this->field( 'dedupe_window', __( 'Merge repeated & as-you-type searches (seconds)', 'advanced-search-logger' ), 'number', 'asl_general_section', __( 'Live search fires a request for almost every key press. Within this window, the same visitor\'s keystrokes ("پم", "پمپ", "پمپ آب") are stored as one search with the final term, and reloading the same search is counted once. 0 = off.', 'advanced-search-logger' ) );
		$this->field( 'exclude_roles', __( 'Do not log these user roles', 'advanced-search-logger' ), 'roles', 'asl_general_section', __( 'Searches by your own staff while testing distort the statistics.', 'advanced-search-logger' ) );
		$this->field( 'ignore_bots', __( 'Ignore bots and crawlers', 'advanced-search-logger' ), 'checkbox', 'asl_general_section' );
		$this->field( 'ignore_spam', __( 'Ignore spam searches', 'advanced-search-logger' ), 'checkbox', 'asl_general_section', __( 'Searches containing links, e-mail addresses, code or very long random strings.', 'advanced-search-logger' ) );
		$this->field( 'exclude_terms', __( 'Ignore these terms', 'advanced-search-logger' ), 'textarea', 'asl_general_section', __( 'One per line. Use * as a wildcard, e.g. test*', 'advanced-search-logger' ) );
		$this->field( 'exclude_ips', __( 'Ignore these IP addresses', 'advanced-search-logger' ), 'textarea', 'asl_general_section', __( 'One per line. Supports 1.2.3.* and CIDR ranges like 10.0.0.0/8.', 'advanced-search-logger' ) . ' ' . sprintf( /* translators: %s: IP */ __( 'Your current IP: %s', 'advanced-search-logger' ), asl_client_ip() ) );
		$this->field( 'log_rest', __( 'Log REST API searches', 'advanced-search-logger' ), 'checkbox', 'asl_general_section', __( 'For headless sites and apps using /wp-json/. Off by default because editors also search through the REST API.', 'advanced-search-logger' ) );

		add_settings_section( 'asl_search_section', __( 'Better search results', 'advanced-search-logger' ), null, $p );
		$this->field( 'normalize_query', __( 'Fix Arabic characters in searches', 'advanced-search-logger' ), 'checkbox', 'asl_search_section', __( 'Converts Arabic ي and ك to Persian ی and ک and removes tatweel, diacritics and invisible characters before searching. Visitors with Arabic keyboards will find your products instead of getting "nothing found".', 'advanced-search-logger' ) );
		$this->field( 'variant_search', __( 'Match any spelling of Persian letters and digits', 'advanced-search-logger' ), 'checkbox', 'asl_search_section', __( 'A search for "سایز 63" also finds titles written as "سايز ۶۳" (Arabic ي/ك, Persian or Latin digits, half-space or space). Recommended: product titles are often typed with different keyboards.', 'advanced-search-logger' ) );
		$this->field( 'search_in', __( 'Search in', 'advanced-search-logger' ), 'search_in', 'asl_search_section', __( 'WordPress also searches the full description, so a product whose description merely mentions a word ("…can be used with a tank") appears for that word. Title only gives cleaner results for shops with descriptive titles.', 'advanced-search-logger' ) );
		$this->field( 'smart_fallback', __( 'Rescue searches that find nothing', 'advanced-search-logger' ), 'checkbox', 'asl_search_section', __( 'Only when a search returns zero results: separates numbers glued to words (مغزی1/2), removes text pasted from product pages, corrects words that do not exist in your product titles (missing آ, typos, words written together) and, if needed, searches with fewer words. Searches that already work are never changed.', 'advanced-search-logger' ) );
		$this->field( 'fallback_notice', __( 'Tell the visitor what was searched instead', 'advanced-search-logger' ), 'checkbox', 'asl_search_section', __( 'Shows "Showing results for …" above the results, like Google.', 'advanced-search-logger' ) );
		$this->field( 'no_results_help', __( 'Help on empty result pages', 'advanced-search-logger' ), 'checkbox', 'asl_search_section', __( 'When nothing is found even after the rescue, WooCommerce result pages show popular searches and the text below. For other themes use the [webisearch_no_results] shortcode.', 'advanced-search-logger' ) );
		$this->field( 'no_results_text', __( 'Text for empty result pages', 'advanced-search-logger' ), 'html', 'asl_search_section', __( 'Optional. Example: Didn\'t find it? Call us on 021-… or message us on WhatsApp — we probably have it.', 'advanced-search-logger' ) );
		$this->field( 'nocache_search', __( 'Do not cache search result pages', 'advanced-search-logger' ), 'checkbox', 'asl_search_section', __( 'Page caches (LiteSpeed, WP Rocket, W3 Total Cache...) serve cached search pages without running WordPress, so those searches would be missing from the logs.', 'advanced-search-logger' ) );

		add_settings_section( 'asl_request_section', __( 'Product requests ("leave your number")', 'advanced-search-logger' ), array( $this, 'request_section_intro' ), $p );
		$this->field( 'request_enabled', __( 'Show the request box', 'advanced-search-logger' ), 'checkbox', 'asl_request_section', __( 'When a search finds nothing (even after the automatic rescue), visitors see a box to leave their phone number instead of a dead end. For page builders use the [webisearch_request_form] shortcode.', 'advanced-search-logger' ) );
		$this->field( 'request_replace', __( 'Replace the "No products were found" message', 'advanced-search-logger' ), 'checkbox', 'asl_request_section', __( 'Hides the theme\'s default WooCommerce message on empty search pages, so the box takes its place.', 'advanced-search-logger' ) );
		$this->field( 'request_on_rescued', __( 'Also offer it above close matches', 'advanced-search-logger' ), 'checkbox', 'asl_request_section', __( 'When the exact product is not found and visitors see related products (found with fewer words), a one-line, expandable version is shown under "Showing results for …".', 'advanced-search-logger' ) );
		$this->field( 'request_name', __( 'Ask for the name', 'advanced-search-logger' ), 'checkbox', 'asl_request_section', __( 'Optional field; the phone number is always required.', 'advanced-search-logger' ) );
		$this->field( 'request_title', __( 'Box title', 'advanced-search-logger' ), 'text', 'asl_request_section', __( 'Leave empty for the default.', 'advanced-search-logger' ) . ' ' . sprintf( /* translators: %s: default text */ __( 'Default: %s', 'advanced-search-logger' ), ASL_Requests::text( 'request_title', '{term}' ) ) . ' ' . __( '{term} is replaced with what the visitor searched.', 'advanced-search-logger' ) );
		$this->field( 'request_text', __( 'Box text', 'advanced-search-logger' ), 'text', 'asl_request_section', sprintf( /* translators: %s: default text */ __( 'Default: %s', 'advanced-search-logger' ), ASL_Requests::text( 'request_text', '{term}' ) ) );
		$this->field( 'request_button', __( 'Button text', 'advanced-search-logger' ), 'text', 'asl_request_section', sprintf( /* translators: %s: default text */ __( 'Default: %s', 'advanced-search-logger' ), ASL_Requests::text( 'request_button' ) ) );
		$this->field( 'request_success', __( 'Thank-you message', 'advanced-search-logger' ), 'text', 'asl_request_section', sprintf( /* translators: %s: default text */ __( 'Default: %s', 'advanced-search-logger' ), ASL_Requests::text( 'request_success', '{term}' ) ) );
		$this->field( 'request_notify', __( 'E-mail each new request', 'advanced-search-logger' ), 'checkbox', 'asl_request_section' );
		$this->field( 'request_notify_email', __( 'Send request e-mails to', 'advanced-search-logger' ), 'text', 'asl_request_section', __( 'Separate several addresses with commas. Empty = the site admin e-mail.', 'advanced-search-logger' ) . ' ' . __( 'For SMS, developers can use the asl_request_created action.', 'advanced-search-logger' ) );

		add_settings_section( 'asl_tracking_section', __( 'Clicks & conversions', 'advanced-search-logger' ), array( $this, 'tracking_section_intro' ), $p );
		$this->field( 'track_clicks', __( 'Track clicks on search results', 'advanced-search-logger' ), 'checkbox', 'asl_tracking_section', __( 'Measures click-through rate and click position for the results page and for live search dropdowns.', 'advanced-search-logger' ) );
		$this->field( 'track_conversions', __( 'Track add-to-cart and revenue (WooCommerce)', 'advanced-search-logger' ), 'checkbox', 'asl_tracking_section' );
		$this->field( 'revenue_status', __( 'Count revenue when the order is', 'advanced-search-logger' ), 'revenue_status', 'asl_tracking_section', __( 'Cash-on-delivery orders are "Processing" before any money is received, so "Completed" gives the real figure. Revenue of orders that are later cancelled, failed or refunded is always taken back.', 'advanced-search-logger' ) );
		$this->field( 'live_selectors', __( 'Extra live-search CSS selectors', 'advanced-search-logger' ), 'textarea', 'asl_tracking_section', __( 'Only needed if clicks in your theme\'s live search dropdown are not counted. One CSS selector per line, e.g. .my-search-dropdown', 'advanced-search-logger' ) );

		add_settings_section( 'asl_cleanup_section', __( 'Privacy & storage', 'advanced-search-logger' ), null, $p );
		$this->field( 'store_user', __( 'Link searches to logged-in users', 'advanced-search-logger' ), 'checkbox', 'asl_cleanup_section', __( 'When off, every search is stored anonymously. IP addresses are never stored.', 'advanced-search-logger' ) );
		$this->field( 'cleanup_days', __( 'Auto-delete logs older than (days)', 'advanced-search-logger' ), 'number', 'asl_cleanup_section', __( 'Days to keep logs (0 = keep forever).', 'advanced-search-logger' ) );
		$this->field( 'jalali_dates', __( 'Show dates in the Jalali calendar', 'advanced-search-logger' ), 'checkbox', 'asl_cleanup_section' );

		add_settings_section( 'asl_email_section', __( 'Weekly e-mail report', 'advanced-search-logger' ), null, $p );
		$this->field( 'email_enable', __( 'Send a weekly report', 'advanced-search-logger' ), 'checkbox', 'asl_email_section' );
		$this->field( 'email_recipient', __( 'Recipients', 'advanced-search-logger' ), 'text', 'asl_email_section', __( 'Separate several addresses with commas.', 'advanced-search-logger' ) );
		$this->field( 'email_subject', __( 'Email subject', 'advanced-search-logger' ), 'text', 'asl_email_section' );
		$this->field( 'email_body', __( 'Email body template', 'advanced-search-logger' ), 'editor', 'asl_email_section', __( 'Leave empty to use the default template.', 'advanced-search-logger' ) . ' ' . __( 'Placeholders:', 'advanced-search-logger' ) . ' {site_name} {report_date} {kpis} {total_searches} {no_result_rate} {top_searches} {no_result_searches} {trending_searches} {dashboard_url}' );
	}

	/**
	 * Intro for the product requests section.
	 */
	public function request_section_intro() {
		/* translators: %s: link to the product requests page */
		echo '<p>' . sprintf( esc_html__( 'Turn "nothing found" into a sales lead. Requests appear in %s.', 'advanced-search-logger' ), '<a href="' . esc_url( admin_url( 'admin.php?page=asl-requests' ) ) . '">' . esc_html__( 'Product requests', 'advanced-search-logger' ) . '</a>' ) . '</p>';
	}

	/**
	 * Intro for the tracking section.
	 */
	public function tracking_section_intro() {
		echo '<p>' . esc_html__( 'A small script (about 1.5 KB, no jQuery) records which result a visitor opens after searching. WooCommerce add-to-cart and paid orders are credited to the search that led to them.', 'advanced-search-logger' ) . '</p>';
	}

	/**
	 * Add a settings field.
	 *
	 * @param string $key     Key.
	 * @param string $label   Label.
	 * @param string $type    Type.
	 * @param string $section Section.
	 * @param string $desc    Description.
	 */
	private function field( $key, $label, $type, $section, $desc = '' ) {
		add_settings_field(
			'asl_' . $key,
			$label,
			array( $this, 'render_field' ),
			'asl-settings-page',
			$section,
			array(
				'key'       => $key,
				'type'      => $type,
				'desc'      => $desc,
				'label_for' => in_array( $type, array( 'roles', 'editor' ), true ) ? '' : 'asl_' . $key,
			)
		);
	}

	/**
	 * Render a field.
	 *
	 * @param array $a Args.
	 */
	public function render_field( $a ) {
		$key  = $a['key'];
		$val  = ASL_Settings::get( $key );
		$name = ASL_Settings::OPTION . '[' . $key . ']';
		$id   = 'asl_' . $key;
		switch ( $a['type'] ) {
			case 'checkbox':
				printf( '<input type="hidden" name="%1$s" value="off"><label><input type="checkbox" id="%2$s" name="%1$s" value="on" %3$s> %4$s</label>', esc_attr( $name ), esc_attr( $id ), checked( 'on', $val, false ), esc_html__( 'Enabled', 'advanced-search-logger' ) );
				break;
			case 'number':
				printf( '<input type="number" id="%s" name="%s" value="%s" min="0" class="small-text">', esc_attr( $id ), esc_attr( $name ), esc_attr( $val ) );
				break;
			case 'search_in':
				$opts = array(
					'all'           => __( 'Title, short and full description (WordPress default)', 'advanced-search-logger' ),
					'title_excerpt' => __( 'Title and short description', 'advanced-search-logger' ),
					'title'         => __( 'Title only', 'advanced-search-logger' ),
				);
				printf( '<select id="%s" name="%s">', esc_attr( $id ), esc_attr( $name ) );
				foreach ( $opts as $k => $label ) {
					printf( '<option value="%s" %s>%s</option>', esc_attr( $k ), selected( $val, $k, false ), esc_html( $label ) );
				}
				echo '</select>';
				break;
			case 'revenue_status':
				$opts = array(
					'completed' => __( 'Completed only (recommended)', 'advanced-search-logger' ),
					'paid'      => __( 'Processing or completed', 'advanced-search-logger' ),
				);
				printf( '<select id="%s" name="%s">', esc_attr( $id ), esc_attr( $name ) );
				foreach ( $opts as $k => $label ) {
					printf( '<option value="%s" %s>%s</option>', esc_attr( $k ), selected( $val, $k, false ), esc_html( $label ) );
				}
				echo '</select>';
				break;
			case 'html':
				printf( '<textarea id="%s" name="%s" rows="3" class="large-text" dir="auto">%s</textarea>', esc_attr( $id ), esc_attr( $name ), esc_textarea( $val ) );
				break;
			case 'textarea':
				printf( '<textarea id="%s" name="%s" rows="4" class="large-text code" dir="auto">%s</textarea>', esc_attr( $id ), esc_attr( $name ), esc_textarea( $val ) );
				break;
			case 'roles':
				echo '<fieldset class="asl-roles">';
				foreach ( wp_roles()->get_names() as $role => $role_name ) {
					printf( '<label><input type="checkbox" name="%s[]" value="%s" %s> %s</label> ', esc_attr( $name ), esc_attr( $role ), checked( in_array( $role, (array) $val, true ), true, false ), esc_html( translate_user_role( $role_name ) ) );
				}
				printf( '<input type="hidden" name="%s[]" value="">', esc_attr( $name ) );
				echo '</fieldset>';
				break;
			case 'editor':
				wp_editor(
					(string) $val,
					'asl_email_body',
					array(
						'textarea_name' => $name,
						'textarea_rows' => 10,
						'media_buttons' => false,
					)
				);
				break;
			default:
				printf( '<input type="text" id="%s" name="%s" value="%s" class="regular-text" dir="auto">', esc_attr( $id ), esc_attr( $name ), esc_attr( $val ) );
		}
		if ( $a['desc'] ) {
			echo '<p class="description">' . esc_html( $a['desc'] ) . '</p>';
		}
	}

	/**
	 * Sanitize settings (merges with current values so partial saves never wipe options).
	 *
	 * @param array $in Input.
	 * @return array
	 */
	public function sanitize_settings( $in ) {
		$in  = is_array( $in ) ? $in : array();
		$out = ASL_Settings::all();
		if ( isset( $in['search_in'] ) ) {
			$out['search_in'] = in_array( $in['search_in'], array( 'all', 'title_excerpt', 'title' ), true ) ? $in['search_in'] : 'all';
		}
		if ( isset( $in['revenue_status'] ) ) {
			$new = 'paid' === $in['revenue_status'] ? 'paid' : 'completed';
			if ( $new !== $out['revenue_status'] ) {
				update_option( 'asl_revenue_resync', 1, false ); // Recount existing orders with the new rule.
			}
			$out['revenue_status'] = $new;
		}
		if ( isset( $in['no_results_text'] ) ) {
			$out['no_results_text'] = wp_kses_post( $in['no_results_text'] );
		}
		foreach ( array( 'variant_search', 'smart_fallback', 'fallback_notice', 'no_results_help', 'logging_enabled', 'ignore_bots', 'ignore_spam', 'log_rest', 'store_user', 'normalize_query', 'nocache_search', 'track_clicks', 'track_conversions', 'jalali_dates', 'email_enable', 'request_enabled', 'request_replace', 'request_on_rescued', 'request_name', 'request_notify' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = 'on' === $in[ $k ] ? 'on' : 'off';
			}
		}
		foreach ( array(
			'min_length'    => array( 1, 50 ),
			'dedupe_window' => array( 0, 600 ),
			'cleanup_days'  => array( 0, 3650 ),
		) as $k => $range ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = min( $range[1], max( $range[0], (int) $in[ $k ] ) );
			}
		}
		foreach ( array( 'exclude_terms', 'exclude_ips', 'live_selectors' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = sanitize_textarea_field( $in[ $k ] );
			}
		}
		if ( isset( $in['exclude_roles'] ) ) {
			$roles                = array_keys( wp_roles()->get_names() );
			$out['exclude_roles'] = array_values( array_intersect( array_map( 'sanitize_key', (array) $in['exclude_roles'] ), $roles ) );
		}
		if ( isset( $in['email_recipient'] ) ) {
			$emails                 = array_filter( array_map( 'sanitize_email', array_map( 'trim', explode( ',', $in['email_recipient'] ) ) ) );
			$out['email_recipient'] = $emails ? implode( ', ', $emails ) : get_option( 'admin_email' );
		}
		foreach ( array( 'request_title', 'request_text', 'request_button', 'request_success' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = sanitize_text_field( $in[ $k ] );
			}
		}
		if ( isset( $in['request_notify_email'] ) ) {
			$emails                      = array_filter( array_map( 'sanitize_email', array_map( 'trim', explode( ',', $in['request_notify_email'] ) ) ) );
			$out['request_notify_email'] = implode( ', ', $emails );
		}
		if ( isset( $in['email_subject'] ) ) {
			$out['email_subject'] = sanitize_text_field( $in['email_subject'] );
		}
		if ( isset( $in['email_body'] ) ) {
			$out['email_body'] = wp_kses_post( $in['email_body'] );
		}
		ASL_Settings::flush();
		ASL_Activator::schedule_cron_jobs();
		return $out;
	}

	/* ------------------------------------------------------------------ *
	 * WP dashboard widget
	 * ------------------------------------------------------------------ */

	/**
	 * Register widget.
	 */
	public function add_dashboard_widget() {
		if ( current_user_can( asl_capability() ) ) {
			wp_add_dashboard_widget( 'asl_dashboard_widget', __( 'Site search (last 7 days)', 'advanced-search-logger' ), array( $this, 'render_dashboard_widget' ) );
		}
	}

	/**
	 * Render widget.
	 */
	public function render_dashboard_widget() {
		$stats = ASL_DB::get_stats( 7 );
		$k     = $stats['kpis'];
		echo '<div class="asl-widget-kpis">';
		printf( '<div><span>%s</span><strong>%s</strong></div>', esc_html__( 'Searches', 'advanced-search-logger' ), esc_html( asl_num( $k['total'] ) ) );
		printf( '<div><span>%s</span><strong class="%s">%s%%</strong></div>', esc_html__( 'No results', 'advanced-search-logger' ), $k['zero_rate'] > 15 ? 'asl-bad' : '', esc_html( asl_num( $k['zero_rate'], 1 ) ) );
		printf( '<div><span>%s</span><strong>%s%%</strong></div>', esc_html__( 'CTR', 'advanced-search-logger' ), esc_html( asl_num( $k['ctr'], 1 ) ) );
		echo '</div><div class="asl-widget-container"><div class="asl-widget-column"><strong>' . esc_html__( 'Top 5 Searches', 'advanced-search-logger' ) . '</strong>';
		$this->widget_list( array_slice( $stats['top'], 0, 5 ), false );
		echo '</div><div class="asl-widget-column"><strong>' . esc_html__( 'Top 5 No-Result Searches', 'advanced-search-logger' ) . '</strong>';
		$this->widget_list( array_slice( $stats['zero'], 0, 5 ), true );
		echo '</div></div>';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=asl-dashboard' ) ) . '" class="button button-primary">' . esc_html__( 'View Full Dashboard', 'advanced-search-logger' ) . '</a>';
	}

	/**
	 * Widget list.
	 *
	 * @param array $rows Rows.
	 * @param bool  $zero Failed searches.
	 */
	private function widget_list( $rows, $zero ) {
		if ( ! $rows ) {
			echo '<p><em>' . esc_html__( 'No data yet.', 'advanced-search-logger' ) . '</em></p>';
			return;
		}
		echo '<ul>';
		foreach ( $rows as $r ) {
			$label = esc_html( $r['term'] );
			if ( $zero ) {
				$label = '<a href="' . esc_url( admin_url( 'admin.php?page=asl-synonyms&term=' . rawurlencode( $r['term'] ) ) ) . '">' . $label . '</a>';
			}
			printf( '<li class="%s">%s <span>%s</span></li>', $zero ? 'asl-no-results' : '', $label, esc_html( asl_num( $r['count'] ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		}
		echo '</ul>';
	}

	/* ------------------------------------------------------------------ *
	 * URL helpers for views
	 * ------------------------------------------------------------------ */

	/**
	 * Nonced admin-post URL.
	 *
	 * @param string $action Action.
	 * @param array  $args   Args.
	 * @return string
	 */
	public static function action_url( $action, $args = array() ) {
		return wp_nonce_url( add_query_arg( array_merge( array( 'action' => $action ), array_map( 'rawurlencode', $args ) ), admin_url( 'admin-post.php' ) ), $action );
	}

	/**
	 * Link to create a rule for a term.
	 *
	 * @param string $term   Term.
	 * @param string $target Suggested target.
	 * @return string
	 */
	public static function new_rule_url( $term, $target = '' ) {
		$args = array(
			'page' => 'asl-synonyms',
			'term' => rawurlencode( $term ),
		);
		if ( '' !== $target ) {
			$args['target'] = rawurlencode( $target );
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) ) . '#asl-rule-form';
	}

	/**
	 * Link to the raw logs of a term.
	 *
	 * @param string $term Term.
	 * @param array  $more Extra filters.
	 * @return string
	 */
	public static function logs_url( $term = '', $more = array() ) {
		$args = array_merge( array( 'page' => 'asl-all-logs' ), $more );
		if ( '' !== $term ) {
			$args['term'] = rawurlencode( $term );
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Front-end search URL.
	 *
	 * @param string $term Term.
	 * @return string
	 */
	public static function front_search_url( $term ) {
		$args = array( 's' => rawurlencode( $term ) );
		if ( class_exists( 'WooCommerce' ) ) {
			$args['post_type'] = 'product';
		}
		return add_query_arg( $args, home_url( '/' ) );
	}
}
