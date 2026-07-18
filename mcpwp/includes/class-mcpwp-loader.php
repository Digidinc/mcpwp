<?php
/**
 * Plugin loader.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register and run the plugin's WordPress hooks.
 */
class Mcpwp_Loader {
	use Mcpwp_Logging;

	/**
	 * Registered WordPress actions.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $actions = array();

	/**
	 * Registered WordPress filters.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $filters = array();

	/**
	 * Register the plugin's deferred actions and filters.
	 */
	public function __construct() {
		// Note: no plugins_loaded textdomain hook here — Mcpwp_i18n::load_plugin_textdomain()
		// is an intentional no-op (WordPress.org language packs auto-load since WP 4.6),
		// so registering it as a hook callback was dead code. The class file itself is
		// retained (see scripts/wporg-artifact-policy.json) for packaging-policy parity.
		$admin = new Mcpwp_Admin();
		$this->add_action( 'admin_menu', $admin, 'add_admin_menu' );
		$this->add_action( 'admin_enqueue_scripts', $admin, 'enqueue_styles' );
		$this->add_filter( 'plugin_action_links_' . MCPWP_PLUGIN_BASENAME, $admin, 'add_action_links', 100 );

		$this->add_action( 'rest_api_init', $this, 'register_rest_routes' );
		$this->add_filter( 'rest_post_dispatch', $this, 'add_mcpwp_rate_limit_headers', 10, 3 );
		$this->add_action( 'init', $this, 'maybe_schedule_log_cleanup' );
		$this->add_action( 'mcpwp_cleanup_logs', $this, 'cleanup_old_logs' );
	}

	/**
	 * Instantiate retained controllers and register their REST routes.
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		$controllers = array(
			new Mcpwp_REST_Site_Info(),
			new Mcpwp_REST_Site_Settings(),
			new Mcpwp_REST_API_Keys(),
			new Mcpwp_REST_Rate_Limit(),
			new Mcpwp_REST_Posts(),
			new Mcpwp_REST_Pages(),
			new Mcpwp_REST_Media(),
			new Mcpwp_REST_Site_Taxonomy(),
			new Mcpwp_REST_MCP(),
		);
		foreach ( $controllers as $controller ) {
			$controller->register_routes();
		}
	}

	/**
	 * Schedule daily local activity-log cleanup when it is absent.
	 *
	 * @return void
	 */
	public function maybe_schedule_log_cleanup() {
		if ( ! wp_next_scheduled( 'mcpwp_cleanup_logs' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'mcpwp_cleanup_logs' );
		}
	}

	/**
	 * Delete local activity rows older than the configured retention period.
	 *
	 * @return void
	 */
	public function cleanup_old_logs() {
		global $wpdb;
		$settings = get_option( 'mcpwp_settings', array() );
		$days     = max( 1, absint( $settings['log_retention_days'] ?? 30 ) );
		$table    = $wpdb->prefix . 'mcpwp_activity_log';
		$cutoff   = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WordPress 6.1 lacks the %i identifier placeholder; the table name is only $wpdb->prefix plus a fixed suffix, and the cutoff retains a %s placeholder.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
	}

	/**
	 * Add rate-limit headers to responses from retained REST routes.
	 *
	 * @param WP_HTTP_Response $response Response being dispatched.
	 * @param WP_REST_Server   $server   Active REST server.
	 * @param WP_REST_Request  $request  Current REST request.
	 * @return WP_HTTP_Response Response with any applicable headers.
	 */
	public function add_mcpwp_rate_limit_headers( $response, $server, $request ) {
		if ( $request instanceof WP_REST_Request && 0 === strpos( (string) $request->get_route(), '/mcpwp/v1/' ) && method_exists( $response, 'header' ) ) {
			foreach ( Mcpwp_Rate_Limiter::get_instance()->get_headers() as $name => $value ) {
				$response->header( $name, $value );
			}
		}
		return $response;
	}

	/**
	 * Queue a WordPress action registration.
	 *
	 * @param string $hook          Action hook name.
	 * @param object $component     Callback owner.
	 * @param string $callback      Callback method name.
	 * @param int    $priority      Hook priority.
	 * @param int    $accepted_args Number of accepted callback arguments.
	 * @return void
	 */
	public function add_action( $hook, $component, $callback, $priority = 10, $accepted_args = 1 ) {
		$this->actions[] = compact( 'hook', 'component', 'callback', 'priority', 'accepted_args' );
	}

	/**
	 * Queue a WordPress filter registration.
	 *
	 * @param string $hook          Filter hook name.
	 * @param object $component     Callback owner.
	 * @param string $callback      Callback method name.
	 * @param int    $priority      Hook priority.
	 * @param int    $accepted_args Number of accepted callback arguments.
	 * @return void
	 */
	public function add_filter( $hook, $component, $callback, $priority = 10, $accepted_args = 1 ) {
		$this->filters[] = compact( 'hook', 'component', 'callback', 'priority', 'accepted_args' );
	}

	/**
	 * Register all queued actions and filters with WordPress.
	 *
	 * @return void
	 */
	public function run() {
		foreach ( $this->filters as $item ) {
			add_filter( $item['hook'], array( $item['component'], $item['callback'] ), $item['priority'], $item['accepted_args'] );
		}
		foreach ( $this->actions as $item ) {
			add_action( $item['hook'], array( $item['component'], $item['callback'] ), $item['priority'], $item['accepted_args'] );
		}
	}
}
