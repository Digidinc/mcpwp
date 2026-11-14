<?php
/**
 * #1108 — follow-ups from the #1103 gates.
 *
 *  1. Callback tools run in-process and never reach the route capability map:
 *     pinned as the CURRENT behaviour (an owner decision is open on whether to
 *     route them through the chokepoint or refuse them for user-run principals).
 *  4. POST /post-meta/{id}: protected keys, and keys whose plugin declared who
 *     may write them.
 *  5. GET /approvals and GET /approvals/{id} list only the posts a user-run
 *     principal may read.
 *
 * (2 and 3 — the Contributor/Subscriber fixtures and the missing-map
 * fail-closed test — live in RouteCapabilityMapTest.)
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-approvals.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-approvals.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-content.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-mcp.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-route-capability-map.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-posts.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-pages.php';

final class CapabilityMapFollowups1108Test extends TestCase {

	const AUTHOR      = 13;
	const SUBSCRIBER  = 14;
	const ADMINISTRATOR = 15;
	const EDITOR       = 16;

	const OWN_DRAFT    = 8801;
	const OTHERS_DRAFT = 8802;
	const PUBLISHED    = 8803;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_filters']      = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_is_admin']     = false;
		$GLOBALS['mcpwp_test_user_caps']    = array(
			2                  => array(),
			self::AUTHOR       => array_fill_keys( array( 'read', 'edit_posts', 'edit_published_posts', 'publish_posts', 'delete_posts', 'upload_files' ), true ),
			self::SUBSCRIBER   => array( 'read' => true ),
			self::EDITOR       => array_fill_keys( array( 'read', 'edit_posts', 'edit_others_posts', 'edit_pages', 'publish_posts', 'publish_pages' ), true ),
			self::ADMINISTRATOR => array_fill_keys( array( 'read', 'edit_posts', 'manage_options', 'edit_others_posts', 'read_private_posts' ), true ),
		);
		$GLOBALS['mcpwp_test_post_caps']    = array(
			self::AUTHOR => array(
				'read_post' => array( self::OWN_DRAFT => true, self::OTHERS_DRAFT => false, self::PUBLISHED => true ),
				'edit_post' => array( self::OWN_DRAFT => true, self::OTHERS_DRAFT => false, self::PUBLISHED => false ),
			),
		);
		$GLOBALS['_mcpwp_test_posts']       = array(
			self::OWN_DRAFT    => new WP_Post( array( 'ID' => self::OWN_DRAFT, 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'My own draft', 'post_author' => self::AUTHOR ) ),
			self::OTHERS_DRAFT => new WP_Post( array( 'ID' => self::OTHERS_DRAFT, 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Embargoed pricing plan', 'post_author' => 99 ) ),
			self::PUBLISHED    => new WP_Post( array( 'ID' => self::PUBLISHED, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Public article', 'post_author' => 99 ) ),
		);
		$GLOBALS['_mcpwp_test_meta']        = array();
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_filters']      = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_user_caps']    = array();
		$GLOBALS['mcpwp_test_post_caps']    = array();
		$GLOBALS['_mcpwp_test_posts']       = array();
		$GLOBALS['_mcpwp_test_meta']        = array();
		$GLOBALS['wp_filter']               = array();
		unset( $GLOBALS['post'], $GLOBALS['mcpwp_test_public_post_types'] );
		$GLOBALS['_mcpwp_test_registered_meta'] = array();
		Mcpwp_Post_Fields::set_user_run_principal( false );
		if ( function_exists( 'mcpwp_test_approvals_put' ) ) {
			mcpwp_test_approvals_put( array() );
		}
	}

	private function user_record( int $user_id ): array {
		return array( 'id' => 'oauth-user:' . $user_id, 'type' => 'oauth', 'scopes' => array( 'read', 'write' ), 'acts_as_user' => $user_id );
	}

	private function bot_record(): array {
		return array( 'id' => 'k-admin', 'label' => 'bot', 'type' => 'scoped', 'scopes' => array( 'read', 'write', 'admin' ), 'role' => 'admin', 'tool_categories' => array() );
	}

	private function as_principal( object $controller, array $record, int $user_id ): void {
		$ref = new ReflectionProperty( Mcpwp_REST_API::class, 'current_api_key_record' );
		$ref->setAccessible( true );
		$ref->setValue( $controller, $record );
		$GLOBALS['mcpwp_test_current_user'] = $user_id;
	}

	// ── 1. Callback tools: the map does not apply (pinned) ──────────────────

	/**
	 * PINS CURRENT BEHAVIOUR, not a desired one (#1108 item 1b is an open owner
	 * decision). A tool registered through mcpwp_register_tools with a
	 * 'callback' runs in-process, never through a REST route, so a user-run
	 * principal that holds nothing but 'read' still reaches it with write
	 * scope. If a change makes this test fail, the decision was taken: update
	 * this test and the map header together.
	 */
	public function test_callback_tools_are_not_checked_against_the_route_capability_map(): void {
		$called = false;
		add_filter(
			'mcpwp_register_tools',
			static function ( $tools ) use ( &$called ) {
				$tools[] = array(
					'name'        => 'digid_callback_tool',
					'description' => 'Run a trusted callback.',
					'callback'    => static function () use ( &$called ) {
						$called = true;
						return array( 'ran' => true );
					},
				);
				return $tools;
			}
		);

		$controller = new Mcpwp_REST_MCP();
		$this->as_principal( $controller, $this->user_record( self::SUBSCRIBER ), self::SUBSCRIBER );
		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array(),
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/call',
				'params'  => array( 'name' => 'digid_callback_tool', 'arguments' => array() ),
			)
		);
		$data = $controller->handle_mcp( $request )->get_data();

		$this->assertArrayNotHasKey( 'error', $data );
		$this->assertTrue( $called, 'A callback tool runs for a Subscriber-level user-run principal: no row covers it.' );

		// No row exists for it, and had the map been consulted it would refuse.
		$this->assertNull( Mcpwp_Route_Capability_Map::resolve( 'POST', 'callback:digid_callback_tool' ) );
		$this->assertSame( 'manage_options', Mcpwp_Route_Capability_Map::required_capability( 'POST', 'callback:digid_callback_tool' ) );
	}

	public function test_the_map_documents_that_it_is_not_enforcement_for_callback_tools(): void {
		$header = ( new ReflectionClass( 'Mcpwp_Route_Capability_Map' ) )->getDocComment();
		$method = ( new ReflectionMethod( 'Mcpwp_Route_Capability_Map', 'tool_rows' ) )->getDocComment();
		foreach ( array( 'class' => $header, 'tool_rows()' => $method ) as $where => $doc ) {
			$this->assertIsString( $doc, $where );
		}
		$file = (string) file_get_contents( dirname( __DIR__ ) . '/includes/core/class-mcpwp-route-capability-map.php' );
		$this->assertStringContainsString( 'NOT enforcement for CALLBACK tools', (string) preg_replace( '/\s*\n\s*\*\s*/', ' ', $file ) );
		$this->assertStringContainsString( 'DOCUMENTATION of tool rows, NOT enforcement', (string) $method );
	}

	/** A plugin's auth rule for a key: the filter (the test double's hook table) and the hook-name registry WordPress keeps. */
	private function declare_auth( string $hook ): void {
		add_filter( $hook, static function () {
			return false;
		} );
		$GLOBALS['wp_filter'][ $hook ] = true;
	}

	// ── 4. POST /post-meta/{id} ─────────────────────────────────────────────

	private function set_meta_as( ?array $record, int $user_id, int $post_id, string $key, $value ) {
		$controller = new Mcpwp_REST_Site_Content();
		if ( null !== $record ) {
			$this->as_principal( $controller, $record, $user_id );
			// The permission chokepoint does this in production, on every request.
			Mcpwp_Post_Fields::set_user_run_principal( ! empty( $record['acts_as_user'] ) );
		}
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/post-meta/' . $post_id );
		$request->set_param( 'id', $post_id );
		$request->set_param( 'key', $key );
		$request->set_param( 'value', $value );
		return $controller->set_post_meta_handler( $request );
	}

	public static function reserved_keys(): array {
		return array(
			'_edit_lock'         => array( '_edit_lock' ),
			'_edit_last'         => array( '_edit_last' ),
			'_wp_attached_file'  => array( '_wp_attached_file' ),
			'_wp_attachment_metadata' => array( '_wp_attachment_metadata' ),
			'_wp_trash_meta_status'   => array( '_wp_trash_meta_status' ),
			'_wp_old_slug'       => array( '_wp_old_slug' ),
			'case variant'       => array( '_WP_Attached_File' ),
			'_menu_item_url'     => array( '_menu_item_url' ),
			'_transient_x'       => array( '_transient_x' ),
		);
	}

	/**
	 * @dataProvider reserved_keys
	 */
	public function test_reserved_core_meta_keys_are_refused_for_user_run_and_bot_principals( string $key ): void {
		foreach ( array(
			'author'    => array( $this->user_record( self::AUTHOR ), self::AUTHOR ),
			'bot'       => array( $this->bot_record(), 2 ),
			'admin user' => array( $this->user_record( self::ADMINISTRATOR ), self::ADMINISTRATOR ),
		) as $who => list( $record, $user ) ) {
			$result = $this->set_meta_as( $record, $user, self::OWN_DRAFT, $key, 'x' );
			$this->assertInstanceOf( WP_Error::class, $result, "$who $key" );
			$this->assertSame( 'forbidden_meta_key', $result->get_error_code(), "$who $key" );
			$this->assertArrayNotHasKey( self::OWN_DRAFT, $GLOBALS['_mcpwp_test_meta'], "$who $key: nothing written" );
		}
	}

	public function test_a_protected_key_nobody_declared_anything_for_stays_writable_with_edit_post(): void {
		$result = $this->set_meta_as( $this->user_record( self::AUTHOR ), self::AUTHOR, self::OWN_DRAFT, '_sku', 'A-1' );
		$this->assertInstanceOf( WP_REST_Response::class, $result );
		$this->assertSame( 'A-1', get_post_meta( self::OWN_DRAFT, '_sku', true ) );
	}

	public function test_the_two_core_keys_the_route_accepts_still_need_edit_post_on_the_post(): void {
		$GLOBALS['_mcpwp_test_posts'][ self::OWN_DRAFT ]->post_type = 'page';
		$ok = $this->set_meta_as( $this->user_record( self::AUTHOR ), self::AUTHOR, self::OWN_DRAFT, '_wp_page_template', 'templates/wide.php' );
		$this->assertInstanceOf( WP_REST_Response::class, $ok );

		// Someone else's draft: the permission callback's per-post check is the
		// gate (check_oauth_user_can_change_post_refs) — the id is a declared post ref.
		$auth = new Mcpwp_REST_Site_Content();
		$this->as_principal( $auth, $this->user_record( self::AUTHOR ), self::AUTHOR );
		$refs = new class() extends Mcpwp_REST_Site_Content {
			public function refs( $request ) {
				return $this->collect_post_refs( $request );
			}
		};
		$found = $refs->refs( new WP_REST_Request( 'POST', '/mcpwp/v1/post-meta/' . self::OTHERS_DRAFT, array( 'id' => self::OTHERS_DRAFT, 'key' => '_wp_page_template' ) ) );
		$this->assertSame( array( self::OTHERS_DRAFT ), $found['scalar'], 'the post id of /post-meta is a declared reference, so edit_post is asked of it' );
		$this->assertFalse( current_user_can( 'edit_post', self::OTHERS_DRAFT ) );
	}

	public function test_a_key_with_a_declared_auth_rule_is_judged_by_edit_post_meta_for_a_user_run_principal(): void {
		$this->declare_auth( 'auth_post_meta__gate1108_flag' );

		// WordPress says no for this user and post.
		$GLOBALS['mcpwp_test_post_caps'][ self::AUTHOR ]['edit_post_meta'] = array( self::OWN_DRAFT => false );
		$denied = $this->set_meta_as( $this->user_record( self::AUTHOR ), self::AUTHOR, self::OWN_DRAFT, '_gate1108_flag', '1' );
		$this->assertInstanceOf( WP_Error::class, $denied );
		$this->assertSame( 'rest_forbidden_meta_key', $denied->get_error_code() );
		$this->assertSame( 403, $denied->get_error_data()['status'] );
		$this->assertSame( '', get_post_meta( self::OWN_DRAFT, '_gate1108_flag', true ), 'nothing written' );

		// WordPress says yes (the plugin's auth callback allows this user).
		$GLOBALS['mcpwp_test_post_caps'][ self::AUTHOR ]['edit_post_meta'] = array( self::OWN_DRAFT => true );
		$allowed = $this->set_meta_as( $this->user_record( self::AUTHOR ), self::AUTHOR, self::OWN_DRAFT, '_gate1108_flag', '1' );
		$this->assertInstanceOf( WP_REST_Response::class, $allowed );
		$this->assertSame( '1', get_post_meta( self::OWN_DRAFT, '_gate1108_flag', true ) );
	}

	public function test_the_declared_auth_rule_also_applies_to_the_per_post_type_filter(): void {
		$this->declare_auth( 'auth_post_meta__gate1108_typed_for_post' );
		$GLOBALS['mcpwp_test_post_caps'][ self::AUTHOR ]['edit_post_meta'] = array( self::OWN_DRAFT => false );
		$denied = $this->set_meta_as( $this->user_record( self::AUTHOR ), self::AUTHOR, self::OWN_DRAFT, '_gate1108_typed', '1' );
		$this->assertInstanceOf( WP_Error::class, $denied );
		$this->assertSame( 'rest_forbidden_meta_key', $denied->get_error_code() );
	}

	public function test_a_bot_run_key_is_not_asked_edit_post_meta(): void {
		$this->declare_auth( 'auth_post_meta__gate1108_flag' );
		$GLOBALS['mcpwp_test_post_caps'][2]['edit_post_meta'] = array( self::OWN_DRAFT => false );
		$result = $this->set_meta_as( $this->bot_record(), 2, self::OWN_DRAFT, '_gate1108_flag', '1' );
		$this->assertInstanceOf( WP_REST_Response::class, $result, 'bot-run behaviour is unchanged' );
		$this->assertSame( '1', get_post_meta( self::OWN_DRAFT, '_gate1108_flag', true ) );
	}

	// ── 5. GET /approvals and GET /approvals/{id} ───────────────────────────

	private function seed( string $suffix, array $overrides = array(), int $age_seconds = 0 ): string {
		$id     = 'appr_1108_' . $suffix;
		$record = array_merge(
			array(
				'id'                    => $id,
				'status'                => 'pending',
				'action'                => 'destructive_operation',
				'operation'             => 'delete_post',
				'title'                 => 'Destructive: delete_post',
				'note'                  => '',
				'tool'                  => 'delete_post',
				'resource'              => array( 'type' => 'post', 'id' => self::OTHERS_DRAFT, 'title' => 'Embargoed pricing plan' ),
				'diff'                  => array( 'summary' => 'No undo.' ),
				'payload'               => array( 'route' => '/mcpwp/v1/posts/' . self::OTHERS_DRAFT, 'method' => 'DELETE', 'params' => array(), 'target' => array() ),
				'rollback'              => array(),
				'metadata'              => array(),
				'requested_by_key_id'   => 'other-key',
				'visibility_categories' => array( 'content' ),
				'created_at'            => gmdate( 'c', time() - $age_seconds ),
				'updated_at'            => gmdate( 'c', time() - $age_seconds ),
				'approved_at'           => null,
				'applied_at'            => null,
				'rejected_at'           => null,
				'rolled_back_at'        => null,
			),
			$overrides
		);
		if ( ! isset( $overrides['payload'] ) && isset( $overrides['resource']['id'] ) && is_numeric( $overrides['resource']['id'] ) ) {
			$record['payload']['route'] = '/mcpwp/v1/posts/' . (int) $overrides['resource']['id'];
		}
		$items        = mcpwp_test_approvals_all();
		$items        = is_array( $items ) ? $items : array();
		$items[ $id ] = $record;
		mcpwp_test_approvals_put( $items );
		return $id;
	}

	private function resource_of( int $post_id ): array {
		$post = $GLOBALS['_mcpwp_test_posts'][ $post_id ];
		return array( 'type' => 'post', 'id' => $post_id, 'title' => $post->post_title );
	}

	private function list_as( array $record, int $user_id, array $params = array() ) {
		$controller = new Mcpwp_REST_Approvals();
		$this->as_principal( $controller, $record, $user_id );
		return $controller->list_approvals( new WP_REST_Request( 'GET', '/mcpwp/v1/approvals', array_merge( array( 'limit' => 50, 'order' => 'date' ), $params ) ) );
	}

	public function test_a_user_run_principal_lists_only_approvals_for_posts_it_can_read(): void {
		$hidden  = $this->seed( 'hidden', array(), 0 );
		$own     = $this->seed( 'own', array( 'resource' => $this->resource_of( self::OWN_DRAFT ) ), 10 );
		$public  = $this->seed( 'public', array( 'resource' => $this->resource_of( self::PUBLISHED ) ), 20 );
		$bulk    = $this->seed(
			'bulk',
			array(
				'action'   => 'bulk_content_publish',
				'resource' => array( 'type' => 'bulk_post', 'id' => null, 'title' => '2 post' ),
				'payload'  => array( 'items' => array( array( 'id' => self::OWN_DRAFT ), array( 'id' => self::OTHERS_DRAFT ) ), 'post_type' => 'post' ),
			),
			5
		);
		$keyless = $this->seed( 'staging', array( 'action' => 'staging_push', 'resource' => array( 'type' => 'staging', 'id' => null, 'title' => 'push' ), 'payload' => array() ), 30 );

		$response = $this->list_as( $this->user_record( self::AUTHOR ), self::AUTHOR );
		$ids      = array_column( $response->get_data()['approvals'], 'id' );

		$this->assertEqualsCanonicalizing( array( $own, $public, $keyless ), $ids );
		$this->assertNotContains( $hidden, $ids );
		$this->assertNotContains( $bulk, $ids, 'a bulk record naming an unreadable post is hidden whole' );

		$encoded = (string) wp_json_encode( $response->get_data() );
		$this->assertStringNotContainsString( 'Embargoed pricing plan', $encoded );
		$this->assertStringNotContainsString( (string) self::OTHERS_DRAFT, $encoded );
	}

	public function test_hidden_approvals_do_not_displace_visible_ones_out_of_a_page(): void {
		// Three hidden, newest first; two visible, older. limit=2 must still return both visible.
		$this->seed( 'h1', array(), 0 );
		$this->seed( 'h2', array(), 1 );
		$this->seed( 'h3', array(), 2 );
		$own    = $this->seed( 'own', array( 'resource' => $this->resource_of( self::OWN_DRAFT ) ), 30 );
		$public = $this->seed( 'public', array( 'resource' => $this->resource_of( self::PUBLISHED ) ), 40 );

		$page = $this->list_as( $this->user_record( self::AUTHOR ), self::AUTHOR, array( 'limit' => 2 ) )->get_data()['approvals'];

		$this->assertSame( array( $own, $public ), array_column( $page, 'id' ) );
	}

	public function test_an_approval_for_a_post_that_no_longer_exists_is_hidden_from_a_non_admin_user(): void {
		$gone = $this->seed( 'gone', array( 'resource' => array( 'type' => 'post', 'id' => 424242, 'title' => 'Deleted draft' ) ) );
		$ids  = array_column( $this->list_as( $this->user_record( self::AUTHOR ), self::AUTHOR )->get_data()['approvals'], 'id' );
		$this->assertNotContains( $gone, $ids );
	}

	public function test_an_administrator_user_and_a_bot_key_still_see_everything(): void {
		$hidden = $this->seed( 'hidden', array(), 0 );
		$gone   = $this->seed( 'gone', array( 'resource' => array( 'type' => 'post', 'id' => 424242, 'title' => 'Deleted draft' ) ), 5 );
		$own    = $this->seed( 'own', array( 'resource' => $this->resource_of( self::OWN_DRAFT ) ), 10 );

		$admin = array_column( $this->list_as( $this->user_record( self::ADMINISTRATOR ), self::ADMINISTRATOR )->get_data()['approvals'], 'id' );
		$this->assertEqualsCanonicalizing( array( $hidden, $gone, $own ), $admin );

		$bot = array_column( $this->list_as( $this->bot_record(), 2 )->get_data()['approvals'], 'id' );
		$this->assertEqualsCanonicalizing( array( $hidden, $gone, $own ), $bot );
	}

	public function test_get_approval_answers_a_hidden_one_exactly_like_a_missing_one(): void {
		$hidden  = $this->seed( 'hidden' );
		$own     = $this->seed( 'own', array( 'resource' => $this->resource_of( self::OWN_DRAFT ) ), 10 );
		$controller = new Mcpwp_REST_Approvals();
		$this->as_principal( $controller, $this->user_record( self::AUTHOR ), self::AUTHOR );

		$missing = $controller->get_approval( new WP_REST_Request( 'GET', '/mcpwp/v1/approvals/nope', array( 'id' => 'appr_1108_nope' ) ) );
		$denied  = $controller->get_approval( new WP_REST_Request( 'GET', '/mcpwp/v1/approvals/' . $hidden, array( 'id' => $hidden ) ) );
		$this->assertInstanceOf( WP_Error::class, $missing );
		$this->assertInstanceOf( WP_Error::class, $denied );
		$this->assertSame( $missing->get_error_code(), $denied->get_error_code() );
		$this->assertSame( $missing->get_error_message(), $denied->get_error_message() );
		$this->assertSame( $missing->get_error_data(), $denied->get_error_data() );

		$visible = $controller->get_approval( new WP_REST_Request( 'GET', '/mcpwp/v1/approvals/' . $own, array( 'id' => $own ) ) );
		$this->assertInstanceOf( WP_REST_Response::class, $visible );
		$this->assertSame( $own, $visible->get_data()['id'] );
	}

	public function test_post_ids_for_record_names_the_resource_and_bulk_items(): void {
		$this->assertSame( array( 5 ), Mcpwp_Approvals::post_ids_for_record( array( 'resource' => array( 'type' => 'page', 'id' => '5' ) ) ) );
		$this->assertSame( array(), Mcpwp_Approvals::post_ids_for_record( array( 'resource' => array( 'type' => 'api_key', 'id' => 7 ) ) ) );
		$this->assertSame( array(), Mcpwp_Approvals::post_ids_for_record( array( 'resource' => array( 'type' => 'drafts', 'id' => 7 ) ) ) );
		$this->assertSame( array( 3, 4 ), Mcpwp_Approvals::post_ids_for_record( array( 'action' => 'bulk_content_publish', 'resource' => array( 'type' => 'bulk_post', 'id' => null ), 'payload' => array( 'items' => array( array( 'id' => 3 ), array( 'id' => 4 ), array( 'id' => 3 ) ) ) ) ) );
	}
	// ── P1: case variants, and every door that writes meta ──────────────────

	public function test_a_case_changed_key_is_judged_like_the_key_the_rule_is_declared_for(): void {
		$this->declare_auth( 'auth_post_meta_rv_admin_only' );
		$GLOBALS['mcpwp_test_post_caps'][ self::AUTHOR ]['edit_post_meta'] = array( self::OWN_DRAFT => false );

		foreach ( array( 'rv_admin_only', 'RV_ADMIN_ONLY', 'Rv_Admin_Only' ) as $key ) {
			$this->assertContains( 'rv_admin_only', Mcpwp_Post_Fields::meta_key_variants( $key, self::OWN_DRAFT, 'post' ), $key );
		}
		// rv_admin_only is not protected, so WordPress is asked for it whatever the rule's source.
		foreach ( array( 'rv_admin_only', 'RV_ADMIN_ONLY', 'Rv_Admin_Only' ) as $key ) {
			$result = $this->set_meta_as( $this->user_record( self::AUTHOR ), self::AUTHOR, self::OWN_DRAFT, $key, 'x' );
			$this->assertInstanceOf( WP_Error::class, $result, $key );
			$this->assertSame( 'rest_forbidden_meta_key', $result->get_error_code(), $key );
		}
		$this->assertArrayNotHasKey( self::OWN_DRAFT, $GLOBALS['_mcpwp_test_meta'] );
	}

	public function test_variants_come_from_registered_keys_existing_rows_and_hook_names(): void {
		$GLOBALS['_mcpwp_test_registered_meta']['Reg_Key']            = array( 'sanitize_callback' => null );
		$GLOBALS['_mcpwp_test_meta'][ self::OWN_DRAFT ]['existing_k'] = 'v';
		$this->declare_auth( 'auth_post_meta__Hooked_For_post' );

		$this->assertContains( 'Reg_Key', Mcpwp_Post_Fields::meta_key_variants( 'reg_key', self::OWN_DRAFT, 'post' ) );
		$this->assertContains( 'existing_k', Mcpwp_Post_Fields::meta_key_variants( 'EXISTING_K', self::OWN_DRAFT, 'post' ) );
		$this->assertContains( '_Hooked', Mcpwp_Post_Fields::meta_key_variants( '_HOOKED', self::OWN_DRAFT, 'post' ) );
		$this->assertSame( array( 'plain' ), Mcpwp_Post_Fields::meta_key_variants( 'plain', self::OWN_DRAFT, 'post' ) );
	}

	public function test_the_deprecated_per_type_hook_counts_as_a_declared_rule(): void {
		$this->assertFalse( Mcpwp_Post_Fields::meta_auth_declared( '_gate1108_old', 'post' ) );
		$this->declare_auth( 'auth_post_post_meta__gate1108_old' );
		$this->assertTrue( Mcpwp_Post_Fields::meta_auth_declared( '_gate1108_old', 'post' ) );
	}

	public function test_a_key_wordpress_denies_is_refused_when_not_protected_even_without_a_hook(): void {
		// A plugin guarding a plain key through map_meta_cap: WordPress's answer is the decision.
		$GLOBALS['mcpwp_test_post_caps'][ self::AUTHOR ]['edit_post_meta'] = array( self::OWN_DRAFT => false );
		$result = $this->set_meta_as( $this->user_record( self::AUTHOR ), self::AUTHOR, self::OWN_DRAFT, 'guarded_plain_key', 'x' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_the_permission_chokepoint_tells_the_meta_helper_who_the_principal_is(): void {
		$probe = new class() {
			use Mcpwp_Api_Auth {
				check_gate_entry as public;
			}
			public function act_as( $record ) {
				$this->current_api_key_record = $record;
			}
		};
		$meta = array( '_gate1108_flag' => '1' );
		$this->declare_auth( 'auth_post_meta__gate1108_flag' );
		$GLOBALS['mcpwp_test_current_user']                                = self::AUTHOR;
		$GLOBALS['mcpwp_test_post_caps'][ self::AUTHOR ]['edit_post_meta'] = array( self::OWN_DRAFT => false );

		$probe->act_as( $this->user_record( self::AUTHOR ) );
		$probe->check_gate_entry( new WP_REST_Request( 'GET', '/mcpwp/v1/site-info' ) );
		$this->assertInstanceOf( WP_Error::class, Mcpwp_Post_Fields::meta_write_refusal( self::OWN_DRAFT, $meta, 'post' ) );

		$probe->act_as( $this->bot_record() );
		$probe->check_gate_entry( new WP_REST_Request( 'GET', '/mcpwp/v1/site-info' ) );
		$this->assertNull( Mcpwp_Post_Fields::meta_write_refusal( self::OWN_DRAFT, $meta, 'post' ), 'a bot-run principal is not asked' );
	}

	private function deny_flag_for_user_run(): void {
		$this->declare_auth( 'auth_post_meta_gate1108_plain_flag' );
		$GLOBALS['mcpwp_test_current_user']                                = self::AUTHOR;
		$GLOBALS['mcpwp_test_post_caps'][ self::AUTHOR ]['edit_post_meta'] = array( self::OWN_DRAFT => false );
		Mcpwp_Post_Fields::set_user_run_principal( true );
	}

	public function test_post_update_refuses_a_denied_meta_key_for_a_user_run_principal_and_writes_nothing(): void {
		$this->deny_flag_for_user_run();
		$result = ( new Mcpwp_Posts() )->update_post( self::OWN_DRAFT, array( 'title' => 'Changed', 'meta' => array( 'gate1108_plain_flag' => '1', 'plain' => 'x' ) ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden_meta_key', $result->get_error_code() );
		$this->assertArrayNotHasKey( self::OWN_DRAFT, $GLOBALS['_mcpwp_test_meta'], 'nothing written, not even the allowed key' );
		$this->assertSame( 'My own draft', get_post( self::OWN_DRAFT )->post_title, 'refused before the post itself changed' );
	}

	public function test_post_create_refuses_a_denied_meta_key_before_inserting_anything(): void {
		$this->declare_auth( 'auth_post_meta_gate1108_plain_flag' );
		$GLOBALS['mcpwp_test_current_user'] = self::AUTHOR;
		Mcpwp_Post_Fields::set_user_run_principal( true );
		$before   = array_keys( $GLOBALS['_mcpwp_test_posts'] );
		$inserted = 0;
		add_filter(
			'wp_insert_post_data',
			static function ( $data ) use ( &$inserted ) {
				++$inserted;
				return $data;
			}
		);

		$result = ( new Mcpwp_Posts() )->create_post( array( 'title' => 'New', 'post_type' => 'post', 'meta' => array( 'gate1108_plain_flag' => '1' ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden_meta_key', $result->get_error_code() );
		$this->assertSame( 0, $inserted, 'judged before insert: no insert, so no save_post / webhook / guardrail hook ran' );
		$this->assertSame( $before, array_keys( $GLOBALS['_mcpwp_test_posts'] ), 'nothing was created' );
	}

	public function test_page_create_and_update_refuse_a_denied_meta_key(): void {
		$GLOBALS['_mcpwp_test_posts'][ self::OWN_DRAFT ]->post_type = 'page';
		$this->deny_flag_for_user_run();

		$update = ( new Mcpwp_Pages() )->update_page( self::OWN_DRAFT, array( 'meta' => array( 'gate1108_plain_flag' => '1' ) ) );
		$this->assertInstanceOf( WP_Error::class, $update );
		$this->assertSame( 'rest_forbidden_meta_key', $update->get_error_code() );

		$before   = array_keys( $GLOBALS['_mcpwp_test_posts'] );
		$inserted = 0;
		add_filter(
			'wp_insert_post_data',
			static function ( $data ) use ( &$inserted ) {
				++$inserted;
				return $data;
			}
		);
		$create = ( new Mcpwp_Pages() )->create_page( array( 'title' => 'P', 'meta' => array( 'gate1108_plain_flag' => '1' ) ) );
		$this->assertInstanceOf( WP_Error::class, $create );
		$this->assertSame( 'rest_forbidden_meta_key', $create->get_error_code() );
		$this->assertSame( 0, $inserted );
		$this->assertSame( $before, array_keys( $GLOBALS['_mcpwp_test_posts'] ) );
		$this->assertArrayNotHasKey( self::OWN_DRAFT, $GLOBALS['_mcpwp_test_meta'] );
	}

	public function test_the_same_writes_succeed_for_a_bot_run_principal_and_for_undeclared_keys(): void {
		$this->declare_auth( 'auth_post_meta_gate1108_plain_flag' );
		$GLOBALS['mcpwp_test_post_caps'][ self::AUTHOR ]['edit_post_meta'] = array( self::OWN_DRAFT => false );

		Mcpwp_Post_Fields::set_user_run_principal( false );
		$bot = ( new Mcpwp_Posts() )->update_post( self::OWN_DRAFT, array( 'meta' => array( 'gate1108_plain_flag' => '1' ) ) );
		$this->assertIsArray( $bot );
		$this->assertSame( '1', get_post_meta( self::OWN_DRAFT, 'gate1108_plain_flag', true ) );

		// A user-run principal writing an undeclared plain key: unchanged (undeclared protected keys go through /post-meta, tested above).
		$GLOBALS['mcpwp_test_post_caps'][ self::AUTHOR ]['edit_post_meta'] = array( self::OWN_DRAFT => true );
		$GLOBALS['mcpwp_test_current_user']                                = self::AUTHOR;
		Mcpwp_Post_Fields::set_user_run_principal( true );
		$user = ( new Mcpwp_Posts() )->update_post( self::OWN_DRAFT, array( 'meta' => array( 'smc_plain' => 'p' ) ) );
		$this->assertIsArray( $user );
		$this->assertSame( 'p', get_post_meta( self::OWN_DRAFT, 'smc_plain', true ) );
	}

	public function test_a_create_guarded_only_through_map_meta_cap_is_refused_before_inserting(): void {
		add_filter(
			'map_meta_cap',
			static function ( $caps, $cap, $user_id, $args ) {
				if ( 'edit_post_meta' === $cap && isset( $args[1] ) && 'gate1108_mapcap' === $args[1] ) {
					$caps[] = 'do_not_allow';
				}
				return $caps;
			},
			10,
			4
		);
		$GLOBALS['mcpwp_test_current_user'] = self::AUTHOR;
		Mcpwp_Post_Fields::set_user_run_principal( true );
		$before = array_keys( $GLOBALS['_mcpwp_test_posts'] );

		$refused = ( new Mcpwp_Posts() )->create_post( array( 'title' => 'New', 'post_type' => 'post', 'meta' => array( 'gate1108_mapcap' => '1' ) ) );
		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( $before, array_keys( $GLOBALS['_mcpwp_test_posts'] ) );

		$ok = ( new Mcpwp_Posts() )->create_post( array( 'title' => 'New', 'post_type' => 'post', 'meta' => array( 'someOtherKey' => '1' ) ) );
		$this->assertIsArray( $ok, 'an unguarded key creates normally' );
	}

	public function test_the_lowercase_spelling_is_always_asked_about(): void {
		foreach ( array( 'RV_MAPCAP_ADMIN', 'Rv_Mapcap_Admin', 'rv_mapcap_admin' ) as $key ) {
			$this->assertContains( 'rv_mapcap_admin', Mcpwp_Post_Fields::meta_key_variants( $key, 0, 'post' ), $key );
		}
	}

	public function test_legitimate_mixed_case_keys_are_still_written_by_a_user_run_principal(): void {
		$GLOBALS['mcpwp_test_post_caps'][ self::AUTHOR ]['edit_post_meta'] = array( self::OWN_DRAFT => true );
		foreach ( array( 'camelCaseKey' => 'a', 'Acf_Field_Name' => 'b', '_acfFieldKey' => 'c', '_Yoast_Style' => 'd' ) as $key => $value ) {
			$result = $this->set_meta_as( $this->user_record( self::AUTHOR ), self::AUTHOR, self::OWN_DRAFT, $key, $value );
			$this->assertInstanceOf( WP_REST_Response::class, $result, $key );
			$this->assertSame( $value, get_post_meta( self::OWN_DRAFT, $key, true ), $key );
		}
	}

	// ── P1: the filtered list is bounded in memory ──────────────────────────

	/** A stored approval whose record really carries about $bytes bytes. */
	private function seed_heavy( string $suffix, array $overrides, int $age, int $bytes ): string {
		$id = $this->seed( $suffix, $overrides, $age );
		$items = mcpwp_test_approvals_all();
		$items[ $id ]['payload']['pad'] = str_repeat( 'x', $bytes );
		mcpwp_test_approvals_put( $items );
		return $id;
	}

	public function test_a_filtered_list_never_holds_more_than_one_page_budget_of_records_in_memory(): void {
		if ( ! function_exists( 'memory_reset_peak_usage' ) ) {
			$this->markTestSkipped( 'memory_reset_peak_usage() needs PHP 8.2.' );
		}
		$mb = 1048576;
		// 40 hidden ~1 MB records (newest), then 3 visible ones.
		$items = array();
		for ( $i = 0; $i < 40; $i++ ) {
			$this->seed( 'h' . $i, array(), $i );
		}
		$visible = array(
			$this->seed( 'v1', array( 'resource' => $this->resource_of( self::OWN_DRAFT ) ), 100 ),
			$this->seed( 'v2', array( 'resource' => $this->resource_of( self::PUBLISHED ) ), 101 ),
			$this->seed( 'v3', array( 'resource' => $this->resource_of( self::OWN_DRAFT ) ), 102 ),
		);
		$all = mcpwp_test_approvals_all();
		foreach ( array_keys( $all ) as $id ) {
			$all[ $id ]['payload']['pad'] = str_repeat( 'x', $mb );
		}
		mcpwp_test_approvals_put( $all );
		unset( $all );

		memory_reset_peak_usage();
		$baseline = memory_get_usage();
		$ids      = array_column( $this->list_as( $this->user_record( self::AUTHOR ), self::AUTHOR, array( 'limit' => 100 ) )->get_data()['approvals'], 'id' );
		$peak     = memory_get_peak_usage() - $baseline;

		$this->assertSame( $visible, $ids, 'every visible record is listed, none displaced' );
		$this->assertLessThan( 40 * $mb, $peak, 'hidden ~1 MB records are loaded in groups bounded by the page budget (peak delta ' . (int) ( $peak / $mb ) . ' MB)' );
	}

	public function test_hidden_records_do_not_spend_the_visible_byte_budget(): void {
		// Nine hidden records of 1 MB quota each, then four visible ones: the
		// visible ones fit the 8 MB page budget whatever came before them.
		for ( $i = 0; $i < 9; $i++ ) {
			$this->seed( 'hb' . $i, array(), $i );
		}
		$expect = array();
		foreach ( array( self::OWN_DRAFT, self::PUBLISHED, self::OWN_DRAFT, self::PUBLISHED ) as $n => $post ) {
			$expect[] = $this->seed( 'vb' . $n, array( 'resource' => $this->resource_of( $post ) ), 100 + $n );
		}
		// seed() re-imports the store, so set the quota columns once everything is in.
		foreach ( $GLOBALS['mcpwp_test_approvals_rows'] as $id => $row ) {
			$GLOBALS['mcpwp_test_approvals_rows'][ $id ]['quota_bytes'] = 1048576;
		}
		$ids = array_column( $this->list_as( $this->user_record( self::AUTHOR ), self::AUTHOR )->get_data()['approvals'], 'id' );
		$this->assertSame( $expect, $ids );
	}

	// ── Creates are judged like core REST judges them: against the post being created ──

	/** The rule pattern most register_post_meta() callbacks use: may this user edit THIS post. */
	private function declare_edit_post_rule( string $key ): void {
		add_filter(
			'auth_post_meta_' . $key,
			static function ( $allowed, $meta_key, $post_id ) {
				return current_user_can( 'edit_post', $post_id );
			},
			10,
			3
		);
		$GLOBALS['wp_filter']['auth_post_meta_' . $key] = true;
	}

	public function test_a_rule_that_asks_edit_post_on_this_post_lets_every_user_who_can_create_create(): void {
		$this->declare_edit_post_rule( 'gate1108_editpost_rule' );
		foreach ( array( 'author' => self::AUTHOR, 'editor' => self::EDITOR, 'administrator' => self::ADMINISTRATOR ) as $who => $user ) {
			$GLOBALS['mcpwp_test_user_caps'][ self::ADMINISTRATOR ]['edit_posts'] = true;
			$GLOBALS['mcpwp_test_current_user'] = $user;
			Mcpwp_Post_Fields::set_user_run_principal( true );
			$created = ( new Mcpwp_Posts() )->create_post( array( 'title' => "By $who", 'post_type' => 'post', 'meta' => array( 'gate1108_editpost_rule' => '1' ) ) );
			$this->assertIsArray( $created, "$who: core REST creates this; so does this door" );
		}
		// A bot-run principal is not asked at all.
		$GLOBALS['mcpwp_test_current_user'] = 2;
		Mcpwp_Post_Fields::set_user_run_principal( false );
		$this->assertIsArray( ( new Mcpwp_Posts() )->create_post( array( 'title' => 'By bot', 'post_type' => 'post', 'meta' => array( 'gate1108_editpost_rule' => '1' ) ) ) );
		// A page-type create with the same kind of rule.
		$GLOBALS['mcpwp_test_current_user'] = self::EDITOR;
		Mcpwp_Post_Fields::set_user_run_principal( true );
		$this->assertIsArray( ( new Mcpwp_Pages() )->create_page( array( 'title' => 'Page', 'meta' => array( 'gate1108_editpost_rule' => '1' ) ) ) );
	}

	public function test_a_rule_that_reads_the_post_is_judged_against_the_post_about_to_be_created(): void {
		// Allowed only while the post is a draft by its own author.
		add_filter(
			'auth_post_meta_gate1108_postrule',
			static function ( $allowed, $key, $post_id, $user_id ) {
				$post = get_post( $post_id );
				return $post && (int) $post->post_author === (int) $user_id && 'draft' === $post->post_status;
			},
			10,
			4
		);
		$GLOBALS['wp_filter']['auth_post_meta_gate1108_postrule'] = true;
		$GLOBALS['mcpwp_test_current_user'] = self::EDITOR;
		Mcpwp_Post_Fields::set_user_run_principal( true );

		$draft = ( new Mcpwp_Posts() )->create_post( array( 'title' => 'D', 'post_type' => 'post', 'status' => 'draft', 'meta' => array( 'gate1108_postrule' => '1' ) ) );
		$this->assertIsArray( $draft );
		$published = ( new Mcpwp_Posts() )->create_post( array( 'title' => 'P', 'post_type' => 'post', 'status' => 'publish', 'meta' => array( 'gate1108_postrule' => '1' ) ) );
		$this->assertInstanceOf( WP_Error::class, $published, 'the rule sees the intended status' );
	}

	public function test_a_post_dependent_map_meta_cap_guard_is_judged_on_create(): void {
		add_filter(
			'map_meta_cap',
			static function ( $caps, $cap, $user_id, $args ) {
				$post = get_post( isset( $args[0] ) ? $args[0] : 0 );
				if ( 'edit_post_meta' === $cap && isset( $args[1] ) && 'gate1108_pubguard' === $args[1] && $post && 'publish' === $post->post_status ) {
					$caps[] = 'manage_options';
				}
				return $caps;
			},
			10,
			4
		);
		$GLOBALS['mcpwp_test_current_user'] = self::AUTHOR;
		Mcpwp_Post_Fields::set_user_run_principal( true );
		$this->assertIsArray( ( new Mcpwp_Posts() )->create_post( array( 'title' => 'D', 'post_type' => 'post', 'status' => 'draft', 'meta' => array( 'gate1108_pubguard' => '1' ) ) ) );
		$refused = ( new Mcpwp_Posts() )->create_post( array( 'title' => 'P', 'post_type' => 'post', 'status' => 'publish', 'meta' => array( 'gate1108_pubguard' => '1' ) ) );
		$this->assertInstanceOf( WP_Error::class, $refused, 'the guard sees the post as published' );
		$GLOBALS['mcpwp_test_current_user'] = self::ADMINISTRATOR;
		$GLOBALS['mcpwp_test_user_caps'][ self::ADMINISTRATOR ]['edit_posts'] = true;
		$GLOBALS['mcpwp_test_user_caps'][ self::ADMINISTRATOR ]['manage_options'] = true;
		$this->assertIsArray( ( new Mcpwp_Posts() )->create_post( array( 'title' => 'P', 'post_type' => 'post', 'status' => 'publish', 'meta' => array( 'gate1108_pubguard' => '1' ) ) ) );
	}

	public function test_a_create_is_never_judged_against_an_unrelated_global_post(): void {
		// A rule that only a post titled LEAK satisfies: the loop's own post must not stand in for the new one.
		add_filter(
			'auth_post_meta_gate1108_leak',
			static function ( $allowed, $key, $post_id ) {
				$post = get_post( $post_id );
				return $post && 'LEAK' === $post->post_title;
			},
			10,
			3
		);
		$GLOBALS['wp_filter']['auth_post_meta_gate1108_leak'] = true;
		$unrelated = new WP_Post( array( 'ID' => 9999, 'post_author' => self::AUTHOR, 'post_title' => 'LEAK', 'post_type' => 'post', 'post_status' => 'draft' ) );
		$GLOBALS['post'] = $unrelated;
		$GLOBALS['mcpwp_test_current_user'] = self::AUTHOR;
		Mcpwp_Post_Fields::set_user_run_principal( true );

		$result = ( new Mcpwp_Posts() )->create_post( array( 'title' => 'New', 'post_type' => 'post', 'meta' => array( 'gate1108_leak' => '1' ) ) );
		$this->assertInstanceOf( WP_Error::class, $result, 'judged against the new post, not the global one' );
		$this->assertSame( $unrelated, $GLOBALS['post'], 'the request\'s global post is restored' );

		unset( $GLOBALS['post'] );
		( new Mcpwp_Posts() )->create_post( array( 'title' => 'New', 'post_type' => 'post', 'meta' => array( 'gate1108_leak' => '1' ) ) );
		$this->assertArrayNotHasKey( 'post', $GLOBALS, 'and absent stays absent' );
	}

	public function test_the_deprecated_per_type_hook_is_judged_on_the_create_path(): void {
		$GLOBALS['mcpwp_test_current_user'] = self::AUTHOR;
		Mcpwp_Post_Fields::set_user_run_principal( true );
		$this->declare_auth( 'auth_post_post_meta__gate1108_old' );
		$before = array_keys( $GLOBALS['_mcpwp_test_posts'] );
		$result = ( new Mcpwp_Posts() )->create_post( array( 'title' => 'New', 'post_type' => 'post', 'meta' => array( '_gate1108_old' => '1' ) ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $before, array_keys( $GLOBALS['_mcpwp_test_posts'] ) );
	}

	public function test_the_deprecated_per_type_hook_is_judged_on_the_post_meta_route_for_a_protected_key(): void {
		$this->declare_auth( 'auth_post_post_meta__gate1108_old2' );
		$GLOBALS['mcpwp_test_post_caps'][ self::AUTHOR ]['edit_post_meta'] = array( self::OWN_DRAFT => false );
		$result = $this->set_meta_as( $this->user_record( self::AUTHOR ), self::AUTHOR, self::OWN_DRAFT, '_gate1108_old2', '1' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden_meta_key', $result->get_error_code() );
	}

	public function test_a_type_specific_rule_applies_to_that_type_only(): void {
		$GLOBALS['mcpwp_test_public_post_types'] = array( 'sm_team' );
		$this->declare_auth( 'auth_post_meta_gate1108_typed_key_for_sm_team' );
		$GLOBALS['mcpwp_test_current_user'] = self::AUTHOR;
		Mcpwp_Post_Fields::set_user_run_principal( true );

		$team = ( new Mcpwp_Posts() )->create_post( array( 'title' => 'T', 'post_type' => 'sm_team', 'meta' => array( 'gate1108_typed_key' => '1' ) ) );
		$this->assertInstanceOf( WP_Error::class, $team, 'denied for the type the rule names' );
		$post = ( new Mcpwp_Posts() )->create_post( array( 'title' => 'P', 'post_type' => 'post', 'meta' => array( 'gate1108_typed_key' => '1' ) ) );
		$this->assertIsArray( $post, 'the same key on another type has no rule' );
	}

	// ── P2: approvals list shape ────────────────────────────────────────────

	public function test_a_non_admin_bot_and_an_administrator_still_see_damaged_record_stubs(): void {
		$this->seed( 'ok', array( 'resource' => $this->resource_of( self::OWN_DRAFT ) ) );
		$this->corrupt_one( 'ok' );

		foreach ( array(
			'bot'   => array( $this->bot_record(), 2 ),
			'admin' => array( $this->user_record( self::ADMINISTRATOR ), self::ADMINISTRATOR ),
		) as $who => list( $record, $user ) ) {
			$items = $this->list_as( $record, $user )->get_data()['approvals'];
			$this->assertContains( 'corrupt_record', array_column( $items, 'error' ), "$who still sees the damaged stub" );
		}
		$author = $this->list_as( $this->user_record( self::AUTHOR ), self::AUTHOR )->get_data()['approvals'];
		$this->assertNotContains( 'corrupt_record', array_column( $author, 'error' ), 'a filtered caller cannot prove visibility' );
	}

	public function test_large_hidden_approvals_do_not_use_up_the_visible_callers_page(): void {
		$max = Mcpwp_Approvals::LIST_PAGE_BYTES_MAX;
		for ( $i = 0; $i < 9; $i++ ) {
			$big = $this->seed( 'big' . $i, array(), $i );
		}
		$own    = $this->seed( 'own', array( 'resource' => $this->resource_of( self::OWN_DRAFT ) ), 100 );
		$public = $this->seed( 'public', array( 'resource' => $this->resource_of( self::PUBLISHED ) ), 101 );
		// seed() re-imports the store, so set the quota columns once everything is in.
		for ( $i = 0; $i < 9; $i++ ) {
			$GLOBALS['mcpwp_test_approvals_rows'][ 'appr_1108_big' . $i ]['quota_bytes'] = 1048576;
		}
		$this->assertGreaterThan( $max, 9 * 1048576 );

		$ids = array_column( $this->list_as( $this->user_record( self::AUTHOR ), self::AUTHOR )->get_data()['approvals'], 'id' );
		$this->assertSame( array( $own, $public ), $ids );
	}

	// ── P2: only post-like resources name a post ────────────────────────────

	public function test_a_term_or_user_resource_does_not_depend_on_a_post_with_the_same_id(): void {
		foreach ( array( 300 => 'term', 4 => 'user', 5 => 'term' ) as $number => $type ) {
			$GLOBALS['_mcpwp_test_posts'][ $number ] = new WP_Post( array( 'ID' => $number, 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Unrelated hidden draft', 'post_author' => 99 ) );
			$GLOBALS['mcpwp_test_post_caps'][ self::AUTHOR ]['read_post'][ $number ] = false;
			$this->assertSame( array(), Mcpwp_Approvals::post_ids_for_record( array( 'resource' => array( 'type' => $type, 'id' => $number ) ) ), "$type $number names no post" );
			$id = $this->seed( 'n' . $number, array( 'resource' => array( 'type' => $type, 'id' => $number, 'title' => 'x' ), 'payload' => array( 'route' => '/mcpwp/v1/' . $type . 's/' . $number ) ), $number );
			$ids = array_column( $this->list_as( $this->user_record( self::AUTHOR ), self::AUTHOR )->get_data()['approvals'], 'id' );
			$this->assertContains( $id, $ids, "$type $number is listed whatever the post with that id is" );
		}
		// A real post type still resolves.
		$this->assertSame( array( 7 ), Mcpwp_Approvals::post_ids_for_record( array( 'resource' => array( 'type' => 'page', 'id' => 7 ) ) ) );
		$this->assertSame( array( 7 ), Mcpwp_Approvals::post_ids_for_record( array( 'resource' => array( 'type' => 'attachment', 'id' => 7 ) ) ) );
		// Bulk items are read only for a bulk publish.
		$this->assertSame( array(), Mcpwp_Approvals::post_ids_for_record( array( 'action' => 'destructive_operation', 'resource' => array( 'type' => 'term', 'id' => 9 ), 'payload' => array( 'items' => array( array( 'id' => 3 ) ) ) ) ) );
	}

	/** Make a stored record unreadable the way a damaged row is. */
	private function corrupt_one( string $suffix ): void {
		$items = mcpwp_test_approvals_all();
		$id    = 'appr_1108_' . $suffix;
		$this->assertArrayHasKey( $id, $items );
		$GLOBALS['mcpwp_test_approvals_rows'][ $id ]['record'] = '{not json';
	}
}
