<?php
/**
 * Regression coverage for #660: a second copy of the plugin re-ran the
 * bootstrap and corrupted every REST response on the request.
 *
 * Two copies can be active at once — `mcpwp/` and `mcpwp-premium/` ship the
 * same constants under different basenames. The second load re-ran the bare
 * define() calls, PHP emitted "already defined" warnings before headers were
 * sent, and the REST response went out as HTTP 200 text/html: warning markup
 * with absolute server paths, followed by the JSON that was supposed to be a
 * clean 401. A permission check that correctly denied the call was delivered
 * as a success.
 *
 * The fix is a no-op second load rather than guarded defines. Silencing the
 * warnings individually would have left a second, partial bootstrap registering
 * hooks over the first — the actual problem — running unobserved.
 *
 * Both properties are asserted here: the second load changes nothing, and it
 * says so somewhere an operator can find. A site running two copies has a
 * problem worth knowing about, and "no warnings" must not be the same as
 * "nothing wrong".
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class BootstrapDoubleLoadTest extends TestCase {

	private function plugin_file(): string {
		return dirname( __DIR__ ) . '/mcpwp.php';
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_second_load_is_a_no_op_and_records_itself(): void {
		// The harness bootstrap already defines MCPWP_VERSION, which is exactly
		// the state this guards: a copy has booted and owns the constants.
		$this->assertTrue( defined( 'MCPWP_VERSION' ), 'This test models a SECOND load; the first must already have happened.' );
		$first_copy_version = MCPWP_VERSION;

		if ( ! function_exists( 'plugin_basename' ) ) {
			function plugin_basename( $file ) {
				return 'mcpwp-premium/' . basename( $file );
			}
		}

		$GLOBALS['mcpwp_test_options'] = array();

		// Warnings become exceptions, so a redefine cannot pass silently — that
		// is the whole defect: on a real request it did not throw, it printed,
		// and the printing is what corrupted the response.
		set_error_handler(
			static function ( $errno, $errstr ) {
				throw new \ErrorException( $errstr, 0, $errno );
			}
		);

		try {
			require $this->plugin_file();
		} finally {
			restore_error_handler();
		}

		$this->assertSame(
			$first_copy_version,
			MCPWP_VERSION,
			'The first copy must keep ownership of the constants — a second load may not redefine them.'
		);

		$this->assertFalse(
			defined( 'MCPWP_PLUGIN_DIR' ),
			'The second load must return before the rest of the bootstrap, not merely skip the constants it happened to guard.'
		);

		$record = get_option( 'mcpwp_double_load' );

		$this->assertIsArray( $record, 'A second active copy is a real misconfiguration; it must be observable, not silently absorbed.' );
		$this->assertSame( 'mcpwp-premium/mcpwp.php', $record['ignored_basename'] );
		$this->assertSame( $first_copy_version, $record['active_version'] );
		$this->assertIsInt( $record['last_seen'] );
	}

	/**
	 * The guard has to come before the first define(), or it protects nothing.
	 * Asserted against the file text as well as the behaviour above, because a
	 * later edit that adds a constant above the guard would reopen exactly this
	 * bug while the separate-process test still passed for the constants that
	 * remained below it.
	 */
	public function test_no_constant_is_defined_before_the_double_load_guard(): void {
		$source = file_get_contents( $this->plugin_file() );

		$this->assertIsString( $source );

		$guard = strpos( $source, "if ( defined( 'MCPWP_VERSION' ) ) {" );
		$this->assertNotFalse( $guard, 'The double-load guard is missing.' );

		$this->assertSame(
			1,
			preg_match_all( "/^define\(\s*'MCPWP_VERSION'/m", $source ),
			'MCPWP_VERSION must be defined exactly once.'
		);

		if ( preg_match( "/^define\(\s*'MCPWP_/m", $source, $matches, PREG_OFFSET_CAPTURE ) ) {
			$this->assertGreaterThan(
				$guard,
				$matches[0][1],
				'A constant is defined before the guard runs, so a second load would redefine it.'
			);
		}
	}
}
