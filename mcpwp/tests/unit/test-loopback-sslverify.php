<?php
/**
 * Production loopback HTTP fetches must not hard-disable TLS verification.
 *
 * wp_get_rendered_html / SEO fetchers already gate sslverify on WP_DEBUG.
 * Custom CSS verification and Elementor CSS priming still used sslverify=false
 * unconditionally, which disables MITM protection on those loopbacks.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class LoopbackSslverifyTest extends TestCase {

	private function php_files(): array {
		$root = dirname( __DIR__, 2 );
		return array(
			$root . '/includes/api/class-mcpwp-rest-site-custom-css.php',
			$root . '/includes/core/traits/trait-mcpwp-elementor-css.php',
		);
	}

	public function test_loopback_files_do_not_hardcode_sslverify_false(): void {
		foreach ( $this->php_files() as $path ) {
			$this->assertFileExists( $path );
			$source = file_get_contents( $path );
			$this->assertNotFalse( $source );
			$this->assertDoesNotMatchRegularExpression(
				"/['\"]sslverify['\"]\\s*=>\\s*false/",
				$source,
				$path . ' hard-disables TLS verification'
			);
		}
	}

	public function test_loopback_files_gate_sslverify_on_wp_debug(): void {
		foreach ( $this->php_files() as $path ) {
			$source = file_get_contents( $path );
			$this->assertNotFalse( strpos( $source, 'sslverify' ), $path . ' has no sslverify policy' );
			$this->assertMatchesRegularExpression(
				"/['\"]sslverify['\"]\\s*=>\\s*!\\s*\\(\\s*defined\\(\\s*'WP_DEBUG'\\s*\\)\\s*&&\\s*WP_DEBUG\\s*\\)/",
				$source,
				$path . ' must gate sslverify on WP_DEBUG like other fetchers'
			);
		}
	}
}
