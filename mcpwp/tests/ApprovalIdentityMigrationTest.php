<?php
/**
 * Regression tests — one-time purge of identity-less pending approvals.
 *
 * A pending approval created via OAuth before per-request identity stamping has
 * an empty requested_by_key_id; a reconnected (now stably-identified) OAuth
 * principal could otherwise approve/apply it. Mcpwp_Activator::migrate_identity_
 * less_approvals() drops those PENDING records once, on upgrade, and leaves
 * everything else (attributed, or non-pending) intact. Since #1042 it runs
 * against the approvals table, after the one-time copy out of the option.
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

	/** An upgrading site: the queue is still in the legacy option. */
	private function seed( array $items ): void {
		update_option( self::OPTION, $items );
	}

	public function test_purges_actionable_unattributed_approvals(): void {
		$this->seed(
			array(
				// Actionable + unattributed → purged.
				'a_pending_empty'    => array( 'id' => 'a_pending_empty', 'status' => 'pending', 'requested_by_key_id' => '' ),
				'd_pending_no_field' => array( 'id' => 'd_pending_no_field', 'status' => 'pending' ),
				'e_approved_empty'   => array( 'id' => 'e_approved_empty', 'status' => 'approved', 'requested_by_key_id' => '' ),
				// Attributed → preserved even when actionable.
				'b_pending_attr'     => array( 'id' => 'b_pending_attr', 'status' => 'pending', 'requested_by_key_id' => 'oauth-user:5' ),
				// Terminal states → preserved even when unattributed.
				'f_applied_empty'    => array( 'id' => 'f_applied_empty', 'status' => 'applied', 'requested_by_key_id' => '' ),
				'g_rejected_empty'   => array( 'id' => 'g_rejected_empty', 'status' => 'rejected', 'requested_by_key_id' => '' ),
			)
		);

		Mcpwp_Activator::migrate_identity_less_approvals();

		$this->assertGreaterThan( 1, (int) get_option( 'mcpwp_approvals_table_migrated' ), 'The queue was moved to the table first.' );
		$items = mcpwp_test_approvals_all();

		// Actionable (pending/approved) + empty requester are gone — both can be
		// approved and/or applied via the empty-requester branch.
		$this->assertArrayNotHasKey( 'a_pending_empty', $items );
		$this->assertArrayNotHasKey( 'd_pending_no_field', $items );
		$this->assertArrayNotHasKey( 'e_approved_empty', $items );
		// Attributed and terminal records are preserved.
		$this->assertArrayHasKey( 'b_pending_attr', $items );
		$this->assertArrayHasKey( 'f_applied_empty', $items );
		$this->assertArrayHasKey( 'g_rejected_empty', $items );

		$this->assertSame( 1, get_option( 'mcpwp_approvals_identity_migrated' ) );
	}

	public function test_is_idempotent_after_flag_set(): void {
		// First run sets the flag.
		$this->seed( array( 'x' => array( 'id' => 'x', 'status' => 'pending', 'requested_by_key_id' => '' ) ) );
		Mcpwp_Activator::migrate_identity_less_approvals();
		$this->assertArrayNotHasKey( 'x', mcpwp_test_approvals_all() );

		// A new admin-created (empty-requester) pending approval added later must
		// NOT be purged by a subsequent upgrade — the migration runs once only.
		mcpwp_test_approvals_put( array( 'y' => array( 'id' => 'y', 'status' => 'pending', 'requested_by_key_id' => '' ) ) );
		Mcpwp_Activator::migrate_identity_less_approvals();
		$this->assertArrayHasKey( 'y', mcpwp_test_approvals_all() );
	}
}
