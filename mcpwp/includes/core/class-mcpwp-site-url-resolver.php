<?php
/**
 * Resolves this site's own non-post URLs for wp_fetch (#989).
 *
 * wp_fetch says it takes "a public URL", but it only resolved posts and pages:
 * `/category/blog/` and every `/wp-json/` URL came back "Content not found"
 * (#804). This class works out what such a URL is, without running the
 * request:
 *
 * - rest_route(): the REST route a `/wp-json/...` or `?rest_route=` URL names.
 * - query_vars(): the public query vars WordPress would read from the URL,
 *   matching the rewrite rules the same way WP::parse_request() does, but
 *   without firing parse_request (plugins exit from that hook).
 * - classify(): which archive those vars name (category, tag, custom taxonomy,
 *   author, date, post type archive, search, blog index), or null.
 * - listing_args(): the WP_Query arguments for the archive's posts. They are
 *   built here from the classified archive alone, so no query var from the
 *   URL reaches WP_Query directly, and only published, unprotected posts are
 *   ever listed.
 *
 * Callers check that the URL is on this site's own origin first.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mcpwp_Site_Url_Resolver {

	/**
	 * Core's public query vars (WP::$public_query_vars), used when the global
	 * WP object is not available.
	 *
	 * @var string[]
	 */
	private static $core_public_query_vars = array(
		'm', 'p', 'posts', 'w', 'cat', 'withcomments', 'withoutcomments', 's', 'search', 'exact', 'sentence',
		'calendar', 'page', 'paged', 'more', 'tb', 'pb', 'author', 'order', 'orderby', 'year', 'monthnum', 'day',
		'hour', 'minute', 'second', 'name', 'category_name', 'tag', 'feed', 'author_name', 'pagename', 'page_id',
		'error', 'attachment', 'attachment_id', 'subpost', 'subpost_id', 'preview', 'robots', 'favicon',
		'taxonomy', 'term', 'cpage', 'post_type', 'embed', 'sitemap', 'sitemap-subtype', 'sitemap-stylesheet',
	);

	/**
	 * Vars that make a request a single post, or not a page listing at all.
	 *
	 * @var string[]
	 */
	private static $non_archive_vars = array(
		'p', 'page_id', 'name', 'pagename', 'attachment', 'attachment_id', 'subpost', 'subpost_id', 'preview',
		'feed', 'embed', 'robots', 'favicon', 'error', 'tb', 'sitemap', 'sitemap-subtype', 'sitemap-stylesheet',
	);

	/**
	 * Most posts one archive response lists.
	 */
	const MAX_PER_PAGE = 50;

	/**
	 * The path of a URL relative to the site's home, and its query args.
	 *
	 * @param string $url Absolute URL on this site.
	 * @return array{path:string,query:array}|null
	 */
	public static function request_path( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $parts ) ) {
			return null;
		}

		$path = isset( $parts['path'] ) ? rawurldecode( (string) $parts['path'] ) : '';

		$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home_path = trim( $home_path, '/' );
		$path      = trim( $path, '/' );

		if ( '' !== $home_path ) {
			if ( $path === $home_path ) {
				$path = '';
			} elseif ( 0 === strpos( $path, $home_path . '/' ) ) {
				$path = substr( $path, strlen( $home_path ) + 1 );
			}
		}

		// A site without pretty permalinks serves everything through index.php.
		if ( 'index.php' === $path ) {
			$path = '';
		} elseif ( 0 === strpos( $path, 'index.php/' ) ) {
			$path = substr( $path, strlen( 'index.php/' ) );
		}

		$query = array();
		if ( ! empty( $parts['query'] ) ) {
			parse_str( (string) $parts['query'], $query );
		}

		return array(
			'path'  => $path,
			'query' => is_array( $query ) ? $query : array(),
		);
	}

	/**
	 * The REST route a URL names, or null when it is not a REST URL.
	 *
	 * @param string $url Absolute URL on this site.
	 * @return array{route:string,query:array}|null
	 */
	public static function rest_route( $url ) {
		$request = self::request_path( $url );
		if ( null === $request ) {
			return null;
		}

		$query = $request['query'];

		if ( isset( $query['rest_route'] ) && is_string( $query['rest_route'] ) ) {
			$route = $query['rest_route'];
			unset( $query['rest_route'] );

			return array(
				'route' => self::normalise_route( $route ),
				'query' => $query,
			);
		}

		$prefix = function_exists( 'rest_get_url_prefix' ) ? trim( (string) rest_get_url_prefix(), '/' ) : 'wp-json';
		if ( '' === $prefix ) {
			return null;
		}

		$path = $request['path'];
		if ( $path === $prefix ) {
			return array(
				'route' => '/',
				'query' => $query,
			);
		}

		if ( 0 === strpos( $path, $prefix . '/' ) ) {
			return array(
				'route' => self::normalise_route( substr( $path, strlen( $prefix ) ) ),
				'query' => $query,
			);
		}

		return null;
	}

	/**
	 * A route with exactly one leading slash and no trailing one.
	 *
	 * @param string $route Route.
	 * @return string
	 */
	private static function normalise_route( $route ) {
		$route = '/' . trim( (string) $route, '/' );

		return '/' === $route ? '/' : rtrim( $route, '/' );
	}

	/**
	 * The public query vars WordPress would read from a URL.
	 *
	 * @param string $url Absolute URL on this site.
	 * @return array{path:string,vars:array}|null
	 */
	public static function query_vars( $url ) {
		$request = self::request_path( $url );
		if ( null === $request ) {
			return null;
		}

		$vars = array();

		if ( '' !== $request['path'] && isset( $GLOBALS['wp_rewrite'] ) && is_object( $GLOBALS['wp_rewrite'] )
			&& method_exists( $GLOBALS['wp_rewrite'], 'wp_rewrite_rules' ) ) {
			$rules   = (array) $GLOBALS['wp_rewrite']->wp_rewrite_rules();
			$verbose = ! empty( $GLOBALS['wp_rewrite']->use_verbose_page_rules );
			$vars    = self::match_rewrite( $request['path'], $rules, $verbose );
		}

		// Query-string vars win over the path, as in WP::parse_request().
		foreach ( $request['query'] as $key => $value ) {
			if ( is_string( $value ) || is_numeric( $value ) ) {
				$vars[ (string) $key ] = (string) $value;
			}
		}

		$public = self::public_query_vars();

		return array(
			'path' => $request['path'],
			'vars' => array_intersect_key( $vars, array_flip( $public ) ),
		);
	}

	/**
	 * Match a path against rewrite rules, as WP::parse_request() does.
	 *
	 * @param string $path    Path relative to home, no surrounding slashes.
	 * @param array  $rules   Rewrite rules, regex => query.
	 * @param bool   $verbose Whether the site uses verbose page rules.
	 * @return array Query vars from the first matching rule.
	 */
	public static function match_rewrite( $path, array $rules, $verbose = false ) {
		foreach ( $rules as $match => $query ) {
			$matches = array();
			$regex   = '#^' . $match . '#';

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a third-party rule may not be a valid regex.
			if ( ! @preg_match( $regex, $path, $matches ) && ! @preg_match( $regex, urldecode( $path ), $matches ) ) {
				continue;
			}

			// A verbose page rule catches every path; WordPress only takes it
			// when a page exists at that path.
			if ( $verbose && preg_match( '/pagename=\$matches\[([0-9]+)\]/', (string) $query, $page_var ) ) {
				$page_path = isset( $matches[ (int) $page_var[1] ] ) ? $matches[ (int) $page_var[1] ] : '';
				if ( '' === $page_path || ! function_exists( 'get_page_by_path' ) || ! get_page_by_path( $page_path ) ) {
					continue;
				}
			}

			$query = preg_replace( '!^.+\?!', '', (string) $query );
			$query = preg_replace_callback(
				'/\$matches\[([0-9]+)\]/',
				static function ( $m ) use ( $matches ) {
					return isset( $matches[ (int) $m[1] ] ) ? rawurlencode( $matches[ (int) $m[1] ] ) : '';
				},
				$query
			);

			$vars = array();
			parse_str( (string) $query, $vars );

			return is_array( $vars ) ? $vars : array();
		}

		return array();
	}

	/**
	 * Public query vars, including those taxonomies and post types registered.
	 *
	 * @return string[]
	 */
	private static function public_query_vars() {
		$vars = self::$core_public_query_vars;

		if ( isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && isset( $GLOBALS['wp']->public_query_vars ) && is_array( $GLOBALS['wp']->public_query_vars ) ) {
			$vars = array_merge( $vars, $GLOBALS['wp']->public_query_vars );
		}

		foreach ( self::queryable_taxonomies() as $taxonomy ) {
			if ( ! empty( $taxonomy->query_var ) && is_string( $taxonomy->query_var ) ) {
				$vars[] = $taxonomy->query_var;
			}
		}

		$vars = apply_filters( 'query_vars', $vars );

		return array_values( array_unique( array_map( 'strval', (array) $vars ) ) );
	}

	/**
	 * Taxonomies a visitor can browse.
	 *
	 * @return object[] name => taxonomy object
	 */
	private static function queryable_taxonomies() {
		if ( ! function_exists( 'get_taxonomies' ) ) {
			return array();
		}

		$taxonomies = get_taxonomies( array( 'publicly_queryable' => true ), 'objects' );

		return is_array( $taxonomies ) ? $taxonomies : array();
	}

	/**
	 * Whether a visitor can browse a taxonomy's archives.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return object|null Taxonomy object.
	 */
	private static function queryable_taxonomy( $taxonomy ) {
		$object = function_exists( 'get_taxonomy' ) ? get_taxonomy( (string) $taxonomy ) : false;
		if ( ! is_object( $object ) || empty( $object->publicly_queryable ) ) {
			return null;
		}

		return $object;
	}

	/**
	 * Which archive a set of query vars names.
	 *
	 * @param array  $vars Public query vars.
	 * @param string $path Path relative to home.
	 * @return array|null Archive descriptor, or null when the vars do not name
	 *                    a browsable archive (a single post, a feed, a term
	 *                    that does not exist, ...).
	 */
	public static function classify( array $vars, $path ) {
		foreach ( self::$non_archive_vars as $key ) {
			if ( isset( $vars[ $key ] ) && '' !== (string) $vars[ $key ] ) {
				return null;
			}
		}

		$paged = 1;
		foreach ( array( 'paged', 'page' ) as $key ) {
			if ( isset( $vars[ $key ] ) && (int) $vars[ $key ] > 1 ) {
				$paged = (int) $vars[ $key ];
				break;
			}
		}

		$base = array( 'paged' => $paged );

		// A singular post type query var (?product=slug, /product/slug/).
		if ( function_exists( 'get_post_types' ) ) {
			foreach ( (array) get_post_types( array( 'publicly_queryable' => true ), 'objects' ) as $type ) {
				if ( is_object( $type ) && ! empty( $type->query_var ) && is_string( $type->query_var )
					&& isset( $vars[ $type->query_var ] ) && '' !== (string) $vars[ $type->query_var ] ) {
					return null;
				}
			}
		}

		$search = '';
		foreach ( array( 's', 'search' ) as $key ) {
			if ( isset( $vars[ $key ] ) && '' !== trim( (string) $vars[ $key ] ) ) {
				$search = trim( (string) $vars[ $key ] );
				break;
			}
		}
		if ( '' !== $search ) {
			return $base + array(
				'type'   => 'search',
				'search' => function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $search ) : $search,
			);
		}

		$term = self::term_from_vars( $vars );
		if ( false === $term ) {
			return null;
		}
		if ( is_array( $term ) ) {
			return $base + $term;
		}

		if ( ( isset( $vars['author'] ) && '' !== (string) $vars['author'] ) || ( isset( $vars['author_name'] ) && '' !== (string) $vars['author_name'] ) ) {
			$author_id = self::author_id_from_vars( $vars );
			if ( $author_id <= 0 ) {
				return null;
			}

			return $base + array(
				'type'      => 'author',
				'author_id' => $author_id,
			);
		}

		if ( isset( $vars['post_type'] ) && is_string( $vars['post_type'] ) && '' !== $vars['post_type'] ) {
			$type = function_exists( 'get_post_type_object' ) ? get_post_type_object( $vars['post_type'] ) : null;
			if ( ! is_object( $type ) || empty( $type->has_archive ) || ( isset( $type->publicly_queryable ) && ! $type->publicly_queryable ) ) {
				return null;
			}

			return $base + array(
				'type'      => 'post_type_archive',
				'post_type' => (string) $vars['post_type'],
			);
		}

		$date = self::date_from_vars( $vars );
		if ( false === $date ) {
			return null;
		}
		if ( is_array( $date ) ) {
			return $base + array(
				'type' => 'date',
				'date' => $date,
			);
		}

		// Nothing else in the vars: the site root, or the posts page.
		$other = array_diff_key( $vars, array_flip( array( 'paged', 'page', 'cpage', 'order', 'orderby' ) ) );
		if ( empty( $other ) && '' === trim( (string) $path, '/' ) ) {
			if ( 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) > 0 ) {
				return array(
					'type'    => 'front_page',
					'post_id' => (int) get_option( 'page_on_front' ),
				);
			}

			return $base + array( 'type' => 'home' );
		}

		// A page-number path under the root (/page/2/).
		if ( empty( $other ) && preg_match( '#^page/[0-9]+$#', trim( (string) $path, '/' ) ) && 'page' !== get_option( 'show_on_front' ) ) {
			return $base + array( 'type' => 'home' );
		}

		return null;
	}

	/**
	 * The term archive the vars name.
	 *
	 * @param array $vars Query vars.
	 * @return array|false|null Descriptor; false when a term var is present but
	 *                          names nothing browsable; null when there is none.
	 */
	private static function term_from_vars( array $vars ) {
		$candidates = array();

		if ( isset( $vars['cat'] ) && '' !== (string) $vars['cat'] ) {
			$ids = array_values( array_filter( array_map( 'intval', preg_split( '/[,\s]+/', (string) $vars['cat'] ) ) ) );
			if ( empty( $ids ) || $ids[0] < 0 ) {
				return false;
			}
			$candidates[] = array( 'category', 'id', $ids[0] );
		}

		if ( isset( $vars['category_name'] ) && '' !== (string) $vars['category_name'] ) {
			$segments     = array_values( array_filter( explode( '/', trim( (string) $vars['category_name'], '/' ) ), 'strlen' ) );
			$candidates[] = array( 'category', 'slug', (string) end( $segments ) );
		}

		if ( isset( $vars['tag'] ) && '' !== (string) $vars['tag'] ) {
			$slugs        = preg_split( '/[,+\s]+/', (string) $vars['tag'] );
			$candidates[] = array( 'post_tag', 'slug', (string) $slugs[0] );
		}

		if ( isset( $vars['tag_id'] ) && (int) $vars['tag_id'] > 0 ) {
			$candidates[] = array( 'post_tag', 'id', (int) $vars['tag_id'] );
		}

		if ( isset( $vars['taxonomy'], $vars['term'] ) && '' !== (string) $vars['taxonomy'] && '' !== (string) $vars['term'] ) {
			$candidates[] = array( (string) $vars['taxonomy'], 'slug', (string) $vars['term'] );
		}

		foreach ( self::queryable_taxonomies() as $name => $taxonomy ) {
			if ( ! is_object( $taxonomy ) || empty( $taxonomy->query_var ) || ! is_string( $taxonomy->query_var ) ) {
				continue;
			}
			if ( in_array( (string) $name, array( 'category', 'post_tag' ), true ) ) {
				continue;
			}
			if ( isset( $vars[ $taxonomy->query_var ] ) && '' !== (string) $vars[ $taxonomy->query_var ] ) {
				$segments     = array_values( array_filter( explode( '/', trim( (string) $vars[ $taxonomy->query_var ], '/' ) ), 'strlen' ) );
				$candidates[] = array( (string) $name, 'slug', (string) end( $segments ) );
			}
		}

		if ( empty( $candidates ) ) {
			return null;
		}

		list( $taxonomy, $field, $value ) = $candidates[0];

		if ( null === self::queryable_taxonomy( $taxonomy ) || '' === (string) $value || ! function_exists( 'get_term_by' ) ) {
			return false;
		}

		$term = get_term_by( $field, $value, $taxonomy );
		if ( ! is_object( $term ) || empty( $term->term_id ) ) {
			return false;
		}

		$type = 'taxonomy';
		if ( 'category' === $taxonomy ) {
			$type = 'category';
		} elseif ( 'post_tag' === $taxonomy ) {
			$type = 'tag';
		}

		return array(
			'type'     => $type,
			'taxonomy' => $taxonomy,
			'term'     => $term,
		);
	}

	/**
	 * The author id the vars name, or 0.
	 *
	 * @param array $vars Query vars.
	 * @return int
	 */
	private static function author_id_from_vars( array $vars ) {
		if ( isset( $vars['author'] ) && '' !== (string) $vars['author'] ) {
			$ids = array_map( 'intval', preg_split( '/[,\s]+/', (string) $vars['author'] ) );

			return isset( $ids[0] ) && $ids[0] > 0 ? $ids[0] : 0;
		}

		if ( isset( $vars['author_name'] ) && function_exists( 'get_user_by' ) ) {
			$user = get_user_by( 'slug', sanitize_title( (string) $vars['author_name'] ) );

			return is_object( $user ) ? (int) $user->ID : 0;
		}

		return 0;
	}

	/**
	 * The date the vars name.
	 *
	 * @param array $vars Query vars.
	 * @return array|false|null year/monthnum/day; false when invalid; null when absent.
	 */
	private static function date_from_vars( array $vars ) {
		$date = array();

		if ( isset( $vars['m'] ) && '' !== (string) $vars['m'] ) {
			$m = preg_replace( '/[^0-9]/', '', (string) $vars['m'] );
			if ( strlen( $m ) < 4 ) {
				return false;
			}
			$date['year'] = (int) substr( $m, 0, 4 );
			if ( strlen( $m ) >= 6 ) {
				$date['monthnum'] = (int) substr( $m, 4, 2 );
			}
			if ( strlen( $m ) >= 8 ) {
				$date['day'] = (int) substr( $m, 6, 2 );
			}
		}

		foreach ( array( 'year', 'monthnum', 'day' ) as $key ) {
			if ( isset( $vars[ $key ] ) && '' !== (string) $vars[ $key ] ) {
				$date[ $key ] = (int) $vars[ $key ];
			}
		}

		if ( empty( $date ) ) {
			return null;
		}

		if ( ( isset( $date['year'] ) && ( $date['year'] < 1000 || $date['year'] > 9999 ) )
			|| ( isset( $date['monthnum'] ) && ( $date['monthnum'] < 1 || $date['monthnum'] > 12 ) )
			|| ( isset( $date['day'] ) && ( $date['day'] < 1 || $date['day'] > 31 ) ) ) {
			return false;
		}

		return $date;
	}

	/**
	 * WP_Query arguments listing an archive's posts.
	 *
	 * Built only from the descriptor, never from the URL's own query vars, and
	 * always limited to published posts without a password: this lists what a
	 * logged-out visitor sees on that archive.
	 *
	 * @param array $archive  classify() result.
	 * @param int   $per_page Posts per page.
	 * @return array
	 */
	public static function listing_args( array $archive, $per_page ) {
		$args = array(
			'post_status'         => 'publish',
			'has_password'        => false,
			'posts_per_page'      => max( 1, min( self::MAX_PER_PAGE, (int) $per_page ) ),
			'paged'               => max( 1, (int) ( $archive['paged'] ?? 1 ) ),
			'ignore_sticky_posts' => true,
			'no_found_rows'       => false,
		);

		switch ( $archive['type'] ) {
			case 'category':
			case 'tag':
			case 'taxonomy':
				$taxonomy          = self::queryable_taxonomy( $archive['taxonomy'] );
				$types             = $taxonomy && ! empty( $taxonomy->object_type ) ? (array) $taxonomy->object_type : array( 'post' );
				$args['post_type'] = array_values( array_filter( $types, array( __CLASS__, 'is_viewable_post_type' ) ) );
				if ( empty( $args['post_type'] ) ) {
					$args['post_type'] = array( 'post' );
				}
				$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy'         => (string) $archive['taxonomy'],
						'field'            => 'term_id',
						'terms'            => (int) $archive['term']->term_id,
						'include_children' => true,
					),
				);
				break;

			case 'author':
				$args['post_type'] = 'post';
				$args['author']    = (int) $archive['author_id'];
				break;

			case 'post_type_archive':
				$args['post_type'] = (string) $archive['post_type'];
				break;

			case 'date':
				$args['post_type'] = 'post';
				foreach ( $archive['date'] as $key => $value ) {
					$args[ $key ] = (int) $value;
				}
				break;

			case 'search':
				$args['post_type'] = 'any';
				$args['s']         = (string) $archive['search'];
				break;

			default:
				$args['post_type'] = 'post';
		}

		return $args;
	}

	/**
	 * Whether visitors can view posts of a type.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public static function is_viewable_post_type( $post_type ) {
		if ( function_exists( 'is_post_type_viewable' ) ) {
			return (bool) is_post_type_viewable( (string) $post_type );
		}

		$object = function_exists( 'get_post_type_object' ) ? get_post_type_object( (string) $post_type ) : null;

		return is_object( $object ) && ! empty( $object->public );
	}
}
