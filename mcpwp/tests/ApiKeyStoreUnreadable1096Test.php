<?php
/**
 * An unreadable API key store fails closed everywhere, including on upgrade (#1096).
 *
 * `mcpwp_api_keys` that does not unserialize to a list (a row a latin1 table
 * truncated, say) used to read as "empty" through get_option(). The activator,
 * which runs on the request path at every plugin update, then overwrote it with
 * one active admin record for the legacy key, dropping every revocation record
 * so that revoked keys authenticated again through their spai_ copies.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-key-state.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-key-store-health.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-mcpwp-chatgpt-connector.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-broker-auth.php';

class Mcpwp_Unreadable_Test_Auth {
	use Mcpwp_Api_Auth;
}

final class ApiKeyStoreUnreadable1096Test extends TestCase {

	private const CORRUPT = 'a:2:{i:0;a:7:{s:2:"id";s:36:"11111111-1111-1111-1111-111111111111";s:5:"label";s:3:"';

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_user_caps']    = array();
		$GLOBALS['mcpwp_test_filters']      = array();
		$GLOBALS['mcpwp_test_wpdb_options'] = array();
		$GLOBALS['mcpwp_test_object_cache'] = array();
		$_GET                               = array();
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_wpdb_options'] = array();
	}

	private function request( string $key ): WP_REST_Request {
		return new WP_REST_Request( 'GET', '/mcpwp/v1/site-info', array(), array( 'X-API-Key' => $key ) );
	}

	/**
	 * A site the way the bug leaves it: a truncated store, the legacy key, and
	 * the spai_ copy of a key that had been revoked.
	 *
	 * @return array{legacy:string,revoked:string}
	 */
	private function corrupt_site(): array {
		$legacy  = 'mcpwp_' . str_repeat( 'a', 48 );
		$revoked = 'spai_' . str_repeat( 'b', 48 );
		update_option( 'mcpwp_api_key', wp_hash_password( $legacy ) );
		update_option( 'spai_api_keys', array( array( 'id' => 's1', 'label' => 'old', 'hash' => wp_hash_password( $revoked ), 'scopes' => array( 'admin' ), 'revoked_at' => null ) ) );
		update_option( 'mcpwp_api_keys', self::CORRUPT );
		return array( 'legacy' => $legacy, 'revoked' => $revoked );
	}

	private function assert_everything_still_refused( array $site, string $where ): void {
		$auth = new Mcpwp_Unreadable_Test_Auth();
		$this->assertSame( self::CORRUPT, get_option( 'mcpwp_api_keys' ), "$where: the stored bytes must be untouched." );
		$this->assertInstanceOf( WP_Error::class, $auth->verify_api_key( $this->request( $site['legacy'] ) ), "$where: the legacy key stays refused." );
		$this->assertInstanceOf( WP_Error::class, $auth->verify_api_key( $this->request( $site['revoked'] ) ), "$where: the spai_ copy of a revoked key stays refused." );
		$this->assertSame( self::CORRUPT, get_option( 'mcpwp_api_keys' ), "$where: authenticating did not write either." );
	}

	// ── the upgrade / activation path ─────────────────────────────────────

	public function test_activation_does_not_overwrite_an_unreadable_store(): void {
		$site = $this->corrupt_site();

		Mcpwp_Activator::ensure_api_keys();
		$this->assert_everything_still_refused( $site, 'first activation' );

		// A plugin update, then a deactivate/reactivate: the same again.
		update_option( 'mcpwp_db_version', '0' );
		Mcpwp_Activator::ensure_api_keys();
		Mcpwp_Activator::ensure_api_keys();
		$this->assert_everything_still_refused( $site, 'update and reactivation' );
	}

	public function test_an_unreadable_store_is_not_a_fresh_site_so_no_first_key_is_minted(): void {
		update_option( 'mcpwp_api_keys', self::CORRUPT );

		$this->assertFalse( Mcpwp_Key_State::is_fresh_site() );
		Mcpwp_Activator::ensure_api_keys();

		$this->assertFalse( get_option( 'mcpwp_api_key', false ), 'No legacy key is minted over an unreadable store.' );
		$this->assertSame( self::CORRUPT, get_option( 'mcpwp_api_keys' ) );
	}

	public function test_the_activator_still_migrates_the_legacy_key_into_an_empty_store_once(): void {
		$legacy = 'mcpwp_' . str_repeat( 'c', 48 );
		update_option( 'mcpwp_api_key', wp_hash_password( $legacy ) );

		Mcpwp_Activator::ensure_api_keys();
		Mcpwp_Activator::ensure_api_keys();

		$records = get_option( 'mcpwp_api_keys' );
		$this->assertCount( 1, $records, 'One record, however many times it runs.' );
		$this->assertSame( 'Primary API Key', $records[0]['label'] );
		$this->assertNull( $records[0]['revoked_at'] );
	}

	public function test_the_activator_leaves_a_readable_non_empty_store_alone(): void {
		update_option( 'mcpwp_api_key', wp_hash_password( 'x' ) );
		$store = array( array( 'id' => 'k1', 'label' => 'mine', 'hash' => wp_hash_password( 'y' ), 'scopes' => array( 'read' ), 'revoked_at' => '2026-01-01 00:00:00' ) );
		update_option( 'mcpwp_api_keys', $store );

		Mcpwp_Activator::ensure_api_keys();

		$this->assertSame( $store, get_option( 'mcpwp_api_keys' ) );
	}

	// ── every writer says so, and none of them writes ─────────────────────

	public function test_every_writer_reports_unreadable_not_busy_and_changes_nothing(): void {
		$site = $this->corrupt_site();
		$auth = new Mcpwp_Unreadable_Test_Auth();

		$this->assertSame( 'unreadable', $auth->revoke_scoped_api_key_outcome( 'anything' ) );
		$this->assertSame( 'keystore_unreadable', $auth->create_scoped_api_key( 'x', array( 'read' ), 'admin' )['error'] );
		$this->assertSame( '', $auth->regenerate_api_key() );
		$this->assert_everything_still_refused( $site, 'writers' );
	}

	public function test_the_message_does_not_suggest_deleting_the_store_without_the_legacy_options(): void {
		$message = Mcpwp_Key_State::unreadable_message();

		$this->assertStringContainsString( 'backup', $message );
		$this->assertStringContainsString( 'support', $message );
		$this->assertStringContainsString( 'Do not simply delete', $message );
		foreach ( array( 'mcpwp_api_key', 'spai_api_key', 'spai_api_keys' ) as $option ) {
			$this->assertStringContainsString( $option, $message );
		}
		$this->assertStringNotContainsString( 'delete it if no key', $message );
	}

	public function test_rest_revoke_on_an_unreadable_store_is_a_500_with_the_recovery_text_and_no_retry_after(): void {
		$GLOBALS['mcpwp_test_current_user'] = 2;
		$controller = new Mcpwp_REST_Site_Updates();
		$admin      = $controller->create_scoped_api_key( 'owner', array( 'admin' ) );
		$request    = new WP_REST_Request( 'DELETE', '/mcpwp/v1/api-keys/x', array( 'id' => 'x' ) );
		$request->set_header( 'X-API-Key', $admin['key'] );
		$this->assertTrue( ( new Mcpwp_REST_Site_Updates() )->verify_api_key( $request ) );
		update_option( 'mcpwp_api_keys', self::CORRUPT );

		$response = ( new Mcpwp_REST_Site_Updates() )->revoke_api_key( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'keystore_unreadable', $response->get_error_code() );
		$this->assertSame( 500, $response->get_error_data()['status'] );
		$this->assertArrayNotHasKey( 'retry_after', $response->get_error_data() );
		$this->assertSame( Mcpwp_Key_State::unreadable_message(), $response->get_error_message() );
	}

	public function test_the_chatgpt_connector_keeps_its_key_id_and_says_unreadable(): void {
		$auth      = new Mcpwp_Unreadable_Test_Auth();
		$connector = new Mcpwp_ChatGPT_Connector( $auth );
		$connector->create_or_rotate();
		$key_id = (string) get_option( 'mcpwp_chatgpt_connector_key_id' );
		update_option( 'mcpwp_api_keys', self::CORRUPT );

		$this->assertSame( 'unreadable', $connector->revoke_link() );
		$this->assertSame( $key_id, get_option( 'mcpwp_chatgpt_connector_key_id' ) );
		$rotated = $connector->create_or_rotate();
		$this->assertInstanceOf( WP_Error::class, $rotated );
		$this->assertSame( Mcpwp_Key_State::unreadable_message(), $rotated->get_error_message() );
	}

	// ── an administrator is told ──────────────────────────────────────────

	public function test_site_health_is_critical_while_the_store_is_unreadable_and_good_otherwise(): void {
		$good = Mcpwp_Key_Store_Health::run_site_status_test();
		$this->assertSame( 'good', $good['status'] );

		update_option( 'mcpwp_api_keys', self::CORRUPT );
		$bad = Mcpwp_Key_Store_Health::run_site_status_test();
		$this->assertSame( 'critical', $bad['status'] );
		$this->assertStringContainsString( 'cannot be read', $bad['description'] );

		$tests = Mcpwp_Key_Store_Health::register_site_status_test( array() );
		$this->assertArrayHasKey( 'mcpwp_key_store', $tests['direct'] );
	}

	public function test_the_admin_notice_shows_on_every_screen_to_an_administrator_only_while_unreadable(): void {
		$GLOBALS['mcpwp_test_current_user'] = 1;

		ob_start();
		Mcpwp_Key_Store_Health::render_notice();
		$this->assertSame( '', ob_get_clean(), 'Nothing while the store is readable.' );

		update_option( 'mcpwp_api_keys', self::CORRUPT );
		ob_start();
		Mcpwp_Key_Store_Health::render_notice();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'refusing every API key', $html );

		$GLOBALS['mcpwp_test_current_user'] = 0;
		ob_start();
		Mcpwp_Key_Store_Health::render_notice();
		$this->assertSame( '', ob_get_clean(), 'Not shown to someone who cannot manage options.' );
	}

	// ── rotation retry; rollback cache ────────────────────────────────────

	public function test_rotation_retries_with_the_untranslated_label_when_the_translation_cannot_be_stored(): void {
		$auth = new Mcpwp_Unreadable_Test_Auth();
		$a    = $auth->create_scoped_api_key( 'old', array( 'read' ), 'admin' );
		$GLOBALS['mcpwp_test_wpdb_col_charset'] = 'latin1';
		$GLOBALS['mcpwp_test_translations']     = array( 'Primary API Key' => "\u{4e3b}\u{952e}" );

		$new = $auth->regenerate_api_key();

		unset( $GLOBALS['mcpwp_test_translations'] );
		$GLOBALS['mcpwp_test_wpdb_col_charset'] = 'utf8mb4';
		$this->assertNotSame( '', $new, 'Rotation must still work.' );
		$records = get_option( 'mcpwp_api_keys' );
		$this->assertCount( 2, $records, 'No duplicate: the refused attempt wrote nothing.' );
		$this->assertNotNull( $records[0]['revoked_at'], 'The old key is revoked.' );
		$this->assertSame( 'Primary API Key', $records[1]['label'] );
		$this->assertNull( $records[1]['revoked_at'] );
		$this->assertTrue( $auth->verify_api_key( $this->request( $new ) ) );
		$this->assertSame( 0, count( array_filter( $auth->list_scoped_api_keys( false ), static function ( $k ) use ( $a ) { return $k['id'] === $a['id']; } ) ) );
	}

	public function test_a_rollback_that_loses_to_a_newer_write_caches_the_newer_write_not_the_old_bytes(): void {
		$auth = new Mcpwp_Unreadable_Test_Auth();
		$a    = $auth->create_scoped_api_key( 'a', array( 'read' ), 'admin' );
		$updates = 0;
		// The write lands truncated...
		$GLOBALS['mcpwp_test_wpdb_after_update'] = static function ( $name ) {
			if ( 'mcpwp_api_keys' === $name ) {
				$GLOBALS['mcpwp_test_options']['mcpwp_api_keys'] = substr( maybe_serialize( $GLOBALS['mcpwp_test_options']['mcpwp_api_keys'] ), 0, 100 );
			}
		};
		// ...and before the rollback can run, a newer legitimate write revokes key a.
		$newer = null;
		$count = static function ( $name ) use ( &$updates, &$newer, $a ) {
			if ( 'mcpwp_api_keys' !== $name ) {
				return;
			}
			$updates++;
			if ( 2 === $updates ) {
				$records                                         = array( array( 'id' => $a['id'], 'label' => 'a', 'hash' => 'h', 'scopes' => array( 'read' ), 'role' => 'admin', 'revoked_at' => '2026-03-03 00:00:00' ) );
				$newer                                           = maybe_serialize( $records );
				$GLOBALS['mcpwp_test_options']['mcpwp_api_keys'] = $records;
			}
		};
		$arm = static function () use ( &$arm, $count ) {
			$GLOBALS['mcpwp_test_wpdb_before_update'] = static function ( $name ) use ( &$arm, $count ) {
				$count( $name );
				$arm();
			};
		};
		$arm();

		$created = $auth->create_scoped_api_key( 'b', array( 'read' ), 'admin' );

		$GLOBALS['mcpwp_test_wpdb_before_update'] = null;
		$GLOBALS['mcpwp_test_wpdb_after_update']  = null;
		$this->assertSame( 'keystore_write_failed', $created['error'] );
		$this->assertSame( $newer, maybe_serialize( get_option( 'mcpwp_api_keys' ) ), 'The newer write is not clobbered by the rollback.' );
		$cached = wp_cache_get( 'alloptions', 'options' );
		$this->assertSame( $newer, $cached['mcpwp_api_keys'], 'The cache shows the newer write, not the bytes from before it.' );
	}

	// ── a truncation that LOOKS serialised: ';' or '}' as the last byte ───

	/**
	 * Core's is_serialized() accepts any "a:N:" string ending in ';' or '}', and
	 * unserialize() then fails: get_option() answers FALSE, not the string. Both
	 * "false" and "a string" must read as unreadable; only an absent row and a
	 * serialised empty list are an empty store (an empty string is not).
	 *
	 * @return array{store:string,legacy:string,revoked:string,live:string}
	 */
	private function three_key_site(): array {
		$live    = 'mcpwp_' . str_repeat( 'l', 48 );
		$revoked = 'spai_' . str_repeat( 'r', 48 );
		$legacy  = 'mcpwp_' . str_repeat( 'g', 48 );
		$records = array(
			array( 'id' => 's0', 'label' => 'live', 'hash' => wp_hash_password( $live ), 'scopes' => array( 'admin' ), 'role' => 'admin', 'created_at' => '2026-01-01 00:00:00', 'revoked_at' => null ),
			array( 'id' => 's1', 'label' => 'revoked', 'hash' => wp_hash_password( $revoked ), 'scopes' => array( 'read' ), 'role' => 'admin', 'created_at' => '2026-01-01 00:00:00', 'revoked_at' => '2026-02-02 00:00:00' ),
			array( 'id' => 's2', 'label' => 'other', 'hash' => wp_hash_password( 'x' ), 'scopes' => array( 'read' ), 'role' => 'admin', 'created_at' => '2026-01-01 00:00:00', 'revoked_at' => null ),
		);
		update_option( 'mcpwp_api_key', wp_hash_password( $legacy ) );
		update_option( 'spai_api_keys', array( array( 'id' => 'spai1', 'label' => 'S1 copy', 'hash' => $records[1]['hash'], 'scopes' => array( 'read' ), 'revoked_at' => null ) ) );
		return array( 'store' => serialize( $records ), 'legacy' => $legacy, 'revoked' => $revoked, 'live' => $live );
	}

	private function set_raw_row( string $bytes ): void {
		unset( $GLOBALS['mcpwp_test_options']['mcpwp_api_keys'] );
		$GLOBALS['mcpwp_test_wpdb_options']['mcpwp_api_keys'] = $bytes;
		$GLOBALS['mcpwp_test_object_cache']                   = array();
	}

	public function test_every_byte_cut_of_a_store_is_whole_or_unreadable_and_fails_closed(): void {
		$site = $this->three_key_site();
		$auth = new Mcpwp_Unreadable_Test_Auth();
		$len  = strlen( $site['store'] );
		$looks_serialised = 0;

		for ( $cut = 0; $cut < $len; $cut++ ) {
			$bytes = substr( $site['store'], 0, $cut );
			$this->set_raw_row( $bytes );
			if ( in_array( substr( $bytes, -1 ), array( ';', '}' ), true ) ) {
				$looks_serialised++;
			}

			$this->assertTrue( Mcpwp_Key_State::store_is_unreadable(), "cut at $cut must be unreadable" );
			$this->assertFalse( Mcpwp_Key_State::is_fresh_site(), "cut at $cut is not a fresh site" );
			foreach ( array( 'legacy', 'revoked', 'live' ) as $which ) {
				if ( method_exists( 'Mcpwp_REST_MCP', 'reset_verdict_memo' ) ) {
					Mcpwp_REST_MCP::reset_verdict_memo();
				}
				$this->assertInstanceOf( WP_Error::class, $auth->verify_api_key( $this->request( $site[ $which ] ) ), "cut at $cut: the $which key must be refused" );
			}
			$this->assertSame( $bytes, $GLOBALS['mcpwp_test_wpdb_options']['mcpwp_api_keys'], "cut at $cut: authenticating must not write" );
			$this->assertSame( 'critical', Mcpwp_Key_Store_Health::run_site_status_test()['status'], "cut at $cut: Site Health" );
		}

		$this->assertGreaterThan( 20, $looks_serialised, 'The sweep must include cuts that end in ; or }.' );

		$this->set_raw_row( $site['store'] );
		$this->assertFalse( Mcpwp_Key_State::store_is_unreadable() );
		$this->assertCount( 3, get_option( 'mcpwp_api_keys' ) );
		$this->assertTrue( $auth->verify_api_key( $this->request( $site['live'] ) ), 'The whole store still authenticates.' );
	}

	public function test_a_store_cut_at_a_semicolon_shows_the_notice_and_refuses_the_spai_copy_of_a_revoked_key(): void {
		$site = $this->three_key_site();
		$cut  = strpos( $site['store'], ';', 200 ) + 1;
		$this->set_raw_row( substr( $site['store'], 0, $cut ) );
		$this->assertSame( ';', substr( $GLOBALS['mcpwp_test_wpdb_options']['mcpwp_api_keys'], -1 ) );
		$this->assertFalse( get_option( 'mcpwp_api_keys' ), 'Core reads this as false, not as a string.' );
		$GLOBALS['mcpwp_test_current_user'] = 1;

		$auth = new Mcpwp_Unreadable_Test_Auth();
		$this->assertInstanceOf( WP_Error::class, $auth->verify_api_key( $this->request( $site['revoked'] ) ) );
		$this->assertInstanceOf( WP_Error::class, $auth->verify_api_key( $this->request( $site['legacy'] ) ) );

		ob_start();
		Mcpwp_Key_Store_Health::render_notice();
		$this->assertStringContainsString( 'refusing every API key', ob_get_clean() );
	}

	public function test_only_an_absent_row_and_a_serialised_empty_list_are_an_empty_store(): void {
		$this->assertFalse( Mcpwp_Key_State::store_is_unreadable(), 'No row.' );
		$this->assertTrue( Mcpwp_Key_State::is_fresh_site() );

		$this->set_raw_row( 'a:0:{}' );
		$this->assertFalse( Mcpwp_Key_State::store_is_unreadable(), 'A serialised empty list.' );
		$this->assertTrue( Mcpwp_Key_State::is_fresh_site(), 'And it is still a fresh site.' );
	}

	public static function present_but_not_a_list(): array {
		return array(
			'empty string'            => array( '' ),
			'serialised empty string' => array( 's:0:"";' ),
			'serialised null'         => array( 'N;' ),
			'serialised false'        => array( 'b:0;' ),
			'serialised zero'         => array( 'i:0;' ),
			'a scalar string'         => array( 's:3:"abc";' ),
		);
	}

	/**
	 * Nothing in the plugin writes any of these, so a row holding one is damage:
	 * counting it as an empty store would let the legacy key and the spai_ copy
	 * of a revoked key authenticate.
	 *
	 * @dataProvider present_but_not_a_list
	 */
	public function test_a_present_row_that_is_not_a_list_is_unreadable_and_fails_closed( string $bytes ): void {
		$site = $this->three_key_site();
		$this->set_raw_row( $bytes );
		$auth = new Mcpwp_Unreadable_Test_Auth();

		$this->assertTrue( Mcpwp_Key_State::store_is_unreadable() );
		$this->assertFalse( Mcpwp_Key_State::is_fresh_site() );
		foreach ( array( 'legacy', 'revoked', 'live' ) as $which ) {
			$this->assertInstanceOf( WP_Error::class, $auth->verify_api_key( $this->request( $site[ $which ] ) ), "the $which key is refused" );
		}
		$this->assertSame( 'critical', Mcpwp_Key_Store_Health::run_site_status_test()['status'] );
		$this->assertSame( 'keystore_unreadable', $auth->create_scoped_api_key( 'x', array( 'read' ), 'admin' )['error'], 'mutate() refuses it' );
		$this->assertSame( $bytes, $GLOBALS['mcpwp_test_wpdb_options']['mcpwp_api_keys'], 'and the bytes are untouched' );
	}

	public function test_a_fresh_site_with_no_row_still_mints_its_first_key(): void {
		Mcpwp_Activator::ensure_api_keys();

		$this->assertNotEmpty( get_option( 'mcpwp_api_key', '' ), 'The first legacy key is minted.' );
		$this->assertCount( 1, (array) get_option( 'mcpwp_api_keys' ), 'And its scoped record is written.' );
		$this->assertFalse( Mcpwp_Key_State::store_is_unreadable() );
	}

	public function test_a_store_whose_every_key_was_revoked_still_honours_the_revocations(): void {
		// Revoking everything leaves revoked RECORDS, not an empty list.
		$auth    = new Mcpwp_Unreadable_Test_Auth();
		$revoked = 'spai_' . str_repeat( 'z', 48 );
		$a       = $auth->create_scoped_api_key( 'only', array( 'admin' ), 'admin' );
		$auth->revoke_scoped_api_key( $a['id'] );
		$records = get_option( 'mcpwp_api_keys' );
		update_option( 'spai_api_keys', array( array( 'id' => 'sp', 'label' => 'copy', 'hash' => $records[0]['hash'], 'scopes' => array( 'admin' ), 'revoked_at' => null ) ) );

		$this->assertFalse( Mcpwp_Key_State::store_is_unreadable() );
		$this->assertInstanceOf( WP_Error::class, $auth->verify_api_key( $this->request( $a['key'] ) ), 'The revoked key stays refused, through the spai_ copy too.' );
	}

	public function test_the_key_store_loads_what_it_needs_on_the_activation_request(): void {
		// On the activation request mcpwp_load_plugin() has not run, so neither
		// Mcpwp_OAuth_Clients (the lock) nor Mcpwp_Encryption (the compare-and-swap)
		// is loaded. mutate() must require them itself, or an activation-time
		// write silently does nothing. Both classes are already loaded in this
		// process, so the guard is pinned at the source.
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/core/class-mcpwp-key-store.php' );
		$this->assertMatchesRegularExpression( "/class_exists\\( 'Mcpwp_OAuth_Clients' \\) \\) \\{\\s+require_once __DIR__ \\. '\\/class-mcpwp-oauth-clients\\.php';/", $source );
		$this->assertMatchesRegularExpression( "/class_exists\\( 'Mcpwp_Encryption' \\) \\) \\{\\s+require_once __DIR__ \\. '\\/class-mcpwp-encryption\\.php';/", $source );
		$this->assertFileExists( dirname( __DIR__ ) . '/includes/core/class-mcpwp-oauth-clients.php' );
		$this->assertFileExists( dirname( __DIR__ ) . '/includes/core/class-mcpwp-encryption.php' );
	}
}
