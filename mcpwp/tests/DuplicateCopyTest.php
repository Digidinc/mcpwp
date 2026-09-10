<?php
/**
 * Coverage for #802 item 7 — two installed copies of MCPWP.
 *
 * The reporter had 3.10.7 active beside an inactive 3.10.5 folder after a
 * manual replace. #660 made a second load a silent no-op (first copy wins and
 * records itself under `mcpwp_double_load`), which is what stopped the
 * "plugin already loaded" fatal. But silent is the problem: nothing told the
 * admin, and activating the second folder was still allowed — and because the
 * losing copy's file returns before it registers any hook, WordPress activated
 * it without a word.
 *
 * Two behaviours are pinned:
 *
 *   - Activating a second MCPWP copy is refused: it is deactivated again and
 *     the admin is told which folder and why.
 *   - A recorded double load is surfaced as an admin notice while it is
 *     current, and not forever after the duplicate is gone.
 *
 * The decisions are pure functions over the record and the plugin headers,
 * so they are tested without WordPress.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class DuplicateCopyTest extends TestCase {

	private const OWN = 'mcpwp/mcpwp.php';

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']    = array();
		$GLOBALS['mcpwp_test_transients'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_options']    = array();
		$GLOBALS['mcpwp_test_transients'] = array();
	}

	private function mcpwp_header(): string {
		return "<?php\n/**\n * Plugin Name: MCPWP\n * Version: 3.10.5\n */\n";
	}

	// ---------------------------------------------------------------
	// Recognising a copy.
	// ---------------------------------------------------------------

	public function test_a_file_with_the_mcpwp_plugin_header_is_a_copy(): void {
		$this->assertTrue( Mcpwp_Duplicate_Copy::is_mcpwp_main_file( $this->mcpwp_header() ) );
	}

	public function test_a_premium_or_renamed_folder_is_still_a_copy(): void {
		// The folder name is not the identity; the header is. mcpwp-premium/,
		// mcpwp-1/, mcpwp (2)/ all carry the same header.
		$this->assertTrue( Mcpwp_Duplicate_Copy::is_mcpwp_main_file( "<?php\n/*\nPlugin Name: MCPWP Premium\nVersion: 3.10.7\n*/" ) );
	}

	public function test_another_plugin_is_not_a_copy(): void {
		$this->assertFalse( Mcpwp_Duplicate_Copy::is_mcpwp_main_file( "<?php\n/**\n * Plugin Name: Elementor\n */" ) );
		$this->assertFalse( Mcpwp_Duplicate_Copy::is_mcpwp_main_file( "<?php\n/**\n * Plugin Name: MCPWP Addon for Forms\n * Requires Plugins: mcpwp\n */" ) );
	}

	public function test_the_running_copy_is_never_its_own_duplicate(): void {
		$this->assertFalse( Mcpwp_Duplicate_Copy::should_refuse( self::OWN, self::OWN, $this->mcpwp_header() ) );
	}

	public function test_a_second_copy_is_refused(): void {
		$this->assertTrue( Mcpwp_Duplicate_Copy::should_refuse( 'mcpwp-1/mcpwp.php', self::OWN, $this->mcpwp_header() ) );
	}

	public function test_a_plugin_whose_file_cannot_be_read_is_not_refused(): void {
		// Unreadable is not "a duplicate". Refusing on a guess would deactivate
		// an unrelated plugin the admin just enabled.
		$this->assertFalse( Mcpwp_Duplicate_Copy::should_refuse( 'other/other.php', self::OWN, null ) );
	}

	// ---------------------------------------------------------------
	// Refusing activation.
	// ---------------------------------------------------------------

	public function test_activating_a_duplicate_deactivates_it_and_records_why(): void {
		$deactivated = array();
		$guard       = new Mcpwp_Duplicate_Copy(
			self::OWN,
			function ( $basename ) use ( &$deactivated ) {
				$deactivated[] = $basename;
			},
			function ( $basename ) {
				return "<?php\n/**\n * Plugin Name: MCPWP\n * Version: 3.10.5\n */";
			}
		);

		$guard->on_activated_plugin( 'mcpwp-1/mcpwp.php' );

		$this->assertSame( array( 'mcpwp-1/mcpwp.php' ), $deactivated );

		$notice = get_transient( Mcpwp_Duplicate_Copy::REFUSED_TRANSIENT );
		$this->assertSame( 'mcpwp-1/mcpwp.php', $notice['basename'] );
		$this->assertSame( '3.10.5', $notice['version'] );
	}

	public function test_activating_an_unrelated_plugin_is_left_alone(): void {
		$deactivated = array();
		$guard       = new Mcpwp_Duplicate_Copy(
			self::OWN,
			function ( $basename ) use ( &$deactivated ) {
				$deactivated[] = $basename;
			},
			function ( $basename ) {
				return "<?php\n/**\n * Plugin Name: Elementor\n */";
			}
		);

		$guard->on_activated_plugin( 'elementor/elementor.php' );

		$this->assertSame( array(), $deactivated );
		$this->assertFalse( get_transient( Mcpwp_Duplicate_Copy::REFUSED_TRANSIENT ) );
	}

	// ---------------------------------------------------------------
	// Telling the admin.
	// ---------------------------------------------------------------

	public function test_a_current_double_load_produces_a_notice_naming_the_folder(): void {
		update_option(
			'mcpwp_double_load',
			array(
				'ignored_basename' => 'mcpwp-premium/mcpwp.php',
				'active_version'   => '3.10.8',
				'last_seen'        => time() - 60,
			)
		);

		$message = Mcpwp_Duplicate_Copy::double_load_message( get_option( 'mcpwp_double_load' ), time() );

		$this->assertIsString( $message );
		$this->assertStringContainsString( 'mcpwp-premium/mcpwp.php', $message );
		$this->assertStringContainsString( '3.10.8', $message );
	}

	public function test_a_stale_double_load_record_produces_no_notice(): void {
		// Seen last week, not since: the duplicate has been removed. A notice
		// that never goes away trains admins to ignore notices.
		$record = array(
			'ignored_basename' => 'mcpwp-premium/mcpwp.php',
			'active_version'   => '3.10.8',
			'last_seen'        => time() - 7 * DAY_IN_SECONDS,
		);

		$this->assertNull( Mcpwp_Duplicate_Copy::double_load_message( $record, time() ) );
	}

	public function test_no_record_produces_no_notice(): void {
		$this->assertNull( Mcpwp_Duplicate_Copy::double_load_message( false, time() ) );
		$this->assertNull( Mcpwp_Duplicate_Copy::double_load_message( array(), time() ) );
	}

	public function test_a_refusal_produces_a_notice_naming_the_folder(): void {
		$message = Mcpwp_Duplicate_Copy::refused_message(
			array(
				'basename' => 'mcpwp-1/mcpwp.php',
				'version'  => '3.10.5',
			)
		);

		$this->assertStringContainsString( 'mcpwp-1/mcpwp.php', $message );
		$this->assertStringContainsString( 'deactivated', $message );
	}
}
