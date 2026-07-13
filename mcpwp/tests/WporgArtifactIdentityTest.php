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
			$command    = 'bash ' . escapeshellarg( $plugin_dir . '/scripts/build-wporg.sh' ) . ' 2>&1';
			exec( $command, $output, $status );
			if ( 0 !== $status ) {
				throw new RuntimeException( "WordPress.org build failed:\n" . implode( "\n", $output ) );
			}

			$candidates = glob( $plugin_dir . '/scripts/*.zip' );
			if ( false === $candidates || array() === $candidates ) {
				throw new RuntimeException( 'WordPress.org builder did not produce a ZIP.' );
			}
			usort(
				$candidates,
				static function ( $left, $right ) {
					return filemtime( $right ) <=> filemtime( $left );
				}
			);
			self::$zip_path = $candidates[0];
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

		$legacy_domains = array();
		foreach ( self::$manifest as $entry ) {
			if ( 1 !== preg_match( '/\.php$/', $entry ) ) {
				continue;
			}
			$contents = self::zip_contents( $entry );
			if ( preg_match( '/\b(?:__|_e|_x|_ex|_n|_nx|_n_noop|_nx_noop|translate|translate_with_gettext_context|esc_html__|esc_html_e|esc_html_x|esc_attr__|esc_attr_e|esc_attr_x)\s*\((?:(?!;).)*,\s*([\'\"])(?:mumcp|mcpwp)\1\s*\)/s', $contents ) ) {
				$legacy_domains[] = $entry;
			}
		}

		$this->assertSame( array(), $legacy_domains, 'Shipped PHP must not use mumcp or mcpwp as a literal gettext domain.' );
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
