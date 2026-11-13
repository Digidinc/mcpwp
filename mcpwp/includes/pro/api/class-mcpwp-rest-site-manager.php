<?php
/**
 * Site Manager REST API Controller
 *
 * @package MCPWP_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller for site management.
 *
 * Provides endpoints for menus, settings, theme mods, and templates.
 */
class Mcpwp_REST_Site_Manager extends Mcpwp_REST_API {

	/**
	 * Site manager handler.
	 *
	 * @var Mcpwp_Site_Manager
	 */
	private $manager;

	/**
	 * Constructor.
	 *
	 * @param Mcpwp_Site_Manager $manager Site manager handler.
	 */
	public function __construct( $manager ) {
		$this->manager = $manager;
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		// =====================================================================
		// MENUS
		// =====================================================================

		// Create menus. Free owns the list, locations, items and settings routes.
		register_rest_route(
			$this->namespace,
			'/menus',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_menu' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Single menu.
		register_rest_route(
			$this->namespace,
			'/menus/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_menu' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_menu' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// =====================================================================
		// THEME CUSTOMIZER
		// =====================================================================

		register_rest_route(
			$this->namespace,
			'/theme/mods',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_theme_mods' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_theme_mods' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/theme/global-styles',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_global_styles' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/theme/custom-css',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_custom_css' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_custom_css' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// =====================================================================
		// PAGE TEMPLATES
		// =====================================================================

		register_rest_route(
			$this->namespace,
			'/pages/(?P<id>[\d]+)/template',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_page_template' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => 'PUT,PATCH',
					'callback'            => array( $this, 'set_page_template' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
	}

	// =========================================================================
	// MENU ENDPOINTS
	// =========================================================================

	/**
	 * Get all menus.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function get_menus( $request ) {
		$menus = $this->manager->get_menus();

		$this->log_activity( 'get_menus', $request, array( 'count' => count( $menus ) ) );

		return $this->success_response( array(
			'menus' => $menus,
			'total' => count( $menus ),
		) );
	}

	/**
	 * Get menu locations.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function get_menu_locations( $request ) {
		$locations = $this->manager->get_menu_locations();

		$this->log_activity( 'get_menu_locations', $request );

		return $this->success_response( array(
			'locations' => $locations,
		) );
	}

	/**
	 * Get single menu.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_menu( $request ) {
		$menu_id = absint( $request->get_param( 'id' ) );
		$menu = $this->manager->get_menu( $menu_id );

		if ( is_wp_error( $menu ) ) {
			return $this->error_response( $menu->get_error_code(), $menu->get_error_message(), 404 );
		}

		$this->log_activity( 'get_menu', $request, array( 'menu_id' => $menu_id ) );

		return $this->success_response( $menu );
	}

	/**
	 * Create menu.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function create_menu( $request ) {
		$data = array(
			'name'     => $request->get_param( 'name' ),
			'location' => $request->get_param( 'location' ),
			'items'    => $request->get_param( 'items' ),
			// One per-item resolver for every bulk door (#1030 round-5 WARN-2):
			// a post_type item naming a missing post, or one hidden from this
			// caller (a coupon for a key without woocommerce), is refused with
			// the same item_errors entry and never created.
			'item_object_visible' => function ( $object_id ) use ( $request ) {
				return $this->post_ref_item_visible( $request, $object_id );
			},
		);

		$menu = $this->manager->create_menu( $data );

		if ( is_wp_error( $menu ) ) {
			return $this->error_response_from_wp_error( $menu );
		}

		$this->log_activity( 'create_menu', $request, array( 'menu_id' => $menu['id'] ), 201 );

		return $this->success_response( $menu, 201 );
	}

	/**
	 * Update menu.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function update_menu( $request ) {
		$menu_id = absint( $request->get_param( 'id' ) );
		$data = array(
			'name'     => $request->get_param( 'name' ),
			'location' => $request->get_param( 'location' ),
		);

		$menu = $this->manager->update_menu( $menu_id, $data );

		if ( is_wp_error( $menu ) ) {
			return $this->error_response_from_wp_error( $menu );
		}

		$this->log_activity( 'update_menu', $request, array( 'menu_id' => $menu_id ) );

		return $this->success_response( $menu );
	}

	/**
	 * Delete menu.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function delete_menu( $request ) {
		$menu_id = absint( $request->get_param( 'id' ) );
		$result = $this->manager->delete_menu( $menu_id );

		if ( is_wp_error( $result ) ) {
			return $this->error_response_from_wp_error( $result );
		}

		$this->log_activity( 'delete_menu', $request, array( 'menu_id' => $menu_id ) );

		return $this->success_response( array(
			'deleted' => true,
			'id'      => $menu_id,
		) );
	}

	/**
	 * Add menu item.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function add_menu_item( $request ) {
		$menu_id = absint( $request->get_param( 'menu_id' ) );
		$data = array(
			'title'       => $request->get_param( 'title' ),
			'url'         => $request->get_param( 'url' ),
			'type'        => $request->get_param( 'type' ),
			'object_id'   => $request->get_param( 'object_id' ),
			'parent_id'   => $request->get_param( 'parent_id' ),
			'position'    => $request->get_param( 'position' ),
			'target'      => $request->get_param( 'target' ),
			'classes'     => $request->get_param( 'classes' ),
			'description' => $request->get_param( 'description' ),
		);

		$item_id = $this->manager->add_menu_item( $menu_id, $data );

		if ( is_wp_error( $item_id ) ) {
			return $this->error_response_from_wp_error( $item_id );
		}

		$this->log_activity( 'add_menu_item', $request, array( 'menu_id' => $menu_id, 'item_id' => $item_id ), 201 );

		return $this->success_response( array(
			'id'      => $item_id,
			'menu_id' => $menu_id,
		), 201 );
	}

	/**
	 * Update menu item.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function update_menu_item( $request ) {
		$menu_id = absint( $request->get_param( 'menu_id' ) );
		$item_id = absint( $request->get_param( 'item_id' ) );
		$data = array(
			'title'     => $request->get_param( 'title' ),
			'url'       => $request->get_param( 'url' ),
			'parent_id' => $request->get_param( 'parent_id' ),
			'position'  => $request->get_param( 'position' ),
			'target'    => $request->get_param( 'target' ),
			'classes'   => $request->get_param( 'classes' ),
		);

		$result = $this->manager->update_menu_item( $menu_id, $item_id, $data );

		if ( is_wp_error( $result ) ) {
			return $this->error_response_from_wp_error( $result );
		}

		$this->log_activity( 'update_menu_item', $request, array( 'menu_id' => $menu_id, 'item_id' => $item_id ) );

		return $this->success_response( array(
			'id'      => $item_id,
			'menu_id' => $menu_id,
			'updated' => true,
		) );
	}

	/**
	 * Delete menu item.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function delete_menu_item( $request ) {
		$item_id = absint( $request->get_param( 'item_id' ) );
		$result = $this->manager->delete_menu_item( $item_id );

		if ( is_wp_error( $result ) ) {
			return $this->error_response_from_wp_error( $result );
		}

		$this->log_activity( 'delete_menu_item', $request, array( 'item_id' => $item_id ) );

		return $this->success_response( array(
			'deleted' => true,
			'id'      => $item_id,
		) );
	}

	// =========================================================================
	// SETTINGS ENDPOINTS
	// =========================================================================

	/**
	 * Get site settings.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function get_settings( $request ) {
		$settings = $this->manager->get_settings();

		$this->log_activity( 'get_settings', $request );

		return $this->success_response( $settings );
	}

	/**
	 * Update site settings.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function update_settings( $request ) {
		$data = array(
			'title'          => $request->get_param( 'title' ),
			'tagline'        => $request->get_param( 'tagline' ),
			'admin_email'    => $request->get_param( 'admin_email' ),
			'timezone'       => $request->get_param( 'timezone' ),
			'date_format'    => $request->get_param( 'date_format' ),
			'time_format'    => $request->get_param( 'time_format' ),
			'posts_per_page' => $request->get_param( 'posts_per_page' ),
			'show_on_front'  => $request->get_param( 'show_on_front' ),
			'page_on_front'  => $request->get_param( 'page_on_front' ),
			'page_for_posts' => $request->get_param( 'page_for_posts' ),
		);

		// Remove null values.
		$data = array_filter( $data, function( $v ) {
			return $v !== null;
		} );

		$settings = $this->manager->update_settings( $data );

		$this->log_activity( 'update_settings', $request, array_keys( $data ) );

		return $this->success_response( $settings );
	}

	// =========================================================================
	// THEME ENDPOINTS
	// =========================================================================

	/**
	 * Get theme mods.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function get_theme_mods( $request ) {
		$mods = $this->manager->get_theme_mods();

		$this->log_activity( 'get_theme_mods', $request );

		return $this->success_response( $mods );
	}

	/**
	 * Update theme mods.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function update_theme_mods( $request ) {
		$mods = $request->get_param( 'mods' );

		if ( empty( $mods ) || ! is_array( $mods ) ) {
			return $this->error_response( 'invalid_data', __( 'Mods object is required.', 'mcpwp' ), 400 );
		}

		$updated = $this->manager->update_theme_mods( $mods );

		$this->log_activity( 'update_theme_mods', $request, array_keys( $mods ) );

		return $this->success_response( $updated );
	}

	/**
	 * Get global styles.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_global_styles( $request ) {
		$styles = $this->manager->get_global_styles();

		if ( is_wp_error( $styles ) ) {
			return $this->error_response_from_wp_error( $styles );
		}

		$this->log_activity( 'get_global_styles', $request );

		return $this->success_response( $styles );
	}

	/**
	 * Get custom CSS.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function get_custom_css( $request ) {
		$css = $this->manager->get_custom_css();

		$this->log_activity( 'get_custom_css', $request );

		return $this->success_response( array(
			'css' => $css,
		) );
	}

	/**
	 * Update custom CSS.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function update_custom_css( $request ) {
		$css = $request->get_param( 'css' );

		$updated = $this->manager->update_custom_css( $css );

		$this->log_activity( 'update_custom_css', $request );

		return $this->success_response( array(
			'css' => $updated,
		) );
	}

	// =========================================================================
	// TEMPLATE ENDPOINTS
	// =========================================================================

	/**
	 * Get available page templates.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function get_page_templates( $request ) {
		$templates = $this->manager->get_page_templates();

		$this->log_activity( 'get_page_templates', $request );

		return $this->success_response( array(
			'templates' => $templates,
		) );
	}

	/**
	 * Get page template.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_page_template( $request ) {
		$page_id = absint( $request->get_param( 'id' ) );
		$template = $this->manager->get_page_template( $page_id );

		if ( is_wp_error( $template ) ) {
			return $this->error_response( $template->get_error_code(), $template->get_error_message(), 404 );
		}

		$this->log_activity( 'get_page_template', $request, array( 'page_id' => $page_id ) );

		return $this->success_response( array(
			'page_id'  => $page_id,
			'template' => $template,
		) );
	}

	/**
	 * Set page template.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function set_page_template( $request ) {
		$page_id = absint( $request->get_param( 'id' ) );
		$template = $request->get_param( 'template' );

		$result = $this->manager->set_page_template( $page_id, $template );

		if ( is_wp_error( $result ) ) {
			return $this->error_response_from_wp_error( $result );
		}

		$this->log_activity( 'set_page_template', $request, array( 'page_id' => $page_id, 'template' => $template ) );

		return $this->success_response( array(
			'page_id'  => $page_id,
			'template' => $template,
			'updated'  => true,
		) );
	}
}
