<?php
/**
 * Admin setup page methods.
 *
 * Carved verbatim from Mcpwp_Admin (G3 split). Mixed back via trait — same class, same $this.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Mcpwp_Admin_Setup_Trait {

	/** @var array|null New scoped key payload for this request only (never persisted). */
	private static $setup_new_scoped_key = null;

	/** @var string|null Connect tab to open on this request. */
	private static $setup_connect_tab = null;

	/** @var bool Scoped-key create POST already handled on load-toplevel_page_mcpwp. */
	private static $setup_scoped_post_handled = false;

	/**
	 * Transient name for the first-activation plaintext key (per user).
	 *
	 * @param int $user_id WordPress user id (0: site-wide mint before login).
	 * @return string
	 */
	public static function new_primary_key_transient_name( $user_id = null ) {
		if ( null === $user_id ) {
			$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		} else {
			$user_id = (int) $user_id;
		}
		return 'mcpwp_new_api_key_' . $user_id;
	}

	/**
	 * Transient that holds admin notices after a PRG redirect to Setup.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	public static function setup_pending_errors_transient_name( $user_id ) {
		return 'mcpwp_setup_pending_errors_' . (int) $user_id;
	}

	/**
	 * Replay pending Setup notices after redirect (no settings-updated query arg).
	 *
	 * @return void
	 */
	private function maybe_restore_setup_page_notices() {
		if ( ! isset( $_GET['mcpwp_setup_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$flag = sanitize_key( wp_unslash( $_GET['mcpwp_setup_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '1' !== $flag ) {
			return;
		}
		$user_id = get_current_user_id();
		$pending = get_transient( self::setup_pending_errors_transient_name( $user_id ) );
		if ( ! is_array( $pending ) ) {
			return;
		}
		foreach ( $pending as $error ) {
			if ( ! is_array( $error ) ) {
				continue;
			}
			add_settings_error(
				isset( $error['setting'] ) ? (string) $error['setting'] : 'mcpwp_messages',
				isset( $error['code'] ) ? (string) $error['code'] : 'mcpwp_setup_notice',
				isset( $error['message'] ) ? (string) $error['message'] : '',
				isset( $error['type'] ) ? (string) $error['type'] : 'error'
			);
		}
		delete_transient( self::setup_pending_errors_transient_name( $user_id ) );
	}

	/**
	 * Read the new primary key transient for the current user (legacy global fallback).
	 *
	 * @return string|null
	 */
	public static function pull_new_primary_api_key_transient() {
		$key = get_transient( self::new_primary_key_transient_name() );
		if ( is_string( $key ) && '' !== $key ) {
			return $key;
		}
		$activation = get_transient( self::new_primary_key_transient_name( 0 ) );
		if ( is_string( $activation ) && '' !== $activation ) {
			return $activation;
		}
		$legacy = get_transient( 'mcpwp_new_api_key' );
		if ( is_string( $legacy ) && '' !== $legacy ) {
			delete_transient( 'mcpwp_new_api_key' );
			set_transient( self::new_primary_key_transient_name(), $legacy, self::NEW_PRIMARY_KEY_TRANSIENT_TTL );
			return $legacy;
		}
		return null;
	}

	/**
	 * Connect tab override set by a successful scoped-key create this request.
	 *
	 * @return string|null
	 */
	public static function get_setup_connect_tab_override() {
		$tab = self::$setup_connect_tab;
		return is_string( $tab ) && '' !== $tab ? $tab : null;
	}

	/**
	 * Reset per-request Setup state (tests).
	 *
	 * @return void
	 */
	public static function reset_setup_page_request_state() {
		self::$setup_new_scoped_key       = null;
		self::$setup_connect_tab          = null;
		self::$setup_scoped_post_handled  = false;
	}

	/**
	 * Handle Setup POST actions before admin-header.php (headers-safe).
	 *
	 * @return void
	 */
	public function handle_setup_page_load() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		if ( isset( $_POST['mcpwp_create_scoped_key'] ) || isset( $_POST['mcpwp_create_readonly_scoped_key'] ) ) {
			$this->handle_setup_scoped_key_create_post();
		}
	}

	/**
	 * Create a scoped key from POST; store result for render only (no plaintext persistence).
	 *
	 * @return void
	 */
	private function handle_setup_scoped_key_create_post() {
		self::$setup_scoped_post_handled = true;

		check_admin_referer( 'mcpwp_manage_scoped_keys', 'mcpwp_scoped_keys_nonce' );

		$allowed_return_tabs = array( 'claude-code', 'claude-desktop', 'cursor', 'windsurf' );
		$return_tab          = isset( $_POST['mcpwp_setup_return_tab'] ) ? sanitize_key( wp_unslash( $_POST['mcpwp_setup_return_tab'] ) ) : '';
		if ( '' !== $return_tab && ! in_array( $return_tab, $allowed_return_tabs, true ) ) {
			$return_tab = '';
		}

		$form_uid = isset( $_POST['mcpwp_scoped_form_uid'] ) ? sanitize_key( wp_unslash( $_POST['mcpwp_scoped_form_uid'] ) ) : '';
		$user_id  = get_current_user_id();
		if ( '' !== $form_uid ) {
			$spent_name = 'mcpwp_scoped_form_spent_' . $user_id . '_' . $form_uid;
			if ( get_transient( $spent_name ) ) {
				add_settings_error(
					'mcpwp_messages',
					'mcpwp_scoped_key_duplicate_submit',
					__( 'That submit already created a key. Reload the Setup page to create another key.', 'mcpwp' ),
					'error'
				);
				$this->redirect_setup_scoped_key_form_error( $return_tab );
			}
		}

		$label  = isset( $_POST['mcpwp_scoped_key_label'] ) ? sanitize_text_field( wp_unslash( $_POST['mcpwp_scoped_key_label'] ) ) : '';
		$scopes = isset( $_POST['mcpwp_scoped_key_scopes'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['mcpwp_scoped_key_scopes'] ) ) : array();
		if ( isset( $_POST['mcpwp_create_readonly_scoped_key'] ) ) {
			$scopes = array( 'read' );
		} elseif ( empty( $scopes ) ) {
			$scopes = array( 'read' );
		}

		// #1041 WARN-B: '' when missing — create_scoped_api_key() applies its default.
		$role            = isset( $_POST['mcpwp_scoped_key_role'] ) ? sanitize_key( wp_unslash( $_POST['mcpwp_scoped_key_role'] ) ) : '';
		$tool_categories = isset( $_POST['mcpwp_scoped_key_categories'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['mcpwp_scoped_key_categories'] ) ) : array();

		$new_scoped_key = $this->create_scoped_api_key( $label, $scopes, $role, $tool_categories );

		if ( is_array( $new_scoped_key ) && isset( $new_scoped_key['error'] ) ) {
			add_settings_error(
				'mcpwp_messages',
				'mcpwp_scoped_key_error',
				$new_scoped_key['message'],
				'error'
			);
			$this->redirect_setup_scoped_key_form_error( $return_tab );
		}

		$actual_role = isset( $new_scoped_key['role'] ) ? $new_scoped_key['role'] : $role;
		$roles       = self::get_role_definitions();
		$role_label  = isset( $roles[ $actual_role ] ) ? $roles[ $actual_role ]['label'] : $actual_role;
		add_settings_error(
			'mcpwp_messages',
			'mcpwp_scoped_key_created',
			sprintf(
				/* translators: %s: role label */
				__( 'API key created (role: %s). Copy it now — it will not be shown again.', 'mcpwp' ),
				$role_label
			),
			'updated'
		);

		if ( '' !== $form_uid ) {
			set_transient( 'mcpwp_scoped_form_spent_' . $user_id . '_' . $form_uid, '1', self::SCOPED_FORM_SPENT_TTL );
		}

		self::$setup_new_scoped_key = $new_scoped_key;
		if ( '' !== $return_tab ) {
			self::$setup_connect_tab = $return_tab;
		}
	}

	/**
	 * PRG redirect after a scoped-key form error (never carries plaintext keys).
	 *
	 * @param string $return_tab Optional connect tab to reopen.
	 * @return void
	 */
	private function redirect_setup_scoped_key_form_error( $return_tab ) {
		$allowed_return_tabs = array( 'claude-code', 'claude-desktop', 'cursor', 'windsurf' );
		$url                 = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		if ( '' !== $return_tab && in_array( $return_tab, $allowed_return_tabs, true ) ) {
			$url = add_query_arg( 'setup_tab', $return_tab, $url );
		}
		$url .= '#mcpwp-connect-tabs';

		$user_id = get_current_user_id();
		set_transient( self::setup_pending_errors_transient_name( $user_id ), get_settings_errors(), self::SETUP_NOTICE_TTL );
		$url = add_query_arg( 'mcpwp_setup_notice', '1', $url );

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Render the Setup page (default landing page).
	 */
	public function render_setup_page() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mcpwp' ) );
		}

		$this->maybe_restore_setup_page_notices();

		// Handle API key actions.
		$new_key = null;
		if ( isset( $_POST['mcpwp_regenerate_key'] ) ) {
			check_admin_referer( 'mcpwp_regenerate_key', 'mcpwp_nonce' );
			$new_key = $this->regenerate_api_key();
			if ( '' === $new_key ) {
				$new_key = null;
				add_settings_error(
					'mcpwp_messages',
					'mcpwp_key_regenerate_busy',
					Mcpwp_Key_State::store_is_unreadable() ? Mcpwp_Key_State::unreadable_message() : __( 'The API key store is busy. Nothing was changed; try again.', 'mcpwp' ),
					'error'
				);
			} else {
				add_settings_error(
					'mcpwp_messages',
					'mcpwp_key_regenerated',
					__( 'API key has been regenerated. Copy it now — it will not be shown again.', 'mcpwp' ),
					'updated'
				);
			}
		}

		$new_scoped_key = self::$setup_new_scoped_key;

		if ( isset( $_POST['mcpwp_revoke_scoped_key'] ) ) {
			check_admin_referer( 'mcpwp_manage_scoped_keys', 'mcpwp_scoped_keys_nonce' );

			$key_id = isset( $_POST['mcpwp_scoped_key_id'] ) ? sanitize_key( wp_unslash( $_POST['mcpwp_scoped_key_id'] ) ) : '';
			if ( '' !== $key_id ) {
				$outcome = $this->revoke_scoped_api_key_outcome( $key_id );
				if ( 'revoked' === $outcome ) {
					add_settings_error(
						'mcpwp_messages',
						'mcpwp_scoped_key_revoked',
						__( 'Scoped API key revoked.', 'mcpwp' ),
						'updated'
					);
				} elseif ( 'unreadable' === $outcome ) {
					add_settings_error( 'mcpwp_messages', 'mcpwp_scoped_key_revoke_unreadable', Mcpwp_Key_State::unreadable_message(), 'error' );
				} elseif ( 'busy' === $outcome ) {
					add_settings_error(
						'mcpwp_messages',
						'mcpwp_scoped_key_revoke_busy',
						__( 'The API key store is busy. The key was NOT revoked and is still active. Try again in a moment.', 'mcpwp' ),
						'error'
					);
				} else {
					add_settings_error(
						'mcpwp_messages',
						'mcpwp_scoped_key_revoke_failed',
						__( 'Unable to revoke key (it may already be revoked).', 'mcpwp' ),
						'error'
					);
				}
			}
		}

		// Check for first-activation key (per-user transient, short TTL).
		if ( ! $new_key ) {
			$first_key = self::pull_new_primary_api_key_transient();
			if ( $first_key ) {
				$new_key = $first_key;
				delete_transient( self::new_primary_key_transient_name() );
				delete_transient( self::new_primary_key_transient_name( 0 ) );
				delete_transient( 'mcpwp_new_api_key' );
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
				__( 'Update check complete.', 'mcpwp' ),
				'updated'
			);
		}

		// "Add to ChatGPT": create/rotate or revoke the one connector key.
		$chatgpt_connector = new Mcpwp_ChatGPT_Connector( $this );
		$chatgpt_new_url   = null;
		if ( isset( $_POST['mcpwp_chatgpt_connector_action'] ) ) {
			check_admin_referer( Mcpwp_ChatGPT_Connector::NONCE_ACTION, 'mcpwp_chatgpt_connector_nonce' );
			$chatgpt_result = $chatgpt_connector->handle_request( wp_unslash( $_POST ) );
			if ( is_array( $chatgpt_result ) ) {
				add_settings_error( 'mcpwp_messages', 'mcpwp_chatgpt_connector', $chatgpt_result['message'], $chatgpt_result['type'] );
				if ( ! empty( $chatgpt_result['url'] ) ) {
					$chatgpt_new_url = $chatgpt_result['url'];
				}
			}
		}
		$chatgpt_oauth_conflict = Mcpwp_ChatGPT_Connector::oauth_discovery_conflict();
		$local_oauth_enabled    = Mcpwp_OAuth_Well_Known::is_legacy_oauth_enabled();
		$chatgpt_active_key = $chatgpt_connector->get_active_key();

		$scoped_keys = $this->list_scoped_api_keys( true );

		// mumcp.com broker connection status for the Connections UI.
		$broker_enrolled   = class_exists( 'Mcpwp_Broker_Auth' ) && ( new Mcpwp_Broker_Auth() )->is_enrolled();
		$broker_enrollment = get_option( 'mcpwp_broker_enrollment', array() );
		$broker_audience   = ( is_array( $broker_enrollment ) && ! empty( $broker_enrollment['audience'] ) )
			? (string) $broker_enrollment['audience']
			: '';
		$broker_notice     = isset( $_GET['broker'] ) ? sanitize_key( wp_unslash( $_GET['broker'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag, no state change.

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

		// Canonical channel for a site that never explicitly saved
		// mcpwp_version_url. A site that did save one — including the legacy
		// mumega.com manifest — is untouched: both read the same R2 bucket
		// (see CLAUDE.md "Deploy & Update System").
		$manifest_url    = get_option( 'mcpwp_version_url', 'https://updates.mcpwp.net/version.json' );
		$manifest_url    = $manifest_url ? $manifest_url : 'https://updates.mcpwp.net/version.json';
		$current_version = defined( 'MCPWP_VERSION' ) ? MCPWP_VERSION : '0.0.0';
		$remote_version  = null;
		$download_url    = 'https://updates.mcpwp.net/mcpwp-latest.zip';
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
			$warning = __( 'The update manifest could not be reached from this site. Manual download is still available below.', 'mcpwp' );
		}

		if ( $option_version && $remote_version && version_compare( $option_version, $remote_version, '!=' ) ) {
			$source  = 'mixed';
			$warning = __( 'A site-level update override is present and does not match the remote manifest. Clear stale override data if updates look wrong.', 'mcpwp' );
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
				__( 'Download the latest ZIP from the canonical package URL.', 'mcpwp' ),
				__( 'In WordPress admin, go to Plugins -> Add Plugin -> Upload Plugin.', 'mcpwp' ),
				__( 'Upload the ZIP and replace the installed version.', 'mcpwp' ),
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
		$has_api_key     = Mcpwp_Key_State::site_has_api_key(); // Not the legacy option alone (#1098 P2).
		$has_references  = ! empty( $inventory['design_references'] );
		$has_archetypes  = ! empty( $inventory['page_archetypes'] ) || ! empty( $inventory['product_archetypes'] );
		$has_parts       = ! empty( $inventory['parts'] );

		$steps = array(
			array(
				'title'       => __( 'Define site character', 'mcpwp' ),
				'description' => __( 'Save the brand voice, audience, and page rules in Settings.', 'mcpwp' ),
				'done'        => $has_site_profile,
				'url'         => admin_url( 'admin.php?page=' . self::SETTINGS_PAGE_SLUG ),
				'cta'         => __( 'Open Settings', 'mcpwp' ),
			),
			array(
				'title'       => __( 'Create an AI key', 'mcpwp' ),
				'description' => __( 'Generate or copy an API key so models can connect to the site.', 'mcpwp' ),
				'done'        => $has_api_key,
				'url'         => admin_url( 'admin.php?page=' . self::PAGE_SLUG ),
				'cta'         => __( 'Manage Keys', 'mcpwp' ),
			),
			array(
				'title'       => __( 'Store a design reference', 'mcpwp' ),
				'description' => __( 'Turn one approved screenshot or mockup into reusable design memory.', 'mcpwp' ),
				'done'        => $has_references,
				'url'         => admin_url( 'admin.php?page=' . self::LIBRARY_PAGE_SLUG ),
				'cta'         => __( 'Open Library', 'mcpwp' ),
			),
			array(
				'title'       => __( 'Create an archetype', 'mcpwp' ),
				'description' => __( 'Save at least one page or product structure so models stop starting from zero.', 'mcpwp' ),
				'done'        => $has_archetypes,
				'url'         => admin_url( 'admin.php?page=' . self::LIBRARY_PAGE_SLUG ),
				'cta'         => __( 'Review Archetypes', 'mcpwp' ),
			),
			array(
				'title'       => __( 'Build reusable parts', 'mcpwp' ),
				'description' => __( 'Keep heroes, proof blocks, FAQs, and CTAs in the parts library for future pages.', 'mcpwp' ),
				'done'        => $has_parts,
				'url'         => admin_url( 'admin.php?page=' . self::LIBRARY_PAGE_SLUG ),
				'cta'         => __( 'Review Parts', 'mcpwp' ),
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
