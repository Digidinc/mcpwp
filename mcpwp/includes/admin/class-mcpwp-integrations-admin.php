<?php
/**
 * Integrations Admin Page
 *
 * Handles the admin UI for managing third-party AI provider integrations.
 *
 * @package MCPWP
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin page for AI integrations.
 */
class Mcpwp_Integrations_Admin {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'mcpwp-integrations';

	/**
	 * Render the admin page.
	 */
	public function render() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mcpwp' ) );
		}

		$manager   = Mcpwp_Integration_Manager::get_instance();
		$providers = $manager->get_available_providers();
		$is_pro    = class_exists( 'Mcpwp_License' ) && Mcpwp_License::get_instance()->is_pro();

		include MCPWP_PLUGIN_DIR . 'admin/partials/mcpwp-integrations-display.php';
	}

	/**
	 * Get the admin URL used to start Figma OAuth.
	 *
	 * @return string
	 */
	public function get_figma_oauth_start_url() {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=mcpwp_figma_oauth_start' ),
			'mcpwp_figma_oauth_start',
			'mcpwp_nonce'
		);
	}

	/**
	 * Enqueue admin assets for integrations page.
	 *
	 * The canonical 'mcpwp-admin' handle and its localized data are already
	 * enqueued by Mcpwp_Admin::enqueue_scripts() for all MCPWP admin pages.
	 * This method is intentionally a no-op — kept to avoid removing a hook
	 * that may be registered by the loader; it simply returns early.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		// All assets handled by Mcpwp_Admin::enqueue_scripts() and enqueue_styles().
	}

	/**
	 * AJAX: Save integration key.
	 */
	public function ajax_save_key() {
		check_ajax_referer( 'mcpwp_integrations_nonce', 'nonce' );

		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		$provider = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
		if ( empty( $provider ) ) {
			wp_send_json_error( array( 'message' => __( 'Provider is required.', 'mcpwp' ) ) );
		}

		$manager = Mcpwp_Integration_Manager::get_instance();

		// Multi-field providers (e.g. screenshot worker: URL + token).
		if ( $manager->is_multi_field_provider( $provider ) ) {
			$config = isset( $_POST['config'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['config'] ) ) : array();
			if ( empty( $config ) ) {
				wp_send_json_error( array( 'message' => __( 'Configuration fields are required.', 'mcpwp' ) ) );
			}
			// Sanitize URL field specifically.
			if ( isset( $config['url'] ) ) {
				$config['url'] = esc_url_raw( $config['url'] );
			}
			$result = $manager->set_provider_config( $provider, $config );
		} else {
			$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
			if ( empty( $key ) ) {
				wp_send_json_error( array( 'message' => __( 'API key is required.', 'mcpwp' ) ) );
			}
			$result = $manager->set_provider_key( $provider, $key );
		}

		if ( $result ) {
			wp_send_json_success( array( 'message' => __( 'Configuration saved.', 'mcpwp' ) ) );
		} elseif ( 'invalid_config' === $manager->get_last_save_error() ) {
			wp_send_json_error(
				array(
					'message' => $manager->get_last_save_error_message(),
					'code'    => 'invalid_config',
				)
			);
		} elseif ( 'integration_write_failed' === $manager->get_last_save_error() ) {
			// The store was the problem, not the input (#845 review).
			wp_send_json_error(
				array(
					'message' => __( 'The configuration could not be saved: the stored integrations changed or could not be read while saving, so nothing was written. Try again.', 'mcpwp' ),
					'code'    => 'integration_write_failed',
				)
			);
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to save configuration.', 'mcpwp' ) ) );
		}
	}

	/**
	 * AJAX: Remove integration key.
	 */
	public function ajax_remove_key() {
		check_ajax_referer( 'mcpwp_integrations_nonce', 'nonce' );

		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		$provider = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
		if ( empty( $provider ) ) {
			wp_send_json_error( array( 'message' => __( 'Provider is required.', 'mcpwp' ) ) );
		}

		$manager = Mcpwp_Integration_Manager::get_instance();
		$outcome = $manager->remove_provider_record( $provider );

		// A lost removal leaves the key stored and in use (#845 review).
		if ( Mcpwp_Integration_Manager::WRITE_FAILED === $outcome ) {
			wp_send_json_error(
				array(
					'message' => __( 'The API key could not be removed: the stored integrations changed or could not be read while saving. It is still stored. Try again.', 'mcpwp' ),
					'code'    => 'integration_write_failed',
				)
			);
		}

		$removed = Mcpwp_Integration_Manager::WRITE_STORED === $outcome;
		wp_send_json_success(
			array(
				'message' => $removed ? __( 'API key removed.', 'mcpwp' ) : __( 'No key was stored for this provider; nothing to remove.', 'mcpwp' ),
				'removed' => $removed,
			)
		);
	}

	/**
	 * AJAX: Test integration connection.
	 */
	public function ajax_test_connection() {
		check_ajax_referer( 'mcpwp_integrations_nonce', 'nonce' );

		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		$provider = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
		if ( empty( $provider ) ) {
			wp_send_json_error( array( 'message' => __( 'Provider is required.', 'mcpwp' ) ) );
		}

		$manager = Mcpwp_Integration_Manager::get_instance();
		$result  = $manager->test_provider( $provider );

		// The notice shows the message: a status that could not be recorded
		// is said there too (#845 review).
		if ( ! empty( $result['status_error']['message'] ) ) {
			$result['message'] = trim( ( isset( $result['message'] ) ? $result['message'] : '' ) . ' ' . $result['status_error']['message'] );
		}

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * AJAX: One-click Cloudflare self-service fix for MCP requests being
	 * blocked by Bot Fight Mode / Security Level / Super Bot Fight Mode.
	 *
	 * Same underlying Mcpwp_Provider_Cloudflare::fix_mcp_access() already
	 * exposed via the wp_cloudflare_fix_mcp_access MCP tool and the
	 * POST /cloudflare/fix-mcp-access REST route — this just makes it
	 * reachable from wp-admin directly, so a customer without an AI client
	 * connected yet (or without Cloudflare dashboard access themselves) isn't
	 * stuck needing a manual per-site WAF rule walked through by support.
	 */
	public function ajax_fix_cloudflare_access() {
		check_ajax_referer( 'mcpwp_integrations_nonce', 'nonce' );

		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		if ( ! class_exists( 'Mcpwp_Provider_Cloudflare' ) ) {
			wp_send_json_error( array( 'message' => __( 'Cloudflare support is not available in this build.', 'mcpwp' ) ) );
		}

		$cloudflare = new Mcpwp_Provider_Cloudflare();
		$result     = $cloudflare->fix_mcp_access();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Start the Figma OAuth handshake.
	 *
	 * @return void
	 */
	public function handle_figma_oauth_start() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mcpwp' ) );
		}

		check_admin_referer( 'mcpwp_figma_oauth_start', 'mcpwp_nonce' );

		$figma = new Mcpwp_Figma();
		$url   = $figma->get_oauth_authorize_url();
		if ( is_wp_error( $url ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'            => self::PAGE_SLUG,
						'mcpwp_figma_oauth' => 'error',
						'message'         => rawurlencode( $url->get_error_message() ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Complete the Figma OAuth callback.
	 *
	 * @return void
	 */
	public function handle_figma_oauth_callback() {
		// Also hooked on admin_post_nopriv (Figma's redirect lands back on this
		// domain as a normal browser navigation, so the admin's own session
		// cookie is present — nopriv exists only so a logged-out hit doesn't
		// fatal). The state-transient check below proves an admin genuinely
		// started this flow, but doesn't confirm the CURRENT request is that
		// same admin's browser — require the capability directly too.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'             => self::PAGE_SLUG,
						'mcpwp_figma_oauth' => 'error',
						'message'          => rawurlencode( __( 'You must be logged in as an administrator to complete this connection.', 'mcpwp' ) ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';

		if ( '' !== $error ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'            => self::PAGE_SLUG,
						'mcpwp_figma_oauth' => 'error',
						'message'         => rawurlencode( $error ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$figma  = new Mcpwp_Figma();
		$result = $figma->exchange_oauth_code( $code, $state );

		$args = array(
			'page'             => self::PAGE_SLUG,
			'mcpwp_figma_oauth' => is_wp_error( $result ) ? 'error' : 'success',
			'message'          => rawurlencode( is_wp_error( $result ) ? $result->get_error_message() : $result['message'] ),
		);

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
