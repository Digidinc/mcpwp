<?php
/**
 * #1100 Pass A, end to end: the route capability map enforced through the
 * real permission callback for a user-run principal (an Editor's OAuth
 * token, a WebMCP user) and NOT for a bot-run admin API key.
 *
 * RouteCapabilityMapTest drives every row through the chokepoint directly;
 * this file proves the chokepoint is actually on the verdict paths.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-posts.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-batch.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-mcp.php';
require_once dirname( __DIR__ ) . '/includes/mcp/class-mcpwp-mcp-free-tools.php';
require_once dirname( __DIR__ ) . '/includes/mcp/class-mcpwp-mcp-pro-tools.php';

final class CapabilityChokepoint1100Test extends TestCase {

	const EDITOR = 7;

	/** @var array */
	private $saved_users;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$this->saved_users                  = $GLOBALS['mcpwp_test_users'];
		$GLOBALS['mcpwp_test_users'][]      = (object) array( 'ID' => self::EDITOR, 'roles' => array( 'editor' ), 'user_login' => 'editor7' );
		$GLOBALS['mcpwp_test_user_caps']    = array(
			self::EDITOR => array_fill_keys( RouteCapabilityMapTest::EDITOR_CAPS, true ),
			2            => array(), // the service account: none of the user caps
		);
		update_option( 'mcpwp_settings', array( 'oauth_enabled' => true ) );
		$GLOBALS['_mcpwp_test_posts'][5] = new WP_Post( array( 'ID' => 5, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Home' ) );
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_users']        = $this->saved_users;
		$GLOBALS['mcpwp_test_user_caps']    = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['_mcpwp_test_posts']       = array();
		$GLOBALS['mcpwp_test_post_caps']    = array();
		if ( class_exists( 'Mcpwp_WebMCP' ) ) {
			Mcpwp_WebMCP::end_dispatch( 0 );
		}
		Mcpwp_REST_Batch::$is_batch_sub_request = false;
	}

	private function harness(): Mcpwp_Api_Auth_Test_Harness {
		return new Mcpwp_Api_Auth_Test_Harness();
	}

	private function editor_token(): array {
		$token = $this->harness()->issue_oauth_access_token( array( 'read', 'write' ), 3600, array( 'user_id' => self::EDITOR ) )['access_token'];
		return array( 'Authorization' => 'Bearer ' . $token );
	}

	private function admin_key(): array {
		$created = $this->harness()->create_scoped_api_key( 'admin', array( 'read', 'write', 'admin' ) );
		return array( 'X-API-Key' => $created['key'] );
	}

	private function check( string $method, string $route, array $headers, array $params = array() ) {
		return ( new Mcpwp_REST_Posts() )->check_permission( new WP_REST_Request( $method, $route, $params, $headers ) );
	}

	private function assert_capability_refused( $result, string $capability, string $context ): void {
		$this->assertInstanceOf( WP_Error::class, $result, $context );
		$this->assertSame( 'rest_forbidden_capability', $result->get_error_code(), $context );
		$this->assertSame( 403, $result->get_error_data()['status'], $context );
		$this->assertSame( $capability, $result->get_error_data()['required_capability'], $context );
	}

	/** One route per capability class: method, route, params, expected for an Editor ('' = allowed, else the refused cap). */
	public static function editor_cases(): array {
		return array(
			'read (discovery)'                  => array( 'GET', '/mcpwp/v1/site-info', array(), '' ),
			'none (public content)'             => array( 'GET', '/mcpwp/v1/posts', array(), '' ),
			'edit_posts (create post)'          => array( 'POST', '/mcpwp/v1/posts', array( 'title' => 'x', 'status' => 'draft' ), '' ),
			'edit_pages (create page)'          => array( 'POST', '/mcpwp/v1/pages', array( 'title' => 'x', 'status' => 'draft' ), '' ),
			'upload_files (media)'              => array( 'POST', '/mcpwp/v1/media/from-url', array( 'url' => 'https://example.com/a.png' ), '' ),
			'manage_categories (terms)'         => array( 'POST', '/mcpwp/v1/terms', array( 'name' => 'x', 'taxonomy' => 'category' ), '' ),
			'edit_theme_options (kit CSS)'      => array( 'POST', '/mcpwp/v1/elementor/kit-css', array( 'css' => 'a{}' ), 'edit_theme_options' ),
			'edit_theme_options (globals)'      => array( 'POST', '/mcpwp/v1/elementor/globals', array(), 'edit_theme_options' ),
			'edit_products (Woo products)'      => array( 'GET', '/mcpwp/v1/woocommerce/products', array(), 'edit_products' ),
			'admin-only until decided (SEO audit)' => array( 'GET', '/mcpwp/v1/seo/audit', array(), 'manage_options' ),
			'admin-only until decided (site state)' => array( 'GET', '/mcpwp/v1/site-state', array(), 'manage_options' ),
		);
	}

	/**
	 * @dataProvider editor_cases
	 */
	public function test_editor_oauth_token_through_the_permission_callback( string $method, string $route, array $params, string $refused ): void {
		$result = $this->check( $method, $route, $this->editor_token(), $params );
		if ( '' === $refused ) {
			$this->assertTrue( $result, "$method $route" );
			return;
		}
		$this->assert_capability_refused( $result, $refused, "$method $route" );
	}

	/**
	 * @dataProvider editor_cases
	 */
	public function test_bot_admin_key_is_gated_by_scope_only( string $method, string $route, array $params ): void {
		$this->assertTrue( $this->check( $method, $route, $this->admin_key(), $params ), "$method $route" );
	}

	// ── Kasra: every core-profile tool is callable by an Editor's OAuth token ──

	/**
	 * Mcpwp_REST_MCP::CORE_TOOL_PROFILE_TOOLS (24) plus the two meta tools.
	 * wp_list_menus is the one exception and is asserted separately below:
	 * it is ADMIN scope (menus are site configuration), which an OAuth token
	 * can never hold (D8), so it is refused by the scope gate — not by the
	 * capability map. tools/list's core profile does not filter by scope, so
	 * the exclusion is stated here, not hidden.
	 */
	public function test_editor_oauth_token_can_call_every_core_profile_tool(): void {
		$ref  = new ReflectionClass( 'Mcpwp_REST_MCP' );
		$core = $ref->getConstant( 'CORE_TOOL_PROFILE_TOOLS' );
		$meta = $ref->getConstant( 'META_TOOL_NAMES' );
		$map  = array_merge( ( new Mcpwp_MCP_Free_Tools() )->get_tool_map(), ( new Mcpwp_MCP_Pro_Tools() )->get_tool_map() );

		$this->assertCount( 24, $core, 'Premise: the core profile this test pins.' );

		// A page the Editor can read and edit (WordPress answers yes for an Editor).
		$GLOBALS['mcpwp_test_post_caps'][ self::EDITOR ] = array(
			'read_post'   => array( 5 => true ),
			'edit_post'   => array( 5 => true ),
			'delete_post' => array( 5 => true ),
		);
		$GLOBALS['_mcpwp_test_posts'][5]->post_name = 'home';
		$headers = $this->editor_token();

		$failed  = array();
		$passed  = array();
		foreach ( $core as $tool ) {
			if ( 'wp_list_menus' === $tool ) {
				continue; // asserted below
			}
			$this->assertArrayHasKey( $tool, $map, $tool );
			$route  = preg_replace_callback(
				'/\{([^}]+)\}/',
				static function ( $m ) {
					return 'slug' === $m[1] ? 'home' : '5';
				},
				'/mcpwp/v1' . $map[ $tool ]['route']
			);
			$params = false !== strpos( $map[ $tool ]['route'], '{id}' ) ? array( 'id' => 5 ) : array();
			if ( '/mcpwp/v1/rendered-html' === $route || '/mcpwp/v1/elementor/build-page' === $route ) {
				$params['id'] = 5;
			}
			$result = $this->check( $map[ $tool ]['method'], $route, $headers, $params );
			if ( true === $result ) {
				$passed[] = $tool;
			} else {
				$failed[] = $tool . ' ' . $map[ $tool ]['method'] . ' ' . $route . ' -> ' . ( is_wp_error( $result ) ? $result->get_error_code() . ' ' . wp_json_encode( $result->get_error_data() ) : var_export( $result, true ) );
			}
		}

		// The meta tools ride the /mcp envelope; their inner call is checked as its own sub-request.
		foreach ( $meta as $tool ) {
			$request = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), $headers );
			$request->set_body( wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array( 'name' => $tool, 'arguments' => array( 'query' => 'pages', 'name' => 'wp_list_pages', 'arguments' => new stdClass() ) ) ) ) );
			$result = ( new Mcpwp_REST_Posts() )->check_permission( $request );
			if ( true === $result ) {
				$passed[] = $tool;
			} else {
				$failed[] = $tool . ' -> ' . ( is_wp_error( $result ) ? $result->get_error_code() : var_export( $result, true ) );
			}
		}

		$this->assertSame( array(), $failed, "Core-profile tools an Editor's OAuth token cannot call:\n" . implode( "\n", $failed ) );
		$this->assertCount( 25, $passed, '23 core tools + 2 meta tools.' );
	}

	public function test_wp_list_menus_is_refused_by_scope_not_by_the_capability_map(): void {
		$map    = ( new Mcpwp_MCP_Free_Tools() )->get_tool_map();
		$result = $this->check( $map['wp_list_menus']['method'], '/mcpwp/v1' . $map['wp_list_menus']['route'], $this->editor_token() );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
		$this->assertSame( 'admin', $result->get_error_data()['required_scope'] );
		$this->assertSame( 'admin', $this->harness()->required_scope_for_tool( 'wp_list_menus' ) );
	}

	public function test_set_seo_also_applies_the_per_post_edit_check(): void {
		$GLOBALS['mcpwp_test_post_caps'][ self::EDITOR ] = array( 'read_post' => array( 5 => true ), 'edit_post' => array( 5 => false ) );
		$result = $this->check( 'POST', '/mcpwp/v1/seo/5', $this->editor_token(), array( 'id' => 5, 'title' => 'x' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_edit', $result->get_error_code(), 'edit_posts passes the map; edit_post on THIS post decides.' );

		$GLOBALS['mcpwp_test_post_caps'][ self::EDITOR ] = array( 'read_post' => array( 5 => false ) );
		$this->assertSame( 'not_found', $this->check( 'GET', '/mcpwp/v1/elementor/5', $this->editor_token(), array( 'id' => 5 ) )->get_error_code(), 'read_post on THIS post decides the read rows.' );
	}

	public function test_sub_request_path_enforces_the_map_too(): void {
		$headers                                = $this->editor_token();
		Mcpwp_REST_Batch::$is_batch_sub_request = true;
		$this->assert_capability_refused( $this->check( 'GET', '/mcpwp/v1/seo/audit', $headers ), 'manage_options', 'sub-request' );
		$this->assertTrue( $this->check( 'GET', '/mcpwp/v1/site-info', $headers ) );
	}

	public function test_webmcp_principal_is_capability_checked(): void {
		$GLOBALS['mcpwp_test_current_user'] = self::EDITOR;
		$prev                               = Mcpwp_WebMCP::begin_dispatch( self::EDITOR );
		Mcpwp_REST_Batch::$is_batch_sub_request = true;
		try {
			$result = ( new Mcpwp_REST_Posts() )->verify_api_key( new WP_REST_Request( 'GET', '/mcpwp/v1/seo/audit', array() ) );
		} finally {
			Mcpwp_WebMCP::end_dispatch( $prev );
		}
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden_capability', $result->get_error_code() );
	}
}
