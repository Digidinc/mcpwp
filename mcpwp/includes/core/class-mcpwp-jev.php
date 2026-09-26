<?php
/**
 * Jev (TypeSafe System One) client — an evaluation model, not a chat model.
 *
 * Jev takes one piece of state plus typed questions and returns typed answers
 * with probabilities in one round trip. It routes; it never gates. Nothing in
 * this class, or in any caller, may treat a Jev answer as an approve/reject
 * decision, a scope check, a kses/safety verdict, or anything that changes
 * what a mutation is allowed to do. It only ever produces a priority/label for
 * display and sorting (see Mcpwp_Jev_Triage).
 *
 * The customer brings their own TypeSafe API key; usage is billed to them
 * directly. Cost is input tokens only, $0.042/1M.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal wire client for POST https://api.typesafe.ai/v1/systemone.
 */
class Mcpwp_Jev {

	/**
	 * Default endpoint. Only ever overridden for testing — the override field
	 * is HTTPS-only, enforced in evaluate() regardless of where the endpoint
	 * string came from.
	 */
	const DEFAULT_ENDPOINT = 'https://api.typesafe.ai/v1/systemone';

	const MODEL = 'jev-latest';

	/**
	 * Hard ceiling from the brief: never let one call hang the request that
	 * triggered it. Triage always runs off a cron tick, never inline, but the
	 * timeout stays short regardless.
	 */
	const TIMEOUT = 8;

	/**
	 * @var string
	 */
	private $api_key;

	/**
	 * @var string
	 */
	private $endpoint;

	/**
	 * @param string $api_key  Plaintext TypeSafe API key.
	 * @param string $endpoint Optional endpoint override. Empty uses the default.
	 */
	public function __construct( $api_key, $endpoint = '' ) {
		$this->api_key  = (string) $api_key;
		$endpoint       = trim( (string) $endpoint );
		$this->endpoint = '' !== $endpoint ? $endpoint : self::DEFAULT_ENDPOINT;
	}

	/**
	 * True when a Jev API key is saved for this site — no HTTP call made.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		if ( ! class_exists( 'Mcpwp_Integration_Manager' ) ) {
			return false;
		}

		$config = Mcpwp_Integration_Manager::get_instance()->get_provider_config( 'jev' );

		return is_array( $config ) && ! empty( $config['api_key'] );
	}

	/**
	 * Build a client from the site's stored Jev configuration.
	 *
	 * @return self|false False when Jev is not configured.
	 */
	public static function for_site() {
		if ( ! class_exists( 'Mcpwp_Integration_Manager' ) ) {
			return false;
		}

		$config = Mcpwp_Integration_Manager::get_instance()->get_provider_config( 'jev' );
		if ( ! is_array( $config ) || empty( $config['api_key'] ) ) {
			return false;
		}

		return new self( $config['api_key'], isset( $config['endpoint'] ) ? $config['endpoint'] : '' );
	}

	/**
	 * Ask Jev to answer typed questions about one piece of state.
	 *
	 * @param string|array $state     Untrusted state to evaluate. May itself
	 *                                contain injected instructions from site
	 *                                content — Jev's answer is data, never a
	 *                                command, and callers must treat it that way.
	 * @param array        $questions Map of question key => {type, instructions, criteria}
	 *                                using this plugin's spelling (boolean/choice/score).
	 * @return array|WP_Error {answers, usage, model} on success.
	 */
	public function evaluate( $state, array $questions ) {
		if ( '' === $this->api_key ) {
			return new WP_Error( 'jev_not_configured', __( 'Jev is not configured for this site.', 'mcpwp' ) );
		}

		if ( empty( $questions ) ) {
			return new WP_Error( 'jev_no_questions', __( 'At least one question is required.', 'mcpwp' ) );
		}

		// HTTPS only, no exceptions on scheme — the sslverify relaxation below
		// (loopback + WP_DEBUG, mirroring every other loopback fetch in this
		// plugin) is how a local test stub is reached without weakening the
		// scheme requirement itself.
		$scheme = wp_parse_url( $this->endpoint, PHP_URL_SCHEME );
		if ( 'https' !== $scheme ) {
			return new WP_Error( 'jev_invalid_endpoint', __( 'The configured Jev endpoint must use HTTPS.', 'mcpwp' ) );
		}

		$host          = (string) wp_parse_url( $this->endpoint, PHP_URL_HOST );
		$is_loopback   = in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true );
		$response      = wp_remote_post(
			$this->endpoint,
			array(
				'timeout'   => self::TIMEOUT,
				'sslverify' => $is_loopback ? ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : true,
				'headers'   => array(
					'Authorization' => 'Bearer ' . $this->api_key,
					'Content-Type'  => 'application/json',
				),
				'body'      => wp_json_encode(
					array(
						'model'     => self::MODEL,
						'state'     => $state,
						'questions' => self::to_wire_questions( $questions ),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'jev_unreachable', __( 'Could not reach Jev.', 'mcpwp' ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'jev_http_error', sprintf( 'Jev returned HTTP %d.', $code ) );
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'jev_bad_response', __( 'Jev returned a non-JSON response.', 'mcpwp' ) );
		}

		$answers = self::from_wire_answers( isset( $decoded['answers'] ) && is_array( $decoded['answers'] ) ? $decoded['answers'] : array() );

		// Jev answers every question it is given; a missing key means the
		// response was truncated or the question was rejected, never that the
		// answer is a false/zero. Treat it as failure, same as the CLI wrapper.
		foreach ( array_keys( $questions ) as $key ) {
			if ( ! array_key_exists( $key, $answers ) ) {
				return new WP_Error( 'jev_incomplete_response', sprintf( 'Jev returned no answer for "%s".', $key ) );
			}
		}

		return array(
			'answers' => $answers,
			'usage'   => isset( $decoded['usage'] ) ? $decoded['usage'] : array(),
			'model'   => isset( $decoded['model'] ) ? $decoded['model'] : '',
		);
	}

	/**
	 * Our spelling ('boolean') -> TypeSafe's wire spelling ('noul').
	 *
	 * @param array $questions Questions keyed by name.
	 * @return array Wire-format questions.
	 */
	private static function to_wire_questions( array $questions ) {
		$out = array();
		foreach ( $questions as $key => $question ) {
			$question         = is_array( $question ) ? $question : array();
			$question['type'] = ( isset( $question['type'] ) && 'boolean' === $question['type'] ) ? 'noul' : ( isset( $question['type'] ) ? $question['type'] : '' );
			$out[ $key ]      = $question;
		}
		return $out;
	}

	/**
	 * TypeSafe's wire spelling -> our spelling. A 'noul' answer carries its
	 * probability under a key named after the type; surface it as
	 * 'probability' so a boolean answer reads the same regardless of wire
	 * dialect.
	 *
	 * @param array $answers Wire-format answers.
	 * @return array Answers keyed by question name.
	 */
	private static function from_wire_answers( array $answers ) {
		$out = array();
		foreach ( $answers as $key => $answer ) {
			if ( ! is_array( $answer ) ) {
				$out[ $key ] = $answer;
				continue;
			}
			if ( isset( $answer['type'] ) && 'noul' === $answer['type'] ) {
				$probability = isset( $answer['noul'] ) ? $answer['noul'] : null;
				unset( $answer['noul'] );
				$answer['type']        = 'boolean';
				$answer['probability'] = $probability;
			}
			$out[ $key ] = $answer;
		}
		return $out;
	}
}
