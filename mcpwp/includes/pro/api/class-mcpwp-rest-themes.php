<?php
/**
 * Themes REST API Controller
 *
 * @package MCPWP_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller for popular theme integration.
 */
class Mcpwp_REST_Themes extends Mcpwp_REST_API {

	/**
	 * Themes handler.
	 *
	 * @var Mcpwp_Themes
	 */
	private $themes;

	/**
	 * Constructor.
	 *
	 * @param Mcpwp_Themes $themes Themes handler.
	 */
	public function __construct( $themes ) {
		$this->themes = $themes;
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		// Detect active theme.
		register_rest_route(
			$this->namespace,
			'/themes/detect',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'detect_theme' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// List supported themes.
		register_rest_route(
			$this->namespace,
			'/themes/supported',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_supported_themes' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Get theme settings (auto-detected).
		register_rest_route(
			$this->namespace,
			'/themes/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Get specific setting category.
		register_rest_route(
			$this->namespace,
			'/themes/settings/(?P<category>[a-z_]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_settings_category' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Astra-specific endpoints (when detected).
		register_rest_route(
			$this->namespace,
			'/themes/astra/colors',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_astra_colors' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_astra_colors' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/themes/astra/typography',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_astra_typography' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_astra_typography' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/themes/astra/header',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_astra_header' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/themes/astra/footer',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_astra_footer' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/themes/astra/layout',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_astra_layout' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Detect active theme.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function detect_theme( $request ) {
		$theme_info = $this->themes->detect_theme();

		$this->log_activity( 'detect_theme', $request, array( 'theme' => $theme_info['slug'] ) );

		return $this->success_response( $theme_info );
	}

	/**
	 * Get supported themes list.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function get_supported_themes( $request ) {
		$themes = $this->themes->get_supported_themes();

		$this->log_activity( 'get_supported_themes', $request, array( 'count' => count( $themes ) ) );

		return $this->success_response( array(
			'themes' => $themes,
			'total'  => count( $themes ),
		) );
	}

	/**
	 * Get theme settings (auto-detected).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function get_settings( $request ) {
		$settings = $this->themes->get_settings();

		$this->log_activity( 'get_theme_settings', $request, array( 'type' => $settings['type'] ?? 'unknown' ) );

		return $this->success_response( $settings );
	}

	/**
	 * Update theme settings.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function update_settings( $request ) {
		$settings = $request->get_params();

		if ( empty( $settings ) ) {
			return $this->error_response(
				'missing_settings',
				__( 'Settings data is required.', 'mumcp' ),
				400
			);
		}

		$result = $this->themes->update_settings( $settings );

		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result->get_error_code(), $result->get_error_message(), 400 );
		}

		$this->log_activity( 'update_theme_settings', $request, array( 'keys' => array_keys( $settings ) ) );

		return $this->success_response( $result );
	}

	/**
	 * Get specific settings category.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_settings_category( $request ) {
		$category = sanitize_text_field( $request->get_param( 'category' ) );
		$settings = $this->themes->get_settings();

		if ( ! isset( $settings[ $category ] ) ) {
			return $this->error_response(
				'category_not_found',
				/* translators: %s: category name */
				sprintf( __( 'Settings category "%s" not found.', 'mumcp' ), $category ),
				404
			);
		}

		$this->log_activity( 'get_theme_settings_category', $request, array( 'category' => $category ) );

		return $this->success_response( array(
			'category' => $category,
			'settings' => $settings[ $category ],
		) );
	}

	// =========================================================================
	// ASTRA-SPECIFIC ENDPOINTS
	// =========================================================================

	/**
	 * Check if Astra is active.
	 *
	 * @return bool|WP_Error True if Astra, error otherwise.
	 */
	private function check_astra() {
		$theme_info = $this->themes->detect_theme();

		if ( 'astra' !== $theme_info['slug'] ) {
			return new WP_Error(
				'not_astra',
				__( 'This endpoint requires Astra theme to be active.', 'mumcp' )
			);
		}

		return true;
	}

	/**
	 * Get Astra colors.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_astra_colors( $request ) {
		$check = $this->check_astra();
		if ( is_wp_error( $check ) ) {
			return $this->error_response( $check->get_error_code(), $check->get_error_message(), 400 );
		}

		$settings = $this->themes->get_settings();

		$this->log_activity( 'get_astra_colors', $request );

		return $this->success_response( $settings['colors'] ?? array() );
	}

	/**
	 * Update Astra colors.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function update_astra_colors( $request ) {
		$check = $this->check_astra();
		if ( is_wp_error( $check ) ) {
			return $this->error_response( $check->get_error_code(), $check->get_error_message(), 400 );
		}

		$colors = $request->get_params();

		if ( empty( $colors ) ) {
			return $this->error_response(
				'missing_colors',
				__( 'Color data is required.', 'mumcp' ),
				400
			);
		}

		// Map back to Astra setting keys.
		$astra_settings = array();
		$color_map      = array(
			'theme_color'       => 'theme-color',
			'link_color'        => 'link-color',
			'link_h_color'      => 'link-h-color',
			'text_color'        => 'text-color',
			'heading_base_color' => 'heading-base-color',
		);

		foreach ( $colors as $key => $value ) {
			$astra_key = isset( $color_map[ $key ] ) ? $color_map[ $key ] : $key;
			$astra_settings[ $astra_key ] = $value;
		}

		$result = $this->themes->update_settings( $astra_settings );

		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result->get_error_code(), $result->get_error_message(), 400 );
		}

		$this->log_activity( 'update_astra_colors', $request, array( 'keys' => array_keys( $colors ) ) );

		return $this->success_response( $result['colors'] ?? array() );
	}

	/**
	 * Get Astra typography.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_astra_typography( $request ) {
		$check = $this->check_astra();
		if ( is_wp_error( $check ) ) {
			return $this->error_response( $check->get_error_code(), $check->get_error_message(), 400 );
		}

		$settings = $this->themes->get_settings();

		$this->log_activity( 'get_astra_typography', $request );

		return $this->success_response( $settings['typography'] ?? array() );
	}

	/**
	 * Update Astra typography.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function update_astra_typography( $request ) {
		$check = $this->check_astra();
		if ( is_wp_error( $check ) ) {
			return $this->error_response( $check->get_error_code(), $check->get_error_message(), 400 );
		}

		$typography = $request->get_params();

		if ( empty( $typography ) ) {
			return $this->error_response(
				'missing_typography',
				__( 'Typography data is required.', 'mumcp' ),
				400
			);
		}

		// Map to Astra keys.
		$astra_settings = array();
		$typo_map       = array(
			'body_font_family' => 'body-font-family',
			'body_font_size'   => 'body-font-size',
			'body_line_height' => 'body-line-height',
			'headings_font_family' => 'headings-font-family',
		);

		foreach ( $typography as $key => $value ) {
			$astra_key = isset( $typo_map[ $key ] ) ? $typo_map[ $key ] : $key;
			$astra_settings[ $astra_key ] = $value;
		}

		$result = $this->themes->update_settings( $astra_settings );

		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result->get_error_code(), $result->get_error_message(), 400 );
		}

		$this->log_activity( 'update_astra_typography', $request, array( 'keys' => array_keys( $typography ) ) );

		return $this->success_response( $result['typography'] ?? array() );
	}

	/**
	 * Get Astra header settings.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_astra_header( $request ) {
		$check = $this->check_astra();
		if ( is_wp_error( $check ) ) {
			return $this->error_response( $check->get_error_code(), $check->get_error_message(), 400 );
		}

		$settings = $this->themes->get_settings();

		$this->log_activity( 'get_astra_header', $request );

		return $this->success_response( $settings['header'] ?? array() );
	}

	/**
	 * Get Astra footer settings.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_astra_footer( $request ) {
		$check = $this->check_astra();
		if ( is_wp_error( $check ) ) {
			return $this->error_response( $check->get_error_code(), $check->get_error_message(), 400 );
		}

		$settings = $this->themes->get_settings();

		$this->log_activity( 'get_astra_footer', $request );

		return $this->success_response( $settings['footer'] ?? array() );
	}

	/**
	 * Get Astra layout settings.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_astra_layout( $request ) {
		$check = $this->check_astra();
		if ( is_wp_error( $check ) ) {
			return $this->error_response( $check->get_error_code(), $check->get_error_message(), 400 );
		}

		$settings = $this->themes->get_settings();

		$this->log_activity( 'get_astra_layout', $request );

		return $this->success_response( $settings['layout'] ?? array() );
	}
}
