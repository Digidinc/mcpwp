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
}
