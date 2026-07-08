<?php
/**
 * Google Indexing API Integration
 *
 * Submit URLs to Google for indexing using the Indexing API
 * with a service account JSON key.
 *
 * @package MCPWP_Pro
 * @since   1.1.22
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google Indexing API handler.
 *
 * Uses the Google Indexing API to submit URLs for crawling/indexing
 * and to check the indexing status of individual URLs.
 */
class Mcpwp_Google_Indexing {

	/**
	 * Google OAuth2 token endpoint.
	 *
	 * @var string
	 */
	const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

	/**
	 * Google Indexing API base URL.
	 *
	 * @var string
	 */
	const INDEXING_API_URL = 'https://indexing.googleapis.com/v3/urlNotifications';

	/**
	 * Required OAuth2 scope for the Indexing API.
	 *
	 * @var string
	 */
	const INDEXING_SCOPE = 'https://www.googleapis.com/auth/indexing';

	/**
	 * Cached access token.
	 *
	 * @var string|null
	 */
	private $access_token = null;

	/**
	 * Cached token expiry time.
	 *
	 * @var int
	 */
	private $token_expiry = 0;

	/**
	 * Get the service account configuration from Integration Manager.
	 *
	 * @return array|false Service account config or false.
	 */
	private function get_service_account() {
		$manager = Mcpwp_Integration_Manager::get_instance();
		$config  = $manager->get_provider_config( 'google_indexing' );

		if ( ! $config || empty( $config['service_account_json'] ) ) {
			return false;
		}

		$sa = json_decode( $config['service_account_json'], true );
		if ( ! is_array( $sa ) || empty( $sa['client_email'] ) || empty( $sa['private_key'] ) ) {
			return false;
		}

		return $sa;
	}

	/**
	 * Create a signed JWT for the service account.
	 *
	 * @param array $service_account Service account data.
	 * @return string|WP_Error JWT string or error.
	 */
	private function create_jwt( $service_account ) {
		$header = array(
			'alg' => 'RS256',
			'typ' => 'JWT',
		);

		$now    = time();
		$claims = array(
			'iss'   => $service_account['client_email'],
			'scope' => self::INDEXING_SCOPE,
			'aud'   => self::TOKEN_ENDPOINT,
			'iat'   => $now,
			'exp'   => $now + 3600,
		);

		$segments   = array();
		$segments[] = $this->base64url_encode( wp_json_encode( $header ) );
		$segments[] = $this->base64url_encode( wp_json_encode( $claims ) );

		$signing_input = implode( '.', $segments );

		$private_key = openssl_pkey_get_private( $service_account['private_key'] );
		if ( false === $private_key ) {
			return new WP_Error(
				'invalid_private_key',
				__( 'Failed to parse the service account private key.', 'mumcp' ),
				array( 'status' => 500 )
			);
		}

		$signature = '';
		$success   = openssl_sign( $signing_input, $signature, $private_key, OPENSSL_ALGO_SHA256 );

		if ( ! $success ) {
			return new WP_Error(
				'signing_failed',
				__( 'Failed to sign the JWT.', 'mumcp' ),
				array( 'status' => 500 )
			);
		}

		$segments[] = $this->base64url_encode( $signature );

		return implode( '.', $segments );
	}

	/**
	 * Base64url encode (no padding).
	 *
	 * @param string $data Data to encode.
	 * @return string Encoded data.
	 */
	private function base64url_encode( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Get an OAuth2 access token using the service account JWT.
	 *
	 * @return string|WP_Error Access token or error.
	 */
	private function get_access_token() {
		// Return cached token if still valid.
		if ( $this->access_token && time() < $this->token_expiry ) {
			return $this->access_token;
		}

		$sa = $this->get_service_account();
		if ( ! $sa ) {
			return new WP_Error(
				'no_service_account',
				__( 'Google Indexing API service account not configured. Add it via MCPWP > Integrations.', 'mumcp' ),
				array( 'status' => 400 )
			);
		}

		$jwt = $this->create_jwt( $sa );
		if ( is_wp_error( $jwt ) ) {
			return $jwt;
		}

		$response = wp_remote_post(
			self::TOKEN_ENDPOINT,
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'    => http_build_query(
					array(
						'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
						'assertion'  => $jwt,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'token_request_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to get access token: %s', 'mumcp' ),
					$response->get_error_message()
				),
				array( 'status' => 502 )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $body['access_token'] ) ) {
			$error_msg = isset( $body['error_description'] )
				? $body['error_description']
				: ( isset( $body['error'] ) ? $body['error'] : 'Unknown error' );

			return new WP_Error(
				'token_error',
				sprintf(
					/* translators: %s: error message */
					__( 'Google OAuth2 error: %s', 'mumcp' ),
					$error_msg
				),
				array( 'status' => 502 )
			);
		}

		$this->access_token = $body['access_token'];
		$this->token_expiry = time() + ( isset( $body['expires_in'] ) ? (int) $body['expires_in'] - 60 : 3540 );

		return $this->access_token;
	}

	/**
	 * Submit URLs to Google for indexing.
	 *
	 * @param array  $urls   Array of URLs to submit.
	 * @param string $action Notification type: 'URL_UPDATED' or 'URL_DELETED'.
	 * @return array|WP_Error Results array or error.
	 */
	public function submit_urls( $urls, $action = 'URL_UPDATED' ) {
		if ( empty( $urls ) || ! is_array( $urls ) ) {
			return new WP_Error(
				'no_urls',
				__( 'No URLs provided.', 'mumcp' ),
				array( 'status' => 400 )
			);
		}

		$valid_actions = array( 'URL_UPDATED', 'URL_DELETED' );
		if ( ! in_array( $action, $valid_actions, true ) ) {
			return new WP_Error(
				'invalid_action',
				sprintf(
					/* translators: %s: valid actions */
					__( 'Invalid action. Must be one of: %s', 'mumcp' ),
					implode( ', ', $valid_actions )
				),
				array( 'status' => 400 )
			);
		}

		// Google Indexing API has a quota of 200 URLs/day.
		if ( count( $urls ) > 200 ) {
			return new WP_Error(
				'too_many_urls',
				__( 'Maximum 200 URLs per request (Google Indexing API daily quota).', 'mumcp' ),
				array( 'status' => 400 )
			);
		}

		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$results = array(
			'submitted' => array(),
			'failed'    => array(),
		);

		foreach ( $urls as $url ) {
			$url = esc_url_raw( $url );
			if ( empty( $url ) ) {
				$results['failed'][] = array(
					'url'   => $url,
					'error' => 'Invalid URL',
				);
				continue;
			}

			$response = wp_remote_post(
				self::INDEXING_API_URL . ':publish',
				array(
					'timeout' => 15,
					'headers' => array(
						'Content-Type'  => 'application/json',
						'Authorization' => 'Bearer ' . $token,
					),
					'body'    => wp_json_encode(
						array(
							'url'  => $url,
							'type' => $action,
						)
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				$results['failed'][] = array(
					'url'   => $url,
					'error' => $response->get_error_message(),
				);
				continue;
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( 200 === $code ) {
				$results['submitted'][] = array(
					'url'        => $url,
					'type'       => isset( $body['urlNotificationMetadata']['latestUpdate']['type'] ) ? $body['urlNotificationMetadata']['latestUpdate']['type'] : $action,
					'notify_time' => isset( $body['urlNotificationMetadata']['latestUpdate']['notifyTime'] ) ? $body['urlNotificationMetadata']['latestUpdate']['notifyTime'] : null,
				);
			} else {
				$error_msg = isset( $body['error']['message'] ) ? $body['error']['message'] : "HTTP $code";
				$results['failed'][] = array(
					'url'   => $url,
					'error' => $error_msg,
					'code'  => $code,
				);
			}
		}

		$results['total_submitted'] = count( $results['submitted'] );
		$results['total_failed']    = count( $results['failed'] );
		$results['action']          = $action;

		return $results;
	}

	/**
	 * Get indexing status for a URL.
	 *
	 * @param string $url URL to check.
	 * @return array|WP_Error Status data or error.
	 */
	public function get_status( $url ) {
		if ( empty( $url ) ) {
			return new WP_Error(
				'no_url',
				__( 'No URL provided.', 'mumcp' ),
				array( 'status' => 400 )
			);
		}

		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$request_url = add_query_arg( 'url', rawurlencode( $url ), self::INDEXING_API_URL . '/metadata' );

		$response = wp_remote_get(
			$request_url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'request_failed',
				$response->get_error_message(),
				array( 'status' => 502 )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$error_msg = isset( $body['error']['message'] ) ? $body['error']['message'] : "HTTP $code";
			return new WP_Error(
				'indexing_api_error',
				$error_msg,
				array( 'status' => $code >= 400 && $code < 600 ? $code : 502 )
			);
		}

		$result = array(
			'url' => $url,
		);

		if ( isset( $body['latestUpdate'] ) ) {
			$result['latest_update'] = array(
				'type'        => isset( $body['latestUpdate']['type'] ) ? $body['latestUpdate']['type'] : null,
				'notify_time' => isset( $body['latestUpdate']['notifyTime'] ) ? $body['latestUpdate']['notifyTime'] : null,
			);
		}

		if ( isset( $body['latestRemove'] ) ) {
			$result['latest_remove'] = array(
				'type'        => isset( $body['latestRemove']['type'] ) ? $body['latestRemove']['type'] : null,
				'notify_time' => isset( $body['latestRemove']['notifyTime'] ) ? $body['latestRemove']['notifyTime'] : null,
			);
		}

		return $result;
	}

	/**
	 * Test the connection by verifying the service account can obtain a token.
	 *
	 * @return array{success: bool, message: string}
	 */
	public function test_connection() {
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return array(
				'success' => false,
				'message' => $token->get_error_message(),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Successfully authenticated with Google Indexing API.', 'mumcp' ),
		);
	}
}
