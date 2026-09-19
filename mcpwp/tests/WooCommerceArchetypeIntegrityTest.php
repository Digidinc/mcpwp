<?php

use PHPUnit\Framework\TestCase;

/**
 * #878 / #879 — WooCommerce product archetype data-integrity fixes.
 *
 * #879: PUT /woocommerce/archetypes/{id} rebuilt the archetype's
 * 'product_data' from a merge of the stored record and the request's flat
 * params. Stored product-field overrides (status, sku, stock_quantity, ...)
 * live NESTED under 'product_data', not at the top level, and
 * normalize_product_archetype_payload() rebuilds 'product_data' by reading
 * only TOP-LEVEL keys of whatever it is handed. So a caller sending one
 * field ({"regular_price":"25.00"}) blew away every other stored product
 * field the extractor never saw. update_product_archetype() now flattens
 * the stored product_data into the merge base before merging the request's
 * params on top of it — see that method's and
 * normalize_product_archetype_payload()'s docblocks in
 * includes/pro/api/class-mcpwp-rest-woocommerce.php.
 *
 * #878: apply_product_archetype() merged the archetype's WHOLE stored
 * product_data over an EXISTING product unconditionally, including
 * 'status'. An archetype legitimately defined with status=draft ("new
 * products made from this template start unpublished") silently took a
 * live published product offline the moment it was applied to change one
 * unrelated field, with 200 OK. The fix drops the archetype's stored
 * 'status' on the existing-product path unless the request itself
 * overrides 'status'; create mode is unaffected and keeps applying the
 * stored status (or its 'draft' default).
 *
 * archetype_apply_targets_publish_status() — the admin-scope
 * authorization gate on this same /apply route (see
 * WooCommerceArchetypeApplyRequiresAdminScopeTest.php) had its OWN,
 * separate copy of the old (pre-#878-fix) merge with no status-strip. Left
 * alone, the #878 fix would have made the gate and the handler disagree:
 * the gate would still demand admin scope for a case the handler no longer
 * publishes. Both now compute their effective payload through the ONE
 * shared build_archetype_apply_payload() helper, so they cannot drift
 * apart again — see that helper's docblock. The parity-matrix test at the
 * bottom of this file pins that agreement directly.
 *
 * IMPORTANT ordering note from live-rig reproduction (coordinator finding,
 * 2026-09-19): the two bugs MASK each other on a real install. #879 quietly
 * destroyed a stored 'status' (and every other stored product field) the
 * first time an archetype ever received a PUT, so an archetype that has
 * been create-then-updated even once has no stored status left for #878 to
 * misapply — #878 only reproduces against an archetype that has NEVER been
 * through the buggy PUT path. The #878 tests below therefore seed their
 * archetype directly into the stored option (bypassing
 * create/update_product_archetype() entirely, the same way
 * WooCommerceArchetypeApplyRequiresAdminScopeTest::seed_archetype() does)
 * and assert the stored status is still present immediately before
 * calling apply, so this suite fails loudly — not vacuously — if a future
 * refactor ever reintroduces that masking interaction. Both fixes must
 * ship together: shipping #879 alone, without #878, re-exposes #878 on
 * every archetype that predates the #879 fix's first PUT.
 *
 * This file uses a fake WooCommerce handler double (below) because these
 * tests exercise update_product_archetype()/apply_product_archetype()
 * themselves, not just the admin-scope gate — unlike
 * WooCommerceArchetypeApplyRequiresAdminScopeTest.php, which deliberately
 * never reaches the handler and so is safe to construct with a null one.
 *
 * @package MCPWP
 */

require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-woocommerce.php';

/**
 * Minimal WooCommerce handler double.
 *
 * Mirrors the one behavior these tests depend on from the real
 * Mcpwp_WooCommerce::set_product_data(): a key ABSENT from the data array
 * leaves that product field unchanged; a key PRESENT (even falsy)
 * overwrites it. That is what makes "the archetype's stored status was
 * stripped from the payload" observable as "the product's status did not
 * change", rather than only an assertion on the payload's shape.
 */
class Mcpwp_Test_Fake_Woocommerce_Handler {

	/**
	 * Every update_product() call received, in order: array of {id, data}.
	 *
	 * @var array
	 */
	public $update_calls = array();

	/**
	 * Every create_product() call received, in order: array of data arrays.
	 *
	 * @var array
	 */
	public $create_calls = array();

	/**
	 * Fake product store, keyed by product ID.
	 *
	 * @var array
	 */
	private $products;

	/**
	 * @param array $products Seed products, keyed by ID.
	 */
	public function __construct( array $products = array() ) {
		$this->products = $products;
	}

	/**
	 * @param int   $id   Product ID.
	 * @param array $data Fields to apply — absent keys leave that field untouched.
	 * @return array|WP_Error
	 */
	public function update_product( $id, $data ) {
		$this->update_calls[] = array(
			'id'   => $id,
			'data' => $data,
		);

		if ( ! isset( $this->products[ $id ] ) ) {
			return new WP_Error( 'not_found', 'Product not found.' );
		}

		foreach ( $data as $key => $value ) {
			$this->products[ $id ][ $key ] = $value;
		}

		return $this->products[ $id ];
	}

	/**
	 * @param array $data Fields for the new product.
	 * @return array
	 */
	public function create_product( $data ) {
		$this->create_calls[] = $data;

		$id                    = 999;
		$this->products[ $id ] = $data;

		return array_merge( array( 'id' => $id ), $data );
	}

	/**
	 * @param int $id Product ID.
	 * @return string|null
	 */
	public function product_status( $id ) {
		return isset( $this->products[ $id ]['status'] ) ? $this->products[ $id ]['status'] : null;
	}
}

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
	 * the option, bypassing the handler entirely. This is deliberate for
	 * the #878 tests: it guarantees a "pristine" archetype whose stored
	 * product_data has never passed through update_product_archetype(),
	 * which is the only way to reproduce #878 independently of #879 (see
	 * the file-level docblock).
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
	 * A same-shaped check: with the archetype's label and its stored
	 * product_data['name'] EQUAL (the ordinary case — they always start
	 * that way via create_product_archetype()), an unrelated field update
	 * must not touch either.
	 *
	 * This does NOT, on its own, guard against the #879 "clobbers name"
	 * defect: when the two values are equal by construction, the old buggy
	 * merge produced the same value anyway, so this test still passes even
	 * with that fix fully reverted. For the test that actually fails
	 * without the fix, see
	 * test_put_unrelated_field_preserves_a_stored_product_name_that_diverges_from_the_archetype_label()
	 * below, which seeds a genuinely divergent name and label.
	 */
	public function test_put_unrelated_field_update_leaves_equal_name_and_label_unaffected(): void {
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
	 * The actual #879 "clobbers name" regression: the archetype's stored
	 * product_data['name'] DIVERGES from its own label (only possible today
	 * by writing the option directly, e.g. via seed_archetype() here — see
	 * normalize_product_archetype_payload()'s docblock). A PUT that never
	 * mentions 'name' at all must preserve the stored product name exactly,
	 * and must not touch the label either.
	 *
	 * This is the test that fails without the guard in
	 * update_product_archetype(): without it, the merge re-derives
	 * product_data['name'] from the archetype's label ('Different Archetype
	 * Label') and the stored 'Divergent Product Name' is lost.
	 */
	public function test_put_unrelated_field_preserves_a_stored_product_name_that_diverges_from_the_archetype_label(): void {
		$controller = new Mcpwp_REST_WooCommerce( null );
		$this->seed_archetype(
			4,
			array(
				'name'   => 'Divergent Product Name',
				'status' => 'draft',
				'sku'    => 'DIV-1',
			),
			'Different Archetype Label'
		);

		$request = new WP_REST_Request(
			'PUT',
			'/mcpwp/v1/woocommerce/archetypes/4',
			array(
				'id'  => 4,
				'sku' => 'DIV-2',
			)
		);

		$response = $controller->update_product_archetype( $request );
		$data     = $response->get_data();

		$this->assertSame(
			'Divergent Product Name',
			$data['product_data']['name'],
			"#879: a PUT that never mentioned 'name' must not rewrite a stored product name that diverges from the archetype's own label."
		);
		$this->assertSame(
			'Different Archetype Label',
			$data['name'],
			'The archetype label itself must also be unaffected by an unrelated field update.'
		);
		$this->assertSame( 'DIV-2', $data['product_data']['sku'] );
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

	// ── #878: applying to an existing product must not touch status unless
	// the caller asks ────────────────────────────────────────────────────

	/**
	 * The #878 regression itself: a draft-storing archetype applied to an
	 * existing PUBLISHED product must leave it published.
	 */
	public function test_applying_draft_archetype_to_existing_published_product_leaves_it_published(): void {
		$this->seed_archetype( 10, array( 'name' => 'Widget', 'status' => 'draft', 'type' => 'simple' ) );

		// Guard against the masking interaction with #879 (see file
		// docblock): fail loudly, not vacuously, if the stored status is
		// ever missing at the moment of apply.
		$stored_items = $GLOBALS['mcpwp_test_options'][ Mcpwp_REST_WooCommerce::PRODUCT_ARCHETYPES_OPTION_KEY ];
		$this->assertSame(
			'draft',
			$stored_items[0]['product_data']['status'],
			'Precondition failed: the archetype must still carry a stored status at apply time, or this test cannot exercise #878 at all.'
		);

		$handler    = new Mcpwp_Test_Fake_Woocommerce_Handler( array( 42 => array( 'status' => 'publish', 'name' => 'Live Widget' ) ) );
		$controller = new Mcpwp_REST_WooCommerce( $handler );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/woocommerce/archetypes/10/apply',
			array(
				'id'         => 10,
				'product_id' => 42,
			)
		);

		$response = $controller->apply_product_archetype( $request );
		$this->assertNotInstanceOf( WP_Error::class, $response );

		$this->assertSame(
			'publish',
			$handler->product_status( 42 ),
			'Applying a draft-storing archetype must not unpublish an existing live product.'
		);
		$this->assertArrayNotHasKey(
			'status',
			$handler->update_calls[0]['data'],
			"The handler's payload must not carry the archetype's stored status when the caller asked for no status change."
		);
	}

	/**
	 * An explicit status override in the request must still win and apply,
	 * even on the existing-product path.
	 */
	public function test_applying_archetype_with_explicit_status_override_still_applies_it_to_existing_product(): void {
		$this->seed_archetype( 11, array( 'name' => 'Widget', 'status' => 'draft' ) );

		$handler    = new Mcpwp_Test_Fake_Woocommerce_Handler( array( 7 => array( 'status' => 'publish' ) ) );
		$controller = new Mcpwp_REST_WooCommerce( $handler );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/woocommerce/archetypes/11/apply',
			array(
				'id'         => 11,
				'product_id' => 7,
				'status'     => 'pending',
			)
		);

		$response = $controller->apply_product_archetype( $request );
		$this->assertNotInstanceOf( WP_Error::class, $response );

		$this->assertSame( 'pending', $handler->product_status( 7 ) );
		$this->assertSame( 'pending', $handler->update_calls[0]['data']['status'] );
	}

	/**
	 * Applying with no status override, no stored status, and an existing
	 * product must simply not touch status at all (no key in the payload).
	 */
	public function test_applying_archetype_with_no_stored_status_and_no_override_to_existing_product_omits_status(): void {
		$this->seed_archetype( 12, array( 'name' => 'Widget' ) );

		$handler    = new Mcpwp_Test_Fake_Woocommerce_Handler( array( 8 => array( 'status' => 'draft' ) ) );
		$controller = new Mcpwp_REST_WooCommerce( $handler );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/woocommerce/archetypes/12/apply',
			array(
				'id'         => 12,
				'product_id' => 8,
			)
		);

		$controller->apply_product_archetype( $request );

		$this->assertArrayNotHasKey( 'status', $handler->update_calls[0]['data'] );
		$this->assertSame( 'draft', $handler->product_status( 8 ) );
	}

	// ── Create mode is unaffected by the #878 fix ───────────────────────────

	/**
	 * Create mode still applies the archetype's stored status verbatim.
	 */
	public function test_create_mode_still_applies_the_stored_status(): void {
		$this->seed_archetype( 20, array( 'name' => 'Widget', 'status' => 'private' ) );

		$handler    = new Mcpwp_Test_Fake_Woocommerce_Handler();
		$controller = new Mcpwp_REST_WooCommerce( $handler );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/woocommerce/archetypes/20/apply',
			array( 'id' => 20 )
		);

		$response = $controller->apply_product_archetype( $request );
		$data     = $response->get_data();

		$this->assertSame( 'created', $data['mode'] );
		$this->assertSame( 'private', $handler->create_calls[0]['status'] );
	}

	/**
	 * Create mode still defaults an empty/missing stored status to 'draft'.
	 */
	public function test_create_mode_defaults_to_draft_when_archetype_has_no_stored_status(): void {
		$this->seed_archetype( 21, array( 'name' => 'Widget' ) );

		$handler    = new Mcpwp_Test_Fake_Woocommerce_Handler();
		$controller = new Mcpwp_REST_WooCommerce( $handler );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/woocommerce/archetypes/21/apply',
			array( 'id' => 21 )
		);

		$controller->apply_product_archetype( $request );

		$this->assertSame( 'draft', $handler->create_calls[0]['status'] );
	}

	// ── Gate/handler parity matrix ───────────────────────────────────────────

	/**
	 * Pins the invariant the #878 fix depends on: the admin-scope gate
	 * (archetype_apply_targets_publish_status(), a static method invoked
	 * BEFORE any controller exists — see trait-mcpwp-api-auth.php ~L1843)
	 * and the handler (apply_product_archetype()) must always agree on
	 * whether the effective status is 'publish'/'private'/'future', across
	 * every combination of: whether the archetype has a stored status, what
	 * it is, whether the request overrides it, what that override is, and
	 * whether the apply targets an existing product or creates a new one.
	 *
	 * Both are asked to compute through the SAME build_archetype_apply_payload()
	 * helper in production; this test does not call that private helper
	 * directly (it has no reason to be public) but instead drives the two
	 * PUBLIC entry points that use it and compares their real-world
	 * outcomes — which is the actual invariant that matters: a write-scoped
	 * key must never be able to reach, via this route, a status the gate
	 * would have required admin scope for.
	 */
	public function test_gate_verdict_matches_handler_effective_status_across_matrix(): void {
		$stored_statuses    = array( null, 'draft', 'pending', 'publish', 'private', 'future' );
		$override_statuses  = array( null, 'draft', 'pending', 'publish', 'private', 'future' );
		$product_id_present = array( false, true );

		$archetype_id = 500;
		$case_no      = 0;

		foreach ( $stored_statuses as $stored_status ) {
			foreach ( $override_statuses as $override_status ) {
				foreach ( $product_id_present as $has_product_id ) {
					++$case_no;

					$label = sprintf(
						'case #%d: stored=%s override=%s product_id_present=%s',
						$case_no,
						null === $stored_status ? '(none)' : $stored_status,
						null === $override_status ? '(none)' : $override_status,
						$has_product_id ? 'yes' : 'no'
					);

					$product_data = array( 'name' => 'Widget' );
					if ( null !== $stored_status ) {
						$product_data['status'] = $stored_status;
					}
					$this->seed_archetype( $archetype_id, $product_data );

					$params = array( 'id' => $archetype_id );
					if ( $has_product_id ) {
						$params['product_id'] = 55;
					}
					if ( null !== $override_status ) {
						$params['status'] = $override_status;
					}

					$request = new WP_REST_Request(
						'POST',
						"/mcpwp/v1/woocommerce/archetypes/{$archetype_id}/apply",
						$params
					);

					$gate_requires_admin = Mcpwp_REST_WooCommerce::archetype_apply_targets_publish_status( $archetype_id, $request );

					$handler_seed = $has_product_id ? array( 55 => array( 'status' => 'publish' ) ) : array();
					$handler      = new Mcpwp_Test_Fake_Woocommerce_Handler( $handler_seed );
					$controller   = new Mcpwp_REST_WooCommerce( $handler );

					$controller->apply_product_archetype( $request );

					if ( $has_product_id ) {
						$call                     = end( $handler->update_calls );
						$handler_targets_publish  = array_key_exists( 'status', $call['data'] )
							&& in_array( sanitize_key( (string) $call['data']['status'] ), array( 'publish', 'private', 'future' ), true );
					} else {
						$created = end( $handler->create_calls );
						// The create path always sends an explicit 'status'
						// (build_archetype_apply_payload() now defaults an
						// empty one to 'draft' itself) -- assert that rather
						// than silently falling back to a value that can
						// never actually be reached here.
						$this->assertArrayHasKey( 'status', $created, $label . ' — create mode must always send an explicit status.' );
						$handler_targets_publish = in_array( sanitize_key( (string) $created['status'] ), array( 'publish', 'private', 'future' ), true );
					}

					$this->assertSame(
						$handler_targets_publish,
						$gate_requires_admin,
						$label . ' — the gate verdict must match what the handler actually writes.'
					);

					// Fail-closed check: a write-scoped key must never reach
					// a publish/private/future outcome the gate did not
					// flag as admin-only.
					if ( $handler_targets_publish ) {
						$this->assertTrue(
							$gate_requires_admin,
							$label . ' — this case writes a publish/private/future status but the gate would not require admin scope for it.'
						);
					}
				}
			}
		}

		$this->assertSame( 6 * 6 * 2, $case_no, 'Sanity check that the full matrix actually ran.' );
	}
}
