<?php
/**
 * #1042 adversarial review, round 3.
 *
 *  BLOCK  visibility_categories derived before `init` under-classified a
 *         legacy record whose target is a post type a plugin registers on
 *         init (a WooCommerce product): the light column said ["content"],
 *         the full record said ["content","woocommerce"], so a restricted
 *         key saw it in lists and counts while get() said 404. Now the
 *         derived categories are persisted INTO the record (one source of
 *         truth), nothing is derived before init, and schema 4 re-derives
 *         every row an earlier build wrote.
 *  NIT    the migration takes spai_approval_requests itself instead of
 *         waiting on the whole spai_ bridge.
 *
 * The differential test is a permanent guard: over a matrix of actions ×
 * targets × key category sets, visibility decided from the light columns
 * (list, count) must equal visibility decided from the full record (get),
 * and equal the decision on the original record after init.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-approvals.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-action-log.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-activator.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-approvals.php';

final class ApprovalsTableRound3Test extends TestCase {

	private const PRODUCT   = 8801;
	private const POST      = 8802;
	private const TEMPLATE  = 8803;
	private const SNIPPET   = 8804;
	private const COURSE    = 8805;
	private const MISSING   = 8899;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']           = array();
		$GLOBALS['mcpwp_test_wpdb_options']      = array();
		$GLOBALS['mcpwp_test_filters']           = array();
		$GLOBALS['mcpwp_test_current_user']      = 0;
		$GLOBALS['mcpwp_test_is_admin']          = false;
		$GLOBALS['mcpwp_test_public_post_types'] = array( 'product', 'lp_course' );
		$GLOBALS['_mcpwp_test_posts']            = array(
			self::PRODUCT  => new WP_Post( array( 'ID' => self::PRODUCT, 'post_type' => 'product', 'post_title' => 'SECRET PRODUCT Eta', 'post_status' => 'publish' ) ),
			self::POST     => new WP_Post( array( 'ID' => self::POST, 'post_type' => 'post', 'post_title' => 'Ordinary', 'post_status' => 'publish' ) ),
			self::TEMPLATE => new WP_Post( array( 'ID' => self::TEMPLATE, 'post_type' => 'elementor_library', 'post_title' => 'Arch T SECRET', 'post_status' => 'publish' ) ),
			self::SNIPPET  => new WP_Post( array( 'ID' => self::SNIPPET, 'post_type' => 'elementor_snippet', 'post_title' => 'Snippet', 'post_status' => 'publish' ) ),
			self::COURSE   => new WP_Post( array( 'ID' => self::COURSE, 'post_type' => 'lp_course', 'post_title' => 'Course', 'post_status' => 'publish' ) ),
		);
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_public_post_types'] = array();
		$GLOBALS['mcpwp_test_options']           = array();
		$GLOBALS['mcpwp_test_did_action']['init'] = 1;
	}

	/** A destructive record as ≤3.14.5 stored it: no visibility_categories. */
	private function legacy_destructive( string $id, int $post_id, string $type ): array {
		$now = gmdate( 'c' );
		return array(
			'id'                  => $id,
			'status'              => 'pending',
			'action'              => 'destructive_operation',
			'operation'           => 'delete_post',
			'title'               => 'Delete ' . $type,
			'resource'            => array( 'type' => $type, 'id' => $post_id, 'title' => 'SECRET PRODUCT Eta' ),
			'payload'             => array( 'route' => '/mcpwp/v1/posts/' . $post_id, 'method' => 'DELETE', 'params' => array( 'force' => true ), 'target' => array( 'exists' => true, 'type' => $type ) ),
			'requested_by_key_id' => 'k-old',
			'created_at'          => $now,
			'updated_at'          => $now,
		);
	}

	private function controller( callable $allowed ) {
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
		return $controller;
	}

	private function visible_via_get( callable $allowed, string $id ): bool {
		$resolve = new ReflectionMethod( Mcpwp_REST_Approvals::class, 'resolve_visible_approval' );
		$resolve->setAccessible( true );
		return ! is_wp_error( $resolve->invoke( $this->controller( $allowed ), $id ) );
	}

	/**
	 * The real boot order: plugins_loaded runs before `init`, when a
	 * plugin's own post types (here `product`, `lp_course`) do not exist
	 * yet. Anything the plugin does at that point, it does with them
	 * missing; then init registers them and requests are served.
	 */
	private function boot(): void {
		$registered                               = $GLOBALS['mcpwp_test_public_post_types'];
		$GLOBALS['mcpwp_test_did_action']['init'] = 0;
		$GLOBALS['mcpwp_test_public_post_types']  = array();
		Mcpwp_Activator::migrate_approvals_to_table(); // plugins_loaded-time attempt.
		$GLOBALS['mcpwp_test_did_action']['init'] = 1;
		$GLOBALS['mcpwp_test_public_post_types']  = $registered;
		Mcpwp_Activator::migrate_approvals_to_table(); // wp_loaded.
	}

	private static function allow( array $categories ): callable {
		return static function ( $category ) use ( $categories ) {
			return in_array( $category, $categories, true );
		};
	}

	// ── BLOCK: the repro ───────────────────────────────────────────────────

	public function test_a_legacy_product_deletion_is_hidden_from_a_content_key_everywhere(): void {
		update_option( Mcpwp_Approvals::OPTION_NAME, array( 'apr_eta' => $this->legacy_destructive( 'apr_eta', self::PRODUCT, 'product' ) ), false );
		$this->boot();
		$this->assertSame( 1, get_option( Mcpwp_Approvals::MIGRATED_FLAG ) );

		$content = self::allow( array( 'admin', 'content' ) );
		$this->assertSame( array(), Mcpwp_Approvals::list_requests( '', 50, true, 'date', $content ) );
		$this->assertSame( array(), Mcpwp_Approvals::list_requests( 'pending', 50, true, 'date', $content ) );
		$this->assertSame( array(), Mcpwp_Approvals::list_requests( '', 50, true, 'priority', $content ) );
		$this->assertSame( array(), Mcpwp_Approvals::count_by_status( $content )['counts'] );
		$this->assertFalse( $this->visible_via_get( $content, 'apr_eta' ) );

		// The categories now live in the record itself, and in the column.
		$this->assertSame( array( 'content', 'woocommerce' ), mcpwp_test_approvals_all()['apr_eta']['visibility_categories'] );
		$this->assertSame( '["content","woocommerce"]', $GLOBALS['mcpwp_test_approvals_rows']['apr_eta']['visibility_categories'] );

		// A key that holds woocommerce sees it through every door.
		$shop = self::allow( array( 'admin', 'content', 'woocommerce' ) );
		$this->assertSame( array( 'apr_eta' ), array_column( Mcpwp_Approvals::list_requests( '', 50, true, 'date', $shop ), 'id' ) );
		$this->assertSame( 1, Mcpwp_Approvals::count_by_status( $shop )['counts']['pending'] );
		$this->assertTrue( $this->visible_via_get( $shop, 'apr_eta' ) );
	}

	public function test_schema_4_repairs_a_column_an_earlier_build_derived_before_init(): void {
		// What an earlier candidate build left: the record without stored
		// categories, the column derived before `product` existed.
		mcpwp_test_approvals_put( array( 'apr_eta' => $this->legacy_destructive( 'apr_eta', self::PRODUCT, 'product' ) ) );
		$record = mcpwp_test_approvals_all()['apr_eta'];
		unset( $record['visibility_categories'] );
		$GLOBALS['mcpwp_test_approvals_rows']['apr_eta']['record']                = wp_json_encode( $record );
		$GLOBALS['mcpwp_test_approvals_rows']['apr_eta']['visibility_categories'] = '["content"]';
		update_option( Mcpwp_Approvals::SCHEMA_OPTION, 3 );

		$content = self::allow( array( 'admin', 'content' ) );
		$this->assertSame( array(), Mcpwp_Approvals::list_requests( '', 50, true, 'date', $content ), 'The first read runs schema 4 first.' );
		$this->assertSame( Mcpwp_Approvals::SCHEMA_VERSION, (int) get_option( Mcpwp_Approvals::SCHEMA_OPTION ) );
		$this->assertSame( '["content","woocommerce"]', $GLOBALS['mcpwp_test_approvals_rows']['apr_eta']['visibility_categories'] );
		$this->assertSame( array( 'content', 'woocommerce' ), mcpwp_test_approvals_all()['apr_eta']['visibility_categories'] );
		$this->assertSame( array(), Mcpwp_Approvals::count_by_status( $content )['counts'] );
	}

	public function test_nothing_is_derived_before_init(): void {
		$GLOBALS['mcpwp_test_did_action']['init'] = 0;
		$GLOBALS['mcpwp_test_public_post_types']  = array(); // `product` is registered on init.
		update_option( Mcpwp_Approvals::OPTION_NAME, array( 'apr_eta' => $this->legacy_destructive( 'apr_eta', self::PRODUCT, 'product' ) ), false );

		$early = Mcpwp_Activator::migrate_approvals_to_table();
		$this->assertInstanceOf( WP_Error::class, $early );
		$this->assertSame( 'approvals_busy', $early->get_error_code() );
		$this->assertSame( array(), $GLOBALS['mcpwp_test_approvals_rows'], 'No row, no column.' );
		$this->assertFalse( get_option( Mcpwp_Approvals::MIGRATED_FLAG, false ) );
		$this->assertIsArray( get_option( Mcpwp_Approvals::OPTION_NAME ) );
		$this->assertSame( 'approvals_busy', Mcpwp_Approvals::backfill_derived_columns()->get_error_code() );

		// After init, with the type registered, it migrates correctly.
		$GLOBALS['mcpwp_test_did_action']['init'] = 1;
		$GLOBALS['mcpwp_test_public_post_types']  = array( 'product' );
		$this->assertTrue( Mcpwp_Activator::migrate_approvals_to_table() );
		$this->assertSame( '["content","woocommerce"]', $GLOBALS['mcpwp_test_approvals_rows']['apr_eta']['visibility_categories'] );
	}

	public function test_the_hooks_run_on_wp_loaded_not_plugins_loaded(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/mcpwp.php' );
		$at     = strpos( $source, 'Mcpwp_Activator::migrate_approvals_to_table();' );
		$this->assertNotFalse( $at );
		$hook = strrpos( substr( $source, 0, $at ), "add_action(\n\t\t'wp_loaded'," );
		$this->assertNotFalse( $hook, 'The migration is registered on wp_loaded.' );
		$this->assertLessThan( 1500, $at - $hook, 'Inside that wp_loaded callback.' );
	}

	// ── The permanent differential guard ───────────────────────────────────

	/**
	 * Legacy records (no stored categories) over actions × targets.
	 */
	private function matrix(): array {
		$now = gmdate( 'c' );
		$m   = array();
		foreach ( array( 'post' => self::POST, 'product' => self::PRODUCT, 'elementor_library' => self::TEMPLATE, 'elementor_snippet' => self::SNIPPET, 'lp_course' => self::COURSE, 'missing' => self::MISSING ) as $type => $post_id ) {
			$real = 'missing' === $type ? 'product' : $type;
			$m[ 'apr_d_' . $type ] = $this->legacy_destructive( 'apr_d_' . $type, $post_id, $real );
			$m[ 'apr_c_' . $type ] = array( 'id' => 'apr_c_' . $type, 'status' => 'pending', 'action' => 'post_content_update', 'resource' => array( 'type' => 'post', 'id' => $post_id ), 'requested_by_key_id' => 'k', 'created_at' => $now, 'updated_at' => $now );
		}
		foreach ( array( 'post', 'page', 'product' ) as $type ) {
			$m[ 'apr_b_' . $type ] = array( 'id' => 'apr_b_' . $type, 'status' => 'pending', 'action' => 'bulk_content_publish', 'resource' => array( 'type' => 'bulk_' . $type, 'id' => null ), 'payload' => array( 'post_type' => $type ), 'requested_by_key_id' => 'k', 'created_at' => $now, 'updated_at' => $now );
		}
		$m['apr_s_push']   = array( 'id' => 'apr_s_push', 'status' => 'approved', 'action' => 'staging_push', 'resource' => array( 'type' => 'staging' ), 'requested_by_key_id' => 'k', 'created_at' => $now, 'updated_at' => $now );
		$m['apr_d_user']   = array( 'id' => 'apr_d_user', 'status' => 'pending', 'action' => 'destructive_operation', 'resource' => array( 'type' => 'user', 'id' => self::PRODUCT ), 'payload' => array( 'route' => '/mcpwp/v1/users/' . self::PRODUCT, 'method' => 'DELETE' ), 'requested_by_key_id' => 'k', 'created_at' => $now, 'updated_at' => $now );
		$m['apr_u_nothing'] = array( 'id' => 'apr_u_nothing', 'status' => 'rejected', 'action' => 'mystery', 'resource' => array( 'type' => 'unknown' ), 'requested_by_key_id' => 'k', 'created_at' => $now, 'updated_at' => $now );
		return $m;
	}

	public function test_light_column_visibility_equals_full_record_visibility(): void {
		$legacy = $this->matrix();
		update_option( Mcpwp_Approvals::OPTION_NAME, $legacy, false );
		$this->boot();
		$this->assertSame( 1, get_option( Mcpwp_Approvals::MIGRATED_FLAG ) );

		$keys = array(
			'everything'   => static function () {
				return true;
			},
			'admin'        => self::allow( array( 'admin' ) ),
			'content'      => self::allow( array( 'admin', 'content' ) ),
			'shop'         => self::allow( array( 'admin', 'content', 'woocommerce' ) ),
			'templates'    => self::allow( array( 'admin', 'content', 'elementor-templates' ) ),
			'theme'        => self::allow( array( 'admin', 'elementor-theme' ) ),
			'courses'      => self::allow( array( 'admin', 'content', 'learnpress' ) ),
			'staging'      => self::allow( array( 'admin', 'staging' ) ),
			'nothing'      => self::allow( array() ),
		);

		foreach ( $keys as $name => $allowed ) {
			$by_original = array();
			$by_full     = array();
			$by_get      = array();
			foreach ( $legacy as $id => $original ) {
				// The decision on the original record after init: its confident
				// derivation, or fail-closed (round 4-5: nothing is derived live
				// at read time).
				$derived = Mcpwp_Approvals::derive_visibility( $original );
				$cats    = $derived['confident'] ? $derived['categories'] : array( Mcpwp_Approvals::UNRESOLVABLE_CATEGORY );
				if ( Mcpwp_Approvals::is_approval_visible_to( array( 'visibility_categories' => $cats ), $allowed ) ) {
					$by_original[] = $id;
				}
				$stored = mcpwp_test_approvals_all()[ $id ];
				if ( Mcpwp_Approvals::is_approval_visible_to( $stored, $allowed ) ) {
					$by_full[] = $id;
				}
				if ( $this->visible_via_get( $allowed, $id ) ) {
					$by_get[] = $id;
				}
			}
			$by_list = array_column( Mcpwp_Approvals::list_requests( '', 100, true, 'date', $allowed, false ), 'id' );
			sort( $by_original );
			sort( $by_full );
			sort( $by_get );
			sort( $by_list );

			$this->assertSame( $by_original, $by_full, "$name: persisting the categories changed nobody's view" );
			$this->assertSame( $by_full, $by_get, "$name: get == full record" );
			$this->assertSame( $by_full, $by_list, "$name: list (light columns) == full record" );
			$this->assertSame( count( $by_full ), array_sum( Mcpwp_Approvals::count_by_status( $allowed )['counts'] ), "$name: count (light columns) == full record" );
		}
	}

	// ── NIT: the spai queue, without the whole bridge ──────────────────────

	public function test_a_failed_bridge_never_leaves_approvals_busy(): void {
		// The bridge stopped on an unrelated table: its flag is unset and it
		// never copied the approvals option.
		update_option( 'spai_settings', array( 'enable_logging' => true ) );
		update_option( 'spai_approval_requests', array( 'apr_spai' => $this->legacy_destructive( 'apr_spai', self::POST, 'post' ) ), false );
		$this->assertFalse( get_option( 'mcpwp_migrated_from_spai', false ) );

		$listed = Mcpwp_Approvals::list_requests( '', 10, true );
		$this->assertIsArray( $listed, 'Not approvals_busy.' );
		$this->assertSame( array( 'apr_spai' ), array_column( $listed, 'id' ) );
		$this->assertSame( 1, get_option( Mcpwp_Approvals::MIGRATED_FLAG ) );
	}
}
