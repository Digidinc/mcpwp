<?php
/**
 * Regression tests — Staging self-approval block.
 *
 * P0 security fix: the API key that creates a staging push approval cannot
 * approve or apply it.  A second, distinct key (or an admin-AJAX context)
 * must be used.  Non-staging approvals (no 'requested_by_key_id') are
 * unaffected.
 *
 * Test approach: subclass Mcpwp_REST_Approvals to inject the current key
 * record without going through the full HTTP auth stack, then call the public
 * approve_approval() / apply_approval() methods directly.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

// Load approvals classes — not included in the main bootstrap because no
// other tests needed them before this feature.
require_once dirname( __DIR__, 2 ) . '/includes/core/class-mcpwp-approvals.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/class-mcpwp-rest-approvals.php';
// Needed to toggle the batch/MCP sub-request flag that routes auth through
// verify_api_key_auth_only() — the batch/MCP sub-request auth path.
require_once dirname( __DIR__, 2 ) . '/includes/api/class-mcpwp-rest-batch.php';

// ── Bootstrap stubs needed only in this test file ────────────────────────

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $value ) {
		return trim( (string) $value );
	}
}

if ( ! function_exists( 'wp_trim_words' ) ) {
	function wp_trim_words( $text, $num_words = 55 ) {
		$words = explode( ' ', strip_tags( (string) $text ) );
		if ( count( $words ) <= $num_words ) {
			return $text;
		}
		return implode( ' ', array_slice( $words, 0, $num_words ) ) . '&hellip;';
	}
}

/**
 * Testable subclass: lets tests inject the current API key record directly
 * rather than running through the full HTTP authentication stack.
 */
class Mcpwp_REST_Approvals_Testable extends Mcpwp_REST_Approvals {

	/** When true, the controller reports a genuine admin-AJAX context. */
	public bool $simulate_admin_ajax = false;

	/** @param array|null $record Key record to inject as "current authenticated key". */
	public function set_key_record( ?array $record ): void {
		$this->current_api_key_record = $record;
	}

	/**
	 * A null key record is only the trusted no-op case when it corresponds to a
	 * genuine admin-AJAX human context. Model that signal explicitly so tests
	 * distinguish it from an id-less remote principal (e.g. an OAuth token),
	 * which must be blocked.
	 */
	protected function is_trusted_admin_ajax_request() {
		return $this->simulate_admin_ajax;
	}

	/** Satisfy the abstract requirement from Mcpwp_REST_API. */
	public function register_routes() {}
}

final class StagingSelfApprovalTest extends TestCase {

	/** ID of the approval created during tests that need one. */
	private string $approval_id = '';

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 2;
		$GLOBALS['_mcpwp_test_posts']       = array();
	}

	protected function tearDown(): void {
		// PHPUnit does not reset static properties between tests; clear the flag
		// so a mid-test failure cannot leak the batch context into later tests.
		if ( class_exists( 'Mcpwp_REST_Batch' ) ) {
			Mcpwp_REST_Batch::$is_batch_sub_request = false;
		}
	}

	// ─────────────────────────────────────────────────────────────────────
	// Helper: insert a raw staging push approval record into the option store.
	// This bypasses the (not-yet-shipping) staging REST controller so the tests
	// target the approval layer in isolation.
	// ─────────────────────────────────────────────────────────────────────

	private function insert_staging_approval( string $requested_by_key_id ): string {
		$id  = 'apr_' . bin2hex( random_bytes( 12 ) );
		$now = gmdate( 'c' );

		$record = array(
			'id'                  => $id,
			'status'              => 'pending',
			'action'              => 'staging_push',
			'title'               => 'Push staging → live',
			'note'                => '',
			'tool'                => 'wp_push_to_live',
			'resource'            => array(),
			'diff'                => array( 'type' => 'staging' ),
			'payload'             => array(
				'source'        => 'staging',
				'destination'   => 'live',
				'include_db'    => true,
				'include_files' => true,
			),
			'rollback'            => array(),
			'metadata'            => array(),
			'requested_by_key_id' => $requested_by_key_id,
			'created_at'          => $now,
			'updated_at'          => $now,
			'approved_at'         => null,
			'applied_at'          => null,
			'rejected_at'         => null,
			'rolled_back_at'      => null,
		);

		$items        = get_option( Mcpwp_Approvals::OPTION_NAME, array() );
		$items[ $id ] = $record;
		update_option( Mcpwp_Approvals::OPTION_NAME, $items, false );

		return $id;
	}

	/** Promote an approval to 'approved' status so apply_approval() can run. */
	private function mark_approved( string $approval_id ): void {
		$items = get_option( Mcpwp_Approvals::OPTION_NAME, array() );
		if ( isset( $items[ $approval_id ] ) ) {
			$items[ $approval_id ]['status']      = 'approved';
			$items[ $approval_id ]['approved_at'] = gmdate( 'c' );
		}
		update_option( Mcpwp_Approvals::OPTION_NAME, $items, false );
	}

	private function make_request( string $approval_id ): WP_REST_Request {
		return new WP_REST_Request(
			'POST',
			'/mcpwp/v1/approvals/' . $approval_id . '/approve',
			array( 'id' => $approval_id )
		);
	}

	// ─────────────────────────────────────────────────────────────────────
	// 1. requested_by_key_id is stored on the record at creation time.
	// ─────────────────────────────────────────────────────────────────────

	public function test_staging_approval_stores_requested_by_key_id(): void {
		$key_id = 'key-creator-abc';
		$apr_id = $this->insert_staging_approval( $key_id );

		$items = get_option( Mcpwp_Approvals::OPTION_NAME, array() );

		$this->assertArrayHasKey( $apr_id, $items );
		$this->assertSame( $key_id, $items[ $apr_id ]['requested_by_key_id'] );
	}

	// ─────────────────────────────────────────────────────────────────────
	// 2. Same key ID → approve returns self_approval_forbidden (403).
	// ─────────────────────────────────────────────────────────────────────

	public function test_approve_by_same_key_returns_self_approval_forbidden(): void {
		$key_id = 'key-creator-111';
		$apr_id = $this->insert_staging_approval( $key_id );

		$controller = new Mcpwp_REST_Approvals_Testable();
		$controller->set_key_record( array( 'id' => $key_id ) );

		$result = $controller->approve_approval( $this->make_request( $apr_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'self_approval_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	// ─────────────────────────────────────────────────────────────────────
	// 3. Same key ID → apply also returns self_approval_forbidden (403).
	// ─────────────────────────────────────────────────────────────────────

	public function test_apply_by_same_key_returns_self_approval_forbidden(): void {
		$key_id = 'key-creator-222';
		$apr_id = $this->insert_staging_approval( $key_id );
		$this->mark_approved( $apr_id );

		$controller = new Mcpwp_REST_Approvals_Testable();
		$controller->set_key_record( array( 'id' => $key_id ) );

		$apply_req = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/approvals/' . $apr_id . '/apply',
			array( 'id' => $apr_id )
		);
		$result = $controller->apply_approval( $apply_req );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'self_approval_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	// ─────────────────────────────────────────────────────────────────────
	// 4. Different key ID → approve passes the self-approval check.
	//    (It will then proceed into Mcpwp_Approvals::approve_request and
	//    succeed because the record is in 'pending' state.)
	// ─────────────────────────────────────────────────────────────────────

	public function test_approve_by_different_key_passes_self_approval_check(): void {
		$creator_key_id  = 'key-creator-333';
		$approver_key_id = 'key-approver-333';
		$apr_id          = $this->insert_staging_approval( $creator_key_id );

		$controller = new Mcpwp_REST_Approvals_Testable();
		$controller->set_key_record( array( 'id' => $approver_key_id ) );

		$result = $controller->approve_approval( $this->make_request( $apr_id ) );

		// The self-approval gate must not fire.  The call will succeed (returns array)
		// because the approval is in 'pending' state and approve_request() accepts that.
		$this->assertNotInstanceOf(
			WP_Error::class,
			$result,
			'A different key should pass the self-approval check and succeed.'
		);

		// Confirm the approval advanced to 'approved'.
		if ( $result instanceof WP_REST_Response ) {
			$data = $result->get_data();
			$this->assertSame( 'approved', $data['status'] ?? '' );
		}
	}

	// ─────────────────────────────────────────────────────────────────────
	// 5. Non-staging approvals (no requested_by_key_id) are unaffected —
	//    check_self_approval() is a no-op when the field is absent.
	// ─────────────────────────────────────────────────────────────────────

	public function test_non_staging_approval_without_requester_field_is_unaffected(): void {
		// Insert a post_content_update approval without requested_by_key_id.
		$id  = 'apr_' . bin2hex( random_bytes( 12 ) );
		$now = gmdate( 'c' );

		$items      = get_option( Mcpwp_Approvals::OPTION_NAME, array() );
		$items[$id] = array(
			'id'          => $id,
			'status'      => 'pending',
			'action'      => 'post_content_update',
			'title'       => 'Update page content',
			'note'        => '',
			'tool'        => 'wp_update_page',
			'resource'    => array( 'type' => 'post', 'id' => 0 ),
			'diff'        => array(),
			'payload'     => array(),
			'rollback'    => array(),
			'metadata'    => array(),
			// 'requested_by_key_id' intentionally absent.
			'created_at'  => $now,
			'updated_at'  => $now,
			'approved_at' => null,
			'applied_at'  => null,
			'rejected_at' => null,
		);
		update_option( Mcpwp_Approvals::OPTION_NAME, $items, false );

		// Use the same key for both roles — should NOT trigger self_approval_forbidden.
		$same_key_id = 'key-same-for-both';

		$controller = new Mcpwp_REST_Approvals_Testable();
		$controller->set_key_record( array( 'id' => $same_key_id ) );

		$result = $controller->approve_approval(
			new WP_REST_Request( 'POST', '/mcpwp/v1/approvals/' . $id . '/approve', array( 'id' => $id ) )
		);

		// self_approval_forbidden must NOT be the error code.
		if ( is_wp_error( $result ) ) {
			$this->assertNotSame(
				'self_approval_forbidden',
				$result->get_error_code(),
				'Non-staging approvals must not trigger the self-approval block.'
			);
		} else {
			// If it resolved without error, the gate is definitely a no-op.
			$this->assertNotInstanceOf(
				WP_Error::class,
				$result,
				'Non-staging approval should not be blocked by self-approval check.'
			);
		}
	}

	// ─────────────────────────────────────────────────────────────────────
	// 6. No key record (admin-AJAX context) → check is a no-op.
	// ─────────────────────────────────────────────────────────────────────

	public function test_null_key_record_admin_ajax_context_is_not_blocked(): void {
		$apr_id = $this->insert_staging_approval( 'key-original' );

		$controller = new Mcpwp_REST_Approvals_Testable();
		// Simulate a GENUINE admin-AJAX trusted context: no API key record, and
		// the admin-AJAX signal (admin cap + nonce) actually present.
		$controller->set_key_record( null );
		$controller->simulate_admin_ajax = true;

		$result = $controller->approve_approval( $this->make_request( $apr_id ) );

		// The self-approval gate must be a no-op for the genuine admin-AJAX human.
		// Outcome: either success (array/WP_REST_Response) or a non-self-approval error.
		$error_code = is_wp_error( $result ) ? $result->get_error_code() : '';
		$this->assertNotSame(
			'self_approval_forbidden',
			$error_code,
			'Admin-AJAX context (null key record) must not trigger self-approval block.'
		);
	}

	// ─────────────────────────────────────────────────────────────────────
	// 6b. Null key record that is NOT admin-AJAX (e.g. an OAuth remote
	//     principal that failed to carry an id) → MUST be blocked. This is the
	//     P0 regression: previously any null record was treated as trusted.
	// ─────────────────────────────────────────────────────────────────────

	public function test_null_key_record_without_admin_ajax_is_blocked(): void {
		$apr_id = $this->insert_staging_approval( 'key-original' );

		$controller = new Mcpwp_REST_Approvals_Testable();
		$controller->set_key_record( null );
		$controller->simulate_admin_ajax = false;

		$result = $controller->approve_approval( $this->make_request( $apr_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame(
			'self_approval_forbidden',
			$result->get_error_code(),
			'An id-less non-admin-AJAX caller must not approve its own request.'
		);
	}

	// ─────────────────────────────────────────────────────────────────────
	// 7. verify_api_key_auth_only() (batch/MCP sub-request path) populates
	//    current_api_key_record.  Pre-fix this record stayed null, silently
	//    disabling the self-approval block.
	// ─────────────────────────────────────────────────────────────────────

	public function test_auth_only_path_populates_current_key_record(): void {
		$controller = new Mcpwp_REST_Approvals_Testable();
		$created    = $controller->create_scoped_api_key( 'admin key', array( 'admin' ) );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/approvals/apr_probe/approve',
			array(),
			array( 'X-API-Key' => $created['key'] )
		);

		Mcpwp_REST_Batch::$is_batch_sub_request = true;
		$auth = $controller->verify_api_key( $request ); // routes to verify_api_key_auth_only().
		Mcpwp_REST_Batch::$is_batch_sub_request = false;

		$this->assertTrue( $auth );

		$record = $controller->get_current_api_key_record();
		$this->assertIsArray( $record, 'auth-only path must populate the key record' );
		$this->assertSame( $created['id'], $record['id'] );
	}

	// ─────────────────────────────────────────────────────────────────────
	// 8. Same key self-approves through the auth-only (batch/MCP) path →
	//    blocked end-to-end.  This is the true regression guard for
	//    the batch/MCP path: pre-fix the record was null so the block no-oped.
	// ─────────────────────────────────────────────────────────────────────

	public function test_self_approval_blocked_through_auth_only_path(): void {
		$controller = new Mcpwp_REST_Approvals_Testable();
		$created    = $controller->create_scoped_api_key( 'admin key', array( 'admin' ) );
		$apr_id     = $this->insert_staging_approval( $created['id'] );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/approvals/' . $apr_id . '/approve',
			array( 'id' => $apr_id ),
			array( 'X-API-Key' => $created['key'] )
		);

		Mcpwp_REST_Batch::$is_batch_sub_request = true;
		$controller->verify_api_key( $request );
		Mcpwp_REST_Batch::$is_batch_sub_request = false;

		$result = $controller->approve_approval( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'self_approval_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	// ─────────────────────────────────────────────────────────────────────
	// 9. create_post_content_request() now stamps requested_by_key_id from
	//    $args so content approvals are covered by the self-approval block
	//, not just staging pushes.
	// ─────────────────────────────────────────────────────────────────────

	public function test_content_approval_stores_requested_by_key_id(): void {
		$GLOBALS['_mcpwp_test_posts'][7] = (object) array(
			'ID'           => 7,
			'post_title'   => 'Sample',
			'post_content' => 'old body',
			'post_status'  => 'publish',
			'post_type'    => 'page',
		);

		$public = Mcpwp_Approvals::create_post_content_request(
			7,
			'new body',
			array(
				'tool'                => 'wp_set_blocks',
				'requested_by_key_id' => 'key-abc',
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $public );

		$items = get_option( Mcpwp_Approvals::OPTION_NAME, array() );
		$this->assertArrayHasKey( $public['id'], $items );
		$this->assertSame( 'key-abc', $items[ $public['id'] ]['requested_by_key_id'] );
	}
}
