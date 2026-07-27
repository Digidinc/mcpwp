<?php
/**
 * DataForSEO API client — keyword market data and search visibility.
 *
 * The customer brings their own credentials. Every call spends their money,
 * so limits are clamped here rather than trusted from callers.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DataForSEO Labs client.
 */
class Mcpwp_Provider_Dataforseo {

	const API_BASE = 'https://api.dataforseo.com/v3/';

	/**
	 * DataForSEO signals success with 20000, not with HTTP 200 alone.
	 */
	const STATUS_OK = 20000;

	/**
	 * Hard ceiling on keywords sent for enrichment in one request.
	 *
	 * No single call is expensive ($0.012/request + $0.00012/result), so this
	 * exists to bound an agent loop, not to ration lookups.
	 */
	const MAX_ENRICHMENT_KEYWORDS = 50;

	const TIMEOUT = 30;

	/**
	 * API login.
	 *
	 * @var string
	 */
	private $login;

	/**
	 * API password.
	 *
	 * @var string
	 */
	private $password;

	/**
	 * Constructor.
	 *
	 * @param string $login    DataForSEO API login.
	 * @param string $password DataForSEO API password.
	 */
	public function __construct( $login, $password ) {
		$this->login    = (string) $login;
		$this->password = (string) $password;
	}

	/**
	 * Map a DataForSEO status code to a distinct machine-readable reason.
	 *
	 * Each failure gets its own reason so "out of credits" never renders the
	 * same as "no results" or "bad password".
	 *
	 * @param int $status_code DataForSEO status code.
	 * @return string Reason slug.
	 */
	public static function reason_for_status( $status_code ) {
		$status_code = (int) $status_code;

		if ( self::STATUS_OK === $status_code ) {
			return 'ok';
		}
		if ( $status_code >= 40100 && $status_code < 40200 ) {
			return 'auth_rejected';
		}
		if ( $status_code >= 40200 && $status_code < 40300 ) {
			return 'insufficient_credits';
		}
		if ( $status_code >= 40000 && $status_code < 50000 ) {
			return 'request_rejected';
		}

		return 'provider_unavailable';
	}

	/**
	 * Human-readable sentence for a reason slug.
	 *
	 * @param string $reason Reason slug.
	 * @return string
	 */
	public static function describe_reason( $reason ) {
		switch ( $reason ) {
			case 'auth_rejected':
				return __( 'DataForSEO rejected the configured credentials.', 'mcpwp' );
			case 'insufficient_credits':
				return __( 'The DataForSEO account has no remaining credit.', 'mcpwp' );
			case 'request_rejected':
				return __( 'DataForSEO rejected the request parameters.', 'mcpwp' );
			case 'provider_unavailable':
				return __( 'DataForSEO could not be reached.', 'mcpwp' );
			case 'not_configured':
				return __( 'No DataForSEO credentials are configured.', 'mcpwp' );
			default:
				return __( 'DataForSEO returned no results.', 'mcpwp' );
		}
	}

	/**
	 * Keyword suggestions with market metrics.
	 *
	 * @param string $seed Seed keyword.
	 * @param array  $args Optional: limit, language_code, location_code.
	 * @return array|WP_Error List of normalized keyword rows.
	 */
	public function keyword_suggestions( $seed, $args = array() ) {
		$limit = isset( $args['limit'] ) ? absint( $args['limit'] ) : self::MAX_ENRICHMENT_KEYWORDS;
		$limit = max( 1, min( self::MAX_ENRICHMENT_KEYWORDS, $limit ) );

		$payload = array(
			array(
				'keyword'           => (string) $seed,
				'language_code'     => isset( $args['language_code'] ) ? (string) $args['language_code'] : 'en',
				'location_code'     => isset( $args['location_code'] ) ? (int) $args['location_code'] : 2840,
				'limit'             => $limit,
				'include_serp_info' => false,
			),
		);

		$items = $this->request( 'dataforseo_labs/google/keyword_suggestions/live', $payload );
		if ( is_wp_error( $items ) ) {
			return $items;
		}

		$out = array();
		foreach ( $items as $item ) {
			$info  = isset( $item['keyword_info'] ) && is_array( $item['keyword_info'] ) ? $item['keyword_info'] : array();
			$props = isset( $item['keyword_properties'] ) && is_array( $item['keyword_properties'] ) ? $item['keyword_properties'] : array();

			$out[] = array(
				'keyword'            => isset( $item['keyword'] ) ? (string) $item['keyword'] : '',
				'search_volume'      => isset( $info['search_volume'] ) ? (int) $info['search_volume'] : 0,
				'cpc'                => isset( $info['cpc'] ) ? (float) $info['cpc'] : 0.0,
				'competition'        => isset( $info['competition'] ) ? (float) $info['competition'] : 0.0,
				'competition_level'  => isset( $info['competition_level'] ) ? (string) $info['competition_level'] : '',
				'keyword_difficulty' => isset( $props['keyword_difficulty'] ) ? (int) $props['keyword_difficulty'] : 0,
			);
		}

		return $out;
	}

	/**
	 * Keywords the target domain already ranks for.
	 *
	 * Returns position and market metrics only. Clicks and impressions are
	 * Search Console data; this provider has no access to them and must not
	 * emit zeroed columns that would render as "no traffic".
	 *
	 * @param string $target Domain, without scheme.
	 * @param array  $args   Optional: limit, language_code, location_code.
	 * @return array|WP_Error
	 */
	public function ranked_keywords( $target, $args = array() ) {
		$limit = isset( $args['limit'] ) ? absint( $args['limit'] ) : 100;
		$limit = max( 1, min( 1000, $limit ) );

		$payload = array(
			array(
				'target'        => (string) $target,
				'language_code' => isset( $args['language_code'] ) ? (string) $args['language_code'] : 'en',
				'location_code' => isset( $args['location_code'] ) ? (int) $args['location_code'] : 2840,
				'limit'         => $limit,
				'order_by'      => array( 'ranked_serp_element.serp_item.rank_group,asc' ),
			),
		);

		$items = $this->request( 'dataforseo_labs/google/ranked_keywords/live', $payload );
		if ( is_wp_error( $items ) ) {
			return $items;
		}

		$out = array();
		foreach ( $items as $item ) {
			$kd    = isset( $item['keyword_data'] ) && is_array( $item['keyword_data'] ) ? $item['keyword_data'] : array();
			$info  = isset( $kd['keyword_info'] ) && is_array( $kd['keyword_info'] ) ? $kd['keyword_info'] : array();
			$props = isset( $kd['keyword_properties'] ) && is_array( $kd['keyword_properties'] ) ? $kd['keyword_properties'] : array();
			$serp  = isset( $item['ranked_serp_element']['serp_item'] ) && is_array( $item['ranked_serp_element']['serp_item'] )
				? $item['ranked_serp_element']['serp_item']
				: array();

			$keyword = isset( $kd['keyword'] ) ? (string) $kd['keyword'] : '';
			if ( '' === $keyword ) {
				continue;
			}

			$out[] = array(
				'keyword'            => $keyword,
				'position'           => isset( $serp['rank_group'] ) ? (int) $serp['rank_group'] : 0,
				'ranking_url'        => isset( $serp['url'] ) ? (string) $serp['url'] : '',
				'search_volume'      => isset( $info['search_volume'] ) ? (int) $info['search_volume'] : 0,
				'keyword_difficulty' => isset( $props['keyword_difficulty'] ) ? (int) $props['keyword_difficulty'] : 0,
			);
		}

		return $out;
	}

	/**
	 * Verify credentials and report remaining credit.
	 *
	 * @return array{success:bool,message:string,balance:float|null}
	 */
	public function test_connection() {
		$response = $this->post( 'appendix/user_data', array() );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => $response->get_error_message(),
				'balance' => null,
			);
		}

		$status = isset( $response['status_code'] ) ? (int) $response['status_code'] : 0;
		if ( self::STATUS_OK !== $status ) {
			return array(
				'success' => false,
				'message' => self::describe_reason( self::reason_for_status( $status ) ),
				'balance' => null,
			);
		}

		$balance = isset( $response['tasks'][0]['result'][0]['money']['balance'] )
			? (float) $response['tasks'][0]['result'][0]['money']['balance']
			: null;

		return array(
			'success' => true,
			'message' => null === $balance
				? __( 'Connected to DataForSEO.', 'mcpwp' )
				: sprintf(
					/* translators: %s: remaining account credit in USD. */
					__( 'Connected to DataForSEO. Remaining credit: $%s', 'mcpwp' ),
					number_format( $balance, 2 )
				),
			'balance' => $balance,
		);
	}

	/**
	 * Issue a request and unwrap the task result items.
	 *
	 * @param string $path    API path after /v3/.
	 * @param array  $payload Request payload.
	 * @return array|WP_Error Items array.
	 */
	private function request( $path, $payload ) {
		$response = $this->post( $path, $payload );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = isset( $response['status_code'] ) ? (int) $response['status_code'] : 0;
		if ( self::STATUS_OK !== $status ) {
			$reason = self::reason_for_status( $status );
			return new WP_Error( $reason, self::describe_reason( $reason ) );
		}

		// A task can fail while the envelope reports success.
		$task_status = isset( $response['tasks'][0]['status_code'] ) ? (int) $response['tasks'][0]['status_code'] : self::STATUS_OK;
		if ( self::STATUS_OK !== $task_status ) {
			$reason = self::reason_for_status( $task_status );
			return new WP_Error( $reason, self::describe_reason( $reason ) );
		}

		$items = isset( $response['tasks'][0]['result'][0]['items'] ) ? $response['tasks'][0]['result'][0]['items'] : array();

		return is_array( $items ) ? $items : array();
	}

	/**
	 * POST to the API. Overridden in tests to replay fixtures.
	 *
	 * @param string $path    API path after /v3/.
	 * @param array  $payload Request payload.
	 * @return array|WP_Error Decoded response.
	 */
	protected function post( $path, $payload ) {
		$args = array(
			'timeout' => self::TIMEOUT,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $this->login . ':' . $this->password ),
				'Content-Type'  => 'application/json',
			),
		);

		if ( ! empty( $payload ) ) {
			$args['body'] = wp_json_encode( $payload );
		} else {
			$args['method'] = 'GET';
		}

		$response = empty( $payload )
			? wp_remote_get( self::API_BASE . $path, $args )
			: wp_remote_post( self::API_BASE . $path, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'provider_unavailable', self::describe_reason( 'provider_unavailable' ) );
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'provider_unavailable', self::describe_reason( 'provider_unavailable' ) );
		}

		return $decoded;
	}
}
