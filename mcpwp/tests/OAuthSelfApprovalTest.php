<?php
/**
 * Regression tests — OAuth principals must not defeat the self-approval gate.
 *
 * Before the fix, authenticate_oauth_access_token() never populated
 * current_api_key_record, so an OAuth token:
 *   1. stamped approvals with an empty requested_by_key_id, and
 *   2. was treated by check_self_approval() as the trusted admin-AJAX human
 *      (the `! $key_record` branch returned true unconditionally),
 * letting a single admin-consented token both create and approve/apply its own
 * push-to-live request — defeating the two-person rule.
 *
 * These tests lock: (a) an OAuth token now carries a stable non-empty identity,
 * and (b) check_self_approval() blocks same-identity approval and refuses an
 * id-less caller that is not genuine admin-AJAX.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-approvals.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-approvals.php';

/** Harness exposing the OAuth-issuing auth trait. */
class Mcpwp_OAuth_SelfApproval_Auth_Harness {
	use Mcpwp_Api_Auth;
}

final class OAuthSelfApprovalTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']       = array();
		$GLOBALS['mcpwp_test_transients']    = array();
		$GLOBALS['mcpwp_test_current_user']  = 0;
		$GLOBALS['mcpwp_test_is_admin']      = false;
		$GLOBALS['mcpwp_test_filters']       = array();

		update_option( 'mcpwp_settings', array(
			'oauth_enabled'    => true,
			'oauth_token_ttl'  => 3600,
		) );
	}

	/** Invoke the private check_self_approval() with a chosen current key record. */
	private function check_self_approval( $current_key_record, string $approval_id ) {
		$controller = new Mcpwp_REST_Approvals();

		$prop = new ReflectionProperty( Mcpwp_REST_Approvals::class, 'current_api_key_record' );
		$prop->setAccessible( true );
		$prop->setValue( $controller, $current_key_record );

		$method = new ReflectionMethod( Mcpwp_REST_Approvals::class, 'check_self_approval' );
		$method->setAccessible( true );

		return $method->invoke( $controller, $approval_id );
	}

	private function seed_approval( string $id, string $requested_by_key_id ): void {
		update_option(
			Mcpwp_Approvals::OPTION_NAME,
			array(
				$id => array(
					'id'                  => $id,
					'status'              => 'pending',
					'requested_by_key_id' => $requested_by_key_id,
				),
			)
		);
	}

	// ── (a) OAuth tokens now carry a stable, non-empty identity ──────────

	public function test_oauth_token_with_user_gets_stable_identity(): void {
		$auth  = new Mcpwp_OAuth_SelfApproval_Auth_Harness();
		$token = $auth->issue_oauth_access_token( array( 'read', 'write', 'admin' ), 3600, array( 'user_id' => 7 ) )['access_token'];

		$request = new WP_REST_Request(
			'GET',
			'/mcpwp/v1/site-info',
			array(),
			array( 'Authorization' => 'Bearer ' . $token )
		);

		$this->assertTrue( $auth->verify_api_key( $request ) );

		$record = $auth->get_current_api_key_record();
		$this->assertIsArray( $record );
		$this->assertSame( 'oauth-user:7', $record['id'], 'OAuth principal must be stamped with a stable id' );
	}

	public function test_legacy_oauth_token_without_user_still_gets_nonempty_id(): void {
		$auth  = new Mcpwp_OAuth_SelfApproval_Auth_Harness();
		// Simulate a legacy token issued before user_id was persisted.
		$token = $auth->issue_oauth_access_token( array( 'read', 'write', 'admin' ), 3600 )['access_token'];

		$request = new WP_REST_Request(
			'GET',
			'/mcpwp/v1/site-info',
			array(),
			array( 'Authorization' => 'Bearer ' . $token )
		);

		$this->assertTrue( $auth->verify_api_key( $request ) );

		$record = $auth->get_current_api_key_record();
		$this->assertIsArray( $record );
		$this->assertNotEmpty( $record['id'] );
		$this->assertStringStartsWith( 'oauth:', $record['id'] );
	}

	// ── (b) The self-approval gate blocks OAuth self-approval ────────────

	public function test_same_oauth_identity_cannot_approve_its_own_request(): void {
		$this->seed_approval( 'appr_1', 'oauth-user:7' );

		$result = $this->check_self_approval(
			array( 'id' => 'oauth-user:7', 'scopes' => array( 'admin' ) ),
			'appr_1'
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'self_approval_forbidden', $result->get_error_code() );
	}

	public function test_different_oauth_identity_may_approve(): void {
		$this->seed_approval( 'appr_1', 'oauth-user:7' );

		$result = $this->check_self_approval(
			array( 'id' => 'oauth-user:9', 'scopes' => array( 'admin' ) ),
			'appr_1'
		);

		$this->assertTrue( $result );
	}

	public function test_idless_non_admin_ajax_caller_is_forbidden(): void {
		// This is the exact previous bug: a null/id-less record used to return
		// true (trusted). It must now be forbidden unless it is genuine admin-AJAX.
		$this->seed_approval( 'appr_1', 'oauth-user:7' );

		$result = $this->check_self_approval( null, 'appr_1' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'self_approval_forbidden', $result->get_error_code() );
	}
}
