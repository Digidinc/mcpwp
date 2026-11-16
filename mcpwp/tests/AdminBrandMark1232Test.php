<?php
/**
 * Admin header badge uses the approved ochre M/W mark (#1232).
 *
 * @package MCPWP
 */

require_once dirname( __DIR__ ) . '/includes/admin/class-mcpwp-admin-brand.php';

use PHPUnit\Framework\TestCase;

final class AdminBrandMark1232Test extends TestCase {

	private static function plugin_dir() {
		return dirname( __DIR__ ) . '/';
	}

	private static function admin_partial_paths() {
		$dir  = self::plugin_dir() . 'admin/partials/';
		$glob = glob( $dir . '*.php' );
		return is_array( $glob ) ? $glob : array();
	}

	public function test_admin_partials_do_not_contain_dashicons_shield() {
		foreach ( self::admin_partial_paths() as $path ) {
			$src = (string) file_get_contents( $path );
			$this->assertStringNotContainsString( 'dashicons-shield', $src, basename( $path ) );
		}
	}

	public function test_approved_svg_and_256_png_sha256_match_provenance() {
		$svg = self::plugin_dir() . Mcpwp_Admin_Brand::HEADER_MARK_SVG_REL;
		$png = self::plugin_dir() . Mcpwp_Admin_Brand::HEADER_MARK_PNG_256_REL;

		$this->assertFileExists( $svg );
		$this->assertFileExists( $png );
		$this->assertSame(
			Mcpwp_Admin_Brand::HEADER_MARK_SVG_SHA256,
			hash_file( 'sha256', $svg )
		);
		$this->assertSame(
			Mcpwp_Admin_Brand::HEADER_MARK_PNG_256_SHA256,
			hash_file( 'sha256', $png )
		);
	}

	public function test_header_logo_helper_emits_img_with_expected_attributes() {
		if ( ! defined( 'MCPWP_PLUGIN_URL' ) ) {
			define( 'MCPWP_PLUGIN_URL', 'https://example.test/wp-content/plugins/mcpwp/' );
		}
		$markup = Mcpwp_Admin_Brand::get_header_logo_img_markup();
		$this->assertStringContainsString( '<img class="mcpwp-logo-mark"', $markup );
		$this->assertStringContainsString(
			'src="' . esc_url( MCPWP_PLUGIN_URL . Mcpwp_Admin_Brand::HEADER_MARK_SVG_REL ) . '"',
			$markup
		);
		$this->assertStringContainsString( 'alt="MCPWP logo"', $markup );
		$this->assertStringContainsString( 'width="42"', $markup );
		$this->assertStringContainsString( 'height="42"', $markup );
	}

	public function test_freemius_plugin_icon_png_matches_approved_256_sha256() {
		$icon = self::plugin_dir() . Mcpwp_Admin_Brand::FREEMIUS_ICON_REL;
		$this->assertFileExists( $icon );
		$this->assertSame(
			Mcpwp_Admin_Brand::HEADER_MARK_PNG_256_SHA256,
			hash_file( 'sha256', $icon )
		);
	}
}