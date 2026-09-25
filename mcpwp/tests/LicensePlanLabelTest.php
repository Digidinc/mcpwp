<?php
/**
 * Regression test — the Setup/Settings pages must show "Plan: Free" for an
 * unlicensed (Free tier) install, not "Plan: Unlicensed".
 *
 * "Unlicensed" is the correct internal value: get_plan() is read by the
 * site-info capabilities payload and asserted verbatim by
 * LicenseCapabilitiesTest / LicenseWpConfigOverrideRemovedTest, so it must
 * not change. Mcpwp_License::get_plan_label() is the display-only mapping
 * used by admin/partials/mcpwp-setup-display.php and
 * admin/partials/mcpwp-settings-display.php.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class LicensePlanLabelTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']    = array();
		$GLOBALS['mcpwp_test_transients'] = array();
		$GLOBALS['mcpwp_test_filters']    = array();
		$GLOBALS['mcpwp_test_fs']         = null;
		$this->reset_license_singleton();
		$this->remove_agency_marker();
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_fs'] = null;
		$this->reset_license_singleton();
		$this->remove_agency_marker();
	}

	private function reset_license_singleton(): void {
		$ref      = new ReflectionClass( 'Mcpwp_License' );
		$instance = $ref->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );
	}

	private function marker_path(): string {
		return dirname( __DIR__ ) . '/includes/agency-license.php';
	}

	private function write_agency_marker( string $return_value ): void {
		$php = "<?php\nreturn " . var_export( $return_value, true ) . ";\n";
		file_put_contents( $this->marker_path(), $php );
	}

	private function remove_agency_marker(): void {
		$path = $this->marker_path();
		if ( is_file( $path ) ) {
			unlink( $path );
		}
	}

	public function test_unlicensed_plan_label_is_free_not_unlicensed(): void {
		$license = Mcpwp_License::get_instance();

		// Precondition, not an assumption: get_plan() is the internal value
		// that must remain 'unlicensed' — only the display label changes.
		$this->assertSame( 'unlicensed', $license->get_plan() );

		$this->assertSame( 'Free', $license->get_plan_label() );
	}

	public function test_pro_plan_label_is_unchanged(): void {
		$this->write_agency_marker( 'MCPWP_AGENCY_LICENSE_V1' );

		$license = Mcpwp_License::get_instance();

		$this->assertSame( 'pro', $license->get_plan() );
		$this->assertSame( 'Pro', $license->get_plan_label() );
	}

	public function test_multi_word_plan_slug_is_title_cased(): void {
		// get_plan_label() only depends on get_plan()'s return value, so drive
		// it through a subclass rather than wiring a full Freemius double for
		// an 'agency_annual'-style multi-word plan slug. Mcpwp_License's own
		// constructor is private (singleton), so the double needs its own
		// public no-op constructor to be instantiable at all.
		$double = new class() extends Mcpwp_License {
			public function __construct() {}
			public function get_plan() {
				return 'agency_annual';
			}
		};

		$this->assertSame( 'Agency Annual', $double->get_plan_label() );
	}
}
