<?php
/**
 * Synonyms & redirects view.
 *
 * @package WebiSearch
 * @var ASL_Synonyms_List_Table $table
 * @var array|null              $edit
 * @var array                   $pre
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$rule = $edit ? $edit : array(
	'id'         => 0,
	'term'       => $pre['term'],
	'type'       => 'synonym',
	'match_type' => 'exact',
	'target'     => $pre['target'],
	'status'     => 1,
);
$is_edit = ! empty( $rule['id'] );
?>
<div class="wrap asl-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Synonyms & Redirects', 'advanced-search-logger' ); ?></h1>
	<a class="page-title-action" href="<?php echo esc_url( ASL_Admin::action_url( 'asl_export', array( 'type' => 'rules' ) ) ); ?>"><?php esc_html_e( 'Export rules (CSV)', 'advanced-search-logger' ); ?></a>
	<hr class="wp-header-end">
	<p><?php esc_html_e( 'Manage rules to redirect searches or replace them with synonyms.', 'advanced-search-logger' ); ?></p>

	<div class="asl-rules-layout">
		<div class="asl-card asl-form-wrap" id="asl-rule-form">
			<h2><?php echo esc_html( $is_edit ? __( 'Edit rule', 'advanced-search-logger' ) : __( 'Add New Rule', 'advanced-search-logger' ) ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="asl_save_rule">
				<input type="hidden" name="id" value="<?php echo (int) $rule['id']; ?>">
				<?php wp_nonce_field( 'asl_save_rule' ); ?>

				<div class="form-field">
					<label for="asl_term"><?php esc_html_e( 'When a visitor searches for', 'advanced-search-logger' ); ?></label>
					<?php if ( $is_edit ) : ?>
						<input type="text" name="term" id="asl_term" value="<?php echo esc_attr( $rule['term'] ); ?>" required dir="auto">
					<?php else : ?>
						<textarea name="term" id="asl_term" rows="2" required dir="auto"><?php echo esc_textarea( $rule['term'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'You can enter several spellings at once, separated by commas or new lines (e.g. هودی، هودي، hoodie). Persian/Arabic letters, digits and half-spaces are matched automatically.', 'advanced-search-logger' ); ?></p>
					<?php endif; ?>
				</div>

				<div class="form-field">
					<label for="asl_match"><?php esc_html_e( 'Match', 'advanced-search-logger' ); ?></label>
					<select name="match_type" id="asl_match">
						<option value="exact" <?php selected( $rule['match_type'], 'exact' ); ?>><?php esc_html_e( 'Exact match — the whole search is this term', 'advanced-search-logger' ); ?></option>
						<option value="contains" <?php selected( $rule['match_type'], 'contains' ); ?>><?php esc_html_e( 'Contains the word — e.g. "هودی" also matches "هودی مردانه"', 'advanced-search-logger' ); ?></option>
						<option value="starts" <?php selected( $rule['match_type'], 'starts' ); ?>><?php esc_html_e( 'Starts with the word', 'advanced-search-logger' ); ?></option>
					</select>
				</div>

				<div class="form-field">
					<label for="asl_type"><?php esc_html_e( 'Rule Type', 'advanced-search-logger' ); ?></label>
					<select name="type" id="asl_type">
						<option value="synonym" <?php selected( $rule['type'], 'synonym' ); ?>><?php esc_html_e( 'Synonym (Show results for...)', 'advanced-search-logger' ); ?></option>
						<option value="redirect" <?php selected( $rule['type'], 'redirect' ); ?>><?php esc_html_e( 'Redirect (Send user to URL)', 'advanced-search-logger' ); ?></option>
					</select>
				</div>

				<div class="form-field">
					<label for="asl_target"><?php esc_html_e( 'Target', 'advanced-search-logger' ); ?></label>
					<input type="text" name="target" id="asl_target" value="<?php echo esc_attr( 'redirect' === $rule['type'] ? urldecode( $rule['target'] ) : $rule['target'] ); ?>" required dir="auto">
					<p class="description asl-desc-synonym"><?php esc_html_e( 'Synonym: the term that should be searched instead. With "contains", only the matched word is replaced.', 'advanced-search-logger' ); ?></p>
					<p class="description asl-desc-redirect"><?php esc_html_e( 'Redirect: a full URL or a path like /product-category/pumps/. Persian URLs are fine. Use {query} to pass the search on, e.g. /shop/?s={query}. Live search dropdowns keep showing normal results; the redirect happens when Enter is pressed.', 'advanced-search-logger' ); ?></p>
				</div>

				<div class="form-field">
					<label><input type="checkbox" name="status" value="1" <?php checked( (int) $rule['status'], 1 ); ?>> <?php esc_html_e( 'Active', 'advanced-search-logger' ); ?></label>
				</div>

				<?php submit_button( $is_edit ? __( 'Update rule', 'advanced-search-logger' ) : __( 'Add Rule', 'advanced-search-logger' ), 'primary', 'submit', false ); ?>
				<?php if ( $is_edit ) : ?>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=asl-synonyms' ) ); ?>"><?php esc_html_e( 'Cancel', 'advanced-search-logger' ); ?></a>
				<?php endif; ?>
			</form>
		</div>

		<div class="asl-side">
		<div class="asl-card asl-test" id="asl-test">
			<h2><?php esc_html_e( 'Test a search', 'advanced-search-logger' ); ?></h2>
			<p class="description"><?php esc_html_e( 'See what your site does with a search: rules, the normal result and, if it finds nothing, the automatic fixes.', 'advanced-search-logger' ); ?></p>
			<form method="get" class="asl-test-form" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="asl-synonyms">
				<input type="search" name="asl_test" value="<?php echo esc_attr( isset( $test_term ) ? $test_term : '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. لولهاب3/4', 'advanced-search-logger' ); ?>" dir="auto">
				<?php submit_button( __( 'Test', 'advanced-search-logger' ), 'secondary', '', false ); ?>
			</form>
			<?php if ( ! empty( $test ) ) : ?>
				<ol class="asl-test-steps">
					<?php foreach ( $test as $i => $step ) : ?>
						<?php $win = ( null === $step['found'] || $step['found'] > 0 ) && count( $test ) - 1 === $i; ?>
						<li class="<?php echo $win ? 'is-win' : ''; ?>">
							<span><?php echo esc_html( $step['label'] ); ?>: <span dir="auto">«<?php echo esc_html( urldecode( $step['term'] ) ); ?>»</span></span>
							<span>
							<?php
							echo null === $step['found'] ? '&rarr;' : esc_html(
								/* translators: %s: number of results */
								sprintf( _n( '%s result', '%s results', $step['found'], 'advanced-search-logger' ), asl_num( $step['found'] ) )
							);
							?>
							</span>
						</li>
					<?php endforeach; ?>
				</ol>
				<?php
				$last = end( $test );
				if ( 0 === $last['found'] ) :
					?>
					<p class="asl-bad"><?php esc_html_e( 'Still nothing. Add a synonym/redirect rule or the missing product.', 'advanced-search-logger' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
		</div>

		<div class="asl-card asl-import">
			<h2><?php esc_html_e( 'Import rules', 'advanced-search-logger' ); ?></h2>
			<p class="description"><?php esc_html_e( 'CSV columns: term, type (synonym/redirect), match_type (exact/contains/starts), target, status. A simple two-column file "term,target" also works. Existing terms are updated.', 'advanced-search-logger' ); ?></p>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="asl_import_rules">
				<?php wp_nonce_field( 'asl_import_rules' ); ?>
				<input type="file" name="rules_csv" accept=".csv,text/csv" required>
				<?php submit_button( __( 'Import', 'advanced-search-logger' ), 'secondary', 'submit', false ); ?>
			</form>
			<h3><?php esc_html_e( 'How rules are applied', 'advanced-search-logger' ); ?></h3>
			<ol class="asl-help">
				<li><?php esc_html_e( 'Exact rules win over "starts with", which wins over "contains".', 'advanced-search-logger' ); ?></li>
				<li><?php esc_html_e( 'Synonyms work in live (AJAX) search and on the results page; the original term is still logged so you can measure the effect.', 'advanced-search-logger' ); ?></li>
				<li><?php esc_html_e( 'The "Used" column counts how often each rule fired.', 'advanced-search-logger' ); ?></li>
			</ol>
		</div>
		</div>
	</div>

	<h2><?php esc_html_e( 'Existing Rules', 'advanced-search-logger' ); ?></h2>
	<form method="get">
		<input type="hidden" name="page" value="asl-synonyms" />
		<?php
		$table->search_box( __( 'Search rules', 'advanced-search-logger' ), 'asl-rule-search' );
		$table->display();
		?>
	</form>
</div>
