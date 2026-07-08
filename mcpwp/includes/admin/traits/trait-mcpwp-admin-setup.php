<?php
/**
 * Admin setup page methods.
 *
 * Carved verbatim from Mcpwp_Admin (G3 split). Mixed back via trait — same class, same $this.
 *
 * @package mumcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Mcpwp_Admin_Setup_Trait {

	/**
	 * Render the Setup page (default landing page).
	 */
	public function render_setup_page() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mumcp' ) );
		}

		// Handle API key actions.
		$new_key = null;
		if ( isset( $_POST['mcpwp_regenerate_key'] ) ) {
			check_admin_referer( 'mcpwp_regenerate_key', 'mcpwp_nonce' );
			$new_key = $this->regenerate_api_key();
			add_settings_error(
				'mcpwp_messages',
				'mcpwp_key_regenerated',
				__( 'API key has been regenerated. Copy it now — it will not be shown again.', 'mumcp' ),
				'updated'
			);
		}

		$new_scoped_key = null;
		if ( isset( $_POST['mcpwp_create_scoped_key'] ) ) {
			check_admin_referer( 'mcpwp_manage_scoped_keys', 'mcpwp_scoped_keys_nonce' );

			$label  = isset( $_POST['mcpwp_scoped_key_label'] ) ? sanitize_text_field( wp_unslash( $_POST['mcpwp_scoped_key_label'] ) ) : '';
			$scopes = isset( $_POST['mcpwp_scoped_key_scopes'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['mcpwp_scoped_key_scopes'] ) ) : array();
			if ( empty( $scopes ) ) {
				$scopes = array( 'read' ); // Least privilege — unchecking all scopes = read-only.
			}

			$role            = isset( $_POST['mcpwp_scoped_key_role'] ) ? sanitize_key( wp_unslash( $_POST['mcpwp_scoped_key_role'] ) ) : 'admin';
			$tool_categories = isset( $_POST['mcpwp_scoped_key_categories'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['mcpwp_scoped_key_categories'] ) ) : array();

			$new_scoped_key = $this->create_scoped_api_key( $label, $scopes, $role, $tool_categories );

			$roles      = self::get_role_definitions();
			$role_label = isset( $roles[ $role ] ) ? $roles[ $role ]['label'] : $role;
			add_settings_error(
				'mcpwp_messages',
				'mcpwp_scoped_key_created',
				sprintf(
					/* translators: %s: role label */
					__( 'API key created (role: %s). Copy it now — it will not be shown again.', 'mumcp' ),
					$role_label
				),
				'updated'
			);
		}

		if ( isset( $_POST['mcpwp_revoke_scoped_key'] ) ) {
			check_admin_referer( 'mcpwp_manage_scoped_keys', 'mcpwp_scoped_keys_nonce' );

			$key_id = isset( $_POST['mcpwp_scoped_key_id'] ) ? sanitize_key( wp_unslash( $_POST['mcpwp_scoped_key_id'] ) ) : '';
			if ( '' !== $key_id ) {
				$revoked = $this->revoke_scoped_api_key( $key_id );
				if ( $revoked ) {
					add_settings_error(
						'mcpwp_messages',
						'mcpwp_scoped_key_revoked',
						__( 'Scoped API key revoked.', 'mumcp' ),
						'updated'
					);
				} else {
					add_settings_error(
						'mcpwp_messages',
						'mcpwp_scoped_key_revoke_failed',
						__( 'Unable to revoke key (it may already be revoked).', 'mumcp' ),
						'error'
					);
				}
			}
		}

		// Check for first-activation key.
		if ( ! $new_key ) {
			$first_key = get_transient( 'mcpwp_new_api_key' );
			if ( $first_key ) {
				$new_key = $first_key;
			}
		}

		// Handle force update check.
		if ( isset( $_POST['mcpwp_force_update_check'] ) ) {
			check_admin_referer( 'mcpwp_check_update', 'mcpwp_update_nonce' );
			delete_site_transient( 'update_plugins' );
			delete_transient( 'mcpwp_update_check' );
			wp_update_plugins();
			add_settings_error(
				'mcpwp_messages',
				'mcpwp_update_checked',
				__( 'Update check complete.', 'mumcp' ),
				'updated'
			);
		}

		$scoped_keys = $this->list_scoped_api_keys( true );

		include MCPWP_PLUGIN_DIR . 'admin/partials/mcpwp-setup-display.php';
	}

	/**
	 * Get the stored structured site profile.
	 *
	 * @return array
	 */
	public function get_site_profile() {
		$profile = get_option( 'mcpwp_site_profile', array() );
		return is_array( $profile ) ? $profile : array();
	}

	/**
	 * Get the canonical generated site context.
	 *
	 * @return string
	 */
	public function get_site_context_preview() {
		$context = get_option( 'mcpwp_site_context', '' );
		return is_string( $context ) ? $context : '';
	}

	/**
	 * Get the live llms.txt URL.
	 *
	 * @return string
	 */
	public function get_llms_url() {
		return home_url( '/llms.txt' );
	}

	/**
	 * Generate the live llms.txt preview text.
	 *
	 * @return string
	 */
	public function get_llms_preview() {
		if ( ! class_exists( 'Mcpwp_AI_Presence' ) ) {
			return '';
		}

		$presence = new Mcpwp_AI_Presence();
		return $presence->generate_llms_txt();
	}

	/**
	 * Get update channel status and manual recovery info for admin.
	 *
	 * @return array
	 */
	public function get_update_channel_status() {
		// In the WP.org build, all update-channel/phone-home logic is disabled.
		if ( defined( 'MCPWP_WPORG_BUILD' ) ) {
			return array(
				'current_version'  => defined( 'MCPWP_VERSION' ) ? MCPWP_VERSION : '0.0.0',
				'remote_version'   => null,
				'download_url'     => '',
				'manifest_url'     => '',
				'option_version'   => null,
				'source'           => 'wporg',
				'update_available' => false,
				'warning'          => '',
				'manual_steps'     => array(),
			);
		}

		$manifest_url    = get_option( 'mcpwp_version_url', 'https://mumega.com/mcp-updates/version.json' );
		$manifest_url    = $manifest_url ? $manifest_url : 'https://mumega.com/mcp-updates/version.json';
		$current_version = defined( 'MCPWP_VERSION' ) ? MCPWP_VERSION : '0.0.0';
		$remote_version  = null;
		$download_url    = 'https://mumega.com/mcp-updates/mcpwp-latest.zip';
		$source          = 'remote';
		$option_version  = null;
		$warning         = '';

		$option_data = get_option( 'mcpwp_update_info' );
		if ( is_string( $option_data ) ) {
			$option_data = json_decode( $option_data, true );
		}
		if ( is_array( $option_data ) ) {
			if ( ! empty( $option_data['version'] ) ) {
				$option_version = (string) $option_data['version'];
			}
			if ( ! empty( $option_data['download_url'] ) && empty( $download_url ) ) {
				$download_url = (string) $option_data['download_url'];
			}
		}

		$response = wp_remote_get(
			$manifest_url,
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept' => 'application/json',
				),
			)
		);

		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $body ) ) {
				if ( ! empty( $body['version'] ) ) {
					$remote_version = (string) $body['version'];
				}
				if ( ! empty( $body['download_url'] ) ) {
					$download_url = (string) $body['download_url'];
				}
			}
		} else {
			$warning = __( 'The update manifest could not be reached from this site. Manual download is still available below.', 'mumcp' );
		}

		if ( $option_version && $remote_version && version_compare( $option_version, $remote_version, '!=' ) ) {
			$source  = 'mixed';
			$warning = __( 'A site-level update override is present and does not match the remote manifest. Clear stale override data if updates look wrong.', 'mumcp' );
		} elseif ( $option_version ) {
			$source = 'option';
		}

		return array(
			'current_version'  => $current_version,
			'remote_version'   => $remote_version,
			'download_url'     => $download_url,
			'manifest_url'     => $manifest_url,
			'option_version'   => $option_version,
			'source'           => $source,
			'update_available' => ( $remote_version && version_compare( $remote_version, $current_version, '>' ) ),
			'warning'          => $warning,
			'manual_steps'     => array(
				__( 'Download the latest ZIP from the canonical package URL.', 'mumcp' ),
				__( 'In WordPress admin, go to Plugins -> Add Plugin -> Upload Plugin.', 'mumcp' ),
				__( 'Upload the ZIP and replace the installed version.', 'mumcp' ),
			),
		);
	}

	/**
	 * Get first-run onboarding status for the operator workflow.
	 *
	 * @return array
	 */
	public function get_onboarding_status() {
		$profile         = $this->get_site_profile();
		$inventory       = $this->get_library_inventory();
		$has_site_profile = ! empty( array_filter( $profile ) );
		$has_api_key     = ! empty( get_option( 'mcpwp_api_key', '' ) );
		$has_references  = ! empty( $inventory['design_references'] );
		$has_archetypes  = ! empty( $inventory['page_archetypes'] ) || ! empty( $inventory['product_archetypes'] );
		$has_parts       = ! empty( $inventory['parts'] );

		$steps = array(
			array(
				'title'       => __( 'Define site character', 'mumcp' ),
				'description' => __( 'Save the brand voice, audience, and page rules in Settings.', 'mumcp' ),
				'done'        => $has_site_profile,
				'url'         => admin_url( 'admin.php?page=' . self::SETTINGS_PAGE_SLUG ),
				'cta'         => __( 'Open Settings', 'mumcp' ),
			),
			array(
				'title'       => __( 'Create an AI key', 'mumcp' ),
				'description' => __( 'Generate or copy an API key so models can connect to the site.', 'mumcp' ),
				'done'        => $has_api_key,
				'url'         => admin_url( 'admin.php?page=' . self::PAGE_SLUG ),
				'cta'         => __( 'Manage Keys', 'mumcp' ),
			),
			array(
				'title'       => __( 'Store a design reference', 'mumcp' ),
				'description' => __( 'Turn one approved screenshot or mockup into reusable design memory.', 'mumcp' ),
				'done'        => $has_references,
				'url'         => admin_url( 'admin.php?page=' . self::LIBRARY_PAGE_SLUG ),
				'cta'         => __( 'Open Library', 'mumcp' ),
			),
			array(
				'title'       => __( 'Create an archetype', 'mumcp' ),
				'description' => __( 'Save at least one page or product structure so models stop starting from zero.', 'mumcp' ),
				'done'        => $has_archetypes,
				'url'         => admin_url( 'admin.php?page=' . self::LIBRARY_PAGE_SLUG ),
				'cta'         => __( 'Review Archetypes', 'mumcp' ),
			),
			array(
				'title'       => __( 'Build reusable parts', 'mumcp' ),
				'description' => __( 'Keep heroes, proof blocks, FAQs, and CTAs in the parts library for future pages.', 'mumcp' ),
				'done'        => $has_parts,
				'url'         => admin_url( 'admin.php?page=' . self::LIBRARY_PAGE_SLUG ),
				'cta'         => __( 'Review Parts', 'mumcp' ),
			),
		);

		$completed = 0;
		foreach ( $steps as $step ) {
			if ( ! empty( $step['done'] ) ) {
				$completed++;
			}
		}

		$next_step = null;
		foreach ( $steps as $step ) {
			if ( empty( $step['done'] ) ) {
				$next_step = $step;
				break;
			}
		}

		return array(
			'completed' => $completed,
			'total'     => count( $steps ),
			'steps'     => $steps,
			'next_step' => $next_step,
		);
	}
}
