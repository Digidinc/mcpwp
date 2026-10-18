<?php
/**
 * The legacy single-key option `mcpwp_api_key` must not outlive revocation.
 *
 * regenerate_api_key() writes the new primary key twice: as a scoped record
 * and as a hash in `mcpwp_api_key`. find_scoped_api_key() skips revoked
 * records, so a revoked primary key used to fall through to the legacy
 * branch, match the hash still sitting in `mcpwp_api_key`, and be re-created
 * by migrate_legacy_key_to_scoped_store() as a brand-new ACTIVE admin record
 * — revoking a primary key did not revoke it.
 *
 * The legacy option is now honoured only on a site whose scoped store is
 * still empty (a genuine pre-multi-key install, migrated once on first use),
 * and revoking a record clears a matching legacy hash.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-key-state.php';

class Mcpwp_Legacy_Key_Test_Auth {
	use Mcpwp_Api_Auth;

	public function auth_only( $request ) {
		return $this->verify_api_key_auth_only( $request );
	}
}

final class LegacyKeyRevocationTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_filters']      = array();
		$GLOBALS['mcpwp_test_user_caps']    = array();
		if ( method_exists( 'Mcpwp_REST_MCP', 'reset_verdict_memo' ) ) {
			Mcpwp_REST_MCP::reset_verdict_memo();
		}
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_current_user'] = 0;
	}

	private function request( string $key ): WP_REST_Request {
		return new WP_REST_Request( 'GET', '/mcpwp/v1/site-info', array(), array( 'X-API-Key' => $key ) );
	}

	private function record_id_for_label( $auth, string $label ): string {
		foreach ( $auth->list_scoped_api_keys( false ) as $key ) {
			if ( $label === $key['label'] ) {
				return $key['id'];
			}
		}
		return '';
	}

	private function active_count( $auth ): int {
		return count( $auth->list_scoped_api_keys( false ) );
	}

	public function test_a_revoked_primary_key_stays_revoked(): void {
		$auth    = new Mcpwp_Legacy_Key_Test_Auth();
		$primary = $auth->regenerate_api_key();
		$this->assertTrue( $auth->verify_api_key( $this->request( $primary ) ) );

		$auth->revoke_scoped_api_key( $this->record_id_for_label( $auth, 'Primary API Key' ) );

		$this->assertInstanceOf( WP_Error::class, $auth->verify_api_key( $this->request( $primary ) ) );
		$this->assertSame( 0, $this->active_count( $auth ), 'No record may be re-created for a revoked key.' );
	}

	public function test_a_revoked_primary_key_stays_revoked_on_the_sub_request_path(): void {
		$auth    = new Mcpwp_Legacy_Key_Test_Auth();
		$primary = $auth->regenerate_api_key();
		$auth->revoke_scoped_api_key( $this->record_id_for_label( $auth, 'Primary API Key' ) );

		$this->assertInstanceOf( WP_Error::class, $auth->auth_only( $this->request( $primary ) ) );
		$this->assertSame( 0, $this->active_count( $auth ) );
	}

	public function test_revoking_the_primary_keeps_the_legacy_hash_but_it_stays_inert(): void {
		// The hash is kept on purpose: three surfaces read it as "this site
		// has been set up" (#1097 round 2). Kept, it must still never
		// authenticate — including when the revoked key is NOT the newest one.
		$auth  = new Mcpwp_Legacy_Key_Test_Auth();
		$first = $auth->regenerate_api_key();
		$this->assertNotEmpty( get_option( 'mcpwp_api_key' ) );
		$auth->create_scoped_api_key( 'newer integration', array( 'read' ), 'author' );

		$auth->revoke_scoped_api_key( $this->record_id_for_label( $auth, 'Primary API Key' ) );

		$this->assertNotEmpty( get_option( 'mcpwp_api_key' ), 'Revoking must not delete the legacy option.' );
		$this->assertInstanceOf( WP_Error::class, $auth->verify_api_key( $this->request( $first ) ) );
		$this->assertSame( 1, $this->active_count( $auth ) );
	}

	public function test_an_all_revoked_store_still_counts_as_non_empty(): void {
		// "Empty store" means no record at all — revoked records included. An
		// all-revoked store plus an old-style legacy hash (a second salted hash,
		// as regenerate_api_key() writes it) must be refused, not migrated.
		$auth    = new Mcpwp_Legacy_Key_Test_Auth();
		$primary = $auth->regenerate_api_key();
		foreach ( $auth->list_scoped_api_keys( false ) as $key ) {
			$auth->revoke_scoped_api_key( $key['id'] );
		}
		update_option( 'mcpwp_api_key', wp_hash_password( $primary ) );

		$this->assertInstanceOf( WP_Error::class, $auth->verify_api_key( $this->request( $primary ) ) );
		$this->assertInstanceOf( WP_Error::class, $auth->auth_only( $this->request( $primary ) ) );
		$this->assertSame( 0, $this->active_count( $auth ) );
	}

	public function test_an_all_revoked_store_refuses_a_plaintext_legacy_value_too(): void {
		$auth    = new Mcpwp_Legacy_Key_Test_Auth();
		$primary = $auth->regenerate_api_key();
		$auth->revoke_scoped_api_key( $this->record_id_for_label( $auth, 'Primary API Key' ) );
		update_option( 'mcpwp_api_key', $primary );

		$this->assertInstanceOf( WP_Error::class, $auth->verify_api_key( $this->request( $primary ) ) );
		$this->assertSame( 0, $this->active_count( $auth ) );
	}

	// ── spai_ bridge (2.8 copies the migration never deleted) ─────────────
	// The migration copies spai records/hashes VERBATIM into the mcpwp store
	// (Mcpwp_Migrate R5, and the spai_api_key → mcpwp_api_key map), so these
	// fixtures use ONE hash for both copies, exactly as a migrated site has.

	private function migrated_pair( string $plain, $mcpwp_revoked_at ): void {
		$hash = wp_hash_password( $plain );
		update_option( 'spai_api_keys', array( array( 'id' => 'spaiold1', 'label' => 'old 2.8 key', 'hash' => $hash, 'scopes' => array( 'read', 'write', 'admin' ), 'revoked_at' => null ) ) );
		update_option( 'mcpwp_api_keys', array( array( 'id' => 'spaiold1', 'label' => 'old 2.8 key', 'hash' => $hash, 'scopes' => array( 'read', 'write', 'admin' ), 'revoked_at' => $mcpwp_revoked_at ) ) );
	}

	public function test_a_key_revoked_in_the_mcpwp_store_is_not_revived_by_its_spai_copy(): void {
		// Opus round-2 probe, as a test: store non-empty, key revoked, spai
		// copy still present → must be refused on both auth paths.
		$auth = new Mcpwp_Legacy_Key_Test_Auth();
		$key  = 'mcpwp_' . str_repeat( '5b', 24 );
		$this->migrated_pair( $key, '2026-10-18 07:00:00' );

		$this->assertInstanceOf( WP_Error::class, $auth->verify_api_key( $this->request( $key ) ) );
		$this->assertInstanceOf( WP_Error::class, $auth->auth_only( $this->request( $key ) ) );
	}

	public function test_a_live_migrated_key_still_works(): void {
		$auth = new Mcpwp_Legacy_Key_Test_Auth();
		$key  = 'mcpwp_' . str_repeat( '6c', 24 );
		$this->migrated_pair( $key, null );

		$this->assertTrue( $auth->verify_api_key( $this->request( $key ) ) );
	}

	public function test_a_single_spai_key_whose_mcpwp_copy_was_revoked_is_refused(): void {
		$auth = new Mcpwp_Legacy_Key_Test_Auth();
		$key  = 'spai_' . str_repeat( '7c', 24 );
		$hash = wp_hash_password( $key );
		update_option( 'spai_api_key', $hash );
		update_option( 'mcpwp_api_keys', array( array( 'id' => 'migr1', 'label' => 'Primary API Key', 'hash' => $hash, 'scopes' => array( 'read', 'write', 'admin' ), 'revoked_at' => '2026-10-18 07:00:00' ) ) );

		$this->assertInstanceOf( WP_Error::class, $auth->verify_api_key( $this->request( $key ) ) );
		$this->assertInstanceOf( WP_Error::class, $auth->auth_only( $this->request( $key ) ) );
	}

	public function test_a_spai_key_with_no_mcpwp_copy_keeps_working(): void {
		// Compatibility (BridgeAuthTest pins it too): a single spai_api_key the
		// migration skipped — the activator had already minted mcpwp_api_key —
		// lives only behind the bridge and must not be locked out.
		$auth = new Mcpwp_Legacy_Key_Test_Auth();
		$auth->create_scoped_api_key( 'some integration', array( 'read' ), 'author' );
		$key = 'spai_' . str_repeat( '8d', 24 );
		update_option( 'spai_api_key', wp_hash_password( $key ) );

		$this->assertTrue( $auth->verify_api_key( $this->request( $key ) ) );
	}

	// ── "Has this site got a key?" — the one decision three surfaces share ─

	public function test_site_has_key_counts_active_scoped_keys_without_the_legacy_option(): void {
		// The mcpwp.net case after the legacy row is deleted by hand: live
		// scoped keys, no legacy option. Setup must not offer "Generate API
		// Key" (which revokes every key), and test-connection must not say
		// "No API key configured".
		$auth = new Mcpwp_Legacy_Key_Test_Auth();
		$auth->create_scoped_api_key( 'live integration', array( 'read' ), 'author' );
		delete_option( 'mcpwp_api_key' );

		$this->assertTrue( Mcpwp_Key_State::site_has_api_key() );
		$this->assertFalse( Mcpwp_Key_State::is_fresh_site(), 'The activator must not mint a phantom key here.' );
	}

	public function test_site_has_key_after_the_primary_is_revoked(): void {
		$auth = new Mcpwp_Legacy_Key_Test_Auth();
		$auth->regenerate_api_key();
		$auth->revoke_scoped_api_key( $this->record_id_for_label( $auth, 'Primary API Key' ) );

		$this->assertTrue( Mcpwp_Key_State::site_has_api_key() );
		$this->assertFalse( Mcpwp_Key_State::is_fresh_site() );
	}

	public function test_an_all_revoked_site_with_no_legacy_option_is_not_fresh(): void {
		$auth = new Mcpwp_Legacy_Key_Test_Auth();
		$auth->regenerate_api_key();
		$auth->revoke_scoped_api_key( $this->record_id_for_label( $auth, 'Primary API Key' ) );
		delete_option( 'mcpwp_api_key' );

		$this->assertFalse( Mcpwp_Key_State::site_has_api_key(), 'No usable key: Setup may offer Generate.' );
		$this->assertFalse( Mcpwp_Key_State::is_fresh_site(), 'But the activator must not mint a legacy key nobody can use.' );
	}

	public function test_only_a_site_with_both_stores_empty_is_fresh(): void {
		$this->assertTrue( Mcpwp_Key_State::is_fresh_site() );
		$this->assertFalse( Mcpwp_Key_State::site_has_api_key() );
	}

	/**
	 * Pins each of the three surfaces to the shared decision, so a later edit
	 * cannot quietly go back to reading the legacy option alone.
	 *
	 * @return array<string,array{string,string}>
	 */
	public static function surfaces(): array {
		return array(
			'Setup page no-key branch'    => array( 'admin/partials/mcpwp-setup-display.php', "<?php if ( ! \$site_has_key && ! ( isset( \$new_key ) && \$new_key ) ) : ?>" ),
			'Setup page has-key flag'     => array( 'admin/partials/mcpwp-setup-display.php', '$site_has_key    = Mcpwp_Key_State::site_has_api_key();' ),
			'activator first-key mint'    => array( 'includes/class-mcpwp-activator.php', 'if ( Mcpwp_Key_State::is_fresh_site() ) {' ),
			'admin test-connection check' => array( 'includes/admin/class-mcpwp-admin.php', 'if ( ! Mcpwp_Key_State::site_has_api_key() ) {' ),
			'network overview (#1098)'    => array( 'includes/admin/class-mcpwp-admin.php', '$has_api_key    = Mcpwp_Key_State::site_has_api_key();' ),
		);
	}

	/**
	 * @dataProvider surfaces
	 */
	public function test_surface_uses_the_shared_key_state( string $file, string $needle ): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/' . $file );
		$this->assertStringContainsString( stripslashes( $needle ), $source );
	}

	public function test_no_surface_reads_the_legacy_option_alone_as_setup_state(): void {
		foreach ( array( 'includes/class-mcpwp-activator.php', 'includes/admin/class-mcpwp-admin.php' ) as $file ) {
			$source = (string) file_get_contents( dirname( __DIR__ ) . '/' . $file );
			$this->assertDoesNotMatchRegularExpression( "/if \\( !\\s*get_option\\( 'mcpwp_api_key' \\)/", $source, $file );
			$this->assertDoesNotMatchRegularExpression( "/\\\$stored_key = get_option\\( 'mcpwp_api_key' \\);\\s*if \\( empty\\( \\\$stored_key \\)/", $source, $file );
		}
	}

	public function test_a_legacy_hash_with_no_record_is_refused_once_scoped_keys_exist(): void {
		$auth  = new Mcpwp_Legacy_Key_Test_Auth();
		$auth->create_scoped_api_key( 'some integration', array( 'read' ), 'author' );
		$stray = 'mcpwp_' . str_repeat( 'ab', 24 );
		update_option( 'mcpwp_api_key', wp_hash_password( $stray ) );

		$this->assertInstanceOf( WP_Error::class, $auth->verify_api_key( $this->request( $stray ) ) );
		$this->assertSame( 1, $this->active_count( $auth ) );
	}

	public function test_a_legacy_hash_with_no_record_is_refused_on_the_sub_request_path(): void {
		$auth  = new Mcpwp_Legacy_Key_Test_Auth();
		$auth->create_scoped_api_key( 'some integration', array( 'read' ), 'author' );
		$stray = 'mcpwp_' . str_repeat( '9a', 24 );
		update_option( 'mcpwp_api_key', wp_hash_password( $stray ) );

		$this->assertInstanceOf( WP_Error::class, $auth->auth_only( $this->request( $stray ) ) );
		$this->assertSame( 1, $this->active_count( $auth ) );
	}

	public function test_a_pre_multi_key_site_still_migrates_its_legacy_key_once(): void {
		$auth   = new Mcpwp_Legacy_Key_Test_Auth();
		$legacy = 'mcpwp_' . str_repeat( 'cd', 24 );
		update_option( 'mcpwp_api_key', wp_hash_password( $legacy ) );

		$this->assertTrue( $auth->verify_api_key( $this->request( $legacy ) ) );
		$this->assertSame( 1, $this->active_count( $auth ) );

		// Second use: matched by the migrated record, not re-migrated.
		Mcpwp_REST_MCP::reset_verdict_memo();
		$this->assertTrue( $auth->verify_api_key( $this->request( $legacy ) ) );
		$this->assertSame( 1, $this->active_count( $auth ) );
	}

	public function test_a_plaintext_legacy_key_on_a_pre_multi_key_site_still_works(): void {
		$auth   = new Mcpwp_Legacy_Key_Test_Auth();
		$legacy = 'mcpwp_' . str_repeat( 'ef', 24 );
		update_option( 'mcpwp_api_key', $legacy );

		$this->assertTrue( $auth->verify_api_key( $this->request( $legacy ) ) );
		$this->assertNotSame( $legacy, get_option( 'mcpwp_api_key' ), 'Plaintext is hashed on first use.' );
	}
}
