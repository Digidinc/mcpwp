<?php
/**
 * Integration Base Class
 *
 * Third-party plugins extend this to register with MCPWP.
 * Provides MCP tool definitions, REST route registration, and
 * capability declarations in a single class.
 *
 * Usage in a third-party plugin:
 *
 *     add_filter( 'mcpwp_integrations', function( $integrations ) {
 *         if ( class_exists( 'Mcpwp_Integration' ) ) {
 *             require_once __DIR__ . '/includes/class-my-plugin-mcpwp.php';
 *             $integrations[] = new My_Plugin_MCPWP();
 *         }
 *         return $integrations;
 *     } );
 *
 * @package MCPWP
 * @since   1.0.58
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base class for third-party integrations.
 *
 * Extends Mcpwp_MCP_Tool_Registry so integrations inherit define_tool(),
 * get_tool_annotations(), and all annotation logic for free.
 *
 * Uses Mcpwp_Api_Auth so integrations can validate API keys in their
 * REST route permission callbacks via $this->verify_api_key( $request ).
 */
abstract class Mcpwp_Integration extends Mcpwp_MCP_Tool_Registry {

	use Mcpwp_Api_Auth;

	/**
	 * Resolved integrations cache.
	 *
	 * @var Mcpwp_Integration[]|null
	 */
	private static $resolved = null;

	/**
	 * Get integration metadata.
	 *
	 * @return array {
	 *     @type string $slug        Unique identifier (e.g., 'my-booking-plugin').
	 *     @type string $name        Display name (e.g., 'My Booking Plugin').
	 *     @type string $version     Integration version (e.g., '1.0.0').
	 *     @type string $plugin_file Optional. Main plugin file for is_plugin_active() check.
	 * }
	 */
	abstract public function get_info();

	/**
	 * Get capabilities to merge into /site-info.
	 *
	 * Return an associative array of capability flags. These appear
	 * in the site-info response and MCP introspection data.
	 *
	 * @return array<string, mixed> Capabilities (e.g., ['my_booking' => true]).
	 */
	public function get_capabilities() {
		return array();
	}

	/**
	 * Register REST routes for this integration.
	 *
	 * Called during rest_api_init, after mcpwp_register_rest_routes.
	 * Override to register routes under the 'mcpwp/v1' namespace.
	 *
	 * Routes can use $this->verify_api_key( $request ) for auth:
	 *
	 *     register_rest_route( 'mcpwp/v1', '/my-route', array(
	 *         'methods'             => 'GET',
	 *         'callback'            => array( $this, 'handle_request' ),
	 *         'permission_callback' => function( $r ) {
	 *             return $this->verify_api_key( $r );
	 *         },
	 *     ) );
	 */
	public function register_routes() {
		// Optional: third-party overrides this.
	}

	/**
	 * Check if the integration's target plugin is active.
	 *
	 * If plugin_file is declared in get_info(), checks is_plugin_active().
	 * Otherwise returns true (no dependency).
	 *
	 * @return bool True if the target plugin is available.
	 */
	public function is_available() {
		$info = $this->get_info();

		if ( empty( $info['plugin_file'] ) ) {
			return true;
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( $info['plugin_file'] );
	}

	/**
	 * Resolve all registered integrations.
	 *
	 * Fires the mcpwp_integrations filter, instantiates class name strings,
	 * filters by is_available(), and caches the result.
	 *
	 * @return Mcpwp_Integration[] Available integrations.
	 */
	public static function resolve_all() {
		if ( null !== self::$resolved ) {
			return self::$resolved;
		}

		/**
		 * Filter to register third-party integrations with MCPWP.
		 *
		 * @since 1.0.58
		 *
		 * @param array $integrations Array of Mcpwp_Integration instances or class name strings.
		 */
		$raw = apply_filters( 'mcpwp_integrations', array() );

		self::$resolved = array();

		foreach ( $raw as $integration ) {
			// Accept class name strings for lazy instantiation.
			if ( is_string( $integration ) && class_exists( $integration ) ) {
				$integration = new $integration();
			}

			if ( ! $integration instanceof Mcpwp_Integration ) {
				continue;
			}

			if ( ! $integration->is_available() ) {
				continue;
			}

			self::$resolved[] = $integration;
		}

		return self::$resolved;
	}

	/**
	 * Clear the resolved integrations cache.
	 *
	 * Useful when plugins are activated/deactivated.
	 */
	public static function clear_cache() {
		self::$resolved = null;
	}
}
