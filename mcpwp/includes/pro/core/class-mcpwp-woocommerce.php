<?php
/**
 * WooCommerce Integration Handler
 *
 * Provides WooCommerce operations for AI agents.
 *
 * @package MCPWP_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce handler class.
 */
class Mcpwp_WooCommerce {

	/**
	 * Check if WooCommerce is active.
	 *
	 * @return bool
	 */
	public function is_active() {
		return class_exists( 'WooCommerce' ) && function_exists( 'WC' );
	}

	/**
	 * Get WooCommerce status.
	 *
	 * @return array
	 */
	public function get_status() {
		if ( ! $this->is_active() ) {
			return array( 'active' => false );
		}

		return array(
			'active'          => true,
			'version'         => WC()->version,
			'currency'        => get_woocommerce_currency(),
			'currency_symbol' => get_woocommerce_currency_symbol(),
			'weight_unit'     => get_option( 'woocommerce_weight_unit' ),
			'dimension_unit'  => get_option( 'woocommerce_dimension_unit' ),
			'tax_enabled'     => wc_tax_enabled(),
			'coupons_enabled' => wc_coupons_enabled(),
			'products_count'  => (int) wp_count_posts( 'product' )->publish,
			'orders_count'    => $this->get_total_orders_count(),
		);
	}

	/**
	 * Get total orders count.
	 *
	 * @return int
	 */
	private function get_total_orders_count() {
		if ( ! $this->is_active() ) {
			return 0;
		}

		$count = 0;
		$statuses = array( 'processing', 'completed', 'on-hold', 'pending' );
		foreach ( $statuses as $status ) {
			$count += wc_orders_count( $status );
		}
		return $count;
	}

	// =========================================================================
	// Products
	// =========================================================================

	/**
	 * Get products.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public function get_products( $args = array() ) {
		if ( ! $this->is_active() ) {
			return array( 'products' => array(), 'total' => 0 );
		}

		$defaults = array(
			'per_page'     => 50,
			'page'         => 1,
			'status'       => 'publish',
			'type'         => '',
			'category'     => '',
			'tag'          => '',
			'search'       => '',
			'sku'          => '',
			'stock_status' => '',
			'orderby'      => 'date',
			'order'        => 'DESC',
		);

		$args = wp_parse_args( $args, $defaults );

		$query_args = array(
			'limit'   => $args['per_page'],
			'page'    => $args['page'],
			'status'  => $args['status'],
			'orderby' => $args['orderby'],
			'order'   => $args['order'],
			'return'  => 'objects',
		);

		if ( ! empty( $args['type'] ) ) {
			$query_args['type'] = $args['type'];
		}

		if ( ! empty( $args['category'] ) ) {
			$query_args['category'] = array( $args['category'] );
		}

		if ( ! empty( $args['tag'] ) ) {
			$query_args['tag'] = array( $args['tag'] );
		}

		if ( ! empty( $args['search'] ) ) {
			$query_args['s'] = $args['search'];
		}

		if ( ! empty( $args['sku'] ) ) {
			$query_args['sku'] = $args['sku'];
		}

		if ( ! empty( $args['stock_status'] ) ) {
			$query_args['stock_status'] = $args['stock_status'];
		}

		$products = wc_get_products( $query_args );

		// Get total count for pagination.
		$count_args = $query_args;
		$count_args['return'] = 'ids';
		$count_args['limit'] = -1;
		unset( $count_args['page'] );
		$total = count( wc_get_products( $count_args ) );

		$formatted = array();
		foreach ( $products as $product ) {
			$formatted[] = $this->format_product( $product );
		}

		return array(
			'products'    => $formatted,
			'total'       => $total,
			'page'        => $args['page'],
			'per_page'    => $args['per_page'],
			'total_pages' => ceil( $total / $args['per_page'] ),
		);
	}

	/**
	 * Get single product.
	 *
	 * @param int $id Product ID.
	 * @return array|WP_Error
	 */
	public function get_product( $id ) {
		if ( ! $this->is_active() ) {
			return new WP_Error( 'wc_not_active', __( 'WooCommerce is not active.', 'mcpwp' ), array( 'status' => 400 ) );
		}

		$product = wc_get_product( $id );
		if ( ! $product ) {
			return new WP_Error( 'not_found', __( 'Product not found.', 'mcpwp' ), array( 'status' => 404 ) );
		}

		return $this->format_product( $product, true );
	}

	/**
	 * Create product.
	 *
	 * @param array $data Product data.
	 * @return array|WP_Error
	 */
	public function create_product( $data ) {
		if ( ! $this->is_active() ) {
			return new WP_Error( 'wc_not_active', __( 'WooCommerce is not active.', 'mcpwp' ), array( 'status' => 400 ) );
		}

		$type = isset( $data['type'] ) ? $data['type'] : 'simple';

		switch ( $type ) {
			case 'variable':
				$product = new WC_Product_Variable();
				break;
			case 'grouped':
				$product = new WC_Product_Grouped();
				break;
			case 'external':
				$product = new WC_Product_External();
				break;
			default:
				$product = new WC_Product_Simple();
		}

		$this->set_product_data( $product, $data );

		$product_id = $product->save();

		if ( ! $product_id ) {
			return new WP_Error( 'create_failed', __( 'Failed to create product.', 'mcpwp' ), array( 'status' => 500 ) );
		}

		// Handle categories.
		if ( ! empty( $data['categories'] ) ) {
			$this->set_product_categories( $product_id, $data['categories'] );
		}

		// Handle tags.
		if ( ! empty( $data['tags'] ) ) {
			$this->set_product_tags( $product_id, $data['tags'] );
		}

		return $this->get_product( $product_id );
	}

	/**
	 * Update product.
	 *
	 * @param int   $id   Product ID.
	 * @param array $data Product data.
	 * @return array|WP_Error
	 */
	public function update_product( $id, $data ) {
		if ( ! $this->is_active() ) {
			return new WP_Error( 'wc_not_active', __( 'WooCommerce is not active.', 'mcpwp' ), array( 'status' => 400 ) );
		}

		$product = wc_get_product( $id );
		if ( ! $product ) {
			return new WP_Error( 'not_found', __( 'Product not found.', 'mcpwp' ), array( 'status' => 404 ) );
		}

		$this->set_product_data( $product, $data );

		$product->save();

		// Handle categories.
		if ( isset( $data['categories'] ) ) {
			$this->set_product_categories( $id, $data['categories'] );
		}

		// Handle tags.
		if ( isset( $data['tags'] ) ) {
			$this->set_product_tags( $id, $data['tags'] );
		}

		return $this->get_product( $id );
	}

	/**
	 * Delete product.
	 *
	 * @param int  $id    Product ID.
	 * @param bool $force Force delete (bypass trash).
	 * @return bool|WP_Error
	 */
	public function delete_product( $id, $force = false ) {
		if ( ! $this->is_active() ) {
			return new WP_Error( 'wc_not_active', __( 'WooCommerce is not active.', 'mcpwp' ), array( 'status' => 400 ) );
		}

		$product = wc_get_product( $id );
		if ( ! $product ) {
			return new WP_Error( 'not_found', __( 'Product not found.', 'mcpwp' ), array( 'status' => 404 ) );
		}

		if ( $force ) {
			$product->delete( true );
		} else {
			$product->delete( false );
		}

		return true;
	}

	/**
	 * Set product data.
	 *
	 * @param WC_Product $product Product object.
	 * @param array      $data    Data to set.
	 */
	private function set_product_data( $product, $data ) {
		if ( isset( $data['name'] ) ) {
			$product->set_name( sanitize_text_field( $data['name'] ) );
		}

		if ( isset( $data['slug'] ) ) {
			$product->set_slug( sanitize_title( $data['slug'] ) );
		}

		if ( isset( $data['status'] ) ) {
			$product->set_status( sanitize_key( $data['status'] ) );
		}

		if ( isset( $data['description'] ) ) {
			$product->set_description( wp_kses_post( $data['description'] ) );
		}

		if ( isset( $data['short_description'] ) ) {
			$product->set_short_description( wp_kses_post( $data['short_description'] ) );
		}

		if ( isset( $data['sku'] ) ) {
			$product->set_sku( sanitize_text_field( $data['sku'] ) );
		}

		if ( isset( $data['regular_price'] ) ) {
			$product->set_regular_price( $data['regular_price'] );
		}

		if ( isset( $data['sale_price'] ) ) {
			$product->set_sale_price( $data['sale_price'] );
		}

		if ( isset( $data['manage_stock'] ) ) {
			$product->set_manage_stock( (bool) $data['manage_stock'] );
		}

		if ( isset( $data['stock_quantity'] ) ) {
			$product->set_stock_quantity( (int) $data['stock_quantity'] );
		}

		if ( isset( $data['stock_status'] ) ) {
			$product->set_stock_status( sanitize_key( $data['stock_status'] ) );
		}

		if ( isset( $data['weight'] ) ) {
			$product->set_weight( $data['weight'] );
		}

		if ( isset( $data['length'] ) ) {
			$product->set_length( $data['length'] );
		}

		if ( isset( $data['width'] ) ) {
			$product->set_width( $data['width'] );
		}

		if ( isset( $data['height'] ) ) {
			$product->set_height( $data['height'] );
		}

		if ( isset( $data['image_id'] ) ) {
			$product->set_image_id( (int) $data['image_id'] );
		}

		if ( isset( $data['gallery_image_ids'] ) ) {
			$product->set_gallery_image_ids( array_map( 'intval', $data['gallery_image_ids'] ) );
		}

		if ( isset( $data['virtual'] ) ) {
			$product->set_virtual( (bool) $data['virtual'] );
		}

		if ( isset( $data['downloadable'] ) ) {
			$product->set_downloadable( (bool) $data['downloadable'] );
		}

		// External product fields.
		if ( $product instanceof WC_Product_External ) {
			if ( isset( $data['product_url'] ) ) {
				$product->set_product_url( esc_url_raw( $data['product_url'] ) );
			}
			if ( isset( $data['button_text'] ) ) {
				$product->set_button_text( sanitize_text_field( $data['button_text'] ) );
			}
		}
	}

	/**
	 * Set product categories.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $categories Category names or IDs.
	 */
	private function set_product_categories( $product_id, $categories ) {
		$term_ids = array();
		foreach ( $categories as $cat ) {
			if ( is_numeric( $cat ) ) {
				$term_ids[] = (int) $cat;
			} else {
				$term = get_term_by( 'name', $cat, 'product_cat' );
				if ( $term ) {
					$term_ids[] = $term->term_id;
				} else {
					// Create category if it doesn't exist.
					$result = wp_insert_term( $cat, 'product_cat' );
					if ( ! is_wp_error( $result ) ) {
						$term_ids[] = $result['term_id'];
					}
				}
			}
		}
		wp_set_object_terms( $product_id, $term_ids, 'product_cat' );
	}

	/**
	 * Set product tags.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $tags       Tag names or IDs.
	 */
	private function set_product_tags( $product_id, $tags ) {
		$term_ids = array();
		foreach ( $tags as $tag ) {
			if ( is_numeric( $tag ) ) {
				$term_ids[] = (int) $tag;
			} else {
				$term = get_term_by( 'name', $tag, 'product_tag' );
				if ( $term ) {
					$term_ids[] = $term->term_id;
				} else {
					$result = wp_insert_term( $tag, 'product_tag' );
					if ( ! is_wp_error( $result ) ) {
						$term_ids[] = $result['term_id'];
					}
				}
			}
		}
		wp_set_object_terms( $product_id, $term_ids, 'product_tag' );
	}

	/**
	 * Get product categories.
	 *
	 * @return array
	 */
	public function get_product_categories() {
		if ( ! $this->is_active() ) {
			return array();
		}

		$terms = get_terms( array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
		) );

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$categories = array();
		foreach ( $terms as $term ) {
			$categories[] = array(
				'id'          => $term->term_id,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'parent'      => $term->parent,
				'description' => $term->description,
				'count'       => $term->count,
				'image_id'    => (int) get_term_meta( $term->term_id, 'thumbnail_id', true ),
			);
		}

		return $categories;
	}

	/**
	 * Create product category.
	 *
	 * @param array $data Category data.
	 * @return array|WP_Error
	 */
	public function create_product_category( $data ) {
		if ( ! $this->is_active() ) {
			return new WP_Error( 'wc_not_active', __( 'WooCommerce is not active.', 'mcpwp' ), array( 'status' => 400 ) );
		}

		if ( empty( $data['name'] ) ) {
			return new WP_Error( 'missing_name', __( 'Category name is required.', 'mcpwp' ), array( 'status' => 400 ) );
		}

		$args = array();

		if ( ! empty( $data['slug'] ) ) {
			$args['slug'] = sanitize_title( $data['slug'] );
		}

		if ( ! empty( $data['description'] ) ) {
			$args['description'] = sanitize_textarea_field( $data['description'] );
		}

		if ( isset( $data['parent'] ) ) {
			$args['parent'] = (int) $data['parent'];
		}

		$result = wp_insert_term( sanitize_text_field( $data['name'] ), 'product_cat', $args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Handle thumbnail image.
		if ( ! empty( $data['image_id'] ) ) {
			update_term_meta( $result['term_id'], 'thumbnail_id', (int) $data['image_id'] );
		}

		$term = get_term( $result['term_id'], 'product_cat' );

		return array(
			'id'          => $term->term_id,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'parent'      => $term->parent,
			'description' => $term->description,
			'count'       => $term->count,
			'image_id'    => (int) get_term_meta( $term->term_id, 'thumbnail_id', true ),
		);
	}

	/**
	 * Update product category.
	 *
	 * @param int   $id   Category (term) ID.
	 * @param array $data Category data.
	 * @return array|WP_Error
	 */
	public function update_product_category( $id, $data ) {
		if ( ! $this->is_active() ) {
			return new WP_Error( 'wc_not_active', __( 'WooCommerce is not active.', 'mcpwp' ), array( 'status' => 400 ) );
		}

		$term = get_term( $id, 'product_cat' );
		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'not_found', __( 'Product category not found.', 'mcpwp' ), array( 'status' => 404 ) );
		}

		$args = array();

		if ( isset( $data['name'] ) ) {
			$args['name'] = sanitize_text_field( $data['name'] );
		}

		if ( isset( $data['slug'] ) ) {
			$args['slug'] = sanitize_title( $data['slug'] );
		}

		if ( isset( $data['description'] ) ) {
			$args['description'] = sanitize_textarea_field( $data['description'] );
		}

		if ( isset( $data['parent'] ) ) {
			$args['parent'] = (int) $data['parent'];
		}

		if ( ! empty( $args ) ) {
			$result = wp_update_term( $id, 'product_cat', $args );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		// Handle thumbnail image.
		if ( isset( $data['image_id'] ) ) {
			update_term_meta( $id, 'thumbnail_id', (int) $data['image_id'] );
		}

		$term = get_term( $id, 'product_cat' );

		return array(
			'id'          => $term->term_id,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'parent'      => $term->parent,
			'description' => $term->description,
			'count'       => $term->count,
			'image_id'    => (int) get_term_meta( $term->term_id, 'thumbnail_id', true ),
		);
	}

	/**
	 * Get product tags.
	 *
	 * @return array
	 */
	public function get_product_tags() {
		if ( ! $this->is_active() ) {
			return array();
		}

		$terms = get_terms( array(
			'taxonomy'   => 'product_tag',
			'hide_empty' => false,
		) );

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$tags = array();
		foreach ( $terms as $term ) {
			$tags[] = array(
				'id'    => $term->term_id,
				'name'  => $term->name,
				'slug'  => $term->slug,
				'count' => $term->count,
			);
		}

		return $tags;
	}

	/**
	 * Format product for API response.
	 *
	 * @param WC_Product $product  Product object.
	 * @param bool       $detailed Include extra details.
	 * @return array
	 */
	private function format_product( $product, $detailed = false ) {
		$data = array(
			'id'                => $product->get_id(),
			'name'              => $product->get_name(),
			'slug'              => $product->get_slug(),
			'type'              => $product->get_type(),
			'status'            => $product->get_status(),
			'sku'               => $product->get_sku(),
			'price'             => $product->get_price(),
			'regular_price'     => $product->get_regular_price(),
			'sale_price'        => $product->get_sale_price(),
			'on_sale'           => $product->is_on_sale(),
			'stock_status'      => $product->get_stock_status(),
			'stock_quantity'    => $product->get_stock_quantity(),
			'manage_stock'      => $product->get_manage_stock(),
			'categories'        => $this->get_term_names( $product, 'product_cat' ),
			'tags'              => $this->get_term_names( $product, 'product_tag' ),
			'permalink'         => $product->get_permalink(),
			'date_created'      => $product->get_date_created() ? $product->get_date_created()->format( 'c' ) : null,
			'date_modified'     => $product->get_date_modified() ? $product->get_date_modified()->format( 'c' ) : null,
		);

		// Whether WooCommerce would sell it, and where the catalog shows it
		// (#843). A fact that cannot be computed is null, and unknown_reasons
		// names the field and why — so it is told apart from a real false, and
		// a client that tests the field for truthiness fails safe instead of
		// building a buy link for a product nobody verified.
		$unknown                    = array();
		$data['purchasable']        = $this->product_fact( $product, 'is_purchasable', 'purchasable', $unknown );
		$data['catalog_visibility'] = $this->product_fact( $product, 'get_catalog_visibility', 'catalog_visibility', $unknown );
		if ( ! empty( $unknown ) ) {
			$data['unknown_reasons'] = $unknown;
		}

		if ( $detailed ) {
			$data['description']       = $product->get_description();
			$data['short_description'] = $product->get_short_description();
			$data['weight']            = $product->get_weight();
			$data['dimensions']        = array(
				'length' => $product->get_length(),
				'width'  => $product->get_width(),
				'height' => $product->get_height(),
			);
			$data['images']            = $this->get_product_images( $product );
			$data['attributes']        = $this->get_product_attributes( $product );
			$data['virtual']           = $product->is_virtual();
			$data['downloadable']      = $product->is_downloadable();

			// Variable product specific.
			if ( $product->is_type( 'variable' ) ) {
				$data['variations'] = $product->get_children();
			}

			// External product specific.
			if ( $product instanceof WC_Product_External ) {
				$data['product_url']  = $product->get_product_url();
				$data['button_text']  = $product->get_button_text();
			}
		}

		return $data;
	}

	/**
	 * Read one product fact, or record why it could not be read.
	 *
	 * @param WC_Product $product Product object.
	 * @param string     $method  WC_Product method: is_purchasable or get_catalog_visibility.
	 * @param string     $field   Response field the fact is reported under.
	 * @param array      $unknown Collected by reference: field => reason.
	 * @return bool|string|null The fact, or null when it could not be computed.
	 */
	private function product_fact( $product, $method, $field, array &$unknown ) {
		if ( ! is_object( $product ) || ! method_exists( $product, $method ) ) {
			$unknown[ $field ] = sprintf( 'WC_Product::%s() is not available on %s.', $method, is_object( $product ) ? get_class( $product ) : gettype( $product ) );
			return null;
		}

		try {
			$value = $product->$method();
		} catch ( Throwable $e ) {
			$unknown[ $field ] = sprintf( 'WC_Product::%s() threw %s: %s', $method, get_class( $e ), $e->getMessage() );
			return null;
		}

		if ( 'is_purchasable' === $method ) {
			// WooCommerce itself tests this for truthiness before selling.
			return (bool) $value;
		}

		if ( ! is_string( $value ) || '' === $value ) {
			$unknown[ $field ] = sprintf( 'WC_Product::%s() returned no value.', $method );
			return null;
		}

		return $value;
	}

	/**
	 * Get term names for product.
	 *
	 * @param WC_Product $product  Product object.
	 * @param string     $taxonomy Taxonomy name.
	 * @return array
	 */
	private function get_term_names( $product, $taxonomy ) {
		$terms = get_the_terms( $product->get_id(), $taxonomy );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return array();
		}
		return wp_list_pluck( $terms, 'name' );
	}

	/**
	 * Get product images.
	 *
	 * @param WC_Product $product Product object.
	 * @return array
	 */
	private function get_product_images( $product ) {
		$images = array();

		// Main image.
		$image_id = $product->get_image_id();
		if ( $image_id ) {
			$images[] = array(
				'id'  => $image_id,
				'url' => wp_get_attachment_url( $image_id ),
				'alt' => get_post_meta( $image_id, '_wp_attachment_image_alt', true ),
			);
		}

		// Gallery images.
		foreach ( $product->get_gallery_image_ids() as $gallery_id ) {
			$images[] = array(
				'id'  => $gallery_id,
				'url' => wp_get_attachment_url( $gallery_id ),
				'alt' => get_post_meta( $gallery_id, '_wp_attachment_image_alt', true ),
			);
		}

		return $images;
	}

	/**
	 * Get product attributes.
	 *
	 * @param WC_Product $product Product object.
	 * @return array
	 */
	private function get_product_attributes( $product ) {
		$attributes = array();
		foreach ( $product->get_attributes() as $attr ) {
			if ( $attr instanceof WC_Product_Attribute ) {
				$attributes[] = array(
					'name'      => $attr->get_name(),
					'options'   => $attr->get_options(),
					'visible'   => $attr->get_visible(),
					'variation' => $attr->get_variation(),
				);
			}
		}
		return $attributes;
	}

	// =========================================================================
	// Orders
	// =========================================================================

	/**
	 * Get orders.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public function get_orders( $args = array() ) {
		if ( ! $this->is_active() ) {
			return array( 'orders' => array(), 'total' => 0 );
		}

		$defaults = array(
			'per_page' => 50,
			'page'     => 1,
			'status'   => 'any',
			'customer' => '',
			'after'    => '',
			'before'   => '',
		);

		$args = wp_parse_args( $args, $defaults );

		$query_args = array(
			'limit'    => $args['per_page'],
			'page'     => $args['page'],
			'status'   => $args['status'],
			'orderby'  => 'date',
			'order'    => 'DESC',
			'return'   => 'objects',
		);

		if ( ! empty( $args['customer'] ) ) {
			$query_args['customer_id'] = (int) $args['customer'];
		}

		if ( ! empty( $args['after'] ) ) {
			$query_args['date_created'] = '>=' . $args['after'];
		}

		if ( ! empty( $args['before'] ) ) {
			if ( ! empty( $args['after'] ) ) {
				$query_args['date_created'] = $args['after'] . '...' . $args['before'];
			} else {
				$query_args['date_created'] = '<=' . $args['before'];
			}
		}

		$orders = wc_get_orders( $query_args );

		// Get total count.
		$count_args = $query_args;
		$count_args['return'] = 'ids';
		$count_args['limit'] = -1;
		unset( $count_args['page'] );
		$total = count( wc_get_orders( $count_args ) );

		$formatted = array();
		foreach ( $orders as $order ) {
			$formatted[] = $this->format_order( $order );
		}

		return array(
			'orders'      => $formatted,
			'total'       => $total,
			'page'        => $args['page'],
			'per_page'    => $args['per_page'],
			'total_pages' => ceil( $total / $args['per_page'] ),
		);
	}

	/**
	 * Get single order.
	 *
	 * @param int $id Order ID.
	 * @return array|WP_Error
	 */
	public function get_order( $id ) {
		if ( ! $this->is_active() ) {
			return new WP_Error( 'wc_not_active', __( 'WooCommerce is not active.', 'mcpwp' ), array( 'status' => 400 ) );
		}

		$order = wc_get_order( $id );
		if ( ! $order ) {
			return new WP_Error( 'not_found', __( 'Order not found.', 'mcpwp' ), array( 'status' => 404 ) );
		}

		return $this->format_order( $order, true );
	}

	/**
	 * Update order.
	 *
	 * @param int   $id   Order ID.
	 * @param array $data Order data.
	 * @return array|WP_Error
	 */
	public function update_order( $id, $data ) {
		if ( ! $this->is_active() ) {
			return new WP_Error( 'wc_not_active', __( 'WooCommerce is not active.', 'mcpwp' ), array( 'status' => 400 ) );
		}

		$order = wc_get_order( $id );
		if ( ! $order ) {
			return new WP_Error( 'not_found', __( 'Order not found.', 'mcpwp' ), array( 'status' => 404 ) );
		}

		// Update status.
		if ( isset( $data['status'] ) ) {
			$order->set_status( sanitize_key( $data['status'] ) );
		}

		// Add note.
		if ( ! empty( $data['note'] ) ) {
			$is_customer_note = isset( $data['note_customer'] ) && $data['note_customer'];
			$order->add_order_note( sanitize_textarea_field( $data['note'] ), $is_customer_note );
		}

		$order->save();

		return $this->get_order( $id );
	}

	/**
	 * Get order statuses.
	 *
	 * @return array
	 */
	public function get_order_statuses() {
		if ( ! $this->is_active() ) {
			return array();
		}

		$statuses = wc_get_order_statuses();
		$result = array();

		foreach ( $statuses as $slug => $name ) {
			$result[] = array(
				'slug' => str_replace( 'wc-', '', $slug ),
				'name' => $name,
			);
		}

		return $result;
	}

	/**
	 * Format order for API response.
	 *
	 * @param WC_Order $order    Order object.
	 * @param bool     $detailed Include extra details.
	 * @return array
	 */
	private function format_order( $order, $detailed = false ) {
		$data = array(
			'id'              => $order->get_id(),
			'number'          => $order->get_order_number(),
			'status'          => $order->get_status(),
			'currency'        => $order->get_currency(),
			'total'           => $order->get_total(),
			'subtotal'        => $order->get_subtotal(),
			'tax_total'       => $order->get_total_tax(),
			'shipping_total'  => $order->get_shipping_total(),
			'discount_total'  => $order->get_discount_total(),
			'payment_method'  => $order->get_payment_method_title(),
			'customer_id'     => $order->get_customer_id(),
			'date_created'    => $order->get_date_created() ? $order->get_date_created()->format( 'c' ) : null,
			'date_completed'  => $order->get_date_completed() ? $order->get_date_completed()->format( 'c' ) : null,
			'items_count'     => $order->get_item_count(),
		);

		if ( $detailed ) {
			$data['billing']  = $order->get_address( 'billing' );
			$data['shipping'] = $order->get_address( 'shipping' );
			$data['items']    = $this->get_order_items( $order );
			$data['notes']    = $this->get_order_notes( $order );
			$data['customer_note'] = $order->get_customer_note();
		}

		return $data;
	}

	/**
	 * Get order items.
	 *
	 * @param WC_Order $order Order object.
	 * @return array
	 */
	private function get_order_items( $order ) {
		$items = array();

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			$items[] = array(
				'id'         => $item->get_id(),
				'product_id' => $item->get_product_id(),
				'name'       => $item->get_name(),
				'sku'        => $product ? $product->get_sku() : '',
				'quantity'   => $item->get_quantity(),
				'subtotal'   => $item->get_subtotal(),
				'total'      => $item->get_total(),
			);
		}

		return $items;
	}

	/**
	 * Get order notes.
	 *
	 * @param WC_Order $order Order object.
	 * @return array
	 */
	private function get_order_notes( $order ) {
		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$result = array();

		foreach ( $notes as $note ) {
			$result[] = array(
				'id'            => $note->id,
				'content'       => $note->content,
				'customer_note' => $note->customer_note,
				'date_created'  => $note->date_created->format( 'c' ),
				'added_by'      => $note->added_by,
			);
		}

		return $result;
	}

	// =========================================================================
	// Customers
	// =========================================================================

	/**
	 * Get customers.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public function get_customers( $args = array() ) {
		if ( ! $this->is_active() ) {
			return array( 'customers' => array(), 'total' => 0 );
		}

		$defaults = array(
			'per_page' => 50,
			'page'     => 1,
			'search'   => '',
			'orderby'  => 'registered',
			'order'    => 'DESC',
		);

		$args = wp_parse_args( $args, $defaults );

		$query_args = array(
			'role'    => 'customer',
			'number'  => $args['per_page'],
			'paged'   => $args['page'],
			'orderby' => $args['orderby'],
			'order'   => $args['order'],
		);

		if ( ! empty( $args['search'] ) ) {
			$query_args['search'] = '*' . $args['search'] . '*';
			$query_args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
		}

		$query = new WP_User_Query( $query_args );
		$users = $query->get_results();
		$total = $query->get_total();

		$formatted = array();
		foreach ( $users as $user ) {
			$formatted[] = $this->format_customer( $user );
		}

		return array(
			'customers'   => $formatted,
			'total'       => $total,
			'page'        => $args['page'],
			'per_page'    => $args['per_page'],
			'total_pages' => ceil( $total / $args['per_page'] ),
		);
	}

	/**
	 * Get single customer.
	 *
	 * @param int $id Customer ID.
	 * @return array|WP_Error
	 */
	public function get_customer( $id ) {
		if ( ! $this->is_active() ) {
			return new WP_Error( 'wc_not_active', __( 'WooCommerce is not active.', 'mcpwp' ), array( 'status' => 400 ) );
		}

		$customer = new WC_Customer( $id );
		if ( ! $customer->get_id() ) {
			return new WP_Error( 'not_found', __( 'Customer not found.', 'mcpwp' ), array( 'status' => 404 ) );
		}

		return $this->format_customer( $customer, true );
	}

	/**
	 * Format customer for API response.
	 *
	 * @param WP_User|WC_Customer $customer Customer object.
	 * @param bool                $detailed Include extra details.
	 * @return array
	 */
	private function format_customer( $customer, $detailed = false ) {
		if ( $customer instanceof WP_User ) {
			$customer = new WC_Customer( $customer->ID );
		}

		$data = array(
			'id'             => $customer->get_id(),
			'email'          => $customer->get_email(),
			'first_name'     => $customer->get_first_name(),
			'last_name'      => $customer->get_last_name(),
			'display_name'   => $customer->get_display_name(),
			'date_created'   => $customer->get_date_created() ? $customer->get_date_created()->format( 'c' ) : null,
			'orders_count'   => $customer->get_order_count(),
			'total_spent'    => $customer->get_total_spent(),
		);

		if ( $detailed ) {
			$data['billing']  = array(
				'first_name' => $customer->get_billing_first_name(),
				'last_name'  => $customer->get_billing_last_name(),
				'company'    => $customer->get_billing_company(),
				'address_1'  => $customer->get_billing_address_1(),
				'address_2'  => $customer->get_billing_address_2(),
				'city'       => $customer->get_billing_city(),
				'state'      => $customer->get_billing_state(),
				'postcode'   => $customer->get_billing_postcode(),
				'country'    => $customer->get_billing_country(),
				'phone'      => $customer->get_billing_phone(),
			);
			$data['shipping'] = array(
				'first_name' => $customer->get_shipping_first_name(),
				'last_name'  => $customer->get_shipping_last_name(),
				'company'    => $customer->get_shipping_company(),
				'address_1'  => $customer->get_shipping_address_1(),
				'address_2'  => $customer->get_shipping_address_2(),
				'city'       => $customer->get_shipping_city(),
				'state'      => $customer->get_shipping_state(),
				'postcode'   => $customer->get_shipping_postcode(),
				'country'    => $customer->get_shipping_country(),
			);
		}

		return $data;
	}

	// =========================================================================
	// Analytics
	// =========================================================================

	/**
	 * Get analytics data.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public function get_analytics( $args = array() ) {
		if ( ! $this->is_active() ) {
			return new WP_Error( 'wc_not_active', __( 'WooCommerce is not active.', 'mcpwp' ), array( 'status' => 400 ) );
		}

		$defaults = array(
			'period'   => 'month',
			'date_min' => '',
			'date_max' => '',
		);

		$args = wp_parse_args( $args, $defaults );

		// Determine date range.
		$date_range = $this->get_date_range( $args );

		return array(
			'period'           => $args['period'],
			'date_range'       => $date_range,
			'sales'            => array(
				'total'   => $this->get_total_sales( $date_range ),
				'count'   => $this->get_orders_count_in_range( $date_range ),
				'average' => $this->get_average_order_value( $date_range ),
			),
			'products'         => array(
				'total'        => (int) wp_count_posts( 'product' )->publish,
				'in_stock'     => $this->count_products_by_stock( 'instock' ),
				'out_of_stock' => $this->count_products_by_stock( 'outofstock' ),
			),
			'top_products'     => $this->get_top_selling_products( 5, $date_range ),
			'customers'        => array(
				'total' => $this->get_customer_count(),
				'new'   => $this->get_new_customers_count( $date_range ),
			),
			'orders_by_status' => $this->get_orders_by_status(),
		);
	}

	/**
	 * Get date range from arguments.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	private function get_date_range( $args ) {
		if ( ! empty( $args['date_min'] ) && ! empty( $args['date_max'] ) ) {
			return array(
				'start' => $args['date_min'],
				'end'   => $args['date_max'],
			);
		}

		$now = current_time( 'timestamp' );

		switch ( $args['period'] ) {
			case 'day':
				$start = gmdate( 'Y-m-d 00:00:00', $now );
				break;
			case 'week':
				$start = gmdate( 'Y-m-d 00:00:00', strtotime( '-7 days', $now ) );
				break;
			case 'year':
				$start = gmdate( 'Y-m-d 00:00:00', strtotime( '-1 year', $now ) );
				break;
			case 'month':
			default:
				$start = gmdate( 'Y-m-d 00:00:00', strtotime( '-30 days', $now ) );
				break;
		}

		return array(
			'start' => $start,
			'end'   => gmdate( 'Y-m-d 23:59:59', $now ),
		);
	}

	/**
	 * Get total sales in date range.
	 *
	 * @param array $date_range Date range.
	 * @return string
	 */
	private function get_total_sales( $date_range ) {
		global $wpdb;

		$statuses = array( 'wc-completed', 'wc-processing' );
		$status_in = "'" . implode( "','", $statuses ) . "'";

		// Handle HPOS (High Performance Order Storage).
		if ( class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' ) &&
			 Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$table = $wpdb->prefix . 'wc_orders';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name and status_in from safe sources.
			$total = $wpdb->get_var( $wpdb->prepare(
				"SELECT SUM(total_amount) FROM {$table}
				WHERE status IN ({$status_in})
				AND date_created_gmt >= %s
				AND date_created_gmt <= %s",
				$date_range['start'],
				$date_range['end']
			) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names and status_in from safe sources.
			$total = $wpdb->get_var( $wpdb->prepare(
				"SELECT SUM(meta.meta_value) FROM {$wpdb->posts} posts
				LEFT JOIN {$wpdb->postmeta} meta ON posts.ID = meta.post_id
				WHERE posts.post_type = 'shop_order'
				AND posts.post_status IN ({$status_in})
				AND meta.meta_key = '_order_total'
				AND posts.post_date >= %s
				AND posts.post_date <= %s",
				$date_range['start'],
				$date_range['end']
			) );
		}

		return wc_format_decimal( $total ? $total : 0, 2 );
	}

	/**
	 * Get orders count in date range.
	 *
	 * @param array $date_range Date range.
	 * @return int
	 */
	private function get_orders_count_in_range( $date_range ) {
		$orders = wc_get_orders( array(
			'status'       => array( 'completed', 'processing' ),
			'date_created' => $date_range['start'] . '...' . $date_range['end'],
			'return'       => 'ids',
			'limit'        => -1,
		) );

		return count( $orders );
	}

	/**
	 * Get average order value.
	 *
	 * @param array $date_range Date range.
	 * @return string
	 */
	private function get_average_order_value( $date_range ) {
		$total = (float) $this->get_total_sales( $date_range );
		$count = $this->get_orders_count_in_range( $date_range );

		if ( $count === 0 ) {
			return '0.00';
		}

		return wc_format_decimal( $total / $count, 2 );
	}

	/**
	 * Count products by stock status.
	 *
	 * @param string $status Stock status.
	 * @return int
	 */
	private function count_products_by_stock( $status ) {
		$products = wc_get_products( array(
			'stock_status' => $status,
			'return'       => 'ids',
			'limit'        => -1,
		) );

		return count( $products );
	}

	/**
	 * Get top selling products.
	 *
	 * @param int   $limit      Number of products.
	 * @param array $date_range Date range.
	 * @return array
	 */
	private function get_top_selling_products( $limit, $date_range ) {
		global $wpdb;

		$statuses = array( 'wc-completed', 'wc-processing' );
		$status_in = "'" . implode( "','", $statuses ) . "'";

		// Get top selling product IDs.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names and status_in from safe sources.
		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT order_items.order_item_id, order_item_meta.meta_value as product_id,
				SUM(order_item_meta_qty.meta_value) as qty
			FROM {$wpdb->prefix}woocommerce_order_items as order_items
			LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta as order_item_meta
				ON order_items.order_item_id = order_item_meta.order_item_id
			LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta as order_item_meta_qty
				ON order_items.order_item_id = order_item_meta_qty.order_item_id
			LEFT JOIN {$wpdb->posts} as posts ON order_items.order_id = posts.ID
			WHERE posts.post_type = 'shop_order'
			AND posts.post_status IN ({$status_in})
			AND order_item_meta.meta_key = '_product_id'
			AND order_item_meta_qty.meta_key = '_qty'
			AND posts.post_date >= %s
			AND posts.post_date <= %s
			GROUP BY product_id
			ORDER BY qty DESC
			LIMIT %d",
			$date_range['start'],
			$date_range['end'],
			$limit
		) );

		$products = array();
		foreach ( $results as $result ) {
			$product = wc_get_product( $result->product_id );
			if ( $product ) {
				$products[] = array(
					'id'       => $product->get_id(),
					'name'     => $product->get_name(),
					'sku'      => $product->get_sku(),
					'quantity' => (int) $result->qty,
					'price'    => $product->get_price(),
				);
			}
		}

		return $products;
	}

	/**
	 * Get total customer count.
	 *
	 * @return int
	 */
	private function get_customer_count() {
		$query = new WP_User_Query( array(
			'role'   => 'customer',
			'fields' => 'ID',
		) );

		return $query->get_total();
	}

	/**
	 * Get new customers count in date range.
	 *
	 * @param array $date_range Date range.
	 * @return int
	 */
	private function get_new_customers_count( $date_range ) {
		$query = new WP_User_Query( array(
			'role'       => 'customer',
			'fields'     => 'ID',
			'date_query' => array(
				array(
					'after'     => $date_range['start'],
					'before'    => $date_range['end'],
					'inclusive' => true,
				),
			),
		) );

		return $query->get_total();
	}

	/**
	 * Get orders grouped by status.
	 *
	 * @return array
	 */
	private function get_orders_by_status() {
		$result = array();
		$statuses = wc_get_order_statuses();

		foreach ( $statuses as $slug => $name ) {
			$status_key = str_replace( 'wc-', '', $slug );
			$result[ $status_key ] = wc_orders_count( $status_key );
		}

		return $result;
	}
}
