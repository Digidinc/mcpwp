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
	 * Whether this install holds a Pro entitlement (fails closed).
	 *
	 * For integrations whose tools are Pro: return no tools and no tool map
	 * when this is false, so they are absent from tools/list and tools/call
	 * cannot route to them. Keep get_tool_categories() unconditional so the
	 * names stay reserved against custom-tool shadowing.
	 *
	 * @return bool
	 */
	protected function is_mcpwp_pro_active() {
		return class_exists( 'Mcpwp_REST_API', false ) && Mcpwp_REST_API::is_pro_entitled();
	}

	/**
	 * Reserved-category remaps already logged in this request, keyed
	 * "source:tool" (#1064 review round 2).
	 *
	 * @var array<string,bool>
	 */
	private static $reserved_category_logged = array();

	/**
	 * Remap reserved category slugs (Mcpwp_Api_Auth::reserved_tool_category_slugs(),
	 * i.e. `meta`) to `custom` in a tool => category map, logging each
	 * remapped tool once a day (#1064 review round 2). `meta` is
	 * granted to no key; a third-party tool that claimed it would otherwise
	 * ride the name-level meta-tool allowance's category into every key.
	 * Shared by integrations and mcpwp_register_tools registrations.
	 *
	 * @param array  $categories Tool name => category slug.
	 * @param string $source     Who declared them (integration slug / "mcpwp_register_tools").
	 * @return array
	 */
	public static function remap_reserved_categories( array $categories, $source ) {
		foreach ( $categories as $tool => $category ) {
			$categories[ $tool ] = self::remap_reserved_category( (string) $category, (string) $tool, $source );
		}
		return $categories;
	}

	/**
	 * One category through remap_reserved_categories().
	 *
	 * @param string $category Category slug.
	 * @param string $tool     Tool name (for the log line).
	 * @param string $source   Declaring source (for the log line).
	 * @return string
	 */
	public static function remap_reserved_category( $category, $tool, $source ) {
		if ( ! in_array( $category, self::reserved_tool_category_slugs(), true ) ) {
			return $category;
		}
		// Logged once a day per tool, not on every request: the static map
		// covers one request, the transient the rest of the day.
		$log_key   = $source . ':' . $tool;
		$transient = 'mcpwp_reserved_cat_' . md5( $log_key );
		if ( ! isset( self::$reserved_category_logged[ $log_key ] ) && false === get_transient( $transient ) ) {
			self::$reserved_category_logged[ $log_key ] = true;
			set_transient( $transient, 1, DAY_IN_SECONDS );
			if ( function_exists( 'error_log' ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf( 'MCPWP: tool "%s" from %s declared the reserved category "%s"; it is treated as "custom".', $tool, $source, $category ) );
			}
		}
		return 'custom';
	}

	/**
	 * {@inheritDoc}
	 *
	 * An integration's own get_tool_categories() may claim a reserved slug;
	 * the category every gate and annotation reads is remapped here
	 * (#1064 review round 2).
	 */
	public function get_tool_category( $name ) {
		return self::remap_reserved_category( parent::get_tool_category( $name ), (string) $name, get_class( $this ) );
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
