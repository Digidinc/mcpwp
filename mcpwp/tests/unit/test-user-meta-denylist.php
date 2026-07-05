<?php
/**
 * Regression tests — user-meta privilege-escalation denylist.
 *
 * create_user()/update_user() must never write privilege-defining
 * user meta (capabilities / user level / session tokens) supplied through the
 * API, which would let a caller promote a new account to administrator via
 * meta.wp_capabilities.
 *
 * Test approach: the guard is a pure static predicate, so we exercise it
 * directly through a thin subclass — no WordPress user stubs required.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/pro/core/class-mcpwp-users.php';

/**
 * Exposes the protected static guard for assertion.
 */
class Mcpwp_Users_Meta_Probe extends Mcpwp_Users {

	public static function probe( string $meta_key ): bool {
		return self::is_protected_user_meta_key( $meta_key );
	}
}

final class UserMetaDenylistTest extends TestCase {

	public function test_blocks_capability_and_level_keys_including_multisite_variants(): void {
		$blocked = array(
			'wp_capabilities',
			'wp_2_capabilities',
			'capabilities',
			'wp_user_level',
			'wp_2_user_level',
			'user_level',
			'session_tokens',
		);
		foreach ( $blocked as $key ) {
			$this->assertTrue( Mcpwp_Users_Meta_Probe::probe( $key ), "$key must be blocked" );
		}
	}

	public function test_allows_ordinary_meta_keys(): void {
		$allowed = array(
			'first_name',
			'last_name',
			'nickname',
			'description',
			'billing_phone',
			'preferred_language',
			// Substring-but-not-suffix keys must NOT be caught by the regex.
			'capabilities_backup_note',
			'user_level_history',
		);
		foreach ( $allowed as $key ) {
			$this->assertFalse( Mcpwp_Users_Meta_Probe::probe( $key ), "$key must be allowed" );
		}
	}
}
