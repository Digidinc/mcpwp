<?php
/**
 * A stored credential verifier must never authenticate as itself.
 *
 * The legacy single-key options (`mcpwp_api_key`, `spai_api_key`) hold a
 * wp_hash_password() hash, or on very old installs the key in plaintext. The
 * match used to be "wp_check_password(), else hash_equals( stored, presented )"
 * — so presenting the stored HASH STRING itself matched the plaintext branch
 * and authenticated as admin. Anyone able to read one option row (a backup, an
 * export, a read-only SQL injection) had an admin key without cracking anything.
 *
 * Covered here, for every hash shape WordPress has produced (`$wp$2y$`, `$2y$`,
 * `$P$`, `$argon2id$`, MD5) and every store and carrier (legacy option, spai
 * single, spai store record, scoped store record; X-API-Key, Bearer, ?api_key=;
 * the direct and the sub-request auth plane):
 *  - the stored value presented as the key is refused, and creates nothing;
 *  - the real key is accepted, and a plaintext or MD5-era legacy value is
 *    replaced with a real hash of the verified key;
 *  - a refused stored-value attempt lands in the same rate-limit bucket as any
 *    other wrong key.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-key-state.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-activator.php';

class Mcpwp_Legacy_Credential_Hash_Test_Auth {
	use Mcpwp_Api_Auth;

	public function auth_only( $request ) {
		return $this->verify_api_key_auth_only( $request );
	}

	public function key_match( $api_key, $stored ) {
		return $this->is_api_key_match( $api_key, $stored );
	}

	public function hash_match( $api_key, $stored ) {
		return $this->is_api_key_hash_match( $api_key, $stored );
	}

	public function spai_lookup( $api_key ) {
		return $this->find_legacy_spai_key( $api_key );
	}

	public function scoped_lookup( $api_key ) {
		return $this->find_scoped_api_key( $api_key );
	}

	public function client_ip() {
		return $this->get_client_ip();
	}
}

final class LegacyCredentialHashTest extends TestCase {

	const KEY = 'mcpwp_0123456789abcdef0123456789abcdef0123456789abcdef';

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_filters']      = array();
		$GLOBALS['mcpwp_test_user_caps']    = array();
		unset( $_GET['api_key'] );
		if ( method_exists( 'Mcpwp_REST_MCP', 'reset_verdict_memo' ) ) {
			Mcpwp_REST_MCP::reset_verdict_memo();
		}
	}

	protected function tearDown(): void {
		unset( $_GET['api_key'] );
		$GLOBALS['mcpwp_test_current_user'] = 0;
	}

	// -----------------------------------------------------------------
	// Fixtures
	// -----------------------------------------------------------------

	/**
	 * Every verifier shape WordPress (or an MD5-era install) stores, keyed by
	 * name. A null value means the shape cannot be produced in this PHP build.
	 *
	 * @return array<string,string|null>
	 */
	private static function verifiers( string $key ): array {
		return array(
			'wp68'     => '$wp' . password_hash( base64_encode( hash_hmac( 'sha384', $key, 'wp-sha384', true ) ), PASSWORD_BCRYPT ),
			'bcrypt'   => password_hash( $key, PASSWORD_BCRYPT ),
			'argon2id' => defined( 'PASSWORD_ARGON2ID' ) ? password_hash( $key, PASSWORD_ARGON2ID ) : null,
			'md5'      => md5( $key ),
		);
	}

	/**
	 * A phpass-shaped value. The test stub cannot verify phpass, so it is used
	 * for the refusal side only — which is exactly the side that matters: the
	 * stub falls back to a plain string compare for it, like a careless
	 * `check_password` filter would, and the stored value must still be refused.
	 */
	const PHPASS = '$P$BabcdefghijklmnopqrstuvwxyzABCD.';

	public static function stored_shapes(): array {
		$shapes = array();
		foreach ( self::verifiers( self::KEY ) as $name => $value ) {
			if ( null !== $value ) {
				$shapes[ $name ] = array( $value );
			}
		}
		$shapes['phpass'] = array( self::PHPASS );
		return $shapes;
	}

	public static function verifiable_shapes(): array {
		$shapes = array();
		foreach ( self::verifiers( self::KEY ) as $name => $value ) {
			if ( null !== $value ) {
				$shapes[ $name ] = array( $value );
			}
		}
		return $shapes;
	}

	public static function carriers(): array {
		return array( 'x-api-key' => array( 'header' ), 'bearer' => array( 'bearer' ), 'query' => array( 'query' ) );
	}

	/**
	 * Every (store, carrier, plane) combination.
	 */
	private static function stores(): array {
		return array( 'legacy_option', 'spai_single', 'spai_record', 'scoped_record' );
	}

	private function request( string $key, string $carrier, string $method = 'GET', string $route = '/mcpwp/v1/site-info' ): WP_REST_Request {
		$headers = array();
		if ( 'header' === $carrier ) {
			$headers['X-API-Key'] = $key;
		} elseif ( 'bearer' === $carrier ) {
			$headers['Authorization'] = 'Bearer ' . $key;
		} else {
			$_GET['api_key'] = $key;
		}
		return new WP_REST_Request( $method, $route, array(), $headers );
	}

	private function plant( string $store, string $stored ): void {
		switch ( $store ) {
			case 'legacy_option':
				update_option( 'mcpwp_api_key', $stored );
				break;
			case 'spai_single':
				update_option( 'spai_api_key', $stored );
				break;
			case 'spai_record':
				update_option(
					'spai_api_keys',
					array(
						array(
							'id'         => 'spai-key-1',
							'label'      => 'Legacy scoped key',
							'hash'       => $stored,
							'scopes'     => array( 'read', 'write', 'admin' ),
							'role'       => 'admin',
							'created_at' => '2026-01-01 00:00:00',
							'revoked_at' => null,
						),
					)
				);
				break;
			case 'scoped_record':
				update_option(
					'mcpwp_api_keys',
					array(
						array(
							'id'         => 'scoped-key-1',
							'label'      => 'Scoped key',
							'hash'       => $stored,
							'scopes'     => array( 'read', 'write', 'admin' ),
							'role'       => 'admin',
							'created_at' => '2026-01-01 00:00:00',
							'revoked_at' => null,
						),
					)
				);
				break;
		}
	}

	private function verify( Mcpwp_Legacy_Credential_Hash_Test_Auth $auth, string $plane, WP_REST_Request $request ) {
		return 'sub' === $plane ? $auth->auth_only( $request ) : $auth->verify_api_key( $request );
	}

	private function assert_refused( $result, string $context ): void {
		$this->assertInstanceOf( WP_Error::class, $result, $context . ': the stored value must not authenticate.' );
		// A site holding only 2.8 spai_ credentials answers the direct plane
		// with api_not_configured (has_configured_api_keys() counts mcpwp_
		// stores only) — a refusal all the same, and unchanged here.
		$this->assertContains( $result->get_error_code(), array( 'invalid_api_key', 'api_not_configured' ), $context );
		$this->assertSame( 0, $GLOBALS['mcpwp_test_current_user'], $context . ': no API user context may be set.' );
	}

	// -----------------------------------------------------------------
	// The matchers themselves
	// -----------------------------------------------------------------

	/**
	 * @dataProvider stored_shapes
	 */
	public function test_a_stored_verifier_never_matches_itself( string $stored ): void {
		$auth = new Mcpwp_Legacy_Credential_Hash_Test_Auth();
		$this->assertFalse( $auth->key_match( $stored, $stored ), 'is_api_key_match' );
		$this->assertFalse( $auth->hash_match( $stored, $stored ), 'is_api_key_hash_match' );
	}

	/**
	 * @dataProvider verifiable_shapes
	 */
	public function test_the_real_key_matches_every_verifiable_shape( string $stored ): void {
		$auth = new Mcpwp_Legacy_Credential_Hash_Test_Auth();
		$this->assertTrue( $auth->key_match( self::KEY, $stored ) );
		$this->assertTrue( $auth->hash_match( self::KEY, $stored ) );
		$this->assertFalse( $auth->key_match( self::KEY . 'x', $stored ) );
	}

	public function test_a_plaintext_legacy_value_still_matches_only_its_own_key(): void {
		$auth = new Mcpwp_Legacy_Credential_Hash_Test_Auth();
		$this->assertTrue( $auth->key_match( self::KEY, self::KEY ) );
		$this->assertFalse( $auth->key_match( self::KEY . 'x', self::KEY ) );
		$this->assertFalse( $auth->hash_match( self::KEY, self::KEY ), 'A store record is never a plaintext key (#1098).' );
	}

	public function test_an_md5_value_is_never_compared_as_plaintext(): void {
		$auth = new Mcpwp_Legacy_Credential_Hash_Test_Auth();
		$md5  = md5( self::KEY );
		$this->assertFalse( $auth->key_match( $md5, $md5 ) );
		$this->assertFalse( $auth->key_match( strtoupper( $md5 ), strtoupper( $md5 ) ) );
		$this->assertTrue( $auth->key_match( self::KEY, $md5 ) );
	}

	// -----------------------------------------------------------------
	// Every store x carrier x plane
	// -----------------------------------------------------------------

	/**
	 * @dataProvider stored_shapes
	 */
	public function test_the_stored_value_is_refused_on_every_path( string $stored ): void {
		foreach ( self::stores() as $store ) {
			foreach ( array( 'header', 'bearer', 'query' ) as $carrier ) {
				foreach ( array( 'direct', 'sub' ) as $plane ) {
					$this->setUp();
					$this->plant( $store, $stored );
					$auth    = new Mcpwp_Legacy_Credential_Hash_Test_Auth();
					$context = "$store/$carrier/$plane";
					$before  = get_option( 'mcpwp_api_key', false );

					$this->assert_refused( $this->verify( $auth, $plane, $this->request( $stored, $carrier ) ), $context );

					$records = get_option( 'mcpwp_api_keys', array() );
					$active  = array_filter(
						is_array( $records ) ? $records : array(),
						static function ( $r ) {
							return is_array( $r ) && empty( $r['revoked_at'] ) && 'scoped-key-1' !== ( $r['id'] ?? '' );
						}
					);
					$this->assertSame( array(), $active, $context . ': a refused attempt must not create a key record.' );
					$this->assertSame( $before, get_option( 'mcpwp_api_key', false ), $context . ': the legacy option is untouched.' );
				}
			}
		}
	}

	/**
	 * A write route under admin scope too: the refusal is an auth refusal,
	 * never a scope one that would reveal the value matched.
	 *
	 * @dataProvider stored_shapes
	 */
	public function test_the_stored_value_is_refused_on_an_admin_write( string $stored ): void {
		foreach ( self::stores() as $store ) {
			$this->setUp();
			$this->plant( $store, $stored );
			$auth = new Mcpwp_Legacy_Credential_Hash_Test_Auth();
			$this->assert_refused( $auth->verify_api_key( $this->request( $stored, 'header', 'POST', '/mcpwp/v1/api-keys' ) ), $store );
		}
	}

	/**
	 * @dataProvider verifiable_shapes
	 */
	public function test_the_real_key_is_accepted_on_every_path( string $stored ): void {
		foreach ( self::stores() as $store ) {
			foreach ( array( 'header', 'bearer', 'query' ) as $carrier ) {
				foreach ( array( 'direct', 'sub' ) as $plane ) {
					$this->setUp();
					$this->plant( $store, $stored );
					$auth = new Mcpwp_Legacy_Credential_Hash_Test_Auth();
					$this->assertTrue( $this->verify( $auth, $plane, $this->request( self::KEY, $carrier ) ), "$store/$carrier/$plane" );
				}
			}
		}
	}

	public function test_a_plaintext_legacy_option_is_accepted_and_migrated_to_a_hash(): void {
		foreach ( array( 'direct', 'sub' ) as $plane ) {
			$this->setUp();
			update_option( 'mcpwp_api_key', self::KEY );
			$auth = new Mcpwp_Legacy_Credential_Hash_Test_Auth();

			$this->assertTrue( $this->verify( $auth, $plane, $this->request( self::KEY, 'header' ) ), $plane );
			$stored = (string) get_option( 'mcpwp_api_key' );
			$this->assertTrue( Mcpwp_Key_State::is_password_hash_shaped( $stored ), $plane . ': plaintext replaced by a hash.' );
			$this->assertTrue( wp_check_password( self::KEY, $stored ), $plane );

			// The record carries the same credential, and the new hash is not a key.
			$this->assertNotNull( $auth->scoped_lookup( self::KEY ), $plane );
			$this->assertNull( $auth->scoped_lookup( $stored ), $plane );
			Mcpwp_REST_MCP::reset_verdict_memo();
			$this->assertTrue( $this->verify( $auth, $plane, $this->request( self::KEY, 'header' ) ), $plane . ': still works after migration.' );
		}
	}

	public function test_an_md5_legacy_option_is_rehashed_from_the_verified_key(): void {
		foreach ( array( 'direct', 'sub' ) as $plane ) {
			$this->setUp();
			update_option( 'mcpwp_api_key', md5( self::KEY ) );
			$auth = new Mcpwp_Legacy_Credential_Hash_Test_Auth();

			$this->assertTrue( $this->verify( $auth, $plane, $this->request( self::KEY, 'header' ) ), $plane );
			$stored = (string) get_option( 'mcpwp_api_key' );
			$this->assertTrue( wp_check_password( self::KEY, $stored ), $plane . ': rehashed from the key, not from the MD5 string.' );
			$this->assertNotNull( $auth->scoped_lookup( self::KEY ), $plane );
			$this->assertNull( $auth->scoped_lookup( md5( self::KEY ) ), $plane . ': the MD5 string must not become the key.' );
		}
	}

	public function test_the_spai_bridge_refuses_its_stored_values_directly(): void {
		foreach ( self::stored_shapes() as $name => $row ) {
			foreach ( array( 'spai_single', 'spai_record' ) as $store ) {
				$this->setUp();
				$this->plant( $store, $row[0] );
				$auth = new Mcpwp_Legacy_Credential_Hash_Test_Auth();
				$this->assertNull( $auth->spai_lookup( $row[0] ), "$name/$store" );
			}
		}
	}

	// -----------------------------------------------------------------
	// Adoption (activator) must not turn a stored value into a credential
	// -----------------------------------------------------------------

	public function test_adopting_an_md5_spai_single_keeps_the_key_and_not_the_md5_string(): void {
		update_option( 'spai_api_key', md5( self::KEY ) );
		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials() );

		$auth = new Mcpwp_Legacy_Credential_Hash_Test_Auth();
		$this->assertNotNull( $auth->scoped_lookup( self::KEY ), 'The real key survives adoption.' );
		$this->assertNull( $auth->scoped_lookup( md5( self::KEY ) ), 'The MD5 string is not a key after adoption.' );
		$this->assert_refused( $auth->verify_api_key( $this->request( md5( self::KEY ), 'header' ) ), 'adopted md5' );
	}

	public function test_adopting_a_hashed_spai_single_does_not_make_the_hash_a_key(): void {
		foreach ( self::verifiable_shapes() as $name => $row ) {
			$this->setUp();
			update_option( 'spai_api_key', $row[0] );
			$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials(), $name );

			$auth = new Mcpwp_Legacy_Credential_Hash_Test_Auth();
			$this->assertTrue( $auth->verify_api_key( $this->request( self::KEY, 'header' ) ), $name . ': the key works after adoption.' );
			Mcpwp_REST_MCP::reset_verdict_memo();
			$GLOBALS['mcpwp_test_current_user'] = 0;
			$this->assert_refused( $auth->verify_api_key( $this->request( $row[0], 'header' ) ), $name . ': adopted' );
		}
	}

	public function test_adopting_alongside_an_md5_legacy_option_keeps_the_key(): void {
		update_option( 'mcpwp_api_key', md5( self::KEY ) );
		update_option( 'spai_api_key', wp_hash_password( 'spai_other_key_value_0123456789abcdef' ) );
		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials() );

		$auth = new Mcpwp_Legacy_Credential_Hash_Test_Auth();
		$this->assertNotNull( $auth->scoped_lookup( self::KEY ) );
		$this->assertNull( $auth->scoped_lookup( md5( self::KEY ) ) );
	}

	public function test_activation_does_not_turn_an_md5_legacy_option_into_a_key(): void {
		update_option( 'mcpwp_api_key', md5( self::KEY ) );
		$method = new ReflectionMethod( 'Mcpwp_Activator', 'ensure_scoped_api_keys' );
		$method->setAccessible( true );
		$method->invoke( null );

		$auth = new Mcpwp_Legacy_Credential_Hash_Test_Auth();
		$this->assertNotNull( $auth->scoped_lookup( self::KEY ) );
		$this->assertNull( $auth->scoped_lookup( md5( self::KEY ) ) );
	}

	// -----------------------------------------------------------------
	// Rate limiting
	// -----------------------------------------------------------------

	/**
	 * @dataProvider stored_shapes
	 */
	public function test_a_refused_stored_value_is_metered_like_any_wrong_key( string $stored ): void {
		$auth   = new Mcpwp_Legacy_Credential_Hash_Test_Auth();
		$bucket = 'invalid:' . $auth->client_ip();

		$this->plant( 'legacy_option', $stored );
		$auth->verify_api_key( $this->request( 'mcpwp_wrong_key_value_000000000000000000000000000000', 'header' ) );
		$after_wrong = Mcpwp_Rate_Limiter::get_instance()->get_usage( $bucket )['minute']['used'];

		$this->assert_refused( $auth->verify_api_key( $this->request( $stored, 'header' ) ), 'metered' );
		$after_stored = Mcpwp_Rate_Limiter::get_instance()->get_usage( $bucket )['minute']['used'];

		$this->assertSame( 1, $after_wrong );
		$this->assertSame( 2, $after_stored, 'The stored value is charged to the same invalid-key bucket as any wrong key.' );
	}

	/**
	 * @dataProvider verifiable_shapes
	 */
	public function test_the_real_key_is_metered_on_its_own_key_bucket( string $stored ): void {
		$this->plant( 'scoped_record', $stored );
		$auth = new Mcpwp_Legacy_Credential_Hash_Test_Auth();

		$this->assertTrue( $auth->verify_api_key( $this->request( self::KEY, 'header' ) ) );
		$limiter = Mcpwp_Rate_Limiter::get_instance();
		$this->assertSame( 1, $limiter->get_usage( 'key:scoped-key-1' )['minute']['used'] );
		$this->assertSame( 0, $limiter->get_usage( 'invalid:' . $auth->client_ip() )['minute']['used'] );
	}
}
