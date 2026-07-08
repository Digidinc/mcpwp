<?php
/**
 * ElevenLabs Provider
 *
 * Text-to-speech audio generation.
 *
 * @package mumcp
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ElevenLabs API provider.
 */
class Mcpwp_Provider_ElevenLabs {

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
	const API_BASE = 'https://api.elevenlabs.io/v1/';

	/**
	 * Default voice ID (Rachel).
	 *
	 * @var string
	 */
	const DEFAULT_VOICE = '21m00Tcm4TlvDq8ikWAM';

	/**
	 * Constructor.
	 *
	 * @param string $api_key ElevenLabs API key.
	 */
	public function __construct( $api_key ) {
		$this->api_key = $api_key;
	}

	/**
	 * Generate speech from text.
	 *
	 * @param string $text     Text to convert to speech.
	 * @param string $voice_id ElevenLabs voice ID.
	 * @return string|WP_Error Audio binary (MP3) or error.
	 */
	public function text_to_speech( $text, $voice_id = '' ) {
		if ( empty( $voice_id ) ) {
			$voice_id = self::DEFAULT_VOICE;
		}

		$response = wp_remote_post(
			self::API_BASE . 'text-to-speech/' . sanitize_text_field( $voice_id ),
			array(
				'headers' => array(
					'xi-api-key'   => $this->api_key,
					'Content-Type' => 'application/json',
					'Accept'       => 'audio/mpeg',
				),
				'body'    => wp_json_encode( array(
					'text'                    => $text,
					'model_id'                => 'eleven_multilingual_v2',
					'voice_settings'          => array(
						'stability'        => 0.5,
						'similarity_boost' => 0.75,
					),
				) ),
				'timeout' => 60,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code >= 400 ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$message = isset( $body['detail']['message'] )
				? $body['detail']['message']
				: ( isset( $body['detail'] ) && is_string( $body['detail'] ) ? $body['detail'] : sprintf( 'ElevenLabs API returned status %d', $code ) );
			return new WP_Error( 'elevenlabs_api_error', $message, array( 'status' => $code ) );
		}

		return wp_remote_retrieve_body( $response );
	}

	/**
	 * Generate speech and upload to media library.
	 *
	 * @param string $text     Text to convert.
	 * @param array  $args     Optional. {voice_id, title}.
	 * @return array|WP_Error Attachment data or error.
	 */
	public function text_to_speech_to_media( $text, $args = array() ) {
		$voice_id = isset( $args['voice_id'] ) ? $args['voice_id'] : '';
		$audio    = $this->text_to_speech( $text, $voice_id );

		if ( is_wp_error( $audio ) ) {
			return $audio;
		}

		$title    = ! empty( $args['title'] ) ? $args['title'] : 'TTS Audio - ' . substr( $text, 0, 50 );
		$filename = sanitize_file_name( substr( $title, 0, 50 ) ) . '.mp3';

		// Write audio to temp file and upload.
		$tmp = wp_tempnam( $filename );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $tmp, $audio );

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}

		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp,
		);

		$attachment_id = media_handle_sideload( $file_array, 0, $title );

		if ( file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		return array(
			'id'        => $attachment_id,
			'title'     => $title,
			'url'       => wp_get_attachment_url( $attachment_id ),
			'mime_type' => 'audio/mpeg',
			'filesize'  => filesize( get_attached_file( $attachment_id ) ),
		);
	}

	/**
	 * Test connection to ElevenLabs API.
	 *
	 * @return array{success: bool, message: string}
	 */
	public function test_connection() {
		$response = wp_remote_get(
			self::API_BASE . 'user',
			array(
				'headers' => array( 'xi-api-key' => $this->api_key ),
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
				'message' => isset( $body['detail']['message'] ) ? $body['detail']['message'] : sprintf( 'HTTP %d', $code ),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'ElevenLabs API connection successful.', 'mumcp' ),
		);
	}
}
