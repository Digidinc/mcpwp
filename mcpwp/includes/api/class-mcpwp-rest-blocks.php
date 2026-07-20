<?php
/**
 * REST API Blocks Controller
 *
 * Provides endpoints for Gutenberg block editor operations:
 * get/set parsed blocks, list block types, and list block patterns.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Blocks REST controller.
 */
class Mcpwp_REST_Blocks extends Mcpwp_REST_API {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		// Get / set parsed blocks for a post.
		register_rest_route(
			$this->namespace,
			'/blocks/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_blocks' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'set_blocks' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'approval_required' => array(
							'description'       => __( 'Create an approval request instead of saving immediately. Defaults to true. Bypassing (false) requires an admin-scoped API key.', 'mcpwp' ),
							'type'              => 'boolean',
							'default'           => true,
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
					),
				),
			)
		);

		// List registered block types.
		register_rest_route(
			$this->namespace,
			'/block-types',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_block_types' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// List registered block patterns.
		register_rest_route(
			$this->namespace,
			'/block-patterns',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_block_patterns' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Parse raw Gutenberg block markup into an inspectable block tree.
		register_rest_route(
			$this->namespace,
			'/blocks/parse',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'parse_block_content' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'content' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		// Serialize a block tree back to Gutenberg block markup.
		register_rest_route(
			$this->namespace,
			'/blocks/serialize',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'serialize_block_content' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'blocks' => array(
						'type'     => 'array',
						'required' => true,
					),
				),
			)
		);

		// Validate block-native safety before saving generated content.
		register_rest_route(
			$this->namespace,
			'/blocks/validate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'validate_block_content' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'content' => array(
						'type'     => 'string',
						'required' => false,
					),
					'blocks'  => array(
						'type'     => 'array',
						'required' => false,
					),
				),
			)
		);

		// Patch one block section without rewriting the full page directly.
		register_rest_route(
			$this->namespace,
			'/blocks/(?P<id>\d+)/section',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'patch_block_section' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Agent-facing Gutenberg design system and block grammar.
		register_rest_route(
			$this->namespace,
			'/blocks/design-system',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_block_design_system' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'include_patterns_content' => array(
						'type'              => 'boolean',
						'required'          => false,
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
				),
			)
		);
	}

	/**
	 * Get parsed blocks for a post or page.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_blocks( $request ) {
		$post_id = $request->get_param( 'id' );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return $this->error_response( 'not_found', 'Post not found.', 404 );
		}

		// Non-public gate: get_blocks returns raw_content + parsed blocks for ANY
		// status, so a read-only key could otherwise read an unpublished body.
		// Return the same 404 as a missing post so existence is not disclosed.
		$denied = $this->guard_non_public_post( $post, 'Post not found.' );
		if ( $denied ) {
			return $denied;
		}

		$content = $post->post_content;
		$blocks  = parse_blocks( $content );

		// Clean up parsed blocks for API output.
		$cleaned = $this->clean_blocks( $blocks );

		$this->log_activity( 'get_blocks', $request, array( 'post_id' => $post_id ) );

		return $this->success_response(
			array(
				'post_id'     => $post_id,
				'title'       => $post->post_title,
				'block_count' => count( $cleaned ),
				'blocks'      => $cleaned,
				'raw_content' => $content,
			)
		);
	}

	/**
	 * Set blocks for a post or page.
	 *
	 * Accepts either a blocks array (serialized via serialize_blocks)
	 * or raw block content string.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function set_blocks( $request ) {
		$post_id = $request->get_param( 'id' );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return $this->error_response( 'not_found', 'Post not found.', 404 );
		}

		// Status-parity guard: a read-only key must not read a draft/private post
		// body. set_blocks queues an approval whose public_record.diff carries a
		// before_excerpt of the existing content, so reject a non-public source
		// with the same 404 a missing post returns before any body is read.
		$source_guard = $this->guard_non_public_post( $post, 'Post not found.' );
		if ( is_wp_error( $source_guard ) ) {
			return $source_guard;
		}

		$blocks  = $request->get_param( 'blocks' );
		$content = $request->get_param( 'content' );
		$allow_restricted_blocks = rest_sanitize_boolean( $request->get_param( 'allow_restricted_blocks' ) );
		$approval_required       = null === $request->get_param( 'approval_required' )
			? true
			: rest_sanitize_boolean( $request->get_param( 'approval_required' ) );
		$approval_note           = (string) $request->get_param( 'approval_note' );

		// Approval-gate floor (admin-only bypass): an explicit approval_required=false is
		// honored only for an admin-scoped API key or the trusted admin-AJAX context. A
		// plain write/read key — or ANY OAuth connector, regardless of scope — is silently
		// forced through approval. OAuth now carries a key record (type 'oauth'), so it is
		// excluded here explicitly rather than by relying on a null record.
		if ( false === $approval_required ) {
			$requester_key = $this->get_current_api_key_record();
			$can_bypass    = $requester_key && ! $this->requester_is_oauth() && $this->key_has_scope( $requester_key, 'admin' );
			if ( ! ( $can_bypass || $this->is_trusted_admin_ajax_request() ) ) {
				$approval_required = true;
			}
		}

		if ( ! empty( $blocks ) && is_array( $blocks ) ) {
			// Serialize blocks array to HTML content.
			$content = serialize_blocks( $blocks );
		} elseif ( empty( $content ) ) {
			return $this->error_response(
				'missing_data',
				'Provide either a blocks array or content string.',
				400
			);
		}

		$safety_report = $this->build_block_safety_report( $content );
		if ( ! $safety_report['pass'] ) {
			if ( ! $allow_restricted_blocks ) {
				return new WP_Error(
					'block_safety_failed',
					'Block safety validation failed. Generated content must be editable Gutenberg blocks by default.',
					array(
						'status'        => 400,
						'safety_report' => $safety_report,
						'hint'          => 'Use wp_validate_blocks before saving. Restricted output requires allow_restricted_blocks=true and an approval_note.',
					)
				);
			}

			if ( '' === trim( $approval_note ) ) {
				return new WP_Error(
					'approval_note_required',
					'Restricted block output requires an approval note before saving.',
					array(
						'status'        => 400,
						'safety_report' => $safety_report,
						'hint'          => 'Pass approval_note explaining why the restricted output is necessary.',
					)
				);
			}
		}

		if ( $approval_required ) {
			if ( ! class_exists( 'Mcpwp_Approvals' ) ) {
				return $this->error_response( 'approvals_unavailable', 'Approval pipeline is not available.', 500 );
			}

			$identity_guard = $this->guard_stable_identity_for_approvals();
			if ( is_wp_error( $identity_guard ) ) {
				return $identity_guard;
			}

			$approval = Mcpwp_Approvals::create_post_content_request(
				$post_id,
				$content,
				array(
					'title'               => sprintf( 'Update Gutenberg blocks for #%d', (int) $post_id ),
					'note'                => $approval_note,
					'tool'                => 'wp_set_blocks',
					'requested_by_key_id' => $this->current_requester_key_id(),
					'metadata'            => array(
						'safety' => $safety_report,
					),
				)
			);

			if ( is_wp_error( $approval ) ) {
				return $approval;
			}

			$this->log_activity( 'request_block_approval', $request, array( 'post_id' => $post_id, 'approval_id' => $approval['id'] ) );

			return $this->success_response(
				array(
					'success'     => true,
					'status'      => 'approval_required',
					'post_id'     => (int) $post_id,
					'approval'    => $approval,
					'safety'      => $safety_report,
					'hint'        => 'Approval request created. Use wp_approve_request, then wp_apply_approval to apply it.',
				),
				202
			);
		}

		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $content,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return $this->error_response(
				'update_failed',
				$result->get_error_message(),
				500
			);
		}

		// Re-parse to return the saved state.
		$saved_content = get_post_field( 'post_content', $post_id );
		$parsed        = $this->clean_blocks( parse_blocks( $saved_content ) );

		$this->log_activity( 'set_blocks', $request, array( 'post_id' => $post_id ) );

		return $this->success_response(
			array(
				'success'     => true,
				'post_id'     => $post_id,
				'block_count' => count( $parsed ),
				'blocks'      => $parsed,
				'safety'      => $safety_report,
				'approved_restricted_blocks' => ( ! $safety_report['pass'] && $allow_restricted_blocks ),
			)
		);
	}

	/**
	 * List registered block types.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function list_block_types( $request ) {
		if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
			return $this->error_response(
				'not_available',
				'Block type registry not available.',
				500
			);
		}

		$registry    = WP_Block_Type_Registry::get_instance();
		$block_types = $registry->get_all_registered();
		$result      = array();

		foreach ( $block_types as $name => $block_type ) {
			$item = array(
				'name'     => $name,
				'title'    => ! empty( $block_type->title ) ? $block_type->title : '',
				'category' => ! empty( $block_type->category ) ? $block_type->category : '',
				'icon'     => ! empty( $block_type->icon ) && is_string( $block_type->icon ) ? $block_type->icon : '',
			);

			if ( ! empty( $block_type->description ) ) {
				$item['description'] = $block_type->description;
			}

			if ( ! empty( $block_type->keywords ) ) {
				$item['keywords'] = $block_type->keywords;
			}

			if ( ! empty( $block_type->supports ) ) {
				$item['supports'] = $block_type->supports;
			}

			$result[] = $item;
		}

		$this->log_activity( 'list_block_types', $request );

		return $this->success_response(
			array(
				'total'       => count( $result ),
				'block_types' => $result,
			)
		);
	}

	/**
	 * List registered block patterns.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function list_block_patterns( $request ) {
		if ( ! class_exists( 'WP_Block_Patterns_Registry' ) ) {
			return $this->error_response(
				'not_available',
				'Block patterns registry not available.',
				500
			);
		}

		$registry = WP_Block_Patterns_Registry::get_instance();
		$patterns = $registry->get_all_registered();
		$result   = array();

		foreach ( $patterns as $pattern ) {
			$item = array(
				'name'       => $pattern['name'],
				'title'      => ! empty( $pattern['title'] ) ? $pattern['title'] : '',
				'categories' => ! empty( $pattern['categories'] ) ? $pattern['categories'] : array(),
			);

			if ( ! empty( $pattern['description'] ) ) {
				$item['description'] = $pattern['description'];
			}

			if ( ! empty( $pattern['keywords'] ) ) {
				$item['keywords'] = $pattern['keywords'];
			}

			// Include content so AI can use patterns directly.
			if ( ! empty( $pattern['content'] ) ) {
				$item['content'] = $pattern['content'];
			}

			$result[] = $item;
		}

		$this->log_activity( 'list_block_patterns', $request );

		return $this->success_response(
			array(
				'total'    => count( $result ),
				'patterns' => $result,
			)
		);
	}

	/**
	 * Parse raw Gutenberg block markup into a structured block tree.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function parse_block_content( $request ) {
		$content = $request->get_param( 'content' );

		if ( ! is_string( $content ) || '' === trim( $content ) ) {
			return $this->error_response(
				'missing_content',
				'Provide a non-empty content string.',
				400
			);
		}

		$blocks  = parse_blocks( $content );
		$cleaned = $this->clean_blocks( $blocks );

		$this->log_activity( 'parse_blocks', $request );

		return $this->success_response(
			array(
				'block_count'      => count( $cleaned ),
				'blocks'           => $cleaned,
				'has_block_markup' => has_blocks( $content ),
				'safety'           => $this->build_block_safety_report( $content ),
				'raw_content'      => $content,
			)
		);
	}

	/**
	 * Serialize a structured block tree into Gutenberg block markup.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function serialize_block_content( $request ) {
		$blocks = $request->get_param( 'blocks' );

		if ( empty( $blocks ) || ! is_array( $blocks ) ) {
			return $this->error_response(
				'missing_blocks',
				'Provide a non-empty blocks array.',
				400
			);
		}

		$content = serialize_blocks( $blocks );
		$parsed  = $this->clean_blocks( parse_blocks( $content ) );

		$this->log_activity( 'serialize_blocks', $request );

		return $this->success_response(
			array(
				'content'          => $content,
				'block_count'      => count( $parsed ),
				'roundtrip_blocks' => $parsed,
				'safety'           => $this->build_block_safety_report( $content ),
			)
		);
	}

	/**
	 * Validate content for block-native safety.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function validate_block_content( $request ) {
		$blocks  = $request->get_param( 'blocks' );
		$content = $request->get_param( 'content' );

		if ( ! empty( $blocks ) && is_array( $blocks ) ) {
			$content = serialize_blocks( $blocks );
		}

		if ( ! is_string( $content ) || '' === trim( $content ) ) {
			return $this->error_response(
				'missing_data',
				'Provide either a blocks array or content string.',
				400
			);
		}

		$this->log_activity( 'validate_blocks', $request );

		return $this->success_response( $this->build_block_safety_report( $content ) );
	}

	/**
	 * Patch one section by path, anchor, or heading.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function patch_block_section( $request ) {
		$post_id = $request->get_param( 'id' );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return $this->error_response( 'not_found', 'Post not found.', 404 );
		}

		// Status-parity guard: patching a section reads the existing post body and
		// queues an approval whose diff carries a before_excerpt of it. A read-only
		// key must not reach a draft/private body — reject with the same 404 a
		// missing post returns.
		$source_guard = $this->guard_non_public_post( $post, 'Post not found.' );
		if ( is_wp_error( $source_guard ) ) {
			return $source_guard;
		}

		$selector = $request->get_param( 'selector' );
		$content  = $request->get_param( 'content' );
		$blocks   = $request->get_param( 'blocks' );

		if ( empty( $selector ) || ! is_array( $selector ) ) {
			return $this->error_response( 'missing_selector', 'Provide a selector with path, anchor, or heading.', 400 );
		}

		if ( ! empty( $blocks ) && is_array( $blocks ) ) {
			$content = serialize_blocks( $blocks );
		}

		if ( ! is_string( $content ) || '' === trim( $content ) ) {
			return $this->error_response( 'missing_content', 'Provide replacement section content or blocks.', 400 );
		}

		$replacement_blocks = $this->filter_empty_blocks( parse_blocks( $content ) );
		if ( empty( $replacement_blocks ) ) {
			return $this->error_response( 'invalid_replacement', 'Replacement content did not parse into Gutenberg blocks.', 400 );
		}

		$safety_report = $this->build_block_safety_report( serialize_blocks( $replacement_blocks ) );
		if ( ! $safety_report['pass'] ) {
			return new WP_Error(
				'block_safety_failed',
				'Replacement section failed block safety validation.',
				array(
					'status'        => 400,
					'safety_report' => $safety_report,
					'hint'          => 'Patch sections with editable Gutenberg blocks. Restricted section output should go through a separate human-approved full-content edit.',
				)
			);
		}

		$page_blocks = $this->filter_empty_blocks( parse_blocks( $post->post_content ) );
		$path        = $this->resolve_section_selector_path( $page_blocks, $selector );

		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$patched_blocks = $page_blocks;
		$patched        = $this->replace_blocks_at_path( $patched_blocks, $path, $replacement_blocks );

		if ( ! $patched ) {
			return $this->error_response( 'section_patch_failed', 'Could not replace the selected section.', 500 );
		}

		$patched_content    = serialize_blocks( $patched_blocks );
		$approval_required = null === $request->get_param( 'approval_required' )
			? true
			: rest_sanitize_boolean( $request->get_param( 'approval_required' ) );
		$approval_note     = (string) $request->get_param( 'approval_note' );

		// Approval-gate floor (admin-only bypass): an explicit approval_required=false is
		// honored only for an admin-scoped API key or the trusted admin-AJAX context. A
		// plain write/read key — or ANY OAuth connector, regardless of scope — is silently
		// forced through approval. OAuth now carries a key record (type 'oauth'), so it is
		// excluded here explicitly rather than by relying on a null record.
		if ( false === $approval_required ) {
			$requester_key = $this->get_current_api_key_record();
			$can_bypass    = $requester_key && ! $this->requester_is_oauth() && $this->key_has_scope( $requester_key, 'admin' );
			if ( ! ( $can_bypass || $this->is_trusted_admin_ajax_request() ) ) {
				$approval_required = true;
			}
		}

		if ( $approval_required ) {
			if ( ! class_exists( 'Mcpwp_Approvals' ) ) {
				return $this->error_response( 'approvals_unavailable', 'Approval pipeline is not available.', 500 );
			}

			$identity_guard = $this->guard_stable_identity_for_approvals();
			if ( is_wp_error( $identity_guard ) ) {
				return $identity_guard;
			}

			$approval = Mcpwp_Approvals::create_post_content_request(
				$post_id,
				$patched_content,
				array(
					'title'               => sprintf( 'Patch Gutenberg section for #%d', (int) $post_id ),
					'note'                => $approval_note,
					'tool'                => 'wp_patch_block_section',
					'requested_by_key_id' => $this->current_requester_key_id(),
					'metadata'            => array(
						'selector' => $selector,
						'path'     => $path,
						'safety'   => $safety_report,
					),
				)
			);

			if ( is_wp_error( $approval ) ) {
				return $approval;
			}

			$this->log_activity( 'request_section_patch_approval', $request, array( 'post_id' => $post_id, 'approval_id' => $approval['id'] ) );

			return $this->success_response(
				array(
					'success'  => true,
					'status'   => 'approval_required',
					'post_id'  => (int) $post_id,
					'selector' => $selector,
					'path'     => $path,
					'approval' => $approval,
					'safety'   => $safety_report,
				),
				202
			);
		}

		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $patched_content,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return $this->error_response( 'update_failed', $result->get_error_message(), 500 );
		}

		$this->log_activity( 'patch_block_section', $request, array( 'post_id' => $post_id ) );

		return $this->success_response(
			array(
				'success'     => true,
				'post_id'     => (int) $post_id,
				'selector'    => $selector,
				'path'        => $path,
				'block_count' => count( $this->clean_blocks( parse_blocks( $patched_content ) ) ),
				'safety'      => $safety_report,
			)
		);
	}

	/**
	 * Return an agent-facing Gutenberg design system.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function get_block_design_system( $request ) {
		$include_patterns_content = rest_sanitize_boolean( $request->get_param( 'include_patterns_content' ) );
		$theme                    = wp_get_theme();

		$this->log_activity( 'get_block_design_system', $request );

		return $this->success_response(
			array(
				'site_editor_ready'      => function_exists( 'wp_is_block_theme' ) ? wp_is_block_theme() : false,
				'theme'                  => array(
					'name'       => $theme->get( 'Name' ),
					'stylesheet' => get_stylesheet(),
					'template'   => get_template(),
					'version'    => $theme->get( 'Version' ),
				),
				'grammar'                => $this->get_html_like_block_grammar(),
				'composition_rules'      => $this->get_block_composition_rules(),
				'recommended_primitives' => $this->get_recommended_block_primitives(),
				'recipes'                => $this->get_design_recipes(),
				'block_types'            => $this->summarize_block_types(),
				'patterns'               => $this->summarize_block_patterns( $include_patterns_content ),
				'workflow'               => array(
					'discover'  => 'Call wp_get_block_design_system, then wp_list_block_patterns for full pattern content if needed.',
					'draft'     => 'Build valid WordPress block markup using core block comments, attributes, and nested inner blocks.',
					'validate'  => 'Call wp_validate_blocks and wp_parse_blocks before saving. Fix classic/null blocks, core/html, inline script/style tags, and unsafe iframes.',
					'save'      => 'Call wp_set_blocks with content for exact markup, or blocks for structured serialization.',
					'read_back' => 'Call wp_get_blocks after saving to confirm the stored tree.',
				),
			)
		);
	}

	/**
	 * Get a compact block grammar reference for agents.
	 *
	 * @return array Grammar reference.
	 */
	private function get_html_like_block_grammar() {
		return array(
			'container' => array(
				'html'  => '<section>',
				'block' => 'core/group',
				'open'  => '<!-- wp:group {"tagName":"section","layout":{"type":"constrained"}} -->',
				'close' => '<!-- /wp:group -->',
			),
			'grid'      => array(
				'html'  => '<div class="columns">',
				'block' => 'core/columns with nested core/column blocks',
			),
			'heading'   => array(
				'html'  => '<h2>',
				'block' => 'core/heading',
			),
			'paragraph' => array(
				'html'  => '<p>',
				'block' => 'core/paragraph',
			),
			'button'    => array(
				'html'  => '<a class="button">',
				'block' => 'core/buttons with nested core/button blocks',
			),
			'image'     => array(
				'html'  => '<figure><img>',
				'block' => 'core/image',
			),
			'list'      => array(
				'html'  => '<ul><li>',
				'block' => 'core/list',
			),
			'spacer'    => array(
				'html'  => '<div style="height">',
				'block' => 'core/spacer',
			),
		);
	}

	/**
	 * Get composition rules for stable agent edits.
	 *
	 * @return array Rules.
	 */
	private function get_block_composition_rules() {
		return array(
			'Use core blocks first; add plugin blocks only after wp_list_block_types confirms they exist.',
			'Prefer patterns for large sections, then edit copy, links, images, and spacing attributes.',
			'Keep semantic wrappers: group tagName section/main/header/footer where appropriate.',
			'Use nested columns sparingly; prefer group and grid-like layouts that remain readable on mobile.',
			'Always parse generated markup before saving. A classic block means the content was plain HTML, not block-native markup.',
			'Use reusable patterns and template parts for repeated sections instead of duplicating large markup.',
			'Do not use core/html, inline script/style tags, or unsafe iframes unless a human explicitly approves the exception.',
		);
	}

	/**
	 * Get recommended core blocks for design-system work.
	 *
	 * @return array Recommended blocks.
	 */
	private function get_recommended_block_primitives() {
		return array(
			'layout'     => array( 'core/group', 'core/columns', 'core/column', 'core/spacer', 'core/separator' ),
			'content'    => array( 'core/heading', 'core/paragraph', 'core/list', 'core/quote', 'core/table' ),
			'media'      => array( 'core/image', 'core/gallery', 'core/video', 'core/cover' ),
			'actions'    => array( 'core/buttons', 'core/button' ),
			'navigation' => array( 'core/navigation', 'core/query', 'core/post-template' ),
			'structure'  => array( 'core/template-part', 'core/post-content', 'core/query-title' ),
		);
	}

	/**
	 * Get reusable design recipes for agents.
	 *
	 * @return array Recipes.
	 */
	private function get_design_recipes() {
		return array(
			array(
				'name'      => 'page_hero',
				'purpose'   => 'Top page section with headline, copy, and primary action.',
				'structure' => array( 'core/group(section)', 'core/heading(h1)', 'core/paragraph', 'core/buttons', 'core/button' ),
			),
			array(
				'name'      => 'feature_grid',
				'purpose'   => 'Scannable product or service benefits.',
				'structure' => array( 'core/group(section)', 'core/heading', 'core/columns', 'core/column x3', 'heading/paragraph in each column' ),
			),
			array(
				'name'      => 'proof_band',
				'purpose'   => 'Testimonials, metrics, or trust markers.',
				'structure' => array( 'core/group(section)', 'core/columns', 'core/quote or core/paragraph metrics' ),
			),
			array(
				'name'      => 'faq',
				'purpose'   => 'Question and answer content without custom JS dependencies.',
				'structure' => array( 'core/group(section)', 'core/heading', 'core/details repeated' ),
			),
			array(
				'name'      => 'cta_band',
				'purpose'   => 'Final conversion section.',
				'structure' => array( 'core/group(section)', 'core/heading', 'core/paragraph', 'core/buttons' ),
			),
		);
	}

	/**
	 * Summarize registered block types.
	 *
	 * @return array Block type summary.
	 */
	private function summarize_block_types() {
		if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
			return array();
		}

		$registry    = WP_Block_Type_Registry::get_instance();
		$block_types = $registry->get_all_registered();
		$result      = array();

		foreach ( $block_types as $name => $block_type ) {
			$result[] = array(
				'name'        => $name,
				'title'       => ! empty( $block_type->title ) ? $block_type->title : '',
				'category'    => ! empty( $block_type->category ) ? $block_type->category : '',
				'description' => ! empty( $block_type->description ) ? $block_type->description : '',
				'supports'    => ! empty( $block_type->supports ) ? $block_type->supports : (object) array(),
			);
		}

		return $result;
	}

	/**
	 * Summarize registered block patterns.
	 *
	 * @param bool $include_content Whether to include full pattern content.
	 * @return array Pattern summary.
	 */
	private function summarize_block_patterns( $include_content = false ) {
		if ( ! class_exists( 'WP_Block_Patterns_Registry' ) ) {
			return array();
		}

		$registry = WP_Block_Patterns_Registry::get_instance();
		$patterns = $registry->get_all_registered();
		$result   = array();

		foreach ( $patterns as $pattern ) {
			$item = array(
				'name'        => $pattern['name'],
				'title'       => ! empty( $pattern['title'] ) ? $pattern['title'] : '',
				'categories'  => ! empty( $pattern['categories'] ) ? $pattern['categories'] : array(),
				'description' => ! empty( $pattern['description'] ) ? $pattern['description'] : '',
			);

			if ( $include_content && ! empty( $pattern['content'] ) ) {
				$item['content'] = $pattern['content'];
			}

			$result[] = $item;
		}

		return $result;
	}

	/**
	 * Build a safety report for generated Gutenberg content.
	 *
	 * @param string $content Raw block content.
	 * @return array Safety report.
	 */
	private function build_block_safety_report( $content ) {
		$issues = array();
		$blocks = parse_blocks( $content );

		if ( ! has_blocks( $content ) && '' !== trim( $content ) ) {
			$issues[] = $this->make_block_safety_issue(
				'classic_content',
				'error',
				'Content is plain HTML/classic content instead of native Gutenberg block markup.',
				'root',
				null
			);
		}

		if ( preg_match( '/<script\b/i', $content ) ) {
			$issues[] = $this->make_block_safety_issue(
				'inline_script',
				'error',
				'Inline script tags are restricted in generated block content.',
				'content',
				null
			);
		}

		if ( preg_match( '/<style\b/i', $content ) ) {
			$issues[] = $this->make_block_safety_issue(
				'inline_style',
				'error',
				'Inline style tags are restricted in generated block content.',
				'content',
				null
			);
		}

		$this->collect_block_safety_issues( $blocks, array(), $issues );

		$error_count   = 0;
		$warning_count = 0;
		foreach ( $issues as $issue ) {
			if ( 'error' === $issue['severity'] ) {
				$error_count++;
			} elseif ( 'warning' === $issue['severity'] ) {
				$warning_count++;
			}
		}

		return array(
			'pass'              => 0 === $error_count,
			'status'            => 0 === $error_count ? ( $warning_count > 0 ? 'warn' : 'pass' ) : 'fail',
			'has_block_markup'  => has_blocks( $content ),
			'block_count'       => count( $this->clean_blocks( $blocks ) ),
			'error_count'       => $error_count,
			'warning_count'     => $warning_count,
			'approval_required' => $error_count > 0,
			'issues'            => $issues,
			'validation_basis'  => 'stored_block_data',
			'validation_note'   => 'Validates the serialized block markup for structural correctness, disallowed block types, and unsafe HTML. Does not check rendered output — block plugins with server-side rendering or dynamic blocks may produce different HTML than what appears here.',
		);
	}

	/**
	 * Recursively collect block safety issues.
	 *
	 * @param array $blocks Parsed blocks.
	 * @param array $path   Current block path.
	 * @param array $issues Issues accumulator.
	 */
	private function collect_block_safety_issues( $blocks, $path, &$issues ) {
		foreach ( $blocks as $index => $block ) {
			$current_path = array_merge( $path, array( (string) $index ) );
			$path_label   = implode( '.innerBlocks.', $current_path );
			$block_name   = $block['blockName'] ?? null;
			$inner_html   = isset( $block['innerHTML'] ) ? (string) $block['innerHTML'] : '';

			if ( empty( $block_name ) && '' !== trim( $inner_html ) ) {
				$issues[] = $this->make_block_safety_issue(
					'classic_block',
					'error',
					'A classic/null block was found. Agents should emit native Gutenberg block comments and registered block types.',
					$path_label,
					$block_name
				);
			}

			if ( 'core/html' === $block_name ) {
				$issues[] = $this->make_block_safety_issue(
					'core_html_block',
					'error',
					'core/html is restricted as a default agent output because it creates opaque content.',
					$path_label,
					$block_name
				);
			}

			if ( preg_match( '/<script\b/i', $inner_html ) ) {
				$issues[] = $this->make_block_safety_issue(
					'inline_script',
					'error',
					'Inline script tags are restricted in block HTML.',
					$path_label,
					$block_name
				);
			}

			if ( preg_match( '/<style\b/i', $inner_html ) ) {
				$issues[] = $this->make_block_safety_issue(
					'inline_style',
					'error',
					'Inline style tags are restricted in block HTML.',
					$path_label,
					$block_name
				);
			}

			if ( preg_match( '/<iframe\b/i', $inner_html ) && ! in_array( $block_name, array( 'core/embed', 'core/video' ), true ) ) {
				$issues[] = $this->make_block_safety_issue(
					'unsafe_iframe',
					'error',
					'Iframes are restricted unless represented by a supported embed/media block.',
					$path_label,
					$block_name
				);
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$this->collect_block_safety_issues( $block['innerBlocks'], $current_path, $issues );
			}
		}
	}

	/**
	 * Create a normalized block safety issue.
	 *
	 * @param string      $code       Issue code.
	 * @param string      $severity   Severity.
	 * @param string      $message    Human-readable message.
	 * @param string      $path       Block path.
	 * @param string|null $block_name Block name.
	 * @return array Issue.
	 */
	private function make_block_safety_issue( $code, $severity, $message, $path, $block_name ) {
		return array(
			'code'      => $code,
			'severity'  => $severity,
			'message'   => $message,
			'path'      => $path,
			'blockName' => $block_name,
		);
	}

	/**
	 * Filter parser artifacts from parsed blocks.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return array Blocks.
	 */
	private function filter_empty_blocks( $blocks ) {
		$filtered = array();

		foreach ( $blocks as $block ) {
			if ( empty( $block['blockName'] ) && empty( trim( $block['innerHTML'] ?? '' ) ) ) {
				continue;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = $this->filter_empty_blocks( $block['innerBlocks'] );
			}

			$filtered[] = $block;
		}

		return $filtered;
	}

	/**
	 * Resolve a section selector to a numeric block path.
	 *
	 * @param array $blocks   Parsed blocks.
	 * @param array $selector Selector.
	 * @return array|WP_Error Path or error.
	 */
	private function resolve_section_selector_path( $blocks, $selector ) {
		if ( isset( $selector['path'] ) && '' !== (string) $selector['path'] ) {
			$path = $this->parse_block_path( (string) $selector['path'] );
			if ( null !== $path && $this->block_path_exists( $blocks, $path ) ) {
				return $path;
			}

			return new WP_Error( 'section_not_found', __( 'No block exists at the requested path.', 'mcpwp' ), array( 'status' => 404 ) );
		}

		if ( isset( $selector['anchor'] ) && '' !== trim( (string) $selector['anchor'] ) ) {
			$path = $this->find_block_path_by_anchor( $blocks, sanitize_title( (string) $selector['anchor'] ) );
			if ( null !== $path ) {
				return $path;
			}

			return new WP_Error( 'section_not_found', __( 'No block section matched the requested anchor.', 'mcpwp' ), array( 'status' => 404 ) );
		}

		if ( isset( $selector['heading'] ) && '' !== trim( (string) $selector['heading'] ) ) {
			$path = $this->find_section_path_by_heading( $blocks, (string) $selector['heading'] );
			if ( null !== $path ) {
				return $path;
			}

			return new WP_Error( 'section_not_found', __( 'No block section matched the requested heading.', 'mcpwp' ), array( 'status' => 404 ) );
		}

		return new WP_Error( 'invalid_selector', __( 'Selector must include path, anchor, or heading.', 'mcpwp' ), array( 'status' => 400 ) );
	}

	/**
	 * Parse a block path string.
	 *
	 * @param string $path Path like "0.innerBlocks.2" or "0/2".
	 * @return array|null Path.
	 */
	private function parse_block_path( $path ) {
		$path  = str_replace( array( '.innerBlocks.', '/' ), '.', trim( $path ) );
		$parts = array_values( array_filter( explode( '.', $path ), 'strlen' ) );
		$parsed = array();

		foreach ( $parts as $part ) {
			if ( ! ctype_digit( $part ) ) {
				return null;
			}
			$parsed[] = (int) $part;
		}

		return empty( $parsed ) ? null : $parsed;
	}

	/**
	 * Check whether a block path exists.
	 *
	 * @param array $blocks Blocks.
	 * @param array $path   Path.
	 * @return bool Exists.
	 */
	private function block_path_exists( $blocks, $path ) {
		$current = $blocks;
		foreach ( $path as $index ) {
			if ( ! isset( $current[ $index ] ) ) {
				return false;
			}
			$current = isset( $current[ $index ]['innerBlocks'] ) && is_array( $current[ $index ]['innerBlocks'] )
				? $current[ $index ]['innerBlocks']
				: array();
		}

		return true;
	}

	/**
	 * Find a block by anchor attribute.
	 *
	 * @param array $blocks Blocks.
	 * @param string $anchor Anchor.
	 * @param array  $path Current path.
	 * @return array|null Path.
	 */
	private function find_block_path_by_anchor( $blocks, $anchor, $path = array() ) {
		foreach ( $blocks as $index => $block ) {
			$current_path = array_merge( $path, array( $index ) );
			$attrs        = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();

			if ( isset( $attrs['anchor'] ) && sanitize_title( (string) $attrs['anchor'] ) === $anchor ) {
				return $current_path;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$found = $this->find_block_path_by_anchor( $block['innerBlocks'], $anchor, $current_path );
				if ( null !== $found ) {
					return $found;
				}
			}
		}

		return null;
	}

	/**
	 * Find the closest section-like block containing a heading.
	 *
	 * @param array  $blocks  Blocks.
	 * @param string $heading Heading text.
	 * @param array  $path    Current path.
	 * @return array|null Path.
	 */
	private function find_section_path_by_heading( $blocks, $heading, $path = array() ) {
		$needle = strtolower( trim( wp_strip_all_tags( $heading ) ) );

		foreach ( $blocks as $index => $block ) {
			$current_path = array_merge( $path, array( $index ) );
			$block_name   = $block['blockName'] ?? '';
			$text         = strtolower( trim( wp_strip_all_tags( $block['innerHTML'] ?? '' ) ) );

			if ( 'core/heading' === $block_name && false !== strpos( $text, $needle ) ) {
				return empty( $path ) ? $current_path : $path;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$found = $this->find_section_path_by_heading( $block['innerBlocks'], $heading, $current_path );
				if ( null !== $found ) {
					return $found;
				}
			}
		}

		return null;
	}

	/**
	 * Replace one block path with replacement blocks.
	 *
	 * @param array $blocks       Blocks.
	 * @param array $path         Path.
	 * @param array $replacements Replacement blocks.
	 * @return bool Replaced.
	 */
	private function replace_blocks_at_path( &$blocks, $path, $replacements ) {
		$index = array_shift( $path );

		if ( ! isset( $blocks[ $index ] ) ) {
			return false;
		}

		if ( empty( $path ) ) {
			array_splice( $blocks, $index, 1, $replacements );
			return true;
		}

		if ( empty( $blocks[ $index ]['innerBlocks'] ) || ! is_array( $blocks[ $index ]['innerBlocks'] ) ) {
			return false;
		}

		return $this->replace_blocks_at_path( $blocks[ $index ]['innerBlocks'], $path, $replacements );
	}

	/**
	 * Clean parsed blocks for API output.
	 *
	 * Removes empty blocks and normalizes the structure.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return array Cleaned blocks.
	 */
	private function clean_blocks( $blocks ) {
		$cleaned = array();

		foreach ( $blocks as $block ) {
			// Skip empty/whitespace-only blocks (parser artifacts).
			if ( empty( $block['blockName'] ) && empty( trim( $block['innerHTML'] ?? '' ) ) ) {
				continue;
			}

			$item = array(
				'blockName' => $block['blockName'] ?? null,
				'attrs'     => ! empty( $block['attrs'] ) ? $block['attrs'] : (object) array(),
			);

			if ( ! empty( $block['innerHTML'] ) ) {
				$item['innerHTML'] = $block['innerHTML'];
			}

			// Recursively clean inner blocks.
			if ( ! empty( $block['innerBlocks'] ) ) {
				$item['innerBlocks'] = $this->clean_blocks( $block['innerBlocks'] );
			}

			if ( ! empty( $block['innerContent'] ) ) {
				$item['innerContent'] = $block['innerContent'];
			}

			$cleaned[] = $item;
		}

		return $cleaned;
	}
}
