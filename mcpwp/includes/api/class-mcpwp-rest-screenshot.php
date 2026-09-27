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
							'description' => __( 'Capture the full scrollable page height instead of just the viewport. Honored by the Cloudflare Browser Rendering worker when configured; the mshots fallback has no full-page mode and the response says so instead of silently ignoring it. Cannot be combined with inline+widths together (memory).', 'mcpwp' ),
							'type'        => 'boolean',
							'default'     => false,
						),
						'widths'        => array(
							'description' => __( 'Capture at several viewport widths (320-1920, clamped) in one call, e.g. [390,767,1024,1440] for a responsive sweep. height applies to every width. Max 6 widths. Returns "screenshots" (one result per width) plus succeeded/failed counts instead of a single result; if every width fails the call itself fails. webhook_url and inline+full_page are not supported together with widths.', 'mcpwp' ),
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

			// #804 W5: inline + widths + full_page is the worst-case memory
			// shape — several full-scroll captures, each returned as raw
			// base64 in the same response, none of them offloaded to the
			// media library. Refused rather than silently accepted and
			// discovered as a memory problem later.
			if ( $inline && $full_page ) {
				return $this->error_response(
					'incompatible_params',
					__( 'inline cannot be combined with both widths and full_page — several full-page captures returned as raw base64 in one response can exhaust memory. Drop inline (screenshots then save to the media library and return URLs), request a single width, or turn off full_page.', 'mcpwp' ),
					400
				);
			}

			$result = $this->capture_multi_width( $url, $widths, $request->get_param( 'height' ), $save_to_media, $request->get_param( 'title' ), $full_page, $inline );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return $this->success_response( $result );
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
	 * Delegates to Mcpwp_Screenshot::capture_many(), which probes mshots
	 * reachability at most once for the whole sweep rather than once per
	 * width (W2). A bad width never blocks the others — its failure is
	 * reported per-entry — but a sweep where EVERY width failed is refused
	 * as a whole WP_Error (W3), so an MCP caller gets isError instead of a
	 * 200 whose "success" envelope hides an all-failed screenshots array.
	 *
	 * @param string      $url           URL to screenshot.
	 * @param array       $widths        Requested widths.
	 * @param int|null    $height        Viewport height, applied to every width.
	 * @param bool        $save_to_media Whether to save each capture to the media library.
	 * @param string|null $title         Title for saved media.
	 * @param bool        $full_page     Whether to request full-page capture.
	 * @param bool        $inline        Whether to keep raw base64 in each result.
	 * @return array{url:string,widths:array<int>,screenshots:array,succeeded:int,failed:int}|WP_Error
	 */
	private function capture_multi_width( $url, array $widths, $height, $save_to_media, $title, $full_page, $inline ) {
		// #804 NIT-3 / N-R2-2: clamp each width into the same 320-1920 range
		// a single-width call enforces BEFORE de-duplicating and capping at
		// 6. Deduping first would silently collapse two different
		// requested widths (e.g. 200 and 300, both clamped to 320) into one
		// entry with no sign to the caller that anything was dropped.
		//
		// This exact pipeline also has to be what Mcpwp_Operation_Weights
		// prices (spend_weight()) — so it lives there as
		// effective_screenshot_widths(), called from both places, instead
		// of being duplicated here and risking the two silently disagreeing
		// about how many renders a sweep actually does.
		$widths = class_exists( 'Mcpwp_Operation_Weights' )
			? Mcpwp_Operation_Weights::effective_screenshot_widths( $widths )
			: $this->clamp_dedupe_cap_widths_fallback( $widths );

		$captures = $this->screenshot->capture_many(
			$url,
			$widths,
			array(
				'height'        => $height,
				'save_to_media' => $save_to_media,
				'title'         => $title,
				'full_page'     => $full_page,
			)
		);

		$screenshots = array();
		$wp_errors   = array();
		$succeeded   = 0;
		$failed      = 0;

		foreach ( $widths as $width ) {
			$one = isset( $captures[ $width ] )
				? $captures[ $width ]
				: new WP_Error( 'capture_missing', __( 'No result was produced for this width.', 'mcpwp' ) );

			if ( is_wp_error( $one ) ) {
				++$failed;
				$wp_errors[]   = $one;
				$screenshots[] = array(
					'width'   => $width,
					'success' => false,
					'error'   => $one->get_error_message(),
					'code'    => $one->get_error_code(),
				);
				continue;
			}

			++$succeeded;

			if ( ! $inline && ! empty( $one['screenshot'] ) && ! empty( $one['media'] ) ) {
				unset( $one['screenshot'] );
				$one['note'] = __( 'Screenshot saved to the media library; base64 omitted. Pass inline=true for raw image data.', 'mcpwp' );
			}

			$one['width']   = $width;
			$one['success'] = true;
			$screenshots[]  = $one;
		}

		// #804 W3: a sweep where nothing succeeded did not do what it was
		// asked, and an MCP client needs isError to react to that — not a
		// 200 that a caller has to inspect screenshots[] to discover failed.
		if ( 0 === $succeeded && $failed > 0 ) {
			// #804 W-R2-3: when every width failed for the SAME underlying
			// reason — most commonly the URL itself was invalid/SSRF-
			// blocked, validated once up front by capture_many() before any
			// width ran, so every entry carries the identical error — pass
			// that real code/status straight through instead of masking it
			// behind a generic screenshot_sweep_failed/502. An SSRF-blocked
			// URL should come back as ssrf_blocked/403, not a 502 that hides
			// why nothing ran.
			$uniform_error = $this->uniform_error( $wp_errors );
			if ( null !== $uniform_error ) {
				return $uniform_error;
			}

			return new WP_Error(
				'screenshot_sweep_failed',
				sprintf(
					/* translators: %d: number of widths requested */
					__( 'All %d widths in this sweep failed. See screenshots[].error for each.', 'mcpwp' ),
					$failed
				),
				array(
					'status'      => 502,
					'widths'      => $widths,
					'screenshots' => $screenshots,
				)
			);
		}

		return array(
			'url'         => $url,
			'widths'      => $widths,
			'screenshots' => $screenshots,
			'succeeded'   => $succeeded,
			'failed'      => $failed,
		);
	}

	/**
	 * When every entry in $errors carries the same error code, that one
	 * WP_Error — with its own real status/message/data — else null (#804
	 * W-R2-3).
	 *
	 * @param WP_Error[] $errors One error per failed width, same order.
	 * @return WP_Error|null
	 */
	private function uniform_error( array $errors ) {
		if ( empty( $errors ) ) {
			return null;
		}

		$first_code = $errors[0]->get_error_code();

		foreach ( $errors as $error ) {
			if ( $error->get_error_code() !== $first_code ) {
				return null;
			}
		}

		return $errors[0];
	}

	/**
	 * Fallback copy of the clamp(320-1920) -> dedupe -> cap(6) pipeline
	 * Mcpwp_Operation_Weights::effective_screenshot_widths() implements —
	 * used only if that class somehow isn't loaded (defensive; it always
	 * is, in production and in the shared test bootstrap). Kept identical
	 * to that method on purpose; a real fix belongs in one place, not two
	 * copies that can drift.
	 *
	 * @param array $widths Raw widths.
	 * @return array<int,int>
	 */
	private function clamp_dedupe_cap_widths_fallback( array $widths ) {
		$clamped = array_map(
			static function ( $w ) {
				return max( 320, min( 1920, absint( $w ) ) );
			},
			$widths
		);

		return array_slice( array_values( array_unique( $clamped ) ), 0, 6 );
	}
}
