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
			'TODO deny (SEO audit)'             => array( 'GET', '/mcpwp/v1/seo/audit', array(), 'manage_options' ),
			'TODO deny (Elementor data)'        => array( 'GET', '/mcpwp/v1/elementor/5', array( 'id' => 5 ), 'manage_options' ),
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
