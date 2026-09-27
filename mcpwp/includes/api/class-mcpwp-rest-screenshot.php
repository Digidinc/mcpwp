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
							'description' => __( 'URL to screenshot.', 'mcpwp' ),
							'type'        => 'string',
							'required'    => true,
							'format'      => 'uri',
						),
						'width'         => array(
							'description' => __( 'Screenshot width (320-1920).', 'mcpwp' ),
							'type'        => 'integer',
							'default'     => 1280,
						),
						'height'        => array(
							'description' => __( 'Screenshot height (240-1440).', 'mcpwp' ),
							'type'        => 'integer',
							'default'     => 960,
						),
						'save_to_media' => array(
							'description' => __( 'Also save screenshot to media library.', 'mcpwp' ),
							'type'        => 'boolean',
							'default'     => false,
						),
						'inline'        => array(
							'description' => __( 'Return raw base64 image data in the response. Off by default: screenshots are saved to the media library and returned as a URL, because inline base64 (500KB+) overflows AI-client contexts.', 'mcpwp' ),
							'type'        => 'boolean',
							'default'     => false,
						),
						'title'         => array(
							'description' => __( 'Title for saved media.', 'mcpwp' ),
							'type'        => 'string',
						),
						'webhook_url'   => array(
							'description' => __( 'Webhook URL to notify when screenshot is ready (async mode).', 'mcpwp' ),
							'type'        => 'string',
							'format'      => 'uri',
							'required'    => false,
						),
						'full_page'     => array(
							'description' => __( 'Capture the full scrollable page height instead of just the viewport. Honored by the Cloudflare Browser Rendering worker when configured; the mshots fallback has no full-page mode and the response says so instead of silently ignoring it.', 'mcpwp' ),
							'type'        => 'boolean',
							'default'     => false,
						),
						'widths'        => array(
							'description' => __( 'Capture at several viewport widths in one call, e.g. [390,767,1024,1440] for a responsive sweep. height applies to every width. Max 6 widths. When set, the response carries "screenshots" (one result per width) instead of a single result, and webhook_url is not supported together with widths.', 'mcpwp' ),
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
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
		$full_page   = rest_sanitize_boolean( $request->get_param( 'full_page' ) );
		$widths      = $request->get_param( 'widths' );

		// Only DEFAULT save_to_media when the caller didn't pass it at all —
		// never clobber an explicit save_to_media=false (#677). get_params()
		// reflects what the caller actually sent, unlike get_param(), which
		// would report the schema default even when the param was absent.
		$save_to_media_supplied = array_key_exists( 'save_to_media', $request->get_params() );
		if ( $save_to_media_supplied ) {
			$save_to_media = $request->get_param( 'save_to_media' );
		} else {
			// URL-by-default: without inline, the image must land in the
			// media library so the response can carry a URL instead of
			// 500KB+ of base64 (#560).
			$save_to_media = ! $inline;
		}

		// Multi-width sweep (#804): one call, one result per breakpoint. The
		// async/webhook path below fires a single webhook for a single
		// mshots URL and doesn't map onto "N screenshots", so it's refused
		// rather than silently only covering one width.
		if ( is_array( $widths ) && ! empty( $widths ) ) {
			if ( ! empty( $webhook_url ) ) {
				return $this->error_response(
					'incompatible_params',
					__( 'widths and webhook_url cannot be combined — request each width separately for async mode.', 'mcpwp' ),
					400
				);
			}

			return $this->success_response(
				$this->capture_multi_width( $url, $widths, $request->get_param( 'height' ), $save_to_media, $request->get_param( 'title' ), $full_page, $inline )
			);
		}

		$args = array(
			'width'         => $request->get_param( 'width' ),
			'height'        => $request->get_param( 'height' ),
			'save_to_media' => $save_to_media,
			'title'         => $request->get_param( 'title' ),
			'full_page'     => $full_page,
		);

		$result = $this->screenshot->capture( $url, $args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Strip inline base64 when a media URL is available (default mode).
		if ( ! $inline && ! empty( $result['screenshot'] ) && ! empty( $result['media'] ) ) {
			unset( $result['screenshot'] );
			$result['note'] = __( 'Screenshot saved to the media library; base64 omitted. Pass inline=true for raw image data.', 'mcpwp' );
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
						'message' => __( 'Screenshot captured and webhook fired.', 'mcpwp' ),
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
						'message'        => __( 'Screenshot queued. Webhook will fire when ready.', 'mcpwp' ),
					)
				);
			}
		}

		// Sync mode: return screenshot URL immediately (original behavior).
		return $this->success_response( $result );
	}

	/**
	 * Capture the same URL at several viewport widths in one call (#804).
	 *
	 * Each width is captured independently through Mcpwp_Screenshot::capture()
	 * — the same code path a single-width call uses, including the mshots
	 * honesty check — so a bad width never blocks the others; its failure is
	 * reported per-entry instead of failing the whole sweep.
	 *
	 * @param string      $url           URL to screenshot.
	 * @param array       $widths        Requested widths.
	 * @param int|null    $height        Viewport height, applied to every width.
	 * @param bool        $save_to_media Whether to save each capture to the media library.
	 * @param string|null $title         Title for saved media.
	 * @param bool        $full_page     Whether to request full-page capture.
	 * @param bool        $inline        Whether to keep raw base64 in each result.
	 * @return array{url:string,widths:array<int>,screenshots:array}
	 */
	private function capture_multi_width( $url, array $widths, $height, $save_to_media, $title, $full_page, $inline ) {
		// Cap the sweep — a responsive check needs a handful of breakpoints,
		// not an open-ended list that turns one call into dozens of outbound
		// renders.
		$widths = array_slice( array_values( array_unique( array_map( 'absint', $widths ) ) ), 0, 6 );

		$screenshots = array();

		foreach ( $widths as $width ) {
			$one = $this->screenshot->capture(
				$url,
				array(
					'width'         => $width,
					'height'        => $height,
					'save_to_media' => $save_to_media,
					'title'         => $title,
					'full_page'     => $full_page,
				)
			);

			if ( is_wp_error( $one ) ) {
				$screenshots[] = array(
					'width'   => $width,
					'success' => false,
					'error'   => $one->get_error_message(),
					'code'    => $one->get_error_code(),
				);
				continue;
			}

			if ( ! $inline && ! empty( $one['screenshot'] ) && ! empty( $one['media'] ) ) {
				unset( $one['screenshot'] );
				$one['note'] = __( 'Screenshot saved to the media library; base64 omitted. Pass inline=true for raw image data.', 'mcpwp' );
			}

			$one['width']  = $width;
			$screenshots[] = $one;
		}

		return array(
			'url'         => $url,
			'widths'      => $widths,
			'screenshots' => $screenshots,
		);
	}
}
