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
				__( 'A valid URL is required.', 'mumcp' ),
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

		$result = array(
			'success'        => true,
			'url'            => $url,
			'screenshot_url' => $screenshot_url,
			'width'          => $width,
			'height'         => $height,
			'service'        => 'wordpress-mshots',
			'note'           => 'First request triggers generation. Screenshot may take 10-30 seconds to appear. Retry the URL if you get a placeholder.',
		);

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
				'message'        => __( 'Screenshot generation timed out after 1 minute. The URL may be unreachable or mshots service may be slow.', 'mumcp' ),
				'timestamp'      => current_time( 'c' ),
			);

			$this->fire_screenshot_webhook( $webhook_url, $webhook_data );
		}
	}
}
