<?php
/**
 * Dashboard view.
 *
 * @package WebiSearch
 * @var array $stats
 * @var int   $days
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$k       = $stats['kpis'];
$prev    = $stats['prev'];
$woo     = class_exists( 'WooCommerce' ) && ASL_Settings::on( 'track_conversions' );
$clicks  = ASL_Settings::on( 'track_clicks' );
$periods = array(
	7   => __( '7 days', 'advanced-search-logger' ),
	30  => __( '30 days', 'advanced-search-logger' ),
	90  => __( '90 days', 'advanced-search-logger' ),
	365 => __( '12 months', 'advanced-search-logger' ),
);

/**
 * Change indicator.
 *
 * @param float  $cur    Current.
 * @param float  $old    Previous.
 * @param bool   $points Show difference in percentage points instead of % change.
 * @param bool   $invert Lower is better.
 * @return string
 */
$asl_delta = function ( $cur, $old, $points = false, $invert = false ) {
	if ( ! $old && ! $cur ) {
		return '';
	}
	if ( $points ) {
		$diff  = round( $cur - $old, 1 );
		$label = ( $diff > 0 ? '+' : '' ) . asl_num( $diff, 1 ) . ' ' . __( 'pts', 'advanced-search-logger' );
	} else {
		if ( ! $old ) {
			return '<span class="asl-delta asl-delta--new">' . esc_html__( 'new', 'advanced-search-logger' ) . '</span>';
		}
		$diff  = round( 100 * ( $cur - $old ) / $old );
		$label = ( $diff > 0 ? '+' : '' ) . asl_num( $diff ) . '%';
	}
	if ( 0.0 === (float) $diff ) {
		return '<span class="asl-delta">' . esc_html( $label ) . '</span>';
	}
	$good = $invert ? $diff < 0 : $diff > 0;
	return '<span class="asl-delta asl-delta--' . ( $good ? 'up' : 'down' ) . '" title="' . esc_attr__( 'Compared with the previous period', 'advanced-search-logger' ) . '">' . ( $diff > 0 ? '&#9650; ' : '&#9660; ' ) . esc_html( $label ) . '</span>';
};

// Automatic insights.
$insights = array();
if ( $k['total'] > 0 ) {
	if ( $k['zero_rate'] >= 10 && ! empty( $stats['zero'] ) ) {
		$insights[] = array(
			'bad',
			/* translators: 1: percent, 2: search term */
			sprintf( __( '%1$s%% of searches find nothing. The most common one is "%2$s" — add a synonym or the missing product.', 'advanced-search-logger' ), asl_num( $k['zero_rate'], 1 ), $stats['zero'][0]['term'] ),
		);
	}
	$with_suggestion = array_filter(
		$stats['zero'],
		function ( $z ) {
			return '' !== $z['suggestion'];
		}
	);
	if ( $with_suggestion ) {
		$first      = reset( $with_suggestion );
		$insights[] = array(
			'tip',
			/* translators: 1: number of terms, 2: failed term, 3: suggestion */
			sprintf( _n( '%1$s failed search looks like a typo of a successful one (e.g. "%2$s" → "%3$s"). One click creates the synonym.', '%1$s failed searches look like typos of successful ones (e.g. "%2$s" → "%3$s"). One click creates the synonym.', count( $with_suggestion ), 'advanced-search-logger' ), asl_num( count( $with_suggestion ) ), $first['term'], $first['suggestion'] ),
		);
	}
	if ( $clicks ) {
		foreach ( $stats['top'] as $t ) {
			$found = (float) $t['avg_results'] > 0;
			$ctr   = $t['count'] > 0 ? 100 * $t['clicked'] / $t['count'] : 0;
			if ( $found && $t['count'] >= 10 && $ctr < 10 && $k['clicked'] > 0 ) {
				$insights[] = array(
					'warn',
					/* translators: 1: term, 2: searches, 3: CTR */
					sprintf( __( '"%1$s" was searched %2$s times and returns results, but only %3$s%% of searchers clicked one. Check that the best product appears first and that titles/images match what people expect.', 'advanced-search-logger' ), $t['term'], asl_num( $t['count'] ), asl_num( $ctr, 1 ) ),
				);
				break;
			}
		}
	}
	if ( ! empty( $stats['trending'] ) ) {
		$tr         = $stats['trending'][0];
		$insights[] = array(
			'up',
			null === $tr['growth']
				/* translators: 1: term, 2: count */
				? sprintf( __( '"%1$s" is a new trend: %2$s searches this period and none before.', 'advanced-search-logger' ), $tr['term'], asl_num( $tr['count'] ) )
				/* translators: 1: term, 2: growth percent */
				: sprintf( __( 'Interest in "%1$s" grew %2$s%% compared with the previous period. Consider featuring it on the home page.', 'advanced-search-logger' ), $tr['term'], asl_num( $tr['growth'] ) ),
		);
	}
	if ( $k['avg_position'] >= 4 ) {
		$insights[] = array(
			'warn',
			/* translators: %s: average position */
			sprintf( __( 'On average visitors click result #%s. The most relevant results are not at the top; synonyms and better product titles help.', 'advanced-search-logger' ), asl_num( $k['avg_position'], 1 ) ),
		);
	}
	if ( $clicks && 0 === (int) $k['clicked'] && $k['trackable'] >= 20 ) {
		$insights[] = array(
			'tip',
			__( 'No result clicks have been recorded yet. If your theme uses a custom live search, add its dropdown CSS class under Settings → Clicks & conversions.', 'advanced-search-logger' ),
		);
	}
}
?>
<div class="wrap asl-wrap" id="asl-dashboard-wrap">
	<div class="asl-head">
		<h1><?php esc_html_e( 'Search Analytics Dashboard', 'advanced-search-logger' ); ?></h1>
		<nav class="asl-periods" aria-label="<?php esc_attr_e( 'Period', 'advanced-search-logger' ); ?>">
			<?php foreach ( $periods as $d => $label ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=asl-dashboard&days=' . $d ) ); ?>" class="<?php echo $d === $days ? 'is-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
	</div>
	<p class="asl-sub">
		<?php
		/* translators: 1: start date, 2: end date, 3: period length such as "30 days" */
		echo esc_html( sprintf( __( 'From %1$s to %2$s, compared with the previous %3$s.', 'advanced-search-logger' ), asl_format_date( $stats['period']['start'] . ' 00:00:00', false ), asl_format_date( $stats['period']['end'] . ' 00:00:00', false ), $periods[ $days ] ) );
		?>
	</p>
	<hr class="wp-header-end">

	<div class="asl-kpis">
		<div class="asl-kpi">
			<span class="asl-kpi__label"><?php esc_html_e( 'Searches', 'advanced-search-logger' ); ?></span>
			<strong class="asl-kpi__value"><?php echo esc_html( asl_num( $k['total'] ) ); ?></strong>
			<?php echo $asl_delta( $k['total'], $prev['total'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
		<div class="asl-kpi">
			<span class="asl-kpi__label"><?php esc_html_e( 'Unique searchers', 'advanced-search-logger' ); ?></span>
			<strong class="asl-kpi__value"><?php echo esc_html( asl_num( $k['visitors'] ) ); ?></strong>
			<?php echo $asl_delta( $k['visitors'], $prev['visitors'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
		<div class="asl-kpi <?php echo $k['zero_rate'] >= 15 ? 'is-bad' : ''; ?>">
			<span class="asl-kpi__label"><?php esc_html_e( 'No-result rate', 'advanced-search-logger' ); ?></span>
			<strong class="asl-kpi__value"><?php echo esc_html( asl_num( $k['zero_rate'], 1 ) ); ?>%</strong>
			<?php echo $asl_delta( $k['zero_rate'], $prev['zero_rate'], true, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
		<?php if ( ASL_Settings::on( 'smart_fallback' ) ) : ?>
		<div class="asl-kpi is-good">
			<span class="asl-kpi__label"><?php esc_html_e( 'Rescued automatically', 'advanced-search-logger' ); ?></span>
			<strong class="asl-kpi__value"><?php echo esc_html( asl_num( $k['rescued'] ) ); ?></strong>
			<a class="asl-delta" href="<?php echo esc_url( ASL_Admin::logs_url( '', array( 'results' => 'fixed' ) ) ); ?>"><?php esc_html_e( 'searches that would have found nothing', 'advanced-search-logger' ); ?></a>
		</div>
		<?php endif; ?>
		<?php if ( $clicks ) : ?>
		<div class="asl-kpi">
			<span class="asl-kpi__label"><?php esc_html_e( 'Click-through rate', 'advanced-search-logger' ); ?></span>
			<strong class="asl-kpi__value"><?php echo esc_html( asl_num( $k['ctr'], 1 ) ); ?>%</strong>
			<?php echo $asl_delta( $k['ctr'], $prev['ctr'], true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
		<div class="asl-kpi">
			<span class="asl-kpi__label"><?php esc_html_e( 'Average click position', 'advanced-search-logger' ); ?></span>
			<strong class="asl-kpi__value"><?php echo $k['avg_position'] ? esc_html( asl_num( $k['avg_position'], 1 ) ) : '&mdash;'; ?></strong>
			<span class="asl-delta asl-muted"><?php esc_html_e( 'lower is better', 'advanced-search-logger' ); ?></span>
		</div>
		<?php endif; ?>
		<?php if ( $woo ) : ?>
		<div class="asl-kpi is-money">
			<span class="asl-kpi__label"><?php esc_html_e( 'Revenue from search', 'advanced-search-logger' ); ?></span>
			<strong class="asl-kpi__value" title="<?php echo esc_attr( wp_strip_all_tags( html_entity_decode( wc_price( $k['revenue'] ) ) ) ); ?>"><?php $asl_money = asl_compact_money_parts( $k['revenue'] ); echo esc_html( $asl_money[0] ); ?> <small class="asl-kpi__unit"><?php echo esc_html( $asl_money[1] ); ?></small></strong>
			<span class="asl-delta asl-muted">
			<?php
			/* translators: 1: carts, 2: orders */
			echo esc_html( sprintf( __( '%1$s carts · %2$s orders', 'advanced-search-logger' ), asl_num( $k['carts'] ), asl_num( $k['orders'] ) ) );
			?>
			</span>
		</div>
		<?php else : ?>
		<div class="asl-kpi">
			<span class="asl-kpi__label"><?php esc_html_e( 'Live search share', 'advanced-search-logger' ); ?></span>
			<strong class="asl-kpi__value"><?php echo esc_html( asl_num( $k['live_rate'], 1 ) ); ?>%</strong>
		</div>
		<?php endif; ?>
	</div>

	<?php if ( $insights ) : ?>
	<div class="asl-card asl-insights">
		<h2><?php esc_html_e( 'Insights', 'advanced-search-logger' ); ?></h2>
		<ul>
			<?php foreach ( $insights as $in ) : ?>
				<li class="asl-insight asl-insight--<?php echo esc_attr( $in[0] ); ?>"><?php echo esc_html( $in[1] ); ?></li>
			<?php endforeach; ?>
		</ul>
	</div>
	<?php endif; ?>

	<div class="asl-grid">
		<div class="asl-card asl-span-2">
			<h2><?php esc_html_e( 'Total Searches Per Day', 'advanced-search-logger' ); ?></h2>
			<div id="asl-chart-daily" class="asl-chart"></div>
		</div>

		<div class="asl-card">
			<div class="asl-card__head">
				<h2><?php esc_html_e( 'Searches with no results', 'advanced-search-logger' ); ?></h2>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=asl-review' ) ); ?>"><?php esc_html_e( 'Full review', 'advanced-search-logger' ); ?></a>
			</div>
			<p class="asl-help"><?php esc_html_e( 'Each one is a visitor who wanted something and found nothing. Fix them with a synonym, a redirect or by adding the product.', 'advanced-search-logger' ); ?></p>
			<?php if ( empty( $stats['zero'] ) ) : ?>
				<p class="asl-empty"><?php esc_html_e( 'No failed searches in this period.', 'advanced-search-logger' ); ?></p>
			<?php else : ?>
			<table class="asl-table">
				<thead><tr><th><?php esc_html_e( 'Search Term', 'advanced-search-logger' ); ?></th><th class="num"><?php esc_html_e( 'Times', 'advanced-search-logger' ); ?></th><th><?php esc_html_e( 'Fix', 'advanced-search-logger' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $stats['zero'] as $z ) : ?>
					<tr>
						<td>
							<a href="<?php echo esc_url( ASL_Admin::logs_url( $z['term'] ) ); ?>" dir="auto"><?php echo esc_html( $z['term'] ); ?></a>
							<?php if ( '' !== $z['suggestion'] ) : ?>
								<br><small class="asl-muted"><?php esc_html_e( 'Did you mean:', 'advanced-search-logger' ); ?> <span dir="auto"><?php echo esc_html( $z['suggestion'] ); ?></span></small>
							<?php endif; ?>
						</td>
						<td class="num"><span class="asl-badge asl-badge--bad"><?php echo esc_html( asl_num( $z['count'] ) ); ?></span></td>
						<td class="asl-actions">
							<?php if ( '' !== $z['suggestion'] ) : ?>
								<a class="button button-small button-primary" href="<?php echo esc_url( ASL_Admin::new_rule_url( $z['term'], $z['suggestion'] ) ); ?>"><?php esc_html_e( 'Use suggestion', 'advanced-search-logger' ); ?></a>
							<?php else : ?>
								<a class="button button-small" href="<?php echo esc_url( ASL_Admin::new_rule_url( $z['term'] ) ); ?>"><?php esc_html_e( 'Add rule', 'advanced-search-logger' ); ?></a>
							<?php endif; ?>
							<a class="button button-small" href="<?php echo esc_url( ASL_Admin::action_url( 'asl_resolve', array( 'term' => $z['term'] ) ) ); ?>" title="<?php esc_attr_e( 'Hide it until it fails again (e.g. after you added the product).', 'advanced-search-logger' ); ?>"><?php esc_html_e( 'Resolved', 'advanced-search-logger' ); ?></a>
							<a class="asl-icon-link" href="<?php echo esc_url( ASL_Admin::front_search_url( $z['term'] ) ); ?>" target="_blank" rel="noopener" title="<?php esc_attr_e( 'Try on site', 'advanced-search-logger' ); ?>"><span class="dashicons dashicons-external"></span></a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
		</div>

		<div class="asl-card">
			<div class="asl-card__head">
				<h2><?php esc_html_e( 'Top 10 Search Terms', 'advanced-search-logger' ); ?></h2>
				<a href="<?php echo esc_url( ASL_Admin::logs_url( '', array( 'view' => 'terms' ) ) ); ?>"><?php esc_html_e( 'See all', 'advanced-search-logger' ); ?></a>
			</div>
			<div id="asl-chart-top" class="asl-chart"></div>
		</div>

		<div class="asl-card">
			<h2><?php esc_html_e( 'Trending searches', 'advanced-search-logger' ); ?></h2>
			<?php if ( empty( $stats['trending'] ) ) : ?>
				<p class="asl-empty"><?php esc_html_e( 'Nothing unusual compared with the previous period.', 'advanced-search-logger' ); ?></p>
			<?php else : ?>
			<table class="asl-table">
				<thead><tr><th><?php esc_html_e( 'Search Term', 'advanced-search-logger' ); ?></th><th class="num"><?php esc_html_e( 'Now', 'advanced-search-logger' ); ?></th><th class="num"><?php esc_html_e( 'Before', 'advanced-search-logger' ); ?></th><th class="num"><?php esc_html_e( 'Change', 'advanced-search-logger' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $stats['trending'] as $tr ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( ASL_Admin::logs_url( $tr['term'] ) ); ?>" dir="auto"><?php echo esc_html( $tr['term'] ); ?></a></td>
						<td class="num"><?php echo esc_html( asl_num( $tr['count'] ) ); ?></td>
						<td class="num"><?php echo esc_html( asl_num( $tr['prev'] ) ); ?></td>
						<td class="num"><?php echo null === $tr['growth'] ? '<span class="asl-delta asl-delta--new">' . esc_html__( 'new', 'advanced-search-logger' ) . '</span>' : '<span class="asl-delta asl-delta--up">&#9650; ' . esc_html( asl_num( $tr['growth'] ) ) . '%</span>'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
		</div>

		<div class="asl-card">
			<h2><?php esc_html_e( 'When do people search?', 'advanced-search-logger' ); ?></h2>
			<div id="asl-chart-hours" class="asl-chart"></div>
		</div>

		<div class="asl-card">
			<h2><?php esc_html_e( 'Top searches in detail', 'advanced-search-logger' ); ?></h2>
			<?php if ( empty( $stats['top'] ) ) : ?>
				<p class="asl-empty"><?php esc_html_e( 'No data for this period yet.', 'advanced-search-logger' ); ?></p>
			<?php else : ?>
			<table class="asl-table">
				<thead><tr>
					<th><?php esc_html_e( 'Search Term', 'advanced-search-logger' ); ?></th>
					<th class="num"><?php esc_html_e( 'Searches', 'advanced-search-logger' ); ?></th>
					<th class="num"><?php esc_html_e( 'Avg. results', 'advanced-search-logger' ); ?></th>
					<?php if ( $clicks ) : ?>
						<th class="num"><?php esc_html_e( 'CTR', 'advanced-search-logger' ); ?></th>
					<?php endif; ?>
					<?php if ( $woo ) : ?>
						<th class="num"><?php esc_html_e( 'Revenue', 'advanced-search-logger' ); ?></th>
					<?php endif; ?>
				</tr></thead>
				<tbody>
				<?php foreach ( $stats['top'] as $t ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( ASL_Admin::logs_url( $t['term'] ) ); ?>" dir="auto"><?php echo esc_html( $t['term'] ); ?></a></td>
						<td class="num"><?php echo esc_html( asl_num( $t['count'] ) ); ?></td>
						<td class="num"><?php echo (int) $t['zero'] === (int) $t['count'] ? '<span class="asl-badge asl-badge--bad">0</span>' : esc_html( asl_num( $t['avg_results'] ) ); ?></td>
						<?php if ( $clicks ) : ?>
							<td class="num"><?php echo esc_html( asl_num( $t['count'] ? 100 * $t['clicked'] / $t['count'] : 0, 1 ) ); ?>%</td>
						<?php endif; ?>
						<?php if ( $woo ) : ?>
							<td class="num"><?php echo (float) $t['revenue'] > 0 ? wp_kses_post( wc_price( $t['revenue'] ) ) : '&mdash;'; ?></td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
		</div>

		<div class="asl-card">
			<h2><?php esc_html_e( 'Most clicked results', 'advanced-search-logger' ); ?></h2>
			<?php if ( ! $clicks ) : ?>
				<p class="asl-empty"><?php esc_html_e( 'Click tracking is turned off in the settings.', 'advanced-search-logger' ); ?></p>
			<?php elseif ( empty( $stats['clicked'] ) ) : ?>
				<p class="asl-empty"><?php esc_html_e( 'No clicks recorded in this period yet.', 'advanced-search-logger' ); ?></p>
			<?php else : ?>
			<table class="asl-table">
				<thead><tr><th><?php esc_html_e( 'Page', 'advanced-search-logger' ); ?></th><th class="num"><?php esc_html_e( 'Clicks', 'advanced-search-logger' ); ?></th><th class="num"><?php esc_html_e( 'Avg. position', 'advanced-search-logger' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $stats['clicked'] as $c ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( $c['url'] ); ?>" target="_blank" rel="noopener" dir="auto"><?php echo esc_html( $c['title'] ); ?></a></td>
						<td class="num"><?php echo esc_html( asl_num( $c['count'] ) ); ?></td>
						<td class="num"><?php echo (float) $c['avg_position'] ? esc_html( asl_num( $c['avg_position'], 1 ) ) : '&mdash;'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
		</div>

		<div class="asl-card">
			<h2><?php esc_html_e( 'Where searches happen', 'advanced-search-logger' ); ?></h2>
			<div id="asl-chart-sources" class="asl-chart"></div>
		</div>
	</div>

	<p class="asl-foot">
		<?php
		/* translators: %s: shortcode */
		printf( esc_html__( 'Tip: show your visitors what others search for with the %s shortcode.', 'advanced-search-logger' ), '<code>[webisearch_popular]</code>' );
		?>
	</p>
</div>
