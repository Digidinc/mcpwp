<?php
/**
 * Coverage for #800 — the option allowlist was wrong both ways.
 *
 * Too strict: whole plugin namespaces the site plainly runs (`megamenu_*`,
 * `gtm4wp-options`, `wp_mail_smtp`) were unreachable, and a boolean named
 * `woocommerce_registration_generate_password` was refused because its name
 * contains "password". Too loose: `woocommerce_<gateway>_settings` returned
 * the merchant's live API passcode in plaintext to a read-only audit.
 *
 * Pinned here: access is an allowlist grown by active plugins and by an
 * admin-maintained per-site list, minus exact keys that are never reachable;
 * secrets are masked on read by field name and value shape, wherever they
 * sit; flags are never secrets; a refusal names its rule.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class OptionAccessTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 2;
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_options'] = array();
	}

	private function activate( string ...$files ): void {
		update_option( 'active_plugins', $files );
	}

	private function read( string $key ) {
		$request = new WP_REST_Request( 'GET', '/mcpwp/v1/option', array( 'key' => $key ) );
		return ( new Mcpwp_REST_Site_Settings() )->get_option_handler( $request );
	}

	private function write( string $key, $value ) {
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/option', array( 'key' => $key, 'value' => $value ) );
		return ( new Mcpwp_REST_Site_Settings() )->update_option_handler( $request );
	}

	// ---------------------------------------------------------------
	// Too strict, fixed.
	// ---------------------------------------------------------------

	public function test_a_plugin_namespace_opens_when_the_plugin_is_active(): void {
		update_option( 'megamenu_settings', array( 'primary' => 'header' ) );

		$refused = $this->read( 'megamenu_settings' );
		$this->assertInstanceOf( WP_Error::class, $refused, 'Not active: not reachable.' );
		$this->assertSame( 'not_allowlisted', $refused->get_error_data()['rule'] );

		$this->activate( 'megamenu/megamenu.php' );
		$allowed = $this->read( 'megamenu_settings' );

		$this->assertInstanceOf( WP_REST_Response::class, $allowed );
		$this->assertSame( 'plugin:megamenu', $allowed->get_data()['rule'] );
		$this->assertSame( array( 'primary' => 'header' ), $allowed->get_data()['value'] );
	}

	public function test_hyphenated_and_exact_plugin_keys_are_covered(): void {
		$this->activate( 'duracelltomi-google-tag-manager/duracelltomi-google-tag-manager-for-wordpress.php', 'wp-mail-smtp/wp_mail_smtp.php' );
		update_option( 'gtm4wp-options', array( 'gtm-code' => 'GTM-XYZ' ) );
		update_option( 'wp_mail_smtp', array( 'mail' => array( 'from_email' => 'hello@example.com' ) ) );

		$this->assertSame( 'GTM-XYZ', $this->read( 'gtm4wp-options' )->get_data()['value']['gtm-code'] );
		$this->assertSame( 'hello@example.com', $this->read( 'wp_mail_smtp' )->get_data()['value']['mail']['from_email'] );
	}

	public function test_a_flag_whose_name_contains_password_is_readable_and_writable(): void {
		update_option( 'woocommerce_registration_generate_password', 'no' );

		$this->assertSame( 'no', $this->read( 'woocommerce_registration_generate_password' )->get_data()['value'] );

		$written = $this->write( 'woocommerce_registration_generate_password', 'yes' );
		$this->assertInstanceOf( WP_REST_Response::class, $written );
		$this->assertSame( 'yes', get_option( 'woocommerce_registration_generate_password' ) );
		$this->assertSame( 'yes', $written->get_data()['value'], 'A flag is shown, not masked.' );
	}

	public function test_core_keys_keep_their_case(): void {
		// sanitize_key() lowercased WPLANG, an allowed core key, into an
		// unreachable one.
		update_option( 'WPLANG', 'de_DE' );

		$this->assertSame( 'de_DE', $this->read( 'WPLANG' )->get_data()['value'] );
	}

	public function test_an_admin_can_extend_the_allowlist_per_site(): void {
		update_option( 'acme_widget_settings', array( 'colour' => 'teal' ) );
		$this->assertInstanceOf( WP_Error::class, $this->read( 'acme_widget_settings' ) );

		$request = new WP_REST_Request( 'PUT', '/mcpwp/v1/plugin-settings', array( 'option_allowlist' => array( 'acme_widget_settings', 'acme_*' ) ) );
		$saved   = ( new Mcpwp_REST_Site_Settings() )->update_plugin_settings( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $saved, is_wp_error( $saved ) ? $saved->get_error_message() : '' );

		$read = $this->read( 'acme_widget_settings' );
		$this->assertInstanceOf( WP_REST_Response::class, $read );
		$this->assertSame( 'site_allowlist:acme_widget_settings', $read->get_data()['rule'] );

		update_option( 'acme_other', 1 );
		$this->assertSame( 'site_allowlist:acme_*', $this->read( 'acme_other' )->get_data()['rule'] );
	}

	// ---------------------------------------------------------------
	// Too loose, fixed.
	// ---------------------------------------------------------------

	public function test_secrets_inside_an_allowed_array_are_masked_and_listed(): void {
		update_option(
			'woocommerce_bambora_settings',
			array(
				'enabled'     => 'yes',
				'title'       => 'Credit card',
				'merchant_id' => '300200100',
				'api_key'     => 'live-4f8a…',
				'passcode'    => 'AbCdEf123456',
				'advanced'    => array( 'webhook_key' => 'whk_secret', 'debug' => 'no' ),
			)
		);

		$data = $this->read( 'woocommerce_bambora_settings' )->get_data();

		$this->assertSame( '***', $data['value']['api_key'] );
		$this->assertSame( '***', $data['value']['passcode'] );
		$this->assertSame( '***', $data['value']['advanced']['webhook_key'] );
		$this->assertSame( 'yes', $data['value']['enabled'] );
		$this->assertSame( 'Credit card', $data['value']['title'] );
		$this->assertSame( '300200100', $data['value']['merchant_id'], 'An id is not a secret.' );
		$this->assertSame( array( 'api_key', 'passcode', 'advanced.webhook_key' ), $data['redacted'] );
	}

	public function test_a_secret_scalar_option_is_masked_but_a_secret_flag_is_not(): void {
		update_option( 'woocommerce_api_key', 'sk_live_abcdef' );
		update_option( 'woocommerce_require_password', 'yes' );

		$this->assertSame( '***', $this->read( 'woocommerce_api_key' )->get_data()['value'] );
		$this->assertSame( array( '' ), $this->read( 'woocommerce_api_key' )->get_data()['redacted'] );
		$this->assertSame( 'yes', $this->read( 'woocommerce_require_password' )->get_data()['value'] );
	}

	public function test_a_write_may_set_a_secret_but_the_response_never_returns_it(): void {
		update_option( 'woocommerce_bambora_settings', array( 'passcode' => 'old-secret-1' ) );

		$written = $this->write( 'woocommerce_bambora_settings', array( 'passcode' => 'new-secret-2', 'enabled' => 'yes' ) );
		$data    = $written->get_data();

		$this->assertSame( 'new-secret-2', get_option( 'woocommerce_bambora_settings' )['passcode'], 'The write landed.' );
		$this->assertSame( '***', $data['value']['passcode'] );
		$this->assertSame( '***', $data['old_value']['passcode'] );
		$this->assertContains( 'passcode', $data['redacted'] );
	}

	// ---------------------------------------------------------------
	// Never reachable, and said so.
	// ---------------------------------------------------------------

	public function test_entitlement_and_updater_controls_stay_blocked_for_everyone_and_name_the_rule(): void {
		foreach ( array( 'mcpwp_update_info', 'mcpwp_version_url', 'mcpwp_pro_license', 'mcpwp_api_keys', 'mcpwp_integrations', 'mcpwp_settings', 'mcpwp_encryption_key', 'stripe_secret', 'auth_key' ) as $key ) {
			$error = $this->read( $key );
			$this->assertInstanceOf( WP_Error::class, $error, $key );
			$this->assertStringStartsWith( 'blocked:', $error->get_error_data()['rule'], $key );
			$this->assertInstanceOf( WP_Error::class, $this->write( $key, 'x' ), $key . ' write' );
		}
	}

	public function test_a_blocked_key_cannot_be_allowlisted(): void {
		$request = new WP_REST_Request( 'PUT', '/mcpwp/v1/plugin-settings', array( 'option_allowlist' => array( 'acme_ok', 'mcpwp_update_info', 'mcpwp_*' ) ) );
		$result  = ( new Mcpwp_REST_Site_Settings() )->update_plugin_settings( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_allowlist_entry', $result->get_error_code() );
		$this->assertStringContainsString( 'mcpwp_update_info', $result->get_error_message() );
		$this->assertSame( array(), Mcpwp_Option_Access::site_allowlist(), 'Nothing saved from a refused list.' );
	}

	public function test_the_refusal_says_how_to_lift_it(): void {
		$error = $this->read( 'some_plugin_option' );

		$this->assertSame( 'not_allowlisted', $error->get_error_data()['rule'] );
		$this->assertStringContainsString( 'option_allowlist', $error->get_error_data()['hint'] );
	}
}
