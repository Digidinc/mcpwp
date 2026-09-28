<?php
/**
 * Regression tests — draft/pending/private body leak via GET /blocks/{id}.
 * get_blocks() returns raw_content + parsed blocks for any status, so a
 * read-only key could otherwise read an unpublished body. Closes the
 * sibling-endpoint bypass of the /rendered-* gate (PR #683).
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-blocks.php';

final class RestBlocksDraftLeakTest extends TestCase {

	private const PUBLISHED_ID = 401;
	private const DRAFT_ID     = 402;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']            = array();
		$GLOBALS['mcpwp_test_transients']         = array();
		$GLOBALS['mcpwp_test_current_user']       = 0;
		$GLOBALS['mcpwp_test_is_admin']           = false;
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		$GLOBALS['_mcpwp_test_meta']              = array();

		$GLOBALS['_mcpwp_test_posts'] = array(
			self::PUBLISHED_ID => new WP_Post(
				array(
					'ID'           => self::PUBLISHED_ID,
					'post_type'    => 'post',
					'post_title'   => 'Public post',
					'post_status'  => 'publish',
					'post_content' => '<!-- wp:paragraph --><p>Public body.</p><!-- /wp:paragraph -->',
				)
			),
			self::DRAFT_ID     => new WP_Post(
				array(
					'ID'           => self::DRAFT_ID,
					'post_type'    => 'post',
					'post_title'   => 'Secret draft',
					'post_status'  => 'draft',
					'post_content' => '<!-- wp:paragraph --><p>SECRET unpublished body.</p><!-- /wp:paragraph -->',
				)
			),
		);
	}

	/**
	 * @return array{0:Mcpwp_REST_Blocks,1:WP_REST_Request}
	 */
	private function authed( int $id, array $scopes ): array {
		$controller = new Mcpwp_REST_Blocks();
		$created    = $controller->create_scoped_api_key( 'test key', $scopes, 'custom', array( 'gutenberg' ) );
		$request    = new WP_REST_Request( 'GET', '/mcpwp/v1/blocks/' . $id, array( 'id' => $id ), array( 'X-API-Key' => $created['key'] ) );
		$this->verdict = $controller->verify_api_key( $request );
		return array( $controller, $request );
	}

	/** @var true|WP_Error Permission verdict of the last authenticated request. */
	private $verdict = true;

	/**
	 * What a real request gets: the permission verdict when it refuses
	 * (#1030 round-5 — the declared-post-ref guard answers a hidden or
	 * missing id itself, before any handler), otherwise the handler's answer.
	 */
	private function dispatch( $controller, string $method, $request ) {
		return true === $this->verdict ? $controller->$method( $request ) : $this->verdict;
	}

	public function test_draft_read_key_blocked_as_404(): void {
		[ $c, $r ] = $this->authed( self::DRAFT_ID, array( 'read' ) );
		$resp = $this->dispatch( $c, 'get_blocks', $r );
		$this->assertInstanceOf( WP_Error::class, $resp );
		$this->assertSame( 'not_found', $resp->get_error_code() );
		$this->assertSame( 404, $resp->get_error_data()['status'] );
	}

	public function test_draft_write_key_returns_body(): void {
		[ $c, $r ] = $this->authed( self::DRAFT_ID, array( 'write' ) );
		$resp = $this->dispatch( $c, 'get_blocks', $r );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 200, $resp->get_status() );
		$this->assertStringContainsString( 'SECRET', $resp->get_data()['raw_content'] );
	}

	public function test_published_read_key_returns_body(): void {
		[ $c, $r ] = $this->authed( self::PUBLISHED_ID, array( 'read' ) );
		$resp = $this->dispatch( $c, 'get_blocks', $r );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 200, $resp->get_status() );
		$this->assertStringContainsString( 'Public body', $resp->get_data()['raw_content'] );
	}
}
