<?php
/**
 * The webhook delivery log keeps a redacted copy; the receiver gets the real
 * payload.
 *
 * deliver() stored the exact bytes it sent — and the response body the
 * receiver sent back — in mcpwp_webhook_logs, where /webhooks/{id}/logs hands
 * them to any caller allowed to read logs. 3.11.0 put every other log through
 * one redactor (Mcpwp_Option_Access::redact_for_log with log_redaction_keys);
 * this pins the webhook log onto that same path, and pins that the outgoing
 * delivery (and its signature) is untouched.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-mcpwp-webhooks.php';

// Loading the class makes it exist for every test in the run, and
// Mcpwp_Event_Store::emit() triggers webhooks whenever it does. Seed the
// singleton without its constructor (which creates tables through dbDelta),
// so those emits reach a trigger() that finds no webhooks, as before.
( static function () {
	$ref      = new ReflectionClass( Mcpwp_Webhooks::class );
	$instance = $ref->getProperty( 'instance' );
	$instance->setAccessible( true );
	if ( null === $instance->getValue() ) {
		$seed  = $ref->newInstanceWithoutConstructor();
		$table = $ref->getProperty( 'table' );
		$table->setAccessible( true );
		$table->setValue( $seed, 'wp_mcpwp_webhooks' );
		$instance->setValue( null, $seed );
	}
} )();

final class WebhookLogRedactionTest extends TestCase {

	/** @var array Captured outgoing requests. */
	private $sent = array();

	/** @var mixed Response the fake receiver answers with. */
	private $answer;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']   = array();
		$GLOBALS['mcpwp_test_filters']   = array();
		$GLOBALS['mcpwp_test_wpdb_rows'] = array();
		$this->sent                      = array();
		$this->answer                    = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"ok":true,"session_token":"rcv_9f8e7d6c5b4a3f2e1d0c"}',
		);

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				$this->sent[] = array( 'url' => $url, 'args' => $args );
				return $this->answer;
			},
			10,
			3
		);
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_filters'] = array();
	}

	private function webhooks(): Mcpwp_Webhooks {
		$ref      = new ReflectionClass( Mcpwp_Webhooks::class );
		$instance = $ref->newInstanceWithoutConstructor();
		$table    = $ref->getProperty( 'table' );
		$table->setAccessible( true );
		$table->setValue( $instance, 'wp_mcpwp_webhooks' );
		return $instance;
	}

	private function deliver( array $payload ): void {
		$webhook = array(
			'id'          => 7,
			'url'         => 'https://93.184.216.34/hook',
			'secret'      => 'whsec_signing_secret_value',
			'retry_count' => 0,
		);
		$method  = new ReflectionMethod( Mcpwp_Webhooks::class, 'deliver' );
		$method->setAccessible( true );
		$method->invoke( $this->webhooks(), $webhook, 'post.updated', $payload );
	}

	private function payload(): array {
		return array(
			'post'      => array( 'id' => 12, 'title' => 'Spring sale' ),
			'api_key'   => 'mcpwp_0123456789abcdef0123456789abcdef',
			'author'    => array( 'name' => 'Ada', 'password' => 'hunter2-hunter2' ),
			'event'     => 'post.updated',
		);
	}

	private function log_row(): array {
		$rows = $GLOBALS['mcpwp_test_wpdb_rows']['wp_mcpwp_webhook_logs'] ?? array();
		$this->assertCount( 1, $rows, 'Exactly one delivery log row is written.' );
		return $rows[0];
	}

	public function test_the_receiver_gets_the_unredacted_payload_and_a_matching_signature(): void {
		$payload = $this->payload();
		$this->deliver( $payload );

		$this->assertCount( 1, $this->sent );
		$body = $this->sent[0]['args']['body'];
		$this->assertSame( wp_json_encode( $payload ), $body, 'The delivery itself is never redacted.' );
		$this->assertStringContainsString( 'mcpwp_0123456789abcdef0123456789abcdef', $body );
		$this->assertStringContainsString( 'hunter2-hunter2', $body );
		$this->assertSame( hash_hmac( 'sha256', $body, 'whsec_signing_secret_value' ), $this->sent[0]['args']['headers']['X-MCPWP-Signature'] );
	}

	public function test_the_logged_payload_is_redacted_through_the_shared_log_redactor(): void {
		$this->deliver( $this->payload() );
		$row = $this->log_row();

		$this->assertStringNotContainsString( 'mcpwp_0123456789abcdef0123456789abcdef', $row['payload'] );
		$this->assertStringNotContainsString( 'hunter2-hunter2', $row['payload'] );

		$logged = json_decode( $row['payload'], true );
		$this->assertIsArray( $logged, 'The logged payload stays JSON.' );
		$this->assertSame( '[redacted]', $logged['api_key'] );
		$this->assertSame( '[redacted]', $logged['author']['password'] );
		$this->assertSame( 'Spring sale', $logged['post']['title'], 'Only secrets are removed.' );
		$this->assertSame( 'Ada', $logged['author']['name'] );
	}

	public function test_a_site_configured_redaction_key_is_honoured_in_the_webhook_log(): void {
		$GLOBALS['mcpwp_test_options']['mcpwp_settings'] = array( 'log_redaction_keys' => array( 'customer_note' ) );
		$payload                                         = $this->payload();
		$payload['customer_note']                        = 'gate code 4471';
		$this->deliver( $payload );

		$logged = json_decode( $this->log_row()['payload'], true );
		$this->assertSame( '[redacted]', $logged['customer_note'] );
		$this->assertStringContainsString( 'gate code 4471', $this->sent[0]['args']['body'], 'Still delivered.' );
	}

	public function test_a_json_response_body_is_redacted_in_the_log(): void {
		$this->deliver( $this->payload() );
		$row = $this->log_row();

		$this->assertStringNotContainsString( 'rcv_9f8e7d6c5b4a3f2e1d0c', $row['response_body'] );
		$logged = json_decode( $row['response_body'], true );
		$this->assertIsArray( $logged );
		$this->assertTrue( $logged['ok'] );
		$this->assertSame( '[redacted]', $logged['session_token'] );
	}

	public function test_a_non_json_response_body_goes_through_the_string_handling(): void {
		$this->answer = array(
			'response' => array( 'code' => 200 ),
			'body'     => 'sk_live_51HqLyjWDarjtT1zdp7dcXsJ4kV',
		);
		$this->deliver( $this->payload() );
		$this->assertSame( '[redacted]', $this->log_row()['response_body'], 'A bare credential is redacted.' );

		$GLOBALS['mcpwp_test_wpdb_rows'] = array();
		$this->answer                    = array(
			'response' => array( 'code' => 202 ),
			'body'     => 'Accepted, thanks',
		);
		$this->deliver( $this->payload() );
		$this->assertSame( 'Accepted, thanks', $this->log_row()['response_body'], 'Plain text is kept as it was.' );
	}

	public function test_a_transport_error_is_still_logged(): void {
		$this->answer = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		$this->deliver( $this->payload() );
		$row = $this->log_row();

		$this->assertSame( 0, $row['response_code'] );
		$this->assertSame( 'cURL error 28: Operation timed out', $row['response_body'] );
		$this->assertStringNotContainsString( 'hunter2-hunter2', $row['payload'] );
	}

	public function test_rows_logged_before_the_fix_are_redacted_when_read(): void {
		// Rows written by 3.11.0 and earlier hold the raw bytes; the logs
		// endpoint must not hand them out as they are.
		$original        = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = new class() extends Mcpwp_Test_WPDB {
			public function get_results( $query = '', $output = ARRAY_A ) {
				return array(
					array(
						'id'            => 1,
						'webhook_id'    => 7,
						'event'         => 'post.updated',
						'payload'       => '{"api_key":"mcpwp_0123456789abcdef0123456789abcdef","post":{"title":"Spring sale"}}',
						'response_code' => 200,
						'response_body' => '{"session_token":"rcv_9f8e7d6c5b4a3f2e1d0c"}',
					),
				);
			}
			public function get_var( $query = '', $x = 0, $y = 0 ) {
				return 1;
			}
		};

		try {
			$logs = $this->webhooks()->get_logs( 7 );
		} finally {
			$GLOBALS['wpdb'] = $original;
		}

		$this->assertCount( 1, $logs['logs'] );
		$row = $logs['logs'][0];
		$this->assertStringNotContainsString( 'mcpwp_0123456789abcdef0123456789abcdef', $row['payload'] );
		$this->assertStringNotContainsString( 'rcv_9f8e7d6c5b4a3f2e1d0c', $row['response_body'] );
		$this->assertSame( 'Spring sale', json_decode( $row['payload'], true )['post']['title'] );
		$this->assertSame( 1, $logs['total'] );
	}

	// -----------------------------------------------------------------
	// Review r1 P2-B: a receiver can echo the payload back in a body the
	// structural redactor cannot open. The site knows exactly which secret
	// values it redacted from the payload, so those values are removed from
	// the response body and error message in every common encoding.
	// -----------------------------------------------------------------

	private const SPECIAL_SECRET = 'p@ss/w0rd+&"x<y>z';

	private function payload_with_secrets(): array {
		return $this->payload() + array(
			'client_secret' => self::SPECIAL_SECRET,
			'token'         => 'abc12', // below the minimum scrub length (6)
		);
	}

	private function deliver_payload( array $payload ): void {
		$webhook = array(
			'id'          => 7,
			'url'         => 'https://93.184.216.34/hook',
			'secret'      => 'whsec_signing_secret_value',
			'retry_count' => 0,
		);
		$method  = new ReflectionMethod( Mcpwp_Webhooks::class, 'deliver' );
		$method->setAccessible( true );
		$method->invoke( $this->webhooks(), $webhook, 'post.updated', $payload );
	}

	private function answer_with( $body, int $code = 500 ): void {
		$this->answer                    = array( 'response' => array( 'code' => $code ), 'body' => $body );
		$GLOBALS['mcpwp_test_wpdb_rows'] = array();
	}

	private function assert_no_payload_secret( string $stored, string $shape ): void {
		foreach ( array( 'mcpwp_0123456789abcdef0123456789abcdef', 'hunter2-hunter2', self::SPECIAL_SECRET ) as $secret ) {
			$json = substr( json_encode( $secret ), 1, -1 );
			$forms = array( $secret, rawurlencode( $secret ), urlencode( $secret ), htmlspecialchars( $secret, ENT_QUOTES ), $json, htmlspecialchars( $json, ENT_QUOTES ) );
			foreach ( array_unique( $forms ) as $form ) {
				$this->assertStringNotContainsString( $form, $stored, "$shape: a payload secret came back in the log as '$form'." );
			}
		}
	}

	private function stored_response_after( $body, string $shape ): string {
		$this->answer_with( $body );
		$this->deliver_payload( $this->payload_with_secrets() );
		$row = $this->log_row();
		$this->assert_no_payload_secret( $row['payload'], "$shape (payload column)" );
		return $row['response_body'];
	}

	public function test_an_html_debug_page_echoing_the_body_is_scrubbed(): void {
		$json = wp_json_encode( $this->payload_with_secrets() );

		$raw = $this->stored_response_after( '<!DOCTYPE html><html><body><h1>ValueError at /hook</h1><pre>' . $json . '</pre></body></html>', 'HTML, raw echo' );
		$this->assert_no_payload_secret( $raw, 'HTML, raw echo' );
		$this->assertStringContainsString( 'ValueError at /hook', $raw, 'The rest of the page is kept.' );

		$escaped = $this->stored_response_after( '<html><body><table><tr><td>POST</td><td>' . htmlspecialchars( $json, ENT_QUOTES ) . '</td></tr></table></body></html>', 'HTML, escaped echo' );
		$this->assert_no_payload_secret( $escaped, 'HTML, escaped echo' );
	}

	public function test_a_form_encoded_echo_is_scrubbed(): void {
		$stored = $this->stored_response_after( http_build_query( $this->payload_with_secrets() ), 'form-encoded' );
		$this->assert_no_payload_secret( $stored, 'form-encoded' );
	}

	public function test_an_xml_echo_is_scrubbed(): void {
		$body   = '<?xml version="1.0"?><request><api_key>mcpwp_0123456789abcdef0123456789abcdef</api_key><author><password>hunter2-hunter2</password></author><client_secret>' . htmlspecialchars( self::SPECIAL_SECRET, ENT_XML1 | ENT_QUOTES ) . '</client_secret></request>';
		$stored = $this->stored_response_after( $body, 'XML' );
		$this->assert_no_payload_secret( $stored, 'XML' );
		$this->assertStringContainsString( '<request>', $stored );
	}

	public function test_a_bom_prefixed_json_body_is_opened_and_redacted(): void {
		$stored = $this->stored_response_after( "\xEF\xBB\xBF" . '{"ok":false,"session_token":"rcv_9f8e7d6c5b4a3f2e1d0c"}', 'BOM JSON' );
		$this->assertStringNotContainsString( 'rcv_9f8e7d6c5b4a3f2e1d0c', $stored, 'A receiver-issued token in BOM-prefixed JSON is redacted by key.' );
		$decoded = json_decode( $stored, true );
		$this->assertIsArray( $decoded );
		$this->assertSame( '[redacted]', $decoded['session_token'] );

		$echo = $this->stored_response_after( "\xEF\xBB\xBF" . wp_json_encode( array( 'received' => $this->payload_with_secrets() ) ), 'BOM JSON echo' );
		$this->assert_no_payload_secret( $echo, 'BOM JSON echo' );
	}

	public function test_a_malformed_json_echo_is_scrubbed(): void {
		$stored = $this->stored_response_after( '{"received":{"password":"hunter2-hunter2","api_key":"mcpwp_0123456789abcdef0123456789abcdef","client_secret":' . json_encode( self::SPECIAL_SECRET ) . ',},', 'malformed JSON' );
		$this->assert_no_payload_secret( $stored, 'malformed JSON' );
	}

	public function test_a_plain_text_echo_and_an_error_message_are_scrubbed(): void {
		$stored = $this->stored_response_after( 'Error: password=hunter2-hunter2 rejected; client_secret=' . self::SPECIAL_SECRET . ' is not valid', 'text' );
		$this->assert_no_payload_secret( $stored, 'text' );
		$this->assertStringContainsString( 'is not valid', $stored );

		$this->answer                    = new WP_Error( 'http_request_failed', 'cURL error 22: 400 for body api_key=mcpwp_0123456789abcdef0123456789abcdef&password=hunter2-hunter2' );
		$GLOBALS['mcpwp_test_wpdb_rows'] = array();
		$this->deliver_payload( $this->payload_with_secrets() );
		$this->assert_no_payload_secret( $this->log_row()['response_body'], 'WP_Error message' );
	}

	public function test_a_short_secret_below_the_minimum_length_is_not_scrubbed(): void {
		// Values under 6 bytes are left alone: scrubbing "1", "ok" or a
		// year would rewrite unrelated text across the whole body. The
		// payload column still redacts them by key.
		$stored = $this->stored_response_after( 'status abc12 accepted', 'short value' );
		$this->assertSame( 'status abc12 accepted', $stored );
	}

	private function read_logs( array $row ): array {
		$original        = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = new class( $row ) extends Mcpwp_Test_WPDB {
			private $row;
			public function __construct( array $row ) {
				$this->row = $row;
			}
			public function get_results( $query = '', $output = ARRAY_A ) {
				return array( $this->row );
			}
			public function get_var( $query = '', $x = 0, $y = 0 ) {
				return 1;
			}
		};
		try {
			return $this->webhooks()->get_logs( 7 )['logs'][0];
		} finally {
			$GLOBALS['wpdb'] = $original;
		}
	}

	public function test_a_legacy_row_is_scrubbed_on_read_from_its_own_payload(): void {
		$payload = $this->payload_with_secrets();
		$row     = $this->read_logs(
			array(
				'id'            => 3,
				'webhook_id'    => 7,
				'event'         => 'post.updated',
				'payload'       => wp_json_encode( $payload ),
				'response_code' => 500,
				'response_body' => '<pre>' . htmlspecialchars( wp_json_encode( $payload ), ENT_QUOTES ) . '</pre> password=hunter2-hunter2',
			)
		);
		$this->assert_no_payload_secret( $row['payload'], 'legacy payload' );
		$this->assert_no_payload_secret( $row['response_body'], 'legacy response_body' );
	}

	public function test_a_legacy_row_cut_at_the_column_limit_is_not_shown(): void {
		// <= 3.11.0 cut bodies at 65535 bytes; a cut JSON body can no longer
		// be opened, so a secret in it is invisible to every redactor.
		$cut = substr( '{"password":"hunter2hunter2","filler":"' . str_repeat( 'a b ', 20000 ), 0, 65535 );
		$row = $this->read_logs(
			array(
				'id'            => 4,
				'webhook_id'    => 7,
				'event'         => 'post.updated',
				'payload'       => '{"post":{"id":1}}',
				'response_code' => 200,
				'response_body' => $cut,
			)
		);
		$this->assertStringNotContainsString( 'hunter2hunter2', $row['response_body'] );
		$this->assertStringContainsString( 'not shown', $row['response_body'] );
	}

	public function test_a_new_row_cut_at_the_column_limit_is_marked_and_still_shown(): void {
		$this->answer_with( str_repeat( 'x', 70000 ), 200 );
		$this->deliver_payload( $this->payload() );
		$stored = $this->log_row()['response_body'];

		$this->assertLessThanOrEqual( 65535, strlen( $stored ) );
		$this->assertStringEndsWith( '[truncated]', $stored );

		$row = $this->read_logs(
			array(
				'id'            => 5,
				'webhook_id'    => 7,
				'event'         => 'post.updated',
				'payload'       => '{"post":{"id":1}}',
				'response_code' => 200,
				'response_body' => $stored,
			)
		);
		$this->assertSame( $stored, $row['response_body'], 'A row cut by this version is not mistaken for a legacy one.' );
	}

	// -----------------------------------------------------------------
	// Review r2 P3-A: secrets with non-ASCII or & < > " ' characters come
	// back in encodings beyond the first set — Python's ensure_ascii JSON,
	// Go's HTML-safe JSON, HTML with only some characters escaped, and JSON
	// escaped twice.
	// -----------------------------------------------------------------

	private function scrubbed_response_for( array $secrets, string $body ): string {
		$payload = array( 'post' => array( 'id' => 9 ) );
		foreach ( $secrets as $i => $secret ) {
			$payload[ 'secret_' . $i ] = $secret;
		}
		$this->answer_with( $body );
		$this->deliver_payload( $payload );
		$row = $this->log_row();
		foreach ( $secrets as $i => $secret ) {
			$this->assertStringNotContainsString( $secret, $row['payload'] );
		}
		return $row['response_body'];
	}

	public function test_python_ensure_ascii_json_of_a_non_ascii_secret_is_scrubbed(): void {
		$secret = 'pässwörd/secret1';
		// json.dumps(secret): non-ASCII as lower-case \u escapes, slash kept.
		$python = json_encode( $secret, JSON_UNESCAPED_SLASHES );
		$this->assertStringContainsString( '\u00e4', $python, 'Fixture: the Python form.' );

		$stored = $this->scrubbed_response_for( array( $secret ), '<html><pre>{"client_secret": ' . $python . '}</pre></html>' );
		$this->assertStringNotContainsString( substr( $python, 1, -1 ), $stored );
	}

	public function test_go_html_safe_json_of_a_secret_is_scrubbed(): void {
		$secret = 'abc&def<ghi>jk';
		// encoding/json: & < > as lower-case \u0026 \u003c \u003e.
		$go = str_replace( array( '&', '<', '>' ), array( '\u0026', '\u003c', '\u003e' ), $secret );

		$stored = $this->scrubbed_response_for( array( $secret ), 'upstream rejected {"api_secret":"' . $go . '"} with 401' );
		$this->assertStringNotContainsString( $go, $stored );
		$this->assertStringNotContainsString( strtoupper( '\u003c' ), $stored );
	}

	public function test_html_escaping_without_quotes_is_scrubbed(): void {
		$secret  = 'ab"cd&ef12';
		$noquote = htmlspecialchars( json_encode( array( 'password' => $secret ) ), ENT_NOQUOTES );
		$compat  = htmlspecialchars( $secret, ENT_COMPAT );
		$apos    = str_replace( "'", '&#39;', htmlspecialchars( "it's-" . $secret, ENT_NOQUOTES ) );

		$stored = $this->scrubbed_response_for( array( $secret, "it's-" . $secret ), '<pre>' . $noquote . '</pre><p>' . $compat . '</p><p>' . $apos . '</p>' );
		$this->assertStringNotContainsString( 'ab\"cd&amp;ef12', $stored );
		$this->assertStringNotContainsString( $compat, $stored );
		$this->assertStringNotContainsString( 'it&#39;s-', $stored );
	}

	public function test_json_escaped_twice_is_scrubbed(): void {
		$secret = 'ab"cd12efgh';
		$twice  = json_encode( json_encode( array( 'password' => $secret ) ) );
		$this->assertStringContainsString( 'ab\\\\\\"cd12efgh', $twice, 'Fixture: the doubly escaped form.' );

		$stored = $this->scrubbed_response_for( array( $secret ), "b'" . $twice . "'" );
		$this->assertStringNotContainsString( 'ab\\\\\\"cd12efgh', $stored );
		$this->assertStringNotContainsString( 'cd12efgh', $stored );
	}

	public function test_the_test_endpoint_returns_the_receiver_body_redacted(): void {
		// Review r2 P3-D: POST /webhooks/{id}/test returned — and the activity
		// log stored — whatever the receiver answered.
		$this->answer    = array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true,"session_token":"rcv_9f8e7d6c5b4a3f2e1d0c"}' );
		$original        = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = new class() extends Mcpwp_Test_WPDB {
			private function hook() {
				return array( 'id' => 7, 'name' => 'hook', 'url' => 'https://93.184.216.34/hook', 'secret' => 'whsec_signing_secret_value', 'events' => '["post.updated"]', 'status' => 'active', 'retry_count' => 0 );
			}
			public function get_row( $query = '', $output = OBJECT, $y = 0 ) {
				return ARRAY_A === $output ? $this->hook() : (object) $this->hook();
			}
			public function get_results( $query = '', $output = ARRAY_A ) {
				return array( $this->hook() );
			}
		};
		try {
			$result = $this->webhooks()->test( 7 );
		} finally {
			$GLOBALS['wpdb'] = $original;
		}

		$this->assertIsArray( $result );
		$this->assertCount( 1, $this->sent, 'The test delivery went out.' );
		$this->assertSame( 200, $result['response_code'] );
		$this->assertStringNotContainsString( 'rcv_9f8e7d6c5b4a3f2e1d0c', $result['response_body'] );
		$this->assertSame( '[redacted]', json_decode( $result['response_body'], true )['session_token'] );
	}
}
