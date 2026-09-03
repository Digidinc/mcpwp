<?php
/**
 * Feedback REST Controller
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for AI feedback submission and listing.
 */
class Mcpwp_REST_Feedback extends Mcpwp_REST_API {

	/**
	 * Per-IP ceiling on relay submissions inside RELAY_RATE_WINDOW.
	 *
	 * @var int
	 */
	const RELAY_RATE_MAX = 10;

	/**
	 * Rolling window for the per-IP relay ceiling above, in seconds.
	 *
	 * @var int
	 */
	const RELAY_RATE_WINDOW = 60;

	/**
	 * Transient key prefix for the per-IP relay counter.
	 *
	 * @var string
	 */
	const RELAY_IP_TRANSIENT_PREFIX = 'mcpwp_relay_';

	/**
	 * Site-wide ceiling on relay submissions inside RELAY_GLOBAL_WINDOW,
	 * counted across every caller regardless of source address.
	 *
	 * Each accepted relay request spends this site's own GitHub token: a
	 * search call plus, usually, an issue-creation call. 200 per hour is
	 * roughly 400 GitHub API calls per hour, comfortably inside GitHub's
	 * 5,000/hour authenticated primary limit and its content-creation
	 * secondary limit, while sitting about two orders of magnitude above any
	 * plausible volume of genuine bug reports arriving from the installed
	 * fleet in one hour. It therefore never fires for real traffic, and it
	 * caps a flood spread across many real source addresses, which the
	 * per-IP counter above cannot see at all.
	 *
	 * @var int
	 */
	const RELAY_GLOBAL_MAX = 200;

	/**
	 * Rolling window for the site-wide relay ceiling above, in seconds.
	 *
	 * One hour is the coarsest window that still recovers on its own inside
	 * a normal support cycle.
	 *
	 * @var int
	 */
	const RELAY_GLOBAL_WINDOW = HOUR_IN_SECONDS;

	/**
	 * Transient key prefix for the site-wide relay counter.
	 *
	 * @var string
	 */
	const RELAY_GLOBAL_TRANSIENT_PREFIX = 'mcpwp_relay_global_';

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/feedback',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'submit_feedback' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'type'        => array(
							'description' => __( 'Feedback type.', 'mcpwp' ),
							'type'        => 'string',
							'required'    => true,
							'enum'        => array( 'bug_report', 'feature_request', 'feedback' ),
						),
						'title'       => array(
							'description' => __( 'Short summary.', 'mcpwp' ),
							'type'        => 'string',
							'required'    => true,
						),
						'description' => array(
							'description' => __( 'Detailed description.', 'mcpwp' ),
							'type'        => 'string',
							'required'    => true,
						),
						'agent'       => array(
							'description' => __( 'AI model or agent name.', 'mcpwp' ),
							'type'        => 'string',
							'default'     => '',
						),
						'priority'    => array(
							'description' => __( 'Priority level.', 'mcpwp' ),
							'type'        => 'string',
							'enum'        => array( 'low', 'medium', 'high', 'critical' ),
							'default'     => 'medium',
						),
						'meta'        => array(
							'description' => __( 'Extra context as JSON object.', 'mcpwp' ),
							'type'        => 'object',
							'default'     => array(),
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_feedback' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'type'   => array(
							'description' => __( 'Filter by feedback type.', 'mcpwp' ),
							'type'        => 'string',
							'enum'        => array( 'bug_report', 'feature_request', 'feedback' ),
						),
						'status' => array(
							'description' => __( 'Filter by status.', 'mcpwp' ),
							'type'        => 'string',
							'enum'        => array( 'open', 'acknowledged', 'resolved', 'closed', 'all' ),
							'default'     => 'open',
						),
						'limit'  => array(
							'description' => __( 'Maximum results.', 'mcpwp' ),
							'type'        => 'integer',
							'default'     => 20,
							'minimum'     => 1,
							'maximum'     => 100,
						),
					),
				),
			)
		);

		// Public relay endpoint — receives feedback from any MCPWP install.
		// No API key required; rate-limited by IP.
		register_rest_route(
			$this->namespace,
			'/feedback/relay',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'relay_feedback' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'type'           => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'bug_report', 'feature_request', 'feedback' ),
					),
					'title'          => array(
						'type'     => 'string',
						'required' => true,
					),
					'description'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'agent'          => array(
						'type'    => 'string',
						'default' => '',
					),
					'priority'       => array(
						'type'    => 'string',
						'enum'    => array( 'low', 'medium', 'high', 'critical' ),
						'default' => 'medium',
					),
					'meta'           => array(
						'type'    => 'object',
						'default' => array(),
					),
					'site_url'       => array(
						'type'    => 'string',
						'default' => '',
					),
					'site_name'      => array(
						'type'    => 'string',
						'default' => '',
					),
					'plugin_version' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
	}

	/**
	 * Submit feedback.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function submit_feedback( $request ) {
		$result = Mcpwp_Feedback::submit(
			array(
				'type'        => $request->get_param( 'type' ),
				'title'       => $request->get_param( 'title' ),
				'description' => $request->get_param( 'description' ),
				'agent'       => $request->get_param( 'agent' ),
				'priority'    => $request->get_param( 'priority' ),
				'meta'        => $request->get_param( 'meta' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->log_activity( 'submit_feedback', $request, $result );

		return $this->success_response( $result, 201 );
	}

	/**
	 * Relay feedback from remote MCPWP installs.
	 *
	 * Public endpoint — no API key required.
	 * Creates a GitHub issue using this server's github_token.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function relay_feedback( $request ) {
		// Per-IP throttle: no single source may exceed RELAY_RATE_MAX
		// submissions inside RELAY_RATE_WINDOW seconds.
		$ip        = $this->get_relay_client_ip();
		$transient = self::RELAY_IP_TRANSIENT_PREFIX . md5( $ip );
		$count     = (int) get_transient( $transient );
		if ( $count >= self::RELAY_RATE_MAX ) {
			return new WP_Error(
				'rate_limited',
				__( 'Too many feedback submissions. Please try again later.', 'mcpwp' ),
				array( 'status' => 429 )
			);
		}

		// Site-wide backstop, counted across every caller regardless of source
		// address. The per-IP bucket above bounds one source and does nothing
		// against a flood spread over many real addresses, while every accepted
		// request here spends this site's own GitHub token on behalf of an
		// unauthenticated caller. Checked before either counter is incremented,
		// so a refused request consumes no budget.
		$global_transient = $this->relay_global_transient_key();
		$global_count     = (int) get_transient( $global_transient );
		if ( $global_count >= self::RELAY_GLOBAL_MAX ) {
			return new WP_Error(
				'rate_limited',
				__( 'Too many feedback submissions. Please try again later.', 'mcpwp' ),
				array( 'status' => 429 )
			);
		}

		set_transient( $transient, $count + 1, self::RELAY_RATE_WINDOW );
		set_transient( $global_transient, $global_count + 1, self::RELAY_GLOBAL_WINDOW );

		$data = array(
			'type'           => $request->get_param( 'type' ),
			'title'          => $request->get_param( 'title' ),
			'description'    => $request->get_param( 'description' ),
			'agent'          => $request->get_param( 'agent' ),
			'priority'       => $request->get_param( 'priority' ),
			'meta'           => $request->get_param( 'meta' ),
			'site_url'       => $request->get_param( 'site_url' ),
			'site_name'      => $request->get_param( 'site_name' ),
			'plugin_version' => $request->get_param( 'plugin_version' ),
		);

		$result = Mcpwp_Feedback::create_github_issue_from_relay( $data );

		if ( ! $result['success'] ) {
			return new WP_Error(
				'relay_failed',
				$result['message'],
				array( 'status' => 502 )
			);
		}

		return new WP_REST_Response(
			array(
				'success'          => true,
				'github_issue_url' => $result['github_issue_url'],
				'message'          => $result['message'],
			),
			201
		);
	}

	/**
	 * Get client IP address for relay rate limiting.
	 *
	 * This gates an unauthenticated, privileged action (creating a GitHub
	 * issue with the site's own token), so the IP it keys on must not be
	 * attacker-choosable. CF-Connecting-IP is only meaningful when Cloudflare
	 * really is in front of this origin; a caller who reaches the origin
	 * directly can set that header themselves and mint a fresh rate-limit
	 * bucket on every request, which removes the throttle entirely. Honour it
	 * only behind the explicit MCPWP_TRUST_PROXY_HEADERS opt-in — the same
	 * gate the rate limiter, the auth trait and the OAuth well-known scheme
	 * check use; otherwise fall straight to REMOTE_ADDR, the actual TCP peer,
	 * which the HTTP client cannot forge. (If the origin's own web server is
	 * configured to rewrite REMOTE_ADDR from a client-supplied header, that
	 * trust must be fixed at the proxy/nginx layer — no application code can
	 * distinguish a forged REMOTE_ADDR from a real one once the web server
	 * already rewrote it.)
	 *
	 * @return string
	 */
	private function get_relay_client_ip() {
		$trust_proxy = defined( 'MCPWP_TRUST_PROXY_HEADERS' ) && MCPWP_TRUST_PROXY_HEADERS;

		return $this->resolve_relay_client_ip( $trust_proxy );
	}

	/**
	 * Resolve the relay bucket key from $_SERVER for a given trust decision.
	 *
	 * Split out from get_relay_client_ip() so both branches are reachable from
	 * tests: a PHP constant cannot be undefined once set, so a single-process
	 * suite can never exercise both sides of the constant check itself.
	 *
	 * Values that are not valid IP addresses are discarded rather than used as
	 * a bucket key — an unvalidated header value is an unlimited supply of
	 * distinct keys, which is the same evasion by another route.
	 *
	 * @param bool $trust_proxy Whether proxy-supplied headers may be believed.
	 * @return string Validated IP address, or '0.0.0.0' when none is usable.
	 */
	private function resolve_relay_client_ip( $trust_proxy ) {
		$ip_keys = $trust_proxy
			? array(
				'HTTP_CF_CONNECTING_IP', // Cloudflare.
				'REMOTE_ADDR',           // Standard.
			)
			: array( 'REMOTE_ADDR' );

		foreach ( $ip_keys as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return '0.0.0.0';
	}

	/**
	 * Transient key for the site-wide relay counter.
	 *
	 * The key carries the window index, so the ceiling tumbles on a fixed
	 * wall-clock boundary instead of being pushed forward every time an
	 * abusive request refreshes the transient's TTL. A sustained flood cannot
	 * keep legitimate submissions locked out past the current window.
	 *
	 * @return string
	 */
	private function relay_global_transient_key() {
		return self::RELAY_GLOBAL_TRANSIENT_PREFIX . (int) floor( time() / self::RELAY_GLOBAL_WINDOW );
	}

	/**
	 * List feedback entries.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function list_feedback( $request ) {
		$entries = Mcpwp_Feedback::list_entries(
			array(
				'type'   => $request->get_param( 'type' ),
				'status' => $request->get_param( 'status' ),
				'limit'  => $request->get_param( 'limit' ),
			)
		);

		$this->log_activity( 'list_feedback', $request );

		return $this->success_response(
			array(
				'feedback' => $entries,
				'total'    => count( $entries ),
			)
		);
	}
}
