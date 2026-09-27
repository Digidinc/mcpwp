<?php

use PHPUnit\Framework\TestCase;

/**
 * GH #873 — the scoped `unfiltered_html` grant around the Custom Code write.
 *
 * Elementor registers `_elementor_code` with a sanitize_callback that runs
 * wp_kses_post() unless the writing user holds `unfiltered_html`, and
 * update_post_meta() honours registered sanitize callbacks. That is what
 * stripped <script> from every snippet MCPWP wrote, making GA4/GTM tags — the
 * feature's primary use case — silently inert.
 *
 * These tests pin the three properties that make the grant defensible: it is
 * taken only when kses would actually destroy the content, it never outlives
 * the one meta write (including when that write throws), and it is refused on
 * multisite, where granting it would hand the API user more than the site's own
 * administrator holds.
 */
final class CustomCodeUnfilteredHtmlGrantTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']         = array();
		$GLOBALS['mcpwp_test_transients']      = array();
		$GLOBALS['_mcpwp_test_posts']          = array();
		$GLOBALS['_mcpwp_test_meta']           = array();
		$GLOBALS['mcpwp_test_current_user']    = 1;
		$GLOBALS['mcpwp_test_is_multisite']    = false;
		$GLOBALS['mcpwp_test_is_super_admin']  = false;
		$GLOBALS['mcpwp_test_filters']         = array();

		$this->set_static( 'custom_code_unfiltered_depth', 0 );
		$this->set_static( 'custom_code_unfiltered_user', 0 );
		$this->set_static( 'custom_code_shutdown_guard_registered', false );
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_is_multisite']   = false;
		$GLOBALS['mcpwp_test_is_super_admin'] = false;

		// remove_filter() on a tag that was never added is a safe no-op, so
		// this is defensive rather than load-bearing: it stops a test that
		// deliberately leaves the grant filter installed (simulating an
		// interrupted write) from leaking it into a later test in the same
		// process.
		remove_filter( 'user_has_cap', array( Mcpwp_REST_Elementor_Pro::class, 'grant_custom_code_unfiltered_html_cap' ), PHP_INT_MAX );

		$this->set_static( 'custom_code_unfiltered_depth', 0 );
		$this->set_static( 'custom_code_unfiltered_user', 0 );
		$this->set_static( 'custom_code_shutdown_guard_registered', false );
	}

	private function controller(): Mcpwp_REST_Elementor_Pro {
		return new Mcpwp_REST_Elementor_Pro( new stdClass() );
	}

	private function call( $method, array $args = array() ) {
		$ref = new ReflectionMethod( Mcpwp_REST_Elementor_Pro::class, $method );
		$ref->setAccessible( true );

		return $ref->invokeArgs( $this->controller(), $args );
	}

	private function set_static( $name, $value ): void {
		$prop = new ReflectionProperty( Mcpwp_REST_Elementor_Pro::class, $name );
		$prop->setAccessible( true );
		$prop->setValue( null, $value );
	}

	private function get_static( $name ) {
		$prop = new ReflectionProperty( Mcpwp_REST_Elementor_Pro::class, $name );
		$prop->setAccessible( true );

		return $prop->getValue();
	}

	// --- when the grant is needed at all -------------------------------------

	public function test_script_bearing_code_needs_the_grant(): void {
		$this->assertTrue(
			$this->call( 'code_needs_unfiltered_html', array( '<script>dataLayer.push({})</script>' ) )
		);
	}

	public function test_plain_markup_does_not_need_the_grant(): void {
		// The majority of snippets survive kses untouched. Those must not take
		// the capability at all — a grant nobody needed is pure blast radius.
		$this->assertFalse(
			$this->call( 'code_needs_unfiltered_html', array( '<p>hello world</p>' ) )
		);
	}

	public function test_empty_and_non_string_code_never_needs_the_grant(): void {
		$this->assertFalse( $this->call( 'code_needs_unfiltered_html', array( '' ) ) );
		$this->assertFalse( $this->call( 'code_needs_unfiltered_html', array( null ) ) );
		$this->assertFalse( $this->call( 'code_needs_unfiltered_html', array( array( 'x' ) ) ) );
	}

	// --- the capability filter itself ---------------------------------------

	public function test_grant_callback_adds_the_cap_only_for_the_captured_user(): void {
		$this->set_static( 'custom_code_unfiltered_depth', 1 );
		$this->set_static( 'custom_code_unfiltered_user', 7 );

		$target = new WP_User( 7 );
		$other  = new WP_User( 8 );

		$granted = Mcpwp_REST_Elementor_Pro::grant_custom_code_unfiltered_html_cap( array(), array(), array(), $target );
		$this->assertTrue( ! empty( $granted['unfiltered_html'] ) );

		// A capability check performed for some OTHER user during the same call
		// — a revision author, a hook walking users — must not pick this up.
		$untouched = Mcpwp_REST_Elementor_Pro::grant_custom_code_unfiltered_html_cap( array(), array(), array(), $other );
		$this->assertArrayNotHasKey( 'unfiltered_html', $untouched );
	}

	public function test_grant_callback_is_inert_when_no_write_is_in_flight(): void {
		$this->set_static( 'custom_code_unfiltered_depth', 0 );
		$this->set_static( 'custom_code_unfiltered_user', 7 );

		$caps = Mcpwp_REST_Elementor_Pro::grant_custom_code_unfiltered_html_cap( array(), array(), array(), new WP_User( 7 ) );

		$this->assertArrayNotHasKey( 'unfiltered_html', $caps );
	}

	// --- lifecycle: the grant must not outlive the write --------------------

	public function test_grant_is_released_after_a_script_bearing_write(): void {
		$this->call( 'write_custom_code_meta', array( 101, '<script>a()</script>' ) );

		$this->assertSame( 0, $this->get_static( 'custom_code_unfiltered_depth' ) );
		$this->assertSame( 0, $this->get_static( 'custom_code_unfiltered_user' ) );
		$this->assertSame( '<script>a()</script>', get_post_meta( 101, '_elementor_code', true ) );
	}

	public function test_plain_write_never_installs_the_grant(): void {
		$this->call( 'write_custom_code_meta', array( 102, '<p>plain</p>' ) );

		$this->assertSame( 0, $this->get_static( 'custom_code_unfiltered_depth' ) );
		$this->assertSame( 0, $this->get_static( 'custom_code_unfiltered_user' ) );
		$this->assertSame( '<p>plain</p>', get_post_meta( 102, '_elementor_code', true ) );
	}

	public function test_grant_is_released_when_the_write_throws(): void {
		// The whole point of the `finally`. A Throwable from update_post_meta —
		// a sanitize callback that errors, a hook that blows up — must not leave
		// `unfiltered_html` installed for the rest of the request.
		$GLOBALS['mcpwp_test_update_post_meta_throws'] = true;

		try {
			$this->call( 'write_custom_code_meta', array( 103, '<script>a()</script>' ) );
			$this->fail( 'expected the seeded Throwable to propagate' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'seeded meta failure', $e->getMessage() );
		} finally {
			unset( $GLOBALS['mcpwp_test_update_post_meta_throws'] );
		}

		$this->assertSame( 0, $this->get_static( 'custom_code_unfiltered_depth' ) );
		$this->assertSame( 0, $this->get_static( 'custom_code_unfiltered_user' ) );
	}

	// --- WARN (RC review): finally is not exit()/die()-safe ------------------
	//
	// finally never runs on exit()/die() — a security/WAF plugin calling
	// wp_die() synchronously from `updated_postmeta` on script-bearing
	// content is the realistic trigger. On a long-lived worker
	// (WP-CLI, a rest_do_request() loop — every authenticated caller maps
	// onto the same mcpwp_api_agent account) that would leak `unfiltered_html`
	// to the rest of the process. register_shutdown_function(), unlike
	// finally, DOES run on exit()/die(), so
	// ensure_custom_code_shutdown_guard() registers a cleanup callback as a
	// safety net. exit()/die() itself can't be exercised inside a PHPUnit
	// process (it would kill the test runner), so these tests exercise the
	// guard's registration and the cleanup callback directly instead of
	// trying to reproduce the termination itself.

	public function test_write_custom_code_meta_registers_the_shutdown_guard(): void {
		$this->assertFalse( $this->get_static( 'custom_code_shutdown_guard_registered' ) );

		$this->call( 'write_custom_code_meta', array( 104, '<script>a()</script>' ) );

		$this->assertTrue( $this->get_static( 'custom_code_shutdown_guard_registered' ) );
	}

	public function test_shutdown_guard_registration_is_idempotent(): void {
		// register_shutdown_function() has no unregister — calling this
		// unconditionally per write would accumulate one registered callback
		// per Custom Code write for the life of a long-lived worker. Calling
		// it repeatedly must be safe and must not un-flip the guard.
		$ref = new ReflectionMethod( Mcpwp_REST_Elementor_Pro::class, 'ensure_custom_code_shutdown_guard' );
		$ref->setAccessible( true );

		$ref->invoke( null );
		$ref->invoke( null );
		$ref->invoke( null );

		$this->assertTrue( $this->get_static( 'custom_code_shutdown_guard_registered' ) );
	}

	public function test_shutdown_cleanup_is_a_noop_after_a_normal_completed_write(): void {
		$this->call( 'write_custom_code_meta', array( 105, '<script>a()</script>' ) );
		$this->assertSame( 0, $this->get_static( 'custom_code_unfiltered_depth' ), 'sanity: finally already cleaned up' );

		// By the time PHP's real shutdown sequence reaches this callback on
		// any ordinary request, depth is already back to 0 — this is that
		// case, and it must change nothing.
		Mcpwp_REST_Elementor_Pro::cleanup_custom_code_unfiltered_html_grant_on_shutdown();

		$this->assertSame( 0, $this->get_static( 'custom_code_unfiltered_depth' ) );
		$this->assertSame( 0, $this->get_static( 'custom_code_unfiltered_user' ) );
	}

	public function test_shutdown_cleanup_closes_a_grant_left_by_an_interrupted_write(): void {
		// Simulates exit()/die() firing partway through write_custom_code_meta(),
		// after the grant was installed but before its `finally` ran: depth
		// incremented, user captured, filter installed — exactly the state
		// that method leaves behind up to the point of interruption.
		$this->set_static( 'custom_code_unfiltered_depth', 1 );
		$this->set_static( 'custom_code_unfiltered_user', 1 );
		add_filter( 'user_has_cap', array( Mcpwp_REST_Elementor_Pro::class, 'grant_custom_code_unfiltered_html_cap' ), PHP_INT_MAX, 4 );

		// Prove the grant is live before cleanup — otherwise "cleanup made no
		// difference" would be indistinguishable from "there was nothing to
		// clean up".
		$granted_before = Mcpwp_REST_Elementor_Pro::grant_custom_code_unfiltered_html_cap( array(), array(), array(), new WP_User( 1 ) );
		$this->assertTrue( ! empty( $granted_before['unfiltered_html'] ) );

		Mcpwp_REST_Elementor_Pro::cleanup_custom_code_unfiltered_html_grant_on_shutdown();

		$this->assertSame( 0, $this->get_static( 'custom_code_unfiltered_depth' ) );
		$this->assertSame( 0, $this->get_static( 'custom_code_unfiltered_user' ) );
		$this->assertFalse( has_filter( 'user_has_cap', array( Mcpwp_REST_Elementor_Pro::class, 'grant_custom_code_unfiltered_html_cap' ) ) );

		// The consequence that actually matters: the same user_id that held
		// the leaked grant no longer gets it.
		$granted_after = Mcpwp_REST_Elementor_Pro::grant_custom_code_unfiltered_html_cap( array(), array(), array(), new WP_User( 1 ) );
		$this->assertArrayNotHasKey( 'unfiltered_html', $granted_after );
	}

	// --- LOW (RC review): size guard ahead of the kses diff-check -----------

	public function test_custom_code_write_over_the_length_cap_is_refused(): void {
		$controller = $this->controller();
		$oversized  = str_repeat( 'a', Mcpwp_REST_Elementor_Pro::CUSTOM_CODE_MAX_LENGTH + 1 );

		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/elementor/custom-code', array(
			'title' => 'Too big',
			'code'  => $oversized,
		) );

		$result = $controller->create_custom_code( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'code_too_large', $result->get_error_code() );
		// Refused before wp_insert_post(), same reasoning as the multisite
		// refusal test above: no orphan snippet post left behind.
		$this->assertSame( array(), $GLOBALS['_mcpwp_test_posts'] );
	}

	public function test_custom_code_write_at_the_length_cap_is_allowed(): void {
		$controller  = $this->controller();
		$at_the_cap  = str_repeat( 'a', Mcpwp_REST_Elementor_Pro::CUSTOM_CODE_MAX_LENGTH );

		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/elementor/custom-code', array(
			'title' => 'Right at the limit',
			'code'  => $at_the_cap,
		) );

		$result = $controller->create_custom_code( $request );

		if ( is_wp_error( $result ) ) {
			$this->assertNotSame( 'code_too_large', $result->get_error_code(), 'exactly the cap must not be refused for size' );
		} else {
			$this->assertNotEmpty( $GLOBALS['_mcpwp_test_posts'], 'a snippet post should have been created at exactly the cap' );
		}
	}

	// --- multisite refusal ---------------------------------------------------

	public function test_multisite_refuses_a_write_that_would_need_the_grant(): void {
		$GLOBALS['mcpwp_test_is_multisite'] = true;

		$result = $this->call( 'custom_code_unfiltered_html_refusal', array( '<script>a()</script>' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'multisite_unfiltered_html_refused', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_multisite_allows_a_write_that_survives_kses(): void {
		// The refusal is narrow on purpose: a snippet kses would not alter needs
		// no capability, so multisite is no obstacle and we do not invent one.
		$GLOBALS['mcpwp_test_is_multisite'] = true;

		$this->assertTrue( $this->call( 'custom_code_unfiltered_html_refusal', array( '<p>plain</p>' ) ) );
	}

	public function test_multisite_super_admin_is_allowed(): void {
		$GLOBALS['mcpwp_test_is_multisite']   = true;
		$GLOBALS['mcpwp_test_is_super_admin'] = true;

		$this->assertTrue( $this->call( 'custom_code_unfiltered_html_refusal', array( '<script>a()</script>' ) ) );
	}

	public function test_single_site_is_allowed(): void {
		$GLOBALS['mcpwp_test_is_multisite'] = false;

		$this->assertTrue( $this->call( 'custom_code_unfiltered_html_refusal', array( '<script>a()</script>' ) ) );
	}

	public function test_create_request_is_refused_on_multisite_before_any_post_is_created(): void {
		$GLOBALS['mcpwp_test_is_multisite'] = true;

		$controller = $this->controller();
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/elementor/custom-code',
			array(
				'title'    => 'GA4',
				'code'     => '<script>window.dataLayer=[]</script>',
				'location' => 'body_end',
			)
		);

		$result = $controller->create_custom_code( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'multisite_unfiltered_html_refused', $result->get_error_code() );
		// Refusing after wp_insert_post() would strand an orphan snippet post,
		// so the check has to run during payload preparation, not at write time.
		$this->assertSame( array(), $GLOBALS['_mcpwp_test_posts'] );
	}

	// --- the scope gate in front of all of this ------------------------------

	public function test_custom_code_route_is_admin_scoped(): void {
		// The grant is only defensible because a designer-, author- or
		// editor-scoped key cannot reach this route at all. If this ever stops
		// being true, the grant becomes reachable by a narrow key.
		$controller = $this->controller();
		$ref        = new ReflectionMethod( $controller, 'get_required_scope_for_request' );
		$ref->setAccessible( true );

		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/elementor/custom-code' );
		$scope   = $ref->invoke( $controller, $request );

		$this->assertSame( 'admin', $scope );
	}
}
