<?php
/**
 * Plugin loader.
 *
 * @package mumcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mcpwp_Loader {
	use Mcpwp_Logging;

	private $actions = array();
	private $filters = array();

	public function __construct() {
		$i18n = new Mcpwp_i18n();
		$this->add_action( 'plugins_loaded', $i18n, 'load_plugin_textdomain' );

		$admin = new Mcpwp_Admin();
		$this->add_action( 'admin_menu', $admin, 'add_admin_menu' );
		$this->add_action( 'admin_enqueue_scripts', $admin, 'enqueue_styles' );
		$this->add_filter( 'plugin_action_links_' . MCPWP_PLUGIN_BASENAME, $admin, 'add_action_links', 100 );

		$this->add_action( 'rest_api_init', $this, 'register_rest_routes' );
		$this->add_filter( 'rest_post_dispatch', $this, 'add_mcpwp_rate_limit_headers', 10, 3 );
		$this->add_action( 'init', $this, 'maybe_schedule_log_cleanup' );
		$this->add_action( 'mcpwp_cleanup_logs', $this, 'cleanup_old_logs' );
	}

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

	public function maybe_schedule_log_cleanup() {
		if ( ! wp_next_scheduled( 'mcpwp_cleanup_logs' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'mcpwp_cleanup_logs' );
		}
	}

	public function cleanup_old_logs() {
		global $wpdb;
		$settings = get_option( 'mcpwp_settings', array() );
		$days     = max( 1, absint( $settings['log_retention_days'] ?? 30 ) );
		$table    = $wpdb->prefix . 'mcpwp_activity_log';
		$cutoff   = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
	}

	public function add_mcpwp_rate_limit_headers( $response, $server, $request ) {
		if ( $request instanceof WP_REST_Request && 0 === strpos( (string) $request->get_route(), '/mcpwp/v1/' ) && method_exists( $response, 'header' ) ) {
			foreach ( Mcpwp_Rate_Limiter::get_instance()->get_headers() as $name => $value ) {
				$response->header( $name, $value );
			}
		}
		return $response;
	}

	public function add_action( $hook, $component, $callback, $priority = 10, $accepted_args = 1 ) {
		$this->actions[] = compact( 'hook', 'component', 'callback', 'priority', 'accepted_args' );
	}

	public function add_filter( $hook, $component, $callback, $priority = 10, $accepted_args = 1 ) {
		$this->filters[] = compact( 'hook', 'component', 'callback', 'priority', 'accepted_args' );
	}

	public function run() {
		foreach ( $this->filters as $item ) {
			add_filter( $item['hook'], array( $item['component'], $item['callback'] ), $item['priority'], $item['accepted_args'] );
		}
		foreach ( $this->actions as $item ) {
			add_action( $item['hook'], array( $item['component'], $item['callback'] ), $item['priority'], $item['accepted_args'] );
		}
	}
}
