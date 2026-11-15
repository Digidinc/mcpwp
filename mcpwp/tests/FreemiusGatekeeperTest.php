<?php
/**
 * Public repository boundary check for Freemius wp_org_gatekeeper.
 *
 * includes/freemius-init.php in the public GPL repository must never carry a
 * hardcoded wp_org_gatekeeper secret. Instead, scripts/build-freemius.sh
 * injects it into the packaged Freemius zip only when FREEMIUS_WP_ORG_GATEKEEPER
 * is set in the build environment, and builds cleanly without it when unset.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class FreemiusGatekeeperTest extends TestCase {

	private static function plugin_dir(): string {
		return dirname( __DIR__ ) . '/';
	}

	public function test_public_freemius_bootstrap_contains_no_hardcoded_gatekeeper(): void {
		$src = (string) file_get_contents( self::plugin_dir() . 'includes/freemius-init.php' );

		$this->assertDoesNotMatchRegularExpression(
			"/'wp_org_gatekeeper'\s*=>/",
			$src,
			'includes/freemius-init.php must not commit a hardcoded wp_org_gatekeeper value in the public repo'
		);
	}

	public function test_build_freemius_supports_optional_env_injection_with_valid_php_syntax(): void {
		$test_version = '0.0.0-gatekeeper-test';
		$script       = self::plugin_dir() . 'scripts/build-freemius.sh';
		$output_zip   = self::plugin_dir() . 'scripts/mcpwp-freemius-' . $test_version . '.zip';

		try {
			// 1. Unset FREEMIUS_WP_ORG_GATEKEEPER: builds cleanly and omits wp_org_gatekeeper.
			$cmd_unset = sprintf(
				'env -u FREEMIUS_WP_ORG_GATEKEEPER bash %s --version %s 2>&1',
				escapeshellarg( $script ),
				escapeshellarg( $test_version )
			);
			exec( $cmd_unset, $out_unset, $rc_unset );
			$this->assertSame( 0, $rc_unset, implode( "\n", $out_unset ) );
			$this->assertFileExists( $output_zip );

			$zip = new ZipArchive();
			$this->assertTrue( true === $zip->open( $output_zip ) );
			$packaged_unset = (string) $zip->getFromName( 'mcpwp/includes/freemius-init.php' );
			$zip->close();

			$this->assertNotSame( '', $packaged_unset );
			$this->assertDoesNotMatchRegularExpression( "/'wp_org_gatekeeper'\s*=>/", $packaged_unset );
			@unlink( $output_zip );

			// 2. Set FREEMIUS_WP_ORG_GATEKEEPER with shell/PHP-sensitive characters ($, !, #, ', \).
			$secret  = "gk_test\$7K1#val!!'quote\\slash";
			$cmd_set = sprintf(
				'FREEMIUS_WP_ORG_GATEKEEPER=%s bash %s --version %s 2>&1',
				escapeshellarg( $secret ),
				escapeshellarg( $script ),
				escapeshellarg( $test_version )
			);
			$out_set = array();
			exec( $cmd_set, $out_set, $rc_set );
			$this->assertSame( 0, $rc_set, implode( "\n", $out_set ) );
			$this->assertFileExists( $output_zip );

			$zip = new ZipArchive();
			$this->assertTrue( true === $zip->open( $output_zip ) );
			$packaged_set = (string) $zip->getFromName( 'mcpwp/includes/freemius-init.php' );
			$zip->close();

			$this->assertMatchesRegularExpression( "/'wp_org_gatekeeper'\s*=>/", $packaged_set );

			// Evaluate only the fs_dynamic_init array literal to verify exact round-trip value.
			$this->assertSame( 1, preg_match( '/\$mcpwp_fs\s*=\s*fs_dynamic_init\(\s*(array\(.*?\))\s*\);/s', $packaged_set, $m ) );
			$tmp = tempnam( sys_get_temp_dir(), 'mcpwp-gk-' );
			file_put_contents( $tmp, "<?php\nreturn " . $m[1] . ";\n" );
			$parsed = include $tmp;
			@unlink( $tmp );

			$this->assertIsArray( $parsed );
			$this->assertSame( $secret, $parsed['wp_org_gatekeeper'] ?? null );

			// Working tree source must remain untouched.
			$this->test_public_freemius_bootstrap_contains_no_hardcoded_gatekeeper();
		} finally {
			if ( file_exists( $output_zip ) ) {
				@unlink( $output_zip );
			}
		}
	}
}
