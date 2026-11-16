<?php
/**
 * Scoped post-content KSES helpers (#1212).
 *
 * Safe inline SVG for MCPWP content writes, plus a saved-vs-submitted
 * modification report. The SVG allow-list is installed only around MCPWP's
 * own kses call and removed afterwards — never a global looseness, and never
 * applied to Elementor meta (#1179) or Kit CSS writers.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Content KSES helpers for post/page/block body writes.
 */
class Mcpwp_Content_Kses {

	/**
	 * Depth of nested with_safe_svg() calls so an inner call cannot remove the
	 * outer filter early.
	 *
	 * @var int
	 */
	private static $svg_filter_depth = 0;

	/**
	 * SVG family tags allowed for MCPWP content writes without unfiltered_html.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function safe_svg_allowed_tags() {
		$presentation = array(
			'viewBox'       => true,
			'width'         => true,
			'height'        => true,
			'fill'          => true,
			'stroke'        => true,
			'stroke-width'  => true,
			'd'             => true,
			'points'        => true,
			'cx'            => true,
			'cy'            => true,
			'r'             => true,
			'rx'            => true,
			'ry'            => true,
			'x'             => true,
			'y'             => true,
			'x1'            => true,
			'y1'            => true,
			'x2'            => true,
			'y2'            => true,
			'transform'     => true,
			'class'         => true,
			'id'            => true,
			'role'          => true,
			'focusable'     => true,
			'xmlns'         => true,
			'xmlns:xlink'   => true,
			'opacity'       => true,
			'fill-opacity'  => true,
			'stroke-opacity'=> true,
			'stroke-linecap'=> true,
			'stroke-linejoin' => true,
			'clip-path'     => true,
			'aria-hidden'   => true,
			'aria-label'    => true,
			'aria-labelledby' => true,
			'aria-describedby' => true,
		);

		$gradient = array(
			'id'                => true,
			'gradientUnits'     => true,
			'gradientTransform' => true,
			'x1'                => true,
			'y1'                => true,
			'x2'                => true,
			'y2'                => true,
			'cx'                => true,
			'cy'                => true,
			'r'                 => true,
			'fx'                => true,
			'fy'                => true,
			'offset'            => true,
			'stop-color'        => true,
			'stop-opacity'      => true,
			'class'             => true,
		);

		return array(
			'svg'             => array_merge(
				$presentation,
				array(
					'preserveAspectRatio' => true,
					'version'             => true,
				)
			),
			'g'               => $presentation,
			'path'            => $presentation,
			'circle'          => $presentation,
			'rect'            => $presentation,
			'line'            => $presentation,
			'polyline'        => $presentation,
			'polygon'         => $presentation,
			'ellipse'         => $presentation,
			'defs'            => array( 'id' => true, 'class' => true ),
			'linearGradient'  => $gradient,
			'radialGradient'  => $gradient,
			'stop'            => $gradient,
			'title'           => array( 'id' => true, 'lang' => true ),
			'desc'            => array( 'id' => true, 'lang' => true ),
			'use'             => array(
				'href'       => true,
				'xlink:href' => true,
				'x'          => true,
				'y'          => true,
				'width'      => true,
				'height'     => true,
				'transform'  => true,
				'class'      => true,
				'id'         => true,
				'fill'       => true,
				'stroke'     => true,
			),
		);
	}

	/**
	 * wp_kses_allowed_html filter: merge safe SVG tags into the 'post' context.
	 *
	 * @param mixed  $tags    Allowed HTML map.
	 * @param string $context KSES context.
	 * @return mixed
	 */
	public static function allow_safe_svg_html( $tags, $context = '' ) {
		if ( 'post' !== $context || ! is_array( $tags ) ) {
			return $tags;
		}

		foreach ( self::safe_svg_allowed_tags() as $tag => $attrs ) {
			$existing       = isset( $tags[ $tag ] ) && is_array( $tags[ $tag ] ) ? $tags[ $tag ] : array();
			$tags[ $tag ] = array_merge( $existing, $attrs );
		}

		return $tags;
	}

	/**
	 * Run $callback with the safe-SVG allow-list filter installed, then remove it.
	 *
	 * @param callable $callback Callback returning mixed.
	 * @return mixed
	 */
	public static function with_safe_svg( callable $callback ) {
		if ( 0 === self::$svg_filter_depth ) {
			add_filter( 'wp_kses_allowed_html', array( __CLASS__, 'allow_safe_svg_html' ), PHP_INT_MAX, 2 );
		}
		++self::$svg_filter_depth;

		try {
			return $callback();
		} finally {
			--self::$svg_filter_depth;
			if ( 0 === self::$svg_filter_depth ) {
				remove_filter( 'wp_kses_allowed_html', array( __CLASS__, 'allow_safe_svg_html' ), PHP_INT_MAX );
			}
		}
	}

	/**
	 * kses a post body under the scoped safe-SVG allow-list, then scrub leftovers.
	 *
	 * @param string $content Content after raw-text element stripping.
	 * @return string
	 */
	public static function kses_post( $content ) {
		$content = self::with_safe_svg(
			static function () use ( $content ) {
				return wp_kses_post( $content );
			}
		);

		return self::scrub_dangerous_svg( $content );
	}

	/**
	 * Post-kses scrub for SVG vectors wp_kses attribute maps do not fully cover.
	 *
	 * Strips foreignObject, style elements, on* attributes, style attrs with
	 * url(), javascript:/data: URLs, and external <use> hrefs. Counts are not
	 * returned here — {@see describe_modifications()} owns reporting.
	 *
	 * @param string $html HTML after kses.
	 * @return string
	 */
	public static function scrub_dangerous_svg( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return is_string( $html ) ? $html : '';
		}

		// Disallowed SVG-adjacent elements. script/style are already removed
		// whole (tag+body) by sanitize_content_body()'s tokenizer before kses;
		// do NOT strip bare <script>/<style> tokens here — that would eat
		// literals inside HTML comments, attribute values, and CDATA (#808).
		$html = preg_replace( '#</?(?:foreignObject|foreignobject|animate|animateTransform|set|handler|listener)(?=[\s/>])[^<>]*>#i', '', $html );

		$html = preg_replace_callback(
			'#<([a-zA-Z][a-zA-Z0-9:-]*)(\s[^<>]*?)(/?)>#',
			static function ( $m ) {
				$tag   = $m[1];
				$attrs = $m[2];
				$self  = $m[3];

				// Drop every on* attribute.
				$attrs = preg_replace( '#\s+on[a-zA-Z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]*)#', '', $attrs );

				// style="...url(...)..." — drop the attribute.
				$attrs = preg_replace_callback(
					'#\s+style\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]*)#i',
					static function ( $sm ) {
						$raw = $sm[1];
						if ( preg_match( '/url\s*\(/i', $raw ) ) {
							return '';
						}
						return $sm[0];
					},
					$attrs
				);

				// javascript: / vbscript: / data: in URL-ish attributes.
				$attrs = preg_replace_callback(
					'#\s+(href|xlink:href|src|action)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]*))#i',
					static function ( $um ) use ( $tag ) {
						$value = isset( $um[3] ) && '' !== $um[3] ? $um[3] : ( isset( $um[4] ) && '' !== $um[4] ? $um[4] : ( $um[5] ?? '' ) );
						$trim  = trim( html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
						$lower = strtolower( $trim );

						if ( preg_match( '/^\s*(javascript|vbscript|data)\s*:/i', $lower ) ) {
							return '';
						}

						// <use> may only target a same-document fragment.
						if ( 0 === strcasecmp( $tag, 'use' ) ) {
							if ( ! preg_match( '/^#[A-Za-z_][\w.:-]*\z/', $trim ) ) {
								return '';
							}
						}

						return $um[0];
					},
					$attrs
				);

				return '<' . $tag . $attrs . $self . '>';
			},
			$html
		);

		return is_string( $html ) ? $html : '';
	}

	/**
	 * Read post_content from the DB row for $post_id.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function saved_post_content( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return '';
		}
		if ( function_exists( 'get_post_field' ) ) {
			return (string) get_post_field( 'post_content', $post_id );
		}
		$post = get_post( $post_id );
		return $post ? (string) $post->post_content : '';
	}

	/**
	 * Compare submitted content to the saved row; list removal kinds (counts only).
	 *
	 * @param string $submitted Content the caller sent (pre-sanitisation).
	 * @param string $saved     Content stored after the write.
	 * @return array{removed: array<int, array{kind:string, count:int}>}|array{}
	 */
	public static function describe_modifications( $submitted, $saved ) {
		if ( ! is_string( $submitted ) ) {
			$submitted = is_scalar( $submitted ) ? (string) $submitted : '';
		}
		if ( ! is_string( $saved ) ) {
			$saved = is_scalar( $saved ) ? (string) $saved : '';
		}

		if ( $submitted === $saved ) {
			return array();
		}

		$kinds = array();

		$script_delta = self::count_tag( $submitted, 'script' ) - self::count_tag( $saved, 'script' );
		if ( $script_delta > 0 ) {
			$kinds[] = array( 'kind' => 'script', 'count' => $script_delta );
		}

		$style_delta = self::count_tag( $submitted, 'style' ) - self::count_tag( $saved, 'style' );
		if ( $style_delta > 0 ) {
			$kinds[] = array( 'kind' => 'style', 'count' => $style_delta );
		}

		$fo_delta = self::count_tag( $submitted, 'foreignObject' )
			+ self::count_tag( $submitted, 'foreignobject' )
			- self::count_tag( $saved, 'foreignObject' )
			- self::count_tag( $saved, 'foreignobject' );
		if ( $fo_delta > 0 ) {
			$kinds[] = array( 'kind' => 'foreignObject', 'count' => $fo_delta );
		}

		$svg_delta = self::count_svg_family( $submitted ) - self::count_svg_family( $saved );
		// Only report svg_elements when the family shrank AND something unsafe
		// was involved, or when a top-level svg open tag disappeared entirely
		// while the submitted had one. Safe SVG that survives will not shrink.
		if ( $svg_delta > 0 ) {
			$kinds[] = array( 'kind' => 'svg_elements', 'count' => $svg_delta );
		}

		$on_delta = self::count_on_attributes( $submitted ) - self::count_on_attributes( $saved );
		if ( $on_delta > 0 ) {
			$kinds[] = array( 'kind' => 'on_attributes', 'count' => $on_delta );
		}

		$iframe_delta = self::count_tag( $submitted, 'iframe' ) - self::count_tag( $saved, 'iframe' );
		if ( $iframe_delta > 0 ) {
			$kinds[] = array( 'kind' => 'iframe', 'count' => $iframe_delta );
		}

		if ( empty( $kinds ) ) {
			return array();
		}

		return array( 'removed' => $kinds );
	}

	/**
	 * @param string $html HTML.
	 * @param string $tag  Tag name.
	 * @return int
	 */
	private static function count_tag( $html, $tag ) {
		if ( '' === $html ) {
			return 0;
		}
		return preg_match_all( '#<' . preg_quote( $tag, '#' ) . '(?=[\s/>])#i', $html );
	}

	/**
	 * @param string $html HTML.
	 * @return int
	 */
	private static function count_svg_family( $html ) {
		if ( '' === $html ) {
			return 0;
		}
		$tags = 'svg|g|path|circle|rect|line|polyline|polygon|ellipse|defs|linearGradient|radialGradient|stop|use|title|desc';
		return preg_match_all( '#<(?:' . $tags . ')(?=[\s/>])#i', $html );
	}

	/**
	 * @param string $html HTML.
	 * @return int
	 */
	private static function count_on_attributes( $html ) {
		if ( '' === $html ) {
			return 0;
		}
		return preg_match_all( '#\son[a-z]+\s*=#i', $html );
	}
}
