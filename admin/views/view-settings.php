<?php
/**
 * Settings view.
 *
 * @package WebiSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap asl-wrap asl-settings">
	<h1><?php esc_html_e( 'Search Logger Settings', 'advanced-search-logger' ); ?></h1>
	<?php settings_errors(); ?>

	<form method="post" action="options.php">
		<?php
		settings_fields( 'asl_settings_group' );
		do_settings_sections( 'asl-settings-page' );
		submit_button();
		?>
	</form>

	<div class="asl-card">
		<h2><?php esc_html_e( 'Tools', 'advanced-search-logger' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asl-inline-form">
			<input type="hidden" name="action" value="asl_test_email">
			<?php wp_nonce_field( 'asl_test_email' ); ?>
			<?php submit_button( __( 'Send the report now (test)', 'advanced-search-logger' ), 'secondary', 'submit', false ); ?>
			<span class="description">
				<?php
				$next = wp_next_scheduled( 'asl_weekly_report' );
				if ( $next && ASL_Settings::on( 'email_enable' ) ) {
					/* translators: %s: date */
					printf( esc_html__( 'Next scheduled report: %s', 'advanced-search-logger' ), esc_html( asl_format_date( asl_date( 'Y-m-d H:i:s', $next ) ) ) );
				}
				?>
			</span>
		</form>

		<h3><?php esc_html_e( 'For developers', 'advanced-search-logger' ); ?></h3>
		<ul class="asl-help">
			<li><code>[webisearch_popular limit="8" days="30" style="tags|list" title="..."]</code> — <?php esc_html_e( 'popular searches with results, for your search page or header.', 'advanced-search-logger' ); ?></li>
			<li><code>webisearch_log_search( $term, $result_count, array( 'source' => 'api' ) )</code> — <?php esc_html_e( 'log searches from a custom engine that does not use WP_Query.', 'advanced-search-logger' ); ?></li>
			<li><code>asl_should_log</code>, <code>asl_track_query</code>, <code>asl_result_count</code>, <code>asl_live_search_selectors</code>, <code>asl_capability</code> — <?php esc_html_e( 'filters', 'advanced-search-logger' ); ?>; <code>asl_search_logged</code>, <code>asl_result_clicked</code> — <?php esc_html_e( 'actions', 'advanced-search-logger' ); ?>.</li>
			<li><code>wp webisearch stats|export|cleanup|report</code> — <?php esc_html_e( 'WP-CLI commands.', 'advanced-search-logger' ); ?></li>
		</ul>
	</div>
</div>
