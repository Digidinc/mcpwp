<?php
/**
 * Regression tests — one-time purge of identity-less pending approvals.
 *
 * A pending approval created via OAuth before per-request identity stamping has
 * an empty requested_by_key_id; a reconnected (now stably-identified) OAuth
 * principal could otherwise approve/apply it. Mcpwp_Activator::migrate_identity_
 * less_approvals() drops those PENDING records once, on upgrade, and leaves
 * everything else (attributed, or non-pending) intact.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-mcpwp-activator.php';

final class ApprovalIdentityMigrationTest extends TestCase {

	private const OPTION = 'mcpwp_approval_requests';

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options'] = array();
	}

	private function seed( array $items ): void {
		update_option( self::OPTION, $items );
	}

	public function test_purges_only_pending_unattributed_approvals(): void {
		$this->seed(
			array(
				'a_pending_empty'     => array( 'id' => 'a_pending_empty', 'status' => 'pending', 'requested_by_key_id' => '' ),
				'b_pending_attr'      => array( 'id' => 'b_pending_attr', 'status' => 'pending', 'requested_by_key_id' => 'oauth-user:5' ),
				'c_approved_empty'    => array( 'id' => 'c_approved_empty', 'status' => 'approved', 'requested_by_key_id' => '' ),
				'd_pending_no_field'  => array( 'id' => 'd_pending_no_field', 'status' => 'pending' ),
			)
		);

		Mcpwp_Activator::migrate_identity_less_approvals();

		$items = get_option( self::OPTION, array() );

		// Pending + empty (explicit or missing field) are gone.
		$this->assertArrayNotHasKey( 'a_pending_empty', $items );
		$this->assertArrayNotHasKey( 'd_pending_no_field', $items );
		// Attributed pending and non-pending records are preserved.
		$this->assertArrayHasKey( 'b_pending_attr', $items );
		$this->assertArrayHasKey( 'c_approved_empty', $items );

		$this->assertSame( 1, get_option( 'mcpwp_approvals_identity_migrated' ) );
	}

	public function test_is_idempotent_after_flag_set(): void {
		// First run sets the flag.
		$this->seed( array( 'x' => array( 'id' => 'x', 'status' => 'pending', 'requested_by_key_id' => '' ) ) );
		Mcpwp_Activator::migrate_identity_less_approvals();
		$this->assertArrayNotHasKey( 'x', get_option( self::OPTION, array() ) );

		// A new admin-created (empty-requester) pending approval added later must
		// NOT be purged by a subsequent upgrade — the migration runs once only.
		$this->seed( array( 'y' => array( 'id' => 'y', 'status' => 'pending', 'requested_by_key_id' => '' ) ) );
		Mcpwp_Activator::migrate_identity_less_approvals();
		$this->assertArrayHasKey( 'y', get_option( self::OPTION, array() ) );
	}
}
