<?php
/**
 * Regression coverage for #952 — deleting an Elementor Pro Theme Builder
 * template left its ID behind in `elementor_pro_theme_builder_conditions`,
 * the option Elementor Pro's frontend reads at render time to decide which
 * templates apply where. `wp_delete_post()` never touches that option, and
 * nothing else ran afterward that would.
 *
 * The option's real shape — confirmed by reading
 * `ElementorPro\Modules\ThemeBuilder\Classes\Conditions_Cache` on a live
 * Elementor Pro install rather than assuming it — is
 * `{ location => { template_id => [condition_strings] } }`: LOCATION first,
 * then template ID. `Mcpwp_Theme_Builder::update_global_conditions_index()`
 * (pre-existing, unrelated to this issue) writes the *opposite* axis
 * (`{ condition_key => [template_ids] }`) into this same option; that is
 * harmless there only because Elementor's own `save_conditions()` and the
 * explicit `get_cache()->regenerate()` right after it both immediately
 * rebuild the option correctly, overwriting it. A delete has no such
 * follow-up write, so a fix for this issue cannot reuse that method's shape
 * — it would silently do nothing. The real fix instead calls Elementor
 * Pro's own purpose-built `Conditions_Manager::purge_post_from_cache()`.
 *
 * Adversarial review of the first cut found two more defects, both covered
 * here:
 *
 *  - WARN-1 (uncovered path): the cleanup was called from three scattered
 *    sites — Mcpwp_Elementor_Pro::delete_template(),
 *    Mcpwp_REST_Content::delete_content(), and create_theme_template()'s
 *    rollback — but MCPWP's own generic `/posts/{id}` route
 *    (Mcpwp_Posts::delete_post()) accepts `elementor_library` too and had
 *    none, and neither does wp-admin's post-delete screen, WP-CLI, or any
 *    other plugin calling wp_delete_post() directly. Fixed by replacing all
 *    three scattered calls with ONE `Mcpwp_Theme_Builder::
 *    handle_before_delete_post()`, hooked on WordPress's own
 *    `before_delete_post` (fired by `wp_delete_post()` itself), which
 *    covers every path at once.
 *  - WARN-2 (unsafe save of a possibly-stale in-memory cache): Elementor
 *    Pro's Conditions_Manager/Conditions_Cache is a per-request singleton
 *    loaded from the option once, at construction. If anything earlier in
 *    the SAME request already ran regenerate() and it threw partway
 *    through its rebuild, that singleton's in-memory state holds only
 *    whatever it rebuilt before the crash — calling
 *    purge_post_from_cache() directly on it would persist that truncated
 *    state, wiping every OTHER template's real binding, not just the one
 *    being deleted. Fixed by refreshing the cache from the option
 *    immediately before every purge. Also: the naive fallback for an
 *    Elementor Pro build old enough not to have purge_post_from_cache()
 *    was get_cache()->regenerate() — its rebuild query matches only public
 *    statuses, so it would silently drop every draft/scheduled template's
 *    binding too. Fixed by removing the one template directly from the
 *    stored option instead, bypassing Elementor's cache object entirely.
 *
 * Covered here:
 *  - `Mcpwp_Theme_Builder::cleanup_after_template_delete()`: removes the
 *    template from the real-shaped cache, leaves unrelated templates and
 *    locations untouched, drops a location left with nothing under it,
 *    makes no write/no purge call when the template was never cached, does
 *    nothing (returns false, no write) when Elementor Pro's Theme Builder
 *    isn't available at all, never lets a broken purge propagate and block
 *    the delete that triggered it, and never persists a regenerate()'s
 *    stale/truncated in-memory state over other templates' real bindings.
 *  - `Mcpwp_Theme_Builder::handle_before_delete_post()`, wired on
 *    `before_delete_post`: covers `Mcpwp_Elementor_Pro::delete_template()`
 *    (trash and force), `Mcpwp_REST_Content::delete_content()`,
 *    `Mcpwp_Posts::delete_post()` (the generic `/posts/{id}` route — the
 *    path WARN-1 found uncovered), and a bare `wp_delete_post()` call with
 *    no MCPWP route involved at all (wp-admin/WP-CLI/another plugin).
 *    Ignores any post that is not `elementor_library`.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

// None of these are required by tests/bootstrap.php by default — guarded
// the same way ElementorTemplateTypeValidationTest.php does, since nothing
// else pulls class-mcpwp-elementor-pro.php into the main process.
if ( ! class_exists( 'Mcpwp_Elementor_Pro' ) ) {
	require_once dirname( __DIR__ ) . '/includes/pro/core/class-mcpwp-elementor-pro.php';
}
if ( ! class_exists( 'Mcpwp_REST_Content' ) ) {
	require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-content.php';
}
if ( ! class_exists( 'Mcpwp_Drafts' ) ) {
	require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-drafts.php';
}
if ( ! class_exists( 'Mcpwp_Posts' ) ) {
	require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-posts.php';
}
if ( ! class_exists( 'Mcpwp_REST_Posts' ) ) {
	require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-posts.php';
}

final class ElementorProConditionsIndexCleanupTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options'] = array();
		$GLOBALS['_mcpwp_test_posts']  = array();
		$GLOBALS['_mcpwp_test_meta']   = array();

		// Not a Destructive Gate test: the /posts and /content routes gate
		// every permanent delete by default (#536), which would otherwise
		// turn a delete into a 202 approval request instead of an actual
		// delete for the tests below that exercise those routes.
		update_option( 'mcpwp_settings', array( 'destructive_requires_approval' => false ) );
	}

	private function seed_template( int $id, string $status = 'publish' ): void {
		$this->seed_post( $id, 'elementor_library', $status );
	}

	private function seed_post( int $id, string $post_type, string $status = 'publish' ): void {
		$GLOBALS['_mcpwp_test_posts'][ $id ] = (object) array(
			'ID'          => $id,
			'post_type'   => $post_type,
			'post_status' => $status,
			'post_title'  => ucfirst( $post_type ) . ' ' . $id,
		);
	}

	/**
	 * Loads the Elementor + Elementor Pro doubles shared with
	 * ThemeBuilderCreateAtomicityTest.php, and the two version constants
	 * Mcpwp_Theme_Builder::is_available() checks for. Only usable from a
	 * @runInSeparateProcess test (see the fixture's own docblock, #757).
	 */
	private function install_elementor_pro_doubles(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}
		if ( ! defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			define( 'ELEMENTOR_PRO_VERSION', '4.2.3' );
		}

		$GLOBALS['mcpwp_theme_test_cache_purge_calls']      = 0;
		$GLOBALS['mcpwp_theme_test_cache_regenerate_calls'] = 0;
		$GLOBALS['mcpwp_theme_test_purge_throws']           = false;
		$GLOBALS['mcpwp_theme_test_regenerate_throws']      = false;

		require_once __DIR__ . '/fixtures/theme-builder-elementor-doubles.php';
	}

	/**
	 * Wires the exact same before_delete_post hook production wires in
	 * Mcpwp_Pro_Bootstrap::register_routes() (#952 WARN-1) — done directly
	 * here rather than by invoking that whole bootstrap method, which would
	 * also register dozens of unrelated REST routes for no benefit to this
	 * test.
	 *
	 * @return Mcpwp_Theme_Builder
	 */
	private function wire_before_delete_post_hook(): Mcpwp_Theme_Builder {
		$theme_builder = new Mcpwp_Theme_Builder();
		add_action( 'before_delete_post', array( $theme_builder, 'handle_before_delete_post' ), 10, 2 );

		return $theme_builder;
	}

	// ---------------------------------------------------------------
	// Elementor Pro's Theme Builder not available at all: cleanup must be
	// a complete no-op, not a fatal (no Elementor namespace declared in
	// this main-process test, so this doubles as proof the method never
	// touches the option before confirming availability).
	// ---------------------------------------------------------------

	public function test_cleanup_does_nothing_when_theme_builder_is_not_available(): void {
		update_option( 'elementor_pro_theme_builder_conditions', array( 'header' => array( 1 => array( 'include/general' ) ) ) );

		$changed = ( new Mcpwp_Theme_Builder() )->cleanup_after_template_delete( 1 );

		$this->assertFalse( $changed );
		$this->assertSame(
			array( 'header' => array( 1 => array( 'include/general' ) ) ),
			get_option( 'elementor_pro_theme_builder_conditions' ),
			'Untouched — nothing here can safely call into Elementor Pro classes that do not exist.'
		);
	}

	// ---------------------------------------------------------------
	// Mcpwp_Theme_Builder::cleanup_after_template_delete() itself.
	// ---------------------------------------------------------------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_removes_the_template_from_the_real_shaped_cache(): void {
		$this->install_elementor_pro_doubles();

		update_option( 'elementor_pro_theme_builder_conditions', array( 'header' => array( 7 => array( 'include/general' ) ) ) );

		$changed = ( new Mcpwp_Theme_Builder() )->cleanup_after_template_delete( 7 );

		$this->assertTrue( $changed );
		$this->assertSame(
			array(),
			get_option( 'elementor_pro_theme_builder_conditions' ),
			'The template must be gone, and the location it alone occupied dropped entirely — not left as [].'
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_leaves_other_templates_and_locations_untouched(): void {
		$this->install_elementor_pro_doubles();

		update_option(
			'elementor_pro_theme_builder_conditions',
			array(
				'header' => array(
					7 => array( 'include/general' ),
					8 => array( 'include/general' ),
				),
				'footer' => array(
					8 => array( 'include/general' ),
				),
			)
		);

		( new Mcpwp_Theme_Builder() )->cleanup_after_template_delete( 7 );

		$this->assertSame(
			array(
				'header' => array( 8 => array( 'include/general' ) ),
				'footer' => array( 8 => array( 'include/general' ) ),
			),
			get_option( 'elementor_pro_theme_builder_conditions' )
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_no_purge_call_and_no_write_when_the_template_was_never_cached(): void {
		$this->install_elementor_pro_doubles();

		update_option( 'elementor_pro_theme_builder_conditions', array( 'header' => array( 5 => array( 'include/general' ) ) ) );

		$changed = ( new Mcpwp_Theme_Builder() )->cleanup_after_template_delete( 999 );

		$this->assertFalse( $changed );
		$this->assertSame( 0, $GLOBALS['mcpwp_theme_test_cache_purge_calls'] );
		$this->assertSame(
			array( 'header' => array( 5 => array( 'include/general' ) ) ),
			get_option( 'elementor_pro_theme_builder_conditions' )
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_broken_purge_does_not_propagate(): void {
		$this->install_elementor_pro_doubles();
		$GLOBALS['mcpwp_theme_test_purge_throws'] = true;

		update_option( 'elementor_pro_theme_builder_conditions', array( 'header' => array( 7 => array( 'include/general' ) ) ) );

		$changed = ( new Mcpwp_Theme_Builder() )->cleanup_after_template_delete( 7 );

		$this->assertFalse( $changed, 'A crash in the best-effort purge is reported as "did not clean up", not thrown.' );
	}

	/**
	 * WARN-2: Conditions_Manager/Conditions_Cache is a per-request
	 * singleton loaded from the option once, at construction. If something
	 * EARLIER in this same request already ran regenerate() and it threw
	 * partway through its rebuild, the singleton's in-memory state holds
	 * only whatever it rebuilt before the crash — while the option on disk,
	 * untouched by the failed regenerate() (its own save() is never
	 * reached), still has everything. A purge that trusted the singleton's
	 * in-memory state rather than refreshing from the option first would
	 * persist that truncated snapshot, wiping every OTHER template's real
	 * binding along with the one actually being deleted.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_regenerate_that_crashed_earlier_in_the_request_does_not_wipe_other_templates_on_purge(): void {
		$this->install_elementor_pro_doubles();

		// Two other, real, unrelated templates, plus the one about to be
		// deleted — all three legitimately bound.
		update_option(
			'elementor_pro_theme_builder_conditions',
			array(
				'header' => array(
					5 => array( 'include/general' ),
					7 => array( 'include/general' ),
					9 => array( 'include/general' ),
				),
			)
		);

		// Simulate something earlier in THIS SAME request already running
		// regenerate() (e.g. an unrelated conditions save elsewhere in the
		// request) and it crashing partway through its rebuild.
		$conditions_manager                            = \ElementorPro\Modules\ThemeBuilder\Module::instance()->get_conditions_manager();
		$GLOBALS['mcpwp_theme_test_regenerate_throws'] = true;
		try {
			$conditions_manager->get_cache()->regenerate();
			$this->fail( 'The double must actually throw for this test to mean anything.' );
		} catch ( \RuntimeException $e ) {
			// Expected — this is the crash being simulated.
		}
		$GLOBALS['mcpwp_theme_test_regenerate_throws'] = false;

		// The option on disk is untouched by that crash — still all three.
		$this->assertSame(
			array( 5, 7, 9 ),
			array_keys( get_option( 'elementor_pro_theme_builder_conditions' )['header'] ),
			'Premise: the crashed regenerate() never reached save(), so the option itself is still intact.'
		);

		$this->seed_template( 9 );

		$changed = ( new Mcpwp_Theme_Builder() )->cleanup_after_template_delete( 9 );

		$this->assertTrue( $changed );
		$this->assertSame(
			array(
				'header' => array(
					5 => array( 'include/general' ),
					7 => array( 'include/general' ),
				),
			),
			get_option( 'elementor_pro_theme_builder_conditions' ),
			"The earlier crashed regenerate() must not have wiped templates 5 and 7 when template 9 was purged afterward — the purge has to refresh from the option first, not trust this request's possibly-stale in-memory cache."
		);
	}

	// ---------------------------------------------------------------
	// Mcpwp_Theme_Builder::handle_before_delete_post() — WARN-1's fix.
	// Covers every path that reaches wp_delete_post() with ONE hook: the
	// exact route #952 reported (delete_template()), the two OTHER routes
	// adversarial review found reach elementor_library with no cleanup of
	// their own (the generic /content and /posts endpoints), and a raw
	// wp_delete_post() call standing in for wp-admin, WP-CLI, or another
	// plugin — none of which MCPWP's own code can intercept except through
	// this WordPress-level hook.
	// ---------------------------------------------------------------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_delete_template_cleans_the_cache_once_the_hook_is_wired(): void {
		$this->install_elementor_pro_doubles();
		$this->wire_before_delete_post_hook();

		$this->seed_template( 42 );
		update_option( 'elementor_pro_theme_builder_conditions', array( 'header' => array( 42 => array( 'include/general' ) ) ) );

		$result = ( new Mcpwp_Elementor_Pro() )->delete_template( 42, true );

		$this->assertTrue( $result );
		$this->assertSame( array(), get_option( 'elementor_pro_theme_builder_conditions' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_trashing_a_template_also_cleans_the_cache_via_the_hook(): void {
		// Elementor never actually trashes elementor_library (#536 round 5:
		// the test double for wp_delete_post mirrors that — only 'post' and
		// 'page' trash on force=false). The cleanup must not depend on
		// which happened.
		$this->install_elementor_pro_doubles();
		$this->wire_before_delete_post_hook();

		$this->seed_template( 43 );
		update_option( 'elementor_pro_theme_builder_conditions', array( 'header' => array( 43 => array( 'include/general' ) ) ) );

		$result = ( new Mcpwp_Elementor_Pro() )->delete_template( 43, false );

		$this->assertTrue( $result );
		$this->assertSame( array(), get_option( 'elementor_pro_theme_builder_conditions' ) );
	}

	/**
	 * WARN-1's exact uncovered path: the generic /posts/{id} route accepts
	 * elementor_library (Mcpwp_Posts::validate_post_type() whitelists it)
	 * and called wp_delete_post() directly, with no cleanup of its own.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_deleting_an_elementor_library_post_through_the_generic_posts_route_cleans_the_cache(): void {
		$this->install_elementor_pro_doubles();
		$this->wire_before_delete_post_hook();

		$this->seed_template( 60 );
		update_option( 'elementor_pro_theme_builder_conditions', array( 'header' => array( 60 => array( 'include/general' ) ) ) );

		$request = new WP_REST_Request( 'DELETE', '/mcpwp/v1/posts/60', array( 'id' => 60, 'force' => true ) );

		$response = ( new Mcpwp_REST_Posts() )->delete_post( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response, is_wp_error( $response ) ? $response->get_error_message() : '' );
		$this->assertSame( array(), get_option( 'elementor_pro_theme_builder_conditions' ) );
	}

	/**
	 * WARN-1's core claim: the hook covers a path MCPWP does not own at
	 * all, not only the routes it does. No MCPWP controller is called
	 * here — this stands in for wp-admin's post-delete screen, WP-CLI, or
	 * another plugin.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_bare_wp_delete_post_call_with_no_mcpwp_route_involved_cleans_the_cache(): void {
		$this->install_elementor_pro_doubles();
		$this->wire_before_delete_post_hook();

		$this->seed_template( 61 );
		update_option( 'elementor_pro_theme_builder_conditions', array( 'header' => array( 61 => array( 'include/general' ) ) ) );

		$result = wp_delete_post( 61, true );

		$this->assertNotFalse( $result );
		$this->assertSame( array(), get_option( 'elementor_pro_theme_builder_conditions' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_hook_ignores_a_delete_of_any_other_post_type(): void {
		// Deliberately the SAME numeric id a real elementor_library
		// template would use, and no Elementor Pro doubles installed at
		// all: the post_type check has to gate this before
		// is_available() is ever asked, or this would fatal instead of
		// silently doing nothing.
		$this->wire_before_delete_post_hook();

		$this->seed_post( 62, 'page' );
		update_option( 'elementor_pro_theme_builder_conditions', array( 'header' => array( 62 => array( 'include/general' ) ) ) );

		wp_delete_post( 62, true );

		$this->assertSame(
			array( 'header' => array( 62 => array( 'include/general' ) ) ),
			get_option( 'elementor_pro_theme_builder_conditions' ),
			'A page delete must never touch the theme-builder conditions cache, hook wired or not.'
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_deleting_a_template_absent_from_the_cache_does_not_call_purge(): void {
		$this->install_elementor_pro_doubles();
		$this->wire_before_delete_post_hook();

		$this->seed_template( 44 );
		update_option( 'elementor_pro_theme_builder_conditions', array( 'header' => array( 5 => array( 'include/general' ) ) ) );

		$result = ( new Mcpwp_Elementor_Pro() )->delete_template( 44, true );

		$this->assertTrue( $result );
		$this->assertSame( 0, $GLOBALS['mcpwp_theme_test_cache_purge_calls'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_broken_cache_purge_does_not_block_the_delete_itself(): void {
		$this->install_elementor_pro_doubles();
		$this->wire_before_delete_post_hook();
		$GLOBALS['mcpwp_theme_test_purge_throws'] = true;

		$this->seed_template( 63 );
		update_option( 'elementor_pro_theme_builder_conditions', array( 'header' => array( 63 => array( 'include/general' ) ) ) );

		$result = ( new Mcpwp_Elementor_Pro() )->delete_template( 63, true );

		$this->assertTrue( $result, 'A crash in the best-effort cache purge must not fail the delete itself.' );
	}

	// ---------------------------------------------------------------
	// Mcpwp_REST_Content::delete_content() — the generic /content endpoint
	// reaches elementor_library too (it has no dedicated CPT).
	// ---------------------------------------------------------------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_deleting_an_elementor_library_post_through_the_generic_content_endpoint_cleans_the_cache(): void {
		$this->install_elementor_pro_doubles();
		$this->wire_before_delete_post_hook();

		$this->seed_post( 50, 'elementor_library' );
		update_option( 'elementor_pro_theme_builder_conditions', array( 'header' => array( 50 => array( 'include/general' ) ) ) );

		$request = new WP_REST_Request(
			'DELETE',
			'/mcpwp/v1/content/elementor_library/50',
			array(
				'post_type' => 'elementor_library',
				'id'        => 50,
				'force'     => true,
			)
		);

		$response = ( new Mcpwp_REST_Content() )->delete_content( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response, is_wp_error( $response ) ? $response->get_error_message() : '' );
		$this->assertSame( array(), get_option( 'elementor_pro_theme_builder_conditions' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_deleting_a_page_through_the_generic_content_endpoint_never_touches_the_cache(): void {
		// Deliberately the SAME numeric id a real elementor_library
		// template would use — only an elementor_library delete may ever
		// reach the cleanup, and this proves it is not falling through on
		// id alone. The hook is wired (unlike the plain post_type-only
		// double-check above, this also proves the ROUTE itself never
		// short-circuits into cleanup on its own — everything runs through
		// the one shared hook).
		$this->wire_before_delete_post_hook();

		$this->seed_post( 51, 'page' );
		update_option( 'elementor_pro_theme_builder_conditions', array( 'header' => array( 51 => array( 'include/general' ) ) ) );

		$request = new WP_REST_Request(
			'DELETE',
			'/mcpwp/v1/content/page/51',
			array(
				'post_type' => 'page',
				'id'        => 51,
				'force'     => true,
			)
		);

		( new Mcpwp_REST_Content() )->delete_content( $request );

		$this->assertSame(
			array( 'header' => array( 51 => array( 'include/general' ) ) ),
			get_option( 'elementor_pro_theme_builder_conditions' ),
			'Deleting a page must never touch the theme-builder conditions cache.'
		);
	}

	// ---------------------------------------------------------------
	// Mcpwp_Theme_Builder::create_theme_template()'s own rollback delete —
	// its wp_delete_post( $template_id, true ) now runs through the same
	// before_delete_post hook as everything else, covering the case where
	// assign_to_location() already registered the template in the cache
	// before a later step in that same method throws. Not asserted with a
	// dedicated end-to-end test here: reaching that exact crash through the
	// real set_template_conditions() call also runs the pre-existing,
	// unrelated update_global_conditions_index() in between — which writes
	// the *wrong* axis into this same option and is normally harmless only
	// because the regenerate() call right after it overwrites the mistake.
	// Forcing regenerate() itself to throw (the only way to reach this
	// rollback branch at all) means that repair never happens, so the cache
	// is left holding the template's ID under an axis
	// cleanup_after_template_delete() cannot recognise — a real
	// interaction with that pre-existing, out-of-scope bug, not a gap in
	// this fix or in handle_before_delete_post()'s wiring.
	// ---------------------------------------------------------------
}
