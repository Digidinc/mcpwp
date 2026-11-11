<?php
/**
 * Regression coverage for #828 part 2 and the residual #759 gap in
 * Mcpwp_Theme_Builder::create_theme_template().
 *
 * Reported (GH #828, comment 2026-09-10): "wp_create_theme_template with
 * elementor_data returned 500 but still created and published the post" — a
 * Theme Builder header/footer template went live, empty, site-wide, because
 * the post was inserted with post_status=publish BEFORE its Elementor data
 * was saved, and the save itself was a bare Document::save() call with no
 * Throwable handling and no suspension of Elementor's own content-sanitizer
 * module (the #759 unguarded `widgetType` read, which fatals for any writer
 * without manage_options — i.e. every MCPWP API write).
 *
 * The fix: insert as a draft, run the Elementor save wrapped in the same
 * Mcpwp_Elementor_Sanitizer_Guard suspend/restore the main Elementor writer
 * trait uses, apply conditions, and only THEN flip the post to publish. Any
 * failure along the way force-deletes the draft and returns a WP_Error —
 * there is no window where an incomplete template is both published and
 * bound to a location.
 *
 * Each test runs in a separate process because the fixture declares
 * \Elementor\Plugin and \ElementorPro\Modules\ThemeBuilder\Module. A constant
 * cannot be undefined once set and this suite runs in one process, so
 * declaring them inline would poison every later test (see #757).
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class ThemeBuilderCreateAtomicityTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['_mcpwp_test_posts'] = array();
		$GLOBALS['_mcpwp_test_meta']  = array();
		$GLOBALS['mcpwp_test_filters'] = array();
	}

	/**
	 * Install the Elementor + Elementor Pro doubles for one test, with the
	 * fake content-sanitizer hooked exactly as the real module would be at
	 * plugin load time.
	 *
	 * @param bool $throw   Whether Mcpwp_Fake_Document::save() should throw.
	 * @param bool $decline Whether Mcpwp_Fake_Document::save() should return
	 *                      the literal bool `false` (#951) instead of
	 *                      throwing or succeeding. Ignored when $throw is true.
	 */
	private function install_elementor( bool $throw, bool $decline = false ): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}
		if ( ! defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			define( 'ELEMENTOR_PRO_VERSION', '4.2.3' );
		}

		$GLOBALS['mcpwp_theme_test_throw']         = $throw;
		$GLOBALS['mcpwp_theme_test_decline']       = $decline;
		$GLOBALS['mcpwp_theme_test_save_calls']    = 0;
		$GLOBALS['mcpwp_theme_test_sanitizer_ran'] = 0;

		require_once __DIR__ . '/fixtures/theme-builder-elementor-doubles.php';

		\Elementor\Plugin::$instance = new \Elementor\Plugin();

		// The real content-sanitizer hooks this filter unconditionally at
		// plugin load time, independent of any one write. Model that so
		// suspend()/restore() have something real to remove and re-add.
		add_filter(
			'elementor/document/save/data',
			array( \Elementor\Plugin::$instance->modules_manager->get_modules( 'content-sanitizer' ), 'sanitize_content' ),
			10,
			2
		);
	}

	/**
	 * A minimal but non-trivial tree: one section > column > heading widget —
	 * the exact shape (a widget alongside non-widget elements) that trips
	 * #759's unguarded `widgetType` read.
	 *
	 * @return array
	 */
	private function minimal_elements(): array {
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
								'id'         => 'wid00001',
								'elType'     => 'widget',
								'widgetType' => 'heading',
								'settings'   => array( 'title' => 'Canary' ),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * A scope that deliberately fails Mcpwp_Theme_Builder::assign_to_location()'s
	 * own validation (no matching switch case, so $conditions stays empty) —
	 * before it ever reaches \ElementorPro\Modules\ThemeBuilder\Module's real
	 * conditions manager. Keeps these tests focused on the create/save/publish
	 * atomicity contract without needing a full Elementor Pro conditions-manager
	 * double, and doubles as coverage that a conditions failure does not roll
	 * back an otherwise-successful save (the pre-existing, unchanged contract).
	 */
	private const INVALID_SCOPE = 'nonexistent_scope';

	private function create_args( array $overrides = array() ): array {
		return array_merge(
			array(
				'title'          => 'Test Template',
				'type'           => 'header',
				'scope'          => self::INVALID_SCOPE,
				'elementor_data' => wp_json_encode( $this->minimal_elements() ),
			),
			$overrides
		);
	}

	/**
	 * The reported defect: a Document::save() that throws must not leave a
	 * published (or even draft) template behind.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_throwable_from_document_save_deletes_the_draft_and_leaves_no_post(): void {
		$this->install_elementor( true );

		$builder = new Mcpwp_Theme_Builder();
		$result  = $builder->create_theme_template( $this->create_args() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'theme_template_save_failed', $result->get_error_code() );

		$this->assertSame( 1, $GLOBALS['mcpwp_theme_test_save_calls'], 'Document::save() must actually have run for this test to mean anything.' );

		// The exact #828 part 2 defect: no post of any status — draft or
		// published — survives a save that threw.
		$this->assertEmpty(
			$GLOBALS['_mcpwp_test_posts'],
			'A failed Elementor save must not leave an orphaned template post behind, in any status.'
		);
		$this->assertEmpty( $GLOBALS['_mcpwp_test_meta'], 'No meta should survive either — the whole post was force-deleted.' );
	}

	/**
	 * The #951 shape: Document::save() declines (returns bool `false`, no
	 * Throwable, no WP_Error) rather than throwing. Before the fix this
	 * return value was ignored outright, the unconditional raw-meta overwrite
	 * a few lines below still ran, and the template ended up published with
	 * data that was never verified as having gone through Elementor's own
	 * save pipeline — the #861 truthfulness gap, but for a site-wide
	 * header/footer template with no render-verification layer to catch it
	 * afterwards. A decline must fail exactly like a throw: force-delete, no
	 * orphaned post of any status, theme_template_save_failed.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_declined_document_save_deletes_the_draft_and_leaves_no_post(): void {
		$this->install_elementor( false, true );

		$builder = new Mcpwp_Theme_Builder();
		$result  = $builder->create_theme_template( $this->create_args() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'theme_template_save_failed', $result->get_error_code() );

		$this->assertSame( 1, $GLOBALS['mcpwp_theme_test_save_calls'], 'Document::save() must actually have run for this test to mean anything.' );

		// The #951 defect: a declined save must not leave data behind via
		// the raw-meta overwrite that follows a successful save — that write
		// must never be reached once save() has declined.
		$this->assertEmpty(
			$GLOBALS['_mcpwp_test_posts'],
			'A declined Elementor save must not leave an orphaned template post behind, in any status.'
		);
		$this->assertEmpty( $GLOBALS['_mcpwp_test_meta'], 'No meta should survive either — the whole post was force-deleted.' );
	}

	/**
	 * The happy path: a save that succeeds ends published, carrying the
	 * submitted data — and publish happens even though the (unrelated,
	 * deliberately invalid) location assignment failed, matching the
	 * pre-existing conditions_error contract.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_successful_save_ends_published_with_the_submitted_data(): void {
		$this->install_elementor( false );

		$builder = new Mcpwp_Theme_Builder();
		$result  = $builder->create_theme_template( $this->create_args() );

		$this->assertIsArray( $result, 'A successful save must return the template array, not a WP_Error.' );
		$this->assertArrayHasKey( 'id', $result );
		$this->assertSame( 'publish', $result['status'] );
		$this->assertArrayHasKey(
			'conditions_error',
			$result,
			'The deliberately invalid scope must still be reported — publish must not silently swallow it.'
		);

		$post = $GLOBALS['_mcpwp_test_posts'][ $result['id'] ] ?? null;
		$this->assertNotNull( $post, 'The published template must exist.' );
		$this->assertSame( 'publish', $post->post_status );

		$raw    = $GLOBALS['_mcpwp_test_meta'][ $result['id'] ]['_elementor_data'] ?? null;
		$stored = is_string( $raw ) ? json_decode( stripslashes( $raw ), true ) : null;

		$this->assertIsArray( $stored, 'The submitted Elementor tree must be persisted to _elementor_data.' );
		$this->assertSame( 'sec00001', $stored[0]['id'] );
		$this->assertSame(
			'Canary',
			$stored[0]['elements'][0]['elements'][0]['settings']['title'],
			'The whole tree must persist, not just the top-level shape.'
		);
	}

	/**
	 * The residual #759 gap this issue also closes: the theme-builder's own
	 * Document::save() call must run with Elementor's content-sanitizer
	 * suspended, the same way trait-mcpwp-elementor-writer.php's calls do.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_document_save_runs_with_the_content_sanitizer_suspended(): void {
		$this->install_elementor( false );

		// Baseline: the fake sanitizer really is hooked before the call, so a
		// zero count below means "suspended", not "never registered".
		$this->assertNotFalse( has_filter( 'elementor/document/save/data' ) );

		$builder = new Mcpwp_Theme_Builder();
		$result  = $builder->create_theme_template( $this->create_args() );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $GLOBALS['mcpwp_theme_test_save_calls'] );
		$this->assertSame(
			0,
			$GLOBALS['mcpwp_theme_test_sanitizer_ran'],
			'The content-sanitizer must not run during a theme-template Document::save() — it is unguarded against non-widget elements (#759) and would fatal for this non-admin write.'
		);

		// Restored afterwards, not left permanently unhooked — exactly one
		// copy of the callback, same as before the call.
		$this->assertSame(
			1,
			mcpwp_test_filter_count( 'elementor/document/save/data' ),
			'Suspending the sanitizer for this save must not leak; it has to be re-hooked exactly once.'
		);
	}

	/**
	 * A Throwable from Document::save() must not leave the sanitizer unhooked
	 * for the rest of the request — the restore() has to run from `finally`,
	 * not only on the happy path.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_throw_does_not_leave_the_content_sanitizer_unhooked(): void {
		$this->install_elementor( true );

		$builder = new Mcpwp_Theme_Builder();
		$builder->create_theme_template( $this->create_args() );

		$this->assertSame(
			1,
			mcpwp_test_filter_count( 'elementor/document/save/data' ),
			'A throw from Document::save() must not leave Elementor\'s own content-sanitizer unhooked beyond this one call.'
		);
	}

	/**
	 * The #951 decline path escalates to a thrown RuntimeException AFTER the
	 * save()/finally pair has already restored the sanitizer — confirm that
	 * escalation itself doesn't leave a second copy hooked (e.g. by tripping
	 * suspend() again on the way out).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_decline_does_not_leave_the_content_sanitizer_unhooked(): void {
		$this->install_elementor( false, true );

		$builder = new Mcpwp_Theme_Builder();
		$builder->create_theme_template( $this->create_args() );

		$this->assertSame(
			1,
			mcpwp_test_filter_count( 'elementor/document/save/data' ),
			'A declined Document::save() must not leave Elementor\'s own content-sanitizer unhooked beyond this one call.'
		);
	}

	/**
	 * RC review BLOCK (2026-09-25): assign_to_location()'s own
	 * save_conditions()/get_cache()->regenerate() call, in real Elementor Pro,
	 * runs a WP_Query with no post_status — which only matches PUBLIC statuses
	 * under a REST request. A draft is invisible to it, and Elementor Pro never
	 * re-regenerates that cache on publish, so a template bound to a location
	 * WHILE STILL A DRAFT is published afterward but never actually rendered —
	 * "assigned:true", present in elementor_pro_theme_builder_conditions,
	 * genuinely published, and permanently absent from the frontend.
	 *
	 * Unit doubles cannot see that cache defect (it lives entirely inside
	 * Elementor Pro's real regenerate() implementation — verified against a
	 * real Elementor Pro install, see the PR). What a double CAN and must
	 * enforce is the ordering that avoids it: by the time
	 * assign_to_location() runs, the post is already 'publish'.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_conditions_are_assigned_only_after_the_template_is_published(): void {
		$this->install_elementor( false );

		require_once __DIR__ . '/fixtures/theme-builder-order-spy.php';

		$builder = new Mcpwp_Test_Theme_Builder_Order_Spy();
		$result  = $builder->create_theme_template( $this->create_args() );

		$this->assertIsArray( $result, 'The happy path must still succeed with the spy in place.' );
		$this->assertTrue( $builder->assign_to_location_was_called, 'assign_to_location() must actually run for this test to mean anything.' );
		$this->assertSame(
			'publish',
			$builder->post_status_when_assign_to_location_ran,
			'assign_to_location() must not run until the template post is already published — otherwise Elementor Pro\'s own conditions-cache regenerate() (which queries only public statuses) silently drops it, and no later publish call ever corrects that cache.'
		);
	}
	public static function dry_run_paths(): array {
		return array(
			'core with content'    => array( false, true ),
			'core without content' => array( false, false ),
			'REST with content'    => array( true, true ),
			'REST without content' => array( true, false ),
		);
	}

	/**
	 * #1078: the REST adapter used by MCP dropped dry_run, even though the
	 * core already honoured it. Exercise the real adapter and writer together.
	 *
	 * @dataProvider dry_run_paths
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_dry_run_never_enters_the_write_path( bool $via_rest, bool $with_content ): void {
		$this->install_elementor( false );
		require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-theme-builder.php';

		update_option( 'mcpwp_settings', array( 'enable_logging' => true ) );
		update_option( 'elementor_pro_theme_builder_conditions', array( 'header' => array( 99 => array( 'include/general' ) ) ) );
		$GLOBALS['mcpwp_test_wpdb_rows'] = array();
		$options_before = $GLOBALS['mcpwp_test_options'];
		$insert_calls   = 0;
		add_filter( 'wp_insert_post_data', function ( $data ) use ( &$insert_calls ) {
			++$insert_calls;
			return $data;
		} );

		$args = $this->create_args( array( 'dry_run' => true, 'scope' => 'specific_post_type', 'post_type' => 'page' ) );
		if ( ! $with_content ) {
			unset( $args['elementor_data'] );
		}
		$builder = new Mcpwp_Theme_Builder();
		if ( $via_rest ) {
			$controller = new Mcpwp_REST_Theme_Builder( $builder );
			$request    = new WP_REST_Request( 'POST', '/mcpwp/v1/theme-builder/templates', $args );
			$response   = $controller->create_theme_template( $request );
			$this->assertInstanceOf( WP_REST_Response::class, $response );
			$result = $response->get_data();
		} else {
			$result = $builder->create_theme_template( $args );
		}

		$this->assertSame( 0, $insert_calls, 'A dry run must never insert or publish a post, even temporarily.' );
		$this->assertEmpty( $GLOBALS['_mcpwp_test_posts'] );
		$this->assertEmpty( $GLOBALS['_mcpwp_test_meta'] );
		$this->assertSame( $options_before, $GLOBALS['mcpwp_test_options'], 'No conditions index or other option may change.' );
		$this->assertEmpty( $GLOBALS['mcpwp_test_wpdb_rows'], 'A preview must not log a creation.' );
		$this->assertSame( 0, $GLOBALS['mcpwp_theme_test_save_calls'] );
		$this->assertSame( 0, $GLOBALS['mcpwp_theme_test_cache_regenerate_calls'] ?? 0 );
		$this->assertTrue( $result['dry_run'] );
		$this->assertTrue( $result['valid'] );
		$this->assertArrayNotHasKey( 'id', $result );
		$this->assertArrayNotHasKey( 'assigned', $result );
		$this->assertArrayHasKey( 'would_create', $result );
		$this->assertSame( 'publish', $result['would_create']['post_status'] );
		$this->assertSame( 'elementor_library', $result['would_create']['post_type'] );
		$this->assertSame( 'Test Template', $result['would_create']['post_title'] );
		$this->assertSame( 'specific_post_type', $result['scope'] );
		$this->assertSame( 'page', $result['post_type'] );
		$this->assertSame( $with_content ? 1 : 0, $result['element_count'] );
	}

	public static function write_flags(): array {
		return array(
			'omitted'      => array( null ),
			'false'        => array( false ),
			'string false' => array( 'false' ),
		);
	}

	/**
	 * @dataProvider write_flags
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_rest_without_dry_run_still_creates_publishes_and_logs( $flag ): void {
		$this->install_elementor( false );
		require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-theme-builder.php';
		update_option( 'mcpwp_settings', array( 'enable_logging' => true ) );
		$GLOBALS['mcpwp_test_wpdb_rows'] = array();

		$args = $this->create_args();
		if ( null !== $flag ) {
			$args['dry_run'] = $flag;
		}
		$controller = new Mcpwp_REST_Theme_Builder( new Mcpwp_Theme_Builder() );
		$response   = $controller->create_theme_template( new WP_REST_Request( 'POST', '/mcpwp/v1/theme-builder/templates', $args ) );
		$result     = $response->get_data();

		$this->assertSame( 'publish', $result['status'] );
		$this->assertCount( 1, $GLOBALS['_mcpwp_test_posts'] );
		$this->assertNotEmpty( $GLOBALS['_mcpwp_test_meta'][ $result['id'] ] );
		$this->assertSame( 1, $GLOBALS['mcpwp_theme_test_save_calls'] );
		global $wpdb;
		$this->assertSame( 'create_theme_template', $GLOBALS['mcpwp_test_wpdb_rows'][ $wpdb->prefix . 'mcpwp_activity_log' ][0]['action'] );
	}

	/**
	 * Use the real MCP dispatcher and bridge the test REST server stub to the
	 * real callback, so the regression also covers the public tool mapping.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_mcp_dry_run_reaches_the_preview_without_creating_a_template(): void {
		$this->install_elementor( false );
		define( 'MCPWP_TESTS', true );
		require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-theme-builder.php';
		$controller = new Mcpwp_REST_Theme_Builder( new Mcpwp_Theme_Builder() );
		$dispatches = 0;
		add_filter( 'rest_dispatch_request', function ( $result, $request ) use ( $controller, &$dispatches ) {
			if ( '/mcpwp/v1/theme-builder/templates' === $request->get_route() ) {
				++$dispatches;
				return $controller->create_theme_template( $request );
			}
			return $result;
		}, 10, 2 );
		$args = $this->create_args( array( 'dry_run' => true, 'scope' => 'entire_site', 'elementor_data' => $this->minimal_elements() ) );
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), array(), array(
			'jsonrpc' => '2.0',
			'id'      => 1078,
			'method'  => 'tools/call',
			'params'  => array( 'name' => 'wp_create_theme_template', 'arguments' => $args ),
		) );
		$data = ( new Mcpwp_REST_MCP() )->handle_mcp( $request )->get_data();

		$this->assertFalse( $data['result']['isError'], wp_json_encode( $data ) );
		$this->assertSame( 1, $dispatches );
		$this->assertEmpty( $GLOBALS['_mcpwp_test_posts'], 'MCP dry_run must not create or publish a template.' );
		$preview = json_decode( $data['result']['content'][0]['text'], true );
		$this->assertTrue( $preview['dry_run'] );
		$this->assertSame( 'publish', $preview['would_create']['post_status'] );
		$this->assertEmpty( $GLOBALS['_mcpwp_test_meta'] );
		$this->assertSame( array(), get_option( 'elementor_pro_theme_builder_conditions', array() ) );
		$this->assertSame( 0, $GLOBALS['mcpwp_theme_test_save_calls'] );
	}

	public static function preview_alias_paths(): array {
		$cases = array();
		foreach ( array( 'REST', 'MCP', 'mcpwp_call', 'batch', 'wp_batch', 'core' ) as $door ) {
			foreach ( array( 'dry_run', 'dryRun', 'dry-run', 'DRY_RUN', 'dryrun', 'dry run', 'dry.run', 'preview' ) as $alias ) {
				foreach ( array( 'true' => true, 'null' => null, 'empty string' => '', 'array' => array() ) as $label => $value ) {
					$cases[ "$door $alias $label" ] = array( $door, array( $alias => $value ) );
				}
				$cases[ "$door conflicting $alias" ] = array( $door, array( 'dry_run' => false, $alias => true ) );
			}
		}
		return $cases;
	}

	/**
	 * Real route handlers behind the test REST server's dispatch hook. The
	 * same adapter receives a direct request or one forwarded by MCP/batch.
	 */
	private function dispatch_template_args( string $door, array $args, array $query = array(), array $body = array() ) {
		require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-theme-builder.php';
		require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-batch.php';
		$controller = new Mcpwp_REST_Theme_Builder( new Mcpwp_Theme_Builder() );
		add_filter( 'rest_dispatch_request', function ( $result, $request ) use ( $controller ) {
			if ( null !== $result ) {
				return $result;
			}
			if ( '/mcpwp/v1/theme-builder/templates' === $request->get_route() ) {
				return $controller->create_theme_template( $request );
			}
			if ( '/mcpwp/v1/batch' === $request->get_route() ) {
				return ( new Mcpwp_REST_Batch() )->handle_batch( $request );
			}
			return $result;
		}, 10, 2 );

		$args += array( 'title' => 'Alias preview', 'type' => 'single', 'scope' => 'entire_site' );
		if ( 'core' === $door ) {
			return ( new Mcpwp_Theme_Builder() )->create_theme_template( $args, array( $query, $body, $args ) );
		}
		if ( 'REST' === $door ) {
			$request = new WP_REST_Request( 'POST', '/mcpwp/v1/theme-builder/templates', $body, array( 'Content-Type' => 'application/json' ), $args );
			$request->set_query_params( $query );
			return $controller->create_theme_template( $request );
		}
		if ( in_array( $door, array( 'batch', 'wp_batch' ), true ) ) {
			$args = array( 'operations' => array( array( 'method' => 'POST', 'path' => '/theme-builder/templates', 'body' => $args ) ) );
		}
		if ( 'batch' === $door ) {
			$request = new WP_REST_Request( 'POST', '/mcpwp/v1/batch', $body, array( 'Content-Type' => 'application/json' ), $args );
			$request->set_query_params( $query );
			return ( new Mcpwp_REST_Batch() )->handle_batch( $request );
		}
		$params = array( 'name' => 'wp_create_theme_template', 'arguments' => $args );
		if ( 'mcpwp_call' === $door ) {
			$params = array( 'name' => 'mcpwp_call', 'arguments' => $params );
		} elseif ( 'wp_batch' === $door ) {
			$params['name'] = 'wp_batch';
		}
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', $body, array( 'Content-Type' => 'application/json' ), array(
			'jsonrpc' => '2.0', 'id' => 1078, 'method' => 'tools/call', 'params' => $params,
		) );
		$request->set_query_params( $query );
		if ( 'WebMCP' === $door ) {
			require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-webmcp.php';
			$web = new WP_REST_Request( 'POST', '/mcpwp/v1/webmcp/call', $body, array( 'Content-Type' => 'application/json' ), $params );
			$web->set_query_params( $query );
			return ( new Mcpwp_REST_WebMCP() )->handle_call( $web );
		}
		return ( new Mcpwp_REST_MCP() )->handle_mcp( $request );
	}

	/**
	 * Every preview spelling and malformed flag must refuse to enter the
	 * write path, including a false canonical flag alongside a true alias.
	 *
	 * @dataProvider preview_alias_paths
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_preview_aliases_and_ambiguous_values_never_write( string $door, array $flags ): void {
		$this->install_elementor( false );
		define( 'MCPWP_TESTS', true );
		$conditions = array( 'single' => array( 99 => array( 'include/general' ) ) );
		update_option( 'elementor_pro_theme_builder_conditions', $conditions );
		$writes = 0;
		add_filter( 'wp_insert_post_data', function ( $data ) use ( &$writes ) {
			++$writes;
			return $data;
		} );
		$response = $this->dispatch_template_args( $door, $flags );

		$this->assertSame( 0, $writes, 'A preview flag must not insert or publish even temporarily.' );
		$this->assertEmpty( $GLOBALS['_mcpwp_test_posts'] );
		$this->assertEmpty( $GLOBALS['_mcpwp_test_meta'] );
		$this->assertSame( $conditions, get_option( 'elementor_pro_theme_builder_conditions' ) );
		$this->assertSame( 0, $GLOBALS['mcpwp_theme_test_save_calls'] );
		$this->assertSame( 0, $GLOBALS['mcpwp_theme_test_cache_regenerate_calls'] ?? 0 );

		$data = $response instanceof WP_REST_Response ? $response->get_data() : $response;
		if ( isset( $data['result'] ) ) {
			if ( ! empty( $data['result']['isError'] ) ) {
				// The MCP schema may refuse an unknown/conflicting spelling
				// before dispatch. Refusing is safe; silently writing is not.
				$this->assertSame( 'invalid_arguments', $data['result']['structuredContent']['error']['code'] );
				return;
			}
			$data = json_decode( $data['result']['content'][0]['text'], true );
		}
		if ( isset( $data['results'] ) ) {
			$this->assertSame( 0, $data['failed'] );
			$data = $data['results'][0]['data'];
		}
		$this->assertTrue( $data['dry_run'] );
		$this->assertSame( 'publish', $data['would_create']['post_status'] );
	}

	public static function false_alias_paths(): array {
		$cases = array();
		foreach ( array( 'REST', 'MCP', 'mcpwp_call', 'batch', 'wp_batch', 'core' ) as $door ) {
			$cases[ "$door omitted" ] = array( $door, array() );
			foreach ( array( 'dry_run', 'dryRun', 'dry-run', 'DRY_RUN', 'dryrun', 'dry run', 'dry.run', 'preview' ) as $alias ) {
				$cases[ "$door $alias false" ] = array( $door, array( $alias => false ) );
				$cases[ "$door $alias zero" ] = array( $door, array( $alias => '0' ) );
			}
		}
		return $cases;
	}

	/**
	 * @dataProvider false_alias_paths
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_clear_false_aliases_and_omitted_flags_keep_normal_writes( string $door, array $flags ): void {
		$this->install_elementor( false );
		define( 'MCPWP_TESTS', true );
		$response = $this->dispatch_template_args( $door, $flags );
		$data = $response instanceof WP_REST_Response ? $response->get_data() : $response;
		if ( isset( $data['result'] ) ) {
			if ( ! empty( $data['result']['isError'] ) ) {
				$this->assertSame( 'preview', key( $flags ) );
				$this->assertSame( 'invalid_arguments', $data['result']['structuredContent']['error']['code'] );
				$this->assertEmpty( $GLOBALS['_mcpwp_test_posts'] );
				return;
			}
			$data = json_decode( $data['result']['content'][0]['text'], true );
		}
		if ( isset( $data['results'] ) ) {
			$this->assertSame( 0, $data['failed'] );
			$data = $data['results'][0]['data'];
		}
		$this->assertArrayHasKey( 'id', $data );
		$this->assertSame( 'publish', $data['status'] );
		$this->assertCount( 1, $GLOBALS['_mcpwp_test_posts'] );
		$this->assertNotEmpty( $GLOBALS['_mcpwp_test_meta'][ $data['id'] ] );
	}

	public static function raw_source_matrix_paths(): array {
		$cases = array();
		foreach ( array( 'REST', 'MCP', 'mcpwp_call', 'batch', 'wp_batch', 'WebMCP', 'core' ) as $door ) {
			foreach ( array( 'dry_run', 'dryRun', 'dry-run', 'DRY_RUN', 'dryrun', 'dry run', 'dry.run', 'preview' ) as $alias ) {
				$cases[ "$door $alias" ] = array( $door, $alias );
			}
		}
		return $cases;
	}

	/**
	 * Cross every value with raw query/form/JSON/tool bags and conflicting
	 * alias orders. Reset operation state per cell, rather than letting a
	 * successful or refused call hide what the next cell actually did.
	 *
	 * @dataProvider raw_source_matrix_paths
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_raw_sources_have_one_order_independent_preview_decision( string $door, string $alias ): void {
		define( 'MCPWP_TESTS', true );
		$values = array(
			'00' => array( '00', true ), '0.0' => array( '0.0', true ),
			'-0' => array( '-0', true ), '0e0' => array( '0e0', true ),
			'spaced false' => array( ' false ', false ), 'upper false' => array( 'FALSE', false ),
			'integer zero' => array( 0, true ), 'integer one' => array( 1, true ),
			'null' => array( null, true ), 'array' => array( array(), true ),
			'object' => array( new stdClass(), true ), 'empty' => array( '', true ),
			'false' => array( false, false ), 'string zero' => array( '0', false ),
		);
		$other = 'dryRun' === $alias ? 'dry-run' : 'dryRun';
		foreach ( $values as $label => $case ) {
			list( $value, $preview ) = $case;
			$canonical = 'dry_run' === $alias ? $other : 'dry_run';
			$pairings = array(
				'argument' => array( array( $alias => $value ), array(), array() ),
				'canonical first' => array( array( $canonical => '0', $alias => $value ), array(), array() ),
				'canonical last' => array( array( $alias => $value, $canonical => '0' ), array(), array() ),
				'alias first' => array( array( $alias => $value, $other => '0' ), array(), array() ),
				'alias last' => array( array( $other => '0', $alias => $value ), array(), array() ),
				'query argument fragment' => array( array( 'dry_run' => '0' ), array( 'params' => array( 'arguments' => array( $alias => $value ) ) ), array() ),
				'form argument fragment' => array( array( 'dry_run' => '0' ), array(), array( 'params' => array( 'arguments' => array( $alias => $value ) ) ) ),
				'query wrapper fragment' => array( array( 'dry_run' => '0' ), array( 'arguments' => array( 'arguments' => array( $alias => $value ) ) ), array() ),
				'form wrapper fragment' => array( array( 'dry_run' => '0' ), array(), array( 'arguments' => array( 'arguments' => array( $alias => $value ) ) ) ),
				'query to JSON' => array( array( 'dry_run' => '0' ), array( $alias => $value ), array() ),
				'JSON to query' => array( array( $alias => $value ), array( 'dry_run' => '0' ), array() ),
				'form to JSON' => array( array( 'dry_run' => '0' ), array(), array( $alias => $value ) ),
				'JSON to form' => array( array( $alias => $value ), array(), array( 'dry_run' => '0' ) ),
				'query to form' => array( array( 'dry_run' => '0' ), array( $alias => $value ), array( 'dry_run' => '0' ) ),
				'form to query' => array( array( 'dry_run' => '0' ), array( 'dry_run' => '0' ), array( $alias => $value ) ),
			);
			foreach ( $pairings as $pair => $bags ) {
				$this->install_elementor( false );
				$GLOBALS['_mcpwp_test_posts'] = array();
				$GLOBALS['_mcpwp_test_meta'] = array();
				$GLOBALS['mcpwp_test_filters'] = array();
				$GLOBALS['mcpwp_test_options'] = array();
				$GLOBALS['mcpwp_test_transients'] = array();
				$GLOBALS['mcpwp_theme_test_cache_regenerate_calls'] = 0;
				$conditions = array( 'single' => array( 99 => array( 'include/general' ) ) );
				update_option( 'elementor_pro_theme_builder_conditions', $conditions );
				$writes = 0;
				add_filter( 'wp_insert_post_data', function ( $data ) use ( &$writes ) {
					++$writes;
					return $data;
				} );
				$response = $this->dispatch_template_args( $door, $bags[0], $bags[1], $bags[2] );
				$cell = "$door $alias $label $pair";
				if ( 'WebMCP' === $door ) {
					// The existing WebMCP allowlist refuses this tool. Keep it
					// refused while checking every raw intent spelling/value.
					$this->assertInstanceOf( WP_Error::class, $response, $cell );
					$this->assertSame( 'webmcp_tool_not_allowed', $response->get_error_code(), $cell );
					$this->assertSame( 0, $writes, $cell );
					continue;
				}
				$data = $response instanceof WP_REST_Response ? $response->get_data() : $response;
				$this->assertIsArray( $data, $cell );
				if ( isset( $data['result'] ) ) {
					$this->assertFalse( $data['result']['isError'], wp_json_encode( $data ) . $cell );
					$data = json_decode( $data['result']['content'][0]['text'], true );
				}
				if ( isset( $data['results'] ) ) {
					$this->assertSame( 0, $data['failed'], $cell );
					$data = $data['results'][0]['data'];
				}
				if ( $preview ) {
					$this->assertSame( 0, $writes, $cell );
					$this->assertEmpty( $GLOBALS['_mcpwp_test_posts'], $cell );
					$this->assertEmpty( $GLOBALS['_mcpwp_test_meta'], $cell );
					$this->assertSame( $conditions, get_option( 'elementor_pro_theme_builder_conditions' ), $cell );
					$this->assertSame( 0, $GLOBALS['mcpwp_theme_test_save_calls'], $cell );
					$this->assertSame( 0, $GLOBALS['mcpwp_theme_test_cache_regenerate_calls'], $cell );
					$this->assertTrue( $data['dry_run'], $cell );
				} else {
					$this->assertSame( 'publish', $data['status'], $cell );
					$this->assertCount( 1, $GLOBALS['_mcpwp_test_posts'], $cell );
				}
			}
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_raw_source_changes_cannot_replay_a_published_result(): void {
		$this->install_elementor( false );
		define( 'MCPWP_TESTS', true );
		$GLOBALS['mcpwp_test_options'] = array();
		$GLOBALS['mcpwp_test_transients'] = array();
		$GLOBALS['mcpwp_test_current_user'] = 2;
		Mcpwp_REST_MCP::reset_verdict_memo();
		$this->dispatch_template_args( 'REST', array( 'dry_run' => true ) );
		$key = ( new Mcpwp_REST_MCP() )->create_scoped_api_key( 'preview replay', array( 'admin' ) );
		$call = function ( array $query, int $id ) use ( $key ) {
			$controller = new Mcpwp_REST_MCP();
			$request = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), array( 'X-API-Key' => $key['key'] ), array(
				'jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call',
				'params' => array( 'name' => 'wp_create_theme_template',
					'arguments' => array( 'title' => 'Pinned', 'type' => 'single' ),
					'_meta' => array( 'request_id' => 'raw-intent-1078' ),
				),
			) );
			$request->set_query_params( $query );
			$this->assertTrue( $controller->verify_api_key( $request ) );
			return $controller->handle_mcp( $request )->get_data();
		};
		$published = $call( array(), 1 );
		$this->assertFalse( $published['result']['isError'], wp_json_encode( $published ) );
		$preview = $call( array( 'dry_run' => 'true' ), 2 );
		$this->assertTrue( $preview['result']['isError'], 'Changed raw intent must not replay a published response.' );
		$this->assertSame( 'idempotency_conflict', $preview['result']['structuredContent']['error']['code'] );
		$retry = $call( array(), 3 );
		$this->assertTrue( $retry['result']['_meta']['replayed'], 'Transport message ids must not change the fingerprint.' );
		$this->assertCount( 1, $GLOBALS['_mcpwp_test_posts'] );
	}

	public static function batch_sibling_paths(): array {
		return array( array( false, false ), array( false, true ), array( true, false ), array( true, true ) );
	}

	/**
	 * @dataProvider batch_sibling_paths
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_raw_bags_do_not_leak_between_operations_or_requests( bool $via_mcp, bool $reverse ): void {
		$this->install_elementor( false );
		define( 'MCPWP_TESTS', true );
		$this->dispatch_template_args( 'REST', array( 'dry_run' => true ) );
		$operations = array(
			array( 'method' => 'POST', 'path' => '/theme-builder/templates', 'body' => array( 'title' => 'Preview sibling', 'type' => 'single', 'dryRun' => '00' ) ),
			array( 'method' => 'POST', 'path' => '/theme-builder/templates', 'body' => array( 'title' => 'Published sibling', 'type' => 'single', 'dry_run' => false ) ),
		);
		if ( $reverse ) {
			$operations = array_reverse( $operations );
		}
		if ( $via_mcp ) {
			$request = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), array(), array(
				'jsonrpc' => '2.0', 'id' => 1078, 'method' => 'tools/call',
				'params' => array( 'name' => 'wp_batch', 'arguments' => array( 'operations' => $operations ) ),
			) );
			$result = ( new Mcpwp_REST_MCP() )->handle_mcp( $request )->get_data();
			$this->assertFalse( $result['result']['isError'], wp_json_encode( $result ) );
			$result = json_decode( $result['result']['content'][0]['text'], true );
		} else {
			$result = ( new Mcpwp_REST_Batch() )->handle_batch( new WP_REST_Request( 'POST', '/mcpwp/v1/batch', array( 'operations' => $operations ) ) )->get_data();
		}
		$this->assertTrue( $result['results'][ $reverse ? 1 : 0 ]['data']['dry_run'] );
		$this->assertSame( 'publish', $result['results'][ $reverse ? 0 : 1 ]['data']['status'] );
		$this->assertCount( 1, $GLOBALS['_mcpwp_test_posts'] );
		$context = new ReflectionProperty( Mcpwp_REST_API::class, 'raw_parameter_context' );
		$context->setAccessible( true );
		$this->assertCount( 0, $context->getValue(), 'Only live internal requests may retain raw bags.' );
		$next = $this->dispatch_template_args( 'MCP', array( 'dry_run' => false ) )->get_data();
		$this->assertFalse( $next['result']['isError'] );
		$this->assertCount( 2, $GLOBALS['_mcpwp_test_posts'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_raw_bag_context_is_removed_when_nested_dispatch_throws(): void {
		$this->install_elementor( false );
		define( 'MCPWP_TESTS', true );
		$throw = function ( $result, $request ) {
			if ( '/mcpwp/v1/theme-builder/templates' === $request->get_route() ) {
				throw new RuntimeException( 'raw bag dispatch canary' );
			}
			return $result;
		};
		add_filter( 'rest_dispatch_request', $throw, 0, 2 );
		try {
			$this->dispatch_template_args( 'wp_batch', array( 'dry_run' => false ), array( 'dryRun' => '00' ) );
			$this->fail( 'The nested dispatch must throw.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 'raw bag dispatch canary', $error->getMessage() );
		}
		remove_filter( 'rest_dispatch_request', $throw, 0 );
		$context = new ReflectionProperty( Mcpwp_REST_API::class, 'raw_parameter_context' );
		$context->setAccessible( true );
		$this->assertCount( 0, $context->getValue() );
		$this->assertFalse( Mcpwp_REST_Batch::$is_batch_sub_request );
		$next = $this->dispatch_template_args( 'REST', array( 'dry_run' => false ) );
		$this->assertSame( 'publish', $next->get_data()['status'] );
	}

	/**
	 * WebMCP cannot call the template tool, but its allowed dispatches must
	 * still preserve the same raw evidence through their internal MCP hop.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_webmcp_carries_unmerged_bags_and_cleans_up(): void {
		require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-webmcp.php';
		$GLOBALS['mcpwp_test_current_user'] = 1;
		$captured = array();
		$reader = new ReflectionMethod( Mcpwp_REST_API::class, 'raw_parameter_bags' );
		$reader->setAccessible( true );
		$controller = new Mcpwp_REST_WebMCP();
		add_filter( 'rest_dispatch_request', function ( $result, $request ) use ( &$captured, $reader, $controller ) {
			if ( '/mcpwp/v1/mcp' === $request->get_route() ) {
				$captured = $reader->invoke( $controller, $request );
				return new WP_REST_Response( array( 'jsonrpc' => '2.0', 'id' => 1, 'result' => array(
					'isError' => false, 'content' => array( array( 'type' => 'text', 'text' => '{}' ) ),
				) ) );
			}
			return $result;
		}, 10, 2 );
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/webmcp/call', array(), array( 'Content-Type' => 'application/json' ), array(
			'name' => 'wp_site_info', 'arguments' => array(), 'dry_run' => false,
		) );
		$request->set_query_params( array( 'dry_run' => 'true' ) );
		$response = $controller->handle_call( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertContains( array( 'dry_run' => 'true' ), $captured );
		$this->assertContains( array( 'name' => 'wp_site_info', 'arguments' => array(), 'dry_run' => false ), $captured );
		$context = new ReflectionProperty( Mcpwp_REST_API::class, 'raw_parameter_context' );
		$context->setAccessible( true );
		$this->assertCount( 0, $context->getValue() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_batch_preserves_shadowed_operation_bodies_from_tool_envelopes(): void {
		$this->install_elementor( false );
		define( 'MCPWP_TESTS', true );
		$this->dispatch_template_args( 'REST', array( 'dry_run' => true ) );
		$operations = array(
			array( 'method' => 'POST', 'path' => '/theme-builder/templates', 'body' => array( 'title' => 'Shadowed preview', 'type' => 'single', 'dry_run' => false ) ),
			array( 'method' => 'POST', 'path' => '/theme-builder/templates', 'body' => array( 'title' => 'Independent sibling', 'type' => 'single', 'dry_run' => false ) ),
		);
		$query_operations = $operations;
		$query_operations[0]['body']['dry_run'] = '00';
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), array(), array(
			'jsonrpc' => '2.0', 'id' => 1078, 'method' => 'tools/call',
			'params' => array( 'name' => 'wp_batch', 'arguments' => array( 'operations' => $operations ) ),
		) );
		$request->set_query_params( array( 'params' => array( 'arguments' => array( 'operations' => $query_operations ) ) ) );
		$data = ( new Mcpwp_REST_MCP() )->handle_mcp( $request )->get_data();
		$this->assertFalse( $data['result']['isError'], wp_json_encode( $data ) );
		$data = json_decode( $data['result']['content'][0]['text'], true );
		$this->assertArrayHasKey( 'dry_run', $data['results'][0]['data'] );
		$this->assertTrue( $data['results'][0]['data']['dry_run'] );
		$this->assertSame( 'publish', $data['results'][1]['data']['status'] );
		$this->assertCount( 1, $GLOBALS['_mcpwp_test_posts'] );
	}

}
