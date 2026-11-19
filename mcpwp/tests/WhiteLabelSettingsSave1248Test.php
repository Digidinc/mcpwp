<?php
/**
 * White-label Settings API save must not re-enter save_settings via update_option (#1248).
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-white-label.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-mcpwp-settings.php';

final class WhiteLabelSettingsSave1248Test extends TestCase {

	/** @var Mcpwp_Settings */
	private $settings;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['mcpwp_test_options']   = array();
		$GLOBALS['mcpwp_test_filters']   = array();
		$this->settings                    = new Mcpwp_Settings();
		add_filter(
			'sanitize_option_' . Mcpwp_White_Label::OPTION_KEY,
			array( $this->settings, 'sanitize_white_label_settings' ),
			10,
			1
		);
		delete_option( Mcpwp_White_Label::OPTION_KEY );
	}

	protected function tearDown(): void {
		remove_filter(
			'sanitize_option_' . Mcpwp_White_Label::OPTION_KEY,
			array( $this->settings, 'sanitize_white_label_settings' ),
			10
		);
		delete_option( Mcpwp_White_Label::OPTION_KEY );
		parent::tearDown();
	}

	public function test_update_option_through_sanitize_option_filter_terminates_and_stores_logo(): void {
		$input = array(
			'enabled'             => '1',
			'agency_name'         => 'Acme Agency',
			'logo_url'            => 'https://cdn.example/l.png?w=64&h=64',
			'primary_color'       => '#336699',
			'chat_greeting'       => 'Hello',
			'hide_mcpwp_branding' => '1',
		);

		$sanitized = apply_filters(
			'sanitize_option_' . Mcpwp_White_Label::OPTION_KEY,
			$input,
			Mcpwp_White_Label::OPTION_KEY,
			$input
		);
		$this->assertIsArray( $sanitized );
		$this->assertSame( 'https://cdn.example/l.png?w=64&h=64', $sanitized['logo_url'] );

		$this->assertTrue( update_option( Mcpwp_White_Label::OPTION_KEY, $sanitized ) );
		$stored = get_option( Mcpwp_White_Label::OPTION_KEY );
		$this->assertIsArray( $stored );
		$this->assertSame( 'Acme Agency', $stored['agency_name'] );
		$this->assertSame( 'https://cdn.example/l.png?w=64&h=64', $stored['logo_url'] );
		$this->assertTrue( $stored['enabled'] );
	}

	public function test_sanitize_refuses_javascript_logo_url(): void {
		$input = array(
			'enabled'     => '1',
			'agency_name' => 'Agency',
			'logo_url'    => 'javascript:alert(1)',
		);

		$sanitized = apply_filters(
			'sanitize_option_' . Mcpwp_White_Label::OPTION_KEY,
			$input,
			Mcpwp_White_Label::OPTION_KEY,
			$input
		);
		$this->assertSame( '', $sanitized['logo_url'] );

		update_option( Mcpwp_White_Label::OPTION_KEY, $sanitized );
		$this->assertSame( '', get_option( Mcpwp_White_Label::OPTION_KEY )['logo_url'] );
	}

	public function test_update_option_does_not_re_enter_sanitize_beyond_single_pass(): void {
		$invocations = 0;
		add_filter(
			'sanitize_option_' . Mcpwp_White_Label::OPTION_KEY,
			function ( $value ) use ( &$invocations ) {
				++$invocations;
				if ( $invocations > 3 ) {
					throw new RuntimeException( 'sanitize_option re-entry loop' );
				}
				return $value;
			},
			5,
			1
		);

		$input = array(
			'enabled'     => '1',
			'agency_name' => 'Loop Test',
			'logo_url'    => 'https://example.com/logo.png',
		);

		update_option( Mcpwp_White_Label::OPTION_KEY, $input );
		$this->assertSame( 1, $invocations, 'update_option must run the sanitize filter exactly once' );
	}

	public function test_direct_save_settings_still_persists_for_rest_callers(): void {
		Mcpwp_White_Label::save_settings(
			array(
				'enabled'     => true,
				'agency_name' => 'Direct',
				'logo_url'    => 'file:///etc/passwd',
			)
		);
		$stored = Mcpwp_White_Label::get_settings();
		$this->assertSame( 'Direct', $stored['agency_name'] );
		$this->assertSame( '', $stored['logo_url'] );
	}
}