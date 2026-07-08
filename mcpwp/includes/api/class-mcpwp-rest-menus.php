<?php
/**
 * Menus REST Controller
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Menus REST controller.
 *
 * Supports listing menus, creating menus, managing menu items (add, update,
 * remove, reorder), and assigning menus to theme locations.
 */
class Mcpwp_REST_Menus extends Mcpwp_REST_API {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		// List all menus.
		register_rest_route(
			$this->namespace,
			'/menus',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_menus' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Delete a menu.
		register_rest_route(
			$this->namespace,
			'/menus/(?P<menu_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_menu' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Assign menu to location.
		register_rest_route(
			$this->namespace,
			'/menus/assign-location',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'assign_menu_location' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'menu_id'  => array(
							'description' => __( 'Menu ID to assign.', 'mumcp' ),
							'type'        => 'integer',
							'required'    => true,
						),
						'location' => array(
							'description' => __( 'Theme menu location key (e.g., menu-1).', 'mumcp' ),
							'type'        => 'string',
							'required'    => true,
						),
					),
				),
			)
		);

		// List theme menu locations.
		register_rest_route(
			$this->namespace,
			'/menus/locations',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_locations' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Create and set up a menu in one call.
		register_rest_route(
			$this->namespace,
			'/menus/setup',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'setup_menu' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'name'      => array(
							'description' => __( 'Menu name.', 'mumcp' ),
							'type'        => 'string',
							'required'    => true,
						),
						'location'  => array(
							'description' => __( 'Theme menu location key to assign.', 'mumcp' ),
							'type'        => 'string',
						),
						'page_ids'  => array(
							'description' => __( 'Page IDs to add as menu items.', 'mumcp' ),
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'default'     => array(),
						),
						'overwrite' => array(
							'description' => __( 'If true, creates a new menu even if one with same name exists.', 'mumcp' ),
							'type'        => 'boolean',
							'default'     => false,
						),
					),
				),
			)
		);

		// List menu items.
		register_rest_route(
			$this->namespace,
			'/menus/(?P<menu_id>\d+)/items',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_menu_items' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add_menu_item' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'type'        => array(
							'description' => __( 'Item type: custom, post_type, or taxonomy.', 'mumcp' ),
							'type'        => 'string',
							'enum'        => array( 'custom', 'post_type', 'taxonomy' ),
							'default'     => 'custom',
						),
						'title'       => array(
							'description' => __( 'Menu item label.', 'mumcp' ),
							'type'        => 'string',
							'required'    => true,
						),
						'url'         => array(
							'description' => __( 'URL for custom links.', 'mumcp' ),
							'type'        => 'string',
						),
						'object'      => array(
							'description' => __( 'Object type: page, post, product, category, etc.', 'mumcp' ),
							'type'        => 'string',
						),
						'object_id'   => array(
							'description' => __( 'Object ID for post_type or taxonomy items.', 'mumcp' ),
							'type'        => 'integer',
						),
						'parent_id'   => array(
							'description' => __( 'Parent menu item ID (for sub-menus).', 'mumcp' ),
							'type'        => 'integer',
							'default'     => 0,
						),
						'position'    => array(
							'description' => __( 'Menu order position.', 'mumcp' ),
							'type'        => 'integer',
						),
						'classes'     => array(
							'description' => __( 'CSS classes for this item.', 'mumcp' ),
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'default'     => array(),
						),
						'target'      => array(
							'description' => __( 'Link target: _blank or _self.', 'mumcp' ),
							'type'        => 'string',
							'enum'        => array( '_blank', '_self' ),
						),
						'description' => array(
							'description' => __( 'Item description or tooltip.', 'mumcp' ),
							'type'        => 'string',
						),
					),
				),
			)
		);

		// Update a menu item.
		register_rest_route(
			$this->namespace,
			'/menus/(?P<menu_id>\d+)/items/(?P<item_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_menu_item' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'title'       => array(
							'description' => __( 'Menu item label.', 'mumcp' ),
							'type'        => 'string',
						),
						'url'         => array(
							'description' => __( 'URL (for custom links).', 'mumcp' ),
							'type'        => 'string',
						),
						'parent_id'   => array(
							'description' => __( 'Parent menu item ID.', 'mumcp' ),
							'type'        => 'integer',
						),
						'position'    => array(
							'description' => __( 'Menu order position.', 'mumcp' ),
							'type'        => 'integer',
						),
						'classes'     => array(
							'description' => __( 'CSS classes.', 'mumcp' ),
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
						),
						'target'      => array(
							'description' => __( 'Link target: _blank or _self.', 'mumcp' ),
							'type'        => 'string',
							'enum'        => array( '_blank', '_self' ),
						),
						'description' => array(
							'description' => __( 'Item description or tooltip.', 'mumcp' ),
							'type'        => 'string',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_menu_item' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Update/delete a menu item without menu_id (auto-resolve menu).
		register_rest_route(
			$this->namespace,
			'/menus/items/(?P<item_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_menu_item_auto' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'title'       => array(
							'description' => __( 'Menu item label.', 'mumcp' ),
							'type'        => 'string',
						),
						'url'         => array(
							'description' => __( 'URL (for custom links).', 'mumcp' ),
							'type'        => 'string',
						),
						'parent_id'   => array(
							'description' => __( 'Parent menu item ID.', 'mumcp' ),
							'type'        => 'integer',
						),
						'position'    => array(
							'description' => __( 'Menu order position.', 'mumcp' ),
							'type'        => 'integer',
						),
						'classes'     => array(
							'description' => __( 'CSS classes.', 'mumcp' ),
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
						),
						'target'      => array(
							'description' => __( 'Link target: _blank or _self.', 'mumcp' ),
							'type'        => 'string',
							'enum'        => array( '_blank', '_self' ),
						),
						'description' => array(
							'description' => __( 'Item description or tooltip.', 'mumcp' ),
							'type'        => 'string',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_menu_item_auto' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Bulk reorder menu items.
		register_rest_route(
			$this->namespace,
			'/menus/(?P<menu_id>\d+)/items/reorder',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'reorder_menu_items' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'items' => array(
							'description' => __( 'Array of {id, position, parent_id} objects.', 'mumcp' ),
							'type'        => 'array',
							'required'    => true,
							'items'       => array( 'type' => 'object' ),
						),
					),
				),
			)
		);
	}

	/**
	 * List all menus.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response Response.
	 */
	public function list_menus( $request ) {
		$this->log_activity( 'list_menus', $request );

		$menus = wp_get_nav_menus();
		$out   = array();

		foreach ( $menus as $menu ) {
			$out[] = array(
				'id'    => (int) $menu->term_id,
				'name'  => (string) $menu->name,
				'slug'  => (string) $menu->slug,
				'count' => (int) $menu->count,
			);
		}

		return $this->success_response( array( 'menus' => $out ) );
	}

	/**
	 * Delete a menu.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function delete_menu( $request ) {
		$this->log_activity( 'delete_menu', $request );

		$menu_id = absint( $request->get_param( 'menu_id' ) );
		$menu    = wp_get_nav_menu_object( $menu_id );

		if ( ! $menu ) {
			return $this->error_response(
				'menu_not_found',
				__( 'Menu not found.', 'mumcp' ),
				404
			);
		}

		$name   = $menu->name;
		$result = wp_delete_nav_menu( $menu_id );

		if ( is_wp_error( $result ) ) {
			return $this->error_response(
				'delete_failed',
				$result->get_error_message(),
				500
			);
		}

		if ( ! $result ) {
			return $this->error_response(
				'delete_failed',
				__( 'Failed to delete menu.', 'mumcp' ),
				500
			);
		}

		return $this->success_response(
			array(
				'success' => true,
				'deleted' => (int) $menu_id,
				'name'    => $name,
			)
		);
	}

	/**
	 * Assign an existing menu to a theme location.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function assign_menu_location( $request ) {
		$this->log_activity( 'assign_menu_location', $request );

		$menu_id  = absint( $request->get_param( 'menu_id' ) );
		$location = sanitize_key( (string) $request->get_param( 'location' ) );

		if ( '' === $location ) {
			return $this->error_response(
				'missing_location',
				__( 'Location key is required.', 'mumcp' ),
				400
			);
		}

		$menu = wp_get_nav_menu_object( $menu_id );
		if ( ! $menu ) {
			return $this->error_response(
				'menu_not_found',
				__( 'Menu not found.', 'mumcp' ),
				404
			);
		}

		$registered = get_registered_nav_menus();
		if ( ! isset( $registered[ $location ] ) ) {
			$available = array_keys( $registered );
			return $this->error_response(
				'invalid_location',
				__( 'Unknown theme menu location.', 'mumcp' ),
				400,
				array( 'available_locations' => $available )
			);
		}

		$locations              = get_nav_menu_locations();
		$locations[ $location ] = $menu_id;
		set_theme_mod( 'nav_menu_locations', $locations );

		return $this->success_response(
			array(
				'success'   => true,
				'menu_id'   => (int) $menu_id,
				'menu_name' => (string) $menu->name,
				'location'  => $location,
				'label'     => $registered[ $location ],
			)
		);
	}

	/**
	 * List theme menu locations.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response Response.
	 */
	public function list_locations( $request ) {
		$this->log_activity( 'list_menu_locations', $request );

		$locations = get_registered_nav_menus();
		$current   = get_nav_menu_locations();

		$out = array();
		foreach ( (array) $locations as $key => $label ) {
			$menu_id = isset( $current[ $key ] ) ? absint( $current[ $key ] ) : 0;
			$menu    = $menu_id ? wp_get_nav_menu_object( $menu_id ) : null;

			$out[] = array(
				'key'        => (string) $key,
				'label'      => (string) $label,
				'assigned'   => $menu_id > 0,
				'menu_id'    => $menu_id,
				'menu_name'  => $menu && isset( $menu->name ) ? (string) $menu->name : null,
				'menu_slug'  => $menu && isset( $menu->slug ) ? (string) $menu->slug : null,
				'menu_count' => $menu && isset( $menu->count ) ? (int) $menu->count : null,
			);
		}

		return $this->success_response(
			array(
				'locations' => $out,
			)
		);
	}

	/**
	 * Create and populate a menu, optionally assigning it to a location.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function setup_menu( $request ) {
		$this->log_activity( 'setup_menu', $request );

		$name      = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$location  = sanitize_key( (string) $request->get_param( 'location' ) );
		$page_ids  = (array) $request->get_param( 'page_ids' );
		$overwrite = (bool) $request->get_param( 'overwrite' );

		if ( '' === $name ) {
			return $this->error_response(
				'missing_name',
				__( 'Menu name is required.', 'mumcp' ),
				400
			);
		}

		$existing = wp_get_nav_menu_object( $name );

		if ( $existing && $overwrite ) {
			// Clear existing items before repopulating — prevents duplicate accumulation
			// when the tool is called more than once with the same menu name.
			$menu_id       = (int) $existing->term_id;
			$existing_items = wp_get_nav_menu_items( $menu_id );
			if ( is_array( $existing_items ) ) {
				foreach ( $existing_items as $item ) {
					wp_delete_post( $item->ID, true );
				}
			}
		} elseif ( $existing ) {
			$menu_id = (int) $existing->term_id;
		} else {
			$menu_id = 0;
		}

		if ( ! $menu_id ) {
			$menu_id = wp_create_nav_menu( $name );
		}

		if ( is_wp_error( $menu_id ) ) {
			return $this->error_response(
				'menu_create_failed',
				$menu_id->get_error_message(),
				500
			);
		}

		$added = array();
		foreach ( $page_ids as $page_id ) {
			$page_id = absint( $page_id );
			if ( $page_id <= 0 ) {
				continue;
			}

			$page = get_post( $page_id );
			if ( ! $page || 'page' !== $page->post_type ) {
				continue;
			}

			$item_id = wp_update_nav_menu_item(
				$menu_id,
				0,
				array(
					'menu-item-object-id' => $page_id,
					'menu-item-object'    => 'page',
					'menu-item-type'      => 'post_type',
					'menu-item-status'    => 'publish',
				)
			);

			if ( ! is_wp_error( $item_id ) ) {
				$added[] = array(
					'item_id' => (int) $item_id,
					'page_id' => (int) $page_id,
					'title'   => (string) $page->post_title,
				);
			}
		}

		$assigned = false;
		if ( '' !== $location ) {
			$registered = get_registered_nav_menus();
			if ( isset( $registered[ $location ] ) ) {
				$locations              = get_nav_menu_locations();
				$locations[ $location ] = $menu_id;
				set_theme_mod( 'nav_menu_locations', $locations );
				$assigned = true;
			}
		}

		$menu = wp_get_nav_menu_object( $menu_id );

		return $this->success_response(
			array(
				'menu'     => array(
					'id'    => (int) $menu_id,
					'name'  => $menu && isset( $menu->name ) ? (string) $menu->name : $name,
					'slug'  => $menu && isset( $menu->slug ) ? (string) $menu->slug : null,
					'count' => $menu && isset( $menu->count ) ? (int) $menu->count : null,
				),
				'added'    => $added,
				'location' => array(
					'key'      => '' !== $location ? $location : null,
					'assigned' => $assigned,
				),
			)
		);
	}

	/**
	 * List items in a menu.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function list_menu_items( $request ) {
		$this->log_activity( 'list_menu_items', $request );

		$menu_id = absint( $request->get_param( 'menu_id' ) );
		$menu    = wp_get_nav_menu_object( $menu_id );

		if ( ! $menu ) {
			return $this->error_response(
				'menu_not_found',
				__( 'Menu not found.', 'mumcp' ),
				404
			);
		}

		$items = wp_get_nav_menu_items( $menu_id );

		if ( false === $items ) {
			return $this->error_response(
				'menu_items_error',
				__( 'Could not retrieve menu items.', 'mumcp' ),
				500
			);
		}

		$out = array();
		foreach ( $items as $item ) {
			$out[] = $this->format_menu_item( $item );
		}

		return $this->success_response(
			array(
				'menu'  => array(
					'id'   => (int) $menu->term_id,
					'name' => (string) $menu->name,
				),
				'items' => $out,
			)
		);
	}

	/**
	 * Add a menu item.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function add_menu_item( $request ) {
		$this->log_activity( 'add_menu_item', $request );

		$menu_id = absint( $request->get_param( 'menu_id' ) );
		$menu    = wp_get_nav_menu_object( $menu_id );

		if ( ! $menu ) {
			return $this->error_response(
				'menu_not_found',
				__( 'Menu not found.', 'mumcp' ),
				404
			);
		}

		$type        = sanitize_text_field( (string) $request->get_param( 'type' ) );
		$title       = sanitize_text_field( (string) $request->get_param( 'title' ) );
		$url         = esc_url_raw( (string) $request->get_param( 'url' ) );
		$object      = sanitize_key( (string) $request->get_param( 'object' ) );
		$object_id   = absint( $request->get_param( 'object_id' ) );
		$parent_id   = absint( $request->get_param( 'parent_id' ) );
		$position    = $request->get_param( 'position' );
		$classes     = (array) $request->get_param( 'classes' );
		$target      = $request->get_param( 'target' );
		$description = $request->get_param( 'description' );

		if ( '' === $title ) {
			return $this->error_response(
				'missing_title',
				__( 'Menu item title is required.', 'mumcp' ),
				400
			);
		}

		$item_data = array(
			'menu-item-title'     => $title,
			'menu-item-status'    => 'publish',
			'menu-item-parent-id' => $parent_id,
			'menu-item-classes'   => implode( ' ', array_map( 'sanitize_html_class', $classes ) ),
		);

		if ( null !== $target ) {
			$item_data['menu-item-target'] = sanitize_text_field( $target );
		}

		if ( null !== $description ) {
			$item_data['menu-item-description'] = sanitize_text_field( $description );
		}

		if ( null !== $position ) {
			$item_data['menu-item-position'] = absint( $position );
		}

		if ( 'custom' === $type || '' === $type ) {
			if ( '' === $url ) {
				return $this->error_response(
					'missing_url',
					__( 'URL is required for custom link items.', 'mumcp' ),
					400
				);
			}
			$item_data['menu-item-type'] = 'custom';
			$item_data['menu-item-url']  = $url;
		} elseif ( 'post_type' === $type ) {
			if ( '' === $object || $object_id <= 0 ) {
				return $this->error_response(
					'missing_object',
					__( 'Object and object_id are required for post_type items.', 'mumcp' ),
					400
				);
			}
			$item_data['menu-item-type']      = 'post_type';
			$item_data['menu-item-object']    = $object;
			$item_data['menu-item-object-id'] = $object_id;
		} elseif ( 'taxonomy' === $type ) {
			if ( '' === $object || $object_id <= 0 ) {
				return $this->error_response(
					'missing_object',
					__( 'Object and object_id are required for taxonomy items.', 'mumcp' ),
					400
				);
			}
			$item_data['menu-item-type']      = 'taxonomy';
			$item_data['menu-item-object']    = $object;
			$item_data['menu-item-object-id'] = $object_id;
		}

		$item_id = wp_update_nav_menu_item( $menu_id, 0, $item_data );

		if ( is_wp_error( $item_id ) ) {
			return $this->error_response(
				'add_item_failed',
				$item_id->get_error_message(),
				500
			);
		}

		$nav_item = wp_setup_nav_menu_item( get_post( $item_id ) );

		return $this->success_response(
			array(
				'success' => true,
				'item'    => $this->format_menu_item( $nav_item ),
			),
			201
		);
	}

	/**
	 * Update a menu item.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function update_menu_item( $request ) {
		$this->log_activity( 'update_menu_item', $request );

		$menu_id = absint( $request->get_param( 'menu_id' ) );
		$item_id = absint( $request->get_param( 'item_id' ) );

		$menu = wp_get_nav_menu_object( $menu_id );
		if ( ! $menu ) {
			return $this->error_response(
				'menu_not_found',
				sprintf(
					/* translators: %d: menu ID */
					__( 'Menu %d not found. Call wp_list_menus to get valid menu IDs.', 'mumcp' ),
					$menu_id
				),
				404
			);
		}

		$existing = get_post( $item_id );
		if ( ! $existing || 'nav_menu_item' !== $existing->post_type ) {
			return $this->error_response(
				'item_not_found',
				sprintf(
					/* translators: %d: item ID */
					__( 'Menu item %d not found. Call wp_list_menu_items with the menu_id to get valid item IDs.', 'mumcp' ),
					$item_id
				),
				404
			);
		}

		// Build update data from existing item, overriding with provided params.
		$nav_item  = wp_setup_nav_menu_item( $existing );
		$item_data = array(
			'menu-item-title'       => $nav_item->title,
			'menu-item-url'         => $nav_item->url,
			'menu-item-type'        => $nav_item->type,
			'menu-item-object'      => $nav_item->object,
			'menu-item-object-id'   => $nav_item->object_id,
			'menu-item-parent-id'   => $nav_item->menu_item_parent,
			'menu-item-position'    => $nav_item->menu_order,
			'menu-item-status'      => 'publish',
			'menu-item-classes'     => is_array( $nav_item->classes ) ? implode( ' ', $nav_item->classes ) : '',
			'menu-item-target'      => isset( $nav_item->target ) ? (string) $nav_item->target : '',
			'menu-item-description' => isset( $nav_item->description ) ? (string) $nav_item->description : '',
		);

		$title = $request->get_param( 'title' );
		if ( null !== $title ) {
			$item_data['menu-item-title'] = sanitize_text_field( $title );
		}

		$url = $request->get_param( 'url' );
		if ( null !== $url ) {
			$item_data['menu-item-url'] = esc_url_raw( $url );
		}

		$parent_id = $request->get_param( 'parent_id' );
		if ( null !== $parent_id ) {
			$item_data['menu-item-parent-id'] = absint( $parent_id );
		}

		$position = $request->get_param( 'position' );
		if ( null !== $position ) {
			$item_data['menu-item-position'] = absint( $position );
		}

		$classes = $request->get_param( 'classes' );
		if ( null !== $classes ) {
			$item_data['menu-item-classes'] = implode( ' ', array_map( 'sanitize_html_class', (array) $classes ) );
		}

		$target = $request->get_param( 'target' );
		if ( null !== $target ) {
			$item_data['menu-item-target'] = sanitize_text_field( $target );
		}

		$description = $request->get_param( 'description' );
		if ( null !== $description ) {
			$item_data['menu-item-description'] = sanitize_text_field( $description );
		}

		$result = wp_update_nav_menu_item( $menu_id, $item_id, $item_data );

		if ( is_wp_error( $result ) ) {
			return $this->error_response(
				'update_item_failed',
				$result->get_error_message(),
				500
			);
		}

		$updated = wp_setup_nav_menu_item( get_post( $result ) );

		return $this->success_response(
			array(
				'success' => true,
				'item'    => $this->format_menu_item( $updated ),
			)
		);
	}

	/**
	 * Delete a menu item.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function delete_menu_item( $request ) {
		$this->log_activity( 'delete_menu_item', $request );

		$item_id = absint( $request->get_param( 'item_id' ) );

		$existing = get_post( $item_id );
		if ( ! $existing || 'nav_menu_item' !== $existing->post_type ) {
			return $this->error_response(
				'item_not_found',
				__( 'Menu item not found.', 'mumcp' ),
				404
			);
		}

		$deleted = wp_delete_post( $item_id, true );

		if ( ! $deleted ) {
			return $this->error_response(
				'delete_failed',
				__( 'Failed to delete menu item.', 'mumcp' ),
				500
			);
		}

		return $this->success_response(
			array(
				'success' => true,
				'deleted' => (int) $item_id,
			)
		);
	}

	/**
	 * Bulk reorder menu items.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function reorder_menu_items( $request ) {
		$this->log_activity( 'reorder_menu_items', $request );

		$menu_id = absint( $request->get_param( 'menu_id' ) );
		$menu    = wp_get_nav_menu_object( $menu_id );

		if ( ! $menu ) {
			return $this->error_response(
				'menu_not_found',
				__( 'Menu not found.', 'mumcp' ),
				404
			);
		}

		$items = (array) $request->get_param( 'items' );

		if ( empty( $items ) ) {
			return $this->error_response(
				'missing_items',
				__( 'Items array is required.', 'mumcp' ),
				400
			);
		}

		$updated = array();
		$errors  = array();

		foreach ( $items as $item ) {
			$id = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
			if ( $id <= 0 ) {
				continue;
			}

			$existing = get_post( $id );
			if ( ! $existing || 'nav_menu_item' !== $existing->post_type ) {
				$errors[] = array(
					'id'    => $id,
					'error' => 'Item not found.',
				);
				continue;
			}

			$nav_item  = wp_setup_nav_menu_item( $existing );
			$item_data = array(
				'menu-item-title'     => $nav_item->title,
				'menu-item-url'       => $nav_item->url,
				'menu-item-type'      => $nav_item->type,
				'menu-item-object'    => $nav_item->object,
				'menu-item-object-id' => $nav_item->object_id,
				'menu-item-status'    => 'publish',
				'menu-item-parent-id' => isset( $item['parent_id'] ) ? absint( $item['parent_id'] ) : $nav_item->menu_item_parent,
				'menu-item-position'  => isset( $item['position'] ) ? absint( $item['position'] ) : $nav_item->menu_order,
				'menu-item-classes'   => is_array( $nav_item->classes ) ? implode( ' ', $nav_item->classes ) : '',
			);

			$result = wp_update_nav_menu_item( $menu_id, $id, $item_data );

			if ( is_wp_error( $result ) ) {
				$errors[] = array(
					'id'    => $id,
					'error' => $result->get_error_message(),
				);
			} else {
				$updated[] = $id;
			}
		}

		return $this->success_response(
			array(
				'success' => true,
				'updated' => $updated,
				'errors'  => $errors,
			)
		);
	}

	/**
	 * Resolve menu ID from a nav_menu_item post ID.
	 *
	 * @param int $item_id Menu item (post) ID.
	 * @return int|WP_Error Menu term ID or error.
	 */
	private function resolve_menu_for_item( $item_id ) {
		$item_id = absint( $item_id );

		$existing = get_post( $item_id );
		if ( ! $existing || 'nav_menu_item' !== $existing->post_type ) {
			return new WP_Error(
				'item_not_found',
				__( 'Menu item not found.', 'mumcp' ),
				array( 'status' => 404 )
			);
		}

		$menus = wp_get_object_terms( $item_id, 'nav_menu' );
		if ( is_wp_error( $menus ) || empty( $menus ) ) {
			return new WP_Error(
				'menu_not_found',
				__( 'Could not determine which menu this item belongs to.', 'mumcp' ),
				array( 'status' => 404 )
			);
		}

		return (int) $menus[0]->term_id;
	}

	/**
	 * Update a menu item without requiring menu_id (auto-resolve).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function update_menu_item_auto( $request ) {
		$item_id = absint( $request->get_param( 'item_id' ) );
		$menu_id = $this->resolve_menu_for_item( $item_id );

		if ( is_wp_error( $menu_id ) ) {
			return $this->error_response(
				$menu_id->get_error_code(),
				$menu_id->get_error_message(),
				$menu_id->get_error_data()['status'] ?? 404
			);
		}

		$request->set_param( 'menu_id', $menu_id );
		return $this->update_menu_item( $request );
	}

	/**
	 * Delete a menu item without requiring menu_id (auto-resolve).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function delete_menu_item_auto( $request ) {
		$item_id = absint( $request->get_param( 'item_id' ) );
		$menu_id = $this->resolve_menu_for_item( $item_id );

		if ( is_wp_error( $menu_id ) ) {
			return $this->error_response(
				$menu_id->get_error_code(),
				$menu_id->get_error_message(),
				$menu_id->get_error_data()['status'] ?? 404
			);
		}

		$request->set_param( 'menu_id', $menu_id );
		return $this->delete_menu_item( $request );
	}

	/**
	 * Format a menu item for API response.
	 *
	 * @param object $item WP_Post nav menu item.
	 * @return array Formatted item.
	 */
	private function format_menu_item( $item ) {
		return array(
			'id'          => (int) $item->ID,
			'title'       => (string) $item->title,
			'url'         => (string) $item->url,
			'type'        => (string) $item->type,
			'object'      => (string) $item->object,
			'object_id'   => (int) $item->object_id,
			'parent'      => (int) $item->menu_item_parent,
			'position'    => (int) $item->menu_order,
			'classes'     => is_array( $item->classes ) ? array_values( array_filter( $item->classes ) ) : array(),
			'target'      => isset( $item->target ) ? (string) $item->target : '',
			'description' => isset( $item->description ) ? (string) $item->description : '',
		);
	}
}
