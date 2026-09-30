<?php
/**
 * #1042 adversarial review, round 5.
 *
 *  W1     a reused post id never makes a pending row confident with the
 *         wrong type: a stored type hint that differs from the live post
 *         means not confident.
 *  W2     a legacy "delete all drafts" record derives ["content"]
 *         confidently, as creation did.
 *  NIT a  a non-post spelling ("widget") in a post-TYPE field is a type,
 *         not a shortcut; the operation names the resource kind.
 *  NIT b  every door (GET, events) decides like list and count: a pending
 *         row is fail-closed everywhere.
 *  NIT c  the re-derivation pass is fair (oldest attempt first), and the
 *         repair union never drops a marker the derivation produced.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-approvals.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-action-log.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-event-store.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-activator.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-approvals.php';

final class ApprovalsTableRound5Test extends TestCase {

	private const REUSED = 4;
	private const TEAM   = 9002;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']           = array();
		$GLOBALS['mcpwp_test_wpdb_options']      = array();
		$GLOBALS['mcpwp_test_filters']           = array();
		$GLOBALS['mcpwp_test_current_user']      = 0;
		$GLOBALS['mcpwp_test_is_admin']          = false;
		$GLOBALS['mcpwp_test_public_post_types'] = array( 'product' );
		$GLOBALS['_mcpwp_test_posts']            = array();
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_public_post_types'] = array();
		$GLOBALS['mcpwp_test_options']           = array();
		$GLOBALS['_mcpwp_test_posts']            = array();
	}

	private static function allow( array $categories ): callable {
		return static function ( $category ) use ( $categories ) {
			return in_array( $category, $categories, true );
		};
	}

	private function visible_via_get( callable $allowed, string $id ): bool {
		$controller          = new class() extends Mcpwp_REST_Approvals {
			public $allowed;
			public function caller_may_read_category( $category ) {
				return (bool) call_user_func( $this->allowed, $category );
			}
			protected function requester_is_unrestricted() {
				return false;
			}
		};
		$controller->allowed = $allowed;
		$resolve             = new ReflectionMethod( Mcpwp_REST_Approvals::class, 'resolve_visible_approval' );
		$resolve->setAccessible( true );
		return ! is_wp_error( $resolve->invoke( $controller, $id ) );
	}

	/** An approval event as the store keeps it: a frozen copy of the record. */
	private function visible_via_event( callable $allowed, string $id, array $frozen_categories ): bool {
		$check = new ReflectionMethod( Mcpwp_Event_Store::class, 'event_visible_to' );
		$check->setAccessible( true );
		$event = array( 'type' => 'approval.created', 'payload' => array( 'approval' => array( 'id' => $id, 'visibility_categories' => $frozen_categories ) ) );
		return (bool) $check->invoke( null, $event, $allowed );
	}

	private function row( string $id ): array {
		return $GLOBALS['mcpwp_test_approvals_rows'][ $id ];
	}

	private function legacy_product_delete( string $id, int $post_id ): array {
		$now = gmdate( 'c' );
		return array(
			'id'                  => $id,
			'status'              => 'pending',
			'action'              => 'destructive_operation',
			'operation'           => 'delete_product',
			'title'               => 'Permanently delete product #' . $post_id,
			'resource'            => array( 'type' => 'product', 'id' => $post_id, 'title' => 'SECRET PRODUCT One' ),
			'payload'             => array( 'route' => '/mcpwp/v1/woocommerce/products/' . $post_id, 'method' => 'DELETE', 'params' => array( 'force' => true ), 'target' => array( 'exists' => true, 'type' => 'product' ) ),
			'requested_by_key_id' => 'k-old',
			'created_at'          => $now,
			'updated_at'          => $now,
		);
	}

	// ── W1 ─────────────────────────────────────────────────────────────────

	public function test_a_reused_post_id_never_resolves_a_pending_row_to_the_wrong_type(): void {
		// Product #4 was deleted: the legacy row is pending.
		update_option( Mcpwp_Approvals::OPTION_NAME, array( 'apr_one' => $this->legacy_product_delete( 'apr_one', self::REUSED ) ), false );
		$this->assertTrue( Mcpwp_Activator::migrate_approvals_to_table() );
		$this->assertSame( 1, (int) $this->row( 'apr_one' )['categories_pending'] );

		// wp_insert_post( [ 'import_id' => 4, 'post_type' => 'page' ] ).
		$GLOBALS['_mcpwp_test_posts'][ self::REUSED ] = new WP_Post( array( 'ID' => self::REUSED, 'post_type' => 'page', 'post_title' => 'A new page', 'post_status' => 'publish' ) );
		Mcpwp_Approvals::rederive_pending();

		$this->assertSame( 1, (int) $this->row( 'apr_one' )['categories_pending'], 'Still pending.' );
		$this->assertArrayNotHasKey( 'visibility_categories', mcpwp_test_approvals_all()['apr_one'] );
		$key = self::allow( array( 'site', 'content', 'admin' ) );
		$this->assertFalse( $this->visible_via_get( $key, 'apr_one' ) );
		$this->assertSame( array(), Mcpwp_Approvals::list_requests( '', 10, true, 'date', $key ) );
		$this->assertSame( array(), Mcpwp_Approvals::count_by_status( $key )['counts'] );
		$this->assertFalse( Mcpwp_Approvals::derive_visibility( $this->legacy_product_delete( 'x', self::REUSED ) )['confident'] );
	}

	// ── W2 ─────────────────────────────────────────────────────────────────

	public function test_a_legacy_delete_all_drafts_record_derives_content_confidently(): void {
		$now    = gmdate( 'c' );
		$drafts = array(
			'id'                  => 'apr_drafts',
			'status'              => 'pending',
			'action'              => 'destructive_operation',
			'operation'           => 'delete_all_drafts',
			'title'               => 'Permanently delete all drafts',
			'resource'            => array( 'type' => 'drafts', 'id' => null, 'title' => 'all drafts', 'post_type' => 'all' ),
			'payload'             => array( 'route' => '/mcpwp/v1/drafts/delete-all', 'method' => 'POST', 'params' => array(), 'target' => array( 'type' => 'drafts', 'count' => 3 ) ),
			'requested_by_key_id' => 'k-old',
			'created_at'          => $now,
			'updated_at'          => $now,
		);
		$derived = Mcpwp_Approvals::derive_visibility( $drafts );
		$this->assertTrue( $derived['confident'] );
		$this->assertSame( array( 'content' ), $derived['categories'] );
		$this->assertSame( $derived['categories'], Mcpwp_Approvals::compute_visibility_categories( 'destructive_operation', $drafts['resource'], $drafts['payload'], 'delete_all_drafts' ), 'The same answer creation computes.' );

		update_option( Mcpwp_Approvals::OPTION_NAME, array( 'apr_drafts' => $drafts ), false );
		$this->assertTrue( Mcpwp_Activator::migrate_approvals_to_table() );
		$key = self::allow( array( 'site', 'content', 'admin' ) );
		$this->assertTrue( $this->visible_via_get( $key, 'apr_drafts' ) );
		$this->assertSame( array( 'apr_drafts' ), array_column( Mcpwp_Approvals::list_requests( '', 10, true, 'date', $key ), 'id' ) );
	}

	// ── NIT a ──────────────────────────────────────────────────────────────

	public function test_a_non_post_spelling_in_a_post_type_field_is_a_type(): void {
		$now      = gmdate( 'c' );
		$post_cpt = array(
			'id'        => 'x',
			'action'    => 'destructive_operation',
			'operation' => 'delete_content',
			'resource'  => array( 'type' => 'widget', 'id' => 555, 'title' => 'A widget CPT post' ),
			'payload'   => array( 'route' => '/mcpwp/v1/content/widget/555', 'method' => 'DELETE', 'target' => array( 'exists' => true, 'type' => 'widget' ) ),
			'created_at' => $now,
		);
		$this->assertFalse( Mcpwp_Approvals::derive_visibility( $post_cpt )['confident'], 'An unregistered CPT named "widget": not confident.' );

		$GLOBALS['mcpwp_test_public_post_types'] = array( 'widget' );
		$GLOBALS['_mcpwp_test_posts'][555]       = new WP_Post( array( 'ID' => 555, 'post_type' => 'widget', 'post_status' => 'publish' ) );
		$this->assertTrue( Mcpwp_Approvals::derive_visibility( $post_cpt )['confident'], 'Registered, it resolves.' );

		// A real widget deletion: the operation names a non-post kind.
		$widget = array(
			'id'        => 'y',
			'action'    => 'destructive_operation',
			'operation' => 'delete_widget',
			'resource'  => array( 'type' => 'widget', 'id' => 'text-3' ),
			'payload'   => array( 'route' => '/mcpwp/v1/widgets/text-3', 'method' => 'DELETE', 'target' => array( 'type' => 'widget' ) ),
		);
		$GLOBALS['mcpwp_test_public_post_types'] = array();
		$this->assertTrue( Mcpwp_Approvals::derive_visibility( $widget )['confident'] );
	}

	// ── NIT b: every door decides like list and count ──────────────────────

	public function test_get_and_events_agree_with_list_and_count_for_pending_rows(): void {
		$GLOBALS['_mcpwp_test_posts'][ self::TEAM ] = new WP_Post( array( 'ID' => self::TEAM, 'post_type' => 'sm_team', 'post_status' => 'publish' ) );
		$GLOBALS['_mcpwp_test_posts'][7]            = new WP_Post( array( 'ID' => 7, 'post_type' => 'post', 'post_status' => 'publish' ) );
		$now = gmdate( 'c' );
		update_option(
			Mcpwp_Approvals::OPTION_NAME,
			array(
				'apr_ok'      => array( 'id' => 'apr_ok', 'status' => 'pending', 'action' => 'post_content_update', 'resource' => array( 'type' => 'post', 'id' => 7 ), 'requested_by_key_id' => 'k', 'created_at' => $now, 'updated_at' => $now ),
				'apr_team'    => array( 'id' => 'apr_team', 'status' => 'pending', 'action' => 'post_content_update', 'resource' => array( 'type' => 'post', 'id' => self::TEAM ), 'requested_by_key_id' => 'k', 'created_at' => $now, 'updated_at' => $now ),
				'apr_one'     => $this->legacy_product_delete( 'apr_one', self::REUSED ),
			),
			false
		);
		$this->assertTrue( Mcpwp_Activator::migrate_approvals_to_table() );
		$this->assertSame( 1, (int) $this->row( 'apr_team' )['categories_pending'] );

		// The environment completes, but the pass has not run yet: the rows
		// are still pending, so every door stays fail-closed for them — even
		// though a live derivation would now say ["content"], and even though
		// the event carries a stale copy that says ["content"].
		$GLOBALS['mcpwp_test_public_post_types'] = array( 'product', 'sm_team' );

		foreach ( array( 'content' => self::allow( array( 'site', 'content', 'admin' ) ), 'shop' => self::allow( array( 'site', 'content', 'admin', 'woocommerce' ) ), 'nothing' => self::allow( array() ) ) as $name => $key ) {
			$by_list = array_column( Mcpwp_Approvals::list_requests( '', 10, true, 'date', $key, false ), 'id' );
			$by_get  = array();
			$by_evt  = array();
			foreach ( array( 'apr_ok', 'apr_team', 'apr_one' ) as $id ) {
				if ( $this->visible_via_get( $key, $id ) ) {
					$by_get[] = $id;
				}
				if ( $this->visible_via_event( $key, $id, array( 'content' ) ) ) {
					$by_evt[] = $id;
				}
			}
			sort( $by_list );
			sort( $by_get );
			sort( $by_evt );
			$this->assertSame( $by_list, $by_get, "$name: get == list" );
			$this->assertSame( $by_list, $by_evt, "$name: events == list" );
			$this->assertSame( count( $by_list ), array_sum( Mcpwp_Approvals::count_by_status( $key )['counts'] ), "$name: count == list" );
		}
		$this->assertFalse( $this->visible_via_event( self::allow( array( 'content' ) ), 'apr_gone_since', array( 'content' ) ), 'A missing row is fail-closed.' );

		// After the pass, all doors move together.
		Mcpwp_Approvals::rederive_pending();
		$content = self::allow( array( 'site', 'content', 'admin' ) );
		$this->assertTrue( $this->visible_via_get( $content, 'apr_team' ) );
		$this->assertTrue( $this->visible_via_event( $content, 'apr_team', array( '__unresolvable__' ) ) );
		$this->assertContains( 'apr_team', array_column( Mcpwp_Approvals::list_requests( '', 10, true, 'date', $content ), 'id' ) );
	}

	// ── NIT c ──────────────────────────────────────────────────────────────

	public function test_the_pass_is_fair_to_rows_behind_ones_that_never_resolve(): void {
		mcpwp_test_approvals_put( array() );
		$now   = gmdate( 'c' );
		$items = array();
		for ( $i = 0; $i < 600; $i++ ) {
			$id = sprintf( 'apr_f%03d', $i );
			if ( $i < 500 ) { // Targets that never come back.
				$items[ $id ] = array( 'id' => $id, 'status' => 'pending', 'action' => 'post_content_update', 'resource' => array( 'type' => 'post', 'id' => 100000 + $i ), 'requested_by_key_id' => 'k', 'created_at' => $now, 'updated_at' => $now );
			} else { // A team member, its plugin off for now.
				$items[ $id ] = array( 'id' => $id, 'status' => 'pending', 'action' => 'post_content_update', 'resource' => array( 'type' => 'post', 'id' => self::TEAM ), 'requested_by_key_id' => 'k', 'created_at' => $now, 'updated_at' => $now );
			}
		}
		$GLOBALS['_mcpwp_test_posts'][ self::TEAM ] = new WP_Post( array( 'ID' => self::TEAM, 'post_type' => 'sm_team', 'post_status' => 'publish' ) );
		Mcpwp_Approvals::import_legacy_items( $items );
		$this->assertSame( 600, count( array_filter( $GLOBALS['mcpwp_test_approvals_rows'], static function ( $r ) {
			return 1 === (int) $r['categories_pending'];
		} ) ) );

		$GLOBALS['mcpwp_test_public_post_types'] = array( 'sm_team' );
		Mcpwp_Approvals::rederive_pending( 500 );
		Mcpwp_Approvals::rederive_pending( 500 );

		$still = array_keys( array_filter( $GLOBALS['mcpwp_test_approvals_rows'], static function ( $r ) {
			return 1 === (int) $r['categories_pending'];
		} ) );
		$this->assertCount( 500, $still, 'Only the never-resolving rows are left pending.' );
		$this->assertSame( 'apr_f499', max( $still ) );
		$this->assertNotNull( $this->row( 'apr_f000' )['rederive_attempted_at'] ?? null, 'Every attempt is stamped.' );
	}

	public function test_the_repair_union_never_drops_a_marker_the_derivation_produced(): void {
		$record = array(
			'id'       => 'x',
			'action'   => 'mystery',
			'resource' => array( 'type' => 'unknown' ),
			'metadata' => array( 'categories_unverified' => array( 'content' ) ),
		);
		$derived = Mcpwp_Approvals::derive_visibility( $record );
		$this->assertTrue( $derived['confident'] );
		$this->assertContains( '__unresolvable__', $derived['categories'] );
		$this->assertContains( 'content', $derived['categories'] );
	}
}
