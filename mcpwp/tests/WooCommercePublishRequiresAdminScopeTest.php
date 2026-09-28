<?php

use PHPUnit\Framework\TestCase;

/**
 * #607 invariant 3, sibling surface — WooCommerce products.
 *
 * Same root cause as PublishRequiresAdminScopeTest.php (posts/pages): every
 * API key authenticates as the same shared mcpwp_api_agent WP user, so a
 * WordPress capability check cannot gate publishing; the key's scope tier is
 * the only real boundary. wc_create_product's own arg schema
 * (Mcpwp_REST_WooCommerce::get_product_create_args()) defaults 'status' to
 * 'publish' — meaning before this fix, a bare create call with NO status
 * param at all, on a plain write-scoped key, published a live storefront
 * listing. This is worse than the posts/pages case (which defaults to
 * 'draft') and is fixed the same way: setting status=publish now requires
 * admin scope.
 *
 * Also locks in that PUT /woocommerce/products/{id} — which previously
 * registered NO 'args' schema at all, so WordPress validated nothing on that
 * route — now declares one (get_product_update_args()), including the
 * 'status' enum.
 *
 * @package MCPWP
 */

require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-woocommerce.php';

final class WooCommercePublishRequiresAdminScopeTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']            = array();
		$GLOBALS['mcpwp_test_transients']         = array();
		$GLOBALS['mcpwp_test_current_user']       = 0;
		$GLOBALS['mcpwp_test_is_admin']           = false;
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		// The ids these requests name must exist: since #1030 round-5 the
		// declared-post-ref guard answers a missing id itself, before scope.
		$GLOBALS['_mcpwp_test_posts'] = array(
			7 => new WP_Post( array( 'ID' => 7, 'post_type' => 'product', 'post_status' => 'publish' ) ),
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

	public function test_write_key_cannot_create_a_published_product_explicitly(): void {
		$controller = $this->controller();
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/woocommerce/products',
			array(
				'name'   => 'Widget',
				'status' => 'publish',
			),
			array( 'X-API-Key' => $key )
		);

		$result = $controller->verify_api_key( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
	}

	/**
	 * The dangerous default: the route's own schema defaults 'status' to
	 * 'publish' (get_product_create_args()). This test simulates what real
	 * WordPress REST dispatch does — merge schema defaults into the
	 * request's params BEFORE the permission_callback runs (WP_REST_Server::
	 * dispatch() calls $request->set_default_params() ahead of the
	 * permission check) — since this suite's WP_REST_Request stub does not
	 * do that merging itself.
	 */
	public function test_write_key_cannot_create_a_product_with_no_status_param_because_the_route_default_is_publish(): void {
		$controller = $this->controller();
		$key        = $this->write_key( $controller );

		// Simulate WordPress applying the route's declared default before
		// permission_callback runs.
		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/woocommerce/products',
			array( 'name' => 'Widget', 'status' => 'publish' ), // the real default WP would apply
			array( 'X-API-Key' => $key )
		);

		$result = $controller->verify_api_key( $request );
		$this->assertInstanceOf(
			WP_Error::class,
			$result,
			'wc_create_product defaults status to publish — a write key must not be able to reach that default.'
		);
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
	}

	public function test_write_key_can_create_an_explicit_draft_product(): void {
		$controller = $this->controller();
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/woocommerce/products',
			array(
				'name'   => 'Widget',
				'status' => 'draft',
			),
			array( 'X-API-Key' => $key )
		);

		$this->assertTrue( $controller->verify_api_key( $request ) );
	}

	public function test_admin_key_can_create_a_published_product(): void {
		$controller = $this->controller();
		$key        = $this->admin_key( $controller );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/woocommerce/products',
			array(
				'name'   => 'Widget',
				'status' => 'publish',
			),
			array( 'X-API-Key' => $key )
		);

		$this->assertTrue( $controller->verify_api_key( $request ) );
	}

	public function test_write_key_cannot_update_a_product_to_published(): void {
		$controller = $this->controller();
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'PUT',
			'/mcpwp/v1/woocommerce/products/7',
			array(
				'id'     => 7,
				'status' => 'publish',
			),
			array( 'X-API-Key' => $key )
		);

		$result = $controller->verify_api_key( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
	}

	public function test_write_key_can_still_update_a_products_price(): void {
		$controller = $this->controller();
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'PUT',
			'/mcpwp/v1/woocommerce/products/7',
			array(
				'id'            => 7,
				'regular_price' => '19.99',
			),
			array( 'X-API-Key' => $key )
		);

		$this->assertTrue( $controller->verify_api_key( $request ) );
	}

	public function test_update_route_now_declares_an_args_schema_with_a_status_enum(): void {
		$controller = $this->controller();
		$controller->register_routes();

		$registered = null;
		foreach ( $GLOBALS['_mcpwp_test_registered_routes'] as $entry ) {
			if ( 'mcpwp/v1' === $entry['namespace'] && '/woocommerce/products/(?P<id>\d+)' === $entry['route'] ) {
				$registered = $entry;
				break;
			}
		}
		$this->assertNotNull( $registered, 'Expected /woocommerce/products/{id} to be registered.' );

		$editable = null;
		foreach ( $registered['args'] as $handler_def ) {
			if ( is_array( $handler_def['methods'] ) ? in_array( 'PUT', $handler_def['methods'], true ) : false !== strpos( (string) $handler_def['methods'], 'PUT' ) ) {
				$editable = $handler_def;
				break;
			}
		}
		$this->assertNotNull( $editable, 'Expected an EDITABLE (PUT) handler on /woocommerce/products/{id}.' );
		$this->assertArrayHasKey(
			'args',
			$editable,
			'#607: the update handler previously had no args schema at all, so WordPress validated nothing on this route.'
		);
		$this->assertArrayHasKey( 'status', $editable['args'] );
		$this->assertSame( array( 'publish', 'draft', 'pending', 'private' ), $editable['args']['status']['enum'] );
		// Unlike create, update must not force a default onto a field the
		// caller did not mention.
		$this->assertArrayNotHasKey( 'default', $editable['args']['status'] );
	}

	// ── WARN 3 (RC 3.12.1 adversarial review): 'private' is publishing too ──

	public function test_write_key_cannot_create_a_private_product(): void {
		$controller = $this->controller();
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/woocommerce/products',
			array(
				'name'   => 'Widget',
				'status' => 'private',
			),
			array( 'X-API-Key' => $key )
		);

		$result = $controller->verify_api_key( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
	}

	public function test_write_key_cannot_update_a_product_to_private(): void {
		$controller = $this->controller();
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'PUT',
			'/mcpwp/v1/woocommerce/products/7',
			array(
				'id'     => 7,
				'status' => 'private',
			),
			array( 'X-API-Key' => $key )
		);

		$result = $controller->verify_api_key( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
	}

	// ── Unrelated sub-route must not be swept in by the prefix match ─────────

	public function test_write_key_can_still_create_a_product_category_no_status_field_involved(): void {
		$controller = $this->controller();
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/woocommerce/products/categories',
			array( 'name' => 'Widgets' ),
			array( 'X-API-Key' => $key )
		);

		$this->assertTrue( $controller->verify_api_key( $request ) );
	}
}
