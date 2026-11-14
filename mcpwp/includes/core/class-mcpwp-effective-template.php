<?php
/**
 * Which templates a page uses, assigned and rendered (#989).
 *
 * #804 recorded a header template published with an "include/general"
 * condition while the theme's own header kept rendering. Nothing in the plugin
 * could say which template a URL actually used: wp_set_template_conditions
 * reports what was assigned, not what rendered. This class answers both:
 *
 * - assigned_for_post(): the page template stored on the post, and the Theme
 *   Builder templates Elementor Pro's display conditions pick for that post.
 * - rendered_from_html(): what a full rendered document shows: the page
 *   template in the body class, every Elementor document in it (with its
 *   location), and theme header and footer markup outside Elementor.
 *
 * compare() reports where the two disagree: an assigned header that did not
 * render, a theme header that rendered beside an Elementor one.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mcpwp_Effective_Template {

	/**
	 * Labels for page templates Elementor ships.
	 *
	 * @var array<string,string>
	 */
	private static $known_page_templates = array(
		'default'                 => 'Theme default template',
		'elementor_canvas'        => 'Elementor Canvas (no theme header or footer)',
		'elementor_header_footer' => 'Elementor Full Width (theme header and footer, full-width content)',
		'elementor_theme'         => 'Elementor Theme template',
	);

	/**
	 * XPath tests for theme header and footer markup. Each is tested only
	 * outside Elementor documents, so an Elementor header that reuses one of
	 * these ids or classes is not counted as theme chrome.
	 *
	 * @var array<string,array<string,string>>
	 */
	private static $theme_chrome = array(
		'header' => array(
			'#masthead'                 => '@id="masthead"',
			'#main-header'              => '@id="main-header"',
			'#site-header'              => '@id="site-header"',
			'header#header'             => 'self::header and @id="header"',
			'.site-header'              => 'contains(concat(" ", normalize-space(@class), " "), " site-header ")',
			'.et-l--header'             => 'contains(concat(" ", normalize-space(@class), " "), " et-l--header ")',
			'header.wp-block-template-part' => 'self::header and contains(concat(" ", normalize-space(@class), " "), " wp-block-template-part ")',
		),
		'footer' => array(
			'#colophon'                 => '@id="colophon"',
			'#main-footer'              => '@id="main-footer"',
			'#site-footer'              => '@id="site-footer"',
			'footer#footer'             => 'self::footer and @id="footer"',
			'.site-footer'              => 'contains(concat(" ", normalize-space(@class), " "), " site-footer ")',
			'.et-l--footer'             => 'contains(concat(" ", normalize-space(@class), " "), " et-l--footer ")',
			'footer.wp-block-template-part' => 'self::footer and contains(concat(" ", normalize-space(@class), " "), " wp-block-template-part ")',
		),
	);

	/**
	 * The templates assigned to one post.
	 *
	 * Theme Builder evaluation runs Elementor Pro's own condition matching with
	 * the global query pointed at this post, then restores the query and clears
	 * Elementor Pro's per-request location cache before and after, so neither
	 * this call nor a later one in the same request sees the other's answer.
	 *
	 * @param WP_Post $post Post.
	 * @return array{page_template:string,page_template_label:string|null,theme_builder:array}
	 */
	public static function assigned_for_post( $post ) {
		$slug = '';
		if ( function_exists( 'get_page_template_slug' ) ) {
			$slug = (string) get_page_template_slug( $post );
		} else {
			$slug = (string) get_post_meta( (int) $post->ID, '_wp_page_template', true );
		}
		if ( '' === $slug ) {
			$slug = 'default';
		}

		return array(
			'page_template'       => $slug,
			'page_template_label' => self::$known_page_templates[ $slug ] ?? null,
			'theme_builder'       => self::theme_builder_for_post( $post ),
		);
	}

	/**
	 * Theme Builder templates Elementor Pro's conditions pick for a post.
	 *
	 * @param WP_Post $post Post.
	 * @return array{evaluated:bool,locations?:array<string,int[]>,reason?:string}
	 */
	private static function theme_builder_for_post( $post ) {
		if ( ! class_exists( '\ElementorPro\Modules\ThemeBuilder\Module' ) ) {
			return array(
				'evaluated' => false,
				'reason'    => 'elementor_pro_theme_builder_unavailable',
			);
		}

		try {
			$module = \ElementorPro\Modules\ThemeBuilder\Module::instance();
			$conditions = $module->get_conditions_manager();
			$locations  = $module->get_locations_manager();
		} catch ( \Throwable $e ) {
			return array(
				'evaluated' => false,
				'reason'    => 'elementor_pro_theme_builder_unavailable',
			);
		}

		// Without clear_location_cache() the answer for this post would stay
		// cached for every later lookup in this request.
		if ( ! is_object( $conditions ) || ! is_object( $locations )
			|| ! method_exists( $conditions, 'get_documents_for_location' )
			|| ! method_exists( $conditions, 'clear_location_cache' )
			|| ! method_exists( $locations, 'get_locations' ) ) {
			return array(
				'evaluated' => false,
				'reason'    => 'unsupported_elementor_pro_version',
			);
		}

		$saved = array();
		foreach ( array( 'wp_query', 'wp_the_query', 'post' ) as $global ) {
			$saved[ $global ] = array_key_exists( $global, $GLOBALS ) ? array( $GLOBALS[ $global ] ) : null;
		}

		$result = array(
			'evaluated' => true,
			'locations' => array(),
		);

		try {
			$query_args = array(
				'post_type'   => $post->post_type,
				// The post's own status: with "any", WP_Query empties a
				// single non-public result for a logged-out request.
				'post_status' => $post->post_status,
			);
			if ( 'page' === $post->post_type ) {
				$query_args['page_id'] = (int) $post->ID;
			} else {
				$query_args['p'] = (int) $post->ID;
			}

			$query = new WP_Query( $query_args );

			// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- restored in finally.
			$GLOBALS['wp_query']     = $query;
			$GLOBALS['wp_the_query'] = $query;
			$GLOBALS['post']         = $post;
			// phpcs:enable

			$conditions->clear_location_cache();

			foreach ( array_keys( (array) $locations->get_locations() ) as $location ) {
				$documents = $conditions->get_documents_for_location( $location );
				$ids       = array_values( array_filter( array_map( 'intval', array_keys( (array) $documents ) ) ) );
				if ( ! empty( $ids ) ) {
					$result['locations'][ (string) $location ] = $ids;
				}
			}
		} catch ( \Throwable $e ) {
			$result = array(
				'evaluated' => false,
				'reason'    => 'evaluation_failed',
				'error'     => get_class( $e ) . ': ' . $e->getMessage(),
			);
		} finally {
			foreach ( $saved as $global => $value ) {
				if ( null === $value ) {
					unset( $GLOBALS[ $global ] );
				} else {
					// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
					$GLOBALS[ $global ] = $value[0];
				}
			}

			try {
				$conditions->clear_location_cache();
			} catch ( \Throwable $e ) {
				// Nothing to do: the lookup above already reported its result.
				unset( $e );
			}
		}

		return $result;
	}

	/**
	 * What a full rendered document shows about its templates.
	 *
	 * @param string $html Full HTML document.
	 * @return array|null Null when $html is not a full document (no <body>).
	 */
	public static function rendered_from_html( $html ) {
		$html = (string) $html;
		if ( '' === $html || false === stripos( $html, '<body' ) || ! class_exists( 'Mcpwp_Render_Inspector' ) ) {
			return null;
		}

		$doc = Mcpwp_Render_Inspector::load_dom( $html );
		if ( null === $doc ) {
			return null;
		}

		$xpath = new DOMXPath( $doc );

		$body_classes = array();
		$body         = $xpath->query( '//body' );
		if ( $body->length > 0 ) {
			$body_classes = preg_split( '/\s+/', trim( (string) $body->item( 0 )->getAttribute( 'class' ) ) );
		}

		$documents = array();
		$locations = array();
		foreach ( $xpath->query( '//*[@data-elementor-type and @data-elementor-id]' ) as $node ) {
			$location = '';
			if ( preg_match( '/(?:^|\s)elementor-location-([a-z0-9_-]+)(?:\s|$)/i', (string) $node->getAttribute( 'class' ), $m ) ) {
				$location = strtolower( $m[1] );
			}

			$entry = array(
				'elementor_type' => (string) $node->getAttribute( 'data-elementor-type' ),
				'id'             => (int) $node->getAttribute( 'data-elementor-id' ),
				'location'       => '' === $location ? null : $location,
			);

			$documents[] = $entry;

			if ( '' !== $location ) {
				$locations[ $location ][] = $entry['id'];
			}
		}

		$chrome = array();
		foreach ( self::$theme_chrome as $area => $tests ) {
			$chrome[ $area ] = array();
			foreach ( $tests as $label => $test ) {
				$query = '//*[(' . $test . ') and not(ancestor-or-self::*[@data-elementor-type])]';
				$found = $xpath->query( $query );
				if ( $found && $found->length > 0 ) {
					$chrome[ $area ][] = $label;
				}
			}
		}

		$rendered = array(
			'source'              => 'rendered_html',
			'page_template'       => self::page_template_from_body_classes( $body_classes ),
			'elementor_documents' => array_slice( $documents, 0, 50 ),
			'locations'           => $locations,
			'theme_chrome'        => $chrome,
			'warnings'            => array(),
		);

		foreach ( array( 'header', 'footer' ) as $area ) {
			$elementor_ids = isset( $locations[ $area ] ) ? array_values( array_unique( $locations[ $area ] ) ) : array();

			if ( ! empty( $elementor_ids ) && ! empty( $chrome[ $area ] ) ) {
				$rendered['warnings'][] = sprintf(
					'Elementor %1$s template %2$s rendered, and so did the theme\'s own %1$s (%3$s). The page shows two %1$ss.',
					$area,
					implode( ', ', $elementor_ids ),
					implode( ', ', $chrome[ $area ] )
				);
			}

			if ( count( $elementor_ids ) > 1 ) {
				$rendered['warnings'][] = sprintf(
					'More than one Elementor %1$s template rendered (%2$s).',
					$area,
					implode( ', ', $elementor_ids )
				);
			}
		}

		return $rendered;
	}

	/**
	 * Read the page template from WordPress's body classes.
	 *
	 * WordPress adds `page-template-{slug}` (pages) or `{post_type}-template-{slug}`
	 * for the template a request used; Elementor adds `elementor-template-canvas`
	 * and `elementor-template-full-width` for its own two.
	 *
	 * @param string[] $classes Body classes.
	 * @return string|null Template slug, "default", or null when not shown.
	 */
	private static function page_template_from_body_classes( array $classes ) {
		if ( in_array( 'elementor-template-canvas', $classes, true ) ) {
			return 'elementor_canvas';
		}
		if ( in_array( 'elementor-template-full-width', $classes, true ) ) {
			return 'elementor_header_footer';
		}

		foreach ( $classes as $class ) {
			// WordPress always adds the bare "{prefix}-template" class beside
			// "{prefix}-template-{slug}". Requiring both keeps a theme class
			// that merely contains "-template-" from being read as one.
			if ( preg_match( '/^([a-z0-9_]+)-template-([a-z0-9_-]+)$/', $class, $m )
				&& 'elementor' !== $m[1]
				&& in_array( $m[1] . '-template', $classes, true ) ) {
				// WordPress replaces "." and "/" with "-" in this class, so a
				// file-based template reads "templates-full-php".
				return $m[2];
			}
		}

		return null;
	}

	/**
	 * Where assigned and rendered templates disagree.
	 *
	 * @param array|null $assigned assigned_for_post() result.
	 * @param array|null $rendered rendered_from_html() result.
	 * @return string[]
	 */
	public static function compare( $assigned, $rendered ) {
		if ( ! is_array( $assigned ) || ! is_array( $rendered ) ) {
			return array();
		}

		$warnings = array();

		$tb = isset( $assigned['theme_builder'] ) && is_array( $assigned['theme_builder'] ) ? $assigned['theme_builder'] : array();
		if ( ! empty( $tb['evaluated'] ) && ! empty( $tb['locations'] ) ) {
			foreach ( $tb['locations'] as $location => $ids ) {
				$rendered_ids = isset( $rendered['locations'][ $location ] ) ? array_map( 'intval', $rendered['locations'][ $location ] ) : array();
				$missing      = array_values( array_diff( array_map( 'intval', (array) $ids ), $rendered_ids ) );

				if ( empty( $missing ) ) {
					continue;
				}

				$instead = '';
				if ( ! empty( $rendered_ids ) ) {
					$instead = ' Template ' . implode( ', ', $rendered_ids ) . ' rendered there instead.';
				} elseif ( ! empty( $rendered['theme_chrome'][ $location ] ) ) {
					$instead = ' The theme\'s own ' . $location . ' (' . implode( ', ', $rendered['theme_chrome'][ $location ] ) . ') rendered instead.';
				}

				$warnings[] = sprintf(
					'Theme Builder %1$s template %2$s is assigned to this page by its display conditions but did not render.%3$s The theme may not support Elementor locations, or a cached copy of the page was served.',
					$location,
					implode( ', ', $missing ),
					$instead
				);
			}
		}

		$assigned_template = isset( $assigned['page_template'] ) ? (string) $assigned['page_template'] : '';
		$rendered_template = isset( $rendered['page_template'] ) ? $rendered['page_template'] : null;
		if ( null !== $rendered_template && '' !== $assigned_template && self::template_class_slug( $assigned_template ) !== $rendered_template ) {
			$warnings[] = sprintf(
				'The post is set to page template "%1$s", but the page rendered with "%2$s".',
				$assigned_template,
				$rendered_template
			);
		}

		return $warnings;
	}

	/**
	 * A template slug as WordPress writes it into the body class.
	 *
	 * @param string $slug Template slug.
	 * @return string
	 */
	private static function template_class_slug( $slug ) {
		return strtolower( str_replace( array( '.', '/' ), '-', (string) $slug ) );
	}
}
