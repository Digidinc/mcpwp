<?php
/**
 * Mcpwp_REST_AI_Presence::submit_indexnow() — order-of-operations and
 * permanent-failure surfacing fixes from the #937 adversarial review.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

// The controller under test is not loaded by the shared bootstrap.
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-ai-presence.php';

final class RestAiPresenceIndexNowSubmitTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']       = array();
		$GLOBALS['mcpwp_test_wpdb_options']   = array();
		$GLOBALS['mcpwp_test_filters']        = array();
		$GLOBALS['mcpwp_test_transients']     = array();
		$GLOBALS['mcpwp_test_cron']           = array();
		$GLOBALS['_mcpwp_test_posts']         = array();
		$GLOBALS['_mcpwp_test_meta']          = array();
		$GLOBALS['mcpwp_test_url_to_postid']  = array();
		putenv( 'WP_ENVIRONMENT_TYPE' );

		( new Mcpwp_IndexNow() )->update_settings( array( 'enabled' => true ) );
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_filters'] = array();
		// Tests here seed bare stdClass posts (no post_title). Left behind,
		// the next get_posts() caller -- /onboard's recent_updates in
		// AdversarialReview1007Round1Test -- read an undefined property (#1084).
		$GLOBALS['_mcpwp_test_posts'] = array();
		putenv( 'WP_ENVIRONMENT_TYPE' );
	}

	/**
	 * #937 NOTE-6: a batch that fails validation entirely must not consume
	 * the 1/minute explicit-submit throttle — the caller made no successful
	 * submission and must be free to immediately retry with corrected URLs.
	 */
	public function test_a_fully_invalid_batch_does_not_consume_the_throttle(): void {
		$controller = new Mcpwp_REST_AI_Presence();

		$bad_request = new WP_REST_Request( 'POST', '/mcpwp/v1/indexnow/submit', array(
			'urls' => array( 'https://example.com/?s=nonsense' ), // query string -> rejected
		) );
		$first = $controller->submit_indexnow( $bad_request );
		$this->assertInstanceOf( 'WP_Error', $first );
		$this->assertSame( 'indexnow_no_valid_urls', $first->get_error_code() );

		// The throttle must still be free — prove it by making a SECOND call
		// with a URL that DOES validate and confirming it is not throttled.
		$GLOBALS['_mcpwp_test_posts'][9] = (object) array( 'ID' => 9, 'post_type' => 'page', 'post_status' => 'publish', 'post_password' => '' );
		$GLOBALS['mcpwp_test_url_to_postid']['https://example.com/real-page/'] = 9;
		add_filter( 'pre_http_request', function () {
			return array( 'response' => array( 'code' => 202 ), 'body' => '' );
		} );

		$good_request = new WP_REST_Request( 'POST', '/mcpwp/v1/indexnow/submit', array(
			'urls' => array( 'https://example.com/real-page/' ),
		) );
		$second = $controller->submit_indexnow( $good_request );

		$this->assertNotInstanceOf( 'WP_Error', $second, 'the invalid first call must not have consumed the throttle' );
	}

	/**
	 * A batch that DOES have at least one valid URL, and succeeds, must have
	 * consumed the throttle — an immediate second call is refused.
	 */
	public function test_a_successful_submission_does_consume_the_throttle(): void {
		$controller = new Mcpwp_REST_AI_Presence();

		$GLOBALS['_mcpwp_test_posts'][9] = (object) array( 'ID' => 9, 'post_type' => 'page', 'post_status' => 'publish', 'post_password' => '' );
		$GLOBALS['mcpwp_test_url_to_postid']['https://example.com/real-page/'] = 9;
		add_filter( 'pre_http_request', function () {
			return array( 'response' => array( 'code' => 202 ), 'body' => '' );
		} );

		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/indexnow/submit', array(
			'urls' => array( 'https://example.com/real-page/' ),
		) );
		$first  = $controller->submit_indexnow( $request );
		$this->assertNotInstanceOf( 'WP_Error', $first );

		$second = $controller->submit_indexnow( $request );
		$this->assertInstanceOf( 'WP_Error', $second );
		$this->assertSame( 'indexnow_throttled', $second->get_error_code() );
	}
}
