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
 * Two transports reach the same model:
 * - 'typesafe': POST https://api.typesafe.ai/v1/systemone. The customer
 *   brings their own TypeSafe API key; usage is billed to them directly.
 *   Cost is input tokens only, $0.042/1M.
 * - 'cloudflare': POST https://api.cloudflare.com/client/v4/accounts/{account_id}/ai/run
 *   with {"model":"typesafe/jev","input":{state,questions}}, using the Jev
 *   integration's OWN cf_account_id + cf_api_token (a Workers-AI-only token
 *   with Workers AI:Read permission). Jev never borrows the Cloudflare
 *   integration's zone token: that token was granted for cache/WAF/Speed,
 *   not for sending approval content to a model, and removing the Jev
 *   integration must remove every credential Jev can send with. Same pricing
 *   per https://developers.cloudflare.com/ai/models/typesafe/jev/.
 *
 * Cloudflare's published schema-input.json / schema-output.json for this
 * model are, question-for-question and answer-for-answer, the SAME dialect
 * TypeSafe calls 'noul' (type: noul/choice/score; noul criteria is
 * {true,false}; choice criteria is {key:label}; score criteria is an array of
 * labels; a noul answer is {type,noul}, a choice answer is
 * {type,choice,probabilities,confidence}, a score answer is
 * {type,score,legend,probabilities,confidence}). So to_wire_questions() and
 * from_wire_answers() below are shared between both transports verbatim —
 * only the request envelope (flat body vs {model,input:{state,questions}})
 * and the response envelope (flat vs Cloudflare's
 * {success,errors,messages,result}) differ. This was verified against
 * Cloudflare's own schema files, not assumed.
 *
 * Transport resolution (Mcpwp_Jev::transport()) is a pure, no-network read of
 * the Jev integration's stored config: transport 'off' picks ''; otherwise a
 * TypeSafe key picks 'typesafe'; otherwise Jev's own cf_api_token +
 * cf_account_id pick 'cloudflare'; otherwise ''. Whether a Cloudflare token can actually
 * run Workers AI is never guessed at resolution time — it is proven by the
 * first real call (evaluate() or test_connection()), and a permission
 * failure surfaces as an ordinary WP_Error, same as any other Jev failure:
 * callers already fall back to rules on any WP_Error, so this never blocks.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal wire client for Jev, over either transport.
 */
class Mcpwp_Jev {

	const TRANSPORT_TYPESAFE   = 'typesafe';
	const TRANSPORT_CLOUDFLARE = 'cloudflare';

	/**
	 * Values the Jev integration's 'transport' setting accepts. Anything else
	 * is refused at configure time (Mcpwp_Integration_Manager).
	 */
	const TRANSPORT_SETTINGS = array( 'auto', 'typesafe', 'cloudflare', 'off' );

	/**
	 * A Cloudflare account ID: 32 hex characters.
	 */
	const CF_ACCOUNT_ID_PATTERN = '/^[0-9a-f]{32}$/i';

	/**
	 * Upstream error text is untrusted and unbounded; cap what is echoed into
	 * responses and error_log.
	 */
	const MAX_UPSTREAM_ERROR_LENGTH = 300;

	/**
	 * Default TypeSafe endpoint. Only ever overridden for testing — the
	 * override field is HTTPS-only, enforced in evaluate() regardless of
	 * where the endpoint string came from.
	 */
	const DEFAULT_ENDPOINT = 'https://api.typesafe.ai/v1/systemone';

	/**
	 * sprintf() template for the Cloudflare Workers AI "run" endpoint.
	 * %s is the account ID, URL-encoded by the caller before substitution.
	 */
	const CLOUDFLARE_ENDPOINT_TEMPLATE = 'https://api.cloudflare.com/client/v4/accounts/%s/ai/run';

	/**
	 * Model identifiers. Deliberately distinct constants: TypeSafe's own API
	 * addresses the model by its short alias, Cloudflare's catalog addresses
	 * it by its published slug — conflating them would silently break one
	 * transport's request.
	 */
	const MODEL            = 'jev-latest';
	const CLOUDFLARE_MODEL = 'typesafe/jev';

	/**
	 * Hard ceiling from the brief: never let one call hang the request that
	 * triggered it. Triage always runs off a cron tick, never inline, but the
	 * timeout stays short regardless. Same ceiling for both transports.
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
	 * @var string One of self::TRANSPORT_TYPESAFE / self::TRANSPORT_CLOUDFLARE.
	 */
	private $transport;

	/**
	 * @param string $api_key   Plaintext credential — a TypeSafe API key for
	 *                          the 'typesafe' transport, a Cloudflare API
	 *                          token for the 'cloudflare' transport.
	 * @param string $endpoint  Optional endpoint override. Empty uses the
	 *                          default — but only the 'typesafe' transport
	 *                          HAS a meaningful default (a bare account-less
	 *                          Cloudflare URL is not callable), so an empty
	 *                          endpoint on the 'cloudflare' transport is left
	 *                          empty and evaluate() will reject it via the
	 *                          HTTPS-only check.
	 * @param string $transport One of self::TRANSPORT_TYPESAFE (default) /
	 *                          self::TRANSPORT_CLOUDFLARE. Kept as a 3rd,
	 *                          optional parameter so every pre-existing
	 *                          `new Mcpwp_Jev( $key, $endpoint )` call site
	 *                          and test keeps its exact old behaviour.
	 */
	public function __construct( $api_key, $endpoint = '', $transport = self::TRANSPORT_TYPESAFE ) {
		$this->transport = in_array( $transport, array( self::TRANSPORT_TYPESAFE, self::TRANSPORT_CLOUDFLARE ), true )
			? $transport
			: self::TRANSPORT_TYPESAFE;

		$this->api_key = (string) $api_key;

		$endpoint = trim( (string) $endpoint );
		if ( '' === $endpoint && self::TRANSPORT_TYPESAFE === $this->transport ) {
			$endpoint = self::DEFAULT_ENDPOINT;
		}
		$this->endpoint = $endpoint;
	}

	/**
	 * True when at least one transport is usable for this site — no HTTP
	 * call made. This is the "is Jev configured" answer callers (e.g.
	 * Mcpwp_Approvals::maybe_schedule_triage()) actually want: whichever
	 * transport resolve_transport() would pick.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== self::transport();
	}

	/**
	 * Alias of is_configured() — the name #944's capability pattern expects.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return '' !== self::transport();
	}

	/**
	 * Resolve which transport this site would use, without making any
	 * network call. Reads only the Jev integration's own record:
	 * - 'off' resolves to '' — nothing is ever sent.
	 * - an explicit override on the 'jev' provider ('typesafe' or
	 *   'cloudflare') is honoured if — and only if — that transport is
	 *   actually configured; an override naming an unconfigured transport
	 *   resolves to '' rather than silently falling back to the other one,
	 *   because an explicit choice that can't be honoured should say so, not
	 *   quietly substitute.
	 * - otherwise ('auto' or empty): a TypeSafe key wins if present, else
	 *   Jev's own Workers AI credentials (cf_api_token + cf_account_id) if
	 *   present, else ''.
	 *
	 * @return string '' | self::TRANSPORT_TYPESAFE | self::TRANSPORT_CLOUDFLARE
	 */
	public static function transport() {
		$config = self::jev_config();

		$setting = self::transport_setting( $config );
		if ( in_array( $setting, array( 'off', 'invalid' ), true ) ) {
			return '';
		}

		$typesafe_ready   = ! empty( $config['api_key'] );
		$cloudflare_ready = self::cloudflare_credentials_ready( $config );

		if ( self::TRANSPORT_TYPESAFE === $setting ) {
			return $typesafe_ready ? self::TRANSPORT_TYPESAFE : '';
		}
		if ( self::TRANSPORT_CLOUDFLARE === $setting ) {
			return $cloudflare_ready ? self::TRANSPORT_CLOUDFLARE : '';
		}

		if ( $typesafe_ready ) {
			return self::TRANSPORT_TYPESAFE;
		}
		if ( $cloudflare_ready ) {
			return self::TRANSPORT_CLOUDFLARE;
		}
		return '';
	}

	/**
	 * The Jev integration's stored config, or an empty array.
	 *
	 * @return array
	 */
	private static function jev_config() {
		if ( ! class_exists( 'Mcpwp_Integration_Manager' ) ) {
			return array();
		}
		$config = Mcpwp_Integration_Manager::get_instance()->get_provider_config( 'jev' );
		return is_array( $config ) ? $config : array();
	}

	/**
	 * The stored transport setting, normalised. Empty means 'auto'. A value
	 * outside TRANSPORT_SETTINGS cannot be saved through the configure paths;
	 * one found in storage anyway (hand-edited option) is invalid and must
	 * not send anything.
	 *
	 * @param array $config Jev config.
	 * @return string One of TRANSPORT_SETTINGS, or invalid.
	 */
	private static function transport_setting( array $config ) {
		$setting = isset( $config['transport'] ) ? strtolower( trim( (string) $config['transport'] ) ) : '';
		if ( '' === $setting ) {
			return 'auto';
		}
		return in_array( $setting, self::TRANSPORT_SETTINGS, true ) ? $setting : 'invalid';
	}

	/**
	 * Validate and normalise a Jev config before it is stored. Called by
	 * Mcpwp_Integration_Manager::set_provider_config() for every save path
	 * (REST, MCP, wp-admin).
	 *
	 * @param array $config Submitted config.
	 * @return array|WP_Error Normalised config, or a 400 WP_Error naming the problem.
	 */
	public static function validate_config( array $config ) {
		$transport = isset( $config['transport'] ) ? strtolower( trim( (string) $config['transport'] ) ) : '';
		if ( '' === $transport ) {
			$transport = 'auto';
		}
		if ( ! in_array( $transport, self::TRANSPORT_SETTINGS, true ) ) {
			return new WP_Error(
				'invalid_jev_transport',
				sprintf(
					/* translators: %s: comma-separated allowed values */
					__( 'Jev transport must be one of: %s.', 'mcpwp' ),
					implode( ', ', self::TRANSPORT_SETTINGS )
				),
				array( 'status' => 400 )
			);
		}
		$config['transport'] = $transport;

		$account_id = isset( $config['cf_account_id'] ) ? trim( (string) $config['cf_account_id'] ) : '';
		if ( '' !== $account_id && ! preg_match( self::CF_ACCOUNT_ID_PATTERN, $account_id ) ) {
			return new WP_Error(
				'invalid_cf_account_id',
				__( 'The Cloudflare account ID must be 32 hexadecimal characters.', 'mcpwp' ),
				array( 'status' => 400 )
			);
		}
		$config['cf_account_id'] = strtolower( $account_id );

		if ( isset( $config['cf_api_token'] ) ) {
			$config['cf_api_token'] = trim( (string) $config['cf_api_token'] );
		}

		return $config;
	}

	/**
	 * Structural (no-network) status for admin display: which transport is
	 * active, and why — or why none is.
	 *
	 * @return array{transport:string, reason:string}
	 */
	public static function status() {
		$transport = self::transport();
		if ( self::TRANSPORT_TYPESAFE === $transport ) {
			return array( 'transport' => $transport, 'reason' => 'typesafe_key_configured' );
		}
		if ( self::TRANSPORT_CLOUDFLARE === $transport ) {
			return array( 'transport' => $transport, 'reason' => 'cloudflare_workers_ai_credentials_configured' );
		}

		$setting = self::transport_setting( self::jev_config() );
		if ( 'invalid' === $setting ) {
			return array( 'transport' => '', 'reason' => 'transport_invalid' );
		}
		if ( 'off' === $setting ) {
			return array( 'transport' => '', 'reason' => 'disabled_by_admin' );
		}
		if ( self::TRANSPORT_TYPESAFE === $setting ) {
			return array( 'transport' => '', 'reason' => 'override_typesafe_not_configured' );
		}
		if ( self::TRANSPORT_CLOUDFLARE === $setting ) {
			return array( 'transport' => '', 'reason' => 'override_cloudflare_not_configured' );
		}
		return array( 'transport' => '', 'reason' => 'not_configured' );
	}

	/**
	 * Whether the Jev integration carries its OWN Workers AI credentials: a
	 * cf_api_token and a well-formed cf_account_id. The Cloudflare
	 * integration's zone token is never consulted. Whether the token really
	 * has Workers AI permission is proven by the first real call.
	 *
	 * @param array $config Jev config.
	 * @return bool
	 */
	private static function cloudflare_credentials_ready( array $config ) {
		$token      = isset( $config['cf_api_token'] ) ? trim( (string) $config['cf_api_token'] ) : '';
		$account_id = isset( $config['cf_account_id'] ) ? trim( (string) $config['cf_account_id'] ) : '';
		return '' !== $token && 1 === preg_match( self::CF_ACCOUNT_ID_PATTERN, $account_id );
	}

	/**
	 * Build a client from the site's stored configuration, for whichever
	 * transport transport() resolves to.
	 *
	 * @return self|false False when neither transport is configured.
	 */
	public static function for_site() {
		$transport = self::transport();
		if ( '' === $transport ) {
			return false;
		}

		$config = self::jev_config();

		if ( self::TRANSPORT_TYPESAFE === $transport ) {
			return new self(
				isset( $config['api_key'] ) ? $config['api_key'] : '',
				isset( $config['endpoint'] ) ? $config['endpoint'] : '',
				self::TRANSPORT_TYPESAFE
			);
		}

		// Cloudflare — Jev's own credentials only (transport() checked them).
		$account_id = trim( (string) $config['cf_account_id'] );
		$api_token  = trim( (string) $config['cf_api_token'] );

		$default_endpoint = sprintf( self::CLOUDFLARE_ENDPOINT_TEMPLATE, rawurlencode( $account_id ) );

		/**
		 * Filter the Cloudflare Workers AI endpoint Jev calls. Exists so a
		 * test can point this at a local stub without touching site config —
		 * never used to relax the HTTPS-only / SSRF-guard requirements
		 * evaluate() enforces regardless of where the endpoint came from.
		 *
		 * @param string $default_endpoint Computed https://api.cloudflare.com/... URL.
		 * @param string $account_id       The site's configured Cloudflare account ID.
		 */
		$endpoint = apply_filters( 'mcpwp_jev_cloudflare_endpoint', $default_endpoint, $account_id );

		return new self( $api_token, (string) $endpoint, self::TRANSPORT_CLOUDFLARE );
	}

	/**
	 * The transport THIS instance was built for.
	 *
	 * @return string
	 */
	public function which_transport() {
		return $this->transport;
	}

	/**
	 * Make one real, cheap call to prove the resolved transport actually
	 * works (a TypeSafe key that is valid, or a Cloudflare token that
	 * actually has Workers AI permission on the given account) — the same
	 * kind of live check every other provider's test_connection() does in
	 * this plugin. Never called from the triage cron path; only from an
	 * explicit admin "Test connection" action.
	 *
	 * @return array{success:bool, message:string, transport:string}
	 */
	public static function test_connection() {
		$status = self::status();
		if ( '' === $status['transport'] ) {
			return array(
				'success'   => false,
				'message'   => self::describe_reason( $status['reason'] ),
				'transport' => '',
			);
		}

		$jev = self::for_site();
		if ( ! $jev ) {
			return array(
				'success'   => false,
				'message'   => __( 'Jev configuration could not be loaded.', 'mcpwp' ),
				'transport' => $status['transport'],
			);
		}

		$result = $jev->evaluate(
			'MCPWP connection test.',
			array(
				'ok' => array(
					'type'         => 'boolean',
					'instructions' => 'Answer true to confirm this connection works.',
					'criteria'     => array(
						'true'  => 'The connection works.',
						'false' => 'The connection does not work.',
					),
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			return array(
				'success'   => false,
				'message'   => $result->get_error_message(),
				'transport' => $status['transport'],
			);
		}

		return array(
			'success'   => true,
			'message'   => sprintf(
				/* translators: %s: transport name ("typesafe" or "cloudflare") */
				__( 'Connected to Jev via %s.', 'mcpwp' ),
				$status['transport']
			),
			'transport' => $status['transport'],
		);
	}

	/**
	 * Human-readable sentence for a status() reason code.
	 *
	 * @param string $reason Reason code from status().
	 * @return string
	 */
	private static function describe_reason( $reason ) {
		switch ( $reason ) {
			case 'override_typesafe_not_configured':
				return __( 'Jev is set to use TypeSafe, but no TypeSafe API key is saved.', 'mcpwp' );
			case 'override_cloudflare_not_configured':
				return __( 'Jev is set to use Cloudflare Workers AI, but the Jev integration is missing its Workers AI API token or a valid 32-character account ID.', 'mcpwp' );
			case 'transport_invalid':
				return __( 'The stored Jev transport is invalid. Save a valid transport in the Jev integration. Nothing is sent.', 'mcpwp' );
			case 'disabled_by_admin':
				return __( 'Jev is turned off (transport set to "off"). Nothing is sent.', 'mcpwp' );
			default:
				return __( 'Jev is not configured: save a TypeSafe API key, or a Cloudflare account ID + Workers AI API token, in the Jev integration to turn it on.', 'mcpwp' );
		}
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

		// HTTPS only, no exceptions on scheme.
		$scheme = wp_parse_url( $this->endpoint, PHP_URL_SCHEME );
		if ( 'https' !== $scheme ) {
			return new WP_Error( 'jev_invalid_endpoint', __( 'The configured Jev endpoint must use HTTPS.', 'mcpwp' ) );
		}

		$wire_questions = self::to_wire_questions( $questions );

		if ( self::TRANSPORT_CLOUDFLARE === $this->transport ) {
			$body = array(
				'model' => self::CLOUDFLARE_MODEL,
				'input' => array(
					'state'     => $state,
					'questions' => $wire_questions,
				),
			);
		} else {
			$body = array(
				'model'     => self::MODEL,
				'state'     => $state,
				'questions' => $wire_questions,
			);
		}

		// wp_safe_remote_post(), not wp_remote_post(): this call is driven by
		// an admin-supplied endpoint (an override for TypeSafe, or an
		// account-derived Cloudflare URL that can also be filtered), so it
		// must refuse internal/private hosts the same way any other outbound
		// integration in this plugin does — an SSRF guard, not a
		// convenience. sslverify is never disabled or relaxed, including for
		// a loopback target: a local test double is reached by mocking
		// pre_http_request, never by weakening TLS verification on a real
		// request.
		$response = wp_safe_remote_post(
			$this->endpoint,
			array(
				'timeout'     => self::TIMEOUT,
				'sslverify'   => true,
				// Never follow a redirect: it would carry the bearer token and
				// the approval content to wherever the Location header says.
				'redirection' => 0,
				'headers'     => array(
					'Authorization' => 'Bearer ' . $this->api_key,
					'Content-Type'  => 'application/json',
				),
				'body'        => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'jev_unreachable', __( 'Could not reach Jev.', 'mcpwp' ) );
		}

		$code     = (int) wp_remote_retrieve_response_code( $response );
		$body_raw = wp_remote_retrieve_body( $response );
		$decoded  = json_decode( $body_raw, true );

		// A redirect is a failure on both transports, never followed (see
		// 'redirection' => 0 above) and never read as an answer.
		if ( $code >= 300 && $code < 400 ) {
			return new WP_Error( 'jev_http_error', sprintf( 'Jev returned HTTP %d (redirects are not followed).', $code ) );
		}

		if ( self::TRANSPORT_CLOUDFLARE === $this->transport ) {
			// Cloudflare's standard API envelope: {success, errors, messages,
			// result}. Cloudflare's own client (Mcpwp_Provider_Cloudflare)
			// gates on the decoded 'success' field, not the HTTP status —
			// an auth/permission failure can arrive as a non-2xx status
			// that STILL carries a JSON body with the real reason in
			// 'errors', and gating on status first would throw that reason
			// away in favour of a bare "HTTP 403". Same rule here.
			if ( ! is_array( $decoded ) ) {
				return new WP_Error( 'jev_bad_response', __( 'Jev returned a non-JSON response.', 'mcpwp' ) );
			}
			// Cloudflare's model page shows the model's own payload
			// ({model, answers, usage}) as the response of the model-in-body
			// /ai/run call, while the REST API convention wraps it in the
			// envelope. Accept the unwrapped form too — but only on a 2xx
			// that actually carries answers, so an error body can never be
			// mistaken for one.
			if ( ! array_key_exists( 'success', $decoded ) && isset( $decoded['answers'] ) && is_array( $decoded['answers'] ) && $code >= 200 && $code < 300 ) {
				$decoded = array(
					'success' => true,
					'result'  => $decoded,
				);
			}
			if ( ! isset( $decoded['success'] ) || true !== $decoded['success'] ) {
				$messages = array();
				if ( ! empty( $decoded['errors'] ) && is_array( $decoded['errors'] ) ) {
					foreach ( $decoded['errors'] as $err ) {
						if ( is_array( $err ) && isset( $err['message'] ) ) {
							$messages[] = $err['message'];
						} elseif ( is_scalar( $err ) ) {
							$messages[] = (string) $err;
						}
					}
				}
				return new WP_Error(
					'jev_http_error',
					$messages ? self::truncate_upstream( implode( '; ', $messages ) ) : sprintf( 'Cloudflare Workers AI returned HTTP %d.', $code )
				);
			}
			// The model's own {model, answers, usage} payload — the same
			// shape TypeSafe returns flat, per Cloudflare's published
			// schema-output.json — lives under 'result'.
			$decoded = ( isset( $decoded['result'] ) && is_array( $decoded['result'] ) ) ? $decoded['result'] : array();
		} else {
			if ( $code < 200 || $code >= 300 ) {
				return new WP_Error( 'jev_http_error', sprintf( 'Jev returned HTTP %d.', $code ) );
			}
			if ( ! is_array( $decoded ) ) {
				return new WP_Error( 'jev_bad_response', __( 'Jev returned a non-JSON response.', 'mcpwp' ) );
			}
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
			'answers'   => $answers,
			'usage'     => isset( $decoded['usage'] ) ? $decoded['usage'] : array(),
			'model'     => isset( $decoded['model'] ) ? $decoded['model'] : '',
			'transport' => $this->transport,
		);
	}

	/**
	 * Cap untrusted upstream error text before it reaches a response or
	 * error_log.
	 *
	 * @param string $text Upstream text.
	 * @return string
	 */
	private static function truncate_upstream( $text ) {
		$text = (string) $text;
		if ( function_exists( 'mb_substr' ) ) {
			return mb_strlen( $text ) > self::MAX_UPSTREAM_ERROR_LENGTH ? mb_substr( $text, 0, self::MAX_UPSTREAM_ERROR_LENGTH ) . '…' : $text;
		}
		return strlen( $text ) > self::MAX_UPSTREAM_ERROR_LENGTH ? substr( $text, 0, self::MAX_UPSTREAM_ERROR_LENGTH ) . '...' : $text;
	}

	/**
	 * Our spelling ('boolean') -> TypeSafe's/Cloudflare's shared wire
	 * spelling ('noul'). Identical for both transports — verified against
	 * Cloudflare's published schema-input.json, which defines the same
	 * noul/choice/score dialect TypeSafe uses.
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
	 * TypeSafe's/Cloudflare's shared wire spelling -> our spelling. A 'noul'
	 * answer carries its probability under a key named after the type;
	 * surface it as 'probability' so a boolean answer reads the same
	 * regardless of wire dialect. Identical for both transports — verified
	 * against Cloudflare's published schema-output.json, which defines the
	 * same per-type answer shape TypeSafe uses.
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
