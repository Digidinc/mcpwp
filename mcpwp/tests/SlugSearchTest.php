<?php
/**
 * Regression coverage for #785's first smaller note — search did not match
 * slugs, and the false negative created a duplicate page.
 *
 * Reported: wp_list_pages( search: "<an existing slug>" ) returned
 * {"pages":[],"total":0}; wp_get_page_by_slug found the same page instantly.
 * The caller used the search as a collision check, believed the empty result,
 * and created a second page at a slug that was already taken.
 *
 * WP_Query searches post_title, post_excerpt and post_content. post_name is
 * not in that list and cannot be added through `search_columns` or the
 * `post_search_columns` filter, both of which core intersects back down to the
 * same three. The `posts_search` filter is the only way in, and using it means
 * rebuilding the clause — including the password guard core appends, which is
 * not decoration.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class SlugSearchTest extends TestCase {

	/**
	 * Minimal WP_Query stand-in: the filter only ever calls get().
	 */
	private function query( array $vars ): object {
		return new class( $vars ) {
			/** @var array */
			private $vars;

			public function __construct( array $vars ) {
				$this->vars = $vars;
			}

			public function get( $key, $default = '' ) {
				return array_key_exists( $key, $this->vars ) ? $this->vars[ $key ] : $default;
			}
		};
	}

	/**
	 * The shape core's parse_search() returns for one term, anonymous.
	 */
	private function core_search_sql(): string {
		return " AND (((wp_posts.post_title LIKE '%about-us%') OR (wp_posts.post_excerpt LIKE '%about-us%') OR (wp_posts.post_content LIKE '%about-us%'))) "
			. " AND (wp_posts.post_password = '') ";
	}

	// ---------------------------------------------------------------
	// Which searches opt in.
	// ---------------------------------------------------------------

	/**
	 * @dataProvider simple_terms
	 * @param string $term A term slug matching should apply to.
	 */
	public function test_a_single_token_opts_in( string $term ): void {
		$this->assertTrue( Mcpwp_Slug_Search::should_match_slug( $term ) );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function simple_terms(): array {
		return array(
			'a slug'          => array( 'about-us' ),
			'a word'          => array( 'contact' ),
			'digits and dash' => array( 'landing-2026' ),
			'padded'          => array( '  about-us  ' ),
		);
	}

	/**
	 * @dataProvider complex_terms
	 * @param string $term Term that must be left to core.
	 * @param string $why  Reason.
	 */
	public function test_anything_with_search_grammar_is_left_to_core( string $term, string $why ): void {
		$this->assertFalse( Mcpwp_Slug_Search::should_match_slug( $term ), $why );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public function complex_terms(): array {
		return array(
			'multiple words' => array( 'about us', 'Core splits terms and joins them with its own AND/OR logic; a slug has no spaces, so there is nothing to gain here and a grammar to get wrong.' ),
			'phrase'         => array( '"about us"', 'A quoted phrase is core\'s syntax.' ),
			'exclusion'      => array( '-draft', 'A leading dash flips LIKE to NOT LIKE and OR to AND; ORing a column into an exclusion would widen it into a match.' ),
			'empty'          => array( '', 'Nothing to match.' ),
			'whitespace'     => array( "  \t ", 'Still nothing.' ),
		);
	}

	public function test_a_non_string_term_is_refused(): void {
		$this->assertFalse( Mcpwp_Slug_Search::should_match_slug( null ) );
		$this->assertFalse( Mcpwp_Slug_Search::should_match_slug( 42 ) );
		$this->assertFalse( Mcpwp_Slug_Search::should_match_slug( array( 'about-us' ) ) );
	}

	// ---------------------------------------------------------------
	// The SQL.
	// ---------------------------------------------------------------

	public function test_post_name_is_added_for_an_opted_in_query(): void {
		$sql = Mcpwp_Slug_Search::add_slug_column(
			$this->core_search_sql(),
			$this->query(
				array(
					's'                            => 'about-us',
					Mcpwp_Slug_Search::QUERY_VAR   => true,
				)
			)
		);

		$this->assertStringContainsString( 'wp_posts.post_name LIKE', $sql );
		$this->assertStringContainsString( 'wp_posts.post_title LIKE', $sql );
		$this->assertStringContainsString( 'wp_posts.post_excerpt LIKE', $sql );
		$this->assertStringContainsString( 'wp_posts.post_content LIKE', $sql );
	}

	public function test_the_password_guard_survives_the_rebuild(): void {
		$sql = Mcpwp_Slug_Search::add_slug_column(
			$this->core_search_sql(),
			$this->query(
				array(
					's'                          => 'about-us',
					Mcpwp_Slug_Search::QUERY_VAR => true,
				)
			)
		);

		// Core appends this for anonymous callers. Rebuilding the clause and
		// forgetting it would list password-protected posts to the public —
		// a listing bug turned into a disclosure bug.
		$this->assertStringContainsString( "post_password = ''", $sql );
	}

	public function test_the_password_guard_is_not_invented_when_core_did_not_add_it(): void {
		$logged_in_sql = " AND (((wp_posts.post_title LIKE '%about-us%'))) ";

		$sql = Mcpwp_Slug_Search::add_slug_column(
			$logged_in_sql,
			$this->query(
				array(
					's'                          => 'about-us',
					Mcpwp_Slug_Search::QUERY_VAR => true,
				)
			)
		);

		$this->assertStringNotContainsString( 'post_password', $sql );
	}

	public function test_the_clause_stays_a_single_and_group(): void {
		$sql = trim(
			Mcpwp_Slug_Search::add_slug_column(
				" AND (((wp_posts.post_title LIKE '%about-us%'))) ",
				$this->query(
					array(
						's'                          => 'about-us',
						Mcpwp_Slug_Search::QUERY_VAR => true,
					)
				)
			)
		);

		// Appending " OR (...)" to core's " AND (...)" — the obvious edit —
		// would let the OR escape the surrounding AND chain and match rows the
		// rest of the WHERE excludes. The clause must stay one bracketed group
		// introduced by AND.
		$this->assertStringStartsWith( 'AND (', $sql );
		$this->assertStringEndsWith( ')', $sql );
	}

	// ---------------------------------------------------------------
	// Everything else must be untouched.
	// ---------------------------------------------------------------

	public function test_a_query_that_did_not_opt_in_is_untouched(): void {
		$original = $this->core_search_sql();

		$this->assertSame(
			$original,
			Mcpwp_Slug_Search::add_slug_column( $original, $this->query( array( 's' => 'about-us' ) ) )
		);
	}

	public function test_a_multi_word_search_is_untouched_even_when_opted_in(): void {
		$original = " AND (((wp_posts.post_title LIKE '%about us%'))) ";

		$this->assertSame(
			$original,
			Mcpwp_Slug_Search::add_slug_column(
				$original,
				$this->query(
					array(
						's'                          => 'about us',
						Mcpwp_Slug_Search::QUERY_VAR => true,
					)
				)
			)
		);
	}

	public function test_an_empty_core_clause_is_left_empty(): void {
		// Core produced no search constraint — all stopwords, say. Adding one
		// here would narrow a query core deliberately left wide.
		$this->assertSame(
			'',
			Mcpwp_Slug_Search::add_slug_column(
				'',
				$this->query(
					array(
						's'                          => 'the',
						Mcpwp_Slug_Search::QUERY_VAR => true,
					)
				)
			)
		);
	}

	public function test_a_missing_query_object_is_survivable(): void {
		$original = $this->core_search_sql();

		// Other plugins call apply_filters( 'posts_search', ... ) with one
		// argument. Fataling there would break search for reasons nothing in
		// this feature explains.
		$this->assertSame( $original, Mcpwp_Slug_Search::add_slug_column( $original ) );
		$this->assertSame( $original, Mcpwp_Slug_Search::add_slug_column( $original, null ) );
	}

	// ---------------------------------------------------------------
	// The wiring, so the opt-in is not left unreachable.
	// ---------------------------------------------------------------

	public function test_sanitize_query_args_opts_a_slug_search_in(): void {
		$controller = new Mcpwp_REST_Pages();
		$method     = new ReflectionMethod( Mcpwp_REST_API::class, 'sanitize_query_args' );
		$method->setAccessible( true );

		$args = $method->invoke( $controller, array( 'search' => 'about-us' ) );

		$this->assertSame( 'about-us', $args['s'] );
		$this->assertTrue( $args[ Mcpwp_Slug_Search::QUERY_VAR ] );
	}

	public function test_sanitize_query_args_leaves_a_phrase_search_alone(): void {
		$controller = new Mcpwp_REST_Pages();
		$method     = new ReflectionMethod( Mcpwp_REST_API::class, 'sanitize_query_args' );
		$method->setAccessible( true );

		$args = $method->invoke( $controller, array( 'search' => 'about us' ) );

		$this->assertSame( 'about us', $args['s'] );
		$this->assertArrayNotHasKey( Mcpwp_Slug_Search::QUERY_VAR, $args );
	}
}
