<?php
/**
 * Update-channel health — makes a broken update path visible on the site.
 *
 * The update channel is the only route a security release has to a live
 * install, and every way it breaks is silent from the site's point of view:
 * the plugin simply never reports an update. #716 sat undetected for exactly
 * that reason. This surfaces the state the updater already resolves — as a
 * Site Health test, an admin notice, and a field in the health payload agents
 * read — so the failure announces itself instead of waiting to be discovered.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mcpwp_Update_Channel_Health {

	/**
	 * Cached channel status.
	 *
	 * @var string
	 */
	const CACHE_KEY = 'mcpwp_update_channel_status';

	/**
	 * How long a healthy result stays cached, in seconds.
	 *
	 * @var int
	 */
	const CACHE_TTL_OK = 12 * HOUR_IN_SECONDS;

	/**
	 * How long a failure stays cached, in seconds.
	 *
	 * Shorter than a healthy result so a channel that comes back is noticed
	 * the same day — but not so short that a broken channel means an outbound
	 * HTTPS request on every admin page load. The updater does not cache
	 * failed fetches, so without this the notice alone would re-hit the
	 * network continuously.
	 *
	 * @var int
	 */
	const CACHE_TTL_FAIL = HOUR_IN_SECONDS;

	/**
	 * Reasons that mean the channel itself is broken.
	 *
	 * Anything not listed here (no reason at all, or 'already_current') means
	 * the channel answered — the site is either current or has an update
	 * waiting. Both are healthy.
	 *
	 * @var string[]
	 */
	const BROKEN_REASONS = array(
		'manifest_unavailable',
		'manifest_fetch_failed',
		'manifest_invalid',
		'package_host_not_trusted',
		'version_url_not_https',
	);

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! class_exists( 'Mcpwp_Updater' ) ) {
			// WordPress.org builds ship without the self-update channel; there
			// is nothing to be healthy or unhealthy about.
			return;
		}

		add_filter( 'site_status_tests', array( __CLASS__, 'register_site_status_test' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ) );
	}

	/**
	 * Discard the cached status.
	 *
	 * @return void
	 */
	public static function flush() {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Resolve the update-channel status, cached.
	 *
	 * @param bool $force Bypass the cache and re-check the channel.
	 * @return array{ok:bool,reason:?string,message:string,current_version:?string,available_version:?string,source:?string,checked_at:int}
	 */
	public static function get_status( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) && isset( $cached['ok'] ) ) {
				return $cached;
			}
		}

		if ( ! class_exists( 'Mcpwp_Updater' ) ) {
			return array(
				'ok'                => true,
				'reason'            => 'not_applicable',
				'message'           => __( 'This build does not use the self-hosted update channel.', 'mcpwp' ),
				'current_version'   => defined( 'MCPWP_VERSION' ) ? MCPWP_VERSION : null,
				'available_version' => null,
				'source'            => null,
				'checked_at'        => time(),
			);
		}

		$state     = Mcpwp_Updater::instance()->get_update_state( $force );
		$reason    = isset( $state['reason'] ) ? $state['reason'] : null;
		$broken    = in_array( $reason, self::BROKEN_REASONS, true );
		$available = ! empty( $state['update_available'] );

		$message = $available
			? sprintf(
				/* translators: %s: plugin version number. */
				__( 'The update channel is offering %s.', 'mcpwp' ),
				(string) $state['selected_version']
			)
			: Mcpwp_Updater::describe_reason( $reason );

		$status = array(
			'ok'                => ! $broken,
			'reason'            => $reason,
			'message'           => $message,
			'current_version'   => isset( $state['current_version'] ) ? $state['current_version'] : null,
			'available_version' => ! empty( $state['update_available'] ) ? $state['selected_version'] : null,
			'source'            => isset( $state['source'] ) ? $state['source'] : null,
			'checked_at'        => time(),
		);

		set_transient(
			self::CACHE_KEY,
			$status,
			$broken ? self::CACHE_TTL_FAIL : self::CACHE_TTL_OK
		);

		return $status;
	}

	/**
	 * Add the update-channel test to WordPress Site Health.
	 *
	 * @param array $tests Registered Site Health tests.
	 * @return array Tests with ours added.
	 */
	public static function register_site_status_test( $tests ) {
		if ( ! is_array( $tests ) ) {
			return $tests;
		}

		$tests['direct']['mcpwp_update_channel'] = array(
			'label' => __( 'MCPWP can receive updates', 'mcpwp' ),
			'test'  => array( __CLASS__, 'run_site_status_test' ),
		);

		return $tests;
	}

	/**
	 * Site Health test result.
	 *
	 * @return array Site Health result array.
	 */
	public static function run_site_status_test() {
		$status = self::get_status();

		$result = array(
			'label'       => __( 'MCPWP can receive updates', 'mcpwp' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Security', 'mcpwp' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html( $status['message'] ) . '</p>',
			'actions'     => '',
			'test'        => 'mcpwp_update_channel',
		);

		if ( $status['ok'] ) {
			if ( ! empty( $status['available_version'] ) ) {
				$result['label'] = sprintf(
					/* translators: %s: plugin version number. */
					__( 'MCPWP %s is available', 'mcpwp' ),
					$status['available_version']
				);
				$result['description'] = '<p>' . sprintf(
					/* translators: 1: installed version, 2: available version. */
					esc_html__( 'This site runs MCPWP %1$s and the update channel is offering %2$s.', 'mcpwp' ),
					esc_html( (string) $status['current_version'] ),
					esc_html( (string) $status['available_version'] )
				) . '</p>';
			}

			return $result;
		}

		$result['status']       = 'critical';
		$result['label']        = __( 'MCPWP cannot receive updates', 'mcpwp' );
		$result['badge']['color'] = 'red';
		$result['description']  = '<p>' . esc_html( $status['message'] ) . '</p><p>'
			. esc_html__( 'While this is unresolved the plugin cannot install any release, including security releases.', 'mcpwp' )
			. '</p>';

		return $result;
	}

	/**
	 * Show a dismissible-free admin notice when the channel is broken.
	 *
	 * Deliberately not dismissible: the condition it reports blocks security
	 * updates and resolves itself the moment the channel works again.
	 *
	 * @return void
	 */
	public static function render_notice() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$slug   = ( $screen && isset( $screen->id ) ) ? (string) $screen->id : '';

		// Plugin screens and the MCPWP admin pages — enough to be seen without
		// following an admin around the whole dashboard.
		$relevant = ( 'plugins' === $slug || 'update-core' === $slug || false !== strpos( $slug, 'mcpwp' ) );
		if ( ! $relevant ) {
			return;
		}

		$status = self::get_status();
		if ( $status['ok'] ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'MCPWP cannot receive updates.', 'mcpwp' ),
			esc_html( $status['message'] )
		);
	}
}
