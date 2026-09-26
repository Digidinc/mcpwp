<?php
/**
 * #892 adversarial review — rate_limit_method() must never be consulted
 * before a credential has been checked.
 *
 * The #891 fix taught the rate limiter to price a /mcp JSON-RPC call by its
 * underlying tool's real HTTP method (Mcpwp_REST_MCP::rate_limit_method())
 * instead of the outer POST every /mcp request arrives as, so a read tool
 * like wp_get_elementor gets the same 2x burst/minute ceiling a real GET
 * gets. The first cut of that fix computed $http_method once, at the top of
 * compute_api_key_verdict(), and reused it for every bucket in the function —
 * including the pre-auth, IP-keyed buckets that exist to cap what an
 * ANONYMOUS caller can do: 'missing:<ip>' (no credential at all),
 * 'invalid:'/'unconfigured:<ip>' (a credential that does not match), and
 * 'broker-attempt:<ip>' (throttles introspection attempts against the
 * mumcp.com broker BEFORE the outbound wp_remote_post, specifically to cap
 * amplification).
 *
 * That let an unauthenticated caller choose its own rate-limit ceiling: send
 * a /mcp body naming a known GET-mapped tool (no valid key needed — the body
 * is inspected before any credential is verified) and the 401 that follows
 * gets metered at the 2x read ceiling instead of the 1x a POST is supposed
 * to cost. Worst case is 'broker-attempt:', whose entire job is bounding
 * outbound requests this server makes on an attacker's behalf; letting the
 * attacker's own request body raise that ceiling defeats it. Building the
 * answer also runs Mcpwp_REST_MCP::get_all_tool_map() — free + pro +
 * every third-party integration's get_tool_map(), through
 * Mcpwp_Integration::resolve_all() and its apply_filters('mcpwp_integrations')
 * — pre-auth, on every anonymous request.
 *
 * The fix: rate_limit_method() is now called ONLY at the three authenticated
 * meter_request() call sites (the 'key:' bucket once a scoped key matched,
 * 'oauth:' once a token record was found, 'broker:' once validate_token()
 * succeeded) — never at the three pre-auth, IP-keyed sites above, which use
 * the request's real HTTP method unconditionally.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-encryption.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-broker-auth.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-oauth-well-known.php';

final class RateLimitMethodPreAuthTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']           = array();
		$GLOBALS['mcpwp_test_transients']        = array();
		$GLOBALS['mcpwp_test_current_user']      = 0;
		$GLOBALS['mcpwp_test_is_admin']          = false;
		$GLOBALS['mcpwp_test_filters']           = array();
		$GLOBALS['mcpwp_test_dispatched_routes'] = array();

		Mcpwp_REST_MCP::reset_verdict_memo();
		Mcpwp_Integration::clear_cache();

		$ref      = new ReflectionClass( Mcpwp_Encryption::class );
		$instance = $ref->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );

		// Small and deterministic: a doubled ceiling (10) is unmistakably
		// different from the correct one (5).
		Mcpwp_Rate_Limiter::get_instance()->update_settings(
			array(
				'enabled'             => true,
				'requests_per_minute' => 60,
				'requests_per_hour'   => 1000,
				'burst_limit'         => 5,
				'whitelist'           => array(),
			)
		);
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_options']    = array();
		$GLOBALS['mcpwp_test_transients'] = array();
		$GLOBALS['mcpwp_test_filters']    = array();

		Mcpwp_Integration::clear_cache();

		if ( class_exists( 'Mcpwp_Rate_Limiter' ) ) {
			$instance = ( new ReflectionClass( Mcpwp_Rate_Limiter::class ) )->getProperty( 'instance' );
			$instance->setAccessible( true );
			$instance->setValue( null, null );
		}
	}

	/** A tools/call body naming a tool this server's own map marks method: GET. */
	private function get_tool_body(): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'tools/call',
			'params'  => array(
				'name'      => 'wp_get_elementor',
				'arguments' => array( 'id' => 1 ),
			),
		);
	}

	/**
	 * Counts apply_filters('mcpwp_register_tools', ...) calls — fired by
	 * Mcpwp_Custom_Tool_Registry::get_registrations(), which every one of
	 * get_tool_map() / get_tools() / get_tool_categories() / get_rejected_count()
	 * calls, and which memoizes PER INSTANCE ($this->registrations, reset only
	 * by set_reserved_names()). get_all_tool_map() always calls
	 * $this->custom_registry->get_tool_map() as part of its merge.
	 *
	 * This is deliberately NOT a spy on 'mcpwp_integrations'
	 * (Mcpwp_Integration::resolve_all()'s own filter): that one fires
	 * unconditionally from Mcpwp_REST_MCP's OWN CONSTRUCTOR (building the
	 * reserved-tool-name guard), on every instantiation regardless of
	 * whether get_all_tool_map() is ever reached — and resolve_all() caches
	 * per-PROCESS, so by the time a later, real get_all_tool_map() call
	 * re-resolves integrations, the filter does not fire again either. Both
	 * directions of that signal are unusable here. custom_registry is a
	 * fresh instance per `new Mcpwp_REST_MCP()` and untouched by its
	 * constructor, so this filter fires if and only if THIS test's call
	 * reached get_all_tool_map().
	 */
	private function spy_tool_map_builds(): ArrayObject {
		$calls = new ArrayObject();
		add_filter(
			'mcpwp_register_tools',
			function ( $tools ) use ( $calls ) {
				$calls[] = true;
				return $tools;
			}
		);
		return $calls;
	}

	public function test_missing_key_request_with_a_get_tool_body_is_metered_at_the_real_post_method(): void {
		$calls = $this->spy_tool_map_builds();

		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), array(), $this->get_tool_body() );

		$controller = new Mcpwp_REST_MCP();
		$verdict    = $controller->verify_api_key( $request );

		$this->assertInstanceOf( WP_Error::class, $verdict );
		$this->assertSame( 'missing_api_key', $verdict->get_error_code(), 'The burst must not already be exhausted by an earlier assertion in this run.' );

		$budget = Mcpwp_Rate_Limiter::get_instance()->get_budget();
		$this->assertNotNull( $budget, 'The missing: bucket must have been metered.' );
		$this->assertSame( 5, $budget['limit'], 'A GET-tool body in an unauthenticated request must not buy the 2x read ceiling on the missing: bucket.' );
		$this->assertSame( 'write', $budget['bucket'] );

		$this->assertCount( 0, $calls, 'An unauthenticated request must never build the tool map.' );
	}

	public function test_invalid_key_request_with_a_get_tool_body_is_metered_at_the_real_post_method(): void {
		// A key must be configured for the identifier to be 'invalid:' rather
		// than 'unconfigured:' — both are pre-auth and IP-keyed, but this is
		// the one the review named.
		update_option( 'mcpwp_api_key', wp_hash_password( 'mcpwp_realkey_1234567890abcdef' ) );

		$calls = $this->spy_tool_map_builds();

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array( 'X-API-Key' => 'mcpwp_wrongkey_0000000000000000' ),
			$this->get_tool_body()
		);

		$controller = new Mcpwp_REST_MCP();
		$verdict    = $controller->verify_api_key( $request );

		$this->assertInstanceOf( WP_Error::class, $verdict );
		$this->assertSame( 'invalid_api_key', $verdict->get_error_code() );

		$budget = Mcpwp_Rate_Limiter::get_instance()->get_budget();
		$this->assertNotNull( $budget );
		$this->assertSame( 5, $budget['limit'], 'A GET-tool body on an unrecognised key must not buy the 2x read ceiling on the invalid: bucket.' );

		$this->assertCount( 0, $calls, 'An invalid-key request must never build the tool map.' );
	}

	/** Enroll the site with an ENCRYPTED site_credential, as the real enrollment flow would. */
	private function enroll(): void {
		$encrypted = Mcpwp_Encryption::get_instance()->encrypt( 'super-secret-cred' );

		update_option(
			'mcpwp_broker_enrollment',
			array(
				'issuer'                 => 'https://mumcp.com',
				'site_id'                => 'site-123',
				'audience'               => 'https://example.test',
				'introspection_endpoint' => 'https://mumcp.com/oauth/introspect',
				'site_credential'        => $encrypted,
			)
		);
	}

	public function test_broker_attempt_request_with_a_get_tool_body_is_metered_at_the_real_post_method(): void {
		$this->enroll();

		$calls = $this->spy_tool_map_builds();

		// No X-API-Key header + a Bearer token that is not a local key routes
		// to authenticate_broker_bearer_token(), whose FIRST act is the
		// broker-attempt: DoS guard, before the (here-unmocked, so
		// network-error-and-fail-closed) outbound introspection call.
		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array( 'Authorization' => 'Bearer not-a-local-key-at-all' ),
			$this->get_tool_body()
		);

		$controller = new Mcpwp_REST_MCP();
		$verdict    = $controller->verify_api_key( $request );

		$this->assertInstanceOf( WP_Error::class, $verdict );

		$budget = Mcpwp_Rate_Limiter::get_instance()->get_budget();
		$this->assertNotNull( $budget, 'The broker-attempt: guard must have been metered.' );
		$this->assertSame( 5, $budget['limit'], 'A GET-tool body must not raise the ceiling that caps outbound introspection amplification (broker-attempt:).' );

		$this->assertCount( 0, $calls, 'A broker-attempt request must never build the tool map.' );
	}

	/**
	 * The other half of the fix: once a request IS authenticated, a GET-tool
	 * body must still buy the 2x read ceiling (#891's original point) — the
	 * #892 fix narrowed WHERE rate_limit_method() applies, it must not have
	 * silently dropped WHAT it does for a real, valid key.
	 */
	public function test_an_authenticated_get_tool_body_still_gets_the_read_ceiling(): void {
		$controller = new Mcpwp_REST_MCP();
		$key        = $controller->create_scoped_api_key( 'preauth-892', array( 'read' ) );

		$calls = $this->spy_tool_map_builds();

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array( 'X-API-Key' => $key['key'] ),
			$this->get_tool_body()
		);

		$verdict = $controller->verify_api_key( $request );

		$this->assertTrue( $verdict, 'A valid, correctly-scoped key must authenticate.' );

		$budget = Mcpwp_Rate_Limiter::get_instance()->get_budget();
		$this->assertNotNull( $budget );
		$this->assertSame( 10, $budget['limit'], 'An authenticated GET-tool call must still get the 2x read ceiling on its key: bucket (#891).' );

		// Proves the spy itself can observe a real build (the 0-calls
		// assertions above are not simply a broken spy).
		$this->assertGreaterThanOrEqual( 1, count( $calls ), 'A real, authenticated dispatch does build the tool map — the spy is not inert.' );
	}
}
