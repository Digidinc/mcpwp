<?php
/**
 * Google Gemini Provider
 *
 * Imagen 3 image generation, Gemini vision analysis, and text generation.
 *
 * @package mumcp
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gemini API provider.
 */
class Mcpwp_Provider_Gemini {

	/**
	 * API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * API base URL.
	 *
	 * @var string
	 */
	const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/';

	/**
	 * Constructor.
	 *
	 * @param string $api_key Gemini API key.
	 */
	public function __construct( $api_key ) {
		$this->api_key = $api_key;
	}

	/**
	 * Generate an image with Imagen 3.
	 *
	 * @param string $prompt Image prompt.
	 * @param string $size   Aspect ratio hint (1024x1024, 1792x1024, 1024x1792).
	 * @return array|WP_Error {image_data: string (base64), mime_type: string} or error.
	 */
	public function generate_image( $prompt, $size = '1024x1024' ) {
		$aspect_ratio = '1:1';
		if ( '1792x1024' === $size ) {
			$aspect_ratio = '16:9';
		} elseif ( '1024x1792' === $size ) {
			$aspect_ratio = '9:16';
		}

		$response = $this->post(
			'models/imagen-3.0-generate-002:predict',
			array(
				'instances' => array(
					array( 'prompt' => $prompt ),
				),
				'parameters' => array(
					'sampleCount'  => 1,
					'aspectRatio'  => $aspect_ratio,
				),
			),
			90
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['predictions'][0]['bytesBase64Encoded'] ) ) {
			return new WP_Error( 'gemini_no_image', __( 'No image returned from Gemini.', 'mumega-mcp' ) );
		}

		return array(
			'image_data' => $response['predictions'][0]['bytesBase64Encoded'],
			'mime_type'  => isset( $response['predictions'][0]['mimeType'] ) ? $response['predictions'][0]['mimeType'] : 'image/png',
		);
	}

	/**
	 * Generate image and upload to media library.
	 *
	 * @param string $prompt Image prompt.
	 * @param array  $args   Optional. {size, alt, title}.
	 * @return array|WP_Error Attachment data or error.
	 */
	public function generate_image_to_media( $prompt, $args = array() ) {
		$size   = isset( $args['size'] ) ? $args['size'] : '1024x1024';
		$result = $this->generate_image( $prompt, $size );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$media    = new Mcpwp_Media();
		$filename = sanitize_file_name( substr( $prompt, 0, 50 ) ) . '.png';

		$upload_args = array(
			'title' => ! empty( $args['title'] ) ? $args['title'] : substr( $prompt, 0, 100 ),
			'alt'   => ! empty( $args['alt'] ) ? $args['alt'] : substr( $prompt, 0, 125 ),
		);

		return $media->upload_from_base64( $result['image_data'], $filename, $upload_args );
	}

	/**
	 * Analyze an image using Gemini 2.5 Flash.
	 *
	 * @param string $image_url   URL of the image.
	 * @param string $instruction Analysis instruction.
	 * @return string|WP_Error Analysis text or error.
	 */
	public function analyze_image( $image_url, $instruction = 'Describe this image concisely.' ) {
		$response = $this->post(
			'models/gemini-2.5-flash:generateContent',
			array(
				'contents' => array(
					array(
						'parts' => array(
							array( 'text' => $instruction ),
							array(
								'fileData' => array(
									'mimeType' => 'image/jpeg',
									'fileUri'  => $image_url,
								),
							),
						),
					),
				),
				'generationConfig' => array(
					'maxOutputTokens' => 500,
				),
			),
			30
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['candidates'][0]['content']['parts'][0]['text'] ) ) {
			return new WP_Error( 'gemini_no_response', __( 'No response from Gemini vision.', 'mumega-mcp' ) );
		}

		return $response['candidates'][0]['content']['parts'][0]['text'];
	}

	/**
	 * Generate text using Gemini 2.5 Flash.
	 *
	 * @param string $prompt     Text prompt.
	 * @param int    $max_tokens Maximum tokens.
	 * @return string|WP_Error Generated text or error.
	 */
	public function generate_text( $prompt, $max_tokens = 500 ) {
		$response = $this->post(
			'models/gemini-2.5-flash:generateContent',
			array(
				'contents' => array(
					array(
						'parts' => array(
							array( 'text' => $prompt ),
						),
					),
				),
				'generationConfig' => array(
					'maxOutputTokens' => max( 50, min( 4096, absint( $max_tokens ) ) ),
				),
			),
			30
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['candidates'][0]['content']['parts'][0]['text'] ) ) {
			return new WP_Error( 'gemini_no_response', __( 'No response from Gemini.', 'mumega-mcp' ) );
		}

		return $response['candidates'][0]['content']['parts'][0]['text'];
	}

	/**
	 * Test connection to Gemini API.
	 *
	 * @return array{success: bool, message: string}
	 */
	public function test_connection() {
		$url = self::API_BASE . 'models?' . http_build_query( array( 'key' => $this->api_key ) );

		$response = wp_remote_get( $url, array( 'timeout' => 10 ) );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => $response->get_error_message(),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			return array(
				'success' => false,
				'message' => isset( $body['error']['message'] ) ? $body['error']['message'] : sprintf( 'HTTP %d', $code ),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Gemini API connection successful.', 'mumega-mcp' ),
		);
	}

	/**
	 * Make a POST request to Gemini API.
	 *
	 * @param string $endpoint API endpoint path.
	 * @param array  $body     Request body.
	 * @param int    $timeout  Request timeout.
	 * @return array|WP_Error Decoded response or error.
	 */
	private function post( $endpoint, $body, $timeout = 30 ) {
		$url = self::API_BASE . $endpoint . '?' . http_build_query( array( 'key' => $this->api_key ) );

		$response = wp_remote_post(
			$url,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
				'timeout' => $timeout,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 ) {
			$message = isset( $data['error']['message'] ) ? $data['error']['message'] : sprintf( 'Gemini API returned status %d', $code );
			return new WP_Error( 'gemini_api_error', $message, array( 'status' => $code ) );
		}

		return $data;
	}
}
