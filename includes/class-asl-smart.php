<?php
/**
 * Smart rescue for searches that return nothing.
 *
 * Runs ONLY when a search found zero results, so searches that already work are never touched.
 * Steps (stops at the first candidate that finds something):
 *  1. Clean-up: split digits/Latin glued to Persian words ("مغزی1/2" → "مغزی 1/2"), remove brackets
 *     and junk pasted from product pages ("افزودن به علاقه مندی", "برای مقایسه اضافه کنید"...).
 *  2. Spelling: every word that never appears in your product/post titles is corrected against a
 *     vocabulary built from those titles: missing آ ("اسانسور" → "آسانسور"), typos ("اتلین" → "اتیلن"),
 *     words written together ("لولهاب" → "لوله آب").
 *  3. Relax: drop unknown words, then sizes/numbers, then the last words, so a very specific
 *     query still shows the closest products.
 *
 * The visitor sees "Showing results for …" and the original term stays in the log as a rescued search.
 *
 * @package WebiSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASL_Smart {

	const VOCAB_OPTION = 'asl_vocab';

	/**
	 * Rescued search on the current page (for the notice).
	 *
	 * @var array|null [original, used]
	 */
	public static $notice = null;

	/**
	 * Vocabulary cache (normalized word => [display form, frequency]).
	 *
	 * @var array|null
	 */
	private static $vocab = null;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'asl_rebuild_vocab', array( __CLASS__, 'rebuild_vocab' ) );
		add_action( 'save_post', array( __CLASS__, 'mark_vocab_stale' ), 10, 2 );
		add_action( 'woocommerce_before_shop_loop', array( __CLASS__, 'print_notice' ), 5 );
		add_action( 'loop_start', array( __CLASS__, 'print_notice_generic' ) );
		add_filter( 'render_block', array( __CLASS__, 'block_notice' ), 10, 2 );
		add_action( 'woocommerce_no_products_found', array( __CLASS__, 'no_results_help' ), 20 );
		add_shortcode( 'webisearch_no_results', array( __CLASS__, 'no_results_shortcode' ) );
		add_filter( 'posts_search', array( __CLASS__, 'variant_search' ), 999, 2 );
		add_filter( 'post_search_columns', array( __CLASS__, 'search_columns' ), 10, 3 );
	}

	/* ------------------------------------------------------------------ *
	 * Spelling-insensitive matching (ی/ي، ک/ك، ۶۳/63، نیم‌فاصله)
	 * ------------------------------------------------------------------ */

	/**
	 * Spellings of one search word as it may be stored in titles.
	 *
	 * @param string $term Word.
	 * @return string[] Variants (without the term itself).
	 */
	public static function variants( $term ) {
		$fa_letters = array(
			'ي' => 'ی',
			'ى' => 'ی',
			'ك' => 'ک',
		);
		$ar_letters = array(
			'ی' => 'ي',
			'ک' => 'ك',
		);
		$fa_digits  = array( '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹' );
		$out        = array();
		// Hamza forms written differently: سوئیچ / سوییچ / سویچ.
		$bases = array( $term );
		if ( false !== strpos( $term, 'ئ' ) ) {
			$bases[] = str_replace( 'ئی', 'یی', $term );
			$bases[] = str_replace( array( 'ئی', 'ئ' ), 'ی', $term );
		}
		$forms = array();
		foreach ( $bases as $base ) {
			$forms[] = strtr( $base, $fa_letters );
			$forms[] = strtr( $base, $ar_letters );
		}
		foreach ( array_unique( $forms ) as $l ) {
			$latin = ASL_Normalizer::latin_digits( $l );
			$out[] = $latin;
			$out[] = strtr( $latin, $fa_digits );
		}
		$zwnj = "\u{200C}";
		if ( false !== strpos( $term, $zwnj ) ) {
			$out[] = str_replace( $zwnj, ' ', $term );
			$out[] = str_replace( $zwnj, '', $term );
		}
		$out = array_values( array_unique( $out ) );
		return array_values( array_diff( $out, array( $term ) ) );
	}

	/**
	 * Where visitors' searches look: title + short description + full description (WordPress default),
	 * or only title (+ short description). Long descriptions often mention other products
	 * ("قابل استفاده با تانکر") and pull unrelated items into the results.
	 *
	 * @param string[] $columns Columns.
	 * @param string   $search  Search.
	 * @param WP_Query $q       Query.
	 * @return string[]
	 */
	public static function search_columns( $columns, $search, $q ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $columns;
		}
		$mode = ASL_Settings::get( 'search_in', 'all' );
		if ( 'title' === $mode ) {
			return array( 'post_title' );
		}
		if ( 'title_excerpt' === $mode ) {
			return array( 'post_title', 'post_excerpt' );
		}
		return $columns;
	}

	/**
	 * Add a missing madda before searching ("تانکر اب" → "تانکر آب") when the catalog clearly
	 * writes the word with آ. Runs on every search, but only touches words that start with ا.
	 *
	 * @param string $s Search.
	 * @return string
	 */
	public static function fix_madda( $s ) {
		if ( false === strpos( $s, 'ا' ) ) {
			return $s;
		}
		$words = preg_split( '/(\s+)/u', $s, -1, PREG_SPLIT_DELIM_CAPTURE );
		$vocab = null;
		foreach ( $words as $i => $w ) {
			if ( 0 !== strpos( $w, 'ا' ) ) {
				continue;
			}
			if ( null === $vocab ) {
				$vocab = self::vocab();
			}
			$n   = ASL_Normalizer::normalize( $w );
			$alt = 'آ' . substr( $n, strlen( 'ا' ) );
			if ( isset( $vocab[ $alt ] ) && $vocab[ $alt ][1] >= 3 * ( isset( $vocab[ $n ] ) ? $vocab[ $n ][1] : 0 ) && $vocab[ $alt ][1] >= 3 ) {
				$words[ $i ] = 'آ' . substr( $w, strlen( 'ا' ) );
			}
		}
		return implode( '', $words );
	}

	/**
	 * Widen WordPress' search SQL so every word also matches its other spellings.
	 * Only the exact clauses WordPress generated are touched; anything other plugins changed is left alone.
	 *
	 * @param string   $search Search SQL.
	 * @param WP_Query $q      Query.
	 * @return string
	 */
	public static function variant_search( $search, $q ) {
		global $wpdb;
		if ( '' === trim( (string) $search ) || ! ASL_Settings::on( 'variant_search' ) || $q->get( 'asl_novariants' ) ) {
			return $search;
		}
		$terms = (array) $q->get( 'search_terms' );
		if ( ! $terms ) {
			return $search;
		}
		$n       = $q->get( 'exact' ) ? '' : '%';
		$columns = $q->get( 'search_columns' ) ? (array) $q->get( 'search_columns' ) : array( 'post_title', 'post_excerpt', 'post_content' );
		$columns = array_intersect( (array) apply_filters( 'post_search_columns', $columns, $q->get( 's' ), $q ), array( 'post_title', 'post_excerpt', 'post_content' ) );
		if ( ! $columns ) {
			$columns = array( 'post_title', 'post_excerpt', 'post_content' );
		}
		$prefix = apply_filters( 'wp_query_search_exclusion_prefix', '-' );
		foreach ( $terms as $term ) {
			$exclude = $prefix && 0 === strpos( $term, $prefix );
			$op      = $exclude ? 'NOT LIKE' : 'LIKE';
			$glue    = $exclude ? ' AND ' : ' OR ';
			$word    = $exclude ? substr( $term, 1 ) : $term;
			$vars    = self::variants( $word );
			// A 1–2 letter Persian word that is not a word of the catalog ("اب") matched inside other
			// words finds thousands of unrelated products ("قابل", "ابعاد"), so it must match as a whole word.
			$short = '%' === $n && ! $exclude && ASL_Normalizer::length( $word ) <= 2 && preg_match( '/^[\x{0600}-\x{06FF}]+$/u', $word ) && ! preg_match( '/[\x{06F0}-\x{06F9}\x{0660}-\x{0669}]/u', $word );
			if ( $short ) {
				// Real short catalog words ("آب", "گاز") keep matching as before ("پمپ آب" also finds "پمپ آبنما").
				$vocab = self::vocab();
				$nw    = ASL_Normalizer::normalize( $word );
				$short = ! isset( $vocab[ $nw ] ) || $vocab[ $nw ][1] < 3;
			}
			if ( ! $vars && ! $short ) {
				continue;
			}
			foreach ( $columns as $col ) {
				$orig = $wpdb->prepare( "({$wpdb->posts}.$col $op %s)", $n . $wpdb->esc_like( $word ) . $n ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( false === strpos( $search, $orig ) ) {
					continue;
				}
				$parts = array();
				foreach ( array_merge( array( $word ), $vars ) as $v ) {
					if ( $short ) {
						$e       = $wpdb->esc_like( $v );
						$parts[] = $wpdb->prepare( "({$wpdb->posts}.$col = %s OR {$wpdb->posts}.$col LIKE %s OR {$wpdb->posts}.$col LIKE %s OR {$wpdb->posts}.$col LIKE %s)", $v, $e . ' %', '% ' . $e, '% ' . $e . ' %' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					} else {
						$parts[] = $wpdb->prepare( "({$wpdb->posts}.$col $op %s)", $n . $wpdb->esc_like( $v ) . $n ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					}
				}
				$search = str_replace( $orig, '(' . implode( $glue, $parts ) . ')', $search );
			}
		}
		return $search;
	}

	/* ------------------------------------------------------------------ *
	 * Rescue
	 * ------------------------------------------------------------------ */

	/**
	 * Try to rescue a zero-result query. Replaces the query's results in place.
	 *
	 * @param WP_Query $q      The query that found nothing.
	 * @param string   $source standard|ajax|rest.
	 * @return array|null ['term' => used term, 'posts' => posts, 'found' => count] or null.
	 */
	public static function rescue( $q, $source ) {
		if ( ! ASL_Settings::on( 'smart_fallback' ) ) {
			return null;
		}
		$term = (string) $q->get( 's' );
		if ( ASL_Normalizer::length( $term ) < 3 ) {
			return null;
		}

		$vars = $q->query_vars;
		foreach ( array( 'search_terms', 'search_terms_count', 'search_orderby_title', 'asl_skip' ) as $k ) {
			unset( $vars[ $k ] );
		}

		// Remember the decision per term (also keeps page 2, 3... consistent).
		$cache_key = 'asl_fix_' . md5( $term . '|' . wp_json_encode( $vars['post_type'] ?? '' ) . '|' . get_option( 'asl_vocab_ver', '' ) );
		$chosen    = get_transient( $cache_key );
		if ( false === $chosen ) {
			$chosen = '';
			$tests  = 0;
			$max    = 'standard' === $source ? 6 : 2; // Live search: only clean-up + spelling (fast).
			foreach ( self::candidate_steps( $term, 'standard' === $source ) as $step ) {
				if ( $tests++ >= $max ) {
					break;
				}
				if ( self::acceptable( $vars, $step ) ) {
					$chosen = $step['term'];
					break;
				}
			}
			set_transient( $cache_key, $chosen, 6 * HOUR_IN_SECONDS );
		}
		if ( '' === $chosen ) {
			return null;
		}

		$vars['s']        = $chosen;
		$vars['asl_skip'] = 1;
		$new              = new WP_Query( $vars );
		if ( empty( $new->posts ) ) {
			return null;
		}
		$q->set( 's', $chosen );
		$q->found_posts   = max( (int) $new->found_posts, count( $new->posts ) );
		$q->max_num_pages = (int) $new->max_num_pages;
		if ( $q->is_main_query() && 'standard' === $source ) {
			self::$notice = array( wp_unslash( $term ), $chosen );
		}
		return array(
			'term'  => $chosen,
			'posts' => $new->posts,
			'found' => $q->found_posts,
		);
	}

	/**
	 * Does a candidate find something useful? A lone word that matches hundreds of products
	 * (often as part of other words) is rejected: an empty page with help is better than noise.
	 *
	 * @param array $vars Query vars.
	 * @param array $step Candidate step.
	 * @return int Results (0 = rejected).
	 */
	public static function acceptable( array $vars, array $step ) {
		$single = 'fewer_words' === $step['kind'] && false === strpos( trim( $step['term'] ), ' ' );
		if ( ! $single ) {
			return self::has_results( $vars, $step['term'] ) ? 1 : 0;
		}
		$found = self::count_results( $vars, $step['term'] );
		$limit = (int) apply_filters( 'asl_single_word_fallback_limit', 300 );
		return ( $found > 0 && $found <= $limit ) ? $found : 0;
	}

	/**
	 * Number of results for a term with the original query's settings.
	 *
	 * @param array  $vars Query vars.
	 * @param string $cand Term.
	 * @return int
	 */
	private static function count_results( array $vars, $cand ) {
		$vars['s']              = $cand;
		$vars['fields']         = 'ids';
		$vars['posts_per_page'] = 1;
		$vars['paged']          = 1;
		$vars['offset']         = 0;
		$vars['no_found_rows']  = false;
		$vars['asl_skip']       = 1;
		$test                   = new WP_Query( $vars );
		return (int) $test->found_posts;
	}

	/**
	 * Does a candidate find anything? (cheap ID-only query).
	 *
	 * @param array  $vars Query vars of the original query.
	 * @param string $cand Candidate term.
	 * @return bool
	 */
	private static function has_results( array $vars, $cand ) {
		$vars['s']              = $cand;
		$vars['fields']         = 'ids';
		$vars['posts_per_page'] = 1;
		$vars['paged']          = 1;
		$vars['offset']         = 0;
		$vars['no_found_rows']  = true;
		$vars['asl_skip']       = 1;
		$vars['cache_results']  = false;
		$test                   = new WP_Query( $vars );
		return ! empty( $test->posts );
	}

	/**
	 * Ordered list of alternative queries.
	 *
	 * @param string $term  Original term.
	 * @param bool   $relax Also generate shorter queries.
	 * @return string[]
	 */
	public static function candidates( $term, $relax = true ) {
		return wp_list_pluck( self::candidate_steps( $term, $relax ), 'term' );
	}

	/**
	 * Alternative queries with the kind of fix each one applies.
	 *
	 * @param string $term  Original term.
	 * @param bool   $relax Also generate shorter queries.
	 * @return array[] [ 'term' => string, 'kind' => cleanup|spelling|fewer_words ]
	 */
	public static function candidate_steps( $term, $relax = true ) {
		$steps = array();
		$clean = self::cleanup( $term );
		$fixed = self::spellfix( $clean );
		$steps[] = array( $clean, 'cleanup' );
		$steps[] = array( $fixed, 'spelling' );

		$stop  = self::stop_words();
		$words = preg_split( '/\s+/u', $fixed, -1, PREG_SPLIT_NO_EMPTY );
		// Words that carry meaning: no numbers, model codes, units or little words.
		$meaningful = array_values(
			array_filter(
				$words,
				function ( $w ) use ( $stop ) {
					$n = ASL_Normalizer::normalize( $w );
					return ASL_Normalizer::length( $n ) >= 2 && ! preg_match( '/\d/', $n ) && ! in_array( $n, $stop, true );
				}
			)
		);

		if ( $relax && count( $words ) >= 2 && $meaningful ) {
			$vocab = self::vocab();
			$known = array_values(
				array_filter(
					$meaningful,
					function ( $w ) use ( $vocab ) {
						return isset( $vocab[ ASL_Normalizer::normalize( $w ) ] );
					}
				)
			);
			// The first word usually names the product type. If the catalog has never used it
			// (leading Latin brand/model codes aside), shortening would only produce unrelated results.
			$head = $meaningful;
			while ( $head && preg_match( '/^[a-z\-]+$/i', $head[0] ) && ! isset( $vocab[ ASL_Normalizer::normalize( $head[0] ) ] ) ) {
				array_shift( $head );
			}
			if ( ! $head || ! isset( $vocab[ ASL_Normalizer::normalize( $head[0] ) ] ) ) {
				$known = array();
				$relax = false;
			}
			$m = $known ? $known : $meaningful;
		}
		if ( $relax && count( $words ) >= 2 && $meaningful ) {
			// a) Only the meaningful words that exist in the catalog.
			$steps[] = array( implode( ' ', $m ), 'fewer_words' );
			// b) The first three / two of them (the head noun comes first in Persian).
			if ( count( $m ) > 3 ) {
				$steps[] = array( implode( ' ', array_slice( $m, 0, 3 ) ), 'fewer_words' );
			}
			if ( count( $m ) > 2 ) {
				$steps[] = array( implode( ' ', array_slice( $m, 0, 2 ) ), 'fewer_words' );
			}
			// c) Without the first word ("قابل لوله خم کن" → "لوله خم کن").
			if ( count( $m ) >= 3 ) {
				$steps[] = array( implode( ' ', array_slice( $m, 1 ) ), 'fewer_words' );
			}
			// d) The head word alone, only if it is specific enough to be useful.
			$steps[] = array( $m[0], 'fewer_words' );
		}

		$seen  = array( $term => true );
		$out   = array();
		$multi = count( $words ) >= 2;
		foreach ( $steps as $step ) {
			$t = trim( $step[0] );
			if ( '' === $t || isset( $seen[ $t ] ) ) {
				continue;
			}
			$seen[ $t ] = true;
			$tw         = array_values(
				array_filter(
					explode( ' ', $t ),
					function ( $w ) use ( $stop ) {
						$n = ASL_Normalizer::normalize( $w );
						return ASL_Normalizer::length( $n ) >= 2 && ! preg_match( '/\d/', $n ) && ! in_array( $n, $stop, true );
					}
				)
			);
			// Must still contain a real word, not only numbers/codes/units.
			if ( ! $tw ) {
				continue;
			}
			// A lone, very common word ("کلید", "اتصال") would flood the visitor with unrelated products.
			if ( $multi && 1 === count( $tw ) && 'fewer_words' === $step[1] && ! self::is_specific( $tw[0] ) ) {
				continue;
			}
			$out[] = array(
				'term' => $t,
				'kind' => $step[1],
			);
		}
		return $out;
	}

	/**
	 * Is a word rare enough in the catalog to be searched on its own?
	 *
	 * @param string $w Word.
	 * @return bool
	 */
	private static function is_specific( $w ) {
		$vocab = self::vocab();
		$n     = ASL_Normalizer::normalize( $w );
		if ( ! isset( $vocab[ $n ] ) || ASL_Normalizer::length( $n ) < 3 ) {
			return false;
		}
		$docs = max( 1, (int) get_option( 'asl_vocab_docs', 1 ) );
		return $vocab[ $n ][1] <= max( 40, 0.005 * $docs );
	}

	/**
	 * Little words, units and labels that are dropped when shortening a search.
	 *
	 * @return string[] Normalized.
	 */
	private static function stop_words() {
		static $list = null;
		if ( null === $list ) {
			$list = array_map(
				array( 'ASL_Normalizer', 'normalize' ),
				array_merge(
					self::filler_words(),
					apply_filters( 'asl_stop_words', array( 'و', 'به', 'با', 'از', 'در', 'برای', 'که', 'یا', 'تا', 'بر', 'را', 'این', 'آن', 'سر', 'پنج', 'قابل', 'بین', 'نوع', 'جنس', 'طرح', 'مخصوص', 'دار', 'های', 'ها', 'ای', 'یک', 'نیم', 'اصل', 'اصلی', 'جدید', 'ارزان', 'فروش', 'نمره', 'شماره' ) )
				)
			);
		}
		return $list;
	}

	/**
	 * Step 1: clean-up.
	 *
	 * @param string $s Term.
	 * @return string
	 */
	public static function cleanup( $s ) {
		$s = ASL_Normalizer::fix_query( $s );
		// Text pasted together with product-page buttons, greetings and buying words.
		$junk = apply_filters(
			'asl_search_junk_phrases',
			array( 'برای مقایسه اضافه کنید', 'افزودن به علاقه مندی', 'افزودن به علاقه‌مندی', 'افزودن به سبد خرید', 'افزودن به سبد', 'مشاهده سریع', 'image 1', 'سلام', 'خرید', 'قیمت', 'لطفا', 'ممنون' )
		);
		foreach ( $junk as $j ) {
			$s = preg_replace( '/(^|\s)' . preg_quote( $j, '/' ) . '(?=\s|$|[.,،:!?؟])/iu', ' ', $s );
		}
		// Write digits the way the catalog does (Persian ۳/۴ vs Latin 3/4).
		$s = self::site_digits( $s );
		// Persian letters glued to digits or Latin letters ("مغزی1/2", "لولهpvc"). Letters only: digits are excluded from the class.
		$fa = '\x{0621}-\x{063A}\x{0641}-\x{064A}\x{067E}\x{0686}\x{0698}\x{06A9}\x{06AF}\x{06CC}\x{0622}';
		$s  = preg_replace( '/([' . $fa . '])([0-9a-zA-Z\x{06F0}-\x{06F9}])/u', '$1 $2', $s );
		$s  = preg_replace( '/([0-9a-zA-Z\x{06F0}-\x{06F9}])([' . $fa . '])/u', '$1 $2', $s );
		// Latin material/model glued to a size: "cpvc75" → "cpvc 75".
		$s = preg_replace( '/\b([a-zA-Z]{3,})(\d{2,})\b/u', '$1 $2', $s );
		// Brackets, separators and stray punctuation (keeps 1/2, 6.5 and model codes like ae15g-15b).
		$s = preg_replace( '/[()\[\]{}|«»"“”،,;:!?؟*×–—_]+/u', ' ', $s );
		$s = preg_replace( '/(^|\s)[.\-\/]+|[.\-\/]+(\s|$)/u', ' ', $s );
		$s = preg_replace( '/\s+/u', ' ', $s );
		// The same text pasted twice ("گیج فشار 6بار تابلوییگیج فشار"): drop repeated words (not numbers).
		$seen = array();
		$out  = array();
		foreach ( explode( ' ', trim( $s ) ) as $w ) {
			$key = ASL_Normalizer::normalize( $w );
			if ( ASL_Normalizer::length( $key ) >= 3 && ! preg_match( '/\d/', $key ) && isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = 1;
			$out[]        = $w;
		}
		return trim( implode( ' ', $out ) );
	}

	/**
	 * Convert digits to the style used in the catalog titles.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	private static function site_digits( $s ) {
		$style = get_option( 'asl_vocab_digits', 'latin' );
		if ( 'persian' === $style ) {
			return strtr( ASL_Normalizer::latin_digits( $s ), array( '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹' ) );
		}
		return ASL_Normalizer::latin_digits( $s );
	}

	/**
	 * Words that only describe a size/unit/brand label and can be dropped when relaxing a search.
	 *
	 * @return string[]
	 */
	private static function filler_words() {
		return apply_filters( 'asl_relax_filler_words', array( 'مدل', 'برند', 'سایز', 'ظرفیت', 'قطر', 'ضخامت', 'ابعاد', 'فاصله', 'عدد', 'عددی', 'متر', 'متری', 'سانت', 'سانتی', 'میلی', 'میل', 'اینچ', 'اینج', 'دور', 'کیلووات', 'وات', 'ولت', 'آمپر', 'امپر', 'اسب', 'بار', 'درجه', 'لیتر', 'لیتری', 'کیلو', 'گرم', 'پل', 'فاز', 'رنگ' ) );
	}

	/**
	 * Step 2: correct words that do not exist in the catalog.
	 *
	 * @param string $s Clean term.
	 * @return string
	 */
	public static function spellfix( $s ) {
		$vocab = self::vocab();
		if ( ! $vocab ) {
			return $s;
		}
		$out = array();
		foreach ( preg_split( '/\s+/u', $s, -1, PREG_SPLIT_NO_EMPTY ) as $w ) {
			$out[] = self::fix_word( $w, $vocab );
		}
		return implode( ' ', $out );
	}

	/**
	 * Correct one word.
	 *
	 * @param string $w     Word.
	 * @param array  $vocab Vocabulary.
	 * @return string
	 */
	private static function fix_word( $w, array $vocab ) {
		$n = ASL_Normalizer::normalize( $w );
		if ( '' === $n || preg_match( '/\d/', $n ) ) {
			return $w;
		}
		if ( isset( $vocab[ $n ] ) ) {
			// Known word, but the catalog clearly prefers the آ spelling ("اب" vs "آب").
			if ( 0 === strpos( $n, 'ا' ) ) {
				$alt = 'آ' . substr( $n, strlen( 'ا' ) );
				if ( isset( $vocab[ $alt ] ) && $vocab[ $alt ][1] >= 3 * $vocab[ $n ][1] ) {
					return $vocab[ $alt ][0];
				}
			}
			return $w;
		}
		$len = ASL_Normalizer::length( $n );

		// Missing madda: "اب" → "آب", "ابیاری" → "آبیاری".
		if ( 0 === strpos( $n, 'ا' ) ) {
			$alt = 'آ' . substr( $n, strlen( 'ا' ) );
			if ( isset( $vocab[ $alt ] ) ) {
				return $vocab[ $alt ][0];
			}
		}
		if ( $len < 3 ) {
			return $w;
		}

		// Two words written together: "لولهاب" → "لوله آب".
		$chars = preg_split( '//u', $n, -1, PREG_SPLIT_NO_EMPTY );
		$best_split = null;
		$best_freq  = 0;
		for ( $i = 2; $i <= count( $chars ) - 2; $i++ ) {
			$a = implode( '', array_slice( $chars, 0, $i ) );
			$b = implode( '', array_slice( $chars, $i ) );
			if ( 0 === strpos( $b, 'ا' ) ) {
				$alt_b = 'آ' . substr( $b, strlen( 'ا' ) );
				if ( isset( $vocab[ $alt_b ] ) && ( ! isset( $vocab[ $b ] ) || $vocab[ $alt_b ][1] >= 3 * $vocab[ $b ][1] ) ) {
					$b = $alt_b;
				}
			}
			// Both parts must be real words: 2-letter parts only when common (آب), never little words (در، بر).
			$stop = self::stop_words();
			$ok_a = ASL_Normalizer::length( $a ) >= 3 || ( isset( $vocab[ $a ] ) && $vocab[ $a ][1] >= 20 );
			$ok_b = ASL_Normalizer::length( $b ) >= 3 || ( isset( $vocab[ $b ] ) && $vocab[ $b ][1] >= 20 );
			if ( ! $ok_a || ! $ok_b || in_array( $a, $stop, true ) || in_array( $b, $stop, true ) ) {
				continue;
			}
			if ( isset( $vocab[ $a ], $vocab[ $b ] ) ) {
				$freq = min( $vocab[ $a ][1], $vocab[ $b ][1] );
				if ( $freq > $best_freq ) {
					$best_freq  = $freq;
					$best_split = $vocab[ $a ][0] . ' ' . $vocab[ $b ][0];
				}
			}
		}

		// Typo: closest catalog word. Short words (≤4 letters) are too ambiguous to correct;
		// 1 edit for 5–6 letters, 2 for longer words; the first letter must match.
		if ( $len <= 3 ) {
			return $best_split ? $best_split : $w;
		}
		$max       = $len >= 7 ? 2 : 1;
		$best      = null;
		$best_dist = PHP_INT_MAX;
		$best_f    = 0;
		$first = $chars[0];
		foreach ( $vocab as $vw => $info ) {
			$vl = $info[2];
			if ( abs( $vl - $len ) > $max ) {
				continue;
			}
			// Typos almost always keep the first letter (ا and آ count as the same letter).
			$vw = (string) $vw;
			if ( 0 !== strpos( $vw, $first ) && ! ( 'ا' === $first && 0 === strpos( $vw, 'آ' ) ) ) {
				continue;
			}
			// "دینام" → "دینا" or "تانکر" → "تانک" are different words, not typos.
			if ( 0 === strpos( $n, $vw ) || 0 === strpos( $vw, $n ) ) {
				continue;
			}
			// 4-letter words: only a forgotten letter ("کاید" → "کلید"). Changing or dropping one makes
			// a different common word too easily ("برقو" → "برقی", "کاتر" → "کار").
			if ( 4 === $len && $vl !== $len + 1 ) {
				continue;
			}
			$d = ASL_Normalizer::distance( $n, $vw );
			if ( $d <= $max && ( $d < $best_dist || ( $d === $best_dist && $info[1] > $best_f ) ) ) {
				$best      = $info[0];
				$best_dist = $d;
				$best_f    = $info[1];
			}
		}
		if ( $best_split && ( null === $best || $best_dist > 1 ) ) {
			return $best_split;
		}
		return null !== $best ? $best : ( $best_split ? $best_split : $w );
	}

	/* ------------------------------------------------------------------ *
	 * Vocabulary of the catalog
	 * ------------------------------------------------------------------ */

	/**
	 * Words used in searchable titles: normalized => [display form, frequency, length].
	 *
	 * @return array
	 */
	public static function vocab() {
		if ( null !== self::$vocab ) {
			return self::$vocab;
		}
		$v = get_option( self::VOCAB_OPTION );
		if ( ! is_array( $v ) ) {
			$v = self::rebuild_vocab();
		}
		self::$vocab = $v;
		return $v;
	}

	/**
	 * Build the vocabulary from published titles of searchable post types.
	 *
	 * @return array
	 */
	public static function rebuild_vocab() {
		global $wpdb;
		$types = array_values( array_diff( get_post_types( array( 'exclude_from_search' => false ) ), array( 'attachment' ) ) );
		$types = apply_filters( 'asl_vocab_post_types', $types );
		if ( ! $types ) {
			return array();
		}
		$in     = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$titles = $wpdb->get_col( $wpdb->prepare( "SELECT post_title FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($in) ORDER BY ID DESC LIMIT 60000", $types ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$freq   = array();
		$forms  = array();
		foreach ( $titles as $title ) {
			$clean = ASL_Normalizer::fix_query( wp_strip_all_tags( $title ) );
			$clean = preg_replace( '/[()\[\]{}|«»"“”،,;:!?؟*×–—_\/\\\\]+/u', ' ', $clean );
			foreach ( preg_split( '/[\s\x{200C}]+/u', $clean, -1, PREG_SPLIT_NO_EMPTY ) as $word ) {
				$word = trim( $word, '.-' );
				$norm = ASL_Normalizer::normalize( $word );
				if ( ASL_Normalizer::length( $norm ) < 2 || preg_match( '/^\d+$/', $norm ) ) {
					continue;
				}
				$freq[ $norm ] = ( $freq[ $norm ] ?? 0 ) + 1;
				if ( ! isset( $forms[ $norm ] ) ) {
					$forms[ $norm ] = $word;
				}
			}
		}
		// Which digits do titles use?
		$fa_digits = 0;
		$en_digits = 0;
		foreach ( $titles as $title ) {
			$fa_digits += preg_match_all( '/[\x{06F0}-\x{06F9}]/u', $title );
			$en_digits += preg_match_all( '/[0-9]/', $title );
		}
		update_option( 'asl_vocab_digits', $fa_digits > $en_digits ? 'persian' : 'latin', false );

		arsort( $freq );
		$freq  = array_slice( $freq, 0, 25000, true );
		$vocab = array();
		foreach ( $freq as $norm => $f ) {
			$vocab[ (string) $norm ] = array( $forms[ $norm ], $f, ASL_Normalizer::length( (string) $norm ) );
		}
		update_option( self::VOCAB_OPTION, $vocab, false );
		update_option( 'asl_vocab_docs', count( $titles ), false );
		update_option( 'asl_vocab_ver', (string) time(), false );
		self::$vocab = $vocab;
		return $vocab;
	}

	/**
	 * A product/post changed: rebuild the vocabulary within the next minutes (batched).
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public static function mark_vocab_stale( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || 'publish' !== $post->post_status ) {
			return;
		}
		if ( ! wp_next_scheduled( 'asl_rebuild_vocab' ) ) {
			wp_schedule_single_event( time() + 10 * MINUTE_IN_SECONDS, 'asl_rebuild_vocab' );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Front-end output
	 * ------------------------------------------------------------------ */

	/**
	 * "Showing results for …" notice (WooCommerce shop loop).
	 */
	public static function print_notice() {
		static $done = false;
		if ( $done || ! self::$notice || ! ASL_Settings::on( 'fallback_notice' ) ) {
			return;
		}
		$done = true;
		echo self::notice_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
	}

	/**
	 * Same notice for non-WooCommerce themes (main query loop).
	 *
	 * @param WP_Query $q Query.
	 */
	public static function print_notice_generic( $q ) {
		// Block themes build the page before <head> is sent: they get the notice through render_block instead.
		if ( ! did_action( 'wp_head' ) || ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) ) {
			return;
		}
		if ( $q->is_main_query() && ! ( function_exists( 'is_woocommerce' ) && is_woocommerce() ) ) {
			self::print_notice();
		}
	}

	/**
	 * Block themes: add the notice after the query title (or before the post list).
	 *
	 * @param string $content Block HTML.
	 * @param array  $block   Block.
	 * @return string
	 */
	public static function block_notice( $content, $block ) {
		static $done = false;
		if ( $done || ! self::$notice || ! ASL_Settings::on( 'fallback_notice' ) ) {
			return $content;
		}
		if ( 'core/query-title' === $block['blockName'] ) {
			$done = true;
			return $content . self::notice_html();
		}
		if ( 'core/post-template' === $block['blockName'] ) {
			$done = true;
			return self::notice_html() . $content;
		}
		return $content;
	}

	/**
	 * Notice HTML.
	 *
	 * @return string
	 */
	private static function notice_html() {
		list( $original, $used ) = self::$notice;
		/* translators: 1: term used, 2: what the visitor typed */
		$text = sprintf( __( 'Showing results for %1$s. Nothing matched %2$s exactly.', 'advanced-search-logger' ), '<strong>' . esc_html( $used ) . '</strong>', '<em>' . esc_html( $original ) . '</em>' );
		return '<p class="asl-fallback-notice" style="padding:10px 14px;margin:0 0 18px;border-radius:6px;background:rgba(0,115,170,.07);border-inline-start:3px solid #2271b1">' . $text . '</p>';
	}

	/**
	 * Help block on an empty WooCommerce result page: popular searches + contact text.
	 */
	public static function no_results_help() {
		if ( ! is_search() || ! ASL_Settings::on( 'no_results_help' ) ) {
			return;
		}
		echo self::no_results_shortcode(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}

	/**
	 * [webisearch_no_results] – the same block for page builders / non-WooCommerce themes.
	 *
	 * @return string
	 */
	public static function no_results_shortcode() {
		$html = '<div class="asl-no-results-help" style="margin:18px 0">';
		$text = trim( (string) ASL_Settings::get( 'no_results_text', '' ) );
		if ( '' !== $text ) {
			$html .= '<div class="asl-no-results-help__text" style="margin-bottom:12px">' . wp_kses_post( wpautop( $text ) ) . '</div>';
		}
		$shortcodes = new ASL_Shortcodes();
		$html      .= $shortcodes->popular(
			array(
				'title' => __( 'Popular searches:', 'advanced-search-logger' ),
				'limit' => 10,
			)
		);
		return $html . '</div>';
	}

	/* ------------------------------------------------------------------ *
	 * Admin "test a search" tool
	 * ------------------------------------------------------------------ */

	/**
	 * Explain what would happen for a term (no logging).
	 *
	 * @param string $term Term.
	 * @return array
	 */
	public static function explain( $term ) {
		$vars  = self::default_vars();
		$steps = array();
		$fixed = ASL_Settings::on( 'normalize_query' ) ? ASL_Smart::fix_madda( ASL_Normalizer::fix_query( $term ) ) : $term;
		$rule  = ASL_Rules::match( $fixed );
		if ( $rule ) {
			$steps[] = array(
				'label' => 'redirect' === $rule['type'] ? __( 'Redirect rule', 'advanced-search-logger' ) : __( 'Synonym rule', 'advanced-search-logger' ),
				'term'  => $rule['result'],
				'found' => 'redirect' === $rule['type'] ? null : self::count( $vars, $rule['result'] ),
			);
			if ( 'redirect' === $rule['type'] ) {
				return $steps;
			}
			$fixed = $rule['result'];
		}
		$found   = self::count( $vars, $fixed );
		$steps[] = array(
			'label' => __( 'Normal search', 'advanced-search-logger' ),
			'term'  => $fixed,
			'found' => $found,
		);
		if ( $found > 0 ) {
			return $steps;
		}
		$labels = array(
			'cleanup'     => __( 'Clean-up', 'advanced-search-logger' ),
			'spelling'    => __( 'Spelling fix', 'advanced-search-logger' ),
			'fewer_words' => __( 'Fewer words', 'advanced-search-logger' ),
		);
		$tests  = 0;
		foreach ( self::candidate_steps( $fixed, true ) as $step ) {
			if ( $tests++ >= 6 ) {
				break;
			}
			$ok      = self::acceptable( $vars, $step );
			$n       = self::count( $vars, $step['term'] );
			$label   = $labels[ $step['kind'] ];
			if ( ! $ok && $n > 0 ) {
				$label .= ' — ' . __( 'too broad, skipped', 'advanced-search-logger' );
			}
			$steps[] = array(
				'label' => $label,
				'term'  => $step['term'],
				'found' => $ok ? $n : 0,
			);
			if ( $ok ) {
				break;
			}
		}
		return $steps;
	}

	/**
	 * Decide what happens today to a search that once found nothing.
	 *
	 * @param string $term What the visitor typed.
	 * @return array status (found|fixed|approximate|missing), to (term used), found (results), kind.
	 */
	public static function classify( $term ) {
		$vars  = self::default_vars();
		$fixed = ASL_Settings::on( 'normalize_query' ) ? ASL_Smart::fix_madda( ASL_Normalizer::fix_query( $term ) ) : $term;
		$rule  = ASL_Rules::match( $fixed );
		if ( $rule ) {
			if ( 'redirect' === $rule['type'] ) {
				return array( 'status' => 'found', 'to' => $rule['result'], 'found' => -1, 'kind' => 'redirect' );
			}
			$fixed = $rule['result'];
		}
		$n = self::count( $vars, $fixed );
		if ( $n > 0 ) {
			return array( 'status' => 'found', 'to' => $fixed, 'found' => $n, 'kind' => $rule ? 'rule' : 'direct' );
		}
		if ( ASL_Settings::on( 'smart_fallback' ) ) {
			$tests = 0;
			foreach ( self::candidate_steps( $fixed, true ) as $step ) {
				if ( $tests++ >= 6 ) {
					break;
				}
				if ( self::acceptable( $vars, $step ) ) {
					return array(
						'status' => 'fewer_words' === $step['kind'] ? 'approximate' : 'fixed',
						'to'     => $step['term'],
						'found'  => self::count( $vars, $step['term'] ),
						'kind'   => $step['kind'],
					);
				}
			}
		}
		return array( 'status' => 'missing', 'to' => '', 'found' => 0, 'kind' => '' );
	}

	/**
	 * Query vars of a typical visitor search.
	 *
	 * @return array
	 */
	private static function default_vars() {
		return apply_filters(
			'asl_review_query_vars',
			array(
				'post_status' => 'publish',
				'post_type'   => class_exists( 'WooCommerce' ) ? 'product' : 'any',
			)
		);
	}

	/**
	 * Count results for a term.
	 *
	 * @param array  $vars Base vars.
	 * @param string $term Term.
	 * @return int
	 */
	private static function count( array $vars, $term ) {
		$q = new WP_Query(
			array_merge(
				$vars,
				array(
					's'              => $term,
					'fields'         => 'ids',
					'posts_per_page' => 1,
					'asl_skip'       => 1,
				)
			)
		);
		return (int) $q->found_posts;
	}
}
