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
	 * Object-cache group holding the admission counters (#752 F6).
	 *
	 * The increment gate is only as persistent as this group. When a recognised
	 * drop-in reports the group non-persistent — Redis Object Cache's
	 * ignored_groups (WP_REDIS_IGNORED_GROUPS, wp_cache_add_non_persistent_groups()),
	 * or Automattic memcached's no_mc_groups — relay_admission_decision() skips
	 * the gate and admission is approximate. For memcached that is a liveness
	 * matter as well: its add() keeps a non-persistent group in-process while
	 * its incr() always asks the server, so the gate would miss every key and
	 * refuse every relay. An unrecognised drop-in that does not persist the
	 * group cannot be detected; see RELAY_ADMISSION_UNVERIFIED.
	 *
	 * @var string
	 */
	const RELAY_ADMISSION_CACHE_GROUP = 'mcpwp_relay_admission';

	/**
	 * Admission mode: decided on an increment known to be atomic — a recognised
	 * drop-in (Redis Object Cache without igbinary, Automattic memcached) whose
	 * backend is reachable and which persists the admission group. A request
	 * during which that stops holding is refused before anything is spent; see
	 * relay_admission_drift().
	 *
	 * @var string
	 */
	const RELAY_ADMISSION_ATOMIC = 'atomic';

	/**
	 * Admission mode: decided on the increment of an object-cache drop-in this
	 * plugin does not recognise. Whether that increment is atomic, and whether
	 * the drop-in is persisting anything at all right now, is not known — so
	 * the report says exactly that instead of claiming either.
	 *
	 * @var string
	 */
	const RELAY_ADMISSION_UNVERIFIED = 'unverified';

	/**
	 * Admission mode: concurrent requests can exceed a ceiling. The transient
	 * read-then-write decides, with or without the increment gate beside it.
	 *
	 * @var string
	 */
	const RELAY_ADMISSION_APPROXIMATE = 'approximate';

	/**
	 * Admission mode: no counter can outlast the request — Redis Object Cache
	 * reports its backend unreachable and keeps everything, transients
	 * included, in memory for this request only — so the relay refuses every
	 * request rather than run with no ceiling at all. (Automattic memcached has
	 * no in-memory fallback: a dead backend already fails every increment and
	 * transient write, so it needs no pre-emptive refusal.)
	 *
	 * @var string
	 */
	const RELAY_ADMISSION_FAIL_CLOSED = 'fail_closed';

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
		// One timestamp for every counter key, so a request that lands on a
		// window boundary is charged to one consistent set of windows.
		$now      = time();
		$decision = self::relay_admission_decision();

		// No ceiling can be enforced: every counter, the transient record
		// included, would live and die with this request. Refuse before any
		// work rather than spend the site's token with no limit at all.
		if ( $decision['refuse'] ) {
			return new WP_Error(
				'relay_unavailable',
				__( 'Feedback relay is temporarily unavailable. Please try again later.', 'mcpwp' ),
				array( 'status' => 503 )
			);
		}

		// Stage 1 — the accounting record. All three transient counters are
		// read and tested before anything is incremented, whatever the
		// admission decision. Where the increment gate also runs this is not
		// redundant: it refuses a saturated window without taking and handing
		// back a slot, and behind a drop-in whose increment is not what it
		// seems ('unverified') it still holds the ceiling at the approximate
		// level. It can never refuse a request the gate would admit: a
		// read-then-write record only ever under-counts.
		//
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
		$transient = $this->relay_ip_transient_key( $ip, $now );
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
		$global_minute_transient = $this->relay_global_minute_transient_key( $now );
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
		$global_transient = $this->relay_global_transient_key( $now );
		$global_count     = (int) get_transient( $global_transient );
		if ( $global_count >= self::RELAY_GLOBAL_MAX ) {
			return new WP_Error(
				'rate_limited',
				__( 'Too many feedback submissions. Please try again later.', 'mcpwp' ),
				array( 'status' => 429 )
			);
		}

		// Stage 2 — the increment gate (#752 F6). The reads above and the
		// writes below are not one step: N requests that all read before any
		// of them writes all see the same count and all pass, so each ceiling
		// overshoots by roughly the concurrency level. Where the object cache
		// offers an increment that is or may be atomic, the decision is taken
		// on that increment instead. relay_admission_decision() says when, and
		// whether the increment is known to be atomic; GET /rate-limit reports
		// that same decision.
		if ( $decision['gate'] ) {
			$refusal = $this->admit_relay_atomically(
				array(
					array( $transient, self::RELAY_RATE_MAX, self::RELAY_RATE_WINDOW ),
					array( $global_minute_transient, self::RELAY_GLOBAL_MAX_PER_MINUTE, self::RELAY_GLOBAL_MINUTE_WINDOW ),
					array( $global_transient, self::RELAY_GLOBAL_MAX, self::RELAY_GLOBAL_WINDOW ),
				)
			);
			if ( null !== $refusal ) {
				return $refusal;
			}

			// The decision was read before the gate touched the cache, and a
			// drop-in can lose its backend inside the gate. Redis Object Cache
			// catches an exception thrown by wp_cache_add() — Redis refusing
			// writes while serving reads: OOM under noeviction, READONLY after
			// a failover, MISCONF, on a client that throws — marks itself
			// disconnected and answers every later increment from a per-request
			// array, where the first increment of any key is 1. Those "counts"
			// just admitted this request without it being counted anywhere.
			// Read the decision again before writing anything else.
			$drift = self::relay_admission_drift( $decision );
			if ( null !== $drift ) {
				return $drift;
			}
		}

		// Stage 3 — write the accounting record.
		//
		// A failed write means the ceiling checked above cannot be enforced
		// for the next request — this bucket will read back as zero no
		// matter how many requests actually land, and the site's GitHub
		// token would be spent on every one of them. Refuse rather than
		// spend it. All writes happen before any GitHub call, so refusing
		// here never lets a request through to create an issue. If an
		// earlier write above succeeds but a later write below fails, we
		// still refuse — the earlier bucket is left slightly over-counted
		// rather than rolled back, which is the safe direction. Where stage 2
		// ran, the slots taken there are likewise kept on a 503 here, for the
		// same reason.
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

		// Last check before the site's token is spent. The transient writes
		// above can lose the backend the same way (and so can the stage 1
		// reads, where a failed read counts as zero): with the drop-in now in
		// memory every write "succeeds" and nothing was recorded. This is the
		// only defence when the gate is off. If the counts this request was
		// admitted on may not have outlived it, spend nothing.
		$drift = self::relay_admission_drift( $decision );
		if ( null !== $drift ) {
			return $drift;
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
	 * @param int|null $now Timestamp to derive the window from. Defaults to
	 *                      the current time.
	 * @return string
	 */
	private function relay_global_transient_key( $now = null ) {
		$now = null === $now ? time() : (int) $now;
		return self::RELAY_GLOBAL_TRANSIENT_PREFIX . (int) floor( $now / self::RELAY_GLOBAL_WINDOW );
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
	 * How relay admission is enforced on this site, and on what evidence (#752 F6).
	 *
	 * The one decision both relay_feedback() admits by and GET /rate-limit
	 * reports, so the two cannot disagree.
	 *
	 * It claims only what it can see. wp_using_ext_object_cache() says an
	 * object-cache drop-in is loaded; it says nothing about whether that
	 * drop-in's increment is atomic, whether its backend is reachable, or
	 * whether it persists the admission group. Those are read from the drop-in
	 * itself where it is recognised, and declared unknown where it is not.
	 * Checked in this order, first match wins:
	 *
	 *  mode         basis                                     gate  refuse
	 *  approximate  no_persistent_object_cache                no    no
	 *  approximate  object_cache_api_incomplete               no    no
	 *  fail_closed  object_cache_backend_unreachable          no    yes
	 *  approximate  cache_addition_suspended                  no    no
	 *  approximate  admission_group_not_persistent            no    no
	 *  approximate  object_cache_incr_not_atomic              no    no
	 *  approximate  object_cache_max_ttl_shorter_than_window  yes   no
	 *  approximate  object_cache_pool_degraded                yes   no
	 *  atomic       redis_object_cache                        yes   no
	 *  atomic       memcached_object_cache                    yes   no
	 *  unverified   unrecognised_object_cache                 yes   no
	 *
	 * - object_cache_backend_unreachable: Redis Object Cache's redis_status() is
	 *   false (connection failed, or an exception earlier in this request),
	 *   after which that drop-in keeps every group — transients included — in a
	 *   per-request array. No counter can outlast the request, so the relay
	 *   would be unlimited. Refusing is the only honest enforcement. The same
	 *   state can begin inside a request, after this decision was read; that is
	 *   what relay_admission_drift() catches.
	 * - object_cache_pool_degraded: Automattic memcached has recorded a
	 *   connection error this request. The drop-in exposes no per-server health
	 *   beyond that list, and one failed member of a pool is enough to fill it
	 *   (every request probes each server for its flush number), while the pool
	 *   itself rehashes to the live members — where a counter that lived on the
	 *   failed server starts again from zero. So: gate on, ceilings approximate,
	 *   no refusal. With every server down there is nothing to refuse
	 *   pre-emptively either: this drop-in has no in-memory fallback, so the
	 *   gate's increment returns false and the relay answers 503 regardless.
	 *   A failure reported after this decision was read does not refuse the
	 *   request either; see relay_admission_drift().
	 * - cache_addition_suspended: wp_suspend_cache_addition() makes
	 *   wp_cache_add() a no-op, so the gate's keys would be created by the
	 *   increment — without a TTL on Redis, never on core or memcached (every
	 *   relay a 503). Transients are written with set, not add, and still work.
	 *   A per-request state: a report shows the state of the reporting request.
	 * - object_cache_incr_not_atomic: with WP_REDIS_IGBINARY, Redis Object
	 *   Cache's increment() and decrement() are GET, add, SET — a
	 *   read-modify-write, the F6 race itself. Judged on the constant alone,
	 *   without the igbinary extension the drop-in also requires: when the two
	 *   disagree this under-claims, the safe direction for a report.
	 * - object_cache_max_ttl_shorter_than_window: WP_REDIS_MAXTTL caps every
	 *   TTL, so a counter can expire and restart inside its own window. The gate
	 *   still runs — it is exact for as long as each key lives — but a ceiling
	 *   can be exceeded, so it is not reported atomic.
	 * - unverified: any other drop-in. The gate runs on its increment, which
	 *   may be atomic (most network caches), a get-then-set (several disk and
	 *   in-memory caches), or silently per-request (a drop-in fallen back to
	 *   memory without saying so). The transient record still bounds the
	 *   first two at the approximate level; nothing here can see the third.
	 *
	 * Recognition is by public API surface. Redis Object Cache
	 * (rhubarbgroup/redis-cache): redis_status(), redis_instance(),
	 * fast_build_key() and a public ignored_groups. Automattic memcached
	 * (Automattic/wp-memcached): get_mc() and public no_mc_groups and
	 * connection_errors. A different drop-in presenting the same surface with
	 * different semantics would be misread; none is known.
	 *
	 * @return array{mode: string, basis: string, gate: bool, refuse: bool}
	 */
	public static function relay_admission_decision() {
		if ( ! function_exists( 'wp_using_ext_object_cache' ) || ! wp_using_ext_object_cache() ) {
			return self::relay_admission_verdict( self::RELAY_ADMISSION_APPROXIMATE, 'no_persistent_object_cache', false );
		}

		if ( ! function_exists( 'wp_cache_add' ) || ! function_exists( 'wp_cache_incr' ) || ! function_exists( 'wp_cache_decr' ) ) {
			return self::relay_admission_verdict( self::RELAY_ADMISSION_APPROXIMATE, 'object_cache_api_incomplete', false );
		}

		$cache     = isset( $GLOBALS['wp_object_cache'] ) && is_object( $GLOBALS['wp_object_cache'] ) ? $GLOBALS['wp_object_cache'] : null;
		$redis     = self::is_redis_object_cache( $cache );
		$memcached = ! $redis && self::is_memcached_object_cache( $cache );

		if ( $redis && ! $cache->redis_status() ) {
			return self::relay_admission_verdict( self::RELAY_ADMISSION_FAIL_CLOSED, 'object_cache_backend_unreachable', false, true );
		}

		if ( function_exists( 'wp_suspend_cache_addition' ) && wp_suspend_cache_addition() ) {
			return self::relay_admission_verdict( self::RELAY_ADMISSION_APPROXIMATE, 'cache_addition_suspended', false );
		}

		if (
			( $redis && in_array( self::RELAY_ADMISSION_CACHE_GROUP, (array) $cache->ignored_groups, true ) )
			|| ( $memcached && in_array( self::RELAY_ADMISSION_CACHE_GROUP, (array) $cache->no_mc_groups, true ) )
		) {
			return self::relay_admission_verdict( self::RELAY_ADMISSION_APPROXIMATE, 'admission_group_not_persistent', false );
		}

		if ( $redis && defined( 'WP_REDIS_IGBINARY' ) && WP_REDIS_IGBINARY ) {
			return self::relay_admission_verdict( self::RELAY_ADMISSION_APPROXIMATE, 'object_cache_incr_not_atomic', false );
		}

		if ( $redis && defined( 'WP_REDIS_MAXTTL' ) && (int) WP_REDIS_MAXTTL > 0 && (int) WP_REDIS_MAXTTL < self::RELAY_GLOBAL_WINDOW ) {
			return self::relay_admission_verdict( self::RELAY_ADMISSION_APPROXIMATE, 'object_cache_max_ttl_shorter_than_window', true );
		}

		if ( $memcached && ! empty( $cache->connection_errors ) ) {
			return self::relay_admission_verdict( self::RELAY_ADMISSION_APPROXIMATE, 'object_cache_pool_degraded', true );
		}

		if ( $redis ) {
			return self::relay_admission_verdict( self::RELAY_ADMISSION_ATOMIC, 'redis_object_cache', true );
		}

		if ( $memcached ) {
			return self::relay_admission_verdict( self::RELAY_ADMISSION_ATOMIC, 'memcached_object_cache', true );
		}

		return self::relay_admission_verdict( self::RELAY_ADMISSION_UNVERIFIED, 'unrecognised_object_cache', true );
	}

	/**
	 * Refuse a request whose counts may not have outlived it (#752 F6).
	 *
	 * relay_admission_decision() is read once, at the start of the request, but
	 * what it reads can change inside the request. Two changes mean the counts
	 * this request was admitted on may have gone to memory, and are refused:
	 *  - the decision now refuses. Redis Object Cache caught an exception (Redis
	 *    refusing writes, a read timeout) and answers every later operation —
	 *    increments and transient writes alike — from a per-request array. A
	 *    controller without the gate answers 503 at the first transient write
	 *    in that state; the gate must never turn it into an admission.
	 *  - the gate's on/off flag changed: the admission group was made
	 *    non-persistent, or cache addition suspended, while the request ran, so
	 *    the increments after that point counted nothing durable.
	 *
	 * Nothing else is refused, even when the mode or basis moved. The case that
	 * matters is Automattic memcached reporting a pool member's failure during
	 * the request (pecl memcache fires its failure callback on the first real
	 * connect attempt), which moves the basis to object_cache_pool_degraded.
	 * That drop-in has no in-memory fallback, so the counts just taken are real;
	 * refusing would spend budget — this request's slots and its transient
	 * writes — while protecting nothing. Mode and basis describe the report; the
	 * refusal tracks durability.
	 *
	 * A refused request keeps the slots it took: those taken before the loss are
	 * real and stay counted (the safe direction), those after it are in memory.
	 * Re-reading costs no I/O; the decision reads drop-in state and constants.
	 *
	 * @param array{mode: string, basis: string, gate: bool, refuse: bool} $admitted_under
	 *        The decision read at the start of the request.
	 * @return WP_Error|null The refusal, or null when the decision still holds.
	 */
	private static function relay_admission_drift( array $admitted_under ) {
		$now = self::relay_admission_decision();

		if ( ! $now['refuse'] && $now['gate'] === $admitted_under['gate'] ) {
			return null;
		}

		return new WP_Error(
			'relay_unavailable',
			__( 'Feedback relay is temporarily unavailable. Please try again later.', 'mcpwp' ),
			array( 'status' => 503 )
		);
	}

	/**
	 * Shape one admission decision.
	 *
	 * @param string $mode   One of the RELAY_ADMISSION_* modes.
	 * @param string $basis  Machine-readable reason for the mode.
	 * @param bool   $gate   Whether the increment gate runs.
	 * @param bool   $refuse Whether every relay request is refused.
	 * @return array{mode: string, basis: string, gate: bool, refuse: bool}
	 */
	private static function relay_admission_verdict( $mode, $basis, $gate, $refuse = false ) {
		return array(
			'mode'   => $mode,
			'basis'  => $basis,
			'gate'   => (bool) $gate,
			'refuse' => (bool) $refuse,
		);
	}

	/**
	 * Whether the loaded object cache presents Redis Object Cache's public API.
	 *
	 * @param mixed $cache The global object cache.
	 * @return bool
	 */
	private static function is_redis_object_cache( $cache ) {
		return is_object( $cache )
			&& method_exists( $cache, 'redis_status' )
			&& method_exists( $cache, 'redis_instance' )
			&& method_exists( $cache, 'fast_build_key' )
			&& array_key_exists( 'ignored_groups', get_object_vars( $cache ) );
	}

	/**
	 * Whether the loaded object cache presents Automattic memcached's public API.
	 *
	 * @param mixed $cache The global object cache.
	 * @return bool
	 */
	private static function is_memcached_object_cache( $cache ) {
		if ( ! is_object( $cache ) || ! method_exists( $cache, 'get_mc' ) ) {
			return false;
		}
		$public = get_object_vars( $cache );
		return array_key_exists( 'no_mc_groups', $public ) && array_key_exists( 'connection_errors', $public );
	}

	/**
	 * The relay's ceilings, for reporting beside relay_admission_decision().
	 *
	 * @return array<string, array{limit: int, window_seconds: int}>
	 */
	public static function relay_limits() {
		return array(
			'per_ip'            => array(
				'limit'          => self::RELAY_RATE_MAX,
				'window_seconds' => self::RELAY_RATE_WINDOW,
			),
			'global_per_minute' => array(
				'limit'          => self::RELAY_GLOBAL_MAX_PER_MINUTE,
				'window_seconds' => self::RELAY_GLOBAL_MINUTE_WINDOW,
			),
			'global_per_hour'   => array(
				'limit'          => self::RELAY_GLOBAL_MAX,
				'window_seconds' => self::RELAY_GLOBAL_WINDOW,
			),
		);
	}

	/**
	 * Increment gate for the relay ceilings (#752 F6).
	 *
	 * Counters are taken in charge order — per-IP, site-wide per minute,
	 * site-wide per hour. Each window key is initialised at zero with
	 * wp_cache_add() and then incremented with wp_cache_incr(), and the value
	 * the increment returns is the decision. On a backend whose increment is
	 * atomic that value already includes every concurrent request's increment,
	 * so two requests can never both be the one that fits under a ceiling. On
	 * one whose increment is not, this is no better than the transient record —
	 * which is why relay_admission_decision() does not call that atomic.
	 * wp_cache_add()'s own result is not consulted: false means both "already
	 * exists" (the normal case) and "backend failed", and the increment that
	 * follows is what tells those apart.
	 *
	 * Deciding on an increment means incrementing before the answer is known,
	 * so this is a pre-increment with rollback, not the check-then-write the
	 * transient record uses. A request refused at any ceiling hands back every
	 * slot it took, including the one that refused it: refused at the per-IP
	 * ceiling, it never reaches the site-wide counters; refused at a site-wide
	 * ceiling, its per-IP (and per-minute) slots are released. Two
	 * consequences, both in the safe direction and neither able to admit past
	 * a ceiling:
	 *  - while a refused request holds a slot — between its increment and its
	 *    rollback — a concurrent request can see that count and be refused one
	 *    slot early;
	 *  - a rollback that fails (backend lost mid-request) leaves its slot taken
	 *    until the window tumbles: over-counted, never under.
	 *
	 * An increment that does not return a positive integer refuses with 503.
	 * Core's cache and memcached return false for an incr on a missing key —
	 * evicted between the add and the incr — and every backend returns false on
	 * failure. Neither is "count 0". Slots already taken are released first.
	 * Redis instead creates a missing key at 1 with no expiry, and a missing key
	 * decremented at -1; reapply_relay_counter_ttl() puts the window TTL back.
	 *
	 * @param array<int, array{0: string, 1: int, 2: int}> $ceilings Each entry is
	 *        [ key, ceiling, window seconds ], in charge order.
	 * @return WP_Error|null The refusal, or null when every ceiling admits.
	 */
	private function admit_relay_atomically( array $ceilings ) {
		$taken = array();

		foreach ( $ceilings as $ceiling ) {
			list( $key, $max, $window ) = $ceiling;

			// The TTL is the whole window and never 0, which every object-cache
			// backend reads as "never expires". The key carries its window
			// index, so a key created late in its window is simply not used
			// after the boundary and has expired one window later.
			wp_cache_add( $key, 0, self::RELAY_ADMISSION_CACHE_GROUP, max( 1, (int) $window ) );
			$count = self::relay_admission_count( wp_cache_incr( $key, 1, self::RELAY_ADMISSION_CACHE_GROUP ) );

			if ( $count < 1 ) {
				$this->release_relay_admission_slots( $taken );
				return new WP_Error(
					'relay_unavailable',
					__( 'Feedback relay is temporarily unavailable. Please try again later.', 'mcpwp' ),
					array( 'status' => 503 )
				);
			}

			if ( 1 === $count ) {
				self::reapply_relay_counter_ttl( $key, $window );
			}

			$taken[] = array( $key, $window );

			if ( $count > (int) $max ) {
				$this->release_relay_admission_slots( $taken );
				return new WP_Error(
					'rate_limited',
					__( 'Too many feedback submissions. Please try again later.', 'mcpwp' ),
					array( 'status' => 429 )
				);
			}
		}

		return null;
	}

	/**
	 * Hand back admission slots, most recent first.
	 *
	 * Best effort by design: a decrement that fails leaves the slot taken,
	 * which over-counts until the window tumbles and cannot admit anything.
	 *
	 * @param array<int, array{0: string, 1: int}> $taken [ key, window seconds ] of
	 *        each counter whose increment applied.
	 */
	private function release_relay_admission_slots( array $taken ) {
		foreach ( array_reverse( $taken ) as $slot ) {
			list( $key, $window ) = $slot;

			$left = wp_cache_decr( $key, 1, self::RELAY_ADMISSION_CACHE_GROUP );
			if ( is_int( $left ) && $left < 0 ) {
				self::reapply_relay_counter_ttl( $key, $window );
			}
		}
	}

	/**
	 * Put the window TTL back on a counter key an increment or a decrement may
	 * have created (#752 F6).
	 *
	 * Redis INCRBY and DECRBY on a missing key — evicted between wp_cache_add()
	 * and the increment, or between an increment and its rollback — create the
	 * key with no expiry. No WordPress cache function can set an expiry without
	 * also overwriting the value, and overwriting is not safe: a concurrent
	 * increment landing between ours and a wp_cache_set() would be lost, and at
	 * the start of a busy window that loses up to a ceiling's worth of slots.
	 * So the TTL is re-applied with the backend's own EXPIRE, which never
	 * touches the value, and only on the recognised Redis Object Cache drop-in,
	 * whose client and key derivation are public methods. Other backends do not
	 * create keys on incr (core, memcached) or are not recognised.
	 *
	 * Called when an increment returns 1 or a decrement returns below 0 — the
	 * only values a key created by INCRBY or DECRBY can hold on arrival. The
	 * off-by-one this accepts is in retention, not in budget: EXPIRE restarts
	 * the TTL from now, so a key that already had one (the ordinary first slot
	 * of a window, or one taken back to 0 and again to 1) expires up to one
	 * window after the moment of the call rather than after its creation — at
	 * most one window past its own window's end. The key name carries the window
	 * index, so a longer-lived key is never counted against a later window.
	 * Best effort: a failure leaves the key without expiry, which costs memory
	 * and never admission.
	 *
	 * @param string $key    Counter key.
	 * @param int    $window Window length in seconds.
	 */
	private static function reapply_relay_counter_ttl( $key, $window ) {
		$cache = isset( $GLOBALS['wp_object_cache'] ) ? $GLOBALS['wp_object_cache'] : null;
		if ( ! self::is_redis_object_cache( $cache ) ) {
			return;
		}

		$ttl = max( 1, (int) $window );
		if ( defined( 'WP_REDIS_MAXTTL' ) && (int) WP_REDIS_MAXTTL > 0 ) {
			$ttl = min( $ttl, (int) WP_REDIS_MAXTTL );
		}

		try {
			$client = $cache->redis_instance();
			if ( is_object( $client ) && is_callable( array( $client, 'expire' ) ) ) {
				$client->expire( $cache->fast_build_key( $key, self::RELAY_ADMISSION_CACHE_GROUP ), $ttl );
			}
		} catch ( Throwable $e ) {
			// Best effort — see above. A key left without expiry costs memory only.
			unset( $e );
		}
	}

	/**
	 * Read a wp_cache_incr() result as a count.
	 *
	 * Only a positive integer, or a string of digits (some drop-ins hand back
	 * the backend's reply verbatim), is a count. Everything else — false,
	 * null, a float, a negative, a non-numeric string — returns 0, which the
	 * gate refuses.
	 *
	 * @param mixed $value Value returned by wp_cache_incr().
	 * @return int
	 */
	private static function relay_admission_count( $value ) {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : 0;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^[0-9]{1,18}$/', $value ) ) {
			return (int) $value;
		}
		return 0;
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
