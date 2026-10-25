<?php
/**
 * Logs page view.
 *
 * @package WebiSearch
 * @var WP_List_Table $table
 * @var string        $view
 * @var array         $filters
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Filters as URL arguments (the text search travels as "s").
$keep = array_filter( $filters );
if ( isset( $keep['search'] ) ) {
	$keep['s'] = $keep['search'];
	unset( $keep['search'] );
}
$export_url = ASL_Admin::action_url( 'asl_export', array_merge( $keep, array( 'type' => 'terms' === $view ? 'terms' : 'logs' ) ) );
$base       = admin_url( 'admin.php?page=asl-all-logs' );
?>
<div class="wrap asl-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'All Search Logs', 'advanced-search-logger' ); ?></h1>
	<a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action">
		<span class="dashicons dashicons-download"></span>
		<?php echo esc_html( array_filter( $filters ) ? __( 'Export filtered results (CSV)', 'advanced-search-logger' ) : __( 'Export all as CSV', 'advanced-search-logger' ) ); ?>
	</a>
	<hr class="wp-header-end">

	<?php if ( '' !== $filters['term'] ) : ?>
		<div class="asl-term-banner">
			<?php
			/* translators: %s: search term */
			printf( esc_html__( 'Showing searches for "%s".', 'advanced-search-logger' ), '<strong dir="auto">' . esc_html( $filters['term'] ) . '</strong>' );
			?>
			<a class="button button-small" href="<?php echo esc_url( ASL_Admin::new_rule_url( $filters['term'] ) ); ?>"><?php esc_html_e( 'Add rule', 'advanced-search-logger' ); ?></a>
			<a class="button button-small" href="<?php echo esc_url( ASL_Admin::front_search_url( $filters['term'] ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Try on site', 'advanced-search-logger' ); ?></a>
			<a href="<?php echo esc_url( $base . ( 'terms' === $view ? '&view=terms' : '' ) ); ?>"><?php esc_html_e( 'Show all terms', 'advanced-search-logger' ); ?></a>
		</div>
	<?php endif; ?>

	<nav class="nav-tab-wrapper asl-tabs">
		<a href="<?php echo esc_url( add_query_arg( array_map( 'rawurlencode', $keep ), $base ) ); ?>" class="nav-tab <?php echo 'logs' === $view ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Every search', 'advanced-search-logger' ); ?></a>
		<a href="<?php echo esc_url( add_query_arg( array_map( 'rawurlencode', array_merge( $keep, array( 'view' => 'terms' ) ) ), $base ) ); ?>" class="nav-tab <?php echo 'terms' === $view ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Grouped by term', 'advanced-search-logger' ); ?></a>
	</nav>

	<form method="get" class="asl-list-form">
		<input type="hidden" name="page" value="asl-all-logs" />
		<?php if ( 'terms' === $view ) : ?>
			<input type="hidden" name="view" value="terms" />
		<?php endif; ?>
		<?php
		$table->search_box( __( 'Search Terms', 'advanced-search-logger' ), 'asl-search-input' );
		$table->display();
		?>
	</form>

	<div class="asl-danger-zone">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asl-confirm-form" data-confirm="<?php esc_attr_e( 'Are you sure you want to delete ALL search logs? This cannot be undone.', 'advanced-search-logger' ); ?>">
			<input type="hidden" name="action" value="asl_clear_logs">
			<?php wp_nonce_field( 'asl_clear_logs' ); ?>
			<button type="submit" class="button button-link-delete">
				<span class="dashicons dashicons-trash"></span>
				<?php esc_html_e( 'Clear All Logs', 'advanced-search-logger' ); ?>
			</button>
			<span class="description">
				<?php
				$days = (int) ASL_Settings::get( 'cleanup_days' );
				echo esc_html(
					$days > 0
						/* translators: %s: days */
						? sprintf( __( 'Logs older than %s days are deleted automatically.', 'advanced-search-logger' ), asl_num( $days ) )
						: __( 'Logs are kept forever.', 'advanced-search-logger' )
				);
				?>
			</span>
		</form>
	</div>
</div>
