<?php
/**
 * A busy key store must never be reported as a success (#1096).
 *
 * Revoke and create can now be refused when the store cannot be written. Every
 * caller that reports the result to a person, or forgets state on success, must
 * tell "revoked" from "could not revoke": the ChatGPT connector carries its key
 * in a URL, so a link that is still live but reported revoked is a live
 * credential nothing can ever cut off again.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/admin/class-mcpwp-chatgpt-connector.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-broker-auth.php';

class Mcpwp_Busy_Test_Auth {
	use Mcpwp_Api_Auth;
}

final class ApiKeyStoreBusy1096Test extends TestCase {

	private const LOCK = 'mcpwp_api_keys_lock';

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_filters']      = array();
		$GLOBALS['mcpwp_test_user_caps']    = array();
		$GLOBALS['mcpwp_test_wpdb_options'] = array();
		$_GET                               = array();
	}

	protected function tearDown(): void {
		$_GET                               = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_user_caps']    = array();
		$GLOBALS['mcpwp_test_wpdb_options'] = array();
	}

	private function hold_lock(): void {
		$GLOBALS['mcpwp_test_wpdb_options'][ self::LOCK ] = ( time() + 300 ) . '|deadbeef';
	}

	private function release_lock(): void {
		unset( $GLOBALS['mcpwp_test_wpdb_options'][ self::LOCK ] );
	}

	private function verify_with_link( $auth, string $url ) {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $params );
		$_GET = $params;
		return $auth->verify_api_key( new WP_REST_Request( 'POST', '/mcpwp/v1/posts', array( 'status' => 'draft' ) ) );
	}

	/**
	 * @return array{0:Mcpwp_Busy_Test_Auth,1:Mcpwp_ChatGPT_Connector,2:string}
	 */
	private function linked(): array {
		$auth      = new Mcpwp_Busy_Test_Auth();
		$connector = new Mcpwp_ChatGPT_Connector( $auth );
		$result    = $connector->create_or_rotate();
		return array( $auth, $connector, $result['url'] );
	}

	// ── ChatGPT connector ────────────────────────────────────────────────

	public function test_a_busy_revoke_keeps_the_key_id_and_the_link_stays_known_and_live(): void {
		list( $auth, $connector, $url ) = $this->linked();
		$key_id                         = (string) get_option( 'mcpwp_chatgpt_connector_key_id', '' );
		$this->assertNotSame( '', $key_id );

		$this->hold_lock();
		$this->assertSame( 'busy', $connector->revoke_link() );

		$this->assertSame( $key_id, get_option( 'mcpwp_chatgpt_connector_key_id' ), 'The id must survive a revoke that did not happen.' );
		$this->release_lock();
		$this->assertTrue( $this->verify_with_link( $auth, $url ), 'The link really is still live.' );

		// ...and the next attempt can still cut it off.
		$this->assertSame( 'revoked', $connector->revoke_link() );
		$this->assertInstanceOf( WP_Error::class, $this->verify_with_link( $auth, $url ) );
		$this->assertSame( '', (string) get_option( 'mcpwp_chatgpt_connector_key_id', '' ) );
	}

	public function test_the_setup_page_does_not_say_revoked_when_it_was_not(): void {
		list( , $connector )              = $this->linked();
		$GLOBALS['mcpwp_test_current_user'] = 1;
		$GLOBALS['mcpwp_test_user_caps']    = array( 1 => array( 'activate_plugins' => true ) );
		$this->hold_lock();

		$result = $connector->handle_request(
			array(
				'mcpwp_chatgpt_connector_action' => 'revoke',
				'mcpwp_chatgpt_connector_nonce'  => 'test-nonce',
			)
		);

		$this->assertSame( 'error', $result['type'] );
		$this->assertStringNotContainsString( 'can no longer reach', $result['message'] );
		$this->assertStringContainsString( 'still active', $result['message'] );
	}

	public function test_a_busy_rotation_mints_nothing_and_does_not_claim_the_old_link_stopped(): void {
		list( $auth, $connector, $old ) = $this->linked();
		$key_id                         = (string) get_option( 'mcpwp_chatgpt_connector_key_id' );
		$this->hold_lock();

		$result = $connector->create_or_rotate();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'mcpwp_chatgpt_keystore_busy', $result->get_error_code() );
		$this->assertSame( $key_id, get_option( 'mcpwp_chatgpt_connector_key_id' ) );
		$this->release_lock();
		$this->assertTrue( $this->verify_with_link( $auth, $old ), 'The old link is still live, and the message says so.' );
		$this->assertCount( 1, $auth->list_scoped_api_keys( false ), 'No second key was minted.' );
	}

	public function test_revoke_of_an_already_gone_key_forgets_the_id(): void {
		list( $auth, $connector ) = $this->linked();
		$auth->revoke_scoped_api_key( (string) get_option( 'mcpwp_chatgpt_connector_key_id' ) );

		$this->assertSame( 'gone', $connector->revoke_link() );
		$this->assertSame( '', (string) get_option( 'mcpwp_chatgpt_connector_key_id', '' ) );
		$this->assertSame( 'none', $connector->revoke_link() );
	}

	// ── REST ─────────────────────────────────────────────────────────────

	private function rest_admin(): array {
		$controller = new Mcpwp_REST_Site_Updates();
		$admin      = $controller->create_scoped_api_key( 'owner', array( 'admin' ) );
		$other      = $controller->create_scoped_api_key( 'victim', array( 'read' ) );
		return array( new Mcpwp_REST_Site_Updates(), $admin['key'], $other['id'] );
	}

	private function authed_delete( $controller, string $key, string $id ) {
		$request = new WP_REST_Request( 'DELETE', '/mcpwp/v1/api-keys/' . $id, array( 'id' => $id ) );
		$request->set_header( 'X-API-Key', $key );
		$verdict = $controller->verify_api_key( $request );
		$this->assertTrue( $verdict, 'The admin key must authenticate while the store is free.' );
		return $request;
	}

	public function test_rest_revoke_answers_503_retry_when_the_store_is_busy(): void {
		$GLOBALS['mcpwp_test_current_user'] = 2;
		list( $controller, $admin_key, $victim_id ) = $this->rest_admin();
		$request = $this->authed_delete( $controller, $admin_key, $victim_id );
		$this->hold_lock();

		$response = $controller->revoke_api_key( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'keystore_busy', $response->get_data()['code'] );
		$this->assertSame( 1, $response->get_data()['data']['retry_after'] );
		$this->assertSame( '1', $response->get_headers()['Retry-After'], 'A real Retry-After header, not only a body field.' );
		$this->release_lock();
		$this->assertCount( 2, $controller->list_scoped_api_keys( false ), 'The key was NOT revoked.' );
	}

	public function test_rest_revoke_still_404s_a_key_that_is_not_there(): void {
		$GLOBALS['mcpwp_test_current_user'] = 2;
		list( $controller, $admin_key ) = $this->rest_admin();
		$request = $this->authed_delete( $controller, $admin_key, 'nosuchkey' );

		$response = $controller->revoke_api_key( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 404, $response->get_error_data()['status'] );
	}

	public function test_rest_create_answers_503_with_a_retry_after_header_when_the_store_is_busy(): void {
		$GLOBALS['mcpwp_test_current_user'] = 2;
		list( $controller, $admin_key ) = $this->rest_admin();
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/api-keys', array( 'label' => 'x', 'scopes' => array( 'read' ), 'role' => 'admin' ) );
		$request->set_header( 'X-API-Key', $admin_key );
		$this->assertTrue( $controller->verify_api_key( $request ) );
		$this->hold_lock();

		$response = $controller->create_api_key( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( '1', $response->get_headers()['Retry-After'] );
	}
}
