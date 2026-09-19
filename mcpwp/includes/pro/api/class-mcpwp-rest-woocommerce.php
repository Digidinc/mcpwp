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
	 * @var string
	 */
	private $product_archetypes_option_key = 'mcpwp_wc_product_archetypes';

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
		$merged  = $this->normalize_product_archetype_payload( array_merge( $current, $request->get_params() ), true );
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
}
