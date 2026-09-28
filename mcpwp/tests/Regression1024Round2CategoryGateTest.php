<?php
/**
 * Regression tests — round-1 adversarial review of PR #1024 (this same
 * chokepoint-reuse fix). Every finding is the SAME class of bug #1024 itself
 * fixed elsewhere: a door that resolves a post by numeric ID, or accepts a
 * caller-named post_type/template_id, with no category_for_post_type()/
 * caller_may_read_category() check of its own.
 *
 * BLOCK-1: add_section/remove_section/replace_section/patch_elementor
 * (trait-mcpwp-elementor-writer.php) and regenerate_css()
 * (trait-mcpwp-elementor-css.php) resolved their target post with a bare
 * get_post()+exists check — never validate_post() — so #1024's own fix to
 * validate_post() never reached them. A designer key could
 * POST /elementor/{product_id}/add-section and write into a WooCommerce
 * product's _elementor_data; with elementor-templates disabled site-wide, an
 * admin+designer key could still POST /elementor/{template_id}/replace-section.
 * Fixed by routing all four (plus regenerate_css) through validate_post(),
 * the SAME chokepoint every other Elementor door already uses.
 *
 * BLOCK-2: GET /graph (Mcpwp_REST_Graph::get_summary(), also the
 * mcpwp://graph resource) returned `nodes_by_kind` and `top_orphans`
 * completely unfiltered. Fixed by threading `$excluded_kinds` into
 * Mcpwp_Graph_Reader::resource_summary() itself (SQL-level, not a
 * REST-layer post-filter).
 *
 * WARN-1: /seo/content-quality/{id}, /seo/readiness/{id},
 * /seo/structured-data/{id}, /seo/media/{id} (Free) and Pro's generic
 * /seo/{id} (get/update) + /seo/report?post_type= resolved/accepted ANY post
 * type with no category check. Fixed via guard_post_type_category() (Free)
 * and a matching same-shape check in Mcpwp_REST_SEO (Pro, whose business
 * class Mcpwp_SEO has no REST/auth context of its own).
 *
 * WARN-2: /seo/audit's own direct get_posts() call defaulted an
 * all-filtered-out $post_types to WordPress's own "empty array means every
 * post" behaviour, and hardcoded page/post back into the internal link
 * graph's post-type set regardless of category. Fixed with the same
 * empty-guard build_content_graph_data() already uses, plus category-filtering
 * the page/post re-addition.
 *
 * WARN-3: Pro's clone_page()/create_landing_page() (template_id)/
 * save_section_as_template() read an arbitrary source/template post with no
 * category check — WooCommerce product meta could be cloned, or an
 * elementor-templates-disabled template's data re-saved as a new, fully
 * readable one. Fixed at the REST layer (Mcpwp_REST_Elementor_Pro), matching
 * each door's own existing not-found error shape exactly.
 *
 * WARN-4: /content-graph/validate-links's resolve_internal_link_for_validation()
 * falls back to url_to_postid(), which can resolve to ANY post on the site —
 * disclosing a gated target's existence, publish status, and canonical URL
 * via the 'unpublished_target'/'non_canonical_url' issues. Fixed by treating
 * a category-denied target exactly like a missing one.
 *
 * NIT: bulk find-replace's not_touched reason for a category-denied id was
 * 'type_not_supported', which still discloses that the id resolves to
 * something. Changed to 'not_found', identical to a genuinely missing id.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-elementor-basic.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-elementor.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-content-quality.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-seo-audit.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-content-graph.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-graph.php';
require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-seo.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-approvals.php';

final class Regression1024Round2CategoryGateTest extends TestCase {

	private const PAGE_ID      = 9201;
	private const PRODUCT_ID   = 9202;
	private const ARCHETYPE_ID = 9203; // elementor_library
	private const TARGET_ID    = 9204; // published page, a link target

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']                 = array();
		$GLOBALS['mcpwp_test_transients']               = array();
		$GLOBALS['mcpwp_test_current_user']             = 0;
		$GLOBALS['mcpwp_test_is_admin']                 = false;
		$GLOBALS['mcpwp_test_filters']                  = array();
		$GLOBALS['_mcpwp_test_meta']                    = array();
		$GLOBALS['mcpwp_test_public_post_types']        = array( 'product', 'elementor_library' );
		$GLOBALS['mcpwp_test_post_type_supports']       = array( 'product' => array( 'thumbnail' ) );
		$GLOBALS['mcpwp_test_show_in_rest_post_types']  = array();

		$GLOBALS['_mcpwp_test_posts'] = array(
			self::PAGE_ID      => new WP_Post(
				array(
					'ID'           => self::PAGE_ID,
					'post_type'    => 'page',
					'post_title'   => 'About',
					'post_name'    => 'about',
					'post_status'  => 'publish',
					'post_content' => 'Plain page body with enough words to pass a minimal length check for the content quality audit surface being exercised here today.',
				)
			),
			self::PRODUCT_ID   => new WP_Post(
				array(
					'ID'           => self::PRODUCT_ID,
					'post_type'    => 'product',
					'post_title'   => 'Woolly Hoodie',
					'post_name'    => 'woolly-hoodie',
					'post_status'  => 'publish',
					'post_content' => 'SECRET margin notes in the product body.',
				)
			),
			self::ARCHETYPE_ID => new WP_Post(
				array(
					'ID'           => self::ARCHETYPE_ID,
					'post_type'    => 'elementor_library',
					'post_title'   => 'Apparel Archetype',
					'post_name'    => 'apparel-archetype',
					'post_status'  => 'publish',
					'post_content' => '',
				)
			),
			self::TARGET_ID    => new WP_Post(
				array(
					'ID'           => self::TARGET_ID,
					'post_type'    => 'page',
					'post_title'   => 'Chapter 7',
					'post_name'    => 'chapter-7',
					'post_status'  => 'publish',
					'post_content' => 'Target body.',
				)
			),
		);

		$GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data']    = json_encode( $this->tree( 'Old Brand' ) );
		$GLOBALS['_mcpwp_test_meta'][ self::ARCHETYPE_ID ]['_elementor_data'] = json_encode( $this->tree( 'Old Brand' ) );
		$GLOBALS['_mcpwp_test_meta'][ self::PRODUCT_ID ]['_elementor_data']  = json_encode( $this->tree( 'Old Brand' ) );
	}

	protected function tearDown(): void {
		update_option( 'mcpwp_disabled_tool_categories', array() );
	}

	private function tree( string $title ): array {
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
								'settings'   => array( 'title' => $title ),
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

	private function assert_not_found( $result, string $context = '' ): void {
		$this->assertInstanceOf( WP_Error::class, $result, $context );
		$this->assertSame( 'not_found', $result->get_error_code(), $context );
	}

	// =========================================================================
	// BLOCK-1: add_section / remove_section / replace_section / patch_elementor
	// / regenerate_css now route through validate_post()
	// =========================================================================

	public function test_add_section_denies_product_for_content_only_key(): void {
		$elementor = new Mcpwp_Elementor_Basic();
		$elementor->set_category_allowed_callback(
			function ( $post_type ) {
				return in_array( $post_type, array( 'elementor' ), true ); // no woocommerce
			}
		);

		$before = $GLOBALS['_mcpwp_test_meta'][ self::PRODUCT_ID ]['_elementor_data'];
		$result = $elementor->add_section( self::PRODUCT_ID, array( 'element' => array( 'elType' => 'section' ) ) );

		$this->assert_not_found( $result, 'add_section on a product must be refused, exactly like validate_post()\'s own denial.' );
		$this->assertSame( $before, $GLOBALS['_mcpwp_test_meta'][ self::PRODUCT_ID ]['_elementor_data'], 'Nothing must have been written.' );
	}

	public function test_remove_section_denies_archetype_when_elementor_templates_missing(): void {
		$elementor = new Mcpwp_Elementor_Basic();
		$elementor->set_category_allowed_callback(
			function ( $post_type ) {
				return in_array( $post_type, array( 'elementor' ), true ); // no elementor-templates
			}
		);

		$result = $elementor->remove_section( self::ARCHETYPE_ID, array( 'element_id' => 'head0001' ) );
		$this->assert_not_found( $result );
	}

	public function test_replace_section_denies_archetype_when_elementor_templates_missing(): void {
		$elementor = new Mcpwp_Elementor_Basic();
		$elementor->set_category_allowed_callback(
			function ( $post_type ) {
				return in_array( $post_type, array( 'elementor' ), true );
			}
		);

		$result = $elementor->replace_section(
			self::ARCHETYPE_ID,
			array( 'element_id' => 'head0001', 'element' => array( 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => 'HACKED' ) ) )
		);
		$this->assert_not_found( $result );
	}

	public function test_patch_elementor_denies_product_for_content_only_key(): void {
		$elementor = new Mcpwp_Elementor_Basic();
		$elementor->set_category_allowed_callback(
			function ( $post_type ) {
				return in_array( $post_type, array( 'elementor' ), true );
			}
		);

		$result = $elementor->patch_elementor( self::PRODUCT_ID, array( 'operations' => array( array( 'op' => 'set', 'element_id' => 'head0001', 'settings' => array( 'title' => 'HACKED' ) ) ) ) );
		$this->assert_not_found( $result );
	}

	public function test_add_section_still_allows_a_plain_page_for_elementor_only_key(): void {
		// Designer-role regression guard: the fix must not impose a 'content'
		// requirement on page/post through this door.
		$elementor = new Mcpwp_Elementor_Basic();
		$elementor->set_category_allowed_callback(
			function ( $post_type ) {
				return in_array( $post_type, array( 'elementor' ), true ); // no content
			}
		);

		$result = $elementor->add_section( self::PAGE_ID, array( 'element' => array( 'elType' => 'section', 'id' => 'sec99999' ) ) );
		$this->assertIsArray( $result, 'A plain page must still be writable by an elementor-only key.' );
		$this->assertTrue( $result['success'] ?? false );
	}

	/**
	 * Isolated: defines ELEMENTOR_VERSION and declares \Elementor\Plugin,
	 * both process-global and permanent once declared. Run in a separate
	 * process so this never changes which branch a LATER test in the same
	 * shared PHPUnit process takes — exactly the class of bug #757 already
	 * fixed once for this same constant (RestElementorDraftLeakTest's
	 * assertPassedGate() docblock).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_regenerate_css_denies_product_for_content_only_key(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '3.0' );
		}
		require_once __DIR__ . '/fixtures/elementor-plugin-minimal-double.php';
		$elementor = new Mcpwp_Elementor_Basic();
		$elementor->set_category_allowed_callback(
			function ( $post_type ) {
				return in_array( $post_type, array( 'elementor' ), true );
			}
		);

		$result = $elementor->regenerate_css( self::PRODUCT_ID );
		$this->assert_not_found( $result );
	}

	// =========================================================================
	// BLOCK-2: GET /graph summary filters nodes_by_kind / top_orphans / total
	// =========================================================================

	public function test_resource_summary_drops_excluded_kind_rows(): void {
		$rc     = new ReflectionClass( 'Mcpwp_Graph_Reader' );
		$method = $rc->getMethod( 'kind_excluded_sql' );
		$method->setAccessible( true );

		list( $sql, $params ) = $method->invoke( null, 'n.kind', array( 'product', 'shop_order' ) );
		$this->assertStringContainsString( 'NOT IN', $sql );
		$this->assertSame( array( 'product', 'shop_order' ), $params );

		list( $noop_sql, $noop_params ) = $method->invoke( null, 'n.kind', array() );
		$this->assertSame( '1=1', $noop_sql );
		$this->assertSame( array(), $noop_params );
	}

	public function test_edge_endpoints_kind_excluded_sql_checks_both_sides(): void {
		$rc     = new ReflectionClass( 'Mcpwp_Graph_Reader' );
		$method = $rc->getMethod( 'edge_endpoints_kind_excluded_sql' );
		$method->setAccessible( true );

		list( $sql, $params ) = $method->invoke( null, 'e', array( 'product' ) );
		$this->assertStringContainsString( 'e.from_key', $sql );
		$this->assertStringContainsString( 'e.to_key', $sql );
		$this->assertSame( array( 'product', 'product' ), $params );
	}

	public function test_rest_graph_computes_excluded_kinds_from_held_categories(): void {
		$controller = new Mcpwp_REST_Graph();
		$this->force_key_record( $controller, array( 'content' ) ); // no woocommerce

		$rc     = new ReflectionClass( $controller );
		$method = $rc->getMethod( 'category_excluded_kinds' );
		$method->setAccessible( true );
		$excluded = $method->invoke( $controller );

		$this->assertContains( 'product', $excluded );
		$this->assertNotContains( 'page', $excluded );
	}

	// =========================================================================
	// WARN-1: SEO id-based doors gate by category
	// =========================================================================

	public function test_content_quality_denies_product_for_content_only_key(): void {
		$controller = new Mcpwp_REST_Content_Quality( false );
		$this->force_key_record( $controller, array( 'seo', 'content' ) ); // no woocommerce
		$request = new WP_REST_Request( 'GET', '/mcpwp/v1/seo/content-quality/' . self::PRODUCT_ID, array( 'id' => self::PRODUCT_ID ) );

		$this->assert_not_found( $controller->audit_content_quality( $request ) );
	}

	public function test_content_quality_allowed_for_a_plain_page(): void {
		$controller = new Mcpwp_REST_Content_Quality( false );
		$this->force_key_record( $controller, array( 'seo', 'content' ) );
		$request = new WP_REST_Request( 'GET', '/mcpwp/v1/seo/content-quality/' . self::PAGE_ID, array( 'id' => self::PAGE_ID ) );

		$this->assertInstanceOf( WP_REST_Response::class, $controller->audit_content_quality( $request ) );
	}

	public function test_seo_readiness_denies_product_for_content_only_key(): void {
		$controller = new Mcpwp_REST_SEO_Audit();
		$this->force_key_record( $controller, array( 'seo', 'content' ) );
		$request = new WP_REST_Request( 'GET', '/mcpwp/v1/seo/readiness/' . self::PRODUCT_ID, array( 'id' => self::PRODUCT_ID ) );

		$this->assert_not_found( $controller->validate_seo_readiness( $request ) );
	}

	public function test_structured_data_denies_product_for_content_only_key(): void {
		$controller = new Mcpwp_REST_SEO_Audit();
		$this->force_key_record( $controller, array( 'seo', 'content' ) );
		$request = new WP_REST_Request( 'GET', '/mcpwp/v1/seo/structured-data/' . self::PRODUCT_ID, array( 'id' => self::PRODUCT_ID ) );

		$this->assert_not_found( $controller->validate_structured_data( $request ) );
	}

	public function test_media_seo_denies_product_for_content_only_key(): void {
		$controller = new Mcpwp_REST_SEO_Audit();
		$this->force_key_record( $controller, array( 'seo', 'content' ) );
		$request = new WP_REST_Request( 'GET', '/mcpwp/v1/seo/media/' . self::PRODUCT_ID, array( 'id' => self::PRODUCT_ID ) );

		$this->assert_not_found( $controller->audit_media_seo( $request ) );
	}

	public function test_pro_get_post_seo_denies_product_for_content_only_key(): void {
		$controller = new Mcpwp_REST_SEO( null );
		$this->force_key_record( $controller, array( 'seo', 'content' ) );
		$request = new WP_REST_Request( 'GET', '/mcpwp/v1/seo/' . self::PRODUCT_ID, array( 'id' => self::PRODUCT_ID ) );

		$result = $controller->get_post_seo( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
	}

	public function test_pro_set_noindex_denies_product_for_content_only_key(): void {
		$controller = new Mcpwp_REST_SEO( null );
		$this->force_key_record( $controller, array( 'seo', 'content' ) );
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/seo/' . self::PRODUCT_ID . '/noindex', array( 'id' => self::PRODUCT_ID, 'noindex' => true ) );

		$result = $controller->set_noindex( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] ?? null );
	}

	public function test_pro_export_report_post_type_denied_as_forbidden_category(): void {
		$controller = new Mcpwp_REST_SEO( null );
		$this->force_key_record( $controller, array( 'seo', 'content' ) ); // no woocommerce
		$request = new WP_REST_Request( 'GET', '/mcpwp/v1/seo/report', array( 'post_type' => 'product' ) );

		$result = $controller->export_report( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'forbidden_category', $result->get_error_code() );
	}

	// =========================================================================
	// WARN-2: /seo/audit's own empty-post_types guard + category-filtered
	// page/post re-addition to the internal link graph
	// =========================================================================

	public function test_seo_audit_all_types_excluded_returns_empty_audit_not_a_leak(): void {
		// A 'woocommerce'-only key requesting post_types=page: page needs
		// 'content', which this key does not hold. Before this fix,
		// get_posts(post_type=>[]) silently defaulted to every 'post'.
		$controller = new Mcpwp_REST_SEO_Audit();
		$this->force_key_record( $controller, array( 'seo', 'woocommerce' ) );
		$request = new WP_REST_Request( 'GET', '/mcpwp/v1/seo/audit', array( 'post_types' => 'page', 'limit' => 10 ) );

		$response = $controller->audit_seo_site( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertSame( array(), $data['urls'] );
	}

	public function test_seo_audit_link_graph_never_readds_a_category_denied_page(): void {
		// audit_seo_site() resets bulk_audit_graph to null before returning
		// (its own cleanup), so this asserts through build_content_graph_data()
		// directly with the SAME inputs the fixed line computes: page/post
		// filtered through the caller's own category, merged with the
		// requested type — proving 'page' is never silently re-added for a
		// key that lacks 'content'.
		$controller = new Mcpwp_REST_SEO_Audit();
		$this->force_key_record( $controller, array( 'seo', 'woocommerce' ) ); // no content

		$rc                     = new ReflectionClass( $controller );
		$caller_may_read_category = $rc->getMethod( 'caller_may_read_category' );
		$caller_may_read_category->setAccessible( true );
		$build_content_graph_data = $rc->getMethod( 'build_content_graph_data' );
		$build_content_graph_data->setAccessible( true );

		$link_target_types = array_values(
			array_filter(
				array( 'page', 'post' ),
				function ( $type ) use ( $controller, $caller_may_read_category ) {
					return $caller_may_read_category->invoke( $controller, Mcpwp_REST_SEO_Audit::category_for_post_type( $type ) );
				}
			)
		);
		$this->assertSame( array(), $link_target_types, 'page/post must not be reachable as link targets for a content-less key.' );

		$graph_post_types = array_values( array_unique( array_merge( $link_target_types, array( 'product' ) ) ) );
		$graph            = $build_content_graph_data->invoke( $controller, $graph_post_types, 100, false );
		$types            = array_values( array_unique( array_column( $graph['nodes'], 'type' ) ) );
		$this->assertNotContains( 'page', $types );
	}

	// =========================================================================
	// WARN-3: Pro Elementor doors — clone/landing-page/save-section-as-template
	// =========================================================================

	public function test_pro_clone_page_denies_product_source_for_content_only_key(): void {
		$controller = new Mcpwp_REST_Elementor_Pro( new stdClass() ); // never reached when denied
		$this->force_key_record( $controller, array( 'elementor', 'elementor-build' ) ); // no woocommerce
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/elementor/clone', array( 'source_id' => self::PRODUCT_ID ) );

		$result = $controller->clone_page( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
	}

	public function test_pro_create_landing_page_denies_gated_template_id(): void {
		$controller = new Mcpwp_REST_Elementor_Pro( new stdClass() );
		$this->force_key_record( $controller, array( 'elementor', 'elementor-build' ) ); // no elementor-templates
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/elementor/landing-page', array( 'template_id' => self::ARCHETYPE_ID ) );

		$result = $controller->create_landing_page( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
	}

	public function test_pro_save_section_as_template_denies_product_source(): void {
		$controller = new Mcpwp_REST_Elementor_Pro( new stdClass() );
		$this->force_key_record( $controller, array( 'gutenberg', 'elementor-templates' ) ); // no woocommerce
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/elementor/save-section-as-template', array( 'page_id' => self::PRODUCT_ID, 'element_id' => 'head0001' ) );

		$result = $controller->save_section_as_template( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_page', $result->get_error_code() );
	}

	// =========================================================================
	// WARN-4: /content-graph/validate-links treats a gated target as missing
	// =========================================================================

	public function test_validate_internal_links_treats_gated_target_as_missing(): void {
		$controller = new Mcpwp_REST_Content_Graph();
		$this->force_key_record( $controller, array( 'content' ) ); // no woocommerce

		// A page linking to the product by raw URL (not by graph node, so
		// resolution falls through to url_to_postid()).
		$GLOBALS['_mcpwp_test_posts'][ self::PAGE_ID ]->post_content =
			'<p><a href="https://example.com/?p=' . self::PRODUCT_ID . '">Shop now</a></p>';

		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/content-graph/validate-links', array( 'post_types' => 'page', 'limit' => 100 ) );
		$response = $controller->validate_internal_links( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();

		$codes = array_column( $data['issues'], 'code' );
		$this->assertContains( 'missing_target', $codes, 'A gated target must read exactly like url_to_postid() finding nothing.' );
		$this->assertNotContains( 'unpublished_target', $codes );
		$this->assertNotContains( 'non_canonical_url', $codes, 'No canonical_url of a gated post may ever be disclosed.' );
	}

	// =========================================================================
	// NIT: bulk find-replace not_touched gives a gated id the SAME reason as
	// a missing one
	// =========================================================================

	public function test_bulk_find_replace_not_touched_reason_matches_missing_for_gated_id(): void {
		$controller = new Mcpwp_REST_Elementor();
		$this->force_key_record( $controller, array( 'elementor' ) ); // no elementor-templates
		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/elementor/find-replace',
			array( 'ids' => array( self::ARCHETYPE_ID, 999999 ), 'search' => 'Old Brand', 'replace' => 'New Brand' )
		);

		$response = $controller->find_replace_many( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();

		$reasons = array();
		foreach ( $data['not_touched'] as $row ) {
			$reasons[ $row['id'] ] = $row['reason'];
		}
		$this->assertSame( $reasons[999999] ?? null, $reasons[ self::ARCHETYPE_ID ] ?? null, 'A gated id and a genuinely missing id must give the identical reason.' );
		$this->assertSame( 'not_found', $reasons[ self::ARCHETYPE_ID ] ?? null );
	}
}
