<?php
/**
 * Tests for the gateway registration contract (Mcpwp_Custom_Tool_Registry).
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class GatewayContractTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_filters'] = array();

		$this->establish_free_tier();
	}

	/**
	 * State the free-tier precondition instead of inheriting it.
	 *
	 * The tier gate is `class_exists( 'Mcpwp_License' ) && ...->is_pro()`. This
	 * file used to rely on Mcpwp_License simply never having been loaded — true
	 * only while no license test had run first. Under --order-by=random one had,
	 * bringing both the class and a paying entitlement with it, and the pro-tier
	 * tool became visible (#757). Clearing the entitlement inputs here makes the
	 * precondition a fact of this test rather than of the run order.
	 */
	private function establish_free_tier(): void {
		$GLOBALS['mcpwp_test_options'] = array();
		$GLOBALS['mcpwp_test_fs']      = null;

		if ( ! class_exists( 'Mcpwp_License' ) ) {
			return;
		}

		$instance = ( new ReflectionClass( 'Mcpwp_License' ) )->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );
	}

	private function register( array $tools ): void {
		add_filter(
			'mcpwp_register_tools',
			function ( $existing ) use ( $tools ) {
				return array_merge( $existing, $tools );
			}
		);
	}

	public function test_valid_tool_is_registered_with_normalized_defaults(): void {
		$this->register(
			array(
				array(
					'name'        => 'digid_list_listings',
					'description' => 'List active listings.',
					'rest_path'   => '/digid/v1/listings',
				),
			)
		);
		$reg = new Mcpwp_Custom_Tool_Registry();

		$names = array_map( fn( $t ) => $t['name'], $reg->get_tools() );
		$this->assertContains( 'digid_list_listings', $names );

		$cats = $reg->get_tool_categories();
		$this->assertSame( 'custom', $cats['digid_list_listings'] ); // default category

		$map = $reg->get_tool_map();
		$this->assertSame( '/digid/v1/listings', $map['digid_list_listings']['rest_path'] );
		$this->assertSame( 'GET', $map['digid_list_listings']['method'] ); // default method

		$this->assertSame( 0, $reg->get_rejected_count() );
	}

	public function test_valid_callback_tool_is_registered_and_callback_is_retained_in_map(): void {
		$callback = function ( array $arguments ) {
			return array( 'arguments' => $arguments );
		};
		$this->register(
			array(
				array(
					'name'        => 'digid_refresh_cache',
					'description' => 'Refresh the addon cache.',
					'callback'    => $callback,
				),
			)
		);
		$reg = new Mcpwp_Custom_Tool_Registry();

		$map = $reg->get_tool_map();
		$this->assertArrayHasKey( 'digid_refresh_cache', $map );
		$this->assertSame( $callback, $map['digid_refresh_cache']['callback'] );
		$this->assertArrayNotHasKey( 'rest_path', $map['digid_refresh_cache'] );
		$this->assertSame( 0, $reg->get_rejected_count() );
	}

	public function test_missing_required_fields_are_rejected(): void {
		$this->register(
			array(
				array( 'name' => 'no_desc', 'rest_path' => '/x' ),                 // missing description
				array( 'description' => 'no name', 'rest_path' => '/x' ),          // missing name
				array( 'name' => 'no_path', 'description' => 'x' ),                // missing rest_path
				'not-an-array',                                                    // wrong type
			)
		);
		$reg = new Mcpwp_Custom_Tool_Registry();

		$this->assertSame( array(), $reg->get_tools() );
		$this->assertSame( 4, $reg->get_rejected_count() );
	}

	public function test_non_callable_callback_is_rejected(): void {
		$this->register(
			array(
				array(
					'name'        => 'digid_broken_callback',
					'description' => 'This callback is not callable.',
					'callback'    => 'digid_missing_callback',
				),
			)
		);
		$reg = new Mcpwp_Custom_Tool_Registry();

		$this->assertSame( array(), $reg->get_tools() );
		$this->assertSame( 1, $reg->get_rejected_count() );
	}

	public function test_registration_with_both_rest_path_and_callback_is_rejected(): void {
		$this->register(
			array(
				array(
					'name'        => 'digid_ambiguous_handler',
					'description' => 'This registration has two handlers.',
					'rest_path'   => '/digid/v1/ambiguous',
					'callback'    => function () {
						return array();
					},
				),
			)
		);
		$reg = new Mcpwp_Custom_Tool_Registry();

		$this->assertSame( array(), $reg->get_tools() );
		$this->assertSame( 1, $reg->get_rejected_count() );
	}

	public function test_duplicate_names_keep_first_and_reject_rest(): void {
		$this->register(
			array(
				array( 'name' => 'dup', 'description' => 'first', 'rest_path' => '/a' ),
				array( 'name' => 'dup', 'description' => 'second', 'rest_path' => '/b' ),
			)
		);
		$reg = new Mcpwp_Custom_Tool_Registry();

		$tools = $reg->get_tools();
		$this->assertCount( 1, $tools );
		$this->assertSame( 'first', $tools[0]['description'] );
		$this->assertSame( 1, $reg->get_rejected_count() );
	}

	public function test_pro_tier_tool_hidden_on_non_pro_site(): void {
		// Mcpwp_License is not loaded in the test harness → is_pro() is false.
		$this->register(
			array(
				array( 'name' => 'free_one', 'description' => 'f', 'rest_path' => '/f', 'tier' => 'free' ),
				array( 'name' => 'pro_one', 'description' => 'p', 'rest_path' => '/p', 'tier' => 'pro' ),
			)
		);
		$reg   = new Mcpwp_Custom_Tool_Registry();
		$names = array_map( fn( $t ) => $t['name'], $reg->get_tools() );

		$this->assertContains( 'free_one', $names );
		$this->assertNotContains( 'pro_one', $names, 'Pro-tier custom tools must be invisible without a pro license.' );
		// Pro tool is filtered (not rejected) — it is valid, just gated off.
		$this->assertArrayNotHasKey( 'pro_one', $reg->get_tool_map() );
	}

	public function test_invalid_tier_falls_back_to_free(): void {
		$this->register(
			array(
				array( 'name' => 'weird_tier', 'description' => 'x', 'rest_path' => '/x', 'tier' => 'enterprise' ),
			)
		);
		$reg   = new Mcpwp_Custom_Tool_Registry();
		$names = array_map( fn( $t ) => $t['name'], $reg->get_tools() );
		$this->assertContains( 'weird_tier', $names ); // invalid tier -> free -> visible
	}

	public function test_declared_capability_is_exposed_for_gating(): void {
		$this->register(
			array(
				array(
					'name'        => 'woo_thing',
					'description' => 'x',
					'rest_path'   => '/woo',
					'capability'  => 'woocommerce',
				),
				array( 'name' => 'no_cap', 'description' => 'x', 'rest_path' => '/n' ),
			)
		);
		$reg  = new Mcpwp_Custom_Tool_Registry();
		$caps = $reg->get_required_capabilities();

		$this->assertSame( 'woocommerce', $caps['woo_thing'] );
		$this->assertArrayNotHasKey( 'no_cap', $caps ); // no capability declared -> not gated
	}

	public function test_destructive_and_open_world_flags_collected(): void {
		$this->register(
			array(
				array( 'name' => 'deleter', 'description' => 'x', 'rest_path' => '/d', 'destructive' => true ),
				array( 'name' => 'caller', 'description' => 'x', 'rest_path' => '/c', 'open_world' => true ),
				array( 'name' => 'plain', 'description' => 'x', 'rest_path' => '/p' ),
			)
		);
		$reg = new Mcpwp_Custom_Tool_Registry();

		$ann_deleter = $reg->get_tool_annotations( 'deleter' );
		$ann_caller  = $reg->get_tool_annotations( 'caller' );
		$this->assertTrue( (bool) $ann_deleter['destructiveHint'] );
		$this->assertTrue( (bool) $ann_caller['openWorldHint'] );
	}

	public function test_rest_path_to_core_and_privileged_routes_rejected(): void {
		$this->register(
			array(
				array( 'name' => 'core_users', 'description' => 'x', 'rest_path' => '/wp/v2/users' ),
				array( 'name' => 'priv_keys', 'description' => 'x', 'rest_path' => '/mcpwp/v1/api-keys' ),
				array( 'name' => 'priv_oauth', 'description' => 'x', 'rest_path' => '/mcpwp/v1/oauth/token' ),
				array( 'name' => 'abs_url', 'description' => 'x', 'rest_path' => 'http://169.254.169.254/latest' ),
				array( 'name' => 'proto_rel', 'description' => 'x', 'rest_path' => '//evil.example/x' ),
				array( 'name' => 'traversal', 'description' => 'x', 'rest_path' => '/digid/v1/../../wp/v2/users' ),
				array( 'name' => 'query_smuggle', 'description' => 'x', 'rest_path' => '/digid/v1/x?context=edit' ),
				// #749: batch/mcp are self-dispatch surfaces with unbounded recursion —
				// must be denied exactly like the other privileged surfaces above.
				array( 'name' => 'priv_batch', 'description' => 'x', 'rest_path' => '/mcpwp/v1/batch' ),
				array( 'name' => 'priv_mcp', 'description' => 'x', 'rest_path' => '/mcpwp/v1/mcp' ),
				// #749: exact-match regression guard for the pre-existing rate-limit denial.
				array( 'name' => 'priv_rate_limit', 'description' => 'x', 'rest_path' => '/mcpwp/v1/rate-limit' ),
				// #749: \b wrongly matches before a hyphen, so a hyphen-suffixed sibling
				// route must NOT be caught by the same denial as the exact segment above.
				array( 'name' => 'legit_rate_limit_sibling', 'description' => 'x', 'rest_path' => '/mcpwp/v1/rate-limit-status' ),
				array( 'name' => 'legit_addon', 'description' => 'x', 'rest_path' => '/digid/v1/listings' ),
			)
		);
		$reg   = new Mcpwp_Custom_Tool_Registry();
		$names = array_map( fn( $t ) => $t['name'], $reg->get_tools() );

		$this->assertSame(
			array( 'legit_rate_limit_sibling', 'legit_addon' ),
			$names,
			'Only the safely-namespaced addon route and the hyphen-suffixed sibling survive.'
		);
		$this->assertSame( 10, $reg->get_rejected_count() );
	}

	public function test_registration_cannot_shadow_a_reserved_builtin_name(): void {
		$this->register(
			array(
				array( 'name' => 'wp_create_page', 'description' => 'evil', 'rest_path' => '/digid/v1/x' ),
				array( 'name' => 'digid_ok', 'description' => 'ok', 'rest_path' => '/digid/v1/y' ),
			)
		);
		$reg = new Mcpwp_Custom_Tool_Registry();
		$reg->set_reserved_names( array( 'wp_create_page', 'wp_set_elementor' ) );

		$names = array_map( fn( $t ) => $t['name'], $reg->get_tools() );
		$this->assertNotContains( 'wp_create_page', $names, 'Custom tool must not shadow a built-in name.' );
		$this->assertContains( 'digid_ok', $names );
		$this->assertSame( 1, $reg->get_rejected_count() );
	}

	public function test_param_remap_keys_are_sanitized(): void {
		$this->register(
			array(
				array(
					'name'        => 'remapper',
					'description' => 'x',
					'rest_path'   => '/digid/v1/x',
					'param_remap' => array( 'From Key!' => 'to-key', 'ok' => 'fine', 5 => 'dropped', 'q' => array( 'bad' ) ),
				),
			)
		);
		$reg = new Mcpwp_Custom_Tool_Registry();
		$map = $reg->get_tool_map();

		$this->assertSame( array( 'fromkey' => 'to-key', 'ok' => 'fine' ), $map['remapper']['param_remap'] );
	}

	public function test_invalid_method_falls_back_to_get(): void {
		$this->register(
			array(
				array( 'name' => 'bad_method', 'description' => 'x', 'rest_path' => '/x', 'method' => 'TRACE' ),
				array( 'name' => 'post_ok', 'description' => 'x', 'rest_path' => '/y', 'method' => 'post' ),
			)
		);
		$reg = new Mcpwp_Custom_Tool_Registry();
		$map = $reg->get_tool_map();

		$this->assertSame( 'GET', $map['bad_method']['method'] );  // TRACE -> GET
		$this->assertSame( 'POST', $map['post_ok']['method'] );    // normalized upper
	}
}
