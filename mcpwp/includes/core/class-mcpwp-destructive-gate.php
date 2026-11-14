<?php
/**
 * Destructive operations go through the approval workflow by default (#536).
 *
 * The plugin ships a full approval lifecycle (request → approve → apply →
 * rollback) and, until now, nothing used it by default: a force delete on a
 * fresh install was gone immediately. The operations gated here are the
 * ones with no undo — permanent deletes of content and media, purging every
 * draft.
 *
 * The first cut was gameable in a way the adversarial review put plainly:
 * every principal the gate applied to could also switch it off, at the same
 * scope, in one ungated call — mint a key with allow_destructive, or flip
 * the site setting through the settings API. A gate whose exemption comes
 * from the thing it gates is an audit record, not a veto. So:
 *
 *   - `allow_destructive` on a key and the site-wide switch are honoured
 *     only from the WordPress admin (a logged-in administrator acting in
 *     wp-admin), never from an API or MCP principal. An API caller that
 *     sends `allow_destructive` is told it was ignored.
 *   - Approving and applying live in the store, not the controller: the
 *     principal that requested an operation cannot approve or apply it,
 *     whichever door it comes through.
 *   - What was reviewed is what runs: parameters are sanitised on store, a
 *     fingerprint of the target is taken at defer and re-checked at apply,
 *     and a drift refuses the apply and says what changed.
 *   - Revoking a key is NOT gated. It is the incident-response lever, and
 *     it is reversible in the sense that matters (mint a replacement).
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Destructive-operation approval gate.
 */
class Mcpwp_Destructive_Gate {

	/**
	 * Operations gated, by short name. Anything on the plugin's own
	 * destructive-tool list and not here is either reversible (trash,
	 * menus, webhooks) or incident response (revoke, reset) — see the test
	 * that ties the two lists together.
	 */
	const GATED = array( 'delete_page', 'delete_post', 'delete_content', 'delete_all_drafts', 'delete_media', 'delete_product', 'delete_course_category', 'delete_widget', 'delete_elementor_template', 'delete_user' );

	/**
	 * Gated operations with no MCP tool: reachable through REST only. The
	 * route-map invariant test skips the tool-map half for these.
	 */
	const REST_ONLY_OPERATIONS = array( 'delete_user' );

	/**
	 * REST controllers that register a DELETE route without the gate, each
	 * with the reason. The coverage oracle walks every controller file that
	 * registers WP_REST_Server::DELETABLE; one that is neither gated nor
	 * listed here fails it (#536 round 6).
	 */
	const UNGATED_DELETE_CONTROLLERS = array(
		'class-mcpwp-rest-menus.php'           => 'menus and items are re-creatable; declared exempt as tools',
		'class-mcpwp-rest-site-manager.php'    => 'menu items (pro alias); re-creatable',
		'class-mcpwp-rest-webhooks.php'        => 'a webhook is re-registered',
		'class-mcpwp-rest-site-taxonomy.php'   => 'terms: content kept, term re-creatable by name (wp_delete_term exempt)',
		'class-mcpwp-rest-site-settings.php'   => 'favicon: removes the site icon assignment, the attachment stays',
		'class-mcpwp-rest-theme-builder.php'   => 'theme-builder conditions/custom code: option-backed text, re-creatable',
		'class-mcpwp-rest-site-memory.php'     => 'memory entries, re-creatable with wp_remember',
		'class-mcpwp-rest-site-blueprints.php' => 'a stored blueprint export, re-creatable by extracting again',
		'class-mcpwp-rest-site-custom-css.php' => 'a text block, re-creatable',
		'class-mcpwp-rest-elementor.php'       => 'kit CSS blocks and sections: text/JSON edits with the action-log snapshot',
		'class-mcpwp-rest-integrations.php'    => 'removes a provider key that is re-enterable; registered with a DELETE string, not the constant',
		'class-mcpwp-rest-site-updates.php'    => 'revoke of an API key: incident response, deliberately ungated (see EXEMPT_WITH_REASON)',
	);

	/**
	 * The one route (method + path) each gated operation may replay. A stored
	 * payload whose route is not the operation's route is a forgery, whatever
	 * the record's summary says (#536 round 2, P0-2).
	 */
	const OPERATION_ROUTES = array(
		'delete_page'            => array( 'DELETE', '#^/mcpwp/v1/pages/\d+$#' ),
		'delete_post'            => array( 'DELETE', '#^/mcpwp/v1/posts/\d+$#' ),
		'delete_content'         => array( 'DELETE', '#^/mcpwp/v1/content/[A-Za-z0-9_\-]+/\d+$#' ),
		'delete_all_drafts'      => array( 'DELETE', '#^/mcpwp/v1/drafts/delete-all$#' ),
		'delete_media'           => array( 'DELETE', '#^/mcpwp/v1/media/\d+$#' ),
		'delete_product'         => array( 'DELETE', '#^/mcpwp/v1/woocommerce/products/\d+$#' ),
		'delete_course_category' => array( 'DELETE', '#^/mcpwp/v1/learnpress/categories/\d+$#' ),
		'delete_widget'          => array( 'DELETE', '#^/mcpwp/v1/widgets/[A-Za-z0-9_\-]+$#' ),
		'delete_elementor_template' => array( 'DELETE', '#^/mcpwp/v1/elementor/templates/\d+$#' ),
		'delete_user'               => array( 'DELETE', '#^/mcpwp/v1/users/\d+$#' ),
	);

	/**
	 * Declared-destructive tools that are deliberately not gated, each with
	 * the reason. The invariant test walks BOTH tool registries against
	 * GATED + this list; a new destructive tool with neither fails it.
	 */
	const EXEMPT_WITH_REASON = array(
		'wp_delete_menu'               => 'reversible: a menu is rebuilt from its items',
		'wp_delete_menu_item'          => 'reversible',
		'wp_delete_menu_item_auto'     => 'reversible',
		'wp_delete_webhook'            => 'reversible: re-register',
		'wp_reset_rate_limit'          => 'incident response, no data loss',
		'wp_revoke_api_key'            => 'incident response: gating it would favour whoever holds the key',
		'wp_push_to_live'              => 'already an approval request of its own (staging_push)',
		'wp_apply_elementor_template'  => 'content replacement with a rollback: the action log snapshots _elementor_data before every Elementor write tool',
		'wp_apply_elementor_archetype' => 'content replacement with a rollback: the action log snapshots _elementor_data before every Elementor write tool',
		'wp_apply_elementor_part'      => 'content replacement with a rollback: the action log snapshots _elementor_data before every Elementor write tool',
		// Named by the wider oracle (every wp_delete_*/wc_delete_*/wp_forget tool), #536 round 4:
		'wp_delete_term'               => 'detaches posts from a term; no post content is lost and the term is re-creatable by name',
		'wp_delete_custom_css'         => 'a text block, re-creatable; the activity log records the request',
		'wp_delete_kit_css_block'      => 'a named, hash-tagged text block, re-creatable by upserting it again',
		'wp_forget'                    => 'a memory entry, re-creatable with wp_remember',
	);

	/**
	 * Pending destructive requests API principals may hold at most, across
	 * all of them. The per-requester cap alone is bypassed by minting keys
	 * (#536 round 2, P1-3). Administrators in wp-admin (wp-user:N) are not
	 * counted against it and are bound by the per-requester cap only, so
	 * minted keys cannot close the destructive path to the people who
	 * review it (#536 round 3, P1-2).
	 */
	const MAX_PENDING_TOTAL = 100;

	/**
	 * Ids a delete-all-drafts fingerprint lists at most. The count is
	 * always exact; the id list is bounded so a site with thousands of
	 * drafts does not hash them all inside a REST request.
	 */
	const DRAFTS_FINGERPRINT_IDS = 1000;

	/**
	 * How many pending requests one principal may hold. A flood of gated
	 * calls must not evict the queue other people are reviewing.
	 */
	const MAX_PENDING_PER_REQUESTER = 20;

	/**
	 * Set while an approved request is being applied; the re-dispatch is
	 * not gated again. Private, saved and restored around the dispatch —
	 * never a request header, never left set.
	 *
	 * @var bool
	 */
	private static $applying = false;

	/**
	 * WordPress user id an admin-context apply runs as, while it runs.
	 *
	 * @var int
	 */
	private static $applying_as_admin = 0;

	/**
	 * @return bool
	 */
	public static function is_applying() {
		return self::$applying;
	}

	/**
	 * The admin user an apply is running as, or 0.
	 *
	 * @return int
	 */
	public static function applying_as_admin() {
		return (int) self::$applying_as_admin;
	}

	/**
	 * Whether this site gates destructive operations. Default on. The switch
	 * is read from the settings option but is NOT in the settings API's
	 * allowlist: it is changed from wp-admin or not at all.
	 *
	 * @return bool
	 */
	public static function enabled_for_site() {
		$settings = get_option( 'mcpwp_settings', array() );

		if ( is_array( $settings ) && array_key_exists( 'destructive_requires_approval', $settings ) ) {
			return (bool) $settings['destructive_requires_approval'];
		}

		return true;
	}

	/**
	 * Whether a key may run destructive operations without approval.
	 *
	 * @param array|null $key_record Current key record.
	 * @return bool
	 */
	public static function key_is_exempt( $key_record ) {
		return is_array( $key_record ) && ! empty( $key_record['allow_destructive'] );
	}

	/**
	 * Whether the current request comes from an API/MCP principal, as
	 * opposed to a logged-in administrator in wp-admin.
	 *
	 * @param array|null $key_record Current key record.
	 * @return bool
	 */
	public static function is_api_principal( $key_record ) {
		// Any key record — with or without an id — is an API principal. The
		// only non-API principals are proven positively: an administrator an
		// approved apply is running as, or a logged-in administrator in
		// wp-admin. Trust is never inferred from a missing field (#536
		// round 2, P1-1).
		if ( is_array( $key_record ) ) {
			return true;
		}

		return ! self::in_admin_context();
	}

	/**
	 * Positive proof of a WordPress administrator acting from wp-admin.
	 *
	 * @return bool
	 */
	public static function in_admin_context() {
		if ( self::applying_as_admin() > 0 ) {
			return true;
		}

		if ( function_exists( 'is_admin' ) && is_admin() && function_exists( 'current_user_can' ) && current_user_can( 'activate_plugins' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Whether this principal is exempt from the approval caps — derived from
	 * Mcpwp_Approvals::requester_is_exempt_from_caps(), the one place that
	 * decides it (#1042 review round 2).
	 *
	 * @param array|null $key_record Current key record.
	 * @return bool
	 */
	public static function key_is_unrestricted( $key_record ) {
		if ( ! class_exists( 'Mcpwp_Approvals' ) ) {
			return false;
		}
		return Mcpwp_Approvals::requester_is_exempt_from_caps( self::requester_id( $key_record ), array( 'key_record' => $key_record ) );
	}

	/**
	 * Who to record as the requester of a deferred operation.
	 *
	 * @param array|null $key_record Current key record.
	 * @return string Key id, 'wp-user:N' for an administrator, or ''.
	 */
	public static function requester_id( $key_record ) {
		if ( is_array( $key_record ) && isset( $key_record['id'] ) && '' !== (string) $key_record['id'] ) {
			return (string) $key_record['id'];
		}

		// The admin chat authenticates with no key record; the ledger still
		// has to name who asked, and the two-person rule has to bind them
		// (#536 round 2, P2-1).
		if ( self::in_admin_context() && function_exists( 'get_current_user_id' ) && get_current_user_id() > 0 ) {
			return 'wp-user:' . get_current_user_id();
		}

		return '';
	}

	/**
	 * Whether a delete of this post type with this force flag is permanent.
	 *
	 * #1023 (round-1 adversarial review of #1020/#1022): this used to treat
	 * every post type OTHER than post/page/product as unconditionally
	 * permanent, on the theory that "WordPress trashes on force=false ONLY
	 * for post and page" — true of core's wp_delete_post() when called
	 * DIRECTLY with force=false, but the CALLERS of this method
	 * (Mcpwp_Posts::delete_post(), Mcpwp_REST_Content::delete_content())
	 * were STILL calling wp_delete_post( $id, $force ) directly for every
	 * type, including the one carve-out ('product') this method claimed WAS
	 * trashable. The result: this method said "not permanent" for a product,
	 * so the destructive-approval gate was skipped, and the actual delete
	 * call then permanently destroyed it anyway — while the response text
	 * still said "moved to trash". WooCommerce's own admin UI trashes
	 * products through ITS OWN code path (a data-store method wired to the
	 * post-list bulk actions); calling core's wp_delete_post()/wp_trash_post()
	 * directly, as this plugin does, never goes through that — only the
	 * generic core behavior applies.
	 *
	 * The real, generic rule core actually offers via wp_trash_post() is
	 * type-agnostic: ANY non-attachment post type can be trashed (soft
	 * status change) regardless of what wp_delete_post()'s own narrower
	 * built-in auto-trash branch happens to special-case. So every
	 * non-attachment type gets the SAME EMPTY_TRASH_DAYS-gated answer here,
	 * and it is the CALLER's job (not this method's) to actually invoke
	 * wp_trash_post() for a "not permanent" delete on anything that isn't
	 * literally 'post' or 'page' — the two types wp_delete_post()'s own
	 * force=false branch already trashes correctly on its own.
	 *
	 * ONE type pair is still hardcoded permanent regardless: elementor_library
	 * and elementor_snippet. Their only delete door
	 * (Mcpwp_REST_Elementor_Pro::delete_template() -> Mcpwp_Elementor_Pro::
	 * delete_template()) is an Elementor route, explicitly OUT OF SCOPE for
	 * this fix (round-1 review of #1020/#1022, WARN-2 — filed separately as
	 * #1024) and still calls wp_delete_post() directly without ever calling
	 * wp_trash_post() for these two types. Generalizing this method's answer
	 * for them the way every other type above was generalized would silently
	 * turn that caller's SAFE-but-over-cautious "always defer to approval"
	 * into an actual unapproved permanent delete — a regression on a door
	 * this fix must not touch. Mcpwp_Posts::delete_post() and
	 * Mcpwp_REST_Content::delete_content() (the doors this round of fixes DID
	 * update) still admit both types (elementor_snippet/elementor_library are
	 * in Mcpwp_Posts's $safe_nonpublic_types, and delete_content() accepts
	 * any registered type) and would trash them correctly if reached — this
	 * carve-out only keeps their PRE-EXISTING deferred-approval behavior
	 * on the ONE door that would otherwise regress.
	 *
	 * @param string $post_type Post type.
	 * @param bool   $force     The caller's force flag.
	 * @return bool
	 */
	public static function delete_is_permanent( $post_type, $force, $status = '' ) {
		if ( $force ) {
			return true;
		}
		// Core's trash branch is skipped for a post that is ALREADY in the
		// trash: force=false on it deletes permanently (#536 round 6).
		if ( 'trash' === (string) $status ) {
			return true;
		}
		if ( 'attachment' === $post_type ) {
			return ! ( defined( 'MEDIA_TRASH' ) && MEDIA_TRASH );
		}
		if ( in_array( (string) $post_type, array( 'elementor_library', 'elementor_snippet' ), true ) ) {
			return true;
		}
		return defined( 'EMPTY_TRASH_DAYS' ) && ! EMPTY_TRASH_DAYS;
	}

	/**
	 * Decide whether a call must be turned into an approval request.
	 *
	 * @param array|null $key_record Current key record.
	 * @return bool
	 */
	public static function should_gate( $key_record ) {
		if ( self::$applying ) {
			return false;
		}

		if ( ! self::enabled_for_site() ) {
			return false;
		}

		return ! self::key_is_exempt( $key_record );
	}

	// ---------------------------------------------------------------
	// Defer.
	// ---------------------------------------------------------------

	/**
	 * Store the call as a pending approval and describe it to the caller.
	 *
	 * @param string          $operation  One of GATED.
	 * @param WP_REST_Request $request    The request that was gated.
	 * @param array           $resource   {type, id, title} for the record.
	 * @param string          $summary    One line a reviewer reads.
	 * @param array|null      $key_record Current key record.
	 * @return array|WP_Error 202 body, or the refusal.
	 */
	public static function defer( $operation, $request, array $resource, $summary, $key_record = null ) {
		$route  = method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
		$method = method_exists( $request, 'get_method' ) ? strtoupper( (string) $request->get_method() ) : 'DELETE';
		$params = method_exists( $request, 'get_params' ) ? (array) $request->get_params() : array();

		unset( $params['_locale'], $params['_wpnonce'], $params['rest_route'], $params['_method'] );
		// A key sent as ?api_key= (connector links) is a credential, not an
		// operation parameter — never persist it into the approval record.
		unset( $params['api_key'] );

		// Only a route the operation owns is ever stored.
		$bound = self::route_matches_operation( $operation, $method, $route );
		if ( is_wp_error( $bound ) ) {
			return $bound;
		}

		// The id in the route, the id in the body and the id the handler
		// resolved must be one id: the reviewer reads the route line as
		// "the exact call" (#536 round 3, P2-1).
		if ( isset( $resource['id'] ) && '' !== (string) $resource['id'] && null !== $resource['id'] ) {
			$ids = array( (string) $resource['id'] );
			if ( preg_match( '#/([^/]+)/?$#', $route, $m ) && ( is_numeric( $resource['id'] ) ? is_numeric( $m[1] ) : $m[1] !== basename( $route ) || true ) ) {
				// The trailing route segment names the target on every gated
				// route that carries one (numeric or a widget id).
				if ( is_numeric( $resource['id'] ) || preg_match( '/^[a-z0-9_\-]+$/i', $m[1] ) ) {
					$ids[] = (string) $m[1];
				}
			}
			if ( isset( $params['id'] ) && ( is_numeric( $params['id'] ) || is_string( $params['id'] ) ) ) {
				$ids[] = (string) $params['id'];
			}
			$ids = array_values( array_unique( array_map( function ( $v ) { return is_numeric( $v ) ? (string) (int) $v : (string) $v; }, $ids ) ) );
			if ( count( $ids ) > 1 && ! in_array( $operation, array( 'delete_all_drafts' ), true ) ) {
				return new WP_Error(
					'destructive_id_mismatch',
					__( 'The id in the route and the id in the body disagree. Nothing was queued; send one id.', 'mcpwp' ),
					array( 'status' => 409, 'ids' => array_values( array_unique( $ids ) ) )
				);
			}
		}

		// The set a purge would remove is part of the target.
		if ( 'drafts' === $resource['type'] ) {
			$resource['post_type'] = isset( $params['type'] ) && is_string( $params['type'] ) ? $params['type'] : 'all';
		}

		// A target this gate cannot fingerprint is not deferred: "no drift
		// check" must never read as "no drift" (#536 round 4, P2-2).
		$target = self::fingerprint( $resource );
		if ( ! empty( $target['unparseable'] ) ) {
			return new WP_Error(
				'invalid_target',
				__( 'The target id is not one this operation can act on. Nothing was queued.', 'mcpwp' ),
				array( 'status' => 400, 'id' => isset( $resource['id'] ) ? $resource['id'] : null )
			);
		}

		$approval = Mcpwp_Approvals::create_destructive_request(
			$operation,
			array(
				'route'  => $route,
				'method' => $method,
				'params' => self::sanitize_params( $params ),
				'target' => $target,
			),
			$resource,
			array(
				'title'                  => $summary,
				'requested_by_key_id'    => self::requester_id( $key_record ),
				// #1042: exemption from the approval caps is decided by
				// Mcpwp_Approvals::requester_is_exempt_from_caps() alone.
				'key_record'             => $key_record,
			)
		);

		if ( is_wp_error( $approval ) ) {
			return $approval;
		}

		return array(
			'approval_required' => true,
			'approval_id'       => $approval['id'],
			'status'            => 'pending',
			'operation'         => $operation,
			'message'           => sprintf(
				/* translators: 1: operation 2: approval id */
				__( '%1$s is a destructive operation and this site requires approval for it. Nothing was changed. Approval request %2$s is pending: a different reviewer approves it (wp_approve_request) and applies it (wp_apply_approval), or a WordPress administrator does so from the Control Room.', 'mcpwp' ),
				$operation,
				$approval['id']
			),
			'hint'              => 'Approval is the default for operations with no undo: any delete that is permanent — force=true, or force=false on a type this plugin does not trash on its own (media without MEDIA_TRASH enabled; Elementor templates and Custom Code snippets) — and purging all drafts. A non-forced delete of anything else (a post, a page, a WooCommerce product, a block, any other custom type) trashes it instead and is not gated. Exemptions (a key\'s allow_destructive, the site switch) are set by an administrator in wp-admin, not through the API.',
		);
	}

	/**
	 * Requester-controlled parameters are stored sanitised and bounded:
	 * they are shown to a reviewer, who may be another model.
	 *
	 * @param mixed $value Params.
	 * @param int   $depth Depth so far.
	 * @return mixed
	 */
	public static function sanitize_params( $value, $depth = 0 ) {
		if ( $depth > 4 ) {
			return null;
		}

		if ( is_array( $value ) ) {
			$out = array();
			$n   = 0;
			foreach ( $value as $k => $v ) {
				if ( ++$n > 50 ) {
					break;
				}
				$key = is_string( $k ) ? preg_replace( '/[^A-Za-z0-9_\-]/', '', $k ) : $k;
				if ( '' === $key ) {
					continue;
				}
				$out[ $key ] = self::sanitize_params( $v, $depth + 1 );
			}
			return $out;
		}

		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}

		return mb_substr( sanitize_text_field( (string) $value ), 0, 500 );
	}

	/**
	 * A fingerprint of the target at defer time, re-checked at apply.
	 *
	 * @param array $resource {type, id}.
	 * @return array
	 */
	public static function fingerprint( array $resource ) {
		// A purge of every draft is fingerprinted as the SET it would remove:
		// between review and apply the set can only stay the same or the
		// apply is refused (#536 round 2, P2-4).
		if ( isset( $resource['type'] ) && 'drafts' === $resource['type'] ) {
			return self::fingerprint_drafts( isset( $resource['post_type'] ) ? (string) $resource['post_type'] : 'all' );
		}

		if ( isset( $resource['type'] ) && 'term' === $resource['type'] ) {
			return self::fingerprint_term( isset( $resource['id'] ) ? (int) $resource['id'] : 0, isset( $resource['taxonomy'] ) ? (string) $resource['taxonomy'] : '' );
		}

		if ( isset( $resource['type'] ) && 'widget' === $resource['type'] ) {
			return self::fingerprint_widget( isset( $resource['id'] ) ? (string) $resource['id'] : '' );
		}

		if ( isset( $resource['type'] ) && 'user' === $resource['type'] ) {
			return self::fingerprint_user( isset( $resource['id'] ) ? (int) $resource['id'] : 0 );
		}

		if ( 'api_key' === $resource['type'] ) {
			return array();
		}

		// Post-like with an id this gate cannot use: tagged, never an empty
		// fingerprint that reads as "no drift" (#536 round 5).
		if ( empty( $resource['id'] ) || ! is_numeric( $resource['id'] ) || (int) $resource['id'] < 1 ) {
			return array( 'exists' => false, 'unparseable' => true );
		}

		$post = get_post( (int) $resource['id'] );
		if ( ! $post ) {
			return array( 'exists' => false );
		}

		return array(
			'exists'   => true,
			'type'     => (string) $post->post_type,
			'status'   => (string) $post->post_status,
			'modified' => isset( $post->post_modified_gmt ) ? (string) $post->post_modified_gmt : '',
			'title'    => (string) $post->post_title,
			'hash'     => hash( 'sha256', (string) $post->post_title . "\0" . (string) $post->post_content ),
		);
	}

	/**
	 * A term, as a fingerprint: renamed or re-slugged between review and
	 * apply is a different thing (#536 round 3, P2-4).
	 *
	 * @param int    $term_id  Term id.
	 * @param string $taxonomy Taxonomy.
	 * @return array
	 */
	public static function fingerprint_term( $term_id, $taxonomy ) {
		if ( $term_id < 1 || '' === $taxonomy ) {
			return array( 'exists' => false, 'unparseable' => true );
		}
		if ( ! function_exists( 'get_term' ) ) {
			return array( 'exists' => false );
		}
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return array( 'exists' => false );
		}
		return array(
			'exists'   => true,
			'type'     => 'term',
			'taxonomy' => (string) $taxonomy,
			'status'   => '',
			'modified' => '',
			'title'    => (string) $term->name,
			'count'    => isset( $term->count ) ? (int) $term->count : 0,
			'hash'     => hash( 'sha256', (string) $term->name . "\0" . (string) $term->slug . "\0" . (string) $taxonomy . "\0" . ( isset( $term->parent ) ? (int) $term->parent : 0 ) ),
		);
	}

	/**
	 * A user, as a fingerprint: login, e-mail and roles. Renamed, re-mailed
	 * or promoted between review and apply is a different person to delete.
	 *
	 * @param int $user_id User id.
	 * @return array
	 */
	public static function fingerprint_user( $user_id ) {
		if ( $user_id < 1 ) {
			return array( 'exists' => false, 'unparseable' => true );
		}
		if ( ! function_exists( 'get_user_by' ) ) {
			return array( 'exists' => false );
		}
		$user = get_user_by( 'ID', $user_id );
		if ( ! $user ) {
			return array( 'exists' => false );
		}
		$roles = isset( $user->roles ) && is_array( $user->roles ) ? $user->roles : array();
		sort( $roles );
		return array(
			'exists'   => true,
			'type'     => 'user',
			'status'   => '',
			'modified' => '',
			'title'    => (string) $user->user_login,
			'hash'     => hash( 'sha256', (string) $user->user_login . "\0" . (string) $user->user_email . "\0" . implode( ',', $roles ) ),
		);
	}

	/**
	 * A widget instance, as a fingerprint of its settings.
	 *
	 * @param string $widget_id e.g. text-3.
	 * @return array
	 */
	public static function fingerprint_widget( $widget_id ) {
		if ( ! preg_match( '/^([a-z0-9_\-]+?)-(\d+)$/i', (string) $widget_id, $m ) ) {
			return array( 'exists' => false, 'unparseable' => true );
		}
		$instances = get_option( 'widget_' . $m[1], array() );
		if ( ! is_array( $instances ) || ! isset( $instances[ (int) $m[2] ] ) ) {
			return array( 'exists' => false );
		}
		return array(
			'exists'   => true,
			'type'     => 'widget',
			'status'   => '',
			'modified' => '',
			'title'    => (string) $widget_id,
			'hash'     => hash( 'sha256', wp_json_encode( $instances[ (int) $m[2] ] ) ),
		);
	}

	/**
	 * The ids a delete-all-drafts run would remove, as a fingerprint. The
	 * count is exact and cheap (wp_count_posts); the id list is bounded.
	 *
	 * What the bound trades away, stated: past DRAFTS_FINGERPRINT_IDS drafts
	 * only the count is compared, so a draft REPLACED beyond the window
	 * (one deleted, one created) is not detected; any addition or removal
	 * still is.
	 *
	 * @param string $post_type post|page|all.
	 * @return array
	 */
	public static function fingerprint_drafts( $post_type = 'all' ) {
		$types = in_array( $post_type, array( 'post', 'page' ), true ) ? array( $post_type ) : array( 'post', 'page' );

		$count = 0;
		foreach ( $types as $type ) {
			$counts = function_exists( 'wp_count_posts' ) ? wp_count_posts( $type ) : null;
			if ( is_object( $counts ) && isset( $counts->draft ) ) {
				$count += (int) $counts->draft;
			} elseif ( is_array( $counts ) && isset( $counts['draft'] ) ) {
				$count += (int) $counts['draft'];
			}
		}

		$ids = array();
		if ( class_exists( 'WP_Query' ) ) {
			$query = new WP_Query(
				array(
					'post_type'      => $types,
					'post_status'    => 'draft',
					'posts_per_page' => self::DRAFTS_FINGERPRINT_IDS,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			foreach ( is_array( $query->posts ) ? $query->posts : array() as $p ) {
				$ids[] = is_object( $p ) ? (int) $p->ID : (int) $p;
			}
		}
		sort( $ids );
		$count = max( $count, count( $ids ) );

		return array(
			'exists'    => true,
			'type'      => 'drafts',
			'post_type' => implode( ',', $types ),
			'count'     => $count,
			'ids'       => array_slice( $ids, 0, 200 ),
			'hash'      => hash( 'sha256', $count . '|' . implode( ',', $ids ) ),
			'title'     => sprintf( '%d draft(s)', $count ),
			'status'    => 'draft',
			'modified'  => '',
		);
	}

	/**
	 * Whether a route belongs to an operation.
	 *
	 * @param string $operation Operation.
	 * @param string $method    HTTP method.
	 * @param string $route     Route.
	 * @return true|WP_Error
	 */
	public static function route_matches_operation( $operation, $method, $route ) {
		$operation = sanitize_key( (string) $operation );

		if ( ! isset( self::OPERATION_ROUTES[ $operation ] ) ) {
			return new WP_Error( 'destructive_operation_unknown', sprintf( 'Unknown destructive operation "%s".', $operation ), array( 'status' => 400 ) );
		}

		list( $want_method, $pattern ) = self::OPERATION_ROUTES[ $operation ];
		if ( strtoupper( (string) $method ) !== $want_method || ! preg_match( $pattern, (string) $route ) ) {
			return new WP_Error(
				'destructive_route_mismatch',
				sprintf(
					/* translators: 1: operation 2: method 3: route */
					__( 'Operation %1$s does not replay %2$s %3$s. The stored request does not match the operation it was reviewed as; nothing was applied.', 'mcpwp' ),
					$operation,
					strtoupper( (string) $method ),
					(string) $route
				),
				array( 'status' => 409 )
			);
		}

		return true;
	}

	// ---------------------------------------------------------------
	// Apply.
	// ---------------------------------------------------------------

	/**
	 * Re-dispatch a stored operation with the gate lifted for that dispatch.
	 *
	 * The re-dispatch runs through the route's own permission callback and
	 * authenticates as the principal APPLYING the approval: an API key
	 * forwarded on the internal request, or a WordPress administrator
	 * applying from wp-admin. Never the requester, never nobody.
	 *
	 * @param array $payload {route, method, params, target}.
	 * @param array $context {api_key?: string, admin_user?: int}.
	 * @return array|WP_Error Response data or error.
	 */
	public static function replay( array $payload, array $context = array(), $operation = '' ) {
		if ( empty( $payload['route'] ) || ! function_exists( 'rest_do_request' ) ) {
			return new WP_Error( 'destructive_replay_unavailable', __( 'The stored operation cannot be replayed.', 'mcpwp' ), array( 'status' => 500 ) );
		}

		// What the reviewer consented to is the operation on the record. A
		// payload that names any other route is not applied, whoever stored
		// it and whatever its summary says.
		$bound = self::route_matches_operation( $operation, isset( $payload['method'] ) ? $payload['method'] : 'DELETE', $payload['route'] );
		if ( is_wp_error( $bound ) ) {
			return $bound;
		}

		$api_key    = isset( $context['api_key'] ) ? (string) $context['api_key'] : '';
		$admin_user = isset( $context['admin_user'] ) ? (int) $context['admin_user'] : 0;

		if ( '' === $api_key && $admin_user < 1 ) {
			return new WP_Error(
				'destructive_replay_unauthenticated',
				__( 'Applying a destructive approval needs the applying principal — a reviewer\'s key, or an administrator in wp-admin — so the operation runs as them.', 'mcpwp' ),
				array( 'status' => 401 )
			);
		}

		// What was reviewed is what runs.
		$drift = self::drift( $payload );
		if ( is_wp_error( $drift ) ) {
			return $drift;
		}

		$request = new WP_REST_Request( isset( $payload['method'] ) ? $payload['method'] : 'DELETE', $payload['route'] );
		foreach ( isset( $payload['params'] ) && is_array( $payload['params'] ) ? $payload['params'] : array() as $k => $v ) {
			$request->set_param( $k, $v );
		}
		if ( '' !== $api_key ) {
			$request->set_header( 'X-API-Key', $api_key );
		}

		$prev_applying = self::$applying;
		$prev_admin    = self::$applying_as_admin;
		self::$applying          = true;
		self::$applying_as_admin = '' === $api_key ? $admin_user : 0;
		try {
			$response = rest_do_request( $request );
		} finally {
			self::$applying          = $prev_applying;
			self::$applying_as_admin = $prev_admin;
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = method_exists( $response, 'get_status' ) ? (int) $response->get_status() : 200;
		$data   = method_exists( $response, 'get_data' ) ? $response->get_data() : null;

		if ( $status >= 400 ) {
			$message = sprintf(
				/* translators: %d: HTTP status */
				__( 'The approved operation failed when applied (HTTP %d).', 'mcpwp' ),
				$status
			);

			// #1022 round-2 review NIT: name the disabled category to the
			// wp-admin PRINCIPAL APPLYING this, rather than leaving them
			// with a bare HTTP status. This is not the existence-oracle
			// concern a scoped key's own denial must avoid — the applier is
			// a trusted reviewer looking at a KNOWN target (the approval
			// record already named its resolved post_type when it was
			// queued; see fingerprint()), so telling them WHY a 403/404
			// happened discloses nothing they could not already see on the
			// record they are reviewing.
			$resource_type = isset( $context['resource_type'] ) ? (string) $context['resource_type'] : '';
			if ( '' !== $resource_type && in_array( $status, array( 403, 404 ), true ) && class_exists( 'Mcpwp_REST_API' ) ) {
				$category = Mcpwp_REST_API::category_for_post_type( $resource_type );
				$disabled = get_option( 'mcpwp_disabled_tool_categories', array() );
				if ( is_array( $disabled ) && in_array( $category, $disabled, true ) ) {
					$message = sprintf(
						/* translators: 1: tool category, 2: HTTP status */
						__( 'The approved operation could not be applied: the "%1$s" tool category is currently disabled by the site administrator (HTTP %2$d). Re-enable it in WP Admin > mcpwp > Tools, then apply again.', 'mcpwp' ),
						$category,
						$status
					);
				}
			}

			return new WP_Error(
				'destructive_replay_failed',
				$message,
				array(
					'status'   => $status,
					'response' => $data,
				)
			);
		}

		return is_array( $data ) ? $data : array( 'applied' => true );
	}

	/**
	 * Refuse an apply whose target no longer matches what was reviewed.
	 *
	 * @param array $payload Stored payload.
	 * @return true|WP_Error
	 */
	private static function drift( array $payload ) {
		$target = isset( $payload['target'] ) && is_array( $payload['target'] ) ? $payload['target'] : array();
		if ( empty( $target ) || empty( $target['exists'] ) ) {
			return true;
		}

		if ( isset( $target['type'] ) && 'drafts' === $target['type'] ) {
			$now = self::fingerprint_drafts( isset( $payload['params']['type'] ) && is_string( $payload['params']['type'] ) ? $payload['params']['type'] : 'all' );
			if ( $now['hash'] !== $target['hash'] ) {
				return new WP_Error(
					'target_changed',
					sprintf(
						/* translators: 1: reviewed count 2: current count */
						__( 'The set of drafts changed after this request was reviewed (%1$d → %2$d). Nothing was applied. Review it again with a new request.', 'mcpwp' ),
						isset( $target['count'] ) ? (int) $target['count'] : 0,
						$now['count']
					),
					array(
						'status'   => 409,
						'reviewed' => array( 'count' => isset( $target['count'] ) ? (int) $target['count'] : 0, 'ids' => isset( $target['ids'] ) ? $target['ids'] : array() ),
						'current'  => array( 'count' => $now['count'], 'ids' => $now['ids'] ),
					)
				);
			}
			return true;
		}

		if ( isset( $target['type'] ) && in_array( $target['type'], array( 'term', 'widget', 'user' ), true ) ) {
			$raw_id = isset( $payload['params']['id'] ) ? $payload['params']['id'] : '';
			if ( '' === (string) $raw_id && preg_match( '#/([^/]+)/?$#', (string) $payload['route'], $m ) ) {
				$raw_id = $m[1];
			}
			if ( 'term' === $target['type'] ) {
				$now = self::fingerprint_term( (int) $raw_id, isset( $target['taxonomy'] ) ? (string) $target['taxonomy'] : '' );
			} elseif ( 'user' === $target['type'] ) {
				$now = self::fingerprint_user( (int) $raw_id );
			} else {
				$now = self::fingerprint_widget( (string) $raw_id );
			}
			if ( empty( $now['exists'] ) ) {
				return true; // Already gone; the route will answer 404.
			}
			if ( $now['hash'] !== $target['hash'] ) {
				return new WP_Error(
					'target_changed',
					sprintf(
						/* translators: 1: type 2: id */
						__( 'The %1$s %2$s changed after this request was reviewed. Nothing was applied. Review it again with a new request.', 'mcpwp' ),
						$target['type'],
						(string) $raw_id
					),
					array( 'status' => 409, 'reviewed' => array( 'title' => $target['title'] ), 'current' => array( 'title' => $now['title'] ) )
				);
			}
			return true;
		}

		$id = 0;
		if ( isset( $payload['params']['id'] ) ) {
			$id = (int) $payload['params']['id'];
		} elseif ( preg_match( '#/(\d+)(?:/|$)#', (string) $payload['route'], $m ) ) {
			$id = (int) $m[1];
		}
		if ( $id < 1 ) {
			return true;
		}

		$now = self::fingerprint( array( 'type' => $target['type'], 'id' => $id ) );
		if ( empty( $now['exists'] ) ) {
			return true; // Already gone; the route will answer 404.
		}

		// Hash, type AND status: a draft approved for deletion that has since
		// been published is a different thing (#536 round 2, P2-3).
		if ( $now['hash'] !== $target['hash'] || $now['type'] !== $target['type'] || ( isset( $target['status'] ) && $now['status'] !== $target['status'] ) ) {
			return new WP_Error(
				'target_changed',
				sprintf(
					/* translators: 1: id 2: title reviewed 3: title now */
					__( 'Post #%1$d changed after this request was reviewed ("%2$s" → "%3$s"). Nothing was applied. Review it again with a new request.', 'mcpwp' ),
					$id,
					$target['title'],
					$now['title']
				),
				array(
					'status'   => 409,
					'reviewed' => array( 'title' => $target['title'], 'status' => $target['status'], 'modified' => $target['modified'] ),
					'current'  => array( 'title' => $now['title'], 'status' => $now['status'], 'modified' => $now['modified'] ),
				)
			);
		}

		return true;
	}
}
