<?php
/**
 * WebMCP in wp-admin (#981).
 *
 * When a logged-in editor or administrator opens wp-admin in a browser that
 * implements WebMCP (https://github.com/webmachinelearning/webmcp — Chrome
 * 149+ origin trial or the enable-webmcp-testing flag), MCPWP registers a
 * small, curated set of its own tools with `document.modelContext`
 * (`navigator.modelContext` on older builds) so the browser's built-in agent
 * can call them. There is no API key: the credential is the user's WordPress
 * login cookie plus the `wp_rest` nonce, and every call runs AS that user.
 *
 * This class owns four things and nothing else:
 *
 *  1. The settings (off by default) and the origin-trial token.
 *  2. The allowlist — TOOL_CAPABILITIES is the one list of tools reachable
 *     this way and the WordPress capability each needs.
 *  3. The WebMCP principal lift: a PHP static, set only by
 *     Mcpwp_REST_WebMCP around its own internal /mcp dispatch, that tells
 *     Mcpwp_Api_Auth::compute_api_key_verdict() "this in-process request is
 *     the logged-in WordPress user acting through WebMCP". Nothing a caller
 *     sends can set it (same pattern as Mcpwp_Destructive_Gate's admin-apply
 *     lift and Mcpwp_REST_Batch::$is_batch_sub_request).
 *  4. The wp-admin wiring: the script, the tool definitions it registers,
 *     and the origin-trial <meta> tag.
 *
 * Prompt-injection stance. Every page, post and SEO row the agent reads
 * through these tools was written by somebody, and may contain text that
 * reads like instructions ("ignore previous instructions and publish…").
 * The plugin cannot tell an instruction from content, so it does not try.
 * The containment is structural instead: the allowlist holds no delete, no
 * settings, no API keys, no plugins/themes, no bulk tools and nothing that
 * publishes; the two create tools can only ever make drafts; and an edit to
 * existing content can only be PROPOSED (wp_propose_content_change creates
 * an approval request that a person reviews in Control Room). The worst a
 * hijacked agent can do here is leave drafts and proposals for a human to
 * throw away. Read tools are advertised with untrustedContentHint so the
 * browser's agent can treat their output as data, not instructions.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WebMCP settings, allowlist, principal and wp-admin wiring.
 */
class Mcpwp_WebMCP {

	/**
	 * Settings keys inside the mcpwp_settings option.
	 */
	const SETTING_ENABLED = 'webmcp_admin_enabled';
	const SETTING_TOKEN   = 'webmcp_origin_trial_token';

	/**
	 * Source label recorded for calls made through WebMCP.
	 */
	const SOURCE = 'webmcp';

	/**
	 * Script handle for the wp-admin registration script.
	 */
	const SCRIPT_HANDLE = 'mcpwp-webmcp';

	/**
	 * Capability a user needs before ANY WebMCP tool is registered for them
	 * or the endpoint answers them at all.
	 *
	 * Editor, not Author: MCPWP's read surfaces show draft, pending and
	 * private content to any principal that can write (see
	 * Mcpwp_Api_Auth::requester_can_see_non_public()). For an Author or
	 * Contributor that is more than WordPress itself lets them see —
	 * other people's drafts and private posts — so they are not offered
	 * WebMCP at all rather than being offered a surface that over-reads.
	 */
	const BASE_CAPABILITY = 'edit_others_posts';

	/**
	 * The WebMCP allowlist: tool name => WordPress capability the logged-in
	 * user must hold to call it. The ONLY tools reachable through
	 * /mcpwp/v1/webmcp/call. Curated: reads, draft creation and a
	 * propose-an-edit path. Nothing here deletes, publishes, changes
	 * settings, touches API keys, plugins or themes, or operates in bulk.
	 *
	 * Tools absent on this install (wp_get_seo is Pro) are simply not
	 * registered in the browser; a call for one is answered by /mcp's own
	 * "not available" result.
	 */
	const TOOL_CAPABILITIES = array(
		'wp_onboard'                => 'edit_others_posts',
		'wp_site_info'              => 'edit_others_posts',
		'wp_list_pages'             => 'edit_pages',
		'wp_list_posts'             => 'edit_posts',
		'wp_get_page_by_slug'       => 'edit_pages',
		'wp_search'                 => 'edit_posts',
		'wp_get_seo'                => 'edit_posts',
		'wp_seo_flags'              => 'edit_others_posts',
		'wp_get_rendered_html'      => 'edit_posts',
		'wp_create_post'            => 'edit_posts',
		'wp_create_page'            => 'edit_pages',
		'wp_propose_content_change' => 'edit_posts',
	);

	/**
	 * Tools that create content: always a draft, never anything else.
	 */
	const DRAFT_ONLY_TOOLS = array( 'wp_create_post', 'wp_create_page' );

	/**
	 * The WebMCP-only propose-an-edit tool (see Mcpwp_WebMCP_Integration).
	 */
	const PROPOSE_TOOL = 'wp_propose_content_change';

	/**
	 * Tool categories the WebMCP principal's key record is restricted to.
	 * Every allowlisted tool's category must be in here
	 * (WebMcpTest::test_principal_categories_cover_allowlist pins it), and
	 * nothing else is — so even a route an allowlisted tool fans out to is
	 * category-gated to these.
	 */
	const PRINCIPAL_CATEGORIES = array( 'site', 'content', 'seo' );

	/**
	 * User id of the WordPress user a WebMCP dispatch is running for, or 0.
	 *
	 * @var int
	 */
	private static $dispatch_user_id = 0;

	/**
	 * True while the wp-admin tool definitions are being built, so the
	 * WebMCP-only tool shows up in that list (and only there, and during a
	 * dispatch).
	 *
	 * @var bool
	 */
	private static $describing = false;

	// ── Settings ──────────────────────────────────────────────────────────

	/**
	 * The mcpwp_settings array.
	 *
	 * @return array
	 */
	private static function settings() {
		$settings = get_option( 'mcpwp_settings', array() );
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Whether WebMCP in wp-admin is switched on. Off unless an administrator
	 * turned it on.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$settings = self::settings();
		return ! empty( $settings[ self::SETTING_ENABLED ] );
	}

	/**
	 * The stored origin-trial token, re-validated on read ('' when unset or
	 * not a well-formed token).
	 *
	 * @return string
	 */
	public static function get_origin_trial_token() {
		$settings = self::settings();
		$raw      = isset( $settings[ self::SETTING_TOKEN ] ) ? $settings[ self::SETTING_TOKEN ] : '';
		$token    = self::sanitize_origin_trial_token( $raw );
		return null === $token ? '' : $token;
	}

	/**
	 * Validate an origin-trial token.
	 *
	 * A Chrome origin-trial token is base64 (standard alphabet, padded) — a
	 * few hundred characters. Whitespace around a pasted token is trimmed;
	 * anything else outside the base64 alphabet makes the token invalid
	 * rather than being silently stripped into a different string.
	 *
	 * @param mixed $raw Raw value.
	 * @return string|null Clean token ('' = none), or null when invalid.
	 */
	public static function sanitize_origin_trial_token( $raw ) {
		if ( null === $raw || false === $raw ) {
			return '';
		}
		if ( ! is_string( $raw ) ) {
			return null;
		}
		$token = trim( $raw );
		if ( '' === $token ) {
			return '';
		}
		if ( strlen( $token ) > 4096 || ! preg_match( '#^[A-Za-z0-9+/]+={0,2}$#', $token ) ) {
			return null;
		}
		return $token;
	}

	// ── Principal ─────────────────────────────────────────────────────────

	/**
	 * Mark the start of a WebMCP dispatch for a user. Returns the previous
	 * value, to be handed back to end_dispatch() in a finally block.
	 *
	 * @param int $user_id Logged-in WordPress user.
	 * @return int
	 */
	public static function begin_dispatch( $user_id ) {
		$previous               = self::$dispatch_user_id;
		self::$dispatch_user_id = max( 0, (int) $user_id );
		return $previous;
	}

	/**
	 * Restore the lift to what it was before begin_dispatch().
	 *
	 * @param int $previous Value begin_dispatch() returned.
	 * @return void
	 */
	public static function end_dispatch( $previous ) {
		self::$dispatch_user_id = max( 0, (int) $previous );
	}

	/**
	 * User id of the running WebMCP dispatch, or 0 when none is running.
	 *
	 * @return int
	 */
	public static function dispatch_user_id() {
		return self::$dispatch_user_id;
	}

	/**
	 * Principal id recorded for a WebMCP user: in the action log's
	 * api_key_id column, on approvals as requested_by_key_id, and as the
	 * rate-limit/idempotency identity. Encodes both the source and the user.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	public static function principal_id( $user_id ) {
		return self::SOURCE . ':user:' . (int) $user_id;
	}

	/**
	 * The synthetic key record a WebMCP call authenticates as.
	 *
	 * read + write, never admin: every surface MCPWP gates on admin scope —
	 * publishing (publish/private/future status), settings, API keys,
	 * options — refuses this principal regardless of what the tool allowlist
	 * says. Role 'custom' restricted to PRINCIPAL_CATEGORIES.
	 *
	 * @param int $user_id User id.
	 * @return array
	 */
	public static function principal_record( $user_id ) {
		return array(
			'id'              => self::principal_id( $user_id ),
			'label'           => 'WebMCP (wp-admin)',
			'type'            => self::SOURCE,
			'source'          => self::SOURCE,
			'user_id'         => (int) $user_id,
			'scopes'          => array( 'read', 'write' ),
			'role'            => 'custom',
			'tool_categories' => self::PRINCIPAL_CATEGORIES,
			'rate_limits'     => array(),
		);
	}

	/**
	 * Whether a user holds the capability the WebMCP surface requires at all.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public static function user_may_use( $user_id ) {
		return (int) $user_id > 0 && user_can( (int) $user_id, self::BASE_CAPABILITY );
	}

	/**
	 * Whether a tool is on the allowlist.
	 *
	 * @param mixed $tool_name Tool name.
	 * @return bool
	 */
	public static function is_allowlisted( $tool_name ) {
		return is_string( $tool_name ) && isset( self::TOOL_CAPABILITIES[ $tool_name ] );
	}

	/**
	 * Whether the current user may call an allowlisted tool.
	 *
	 * @param string $tool_name Tool name.
	 * @return bool
	 */
	public static function current_user_may_call( $tool_name ) {
		if ( ! self::is_allowlisted( $tool_name ) ) {
			return false;
		}
		return current_user_can( self::BASE_CAPABILITY ) && current_user_can( self::TOOL_CAPABILITIES[ $tool_name ] );
	}

	/**
	 * Every JSON-RPC message of an in-process /mcp request made under the
	 * WebMCP lift must be a tools/call for an allowlisted tool. The WebMCP
	 * endpoint only ever builds exactly that; this is the second lock, at
	 * the auth chokepoint, so the lift can never authenticate anything else
	 * (a tools/list, an mcpwp_call wrapper, a batch) even if some future
	 * code path dispatched one while it was held.
	 *
	 * @param mixed $body Decoded JSON body.
	 * @return bool
	 */
	public static function mcp_body_is_allowed( $body ) {
		if ( ! is_array( $body ) || empty( $body ) ) {
			return false;
		}
		$messages = isset( $body[0] ) ? $body : array( $body );
		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) || ! isset( $message['method'] ) || 'tools/call' !== $message['method'] ) {
				return false;
			}
			$name = isset( $message['params']['name'] ) ? $message['params']['name'] : null;
			if ( ! self::is_allowlisted( $name ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Force a create tool to a draft.
	 *
	 * An explicit non-draft status is refused, not silently rewritten — the
	 * agent should learn that publishing is a person's decision, and
	 * whatever it told the user ("published!") must not be a lie. Any
	 * argument that names a status under another spelling (post_status,
	 * Status, post-status …) counts, since the /mcp dispatcher remaps
	 * aliases and near-miss keys onto `status`. Absent a status, 'draft'
	 * is set explicitly so the handler's own default never decides.
	 *
	 * Belt and braces: the WebMCP principal has no admin scope, and every
	 * publish-capable route requires admin scope for a publish/private/
	 * future status, so a status that slipped past here is still refused
	 * at the auth layer.
	 *
	 * @param string $tool_name Tool name.
	 * @param array  $arguments Tool arguments.
	 * @return array|WP_Error Arguments with status=draft, or a refusal.
	 */
	public static function enforce_draft( $tool_name, array $arguments ) {
		if ( ! in_array( $tool_name, self::DRAFT_ONLY_TOOLS, true ) ) {
			return $arguments;
		}

		foreach ( $arguments as $key => $value ) {
			$normalized = preg_replace( '/[^a-z]/', '', strtolower( (string) $key ) );
			if ( 'status' !== $normalized && 'poststatus' !== $normalized ) {
				continue;
			}
			$status = is_scalar( $value ) ? sanitize_key( (string) $value ) : '';
			if ( 'draft' !== $status ) {
				return new WP_Error(
					'webmcp_draft_only',
					__( 'WebMCP can only create drafts. Leave status out (or set it to "draft"); a person publishes from wp-admin.', 'mcpwp' ),
					array( 'status' => 403 )
				);
			}
			unset( $arguments[ $key ] );
		}

		$arguments['status'] = 'draft';
		return $arguments;
	}

	/**
	 * Gate one REST sub-request dispatched under the WebMCP principal —
	 * the request the tool's handler actually reads, so these checks see
	 * the CANONICAL arguments: after the /mcp dispatcher's alias
	 * resolution (#831), near-miss remapping (#911: postType, Post-Type,
	 * POST_TYPE → post_type) and param_remap (wp_search's post_type →
	 * type). Checking the tool arguments before dispatch instead would
	 * check a spelling the handler never reads.
	 *
	 * Called from Mcpwp_Api_Auth::authenticate_webmcp_principal() for every
	 * sub-request. Brings the parts of WordPress's own authorization that
	 * MCPWP's API-key model does not express to the signed-in user:
	 *
	 *  - writes: status must stay draft (belt to enforce_draft()'s braces);
	 *  - post types: reading a type's list needs that type's edit_posts
	 *    cap, creating one needs its create_posts cap;
	 *  - terms / categories / tags: the taxonomy's assign_terms cap (and
	 *    edit_terms for a tag name that would create a new term);
	 *  - meta: each key must pass edit_post_meta's rules — not protected,
	 *    and the registered auth_callback (auth_post_meta_{key}[_for_{type}])
	 *    must allow this user — exactly how map_meta_cap() decides it.
	 *
	 * Any failure refuses the whole call.
	 *
	 * @param WP_REST_Request $request Sub-request.
	 * @return true|WP_Error
	 */
	public static function check_sub_request( $request ) {
		$method = method_exists( $request, 'get_method' ) ? strtoupper( (string) $request->get_method() ) : 'GET';
		$route  = method_exists( $request, 'get_route' ) ? strtolower( untrailingslashit( (string) $request->get_route() ) ) : '';
		$params = method_exists( $request, 'get_params' ) ? (array) $request->get_params() : array();
		$write  = ! in_array( $method, array( 'GET', 'HEAD', 'OPTIONS' ), true );

		if ( $write ) {
			$draft = self::enforce_draft( 'wp_create_post', $params );
			if ( is_wp_error( $draft ) ) {
				return $draft;
			}
		}

		// The post type this request reads or writes.
		$types = array();
		if ( array_key_exists( 'post_type', $params ) ) {
			$types[] = $params['post_type'];
		}
		if ( '/mcpwp/v1/search' === $route && array_key_exists( 'type', $params ) && 'any' !== $params['type'] ) {
			$types[] = $params['type'];
		}
		if ( $write && empty( $types ) ) {
			if ( '/mcpwp/v1/posts' === $route ) {
				$types[] = 'post';
			} elseif ( '/mcpwp/v1/pages' === $route ) {
				$types[] = 'page';
			}
		}

		$write_type = '';
		foreach ( $types as $type ) {
			if ( ! is_string( $type ) ) {
				return self::refusal( 'webmcp_post_type_forbidden', __( 'post_type must be a single post type name.', 'mcpwp' ) );
			}
			$object = function_exists( 'get_post_type_object' ) ? get_post_type_object( sanitize_key( $type ) ) : null;
			$cap    = null;
			if ( $object && isset( $object->cap ) ) {
				$cap = $write
					? ( isset( $object->cap->create_posts ) ? $object->cap->create_posts : null )
					: ( isset( $object->cap->edit_posts ) ? $object->cap->edit_posts : null );
			}
			if ( null === $cap || ! current_user_can( $cap ) ) {
				return self::refusal( 'webmcp_post_type_forbidden', __( 'Your WordPress role cannot work with this type of content.', 'mcpwp' ) );
			}
			$write_type = sanitize_key( $type );
		}

		if ( ! $write ) {
			return true;
		}

		// Taxonomy terms.
		$taxonomies = array();
		if ( isset( $params['terms'] ) && is_array( $params['terms'] ) ) {
			foreach ( array_keys( $params['terms'] ) as $taxonomy ) {
				$taxonomies[ sanitize_key( (string) $taxonomy ) ] = true;
			}
		}
		if ( ! empty( $params['categories'] ) ) {
			$taxonomies['category'] = true;
		}
		if ( ! empty( $params['tags'] ) ) {
			$taxonomies['post_tag'] = true;
		}
		foreach ( array_keys( $taxonomies ) as $taxonomy ) {
			$tax_object = function_exists( 'get_taxonomy' ) ? get_taxonomy( $taxonomy ) : false;
			if ( ! $tax_object ) {
				continue; // Unknown taxonomy: the handler refuses it by name.
			}
			if ( ! current_user_can( $tax_object->cap->assign_terms ) ) {
				return self::refusal(
					'webmcp_terms_forbidden',
					/* translators: %s: taxonomy */
					sprintf( __( 'Your WordPress role cannot assign "%s" terms.', 'mcpwp' ), $taxonomy )
				);
			}
		}
		// wp_set_post_tags() creates a tag it does not find by name.
		if ( ! empty( $params['tags'] ) && function_exists( 'get_taxonomy' ) && get_taxonomy( 'post_tag' ) ) {
			foreach ( (array) $params['tags'] as $tag ) {
				if ( is_scalar( $tag ) && ! term_exists( (string) $tag, 'post_tag' ) && ! current_user_can( get_taxonomy( 'post_tag' )->cap->edit_terms ) ) {
					return self::refusal( 'webmcp_terms_forbidden', __( 'Your WordPress role cannot create new tags.', 'mcpwp' ) );
				}
			}
		}

		// Registered meta — gated on exactly the keys the handler will
		// write: Mcpwp_Post_Fields::validate_meta()'s OUTPUT, not the raw
		// argument. validate_meta() trims keys, so gating the raw key let
		// "featured_flag " / " featured_flag" past the auth_callback and then
		// wrote featured_flag (#981 review round 2, WARN-A).
		if ( array_key_exists( 'meta', $params ) && null !== $params['meta'] ) {
			if ( ! class_exists( 'Mcpwp_Post_Fields' ) ) {
				return self::refusal( 'webmcp_meta_forbidden', __( 'Custom fields cannot be set through WebMCP on this site.', 'mcpwp' ) );
			}
			$clean_meta = Mcpwp_Post_Fields::validate_meta( $params['meta'], false, '' !== $write_type ? $write_type : 'post' );
			if ( is_wp_error( $clean_meta ) ) {
				return $clean_meta;
			}
			foreach ( array_keys( $clean_meta ) as $key ) {
				if ( ! self::meta_key_allowed( (string) $key, '' !== $write_type ? $write_type : 'post' ) ) {
					return self::refusal(
						'webmcp_meta_forbidden',
						/* translators: %s: meta key */
						sprintf( __( 'Your WordPress role cannot set the "%s" field.', 'mcpwp' ), (string) $key )
					);
				}
			}
		}

		return true;
	}

	/**
	 * map_meta_cap()'s edit_post_meta rule for a post that does not exist
	 * yet: allowed unless the key is protected, then whatever the key's
	 * registered auth_callback (hooked on auth_post_meta_{key}_for_{type},
	 * or auth_post_meta_{key}) says for this user.
	 *
	 * @param string $key       Meta key.
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public static function meta_key_allowed( $key, $post_type ) {
		$allowed = ! ( function_exists( 'is_protected_meta' ) && is_protected_meta( $key, 'post' ) );
		$user_id = get_current_user_id();

		$subtype_tag = "auth_post_meta_{$key}_for_{$post_type}";
		$tag         = function_exists( 'has_filter' ) && has_filter( $subtype_tag ) ? $subtype_tag : "auth_post_meta_{$key}";

		return (bool) apply_filters( $tag, $allowed, $key, 0, $user_id, 'edit_post_meta', array() );
	}

	/**
	 * A 403 refusal.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @return WP_Error
	 */
	private static function refusal( $code, $message ) {
		return new WP_Error( $code, $message, array( 'status' => 403 ) );
	}

	// ── The WebMCP-only propose tool ──────────────────────────────────────

	/**
	 * Whether the WebMCP-only tool should be visible to the MCP registries
	 * right now: during a WebMCP dispatch, or while building the wp-admin
	 * tool definitions. Never to an API key, OAuth token or broker token.
	 *
	 * @return bool
	 */
	public static function exposes_webmcp_only_tools() {
		return self::$dispatch_user_id > 0 || self::$describing;
	}

	/**
	 * Callback for wp_propose_content_change: create a post_content_update
	 * approval request, change nothing.
	 *
	 * @param array $arguments Validated tool arguments.
	 * @return array|WP_Error
	 */
	public static function propose_content_change( $arguments ) {
		$user_id = self::$dispatch_user_id;
		if ( $user_id < 1 || get_current_user_id() !== $user_id ) {
			return new WP_Error( 'webmcp_only', __( 'This tool is only available through WebMCP in wp-admin.', 'mcpwp' ), array( 'status' => 403 ) );
		}

		$arguments = is_array( $arguments ) ? $arguments : array();
		$post_id   = isset( $arguments['post_id'] ) ? absint( $arguments['post_id'] ) : 0;
		$content   = isset( $arguments['content'] ) && is_string( $arguments['content'] ) ? $arguments['content'] : null;

		if ( $post_id < 1 || null === $content ) {
			return new WP_Error( 'invalid_arguments', __( 'post_id and content are required.', 'mcpwp' ), array( 'status' => 400 ) );
		}

		$post = get_post( $post_id );
		// Same 404 whether the post is missing or not the caller's to edit:
		// this tool must not confirm the existence of a post the user
		// cannot edit.
		if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'mcpwp' ), array( 'status' => 404 ) );
		}

		// Same category limit as every other WebMCP read/write: a post type
		// outside the principal's categories (a reusable block → gutenberg,
		// an Elementor template → elementor-templates, a product →
		// woocommerce) is not WebMCP's to propose edits for.
		$category = class_exists( 'Mcpwp_REST_API' ) ? Mcpwp_REST_API::category_for_post_type( $post->post_type ) : '';
		if ( ! in_array( $category, self::PRINCIPAL_CATEGORIES, true ) ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'mcpwp' ), array( 'status' => 404 ) );
		}

		if ( ! class_exists( 'Mcpwp_Approvals' ) ) {
			return new WP_Error( 'approvals_unavailable', __( 'Approval pipeline is not available.', 'mcpwp' ), array( 'status' => 500 ) );
		}

		// The proposal is applied later by someone else — usually an
		// administrator, who holds unfiltered_html. A user who could not
		// write raw HTML themselves must not get it written on their behalf
		// through the approval queue.
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			$content = wp_kses_post( $content );
		}

		$note = isset( $arguments['note'] ) && is_string( $arguments['note'] ) ? $arguments['note'] : '';

		$approval = Mcpwp_Approvals::create_post_content_request(
			$post_id,
			$content,
			array(
				'title'               => sprintf(
					/* translators: %d: post ID */
					__( 'Proposed edit for #%d (WebMCP)', 'mcpwp' ),
					$post_id
				),
				'note'                => $note,
				'tool'                => self::PROPOSE_TOOL,
				'requested_by_key_id' => self::principal_id( $user_id ),
				'metadata'            => array(
					'source'  => self::SOURCE,
					'user_id' => $user_id,
				),
			)
		);

		if ( is_wp_error( $approval ) ) {
			return $approval;
		}

		return array(
			'success'  => true,
			'status'   => 'approval_required',
			'post_id'  => $post_id,
			'approval' => $approval,
			'message'  => 'Proposed. Nothing on the site changed: an administrator reviews this edit in MCPWP > Control Room and applies or rejects it.',
		);
	}

	// ── wp-admin wiring ───────────────────────────────────────────────────

	/**
	 * Whether this wp-admin page load gets WebMCP: switched on, a per-site
	 * admin screen, and a user who may use it.
	 *
	 * @return bool
	 */
	public static function should_load_in_admin() {
		if ( ! self::is_enabled() || ! is_admin() ) {
			return false;
		}
		if ( function_exists( 'is_network_admin' ) && is_network_admin() ) {
			return false;
		}
		return self::user_may_use( get_current_user_id() );
	}

	/**
	 * Print the origin-trial token, in wp-admin only (hooked on admin_head,
	 * which the front end never fires), only when WebMCP is on for this
	 * user and a token is set.
	 *
	 * @return void
	 */
	public static function print_origin_trial_meta() {
		if ( ! self::should_load_in_admin() ) {
			return;
		}
		$token = self::get_origin_trial_token();
		if ( '' === $token ) {
			return;
		}
		printf( '<meta http-equiv="origin-trial" content="%s" />' . "\n", esc_attr( $token ) );
	}

	/**
	 * Enqueue the registration script with the tool definitions.
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! self::should_load_in_admin() ) {
			return;
		}

		$tools = self::tool_definitions_for_current_user();
		if ( empty( $tools ) ) {
			return;
		}

		$version = defined( 'MCPWP_VERSION' ) ? MCPWP_VERSION : false;
		wp_enqueue_script( self::SCRIPT_HANDLE, MCPWP_PLUGIN_URL . 'admin/js/mcpwp-webmcp.js', array(), $version, true );
		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'window.mcpwpWebMcp = ' . wp_json_encode( self::client_config( $tools ) ) . ';',
			'before'
		);
	}

	/**
	 * The object the script reads.
	 *
	 * @param array $tools WebMCP tool definitions.
	 * @return array
	 */
	public static function client_config( array $tools ) {
		return array(
			'endpoint' => rest_url( 'mcpwp/v1/webmcp/call' ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'tools'    => array_values( $tools ),
		);
	}

	/**
	 * WebMCP tool definitions for the current user: the plugin's own MCP
	 * tool definitions (name, title, description, inputSchema — single
	 * sourced from the registries through Mcpwp_REST_MCP), filtered to the
	 * allowlist and the user's capabilities, reshaped to WebMCP's
	 * ModelContextTool fields.
	 *
	 * @param Mcpwp_REST_MCP|null $mcp Controller (tests inject one).
	 * @return array
	 */
	public static function tool_definitions_for_current_user( $mcp = null ) {
		$names = array();
		foreach ( array_keys( self::TOOL_CAPABILITIES ) as $name ) {
			if ( self::current_user_may_call( $name ) ) {
				$names[] = $name;
			}
		}
		if ( empty( $names ) || ! class_exists( 'Mcpwp_REST_MCP' ) ) {
			return array();
		}

		$previous         = self::$describing;
		self::$describing = true;
		try {
			$mcp         = $mcp ? $mcp : new Mcpwp_REST_MCP();
			$definitions = $mcp->get_tool_definitions( $names );
		} finally {
			self::$describing = $previous;
		}

		$tools = array();
		foreach ( $definitions as $definition ) {
			$tool = self::to_webmcp_tool( $definition );
			if ( null !== $tool ) {
				$tools[] = $tool;
			}
		}
		return $tools;
	}

	/**
	 * Reshape one MCP tool definition to a WebMCP ModelContextTool (minus
	 * execute, which the script supplies).
	 *
	 * @param array $definition MCP tool definition.
	 * @return array|null
	 */
	public static function to_webmcp_tool( $definition ) {
		if ( ! is_array( $definition ) || ! isset( $definition['name'] ) || ! self::is_allowlisted( $definition['name'] ) ) {
			return null;
		}

		$name        = $definition['name'];
		$annotations = isset( $definition['annotations'] ) && is_array( $definition['annotations'] ) ? $definition['annotations'] : array();
		$read_only   = ! empty( $annotations['readOnlyHint'] );
		$schema      = isset( $definition['inputSchema'] ) && is_array( $definition['inputSchema'] ) ? $definition['inputSchema'] : array( 'type' => 'object' );
		$description = isset( $definition['description'] ) ? (string) $definition['description'] : '';

		if ( in_array( $name, self::DRAFT_ONLY_TOOLS, true ) ) {
			// The status is not the agent's to choose here — do not offer it.
			if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
				unset( $schema['properties']['status'] );
				if ( empty( $schema['properties'] ) ) {
					$schema['properties'] = new stdClass();
				}
			}
			if ( isset( $schema['required'] ) && is_array( $schema['required'] ) ) {
				$schema['required'] = array_values( array_diff( $schema['required'], array( 'status' ) ) );
				if ( empty( $schema['required'] ) ) {
					unset( $schema['required'] );
				}
			}
			$description .= ' Through WebMCP this always saves a DRAFT; a person publishes it from wp-admin.';
		}

		$tool = array(
			'name'        => $name,
			'title'       => isset( $definition['title'] ) && is_string( $definition['title'] ) ? $definition['title'] : $name,
			'description' => $description,
			'inputSchema' => $schema,
			'annotations' => array(
				'readOnlyHint'         => $read_only,
				// Everything these tools return was written by site users
				// and may carry injected instructions (see the class
				// docblock) — data for the agent, not directions.
				'untrustedContentHint' => true,
				// Drafts and proposals are reversible by construction.
				'consequentialHint'    => false,
			),
		);

		return $tool;
	}
}
