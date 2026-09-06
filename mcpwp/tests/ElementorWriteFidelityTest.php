<?php
/**
 * Regression coverage for #769: a second wp_apply_elementor_part insert stored
 * the caller's data correctly but came back render_verified=false, reporting a
 * "truncated or otherwise changed tree" of 44 elements out of 44.
 *
 * The element count was identical because nothing was dropped. Elementor was
 * REWRITING settings inside the tree during the HTML-cache rebuild, through two
 * separate paths, both of which run for an API-key request and neither of which
 * runs for an admin editing in the browser:
 *
 * 1. Core\Base\Document::save() runs the whole payload through
 *    Utils::kses_post_deep() whenever the current user lacks `unfiltered_html`.
 *    The MCPWP API key maps onto the mcpwp_api_agent role, which never has it.
 * 2. Modules\ContentSanitizer\Module::sanitize_content() rewrites every heading
 *    `title` through wp_kses() with an allow-list it builds itself — and its own
 *    attribute filter is inverted (`! substr( $a, 0, 5 ) === 'data-'` parses as
 *    `( ! substr(...) ) === 'data-'`, always false), so array_filter() keeps
 *    nothing and every attribute is stripped from every tag.
 *
 * Reproduced on the local rig (WP 7.0.2, Elementor 4.2.4, PHP 8.3): a heading
 * title of `Upcoming <span style="color:#f00">Dates</span>` came back as
 * `Upcoming <span>Dates</span>`, tripping the integrity guard on a page that had
 * been written correctly.
 *
 * Neither pass was protecting anything here. MCPWP writes and verifies
 * _elementor_data from the caller's bytes before Elementor is called at all, and
 * restores those bytes over any rebuild that disagrees; Elementor's frontend
 * renders from that meta. Both mutations only ever reached the render cache —
 * the one copy that has to agree with what we stored.
 *
 * What is asserted here: both are neutralised for exactly the calls that carry
 * our tree, for exactly the writing user, and neither survives the call — not
 * on the way out, and not after a Throwable.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class ElementorWriteFidelityTest extends TestCase {

	private const WRITER_ID = 2;
	private const OTHER_ID  = 3;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_filters']         = array();
		$GLOBALS['mcpwp_test_current_user']    = self::WRITER_ID;
		$GLOBALS['mcpwp_test_user_caps']       = array(
			self::WRITER_ID => array( 'unfiltered_html' => false ),
			self::OTHER_ID  => array( 'unfiltered_html' => false ),
		);
		$GLOBALS['mcpwp_test_sanitizer_ran']   = 0;

		$this->install_core_post_content_kses_gate();
	}

	/**
	 * WordPress hooks wp_filter_post_kses onto content_save_pre in kses_init(),
	 * once per request, for any user without `unfiltered_html`. Everything below
	 * depends on that being the state of the request — it is what keeps
	 * post_content filtered while the scoped grant is active, and the writer
	 * treats its presence as a precondition for granting at all.
	 */
	private function install_core_post_content_kses_gate(): void {
		add_filter( 'content_save_pre', 'wp_filter_post_kses', 10, 1 );
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_filters']      = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_user_caps']    = array();
	}

	/**
	 * Run one call through the writer's Elementor guard.
	 *
	 * @param callable $call  What to run inside the guard.
	 * @param string   $label Debug label, which is also what selects the scope.
	 * @param array    $debug Debug bag, by reference.
	 * @return array{ok:bool,value:mixed}
	 */
	private function attempt( callable $call, string $label, array &$debug ): array {
		$writer = ( new ReflectionClass( Mcpwp_Elementor_Basic::class ) )->newInstanceWithoutConstructor();

		$method = new ReflectionMethod( Mcpwp_Elementor_Basic::class, 'attempt_elementor_call' );
		$method->setAccessible( true );

		$args = array( $call, &$debug, $label );

		return $method->invokeArgs( $writer, $args );
	}

	// ---------------------------------------------------------------------
	// The scoped unfiltered_html grant.
	// ---------------------------------------------------------------------

	/**
	 * @dataProvider content_bearing_label_provider
	 *
	 * @param string $label A call that hands Elementor our element tree.
	 */
	public function test_a_content_bearing_call_can_write_its_own_bytes( string $label ): void {
		$this->assertFalse(
			current_user_can( 'unfiltered_html' ),
			'Baseline: the API-agent user has no unfiltered_html, which is what makes Elementor rewrite the payload.'
		);

		$seen  = null;
		$debug = array();

		$this->attempt(
			static function () use ( &$seen ) {
				$seen = current_user_can( 'unfiltered_html' );
				return true;
			},
			$label,
			$debug
		);

		$this->assertTrue(
			$seen,
			'Inside a content-bearing call Elementor must not kses the tree we already stored and verified.'
		);
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function content_bearing_label_provider(): array {
		return array(
			'initial document save' => array( 'document_save' ),
			'html cache rebuild'    => array( 'html_cache_rebuild' ),
		);
	}

	/**
	 * @dataProvider content_free_label_provider
	 *
	 * @param string $label A call that carries no caller content.
	 */
	public function test_a_call_carrying_no_content_leaves_capabilities_alone( string $label ): void {
		$seen  = null;
		$debug = array();

		$this->attempt(
			static function () use ( &$seen ) {
				$seen = current_user_can( 'unfiltered_html' );
				return true;
			},
			$label,
			$debug
		);

		$this->assertFalse(
			$seen,
			'A cache clear or CSS regeneration carries no content, so it has no business touching capabilities.'
		);
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function content_free_label_provider(): array {
		return array(
			'cache clear'    => array( 'cache_clear' ),
			'css regenerate' => array( 'css_regeneration' ),
		);
	}

	public function test_the_grant_does_not_outlive_the_call(): void {
		$debug = array();

		$this->attempt( static fn() => true, 'document_save', $debug );

		$this->assertFalse( current_user_can( 'unfiltered_html' ), 'The grant must end with the call that needed it.' );
		$this->assertSame( 0, mcpwp_test_filter_count( 'user_has_cap' ) );
		$this->assertSame( 0, mcpwp_test_filter_count( 'map_meta_cap' ) );
	}

	public function test_a_throw_does_not_leave_the_grant_installed(): void {
		$debug = array();

		$result = $this->attempt(
			static function () {
				throw new \RuntimeException( 'synthetic Elementor failure' );
			},
			'document_save',
			$debug
		);

		$this->assertFalse( $result['ok'] );
		$this->assertFalse(
			current_user_can( 'unfiltered_html' ),
			'A Throwable is the case where a leaked capability would be least visible and most dangerous.'
		);
		$this->assertSame( 0, mcpwp_test_filter_count( 'user_has_cap' ) );
		$this->assertSame( 0, mcpwp_test_filter_count( 'map_meta_cap' ) );
	}

	public function test_the_grant_applies_only_to_the_writing_user(): void {
		$seen  = null;
		$debug = array();

		$this->attempt(
			static function () use ( &$seen ) {
				// A hook running inside the save that checks some other user —
				// a revision author, a notification recipient — must not pick
				// the grant up.
				$seen = apply_filters(
					'user_has_cap',
					array(),
					array( 'unfiltered_html' ),
					array( 'unfiltered_html' ),
					new WP_User( self::OTHER_ID )
				);
			},
			'document_save',
			$debug
		);

		$this->assertArrayNotHasKey( 'unfiltered_html', $seen );
	}

	public function test_no_grant_is_installed_without_a_logged_in_user(): void {
		$GLOBALS['mcpwp_test_current_user'] = 0;

		$installed = null;
		$debug     = array();

		$this->attempt(
			static function () use ( &$installed ) {
				$installed = mcpwp_test_filter_count( 'user_has_cap' );
				return true;
			},
			'document_save',
			$debug
		);

		$this->assertSame(
			0,
			$installed,
			'Elementor refuses the save outright with no user; granting a capability to nobody would only widen the surface.'
		);
	}

	public function test_unfiltered_html_stays_reachable_where_map_meta_cap_would_deny_it(): void {
		$mapped = null;
		$debug  = array();

		$this->attempt(
			static function () use ( &$mapped ) {
				// Multisite maps unfiltered_html to do_not_allow for anyone who
				// is not a super admin, before user_has_cap is consulted. Without
				// the map_meta_cap leg the grant is a silent no-op there.
				$mapped = apply_filters( 'map_meta_cap', array( 'do_not_allow' ), 'unfiltered_html', self::WRITER_ID, array() );
			},
			'document_save',
			$debug
		);

		$this->assertSame( array( 'unfiltered_html' ), $mapped );
	}

	public function test_the_grant_is_declined_when_core_stops_filtering_post_content(): void {
		// Elementor's save writes post_content from caller markup while the
		// grant is active, and it is core's latched content_save_pre filter —
		// not anything in this plugin — that keeps that write filtered. Without
		// it the grant would be the only thing standing between an API caller
		// and stored markup, so it is refused rather than assumed.
		remove_filter( 'content_save_pre', 'wp_filter_post_kses', 10 );

		$seen  = null;
		$debug = array();

		$this->attempt(
			static function () use ( &$seen ) {
				$seen = current_user_can( 'unfiltered_html' );
				return true;
			},
			'document_save',
			$debug
		);

		$this->assertFalse( $seen, 'No core gate means no grant — #769 returns as an honest failed verdict instead.' );
		$this->assertSame(
			'core content_save_pre KSES gate is not installed',
			$debug['unfiltered_html_grant_declined'] ?? null,
			'Declining must be visible: "we chose not to" cannot look like "there was nothing to do".'
		);
		$this->assertSame( 0, mcpwp_test_filter_count( 'user_has_cap' ) );
	}

	public function test_an_unrelated_capability_is_never_granted(): void {
		$seen  = null;
		$debug = array();

		$GLOBALS['mcpwp_test_user_caps'][ self::WRITER_ID ]['manage_options'] = false;

		$this->attempt(
			static function () use ( &$seen ) {
				$seen = current_user_can( 'manage_options' );
				return true;
			},
			'document_save',
			$debug
		);

		$this->assertFalse( $seen, 'Exactly one capability is in scope. manage_options would hand over the whole site.' );
	}

	// ---------------------------------------------------------------------
	// Elementor's own content sanitizer.
	// ---------------------------------------------------------------------

	private function install_elementor_with_sanitizer(): callable {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}

		require_once __DIR__ . '/fixtures/elementor-content-sanitizer-doubles.php';

		\Elementor\Plugin::$instance = new \Elementor\Plugin();

		$callback = array( \Elementor\Plugin::$instance->modules_manager->get_modules( 'content-sanitizer' ), 'sanitize_content' );

		add_filter( 'elementor/document/save/data', $callback, 10, 2 );

		return $callback;
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_content_sanitizer_cannot_rewrite_the_tree_we_are_rendering(): void {
		$callback = $this->install_elementor_with_sanitizer();

		$ran   = null;
		$debug = array();

		$this->attempt(
			static function () use ( &$ran ) {
				apply_filters( 'elementor/document/save/data', array( 'elements' => array() ), null );
				$ran = $GLOBALS['mcpwp_test_sanitizer_ran'];
				return true;
			},
			'html_cache_rebuild',
			$debug
		);

		$this->assertSame( 0, $ran, 'Elementor 4.2.4 strips every attribute from every heading title it touches.' );
		$this->assertTrue( $debug['content_sanitizer_suspended'], 'Suspending a third-party sanitizer must be visible in the write debug, not silent.' );
		$this->assertSame( 10, has_filter( 'elementor/document/save/data', $callback ), 'It is Elementor\'s hook — it goes straight back.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_throw_does_not_leave_the_content_sanitizer_unhooked(): void {
		$callback = $this->install_elementor_with_sanitizer();

		$debug = array();

		$this->attempt(
			static function () {
				throw new \RuntimeException( 'synthetic Elementor failure' );
			},
			'html_cache_rebuild',
			$debug
		);

		$this->assertSame(
			10,
			has_filter( 'elementor/document/save/data', $callback ),
			'Leaving Elementor\'s own sanitizer off after a failed write would change the site beyond this request.'
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_content_free_call_leaves_the_sanitizer_hooked(): void {
		$this->install_elementor_with_sanitizer();

		$ran   = null;
		$debug = array();

		$this->attempt(
			static function () use ( &$ran ) {
				apply_filters( 'elementor/document/save/data', array( 'elements' => array() ), null );
				$ran = $GLOBALS['mcpwp_test_sanitizer_ran'];
				return true;
			},
			'cache_clear',
			$debug
		);

		$this->assertSame( 1, $ran );
		$this->assertArrayNotHasKey( 'content_sanitizer_suspended', $debug );
	}
}
