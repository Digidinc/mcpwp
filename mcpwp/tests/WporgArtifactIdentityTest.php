<?php
/**
 * Tests for the public identity of the WordPress.org artifact.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class WporgArtifactIdentityTest extends TestCase {

	private const SLUG = 'mumega-mcp';

	/** @var string */
	private static $zip_path;

	/** @var string[] */
	private static $manifest;

	public static function setUpBeforeClass(): void {
		$provided = getenv( 'MCPWP_WPORG_ZIP' );
		if ( false !== $provided && '' !== $provided ) {
			self::$zip_path = $provided;
		} else {
			$plugin_dir = dirname( __DIR__ );
			$source_main = file_get_contents( $plugin_dir . '/mcpwp.php' );
			if ( false === $source_main ) {
				throw new RuntimeException( 'Could not read source plugin header.' );
			}
			$version = self::header_value( $source_main, 'Version' );
			if ( '' === $version ) {
				throw new RuntimeException( 'Could not determine source plugin version.' );
			}
			self::$zip_path = $plugin_dir . '/scripts/' . self::SLUG . '-' . $version . '.zip';

			$command    = 'bash ' . escapeshellarg( $plugin_dir . '/scripts/build-wporg.sh' ) . ' 2>&1';
			exec( $command, $output, $status );
			if ( 0 !== $status ) {
				throw new RuntimeException( "WordPress.org build failed:\n" . implode( "\n", $output ) );
			}
		}

		if ( ! is_file( self::$zip_path ) ) {
			throw new RuntimeException( 'Candidate ZIP not found: ' . self::$zip_path );
		}

		self::$manifest = self::run_command( 'unzip -Z1 ' . escapeshellarg( self::$zip_path ) );
		if ( array() === self::$manifest ) {
			throw new RuntimeException( 'Candidate ZIP has an empty manifest.' );
		}
	}

	public function test_artifact_has_exact_public_root_and_main_file(): void {
		$roots = array_values(
			array_unique(
				array_map(
					static function ( $entry ) {
						return explode( '/', $entry, 2 )[0];
					},
					self::$manifest
				)
			)
		);

		$this->assertSame( array( self::SLUG ), $roots, 'Artifact must have exactly the mumega-mcp/ top-level root.' );
		$this->assertContains( self::SLUG . '/' . self::SLUG . '.php', self::$manifest, 'Artifact main file must be mumega-mcp/mumega-mcp.php.' );
	}

	public function test_main_file_header_has_exact_public_identity(): void {
		$main = $this->required_entry_contents( self::SLUG . '/' . self::SLUG . '.php' );

		$this->assertSame( 'Mumega MCP', self::header_value( $main, 'Plugin Name' ) );
		$this->assertSame( self::SLUG, self::header_value( $main, 'Text Domain' ) );
	}

	public function test_readme_and_versions_match_the_plugin_header(): void {
		$main   = $this->required_entry_contents( self::SLUG . '/' . self::SLUG . '.php' );
		$readme = $this->required_entry_contents( self::SLUG . '/readme.txt' );

		$this->assertMatchesRegularExpression( '/\A=== Mumega MCP ===\R/', $readme );
		$this->assertMatchesRegularExpression( "/define\(\s*'MCPWP_VERSION'\s*,\s*'([^']+)'\s*\)/", $main );
		preg_match( "/define\(\s*'MCPWP_VERSION'\s*,\s*'([^']+)'\s*\)/", $main, $constant );
		preg_match( '/^Stable tag:\s*(\S+)\s*$/mi', $readme, $stable_tag );

		$header_version = self::header_value( $main, 'Version' );
		$this->assertSame( $header_version, $constant[1], 'Plugin header and MCPWP_VERSION constant must match.' );
		$this->assertSame( $header_version, $stable_tag[1] ?? null, 'Stable tag must match the plugin version.' );
	}

	public function test_artifact_ships_only_the_canonical_translation_identity(): void {
		$pot_files = array_values(
			array_filter(
				self::$manifest,
				static function ( $entry ) {
					return 1 === preg_match( '#/languages/[^/]+\.pot$#', $entry );
				}
			)
		);
		$this->assertSame( array( self::SLUG . '/languages/' . self::SLUG . '.pot' ), $pot_files );

		$result = self::verify_zip( self::$zip_path );
		$this->assertSame( 0, $result['status'], implode( "\n", $result['output'] ) );
	}

	public function test_builder_rejects_explicit_version_that_differs_from_source_header(): void {
		$plugin_dir = dirname( __DIR__ );
		$wrong_zip  = $plugin_dir . '/scripts/' . self::SLUG . '-9.9.9.zip';
		@unlink( $wrong_zip );

		exec(
			'bash ' . escapeshellarg( $plugin_dir . '/scripts/build-wporg.sh' ) . ' --version 9.9.9 2>&1',
			$output,
			$status
		);
		$created = is_file( $wrong_zip );
		@unlink( $wrong_zip );

		$this->assertNotSame( 0, $status, 'Builder must fail when --version differs from the source header.' );
		$this->assertFalse( $created, 'Builder must not leave a mismatched-version ZIP behind.' );
	}

	public function test_verifier_rejects_filename_version_that_differs_from_artifact(): void {
		$temp_dir = sys_get_temp_dir() . '/mcpwp-wporg-' . bin2hex( random_bytes( 8 ) );
		if ( ! mkdir( $temp_dir, 0700, true ) && ! is_dir( $temp_dir ) ) {
			throw new RuntimeException( 'Could not create temporary artifact directory.' );
		}

		try {
			$mismatched = $temp_dir . '/' . self::SLUG . '-9.9.9.zip';
			if ( ! copy( self::$zip_path, $mismatched ) ) {
				throw new RuntimeException( 'Could not create mismatched-filename candidate.' );
			}
			$result = self::verify_zip( $mismatched );

			$this->assertNotSame( 0, $result['status'], 'Verifier must reject filename/version drift.' );
			$this->assertStringContainsString( '[FAIL] artifact filename version:', implode( "\n", $result['output'] ) );
		} finally {
			exec( 'rm -rf ' . escapeshellarg( $temp_dir ) );
		}
	}

	public function test_verifier_rejects_legacy_gettext_domain_after_semicolon_in_message(): void {
		$result = self::verify_zip_with_extra_php(
			"<?php\n\$internal_slug = 'mcpwp';\n__( 'Legacy; message', 'mcpwp' );\n"
		);

		$this->assertNotSame( 0, $result['status'], 'Verifier must reject the legacy gettext domain.' );
		$this->assertStringContainsString( '[FAIL] legacy gettext domain:', implode( "\n", $result['output'] ) );
	}

	public function test_verifier_rejects_legacy_gettext_domain_after_nested_message_expression(): void {
		$result = self::verify_zip_with_extra_php(
			"<?php\n__( sprintf( 'Legacy; %s', 'message' ), 'mcpwp' );\n"
		);

		$this->assertNotSame( 0, $result['status'], 'Verifier must inspect the final domain after nested calls.' );
		$this->assertStringContainsString( '[FAIL] legacy gettext domain:', implode( "\n", $result['output'] ) );
	}

	public function test_verifier_accepts_internal_mcpwp_literals_outside_gettext_calls(): void {
		$result = self::verify_zip_with_extra_php(
			"<?php\n\$server_name = 'mcpwp';\nstrpos( \$server_name, 'mcpwp' );\n"
		);

		$this->assertSame( 0, $result['status'], implode( "\n", $result['output'] ) );
	}

	private static function header_value( $contents, $header ): string {
		if ( 1 !== preg_match( '/^\s*\*\s*' . preg_quote( $header, '/' ) . ':\s*(.*?)\s*$/mi', $contents, $matches ) ) {
			return '';
		}

		return $matches[1];
	}

	private function required_entry_contents( $entry ): string {
		if ( ! in_array( $entry, self::$manifest, true ) ) {
			$this->fail( 'Required artifact entry is missing: ' . $entry );
		}

		return self::zip_contents( $entry );
	}

	private static function zip_contents( $entry ): string {
		$output = self::run_command( 'unzip -p ' . escapeshellarg( self::$zip_path ) . ' ' . escapeshellarg( $entry ) );
		return implode( "\n", $output );
	}

	/**
	 * @return array{status:int,output:string[]}
	 */
	private static function verify_zip( $zip_path ): array {
		$verifier = dirname( __DIR__ ) . '/scripts/verify-wporg-artifact.sh';
		exec( 'bash ' . escapeshellarg( $verifier ) . ' ' . escapeshellarg( $zip_path ) . ' 2>&1', $output, $status );

		return array( 'status' => $status, 'output' => $output );
	}

	/**
	 * @return array{status:int,output:string[]}
	 */
	private static function verify_zip_with_extra_php( $php_contents ): array {
		$temp_dir = sys_get_temp_dir() . '/mcpwp-wporg-' . bin2hex( random_bytes( 8 ) );
		if ( ! mkdir( $temp_dir, 0700, true ) && ! is_dir( $temp_dir ) ) {
			throw new RuntimeException( 'Could not create temporary artifact directory.' );
		}

		try {
			self::run_command( 'unzip -qq ' . escapeshellarg( self::$zip_path ) . ' -d ' . escapeshellarg( $temp_dir ) );
			$probe = $temp_dir . '/' . self::SLUG . '/includes/wporg-identity-probe.php';
			if ( false === file_put_contents( $probe, $php_contents ) ) {
				throw new RuntimeException( 'Could not create synthetic PHP probe.' );
			}
			$candidate = $temp_dir . '/' . basename( self::$zip_path );
			self::run_command(
				'cd ' . escapeshellarg( $temp_dir ) . ' && find ' . escapeshellarg( self::SLUG )
				. ' -type f -print | LC_ALL=C sort | zip -q -X ' . escapeshellarg( $candidate ) . ' -@'
			);

			return self::verify_zip( $candidate );
		} finally {
			exec( 'rm -rf ' . escapeshellarg( $temp_dir ) );
		}
	}

	/**
	 * @return string[]
	 */
	private static function run_command( $command ): array {
		exec( $command . ' 2>&1', $output, $status );
		if ( 0 !== $status ) {
			throw new RuntimeException( "Command failed: {$command}\n" . implode( "\n", $output ) );
		}

		return $output;
	}
}
