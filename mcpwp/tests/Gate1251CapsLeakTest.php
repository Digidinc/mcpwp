<?php
/**
 * Regression for #1251 — per-test capability / current-user globals must reset.
 *
 * current_user_can() reads mcpwp_test_user_caps + mcpwp_test_post_caps;
 * get_current_user_id() reads mcpwp_test_current_user. A leaked cap map left
 * manage_options denied for user 1, so AdminScopeRequiresWrite1210Test's
 * hostile-label notice rendered empty under random seed 31337 on the #1250 tree.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class Gate1251CapsLeakTest extends TestCase {

	/**
	 * Polluter: leave a restrictive per-user cap map and a non-default current user.
	 */
	public function test_plants_leaked_capability_map(): void {
		$GLOBALS['mcpwp_test_user_caps'] = array(
			1 => array(
				'read' => true,
				// manage_options deliberately absent → current_user_can denies it.
			),
		);
		$GLOBALS['mcpwp_test_post_caps']    = array(
			1 => array(
				'edit_post' => array( 42 => false ),
			),
		);
		$GLOBALS['mcpwp_test_current_user'] = 1;

		$this->assertSame( 1, (int) $GLOBALS['mcpwp_test_current_user'] );
		$this->assertArrayHasKey( 1, $GLOBALS['mcpwp_test_user_caps'] );
		$this->assertArrayHasKey( 1, $GLOBALS['mcpwp_test_post_caps'] );
	}

	/**
	 * Victim: runs after the polluter in-process; passes only when
	 * Mcpwp_Test_Process_State_Reset restores the bootstrap defaults.
	 *
	 * @depends test_plants_leaked_capability_map
	 */
	public function test_capability_globals_reset_between_tests(): void {
		$this->assertSame( array(), $GLOBALS['mcpwp_test_user_caps'] ?? null );
		$this->assertSame( array(), $GLOBALS['mcpwp_test_post_caps'] ?? null );
		$this->assertSame( 0, (int) ( $GLOBALS['mcpwp_test_current_user'] ?? -1 ) );
	}
}
