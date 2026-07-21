<?php

use PHPUnit\Framework\TestCase;

/**
 * Broker-enrollment-aware .well-known/oauth-protected-resource — unit tests.
 *
 * Plan 2 / Task 1: when a site is enrolled with the mumcp.com broker
 * (`mcpwp_broker_enrollment` option holds a non-empty array with a `site_id`),
 * the RFC 9728 protected-resource metadata must:
 *   - point `authorization_servers` at `https://mumcp.com`
 *   - be served regardless of the legacy `oauth_enabled` setting
 *
 * When the site is NOT broker-enrolled, behavior must be byte-for-byte
 * unchanged from the pre-broker implementation (self as AS, gated on
 * `oauth_enabled` alone).
 *
 * These tests exercise the pure metadata builder
 * (`build_protected_resource_metadata()`) and the serve-gate predicate
 * (`should_serve_protected_resource_metadata()`) directly, rather than the
 * `do_parse_request` dispatcher (`maybe_serve_well_known()`), because the
 * dispatcher calls `exit()` on a match — invoking it here would kill the
 * PHPUnit process.
 *
 * @package MCPWP
 */
final class BrokerWellKnownTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']    = array();
		$GLOBALS['mcpwp_test_transients'] = array();
		unset( $_SERVER['HTTP_X_FORWARDED_PROTO'] );
	}

	private function enroll( array $overrides = array() ): void {
		update_option(
			'mcpwp_broker_enrollment',
			array_merge(
				array(
					'site_id'         => 'abc',
					'audience'        => home_url(),
					'site_credential' => 'x',
				),
				$overrides
			)
		);
	}

	// ── Broker-enrolled ────────────────────────────────────────────────────

	public function test_broker_enrolled_points_authorization_servers_at_mumcp(): void {
		$this->enroll();

		$metadata = Mcpwp_OAuth_Well_Known::build_protected_resource_metadata();

		$this->assertSame( array( 'https://mumcp.com' ), $metadata['authorization_servers'] );
	}

	public function test_broker_enrolled_resource_is_site_canonical_url(): void {
		$this->enroll();

		$metadata = Mcpwp_OAuth_Well_Known::build_protected_resource_metadata();
		$origin   = Mcpwp_OAuth_Well_Known::get_site_origin();
		$prefix   = Mcpwp_OAuth_Well_Known::get_site_path_prefix();

		$this->assertSame( $origin . $prefix, $metadata['resource'] );
	}

	public function test_broker_enrolled_serves_regardless_of_legacy_oauth_setting(): void {
		$this->enroll();
		update_option( 'mcpwp_settings', array( 'oauth_enabled' => false ) );

		$this->assertTrue(
			Mcpwp_OAuth_Well_Known::should_serve_protected_resource_metadata(),
			'Broker enrollment must independently enable protected-resource discovery, even with legacy OAuth off.'
		);
	}

	public function test_broker_enrolled_and_legacy_oauth_also_on_still_points_at_mumcp(): void {
		$this->enroll();
		update_option( 'mcpwp_settings', array( 'oauth_enabled' => true ) );

		$metadata = Mcpwp_OAuth_Well_Known::build_protected_resource_metadata();

		$this->assertSame(
			array( 'https://mumcp.com' ),
			$metadata['authorization_servers'],
			'Broker enrollment must win over the legacy self-issued AS when both are active.'
		);
	}

	public function test_empty_broker_enrollment_array_is_not_enrolled(): void {
		update_option( 'mcpwp_broker_enrollment', array() );
		update_option( 'mcpwp_settings', array( 'oauth_enabled' => false ) );

		$this->assertFalse(
			Mcpwp_OAuth_Well_Known::should_serve_protected_resource_metadata(),
			'An empty array option value must not count as enrolled.'
		);
	}

	public function test_broker_enrollment_without_site_id_is_not_enrolled(): void {
		update_option( 'mcpwp_broker_enrollment', array( 'audience' => home_url() ) );
		update_option( 'mcpwp_settings', array( 'oauth_enabled' => false ) );

		$this->assertFalse(
			Mcpwp_OAuth_Well_Known::should_serve_protected_resource_metadata(),
			'A site_id is required for broker enrollment to count as active.'
		);
	}

	// ── Not enrolled — prior behavior must be unchanged ─────────────────────

	public function test_not_enrolled_legacy_oauth_off_does_not_serve(): void {
		update_option( 'mcpwp_settings', array( 'oauth_enabled' => false ) );

		$this->assertFalse(
			Mcpwp_OAuth_Well_Known::should_serve_protected_resource_metadata(),
			'Without enrollment and with legacy OAuth off, the endpoint must not serve (unchanged 404-fallthrough behavior).'
		);
	}

	public function test_not_enrolled_legacy_oauth_on_still_self_serves(): void {
		update_option( 'mcpwp_settings', array( 'oauth_enabled' => true ) );

		$this->assertTrue(
			Mcpwp_OAuth_Well_Known::should_serve_protected_resource_metadata(),
			'Legacy oauth_enabled must continue to independently enable the endpoint (unchanged).'
		);

		$metadata = Mcpwp_OAuth_Well_Known::build_protected_resource_metadata();
		$origin   = Mcpwp_OAuth_Well_Known::get_site_origin();
		$prefix   = Mcpwp_OAuth_Well_Known::get_site_path_prefix();

		$this->assertSame(
			array( $origin . '/.well-known/oauth-authorization-server' . $prefix ),
			$metadata['authorization_servers'],
			'Not broker-enrolled: the site is still its own AS, exactly as before.'
		);
	}
}
