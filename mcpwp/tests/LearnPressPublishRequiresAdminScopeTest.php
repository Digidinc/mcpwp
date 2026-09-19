<?php

use PHPUnit\Framework\TestCase;

/**
 * RC 3.12.1 adversarial review, BLOCK 1 — LearnPress courses/lessons/quizzes
 * were entirely outside the publish-scope gate.
 *
 * Same root cause as PublishRequiresAdminScopeTest.php (posts/pages) and
 * WooCommercePublishRequiresAdminScopeTest.php (products): every API key
 * authenticates as the same shared mcpwp_api_agent WP user, so a WordPress
 * capability check cannot gate publishing; the key's scope tier is the only
 * real boundary. Mcpwp_LearnPress::create_lesson() and create_quiz() default
 * 'status' to 'publish' when the caller sends NO status param at all — worse
 * than posts/pages (which default to 'draft') and closer to the WooCommerce
 * products case. This is fixed the same way as both of those: setting status
 * to 'publish' (or 'private' — see WARN 3 / param_value_targets_admin_scope())
 * now requires admin scope, and Mcpwp_REST_LearnPress::get_lesson_create_args()
 * / get_quiz_create_args() now declare that 'publish' default in the route
 * schema so it is visible to the scope check before the handler ever runs.
 *
 * @package MCPWP
 */

require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-learnpress.php';

final class LearnPressPublishRequiresAdminScopeTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']            = array();
		$GLOBALS['mcpwp_test_transients']         = array();
		$GLOBALS['mcpwp_test_current_user']       = 0;
		$GLOBALS['mcpwp_test_is_admin']           = false;
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		if ( method_exists( 'Mcpwp_REST_API', 'reset_verdict_memo' ) ) {
			Mcpwp_REST_API::reset_verdict_memo();
		}
	}

	private function controller(): Mcpwp_REST_LearnPress {
		// verify_api_key() never reaches the handler for these tests (it is
		// rejected — or accepted — at the permission_callback/scope-check
		// stage before any LearnPress call), so a null handler is safe here.
		return new Mcpwp_REST_LearnPress( null );
	}

	private function write_key( $controller ): string {
		return $controller->create_scoped_api_key( 'write key', array( 'write' ), 'custom' )['key'];
	}

	private function admin_key( $controller ): string {
		return $controller->create_scoped_api_key( 'admin key', array( 'admin' ), 'admin' )['key'];
	}

	// ── Courses (default status is 'draft' — the safe default) ─────────────

	public function test_write_key_cannot_create_a_published_course(): void {
		$controller = $this->controller();
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/learnpress/courses',
			array(
				'title'  => 'Intro to Widgets',
				'status' => 'publish',
			),
			array( 'X-API-Key' => $key )
		);

		$result = $controller->verify_api_key( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
	}

	public function test_write_key_can_create_a_course_with_no_status_param_at_all(): void {
		$controller = $this->controller();
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/learnpress/courses',
			array( 'title' => 'Intro to Widgets' ),
			array( 'X-API-Key' => $key )
		);

		$this->assertTrue(
			$controller->verify_api_key( $request ),
			'create_course() defaults to draft with no status param — a write key must still be able to do that.'
		);
	}

	public function test_admin_key_can_create_a_published_course(): void {
		$controller = $this->controller();
		$key        = $this->admin_key( $controller );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/learnpress/courses',
			array(
				'title'  => 'Intro to Widgets',
				'status' => 'publish',
			),
			array( 'X-API-Key' => $key )
		);

		$this->assertTrue( $controller->verify_api_key( $request ) );
	}

	public function test_write_key_cannot_update_a_course_to_published(): void {
		$controller = $this->controller();
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'PUT',
			'/mcpwp/v1/learnpress/courses/5',
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

	public function test_write_key_can_still_update_a_course_title_only(): void {
		$controller = $this->controller();
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'PUT',
			'/mcpwp/v1/learnpress/courses/5',
			array(
				'id'    => 5,
				'title' => 'New title',
			),
			array( 'X-API-Key' => $key )
		);

		$this->assertTrue( $controller->verify_api_key( $request ) );
	}

	public function test_write_key_can_still_set_curriculum_no_status_param_involved(): void {
		$controller = $this->controller();
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'PUT',
			'/mcpwp/v1/learnpress/courses/5/curriculum',
			array(
				'id'       => 5,
				'sections' => array(),
			),
			array( 'X-API-Key' => $key )
		);

		$this->assertTrue(
			$controller->verify_api_key( $request ),
			'The /courses prefix gate must not sweep in the unrelated curriculum sub-route.'
		);
	}

	// ── Lessons (dangerous default: status defaults to 'publish') ──────────

	public function test_write_key_cannot_create_a_published_lesson_explicitly(): void {
		$controller = $this->controller();
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/learnpress/lessons',
			array(
				'title'  => 'Lesson One',
				'status' => 'publish',
			),
			array( 'X-API-Key' => $key )
		);

		$result = $controller->verify_api_key( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
	}

	/**
	 * The dangerous default: create_lesson() defaults 'status' to 'publish'
	 * with ZERO arguments. This test simulates what real WordPress REST
	 * dispatch does — merge schema defaults into the request's params BEFORE
	 * the permission_callback runs (WP_REST_Server::dispatch() calls
	 * $request->set_default_params() ahead of the permission check) — since
	 * this suite's WP_REST_Request stub does not do that merging itself. See
	 * Mcpwp_REST_LearnPress::get_lesson_create_args(), which now declares
	 * that default so the real route enforces this too (verified live in the
	 * accompanying HTTP smoke test / report).
	 */
	public function test_write_key_cannot_create_a_lesson_with_no_status_param_because_the_route_default_is_publish(): void {
		$controller = $this->controller();
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/learnpress/lessons',
			array( 'title' => 'Lesson One', 'status' => 'publish' ), // the real default WP would apply
			array( 'X-API-Key' => $key )
		);

		$result = $controller->verify_api_key( $request );
		$this->assertInstanceOf(
			WP_Error::class,
			$result,
			'create_lesson() defaults status to publish — a write key must not be able to reach that default with zero arguments.'
		);
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
	}

	public function test_write_key_can_create_an_explicit_draft_lesson(): void {
		$controller = $this->controller();
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/learnpress/lessons',
			array(
				'title'  => 'Lesson One',
				'status' => 'draft',
			),
			array( 'X-API-Key' => $key )
		);

		$this->assertTrue( $controller->verify_api_key( $request ) );
	}

	public function test_admin_key_can_create_a_lesson_with_no_status_param_at_all(): void {
		$controller = $this->controller();
		$key        = $this->admin_key( $controller );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/learnpress/lessons',
			array( 'title' => 'Lesson One', 'status' => 'publish' ), // the real default WP would apply
			array( 'X-API-Key' => $key )
		);

		$this->assertTrue( $controller->verify_api_key( $request ) );
	}

	public function test_write_key_cannot_update_a_lesson_to_published(): void {
		$controller = $this->controller();
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'PUT',
			'/mcpwp/v1/learnpress/lessons/9',
			array(
				'id'     => 9,
				'status' => 'publish',
			),
			array( 'X-API-Key' => $key )
		);

		$result = $controller->verify_api_key( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
	}

	public function test_write_key_can_still_update_a_lessons_duration_only(): void {
		$controller = $this->controller();
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'PUT',
			'/mcpwp/v1/learnpress/lessons/9',
			array(
				'id'       => 9,
				'duration' => '10 minutes',
			),
			array( 'X-API-Key' => $key )
		);

		$this->assertTrue( $controller->verify_api_key( $request ) );
	}

	// ── Quizzes (same dangerous default as lessons) ─────────────────────────

	public function test_write_key_cannot_create_a_published_quiz_explicitly(): void {
		$controller = $this->controller();
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/learnpress/quizzes',
			array(
				'title'  => 'Quiz One',
				'status' => 'publish',
			),
			array( 'X-API-Key' => $key )
		);

		$result = $controller->verify_api_key( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
	}

	public function test_write_key_cannot_create_a_quiz_with_no_status_param_because_the_route_default_is_publish(): void {
		$controller = $this->controller();
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/learnpress/quizzes',
			array( 'title' => 'Quiz One', 'status' => 'publish' ), // the real default WP would apply
			array( 'X-API-Key' => $key )
		);

		$result = $controller->verify_api_key( $request );
		$this->assertInstanceOf(
			WP_Error::class,
			$result,
			'create_quiz() defaults status to publish — a write key must not be able to reach that default with zero arguments.'
		);
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
	}

	public function test_write_key_can_create_an_explicit_draft_quiz(): void {
		$controller = $this->controller();
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/learnpress/quizzes',
			array(
				'title'  => 'Quiz One',
				'status' => 'draft',
			),
			array( 'X-API-Key' => $key )
		);

		$this->assertTrue( $controller->verify_api_key( $request ) );
	}

	public function test_write_key_cannot_update_a_quiz_to_published(): void {
		$controller = $this->controller();
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'PUT',
			'/mcpwp/v1/learnpress/quizzes/3',
			array(
				'id'     => 3,
				'status' => 'publish',
			),
			array( 'X-API-Key' => $key )
		);

		$result = $controller->verify_api_key( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
	}

	public function test_write_key_can_still_get_quiz_questions_no_status_param_involved(): void {
		$controller = $this->controller();
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'GET',
			'/mcpwp/v1/learnpress/quizzes/3/questions',
			array( 'id' => 3 ),
			array( 'X-API-Key' => $key )
		);

		$this->assertTrue(
			$controller->verify_api_key( $request ),
			'GET is never write scope regardless of the publish-status prefix gate.'
		);
	}

	// ── WARN 3: 'private' must be treated the same as 'publish' ────────────

	public function test_write_key_cannot_create_a_private_lesson(): void {
		$controller = $this->controller();
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/learnpress/lessons',
			array(
				'title'  => 'Lesson One',
				'status' => 'private',
			),
			array( 'X-API-Key' => $key )
		);

		$result = $controller->verify_api_key( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
	}

	// ── Unrelated sub-route must not be swept in by the prefix match ───────

	public function test_write_key_can_still_create_a_course_category_no_status_field_involved(): void {
		$controller = $this->controller();
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/learnpress/categories',
			array( 'name' => 'Widgets 101' ),
			array( 'X-API-Key' => $key )
		);

		$this->assertTrue( $controller->verify_api_key( $request ) );
	}
}
