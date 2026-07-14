<?php
/**
 * Base REST controller.
 *
 * @package mumcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provide shared authorization, validation, and response helpers for REST controllers.
 */
abstract class Mcpwp_REST_API {
	use Mcpwp_Api_Auth;
	use Mcpwp_Logging;
	use Mcpwp_Sanitization;

	/**
	 * REST namespace for all retained routes.
	 *
	 * @var string
	 */
	protected $namespace = 'mcpwp/v1';

	/**
	 * Authorize a request based on its retained REST route and method.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error True when authorized, otherwise an authorization error.
	 */
	public function check_permission( $request ) {
		$route  = (string) $request->get_route();
		$method = strtoupper( (string) $request->get_method() );
		if ( false !== strpos( $route, '/site-info' ) ) {
			return $this->check_site_info_permission( $request ); }
		if ( false !== strpos( $route, '/mcp' ) ) {
			return $this->check_mcp_permission( $request ); }
		if ( false !== strpos( $route, '/media' ) ) {
			return 'GET' === $method ? $this->authorize_request( $request, 'read', 'read', 'media' ) : $this->check_upload_media_permission( $request ); }
		if ( false !== strpos( $route, '/categories' ) || false !== strpos( $route, '/tags' ) ) {
			return $this->check_taxonomy_read_permission( $request ); }
		if ( false !== strpos( $route, '/terms' ) ) {
			return $this->check_create_term_permission( $request ); }
		if ( false !== strpos( $route, '/plugin-settings' ) || false !== strpos( $route, '/rate-limit' ) || false !== strpos( $route, '/api-keys' ) ) {
			return $this->check_admin_permission( $request ); }
		return $this->authorize_request( $request, in_array( $method, array( 'GET', 'HEAD', 'OPTIONS' ), true ) ? 'read' : 'write', 'read', 'content' );
	}

	/**
	 * Authenticate a request and enforce its category and WordPress capability.
	 *
	 * @param WP_REST_Request $request    REST request.
	 * @param string          $scope      Required API-key scope.
	 * @param string          $capability Required WordPress capability.
	 * @param string          $category   Required tool category.
	 * @param int|null        $object_id  Optional object ID for a meta capability.
	 * @return bool|WP_Error True when authorized, otherwise an authorization error.
	 */
	protected function authorize_request( $request, $scope, $capability, $category, $object_id = null ) {
		$authenticated = $this->verify_api_key( $request, $scope );
		if ( true !== $authenticated ) {
			return $authenticated; }
		$category = sanitize_key( $category );
		$disabled = get_option( 'mcpwp_disabled_tool_categories', array() );
		if ( is_array( $disabled ) && in_array( $category, $disabled, true ) ) {
			return new WP_Error( 'tool_category_disabled', __( 'This tool category is disabled by the site administrator.', 'mumega-mcp' ), array( 'status' => 403 ) );
		}
		$record = $this->get_current_api_key_record();
		if ( ! $this->key_allows_category( $record, $category ) ) {
			return new WP_Error( 'tool_category_forbidden', __( 'This API key does not grant the requested tool category.', 'mumega-mcp' ), array( 'status' => 403 ) );
		}
		$allowed = null === $object_id ? current_user_can( $capability ) : current_user_can( $capability, (int) $object_id );
		if ( ! $allowed ) {
			return new WP_Error(
				'insufficient_capability',
				__( 'The bound WordPress user lacks the required capability.', 'mumega-mcp' ),
				array(
					'status'              => 403,
					'required_capability' => $capability,
				)
			);
		}
		return true;
	}

	/**
	 * Authorize access to the MCP endpoint without charging its batch rate limit.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error True when authorized, otherwise an authorization error.
	 */
	public function check_mcp_permission( $request ) {
		$result = $this->verify_api_key( $request, 'read', false );
		if ( true !== $result ) {
			return $result; }
		return current_user_can( 'read' ) ? true : new WP_Error(
			'insufficient_capability',
			__( 'The bound WordPress user lacks the read capability.', 'mumega-mcp' ),
			array(
				'status'              => 403,
				'required_capability' => 'read',
			)
		);
	}
	/**
	 * Authorize a site-information request.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error True when authorized, otherwise an authorization error.
	 */
	public function check_site_info_permission( $request ) {
		return $this->authorize_request( $request, 'read', 'read', 'site' ); }
	/**
	 * Authorize a post-list request, including non-public visibility checks.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error True when authorized, otherwise an authorization error.
	 */
	public function check_list_posts_permission( $request ) {
		return $this->check_content_list_permission( $request, 'post' ); }
	/**
	 * Authorize a post-create request and any requested term assignments.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error True when authorized, otherwise an authorization error.
	 */
	public function check_create_post_permission( $request ) {
		$result = $this->check_content_create_permission( $request, 'edit_posts', 'publish_posts' );
		return true === $result ? $this->check_post_term_assignment_permission( $request ) : $result;
	}
	/**
	 * Authorize access to a requested post.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error True when authorized, otherwise an authorization error.
	 */
	public function check_get_post_permission( $request ) {
		return $this->check_typed_resource_permission( $request, 'post', 'read', 'read_post', 'content' ); }
	/**
	 * Authorize a post update and any requested term assignments.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error True when authorized, otherwise an authorization error.
	 */
	public function check_update_post_permission( $request ) {
		$result = $this->check_content_update_permission( $request, 'post', 'publish_posts' );
		return true === $result ? $this->check_post_term_assignment_permission( $request ) : $result;
	}
	/**
	 * Authorize a page-list request, including non-public visibility checks.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error True when authorized, otherwise an authorization error.
	 */
	public function check_list_pages_permission( $request ) {
		return $this->check_content_list_permission( $request, 'page' ); }
	/**
	 * Authorize a page-create request.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error True when authorized, otherwise an authorization error.
	 */
	public function check_create_page_permission( $request ) {
		return $this->check_content_create_permission( $request, 'edit_pages', 'publish_pages' ); }
	/**
	 * Authorize access to a requested page.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error True when authorized, otherwise an authorization error.
	 */
	public function check_get_page_permission( $request ) {
		return $this->check_typed_resource_permission( $request, 'page', 'read', 'read_post', 'content' ); }
	/**
	 * Authorize a page-update request.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error True when authorized, otherwise an authorization error.
	 */
	public function check_update_page_permission( $request ) {
		return $this->check_content_update_permission( $request, 'page', 'publish_pages' ); }
	/**
	 * Authorize a draft-list request for its requested content type.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error True when authorized, otherwise an authorization error.
	 */
	public function check_list_drafts_permission( $request ) {
		$result = $this->authorize_request( $request, 'read', 'read', 'content' );
		if ( true !== $result ) {
			return $result; }
		$type    = $request->get_param( 'type' );
		$allowed = 'post' === $type ? current_user_can( 'edit_posts' ) : ( 'page' === $type ? current_user_can( 'edit_pages' ) : ( current_user_can( 'edit_posts' ) || current_user_can( 'edit_pages' ) ) );
		return $allowed ? true : new WP_Error( 'insufficient_capability', __( 'The bound WordPress user cannot read drafts.', 'mumega-mcp' ), array( 'status' => 403 ) );
	}
	/**
	 * Authorize deletion of a requested post or page draft.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error True when authorized, otherwise an authorization error.
	 */
	public function check_delete_draft_permission( $request ) {
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );
		if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) || 'draft' !== $post->post_status ) {
			return new WP_Error( 'not_a_draft', __( 'The requested post or page is not a draft.', 'mumega-mcp' ), array( 'status' => 404 ) ); }
		return $this->authorize_request( $request, 'admin', 'delete_post', 'content', $id );
	}
	/**
	 * Authorize a media-list request.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error True when authorized, otherwise an authorization error.
	 */
	public function check_list_media_permission( $request ) {
		return $this->authorize_request( $request, 'read', 'read', 'media' ); }
	/**
	 * Authorize access to a requested media attachment.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error True when authorized, otherwise an authorization error.
	 */
	public function check_get_media_permission( $request ) {
		return $this->check_typed_resource_permission( $request, 'attachment', 'read', 'read_post', 'media' ); }
	/**
	 * Authorize a media-upload request.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error True when authorized, otherwise an authorization error.
	 */
	public function check_upload_media_permission( $request ) {
		return $this->authorize_request( $request, 'write', 'upload_files', 'media' ); }
	/**
	 * Authorize a taxonomy-read request.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error True when authorized, otherwise an authorization error.
	 */
	public function check_taxonomy_read_permission( $request ) {
		return $this->authorize_request( $request, 'read', 'read', 'taxonomy' ); }
	/**
	 * Authorize term creation for the requested taxonomy.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error True when authorized, otherwise an authorization error.
	 */
	public function check_create_term_permission( $request ) {
		$params        = $this->raw_json_params( $request );
		$taxonomy_name = is_array( $params ) && isset( $params['taxonomy'] ) && is_string( $params['taxonomy'] ) ? $params['taxonomy'] : '';
		$taxonomy      = get_taxonomy( $taxonomy_name );
		$capability    = $taxonomy && isset( $taxonomy->cap->manage_terms ) ? $taxonomy->cap->manage_terms : 'manage_categories';
		return $this->authorize_request( $request, 'write', $capability, 'taxonomy' );
	}
	/**
	 * Authorize access to retained administrative settings routes.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error True when authorized, otherwise an authorization error.
	 */
	public function check_admin_permission( $request ) {
		return $this->authorize_request( $request, 'admin', 'mcpwp_manage_settings', 'site' ); }

	/**
	 * Authorize content creation and enforce publishing capability when needed.
	 *
	 * @param WP_REST_Request $request            REST request.
	 * @param string          $edit_capability    Capability required to create the content.
	 * @param string          $publish_capability Capability required to publish the content.
	 * @return bool|WP_Error True when authorized, otherwise an authorization error.
	 */
	private function check_content_create_permission( $request, $edit_capability, $publish_capability ) {
		$params     = (array) $this->raw_json_params( $request );
		$publishing = isset( $params['status'] ) && in_array( $params['status'], array( 'publish', 'private' ), true );
		$result     = $this->authorize_request( $request, $publishing ? 'admin' : 'write', $edit_capability, 'content' );
		if ( true !== $result || ! $publishing ) {
			return $result; }
		return current_user_can( $publish_capability ) ? true : new WP_Error( 'insufficient_capability', __( 'The bound WordPress user cannot publish this content type.', 'mumega-mcp' ), array( 'status' => 403 ) );
	}

	/**
	 * Authorize a content list and enforce non-public visibility capabilities.
	 *
	 * @param WP_REST_Request $request   REST request.
	 * @param string          $post_type Post type being listed.
	 * @return bool|WP_Error True when authorized, otherwise an authorization error.
	 */
	private function check_content_list_permission( $request, $post_type ) {
		$result = $this->authorize_request( $request, 'read', 'read', 'content' );
		if ( true !== $result ) {
			return $result; }
		$status = (string) $request->get_param( 'post_status' );
		if ( '' === $status || 'publish' === $status ) {
			return true; }
		$edit_capability = 'page' === $post_type ? 'edit_pages' : 'edit_posts';
		if ( ! current_user_can( $edit_capability ) ) {
			return new WP_Error(
				'insufficient_capability',
				__( 'The bound WordPress user cannot list non-public content.', 'mumega-mcp' ),
				array(
					'status'              => 403,
					'required_capability' => $edit_capability,
				)
			); }
		$private_capability = 'page' === $post_type ? 'read_private_pages' : 'read_private_posts';
		if ( 'private' === $status && ! current_user_can( $private_capability ) ) {
			return new WP_Error(
				'insufficient_capability',
				__( 'The bound WordPress user cannot read private content.', 'mumega-mcp' ),
				array(
					'status'              => 403,
					'required_capability' => $private_capability,
				)
			); }
		return true;
	}

	/**
	 * Verify the current user can assign every requested post taxonomy.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error True when assignments are allowed, otherwise an authorization error.
	 */
	private function check_post_term_assignment_permission( $request ) {
		$params = $this->raw_json_params( $request );
		if ( ! is_array( $params ) ) {
			return true; }
		foreach ( array(
			'categories' => 'category',
			'tags'       => 'post_tag',
		) as $field => $taxonomy_name ) {
			if ( ! array_key_exists( $field, $params ) ) {
				continue; }
			$taxonomy   = get_taxonomy( $taxonomy_name );
			$capability = $taxonomy && isset( $taxonomy->cap->assign_terms ) ? $taxonomy->cap->assign_terms : 'edit_posts';
			if ( ! current_user_can( $capability ) ) {
				return new WP_Error(
					'insufficient_capability',
					__( 'The bound WordPress user cannot assign the requested terms.', 'mumega-mcp' ),
					array(
						'status'              => 403,
						'required_capability' => $capability,
					)
				); }
		}
		return true;
	}

	/**
	 * Authorize an update and enforce publishing capability when needed.
	 *
	 * @param WP_REST_Request $request            REST request.
	 * @param string          $post_type          Expected post type.
	 * @param string          $publish_capability Capability required to publish the content.
	 * @return bool|WP_Error True when authorized, otherwise an authorization error.
	 */
	private function check_content_update_permission( $request, $post_type, $publish_capability ) {
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );
		if ( ! $post || $post_type !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'The requested content was not found.', 'mumega-mcp' ), array( 'status' => 404 ) ); }
		$params     = (array) $this->raw_json_params( $request );
		$publishing = isset( $params['status'] ) && in_array( $params['status'], array( 'publish', 'private' ), true );
		$admin      = $publishing || in_array( $post->post_status, array( 'publish', 'private' ), true );
		$result     = $this->authorize_request( $request, $admin ? 'admin' : 'write', 'edit_post', 'content', $id );
		if ( true !== $result || ! $publishing ) {
			return $result; }
		return current_user_can( $publish_capability ) ? true : new WP_Error( 'insufficient_capability', __( 'The bound WordPress user cannot publish this content type.', 'mumega-mcp' ), array( 'status' => 403 ) );
	}

	/**
	 * Build a WordPress REST route definition with retained policy metadata.
	 *
	 * @param string|string[] $methods      Allowed HTTP methods.
	 * @param string          $callback     Controller callback method name.
	 * @param string          $permission   Permission callback method name.
	 * @param string          $category     Retained tool category.
	 * @param string          $scope        Required API-key scope.
	 * @param string[]        $capabilities Required WordPress capabilities.
	 * @param bool            $mutable      Whether the route can mutate state.
	 * @param array           $args         Optional REST argument schema.
	 * @return array<string,mixed> REST route definition.
	 */
	protected function route_definition( $methods, $callback, $permission, $category, $scope, $capabilities, $mutable, $args = array() ) {
		return array(
			'methods'             => $methods,
			'callback'            => array( $this, $callback ),
			'permission_callback' => array( $this, $permission ),
			'mcpwp_category'      => $category,
			'mcpwp_scope'         => $scope,
			'mcpwp_capabilities'  => $capabilities,
			'mcpwp_mutable'       => $mutable,
			'args'                => $args,
		);
	}

	/**
	 * Build a successful REST response with current rate-limit headers.
	 *
	 * @param mixed $data   Response payload.
	 * @param int   $status HTTP status code.
	 * @return WP_REST_Response REST response.
	 */
	protected function success_response( $data, $status = 200 ) {
		return $this->add_rate_limit_headers( new WP_REST_Response( $data, $status ) );
	}

	/**
	 * Build an error REST response with context and rate-limit headers.
	 *
	 * @param string $code    Stable error code.
	 * @param string $message Human-readable error message.
	 * @param int    $status  HTTP status code.
	 * @param array  $context Additional error data.
	 * @return WP_REST_Response REST response.
	 */
	protected function error_response( $code, $message, $status = 400, $context = array() ) {
		return $this->add_rate_limit_headers(
			new WP_REST_Response(
				array(
					'code'    => $code,
					'message' => $message,
					'data'    => array_merge( array( 'status' => $status ), $context ),
				),
				$status
			)
		);
	}

	/**
	 * Decode and strictly validate a JSON object request body.
	 *
	 * @param WP_REST_Request $request       REST request.
	 * @param array           $schema        Strict object schema.
	 * @param bool            $require_field Whether at least one schema field is required.
	 * @param int             $max_bytes     Maximum accepted body size.
	 * @return array|WP_Error Validated body data or a validation error.
	 */
	protected function validated_json_body( $request, $schema, $require_field = false, $max_bytes = 1048576 ) {
		$body = method_exists( $request, 'get_body' ) ? (string) $request->get_body() : '';
		if ( strlen( $body ) > $max_bytes ) {
			return new WP_Error( 'payload_too_large', __( 'The JSON request body is too large.', 'mumega-mcp' ), array( 'status' => 413 ) );
		}
		$object = json_decode( $body );
		$data   = json_decode( $body, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_object( $object ) || ! is_array( $data ) ) {
			return new WP_Error( 'invalid_json_body', __( 'A JSON object body is required.', 'mumega-mcp' ), array( 'status' => 400 ) );
		}
		return $this->validate_strict_object( $data, $schema, $require_field );
	}

	/**
	 * Decode a JSON object request body without applying its route schema.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return array|null Decoded object data, or null for invalid input.
	 */
	protected function raw_json_params( $request ) {
		$body   = method_exists( $request, 'get_body' ) ? (string) $request->get_body() : '';
		$object = json_decode( $body );
		$data   = json_decode( $body, true );
		return JSON_ERROR_NONE === json_last_error() && is_object( $object ) && is_array( $data ) ? $data : null;
	}

	/**
	 * Authorize access to an existing resource of the expected post type.
	 *
	 * @param WP_REST_Request $request    REST request.
	 * @param string          $post_type  Expected post type.
	 * @param string          $scope      Required API-key scope.
	 * @param string          $capability Required WordPress capability.
	 * @param string          $category   Required tool category.
	 * @return bool|WP_Error True when authorized, otherwise a not-found or authorization error.
	 */
	private function check_typed_resource_permission( $request, $post_type, $scope, $capability, $category ) {
		$id       = (int) $request->get_param( 'id' );
		$post     = get_post( $id );
		$messages = array(
			'post'       => __( 'Post not found.', 'mumega-mcp' ),
			'page'       => __( 'Page not found.', 'mumega-mcp' ),
			'attachment' => __( 'Media attachment not found.', 'mumega-mcp' ),
		);
		if ( ! $post || $post_type !== $post->post_type ) {
			return new WP_Error( 'not_found', $messages[ $post_type ], array( 'status' => 404 ) ); }
		return $this->authorize_request( $request, $scope, $capability, $category, $id );
	}

	/**
	 * Normalize and strictly validate REST query parameters.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @param array           $schema  Strict query schema.
	 * @return array|WP_Error Validated query data or a validation error.
	 */
	protected function validated_query_params( $request, $schema ) {
		$params = $request->get_params();
		if ( ! is_array( $params ) ) {
			$params = array(); }
		$unknown = array_diff( array_keys( $params ), array_keys( $schema ) );
		if ( ! empty( $unknown ) ) {
			return new WP_Error(
				'unknown_parameter',
				__( 'The request contains an unknown parameter.', 'mumega-mcp' ),
				array(
					'status'    => 400,
					'parameter' => (string) reset( $unknown ),
				)
			); }
		foreach ( $params as $name => &$value ) {
			$type = isset( $schema[ $name ]['type'] ) ? $schema[ $name ]['type'] : '';
			if ( 'integer' === $type && is_string( $value ) && preg_match( '/^(0|[1-9][0-9]*)$/', $value ) ) {
				$value = (int) $value; }
			if ( 'boolean' === $type && is_string( $value ) && in_array( $value, array( 'true', 'false', '1', '0' ), true ) ) {
				$value = in_array( $value, array( 'true', '1' ), true ); }
		}
		unset( $value );
		return $this->validate_strict_object( $params, $schema );
	}

	/**
	 * Strictly validate form-encoded request parameters.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @param array           $schema  Strict form schema.
	 * @return array|WP_Error Validated form data or a validation error.
	 */
	protected function validated_form_params( $request, $schema ) {
		$params = method_exists( $request, 'get_body_params' ) ? $request->get_body_params() : array();
		return $this->validate_strict_object( is_array( $params ) ? $params : array(), $schema );
	}

	/**
	 * Convert a validation error into the retained REST error envelope.
	 *
	 * @param WP_Error $error Validation error.
	 * @return WP_REST_Response REST error response.
	 */
	protected function validation_error_response( $error ) {
		$data    = $error->get_error_data();
		$status  = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
		$context = is_array( $data ) ? $data : array();
		unset( $context['status'] );
		return $this->error_response( $error->get_error_code(), $error->get_error_message(), $status, $context );
	}

	/**
	 * Validate requested terms, featured media, and page-parent relationships.
	 *
	 * @param array $data       Validated content data.
	 * @param int   $current_id Current content ID during updates.
	 * @param bool  $page       Whether to validate page parenting.
	 * @return true|WP_Error True when relationships are valid, otherwise a validation error.
	 */
	protected function validate_content_relationships( $data, $current_id = 0, $page = false ) {
		if ( isset( $data['categories'] ) ) {
			foreach ( $data['categories'] as $category_id ) {
				if ( ! term_exists( $category_id, 'category' ) ) {
					return new WP_Error(
						'invalid_category',
						__( 'A category does not exist in the category taxonomy.', 'mumega-mcp' ),
						array(
							'status'      => 400,
							'category_id' => $category_id,
						)
					); }
			}
		}
		if ( isset( $data['featured_image'] ) && (int) $data['featured_image'] > 0 ) {
			$attachment = get_post( $data['featured_image'] );
			$mime       = $attachment && isset( $attachment->post_mime_type ) ? (string) $attachment->post_mime_type : '';
			if ( ! $attachment || 'attachment' !== $attachment->post_type || 0 !== strpos( $mime, 'image/' ) || 'image/svg+xml' === $mime || ! current_user_can( 'read_post', $attachment->ID ) ) {
				return new WP_Error( 'invalid_featured_image', __( 'The featured image must be a readable non-SVG image attachment.', 'mumega-mcp' ), array( 'status' => 400 ) );
			}
		}
		if ( $page && isset( $data['parent'] ) && (int) $data['parent'] > 0 ) {
			$parent_id = (int) $data['parent'];
			$seen      = array();
			while ( $parent_id > 0 ) {
				if ( $parent_id === (int) $current_id || isset( $seen[ $parent_id ] ) ) {
					return new WP_Error( 'invalid_parent', __( 'The requested page parent creates a cycle.', 'mumega-mcp' ), array( 'status' => 400 ) ); }
				$seen[ $parent_id ] = true;
				$parent             = get_post( $parent_id );
				if ( ! $parent || 'page' !== $parent->post_type || ! current_user_can( 'read_post', $parent_id ) ) {
					return new WP_Error( 'invalid_parent', __( 'The page parent must be another readable page.', 'mumega-mcp' ), array( 'status' => 400 ) ); }
				$parent_id = (int) $parent->post_parent;
			}
		}
		return true;
	}

	/**
	 * Attach the current rate-limit headers to a REST response.
	 *
	 * @param WP_REST_Response $response REST response.
	 * @return WP_REST_Response REST response with rate-limit headers.
	 */
	protected function add_rate_limit_headers( $response ) {
		if ( class_exists( 'Mcpwp_Rate_Limiter' ) ) {
			foreach ( Mcpwp_Rate_Limiter::get_instance()->get_headers() as $name => $value ) {
				$response->header( $name, $value );
			}
		}
		return $response;
	}

	/**
	 * Return default pagination values used by retained services.
	 *
	 * @return array<string,int> Default page and page-size values.
	 */
	protected function get_pagination_args() {
		return array(
			'page'     => 1,
			'per_page' => 20,
		);
	}

	/**
	 * Return the retained base post-query arguments.
	 *
	 * @return array Empty base argument set.
	 */
	protected function get_post_args() {
		return array();
	}

	/**
	 * Return the schema for a positive integer route ID.
	 *
	 * @return array<string,array<string,mixed>> Route argument schema.
	 */
	protected function id_route_args() {
		return array( 'id' => $this->integer_arg( 1, 2147483647, array( 'required' => true ) ) ); }
	/**
	 * Return list-query arguments for posts or pages.
	 *
	 * @param bool $pages Whether to include page-specific order fields.
	 * @return array<string,array<string,mixed>> Query argument schema.
	 */
	protected function content_list_args( $pages = false ) {
		return array(
			'posts_per_page' => $this->integer_arg( 1, 100, array( 'default' => 20 ) ),
			'paged'          => $this->integer_arg( 1, 10000, array( 'default' => 1 ) ),
			'post_status'    => $this->string_arg(
				array(
					'enum'    => array( 'publish', 'draft', 'pending', 'private' ),
					'default' => 'publish',
				)
			),
			'order'          => $this->string_arg( array( 'enum' => array( 'asc', 'desc' ) ) ),
			'orderby'        => $this->string_arg( array( 'enum' => $pages ? array( 'date', 'modified', 'title', 'id', 'menu_order' ) : array( 'date', 'modified', 'title', 'id' ) ) ),
		);
	}
	/**
	 * Return request-body arguments for creating or updating posts.
	 *
	 * @param bool $update Whether the schema is for an update route.
	 * @return array<string,array<string,mixed>> Request argument schema.
	 */
	protected function post_body_args( $update = false ) {
		$args = array(
			'title'          => $this->string_arg(
				array(
					'minLength' => 1,
					'maxLength' => 200,
					'required'  => ! $update,
				)
			),
			'content'        => $this->string_arg( array( 'maxLength' => 1000000 ) ),
			'status'         => $this->string_arg( array( 'enum' => array( 'draft', 'pending', 'publish', 'private' ) ) ),
			'excerpt'        => $this->string_arg( array( 'maxLength' => 10000 ) ),
			'slug'           => $this->string_arg(
				array(
					'minLength' => 1,
					'maxLength' => 200,
				)
			),
			'categories'     => $this->id_array_arg(),
			'tags'           => array(
				'type'        => 'array',
				'maxItems'    => 100,
				'uniqueItems' => true,
				'items'       => $this->string_arg(
					array(
						'minLength' => 1,
						'maxLength' => 200,
					)
				),
			),
			'featured_image' => $this->integer_arg( 0, 2147483647 ),
		);
		return $update ? array_merge( $this->id_route_args(), $args ) : $args;
	}
	/**
	 * Return request-body arguments for creating or updating pages.
	 *
	 * @param bool $update Whether the schema is for an update route.
	 * @return array<string,array<string,mixed>> Request argument schema.
	 */
	protected function page_body_args( $update = false ) {
		$args = array(
			'title'          => $this->string_arg(
				array(
					'minLength' => 1,
					'maxLength' => 200,
					'required'  => ! $update,
				)
			),
			'content'        => $this->string_arg( array( 'maxLength' => 1000000 ) ),
			'status'         => $this->string_arg( array( 'enum' => array( 'draft', 'pending', 'publish', 'private' ) ) ),
			'slug'           => $this->string_arg(
				array(
					'minLength' => 1,
					'maxLength' => 200,
				)
			),
			'parent'         => $this->integer_arg( 0, 2147483647 ),
			'menu_order'     => $this->integer_arg( 0, 100000 ),
			'template'       => $this->string_arg(
				array(
					'minLength' => 1,
					'maxLength' => 200,
				)
			),
			'featured_image' => $this->integer_arg( 0, 2147483647 ),
		);
		return $update ? array_merge( $this->id_route_args(), $args ) : $args;
	}
	/**
	 * Return query arguments for listing retained drafts.
	 *
	 * @return array<string,array<string,mixed>> Query argument schema.
	 */
	protected function draft_list_args() {
		return array(
			'type'           => $this->string_arg(
				array(
					'enum'    => array( 'all', 'post', 'page' ),
					'default' => 'all',
				)
			),
			'posts_per_page' => $this->integer_arg( 1, 100, array( 'default' => 20 ) ),
			'paged'          => $this->integer_arg( 1, 10000, array( 'default' => 1 ) ),
			'orderby'        => $this->string_arg( array( 'enum' => array( 'modified', 'date', 'title', 'id' ) ) ),
			'order'          => $this->string_arg(
				array(
					'enum'    => array( 'asc', 'desc' ),
					'default' => 'desc',
				)
			),
		);
	}
	/**
	 * Return query arguments for listing media attachments.
	 *
	 * @return array<string,array<string,mixed>> Query argument schema.
	 */
	protected function media_list_args() {
		return array(
			'posts_per_page' => $this->integer_arg( 1, 100, array( 'default' => 20 ) ),
			'paged'          => $this->integer_arg( 1, 10000, array( 'default' => 1 ) ),
			'mime_type'      => $this->string_arg( array( 'maxLength' => 100 ) ),
			'search'         => $this->string_arg( array( 'maxLength' => 200 ) ),
			'orderby'        => $this->string_arg( array( 'enum' => array( 'date', 'modified', 'title', 'id' ) ) ),
			'order'          => $this->string_arg( array( 'enum' => array( 'asc', 'desc' ) ) ),
		);
	}
	/**
	 * Return request arguments for editable media text fields.
	 *
	 * @return array<string,array<string,mixed>> Media text argument schema.
	 */
	protected function media_text_args() {
		return array(
			'title'   => $this->string_arg(
				array(
					'minLength' => 1,
					'maxLength' => 200,
				)
			),
			'alt'     => $this->string_arg( array( 'maxLength' => 250 ) ),
			'caption' => $this->string_arg( array( 'maxLength' => 10000 ) ),
		); }
	/**
	 * Return form arguments accepted by multipart media uploads.
	 *
	 * @return array<string,array<string,mixed>> Multipart upload argument schema.
	 */
	protected function multipart_media_args() {
		return $this->media_text_args(); }
	/**
	 * Return request arguments accepted by base64 media uploads.
	 *
	 * @return array<string,array<string,mixed>> Base64 upload argument schema.
	 */
	protected function base64_media_args() {
		return array_merge(
			array(
				'data'      => $this->string_arg(
					array(
						'minLength' => 1,
						'required'  => true,
					)
				),
				'filename'  => $this->string_arg(
					array(
						'minLength' => 1,
						'maxLength' => 255,
						'required'  => true,
					)
				),
				'mime_type' => $this->string_arg( array( 'maxLength' => 100 ) ),
			),
			$this->media_text_args()
		); }
	/**
	 * Return query arguments for listing taxonomy terms.
	 *
	 * @return array<string,array<string,mixed>> Term-list argument schema.
	 */
	protected function term_list_args() {
		return array(
			'per_page'   => $this->integer_arg( 1, 100, array( 'default' => 50 ) ),
			'page'       => $this->integer_arg( 1, 10000, array( 'default' => 1 ) ),
			'search'     => $this->string_arg( array( 'maxLength' => 200 ) ),
			'hide_empty' => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'orderby'    => $this->string_arg( array( 'enum' => array( 'name', 'slug', 'count', 'id' ) ) ),
			'order'      => $this->string_arg( array( 'enum' => array( 'asc', 'desc' ) ) ),
		);
	}
	/**
	 * Return request arguments for creating taxonomy terms.
	 *
	 * @return array<string,array<string,mixed>> Term-create argument schema.
	 */
	protected function term_create_args() {
		return array(
			'taxonomy'    => $this->string_arg(
				array(
					'enum'     => array( 'category', 'post_tag' ),
					'required' => true,
				)
			),
			'name'        => $this->string_arg(
				array(
					'minLength' => 1,
					'maxLength' => 200,
					'required'  => true,
				)
			),
			'slug'        => $this->string_arg(
				array(
					'minLength' => 1,
					'maxLength' => 200,
				)
			),
			'description' => $this->string_arg( array( 'maxLength' => 10000 ) ),
		); }
	/**
	 * Return request arguments for retained plugin settings.
	 *
	 * @return array<string,array<string,mixed>> Plugin-setting argument schema.
	 */
	protected function plugin_settings_args() {
		return array(
			'enable_logging'     => array( 'type' => 'boolean' ),
			'log_retention_days' => $this->integer_arg( 1, 365 ),
		); }
	/**
	 * Return request arguments for rate-limit settings.
	 *
	 * @return array<string,array<string,mixed>> Rate-setting argument schema.
	 */
	protected function rate_settings_args() {
		return array(
			'enabled'             => array( 'type' => 'boolean' ),
			'requests_per_minute' => $this->integer_arg( 1, 100000 ),
			'requests_per_hour'   => $this->integer_arg( 1, 100000 ),
			'burst_limit'         => $this->integer_arg( 1, 100000 ),
			'whitelist'           => array(
				'type'        => 'array',
				'maxItems'    => 100,
				'uniqueItems' => true,
				'items'       => $this->string_arg(
					array(
						'minLength' => 1,
						'maxLength' => 100,
					)
				),
			),
		); }
	/**
	 * Return request arguments for scoped API-key creation.
	 *
	 * @return array<string,array<string,mixed>> API-key creation argument schema.
	 */
	protected function api_key_create_args() {
		return array(
			'label'           => $this->string_arg(
				array(
					'minLength' => 1,
					'maxLength' => 100,
					'required'  => true,
				)
			),
			'scopes'          => array(
				'type'        => 'array',
				'minItems'    => 1,
				'maxItems'    => 3,
				'uniqueItems' => true,
				'required'    => true,
				'items'       => $this->string_arg( array( 'enum' => array( 'read', 'write', 'admin' ) ) ),
			),
			'tool_categories' => array(
				'type'        => 'array',
				'maxItems'    => 4,
				'uniqueItems' => true,
				'items'       => $this->string_arg( array( 'enum' => array( 'site', 'content', 'media', 'taxonomy' ) ) ),
			),
		); }
	/**
	 * Build an integer schema with inclusive bounds and optional constraints.
	 *
	 * @param int   $minimum Minimum accepted value.
	 * @param int   $maximum Maximum accepted value.
	 * @param array $extra   Additional schema constraints.
	 * @return array<string,mixed> Integer argument schema.
	 */
	protected function integer_arg( $minimum, $maximum, $extra = array() ) {
		return array_merge(
			array(
				'type'    => 'integer',
				'minimum' => $minimum,
				'maximum' => $maximum,
			),
			$extra
		); }
	/**
	 * Build a string schema with optional constraints.
	 *
	 * @param array $extra Additional schema constraints.
	 * @return array<string,mixed> String argument schema.
	 */
	protected function string_arg( $extra = array() ) {
		return array_merge( array( 'type' => 'string' ), $extra ); }
	/**
	 * Build a schema for a unique array of positive object IDs.
	 *
	 * @return array<string,mixed> Object-ID array schema.
	 */
	protected function id_array_arg() {
		return array(
			'type'        => 'array',
			'maxItems'    => 100,
			'uniqueItems' => true,
			'items'       => $this->integer_arg( 1, 2147483647 ),
		); }
}
