<?php
/**
 * OpenAI Provider
 *
 * GPT-Image-1-Mini image generation, GPT-4o vision analysis, and text generation.
 *
 * @package mumcp
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OpenAI API provider.
 */
class Mcpwp_Provider_OpenAI {

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
	const API_BASE = 'https://api.openai.com/v1/';

	/**
	 * Constructor.
	 *
	 * @param string $api_key OpenAI API key.
	 */
	public function __construct( $api_key ) {
		$this->api_key = $api_key;
	}

	/**
	 * Generate an image with GPT-Image-1-Mini.
	 *
	 * @param string $prompt  Image prompt.
	 * @param string $size    Image size (1024x1024, 1536x1024, 1024x1536).
	 * @param string $quality Quality (low, medium, high).
	 * @return array|WP_Error {b64_json: string, revised_prompt: string} or error.
	 */
	public function generate_image( $prompt, $size = '1024x1024', $quality = 'medium' ) {
		$allowed_sizes = array( '1024x1024', '1536x1024', '1024x1536' );
		if ( ! in_array( $size, $allowed_sizes, true ) ) {
			$size = '1024x1024';
		}

		$allowed_qualities = array( 'low', 'medium', 'high' );
		if ( ! in_array( $quality, $allowed_qualities, true ) ) {
			$quality = 'medium';
		}

		$response = $this->post( 'images/generations', array(
			'model'         => 'gpt-image-1-mini',
			'prompt'        => $prompt,
			'n'             => 1,
			'size'          => $size,
			'quality'       => $quality,
			'output_format' => 'png',
		), 90 );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['data'][0]['b64_json'] ) ) {
			return new WP_Error( 'openai_no_image', __( 'No image returned from OpenAI.', 'mumega-mcp' ) );
		}

		return array(
			'b64_json'        => $response['data'][0]['b64_json'],
			'revised_prompt'  => isset( $response['data'][0]['revised_prompt'] ) ? $response['data'][0]['revised_prompt'] : '',
		);
	}

	/**
	 * Generate image and upload to media library.
	 *
	 * @param string $prompt Image prompt.
	 * @param array  $args   Optional. {size, quality, alt, title}.
	 * @return array|WP_Error Attachment data or error.
	 */
	public function generate_image_to_media( $prompt, $args = array() ) {
		$size    = isset( $args['size'] ) ? $args['size'] : '1024x1024';
		$quality = isset( $args['quality'] ) ? $args['quality'] : ( isset( $args['style'] ) ? 'medium' : 'medium' );

		$result = $this->generate_image( $prompt, $size, $quality );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$media    = new Mcpwp_Media();
		$filename = sanitize_file_name( substr( $prompt, 0, 50 ) ) . '.png';
		$title    = ! empty( $args['title'] ) ? $args['title'] : substr( $prompt, 0, 100 );
		$alt      = ! empty( $args['alt'] ) ? $args['alt'] : substr( $prompt, 0, 125 );

		$attachment = $media->upload_from_base64( $result['b64_json'], $filename, array(
			'title' => $title,
			'alt'   => $alt,
		) );
		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}

		$attachment['revised_prompt'] = $result['revised_prompt'];
		return $attachment;
	}

	/**
	 * Analyze an image using GPT-4o vision.
	 *
	 * @param string $image_url   URL of the image to analyze.
	 * @param string $instruction What to analyze (e.g., "Generate alt text").
	 * @return string|WP_Error Analysis text or error.
	 */
	public function analyze_image( $image_url, $instruction = 'Describe this image concisely.' ) {
		$response = $this->post( 'chat/completions', array(
			'model'      => 'gpt-4o',
			'max_tokens' => 500,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type' => 'text',
							'text' => $instruction,
						),
						array(
							'type'      => 'image_url',
							'image_url' => array( 'url' => $image_url ),
						),
					),
				),
			),
		), 30 );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['choices'][0]['message']['content'] ) ) {
			return new WP_Error( 'openai_no_response', __( 'No response from OpenAI vision.', 'mumega-mcp' ) );
		}

		return $response['choices'][0]['message']['content'];
	}

	/**
	 * Generate text using GPT-4o.
	 *
	 * @param string $prompt     Text prompt.
	 * @param int    $max_tokens Maximum tokens.
	 * @return string|WP_Error Generated text or error.
	 */
	public function generate_text( $prompt, $max_tokens = 500 ) {
		$response = $this->post( 'chat/completions', array(
			'model'      => 'gpt-4o',
			'max_tokens' => max( 50, min( 4096, absint( $max_tokens ) ) ),
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
		), 30 );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['choices'][0]['message']['content'] ) ) {
			return new WP_Error( 'openai_no_response', __( 'No response from OpenAI.', 'mumega-mcp' ) );
		}

		return $response['choices'][0]['message']['content'];
	}

	/**
	 * Test connection to OpenAI API.
	 *
	 * @return array{success: bool, message: string}
	 */
	public function test_connection() {
		$response = wp_remote_get(
			self::API_BASE . 'models',
			array(
				'headers' => $this->get_headers(),
				'timeout' => 10,
			)
		);

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
			'message' => __( 'OpenAI API connection successful.', 'mumega-mcp' ),
		);
	}

	/**
	 * Make a POST request to OpenAI API.
	 *
	 * @param string $endpoint API endpoint path.
	 * @param array  $body     Request body.
	 * @param int    $timeout  Request timeout in seconds.
	 * @return array|WP_Error Decoded response or error.
	 */
	private function post( $endpoint, $body, $timeout = 30 ) {
		$response = wp_remote_post(
			self::API_BASE . $endpoint,
			array(
				'headers' => array_merge( $this->get_headers(), array(
					'Content-Type' => 'application/json',
				) ),
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
			$message = isset( $data['error']['message'] ) ? $data['error']['message'] : sprintf( 'OpenAI API returned status %d', $code );
			return new WP_Error( 'openai_api_error', $message, array( 'status' => $code ) );
		}

		return $data;
	}

	/**
	 * Get request headers.
	 *
	 * @return array
	 */
	private function get_headers() {
		return array(
			'Authorization' => 'Bearer ' . $this->api_key,
		);
	}
}
