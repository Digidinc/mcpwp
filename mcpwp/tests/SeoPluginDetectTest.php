<?php

use PHPUnit\Framework\TestCase;

/**
 * Mcpwp_SEO_Plugin_Detect — always-loaded SEO-plugin presence checks.
 *
 * Uses the `mcpwp_seo_plugin_active_override` filter rather than defining
 * WPSEO_VERSION/RANK_MATH_VERSION/etc. directly: those are real PHP
 * constants, and once defined in a process they can never be un-defined,
 * which would leak "Yoast is active" into every other test in the same
 * PHPUnit run.
 */
final class SeoPluginDetectTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_filters'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_filters'] = array();
	}

	public function test_none_active_by_default(): void {
		$this->assertFalse( Mcpwp_SEO_Plugin_Detect::any_active() );
		$this->assertSame( '', Mcpwp_SEO_Plugin_Detect::active_plugin_slug() );
	}

	public function test_override_forces_yoast_active(): void {
		add_filter( 'mcpwp_seo_plugin_active_override', function () {
			return 'yoast';
		} );

		$this->assertTrue( Mcpwp_SEO_Plugin_Detect::any_active() );
		$this->assertSame( 'yoast', Mcpwp_SEO_Plugin_Detect::active_plugin_slug() );
	}

	public function test_override_forces_rankmath_active(): void {
		add_filter( 'mcpwp_seo_plugin_active_override', function () {
			return 'rankmath';
		} );

		$this->assertTrue( Mcpwp_SEO_Plugin_Detect::any_active() );
		$this->assertSame( 'rankmath', Mcpwp_SEO_Plugin_Detect::active_plugin_slug() );
	}

	public function test_override_can_force_none_active(): void {
		add_filter( 'mcpwp_seo_plugin_active_override', function () {
			return '';
		} );

		$this->assertFalse( Mcpwp_SEO_Plugin_Detect::any_active() );
	}
}
