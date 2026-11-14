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
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_silently_failed_meta_write_never_publishes_a_template(): void {
		$this->install_elementor( false );
		$GLOBALS['mcpwp_test_meta_write_fails'] = array( '_elementor_data' );

		$result = ( new Mcpwp_Theme_Builder() )->create_theme_template( $this->create_args() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEmpty( $GLOBALS['_mcpwp_test_posts'], 'A template with no persisted tree must not remain published or draft.' );
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
			$request    = new WP_REST_Request( 'POST', '/mcpwp/v1/theme-builder/templates', $args, array( 'Content-Type' => 'application/x-www-form-urlencoded' ) );
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
		$response   = $controller->create_theme_template( new WP_REST_Request( 'POST', '/mcpwp/v1/theme-builder/templates', $args, array( 'Content-Type' => 'application/x-www-form-urlencoded' ) ) );
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
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), array( 'Content-Type' => 'application/json' ), array(
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
			$request = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), array( 'Content-Type' => 'application/json', 'X-API-Key' => $key['key'] ), array(
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
			$request = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), array( 'Content-Type' => 'application/json' ), array(
				'jsonrpc' => '2.0', 'id' => 1078, 'method' => 'tools/call',
				'params' => array( 'name' => 'wp_batch', 'arguments' => array( 'operations' => $operations ) ),
			) );
			$result = ( new Mcpwp_REST_MCP() )->handle_mcp( $request )->get_data();
			$this->assertFalse( $result['result']['isError'], wp_json_encode( $result ) );
			$result = json_decode( $result['result']['content'][0]['text'], true );
		} else {
			$result = ( new Mcpwp_REST_Batch() )->handle_batch( new WP_REST_Request( 'POST', '/mcpwp/v1/batch', array( 'operations' => $operations ), array( 'Content-Type' => 'application/x-www-form-urlencoded' ) ) )->get_data();
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
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), array( 'Content-Type' => 'application/json' ), array(
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

	private function dispatch_raw_template_request( string $door, string $raw_body, string $raw_query = '', bool $form = false, $content_type = null ) {
		$this->dispatch_template_args( 'REST', array( 'dry_run' => true ) );
		$_SERVER['QUERY_STRING'] = $raw_query;
		$path = 'REST' === $door ? '/theme-builder/templates' : ( 'batch' === $door ? '/batch' : ( 'WebMCP' === $door ? '/webmcp/call' : '/mcp' ) );
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1' . $path, array(), array(
			'Content-Type' => null !== $content_type ? $content_type : ( $form ? 'application/x-www-form-urlencoded' : 'application/json' ),
		) );
		$request->set_body( $raw_body );
		parse_str( $raw_query, $query );
		$request->set_query_params( $query );
		if ( $form ) {
			parse_str( $raw_body, $body );
			$request->set_body_params( $body );
		}
		if ( 'REST' === $door ) {
			return ( new Mcpwp_REST_Theme_Builder( new Mcpwp_Theme_Builder() ) )->create_theme_template( $request );
		}
		if ( 'WebMCP' === $door ) {
			require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-webmcp.php';
			return ( new Mcpwp_REST_WebMCP() )->handle_call( $request );
		}
		return 'batch' === $door ? ( new Mcpwp_REST_Batch() )->handle_batch( $request ) : ( new Mcpwp_REST_MCP() )->handle_mcp( $request );
	}

	public static function repeated_raw_key_paths(): array {
		$template = '{"title":"Repeated","type":"single","dry_run":true,"dry_run":false}';
		$mcp = '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"wp_create_theme_template","arguments":' . $template . '}}';
		return array(
			'query open bracket' => array( 'REST', '{"title":"Repeated","type":"single"}', 'rest_route=/mcpwp/v1/theme-builder/templates&dry[run=1&dry_run=0', false ),
			'form open bracket' => array( 'REST', 'title=Repeated&type=single&dry[run=1&dry_run=0', '', true ),
			'query NUL suffix' => array( 'REST', '{"title":"Repeated","type":"single"}', 'dry_run%00x=1&dry_run=0', false ),
			'form NUL suffix' => array( 'REST', 'title=Repeated&type=single&dry_run%00x=1&dry_run=0', '', true ),
			'100 query repeats' => array( 'REST', '{"title":"Repeated","type":"single"}', implode( '&', array_fill( 0, 100, 'dry_run=0' ) ), false ),
			'query dry_run' => array( 'REST', '{"title":"Repeated","type":"single"}', 'rest_route=/mcpwp/v1/theme-builder/templates&dry_run=1&dry_run=0', false ),
			'query preview' => array( 'REST', '{"title":"Repeated","type":"single"}', 'preview=1&preview=false', false ),
			'query aliases false' => array( 'REST', '{"title":"Repeated","type":"single"}', 'dry_run=0&preview=false', false ),
			'form body' => array( 'REST', 'title=Repeated&type=single&dry_run=1&dry_run=0', '', true ),
			'form encoded key' => array( 'REST', 'title=Repeated&type=single&dry%5Frun=1&dry_run=0', '', true ),
			'JSON body' => array( 'REST', $template, '', false ),
			'JSON aliases across containers' => array( 'REST', '{"title":"Repeated","type":"single","dry_run":false,"params":{"preview":false}}', '', false ),
			'query aliases across containers' => array( 'REST', '{"title":"Repeated","type":"single"}', 'dry_run=0&params[preview]=false', false ),
			'batch global and operation' => array( 'batch', '{"dry_run":false,"operations":[{"method":"POST","path":"/theme-builder/templates","body":{"title":"Repeated","type":"single","dry_run":false}}]}', '', false ),
			'JSON aliases false' => array( 'REST', '{"title":"Repeated","type":"single","dryRun":false,"preview":false}', '', false ),
			'JSON escaped key' => array( 'REST', str_replace( '"dry_run":true', '"\\u0064ry_run":true', $template ), '', false ),
			'batch operation' => array( 'batch', '{"operations":[{"method":"POST","path":"/theme-builder/templates","body":' . $template . '}]}', '', false ),
			'MCP arguments' => array( 'MCP', $mcp, '', false ),
			'wrapped arguments' => array( 'MCP', '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"mcpwp_call","arguments":{"name":"wp_create_theme_template","arguments":' . $template . '}}}', '', false ),
			'MCP query' => array( 'MCP', str_replace( ',"dry_run":true,"dry_run":false', '', $mcp ), 'dry_run=1&dry_run=0', false ),
			'MCP batch operation' => array( 'MCP', '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"wp_batch","arguments":{"operations":[{"method":"POST","path":"/theme-builder/templates","body":' . $template . '}]}}}', '', false ),
			'overwritten intent envelope' => array( 'MCP', '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"wp_create_theme_template","arguments":{"title":"Repeated","type":"single","dry_run":true},"arguments":{"title":"Repeated","type":"single"}}}', '', false ),
			'repeated argument envelope' => array( 'MCP', '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"wp_create_theme_template","arguments":{"title":"Repeated","type":"single","dry_run":true},"arguments":{"title":"Repeated","type":"single","dry_run":false}}}', '', false ),
		);
	}

	/**
	 * @dataProvider repeated_raw_key_paths
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_repeated_intent_keys_cannot_disappear_during_parsing( string $door, string $body, string $query, bool $form ): void {
		$this->install_elementor( false );
		define( 'MCPWP_TESTS', true );
		$response = $this->dispatch_raw_template_request( $door, $body, $query, $form );
		$this->assertEmpty( $GLOBALS['_mcpwp_test_posts'], 'Repeated raw keys must not publish, even if the parsed last value is false.' );
		$this->assertEmpty( $GLOBALS['_mcpwp_test_meta'] );
		$this->assertSame( 0, $GLOBALS['mcpwp_theme_test_save_calls'] );
		$data = $response->get_data();
		if ( isset( $data['result'] ) ) {
			$this->assertFalse( $data['result']['isError'], wp_json_encode( $data ) );
			$data = json_decode( $data['result']['content'][0]['text'], true );
		}
		if ( isset( $data['results'] ) ) {
			$data = $data['results'][0]['data'];
		}
		$this->assertTrue( $data['dry_run'] );
	}

	public static function metadata_intent_paths(): array {
		$cases = array();
		foreach ( array( 'REST', 'MCP', 'mcpwp_call', 'batch', 'wp_batch' ) as $door ) {
			foreach ( array( array(), array( 'params' ), array( 'params', '_meta' ), array( 'params', 'arguments' ), array( 'arguments' ) ) as $path ) {
				$cases[ $door . ' ' . implode( '.', $path ) ] = array( $door, $path );
			}
		}
		return $cases;
	}

	/**
	 * @dataProvider metadata_intent_paths
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_intent_counts_in_every_protocol_metadata_location( string $door, array $path ): void {
		$this->install_elementor( false );
		define( 'MCPWP_TESTS', true );
		$body = array( 'title' => 'Metadata', 'type' => 'single' );
		if ( in_array( $door, array( 'batch', 'wp_batch' ), true ) ) {
			$body = array( 'operations' => array( array( 'method' => 'POST', 'path' => '/theme-builder/templates', 'body' => $body ) ) );
		}
		if ( in_array( $door, array( 'MCP', 'mcpwp_call', 'wp_batch' ), true ) ) {
			$params = array( 'name' => 'wp_batch' === $door ? 'wp_batch' : 'wp_create_theme_template', 'arguments' => $body );
			if ( 'mcpwp_call' === $door ) {
				$params = array( 'name' => 'mcpwp_call', 'arguments' => $params );
			}
			$body = array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => $params );
		}
		$slot =& $body;
		foreach ( $path as $segment ) {
			if ( ! isset( $slot[ $segment ] ) ) {
				$slot[ $segment ] = array();
			}
			$slot =& $slot[ $segment ];
		}
		$slot['dry_run'] = true;
		unset( $slot );
		$response = $this->dispatch_raw_template_request( in_array( $door, array( 'mcpwp_call', 'wp_batch' ), true ) ? 'MCP' : $door, wp_json_encode( $body ) );
		$this->assertEmpty( $GLOBALS['_mcpwp_test_posts'], 'Protocol-level intent must not be ignored.' );
		$this->assertEmpty( $GLOBALS['_mcpwp_test_meta'] );
		$data = $response->get_data();
		if ( isset( $data['result']['isError'] ) && $data['result']['isError'] ) {
			$this->assertContains( $data['result']['structuredContent']['error']['code'], array( 'invalid_params', 'invalid_arguments' ) );
		}
	}

	public static function raw_sibling_paths(): array {
		return array( array( 'batch' ), array( 'MCP' ) );
	}

	/**
	 * @dataProvider raw_sibling_paths
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_repeated_raw_keys_only_preview_the_selected_sibling( string $door ): void {
		$this->install_elementor( false );
		define( 'MCPWP_TESTS', true );
		$duplicate = '{"title":"Preview","type":"single","dry_run":true,"dry_run":false}';
		$ordinary = '{"title":"Publish","type":"single","dry_run":false}';
		if ( 'batch' === $door ) {
			$body = '{"operations":[{"method":"POST","path":"/theme-builder/templates","body":' . $duplicate . '},{"method":"POST","path":"/theme-builder/templates","body":' . $ordinary . '}]}';
		} else {
			$prefix = '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"wp_create_theme_template","arguments":';
			$body = '[' . $prefix . $duplicate . '}},' . str_replace( '"id":1', '"id":2', $prefix ) . $ordinary . '}}]';
		}
		$data = $this->dispatch_raw_template_request( $door, $body )->get_data();
		if ( 'MCP' === $door ) {
			$first = json_decode( $data[0]['result']['content'][0]['text'], true );
			$second = json_decode( $data[1]['result']['content'][0]['text'], true );
		} else {
			$first = $data['results'][0]['data'];
			$second = $data['results'][1]['data'];
		}
		$this->assertTrue( $first['dry_run'] );
		$this->assertSame( 'publish', $second['status'] );
		$this->assertCount( 1, $GLOBALS['_mcpwp_test_posts'] );
		foreach ( array( 'raw_parameter_context', 'raw_parameter_sources', 'raw_body_paths' ) as $name ) {
			$property = new ReflectionProperty( Mcpwp_REST_API::class, $name );
			$property->setAccessible( true );
			$value = $property->getValue();
			$this->assertTrue( null === $value || 0 === count( $value ), $name . ' must be empty after dispatch.' );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_raw_scan_does_not_treat_string_contents_as_intent_keys(): void {
		$this->install_elementor( false );
		define( 'MCPWP_TESTS', true );
		$body = wp_json_encode( array( 'title' => 'Ordinary', 'type' => 'single', 'dry_run' => false,
			'note' => 'Quoted "dry_run":true,"dry_run":false, braces {} [], backslash \\ and unicode.',
		) );
		$result = $this->dispatch_raw_template_request( 'REST', $body, 'dry_run=0' )->get_data();
		$this->assertSame( 'publish', $result['status'] );
		$this->assertCount( 1, $GLOBALS['_mcpwp_test_posts'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_repeated_wire_keys_cannot_replay_a_prior_publish(): void {
		$this->install_elementor( false );
		define( 'MCPWP_TESTS', true );
		$_SERVER['QUERY_STRING'] = '';
		$GLOBALS['mcpwp_test_options'] = array();
		$GLOBALS['mcpwp_test_transients'] = array();
		$GLOBALS['mcpwp_test_current_user'] = 2;
		Mcpwp_REST_MCP::reset_verdict_memo();
		$this->dispatch_template_args( 'REST', array( 'dry_run' => true ) );
		$key = ( new Mcpwp_REST_MCP() )->create_scoped_api_key( 'wire repeat replay', array( 'admin' ) );
		$call = function ( bool $duplicate, int $id ) use ( $key ) {
			$request = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), array( 'Content-Type' => 'application/json', 'X-API-Key' => $key['key'] ) );
			$arguments = '{"title":"Pinned wire","type":"single",' . ( $duplicate ? '"dry_run":true,' : '' ) . '"dry_run":false}';
			$request->set_body( '{"jsonrpc":"2.0","id":' . $id . ',"method":"tools/call","params":{"name":"wp_create_theme_template","arguments":' . $arguments . ',"_meta":{"request_id":"wire-repeat-1078"}}}' );
			$controller = new Mcpwp_REST_MCP();
			$this->assertTrue( $controller->verify_api_key( $request ) );
			return $controller->handle_mcp( $request )->get_data();
		};
		$first = $call( false, 1 );
		$this->assertFalse( $first['result']['isError'] );
		$second = $call( true, 2 );
		$this->assertTrue( $second['result']['isError'], 'Raw duplicates change the replay identity even when json_decode returns the same arguments.' );
		$this->assertSame( 'idempotency_conflict', $second['result']['structuredContent']['error']['code'] );
		$retry = $call( false, 3 );
		$this->assertTrue( $retry['result']['_meta']['replayed'] );
		$this->assertCount( 1, $GLOBALS['_mcpwp_test_posts'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_parsed_jsonrpc_messages_keep_their_own_metadata_intent(): void {
		$this->install_elementor( false );
		define( 'MCPWP_TESTS', true );
		$this->dispatch_template_args( 'REST', array( 'dry_run' => true ) );
		$message = array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array(
			'name' => 'wp_create_theme_template', 'arguments' => array( 'title' => 'Metadata', 'type' => 'single' ),
		) );
		$preview = $message;
		$preview['params']['_meta'] = array( 'dry_run' => true );
		$message['id'] = 2;
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), array( 'Content-Type' => 'application/json' ), array( $preview, $message ) );
		$data = ( new Mcpwp_REST_MCP() )->handle_mcp( $request )->get_data();
		$first = json_decode( $data[0]['result']['content'][0]['text'], true );
		$second = json_decode( $data[1]['result']['content'][0]['text'], true );
		$this->assertArrayHasKey( 'dry_run', $first );
		$this->assertTrue( $first['dry_run'] );
		$this->assertSame( 'publish', $second['status'] );
		$this->assertCount( 1, $GLOBALS['_mcpwp_test_posts'] );
	}

	public static function php_key_spellings(): array {
		$keys = array(
			'plain' => 'dry_run', 'open bracket' => 'dry[run', 'encoded open bracket' => 'dry%5Brun',
			'NUL suffix' => 'dry_run%00x', 'literal NUL' => "dry_run\0x", 'dot' => 'dry.run',
			'plus' => 'dry+run', 'space' => 'dry%20run', 'leading space' => '%20dry_run',
			'encoded ampersand' => 'dry%26run', 'encoded semicolon' => 'dry%3Brun', 'encoded equals' => 'dry%3Drun',
			'nested' => 'params[arguments][dry_run]', 'encoded nested' => 'params%5Barguments%5D%5Bdry_run%5D',
			'nested metadata' => 'params[_meta][preview]', 'array' => 'dry_run[]', 'nested array' => 'dry_run[child][nested]',
			'unknown nesting' => 'unknown[dry_run]', 'unicode' => 'ｄｒｙ＿ｒｕｎ', 'unicode child' => 'dry_run[ｘ]',
			'long unknown' => 'dry_run' . str_repeat( 'x', 8192 ), 'long leading space' => str_repeat( ' ', 8192 ) . 'dry_run',
		);
		$cases = array();
		foreach ( $keys as $label => $key ) {
			$cases[ $label ] = array( $key . '=1', false );
		}
		$cases['100 repeats'] = array( implode( '&', array_fill( 0, 100, 'dry_run=0' ) ), true );
		$cases['semicolon stays in value'] = array( 'note=x;dry_run=1&dry_run=0', false );
		return $cases;
	}

	/** @dataProvider php_key_spellings */
	public function test_form_scanner_sees_every_intent_key_php_exposes( string $raw, bool $repeated ): void {
		parse_str( $raw, $php );
		$expected = array();
		$walk = function ( array $bag, array $path = array() ) use ( &$walk, &$expected ) {
			foreach ( $bag as $key => $value ) {
				$next = array_merge( $path, array( $key ) );
				if ( Mcpwp_Theme_Builder::is_dry_run_key( $key ) ) {
					$expected[] = $next;
				} elseif ( is_array( $value ) ) {
					$walk( $value, $next );
				}
			}
		};
		$walk( $php );
		$source = new Mcpwp_Raw_Parameter_Source( 'form', $raw, 'query' );
		Mcpwp_Theme_Builder::raw_intent_evidence( $source );
		foreach ( $expected as $path ) {
			$this->assertContains( $path, $source->document->keys, 'The scanner must use the key/path PHP actually exposes.' );
		}
		$this->assertCount( $repeated ? 100 : count( $expected ), $source->document->keys );
	}

	public static function multipart_intent_flags(): array {
		return array( array( 'dry_run', '0' ), array( 'dry_run', false ), array( 'dryRun', 'false' ),
			array( 'dry-run', '0' ), array( 'DRY_RUN', '0' ), array( 'preview', 'false' ), array( 'dry_run', null ) );
	}

	/**
	 * PHP exposes only the final text-part value; the original body is empty.
	 * @dataProvider multipart_intent_flags
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_multipart_with_any_intent_flag_never_writes( string $key, $value ): void {
		$this->install_elementor( false );
		require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-theme-builder.php';
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/theme-builder/templates', array(
			'title' => 'Multipart', 'type' => 'single', $key => $value,
		), array( 'Content-Type' => 'multipart/form-data; boundary=php-consumed-parts' ) );
		$this->assertSame( '', $request->get_body() );
		$response = ( new Mcpwp_REST_Theme_Builder( new Mcpwp_Theme_Builder() ) )->create_theme_template( $request );
		$this->assertEmpty( $GLOBALS['_mcpwp_test_posts'], 'Unavailable multipart history must not allow an intent-bearing request to publish.' );
		$this->assertEmpty( $GLOBALS['_mcpwp_test_meta'] );
		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'invalid_params', $response->get_error_code() );
		$this->assertSame( 400, $response->get_error_data()['status'] );
	}

	public static function multipart_content_types(): array {
		return array( array( 'multipart/form-data; boundary=php-consumed-parts' ), array( 'Multipart/Form-Data; boundary=php-consumed-parts' ) );
	}

	/**
	 * @dataProvider multipart_content_types
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_multipart_without_intent_creates_normally( string $type ): void {
		$this->install_elementor( false );
		require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-theme-builder.php';
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/theme-builder/templates', array(
			'title' => 'Multipart', 'type' => 'single',
		), array( 'Content-Type' => $type ) );
		$response = ( new Mcpwp_REST_Theme_Builder( new Mcpwp_Theme_Builder() ) )->create_theme_template( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'publish', $response->get_data()['status'] );
		$this->assertCount( 1, $GLOBALS['_mcpwp_test_posts'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_semicolons_and_unrelated_repeats_do_not_preview(): void {
		$this->install_elementor( false );
		$response = $this->dispatch_raw_template_request( 'REST', 'title=first&title=last&type=single&note=x;dry_run=1&dry_run=0', '', true );
		$this->assertSame( 'publish', $response->get_data()['status'] );
		$this->assertCount( 1, $GLOBALS['_mcpwp_test_posts'] );
	}

	public static function single_php_false_flags(): array {
		$cases = array();
		foreach ( array( 'dry_run', 'dry[run', 'dry_run%00x' ) as $key ) {
			$cases[ 'query ' . $key ] = array( $key, false );
			$cases[ 'form ' . $key ] = array( $key, true );
		}
		return $cases;
	}

	/**
	 * @dataProvider single_php_false_flags
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_single_php_false_flag_still_creates( string $key, bool $form ): void {
		$this->install_elementor( false );
		$body = $form ? 'title=Single&type=single&' . $key . '=0' : '{"title":"Single","type":"single"}';
		$response = $this->dispatch_raw_template_request( 'REST', $body, $form ? '' : $key . '=0', $form );
		$this->assertSame( 'publish', $response->get_data()['status'] );
		$this->assertCount( 1, $GLOBALS['_mcpwp_test_posts'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_multipart_clear_false_query_does_not_block_plain_body(): void {
		$this->install_elementor( false );
		require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-theme-builder.php';
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/theme-builder/templates', array(
			'title' => 'Multipart', 'type' => 'single',
		), array( 'Content-Type' => 'multipart/form-data; boundary=php-consumed-parts' ) );
		$request->set_query_params( array( 'preview' => '0' ) );
		$response = ( new Mcpwp_REST_Theme_Builder( new Mcpwp_Theme_Builder() ) )->create_theme_template( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $GLOBALS['_mcpwp_test_posts'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_multipart_operation_presence_does_not_affect_its_sibling(): void {
		$this->install_elementor( false );
		$this->dispatch_template_args( 'REST', array( 'dry_run' => true ) );
		$operations = array(
			array( 'method' => 'POST', 'path' => '/theme-builder/templates', 'body' => array( 'title' => 'Preview', 'type' => 'single', 'dry_run' => '0' ) ),
			array( 'method' => 'GET', 'path' => '/site-info' ),
		);
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/batch', array( 'operations' => $operations ), array( 'Content-Type' => 'multipart/form-data; boundary=php-consumed-parts' ) );
		$data = ( new Mcpwp_REST_Batch() )->handle_batch( $request )->get_data();
		$this->assertSame( 400, $data['results'][0]['status'] );
		$this->assertSame( 'invalid_params', $data['results'][0]['data']['code'] );
		$this->assertSame( 200, $data['results'][1]['status'] );
		$this->assertEmpty( $GLOBALS['_mcpwp_test_posts'] );
	}

	public static function query_separator_sets(): array {
		return array(
			array( 'query', '&;', 'dry_run=1;dry_run=0&title=x', array( 'dry_run=1', 'dry_run=0', 'title=x' ) ),
			array( 'query', ';', 'dry_run=1;dry_run=0', array( 'dry_run=1', 'dry_run=0' ) ),
			array( 'query', '&|;', 'a=1|b=2;c=3&d=4', array( 'a=1', 'b=2', 'c=3', 'd=4' ) ),
			array( 'query', '', 'a=1&b=2;c=3', array( 'a=1', 'b=2;c=3' ) ),
			array( 'query', '&;', 'dry_run=1%3Bdry_run%3D0&preview=0', array( 'dry_run=1%3Bdry_run%3D0', 'preview=0' ) ),
			array( 'body', '&;', 'dry_run=1;dry_run=0&title=x', array( 'dry_run=1;dry_run=0', 'title=x' ) ),
		);
	}

	/** @dataProvider query_separator_sets */
	public function test_form_split_matches_source_separator_rules( string $channel, string $separators, string $raw, array $expected ): void {
		$this->assertTrue( method_exists( Mcpwp_Theme_Builder::class, 'split_form_fields' ), 'One source-aware splitter must own field boundaries.' );
		$this->assertSame( $expected, Mcpwp_Theme_Builder::split_form_fields( $raw, $channel, $separators ) );
	}

	public static function multipart_field_limits(): array {
		return array( array( false, true ), array( true, true ), array( false, false ), array( true, false ) );
	}

	/**
	 * @dataProvider multipart_field_limits
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_multipart_field_limit_refuses_only_at_or_above_bound( bool $nested, bool $at_limit ): void {
		$this->install_elementor( false );
		require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-theme-builder.php';
		$limit = (int) ini_get( 'max_input_vars' );
		if ( $limit < 3 ) {
			$this->markTestSkipped( 'This boundary test requires a finite limit above the required fields.' );
		}
		$data = array( 'title' => 'Bounded', 'type' => 'single' );
		$count = $limit - 2 - ( $at_limit ? 0 : 1 );
		for ( $i = 0; $i < $count; ++$i ) {
			if ( $nested ) {
				$data['padding'][ $i ] = 'x';
			} else {
				$data[ 'padding_' . $i ] = 'x';
			}
		}
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/theme-builder/templates', $data, array( 'Content-Type' => 'multipart/form-data; boundary=php-consumed-parts' ) );
		$response = ( new Mcpwp_REST_Theme_Builder( new Mcpwp_Theme_Builder() ) )->create_theme_template( $request );
		if ( $at_limit ) {
			$this->assertInstanceOf( WP_Error::class, $response );
			$this->assertSame( 'invalid_params', $response->get_error_code() );
			$this->assertEmpty( $GLOBALS['_mcpwp_test_posts'] );
		} else {
			$this->assertInstanceOf( WP_REST_Response::class, $response );
			$this->assertSame( 200, $response->get_status() );
			$this->assertCount( 1, $GLOBALS['_mcpwp_test_posts'] );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_plain_multipart_batch_template_still_creates(): void {
		$this->install_elementor( false );
		$this->dispatch_template_args( 'REST', array( 'dry_run' => true ) );
		$body = array( 'operations' => array( array( 'method' => 'POST', 'path' => '/theme-builder/templates', 'body' => array( 'title' => 'Multipart batch', 'type' => 'single' ) ) ) );
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/batch', $body, array( 'Content-Type' => 'Multipart/Form-Data; boundary=php-consumed-parts' ) );
		$data = ( new Mcpwp_REST_Batch() )->handle_batch( $request )->get_data();
		$this->assertSame( 200, $data['results'][0]['status'] );
		$this->assertSame( 'publish', $data['results'][0]['data']['status'] );
		$this->assertCount( 1, $GLOBALS['_mcpwp_test_posts'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_multipart_body_intent_is_refused_at_arbitrary_depth(): void {
		$this->install_elementor( false );
		require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-theme-builder.php';
		$body = array( 'title' => 'Nested', 'type' => 'single', 'metadata' => array( 'custom' => array( 'DryRun' => false ) ) );
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/theme-builder/templates', $body, array( 'Content-Type' => 'multipart/form-data; boundary=php-consumed-parts' ) );
		$response = ( new Mcpwp_REST_Theme_Builder( new Mcpwp_Theme_Builder() ) )->create_theme_template( $request );
		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'invalid_params', $response->get_error_code() );
		$this->assertEmpty( $GLOBALS['_mcpwp_test_posts'] );
	}

	public static function multipart_template_sibling_orders(): array {
		return array( array( false ), array( true ) );
	}

	/**
	 * @dataProvider multipart_template_sibling_orders
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_multipart_template_intent_is_scoped_to_its_operation( bool $reverse ): void {
		$this->install_elementor( false );
		$this->dispatch_template_args( 'REST', array( 'dry_run' => true ) );
		$operations = array(
			array( 'method' => 'POST', 'path' => '/theme-builder/templates', 'body' => array( 'title' => 'Refuse', 'type' => 'single', 'custom' => array( 'DryRun' => false ) ) ),
			array( 'method' => 'POST', 'path' => '/theme-builder/templates', 'body' => array( 'title' => 'Create', 'type' => 'single' ) ),
		);
		if ( $reverse ) {
			$operations = array_reverse( $operations );
		}
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/batch', array( 'operations' => $operations ), array( 'Content-Type' => 'multipart/form-data; boundary=php-consumed-parts' ) );
		$data = ( new Mcpwp_REST_Batch() )->handle_batch( $request )->get_data();
		$this->assertSame( 400, $data['results'][ $reverse ? 1 : 0 ]['status'] );
		$this->assertSame( 200, $data['results'][ $reverse ? 0 : 1 ]['status'] );
		$this->assertCount( 1, $GLOBALS['_mcpwp_test_posts'] );
	}

	public static function post_field_isolation_cases(): array {
		return array(
			array( '&', 'dry_run=0', 'dry_run=0' ),
			array( '&;', 'note=x;dry_run=1', 'note=x%3Bdry_run=1' ),
			array( '&;', 'dry_run=%30', 'dry_run=%30' ),
			array( '&%', '%64ry_run=1', null ),
			array( '&B', 'dry_run=%3B', null ),
			array( '&6', 'dry_run=6', null ),
			array( '&|', 'dry|run=0', 'dry%7Crun=0' ),
			array( '&=', 'dry_run=0', null ),
		);
	}

	/** @dataProvider post_field_isolation_cases */
	public function test_post_field_cannot_be_resplit_by_query_configuration( string $separators, string $field, $expected ): void {
		$this->assertTrue( method_exists( Mcpwp_Theme_Builder::class, 'prepare_post_field' ) );
		$this->assertSame( $expected, Mcpwp_Theme_Builder::prepare_post_field( $field, $separators ) );
	}

	public static function json_media_parameter_paths(): array {
		$cases = array();
		$headers = array(
			'application/json; x=multipart/form-data',
			'application/json;boundary=multipart/form-data',
			'application/json; a=multipart/form-data',
			'application/json; charset=utf-8; boundary="multipart/form-data"',
			'application/json; boundary="multipart/form-data"; charset=utf-8',
			' Application/JSON ; charset=utf-8; x="multipart/form-data"',
			'APPLICATION/JSON; Content-Type="application/x-www-form-urlencoded"',
			'application/json; x="form"; y="json"',
			'application/json; profile="x;multipart/form-data"; boundary="json"',
		);
		foreach ( array( 'REST', 'MCP', 'mcpwp_call', 'batch', 'wp_batch', 'WebMCP' ) as $door ) {
			foreach ( $headers as $index => $header ) {
				$cases[ "$door header $index" ] = array( $door, $header );
			}
		}
		return $cases;
	}

	/**
	 * @dataProvider json_media_parameter_paths
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_json_media_parameters_cannot_hide_repeated_intent( string $door, string $header ): void {
		$this->install_elementor( false );
		define( 'MCPWP_TESTS', true );
		$template = '{"title":"Media parameters","type":"single","dry_run":true,"dry_run":false}';
		$body = $template;
		if ( in_array( $door, array( 'batch', 'wp_batch' ), true ) ) {
			$body = '{"operations":[{"method":"POST","path":"/theme-builder/templates","body":' . $template . '}]}';
		}
		if ( in_array( $door, array( 'MCP', 'mcpwp_call', 'wp_batch' ), true ) ) {
			$params = '{"name":"' . ( 'wp_batch' === $door ? 'wp_batch' : 'wp_create_theme_template' ) . '","arguments":' . $body . '}';
			if ( 'mcpwp_call' === $door ) {
				$params = '{"name":"mcpwp_call","arguments":' . $params . '}';
			}
			$body = '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":' . $params . '}';
		} elseif ( 'WebMCP' === $door ) {
			$body = '{"name":"wp_create_theme_template","arguments":' . $template . '}';
		}
		$response = $this->dispatch_raw_template_request( $door, $body, '', false, $header );
		$this->assertEmpty( $GLOBALS['_mcpwp_test_posts'], 'Header parameters must not choose a different raw-body parser.' );
		$this->assertEmpty( $GLOBALS['_mcpwp_test_meta'] );
		if ( 'WebMCP' === $door ) {
			$this->assertInstanceOf( WP_Error::class, $response );
			$this->assertSame( 'webmcp_tool_not_allowed', $response->get_error_code() );
			return;
		}
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		if ( isset( $data['result'] ) ) {
			$this->assertFalse( $data['result']['isError'], wp_json_encode( $data ) );
			$data = json_decode( $data['result']['content'][0]['text'], true );
		}
		if ( isset( $data['results'] ) ) {
			$data = $data['results'][0]['data'];
		}
		$this->assertTrue( $data['dry_run'] );
	}

	public static function non_json_media_parameter_paths(): array {
		$cases = array();
		foreach ( array( 'REST', 'batch' ) as $door ) {
			foreach ( array(
				'application/x-www-form-urlencoded; x=application/json',
				'application/x-www-form-urlencoded; x=multipart/form-data',
				' Application/X-WWW-Form-Urlencoded ; charset=utf-8; boundary="json"',
				'multipart/form-data; x=application/json; boundary="form"',
				'Multipart/Form-Data; charset=utf-8; boundary="application/json"',
			) as $header ) {
				$cases[ $door . ' ' . $header ] = array( $door, $header );
			}
		}
		return $cases;
	}

	/**
	 * @dataProvider non_json_media_parameter_paths
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_header_parameters_do_not_block_supported_plain_creates( string $door, string $header ): void {
		$this->install_elementor( false );
		$this->dispatch_template_args( 'REST', array( 'dry_run' => true ) );
		$multipart = 0 === stripos( $header, 'multipart/form-data' );
		$body = array( 'title' => 'Media create', 'type' => 'single' );
		if ( ! $multipart ) {
			$body['dry_run'] = '0';
		}
		if ( 'batch' === $door ) {
			$body = array( 'operations' => array( array( 'method' => 'POST', 'path' => '/theme-builder/templates', 'body' => $body ) ) );
		}
		$request = new WP_REST_Request( 'POST', 'REST' === $door ? '/mcpwp/v1/theme-builder/templates' : '/mcpwp/v1/batch', $body, array( 'Content-Type' => $header ) );
		if ( ! $multipart ) {
			$request->set_body( http_build_query( $body ) );
		}
		$response = 'REST' === $door ? ( new Mcpwp_REST_Theme_Builder( new Mcpwp_Theme_Builder() ) )->create_theme_template( $request ) : ( new Mcpwp_REST_Batch() )->handle_batch( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		if ( 'batch' === $door ) {
			$this->assertSame( 200, $data['results'][0]['status'] );
			$data = $data['results'][0]['data'];
		}
		$this->assertArrayHasKey( 'status', $data );
		$this->assertSame( 'publish', $data['status'] );
		$this->assertCount( 1, $GLOBALS['_mcpwp_test_posts'] );
	}

	public static function unknown_media_intent_paths(): array {
		$cases = array();
		foreach ( array( 'text/plain; x=application/json', 'application/octet-stream; x=multipart/form-data', 'application/problem+json; boundary="form"' ) as $header ) {
			foreach ( array( false, true ) as $visible ) {
				$cases[ $header . ' visible=' . (int) $visible ] = array( $header, $visible );
			}
		}
		return $cases;
	}

	/**
	 * @dataProvider unknown_media_intent_paths
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_unknown_media_type_refuses_only_visible_intent( string $header, bool $visible ): void {
		$this->install_elementor( false );
		require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-theme-builder.php';
		$query = array( 'title' => 'Unknown media', 'type' => 'single' );
		if ( $visible ) {
			$query['metadata'] = array( 'nested' => array( 'preview' => false ) );
		}
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/theme-builder/templates', array(), array( 'Content-Type' => $header ) );
		$request->set_body( '{}' );
		$request->set_query_params( $query );
		$response = ( new Mcpwp_REST_Theme_Builder( new Mcpwp_Theme_Builder() ) )->create_theme_template( $request );
		if ( $visible ) {
			$this->assertInstanceOf( WP_Error::class, $response );
			$this->assertSame( 'invalid_params', $response->get_error_code() );
			$this->assertEmpty( $GLOBALS['_mcpwp_test_posts'] );
		} else {
			$this->assertInstanceOf( WP_REST_Response::class, $response );
			$this->assertSame( 200, $response->get_status() );
			$this->assertCount( 1, $GLOBALS['_mcpwp_test_posts'] );
		}
	}

	public static function php_native_header_spellings(): array {
		return array(
			array( 'multipart/form-data x; boundary=B', 'multipart/form-data' ),
			array( 'multipart/form-data , x; boundary=B', 'multipart/form-data' ),
			array( 'multipart/form-data,application/json; boundary=B', 'multipart/form-data' ),
			array( 'application/x-www-form-urlencoded, text/plain', 'application/x-www-form-urlencoded' ),
			array( 'application/x-www-form-urlencoded x', 'application/x-www-form-urlencoded' ),
			array( 'Multipart/Form-Data; charset=utf-8; boundary="B"', 'multipart/form-data' ),
			array( ' application/json ; x=multipart/form-data', 'application/json' ),
			array( 'APPLICATION/JSON; charset=utf-8', 'application/json' ),
			array( 'text/plain; x=application/json', 'text/plain' ),
			array( '', '' ),
		);
	}

	/** @dataProvider php_native_header_spellings */
	public function test_media_token_matches_php_sapi_rule( string $header, string $expected ): void {
		$this->assertTrue( method_exists( Mcpwp_Raw_Parameter_Source::class, 'php_media_type' ) );
		$this->assertSame( $expected, Mcpwp_Raw_Parameter_Source::php_media_type( $header ) );
	}

	public static function native_header_truncation_paths(): array {
		$cases = array();
		foreach ( array_slice( self::php_native_header_spellings(), 0, 5 ) as $case ) {
			foreach ( array( false, true ) as $batch ) {
				$cases[ $case[0] . ( $batch ? ' batch' : ' REST' ) ] = array( $case[0], $case[1], $batch );
			}
		}
		return $cases;
	}

	/**
	 * Model the exact $_POST prefix PHP exposes after truncation, retaining
	 * the complete URL-encoded wire body where PHP leaves it available.
	 * @dataProvider native_header_truncation_paths
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_php_parsed_header_variants_cannot_skip_late_intent( string $header, string $kind, bool $batch ): void {
		$this->install_elementor( false );
		$this->dispatch_template_args( 'REST', array( 'dry_run' => true ) );
		$limit = (int) ini_get( 'max_input_vars' );
		if ( $limit < 3 ) {
			$this->markTestSkipped( 'Requires a finite PHP input cap above required fields.' );
		}
		$template = array( 'title' => 'Late intent', 'type' => 'single' );
		$parsed = $batch ? array( 'operations' => array( array( 'method' => 'POST', 'path' => '/theme-builder/templates', 'body' => $template ) ) ) : $template;
		// A batch operation has method/path and two body leaves; a direct
		// template has just title/type. Fill the visible prefix to the cap.
		$required = $batch ? 4 : 2;
		for ( $i = $required; $i < $limit; ++$i ) {
			$parsed[ 'padding_' . $i ] = 'x';
		}
		$request = new WP_REST_Request( 'POST', $batch ? '/mcpwp/v1/batch' : '/mcpwp/v1/theme-builder/templates', $parsed, array( 'Content-Type' => $header ) );
		if ( 'application/x-www-form-urlencoded' === $kind ) {
			$wire = $parsed;
			for ( $i = $limit; $i < 1500; ++$i ) {
				$wire[ 'padding_' . $i ] = 'x';
			}
			if ( $batch ) {
				$wire['operations'][0]['body']['dry_run'] = '1';
				// http_build_query emits nested operation fields first. Put
				// the intent field last, exactly as in the reported request.
				unset( $wire['operations'][0]['body']['dry_run'] );
				$raw = http_build_query( $wire ) . '&operations[0][body][dry_run]=1';
			} else {
				$raw = http_build_query( $wire ) . '&dry_run=1';
			}
			$request->set_body( $raw );
		}
		$response = $batch ? ( new Mcpwp_REST_Batch() )->handle_batch( $request ) : ( new Mcpwp_REST_Theme_Builder( new Mcpwp_Theme_Builder() ) )->create_theme_template( $request );
		$this->assertEmpty( $GLOBALS['_mcpwp_test_posts'], 'A PHP-parsed form must not fall through unknown-media handling after input truncation.' );
		$this->assertEmpty( $GLOBALS['_mcpwp_test_meta'] );
		if ( $response instanceof WP_Error ) {
			$this->assertSame( 'invalid_params', $response->get_error_code() );
		} else {
			$data = $response->get_data();
			if ( $batch ) {
				$data = $data['results'][0]['data'];
			}
			$this->assertTrue( isset( $data['code'] ) || ! empty( $data['dry_run'] ) );
		}
	}

	public static function query_only_intent_paths(): array {
		return array( array( '1', false ), array( 'false', false ), array( '1', true ), array( null, false ), array( '1', false, 'text/plain' ), array( 'false', false, 'application/octet-stream' ) );
	}

	/**
	 * @dataProvider query_only_intent_paths
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_bodyless_headerless_query_uses_normal_intent_rules( $flag, bool $method_override, string $header = '' ): void {
		$this->install_elementor( false );
		require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-theme-builder.php';
		$query = array( 'rest_route' => '/mcpwp/v1/theme-builder/templates', 'title' => 'Query only', 'type' => 'single' );
		if ( null !== $flag ) {
			$query['dry_run'] = $flag;
		}
		if ( $method_override ) {
			$query['_method'] = 'POST';
		}
		$_SERVER['QUERY_STRING'] = http_build_query( $query );
		// WordPress has already resolved GET's _method=POST before calling
		// the create callback; preserve the original override in the query.
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/theme-builder/templates', array(), '' === $header ? array() : array( 'Content-Type' => $header ) );
		$request->set_query_params( $query );
		$response = ( new Mcpwp_REST_Theme_Builder( new Mcpwp_Theme_Builder() ) )->create_theme_template( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response, 'No body means there is no unknown body format to reject.' );
		$this->assertSame( 200, $response->get_status() );
		if ( '1' === $flag ) {
			$this->assertTrue( $response->get_data()['dry_run'] );
			$this->assertEmpty( $GLOBALS['_mcpwp_test_posts'] );
		} else {
			$this->assertSame( 'publish', $response->get_data()['status'] );
			$this->assertCount( 1, $GLOBALS['_mcpwp_test_posts'] );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_internal_json_post_bucket_is_not_php_form_input(): void {
		$this->install_elementor( false );
		define( 'MCPWP_TESTS', true );
		$elements = $this->minimal_elements();
		for ( $i = 0; $i <= (int) ini_get( 'max_input_vars' ); ++$i ) {
			$elements[0]['settings'][ 'custom_' . $i ] = 'x';
		}
		$data = $this->dispatch_template_args( 'MCP', array( 'elementor_data' => $elements ) )->get_data();
		$this->assertFalse( $data['result']['isError'], wp_json_encode( $data ) );
		$this->assertCount( 1, $GLOBALS['_mcpwp_test_posts'] );
		$this->assertSame( 1, $GLOBALS['mcpwp_theme_test_save_calls'] );
	}

	public static function dual_php_wordpress_json_paths(): array {
		return array( array( 'REST' ), array( 'MCP' ), array( 'mcpwp_call' ), array( 'batch' ), array( 'wp_batch' ) );
	}

	/**
	 * PHP may parse a form view while WordPress also accepts the comma-listed
	 * JSON media type. Preserve BOTH actual decoder views, not just one.
	 * @dataProvider dual_php_wordpress_json_paths
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_php_form_prefix_does_not_erase_wordpress_json_evidence( string $door ): void {
		$this->install_elementor( false );
		define( 'MCPWP_TESTS', true );
		$this->dispatch_template_args( 'REST', array( 'dry_run' => true ) );
		$body = '{"title":"Dual decoder","type":"single","dry_run":true,"dry_run":false}';
		if ( in_array( $door, array( 'batch', 'wp_batch' ), true ) ) {
			$body = '{"operations":[{"method":"POST","path":"/theme-builder/templates","body":' . $body . '}]}';
		}
		if ( in_array( $door, array( 'MCP', 'mcpwp_call', 'wp_batch' ), true ) ) {
			$params = '{"name":"' . ( 'wp_batch' === $door ? 'wp_batch' : 'wp_create_theme_template' ) . '","arguments":' . $body . '}';
			if ( 'mcpwp_call' === $door ) {
				$params = '{"name":"mcpwp_call","arguments":' . $params . '}';
			}
			$body = '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":' . $params . '}';
		}
		parse_str( $body, $post );
		$request = new WP_REST_Request( 'POST', 'REST' === $door ? '/mcpwp/v1/theme-builder/templates' : ( 'batch' === $door ? '/mcpwp/v1/batch' : '/mcpwp/v1/mcp' ), $post, array( 'Content-Type' => 'application/x-www-form-urlencoded,application/json' ) );
		$request->set_body( $body );
		$response = 'REST' === $door ? ( new Mcpwp_REST_Theme_Builder( new Mcpwp_Theme_Builder() ) )->create_theme_template( $request ) : ( 'batch' === $door ? ( new Mcpwp_REST_Batch() )->handle_batch( $request ) : ( new Mcpwp_REST_MCP() )->handle_mcp( $request ) );
		$this->assertEmpty( $GLOBALS['_mcpwp_test_posts'], 'The WordPress JSON view must retain repeated intent when PHP also parsed a form view.' );
		$this->assertEmpty( $GLOBALS['_mcpwp_test_meta'] );
	}

	public static function parsed_body_backstop_paths(): array {
		return array( array( '', false ), array( '', true ), array( 'text/plain', false ), array( 'text/plain', true ) );
	}

	/**
	 * A pre-parsed body is authoritative input even when its header is absent
	 * or was not recognized. It must retain the request-wide cap guard.
	 * @dataProvider parsed_body_backstop_paths
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_parsed_body_cannot_be_filed_as_unknown( string $header, bool $has_wire ): void {
		$this->install_elementor( false );
		require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-theme-builder.php';
		$limit = (int) ini_get( 'max_input_vars' );
		if ( $limit < 3 ) {
			$this->markTestSkipped( 'Requires a finite input cap above required fields.' );
		}
		$post = array( 'title' => 'Preparsed', 'type' => 'single' );
		for ( $i = 2; $i < $limit; ++$i ) {
			$post[ 'padding_' . $i ] = 'x';
		}
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/theme-builder/templates', $post, '' === $header ? array() : array( 'Content-Type' => $header ) );
		if ( $has_wire ) {
			$request->set_body( http_build_query( $post ) );
		}
		$response = ( new Mcpwp_REST_Theme_Builder( new Mcpwp_Theme_Builder() ) )->create_theme_template( $request );
		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'invalid_params', $response->get_error_code() );
		$this->assertEmpty( $GLOBALS['_mcpwp_test_posts'] );
	}

}
