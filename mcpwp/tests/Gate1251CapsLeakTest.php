<?php
/**
 * Regression test for #1251: per-test capability/current-user doubles are reset.
 *
 * The first test plants the exact state that leaked between classes (a per-user
 * capability map and a current user); the second runs right after it and asserts
 * the shared per-test reset (Mcpwp_Test_Process_State_Reset in bootstrap.php)
 * cleared both. Remove the reset and the second test fails.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

class Gate1251CapsLeakTest extends TestCase {

	public function test_plant_leaked_capabilities_and_current_user(): void {
		$GLOBALS['mcpwp_test_user_caps']    = array( 1 => array( 'manage_options' => false ) );
		$GLOBALS['mcpwp_test_current_user'] = 7;
		$this->assertSame( 7, $GLOBALS['mcpwp_test_current_user'] );
	}

	/**
	 * @depends test_plant_leaked_capabilities_and_current_user
	 */
	public function test_shared_reset_cleared_the_planted_state(): void {
		$this->assertSame( array(), $GLOBALS['mcpwp_test_user_caps'] );
		$this->assertSame( 0, $GLOBALS['mcpwp_test_current_user'] );
	}
}
