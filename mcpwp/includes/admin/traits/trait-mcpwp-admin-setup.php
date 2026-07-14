<?php
/**
 * Setup-page and API-key administration methods.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }
trait Mcpwp_Admin_Setup_Trait {
	/**
	 * Render API-key setup and process an optional submitted action.
	 *
	 * @return void
	 */
	public function render_setup_page() {
		if ( ! current_user_can( 'mcpwp_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'mumega-mcp' ) ); }
		$new_key = '';
		$notice  = null;
		if ( isset( $_POST['mcpwp_create_key'] ) || isset( $_POST['mcpwp_revoke_key'] ) ) {
			$result = $this->handle_setup_action( $_POST );
			if ( is_wp_error( $result ) ) {
				$notice = array( 'error', $result->get_error_message() );
			} elseif ( isset( $result['created']['key'] ) ) {
				$new_key = $result['created']['key'];
				$notice  = array( 'success', __( 'API key created. Copy it now because it will not be shown again.', 'mumega-mcp' ) );
			} elseif ( isset( $result['revoked'] ) ) {
				$notice = array( 'success', __( 'API key revoked.', 'mumega-mcp' ) );
			}
		}
		$keys = $this->list_scoped_api_keys();
		include MCPWP_PLUGIN_DIR . 'admin/partials/mcpwp-setup-display.php';
	}

	/**
	 * Validate and execute an API-key creation or revocation action.
	 *
	 * @param array<string,mixed> $post Submitted form data.
	 * @return array<string,mixed>|WP_Error Action result or validation error.
	 */
	public function handle_setup_action( $post ) {
		if ( ! current_user_can( 'mcpwp_manage_settings' ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to manage API keys.', 'mumega-mcp' ) );
		}
		$post = is_array( $post ) ? $post : array();

		if ( isset( $post['mcpwp_create_key'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $post['_wpnonce'] ?? '' ) );
			if ( ! wp_verify_nonce( $nonce, 'mcpwp_create_key' ) ) {
				return new WP_Error( 'invalid_admin_nonce', __( 'The API-key creation request could not be verified.', 'mumega-mcp' ) );
			}
			$label = sanitize_text_field( wp_unslash( $post['label'] ?? '' ) );
			if ( '' === $label || strlen( $label ) > 100 ) {
				return new WP_Error( 'invalid_api_key_label', __( 'Enter an API-key label of no more than 100 bytes.', 'mumega-mcp' ) );
			}
			$scopes     = array_values( array_unique( map_deep( (array) ( $post['scopes'] ?? array() ), 'sanitize_key' ) ) );
			$categories = array_values( array_unique( map_deep( (array) ( $post['tool_categories'] ?? array() ), 'sanitize_key' ) ) );
			if ( empty( $scopes ) || empty( $categories ) ) {
				return new WP_Error( 'invalid_api_key_record', __( 'Select at least one scope and one tool category.', 'mumega-mcp' ) );
			}
			$created = $this->create_scoped_api_key(
				$label,
				$scopes,
				$categories,
				get_current_user_id()
			);
			if ( is_wp_error( $created ) ) {
				return $created;
			}
			Mcpwp_Migrate::setup_owner( get_current_user_id() );
			return array( 'created' => $created );
		}

		if ( isset( $post['mcpwp_revoke_key'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $post['_wpnonce'] ?? '' ) );
			if ( ! wp_verify_nonce( $nonce, 'mcpwp_revoke_key' ) ) {
				return new WP_Error( 'invalid_admin_nonce', __( 'The API-key revocation request could not be verified.', 'mumega-mcp' ) );
			}
			$key_id = sanitize_key( wp_unslash( $post['key_id'] ?? '' ) );
			if ( '' === $key_id || ! $this->revoke_scoped_api_key( $key_id ) ) {
				return new WP_Error( 'api_key_not_found', __( 'The API key could not be revoked.', 'mumega-mcp' ) );
			}
			return array( 'revoked' => $key_id );
		}

		return array();
	}
}
