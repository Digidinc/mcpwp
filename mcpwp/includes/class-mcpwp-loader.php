<?php
/**
 * Plugin Loader
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main loader class.
 *
 * Orchestrates plugin initialization and hooks.
 */
class Mcpwp_Loader {

	use Mcpwp_Logging;

	/**
	 * Array of actions to register.
	 *
	 * @var array
	 */
	protected $actions = array();

	/**
	 * Array of filters to register.
	 *
	 * @var array
	 */
	protected $filters = array();

	/**
	 * Initialize the loader.
	 */
	public function __construct() {
		$this->load_dependencies();
		$this->set_locale();
		$this->define_admin_hooks();
		$this->define_api_hooks();
	}

	/**
	 * Load dependencies.
	 */
	private function load_dependencies() {
		// Dependencies are loaded in main plugin file
	}

	/**
	 * Set plugin locale for internationalization.
	 */
	private function set_locale() {
		$i18n = new Mcpwp_i18n();
		$this->add_action( 'plugins_loaded', $i18n, 'load_plugin_textdomain' );
	}

	/**
	 * Register admin hooks.
	 */
	private function define_admin_hooks() {
		$admin = new Mcpwp_Admin();
		$settings = new Mcpwp_Settings();
		$integrations_admin = new Mcpwp_Integrations_Admin();
		$tools_admin = new Mcpwp_Tools_Admin();
		$broker_admin = new Mcpwp_Broker_Admin();
		$ai_presence_settings = new Mcpwp_AI_Presence_Settings();

		// Admin menu
		$this->add_action( 'admin_menu', $admin, 'add_admin_menu' );

		// Network admin menu (multisite only).
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			$this->add_action( 'network_admin_menu', $admin, 'add_network_admin_menu' );
		}
		$this->add_action( 'admin_enqueue_scripts', $admin, 'enqueue_styles' );
		$this->add_action( 'admin_enqueue_scripts', $admin, 'enqueue_scripts' );

		// Integrations admin page assets
		$this->add_action( 'admin_enqueue_scripts', $integrations_admin, 'enqueue_assets' );

		// Tools admin page assets and AJAX
		$this->add_action( 'admin_enqueue_scripts', $tools_admin, 'enqueue_assets' );
		$this->add_action( 'wp_ajax_mcpwp_toggle_tool_category', $tools_admin, 'ajax_toggle_category' );

		// Settings
		$this->add_action( 'admin_init', $settings, 'register_settings' );
		$this->add_action( 'admin_init', $ai_presence_settings, 'register_settings' );
		// Content guardrails settings (#957) — static class, same admin_init pattern.
		$this->add_action( 'admin_init', 'Mcpwp_Content_Guardrails', 'register_settings_ui' );
		$this->add_action( 'admin_notices', $ai_presence_settings, 'maybe_render_notices' );

		// mupot Profile screen connect fields
		$mupot_profile = new Mcpwp_Mupot_Profile();
		$this->add_action( 'show_user_profile', $mupot_profile, 'render_fields' );
		$this->add_action( 'edit_user_profile', $mupot_profile, 'render_fields' );
		$this->add_action( 'personal_options_update', $mupot_profile, 'save_fields' );
		$this->add_action( 'edit_user_profile_update', $mupot_profile, 'save_fields' );

		// Admin notices
		$this->add_action( 'admin_notices', $admin, 'admin_notices' );

		// AJAX handlers
		$this->add_action( 'wp_ajax_mcpwp_test_connection', $admin, 'ajax_test_connection' );
		$this->add_action( 'wp_ajax_mcpwp_dismiss_welcome', $admin, 'ajax_dismiss_welcome' );
		$this->add_action( 'wp_ajax_mcpwp_chat', $admin, 'ajax_chat' );
		$this->add_action( 'wp_ajax_mcpwp_chat_execute_tool', $admin, 'ajax_chat_execute_tool' );
		$this->add_action( 'wp_ajax_mcpwp_chat_stream', $admin, 'ajax_chat_stream' );
		$this->add_action( 'wp_ajax_mcpwp_chat_save_history', $admin, 'ajax_chat_save_history' );
		$this->add_action( 'wp_ajax_mcpwp_chat_clear_history', $admin, 'ajax_chat_clear_history' );

		// Integrations AJAX handlers
		$this->add_action( 'wp_ajax_mcpwp_save_integration_key', $integrations_admin, 'ajax_save_key' );
		$this->add_action( 'wp_ajax_mcpwp_remove_integration_key', $integrations_admin, 'ajax_remove_key' );
		$this->add_action( 'wp_ajax_mcpwp_test_integration', $integrations_admin, 'ajax_test_connection' );
		$this->add_action( 'wp_ajax_mcpwp_fix_cloudflare_access', $integrations_admin, 'ajax_fix_cloudflare_access' );
		$this->add_action( 'admin_post_mcpwp_figma_oauth_start', $integrations_admin, 'handle_figma_oauth_start' );
		$this->add_action( 'admin_post_mcpwp_figma_oauth_callback', $integrations_admin, 'handle_figma_oauth_callback' );
		$this->add_action( 'admin_post_nopriv_mcpwp_figma_oauth_callback', $integrations_admin, 'handle_figma_oauth_callback' );

		// mumcp.com broker — admin Connect / Disconnect browser legs.
		$this->add_action( 'admin_post_mcpwp_broker_connect', $broker_admin, 'handle_connect' );
		$this->add_action( 'admin_post_mcpwp_broker_disconnect', $broker_admin, 'handle_disconnect' );

		// WebMCP in wp-admin (#981): script + origin-trial meta, both no-ops
		// unless switched on and the user qualifies.
		$this->add_action( 'admin_enqueue_scripts', 'Mcpwp_WebMCP', 'enqueue' );
		$this->add_action( 'admin_head', 'Mcpwp_WebMCP', 'print_origin_trial_meta' );

		// Plugin action links.
		$this->add_filter( 'plugin_action_links_' . MCPWP_PLUGIN_BASENAME, $admin, 'add_action_links', 100 );

		// Register built-in MCP integrations via the mcpwp_integrations filter.
		$this->add_filter( 'mcpwp_integrations', $this, 'register_builtin_integrations' );
	}

	/**
	 * Register built-in MCP integrations.
	 *
	 * @param array $integrations Existing integrations.
	 * @return array
	 */
	public function register_builtin_integrations( $integrations ) {
		$integrations[] = new Mcpwp_MCP_AI_Integration();
		$integrations[] = new Mcpwp_MCP_Figma_Integration();
		// WebMCP-only tools (#981): exposed to the registries only during a
		// WebMCP dispatch — see Mcpwp_WebMCP_Integration.
		$integrations[] = new Mcpwp_WebMCP_Integration();
		return $integrations;
	}

	/**
	 * Register API hooks.
	 */
	private function define_api_hooks() {
		$ai_presence = new Mcpwp_AI_Presence();
		$indexnow    = new Mcpwp_IndexNow();

		// Initialize REST API
		$this->add_action( 'rest_api_init', $this, 'register_rest_routes' );
		$this->add_action( 'init', $ai_presence, 'register' );
		$this->add_action( 'init', $indexnow, 'register' );
		// Attach rate-limit headers to both success and error responses.
		$this->add_filter( 'rest_post_dispatch', $this, 'add_mcpwp_rate_limit_headers', 10, 3 );
		// Ensure log cleanup cron is scheduled and executed.
		$this->add_action( 'init', $this, 'maybe_schedule_log_cleanup' );
		$this->add_action( 'mcpwp_cleanup_logs', $this, 'cleanup_old_logs' );

		// Alert checks (cron).
		$this->add_filter( 'cron_schedules', $this, 'register_cron_schedules' );
		$this->add_action( 'init', $this, 'maybe_schedule_alert_checks' );
		$this->add_action( 'mcpwp_check_alerts', $this, 'check_alerts' );

		// Jev approval-queue triage (optional, opt-in, BYOK): a single
		// wp-cron event per newly created approval, scheduled from
		// Mcpwp_Approvals — never run inline with the create call.
		$this->add_action( Mcpwp_Jev_Triage::HOOK, 'Mcpwp_Jev_Triage', 'run', 10, 2 );

		// Invalidate the capabilities cache when any plugin is activated or
		// deactivated, so newly-enabled integrations (WooCommerce, LearnPress, …)
		// are detected immediately instead of after the 1-hour transient TTL.
		$this->add_action( 'activated_plugin', 'Mcpwp_Core', 'clear_capabilities_cache' );
		$this->add_action( 'deactivated_plugin', 'Mcpwp_Core', 'clear_capabilities_cache' );
	}

	/**
	 * Register custom cron schedules.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public function register_cron_schedules( $schedules ) {
		if ( ! isset( $schedules['mcpwp_every_five_minutes'] ) ) {
			// The `cron_schedules` filter can run before `init` — e.g. the very
			// first `wp_schedule_event()` call for one of this plugin's own cron
			// hooks happens eagerly on `plugins_loaded` (Mcpwp_Signals::schedule(),
			// Mcpwp_Figma::schedule(), the action-log prune scheduling in
			// mcpwp.php). Calling `__()` at that point loads the 'mcpwp' text
			// domain before WordPress considers it safe to, and WP 6.7+ logs a
			// "_load_textdomain_just_in_time" doing-it-wrong notice on every such
			// first-run. This 'display' label is only ever read by WP-Cron admin
			// UI (which loads well after init), so it is safe — and required —
			// to skip translation on the early call and fall back to the
			// untranslated string; any later call (admin screens) still gets the
			// real translation.
			$display = did_action( 'init' ) ? __( 'Every 5 Minutes (MCPWP)', 'mcpwp' ) : 'Every 5 Minutes (MCPWP)';

			$schedules['mcpwp_every_five_minutes'] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => $display,
			);
		}

		return $schedules;
	}

	/**
	 * Ensure periodic alert checks are scheduled.
	 */
	public function maybe_schedule_alert_checks() {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
			return;
		}

		if ( wp_next_scheduled( 'mcpwp_check_alerts' ) ) {
			return;
		}

		wp_schedule_event( time() + 2 * MINUTE_IN_SECONDS, 'mcpwp_every_five_minutes', 'mcpwp_check_alerts' );
	}

	/**
	 * Run alert checks.
	 */
	public function check_alerts() {
		if ( ! class_exists( 'Mcpwp_Alerts' ) ) {
			return;
		}

		$alerts = new Mcpwp_Alerts();
		$alerts->check_alerts();
	}

	/**
	 * Ensure periodic activity-log cleanup is scheduled.
	 */
	public function maybe_schedule_log_cleanup() {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
			return;
		}

		if ( wp_next_scheduled( 'mcpwp_cleanup_logs' ) ) {
			return;
		}

		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'mcpwp_cleanup_logs' );
	}

	/**
	 * Cleanup old activity logs using configured retention days.
	 */
	public function cleanup_old_logs() {
		global $wpdb;
		if ( ! isset( $wpdb->prefix ) ) {
			return;
		}

		$settings = get_option( 'mcpwp_settings', array() );
		$days     = isset( $settings['log_retention_days'] ) ? max( 1, absint( $settings['log_retention_days'] ) ) : 30;
		$table    = $wpdb->prefix . 'mcpwp_activity_log';
		$cutoff   = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < %s",
				$cutoff
			)
		);
	}

	/**
	 * Register all REST routes.
	 */
	public function register_rest_routes() {
		// Site surfaces (split from the former monolithic Mcpwp_REST_Site — G1).
		( new Mcpwp_REST_OpenAPI() )->register_routes();
		( new Mcpwp_REST_Site_Info() )->register_routes();
		( new Mcpwp_REST_Site_Settings() )->register_routes();
		( new Mcpwp_REST_Site_Content() )->register_routes();
		( new Mcpwp_REST_Site_Taxonomy() )->register_routes();
		( new Mcpwp_REST_Site_Custom_Css() )->register_routes();
		( new Mcpwp_REST_Site_Updates() )->register_routes();
		( new Mcpwp_REST_Site_Library() )->register_routes();
		( new Mcpwp_REST_Site_Network() )->register_routes();
		( new Mcpwp_REST_Site_Cache() )->register_routes();

		// Content graph and internal links
		$content_graph_controller = new Mcpwp_REST_Content_Graph();
		$content_graph_controller->register_routes();

		// Site graph read surface (#943 S2)
		if ( class_exists( 'Mcpwp_REST_Graph' ) ) {
			$graph_controller = new Mcpwp_REST_Graph();
			$graph_controller->register_routes();
		}

		// SEO audit
		$seo_audit_controller = new Mcpwp_REST_SEO_Audit();
		$seo_audit_controller->register_routes();

		// SEO market data — visibility and opportunities
		$seo_market = new Mcpwp_REST_SEO_Market();
		$seo_market->register_routes();

		// AI crawler policy + IndexNow
		$ai_presence_controller = new Mcpwp_REST_AI_Presence();
		$ai_presence_controller->register_routes();

		// Content quality
		$content_quality_controller = new Mcpwp_REST_Content_Quality();
		$content_quality_controller->register_routes();

		// Posts
		$posts_controller = new Mcpwp_REST_Posts();
		$posts_controller->register_routes();

		// Pages
		$pages_controller = new Mcpwp_REST_Pages();
		$pages_controller->register_routes();

		// Media
		$media_controller = new Mcpwp_REST_Media();
		$media_controller->register_routes();

		// Menus
		$menus_controller = new Mcpwp_REST_Menus();
		$menus_controller->register_routes();

		// Generic content (custom post types)
		$content_controller = new Mcpwp_REST_Content();
		$content_controller->register_routes();

		// Elementor (basic)
		$elementor_controller = new Mcpwp_REST_Elementor();
		$elementor_controller->register_routes();

		// Webhooks
		$webhooks_controller = new Mcpwp_REST_Webhooks();
		$webhooks_controller->register_routes();

		// Screenshot
		$screenshot_controller = new Mcpwp_REST_Screenshot();
		$screenshot_controller->register_routes();

		// Cloudflare
		$cloudflare_controller = new Mcpwp_REST_Cloudflare();
		$cloudflare_controller->register_routes();

		// Feedback
		$feedback_controller = new Mcpwp_REST_Feedback();
		$feedback_controller->register_routes();

		// Blocks (Gutenberg)
		$blocks_controller = new Mcpwp_REST_Blocks();
		$blocks_controller->register_routes();

		// Approvals
		$approvals_controller = new Mcpwp_REST_Approvals();
		$approvals_controller->register_routes();

		// Action log
		$action_log_controller = new Mcpwp_REST_Action_Log();
		$action_log_controller->register_routes();

		// Site Memory (#362)
		if ( class_exists( 'Mcpwp_REST_Site_Memory' ) ) {
			$memory_controller = new Mcpwp_REST_Site_Memory();
			$memory_controller->register_routes();
		}

		// Site Signals (#363)
		if ( class_exists( 'Mcpwp_REST_Signals' ) ) {
			$signals_controller = new Mcpwp_REST_Signals();
			$signals_controller->register_routes();
		}

		// Site Blueprints (#364)
		if ( class_exists( 'Mcpwp_REST_Site_Blueprints' ) ) {
			$blueprints_controller = new Mcpwp_REST_Site_Blueprints();
			$blueprints_controller->register_routes();
		}

		// MCP (Model Context Protocol)
		$mcp_controller = new Mcpwp_REST_MCP();
		$mcp_controller->register_routes();

		// OAuth 2.1 — authorize + token REST routes.
		if ( class_exists( 'Mcpwp_REST_OAuth' ) ) {
			( new Mcpwp_REST_OAuth() )->register_routes();
		}

		// mumcp.com broker — enrollment handshake (enroll-callback + enroll-confirm).
		if ( class_exists( 'Mcpwp_REST_Broker' ) ) {
			( new Mcpwp_REST_Broker() )->register_routes();
		}

		// Batch
		$batch_controller = new Mcpwp_REST_Batch();
		$batch_controller->register_routes();

		// WebMCP in wp-admin (#981) — cookie + nonce only; 404 while switched off.
		if ( class_exists( 'Mcpwp_REST_WebMCP' ) ) {
			( new Mcpwp_REST_WebMCP() )->register_routes();
		}

		/**
		 * Action to register additional REST routes.
		 *
		 * Used by Pro add-on to register additional endpoints.
		 */
		do_action( 'mcpwp_register_rest_routes' );

		// Register routes from third-party integrations.
		if ( class_exists( 'Mcpwp_Integration' ) ) {
			foreach ( Mcpwp_Integration::resolve_all() as $integration ) {
				$integration->register_routes();
			}
		}
	}

	/**
	 * Add MCPWP rate-limit headers to REST responses.
	 *
	 * @param WP_HTTP_Response $response Result to send to the client.
	 * @param WP_REST_Server   $server   Server instance.
	 * @param WP_REST_Request  $request  Request used to generate the response.
	 * @return WP_HTTP_Response Filtered response.
	 */
	public function add_mcpwp_rate_limit_headers( $response, $server, $request ) {
		if ( ! class_exists( 'Mcpwp_Rate_Limiter' ) ) {
			return $response;
		}

		if ( ! $request instanceof WP_REST_Request ) {
			return $response;
		}

		if ( ! method_exists( $request, 'get_route' ) || ! method_exists( $response, 'header' ) ) {
			return $response;
		}

		$route = (string) $request->get_route();
		if ( 0 !== strpos( $route, '/mcpwp/v1/' ) ) {
			return $response;
		}

		$limiter = Mcpwp_Rate_Limiter::get_instance();
		$headers = $limiter->get_headers();

		foreach ( $headers as $key => $value ) {
			$response->header( $key, $value );
		}

		$status = method_exists( $response, 'get_status' ) ? (int) $response->get_status() : 200;

		// Ensure we log permission_callback failures and server errors which never reach controller-level logging.
		if ( $status >= 400 ) {
			$payload = null;
			if ( method_exists( $response, 'get_data' ) ) {
				$payload = $response->get_data();
			}
			$this->log_activity( 'rest_error', $request, $payload, $status );
		}

		if ( 429 !== $status ) {
			return $response;
		}

		// Ensure 429 responses always have a JSON body (#92).
		$data = method_exists( $response, 'get_data' ) ? $response->get_data() : null;

		if ( empty( $data ) ) {
			// Build a fallback body from rate limiter state.
			$rl_headers = $limiter->get_headers();
			$retry      = isset( $rl_headers['Retry-After'] ) ? (int) $rl_headers['Retry-After'] : 0;
			$response->set_data( array(
				'code'    => 'rate_limit_exceeded',
				'message' => sprintf( 'Rate limit exceeded. Try again in %d seconds.', $retry ),
				'data'    => array(
					'status'      => 429,
					'retry_after' => $retry,
				),
			) );
			$data = $response->get_data();
		}

		$response->header( 'Content-Type', 'application/json; charset=UTF-8' );

		if ( is_array( $data ) && ! empty( $data['data'] ) && is_array( $data['data'] ) ) {
			if ( isset( $data['data']['retry_after'] ) ) {
				$response->header( 'Retry-After', max( 0, (int) $data['data']['retry_after'] ) );
			}

			if ( isset( $data['data']['limit'] ) ) {
				$response->header( 'X-RateLimit-Limit', (int) $data['data']['limit'] );
			}

			if ( isset( $data['data']['remaining'] ) ) {
				$response->header( 'X-RateLimit-Remaining', max( 0, (int) $data['data']['remaining'] ) );
			}

			if ( isset( $data['data']['reset'] ) ) {
				// data.reset is an epoch; the header is seconds-until-reset on
				// every 200 (Mcpwp_Rate_Limiter::get_headers), so a 429 must
				// not switch units on the client (#802).
				$response->header( 'X-RateLimit-Reset', max( 0, (int) $data['data']['reset'] - time() ) );
			}

			if ( ! empty( $data['data']['bucket'] ) ) {
				$response->header( 'X-RateLimit-Bucket', (string) $data['data']['bucket'] );
			}

			if ( ! empty( $data['data']['window'] ) ) {
				$response->header( 'X-RateLimit-Window', (string) $data['data']['window'] );
			}
		}

		return $response;
	}

	/**
	 * Add an action to the collection.
	 *
	 * @param string $hook          Hook name.
	 * @param object $component     Component with the callback.
	 * @param string $callback      Callback method name.
	 * @param int    $priority      Priority.
	 * @param int    $accepted_args Number of accepted arguments.
	 */
	public function add_action( $hook, $component, $callback, $priority = 10, $accepted_args = 1 ) {
		$this->actions = $this->add( $this->actions, $hook, $component, $callback, $priority, $accepted_args );
	}

	/**
	 * Add a filter to the collection.
	 *
	 * @param string $hook          Hook name.
	 * @param object $component     Component with the callback.
	 * @param string $callback      Callback method name.
	 * @param int    $priority      Priority.
	 * @param int    $accepted_args Number of accepted arguments.
	 */
	public function add_filter( $hook, $component, $callback, $priority = 10, $accepted_args = 1 ) {
		$this->filters = $this->add( $this->filters, $hook, $component, $callback, $priority, $accepted_args );
	}

	/**
	 * Add hook to collection.
	 *
	 * @param array  $hooks         Current hooks.
	 * @param string $hook          Hook name.
	 * @param object $component     Component with the callback.
	 * @param string $callback      Callback method name.
	 * @param int    $priority      Priority.
	 * @param int    $accepted_args Number of accepted arguments.
	 * @return array Updated hooks.
	 */
	private function add( $hooks, $hook, $component, $callback, $priority, $accepted_args ) {
		$hooks[] = array(
			'hook'          => $hook,
			'component'     => $component,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);
		return $hooks;
	}

	/**
	 * Register all hooks with WordPress.
	 */
	public function run() {
		foreach ( $this->filters as $hook ) {
			add_filter(
				$hook['hook'],
				array( $hook['component'], $hook['callback'] ),
				$hook['priority'],
				$hook['accepted_args']
			);
		}

		foreach ( $this->actions as $hook ) {
			add_action(
				$hook['hook'],
				array( $hook['component'], $hook['callback'] ),
				$hook['priority'],
				$hook['accepted_args']
			);
		}
	}
}
