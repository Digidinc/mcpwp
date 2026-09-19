<?php
/**
 * Unit coverage for Mcpwp_REST_MCP::resolve_param_aliases() (#831).
 *
 * Issue #831: sibling MCP tools used different parameter names for the same
 * concept (`page_id`/`id`, `alt`/`alt_text`, `data`/`base64`, `key`/`name`,
 * ...). The fix renames each tool's schema to one canonical name and adds a
 * `param_aliases` map (deprecated name => canonical name) to the tool_map
 * entry, resolved by this method before validate_tool_arguments() and
 * resolve_tool_dispatch() ever see the arguments — so an existing caller
 * that still sends the old name keeps working, and the schema a client (or
 * Tool Search Tool) reads advertises only the canonical name.
 *
 * These tests exercise resolve_param_aliases() directly via reflection —
 * the same style ParamAliasesDispatchTest.php's sibling files
 * (BatchRouteNormalizationNullTest.php, BatchOperationPathTest.php) use for
 * a private method that is easiest to prove in isolation from full dispatch.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class ParamAliasResolutionTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']    = array();
		$GLOBALS['mcpwp_test_transients'] = array();
	}

	private function resolve( array $mapping, array $arguments ): array {
		$controller = new Mcpwp_REST_MCP();
		$method     = new ReflectionMethod( Mcpwp_REST_MCP::class, 'resolve_param_aliases' );
		$method->setAccessible( true );
		return $method->invoke( $controller, $mapping, $arguments );
	}

	public function test_a_single_alias_is_renamed_to_its_canonical_key(): void {
		$mapping = array( 'param_aliases' => array( 'page_id' => 'id' ) );
		$out     = $this->resolve( $mapping, array( 'page_id' => 42 ) );

		$this->assertSame( array( 'id' => 42 ), $out );
	}

	public function test_multiple_aliases_on_the_same_mapping_all_resolve(): void {
		$mapping = array(
			'param_aliases' => array(
				'base64'   => 'data',
				'alt_text' => 'alt',
			),
		);
		$out = $this->resolve(
			$mapping,
			array(
				'base64'   => 'ZmFrZQ==',
				'alt_text' => 'A hero image',
				'filename' => 'hero.png',
			)
		);

		// assertEquals (not assertSame): resolve_param_aliases() does not
		// promise to preserve original key order, only key/value identity.
		$this->assertEquals(
			array(
				'data'     => 'ZmFrZQ==',
				'alt'      => 'A hero image',
				'filename' => 'hero.png',
			),
			$out
		);
	}

	public function test_canonical_name_wins_when_both_are_sent(): void {
		$mapping = array( 'param_aliases' => array( 'name' => 'key' ) );
		$out     = $this->resolve( $mapping, array( 'name' => 'old_value', 'key' => 'new_value' ) );

		$this->assertSame( array( 'key' => 'new_value' ), $out, 'The explicit canonical value wins; the alias is dropped, not merged.' );
	}

	public function test_sending_only_the_canonical_name_is_unaffected(): void {
		$mapping = array( 'param_aliases' => array( 'name' => 'key' ) );
		$out     = $this->resolve( $mapping, array( 'key' => 'blogname' ) );

		$this->assertSame( array( 'key' => 'blogname' ), $out );
	}

	public function test_a_tool_with_no_param_aliases_entry_is_untouched(): void {
		$out = $this->resolve( array( 'route' => '/site-info', 'method' => 'GET' ), array( 'anything' => 1 ) );

		$this->assertSame( array( 'anything' => 1 ), $out );
	}

	public function test_unrelated_arguments_pass_through_unchanged(): void {
		$mapping = array( 'param_aliases' => array( 'page_id' => 'id' ) );
		$out     = $this->resolve( $mapping, array( 'element_id' => 'abc1234', 'settings' => array( 'title' => 'Hi' ) ) );

		$this->assertSame( array( 'element_id' => 'abc1234', 'settings' => array( 'title' => 'Hi' ) ), $out );
	}

	public function test_a_malformed_param_aliases_entry_does_not_crash_or_apply(): void {
		// sanitize_param_remap()-style malformed input (GatewayContractTest
		// covers that sanitizer for param_remap); resolve_param_aliases()
		// itself must not choke on a non-string key/value if one ever
		// reaches it un-sanitized.
		$mapping = array( 'param_aliases' => array( 5 => 'id', 'ok' => array( 'bad' ) ) );
		$out     = $this->resolve( $mapping, array( 'ok' => 'value', 5 => 'ignored' ) );

		$this->assertSame( array( 'ok' => 'value', 5 => 'ignored' ), $out );
	}
}
