<?php
/**
 * Failed searches review view.
 *
 * @package WebiSearch
 * @var string $tab
 * @var array  $data
 * @var array  $counts
 * @var array  $items
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$labels = array(
	'missing'     => array( __( 'Not in your catalog', 'advanced-search-logger' ), __( 'Even with automatic fixes nothing matches. This is the list of products people want that you do not sell (or that are named differently).', 'advanced-search-logger' ) ),
	'approximate' => array( __( 'Close matches', 'advanced-search-logger' ), __( 'The exact product is not found, so visitors now see related products (found with fewer words). Check them: add a precise rule, or mark as "we don\'t have it".', 'advanced-search-logger' ) ),
	'fixed'       => array( __( 'Fixed automatically', 'advanced-search-logger' ), __( 'Typos, missing آ, glued numbers or pasted text. Visitors now get the right products automatically.', 'advanced-search-logger' ) ),
	'found'       => array( __( 'Found now', 'advanced-search-logger' ), __( 'These find products today without any fix (a product was added, a rule exists, or Arabic/Persian letters and digits are matched).', 'advanced-search-logger' ) ),
	'ignored'     => array( __( 'Ignored', 'advanced-search-logger' ), __( 'Junk or irrelevant searches you chose to hide.', 'advanced-search-logger' ) ),
);
$base = admin_url( 'admin.php?page=asl-review' );
?>
<div class="wrap asl-wrap asl-review">
	<div class="asl-head">
		<h1><?php esc_html_e( 'Failed searches review', 'advanced-search-logger' ); ?></h1>
		<div>
			<button type="button" class="button button-primary" id="asl-review-run"><?php esc_html_e( 'Re-check all with current products', 'advanced-search-logger' ); ?></button>
			<a class="button" href="<?php echo esc_url( ASL_Admin::action_url( 'asl_export', array( 'type' => 'review', 'status' => $tab ) ) ); ?>"><?php esc_html_e( 'Export this list (CSV)', 'advanced-search-logger' ); ?></a>
		</div>
	</div>
	<p class="asl-sub">
		<?php esc_html_e( 'Every search that ever found nothing is tested again against today\'s products, rules and automatic fixes. This runs by itself every night; use the button after adding products.', 'advanced-search-logger' ); ?>
		<?php if ( $data['updated'] ) : ?>
			<?php
			/* translators: %s: date */
			printf( ' ' . esc_html__( 'Last check: %s', 'advanced-search-logger' ), esc_html( asl_format_date( $data['updated'] ) ) );
			?>
		<?php endif; ?>
	</p>
	<div id="asl-review-progress" class="asl-progress" hidden><div class="asl-progress__bar"></div><span class="asl-progress__text"></span></div>
	<hr class="wp-header-end">

	<div class="asl-kpis asl-review-tiles">
		<?php foreach ( array( 'missing', 'approximate', 'fixed', 'found' ) as $s ) : ?>
			<a class="asl-kpi asl-kpi--<?php echo esc_attr( $s ); ?> <?php echo $tab === $s ? 'is-current' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', $s, $base ) ); ?>">
				<span class="asl-kpi__label"><?php echo esc_html( $labels[ $s ][0] ); ?></span>
				<strong class="asl-kpi__value"><?php echo esc_html( asl_num( $counts[ $s ]['terms'] ) ); ?></strong>
				<span class="asl-delta">
				<?php
				/* translators: %s: number of searches */
				echo esc_html( sprintf( __( '%s searches', 'advanced-search-logger' ), asl_num( $counts[ $s ]['searches'] ) ) );
				?>
				</span>
			</a>
		<?php endforeach; ?>
	</div>

	<div class="asl-card">
		<div class="asl-card__head">
			<h2><?php echo esc_html( $labels[ $tab ][0] ); ?></h2>
			<?php if ( $counts['ignored']['terms'] ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'ignored', $base ) ); ?>">
					<?php
					/* translators: %s: number */
					echo esc_html( sprintf( __( 'Ignored (%s)', 'advanced-search-logger' ), asl_num( $counts['ignored']['terms'] ) ) );
					?>
				</a>
			<?php endif; ?>
		</div>
		<p class="asl-help"><?php echo esc_html( $labels[ $tab ][1] ); ?></p>

		<?php if ( ! $items ) : ?>
			<p class="asl-empty">
				<?php echo $data['updated'] ? esc_html__( 'Nothing in this group.', 'advanced-search-logger' ) : esc_html__( 'Not checked yet. Click "Re-check all with current products".', 'advanced-search-logger' ); ?>
			</p>
		<?php else : ?>
		<table class="asl-table">
			<thead><tr>
				<th><?php esc_html_e( 'What visitors searched', 'advanced-search-logger' ); ?></th>
				<th class="num"><?php esc_html_e( 'Times', 'advanced-search-logger' ); ?></th>
				<?php if ( in_array( $tab, array( 'approximate', 'fixed', 'found' ), true ) ) : ?>
					<th><?php esc_html_e( 'What they see now', 'advanced-search-logger' ); ?></th>
				<?php endif; ?>
				<th><?php esc_html_e( 'Last searched', 'advanced-search-logger' ); ?></th>
				<th></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $items as $norm => $it ) : ?>
				<tr>
					<td><a href="<?php echo esc_url( ASL_Admin::logs_url( $norm ) ); ?>" dir="auto"><?php echo esc_html( $it['term'] ); ?></a></td>
					<td class="num"><?php echo esc_html( asl_num( $it['searches'] ) ); ?></td>
					<?php if ( in_array( $tab, array( 'approximate', 'fixed', 'found' ), true ) ) : ?>
						<td>
							<?php if ( $it['to'] ) : ?>
								<a href="<?php echo esc_url( 'redirect' === $it['kind'] ? $it['to'] : ASL_Admin::front_search_url( $it['to'] ) ); ?>" target="_blank" rel="noopener" dir="auto"><?php echo esc_html( urldecode( $it['to'] ) ); ?></a>
								<?php if ( $it['found'] > 0 ) : ?>
									<small class="asl-muted">
									<?php
									/* translators: %s: number of results */
									echo esc_html( sprintf( _n( '%s result', '%s results', $it['found'], 'advanced-search-logger' ), asl_num( $it['found'] ) ) );
									?>
									</small>
								<?php endif; ?>
							<?php endif; ?>
						</td>
					<?php endif; ?>
					<td><small><?php echo esc_html( asl_format_date( $it['last'], false ) ); ?></small></td>
					<td class="asl-actions">
						<?php if ( in_array( $tab, array( 'missing', 'approximate' ), true ) ) : ?>
							<a class="button button-small" href="<?php echo esc_url( ASL_Admin::new_rule_url( $norm, 'approximate' === $tab ? $it['to'] : '' ) ); ?>"><?php esc_html_e( 'Add rule', 'advanced-search-logger' ); ?></a>
						<?php endif; ?>
						<?php if ( 'approximate' === $tab ) : ?>
							<a class="button button-small" href="<?php echo esc_url( ASL_Admin::action_url( 'asl_review_decide', array( 'term' => $norm, 'decision' => 'missing' ) ) ); ?>"><?php esc_html_e( 'We don\'t have it', 'advanced-search-logger' ); ?></a>
						<?php endif; ?>
						<?php if ( 'ignored' === $tab || ( 'missing' === $tab && 'missing' !== $it['status'] ) ) : ?>
							<a class="button button-small" href="<?php echo esc_url( ASL_Admin::action_url( 'asl_review_decide', array( 'term' => $norm, 'decision' => '' ) ) ); ?>"><?php esc_html_e( 'Undo', 'advanced-search-logger' ); ?></a>
						<?php elseif ( in_array( $tab, array( 'missing', 'approximate' ), true ) ) : ?>
							<a class="button button-small button-link-delete" href="<?php echo esc_url( ASL_Admin::action_url( 'asl_review_decide', array( 'term' => $norm, 'decision' => 'ignore' ) ) ); ?>" title="<?php esc_attr_e( 'Junk or irrelevant: hide it.', 'advanced-search-logger' ); ?>"><?php esc_html_e( 'Ignore', 'advanced-search-logger' ); ?></a>
						<?php endif; ?>
						<a class="asl-icon-link" href="<?php echo esc_url( ASL_Admin::front_search_url( $it['term'] ) ); ?>" target="_blank" rel="noopener" title="<?php esc_attr_e( 'Try on site', 'advanced-search-logger' ); ?>"><span class="dashicons dashicons-external"></span></a>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
	</div>
</div>
