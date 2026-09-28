<?php
/**
 * Site Manager
 *
 * @package MCPWP_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site management functionality.
 *
 * Handles menus, site settings, theme customizer, and global options.
 */
class Mcpwp_Site_Manager {

	// =========================================================================
	// WORDPRESS MENUS
	// =========================================================================

	/**
	 * Get all registered menus.
	 *
	 * @return array Menus list.
	 */
	public function get_menus() {
		$menus = wp_get_nav_menus();
		$result = array();

		foreach ( $menus as $menu ) {
			$result[] = $this->format_menu( $menu );
		}

		return $result;
	}

	/**
	 * Get menu locations.
	 *
	 * @return array Menu locations with assigned menus.
	 */
	public function get_menu_locations() {
		$locations = get_registered_nav_menus();
		$assigned = get_nav_menu_locations();

		$result = array();
		foreach ( $locations as $location => $description ) {
			$menu_id = isset( $assigned[ $location ] ) ? $assigned[ $location ] : 0;
			$menu = $menu_id ? wp_get_nav_menu_object( $menu_id ) : null;

			$result[ $location ] = array(
				'location'    => $location,
				'description' => $description,
				'menu_id'     => $menu_id,
				'menu_name'   => $menu ? $menu->name : null,
			);
		}

		return $result;
	}

	/**
	 * Get single menu with items.
	 *
	 * @param int|string $menu_id Menu ID, slug, or name.
	 * @return array|WP_Error Menu data.
	 */
	public function get_menu( $menu_id ) {
		$menu = wp_get_nav_menu_object( $menu_id );

		if ( ! $menu ) {
			return new WP_Error( 'not_found', __( 'Menu not found.', 'mcpwp' ) );
		}

		$items = wp_get_nav_menu_items( $menu->term_id );
		$formatted_items = array();

		if ( $items ) {
			foreach ( $items as $item ) {
				$formatted_items[] = $this->format_menu_item( $item );
			}
		}

		$data = $this->format_menu( $menu );
		$data['items'] = $formatted_items;

		return $data;
	}

	/**
	 * Create a new menu.
	 *
	 * @param array $data Menu data.
	 * @return array|WP_Error Created menu.
	 */
	public function create_menu( $data ) {
		$name = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';

		if ( empty( $name ) ) {
			return new WP_Error( 'missing_name', __( 'Menu name is required.', 'mcpwp' ) );
		}

		$menu_id = wp_create_nav_menu( $name );

		if ( is_wp_error( $menu_id ) ) {
			return $menu_id;
		}

		// Assign to location if specified.
		if ( ! empty( $data['location'] ) ) {
			$locations = get_nav_menu_locations();
			$locations[ sanitize_key( $data['location'] ) ] = $menu_id;
			set_theme_mod( 'nav_menu_locations', $locations );
		}

		// Add items if provided, collecting item-level failures so the caller
		// can detect a partially-built menu instead of a silent success.
		$item_errors = array();
		if ( ! empty( $data['items'] ) && is_array( $data['items'] ) ) {
			$object_visible = isset( $data['item_object_visible'] ) && is_callable( $data['item_object_visible'] ) ? $data['item_object_visible'] : null;
			foreach ( array_values( $data['items'] ) as $index => $item ) {
				$result = $this->add_menu_item( $menu_id, $item, $object_visible );
				if ( is_wp_error( $result ) ) {
					$item_errors[] = array(
						'index'   => (int) $index,
						'code'    => $result->get_error_code(),
						'message' => $result->get_error_message(),
					);
				}
			}
		}

		$menu = $this->get_menu( $menu_id );
		if ( ! empty( $item_errors ) && is_array( $menu ) ) {
			$menu['item_errors'] = $item_errors;
		}

		return $menu;
	}

	/**
	 * Update a menu.
	 *
	 * @param int   $menu_id Menu ID.
	 * @param array $data    Update data.
	 * @return array|WP_Error Updated menu.
	 */
	public function update_menu( $menu_id, $data ) {
		$menu = wp_get_nav_menu_object( $menu_id );

		if ( ! $menu ) {
			return new WP_Error( 'not_found', __( 'Menu not found.', 'mcpwp' ) );
		}

		// Update name if provided.
		if ( ! empty( $data['name'] ) ) {
			wp_update_nav_menu_object( $menu_id, array(
				'menu-name' => sanitize_text_field( $data['name'] ),
			) );
		}

		// Update location if provided.
		if ( isset( $data['location'] ) ) {
			$locations = get_nav_menu_locations();

			// Remove from current location.
			foreach ( $locations as $loc => $mid ) {
				if ( $mid === $menu_id ) {
					$locations[ $loc ] = 0;
				}
			}

			// Assign to new location.
			if ( ! empty( $data['location'] ) ) {
				$locations[ sanitize_key( $data['location'] ) ] = $menu_id;
			}

			set_theme_mod( 'nav_menu_locations', $locations );
		}

		return $this->get_menu( $menu_id );
	}

	/**
	 * Delete a menu.
	 *
	 * @param int $menu_id Menu ID.
	 * @return bool|WP_Error True on success.
	 */
	public function delete_menu( $menu_id ) {
		$menu = wp_get_nav_menu_object( $menu_id );

		if ( ! $menu ) {
			return new WP_Error( 'not_found', __( 'Menu not found.', 'mcpwp' ) );
		}

		$result = wp_delete_nav_menu( $menu_id );

		if ( ! $result ) {
			return new WP_Error( 'delete_failed', __( 'Failed to delete menu.', 'mcpwp' ) );
		}

		return true;
	}

	/**
	 * Add item to menu.
	 *
	 * @param int           $menu_id        Menu ID.
	 * @param array         $data           Item data.
	 * @param callable|null $object_visible Optional per-item resolver (the REST
	 *                                      layer's post_ref_item_visible()): a
	 *                                      post_type item whose object it rejects
	 *                                      — missing, or hidden from the caller —
	 *                                      is refused with one not-found answer
	 *                                      and never created (#1030 round-5).
	 * @return int|WP_Error Item ID.
	 */
	public function add_menu_item( $menu_id, $data, $object_visible = null ) {
		$menu = wp_get_nav_menu_object( $menu_id );

		if ( ! $menu ) {
			return new WP_Error( 'not_found', __( 'Menu not found.', 'mcpwp' ) );
		}

		$item_data = array(
			'menu-item-title'     => isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '',
			'menu-item-status'    => 'publish',
			'menu-item-position'  => isset( $data['position'] ) ? absint( $data['position'] ) : 0,
			'menu-item-parent-id' => isset( $data['parent_id'] ) ? absint( $data['parent_id'] ) : 0,
		);

		// Normalize the item type onto the documented custom|post_type|taxonomy
		// contract. Older callers passed the WordPress object directly as `type`
		// (post|page|category|tag); keep those as back-compat aliases so existing
		// integrations do not break.
		$type      = isset( $data['type'] ) ? sanitize_key( (string) $data['type'] ) : 'custom';
		$object    = isset( $data['object'] ) ? sanitize_key( (string) $data['object'] ) : '';
		$object_id = isset( $data['object_id'] ) ? absint( $data['object_id'] ) : 0;

		switch ( $type ) {
			case 'post':
			case 'page':
				$object = '' !== $object ? $object : $type;
				$type   = 'post_type';
				break;
			case 'category':
				$object = '' !== $object ? $object : 'category';
				$type   = 'taxonomy';
				break;
			case 'tag':
			case 'post_tag':
				$object = '' !== $object ? $object : 'post_tag';
				$type   = 'taxonomy';
				break;
		}

		switch ( $type ) {
			case 'post_type':
				if ( '' === $object || $object_id <= 0 ) {
					return new WP_Error( 'missing_object', __( 'object and object_id are required for post_type menu items.', 'mcpwp' ) );
				}
				if ( is_callable( $object_visible ) && ! call_user_func( $object_visible, $object_id ) ) {
					return new WP_Error( 'not_found', __( 'The requested resource was not found.', 'mcpwp' ) );
				}
				$item_data['menu-item-type']      = 'post_type';
				$item_data['menu-item-object']    = $object;
				$item_data['menu-item-object-id'] = $object_id;
				break;

			case 'taxonomy':
				if ( '' === $object || $object_id <= 0 ) {
					return new WP_Error( 'missing_object', __( 'object and object_id are required for taxonomy menu items.', 'mcpwp' ) );
				}
				$item_data['menu-item-type']      = 'taxonomy';
				$item_data['menu-item-object']    = $object;
				$item_data['menu-item-object-id'] = $object_id;
				break;

			case 'custom':
			default:
				$item_data['menu-item-type'] = 'custom';
				$item_data['menu-item-url']  = isset( $data['url'] ) ? esc_url_raw( $data['url'] ) : '';
				break;
		}

		// Additional attributes.
		if ( ! empty( $data['target'] ) ) {
			$item_data['menu-item-target'] = sanitize_text_field( $data['target'] );
		}
		if ( ! empty( $data['classes'] ) ) {
			$item_data['menu-item-classes'] = sanitize_text_field( $data['classes'] );
		}
		if ( ! empty( $data['description'] ) ) {
			$item_data['menu-item-description'] = sanitize_textarea_field( $data['description'] );
		}

		$item_id = wp_update_nav_menu_item( $menu_id, 0, $item_data );

		if ( is_wp_error( $item_id ) ) {
			return $item_id;
		}

		return $item_id;
	}

	/**
	 * Update menu item.
	 *
	 * @param int   $menu_id Menu ID.
	 * @param int   $item_id Item ID.
	 * @param array $data    Update data.
	 * @return int|WP_Error Item ID.
	 */
	public function update_menu_item( $menu_id, $item_id, $data ) {
		$item_data = array();

		if ( isset( $data['title'] ) ) {
			$item_data['menu-item-title'] = sanitize_text_field( $data['title'] );
		}
		if ( isset( $data['url'] ) ) {
			$item_data['menu-item-url'] = esc_url_raw( $data['url'] );
		}
		if ( isset( $data['position'] ) ) {
			$item_data['menu-item-position'] = absint( $data['position'] );
		}
		if ( isset( $data['parent_id'] ) ) {
			$item_data['menu-item-parent-id'] = absint( $data['parent_id'] );
		}
		if ( isset( $data['target'] ) ) {
			$item_data['menu-item-target'] = sanitize_text_field( $data['target'] );
		}
		if ( isset( $data['classes'] ) ) {
			$item_data['menu-item-classes'] = sanitize_text_field( $data['classes'] );
		}

		$result = wp_update_nav_menu_item( $menu_id, $item_id, $item_data );

		return $result;
	}

	/**
	 * Delete menu item.
	 *
	 * @param int $item_id Item ID.
	 * @return bool|WP_Error True on success.
	 */
	public function delete_menu_item( $item_id ) {
		$result = wp_delete_post( $item_id, true );

		if ( ! $result ) {
			return new WP_Error( 'delete_failed', __( 'Failed to delete menu item.', 'mcpwp' ) );
		}

		return true;
	}

	/**
	 * Format menu for response.
	 *
	 * @param WP_Term $menu Menu object.
	 * @return array Formatted menu.
	 */
	private function format_menu( $menu ) {
		$locations = get_nav_menu_locations();
		$assigned_locations = array();

		foreach ( $locations as $location => $menu_id ) {
			if ( $menu_id === $menu->term_id ) {
				$assigned_locations[] = $location;
			}
		}

		return array(
			'id'          => $menu->term_id,
			'name'        => $menu->name,
			'slug'        => $menu->slug,
			'description' => $menu->description,
			'count'       => $menu->count,
			'locations'   => $assigned_locations,
		);
	}

	/**
	 * Format menu item for response.
	 *
	 * @param WP_Post $item Menu item object.
	 * @return array Formatted item.
	 */
	private function format_menu_item( $item ) {
		return array(
			'id'          => $item->ID,
			'title'       => $item->title,
			'url'         => $item->url,
			'type'        => $item->type,
			'object'      => $item->object,
			'object_id'   => (int) $item->object_id,
			'parent_id'   => (int) $item->menu_item_parent,
			'position'    => (int) $item->menu_order,
			'target'      => $item->target,
			'classes'     => implode( ' ', array_filter( $item->classes ) ),
			'description' => $item->description,
		);
	}

	// =========================================================================
	// SITE SETTINGS
	// =========================================================================

	/**
	 * Get site settings.
	 *
	 * @return array Site settings.
	 */
	public function get_settings() {
		return array(
			'title'           => get_option( 'blogname' ),
			'tagline'         => get_option( 'blogdescription' ),
			'url'             => get_option( 'siteurl' ),
			'home'            => get_option( 'home' ),
			'admin_email'     => get_option( 'admin_email' ),
			'language'        => get_option( 'WPLANG' ) ?: 'en_US',
			'timezone'        => get_option( 'timezone_string' ) ?: get_option( 'gmt_offset' ),
			'date_format'     => get_option( 'date_format' ),
			'time_format'     => get_option( 'time_format' ),
			'posts_per_page'  => (int) get_option( 'posts_per_page' ),
			'show_on_front'   => get_option( 'show_on_front' ),
			'page_on_front'   => (int) get_option( 'page_on_front' ),
			'page_for_posts'  => (int) get_option( 'page_for_posts' ),
			'permalink_structure' => get_option( 'permalink_structure' ),
		);
	}

	/**
	 * Update site settings.
	 *
	 * @param array $data Settings to update.
	 * @return array Updated settings.
	 */
	public function update_settings( $data ) {
		$allowed = array(
			'title'          => 'blogname',
			'tagline'        => 'blogdescription',
			'admin_email'    => 'admin_email',
			'timezone'       => 'timezone_string',
			'date_format'    => 'date_format',
			'time_format'    => 'time_format',
			'posts_per_page' => 'posts_per_page',
			'show_on_front'  => 'show_on_front',
			'page_on_front'  => 'page_on_front',
			'page_for_posts' => 'page_for_posts',
		);

		foreach ( $data as $key => $value ) {
			if ( isset( $allowed[ $key ] ) ) {
				$option_name = $allowed[ $key ];

				// Sanitize based on type.
				switch ( $key ) {
					case 'title':
					case 'tagline':
						$value = sanitize_text_field( $value );
						break;
					case 'admin_email':
						$value = sanitize_email( $value );
						break;
					case 'posts_per_page':
					case 'page_on_front':
					case 'page_for_posts':
						$value = absint( $value );
						break;
					case 'show_on_front':
						$value = in_array( $value, array( 'posts', 'page' ), true ) ? $value : 'posts';
						break;
				}

				update_option( $option_name, $value );
			}
		}

		return $this->get_settings();
	}

	// =========================================================================
	// THEME CUSTOMIZER / GLOBAL STYLES
	// =========================================================================

	/**
	 * Get theme mods (customizer settings).
	 *
	 * @return array Theme mods.
	 */
	public function get_theme_mods() {
		$mods = get_theme_mods();

		// Remove internal/sensitive data.
		unset( $mods['nav_menu_locations'] );
		unset( $mods['sidebars_widgets'] );

		return $mods;
	}

	/**
	 * Update theme mods.
	 *
	 * @param array $mods Mods to update.
	 * @return array Updated mods.
	 */
	public function update_theme_mods( $mods ) {
		// Prevent updating sensitive mods.
		$blocked = array( 'nav_menu_locations', 'sidebars_widgets' );

		foreach ( $mods as $key => $value ) {
			if ( ! in_array( $key, $blocked, true ) ) {
				set_theme_mod( sanitize_key( $key ), $value );
			}
		}

		return $this->get_theme_mods();
	}

	/**
	 * Get global styles (WordPress 5.9+ block themes).
	 *
	 * @return array|WP_Error Global styles or error.
	 */
	public function get_global_styles() {
		if ( ! function_exists( 'wp_get_global_styles' ) ) {
			return new WP_Error( 'not_supported', __( 'Global styles require WordPress 5.9+ and a block theme.', 'mcpwp' ) );
		}

		return array(
			'styles'   => wp_get_global_styles(),
			'settings' => wp_get_global_settings(),
		);
	}

	/**
	 * Get custom CSS.
	 *
	 * @return string Custom CSS.
	 */
	public function get_custom_css() {
		return wp_get_custom_css();
	}

	/**
	 * Update custom CSS.
	 *
	 * @param string $css CSS content.
	 * @return string Updated CSS.
	 */
	public function update_custom_css( $css ) {
		$css = wp_strip_all_tags( $css );

		wp_update_custom_css_post( $css );

		return $this->get_custom_css();
	}

	// =========================================================================
	// PAGE TEMPLATES
	// =========================================================================

	/**
	 * Get available page templates.
	 *
	 * @return array Templates list.
	 */
	public function get_page_templates() {
		$templates = wp_get_theme()->get_page_templates();

		$result = array(
			'default' => __( 'Default Template', 'mcpwp' ),
		);

		foreach ( $templates as $file => $name ) {
			$result[ $file ] = $name;
		}

		// Add Elementor templates if active.
		if ( did_action( 'elementor/loaded' ) ) {
			$result['elementor_header_footer'] = __( 'Elementor Full Width', 'mcpwp' );
			$result['elementor_canvas'] = __( 'Elementor Canvas', 'mcpwp' );
		}

		return $result;
	}

	/**
	 * Set page template.
	 *
	 * @param int    $page_id  Page ID.
	 * @param string $template Template file.
	 * @return bool|WP_Error True on success.
	 */
	public function set_page_template( $page_id, $template ) {
		$page = get_post( $page_id );

		if ( ! $page || 'page' !== $page->post_type ) {
			return new WP_Error( 'not_found', __( 'Page not found.', 'mcpwp' ) );
		}

		// Validate template.
		$valid_templates = array_keys( $this->get_page_templates() );
		if ( ! in_array( $template, $valid_templates, true ) && 'default' !== $template && '' !== $template ) {
			return new WP_Error( 'invalid_template', __( 'Invalid template.', 'mcpwp' ) );
		}

		// Set template (empty string or 'default' means default template).
		$template_value = ( 'default' === $template || '' === $template ) ? '' : $template;
		update_post_meta( $page_id, '_wp_page_template', $template_value );

		return true;
	}

	/**
	 * Get page template.
	 *
	 * @param int $page_id Page ID.
	 * @return string|WP_Error Template file.
	 */
	public function get_page_template( $page_id ) {
		$page = get_post( $page_id );

		if ( ! $page || 'page' !== $page->post_type ) {
			return new WP_Error( 'not_found', __( 'Page not found.', 'mcpwp' ) );
		}

		$template = get_post_meta( $page_id, '_wp_page_template', true );

		return $template ?: 'default';
	}
}
