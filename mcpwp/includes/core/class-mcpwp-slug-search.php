<?php
/**
 * Make `search` match slugs as well as titles and bodies.
 *
 * Reported in #785: wp_list_pages( search: "<an existing slug>" ) returned
 * {"pages":[],"total":0} while wp_get_page_by_slug found the page instantly.
 * The caller was using the search as a collision check before creating a page,
 * read the empty result as "no such page", and created a duplicate.
 *
 * The cause is WP_Query's default search columns: post_title, post_excerpt and
 * post_content. post_name — the slug — is not among them, and cannot be added
 * through the documented route, because parse_search() intersects whatever
 * `search_columns` or the `post_search_columns` filter supplies against that
 * same list of three (class-wp-query.php: `$search_columns = array_intersect(
 * $search_columns, $default_search_columns );`). So the only way in is the
 * `posts_search` filter.
 *
 * This applies to single-token searches only — a term with no whitespace,
 * quotes, or leading `-`. A slug never contains a space, so multi-word searches
 * gain nothing from slug matching, and leaving them to core means none of
 * WordPress's phrase, exclusion or stopword handling is reimplemented here.
 * The SQL below is built for exactly one term and would be wrong for any other
 * shape.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds post_name to the searched columns for simple single-term queries.
 */
class Mcpwp_Slug_Search {

	/**
	 * Query var that opts a query in.
	 *
	 * Set alongside `s` in sanitize_query_args(), which covers the pages and
	 * posts listings, and passed explicitly by the two endpoints that build
	 * their own WP_Query — /content and /search. wp_search in particular is
	 * the tool a caller reaches for to check whether a slug is taken, so it
	 * needs this more than the listings do; an earlier version of this class
	 * claimed to cover it and did not.
	 */
	const QUERY_VAR = 'mcpwp_match_slug';

	/**
	 * Hook the filter.
	 */
	public static function register() {
		add_filter( 'posts_search', array( __CLASS__, 'add_slug_column' ), 10, 2 );
	}

	/**
	 * Whether a search term is the simple single-token kind this handles.
	 *
	 * @param mixed $term Search term.
	 * @return bool
	 */
	public static function should_match_slug( $term ) {
		if ( ! is_string( $term ) ) {
			return false;
		}

		$term = trim( $term );

		if ( '' === $term ) {
			return false;
		}

		// Anything core would split into more than one term is left to core.
		//
		// Core's tokenizer is, verbatim from class-wp-query.php:
		//
		//   /".*?("|$)|((?<=[\t ",+])|^)[^\t ",+]+/
		//
		// so the delimiters are tab, space, double quote, COMMA and PLUS. An
		// earlier version of this check only rejected whitespace and quotes,
		// which let two shapes through that it must not:
		//
		//   "about,us"  core ANDs two LIKE groups; a rebuild for one term
		//               searches the literal "about,us" and matches nothing —
		//               reintroducing the very false negative that made the
		//               reporter create a duplicate page.
		//
		//   ",-draft"   core builds a pure NOT LIKE exclusion. The old check
		//               looked at the first character of the whole string, saw
		//               a comma rather than the dash, and let it through — so
		//               the rebuild replaced an exclusion with an inclusive
		//               match, the exact inversion the next comment forbids.
		if ( preg_match( '/[\t ",+]/', $term ) ) {
			return false;
		}

		// A leading `-` is core's exclusion syntax: it flips the operator from
		// LIKE to NOT LIKE and the joiner from OR to AND. ORing another column
		// into that would widen an exclusion into a match.
		if ( 0 === strpos( $term, '-' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether the search fragment is still exactly core's, for one term.
	 *
	 * Assembled the way parse_search() assembles it, not by pattern sniffing,
	 * so a core change to the shape makes this stop matching — which degrades
	 * to leaving search untouched — rather than matching something it no
	 * longer understands.
	 *
	 * Two details, both captured from a live query rather than assumed:
	 *
	 *  - Core wraps the already-parenthesised column group a second time
	 *    (`$search = " AND ({$search}) "` around `"(a OR b OR c)"`), and the
	 *    optional password clause is appended after that trailing space, so
	 *    there are TWO spaces before its AND.
	 *
	 *  - At filter time the `%` characters are not `%`. $wpdb->prepare()
	 *    replaces them with its placeholder-escape hash and only restores them
	 *    later, so a literal comparison has to go through prepare() as well —
	 *    which is why the parts below are built with it.
	 *
	 * @param string $search Fragment core (and possibly others) produced.
	 * @param string $like   The escaped LIKE value for the single term.
	 * @return bool
	 */
	private static function is_untouched_core_clause( $search, $like ) {
		global $wpdb;

		$parts = array();

		foreach ( array( 'post_title', 'post_excerpt', 'post_content' ) as $column ) {
			$parts[] = $wpdb->prepare( "({$wpdb->posts}.$column LIKE %s)", $like );
		}

		$expected = ' AND (' . '(' . implode( ' OR ', $parts ) . ')' . ') ';

		// Core appends the password guard only for anonymous callers, so both
		// forms are legitimate.
		return $search === $expected
			|| $search === $expected . " AND ({$wpdb->posts}.post_password = '') ";
	}

	/**
	 * Rebuild the search clause with post_name included.
	 *
	 * @param string   $search Search SQL fragment core built.
	 * @param WP_Query $query  The query being run.
	 * @return string Search SQL.
	 */
	public static function add_slug_column( $search, $query = null ) {
		global $wpdb;

		if ( ! is_object( $query ) || ! method_exists( $query, 'get' ) ) {
			return $search;
		}

		if ( ! $query->get( self::QUERY_VAR ) ) {
			return $search;
		}

		$term = $query->get( 's' );

		if ( ! self::should_match_slug( $term ) ) {
			return $search;
		}

		// Core produced nothing — the term was all stopwords, or search was
		// otherwise skipped. Adding a clause here would turn a query core
		// decided not to constrain into one that is constrained, which is a
		// different query than the caller asked for.
		if ( '' === trim( (string) $search ) ) {
			return $search;
		}

		$term = trim( $term );
		$like = '%' . $wpdb->esc_like( $term ) . '%';

		// Only rebuild a clause that is still exactly what core emitted.
		//
		// This filter replaces $search wholesale, so anything another plugin
		// contributed at a lower priority would be silently discarded — and
		// the realistic case is a membership or private-content plugin that
		// NARROWS the results. Dropping its restriction would list titles and
		// URLs it exists to hide. Rebuilding is only safe on a fragment nobody
		// else has touched; when the shape is unfamiliar the honest answer is
		// to leave search alone and lose the slug match, not to guess.
		if ( ! self::is_untouched_core_clause( $search, $like ) ) {
			return $search;
		}

		$clause = $wpdb->prepare(
			"({$wpdb->posts}.post_title LIKE %s) OR ({$wpdb->posts}.post_excerpt LIKE %s) OR ({$wpdb->posts}.post_content LIKE %s) OR ({$wpdb->posts}.post_name LIKE %s)",
			$like,
			$like,
			$like,
			$like
		);

		$rebuilt = " AND ({$clause}) ";

		// Core appends this when the visitor is not logged in, and dropping it
		// would list password-protected posts to anonymous callers. Rebuilding
		// the clause means rebuilding this too — it is not decoration.
		//
		// The detection is a substring test, which is only safe because
		// esc_like() escapes `_` — a caller searching for the literal
		// "post_password" lands in the SQL as `post\_password` and cannot
		// forge this. If the detection ever moves ahead of esc_like(), that
		// stops being true. The shape guard above makes it moot in practice,
		// and it is kept because it is the clause's own invariant, not the
		// guard's.
		if ( false !== strpos( $search, 'post_password' ) ) {
			$rebuilt .= " AND ({$wpdb->posts}.post_password = '') ";
		}

		return $rebuilt;
	}
}
