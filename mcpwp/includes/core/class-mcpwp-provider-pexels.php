<?php
/**
 * Pexels Provider
 *
 * Stock photo search and download via Pexels API.
 *
 * @package MCPWP
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pexels API provider.
 */
class Mcpwp_Provider_Pexels {

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
	const API_BASE = 'https://api.pexels.com/v1/';

	/**
	 * Constructor.
	 *
	 * @param string $api_key Pexels API key.
	 */
	public function __construct( $api_key ) {
		$this->api_key = $api_key;
	}

	/**
	 * Search for photos.
	 *
	 * @param string $query    Search query.
	 * @param int    $per_page Results per page (1-80).
	 * @param int    $page     Page number.
	 * @return array|WP_Error Search results or error.
	 */
	public function search( $query, $per_page = 10, $page = 1 ) {
		$per_page = max( 1, min( 80, absint( $per_page ) ) );
		$page     = max( 1, absint( $page ) );

		$response = wp_remote_get(
			add_query_arg(
				array(
					'query'    => rawurlencode( $query ),
					'per_page' => $per_page,
					'page'     => $page,
				),
				self::API_BASE . 'search'
			),
			array(
				'headers' => $this->get_headers(),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			return new WP_Error(
				'pexels_api_error',
				isset( $body['error'] ) ? $body['error'] : sprintf( 'Pexels API returned status %d', $code ),
				array( 'status' => $code )
			);
		}

		$photos = array();
		if ( ! empty( $body['photos'] ) && is_array( $body['photos'] ) ) {
			foreach ( $body['photos'] as $photo ) {
				$photos[] = array(
					'id'              => $photo['id'],
					'width'           => $photo['width'],
					'height'          => $photo['height'],
					'photographer'    => $photo['photographer'],
					'alt'             => isset( $photo['alt'] ) ? $photo['alt'] : '',
					'url'             => $photo['url'],
					'src'             => array(
						'original' => $photo['src']['original'],
						'large2x'  => $photo['src']['large2x'],
						'large'    => $photo['src']['large'],
						'medium'   => $photo['src']['medium'],
						'small'    => $photo['src']['small'],
					),
				);
			}
		}

		return array(
			'total_results' => isset( $body['total_results'] ) ? $body['total_results'] : 0,
			'page'          => $page,
			'per_page'      => $per_page,
			'photos'        => $photos,
		);
	}

	/**
	 * Get a single photo by ID.
	 *
	 * @param int $photo_id Pexels photo ID.
	 * @return array|WP_Error Photo data or error.
	 */
	public function get_photo( $photo_id ) {
		$response = wp_remote_get(
			self::API_BASE . 'photos/' . absint( $photo_id ),
			array(
				'headers' => $this->get_headers(),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			return new WP_Error(
				'pexels_api_error',
				isset( $body['error'] ) ? $body['error'] : sprintf( 'Pexels API returned status %d', $code ),
				array( 'status' => $code )
			);
		}

		return $body;
	}

	/**
	 * Download a photo to the WordPress media library.
	 *
	 * @param int   $photo_id Pexels photo ID.
	 * @param array $args     Optional. {size, alt, title}.
	 * @return array|WP_Error Attachment data or error.
	 */
	public function download_to_media( $photo_id, $args = array() ) {
		$photo = $this->get_photo( $photo_id );
		if ( is_wp_error( $photo ) ) {
			return $photo;
		}

		$size     = isset( $args['size'] ) ? $args['size'] : 'large';
		$size_map = array( 'original', 'large2x', 'large', 'medium', 'small' );
		if ( ! in_array( $size, $size_map, true ) ) {
			$size = 'large';
		}

		$url = isset( $photo['src'][ $size ] ) ? $photo['src'][ $size ] : $photo['src']['large'];

		$media      = new Mcpwp_Media();
		$upload_args = array(
			'title'    => ! empty( $args['title'] ) ? $args['title'] : sprintf( 'Pexels Photo %d', $photo_id ),
			'alt'      => ! empty( $args['alt'] ) ? $args['alt'] : ( isset( $photo['alt'] ) ? $photo['alt'] : '' ),
			'caption'  => sprintf(
				'Photo by %s on Pexels',
				isset( $photo['photographer'] ) ? $photo['photographer'] : 'Unknown'
			),
			'filename' => sprintf( 'pexels-%d.jpeg', $photo_id ),
		);

		return $media->upload_from_url( $url, $upload_args );
	}

	/**
	 * Test connection to Pexels API.
	 *
	 * @return array{success: bool, message: string}
	 */
	public function test_connection() {
		$result = $this->search( 'test', 1 );
		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'message' => $result->get_error_message(),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Pexels API connection successful.', 'mumcp' ),
		);
	}

	/**
	 * Get request headers.
	 *
	 * @return array
	 */
	private function get_headers() {
		return array(
			'Authorization' => $this->api_key,
		);
	}
}
