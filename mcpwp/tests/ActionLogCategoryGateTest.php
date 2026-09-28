<?php
/**
 * Regression tests — #1031.
 *
 * ROUND 1: /action-log is gated only the blanket 'admin' category
 * (get_route_category_map()), so a custom key holding 'admin' but not, say,
 * 'woocommerce' could read every OTHER key's write-tool activity there — the
 * same class of leak #1025 closed for /approvals and /events, at lower
 * detail (no titles/arguments, but tool_name/category/resource_type/
 * resource_id already confirm a resource exists and is being worked on).
 * Fixed by filtering on the tool's own category AND, when resolvable, the
 * resource's real post-type category.
 *
 * ROUND 2 (adversarial review of the round-1 PR): BLOCKED.
 *
 * B1 (fatal) — round 1 resolved the post-type category LIVE, at read time,
 * via a bounded PHP-side SELECT * ... LIMIT 5000 scan-then-filter. That
 * loads before_snap/after_snap (full post_content/_elementor_data — one
 * wp_set_elementor row alone was 1.5MB) for up to 5000 rows on every
 * /action-log read by every category-restricted key, which is a PHP memory
 * fatal at realistic table sizes and an amplification any write key can
 * trigger just by writing more rows.
 *
 * W1 — the same scan cap gave a WRONG total/pagination past 5000 matching
 * rows (a silent undercount, and a page boundary that could hide or
 * duplicate rows), which is itself a count oracle.
 *
 * W2 — live resolution meant a hard-deleted post's own delete/edit entry
 * became unclassifiable and, under round 1's fail-closed default, invisible
 * to EVERY category-restricted key, including one whose role legitimately
 * covers that resource.
 *
 * W3 — a custom key with, say, ['elementor'] but not the category of a
 * PRODUCT PAGE it just saved through wp_set_elementor could not see its own
 * just-completed action in the log at all.
 *
 * W4 — the round-1 fix reused caller_may_read_category(), which also
 * applies the site-wide `mcpwp_disabled_tool_categories` toggle — so
 * disabling a category hid its OWN PAST HISTORY from an unrestricted key,
 * not just future calls.
 *
 * FIX (round 2 DESIGN): resource_post_type is now captured at WRITE time
 * (begin(), before the tool runs, so a delete's target survives its own
 * deletion) and stored as a real column. list_entries()/export_csv() build
 * their WHERE entirely in SQL from the caller's role categories (never the
 * site-wide toggle) and the post types those categories cover, so COUNT(*)
 * and the LIMIT/OFFSET page share one predicate with an exact total, and
 * before_snap/after_snap are never selected by these two doors at all
 * (LIST_COLUMNS). get_entry_public()/rollback() apply the identical rule
 * PHP-side to one already-fetched row. An entry the caller's OWN key wrote
 * is always visible to it. A before/after snapshot over 512KB is withheld
 * with a clear marker instead of stored.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-action-log.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-action-log.php';

final class ActionLogCategoryGateTest extends TestCase {

	private const PRODUCT_ID       = 9201;
	private const ORDINARY_POST_ID = 9202;

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

		// A test-scoped interceptor (tests/bootstrap.php's own
		// Mcpwp_Test_WPDB) answering only mcpwp_action_log queries against
		// the row list $wpdb->insert() already captures generically. It
		// parses the FULLY-SUBSTITUTED query text (the real prepare()
		// double embeds bound values as literal SQL, not %s placeholders),
		// which is enough to evaluate the exact predicates list_entries()/
		// build_visibility_sql() generate without a real SQL engine.
		$GLOBALS['mcpwp_test_wpdb_intercept'] = static function ( $method, $query ) {
			if ( false === strpos( $query, 'mcpwp_action_log' ) ) {
				return MCPWP_TEST_WPDB_PASS;
			}

			$defaults = array(
				'log_id' => '', 'timestamp' => '', 'tool_name' => '', 'category' => '',
				'api_key_id' => '', 'arguments' => '', 'before_snap' => null, 'after_snap' => null,
				'resource_type' => '', 'resource_id' => '', 'resource_post_type' => '',
				'snapshot_skipped' => '', 'duration_ms' => 0, 'success' => 0, 'error_code' => '',
				'rollback_supported' => 0, 'rolled_back' => 0, 'rolled_back_at' => null,
			);
			$rows = array_map(
				function ( $row ) use ( $defaults ) {
					return array_merge( $defaults, $row );
				},
				$GLOBALS['mcpwp_test_wpdb_rows']['wp_mcpwp_action_log'] ?? array()
			);

			if ( 'get_row' === $method ) {
				if ( preg_match( "/log_id = '((?:[^'\\\\]|\\\\.)*)'/", $query, $m ) ) {
					$log_id = str_replace( "\\'", "'", $m[1] );
					foreach ( $rows as $row ) {
						if ( $log_id === $row['log_id'] ) {
							return $row;
						}
					}
				}
				return null;
			}

			// Plain scalar WHERE filters (tool_name/success/resource_type/resource_id).
			if ( preg_match( "/[^_]tool_name = '((?:[^'\\\\]|\\\\.)*)'/", $query, $m ) ) {
				$v    = str_replace( "\\'", "'", $m[1] );
				$rows = array_values( array_filter( $rows, fn( $r ) => $r['tool_name'] === $v ) );
			}
			if ( preg_match( '/[^_]success = (\d+)/', $query, $m ) ) {
				$v    = (int) $m[1];
				$rows = array_values( array_filter( $rows, fn( $r ) => (int) $r['success'] === $v ) );
			}
			if ( preg_match( "/[^_]resource_type = '((?:[^'\\\\]|\\\\.)*)'/", $query, $m ) ) {
				$v    = str_replace( "\\'", "'", $m[1] );
				$rows = array_values( array_filter( $rows, fn( $r ) => $r['resource_type'] === $v ) );
			}
			if ( preg_match( "/[^_]resource_id = '((?:[^'\\\\]|\\\\.)*)'/", $query, $m ) ) {
				$v    = str_replace( "\\'", "'", $m[1] );
				$rows = array_values( array_filter( $rows, fn( $r ) => $r['resource_id'] === $v ) );
			}

			// The visibility predicate: (category IN (...) AND
			// (resource_post_type = '' OR resource_post_type IN (...))) [OR api_key_id = '...']
			if ( preg_match( '/category IN \(([^)]*)\)/', $query, $cat_m ) ) {
				$categories = array_map(
					fn( $s ) => trim( trim( $s ), "'" ),
					explode( ',', $cat_m[1] )
				);
				$post_types = array();
				if ( preg_match( '/resource_post_type IN \(([^)]*)\)/', $query, $pt_m ) ) {
					$post_types = array_map( fn( $s ) => trim( trim( $s ), "'" ), explode( ',', $pt_m[1] ) );
				}
				$own_key = '';
				if ( preg_match( "/OR api_key_id = '((?:[^'\\\\]|\\\\.)*)'/", $query, $key_m ) ) {
					$own_key = str_replace( "\\'", "'", $key_m[1] );
				}
				$rows = array_values(
					array_filter(
						$rows,
						function ( $r ) use ( $categories, $post_types, $own_key ) {
							if ( '' !== $own_key && $r['api_key_id'] === $own_key ) {
								return true;
							}
							// N1: '' is never a member of $categories in practice, so this
							// already agrees with production without a special case.
							if ( ! in_array( $r['category'], $categories, true ) ) {
								return false;
							}
							// N2: the post-type gate only applies to a row that IDENTIFIES
							// a real post/elementor resource; anything else (option/other,
							// or an unresolved id of '0') skips it, matching
							// is_entry_visible_to()'s own $identified_post check.
							$identified = in_array( $r['resource_type'], array( 'post', 'elementor' ), true ) && (int) $r['resource_id'] > 0;
							if ( ! $identified ) {
								return true;
							}
							if ( '' === $r['resource_post_type'] ) {
								return false; // Identified but unresolved — fail closed.
							}
							return in_array( $r['resource_post_type'], $post_types, true );
						}
					)
				);
			}

			usort( $rows, fn( $a, $b ) => strcmp( (string) $b['timestamp'], (string) $a['timestamp'] ) );

			if ( 'get_var' === $method ) {
				return (string) count( $rows );
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
		update_option( 'mcpwp_disabled_tool_categories', array() );
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
	 * a hand-built row), so resource detection, resource_post_type capture
	 * and the rollback_supported flag are exactly what production writes.
	 */
	private function seed_entry( string $tool_name, array $arguments, string $category, string $api_key_id = 'writer-key', bool $success = true ): string {
		$log_id = Mcpwp_Action_Log::begin( $tool_name, $arguments, $api_key_id, $category );
		$this->assertNotNull( $log_id, 'premise: ' . $tool_name . ' must be recognised as a write tool' );
		Mcpwp_Action_Log::complete( $log_id, 5, $success, $success ? '' : 'execution_error' );
		return $log_id;
	}

	/**
	 * Blank a seeded row's resource_post_type back to '', simulating a row
	 * written before the #1031 round-2 column existed and not yet visited
	 * by the backfill migration.
	 */
	private function simulate_not_yet_backfilled( string $log_id ): void {
		foreach ( $GLOBALS['mcpwp_test_wpdb_rows']['wp_mcpwp_action_log'] as &$row ) {
			if ( $log_id === $row['log_id'] ) {
				$row['resource_post_type'] = '';
			}
		}
		unset( $row );
	}

	/** Force a row's stored timestamp, for deterministic DESC-order/offset assertions. */
	private function set_row_timestamp( string $log_id, string $timestamp ): void {
		foreach ( $GLOBALS['mcpwp_test_wpdb_rows']['wp_mcpwp_action_log'] as &$row ) {
			if ( $log_id === $row['log_id'] ) {
				$row['timestamp'] = $timestamp;
			}
		}
		unset( $row );
	}

	// ── Mcpwp_Action_Log::is_entry_visible_to(): the chokepoint itself ──────

	public function test_tool_category_alone_hides_an_entry(): void {
		$entry      = array( 'category' => 'woocommerce', 'resource_type' => 'other', 'resource_id' => '', 'resource_post_type' => '', 'api_key_id' => 'w' );
		$visibility = array( 'categories' => array( 'content', 'admin' ), 'post_types' => array(), 'caller_key_id' => 'reader' );

		$this->assertFalse( Mcpwp_Action_Log::is_entry_visible_to( $entry, $visibility ) );
	}

	public function test_generic_tool_category_does_not_reveal_a_product_entry_on_its_own(): void {
		// #1031's own PoC: the TOOL's category ('content') is allowed, but
		// the entry names a product — the additive post-type check must
		// still deny it.
		$entry      = array( 'category' => 'content', 'resource_type' => 'post', 'resource_id' => '10', 'resource_post_type' => 'product', 'api_key_id' => 'w' );
		$visibility = array( 'categories' => array( 'content', 'admin' ), 'post_types' => array( 'post', 'page' ), 'caller_key_id' => 'reader' );

		$this->assertFalse( Mcpwp_Action_Log::is_entry_visible_to( $entry, $visibility ) );
	}

	public function test_entry_visible_once_key_holds_both_tool_and_post_type_category(): void {
		$entry      = array( 'category' => 'content', 'resource_type' => 'post', 'resource_id' => '10', 'resource_post_type' => 'product', 'api_key_id' => 'w' );
		$visibility = array( 'categories' => array( 'content', 'woocommerce' ), 'post_types' => array( 'product' ), 'caller_key_id' => 'reader' );

		$this->assertTrue( Mcpwp_Action_Log::is_entry_visible_to( $entry, $visibility ) );
	}

	public function test_ordinary_post_entry_requires_only_the_tool_category(): void {
		$entry      = array( 'category' => 'content', 'resource_type' => 'post', 'resource_id' => '11', 'resource_post_type' => 'post', 'api_key_id' => 'w' );
		$visibility = array( 'categories' => array( 'content' ), 'post_types' => array( 'post', 'page' ), 'caller_key_id' => 'reader' );

		$this->assertTrue( Mcpwp_Action_Log::is_entry_visible_to( $entry, $visibility ) );
	}

	public function test_empty_resource_post_type_skips_the_post_type_check_for_a_non_post_resource(): void {
		// 'option'/'other' entries never name a post at all — the post-type
		// gate must not apply to them regardless of resource_post_type.
		$entry      = array( 'category' => 'admin', 'resource_type' => 'option', 'resource_id' => 'mcpwp_settings', 'resource_post_type' => '', 'api_key_id' => 'w' );
		$visibility = array( 'categories' => array( 'admin' ), 'post_types' => array(), 'caller_key_id' => 'reader' );

		$this->assertTrue( Mcpwp_Action_Log::is_entry_visible_to( $entry, $visibility ) );
	}

	public function test_own_entry_is_visible_even_when_its_category_is_not_held(): void {
		// W3: a key must always see an entry IT wrote, even one whose tool
		// or resource category its own role does not otherwise cover.
		$entry      = array( 'category' => 'woocommerce', 'resource_type' => 'post', 'resource_id' => '10', 'resource_post_type' => 'product', 'api_key_id' => 'writer-key' );
		$visibility = array( 'categories' => array( 'content' ), 'post_types' => array( 'post' ), 'caller_key_id' => 'writer-key' );

		$this->assertTrue( Mcpwp_Action_Log::is_entry_visible_to( $entry, $visibility ) );
	}

	// ── N1/N2 (round-3 adversarial review): PHP must fail closed the same way SQL does ─

	public function test_n1_empty_tool_category_is_not_a_free_pass_for_a_restricted_key(): void {
		// N1: build_visibility_sql()'s `category IN (...)` never matches an
		// empty string either — is_entry_visible_to() must agree, not treat
		// '' as "skip the check".
		$entry      = array( 'category' => '', 'resource_type' => 'other', 'resource_id' => '', 'resource_post_type' => '', 'api_key_id' => 'w' );
		$visibility = array( 'categories' => array( 'content', 'admin' ), 'post_types' => array(), 'caller_key_id' => 'reader' );

		$this->assertFalse( Mcpwp_Action_Log::is_entry_visible_to( $entry, $visibility ) );
	}

	public function test_n1_empty_tool_category_is_visible_only_when_unrestricted(): void {
		$entry = array( 'category' => '', 'resource_type' => 'other', 'resource_id' => '', 'resource_post_type' => '', 'api_key_id' => 'w' );

		$this->assertTrue( Mcpwp_Action_Log::is_entry_visible_to( $entry, array( 'categories' => null, 'post_types' => array(), 'caller_key_id' => '' ) ) );
	}

	public function test_n2_identified_post_with_unresolved_post_type_is_hidden_for_a_restricted_key(): void {
		// N2: resource_id > 0 identifies a real post/elementor resource;
		// resource_post_type '' there means unresolved/not-yet-backfilled,
		// not "no post" — must fail closed, not get a free pass.
		$entry      = array( 'category' => 'content', 'resource_type' => 'post', 'resource_id' => '10', 'resource_post_type' => '', 'api_key_id' => 'w' );
		$visibility = array( 'categories' => array( 'content', 'admin' ), 'post_types' => array( 'post', 'page' ), 'caller_key_id' => 'reader' );

		$this->assertFalse( Mcpwp_Action_Log::is_entry_visible_to( $entry, $visibility ) );
	}

	public function test_n2_identified_post_with_unresolved_post_type_is_visible_only_when_unrestricted(): void {
		$entry = array( 'category' => 'content', 'resource_type' => 'post', 'resource_id' => '10', 'resource_post_type' => '', 'api_key_id' => 'w' );

		$this->assertTrue( Mcpwp_Action_Log::is_entry_visible_to( $entry, array( 'categories' => null, 'post_types' => array(), 'caller_key_id' => '' ) ) );
	}

	public function test_n2_unresolved_id_zero_still_skips_the_post_type_gate(): void {
		// An unresolved create (detect_resource() never captured a real id)
		// stores resource_id '0' — not "identified", so N2 does not apply;
		// this is unchanged, tool-category-only, behavior.
		$entry      = array( 'category' => 'content', 'resource_type' => 'post', 'resource_id' => '0', 'resource_post_type' => '', 'api_key_id' => 'w' );
		$visibility = array( 'categories' => array( 'content' ), 'post_types' => array(), 'caller_key_id' => 'reader' );

		$this->assertTrue( Mcpwp_Action_Log::is_entry_visible_to( $entry, $visibility ) );
	}

	// ── Mcpwp_REST_Action_Log::list_entries() ───────────────────────────────

	public function test_list_entries_hides_a_product_entry_from_an_admin_content_key(): void {
		$hidden_id  = $this->seed_entry( 'wp_update_post', array( 'id' => self::PRODUCT_ID, 'title' => 'new title' ), 'content' );
		$visible_id = $this->seed_entry( 'wp_update_post', array( 'id' => self::ORDINARY_POST_ID, 'title' => 'new title' ), 'content' );

		$controller = new Mcpwp_REST_Action_Log();
		$this->force_key_record( $controller, array( 'admin', 'content' ) );

		$response = $controller->list_entries( new WP_REST_Request( 'GET', '/mcpwp/v1/action-log', array( 'limit' => 50, 'offset' => 0 ) ) );
		$data     = $response->get_data();
		$ids      = array_column( $data['entries'], 'log_id' );

		$this->assertContains( $visible_id, $ids );
		$this->assertNotContains( $hidden_id, $ids, 'a [admin, content] key must not see the product write-tool entry' );
		$this->assertSame( 1, $data['total'], 'total must be exact (SQL-computed), not a raw row count' );
	}

	public function test_list_entries_shows_it_once_the_key_also_holds_woocommerce(): void {
		$hidden_id = $this->seed_entry( 'wp_update_post', array( 'id' => self::PRODUCT_ID, 'title' => 'new title' ), 'content' );

		$controller = new Mcpwp_REST_Action_Log();
		$this->force_key_record( $controller, array( 'admin', 'content', 'woocommerce' ) );

		$response = $controller->list_entries( new WP_REST_Request( 'GET', '/mcpwp/v1/action-log', array( 'limit' => 50, 'offset' => 0 ) ) );
		$data     = $response->get_data();
		$ids      = array_column( $data['entries'], 'log_id' );

		$this->assertContains( $hidden_id, $ids );
		$this->assertSame( 1, $data['total'] );
	}

	public function test_list_entries_unfiltered_for_a_caller_with_no_category_context(): void {
		// wp-admin's own Activity Log / Control Room call list_entries()
		// directly with no 'visibility' key — must stay unaffected.
		$this->seed_entry( 'wp_update_post', array( 'id' => self::PRODUCT_ID, 'title' => 'new title' ), 'content' );
		$this->seed_entry( 'wp_update_post', array( 'id' => self::ORDINARY_POST_ID, 'title' => 'new title' ), 'content' );

		$result = Mcpwp_Action_Log::list_entries( array( 'limit' => 50, 'offset' => 0 ) );

		$this->assertSame( 2, $result['total'] );
		$this->assertCount( 2, $result['entries'] );
	}

	public function test_exact_total_and_offset_paging_for_a_restricted_key(): void {
		// 3 visible ('content'), 2 hidden ('woocommerce', product) — a
		// [content]-only key must see an EXACT total of 3 and page through
		// exactly those 3, in the right order, never displaced by a hidden row.
		$ids = array();
		foreach ( range( 1, 3 ) as $i ) {
			$ids[] = $this->seed_entry( 'wp_update_post', array( 'id' => self::ORDINARY_POST_ID, 'title' => "v$i" ), 'content' );
		}
		$this->seed_entry( 'wp_update_post', array( 'id' => self::PRODUCT_ID, 'title' => 'hidden-1' ), 'content' );
		$this->seed_entry( 'wp_delete_post', array( 'id' => self::PRODUCT_ID ), 'content' );

		// Deterministic, strictly increasing timestamps: $ids[0] oldest.
		foreach ( $ids as $i => $log_id ) {
			$this->set_row_timestamp( $log_id, sprintf( '2026-01-01 00:00:%02d', $i + 1 ) );
		}

		$controller = new Mcpwp_REST_Action_Log();
		$this->force_key_record( $controller, array( 'admin', 'content' ) );

		$page1 = $controller->list_entries( new WP_REST_Request( 'GET', '/mcpwp/v1/action-log', array( 'limit' => 2, 'offset' => 0 ) ) )->get_data();
		$page2 = $controller->list_entries( new WP_REST_Request( 'GET', '/mcpwp/v1/action-log', array( 'limit' => 2, 'offset' => 2 ) ) )->get_data();

		$this->assertSame( 3, $page1['total'] );
		$this->assertSame( 3, $page2['total'] );
		// Newest-first: ids[2], ids[1] on page 1; ids[0] alone on page 2.
		$this->assertSame( array( $ids[2], $ids[1] ), array_column( $page1['entries'], 'log_id' ) );
		$this->assertSame( array( $ids[0] ), array_column( $page2['entries'], 'log_id' ) );
	}

	public function test_site_wide_disabled_category_does_not_hide_history_from_an_unrestricted_key(): void {
		// W4: the audit log applies ROLE categories only. Disabling
		// 'content' site-wide must not erase an unrestricted admin's own
		// view of content history that already happened.
		update_option( 'mcpwp_disabled_tool_categories', array( 'content' ) );
		$visible_id = $this->seed_entry( 'wp_update_post', array( 'id' => self::ORDINARY_POST_ID, 'title' => 'x' ), 'content' );

		$controller = new Mcpwp_REST_Action_Log();
		$this->force_key_record( $controller, array(), 'unrestricted-admin', 'admin' );

		$response = $controller->list_entries( new WP_REST_Request( 'GET', '/mcpwp/v1/action-log', array( 'limit' => 50, 'offset' => 0 ) ) );
		$ids      = array_column( $response->get_data()['entries'], 'log_id' );

		$this->assertContains( $visible_id, $ids );
	}

	public function test_own_entry_visible_in_the_list_even_without_the_category(): void {
		// W3 at the door level: a [elementor]-only key that just saved a
		// product page via wp_set_elementor must see its OWN entry, even
		// though it holds neither 'content' (wp_update_post's own category,
		// unrelated here) nor 'woocommerce' (the product's).
		$own_id = $this->seed_entry( 'wp_set_elementor', array( 'id' => self::PRODUCT_ID ), 'elementor', 'own-key' );

		$controller = new Mcpwp_REST_Action_Log();
		$this->force_key_record( $controller, array( 'elementor' ), 'own-key' );

		$response = $controller->list_entries( new WP_REST_Request( 'GET', '/mcpwp/v1/action-log', array( 'limit' => 50, 'offset' => 0 ) ) );
		$ids      = array_column( $response->get_data()['entries'], 'log_id' );

		$this->assertContains( $own_id, $ids );
	}

	public function test_deleted_post_entry_stays_visible_to_a_category_holder(): void {
		// W2: resource_post_type is captured at WRITE time (while the post
		// still exists), not re-resolved live — so a hard-deleted target's
		// own entry is still correctly classified, not lost to
		// "unresolvable" the way round 1's live resolution made it.
		$log_id = $this->seed_entry( 'wp_delete_post', array( 'id' => self::PRODUCT_ID ), 'content' );
		unset( $GLOBALS['_mcpwp_test_posts'][ self::PRODUCT_ID ] ); // Simulate the delete actually happening.

		$holder = new Mcpwp_REST_Action_Log();
		$this->force_key_record( $holder, array( 'admin', 'content', 'woocommerce' ) );
		$visible_ids = array_column(
			$holder->list_entries( new WP_REST_Request( 'GET', '/mcpwp/v1/action-log', array( 'limit' => 50, 'offset' => 0 ) ) )->get_data()['entries'],
			'log_id'
		);
		$this->assertContains( $log_id, $visible_ids, 'a key holding woocommerce must still see the deleted product\'s own entry' );

		$non_holder = new Mcpwp_REST_Action_Log();
		$this->force_key_record( $non_holder, array( 'admin', 'content' ) );
		$hidden_ids = array_column(
			$non_holder->list_entries( new WP_REST_Request( 'GET', '/mcpwp/v1/action-log', array( 'limit' => 50, 'offset' => 0 ) ) )->get_data()['entries'],
			'log_id'
		);
		$this->assertNotContains( $log_id, $hidden_ids, 'a key without woocommerce must still NOT see it after the delete either' );
	}

	public function test_n2_not_yet_backfilled_row_is_hidden_from_a_restricted_key_but_visible_to_unrestricted(): void {
		// N2, door level: a row written before the round-2 column existed
		// (resource_post_type still '', resource_id identifies a real post)
		// must fail closed for a restricted key, and still be readable by
		// an unrestricted one — the whole point of the backfill migration
		// being "in progress" must never widen visibility in the meantime.
		$log_id = $this->seed_entry( 'wp_update_post', array( 'id' => self::PRODUCT_ID, 'title' => 'x' ), 'content' );
		$this->simulate_not_yet_backfilled( $log_id );

		$restricted = new Mcpwp_REST_Action_Log();
		$this->force_key_record( $restricted, array( 'admin', 'content', 'woocommerce' ) );
		$restricted_ids = array_column(
			$restricted->list_entries( new WP_REST_Request( 'GET', '/mcpwp/v1/action-log', array( 'limit' => 50, 'offset' => 0 ) ) )->get_data()['entries'],
			'log_id'
		);
		$this->assertNotContains( $log_id, $restricted_ids, 'a restricted key must not get a free pass on a not-yet-backfilled row' );

		$unrestricted = new Mcpwp_REST_Action_Log();
		$this->force_key_record( $unrestricted, array(), 'unrestricted-admin', 'admin' );
		$unrestricted_ids = array_column(
			$unrestricted->list_entries( new WP_REST_Request( 'GET', '/mcpwp/v1/action-log', array( 'limit' => 50, 'offset' => 0 ) ) )->get_data()['entries'],
			'log_id'
		);
		$this->assertContains( $log_id, $unrestricted_ids );

		// And the same rule PHP-side, on the by-id door.
		$get_result = $restricted->get_entry( new WP_REST_Request( 'GET', '/mcpwp/v1/action-log/' . $log_id, array( 'log_id' => $log_id ) ) );
		$this->assertInstanceOf( WP_Error::class, $get_result );
		$this->assertSame( 'not_found', $get_result->get_error_code() );
	}

	// ── Mcpwp_REST_Action_Log::get_entry() / rollback_entry() ───────────────

	public function test_get_entry_denies_a_hidden_entry_as_not_found(): void {
		$hidden_id = $this->seed_entry( 'wp_update_post', array( 'id' => self::PRODUCT_ID, 'title' => 'new title' ), 'content' );

		$controller = new Mcpwp_REST_Action_Log();
		$this->force_key_record( $controller, array( 'admin', 'content' ) );

		$result = $controller->get_entry( new WP_REST_Request( 'GET', '/mcpwp/v1/action-log/' . $hidden_id, array( 'log_id' => $hidden_id ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_get_entry_allows_a_visible_entry(): void {
		$visible_id = $this->seed_entry( 'wp_update_post', array( 'id' => self::ORDINARY_POST_ID, 'title' => 'new title' ), 'content' );

		$controller = new Mcpwp_REST_Action_Log();
		$this->force_key_record( $controller, array( 'admin', 'content' ) );

		$result = $controller->get_entry( new WP_REST_Request( 'GET', '/mcpwp/v1/action-log/' . $visible_id, array( 'log_id' => $visible_id ) ) );

		$this->assertInstanceOf( WP_REST_Response::class, $result );
		$this->assertSame( $visible_id, $result->get_data()['log_id'] );
	}

	public function test_rollback_denies_a_hidden_entry_as_not_found(): void {
		$hidden_id = $this->seed_entry( 'wp_update_post', array( 'id' => self::PRODUCT_ID, 'title' => 'new title' ), 'content' );

		$controller = new Mcpwp_REST_Action_Log();
		$this->force_key_record( $controller, array( 'admin', 'content' ) );

		$result = $controller->rollback_entry( new WP_REST_Request( 'POST', '/mcpwp/v1/action-log/' . $hidden_id . '/rollback', array( 'log_id' => $hidden_id ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
	}

	// ── NIT N2: export pages beyond list_entries()'s own 100-row ceiling ────

	public function test_export_csv_pages_through_more_than_100_rows(): void {
		for ( $i = 0; $i < 130; $i++ ) {
			$this->seed_entry( 'wp_update_option', array( 'key' => 'mcpwp_test_option_' . $i, 'value' => 'x' ), 'admin' );
		}

		$csv  = Mcpwp_Action_Log::export_csv( array( 'limit' => 130 ) );
		$rows = array_filter( explode( "\n", trim( $csv ) ) );

		// Header + 130 data rows.
		$this->assertCount( 131, $rows, 'export must page through list_entries() to reach its own 130-row request, not silently cap at 100' );
	}

	// ── DESIGN item 6: snapshot size cap ─────────────────────────────────────

	public function test_oversized_snapshot_is_withheld_and_rollback_gives_a_clear_error(): void {
		$GLOBALS['_mcpwp_test_posts'][ self::ORDINARY_POST_ID ]->post_content = str_repeat( 'A', Mcpwp_Action_Log::SNAPSHOT_MAX_BYTES + 1 );

		$log_id = $this->seed_entry( 'wp_update_post', array( 'id' => self::ORDINARY_POST_ID, 'title' => 'new title' ), 'content' );

		$controller = new Mcpwp_REST_Action_Log();
		$this->force_key_record( $controller, array(), 'admin-key', 'admin' );

		$entry = $controller->get_entry( new WP_REST_Request( 'GET', '/mcpwp/v1/action-log/' . $log_id, array( 'log_id' => $log_id ) ) )->get_data();
		$this->assertSame( 'too_large', $entry['snapshot_skipped'] );

		$result = $controller->rollback_entry( new WP_REST_Request( 'POST', '/mcpwp/v1/action-log/' . $log_id . '/rollback', array( 'log_id' => $log_id ) ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'snapshot_withheld', $result->get_error_code() );
	}

	public function test_normal_sized_snapshot_is_not_withheld(): void {
		$log_id = $this->seed_entry( 'wp_update_post', array( 'id' => self::ORDINARY_POST_ID, 'title' => 'new title' ), 'content' );

		$controller = new Mcpwp_REST_Action_Log();
		$this->force_key_record( $controller, array(), 'admin-key', 'admin' );

		$entry = $controller->get_entry( new WP_REST_Request( 'GET', '/mcpwp/v1/action-log/' . $log_id, array( 'log_id' => $log_id ) ) )->get_data();
		$this->assertSame( '', $entry['snapshot_skipped'] );
	}

	// ── Door-level proof: a real scoped [admin, content] key over check_permission() ─

	public function test_door_level_admin_content_key_does_not_see_product_activity(): void {
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
