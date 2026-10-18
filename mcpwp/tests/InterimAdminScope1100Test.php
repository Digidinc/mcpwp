<?php
/**
 * #1100 interim: routes whose handlers carry no capability check are admin
 * scope until the capability sweep lands — form entries (route + tool),
 * widget/sidebar writes (routes + tools) and product creation (route + tool).
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-posts.php';

final class InterimAdminScope1100Test extends TestCase {

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
			self::EDITOR => array( 'read' => true, 'edit_posts' => true, 'edit_others_posts' => true, 'publish_posts' => true, 'read_private_posts' => true ),
		);
		update_option( 'mcpwp_settings', array( 'oauth_enabled' => true ) );
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_users']        = $this->saved_users;
		$GLOBALS['mcpwp_test_user_caps']    = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
	}

	private function harness(): Mcpwp_Api_Auth_Test_Harness {
		return new Mcpwp_Api_Auth_Test_Harness();
	}

	private function check( string $method, string $route, array $headers, array $params = array() ) {
		return ( new Mcpwp_REST_Posts() )->check_permission( new WP_REST_Request( $method, $route, $params, $headers ) );
	}

	private function editor_token( array $scopes = array( 'read', 'write' ) ): array {
		$token = $this->harness()->issue_oauth_access_token( $scopes, 3600, array( 'user_id' => self::EDITOR ) )['access_token'];
		return array( 'Authorization' => 'Bearer ' . $token );
	}

	private function key( array $scopes, string $role = 'custom', array $categories = array( 'forms', 'site', 'woocommerce', 'content', 'seo' ) ): array {
		$created = 'admin' === $role
			? $this->harness()->create_scoped_api_key( 'k', $scopes )
			: $this->harness()->create_scoped_api_key( 'k', $scopes, $role, $categories );
		return array( 'X-API-Key' => $created['key'] );
	}

	private function assert_admin_required( $result, string $context ): void {
		$this->assertInstanceOf( WP_Error::class, $result, $context );
		$this->assertSame( 'insufficient_scope', $result->get_error_code(), $context );
		$this->assertSame( 'admin', $result->get_error_data()['required_scope'], $context );
	}

	/** Admin-scope cases: method, route, params. */
	public static function admin_cases(): array {
		return array(
			'form entries (gravity)'      => array( 'GET', '/mcpwp/v1/forms/gravityforms/3/entries', array() ),
			'form entries (cf7)'          => array( 'GET', '/mcpwp/v1/forms/cf7/9/entries', array() ),
			'form entries (future sub)'   => array( 'GET', '/mcpwp/v1/forms/wpforms/1/entries/55', array() ),
			'add widget'                  => array( 'POST', '/mcpwp/v1/sidebars/sidebar-1/widgets', array( 'type' => 'text' ) ),
			'reorder sidebar'             => array( 'POST', '/mcpwp/v1/sidebars/sidebar-1/reorder', array() ),
			'update widget'               => array( 'PUT', '/mcpwp/v1/widgets/text-1', array( 'instance' => array( 'title' => 'x' ) ) ),
			'delete widget'               => array( 'DELETE', '/mcpwp/v1/widgets/text-1', array() ),
			'move widget'                 => array( 'POST', '/mcpwp/v1/widgets/text-1/move', array() ),
			'create product (draft)'      => array( 'POST', '/mcpwp/v1/woocommerce/products', array( 'name' => 'x', 'status' => 'draft' ) ),
			'create product (no status)'  => array( 'POST', '/mcpwp/v1/woocommerce/products', array( 'name' => 'x' ) ),
			// Phase 1 (Kasra): site-wide settings.
			'set favicon (POST)'          => array( 'POST', '/mcpwp/v1/favicon', array( 'attachment_id' => 3 ) ),
			'set favicon (PUT)'           => array( 'PUT', '/mcpwp/v1/favicon', array( 'attachment_id' => 3 ) ),
			'remove favicon'              => array( 'DELETE', '/mcpwp/v1/favicon', array() ),
			'flush permalinks'            => array( 'POST', '/mcpwp/v1/flush-permalinks', array() ),
			'flush page cache'            => array( 'POST', '/mcpwp/v1/cache-flush', array() ),
			'signal settings (POST)'      => array( 'POST', '/mcpwp/v1/signals/settings', array() ),
			'signal settings (PUT)'       => array( 'PUT', '/mcpwp/v1/signals/settings', array() ),
			'set site context'            => array( 'POST', '/mcpwp/v1/site-context', array( 'context' => 'x' ) ),
			// POST /feedback/relay is NOT here: it is registered with
			// __return_true (public by design), so the scope row for it is
			// inert — see Gate1103Round2Test.
			'create site blueprint'       => array( 'POST', '/mcpwp/v1/site-blueprints', array( 'name' => 'x' ) ),
			'delete site blueprint'       => array( 'DELETE', '/mcpwp/v1/site-blueprints/abc', array() ),
		);
	}

	/**
	 * @dataProvider admin_cases
	 */
	public function test_editor_oauth_token_is_refused( string $method, string $route, array $params ): void {
		$this->assert_admin_required( $this->check( $method, $route, $this->editor_token(), $params ), "$method $route" );
	}

	/**
	 * @dataProvider admin_cases
	 */
	public function test_non_admin_api_keys_are_refused( string $method, string $route, array $params ): void {
		$this->assert_admin_required( $this->check( $method, $route, $this->key( array( 'read', 'write' ) ), $params ), "write key $method $route" );
		$this->assert_admin_required( $this->check( $method, $route, $this->key( array( 'read', 'write' ), 'editor', array() ), $params ), "editor-role key $method $route" );
	}

	/**
	 * @dataProvider admin_cases
	 */
	public function test_admin_api_key_still_passes_the_auth_gate( string $method, string $route, array $params ): void {
		$this->assertTrue( $this->check( $method, $route, $this->key( array( 'read', 'write', 'admin' ), 'admin' ), $params ), "$method $route" );
	}

	/** What stays where it was: reads of widgets/sidebars/forms, product listing, product edit by id. */
	public static function unchanged_cases(): array {
		return array(
			'list sidebars'      => array( 'GET', '/mcpwp/v1/sidebars', array(), 'read' ),
			'sidebar widgets'    => array( 'GET', '/mcpwp/v1/sidebars/sidebar-1/widgets', array(), 'read' ),
			'widget types'       => array( 'GET', '/mcpwp/v1/widgets/types', array(), 'read' ),
			'get widget'         => array( 'GET', '/mcpwp/v1/widgets/text-1', array(), 'read' ),
			'list forms'         => array( 'GET', '/mcpwp/v1/forms', array(), 'read' ),
			'get form'           => array( 'GET', '/mcpwp/v1/forms/gravityforms/3', array(), 'read' ),
			'list products'      => array( 'GET', '/mcpwp/v1/woocommerce/products', array(), 'read' ),
			'update product'     => array( 'PUT', '/mcpwp/v1/woocommerce/products/5', array( 'id' => 5, 'status' => 'draft' ), 'write' ),
			'get favicon'        => array( 'GET', '/mcpwp/v1/favicon', array(), 'read' ),
			'get site context'   => array( 'GET', '/mcpwp/v1/site-context', array(), 'read' ),
			'get signal settings' => array( 'GET', '/mcpwp/v1/signals/settings', array(), 'read' ),
			'list blueprints'    => array( 'GET', '/mcpwp/v1/site-blueprints', array(), 'read' ),
			'get blueprint'      => array( 'GET', '/mcpwp/v1/site-blueprints/abc', array(), 'read' ),
			'extract blueprint'  => array( 'GET', '/mcpwp/v1/site-blueprints/extract', array(), 'read' ),
			'submit feedback'    => array( 'POST', '/mcpwp/v1/feedback', array( 'message' => 'x' ), 'write' ),
		);
	}

	/**
	 * @dataProvider unchanged_cases
	 */
	public function test_neighbouring_routes_keep_their_scope( string $method, string $route, array $params, string $scope ): void {
		$probe = new class() extends Mcpwp_Api_Auth_Test_Harness {
			public function scope( $request ) {
				return $this->get_required_scope_for_request( $request );
			}
		};
		$this->assertSame( $scope, $probe->scope( new WP_REST_Request( $method, $route, $params ) ) );
	}

	public function test_tools_resolve_to_admin_in_parity_with_their_routes(): void {
		foreach ( array( 'wp_get_form_entries', 'wp_add_widget', 'wp_update_widget', 'wp_delete_widget', 'wp_move_widget', 'wp_reorder_widgets', 'wc_create_product', 'wp_flush_permalinks', 'wp_flush_cache', 'wp_set_site_context', 'wp_create_site_blueprint' ) as $tool ) {
			$this->assertSame( 'admin', $this->harness()->required_scope_for_tool( $tool ), $tool );
		}
		foreach ( array( 'wp_get_form', 'wp_list_forms', 'wp_list_sidebars', 'wp_get_widget', 'wc_list_products', 'wp_get_site_context', 'wp_list_site_blueprints', 'wp_get_site_blueprint', 'wp_submit_feedback' ) as $tool ) {
			$this->assertNotSame( 'admin', $this->harness()->required_scope_for_tool( $tool ), $tool );
		}
	}
}
