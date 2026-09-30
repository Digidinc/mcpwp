<?php
/**
 * #1042 adversarial review, round 4 — derivation with confidence.
 *
 * Rounds 3 and 4 found the same class of hole: visibility categories
 * derived in an incomplete environment (before init; an activation request
 * without the REST classes; a post-type plugin inactive), then persisted
 * forever. Now Mcpwp_Approvals::derive_visibility() says whether it is
 * confident, and only a confident answer is ever written into a record.
 * Otherwise the row is fail-closed (visible only to unrestricted callers)
 * and categories_pending = 1 until a later pass is confident.
 *
 * The environment differential test is a permanent guard.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-approvals.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-action-log.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-activator.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-approvals.php';

final class ApprovalsTableRound4Test extends TestCase {

	private const PRODUCT = 8901;
	private const POST    = 8902;
	private const TEAM    = 8903;
	private const MISSING = 8999;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']           = array();
		$GLOBALS['mcpwp_test_wpdb_options']      = array();
		$GLOBALS['mcpwp_test_filters']           = array();
		$GLOBALS['mcpwp_test_current_user']      = 0;
		$GLOBALS['mcpwp_test_is_admin']          = false;
		$GLOBALS['mcpwp_test_public_post_types'] = array( 'product', 'sm_team' );
		$GLOBALS['_mcpwp_test_posts']            = array(
			self::PRODUCT => new WP_Post( array( 'ID' => self::PRODUCT, 'post_type' => 'product', 'post_title' => 'SECRET PRODUCT', 'post_status' => 'publish' ) ),
			self::POST    => new WP_Post( array( 'ID' => self::POST, 'post_type' => 'post', 'post_title' => 'Ordinary', 'post_status' => 'publish' ) ),
			self::TEAM    => new WP_Post( array( 'ID' => self::TEAM, 'post_type' => 'sm_team', 'post_title' => 'Team member', 'post_status' => 'publish' ) ),
		);
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_public_post_types']  = array();
		$GLOBALS['mcpwp_test_options']            = array();
		$GLOBALS['mcpwp_test_filters']            = array();
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
			'resource'            => array( 'type' => $type, 'id' => $post_id, 'title' => 'SECRET ' . $type ),
			'payload'             => array( 'route' => '/mcpwp/v1/posts/' . $post_id, 'method' => 'DELETE', 'params' => array(), 'target' => array( 'exists' => true, 'type' => $type ) ),
			'requested_by_key_id' => 'k-old',
			'created_at'          => $now,
			'updated_at'          => $now,
		);
	}

	private function legacy_content( string $id, int $post_id, string $requester = 'k-content' ): array {
		$now = gmdate( 'c' );
		return array( 'id' => $id, 'status' => 'pending', 'action' => 'post_content_update', 'resource' => array( 'type' => 'post', 'id' => $post_id ), 'payload' => array( 'after_content' => 'x' ), 'requested_by_key_id' => $requester, 'created_at' => $now, 'updated_at' => $now );
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

	private function rest_classes_missing( bool $missing ): void {
		unset( $GLOBALS['mcpwp_test_filters']['mcpwp_approvals_visibility_dependencies_loaded'] );
		if ( $missing ) {
			add_filter(
				'mcpwp_approvals_visibility_dependencies_loaded',
				static function () {
					return false;
				}
			);
		}
	}

	private function row( string $id ): array {
		return $GLOBALS['mcpwp_test_approvals_rows'][ $id ];
	}

	// ── BLOCK: activation ──────────────────────────────────────────────────

	public function test_activation_creates_the_table_but_never_migrates_or_derives(): void {
		update_option( Mcpwp_Approvals::OPTION_NAME, array( 'apr_mine' => $this->legacy_content( 'apr_mine', self::POST ) ), false );

		// An activation request: plugins_loaded ran without MCPWP, so its
		// REST classes are not loaded. activate() creates the tables and
		// must neither migrate nor derive.
		$this->rest_classes_missing( true );
		$source   = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-mcpwp-activator.php' );
		$activate = substr( $source, strpos( $source, 'public static function activate()' ) );
		$activate = substr( $activate, 0, strpos( $activate, "\n\t}\n" ) );
		$this->assertStringNotContainsString( 'migrate_approvals_to_table(', $activate );
		$this->assertStringNotContainsString( 'migrate_identity_less_approvals(', $activate );
		$this->assertStringContainsString( 'self::create_tables();', $activate );
		$create = new ReflectionMethod( Mcpwp_Activator::class, 'create_tables' );
		$create->setAccessible( true );
		$create->invoke( null );

		$this->assertTrue( $GLOBALS['mcpwp_test_approvals_table_exists'], 'The table exists.' );
		$this->assertSame( array(), $GLOBALS['mcpwp_test_approvals_rows'], 'Nothing migrated, nothing derived.' );
		$this->assertFalse( get_option( Mcpwp_Approvals::MIGRATED_FLAG, false ) );
		$this->assertIsArray( get_option( Mcpwp_Approvals::OPTION_NAME ) );

		// The next request's wp_loaded, with everything loaded.
		$this->rest_classes_missing( false );
		$this->assertTrue( Mcpwp_Activator::migrate_approvals_to_table() );
		$this->assertSame( '["content"]', $this->row( 'apr_mine' )['visibility_categories'] );
		$this->assertSame( array( 'apr_mine' ), array_column( Mcpwp_Approvals::list_requests( '', 10, true, 'date', self::allow( array( 'admin', 'content' ) ) ), 'id' ), 'The content key still sees its own proposal, as on 3.14.8.' );
	}

	public function test_nothing_is_persisted_while_the_rest_classes_are_missing(): void {
		mcpwp_test_approvals_put( array() );
		$this->rest_classes_missing( true );
		$this->assertTrue( Mcpwp_Approvals::import_legacy_items( array( 'apr_mine' => $this->legacy_content( 'apr_mine', self::POST ) ) )['records'] > 0 );

		$this->assertArrayNotHasKey( 'visibility_categories', mcpwp_test_approvals_all()['apr_mine'] );
		$this->assertSame( 1, (int) $this->row( 'apr_mine' )['categories_pending'] );
		$this->assertSame( '["__unresolvable__"]', $this->row( 'apr_mine' )['visibility_categories'] );

		$this->rest_classes_missing( false );
		$this->assertSame( 1, Mcpwp_Approvals::rederive_pending() );
		$this->assertSame( array( 'content' ), mcpwp_test_approvals_all()['apr_mine']['visibility_categories'] );
		$this->assertSame( 0, (int) $this->row( 'apr_mine' )['categories_pending'] );
	}

	// ── WARN: a post-type plugin inactive at migration time ────────────────

	public function test_a_mapped_type_resolves_without_its_plugin_and_an_unmapped_one_waits(): void {
		// WooCommerce and the team plugin are both inactive during the upgrade.
		$GLOBALS['mcpwp_test_public_post_types'] = array();
		update_option(
			Mcpwp_Approvals::OPTION_NAME,
			array(
				'apr_product' => $this->legacy_destructive( 'apr_product', self::PRODUCT, 'product' ),
				'apr_team'    => $this->legacy_destructive( 'apr_team', self::TEAM, 'sm_team' ),
			),
			false
		);
		$this->assertTrue( Mcpwp_Activator::migrate_approvals_to_table() );

		$content = self::allow( array( 'admin', 'content' ) );
		$shop    = self::allow( array( 'admin', 'content', 'woocommerce' ) );

		// `product` is in the explicit map: confident without registration.
		$this->assertSame( array( 'content', 'woocommerce' ), mcpwp_test_approvals_all()['apr_product']['visibility_categories'] );
		$this->assertFalse( $this->visible_via_get( $content, 'apr_product' ) );
		$this->assertTrue( $this->visible_via_get( $shop, 'apr_product' ) );

		// `sm_team` is not: pending and fail-closed, hidden from the content key.
		$this->assertSame( 1, (int) $this->row( 'apr_team' )['categories_pending'] );
		$this->assertArrayNotHasKey( 'visibility_categories', mcpwp_test_approvals_all()['apr_team'] );
		$this->assertSame( array(), array_column( Mcpwp_Approvals::list_requests( '', 10, true, 'date', $content ), 'id' ) );
		$this->assertFalse( $this->visible_via_get( $content, 'apr_team' ) );
		$this->assertSame( array( 'apr_product', 'apr_team' ), array_column( Mcpwp_Approvals::list_requests( '', 10, true, 'date' ), 'id' ), 'Unrestricted callers see both.' );

		// The plugins come back: the next pass persists it.
		$GLOBALS['mcpwp_test_public_post_types'] = array( 'product', 'sm_team' );
		$this->assertSame( 1, Mcpwp_Approvals::rederive_pending() );
		$this->assertSame( array( 'content' ), mcpwp_test_approvals_all()['apr_team']['visibility_categories'] );
		$this->assertSame( 'derived', mcpwp_test_approvals_all()['apr_team']['metadata']['categories_source'] );
		$this->assertSame( array( 'apr_team' ), array_column( Mcpwp_Approvals::list_requests( '', 10, true, 'date', $content ), 'id' ) );
		$this->assertTrue( $this->visible_via_get( $content, 'apr_team' ) );
	}

	public function test_explicit_map_resolution_without_registration(): void {
		$GLOBALS['mcpwp_test_public_post_types'] = array();
		$derived = Mcpwp_Approvals::derive_visibility( $this->legacy_destructive( 'x', self::PRODUCT, 'product' ) );
		$this->assertTrue( $derived['confident'] );
		$this->assertSame( array( 'content', 'woocommerce' ), $derived['categories'] );

		$unmapped = Mcpwp_Approvals::derive_visibility( $this->legacy_destructive( 'y', self::TEAM, 'sm_team' ) );
		$this->assertFalse( $unmapped['confident'] );
	}

	public function test_a_missing_target_post_stays_pending_and_fail_closed(): void {
		update_option( Mcpwp_Approvals::OPTION_NAME, array( 'apr_gone' => $this->legacy_destructive( 'apr_gone', self::MISSING, 'post' ) ), false );
		$this->assertTrue( Mcpwp_Activator::migrate_approvals_to_table() );

		$this->assertSame( 1, (int) $this->row( 'apr_gone' )['categories_pending'] );
		$this->assertSame( '["__unresolvable__"]', $this->row( 'apr_gone' )['visibility_categories'] );
		$this->assertSame( 0, Mcpwp_Approvals::rederive_pending() );
		$this->assertFalse( $this->visible_via_get( self::allow( array( 'admin', 'content' ) ), 'apr_gone' ) );
		$this->assertSame( array( 'apr_gone' ), array_column( Mcpwp_Approvals::list_requests( '', 10, true ), 'id' ) );
	}

	// ── Schema 5 repair ────────────────────────────────────────────────────

	public function test_schema_5_repairs_categories_a_candidate_build_persisted(): void {
		// Candidate builds wrote ["__unresolvable__"] during activation, and
		// ["content"] for a product while WooCommerce was off.
		mcpwp_test_approvals_put(
			array(
				'apr_unres'   => $this->legacy_content( 'apr_unres', self::POST ) + array( 'visibility_categories' => array( '__unresolvable__' ) ),
				'apr_product' => $this->legacy_destructive( 'apr_product', self::PRODUCT, 'product' ) + array( 'visibility_categories' => array( 'content' ) ),
				'apr_created' => $this->legacy_content( 'apr_created', self::POST ) + array( 'visibility_categories' => array( 'content', 'gutenberg' ), 'metadata' => array( 'categories_source' => 'create' ) ),
			)
		);
		update_option( Mcpwp_Approvals::SCHEMA_OPTION, 4 );

		$this->assertTrue( Mcpwp_Activator::migrate_approvals_to_table() );
		$all = mcpwp_test_approvals_all();
		$this->assertSame( array( 'content' ), $all['apr_unres']['visibility_categories'] );
		$this->assertSame( array( 'content', 'woocommerce' ), $all['apr_product']['visibility_categories'], 'Unioned with the stored set: never wider.' );
		$this->assertSame( array( 'content', 'gutenberg' ), $all['apr_created']['visibility_categories'], 'Creation-time categories are left alone.' );
		$this->assertFalse( $this->visible_via_get( self::allow( array( 'admin', 'content' ) ), 'apr_product' ) );
		$this->assertTrue( $this->visible_via_get( self::allow( array( 'admin', 'content' ) ), 'apr_unres' ) );
		$this->assertArrayNotHasKey( 'categories_source', Mcpwp_Approvals::get_request( 'apr_unres' )['metadata'], 'Bookkeeping is not in the public record.' );
	}

	// ── The permanent environment differential ─────────────────────────────

	private function matrix(): array {
		$m = array();
		foreach ( array( 'post' => self::POST, 'product' => self::PRODUCT, 'sm_team' => self::TEAM, 'missing' => self::MISSING ) as $type => $post_id ) {
			$real                  = 'missing' === $type ? 'post' : $type;
			$m[ 'apr_d_' . $type ] = $this->legacy_destructive( 'apr_d_' . $type, $post_id, $real );
			$m[ 'apr_c_' . $type ] = $this->legacy_content( 'apr_c_' . $type, $post_id );
		}
		$now                 = gmdate( 'c' );
		$m['apr_b_product']  = array( 'id' => 'apr_b_product', 'status' => 'pending', 'action' => 'bulk_content_publish', 'resource' => array( 'type' => 'bulk_product' ), 'payload' => array( 'post_type' => 'product' ), 'requested_by_key_id' => 'k', 'created_at' => $now, 'updated_at' => $now );
		$m['apr_b_team']     = array( 'id' => 'apr_b_team', 'status' => 'pending', 'action' => 'bulk_content_publish', 'resource' => array( 'type' => 'bulk_sm_team' ), 'payload' => array( 'post_type' => 'sm_team' ), 'requested_by_key_id' => 'k', 'created_at' => $now, 'updated_at' => $now );
		$m['apr_s_push']     = array( 'id' => 'apr_s_push', 'status' => 'approved', 'action' => 'staging_push', 'resource' => array( 'type' => 'staging' ), 'requested_by_key_id' => 'k', 'created_at' => $now, 'updated_at' => $now );
		$m['apr_d_user']     = array( 'id' => 'apr_d_user', 'status' => 'pending', 'action' => 'destructive_operation', 'resource' => array( 'type' => 'user', 'id' => self::PRODUCT ), 'payload' => array( 'route' => '/mcpwp/v1/users/' . self::PRODUCT, 'method' => 'DELETE' ), 'requested_by_key_id' => 'k', 'created_at' => $now, 'updated_at' => $now );
		return $m;
	}

	/**
	 * @return array<string,callable>
	 */
	private function keys(): array {
		return array(
			'everything' => static function () {
				return true;
			},
			'content'    => self::allow( array( 'admin', 'content' ) ),
			'shop'       => self::allow( array( 'admin', 'content', 'woocommerce' ) ),
			'staging'    => self::allow( array( 'admin', 'staging' ) ),
			'nothing'    => self::allow( array() ),
		);
	}

	private function assert_doors_agree( string $env ): void {
		$ids = array_keys( mcpwp_test_approvals_all() );
		foreach ( $this->keys() as $name => $allowed ) {
			$by_get = array();
			foreach ( $ids as $id ) {
				if ( $this->visible_via_get( $allowed, $id ) ) {
					$by_get[] = $id;
				}
			}
			$by_list = array_column( Mcpwp_Approvals::list_requests( '', 100, true, 'date', $allowed, false ), 'id' );
			sort( $by_get );
			sort( $by_list );
			$this->assertSame( $by_get, $by_list, "$env / $name: list == get" );
			$this->assertSame( count( $by_get ), array_sum( Mcpwp_Approvals::count_by_status( $allowed )['counts'] ), "$env / $name: count == get" );
		}
	}

	public function test_every_door_agrees_and_nothing_is_persisted_unconfidently_in_every_environment(): void {
		$legacy = $this->matrix();

		// What a confident derivation in the complete environment gives.
		$truth = array();
		foreach ( $legacy as $id => $record ) {
			$derived = Mcpwp_Approvals::derive_visibility( $record );
			if ( $derived['confident'] ) {
				$truth[ $id ] = $derived['categories'];
			}
		}
		$this->assertArrayNotHasKey( 'apr_d_missing', $truth, 'A missing target is never confident.' );

		foreach ( array( true, false ) as $rest_loaded ) {
			foreach ( array( true, false ) as $plugins_registered ) {
				foreach ( array( true, false ) as $after_init ) {
					$env = sprintf( 'rest=%d plugins=%d init=%d', $rest_loaded, $plugins_registered, $after_init );
					Mcpwp_Test_Approvals_Table::reset();
					$GLOBALS['mcpwp_test_options'] = array();
					update_option( Mcpwp_Approvals::OPTION_NAME, $legacy, false );

					$this->rest_classes_missing( ! $rest_loaded );
					$GLOBALS['mcpwp_test_public_post_types']  = $plugins_registered ? array( 'product', 'sm_team' ) : array();
					$GLOBALS['mcpwp_test_did_action']['init'] = $after_init ? 1 : 0;

					Mcpwp_Activator::migrate_approvals_to_table();
					Mcpwp_Approvals::rederive_pending( 100 );

					// Nothing is ever persisted that a confident, complete
					// derivation would not also give.
					foreach ( mcpwp_test_approvals_all() as $id => $stored ) {
						if ( isset( $stored['visibility_categories'] ) ) {
							$this->assertArrayHasKey( $id, $truth, "$env: $id persisted while it can never be confident" );
							$this->assertSame( $truth[ $id ], $stored['visibility_categories'], "$env: $id persisted categories" );
						} else {
							$this->assertSame( 1, (int) $this->row( $id )['categories_pending'], "$env: $id not persisted, so pending" );
							$this->assertSame( '["__unresolvable__"]', $this->row( $id )['visibility_categories'], "$env: $id fail-closed" );
						}
					}

					if ( ! $after_init ) {
						$this->assertSame( array(), $GLOBALS['mcpwp_test_approvals_rows'], "$env: nothing migrated before init" );
						$GLOBALS['mcpwp_test_did_action']['init'] = 1;
						$this->rest_classes_missing( false );
						$GLOBALS['mcpwp_test_public_post_types'] = array( 'product', 'sm_team' );
						Mcpwp_Activator::migrate_approvals_to_table();
					}
					$this->assert_doors_agree( $env );

					// The complete environment arrives: everything that can be
					// confident is persisted, and the doors still agree.
					$this->rest_classes_missing( false );
					$GLOBALS['mcpwp_test_public_post_types']  = array( 'product', 'sm_team' );
					$GLOBALS['mcpwp_test_did_action']['init'] = 1;
					Mcpwp_Approvals::rederive_pending( 100 );
					foreach ( $truth as $id => $categories ) {
						$this->assertSame( $categories, mcpwp_test_approvals_all()[ $id ]['visibility_categories'], "$env then complete: $id" );
					}
					$this->assert_doors_agree( "$env then complete" );
				}
			}
		}
	}
}
