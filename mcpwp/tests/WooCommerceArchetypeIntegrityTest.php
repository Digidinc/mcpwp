<?php

use PHPUnit\Framework\TestCase;

/**
 * #879 — PUT /woocommerce/archetypes/{id} data-integrity fix.
 *
 * update_product_archetype() rebuilt the archetype's 'product_data' from a
 * merge of the stored record and the request's flat params. Stored
 * product-field overrides (status, sku, stock_quantity, ...) live NESTED
 * under 'product_data', not at the top level, and
 * normalize_product_archetype_payload() rebuilds 'product_data' by reading
 * only TOP-LEVEL keys of whatever it is handed. So a caller sending one
 * field ({"regular_price":"25.00"}) blew away every other stored product
 * field the extractor never saw. update_product_archetype() now flattens
 * the stored product_data into the merge base before merging the request's
 * params on top of it — see that method's and
 * normalize_product_archetype_payload()'s docblocks in
 * includes/pro/api/class-mcpwp-rest-woocommerce.php.
 *
 * @package MCPWP
 */

require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-woocommerce.php';

final class WooCommerceArchetypeIntegrityTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']            = array();
		$GLOBALS['mcpwp_test_transients']         = array();
		$GLOBALS['mcpwp_test_current_user']       = 0;
		$GLOBALS['mcpwp_test_is_admin']           = false;
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		if ( method_exists( 'Mcpwp_REST_API', 'reset_verdict_memo' ) ) {
			Mcpwp_REST_API::reset_verdict_memo();
		}
		if ( ! function_exists( 'rest_ensure_response' ) ) {
			function rest_ensure_response( $response ) {
				return $response instanceof WP_REST_Response ? $response : new WP_REST_Response( $response );
			}
		}
	}

	/**
	 * Seed a stored archetype record the way create_product_archetype()/
	 * normalize_product_archetype_payload() would — writing straight into
	 * the option, bypassing the handler entirely.
	 */
	private function seed_archetype( int $id, array $product_data, string $name = 'Test archetype' ): void {
		$GLOBALS['mcpwp_test_options'][ Mcpwp_REST_WooCommerce::PRODUCT_ARCHETYPES_OPTION_KEY ] = array(
			array(
				'id'              => $id,
				'name'            => $name,
				'archetype_class' => '',
				'archetype_style' => '',
				'brief'           => '',
				'product_type'    => isset( $product_data['type'] ) ? $product_data['type'] : 'simple',
				'product_data'    => $product_data,
				'created_gmt'     => '2026-01-01 00:00:00',
				'updated_gmt'     => '2026-01-01 00:00:00',
			),
		);
	}

	// ── #879: PUT keeps unsent product_data fields ──────────────────────────

	/**
	 * The #879 repro from the issue: an archetype holding a full product
	 * template, PUT with exactly one field. Every other stored field must
	 * survive, asserted field by field.
	 */
	public function test_put_single_field_leaves_every_other_stored_field_intact(): void {
		$controller = new Mcpwp_REST_WooCommerce( null );
		$this->seed_archetype(
			1,
			array(
				'name'           => 'Widget',
				'type'           => 'simple',
				'status'         => 'draft',
				'sku'            => 'WID-001',
				'regular_price'  => '19.99',
				'stock_quantity' => 5,
			),
			'Widget'
		);

		$request = new WP_REST_Request(
			'PUT',
			'/mcpwp/v1/woocommerce/archetypes/1',
			array(
				'id'            => 1,
				'regular_price' => '25.00',
			)
		);

		$response = $controller->update_product_archetype( $request );
		$this->assertNotInstanceOf( WP_Error::class, $response );

		$data = $response->get_data();

		$this->assertSame( '25.00', $data['product_data']['regular_price'], 'The sent field must apply.' );
		$this->assertSame( 'draft', $data['product_data']['status'], 'Unsent status must survive the PUT.' );
		$this->assertSame( 'WID-001', $data['product_data']['sku'], 'Unsent sku must survive the PUT.' );
		$this->assertSame( 5, $data['product_data']['stock_quantity'], 'Unsent stock_quantity must survive the PUT.' );
		$this->assertSame( 'simple', $data['product_data']['type'], 'Unsent type must survive the PUT.' );
		$this->assertSame( 'Widget', $data['product_data']['name'], 'Unsent product_data name must survive the PUT.' );
		$this->assertSame( 'Widget', $data['name'], 'The archetype label must be unaffected by an unrelated field update.' );
	}

	/**
	 * A same-shaped regression: an unrelated field update must never leak
	 * into, or otherwise disturb, product_data['name'] — the field the
	 * issue specifically flagged as suspicious (it is tied to the
	 * archetype's own 'name' by design, see
	 * normalize_product_archetype_payload()'s docblock, but that tie must
	 * only ever move when the CALLER sends 'name', never as a side effect
	 * of some other field's update).
	 */
	public function test_put_unrelated_field_update_does_not_change_product_data_name(): void {
		$controller = new Mcpwp_REST_WooCommerce( null );
		$this->seed_archetype(
			2,
			array(
				'name'   => 'Starter Kit Archetype',
				'status' => 'draft',
				'sku'    => 'KIT-1',
			),
			'Starter Kit Archetype'
		);

		$request = new WP_REST_Request(
			'PUT',
			'/mcpwp/v1/woocommerce/archetypes/2',
			array(
				'id'  => 2,
				'sku' => 'KIT-2',
			)
		);

		$response = $controller->update_product_archetype( $request );
		$data     = $response->get_data();

		$this->assertSame( 'Starter Kit Archetype', $data['product_data']['name'] );
		$this->assertSame( 'Starter Kit Archetype', $data['name'] );
		$this->assertSame( 'KIT-2', $data['product_data']['sku'] );
	}

	/**
	 * A PUT that DOES send 'name' still moves both — the archetype label and
	 * product_data['name'] are deliberately tied (see
	 * normalize_product_archetype_payload()'s docblock). This is not a bug;
	 * it pins the intended behavior so a future attempt to "fix" the tie by
	 * only updating one of the two is caught.
	 */
	public function test_put_explicit_name_change_updates_both_the_label_and_product_data_name(): void {
		$controller = new Mcpwp_REST_WooCommerce( null );
		$this->seed_archetype( 3, array( 'name' => 'Widget', 'status' => 'draft' ), 'Widget' );

		$request = new WP_REST_Request(
			'PUT',
			'/mcpwp/v1/woocommerce/archetypes/3',
			array(
				'id'   => 3,
				'name' => 'Deluxe Widget',
			)
		);

		$response = $controller->update_product_archetype( $request );
		$data     = $response->get_data();

		$this->assertSame( 'Deluxe Widget', $data['name'] );
		$this->assertSame( 'Deluxe Widget', $data['product_data']['name'] );
		$this->assertSame( 'draft', $data['product_data']['status'], 'Unsent status must still survive alongside a name change.' );
	}
}
