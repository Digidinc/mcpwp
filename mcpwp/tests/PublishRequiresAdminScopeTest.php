<?php

use PHPUnit\Framework\TestCase;

/**
 * #607 invariant 3 — "Publishing requires publish_posts or publish_pages;
 * never infer it from generic write scope."
 *
 * A WordPress current_user_can('publish_posts') / ('publish_pages') check
 * cannot enforce this on its own: EVERY API key, whatever scope it declares,
 * authenticates as the same shared mcpwp_api_agent WP user (see
 * class-mcpwp-activator.php's add_role() call), and that role has been
 * granted publish_posts/publish_pages unconditionally since 3.11 — widened
 * further by #861's edit_published_posts/edit_published_pages grant in
 * 3.12.0. So current_user_can('publish_posts') is always true regardless of
 * which key made the request; the key's own declared scope tier is the ONLY
 * real authorization boundary this plugin has.
 *
 * Before this fix, creating or updating a post/page (singular or bulk) with
 * status=publish, or cloning a page with status=publish, required only
 * 'write' scope — the same tier a key needs to save a draft. These tests
 * lock in that publishing now requires 'admin' scope, while ordinary
 * (non-publishing) writes stay reachable on 'write' scope exactly as before.
 *
 * @package MCPWP
 */

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-posts.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-pages.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-posts.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-pages.php';

final class PublishRequiresAdminScopeTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']            = array();
		$GLOBALS['mcpwp_test_transients']         = array();
		$GLOBALS['mcpwp_test_current_user']       = 0;
		$GLOBALS['mcpwp_test_is_admin']           = false;
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		// The ids these requests name must exist: since #1030 round-5 the
		// declared-post-ref guard answers a missing id itself, before scope.
		$GLOBALS['_mcpwp_test_posts'] = array(
			5 => new WP_Post( array( 'ID' => 5, 'post_type' => 'post', 'post_status' => 'publish' ) ),
			9 => new WP_Post( array( 'ID' => 9, 'post_type' => 'page', 'post_status' => 'publish' ) ),
		);
		Mcpwp_Api_Auth_Verdict_Reset::reset();
	}

	private function write_key( $controller ): string {
		return $controller->create_scoped_api_key( 'write key', array( 'write' ), 'custom' )['key'];
	}

	private function admin_key( $controller ): string {
		return $controller->create_scoped_api_key( 'admin key', array( 'admin' ), 'admin' )['key'];
	}

	// ── POST /posts (create) ────────────────────────────────────────────────

	public function test_write_key_cannot_create_a_published_post(): void {
		$controller = new Mcpwp_REST_Posts();
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/posts',
			array(
				'title'  => 'Hello',
				'status' => 'publish',
			),
			array( 'X-API-Key' => $key )
		);

		$result = $controller->verify_api_key( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
	}

	public function test_write_key_can_still_create_a_draft_post(): void {
		$controller = new Mcpwp_REST_Posts();
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/posts',
			array(
				'title'  => 'Hello',
				'status' => 'draft',
			),
			array( 'X-API-Key' => $key )
		);

		$this->assertTrue( $controller->verify_api_key( $request ), 'A write key must still be able to save a draft.' );
	}

	public function test_write_key_can_create_a_post_with_no_status_param_at_all(): void {
		$controller = new Mcpwp_REST_Posts();
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/posts',
			array( 'title' => 'Hello' ),
			array( 'X-API-Key' => $key )
		);

		$this->assertTrue( $controller->verify_api_key( $request ) );
	}

	public function test_admin_key_can_create_a_published_post(): void {
		$controller = new Mcpwp_REST_Posts();
		$key        = $this->admin_key( $controller );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/posts',
			array(
				'title'  => 'Hello',
				'status' => 'publish',
			),
			array( 'X-API-Key' => $key )
		);

		$this->assertTrue( $controller->verify_api_key( $request ) );
	}

	public function test_status_check_is_case_insensitive(): void {
		$controller = new Mcpwp_REST_Posts();
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/posts',
			array(
				'title'  => 'Hello',
				'status' => 'PUBLISH',
			),
			array( 'X-API-Key' => $key )
		);

		$result = $controller->verify_api_key( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
	}

	// ── PUT /posts/{id} (update) ─────────────────────────────────────────────

	public function test_write_key_cannot_transition_a_post_to_published_on_update(): void {
		$controller = new Mcpwp_REST_Posts();
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'PUT',
			'/mcpwp/v1/posts/5',
			array(
				'id'     => 5,
				'status' => 'publish',
			),
			array( 'X-API-Key' => $key )
		);

		$result = $controller->verify_api_key( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
	}

	public function test_write_key_can_still_update_a_post_title_only(): void {
		$controller = new Mcpwp_REST_Posts();
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'PUT',
			'/mcpwp/v1/posts/5',
			array(
				'id'    => 5,
				'title' => 'New title',
			),
			array( 'X-API-Key' => $key )
		);

		$this->assertTrue( $controller->verify_api_key( $request ) );
	}

	// ── /posts/bulk ──────────────────────────────────────────────────────────

	public function test_write_key_cannot_bulk_create_posts_with_a_published_item(): void {
		$controller = new Mcpwp_REST_Posts();
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/posts/bulk',
			array(
				'posts' => array(
					array( 'title' => 'Draft one', 'status' => 'draft' ),
					array( 'title' => 'Sneaky one', 'status' => 'publish' ),
				),
			),
			array( 'X-API-Key' => $key )
		);

		$result = $controller->verify_api_key( $request );
		$this->assertInstanceOf(
			WP_Error::class,
			$result,
			'A published item anywhere in a bulk-create array must require admin scope, not just the first item.'
		);
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
	}

	public function test_write_key_can_bulk_create_all_draft_posts(): void {
		$controller = new Mcpwp_REST_Posts();
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/posts/bulk',
			array(
				'posts' => array(
					array( 'title' => 'Draft one', 'status' => 'draft' ),
					array( 'title' => 'Draft two' ),
				),
			),
			array( 'X-API-Key' => $key )
		);

		$this->assertTrue( $controller->verify_api_key( $request ) );
	}

	public function test_write_key_cannot_bulk_update_posts_via_the_updates_alias_to_published(): void {
		$controller = new Mcpwp_REST_Posts();
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'PUT',
			'/mcpwp/v1/posts/bulk',
			array(
				'updates' => array(
					array( 'id' => 1, 'status' => 'publish' ),
				),
			),
			array( 'X-API-Key' => $key )
		);

		$result = $controller->verify_api_key( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
	}

	// ── /pages ───────────────────────────────────────────────────────────────

	public function test_write_key_cannot_create_a_published_page(): void {
		$controller = new Mcpwp_REST_Pages();
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/pages',
			array(
				'title'  => 'Hello',
				'status' => 'publish',
			),
			array( 'X-API-Key' => $key )
		);

		$result = $controller->verify_api_key( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
	}

	public function test_write_key_cannot_bulk_create_pages_with_a_published_item(): void {
		$controller = new Mcpwp_REST_Pages();
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/pages/bulk',
			array(
				'pages' => array(
					array( 'title' => 'Sneaky one', 'status' => 'publish' ),
				),
			),
			array( 'X-API-Key' => $key )
		);

		$result = $controller->verify_api_key( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
	}

	public function test_write_key_cannot_clone_a_page_to_published_status(): void {
		$controller = new Mcpwp_REST_Pages();
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/pages/9/clone',
			array(
				'id'     => 9,
				'status' => 'publish',
			),
			array( 'X-API-Key' => $key )
		);

		$result = $controller->verify_api_key( $request );
		$this->assertInstanceOf(
			WP_Error::class,
			$result,
			'/pages/{id}/clone accepts its own status param (default draft) — cloning to publish must also require admin scope.'
		);
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
	}

	public function test_write_key_can_clone_a_page_to_draft_status(): void {
		$controller = new Mcpwp_REST_Pages();
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/pages/9/clone',
			array( 'id' => 9 ),
			array( 'X-API-Key' => $key )
		);

		$this->assertTrue( $controller->verify_api_key( $request ) );
	}

	// ── WARN 3 (RC 3.12.1 adversarial review): 'private' is publishing too ──

	public function test_write_key_cannot_create_a_private_post(): void {
		$controller = new Mcpwp_REST_Posts();
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/posts',
			array(
				'title'  => 'Hello',
				'status' => 'private',
			),
			array( 'X-API-Key' => $key )
		);

		$result = $controller->verify_api_key( $request );
		$this->assertInstanceOf(
			WP_Error::class,
			$result,
			"'private' is a real, non-draft state visible to editors/admins — it must require admin scope exactly like 'publish'."
		);
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
	}

	public function test_write_key_cannot_update_a_page_to_private(): void {
		$controller = new Mcpwp_REST_Pages();
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'PUT',
			'/mcpwp/v1/pages/5',
			array(
				'id'     => 5,
				'status' => 'private',
			),
			array( 'X-API-Key' => $key )
		);

		$result = $controller->verify_api_key( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
	}

	public function test_write_key_cannot_clone_a_page_to_private_status(): void {
		$controller = new Mcpwp_REST_Pages();
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/pages/9/clone',
			array(
				'id'     => 9,
				'status' => 'private',
			),
			array( 'X-API-Key' => $key )
		);

		$result = $controller->verify_api_key( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
	}

	public function test_admin_key_can_create_a_private_post(): void {
		$controller = new Mcpwp_REST_Posts();
		$key        = $this->admin_key( $controller );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/posts',
			array(
				'title'  => 'Hello',
				'status' => 'private',
			),
			array( 'X-API-Key' => $key )
		);

		$this->assertTrue( $controller->verify_api_key( $request ) );
	}

	// ── Unrelated sub-routes must never be swept in by the prefix match ──────

	public function test_write_key_can_still_set_featured_image_no_status_param_involved(): void {
		$controller = new Mcpwp_REST_Posts();
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/posts/5/featured-image',
			array(
				'id'       => 5,
				'media_id' => 42,
			),
			array( 'X-API-Key' => $key )
		);

		$this->assertTrue( $controller->verify_api_key( $request ) );
	}
}

/**
 * verify_api_key() memoises its verdict per request OBJECT id
 * (Mcpwp_Api_Auth::$mcpwp_verdicts, a static). Object ids can be reused
 * across tests within one process once PHP garbage-collects an earlier
 * WP_REST_Request, which would otherwise let one test's verdict leak into
 * another's assertion. Mcpwp_Api_Auth::reset_verdict_memo() is the
 * production-provided escape hatch for exactly this ("Tests only; a real
 * process serves one request.").
 */
final class Mcpwp_Api_Auth_Verdict_Reset {
	public static function reset(): void {
		if ( method_exists( 'Mcpwp_REST_API', 'reset_verdict_memo' ) ) {
			Mcpwp_REST_API::reset_verdict_memo();
		}
	}
}
