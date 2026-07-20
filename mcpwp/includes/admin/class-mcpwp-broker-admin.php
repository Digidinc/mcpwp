<?php
/**
 * mumcp.com broker — admin Connect / Disconnect handlers.
 *
 * The two browser-facing legs of the enrollment lifecycle (Plan 2 / Task 5).
 * Mirrors the admin_post_ pattern already used for the Figma OAuth handshake
 * in Mcpwp_Integrations_Admin::handle_figma_oauth_start()/
 * handle_figma_oauth_callback() — a plain WP admin-post.php action, gated on
 * `manage_options` + a WordPress nonce (`wp_nonce_field`/`check_admin_referer`),
 * ending in a redirect.
 *
 * Connect:
 *   1. Admin clicks "Connect to mumcp.com" on the Setup page (a form POST to
 *      admin-post.php?action=mcpwp_broker_connect, carrying a wp_nonce_field).
 *   2. handle_connect() verifies capability + nonce, mints a random CSRF
 *      `state` token, stores it as a transient keyed to the current user id
 *      (self::STATE_TTL seconds), and 302s the browser to
 *      https://mumcp.com/enroll/start?site_url=..&state=..
 *   3. The broker relays `state` back unmodified on its 302 to this site's
 *      `/broker/enroll-callback` (see class-mcpwp-rest-broker.php) — which
 *      is what proves the browser hitting the callback is the SAME session
 *      that clicked Connect, not a cross-site forgery.
 *
 * Disconnect:
 *   1. Admin clicks "Disconnect" (form POST to
 *      admin-post.php?action=mcpwp_broker_disconnect, its own nonce field).
 *   2. handle_disconnect() verifies capability + nonce, deletes the local
 *      `mcpwp_broker_enrollment` option (the instant local kill-switch —
 *      Mcpwp_Broker_Auth::is_enrolled() goes false immediately, no network
 *      round trip required for the site to stop trusting broker tokens),
 *      then best-effort-notifies the broker so it can clean up its own
 *      records. The remote call's failure is deliberately ignored — the
 *      local kill-switch is what matters for this site's security posture.
 *
 * @package MCPWP
 * @since   3.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin-post handlers for the broker connect/disconnect browser legs.
 */
class Mcpwp_Broker_Admin {

	/**
	 * WP nonce action name for the Connect form.
	 *
	 * @var string
	 */
	const CONNECT_ACTION = 'mcpwp_broker_connect';

	/**
	 * WP nonce action name for the Disconnect form.
	 *
	 * @var string
	 */
	const DISCONNECT_ACTION = 'mcpwp_broker_disconnect';

	/**
	 * Shared nonce field name for both Connect and Disconnect forms.
	 *
	 * @var string
	 */
	const NONCE_FIELD = 'mcpwp_broker_nonce';

	/**
	 * mumcp.com's enrollment-start endpoint.
	 *
	 * @var string
	 */
	const BROKER_ENROLL_START_URL = 'https://mumcp.com/enroll/start';

	/**
	 * mumcp.com's best-effort disconnect-notify endpoint.
	 *
	 * @var string
	 */
	const BROKER_DISCONNECT_URL = 'https://mumcp.com/enroll/disconnect';

	// -------------------------------------------------------------------------
	// admin_post_mcpwp_broker_connect
	// -------------------------------------------------------------------------

	/**
	 * Start the mumcp.com broker enrollment handshake.
	 *
	 * @return void Always redirects or wp_die()s; never returns normally.
	 */
	public function handle_connect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mcpwp' ) );
		}

		check_admin_referer( self::CONNECT_ACTION, self::NONCE_FIELD );

		// wp_generate_password( $length, $special_chars = false ) yields an
		// alphanumeric-only token — safe to splice into a transient key and a
		// URL query param without further encoding concerns, though it is
		// still rawurlencode()'d below on principle.
		$state = wp_generate_password( 32, false );

		set_transient(
			Mcpwp_REST_Broker::STATE_TRANSIENT_PREFIX . $state,
			get_current_user_id(),
			Mcpwp_REST_Broker::STATE_TTL
		);

		$url = self::BROKER_ENROLL_START_URL
			. '?site_url=' . rawurlencode( home_url() )
			. '&state=' . rawurlencode( $state );

		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- external host by design; wp_safe_redirect() would reject mumcp.com as an unrecognized host.
		$this->terminate();
	}

	// -------------------------------------------------------------------------
	// admin_post_mcpwp_broker_disconnect
	// -------------------------------------------------------------------------

	/**
	 * Disconnect this site from the mumcp.com broker.
	 *
	 * @return void Always redirects or wp_die()s; never returns normally.
	 */
	public function handle_disconnect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mcpwp' ) );
		}

		check_admin_referer( self::DISCONNECT_ACTION, self::NONCE_FIELD );

		$enrollment = get_option( 'mcpwp_broker_enrollment', array() );
		$site_id    = ( is_array( $enrollment ) && ! empty( $enrollment['site_id'] ) ) ? (string) $enrollment['site_id'] : '';

		// Local kill-switch FIRST — this is what actually protects the site.
		// Mcpwp_Broker_Auth::is_enrolled() reads this same option, so broker
		// tokens stop being trusted the instant this line runs, independent
		// of whether the remote notify below succeeds.
		delete_option( 'mcpwp_broker_enrollment' );

		if ( '' !== $site_id ) {
			// Best-effort only — a failure here must never block the local
			// disconnect that already happened above.
			wp_remote_post(
				self::BROKER_DISCONNECT_URL,
				array(
					'timeout' => 5,
					'body'    => array( 'site_id' => $site_id ),
				)
			);
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => 'mcpwp',
					'broker' => 'disconnected',
				),
				admin_url( 'admin.php' )
			)
		);
		$this->terminate();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * End the request after a redirect.
	 *
	 * A thin wrapper around exit() so tests can override it (throwing a
	 * catchable signal) instead of the real exit() killing the PHPUnit
	 * process — mirrors the pattern documented in tests/BrokerLifecycleTest.php.
	 * Production behavior is unchanged: a real exit.
	 *
	 * @return void
	 */
	protected function terminate() {
		exit; // phpcs:ignore WordPress.PHP.NoSilencedErrors, Squiz.PHP.DiscouragedFunctions -- required after wp_redirect() in an admin_post_ handler.
	}
}
