<?php
/**
 * Analytics gateway — sends anonymous usage events to PostHog.
 *
 * @package mumcp
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
	 * Whether analytics is enabled for this site.
	 *
	 * Free tier: requires explicit opt-in via mcpwp_settings['analytics_enabled'].
	 * Paid tier: defaults to true unless the user has explicitly set the flag to false.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$settings = get_option( 'mcpwp_settings', array() );

		// null = not yet explicitly set; false = explicitly disabled; true = explicitly enabled.
		$explicit = array_key_exists( 'analytics_enabled', $settings )
			? (bool) $settings['analytics_enabled']
			: null;

		// Explicit disable always wins — free or paid.
		if ( false === $explicit ) {
			return false;
		}

		// Paid sites: default to enabled when not explicitly set.
		$fs = function_exists( 'mcpwp_get_fs_instance' ) ? mcpwp_get_fs_instance() : null;
		if ( $fs && method_exists( $fs, 'is_paying' ) && $fs->is_paying() ) {
			return true;
		}

		// Free sites: only if explicitly enabled.
		return true === $explicit;
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
				error_log( 'mumcp Analytics: capture failed — ' . $e->getMessage() );
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
				'success'     => '' === $error_code,
				'duration_ms' => (int) $duration_ms,
				'error_code'  => (string) $error_code,
			)
		);
	}
}
