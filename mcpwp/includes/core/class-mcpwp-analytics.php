<?php
/**
 * Analytics gateway — sends anonymous usage events to PostHog.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles server-side analytics via PostHog HTTP Capture API.
 *
 * distinct_id is a random site UUID — never the site URL or any PII.
 * All error codes are enum strings — no customer content is ever captured.
 */
class Mcpwp_Analytics {

	/**
	 * wp_options key for the persistent site UUID.
	 */
	const UUID_OPTION = 'mcpwp_site_uuid';

	/**
	 * Set once an administrator dismisses the legacy-opt-in notice.
	 */
	const LEGACY_NOTICE_DISMISSED_OPTION = 'mcpwp_analytics_legacy_notice_dismissed';

	/**
	 * admin-post action (and nonce action) that dismisses it.
	 */
	const LEGACY_NOTICE_ACTION = 'mcpwp_dismiss_analytics_notice';

	/**
	 * Whether analytics is enabled for this site.
	 *
	 * Free tier: requires an explicit opt-in — a stored analytics_enabled true
	 * recorded as analytics_choice = explicit (see has_explicit_consent()).
	 * Paid tier: defaults to true unless analytics_enabled is stored false.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$settings = get_option( 'mcpwp_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		// A stored off always wins — free or paid.
		if ( array_key_exists( 'analytics_enabled', $settings ) && ! $settings['analytics_enabled'] ) {
			return false;
		}

		// Paid sites: default to enabled when not explicitly set.
		if ( self::is_paying_site() ) {
			return true;
		}

		// Free sites: only consent the admin gave.
		return self::has_explicit_consent( $settings );
	}

	/**
	 * Whether the stored settings record an explicit analytics opt-in.
	 *
	 * A true alone is not consent: a paid plan's default carried into Free, a
	 * cloned database, a programmatic write — or, before v3.4.2, any API key
	 * writing the option through the generic option tool — can all leave one
	 * behind (reviews r1 P2-A, r2 N1). Consent is a true the settings form
	 * recorded as a change the admin made (analytics_choice = explicit). A
	 * true stored before 3.11.1 is labelled legacy and is not consent.
	 *
	 * @param array $settings mcpwp_settings.
	 * @return bool
	 */
	public static function has_explicit_consent( array $settings ) {
		return array_key_exists( 'analytics_enabled', $settings )
			&& in_array( $settings['analytics_enabled'], array( true, 1, '1' ), true )
			&& isset( $settings['analytics_choice'] )
			&& 'explicit' === $settings['analytics_choice'];
	}

	/**
	 * Whether Freemius reports a paying licence.
	 *
	 * @return bool
	 */
	private static function is_paying_site() {
		$fs = function_exists( 'mcpwp_get_fs_instance' ) ? mcpwp_get_fs_instance() : null;
		return $fs && method_exists( $fs, 'is_paying' ) && $fs->is_paying();
	}

	/**
	 * PostHog settings for the admin pages' browser script.
	 *
	 * The token is handed out only when is_enabled() — the one decision
	 * server-side capture uses — so wp-admin never loads PostHog on a site
	 * that has not consented (review r2 N3).
	 *
	 * @param array|null $posthog Mcpwp_Integration_Manager::get_posthog_config(); read when null.
	 * @return array{token: string, host: string}
	 */
	public static function browser_config( $posthog = null ) {
		if ( ! self::is_enabled() ) {
			return array( 'token' => '', 'host' => '' );
		}
		if ( null === $posthog ) {
			$posthog = class_exists( 'Mcpwp_Integration_Manager' ) ? Mcpwp_Integration_Manager::get_instance()->get_posthog_config() : array();
		}
		$posthog = is_array( $posthog ) ? $posthog : array();

		return array(
			'token' => isset( $posthog['token'] ) ? (string) $posthog['token'] : '',
			'host'  => isset( $posthog['host'] ) ? (string) $posthog['host'] : '',
		);
	}

	/**
	 * Whether a Free site holds an analytics opt-in that predates recorded
	 * consent (analytics_choice = legacy) and nobody has dismissed the notice.
	 *
	 * @return bool
	 */
	public static function needs_legacy_confirmation() {
		$settings = get_option( 'mcpwp_settings', array() );
		if ( ! is_array( $settings ) || ! isset( $settings['analytics_choice'] ) || 'legacy' !== $settings['analytics_choice'] ) {
			return false;
		}
		if ( ! array_key_exists( 'analytics_enabled', $settings ) || ! in_array( $settings['analytics_enabled'], array( true, 1, '1' ), true ) ) {
			return false;
		}
		if ( self::is_paying_site() ) {
			return false;
		}
		return ! get_option( self::LEGACY_NOTICE_DISMISSED_OPTION );
	}

	/**
	 * admin_notices: ask an administrator of a Free site with a legacy opt-in
	 * to confirm the choice. It carries no site data.
	 */
	public static function render_legacy_consent_notice() {
		if ( ! current_user_can( 'manage_options' ) || ! self::needs_legacy_confirmation() ) {
			return;
		}

		$settings_url = admin_url( 'admin.php?page=mcpwp-settings' );
		$dismiss_url  = add_query_arg(
			array(
				'action'   => self::LEGACY_NOTICE_ACTION,
				'_wpnonce' => wp_create_nonce( self::LEGACY_NOTICE_ACTION ),
			),
			admin_url( 'admin-post.php' )
		);

		printf(
			'<div class="notice notice-warning"><p>%1$s</p><p><a class="button button-primary" href="%2$s">%3$s</a> <a class="button" href="%4$s">%5$s</a></p></div>',
			esc_html__( 'MCPWP anonymous usage analytics were switched on by an earlier version in a way that cannot be confirmed as an administrator\'s choice, so they are now off on this site. To share anonymous tool-usage data, turn it on in MCPWP Settings.', 'mcpwp' ),
			esc_url( $settings_url ),
			esc_html__( 'Review in Settings', 'mcpwp' ),
			esc_url( $dismiss_url ),
			esc_html__( 'Dismiss', 'mcpwp' )
		);
	}

	/**
	 * Dismiss the legacy-opt-in notice for this site.
	 *
	 * @param string $nonce Nonce for LEGACY_NOTICE_ACTION.
	 * @return bool Whether it was dismissed.
	 */
	public static function dismiss_legacy_notice( $nonce ) {
		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( (string) $nonce, self::LEGACY_NOTICE_ACTION ) ) {
			return false;
		}
		update_option( self::LEGACY_NOTICE_DISMISSED_OPTION, 1, false );
		return true;
	}

	/**
	 * admin-post handler for the notice's Dismiss link.
	 */
	public static function handle_dismiss_legacy_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified in dismiss_legacy_notice().
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! self::dismiss_legacy_notice( $nonce ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'mcpwp' ), '', array( 'response' => 403 ) );
		}
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url( 'admin.php?page=mcpwp-settings' ) );
		exit;
	}

	/**
	 * Get or create the persistent site UUID.
	 *
	 * The UUID is prefixed with 'mcpwp-' so it is recognisable in PostHog.
	 * It is stored in wp_options and never changes after first generation.
	 *
	 * @return string
	 */
	public static function get_site_uuid() {
		$uuid = get_option( self::UUID_OPTION, '' );
		if ( empty( $uuid ) ) {
			$uuid = 'mcpwp-' . wp_generate_uuid4();
			update_option( self::UUID_OPTION, $uuid, false );
		}
		return $uuid;
	}

	/**
	 * Send an event to PostHog. Fire-and-forget — never throws, never blocks.
	 *
	 * @param string $event      PostHog event name.
	 * @param array  $properties Event properties (must not contain PII or customer content).
	 */
	public static function capture( $event, array $properties = array() ) {
		if ( ! self::is_enabled() ) {
			return;
		}

		$config = Mcpwp_Integration_Manager::get_instance()->get_posthog_config();
		if ( empty( $config['token'] ) ) {
			return;
		}

		$payload = array(
			'api_key'     => $config['token'],
			'event'       => $event,
			'distinct_id' => self::get_site_uuid(),
			'properties'  => array_merge(
				array(
					'plugin_version' => defined( 'MCPWP_VERSION' ) ? MCPWP_VERSION : '',
					'wp_version'     => get_bloginfo( 'version' ),
					'php_version'    => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
					'$lib'           => 'mcpwp-php',
				),
				$properties
			),
		);

		try {
			wp_remote_post(
				rtrim( $config['host'], '/' ) . '/capture/',
				array(
					'blocking'    => false,
					'timeout'     => 5,
					'headers'     => array( 'Content-Type' => 'application/json' ),
					'body'        => wp_json_encode( $payload ),
					'data_format' => 'body',
				)
			);
		} catch ( Exception $e ) {
			// Analytics must never affect tool execution.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'MCPWP Analytics: capture failed — ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Hook subscriber for mcpwp_tool_called action.
	 *
	 * @param string $tool_name   MCP tool name.
	 * @param string $category    Tool category slug.
	 * @param int    $duration_ms Execution time in milliseconds.
	 * @param string $error_code  Enum error code, empty string on success.
	 */
	public static function on_tool_called( $tool_name, $category, $duration_ms, $error_code ) {
		self::capture(
			'mcp_tool_called',
			array(
				'tool'        => (string) $tool_name,
				'category'    => (string) $category,
				// A replay is the feature's happy path, not a failed call: it
				// must not poison the tool error rate (#802 round 4).
				'success'     => in_array( (string) $error_code, array( '', 'replayed' ), true ),
				'duration_ms' => (int) $duration_ms,
				'error_code'  => (string) $error_code,
			)
		);
	}
}
