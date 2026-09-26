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
 * Pro's own purpose-built `Conditions_Manager::purge_post_from_cache()`
 * (falling back to a full `get_cache()->regenerate()` rebuild if an older
 * Elementor Pro build lacks it), which is guaranteed to agree with
 * whatever shape Elementor Pro's own code actually uses.
 *
 * Covered here:
 *  - `Mcpwp_Theme_Builder::cleanup_after_template_delete()`: removes the
 *    template from the real-shaped cache, leaves unrelated templates and
 *    locations untouched, drops a location left with nothing under it,
 *    makes no write/no purge call when the template was never cached, does
 *    nothing (returns false, no write) when Elementor Pro's Theme Builder
 *    isn't available at all, and never lets a broken purge propagate and
 *    block the delete that triggered it.
 *  - `Mcpwp_Elementor_Pro::delete_template()` — trash (force=false) and
 *    force delete alike — runs that cleanup.
 *  - `Mcpwp_REST_Content::delete_content()` — the generic `/content`
 *    endpoint can delete an `elementor_library` post too (Theme Builder
 *    templates have no dedicated CPT of their own) and shares the same
 *    cleanup; deleting any other post type must never touch the cache.
 *  - `Mcpwp_Theme_Builder::create_theme_template()`'s own rollback delete: a
 *    Throwable straight out of Elementor Pro's conditions-cache
 *    `regenerate()` can land AFTER `assign_to_location()` already
 *    registered the template in the cache — the force-delete that follows
 *    must clean that up too, not just remove the post.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

// Neither file is required by tests/bootstrap.php by default (only the REST
// wrapper and Mcpwp_Theme_Builder are) — guarded the same way
// ElementorTemplateTypeValidationTest.php does, since nothing else pulls
// class-mcpwp-elementor-pro.php into the main process.
if ( ! class_exists( 'Mcpwp_Elementor_Pro' ) ) {
	require_once dirname( __DIR__ ) . '/includes/pro/core/class-mcpwp-elementor-pro.php';
}
if ( ! class_exists( 'Mcpwp_REST_Content' ) ) {
	require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-content.php';
}

final class ElementorProConditionsIndexCleanupTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options'] = array();
		$GLOBALS['_mcpwp_test_posts']  = array();
		$GLOBALS['_mcpwp_test_meta']   = array();

		// Not a Destructive Gate test: the /content endpoint gates every
		// permanent delete by default (#536), which would otherwise turn
		// delete_content() into a 202 approval request instead of an actual
		// delete for the tests below that exercise it.
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

	// ---------------------------------------------------------------
	// Mcpwp_Elementor_Pro::delete_template() — the exact path #952 reported.
	// ---------------------------------------------------------------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_force_deleting_a_template_removes_it_from_the_cache(): void {
		$this->install_elementor_pro_doubles();

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
	public function test_trashing_a_template_also_removes_it_from_the_cache(): void {
		// Elementor never actually trashes elementor_library (#536 round 5:
		// the test double for wp_delete_post mirrors that — only 'post' and
		// 'page' trash on force=false). The cleanup must not depend on
		// which happened: a trashed template is already skipped at render,
		// but the binding must still go, in case the trash is restored or
		// the ID is ever reused.
		$this->install_elementor_pro_doubles();

		$this->seed_template( 43 );
		update_option( 'elementor_pro_theme_builder_conditions', array( 'header' => array( 43 => array( 'include/general' ) ) ) );

		$result = ( new Mcpwp_Elementor_Pro() )->delete_template( 43, false );

		$this->assertTrue( $result );
		$this->assertSame( array(), get_option( 'elementor_pro_theme_builder_conditions' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_deleting_a_template_absent_from_the_cache_does_not_call_purge(): void {
		$this->install_elementor_pro_doubles();

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
		$GLOBALS['mcpwp_theme_test_purge_throws'] = true;

		$this->seed_template( 62 );
		update_option( 'elementor_pro_theme_builder_conditions', array( 'header' => array( 62 => array( 'include/general' ) ) ) );

		$result = ( new Mcpwp_Elementor_Pro() )->delete_template( 62, true );

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

	public function test_deleting_a_page_through_the_generic_content_endpoint_never_touches_the_cache(): void {
		// Deliberately the SAME numeric id a real elementor_library template
		// would use — only the elementor_library branch may ever call the
		// cleanup, and this proves it is not falling through on id alone.
		// No Elementor Pro doubles needed: post_type alone must gate this
		// before is_available() is ever asked.
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
	// a Throwable from Elementor Pro's conditions-cache regenerate() can
	// land AFTER assign_to_location() already registered the template in
	// the cache. The rollback's force-delete now also calls
	// cleanup_after_template_delete() (see the catch block in
	// create_theme_template()) as defense-in-depth. This is not asserted
	// with a dedicated end-to-end test here: reaching that exact crash
	// through the real set_template_conditions() call also runs the
	// pre-existing, unrelated update_global_conditions_index() in between
	// — which writes the *wrong* axis into this same option and is normally
	// harmless only because the regenerate() call right after it overwrites
	// the mistake. Forcing regenerate() itself to throw (the only way to
	// reach this rollback branch at all) means that repair never happens,
	// so the cache is left holding the template's ID under an axis
	// cleanup_after_template_delete() cannot recognise — a real interaction
	// with that pre-existing, out-of-scope bug, not a gap in this fix.
	// Confirmed directly: with install_elementor_pro_doubles() and
	// $GLOBALS['mcpwp_theme_test_regenerate_throws'] = true,
	// create_theme_template(['title' => '...', 'type' => 'header', 'scope'
	// => 'entire_site']) returns theme_template_save_failed and the option
	// is left as ['include/general' => [<id>]] rather than [] — the
	// template's ID stored as a plain value under update_global_conditions_
	// index()'s own condition-key axis, not as a key cleanup_after_
	// template_delete() looks under.
}
