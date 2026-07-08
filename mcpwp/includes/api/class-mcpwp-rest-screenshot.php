<?php
/**
 * Screenshot REST Controller
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Screenshot REST controller.
 */
class Mcpwp_REST_Screenshot extends Mcpwp_REST_API {

	/**
	 * Screenshot handler.
	 *
	 * @var Mcpwp_Screenshot
	 */
	private $screenshot;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->screenshot = new Mcpwp_Screenshot();
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/screenshot',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'take_screenshot' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'url'           => array(
							'description' => __( 'URL to screenshot.', 'mumcp' ),
							'type'        => 'string',
							'required'    => true,
							'format'      => 'uri',
						),
						'width'         => array(
							'description' => __( 'Screenshot width (320-1920).', 'mumcp' ),
							'type'        => 'integer',
							'default'     => 1280,
						),
						'height'        => array(
							'description' => __( 'Screenshot height (240-1440).', 'mumcp' ),
							'type'        => 'integer',
							'default'     => 960,
						),
						'save_to_media' => array(
							'description' => __( 'Also save screenshot to media library.', 'mumcp' ),
							'type'        => 'boolean',
							'default'     => false,
						),
						'inline'        => array(
							'description' => __( 'Return raw base64 image data in the response. Off by default: screenshots are saved to the media library and returned as a URL, because inline base64 (500KB+) overflows AI-client contexts.', 'mumcp' ),
							'type'        => 'boolean',
							'default'     => false,
						),
						'title'         => array(
							'description' => __( 'Title for saved media.', 'mumcp' ),
							'type'        => 'string',
						),
						'webhook_url'   => array(
							'description' => __( 'Webhook URL to notify when screenshot is ready (async mode).', 'mumcp' ),
							'type'        => 'string',
							'format'      => 'uri',
							'required'    => false,
						),
					),
				),
			)
		);
	}

	/**
	 * Take a screenshot.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function take_screenshot( $request ) {
		$this->log_activity( 'screenshot', $request );

		$url         = $request->get_param( 'url' );
		$webhook_url = $request->get_param( 'webhook_url' );
		$inline      = rest_sanitize_boolean( $request->get_param( 'inline' ) );
		$args        = array(
			'width'         => $request->get_param( 'width' ),
			'height'        => $request->get_param( 'height' ),
			// URL-by-default: without inline, the image must land in the
			// media library so the response can carry a URL instead of
			// 500KB+ of base64 (#560).
			'save_to_media' => $inline ? $request->get_param( 'save_to_media' ) : true,
			'title'         => $request->get_param( 'title' ),
		);

		$result = $this->screenshot->capture( $url, $args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Strip inline base64 when a media URL is available (default mode).
		if ( ! $inline && ! empty( $result['screenshot'] ) && ! empty( $result['media'] ) ) {
			unset( $result['screenshot'] );
			$result['note'] = __( 'Screenshot saved to the media library; base64 omitted. Pass inline=true for raw image data.', 'mumcp' );
		}

		// Async mode: webhook notification when screenshot is ready.
		if ( ! empty( $webhook_url ) ) {
			// Cloudflare returns base64 immediately — fire webhook now, no verification needed.
			if ( ! empty( $result['screenshot'] ) ) {
				$webhook_data = array(
					'url'       => $url,
					'status'    => 'ready',
					'service'   => 'cloudflare-browser',
					'format'    => isset( $result['format'] ) ? $result['format'] : 'png',
					'timestamp' => current_time( 'c' ),
				);

				if ( ! empty( $result['media'] ) ) {
					$webhook_data['media'] = $result['media'];
				}

				$this->screenshot->fire_screenshot_webhook( $webhook_url, $webhook_data );

				return $this->success_response(
					array(
						'status'  => 'ready',
						'service' => 'cloudflare-browser',
						'message' => __( 'Screenshot captured and webhook fired.', 'mumcp' ),
						'media'   => isset( $result['media'] ) ? $result['media'] : null,
					)
				);
			}

			// mshots fallback — schedule async verification.
			if ( ! empty( $result['screenshot_url'] ) ) {
				$this->screenshot->schedule_verification(
					$url,
					$result['screenshot_url'],
					$webhook_url,
					$args
				);

				return $this->success_response(
					array(
						'status'         => 'pending',
						'screenshot_url' => $result['screenshot_url'],
						'message'        => __( 'Screenshot queued. Webhook will fire when ready.', 'mumcp' ),
					)
				);
			}
		}

		// Sync mode: return screenshot URL immediately (original behavior).
		return $this->success_response( $result );
	}
}
