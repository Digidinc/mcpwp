<?php
/**
 * Regression for #1247 — admin OAuth on Gate1103Round3BulkItemsTest must not
 * inherit a future user-revocation epoch left in fake user meta by an earlier
 * test (OAuthRevocation1101Test::test_an_epoch_can_never_be_lowered).
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Gate1103Round3BulkItemsTest.php';

final class Gate1103Round3OrderLeak1247Test extends TestCase {

	/**
	 * Polluter stand-in: same persisted state as test_an_epoch_can_never_be_lowered.
	 */
	public function test_plants_future_revocation_epoch_on_admin_user(): void {
		$future = Mcpwp_OAuth_Revocation::now() + 3600 * 1000000;
		update_user_meta( Gate1103Round3BulkItemsTest::ADMIN, Mcpwp_OAuth_Revocation::USER_META, (string) $future );
		$this->assertSame( $future, Mcpwp_OAuth_Revocation::user_epoch( Gate1103Round3BulkItemsTest::ADMIN ) );
	}

	/**
	 * Victim stand-in: runs after the polluter in-process; passes only when
	 * Mcpwp_Test_Process_State_Reset clears fake user meta between tests.
	 *
	 * @depends test_plants_future_revocation_epoch_on_admin_user
	 */
	public function test_bulk_gate_admin_oauth_survives_epoch_pollution_leak(): void {
		$victim = new Gate1103Round3BulkItemsTest( 'test_array_items_are_judged_exactly_as_before' );
		$victim->setUp();
		try {
			$victim->test_array_items_are_judged_exactly_as_before();
		} finally {
			$victim->tearDown();
		}
	}
}