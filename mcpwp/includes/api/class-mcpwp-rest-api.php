<?php
/**
 * Base REST controller.
 *
 * @package mumcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Mcpwp_REST_API {
	use Mcpwp_Api_Auth;
	use Mcpwp_Logging;
	use Mcpwp_Sanitization;

	protected $namespace = 'mcpwp/v1';

	public function check_permission( $request ) {
		$route = (string) $request->get_route();
		$method = strtoupper( (string) $request->get_method() );
		if ( false !== strpos( $route, '/site-info' ) ) { return $this->check_site_info_permission( $request ); }
		if ( false !== strpos( $route, '/mcp' ) ) { return $this->check_mcp_permission( $request ); }
		if ( false !== strpos( $route, '/media' ) ) { return 'GET' === $method ? $this->authorize_request( $request, 'read', 'read', 'media' ) : $this->check_upload_media_permission( $request ); }
		if ( false !== strpos( $route, '/categories' ) || false !== strpos( $route, '/tags' ) ) { return $this->check_taxonomy_read_permission( $request ); }
		if ( false !== strpos( $route, '/terms' ) ) { return $this->check_create_term_permission( $request ); }
		if ( false !== strpos( $route, '/plugin-settings' ) || false !== strpos( $route, '/rate-limit' ) || false !== strpos( $route, '/api-keys' ) ) { return $this->check_admin_permission( $request ); }
		return $this->authorize_request( $request, in_array( $method, array( 'GET', 'HEAD', 'OPTIONS' ), true ) ? 'read' : 'write', 'read', 'content' );
	}

	protected function authorize_request( $request, $scope, $capability, $category, $object_id = null ) {
		$authenticated = $this->verify_api_key( $request, $scope );
		if ( true !== $authenticated ) { return $authenticated; }
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
			return new WP_Error( 'insufficient_capability', __( 'The bound WordPress user lacks the required capability.', 'mumega-mcp' ), array( 'status' => 403, 'required_capability' => $capability ) );
		}
		return true;
	}

	public function check_mcp_permission( $request ) {
		$result = $this->verify_api_key( $request, 'read', false );
		if ( true !== $result ) { return $result; }
		return current_user_can( 'read' ) ? true : new WP_Error( 'insufficient_capability', __( 'The bound WordPress user lacks the read capability.', 'mumega-mcp' ), array( 'status' => 403, 'required_capability' => 'read' ) );
	}
	public function check_site_info_permission( $request ) { return $this->authorize_request( $request, 'read', 'read', 'site' ); }
	public function check_list_posts_permission( $request ) { return $this->check_content_list_permission( $request, 'post' ); }
	public function check_create_post_permission( $request ) {
		$result = $this->check_content_create_permission( $request, 'edit_posts', 'publish_posts' );
		return true === $result ? $this->check_post_term_assignment_permission( $request ) : $result;
	}
	public function check_get_post_permission( $request ) { return $this->authorize_request( $request, 'read', 'read_post', 'content', $request->get_param( 'id' ) ); }
	public function check_update_post_permission( $request ) {
		$result = $this->check_content_update_permission( $request, 'post', 'publish_posts' );
		return true === $result ? $this->check_post_term_assignment_permission( $request ) : $result;
	}
	public function check_list_pages_permission( $request ) { return $this->check_content_list_permission( $request, 'page' ); }
	public function check_create_page_permission( $request ) { return $this->check_content_create_permission( $request, 'edit_pages', 'publish_pages' ); }
	public function check_get_page_permission( $request ) { return $this->authorize_request( $request, 'read', 'read_post', 'content', $request->get_param( 'id' ) ); }
	public function check_update_page_permission( $request ) { return $this->check_content_update_permission( $request, 'page', 'publish_pages' ); }
	public function check_list_drafts_permission( $request ) {
		$result = $this->authorize_request( $request, 'read', 'read', 'content' );
		if ( true !== $result ) { return $result; }
		$type = $request->get_param( 'type' );
		$allowed = 'post' === $type ? current_user_can( 'edit_posts' ) : ( 'page' === $type ? current_user_can( 'edit_pages' ) : ( current_user_can( 'edit_posts' ) || current_user_can( 'edit_pages' ) ) );
		return $allowed ? true : new WP_Error( 'insufficient_capability', __( 'The bound WordPress user cannot read drafts.', 'mumega-mcp' ), array( 'status' => 403 ) );
	}
	public function check_delete_draft_permission( $request ) { return $this->authorize_request( $request, 'admin', 'delete_post', 'content', $request->get_param( 'id' ) ); }
	public function check_list_media_permission( $request ) { return $this->authorize_request( $request, 'read', 'read', 'media' ); }
	public function check_get_media_permission( $request ) { return $this->authorize_request( $request, 'read', 'read_post', 'media', $request->get_param( 'id' ) ); }
	public function check_upload_media_permission( $request ) { return $this->authorize_request( $request, 'write', 'upload_files', 'media' ); }
	public function check_taxonomy_read_permission( $request ) { return $this->authorize_request( $request, 'read', 'read', 'taxonomy' ); }
	public function check_create_term_permission( $request ) {
		$params = $request->get_json_params();
		$taxonomy_name = is_array( $params ) && isset( $params['taxonomy'] ) && is_string( $params['taxonomy'] ) ? $params['taxonomy'] : '';
		$taxonomy = get_taxonomy( $taxonomy_name );
		$capability = $taxonomy && isset( $taxonomy->cap->manage_terms ) ? $taxonomy->cap->manage_terms : 'manage_categories';
		return $this->authorize_request( $request, 'write', $capability, 'taxonomy' );
	}
	public function check_admin_permission( $request ) { return $this->authorize_request( $request, 'admin', 'mcpwp_manage_settings', 'site' ); }

	private function check_content_create_permission( $request, $edit_capability, $publish_capability ) {
		$params = (array) $request->get_json_params();
		$publishing = isset( $params['status'] ) && in_array( $params['status'], array( 'publish', 'private' ), true );
		$result = $this->authorize_request( $request, $publishing ? 'admin' : 'write', $edit_capability, 'content' );
		if ( true !== $result || ! $publishing ) { return $result; }
		return current_user_can( $publish_capability ) ? true : new WP_Error( 'insufficient_capability', __( 'The bound WordPress user cannot publish this content type.', 'mumega-mcp' ), array( 'status' => 403 ) );
	}

	private function check_content_list_permission( $request, $post_type ) {
		$result = $this->authorize_request( $request, 'read', 'read', 'content' );
		if ( true !== $result ) { return $result; }
		$status = (string) $request->get_param( 'post_status' );
		if ( '' === $status || 'publish' === $status ) { return true; }
		$edit_capability = 'page' === $post_type ? 'edit_pages' : 'edit_posts';
		if ( ! current_user_can( $edit_capability ) ) { return new WP_Error( 'insufficient_capability', __( 'The bound WordPress user cannot list non-public content.', 'mumega-mcp' ), array( 'status' => 403, 'required_capability' => $edit_capability ) ); }
		$private_capability = 'page' === $post_type ? 'read_private_pages' : 'read_private_posts';
		if ( 'private' === $status && ! current_user_can( $private_capability ) ) { return new WP_Error( 'insufficient_capability', __( 'The bound WordPress user cannot read private content.', 'mumega-mcp' ), array( 'status' => 403, 'required_capability' => $private_capability ) ); }
		return true;
	}

	private function check_post_term_assignment_permission( $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) { return true; }
		foreach ( array( 'categories' => 'category', 'tags' => 'post_tag' ) as $field => $taxonomy_name ) {
			if ( ! array_key_exists( $field, $params ) ) { continue; }
			$taxonomy = get_taxonomy( $taxonomy_name );
			$capability = $taxonomy && isset( $taxonomy->cap->assign_terms ) ? $taxonomy->cap->assign_terms : 'edit_posts';
			if ( ! current_user_can( $capability ) ) { return new WP_Error( 'insufficient_capability', __( 'The bound WordPress user cannot assign the requested terms.', 'mumega-mcp' ), array( 'status' => 403, 'required_capability' => $capability ) ); }
		}
		return true;
	}

	private function check_content_update_permission( $request, $post_type, $publish_capability ) {
		$id = (int) $request->get_param( 'id' );
		$post = get_post( $id );
		if ( ! $post || $post_type !== $post->post_type ) { return new WP_Error( 'not_found', __( 'The requested content was not found.', 'mumega-mcp' ), array( 'status' => 404 ) ); }
		$params = (array) $request->get_json_params();
		$publishing = isset( $params['status'] ) && in_array( $params['status'], array( 'publish', 'private' ), true );
		$admin = $publishing || in_array( $post->post_status, array( 'publish', 'private' ), true );
		$result = $this->authorize_request( $request, $admin ? 'admin' : 'write', 'edit_post', 'content', $id );
		if ( true !== $result || ! $publishing ) { return $result; }
		return current_user_can( $publish_capability ) ? true : new WP_Error( 'insufficient_capability', __( 'The bound WordPress user cannot publish this content type.', 'mumega-mcp' ), array( 'status' => 403 ) );
	}

	protected function route_definition( $methods, $callback, $permission, $category, $scope, $capabilities, $mutable, $args = array() ) {
		return array( 'methods' => $methods, 'callback' => array( $this, $callback ), 'permission_callback' => array( $this, $permission ), 'mcpwp_category' => $category, 'mcpwp_scope' => $scope, 'mcpwp_capabilities' => $capabilities, 'mcpwp_mutable' => $mutable, 'args' => $args );
	}

	protected function success_response( $data, $status = 200 ) {
		return $this->add_rate_limit_headers( new WP_REST_Response( $data, $status ) );
	}

	protected function error_response( $code, $message, $status = 400, $context = array() ) {
		return $this->add_rate_limit_headers( new WP_REST_Response( array( 'code' => $code, 'message' => $message, 'data' => array_merge( array( 'status' => $status ), $context ) ), $status ) );
	}

	protected function validated_json_body( $request, $schema, $require_field = false, $max_bytes = 1048576 ) {
		$body = method_exists( $request, 'get_body' ) ? (string) $request->get_body() : '';
		if ( strlen( $body ) > $max_bytes ) {
			return new WP_Error( 'payload_too_large', __( 'The JSON request body is too large.', 'mumega-mcp' ), array( 'status' => 413 ) );
		}
		$object = json_decode( $body );
		$data = json_decode( $body, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_object( $object ) || ! is_array( $data ) ) {
			return new WP_Error( 'invalid_json_body', __( 'A JSON object body is required.', 'mumega-mcp' ), array( 'status' => 400 ) );
		}
		return $this->validate_strict_object( $data, $schema, $require_field );
	}

	protected function validated_query_params( $request, $schema ) {
		$params = $request->get_params();
		if ( ! is_array( $params ) ) { $params = array(); }
		$unknown = array_diff( array_keys( $params ), array_keys( $schema ) );
		if ( ! empty( $unknown ) ) { return new WP_Error( 'unknown_parameter', __( 'The request contains an unknown parameter.', 'mumega-mcp' ), array( 'status' => 400, 'parameter' => (string) reset( $unknown ) ) ); }
		foreach ( $params as $name => &$value ) {
			$type = isset( $schema[ $name ]['type'] ) ? $schema[ $name ]['type'] : '';
			if ( 'integer' === $type && is_string( $value ) && preg_match( '/^(0|[1-9][0-9]*)$/', $value ) ) { $value = (int) $value; }
			if ( 'boolean' === $type && is_string( $value ) && in_array( $value, array( 'true', 'false', '1', '0' ), true ) ) { $value = in_array( $value, array( 'true', '1' ), true ); }
		}
		unset( $value );
		return $this->validate_strict_object( $params, $schema );
	}

	protected function validated_form_params( $request, $schema ) {
		$params = method_exists( $request, 'get_body_params' ) ? $request->get_body_params() : array();
		return $this->validate_strict_object( is_array( $params ) ? $params : array(), $schema );
	}

	protected function validation_error_response( $error ) {
		$data = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
		$context = is_array( $data ) ? $data : array();
		unset( $context['status'] );
		return $this->error_response( $error->get_error_code(), $error->get_error_message(), $status, $context );
	}

	protected function validate_content_relationships( $data, $current_id = 0, $page = false ) {
		if ( isset( $data['categories'] ) ) {
			foreach ( $data['categories'] as $category_id ) {
				if ( ! term_exists( $category_id, 'category' ) ) { return new WP_Error( 'invalid_category', __( 'A category does not exist in the category taxonomy.', 'mumega-mcp' ), array( 'status' => 400, 'category_id' => $category_id ) ); }
			}
		}
		if ( isset( $data['featured_image'] ) && (int) $data['featured_image'] > 0 ) {
			$attachment = get_post( $data['featured_image'] );
			$mime = $attachment && isset( $attachment->post_mime_type ) ? (string) $attachment->post_mime_type : '';
			if ( ! $attachment || 'attachment' !== $attachment->post_type || 0 !== strpos( $mime, 'image/' ) || 'image/svg+xml' === $mime || ! current_user_can( 'read_post', $attachment->ID ) ) {
				return new WP_Error( 'invalid_featured_image', __( 'The featured image must be a readable non-SVG image attachment.', 'mumega-mcp' ), array( 'status' => 400 ) );
			}
		}
		if ( $page && isset( $data['parent'] ) && (int) $data['parent'] > 0 ) {
			$parent_id = (int) $data['parent'];
			$seen = array();
			while ( $parent_id > 0 ) {
				if ( $parent_id === (int) $current_id || isset( $seen[ $parent_id ] ) ) { return new WP_Error( 'invalid_parent', __( 'The requested page parent creates a cycle.', 'mumega-mcp' ), array( 'status' => 400 ) ); }
				$seen[ $parent_id ] = true;
				$parent = get_post( $parent_id );
				if ( ! $parent || 'page' !== $parent->post_type || ! current_user_can( 'read_post', $parent_id ) ) { return new WP_Error( 'invalid_parent', __( 'The page parent must be another readable page.', 'mumega-mcp' ), array( 'status' => 400 ) ); }
				$parent_id = (int) $parent->post_parent;
			}
		}
		return true;
	}

	protected function add_rate_limit_headers( $response ) {
		if ( class_exists( 'Mcpwp_Rate_Limiter' ) ) {
			foreach ( Mcpwp_Rate_Limiter::get_instance()->get_headers() as $name => $value ) {
				$response->header( $name, $value );
			}
		}
		return $response;
	}

	protected function get_pagination_args() {
		return array( 'page' => 1, 'per_page' => 20 );
	}

	protected function get_post_args() {
		return array();
	}

	protected function id_route_args() { return array( 'id' => $this->integer_arg( 1, 2147483647, array( 'required' => true ) ) ); }
	protected function content_list_args( $pages = false ) {
		return array(
			'posts_per_page' => $this->integer_arg( 1, 100, array( 'default' => 20 ) ),
			'paged' => $this->integer_arg( 1, 10000, array( 'default' => 1 ) ),
			'post_status' => $this->string_arg( array( 'enum' => array( 'publish', 'draft', 'pending', 'private' ), 'default' => 'publish' ) ),
			'order' => $this->string_arg( array( 'enum' => array( 'asc', 'desc' ) ) ),
			'orderby' => $this->string_arg( array( 'enum' => $pages ? array( 'date', 'modified', 'title', 'id', 'menu_order' ) : array( 'date', 'modified', 'title', 'id' ) ) ),
		);
	}
	protected function post_body_args( $update = false ) {
		$args = array(
			'title' => $this->string_arg( array( 'minLength' => 1, 'maxLength' => 200, 'required' => ! $update ) ),
			'content' => $this->string_arg( array( 'maxLength' => 1000000 ) ),
			'status' => $this->string_arg( array( 'enum' => array( 'draft', 'pending', 'publish', 'private' ) ) ),
			'excerpt' => $this->string_arg( array( 'maxLength' => 10000 ) ),
			'slug' => $this->string_arg( array( 'minLength' => 1, 'maxLength' => 200 ) ),
			'categories' => $this->id_array_arg(),
			'tags' => array( 'type' => 'array', 'maxItems' => 100, 'uniqueItems' => true, 'items' => $this->string_arg( array( 'minLength' => 1, 'maxLength' => 200 ) ) ),
			'featured_image' => $this->integer_arg( 0, 2147483647 ),
		);
		return $update ? array_merge( $this->id_route_args(), $args ) : $args;
	}
	protected function page_body_args( $update = false ) {
		$args = array(
			'title' => $this->string_arg( array( 'minLength' => 1, 'maxLength' => 200, 'required' => ! $update ) ),
			'content' => $this->string_arg( array( 'maxLength' => 1000000 ) ),
			'status' => $this->string_arg( array( 'enum' => array( 'draft', 'pending', 'publish', 'private' ) ) ),
			'slug' => $this->string_arg( array( 'minLength' => 1, 'maxLength' => 200 ) ),
			'parent' => $this->integer_arg( 0, 2147483647 ),
			'menu_order' => $this->integer_arg( 0, 100000 ),
			'template' => $this->string_arg( array( 'minLength' => 1, 'maxLength' => 200 ) ),
			'featured_image' => $this->integer_arg( 0, 2147483647 ),
		);
		return $update ? array_merge( $this->id_route_args(), $args ) : $args;
	}
	protected function draft_list_args() {
		return array(
			'type' => $this->string_arg( array( 'enum' => array( 'all', 'post', 'page' ), 'default' => 'all' ) ),
			'posts_per_page' => $this->integer_arg( 1, 100, array( 'default' => 20 ) ),
			'paged' => $this->integer_arg( 1, 10000, array( 'default' => 1 ) ),
			'orderby' => $this->string_arg( array( 'enum' => array( 'modified', 'date', 'title', 'id' ) ) ),
			'order' => $this->string_arg( array( 'enum' => array( 'asc', 'desc' ), 'default' => 'desc' ) ),
		);
	}
	protected function media_list_args() {
		return array(
			'posts_per_page' => $this->integer_arg( 1, 100, array( 'default' => 20 ) ), 'paged' => $this->integer_arg( 1, 10000, array( 'default' => 1 ) ),
			'mime_type' => $this->string_arg( array( 'maxLength' => 100 ) ), 'search' => $this->string_arg( array( 'maxLength' => 200 ) ),
			'orderby' => $this->string_arg( array( 'enum' => array( 'date', 'modified', 'title', 'id' ) ) ), 'order' => $this->string_arg( array( 'enum' => array( 'asc', 'desc' ) ) ),
		);
	}
	protected function media_text_args() { return array( 'title' => $this->string_arg( array( 'minLength' => 1, 'maxLength' => 200 ) ), 'alt' => $this->string_arg( array( 'maxLength' => 250 ) ), 'caption' => $this->string_arg( array( 'maxLength' => 10000 ) ) ); }
	protected function multipart_media_args() { return $this->media_text_args(); }
	protected function base64_media_args() { return array_merge( array( 'data' => $this->string_arg( array( 'minLength' => 1, 'required' => true ) ), 'filename' => $this->string_arg( array( 'minLength' => 1, 'maxLength' => 255, 'required' => true ) ), 'mime_type' => $this->string_arg( array( 'maxLength' => 100 ) ) ), $this->media_text_args() ); }
	protected function term_list_args() {
		return array( 'per_page' => $this->integer_arg( 1, 100, array( 'default' => 50 ) ), 'page' => $this->integer_arg( 1, 10000, array( 'default' => 1 ) ), 'search' => $this->string_arg( array( 'maxLength' => 200 ) ), 'hide_empty' => array( 'type' => 'boolean', 'default' => false ), 'orderby' => $this->string_arg( array( 'enum' => array( 'name', 'slug', 'count', 'id' ) ) ), 'order' => $this->string_arg( array( 'enum' => array( 'asc', 'desc' ) ) ) );
	}
	protected function term_create_args() { return array( 'taxonomy' => $this->string_arg( array( 'enum' => array( 'category', 'post_tag' ), 'required' => true ) ), 'name' => $this->string_arg( array( 'minLength' => 1, 'maxLength' => 200, 'required' => true ) ), 'slug' => $this->string_arg( array( 'minLength' => 1, 'maxLength' => 200 ) ), 'description' => $this->string_arg( array( 'maxLength' => 10000 ) ) ); }
	protected function plugin_settings_args() { return array( 'enable_logging' => array( 'type' => 'boolean' ), 'log_retention_days' => $this->integer_arg( 1, 365 ) ); }
	protected function rate_settings_args() { return array( 'enabled' => array( 'type' => 'boolean' ), 'requests_per_minute' => $this->integer_arg( 1, 100000 ), 'requests_per_hour' => $this->integer_arg( 1, 100000 ), 'burst_limit' => $this->integer_arg( 1, 100000 ), 'whitelist' => array( 'type' => 'array', 'maxItems' => 100, 'uniqueItems' => true, 'items' => $this->string_arg( array( 'minLength' => 1, 'maxLength' => 100 ) ) ) ); }
	protected function api_key_create_args() { return array( 'label' => $this->string_arg( array( 'minLength' => 1, 'maxLength' => 100, 'required' => true ) ), 'scopes' => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => 3, 'uniqueItems' => true, 'required' => true, 'items' => $this->string_arg( array( 'enum' => array( 'read', 'write', 'admin' ) ) ) ), 'tool_categories' => array( 'type' => 'array', 'maxItems' => 4, 'uniqueItems' => true, 'items' => $this->string_arg( array( 'enum' => array( 'site', 'content', 'media', 'taxonomy' ) ) ) ) ); }
	protected function integer_arg( $minimum, $maximum, $extra = array() ) { return array_merge( array( 'type' => 'integer', 'minimum' => $minimum, 'maximum' => $maximum ), $extra ); }
	protected function string_arg( $extra = array() ) { return array_merge( array( 'type' => 'string' ), $extra ); }
	protected function id_array_arg() { return array( 'type' => 'array', 'maxItems' => 100, 'uniqueItems' => true, 'items' => $this->integer_arg( 1, 2147483647 ) ); }
}
