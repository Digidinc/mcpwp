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
		return $this->verify_api_key( $request );
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

		$upgrade_url = $this->get_pro_upgrade_url();

		// Put upgrade_url on the WP_Error data (agents follow this field).
		// error_response() only forwards $context to hint interpolation, so a
		// contact-form or marketing URL stuffed there never reached the client.
		$error = new WP_Error(
			'pro_required',
			__( 'This feature requires MCPWP Pro. Upgrade at https://mcpwp.net/pricing/', 'mcpwp' ),
			array(
				'status'      => 403,
				'upgrade_url' => $upgrade_url,
			)
		);

		if ( class_exists( 'Mcpwp_Error_Hints' ) ) {
			return Mcpwp_Error_Hints::enhance_error( $error, array( 'upgrade_url' => $upgrade_url ) );
		}

		return $error;
	}

	/**
	 * Machine-readable Pro upgrade URL for REST / MCP upsells.
	 *
	 * Prefers install-linked Freemius checkout. Falls back to the marketing
	 * pricing page. Never /contact/.
	 *
	 * @return string
	 */
	protected function get_pro_upgrade_url() {
		if ( class_exists( 'Mcpwp_License' ) ) {
			return Mcpwp_License::get_instance()->get_upgrade_url();
		}

		return 'https://mcpwp.net/pricing/';
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
	 * @return WP_REST_Response Response object.
	 */
	protected function success_response( $data, $status = 200 ) {
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
		$error = new WP_Error( $code, $message, array( 'status' => $status ) );

		if ( class_exists( 'Mcpwp_Error_Hints' ) ) {
			$error = Mcpwp_Error_Hints::enhance_error( $error, $context );
		}

		return $error;
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
	 * Parse requested graph post types and keep only public/queryable types.
	 *
	 * @param string $post_types Raw comma-separated post types.
	 * @return array Post types.
	 */
	protected function parse_graph_post_types( $post_types ) {
		$requested = array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', $post_types ) ) ) );
		if ( empty( $requested ) ) {
			$requested = array( 'page', 'post' );
		}

		$allowed = array();
		foreach ( $requested as $post_type ) {
			$obj = get_post_type_object( $post_type );
			if ( $obj && ( ! empty( $obj->public ) || ! empty( $obj->publicly_queryable ) ) ) {
				$allowed[] = $post_type;
			}
		}

		return empty( $allowed ) ? array( 'page', 'post' ) : array_values( array_unique( $allowed ) );
	}

	/**
	 * Get graph-safe term names for a post.
	 *
	 * @param WP_Post $post Post.
	 * @return array Term names.
	 */
	protected function get_graph_term_names( $post ) {
		$names      = array();
		$taxonomies = get_object_taxonomies( $post->post_type );

		foreach ( $taxonomies as $taxonomy ) {
			$term_names = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'names' ) );
			if ( is_wp_error( $term_names ) || empty( $term_names ) ) {
				continue;
			}

			$names = array_merge( $names, $term_names );
		}

		return array_values( array_unique( array_filter( array_map( 'trim', $names ) ) ) );
	}

	/**
	 * Get post IDs included in navigation menus.
	 *
	 * @return array Menu post IDs.
	 */
	protected function get_menu_post_ids() {
		return array_keys( $this->get_menu_post_depths() );
	}

	/**
	 * Get post IDs included in navigation menus with shallowest menu depth.
	 *
	 * @return array Post ID to depth map.
	 */
	protected function get_menu_post_depths() {
		$depths    = array();
		$locations = get_nav_menu_locations();

		foreach ( $locations as $menu_id ) {
			$items = wp_get_nav_menu_items( $menu_id );
			if ( empty( $items ) || is_wp_error( $items ) ) {
				continue;
			}

			foreach ( $items as $item ) {
				if ( 'post_type' === $item->type && ! empty( $item->object_id ) ) {
					$depth   = $this->get_menu_item_depth( $item, $items );
					$post_id = (int) $item->object_id;

					if ( ! isset( $depths[ $post_id ] ) || $depth < $depths[ $post_id ] ) {
						$depths[ $post_id ] = $depth;
					}
				}
			}
		}

		return $depths;
	}

	/**
	 * Get depth for a nav menu item.
	 *
	 * @param WP_Post $item  Menu item.
	 * @param array   $items Menu items.
	 * @return int Depth.
	 */
	protected function get_menu_item_depth( $item, $items ) {
		$parent_id = (int) $item->menu_item_parent;
		$depth     = 0;
		$seen      = array();

		while ( $parent_id > 0 && ! isset( $seen[ $parent_id ] ) ) {
			$seen[ $parent_id ] = true;
			$depth++;
			$next_parent = 0;

			foreach ( $items as $candidate ) {
				if ( (int) $candidate->ID === $parent_id ) {
					$next_parent = (int) $candidate->menu_item_parent;
					break;
				}
			}

			$parent_id = $next_parent;
		}

		return $depth;
	}

	/**
	 * Calculate freshness score from age in days.
	 *
	 * @param int|null $freshness_days Days since modified.
	 * @return float Score from 0-1.
	 */
	protected function calculate_freshness_score( $freshness_days ) {
		if ( null === $freshness_days ) {
			return 0.0;
		}

		if ( $freshness_days <= 30 ) {
			return 1.0;
		}

		if ( $freshness_days >= 730 ) {
			return 0.0;
		}

		return round( max( 0, 1 - ( ( $freshness_days - 30 ) / 700 ) ), 3 );
	}

	/**
	 * Calculate a simple hub score for graph nodes.
	 *
	 * @param array $node Graph node.
	 * @return float Score.
	 */
	protected function calculate_hub_score( $node ) {
		$score = ( (int) $node['inbound_count'] * 2 ) + (int) $node['outbound_count'];

		if ( ! empty( $node['in_menu'] ) ) {
			$score += null === $node['menu_depth'] ? 2 : max( 1, 3 - (int) $node['menu_depth'] );
		}

		if ( ! empty( $node['parent_id'] ) ) {
			$score += 1;
		}

		$score += min( 3, count( $node['terms'] ) );

		return round( $score, 3 );
	}

	/**
	 * Calculate orphan severity for nodes.
	 *
	 * @param array $node Graph node.
	 * @return string Severity.
	 */
	protected function calculate_orphan_severity( $node ) {
		if ( ! empty( $node['in_menu'] ) || (int) $node['inbound_count'] > 0 ) {
			return 'none';
		}

		if ( 'page' === $node['type'] && (int) $node['word_count'] >= 300 ) {
			return 'high';
		}

		if ( (int) $node['word_count'] >= 150 ) {
			return 'medium';
		}

		return 'low';
	}

	/**
	 * Calculate a compact PageRank-style score for content-link edges.
	 *
	 * @param array $node_ids Node IDs.
	 * @param array $edges    Graph edges.
	 * @return array Scores.
	 */
	protected function calculate_graph_page_rank( $node_ids, $edges ) {
		$count = count( $node_ids );
		if ( 0 === $count ) {
			return array();
		}

		$ranks    = array_fill_keys( $node_ids, 1 / $count );
		$outgoing = array_fill_keys( $node_ids, array() );

		foreach ( $edges as $edge ) {
			if ( 'content_link' !== $edge['type'] || ! array_key_exists( $edge['from'], $outgoing ) ) {
				continue;
			}

			if ( isset( $outgoing[ $edge['from'] ], $ranks[ $edge['to'] ] ) ) {
				$outgoing[ $edge['from'] ][] = (int) $edge['to'];
			}
		}

		for ( $i = 0; $i < 8; $i++ ) {
			$next = array_fill_keys( $node_ids, ( 1 - 0.85 ) / $count );

			foreach ( $node_ids as $node_id ) {
				$targets = array_values( array_unique( $outgoing[ $node_id ] ) );
				if ( empty( $targets ) ) {
					continue;
				}

				$share = ( $ranks[ $node_id ] * 0.85 ) / count( $targets );
				foreach ( $targets as $target_id ) {
					$next[ $target_id ] += $share;
				}
			}

			$ranks = $next;
		}

		foreach ( $ranks as $id => $score ) {
			$ranks[ $id ] = round( $score, 6 );
		}

		return $ranks;
	}

	/**
	 * Extract heading texts from block/raw content.
	 *
	 * @param string $content Post content.
	 * @return array Heading texts.
	 */
	protected function extract_heading_texts( $content ) {
		$headings = array();

		if ( preg_match_all( '/<h([1-6])[^>]*>(.*?)<\/h\1>/is', $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$text = trim( wp_strip_all_tags( $match[2] ) );
				if ( '' !== $text ) {
					$headings[] = array(
						'level' => (int) $match[1],
						'text'  => $text,
					);
				}
			}
		}

		return $headings;
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
		if ( 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true ) ) {
			return true;
		}
		// empty(), not a (string) cast: array-stored data is data (#845).
		return ! empty( get_post_meta( $post_id, '_elementor_data', true ) );
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
			$html   = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $post_id, true );
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
		$parts = wp_parse_url( $url );
		$path  = isset( $parts['path'] ) ? untrailingslashit( $parts['path'] ) : '';
		$query = isset( $parts['query'] ) ? '?' . $parts['query'] : '';

		return strtolower( $path . $query );
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
		$href = trim( (string) $href );

		// A fragment jumps within the page; a non-navigational scheme is not a
		// link to another document. Neither is an internal outbound link.
		// data: is included — it embeds content rather than pointing anywhere.
		if ( '' === $href || 0 === strpos( $href, '#' )
			|| preg_match( '/^(mailto|tel|sms|javascript|data):/i', $href ) ) {
			return false;
		}

		$home = wp_parse_url( home_url() );
		if ( empty( $home['host'] ) ) {
			return false;
		}

		// Classify by the URL's own shape. An earlier version asked
		// wp_http_validate_url() whether the href was absolute and fell back to
		// home_url( $href ) when it said no — which is wrong in a way that
		// inverts this function's answer.
		//
		// wp_http_validate_url() returns false for reasons that have nothing to
		// do with being absolute: it runs gethostbyname() on the host and
		// `return false`s when the lookup fails (wp-includes/http.php), and it
		// rejects userinfo and private/loopback targets. home_url() then appends
		// the whole absolute URL as a path — home_url('https://other.test/x')
		// is 'https://example.com/https://other.test/x', whose parsed host is
		// OUR host. Every unresolvable external link counted as internal, and on
		// a host with no outbound DNS that meant all of them, silencing
		// no_internal_outbound_links exactly as #717 complained.
		//
		// It also blocked per-href DNS lookups inside the bulk audit loop.
		// Determining whether a URL points at our own host needs no network.
		if ( 0 === strpos( $href, '//' ) ) {
			// Protocol-relative: inherits our scheme, carries its own host.
			$target = wp_parse_url( 'https:' . $href );
		} elseif ( preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $href ) ) {
			// Any other scheme — absolute. Parse as-is; a scheme we do not
			// navigate with was already rejected above.
			$target = wp_parse_url( $href );
		} else {
			// No scheme and no leading '//' — a site-relative path or a bare
			// filename. Internal by construction.
			return true;
		}

		if ( empty( $target['host'] ) ) {
			return false;
		}

		return strtolower( $target['host'] ) === strtolower( $home['host'] );
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
		$hrefs = array();

		if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>/i', (string) $post->post_content, $matches ) ) {
			foreach ( $matches[1] as $href ) {
				$hrefs[] = $href;
			}
		}

		// In whatever form it is stored (#845): an array-stored tree has links too.
		$data = Mcpwp_Elementor_Stored_Tree::of_post( (int) $post->ID )['elements'];
		if ( ! empty( $data ) ) {
			$this->collect_elementor_link_hrefs( $data, $hrefs );
		}

		$count = 0;
		foreach ( array_unique( $hrefs ) as $href ) {
			$href = trim( html_entity_decode( $href, ENT_QUOTES, 'UTF-8' ) );
			if ( $this->is_same_host_href( $href ) ) {
				$count++;
			}
		}

		return $count;
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
		$href = trim( $href );
		if ( '' === $href || 0 === strpos( $href, '#' ) || preg_match( '/^(mailto|tel|sms|javascript):/i', $href ) ) {
			return null;
		}

		$absolute = wp_http_validate_url( $href ) ? $href : home_url( $href );
		$home     = wp_parse_url( home_url() );
		$target   = wp_parse_url( $absolute );

		if ( empty( $target['host'] ) || empty( $home['host'] ) || strtolower( $target['host'] ) !== strtolower( $home['host'] ) ) {
			return null;
		}

		$normalized = $this->normalize_internal_graph_url( $absolute );
		$target_id  = isset( $url_to_id[ $normalized ] ) ? (int) $url_to_id[ $normalized ] : url_to_postid( $absolute );

		if ( ! $target_id ) {
			return null;
		}

		return array(
			'target_id' => $target_id,
			'url'       => $absolute,
			'anchor'    => trim( $anchor ),
		);
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
		// In whatever form it is stored (#845): an array-stored tree has links too.
		$data = Mcpwp_Elementor_Stored_Tree::of_post( $post_id )['elements'];
		if ( empty( $data ) ) {
			return array();
		}

		$hrefs = array();
		$this->collect_elementor_link_hrefs( $data, $hrefs );

		$links = array();
		foreach ( array_unique( $hrefs ) as $href ) {
			$href = trim( html_entity_decode( $href, ENT_QUOTES, 'UTF-8' ) );
			if ( '' === $href ) {
				continue;
			}
			$link = $this->resolve_internal_graph_link( $href, '', $url_to_id );
			if ( $link ) {
				$links[] = $link;
			}
		}

		return $links;
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
		if ( ! is_array( $elements ) ) {
			return;
		}

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();

			// button, icon-box, image, image-box, call-to-action, flip-box — link object.
			if ( ! empty( $settings['link']['url'] ) && is_string( $settings['link']['url'] ) ) {
				$hrefs[] = $settings['link']['url'];
			}

			// icon widget — url object.
			if ( ! empty( $settings['url']['url'] ) && is_string( $settings['url']['url'] ) ) {
				$hrefs[] = $settings['url']['url'];
			}

			// text-editor, html, rich-text content — parse HTML for <a href>.
			foreach ( array( 'editor', 'html', 'content', 'inner_text' ) as $field ) {
				if ( ! empty( $settings[ $field ] ) && is_string( $settings[ $field ] ) ) {
					if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $settings[ $field ], $matches ) ) {
						foreach ( $matches[1] as $href ) {
							$hrefs[] = $href;
						}
					}
				}
			}

			// Recurse into child elements.
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$this->collect_elementor_link_hrefs( $element['elements'], $hrefs );
			}
		}
	}

	/**
	 * Extract all internal links from post content.
	 *
	 * @param string $content   Post content HTML.
	 * @param array  $url_to_id Normalized URL to post ID map.
	 * @return array Links.
	 */
	protected function extract_internal_links_from_content( $content, $url_to_id ) {
		$links = array();

		if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$link = $this->resolve_internal_graph_link( $match[1], wp_strip_all_tags( $match[2] ), $url_to_id );
				if ( $link ) {
					$links[] = $link;
				}
			}
		}

		return $links;
	}
}
