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
	 * Set alongside `s` in sanitize_query_args(), so every listing endpoint
	 * that already routes through it — pages, posts, generic content — gets
	 * the same behaviour without three copies of this decision.
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

		// Whitespace: multiple terms, which core splits and combines with its
		// own AND/OR logic. A slug has no spaces, so there is nothing to gain
		// and a whole grammar to get wrong.
		if ( preg_match( '/\s/', $term ) ) {
			return false;
		}

		// Core's search grammar. `"quoted"` is a phrase; a leading `-` is an
		// exclusion, which inverts the operator from LIKE to NOT LIKE and the
		// joiner from OR to AND — ORing another column into that would widen
		// an exclusion into a match.
		if ( false !== strpos( $term, '"' ) || 0 === strpos( $term, '-' ) ) {
			return false;
		}

		return true;
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
		if ( false !== strpos( $search, 'post_password' ) ) {
			$rebuilt .= " AND ({$wpdb->posts}.post_password = '') ";
		}

		return $rebuilt;
	}
}
