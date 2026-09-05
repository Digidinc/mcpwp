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
	 * Window for the per-IP relay ceiling above, in seconds.
	 *
	 * Tumbling, not sliding — see relay_ip_transient_key(). A per-IP bucket
	 * is frequently shared (any origin that does not restore real client
	 * addresses puts every visitor behind one proxy address), so a bucket
	 * whose TTL is pushed forward by each accepted request can be held at
	 * the ceiling indefinitely by one abusive source, locking out everyone
	 * sharing it. A fixed boundary caps that at the current window.
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
	 * search call (dedupe, against GitHub's search-specific limit of
	 * 30 requests/minute) plus, usually, an issue-creation call (against
	 * GitHub's content-creation secondary limit of 80/minute and 500/hour).
	 * Neither is the 5,000/hour authenticated primary limit — that limit is
	 * never the binding constraint here. At 200/hour, a saturated hour spends
	 * 200 of the 500/hour content-creation budget: 40% of it, not the two
	 * orders of magnitude of headroom once claimed. That is a reasonable
	 * hourly figure on its own, but nothing here spreads it across the hour —
	 * see RELAY_GLOBAL_MAX_PER_MINUTE below for the bound that actually
	 * protects the per-minute limits.
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
	 * Site-wide ceiling on relay submissions inside RELAY_GLOBAL_MINUTE_WINDOW,
	 * counted across every caller regardless of source address.
	 *
	 * RELAY_GLOBAL_MAX alone permits the entire hour's budget to be spent in
	 * a single minute: the per-IP counter allows RELAY_RATE_MAX per source, so
	 * 20 distinct sources at that rate land all 200 inside one minute — 200
	 * search calls against GitHub's 30/minute search limit, 200 issue
	 * creations against its 80/minute content-creation limit. 20/minute keeps
	 * both satisfied (20 <= 30, 20 <= 80) while still reaching the 200/hour
	 * ceiling in ten minutes for a sustained flood.
	 *
	 * @var int
	 */
	const RELAY_GLOBAL_MAX_PER_MINUTE = 20;

	/**
	 * Window for the site-wide per-minute relay ceiling above, in seconds.
	 *
	 * @var int
	 */
	const RELAY_GLOBAL_MINUTE_WINDOW = 60;

	/**
	 * Transient key prefix for the site-wide per-minute relay counter.
	 *
	 * @var string
	 */
	const RELAY_GLOBAL_MINUTE_TRANSIENT_PREFIX = 'mcpwp_relay_global_min_';

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
		//
		// Note deliberately not "fixed" on the read side: get_transient()
		// returns false for both "no requests yet" and "the backend cannot
		// answer right now", and there is no reliable way through the WP
		// transient API to tell those apart here. That is fine — the write
		// check below catches a dead backend before any GitHub call is
		// made, which is the only place it matters on this credential-
		// spending path.
		$ip        = $this->get_relay_client_ip();
		$transient = $this->relay_ip_transient_key( $ip );
		$count     = (int) get_transient( $transient );
		if ( $count >= self::RELAY_RATE_MAX ) {
			return new WP_Error(
				'rate_limited',
				__( 'Too many feedback submissions. Please try again later.', 'mcpwp' ),
				array( 'status' => 429 )
			);
		}

		// Site-wide per-minute backstop, counted across every caller regardless
		// of source address. RELAY_GLOBAL_MAX alone does not spread the hour's
		// budget across it — many distinct sources, each within their own
		// per-IP ceiling, can otherwise land the whole hourly budget inside one
		// minute and trip GitHub's per-minute limits (search, content
		// creation) while the hourly counter still reads as un-tripped.
		// Checked before any counter is incremented, so a refused request
		// consumes no budget.
		$global_minute_transient = $this->relay_global_minute_transient_key();
		$global_minute_count     = (int) get_transient( $global_minute_transient );
		if ( $global_minute_count >= self::RELAY_GLOBAL_MAX_PER_MINUTE ) {
			return new WP_Error(
				'rate_limited',
				__( 'Too many feedback submissions. Please try again later.', 'mcpwp' ),
				array( 'status' => 429 )
			);
		}

		// Site-wide hourly backstop, counted across every caller regardless of
		// source address. The per-IP bucket above bounds one source and does
		// nothing against a flood spread over many real addresses, while every
		// accepted request here spends this site's own GitHub token on behalf
		// of an unauthenticated caller. Checked before either counter is
		// incremented, so a refused request consumes no budget.
		$global_transient = $this->relay_global_transient_key();
		$global_count     = (int) get_transient( $global_transient );
		if ( $global_count >= self::RELAY_GLOBAL_MAX ) {
			return new WP_Error(
				'rate_limited',
				__( 'Too many feedback submissions. Please try again later.', 'mcpwp' ),
				array( 'status' => 429 )
			);
		}

		// A failed write means the ceiling checked above cannot be enforced
		// for the next request — this bucket will read back as zero no
		// matter how many requests actually land, and the site's GitHub
		// token would be spent on every one of them. Refuse rather than
		// spend it. All writes happen before any GitHub call, so refusing
		// here never lets a request through to create an issue. If an
		// earlier write above succeeds but a later write below fails, we
		// still refuse — the earlier bucket is left slightly over-counted
		// rather than rolled back, which is the safe direction.
		if ( false === set_transient( $transient, $count + 1, self::RELAY_RATE_WINDOW ) ) {
			return new WP_Error(
				'relay_unavailable',
				__( 'Feedback relay is temporarily unavailable. Please try again later.', 'mcpwp' ),
				array( 'status' => 503 )
			);
		}

		if ( false === set_transient( $global_minute_transient, $global_minute_count + 1, self::RELAY_GLOBAL_MINUTE_WINDOW ) ) {
			return new WP_Error(
				'relay_unavailable',
				__( 'Feedback relay is temporarily unavailable. Please try again later.', 'mcpwp' ),
				array( 'status' => 503 )
			);
		}

		if ( false === set_transient( $global_transient, $global_count + 1, self::RELAY_GLOBAL_WINDOW ) ) {
			return new WP_Error(
				'relay_unavailable',
				__( 'Feedback relay is temporarily unavailable. Please try again later.', 'mcpwp' ),
				array( 'status' => 503 )
			);
		}

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
	 * Header list and comma-splitting deliberately match the two siblings
	 * (Mcpwp_Rate_Limiter::get_client_identifier(),
	 * Mcpwp_API_Auth::get_client_ip()) — see #753. A shorter list here had no
	 * stated rationale and left an operator behind a non-Cloudflare proxy
	 * (nginx/Varnish setting X-Real-IP, no CF-Connecting-IP) falling to
	 * REMOTE_ADDR — the proxy's own address — collapsing every caller into
	 * one bucket.
	 *
	 * @param bool $trust_proxy Whether proxy-supplied headers may be believed.
	 * @return string Validated IP address, or 'unknown' when none is usable.
	 */
	private function resolve_relay_client_ip( $trust_proxy ) {
		$ip_keys = $trust_proxy
			? array(
				'HTTP_CF_CONNECTING_IP', // Cloudflare.
				'HTTP_X_FORWARDED_FOR',  // Proxy.
				'HTTP_X_REAL_IP',        // Nginx.
				'REMOTE_ADDR',           // Standard.
			)
			: array( 'REMOTE_ADDR' );

		foreach ( $ip_keys as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				// Handle comma-separated IPs (X-Forwarded-For).
				if ( strpos( $ip, ',' ) !== false ) {
					$ip = trim( explode( ',', $ip )[0] );
				}
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return 'unknown';
	}

	/**
	 * Transient key for one source address's relay counter.
	 *
	 * Carries the window index for the same reason the site-wide key does:
	 * without it, every accepted request rewrites the transient's TTL and an
	 * abusive source can hold the bucket at the ceiling indefinitely by
	 * submitting once per window. That matters more here than for the
	 * site-wide counter, because this bucket is not always one caller — on
	 * any origin that does not restore real client addresses, every visitor
	 * behind the proxy shares it, and the lockout would fall on them.
	 *
	 * @param string   $ip  Source address.
	 * @param int|null $now Timestamp to derive the window from. Defaults to
	 *                      the current time; taken as an argument so a test
	 *                      can cross a window boundary without sleeping.
	 * @return string
	 */
	private function relay_ip_transient_key( $ip, $now = null ) {
		$now = null === $now ? time() : (int) $now;
		return self::RELAY_IP_TRANSIENT_PREFIX . md5( $ip ) . '_' . (int) floor( $now / self::RELAY_RATE_WINDOW );
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
	 * Transient key for the site-wide per-minute relay counter.
	 *
	 * Tumbling for the same reason as relay_global_transient_key() and
	 * relay_ip_transient_key() above: a fixed wall-clock boundary, not a
	 * TTL pushed forward by each accepted request.
	 *
	 * @param int|null $now Timestamp to derive the window from. Defaults to
	 *                      the current time; taken as an argument so a test
	 *                      can cross a window boundary without sleeping.
	 * @return string
	 */
	private function relay_global_minute_transient_key( $now = null ) {
		$now = null === $now ? time() : (int) $now;
		return self::RELAY_GLOBAL_MINUTE_TRANSIENT_PREFIX . (int) floor( $now / self::RELAY_GLOBAL_MINUTE_WINDOW );
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
