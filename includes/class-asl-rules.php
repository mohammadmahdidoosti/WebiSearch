<?php
/**
 * Synonym / redirect rule matching.
 *
 * @package WebiSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASL_Rules {

	/**
	 * Find the rule for a search. Exact rules win, then "starts with", then "contains" (longest term first).
	 *
	 * @param string $search What the visitor typed.
	 * @return array|null Rule plus 'result' (rewritten query for synonyms, URL for redirects).
	 */
	public static function match( $search ) {
		$norm = ASL_Normalizer::normalize( $search );
		if ( '' === $norm ) {
			return null;
		}
		$rules = ASL_DB::active_rules();
		if ( ! $rules ) {
			return null;
		}

		$best       = null;
		$best_score = -1;
		$best_term  = '';
		foreach ( $rules as $rule ) {
			$rterm = ASL_Normalizer::normalize( $rule['term'] );
			if ( '' === $rterm ) {
				continue;
			}
			$score = -1;
			switch ( $rule['match_type'] ) {
				case 'contains':
					if ( false !== self::find_word( $norm, $rterm ) ) {
						$score = 1000 + ASL_Normalizer::length( $rterm );
					}
					break;
				case 'starts':
					if ( 0 === self::find_word( $norm, $rterm ) ) {
						$score = 2000 + ASL_Normalizer::length( $rterm );
					}
					break;
				default:
					if ( $norm === $rterm ) {
						$score = 3000;
					}
			}
			if ( $score > $best_score ) {
				$best       = $rule;
				$best_score = $score;
				$best_term  = $rterm;
			}
		}
		if ( ! $best ) {
			return null;
		}

		if ( 'redirect' === $best['type'] ) {
			$best['result'] = self::redirect_url( $best['target'], $search );
		} elseif ( 'exact' === $best['match_type'] ) {
			$best['result'] = $best['target'];
		} else {
			// Replace only the matched part, keep the rest of the query ("هودی مردانه" => "سوییشرت مردانه").
			$pos            = 'starts' === $best['match_type'] ? 0 : self::find_word( $norm, $best_term );
			$before         = substr( $norm, 0, $pos );
			$after          = substr( $norm, $pos + strlen( $best_term ) );
			$best['result'] = trim( preg_replace( '/\s+/u', ' ', $before . $best['target'] . $after ) );
		}
		return apply_filters( 'asl_matched_rule', $best, $search );
	}

	/**
	 * Byte position of a whole-word occurrence (so "پمپ" does not match inside "پمپاژ"... unless it is the whole query).
	 *
	 * @param string $haystack Normalized query.
	 * @param string $needle   Normalized rule term.
	 * @return int|false
	 */
	private static function find_word( $haystack, $needle ) {
		$offset = 0;
		while ( false !== ( $pos = strpos( $haystack, $needle, $offset ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			$before_ok = 0 === $pos || ' ' === $haystack[ $pos - 1 ];
			$end       = $pos + strlen( $needle );
			$after_ok  = strlen( $haystack ) === $end || ' ' === $haystack[ $end ];
			if ( $before_ok && $after_ok ) {
				return $pos;
			}
			$offset = $pos + 1;
		}
		return false;
	}

	/**
	 * Build the redirect URL. Supports relative paths and the {query} placeholder.
	 *
	 * @param string $target Target from the rule.
	 * @param string $search Original search.
	 * @return string
	 */
	public static function redirect_url( $target, $search ) {
		$target = str_replace( '{query}', rawurlencode( $search ), $target );
		if ( 0 === strpos( $target, '/' ) && 0 !== strpos( $target, '//' ) ) {
			$target = home_url( $target );
		}
		return apply_filters( 'asl_redirect_url', $target, $search );
	}

	/**
	 * Validate and clean a redirect target. Keeps percent-encoded (Persian) URLs intact.
	 *
	 * @param string $target Raw input.
	 * @return string Empty string when invalid.
	 */
	public static function sanitize_redirect_target( $target ) {
		$target = trim( (string) $target );
		if ( '' === $target ) {
			return '';
		}
		// Relative path on this site.
		if ( 0 === strpos( $target, '/' ) && 0 !== strpos( $target, '//' ) ) {
			return esc_url_raw( home_url( $target ) ) ? $target : '';
		}
		if ( ! preg_match( '#^https?://#i', $target ) ) {
			$target = 'https://' . $target;
		}
		return esc_url_raw( $target, array( 'http', 'https' ) );
	}
}
