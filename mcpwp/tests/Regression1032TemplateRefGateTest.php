<?php
/**
 * Regression tests — #1032, found in the round-3 adversarial review of
 * PR #1030 (#1024): a designer key, with the 'elementor-templates' category
 * disabled, saved an Elementor Pro Template widget with
 * `{"template_id":"12"}` on a page it could freely write to. Neither
 * set_elementor_data() nor any of the surgical section/widget writers
 * inspected the SUBMITTED TREE for a Template Library reference — only the
 * TARGET post's own category was checked (#1024/#1030's own chokepoint) — so
 * the widget saved cleanly, and /elementor/{id}/preview (html and summary)
 * then rendered the gated template's content straight through, because
 * Elementor's own in-process render expands ANY template_id/templateID it
 * finds, with no notion of MCPWP's tool categories at all.
 *
 * The fix is two shared, reusable pieces (never a per-door patch):
 *
 *  - Mcpwp_Elementor_Template_Refs::find()/find_ids() — the one walker that
 *    knows which widget settings embed a Template Library id (Template
 *    widget, Global widget, Loop Grid/Carousel, ...).
 *  - Mcpwp_Api_Auth::template_ref_gate()/post_ref_readable() — the same
 *    existence-oracle rule post_ref_not_found_error() already enforces for
 *    every DECLARED post-ref parameter, reused here for an id discovered a
 *    level deeper inside a write, or resolved by Elementor mid-render for a
 *    read.
 *
 * Both are injected into the business-logic classes (which have no REST/auth
 * context of their own) the same way set_category_allowed_callback() already
 * is — see each REST controller's check_permission() override.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-elementor-template-refs.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-elementor-stored-tree.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-elementor-basic.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-elementor.php';
require_once dirname( __DIR__ ) . '/includes/pro/core/class-mcpwp-elementor-pro.php';
require_once dirname( __DIR__ ) . '/includes/pro/core/class-mcpwp-page-builder.php';
require_once dirname( __DIR__ ) . '/includes/pro/core/class-mcpwp-theme-builder.php';
require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-elementor-pro.php';
require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-theme-builder.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-posts.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-pages.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-posts.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-pages.php';

final class Regression1032TemplateRefGateTest extends TestCase {

	private const PAGE_ID      = 91101; // Ordinary page, always writable/readable.
	private const ARCHETYPE_ID = 91102; // elementor_library — the gated template.
	private const MISSING_ID   = 91199; // Never in _mcpwp_test_posts.

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']            = array();
		$GLOBALS['mcpwp_test_transients']         = array();
		$GLOBALS['mcpwp_test_current_user']       = 0;
		$GLOBALS['mcpwp_test_is_admin']           = false;
		$GLOBALS['mcpwp_test_filters']            = array();
		$GLOBALS['_mcpwp_test_meta']              = array();
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		$GLOBALS['mcpwp_test_public_post_types']  = array( 'elementor_library' );

		// post_ref_readable()'s memo (adversarial review round 1 NIT) lives
		// no longer than one request in production; this test SUITE process
		// reuses the same ids under different forced key records across many
		// tests, so each test starts with a clean memo on every class that
		// composes the trait.
		Mcpwp_REST_Elementor::reset_post_ref_readable_memo();
		Mcpwp_REST_Elementor_Pro::reset_post_ref_readable_memo();
		Mcpwp_REST_Theme_Builder::reset_post_ref_readable_memo();
		Mcpwp_REST_Pages::reset_post_ref_readable_memo();

		$GLOBALS['_mcpwp_test_posts'] = array(
			self::PAGE_ID      => new WP_Post(
				array(
					'ID'          => self::PAGE_ID,
					'post_type'   => 'page',
					'post_title'  => 'Landing',
					'post_name'   => 'landing',
					'post_status' => 'publish',
				)
			),
			self::ARCHETYPE_ID => new WP_Post(
				array(
					'ID'          => self::ARCHETYPE_ID,
					'post_type'   => 'elementor_library',
					'post_title'  => 'Gated Section',
					'post_name'   => 'gated-section',
					'post_status' => 'publish',
				)
			),
		);

		$GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data']      = json_encode( $this->plain_tree() );
		$GLOBALS['_mcpwp_test_meta'][ self::ARCHETYPE_ID ]['_elementor_data'] = json_encode( $this->plain_tree() );
	}

	protected function tearDown(): void {
		update_option( 'mcpwp_disabled_tool_categories', array() );
	}

	private function plain_tree(): array {
		return array(
			array(
				'id'       => 'sec00001',
				'elType'   => 'section',
				'settings' => array(),
				'elements' => array(
					array(
						'id'       => 'col00001',
						'elType'   => 'column',
						'settings' => array( '_column_size' => 100 ),
						'elements' => array(
							array(
								'id'         => 'head0001',
								'elType'     => 'widget',
								'widgetType' => 'heading',
								'settings'   => array( 'title' => 'Hello' ),
							),
						),
					),
				),
			),
		);
	}

	private function tree_with_template_widget( int $template_id ): array {
		return array(
			array(
				'id'       => 'sec00002',
				'elType'   => 'section',
				'settings' => array(),
				'elements' => array(
					array(
						'id'       => 'col00002',
						'elType'   => 'column',
						'settings' => array( '_column_size' => 100 ),
						'elements' => array(
							array(
								'id'         => 'tpl00001',
								'elType'     => 'widget',
								'widgetType' => 'template',
								'settings'   => array( 'template_id' => (string) $template_id ),
							),
						),
					),
				),
			),
		);
	}

	private function tree_with_global_widget( int $template_id ): array {
		return array(
			array(
				'id'       => 'sec00003',
				'elType'   => 'section',
				'settings' => array(),
				'elements' => array(
					array(
						'id'         => 'glb00001',
						'elType'     => 'widget',
						'widgetType' => 'global',
						'templateID' => $template_id,
						'settings'   => array(),
					),
				),
			),
		);
	}

	private function tree_with_loop_grid_alternate_template( int $base_id, int $alt_id ): array {
		return array(
			array(
				'id'       => 'sec00004',
				'elType'   => 'section',
				'settings' => array(),
				'elements' => array(
					array(
						'id'         => 'lg000001',
						'elType'     => 'widget',
						'widgetType' => 'loop-grid',
						'settings'   => array(
							'template_id'         => $base_id,
							'alternate_templates' => array(
								array( 'template_id' => $alt_id, 'repeat_template' => 3 ),
							),
						),
					),
				),
			),
		);
	}

	/** Attach a key record directly, bypassing scope/route gates unrelated to this fix. */
	private function force_key_record( object $controller, array $categories, string $role = 'custom' ): void {
		$ref = new ReflectionProperty( get_class( $controller ), 'current_api_key_record' );
		$ref->setAccessible( true );
		$ref->setValue(
			$controller,
			array(
				'id'              => 'forced',
				'label'           => 'forced',
				'scopes'          => array( 'read', 'write', 'admin' ),
				'role'            => $role,
				'tool_categories' => $categories,
			)
		);
	}

	private function assert_canonical_not_found( $result, string $context = '' ): void {
		$this->assertInstanceOf( WP_Error::class, $result, $context );
		$this->assertSame( 'not_found', $result->get_error_code(), $context );
		$this->assertSame( 'The requested resource was not found.', $result->get_error_message(), $context );
		$data = $result->get_error_data();
		$this->assertSame( 404, $data['status'] ?? null, $context );
	}

	// =========================================================================
	// Mcpwp_Elementor_Template_Refs — the walker itself
	// =========================================================================

	public function test_finds_template_widget_settings_template_id(): void {
		$refs = Mcpwp_Elementor_Template_Refs::find( $this->tree_with_template_widget( 12 ) );
		$this->assertCount( 1, $refs );
		$this->assertSame( 12, $refs[0]['id'] );
		$this->assertSame( 'settings', $refs[0]['key'] );
	}

	public function test_finds_global_widget_top_level_template_id(): void {
		$ids = Mcpwp_Elementor_Template_Refs::find_ids( $this->tree_with_global_widget( 34 ) );
		$this->assertSame( array( 34 ), $ids );
	}

	public function test_finds_loop_grid_base_and_repeater_alternate_template(): void {
		$ids = Mcpwp_Elementor_Template_Refs::find_ids( $this->tree_with_loop_grid_alternate_template( 55, 56 ) );
		sort( $ids );
		$this->assertSame( array( 55, 56 ), $ids );
	}

	public function test_finds_woocommerce_cart_and_my_account_template_settings(): void {
		$elements = array(
			array(
				'id'         => 'cart0001',
				'elType'     => 'widget',
				'widgetType' => 'woocommerce-cart',
				'settings'   => array( 'additional_template_select' => 77 ),
			),
			array(
				'id'         => 'acct0001',
				'elType'     => 'widget',
				'widgetType' => 'woocommerce-my-account',
				'settings'   => array( 'customize_dashboard_select' => 78 ),
			),
		);
		$ids = Mcpwp_Elementor_Template_Refs::find_ids( $elements );
		sort( $ids );
		$this->assertSame( array( 77, 78 ), $ids );
	}

	public function test_ignores_zero_negative_and_non_numeric_values(): void {
		$elements = array(
			array(
				'id'         => 'tpl00002',
				'elType'     => 'widget',
				'widgetType' => 'template',
				'settings'   => array( 'template_id' => '' ),
			),
			array(
				'id'         => 'tpl00003',
				'elType'     => 'widget',
				'widgetType' => 'template',
				'settings'   => array( 'template_id' => 0 ),
			),
			array(
				'id'         => 'tpl00004',
				'elType'     => 'widget',
				'widgetType' => 'template',
				'settings'   => array( 'template_id' => -5 ),
			),
			array(
				'id'         => 'tpl00005',
				'elType'     => 'widget',
				'widgetType' => 'template',
				'settings'   => array( 'template_id' => 'not-a-number' ),
			),
		);
		$this->assertSame( array(), Mcpwp_Elementor_Template_Refs::find_ids( $elements ) );
	}

	public function test_find_ids_deduplicates(): void {
		$elements = array(
			$this->tree_with_template_widget( 99 )[0],
			$this->tree_with_global_widget( 99 )[0],
		);
		$this->assertSame( array( 99 ), Mcpwp_Elementor_Template_Refs::find_ids( $elements ) );
	}

	// =========================================================================
	// Write side — Mcpwp_Elementor_Basic::check_template_references() and
	// every write path that calls it
	// =========================================================================

	public function test_no_gate_injected_means_unrestricted(): void {
		// Matches every other injected-callback convention in this trait
		// (set_category_allowed_callback et al.): a context with no
		// REST/auth wiring at all is not newly restricted by this fix.
		$elementor = new Mcpwp_Elementor_Basic();
		$this->assertTrue( $elementor->check_template_references( $this->tree_with_template_widget( self::ARCHETYPE_ID ) ) );
	}

	public function test_add_section_refuses_embedded_template_widget_caller_cannot_read(): void {
		$elementor = new Mcpwp_Elementor_Basic();
		$elementor->set_category_allowed_callback(
			function ( $post_type ) {
				return in_array( $post_type, array( 'page' ), true );
			}
		);
		$elementor->set_template_ref_gate(
			function ( array $ids ) {
				// Only elementor-templates disabled; ARCHETYPE_ID is the only
				// candidate this test ever submits.
				return in_array( self::ARCHETYPE_ID, $ids, true )
					? new WP_Error( 'not_found', 'The requested resource was not found.', array( 'status' => 404, 'hint' => 'Check the id with wp_search or wp_list_posts.' ) )
					: true;
			}
		);

		$before = $GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'];
		$result = $elementor->add_section(
			self::PAGE_ID,
			array( 'element' => $this->tree_with_template_widget( self::ARCHETYPE_ID )[0] )
		);

		$this->assert_canonical_not_found( $result, 'A Template widget referencing a gated id must refuse the whole write.' );
		$this->assertSame( $before, $GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'], 'Nothing must have been written.' );
	}

	public function test_add_section_allows_template_widget_caller_can_read(): void {
		$elementor = new Mcpwp_Elementor_Basic();
		$elementor->set_category_allowed_callback(
			function ( $post_type ) {
				return true;
			}
		);
		$elementor->set_template_ref_gate(
			function ( array $ids ) {
				return true; // elementor-templates is enabled for this caller.
			}
		);

		$result = $elementor->add_section(
			self::PAGE_ID,
			array( 'element' => $this->tree_with_template_widget( self::ARCHETYPE_ID )[0] )
		);

		$this->assertIsArray( $result, 'A readable template reference must not be refused.' );
		$this->assertTrue( $result['success'] ?? false );
	}

	public function test_add_section_does_not_re_judge_a_preexisting_reference_on_the_same_page(): void {
		// #1032 round 2 false-positives NIT, round 2 of the round-2 fix
		// itself: check_template_references() (the ROUND-1 per-door check,
		// called by save_elements_to_page()/set_elementor_data() against the
		// FULL resulting tree — old content plus the new edit) must ALSO
		// carry the pre-existing exemption, not just the newer request-body
		// chokepoint — a rig run surfaced this: without it, add_section()
		// re-scanned the WHOLE tree (the page's pre-existing gated Template
		// widget included) and locked a designer key out of adding an
		// unrelated, reference-free section to a page an admin had already
		// built.
		$GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] = json_encode( $this->tree_with_template_widget( self::ARCHETYPE_ID ) );

		$elementor = new Mcpwp_Elementor_Basic();
		$elementor->set_category_allowed_callback( function () { return true; } );
		$elementor->set_template_ref_gate(
			function ( array $ids ) {
				return in_array( self::ARCHETYPE_ID, $ids, true )
					? new WP_Error( 'not_found', 'The requested resource was not found.', array( 'status' => 404, 'hint' => 'A referenced template was not found.' ) )
					: true;
			}
		);

		$unrelated_section = array(
			'id'       => 'sec00099',
			'elType'   => 'section',
			'settings' => array(),
			'elements' => array(
				array(
					'id'         => 'wid00099',
					'elType'     => 'widget',
					'widgetType' => 'heading',
					'settings'   => array( 'title' => 'Nothing to do with any template' ),
				),
			),
		);
		$result = $elementor->add_section( self::PAGE_ID, array( 'element' => $unrelated_section ) );

		$this->assertIsArray( $result, 'Adding a reference-free section to a page that already held a gated reference must not be locked out.' );
		$this->assertTrue( $result['success'] ?? false );
	}

	public function test_add_section_still_refuses_a_new_reference_on_a_page_with_a_preexisting_one(): void {
		// Companion to the test above: the exemption is per-id, not
		// per-page.
		$GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] = json_encode( $this->tree_with_template_widget( self::ARCHETYPE_ID ) );

		$second_id                                       = self::ARCHETYPE_ID + 700;
		$GLOBALS['_mcpwp_test_posts'][ $second_id ] = new WP_Post(
			array(
				'ID'          => $second_id,
				'post_type'   => 'elementor_library',
				'post_title'  => 'Another Gated Section',
				'post_name'   => 'another-gated-section',
				'post_status' => 'publish',
			)
		);

		$elementor = new Mcpwp_Elementor_Basic();
		$elementor->set_category_allowed_callback( function () { return true; } );
		$elementor->set_template_ref_gate(
			function ( array $ids ) use ( $second_id ) {
				return in_array( $second_id, $ids, true )
					? new WP_Error( 'not_found', 'The requested resource was not found.', array( 'status' => 404, 'hint' => 'A referenced template was not found.' ) )
					: true;
			}
		);

		$result = $elementor->add_section( self::PAGE_ID, array( 'element' => $this->tree_with_template_widget( $second_id )[0] ) );

		$this->assertInstanceOf( WP_Error::class, $result, 'A NEW reference (distinct from the one already on the page) must still be refused.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_set_elementor_data_refuses_global_widget_referencing_gated_template(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '3.0' );
		}
		$elementor = new Mcpwp_Elementor_Basic();
		$elementor->set_category_allowed_callback(
			function ( $post_type ) {
				return true;
			}
		);
		$elementor->set_template_ref_gate(
			function ( array $ids ) {
				return new WP_Error( 'not_found', 'The requested resource was not found.', array( 'status' => 404, 'hint' => 'Check the id with wp_search or wp_list_posts.' ) );
			}
		);

		$before = $GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'];
		$result = $elementor->set_elementor_data(
			self::PAGE_ID,
			array( 'elementor_data' => $this->tree_with_global_widget( self::ARCHETYPE_ID ) )
		);

		$this->assert_canonical_not_found( $result );
		$this->assertSame( $before, $GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_set_elementor_data_dry_run_also_refuses(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '3.0' );
		}
		$elementor = new Mcpwp_Elementor_Basic();
		$elementor->set_category_allowed_callback(
			function ( $post_type ) {
				return true;
			}
		);
		$elementor->set_template_ref_gate(
			function ( array $ids ) {
				return new WP_Error( 'not_found', 'The requested resource was not found.', array( 'status' => 404, 'hint' => 'Check the id with wp_search or wp_list_posts.' ) );
			}
		);

		$result = $elementor->set_elementor_data(
			self::PAGE_ID,
			array( 'elementor_data' => $this->tree_with_template_widget( self::ARCHETYPE_ID ) ),
			true // dry_run
		);

		$this->assert_canonical_not_found( $result, 'A dry run must report the same refusal a real write would give.' );
	}

	public function test_patch_elementor_refuses_operation_that_introduces_a_gated_template(): void {
		$elementor = new Mcpwp_Elementor_Basic();
		$elementor->set_category_allowed_callback(
			function ( $post_type ) {
				return true;
			}
		);
		$elementor->set_template_ref_gate(
			function ( array $ids ) {
				return new WP_Error( 'not_found', 'The requested resource was not found.', array( 'status' => 404, 'hint' => 'Check the id with wp_search or wp_list_posts.' ) );
			}
		);

		$before = $GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'];
		$result = $elementor->patch_elementor(
			self::PAGE_ID,
			array(
				'operations' => array(
					array(
						'op'      => 'add',
						'position' => 'end',
						'element' => $this->tree_with_template_widget( self::ARCHETYPE_ID )[0],
					),
				),
			)
		);

		$this->assert_canonical_not_found( $result );
		$this->assertSame( $before, $GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] );
	}

	// =========================================================================
	// Auth-trait primitives — post_ref_readable() / template_ref_gate() /
	// template_render_gate()
	// =========================================================================

	public function test_post_ref_readable_false_for_missing_id(): void {
		$controller = new Mcpwp_REST_Elementor();
		$this->force_key_record( $controller, array( 'elementor', 'elementor-templates' ) );
		$this->assertFalse( $controller->post_ref_readable( self::MISSING_ID, 'elementor' ) );
	}

	public function test_post_ref_readable_false_when_category_disabled_site_wide(): void {
		// Exact reproduction of the issue's own scenario: a fully-privileged
		// key, but the site owner disabled elementor-templates for everyone.
		update_option( 'mcpwp_disabled_tool_categories', array( 'elementor-templates' ) );

		$controller = new Mcpwp_REST_Elementor();
		$this->force_key_record( $controller, array(), 'admin' ); // admin: categories=[] means "all", still loses to the site-wide disable.
		$this->assertFalse( $controller->post_ref_readable( self::ARCHETYPE_ID, 'elementor' ) );
	}

	public function test_post_ref_readable_true_when_category_held(): void {
		$controller = new Mcpwp_REST_Elementor();
		$this->force_key_record( $controller, array( 'elementor', 'elementor-templates' ) );
		$this->assertTrue( $controller->post_ref_readable( self::ARCHETYPE_ID, 'elementor' ) );
	}

	public function test_template_ref_gate_refuses_on_first_hidden_id(): void {
		$controller = new Mcpwp_REST_Elementor();
		$this->force_key_record( $controller, array( 'elementor' ) ); // no elementor-templates
		$gate   = $controller->template_ref_gate();
		$result = $gate( array( self::ARCHETYPE_ID ) );
		$this->assert_canonical_not_found( $result );
	}

	public function test_template_ref_gate_passes_when_every_id_readable(): void {
		$controller = new Mcpwp_REST_Elementor();
		$this->force_key_record( $controller, array( 'elementor', 'elementor-templates' ) );
		$gate = $controller->template_ref_gate();
		$this->assertTrue( $gate( array( self::ARCHETYPE_ID ) ) );
	}

	public function test_template_ref_gate_batches_one_query_for_every_id(): void {
		// Adversarial review round 1 NIT: one query for the whole batch, not
		// one per id.
		$controller = new Mcpwp_REST_Elementor();
		$this->force_key_record( $controller, array( 'elementor', 'elementor-templates' ) );
		$gate = $controller->template_ref_gate();

		$GLOBALS['mcpwp_test_post_ref_queries'] = array();
		$this->assertTrue( $gate( array( self::ARCHETYPE_ID, self::PAGE_ID ) ) );
		$this->assertCount( 1, $GLOBALS['mcpwp_test_post_ref_queries'] ?? array(), 'A batch of ids must resolve in one query.' );
	}

	public function test_template_render_gate_denies_hidden_and_allows_readable(): void {
		$denied  = new Mcpwp_REST_Elementor();
		$this->force_key_record( $denied, array( 'elementor' ) );
		$deny_gate = $denied->template_render_gate();
		$this->assertFalse( $deny_gate( self::ARCHETYPE_ID ) );

		// post_ref_readable()'s per-(id,category) memo is correct for real
		// usage (one caller per PHP request) but two DIFFERENT callers
		// checking the same id in the same process — only possible here,
		// deliberately exercising both outcomes in one test — must not share
		// the first caller's cached verdict.
		Mcpwp_REST_Elementor::reset_post_ref_readable_memo();

		$allowed = new Mcpwp_REST_Elementor();
		$this->force_key_record( $allowed, array( 'elementor', 'elementor-templates' ) );
		$allow_gate = $allowed->template_render_gate();
		$this->assertTrue( $allow_gate( self::ARCHETYPE_ID ) );
	}

	// =========================================================================
	// Adversarial review round 1 — B1: loose numeric coercion
	// =========================================================================

	public function test_write_refuses_template_id_with_trailing_garbage(): void {
		// "5abc" is exactly how Elementor's own (int) cast resolves — the
		// live exploit named in the review.
		$elementor = new Mcpwp_Elementor_Basic();
		$elementor->set_category_allowed_callback( function () { return true; } );
		$elementor->set_template_ref_gate(
			function ( array $ids ) {
				return in_array( self::ARCHETYPE_ID, $ids, true )
					? new WP_Error( 'not_found', 'The requested resource was not found.', array( 'status' => 404, 'hint' => 'Check the id with wp_search or wp_list_posts.' ) )
					: true;
			}
		);

		$tree = array(
			array(
				'id'       => 'sec00009',
				'elType'   => 'section',
				'settings' => array(),
				'elements' => array(
					array(
						'id'         => 'tpl00009',
						'elType'     => 'widget',
						'widgetType' => 'template',
						'settings'   => array( 'template_id' => self::ARCHETYPE_ID . 'abc' ),
					),
				),
			),
		);
		$result = $elementor->add_section( self::PAGE_ID, array( 'element' => $tree[0] ) );
		$this->assert_canonical_not_found( $result, '"' . self::ARCHETYPE_ID . 'abc" must resolve the same way Elementor\'s own (int) cast would.' );
	}

	public function test_write_refuses_global_widget_template_id_with_trailing_garbage(): void {
		$elementor = new Mcpwp_Elementor_Basic();
		$elementor->set_category_allowed_callback( function () { return true; } );
		$elementor->set_template_ref_gate(
			function ( array $ids ) {
				return in_array( self::ARCHETYPE_ID, $ids, true )
					? new WP_Error( 'not_found', 'The requested resource was not found.', array( 'status' => 404, 'hint' => 'Check the id with wp_search or wp_list_posts.' ) )
					: true;
			}
		);

		$element = array(
			'id'         => 'glb00009',
			'elType'     => 'widget',
			'widgetType' => 'global',
			'templateID' => self::ARCHETYPE_ID . 'x',
			'settings'   => array(),
		);
		$result = $elementor->add_section( self::PAGE_ID, array( 'element' => $element ) );
		$this->assert_canonical_not_found( $result );
	}

	// =========================================================================
	// Adversarial review round 1 — B2: find/replace bypassed the gate
	// =========================================================================

	public function test_single_find_replace_refuses_post_replacement_gated_reference(): void {
		$GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] = json_encode( array(
			array(
				'id'       => 'sec00010',
				'elType'   => 'section',
				'settings' => array(),
				'elements' => array(
					array(
						'id'         => 'tpl00010',
						'elType'     => 'widget',
						'widgetType' => 'template',
						'settings'   => array( 'template_id' => 'PLACEHOLDER' ),
					),
				),
			),
		) );

		$controller = new Mcpwp_REST_Elementor();
		$created    = $controller->create_scoped_api_key( 'no templates', array( 'read', 'write' ), 'custom', array( 'elementor' ) );
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/elementor/' . self::PAGE_ID . '/find-replace',
			array( 'id' => self::PAGE_ID, 'search' => 'PLACEHOLDER', 'replace' => (string) self::ARCHETYPE_ID ),
			array( 'X-API-Key' => $created['key'] )
		);
		$this->assertTrue( $controller->check_permission( $request ) );

		$before = $GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'];
		$result = $controller->find_replace( $request );

		$this->assertInstanceOf( WP_Error::class, $result, 'A replacement that turns a placeholder into a gated template id must be refused, not written.' );
		$this->assertSame( 'not_found', $result->get_error_code() );
		$this->assertSame( $before, $GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] );
	}

	public function test_bulk_find_replace_refuses_post_replacement_gated_reference(): void {
		$GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] = json_encode( array(
			array(
				'id'       => 'sec00011',
				'elType'   => 'section',
				'settings' => array(),
				'elements' => array(
					array(
						'id'         => 'tpl00011',
						'elType'     => 'widget',
						'widgetType' => 'template',
						'settings'   => array( 'template_id' => 'PLACEHOLDER' ),
					),
				),
			),
		) );

		$controller = new Mcpwp_REST_Elementor();
		$created    = $controller->create_scoped_api_key( 'no templates', array( 'read', 'write', 'admin' ), 'custom', array( 'elementor' ) );
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/elementor/find-replace',
			array( 'ids' => array( self::PAGE_ID ), 'search' => 'PLACEHOLDER', 'replace' => (string) self::ARCHETYPE_ID ),
			array( 'X-API-Key' => $created['key'] )
		);
		$this->assertTrue( $controller->check_permission( $request ) );

		$before   = $GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'];
		$response = $controller->find_replace_many( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data    = $response->get_data();
		$written = array_filter( $data['matched'] ?? array(), static function ( $entry ) {
			return ! empty( $entry['written'] );
		} );
		$this->assertEmpty( $written, 'The matched post must not be reported as successfully written.' );
		$this->assertSame( $before, $GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] );
	}

	// =========================================================================
	// REST wiring — Mcpwp_REST_Elementor::check_permission() installs both
	// gates end to end (the issue's own reproduction shape)
	// =========================================================================

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_rest_wiring_refuses_write_embedding_gated_template_end_to_end(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '3.0' );
		}
		$controller = new Mcpwp_REST_Elementor();
		$created    = $controller->create_scoped_api_key( 'designer, no templates', array( 'read', 'write' ), 'custom', array( 'elementor' ) );
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/elementor/' . self::PAGE_ID,
			array(
				'id'             => self::PAGE_ID,
				'elementor_data' => $this->tree_with_template_widget( self::ARCHETYPE_ID ),
			),
			array( 'X-API-Key' => $created['key'] )
		);

		// #1032 round 2: check_permission() itself now refuses via the
		// request-body-wide chokepoint (check_request_template_refs()),
		// before the handler is ever reached — earlier than round 1's own
		// per-door check, which stays as the second layer (find-replace and
		// other server-side transformations the request body itself never
		// shows).
		$before = $GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'];
		$auth   = $controller->check_permission( $request );

		$this->assertInstanceOf( WP_Error::class, $auth );
		$this->assertSame( 'not_found', $auth->get_error_code() );
		$this->assertSame( $before, $GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'], 'The gated template reference must never reach storage.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_rest_wiring_allows_write_when_key_holds_elementor_templates(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '3.0' );
		}
		$controller = new Mcpwp_REST_Elementor();
		$created    = $controller->create_scoped_api_key( 'full designer', array( 'read', 'write' ), 'custom', array( 'elementor', 'elementor-templates' ) );
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/elementor/' . self::PAGE_ID,
			array(
				'id'             => self::PAGE_ID,
				'elementor_data' => $this->tree_with_template_widget( self::ARCHETYPE_ID ),
			),
			array( 'X-API-Key' => $created['key'] )
		);

		$this->assertTrue( $controller->check_permission( $request ) );
		$result = $controller->set_elementor_data( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $result, 'A key that DOES hold elementor-templates must be able to embed the reference.' );
	}

	// =========================================================================
	// Read side — render_with_template_gate() filter mechanics (Elementor
	// itself is not installable in this unit harness — see rig proof for the
	// full in-process render; this proves the redaction mechanism the rig
	// exercises end to end).
	// =========================================================================

	public function test_render_with_template_gate_blanks_a_denied_nested_document(): void {
		$elementor = new Mcpwp_Elementor_Basic();
		$elementor->set_nested_render_gate(
			function ( $doc_post_id ) {
				return (int) $doc_post_id !== self::ARCHETYPE_ID; // deny only the nested template.
			}
		);

		$rc     = new ReflectionClass( $elementor );
		$method = $rc->getMethod( 'render_with_template_gate' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$elementor,
			self::PAGE_ID,
			function () {
				// Simulates Elementor::Frontend::get_builder_content() calling
				// apply_filters( 'elementor/frontend/builder_content_data', ... )
				// once for the outer page and once for the nested template it
				// resolves via a Template/Global widget.
				$outer  = apply_filters( 'elementor/frontend/builder_content_data', array( 'outer' => true ), self::PAGE_ID );
				$nested = apply_filters( 'elementor/frontend/builder_content_data', array( 'secret' => 'SECRET-TPL-HEADING' ), self::ARCHETYPE_ID );
				return array( 'outer' => $outer, 'nested' => $nested );
			}
		);

		$this->assertSame( array( 'outer' => true ), $result['outer'], 'The outer document being read must never be redacted by this filter.' );
		$this->assertSame( array(), $result['nested'], 'A nested document the caller cannot read must render as empty, not fail.' );
	}

	public function test_render_with_template_gate_passes_through_a_readable_nested_document(): void {
		$elementor = new Mcpwp_Elementor_Basic();
		$elementor->set_nested_render_gate(
			function ( $doc_post_id ) {
				return true; // caller holds elementor-templates.
			}
		);

		$rc     = new ReflectionClass( $elementor );
		$method = $rc->getMethod( 'render_with_template_gate' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$elementor,
			self::PAGE_ID,
			function () {
				return apply_filters( 'elementor/frontend/builder_content_data', array( 'visible' => 'ok' ), self::ARCHETYPE_ID );
			}
		);

		$this->assertSame( array( 'visible' => 'ok' ), $result );
	}

	public function test_render_with_template_gate_removes_its_filter_after_use(): void {
		$elementor = new Mcpwp_Elementor_Basic();
		$elementor->set_nested_render_gate(
			function ( $doc_post_id ) {
				return false; // deny everything, to make a leak obvious.
			}
		);

		$rc     = new ReflectionClass( $elementor );
		$method = $rc->getMethod( 'render_with_template_gate' );
		$method->setAccessible( true );

		$method->invoke( $elementor, self::PAGE_ID, function () {
			return apply_filters( 'elementor/frontend/builder_content_data', array( 'x' => 1 ), self::ARCHETYPE_ID );
		} );

		// A later, unrelated caller (no gate involved at all) must see its
		// own data completely unfiltered — the hook must not have leaked.
		$after = apply_filters( 'elementor/frontend/builder_content_data', array( 'x' => 1 ), self::ARCHETYPE_ID );
		$this->assertSame( array( 'x' => 1 ), $after );
	}

	public function test_render_with_template_gate_no_gate_injected_calls_render_directly(): void {
		$elementor = new Mcpwp_Elementor_Basic(); // no set_nested_render_gate() at all.

		$rc     = new ReflectionClass( $elementor );
		$method = $rc->getMethod( 'render_with_template_gate' );
		$method->setAccessible( true );

		$called = false;
		$result = $method->invoke(
			$elementor,
			self::PAGE_ID,
			function () use ( &$called ) {
				$called = true;
				return 'rendered';
			}
		);

		$this->assertTrue( $called );
		$this->assertSame( 'rendered', $result );
	}

	// =========================================================================
	// Pro write paths that bypass the shared writer trait entirely
	// =========================================================================

	public function test_pro_clone_page_refuses_when_source_embeds_gated_global_widget(): void {
		$GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] = json_encode( $this->tree_with_global_widget( self::ARCHETYPE_ID ) );

		$elementor_pro = new Mcpwp_Elementor_Pro();
		$controller    = new Mcpwp_REST_Elementor_Pro( $elementor_pro );
		$created       = $controller->create_scoped_api_key( 'no templates', array( 'read', 'write' ), 'custom', array( 'elementor', 'elementor-build' ) );
		$request       = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/elementor/clone',
			array( 'source_id' => self::PAGE_ID ),
			array( 'X-API-Key' => $created['key'] )
		);

		$this->assertTrue( $controller->check_permission( $request ) );

		$before_count = count( $GLOBALS['_mcpwp_test_posts'] );
		$result       = $controller->clone_page( $request );

		$this->assertInstanceOf( WP_Error::class, $result, 'clone_page() must refuse rather than materialize the gated reference on a new, fully-readable post.' );
		$this->assertSame( 'not_found', $result->get_error_code() );
		$this->assertCount( $before_count, $GLOBALS['_mcpwp_test_posts'], 'No new post must have been created.' );
	}

	public function test_pro_clone_page_allows_source_with_no_template_reference(): void {
		$elementor_pro = new Mcpwp_Elementor_Pro();
		$controller    = new Mcpwp_REST_Elementor_Pro( $elementor_pro );
		$created       = $controller->create_scoped_api_key( 'no templates', array( 'read', 'write' ), 'custom', array( 'elementor', 'elementor-build' ) );
		$request       = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/elementor/clone',
			array( 'source_id' => self::PAGE_ID ),
			array( 'X-API-Key' => $created['key'] )
		);

		$this->assertTrue( $controller->check_permission( $request ) );
		$result = $controller->clone_page( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $result, 'A plain page with no embedded template reference must clone normally.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_theme_builder_create_template_refuses_gated_template_reference(): void {
		// is_available() gates create_theme_template() before this fix's own
		// check ever runs — the fixture's namespaced class is enough to
		// satisfy it; no working Elementor\Plugin instance is needed since
		// the #1032 check now runs before wp_insert_post(), let alone any
		// Document::save() call.
		if ( ! defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			define( 'ELEMENTOR_PRO_VERSION', '4.2.3' );
		}
		require_once __DIR__ . '/fixtures/theme-builder-elementor-doubles.php';

		$theme_builder = new Mcpwp_Theme_Builder();
		$controller    = new Mcpwp_REST_Theme_Builder( $theme_builder );
		$this->force_key_record( $controller, array( 'elementor-theme' ) );

		// Wired directly (#1032 round 2: check_permission() would ALSO
		// refuse this same payload itself, through the request-body
		// chokepoint, conditionally skipping its own gate-wiring side
		// effect — irrelevant here, since create_theme_template() is called
		// directly, below, exercising THIS handler's own round-1 check in
		// isolation).
		$theme_builder->set_template_ref_gate( $controller->template_ref_gate() );

		$before_count = count( $GLOBALS['_mcpwp_test_posts'] );
		$result       = $theme_builder->create_theme_template(
			array(
				'title'          => 'New Header',
				'type'           => 'header',
				'elementor_data' => $this->tree_with_template_widget( self::ARCHETYPE_ID ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
		$this->assertCount( $before_count, $GLOBALS['_mcpwp_test_posts'], 'No draft template post must have been left behind.' );
	}

	// =========================================================================
	// Adversarial review round 1 — W1: landing-page orphan
	// =========================================================================

	public function test_create_landing_page_refuses_and_leaves_no_orphan_page(): void {
		$GLOBALS['mcpwp_test_did_action']['elementor/loaded'] = 1;
		$elementor_pro = new Mcpwp_Elementor_Pro();
		$controller    = new Mcpwp_REST_Elementor_Pro( $elementor_pro );
		$this->force_key_record( $controller, array( 'elementor', 'elementor-build' ) );
		// Wired directly (#1032 round 2: check_permission() would ALSO
		// refuse this same payload itself, through the request-body
		// chokepoint, conditionally skipping its own gate-wiring side
		// effect — irrelevant here; this test exercises W1's own round-1
		// fix in isolation).
		$elementor_pro->set_template_ref_gate( $controller->template_ref_gate() );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/elementor/landing-page',
			array(
				'title'          => 'LP',
				'elementor_data' => $this->tree_with_template_widget( self::ARCHETYPE_ID ),
			)
		);

		$before_count = count( $GLOBALS['_mcpwp_test_posts'] );
		$result       = $controller->create_landing_page( $request );

		$this->assertInstanceOf( WP_Error::class, $result, 'create_landing_page() must refuse before any page is created.' );
		$this->assertSame( 'not_found', $result->get_error_code(), 'Must be the #1032 gate refusing, not an unrelated "Elementor inactive" error.' );
		$this->assertCount( $before_count, $GLOBALS['_mcpwp_test_posts'], 'No orphan landing page may exist after the refusal — the #1032 W1 bug wrote it into meta_input before this gate ran.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_create_landing_page_succeeds_with_readable_template(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '3.0' );
		}
		$GLOBALS['mcpwp_test_did_action']['elementor/loaded'] = 1;
		$elementor_pro = new Mcpwp_Elementor_Pro();
		$controller    = new Mcpwp_REST_Elementor_Pro( $elementor_pro );
		$created       = $controller->create_scoped_api_key( 'full', array( 'read', 'write' ), 'custom', array( 'elementor', 'elementor-build', 'elementor-templates' ) );
		$request       = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/elementor/landing-page',
			array(
				'title'          => 'LP',
				'elementor_data' => $this->tree_with_template_widget( self::ARCHETYPE_ID ),
			),
			array( 'X-API-Key' => $created['key'] )
		);
		$this->assertTrue( $controller->check_permission( $request ) );

		$result = $controller->create_landing_page( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $result, 'A readable template reference must not be refused.' );
	}

	// =========================================================================
	// Adversarial review round 1 — B3: `[elementor-template]` shortcode
	// =========================================================================

	public function test_guard_elementor_template_shortcode_blocks_unreadable_id(): void {
		$controller = new Mcpwp_REST_Elementor();
		$this->force_key_record( $controller, array( 'elementor' ) ); // no elementor-templates

		$result = $controller->guard_elementor_template_shortcode( false, 'elementor-template', array( 'id' => (string) self::ARCHETYPE_ID ), array() );
		$this->assertSame( '', $result, 'A gated template named by the shortcode must render empty.' );
	}

	public function test_guard_elementor_template_shortcode_passes_readable_id(): void {
		$controller = new Mcpwp_REST_Elementor();
		$this->force_key_record( $controller, array( 'elementor', 'elementor-templates' ) );

		$result = $controller->guard_elementor_template_shortcode( false, 'elementor-template', array( 'id' => (string) self::ARCHETYPE_ID ), array() );
		$this->assertFalse( $result, 'A readable template must run through normal shortcode processing (unchanged $return).' );
	}

	public function test_guard_elementor_template_shortcode_ignores_other_tags(): void {
		$controller = new Mcpwp_REST_Elementor();
		$this->force_key_record( $controller, array( 'elementor' ) );

		$result = $controller->guard_elementor_template_shortcode( false, 'gallery', array( 'id' => self::ARCHETYPE_ID ), array() );
		$this->assertFalse( $result, 'A non-Template-Library shortcode must be left alone entirely.' );
	}

	public function test_create_post_refuses_content_with_unreadable_template_shortcode(): void {
		$controller = new Mcpwp_REST_Posts();
		$created    = $controller->create_scoped_api_key( 'author, no templates', array( 'read', 'write' ), 'custom', array( 'content' ) );
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/posts',
			array(
				'title'   => 'Post with shortcode',
				'content' => 'Hello [elementor-template id="' . self::ARCHETYPE_ID . '"] world',
			),
			array( 'X-API-Key' => $created['key'] )
		);
		// #1032 round 2: check_permission() may now ALSO refuse this same
		// content via the request-body chokepoint — side effect wiring only
		// here; the handler call below is unconditional.
		$controller->check_permission( $request );

		$result = $controller->create_post( $request );

		$this->assertInstanceOf( WP_Error::class, $result, 'post_content naming a gated template via the shortcode must be refused — no Elementor data is involved at all.' );
		$this->assertSame( 'not_found', $result->get_error_code() );
	}

	public function test_update_page_refuses_content_with_unreadable_template_shortcode(): void {
		$controller = new Mcpwp_REST_Pages();
		$created    = $controller->create_scoped_api_key( 'author, no templates', array( 'read', 'write' ), 'custom', array( 'content' ) );
		$request    = new WP_REST_Request(
			'PUT',
			'/mcpwp/v1/pages/' . self::PAGE_ID,
			array(
				'id'      => self::PAGE_ID,
				'content' => '[elementor-template id="' . self::ARCHETYPE_ID . '"]',
			),
			array( 'X-API-Key' => $created['key'] )
		);
		$controller->check_permission( $request );

		$result = $controller->update_page( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
	}

	public function test_create_post_allows_content_with_readable_template_shortcode(): void {
		$controller = new Mcpwp_REST_Posts();
		$created    = $controller->create_scoped_api_key( 'author, full', array( 'read', 'write' ), 'custom', array( 'content', 'elementor-templates' ) );
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/posts',
			array(
				'title'   => 'Post with shortcode',
				'content' => 'Hello [elementor-template id="' . self::ARCHETYPE_ID . '"] world',
			),
			array( 'X-API-Key' => $created['key'] )
		);
		$this->assertTrue( $controller->check_permission( $request ) );

		$result = $controller->create_post( $request );

		$this->assertNotInstanceOf( WP_Error::class, $result, 'A readable template named by the shortcode must not be refused.' );
	}

	public function test_write_refuses_dynamic_tag_encoded_shortcode_reference(): void {
		// Elementor Pro's "Shortcode" dynamic tag calls do_shortcode() on an
		// arbitrary settings.shortcode string — a widget setting can carry
		// one WITHOUT ever naming a known reference key.
		$elementor = new Mcpwp_Elementor_Basic();
		$elementor->set_category_allowed_callback( function () { return true; } );
		$elementor->set_template_ref_gate(
			function ( array $ids ) {
				return in_array( self::ARCHETYPE_ID, $ids, true )
					? new WP_Error( 'not_found', 'The requested resource was not found.', array( 'status' => 404, 'hint' => 'Check the id with wp_search or wp_list_posts.' ) )
					: true;
			}
		);

		$payload = urlencode( json_encode( array( 'shortcode' => '[elementor-template id="' . self::ARCHETYPE_ID . '"]' ) ) );
		$element = array(
			'id'         => 'dyn00001',
			'elType'     => 'widget',
			'widgetType' => 'heading',
			'settings'   => array(
				'title' => '[elementor-tag id="abc1234" name="shortcode" settings="' . $payload . '"]',
			),
		);
		$result = $elementor->add_section( self::PAGE_ID, array( 'element' => $element ) );
		$this->assert_canonical_not_found( $result, 'A Shortcode dynamic tag whose own settings.shortcode names a gated template must be refused.' );
	}

	// =========================================================================
	// Adversarial review round 1 — NIT: loop-item / elementor-theme
	// =========================================================================

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_loop_item_template_readable_by_elementor_theme_only_key(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '3.0' );
		}
		// A key holding elementor-theme but NOT elementor-templates must
		// still be able to embed a loop-item (theme-builder) template via a
		// Loop Grid on an ordinary /elementor/{id} write — loop-item is a
		// theme-builder location, not a template-library section, regardless
		// of which door the reference travels through.
		$GLOBALS['_mcpwp_test_meta'][ self::ARCHETYPE_ID ]['_elementor_template_type'] = 'loop-item';

		$controller = new Mcpwp_REST_Elementor();
		$created    = $controller->create_scoped_api_key( 'theme only', array( 'read', 'write' ), 'custom', array( 'elementor', 'elementor-theme' ) );
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/elementor/' . self::PAGE_ID,
			array(
				'id'             => self::PAGE_ID,
				'elementor_data' => array(
					array(
						'id'         => 'lg000002',
						'elType'     => 'widget',
						'widgetType' => 'loop-grid',
						'settings'   => array( 'template_id' => self::ARCHETYPE_ID ),
					),
				),
			),
			array( 'X-API-Key' => $created['key'] )
		);
		$this->assertTrue( $controller->check_permission( $request ) );

		$result = $controller->set_elementor_data( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $result, 'A loop-item theme-builder template must be readable by an elementor-theme-only key, regardless of which route embeds the reference.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_ordinary_section_template_still_refused_for_elementor_theme_only_key(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '3.0' );
		}
		// Regression guard for the NIT fix above: an ORDINARY (non-theme-
		// location) template must still require elementor-templates, not be
		// waved through just because template_ref_gate() now always checks
		// under 'elementor-theme'.
		$controller = new Mcpwp_REST_Elementor();
		$created    = $controller->create_scoped_api_key( 'theme only', array( 'read', 'write' ), 'custom', array( 'elementor', 'elementor-theme' ) );
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/elementor/' . self::PAGE_ID,
			array(
				'id'             => self::PAGE_ID,
				'elementor_data' => $this->tree_with_template_widget( self::ARCHETYPE_ID ),
			),
			array( 'X-API-Key' => $created['key'] )
		);
		// #1032 round 2: check_permission() itself now refuses this via the
		// request-body chokepoint, earlier than the handler's own round-1
		// check — both must agree, so asserting on check_permission()'s own
		// verdict here proves the SAME 'elementor-theme' classification rule
		// governs both layers.
		$auth = $controller->check_permission( $request );

		$this->assertInstanceOf( WP_Error::class, $auth, 'An ordinary section template must still require elementor-templates.' );
		$this->assertSame( 'not_found', $auth->get_error_code() );
	}

	// =========================================================================
	// Adversarial review round 2 — the request-body-wide chokepoint
	// (Mcpwp_Api_Auth::check_request_template_refs()). The mechanism is
	// route-agnostic (it walks $request->get_params() regardless of which
	// controller/handler eventually runs), so these use whichever loaded
	// controller matches the route's own category — the SAME check_permission()
	// every REST controller shares, not a per-controller reimplementation.
	// =========================================================================

	private function assert_request_refused( object $controller, WP_REST_Request $request, string $context = '' ): void {
		$auth = $controller->check_permission( $request );
		$this->assertInstanceOf( WP_Error::class, $auth, $context );
		$this->assertSame( 'not_found', $auth->get_error_code(), $context );
		$this->assertSame( 'A referenced template was not found.', $auth->get_error_data()['hint'] ?? null, $context );
	}

	private function assert_request_allowed( object $controller, WP_REST_Request $request, string $context = '' ): void {
		$this->assertTrue( $controller->check_permission( $request ), $context );
	}

	/**
	 * @return array{0:object,1:WP_REST_Request}
	 */
	private function authed_request( object $controller, array $categories, string $method, string $route, array $params ): array {
		$created = $controller->create_scoped_api_key( 'test', array( 'read', 'write' ), 'custom', $categories );
		$request = new WP_REST_Request( $method, $route, $params, array( 'X-API-Key' => $created['key'] ) );
		return array( $controller, $request );
	}

	public function test_request_scan_catches_sidebar_text_widget_shortcode(): void {
		// A classic sidebar's Text widget instance — no Elementor involved
		// at all. /widgets is 'site' category.
		list( $controller, $request ) = $this->authed_request(
			new Mcpwp_REST_Posts(),
			array( 'site' ), // no elementor-templates
			'PUT',
			'/mcpwp/v1/widgets/text-1',
			array(
				'instance' => array(
					'title' => 'About',
					'text'  => 'Welcome! [elementor-template id="' . self::ARCHETYPE_ID . '"]',
				),
			)
		);
		$this->assert_request_refused( $controller, $request, 'A sidebar Text widget instance is not Elementor data, but the shortcode inside it must still be caught.' );
	}

	public function test_request_scan_catches_pro_elementor_library_widget_template_id(): void {
		// A classic sidebar's Elementor Pro "Elementor Library" widget —
		// its instance settings carry template_id directly, not inside any
		// _elementor_data tree.
		list( $controller, $request ) = $this->authed_request(
			new Mcpwp_REST_Posts(),
			array( 'site' ),
			'PUT',
			'/mcpwp/v1/widgets/elementor-library-2',
			array( 'instance' => array( 'template_id' => self::ARCHETYPE_ID ) )
		);
		$this->assert_request_refused( $controller, $request );
	}

	public function test_request_scan_catches_woocommerce_product_description(): void {
		list( $controller, $request ) = $this->authed_request(
			new Mcpwp_REST_Posts(),
			array( 'woocommerce' ),
			'POST',
			'/mcpwp/v1/woocommerce/products',
			array(
				'name'              => 'Widget',
				'description'       => 'Full desc [elementor-template id="' . self::ARCHETYPE_ID . '"]',
				'short_description' => 'Short desc',
			)
		);
		$this->assert_request_refused( $controller, $request );
	}

	public function test_request_scan_catches_blocks_queued_for_approval(): void {
		list( $controller, $request ) = $this->authed_request(
			new Mcpwp_REST_Posts(),
			array( 'gutenberg' ),
			'PUT',
			'/mcpwp/v1/blocks/' . self::PAGE_ID,
			array( 'blocks' => '<!-- wp:paragraph --><p>[elementor-template id="' . self::ARCHETYPE_ID . '"]</p><!-- /wp:paragraph -->' )
		);
		$this->assert_request_refused( $controller, $request );
	}

	public function test_request_scan_catches_content_graph_apply_link_anchor(): void {
		list( $controller, $request ) = $this->authed_request(
			new Mcpwp_REST_Posts(),
			array( 'content' ),
			'POST',
			'/mcpwp/v1/content-graph/apply-link',
			array(
				'source_id' => self::PAGE_ID,
				'target_id' => self::PAGE_ID,
				'anchor'    => 'see [elementor-template id="' . self::ARCHETYPE_ID . '"]',
			)
		);
		$this->assert_request_refused( $controller, $request );
	}

	public function test_request_scan_catches_media_description(): void {
		list( $controller, $request ) = $this->authed_request(
			new Mcpwp_REST_Posts(),
			array( 'media' ),
			'PUT',
			'/mcpwp/v1/media/9',
			array( 'description' => 'Photo credit [elementor-template id="' . self::ARCHETYPE_ID . '"]' )
		);
		$this->assert_request_refused( $controller, $request );
	}

	public function test_request_scan_catches_popup_dynamic_tag(): void {
		$payload = urlencode( json_encode( array( 'action' => 'open', 'popup' => (string) self::ARCHETYPE_ID ) ) );
		list( $controller, $request ) = $this->authed_request(
			new Mcpwp_REST_Posts(),
			array( 'site' ),
			'PUT',
			'/mcpwp/v1/widgets/button-1',
			array( 'instance' => array( 'link' => '[elementor-tag id="abc1234" name="popup" settings="' . $payload . '"]' ) )
		);
		$this->assert_request_refused( $controller, $request, 'The Popup dynamic tag names a template via its own settings.popup key.' );
	}

	public function test_request_scan_allows_escaped_shortcode(): void {
		// [[elementor-template ...]] is WordPress's own escape syntax — never
		// processed, so it names no template at all.
		list( $controller, $request ) = $this->authed_request(
			new Mcpwp_REST_Posts(),
			array( 'site' ),
			'PUT',
			'/mcpwp/v1/widgets/text-3',
			array( 'instance' => array( 'text' => 'Example syntax: [[elementor-template id="' . self::ARCHETYPE_ID . '"]]' ) )
		);
		$this->assert_request_allowed( $controller, $request, 'An escaped shortcode must not be treated as a reference.' );
	}

	public function test_request_scan_allows_new_content_with_no_reference(): void {
		list( $controller, $request ) = $this->authed_request(
			new Mcpwp_REST_Posts(),
			array( 'site' ),
			'PUT',
			'/mcpwp/v1/widgets/text-4',
			array( 'instance' => array( 'text' => 'Nothing suspicious here.' ) )
		);
		$this->assert_request_allowed( $controller, $request );
	}

	public function test_request_scan_does_not_re_judge_a_preexisting_reference(): void {
		// #1032 round 2 false-positives NIT: a page that ALREADY carries a
		// gated reference (built by an admin, say) must not lock a designer
		// key out of editing anything ELSE on that same page — only a NEWLY
		// introduced reference is judged.
		$GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] = json_encode( $this->tree_with_template_widget( self::ARCHETYPE_ID ) );

		list( $controller, $request ) = $this->authed_request(
			new Mcpwp_REST_Elementor(),
			array( 'elementor' ), // no elementor-templates
			'PUT',
			'/mcpwp/v1/elementor/' . self::PAGE_ID . '/edit-widget',
			array(
				'id'        => self::PAGE_ID,
				'widget_id' => 'tpl00001',
				// Resubmits the widget's UNCHANGED template_id (the id
				// already stored, pre-existing) alongside a genuinely new,
				// unrelated setting — the realistic "edit one thing, resend
				// the widget" shape.
				'settings'  => array(
					'template_id'            => self::ARCHETYPE_ID,
					'some_unrelated_setting' => 'new value',
				),
			)
		);
		$this->assert_request_allowed( $controller, $request, 'Editing something unrelated on a page that already held a gated reference before this write must not be locked out.' );
	}

	public function test_request_scan_still_refuses_a_newly_added_reference_on_a_page_with_a_preexisting_one(): void {
		// Companion to the test above: the exemption is per-id, not
		// per-page — adding a SECOND, different gated reference on the same
		// page is still refused.
		$GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] = json_encode( $this->tree_with_template_widget( self::ARCHETYPE_ID ) );

		$second_archetype_id                                   = self::ARCHETYPE_ID + 500;
		$GLOBALS['_mcpwp_test_posts'][ $second_archetype_id ] = new WP_Post(
			array(
				'ID'          => $second_archetype_id,
				'post_type'   => 'elementor_library',
				'post_title'  => 'Second Gated Section',
				'post_name'   => 'second-gated-section',
				'post_status' => 'publish',
			)
		);

		list( $controller, $request ) = $this->authed_request(
			new Mcpwp_REST_Elementor(),
			array( 'elementor' ),
			'POST',
			'/mcpwp/v1/elementor/' . self::PAGE_ID . '/add-section',
			array(
				'id'      => self::PAGE_ID,
				'element' => $this->tree_with_template_widget( $second_archetype_id )[0],
			)
		);
		$this->assert_request_refused( $controller, $request );
	}

	public function test_mcp_route_is_skipped_by_the_request_scan(): void {
		// The outer /mcp envelope is never scanned directly — the
		// re-dispatched sub-request (the real route, with the real,
		// already-unwrapped tool arguments) runs this same check itself.
		list( $controller, $request ) = $this->authed_request(
			new Mcpwp_REST_Posts(),
			array( 'content' ),
			'POST',
			'/mcpwp/v1/mcp',
			array(
				'jsonrpc' => '2.0',
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'wp_update_post',
					'arguments' => array(
						'id'      => self::PAGE_ID,
						'content' => '[elementor-template id="' . self::ARCHETYPE_ID . '"]',
					),
				),
			)
		);
		$this->assert_request_allowed( $controller, $request, 'The outer /mcp envelope must not be scanned; its re-dispatched sub-request is.' );
	}

	public function test_batch_route_is_skipped_by_the_request_scan(): void {
		list( $controller, $request ) = $this->authed_request(
			new Mcpwp_REST_Posts(),
			array( 'content' ),
			'POST',
			'/mcpwp/v1/batch',
			array(
				'operations' => array(
					array(
						'method' => 'PUT',
						'path'   => '/posts/' . self::PAGE_ID,
						'body'   => array( 'content' => '[elementor-template id="' . self::ARCHETYPE_ID . '"]' ),
					),
				),
			)
		);
		$this->assert_request_allowed( $controller, $request, 'The outer /batch envelope must not be scanned; each re-dispatched sub-request is.' );
	}

	public function test_request_scan_refuses_oversized_payload(): void {
		list( $controller, $request ) = $this->authed_request(
			new Mcpwp_REST_Posts(),
			array( 'content' ),
			'PUT',
			'/mcpwp/v1/posts/' . self::PAGE_ID,
			array( 'content' => str_repeat( 'a', 6 * 1024 * 1024 ) )
		);
		$auth = $controller->check_permission( $request );
		$this->assertInstanceOf( WP_Error::class, $auth );
		$this->assertSame( 'payload_too_large', $auth->get_error_code() );
		$this->assertSame( 413, $auth->get_error_data()['status'] ?? null );
	}
}
