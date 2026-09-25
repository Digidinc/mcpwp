<?php
/**
 * Regression test — the [MCPWP-612] Elementor write/delete audit trail must
 * be silent by default and only log when explicitly enabled.
 *
 * Mcpwp_Elementor_Data_Audit was added to hunt #612 ("wp_set_elementor
 * reports success but a later read finds the page empty"). #612's causes are
 * now fixed and reported to the caller at write time (render_verified, #793,
 * shipped 3.11.0), but the class kept logging an error_log() line — with a
 * full backtrace — on every single Elementor write/delete, unconditionally.
 * On a fresh install ahead of the Product Hunt launch this flooded
 * debug.log from the first page build onward. It is kept available (not
 * deleted) for hosts that could still reproduce a similar symptom, gated
 * behind the MCPWP_DEBUG_WRITES constant, off by default.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-elementor-data-audit.php';

final class ElementorDataAuditOptInTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_actions'] = array();
	}

	public function test_init_registers_no_hooks_by_default(): void {
		$this->assertFalse(
			defined( 'MCPWP_DEBUG_WRITES' ),
			'Fixture assumption: no earlier test may have defined MCPWP_DEBUG_WRITES yet.'
		);

		Mcpwp_Elementor_Data_Audit::init();

		$this->assertArrayNotHasKey(
			'added_post_meta',
			$GLOBALS['mcpwp_test_actions'],
			'Without MCPWP_DEBUG_WRITES, the audit trail must not hook post-meta writes at all.'
		);
		$this->assertArrayNotHasKey( 'updated_post_meta', $GLOBALS['mcpwp_test_actions'] );
		$this->assertArrayNotHasKey( 'deleted_post_meta', $GLOBALS['mcpwp_test_actions'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_init_registers_hooks_when_explicitly_enabled(): void {
		require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-elementor-data-audit.php';
		$GLOBALS['mcpwp_test_actions'] = array();

		define( 'MCPWP_DEBUG_WRITES', true );

		Mcpwp_Elementor_Data_Audit::init();

		$this->assertArrayHasKey( 'added_post_meta', $GLOBALS['mcpwp_test_actions'] );
		$this->assertArrayHasKey( 'updated_post_meta', $GLOBALS['mcpwp_test_actions'] );
		$this->assertArrayHasKey( 'deleted_post_meta', $GLOBALS['mcpwp_test_actions'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_init_registers_no_hooks_when_explicitly_disabled(): void {
		require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-elementor-data-audit.php';
		$GLOBALS['mcpwp_test_actions'] = array();

		define( 'MCPWP_DEBUG_WRITES', false );

		Mcpwp_Elementor_Data_Audit::init();

		$this->assertArrayNotHasKey( 'added_post_meta', $GLOBALS['mcpwp_test_actions'] );
	}
}
