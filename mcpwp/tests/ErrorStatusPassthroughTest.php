<?php
/**
 * Coverage for #953 — 64 REST handlers re-wrapped a WP_Error and hardcoded
 * status 400 on the re-wrap:
 *
 *   error_response( $x->get_error_code(), $x->get_error_message(), 400 )
 *
 * A lower-level failure that already carried its own status (500 for a
 * storage failure, 404 for "not found", 409 for a conflict, ...) was
 * reported to the caller as a generic 400 client error. An AI agent acting
 * on the status code alone had no way to tell "you sent something wrong"
 * from "the server failed" or "that does not exist".
 *
 * The fix is two helpers on the base REST controller
 * (Mcpwp_REST_API::get_wp_error_status() and
 * Mcpwp_REST_API::error_response_from_wp_error()) that read the status the
 * WP_Error already carries in its own data and only fall back to 400 when
 * it is missing or not a plausible HTTP status. All 64 call sites were
 * swapped to the new helper; this file pins the helper's own contract, a
 * handful of representative endpoints that now answer with their real
 * status, and a guard against the old pattern reappearing.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-posts.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-drafts.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-posts.php';

/**
 * Minimal concrete subclass — Mcpwp_REST_API is abstract and the two
 * methods under test are protected. register_routes() is the only abstract
 * method the base class declares.
 */
class Mcpwp_Test_Error_Status_Controller extends Mcpwp_REST_API {

	public function register_routes() {}

	public function public_success_response( $data, $status = 200 ) {
		return $this->success_response( $data, $status );
	}

	public function public_get_wp_error_status( WP_Error $error, $default_status = 400 ) {
		return $this->get_wp_error_status( $error, $default_status );
	}

	public function public_error_response_from_wp_error( WP_Error $error, $default_status = 400, $context = array() ) {
		return $this->error_response_from_wp_error( $error, $default_status, $context );
	}
}

final class ErrorStatusPassthroughTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['_mcpwp_test_posts']       = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_options'] = array();
		$GLOBALS['_mcpwp_test_posts']  = array();
	}

	// ---------------------------------------------------------------
	// get_wp_error_status() / error_response_from_wp_error() contract.
	// ---------------------------------------------------------------

	public function test_list_posts_unknown_type_returns_a_400_error_not_a_success_body(): void {
		$controller = new Mcpwp_REST_Posts();
		$request    = new WP_REST_Request( 'GET', '/mcpwp/v1/posts', array( 'post_type' => 'unknown' ) );

		$result = $controller->list_posts( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_post_type', $result->get_error_code() );
		$this->assertSame( 'Invalid or unsupported post type.', $result->get_error_message() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/** @dataProvider success_response_error_provider */
	public function test_success_response_normalizes_only_the_error_status( $data, $expected_status ): void {
		$controller = new Mcpwp_Test_Error_Status_Controller();
		$error      = new WP_Error( 'invalid_post_type', 'Invalid type.', $data );
		$result     = $controller->public_success_response( $error, 201 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $error, $result );
		$this->assertSame( 'invalid_post_type', $result->get_error_code() );
		$this->assertSame( 'Invalid type.', $result->get_error_message() );
		$expected_data           = (array) $data;
		$expected_data['status'] = $expected_status;
		$this->assertSame( $expected_data, $result->get_error_data() );
	}

	public static function success_response_error_provider(): array {
		return array(
			'no status'       => array( null, 400 ),
			'forbidden'       => array( array( 'status' => 403 ), 403 ),
			'backend failure' => array( array( 'status' => 503 ), 503 ),
			'invalid status'  => array( array( 'status' => 200, 'hint' => 'Backend hint.', 'guide' => array( 'retry' => false ) ), 400 ),
			'string status'   => array( array( 'status' => '503', 'resource' => 'posts' ), 400 ),
		);
	}

	public function test_list_posts_revision_preserves_the_backend_security_hint(): void {
		require_once dirname( __DIR__ ) . '/includes/class-mcpwp-error-hints.php';

		$controller = new Mcpwp_REST_Posts();
		$request    = new WP_REST_Request( 'GET', '/mcpwp/v1/posts', array( 'post_type' => 'revision' ) );
		$result     = $controller->list_posts( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_post_type', $result->get_error_code() );
		$this->assertSame( 'Invalid or unsupported post type.', $result->get_error_message() );
		$this->assertSame(
			array(
				'status' => 400,
				'hint'   => 'Post type "revision" is blocked for security reasons. Use wp_site_info to check available post types, or wp_list_content(post_type=\'...\') for custom post types.',
			),
			$result->get_error_data()
		);
	}

	public function test_success_response_keeps_success_data_and_status(): void {
		$controller = new Mcpwp_Test_Error_Status_Controller();
		$data       = array( 'posts' => array(), 'total' => 0 );
		$result     = $controller->public_success_response( $data, 201 );

		$this->assertInstanceOf( WP_REST_Response::class, $result );
		$this->assertSame( $data, $result->get_data() );
		$this->assertSame( 201, $result->get_status() );
	}

	public function test_a_500_status_the_error_carries_is_preserved(): void {
		$ctl   = new Mcpwp_Test_Error_Status_Controller();
		$error = new WP_Error( 'delete_failed', 'Failed to delete page.', array( 'status' => 500 ) );

		$this->assertSame( 500, $ctl->public_get_wp_error_status( $error ) );

		$wrapped = $ctl->public_error_response_from_wp_error( $error );
		$this->assertInstanceOf( WP_Error::class, $wrapped );
		$this->assertSame( 500, $wrapped->get_error_data()['status'] );
		$this->assertSame( 'delete_failed', $wrapped->get_error_code() );
	}

	public function test_a_404_status_the_error_carries_is_preserved(): void {
		$ctl   = new Mcpwp_Test_Error_Status_Controller();
		$error = new WP_Error( 'not_found', 'Page not found.', array( 'status' => 404 ) );

		$this->assertSame( 404, $ctl->public_get_wp_error_status( $error ) );
		$this->assertSame( 404, $ctl->public_error_response_from_wp_error( $error )->get_error_data()['status'] );
	}

	public function test_a_409_status_the_error_carries_is_preserved(): void {
		// A representative "in-band" status beyond the two most common
		// (404/500) — conflicts (e.g. a destructive-gate two-person-rule
		// refusal) must pass through just the same.
		$ctl   = new Mcpwp_Test_Error_Status_Controller();
		$error = new WP_Error( 'translation_exists', 'Translation already exists.', array( 'status' => 409 ) );

		$this->assertSame( 409, $ctl->public_get_wp_error_status( $error ) );
	}

	public function test_a_missing_status_falls_back_to_400(): void {
		$ctl   = new Mcpwp_Test_Error_Status_Controller();
		$error = new WP_Error( 'invalid_widget', 'Invalid widget ID format.' );

		$this->assertSame( 400, $ctl->public_get_wp_error_status( $error ) );
		$this->assertSame( 400, $ctl->public_error_response_from_wp_error( $error )->get_error_data()['status'] );
	}

	public function test_data_present_but_no_status_key_falls_back_to_400(): void {
		$ctl   = new Mcpwp_Test_Error_Status_Controller();
		$error = new WP_Error( 'invalid_taxonomy', 'Taxonomy does not exist.', array( 'valid_types' => array( 'page' ) ) );

		$this->assertSame( 400, $ctl->public_get_wp_error_status( $error ) );
	}

	public function test_a_non_int_status_falls_back_to_400(): void {
		// A status carried as a numeric string ("500") is not what any of
		// the 64 call sites' underlying errors produce, but a helper that
		// coerced it would risk accepting attacker- or bug-supplied noise
		// as a real HTTP status. Strict is_int() only.
		$ctl   = new Mcpwp_Test_Error_Status_Controller();
		$error = new WP_Error( 'delete_failed', 'Failed.', array( 'status' => '500' ) );

		$this->assertSame( 400, $ctl->public_get_wp_error_status( $error ) );
	}

	public function test_an_out_of_range_status_falls_back_to_400(): void {
		$ctl = new Mcpwp_Test_Error_Status_Controller();

		$too_low  = new WP_Error( 'x', 'x', array( 'status' => 300 ) );
		$too_high = new WP_Error( 'x', 'x', array( 'status' => 700 ) );
		$zero     = new WP_Error( 'x', 'x', array( 'status' => 0 ) );
		$negative = new WP_Error( 'x', 'x', array( 'status' => -1 ) );

		$this->assertSame( 400, $ctl->public_get_wp_error_status( $too_low ) );
		$this->assertSame( 400, $ctl->public_get_wp_error_status( $too_high ) );
		$this->assertSame( 400, $ctl->public_get_wp_error_status( $zero ) );
		$this->assertSame( 400, $ctl->public_get_wp_error_status( $negative ) );
	}

	public function test_boundary_statuses_400_and_599_are_accepted(): void {
		$ctl = new Mcpwp_Test_Error_Status_Controller();

		$this->assertSame( 400, $ctl->public_get_wp_error_status( new WP_Error( 'x', 'x', array( 'status' => 400 ) ) ) );
		$this->assertSame( 599, $ctl->public_get_wp_error_status( new WP_Error( 'x', 'x', array( 'status' => 599 ) ) ) );
	}

	public function test_a_caller_supplied_default_is_honoured_when_the_error_carries_none(): void {
		// error_response_from_wp_error()'s $default_status exists for
		// parity with error_response()'s own signature; every real call
		// site uses the implicit 400 default, but the helper must not
		// silently ignore a caller who passes something else.
		$ctl   = new Mcpwp_Test_Error_Status_Controller();
		$error = new WP_Error( 'x', 'No status here.' );

		$this->assertSame( 422, $ctl->public_get_wp_error_status( $error, 422 ) );
	}

	// ---------------------------------------------------------------
	// Hint enrichment still applies (Mcpwp_Error_Hints), status untouched.
	// ---------------------------------------------------------------

	public function test_hint_enrichment_still_applies_and_does_not_disturb_the_status(): void {
		require_once dirname( __DIR__ ) . '/includes/class-mcpwp-error-hints.php';

		$ctl     = new Mcpwp_Test_Error_Status_Controller();
		$error   = new WP_Error( 'not_found', 'Page not found.', array( 'status' => 404 ) );
		$wrapped = $ctl->public_error_response_from_wp_error( $error, 400, array( 'id' => 42 ) );

		$data = $wrapped->get_error_data();
		$this->assertSame( 404, $data['status'], 'Hint enrichment must not overwrite the resolved status.' );
		$this->assertArrayHasKey( 'hint', $data, 'error_response_from_wp_error() must still route through hint enrichment.' );
		$this->assertStringContainsString( '42', $data['hint'] );
	}

	// ---------------------------------------------------------------
	// Representative endpoints: the same call now answers with its real
	// status instead of a hardcoded 400.
	// ---------------------------------------------------------------

	/**
	 * DELETE /pages/{id} on a page that does not exist. Mcpwp_Pages::
	 * delete_page() has always returned WP_Error('not_found', ..., array(
	 * 'status' => 404)) — the REST controller hardcoded 400 on top of it.
	 */
	public function test_delete_page_not_found_now_answers_404_not_400(): void {
		// The destructive gate defaults to "on" (#536); disable it so the
		// call reaches Mcpwp_Pages::delete_page() instead of being deferred
		// for approval — a different code path this test is not about.
		update_option( 'mcpwp_settings', array( 'destructive_requires_approval' => false ) );

		$controller = new Mcpwp_REST_Pages();
		$request    = new WP_REST_Request( 'DELETE', '/mcpwp/v1/pages/999999', array( 'id' => 999999 ) );

		$result = $controller->delete_page( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	/**
	 * DELETE /pages/{id} where the underlying delete fails. Injects a
	 * double for Mcpwp_Pages so the 500 path (delete_failed) is reachable
	 * without fighting the wp_delete_post()/wp_trash_post() test doubles,
	 * which cannot be made to disagree with get_post() on whether the
	 * post exists.
	 */
	public function test_delete_page_delete_failure_now_answers_500_not_400(): void {
		update_option( 'mcpwp_settings', array( 'destructive_requires_approval' => false ) );

		$controller = new Mcpwp_REST_Pages();
		$double     = new class() {
			public function delete_page( $id, $force = false ) {
				return new WP_Error( 'delete_failed', 'Failed to delete page.', array( 'status' => 500 ) );
			}
		};
		$prop = new ReflectionProperty( Mcpwp_REST_Pages::class, 'pages' );
		$prop->setAccessible( true );
		$prop->setValue( $controller, $double );

		$request = new WP_REST_Request( 'DELETE', '/mcpwp/v1/pages/5', array( 'id' => 5, 'force' => true ) );
		$result  = $controller->delete_page( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'delete_failed', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'], 'A storage failure must not be reported as a 400 client error.' );
	}

	/**
	 * POST /widgets/{id} (add_widget) where the widget backend answers
	 * with an explicit, non-400 status. Uses a double for Mcpwp_Widgets —
	 * the real class's own WP_Errors never carry a status (a separate,
	 * pre-existing gap this issue does not touch), so this exercises the
	 * REST controller's passthrough with an error shaped the way #953
	 * describes.
	 */
	public function test_add_widget_backend_failure_now_answers_503_not_400(): void {
		require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-widgets.php';

		$double = new class() {
			public function add_widget( $sidebar_id, $widget_type, $settings, $position ) {
				return new WP_Error( 'widget_backend_unavailable', 'The widgets backend could not be reached.', array( 'status' => 503 ) );
			}
		};
		$controller = new Mcpwp_REST_Widgets( $double );
		$request    = new WP_REST_Request( 'POST', '/mcpwp/v1/widgets/sidebar-1', array( 'id' => 'sidebar-1', 'type' => 'text', 'settings' => array( 'text' => 'hi' ) ) );

		$result = $controller->add_widget( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 503, $result->get_error_data()['status'] );
	}

	/**
	 * POST /users (create_user) where the underlying WP_Error carries no
	 * status at all (as WordPress's own username_exists()/email_exists()
	 * checks are wrapped here). Behaviour must stay byte-identical to
	 * before this fix: still 400.
	 */
	public function test_create_user_with_no_status_on_the_error_still_answers_400(): void {
		require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-users.php';

		$double = new class() {
			public function create_user( $data ) {
				return new WP_Error( 'username_exists', 'Username already exists.' );
			}
		};
		$controller = new Mcpwp_REST_Users( $double );
		$request    = new WP_REST_Request( 'POST', '/mcpwp/v1/users', array( 'username' => 'alice', 'email' => 'alice@example.test', 'password' => 'x' ) );

		$result = $controller->create_user( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'username_exists', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'], 'Unchanged behaviour: no status on the error means the old hardcoded 400.' );
	}

	// ---------------------------------------------------------------
	// Guard: the fixed pattern must not reappear.
	// ---------------------------------------------------------------

	/**
	 * The exact pattern #953 fixed, scanned across the whole includes
	 * tree. A future call site that copies the old
	 * `error_response( $x->get_error_code(), $x->get_error_message(), 400 )`
	 * idiom instead of calling error_response_from_wp_error() fails this
	 * test instead of silently reintroducing the bug.
	 */
	public function test_the_hardcoded_400_rewrap_pattern_does_not_reappear(): void {
		$includes_dir = dirname( __DIR__ ) . '/includes';
		$pattern      = '/error_response\(\s*\$[a-zA-Z_][a-zA-Z0-9_]*->get_error_code\(\)\s*,\s*\$[a-zA-Z_][a-zA-Z0-9_]*->get_error_message\(\)\s*,\s*400\s*\)/';

		$offenders = array();
		$iterator  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $includes_dir, FilesystemIterator::SKIP_DOTS ) );

		foreach ( $iterator as $file ) {
			if ( 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}
			$contents = file_get_contents( $file->getPathname() );
			if ( false === $contents ) {
				continue;
			}
			if ( preg_match( $pattern, $contents ) ) {
				$offenders[] = str_replace( $includes_dir . '/', '', $file->getPathname() );
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"Found the #953 hardcoded-400 WP_Error rewrap pattern. Use \$this->error_response_from_wp_error(\$error) instead:\n" . implode( "\n", $offenders )
		);
	}
}
