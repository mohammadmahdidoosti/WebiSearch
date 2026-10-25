<?php
/**
 * Text normalization (Persian/Arabic aware) and fuzzy matching helpers.
 *
 * @package WebiSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASL_Normalizer {

	/**
	 * Arabic letters that Persian keyboards/sites commonly mix up, mapped to Persian.
	 *
	 * @return array
	 */
	private static function letter_map() {
		return array(
			"\u{064A}" => "\u{06CC}", // ي Arabic yeh => ی.
			"\u{0649}" => "\u{06CC}", // ى alef maksura => ی.
			"\u{0643}" => "\u{06A9}", // ك Arabic kaf => ک.
			"\u{0640}" => '',         // ـ tatweel.
		);
	}

	/**
	 * Characters removed or turned into spaces.
	 *
	 * @return array
	 */
	private static function invisible_map() {
		return array(
			"\u{200B}" => '',  // zero width space.
			"\u{200D}" => '',  // zero width joiner.
			"\u{200E}" => '',  // LRM.
			"\u{200F}" => '',  // RLM.
			"\u{FEFF}" => '',  // BOM.
			"\u{00A0}" => ' ', // nbsp.
		);
	}

	/**
	 * Light normalization that is safe to apply to the real search query:
	 * fixes Arabic yeh/kaf typed on Arabic keyboards, removes tatweel, diacritics and invisible characters.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	public static function fix_query( $s ) {
		$s = strtr( (string) $s, self::letter_map() + self::invisible_map() );
		$s = preg_replace( '/[\x{064B}-\x{065F}\x{0670}]/u', '', $s ); // Arabic diacritics.
		$s = preg_replace( '/\s+/u', ' ', $s );
		return trim( (string) $s );
	}

	/**
	 * Aggressive normalization used to group searches and match rules:
	 * fix_query() + Persian/Arabic digits to Latin, ZWNJ to space, hamza forms, lowercase.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	public static function normalize( $s ) {
		$s = self::fix_query( $s );
		$s = strtr(
			$s,
			array(
				"\u{200C}" => ' ', // ZWNJ (نیم‌فاصله).
				"\u{0629}" => "\u{0647}", // ة => ه.
				"\u{06C0}" => "\u{0647}", // ۀ => ه.
				"\u{0623}" => "\u{0627}", // أ => ا.
				"\u{0625}" => "\u{0627}", // إ => ا.
				"\u{0671}" => "\u{0627}", // ٱ => ا.
				"\u{0624}" => "\u{0648}", // ؤ => و.
			)
		);
		$s = self::latin_digits( $s );
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
		$s = preg_replace( '/\s+/u', ' ', $s );
		// Trim spaces and punctuation at both ends (multibyte-safe; trim() would cut Persian letters in half).
		$s = preg_replace( '/^[\s"\'`.,;:!?\x{061F}\x{060C}\x{061B}\x{00AB}\x{00BB}]+|[\s"\'`.,;:!?\x{061F}\x{060C}\x{061B}\x{00AB}\x{00BB}]+$/u', '', (string) $s );
		return self::truncate( $s, 191 );
	}

	/**
	 * Convert Persian and Arabic-Indic digits to Latin digits.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	public static function latin_digits( $s ) {
		static $map = null;
		if ( null === $map ) {
			$map     = array();
			$persian = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' );
			$arabic  = array( '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' );
			for ( $i = 0; $i < 10; $i++ ) {
				$map[ $persian[ $i ] ] = (string) $i;
				$map[ $arabic[ $i ] ]  = (string) $i;
			}
		}
		return strtr( (string) $s, $map );
	}

	/**
	 * Multibyte-safe truncate.
	 *
	 * @param string $s   Text.
	 * @param int    $len Max characters.
	 * @return string
	 */
	public static function truncate( $s, $len ) {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( (string) $s, 0, $len, 'UTF-8' );
		}
		return substr( (string) $s, 0, $len );
	}

	/**
	 * Character length.
	 *
	 * @param string $s Text.
	 * @return int
	 */
	public static function length( $s ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $s, 'UTF-8' ) : strlen( (string) $s );
	}

	/**
	 * Unicode-aware Levenshtein distance (PHP's levenshtein() counts bytes, which breaks Persian).
	 *
	 * @param string $a First.
	 * @param string $b Second.
	 * @return int
	 */
	public static function distance( $a, $b ) {
		// Optimal string alignment (Damerau-Levenshtein): a swap of two neighbouring letters ("اتلین" ↔ "اتیلن") costs 1.
		$a  = preg_split( '//u', (string) $a, -1, PREG_SPLIT_NO_EMPTY );
		$b  = preg_split( '//u', (string) $b, -1, PREG_SPLIT_NO_EMPTY );
		$la = count( $a );
		$lb = count( $b );
		if ( 0 === $la ) {
			return $lb;
		}
		if ( 0 === $lb ) {
			return $la;
		}
		$d = array();
		for ( $i = 0; $i <= $la; $i++ ) {
			$d[ $i ][0] = $i;
		}
		for ( $j = 0; $j <= $lb; $j++ ) {
			$d[0][ $j ] = $j;
		}
		for ( $i = 1; $i <= $la; $i++ ) {
			for ( $j = 1; $j <= $lb; $j++ ) {
				$cost        = ( $a[ $i - 1 ] === $b[ $j - 1 ] ) ? 0 : 1;
				$d[ $i ][ $j ] = min( $d[ $i - 1 ][ $j ] + 1, $d[ $i ][ $j - 1 ] + 1, $d[ $i - 1 ][ $j - 1 ] + $cost );
				if ( $i > 1 && $j > 1 && $a[ $i - 1 ] === $b[ $j - 2 ] && $a[ $i - 2 ] === $b[ $j - 1 ] ) {
					$d[ $i ][ $j ] = min( $d[ $i ][ $j ], $d[ $i - 2 ][ $j - 2 ] + 1 );
				}
			}
		}
		return $d[ $la ][ $lb ];
	}

	/**
	 * Does a text look like spam (URLs, e-mails, code, very long random strings)?
	 *
	 * @param string $s Text.
	 * @return bool
	 */
	public static function looks_like_spam( $s ) {
		if ( preg_match( '#(https?://|www\.|\.(com|net|org|ru|cn|xyz|top)\b|<|>|\{|\}|\bselect\b.+\bfrom\b|\bunion\b.+\bselect\b|@[a-z0-9-]+\.)#iu', $s ) ) {
			return true;
		}
		// A single "word" of 40+ characters without spaces is almost always junk.
		if ( preg_match( '/\S{40,}/u', $s ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Wildcard match ("*" = anything) on normalized text.
	 *
	 * @param string $pattern Pattern.
	 * @param string $text    Text.
	 * @return bool
	 */
	public static function wildcard_match( $pattern, $text ) {
		$pattern = self::normalize( $pattern );
		if ( '' === $pattern ) {
			return false;
		}
		if ( false === strpos( $pattern, '*' ) ) {
			return $pattern === $text;
		}
		$regex = '/^' . str_replace( '\*', '.*', preg_quote( $pattern, '/' ) ) . '$/u';
		return (bool) preg_match( $regex, $text );
	}
}
