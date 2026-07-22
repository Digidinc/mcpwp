<?php

use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for the Elementor globals hardening:
 * - the MCP tool no longer advertises an unbounded "any kit setting" contract;
 * - the writable-key allowlist is exactly the 12 documented kit settings;
 * - kit custom CSS is sanitized before it is stored and rendered sitewide.
 *
 * These assertions run without a live WordPress/Elementor install; the WP
 * functions they touch are stubbed by tests/bootstrap.php.
 */
final class ElementorGlobalsHardeningTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['_mcpwp_test_posts']       = array();
		$GLOBALS['_mcpwp_test_meta']        = array();
		$GLOBALS['mcpwp_test_current_user'] = 1;
	}

	public function test_set_globals_tool_description_no_longer_advertises_any_kit_setting(): void {
		$registry = new Mcpwp_MCP_Pro_Tools();
		$tools    = $registry->get_tools();

		$def = null;
		foreach ( $tools as $tool ) {
			if ( 'wp_set_elementor_globals' === $tool['name'] ) {
				$def = $tool;
				break;
			}
		}

		$this->assertNotNull( $def, 'wp_set_elementor_globals tool should be registered.' );
		$this->assertStringNotContainsString( 'any kit setting works', $def['description'] );
		$this->assertStringContainsString( 'ignored', $def['description'] );
	}

	public function test_allowed_global_setting_types_lists_exactly_the_twelve_documented_keys(): void {
		$method = new ReflectionMethod( 'Mcpwp_REST_Elementor_Pro', 'allowed_global_setting_types' );
		$method->setAccessible( true );
		$allowed = $method->invoke( null );

		$expected = array(
			'system_colors',
			'custom_colors',
			'system_typography',
			'custom_typography',
			'custom_css',
			'container_width',
			'space_between_widgets',
			'page_title_selector',
			'stretched_section_container',
			'default_generic_fonts',
			'viewport_md',
			'viewport_lg',
		);

		$this->assertIsArray( $allowed );
		$this->assertCount( 12, $allowed );
		$this->assertSame( $expected, array_keys( $allowed ) );
	}

	public function test_sanitize_kit_custom_css_neutralizes_dangerous_constructs(): void {
		$controller = new Mcpwp_REST_Elementor_Pro( new stdClass() );

		$method = new ReflectionMethod( 'Mcpwp_REST_Elementor_Pro', 'sanitize_kit_custom_css' );
		$method->setAccessible( true );

		$css      = "@import url('//evil');a{behavior:url(x)}b{}</style><script>alert(1)</script>.x{color:red}";
		$warnings = array();
		$args     = array( $css, &$warnings );
		$result   = $method->invokeArgs( $controller, $args );

		$this->assertStringNotContainsString( '</style>', $result );
		$this->assertStringNotContainsString( '<script', $result );
		$this->assertStringNotContainsString( '@import', $result );
		$this->assertStringNotContainsString( 'behavior:', $result );
		$this->assertStringContainsString( '.x{color:red}', $result );
		$this->assertNotEmpty( $warnings, 'A sanitization warning should be reported.' );
	}

	public function test_sanitize_kit_custom_css_preserves_ordinary_rules(): void {
		$controller = new Mcpwp_REST_Elementor_Pro( new stdClass() );

		$method = new ReflectionMethod( 'Mcpwp_REST_Elementor_Pro', 'sanitize_kit_custom_css' );
		$method->setAccessible( true );

		$css      = '.x{color:red}';
		$warnings = array();
		$args     = array( $css, &$warnings );
		$result   = $method->invokeArgs( $controller, $args );

		$this->assertSame( '.x{color:red}', $result );
	}
}
