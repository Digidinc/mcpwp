<?php
use PHPUnit\Framework\TestCase;

class Mcpwp_Scope_Matrix_Auth_Harness {
	use Mcpwp_Api_Auth;
	public function scope( $method, $route ) { return $this->get_required_scope_for_request( new WP_REST_Request( $method, $route ) ); }
	public function tool_scope( $tool ) { return $this->get_required_scope_for_tool_name( $tool ); }
}

final class AdminScopeMatrixTest extends TestCase {
	private $auth;
	protected function setUp(): void { $this->auth = new Mcpwp_Scope_Matrix_Auth_Harness(); }
	public function test_admin_rest_foundations_require_admin_for_mutation(): void {
		$this->assertSame( 'admin', $this->auth->scope( 'GET', '/mcpwp/v1/api-keys' ) );
		$this->assertSame( 'admin', $this->auth->scope( 'PATCH', '/mcpwp/v1/rate-limit' ) );
		$this->assertSame( 'admin', $this->auth->scope( 'GET', '/mcpwp/v1/plugin-settings' ) );
	}
	public function test_content_routes_use_read_and_write_scopes(): void {
		$this->assertSame( 'read', $this->auth->scope( 'GET', '/mcpwp/v1/posts' ) );
		$this->assertSame( 'write', $this->auth->scope( 'PATCH', '/mcpwp/v1/posts/7' ) );
		$this->assertSame( 'write', $this->auth->scope( 'DELETE', '/mcpwp/v1/drafts/7' ) );
	}
	public function test_approved_mcp_tool_scope_mirror(): void {
		$this->assertSame( 'read', $this->auth->tool_scope( 'wp_get_post' ) );
		$this->assertSame( 'write', $this->auth->tool_scope( 'wp_update_post' ) );
		$this->assertSame( 'write', $this->auth->tool_scope( 'wp_delete_draft' ) );
	}
}
