<?php
/**
 * Screenshot handler
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handle screenshot operations.
 *
 * Primary: Cloudflare Browser Rendering Worker (real headless Chromium).
 * Fallback: WordPress.com mshots service.
 */
class Mcpwp_Screenshot {

	use Mcpwp_Sanitization;

	/**
	 * mshots service base URL.
	 *
	 * @var string
	 */
	private $mshots_base = 'https://s0.wp.com/mshots/v1/';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'mcpwp_verify_screenshot', array( $this, 'handle_verification_cron' ), 10, 5 );
	}

	/**
	 * Get the Cloudflare screenshot worker config.
	 *
	 * Checks Integration Manager first, then falls back to legacy mcpwp_settings.
	 *
	 * @return array|false Array with 'url' and 'token' keys, or false if not configured.
	 */
	private function get_cf_worker_config() {
		// Try Integration Manager first.
		if ( class_exists( 'Mcpwp_Integration_Manager' ) ) {
			$manager = Mcpwp_Integration_Manager::get_instance();
			$config  = $manager->get_provider_config( 'screenshot' );
			if ( $config && ! empty( $config['url'] ) ) {
				return array(
					'url'   => rtrim( $config['url'], '/' ),
					'token' => isset( $config['token'] ) ? $config['token'] : '',
				);
			}
		}

		// Fallback to legacy mcpwp_settings for backward compatibility.
		$settings     = get_option( 'mcpwp_settings', array() );
		$worker_url   = isset( $settings['screenshot_worker_url'] ) ? $settings['screenshot_worker_url'] : '';
		$worker_token = isset( $settings['screenshot_worker_token'] ) ? $settings['screenshot_worker_token'] : '';

		if ( empty( $worker_url ) ) {
			return false;
		}

		return array(
			'url'   => rtrim( $worker_url, '/' ),
			'token' => $worker_token,
		);
	}

	/**
	 * Take a screenshot via Cloudflare Browser Rendering Worker.
	 *
	 * @param string $url    URL to screenshot.
	 * @param int    $width  Viewport width.
	 * @param int    $height Viewport height.
	 * @param array  $args   Additional options: wait, full_page, format, quality.
	 * @return array|WP_Error Screenshot data with base64 image, or error.
	 */
	private function capture_via_cloudflare( $url, $width, $height, $args = array() ) {
		$config = $this->get_cf_worker_config();
		if ( ! $config ) {
			return new WP_Error( 'no_worker', 'Cloudflare screenshot worker not configured.' );
		}

		$body = array(
			'url'    => $url,
			'width'  => $width,
			'height' => $height,
			'wait'   => isset( $args['wait'] ) ? absint( $args['wait'] ) : 2000,
		);

		if ( ! empty( $args['full_page'] ) ) {
			$body['full_page'] = true;
		}

		$headers = array(
			'Content-Type' => 'application/json',
		);

		if ( ! empty( $config['token'] ) ) {
			$headers['X-Auth-Token'] = $config['token'];
		}

		$response = wp_remote_post(
			$config['url'],
			array(
				'timeout' => 30,
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 || empty( $data['success'] ) ) {
			$message = isset( $data['error'] ) ? $data['error'] : 'Worker returned status ' . $code;
			if ( isset( $data['message'] ) ) {
				$message .= ': ' . $data['message'];
			}
			return new WP_Error( 'worker_error', $message );
		}

		return $data;
	}

	/**
	 * Take a screenshot of a URL.
	 *
	 * Tries Cloudflare Browser Rendering first, falls back to mshots.
	 *
	 * @param string $url    URL to screenshot.
	 * @param array  $args   Options: width, height, save_to_media, title, wait, full_page.
	 * @return array|WP_Error Screenshot data or error.
	 */
	public function capture( $url, $args = array() ) {
		$url = esc_url_raw( $url );
		if ( empty( $url ) ) {
			return new WP_Error(
				'invalid_url',
				__( 'A valid URL is required.', 'mcpwp' ),
				array( 'status' => 400 )
			);
		}

		// SSRF protection.
		if ( class_exists( 'Mcpwp_Security' ) ) {
			$ssrf_check = Mcpwp_Security::validate_external_url( $url );
			if ( is_wp_error( $ssrf_check ) ) {
				return $ssrf_check;
			}
		}

		$width  = isset( $args['width'] ) ? absint( $args['width'] ) : 1280;
		$height = isset( $args['height'] ) ? absint( $args['height'] ) : 960;

		// Clamp dimensions.
		$width  = max( 320, min( 1920, $width ) );
		$height = max( 240, min( 1440, $height ) );

		// Try Cloudflare Browser Rendering first.
		$cf_result = $this->capture_via_cloudflare( $url, $width, $height, $args );

		if ( ! is_wp_error( $cf_result ) && ! empty( $cf_result['screenshot'] ) ) {
			$result = array(
				'success'    => true,
				'url'        => $url,
				'screenshot' => $cf_result['screenshot'],
				'format'     => isset( $cf_result['format'] ) ? $cf_result['format'] : 'png',
				'width'      => $width,
				'height'     => $height,
				'elapsed_ms' => isset( $cf_result['elapsed_ms'] ) ? $cf_result['elapsed_ms'] : null,
				'service'    => 'cloudflare-browser',
			);

			if ( ! empty( $args['full_page'] ) ) {
				// #804 NIT-2: report what the worker actually did, not what we
				// asked for. The worker echoes `full_page` back in its
				// response; a worker that doesn't (an older deploy, or a
				// self-hosted one that doesn't echo it) leaves this
				// genuinely unknown rather than a guessed true.
				$result['full_page_honored'] = array_key_exists( 'full_page', $cf_result )
					? (bool) $cf_result['full_page']
					: 'unknown';
			}

			// Save to media library if requested.
			if ( ! empty( $args['save_to_media'] ) ) {
				$cf_format = isset( $cf_result['format'] ) ? $cf_result['format'] : 'png';
				$saved = $this->save_base64_to_media( $cf_result['screenshot'], $url, $args, $cf_format );
				if ( ! is_wp_error( $saved ) ) {
					$result['media'] = $saved;
				} else {
					$result['media_error'] = $saved->get_error_message();
				}
			}

			return $result;
		}

		// Fallback: mshots.
		$screenshot_url = $this->mshots_base . rawurlencode( $url ) . '?w=' . $width . '&h=' . $height;

		// mshots can refuse this request outright, or be down/rate-limiting,
		// rather than merely being slow to generate. Claiming "success" for a
		// URL that will never render anything but a broken-image icon is
		// exactly the dead end #804 reported — one quick probe here catches
		// that before this method ever says success, instead of leaving the
		// caller to discover it only when they try to view the image. A
		// "still generating" placeholder is a normal 200/202 and is not
		// mistaken for a failure; that case is unchanged below.
		//
		// capture_many() probes once for a whole widths sweep (the answer
		// does not depend on viewport size) and passes it down via
		// _mshots_probe_status; a direct capture() call probes for itself.
		$probe_status = array_key_exists( '_mshots_probe_status', $args )
			? $args['_mshots_probe_status']
			: $this->probe_mshots_status( $screenshot_url );

		$mshots_failure = $this->classify_mshots_probe( $probe_status, $cf_result );
		if ( is_wp_error( $mshots_failure ) ) {
			return $mshots_failure;
		}

		$result = array(
			'success'        => true,
			'url'            => $url,
			'screenshot_url' => $screenshot_url,
			'width'          => $width,
			'height'         => $height,
			'service'        => 'wordpress-mshots',
			'note'           => 'First request triggers generation. Screenshot may take 10-30 seconds to appear. Retry the URL if you get a placeholder.',
		);

		// mshots is a fixed-viewport service — it has no full-page equivalent,
		// so a caller who asked for full_page needs to know it silently did
		// not happen here rather than assume the returned image is the whole
		// page (#804).
		if ( ! empty( $args['full_page'] ) ) {
			$result['full_page_honored'] = false;
			$result['note']             .= ' full_page was requested but the mshots fallback only supports a fixed viewport, not full-page capture; configure the Cloudflare Browser Rendering worker for that.';
		}

		// If CF failed, include the reason.
		if ( is_wp_error( $cf_result ) ) {
			$result['cf_fallback_reason'] = $cf_result->get_error_message();
		}

		// Save to media library if requested.
		if ( ! empty( $args['save_to_media'] ) ) {
			$saved = $this->save_screenshot_to_media( $screenshot_url, $url, $args );
			if ( ! is_wp_error( $saved ) ) {
				$result['media'] = $saved;
			} else {
				$result['media_error'] = $saved->get_error_message();
				$result['note']        = 'Screenshot URL generated but saving to media library failed. The mshots service may still be generating the image — try again in 15-30 seconds.';
			}
		}

		return $result;
	}

	/**
	 * Save a base64-encoded screenshot to the media library.
	 *
	 * @param string $base64     Base64-encoded image data.
	 * @param string $source_url Original page URL.
	 * @param array  $args       Additional args (title, etc).
	 * @return array|WP_Error Media data or error.
	 */
	private function save_base64_to_media( $base64, $source_url, $args = array(), $format = 'png' ) {
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}

		$image_data = base64_decode( $base64 );
		if ( false === $image_data ) {
			return new WP_Error( 'decode_failed', 'Failed to decode base64 screenshot data.' );
		}

		// Write to temp file.
		$tmp = wp_tempnam( 'mcpwp-screenshot-' );
		if ( ! $tmp ) {
			return new WP_Error( 'tmp_failed', 'Failed to create temporary file.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $tmp, $image_data );

		$parsed   = wp_parse_url( $source_url );
		$host     = isset( $parsed['host'] ) ? sanitize_file_name( $parsed['host'] ) : 'screenshot';
		$ext      = ( 'jpeg' === $format || 'jpg' === $format ) ? '.jpg' : '.png';
		$filename = 'screenshot-' . $host . '-' . gmdate( 'Ymd-His' ) . $ext;

		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp,
		);

		$title = isset( $args['title'] )
			? sanitize_text_field( $args['title'] )
			: sprintf( 'Screenshot of %s', $source_url );

		$attachment_id = media_handle_sideload( $file_array, 0, $title );

		if ( file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		update_post_meta(
			$attachment_id,
			'_wp_attachment_image_alt',
			sprintf( 'Screenshot of %s', esc_url( $source_url ) )
		);

		$attachment = get_post( $attachment_id );

		return array(
			'id'    => $attachment_id,
			'title' => $attachment->post_title,
			'url'   => wp_get_attachment_url( $attachment_id ),
		);
	}

	/**
	 * Save a screenshot to the media library.
	 *
	 * @param string $screenshot_url mshots URL.
	 * @param string $source_url     Original page URL.
	 * @param array  $args           Additional args.
	 * @return array|WP_Error Media data or error.
	 */
	private function save_screenshot_to_media( $screenshot_url, $source_url, $args = array() ) {
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}

		// Wait briefly for mshots to generate.
		sleep( 3 );

		$tmp = download_url( $screenshot_url, 30 );

		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		// Generate a clean filename.
		$parsed   = wp_parse_url( $source_url );
		$host     = isset( $parsed['host'] ) ? sanitize_file_name( $parsed['host'] ) : 'screenshot';
		$filename = 'screenshot-' . $host . '-' . gmdate( 'Ymd-His' ) . '.jpg';

		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp,
		);

		$title = isset( $args['title'] )
			? sanitize_text_field( $args['title'] )
			: sprintf( 'Screenshot of %s', $source_url );

		$attachment_id = media_handle_sideload( $file_array, 0, $title );

		if ( file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Set alt text.
		update_post_meta(
			$attachment_id,
			'_wp_attachment_image_alt',
			sprintf( 'Screenshot of %s', esc_url( $source_url ) )
		);

		$attachment = get_post( $attachment_id );

		return array(
			'id'    => $attachment_id,
			'title' => $attachment->post_title,
			'url'   => wp_get_attachment_url( $attachment_id ),
		);
	}

	/**
	 * Schedule async verification for screenshot readiness.
	 *
	 * @param string $url            Original page URL.
	 * @param string $screenshot_url mshots URL to verify.
	 * @param string $webhook_url    Webhook URL to notify when ready.
	 * @param array  $args           Additional args (save_to_media, title, etc).
	 */
	public function schedule_verification( $url, $screenshot_url, $webhook_url, $args = array() ) {
		wp_schedule_single_event(
			time() + 5,
			'mcpwp_verify_screenshot',
			array( $url, $screenshot_url, $webhook_url, $args, 0 )
		);
	}

	/**
	 * Check if screenshot is ready (not placeholder).
	 *
	 * @param string $screenshot_url mshots URL to check.
	 * @return bool True if ready, false if still placeholder.
	 */
	public function verify_screenshot_ready( $screenshot_url ) {
		$response = wp_remote_get(
			$screenshot_url,
			array(
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$content_length = wp_remote_retrieve_header( $response, 'content-length' );

		// Real screenshots are typically > 5KB, placeholders are much smaller.
		return $content_length > 5000;
	}

	/**
	 * Quick reachability probe for an mshots URL — distinguishes an outright
	 * rejection (403/404/451, WordPress.com's own service refusing to reach
	 * this host at all) from the normal "still generating" placeholder state
	 * (#804). Only the former should stop capture() from claiming success;
	 * the latter is expected on a first request and is unaffected.
	 *
	 * @param string $screenshot_url mshots URL to probe.
	 * @return int|false HTTP status code, or false if the probe itself could
	 *                    not complete (treated as inconclusive, never as a
	 *                    rejection — a network hiccup here must not turn into
	 *                    a false failure for the caller).
	 */
	private function probe_mshots_status( $screenshot_url ) {
		$response = wp_remote_get(
			$screenshot_url,
			array(
				// #804 W2: 4s, not 8 — this runs synchronously before every
				// mshots-fallback response (once per sweep now, not once per
				// width, but still on the hot path of a call the caller is
				// waiting on).
				'timeout'   => 4,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		return (int) wp_remote_retrieve_response_code( $response );
	}

	/**
	 * Turn an mshots probe status into a failure, or true when it isn't one
	 * (#804 W1/W2).
	 *
	 * Two distinct failure shapes, because they call for different action:
	 *
	 * - mshots_rejected (401/403/404/410/451): mshots refuses to fetch THIS
	 *   SERVER at all. Confirmed live from a Hetzner host against multiple
	 *   unrelated targets (github.com, wordpress.org/news, …) — mshots blocks
	 *   the requesting datacenter IP, not anything about the target host. The
	 *   message must not blame the caller's URL for what is mshots refusing
	 *   to talk to *us*.
	 * - mshots_unavailable (400, 429, 5xx): mshots itself is erroring or
	 *   rate-limiting — a transient service problem, not a standing
	 *   rejection, but still not something to report as a successful
	 *   screenshot.
	 *
	 * A probe that could not connect at all (false) or returned any other
	 * status (a "still generating" 200/202 placeholder) is not a failure.
	 *
	 * @param int|false $probe_status HTTP status probe_mshots_status() returned.
	 * @param mixed     $cf_result    The Cloudflare attempt's own result/error, for context.
	 * @return true|WP_Error
	 */
	private function classify_mshots_probe( $probe_status, $cf_result ) {
		$cf_fallback_reason = is_wp_error( $cf_result ) ? $cf_result->get_error_message() : null;

		if ( in_array( $probe_status, array( 401, 403, 404, 410, 451 ), true ) ) {
			return new WP_Error(
				'mshots_rejected',
				sprintf(
					/* translators: %d: HTTP status code returned by WordPress.com mshots */
					__( "WordPress.com mshots refused this site's server (HTTP %d). mshots commonly blocks datacenter hosts; configure the Screenshot Worker integration for reliable screenshots.", 'mcpwp' ),
					$probe_status
				),
				array(
					'status'             => 502,
					'mshots_status'      => $probe_status,
					'cf_fallback_reason' => $cf_fallback_reason,
				)
			);
		}

		$is_server_error = is_int( $probe_status ) && $probe_status >= 500 && $probe_status <= 599;

		if ( 400 === $probe_status || 429 === $probe_status || $is_server_error ) {
			return new WP_Error(
				'mshots_unavailable',
				sprintf(
					/* translators: %d: HTTP status code returned by WordPress.com mshots */
					__( 'WordPress.com mshots is temporarily unavailable (HTTP %d). Retry later, or configure the Screenshot Worker integration for a renderer that does not depend on it.', 'mcpwp' ),
					$probe_status
				),
				array(
					'status'             => 502,
					'mshots_status'      => $probe_status,
					'cf_fallback_reason' => $cf_fallback_reason,
				)
			);
		}

		return true;
	}

	/**
	 * Capture the same URL at several widths in one call.
	 *
	 * Probes mshots reachability at most ONCE for the whole sweep, not once
	 * per width (#804 W2) — the probe answers "can mshots reach this server
	 * at all", which does not vary with the requested viewport, so probing
	 * per width was pure waste (and, on a rejection, N redundant outbound
	 * requests that all get the same answer). Skipped entirely when the
	 * Cloudflare worker is configured, since every width then renders
	 * through it instead.
	 *
	 * @param string $url    URL to screenshot.
	 * @param array  $widths Widths to capture. Caller is responsible for
	 *                       clamping/deduping/capping this list.
	 * @param array  $args   Same shape capture() takes, applied to every
	 *                       width; any 'width' key in it is ignored.
	 * @return array<int,array|WP_Error> Keyed by width, in the order given.
	 */
	public function capture_many( $url, array $widths, $args = array() ) {
		$probe_status = null;

		if ( ! $this->get_cf_worker_config() ) {
			$sample_width  = ! empty( $widths ) ? max( 320, min( 1920, absint( reset( $widths ) ) ) ) : 1280;
			$sample_height = isset( $args['height'] ) ? absint( $args['height'] ) : 960;
			$sample_height = max( 240, min( 1440, $sample_height ) );

			$probe_url    = $this->mshots_base . rawurlencode( esc_url_raw( $url ) ) . '?w=' . $sample_width . '&h=' . $sample_height;
			$probe_status = $this->probe_mshots_status( $probe_url );
		}

		$results = array();

		foreach ( $widths as $width ) {
			$one_args = array_merge( $args, array( 'width' => $width ) );
			if ( null !== $probe_status ) {
				$one_args['_mshots_probe_status'] = $probe_status;
			}
			$results[ $width ] = $this->capture( $url, $one_args );
		}

		return $results;
	}

	/**
	 * Fire webhook with screenshot data.
	 *
	 * @param string $webhook_url Webhook URL.
	 * @param array  $data        Payload data.
	 * @return array|WP_Error Response data or error.
	 */
	public function fire_screenshot_webhook( $webhook_url, $data ) {
		// SSRF protection.
		if ( class_exists( 'Mcpwp_Security' ) ) {
			$ssrf_check = Mcpwp_Security::validate_external_url( $webhook_url );
			if ( is_wp_error( $ssrf_check ) ) {
				return $ssrf_check;
			}
		}

		$body = wp_json_encode( $data );

		$response = wp_remote_post(
			$webhook_url,
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => array(
					'Content-Type'  => 'application/json',
					// X-SPAI-* kept for backward-compat; remove in a future major.
					'X-SPAI-Event'  => 'screenshot.ready',
					// X-MCPWP-* alias (new canonical name).
					'X-MCPWP-Event' => 'screenshot.ready',
				),
				'body'        => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		return array(
			'success'       => $code >= 200 && $code < 300,
			'response_code' => $code,
			'response_body' => wp_remote_retrieve_body( $response ),
		);
	}

	/**
	 * Handle scheduled screenshot verification (cron callback).
	 *
	 * @param string $url            Original page URL.
	 * @param string $screenshot_url mshots URL.
	 * @param string $webhook_url    Webhook URL.
	 * @param array  $args           Additional args.
	 * @param int    $retry_count    Current retry attempt.
	 */
	public function handle_verification_cron( $url, $screenshot_url, $webhook_url, $args, $retry_count ) {
		$is_ready = $this->verify_screenshot_ready( $screenshot_url );

		if ( $is_ready ) {
			// Screenshot is ready - prepare webhook payload.
			$webhook_data = array(
				'url'            => $url,
				'screenshot_url' => $screenshot_url,
				'status'         => 'ready',
				'timestamp'      => current_time( 'c' ),
			);

			// Optionally save to media library.
			if ( ! empty( $args['save_to_media'] ) ) {
				$media_result = $this->save_screenshot_to_media( $screenshot_url, $url, $args );
				if ( ! is_wp_error( $media_result ) ) {
					$webhook_data['media'] = $media_result;
				} else {
					$webhook_data['media_error'] = $media_result->get_error_message();
				}
			}

			// Fire webhook.
			$this->fire_screenshot_webhook( $webhook_url, $webhook_data );

		} elseif ( $retry_count < 6 ) {
			// Not ready yet - reschedule check in 10 seconds.
			wp_schedule_single_event(
				time() + 10,
				'mcpwp_verify_screenshot',
				array( $url, $screenshot_url, $webhook_url, $args, $retry_count + 1 )
			);

		} else {
			// Max retries reached - fire webhook with timeout status.
			$webhook_data = array(
				'url'            => $url,
				'screenshot_url' => $screenshot_url,
				'status'         => 'timeout',
				'message'        => __( 'Screenshot generation timed out after 1 minute. The URL may be unreachable or mshots service may be slow.', 'mcpwp' ),
				'timestamp'      => current_time( 'c' ),
			);

			$this->fire_screenshot_webhook( $webhook_url, $webhook_data );
		}
	}
}
