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
}
