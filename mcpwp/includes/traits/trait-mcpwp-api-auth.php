<?php
/**
 * API Authentication Trait
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * API authentication functionality.
 */
trait Mcpwp_Api_Auth {

	/**
	 * The matched API key record for the current request.
	 *
	 * Populated by verify_api_key() so downstream code (e.g. MCP controller)
	 * can check the key's role and tool category restrictions.
	 *
	 * @var array|null
	 */
	protected $current_api_key_record = null;

	/** Verified identity for audit attribution only; never used to grant access. */
	protected $mcpwp_audit_key_id = '';

	/**
	 * Request-scoped post-reference resolutions (#1030 round-5), keyed by
	 * the request object itself so the permission check and the handler of
	 * one request share one answer, and two requests never share one.
	 *
	 * @var SplObjectStorage|null
	 */
	private $post_ref_resolutions = null;

	/**
	 * Get the matched API key record for the current request.
	 *
	 * @return array|null Key record or null if not authenticated yet.
	 */
	public function get_current_api_key_record() {
		return $this->current_api_key_record;
	}

	/** Audit identity is independent of authorization state and never grants access. */
	public function get_audit_key_id() {
		return $this->mcpwp_audit_key_id;
	}

	/**
	 * Whether a post status is publicly viewable.
	 *
	 * Only statuses whose registered status object carries the `public` flag
	 * count as public — on a stock WordPress install that is exactly the
	 * built-in `publish` status. Draft, pending, private, future, trash and
	 * every internal status return false. Unknown or unregistered status
	 * strings also return false, so ambiguous input is treated as non-public.
	 *
	 * Shared gate: every REST controller that reads or renders post bodies
	 * (site-content, elementor, blocks, pages, posts, the SEO structured-data /
	 * media / readiness audits, content-quality, and the content-graph) uses this
	 * together with requester_can_see_non_public() so a read-only key can never
	 * reach an unpublished body through any read surface. The content-graph and
	 * /drafts surfaces enumerate rather than resolve a single post, so they clamp
	 * on requester_can_see_non_public() directly instead of calling
	 * guard_non_public_post().
	 *
	 * @param string $status Post status slug.
	 * @return bool True when the status is publicly viewable.
	 */
	protected function is_public_status( $status ) {
		$status_obj = get_post_status_object( (string) $status );
		return $status_obj && ! empty( $status_obj->public );
	}

	/**
	 * Whether the current caller may access non-public content.
	 *
	 * Non-public content (draft, pending, private, etc.) is limited to keys
	 * carrying write scope or higher (write or admin). A read-only key — or a
	 * request with no matched key record — is treated as unprivileged and sees
	 * published content only. Trusted in-dashboard admin-AJAX callers (admin
	 * capability plus a valid nonce) are also allowed, matching the plugin's
	 * other privileged read gates.
	 *
	 * @return bool True when the caller may access non-public content.
	 */
	protected function requester_can_see_non_public() {
		// A WebMCP principal (#981) is a real WordPress user, and WordPress
		// decides per post what that user may read (read_post: private
		// posts of a type whose read_private_* cap the user lacks, other
		// people's drafts of a type they cannot edit …). A blanket "sees
		// non-public" answer would override that, so this post-less gate is
		// always false for it: every list/enumeration clamps to published
		// content, and single-post reads go through requester_can_read_post(),
		// which asks WordPress about that one post.
		if ( $this->requester_is_webmcp() ) {
			return false;
		}

		$key_record = $this->get_current_api_key_record();

		// An OAuth token acting as a user (PR-2 P0): list-level non-public
		// visibility needs write scope AND what WordPress gives an Editor
		// for it — reading others' drafts and private posts.  Per-post reads
		// still ask read_post (requester_can_read_post()).
		if ( $this->requester_oauth_user_id() > 0 ) {
			return $key_record && $this->key_has_scope( $key_record, 'write' )
				&& current_user_can( 'edit_others_posts' ) && current_user_can( 'read_private_posts' );
		}

		if ( $key_record && $this->key_has_scope( $key_record, 'write' ) ) {
			return true;
		}

		return $this->is_trusted_admin_ajax_request();
	}

	/**
	 * Whether the current principal is a WebMCP user (#981).
	 *
	 * @return bool
	 */
	protected function requester_is_webmcp() {
		$key_record = $this->get_current_api_key_record();
		return is_array( $key_record ) && isset( $key_record['type'] ) && class_exists( 'Mcpwp_WebMCP' ) && Mcpwp_WebMCP::SOURCE === $key_record['type'];
	}

	/**
	 * Whether the current principal ACTS AS A REAL WORDPRESS USER (#1100).
	 *
	 * The ONE predicate the route capability map is enforced on.  A user-run
	 * principal is gated by scope AND the route's WordPress capability; a
	 * bot-run principal (X-API-Key / ?api_key keys, broker bearer tokens,
	 * client_credentials OAuth tokens — all running as the mcpwp_api_agent
	 * service account) by scope alone, because that account deliberately
	 * lacks manage_woocommerce, edit_theme_options and similar.
	 *
	 * A new user-run principal needs exactly one more line here.
	 *
	 * @return bool
	 */
	protected function requester_acts_as_user() {
		return $this->requester_oauth_user_id() > 0                                                         // OAuth token minted for a user (#1099).
			|| $this->requester_is_webmcp()                                                                  // WebMCP principal record (#981).
			|| ( class_exists( 'Mcpwp_WebMCP' ) && Mcpwp_WebMCP::dispatch_user_id() > 0 )                    // WebMCP cookie + nonce dispatch.
			|| $this->is_trusted_admin_ajax_request()                                                         // wp-admin Chat tab (admin, nonce).
			|| ( class_exists( 'Mcpwp_Destructive_Gate' ) && Mcpwp_Destructive_Gate::applying_as_admin() > 0 ); // Approved delete replayed by an admin.
	}

	/**
	 * The capability a user-run principal lacks for a route, or '' when it
	 * holds every capability the route's row requires. The single decision
	 * check_user_run_capability() makes on a dispatch and the tool listing
	 * (principal_may_call_tool()) asks to hide what a call would refuse.
	 *
	 * A row may require several capabilities at once ('a+b'); every one
	 * must be held. Used only where one action spans two post types
	 * (DELETE /drafts/delete-all: others' posts AND others' pages).
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route.
	 * @return string The required capability string when one is missing; ''.
	 */
	protected function user_run_capability_shortfall( $method, $route ) {
		if ( ! class_exists( 'Mcpwp_Route_Capability_Map' ) ) {
			return 'manage_options';
		}
		$capability = Mcpwp_Route_Capability_Map::required_capability( $method, $route );
		foreach ( '' === $capability ? array() : explode( '+', $capability ) as $one ) {
			if ( ! current_user_can( $one ) ) {
				return $capability;
			}
		}

		return '';
	}

	/**
	 * Enforce the route's WordPress capability on a user-run principal.
	 *
	 * Unknown routes and undecided (TODO) rows are admin-only until decided
	 * (Mcpwp_Route_Capability_Map::UNDECIDED_ADMIN_ONLY_CAPABILITY) — never a
	 * pass-through.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	protected function check_user_run_capability( $request ) {
		if ( ! $this->requester_acts_as_user() ) {
			return true; // bots: scope is their gate (and see check_generic_post_type_access()).
		}
		// Fail CLOSED when the map is not loaded (a pruned build, a load-order
		// bug): a user-run principal is refused rather than passed through.
		if ( ! $this->route_capability_map_available() ) {
			return new WP_Error( 'rest_forbidden_capability', __( 'Your WordPress account is not allowed to do this.', 'mcpwp' ), array( 'status' => 403 ) );
		}
		$method     = method_exists( $request, 'get_method' ) ? (string) $request->get_method() : 'GET';
		$route      = method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
		$capability = Mcpwp_Route_Capability_Map::required_capability( $method, $route );
		if ( '' !== $this->user_run_capability_shortfall( $method, $route ) ) {
			return new WP_Error(
				'rest_forbidden_capability',
				__( 'Your WordPress account is not allowed to do this.', 'mcpwp' ),
				array(
					'status'              => 403,
					'required_capability' => $capability,
				)
			);
		}

		return true;
	}

	/**
	 * Generic post writes that CREATE a post of a caller-chosen or implied
	 * type, keyed by normalised route => how the target type is found.
	 * 'param:<name>=<default>' reads the type from a request parameter;
	 * 'items:<param>=<default>' reads it from each item of an array param;
	 * 'type:<type>' is fixed; 'source' is the type of the declared source post.
	 *
	 * @return array<string,string>
	 */
	protected static function user_run_create_type_routes() {
		return array(
			'/posts'                     => 'param:post_type=post',
			'/posts/bulk'                => 'items:posts=post',
			'/pages'                     => 'type:page',
			'/pages/bulk'                => 'type:page',
			'/elementor/page'            => 'type:page',
			'/elementor/build-page'      => 'type:page',
			'/elementor/landing-page'    => 'type:page',
			'/elementor/clone'           => 'source:source_id', // The handler copies the source's post_type (#1118 P0-3).
			'/elementor/blueprints/build' => 'type:page',
		);
	}

	/**
	 * The request's params AS THE HANDLERS SEE THEM (#1110): every key any
	 * bucket carries, each resolved through get_param() — the accessor every
	 * handler uses. WP_REST_Request::get_params() merges the buckets so a
	 * null in a higher bucket (a JSON body's {"updates":null}) OVERRIDES a
	 * value below it (?updates[0][post_id]=X), while get_param() skips the
	 * null and returns the query value; a gate reading get_params() judged
	 * nothing while the handler wrote to post X. Every gate reader that
	 * judges a list or an id a handler will act on reads this instead.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array<string,mixed> Empty when the request exposes no params.
	 */
	protected function gate_params( $request ) {
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_params' ) ) {
			return array();
		}
		$merged = $request->get_params();
		if ( ! is_array( $merged ) ) {
			return array();
		}
		if ( ! method_exists( $request, 'get_param' ) ) {
			return $merged;
		}
		$params = array();
		foreach ( array_keys( $merged ) as $key ) {
			$params[ $key ] = $request->get_param( $key );
		}
		return $params;
	}

	/**
	 * Every request item list the gate reads PER ITEM on this route: the
	 * 'list[].field' post references (get_post_ref_param_map()) and the
	 * 'items:list' create rules (user_run_create_type_routes()). Derived from
	 * those two tables, so a bulk door declared there is covered here with
	 * no second list to keep in step.
	 *
	 * @param string $path      Route without the /mcpwp/v1 prefix, lower-cased.
	 * @param bool   $user_run  Whether the caller acts as a user ('@read-user' refs are then read too).
	 * @return array<string,string[]> List param => the post-id fields declared in its items.
	 */
	protected static function bulk_item_list_params( $path, $user_run = false ) {
		$lists = array();
		foreach ( self::post_ref_specs_for_path( $path ) as $declared_spec ) {
			list( $spec, $class ) = self::post_ref_spec_parts( $declared_spec );
			if ( 'read-user' === $class && ! $user_run ) {
				continue;
			}
			if ( false !== strpos( $spec, '[].' ) ) {
				list( $list, $field ) = explode( '[].', $spec, 2 );
				$lists[ $list ][]     = $field;
			}
		}
		$routes = self::user_run_create_type_routes();
		if ( isset( $routes[ $path ] ) && 0 === strpos( $routes[ $path ], 'items:' ) ) {
			$list = (string) strstr( substr( $routes[ $path ], 6 ) . '=', '=', true );
			if ( ! isset( $lists[ $list ] ) ) {
				$lists[ $list ] = array();
			}
		}
		return $lists;
	}

	/**
	 * Whether a declared per-item post id is one both parsers read the same
	 * way: empty (the handler skips the item and writes nothing) or a
	 * canonical positive integer. Anything else — '9402abc', '0x24C2',
	 * [9402], 9402.0 — the gate's is_numeric() and a handler's absint()
	 * disagree on ('9402abc': skipped by the gate, post 9402 to absint();
	 * [9402]: skipped, post 1), so it is refused rather than normalised.
	 *
	 * @param mixed $value Item field value.
	 * @return bool
	 */
	protected static function is_unambiguous_item_post_id( $value ) {
		if ( empty( $value ) ) {
			return true;
		}
		if ( is_int( $value ) ) {
			return $value > 0;
		}
		return is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/', $value );
	}

	/**
	 * Bulk item lists must be lists of OBJECTS — refused whole otherwise
	 * (#1103 round 3, parser differential). The gate reads post types and
	 * post ids only from array items; the handlers used to json_decode a
	 * STRING item as well, so `{"posts":["{\"post_type\":\"product\"}"]}`
	 * passed every check and then created a product, and a string update
	 * item edited a post the per-post edit_post check never saw. This runs
	 * before ANY post-type or post-reference extraction, on every verdict
	 * path (check_gate_entry()); normalize_bulk_items() no longer decodes
	 * strings either, so there is one parser and it is is_array(). A
	 * declared per-item post id must be one the gate and the handler read
	 * identically (is_unambiguous_item_post_id()), or the request is
	 * refused the same way.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return true|WP_Error 400 invalid_item.
	 */
	protected function check_bulk_items_are_objects( $request ) {
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) || ! method_exists( $request, 'get_params' ) ) {
			return true;
		}
		$route = $this->normalize_route_for_authorization( (string) $request->get_route() );
		$path  = strtolower( untrailingslashit( (string) preg_replace( '#^/mcpwp/v[0-9]+#i', '', $route ) ) );
		$lists = self::bulk_item_list_params( $path, $this->requester_acts_as_user() );
		if ( empty( $lists ) ) {
			return true;
		}
		$params = $this->gate_params( $request );
		foreach ( $lists as $list => $id_fields ) {
			if ( ! array_key_exists( $list, $params ) || null === $params[ $list ] ) {
				continue;
			}
			$items = $params[ $list ];
			$bad   = is_array( $items ) ? null : -1;
			if ( null === $bad ) {
				foreach ( $items as $index => $item ) {
					if ( ! is_array( $item ) ) {
						$bad = $index;
						break;
					}
					foreach ( $id_fields as $field ) {
						if ( array_key_exists( $field, $item ) && ! self::is_unambiguous_item_post_id( $item[ $field ] ) ) {
							$bad = $index;
							break 2;
						}
					}
				}
			}
			if ( null !== $bad ) {
				return new WP_Error(
					'invalid_item',
					sprintf(
						/* translators: %s: request parameter name, e.g. "posts". */
						__( 'Every item in "%s" must be a JSON object, and any post id in it a plain positive integer; nothing was changed.', 'mcpwp' ),
						$list
					),
					array(
						'status' => 400,
						'param'  => $list,
						'index'  => $bad,
					)
				);
			}
		}
		return true;
	}

	/**
	 * The first check on every verdict path that applies the route rules —
	 * the main tail (check_post_naming_params_category()) and the trusted
	 * admin AJAX and approved-destructive-replay early returns: bulk item
	 * shape first, then the route's user-run capability.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return true|WP_Error
	 */
	protected function check_gate_entry( $request ) {
		// Tell the meta-write helper whether this request's principal acts as
		// a real user (#1108); set on every request and sub-request.
		if ( class_exists( 'Mcpwp_Post_Fields' ) ) {
			Mcpwp_Post_Fields::set_user_run_principal( $this->requester_acts_as_user() );
		}
		$shape = $this->check_bulk_items_are_objects( $request );
		if ( is_wp_error( $shape ) ) {
			return $shape;
		}
		return $this->check_user_run_capability( $request );
	}

	/**
	 * Whether the route capability map class is loaded. One seam, so the
	 * fail-closed branch of check_user_run_capability() can be tested without
	 * unloading a class (#1108).
	 *
	 * @return bool
	 */
	protected function route_capability_map_available() {
		return class_exists( 'Mcpwp_Route_Capability_Map' );
	}

	/**
	 * The generic post doors: routes that write a post whose TYPE the caller
	 * chooses or that the door infers from an existing post (#1103 round 2).
	 * A bot is held to post/page there unless it has admin scope; a user is
	 * held to the type's own capability object.
	 *
	 * @param string $path Route without the /mcpwp/v1 prefix, lower-cased.
	 * @return bool
	 */
	protected static function is_generic_post_door( $path ) {
		return 1 === preg_match( '#^/(posts|pages|content)(/|$)#', (string) $path );
	}

	/**
	 * Every (post type, cap key) a write targets. Creates read the type from
	 * the request (user_run_create_type_routes(), a page clone, a
	 * translation's source); writes that name declared post references read
	 * it from each existing post. GET/HEAD/OPTIONS target nothing.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array{path:string,targets:array<int,array{0:string,1:string}>}
	 */
	protected function generic_post_type_targets( $request ) {
		$method = method_exists( $request, 'get_method' ) ? strtoupper( (string) $request->get_method() ) : 'GET';
		$route  = method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
		$path   = strtolower( untrailingslashit( (string) preg_replace( '#^/mcpwp/v[0-9]+#i', '', $route ) ) );
		if ( in_array( $method, array( 'GET', 'HEAD', 'OPTIONS' ), true ) ) {
			return array( 'path' => $path, 'targets' => array() );
		}

		$checks       = array();
		$routes       = self::user_run_create_type_routes();
		$source_param = '';

		if ( 'POST' === $method && isset( $routes[ $path ] ) ) {
			$rule = $routes[ $path ];
			if ( 0 === strpos( $rule, 'type:' ) ) {
				$checks[] = array( substr( $rule, 5 ), 'create_posts' );
			} elseif ( 0 === strpos( $rule, 'param:' ) ) {
				list( $param, $default ) = explode( '=', substr( $rule, 6 ), 2 );
				$value    = $request->get_param( $param );
				$checks[] = array( ( is_string( $value ) && '' !== $value ) ? sanitize_key( $value ) : ( null === $value || '' === $value ? $default : '' ), 'create_posts' );
			} elseif ( 0 === strpos( $rule, 'source:' ) ) {
				// The created type is the declared SOURCE post's type: resolved
				// below, from the same reference resolution the chokepoint uses.
				$source_param = substr( $rule, 7 );
			} elseif ( 0 === strpos( $rule, 'items:' ) ) {
				list( $param, $default ) = explode( '=', substr( $rule, 6 ), 2 );
				$items = $request->get_param( $param );
				foreach ( is_array( $items ) ? $items : array() as $item ) {
					$type     = is_array( $item ) && array_key_exists( 'post_type', $item ) ? $item['post_type'] : null;
					$checks[] = array( ( is_string( $type ) && '' !== $type ) ? sanitize_key( $type ) : ( null === $type || '' === $type ? $default : '' ), 'create_posts' );
				}
			}
		}

		if ( 'POST' === $method && preg_match( '#^/pages/\\d+/clone$#', $path ) ) {
			$checks[] = array( 'page', 'create_posts' );
		}

		// DELETE /content/{post_type}/{id}: the type is named in the path.
		if ( preg_match( '#^/content/([a-z0-9_-]+)/\\d+$#', $path, $m ) ) {
			$checks[] = array( sanitize_key( $m[1] ), 'DELETE' === $method ? 'delete_posts' : 'edit_posts' );
		}

		// Existing posts named by declared references. A reference the caller
		// cannot see is skipped: the post-ref chokepoint already answers it
		// exactly like a missing id, and a type-based refusal for it would be
		// an existence oracle.
		$resolution = $this->get_post_ref_resolution( $request );
		if ( ! is_wp_error( $resolution ) && null !== $resolution['refs'] ) {
			$is_translation = 'POST' === $method && preg_match( '#^/(posts|pages)/\\d+/translations$#', $path );
			// Only WRITTEN references charge their type's write capability
			// (#1101 PR-3c). A read-only reference ('@read' / '@read-user' —
			// a link target, a featured image, a clone source) is judged by
			// read_post alone; charging edit_pages for linking to a published
			// page would refuse an Author a call core allows.
			$written = array_flip( array_map( 'intval', array_merge( $resolution['refs']['scalar'], $resolution['refs']['items'] ) ) );
			// 'source:<param>': the create cap of the source's own type. A
			// hidden or missing source is skipped — the chokepoint answers it
			// as the canonical 404, and a type verdict here would be an
			// existence oracle.
			if ( '' !== $source_param ) {
				$source_value = $request->get_param( $source_param );
				$source_id    = is_numeric( $source_value ) ? (int) $source_value : 0;
				if ( $source_id > 0 && isset( $resolution['rows'][ $source_id ] ) && empty( $resolution['hidden'][ $source_id ] ) ) {
					$checks[] = array( (string) $resolution['rows'][ $source_id ]['post_type'], 'create_posts' );
				}
			}
			foreach ( $resolution['rows'] as $id => $row ) {
				if ( ! empty( $resolution['hidden'][ $id ] ) || ! isset( $written[ (int) $id ] ) ) {
					continue;
				}
				$checks[] = array( $row['post_type'], 'DELETE' === $method ? 'delete_posts' : 'edit_posts' );
				if ( $is_translation ) {
					$checks[] = array( $row['post_type'], 'create_posts' );
				}
			}
		}

		return array( 'path' => $path, 'targets' => $checks );
	}

	/**
	 * THE post-type rule of the generic post doors, for every principal
	 * (#1100 / #1103 round 2) — one place, two branches:
	 *
	 *  - BOT-run principals (API keys, broker bearer, client_credentials —
	 *    not requester_acts_as_user()): on a generic post door
	 *    (is_generic_post_door()), a target type other than post or page
	 *    requires ADMIN scope — on create (the post_type param) and on update
	 *    and delete (the type of the existing post). Without it a write key
	 *    holding content + woocommerce created products through POST /posts,
	 *    around the admin scope on POST /woocommerce/products. This is a
	 *    scope requirement: it runs in the verdict tail every bot path ends
	 *    in, after the route-level scope check, so it cannot be skipped.
	 *  - USER-run principals: the target type's own capability object —
	 *    create_posts / edit_posts / delete_posts — on EVERY write that names a
	 *    type (F1), never a fixed string. An unknown type or a missing cap
	 *    object fails closed.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	protected function check_generic_post_type_access( $request ) {
		$found = $this->generic_post_type_targets( $request );
		if ( empty( $found['targets'] ) ) {
			return true;
		}

		if ( ! $this->requester_acts_as_user() ) {
			if ( ! self::is_generic_post_door( $found['path'] ) ) {
				return true;
			}
			foreach ( $found['targets'] as list( $type ) ) {
				if ( in_array( (string) $type, array( 'post', 'page' ), true ) ) {
					continue;
				}
				$record = $this->get_current_api_key_record();
				if ( ! is_array( $record ) || ! $this->key_has_scope( $record, 'admin' ) ) {
					return new WP_Error(
						'insufficient_scope',
						__( 'Writing this post type through the generic post endpoints requires an admin-scoped key. Use the post type\'s own endpoints.', 'mcpwp' ),
						array(
							'status'         => 403,
							'required_scope' => 'admin',
							'post_type'      => (string) $type,
						)
					);
				}
			}
			return true;
		}

		return $this->check_user_run_post_type_capability( $found['targets'] );
	}

	/**
	 * The user-run branch of check_generic_post_type_access(): every target
	 * type's own capability (F1).
	 *
	 * @param array<int,array{0:string,1:string}> $targets (type, cap key) pairs.
	 * @return true|WP_Error
	 */
	protected function check_user_run_post_type_capability( array $targets ) {
		foreach ( $targets as list( $type, $cap_key ) ) {
			$pto = ( '' !== $type && function_exists( 'get_post_type_object' ) ) ? get_post_type_object( $type ) : null;
			$cap = ( is_object( $pto ) && isset( $pto->cap ) && is_object( $pto->cap ) && ! empty( $pto->cap->{$cap_key} ) ) ? (string) $pto->cap->{$cap_key} : '';
			if ( '' === $cap || ! current_user_can( $cap ) ) {
				return new WP_Error(
					'rest_forbidden_capability',
					__( 'Your WordPress account is not allowed to create or change this kind of content.', 'mcpwp' ),
					array(
						'status'              => 403,
						'post_type'           => (string) $type,
						'required_capability' => '' === $cap ? 'unknown post type' : $cap,
					)
				);
			}
		}
		return true;
	}

	/**
	 * The WordPress user an OAuth token acts as (PR-2 P0), or 0.
	 *
	 * @return int
	 */
	protected function requester_oauth_user_id() {
		$key_record = $this->get_current_api_key_record();
		if ( ! is_array( $key_record ) || ! isset( $key_record['type'] ) || 'oauth' !== $key_record['type'] || empty( $key_record['acts_as_user'] ) ) {
			return 0;
		}
		return (int) $key_record['acts_as_user'];
	}

	/**
	 * Whether a user meets the site's minimum role for OAuth connections.
	 *
	 * Fails closed when the resolver is unavailable.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	protected function oauth_user_meets_min_role( $user_id ) {
		return class_exists( 'Mcpwp_OAuth_Well_Known' ) && Mcpwp_OAuth_Well_Known::user_can_connect( (int) $user_id );
	}

	/**
	 * Whether the current caller may read ONE resolved post — the per-post
	 * form of requester_can_see_non_public(), used by every single-post read
	 * (guard_non_public_post(), the post-ref chokepoint, fetch/meta/render).
	 *
	 * For an API key / OAuth / admin-AJAX caller: unchanged — public status,
	 * or non-public visibility by scope. For a WebMCP user: exactly what
	 * WordPress says, current_user_can( 'read_post', $id ), whatever the
	 * status.
	 *
	 * @param WP_Post|mixed $post Post.
	 * @return bool
	 */
	protected function requester_can_read_post( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		if ( $this->requester_is_webmcp() ) {
			return current_user_can( 'read_post', (int) $post->ID );
		}

		// OAuth acting as a user: WordPress decides (read_post), and a
		// non-public post additionally needs write scope — the scope stays a
		// ceiling, exactly as for an API key.
		if ( $this->requester_oauth_user_id() > 0 ) {
			if ( ! current_user_can( 'read_post', (int) $post->ID ) ) {
				return false;
			}
			$key_record = $this->get_current_api_key_record();
			return $this->is_public_status( $post->post_status ) || $this->key_has_scope( $key_record, 'write' );
		}

		return $this->is_public_status( $post->post_status ) || $this->requester_can_see_non_public();
	}

	/**
	 * Status-parity guard for a resolved post on a read surface.
	 *
	 * Returns a 404 WP_Error when the post is non-public and the caller lacks
	 * write scope (or trusted admin-AJAX context); returns null when access is
	 * allowed. The 404 is intentionally indistinguishable from a genuinely
	 * missing post so the response never discloses that a hidden draft/pending/
	 * private post exists. Callers pass the exact not-found message their
	 * surface already uses so the parity is byte-for-byte.
	 *
	 * $hint controls the 'hint' entry of the returned error's data, and must
	 * match whatever the caller's OWN "genuinely missing" branch produces for
	 * the same id, or the two 404s are distinguishable by hint text alone
	 * (#1026):
	 *  - omitted (default `false`): the generic auto-hint (via
	 *    Mcpwp_Error_Hints, unsubstituted `{id}`) — only correct when the
	 *    caller's own missing-post branch also leaves hint generation to
	 *    error_response()/enhance_error() with no context.
	 *  - `''` (empty string): no 'hint' key at all — matches a caller whose
	 *    own missing-post branch returns a bare WP_Error with no hint.
	 *  - any other string: used verbatim as 'hint' — the caller is
	 *    responsible for building it identically (id-substituted) to its own
	 *    missing-post branch's hint.
	 *
	 * @param WP_Post|mixed $post    Resolved post object.
	 * @param string        $message Surface-specific "not found" message.
	 * @param string|false  $hint    See above. Default false (generic auto-hint).
	 * @return WP_Error|null WP_Error(404) when blocked, null when allowed.
	 */
	protected function guard_non_public_post( $post, $message, $hint = false ) {
		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		if ( $this->requester_can_read_post( $post ) ) {
			return null;
		}

		return $this->build_not_found_error( $message, $hint );
	}

	/**
	 * Build a 404 WP_Error, identical in shape to a genuinely missing
	 * resource. Shared by every "existence must not be disclosed" guard in
	 * this trait (guard_non_public_post(), guard_post_type_category()) so
	 * they cannot drift into two slightly different 404 shapes.
	 *
	 * @param string       $message Surface-specific "not found" message.
	 * @param string|false $hint    See guard_non_public_post()'s docblock.
	 *                              Default false (generic auto-hint).
	 * @return WP_Error
	 */
	protected function build_not_found_error( $message, $hint = false ) {
		// The canonical post-ref message is only ever sent with its own fixed
		// hint, whichever hint a caller asked for (#1030 round-5).
		if ( self::is_post_ref_not_found( 'not_found', $message ) ) {
			return $this->post_ref_not_found_error();
		}

		if ( false === $hint ) {
			// Built inline rather than via $this->error_response() so this guard
			// has no dependency on the base REST controller — the trait is also
			// composed into non-controller classes. Mirrors error_response()'s
			// hint enrichment so the response is identical to a genuine not-found.
			$error = new WP_Error( 'not_found', $message, array( 'status' => 404 ) );
			if ( class_exists( 'Mcpwp_Error_Hints' ) ) {
				$error = Mcpwp_Error_Hints::enhance_error( $error );
			}

			return $error;
		}

		$data = array( 'status' => 404 );
		if ( '' !== $hint ) {
			$data['hint'] = $hint;
		}

		return new WP_Error( 'not_found', $message, $data );
	}

	/**
	 * Whether the current caller may read data in $category — the site-wide
	 * disabled-category toggle (blocks every key, including admin) and the
	 * per-key role restriction (empty for admin/legacy/no-key-record
	 * contexts, meaning unrestricted). The identical pair
	 * check_disabled_category_for_route()/check_key_category_restriction_for_route()
	 * apply to a ROUTE's classified category; this applies the same pair to
	 * an arbitrary category, for a chokepoint that a route-prefix
	 * classification can't reach — e.g. a category a response is about to
	 * embed data from (site-context archetype overrides, #1007 round 3) or
	 * the category a RESOLVED POST's own type belongs to, which is exactly
	 * what a route prefix cannot know before the post is fetched (#1020 —
	 * see category_for_post_type() and guard_post_type_category() below).
	 *
	 * Promoted here from a private method of Mcpwp_REST_Site_Info (#1007
	 * round 3) once a second, unrelated chokepoint (#1020) needed the exact
	 * same pair of checks — a single copy every caller shares rather than
	 * each writing its own.
	 *
	 * Public (not protected): #1025 needs this callable from a plain static
	 * store class (Mcpwp_Approvals, Mcpwp_Event_Store) with no instance of
	 * its own — passed as `array( $this, 'caller_may_read_category' )` from
	 * the REST controller that has the request context, exactly the same
	 * "caller decides, static store applies the verdict" split
	 * redact_source_for_unprivileged()'s own docblock already documents for
	 * scope. Widening this from protected to public changes no behavior:
	 * every existing call site is still `$this->caller_may_read_category(...)`
	 * from inside a class composing this trait.
	 *
	 * @param string $category Tool category slug.
	 * @return bool
	 */
	public function caller_may_read_category( $category ) {
		$disabled_categories = get_option( 'mcpwp_disabled_tool_categories', array() );
		if ( is_array( $disabled_categories ) && in_array( $category, $disabled_categories, true ) ) {
			return false;
		}

		$key_record = $this->get_current_api_key_record();
		if ( ! is_array( $key_record ) ) {
			return true;
		}

		return $this->key_allows_category( $key_record, $category );
	}

	/**
	 * Single chokepoint (#1020): map a RESOLVED post's post_type to the tool
	 * category that governs it.
	 *
	 * Every generic, id-based door that resolves an arbitrary post by
	 * numeric ID — /post-meta (read + write, and the wp_get_post_meta /
	 * wp_set_post_meta tools, which dispatch to that same route), /content
	 * (list + delete), /fetch, /posts (including a caller-supplied
	 * ?post_type= override) and /posts/{id}/featured-image — used to be
	 * gated ONLY by its ROUTE PREFIX, via get_category_for_route(): always
	 * 'content' for every one of those. That answers "what kind of route is
	 * this", not "what kind of DATA did it just resolve" — so an author key
	 * whose role carries only the 'content' category, on a site with
	 * 'woocommerce' and 'elementor-templates' both disabled, could still
	 * read a WooCommerce product's _sku/_regular_price, or an Elementor
	 * archetype's `_mcpwp_archetype_brief`, straight through
	 * GET /post-meta/{id} — a route that looked like a content route even
	 * though the post it resolved did not belong to the content domain at
	 * all (#1020, found in the round-4 adversarial review of #1013/#1007).
	 *
	 * Callers combine this with caller_may_read_category() via
	 * guard_post_type_category() (existence-sensitive, single-post doors) or
	 * guard_post_type_category_for_type() (list-style doors where the
	 * caller names the post type up front) rather than calling either
	 * directly, so the "which gate shape" decision lives in one place too.
	 *
	 * Static (uses no instance state) so Mcpwp_Destructive_Gate::replay()
	 * — a plain static class with no REST/auth context — can also call it
	 * (as Mcpwp_REST_API::category_for_post_type(), since a trait's static
	 * method is only reachable through a composing class) to name a
	 * disabled category to the wp-admin principal applying a queued
	 * destructive operation (#1022 round-2 review NIT), rather than every
	 * existing `$this->category_for_post_type(...)` call site needing to
	 * change — PHP resolves a static method called via `->` exactly like
	 * `::`.
	 *
	 * @param string $post_type Resolved post's post_type (or a caller-named
	 *                           one for a list-style door).
	 * @return string Category slug. Falls back to 'admin' for a private,
	 *                unmapped type — see the fallback comment below for why
	 *                that is the safe default rather than 'content'.
	 */
	public static function category_for_post_type( $post_type ) {
		$post_type = (string) $post_type;
		$map       = self::known_post_type_categories();

		if ( isset( $map[ $post_type ] ) ) {
			return $map[ $post_type ];
		}

		// Unmapped type: a third-party plugin's CPT this list has never heard
		// of, or one this plugin already treats as sensitive elsewhere. Give
		// it the 'content' home ONLY when it is a type an unprivileged reader
		// could already see some other way on this very site — publicly
		// queryable (public) or exposed through the REST API in its own right
		// (show_in_rest). A type that is neither of those is private by the
		// site's own declaration (working storage, an addon's internal
		// control records, a form-entry CPT), so it gets no category home at
		// all here: only a key with NO category restriction (admin role, or a
		// custom role explicitly granted 'admin' — see
		// resolve_key_tool_categories()) reaches it, matching how every other
		// admin-only surface in this plugin (e.g. /users) is gated.
		$object = post_type_exists( $post_type ) ? get_post_type_object( $post_type ) : null;
		if ( $object && ( ! empty( $object->public ) || ! empty( $object->show_in_rest ) ) ) {
			return 'content';
		}

		return 'admin';
	}

	/**
	 * The explicit post type → category map category_for_post_type() reads
	 * first. It holds whether or not the type is registered right now (its
	 * plugin may be inactive, or `init` may not have run), which is what lets
	 * Mcpwp_Approvals::derive_visibility() resolve a known type confidently
	 * without registration (#1042 review round 4).
	 *
	 * @return array<string,string>
	 */
	public static function known_post_type_categories() {
		// Ordered, explicit map for every type a domain plugin this codebase
		// already integrates with can put in wp_posts. Add a new entry here,
		// not a special case at each door — that is the whole point of this
		// being one chokepoint.
		return array(
			'post'              => 'content',
			'page'              => 'content',
			// Media. attachment is PUBLIC (get_post_type_object('attachment')
			// ->public is true), so the generic fallback below would
			// otherwise bucket it as 'content' — the same "core CPTs that
			// belong to a different domain" reasoning nav_menu_item/
			// wp_navigation/etc. already get below, just not yet written
			// down for this one. #1025's own additive post-type check
			// (Mcpwp_Approvals::compute_visibility_categories()) is what
			// surfaced the gap: a destructive attachment delete queued
			// through /posts/{id} or /media/{id} would otherwise require
			// 'content' in addition to the door's real 'media' category,
			// wrongly over-restricting a media-only key. The dedicated
			// /media/* routes already require 'media'; this just makes the
			// post-type map agree with them.
			'attachment'        => 'media',
			// WooCommerce (#1020's own PoC: a product's _sku/_regular_price
			// leaking through /post-meta). product_variation and the order/
			// coupon/refund types share the same customer- and business-data
			// sensitivity, so all five get the one category the dedicated
			// /woocommerce/* routes already require.
			'product'           => 'woocommerce',
			'product_variation' => 'woocommerce',
			'shop_order'        => 'woocommerce',
			'shop_order_refund' => 'woocommerce',
			'shop_coupon'       => 'woocommerce',
			// Elementor. elementor_library is the CPT behind Pro's saved
			// templates/archetypes/reusable parts — gated 'elementor-templates'
			// at their own routes (get_route_category_map() above); an
			// elementor_library post used as a Theme Builder condition set is
			// classified the same way here (a coarser call than the route
			// map's own /theme-builder => 'elementor-theme' split, accepted
			// because the CPT itself, not the feature built on top of it, is
			// what a post-type map can see). elementor_snippet is Elementor
			// Pro Custom Code, gated 'elementor-theme' at /elementor/custom-code.
			'elementor_library' => 'elementor-templates',
			'elementor_snippet'  => 'elementor-theme',
			// LearnPress (#1022 round-1 review, WARN-3) — the same 'learnpress'
			// category the /learnpress/* routes already require.
			'lp_course'         => 'learnpress',
			'lp_lesson'         => 'learnpress',
			'lp_quiz'           => 'learnpress',
			'lp_question'       => 'learnpress',
			'lp_order'          => 'learnpress',
			// The Events Calendar — the 'events' category ("Events-plugin
			// listings and CRUD", get_category_meta()). Distinct from the
			// REST '/events' prefix, which is MCPWP's own webhook/event-store
			// surface classified 'webhooks' — see get_route_category_map()'s
			// docblock note on that split.
			'tribe_events'      => 'events',
			'tribe_venue'       => 'events',
			'tribe_organizer'   => 'events',
			// Elementor Pro's Floating Buttons widget/template.
			'e-floating-buttons' => 'elementor-theme',
			// Core CPTs that ARE public/show_in_rest (so the generic fallback
			// below would otherwise bucket them as 'content') but belong to a
			// different domain than ordinary post/page content:
			// - nav_menu_item backs the CLASSIC menu system /menus/* already
			//   requires 'site' for.
			// - wp_navigation/wp_template/wp_template_part are block-editor
			//   (FSE) data — literally sequences of blocks — closer to
			//   Gutenberg's own domain than to 'content'; no MCPWP tool owns
			//   them yet, but 'gutenberg' is the closer-fitting existing
			//   category of the two candidates.
			// - wp_global_styles (FSE theme.json overrides) is site-wide
			//   presentation, the same bucket as /theme, /theme-builder,
			//   /custom-css.
			'nav_menu_item'      => 'site',
			// wp_block (reusable blocks / synced patterns) is a block
			// sequence the /blocks door and wp_list_block_patterns manage;
			// without this it fell back to 'content' and a designer key
			// (gutenberg, no content) got a 404 on its own blocks (#1030
			// round-5 WARN-3).
			'wp_block'           => 'gutenberg',
			'wp_navigation'      => 'gutenberg',
			'wp_template'        => 'gutenberg',
			'wp_template_part'   => 'gutenberg',
			'wp_global_styles'   => 'site',
		);
	}

	/**
	 * Post-type category guard for a door that resolves ONE specific post by
	 * ID (#1020) — /post-meta, /fetch, /content/{type}/{id}, GET /posts/{id},
	 * POST /posts/{id}/featured-image.
	 *
	 * Denial returns the door's own not-found shape, never a distinct
	 * "forbidden category" error: the existence of a specific post is the
	 * sensitive fact on these doors (is there a product with this ID? an
	 * Elementor archetype?), so a denial here must be indistinguishable from
	 * the post genuinely not existing — the same existence-oracle concern
	 * guard_non_public_post() already protects against for status, applied
	 * here to category. Callers pass their surface's own not-found message
	 * so the parity is byte-for-byte, exactly as guard_non_public_post()
	 * does.
	 *
	 * @param WP_Post|mixed $post          Resolved post object.
	 * @param string        $not_found_msg Surface-specific "not found" message.
	 * @return WP_Error|null WP_Error(404) when blocked, null when allowed.
	 */
	protected function guard_post_type_category( $post, $not_found_msg ) {
		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		$category = $this->category_for_post_type( $post->post_type );
		if ( $this->caller_may_read_category( $category ) ) {
			return null;
		}

		return $this->build_not_found_error( $not_found_msg );
	}

	/**
	 * Post-type category guard for a LIST-style door where the caller names
	 * the post type up front, as an explicit parameter, rather than this
	 * guard resolving one post's existence (#1020) — GET /content
	 * (?post_type=), GET /posts (?post_type=).
	 *
	 * Returns the same 403 forbidden_category shape
	 * check_key_category_restriction_for_route() already uses for a denied
	 * ROUTE: naming a whole post type is not an existence oracle the way a
	 * single numeric ID is (the caller supplied the type; nothing about a
	 * particular record is disclosed either way), and #1020's own PoC
	 * control case (GET /elementor/archetypes → 403) is exactly this shape.
	 *
	 * @param string $post_type Caller-named post type.
	 * @return WP_Error|null WP_Error(403) when blocked, null when allowed.
	 */
	protected function guard_post_type_category_for_type( $post_type ) {
		$category = $this->category_for_post_type( $post_type );
		if ( $this->caller_may_read_category( $category ) ) {
			return null;
		}

		$key_record = $this->get_current_api_key_record();
		$role       = ( is_array( $key_record ) && isset( $key_record['role'] ) ) ? self::describe_role_for_message( (string) $key_record['role'] ) : 'unknown';

		return new WP_Error(
			'forbidden_category',
			sprintf(
				/* translators: 1: post type, 2: tool category, 3: role name */
				__( 'Post type "%1$s" belongs to the "%2$s" tool category, which this API key (role: %3$s) does not have access to, or which has been disabled by the site administrator.', 'mcpwp' ),
				$post_type,
				$category,
				$role
			),
			array(
				'status'   => 403,
				'category' => $category,
			)
		);
	}

	/**
	 * Verify API key from request.
	 *
	 * Accepts both current mcpwp_ keys (from mcpwp_api_keys / mcpwp_api_key)
	 * and legacy spai_ keys (from spai_api_keys / spai_api_key) so that
	 * existing AI connections continue to work through the 2.8.x → v3 cutover
	 * without requiring customers to re-key.  See find_legacy_spai_key().
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error True if valid, error otherwise.
	 */
	public function verify_api_key( $request ) {
		// WordPress core re-runs every handler's permission_callback on the
		// matched route to build the Allow header (rest_send_allow_header on
		// rest_post_dispatch). /mcp has three handlers, so this ran three
		// times per request and charged three rate-limit slots — the
		// advertised 1000/hour was really ~333 (#802). The verdict for one
		// request object is computed once and reused.
		if ( is_object( $request ) ) {
			$memo_id = spl_object_id( $request );
			if ( isset( self::$mcpwp_verdicts[ $memo_id ] ) && self::$mcpwp_verdicts[ $memo_id ][0] === $request ) {
				// The verdict's side effects travel with it: a hit on another
				// controller instance must see the same principal and the
				// same metered bucket, or its scope checks and nested
				// charging silently switch off (#802 round 4).
				$this->current_api_key_record = self::$mcpwp_verdicts[ $memo_id ][2];
				$this->mcpwp_metered          = self::$mcpwp_verdicts[ $memo_id ][3];
				$this->mcpwp_audit_key_id = self::$mcpwp_verdicts[ $memo_id ][4] ?? '';
				$this->maybe_enter_guardrails_context( self::$mcpwp_verdicts[ $memo_id ][1] );
				if ( class_exists( 'Mcpwp_WooCommerce_Audit' ) ) {
					Mcpwp_WooCommerce_Audit::auth_result( $request, self::$mcpwp_verdicts[ $memo_id ][1], $this->mcpwp_audit_key_id );
				}
				return self::$mcpwp_verdicts[ $memo_id ][1];
			}

			$verdict = $this->compute_api_key_verdict( $request );
			if ( class_exists( 'Mcpwp_WooCommerce_Audit' ) ) {
				Mcpwp_WooCommerce_Audit::auth_result( $request, $verdict, $this->mcpwp_audit_key_id );
			}

			// Holding the request keeps its object id from being reused for
			// another request within this process. Bounded: a long-lived
			// worker looping rest_do_request() must not retain every body.
			if ( count( self::$mcpwp_verdicts ) >= 64 ) {
				// Not array_shift(): it renumbers the object-id keys.
				unset( self::$mcpwp_verdicts[ array_key_first( self::$mcpwp_verdicts ) ] );
			}
			self::$mcpwp_verdicts[ $memo_id ] = array( $request, $verdict, $this->current_api_key_record, $this->mcpwp_metered, $this->mcpwp_audit_key_id );

			$this->maybe_enter_guardrails_context( $verdict );
			return $verdict;
		}

		$verdict = $this->compute_api_key_verdict( $request );
		$this->maybe_enter_guardrails_context( $verdict );
		return $verdict;
	}

	/**
	 * Mark the rest of this request as MCPWP-authenticated for the content
	 * guardrails module (#957 adversarial review design fix) the moment a
	 * verdict resolves to true — whichever of compute_api_key_verdict()'s
	 * several `return true;` points produced it (the trusted-admin-ajax
	 * Chat-tab bypass, the destructive-gate admin-replay bypass, or the
	 * ordinary end-of-checks success) and regardless of whether this call
	 * hit the per-request verdict cache above or computed fresh. Every one
	 * of those IS a genuine MCPWP tool call executing, even when triggered
	 * from wp-admin (the Chat tab literally dispatches tools/call) — the
	 * cases that must NOT set this (a plain wp-admin post edit, WP-CLI,
	 * another plugin's save) never call verify_api_key() at all, so they
	 * are excluded by construction, not by an extra check here.
	 *
	 * @param bool|WP_Error $verdict This call's result.
	 * @return void
	 */
	private function maybe_enter_guardrails_context( $verdict ) {
		if ( true === $verdict && class_exists( 'Mcpwp_Content_Guardrails' ) ) {
			Mcpwp_Content_Guardrails::enter_context();
		}
	}

	/**
	 * Memoised verdicts, keyed by request object id.
	 *
	 * @var array<int, array{0:object,1:mixed,2:?array,3:?array,4:string}>
	 */
	private static $mcpwp_verdicts = array();

	/**
	 * Forget memoised verdicts. Tests only; a real process serves one request.
	 */
	public static function reset_verdict_memo() {
		self::$mcpwp_verdicts = array();
	}

	/**
	 * Diagnostic bucket ceilings (#802): small and absolute. Diagnostics stay
	 * reachable while the write budget is exhausted, and cannot be used to
	 * double a key's throughput.
	 *
	 * @return array
	 */
	protected function diagnostic_rate_limits( $key_record = null ) {
		$limits = array(
			'burst'      => 15,
			'per_minute' => 30,
			'per_hour'   => 300,
		);

		// The allowance is a floor for exhausted keys, never a lift above a
		// deliberate throttle — the key's own ceilings, or the site's.
		$ceilings = array();
		if ( class_exists( 'Mcpwp_Rate_Limiter' ) ) {
			$site     = Mcpwp_Rate_Limiter::get_instance()->get_settings();
			$ceilings = array(
				'burst'      => isset( $site['burst_limit'] ) ? (int) $site['burst_limit'] : 0,
				'per_minute' => isset( $site['requests_per_minute'] ) ? (int) $site['requests_per_minute'] : 0,
				'per_hour'   => isset( $site['requests_per_hour'] ) ? (int) $site['requests_per_hour'] : 0,
			);
		}
		if ( is_array( $key_record ) && ! empty( $key_record['rate_limits'] ) && is_array( $key_record['rate_limits'] ) ) {
			foreach ( $key_record['rate_limits'] as $name => $value ) {
				if ( (int) $value > 0 ) {
					$ceilings[ $name ] = isset( $ceilings[ $name ] ) && $ceilings[ $name ] > 0 ? min( $ceilings[ $name ], (int) $value ) : (int) $value;
				}
			}
		}
		foreach ( $limits as $name => $value ) {
			if ( ! empty( $ceilings[ $name ] ) ) {
				$limits[ $name ] = min( $value, (int) $ceilings[ $name ] );
			}
		}

		return array( 'rate_limits' => $limits );
	}

	/**
	 * Identifier, method and key record of the last meter_request() call.
	 *
	 * @var array|null
	 */
	private $mcpwp_metered = null;

	/**
	 * Charge slots on top of the one the HTTP request already paid (#802).
	 *
	 * @param int $slots How many more.
	 * @return true|WP_Error
	 */
	protected function charge_additional_slots( $slots ) {
		$slots = (int) $slots;

		if ( $slots < 1 ) {
			return true;
		}

		if ( ! is_array( $this->mcpwp_metered ) ) {
			// Never metered (internal dispatch, admin chat): nothing to charge against.
			return true;
		}

		list( $identifier, $method, $key_record ) = $this->mcpwp_metered;

		return $this->check_rate_limit( $identifier, $method, $key_record, $slots );
	}

	/**
	 * Meter one authenticated request (#802).
	 *
	 * The single place that decides which bucket a request is charged to and
	 * how much. Every authentication path — API key, OAuth token, broker
	 * token — goes through here so they cannot drift.
	 *
	 * @param WP_REST_Request $request     Request.
	 * @param string          $identifier  Caller's bucket identifier.
	 * @param string          $http_method HTTP method.
	 * @param array|null      $key_record  Key record with optional per-key ceilings.
	 * @return bool|WP_Error
	 */
	protected function meter_request( $request, $identifier, $http_method = 'POST', $key_record = null ) {
		$weight = $this->request_rate_weight( $request );

		// Remembered so a tool that turns out to carry N operations can be
		// charged the other N-1 before it runs (charge_additional_slots).
		$this->mcpwp_metered = array( $identifier, $http_method, $key_record );
		if ( isset( $key_record['id'] ) ) {
			$this->mcpwp_audit_key_id = (string) $key_record['id'];
		}

		if ( ! $this->is_diagnostic_request( $request ) ) {
			return $this->check_rate_limit( $identifier, $http_method, $key_record, $weight );
		}

		$check = $this->check_rate_limit( $identifier . ':diag', 'POST', $this->diagnostic_rate_limits( $key_record ), $weight );

		// The budget the caller is asking about is the write budget, not the
		// diagnostic slot just spent. Report that one.
		if ( ! is_wp_error( $check ) && class_exists( 'Mcpwp_Rate_Limiter' ) ) {
			Mcpwp_Rate_Limiter::get_instance()->report_bucket( $identifier, $http_method, $key_record );
		}

		return $check;
	}

	/**
	 * The actual verification. See verify_api_key() for why it is wrapped.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	private function compute_api_key_verdict( $request ) {
		$this->mcpwp_audit_key_id = '';
		// A persistent process (WP-CLI, a rest_do_request() loop) reuses this
		// instance across requests; a nested charge must never land on the
		// previous request's bucket.
		$this->mcpwp_metered = null;

		// WebMCP in wp-admin (#981): an in-process /mcp dispatch made by
		// Mcpwp_REST_WebMCP for the logged-in WordPress user, and every
		// sub-request it fans out to. The lift is a PHP static that only
		// that controller sets, after it has verified the cookie, the
		// wp_rest nonce, the origin and the user's capabilities; nothing a
		// caller sends can set it. Checked first: while it is held, every
		// MCPWP request in this process is that user and nobody else.
		if ( class_exists( 'Mcpwp_WebMCP' ) && Mcpwp_WebMCP::dispatch_user_id() > 0 ) {
			return $this->authenticate_webmcp_principal( $request );
		}

		// Bypass for internal server-side requests (Chat tab tool execution).
		// Requires: admin context + activate_plugins cap + AJAX request +
		// a valid nonce tied to a known admin action (prevents filter-hook abuse).
		// The open apply_filters() bypass was removed: any code that added a filter
		// returning true could silently skip API-key auth for all requests.
		if ( $this->is_trusted_admin_ajax_request() ) {
			$this->mcpwp_audit_key_id = 'wp-user:' . get_current_user_id();
			// A user-run principal (the logged-in administrator): the route's
			// capability and the post-type rule still apply (#1100).
			$capability_check = $this->check_gate_entry( $request );
			return is_wp_error( $capability_check ) ? $capability_check : $this->check_generic_post_type_access( $request );
		}

		// An approved destructive operation being applied by an administrator
		// from wp-admin re-dispatches its route in-process. The lift is a PHP
		// static set by the gate for that one dispatch and carries the admin
		// user id; nothing a caller sends can set it.
		//
		// #1032 round 4 (adversarial review): this bypass never reaches the
		// template-ref scan below, so it would be a sixth verdict-path gap
		// exactly like the four round-3 closed — EXCEPT that
		// Mcpwp_Destructive_Gate::GATED (the only operations ever replayed
		// through this lift) is, and must stay, delete_* operations only
		// (delete_page, delete_post, delete_content, delete_all_drafts,
		// delete_media, delete_product, delete_course_category,
		// delete_widget, delete_elementor_template, delete_user) — nothing
		// that writes _elementor_data/post_content, so there is no payload
		// here a template reference could hide in. The Elementor
		// content-replacement tools that WOULD need this scan
		// (wp_apply_elementor_template/archetype/part) are deliberately kept
		// OUT of GATED (see EXEMPT_WITH_REASON) precisely because they use
		// the action-log snapshot/rollback path instead, which the writer
		// traits' own check_template_references() already covers. If a
		// future GATED entry is ever a write rather than a delete, this
		// bypass becomes exactly the gap round 3 fixed for every other
		// path — DestructiveGateOnlyDeletesTest pins the invariant.
		if ( class_exists( 'Mcpwp_Destructive_Gate' ) && Mcpwp_Destructive_Gate::applying_as_admin() > 0 && function_exists( 'user_can' ) && user_can( Mcpwp_Destructive_Gate::applying_as_admin(), 'activate_plugins' ) ) {
			wp_set_current_user( Mcpwp_Destructive_Gate::applying_as_admin() );
			$this->mcpwp_audit_key_id = 'wp-user:' . get_current_user_id();
			$capability_check = $this->check_gate_entry( $request );
			return is_wp_error( $capability_check ) ? $capability_check : $this->check_generic_post_type_access( $request );
		}

		// Skip rate limiting for batch sub-requests (already counted on the outer request).
		// Use a PHP static property — not an HTTP header — so external callers cannot
		// forge this bypass.  The flag is set/cleared by Mcpwp_REST_Batch::execute_operation()
		// and by Mcpwp_REST_MCP around its own internal sub-request dispatch.
		if ( class_exists( 'Mcpwp_REST_Batch' ) && Mcpwp_REST_Batch::$is_batch_sub_request ) {
			return $this->verify_api_key_auth_only( $request );
		}

		// The real HTTP method, not rate_limit_method() — every pre-auth bucket
		// below (missing:, unconfigured:/invalid:) is keyed on the client IP
		// alone, reachable by anyone who can send a request at all, with or
		// without a real credential. rate_limit_method() for /mcp parses the
		// JSON-RPC body to ask "does every operation name a known GET-mapped
		// tool", which an unauthenticated caller controls completely — an
		// attacker-chosen body would buy the 2x read ceiling on these
		// anonymous-abuse buckets, and building the tool map to answer that
		// question runs get_all_tool_map() (with its
		// apply_filters('mcpwp_integrations')) before any credential has been
		// checked at all (#892 adversarial review). rate_limit_method() is
		// reserved for the authenticated meter_request() calls below, once a
		// real key/token is already known to be valid.
		$http_method = method_exists( $request, 'get_method' ) ? $request->get_method() : 'POST';
		$api_key     = $this->get_api_key_from_request( $request );

		if ( empty( $api_key ) ) {
			$rate_limit_check = $this->check_rate_limit( 'missing:' . $this->get_client_ip(), $http_method );
			if ( is_wp_error( $rate_limit_check ) ) {
				if ( class_exists( 'Mcpwp_Error_Hints' ) ) {
					Mcpwp_Error_Hints::enhance_error( $rate_limit_check );
				}
				return $rate_limit_check;
			}

			return new WP_Error(
				'missing_api_key',
				__( 'API key is required.', 'mcpwp' ),
				array(
					'status' => 401,
					'hint'   => 'Include your API key in the X-API-Key header or Authorization: Bearer header. Generate keys in WP Admin > MCPWP > Setup, or call wp_create_api_key if you have admin access.',
				)
			);
		}

		if ( $this->looks_like_oauth_access_token( $api_key ) ) {
			return $this->authenticate_oauth_access_token( $api_key, $request );
		}

		$matched_key = $this->find_scoped_api_key( $api_key );
		$legacy_key  = get_option( 'mcpwp_api_key' );

		if ( ! $matched_key && ! empty( $legacy_key ) && $this->legacy_api_key_option_is_honoured() && $this->is_api_key_match( $api_key, $legacy_key ) ) {
			// Auto-migrate legacy plain text keys to hashed storage.
			if ( hash_equals( $legacy_key, $api_key ) ) {
				$legacy_key = wp_hash_password( $api_key );
				update_option( 'mcpwp_api_key', $legacy_key );
			}

			$matched_key = $this->migrate_legacy_key_to_scoped_store( $legacy_key );
		}

		// Bridge: also accept keys stored under the old spai_* option names.
		// This allows customers on 2.8.x to keep their existing API keys working
		// through the folder-rename cutover without re-generating keys.
		// find_legacy_spai_key() is constant-time / hashed-compare identical to
		// find_scoped_api_key().
		if ( ! $matched_key ) {
			$matched_key = $this->find_legacy_spai_key( $api_key );
		}

		if ( ! $matched_key ) {
			// mumcp.com broker OAuth Bearer token — FALLBACK only, reached ONLY
			// after no local API key (scoped, legacy mcpwp_, or bridged spai_)
			// matched the presented credential. Ordering matters for two reasons:
			//   1. Secret egress: the plugin also accepts API keys on the
			//      Authorization: Bearer header (get_api_key_from_request), so a
			//      real API key must be resolved locally above and NEVER shipped
			//      to mumcp.com as an introspection token.
			//   2. Correctness: a valid local key must authenticate, not be
			//      rejected because introspection intercepted it first.
			// Only ever attempted for a genuine Authorization: Bearer header with
			// no X-API-Key header (never the ?api_key= query fallback), and only
			// when the site is actively enrolled — the kill-switch inside
			// Mcpwp_Broker_Auth::is_enrolled() means an unenrolled/disabled site
			// makes NO network call and falls straight through to the unchanged
			// invalid-key handling below.
			$auth_header        = method_exists( $request, 'get_header' ) ? $request->get_header( 'Authorization' ) : null;
			$has_bearer_header  = ! empty( $auth_header ) && 0 === strpos( (string) $auth_header, 'Bearer ' );
			$has_api_key_header = method_exists( $request, 'get_header' ) && ! empty( $request->get_header( 'X-API-Key' ) );

			if ( $has_bearer_header && ! $has_api_key_header && class_exists( 'Mcpwp_Broker_Auth' ) ) {
				$broker_auth = new Mcpwp_Broker_Auth();
				if ( $broker_auth->is_enrolled() ) {
					return $this->authenticate_broker_bearer_token( $api_key, $request, $broker_auth );
				}
			}

			$has_configured_keys = $this->has_configured_api_keys( $legacy_key );
			$rate_identifier     = $has_configured_keys ? 'invalid:' . $this->get_client_ip() : 'unconfigured:' . $this->get_client_ip();
			$rate_limit_check    = $this->check_rate_limit( $rate_identifier, $http_method );

			if ( is_wp_error( $rate_limit_check ) ) {
				return $rate_limit_check;
			}

			if ( ! $has_configured_keys ) {
				return new WP_Error(
					'api_not_configured',
					__( 'API key not configured. Please visit the MCPWP settings.', 'mcpwp' ),
					array(
						'status' => 500,
						'hint'   => 'No API keys have been configured yet. The site admin needs to visit WP Admin > MCPWP > Setup to generate an API key.',
					)
				);
			}

			$this->log_auth_failure( $request );

			return new WP_Error(
				'invalid_api_key',
				__( 'Invalid API key.', 'mcpwp' ),
				array(
					'status' => 401,
					'hint'   => self::invalid_api_key_hint(),
				)
			);
		}

		$rate_identifier = ! empty( $matched_key['id'] )
			? 'key:' . sanitize_key( $matched_key['id'] )
			: 'key:' . hash( 'sha256', $api_key );

		// rate_limit_method(), not $http_method, ONLY here: $matched_key is a
		// real, validated key by this point, so this bucket is not reachable
		// by an anonymous caller shaping a body (#892).
		$rate_limit_check = $this->meter_request( $request, $rate_identifier, $this->rate_limit_method( $request ), $matched_key );
		if ( is_wp_error( $rate_limit_check ) ) {
			return $rate_limit_check;
		}

		$this->mcpwp_audit_key_id = isset( $matched_key['id'] ) ? (string) $matched_key['id'] : '';
		$required_scope = $this->get_required_scope_for_request( $request );
		if ( ! $this->key_has_scope( $matched_key, $required_scope ) && ! $this->mcp_scope_refusal_is_per_call( $request, $matched_key ) ) {
			return $this->build_insufficient_scope_error( $matched_key, $required_scope, $this->route_category_for_refusal( $request ) );
		}

		// Store the matched key for downstream use (MCP tool filtering, etc.).
		$this->current_api_key_record = $matched_key;

		if ( ! empty( $matched_key['id'] ) ) {
			$this->touch_api_key_last_used( $matched_key['id'] );
		}

		if ( ! $this->set_api_user_context() ) {
			return new WP_Error(
				'api_user_missing',
				__( 'API user context is not configured. Re-activate MCPWP to provision the service account.', 'mcpwp' ),
				array(
					'status' => 500,
					'hint'   => 'The plugin\'s internal service account is missing. Deactivate and reactivate the MCPWP plugin in WP Admin > Plugins to reprovision it.',
				)
			);
		}

		// Block direct REST calls to routes whose tool category has been disabled
		// by the site administrator via WP Admin > mcpwp > Tools.
		// This mirrors the MCP-layer check in Mcpwp_REST_MCP::handle_tools_call()
		// so that callers cannot bypass the category toggle by hitting the raw
		// REST endpoint instead of going through the MCP protocol.
		$category_check = $this->check_disabled_category_for_route( $request );
		if ( is_wp_error( $category_check ) ) {
			return $category_check;
		}

		// Per-key role/category restriction (#874) — see
		// check_key_category_restriction_for_route() docblock for why this is a
		// separate check from the one immediately above.
		$key_category_check = $this->check_key_category_restriction_for_route( $request );
		if ( is_wp_error( $key_category_check ) ) {
			return $key_category_check;
		}

		// check_post_naming_params_category() also runs the #1032 round-2/3
		// request-body-wide template-ref scan (check_request_template_refs())
		// once the declared-param check clears — see its own docblock. Every
		// verdict path in this trait ends by returning this call directly,
		// so the scan runs uniformly regardless of identity type.
		return $this->check_post_naming_params_category( $request );
	}

	/**
	 * Authenticate a request made under the WebMCP principal lift (#981).
	 *
	 * The principal is the logged-in WordPress user, identified by a
	 * synthetic key record (Mcpwp_WebMCP::principal_record(): read + write,
	 * never admin; role 'custom' limited to the WebMCP categories). Unlike
	 * an API key, the current user is NOT switched to the mcpwp_bot service
	 * account — tool handlers run as the real user, so WordPress's own
	 * capability checks and post authorship apply to them.
	 *
	 * Every gate an API key passes still runs: rate limit (on the outer
	 * /mcp request, bucket webmcp:user:<id>), scope for the route/tool,
	 * site-disabled categories, the principal's own category restriction
	 * and post-ref category checks. The outer /mcp body must additionally
	 * be nothing but tools/call for allowlisted tools.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	private function authenticate_webmcp_principal( $request ) {
		$user_id = Mcpwp_WebMCP::dispatch_user_id();

		// The lift names the user the endpoint verified; it must still be
		// the user this request runs as, and that user must still qualify.
		if ( get_current_user_id() !== $user_id || ! Mcpwp_WebMCP::user_may_use( $user_id ) ) {
			return new WP_Error(
				'webmcp_principal_mismatch',
				__( 'WebMCP request is not running as the signed-in user.', 'mcpwp' ),
				array( 'status' => 401 )
			);
		}

		$is_sub_request = class_exists( 'Mcpwp_REST_Batch' ) && Mcpwp_REST_Batch::$is_batch_sub_request;
		$route          = method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';

		// The only top-level request the lift may authenticate is the /mcp
		// tools/call envelope the WebMCP endpoint built; everything else runs
		// as a sub-request of it (Mcpwp_REST_Batch::$is_batch_sub_request).
		if ( ! $is_sub_request ) {
			$body = method_exists( $request, 'get_json_params' ) ? $request->get_json_params() : null;
			if ( '/mcpwp/v1/mcp' !== strtolower( untrailingslashit( $route ) ) || ! Mcpwp_WebMCP::mcp_body_is_allowed( $body ) ) {
				return new WP_Error(
					'webmcp_tool_not_allowed',
					__( 'This tool is not available through WebMCP.', 'mcpwp' ),
					array( 'status' => 403 )
				);
			}
		}

		$record = Mcpwp_WebMCP::principal_record( $user_id );
		$this->mcpwp_audit_key_id = isset( $record['id'] ) ? (string) $record['id'] : '';

		// Charged once, on the outer request — sub-requests were already
		// counted there, same rule as API keys.
		if ( ! $is_sub_request ) {
			$rate_limit_check = $this->meter_request( $request, 'webmcp:user:' . $user_id, $this->rate_limit_method( $request ), $record );
			if ( is_wp_error( $rate_limit_check ) ) {
				return $rate_limit_check;
			}
		}

		$required_scope = $this->get_required_scope_for_request( $request );
		if ( ! $this->key_has_scope( $record, $required_scope ) ) {
			return new WP_Error(
				'insufficient_scope',
				sprintf(
					/* translators: %s: scope name */
					__( 'WebMCP cannot perform this action (requires %s scope). Publishing, settings and other administrator actions are done by a person in wp-admin.', 'mcpwp' ),
					$required_scope
				),
				array(
					'status'         => 403,
					'required_scope' => $required_scope,
				)
			);
		}

		$this->current_api_key_record = $record;

		// WordPress's own per-user rules on the canonical arguments the
		// handler will read (post type caps, terms, meta, draft status).
		if ( $is_sub_request ) {
			$wp_check = Mcpwp_WebMCP::check_sub_request( $request );
			if ( is_wp_error( $wp_check ) ) {
				return $wp_check;
			}
		}

		$category_check = $this->check_disabled_category_for_route( $request );
		if ( is_wp_error( $category_check ) ) {
			return $category_check;
		}

		$key_category_check = $this->check_key_category_restriction_for_route( $request );
		if ( is_wp_error( $key_category_check ) ) {
			return $key_category_check;
		}

		return $this->check_post_naming_params_category( $request );
	}

	/**
	 * Single source of truth for the discovery allowlist: tool name => {route,
	 * read_only} (#1007 owner decision, 2026-09-27, hardened by adversarial
	 * review rounds 1-2). Every key, of any role, and even while the site
	 * administrator has disabled the 'site' category outright, must still be
	 * able to make first contact with a site:
	 *
	 *  - wp_site_info  / '/site-info'  — capabilities probe every client calls
	 *    before anything else (KeyCategoryRestrictionAuthPlaneTest,
	 *    BridgeAuthTest pin this for author/editor keys, including migrated
	 *    2.8 spai_ keys).
	 *  - wp_onboard    / '/onboard'    — the first-connection briefing; an
	 *    agent cannot follow it to a specific tool's category without having
	 *    read it first.
	 *  - wp_introspect / '/introspect' — the machine-readable API description;
	 *    same chicken-and-egg problem as onboard.
	 *  - wp_get_site_context / '/site-context' — round 2 owner decision:
	 *    site context is public brand copy, already published unauthenticated
	 *    at /llms.txt (Mcpwp_AI_Presence::generate_llms_txt() reads the same
	 *    mcpwp_site_context option), so READING it needs no gate at all.
	 *    'read_only' => true: unlike the other three entries, this route is
	 *    NOT method-agnostic — wp_set_site_context (POST/PUT /site-context,
	 *    a DIFFERENT tool name sharing the same route) is a genuine site-owner
	 *    edit and stays gated to 'site'. get_discovery_allowlist_routes()
	 *    (unconditional, any method — used by get_category_for_route()'s
	 *    method-agnostic classifier) therefore excludes read_only entries;
	 *    get_discovery_allowlist_read_only_routes() carries them, consulted
	 *    only where a request's HTTP method is known to be safe (GET/HEAD) —
	 *    see is_discovery_read_bypass().
	 *
	 * Consumed three ways, all derived from this one map so they cannot drift
	 * apart: get_discovery_allowlist_routes() (REST, any method — checked
	 * first in get_category_for_route(), before either route map, so nothing
	 * added there can ever shadow it back into being gated),
	 * get_discovery_allowlist_read_only_routes() (REST, GET/HEAD only — see
	 * is_discovery_read_bypass(), consulted by check_disabled_category_for_route()
	 * and check_key_category_restriction_for_route()), and
	 * get_discovery_allowlist_tool_names() (MCP — Mcpwp_REST_MCP's
	 * get_all_tools(), filter_tools_by_key_role(), handle_tools_call() and
	 * tool_currently_permitted() all exempt these tool NAMES from the per-key
	 * role gate and the site-wide toggle exactly the way this method's routes
	 * are exempt over REST — a tool name is not method-ambiguous the way a
	 * shared route is, since wp_get_site_context and wp_set_site_context are
	 * already two different names; the tool's own SCOPE requirement, e.g.
	 * wp_onboard's 'read', still applies — only the CATEGORY gate is
	 * bypassed).
	 *
	 * Before WARN-2, the REST allowlist above had no MCP-side counterpart:
	 * an author key's tools/list omitted wp_onboard/wp_site_info/wp_introspect
	 * and a tools/call for any of them answered scope_denied (or
	 * category_disabled once the admin disabled 'site' site-wide) — the same
	 * three tools /mcp refused that direct REST already, correctly, let
	 * through. First contact should not depend on which transport a client
	 * happens to use.
	 *
	 * The MCP JSON-RPC handshake (initialize, tools/list, ping, and
	 * notifications/*) needs no entry here: none of those methods dispatch
	 * through a REST route or a named tool at all — process_single_request()
	 * answers them directly.
	 *
	 * A method, not a trait class constant: this plugin still supports PHP
	 * 7.4 (readme.txt "Requires PHP"), and constants inside a trait are a
	 * PHP 8.1 addition — declaring one here would fail to parse on 7.4/8.0.
	 *
	 * @return array<string,array{route:string,read_only?:bool}> Tool name => entry.
	 */
	protected static function get_discovery_allowlist() {
		return array(
			'wp_site_info'        => array( 'route' => '/site-info' ),
			'wp_onboard'          => array( 'route' => '/onboard' ),
			'wp_introspect'       => array( 'route' => '/introspect' ),
			'wp_get_site_context' => array( 'route' => '/site-context', 'read_only' => true ),
		);
	}

	/**
	 * @return string[] Routes exempt from the category gate for EVERY method
	 *                   (the three entries with no write counterpart on the
	 *                   same route).
	 */
	protected static function get_discovery_allowlist_routes() {
		$routes = array();
		foreach ( self::get_discovery_allowlist() as $entry ) {
			if ( empty( $entry['read_only'] ) ) {
				$routes[] = $entry['route'];
			}
		}
		return $routes;
	}

	/**
	 * @return string[] Routes exempt from the category gate ONLY for a
	 *                   safe/read HTTP method (GET/HEAD) — the route's own
	 *                   write method stays gated. See is_discovery_read_bypass().
	 */
	protected static function get_discovery_allowlist_read_only_routes() {
		$routes = array();
		foreach ( self::get_discovery_allowlist() as $entry ) {
			if ( ! empty( $entry['read_only'] ) ) {
				$routes[] = $entry['route'];
			}
		}
		return $routes;
	}

	/**
	 * @return string[] The discovery allowlist's MCP tool names (read-only
	 *                   entries included — a tool name is never method-shared).
	 */
	protected static function get_discovery_allowlist_tool_names() {
		return array_keys( self::get_discovery_allowlist() );
	}

	/**
	 * Whether $request's route+method matches a READ-ONLY discovery-allowlist
	 * entry on a safe method (GET/HEAD) — e.g. GET /site-context, but not
	 * POST/PUT /site-context. Consulted by both real category gates
	 * (check_disabled_category_for_route(), check_key_category_restriction_for_route())
	 * in addition to the unconditional get_discovery_allowlist_routes() check
	 * already inside get_category_for_route().
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return bool
	 */
	protected function is_discovery_read_bypass( $request ) {
		$method = method_exists( $request, 'get_method' ) ? strtoupper( (string) $request->get_method() ) : 'GET';
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return false;
		}

		$route = $this->normalize_route_for_authorization( method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '' );
		$base  = '/mcpwp/v1';
		$path  = ( 0 === strpos( $route, $base ) ) ? substr( $route, strlen( $base ) ) : $route;

		foreach ( self::get_discovery_allowlist_read_only_routes() as $allowlisted ) {
			if ( $path === $allowlisted || 0 === strpos( $path, $allowlisted . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Map a REST route to the tool-category it belongs to.
	 *
	 * INVARIANT (enforced by CategoryTaxonomyInvariantTest): every category this
	 * classifier can return MUST exist in Mcpwp_Tools_Admin::get_category_meta().
	 * A category the map emits but the admin UI cannot toggle is un-gateable — the
	 * route can never be blocked by any configuration, which is a silent net-widening
	 * of the gate. Best-effort second property (NOT test-enforced): where a route IS
	 * category-gated, its category SHOULD match the MCP tool-category of the tool that
	 * dispatches to it (free + pro registries, defaulting to 'site' for a tool in
	 * neither), because MCP tools dispatch through rest_do_request() and pass through
	 * this gate — if the two taxonomies disagree the MCP layer says "callable" while
	 * this gate answers 403, indistinguishable from a broken endpoint. This alignment
	 * is intentionally partial: many routes return '' (ungated) here — e.g. /settings,
	 * /site-info, /menus, /users — and are governed only by their MCP-tool category, so
	 * a returned category matching the tool taxonomy is a goal, not a guarantee. When
	 * adding a route, add its prefix here, finer prefixes BEFORE coarser ones (first
	 * match wins, iteration is insertion order).
	 *
	 * NOTE on /events: the free event-store endpoints (GET /events, GET /events/schema)
	 * are classified 'webhooks' at the REST layer. The Pro events tools keep the 'events'
	 * MCP-tool category — a first-class toggle present in get_category_meta() that hides
	 * them from tools/list when disabled. One REST prefix cannot serve two taxonomies, so
	 * the split (REST '/events' => 'webhooks' vs the 'events' MCP-tool category) is
	 * deliberate, not drift. The Pro plugin does register its own /events and /events/{id}
	 * routes; those fall under the same '/events' prefix and are gated as 'webhooks' here.
	 *
	 * Public static (#1025): a plain static store class with no REST
	 * controller instance (Mcpwp_Approvals, classifying an approval whose
	 * target is a route rather than a resolved post — see
	 * Mcpwp_Approvals::compute_visibility_categories()) needs this classifier too.
	 * Uses no instance state (neither this method nor the two maps it
	 * reads do), so promoting it changes no behavior — every existing
	 * `$this->get_category_for_route(...)` call site keeps working
	 * unchanged, exactly as category_for_post_type()'s own promotion above
	 * documents (PHP resolves a static method called via `->` like `::`).
	 *
	 * @param string $route Full REST route, e.g. /mcpwp/v1/pages.
	 * @return string Category slug, or '' if not category-gated.
	 */
	public static function get_category_for_route( $route ) {
		$base = '/mcpwp/v1';
		$path = ( 0 === strpos( $route, $base ) ) ? substr( $route, strlen( $base ) ) : $route;

		// Discovery allowlist (#1007) — checked before every other map so
		// nothing added to get_route_category_map() or get_route_pattern_map()
		// can ever accidentally shadow it back into being gated.
		// Exact match or a '/' boundary right after — NOT a bare strpos()
		// prefix (review NIT): a naive prefix match would also swallow an
		// unrelated route that merely starts with the same characters, e.g.
		// a hypothetical '/site-information'. None of the three routes here
		// has a real sub-path today, so this is strictly more correct with
		// no behavior change for any route that actually exists.
		foreach ( self::get_discovery_allowlist_routes() as $allowlisted ) {
			if ( $path === $allowlisted || 0 === strpos( $path, $allowlisted . '/' ) ) {
				return '';
			}
		}

		// Path-parameter routes whose category cannot be expressed as a leading prefix.
		foreach ( self::get_route_pattern_map() as $pattern => $pattern_category ) {
			if ( preg_match( $pattern, $path ) ) {
				return $pattern_category;
			}
		}

		// Longest/most-specific prefixes first; first match wins.
		foreach ( self::get_route_category_map() as $prefix => $category ) {
			if ( 0 === strpos( $path, $prefix ) ) {
				return $category;
			}
		}

		return ''; // Not governed by any category toggle.
	}

	/**
	 * Regex route→category tier, checked before the prefix map. For routes whose
	 * distinguishing segment follows a path parameter (e.g. /pages/{id}/template).
	 *
	 * Public static: see get_category_for_route()'s docblock above.
	 *
	 * @return array<string,string> Regex pattern => category slug.
	 */
	public static function get_route_pattern_map() {
		return array(
			// wp_update_page_template is a 'site' tool (Mcpwp_MCP_Free_Tools), but its
			// route lives under /pages/{id}/ which the prefix map would claim as 'content'.
			// The id segment is restricted to digits so this only claims the numeric-id
			// template route (/pages/5/template). Non-numeric segments such as
			// /pages/by-slug/template are a content read and must fall through to
			// /pages => 'content' rather than being mis-gated as 'site'.
			'#^/pages/\d+/template(/|$)#' => 'site',
		);
	}

	/**
	 * Prefix route→category map. Ordered most-specific first.
	 *
	 * Public static: see get_category_for_route()'s docblock above.
	 *
	 * @return array<string,string> Route prefix => category slug.
	 */
	public static function get_route_category_map() {
		return array(
			// Elementor info (status, widget schemas). Before generic /elementor.
			'/elementor/status'                     => 'elementor-info',
			'/elementor/widgets'                    => 'elementor-info',
			'/elementor/widget-help'                => 'elementor-info',
			'/elementor/regenerate-css'             => 'elementor-info',

			// Elementor templates, archetypes, reusable parts (Pro).
			'/elementor/templates'                  => 'elementor-templates',
			'/elementor/archetypes'                 => 'elementor-templates',
			'/elementor/parts'                      => 'elementor-templates',

			// Elementor page-building helpers (Pro).
			'/elementor/blueprints'                 => 'elementor-build',
			'/elementor/build-page'                 => 'elementor-build',
			'/elementor/clone'                      => 'elementor-build',
			'/elementor/globals'                    => 'elementor-build',
			'/elementor/landing-page'               => 'elementor-build',
			'/elementor/save-section-as-template'   => 'elementor-build',

			// Elementor theme builder / custom code (Pro).
			'/elementor/custom-code'                => 'elementor-theme',

			// Elementor build (read/write page data) — catch-all; stays below every
			// finer /elementor prefix above.
			'/elementor'                            => 'elementor',

			// Block utilities absent from the MCP tool-category map (default 'site' at
			// the MCP layer). They must resolve 'site' here too, or disabling Gutenberg
			// would 403 tools that tools/list still advertises under Site.
			'/blocks/design-system'                 => 'site',
			'/blocks/parse'                         => 'site',
			'/blocks/serialize'                     => 'site',
			'/blocks/validate'                      => 'site',

			// Gutenberg blocks.
			'/blocks'                               => 'gutenberg',

			// SEO. /content-coherence MUST precede /content, which would otherwise
			// swallow it and gate an SEO tool behind the Content toggle.
			'/content-coherence'                    => 'seo',
			'/seo'                                  => 'seo',

			// Content.
			'/pages'                                => 'content',
			'/posts'                                => 'content',
			'/content'                              => 'content',
			'/drafts'                               => 'content',
			'/batch'                                => 'content',
			'/post-meta'                            => 'content',

			// Media.
			'/media'                                => 'media',
			'/screenshot'                           => 'media',

			// Taxonomy.
			'/categories'                           => 'taxonomy',
			'/tags'                                 => 'taxonomy',
			'/terms'                                => 'taxonomy',

			// Webhooks + MCP event store (see /events note in the method docblock).
			'/webhooks'                             => 'webhooks',
			'/events'                               => 'webhooks',

			// AI generation / stock-photo routes live under /integrations/*
			// but are MCP category 'ai', NOT integration management. They must resolve
			// 'ai' here, ahead of the generic /integrations => 'admin' below, so the REST
			// gate agrees with the tool taxonomy. All are still gateable ('ai' is in
			// get_category_meta()), so this realignment does not net-widen — it removes a
			// mismatch, not a control.
			'/integrations/stock-photos'            => 'ai',
			'/integrations/generate-image'          => 'ai',
			'/integrations/generate-featured-image' => 'ai',
			'/integrations/generate-alt-text'       => 'ai',
			'/integrations/describe-image'          => 'ai',
			'/integrations/generate-excerpt'        => 'ai',
			'/integrations/text-to-speech'          => 'ai',

			// Admin. Integration MANAGEMENT (status/configure/remove/test) stays 'admin';
			// settings/options are intentionally not gated (they carry the 'admin' scope).
			'/approvals'                            => 'admin',
			'/api-keys'                             => 'admin',
			'/rate-limit'                           => 'admin',
			'/integrations'                         => 'admin',
			'/feedback'                             => 'admin',
			// Cross-key audit trail: /action-log shows which API key ran which
			// tool/route, including ones a viewer's own role would not
			// otherwise be able to call — an owner-facing surface, same
			// bucket as /api-keys and /approvals, not a per-content-area tool.
			'/action-log'                           => 'admin',
			// Privilege-escalation surface: can create/modify WP user accounts
			// and exposes account PII (emails, roles, capabilities). Already
			// blanket-admin SCOPE in get_required_scope_for_request(); this
			// closes the matching CATEGORY gap so the site-wide toggle also
			// reaches it (adversarial review round 1 on #1007, BLOCK-1).
			'/users'                                => 'admin',

			// Granular plugin/admin families that the admin UI can toggle (all present
			// in get_category_meta()) but whose REST routes previously classified as ''
			// (ungated) — so disabling e.g. WooCommerce still let a direct call to
			// /woocommerce/* through. Each category here matches the dispatching MCP
			// tool's category, so the REST gate and tools/list agree. None of these
			// prefixes is a prefix of another and none shadows a finer route above, so
			// placing them at the end keeps the existing first-match ordering intact.
			'/woocommerce'                          => 'woocommerce',
			'/learnpress'                           => 'learnpress',
			'/network'                              => 'multisite',
			'/staging'                              => 'staging',
			// /theme-builder MUST precede /theme below — '/theme' is a literal
			// string prefix of '/theme-builder' (strpos() matching, not word
			// boundary), so first-match-wins would misclassify every
			// theme-builder route as 'site' if the order were reversed.
			'/theme-builder'                        => 'elementor-theme',
			'/forms'                                => 'forms',
			'/keyword-research'                     => 'seo',

			// Adversarial review round 1 on #1007, BLOCK-1: /settings, /favicon,
			// /theme/* (customizer mods/global-styles/custom-css — distinct from
			// /theme-builder above) and /themes/* (Pro theme reconfiguration:
			// Astra colors/typography/header/footer/layout, detect/supported)
			// are all check_permission()-gated but dispatch no MCP tool at all,
			// so RouteCategoryToolParityTest — which only iterates TOOL routes —
			// could never see them ungated. Live evidence from the review: an
			// author key with 'site' disabled could still POST /favicon and
			// change the site icon; an admin key with 'site' disabled could
			// still PUT /settings and change blogname, and GET/PUT
			// /theme/custom-css freely. AllRegisteredRoutesCategoryTest now
			// enumerates every REAL registered route (not just tooled ones) so
			// this class of gap fails a test instead of needing a live probe.
			'/settings'                             => 'site',
			'/favicon'                              => 'site',
			'/theme'                                => 'site',
			'/themes'                               => 'site',

			// wp_search is a 'content' tool; wp_list_block_types / wp_list_block_patterns
			// are 'gutenberg'. /block-types and /block-patterns do NOT begin with the
			// /blocks prefix above, so they need their own entries.
			'/search'                               => 'content',
			'/block-types'                          => 'gutenberg',
			'/block-patterns'                       => 'gutenberg',

			// Figma tools and wp_list_page_templates are 'site' tools.
			'/figma'                                => 'site',
			'/templates/page'                       => 'site',

			// #943 S2 adversarial review, BLOCK-1: every route below is the REST
			// target of a Free or Pro MCP tool but used to classify as '' (ungated),
			// so a key whose role lacks the tool's category — or a site that had
			// disabled that category — could still reach it directly, through
			// /batch, or through resources/read, while /mcp refused the same tool.
			// The site graph was the data-egress case: a designer-role read+write
			// key read every draft through /graph/query. Each entry matches the
			// dispatching tool's category; RouteCategoryToolParityTest now fails
			// if any tool route resolves to '' or to a different category.
			'/graph'                                => 'content',
			'/fetch'                                => 'content',
			'/languages'                            => 'content',
			'/ai-crawlers'                          => 'seo',
			'/indexnow'                             => 'seo',
			'/google-indexing'                      => 'seo',
			'/plugin-settings'                      => 'admin',
			'/update'                               => 'admin',
			'/cloudflare'                           => 'admin',

			// #1007: the remaining 'site'-category TOOL routes — 61 of the 64
			// routes a Free or Pro MCP tool maps to the 'site' category (the
			// other 3 are the discovery allowlist, immediately below). Before
			// this fix these 61 resolved to '' (ungated) — the same shape of
			// gap the #943 S2 review's BLOCK-1 fixed for /graph above — so an
			// author/editor key, or a site with 'site' disabled, could still
			// reach /menus, /memory, /options, etc. directly, through /batch,
			// or through resources/read, even though the identical tool call
			// through /mcp tools/call was refused. That was deliberate for
			// /site-info, /onboard and /introspect (see
			// get_discovery_allowlist_routes(), checked earlier in this method
			// and therefore never reaching this map) but never for the other
			// 61 — the owner decision that closed #1007 is to gate all of them, accepting
			// that Author and Editor keys (whose role categories do not include
			// 'site') lose access to this group. RouteCategoryToolParityTest's
			// SITE_EXEMPT is shrunk to exactly the discovery allowlist above.
			'/menus'                                => 'site',
			'/sidebars'                             => 'site',
			'/widgets'                              => 'site',
			'/analytics'                            => 'site',
			'/site-blueprints'                      => 'site',
			'/custom-css'                           => 'site',
			'/plugins'                              => 'site',
			'/cache-flush'                          => 'site',
			'/flush-permalinks'                     => 'site',
			'/memory'                               => 'site',
			'/agent-playbooks'                      => 'site',
			'/design-references'                    => 'site',
			'/guides'                                => 'site',
			'/options'                              => 'site',
			'/option'                               => 'site',
			'/rendered-copy'                        => 'site',
			'/rendered-html'                        => 'site',
			'/signals'                              => 'site',
			'/site-context'                         => 'site',
			'/site-health'                          => 'site',
			'/site-state'                           => 'site',
			'/theme-info'                           => 'site',
			'/workflows'                            => 'site',
		);
	}

	/**
	 * Codomain of get_category_for_route(): every category it can emit, from the same
	 * two maps the classifier uses (single source of truth for the invariant test).
	 *
	 * Public static: see get_category_for_route()'s docblock above.
	 *
	 * @return string[] Distinct category slugs the route classifier can return.
	 */
	public static function get_all_route_categories() {
		return array_values(
			array_unique(
				array_merge(
					array_values( self::get_route_pattern_map() ),
					array_values( self::get_route_category_map() )
				)
			)
		);
	}

	/**
	 * Check whether the route for the current request belongs to a
	 * site-administrator-disabled tool category.
	 *
	 * Called from verify_api_key() after authentication succeeds, so it only
	 * runs for valid, authenticated requests. Returns a WP_Error (HTTP 403)
	 * when the category is disabled; returns true otherwise.
	 *
	 * Note: ALL methods (GET and write) are blocked when a category is disabled.
	 * This mirrors the MCP layer, which removes disabled-category tools from
	 * tools/list entirely — both reads and writes are suppressed.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return true|WP_Error True when allowed; WP_Error when category is disabled.
	 */
	protected function check_disabled_category_for_route( $request ) {
		$disabled_categories = get_option( 'mcpwp_disabled_tool_categories', array() );

		// Fast path: nothing is disabled.
		if ( empty( $disabled_categories ) || ! is_array( $disabled_categories ) ) {
			return true;
		}

		// #1007 review round 2: GET/HEAD on a read-only discovery route (e.g.
		// GET /site-context) bypasses the site-wide toggle too — site context
		// is public brand copy, already served unauthenticated at /llms.txt.
		if ( $this->is_discovery_read_bypass( $request ) ) {
			return true;
		}

		$route    = $this->normalize_route_for_authorization( method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '' );
		$category = $this->get_category_for_route( $route );

		// Route is not category-gated — always allow.
		if ( '' === $category ) {
			return true;
		}

		if ( in_array( $category, $disabled_categories, true ) ) {
			return new WP_Error(
				'forbidden_category',
				sprintf(
					/* translators: %s: tool category name */
					__( 'The "%s" tool category has been disabled by the site administrator.', 'mcpwp' ),
					$category
				),
				array(
					'status'   => 403,
					'category' => $category,
					'hint'     => 'This tool category has been disabled in WP Admin > mcpwp > Tools. Contact the site administrator to re-enable it.',
				)
			);
		}

		return true;
	}

	/**
	 * Check whether the AUTHENTICATED KEY's own role/category restriction allows
	 * the route for the current request (#874).
	 *
	 * This is a DIFFERENT gate from check_disabled_category_for_route() above:
	 * that one is a site-administrator-wide toggle (same for every key); this one
	 * is the per-key restriction that is the entire substance of the "role" a
	 * scoped key is created with (admin/designer/author/editor/custom — see
	 * get_role_definitions()). Before this fix that restriction was enforced
	 * ONLY inside Mcpwp_REST_MCP::handle_tools_call(), i.e. only for callers going
	 * through the /mcp JSON-RPC surface. Every other entry point to this same
	 * REST API — a direct call to any /mcpwp/v1/* route, or one nested inside a
	 * /batch envelope — skipped it entirely, so an "author" key (categories:
	 * content, media, taxonomy — no elementor* category) could write Elementor
	 * data by calling POST /mcpwp/v1/elementor/{id} directly, something the same
	 * key's role explicitly does not permit through /mcp. The scope tier
	 * (read/write/admin, see get_required_scope_for_request()) was satisfied in
	 * both cases — scope and category are orthogonal checks, and only one of the
	 * two was ever applied outside of /mcp.
	 *
	 * Fails OPEN (allows) on an unclassified route ('' from get_category_for_route()),
	 * matching check_disabled_category_for_route()'s own treatment of the same
	 * cases and the documented fact that many discovery routes are deliberately
	 * left uncategorised. Fails OPEN only when the key's role carries NO
	 * category restriction at all — i.e. resolve_key_tool_categories() returns
	 * array() (admin role, or a legacy/migrated key with no 'role' field).
	 * A custom-role key whose categories resolve to nothing
	 * (resolve_key_tool_categories() returns the deny-all sentinel, never a
	 * bare array()) falls through to the real category check below and is
	 * denied there like any other restricted key (#1041) — it is no longer
	 * indistinguishable from "unrestricted".
	 *
	 * Called from every authentication path that also calls
	 * check_disabled_category_for_route() (plain X-API-Key, the batch/MCP
	 * sub-request fast path, OAuth bearer, and the mumcp.com broker bearer),
	 * always AFTER $this->current_api_key_record is populated.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return true|WP_Error True when allowed; WP_Error (403) when the key's role
	 *                       does not include the route's tool category.
	 */
	protected function check_key_category_restriction_for_route( $request ) {
		$key_record = $this->get_current_api_key_record();
		if ( ! is_array( $key_record ) ) {
			// No key record — trusted admin-AJAX context or a code path that
			// never set one. Nothing to restrict.
			return true;
		}

		$allowed = $this->resolve_key_tool_categories( $key_record );
		if ( empty( $allowed ) ) {
			// Unrestricted (admin role, or legacy/migrated key with no 'role'
			// field). A custom role with nothing granted resolves to the
			// deny-all sentinel, never a bare array(), so it never reaches
			// this branch (#1041).
			return true;
		}

		// #1007 review round 2: GET/HEAD on a read-only discovery route (e.g.
		// GET /site-context) bypasses the per-key role gate too — same
		// reasoning as check_disabled_category_for_route()'s matching check.
		if ( $this->is_discovery_read_bypass( $request ) ) {
			return true;
		}

		$route = $this->normalize_route_for_authorization( method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '' );

		// /batch is a fan-out envelope, not a category surface in its own right —
		// get_category_for_route() classifies it 'content' only so the SITE-WIDE
		// disabled-category toggle can block it outright (check_disabled_category_for_route()).
		// A per-key role restriction must not reuse that classification here: each
		// operation inside the envelope re-dispatches through
		// Mcpwp_REST_Batch::execute_operation() -> rest_do_request() ->
		// verify_api_key_auth_only() (Mcpwp_REST_Batch::$is_batch_sub_request is
		// true), which calls THIS method again with the operation's REAL route and
		// correctly restricts it there. Applying the 'content' label to the outer
		// /batch call itself would block a designer-role key from ever calling
		// /batch at all — even to batch nothing but Elementor writes its role
		// fully permits — which is exactly the over-broad, wrapper-level mistake
		// #874 asked this file to stop making, just pointed the other direction.
		// /mcp needs no such carve-out: it is not in get_route_category_map() at
		// all (category '' below), because the real per-tool category check
		// already lives in Mcpwp_REST_MCP::handle_tools_call() via
		// resolve_key_tool_categories()/tool_category_for(), keyed by tool name,
		// which is more precise than any route-prefix classification of '/mcp'
		// itself could be.
		if ( $this->route_matches( $route, '/mcpwp/v1/batch' ) ) {
			return true;
		}

		$category = $this->get_category_for_route( $route );

		// Route is not category-classified — always allow (same convention as
		// check_disabled_category_for_route() and get_category_for_route()'s
		// own docblock: many safe discovery routes are deliberately ungated).
		if ( '' === $category || in_array( $category, $allowed, true ) ) {
			return true;
		}

		$role = isset( $key_record['role'] ) ? self::describe_role_for_message( (string) $key_record['role'] ) : 'unknown';

		return new WP_Error(
			'forbidden_category',
			sprintf(
				/* translators: 1: role name, 2: tool category, 3: comma-separated allowed categories */
				__( 'This API key (role: %1$s) does not have access to %2$s routes. Allowed categories: %3$s.', 'mcpwp' ),
				$role,
				$category,
				self::describe_allowed_categories_for_message( $allowed )
			),
			array(
				'status'   => 403,
				'category' => $category,
				'hint'     => sprintf(
					'This route is in the "%1$s" tool category, but this key (role: %2$s) only has access to: %3$s. Request an admin key or a key with the "%1$s" category enabled.',
					$category,
					$role,
					self::describe_allowed_categories_for_message( $allowed )
				),
			)
		);
	}

	/**
	 * Round-3 adversarial review of #1024/#1030 (coordinator decision):
	 * round-2's chokepoint scanned every request for a fixed list of
	 * PARAM NAMES (id, post_id, page_id, ...) and resolved anything numeric
	 * found under them via get_post() — which meant a route whose numeric id
	 * happens to share a name with that list, but names something that is
	 * NOT a post at all (a WP_User id at /users/{id} or
	 * /woocommerce/customers/{id}, a term id at /terms/{id}, a menu id at
	 * /menus/{id}, an event/webhook row id), got resolved anyway: an
	 * unrelated WooCommerce product happening to share a numeric id with a
	 * WP_User was enough to 404 a request that never touched a post
	 * (BLOCK-2). Name-based scanning cannot fix this — the same name means
	 * different things on different routes — so this map replaces it with
	 * explicit, per-route-family DECLARATIONS: a route's params are resolved
	 * as post references only when this map says so, the same "opt in, not
	 * inferred" discipline get_route_category_map() already uses for
	 * category, and it lives right below that map for the same reason.
	 *
	 * Keys are route path PREFIXES, matched the same way
	 * get_category_for_route() matches get_route_category_map() — first
	 * match wins, so a longer/more specific prefix (e.g.
	 * '/woocommerce/products/categories', a TERM id, never a post) MUST be
	 * listed before a shorter one it would otherwise be swallowed by (e.g.
	 * '/woocommerce/products'). A prefix mapped to an empty array is
	 * documentation, not a no-op default: it exists so the next person
	 * editing this map sees THIS route was considered and deliberately left
	 * unresolved, rather than merely forgotten (also satisfies the
	 * enumeration test's requirement that every id-shaped param be
	 * accounted for one way or the other).
	 *
	 * Each param spec is a path string extract_post_ref_values() understands:
	 *  - 'name'          SCALAR param, e.g. the {id} in /pages/(?P<id>\d+) —
	 *                    the guard answers a missing or hidden one itself
	 *                    with the canonical 404
	 *  - 'name[]'        PER-ITEM: an array param, every element a post id
	 *  - 'name[].key'    PER-ITEM: an array of objects, 'key' from each
	 *  - 'name:csv'      PER-ITEM: a comma-separated string of ids (?ids=1,2,3)
	 *  - 'name:item'     PER-ITEM: one id that a bulk-shaped door reports in
	 *                    its per-item results rather than refusing
	 *
	 * and carries ONE of four classes (#1101 PR-3c, #1118):
	 *  - (no suffix)     WRITE target: the request changes that post.  Every
	 *                    principal gets the hidden check; a user-run principal
	 *                    also needs edit_post (delete_post on DELETE).
	 *  - '@read'         READ-only reference: the request only looks at that
	 *                    post (a link target, a clone or template source).
	 *                    Every principal gets the hidden check; a user-run
	 *                    principal needs read_post, not edit_post — so an
	 *                    Author may reference a published post, never
	 *                    another user's draft or private post.  A hidden read
	 *                    ref refuses the whole request, whatever its shape.
	 *  - '@read-user'    READ-only reference checked for user-run principals
	 *                    only (read_post, as '@read').  Used for the nested
	 *                    references this audit found undeclared
	 *                    (featured_image, media_id, a page's parent): a bot
	 *                    key keeps exactly the scope-only behaviour it had.
	 *                    Never on GET/HEAD, where the name is a list filter.
	 *  - '@source'       As '@read', for a post whose stored content the
	 *                    handler COPIES into the post it creates (a clone's
	 *                    or landing page's source). The only read refs that
	 *                    count toward the #1032 pre-existing-template
	 *                    exemption (written_post_ref_target_ids()); '@read'
	 *                    and '@read-user' refs never do (#1118 P0-2). A route
	 *                    that copies the source's TYPE also declares
	 *                    'source:<param>' in user_run_create_type_routes().
	 *
	 * Every declared value that this guard and a handler's absint() could
	 * read differently — negative, '12abc', true, an array — is refused 400
	 * invalid_post_ref (is_ambiguous_post_ref_value()).
	 *
	 * A key may contain '*' for one path segment ('/pages/*' + '/clone'), so a
	 * sub-route can carry a different class than its parent family.  Keys
	 * are matched in order; the first that matches wins.
	 *
	 * Per-item ids are never refused as a request; the bulk handler asks
	 * post_ref_item_visible() for each one (see
	 * check_post_naming_params_category()).
	 *
	 * A handful of routes need MORE than a static name — a nav menu item's
	 * `object_id` only ever names a post when its sibling `type` field is
	 * 'post_type' (it is a term id under 'taxonomy', nothing under 'custom')
	 * — those are NOT declared here; check_post_naming_params_category()
	 * handles them as small, explicit special cases immediately below this
	 * map, for the same "the guard should not guess" reason.
	 *
	 * @return array<string,array<string>> Route prefix => declared param paths.
	 */
	public static function get_post_ref_param_map() {
		return array(
			// === Elementor — specific families before the generic catch-all ===
			'/elementor/archetypes'              => array( 'id', 'page_id' ),
			'/elementor/blueprints'               => array(),
			'/elementor/build-page'               => array(),
			'/elementor/bulk'                     => array( 'ids:csv' ),
			// The clone's source is only read; its parent page too.
			'/elementor/clone'                    => array( 'source_id@source', 'parent@read' ),
			'/elementor/custom-code'              => array( 'id' ),
			// Both shapes are per-item: an id the run cannot touch is reported
			// in not_touched[], never refused as a whole request.
			'/elementor/find-replace'             => array( 'id:item', 'ids[]' ),
			'/elementor/globals'                  => array(),
			'/elementor/kit-css'                  => array(),
			'/elementor/landing-page'             => array( 'template_id@source' ),
			'/elementor/parts'                    => array( 'id', 'page_id' ),
			// The section is copied FROM page_id into a new template: a read.
			'/elementor/save-section-as-template' => array( 'page_id@read' ),
			'/elementor/status'                   => array(),
			'/elementor/templates'                => array( 'id', 'page_id' ),
			'/elementor/widget-help'               => array(),
			'/elementor/widgets'                   => array(),
			// Catch-all: /elementor/{id} and every sub-route hung off it
			// (add-section, edit-section, remove-section, replace-section,
			// edit-widget, patch, preview, summary) plus /elementor/
			// regenerate-css's optional 'id'.
			'/elementor'                           => array( 'id' ),

			// === Content ===
			// #1020's own named doors: a generic, id-based door that
			// resolves an arbitrary post and used to be gated only by ROUTE
			// PREFIX (always 'content'), not by what the id actually
			// resolved to.
			'/fetch'                               => array( 'id' ),
			'/rendered-html'                       => array( 'id' ),
			'/rendered-copy'                       => array( 'id' ),
			// `updates` and the original `pages` spelling are both accepted.
			// parent / featured_image: nested references (#1110, PR-3c).
			'/pages/bulk'                          => array( 'updates[].id', 'pages[].id', 'updates[].parent@read-user', 'pages[].parent@read-user', 'updates[].featured_image@read-user', 'pages[].featured_image@read-user' ),
			// /pages/{id}/clone: the {id} IS the clone source — only read.
			'/pages/*/clone'                       => array( 'id@source' ),
			// /pages/{id}, .../template, .../translations.
			'/pages'                               => array( 'id', 'parent@read-user', 'featured_image@read-user' ),
			// `updates` and the original `posts` spelling are both accepted.
			'/posts/bulk'                          => array( 'updates[].id', 'posts[].id', 'updates[].featured_image@read-user', 'posts[].featured_image@read-user' ),
			// The attachment set as the featured image is only referenced.
			'/posts/*/featured-image'              => array( 'id', 'media_id@read-user' ),
			'/posts'                               => array( 'id', 'featured_image@read-user' ),
			'/post-meta'                           => array( 'id' ),
			// Generic CPT door: /content/{post_type}/{id}. GET /content
			// (list) has no 'id' param, so nothing fires there.
			'/content'                             => array( 'id' ),
			'/blocks'                              => array( 'id' ),

			// === Content graph ===
			// The link is written INTO source_id; target_id is only read
			// (its URL and title).
			'/content-graph/apply-link'            => array( 'source_id', 'target_id@read' ),
			'/content-graph/suggestions'           => array( 'source_id@read' ),

			// === SEO — specific plugin/aggregate doors before the /seo/{id} catch-all ===
			// updates[].id: bulk_update()'s own 'id'→'post_id' alias
			// normalization runs AFTER auth, so the guard must recognize
			// both spellings before it (#1030 round-3, BLOCK-3).
			'/seo/bulk'                            => array( 'updates[].id', 'updates[].post_id' ),
			'/seo/content-quality'                 => array( 'id' ),
			'/seo/readiness'                       => array( 'id' ),
			'/seo/structured-data'                 => array( 'id' ),
			'/seo/media'                           => array( 'id' ),
			'/seo/yoast'                           => array( 'id' ),
			'/seo/rankmath'                        => array( 'id' ),
			'/seo/aioseo'                          => array( 'id' ),
			'/seo/seopress'                        => array( 'id' ),
			// post_id on the stored-issue readers names one post whose title/
			// url the answer carries (#1030 round-4 WARN-1): a hidden or
			// missing one gets the canonical 404 like any other declared id,
			// and the unfiltered lists drop items for hidden posts in the
			// handlers themselves.
			'/seo/issues'                          => array( 'post_id' ),
			'/seo/autofix-plan'                    => array( 'post_id' ),
			// Catch-all: /seo/{id}, /seo/{id}/analyze, /seo/{id}/noindex.
			'/seo'                                 => array( 'id' ),

			// === Media ===
			'/media'                               => array( 'id' ),

			// === Theme builder (Pro) — genuinely elementor_library posts;
			// category_for_post_in_route() below is what keeps a real theme
			// location distinct from a saved "section" template here, not an
			// exemption (#1030 round-3, WARN-2).
			'/theme-builder'                       => array( 'id' ),

			// === WooCommerce — term/customer sub-paths BEFORE the post ones
			// they would otherwise be swallowed by ===
			'/woocommerce/products/categories'     => array(), // product_cat TERM id.
			'/woocommerce/products/tags'           => array(), // product_tag TERM id.
			'/woocommerce/products'                => array( 'id' ),
			// Only product_id names a post here: a product archetype's own
			// {id} is a row in the mcpwp product-archetypes OPTION
			// (get_product_archetype_by_id()), never a wp_posts id — declaring
			// it would resolve an unrelated post that happens to share the
			// number (round-3's BLOCK-2 again) and 404 every real archetype.
			'/woocommerce/archetypes'              => array( 'product_id' ),
			// #1030 round-3, BLOCK-2's own named example: a WooCommerce
			// "customer" IS a WP_User, resolved by get_user_by(), never
			// get_post() — declaring 'id' here was what made /users/{id}
			// and /woocommerce/customers/{id} collide with an unrelated
			// gated post sharing the same numeric id.
			'/woocommerce/customers'               => array(),
			// Orders: under WooCommerce's High-Performance Order Storage
			// (the current default) an order is a row in a custom table, not
			// a post at all; under legacy storage it is a 'shop_order' post,
			// but nothing here can tell which a given site uses without a
			// query the guard has no business making. Left undeclared either
			// way — /woocommerce's own route category already gates the
			// whole family.
			'/woocommerce/orders'                  => array(),

			// === LearnPress — categories are a TAXONOMY term id, not a post ===
			'/learnpress/categories'               => array(),
			'/learnpress/courses'                  => array( 'id' ),
			'/learnpress/lessons'                  => array( 'id' ),
			'/learnpress/quizzes'                  => array( 'id' ),

			// === AI integrations that resolve and touch one specific post ===
			'/integrations/generate-featured-image' => array( 'post_id' ),
			'/integrations/generate-excerpt'        => array( 'post_id' ),
			'/integrations/generate-alt-text'       => array( 'attachment_id' ),
			'/integrations/describe-image'          => array( 'attachment_id' ),
		);
	}

	/**
	 * Routes whose regex or declared args carry a param named like a post
	 * reference (id, ids, *_id, *_ids —
	 * DeclaredPostRefParityAllRoutesTest's own enumeration pattern) but
	 * that are NOT post references, so get_post_ref_param_map() above
	 * correctly maps them to an empty array rather than omitting them.
	 * Kept as one named, commented list — not scattered empty-array entries
	 * a reader has to notice are intentional — so the enumeration test's own
	 * "every matching route is accounted for" assertion has one place to
	 * check against, matching the map's own prefix-matching rules.
	 *
	 * @return array<string> Route prefixes with a non-post id-shaped param.
	 */
	public static function get_non_post_id_route_prefixes() {
		return array(
			'/users',                          // WP_User id.
			'/woocommerce/customers',          // WP_User id (see the map's own comment).
			'/woocommerce/orders',             // HPOS row or legacy post — ambiguous, left alone.
			'/woocommerce/products/categories', // product_cat TERM id.
			'/woocommerce/products/tags',      // product_tag TERM id.
			'/learnpress/categories',          // TAXONOMY term id.
			'/terms',                          // TERM id.
			'/categories',                     // TERM id.
			'/tags',                           // TERM id.
			'/menus',                          // menu / menu-item id — object_id handled as its own special case below.
			'/events',                         // MCPWP event-store row id (Free) or Pro Events Calendar row id.
			'/webhooks',                       // webhook row id.
			'/api-keys',                       // API key row id (string hash), not numeric anyway.
			'/design-references',              // design reference row id.
			'/site-blueprints',                // blueprint row id.
			'/approvals',                      // approval request id — its TARGET's category is gated separately (#1029), not this id.
			'/action-log',                     // log row id (non-numeric anyway).
			'/sidebars',                       // sidebar/widget-area id (string, not numeric).
			'/widgets',                        // widget instance id (string, not numeric).
			'/staging',                        // async push-operation id (string, not numeric).
			'/forms',                          // third-party form-plugin id — most of these plugins do not back entries with wp_posts at all, and the ones that do (CF7) are ungated by post-type today; left alone rather than guessed at.
			// /graph/node and /graph/neighbors' post_id: Mcpwp_Graph_Reader
			// is ITS OWN visibility chokepoint (round-1/round-2 of #1024),
			// filtering by kind/taxonomy in SQL with a richer response shape
			// (graph_status, cursors) than a flat "not found" — declaring
			// post_id here too would run this chokepoint's generic check
			// FIRST and short-circuit that already-correct, already-tested
			// mechanism with a cruder one. See class-mcpwp-rest-graph.php's
			// category_excluded_kinds()/category_excluded_taxonomies().
			'/graph',
			// Ids from other systems, never wp_posts rows.
			'/integrations/stock-photos',      // Pexels photo_id.
			'/integrations/text-to-speech',    // ElevenLabs voice_id.
			'/figma',                          // Figma node_id / file key.
		);
	}

	/**
	 * Contextual category for a resolved post reference (#1030 round-3
	 * review, item 3): the same post can legitimately need a DIFFERENT
	 * category depending on which door resolved it.
	 *
	 * elementor_snippet is always 'elementor-theme' — category_for_post_type()
	 * already says so and there is no second reading of it.
	 *
	 * elementor_library is 'elementor-templates' EXCEPT when the resolving
	 * route's own category is 'elementor-theme' (i.e. /theme-builder/*) AND
	 * the template's own `_elementor_template_type` is a genuine
	 * theme-builder location (header, footer, single*, archive*, popup,
	 * error-404, loop-item, product*, search-results) — then it is
	 * 'elementor-theme' too, matching the route it was reached through.
	 * Round-2's fix for this same problem was a flat EXEMPTION for both
	 * types (skip the chokepoint entirely), which fixed the false 404 on a
	 * real theme template but silently stopped gating a "section" template
	 * read through /theme-builder/* at all (WARN-1/WARN-2's own PoCs — a
	 * Pro SEO route or /theme-builder/templates/{id}/conditions accepting
	 * ANY post id, elementor_library included). Reading the template's OWN
	 * type, rather than exempting the type wholesale, is what lets a real
	 * theme location and a saved section template answer differently
	 * through the SAME door.
	 *
	 * Every other post type defers to category_for_post_type() unchanged.
	 *
	 * @param WP_Post $post           Resolved post.
	 * @param string  $route_category The resolving route's own category
	 *                                (get_category_for_route()'s answer).
	 * @return string Category slug.
	 */
	public static function category_for_post_in_route( WP_Post $post, $route_category ) {
		return self::category_for_post_ref( (int) $post->ID, (string) $post->post_type, $route_category );
	}

	/**
	 * category_for_post_in_route() for a post known only by id and type —
	 * what the guard's single resolution query returns.
	 *
	 * @param int    $post_id        Post id.
	 * @param string $post_type      Post type.
	 * @param string $route_category The resolving route's own category.
	 * @return string Category slug.
	 */
	public static function category_for_post_ref( $post_id, $post_type, $route_category ) {
		if ( 'elementor_library' === $post_type && 'elementor-theme' === $route_category ) {
			$template_type = get_post_meta( (int) $post_id, '_elementor_template_type', true );
			if ( self::is_theme_builder_location_type( $template_type ) ) {
				return 'elementor-theme';
			}
		}

		return self::category_for_post_type( $post_type );
	}

	/**
	 * Whether an Elementor Pro `_elementor_template_type` value is a genuine
	 * theme-builder location (a page/site region bound to a display
	 * condition) rather than a saved page template, section, popup-as-library-
	 * item, or landing page — the same "this is a THEME location, not just
	 * anything in the template library" line category_for_post_in_route()
	 * needs and Mcpwp_Theme_Builder::resolve_theme_template() drew
	 * independently for its own, non-category reason (#1030 round-2 WARN-A).
	 *
	 * header/footer/popup/error-404/loop-item/search-results are exact
	 * matches; single/archive/product are prefixes (Elementor Pro suffixes
	 * them per-post-type and per-taxonomy, e.g. 'single-post', 'single-page',
	 * 'archive-product', 'product-archive').
	 *
	 * popup is deliberately a theme location (#1030 round-4 WARN-3, kept by
	 * decision): an Elementor Pro popup is a site-wide display bound to
	 * display conditions and triggers, managed from the same Theme Builder
	 * screen as header/footer, not a reusable content block pasted into a
	 * page. An elementor-theme key that can place a header can place a
	 * popup; a key with only elementor-templates reaches popups through the
	 * template library routes, exactly as before.
	 *
	 * Public static so Mcpwp_Theme_Builder can share this one definition
	 * instead of keeping its own, second list that could drift from this one.
	 *
	 * @param mixed $type The post's `_elementor_template_type` meta value.
	 * @return bool
	 */
	public static function is_theme_builder_location_type( $type ) {
		if ( ! is_string( $type ) || '' === $type ) {
			return false;
		}

		$exact = array( 'header', 'footer', 'popup', 'error-404', 'loop-item', 'search-results' );
		if ( in_array( $type, $exact, true ) ) {
			return true;
		}

		foreach ( array( 'single', 'archive', 'product' ) as $prefix ) {
			if ( $type === $prefix || 0 === strpos( $type, $prefix . '-' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Extract raw candidate id values named by one declared param PATH
	 * (get_post_ref_param_map()'s own spec syntax) out of a request's params.
	 * Never resolves anything — just pulls out what's THERE, numeric or not;
	 * collect_post_refs() filters and caps the result.
	 *
	 * @param array  $params Request params.
	 * @param string $path   One declared spec, e.g. 'id', 'ids[]', 'updates[].id', 'ids:csv', 'id:item'.
	 * @return array<mixed> Raw values, unfiltered.
	 */
	protected function extract_post_ref_values( array $params, $path ) {
		if ( ':csv' === substr( $path, -4 ) ) {
			$key = substr( $path, 0, -4 );
			$raw = isset( $params[ $key ] ) ? $params[ $key ] : null;
			if ( ! is_string( $raw ) || '' === $raw ) {
				return array();
			}
			return array_map( 'trim', explode( ',', $raw ) );
		}

		if ( ':item' === substr( $path, -5 ) ) {
			$key = substr( $path, 0, -5 );
			return ( isset( $params[ $key ] ) && is_scalar( $params[ $key ] ) ) ? array( $params[ $key ] ) : array();
		}

		if ( false !== strpos( $path, '[].' ) ) {
			list( $list_key, $field ) = explode( '[].', $path, 2 );
			if ( ! isset( $params[ $list_key ] ) || ! is_array( $params[ $list_key ] ) ) {
				return array();
			}
			$values = array();
			foreach ( $params[ $list_key ] as $item ) {
				if ( is_array( $item ) && isset( $item[ $field ] ) ) {
					$values[] = $item[ $field ];
				}
			}
			return $values;
		}

		if ( '[]' === substr( $path, -2 ) ) {
			$key = substr( $path, 0, -2 );
			return ( isset( $params[ $key ] ) && is_array( $params[ $key ] ) ) ? array_values( $params[ $key ] ) : array();
		}

		return isset( $params[ $path ] ) ? array( $params[ $path ] ) : array();
	}

	/**
	 * Whether a declared spec names a PER-ITEM post reference (the bulk
	 * shapes) rather than a scalar one the guard answers itself.
	 *
	 * @param string $spec Declared spec.
	 * @return bool
	 */
	protected static function is_per_item_post_ref_spec( $spec ) {
		return '[]' === substr( $spec, -2 )
			|| false !== strpos( $spec, '[].' )
			|| ':csv' === substr( $spec, -4 )
			|| ':item' === substr( $spec, -5 );
	}

	/**
	 * Split a declared spec into its path and its class (#1101 PR-3c):
	 * 'write', 'read' ('@read') or 'read-user' ('@read-user').
	 *
	 * @param string $spec Declared spec.
	 * @return array{0:string,1:string}
	 */
	public static function post_ref_spec_parts( $spec ) {
		$spec = (string) $spec;
		if ( '@read-user' === substr( $spec, -10 ) ) {
			return array( substr( $spec, 0, -10 ), 'read-user' );
		}
		if ( '@read' === substr( $spec, -5 ) ) {
			return array( substr( $spec, 0, -5 ), 'read' );
		}
		if ( '@source' === substr( $spec, -7 ) ) {
			return array( substr( $spec, 0, -7 ), 'source' );
		}
		return array( $spec, 'write' );
	}

	/**
	 * Whether a declared ref value is one this guard and a handler's
	 * absint() / (int) could read differently, and so is refused (400
	 * invalid_post_ref) rather than skipped:
	 *  - an array, object or true (absint() reads 1, or the first element);
	 *  - a non-numeric string that starts like a number ('12abc', '+12x',
	 *    '-0x1');
	 *  - #1118 P0-1: ANY negative number, int, float or numeric string
	 *    ('-5', -5, ' -5', -5.0). The guard skipped values <= 0 as "not an
	 *    id", and absint() turned -N into N — a page editor cloned another
	 *    user's draft by sending -id.
	 * 0, '0', '', null and false are not ids and still pass untouched.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public static function is_ambiguous_post_ref_value( $value ) {
		if ( is_array( $value ) || is_object( $value ) || true === $value ) {
			return true;
		}
		// A float (or float-shaped string) is an id only when it is a whole
		// number an int holds exactly (< 2^53): then a cast, absint() and the
		// handlers all read the same integer. 4095.99999999999 (read as 4095,
		// stored as 4096), 1e19 (wraps), INF and NAN are refused. Values a
		// handler STORES verbatim (id-metas) take the stricter path in
		// canonicalize_post_meta_value(), which refuses every float.
		if ( is_float( $value ) ) {
			return $value < 0 || ! self::is_exact_whole_number( $value );
		}
		if ( is_int( $value ) ) {
			return $value < 0;
		}
		if ( ! is_string( $value ) ) {
			return false;
		}
		// '', ' ' and signed zeros ('0', '-0', '+00') are not ids: every reading is 0.
		if ( '' === trim( $value ) || 1 === preg_match( '/^\\s*[+-]?0+\\s*$/', $value ) ) {
			return false;
		}
		// A digit-only string is an id (or 0); a string that opens like a
		// number but is anything else ('12abc', '-5', '1e3', '4.096e3', '5.0')
		// is read differently by a cast and a store, so it is refused.
		if ( 1 === preg_match( '/^\s*\+?[0-9]+\s*$/', $value ) ) {
			return null === self::canonical_post_id_token( $value ) && ! self::is_zero_digits( $value );
		}
		if ( is_numeric( $value ) ) {
			$as_float = (float) $value;
			return $as_float < 0 || ! self::is_exact_whole_number( $as_float );
		}
		return 1 === preg_match( '/^\s*[+-]?[.\d]/', $value );
	}

	/**
	 * Whether a float is finite, whole and below 2^53 (exactly an int).
	 *
	 * @param float $value Float.
	 * @return bool
	 */
	private static function is_exact_whole_number( $value ) {
		return is_finite( $value ) && abs( $value ) < 9007199254740992.0 && floor( $value ) === $value;
	}

	/**
	 * Whether a digit-only string is zero ('0', '000', ' +0 ').
	 *
	 * @param string $value Digit-only string.
	 * @return bool
	 */
	private static function is_zero_digits( $value ) {
		return '' === ltrim( trim( $value, " \t\n\r\0\x0B+" ), '0' );
	}

	/**
	 * The ONE definition of an id token: a PHP int > 0, or a digit-only
	 * string ('5', ' 7 ', '+7', '007') whose value fits an int and is > 0.
	 * Anything else (a float, an exponent/decimal/hex string, a bool, null,
	 * an array, zero, an overflowing number) is null — not an id.
	 *
	 * @param mixed $value Raw value.
	 * @return int|null
	 */
	public static function canonical_post_id_token( $value ) {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\s*\+?([0-9]+)\s*$/', $value, $m ) ) {
			return null;
		}
		$digits = ltrim( $m[1], '0' );
		if ( '' === $digits || strlen( $digits ) > 19 || ( 19 === strlen( $digits ) && strcmp( $digits, '9223372036854775807' ) > 0 ) ) {
			return null;
		}
		return (int) $digits;
	}

	/**
	 * The declared specs for a route path: those of post_ref_map_key_for_path().
	 *
	 * @param string $path Route without the /mcpwp/v1 prefix.
	 * @return string[]
	 */
	public static function post_ref_specs_for_path( $path ) {
		$key = self::post_ref_map_key_for_path( $path );
		if ( null === $key ) {
			return array();
		}
		$map = self::get_post_ref_param_map();
		return $map[ $key ];
	}

	/**
	 * The map key that governs a route path: the first key that matches it —
	 * exactly, as a prefix followed by '/', or with '*' standing for one
	 * segment. Null when none does.
	 *
	 * @param string $path Route without the /mcpwp/v1 prefix.
	 * @return string|null
	 */
	public static function post_ref_map_key_for_path( $path ) {
		$path = (string) $path;
		foreach ( array_keys( self::get_post_ref_param_map() ) as $prefix ) {
			if ( false !== strpos( $prefix, '*' ) ) {
				$pattern = '#^' . str_replace( '\\*', '[^/]+', preg_quote( $prefix, '#' ) ) . '(/|$)#';
				if ( preg_match( $pattern, $path ) ) {
					return $prefix;
				}
				continue;
			}
			if ( $path === $prefix || 0 === strpos( $path, $prefix . '/' ) ) {
				return $prefix;
			}
		}
		return null;
	}

	/**
	 * Most ids a declared per-item param may name in one request. Checked
	 * before any lookup runs, so an oversized list costs nothing.
	 */
	protected static function post_ref_batch_cap() {
		return 500;
	}

	/**
	 * Pull every declared post reference out of a request: the scalar ids
	 * the guard answers itself and the per-item ids bulk handlers answer
	 * one by one. Null when the route declares nothing (or is an /mcp or
	 * /batch envelope — each re-dispatches its real work through
	 * rest_do_request(), which runs this same check on the real route).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array{route_category:string,scalar:int[],items:int[],read:int[]}|WP_Error|null
	 */
	protected function collect_post_refs( $request ) {
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
			return null;
		}
		$route = $this->normalize_route_for_authorization( (string) $request->get_route() );
		if ( $this->route_matches( $route, '/mcpwp/v1/mcp' ) || $this->route_matches( $route, '/mcpwp/v1/batch' ) ) {
			return null;
		}

		if ( ! method_exists( $request, 'get_params' ) ) {
			return null;
		}
		$params = $this->gate_params( $request );

		$path = ( 0 === strpos( $route, '/mcpwp/v1' ) ) ? substr( $route, strlen( '/mcpwp/v1' ) ) : $route;

		$specs     = self::post_ref_specs_for_path( $path );
		$user_run  = $this->requester_acts_as_user();
		// '@read-user' refs are payload refs of WRITES (a featured image, a
		// parent being set). On a GET the same name is a list filter
		// (GET /pages?parent=) whose results are already clamped to what
		// the caller may read; judging it would turn "list the published
		// children" into a 404 an Author never got before.
		$is_read_method = method_exists( $request, 'get_method' ) && in_array( strtoupper( (string) $request->get_method() ), array( 'GET', 'HEAD' ), true );

		$scalar = array();
		$items  = array();
		$read   = array();
		$source = array(); // '@source' refs: also in $read; the only template-exempt read refs.

		foreach ( $specs as $declared_spec ) {
			list( $spec, $class ) = self::post_ref_spec_parts( $declared_spec );
			if ( 'read-user' === $class && ( ! $user_run || $is_read_method ) ) {
				continue; // A bot key keeps its scope-only behaviour; a GET filter is not a write.
			}
			$per_item = self::is_per_item_post_ref_spec( $spec );
			$raw      = $this->extract_post_ref_values( $params, $spec );

			if ( $per_item && count( $raw ) > self::post_ref_batch_cap() ) {
				return new WP_Error(
					'too_many_ids',
					sprintf(
						/* translators: %d: cap */
						__( 'A single request may name at most %d posts.', 'mcpwp' ),
						self::post_ref_batch_cap()
					),
					array( 'status' => 400 )
				);
			}

			foreach ( $raw as $value ) {
				// #1101 PR-3c: a value this guard and a handler's absint()
				// would read differently ('12abc', true, [12]: skipped here,
				// a real post id there) is refused, not skipped.
				if ( self::is_ambiguous_post_ref_value( $value ) ) {
					return new WP_Error(
						'invalid_post_ref',
						__( 'Post ids must be plain positive integers.', 'mcpwp' ),
						array( 'status' => 400 )
					);
				}
				if ( ! is_numeric( $value ) || (int) $value <= 0 ) {
					continue; // Not an id at all: the door's own validation answers it.
				}
				if ( 'source' === $class ) {
					$read[]   = (int) $value;
					$source[] = (int) $value;
				} elseif ( 'write' !== $class ) {
					$read[] = (int) $value;
				} elseif ( $per_item ) {
					$items[] = (int) $value;
				} else {
					$scalar[] = (int) $value;
				}
			}
		}

		// The nav menu item special case (#1030 round-3 WARN-3, round-5
		// WARN-2): object_id only ever names a post when its sibling 'type'
		// says so — 'post_type', or Pro's back-compat aliases 'post'/'page'
		// (Mcpwp_Site_Manager::add_menu_item() maps both onto post_type,
		// keeping a caller-supplied `object`). Under 'taxonomy' it is a term
		// id, under 'custom' there is no object at all, so declaring it
		// unconditionally in the map above would misresolve those shapes
		// exactly the way BLOCK-2 did for /users and /woocommerce/customers.
		// A single item (POST /menus/{id}/items) is a scalar ref; the items[]
		// of a menu created in one call (POST /menus) are per-item refs.
		if ( $this->route_matches( $path, '/menus' ) ) {
			if ( ! $is_read_method && array_key_exists( 'items', $params ) ) {
				if ( ! is_array( $params['items'] ) ) {
					return new WP_Error( 'invalid_menu_item', __( 'Menu items must be an array of objects.', 'mcpwp' ), array( 'status' => 400 ) );
				}
				foreach ( $params['items'] as $menu_item ) {
					$valid = self::validate_menu_item_shape( $menu_item );
					if ( is_wp_error( $valid ) ) {
						return $valid;
					}
				}
			}
			// #1118 P0-1: an object_id the guard skips (negative, '12abc') but
			// absint() reads as a post, under a type that names a post.
			foreach ( array_merge( array( $params ), isset( $params['items'] ) && is_array( $params['items'] ) ? $params['items'] : array() ) as $menu_item ) {
				if ( ! $is_read_method && is_array( $menu_item ) && array_key_exists( 'type', $menu_item ) ) {
					$type = self::normalize_menu_item_type( $menu_item['type'] );
					if ( is_wp_error( $type ) ) {
						return $type;
					}
				}
				if ( is_array( $menu_item ) && self::menu_item_type_names_post( $menu_item ) && array_key_exists( 'object_id', $menu_item ) && self::is_ambiguous_post_ref_value( $menu_item['object_id'] ) ) {
					return new WP_Error( 'invalid_post_ref', __( 'Post ids must be plain positive integers.', 'mcpwp' ), array( 'status' => 400 ) );
				}
			}
			if ( self::menu_item_names_post( $params ) ) {
				$scalar[] = (int) $params['object_id'];
			}
			if ( isset( $params['items'] ) && is_array( $params['items'] ) ) {
				if ( count( $params['items'] ) > self::post_ref_batch_cap() ) {
					return new WP_Error(
						'too_many_ids',
						sprintf(
							/* translators: %d: cap */
							__( 'A single request may name at most %d posts.', 'mcpwp' ),
							self::post_ref_batch_cap()
						),
						array( 'status' => 400 )
					);
				}
				foreach ( $params['items'] as $menu_item ) {
					if ( is_array( $menu_item ) && self::menu_item_names_post( $menu_item ) ) {
						$items[] = (int) $menu_item['object_id'];
					}
				}
			}
		}

		// #1121: POST /post-meta/{id} {key, value} — the value names posts when
		// the key is a known id meta (get_post_ref_meta_value_keys()). Read-only
		// references (an attachment set as the thumbnail, a product linked as an
		// upsell), so '@read-user' semantics: user-run principals, writes only.
		if ( $user_run && ! $is_read_method && preg_match( '#^/post-meta/\d+$#', $path ) && array_key_exists( 'value', $params ) ) {
			$meta_ids = self::post_ref_meta_ids( isset( $params['key'] ) ? $params['key'] : '', $params['value'] );
			if ( is_wp_error( $meta_ids ) ) {
				return $meta_ids;
			}
			foreach ( $meta_ids as $meta_id ) {
				$read[] = $meta_id;
			}
		}

		if ( empty( $scalar ) && empty( $items ) && empty( $read ) ) {
			return null;
		}

		return array(
			'route_category' => (string) self::get_category_for_route( '/mcpwp/v1' . $path ),
			'scalar'         => array_values( array_unique( $scalar ) ),
			'items'          => array_values( array_unique( $items ) ),
			// #1101 PR-3c: read-only references — hidden check (read_post
			// for a user-run principal), never the per-post edit check.
			'read'           => array_values( array_unique( $read ) ),
			// #1118 P0-2: the subset of 'read' whose stored content the handler
			// copies verbatim (a clone's or landing page's source) — the only
			// read refs the #1032 pre-existing-template exemption may use.
			'source'         => array_values( array_unique( $source ) ),
		);
	}

	/**
	 * Menu items must be field maps, never scalar values or nested lists.
	 *
	 * @param mixed $item Item payload.
	 * @return true|WP_Error
	 */
	public static function validate_menu_item_shape( $item ) {
		if ( ! is_array( $item ) || empty( $item ) || count( array_filter( array_keys( $item ), 'is_int' ) ) > 0 ) {
			return new WP_Error( 'invalid_menu_item', __( 'Menu items must be objects with named fields.', 'mcpwp' ), array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * Parse a menu-item type for both the category gate and write handlers.
	 *
	 * Do not strip or case-fold input: sanitize_key()/sanitize_text_field()
	 * can turn an unrecognised value into a type that names a post. Keep the
	 * existing aliases, but refuse every value outside this exact vocabulary.
	 * Callers apply the documented custom default only when type is omitted.
	 *
	 * @param mixed $type Raw type.
	 * @return array{type:string,object:string}|WP_Error Canonical type and alias object default.
	 */
	public static function normalize_menu_item_type( $type ) {
		$types = array(
			'custom'    => array( 'type' => 'custom', 'object' => '' ),
			'post_type' => array( 'type' => 'post_type', 'object' => '' ),
			'taxonomy'  => array( 'type' => 'taxonomy', 'object' => '' ),
			'post'      => array( 'type' => 'post_type', 'object' => 'post' ),
			'page'      => array( 'type' => 'post_type', 'object' => 'page' ),
			'category'  => array( 'type' => 'taxonomy', 'object' => 'category' ),
			'tag'       => array( 'type' => 'taxonomy', 'object' => 'post_tag' ),
			'post_tag'  => array( 'type' => 'taxonomy', 'object' => 'post_tag' ),
		);
		if ( is_string( $type ) && isset( $types[ $type ] ) ) {
			return $types[ $type ];
		}
		return new WP_Error( 'invalid_menu_item_type', __( 'Unrecognised menu item type.', 'mcpwp' ), array( 'status' => 400 ) );
	}

	/**
	 * Whether a nav-menu item's type says its object_id is a post.
	 *
	 * @param array $item Item payload.
	 * @return bool
	 */
	public static function menu_item_type_names_post( array $item ) {
		// The gate must never be looser than any reader of the field.
		// (1) The write handlers parse the type exactly, through
		// normalize_menu_item_type(): only that vocabulary reaches a post.
		$type = self::normalize_menu_item_type( array_key_exists( 'type', $item ) ? $item['type'] : 'custom' );
		if ( ! is_wp_error( $type ) && 'post_type' === $type['type'] ) {
			return true;
		}
		// (2) #1121: a reader that folds the value the way sanitize_key() does
		// (lowercase, strip) sees 'Page' and ' page ' as 'page'. Those values
		// are refused by the exact parse above, but the gate still demands the
		// post capability for them rather than depend on that refusal.
		$folded = isset( $item['type'] ) && is_string( $item['type'] ) ? self::normalize_post_ref_token( $item['type'] ) : '';
		return in_array( $folded, array( 'post_type', 'post', 'page' ), true );
	}

	/**
	 * sanitize_key() semantics without the WordPress dependency: lowercase,
	 * then drop everything outside [a-z0-9_-].
	 *
	 * @param string $token Raw token.
	 * @return string
	 */
	public static function normalize_post_ref_token( $token ) {
		return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $token ) );
	}

	/**
	 * Post-meta keys whose VALUE names posts (#1121), and the value's shape:
	 * 'scalar' (one id), 'csv' ('1,2,3') or 'list' (an array, or a JSON string
	 * the handler decodes). Keys are lowercase: look one up through
	 * post_ref_meta_ids(), which lowercases first. Only keys /post-meta can
	 * write: _elementor_* keys are refused there outright.
	 *
	 * @return array<string,string>
	 */
	public static function get_post_ref_meta_value_keys() {
		return array(
			'_thumbnail_id'          => 'scalar', // Featured image (core).
			'_product_image_gallery' => 'csv',    // WooCommerce gallery attachment ids.
			'_upsell_ids'            => 'list',   // WooCommerce linked products.
			'_crosssell_ids'         => 'list',
			'_children'              => 'list',   // WooCommerce grouped product children.
		);
	}

	/**
	 * The ONE decoder for a /post-meta value: a string that opens a JSON
	 * object or array and parses is the structure it encodes (as an
	 * associative array); anything else is returned unchanged. The handler
	 * stores what this returns and the guard audits what this returns, so the
	 * two can never read a value differently (#1121: '{"0":8}' was decoded by
	 * one and not the other).
	 *
	 * @param mixed $value Raw value.
	 * @return mixed
	 */
	public static function decode_post_meta_value( $value ) {
		if ( is_string( $value ) ) {
			$head = substr( ltrim( $value ), 0, 1 );
			if ( '{' === $head || '[' === $head ) {
				$decoded = json_decode( $value, true );
				if ( null !== $decoded ) {
					return $decoded;
				}
			}
		}
		return $value;
	}

	/**
	 * The ONE canonicaliser for an id-meta write: the ids the value names
	 * AND the canonical value to store, so what the guard checks is by
	 * construction what the handler stores.
	 *
	 * Null when the key is not a known id meta. The key is lowercased first
	 * (wp_postmeta.meta_key collates case-insensitively), and the value goes
	 * through decode_post_meta_value(). Accepted: a PHP int > 0, a digit-only
	 * string ('5', ' 7 ', '+7', '007'), a comma list of those ('5,6'), and
	 * an array (or JSON array/object) of those. Every float, exponent /
	 * decimal / hex string, bool, nested array, zero or null inside a list is
	 * 400 invalid_post_ref; over post_ref_batch_cap() ids is 400 too_many_ids.
	 * A whole-number float (5.0) is refused too: JSON clients send 5, and a
	 * float has no single reading across a cast, a store and absint().
	 *
	 * 'value' is what to store: an int for the scalar shape, a canonical
	 * 'a,b' string for csv, a list of ints for list; null (delete) and the
	 * empty scalars ('', 0, '0' — the clearing forms) pass as they are.
	 *
	 * @param mixed $key   Raw key param.
	 * @param mixed $value Raw value param.
	 * @return array{ids:int[],value:mixed}|WP_Error|null
	 */
	public static function canonicalize_post_meta_value( $key, $value ) {
		if ( ! is_string( $key ) ) {
			return null;
		}
		$shapes = self::get_post_ref_meta_value_keys();
		$key    = strtolower( trim( $key ) );
		if ( ! isset( $shapes[ $key ] ) ) {
			return null;
		}
		$shape   = $shapes[ $key ];
		$value   = self::decode_post_meta_value( $value );
		$invalid = new WP_Error( 'invalid_post_ref', __( 'Post ids must be positive integers.', 'mcpwp' ), array( 'status' => 400 ) );

		if ( null === $value ) {
			return array( 'ids' => array(), 'value' => null );
		}
		if ( 'scalar' === $shape ) {
			if ( '' === $value || 0 === $value || '0' === $value ) {
				return array( 'ids' => array(), 'value' => $value );
			}
			$id = self::canonical_post_id_token( $value );
			return null === $id ? $invalid : array( 'ids' => array( $id ), 'value' => $id );
		}

		if ( is_array( $value ) ) {
			$parts = array_values( $value );
		} elseif ( is_string( $value ) ) {
			$parts = '' === trim( $value ) ? array() : explode( ',', $value );
		} else {
			$parts = array( $value );
		}
		if ( count( $parts ) > self::post_ref_batch_cap() ) {
			return new WP_Error(
				'too_many_ids',
				sprintf(
					/* translators: %d: cap */
					__( 'A single request may name at most %d posts.', 'mcpwp' ),
					self::post_ref_batch_cap()
				),
				array( 'status' => 400 )
			);
		}
		$ids = array();
		foreach ( $parts as $part ) {
			$id = self::canonical_post_id_token( $part );
			if ( null === $id ) {
				return $invalid;
			}
			$ids[] = $id;
		}
		return array( 'ids' => $ids, 'value' => 'csv' === $shape ? implode( ',', $ids ) : $ids );
	}

	/**
	 * The post ids a /post-meta write names (see canonicalize_post_meta_value()),
	 * empty when the key is not an id meta, or a WP_Error.
	 *
	 * @param mixed $key   Raw key param.
	 * @param mixed $value Raw value param.
	 * @return int[]|WP_Error
	 */
	public static function post_ref_meta_ids( $key, $value ) {
		$canonical = self::canonicalize_post_meta_value( $key, $value );
		if ( null === $canonical ) {
			return array();
		}
		return is_wp_error( $canonical ) ? $canonical : $canonical['ids'];
	}

	/**
	 * Whether a nav-menu item payload's object_id names a post (see the
	 * /menus special case in collect_post_refs()).
	 *
	 * @param array $item Item payload.
	 * @return bool
	 */
	public static function menu_item_names_post( array $item ) {
		return self::menu_item_type_names_post( $item )
			&& isset( $item['object_id'] ) && is_numeric( $item['object_id'] ) && (int) $item['object_id'] > 0;
	}

	/**
	 * Resolve post ids to {post_type, post_status} with ONE direct query
	 * (#1030 round-5, BLOCK-2). Never WP_Query with post_type/post_status
	 * 'any': 'any' silently drops every exclude_from_search type
	 * (shop_coupon, shop_order, product_variation, elementor_library,
	 * elementor_snippet...) and the trash/auto-draft statuses, so those ids
	 * read as "missing" to the guard and were never category-checked at
	 * all while the door itself resolved them with get_post() and wrote to
	 * them. A row that exists is a row that is checked, whatever its type
	 * or status.
	 *
	 * @param int[] $ids Positive integer ids.
	 * @return array<int,array{post_type:string,post_status:string}> Keyed by id; missing ids absent.
	 */
	protected static function fetch_post_ref_rows( array $ids ) {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static function ( $id ) {
			return $id > 0;
		} ) ) );
		if ( empty( $ids ) || ! is_object( $wpdb ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is only '%d' tokens; every value is an int bound through prepare().
		$sql  = $wpdb->prepare( "SELECT ID, post_type, post_status FROM {$wpdb->posts} WHERE ID IN ({$placeholders})", $ids );
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one bounded lookup per request.

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$row = (array) $row;
			if ( ! isset( $row['ID'] ) ) {
				continue;
			}
			$out[ (int) $row['ID'] ] = array(
				'post_type'   => (string) ( $row['post_type'] ?? '' ),
				'post_status' => (string) ( $row['post_status'] ?? '' ),
			);
		}

		return $out;
	}

	/**
	 * Whether a resolved post reference must read, to THIS caller, exactly
	 * like an id that does not exist. The one definition every scalar
	 * guard decision and every bulk item decision shares:
	 *  - no row at all (missing);
	 *  - non-public status the caller may not see (the #1026 parity rule —
	 *    a read-only key must not tell a hidden draft from a missing id,
	 *    which it could the moment a missing id got the guard's answer and
	 *    a draft kept the door's). 'inherit' (attachments, revisions)
	 *    carries no visibility of its own and is left to the door;
	 *  - a post type whose category the caller cannot read (#1024) — post
	 *    and page excepted, which every door governs by its own route
	 *    category (the designer role holds elementor without content).
	 *
	 * @param int        $id             Post id.
	 * @param array|null $row            fetch_post_ref_rows() entry, null when missing.
	 * @param string     $route_category The resolving route's category.
	 * @return bool
	 */
	protected function post_ref_is_hidden( $id, $row, $route_category ) {
		if ( ! is_array( $row ) ) {
			return true;
		}

		if ( 'inherit' !== $row['post_status'] ) {
			if ( $this->requester_is_webmcp() ) {
				// A WebMCP user (#981) reads what WordPress lets them read —
				// per post, whatever the status (requester_can_read_post()).
				if ( ! current_user_can( 'read_post', (int) $id ) ) {
					return true;
				}
			} elseif ( $this->requester_oauth_user_id() > 0 ) {
				// OAuth acting as a user (PR-2 P0): WordPress decides, and
				// a non-public post still needs write scope.
				if ( ! current_user_can( 'read_post', (int) $id ) ) {
					return true;
				}
				if ( ! $this->is_public_status( $row['post_status'] ) && ! $this->key_has_scope( $this->get_current_api_key_record(), 'write' ) ) {
					return true;
				}
			} elseif ( ! $this->is_public_status( $row['post_status'] ) && ! $this->requester_can_see_non_public() ) {
				return true;
			}
		} elseif ( 'attachment' === $row['post_type'] && ( $this->requester_is_webmcp() || $this->requester_oauth_user_id() > 0 ) && ! current_user_can( 'read_post', (int) $id ) ) {
			// #1101 PR-3c: an attachment carries no visibility of its own, but
			// for a user-run principal WordPress still answers read_post for it
			// through its parent (an unattached file reads as published; one
			// attached to another user's draft does not).  Without this, a
			// featured_image / media_id reference would never be checked.
			return true;
		}

		if ( in_array( $row['post_type'], array( 'post', 'page' ), true ) ) {
			return false;
		}

		return ! $this->caller_may_read_category( self::category_for_post_ref( (int) $id, $row['post_type'], $route_category ) );
	}

	/**
	 * Whether a resolved row is a revision, or any non-attachment post with
	 * the 'inherit' status (see get_post_ref_resolution()).
	 *
	 * @param array|null $row Resolved row.
	 * @return bool
	 */
	protected static function post_ref_row_is_hidden_inherit( $row ) {
		if ( ! is_array( $row ) ) {
			return false;
		}
		return 'revision' === $row['post_type'] || ( 'inherit' === $row['post_status'] && 'attachment' !== $row['post_type'] );
	}

	/**
	 * The request-scoped resolution of every declared post reference:
	 * one query for all of them, memoized per request object so the guard
	 * and every bulk handler read the SAME answer (and a handler called
	 * without the guard in front of it still gets one).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array{refs:array|null,rows:array,hidden:array<int,bool>}|WP_Error
	 */
	protected function get_post_ref_resolution( $request ) {
		if ( ! $this->post_ref_resolutions instanceof SplObjectStorage ) {
			$this->post_ref_resolutions = new SplObjectStorage();
		}
		if ( is_object( $request ) && $this->post_ref_resolutions->contains( $request ) ) {
			return $this->post_ref_resolutions[ $request ];
		}

		$refs = $this->collect_post_refs( $request );
		if ( is_wp_error( $refs ) ) {
			return $refs;
		}

		$resolution = array(
			'refs'   => $refs,
			'rows'   => array(),
			'hidden' => array(),
		);

		if ( null !== $refs ) {
			$ids  = array_values( array_unique( array_merge( $refs['scalar'], $refs['items'], $refs['read'] ) ) );
			$rows = self::fetch_post_ref_rows( $ids );
			foreach ( $ids as $id ) {
				$resolution['hidden'][ $id ] = $this->post_ref_is_hidden( $id, $rows[ $id ] ?? null, $refs['route_category'] );
			}
			// #1118 scope cut, P0: a READ or SOURCE ref naming a revision — or
			// any other non-attachment 'inherit' post — is hidden for a
			// user-run principal. A revision carries its parent's full body
			// but no visibility of its own; post_ref_is_hidden() checks
			// read_post only for attachments, so cloning a revision of
			// someone's draft copied the draft. Attachments keep read_post;
			// bots are unchanged.
			if ( $this->requester_acts_as_user() ) {
				foreach ( $refs['read'] as $id ) {
					if ( self::post_ref_row_is_hidden_inherit( $rows[ $id ] ?? null ) ) {
						$resolution['hidden'][ $id ] = true;
					}
				}
			}
			$resolution['rows'] = $rows;
		}

		if ( is_object( $request ) ) {
			$this->post_ref_resolutions[ $request ] = $resolution;
		}

		return $resolution;
	}

	/**
	 * The chokepoint for declared post references (#1024/#1030, round-5
	 * design — coordinator decision, binding):
	 *
	 *  - SCALAR declared params (the {id} in /pages/{id}, source_id, ...):
	 *    the guard owns BOTH outcomes. It resolves the id itself and, when
	 *    the post does not exist OR is hidden from this caller
	 *    (post_ref_is_hidden()), answers with the ONE canonical error,
	 *    post_ref_not_found_error(). A door never gets to answer a missing
	 *    declared scalar id, so "missing" and "gated" cannot differ by
	 *    status, message or hint on any declared door — by construction,
	 *    not by keeping dozens of per-door messages in step.
	 *  - PER-ITEM declared params (ids[], updates[].id, ids:csv, id:item):
	 *    the guard does NOT refuse the request for one hidden item. It
	 *    caps the list (400 over post_ref_batch_cap(), before any lookup),
	 *    resolves every id in the same single query, and records which are
	 *    hidden; each bulk handler then asks post_ref_item_visible() per
	 *    item and gives a hidden item exactly the entry it gives a missing
	 *    one.
	 *
	 * #1032 round 3: this is also the SINGLE tail every verdict-computing
	 * path in this trait calls last and returns directly —
	 * compute_api_key_verdict()'s own local-key path, authenticate_webmcp_principal(),
	 * verify_api_key_auth_only() (the /batch and /mcp sub-request fast path),
	 * authenticate_oauth_access_token() and authenticate_broker_bearer_token().
	 * Chaining check_request_template_refs() here, rather than at each of
	 * those five call sites, is deliberate: a sixth verdict path added later
	 * inherits the gate for free instead of silently missing it the way
	 * authenticate_webmcp_principal() and the batch/MCP/OAuth/broker paths
	 * did (round-3 adversarial review — the request-body-wide template-ref
	 * scan ran only on the plain-X-API-Key path, so every other identity
	 * type, and every batch/MCP inner sub-request regardless of identity
	 * type, could still smuggle a gated Template Library reference through).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return true|WP_Error
	 */
	protected function check_post_naming_params_category( $request ) {
		// #1100: the WordPress capability a user-run principal needs for this
		// route (Mcpwp_Route_Capability_Map). This is the tail every
		// verdict-computing path ends in, so the check runs on the main and
		// the sub-request path alike; it is a no-op for bot-run principals.
		$capability_check = $this->check_gate_entry( $request );
		if ( is_wp_error( $capability_check ) ) {
			return $capability_check;
		}

		$resolution = $this->get_post_ref_resolution( $request );
		if ( is_wp_error( $resolution ) ) {
			return $resolution;
		}

		if ( null !== $resolution['refs'] ) {
			// Scalar write refs and every read-only ref (#1101 PR-3c) answer
			// a hidden id with the canonical 404 — for a user-run principal
			// "hidden" includes read_post false, so another user's draft
			// reads exactly like a missing id.
			foreach ( array_merge( $resolution['refs']['scalar'], $resolution['refs']['read'] ) as $id ) {
				if ( ! empty( $resolution['hidden'][ $id ] ) ) {
					return $this->post_ref_not_found_error( $request );
				}
			}

			// PR-2 P0: an OAuth token acting as a user may change only posts
			// WordPress lets that user change.  MCPWP's write handlers call
			// wp_update_post()/wp_delete_post(), which check no capability,
			// so running as the user is not enough on its own — this is the
			// one chokepoint every declared post reference passes.  Visible-
			// but-not-editable is a 403, not a 404: the user can already
			// read the post, so its existence is no secret.
			//
			// Per-item refs (ids[], updates[].id, posts[].id, ids:csv …) are
			// checked too, and ANY failing id fails the WHOLE request — nothing
			// is partially applied.  A missing or hidden id fails the same way
			// (WordPress answers edit_post false for both), so the 403 is no
			// existence oracle.
			$edit_check = $this->check_oauth_user_can_change_post_refs( $request, array_merge( $resolution['refs']['scalar'], $resolution['refs']['items'] ) );
			if ( is_wp_error( $edit_check ) ) {
				return $edit_check;
			}
		}

		// The generic post doors' post-type rule, for EVERY principal (bots:
		// admin scope for types other than post/page; users: the type's own
		// capability). After the hidden-reference checks above, so it can
		// never tell a hidden post from a missing one.
		$post_type_check = $this->check_generic_post_type_access( $request );
		if ( is_wp_error( $post_type_check ) ) {
			return $post_type_check;
		}

		return $this->check_request_template_refs( $request );
	}

	/**
	 * Per-post write check for an OAuth token acting as a user (PR-2 P0).
	 *
	 * Applies to every non-GET/HEAD request carrying declared scalar post
	 * references: DELETE needs delete_post, anything else edit_post.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param int[]           $ids     Declared scalar post ids.
	 * @return true|WP_Error
	 */
	protected function check_oauth_user_can_change_post_refs( $request, $ids ) {
		if ( $this->requester_oauth_user_id() <= 0 || empty( $ids ) ) {
			return true;
		}
		$method = is_object( $request ) && method_exists( $request, 'get_method' ) ? strtoupper( (string) $request->get_method() ) : 'GET';
		if ( in_array( $method, array( 'GET', 'HEAD', 'OPTIONS' ), true ) ) {
			return true;
		}
		$cap = 'DELETE' === $method ? 'delete_post' : 'edit_post';
		foreach ( (array) $ids as $id ) {
			if ( (int) $id > 0 && ! current_user_can( $cap, (int) $id ) ) {
				return new WP_Error(
					'rest_cannot_edit',
					__( 'Your WordPress account is not allowed to change this content.', 'mcpwp' ),
					array( 'status' => 403 )
				);
			}
		}
		return true;
	}

	/**
	 * The ONE per-item resolver every bulk handler uses (#1030 round-5,
	 * BLOCK-1/BLOCK-2): false when $id is missing OR hidden from this
	 * caller, so the handler's own "missing item" entry is the only answer
	 * a hidden item can ever get. An id the request never declared (a
	 * handler resolving something extra) is resolved on the spot through
	 * the same query and the same rule.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @param int             $id      Item post id.
	 * @return bool True when the handler may treat the post as existing.
	 */
	protected function post_ref_item_visible( $request, $id ) {
		$id = (int) $id;
		if ( $id <= 0 ) {
			return false;
		}

		$resolution = $this->get_post_ref_resolution( $request );
		if ( ! is_wp_error( $resolution ) && isset( $resolution['hidden'][ $id ] ) ) {
			return ! $resolution['hidden'][ $id ];
		}

		$route_category = ( ! is_wp_error( $resolution ) && null !== $resolution['refs'] )
			? $resolution['refs']['route_category']
			: ( is_object( $request ) && method_exists( $request, 'get_route' ) ? (string) self::get_category_for_route( $this->normalize_route_for_authorization( (string) $request->get_route() ) ) : '' );
		$rows = self::fetch_post_ref_rows( array( $id ) );

		return ! $this->post_ref_is_hidden( $id, $rows[ $id ] ?? null, $route_category );
	}

	/**
	 * Canonical not-found message (see post_ref_not_found_error()).
	 *
	 * @return string
	 */
	public static function post_ref_not_found_message() {
		return __( 'The requested resource was not found.', 'mcpwp' );
	}

	/**
	 * Canonical not-found hint (see post_ref_not_found_error()). Fixed text:
	 * it never echoes the id, so it cannot differ between two ids.
	 *
	 * @return string
	 */
	public static function post_ref_not_found_hint() {
		return __( 'Check the id with wp_search or wp_list_posts.', 'mcpwp' );
	}

	/**
	 * Whether an error code/message pair IS the canonical post-ref
	 * not-found error. error_response() (and so
	 * error_response_from_wp_error()) and build_not_found_error() use this
	 * to hand the canonical error back unchanged instead of letting
	 * Mcpwp_Error_Hints swap in its id-echoing 'not_found' hint — the
	 * round-4 BLOCK-1 finding: a handler returning the "same" error still
	 * answered differently from the permission layer purely by hint.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @return bool
	 */
	public static function is_post_ref_not_found( $code, $message ) {
		return 'not_found' === $code && self::post_ref_not_found_message() === $message;
	}

	/**
	 * The ONE canonical error for a declared post reference that is missing
	 * or hidden from the caller: status 404, code not_found, fixed
	 * message, fixed hint, data.status 404, nothing echoing the id. Built
	 * here and only here; whether it leaves through the permission layer or
	 * a handler, the client receives these exact bytes.
	 *
	 * @param WP_REST_Request|null $request Request object (unused).
	 * @return WP_Error
	 */
	protected function post_ref_not_found_error( $request = null ) {
		return new WP_Error(
			'not_found',
			self::post_ref_not_found_message(),
			array(
				'status' => 404,
				'hint'   => self::post_ref_not_found_hint(),
			)
		);
	}

	/**
	 * Canonical hint for a Template Library reference specifically (#1032
	 * round 2, false-positives NIT): the id in question is never a
	 * DECLARED request parameter (post_ref_not_found_hint()'s own
	 * "wp_search or wp_list_posts" advice assumes the caller can see and
	 * fix the id they passed), so a caller reading this hint needs to know
	 * WHAT kind of reference was refused, not how to look up an id they may
	 * never have typed themselves — it could be embedded three encodings
	 * deep inside a widget setting or a dynamic tag. Deliberately the SAME
	 * fixed text whether the referenced id is missing or merely unreadable
	 * to this caller — the existence-oracle rule post_ref_not_found_hint()
	 * already enforces for a declared param, extended here.
	 *
	 * @return string
	 */
	public static function template_ref_not_found_hint() {
		return __( 'A referenced template was not found.', 'mcpwp' );
	}

	/**
	 * The canonical refusal for a Template Library reference (#1032):
	 * post_ref_not_found_error()'s exact code/status/message — so
	 * is_post_ref_not_found() still recognises it and no hint-injection
	 * layer downstream overrides what is set here — with
	 * template_ref_not_found_hint()'s own hint in place of the generic
	 * "check the id" one. Used by template_ref_gate() and
	 * check_request_template_refs() so every template-reference refusal,
	 * wherever the id was found, reads identically.
	 *
	 * @return WP_Error
	 */
	protected function template_ref_not_found_error() {
		return new WP_Error(
			'not_found',
			self::post_ref_not_found_message(),
			array(
				'status' => 404,
				'hint'   => self::template_ref_not_found_hint(),
			)
		);
	}

	/**
	 * Per-(id,category) memo for post_ref_readable() (#1032 adversarial
	 * review round 1, NIT): a page can embed — or render through — the same
	 * template id from more than one widget, and a request-wide scan
	 * (template_ref_gate()'s own batched fetch below already answers a whole
	 * write in one query; this catches the read side, where each nested
	 * document is discovered one at a time as Elementor renders it). A trait
	 * static is a separate copy per COMPOSING CLASS, not truly global — still
	 * enough to dedupe the common case (many references to the same one or
	 * two templates within a single controller's handling of one request)
	 * without claiming a guarantee this data shape cannot give.
	 *
	 * @var array<string,bool>
	 */
	private static $post_ref_readable_memo = array();

	/**
	 * Clear post_ref_readable()'s memo. Production never needs this — the
	 * memo lives no longer than one PHP request/process, exactly like
	 * $mcpwp_verdicts above. A test SUITE process, in contrast, calls
	 * post_ref_readable() for the SAME id under different forced key records
	 * across many tests, so a test that needs to guarantee it is not reading
	 * a prior test's cached verdict calls this from setUp().
	 *
	 * @return void
	 */
	public static function reset_post_ref_readable_memo() {
		self::$post_ref_readable_memo = array();
	}

	/**
	 * Whether $id — resolved fresh, on the spot, NOT from a declared request
	 * param — must read to this caller exactly like a missing post (#1032).
	 *
	 * Every declared scalar/per-item post reference (collect_post_refs()'s
	 * own param map) is judged by post_ref_is_hidden() already. This is the
	 * same judgment for an id that never appears as a request PARAMETER at
	 * all because it is embedded a level deeper — inside the JSON tree a
	 * write submits (an Elementor Template/Global widget's own
	 * template_id/templateID, Mcpwp_Elementor_Template_Refs' whole reason
	 * for existing) or inside a stored document Elementor is about to render
	 * for a read (a nested template a Template/Global widget, a Loop
	 * Grid/Carousel, or the `[elementor-template]` shortcode resolves at
	 * render time). Both call sites need the identical existence-oracle
	 * answer post_ref_is_hidden() already gives declared params, so this is
	 * that same rule, callable for an id discovered by a different route.
	 *
	 * @param int    $id             Candidate post id.
	 * @param string $route_category The category to judge $id under
	 *                                (category_for_post_ref()'s own input) —
	 *                                callers checking an EMBEDDED template
	 *                                reference should pass 'elementor-theme'
	 *                                (see template_ref_gate()'s docblock for
	 *                                why), not whatever route resolved the
	 *                                outer request.
	 * @return bool True when the caller may treat $id as existing and readable.
	 */
	public function post_ref_readable( $id, $route_category ) {
		$id = (int) $id;
		if ( $id <= 0 ) {
			return true; // Not a real reference — nothing to hide.
		}

		$route_category = (string) $route_category;
		$memo_key       = $id . ':' . $route_category;
		if ( array_key_exists( $memo_key, self::$post_ref_readable_memo ) ) {
			return self::$post_ref_readable_memo[ $memo_key ];
		}

		$rows                                      = self::fetch_post_ref_rows( array( $id ) );
		$result                                    = ! $this->post_ref_is_hidden( $id, $rows[ $id ] ?? null, $route_category );
		self::$post_ref_readable_memo[ $memo_key ] = $result;

		return $result;
	}

	/**
	 * Build a callable gate for embedded Elementor Template-Library
	 * references (#1032): given the candidate ids
	 * Mcpwp_Elementor_Template_Refs::find_ids()/ids_in_text() turned up in a
	 * submitted element tree or post_content, refuse the WHOLE write with the
	 * canonical post-ref not-found error the moment any one of them is
	 * missing or hidden from this caller — same body for both, matching
	 * every other declared post reference (post_ref_not_found_error()'s own
	 * rule). One query for every id in the batch (adversarial review round 1
	 * NIT), not one per id.
	 *
	 * Always judges a referenced id under the 'elementor-theme' category,
	 * never the resolving route's own — a Loop Grid can legitimately embed a
	 * `loop-item` theme-builder template (Elementor Pro's own per-post-type
	 * loop skin) through the perfectly ordinary /elementor/{id} door;
	 * category_for_post_ref() only re-checks a referenced post's OWN
	 * `_elementor_template_type` for a theme-location match when its
	 * $route_category argument is already 'elementor-theme', so passing the
	 * outer route's real category here (e.g. plain 'elementor') wrongly
	 * classified every such reference as 'elementor-templates' and refused a
	 * key that holds 'elementor-theme' but not 'elementor-templates'
	 * (adversarial review round 1 NIT — a false positive, not a security
	 * gap, but a real regression for Theme Builder-only keys). A reference to
	 * an ordinary, non-theme-location template is unaffected either way: it
	 * still falls through to category_for_post_type() = 'elementor-templates'.
	 *
	 * Injected into the business-logic write paths (Mcpwp_Elementor_Basic,
	 * Mcpwp_Elementor_Pro, Mcpwp_Page_Builder, Mcpwp_Theme_Builder — none of
	 * which have REST/auth context of their own) via
	 * set_template_ref_gate(), the same "caller decides, callee applies the
	 * verdict" split set_category_allowed_callback() already uses for the
	 * post-type category check. A REST controller with direct trait access
	 * can instead call refuse_if_template_refs_unreadable() below.
	 *
	 * @return callable function(int[] $ids): true|WP_Error
	 */
	public function template_ref_gate() {
		return function ( array $ids ) {
			$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static function ( $id ) {
				return $id > 0;
			} ) ) );
			if ( empty( $ids ) ) {
				return true;
			}

			$rows = self::fetch_post_ref_rows( $ids );
			foreach ( $ids as $id ) {
				if ( $this->post_ref_is_hidden( $id, $rows[ $id ] ?? null, 'elementor-theme' ) ) {
					return $this->template_ref_not_found_error();
				}
			}

			return true;
		};
	}

	/**
	 * template_ref_gate()'s SAME shape — function(int[] $ids): true|WP_Error —
	 * but judged against a PAST principal's stored key id rather than the
	 * current caller (#1032 round 3). Built for exactly one caller:
	 * Mcpwp_REST_Approvals::apply_approval() re-scanning a post_content_update
	 * payload at APPLY time against the identity that PROPOSED it
	 * (`requested_by_key_id`), never the admin applying it — see
	 * requester_may_read_category()'s own docblock for why that distinction
	 * is the entire point.
	 *
	 * Deliberately narrower than post_ref_is_hidden(): it judges only the
	 * resolved post's CATEGORY (requester_may_read_category(), which also
	 * honours the site-wide disabled-category toggle) and whether a row
	 * exists at all. It does not attempt to reconstruct the requester's
	 * draft/private-post visibility the way post_ref_is_hidden() does for a
	 * live request — there is no live request to ask, only a stored key id —
	 * so a Template Library reference is judged purely on category here.
	 * That is the axis #1032 is about (a category the requester's role never
	 * had), and it is the axis get_current_api_key_record()'s replacement
	 * would otherwise silently skip.
	 *
	 * @param string $requester_key_id Stored requested_by_key_id (may be '').
	 * @return callable function(int[] $ids): true|WP_Error
	 */
	public function requester_template_ref_gate( $requester_key_id ) {
		$requester_key_id = (string) $requester_key_id;

		return function ( array $ids ) use ( $requester_key_id ) {
			$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static function ( $id ) {
				return $id > 0;
			} ) ) );
			if ( empty( $ids ) ) {
				return true;
			}

			$rows = self::fetch_post_ref_rows( $ids );
			foreach ( $ids as $id ) {
				$row = $rows[ $id ] ?? null;
				if ( ! is_array( $row ) ) {
					return $this->template_ref_not_found_error();
				}

				$category = self::category_for_post_ref( $id, $row['post_type'], 'elementor-theme' );
				if ( ! $this->requester_may_read_category( $requester_key_id, $category ) ) {
					return $this->template_ref_not_found_error();
				}
			}

			return true;
		};
	}

	/**
	 * refuse_if_template_refs_unreadable()'s own logic, exposed as a bare
	 * callable for callers that inject a gate into ANOTHER object rather
	 * than asking this one directly (Mcpwp_Elementor_Basic et al. via
	 * set_template_ref_gate()) — see template_ref_gate()'s docblock for the
	 * full rationale, identical here.
	 *
	 * Pre-existing-reference exemption (#1032 round 2, false-positives NIT —
	 * see Mcpwp_Elementor_Validator_Trait::check_template_references()'s
	 * matching docblock for the full rationale): find/replace scans the
	 * WHOLE resulting tree, so a page already carrying a gated reference the
	 * replacement never touched must not lock the caller out of a find/
	 * replace that changes something else entirely. $preexisting_elements —
	 * the tree as it stood BEFORE this write, which every find/replace
	 * caller already holds in memory before running the replacement — is
	 * scanned the same way, and its own ids are subtracted from what
	 * $elements turns up before judging what remains.
	 *
	 * @param array      $elements             Element tree about to be written.
	 * @param array|null $preexisting_elements The SAME tree as it stood
	 *                                          before this write, or null
	 *                                          when there is no "before" to
	 *                                          exempt against (a brand new
	 *                                          post).
	 * @return true|WP_Error
	 */
	public function refuse_if_template_refs_unreadable( array $elements, array $preexisting_elements = null ) {
		if ( ! class_exists( 'Mcpwp_Elementor_Template_Refs' ) ) {
			return true;
		}

		$ids = Mcpwp_Elementor_Template_Refs::find_ids( $elements );
		if ( empty( $ids ) ) {
			return true;
		}

		if ( null !== $preexisting_elements ) {
			$preexisting = Mcpwp_Elementor_Template_Refs::find_ids( $preexisting_elements );
			$ids         = array_values( array_diff( $ids, $preexisting ) );
			if ( empty( $ids ) ) {
				return true;
			}
		}

		return call_user_func( $this->template_ref_gate(), $ids );
	}

	/**
	 * Same refusal as refuse_if_template_refs_unreadable(), for a plain
	 * string (a post's post_content, or any other free text a write can set)
	 * rather than an Elementor element tree — the `[elementor-template
	 * id="N"]` shortcode is reachable from EITHER (#1032 B3): a caller never
	 * needs to touch Elementor's own data at all to embed a reference, a
	 * classic/Gutenberg post's post_content is a shortcode-processing
	 * surface in its own right.
	 *
	 * @param string $content Free text to scan.
	 * @return true|WP_Error
	 */
	public function refuse_if_content_has_unreadable_template_shortcode( $content ) {
		if ( ! is_string( $content ) || '' === $content || ! class_exists( 'Mcpwp_Elementor_Template_Refs' ) ) {
			return true;
		}

		$ids = Mcpwp_Elementor_Template_Refs::ids_in_text( $content );
		if ( empty( $ids ) ) {
			return true;
		}

		return call_user_func( $this->template_ref_gate(), $ids );
	}

	/**
	 * Build a callable gate for a NESTED document Elementor resolves while
	 * rendering one already-authorized post (#1032's read side): true when
	 * $doc_post_id may be expanded, false when the read must see an empty
	 * placeholder instead — never a failed request, since the post being
	 * READ is not itself in question, only whether one of the templates it
	 * embeds may be shown to this caller. Always judges $doc_post_id under
	 * 'elementor-theme' — see template_ref_gate()'s docblock for why.
	 *
	 * @return callable function(int $doc_post_id): bool
	 */
	public function template_render_gate() {
		return function ( $doc_post_id ) {
			return $this->post_ref_readable( $doc_post_id, 'elementor-theme' );
		};
	}

	/**
	 * Byte cap on the request body check_request_template_refs() below will
	 * scan (#1032 round 2) before refusing outright rather than let CPU cost
	 * scale with an attacker-controlled payload. Matches the JSON payload
	 * ceiling Mcpwp_Security::validate_json_payload() already uses for a
	 * full Elementor tree.
	 */
	protected static function template_ref_scan_max_bytes() {
		return 5 * 1024 * 1024;
	}

	/**
	 * Depth cap for the same scan — a value nested deeper than this is
	 * refused (413) rather than walked, the same "cost is bounded before
	 * work starts" rule the byte cap enforces.
	 */
	protected static function template_ref_scan_max_depth() {
		return 40;
	}

	/**
	 * The request-wide chokepoint (#1032 round 2, coordinator decision): a
	 * Template Library reference does not only show up as a declared
	 * post-ref parameter (collect_post_refs()) or inside an Elementor
	 * element tree (Mcpwp_Elementor_Template_Refs, the writer traits' own
	 * check) — round-1's adversarial review found it hiding in a classic
	 * sidebar Text widget, a WooCommerce product description, a Gutenberg
	 * block queued for approval, a content-graph anchor, a media
	 * description, and anywhere else a caller can put an arbitrary string.
	 * Rather than keep enumerating doors, this walks the ENTIRE decoded
	 * request body ONCE, for every route, the same way
	 * check_post_naming_params_category() already does for declared params —
	 * same chokepoint (called right after it, from the same
	 * compute_api_key_verdict() chain), same canonical refusal.
	 *
	 * Skips /mcp and /batch envelopes for the same reason
	 * collect_post_refs() does: each re-dispatches through rest_do_request(),
	 * which runs this exact check again on the REAL route with the REAL,
	 * already-unwrapped/normalised arguments (mcpwp_call's own unwrap and
	 * #911's param normalisation both happen before that inner dispatch) —
	 * scanning the outer envelope too would just re-walk the same bytes
	 * twice under a route this method cannot judge a category for anyway.
	 *
	 * WebMCP (#1038, merged), the /batch and /mcp sub-request fast path
	 * (verify_api_key_auth_only()), OAuth bearer callers and mumcp.com
	 * broker callers: none of these call this method directly. All five
	 * verdict-computing paths in this trait share ONE tail,
	 * check_post_naming_params_category(), and IT is what calls this method
	 * (see that method's own docblock) — round 3's adversarial review found
	 * that four of the five short-circuited compute_api_key_verdict() before
	 * ever reaching this method's OWN call site there, so the scan ran only
	 * for a plain local X-API-Key request. Chaining from the shared tail
	 * instead means a sixth verdict path added later inherits this gate for
	 * free rather than silently missing it the same way.
	 *
	 * Pre-existing-reference exemption (#1032 round 2, NIT — avoids a
	 * designer lock-out without opening a hole): a reference already
	 * present on the request's OWN declared target post(s) — before this
	 * write touches anything — is not re-judged. Only ids newly introduced
	 * BY this request's payload are checked. An admin-built page that
	 * already carries a reference a designer key cannot read stays editable
	 * by that designer for everything else on the page; the designer simply
	 * cannot ADD a new one they cannot themselves read back.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return true|WP_Error
	 */
	public function check_request_template_refs( $request ) {
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
			return true;
		}

		$route = $this->normalize_route_for_authorization( (string) $request->get_route() );
		if ( $this->route_matches( $route, '/mcpwp/v1/mcp' ) || $this->route_matches( $route, '/mcpwp/v1/batch' ) ) {
			return true;
		}

		if ( ! class_exists( 'Mcpwp_Elementor_Template_Refs' ) ) {
			return true;
		}

		// #1032 round 3 NIT: a GET/HEAD request never persists anything, so
		// there is nothing here for a NEW reference to be smuggled INTO — the
		// read side is guarded separately (render_with_template_gate()'s
		// nested-doc redaction, install_template_shortcode_guard()'s
		// pre_do_shortcode_tag filter). Scanning a GET's query string bought
		// no security and only risked a false-positive refusal on an
		// unrelated `?search=` term that happens to look like a shortcode.
		// The #1024 declared-param check this method is chained after
		// (check_post_naming_params_category()) still runs for every method.
		$http_method = method_exists( $request, 'get_method' ) ? strtoupper( (string) $request->get_method() ) : 'POST';
		if ( in_array( $http_method, array( 'GET', 'HEAD' ), true ) ) {
			return true;
		}

		$params = $this->gate_params( $request );
		if ( empty( $params ) ) {
			return true;
		}

		$size_estimate = strlen( (string) wp_json_encode( $params ) );
		if ( $size_estimate > self::template_ref_scan_max_bytes() ) {
			return new WP_Error(
				'payload_too_large',
				__( 'Request body is too large to scan for template references.', 'mcpwp' ),
				array( 'status' => 413 )
			);
		}

		$ids = Mcpwp_Elementor_Template_Refs::find_ids_in_value(
			$params,
			self::template_ref_scan_max_depth()
		);
		if ( is_wp_error( $ids ) ) {
			return $ids;
		}
		if ( empty( $ids ) ) {
			return true;
		}

		$preexisting = $this->preexisting_template_ref_ids( $request );
		$new_ids     = array_values( array_diff( $ids, $preexisting ) );
		if ( empty( $new_ids ) ) {
			return true;
		}

		$rows = self::fetch_post_ref_rows( $new_ids );
		foreach ( $new_ids as $id ) {
			if ( $this->post_ref_is_hidden( $id, $rows[ $id ] ?? null, 'elementor-theme' ) ) {
				return $this->template_ref_not_found_error();
			}
		}

		return true;
	}

	/**
	 * Per-route override for preexisting_template_ref_ids(): the subset of
	 * get_post_ref_param_map()'s SCALAR param names, for a given route
	 * prefix, that identify a post whose CONTENT this request is actually
	 * about to write — as opposed to a scalar param resolved only so the
	 * #1024 guard can confirm the caller may see it (a link target read for
	 * its URL/title, a context post). A route absent here exempts every
	 * declared scalar param's stored refs (unchanged behaviour); that is
	 * correct for the overwhelming majority of routes, where the single
	 * declared scalar IS the post being written, or (a clone's source_id)
	 * becomes the written post's content verbatim.
	 *
	 * #1032 round 3 (adversarial review): /content-graph/apply-link is the
	 * route this mattered for in practice. apply_internal_link() reads
	 * target_id only for get_permalink()/get_the_title() — it never writes
	 * to it — while an attacker-controlled `anchor` string (wp_strip_all_tags()
	 * does not touch a bracket shortcode) is appended to source_id's
	 * post_content. Exempting target_id's own stored template refs let an
	 * unrelated, legitimately-pre-existing reference on the TARGET post
	 * launder a brand-new `[elementor-template id="…"]` the request was
	 * writing into the SOURCE post's content.
	 *
	 * @return array<string,array<string>> Route prefix => written scalar param names.
	 */
	protected static function written_post_ref_scalar_overrides() {
		return array(
			'/content-graph/apply-link'  => array( 'source_id' ),
			'/content-graph/suggestions' => array(), // Read-only: nothing is written.
		);
	}

	/**
	 * The post ids this request is actually about to write to, for
	 * preexisting_template_ref_ids()'s exemption set: every per-item id
	 * (a bulk update touches each one) plus whichever declared scalar ids
	 * written_post_ref_scalar_overrides() says are write targets for this
	 * route (or, absent an override, every declared scalar id — see that
	 * method's own docblock).
	 *
	 * @param WP_REST_Request $request    Request object.
	 * @param array            $refs       collect_post_refs()'s resolved 'scalar'/'items' arrays.
	 * @return int[]
	 */
	protected function written_post_ref_target_ids( $request, array $refs ) {
		// #1101 PR-3c: read-only refs count here by default as before — a
		// clone's or landing page's source is copied into the written post
		// verbatim, so its stored refs were already exempt; the per-route
		// override below is what excludes a link target.
		$scalar = array_values( array_unique( array_merge( $refs['scalar'], $refs['source'] ?? array() ) ) );

		if ( is_object( $request ) && method_exists( $request, 'get_route' ) ) {
			$route = $this->normalize_route_for_authorization( (string) $request->get_route() );
			$path  = ( 0 === strpos( $route, '/mcpwp/v1' ) ) ? substr( $route, strlen( '/mcpwp/v1' ) ) : $route;

			foreach ( self::written_post_ref_scalar_overrides() as $prefix => $written_specs ) {
				if ( $path === $prefix || 0 === strpos( $path, $prefix . '/' ) ) {
					$params = $this->gate_params( $request );
					$params = is_array( $params ) ? $params : array();

					$scalar = array();
					foreach ( $written_specs as $spec ) {
						foreach ( $this->extract_post_ref_values( $params, $spec ) as $value ) {
							if ( is_numeric( $value ) && (int) $value > 0 ) {
								$scalar[] = (int) $value;
							}
						}
					}
					break;
				}
			}
		}

		return array_values( array_unique( array_merge( $scalar, $refs['items'] ) ) );
	}

	/**
	 * The Template Library ids already present on this request's declared
	 * target post(s), BEFORE this write runs — the exemption set
	 * check_request_template_refs() subtracts from what it finds in the
	 * submitted payload (see that method's own docblock for why).
	 *
	 * Reuses collect_post_refs()'s own declared-param resolution (the SAME
	 * 'id'-shaped scalar param this route already resolves for the #1024
	 * chokepoint) rather than a second, route-specific mapping: whatever
	 * post this request is ABOUT is exactly the post whose CURRENT content
	 * defines "pre-existing". A route with no declared post-ref param (a
	 * pure create, e.g. POST /posts) has no pre-existing content to exempt
	 * anything against, so every reference the payload introduces is new by
	 * definition — the empty array this returns is the correct exemption
	 * set, not a gap. written_post_ref_target_ids() narrows the target set
	 * further, to posts this request actually writes (see its own docblock).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return int[] Ids already referenced by the target post(s)' stored
	 *               _elementor_data and post_content.
	 */
	protected function preexisting_template_ref_ids( $request ) {
		$resolution = $this->get_post_ref_resolution( $request );
		if ( is_wp_error( $resolution ) || null === $resolution['refs'] ) {
			return array();
		}

		$target_ids = $this->written_post_ref_target_ids( $request, $resolution['refs'] );
		if ( empty( $target_ids ) ) {
			return array();
		}

		$found = array();
		foreach ( $target_ids as $target_id ) {
			$row = $resolution['rows'][ $target_id ] ?? null;
			if ( ! is_array( $row ) ) {
				continue;
			}

			$elementor_raw = get_post_meta( $target_id, '_elementor_data', true );
			if ( ! empty( $elementor_raw ) && class_exists( 'Mcpwp_Elementor_Stored_Tree' ) ) {
				$stored   = Mcpwp_Elementor_Stored_Tree::read( $elementor_raw );
				$elements = $stored['elements'];
				if ( is_array( $elements ) ) {
					$found = array_merge( $found, Mcpwp_Elementor_Template_Refs::find_ids( $elements ) );
				}
			}

			$post = get_post( $target_id );
			if ( $post instanceof WP_Post && is_string( $post->post_content ) && '' !== $post->post_content ) {
				$found = array_merge( $found, Mcpwp_Elementor_Template_Refs::ids_in_text( $post->post_content ) );
			}
		}

		return array_values( array_unique( $found ) );
	}

	/**
	 * Verify API key without rate limiting (for batch sub-requests).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error True if valid, error otherwise.
	 */
	protected function verify_api_key_auth_only( $request ) {
		$api_key = $this->get_api_key_from_request( $request );

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'missing_api_key',
				__( 'API key is required.', 'mcpwp' ),
				array( 'status' => 401 )
			);
		}

		// Same credential-type dispatch as compute_api_key_verdict(), unmetered
		// (see authenticate_oauth_access_token()'s $metered param): an OAuth
		// bearer caller whose outer /mcp or /batch request already authenticated
		// must not be turned into "invalid_api_key" just because this fast path
		// only ever tried the scoped-key store (#902 review round 2 — the WARN
		// that follows applies here too: get_api_key_from_request() returns the
		// same string get_header() gave the outer request, so the token's own
		// shape ("mcpwp_at_"/"spai_at_" prefix) is preserved end to end).
		if ( $this->looks_like_oauth_access_token( $api_key ) ) {
			return $this->authenticate_oauth_access_token( $api_key, $request, false );
		}

		$matched_key = $this->find_scoped_api_key( $api_key );
		$legacy_key  = get_option( 'mcpwp_api_key' );

		if ( ! $matched_key && ! empty( $legacy_key ) && $this->legacy_api_key_option_is_honoured() && $this->is_api_key_match( $api_key, $legacy_key ) ) {
			$matched_key = $this->migrate_legacy_key_to_scoped_store( $legacy_key );
		}

		// Bridge: also accept keys from the old spai_* option store.
		if ( ! $matched_key ) {
			$matched_key = $this->find_legacy_spai_key( $api_key );
		}

		if ( ! $matched_key ) {
			// mumcp.com broker bearer token — same fallback and same ordering
			// compute_api_key_verdict() uses: only after every local key store
			// missed, only for a genuine Authorization: Bearer with no
			// X-API-Key, and only when the site is actively enrolled. Unmetered
			// for the same reason the OAuth branch above is.
			$auth_header        = method_exists( $request, 'get_header' ) ? $request->get_header( 'Authorization' ) : null;
			$has_bearer_header  = ! empty( $auth_header ) && 0 === strpos( (string) $auth_header, 'Bearer ' );
			$has_api_key_header = method_exists( $request, 'get_header' ) && ! empty( $request->get_header( 'X-API-Key' ) );

			if ( $has_bearer_header && ! $has_api_key_header && class_exists( 'Mcpwp_Broker_Auth' ) ) {
				$broker_auth = new Mcpwp_Broker_Auth();
				if ( $broker_auth->is_enrolled() ) {
					return $this->authenticate_broker_bearer_token( $api_key, $request, $broker_auth, false );
				}
			}

			return new WP_Error(
				'invalid_api_key',
				__( 'Invalid API key.', 'mcpwp' ),
				array(
					'status' => 401,
					'hint'   => self::invalid_api_key_hint(),
				)
			);
		}

		$this->mcpwp_audit_key_id = isset( $matched_key['id'] ) ? (string) $matched_key['id'] : '';
		$required_scope = $this->get_required_scope_for_request( $request );
		if ( ! $this->key_has_scope( $matched_key, $required_scope ) ) {
			return $this->build_insufficient_scope_error( $matched_key, $required_scope, $this->route_category_for_refusal( $request ) );
		}

		// Persist the matched key so downstream identity/role checks (self-approval,
		// per-key tool-category enforcement) see the real requester even on
		// batch/MCP sub-requests.
		$this->current_api_key_record = $matched_key;

		if ( ! $this->set_api_user_context() ) {
			return new WP_Error(
				'api_user_missing',
				__( 'API user context is not configured.', 'mcpwp' ),
				array( 'status' => 500 )
			);
		}

		// Site-administrator tool-category toggle, same as the full verify_api_key()
		// tail. This function is the batch / MCP sub-request fast path
		// (Mcpwp_REST_Batch::$is_batch_sub_request), and it skipped the gate
		// entirely — so /batch with an operation targeting a disabled category
		// succeeded even though the identical call to the same route as a top-level
		// request returned 403. Wrapping a blocked route in a batch envelope must
		// not launder it.
		//
		// Only rate limiting is legitimately skipped on this path (already counted
		// on the outer request); authorisation decisions are not.
		$category_check = $this->check_disabled_category_for_route( $request );
		if ( is_wp_error( $category_check ) ) {
			return $category_check;
		}

		// Per-key role/category restriction (#874) — this is the batch / MCP
		// sub-request fast path, so without this a /batch envelope (or a
		// route-mapped MCP tool re-dispatched via rest_do_request()) would launder
		// a route the calling key's own role does not permit, the same way a
		// disabled category was laundered here before the fix above.
		$key_category_check = $this->check_key_category_restriction_for_route( $request );
		if ( is_wp_error( $key_category_check ) ) {
			return $key_category_check;
		}

		return $this->check_post_naming_params_category( $request );
	}

	/**
	 * Check rate limit for current request.
	 *
	 * @param string $identifier Rate-limit bucket identifier.
	 * @param string $method     HTTP method (GET requests get higher limits).
	 * @return bool|WP_Error True if allowed, error if rate limited.
	 */
	/**
	 * Whether a request is purely diagnostic and belongs in the diagnostic
	 * rate-limit bucket rather than the caller's write bucket (#802).
	 *
	 * Default: nothing is. Controllers that own a diagnostic surface override
	 * this and must answer true only when EVERY operation in the request is
	 * diagnostic — one write beside an initialize makes the whole request a
	 * write, or the exemption is a bypass.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	protected function is_diagnostic_request( $request ) {
		return false;
	}

	/**
	 * The HTTP method a request is rate-limited as (#891).
	 *
	 * Default: the request's real HTTP method. check_limit() gives GET/HEAD/
	 * OPTIONS 2x the burst and per-minute ceiling, on the theory that a read
	 * is non-destructive and cheaper to allow more of. That theory breaks for
	 * any controller whose transport method is fixed regardless of what the
	 * request actually does — every /mcp call is an HTTP POST carrying a
	 * JSON-RPC envelope, so a call to the read tool wp_get_elementor was
	 * priced exactly like a write: same 1x ceiling, same shared per-identifier
	 * counter. A single-post wp_bulk_find_replace burning the whole write
	 * burst then left wp_get_elementor refused right behind it — a read
	 * blocked by an unrelated write, both reported under "bucket":"write"
	 * (#891).
	 *
	 * Mcpwp_REST_MCP overrides this to answer with the underlying tool's own
	 * mapped method when every operation in the request resolves to a
	 * known, GET-mapped tool — the same "every operation must qualify" rule
	 * is_diagnostic_request() applies, so one write anywhere in a batch keeps
	 * the whole request priced as a write.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string HTTP method to price the request as.
	 */
	protected function rate_limit_method( $request ) {
		return method_exists( $request, 'get_method' ) ? $request->get_method() : 'POST';
	}

	/**
	 * How many rate-limit slots this request costs (#802).
	 *
	 * One, unless a controller carries several operations in one HTTP
	 * request — the JSON-RPC batch and REST /batch override this to charge
	 * per operation, so a bigger batch cap does not make the budget fiction.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return int
	 */
	protected function request_rate_weight( $request ) {
		// Site graph reads (#943 S2 review round 2, W6): a direct REST call is
		// priced the same as the tool that dispatches to it, through the one
		// graph_weight() table. An internal /mcp or /batch sub-request never
		// reaches this (it takes the unmetered verify_api_key_auth_only() path),
		// so a tools/call that already charged the weight is not charged twice.
		if ( class_exists( 'Mcpwp_Operation_Weights' ) && method_exists( $request, 'get_route' ) ) {
			$params = $this->gate_params( $request );
			$graph  = Mcpwp_Operation_Weights::graph_weight( Mcpwp_Operation_Weights::normalize_route( (string) $request->get_route() ), $params );
			if ( null !== $graph ) {
				return $graph;
			}
		}
		return 1;
	}

	protected function check_rate_limit( $identifier = null, $method = 'POST', $key_record = null, $weight = 1 ) {
		if ( ! class_exists( 'Mcpwp_Rate_Limiter' ) ) {
			return true;
		}

		$limiter = Mcpwp_Rate_Limiter::get_instance();
		return $limiter->check_limit( $identifier, $method, $key_record, $weight );
	}

	/**
	 * Get API key from request.
	 *
	 * Checks header first.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return string|null API key or null.
	 */
	protected function get_api_key_from_request( $request ) {
		// An OAuth access token (mcpwp_at_ / spai_at_) is a bearer credential
		// and is accepted ONLY in `Authorization: Bearer` — the one method the
		// discovery metadata advertises (bearer_methods_supported ["header"],
		// RFC 6750 §2).  In X-API-Key or the ?api_key= query string it would
		// end up in access logs and proxies' URL records, so it is not read
		// from there at all.  API keys are unaffected: every method that
		// accepted one still does.

		// Check header first (X-API-Key)
		$api_key = $request->get_header( 'X-API-Key' );

		if ( ! empty( $api_key ) && ! $this->looks_like_oauth_access_token( $api_key ) ) {
			return sanitize_text_field( $api_key );
		}

		// Check Authorization header (Bearer token)
		$auth_header = $request->get_header( 'Authorization' );
		if ( ! empty( $auth_header ) && 0 === strpos( $auth_header, 'Bearer ' ) ) {
			return sanitize_text_field( substr( $auth_header, 7 ) );
		}

		// Check query parameter (for Claude Desktop custom connectors and similar
		// MCP clients that don't support custom headers).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['api_key'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$from_query = sanitize_text_field( wp_unslash( $_GET['api_key'] ) );
			if ( ! $this->looks_like_oauth_access_token( $from_query ) ) {
				return $from_query;
			}
		}

		return null;
	}

	/**
	 * Carry the caller's own authenticated credential onto an internal
	 * sub-request, exactly as the caller presented it — never converted into
	 * a different credential type.
	 *
	 * A plain API key arrives as X-API-Key (or the ?api_key= query fallback,
	 * which get_api_key_from_request() folds into the same string). An OAuth
	 * access token or a mumcp.com broker token arrives as
	 * `Authorization: Bearer <token>`. The broker fallback in this trait's
	 * compute_api_key_verdict()/verify_api_key_auth_only() only ever runs for
	 * a genuine Bearer header with no X-API-Key present, so re-sending a
	 * Bearer credential as X-API-Key authenticates as neither — a broker (or,
	 * before #902's OAuth branch was added to the sub-request fast path,
	 * OAuth) caller's own sub-request would fail even though its outer
	 * request had already authenticated (#902, #978).
	 *
	 * Shared by every internal dispatch that forwards auth to a sub-request:
	 * resources/read, tools/call, and /batch's per-operation dispatch.
	 *
	 * @param WP_REST_Request $outer_request    The original, already-authenticated request.
	 * @param WP_REST_Request $internal_request The sub-request about to be dispatched.
	 * @return void
	 */
	protected function forward_caller_credential( $outer_request, $internal_request ) {
		$outer_api_key_header = method_exists( $outer_request, 'get_header' ) ? $outer_request->get_header( 'X-API-Key' ) : null;
		$outer_auth_header    = method_exists( $outer_request, 'get_header' ) ? $outer_request->get_header( 'Authorization' ) : null;
		// An OAuth token in X-API-Key was never the credential (see
		// get_api_key_from_request()); do not forward it as one.
		if ( ! empty( $outer_api_key_header ) && $this->looks_like_oauth_access_token( $outer_api_key_header ) ) {
			$outer_api_key_header = null;
		}

		if ( ! empty( $outer_api_key_header ) ) {
			$internal_request->set_header( 'X-API-Key', $outer_api_key_header );
		} elseif ( ! empty( $outer_auth_header ) ) {
			$internal_request->set_header( 'Authorization', $outer_auth_header );
		} else {
			// Neither header was present — e.g. the ?api_key= query-string
			// fallback some MCP clients use. get_api_key_from_request() folds
			// that into the same plain-key form X-API-Key would carry.
			$api_key = $this->get_api_key_from_request( $outer_request );
			if ( $api_key ) {
				$internal_request->set_header( 'X-API-Key', $api_key );
			}
		}
	}

	/**
	 * Check whether a token looks like a generated OAuth access token.
	 *
	 * Accepts both the v3 prefix (mcpwp_at_) and the legacy 2.8.x prefix
	 * (spai_at_) so that OAuth-connected clients (Claude Desktop, ChatGPT)
	 * continue to work through the slug-rename cutover without needing to
	 * re-authenticate.  The token lookup logic in authenticate_oauth_access_token
	 * handles the transient-key difference (spai_ tokens were stored under
	 * spai_oauth_token_<md5>; v3 stores under mcpwp_oauth_token_<md5>).
	 *
	 * @param string $token Access token.
	 * @return bool True when token has an OAuth prefix (v3 or legacy).
	 */
	protected function looks_like_oauth_access_token( $token ) {
		$token = (string) $token;
		return 0 === strpos( $token, 'mcpwp_at_' ) || 0 === strpos( $token, 'spai_at_' );
	}

	/**
	 * Validate and authenticate OAuth bearer access token.
	 *
	 * Handles both v3 tokens (mcpwp_at_) and legacy 2.8.x tokens (spai_at_).
	 * 2.8.56 stored OAuth tokens under the transient key spai_oauth_token_<md5(token)>.
	 * v3 stores them under mcpwp_oauth_token_<md5(token)>.  Because the md5 is of
	 * the full token string (including its prefix), the transient key differs and
	 * we must use the correct prefix-aware lookup.  For spai_at_ tokens we first
	 * check the legacy spai_oauth_token_ transient namespace.  OAuth must be
	 * enabled on this site for either path to work.
	 *
	 * @param string          $token   Access token.
	 * @param WP_REST_Request $request Request object.
	 * @param bool            $metered False for an internal sub-request whose
	 *                                 outer request already metered this same
	 *                                 caller once (mirrors verify_api_key_auth_only()'s
	 *                                 contract for scoped keys — auth and scope
	 *                                 checks always run; only the rate-limit
	 *                                 charge is skipped, so a resources/read or
	 *                                 tools/call fan-out is one slot, not two).
	 * @return bool|WP_Error True when valid, error otherwise.
	 */
	protected function authenticate_oauth_access_token( $token, $request, $metered = true ) {
		$oauth_settings = $this->get_oauth_settings();
		if ( empty( $oauth_settings['oauth_enabled'] ) ) {
			$this->count_oauth_failure( $request, $metered );
			return self::oauth_disabled_error();
		}

		// #1101 PR-3b: access tokens live in the hashed token table.  A
		// token is valid only when its row is found active (an access row,
		// not revoked, not expired); a missing table finds nothing (fail
		// closed).  A token with no row at all may be one issued before the
		// table existed: during the grace window it is looked up in its old
		// transient — 2.8.x spai_oauth_token_<md5> first for an spai_at_
		// token, then mcpwp_oauth_token_<md5> — and moved into the table on
		// this use, or refused if that write fails.
		$record = false;
		$row    = Mcpwp_OAuth_Token_Store::find( $token );
		if ( Mcpwp_OAuth_Token_Store::is_active( $row, Mcpwp_OAuth_Token_Store::TYPE_ACCESS ) ) {
			$record = Mcpwp_OAuth_Token_Store::to_record( $row );
			Mcpwp_OAuth_Token_Store::touch( $row );
		} elseif ( null === $row ) {
			$legacy_keys = array( $this->get_oauth_token_transient_key( $token ) );
			if ( 0 === strpos( (string) $token, 'spai_at_' ) ) {
				array_unshift( $legacy_keys, 'spai_oauth_token_' . md5( (string) $token ) );
			}
			foreach ( $legacy_keys as $legacy_key ) {
				$record = Mcpwp_OAuth_Token_Store::migrate_from_transient( $token, Mcpwp_OAuth_Token_Store::TYPE_ACCESS, $legacy_key, DAY_IN_SECONDS );
				if ( is_array( $record ) ) {
					break;
				}
			}
		}

		if ( ! is_array( $record ) || empty( $record['scopes'] ) ) {
			if ( ! $this->count_oauth_failure( $request, $metered ) ) {
				$this->log_auth_failure( $request );
			}
			return self::oauth_reconnect_error();
		}

		// #1101: revoked by epoch (its client was revoked or deleted, its user
		// revoked or deleted, its refresh family killed, or the whole site
		// disconnected) at or after the moment it was issued. Checked BEFORE
		// the audience so a revoked token answers like every other dead one,
		// whatever else is wrong with it.
		if ( Mcpwp_OAuth_Revocation::is_revoked( $record ) ) {
			if ( ! $this->count_oauth_failure( $request, $metered ) ) {
				$this->log_auth_failure( $request );
			}
			return self::oauth_reconnect_error();
		}

		// RFC 8707 audience binding (PR-2 D2).  Every token issued since PR-2
		// carries `aud` = this site's MCP endpoint URL; one carrying anything
		// else (a different site's URL, a value altered in storage, an empty
		// string) is refused.  The key's PRESENCE decides — array_key_exists,
		// not isset/empty — so a null or empty `aud` fails closed instead of
		// passing as "no audience".
		//
		// A record with no `aud` key at all was minted before this release.
		// Access tokens live at most a day (issue_oauth_access_token() clamps
		// the TTL to 86400), so that grace period ends on its own.
		if ( array_key_exists( 'aud', $record ) && ! $this->oauth_audience_matches( $record['aud'] ) ) {
			$this->log_auth_failure( $request );
			return new WP_Error(
				'invalid_token',
				__( 'This access token was issued for a different resource.', 'mcpwp' ),
				array(
					'status' => 401,
					'hint'   => 'This token was issued for a different site address. Connect to this site\'s current MCP URL, or re-add the connector.',
				)
			);
		}

		// PR-2 P0: a token minted for a user ACTS AS that user.  So the user
		// must still exist and still meet the site's minimum role for AI
		// connections (Mcpwp_OAuth_Well_Known::get_min_role()); a deleted or
		// demoted user's token stops working on its next request.  A record
		// without user_id (client_credentials, pre-identity legacy tokens)
		// keeps running as the service account — see below.
		$oauth_user_id = ( isset( $record['user_id'] ) && (int) $record['user_id'] > 0 ) ? (int) $record['user_id'] : 0;
		if ( $oauth_user_id > 0 && ( ! get_userdata( $oauth_user_id ) || ! $this->oauth_user_meets_min_role( $oauth_user_id ) ) ) {
			$this->log_auth_failure( $request );
			return new WP_Error(
				'invalid_token',
				__( 'The WordPress user who approved this connection can no longer connect AI apps on this site.', 'mcpwp' ),
				array(
					'status' => 401,
					'hint'   => 'Ask a site administrator to restore this user\'s role (MCPWP -> Setup -> minimum role), or connect as another user.',
				)
			);
		}

		if ( $metered ) {
			$rate_limit_check = $this->meter_request( $request, 'oauth:' . substr( hash( 'sha256', (string) $token ), 0, 16 ), $this->rate_limit_method( $request ) );
			if ( is_wp_error( $rate_limit_check ) ) {
				return $rate_limit_check;
			}
		}

		// Give the OAuth principal a stable, non-empty identity so downstream
		// separation-of-duties checks (check_self_approval) treat it as a real
		// remote caller rather than the id-less admin-AJAX human context. Prefer
		// the consenting user, then the client, then a token-derived fallback for
		// legacy tokens issued before the identity was persisted.
		if ( isset( $record['user_id'] ) && (int) $record['user_id'] > 0 ) {
			$principal_id = 'oauth-user:' . (int) $record['user_id'];
		} elseif ( ! empty( $record['client_id'] ) ) {
			$principal_id = 'oauth-client:' . sanitize_key( (string) $record['client_id'] );
		} else {
			$principal_id = 'oauth:' . substr( hash( 'sha256', (string) $token ), 0, 16 );
		}

		// D8, again at authentication (belt and braces beside the cap at write
		// in Mcpwp_OAuth_Token_Store::insert()): a token that runs as a user
		// never acts with `admin`, whatever a stored row says.
		$record_scopes = (array) $record['scopes'];
		if ( $oauth_user_id > 0 ) {
			$record_scopes = Mcpwp_OAuth_Token_Store::cap_user_bound_scopes( $record_scopes );
		}
		$key_record = array(
			'id'     => $principal_id,
			'type'   => 'oauth',
			'scopes' => $this->sanitize_scopes( $record_scopes ),
		);
		if ( $oauth_user_id > 0 ) {
			// Read by requester_oauth_user_id(): this principal is a real
			// WordPress user, so per-post visibility and write checks ask
			// WordPress (the WebMCP rule, #981) instead of trusting scope.
			$key_record['acts_as_user'] = $oauth_user_id;
		}
		$this->current_api_key_record = $key_record;
		$this->mcpwp_audit_key_id = isset( $key_record['id'] ) ? (string) $key_record['id'] : '';
		$required_scope               = $this->get_required_scope_for_request( $request );

		if ( ! $this->key_has_scope( $key_record, $required_scope ) ) {
			return new WP_Error(
				'insufficient_scope',
				sprintf(
					/* translators: %s: scope name */
					__( 'API key lacks required scope: %s', 'mcpwp' ),
					$required_scope
				),
				array(
					'status'         => 403,
					'required_scope' => $required_scope,
					'granted_scopes' => $key_record['scopes'],
				)
			);
		}

		// PR-2 P0: run AS THE APPROVING USER, never as the service account.
		// The service account (mcpwp_api_agent) holds editor-level caps, so
		// running a user's token as it would hand a low-privilege user an
		// editor's reach; as the user, WordPress capability checks (and the
		// per-post read/edit checks below) apply.  The token scope stays a
		// further ceiling on top.  Only a token with no user — the machine
		// client_credentials grant and pre-identity legacy tokens — keeps
		// the service account, unchanged.
		if ( $oauth_user_id > 0 ) {
			wp_set_current_user( $oauth_user_id );
		} elseif ( ! $this->set_api_user_context() ) {
			return new WP_Error(
				'api_user_missing',
				__( 'API user context is not configured. Re-activate MCPWP to provision the service account.', 'mcpwp' ),
				array( 'status' => 500 )
			);
		}

		// Site-administrator tool-category toggle — the same gate the X-API-Key
		// path applies at the end of verify_api_key() and the broker path applies
		// at the end of authenticate_broker_bearer_token().
		//
		// Without it, an OAuth bearer principal could reach a disabled category by
		// calling the REST route directly: the MCP layer refuses the equivalent
		// tool with -32003, so the toggle *looked* effective while the raw route
		// stayed wide open. A toggle that only holds on one of two entry points is
		// not a control.
		//
		// Placement is deliberate: after the token lookup, the rate-limit check and
		// the scope check. Running it earlier would let an unauthenticated caller
		// tell 403 "category disabled" apart from 401 and enumerate the site's
		// disabled categories without presenting a credential.
		$category_check = $this->check_disabled_category_for_route( $request );
		if ( is_wp_error( $category_check ) ) {
			return $category_check;
		}

		// Per-key role/category restriction (#874). OAuth key records never
		// carry a 'role', so resolve_key_tool_categories() treats them as
		// unrestricted here exactly as it already does at the MCP layer — no
		// behaviour change for OAuth callers, only parity with handle_tools_call().
		$key_category_check = $this->check_key_category_restriction_for_route( $request );
		if ( is_wp_error( $key_category_check ) ) {
			return $key_category_check;
		}

		return $this->check_post_naming_params_category( $request );
	}

	/**
	 * Authenticate a mumcp.com broker-issued opaque Bearer token.
	 *
	 * Alternative to X-API-Key auth. Delegates validation to
	 * Mcpwp_Broker_Auth::validate_token() (cached RFC 7662-shaped introspection
	 * against mumcp.com, fail-closed) and, on success, builds a $key_record in
	 * the SAME shape find_scoped_api_key() / authenticate_oauth_access_token()
	 * produce — 'id' / 'type' / 'scopes' — so every downstream consumer
	 * (scope enforcement via key_has_scope(), requester_can_see_non_public(),
	 * the #683 self-approval separation-of-duties identity, rate limiting,
	 * disabled-tool-category enforcement) treats a broker principal exactly
	 * like any other authenticated caller.
	 *
	 * Admin is impossible via this path: Mcpwp_Broker_Auth::scope_to_capability()
	 * only ever returns 'read' or 'write' (never 'admin', even defensively if a
	 * future/compromised broker response claimed it), and sanitize_scopes() is
	 * only ever fed that single capability string here — there is no code path
	 * by which 'admin' could enter $key_record['scopes'] for a broker principal.
	 *
	 * @param string            $token       Opaque bearer token (Bearer prefix already stripped).
	 * @param WP_REST_Request   $request     Request object.
	 * @param Mcpwp_Broker_Auth $broker_auth Validator instance (caller already confirmed is_enrolled()).
	 * @param bool              $metered     False for an internal sub-request whose
	 *                                       outer request already metered this same
	 *                                       caller once. Also gates the per-IP
	 *                                       broker-attempt DoS throttle below —
	 *                                       an internal sub-request's outer
	 *                                       request already passed that same
	 *                                       throttle, in the same request
	 *                                       lifecycle, for the same token, a
	 *                                       moment earlier, so charging it a
	 *                                       second time only halves the
	 *                                       legitimate ceiling without capping
	 *                                       any additional unauthenticated
	 *                                       attempt (#978 review NOTE). The
	 *                                       OUTER call's throttle is never
	 *                                       weakened: it still always runs
	 *                                       with $metered defaulting true.
	 * @return bool|WP_Error True when valid, error otherwise.
	 */
	protected function authenticate_broker_bearer_token( $token, $request, $broker_auth, $metered = true ) {
		// DoS guard: throttle anonymous introspection attempts per client IP
		// BEFORE the outbound network call, mirroring the invalid-key limiter
		// ('invalid:'.$ip). Without this a Bearer token that is not a local key
		// would trigger one unthrottled wp_remote_post (HTTP_TIMEOUT) per attempt
		// — worker exhaustion locally and amplification against the broker. This
		// bucket is keyed on IP (not the token) so rotating tokens can't evade it,
		// and it runs ahead of validate_token()'s own per-token 'broker:' bucket.
		//
		// The real HTTP method, not rate_limit_method(): the token has not
		// been validated yet, so this ceiling exists precisely to cap what an
		// unauthenticated caller can make this server do — letting that same
		// caller's request body raise its own ceiling defeats the guard, and
		// this is the amplification-capping bucket the adversarial review on
		// #892 called out by name (#892).
		//
		// Skipped only when $metered is false — i.e. only for the internal
		// sub-request fast path (resources/read, tools/call, /batch), whose
		// outer request already ran this exact check, for this exact token,
		// earlier in the same request. That outer check is unconditional
		// (this branch never runs there), so the throttle itself is never
		// weakened — only the redundant second charge for one client call is
		// removed.
		if ( $metered ) {
			$http_method   = method_exists( $request, 'get_method' ) ? $request->get_method() : 'POST';
			$attempt_limit = $this->check_rate_limit( 'broker-attempt:' . $this->get_client_ip(), $http_method );
			if ( is_wp_error( $attempt_limit ) ) {
				return $attempt_limit;
			}
		}

		$result = $broker_auth->validate_token( $token );
		if ( is_wp_error( $result ) ) {
			// Fail-closed: not enrolled (kill-switch), transport error, non-200,
			// active !== true, or audience/site_id mismatch — validate_token()
			// already shaped the WP_Error/status for each case.
			return $result;
		}

		if ( $metered ) {
			$rate_limit_check = $this->meter_request( $request, 'broker:' . substr( hash( 'sha256', (string) $token ), 0, 16 ), $this->rate_limit_method( $request ) );
			if ( is_wp_error( $rate_limit_check ) ) {
				return $rate_limit_check;
			}
		}

		// Cap capability at read/write. scope_to_capability() never returns
		// 'admin'; sanitize_scopes() is only ever given that single string, so
		// 'admin' can never appear in the resulting scopes array.
		$scope      = isset( $result['scope'] ) ? (array) $result['scope'] : array();
		$capability = $broker_auth->scope_to_capability( $scope );
		$scopes     = $this->sanitize_scopes( array( $capability ) );

		// Stable, non-empty identity in its own namespace for the #683
		// self-approval separation-of-duties gate (current_requester_key_id() /
		// check_self_approval()) — a broker principal is `broker:<sub>`,
		// distinct from `oauth-user:`, `oauth-client:`, the legacy `oauth:`
		// fallback, and scoped-key UUIDs, so it can never collide with (and
		// thus never satisfy "same identity" against) any other principal type.
		$sub          = isset( $result['sub'] ) && '' !== (string) $result['sub'] ? (string) $result['sub'] : '';
		$principal_id = '' !== $sub ? 'broker:' . $sub : 'broker:' . substr( hash( 'sha256', (string) $token ), 0, 16 );

		$key_record = array(
			'id'     => $principal_id,
			'type'   => 'broker',
			'scopes' => $scopes,
		);
		$this->current_api_key_record = $key_record;
		$this->mcpwp_audit_key_id = isset( $key_record['id'] ) ? (string) $key_record['id'] : '';

		$required_scope = $this->get_required_scope_for_request( $request );
		if ( ! $this->key_has_scope( $key_record, $required_scope ) ) {
			return new WP_Error(
				'insufficient_scope',
				sprintf(
					/* translators: %s: scope name */
					__( 'API key lacks required scope: %s', 'mcpwp' ),
					$required_scope
				),
				array(
					'status'         => 403,
					'required_scope' => $required_scope,
					'granted_scopes' => $key_record['scopes'],
				)
			);
		}

		if ( ! $this->set_api_user_context() ) {
			return new WP_Error(
				'api_user_missing',
				__( 'API user context is not configured. Re-activate MCPWP to provision the service account.', 'mcpwp' ),
				array( 'status' => 500 )
			);
		}

		// Same site-administrator category toggle enforcement the X-API-Key
		// path applies (verify_api_key() line ~282) — a broker principal must
		// not be able to reach a disabled tool category any more than a scoped
		// API key can.
		$category_check = $this->check_disabled_category_for_route( $request );
		if ( is_wp_error( $category_check ) ) {
			return $category_check;
		}

		// Per-key role/category restriction (#874). Broker key records never
		// carry a 'role' either (scope_to_capability() only ever yields
		// read/write) — unrestricted here, same parity note as the OAuth path.
		$key_category_check = $this->check_key_category_restriction_for_route( $request );
		if ( is_wp_error( $key_category_check ) ) {
			return $key_category_check;
		}

		return $this->check_post_naming_params_category( $request );
	}

	/**
	 * Log authentication failure.
	 *
	 * @param WP_REST_Request $request Request object.
	 */
	protected function log_auth_failure( $request ) {
		$settings = get_option( 'mcpwp_settings', array() );

		if ( empty( $settings['enable_logging'] ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'mcpwp_activity_log';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'action'      => 'auth_failure',
				'endpoint'    => $request->get_route(),
				'method'      => $request->get_method(),
				'status_code' => 401,
				'ip_address'  => $this->get_client_ip(),
				'user_agent'  => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Get client IP address.
	 *
	 * @return string IP address.
	 */
	protected function get_client_ip() {
		// Proxy-supplied client-IP headers are trivially spoofable, so an
		// attacker could otherwise mint a fresh rate-limit/auth-throttle bucket
		// (or forge a whitelisted IP) per request. Only trust them behind an
		// explicit opt-in — the same MCPWP_TRUST_PROXY_HEADERS gate the OAuth
		// well-known scheme check uses; otherwise fall back to REMOTE_ADDR, which
		// the web server controls.
		return $this->resolve_client_ip( defined( 'MCPWP_TRUST_PROXY_HEADERS' ) && MCPWP_TRUST_PROXY_HEADERS );
	}

	/**
	 * Resolve the client IP under an explicit trust flag.
	 *
	 * Split from get_client_ip() so both branches are testable without
	 * define()-ing MCPWP_TRUST_PROXY_HEADERS (a constant cannot be undefined
	 * in a single-process suite) — the same shape as
	 * resolve_logging_client_ip().
	 *
	 * Behind a trusted proxy, CF-Connecting-IP is preferred over the first
	 * X-Forwarded-For hop: Cloudflare sets it to the address it actually
	 * accepted the connection from, while the first XFF hop is whatever the
	 * client claimed before Cloudflare appended to the list.
	 *
	 * @param bool $trust_proxy Whether proxy-supplied headers may be believed.
	 * @return string
	 */
	protected function resolve_client_ip( $trust_proxy ) {
		$ip_keys = $trust_proxy
			? array(
				'HTTP_CF_CONNECTING_IP', // Cloudflare — preferred (see above).
				'HTTP_X_FORWARDED_FOR',  // Proxy
				'HTTP_X_REAL_IP',        // Nginx
				'REMOTE_ADDR',           // Standard
			)
			: array( 'REMOTE_ADDR' );

		foreach ( $ip_keys as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				// Handle comma-separated IPs (X-Forwarded-For)
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
	 * Set the current user context for API requests.
	 *
	 * Sets the current user to the dedicated API agent role
	 * so that capability checks work correctly.
	 */
	protected function set_api_user_context() {
		// Try to find a user with the mcpwp_api_agent role
		$users = get_users( array(
			'role'    => 'mcpwp_api_agent',
			'number'  => 1,
			'orderby' => 'ID',
			'order'   => 'ASC',
		) );

		if ( ! empty( $users ) ) {
			$user = $users[0];

			// Multisite: ensure the bot user is a member of the current blog.
			if ( function_exists( 'is_multisite' ) && is_multisite() && ! is_user_member_of_blog( $user->ID, get_current_blog_id() ) ) {
				add_user_to_blog( get_current_blog_id(), $user->ID, 'mcpwp_api_agent' );
			}

			wp_set_current_user( $user->ID );
			return true;
		}

		// Multisite fallback: the bot user may exist on the main site but
		// get_users with role filter only searches the current blog's usermeta.
		// Look up the user by login directly.
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			$user = get_user_by( 'login', 'mcpwp_bot' );
			if ( $user ) {
				add_user_to_blog( get_current_blog_id(), $user->ID, 'mcpwp_api_agent' );
				wp_set_current_user( $user->ID );
				return true;
			}
		}

		return false;
	}

	/**
	 * Match a presented key against a SCOPED-STORE (or 2.8 store) record's
	 * hash: hash-shaped values only, never the plaintext fallback of
	 * is_api_key_match(). That fallback exists for the single-key options a
	 * pre-1.0.14 site stored in plaintext; on a store record it let any
	 * non-hash string authenticate as itself (#1098).
	 *
	 * @param string $api_key Presented key.
	 * @param mixed  $hash    Stored record hash.
	 * @return bool
	 */
	protected function is_api_key_hash_match( $api_key, $hash ) {
		if ( ! class_exists( 'Mcpwp_Key_State' ) ) {
			require_once dirname( __DIR__ ) . '/core/class-mcpwp-key-state.php';
		}
		if ( empty( $api_key ) || ! Mcpwp_Key_State::is_password_hash_shaped( $hash ) ) {
			return false;
		}
		return (bool) wp_check_password( $api_key, $hash );
	}

	/**
	 * Check whether incoming key matches stored key (hash or legacy plain text).
	 *
	 * @param string $api_key    Incoming API key.
	 * @param string $stored_key Stored API key value.
	 * @return bool True if key is valid.
	 */
	protected function is_api_key_match( $api_key, $stored_key ) {
		if ( empty( $api_key ) || empty( $stored_key ) ) {
			return false;
		}

		if ( wp_check_password( $api_key, $stored_key ) ) {
			return true;
		}

		return hash_equals( $stored_key, $api_key );
	}

	/**
	 * Check whether API keys are configured in any supported store.
	 *
	 * @param string $legacy_key Legacy single key option value.
	 * @return bool True if at least one key is configured.
	 */
	protected function has_configured_api_keys( $legacy_key ) {
		if ( ! empty( $legacy_key ) ) {
			return true;
		}

		$keys = $this->get_scoped_api_keys_raw();
		foreach ( $keys as $key ) {
			if ( empty( $key['revoked_at'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the legacy single-key option `mcpwp_api_key` may authenticate.
	 *
	 * Only on a site whose scoped store is still empty: a genuine
	 * pre-multi-key install, whose key is migrated into the store on first use
	 * and matched there from then on. Once the store holds any record, every
	 * live key has a record, so a legacy hash with no live record can only
	 * belong to a REVOKED key — find_scoped_api_key() skips revoked records,
	 * and honouring the option here used to re-create a revoked primary key
	 * as a brand-new active admin record (migrate_legacy_key_to_scoped_store()).
	 *
	 * @return bool
	 */
	protected function legacy_api_key_option_is_honoured() {
		return array() === $this->get_scoped_api_keys_raw() && ! $this->scoped_api_key_store_is_unreadable();
	}

	/**
	 * Whether the scoped key store holds something that is not a list of keys.
	 *
	 * A truncated or otherwise corrupt value reads as an empty store through
	 * get_scoped_api_keys_raw(), which would make every legacy fallback look
	 * safe: the legacy single key would be honoured and re-migrated as an
	 * active admin record, and a spai_ copy of a revoked key would match. While
	 * the store is unreadable nothing falls back to those; the request fails
	 * closed (#1096).
	 *
	 * @return bool
	 */
	protected function scoped_api_key_store_is_unreadable() {
		if ( ! class_exists( 'Mcpwp_Key_State' ) ) {
			require_once dirname( __DIR__ ) . '/core/class-mcpwp-key-state.php';
		}
		return Mcpwp_Key_State::store_is_unreadable();
	}

	/**
	 * Whether the single 2.8 spai_api_key is revoked anywhere (#1098):
	 *  - its exact hash is revoked in the scoped store (#1097), or
	 *  - its exact hash is a REVOKED spai_api_keys record (2.8 revoked the
	 *    Primary key in its store and left the same hash in spai_api_key), or
	 *  - it is a pre-1.0.14 PLAINTEXT value and the presented key matches a
	 *    revoked record's hash in either store (a string compare can never see
	 *    a plaintext value's revoked, rehashed copy).
	 *
	 * @param string $single    spai_api_key value.
	 * @param string $api_key   Presented key.
	 * @param array  $spai_keys spai_api_keys records.
	 * @return bool
	 */
	protected function spai_single_key_is_revoked( $single, $api_key, array $spai_keys ) {
		if ( $this->hash_is_revoked_in_scoped_store( $single ) ) {
			return true;
		}
		$revoked = array();
		foreach ( array_merge( $this->get_scoped_api_keys_raw(), $spai_keys ) as $record ) {
			if ( is_array( $record ) && ! empty( $record['revoked_at'] ) && isset( $record['hash'] ) && '' !== (string) $record['hash'] ) {
				$revoked[] = (string) $record['hash'];
			}
		}
		foreach ( $revoked as $hash ) {
			if ( hash_equals( $hash, $single ) ) {
				return true;
			}
		}
		if ( ! $this->looks_like_password_hash( $single ) ) {
			foreach ( $revoked as $hash ) {
				if ( $this->is_api_key_hash_match( $api_key, $hash ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Whether a stored hash belongs to a REVOKED record in the scoped store.
	 *
	 * Exact string compare: the 2.8 migration copies hashes verbatim, so a
	 * revoked mcpwp copy of a spai key carries the identical hash (#1097).
	 *
	 * @param string $hash Stored hash.
	 * @return bool
	 */
	protected function hash_is_revoked_in_scoped_store( $hash ) {
		if ( '' === $hash ) {
			return false;
		}
		foreach ( $this->get_scoped_api_keys_raw() as $record ) {
			if ( is_array( $record ) && ! empty( $record['revoked_at'] ) && isset( $record['hash'] ) && hash_equals( (string) $record['hash'], $hash ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Find an active scoped API key record matching a plaintext key.
	 *
	 * @param string $api_key Incoming plaintext API key.
	 * @return array|null Matching key record or null.
	 */
	protected function find_scoped_api_key( $api_key ) {
		$keys = $this->get_scoped_api_keys_raw();

		foreach ( $keys as $key ) {
			if ( ! empty( $key['revoked_at'] ) || empty( $key['hash'] ) ) {
				continue;
			}

			if ( $this->is_api_key_hash_match( $api_key, $key['hash'] ) ) {
				$record = $this->normalize_api_key_record( $key );
				// A record with no id cannot be named in a ledger or bound by
				// the two-person rule; it is not a principal (#536 round 2).
				return '' === $record['id'] ? null : $record;
			}
		}

		return null;
	}

	/**
	 * Look up a scoped API key's record BY ITS STORED id — not by presenting
	 * the credential — for the one case where a request needs a PAST
	 * principal's current role/category, not the caller's own: replaying a
	 * post_content_update approval against the identity that PROPOSED it
	 * (#1032 round 3). An approval's `requested_by_key_id` is exactly the id
	 * normalize_api_key_record() puts on this record.
	 *
	 * A revoked key is deliberately excluded (returns null, same as a
	 * missing one) rather than returning its last-known role: a revoked
	 * key's already-queued proposal must not keep whatever access it had at
	 * request time.
	 *
	 * @param string $key_id Stored key id.
	 * @return array|null Normalized key record, or null if unknown/revoked.
	 */
	protected function find_scoped_api_key_by_id( $key_id ) {
		$key_id = sanitize_key( (string) $key_id );
		if ( '' === $key_id ) {
			return null;
		}

		foreach ( $this->get_scoped_api_keys_raw() as $key ) {
			if ( ! is_array( $key ) || ! empty( $key['revoked_at'] ) ) {
				continue;
			}
			if ( isset( $key['id'] ) && sanitize_key( (string) $key['id'] ) === $key_id ) {
				return $this->normalize_api_key_record( $key );
			}
		}

		return null;
	}

	/**
	 * Whether a PAST principal — identified only by the key id an approval
	 * recorded at request time, e.g. `requested_by_key_id` — may read
	 * $category, for re-checking a queued write against its REQUESTER
	 * rather than whoever is applying it now (#1032 round 3). Deliberately
	 * separate from caller_may_read_category(): that method always reads
	 * $this->get_current_api_key_record(), which at apply time is the
	 * APPROVING admin — using it here would let an admin's own unrestricted
	 * visibility launder a reference a restricted requester was never
	 * allowed to introduce, defeating the entire point of re-checking.
	 *
	 * Still honours the site-wide disabled-category toggle first: a
	 * category the ADMIN disabled after the request was queued must block
	 * the apply even if the requester's own role would have allowed it.
	 *
	 * $key_id's possible shapes, and how each is resolved (#1032 round 4 —
	 * every writer of `requested_by_key_id` is enumerated here; a shape not
	 * recognised by any branch below falls through to the scoped-key lookup
	 * and denies, per the last paragraph):
	 *
	 *  - '' — current_requester_key_id()'s OWN documented contract: an
	 *    admin-AJAX (Chat tab) or wp-admin cookie context with NO API-key
	 *    record at all. It is the only writer of the empty string (every
	 *    other caller — REST controllers, Mcpwp_WebMCP,
	 *    Mcpwp_Destructive_Gate::requester_id() — stamps a real, non-empty
	 *    principal id), so '' cannot mean anything else here and is
	 *    unrestricted, matching caller_may_read_category()'s own
	 *    null-key-record behaviour.
	 *  - `oauth-user:*` / `oauth-client:*` / `oauth:*` (OAuth bearer,
	 *    authenticate_oauth_access_token()) and `broker:*` (mumcp.com
	 *    broker, authenticate_broker_bearer_token()) — neither principal
	 *    type ever carries a 'role' field, and resolve_key_tool_categories()
	 *    documents both as unrestricted BY CATEGORY, governed by scope
	 *    instead, at authentication time itself. Looking either up in the
	 *    scoped-key store (below) always misses — they are never written
	 *    there — which used to fail CLOSED for a real, legitimate requester:
	 *    a false positive that would refuse every OAuth/broker-proposed
	 *    approval, not a security gap fixed by refusing it.
	 *  - `webmcp:user:*` (Mcpwp_WebMCP::principal_id()) — also never in the
	 *    scoped-key store, but WebMCP's principal_record() DOES carry a real
	 *    category restriction (PRINCIPAL_CATEGORIES: site, content, seo) —
	 *    resolved directly against that fixed list rather than treated as
	 *    unrestricted like OAuth/broker.
	 *  - anything else (a scoped API key's own id) — find_scoped_api_key_by_id().
	 *    An id that no longer resolves to a live key (revoked since the
	 *    request was queued, or simply unrecognised) fails CLOSED: nothing
	 *    is left to prove that principal still has the access it needed, so
	 *    it must not be assumed.
	 *
	 * @param string $key_id   Stored requester key id (may be '').
	 * @param string $category Tool category slug.
	 * @return bool
	 */
	protected function requester_may_read_category( $key_id, $category ) {
		$disabled_categories = get_option( 'mcpwp_disabled_tool_categories', array() );
		if ( is_array( $disabled_categories ) && in_array( $category, $disabled_categories, true ) ) {
			return false;
		}

		$key_id = (string) $key_id;
		if ( '' === $key_id ) {
			return true;
		}

		if ( 0 === strpos( $key_id, 'oauth-user:' ) || 0 === strpos( $key_id, 'oauth-client:' ) || 0 === strpos( $key_id, 'oauth:' ) || 0 === strpos( $key_id, 'broker:' ) ) {
			return true;
		}

		if ( class_exists( 'Mcpwp_WebMCP' ) && 0 === strpos( $key_id, Mcpwp_WebMCP::SOURCE . ':' ) ) {
			return in_array( $category, Mcpwp_WebMCP::PRINCIPAL_CATEGORIES, true );
		}

		$key_record = $this->find_scoped_api_key_by_id( $key_id );
		if ( ! is_array( $key_record ) ) {
			return false;
		}

		return $this->key_allows_category( $key_record, $category );
	}

	/**
	 * Find an active API key record from the legacy spai_* option store.
	 *
	 * This is the dual-prefix bridge for the 2.8.x → v3 cutover.  It checks
	 * two legacy options:
	 *
	 *   spai_api_keys  — the scoped multi-key store written by 2.8.x
	 *   spai_api_key   — the single-key legacy option (written by pre-multi-key builds)
	 *
	 * The comparison uses the same wp_check_password() / hash_equals() path as
	 * find_scoped_api_key(), so timing characteristics are identical.  No
	 * plaintext key material is logged or retained.
	 *
	 * When a match is found the method returns a normalized key record (same
	 * shape as find_scoped_api_key()) so the caller can apply scope checks
	 * uniformly.  It does NOT auto-migrate the key to the mcpwp_ store — that
	 * is intentional.  Migration is handled separately by Mcpwp_Migrate::run()
	 * so that the auth path stays simple and side-effect-free.
	 *
	 * @param string $api_key Incoming plaintext API key.
	 * @return array|null Matching (normalized) key record, or null.
	 */
	protected function find_legacy_spai_key( $api_key ) {
		if ( $this->scoped_api_key_store_is_unreadable() ) {
			return null; // Fail closed: see scoped_api_key_store_is_unreadable().
		}
		// #1097: the 2.8 migration copies spai_api_keys / spai_api_key into the
		// mcpwp stores VERBATIM (same hash) and never deletes the originals. So
		// a key revoked in the mcpwp store is still live in its spai copy — and
		// used to authenticate here as admin. A spai credential whose exact hash
		// belongs to a REVOKED mcpwp record is therefore refused. A spai key with
		// no mcpwp copy at all (e.g. a single spai_api_key the migration skipped
		// because the activator had already minted mcpwp_api_key) keeps working,
		// as before. Checked here, at the chokepoint, so every caller (main
		// path, sub-request path, rate-limit diagnostics) gets it.

		// 1. Check the spai_api_keys scoped multi-key store.
		$spai_keys = get_option( 'spai_api_keys', array() );
		if ( is_array( $spai_keys ) ) {
			foreach ( $spai_keys as $key ) {
				if ( ! is_array( $key ) ) {
					continue;
				}
				// Skip revoked keys.
				if ( ! empty( $key['revoked_at'] ) ) {
					continue;
				}
				if ( empty( $key['hash'] ) ) {
					continue;
				}
				if ( $this->hash_is_revoked_in_scoped_store( (string) $key['hash'] ) ) {
					continue;
				}
				if ( $this->is_api_key_hash_match( $api_key, $key['hash'] ) ) {
					$record = $this->normalize_api_key_record( $key );
					return '' === $record['id'] ? null : $record;
				}
			}
		}

		// 2. Fall back to the single-key spai_api_key option.
		$spai_single = get_option( 'spai_api_key' );
		if ( ! empty( $spai_single ) && ! $this->spai_single_key_is_revoked( (string) $spai_single, $api_key, is_array( $spai_keys ) ? $spai_keys : array() ) && $this->is_api_key_match( $api_key, $spai_single ) ) {
			// Synthesise a minimal key record consistent with normalize_api_key_record().
			return $this->normalize_api_key_record(
				array(
					'id'         => 'spai_legacy_single',
					'label'      => 'Legacy spai_ key (bridge)',
					'hash'       => (string) $spai_single,
					'scopes'     => $this->get_default_api_key_scopes(),
					'role'       => 'admin',
					'created_at' => null,
				)
			);
		}

		return null;
	}

	/**
	 * Get required authorization scope for a request.
	 *
	 * ============================================================================
	 * #874 — READ THIS BEFORE ADDING A ROUTE. THIS IS THE ONLY GATE.
	 * ============================================================================
	 * Every API key — whatever role it was created with (admin/designer/author/
	 * editor/custom) — authenticates as the SAME WordPress user carrying the SAME
	 * `mcpwp_api_agent` role (see set_api_user_context()). There is no WordPress
	 * capability behind the read/write/admin distinction this method computes:
	 * this table, and this table alone, is what stops a write-scoped key from
	 * reaching an admin-only surface. A route this function does not classify
	 * falls through to the bottom-line default (write for any mutating HTTP
	 * method) — that default is a real decision for every admin-sensitive route,
	 * not a safe fallback, so a new admin surface that is only added to a REST
	 * controller and never added to $admin_routes / $admin_write_routes here is
	 * reachable by a plain write-scoped key with nothing else in the way.
	 *
	 * This function governs the OUTER request only. Three things depend on it
	 * agreeing with itself and with its two companions, or the "single layer"
	 * silently grows a second, unaudited shape:
	 *   1. get_required_scope_for_tool_name() — the /mcp JSON-RPC mirror of this
	 *      table, keyed by tool name instead of route. For a route-mapped tool
	 *      (the tool map's 'route' key — every first-party wp_ or wc_ tool today)
	 *      this method's verdict is re-derived and re-checked from the REAL route
	 *      when the tool dispatches via rest_do_request() (see
	 *      Mcpwp_REST_MCP::handle_tools_call()), so a tool-name-table entry that
	 *      under-classifies is latent, not live — UNTIL that tool is ever
	 *      converted to a 'callback' tool (in-process dispatch, no
	 *      rest_do_request() re-check — see the 'callback' branch of
	 *      handle_tools_call()), at which point the tool-name table becomes the
	 *      SOLE gate and a stale entry becomes a live bypass. Today the only
	 *      'callback' tools are third-party additions registered through
	 *      Mcpwp_Custom_Tool_Registry (the `mcpwp_register_tools` filter contract)
	 *      — no first-party tool uses 'callback'. ToolNameRouteScopeParityTest
	 *      asserts the two tables agree for every route-mapped tool so this
	 *      cannot silently drift again.
	 *   2. check_key_category_restriction_for_route() — a SEPARATE, ORTHOGONAL
	 *      gate. This method answers "how privileged must the key be" (read vs.
	 *      write vs. admin); that one answers "is this key's role even allowed to
	 *      touch this tool category at all" (e.g. an 'author' key is read+write
	 *      scoped but its role grants only the content/media/taxonomy categories
	 *      — it must never reach an Elementor route regardless of scope). Before
	 *      the #874 fix that category check ran ONLY inside
	 *      Mcpwp_REST_MCP::handle_tools_call(), so calling a REST route directly
	 *      (or nesting it in /batch) bypassed a key's role entirely and left only
	 *      this method's coarse scope tier standing — a real, live bypass, not a
	 *      latent one. It is now called from every authentication path in this
	 *      trait (see its own docblock) precisely so this method is not the ONLY
	 *      thing gating a route, even though it remains the only thing gating
	 *      SCOPE.
	 *   3. normalize_route_for_authorization() — every route compared against the
	 *      tables below MUST be folded through it first (case-folding; see that
	 *      method's docblock for the exact bug this closed). Comparing a raw,
	 *      un-normalized route here reopens a case-sensitivity bypass.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return string Scope name: read|write|admin.
	 */
	protected function get_required_scope_for_request( $request ) {
		$route    = $this->normalize_route_for_authorization( method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '' );
		$method   = method_exists( $request, 'get_method' ) ? strtoupper( (string) $request->get_method() ) : 'GET';
		$is_write = ! in_array( $method, array( 'GET', 'HEAD', 'OPTIONS' ), true );

		// MCP JSON-RPC dispatch resolves scope from the tool payload.
		if ( 0 === strpos( $route, '/mcpwp/v1/mcp' ) ) {
			return $this->get_required_scope_for_mcp_request( $request );
		}

		// Approval state transitions (approve/reject/apply/rollback) require admin
		// scope so a plain write key cannot approve/apply its own pending request.
		// Listing/reading approvals stays read; creating one (via the
		// blocks/content-graph/staging controllers) stays at the write default.
		if ( preg_match( '#^/mcpwp/v1/approvals/[^/]+/(approve|reject|apply|rollback|resolve)(/|$)#i', $route ) ) {
			return 'admin';
		}
		// #981 review round 4: purging closed approvals is an admin action.
		if ( preg_match( '#^/mcpwp/v1/approvals/purge-closed(/|$)#i', $route ) ) {
			return 'admin';
		}

		// Deploying a stored site blueprint replays a full page/menu/option set onto
		// the live site — a destructive, high-blast-radius op. Require admin; saving,
		// listing, and extracting blueprints stay at their read/write defaults.
		if ( preg_match( '#^/mcpwp/v1/site-blueprints/[^/]+/deploy(/|$)#i', $route ) ) {
			return 'admin';
		}

		// A find/replace across a whole type or the whole site rewrites content
		// the caller has not named. Gated here, in the route table, like every
		// other high-blast-radius op — not only inside the handler (#803).
		if ( $is_write && preg_match( '#^/mcpwp/v1/elementor/find-replace(/|$)#i', $route ) ) {
			$params = $this->gate_params( $request );
			if ( is_array( $params ) && class_exists( 'Mcpwp_REST_Elementor' ) && Mcpwp_REST_Elementor::find_replace_is_wide( $params ) ) {
				return 'admin';
			}
		}

		// Figma design-token sync makes an external Figma API call and overwrites
		// the cached mcpwp_figma_design_tokens option (read by a companion theme) —
		// require admin. Reading the cached tokens stays at the read default.
		if ( preg_match( '#^/mcpwp/v1/figma/design-tokens/sync(/|$)#i', $route ) ) {
			return 'admin';
		}

		// Integrations: reading, writing, deleting, or probing a stored provider
		// secret requires admin scope. Discovery (status/providers/
		// stock-photos) stays read; content-generation stays at the write default.
		if ( $this->route_matches( $route, '/mcpwp/v1/integrations' ) ) {
			if (
				$this->route_matches( $route, '/mcpwp/v1/integrations/configure' )
				|| $this->route_matches( $route, '/mcpwp/v1/integrations/remove' )
				|| $this->route_matches( $route, '/mcpwp/v1/integrations/test' )
				|| preg_match( '#^/mcpwp/v1/integrations/providers/[^/]+/(key|test)(/|$)#i', $route )
			) {
				return 'admin';
			}
			return $is_write ? 'write' : 'read';
		}

		// #1100 interim (before the capability sweep): routes whose handlers
		// carry no capability check of their own, so scope is the only gate.
		// Admin scope closes them for every non-admin key and every OAuth
		// token (OAuth is never admin) until the real WordPress capability
		// is enforced for user-run principals.
		//  - Form entries: submitters' names, emails and messages, which an
		//    Editor cannot see in wp-admin. Every method, prefix match.
		if ( preg_match( '#^/mcpwp/v1/forms/[^/]+/[^/]+/entries(/|$)#i', $route ) ) {
			return 'admin';
		}
		//  - Widgets and sidebars: writing them needs edit_theme_options in
		//    wp-admin. Writes only; listing stays at the read default.
		if ( $is_write && ( $this->route_matches( $route, '/mcpwp/v1/widgets' ) || $this->route_matches( $route, '/mcpwp/v1/sidebars' ) ) ) {
			return 'admin';
		}
		//  - Product creation (POST with no id): no per-post check exists for
		//    a post that does not exist yet, and Editors lack edit_products.
		//    Edits by id stay on the publish-gated rules below.
		if ( 'POST' === $method && '/mcpwp/v1/woocommerce/products' === untrailingslashit( $route ) ) {
			return 'admin';
		}
		//  - Site-wide settings with no capability check (Kasra, #1100 phase 1):
		//    the site icon (/favicon writes), rewrite flush, page-cache flush,
		//    site-health signal settings, the site context (brand brief) write,
		//    the feedback relay, and creating or deleting a stored site
		//    blueprint. In wp-admin each of these needs manage_options. Reads
		//    of the same routes stay where they were.
		$route_exact = untrailingslashit( $route );
		if ( $is_write && $this->route_matches( $route, '/mcpwp/v1/favicon' ) ) {
			return 'admin';
		}
		if ( 'POST' === $method && in_array( $route_exact, array( '/mcpwp/v1/flush-permalinks', '/mcpwp/v1/cache-flush', '/mcpwp/v1/site-context', '/mcpwp/v1/feedback/relay', '/mcpwp/v1/site-blueprints' ), true ) ) {
			return 'admin';
		}
		if ( $is_write && '/mcpwp/v1/signals/settings' === $route_exact ) {
			return 'admin';
		}
		if ( 'DELETE' === $method && preg_match( '#^/mcpwp/v1/site-blueprints/[^/]+$#i', $route_exact ) ) {
			return 'admin';
		}

		// Blanket-admin routes: every method (including GET) requires admin scope.
		$admin_routes = array(
			'/mcpwp/v1/settings',
			'/mcpwp/v1/options',
			'/mcpwp/v1/menus',
			'/mcpwp/v1/content',
			'/mcpwp/v1/webhooks',
			'/mcpwp/v1/api-keys',
			'/mcpwp/v1/elementor/custom-code',
			// User management can create/modify accounts (privilege-escalation
			// surface) and exposes account PII (emails, roles, capabilities);
			// restrict the whole surface to admin scope.
			'/mcpwp/v1/users',
			// Plugin self-update is a code-execution surface: it can install an
			// arbitrary package and reactivate the plugin. Restrict to admin scope.
			'/mcpwp/v1/update',
			// Singular /option write can set update-control options
			// (mcpwp_update_info / mcpwp_version_url) that steer the updater.
			'/mcpwp/v1/option',
			// /plugin-settings exposes the GitHub token / OAuth client-secret hash and
			// other mcpwp_settings internals; a write-scoped key could overwrite OAuth
			// config (secret takeover). Require admin scope for both GET and PUT.
			'/mcpwp/v1/plugin-settings',
			// Staging routes: pushing environments (especially →live) is irreversible
			// without a host-level backup, so all staging endpoints require admin scope.
			'/mcpwp/v1/staging',
			// WooCommerce orders and customers (PR-2 round 2, Kasra option A):
			// customer PII, addresses and order history.  The handlers carry no
			// capability check of their own (that is #1100), so scope is the
			// only gate — admin, every method, prefix match (orders/{id},
			// orders/statuses, customers/{id} and any future sub-route).  An
			// OAuth token is never admin (D8), so it cannot reach them at all.
			'/mcpwp/v1/woocommerce/orders',
			'/mcpwp/v1/woocommerce/customers',
		);

		foreach ( $admin_routes as $admin_route ) {
			if ( $this->route_matches( $route, $admin_route ) ) {
				return 'admin';
			}
		}

		// Admin-on-write routes: mutating methods require admin scope; GET/HEAD/
		// OPTIONS stay readable. Used where reads are safe to expose but writes are
		// a high-blast-radius or secret-bearing surface.
		$admin_write_routes = array(
			// Rate-limit reset is an admin control (was an inline special case).
			'/mcpwp/v1/rate-limit',
			// Site-wide custom CSS is a persistent injection/defacement vector.
			// Standalone controller.
			'/mcpwp/v1/custom-css',
			// Theme customizer surface: /theme/mods overwrites all customizer state,
			// /theme/custom-css injects site-wide CSS. Boundary match
			// on /theme covers both mutating sub-routes; /theme/global-styles GET and
			// other GETs stay readable. Distinct from /themes (plural) below.
			'/mcpwp/v1/theme',
			// Pro theme reconfiguration: /themes/settings, /themes/astra/colors,
			// /themes/astra/typography are EDITABLE global-presentation writes; the
			// many GET discovery routes under /themes stay read.
			'/mcpwp/v1/themes',
			// Action-log rollback reverts an arbitrary historical mutation; the log
			// GET/export routes stay read.
			'/mcpwp/v1/action-log',
			// Cloudflare zone mutations (bot-check fix, cache purge, Speed settings)
			// change external infra config outside this site; status/speed-settings
			// GETs stay read.
			'/mcpwp/v1/cloudflare',
			// Writing the AI crawler allow/deny policy edits robots.txt output
			// site-wide (denying a search bot can remove the site from an AI
			// product's live answers); reading it stays at the read default.
			'/mcpwp/v1/ai-crawlers',
			// Explicit IndexNow submission (adversarial review #937 WARN-3):
			// pings an external endpoint with arbitrary caller-supplied URLs.
			// Admin scope only; /indexnow/status stays read.
			'/mcpwp/v1/indexnow',
			// Site graph (#943 S2): /graph/rebuild reschedules a full
			// backfill pass over every covered post — a real, if bounded,
			// cost the owner should control. Every other /graph/* route is
			// GET-only and stays at the read default.
			'/mcpwp/v1/graph/rebuild',
		);

		foreach ( $admin_write_routes as $admin_write_route ) {
			if ( $this->route_matches( $route, $admin_write_route ) ) {
				return $is_write ? 'admin' : 'read';
			}
		}

		// #607: publishing a post or page (create, update, bulk create/update,
		// or the page-clone target status) must never be reachable on generic
		// write scope alone. A WordPress current_user_can('publish_posts') /
		// ('publish_pages') check cannot enforce this boundary here: EVERY API
		// key, whatever scope it declares, authenticates as the same shared
		// mcpwp_api_agent WP user (see class-mcpwp-activator.php's add_role()
		// call — read the docblock above it), and that role is granted
		// publish_posts/publish_pages unconditionally, so
		// current_user_can('publish_posts') is always true no matter which
		// key made the request. The key's own scope tier is therefore the
		// ONLY real authorization boundary this plugin has, so — the same way
		// every other high-blast-radius write already is (wide find/replace,
		// blueprint deploy, approval state transitions) — setting status to
		// 'publish' is elevated to admin scope here, at the single chokepoint
		// every dispatch path (direct REST, /batch, MCP tools/call) re-checks
		// against (see this method's docblock).
		$publish_gated_routes = array(
			'/mcpwp/v1/posts', // covers /posts, /posts/{id}, /posts/bulk
			'/mcpwp/v1/pages', // covers /pages, /pages/{id}, /pages/bulk, /pages/{id}/clone
			// WooCommerce products carry the SAME status field and the SAME
			// shared-agent-user problem, and create_product's own schema
			// defaults 'status' to 'publish' (Mcpwp_REST_WooCommerce::
			// get_product_create_args()) — a bare wc_create_product call with
			// no status param at all would otherwise publish a live storefront
			// listing on write scope alone. Covers /woocommerce/products and
			// /woocommerce/products/{id}.
			'/mcpwp/v1/woocommerce/products',
			// RC 3.12.1 adversarial review, BLOCK 1: LearnPress courses/lessons/
			// quizzes are lp_course/lp_lesson/lp_quiz POSTS (Mcpwp_LearnPress::
			// create_course()/create_lesson()/create_quiz() all call
			// wp_insert_post(); the *_update variants call wp_update_post()) with
			// the SAME shared-agent-user problem as posts/pages/products above —
			// this table simply never listed them. create_lesson() and
			// create_quiz() default 'status' to 'publish' with zero arguments
			// (Mcpwp_REST_LearnPress::get_lesson_create_args() /
			// get_quiz_create_args() now declare that default so it is visible
			// here, mirroring the WooCommerce products fix). Covers
			// /learnpress/courses(/{id}(/curriculum)), /learnpress/lessons(/{id}),
			// /learnpress/quizzes(/{id}(/questions)) — the curriculum and
			// questions sub-routes never carry a 'status' param, so they are
			// unaffected by this gate; they ride along only because they share
			// the /courses and /quizzes prefixes.
			'/mcpwp/v1/learnpress/courses',
			'/mcpwp/v1/learnpress/lessons',
			'/mcpwp/v1/learnpress/quizzes',
			// RC 3.12.1 adversarial review, Requirement 1 systematic sweep
			// (grepping every wp_insert_post()/wp_update_post() call site for a
			// caller-controlled 'post_status', not just the route table) turned
			// up FIVE more surfaces with the identical defect, none named in the
			// original two BLOCK findings:
			//
			// - Events (Mcpwp_Events::update_event(), a sibling CPT to
			//   LearnPress with the exact same shape — create_event() defaults
			//   safely to 'draft', update_event() takes the caller's status raw).
			//   Covers /events and /events/{id}. NOTE: this route also has a
			//   pre-existing, UNRELATED bug — Mcpwp_REST_Webhooks and
			//   Mcpwp_REST_Events both register GET /mcpwp/v1/events — that is
			//   not this fix's to resolve (see the report), but the scope gate
			//   on the PUT sub-route is independent of it and closed here.
			// - wp_build_page (Mcpwp_REST_Elementor_Pro::build_page() ->
			//   Mcpwp_Page_Builder::build(), which accepts 'draft'/'publish'/
			//   'private' and defaults to 'draft'). Covers /elementor/build-page.
			// - wp_create_landing_page (Mcpwp_Elementor_Pro::create_landing_page(),
			//   same 'draft'-default/caller-override shape). Covers
			//   /elementor/landing-page.
			// - wp_clone_elementor_page (Mcpwp_Elementor_Pro::clone_page() — the
			//   Elementor-specific sibling of /pages/{id}/clone, which is already
			//   gated above; this one was not). Covers /elementor/clone.
			// - create_elementor_page (Mcpwp_Elementor_Writer_Trait::
			//   create_elementor_page(), reachable directly over REST at
			//   /elementor/page even though it maps to no MCP tool today — the
			//   review's own threat model is direct REST, not only /mcp).
			//
			// (wp_apply_elementor_archetype/_template/_part were audited too —
			// all three only ever mutate Elementor JSON on an EXISTING page_id
			// and never touch post_status, so none needed gating.
			// wp_create_translation's routes are already covered: they live at
			// /posts/{id}/translations and /pages/{id}/translations, under the
			// /posts and /pages prefixes above. Elementor Custom Code and
			// site-blueprints deploy also set a caller status but are already
			// blanket-admin-gated for every method, publish or not — see
			// $admin_routes and the site-blueprints deploy regex above.)
			'/mcpwp/v1/events',
			'/mcpwp/v1/elementor/build-page',
			'/mcpwp/v1/elementor/landing-page',
			'/mcpwp/v1/elementor/clone',
			'/mcpwp/v1/elementor/page',
		);
		if ( $is_write ) {
			foreach ( $publish_gated_routes as $publish_gated_route ) {
				if ( $this->route_matches( $route, $publish_gated_route ) && $this->request_targets_publish_status( $request ) ) {
					return 'admin';
				}
			}

			// RC 3.12.1 adversarial review, BLOCK 2: applying a WooCommerce
			// product archetype dispatches through the SAME create_product()/
			// update_product() path as /woocommerce/products above (see
			// Mcpwp_REST_WooCommerce::apply_product_archetype() and
			// extract_product_override_payload(), which whitelists 'status' as
			// an overridable field) — but the id sits in the MIDDLE of the path
			// (/woocommerce/archetypes/{id}/apply), so route_matches()'s
			// exact-or-prefix comparison can never match it against a
			// '/woocommerce/archetypes' prefix without ALSO sweeping in
			// archetype DEFINITION CRUD (create/get/update the stored
			// template) — routes that only ever update_option() a record and
			// never call wp_insert_post()/wp_update_post() at all, so gating
			// them here would require admin scope for something that cannot
			// publish anything. A dynamic-segment regex, the same technique
			// already used above for /approvals/{id}/(approve|reject|...) and
			// /site-blueprints/{id}/deploy, keeps the gate on only the route
			// that actually publishes.
			//
			// This route also cannot use the plain request_targets_publish_status()
			// check below: the archetype's OWN stored product_data can already
			// carry 'status' => 'publish' from whenever it was defined, so a
			// caller need not repeat it in the request at all to reach the
			// same outcome. archetype_apply_targets_publish_status() merges
			// the request's override on top of the stored archetype the same
			// way apply_product_archetype() itself does.
			if ( preg_match( '#^/mcpwp/v1/woocommerce/archetypes/([^/]+)/apply$#', $route, $archetype_route_matches ) ) {
				$targets_publish = class_exists( 'Mcpwp_REST_WooCommerce' )
					? Mcpwp_REST_WooCommerce::archetype_apply_targets_publish_status( $archetype_route_matches[1], $request )
					: $this->request_targets_publish_status( $request );

				if ( $targets_publish ) {
					return 'admin';
				}
			}
		}

		// Default: GET/HEAD/OPTIONS → read, everything else → write.
		return $is_write ? 'write' : 'read';
	}

	/**
	 * Whether a create/update/bulk/clone request for a post, page, WooCommerce
	 * product, or LearnPress course/lesson/quiz asks for a status this plugin
	 * treats as publishing anywhere in its body — the top-level 'status'
	 * param, or any item's 'status' inside a bulk 'posts' / 'pages' /
	 * 'updates' array. See get_required_scope_for_request() (#607) for why
	 * this gates scope instead of a WordPress capability check.
	 *
	 * RC 3.12.1 adversarial review, WARN 3: 'publish' is not the only status
	 * this must catch. 'private' is a real, non-draft state — visible to
	 * editors and admins, excluded from public listings but not from the
	 * dashboard — and is an accepted enum value on every gated surface
	 * (/posts, /pages, /pages/{id}/clone, WooCommerce products, and now
	 * LearnPress). Treating only 'publish' as publishing let a write-scoped
	 * key reach the same "content is live for anyone with dashboard access"
	 * outcome via status=private.
	 *
	 * fix/3121-future-status-coercion: the note that used to sit here —
	 * "'future' was also checked by the review and confirmed NOT reachable
	 * here" — was true only for Mcpwp_Posts/Mcpwp_Pages, which allow-list
	 * post_status to publish/draft/pending/private(/trash on update) BEFORE
	 * calling wp_insert_post()/wp_update_post(). It was WRONG as a blanket
	 * claim: Mcpwp_WooCommerce::set_product_data() had no such allow-list at
	 * all (now added — see that method's docblock), and the WooCommerce
	 * archetype-definition routes (POST /woocommerce/archetypes, PUT
	 * /woocommerce/archetypes/{id}) had no 'args' schema whatsoever, so a
	 * 'future' status could be stored via those routes and later reach a real
	 * product write through /woocommerce/archetypes/{id}/apply with no
	 * request-time validation ever seeing it (see
	 * Mcpwp_REST_WooCommerce::archetype_apply_targets_publish_status(), which
	 * has its own independent copy of this same check for exactly that
	 * reason). WordPress's wp_insert_post() coerces 'future' straight to
	 * 'publish' whenever the post's date is not itself at least a minute out
	 * (wp-includes/post.php), and no surface in this plugin exposes a way to
	 * set a post's or product's date, so 'future' is unconditionally
	 * equivalent to 'publish' everywhere this gate applies — there is no
	 * legitimate scheduled-publish flow this could break. 'future' is
	 * therefore now included below, alongside 'publish' and 'private'. See
	 * param_value_targets_admin_scope().
	 *
	 * Reads gate_params() (like the sibling find_replace_is_wide() check
	 * above) rather than get_json_params(): every bucket a handler can read
	 * — JSON, body, query, URL — on every real dispatch path this plugin
	 * uses (direct REST, /batch sub-dispatch, MCP tools/call via
	 * rest_do_request()), degrading safely (empty array) when a request has
	 * no JSON body. It used to read get_params() for the same reasons, but
	 * get_params() lets a JSON-body null hide a query-string value that the
	 * handler's get_param() still reads (#1110): {"status":null} plus
	 * ?status=publish was judged "no status" and then published. Each key is
	 * now resolved through get_param(), the handlers' own accessor.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool True when any targeted item would end up 'publish', 'private', or 'future'.
	 */
	protected function request_targets_publish_status( $request ) {
		if ( ! method_exists( $request, 'get_params' ) ) {
			return false;
		}

		$params = $this->gate_params( $request );

		if ( $this->param_value_targets_admin_scope( $params, 'status' ) ) {
			return true;
		}

		// Bulk create/update bodies carry an array of items under 'posts',
		// 'pages', or the 'updates' alias (see Mcpwp_REST_Posts::
		// register_routes() / Mcpwp_REST_Pages::register_routes()) — each
		// item can independently request 'publish'/'private', so every one
		// must be checked, not just the top level.
		foreach ( array( 'posts', 'pages', 'updates' ) as $bulk_key ) {
			if ( empty( $params[ $bulk_key ] ) || ! is_array( $params[ $bulk_key ] ) ) {
				continue;
			}
			foreach ( $params[ $bulk_key ] as $item ) {
				if ( is_array( $item ) && $this->param_value_targets_admin_scope( $item, 'status' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Case-insensitive helper: is $params[$key] present and a status this
	 * plugin requires admin scope for — 'publish', 'private' (WARN 3, RC
	 * 3.12.1 adversarial review), or 'future' (fix/3121-future-status-coercion).
	 * See request_targets_publish_status()'s docblock for why each is
	 * included — in short: WordPress's wp_insert_post() can coerce 'future'
	 * to 'publish' at write time, and 'private' is a real, non-draft visible
	 * status, so both must be treated exactly like 'publish' here.
	 *
	 * RC 3.12.1 adversarial review, BLOCK: this used to compare
	 * strtolower( (string) $value ) against ('publish', 'private') with an
	 * EXACT match. Most gated routes never noticed because their own
	 * register_routes() declares an 'enum' on 'status', so WordPress rejects
	 * an out-of-enum value with rest_invalid_param before the callback (and
	 * this gate's own admin-scope check earlier in dispatch) ever sees a
	 * value that could reach a handler. Five routes registered NO args
	 * schema at all on their write methods (see the five register_routes()
	 * additions this fix makes — 'enum' now closes that gap directly), and
	 * every one of their handlers normalizes the raw value before using it:
	 * Mcpwp_Events::create_event()/update_event() and
	 * Mcpwp_WooCommerce::set_product_data() call sanitize_key( $data['status'] ),
	 * Mcpwp_Elementor_Pro::clone_page()/create_landing_page() call
	 * sanitize_text_field(). A value like ' publish' (leading space),
	 * "publish\n", or a unicode-whitespace-padded variant fails this
	 * method's old exact match (scope stayed 'write') but normalized to the
	 * literal 'publish' in every one of those handlers — a live bypass.
	 *
	 * The fix: run the SAME normalization here that the handlers run.
	 * sanitize_key() is the strictly more aggressive of the two downstream
	 * normalizers — it deletes every byte outside [a-z0-9_-] anywhere in the
	 * string (not only at the edges), so it already swallows ASCII space and
	 * tab, every unicode whitespace/format/control character (non-breaking
	 * space, zero-width space, line/paragraph separators, NUL, etc. — none
	 * of those are in [a-z0-9_-], so they are stripped unconditionally
	 * without needing to be enumerated by name here), and it is a superset
	 * of what sanitize_text_field()'s trim-and-collapse does for this
	 * specific padding-based bypass shape (a clean 'publish'/'private'
	 * token surrounded by whitespace/control padding — the only shape this
	 * gate needs to reason about, since it is a status ENUM check, not a
	 * free-text field). Using sanitize_key() here therefore can never be
	 * NARROWER than the most permissive downstream normalizer: any input
	 * that lands on 'publish'/'private' via sanitize_text_field() also
	 * lands there via sanitize_key(), so the gate is always at least as
	 * inclusive as the handler it protects.
	 *
	 * @param array  $params Params array.
	 * @param string $key    Key to inspect.
	 * @return bool
	 */
	private function param_value_targets_admin_scope( $params, $key ) {
		if ( ! isset( $params[ $key ] ) || ! is_scalar( $params[ $key ] ) ) {
			return false;
		}

		$normalized = sanitize_key( (string) $params[ $key ] );

		return in_array( $normalized, array( 'publish', 'private', 'future' ), true );
	}

	/**
	 * Boundary-aware route prefix match.
	 *
	 * Matches only when $route equals $prefix or continues with a path separator,
	 * so '/mcpwp/v1/content' no longer over-matches '/mcpwp/v1/content-graph'.
	 *
	 * @param string $route  Full request route (with namespace).
	 * @param string $prefix Route prefix to test.
	 * @return bool True when $route is $prefix or a sub-path of it.
	 */
	protected function route_matches( $route, $prefix ) {
		return $route === $prefix || 0 === strpos( $route, $prefix . '/' );
	}

	/**
	 * Fold a request route to the form the authorization tables are written in.
	 *
	 * WordPress compiles route patterns with `@^…$@i` and never rewrites
	 * $request->get_route(), so `POST /mcpwp/v1/Api-Keys` dispatches to the
	 * `/api-keys` handler while every case-sensitive comparison in this trait
	 * misses it. A route that misses the admin table falls through to the
	 * write/read default, which is how a write-scoped credential reached an
	 * admin-only endpoint. Every route that feeds an authorization decision
	 * must come through here first.
	 *
	 * The fold is ASCII-only on purpose: strtolower() consults the C locale
	 * below PHP 8.2, and this plugin supports 7.4. Under a Turkish LC_CTYPE it
	 * maps "I" to a dotless "ı", which would match none of the prefixes below
	 * and reopen exactly this hole.
	 *
	 * @param string $route Raw request route.
	 * @return string Route folded to lower case.
	 */
	protected function normalize_route_for_authorization( $route ) {
		return strtr(
			(string) $route,
			'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
			'abcdefghijklmnopqrstuvwxyz'
		);
	}

	/**
	 * The tool a tools/call message will ACTUALLY dispatch — unwrapping the
	 * mcpwp_call meta tool (#907) so every place that infers required
	 * scope, rate-limit weight, or diagnostic status from a raw tools/call
	 * payload agrees with what will really run, and can never be
	 * under-classified merely because the payload named the wrapper
	 * instead of the tool it wraps.
	 *
	 * Adversarial review of #907 round 1 (BLOCK-1): get_required_scope_for_mcp_request()
	 * used to read `params.name` directly, so a message naming "mcpwp_call"
	 * was classified from a table that has never heard of "mcpwp_call" —
	 * defaulting to 'read' — regardless of how privileged the tool named
	 * in `params.arguments.name` actually was. A read-scoped key wrapping
	 * an admin-only tool (`wp_list_mcp_events`) or a write-scoped key
	 * wrapping one (`wp_delete_all_drafts`, `wp_batch`) sailed past this
	 * gate and reached it — live, over real HTTP, 200 with real data. This
	 * is the ONE place that unwraps for every caller of this kind
	 * (get_required_scope_for_mcp_request() here, plus
	 * Mcpwp_REST_MCP::is_diagnostic_operation() and ::rate_limit_method(),
	 * which call it the same way) — every one of those MUST route through
	 * this method rather than read `params.name`/`params.arguments`
	 * themselves, or the next one of them reopens exactly this bypass.
	 *
	 * A message that does not name mcpwp_call passes through unchanged. One
	 * that does but carries no resolvable inner tool (missing `name`, or an
	 * attempt to wrap mcpwp_call/mcpwp_find_tools — Mcpwp_REST_MCP::resolve_mcpwp_call()
	 * refuses both before dispatch, so neither ever actually runs) is
	 * reported as the wrapper itself, `"mcpwp_call"` — which the tool-name
	 * scope table below lists under `$admin_tools` for exactly this case,
	 * so an unresolvable wrapper is never classified BELOW what any real
	 * dispatch could need.
	 *
	 * @param string $tool_name Outer tools/call `name`.
	 * @param mixed  $arguments Outer tools/call `arguments`.
	 * @return array{name:string, arguments:array} Effective name/arguments.
	 */
	protected function effective_mcp_tool_call( $tool_name, $arguments ) {
		if ( 'mcpwp_call' !== $tool_name ) {
			return array(
				'name'      => is_string( $tool_name ) ? $tool_name : '',
				'arguments' => is_array( $arguments ) ? $arguments : array(),
			);
		}

		$wrapped    = is_array( $arguments ) ? $arguments : array();
		$inner_name = isset( $wrapped['name'] ) && is_string( $wrapped['name'] ) ? $wrapped['name'] : '';

		if ( '' === $inner_name || in_array( $inner_name, array( 'mcpwp_call', 'mcpwp_find_tools' ), true ) ) {
			return array( 'name' => 'mcpwp_call', 'arguments' => array() );
		}

		return array(
			'name'      => $inner_name,
			'arguments' => isset( $wrapped['arguments'] ) && is_array( $wrapped['arguments'] ) ? $wrapped['arguments'] : array(),
		);
	}

	/**
	 * Infer required scope from MCP JSON-RPC payload.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return string Scope.
	 */
	protected function get_required_scope_for_mcp_request( $request ) {
		$payload = method_exists( $request, 'get_json_params' ) ? $request->get_json_params() : null;

		if ( empty( $payload ) ) {
			return 'read';
		}

		$messages = isset( $payload[0] ) && is_array( $payload ) ? $payload : array( $payload );
		$highest  = 'read';

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			$scope = $this->get_required_scope_for_mcp_message( $message );

			if ( $this->scope_rank( $scope ) > $this->scope_rank( $highest ) ) {
				$highest = $scope;
			}
		}

		return $highest;
	}

	/**
	 * Scope one JSON-RPC message needs. The single derivation: the outer
	 * request check (get_required_scope_for_mcp_request()) and the per-call
	 * check inside /mcp (tool_call_scope_refusal()) both read it, so the
	 * two cannot disagree about what a call requires.
	 *
	 * @param array $message JSON-RPC message.
	 * @return string Scope.
	 */
	protected function get_required_scope_for_mcp_message( array $message ) {
		$method = isset( $message['method'] ) ? (string) $message['method'] : '';

		if ( 'tools/call' === $method ) {
			// #907 BLOCK-1: classify the EFFECTIVE tool — mcpwp_call
			// unwrapped to the tool it names — never the raw outer
			// name, or a wrapped admin/write tool is misclassified as
			// whatever "mcpwp_call" itself would require (nothing).
			// #1016: pass the raw value through, unconverted — a
			// premature (string) cast turns an array/object `name`
			// into the literal string "Array" (a PHP "Array to string
			// conversion" warning, not caught here) instead of the ''
			// effective_mcp_tool_call() itself already normalizes a
			// non-string name to.
			$outer_name = isset( $message['params']['name'] ) ? $message['params']['name'] : '';
			$outer_args = isset( $message['params']['arguments'] ) && is_array( $message['params']['arguments'] ) ? $message['params']['arguments'] : array();
			$effective  = $this->effective_mcp_tool_call( $outer_name, $outer_args );
			$tool_name  = $effective['name'];
			$scope      = $this->get_required_scope_for_tool_name( $tool_name );

			// The same rule the REST route table applies: a wide
			// find/replace is admin whichever door it comes through —
			// including wrapped in mcpwp_call, so this reads the
			// EFFECTIVE arguments, not the outer wrapper's.
			if ( 'wp_bulk_find_replace' === $tool_name && class_exists( 'Mcpwp_REST_Elementor' ) ) {
				if ( Mcpwp_REST_Elementor::find_replace_is_wide( $effective['arguments'] ) ) {
					$scope = 'admin';
				}
			}

			return $scope;
		}

		if ( ! in_array( $method, array( 'initialize', 'tools/list', 'resources/list', 'resources/read', 'ping', 'notifications/initialized' ), true ) ) {
			return 'write';
		}

		return 'read';
	}

	/**
	 * The one answer for an OAuth access token that is not valid: expired,
	 * unknown, never issued, malformed, revoked (app removed, user revoke,
	 * revoked refresh family, /oauth/revoke, Disconnect all), covered by a
	 * revocation epoch, or local OAuth switched off. Identical for every
	 * cause, so it is no oracle for whether a string was once a real token.
	 * It never carries the token. The WWW-Authenticate challenge
	 * (error="invalid_token" plus resource_metadata) is added to the 401 by
	 * Mcpwp_OAuth_Well_Known::add_www_authenticate_header().
	 *
	 * @return WP_Error
	 */
	public static function oauth_reconnect_error() {
		return new WP_Error(
			'invalid_token',
			self::oauth_reconnect_message(),
			array(
				'status' => 401,
				'hint'   => self::oauth_reconnect_hint(),
			)
		);
	}

	/**
	 * Count a failed OAuth token against the same per-address limiter a wrong
	 * API key uses ('invalid:<ip>'), so the two share ONE budget and the
	 * failure load is bounded. Unlike the API-key path, going over the limit
	 * never changes the answer: a dead token still gets the identical 401
	 * invalid_token (a client refreshes on 401, never on 429, so a shared
	 * address's exhausted budget must not stall every client's hourly expiry).
	 * The caller skips only the side effects (the activity-log INSERT) when
	 * this returns true. Valid tokens never reach this; unmetered
	 * sub-requests were already charged by their outer request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param bool            $metered Whether this call is the outer request.
	 * @return bool True when the address is over its failure budget.
	 */
	protected function count_oauth_failure( $request, $metered ) {
		if ( ! $metered ) {
			return false;
		}
		$method = method_exists( $request, 'get_method' ) ? $request->get_method() : 'POST';
		$over   = $this->check_rate_limit( 'invalid:' . $this->get_client_ip(), $method );
		return is_wp_error( $over );
	}

	/**
	 * A token presented while local OAuth sign-in is switched off. On a site
	 * enrolled with the mumcp.com broker, discovery still points somewhere, so
	 * it is the ordinary reconnect answer. Otherwise "reconnect" is not
	 * actionable, so say so. Same answer for every token on such a site; the
	 * switch is already public (the metadata answers 404).
	 *
	 * @return WP_Error
	 */
	public static function oauth_disabled_error() {
		if ( class_exists( 'Mcpwp_OAuth_Well_Known' ) && Mcpwp_OAuth_Well_Known::is_broker_enrolled() ) {
			return self::oauth_reconnect_error();
		}
		return new WP_Error(
			'invalid_token',
			self::oauth_disabled_message(),
			array(
				'status' => 401,
				'hint'   => 'OAuth sign-in is switched off on this site (MCPWP -> Setup -> Advanced). Use an API key, or ask the site owner to turn it on.',
			)
		);
	}

	/**
	 * @return string
	 */
	public static function oauth_disabled_message() {
		return __( 'OAuth sign-in is switched off on this site. Use an API key, or ask the site owner.', 'mcpwp' );
	}

	/**
	 * @return string
	 */
	public static function oauth_reconnect_message() {
		return __( 'This connection has expired or was revoked. Reconnect (sign in again) to continue.', 'mcpwp' );
	}

	/**
	 * @return string
	 */
	public static function oauth_reconnect_hint() {
		return 'The access token is expired, revoked, or not recognised. Try the refresh token, and if that is refused, reconnect: remove and re-add the connector, or sign in again.';
	}

	/**
	 * The hint every invalid-key 401 carries. One text for every cause
	 * (mistyped, revoked, retired first-activation key, never valid), so the
	 * answer is never an oracle for whether a string was once a real key.
	 * Mcpwp_Error_Hints holds the same text for paths that add a hint late.
	 *
	 * @return string
	 */
	public static function invalid_api_key_hint() {
		return 'The key is not valid: it may be mistyped, revoked, or a retired first-activation key. Create a new key under MCPWP -> Setup.';
	}

	/**
	 * Whether an insufficient-scope refusal on this request is reported
	 * per call, inside /mcp, instead of as one HTTP 403 for the whole body.
	 *
	 * True only for a POST /mcp body whose scope shortfall is confined to
	 * tools/call messages: every other message in it (initialize, tools/list,
	 * resources/*, prompts/*, unknown methods) must already be within the
	 * key's scope. Each tools/call is then judged on its own by
	 * tool_call_scope_refusal() before it dispatches, with the same scope
	 * derivation (get_required_scope_for_mcp_message()), so no call that was
	 * refused is allowed: the refusal moves from the envelope to the call.
	 * The result is an isError tools/call result (#936) the model can read,
	 * and the allowed calls of a JSON-RPC batch still run.
	 *
	 * @param WP_REST_Request $request    Request.
	 * @param array           $key_record Matched key record.
	 * @return bool
	 */
	protected function mcp_scope_refusal_is_per_call( $request, $key_record ) {
		$route = $this->normalize_route_for_authorization( method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '' );
		if ( 0 !== strpos( $route, '/mcpwp/v1/mcp' ) ) {
			return false;
		}
		$http_method = method_exists( $request, 'get_method' ) ? strtoupper( (string) $request->get_method() ) : 'POST';
		if ( 'POST' !== $http_method ) {
			return false;
		}
		$payload = method_exists( $request, 'get_json_params' ) ? $request->get_json_params() : null;
		if ( empty( $payload ) || ! is_array( $payload ) ) {
			return false;
		}

		$messages  = isset( $payload[0] ) ? $payload : array( $payload );
		$any_calls = false;
		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}
			if ( isset( $message['method'] ) && 'tools/call' === $message['method'] ) {
				$any_calls = true;
				continue;
			}
			if ( ! $this->key_has_scope( $key_record, $this->get_required_scope_for_mcp_message( $message ) ) ) {
				return false;
			}
		}

		return $any_calls;
	}

	/**
	 * The scope refusal for ONE tools/call, or null when the caller's scope
	 * covers it. Reads the message the same way the outer request check does,
	 * from the params as the client sent them.
	 *
	 * @param mixed $params tools/call params.
	 * @return array{required_scope:string,tool:string}|null
	 */
	protected function tool_call_scope_refusal( $params ) {
		$key_record = $this->get_current_api_key_record();
		if ( ! is_array( $key_record ) ) {
			return null; // Trusted admin-AJAX context: no key to hold a scope.
		}

		$message  = array(
			'method' => 'tools/call',
			'params' => is_array( $params ) ? $params : array(),
		);
		$required = $this->get_required_scope_for_mcp_message( $message );
		if ( $this->key_has_scope( $key_record, $required ) ) {
			return null;
		}

		$outer_name = isset( $message['params']['name'] ) ? $message['params']['name'] : '';
		$outer_args = isset( $message['params']['arguments'] ) && is_array( $message['params']['arguments'] ) ? $message['params']['arguments'] : array();
		$effective  = $this->effective_mcp_tool_call( $outer_name, $outer_args );

		return array(
			'required_scope' => $required,
			'tool'           => $effective['name'],
		);
	}

	/**
	 * Whether the record's role can ever be granted a scope. Only the Admin
	 * role (and a legacy record with no role at all) can hold admin scope:
	 * create_scoped_api_key() caps every other role (editor, author,
	 * designer, custom, ...) at read + write.
	 *
	 * @param array  $key_record Key record.
	 * @param string $scope      read|write|admin.
	 * @return bool
	 */
	public static function key_role_can_hold_scope( $key_record, $scope ) {
		if ( 'admin' !== $scope || ! is_array( $key_record ) ) {
			return true;
		}
		if ( ! array_key_exists( 'role', $key_record ) ) {
			return true;
		}

		return 'admin' === (string) $key_record['role'];
	}

	/**
	 * The insufficient_scope refusal for an API key, worded for what is true
	 * and what the caller can do about it. The decision is made by the
	 * caller; this only describes it.
	 *
	 * A key whose role can never hold the required scope (an Admin-only
	 * action asked of an editor, author, designer or custom key) is told so,
	 * and told who can fix it: the site owner, with an Admin-role key from
	 * MCPWP > Setup. Where the action is also outside the key's tool
	 * categories, that category is named too, as the category refusal did
	 * before the scope check ran first. The code stays insufficient_scope.
	 *
	 * @param array  $key_record     Key record.
	 * @param string $required_scope Scope the action needs.
	 * @param string $category       Tool category of the route or tool, '' if none.
	 * @return WP_Error
	 */
	protected function build_insufficient_scope_error( $key_record, $required_scope, $category = '' ) {
		$key_record = is_array( $key_record ) ? $key_record : array();
		$granted    = isset( $key_record['scopes'] ) ? (array) $key_record['scopes'] : array();
		$role       = isset( $key_record['role'] ) ? self::describe_role_for_message( (string) $key_record['role'] ) : '';
		$can_hold   = self::key_role_can_hold_scope( $key_record, $required_scope );

		$data = array(
			'status'         => 403,
			'required_scope' => $required_scope,
			'granted_scopes' => $granted,
		);

		$message = sprintf(
			/* translators: %s: scope name */
			__( 'API key lacks required scope: %s', 'mcpwp' ),
			$required_scope
		);

		if ( $can_hold ) {
			$hint = sprintf(
				'This API key has scopes [%s] but needs "%s". Request a key with the required scope from the site admin, or use wp_create_api_key to create one with appropriate scopes.',
				implode( ', ', $granted ),
				$required_scope
			);
		} else {
			$message .= sprintf(
				/* translators: %s: key role */
				__( '. This key (role: %s) can never hold admin scope: this action needs an Admin-role key with the admin scope.', 'mcpwp' ),
				'' !== $role ? $role : 'unknown'
			);
			$hint = sprintf(
				'This action needs an Admin-role API key with the admin scope. Keys with the %s role are limited to read and write scope and cannot be upgraded. Ask the site owner to create an Admin-role key under MCPWP -> Setup (WP Admin > MCPWP > Setup) and use that key for this action.',
				'' !== $role ? '"' . $role . '"' : 'this'
			);
			$data['role']              = $role;
			$data['needs_admin_role']  = true;
		}

		$allowed = $this->resolve_key_tool_categories( $key_record );
		if ( '' !== $category && ! empty( $allowed ) && ! in_array( $category, $allowed, true ) ) {
			$allowed_text = self::describe_allowed_categories_for_message( $allowed );
			$message     .= sprintf(
				/* translators: 1: tool category, 2: comma-separated allowed categories */
				__( ' It is also outside this key\'s tool categories: it is in the "%1$s" category and this key allows: %2$s.', 'mcpwp' ),
				$category,
				$allowed_text
			);
			$hint                     .= sprintf( ' The new key must also include the "%s" category.', $category );
			$data['category']           = $category;
			$data['allowed_categories'] = self::present_tool_categories( $allowed );
		}

		$data['hint'] = $hint;

		return new WP_Error( 'insufficient_scope', $message, $data );
	}

	/**
	 * The tool category of the request's route, for describing a refusal.
	 * '' for /mcp and /batch (envelopes, not category surfaces).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string
	 */
	protected function route_category_for_refusal( $request ) {
		$route = $this->normalize_route_for_authorization( method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '' );
		if ( $this->route_matches( $route, '/mcpwp/v1/batch' ) || 0 === strpos( $route, '/mcpwp/v1/mcp' ) ) {
			return '';
		}

		return (string) $this->get_category_for_route( $route );
	}

	/**
	 * Determine required scope for a tool call name.
	 *
	 * #874: this is the tool-name mirror of get_required_scope_for_request()'s
	 * route table — READ THAT METHOD'S DOCBLOCK FIRST. It must never classify a
	 * route-mapped tool BELOW what that tool's real route requires; keep the two
	 * tables in sync when adding either a route or a tool, and see
	 * ToolNameRouteScopeParityTest, which now enforces that automatically for
	 * every route-mapped tool in the free and pro registries.
	 *
	 * @param string $tool_name MCP tool name.
	 * @return string Scope.
	 */
	protected function get_required_scope_for_tool_name( $tool_name ) {
		$admin_tools = array(
			// #1100 interim: mirrors the form-entries, widget/sidebar-write
			// and product-create admin routes in get_required_scope_for_request().
			'wp_get_form_entries',
			'wp_add_widget',
			'wp_update_widget',
			'wp_delete_widget',
			'wp_move_widget',
			'wp_reorder_widgets',
			'wc_create_product',
			// #1100 phase 1: mirrors the site-wide setting writes above.
			'wp_flush_permalinks',
			'wp_flush_cache',
			'wp_set_site_context',
			'wp_create_site_blueprint',
			// WooCommerce orders/customers: mirrors the blanket-admin
			// /woocommerce/orders and /woocommerce/customers routes (PR-2 round 2).
			'wc_list_orders',
			'wc_get_order',
			'wc_update_order',
			'wc_list_order_statuses',
			'wc_list_customers',
			'wc_get_customer',
			'wp_delete_all_drafts',
			'wp_get_options',
			'wp_update_options',
			'wp_list_menus',
			'wp_list_menu_locations',
			'wp_list_menu_items',
			'wp_setup_menu',
			'wp_add_menu_item',
			'wp_update_menu_item',
			'wp_delete_menu_item',
			'wp_reorder_menu_items',
			'wp_delete_menu',
			'wp_assign_menu_location',
			'wp_batch_update',
			// Same route and controller as wp_batch_update (#802), same scope.
			'wp_batch',
			'wp_list_content',
			'wp_delete_content',
			'wp_languages',
			'wp_set_language',
			'wp_get_translations',
			'wp_create_translation',
			'wp_create_webhook',
			'wp_update_webhook',
			'wp_delete_webhook',
			'wp_test_webhook',
			'wp_list_webhooks',
			'wp_list_webhook_logs',
			'wp_list_webhook_events',
			'wp_get_event_schema',
			'wp_list_mcp_events',
			'wp_create_api_key',
			'wp_revoke_api_key',
			'wp_list_api_keys',
			'wp_update_rate_limit',
			'wp_reset_rate_limit',
			// Plugin self-update installs a package and reactivates the plugin —
			// a code-execution surface that must never be reachable by a
			// write-scoped key. Defense-in-depth alongside the REST admin gate.
			'wp_trigger_update',
			// Plugin settings hold the GitHub token / OAuth client-secret hash and
			// other secret-bearing config; write access must require admin scope.
			'wp_update_plugin_settings',
			// Staging tools require admin scope — staging→live push is a destructive,
			// irreversible operation that must not be accessible to write-scoped keys.
			'wp_get_staging_info',
			'wp_push_to_staging',
			'wp_push_to_live',
			'wp_get_staging_status',
			// Approval state transitions require admin scope so a plain write key
			// cannot approve/apply/reject/rollback a change — including its own
			// pending request. List/get stay read (not listed here).
			'wp_approve_request',
			'wp_reject_request',
			'wp_apply_approval',
			'wp_rollback_approval',
			// Integration credential management writes/removes/probes encrypted
			// third-party provider secrets (OpenAI/Gemini/ElevenLabs/…) — admin
			// only. Status/discovery stay read (not listed here).
			'wp_configure_integration',
			'wp_remove_integration',
			'wp_test_integration',
			// Site-wide custom CSS is a persistent injection surface; writing or
			// deleting it requires admin scope.
			'wp_set_custom_css',
			'wp_delete_custom_css',
			// Deploying a stored site blueprint replays a full page/menu/option set
			// onto the live site — a destructive op. The write-verb regex does not
			// match "deploy", so name it explicitly to mirror the REST deploy gate.
			'wp_deploy_site_blueprint',
			// Singular option get/update and plugin-settings read reach the same
			// update-control / secret-bearing options already admin-gated at the
			// REST layer (/option, /plugin-settings) — keep the tool table in sync
			// with those routes.
			'wp_get_option',
			'wp_update_option',
			'wp_get_plugin_settings',
			// Figma design-token sync calls the external Figma API and overwrites
			// the cached design-tokens option a companion theme reads — admin only,
			// mirroring the REST /figma/design-tokens/sync gate above.
			'wp_sync_figma_design_tokens',
			// #874 systematic diff: every tool below dispatches (via its 'route'
			// entry in the free/pro tool maps) to a REST route that
			// get_required_scope_for_request() already gates at 'admin' — this
			// table simply did not say so. Route-mapped tools are not exploitable
			// from the gap alone (rest_do_request() re-derives scope from the real
			// route and re-blocks a non-admin key there — see the class comment on
			// get_required_scope_for_tool_name()), but a table that disagrees with
			// the gate it mirrors is a bug in its own right and becomes a live
			// bypass the moment a tool's dispatch mechanism ever changes (exactly
			// what #874 asked this file to stop being able to do silently).
			// Found and closed by ToolNameRouteScopeParityTest, which fails on any
			// future desync between the two tables for every route-mapped tool.
			'wp_check_update',
			'wp_cloudflare_fix_mcp_access',
			'wp_cloudflare_purge_cache',
			'wp_cloudflare_update_speed_setting',
			// /elementor/custom-code is a blanket-admin route (see $admin_routes
			// above); mirror it for all seven of its tools, not just the two
			// (create/update) that also happen to match the write-verb regex.
			'wp_list_elementor_custom_code',
			'wp_get_elementor_custom_code',
			'wp_create_elementor_custom_code',
			'wp_update_elementor_custom_code',
			'wp_disable_elementor_custom_code',
			'wp_enable_elementor_custom_code',
			'wp_sanitize_elementor_custom_code',
			// /menus is a blanket-admin route (see $admin_routes above); these
			// four were missing from the otherwise-complete menu list above.
			'wp_get_menu',
			'wp_create_menu',
			'wp_update_menu',
			'wp_update_menu_item_auto',
			'wp_delete_menu_item_auto',
			// Mirrors the '/mcpwp/v1/ai-crawlers' admin-on-write route: writing
			// the AI crawler policy edits robots.txt output site-wide (denying a
			// search bot can remove the site from an AI product's live
			// answers). wp_get_ai_crawler_policy is read-only and not listed here.
			'wp_set_ai_crawler_policy',
			// Mirrors the '/mcpwp/v1/indexnow' admin-on-write route (#937 WARN-3):
			// pings an external endpoint with caller-supplied URLs.
			// wp_indexnow_status is read-only and not listed here.
			'wp_indexnow_submit',
			// #907 BLOCK-1: the literal string "mcpwp_call" only ever reaches
			// this table via effective_mcp_tool_call()'s conservative fallback
			// — an mcpwp_call message whose own wrapper is unresolvable
			// (missing `name`, or an attempt to wrap mcpwp_call/mcpwp_find_tools,
			// both refused before dispatch by Mcpwp_REST_MCP::resolve_mcpwp_call()).
			// A VALID wrapper is unwrapped to the tool it actually names before
			// this table is ever consulted, so listing it here does not require
			// admin scope to use mcpwp_call itself — only to send it a wrapper
			// this server cannot resolve to anything.
			'mcpwp_call',
		);

		// #874 systematic diff, second half: tools whose route requires 'write'
		// (a mutating HTTP method with no admin classification) but whose name
		// does not start with one of the regex's verbs below — most of these use
		// a domain verb (apply/clone/edit/patch/regenerate/flush/assign/move/…)
		// that was never added to the regex. Same rationale as the admin list
		// above: not exploitable today (the second, route-derived check already
		// requires 'write' and blocks a read-only key), but a table that
		// disagrees with the gate it mirrors is a bug, and ToolNameRouteScopeParityTest
		// now fails on any tool in this position that is left off both this list
		// and the regex.
		$write_tools = array(
			'wp_remember',
			'wp_forget',
			'wp_apply_internal_link',
			'wp_import_search_performance',
			'wp_upsert_kit_css_block',
			'wp_clone_page',
			'wp_clone_elementor_page',
			'wp_edit_section',
			'wp_add_section',
			'wp_remove_section',
			'wp_replace_section',
			'wp_patch_elementor',
			'wp_patch_block_section',
			'wp_edit_widget',
			'wp_add_widget',
			'wp_move_widget',
			'wp_reorder_widgets',
			'wp_regenerate_elementor_css',
			'wp_screenshot_url',
			'wp_parse_blocks',
			'wp_serialize_blocks',
			'wp_validate_blocks',
			'wp_submit_feedback',
			'wp_flush_permalinks',
			'wp_flush_cache',
			'wp_refresh_seo_visibility',
			'wp_apply_elementor_template',
			'wp_apply_elementor_archetype',
			'wp_apply_elementor_part',
			'wp_build_page',
			'wp_get_blueprint', // POST /elementor/blueprints/build — builds & writes, not a plain read despite the name.
			'wp_save_section_as_template',
			'wp_assign_template',
			'wp_submit_to_google_index',
			'wp_network_switch',
			// WooCommerce archetype/product/order tools — 'wc_' prefix, so the
			// 'wp_' regex never had a chance to match these regardless of verb.
			'wc_create_product_archetype',
			'wc_apply_product_archetype',
			'wc_create_product',
			'wc_update_product',
			'wc_delete_product',
			'wc_create_product_category',
			'wc_update_product_category',
			'wc_update_order',
		);

		if ( in_array( $tool_name, $admin_tools, true ) ) {
			$required_scope = 'admin';
		} elseif ( in_array( $tool_name, $write_tools, true ) || preg_match( '/^wp_(create|update|delete|set|upload|bulk|generate|download)/', $tool_name ) ) {
			$required_scope = 'write';
		} else {
			$required_scope = 'read';
		}

		/**
		 * Filters the required authorization scope for an MCP tool call.
		 *
		 * Implementations may raise, but cannot lower, the inferred requirement.
		 *
		 * @param string $required_scope Inferred scope requirement.
		 * @param string $tool_name      MCP tool name.
		 */
		$filtered_scope = apply_filters(
			'mcpwp_required_scope_for_tool',
			$required_scope,
			$tool_name
		);

		$scope_ranks = array(
			'read'  => 1,
			'write' => 2,
			'admin' => 3,
		);

		if (
			is_string( $filtered_scope )
			&& isset( $scope_ranks[ $filtered_scope ] )
			&& $scope_ranks[ $filtered_scope ] > $scope_ranks[ $required_scope ]
		) {
			return $filtered_scope;
		}

		return $required_scope;
	}

	/**
	 * Check if a key grants a required scope.
	 *
	 * @param array  $key_record    Key record.
	 * @param string $required_scope Scope required for request.
	 * @return bool True if granted.
	 */
	protected function key_has_scope( $key_record, $required_scope ) {
		$scopes = isset( $key_record['scopes'] ) ? (array) $key_record['scopes'] : array();
		$scopes = $this->sanitize_scopes( $scopes );

		if ( in_array( 'admin', $scopes, true ) ) {
			return true;
		}

		if ( 'write' === $required_scope && in_array( 'write', $scopes, true ) ) {
			return true;
		}

		if ( 'read' === $required_scope && ( in_array( 'read', $scopes, true ) || in_array( 'write', $scopes, true ) ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Rank scope for comparison.
	 *
	 * @param string $scope Scope value.
	 * @return int Rank value.
	 */
	protected function scope_rank( $scope ) {
		$ranks = array(
			'read'  => 1,
			'write' => 2,
			'admin' => 3,
		);

		return isset( $ranks[ $scope ] ) ? $ranks[ $scope ] : 1;
	}

	/**
	 * Create and store a scoped API key.
	 *
	 * @param string $label           Human-readable key label.
	 * @param array  $scopes          Scopes for key.
	 * @param string $role            Role slug (admin, author, designer, editor, custom).
	 *                                 Accepted case-insensitively and trimmed
	 *                                 (#1041 NIT-A). Omitted (empty after
	 *                                 normalising) defaults to 'custom' when
	 *                                 tool_categories was supplied, or 'admin'
	 *                                 otherwise (#1041 WARN-B). Any other
	 *                                 value must be one of
	 *                                 get_role_definitions()'s keys or the
	 *                                 call is refused (#1041 WARN-2).
	 * @param array  $tool_categories Tool category slugs (for custom role
	 *                                 only — supplying them with any other
	 *                                 role is refused, #1041 WARN-B).
	 * @return array Key creation result, including plaintext key once, or an
	 *               error shape the caller MUST check for before reading 'key':
	 *               - role unrecognised (#1041 WARN-2): array{error:'unknown_role',
	 *                 message:string, valid_roles:array}
	 *               - non-custom role paired with tool_categories (#1041
	 *                 WARN-B): array{error:'categories_require_custom_role',
	 *                 message:string}
	 *               - custom role whose category list resolves empty (#1041):
	 *                 array{error:'empty_custom_categories', message:string,
	 *                 invalid_categories:array}
	 *               - key store busy (#1096): array{error:'keystore_busy',
	 *                 message:string, retry_after:int}; nothing was written.
	 *
	 * The sixth parameter makes the append atomic with revoking every live key
	 * (key rotation): one store write, so a busy store changes nothing.
	 */
	public function create_scoped_api_key( $label = '', $scopes = array(), $role = 'admin', $tool_categories = array(), $allow_destructive = false, $revoke_existing = false ) {
		$plain_key = $this->generate_api_key();
		$key_id    = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'mcpwp_', true );
		$now       = current_time( 'mysql' );
		// Capture whether the caller supplied any recognisable scope BEFORE
		// sanitize_scopes() runs. It now fails CLOSED to array('read') on empty input,
		// which would otherwise mask "no scopes requested" as "read requested" and
		// demote an admin-role key created without explicit scopes to read-only. Key
		// CREATION is the sanctioned place to default UP to the role ceiling.
		$requested_scopes = array_values(
			array_intersect(
				array_map( 'sanitize_key', (array) $scopes ),
				array( 'read', 'write', 'admin' )
			)
		);
		$scopes = $this->sanitize_scopes( $scopes );

		// #1041 NIT-A: accept role case-insensitively and trimmed — trim()
		// first (sanitize_key() alone would silently DROP internal/edge
		// whitespace rather than treating it as a delimiter, e.g. turning
		// "cus tom" into the valid-looking "custom") then lowercase+validate
		// via sanitize_key(). A value that normalises to "" (omitted, or
		// originally null/whitespace-only) is NOT a garbled role — it is
		// "not specified", and the WARN-B default below decides what that
		// means.
		$role = sanitize_key( trim( (string) $role ) );

		// #1041 WARN-B: whether the caller supplied any tool_categories at
		// all — even an all-invalid list (['bogus']) counts as "supplied",
		// because it is still a clear signal the caller meant to scope this
		// key, not mint an unrestricted/predefined-role one. Computed from
		// the RAW input, before sanitisation, so a garbage-only list still
		// trips the "categories require custom role" refusal below rather
		// than silently looking like "nothing was supplied".
		$categories_supplied = ! empty( (array) $tool_categories );

		if ( '' === $role ) {
			// Unspecified role: default to 'custom' when categories were
			// supplied — the caller is clearly trying to scope the key, and
			// defaulting to 'admin' would silently discard that intent and
			// mint an unrestricted key instead (POST /api-keys
			// {"tool_categories":["seo"]} used to come back role:admin with
			// the categories stored but silently ignored). Default to
			// 'admin' otherwise — unchanged behaviour for a bare create call.
			$role = $categories_supplied ? 'custom' : 'admin';
		}

		$known_roles = array_keys( self::get_role_definitions() );
		if ( ! in_array( $role, $known_roles, true ) ) {
			return array(
				'error'       => 'unknown_role',
				'message'     => sprintf(
					/* translators: 1: the invalid role supplied, 2: comma-separated list of valid roles */
					__( 'Unknown role "%1$s". Valid roles: %2$s.', 'mcpwp' ),
					$role,
					implode( ', ', $known_roles )
				),
				'valid_roles' => $known_roles,
			);
		}

		// #1041 WARN-B: tool_categories only means anything for role=custom.
		// An explicit predefined role (or 'admin') paired with a supplied
		// tool_categories is refused rather than silently storing (and
		// ignoring) them — accepting it would look, to the caller, like the
		// key had been scoped down when it had not.
		if ( 'custom' !== $role && $categories_supplied ) {
			return array(
				'error'   => 'categories_require_custom_role',
				'message' => sprintf(
					/* translators: %s: the role that was supplied alongside tool_categories */
					__( 'tool_categories only applies to the "custom" role; role "%s" ignores it. Omit tool_categories, or set role to "custom".', 'mcpwp' ),
					$role
				),
			);
		}

		// Sanitize tool categories.
		$all_cats             = array_keys( self::get_all_tool_category_labels() );
		$requested_categories = array_values( array_filter( array_map( 'sanitize_key', (array) $tool_categories ), 'strlen' ) );
		$tool_categories      = array_values( array_intersect( $requested_categories, $all_cats ) );

		// #1041: a custom-role key whose resulting category list is empty —
		// either nothing was requested, or every requested slug failed
		// sanitisation/validation (e.g. a typo, a category the plugin has
		// never heard of, or one this build doesn't ship) — is refused
		// outright rather than silently stored as `tool_categories: []`.
		// resolve_key_tool_categories() now (correctly) resolves that to
		// deny-all rather than "every category", so minting it would produce
		// a key that authenticates but can never do anything useful — a
		// worse failure mode than refusing at creation with the specific
		// invalid slugs named. Checked AFTER role validation/coercion above,
		// so a caller who mistyped the role itself (coerced to 'admin') never
		// hits this branch.
		if ( 'custom' === $role && empty( $tool_categories ) ) {
			$invalid = array_values( array_diff( $requested_categories, $all_cats ) );
			return array(
				'error'   => 'empty_custom_categories',
				'message' => empty( $invalid )
					? __( 'A custom-role API key needs at least one tool category. Select one or more categories, or choose a predefined role instead.', 'mcpwp' )
					: sprintf(
						/* translators: %s: comma-separated list of invalid/unrecognised category slugs supplied */
						__( 'None of the requested tool categories are valid: %s. A custom-role API key needs at least one valid category.', 'mcpwp' ),
						implode( ', ', $invalid )
					),
				'invalid_categories' => $invalid,
			);
		}

		// Cap scopes to the role's maximum ceiling — prevents a non-admin role from
		// claiming admin scope even if sanitize_scopes expanded it from 'admin' input.
		$allowed_ceiling = ( 'admin' === $role )
			? array( 'read', 'write', 'admin' )
			: array( 'read', 'write' );
		$scopes = array_values( array_intersect( $scopes, $allowed_ceiling ) );
		if ( empty( $requested_scopes ) || empty( $scopes ) ) {
			// No explicit scopes requested (or all exceeded the ceiling) → grant the
			// role's full ceiling. The ONLY sanctioned "default up".
			$scopes = $allowed_ceiling;
		}

		$record = array(
			'id'              => sanitize_key( (string) $key_id ),
			'label'           => '' !== $label ? sanitize_text_field( $label ) : __( 'API Key', 'mcpwp' ),
			'hash'            => wp_hash_password( $plain_key ),
			'scopes'          => $scopes,
			'role'            => $role,
			'tool_categories' => $tool_categories,
			// Opt-out of the destructive-operation approval gate (#536): an
			// unattended job may say so, once, on a record an admin created.
			'allow_destructive' => (bool) $allow_destructive,
			'created_at'      => $now,
			'last_used_at'    => null,
			'revoked_at'      => null,
		);

		// #1096 P2-4: appended through the key-store write path, to the store
		// as it is NOW — not a snapshot a migration or revoke could have outrun.
		$stored = $this->mutate_scoped_api_keys(
			static function ( array $keys ) use ( $record, $revoke_existing ) {
				if ( $revoke_existing ) {
					foreach ( $keys as &$existing ) {
						if ( is_array( $existing ) && empty( $existing['revoked_at'] ) ) {
							$existing['revoked_at'] = current_time( 'mysql' );
						}
					}
					unset( $existing );
				}
				$keys[] = $record;
				return array( $keys, true );
			},
			true
		);
		if ( ! $stored['written'] ) {
			return $this->keystore_write_refusal( $stored['reason'] );
		}

		return array(
			'id'              => $record['id'],
			'label'           => $record['label'],
			'scopes'          => $record['scopes'],
			'role'            => $record['role'],
			'tool_categories' => $record['tool_categories'],
			'created_at'      => $record['created_at'],
			'key'             => $plain_key,
		);
	}

	/**
	 * List scoped API keys without exposing secrets.
	 *
	 * @param bool $include_revoked Whether to include revoked keys.
	 * @return array Key metadata list.
	 */
	public function list_scoped_api_keys( $include_revoked = false ) {
		$keys      = $this->get_scoped_api_keys_raw();
		$output    = array();
		$last_used = get_option( $this->get_api_key_last_used_option_name(), array() );
		$last_used = is_array( $last_used ) ? $last_used : array();

		foreach ( $keys as $key ) {
			$normalized = $this->normalize_api_key_record( $key );
			if ( ! $include_revoked && ! empty( $normalized['revoked_at'] ) ) {
				continue;
			}

			// #1041 NIT-1: neither internal sentinel — deny_all_category_marker()
			// (a migrated/hand-seeded custom key with an empty category list)
			// nor unknown_role_marker() (a malformed 'role' field, #1041 WARN-1)
			// — belongs in a client-facing listing. Present the resolved
			// verdict instead: tool_categories as the real (possibly empty)
			// list via present_tool_categories(), plus an explicit deny_all
			// flag so a caller (the admin UI, `wp_list_api_keys`) can render
			// "denied everything" rather than mistaking [] for admin's own
			// "unrestricted" empty array — those are opposite meanings and
			// this field is the only thing that tells them apart.
			$deny_all = self::is_deny_all_categories( $this->resolve_key_tool_categories( $normalized ) );

			$output[] = array(
				'id'              => $normalized['id'],
				'label'           => $normalized['label'],
				'scopes'          => $normalized['scopes'],
				'role'            => self::unknown_role_marker() === $normalized['role'] ? 'unknown' : $normalized['role'],
				'tool_categories' => $deny_all ? array() : self::without_reserved_tool_categories( $normalized['tool_categories'] ),
				'deny_all'        => $deny_all,
				'created_at'      => $normalized['created_at'],
				// The separate map wins; a record's own value is pre-#1090 history.
				'last_used_at'    => isset( $last_used[ $normalized['id'] ] ) ? (string) $last_used[ $normalized['id'] ] : $normalized['last_used_at'],
				'revoked_at'      => $normalized['revoked_at'],
				// #1098: adopted from a 2.8 credential (the Setup page says so).
				'migrated_from'   => isset( $key['migrated_from'] ) ? sanitize_key( (string) $key['migrated_from'] ) : '',
			);
		}

		return $output;
	}

	/**
	 * Revoke a scoped API key.
	 *
	 * True ONLY when this call revoked it. False covers "not found or already
	 * revoked" AND "store busy"; a caller that must tell them apart (anything
	 * that reports the result to a person, or forgets state on success) uses
	 * revoke_scoped_api_key_outcome().
	 *
	 * @param string $key_id Key identifier.
	 * @return bool True if revoked.
	 */
	public function revoke_scoped_api_key( $key_id ) {
		return 'revoked' === $this->revoke_scoped_api_key_outcome( $key_id );
	}

	/**
	 * Revoke a scoped API key and say what happened (#1096).
	 *
	 * @param string $key_id Key identifier.
	 * @return string 'revoked' (this call revoked it), 'not_found' (no such key,
	 *                or already revoked: nothing to do), 'busy' (the key store
	 *                could not be written; the key is NOT revoked, retry) or
	 *                'unreadable' (the stored keys are corrupt; retrying cannot
	 *                help and nothing was changed).
	 */
	public function revoke_scoped_api_key_outcome( $key_id ) {
		$key_id = sanitize_key( (string) $key_id );
		if ( '' === $key_id ) {
			return 'not_found';
		}

		// #1096 P2-4: flipped through the key-store write path, on the store as
		// it is now. A busy store answers 'busy', never a silent success.
		$outcome = $this->mutate_scoped_api_keys(
			function ( array $keys ) use ( $key_id ) {
				$updated = false;
				foreach ( $keys as &$key ) {
					$normalized = $this->normalize_api_key_record( $key );
					if ( $normalized['id'] !== $key_id ) {
						continue;
					}

					if ( empty( $normalized['revoked_at'] ) ) {
						$key['revoked_at'] = current_time( 'mysql' );
						$updated           = true;
					}
				}
				unset( $key );
				return array( $updated ? $keys : null, $updated );
			},
			true
		);
		if ( ! $outcome['written'] ) {
			return 'unreadable' === $outcome['reason'] ? 'unreadable' : 'busy';
		}

		// The legacy `mcpwp_api_key` hash is deliberately LEFT in place: three
		// surfaces read it as "this site has been set up" (Setup page, the
		// activator's first-key mint, test-connection), and deleting it made
		// the Setup page offer a hero "Generate API Key" that revokes every
		// key (#1097 review round 2). It cannot authenticate any more —
		// legacy_api_key_option_is_honoured() refuses it once the scoped store
		// holds any record, revoked records included.
		if ( $outcome['result'] ) {
			// Best effort: an in-flight touch can re-add a display-only entry.
			$this->forget_api_key_last_used( array( $key_id ) );
			return 'revoked';
		}

		return 'not_found';
	}

	/**
	 * Migrate legacy single-key storage to scoped key storage.
	 *
	 * @param string $legacy_key Legacy key option value.
	 * @return array|null Migrated key record.
	 */
	protected function migrate_legacy_key_to_scoped_store( $legacy_key ) {
		if ( empty( $legacy_key ) ) {
			return null;
		}

		$legacy_key      = (string) $legacy_key;
		$legacy_is_hashed = $this->looks_like_password_hash( $legacy_key );
		$legacy_hash      = $legacy_key;

		if ( ! $legacy_is_hashed ) {
			$legacy_hash = wp_hash_password( $legacy_key );
			update_option( 'mcpwp_api_key', $legacy_hash );
		}

		$migrated = array(
			'id'           => sanitize_key( function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'mcpwp_', true ) ),
			'label'        => __( 'Primary API Key (migrated)', 'mcpwp' ),
			'hash'         => $legacy_hash,
			'scopes'       => $this->get_default_api_key_scopes(),
			'created_at'   => current_time( 'mysql' ),
			'last_used_at' => null,
			'revoked_at'   => null,
		);

		// #1096 P2-4: decided on the store as it is NOW, under the key-store
		// lock. This used to read the store, then write the whole array back,
		// so a revoke, a new key or a rotation landing in between was
		// overwritten. Inside the lock:
		// - an active record already carrying this key wins (another request
		//   migrated it first);
		// - ANY other record means the store is no longer the empty
		//   pre-multi-key store the legacy option is honoured for (this key may
		//   well be a revoked one): refuse, never re-create it.
		$outcome = $this->mutate_scoped_api_keys(
			function ( array $keys ) use ( $legacy_key, $legacy_is_hashed, $legacy_hash, $migrated ) {
				foreach ( $keys as $key ) {
					$normalized = $this->normalize_api_key_record( $key );
					if ( ! empty( $normalized['revoked_at'] ) ) {
						continue;
					}
					$is_existing_match = $legacy_is_hashed
						? hash_equals( (string) $normalized['hash'], $legacy_hash )
						: $this->is_api_key_match( $legacy_key, $normalized['hash'] );

					if ( $is_existing_match ) {
						return array( null, $normalized );
					}
				}
				if ( ! empty( $keys ) ) {
					return array( null, null );
				}

				$keys[] = $migrated;
				return array( $keys, $this->normalize_api_key_record( $migrated ) );
			},
			false
		);

		// A store that is busy, or a write that did not verify, is a refusal:
		// this request fails and the next one retries.
		return is_array( $outcome['result'] ) ? $outcome['result'] : null;
	}

	/**
	 * The error shape for a key-store write that was refused (#1096).
	 *
	 * @param string $reason Mcpwp_Key_Store::mutate() reason.
	 * @return array{error:string,message:string,retry_after?:int}
	 */
	protected function keystore_write_refusal( $reason ) {
		switch ( $reason ) {
			case 'unsupported_characters':
				return array(
					'error'   => 'unsupported_characters',
					'message' => __( 'The database table that stores API keys cannot hold some of the characters in this key (for example in the label). Nothing was saved. Use only characters your database table supports; plain Latin letters always work.', 'mcpwp' ),
				);
			case 'unreadable':
				if ( ! class_exists( 'Mcpwp_Key_State' ) ) {
					require_once dirname( __DIR__ ) . '/core/class-mcpwp-key-state.php';
				}
				return array(
					'error'   => 'keystore_unreadable',
					'message' => Mcpwp_Key_State::unreadable_message(),
				);
			case 'unwritable':
				return array(
					'error'   => 'keystore_write_failed',
					'message' => __( 'Writing the API key store failed and was rolled back. Nothing was changed.', 'mcpwp' ),
				);
			default:
				return array(
					'error'       => 'keystore_busy',
					'message'     => __( 'The API key store is busy. Nothing was saved; try again in a moment.', 'mcpwp' ),
					'retry_after' => 1,
				);
		}
	}

	/**
	 * Read-modify-write the scoped key store without losing a concurrent write.
	 *
	 * Delegates to Mcpwp_Key_Store::mutate(), the single write path: store lock,
	 * a read of the database row (never the options cache), and a
	 * compare-and-swap write. See that class for the full argument. Fails
	 * closed: a busy store or a swap that keeps missing changes nothing and says
	 * so in `reason`.
	 *
	 * @param callable $mutator Receives (array $keys, bool $exists); returns array( $new_keys_or_null, $result ).
	 * @param bool     $patient Retry the lock for ~1.5 s (admin writes).
	 * @return array{locked:bool,written:bool,result:mixed,reason:string}
	 */
	protected function mutate_scoped_api_keys( callable $mutator, $patient ) {
		if ( ! class_exists( 'Mcpwp_Key_Store' ) ) {
			require_once dirname( __DIR__ ) . '/core/class-mcpwp-key-store.php';
		}
		return Mcpwp_Key_Store::mutate( $mutator, $patient );
	}

	/**
	 * Drop last-used entries for keys that were revoked or removed.
	 *
	 * Without this the map only ever grew: a revoked key's id stayed in it for
	 * good. Best effort and lock-free by design: it only touches the timestamp
	 * map, where a lost update costs a timestamp, never a key.
	 *
	 * @param array $key_ids Key ids to forget.
	 */
	protected function forget_api_key_last_used( array $key_ids ) {
		$name = $this->get_api_key_last_used_option_name();
		$map  = get_option( $name, array() );
		if ( ! is_array( $map ) || empty( $map ) ) {
			return;
		}
		$pruned = array_diff_key( $map, array_flip( array_map( 'strval', $key_ids ) ) );
		if ( count( $pruned ) !== count( $map ) ) {
			update_option( $name, $pruned, false );
		}
	}

	/**
	 * Option holding last-used timestamps, keyed by scoped key id.
	 *
	 * Kept OUT of the key store on purpose. touch_api_key_last_used() runs on
	 * every authenticated request; when it rewrote the whole key array from the
	 * snapshot read before the password-hash loop, a request in flight across a
	 * revoke wrote `revoked_at = null` back and resurrected the key (adversarial
	 * review of #1090, P1-2). Losing a timestamp to a race is harmless; losing a
	 * revocation is not, so the request path never writes the key store.
	 *
	 * NOT autoloaded, on purpose (#1096). In core, update_option() on an
	 * autoloaded option force-loads `alloptions` and then wp_cache_set()s it. The
	 * key store lives in that same `alloptions` entry, so a touch (which runs on
	 * every authenticated request) stalled between that load and that set could
	 * write a PRE-revoke copy of the key store back over a revoke's cache
	 * refresh, and a revoked key would stay live on a persistent cache. A
	 * non-autoloaded option never writes `alloptions`. The cost is one option
	 * read per request on a site WITHOUT a persistent object cache; with one it
	 * is cached under its own key.
	 *
	 * @return string
	 */
	protected function get_api_key_last_used_option_name() {
		return 'mcpwp_api_keys_last_used';
	}

	/**
	 * Record that a scoped API key was just used.
	 *
	 * Writes only the separate last-used map, at most once a minute per key.
	 *
	 * @param string $key_id Key identifier.
	 */
	protected function touch_api_key_last_used( $key_id ) {
		$key_id = sanitize_key( (string) $key_id );
		if ( '' === $key_id ) {
			return;
		}

		$map = get_option( $this->get_api_key_last_used_option_name(), array() );
		$map = is_array( $map ) ? $map : array();
		$now = current_time( 'mysql' );

		if ( isset( $map[ $key_id ] ) && ( strtotime( $now ) - strtotime( (string) $map[ $key_id ] ) ) < 60 ) {
			return;
		}

		$map[ $key_id ] = $now;
		update_option( $this->get_api_key_last_used_option_name(), $map, false );
	}

	/**
	 * Get raw scoped API key storage.
	 *
	 * @return array Raw key records.
	 */
	protected function get_scoped_api_keys_raw() {
		$keys = get_option( $this->get_scoped_api_keys_option_name(), array() );
		return is_array( $keys ) ? $keys : array();
	}

	/**
	 * Normalize key record fields.
	 *
	 * Role handling (#1041 WARN-1, round-2 adversarial review of PR #1043):
	 * a record with NO 'role' field at all is genuinely legacy — created
	 * before role-scoping existed, when every key was implicitly full-access
	 * — and normalizes to 'admin' (unrestricted; see
	 * resolve_key_tool_categories()). A record whose 'role' field IS present
	 * but does not sanitize to one of get_role_definitions()'s keys (empty,
	 * numeric, a typo, a stale role a build removed) is malformed data, not a
	 * legacy key, and must NOT collapse to 'admin' — that was the exact
	 * silent-grant bug this fixes: live, a hand-seeded record with role
	 * 'viewer', '', null or 5 (any of them present-but-invalid) got full
	 * admin access. It normalizes to unknown_role_marker() instead, which
	 * resolve_key_tool_categories() resolves to deny-all like any other
	 * unrecognised role — the record still authenticates (never revoked or
	 * deleted here), it just cannot reach any real category.
	 *
	 * #1041 WARN-A (round-3 review): "no role field" is ONLY genuinely legacy
	 * when tool_categories is ALSO absent/empty — role-scoping and
	 * tool_categories were introduced together, so a real pre-roles MCPWP key
	 * never carries the latter without the former. A roleless record that
	 * DOES carry a non-empty tool_categories is a different writer's shape
	 * entirely (the wp.org "Mumega MCP" edition writes exactly
	 * {scopes, tool_categories} with no role field to this same
	 * mcpwp_api_keys option) and normalizes to 'custom' with those
	 * categories instead of silently inheriting every category.
	 *
	 * @param array $record Key record.
	 * @return array Normalized key record.
	 */
	protected function normalize_api_key_record( $record ) {
		$record = is_array( $record ) ? $record : array();

		$known_roles     = array_keys( self::get_role_definitions() );
		$raw_categories  = isset( $record['tool_categories'] ) ? (array) $record['tool_categories'] : array();

		if ( ! array_key_exists( 'role', $record ) ) {
			$role = empty( $raw_categories ) ? 'admin' : 'custom';
		} else {
			$raw_role = sanitize_key( (string) $record['role'] );
			$role     = in_array( $raw_role, $known_roles, true ) ? $raw_role : self::unknown_role_marker();
		}

		$tool_categories = array_map( 'sanitize_key', $raw_categories );

		return array(
			'id'              => isset( $record['id'] ) ? sanitize_key( (string) $record['id'] ) : '',
			'label'           => isset( $record['label'] ) ? sanitize_text_field( (string) $record['label'] ) : __( 'API Key', 'mcpwp' ),
			'hash'            => isset( $record['hash'] ) ? (string) $record['hash'] : '',
			'scopes'          => $this->normalize_record_scopes( $record, $role ),
			'role'            => $role,
			'tool_categories' => $tool_categories,
			'rate_limits'     => $this->sanitize_rate_limits( isset( $record['rate_limits'] ) ? $record['rate_limits'] : null ),
			'allow_destructive' => ! empty( $record['allow_destructive'] ),
			'created_at'      => isset( $record['created_at'] ) ? (string) $record['created_at'] : null,
			'last_used_at'    => isset( $record['last_used_at'] ) ? (string) $record['last_used_at'] : null,
			'revoked_at'      => isset( $record['revoked_at'] ) ? (string) $record['revoked_at'] : null,
		);
	}

	/**
	 * Resolve a stored key record's scopes, now that sanitize_scopes() fails closed.
	 *
	 * - 'scopes' key ENTIRELY ABSENT → a legacy record predating scoped keys; these were
	 *   effectively their role's full grant. Derive from the (already validated) role so
	 *   the fail-closed change does not silently demote every legacy admin key to read.
	 * - 'scopes' PRESENT but empties out (corrupt/hand-seeded) → genuinely ambiguous;
	 *   fail closed to array('read') via sanitize_scopes().
	 *
	 * @param array  $record Raw key record.
	 * @param string $role   Already-resolved role slug — 'admin' for a
	 *                       roleless legacy record, a real role, or
	 *                       unknown_role_marker() for a present-but-invalid
	 *                       one (#1041 WARN-1). A scopeless malformed-role
	 *                       record therefore defaults to [read,write], not
	 *                       the full admin grant — less privilege, fail closed.
	 * @return array Scope list.
	 */
	protected function normalize_record_scopes( $record, $role ) {
		if ( ! array_key_exists( 'scopes', $record ) ) {
			return ( 'admin' === $role )
				? array( 'read', 'write', 'admin' )
				: array( 'read', 'write' );
		}
		return $this->sanitize_scopes( (array) $record['scopes'] );
	}

	/**
	 * Sanitize and normalize scopes list.
	 *
	 * @param array $scopes Scopes input.
	 * @return array Sanitized scopes.
	 */
	/**
	 * Sanitize per-key rate limit overrides.
	 *
	 * Accepts an optional array with keys: burst, per_minute, per_hour.
	 * Returns null if no valid overrides are present, or a sanitized array.
	 *
	 * @param mixed $rate_limits Raw rate_limits value from key record.
	 * @return array|null Sanitized rate limits or null.
	 */
	protected function sanitize_rate_limits( $rate_limits ) {
		if ( ! is_array( $rate_limits ) ) {
			return null;
		}

		$sanitized = array();
		$valid_keys = array( 'burst', 'per_minute', 'per_hour' );

		foreach ( $valid_keys as $key ) {
			if ( isset( $rate_limits[ $key ] ) ) {
				$sanitized[ $key ] = max( 1, min( 100000, (int) $rate_limits[ $key ] ) );
			}
		}

		return empty( $sanitized ) ? null : $sanitized;
	}

	protected function sanitize_scopes( $scopes ) {
		$allowed = array( 'read', 'write', 'admin' );
		$scopes  = array_map( 'sanitize_key', (array) $scopes );
		$scopes  = array_values( array_intersect( $scopes, $allowed ) );

		if ( empty( $scopes ) ) {
			// Fail CLOSED. Empty/unrecognised stored scopes are ambiguous data, not a
			// grant — return the minimum privilege. get_default_api_key_scopes()
			// (read+write+admin) is reserved for the explicit key-CREATION path
			// (create_scoped_api_key) and legacy-key migration, never for runtime
			// validation of stored scopes. This is the canonical null-collapse-to-trusted
			// this repo had: key_has_scope(), issue_oauth_access_token() and
			// authenticate_oauth_access_token() all re-run this on every gated request.
			// normalize_api_key_record() preserves legacy scopeless admin keys without
			// this fallback (see normalize_record_scopes()).
			return array( 'read' );
		}

		if ( in_array( 'admin', $scopes, true ) ) {
			return array( 'read', 'write', 'admin' );
		}

		if ( in_array( 'write', $scopes, true ) && ! in_array( 'read', $scopes, true ) ) {
			$scopes[] = 'read';
		}

		return array_values( array_unique( $scopes ) );
	}

	/**
	 * Get default key scopes.
	 *
	 * @return array Default scopes.
	 */
	protected function get_default_api_key_scopes() {
		return array( 'read', 'write', 'admin' );
	}

	/**
	 * Get available role definitions.
	 *
	 * Each role maps to a set of tool categories the key can access.
	 *
	 * @return array Map of role_slug => array with 'label', 'description', 'categories'.
	 */
	public static function get_role_definitions() {
		return array(
			'admin'    => array(
				'label'       => __( 'Admin', 'mcpwp' ),
				'description' => __( 'Full access to all tool categories.', 'mcpwp' ),
				'categories'  => array(), // Empty = all categories allowed.
			),
			'author'   => array(
				'label'       => __( 'Author', 'mcpwp' ),
				'description' => __( 'Content writing — pages, posts, media, taxonomy.', 'mcpwp' ),
				// No 'meta': mcpwp_find_tools/mcpwp_call are allowed to every
				// key by name (get_key_exempt_tool_names(), #1064).
				'categories'  => array( 'content', 'media', 'taxonomy' ),
			),
			'designer' => array(
				'label'       => __( 'Designer', 'mcpwp' ),
				'description' => __( 'Visual building — Elementor, Gutenberg, media, site settings.', 'mcpwp' ),
				'categories'  => array( 'elementor', 'elementor-build', 'elementor-templates', 'elementor-theme', 'elementor-info', 'gutenberg', 'media', 'site' ),
			),
			'editor'   => array(
				'label'       => __( 'Editor', 'mcpwp' ),
				'description' => __( 'Content + SEO management.', 'mcpwp' ),
				'categories'  => array( 'content', 'media', 'taxonomy', 'seo', 'elementor', 'elementor-build', 'elementor-templates', 'elementor-theme', 'elementor-info' ),
			),
			'custom'   => array(
				'label'       => __( 'Custom', 'mcpwp' ),
				'description' => __( 'Pick individual tool categories.', 'mcpwp' ),
				'categories'  => array(), // User-defined.
			),
		);
	}

	/**
	 * Get all known tool category slugs.
	 *
	 * @return array Map of category_slug => label.
	 */
	public static function get_all_tool_category_labels() {
		return array(
			'content'             => __( 'Content', 'mcpwp' ),
			'media'               => __( 'Media', 'mcpwp' ),
			'taxonomy'            => __( 'Taxonomy', 'mcpwp' ),
			'elementor'           => __( 'Elementor', 'mcpwp' ),
			'elementor-build'     => __( 'Elementor Build', 'mcpwp' ),
			'elementor-templates' => __( 'Elementor Templates', 'mcpwp' ),
			'elementor-theme'     => __( 'Elementor Theme', 'mcpwp' ),
			'elementor-info'      => __( 'Elementor Info', 'mcpwp' ),
			'gutenberg'           => __( 'Gutenberg', 'mcpwp' ),
			'seo'                 => __( 'SEO', 'mcpwp' ),
			'forms'               => __( 'Forms', 'mcpwp' ),
			'site'                => __( 'Site', 'mcpwp' ),
			'admin'               => __( 'Admin', 'mcpwp' ),
			'webhooks'            => __( 'Webhooks', 'mcpwp' ),
			'ai'                  => __( 'AI', 'mcpwp' ),
			'woocommerce'         => __( 'WooCommerce', 'mcpwp' ),
			'learnpress'          => __( 'LearnPress', 'mcpwp' ),
			'events'              => __( 'Events', 'mcpwp' ),
			'multisite'           => __( 'Multisite', 'mcpwp' ),
			'staging'             => __( 'Staging', 'mcpwp' ),
			// No 'meta' (#1064 review round 2): mcpwp_find_tools and
			// mcpwp_call are allowed to every key by name, so there is no
			// per-key "Tool Search & Dispatch" choice. The slug is reserved
			// (reserved_tool_category_slugs()); the site-wide toggle on the
			// Tools page can still disable it.
		);
	}

	/**
	 * Internal sentinel meaning "this key is restricted to NO category" —
	 * distinct from the empty-array return of resolve_key_tool_categories()
	 * itself, which means "unrestricted" (#1041).
	 *
	 * Before #1041, a custom-role key whose tool_categories resolved to an
	 * empty array — either because none were ever selected, or because every
	 * selected slug failed sanitisation (e.g. a stale/typo'd category from a
	 * pre-#907 client) — was indistinguishable from an admin/legacy key at
	 * every one of resolve_key_tool_categories()'s ~10 call sites, ALL of
	 * which treat `empty($allowed)` as "no restriction". A key created that
	 * way silently inherited every category, including ones a site had
	 * explicitly disabled. Live evidence: such a key saw all 804 entries in
	 * the action log.
	 *
	 * The fix returns array( self::deny_all_category_marker() ) instead of
	 * array() whenever a NON-admin, NON-legacy resolution would otherwise be
	 * empty. This sentinel can never equal a real category slug (see
	 * get_all_tool_category_labels() — none use a leading/trailing double
	 * underscore), so:
	 *   - every `empty($allowed)` check downstream is FALSE (correctly: the
	 *     key IS restricted), and
	 *   - every `in_array($category, $allowed, true)` check is FALSE for any
	 *     real category (correctly: nothing is allowed).
	 * Every existing consumer therefore falls through to its own
	 * already-correct "restricted, category not in the allowed list" branch
	 * with ZERO code changes — this is a chokepoint fix, not a per-call-site
	 * one. The one exception is human/machine-readable output that would
	 * otherwise print the raw sentinel string; use
	 * describe_allowed_categories_for_message() there.
	 *
	 * A key at this state can still authenticate and can still reach the
	 * discovery allowlist (get_discovery_allowlist_routes() /
	 * get_discovery_allowlist_tool_names() — /site-info, /onboard,
	 * /introspect, and read-only /site-context — are checked before any
	 * per-key category list is consulted, exactly as for every other role).
	 *
	 * @return string
	 */
	public static function deny_all_category_marker() {
		return '__mcpwp_deny_all__';
	}

	/**
	 * Internal marker for a stored 'role' field that is PRESENT but does not
	 * sanitize to one of get_role_definitions()'s keys — empty string,
	 * numeric, a typo, a stale role name a build removed (#1041 WARN-1,
	 * round-2 adversarial review of PR #1043).
	 *
	 * normalize_api_key_record() used to coerce this exact case to 'admin',
	 * the same silent-grant shape #1041's own fix closed for empty custom
	 * categories — live: a hand-seeded record with role 'viewer', '', null or
	 * 5 got full admin access (200 on /action-log, all 172 tools listed).
	 * This marker keeps that distinct from BOTH a real 'admin' role AND a
	 * genuinely legacy pre-roles record (no 'role' field at all, still the
	 * only unrestricted-by-absence case — see resolve_key_tool_categories()).
	 * It is not a category slug, never returned by resolve_key_tool_categories()
	 * itself, and never stored back into a record — it exists only as the
	 * normalized 'role' value a malformed record resolves to, which
	 * resolve_key_tool_categories() then maps to deny-all like any other
	 * unrecognised role.
	 *
	 * @return string
	 */
	public static function unknown_role_marker() {
		return '__mcpwp_unknown_role__';
	}

	/**
	 * Whether a resolve_key_tool_categories() result represents "restricted
	 * to nothing" (the deny-all sentinel) rather than a real, non-empty
	 * category list.
	 *
	 * @param array $categories Return value of resolve_key_tool_categories().
	 * @return bool
	 */
	public static function is_deny_all_categories( $categories ) {
		return array( self::deny_all_category_marker() ) === array_values( (array) $categories );
	}

	/**
	 * Render a resolve_key_tool_categories() result for a human- or
	 * machine-readable field, never leaking the internal deny-all sentinel
	 * (#1041) — callers that used to `implode( ', ', $allowed )` directly, or
	 * hand $allowed to a client as-is, should go through this instead.
	 *
	 * @param array $categories Return value of resolve_key_tool_categories().
	 * @return string Comma-separated category list, or '(none)' for deny-all.
	 */
	public static function describe_allowed_categories_for_message( $categories ) {
		if ( self::is_deny_all_categories( $categories ) ) {
			return __( '(none)', 'mcpwp' );
		}
		return implode( ', ', (array) $categories );
	}

	/**
	 * Render a key record's 'role' field for a human- or machine-readable
	 * surface, never leaking unknown_role_marker() (#1041 WARN-1 round-2
	 * adversarial review of PR #1043) — every "role: %s" message and the MCP
	 * `initialize` `role`/`tool_categories` fields read $key_record['role']
	 * directly; a malformed-role record's normalized role is the internal
	 * marker string, not something a client (or an admin reading a log) is
	 * meant to see.
	 *
	 * @param string $role Raw 'role' value from a (normalized) key record.
	 * @return string The role as-is, or 'unknown' for the marker.
	 */
	public static function describe_role_for_message( $role ) {
		return self::unknown_role_marker() === $role ? 'unknown' : (string) $role;
	}

	/**
	 * The same rendering as describe_allowed_categories_for_message(), but as
	 * a real (possibly empty) array rather than a string — for a structured,
	 * client-consumed field (e.g. MCP `initialize`'s `tool_categories`)
	 * rather than a prose message.
	 *
	 * @param array $categories Return value of resolve_key_tool_categories().
	 * @return array Category slugs, or [] for deny-all.
	 */
	public static function present_tool_categories( $categories ) {
		return self::is_deny_all_categories( $categories ) ? array() : (array) $categories;
	}

	/**
	 * Category slugs no key can be granted and no addon tool may use
	 * (#1064 review round 2). `meta` holds only mcpwp_find_tools and
	 * mcpwp_call, which every key may use BY NAME
	 * (get_key_exempt_tool_names()). Granting the category instead let any
	 * addon tool registered in `meta` reach every restricted key, deny-all
	 * included (#1041). The site-wide toggle can still disable `meta`.
	 *
	 * @return string[]
	 */
	public static function reserved_tool_category_slugs() {
		return array( 'meta' );
	}

	/**
	 * A stored category list without the reserved slugs.
	 *
	 * @param array $categories Category slugs.
	 * @return string[]
	 */
	public static function without_reserved_tool_categories( $categories ) {
		return array_values( array_diff( array_map( 'strval', (array) $categories ), self::reserved_tool_category_slugs() ) );
	}

	/**
	 * Tool NAMES every key may use whatever its role's categories
	 * (#1064 review B1, narrowed in round 2): the two meta tools. Neither
	 * can reach a tool the key could not call directly — find_tools
	 * searches the caller's own catalog and mcpwp_call re-runs every gate
	 * on the tool it names. Without them the `core` default profile
	 * stranded keys that were never granted `meta`. Must equal
	 * Mcpwp_REST_MCP::META_TOOL_NAMES (ToolWeightBudgetReviewTest pins it).
	 *
	 * @return string[]
	 */
	public static function get_key_exempt_tool_names() {
		return array( 'mcpwp_find_tools', 'mcpwp_call' );
	}

	/**
	 * The per-key role gate for ONE tool — the single decision tools/list,
	 * tools/call, mcpwp_call's inner call, the idempotent-replay gate and
	 * introspection all use (#1064 review round 2). The site-wide category
	 * toggle is separate and still applies to every tool but the discovery
	 * allowlist.
	 *
	 * @param string     $tool_name     Tool name.
	 * @param string     $tool_category The tool's enforced category ('' if none).
	 * @param array|null $key_record    Caller's key record, or null (no key context).
	 * @return bool
	 */
	public function key_role_permits_tool( $tool_name, $tool_category, $key_record ) {
		if ( ! is_array( $key_record ) ) {
			return true;
		}
		if ( in_array( $tool_name, self::get_discovery_allowlist_tool_names(), true )
			|| in_array( $tool_name, self::get_key_exempt_tool_names(), true ) ) {
			return true;
		}
		$allowed = $this->resolve_key_tool_categories( $key_record );
		if ( empty( $allowed ) ) {
			return true;
		}
		// An uncategorised tool is denied to a restricted key (#802 round 6).
		return '' !== (string) $tool_category && in_array( (string) $tool_category, $allowed, true );
	}

	/**
	 * Resolve allowed tool categories for a key record.
	 *
	 * Only role=admin and a legacy/migrated key (no 'role' field at all) are
	 * unrestricted, in which case this returns array() — every caller of this
	 * method treats an empty array as "no restriction", so array() is
	 * reserved EXCLUSIVELY for those two cases (#1041). Every other
	 * resolution — a predefined role, or a custom role — returns either a
	 * real, non-empty category list or array( self::deny_all_category_marker() ),
	 * never a bare array(). See deny_all_category_marker()'s docblock for why
	 * that sentinel is safe to hand to every existing `empty($allowed)` /
	 * `in_array($category, $allowed, true)` consumer unchanged.
	 *
	 * OAuth and broker principals (authenticate_oauth_access_token(),
	 * authenticate_broker_bearer_token()) never carry a 'role' field either,
	 * so they take the same "legacy" branch here and are documented as
	 * unrestricted by category — the per-key restriction this method
	 * expresses is scoped-API-key-only; those principals are governed by
	 * scope (read/write/admin) instead.
	 *
	 * @param array $key_record Normalized key record.
	 * @return array Allowed category slugs; array() ONLY for admin/legacy
	 *               (unrestricted); array(deny_all_category_marker()) for a
	 *               custom (or otherwise unrecognised) role with no real
	 *               category granted.
	 */
	public function resolve_key_tool_categories( $key_record ) {
		// A record with NO 'role' field at all is the ONLY unrestricted-by-
		// absence case — a genuinely legacy, pre-roles key (#1041 WARN-1).
		// This must be array_key_exists(), not isset(): a record could in
		// principle carry an explicit `'role' => null`, which isset() also
		// treats as absent but which normalize_api_key_record() resolves to
		// unknown_role_marker() (present-but-invalid), not 'admin' — the two
		// must not be conflated here either.
		if ( ! array_key_exists( 'role', $key_record ) ) {
			// #1041 WARN-A (round-3 adversarial review): a real legacy MCPWP
			// key predating role-scoping has no 'tool_categories' field
			// either — the two were introduced together. A roleless record
			// that DOES carry a non-empty tool_categories is not that shape;
			// it is what a third-party writer to this same wp_options row
			// produces (the wp.org "Mumega MCP" edition writes exactly
			// {scopes, tool_categories} with no role field). Treating it as
			// legacy-unrestricted would silently grant it every category, so
			// it is resolved as 'custom' with those categories instead — the
			// same outcome the 'custom' branch below gives a normalized
			// record shaped like this.
			$raw_categories = ( isset( $key_record['tool_categories'] ) && is_array( $key_record['tool_categories'] ) )
				? array_values( $key_record['tool_categories'] )
				: array();
			if ( ! empty( $raw_categories ) ) {
				// A reserved slug grants nothing (#1064 review round 2). A
				// list that held only reserved slugs was still a
				// restriction, so it is deny-all, never unrestricted.
				$granted = self::without_reserved_tool_categories( $raw_categories );
				return empty( $granted ) ? array( self::deny_all_category_marker() ) : $granted;
			}
			return array();
		}

		$role = (string) $key_record['role'];

		// Admin role: unrestricted. An empty string is NOT treated as
		// unrestricted here (#1041 WARN-1) — normalize_api_key_record() never
		// emits '' for 'role' (a present-but-empty value resolves to
		// unknown_role_marker()), so this stays strictly "role literally is
		// the string 'admin'".
		if ( 'admin' === $role ) {
			return array();
		}

		// Custom role: use the stored tool_categories. An empty list here
		// used to be conflated with "no restriction" (#1041) — it now means
		// "restricted to nothing", signalled via the deny-all sentinel so
		// every downstream `empty()`/`in_array()` check keeps working.
		if ( 'custom' === $role ) {
			$categories = ( isset( $key_record['tool_categories'] ) && is_array( $key_record['tool_categories'] ) )
				? self::without_reserved_tool_categories( $key_record['tool_categories'] )
				: array();
			return empty( $categories ) ? array( self::deny_all_category_marker() ) : $categories;
		}

		// Predefined role: look up from definitions. Every current
		// definition's 'categories' is non-empty; the empty() fallback is
		// defense-in-depth for a future role definition that forgets to list
		// any — same "only admin/legacy is unrestricted" rule, not a special
		// case for predefined roles.
		$roles = self::get_role_definitions();
		if ( isset( $roles[ $role ] ) ) {
			$categories = (array) $roles[ $role ]['categories'];
			return empty( $categories ) ? array( self::deny_all_category_marker() ) : $categories;
		}

		// Unknown role: reachable both for a raw, non-normalized record and,
		// since #1041 WARN-1, for a NORMALIZED one too —
		// normalize_api_key_record() now resolves a present-but-invalid role
		// to unknown_role_marker() rather than silently coercing it to
		// 'admin' (the exact silent-grant bug WARN-1 found and closed).
		// Treated as deny-all — NOT unrestricted — because an unrecognised
		// role is not "documented as unrestricted"; only admin and legacy
		// (roleless) keys are.
		return array( self::deny_all_category_marker() );
	}

	/**
	 * Check whether a key is allowed to access a given tool category.
	 *
	 * @param array  $key_record Normalized key record.
	 * @param string $category   Tool category slug.
	 * @return bool True if allowed.
	 */
	public function key_allows_category( $key_record, $category ) {
		$allowed = $this->resolve_key_tool_categories( $key_record );

		// Empty = unrestricted (admin role or legacy key). A restricted key —
		// including one restricted to nothing, i.e. deny_all_category_marker()
		// — is never empty here (#1041), so it always falls through to the
		// in_array() check below, which is false for every real category.
		if ( empty( $allowed ) ) {
			return true;
		}

		return in_array( $category, $allowed, true );
	}

	/**
	 * Get OAuth settings.
	 *
	 * @return array OAuth settings.
	 */
	protected function get_oauth_settings() {
		$settings = get_option( 'mcpwp_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();

		// Ceiling for the client_credentials grant (machine tokens). Resolved
		// through an explicit allow-list (read/write/admin) rather than through the
		// scope helpers, so its semantics are self-contained and cannot drift with
		// them. An unrecognised or absent value falls back to 'write' — the documented
		// default. That fallback is a two-sided guarantee: it stops a bad value
		// widening the ceiling, and it stops an unset option narrowing every
		// machine client to read and breaking existing integrations.
		$cc_max_scope = isset( $settings['oauth_client_credentials_max_scope'] )
			? sanitize_key( (string) $settings['oauth_client_credentials_max_scope'] )
			: '';
		if ( ! in_array( $cc_max_scope, array( 'read', 'write', 'admin' ), true ) ) {
			$cc_max_scope = 'write';
		}

		// D10: absent means ON; a stored value (on or off) is respected.  One
		// resolver for every reader — see Mcpwp_OAuth_Well_Known::resolve_oauth_enabled().
		$oauth_enabled = class_exists( 'Mcpwp_OAuth_Well_Known' )
			? Mcpwp_OAuth_Well_Known::resolve_oauth_enabled( $settings )
			: ( ! array_key_exists( 'oauth_enabled', $settings ) || ! empty( $settings['oauth_enabled'] ) );

		return array(
			'oauth_enabled'                      => $oauth_enabled,
			'oauth_client_id'                    => isset( $settings['oauth_client_id'] ) ? sanitize_key( (string) $settings['oauth_client_id'] ) : 'site_pilot_ai',
			'oauth_client_secret_hash'           => isset( $settings['oauth_client_secret_hash'] ) ? (string) $settings['oauth_client_secret_hash'] : '',
			'oauth_token_ttl'                    => isset( $settings['oauth_token_ttl'] ) ? max( 300, min( 86400, absint( $settings['oauth_token_ttl'] ) ) ) : 3600,
			'oauth_client_credentials_max_scope' => $cc_max_scope,
		);
	}

	/**
	 * Verify OAuth client credentials.
	 *
	 * @param string $client_id     Client ID.
	 * @param string $client_secret Client secret.
	 * @return bool True when credentials are valid.
	 */
	protected function verify_oauth_client_credentials( $client_id, $client_secret ) {
		$oauth_settings = $this->get_oauth_settings();

		if ( empty( $oauth_settings['oauth_enabled'] ) || empty( $oauth_settings['oauth_client_secret_hash'] ) ) {
			return false;
		}

		if ( sanitize_key( (string) $client_id ) !== $oauth_settings['oauth_client_id'] ) {
			return false;
		}

		return wp_check_password( (string) $client_secret, $oauth_settings['oauth_client_secret_hash'] );
	}

	/**
	 * Issue an OAuth bearer access token.
	 *
	 * #1101 PR-3b: the token is written to the hashed token table (never a
	 * transient).  If that write fails nothing is issued: the caller gets a
	 * WP_Error, never a token that would not validate (fail closed).
	 *
	 * @param array $scopes Scopes to grant.
	 * @param int   $ttl    Token TTL in seconds.
	 * @return array|WP_Error Token response payload, or 503 when it could not be stored.
	 */
	public function issue_oauth_access_token( $scopes, $ttl, $context = array() ) {
		$ttl    = max( 300, min( 86400, absint( $ttl ) ) );
		$scopes = $this->sanitize_scopes( $scopes );
		$token  = 'mcpwp_at_' . bin2hex( random_bytes( 24 ) );

		$payload = array(
			'scopes'     => $scopes,
			// RFC 8707 audience (PR-2 D1/D2): always this site's MCP endpoint,
			// whatever `resource` form the client used.  Enforced on every
			// request in authenticate_oauth_access_token().
			'aud'        => $this->get_oauth_canonical_resource(),
			'created_at' => time(),
			// #1101: what revocation epochs are compared against
			// (Mcpwp_OAuth_Revocation::is_revoked()) — integer microseconds,
			// the moment the issuing request checked revocation when it
			// passes one (context issued_at), else now.
			'issued_at'  => isset( $context['issued_at'] ) && is_int( $context['issued_at'] ) ? $context['issued_at'] : Mcpwp_OAuth_Revocation::now(),
			'expires_at' => time() + $ttl,
		);

		// Persist the consenting principal (user or client) so the auth path can
		// give the token a stable, non-empty identity — required for the
		// separation-of-duties (self-approval) gate to distinguish it from the
		// trusted admin-AJAX human context.
		if ( isset( $context['user_id'] ) && (int) $context['user_id'] > 0 ) {
			$payload['user_id'] = (int) $context['user_id'];
		}
		if ( ! empty( $context['client_id'] ) ) {
			$payload['client_id'] = sanitize_key( (string) $context['client_id'] );
		}
		// #1101: the refresh family this token was issued with, so revoking
		// the family (RFC 7009, or refresh-token reuse) kills it too.
		if ( ! empty( $context['family_id'] ) && is_string( $context['family_id'] ) ) {
			$payload['family_id'] = $context['family_id'];
		}

		if ( ! Mcpwp_OAuth_Token_Store::insert( $token, Mcpwp_OAuth_Token_Store::TYPE_ACCESS, $payload ) ) {
			return new WP_Error( 'temporarily_unavailable', __( 'The token could not be stored. Try again.', 'mcpwp' ), array( 'status' => 503 ) );
		}

		return array(
			'access_token' => $token,
			'token_type'   => 'Bearer',
			'expires_in'   => $ttl,
			'scope'        => implode( ' ', $scopes ),
		);
	}

	/**
	 * The canonical OAuth resource / token audience: the MCP endpoint URL.
	 *
	 * @return string
	 */
	protected function get_oauth_canonical_resource() {
		if ( class_exists( 'Mcpwp_OAuth_Well_Known' ) ) {
			return Mcpwp_OAuth_Well_Known::get_canonical_resource();
		}
		return (string) rest_url( 'mcpwp/v1/mcp' );
	}

	/**
	 * Whether a stored token audience names this site's MCP endpoint.
	 *
	 * Fails closed when the comparison helper is unavailable: an `aud` that
	 * cannot be checked is not accepted.
	 *
	 * @param mixed $aud Stored audience.
	 * @return bool
	 */
	protected function oauth_audience_matches( $aud ) {
		if ( ! class_exists( 'Mcpwp_OAuth_Well_Known' ) ) {
			return false;
		}
		return Mcpwp_OAuth_Well_Known::audience_matches( $aud );
	}

	/**
	 * Get transient key for an OAuth access token.
	 *
	 * @param string $token Access token.
	 * @return string Transient key.
	 */
	protected function get_oauth_token_transient_key( $token ) {
		return 'mcpwp_oauth_token_' . md5( (string) $token );
	}

	/**
	 * Get scoped key option name.
	 *
	 * @return string Option name.
	 */
	protected function get_scoped_api_keys_option_name() {
		return 'mcpwp_api_keys';
	}

	/**
	 * Check whether a stored key value appears to be a password hash.
	 *
	 * @param string $value Stored key value.
	 * @return bool True when value looks hashed.
	 */
	protected function looks_like_password_hash( $value ) {
		$value = (string) $value;
		return '' !== $value && '$' === substr( $value, 0, 1 );
	}

	/**
	 * Check whether the current request is a trusted internal admin AJAX call.
	 *
	 * Replaces the former apply_filters('mcpwp_bypass_api_key_check') open bypass.
	 * All conditions must pass simultaneously:
	 *
	 *   1. is_admin() — request is served from the WP admin context.
	 *   2. activate_plugins capability — caller is a site admin.
	 *   3. DOING_AJAX constant — request went through wp-admin/admin-ajax.php.
	 *   4. $_POST['action'] is in the allowlist of known MCPWP admin AJAX actions.
	 *   5. check_ajax_referer() verifies the mcpwp_admin_nonce nonce for the action.
	 *
	 * #607: the allowlist here previously named 'mcpwp_admin_chat' and
	 * 'mcpwp_chat_tool_call' — action strings that are never registered
	 * anywhere (see includes/class-mcpwp-loader.php, which registers
	 * wp_ajax_mcpwp_chat_execute_tool) and never sent by any client. The
	 * nonce action checked was likewise 'mcpwp_admin_chat', while the only
	 * nonce ever minted for this flow is 'mcpwp_admin_nonce' (see
	 * Mcpwp_Admin::localize — wp_create_nonce('mcpwp_admin_nonce') — and
	 * every check_ajax_referer('mcpwp_admin_nonce', ...) call in
	 * trait-mcpwp-admin-chat.php). Both mismatches meant this method could
	 * never return true against a real request: Chat's "execute tool"
	 * button (Mcpwp_Admin_Chat_Trait::ajax_chat_execute_tool()) always hit
	 * verify_api_key()'s missing-API-key rejection when it internally
	 * rest_do_request()'d the /mcp route, an authenticated admin's own tool
	 * execution failing with an auth error 100% of the time in production.
	 * Fixed to the strings that are actually registered/minted.
	 *
	 * The GET ?api_key= query-param path is intentionally NOT touched here —
	 * that path is required for MCP clients (Claude Desktop, ChatGPT connectors)
	 * that cannot set custom request headers. See get_api_key_from_request()
	 * line ~431 for the exact location. Any product decision to restrict or
	 * remove that path belongs to Hadi/Kasra-core, not this security gate.
	 *
	 * @return bool True when all conditions are satisfied.
	 */
	protected function is_trusted_admin_ajax_request() {
		if ( ! is_admin() ) {
			return false;
		}
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return false;
		}
		if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) {
			return false;
		}

		// Only bypass for known MCPWP admin actions — never a wildcard. This
		// must be the exact action string(s) registered via
		// add_action('wp_ajax_<action>', ...) in class-mcpwp-loader.php for
		// a handler that internally rest_do_request()s the /mcp route.
		// Today that is only 'mcpwp_chat_execute_tool'
		// (Mcpwp_Admin_Chat_Trait::ajax_chat_execute_tool()).
		$allowed_actions = array(
			'mcpwp_chat_execute_tool',
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
		if ( ! in_array( $action, $allowed_actions, true ) ) {
			return false;
		}

		// Verify the nonce. check_ajax_referer()'s third argument ($die) is
		// passed false here, so an invalid/missing nonce returns false rather
		// than die()ing — this method must be able to return false and let its
		// caller fall through to the ordinary API-key check, not halt the
		// request. The nonce is generated server-side
		// (wp_create_nonce('mcpwp_admin_nonce')) and sent to the admin JS
		// bundle; every handler in trait-mcpwp-admin-chat.php verifies against
		// the same action string.
		return (bool) check_ajax_referer( 'mcpwp_admin_nonce', 'nonce', false );
	}

	/**
	 * Generate a new API key.
	 *
	 * @return string New API key.
	 */
	public function generate_api_key() {
		return 'mcpwp_' . bin2hex( random_bytes( 24 ) );
	}

	/**
	 * Regenerate API key.
	 *
	 * @return string New API key (plain text).
	 */
	public function regenerate_api_key() {
		// #1096: revoke-all and the new record are ONE store write, so a busy
		// store changes nothing (every old key stays live) and returns ''.
		$created = $this->create_scoped_api_key( __( 'Primary API Key', 'mcpwp' ), $this->get_default_api_key_scopes(), 'admin', array(), false, true );
		if ( isset( $created['error'] ) && 'unsupported_characters' === $created['error'] ) {
			// A translated label the table cannot hold: rotation must still work.
			$created = $this->create_scoped_api_key( 'Primary API Key', $this->get_default_api_key_scopes(), 'admin', array(), false, true );
		}
		if ( ! isset( $created['key'] ) ) {
			return '';
		}
		delete_option( $this->get_api_key_last_used_option_name() );
		update_option( 'mcpwp_api_key', wp_hash_password( $created['key'] ) );

		// "Regenerate revokes every key" includes a 2.8 spai credential the
		// scoped store never held (#1098). Only now that the new key exists —
		// a refused rotation must not strand the old keys without a new one.
		if ( ! class_exists( 'Mcpwp_Activator' ) ) {
			require_once dirname( __DIR__ ) . '/class-mcpwp-activator.php';
		}
		Mcpwp_Activator::end_spai_credentials();

		return $created['key'];
	}
}
