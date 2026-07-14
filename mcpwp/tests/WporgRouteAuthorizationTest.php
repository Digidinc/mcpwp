<?php

use PHPUnit\Framework\TestCase;

final class WporgRouteAuthorizationTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options'] = array(
			'mcpwp_rate_limit_settings' => array( 'enabled' => false ),
		);
		$GLOBALS['mcpwp_test_transients'] = array();
		$GLOBALS['mcpwp_test_current_user'] = 99;
		$GLOBALS['mcpwp_test_users'] = array(
			(object) array( 'ID' => 2, 'roles' => array( 'mcpwp_api_agent' ) ),
			(object) array( 'ID' => 7, 'roles' => array( 'author' ) ),
			(object) array( 'ID' => 8, 'roles' => array( 'editor' ) ),
		);
		$GLOBALS['mcpwp_test_user_caps'] = array(
			7 => array( 'read' => true, 'edit_posts' => true, 'edit_pages' => true, 'upload_files' => true, 'manage_categories' => true, 'mcpwp_manage_settings' => true ),
			8 => array( 'read' => true ),
			99 => array( 'read' => true, 'mcpwp_manage_settings' => true ),
		);
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		$this->reset_limiter_singleton();
	}

	public function test_runtime_routes_match_exact_hardening_fixture(): void {
		$fixture = $this->read_fixture( 'wporg-routes.json' );
		$this->register_all_routes();
		$actual = array();
		foreach ( $GLOBALS['_mcpwp_test_registered_routes'] as $registration ) {
			$configurations = isset( $registration['args']['methods'] ) ? array( $registration['args'] ) : $registration['args'];
			foreach ( $configurations as $configuration ) {
				foreach ( array_map( 'trim', explode( ',', $configuration['methods'] ) ) as $method ) {
					$permission = $configuration['permission_callback'] ?? null;
					$actual[] = array(
						'route'        => $registration['route'],
						'method'       => $method,
						'permission'   => is_array( $permission ) ? $permission[1] : $permission,
						'category'     => $configuration['mcpwp_category'] ?? null,
						'scope'        => $configuration['mcpwp_scope'] ?? null,
						'capabilities' => $configuration['mcpwp_capabilities'] ?? array(),
						'mutable'      => $configuration['mcpwp_mutable'] ?? null,
						'args'         => $configuration['args'] ?? array(),
					);
				}
			}
		}
		$sort = static function ( $left, $right ) { return strcmp( $left['route'] . '|' . $left['method'], $right['route'] . '|' . $right['method'] ); };
		usort( $fixture['endpoints'], $sort );
		usort( $actual, $sort );
		$this->assertSame( $fixture['endpoints'], $actual );
	}

	public function test_runtime_tools_match_exact_hardening_fixture(): void {
		$fixture = $this->read_fixture( 'wporg-tools.json' );
		$registry = new Mcpwp_MCP_Free_Tools();
		$map = $registry->get_tool_map();
		$capabilities = $registry->get_required_capabilities();
		$actual = array();
		foreach ( $registry->get_tools() as $definition ) {
			$name = $definition['name'];
			$actual[] = array(
				'name'                 => $name,
				'dispatch'             => $map[ $name ],
				'category'             => $registry->get_tool_category( $name ),
				'description'          => $definition['description'],
				'requiredCapabilities' => $capabilities[ $name ] ?? array(),
				'annotations'          => $definition['annotations'],
				'inputSchema'          => json_decode( wp_json_encode( $definition['inputSchema'] ), true ),
			);
		}
		$this->assertSame( $fixture['tools'], $actual );
	}

	public function test_route_and_tool_snapshot_mutations_are_detected(): void {
		$route_fixture = $this->read_fixture( 'wporg-routes.json' );
		$mutated_routes = $route_fixture;
		$mutated_routes['endpoints'][0]['method'] = 'DELETE';
		$this->assertFalse( $this->route_fixture_matches_runtime( $mutated_routes ) );
		$mutated_routes = $route_fixture;
		$mutated_routes['endpoints'][0]['permission'] = 'check_permission';
		$this->assertFalse( $this->route_fixture_matches_runtime( $mutated_routes ) );
		$mutated_routes = $route_fixture;
		$mutated_routes['endpoints'][0]['capabilities'][] = 'unreviewed_capability';
		$this->assertFalse( $this->route_fixture_matches_runtime( $mutated_routes ) );
		$mutated_routes = $route_fixture;
		foreach ( $mutated_routes['endpoints'] as &$endpoint ) {
			if ( isset( $endpoint['args']['posts_per_page']['maximum'] ) ) { $endpoint['args']['posts_per_page']['maximum'] = 101; break; }
		}
		unset( $endpoint );
		$this->assertFalse( $this->route_fixture_matches_runtime( $mutated_routes ) );

		$tool_fixture = $this->read_fixture( 'wporg-tools.json' );
		$mutated_tools = $tool_fixture;
		$mutated_tools['tools'][0]['annotations']['openWorldHint'] = true;
		$this->assertFalse( $this->tool_fixture_matches_runtime( $mutated_tools ) );
		$mutated_tools = $tool_fixture;
		$mutated_tools['tools'][0]['inputSchema']['additionalProperties'] = true;
		$this->assertFalse( $this->tool_fixture_matches_runtime( $mutated_tools ) );
		$mutated_tools = $tool_fixture;
		$mutated_tools['tools'][0]['dispatch']['route'] = '/unreviewed';
		$this->assertFalse( $this->tool_fixture_matches_runtime( $mutated_tools ) );
		$mutated_tools = $tool_fixture;
		$mutated_tools['tools'][0]['requiredCapabilities'][] = 'unreviewed_capability';
		$this->assertFalse( $this->tool_fixture_matches_runtime( $mutated_tools ) );
	}

	public function test_bound_key_replaces_ambient_cookie_identity_with_stored_owner(): void {
		$this->seed_key( 'owner-seven', 7, array( 'read' ) );
		$result = ( new Mcpwp_Api_Auth_Test_Harness() )->verify_api_key( $this->request( 'owner-seven' ) );
		$this->assertTrue( $result );
		$this->assertSame( 7, get_current_user_id() );
	}

	public function test_two_keys_install_their_distinct_stored_owners(): void {
		$this->seed_keys( array(
			$this->key_record( 'owner-seven', 7, array( 'read' ) ),
			$this->key_record( 'owner-eight', 8, array( 'read' ) ),
		) );
		$auth = new Mcpwp_Api_Auth_Test_Harness();
		$this->assertTrue( $auth->verify_api_key( $this->request( 'owner-seven' ) ) );
		$this->assertSame( 7, get_current_user_id() );
		$GLOBALS['mcpwp_test_current_user'] = 99;
		$this->assertTrue( $auth->verify_api_key( $this->request( 'owner-eight' ) ) );
		$this->assertSame( 8, get_current_user_id() );
	}

	public function test_unbound_key_owner_fails_closed_without_role_fallback(): void {
		$this->seed_key( 'unbound', null, array( 'read' ) );
		$result = ( new Mcpwp_Api_Auth_Test_Harness() )->verify_api_key( $this->request( 'unbound' ) );
		$this->assertWpError( 'api_key_owner_setup_required', 500, $result );
		$this->assertSame( 99, get_current_user_id() );
	}

	public function test_deleted_bound_owner_fails_closed(): void {
		$this->seed_key( 'deleted-owner', 404, array( 'read' ) );
		$result = ( new Mcpwp_Api_Auth_Test_Harness() )->verify_api_key( $this->request( 'deleted-owner' ) );
		$this->assertWpError( 'api_key_owner_unavailable', 500, $result );
		$this->assertSame( 99, get_current_user_id() );
	}

	public function test_empty_or_unknown_stored_scopes_fail_closed(): void {
		foreach ( array( array(), array( 'read', 'super-admin' ) ) as $index => $scopes ) {
			$key = 'invalid-scopes-' . $index;
			$this->seed_key( $key, 7, $scopes );
			$result = ( new Mcpwp_Api_Auth_Test_Harness() )->verify_api_key( $this->request( $key ) );
			$this->assertWpError( 'api_key_scope_invalid', 500, $result );
		}
	}

	public function test_direct_rest_honors_per_key_category_restriction(): void {
		$this->seed_key( 'content-only', 7, array( 'read' ), array( 'content' ) );
		$result = ( new Mcpwp_REST_Site_Info() )->check_permission( $this->request( 'content-only' ) );
		$this->assertWpError( 'tool_category_forbidden', 403, $result );
	}

	public function test_mcp_transport_does_not_require_site_category(): void {
		$this->seed_key( 'content-only-mcp', 7, array( 'read', 'write' ), array( 'content' ) );
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), array( 'X-API-Key' => 'content-only-mcp' ), array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => array() ) );
		$this->assertTrue( ( new Mcpwp_REST_MCP() )->check_mcp_permission( $request ) );
	}

	public function test_direct_rest_honors_site_disabled_category(): void {
		$this->seed_key( 'site-key', 7, array( 'read' ) );
		$GLOBALS['mcpwp_test_options']['mcpwp_disabled_tool_categories'] = array( 'site' );
		$result = ( new Mcpwp_REST_Site_Info() )->check_permission( $this->request( 'site-key' ) );
		$this->assertWpError( 'tool_category_disabled', 403, $result );
	}

	public function test_route_requires_bound_owner_wordpress_capability(): void {
		$this->seed_key( 'no-read', 8, array( 'read' ) );
		$GLOBALS['mcpwp_test_user_caps'][8]['read'] = false;
		$result = ( new Mcpwp_REST_Site_Info() )->check_permission( $this->request( 'no-read' ) );
		$this->assertWpError( 'insufficient_capability', 403, $result );
	}

	public function test_nonpublic_lists_require_edit_and_private_capabilities(): void {
		$this->seed_key( 'private-list', 7, array( 'read' ), array( 'content' ) );
		$request = new WP_REST_Request( 'GET', '/mcpwp/v1/posts', array( 'post_status' => 'private' ), array( 'X-API-Key' => 'private-list' ) );
		$result = ( new Mcpwp_REST_Posts() )->check_list_posts_permission( $request );
		$this->assertWpError( 'insufficient_capability', 403, $result );
		$GLOBALS['mcpwp_test_user_caps'][7]['read_private_posts'] = true;
		$this->assertTrue( ( new Mcpwp_REST_Posts() )->check_list_posts_permission( $request ) );
	}

	public function test_nonpublic_list_is_restricted_to_owner_without_edit_others(): void {
		$GLOBALS['mcpwp_test_current_user'] = 7;
		$GLOBALS['_mcpwp_test_posts'] = array(
			7 => $this->post_fixture( 7, 'post', 'draft', 7 ),
			8 => $this->post_fixture( 8, 'post', 'draft', 8 ),
		);
		$result = ( new Mcpwp_Posts() )->list_posts( array( 'post_status' => 'draft', 'posts_per_page' => 20, 'paged' => 1 ) );
		$this->assertSame( array( 7 ), array_column( $result['posts'], 'id' ) );
		$this->assertArrayNotHasKey( 'edit_url', $result['posts'][0] );
	}

	public function test_drafts_filter_authority_independently_for_posts_and_pages(): void {
		$GLOBALS['mcpwp_test_current_user'] = 7;
		$GLOBALS['mcpwp_test_user_caps'][7] = array(
			'edit_posts' => true, 'edit_pages' => true,
			'edit_others_posts' => true, 'edit_others_pages' => false,
		);
		$GLOBALS['_mcpwp_test_posts'] = array(
			41 => $this->post_fixture( 41, 'post', 'draft', 7 ),
			42 => $this->post_fixture( 42, 'post', 'draft', 8 ),
			43 => $this->post_fixture( 43, 'page', 'draft', 7 ),
			44 => $this->post_fixture( 44, 'page', 'draft', 8 ),
		);

		$result = ( new Mcpwp_Drafts() )->list_drafts( array( 'type' => 'all', 'posts_per_page' => 20, 'paged' => 1, 'orderby' => 'id', 'order' => 'asc' ) );

		$this->assertSame( array( 41, 42, 43 ), array_column( $result['drafts'], 'id' ) );
		$this->assertSame( 3, $result['total'] );
		$this->assertSame( 2, $result['posts'] );
		$this->assertSame( 1, $result['pages'] );
	}

	public function test_drafts_skip_a_type_without_its_edit_capability(): void {
		$GLOBALS['mcpwp_test_current_user'] = 7;
		$GLOBALS['mcpwp_test_user_caps'][7] = array( 'edit_posts' => true, 'edit_others_posts' => true, 'edit_pages' => false );
		$GLOBALS['_mcpwp_test_posts'] = array(
			45 => $this->post_fixture( 45, 'post', 'draft', 8 ),
			46 => $this->post_fixture( 46, 'page', 'draft', 7 ),
		);

		$result = ( new Mcpwp_Drafts() )->list_drafts( array( 'type' => 'all', 'posts_per_page' => 20, 'paged' => 1 ) );

		$this->assertSame( array( 45 ), array_column( $result['drafts'], 'id' ) );
	}

	public function test_media_list_filters_every_attachment_by_read_post(): void {
		$GLOBALS['mcpwp_test_current_user'] = 7;
		$GLOBALS['mcpwp_test_user_caps'][7]['read_post:21'] = true;
		$GLOBALS['mcpwp_test_user_caps'][7]['read_post:22'] = false;
		$GLOBALS['_mcpwp_test_posts'] = array(
			21 => $this->attachment_fixture( 21 ),
			22 => $this->attachment_fixture( 22 ),
		);
		$result = ( new Mcpwp_Media() )->list_media( array( 'posts_per_page' => 20, 'paged' => 1 ) );
		$this->assertSame( array( 21 ), array_column( $result['media'], 'id' ) );
	}

	public function test_media_paginates_after_visibility_filter_with_authorized_totals(): void {
		$GLOBALS['mcpwp_test_current_user'] = 7;
		$GLOBALS['mcpwp_test_user_caps'][7] = array( 'read_post:51' => false, 'read_post:52' => true, 'read_post:53' => true );
		$GLOBALS['_mcpwp_test_posts'] = array(
			51 => $this->attachment_fixture( 51 ),
			52 => $this->attachment_fixture( 52 ),
			53 => $this->attachment_fixture( 53 ),
		);

		$page_one = ( new Mcpwp_Media() )->list_media( array( 'posts_per_page' => 1, 'paged' => 1, 'orderby' => 'id', 'order' => 'asc' ) );
		$page_two = ( new Mcpwp_Media() )->list_media( array( 'posts_per_page' => 1, 'paged' => 2, 'orderby' => 'id', 'order' => 'asc' ) );

		$this->assertSame( array( 52 ), array_column( $page_one['media'], 'id' ) );
		$this->assertSame( array( 53 ), array_column( $page_two['media'], 'id' ) );
		$this->assertSame( 2, $page_one['total'] );
		$this->assertSame( 2, $page_one['pages'] );
	}

	public function test_term_creation_uses_selected_taxonomy_manage_capability(): void {
		$this->seed_key( 'term-key', 7, array( 'write' ), array( 'taxonomy' ) );
		$GLOBALS['mcpwp_test_user_caps'][7]['manage_categories'] = false;
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/terms', array(), array( 'X-API-Key' => 'term-key' ), array( 'taxonomy' => 'category', 'name' => 'News' ) );
		$this->assertWpError( 'insufficient_capability', 403, ( new Mcpwp_REST_Site_Taxonomy() )->check_create_term_permission( $request ) );
	}

	public function test_category_assignment_requires_taxonomy_assign_capability(): void {
		$this->seed_key( 'post-key', 7, array( 'write' ), array( 'content' ) );
		$GLOBALS['mcpwp_test_user_caps'][7]['assign_categories'] = false;
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/posts', array(), array( 'X-API-Key' => 'post-key' ), array( 'title' => 'Post', 'categories' => array( 3 ) ) );
		$this->assertWpError( 'insufficient_capability', 403, ( new Mcpwp_REST_Posts() )->check_create_post_permission( $request ) );
	}

	public function test_direct_update_to_publish_requires_admin_scope(): void {
		$this->seed_key( 'write-update', 7, array( 'read', 'write' ), array( 'content' ) );
		$GLOBALS['_mcpwp_test_posts'][31] = $this->post_fixture( 31, 'post', 'draft', 7 );
		$GLOBALS['mcpwp_test_user_caps'][7]['edit_post:31'] = true;
		$GLOBALS['mcpwp_test_user_caps'][7]['publish_posts'] = true;
		$request = new WP_REST_Request( 'PATCH', '/mcpwp/v1/posts/31', array( 'id' => 31 ), array( 'X-API-Key' => 'write-update' ), array( 'status' => 'publish' ) );
		$this->assertWpError( 'insufficient_scope', 403, ( new Mcpwp_REST_Posts() )->check_update_post_permission( $request ) );
	}

	public function test_site_info_returns_numeric_readable_counts(): void {
		$GLOBALS['_mcpwp_test_posts'] = array( 1 => $this->post_fixture( 1, 'post', 'publish', 7 ), 2 => $this->post_fixture( 2, 'page', 'draft', 7 ) );
		$GLOBALS['_mcpwp_test_count_post_calls'] = array();
		$data = ( new Mcpwp_REST_Site_Info() )->get_site_info( new WP_REST_Request() )->get_data();
		$this->assertSame( array( 'posts' => 1, 'pages' => 1, 'media' => 0 ), $data['counts'] );
		$this->assertSame( array( array( 'post', 'readable' ), array( 'page', 'readable' ), array( 'attachment', 'readable' ) ), $GLOBALS['_mcpwp_test_count_post_calls'] );
	}

	public function test_limiter_receives_get_method_for_read_multiplier(): void {
		$this->enable_rate_limit( 1 );
		$this->seed_key( 'read-rate', 7, array( 'read' ) );
		$auth = new Mcpwp_Api_Auth_Test_Harness();
		$this->assertTrue( $auth->verify_api_key( $this->request( 'read-rate', 'GET' ) ) );
		$this->assertTrue( $auth->verify_api_key( $this->request( 'read-rate', 'GET' ) ), 'GET must retain the limiter read multiplier.' );
	}

	public function test_unreviewed_stored_rate_override_is_not_authoritative(): void {
		$this->enable_rate_limit( 10 );
		$record = $this->key_record( 'override-rate', 7, array( 'write' ) );
		$record['rate_limits'] = array( 'burst' => 1, 'per_minute' => 100, 'per_hour' => 100 );
		$this->seed_keys( array( $record ) );
		$auth = new Mcpwp_Api_Auth_Test_Harness();
		$this->assertTrue( $auth->verify_api_key( $this->request( 'override-rate', 'POST', '/mcpwp/v1/posts' ) ) );
		$this->assertTrue( $auth->verify_api_key( $this->request( 'override-rate', 'POST', '/mcpwp/v1/posts' ) ) );
	}

	private function register_all_routes(): void {
		foreach ( array(
			new Mcpwp_REST_MCP(), new Mcpwp_REST_Site_Info(), new Mcpwp_REST_Posts(), new Mcpwp_REST_Pages(),
			new Mcpwp_REST_Media(), new Mcpwp_REST_Site_Taxonomy(), new Mcpwp_REST_Site_Settings(),
			new Mcpwp_REST_Rate_Limit(), new Mcpwp_REST_API_Keys(),
		) as $controller ) {
			$controller->register_routes();
		}
	}

	private function read_fixture( $name ): array {
		return json_decode( (string) file_get_contents( __DIR__ . '/fixtures/' . $name ), true, 512, JSON_THROW_ON_ERROR );
	}

	private function route_fixture_matches_runtime( $fixture ): bool {
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		$this->register_all_routes();
		$actual = array();
		foreach ( $GLOBALS['_mcpwp_test_registered_routes'] as $registration ) {
			$configurations = isset( $registration['args']['methods'] ) ? array( $registration['args'] ) : $registration['args'];
			foreach ( $configurations as $configuration ) {
				foreach ( array_map( 'trim', explode( ',', $configuration['methods'] ) ) as $method ) {
					$permission = $configuration['permission_callback'] ?? null;
					$actual[] = array( 'route' => $registration['route'], 'method' => $method, 'permission' => is_array( $permission ) ? $permission[1] : $permission, 'category' => $configuration['mcpwp_category'] ?? null, 'scope' => $configuration['mcpwp_scope'] ?? null, 'capabilities' => $configuration['mcpwp_capabilities'] ?? array(), 'mutable' => $configuration['mcpwp_mutable'] ?? null, 'args' => $configuration['args'] ?? array() );
				}
			}
		}
		$sort = static function ( $left, $right ) { return strcmp( $left['route'] . '|' . $left['method'], $right['route'] . '|' . $right['method'] ); };
		usort( $fixture['endpoints'], $sort ); usort( $actual, $sort );
		return $fixture['endpoints'] === $actual;
	}

	private function tool_fixture_matches_runtime( $fixture ): bool {
		$registry = new Mcpwp_MCP_Free_Tools(); $map = $registry->get_tool_map(); $capabilities = $registry->get_required_capabilities(); $actual = array();
		foreach ( $registry->get_tools() as $definition ) {
			$name = $definition['name'];
			$actual[] = array( 'name' => $name, 'dispatch' => $map[ $name ], 'category' => $registry->get_tool_category( $name ), 'description' => $definition['description'], 'requiredCapabilities' => $capabilities[ $name ] ?? array(), 'annotations' => $definition['annotations'], 'inputSchema' => json_decode( wp_json_encode( $definition['inputSchema'] ), true ) );
		}
		return $fixture['tools'] === $actual;
	}

	private function seed_key( $key, $user_id, $scopes, $categories = array() ): void {
		$this->seed_keys( array( $this->key_record( $key, $user_id, $scopes, $categories ) ) );
	}

	private function seed_keys( $records ): void {
		$GLOBALS['mcpwp_test_options']['mcpwp_api_keys'] = $records;
	}

	private function key_record( $key, $user_id, $scopes, $categories = array() ): array {
		$record = array(
			'id' => $key, 'hash' => $key, 'scopes' => $scopes, 'tool_categories' => $categories,
			'created_at' => '2026-07-13 00:00:00', 'last_used_at' => null, 'revoked_at' => null,
		);
		if ( null !== $user_id ) { $record['user_id'] = $user_id; }
		return $record;
	}

	private function request( $key, $method = 'GET', $route = '/mcpwp/v1/site-info' ): WP_REST_Request {
		return new WP_REST_Request( $method, $route, array(), array( 'X-API-Key' => $key ) );
	}

	private function post_fixture( $id, $type, $status, $author ) {
		return (object) array( 'ID' => $id, 'post_type' => $type, 'post_title' => 'Item ' . $id, 'post_name' => 'item-' . $id, 'post_status' => $status, 'post_date' => '2026-01-01', 'post_date_gmt' => '2026-01-01', 'post_modified' => '2026-01-02', 'post_modified_gmt' => '2026-01-02', 'post_parent' => 0, 'menu_order' => 0, 'post_author' => $author, 'post_content' => '', 'post_excerpt' => '' );
	}

	private function attachment_fixture( $id ) {
		$post = $this->post_fixture( $id, 'attachment', 'inherit', 7 );
		$post->post_mime_type = 'image/png';
		$post->attached_file = $id . '.png';
		return $post;
	}

	private function assertWpError( $code, $status, $result ): void {
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $status, $result->get_error_data()['status'] );
	}

	private function enable_rate_limit( $burst ): void {
		$GLOBALS['mcpwp_test_options']['mcpwp_rate_limit_settings'] = array(
			'enabled' => true, 'requests_per_minute' => 100, 'requests_per_hour' => 100, 'burst_limit' => $burst, 'whitelist' => array(),
		);
		$this->reset_limiter_singleton();
	}

	private function reset_limiter_singleton(): void {
		$reflection = new ReflectionClass( Mcpwp_Rate_Limiter::class );
		$instance = $reflection->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null );
	}
}
