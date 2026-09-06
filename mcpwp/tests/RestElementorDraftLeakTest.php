<?php
/**
 * Regression tests — draft/pending/private body leak via the Elementor read
 * surface. Closes the sibling-endpoint bypass of the /rendered-* gate (PR #683):
 * a read-only key must never reach an unpublished body through
 * GET /elementor/{id}, /elementor/bulk, /elementor/{id}/summary, or
 * /elementor/{id}/preview.
 *
 * The non-public gate (guard_non_public_post) runs BEFORE Elementor's
 * is_active() check, so the negative case (read key + draft) returns a
 * status-parity 404 regardless of whether Elementor is loaded. Elementor is not
 * installable in this unit harness, so the positive cases (write key + draft,
 * read key + published) assert that the request PASSES the gate — i.e. it
 * reaches the handler and returns the benign `elementor_not_active` error rather
 * than the gate's `not_found` 404. That precisely exercises the added gate.
 *
 * We deliberately do NOT `define('ELEMENTOR_VERSION')`: that constant leaks
 * across the shared PHPUnit process (it is read at file-discovery time) and
 * would flip Mcpwp_Signals and ElementorFindReplaceTest into Elementor code
 * paths that need the real Elementor classes.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-elementor.php';

final class RestElementorDraftLeakTest extends TestCase {

	private const PUBLISHED_ID = 301;
	private const DRAFT_ID     = 302;

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
					'ID'          => self::PUBLISHED_ID,
					'post_type'   => 'page',
					'post_title'  => 'Public Elementor page',
					'post_name'   => 'public-el',
					'post_status' => 'publish',
				)
			),
			self::DRAFT_ID     => new WP_Post(
				array(
					'ID'          => self::DRAFT_ID,
					'post_type'   => 'page',
					'post_title'  => 'Secret Elementor draft',
					'post_name'   => 'secret-el',
					'post_status' => 'draft',
				)
			),
		);
	}

	/**
	 * @return array{0:Mcpwp_REST_Elementor,1:WP_REST_Request}
	 */
	private function authed( string $route, array $params, array $scopes ): array {
		$controller = new Mcpwp_REST_Elementor();
		$created    = $controller->create_scoped_api_key( 'test key', $scopes, 'custom' );
		$request    = new WP_REST_Request( 'GET', $route, $params, array( 'X-API-Key' => $created['key'] ) );
		$this->assertTrue( $controller->verify_api_key( $request ), 'verify_api_key should succeed' );
		return array( $controller, $request );
	}

	/** The gate's response: a status-parity 404 not_found. */
	private function assertBlocked( $resp ): void {
		$this->assertInstanceOf( WP_Error::class, $resp );
		$this->assertSame( 'not_found', $resp->get_error_code() );
		$this->assertSame( 404, $resp->get_error_data()['status'] );
	}

	/**
	 * The request passed the gate and reached the handler. In this harness the
	 * handler then reports that Elementor is unavailable — a benign, non-404
	 * outcome that a genuine draft-block would never produce.
	 *
	 * What the handler then says is incidental to what these tests are for, and
	 * depends on process state this file does not own: `elementor_not_active`
	 * when ELEMENTOR_VERSION is undefined, `elementor_not_loaded` when it is
	 * defined but \Elementor\Plugin is not declared, and an ordinary success
	 * response when the constant is defined and the handler does not need the
	 * class. Pinning one of those made this file depend on whether some OTHER
	 * file had already defined that constant in the shared process: the suite
	 * passed in declaration order and failed under --order-by=random (#757).
	 *
	 * The gate verdict is what is asserted, and it stays exact — the error set
	 * is closed, so a genuinely different failure still fails the test.
	 */
	private function assertPassedGate( $resp ): void {
		if ( $resp instanceof WP_Error ) {
			$this->assertNotSame(
				'not_found',
				$resp->get_error_code(),
				'This is the gate rejecting the request — the exact leak these tests exist to catch.'
			);
			$this->assertContains(
				$resp->get_error_code(),
				array( 'elementor_not_active', 'elementor_not_loaded' ),
				'Past the gate, the only expected failure here is that Elementor is unavailable in this harness.'
			);
			return;
		}

		$this->assertInstanceOf( WP_REST_Response::class, $resp );
	}

	/**
	 * The bulk equivalent: this id reached normal processing rather than being
	 * dropped by the gate. Whether it then succeeded or reported an unavailable
	 * Elementor is process state, not the property under test.
	 */
	private function assertBulkPassedGate( array $data, int $id ): void {
		if ( array_key_exists( $id, $data['errors'] ) ) {
			$this->assertNotSame(
				'Post not found or unsupported type.',
				$data['errors'][ $id ],
				'That is the gate message — this id was blocked, not processed.'
			);
			return;
		}

		$this->assertArrayHasKey( $id, $data['results'] );
	}

	// ── GET /elementor/{id} ──────────────────────────────────────────────

	public function test_get_data_draft_read_key_blocked_as_404(): void {
		[ $c, $r ] = $this->authed( '/mcpwp/v1/elementor/' . self::DRAFT_ID, array( 'id' => self::DRAFT_ID ), array( 'read' ) );
		$this->assertBlocked( $c->get_elementor_data( $r ) );
	}

	public function test_get_data_draft_write_key_passes_gate(): void {
		[ $c, $r ] = $this->authed( '/mcpwp/v1/elementor/' . self::DRAFT_ID, array( 'id' => self::DRAFT_ID ), array( 'write' ) );
		$this->assertPassedGate( $c->get_elementor_data( $r ) );
	}

	public function test_get_data_published_read_key_passes_gate(): void {
		[ $c, $r ] = $this->authed( '/mcpwp/v1/elementor/' . self::PUBLISHED_ID, array( 'id' => self::PUBLISHED_ID ), array( 'read' ) );
		$this->assertPassedGate( $c->get_elementor_data( $r ) );
	}

	// ── GET /elementor/{id}/summary ──────────────────────────────────────

	public function test_summary_draft_read_key_blocked_as_404(): void {
		[ $c, $r ] = $this->authed( '/mcpwp/v1/elementor/' . self::DRAFT_ID . '/summary', array( 'id' => self::DRAFT_ID ), array( 'read' ) );
		$this->assertBlocked( $c->get_elementor_summary( $r ) );
	}

	public function test_summary_draft_write_key_passes_gate(): void {
		[ $c, $r ] = $this->authed( '/mcpwp/v1/elementor/' . self::DRAFT_ID . '/summary', array( 'id' => self::DRAFT_ID ), array( 'write' ) );
		$this->assertPassedGate( $c->get_elementor_summary( $r ) );
	}

	public function test_summary_published_read_key_passes_gate(): void {
		[ $c, $r ] = $this->authed( '/mcpwp/v1/elementor/' . self::PUBLISHED_ID . '/summary', array( 'id' => self::PUBLISHED_ID ), array( 'read' ) );
		$this->assertPassedGate( $c->get_elementor_summary( $r ) );
	}

	// ── GET /elementor/{id}/preview ──────────────────────────────────────

	public function test_preview_draft_read_key_blocked_as_404(): void {
		[ $c, $r ] = $this->authed( '/mcpwp/v1/elementor/' . self::DRAFT_ID . '/preview', array( 'id' => self::DRAFT_ID ), array( 'read' ) );
		$this->assertBlocked( $c->get_elementor_preview( $r ) );
	}

	public function test_preview_draft_write_key_passes_gate(): void {
		[ $c, $r ] = $this->authed( '/mcpwp/v1/elementor/' . self::DRAFT_ID . '/preview', array( 'id' => self::DRAFT_ID ), array( 'write' ) );
		$this->assertPassedGate( $c->get_elementor_preview( $r ) );
	}

	public function test_preview_published_read_key_passes_gate(): void {
		[ $c, $r ] = $this->authed( '/mcpwp/v1/elementor/' . self::PUBLISHED_ID . '/preview', array( 'id' => self::PUBLISHED_ID ), array( 'read' ) );
		$this->assertPassedGate( $c->get_elementor_preview( $r ) );
	}

	// ── GET /elementor/bulk ──────────────────────────────────────────────

	/**
	 * Read key: the draft is filtered out by the gate and appears only in the
	 * errors map with the gate's not-found message — indistinguishable from a
	 * missing id. The published id is passed through to normal processing (its
	 * error is the benign elementor_not_active message, NOT the gate message),
	 * proving the draft alone was gate-blocked.
	 */
	public function test_bulk_read_key_blocks_only_the_draft(): void {
		$ids       = self::PUBLISHED_ID . ',' . self::DRAFT_ID;
		[ $c, $r ] = $this->authed( '/mcpwp/v1/elementor/bulk', array( 'ids' => $ids ), array( 'read' ) );
		$data      = $c->get_elementor_data_bulk( $r )->get_data();

		$this->assertArrayNotHasKey( self::DRAFT_ID, $data['results'] );
		$this->assertArrayHasKey( self::DRAFT_ID, $data['errors'] );
		$this->assertSame( 'Post not found or unsupported type.', $data['errors'][ self::DRAFT_ID ] );
		// Published id was NOT gate-blocked: it reached normal processing.
		$this->assertBulkPassedGate( $data, self::PUBLISHED_ID );
	}

	/**
	 * Write key: no id is gate-blocked — the draft is processed like any other
	 * id (its error is the benign elementor_not_active message, never the gate's
	 * not-found message).
	 */
	public function test_bulk_write_key_blocks_nothing(): void {
		$ids       = self::PUBLISHED_ID . ',' . self::DRAFT_ID;
		[ $c, $r ] = $this->authed( '/mcpwp/v1/elementor/bulk', array( 'ids' => $ids ), array( 'write' ) );
		$data      = $c->get_elementor_data_bulk( $r )->get_data();

		$this->assertBulkPassedGate( $data, self::DRAFT_ID );
	}
}
