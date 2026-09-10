<?php
/**
 * Coverage for #536 — destructive operations go through approvals by default.
 *
 * The first cut's adversarial review found the authority model broken:
 * every gated principal could exempt itself in one ungated call (mint a key
 * with allow_destructive; flip the site setting through the settings API),
 * the two-person rule lived on one controller and not in the store, the
 * wp-admin reviewer could not apply, private page titles leaked through the
 * approvals list, and a flood of gated calls evicted the review queue.
 *
 * Pinned here: exemptions come only from wp-admin; the store itself refuses
 * self-approval; an administrator applies from the Control Room; parameters
 * are sanitised and the target fingerprinted; open records are never
 * evicted; the gated set matches the plugin's own destructive-tool list.
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
		$GLOBALS['mcpwp_test_dispatched_requests'] = array();
		if ( method_exists( 'Mcpwp_REST_MCP', 'reset_verdict_memo' ) ) {
			Mcpwp_REST_MCP::reset_verdict_memo();
		}

		// A limiter built from this test's own (empty) options, not whatever
		// limits the previous test class left in the singleton: the flood test
		// makes 25 calls on one key and is about the approval cap, not rate
		// limiting (random order exposed the inherited burst_limit of 10).
		if ( class_exists( 'Mcpwp_Rate_Limiter' ) ) {
			$limiter = ( new ReflectionClass( Mcpwp_Rate_Limiter::class ) )->getProperty( 'instance' );
			$limiter->setAccessible( true );
			$limiter->setValue( null, null );
		}

		$this->key = ( new Mcpwp_REST_Pages() )->create_scoped_api_key( 'agent', array( 'admin' ) );
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_is_admin']  = false;
		$GLOBALS['mcpwp_test_options']   = array();
		$GLOBALS['_mcpwp_test_posts']    = array();
		$GLOBALS['mcpwp_test_user_caps'] = array();
	}

	private function seed( int $id, string $type = 'page', string $status = 'publish' ): void {
		$GLOBALS['_mcpwp_test_posts'][ $id ] = (object) array(
			'ID'                => $id,
			'post_type'         => $type,
			'post_title'        => ucfirst( $type ) . ' ' . $id,
			'post_name'         => $type . '-' . $id,
			'post_status'       => $status,
			'post_content'      => 'body ' . $id,
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

	private function authed( $controller, WP_REST_Request $request, $key = null ) {
		$request->set_header( 'X-API-Key', $key ?: $this->key['key'] );
		if ( method_exists( 'Mcpwp_REST_MCP', 'reset_verdict_memo' ) ) {
			Mcpwp_REST_MCP::reset_verdict_memo();
		}
		$this->assertTrue( $controller->verify_api_key( $request ), 'Premise: the key authenticates.' );
		return $controller;
	}

	private function delete_page( int $id, bool $force, $key = null ) {
		$controller = new Mcpwp_REST_Pages();
		$request    = new WP_REST_Request( 'DELETE', '/mcpwp/v1/pages/' . $id, array( 'id' => $id, 'force' => $force ) );
		return $this->authed( $controller, $request, $key )->delete_page( $request );
	}

	// ---------------------------------------------------------------
	// Gated by default.
	// ---------------------------------------------------------------

	public function test_a_force_delete_is_deferred_to_an_approval_by_default(): void {
		$this->seed( 10 );

		$response = $this->delete_page( 10, true );

		$this->assertInstanceOf( WP_REST_Response::class, $response, is_wp_error( $response ) ? $response->get_error_message() : '' );
		$this->assertSame( 202, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['approval_required'] );
		$this->assertArrayHasKey( 10, $GLOBALS['_mcpwp_test_posts'], 'Nothing was deleted.' );

		$record = Mcpwp_Approvals::get_request( $data['approval_id'] );
		$this->assertSame( 'pending', $record['status'] );
		$this->assertSame( 'destructive_operation', $record['action'] );
		$this->assertSame( 'page', $record['resource']['type'] );
	}

	public function test_trashing_is_not_gated(): void {
		$this->seed( 10 );

		$this->assertSame( 200, $this->delete_page( 10, false )->get_status(), 'Trash has an undo; only the permanent delete is gated.' );
	}

	public function test_the_gated_set_is_the_plugins_own_destructive_list_minus_the_reversible_and_incident_response_ones(): void {
		$method = new ReflectionMethod( Mcpwp_MCP_Free_Tools::class, 'get_destructive_tools' );
		$method->setAccessible( true );
		$declared = $method->invoke( new Mcpwp_MCP_Free_Tools() );

		// Reversible (menus, webhooks, rate-limit reset) or the incident-response
		// lever (revoke). Every other declared destructive tool must be gated;
		// a new one added to the plugin list without a gate fails here.
		$exempt = array( 'wp_delete_menu', 'wp_delete_menu_item', 'wp_delete_webhook', 'wp_reset_rate_limit', 'wp_revoke_api_key' );
		$gated  = array_map( function ( $op ) { return 'wp_' . $op; }, Mcpwp_Destructive_Gate::GATED );

		foreach ( array_diff( $declared, $exempt ) as $tool ) {
			$this->assertContains( $tool, $gated, $tool . ' is declared destructive and must be gated (or listed as exempt with a reason).' );
		}
		$this->assertContains( 'wp_delete_media', $gated, 'Deleting media removes files from disk.' );
	}

	public function test_every_gated_operation_defers_and_revocation_does_not(): void {
		$this->seed( 10 );
		$this->seed( 11, 'post' );
		$this->seed( 12, 'attachment' );

		$cases = array(
			array( new Mcpwp_REST_Posts(), 'delete_post', new WP_REST_Request( 'DELETE', '/mcpwp/v1/posts/11', array( 'id' => 11, 'force' => true ) ) ),
			array( new Mcpwp_REST_Content(), 'delete_content', new WP_REST_Request( 'DELETE', '/mcpwp/v1/content/post/11', array( 'post_type' => 'post', 'id' => 11, 'force' => true ) ) ),
			array( new Mcpwp_REST_Posts(), 'delete_all_drafts', new WP_REST_Request( 'DELETE', '/mcpwp/v1/drafts/delete-all', array( 'force' => true ) ) ),
			array( new Mcpwp_REST_Media(), 'delete_media', new WP_REST_Request( 'DELETE', '/mcpwp/v1/media/12', array( 'id' => 12, 'force' => true ) ) ),
		);

		foreach ( $cases as list( $controller, $method, $request ) ) {
			$response = $this->authed( $controller, $request )->$method( $request );
			$this->assertInstanceOf( WP_REST_Response::class, $response, $method . ': ' . ( is_wp_error( $response ) ? $response->get_error_message() : '' ) );
			$this->assertSame( 202, $response->get_status(), $method );
			$this->assertSame( $method, $response->get_data()['operation'] );
		}
		$this->assertArrayHasKey( 11, $GLOBALS['_mcpwp_test_posts'] );
		$this->assertArrayHasKey( 12, $GLOBALS['_mcpwp_test_posts'] );

		// Revocation is the incident-response lever and is not gated.
		$victim  = ( new Mcpwp_REST_Pages() )->create_scoped_api_key( 'victim', array( 'admin' ) );
		$request = new WP_REST_Request( 'DELETE', '/mcpwp/v1/api-keys/' . $victim['id'], array( 'id' => $victim['id'] ) );
		$this->assertSame( 200, $this->authed( new Mcpwp_REST_Site_Updates(), $request )->revoke_api_key( $request )->get_status() );
	}

	public function test_a_deferral_is_not_a_way_to_manufacture_records(): void {
		$this->seed( 12, 'attachment' );

		$missing = $this->delete_page( 999999, true );
		$this->assertInstanceOf( WP_Error::class, $missing );
		$this->assertSame( 'not_found', $missing->get_error_code() );

		$wrong_type = $this->delete_page( 12, true );
		$this->assertSame( 'not_found', $wrong_type->get_error_code(), 'An attachment is not a page.' );

		$this->assertSame( array(), Mcpwp_Approvals::list_requests(), 'No record was created for either.' );
	}

	// ---------------------------------------------------------------
	// Exemptions come only from wp-admin.
	// ---------------------------------------------------------------

	public function test_an_api_principal_cannot_mint_an_exempt_key(): void {
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/api-keys', array( 'label' => 'escape', 'scopes' => array( 'admin' ), 'role' => 'admin', 'allow_destructive' => true ) );
		$created = $this->authed( new Mcpwp_REST_Site_Updates(), $request )->create_api_key( $request )->get_data();

		$this->assertArrayHasKey( 'ignored', $created['api_key'], 'The flag is reported as ignored, not silently dropped.' );

		$this->seed( 10 );
		$this->assertSame( 202, $this->delete_page( 10, true, $created['api_key']['key'] )->get_status(), 'The minted key is still gated.' );
	}

	public function test_an_administrator_in_wp_admin_can_mint_an_exempt_key(): void {
		// No API principal on the request AND positive proof of wp-admin:
		// an absent record alone no longer means administrator (round 2).
		$GLOBALS['mcpwp_test_is_admin'] = true;
		$controller = new Mcpwp_REST_Site_Updates();
		$request    = new WP_REST_Request( 'POST', '/mcpwp/v1/api-keys', array( 'label' => 'job', 'scopes' => array( 'admin' ), 'role' => 'admin', 'allow_destructive' => true ) );
		$created    = $controller->create_api_key( $request )->get_data();

		$this->assertArrayNotHasKey( 'ignored', $created['api_key'] );
		$this->seed( 10 );
		$this->assertSame( 200, $this->delete_page( 10, true, $created['api_key']['key'] )->get_status() );
	}

	public function test_the_site_switch_is_not_reachable_through_the_settings_api(): void {
		$request = new WP_REST_Request( 'PUT', '/mcpwp/v1/settings', array( 'destructive_requires_approval' => false ) );
		( new Mcpwp_REST_Site_Settings() )->update_plugin_settings( $request );

		$settings = get_option( 'mcpwp_settings', array() );
		$this->assertArrayNotHasKey( 'destructive_requires_approval', $settings, 'A gated principal cannot switch the gate off at its own scope.' );
		$this->assertTrue( Mcpwp_Destructive_Gate::enabled_for_site() );

		// Set from wp-admin (the option itself), it is honoured.
		update_option( 'mcpwp_settings', array( 'destructive_requires_approval' => false ) );
		$this->seed( 10 );
		$this->assertSame( 200, $this->delete_page( 10, true )->get_status() );
	}

	public function test_the_lift_cannot_be_set_by_anything_a_caller_sends(): void {
		$this->seed( 10 );
		$controller = new Mcpwp_REST_Pages();
		$request    = new WP_REST_Request( 'DELETE', '/mcpwp/v1/pages/10', array( 'id' => 10, 'force' => true, 'allow_destructive' => true, 'applying' => true ), array( 'X-MCPWP-Applying' => '1' ) );

		$this->assertSame( 202, $this->authed( $controller, $request )->delete_page( $request )->get_status() );
		$this->assertFalse( Mcpwp_Destructive_Gate::is_applying() );
	}

	// ---------------------------------------------------------------
	// The two-person rule lives in the store.
	// ---------------------------------------------------------------

	public function test_the_store_itself_refuses_self_approval_and_unidentified_actors(): void {
		$this->seed( 10 );
		$approval_id = $this->delete_page( 10, true )->get_data()['approval_id'];

		$anon = Mcpwp_Approvals::approve_request( $approval_id, 'ok' );
		$this->assertSame( 'unidentified_actor', $anon->get_error_code() );

		$self = Mcpwp_Approvals::approve_request( $approval_id, 'ok', array( 'actor' => $this->key['id'] ) );
		$this->assertSame( 'self_approval_forbidden', $self->get_error_code(), 'Whichever door: the requester cannot approve its own request.' );

		$reviewer = ( new Mcpwp_REST_Pages() )->create_scoped_api_key( 'reviewer', array( 'admin' ) );
		$this->assertSame( 'approved', Mcpwp_Approvals::approve_request( $approval_id, 'ok', array( 'actor' => $reviewer['id'] ) )['status'] );

		$self_apply = Mcpwp_Approvals::apply_request( $approval_id, array( 'api_key' => $this->key['key'], 'actor' => $this->key['id'] ) );
		$this->assertSame( 'self_approval_forbidden', $self_apply->get_error_code() );
	}

	public function test_a_reviewer_key_applies_and_the_replay_runs_as_the_reviewer(): void {
		$this->seed( 10 );
		$approval_id = $this->delete_page( 10, true )->get_data()['approval_id'];
		$reviewer    = ( new Mcpwp_REST_Pages() )->create_scoped_api_key( 'reviewer', array( 'admin' ) );
		Mcpwp_Approvals::approve_request( $approval_id, 'ok', array( 'actor' => $reviewer['id'] ) );

		$GLOBALS['mcpwp_test_dispatched_requests'] = array();
		$applied = Mcpwp_Approvals::apply_request( $approval_id, array( 'api_key' => $reviewer['key'], 'actor' => $reviewer['id'] ) );

		$this->assertIsArray( $applied, is_wp_error( $applied ) ? $applied->get_error_message() : '' );
		$this->assertSame( 'applied', $applied['status'] );
		$this->assertContains( '/mcpwp/v1/pages/10', $GLOBALS['mcpwp_test_dispatched_routes'] );
		$this->assertSame( $reviewer['key'], $GLOBALS['mcpwp_test_dispatched_requests'][0]->get_header( 'X-API-Key' ) );
		$this->assertFalse( Mcpwp_Destructive_Gate::is_applying() );
	}

	public function test_an_administrator_applies_from_wp_admin_without_a_key(): void {
		$this->seed( 10 );
		$approval_id = $this->delete_page( 10, true )->get_data()['approval_id'];

		// The Control Room path: an admin user, no API key. The replay runs
		// as that administrator; the permission callback honours the lift.
		$GLOBALS['mcpwp_test_user_caps'][7] = array( 'activate_plugins' => true );
		Mcpwp_Approvals::approve_request( $approval_id, '', array( 'admin_user' => 7, 'actor' => 'wp-user:7' ) );
		$GLOBALS['mcpwp_test_dispatched_requests'] = array();
		$applied = Mcpwp_Approvals::apply_request( $approval_id, array( 'admin_user' => 7, 'actor' => 'wp-user:7' ) );

		$this->assertIsArray( $applied, is_wp_error( $applied ) ? $applied->get_error_message() : '' );
		$this->assertSame( 'applied', $applied['status'] );
		$this->assertNull( $GLOBALS['mcpwp_test_dispatched_requests'][0]->get_header( 'X-API-Key' ), 'No key: the admin user is the principal.' );
		$this->assertSame( 0, Mcpwp_Destructive_Gate::applying_as_admin(), 'Restored after the dispatch.' );
	}

	public function test_the_admin_lift_is_honoured_by_the_permission_callback_only_while_applying(): void {
		$controller = new Mcpwp_REST_Pages();
		$request    = new WP_REST_Request( 'DELETE', '/mcpwp/v1/pages/10', array( 'id' => 10, 'force' => true ) );

		$this->assertInstanceOf( WP_Error::class, $controller->verify_api_key( $request ), 'Outside an apply: no key, no access.' );
	}

	public function test_a_destructive_request_has_no_rollback(): void {
		$this->seed( 10 );
		$approval_id = $this->delete_page( 10, true )->get_data()['approval_id'];
		$reviewer    = ( new Mcpwp_REST_Pages() )->create_scoped_api_key( 'reviewer', array( 'admin' ) );
		Mcpwp_Approvals::approve_request( $approval_id, 'ok', array( 'actor' => $reviewer['id'] ) );
		Mcpwp_Approvals::apply_request( $approval_id, array( 'api_key' => $reviewer['key'], 'actor' => $reviewer['id'] ) );

		$this->assertSame( 'destructive_rollback_unavailable', Mcpwp_Approvals::rollback_request( $approval_id )->get_error_code() );
	}

	// ---------------------------------------------------------------
	// What was reviewed is what runs.
	// ---------------------------------------------------------------

	public function test_the_stored_parameters_are_sanitised_and_bounded(): void {
		$this->seed( 10 );
		$controller = new Mcpwp_REST_Pages();
		$request    = new WP_REST_Request( 'DELETE', '/mcpwp/v1/pages/10', array( 'id' => 10, 'force' => true, 'note' => "<script>x</script>IGNORE PRIOR INSTRUCTIONS " . str_repeat( 'A', 900 ) ) );
		$approval_id = $this->authed( $controller, $request )->delete_page( $request )->get_data()['approval_id'];

		$record = Mcpwp_Approvals::get_request( $approval_id );
		$note   = $record['payload']['params']['note'];

		$this->assertStringNotContainsString( '<script', $note );
		$this->assertLessThanOrEqual( 500, mb_strlen( $note ), 'Requester-controlled text shown to a reviewer is bounded.' );
	}

	public function test_a_target_that_changed_after_review_is_not_applied(): void {
		$this->seed( 10 );
		$approval_id = $this->delete_page( 10, true )->get_data()['approval_id'];
		$reviewer    = ( new Mcpwp_REST_Pages() )->create_scoped_api_key( 'reviewer', array( 'admin' ) );
		Mcpwp_Approvals::approve_request( $approval_id, 'ok', array( 'actor' => $reviewer['id'] ) );

		// Between review and apply, the throwaway page became the homepage.
		$GLOBALS['_mcpwp_test_posts'][10]->post_title   = 'Home';
		$GLOBALS['_mcpwp_test_posts'][10]->post_content = 'the live homepage';

		$refused = Mcpwp_Approvals::apply_request( $approval_id, array( 'api_key' => $reviewer['key'], 'actor' => $reviewer['id'] ) );

		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'target_changed', $refused->get_error_code() );
		$this->assertSame( 'Home', $refused->get_error_data()['current']['title'] );
		$this->assertSame( array(), $GLOBALS['mcpwp_test_dispatched_routes'], 'Nothing ran.' );
	}

	// ---------------------------------------------------------------
	// The queue and what it reveals.
	// ---------------------------------------------------------------

	public function test_a_private_page_title_does_not_leak_through_the_approvals_list(): void {
		$this->seed( 10, 'page', 'private' );
		$GLOBALS['_mcpwp_test_posts'][10]->post_title = 'Secret launch plan';
		$this->delete_page( 10, true );

		$record   = Mcpwp_Approvals::list_requests( '', 50, false )[0];
		$redacted = Mcpwp_Approvals::redact_source_for_unprivileged( $record );

		$this->assertSame( '', $redacted['resource']['title'] );
		$this->assertTrue( $redacted['redacted'] );
	}

	public function test_a_flood_of_deferrals_neither_evicts_open_records_nor_exceeds_the_per_requester_cap(): void {
		for ( $i = 1; $i <= Mcpwp_Destructive_Gate::MAX_PENDING_PER_REQUESTER + 5; $i++ ) {
			$this->seed( 100 + $i );
			$response = $this->delete_page( 100 + $i, true );
			if ( $i <= Mcpwp_Destructive_Gate::MAX_PENDING_PER_REQUESTER ) {
				$this->assertSame( 202, $response->get_status(), 'request ' . $i );
			} else {
				$this->assertInstanceOf( WP_Error::class, $response, 'request ' . $i );
				$this->assertSame( 'too_many_pending', $response->get_error_code() );
			}
		}

		$pending = array_filter( Mcpwp_Approvals::list_requests( 'pending', 500 ), function ( $r ) { return 'pending' === $r['status']; } );
		$this->assertCount( Mcpwp_Destructive_Gate::MAX_PENDING_PER_REQUESTER, $pending );
	}

	public function test_open_records_survive_the_store_cap(): void {
		$this->seed( 10 );
		$approval_id = $this->delete_page( 10, true )->get_data()['approval_id'];

		// Bury it under closed records.
		$items = get_option( Mcpwp_Approvals::OPTION_NAME, array() );
		for ( $i = 0; $i < Mcpwp_Approvals::MAX_ITEMS + 10; $i++ ) {
			$items[ 'apr_closed_' . $i ] = array( 'id' => 'apr_closed_' . $i, 'status' => 'rejected', 'created_at' => '2099-01-01T00:00:00+00:00' );
		}
		update_option( Mcpwp_Approvals::OPTION_NAME, $items );
		$this->seed( 11 );
		$this->delete_page( 11, true ); // triggers upsert → eviction

		$this->assertIsArray( Mcpwp_Approvals::get_request( $approval_id ), 'A pending request is someone\'s unfinished review; it is never evicted.' );
	}

	// ---------------------------------------------------------------
	// Round 2: the gate's own state is out of reach, the replay is bound
	// to the reviewed operation, trust is proven, the queue is bounded.
	// ---------------------------------------------------------------

	public function test_every_option_the_gate_reads_is_unreachable_through_the_options_api(): void {
		// P0-1: the approval store, the keys, the switches — all in the
		// plugin's own namespace, which a prefix allowlist opened wholesale.
		// The option floor (#800) is the gate's guarantee now: the plugin's
		// own namespace is closed except the exact-listed keys.
		$settings = new Mcpwp_REST_Site_Settings();
		foreach ( array( Mcpwp_Approvals::OPTION_NAME, 'mcpwp_settings', 'mcpwp_api_keys', 'mcpwp_api_key', 'mcpwp_disabled_tool_categories', 'mcpwp_rate_limit_settings', 'mcpwp_anything_added_later' ) as $option ) {
			$this->assertFalse( Mcpwp_Option_Access::decide( $option, 'write' )['allowed'], $option );
		}
		foreach ( array( 'mcpwp_site_context', 'mcpwp_site_context_updated' ) as $option ) {
			$this->assertTrue( Mcpwp_Option_Access::decide( $option, 'write' )['allowed'], $option . ' is exact-listed and stays writable.' );
		}

		// The forge from the review, end to end: refused, store untouched.
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/option', array( 'key' => Mcpwp_Approvals::OPTION_NAME, 'value' => array( 'apr_forged' => array( 'status' => 'approved' ) ) ) );
		$result  = $this->authed( $settings, $request )->update_option_handler( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( array(), Mcpwp_Approvals::list_requests( '', 50, true ) );
	}

	public function test_a_stored_payload_whose_route_is_not_the_operations_route_is_never_applied(): void {
		// P0-2: the reviewer consents to the operation on the record, not to
		// whatever route the payload happens to name.
		$forged = array(
			'id'                  => 'apr_forged',
			'status'              => 'approved',
			'action'              => 'destructive_operation',
			'operation'           => 'delete_page',
			'title'               => 'Permanently delete page #12',
			'resource'            => array( 'type' => 'page', 'id' => 12, 'title' => 'x' ),
			'payload'             => array( 'route' => '/mcpwp/v1/api-keys', 'method' => 'POST', 'params' => array( 'label' => 'x', 'allow_destructive' => true ), 'target' => array() ),
			'requested_by_key_id' => 'someone-else',
			'created_at'          => '2026-09-10T00:00:00+00:00',
			'updated_at'          => '2026-09-10T00:00:00+00:00',
		);
		update_option( Mcpwp_Approvals::OPTION_NAME, array( 'apr_forged' => $forged ) );

		$refused = Mcpwp_Approvals::apply_request( 'apr_forged', array( 'admin_user' => 7, 'actor' => 'wp-user:7' ) );
		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'destructive_route_mismatch', $refused->get_error_code() );
		$this->assertSame( array(), $GLOBALS['mcpwp_test_dispatched_routes'], 'Nothing ran.' );

		// Every operation is bound to exactly one route shape.
		foreach ( Mcpwp_Destructive_Gate::GATED as $op ) {
			$this->assertArrayHasKey( $op, Mcpwp_Destructive_Gate::OPERATION_ROUTES, $op );
		}
		$this->assertTrue( Mcpwp_Destructive_Gate::route_matches_operation( 'delete_page', 'DELETE', '/mcpwp/v1/pages/12' ) );
		$this->assertInstanceOf( WP_Error::class, Mcpwp_Destructive_Gate::route_matches_operation( 'delete_page', 'DELETE', '/mcpwp/v1/posts/12' ) );
		$this->assertInstanceOf( WP_Error::class, Mcpwp_Destructive_Gate::route_matches_operation( 'delete_page', 'POST', '/mcpwp/v1/pages/12' ) );
		$this->assertInstanceOf( WP_Error::class, Mcpwp_Destructive_Gate::route_matches_operation( 'delete_page', 'DELETE', '/mcpwp/v1/pages/12/../../api-keys' ) );
	}

	public function test_a_record_with_no_requester_can_be_neither_created_nor_actioned(): void {
		// A destructive record names its requester or the two-person rule
		// has nothing to bind. The '' clause in guard_actor is not a pass.
		$refused = Mcpwp_Approvals::create_destructive_request( 'delete_page', array( 'route' => '/mcpwp/v1/pages/1', 'method' => 'DELETE', 'params' => array(), 'target' => array() ), array( 'type' => 'page', 'id' => 1, 'title' => 'x' ), array( 'requested_by_key_id' => '' ) );
		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'unidentified_requester', $refused->get_error_code() );

		$orphan = array( 'id' => 'apr_orphan', 'status' => 'pending', 'action' => 'destructive_operation', 'operation' => 'delete_page', 'resource' => array( 'type' => 'page', 'id' => 1 ), 'payload' => array( 'route' => '/mcpwp/v1/pages/1', 'method' => 'DELETE', 'params' => array(), 'target' => array() ), 'requested_by_key_id' => '', 'created_at' => '2026-09-10T00:00:00+00:00', 'updated_at' => '2026-09-10T00:00:00+00:00' );
		update_option( Mcpwp_Approvals::OPTION_NAME, array( 'apr_orphan' => $orphan ) );
		$this->assertSame( 'unidentified_requester', Mcpwp_Approvals::approve_request( 'apr_orphan', '', array( 'actor' => 'reviewer' ) )->get_error_code() );
		$this->assertSame( 'unidentified_requester', Mcpwp_Approvals::apply_request( 'apr_orphan', array( 'actor' => 'reviewer', 'api_key' => 'x' ) )->get_error_code() );
	}

	public function test_trust_is_a_positive_proof_never_an_absent_field(): void {
		// P1-1: a null record outside wp-admin is an API principal; so is a
		// record without an id; only proven admin context is not.
		$GLOBALS['mcpwp_test_is_admin'] = false;
		$this->assertTrue( Mcpwp_Destructive_Gate::is_api_principal( null ) );
		$this->assertTrue( Mcpwp_Destructive_Gate::is_api_principal( array( 'id' => '', 'scopes' => array( 'admin' ) ) ) );
		$this->assertTrue( Mcpwp_Destructive_Gate::is_api_principal( array( 'id' => 'k1' ) ) );

		$GLOBALS['mcpwp_test_is_admin'] = true;
		$this->assertFalse( Mcpwp_Destructive_Gate::is_api_principal( null ) );
		$this->assertTrue( Mcpwp_Destructive_Gate::is_api_principal( array( 'id' => 'k1' ) ), 'A key is a key even from wp-admin.' );
		$GLOBALS['mcpwp_test_is_admin'] = false;

		// An id-less stored record never authenticates.
		$keys = get_option( 'mcpwp_api_keys', array() );
		$plain = 'mcpwp_' . str_repeat( 'b', 48 );
		$keys[] = array( 'label' => 'seeded', 'hash' => wp_hash_password( $plain ), 'scopes' => array( 'read', 'write', 'admin' ), 'role' => 'admin' );
		update_option( 'mcpwp_api_keys', $keys );
		$controller = new Mcpwp_REST_Pages();
		$request    = new WP_REST_Request( 'DELETE', '/mcpwp/v1/pages/10', array( 'id' => 10, 'force' => true ) );
		$request->set_header( 'X-API-Key', $plain );
		if ( method_exists( 'Mcpwp_REST_MCP', 'reset_verdict_memo' ) ) {
			Mcpwp_REST_MCP::reset_verdict_memo();
		}
		$this->assertInstanceOf( WP_Error::class, $controller->verify_api_key( $request ), 'A record with no id is not a principal.' );
	}

	public function test_the_target_fingerprint_is_not_in_the_public_record(): void {
		// P1-2: the fingerprint carried the private page's exact title and a
		// hash of its body past the redaction.
		$this->seed( 10, 'page', 'private' );
		$GLOBALS['_mcpwp_test_posts'][10]->post_title = 'Secret launch plan';
		$this->delete_page( 10, true );

		$record = Mcpwp_Approvals::list_requests( '', 50, false )[0];
		$this->assertArrayNotHasKey( 'title', $record['payload']['target'] );
		$this->assertArrayNotHasKey( 'hash', $record['payload']['target'] );
		$this->assertArrayNotHasKey( 'modified', $record['payload']['target'] );
		$this->assertStringNotContainsString( 'Secret launch plan', wp_json_encode( Mcpwp_Approvals::redact_source_for_unprivileged( $record ) ) );

		// drift() still has the full fingerprint (private record).
		$reviewer = ( new Mcpwp_REST_Pages() )->create_scoped_api_key( 'reviewer', array( 'admin' ) );
		Mcpwp_Approvals::approve_request( $record['id'], '', array( 'actor' => $reviewer['id'] ) );
		$GLOBALS['_mcpwp_test_posts'][10]->post_title = 'Renamed';
		$this->assertSame( 'target_changed', Mcpwp_Approvals::apply_request( $record['id'], array( 'api_key' => $reviewer['key'], 'actor' => $reviewer['id'] ) )->get_error_code() );
	}

	public function test_the_queue_is_bounded_across_requesters_not_only_per_requester(): void {
		// P1-3: N minted keys × 20 slots was unbounded.
		for ( $i = 1; $i <= Mcpwp_Destructive_Gate::MAX_PENDING_TOTAL; $i++ ) {
			$ok = Mcpwp_Approvals::create_destructive_request( 'delete_page', array( 'route' => '/mcpwp/v1/pages/' . $i, 'method' => 'DELETE', 'params' => array(), 'target' => array() ), array( 'type' => 'page', 'id' => $i, 'title' => 'p' ), array( 'requested_by_key_id' => 'minted-' . (int) ceil( $i / 10 ) ) );
			$this->assertNotInstanceOf( WP_Error::class, $ok, 'request ' . $i );
		}
		$full = Mcpwp_Approvals::create_destructive_request( 'delete_page', array( 'route' => '/mcpwp/v1/pages/999', 'method' => 'DELETE', 'params' => array(), 'target' => array() ), array( 'type' => 'page', 'id' => 999, 'title' => 'p' ), array( 'requested_by_key_id' => 'minted-fresh' ) );
		$this->assertInstanceOf( WP_Error::class, $full );
		$this->assertSame( 'approval_queue_full', $full->get_error_code() );
		$this->assertCount( Mcpwp_Destructive_Gate::MAX_PENDING_TOTAL, Mcpwp_Approvals::list_requests( 'pending', 1000, true ) );
	}

	public function test_both_tool_registries_are_gated_or_exempt_with_a_reason(): void {
		// P1-4: the invariant test reflected one registry and certified both.
		$declared = array();
		foreach ( array( Mcpwp_MCP_Free_Tools::class, Mcpwp_MCP_Pro_Tools::class ) as $class ) {
			$method = new ReflectionMethod( $class, 'get_destructive_tools' );
			$method->setAccessible( true );
			$declared = array_merge( $declared, $method->invoke( ( new ReflectionClass( $class ) )->newInstanceWithoutConstructor() ) );
		}
		$this->assertContains( 'wc_delete_product', $declared, 'Premise: the pro registry was read.' );

		$gated = array();
		foreach ( Mcpwp_Destructive_Gate::GATED as $op ) {
			$gated[] = 'wp_' . $op;
			$gated[] = 'wc_' . $op;
		}

		// The wider oracle: every tool NAMED like a delete, whether or not
		// its registry declared it destructive (#536 round 4, P2-4).
		foreach ( array( Mcpwp_MCP_Free_Tools::class, Mcpwp_MCP_Pro_Tools::class ) as $class ) {
			foreach ( array_keys( ( new ReflectionClass( $class ) )->newInstanceWithoutConstructor()->get_tool_map() ) as $name ) {
				if ( preg_match( '/^(wp|wc)_(delete_|forget)/', $name ) ) {
					$declared[] = $name;
				}
			}
		}
		$declared = array_values( array_unique( $declared ) );

		foreach ( $declared as $tool ) {
			$this->assertTrue(
				in_array( $tool, $gated, true ) || isset( Mcpwp_Destructive_Gate::EXEMPT_WITH_REASON[ $tool ] ),
				$tool . ' is declared destructive and is neither gated nor exempt with a reason.'
			);
		}
		foreach ( Mcpwp_Destructive_Gate::EXEMPT_WITH_REASON as $tool => $reason ) {
			$this->assertNotEmpty( $reason, $tool );
		}
	}

	public function test_pro_deletes_are_deferred_like_every_other_permanent_delete(): void {
		foreach ( array( 'includes/pro/api/class-mcpwp-rest-woocommerce.php', 'includes/pro/api/class-mcpwp-rest-widgets.php' ) as $file ) {
			require_once dirname( __DIR__ ) . '/' . $file;
		}
		if ( ! function_exists( 'rest_ensure_response' ) ) {
			function rest_ensure_response( $r ) { return $r instanceof WP_REST_Response ? $r : new WP_REST_Response( $r ); }
		}
		$never = new class() {
			public $calls = 0;
			public function __call( $name, $args ) { $this->calls++; return true; }
		};

		$this->seed( 50, 'product' );
		$wc      = new Mcpwp_REST_WooCommerce( $never );
		$request = new WP_REST_Request( 'DELETE', '/mcpwp/v1/woocommerce/products/50', array( 'id' => 50, 'force' => true ) );
		$response = $this->authed( $wc, $request )->delete_product( $request );
		$this->assertSame( 202, $response->get_status() );
		$this->assertSame( 'delete_product', $response->get_data()['operation'] );
		$this->assertSame( 0, $never->calls, 'The product was not touched.' );

		$widgets  = new Mcpwp_REST_Widgets( $never );
		update_option( 'widget_text', array( 3 => array( 'text' => 'x' ), '_multiwidget' => 1 ) );
		$request  = new WP_REST_Request( 'DELETE', '/mcpwp/v1/widgets/text-3', array( 'id' => 'text-3' ) );
		$response = $this->authed( $widgets, $request )->delete_widget( $request );
		$this->assertSame( 202, $response->get_status() );
		$this->assertSame( 0, $never->calls );

		// A widget that does not exist is a 404, never a no-op to approve (round 8).
		$request = new WP_REST_Request( 'DELETE', '/mcpwp/v1/widgets/text-9999', array( 'id' => 'text-9999' ) );
		$this->assertSame( 'not_found', $this->authed( $widgets, $request )->delete_widget( $request )->get_error_code() );

		// Trashing a product is still one call.
		$request = new WP_REST_Request( 'DELETE', '/mcpwp/v1/woocommerce/products/50', array( 'id' => 50, 'force' => false ) );
		$this->assertSame( 200, $this->authed( $wc, $request )->delete_product( $request )->get_status() );
		$this->assertSame( 1, $never->calls );
	}

	public function test_the_admin_chat_requester_is_named_and_bound_by_the_two_person_rule(): void {
		// P2-1: a null record from the admin chat records wp-user:N, so the
		// same human cannot approve their own request from the Control Room.
		$GLOBALS['mcpwp_test_is_admin'] = true;
		$this->assertSame( 'wp-user:2', Mcpwp_Destructive_Gate::requester_id( null ) );
		$this->seed( 10 );
		$controller = new Mcpwp_REST_Pages();
		$request    = new WP_REST_Request( 'DELETE', '/mcpwp/v1/pages/10', array( 'id' => 10, 'force' => true ) );
		$approval   = $controller->delete_page( $request )->get_data();
		$this->assertTrue( $approval['approval_required'] );
		$this->assertSame( 'wp-user:2', Mcpwp_Approvals::get_request( $approval['approval_id'] )['requested_by_key_id'] );

		$self = Mcpwp_Approvals::approve_request( $approval['approval_id'], '', array( 'admin_user' => 2, 'actor' => 'wp-user:2' ) );
		$this->assertSame( 'self_approval_forbidden', $self->get_error_code() );
		$this->assertNotInstanceOf( WP_Error::class, Mcpwp_Approvals::approve_request( $approval['approval_id'], '', array( 'admin_user' => 7, 'actor' => 'wp-user:7' ) ) );
		$GLOBALS['mcpwp_test_is_admin'] = false;
	}

	public function test_a_target_whose_status_changed_is_not_applied(): void {
		// P2-3: title and body untouched, but the draft was published (and
		// could now be the homepage).
		$this->seed( 10, 'page', 'draft' );
		$approval_id = $this->delete_page( 10, true )->get_data()['approval_id'];
		$reviewer    = ( new Mcpwp_REST_Pages() )->create_scoped_api_key( 'reviewer', array( 'admin' ) );
		Mcpwp_Approvals::approve_request( $approval_id, 'ok', array( 'actor' => $reviewer['id'] ) );

		$GLOBALS['_mcpwp_test_posts'][10]->post_status = 'publish';

		$refused = Mcpwp_Approvals::apply_request( $approval_id, array( 'api_key' => $reviewer['key'], 'actor' => $reviewer['id'] ) );
		$this->assertSame( 'target_changed', $refused->get_error_code() );
		$this->assertSame( 'publish', $refused->get_error_data()['current']['status'] );
	}

	public function test_delete_all_drafts_is_approved_for_a_set_not_for_whatever_exists_at_apply(): void {
		// P2-4: the reviewer saw "3 drafts"; a fourth created after review
		// must not ride the same approval.
		foreach ( array( 21, 22, 23 ) as $id ) {
			$this->seed( $id, 'post', 'draft' );
		}
		$controller = new Mcpwp_REST_Posts();
		$request    = new WP_REST_Request( 'DELETE', '/mcpwp/v1/drafts/delete-all', array( 'type' => 'all', 'force' => true ) );
		$approval   = $this->authed( $controller, $request )->delete_all_drafts( $request )->get_data();
		$record     = Mcpwp_Approvals::get_request( $approval['approval_id'] );
		$this->assertSame( 3, $record['payload']['target']['count'] );

		$reviewer = ( new Mcpwp_REST_Pages() )->create_scoped_api_key( 'reviewer', array( 'admin' ) );
		Mcpwp_Approvals::approve_request( $approval['approval_id'], '', array( 'actor' => $reviewer['id'] ) );
		$this->seed( 24, 'post', 'draft' );

		$refused = Mcpwp_Approvals::apply_request( $approval['approval_id'], array( 'api_key' => $reviewer['key'], 'actor' => $reviewer['id'] ) );
		$this->assertSame( 'target_changed', $refused->get_error_code() );
		$this->assertSame( 4, $refused->get_error_data()['current']['count'] );
		$this->assertArrayHasKey( 24, $GLOBALS['_mcpwp_test_posts'], 'Nothing was deleted.' );
	}

	public function test_a_stored_false_switch_from_the_early_build_is_reset_once(): void {
		require_once dirname( __DIR__ ) . '/includes/class-mcpwp-activator.php';
		update_option( 'mcpwp_settings', array( 'destructive_requires_approval' => false, 'enable_logging' => true ) );
		delete_option( 'mcpwp_destructive_switch_reset' );

		Mcpwp_Activator::reset_destructive_switch();
		$this->assertTrue( Mcpwp_Destructive_Gate::enabled_for_site() );
		$this->assertTrue( (bool) get_option( 'mcpwp_settings' )['enable_logging'], 'Only the switch is touched.' );

		// Once: an administrator turning it off afterwards is respected.
		update_option( 'mcpwp_settings', array( 'destructive_requires_approval' => false ) );
		Mcpwp_Activator::reset_destructive_switch();
		$this->assertFalse( Mcpwp_Destructive_Gate::enabled_for_site() );
	}

	public function test_sanitize_params_bounds_depth_and_key_count(): void {
		// P2-6: the bounds that protect a reviewer who may be another model.
		$deep = array( 'a' => array( 'b' => array( 'c' => array( 'd' => array( 'e' => array( 'f' => 'too deep' ) ) ) ) ) );
		$out  = Mcpwp_Destructive_Gate::sanitize_params( $deep );
		$this->assertNull( $out['a']['b']['c']['d']['e'] );

		$wide = array();
		for ( $i = 1; $i <= 60; $i++ ) {
			$wide[ 'k' . $i ] = $i;
		}
		$this->assertCount( 50, Mcpwp_Destructive_Gate::sanitize_params( $wide ) );
		$this->assertSame( array( 'ok' => 'x', 'badkey' => 'y' ), Mcpwp_Destructive_Gate::sanitize_params( array( 'ok' => 'x', '<bad key>' => 'y' ) ), 'Keys are reduced to a safe charset; a key nothing survives of is dropped.' );
		$this->assertSame( array(), Mcpwp_Destructive_Gate::sanitize_params( array( '<>' => 'y' ) ) );
	}

	public function test_only_an_approved_request_is_applied(): void {
		$this->seed( 10 );
		$approval_id = $this->delete_page( 10, true )->get_data()['approval_id'];
		$reviewer    = ( new Mcpwp_REST_Pages() )->create_scoped_api_key( 'reviewer', array( 'admin' ) );

		$pending = Mcpwp_Approvals::apply_request( $approval_id, array( 'api_key' => $reviewer['key'], 'actor' => $reviewer['id'] ) );
		$this->assertSame( 'approval_not_ready', $pending->get_error_code() );

		Mcpwp_Approvals::reject_request( $approval_id, 'no' );
		$rejected = Mcpwp_Approvals::apply_request( $approval_id, array( 'api_key' => $reviewer['key'], 'actor' => $reviewer['id'] ) );
		$this->assertSame( 'approval_not_ready', $rejected->get_error_code() );
		$this->assertArrayHasKey( 10, $GLOBALS['_mcpwp_test_posts'] );
	}

	// ---------------------------------------------------------------
	// Round 3: routes match the tool map, admins are never starved, the
	// printed call is the call, terms and widgets drift too.
	// ---------------------------------------------------------------

	public function test_every_operation_route_matches_the_route_its_own_tool_declares(): void {
		// Round-3 P1-1: the product pattern named a route that does not
		// exist, so the gate bricked wc_delete_product and the invariant
		// test certified it as gated.
		$maps = array();
		foreach ( array( Mcpwp_MCP_Free_Tools::class, Mcpwp_MCP_Pro_Tools::class ) as $class ) {
			$maps = array_merge( $maps, ( new ReflectionClass( $class ) )->newInstanceWithoutConstructor()->get_tool_map() );
		}
		$tools = array(
			'delete_page'               => 'wp_delete_page',
			'delete_post'               => 'wp_delete_post',
			'delete_all_drafts'         => 'wp_delete_all_drafts',
			'delete_media'              => 'wp_delete_media',
			'delete_product'            => 'wc_delete_product',
			'delete_course_category'    => 'wp_delete_course_category',
			'delete_widget'             => 'wp_delete_widget',
			'delete_content'            => 'wp_delete_content',
			'delete_elementor_template' => 'wp_delete_elementor_template',
		);
		foreach ( Mcpwp_Destructive_Gate::OPERATION_ROUTES as $op => $spec ) {
			if ( in_array( $op, Mcpwp_Destructive_Gate::REST_ONLY_OPERATIONS, true ) ) {
				continue;
			}
			$this->assertArrayHasKey( $op, $tools, $op );
			$tool = $tools[ $op ];
			$this->assertArrayHasKey( $tool, $maps, $tool . ' is in a tool map.' );
			$route  = '/mcpwp/v1' . preg_replace( '/\{[a-z_]+\}/', '1', $maps[ $tool ]['route'] );
			$method = strtoupper( $maps[ $tool ]['method'] );
			$this->assertTrue( Mcpwp_Destructive_Gate::route_matches_operation( $op, $method, $route ), $op . ' must match ' . $method . ' ' . $route );
		}
	}

	public function test_minted_keys_cannot_close_the_destructive_path_to_administrators(): void {
		// Round-3 P1-2: one pool of 100 let five minted keys lock every
		// principal out, including the reviewers.
		for ( $i = 1; $i <= Mcpwp_Destructive_Gate::MAX_PENDING_TOTAL; $i++ ) {
			Mcpwp_Approvals::create_destructive_request( 'delete_page', array( 'route' => '/mcpwp/v1/pages/' . $i, 'method' => 'DELETE', 'params' => array(), 'target' => array() ), array( 'type' => 'page', 'id' => $i, 'title' => 'p' ), array( 'requested_by_key_id' => 'minted-' . (int) ceil( $i / 20 ) ) );
		}
		$api = Mcpwp_Approvals::create_destructive_request( 'delete_page', array( 'route' => '/mcpwp/v1/pages/999', 'method' => 'DELETE', 'params' => array(), 'target' => array() ), array( 'type' => 'page', 'id' => 999, 'title' => 'p' ), array( 'requested_by_key_id' => 'minted-fresh' ) );
		$this->assertSame( 'approval_queue_full', $api->get_error_code() );

		$admin = Mcpwp_Approvals::create_destructive_request( 'delete_page', array( 'route' => '/mcpwp/v1/pages/998', 'method' => 'DELETE', 'params' => array(), 'target' => array() ), array( 'type' => 'page', 'id' => 998, 'title' => 'p' ), array( 'requested_by_key_id' => 'wp-user:2' ) );
		$this->assertNotInstanceOf( WP_Error::class, $admin, 'An administrator is outside the API pool.' );

		// And bound by the per-requester cap like everyone else.
		for ( $i = 1; $i < Mcpwp_Destructive_Gate::MAX_PENDING_PER_REQUESTER; $i++ ) {
			Mcpwp_Approvals::create_destructive_request( 'delete_page', array( 'route' => '/mcpwp/v1/pages/' . ( 2000 + $i ), 'method' => 'DELETE', 'params' => array(), 'target' => array() ), array( 'type' => 'page', 'id' => 2000 + $i, 'title' => 'p' ), array( 'requested_by_key_id' => 'wp-user:2' ) );
		}
		$this->assertSame( 'too_many_pending', Mcpwp_Approvals::create_destructive_request( 'delete_page', array( 'route' => '/mcpwp/v1/pages/3000', 'method' => 'DELETE', 'params' => array(), 'target' => array() ), array( 'type' => 'page', 'id' => 3000, 'title' => 'p' ), array( 'requested_by_key_id' => 'wp-user:2' ) )->get_error_code() );
	}

	public function test_a_route_id_and_a_body_id_that_disagree_are_refused_at_defer(): void {
		// Round-3 P2-1: the Control Room printed the route id while the
		// replay deleted the body id.
		$this->seed( 12 );
		$this->seed( 999 );
		$controller = new Mcpwp_REST_Pages();
		$request    = new WP_REST_Request( 'DELETE', '/mcpwp/v1/pages/12', array( 'id' => 999, 'force' => true ) );
		$result     = $this->authed( $controller, $request )->delete_page( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'destructive_id_mismatch', $result->get_error_code() );
		$this->assertSame( array(), Mcpwp_Approvals::list_requests( '', 50, true ) );
	}

	public function test_a_widget_changed_after_review_is_not_deleted(): void {
		// Round-3 P2-4.
		update_option( 'widget_text', array( 3 => array( 'title' => 'Footer', 'text' => 'old' ), '_multiwidget' => 1 ) );
		$before = Mcpwp_Destructive_Gate::fingerprint( array( 'type' => 'widget', 'id' => 'text-3' ) );
		$this->assertTrue( $before['exists'] );

		update_option( 'widget_text', array( 3 => array( 'title' => 'Footer', 'text' => 'new' ), '_multiwidget' => 1 ) );
		$drift = new ReflectionMethod( Mcpwp_Destructive_Gate::class, 'drift' );
		$drift->setAccessible( true );
		$refused = $drift->invoke( null, array( 'route' => '/mcpwp/v1/widgets/text-3', 'method' => 'DELETE', 'params' => array( 'id' => 'text-3' ), 'target' => $before ) );
		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'target_changed', $refused->get_error_code() );

		$this->assertSame( array( 'exists' => false ), Mcpwp_Destructive_Gate::fingerprint( array( 'type' => 'widget', 'id' => 'text-9' ) ) );
		$this->assertTrue( Mcpwp_Destructive_Gate::fingerprint( array( 'type' => 'widget', 'id' => 'not a widget id' ) )['unparseable'], 'Not "no drift check": named as unparseable.' );
	}

	public function test_the_drafts_fingerprint_is_an_exact_count_and_a_bounded_id_list(): void {
		foreach ( range( 21, 25 ) as $id ) {
			$this->seed( $id, 'post', 'draft' );
		}
		$fp = Mcpwp_Destructive_Gate::fingerprint_drafts( 'post' );
		$this->assertSame( 5, $fp['count'] );
		$this->assertSame( array( 21, 22, 23, 24, 25 ), $fp['ids'] );
		$this->assertSame( 1000, Mcpwp_Destructive_Gate::DRAFTS_FINGERPRINT_IDS );
	}

	public function test_the_switch_has_a_setter_in_wp_admin_and_defaults_on(): void {
		// Round-3 P2-3: nothing wrote destructive_requires_approval.
		if ( ! class_exists( 'Mcpwp_Settings' ) ) {
			require_once dirname( __DIR__ ) . '/includes/admin/class-mcpwp-settings.php';
		}
		$settings = ( new ReflectionClass( Mcpwp_Settings::class ) )->newInstanceWithoutConstructor();
		$this->assertTrue( $settings->get_defaults()['destructive_requires_approval'] );

		$off = $settings->sanitize_settings( array( 'enable_logging' => '1', '__present' => array( 'destructive_requires_approval' ) ) );
		$this->assertFalse( $off['destructive_requires_approval'], 'Unchecked in the form (marker present) = off.' );
		$on = $settings->sanitize_settings( array( 'enable_logging' => '1', '__present' => array( 'destructive_requires_approval' ), 'destructive_requires_approval' => '1' ) );
		$this->assertTrue( $on['destructive_requires_approval'] );

		update_option( 'mcpwp_settings', $off );
		$this->assertFalse( Mcpwp_Destructive_Gate::enabled_for_site() );
		update_option( 'mcpwp_settings', $on );
		$this->assertTrue( Mcpwp_Destructive_Gate::enabled_for_site() );
	}

	// ---------------------------------------------------------------
	// Round 4: the switch moves only from its form; every id shape is
	// checked; a target the gate cannot fingerprint is not deferred.
	// ---------------------------------------------------------------

	public function test_the_switch_changes_only_when_its_form_was_posted(): void {
		// Round-4 P1-1: sanitize_settings runs on EVERY update_option of
		// mcpwp_settings; an absent checkbox turned the gate off.
		if ( ! class_exists( 'Mcpwp_Settings' ) ) {
			require_once dirname( __DIR__ ) . '/includes/admin/class-mcpwp-settings.php';
		}
		$settings = ( new ReflectionClass( Mcpwp_Settings::class ) )->newInstanceWithoutConstructor();

		// A write from anywhere but the form (the settings API, the admin
		// chat): no marker, no key in the input → the stored choice stays.
		update_option( 'mcpwp_settings', array( 'enable_logging' => true ) );
		update_option( 'mcpwp_settings', $settings->sanitize_settings( array( 'enable_logging' => '1' ) ) );
		$this->assertTrue( Mcpwp_Destructive_Gate::enabled_for_site(), 'Only the form turns the gate off.' );
		$stored = get_option( 'mcpwp_settings' );
		$this->assertNotSame( false, isset( $stored['destructive_requires_approval'] ) ? $stored['destructive_requires_approval'] : null, 'No stored choice is never written as a stored false.' );

		update_option( 'mcpwp_settings', array( 'destructive_requires_approval' => false ) );
		update_option( 'mcpwp_settings', $settings->sanitize_settings( array( 'enable_logging' => '1' ) ) );
		$this->assertFalse( Mcpwp_Destructive_Gate::enabled_for_site(), 'And an explicit off is kept, not flipped on.' );

		// The form, with the checkbox unchecked: its marker is present → off.
		$off = $settings->sanitize_settings( array( 'enable_logging' => '1', '__present' => array( 'destructive_requires_approval' ) ) );
		$this->assertFalse( $off['destructive_requires_approval'] );
		$on = $settings->sanitize_settings( array( 'enable_logging' => '1', '__present' => array( 'destructive_requires_approval' ), 'destructive_requires_approval' => '1' ) );
		$this->assertTrue( $on['destructive_requires_approval'] );

		// The renderer emits the marker.
		if ( ! function_exists( 'checked' ) ) {
			function checked( $checked, $current = true, $echo = true ) { return $checked == $current ? 'checked=\'checked\'' : ''; } // phpcs:ignore
		}
		ob_start();
		$settings->render_checkbox_field( array( 'id' => 'destructive_requires_approval', 'description' => 'x' ) );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'name="mcpwp_settings[__present][]" value="destructive_requires_approval"', $html );
	}

	public function test_a_string_id_in_the_route_and_a_different_one_in_the_body_are_refused(): void {
		// Round-4 P2-1.
		require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-widgets.php';
		$never   = new class() { public function __call( $n, $a ) { return true; } };
		$widgets = new Mcpwp_REST_Widgets( $never );
		update_option( 'widget_text', array( 3 => array( 'text' => 'x' ), 9 => array( 'text' => 'y' ), '_multiwidget' => 1 ) );
		$request = new WP_REST_Request( 'DELETE', '/mcpwp/v1/widgets/text-3', array( 'id' => 'text-9' ) );
		$result  = $this->authed( $widgets, $request )->delete_widget( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'destructive_id_mismatch', $result->get_error_code() );

		$request = new WP_REST_Request( 'DELETE', '/mcpwp/v1/widgets/text-3', array( 'id' => 'text-3' ) );
		$this->assertSame( 202, $this->authed( $widgets, $request )->delete_widget( $request )->get_status() );
	}

	public function test_a_target_the_gate_cannot_fingerprint_is_not_deferred(): void {
		// Round-4 P2-2: an empty fingerprint used to mean "no drift check"
		// and the apply proceeded unchecked.
		require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-widgets.php';
		$never   = new class() { public function __call( $n, $a ) { return true; } };
		$widgets = new Mcpwp_REST_Widgets( $never );
		$request = new WP_REST_Request( 'DELETE', '/mcpwp/v1/widgets/my_single_widget', array( 'id' => 'my_single_widget' ) );
		$result  = $this->authed( $widgets, $request )->delete_widget( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_target', $result->get_error_code() );
		$this->assertSame( array(), Mcpwp_Approvals::list_requests( '', 50, true ) );

		$this->assertTrue( Mcpwp_Destructive_Gate::fingerprint_term( 0, 'course_category' )['unparseable'] );
		$this->assertTrue( Mcpwp_Destructive_Gate::fingerprint_widget( 'my_single_widget' )['unparseable'] );
	}

	public function test_a_template_delete_is_deferred_whatever_the_flag(): void {
		require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-elementor-pro.php';
		$never = new class() { public $calls = 0; public function __call( $n, $a ) { $this->calls++; return true; } };
		$this->seed( 70, 'elementor_library' );
		$ctl = ( new ReflectionClass( Mcpwp_REST_Elementor_Pro::class ) )->newInstanceWithoutConstructor();
		$prop = new ReflectionProperty( Mcpwp_REST_Elementor_Pro::class, 'elementor_pro' );
		$prop->setAccessible( true );
		$prop->setValue( $ctl, $never );

		$request = new WP_REST_Request( 'DELETE', '/mcpwp/v1/elementor/templates/70', array( 'id' => 70, 'force' => true ) );
		$this->assertSame( 202, $this->authed( $ctl, $request )->delete_template( $request )->get_status() );
		$this->assertSame( 0, $never->calls );

		// force=false is NOT a trash for elementor_library: WordPress deletes
		// it outright, so it is deferred just the same (round 5).
		$request = new WP_REST_Request( 'DELETE', '/mcpwp/v1/elementor/templates/70', array( 'id' => 70, 'force' => false ) );
		$this->assertSame( 202, $this->authed( $ctl, $request )->delete_template( $request )->get_status() );
		$this->assertSame( 0, $never->calls );
	}

	// ---------------------------------------------------------------
	// Round 5: "permanent" is decided by the type, not by the flag.
	// ---------------------------------------------------------------

	public function test_force_false_is_gated_wherever_wordpress_would_not_trash(): void {
		// Round-5 P0-1: wp_block, custom types, templates and media are
		// deleted outright on force=false; the gate rested on the flag.
		$this->assertFalse( Mcpwp_Destructive_Gate::delete_is_permanent( 'post', false ) );
		$this->assertFalse( Mcpwp_Destructive_Gate::delete_is_permanent( 'page', false ) );
		$this->assertTrue( Mcpwp_Destructive_Gate::delete_is_permanent( 'page', true ) );
		foreach ( array( 'wp_block', 'elementor_library', 'elementor_snippet', 'sm_team', 'attachment' ) as $type ) {
			$this->assertTrue( Mcpwp_Destructive_Gate::delete_is_permanent( $type, false ), $type );
		}

		// Media, force omitted.
		$this->seed( 12, 'attachment' );
		$media   = new Mcpwp_REST_Media();
		$request = new WP_REST_Request( 'DELETE', '/mcpwp/v1/media/12', array( 'id' => 12 ) );
		$this->assertSame( 202, $this->authed( $media, $request )->delete_media( $request )->get_status() );
		$this->assertArrayHasKey( 12, $GLOBALS['_mcpwp_test_posts'] );

		// A block through /posts, force omitted.
		$this->seed( 41, 'wp_block' );
		$posts   = new Mcpwp_REST_Posts();
		$request = new WP_REST_Request( 'DELETE', '/mcpwp/v1/posts/41', array( 'id' => 41 ) );
		$this->assertSame( 202, $this->authed( $posts, $request )->delete_post( $request )->get_status() );
		$this->assertArrayHasKey( 41, $GLOBALS['_mcpwp_test_posts'] );

		// A custom type through /content, force omitted.
		$GLOBALS['mcpwp_test_public_post_types'] = array( 'sm_team' );
		$this->seed( 31, 'sm_team' );
		$content = new Mcpwp_REST_Content();
		$request = new WP_REST_Request( 'DELETE', '/mcpwp/v1/content/sm_team/31', array( 'post_type' => 'sm_team', 'id' => 31 ) );
		$response = $this->authed( $content, $request )->delete_content( $request );
		$this->assertSame( 202, $response->get_status(), is_wp_error( $response ) ? $response->get_error_message() : wp_json_encode( $response->get_data() ) );
		$this->assertArrayHasKey( 31, $GLOBALS['_mcpwp_test_posts'] );
		unset( $GLOBALS['mcpwp_test_public_post_types'] );

		// A template, force omitted (round 4 gated only force=true).
		require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-elementor-pro.php';
		$never = new class() { public $calls = 0; public function __call( $n, $a ) { $this->calls++; return true; } };
		$this->seed( 70, 'elementor_library' );
		$ctl  = ( new ReflectionClass( Mcpwp_REST_Elementor_Pro::class ) )->newInstanceWithoutConstructor();
		$prop = new ReflectionProperty( Mcpwp_REST_Elementor_Pro::class, 'elementor_pro' );
		$prop->setAccessible( true );
		$prop->setValue( $ctl, $never );
		$request = new WP_REST_Request( 'DELETE', '/mcpwp/v1/elementor/templates/70', array( 'id' => 70 ) );
		$this->assertSame( 202, $this->authed( $ctl, $request )->delete_template( $request )->get_status() );
		$this->assertSame( 0, $never->calls );

		// A post or page on force=false still trashes in one call.
		$this->seed( 5, 'post' );
		$request = new WP_REST_Request( 'DELETE', '/mcpwp/v1/posts/5', array( 'id' => 5, 'force' => false ) );
		$this->assertSame( 200, $this->authed( $posts, $request )->delete_post( $request )->get_status() );
		$this->assertSame( 'trash', $GLOBALS['_mcpwp_test_posts'][5]->post_status );

		$this->assertCount( 4, Mcpwp_Approvals::list_requests( 'pending', 50, true ) );
	}

	public function test_the_delete_doubles_mirror_core(): void {
		// Round-5 P1-1: the harness trashed every type on force=false, so no
		// test could observe the bypass.
		$this->seed( 41, 'wp_block' );
		wp_delete_post( 41, false );
		$this->assertArrayNotHasKey( 41, $GLOBALS['_mcpwp_test_posts'], 'A block is deleted outright on force=false, as in core.' );
		$this->seed( 5, 'post' );
		wp_delete_post( 5, false );
		$this->assertSame( 'trash', $GLOBALS['_mcpwp_test_posts'][5]->post_status );
		$this->assertFalse( wp_delete_post( 999999, false ), 'A missing post is false, as in core.' );
		$this->seed( 12, 'attachment' );
		wp_delete_attachment( 12, false );
		$this->assertArrayNotHasKey( 12, $GLOBALS['_mcpwp_test_posts'], 'MEDIA_TRASH is off by default: an attachment is deleted outright.' );
	}

	public function test_a_post_like_target_with_no_usable_id_is_tagged_not_empty(): void {
		$this->assertTrue( Mcpwp_Destructive_Gate::fingerprint( array( 'type' => 'page', 'id' => '12abc' ) )['unparseable'] );
		$this->assertTrue( Mcpwp_Destructive_Gate::fingerprint( array( 'type' => 'page', 'id' => 0 ) )['unparseable'] );
	}

	// ---------------------------------------------------------------
	// Round 6: an already-trashed post is permanent; users are gated; the
	// oracle walks REST controllers, not only tool names.
	// ---------------------------------------------------------------

	public function test_force_false_on_an_already_trashed_post_is_permanent_and_gated(): void {
		$this->assertTrue( Mcpwp_Destructive_Gate::delete_is_permanent( 'post', false, 'trash' ) );
		$this->assertFalse( Mcpwp_Destructive_Gate::delete_is_permanent( 'post', false, 'publish' ) );
		$this->assertFalse( Mcpwp_Destructive_Gate::delete_is_permanent( 'product', false ), 'WooCommerce trashes a product on force=false.' );

		$this->seed( 7, 'post', 'trash' );
		$posts   = new Mcpwp_REST_Posts();
		$request = new WP_REST_Request( 'DELETE', '/mcpwp/v1/posts/7', array( 'id' => 7 ) );
		$this->assertSame( 202, $this->authed( $posts, $request )->delete_post( $request )->get_status() );
		$this->assertArrayHasKey( 7, $GLOBALS['_mcpwp_test_posts'] );

		$GLOBALS['mcpwp_test_public_post_types'] = array( 'page' );
		$this->seed( 8, 'page', 'trash' );
		$content = new Mcpwp_REST_Content();
		$request = new WP_REST_Request( 'DELETE', '/mcpwp/v1/content/page/8', array( 'post_type' => 'page', 'id' => 8 ) );
		$this->assertSame( 202, $this->authed( $content, $request )->delete_content( $request )->get_status() );
		$this->assertArrayHasKey( 8, $GLOBALS['_mcpwp_test_posts'] );
		unset( $GLOBALS['mcpwp_test_public_post_types'] );
	}

	public function test_deleting_a_user_is_deferred(): void {
		require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-users.php';
		$never = new class() {
			public $calls = 0;
			public $may   = true;
			public function current_principal_may_delete( $id ) { return $this->may; }
			public function __call( $n, $a ) { $this->calls++; return true; }
		};
		$ctl   = new Mcpwp_REST_Users( $never );
		if ( ! function_exists( 'get_user_by' ) ) {
			function get_user_by( $field, $value ) {
				return 42 === (int) $value ? (object) array( 'ID' => 42, 'user_login' => 'alice', 'user_email' => 'a@x.test', 'roles' => array( 'editor' ) ) : false;
			}
		}
		// The principal must already be allowed to delete the user, or the
		// gate never sees it (round 7): a 400 stays a 400.
		$never->may = false;
		$request = new WP_REST_Request( 'DELETE', '/mcpwp/v1/users/42', array( 'id' => 42 ) );
		$refused = $this->authed( $ctl, $request )->delete_user( $request );
		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'cannot_delete', $refused->get_error_code() );
		$this->assertSame( array(), Mcpwp_Approvals::list_requests( '', 50, true ), 'No approval was manufactured.' );

		$never->may = true;
		$response = $this->authed( $ctl, $request )->delete_user( $request );
		$this->assertSame( 202, $response->get_status(), is_wp_error( $response ) ? $response->get_error_message() : '' );
		$this->assertSame( 'delete_user', $response->get_data()['operation'] );
		$this->assertSame( 0, $never->calls );

		$fp = Mcpwp_Destructive_Gate::fingerprint( array( 'type' => 'user', 'id' => 42 ) );
		$this->assertTrue( $fp['exists'] );
		$this->assertSame( 'alice', $fp['title'] );
		$this->assertTrue( Mcpwp_Destructive_Gate::fingerprint_user( 0 )['unparseable'] );
	}

	public function test_every_controller_registering_a_delete_route_is_gated_or_listed_with_a_reason(): void {
		// Round-6 P0-2: the name-shaped oracle could not see a REST-only
		// delete (users). This one walks the controllers themselves.
		$root  = dirname( __DIR__ ) . '/includes';
		$files = array();
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) ) as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}
		$seen  = 0;
		foreach ( $files as $file ) {
			$src = file_get_contents( $file );
			// Only files that register routes are controllers.
			if ( false === strpos( $src, 'register_rest_route(' ) ) {
				continue;
			}
			// Every spelling WP accepts: the constant, ALLMETHODS, a string, a
			// comma list, or an array carrying DELETE anywhere in it.
			if ( ! preg_match( '/WP_REST_Server::(DELETABLE|ALLMETHODS)|["\']methods["\']\s*=>\s*(?:array\s*\(|\[)?[^)\]\n]*["\']DELETE["\']|["\']methods["\']\s*=>\s*["\'][^"\']*DELETE/i', $src ) ) {
				continue;
			}
			$seen++;
			$name = basename( $file );
			// File granularity, knowingly: a controller that gates one DELETE
			// and forgets another passes here. The per-route half of the
			// invariant is test_every_gated_operation_defers… and the
			// UNGATED list names each controller's ungated routes by reason.
			$this->assertTrue(
				false !== strpos( $src, 'Mcpwp_Destructive_Gate::should_gate' ) || isset( Mcpwp_Destructive_Gate::UNGATED_DELETE_CONTROLLERS[ $name ] ),
				$name . ' registers a DELETE route and is neither gated nor listed as ungated with a reason.'
			);
		}
		$this->assertGreaterThan( 10, $seen, 'Premise: the controllers were read.' );
		$this->assertArrayHasKey( 'class-mcpwp-rest-users.php', array_flip( array_map( 'basename', $files ) ) );
	}
}
