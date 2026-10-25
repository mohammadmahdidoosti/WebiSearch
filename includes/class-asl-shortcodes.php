<?php
/**
 * Front-end "popular searches" shortcode.
 *
 * [webisearch_popular limit="8" days="30" title="" style="tags|list" post_type="product" min="2"]
 *
 * Only searches that returned results are shown, so visitors are never sent to an empty page.
 * Works anywhere shortcodes do (Elementor/WoodMart HTML blocks, widgets, Gutenberg shortcode block).
 *
 * @package WebiSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASL_Shortcodes {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_shortcode( 'webisearch_popular', array( $this, 'popular' ) );
	}

	/**
	 * Render.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public function popular( $atts ) {
		$a = shortcode_atts(
			array(
				'limit'     => 8,
				'days'      => 30,
				'title'     => '',
				'style'     => 'tags',
				'post_type' => class_exists( 'WooCommerce' ) ? 'product' : '',
				'min'       => 2,
				'exclude'   => '',
			),
			$atts,
			'webisearch_popular'
		);

		$limit = min( 50, max( 1, (int) $a['limit'] ) );
		$days  = min( 365, max( 1, (int) $a['days'] ) );
		$key   = 'asl_pop_' . md5( $limit . '|' . $days . '|' . (int) $a['min'] . '|' . $a['exclude'] . '|' . get_option( 'asl_cache_ver', '' ) );
		$rows  = get_transient( $key );
		if ( ! is_array( $rows ) ) {
			$rows    = ASL_DB::popular_terms( $days, $limit + 20, max( 1, (int) $a['min'] ) );
			$exclude = array_filter( array_map( array( 'ASL_Normalizer', 'normalize' ), explode( ',', (string) $a['exclude'] ) ) );
			$rows    = array_values(
				array_filter(
					$rows,
					function ( $r ) use ( $exclude ) {
						return ! in_array( $r['term'], $exclude, true );
					}
				)
			);
			$rows    = array_slice( $rows, 0, $limit );
			set_transient( $key, $rows, HOUR_IN_SECONDS );
		}
		if ( ! $rows ) {
			return '';
		}

		$style = 'list' === $a['style'] ? 'list' : 'tags';
		$html  = '<div class="asl-popular asl-popular--' . esc_attr( $style ) . '">';
		if ( '' !== $a['title'] ) {
			$html .= '<span class="asl-popular__title">' . esc_html( $a['title'] ) . '</span> ';
		}
		$html .= 'list' === $style ? '<ol>' : '';
		foreach ( $rows as $r ) {
			$args = array( 's' => $r['sample'] );
			if ( $a['post_type'] ) {
				$args['post_type'] = sanitize_key( $a['post_type'] );
			}
			$link  = '<a href="' . esc_url( add_query_arg( array_map( 'rawurlencode', $args ), home_url( '/' ) ) ) . '" rel="nofollow">' . esc_html( $r['sample'] ) . '</a>';
			$html .= 'list' === $style ? '<li>' . $link . '</li>' : $link . ' ';
		}
		$html .= 'list' === $style ? '</ol>' : '';
		$html .= '</div>';

		if ( 'tags' === $style ) {
			$html .= '<style>.asl-popular--tags a{display:inline-block;margin:0 0 6px 6px;padding:3px 12px;border:1px solid rgba(0,0,0,.12);border-radius:999px;font-size:.9em;text-decoration:none}.asl-popular--tags a:hover{border-color:currentColor}</style>';
		}
		return $html;
	}
}
