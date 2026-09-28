<?php
/**
 * Regression tests — #1031.
 *
 * /action-log is gated only the blanket 'admin' category
 * (get_route_category_map()), so a custom key holding 'admin' but not, say,
 * 'woocommerce' could read every OTHER key's write-tool activity there — the
 * same class of leak #1025 closed for /approvals and /events, at lower
 * detail (no titles/arguments, but tool_name/category/resource_type/
 * resource_id are already enough to learn a product exists and is being
 * worked on).
 *
 * The action log stores the TOOL's own category at write time
 * (Mcpwp_REST_MCP::handle_tools_call()'s $audit_category, from the
 * tool→category map) — not the resource's. A generic, post-type-agnostic
 * write tool (wp_update_post, wp_delete_post, wp_bulk_update_posts, ...)
 * used against a post belonging to a DIFFERENT domain (a WooCommerce
 * product, an Elementor template) logs under its own door's category
 * ('content'), not the resource's ('woocommerce', 'elementor-templates') —
 * the same additive gap #1020/#1025 (round 2, BLOCK-1) closed for
 * /post-meta and /approvals, applied here to the audit trail itself.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-approvals.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-action-log.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-action-log.php';

final class ActionLogCategoryGateTest extends TestCase {

	private const PRODUCT_ID        = 9201;
	private const ORDINARY_POST_ID  = 9202;
	private const DELETED_POST_ID   = 9203; // Never in $GLOBALS['_mcpwp_test_posts'] — simulates hard-deleted.

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']            = array();
		$GLOBALS['mcpwp_test_wpdb_rows']          = array();
		$GLOBALS['mcpwp_test_public_post_types']  = array( 'product' );
		$GLOBALS['mcpwp_test_post_type_supports'] = array( 'product' => array( 'thumbnail' ) );
		$GLOBALS['_mcpwp_test_posts']             = array(
			self::PRODUCT_ID       => new WP_Post(
				array(
					'ID'          => self::PRODUCT_ID,
					'post_type'   => 'product',
					'post_title'  => 'SECRET PRODUCT Zeta',
					'post_name'   => 'secret-product-zeta',
					'post_status' => 'publish',
				)
			),
			self::ORDINARY_POST_ID => new WP_Post(
				array(
					'ID'          => self::ORDINARY_POST_ID,
					'post_type'   => 'post',
					'post_title'  => 'Ordinary post',
					'post_name'   => 'ordinary-post',
					'post_status' => 'publish',
				)
			),
		);

		// A test-scoped interceptor answering only mcpwp_action_log queries
		// from the row list $wpdb->insert() already captures generically
		// (tests/bootstrap.php's own Mcpwp_Test_WPDB); everything else falls
		// through to the double's built-in handling.
		$GLOBALS['mcpwp_test_wpdb_intercept'] = static function ( $method, $query ) {
			if ( false === strpos( $query, 'mcpwp_action_log' ) ) {
				return MCPWP_TEST_WPDB_PASS;
			}

			// A real SELECT * always returns every column, even NULL ones;
			// $wpdb->insert()'s generic double only captures the columns
			// Mcpwp_Action_Log::insert() actually passed (no rolled_back_at
			// until a rollback happens), so backfill the rest here the same
			// way a real row would have them.
			$defaults = array(
				'log_id'             => '',
				'timestamp'          => '',
				'tool_name'          => '',
				'category'           => '',
				'api_key_id'         => '',
				'arguments'          => '',
				'before_snap'        => null,
				'after_snap'         => null,
				'resource_type'      => '',
				'resource_id'        => '',
				'duration_ms'        => 0,
				'success'            => 0,
				'error_code'         => '',
				'rollback_supported' => 0,
				'rolled_back'        => 0,
				'rolled_back_at'     => null,
			);
			$rows = array_map(
				function ( $row ) use ( $defaults ) {
					return array_merge( $defaults, $row );
				},
				$GLOBALS['mcpwp_test_wpdb_rows']['wp_mcpwp_action_log'] ?? array()
			);
			usort(
				$rows,
				function ( $a, $b ) {
					return strcmp( (string) ( $b['timestamp'] ?? '' ), (string) ( $a['timestamp'] ?? '' ) );
				}
			);

			if ( 'get_var' === $method ) {
				return (string) count( $rows );
			}

			if ( 'get_row' === $method ) {
				if ( preg_match( "/log_id = '((?:[^'\\\\]|\\\\.)*)'/", $query, $m ) ) {
					$log_id = str_replace( "\\'", "'", $m[1] );
					foreach ( $rows as $row ) {
						if ( isset( $row['log_id'] ) && $log_id === $row['log_id'] ) {
							return $row;
						}
					}
				}
				return null;
			}

			// get_results
			if ( preg_match( '/LIMIT (\d+)(?:\s+OFFSET\s+(\d+))?/i', $query, $m ) ) {
				$limit  = (int) $m[1];
				$offset = isset( $m[2] ) ? (int) $m[2] : 0;
				return array_slice( $rows, $offset, $limit );
			}
			return $rows;
		};
	}

	protected function tearDown(): void {
		unset( $GLOBALS['mcpwp_test_wpdb_intercept'] );
	}

	private function force_key_record( object $controller, array $categories, string $key_id = 'forced', string $role = 'custom' ): void {
		$ref = new ReflectionProperty( get_class( $controller ), 'current_api_key_record' );
		$ref->setAccessible( true );
		$ref->setValue(
			$controller,
			array(
				'id'              => $key_id,
				'label'           => 'forced',
				'scopes'          => array( 'read', 'write', 'admin' ),
				'role'            => $role,
				'tool_categories' => $categories,
			)
		);
	}

	/** A real scoped key, authenticated on $controller via check_permission(). */
	private function real_scoped_key_request( object $controller, array $categories, string $method, string $route, array $params = array() ): WP_REST_Request {
		$created = $controller->create_scoped_api_key( 'scoped-' . implode( '-', $categories ), array( 'read', 'write' ), 'custom', $categories );
		$request = new WP_REST_Request( $method, $route, $params, array( 'X-API-Key' => $created['key'] ) );
		$auth    = $controller->check_permission( $request );
		$this->assertTrue( $auth, 'premise: authentication + route-category gate must pass for this key/route' );
		return $request;
	}

	/**
	 * Seed one write-tool entry through the REAL begin()/complete() path (not
	 * a hand-built row), so resource detection, snapshotting and the
	 * rollback_supported flag are exactly what production writes.
	 */
	private function seed_entry( string $tool_name, array $arguments, string $category, string $api_key_id = 'writer-key' ): string {
		$log_id = Mcpwp_Action_Log::begin( $tool_name, $arguments, $api_key_id, $category );
		$this->assertNotNull( $log_id, 'premise: ' . $tool_name . ' must be recognised as a write tool' );
		Mcpwp_Action_Log::complete( $log_id, 5, true, '' );
		return $log_id;
	}

	// ── Mcpwp_Action_Log::is_entry_visible_to(): the chokepoint itself ──────

	public function test_tool_category_alone_hides_an_entry() {
		$entry            = array( 'category' => 'woocommerce', 'resource_type' => 'other', 'resource_id' => '' );
		$category_allowed = function ( $category ) {
			return 'woocommerce' !== $category;
		};

		$this->assertFalse( Mcpwp_Action_Log::is_entry_visible_to( $entry, $category_allowed ) );
	}

	public function test_generic_tool_category_does_not_hide_a_product_entry_on_its_own() {
		// #1031's own PoC: the TOOL's category ('content') is allowed, but
		// the entry names a product — the additive post-type check must
		// still deny it.
		$entry            = array( 'category' => 'content', 'resource_type' => 'post', 'resource_id' => (string) self::PRODUCT_ID );
		$category_allowed = function ( $category ) {
			return in_array( $category, array( 'content', 'admin' ), true );
		};

		$this->assertFalse( Mcpwp_Action_Log::is_entry_visible_to( $entry, $category_allowed ) );
	}

	public function test_entry_visible_once_key_holds_both_tool_and_post_type_category() {
		$entry            = array( 'category' => 'content', 'resource_type' => 'post', 'resource_id' => (string) self::PRODUCT_ID );
		$category_allowed = function ( $category ) {
			return in_array( $category, array( 'content', 'admin', 'woocommerce' ), true );
		};

		$this->assertTrue( Mcpwp_Action_Log::is_entry_visible_to( $entry, $category_allowed ) );
	}

	public function test_ordinary_post_entry_requires_only_the_tool_category() {
		$entry            = array( 'category' => 'content', 'resource_type' => 'post', 'resource_id' => (string) self::ORDINARY_POST_ID );
		$category_allowed = function ( $category ) {
			return 'content' === $category;
		};

		$this->assertTrue( Mcpwp_Action_Log::is_entry_visible_to( $entry, $category_allowed ) );
	}

	public function test_hard_deleted_post_fails_closed_to_unresolvable_not_the_tool_category() {
		$entry = array( 'category' => 'content', 'resource_type' => 'post', 'resource_id' => (string) self::DELETED_POST_ID );

		// A restricted key holding the tool's own category, but nothing else,
		// must NOT see it once the post can no longer be resolved.
		$restricted = function ( $category ) {
			return 'content' === $category;
		};
		$this->assertFalse( Mcpwp_Action_Log::is_entry_visible_to( $entry, $restricted ) );

		// An unrestricted key (every category allowed) still sees it.
		$unrestricted = function () {
			return true;
		};
		$this->assertTrue( Mcpwp_Action_Log::is_entry_visible_to( $entry, $unrestricted ) );
	}

	public function test_null_category_allowed_is_unfiltered() {
		$entry = array( 'category' => 'woocommerce', 'resource_type' => 'post', 'resource_id' => (string) self::PRODUCT_ID );
		$this->assertTrue( Mcpwp_Action_Log::is_entry_visible_to( $entry, null ) );
	}

	// ── Mcpwp_REST_Action_Log::list_entries() ───────────────────────────────

	public function test_list_entries_hides_a_product_entry_from_an_admin_content_key() {
		$hidden_id  = $this->seed_entry( 'wp_update_post', array( 'id' => self::PRODUCT_ID, 'title' => 'new title' ), 'content' );
		$visible_id = $this->seed_entry( 'wp_update_post', array( 'id' => self::ORDINARY_POST_ID, 'title' => 'new title' ), 'content' );

		$controller = new Mcpwp_REST_Action_Log();
		$this->force_key_record( $controller, array( 'admin', 'content' ) );

		$response = $controller->list_entries( new WP_REST_Request( 'GET', '/mcpwp/v1/action-log', array( 'limit' => 50, 'offset' => 0 ) ) );
		$data     = $response->get_data();
		$ids      = array_column( $data['entries'], 'log_id' );

		$this->assertContains( $visible_id, $ids );
		$this->assertNotContains( $hidden_id, $ids, 'a [admin, content] key must not see the product write-tool entry' );
		$this->assertSame( 1, $data['total'], 'total must be recomputed after filtering, not the raw row count' );
	}

	public function test_list_entries_shows_it_once_the_key_also_holds_woocommerce() {
		$hidden_id = $this->seed_entry( 'wp_update_post', array( 'id' => self::PRODUCT_ID, 'title' => 'new title' ), 'content' );

		$controller = new Mcpwp_REST_Action_Log();
		$this->force_key_record( $controller, array( 'admin', 'content', 'woocommerce' ) );

		$response = $controller->list_entries( new WP_REST_Request( 'GET', '/mcpwp/v1/action-log', array( 'limit' => 50, 'offset' => 0 ) ) );
		$data     = $response->get_data();
		$ids      = array_column( $data['entries'], 'log_id' );

		$this->assertContains( $hidden_id, $ids );
		$this->assertSame( 1, $data['total'] );
	}

	public function test_list_entries_unfiltered_for_a_caller_with_no_category_context() {
		// wp-admin's own Activity Log / Control Room call list_entries()
		// directly with no 'category_allowed' key — must stay unaffected.
		$this->seed_entry( 'wp_update_post', array( 'id' => self::PRODUCT_ID, 'title' => 'new title' ), 'content' );
		$this->seed_entry( 'wp_update_post', array( 'id' => self::ORDINARY_POST_ID, 'title' => 'new title' ), 'content' );

		$result = Mcpwp_Action_Log::list_entries( array( 'limit' => 50, 'offset' => 0 ) );

		$this->assertSame( 2, $result['total'] );
		$this->assertCount( 2, $result['entries'] );
	}

	// ── Mcpwp_REST_Action_Log::get_entry() / rollback_entry() ───────────────

	public function test_get_entry_denies_a_hidden_entry_as_not_found() {
		$hidden_id = $this->seed_entry( 'wp_update_post', array( 'id' => self::PRODUCT_ID, 'title' => 'new title' ), 'content' );

		$controller = new Mcpwp_REST_Action_Log();
		$this->force_key_record( $controller, array( 'admin', 'content' ) );

		$result = $controller->get_entry( new WP_REST_Request( 'GET', '/mcpwp/v1/action-log/' . $hidden_id, array( 'log_id' => $hidden_id ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_get_entry_allows_a_visible_entry() {
		$visible_id = $this->seed_entry( 'wp_update_post', array( 'id' => self::ORDINARY_POST_ID, 'title' => 'new title' ), 'content' );

		$controller = new Mcpwp_REST_Action_Log();
		$this->force_key_record( $controller, array( 'admin', 'content' ) );

		$result = $controller->get_entry( new WP_REST_Request( 'GET', '/mcpwp/v1/action-log/' . $visible_id, array( 'log_id' => $visible_id ) ) );

		$this->assertInstanceOf( WP_REST_Response::class, $result );
		$this->assertSame( $visible_id, $result->get_data()['log_id'] );
	}

	public function test_rollback_denies_a_hidden_entry_as_not_found() {
		$hidden_id = $this->seed_entry( 'wp_update_post', array( 'id' => self::PRODUCT_ID, 'title' => 'new title' ), 'content' );

		$controller = new Mcpwp_REST_Action_Log();
		$this->force_key_record( $controller, array( 'admin', 'content' ) );

		$result = $controller->rollback_entry( new WP_REST_Request( 'POST', '/mcpwp/v1/action-log/' . $hidden_id . '/rollback', array( 'log_id' => $hidden_id ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
	}

	// ── Door-level proof: a real scoped [admin, content] key over check_permission() ─

	public function test_door_level_admin_content_key_does_not_see_product_activity() {
		$hidden_id  = $this->seed_entry( 'wp_update_post', array( 'id' => self::PRODUCT_ID, 'title' => 'new title' ), 'content' );
		$visible_id = $this->seed_entry( 'wp_update_post', array( 'id' => self::ORDINARY_POST_ID, 'title' => 'new title' ), 'content' );

		$controller = new Mcpwp_REST_Action_Log();
		$request    = $this->real_scoped_key_request( $controller, array( 'admin', 'content' ), 'GET', '/mcpwp/v1/action-log', array( 'limit' => 50, 'offset' => 0 ) );

		$response = $controller->list_entries( $request );
		$ids      = array_column( $response->get_data()['entries'], 'log_id' );

		$this->assertContains( $visible_id, $ids );
		$this->assertNotContains( $hidden_id, $ids, 'a real [admin, content] scoped key must not see the product write-tool entry' );
	}
}
