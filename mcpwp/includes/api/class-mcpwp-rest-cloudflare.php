<?php
/**
 * Cloudflare REST Controller
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cloudflare REST controller.
 */
class Mcpwp_REST_Cloudflare extends Mcpwp_REST_API {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/cloudflare/status',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_status' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/cloudflare/fix-mcp-access',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'fix_mcp_access' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/cloudflare/purge-cache',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'purge_cache' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'urls'             => array(
							'description' => __( 'URLs to purge (max 30). Omit if purge_everything is true.', 'mcpwp' ),
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
						),
						'purge_everything' => array(
							'description' => __( 'Purge the entire zone cache instead of specific URLs.', 'mcpwp' ),
							'type'        => 'boolean',
							'default'     => false,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/cloudflare/speed-settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_speed_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_speed_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'setting' => array(
							'description' => __( 'Setting name: early_hints, http3, rocket_loader, or polish.', 'mcpwp' ),
							'type'        => 'string',
							'required'    => true,
						),
						'value'   => array(
							'description' => __( 'New value ("on"/"off", or "off"/"lossless"/"lossy"/"webp" for polish).', 'mcpwp' ),
							'required'    => true,
						),
					),
				),
			)
		);
	}

	/**
	 * GET /cloudflare/status — connection + zone status.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_status( $request ) {
		$this->log_activity( 'cloudflare_status', $request );

		$manager = Mcpwp_Integration_Manager::get_instance();
		if ( ! $manager->has_provider_key( 'cloudflare' ) ) {
			return $this->success_response(
				array(
					'configured' => false,
					'message'    => __( 'Cloudflare is not configured. Add an API Token and Zone ID under WP Admin > Integrations.', 'mcpwp' ),
				)
			);
		}

		$cloudflare = new Mcpwp_Provider_Cloudflare();
		$zone       = $cloudflare->get_zone_status();

		if ( is_wp_error( $zone ) ) {
			return $zone;
		}

		return $this->success_response(
			array(
				'configured' => true,
				'zone'       => array(
					'name'   => isset( $zone['name'] ) ? $zone['name'] : null,
					'status' => isset( $zone['status'] ) ? $zone['status'] : null,
					'plan'   => isset( $zone['plan']['name'] ) ? $zone['plan']['name'] : null,
				),
			)
		);
	}

	/**
	 * POST /cloudflare/fix-mcp-access — self-service Bot Fight Mode / Security Level fix.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function fix_mcp_access( $request ) {
		$this->log_activity( 'cloudflare_fix_mcp_access', $request );

		$cloudflare = new Mcpwp_Provider_Cloudflare();
		$result     = $cloudflare->fix_mcp_access();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response( $result );
	}

	/**
	 * POST /cloudflare/purge-cache.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function purge_cache( $request ) {
		$this->log_activity( 'cloudflare_purge_cache', $request );

		$cloudflare       = new Mcpwp_Provider_Cloudflare();
		$urls             = (array) $request->get_param( 'urls' );
		$purge_everything = rest_sanitize_boolean( $request->get_param( 'purge_everything' ) );

		$result = $cloudflare->purge_cache( $urls, $purge_everything );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response(
			array(
				'purged'           => true,
				'purge_everything' => $purge_everything,
				'urls'             => $purge_everything ? array() : $urls,
			)
		);
	}

	/**
	 * GET /cloudflare/speed-settings.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_speed_settings( $request ) {
		$this->log_activity( 'cloudflare_get_speed_settings', $request );

		$cloudflare = new Mcpwp_Provider_Cloudflare();
		$settings   = $cloudflare->get_speed_settings();

		if ( is_wp_error( $settings ) ) {
			return $settings;
		}

		return $this->success_response( array( 'settings' => $settings ) );
	}

	/**
	 * POST /cloudflare/speed-settings.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_speed_settings( $request ) {
		$this->log_activity( 'cloudflare_update_speed_settings', $request );

		$cloudflare = new Mcpwp_Provider_Cloudflare();
		$result     = $cloudflare->update_speed_setting(
			$request->get_param( 'setting' ),
			$request->get_param( 'value' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response(
			array(
				'setting' => $request->get_param( 'setting' ),
				'value'   => isset( $result['value'] ) ? $result['value'] : $request->get_param( 'value' ),
			)
		);
	}
}
