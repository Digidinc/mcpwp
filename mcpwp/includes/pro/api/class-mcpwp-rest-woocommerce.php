<?php
/**
 * WooCommerce REST API Controller
 *
 * @package MCPWP_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce REST controller class.
 */
class Mcpwp_REST_WooCommerce extends Mcpwp_REST_API {
	/**
	 * Option key storing product archetype definitions.
	 *
	 * A class constant (not just the instance property below) so
	 * Mcpwp_Api_Auth::get_required_scope_for_request() — which must resolve a
	 * request's required scope BEFORE any controller is instantiated — can
	 * read the same option via a static call. See
	 * archetype_apply_targets_publish_status().
	 *
	 * @var string
	 */
	const PRODUCT_ARCHETYPES_OPTION_KEY = 'mcpwp_wc_product_archetypes';

	/**
	 * Option key storing product archetype definitions.
	 *
	 * @var string
	 */
	private $product_archetypes_option_key = self::PRODUCT_ARCHETYPES_OPTION_KEY;

	/**
	 * Option key storing next product archetype ID.
	 *
	 * @var string
	 */
	private $product_archetypes_next_id_option_key = 'mcpwp_wc_product_archetypes_next_id';

	/**
	 * WooCommerce handler instance.
	 *
	 * @var Mcpwp_WooCommerce
	 */
	private $handler;

	/**
	 * Constructor.
	 *
	 * @param Mcpwp_WooCommerce $handler Handler instance.
	 */
	public function __construct( $handler ) {
		$this->handler = $handler;
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		// Status.
		register_rest_route(
			$this->namespace,
			'/woocommerce/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Products.
		register_rest_route(
			$this->namespace,
			'/woocommerce/products',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_products' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $this->get_products_args(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_product' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $this->get_product_create_args(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/woocommerce/products/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_product' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_product' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $this->get_product_update_args(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_product' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'force' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Permanently delete the product.', 'mcpwp' ),
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/woocommerce/products/categories',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_product_categories' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_product_category' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $this->get_product_category_args(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/woocommerce/products/categories/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_product_category' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => $this->get_product_category_args(),
			)
		);

		register_rest_route(
			$this->namespace,
			'/woocommerce/products/tags',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_product_tags' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/woocommerce/archetypes',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_product_archetypes' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_product_archetype' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $this->get_archetype_write_args(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/woocommerce/archetypes/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_product_archetype' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_product_archetype' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $this->get_archetype_write_args(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/woocommerce/archetypes/(?P<id>\d+)/apply',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'apply_product_archetype' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => $this->get_archetype_apply_args(),
			)
		);

		// Orders.
		register_rest_route(
			$this->namespace,
			'/woocommerce/orders',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_orders' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => $this->get_orders_args(),
			)
		);

		register_rest_route(
			$this->namespace,
			'/woocommerce/orders/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_order' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_order' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'status' => array(
							'type'        => 'string',
							'description' => __( 'Order status.', 'mcpwp' ),
						),
						'note' => array(
							'type'        => 'string',
							'description' => __( 'Order note to add.', 'mcpwp' ),
						),
						'note_customer' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Send note to customer.', 'mcpwp' ),
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/woocommerce/orders/statuses',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_order_statuses' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Customers.
		register_rest_route(
			$this->namespace,
			'/woocommerce/customers',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_customers' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => $this->get_customers_args(),
			)
		);

		register_rest_route(
			$this->namespace,
			'/woocommerce/customers/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_customer' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Analytics.
		register_rest_route(
			$this->namespace,
			'/woocommerce/analytics',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_analytics' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => $this->get_analytics_args(),
			)
		);
	}

	// =========================================================================
	// Route Arguments
	// =========================================================================

	/**
	 * Get products query arguments.
	 *
	 * @return array
	 */
	private function get_products_args() {
		return array(
			'per_page'     => array(
				'type'        => 'integer',
				'default'     => 50,
				'minimum'     => 1,
				'maximum'     => 100,
				'description' => __( 'Items per page.', 'mcpwp' ),
			),
			'page'         => array(
				'type'        => 'integer',
				'default'     => 1,
				'minimum'     => 1,
				'description' => __( 'Page number.', 'mcpwp' ),
			),
			'status'       => array(
				'type'        => 'string',
				'default'     => 'publish',
				'enum'        => array( 'publish', 'draft', 'pending', 'private', 'any' ),
				'description' => __( 'Product status.', 'mcpwp' ),
			),
			'type'         => array(
				'type'        => 'string',
				'enum'        => array( 'simple', 'variable', 'grouped', 'external' ),
				'description' => __( 'Product type.', 'mcpwp' ),
			),
			'category'     => array(
				'type'        => 'string',
				'description' => __( 'Category slug.', 'mcpwp' ),
			),
			'tag'          => array(
				'type'        => 'string',
				'description' => __( 'Tag slug.', 'mcpwp' ),
			),
			'search'       => array(
				'type'        => 'string',
				'description' => __( 'Search term.', 'mcpwp' ),
			),
			'sku'          => array(
				'type'        => 'string',
				'description' => __( 'Exact SKU match.', 'mcpwp' ),
			),
			'stock_status' => array(
				'type'        => 'string',
				'enum'        => array( 'instock', 'outofstock', 'onbackorder' ),
				'description' => __( 'Stock status.', 'mcpwp' ),
			),
			'orderby'      => array(
				'type'        => 'string',
				'default'     => 'date',
				'enum'        => array( 'date', 'title', 'price', 'popularity', 'rating' ),
				'description' => __( 'Order by field.', 'mcpwp' ),
			),
			'order'        => array(
				'type'        => 'string',
				'default'     => 'DESC',
				'enum'        => array( 'ASC', 'DESC' ),
				'description' => __( 'Sort order.', 'mcpwp' ),
			),
		);
	}

	/**
	 * Get product create arguments.
	 *
	 * @return array
	 */
	private function get_product_create_args() {
		return array(
			'name'              => array(
				'type'        => 'string',
				'required'    => true,
				'description' => __( 'Product name.', 'mcpwp' ),
			),
			'type'              => array(
				'type'        => 'string',
				'default'     => 'simple',
				'enum'        => array( 'simple', 'variable', 'grouped', 'external' ),
				'description' => __( 'Product type.', 'mcpwp' ),
			),
			'status'            => array(
				'type'        => 'string',
				'default'     => 'publish',
				'enum'        => array( 'publish', 'draft', 'pending', 'private' ),
				'description' => __( 'Product status.', 'mcpwp' ),
			),
			'description'       => array(
				'type'        => 'string',
				'description' => __( 'Product description.', 'mcpwp' ),
			),
			'short_description' => array(
				'type'        => 'string',
				'description' => __( 'Short description.', 'mcpwp' ),
			),
			'sku'               => array(
				'type'        => 'string',
				'description' => __( 'Product SKU.', 'mcpwp' ),
			),
			'regular_price'     => array(
				'type'        => 'string',
				'description' => __( 'Regular price.', 'mcpwp' ),
			),
			'sale_price'        => array(
				'type'        => 'string',
				'description' => __( 'Sale price.', 'mcpwp' ),
			),
			'manage_stock'      => array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'Manage stock.', 'mcpwp' ),
			),
			'stock_quantity'    => array(
				'type'        => 'integer',
				'description' => __( 'Stock quantity.', 'mcpwp' ),
			),
			'stock_status'      => array(
				'type'        => 'string',
				'enum'        => array( 'instock', 'outofstock', 'onbackorder' ),
				'description' => __( 'Stock status.', 'mcpwp' ),
			),
			'categories'        => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'description' => __( 'Category names or IDs.', 'mcpwp' ),
			),
			'tags'              => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'description' => __( 'Tag names or IDs.', 'mcpwp' ),
			),
			'image_id'          => array(
				'type'        => 'integer',
				'description' => __( 'Main image attachment ID.', 'mcpwp' ),
			),
			'gallery_image_ids' => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'integer' ),
				'description' => __( 'Gallery image attachment IDs.', 'mcpwp' ),
			),
			'virtual'           => array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'Virtual product.', 'mcpwp' ),
			),
			'downloadable'      => array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'Downloadable product.', 'mcpwp' ),
			),
		);
	}

	/**
	 * Get product UPDATE arguments.
	 *
	 * #607: the update route previously registered no 'args' schema at all —
	 * every field, including 'status', went straight through with no type,
	 * enum, or range check at the WP REST layer (WordPress only validates
	 * against a route's declared arg schema; an omitted schema validates
	 * nothing). Mirrors get_product_create_args() with 'required' and
	 * 'default' stripped — same pattern as
	 * Mcpwp_REST_API::get_post_update_args() for posts/pages.
	 *
	 * @return array
	 */
	private function get_product_update_args() {
		$args = $this->get_product_create_args();
		foreach ( $args as $name => $schema ) {
			unset( $args[ $name ]['required'], $args[ $name ]['default'] );
		}
		return $args;
	}

	/**
	 * Get orders query arguments.
	 *
	 * @return array
	 */
	private function get_orders_args() {
		return array(
			'per_page' => array(
				'type'        => 'integer',
				'default'     => 50,
				'minimum'     => 1,
				'maximum'     => 100,
				'description' => __( 'Items per page.', 'mcpwp' ),
			),
			'page'     => array(
				'type'        => 'integer',
				'default'     => 1,
				'minimum'     => 1,
				'description' => __( 'Page number.', 'mcpwp' ),
			),
			'status'   => array(
				'type'        => 'string',
				'default'     => 'any',
				'description' => __( 'Order status.', 'mcpwp' ),
			),
			'customer' => array(
				'type'        => 'integer',
				'description' => __( 'Customer ID.', 'mcpwp' ),
			),
			'after'    => array(
				'type'        => 'string',
				'format'      => 'date-time',
				'description' => __( 'Orders after date (ISO 8601).', 'mcpwp' ),
			),
			'before'   => array(
				'type'        => 'string',
				'format'      => 'date-time',
				'description' => __( 'Orders before date (ISO 8601).', 'mcpwp' ),
			),
		);
	}

	/**
	 * Get customers query arguments.
	 *
	 * @return array
	 */
	private function get_customers_args() {
		return array(
			'per_page' => array(
				'type'        => 'integer',
				'default'     => 50,
				'minimum'     => 1,
				'maximum'     => 100,
				'description' => __( 'Items per page.', 'mcpwp' ),
			),
			'page'     => array(
				'type'        => 'integer',
				'default'     => 1,
				'minimum'     => 1,
				'description' => __( 'Page number.', 'mcpwp' ),
			),
			'search'   => array(
				'type'        => 'string',
				'description' => __( 'Search term.', 'mcpwp' ),
			),
			'orderby'  => array(
				'type'        => 'string',
				'default'     => 'registered',
				'enum'        => array( 'registered', 'display_name', 'user_login', 'user_email' ),
				'description' => __( 'Order by field.', 'mcpwp' ),
			),
			'order'    => array(
				'type'        => 'string',
				'default'     => 'DESC',
				'enum'        => array( 'ASC', 'DESC' ),
				'description' => __( 'Sort order.', 'mcpwp' ),
			),
		);
	}

	/**
	 * Get product category arguments.
	 *
	 * @return array
	 */
	private function get_product_category_args() {
		return array(
			'name'        => array(
				'type'        => 'string',
				'description' => __( 'Category name.', 'mcpwp' ),
			),
			'slug'        => array(
				'type'        => 'string',
				'description' => __( 'Category slug.', 'mcpwp' ),
			),
			'description' => array(
				'type'        => 'string',
				'description' => __( 'Category description.', 'mcpwp' ),
			),
			'parent'      => array(
				'type'        => 'integer',
				'description' => __( 'Parent category ID.', 'mcpwp' ),
			),
			'image_id'    => array(
				'type'        => 'integer',
				'description' => __( 'Category thumbnail image attachment ID.', 'mcpwp' ),
			),
		);
	}

	/**
	 * Get analytics query arguments.
	 *
	 * @return array
	 */
	private function get_analytics_args() {
		return array(
			'period'   => array(
				'type'        => 'string',
				'default'     => 'month',
				'enum'        => array( 'day', 'week', 'month', 'year' ),
				'description' => __( 'Time period.', 'mcpwp' ),
			),
			'date_min' => array(
				'type'        => 'string',
				'format'      => 'date-time',
				'description' => __( 'Start date (ISO 8601).', 'mcpwp' ),
			),
			'date_max' => array(
				'type'        => 'string',
				'format'      => 'date-time',
				'description' => __( 'End date (ISO 8601).', 'mcpwp' ),
			),
		);
	}

	/**
	 * Get write arguments for POST /woocommerce/archetypes and
	 * PUT /woocommerce/archetypes/{id}.
	 *
	 * fix/3121-future-status-coercion: these two routes registered NO 'args'
	 * at all -- unlike every other WooCommerce write route in this file,
	 * including their own sibling get_archetype_apply_args() below. A caller
	 * could therefore store ANY string, including 'future', as the
	 * archetype's product_data['status'], via normalize_product_archetype_payload()
	 * -> extract_product_override_payload() -> create_product_archetype()/
	 * update_product_archetype() (which do nothing but update_option() the
	 * record, so no WordPress or WooCommerce write API ever saw the value at
	 * definition time to reject it). apply_product_archetype() later merges
	 * that stored value into a real product write with zero further
	 * validation of its own (Mcpwp_WooCommerce::set_product_data(), which
	 * used to hand the raw value straight to the product's status setter --
	 * now allow-listed separately; see that method's docblock for why
	 * 'future' is unconditionally equivalent to 'publish' here). Declaring
	 * 'enum' stops
	 * an invalid value being stored in the first place, the same way it
	 * already stops one being applied directly via
	 * get_archetype_apply_args() and the five routes fixed earlier in this
	 * RC.
	 *
	 * @return array
	 */
	private function get_archetype_write_args() {
		return array(
			'status' => array(
				'type'        => 'string',
				'enum'        => array( 'draft', 'pending', 'private', 'publish' ),
				'description' => __( 'Optional product status to store on the archetype template.', 'mcpwp' ),
			),
		);
	}

	/**
	 * Get write arguments for POST /woocommerce/archetypes/{id}/apply.
	 *
	 * RC 3.12.1 adversarial review, BLOCK 2: this route registered NO 'args'
	 * schema at all — unlike get_product_create_args() above, which already
	 * declares 'enum' on 'status' for POST /woocommerce/products. A
	 * caller-supplied 'status' override here reaches
	 * extract_product_override_payload() -> Mcpwp_WooCommerce::
	 * set_product_data() (sanitize_key( $data['status'] )) with nothing
	 * validating it first, so a padded value like ' publish' could defeat
	 * the publish-scope gate the same way it could on the four sibling
	 * routes this fix closes. Declaring 'enum' here makes WordPress itself
	 * reject an out-of-enum value before either the gate or the handler
	 * ever sees it.
	 *
	 * Deliberately NO 'default' on 'status' here, unlike
	 * get_product_create_args(): this route's override is optional by
	 * design — when the caller omits it, the archetype's OWN stored
	 * product_data status applies (see apply_product_archetype() and
	 * archetype_apply_targets_publish_status() below, which is why THIS
	 * route cannot rely on the plain request_targets_publish_status() check
	 * alone). Injecting a default would silently clobber that stored value
	 * on every apply call that omits an explicit override — a behavior
	 * change this fix is not meant to make.
	 *
	 * @return array
	 */
	private function get_archetype_apply_args() {
		return array(
			'status' => array(
				'type'        => 'string',
				'enum'        => array( 'draft', 'pending', 'private', 'publish' ),
				'description' => __( 'Optional product status override.', 'mcpwp' ),
			),
		);
	}

	// =========================================================================
	// Route Callbacks
	// =========================================================================

	/**
	 * Get WooCommerce status.
	 *
	 * @return WP_REST_Response
	 */
	public function get_status() {
		return rest_ensure_response( $this->handler->get_status() );
	}

	/**
	 * Get products.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_products( $request ) {
		$args = array(
			'per_page'     => $request->get_param( 'per_page' ),
			'page'         => $request->get_param( 'page' ),
			'status'       => $request->get_param( 'status' ),
			'type'         => $request->get_param( 'type' ),
			'category'     => $request->get_param( 'category' ),
			'tag'          => $request->get_param( 'tag' ),
			'search'       => $request->get_param( 'search' ),
			'sku'          => $request->get_param( 'sku' ),
			'stock_status' => $request->get_param( 'stock_status' ),
			'orderby'      => $request->get_param( 'orderby' ),
			'order'        => $request->get_param( 'order' ),
		);

		return rest_ensure_response( $this->handler->get_products( $args ) );
	}

	/**
	 * Get single product.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_product( $request ) {
		$result = $this->handler->get_product( $request->get_param( 'id' ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Create product.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_product( $request ) {
		$data = $request->get_params();

		$result = $this->handler->create_product( $data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Update product.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_product( $request ) {
		$id   = $request->get_param( 'id' );
		$data = $request->get_params();

		$result = $this->handler->update_product( $id, $data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Delete product.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_product( $request ) {
		$id    = absint( $request->get_param( 'id' ) );
		$force = rest_sanitize_boolean( $request->get_param( 'force' ) );

		// Permanently deleting a product is revenue-bearing data with no
		// undo: approval by default (#536 round 2). Only for a product that
		// exists — a deferral is not a way to manufacture records.
		$product = class_exists( 'Mcpwp_Destructive_Gate' ) && Mcpwp_Destructive_Gate::should_gate( $this->get_current_api_key_record() ) ? get_post( $id ) : null;
		if ( $product && Mcpwp_Destructive_Gate::delete_is_permanent( 'product', $force, (string) $product->post_status ) ) {
			if ( 'product' !== $product->post_type ) {
				return $this->error_response( 'not_found', __( 'Product not found.', 'mcpwp' ), 404, array( 'id' => $id ) );
			}
			return $this->defer_destructive( 'delete_product', $request, array( 'type' => 'product', 'id' => $id, 'title' => (string) $product->post_title ), sprintf( 'Permanently delete product #%d', $id ) );
		}

		$result = $this->handler->delete_product( $id, $force );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array(
			'deleted' => true,
			'id'      => $id,
		) );
	}

	/**
	 * Get product categories.
	 *
	 * @return WP_REST_Response
	 */
	public function get_product_categories() {
		return rest_ensure_response( $this->handler->get_product_categories() );
	}

	/**
	 * Create product category.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_product_category( $request ) {
		$data = $request->get_params();

		$result = $this->handler->create_product_category( $data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Update product category.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_product_category( $request ) {
		$id   = $request->get_param( 'id' );
		$data = $request->get_params();

		$result = $this->handler->update_product_category( $id, $data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Get product tags.
	 *
	 * @return WP_REST_Response
	 */
	public function get_product_tags() {
		return rest_ensure_response( $this->handler->get_product_tags() );
	}

	/**
	 * List stored product archetypes.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_product_archetypes( $request ) {
		$items = $this->load_product_archetypes();

		$archetype_class = sanitize_key( (string) $request->get_param( 'archetype_class' ) );
		$product_type    = sanitize_key( (string) $request->get_param( 'product_type' ) );
		$style           = sanitize_text_field( (string) $request->get_param( 'archetype_style' ) );

		$items = array_values(
			array_filter(
				$items,
				function ( $item ) use ( $archetype_class, $product_type, $style ) {
					if ( $archetype_class && $archetype_class !== $item['archetype_class'] ) {
						return false;
					}

					if ( $product_type && $product_type !== $item['product_type'] ) {
						return false;
					}

					if ( $style && $style !== $item['archetype_style'] ) {
						return false;
					}

					return true;
				}
			)
		);

		return rest_ensure_response(
			array(
				'archetypes' => $items,
				'total'      => count( $items ),
			)
		);
	}

	/**
	 * Get a single product archetype.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_product_archetype( $request ) {
		$item = $this->get_product_archetype_by_id( absint( $request->get_param( 'id' ) ) );
		if ( is_wp_error( $item ) ) {
			return $item;
		}

		return rest_ensure_response( $item );
	}

	/**
	 * Create a product archetype.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_product_archetype( $request ) {
		$data = $this->normalize_product_archetype_payload( $request->get_params() );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$id                 = (int) get_option( $this->product_archetypes_next_id_option_key, 1 );
		$data['id']         = $id;
		$data['created_gmt'] = current_time( 'mysql', true );
		$data['updated_gmt'] = $data['created_gmt'];

		$items   = $this->load_product_archetypes();
		$items[] = $data;

		update_option( $this->product_archetypes_option_key, $items, false );
		update_option( $this->product_archetypes_next_id_option_key, $id + 1, false );

		return rest_ensure_response( $data );
	}

	/**
	 * Update a product archetype.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_product_archetype( $request ) {
		$id    = absint( $request->get_param( 'id' ) );
		$items = $this->load_product_archetypes();
		$index = $this->find_product_archetype_index( $items, $id );

		if ( -1 === $index ) {
			return new WP_Error( 'not_found', __( 'Product archetype not found.', 'mcpwp' ), array( 'status' => 404 ) );
		}

		$current = $items[ $index ];

		// #879: $current is shaped {..., product_data: {...}} — the stored
		// product-field overrides (status, sku, stock_quantity, ...) live
		// NESTED under 'product_data', not at the top level. Request params
		// are flat ({"regular_price":"25.00"}). normalize_product_archetype_
		// payload() rebuilds 'product_data' by calling
		// extract_product_override_payload() on its input, which only ever
		// reads TOP-LEVEL keys. Merging $current straight against the
		// request (the old code) left 'product_data' sitting untouched as a
		// single nested value the extractor never looks inside, so every
		// stored product field the caller did not resend in this request was
		// silently dropped. Flatten the stored product_data into the merge
		// base first — record-level keys from $current (name, product_type,
		// archetype_class, archetype_style, brief) are merged in AFTER, so
		// they are never shadowed by a same-named product field, and the
		// request's own params are merged in last so an explicit override
		// still wins.
		$stored_product_data = isset( $current['product_data'] ) && is_array( $current['product_data'] ) ? $current['product_data'] : array();
		$merge_base           = array_merge( $stored_product_data, $current );
		unset( $merge_base['product_data'] );

		$merged = $this->normalize_product_archetype_payload( array_merge( $merge_base, $request->get_params() ), true );
		if ( is_wp_error( $merged ) ) {
			return $merged;
		}

		$merged['id']          = $id;
		$merged['created_gmt'] = $current['created_gmt'];
		$merged['updated_gmt'] = current_time( 'mysql', true );

		$items[ $index ] = $merged;
		update_option( $this->product_archetypes_option_key, $items, false );

		return rest_ensure_response( $merged );
	}

	/**
	 * Apply a product archetype to an existing or new product.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function apply_product_archetype( $request ) {
		$archetype = $this->get_product_archetype_by_id( absint( $request->get_param( 'id' ) ) );
		if ( is_wp_error( $archetype ) ) {
			return $archetype;
		}

		$payload = $archetype['product_data'];
		$payload = array_merge( $payload, $this->extract_product_override_payload( $request->get_params() ) );

		if ( ! empty( $request->get_param( 'product_id' ) ) ) {
			$product_id = absint( $request->get_param( 'product_id' ) );
			$result     = $this->handler->update_product( $product_id, $payload );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return rest_ensure_response(
				array(
					'mode'         => 'updated',
					'archetype_id' => $archetype['id'],
					'product'      => $result,
				)
			);
		}

		if ( empty( $payload['name'] ) ) {
			return new WP_Error( 'missing_name', __( 'Applying a product archetype to a new product requires a name.', 'mcpwp' ), array( 'status' => 400 ) );
		}

		if ( empty( $payload['status'] ) ) {
			$payload['status'] = 'draft';
		}

		$result = $this->handler->create_product( $payload );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'mode'         => 'created',
				'archetype_id' => $archetype['id'],
				'product'      => $result,
			)
		);
	}

	/**
	 * Get orders.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_orders( $request ) {
		$args = array(
			'per_page' => $request->get_param( 'per_page' ),
			'page'     => $request->get_param( 'page' ),
			'status'   => $request->get_param( 'status' ),
			'customer' => $request->get_param( 'customer' ),
			'after'    => $request->get_param( 'after' ),
			'before'   => $request->get_param( 'before' ),
		);

		return rest_ensure_response( $this->handler->get_orders( $args ) );
	}

	/**
	 * Get single order.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_order( $request ) {
		$result = $this->handler->get_order( $request->get_param( 'id' ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Update order.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_order( $request ) {
		$id   = $request->get_param( 'id' );
		$data = array(
			'status'        => $request->get_param( 'status' ),
			'note'          => $request->get_param( 'note' ),
			'note_customer' => $request->get_param( 'note_customer' ),
		);

		$result = $this->handler->update_order( $id, $data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Get order statuses.
	 *
	 * @return WP_REST_Response
	 */
	public function get_order_statuses() {
		return rest_ensure_response( $this->handler->get_order_statuses() );
	}

	/**
	 * Get customers.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_customers( $request ) {
		$args = array(
			'per_page' => $request->get_param( 'per_page' ),
			'page'     => $request->get_param( 'page' ),
			'search'   => $request->get_param( 'search' ),
			'orderby'  => $request->get_param( 'orderby' ),
			'order'    => $request->get_param( 'order' ),
		);

		return rest_ensure_response( $this->handler->get_customers( $args ) );
	}

	/**
	 * Get single customer.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_customer( $request ) {
		$result = $this->handler->get_customer( $request->get_param( 'id' ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Get analytics.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_analytics( $request ) {
		$args = array(
			'period'   => $request->get_param( 'period' ),
			'date_min' => $request->get_param( 'date_min' ),
			'date_max' => $request->get_param( 'date_max' ),
		);

		$result = $this->handler->get_analytics( $args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Load stored product archetypes.
	 *
	 * @return array
	 */
	private function load_product_archetypes() {
		$items = get_option( $this->product_archetypes_option_key, array() );
		return is_array( $items ) ? $items : array();
	}

	/**
	 * Find a product archetype by ID.
	 *
	 * @param int $id Archetype ID.
	 * @return array|WP_Error
	 */
	private function get_product_archetype_by_id( $id ) {
		$items = $this->load_product_archetypes();
		$index = $this->find_product_archetype_index( $items, $id );

		if ( -1 === $index ) {
			return new WP_Error( 'not_found', __( 'Product archetype not found.', 'mcpwp' ), array( 'status' => 404 ) );
		}

		return $items[ $index ];
	}

	/**
	 * Find an archetype index in a list.
	 *
	 * @param array $items Archetypes list.
	 * @param int   $id    Archetype ID.
	 * @return int
	 */
	private function find_product_archetype_index( $items, $id ) {
		foreach ( $items as $index => $item ) {
			if ( isset( $item['id'] ) && (int) $item['id'] === (int) $id ) {
				return (int) $index;
			}
		}

		return -1;
	}

	/**
	 * Normalize product archetype payload.
	 *
	 * NOTE on 'name': the archetype's own label (top-level $record['name'])
	 * and $record['product_data']['name'] are DELIBERATELY the same value —
	 * create_product_archetype() feeds this method one 'name' param that
	 * becomes both. This is not a bug and not an oversight to "fix" by
	 * splitting them into two fields; that would be an API shape change. The
	 * two can only diverge today by writing the mcpwp_wc_product_archetypes
	 * option directly, outside this method.
	 *
	 * @param array $data    Raw input.
	 * @param bool  $partial Whether partial update is allowed.
	 * @return array|WP_Error
	 */
	private function normalize_product_archetype_payload( $data, $partial = false ) {
		$name = isset( $data['name'] ) ? sanitize_text_field( (string) $data['name'] ) : '';
		if ( ! $partial && '' === $name ) {
			return new WP_Error( 'missing_name', __( 'Archetype name is required.', 'mcpwp' ), array( 'status' => 400 ) );
		}

		$product_type = isset( $data['product_type'] ) ? sanitize_key( (string) $data['product_type'] ) : 'simple';
		if ( '' === $product_type ) {
			$product_type = 'simple';
		}

		$record = array(
			'name'            => $name,
			'archetype_class' => isset( $data['archetype_class'] ) ? sanitize_key( (string) $data['archetype_class'] ) : '',
			'archetype_style' => isset( $data['archetype_style'] ) ? sanitize_text_field( (string) $data['archetype_style'] ) : '',
			'brief'           => isset( $data['brief'] ) ? sanitize_textarea_field( (string) $data['brief'] ) : '',
			'product_type'    => $product_type,
			'product_data'    => $this->extract_product_override_payload( $data ),
		);

		if ( empty( $record['product_data']['type'] ) ) {
			$record['product_data']['type'] = $product_type;
		}

		return $record;
	}

	/**
	 * Extract known WooCommerce product fields from an input array.
	 *
	 * @param array $data Raw input.
	 * @return array
	 */
	private function extract_product_override_payload( $data ) {
		$allowed_keys = array(
			'name',
			'type',
			'status',
			'description',
			'short_description',
			'sku',
			'regular_price',
			'sale_price',
			'manage_stock',
			'stock_quantity',
			'stock_status',
			'categories',
			'tags',
			'image_id',
			'gallery_image_ids',
			'virtual',
			'downloadable',
			'weight',
			'length',
			'width',
			'height',
		);

		$payload = array();
		foreach ( $allowed_keys as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$payload[ $key ] = $data[ $key ];
			}
		}

		return $payload;
	}

	/**
	 * Whether applying archetype $archetype_id, together with this request's
	 * overrides, would result in a 'publish', 'private', or 'future' product
	 * status.
	 *
	 * RC 3.12.1 adversarial review, BLOCK 2 follow-up: a plain per-request
	 * check (Mcpwp_Api_Auth::request_targets_publish_status(), which only ever
	 * looks at $request's own params) is not enough for this route. The
	 * archetype's STORED product_data can already carry 'status' => 'publish'
	 * from whenever the archetype was defined — apply_product_archetype()
	 * merges the request's overrides ON TOP of that stored data, so a caller
	 * who sends zero status-related params at all can still end up publishing
	 * a live product, as long as they know an archetype ID whose template
	 * already defaults to publish. This mirrors that exact merge order
	 * (archetype data first, request override wins) so the scope check and
	 * the handler never disagree about the resulting status.
	 *
	 * Called statically from get_required_scope_for_request(), which must
	 * resolve scope before any controller is constructed — see
	 * PRODUCT_ARCHETYPES_OPTION_KEY's docblock.
	 *
	 * RC 3.12.1 adversarial review, follow-up hardening (this fix): this
	 * method had its OWN independent `in_array( strtolower( $status ), [...],
	 * true )` exact-match check — a second, separate instance of the exact
	 * same defect this fix closes in
	 * Mcpwp_Api_Auth::param_value_targets_admin_scope(). Because
	 * get_required_scope_for_request() dispatches to THIS method instead of
	 * request_targets_publish_status()/param_value_targets_admin_scope()
	 * whenever Mcpwp_REST_WooCommerce is loaded (see the docblock on that
	 * branch), fixing only the trait's comparison would have left this
	 * route's real bypass wide open — caught by
	 * PublishGatePaddedValueBypassTest, which failed here specifically
	 * because that test suite loads Mcpwp_REST_WooCommerce elsewhere and so
	 * exercises this exact branch, not the trait's fallback. Normalized with
	 * sanitize_key() for the same reason as param_value_targets_admin_scope()
	 * — it is the more aggressive of set_product_data()'s own
	 * sanitize_key( $data['status'] ) call, so this check can never be
	 * narrower than what the handler actually does with the value.
	 *
	 * fix/3121-future-status-coercion: 'future' added alongside 'publish' and
	 * 'private'. This is the exact live bypass this fix closes: an archetype
	 * defined (or updated) with product_data['status'] => 'future' at a time
	 * when neither archetype route validated 'status' at all -- reachable on
	 * write scope alone, since defining a template only ever update_option()s
	 * a record -- then applied with ZERO status override, so this method's
	 * merge sees only the stored 'future' value. WordPress's wp_insert_post()
	 * coerces 'future' straight to 'publish' whenever the post's date is not
	 * itself at least a minute in the future (wp-includes/post.php), and this
	 * integration has no field anywhere that lets a caller set a product's
	 * date -- every product it writes is dated now(), so 'future' here is
	 * unconditionally equivalent to 'publish'. There is no legitimate
	 * scheduled-publish flow for a WooCommerce product this plugin exposes
	 * that including 'future' here could break.
	 *
	 * @param int|string       $archetype_id Archetype ID from the route.
	 * @param WP_REST_Request  $request      Request object.
	 * @return bool True when the effective status is 'publish', 'private', or 'future'.
	 */
	public static function archetype_apply_targets_publish_status( $archetype_id, $request ) {
		$status = '';

		$items = get_option( self::PRODUCT_ARCHETYPES_OPTION_KEY, array() );
		if ( is_array( $items ) ) {
			foreach ( $items as $item ) {
				if ( isset( $item['id'] ) && (int) $item['id'] === (int) $archetype_id ) {
					if ( isset( $item['product_data']['status'] ) && is_scalar( $item['product_data']['status'] ) ) {
						$status = (string) $item['product_data']['status'];
					}
					break;
				}
			}
		}

		// The request's own 'status' override, when present, wins over the
		// archetype's stored default — same precedence as the array_merge()
		// in apply_product_archetype().
		$params = method_exists( $request, 'get_params' ) ? (array) $request->get_params() : array();
		if ( array_key_exists( 'status', $params ) && is_scalar( $params['status'] ) ) {
			$status = (string) $params['status'];
		}

		return in_array( sanitize_key( $status ), array( 'publish', 'private', 'future' ), true );
	}
}
