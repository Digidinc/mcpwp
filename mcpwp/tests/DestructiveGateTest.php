<?php
/**
 * Coverage for #536 — destructive operations go through approvals by default.
 *
 * A force delete on a fresh install was gone immediately. The approval
 * workflow existed and nothing used it by default. Now: a permanent delete,
 * purging all drafts, deleting media and revoking a key are turned into a
 * pending approval request (202) unless the site or the key opted out.
 * Approving and applying the request re-dispatches exactly the reviewed
 * call with the gate lifted for that one dispatch.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class DestructiveGateTest extends TestCase {

	/** @var array */
	private $key;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']           = array();
		$GLOBALS['mcpwp_test_transients']        = array();
		$GLOBALS['_mcpwp_test_posts']            = array();
		$GLOBALS['_mcpwp_test_meta']             = array();
		$GLOBALS['mcpwp_test_current_user']      = 2;
		$GLOBALS['mcpwp_test_filters']           = array();
		$GLOBALS['mcpwp_test_dispatched_routes'] = array();
		Mcpwp_Destructive_Gate::$applying = false;
		if ( method_exists( 'Mcpwp_REST_MCP', 'reset_verdict_memo' ) ) {
			Mcpwp_REST_MCP::reset_verdict_memo();
		}

		$this->key = ( new Mcpwp_REST_Pages() )->create_scoped_api_key( 'agent', array( 'admin' ) );
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_options'] = array();
		$GLOBALS['_mcpwp_test_posts']  = array();
		Mcpwp_Destructive_Gate::$applying = false;
	}

	private function seed_page( int $id ): void {
		$GLOBALS['_mcpwp_test_posts'][ $id ] = (object) array(
			'ID'                => $id,
			'post_type'         => 'page',
			'post_title'        => 'Page ' . $id,
			'post_name'         => 'page-' . $id,
			'post_status'       => 'publish',
			'post_content'      => '',
			'post_excerpt'      => '',
			'post_parent'       => 0,
			'menu_order'        => 0,
			'post_author'       => 1,
			'post_date'         => '2026-06-06 00:00:00',
			'post_date_gmt'     => '2026-06-06 00:00:00',
			'post_modified'     => '2026-06-06 00:00:00',
			'post_modified_gmt' => '2026-06-06 00:00:00',
		);
	}

	private function delete_page( int $id, bool $force, $key = null ) {
		$controller = new Mcpwp_REST_Pages();
		$request    = new WP_REST_Request( 'DELETE', '/mcpwp/v1/pages/' . $id, array( 'id' => $id, 'force' => $force ), array( 'X-API-Key' => $key ?: $this->key['key'] ) );
		$this->assertTrue( $controller->verify_api_key( $request ), 'Premise: the key authenticates.' );
		return $controller->delete_page( $request );
	}

	// ---------------------------------------------------------------
	// Gated by default.
	// ---------------------------------------------------------------

	public function test_a_force_delete_is_deferred_to_an_approval_by_default(): void {
		$this->seed_page( 10 );

		$response = $this->delete_page( 10, true );

		$this->assertInstanceOf( WP_REST_Response::class, $response, is_wp_error( $response ) ? $response->get_error_message() : '' );
		$this->assertSame( 202, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['approval_required'] );
		$this->assertArrayHasKey( 'approval_id', $data );
		$this->assertArrayHasKey( 10, $GLOBALS['_mcpwp_test_posts'], 'Nothing was deleted.' );

		$record = Mcpwp_Approvals::get_request( $data['approval_id'] );
		$this->assertSame( 'pending', $record['status'] );
		$this->assertSame( 'destructive_operation', $record['action'] );
		$this->assertSame( 'page', $record['resource']['type'] );
		$this->assertSame( 10, $record['resource']['id'] );
	}

	public function test_trashing_is_not_gated(): void {
		$this->seed_page( 10 );

		$response = $this->delete_page( 10, false );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status(), 'Trash has an undo; only the permanent delete is gated.' );
	}

	public function test_every_gated_operation_defers(): void {
		$this->seed_page( 10 );

		// delete_post, delete_content, delete_all_drafts (force), revoke_api_key.
		$GLOBALS['_mcpwp_test_posts'][11] = clone $GLOBALS['_mcpwp_test_posts'][10];
		$GLOBALS['_mcpwp_test_posts'][11]->ID        = 11;
		$GLOBALS['_mcpwp_test_posts'][11]->post_type = 'post';

		$cases = array(
			array( new Mcpwp_REST_Posts(), 'delete_post', new WP_REST_Request( 'DELETE', '/mcpwp/v1/posts/11', array( 'id' => 11, 'force' => true ) ) ),
			array( new Mcpwp_REST_Content(), 'delete_content', new WP_REST_Request( 'DELETE', '/mcpwp/v1/content/post/11', array( 'post_type' => 'post', 'id' => 11, 'force' => true ) ) ),
			array( new Mcpwp_REST_Posts(), 'delete_all_drafts', new WP_REST_Request( 'POST', '/mcpwp/v1/drafts/delete-all', array( 'force' => true ) ) ),
			array( new Mcpwp_REST_Site_Updates(), 'revoke_api_key', new WP_REST_Request( 'DELETE', '/mcpwp/v1/api-keys/' . $this->key['id'], array( 'id' => $this->key['id'] ) ) ),
		);

		foreach ( $cases as list( $controller, $method, $request ) ) {
			$request->set_header( 'X-API-Key', $this->key['key'] );
			if ( method_exists( 'Mcpwp_REST_MCP', 'reset_verdict_memo' ) ) {
				Mcpwp_REST_MCP::reset_verdict_memo();
			}
			$this->assertTrue( $controller->verify_api_key( $request ), $method );
			$response = $controller->$method( $request );
			$this->assertInstanceOf( WP_REST_Response::class, $response, $method . ': ' . ( is_wp_error( $response ) ? $response->get_error_message() : '' ) );
			$this->assertSame( 202, $response->get_status(), $method );
			$this->assertSame( $method, $response->get_data()['operation'] );
		}

		$this->assertArrayHasKey( 11, $GLOBALS['_mcpwp_test_posts'] );
	}

	// ---------------------------------------------------------------
	// Approve → apply runs exactly the reviewed call.
	// ---------------------------------------------------------------

	public function test_applying_the_approval_runs_the_stored_operation_once(): void {
		$this->seed_page( 10 );
		$approval_id = $this->delete_page( 10, true )->get_data()['approval_id'];

		$this->assertArrayHasKey( 10, $GLOBALS['_mcpwp_test_posts'] );
		Mcpwp_Approvals::approve_request( $approval_id, 'ok' );

		// Applying without a principal is refused: the replay must run as the
		// reviewer, never unauthenticated. Found on the rig: the first cut's
		// re-dispatch carried no key and the route answered 401.
		$anon = Mcpwp_Approvals::apply_request( $approval_id );
		$this->assertInstanceOf( WP_Error::class, $anon );
		$this->assertSame( 'destructive_replay_unauthenticated', $anon->get_error_code() );
		$this->assertSame( array(), $GLOBALS['mcpwp_test_dispatched_routes'], 'Nothing dispatched without a principal.' );

		$reviewer = ( new Mcpwp_REST_Pages() )->create_scoped_api_key( 'reviewer', array( 'admin' ) );
		$GLOBALS['mcpwp_test_dispatched_requests'] = array();
		$applied = Mcpwp_Approvals::apply_request( $approval_id, array( 'api_key' => $reviewer['key'] ) );

		$this->assertIsArray( $applied, is_wp_error( $applied ) ? $applied->get_error_message() : '' );
		$this->assertSame( 'applied', $applied['status'] );
		$this->assertContains( '/mcpwp/v1/pages/10', $GLOBALS['mcpwp_test_dispatched_routes'], 'The reviewed route was re-dispatched.' );
		$this->assertSame( $reviewer['key'], $GLOBALS['mcpwp_test_dispatched_requests'][0]->get_header( 'X-API-Key' ), 'As the reviewer.' );
		$this->assertFalse( Mcpwp_Destructive_Gate::$applying, 'The gate is lifted only for the dispatch.' );
	}

	public function test_an_unapproved_request_cannot_be_applied_and_has_no_rollback(): void {
		$this->seed_page( 10 );
		$approval_id = $this->delete_page( 10, true )->get_data()['approval_id'];

		$early = Mcpwp_Approvals::apply_request( $approval_id );
		$this->assertInstanceOf( WP_Error::class, $early );
		$this->assertSame( 'approval_not_ready', $early->get_error_code() );

		Mcpwp_Approvals::approve_request( $approval_id, 'ok' );
		Mcpwp_Approvals::apply_request( $approval_id, array( 'api_key' => $this->key['key'] ) );
		$rollback = Mcpwp_Approvals::rollback_request( $approval_id );
		$this->assertInstanceOf( WP_Error::class, $rollback );
		$this->assertSame( 'destructive_rollback_unavailable', $rollback->get_error_code(), 'What a permanent delete removed is gone; say so rather than pretend.' );
	}

	// ---------------------------------------------------------------
	// Opt-outs, both audited.
	// ---------------------------------------------------------------

	public function test_a_key_created_with_allow_destructive_runs_immediately(): void {
		$this->seed_page( 10 );
		$job = ( new Mcpwp_REST_Pages() )->create_scoped_api_key( 'migration job', array( 'admin' ), 'admin', array(), true );

		$response = $this->delete_page( 10, true, $job['key'] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['deleted'] );
	}

	public function test_the_site_can_switch_the_gate_off(): void {
		$this->seed_page( 10 );
		update_option( 'mcpwp_settings', array( 'destructive_requires_approval' => false ) );

		$this->assertSame( 200, $this->delete_page( 10, true )->get_status() );
	}

	public function test_the_gate_cannot_be_lifted_by_anything_a_caller_sends(): void {
		$this->seed_page( 10 );

		$controller = new Mcpwp_REST_Pages();
		$request    = new WP_REST_Request( 'DELETE', '/mcpwp/v1/pages/10', array( 'id' => 10, 'force' => true, 'allow_destructive' => true, 'applying' => true ), array( 'X-API-Key' => $this->key['key'], 'X-MCPWP-Applying' => '1' ) );
		$controller->verify_api_key( $request );

		$this->assertSame( 202, $controller->delete_page( $request )->get_status(), 'The lift is a PHP static set only while an approved request is applied.' );
	}

	public function test_allow_destructive_survives_record_normalisation_and_is_exposed_on_create(): void {
		$job = ( new Mcpwp_REST_Pages() )->create_scoped_api_key( 'job', array( 'admin' ), 'admin', array(), true );
		$keys = get_option( 'mcpwp_api_keys', array() );
		$stored = null;
		foreach ( $keys as $record ) {
			if ( $record['id'] === $job['id'] ) {
				$stored = $record;
			}
		}

		$this->assertTrue( $stored['allow_destructive'] );
		$this->assertTrue( Mcpwp_Destructive_Gate::key_is_exempt( $stored ) );
		$this->assertFalse( Mcpwp_Destructive_Gate::key_is_exempt( array( 'id' => 'x' ) ), 'Absent means gated.' );
	}
}
