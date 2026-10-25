<?php
/**
 * Product requests view.
 *
 * @package WebiSearch
 * @var string $status
 * @var string $q
 * @var string $term
 * @var int    $paged
 * @var int    $per_page
 * @var int    $total
 * @var array  $rows
 * @var array  $counts
 * @var array  $top
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$statuses = ASL_Requests::statuses();
$base     = admin_url( 'admin.php?page=asl-requests' );
$pages    = max( 1, (int) ceil( $total / $per_page ) );
$tabs     = array(
	'open'      => array( __( 'Open', 'advanced-search-logger' ), $counts['new'] + $counts['contacted'] ),
	'new'       => array( $statuses['new'], $counts['new'] ),
	'contacted' => array( $statuses['contacted'], $counts['contacted'] ),
	'done'      => array( $statuses['done'], $counts['done'] ),
	'canceled'  => array( $statuses['canceled'], $counts['canceled'] ),
	'all'       => array( __( 'All', 'advanced-search-logger' ), $counts['all'] ),
);
$users = array();
?>
<div class="wrap asl-wrap asl-requests">
	<div class="asl-head">
		<h1><?php esc_html_e( 'Product requests', 'advanced-search-logger' ); ?></h1>
		<div>
			<a class="button" href="<?php echo esc_url( ASL_Admin::action_url( 'asl_export', array( 'type' => 'requests', 'status' => $status ) ) ); ?>"><?php esc_html_e( 'Export this list (CSV)', 'advanced-search-logger' ); ?></a>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=asl-settings#asl_request_enabled' ) ); ?>"><?php esc_html_e( 'Box settings', 'advanced-search-logger' ); ?></a>
		</div>
	</div>
	<p class="asl-sub"><?php esc_html_e( 'Visitors who searched for a product you do not have and left their phone number. Call them when the product is available, then mark the request as done.', 'advanced-search-logger' ); ?></p>
	<hr class="wp-header-end">

	<?php if ( ! ASL_Settings::on( 'request_enabled' ) ) : ?>
		<div class="notice notice-warning inline"><p>
			<?php esc_html_e( 'The request box is turned off, so visitors cannot leave new requests.', 'advanced-search-logger' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=asl-settings#asl_request_enabled' ) ); ?>"><?php esc_html_e( 'Turn it on', 'advanced-search-logger' ); ?></a>
		</p></div>
	<?php endif; ?>

	<div class="asl-kpis">
		<div class="asl-kpi <?php echo $counts['new'] ? 'is-bad' : ''; ?>">
			<span class="asl-kpi__label"><?php esc_html_e( 'Waiting for a call', 'advanced-search-logger' ); ?></span>
			<strong class="asl-kpi__value"><?php echo esc_html( asl_num( $counts['new'] ) ); ?></strong>
		</div>
		<div class="asl-kpi">
			<span class="asl-kpi__label"><?php echo esc_html( $statuses['contacted'] ); ?></span>
			<strong class="asl-kpi__value"><?php echo esc_html( asl_num( $counts['contacted'] ) ); ?></strong>
		</div>
		<div class="asl-kpi is-good">
			<span class="asl-kpi__label"><?php echo esc_html( $statuses['done'] ); ?></span>
			<strong class="asl-kpi__value"><?php echo esc_html( asl_num( $counts['done'] ) ); ?></strong>
		</div>
		<div class="asl-kpi">
			<span class="asl-kpi__label"><?php esc_html_e( 'All requests', 'advanced-search-logger' ); ?></span>
			<strong class="asl-kpi__value"><?php echo esc_html( asl_num( $counts['all'] ) ); ?></strong>
		</div>
	</div>

	<div class="asl-requests-layout">
		<div class="asl-card">
			<ul class="subsubsub asl-req-tabs">
				<?php
				$links = array();
				foreach ( $tabs as $key => $tab ) {
					$links[] = sprintf(
						'<li><a href="%s" class="%s">%s <span class="count">(%s)</span></a>',
						esc_url( add_query_arg( 'status', $key, $base ) ),
						$status === $key ? 'current' : '',
						esc_html( $tab[0] ),
						esc_html( asl_num( $tab[1] ) )
					);
				}
				echo implode( ' |</li>', $links ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				?>
			</ul>
			<form method="get" class="asl-req-search">
				<input type="hidden" name="page" value="asl-requests">
				<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
				<input type="search" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="<?php esc_attr_e( 'Phone, name or product', 'advanced-search-logger' ); ?>">
				<?php submit_button( __( 'Search', 'advanced-search-logger' ), 'secondary', '', false ); ?>
			</form>
			<div class="clear"></div>

			<?php if ( '' !== $term ) : ?>
				<div class="asl-term-banner">
					<span>
					<?php
					/* translators: %s: product searched */
					printf( esc_html__( 'Requests for: %s', 'advanced-search-logger' ), '<strong dir="auto">' . esc_html( $term ) . '</strong>' );
					?>
					</span>
					<a class="button button-small" href="<?php echo esc_url( ASL_Admin::action_url( 'asl_request_term', array( 'term' => $term, 'status' => 'done' ) ) ); ?>"><?php esc_html_e( 'Mark all open as done', 'advanced-search-logger' ); ?></a>
					<a href="<?php echo esc_url( remove_query_arg( 'term' ) ); ?>"><?php esc_html_e( 'Show all products', 'advanced-search-logger' ); ?></a>
				</div>
			<?php endif; ?>

			<?php if ( ! $rows ) : ?>
				<p class="asl-empty"><?php echo $counts['all'] ? esc_html__( 'Nothing in this group.', 'advanced-search-logger' ) : esc_html__( 'No requests yet. They appear here as soon as a visitor leaves a number on an empty search page.', 'advanced-search-logger' ); ?></p>
			<?php else : ?>
			<table class="asl-table asl-req-table">
				<thead><tr>
					<th><?php esc_html_e( 'Product searched', 'advanced-search-logger' ); ?></th>
					<th><?php esc_html_e( 'Customer', 'advanced-search-logger' ); ?></th>
					<th><?php esc_html_e( 'Date', 'advanced-search-logger' ); ?></th>
					<th><?php esc_html_e( 'Status & note', 'advanced-search-logger' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $rows as $r ) : ?>
					<?php
					$now  = ASL_Requests::available_now( $r['term_norm'] );
					$uid  = (int) $r['user_id'];
					if ( $uid && ! isset( $users[ $uid ] ) ) {
						$u              = get_userdata( $uid );
						$users[ $uid ] = $u ? $u->display_name : '';
					}
					?>
					<tr class="asl-req-row asl-req-row--<?php echo esc_attr( $r['status'] ); ?>">
						<td>
							<a href="<?php echo esc_url( add_query_arg( 'term', rawurlencode( $r['term'] ), $base . '&status=all' ) ); ?>" dir="auto"><strong><?php echo esc_html( '' !== $r['term'] ? $r['term'] : '—' ); ?></strong></a>
							<?php if ( $now ) : ?>
								<br><a class="asl-pill asl-pill--good" href="<?php echo esc_url( ASL_Admin::front_search_url( $r['term'] ) ); ?>" target="_blank" rel="noopener" title="<?php esc_attr_e( 'The last failed-searches check found products for this search. Time to call!', 'advanced-search-logger' ); ?>">&#10003; <?php esc_html_e( 'Found on the site now', 'advanced-search-logger' ); ?></a>
							<?php endif; ?>
						</td>
						<td>
							<a class="asl-phone" href="tel:<?php echo esc_attr( $r['phone'] ); ?>" dir="ltr"><?php echo esc_html( $r['phone'] ); ?></a>
							<?php if ( '' !== $r['name'] ) : ?>
								<br><span dir="auto"><?php echo esc_html( $r['name'] ); ?></span>
							<?php endif; ?>
							<?php if ( $uid && ! empty( $users[ $uid ] ) ) : ?>
								<br><a class="asl-muted" href="<?php echo esc_url( get_edit_user_link( $uid ) ); ?>"><small><?php echo esc_html( $users[ $uid ] ); ?></small></a>
							<?php endif; ?>
						</td>
						<td><small><?php echo esc_html( asl_format_date( $r['created'] ) ); ?></small></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asl-req-edit">
								<input type="hidden" name="action" value="asl_request_update">
								<input type="hidden" name="id" value="<?php echo esc_attr( $r['id'] ); ?>">
								<?php wp_nonce_field( 'asl_request_update' ); ?>
								<select name="status" aria-label="<?php esc_attr_e( 'Status', 'advanced-search-logger' ); ?>">
									<?php foreach ( $statuses as $k => $label ) : ?>
										<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $r['status'], $k ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
								<input type="text" name="note" value="<?php echo esc_attr( $r['note'] ); ?>" placeholder="<?php esc_attr_e( 'Note for the team', 'advanced-search-logger' ); ?>" dir="auto">
								<button type="submit" class="button button-small"><?php esc_html_e( 'Save', 'advanced-search-logger' ); ?></button>
							</form>
						</td>
						<td class="asl-actions">
							<?php if ( ! empty( $r['page_url'] ) ) : ?>
								<a class="asl-icon-link" href="<?php echo esc_url( $r['page_url'] ); ?>" target="_blank" rel="noopener" title="<?php esc_attr_e( 'Page where the request was sent', 'advanced-search-logger' ); ?>"><span class="dashicons dashicons-external"></span></a>
							<?php endif; ?>
							<a class="asl-icon-link asl-danger-link" href="<?php echo esc_url( ASL_Admin::action_url( 'asl_request_delete', array( 'id' => $r['id'] ) ) ); ?>" title="<?php esc_attr_e( 'Delete', 'advanced-search-logger' ); ?>" onclick="return window.confirm(this.dataset.confirm);" data-confirm="<?php esc_attr_e( 'Delete this request?', 'advanced-search-logger' ); ?>"><span class="dashicons dashicons-trash"></span></a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'current'   => $paged,
								'total'     => $pages,
								'prev_text' => '&lsaquo;',
								'next_text' => '&rsaquo;',
							)
						)
					);
					?>
				</div></div>
			<?php endif; ?>
			<?php endif; ?>
		</div>

		<div class="asl-card">
			<h2><?php esc_html_e( 'Most requested products', 'advanced-search-logger' ); ?></h2>
			<p class="asl-help"><?php esc_html_e( 'Open requests only. A strong signal of what to stock next.', 'advanced-search-logger' ); ?></p>
			<?php if ( ! $top ) : ?>
				<p class="asl-empty"><?php esc_html_e( 'Nothing yet.', 'advanced-search-logger' ); ?></p>
			<?php else : ?>
				<table class="asl-table">
					<tbody>
					<?php foreach ( $top as $t ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( add_query_arg( array( 'term' => rawurlencode( $t['term'] ), 'status' => 'open' ), $base ) ); ?>" dir="auto"><?php echo esc_html( $t['term'] ); ?></a></td>
							<td class="num"><span class="asl-badge asl-badge--bad"><?php echo esc_html( asl_num( $t['n'] ) ); ?></span></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</div>
</div>
