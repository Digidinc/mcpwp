<?php
/**
 * Pro-tier tool definitions — commerce category group.
 *
 * Carved verbatim from Mcpwp_MCP_Pro_Tools::get_tools() (G2 split). Behavior-identical.
 *
 * @package mumcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Mcpwp_Pro_Tools_Commerce_Trait {

	/**
	 * @return array
	 */
	private function get_woocommerce_pro_tools() {
		$pro_tools = array();
		// WooCommerce Tools
		// =====================================================================

		$pro_tools[] = $this->define_tool(
			'wc_status',
			'Get WooCommerce status: version, currency, tax settings, product/order counts',
			array()
		);

		$pro_tools[] = $this->define_tool(
			'wc_list_products',
			'List WooCommerce products with price, stock, SKU, categories, tags. Supports filtering by type, category, tag, SKU, stock status, and search.',
			array(
				'per_page'     => array(
					'type'        => 'number',
					'description' => 'Items per page (default 50, max 100)',
				),
				'page'         => array(
					'type'        => 'number',
					'description' => 'Page number',
				),
				'status'       => array(
					'type'        => 'string',
					'description' => 'Product status: publish, draft, pending, private, any',
				),
				'type'         => array(
					'type'        => 'string',
					'description' => 'Product type: simple, variable, grouped, external',
				),
				'category'     => array(
					'type'        => 'string',
					'description' => 'Filter by category slug',
				),
				'tag'          => array(
					'type'        => 'string',
					'description' => 'Filter by tag slug',
				),
				'search'       => array(
					'type'        => 'string',
					'description' => 'Search term',
				),
				'sku'          => array(
					'type'        => 'string',
					'description' => 'Exact SKU match',
				),
				'stock_status' => array(
					'type'        => 'string',
					'description' => 'Stock status: instock, outofstock, onbackorder',
				),
				'orderby'      => array(
					'type'        => 'string',
					'description' => 'Order by: date, title, price, popularity, rating',
				),
				'order'        => array(
					'type'        => 'string',
					'description' => 'Sort order: ASC or DESC',
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wc_get_product',
			'Get a single WooCommerce product with full details: description, images, attributes, dimensions, variations (for variable products)',
			array(
				'id' => array(
					'type'        => 'number',
					'description' => 'Product ID',
					'required'    => true,
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wc_list_product_archetypes',
			'List stored WooCommerce product archetypes. Use archetypes to standardize repeatable product classes like simple products, variable products, digital products, bundles, or course products.',
			array(
				'archetype_class' => array(
					'type'        => 'string',
					'description' => 'Optional archetype class such as simple_product, variable_product, digital_product, bundle',
				),
				'product_type' => array(
					'type'        => 'string',
					'description' => 'Optional WooCommerce product type filter: simple, variable, grouped, external',
				),
				'archetype_style' => array(
					'type'        => 'string',
					'description' => 'Optional archetype style or variant label',
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wc_get_product_archetype',
			'Get a single WooCommerce product archetype with its stored field pattern.',
			array(
				'id' => array(
					'type'        => 'number',
					'description' => 'Product archetype ID',
					'required'    => true,
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wc_create_product_archetype',
			'Create a WooCommerce product archetype. Archetypes store a canonical product field pattern that can later be applied to a new or existing product.',
			array(
				'name' => array(
					'type'        => 'string',
					'description' => 'Archetype name',
					'required'    => true,
				),
				'archetype_class' => array(
					'type'        => 'string',
					'description' => 'Archetype class such as simple_product, variable_product, digital_product, bundle',
					'required'    => true,
				),
				'archetype_style' => array(
					'type'        => 'string',
					'description' => 'Optional style or variant label',
				),
				'product_type' => array(
					'type'        => 'string',
					'description' => 'WooCommerce product type: simple, variable, grouped, external',
					'default'     => 'simple',
				),
				'description' => array(
					'type'        => 'string',
					'description' => 'Default long description',
				),
				'short_description' => array(
					'type'        => 'string',
					'description' => 'Default short description',
				),
				'regular_price' => array(
					'type'        => 'string',
					'description' => 'Default regular price',
				),
				'sale_price' => array(
					'type'        => 'string',
					'description' => 'Default sale price',
				),
				'categories' => array(
					'type'        => 'array',
					'description' => 'Default categories',
				),
				'tags' => array(
					'type'        => 'array',
					'description' => 'Default tags',
				),
				'virtual' => array(
					'type'        => 'boolean',
					'description' => 'Default virtual flag',
				),
				'downloadable' => array(
					'type'        => 'boolean',
					'description' => 'Default downloadable flag',
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wc_apply_product_archetype',
			'Apply a stored WooCommerce product archetype to a new or existing product. Pass product_id to update an existing product, or pass name to create a new draft product from the archetype.',
			array(
				'id' => array(
					'type'        => 'number',
					'description' => 'Product archetype ID',
					'required'    => true,
				),
				'product_id' => array(
					'type'        => 'number',
					'description' => 'Existing product ID to update from the archetype',
				),
				'name' => array(
					'type'        => 'string',
					'description' => 'Product name when creating a new product from the archetype',
				),
				'status' => array(
					'type'        => 'string',
					'description' => 'Optional status override, defaults to draft for new products',
				),
				'description' => array(
					'type'        => 'string',
					'description' => 'Optional description override',
				),
				'short_description' => array(
					'type'        => 'string',
					'description' => 'Optional short description override',
				),
				'regular_price' => array(
					'type'        => 'string',
					'description' => 'Optional regular price override',
				),
				'sale_price' => array(
					'type'        => 'string',
					'description' => 'Optional sale price override',
				),
				'categories' => array(
					'type'        => 'array',
					'description' => 'Optional category override',
				),
				'tags' => array(
					'type'        => 'array',
					'description' => 'Optional tag override',
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wc_create_product',
			'Create a WooCommerce product. Supports simple, variable, grouped, and external product types.',
			array(
				'name'              => array(
					'type'        => 'string',
					'description' => 'Product name',
					'required'    => true,
				),
				'type'              => array(
					'type'        => 'string',
					'description' => 'Product type: simple (default), variable, grouped, external',
				),
				'status'            => array(
					'type'        => 'string',
					'description' => 'Product status: publish (default), draft, pending, private',
				),
				'description'       => array(
					'type'        => 'string',
					'description' => 'Full product description (HTML)',
				),
				'short_description' => array(
					'type'        => 'string',
					'description' => 'Short description (HTML)',
				),
				'sku'               => array(
					'type'        => 'string',
					'description' => 'Product SKU',
				),
				'regular_price'     => array(
					'type'        => 'string',
					'description' => 'Regular price',
				),
				'sale_price'        => array(
					'type'        => 'string',
					'description' => 'Sale price',
				),
				'manage_stock'      => array(
					'type'        => 'boolean',
					'description' => 'Enable stock management',
				),
				'stock_quantity'    => array(
					'type'        => 'number',
					'description' => 'Stock quantity (requires manage_stock: true)',
				),
				'stock_status'      => array(
					'type'        => 'string',
					'description' => 'Stock status: instock, outofstock, onbackorder',
				),
				'categories'        => array(
					'type'        => 'array',
					'description' => 'Category names or IDs (auto-creates if name not found)',
				),
				'tags'              => array(
					'type'        => 'array',
					'description' => 'Tag names or IDs (auto-creates if name not found)',
				),
				'weight'            => array(
					'type'        => 'string',
					'description' => 'Product weight',
				),
				'length'            => array(
					'type'        => 'string',
					'description' => 'Product length',
				),
				'width'             => array(
					'type'        => 'string',
					'description' => 'Product width',
				),
				'height'            => array(
					'type'        => 'string',
					'description' => 'Product height',
				),
				'image_id'          => array(
					'type'        => 'number',
					'description' => 'Main image attachment ID',
				),
				'gallery_image_ids' => array(
					'type'        => 'array',
					'description' => 'Gallery image attachment IDs',
				),
				'virtual'           => array(
					'type'        => 'boolean',
					'description' => 'Virtual product (no shipping)',
				),
				'downloadable'      => array(
					'type'        => 'boolean',
					'description' => 'Downloadable product',
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wc_update_product',
			'Update a WooCommerce product. Any field not provided is left unchanged.',
			array(
				'id'                => array(
					'type'        => 'number',
					'description' => 'Product ID',
					'required'    => true,
				),
				'name'              => array(
					'type'        => 'string',
					'description' => 'Product name',
				),
				'status'            => array(
					'type'        => 'string',
					'description' => 'Product status: publish, draft, pending, private',
				),
				'description'       => array(
					'type'        => 'string',
					'description' => 'Full product description (HTML)',
				),
				'short_description' => array(
					'type'        => 'string',
					'description' => 'Short description (HTML)',
				),
				'sku'               => array(
					'type'        => 'string',
					'description' => 'Product SKU',
				),
				'regular_price'     => array(
					'type'        => 'string',
					'description' => 'Regular price',
				),
				'sale_price'        => array(
					'type'        => 'string',
					'description' => 'Sale price',
				),
				'manage_stock'      => array(
					'type'        => 'boolean',
					'description' => 'Enable stock management',
				),
				'stock_quantity'    => array(
					'type'        => 'number',
					'description' => 'Stock quantity',
				),
				'stock_status'      => array(
					'type'        => 'string',
					'description' => 'Stock status: instock, outofstock, onbackorder',
				),
				'categories'        => array(
					'type'        => 'array',
					'description' => 'Category names or IDs (replaces existing)',
				),
				'tags'              => array(
					'type'        => 'array',
					'description' => 'Tag names or IDs (replaces existing)',
				),
				'weight'            => array(
					'type'        => 'string',
					'description' => 'Product weight',
				),
				'image_id'          => array(
					'type'        => 'number',
					'description' => 'Main image attachment ID',
				),
				'gallery_image_ids' => array(
					'type'        => 'array',
					'description' => 'Gallery image attachment IDs',
				),
				'virtual'           => array(
					'type'        => 'boolean',
					'description' => 'Virtual product',
				),
				'downloadable'      => array(
					'type'        => 'boolean',
					'description' => 'Downloadable product',
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wc_delete_product',
			'Delete a WooCommerce product. By default moves to trash; use force to permanently delete.',
			array(
				'id'    => array(
					'type'        => 'number',
					'description' => 'Product ID',
					'required'    => true,
				),
				'force' => array(
					'type'        => 'boolean',
					'description' => 'Permanently delete (bypass trash)',
					'default'     => false,
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wc_list_product_categories',
			'List all WooCommerce product categories (product_cat taxonomy) with ID, name, slug, parent, and product count',
			array()
		);

		$pro_tools[] = $this->define_tool(
			'wc_create_product_category',
			'Create a WooCommerce product category',
			array(
				'name'        => array(
					'type'        => 'string',
					'description' => 'Category name',
					'required'    => true,
				),
				'slug'        => array(
					'type'        => 'string',
					'description' => 'Category slug (auto-generated from name if omitted)',
				),
				'description' => array(
					'type'        => 'string',
					'description' => 'Category description',
				),
				'parent'      => array(
					'type'        => 'number',
					'description' => 'Parent category ID for nested categories',
				),
				'image_id'    => array(
					'type'        => 'number',
					'description' => 'Category thumbnail image attachment ID',
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wc_update_product_category',
			'Update a WooCommerce product category',
			array(
				'id'          => array(
					'type'        => 'number',
					'description' => 'Category (term) ID',
					'required'    => true,
				),
				'name'        => array(
					'type'        => 'string',
					'description' => 'Category name',
				),
				'slug'        => array(
					'type'        => 'string',
					'description' => 'Category slug',
				),
				'description' => array(
					'type'        => 'string',
					'description' => 'Category description',
				),
				'parent'      => array(
					'type'        => 'number',
					'description' => 'Parent category ID',
				),
				'image_id'    => array(
					'type'        => 'number',
					'description' => 'Category thumbnail image attachment ID',
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wc_list_product_tags',
			'List all WooCommerce product tags with ID, name, slug, and product count',
			array()
		);

		$pro_tools[] = $this->define_tool(
			'wc_list_orders',
			'List WooCommerce orders with status, totals, customer info. Supports filtering by status, customer, and date range.',
			array(
				'per_page' => array(
					'type'        => 'number',
					'description' => 'Items per page (default 50, max 100)',
				),
				'page'     => array(
					'type'        => 'number',
					'description' => 'Page number',
				),
				'status'   => array(
					'type'        => 'string',
					'description' => 'Order status: any, pending, processing, on-hold, completed, cancelled, refunded, failed',
				),
				'customer' => array(
					'type'        => 'number',
					'description' => 'Filter by customer ID',
				),
				'after'    => array(
					'type'        => 'string',
					'description' => 'Orders after date (ISO 8601)',
				),
				'before'   => array(
					'type'        => 'string',
					'description' => 'Orders before date (ISO 8601)',
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wc_get_order',
			'Get a single WooCommerce order with full details: items, billing/shipping addresses, notes, payment method',
			array(
				'id' => array(
					'type'        => 'number',
					'description' => 'Order ID',
					'required'    => true,
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wc_update_order',
			'Update a WooCommerce order status and/or add a note',
			array(
				'id'            => array(
					'type'        => 'number',
					'description' => 'Order ID',
					'required'    => true,
				),
				'status'        => array(
					'type'        => 'string',
					'description' => 'New order status: pending, processing, on-hold, completed, cancelled, refunded, failed',
				),
				'note'          => array(
					'type'        => 'string',
					'description' => 'Order note to add',
				),
				'note_customer' => array(
					'type'        => 'boolean',
					'description' => 'Send note to customer (default false)',
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wc_list_order_statuses',
			'List all available WooCommerce order statuses (including custom statuses)',
			array()
		);

		$pro_tools[] = $this->define_tool(
			'wc_list_customers',
			'List WooCommerce customers with order count and total spent',
			array(
				'per_page' => array(
					'type'        => 'number',
					'description' => 'Items per page (default 50, max 100)',
				),
				'page'     => array(
					'type'        => 'number',
					'description' => 'Page number',
				),
				'search'   => array(
					'type'        => 'string',
					'description' => 'Search by name, email, or username',
				),
				'orderby'  => array(
					'type'        => 'string',
					'description' => 'Order by: registered, display_name, user_login, user_email',
				),
				'order'    => array(
					'type'        => 'string',
					'description' => 'Sort order: ASC or DESC',
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wc_get_customer',
			'Get a single WooCommerce customer with billing/shipping addresses and order history summary',
			array(
				'id' => array(
					'type'        => 'number',
					'description' => 'Customer (user) ID',
					'required'    => true,
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wc_analytics',
			'Get WooCommerce analytics: sales totals, order counts, average order value, top-selling products, stock status, customers. Supports period (day/week/month/year) or custom date range.',
			array(
				'period'   => array(
					'type'        => 'string',
					'description' => 'Time period: day, week, month (default), year',
				),
				'date_min' => array(
					'type'        => 'string',
					'description' => 'Custom start date (ISO 8601, overrides period)',
				),
				'date_max' => array(
					'type'        => 'string',
					'description' => 'Custom end date (ISO 8601, overrides period)',
				),
			)
		);

		// =====================================================================
		return $pro_tools;
	}

	/**
	 * @return array
	 */
	private function get_learnpress_pro_tools() {
		$pro_tools = array();
		// LearnPress LMS Tools
		// =====================================================================

		$pro_tools[] = $this->define_tool(
			'wp_list_courses',
			'List LearnPress courses with price, duration, level, instructor, enrollment count, lesson/quiz count, and categories. Supports search, status, and category filters.',
			array(
				'per_page' => array(
					'type'        => 'number',
					'description' => 'Items per page (default 50, max 100)',
				),
				'page'     => array(
					'type'        => 'number',
					'description' => 'Page number',
				),
				'status'   => array(
					'type'        => 'string',
					'description' => 'Course status: publish, draft, pending, private, any',
				),
				'search'   => array(
					'type'        => 'string',
					'description' => 'Search term',
				),
				'category' => array(
					'type'        => 'string',
					'description' => 'Category slug or ID to filter by',
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_get_course',
			'Get full LearnPress course details: title, content, price, duration, level, requirements, target audiences, key features, FAQs, featured review, enrollment count, curriculum summary.',
			array(
				'id' => array(
					'type'        => 'number',
					'description' => 'Course ID',
					'required'    => true,
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_create_course',
			'Create a LearnPress course with title, content, price, duration (e.g. "10 week"), level (all/beginner/intermediate/advanced), requirements, target audiences, key features, FAQs, and categories.',
			array(
				'title'            => array(
					'type'        => 'string',
					'description' => 'Course title',
					'required'    => true,
				),
				'content'          => array(
					'type'        => 'string',
					'description' => 'Course description (HTML)',
				),
				'excerpt'          => array(
					'type'        => 'string',
					'description' => 'Course short description',
				),
				'status'           => array(
					'type'        => 'string',
					'description' => 'Post status: draft (default), publish, private',
					'default'     => 'draft',
				),
				'regular_price'    => array(
					'type'        => 'string',
					'description' => 'Regular price',
				),
				'sale_price'       => array(
					'type'        => 'string',
					'description' => 'Sale price',
				),
				'duration'         => array(
					'type'        => 'string',
					'description' => 'Course duration (e.g. "10 week", "3 month")',
				),
				'level'            => array(
					'type'        => 'string',
					'description' => 'Course level: all, beginner, intermediate, advanced',
				),
				'requirements'     => array(
					'type'        => 'array',
					'description' => 'Array of prerequisite strings',
				),
				'target_audiences' => array(
					'type'        => 'array',
					'description' => 'Array of target audience strings',
				),
				'key_features'     => array(
					'type'        => 'array',
					'description' => 'Array of key feature strings',
				),
				'faqs'             => array(
					'type'        => 'array',
					'description' => 'Array of [question, answer] pairs',
				),
				'featured_review'  => array(
					'type'        => 'string',
					'description' => 'Featured review text',
				),
				'categories'       => array(
					'type'        => 'array',
					'description' => 'Array of category names or IDs',
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_update_course',
			'Update a LearnPress course. Any field can be updated: title, content, price, duration, level, requirements, target audiences, key features, FAQs, categories.',
			array(
				'id'               => array(
					'type'        => 'number',
					'description' => 'Course ID',
					'required'    => true,
				),
				'title'            => array(
					'type'        => 'string',
					'description' => 'Course title',
				),
				'content'          => array(
					'type'        => 'string',
					'description' => 'Course description (HTML)',
				),
				'excerpt'          => array(
					'type'        => 'string',
					'description' => 'Course short description',
				),
				'status'           => array(
					'type'        => 'string',
					'description' => 'Post status',
				),
				'regular_price'    => array(
					'type'        => 'string',
					'description' => 'Regular price',
				),
				'sale_price'       => array(
					'type'        => 'string',
					'description' => 'Sale price',
				),
				'duration'         => array(
					'type'        => 'string',
					'description' => 'Course duration (e.g. "10 week")',
				),
				'level'            => array(
					'type'        => 'string',
					'description' => 'Course level: all, beginner, intermediate, advanced',
				),
				'requirements'     => array(
					'type'        => 'array',
					'description' => 'Array of prerequisite strings',
				),
				'target_audiences' => array(
					'type'        => 'array',
					'description' => 'Array of target audience strings',
				),
				'key_features'     => array(
					'type'        => 'array',
					'description' => 'Array of key feature strings',
				),
				'faqs'             => array(
					'type'        => 'array',
					'description' => 'Array of [question, answer] pairs',
				),
				'featured_review'  => array(
					'type'        => 'string',
					'description' => 'Featured review text',
				),
				'categories'       => array(
					'type'        => 'array',
					'description' => 'Array of category names or IDs',
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_get_curriculum',
			'Get the curriculum (sections and items) for a LearnPress course. Returns sections with their lessons and quizzes in order.',
			array(
				'id' => array(
					'type'        => 'number',
					'description' => 'Course ID',
					'required'    => true,
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_set_curriculum',
			'Set or replace the curriculum for a LearnPress course. Provide an array of sections, each with name, description, and items (lesson/quiz IDs with types). Lessons and quizzes must be created first.',
			array(
				'id'       => array(
					'type'        => 'number',
					'description' => 'Course ID',
					'required'    => true,
				),
				'sections' => array(
					'type'        => 'array',
					'description' => 'Array of section objects: {name, description, items: [{id, type: "lp_lesson"|"lp_quiz"}]}',
					'required'    => true,
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_list_lessons',
			'List LearnPress lessons with duration and preview status. Optionally filter by course_id.',
			array(
				'per_page'  => array(
					'type'        => 'number',
					'description' => 'Items per page (default 50, max 100)',
				),
				'page'      => array(
					'type'        => 'number',
					'description' => 'Page number',
				),
				'course_id' => array(
					'type'        => 'number',
					'description' => 'Filter lessons by course ID',
				),
				'search'    => array(
					'type'        => 'string',
					'description' => 'Search term',
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_create_lesson',
			'Create a LearnPress lesson with title, content, duration, and preview flag. After creating, add to a course curriculum using wp_set_curriculum.',
			array(
				'title'    => array(
					'type'        => 'string',
					'description' => 'Lesson title',
					'required'    => true,
				),
				'content'  => array(
					'type'        => 'string',
					'description' => 'Lesson content (HTML)',
				),
				'status'   => array(
					'type'        => 'string',
					'description' => 'Post status (default: publish)',
					'default'     => 'publish',
				),
				'duration' => array(
					'type'        => 'string',
					'description' => 'Lesson duration (e.g. "30 minute", "1 hour")',
				),
				'preview'  => array(
					'type'        => 'boolean',
					'description' => 'Allow free preview of this lesson',
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_update_lesson',
			'Update a LearnPress lesson: title, content, duration, preview flag.',
			array(
				'id'       => array(
					'type'        => 'number',
					'description' => 'Lesson ID',
					'required'    => true,
				),
				'title'    => array(
					'type'        => 'string',
					'description' => 'Lesson title',
				),
				'content'  => array(
					'type'        => 'string',
					'description' => 'Lesson content (HTML)',
				),
				'status'   => array(
					'type'        => 'string',
					'description' => 'Post status',
				),
				'duration' => array(
					'type'        => 'string',
					'description' => 'Lesson duration (e.g. "30 minute")',
				),
				'preview'  => array(
					'type'        => 'boolean',
					'description' => 'Allow free preview',
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_list_quizzes',
			'List LearnPress quizzes with duration, passing grade, and review settings. Optionally filter by course_id.',
			array(
				'per_page'  => array(
					'type'        => 'number',
					'description' => 'Items per page (default 50, max 100)',
				),
				'page'      => array(
					'type'        => 'number',
					'description' => 'Page number',
				),
				'course_id' => array(
					'type'        => 'number',
					'description' => 'Filter quizzes by course ID',
				),
				'search'    => array(
					'type'        => 'string',
					'description' => 'Search term',
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_create_quiz',
			'Create a LearnPress quiz with settings: duration (e.g. "40 minute"), passing grade, retake count, instant check, review. After creating, add to a course curriculum using wp_set_curriculum.',
			array(
				'title'          => array(
					'type'        => 'string',
					'description' => 'Quiz title',
					'required'    => true,
				),
				'content'        => array(
					'type'        => 'string',
					'description' => 'Quiz description (HTML)',
				),
				'status'         => array(
					'type'        => 'string',
					'description' => 'Post status (default: publish)',
					'default'     => 'publish',
				),
				'duration'       => array(
					'type'        => 'string',
					'description' => 'Quiz duration (e.g. "40 minute")',
				),
				'passing_grade'  => array(
					'type'        => 'string',
					'description' => 'Passing grade percentage (e.g. "80")',
				),
				'retake_count'   => array(
					'type'        => 'string',
					'description' => 'Number of allowed retakes (0 = unlimited)',
				),
				'instant_check'  => array(
					'type'        => 'string',
					'description' => 'Enable instant answer check: yes/no',
				),
				'review'         => array(
					'type'        => 'string',
					'description' => 'Allow review after completion: yes/no',
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_update_quiz',
			'Update a LearnPress quiz: title, content, duration, passing grade, retake count, instant check, review.',
			array(
				'id'             => array(
					'type'        => 'number',
					'description' => 'Quiz ID',
					'required'    => true,
				),
				'title'          => array(
					'type'        => 'string',
					'description' => 'Quiz title',
				),
				'content'        => array(
					'type'        => 'string',
					'description' => 'Quiz description (HTML)',
				),
				'status'         => array(
					'type'        => 'string',
					'description' => 'Post status',
				),
				'duration'       => array(
					'type'        => 'string',
					'description' => 'Quiz duration (e.g. "40 minute")',
				),
				'passing_grade'  => array(
					'type'        => 'string',
					'description' => 'Passing grade percentage',
				),
				'retake_count'   => array(
					'type'        => 'string',
					'description' => 'Number of allowed retakes',
				),
				'instant_check'  => array(
					'type'        => 'string',
					'description' => 'Enable instant answer check: yes/no',
				),
				'review'         => array(
					'type'        => 'string',
					'description' => 'Allow review after completion: yes/no',
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_get_quiz_questions',
			'Get all questions for a LearnPress quiz with their titles, content, and order.',
			array(
				'id' => array(
					'type'        => 'number',
					'description' => 'Quiz ID',
					'required'    => true,
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_list_course_categories',
			'List all LearnPress course categories with name, slug, description, parent, and course count.',
			array()
		);

		$pro_tools[] = $this->define_tool(
			'wp_create_course_category',
			'Create a new LearnPress course category. Returns the new category ID, slug, and URL.',
			array(
				'name'        => array(
					'type'        => 'string',
					'description' => 'Category name',
					'required'    => true,
				),
				'slug'        => array(
					'type'        => 'string',
					'description' => 'Category slug (auto-generated from name if omitted)',
				),
				'description' => array(
					'type'        => 'string',
					'description' => 'Category description',
				),
				'parent'      => array(
					'type'        => 'number',
					'description' => 'Parent category ID for hierarchical categories',
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_update_course_category',
			'Update a LearnPress course category name, slug, or description.',
			array(
				'id'          => array(
					'type'        => 'number',
					'description' => 'Category term ID',
					'required'    => true,
				),
				'name'        => array(
					'type'        => 'string',
					'description' => 'Category name',
				),
				'slug'        => array(
					'type'        => 'string',
					'description' => 'Category slug',
				),
				'description' => array(
					'type'        => 'string',
					'description' => 'Category description',
				),
				'parent'      => array(
					'type'        => 'number',
					'description' => 'Parent category ID',
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_delete_course_category',
			'Delete a LearnPress course category by term ID. Does not delete courses in the category.',
			array(
				'id' => array(
					'type'        => 'number',
					'description' => 'Category term ID',
					'required'    => true,
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_lms_stats',
			'Get LearnPress LMS dashboard statistics: total courses, lessons, quizzes, enrollments, categories, and revenue summary.',
			array()
		);

		// =====================================================================
		return $pro_tools;
	}

	/**
	 * @return array
	 */
	private function get_tp_events_pro_tools() {
		$pro_tools = array();
		// TP Events Tools
		// =====================================================================

		$pro_tools[] = $this->define_tool(
			'wp_list_events',
			'List ThimPress Events with date, time, location, price, and status. Supports search and status filters.',
			array(
				'per_page' => array(
					'type'        => 'number',
					'description' => 'Items per page (default 50, max 100)',
				),
				'page'     => array(
					'type'        => 'number',
					'description' => 'Page number',
				),
				'status'   => array(
					'type'        => 'string',
					'description' => 'Event status: publish, draft, pending, private, any',
				),
				'search'   => array(
					'type'        => 'string',
					'description' => 'Search term',
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_get_event',
			'Get full ThimPress Event details: title, content, dates, times, location, price, quantity, registration deadline, and iframe embed.',
			array(
				'id' => array(
					'type'        => 'number',
					'description' => 'Event ID',
					'required'    => true,
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_create_event',
			'Create a ThimPress Event with title, content, dates, times, location, price, quantity, and registration deadline.',
			array(
				'title'                 => array(
					'type'        => 'string',
					'description' => 'Event title',
					'required'    => true,
				),
				'content'               => array(
					'type'        => 'string',
					'description' => 'Event description (HTML)',
				),
				'excerpt'               => array(
					'type'        => 'string',
					'description' => 'Event short description',
				),
				'status'                => array(
					'type'        => 'string',
					'description' => 'Post status: draft (default), publish, private',
					'default'     => 'draft',
				),
				'date_start'            => array(
					'type'        => 'string',
					'description' => 'Start date (YYYY-MM-DD)',
				),
				'time_start'            => array(
					'type'        => 'string',
					'description' => 'Start time (HH:MM)',
				),
				'date_end'              => array(
					'type'        => 'string',
					'description' => 'End date (YYYY-MM-DD)',
				),
				'time_end'              => array(
					'type'        => 'string',
					'description' => 'End time (HH:MM)',
				),
				'location'              => array(
					'type'        => 'string',
					'description' => 'Event location',
				),
				'price'                 => array(
					'type'        => 'string',
					'description' => 'Ticket price',
				),
				'qty'                   => array(
					'type'        => 'string',
					'description' => 'Available quantity/seats',
				),
				'registration_end_date' => array(
					'type'        => 'string',
					'description' => 'Registration deadline date (YYYY-MM-DD)',
				),
				'registration_end_time' => array(
					'type'        => 'string',
					'description' => 'Registration deadline time (HH:MM)',
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_update_event',
			'Update a ThimPress Event: title, content, dates, times, location, price, quantity, registration deadline.',
			array(
				'id'                    => array(
					'type'        => 'number',
					'description' => 'Event ID',
					'required'    => true,
				),
				'title'                 => array(
					'type'        => 'string',
					'description' => 'Event title',
				),
				'content'               => array(
					'type'        => 'string',
					'description' => 'Event description (HTML)',
				),
				'excerpt'               => array(
					'type'        => 'string',
					'description' => 'Event short description',
				),
				'status'                => array(
					'type'        => 'string',
					'description' => 'Post status',
				),
				'date_start'            => array(
					'type'        => 'string',
					'description' => 'Start date (YYYY-MM-DD)',
				),
				'time_start'            => array(
					'type'        => 'string',
					'description' => 'Start time (HH:MM)',
				),
				'date_end'              => array(
					'type'        => 'string',
					'description' => 'End date (YYYY-MM-DD)',
				),
				'time_end'              => array(
					'type'        => 'string',
					'description' => 'End time (HH:MM)',
				),
				'location'              => array(
					'type'        => 'string',
					'description' => 'Event location',
				),
				'price'                 => array(
					'type'        => 'string',
					'description' => 'Ticket price',
				),
				'qty'                   => array(
					'type'        => 'string',
					'description' => 'Available quantity/seats',
				),
				'registration_end_date' => array(
					'type'        => 'string',
					'description' => 'Registration deadline date (YYYY-MM-DD)',
				),
				'registration_end_time' => array(
					'type'        => 'string',
					'description' => 'Registration deadline time (HH:MM)',
				),
			)
		);

		return $pro_tools;
	}
}
