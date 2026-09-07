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

	/** @var object|null */
	private $real_wpdb;

	protected function setUp(): void {
		// The suite-wide $wpdb double returns prepare()'s template verbatim and
		// DISCARDS the arguments, and its esc_like() is the identity. Under it
		// the search term never enters the SQL under test, so every assertion
		// here would pass unchanged if the clause were rewritten with direct
		// string interpolation — the injection guard would have no test at all.
		//
		// This one substitutes and escapes, and records what it was asked to
		// prepare, so both the shape and the escaping are actually exercised.
		$this->real_wpdb   = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = new class {
			public $posts = 'wp_posts';

			/** @var array Every prepare() call, as {query, args}. */
			public $prepared = array();

			public function esc_like( $text ) {
				return addcslashes( (string) $text, '_%\\' );
			}

			public function prepare( $query, ...$args ) {
				$this->prepared[] = array(
					'query' => $query,
					'args'  => $args,
				);

				foreach ( $args as $arg ) {
					$query = preg_replace(
						'/%s/',
						"'" . str_replace( "'", "\\'", (string) $arg ) . "'",
						$query,
						1
					);
				}

				return $query;
			}
		};
	}

	protected function tearDown(): void {
		// Restored, not merely reassigned in setUp: a swapped $wpdb following
		// this class out would change every query-touching test after it
		// (#757's family).
		if ( null !== $this->real_wpdb ) {
			$GLOBALS['wpdb'] = $this->real_wpdb;
		}
	}

	/**
	 * Core's own search fragment for one term, assembled the way
	 * WP_Query::parse_search() assembles it.
	 *
	 * Verified against a live WP_Query on the rig, which is where the two
	 * details a hand-written fixture gets wrong came from: the column group is
	 * parenthesised TWICE, and the password clause lands after a trailing
	 * space so there are two spaces before its AND.
	 *
	 * @param string $term        Search term.
	 * @param bool   $logged_out  Whether core appends the password guard.
	 * @return string
	 */
	private function core_search_sql( string $term = 'about-us', bool $logged_out = true ): string {
		$wpdb = $GLOBALS['wpdb'];
		$like = '%' . $wpdb->esc_like( $term ) . '%';

		$parts = array();

		foreach ( array( 'post_title', 'post_excerpt', 'post_content' ) as $column ) {
			$parts[] = $wpdb->prepare( "({$wpdb->posts}.$column LIKE %s)", $like );
		}

		$search = ' AND (' . '(' . implode( ' OR ', $parts ) . ')' . ') ';

		if ( $logged_out ) {
			$search .= " AND ({$wpdb->posts}.post_password = '') ";
		}

		return $search;
	}

	/**
	 * Run the filter for an opted-in single-term query.
	 *
	 * @param string      $term   Search term.
	 * @param string|null $search Fragment to feed it; core's own by default.
	 * @return string
	 */
	private function filter( string $term = 'about-us', ?string $search = null ): string {
		return Mcpwp_Slug_Search::add_slug_column(
			null === $search ? $this->core_search_sql( $term ) : $search,
			$this->query(
				array(
					's'                          => $term,
					Mcpwp_Slug_Search::QUERY_VAR => true,
				)
			)
		);
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
	 * Core's tokenizer, verbatim from class-wp-query.php:
	 *
	 *   /".*?("|$)|((?<=[\t ",+])|^)[^\t ",+]+/
	 *
	 * so the delimiters are tab, space, double quote, comma and plus. An
	 * earlier version of should_match_slug() rejected only whitespace, quotes
	 * and a leading dash, which let the last two rows through.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function complex_terms(): array {
		return array(
			'multiple words' => array( 'about us', 'Core splits terms and joins them with its own AND/OR logic; a slug has no spaces, so there is nothing to gain here and a grammar to get wrong.' ),
			'phrase'         => array( '"about us"', 'A quoted phrase is core\'s syntax.' ),
			'exclusion'      => array( '-draft', 'A leading dash flips LIKE to NOT LIKE and OR to AND; ORing a column into an exclusion would widen it into a match.' ),
			'empty'          => array( '', 'Nothing to match.' ),
			'whitespace'     => array( "  \t ", 'Still nothing.' ),
			'comma'          => array( 'about,us', 'Core splits on commas too. Searching the literal "about,us" as one term matches nothing — the same false negative that made the reporter create a duplicate page, for a different input.' ),
			'plus'           => array( 'landing+page', 'Core splits on plus as well.' ),
			'comma exclusion' => array( ',-draft', 'Core reads this as a single EXCLUSION. Checking only the first character of the whole string saw the comma, not the dash, and let an exclusion be rewritten as an inclusive match.' ),
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
		$before = $this->core_search_sql();
		$after  = $this->filter();

		$this->assertNotSame(
			$before,
			$after,
			'A filter that returned its input unchanged would satisfy every other assertion in this class; this is the one that fails for it.'
		);

		$this->assertStringNotContainsString( 'post_name', $before );
		$this->assertStringContainsString( 'wp_posts.post_name LIKE', $after );

		foreach ( array( 'post_title', 'post_excerpt', 'post_content' ) as $column ) {
			$this->assertStringContainsString( "wp_posts.{$column} LIKE", $after );
		}
	}

	public function test_the_term_reaches_the_sql_as_a_prepared_argument(): void {
		$GLOBALS['wpdb']->prepared = array();

		$this->filter( "o'brien-page" );

		$found = false;

		foreach ( $GLOBALS['wpdb']->prepared as $call ) {
			if ( false === strpos( $call['query'], 'post_name' ) ) {
				continue;
			}

			$found = true;

			// The term must arrive as an ARGUMENT. A clause built by
			// interpolating it into the template would put it in ['query'],
			// and every shape assertion in this file would still pass.
			$this->assertStringNotContainsString( 'brien', $call['query'] );
			$this->assertNotEmpty( $call['args'] );
			$this->assertStringContainsString( "o'brien-page", (string) $call['args'][0] );
		}

		$this->assertTrue( $found, 'No prepared statement mentioned post_name, so nothing above was checked.' );
	}

	public function test_like_wildcards_in_the_term_are_escaped(): void {
		$GLOBALS['wpdb']->prepared = array();

		// Unescaped, `%` and `_` are LIKE wildcards: "100%_off" would match far
		// more rows than the caller asked about.
		$this->filter( '100%_off' );

		foreach ( $GLOBALS['wpdb']->prepared as $call ) {
			if ( false === strpos( $call['query'], 'post_name' ) ) {
				continue;
			}

			$this->assertStringContainsString( '100\%\_off', (string) $call['args'][0] );
		}
	}

	public function test_the_password_guard_survives_the_rebuild(): void {
		$after = $this->filter();

		// Core appends this for anonymous callers. Rebuilding the clause and
		// forgetting it would list password-protected posts to the public —
		// a listing bug turned into a disclosure bug.
		$this->assertStringContainsString( "post_password = ''", $after );

		// And the clause really was rebuilt, so this is not just the input
		// coming back untouched.
		$this->assertStringContainsString( 'post_name', $after );
	}

	public function test_the_password_guard_is_not_invented_when_core_did_not_add_it(): void {
		$after = $this->filter( 'about-us', $this->core_search_sql( 'about-us', false ) );

		$this->assertStringContainsString( 'post_name', $after, 'The rebuild must have happened for the next assertion to mean anything.' );
		$this->assertStringNotContainsString( 'post_password', $after );
	}

	public function test_the_clause_stays_a_single_and_group(): void {
		$after = trim( $this->filter( 'about-us', $this->core_search_sql( 'about-us', false ) ) );

		$this->assertStringContainsString( 'post_name', $after );

		// Appending " OR (...)" to core's " AND (...)" — the obvious edit —
		// would let the OR escape the surrounding AND chain and match rows the
		// rest of the WHERE excludes. The clause must stay one bracketed group
		// introduced by AND, with balanced parentheses.
		$this->assertStringStartsWith( 'AND (', $after );
		$this->assertStringEndsWith( ')', $after );
		$this->assertSame( substr_count( $after, '(' ), substr_count( $after, ')' ) );
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

	public function test_a_fragment_another_plugin_has_touched_is_left_alone(): void {
		// The rebuild replaces $search wholesale. A membership or
		// private-content plugin that NARROWS search at a lower priority would
		// have its restriction silently discarded, listing titles and URLs it
		// exists to hide. When the shape is not exactly core's, the honest
		// answer is to lose the slug match rather than guess.
		$narrowed = $this->core_search_sql() . " AND (wp_posts.post_author = 7) ";

		$after = Mcpwp_Slug_Search::add_slug_column(
			$narrowed,
			$this->query(
				array(
					's'                          => 'about-us',
					Mcpwp_Slug_Search::QUERY_VAR => true,
				)
			)
		);

		$this->assertSame( $narrowed, $after );
		$this->assertStringNotContainsString( 'post_name', $after );
	}

	public function test_a_multi_word_search_is_untouched_even_when_opted_in(): void {
		$original = $this->core_search_sql( 'about us' );

		$this->assertSame( $original, $this->filter( 'about us', $original ) );
	}

	public function test_an_empty_core_clause_is_left_empty(): void {
		// Core produced no search constraint — all stopwords, say. Adding one
		// here would narrow a query core deliberately left wide.
		$this->assertSame( '', $this->filter( 'the', '' ) );
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
