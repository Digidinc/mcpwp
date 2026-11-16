<?php
/**
 * Admin header badge uses the approved ochre M/W mark (#1232).
 *
 * @package MCPWP
 */

require_once dirname( __DIR__ ) . '/includes/admin/class-mcpwp-admin-brand.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-white-label.php';

use PHPUnit\Framework\TestCase;

final class AdminBrandMark1232Test extends TestCase {

	private static function plugin_dir() {
		return dirname( __DIR__ ) . '/';
	}

	/** @var string[] */
	private static $header_partial_basenames = array(
		'mcpwp-setup-display.php',
		'mcpwp-control-room-display.php',
		'mcpwp-chat-display.php',
		'mcpwp-library-display.php',
		'mcpwp-integrations-display.php',
		'mcpwp-settings-display.php',
		'mcpwp-tools-display.php',
	);

	private static function extract_header_badge_markup( string $src ): string {
		if ( 1 !== preg_match( '#<h1 class="mcpwp-header">.*?<span class="mcpwp-logo">(.*?)</span>#s', $src, $m ) ) {
			return '';
		}
		return $m[1];
	}

	public function test_seven_admin_header_badges_do_not_contain_dashicons_shield() {
		$dir = self::plugin_dir() . 'admin/partials/';
		foreach ( self::$header_partial_basenames as $basename ) {
			$src   = (string) file_get_contents( $dir . $basename );
			$badge = self::extract_header_badge_markup( $src );
			$this->assertNotSame( '', $badge, $basename . ' header badge not found' );
			$this->assertStringNotContainsString( 'dashicons-shield', $badge, $basename . ' header badge' );
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
			'src="' . esc_url( MCPWP_PLUGIN_URL . 'assets/mcpwp-mark-ochre.svg' ) . '"',
			$markup
		);
		$this->assertStringContainsString( 'alt="MCPWP logo"', $markup );
		$this->assertStringContainsString( 'width="42"', $markup );
		$this->assertStringContainsString( 'height="42"', $markup );
	}

	public function test_white_label_with_agency_logo_omits_hidden_product_img_markup() {
		update_option(
			Mcpwp_White_Label::OPTION_KEY,
			array(
				'enabled'     => true,
				'agency_name' => 'Agency',
				'logo_url'    => 'https://agency.test/logo.png',
			)
		);

		$markup = Mcpwp_Admin_Brand::get_header_badge_markup();
		$this->assertSame( '', $markup );

		delete_option( Mcpwp_White_Label::OPTION_KEY );
	}

	public function test_white_label_with_agency_logo_hides_mark_and_resets_badge_container() {
		if ( ! function_exists( 'esc_js' ) ) {
			function esc_js( $text ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
				return (string) $text;
			}
		}

		update_option(
			Mcpwp_White_Label::OPTION_KEY,
			array(
				'enabled'             => true,
				'agency_name'         => 'Agency',
				'logo_url'            => 'https://agency.test/logo.png',
				'hide_mcpwp_branding' => true,
			)
		);

		ob_start();
		Mcpwp_White_Label::inject_admin_branding_css();
		$css = (string) ob_get_clean();

		$this->assertStringContainsString( '.mcpwp-logo .mcpwp-logo-mark { display: none !important; }', $css );
		$this->assertStringContainsString( 'background: none', $css );
		$this->assertStringContainsString( 'https://agency.test/logo.png', $css );

		delete_option( Mcpwp_White_Label::OPTION_KEY );
	}

	public function test_hide_mcpwp_branding_without_logo_shows_neutral_shield_not_product_mark() {
		update_option(
			Mcpwp_White_Label::OPTION_KEY,
			array(
				'enabled'             => true,
				'agency_name'         => 'Agency',
				'logo_url'            => '',
				'hide_mcpwp_branding' => true,
			)
		);

		$markup = Mcpwp_Admin_Brand::get_header_badge_markup();
		$this->assertStringContainsString( 'dashicons-shield', $markup );
		$this->assertStringNotContainsString( 'mcpwp-logo-mark', $markup );

		delete_option( Mcpwp_White_Label::OPTION_KEY );
	}
}
