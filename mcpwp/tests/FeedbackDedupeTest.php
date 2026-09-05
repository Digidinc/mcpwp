<?php
/**
 * Regression coverage for GitHub dedupe-search outcome classification.
 *
 * `Mcpwp_Feedback::create_github_issue_from_relay()` searches GitHub for an
 * existing open issue with the same title before creating a new one. A
 * `WP_Error` from that search is handled, but a *successful* HTTP transaction
 * carrying an error body (403 rate-limited, 422, 5xx) is not a `WP_Error` —
 * it is a response with no `total_count` key, which the old code treated
 * identically to "zero matches found". That silently turns a GitHub-side
 * rate limit into a duplicate-issue generator (#748).
 *
 * These tests mock the WordPress HTTP layer via the `pre_http_request`
 * filter (the same short-circuit contract WordPress itself uses), exactly
 * as the plugin's own stub in tests/bootstrap.php expects.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-feedback.php';

final class FeedbackDedupeTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options'] = array(
			'mcpwp_settings' => array(
				'github_token' => 'test-token',
				'github_repo'  => 'acme/widget',
			),
		);
		$GLOBALS['mcpwp_test_filters'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_options'] = array();
		$GLOBALS['mcpwp_test_filters'] = array();
	}

	/**
	 * Registers a pre_http_request mock. $search_response and
	 * $create_response are each either a WP_Error or an array shaped like a
	 * real wp_remote_* response (['response' => ['code' => int], 'body' => string]).
	 * Returns an array capturing the args passed to the create-issue POST
	 * (by reference target populated once that call happens).
	 */
	private function mock_http( $search_response, $create_response, array &$create_request ) {
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $search_response, $create_response, &$create_request ) {
				if ( false !== strpos( $url, 'search/issues' ) ) {
					return $search_response;
				}

				$create_request = $args;
				return $create_response;
			},
			10,
			3
		);
	}

	private function relay_data(): array {
		return array(
			'type'        => 'bug_report',
			'title'       => 'Something broke',
			'description' => 'It broke.',
			'agent'       => 'test-agent',
			'priority'    => 'high',
		);
	}

	public function test_403_search_does_not_silently_become_no_duplicate(): void {
		$search_response = array(
			'response' => array( 'code' => 403 ),
			'body'     => wp_json_encode( array( 'message' => 'API rate limit exceeded' ) ),
		);
		$create_response = array(
			'response' => array( 'code' => 201 ),
			'body'     => wp_json_encode( array( 'html_url' => 'https://github.com/acme/widget/issues/101' ) ),
		);

		$create_request = array();
		$this->mock_http( $search_response, $create_response, $create_request );

		$result = Mcpwp_Feedback::create_github_issue_from_relay( $this->relay_data() );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'https://github.com/acme/widget/issues/101', $result['github_issue_url'] );

		$this->assertNotEmpty( $create_request, 'The relay must still create an issue when dedupe search fails.' );
		$payload = json_decode( $create_request['body'], true );
		$this->assertStringContainsString(
			'403',
			$payload['body'],
			'The created issue body must record the HTTP status that made dedupe search unavailable.'
		);
		$this->assertMatchesRegularExpression(
			'/duplicate.{0,40}unavailable|unavailable.{0,40}duplicate/i',
			$payload['body'],
			'The created issue body must clearly flag that duplicate detection did not run.'
		);
	}

	public function test_5xx_search_does_not_silently_become_no_duplicate(): void {
		$search_response = array(
			'response' => array( 'code' => 502 ),
			'body'     => 'Bad Gateway',
		);
		$create_response = array(
			'response' => array( 'code' => 201 ),
			'body'     => wp_json_encode( array( 'html_url' => 'https://github.com/acme/widget/issues/102' ) ),
		);

		$create_request = array();
		$this->mock_http( $search_response, $create_response, $create_request );

		$result = Mcpwp_Feedback::create_github_issue_from_relay( $this->relay_data() );

		$this->assertTrue( $result['success'] );
		$this->assertNotEmpty( $create_request );
		$payload = json_decode( $create_request['body'], true );
		$this->assertStringContainsString( '502', $payload['body'] );
		$this->assertMatchesRegularExpression(
			'/duplicate.{0,40}unavailable|unavailable.{0,40}duplicate/i',
			$payload['body']
		);
	}

	public function test_200_with_match_still_short_circuits_to_existing_issue(): void {
		$search_response = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode(
				array(
					'total_count' => 1,
					'items'       => array(
						array( 'html_url' => 'https://github.com/acme/widget/issues/7' ),
					),
				)
			),
		);
		// If the create call happens at all, capture it so we can prove it never did.
		$create_response = array(
			'response' => array( 'code' => 201 ),
			'body'     => wp_json_encode( array( 'html_url' => 'https://github.com/acme/widget/issues/999' ) ),
		);

		$create_request = array();
		$this->mock_http( $search_response, $create_response, $create_request );

		$result = Mcpwp_Feedback::create_github_issue_from_relay( $this->relay_data() );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'https://github.com/acme/widget/issues/7', $result['github_issue_url'] );
		$this->assertSame( 'Duplicate issue found, returning existing.', $result['message'] );
		$this->assertEmpty( $create_request, 'A confirmed duplicate must short-circuit before creating a new issue.' );
	}

	public function test_200_with_zero_matches_creates_issue_with_no_marker(): void {
		$search_response = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode(
				array(
					'total_count' => 0,
					'items'       => array(),
				)
			),
		);
		$create_response = array(
			'response' => array( 'code' => 201 ),
			'body'     => wp_json_encode( array( 'html_url' => 'https://github.com/acme/widget/issues/103' ) ),
		);

		$create_request = array();
		$this->mock_http( $search_response, $create_response, $create_request );

		$result = Mcpwp_Feedback::create_github_issue_from_relay( $this->relay_data() );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'https://github.com/acme/widget/issues/103', $result['github_issue_url'] );

		$this->assertNotEmpty( $create_request );
		$payload = json_decode( $create_request['body'], true );
		$this->assertStringNotContainsStringIgnoringCase(
			'duplicate detection unavailable',
			$payload['body'],
			'A clean zero-match search must not be flagged as unavailable.'
		);
	}

	public function test_200_with_match_but_missing_html_url_is_treated_as_unavailable(): void {
		$search_response = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode(
				array(
					'total_count' => 1,
					'items'       => array(
						array( 'number' => 7 ),
					),
				)
			),
		);
		$create_response = array(
			'response' => array( 'code' => 201 ),
			'body'     => wp_json_encode( array( 'html_url' => 'https://github.com/acme/widget/issues/104' ) ),
		);

		$create_request = array();
		$this->mock_http( $search_response, $create_response, $create_request );

		$result = Mcpwp_Feedback::create_github_issue_from_relay( $this->relay_data() );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'https://github.com/acme/widget/issues/104', $result['github_issue_url'] );

		$this->assertNotEmpty(
			$create_request,
			'A total_count > 0 result with no usable html_url must not silently short-circuit; it must fall through to issue creation with a marker.'
		);
		$payload = json_decode( $create_request['body'], true );
		$this->assertMatchesRegularExpression(
			'/duplicate.{0,40}unavailable|unavailable.{0,40}duplicate/i',
			$payload['body'],
			'A confirmed match with no usable html_url must be flagged as dedupe-unavailable, not created silently.'
		);
	}

	public function test_200_with_match_and_empty_html_url_is_treated_as_unavailable(): void {
		$search_response = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode(
				array(
					'total_count' => 1,
					'items'       => array(
						array( 'html_url' => '' ),
					),
				)
			),
		);
		$create_response = array(
			'response' => array( 'code' => 201 ),
			'body'     => wp_json_encode( array( 'html_url' => 'https://github.com/acme/widget/issues/105' ) ),
		);

		$create_request = array();
		$this->mock_http( $search_response, $create_response, $create_request );

		$result = Mcpwp_Feedback::create_github_issue_from_relay( $this->relay_data() );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'https://github.com/acme/widget/issues/105', $result['github_issue_url'] );

		$this->assertNotEmpty( $create_request );
		$payload = json_decode( $create_request['body'], true );
		$this->assertMatchesRegularExpression(
			'/duplicate.{0,40}unavailable|unavailable.{0,40}duplicate/i',
			$payload['body'],
			'An empty-string html_url must be treated the same as a missing one.'
		);
	}

	public function test_search_error_message_is_sanitized_in_issue_body(): void {
		$search_response = new WP_Error(
			'http_request_failed',
			"cURL error 6: Could not resolve host <script>alert('x')</script>\nsecond line"
		);
		$create_response = array(
			'response' => array( 'code' => 201 ),
			'body'     => wp_json_encode( array( 'html_url' => 'https://github.com/acme/widget/issues/106' ) ),
		);

		$create_request = array();
		$this->mock_http( $search_response, $create_response, $create_request );

		$result = Mcpwp_Feedback::create_github_issue_from_relay( $this->relay_data() );

		$this->assertTrue( $result['success'] );
		$this->assertNotEmpty( $create_request );
		$payload = json_decode( $create_request['body'], true );

		$this->assertStringNotContainsString( '<script>', $payload['body'], 'Raw HTTP-stack error text must not carry tags into the issue body unescaped.' );

		$this->assertMatchesRegularExpression(
			'/the search request failed \(([^)]*)\)/',
			$payload['body'],
			'The marker sentence with the embedded error text must be present and confined to a single parenthetical.'
		);
		preg_match( '/the search request failed \(([^)]*)\)/', $payload['body'], $matches );
		$this->assertStringNotContainsString(
			"\n",
			$matches[1],
			'The embedded error text must not inject raw newlines into the marker line.'
		);
	}
}
