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

	/**
	 * Unchecked HTML checkboxes are omitted from POST — sanitize must coerce both to false.
	 *
	 * Mutation: removing the $raw['enabled'] / $raw['hide_mcpwp_branding'] !empty()
	 * lines in sanitize_white_label_settings() lets hide_mcpwp_branding fall back to
	 * defaults() (true) and this test fails.
	 */
	public function test_form_submit_without_checkbox_fields_stores_both_booleans_false(): void {
		$input = array(
			'agency_name'   => 'Unchecked Agency',
			'logo_url'      => 'https://cdn.example/l.png',
			'primary_color' => '#112233',
			'chat_greeting' => 'Hi',
		);

		$this->assertTrue( update_option( Mcpwp_White_Label::OPTION_KEY, $input ) );
		$stored = get_option( Mcpwp_White_Label::OPTION_KEY );
		$this->assertIsArray( $stored );
		$this->assertArrayHasKey( 'enabled', $stored );
		$this->assertArrayHasKey( 'hide_mcpwp_branding', $stored );
		$this->assertFalse( $stored['enabled'], 'Absent enabled checkbox must persist false' );
		$this->assertFalse(
			$stored['hide_mcpwp_branding'],
			'Absent hide_mcpwp_branding checkbox must persist false, not defaults() true'
		);
	}

	/**
	 * Logo URLs are validated with esc_url_raw() for storage (raw & in query strings).
	 *
	 * Mutation: swapping esc_url_raw() for esc_url() in validate_logo_url_for_storage()
	 * stores &#038; when strict esc_url is enabled (WordPress-like) and this test fails.
	 */
	public function test_stored_logo_url_keeps_raw_ampersand_in_query_string(): void {
		$logo = 'https://cdn.example/l.png?w=64&h=64';
		$GLOBALS['mcpwp_test_strict_esc_url'] = true;

		try {
			$input = array(
				'enabled'             => '1',
				'agency_name'         => 'Ampersand Agency',
				'logo_url'            => $logo,
				'hide_mcpwp_branding' => '1',
			);

			$sanitized = apply_filters(
				'sanitize_option_' . Mcpwp_White_Label::OPTION_KEY,
				$input,
				Mcpwp_White_Label::OPTION_KEY,
				$input
			);
			$this->assertStringNotContainsString( '&#038;', $sanitized['logo_url'] );
			$this->assertStringContainsString( '&', $sanitized['logo_url'] );
			$this->assertSame( $logo, $sanitized['logo_url'] );

			update_option( Mcpwp_White_Label::OPTION_KEY, $sanitized );
			$stored = get_option( Mcpwp_White_Label::OPTION_KEY );
			$this->assertSame( $logo, $stored['logo_url'] );
			$this->assertSame( $logo, Mcpwp_White_Label::validate_logo_url_for_storage( $logo ) );
		} finally {
			unset( $GLOBALS['mcpwp_test_strict_esc_url'] );
		}
	}
}