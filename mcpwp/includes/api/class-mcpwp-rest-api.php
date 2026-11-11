<?php
/**
 * REST API Base Controller
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base REST API controller.
 */
abstract class Mcpwp_REST_API {

	use Mcpwp_Api_Auth;
	use Mcpwp_Sanitization;
	use Mcpwp_Logging;

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'mcpwp/v1';

	/**
	 * Per-site ceiling on distinct paid DataForSEO keyword lookups any route on
	 * this base class can trigger in one window.
	 *
	 * Originally lived only on Mcpwp_REST_SEO_Market (get_opportunities() is a
	 * READABLE (GET) route driven entirely by a caller-supplied seed, and every
	 * distinct seed that misses Mcpwp_SEO_Cache is a real DataForSEO charge —
	 * $0.012/request + $0.00012/result; ~2,000 distinct seeds cost roughly $36).
	 * Hoisted here once keyword_research() (Mcpwp_REST_SEO_Audit) became a
	 * second caller spending the same credit via the same shared cache — one
	 * per-site ceiling, not two independently-tracked ones. This budget is
	 * independent of the generic rate limiter, which throttles request counts,
	 * not spend, and is keyed to a read-scoped key even though this is the
	 * expensive call.
	 */
	const LOOKUP_BUDGET_MAX = 200;

	/**
	 * Rolling window for the lookup budget above.
	 */
	const LOOKUP_BUDGET_WINDOW = DAY_IN_SECONDS;

	/**
	 * Transient key for the lookup-budget counter.
	 */
	const LOOKUP_BUDGET_TRANSIENT = 'mcpwp_seo_lookup_budget';

	/**
	 * Reason slug returned when the lookup budget above is exhausted.
	 */
	const REASON_LOOKUP_BUDGET_EXHAUSTED = 'lookup_budget_exhausted';

	/**
	 * Whether guard_elementor_template_shortcode() has been hooked onto
	 * `pre_do_shortcode_tag` for this PHP request (#1032 B3). Declared here,
	 * on the base class, rather than on the Mcpwp_Api_Auth trait — a trait's
	 * own static property is a SEPARATE copy per composing class, which
	 * would let two different controller subclasses each install their own
	 * listener within a single request (harmless, since post_ref_readable()
	 * is idempotent, but not the single-install-per-request this flag is
	 * for). A plain class static IS shared across every subclass, so
	 * whichever controller authenticates first wins, and every request gets
	 * exactly one listener.
	 *
	 * @var bool
	 */
	private static $template_shortcode_guard_installed = false;

	/**
	 * Register routes.
	 */
	abstract public function register_routes();

	/**
	 * Check if request has valid API key.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error True if valid.
	 */
	public function check_permission( $request ) {
		// Site graph (#943 S1 review round 3, N1): this used to also flag
		// Mcpwp_Graph_Builder's own "authenticated request" static here, as
		// the standalone equivalent of #957's guardrails context before that
		// branch had merged. #957 is on main now — verify_api_key() (below)
		// already calls Mcpwp_Content_Guardrails::enter_context() at this
		// same chokepoint, and Mcpwp_Graph_Builder::mark() reads
		// Mcpwp_Content_Guardrails::in_context() directly, so a second,
		// graph-owned copy of the same flag would just be two sources of
		// truth for one fact.
		$result = $this->verify_api_key( $request );

		if ( true === $result ) {
			$this->install_template_shortcode_guard();
		}

		return $result;
	}

	/**
	 * Hook Elementor's own `[elementor-template id="N"]` shortcode (#1032
	 * B3a): `elementor/frontend/builder_content_data`
	 * (render_elementor_in_process()/render_with_template_gate()) only
	 * catches a template resolved through Elementor's own DOCUMENT-level
	 * render (a Template/Global widget, a Loop Grid/Carousel's display
	 * template). The shortcode is a SEPARATE, site-wide-registered surface —
	 * `modules/library/classes/shortcode.php`'s callback also ends up calling
	 * `get_builder_content_for_display()`, but it is reachable from ANY
	 * `do_shortcode()`/`the_content` pass over ANY text, Elementor-built or
	 * not: a plain classic/Gutenberg post's post_content, a Text Editor/HTML
	 * widget's own content rendered as part of an OUTER Elementor document,
	 * or Elementor Pro's "Shortcode" dynamic tag, which calls
	 * `do_shortcode()` on an arbitrary caller-controlled string. Hooking
	 * `pre_do_shortcode_tag` once per request, rather than wrapping every
	 * individual the_content/do_shortcode call site, catches all of these in
	 * one place — including any such call site this file does not itself
	 * know about.
	 *
	 * Installed only once check_permission() has authenticated the caller
	 * (i.e. only for a request this plugin is actively serving), so a normal
	 * front-end page load — where the SAME shortcode is the site owner's own
	 * publishing intent and must render exactly as published — is never
	 * touched by it.
	 *
	 * @return void
	 */
	protected function install_template_shortcode_guard() {
		if ( self::$template_shortcode_guard_installed ) {
			return;
		}
		self::$template_shortcode_guard_installed = true;

		add_filter( 'pre_do_shortcode_tag', array( $this, 'guard_elementor_template_shortcode' ), 10, 4 );
	}

	/**
	 * `pre_do_shortcode_tag` callback — see install_template_shortcode_guard().
	 *
	 * @param false|string $return Short-circuit value; false runs the shortcode normally.
	 * @param string       $tag    Shortcode tag being processed.
	 * @param array|string $attr   Shortcode attributes.
	 * @param array        $m      Regex match array from get_shortcode_regex().
	 * @return false|string $return unchanged when this is not the Template
	 *                       Library shortcode or its id is readable; an empty
	 *                       string (short-circuiting the shortcode to no
	 *                       output) when it names a template this caller may
	 *                       not read.
	 */
	public function guard_elementor_template_shortcode( $return, $tag, $attr, $m ) {
		if ( ! class_exists( 'Mcpwp_Elementor_Template_Refs' )
			|| Mcpwp_Elementor_Template_Refs::SHORTCODE_TAG !== $tag
			|| ! is_array( $attr ) || empty( $attr['id'] )
		) {
			return $return;
		}

		$id = Mcpwp_Elementor_Template_Refs::coerce_id( $attr['id'] );
		if ( $id <= 0 ) {
			return $return;
		}

		return $this->post_ref_readable( $id, 'elementor-theme' ) ? $return : '';
	}

	/**
	 * Permission callback for Pro-tier routes: authentication first, then the tier.
	 *
	 * Runs on every dispatch path that reaches a route through the REST server —
	 * direct HTTP, POST /batch and MCP tools/call (both use rest_do_request()) —
	 * so a Pro route cannot be reached on Free by picking a different path.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error True when authenticated and entitled.
	 */
	public function check_pro_permission( $request ) {
		$auth = $this->check_permission( $request );
		if ( true !== $auth ) {
			return $auth;
		}

		return $this->require_pro();
	}

	/**
	 * Require a Pro entitlement.
	 *
	 * @return true|WP_Error 403 pro_required when not entitled.
	 */
	protected function require_pro() {
		if ( self::is_pro_entitled() ) {
			return true;
		}

		return $this->error_response(
			'pro_required',
			__( 'This feature requires MCPWP Pro. Upgrade at https://mcpwp.net/pricing/', 'mcpwp' ),
			403,
			array( 'upgrade_url' => 'https://mcpwp.net/pricing/' )
		);
	}

	/**
	 * Whether this install holds a Pro entitlement.
	 *
	 * The one shared tier check for REST gates and gated MCP integrations. It
	 * fails closed: a WP.org build, a license class that is not loaded, or an
	 * answer that is not literally true is "not entitled" — an entitlement that
	 * cannot be read is not an entitlement.
	 *
	 * @return bool
	 */
	public static function is_pro_entitled() {
		return self::entitlement_from_license_class( 'Mcpwp_License' );
	}

	/**
	 * Read the entitlement from a license class.
	 *
	 * @param string $license_class License class name.
	 * @return bool
	 */
	private static function entitlement_from_license_class( $license_class ) {
		if ( defined( 'MCPWP_WPORG_BUILD' ) ) {
			return false;
		}

		if ( ! class_exists( $license_class, false ) || ! method_exists( $license_class, 'get_instance' ) ) {
			return false;
		}

		$license = call_user_func( array( $license_class, 'get_instance' ) );

		return is_object( $license ) && method_exists( $license, 'is_pro' ) && true === $license->is_pro();
	}

	/**
	 * Current requester's API key id, or '' when none (admin-AJAX / cookie context).
	 *
	 * Used to stamp approval requests with their creator so the same key cannot
	 * later approve or apply its own request. Populated on direct,
	 * batch, and MCP sub-request paths.
	 *
	 * @return string Key id used to stamp approval requests for self-approval checks.
	 */
	protected function current_requester_key_id() {
		$key_record = $this->get_current_api_key_record();
		return ( is_array( $key_record ) && ! empty( $key_record['id'] ) ) ? (string) $key_record['id'] : '';
	}

	/**
	 * Whether the current OAuth principal lacks a stable identity.
	 *
	 * A legacy OAuth access token issued before per-token identity was persisted
	 * (or a 2.8.56 `spai_at_` bridge token) carries no user_id/client_id, so it
	 * is identified only by a token-hash fallback (`oauth:<hash>`). Refreshing
	 * such a grant mints a differently-keyed `oauth-user:<id>` token, so an
	 * unstable-identity principal must not create or approve approval requests —
	 * otherwise the self-approval gate can be sidestepped by creating with the
	 * legacy token and approving with the refreshed one. All other operations
	 * (read/write) remain available; reconnecting re-mints an identified token.
	 *
	 * @return bool
	 */
	protected function requester_has_unstable_oauth_identity() {
		$key_record = $this->get_current_api_key_record();
		if ( ! is_array( $key_record ) || empty( $key_record['id'] ) ) {
			return false;
		}
		// Strong identities are `oauth-user:` / `oauth-client:` / scoped-key ids;
		// only the weak fallback is the bare `oauth:` prefix.
		return 0 === strpos( (string) $key_record['id'], 'oauth:' );
	}

	/**
	 * The wp-admin user behind the trusted admin-AJAX context (0 otherwise),
	 * for Mcpwp_Approvals::requester_is_exempt_from_caps() — which decides,
	 * alone, whether an approval requester is exempt from the caps (#1042).
	 *
	 * @return int
	 */
	protected function approval_admin_user_id() {
		return $this->is_trusted_admin_ajax_request() && function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	}

	/**
	 * Whether the current requester is not category-restricted: the trusted
	 * wp-admin (admin-AJAX) context, or an API key / OAuth principal whose
	 * role grants every category. (Exemption from the approval caps is a
	 * different question — see Mcpwp_Approvals::requester_is_exempt_from_caps().)
	 *
	 * @return bool
	 */
	protected function requester_is_unrestricted() {
		if ( $this->is_trusted_admin_ajax_request() ) {
			return true;
		}
		$key_record = $this->get_current_api_key_record();
		if ( ! is_array( $key_record ) || $this->requester_is_webmcp() ) {
			return false;
		}
		return array() === $this->resolve_key_tool_categories( $key_record );
	}

	/**
	 * Whether the current principal authenticated via an OAuth access token.
	 *
	 * OAuth records are tagged `type => 'oauth'` at authentication; scoped API
	 * keys never carry that tag. Used to keep OAuth connectors subject to the
	 * approval floor even when they hold admin scope — only a provisioned
	 * admin-scoped API key (or admin-AJAX) may bypass approval.
	 *
	 * @return bool
	 */
	protected function requester_is_oauth() {
		$key_record = $this->get_current_api_key_record();
		return is_array( $key_record ) && isset( $key_record['type'] ) && 'oauth' === $key_record['type'];
	}

	/**
	 * Abort the approval surface for an unstable-identity OAuth principal.
	 *
	 * @return WP_Error|null WP_Error when the caller must be refused, else null.
	 */
	protected function guard_stable_identity_for_approvals() {
		if ( $this->requester_has_unstable_oauth_identity() ) {
			return new WP_Error(
				'oauth_identity_unstable',
				__( 'This OAuth session predates the current security update and is not stably identified. Reconnect the connector, then retry — approval requests require a re-authenticated session.', 'mcpwp' ),
				array( 'status' => 403 )
			);
		}
		return null;
	}

	/**
	 * Build a configured DataForSEO provider, or null when credentials are absent.
	 *
	 * Shared by every controller that spends DataForSEO credit (SEO market data,
	 * keyword research enrichment) so credential-reading logic exists in exactly
	 * one place. Protected (not private) so tests can inject a stub provider
	 * without calling the live API — see tests/unit/test-seo-market-debounce.php
	 * and tests/unit/test-rest-seo-audit-keyword-enrichment.php.
	 *
	 * @return Mcpwp_Provider_Dataforseo|null
	 */
	protected function get_dataforseo_provider() {
		if ( ! class_exists( 'Mcpwp_Integration_Manager' ) || ! class_exists( 'Mcpwp_Provider_Dataforseo' ) ) {
			return null;
		}

		$config = Mcpwp_Integration_Manager::get_instance()->get_provider_config( 'dataforseo' );

		if ( empty( $config['login'] ) || empty( $config['password'] ) ) {
			return null;
		}

		return new Mcpwp_Provider_Dataforseo( $config['login'], $config['password'] );
	}

	/**
	 * Consume one slot from the daily paid-lookup budget.
	 *
	 * Must only be called on a Mcpwp_SEO_Cache miss, immediately before the
	 * fetch that miss triggers — see get_opportunities() and keyword_research().
	 * A fixed-window counter, same shape as Mcpwp_Rate_Limiter's windows: the
	 * window resets itself the first time it is read after expiring, rather
	 * than sliding on every write.
	 *
	 * A slot consumed here for a fetch that turns out to fail (WP_Error) must
	 * be handed back via refund_lookup_budget() — see that method's docblock.
	 *
	 * @return true|WP_Error True if a slot was consumed; WP_Error (with
	 *                       'retry_after' seconds in its data) if exhausted.
	 */
	protected function consume_lookup_budget() {
		$now    = time();
		$window = get_transient( self::LOOKUP_BUDGET_TRANSIENT );

		if ( ! is_array( $window ) || ! isset( $window['count'], $window['reset'] ) || (int) $window['reset'] <= $now ) {
			$window = array(
				'count' => 0,
				'reset' => $now + self::LOOKUP_BUDGET_WINDOW,
			);
		}

		if ( (int) $window['count'] >= self::LOOKUP_BUDGET_MAX ) {
			return new WP_Error(
				'lookup_budget_exhausted',
				__( 'Daily budget for paid keyword lookups on this site has been reached.', 'mcpwp' ),
				array( 'retry_after' => max( 0, (int) $window['reset'] - $now ) )
			);
		}

		$window['count']++;
		set_transient( self::LOOKUP_BUDGET_TRANSIENT, $window, max( 1, (int) $window['reset'] - $now ) );

		return true;
	}

	/**
	 * Hand back a lookup-budget slot consumed by consume_lookup_budget() for a
	 * fetch that resolved to a WP_Error.
	 *
	 * Mcpwp_SEO_Cache::TTL_FAIL is only five minutes, so a permanently-rejected
	 * credential retried on a natural cadence would otherwise re-consume a slot
	 * every five minutes — up to 288 charges/day against a 200-slot ceiling —
	 * and lock out legitimate lookups for a failure that was never going to
	 * succeed. Only ever called for the same miss that just consumed a slot
	 * (callers track that with a local flag); a cache-hit replay of a cached
	 * error never reaches here and never charged in the first place.
	 *
	 * If the window has already rolled over since the matching consume, there
	 * is nothing to give back — a fresh window starts at zero regardless.
	 */
	protected function refund_lookup_budget() {
		$now    = time();
		$window = get_transient( self::LOOKUP_BUDGET_TRANSIENT );

		if ( ! is_array( $window ) || ! isset( $window['count'], $window['reset'] ) || (int) $window['reset'] <= $now ) {
			return;
		}

		$window['count'] = max( 0, (int) $window['count'] - 1 );
		set_transient( self::LOOKUP_BUDGET_TRANSIENT, $window, max( 1, (int) $window['reset'] - $now ) );
	}

	/**
	 * Turn a destructive call into a pending approval and answer 202 (#536).
	 *
	 * @param string          $operation Short name.
	 * @param WP_REST_Request $request   Request.
	 * @param array           $resource  {type, id, title}.
	 * @param string          $summary   One line for the reviewer.
	 * @return WP_REST_Response|WP_Error
	 */
	protected function defer_destructive( $operation, $request, array $resource, $summary ) {
		$body = Mcpwp_Destructive_Gate::defer( $operation, $request, $resource, $summary, $this->get_current_api_key_record() );

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$this->log_activity( 'destructive_deferred', $request );

		return $this->success_response( $body, 202 );
	}

	/**
	 * Prepare success response.
	 *
	 * @param mixed $data Response data.
	 * @param int   $status HTTP status code.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	protected function success_response( $data, $status = 200 ) {
		// A handler may pass a backend failure here; never serialize it as success.
		if ( is_wp_error( $data ) ) {
			return $this->error_response_from_wp_error( $data );
		}

		// Merge any content-guardrails notice recorded for a post ID
		// somewhere in this response, generically — every create/update
		// route reports guardrails results without needing its own call to
		// ask for them (#957 adversarial review design fix: "so every tool
		// reports it, no per-site plumbing").
		if ( is_array( $data ) && class_exists( 'Mcpwp_Content_Guardrails' ) ) {
			Mcpwp_Content_Guardrails::attach_notices_to_response( $data );
		}

		$response = new WP_REST_Response( $data, $status );

		// Add rate limit headers.
		$this->add_rate_limit_headers( $response );

		return $response;
	}

	/**
	 * Add rate limit headers to response.
	 *
	 * @param WP_REST_Response $response Response object.
	 */
	protected function add_rate_limit_headers( $response ) {
		if ( ! class_exists( 'Mcpwp_Rate_Limiter' ) ) {
			return;
		}

		$limiter = Mcpwp_Rate_Limiter::get_instance();
		$headers = $limiter->get_headers();

		foreach ( $headers as $key => $value ) {
			$response->header( $key, $value );
		}
	}

	/**
	 * Prepare error response.
	 *
	 * Automatically enhances the error with actionable hints when available.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @param int    $status  HTTP status code.
	 * @param array  $context Optional context for dynamic hint generation.
	 * @return WP_Error Error object with hints.
	 */
	protected function error_response( $code, $message, $status = 400, $context = array() ) {
		// The canonical post-ref not-found error (Mcpwp_Api_Auth::
		// post_ref_not_found_error()) leaves every handler exactly as the
		// permission layer sends it: Mcpwp_Error_Hints must not swap in its
		// id-echoing 'not_found' hint, or the same refusal reads differently
		// depending on which layer produced it (#1030 round-4, BLOCK-1).
		if ( self::is_post_ref_not_found( $code, $message ) ) {
			return $this->post_ref_not_found_error();
		}

		$error = new WP_Error( $code, $message, array( 'status' => $status ) );

		if ( class_exists( 'Mcpwp_Error_Hints' ) ) {
			$error = Mcpwp_Error_Hints::enhance_error( $error, $context );
		}

		return $error;
	}

	/**
	 * Resolve the HTTP status a WP_Error carries in its own data, if any.
	 *
	 * 64 call sites used to re-wrap a lower-level WP_Error (e.g. from a
	 * WordPress core insert/update helper or a storage class) and hardcode
	 * status 400 on the re-wrap, which reported real server failures
	 * (500), not-found (404), permission errors (403), etc. as a generic
	 * client error. This reads the status the original error already
	 * carries and only falls back to $default_status when it is missing
	 * or not a plausible HTTP status. See #953.
	 *
	 * @param WP_Error $error          The error to inspect.
	 * @param int      $default_status Status to use when the error carries none.
	 * @return int Resolved HTTP status.
	 */
	protected function get_wp_error_status( WP_Error $error, $default_status = 400 ) {
		$data = $error->get_error_data( $error->get_error_code() );

		if ( is_array( $data ) && isset( $data['status'] ) ) {
			$status = $data['status'];

			if ( is_int( $status ) && $status >= 400 && $status <= 599 ) {
				return $status;
			}
		}

		return $default_status;
	}

	/**
	 * Prepare an error response from an existing WP_Error, honouring the
	 * HTTP status the error already carries instead of forcing one.
	 *
	 * Replaces the historic pattern of re-wrapping a WP_Error's own code
	 * and message while hardcoding the status to 400. Routes through
	 * error_response() so hint enrichment still applies. See #953.
	 *
	 * @param WP_Error $error          The error to re-wrap.
	 * @param int      $default_status Status to use when the error carries none
	 *                                 (matches the old hardcoded behaviour).
	 * @param array    $context        Optional context for dynamic hint generation.
	 * @return WP_Error Error object with hints, carrying the resolved status.
	 */
	protected function error_response_from_wp_error( WP_Error $error, $default_status = 400, $context = array() ) {
		return $this->error_response(
			$error->get_error_code(),
			$error->get_error_message(),
			$this->get_wp_error_status( $error, $default_status ),
			$context
		);
	}

	/**
	 * Get pagination args schema.
	 *
	 * @return array Schema.
	 */
	protected function get_pagination_args() {
		return array(
			'per_page' => array(
				'description' => __( 'Maximum number of items per page.', 'mcpwp' ),
				'type'        => 'integer',
				'default'     => 10,
				'minimum'     => 1,
				'maximum'     => 100,
			),
			'page'     => array(
				'description' => __( 'Current page number.', 'mcpwp' ),
				'type'        => 'integer',
				'default'     => 1,
				'minimum'     => 1,
			),
		);
	}

	/**
	 * Get common post args schema.
	 *
	 * @return array Schema.
	 */
	protected function get_post_args() {
		return array(
			'title'   => array(
				'description' => __( 'Post title.', 'mcpwp' ),
				'type'        => 'string',
				'required'    => true,
			),
			'content' => array(
				'description' => __( 'Post content.', 'mcpwp' ),
				'type'        => 'string',
				'default'     => '',
			),
			'status'  => array(
				'description' => __( 'Post status.', 'mcpwp' ),
				'type'        => 'string',
				'enum'        => array( 'publish', 'draft', 'pending', 'private' ),
				'default'     => 'draft',
			),
			'excerpt' => array(
				'description' => __( 'Post excerpt.', 'mcpwp' ),
				'type'        => 'string',
				'default'     => '',
			),
			'terms'   => array(
				'description' => __( 'Taxonomy terms as {taxonomy: [ids, slugs or names]}. Not alongside categories/tags for the same taxonomy.', 'mcpwp' ),
				'type'        => 'object',
			),
			'meta'    => array(
				'description' => __( 'Post meta as {key: value}; null deletes. Credential-shaped, reserved and Elementor keys are refused.', 'mcpwp' ),
				'type'        => 'object',
			),
		);
	}

	/**
	 * The same schema for an update: nothing is required.
	 *
	 * @return array
	 */
	protected function get_post_update_args() {
		$args = $this->get_post_args();
		foreach ( $args as $name => $schema ) {
			unset( $args[ $name ]['required'], $args[ $name ]['default'] );
		}
		return $args;
	}

	// -------------------------------------------------------------------------
	// Shared content-graph utilities (used by content graph, SEO audit, and
	// content quality controllers).
	// -------------------------------------------------------------------------

	// --- Private helpers ---

	protected function build_content_graph_data( $post_types, $limit, $include_drafts = false ) {
		// Security clamp: a read-only key must never receive draft/private nodes
		// even when it passes include_drafts=true. Only a caller with write scope
		// (or a trusted admin-AJAX context) may widen the status set beyond public.
		// This is the SINGLE canonical builder — every graph consumer (content
		// graph, SEO audit, content quality) inherits it, so the clamp cannot be
		// bypassed by a subclass that forgets to re-apply it.
		$include_drafts = $include_drafts && $this->requester_can_see_non_public();
		$statuses = $include_drafts ? array( 'publish', 'draft', 'private' ) : array( 'publish' );

		// An empty $post_types means "no readable type" (parse_graph_post_types()
		// filters to a caller's held categories, #1024) and must return an
		// empty graph, never every post. get_posts()/WP_Query silently
		// DEFAULTS an empty post_type array to 'post' (its own "nothing was
		// asked for" fallback), which here would hand back exactly the data
		// this call was trying not to.
		if ( empty( $post_types ) ) {
			return array(
				'summary'  => array(
					'node_count'   => 0,
					'edge_count'   => 0,
					'orphan_count' => 0,
					'post_types'   => array(),
					'statuses'     => $statuses,
					'signals'      => array( 'hub_score', 'rank_score', 'freshness_score', 'orphan_severity', 'menu_depth', 'shared_taxonomy' ),
				),
				'nodes'    => array(),
				'edges'    => array(),
				'orphans'  => array(),
				'workflow' => array(
					'read'     => 'Use this graph before creating pages or adding internal links.',
					'suggest'  => 'Choose candidate links from nodes and edges; do not invent internal URLs.',
					'approval' => 'Return a link diff before applying links to page content.',
				),
			);
		}

		$posts = get_posts(
			array(
				'post_type'      => $post_types,
				'post_status'    => $statuses,
				'posts_per_page' => $limit,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);

		$nodes_by_id       = array();
		$url_to_id         = array();
		$menu_post_depths  = $this->get_menu_post_depths();
		$menu_post_ids     = array_keys( $menu_post_depths );
		$terms_to_post_ids = array();
		$now               = time();

		foreach ( $posts as $post ) {
			$permalink  = get_permalink( $post );
			$term_names = $this->get_graph_term_names( $post );
			$headings   = $this->extract_heading_texts( $post->post_content );
			$modified_ts = (int) get_post_modified_time( 'U', true, $post );
			$freshness_days = $modified_ts > 0 ? max( 0, floor( ( $now - $modified_ts ) / DAY_IN_SECONDS ) ) : null;

			$node = array(
				'id'             => (int) $post->ID,
				'type'           => $post->post_type,
				'status'         => $post->post_status,
				'title'          => get_the_title( $post ),
				'url'            => $permalink,
				'slug'           => $post->post_name,
				'excerpt'        => has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_trim_words( wp_strip_all_tags( $post->post_content ), 32 ),
				'modified'       => get_post_modified_time( DATE_ATOM, true, $post ),
				'parent_id'      => (int) $post->post_parent,
				'in_menu'        => in_array( (int) $post->ID, $menu_post_ids, true ),
				'menu_depth'     => isset( $menu_post_depths[ (int) $post->ID ] ) ? (int) $menu_post_depths[ (int) $post->ID ] : null,
				'terms'          => $term_names,
				'headings'       => $headings,
				'word_count'     => str_word_count( wp_strip_all_tags( $post->post_content ) ),
				'freshness_days' => $freshness_days,
				'freshness_score' => $this->calculate_freshness_score( $freshness_days ),
				'inbound_count'  => 0,
				'outbound_count' => 0,
				'hub_score'      => 0,
				'orphan_severity' => 'none',
				'rank_score'     => 0,
				'anchors_in'     => array(),
				'anchors_out'    => array(),
			);

			$nodes_by_id[ (int) $post->ID ] = $node;
			$url_to_id[ $this->normalize_internal_graph_url( $permalink ) ] = (int) $post->ID;

			foreach ( $term_names as $term_name ) {
				$term_key = sanitize_title( $term_name );
				if ( '' === $term_key ) {
					continue;
				}

				if ( empty( $terms_to_post_ids[ $term_key ] ) ) {
					$terms_to_post_ids[ $term_key ] = array(
						'name' => $term_name,
						'ids'  => array(),
					);
				}
				$terms_to_post_ids[ $term_key ]['ids'][] = (int) $post->ID;
			}
		}

		$edges = array();

		foreach ( $posts as $post ) {
			$from_id = (int) $post->ID;
			$links   = $this->extract_internal_links_from_content( $post->post_content, $url_to_id );

			// Also extract links embedded in Elementor widget settings (buttons, text editors, HTML widgets, etc.)
			// post_content is empty/minimal for Elementor pages, so <a> regex alone misses all rendered links.
			$elementor_links = $this->extract_elementor_links_from_postmeta( $from_id, $url_to_id );
			$links           = array_merge( $links, $elementor_links );

			$linked_to = array();

			foreach ( $links as $link ) {
				$to_id = $link['target_id'];
				if ( $from_id === $to_id || ! isset( $nodes_by_id[ $to_id ] ) ) {
					continue;
				}

				// Deduplicate edges per source→target pair so Elementor + post_content don't double-count.
				if ( isset( $linked_to[ $to_id ] ) ) {
					continue;
				}
				$linked_to[ $to_id ] = true;

				$edges[] = array(
					'from'   => $from_id,
					'to'     => $to_id,
					'type'   => 'content_link',
					'anchor' => $link['anchor'],
					'url'    => $link['url'],
				);

				$nodes_by_id[ $from_id ]['outbound_count']++;
				$nodes_by_id[ $to_id ]['inbound_count']++;
				$nodes_by_id[ $from_id ]['anchors_out'][] = $link['anchor'];
				$nodes_by_id[ $to_id ]['anchors_in'][]    = $link['anchor'];
			}

			if ( $post->post_parent && isset( $nodes_by_id[ (int) $post->post_parent ] ) ) {
				$edges[] = array(
					'from' => (int) $post->post_parent,
					'to'   => $from_id,
					'type' => 'parent_child',
				);
			}
		}

		foreach ( $terms_to_post_ids as $term ) {
			$term_ids = array_values( array_unique( $term['ids'] ) );
			sort( $term_ids );

			for ( $i = 0; $i < count( $term_ids ); $i++ ) {
				for ( $j = $i + 1; $j < count( $term_ids ); $j++ ) {
					$edges[] = array(
						'from'   => $term_ids[ $i ],
						'to'     => $term_ids[ $j ],
						'type'   => 'shared_taxonomy',
						'term'   => $term['name'],
						'weight' => 2,
					);
				}
			}
		}

		$front_page_id = (int) get_option( 'page_on_front' );
		$orphans       = array();
		$page_rank     = $this->calculate_graph_page_rank( array_keys( $nodes_by_id ), $edges );

		foreach ( $nodes_by_id as $id => $node ) {
			$nodes_by_id[ $id ]['anchors_in']  = array_values( array_unique( array_filter( $node['anchors_in'] ) ) );
			$nodes_by_id[ $id ]['anchors_out'] = array_values( array_unique( array_filter( $node['anchors_out'] ) ) );
			$nodes_by_id[ $id ]['hub_score']   = $this->calculate_hub_score( $nodes_by_id[ $id ] );
			$nodes_by_id[ $id ]['rank_score']  = isset( $page_rank[ $id ] ) ? $page_rank[ $id ] : 0;

			if ( 'publish' === $node['status'] && 0 === $node['inbound_count'] && ! $node['in_menu'] && $front_page_id !== $id ) {
				$severity = $this->calculate_orphan_severity( $nodes_by_id[ $id ] );
				$nodes_by_id[ $id ]['orphan_severity'] = $severity;
				$orphans[] = array(
					'id'    => $id,
					'title' => $node['title'],
					'type'  => $node['type'],
					'url'   => $node['url'],
					'severity' => $severity,
				);
			}
		}

		$nodes = array_values( $nodes_by_id );

		return array(
			'summary' => array(
				'node_count'   => count( $nodes ),
				'edge_count'   => count( $edges ),
				'orphan_count' => count( $orphans ),
				'post_types'   => $post_types,
				'statuses'     => $statuses,
				'signals'      => array( 'hub_score', 'rank_score', 'freshness_score', 'orphan_severity', 'menu_depth', 'shared_taxonomy' ),
			),
			'nodes'   => $nodes,
			'edges'   => $edges,
			'orphans' => $orphans,
			'workflow' => array(
				'read'     => 'Use this graph before creating pages or adding internal links.',
				'suggest'  => 'Choose candidate links from nodes and edges; do not invent internal URLs.',
				'approval' => 'Return a link diff before applying links to page content.',
			),
		);
	}

	/**
	 * Parse requested graph post types and keep only public/queryable types
	 * this caller's key may actually read.
	 *
	 * Every caller of this wrapper (content-graph, SEO audit/content-quality)
	 * runs in a real request context, so $this->caller_may_read_category()
	 * is always available here — unlike the static Mcpwp_Graph_Builder
	 * method this wraps, which has no auth context and is left untouched.
	 *
	 * Before this, a caller who could reach /content-graph or /seo/audit at
	 * all (route category 'content'/'seo') could request
	 * post_types=product,elementor_library and get WooCommerce product or
	 * Elementor template nodes — titles, excerpts, word counts — back in
	 * the graph even with 'woocommerce'/'elementor-templates' disabled site-
	 * wide, because the only filter here was "is this type public/
	 * queryable", never which MCPWP tool category owns it (#1024, follow-up
	 * to #1022's WARN-2, applying the same category_for_post_type()/
	 * caller_may_read_category() chokepoint). page/post always resolve to
	 * 'content' here, same as the door itself defaults to when nothing is
	 * requested, so this is a no-op for the common case.
	 *
	 * A gated type is silently dropped, not refused: unknown/non-public
	 * types are already dropped the same way one line up, and the default
	 * fallback to page/post below covers "nothing left".
	 *
	 * @param string $post_types Raw comma-separated post types.
	 * @return array Post types.
	 */
	protected function parse_graph_post_types( $post_types ) {
		$types = Mcpwp_Graph_Builder::parse_graph_post_types( $post_types );

		// No page/post fallback here on an empty result, unlike the wrapped
		// method: THAT fallback means "nothing recognisable was asked for,
		// default to something sane" (its own docblock) — never reachable
		// with an empty result from this filter, since it only ever
		// narrows an already-non-empty list. An empty result here is
		// always a permission denial (no requested type's category is
		// held), and defaulting to page/post would hand back 'content'
		// data to a caller this filter just proved does not have 'content'
		// category. build_content_graph_data() treats an empty list as "no
		// nodes", not as WP_Query's own empty-post_type default of 'post'.
		return array_values(
			array_filter(
				$types,
				function ( $type ) {
					return $this->caller_may_read_category( $this->category_for_post_type( $type ) );
				}
			)
		);
	}

	/**
	 * Get graph-safe term names for a post.
	 *
	 * @param WP_Post $post Post.
	 * @return array Term names.
	 */
	protected function get_graph_term_names( $post ) {
		return Mcpwp_Graph_Builder::get_graph_term_names( $post );
	}

	/**
	 * Get post IDs included in navigation menus.
	 *
	 * @return array Menu post IDs.
	 */
	protected function get_menu_post_ids() {
		return Mcpwp_Graph_Builder::get_menu_post_ids();
	}

	/**
	 * Get post IDs included in navigation menus with shallowest menu depth.
	 *
	 * @return array Post ID to depth map.
	 */
	protected function get_menu_post_depths() {
		return Mcpwp_Graph_Builder::get_menu_post_depths();
	}

	/**
	 * Get depth for a nav menu item.
	 *
	 * @param WP_Post $item  Menu item.
	 * @param array   $items Menu items.
	 * @return int Depth.
	 */
	protected function get_menu_item_depth( $item, $items ) {
		return Mcpwp_Graph_Builder::get_menu_item_depth( $item, $items );
	}

	/**
	 * Calculate freshness score from age in days.
	 *
	 * @param int|null $freshness_days Days since modified.
	 * @return float Score from 0-1.
	 */
	protected function calculate_freshness_score( $freshness_days ) {
		return Mcpwp_Graph_Builder::calculate_freshness_score( $freshness_days );
	}

	/**
	 * Calculate a simple hub score for graph nodes.
	 *
	 * @param array $node Graph node.
	 * @return float Score.
	 */
	protected function calculate_hub_score( $node ) {
		return Mcpwp_Graph_Builder::calculate_hub_score( $node );
	}

	/**
	 * Calculate orphan severity for nodes.
	 *
	 * @param array $node Graph node.
	 * @return string Severity.
	 */
	protected function calculate_orphan_severity( $node ) {
		return Mcpwp_Graph_Builder::calculate_orphan_severity( $node );
	}

	/**
	 * Calculate a compact PageRank-style score for content-link edges.
	 *
	 * @param array $node_ids Node IDs.
	 * @param array $edges    Graph edges.
	 * @return array Scores.
	 */
	protected function calculate_graph_page_rank( $node_ids, $edges ) {
		return Mcpwp_Graph_Builder::calculate_graph_page_rank( $node_ids, $edges );
	}

	/**
	 * Extract heading texts from block/raw content.
	 *
	 * @param string $content Post content.
	 * @return array Heading texts.
	 */
	protected function extract_heading_texts( $content ) {
		return Mcpwp_Graph_Builder::extract_heading_texts( $content );
	}

	/**
	 * Whether a post is built with Elementor.
	 *
	 * Uses Elementor's canonical edit-mode flag first (set even when the parent
	 * post's _elementor_data is briefly empty on a fresh draft), then falls back
	 * to the presence of stored Elementor data.
	 *
	 * Shared by the rendered-copy surface (class-mcpwp-rest-site-content.php)
	 * and the SEO readiness validator (class-mcpwp-rest-seo-audit.php) — both
	 * need to know whether a post's real content lives outside post_content.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	protected function is_elementor_built( $post_id ) {
		return Mcpwp_Graph_Builder::is_elementor_built( $post_id );
	}

	/**
	 * Render one post id's Elementor content in-process, redacting any
	 * NESTED document (a Template/Global widget, a Loop Grid/Carousel's
	 * display template, ...) this caller cannot read (#1032).
	 *
	 * Hooks Elementor's `elementor/frontend/builder_content_data` filter —
	 * fired from Frontend::get_builder_content() for the outer document AND,
	 * because Document::get_content()/print_content() route through that
	 * exact same method, for every nested document a widget or Loop skin
	 * resolves via Plugin::elementor()->documents->get($id) — so this one
	 * hook covers the Template widget, the Global widget, and Loop
	 * Grid/Carousel's per-item template alike, with no per-widget-type
	 * special-casing. A denied nested document renders as an EMPTY page
	 * (Frontend::get_builder_content()'s own "empty $data" branch) rather
	 * than failing the read — the post actually being rendered is not in
	 * question, only whether a template it embeds may be shown.
	 *
	 * The outer $post_id is always let through: its own visibility was
	 * already decided by the caller before this runs (guard_non_public_post()
	 * / guard_post_type_category()), and Elementor's own recursion guard in
	 * get_builder_content_for_display() only compares against the current
	 * loop post anyway.
	 *
	 * @param int  $post_id  Post id being rendered.
	 * @param bool $with_css Whether to inline CSS/font links.
	 * @return string Rendered HTML.
	 */
	protected function render_elementor_in_process( $post_id, $with_css = true ) {
		$post_id = (int) $post_id;
		$filter  = function ( $data, $doc_post_id ) use ( $post_id ) {
			if ( (int) $doc_post_id === $post_id ) {
				return $data;
			}

			// 'elementor-theme': see Mcpwp_Api_Auth::template_ref_gate()'s
			// docblock — a nested reference is judged by its OWN kind, never
			// by whatever route resolved the outer document.
			return $this->post_ref_readable( $doc_post_id, 'elementor-theme' ) ? $data : array();
		};

		add_filter( 'elementor/frontend/builder_content_data', $filter, 10, 2 );

		try {
			return \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $post_id, $with_css );
		} finally {
			remove_filter( 'elementor/frontend/builder_content_data', $filter, 10 );
		}
	}

	/**
	 * Render a post's body HTML in-process (no HTTP round trip) — the same
	 * approach class-mcpwp-rest-site-content.php's rendered-copy surface uses
	 * for draft/pending/private posts that a loopback fetch can't reach.
	 *
	 * Tries an Elementor render first when the post is Elementor-built, then
	 * falls back to `the_content`-filtered post_content (which is how a
	 * server-rendered PHP page template's own markup — not just the stored
	 * post_content — ends up represented here, since `the_content` output for
	 * an empty post_content still reflects theme/template filters registered
	 * on that hook).
	 *
	 * Safe to call for any post status — callers are responsible for gating
	 * who may see the result (see requester_can_see_non_public()).
	 *
	 * @param WP_Post $post Post object.
	 * @return array{html: string, method: string} Rendered HTML and the render method used.
	 */
	protected function render_post_html_in_process( $post ) {
		$post_id = (int) $post->ID;
		$html    = '';
		$method  = 'internal_content';

		if ( $this->is_elementor_built( $post_id ) && class_exists( '\Elementor\Plugin' ) ) {
			$html   = $this->render_elementor_in_process( $post_id, true );
			$method = 'internal_elementor';
		}

		if ( '' === trim( (string) $html ) ) {
			$html   = apply_filters( 'the_content', $post->post_content );
			$method = 'internal_content';
		}

		return array(
			'html'   => (string) $html,
			'method' => $method,
		);
	}

	/**
	 * Extract raw anchor links from content.
	 *
	 * @param string $content Post content.
	 * @return array Links.
	 */
	protected function extract_raw_anchor_links( $content ) {
		$links = array();

		if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$links[] = array(
					'href'   => trim( html_entity_decode( $match[1], ENT_QUOTES, get_bloginfo( 'charset' ) ) ),
					'anchor' => trim( wp_strip_all_tags( $match[2] ) ),
				);
			}
		}

		return $links;
	}

	/**
	 * Normalize internal URLs for graph lookup.
	 *
	 * @param string $url URL.
	 * @return string Normalized URL.
	 */
	protected function normalize_internal_graph_url( $url ) {
		return Mcpwp_Graph_Builder::normalize_internal_graph_url( $url );
	}

	/**
	 * Whether an href points at this site, regardless of whether it resolves
	 * to a post.
	 *
	 * resolve_internal_graph_link() answers a narrower question — "which node
	 * does this link point to" — and returns null when no node matches. That
	 * is right for building graph edges and wrong for asking "does this page
	 * link anywhere internal at all", because a link can be unmistakably
	 * internal and still resolve to nothing here: an absolute URL carrying a
	 * different path prefix (a staging install serving the same host), or a
	 * target outside the graph's sampled window.
	 *
	 * @param string $href Raw href value.
	 * @return bool
	 */
	protected function is_same_host_href( $href ) {
		return Mcpwp_Graph_Builder::is_same_host_href( $href );
	}

	/**
	 * Count links on a post that point at this site.
	 *
	 * Reads both surfaces a link can live on: post_content, and Elementor
	 * widget settings for pages whose post_content is empty by construction.
	 *
	 * @param WP_Post $post Post object.
	 * @return int Number of distinct same-host hrefs.
	 */
	protected function count_same_host_outbound_links( $post ) {
		return Mcpwp_Graph_Builder::count_same_host_outbound_links( $post );
	}

	/**
	 * Resolve an href to an internal post link, or null if external/non-content.
	 *
	 * @param string $href      Raw href value.
	 * @param string $anchor    Link anchor text.
	 * @param array  $url_to_id Normalized URL to post ID map.
	 * @return array|null
	 */
	protected function resolve_internal_graph_link( $href, $anchor, $url_to_id ) {
		return Mcpwp_Graph_Builder::resolve_internal_graph_link( $href, $anchor, $url_to_id );
	}

	/**
	 * Extract internal links embedded in an Elementor page's `_elementor_data`.
	 *
	 * post_content is empty/minimal for Elementor-built pages, so the plain
	 * <a href> scan of extract_internal_links_from_content() misses every link
	 * that lives in a widget setting (button link, icon-box link, text-editor /
	 * HTML widget markup, etc.). This walks the Elementor element tree and
	 * resolves each href to an internal post link in the same shape the graph
	 * builder consumes.
	 *
	 * @param int   $post_id   Post ID.
	 * @param array $url_to_id Normalized URL to post ID map.
	 * @return array Links.
	 */
	protected function extract_elementor_links_from_postmeta( $post_id, $url_to_id ) {
		return Mcpwp_Graph_Builder::extract_elementor_links_from_postmeta( $post_id, $url_to_id );
	}

	/**
	 * Recursively collect link hrefs from an Elementor elements tree.
	 *
	 * Covers the most common link-bearing widget settings:
	 *   - settings.link.url   (button, icon-box, image, image-box, call-to-action, flip-box)
	 *   - settings.url.url    (icon widget)
	 *   - settings.editor     (text-editor: HTML content with <a> tags)
	 *   - settings.html       (html widget: raw HTML with <a> tags)
	 *   - settings.content    (some rich-text widgets)
	 *
	 * @param array $elements Elementor elements array.
	 * @param array $hrefs    Accumulator passed by reference.
	 */
	protected function collect_elementor_link_hrefs( $elements, &$hrefs ) {
		Mcpwp_Graph_Builder::collect_elementor_link_hrefs( $elements, $hrefs );
	}

	/**
	 * Extract all internal links from post content.
	 *
	 * @param string $content   Post content HTML.
	 * @param array  $url_to_id Normalized URL to post ID map.
	 * @return array Links.
	 */
	protected function extract_internal_links_from_content( $content, $url_to_id ) {
		return Mcpwp_Graph_Builder::extract_internal_links_from_content( $content, $url_to_id );
	}
}
