<?php

use PHPUnit\Framework\TestCase;

/**
 * RC 3.12.1 adversarial review, BLOCK 2 — applying a WooCommerce product
 * archetype bypassed the same publish-scope gate its own MCP tool entry
 * already requires.
 *
 * POST /woocommerce/archetypes/{id}/apply dispatches through the SAME
 * create_product()/update_product() path as /woocommerce/products (see
 * Mcpwp_REST_WooCommerce::apply_product_archetype() and
 * extract_product_override_payload(), which whitelists 'status' as an
 * overridable field), but the id sits in the middle of the path
 * (/woocommerce/archetypes/{id}/apply) so the plain prefix-matched
 * $publish_gated_routes table in get_required_scope_for_request() never saw
 * it. wc_apply_product_archetype's MCP tool-name entry only requires 'write'
 * scope (trait-mcpwp-api-auth.php's $write_tools list) — same as
 * wc_create_product/wc_update_product — so nothing blocked this route
 * before this fix, on either direct REST or /mcp (which re-derives scope
 * from this same route table via rest_do_request() — see
 * Mcpwp_REST_MCP::handle_tools_call()).
 *
 * The part that matters most: an archetype's OWN stored product_data can
 * already carry 'status' => 'publish' from whenever the archetype was
 * defined, so a caller need not repeat 'status' in the /apply request at all
 * to reach the same outcome — a plain per-request status check is not
 * enough. Mcpwp_REST_WooCommerce::archetype_apply_targets_publish_status()
 * merges the request's override on top of the stored archetype the same way
 * apply_product_archetype() itself does, and these tests exercise that
 * merge in both directions.
 *
 * @package MCPWP
 */

require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-woocommerce.php';

final class WooCommerceArchetypeApplyRequiresAdminScopeTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']            = array();
		$GLOBALS['mcpwp_test_transients']         = array();
		$GLOBALS['mcpwp_test_current_user']       = 0;
		$GLOBALS['mcpwp_test_is_admin']           = false;
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		// The ids these requests name must exist: since #1030 round-5 the
		// declared-post-ref guard answers a missing id itself, before scope.
		$GLOBALS['_mcpwp_test_posts'] = array(
			42 => new WP_Post( array( 'ID' => 42, 'post_type' => 'product', 'post_status' => 'publish' ) ),
		);
		if ( method_exists( 'Mcpwp_REST_API', 'reset_verdict_memo' ) ) {
			Mcpwp_REST_API::reset_verdict_memo();
		}
	}

	private function controller(): Mcpwp_REST_WooCommerce {
		// verify_api_key() never reaches the handler for these tests (it is
		// rejected — or accepted — at the permission_callback/scope-check
		// stage before any WooCommerce call), so a null handler is safe here.
		return new Mcpwp_REST_WooCommerce( null );
	}

	private function write_key( $controller ): string {
		return $controller->create_scoped_api_key( 'write key', array( 'write' ), 'custom' )['key'];
	}

	private function admin_key( $controller ): string {
		return $controller->create_scoped_api_key( 'admin key', array( 'admin' ), 'admin' )['key'];
	}

	/**
	 * Seed a stored archetype record the way create_product_archetype()/
	 * normalize_product_archetype_payload() would.
	 */
	private function seed_archetype( int $id, array $product_data ): void {
		$GLOBALS['mcpwp_test_options'][ Mcpwp_REST_WooCommerce::PRODUCT_ARCHETYPES_OPTION_KEY ] = array(
			array(
				'id'              => $id,
				'name'            => 'Test archetype',
				'archetype_class' => '',
				'archetype_style' => '',
				'brief'           => '',
				'product_type'    => 'simple',
				'product_data'    => $product_data,
				'created_gmt'     => '2026-01-01 00:00:00',
				'updated_gmt'     => '2026-01-01 00:00:00',
			),
		);
	}

	// ── Explicit status override in the request ─────────────────────────────

	public function test_write_key_cannot_apply_an_archetype_with_an_explicit_publish_override(): void {
		$controller = $this->controller();
		$key        = $this->write_key( $controller );
		$this->seed_archetype( 1, array( 'name' => 'Widget', 'status' => 'draft' ) );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/woocommerce/archetypes/1/apply',
			array(
				'id'     => 1,
				'status' => 'publish',
			),
			array( 'X-API-Key' => $key )
		);

		$result = $controller->verify_api_key( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
	}

	public function test_write_key_can_apply_an_archetype_with_an_explicit_draft_override(): void {
		$controller = $this->controller();
		$key        = $this->write_key( $controller );
		$this->seed_archetype( 1, array( 'name' => 'Widget', 'status' => 'publish' ) );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/woocommerce/archetypes/1/apply',
			array(
				'id'     => 1,
				'status' => 'draft',
			),
			array( 'X-API-Key' => $key )
		);

		$this->assertTrue(
			$controller->verify_api_key( $request ),
			'An explicit draft override must win over a publish-defaulting archetype.'
		);
	}

	/**
	 * The finding that matters most: the archetype's OWN stored data already
	 * says 'publish' — the caller sends NO status-related param at all.
	 */
	public function test_write_key_cannot_apply_a_publish_defaulting_archetype_with_zero_status_params(): void {
		$controller = $this->controller();
		$key        = $this->write_key( $controller );
		$this->seed_archetype( 1, array( 'name' => 'Widget', 'status' => 'publish' ) );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/woocommerce/archetypes/1/apply',
			array( 'id' => 1 ),
			array( 'X-API-Key' => $key )
		);

		$result = $controller->verify_api_key( $request );
		$this->assertInstanceOf(
			WP_Error::class,
			$result,
			'The archetype template itself defaults to publish — applying it with no override at all must still require admin scope.'
		);
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
	}

	public function test_write_key_can_apply_a_draft_defaulting_archetype_with_zero_status_params(): void {
		$controller = $this->controller();
		$key        = $this->write_key( $controller );
		$this->seed_archetype( 1, array( 'name' => 'Widget', 'status' => 'draft' ) );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/woocommerce/archetypes/1/apply',
			array( 'id' => 1 ),
			array( 'X-API-Key' => $key )
		);

		$this->assertTrue( $controller->verify_api_key( $request ) );
	}

	public function test_write_key_can_apply_an_archetype_with_no_stored_status_and_no_override(): void {
		$controller = $this->controller();
		$key        = $this->write_key( $controller );
		$this->seed_archetype( 1, array( 'name' => 'Widget' ) );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/woocommerce/archetypes/1/apply',
			array( 'id' => 1 ),
			array( 'X-API-Key' => $key )
		);

		$this->assertTrue(
			$controller->verify_api_key( $request ),
			"apply_product_archetype() itself defaults an empty status to 'draft' in create mode — nothing here should require admin scope."
		);
	}

	public function test_admin_key_can_apply_a_publish_defaulting_archetype(): void {
		$controller = $this->controller();
		$key        = $this->admin_key( $controller );
		$this->seed_archetype( 1, array( 'name' => 'Widget', 'status' => 'publish' ) );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/woocommerce/archetypes/1/apply',
			array( 'id' => 1 ),
			array( 'X-API-Key' => $key )
		);

		$this->assertTrue( $controller->verify_api_key( $request ) );
	}

	// ── WARN 3: 'private' must be treated the same as 'publish' ────────────

	public function test_write_key_cannot_apply_an_archetype_to_private_status(): void {
		$controller = $this->controller();
		$key        = $this->write_key( $controller );
		$this->seed_archetype( 1, array( 'name' => 'Widget', 'status' => 'draft' ) );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/woocommerce/archetypes/1/apply',
			array(
				'id'     => 1,
				'status' => 'private',
			),
			array( 'X-API-Key' => $key )
		);

		$result = $controller->verify_api_key( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
	}

	// ── fix/3121-future-status-coercion: 'future' must be treated the same
	// as 'publish'/'private' — WordPress coerces it there at write time
	// whenever the post's date is not itself in the future, and this
	// integration exposes no way to set a product's date. ───────────────

	public function test_write_key_cannot_apply_an_archetype_with_an_explicit_future_override(): void {
		$controller = $this->controller();
		$key        = $this->write_key( $controller );
		$this->seed_archetype( 1, array( 'name' => 'Widget', 'status' => 'draft' ) );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/woocommerce/archetypes/1/apply',
			array(
				'id'     => 1,
				'status' => 'future',
			),
			array( 'X-API-Key' => $key )
		);

		$result = $controller->verify_api_key( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
	}

	/**
	 * The finding that matters most, for 'future': the archetype's OWN
	 * stored data already says 'future' — the caller sends NO status-related
	 * param at all. This is exactly the shape a pre-fix archetype record (or
	 * any future bug in the defining route's own schema) has.
	 */
	public function test_write_key_cannot_apply_a_future_defaulting_archetype_with_zero_status_params(): void {
		$controller = $this->controller();
		$key        = $this->write_key( $controller );
		$this->seed_archetype( 1, array( 'name' => 'Widget', 'status' => 'future' ) );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/woocommerce/archetypes/1/apply',
			array( 'id' => 1 ),
			array( 'X-API-Key' => $key )
		);

		$result = $controller->verify_api_key( $request );
		$this->assertInstanceOf(
			WP_Error::class,
			$result,
			"The archetype template itself defaults to 'future' — applying it with no override at all must still " .
			'require admin scope, the same as an explicit \'publish\'.'
		);
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
	}

	public function test_admin_key_can_apply_a_future_defaulting_archetype(): void {
		$controller = $this->controller();
		$key        = $this->admin_key( $controller );
		$this->seed_archetype( 1, array( 'name' => 'Widget', 'status' => 'future' ) );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/woocommerce/archetypes/1/apply',
			array( 'id' => 1 ),
			array( 'X-API-Key' => $key )
		);

		$this->assertTrue( $controller->verify_api_key( $request ) );
	}

	// ── The existing-product authorization boundary itself: no test above
	// this point ever calls verify_api_key() with 'product_id' set, so the
	// #878 relaxation -- a write-scoped key now gets through here for a
	// case create mode still refuses -- was never pinned at the layer that
	// actually enforces it. Pin it deliberately in both directions. ───────

	public function test_write_key_can_apply_a_publish_defaulting_archetype_to_an_existing_product_with_no_override(): void {
		$controller = $this->controller();
		$key        = $this->write_key( $controller );
		$this->seed_archetype( 1, array( 'name' => 'Widget', 'status' => 'publish' ) );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/woocommerce/archetypes/1/apply',
			array(
				'id'         => 1,
				'product_id' => 42,
			),
			array( 'X-API-Key' => $key )
		);

		$this->assertTrue(
			$controller->verify_api_key( $request ),
			'#878: applying to an EXISTING product strips the stored status when the request carries no override at all — a write key must be let through even though the archetype defaults to publish.'
		);
	}

	public function test_write_key_cannot_apply_a_publish_defaulting_archetype_to_an_existing_product_with_explicit_publish_override(): void {
		$controller = $this->controller();
		$key        = $this->write_key( $controller );
		$this->seed_archetype( 1, array( 'name' => 'Widget', 'status' => 'publish' ) );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/woocommerce/archetypes/1/apply',
			array(
				'id'         => 1,
				'product_id' => 42,
				'status'     => 'publish',
			),
			array( 'X-API-Key' => $key )
		);

		$result = $controller->verify_api_key( $request );
		$this->assertInstanceOf(
			WP_Error::class,
			$result,
			'The strip only drops the ARCHETYPE\'s stored status; an explicit override in the request is never stripped and must still require admin scope.'
		);
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
	}

	public function test_write_key_cannot_apply_a_publish_defaulting_archetype_in_create_mode_with_no_override(): void {
		$controller = $this->controller();
		$key        = $this->write_key( $controller );
		$this->seed_archetype( 1, array( 'name' => 'Widget', 'status' => 'publish' ) );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/woocommerce/archetypes/1/apply',
			array( 'id' => 1 ),
			array( 'X-API-Key' => $key )
		);

		$result = $controller->verify_api_key( $request );
		$this->assertInstanceOf(
			WP_Error::class,
			$result,
			'The strip is conditioned on product_id being present. With no product_id (create mode), the same stored publish status must still require admin scope — the direct contrast to the case above.'
		);
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
	}

	public function test_write_key_can_apply_a_private_defaulting_archetype_to_an_existing_product_with_no_override(): void {
		$controller = $this->controller();
		$key        = $this->write_key( $controller );
		$this->seed_archetype( 1, array( 'name' => 'Widget', 'status' => 'private' ) );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/woocommerce/archetypes/1/apply',
			array(
				'id'         => 1,
				'product_id' => 42,
			),
			array( 'X-API-Key' => $key )
		);

		$this->assertTrue(
			$controller->verify_api_key( $request ),
			"The strip logic applies to a stored 'private' status the same as 'publish' on the existing-product path — confirmed, not assumed."
		);
	}

	public function test_write_key_can_apply_a_future_defaulting_archetype_to_an_existing_product_with_no_override(): void {
		$controller = $this->controller();
		$key        = $this->write_key( $controller );
		$this->seed_archetype( 1, array( 'name' => 'Widget', 'status' => 'future' ) );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/woocommerce/archetypes/1/apply',
			array(
				'id'         => 1,
				'product_id' => 42,
			),
			array( 'X-API-Key' => $key )
		);

		$this->assertTrue(
			$controller->verify_api_key( $request ),
			"The strip logic applies to a stored 'future' status the same as 'publish' on the existing-product path — confirmed, not assumed."
		);
	}

	// ── Unrelated archetype-definition CRUD must not require admin scope ───

	public function test_write_key_can_still_create_an_archetype_definition_whose_stored_status_is_publish(): void {
		$controller = $this->controller();
		$key        = $this->write_key( $controller );

		// Defining a template is inert — it only ever update_option()s a
		// record and never calls wp_insert_post()/wp_update_post(). Only the
		// /apply route may set a real product's status.
		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/woocommerce/archetypes',
			array(
				'name'   => 'Widget archetype',
				'status' => 'publish',
			),
			array( 'X-API-Key' => $key )
		);

		$this->assertTrue( $controller->verify_api_key( $request ) );
	}

	public function test_write_key_can_still_update_an_archetype_definitions_stored_status(): void {
		$controller = $this->controller();
		$key        = $this->write_key( $controller );
		$this->seed_archetype( 1, array( 'name' => 'Widget', 'status' => 'draft' ) );

		$request = new WP_REST_Request(
			'PUT',
			'/mcpwp/v1/woocommerce/archetypes/1',
			array(
				'id'     => 1,
				'status' => 'publish',
			),
			array( 'X-API-Key' => $key )
		);

		$this->assertTrue( $controller->verify_api_key( $request ) );
	}

	public function test_write_key_can_still_get_an_archetype_definition(): void {
		$controller = $this->controller();
		$key        = $this->write_key( $controller );
		$this->seed_archetype( 1, array( 'name' => 'Widget', 'status' => 'publish' ) );

		$request = new WP_REST_Request(
			'GET',
			'/mcpwp/v1/woocommerce/archetypes/1',
			array( 'id' => 1 ),
			array( 'X-API-Key' => $key )
		);

		$this->assertTrue( $controller->verify_api_key( $request ) );
	}
}
