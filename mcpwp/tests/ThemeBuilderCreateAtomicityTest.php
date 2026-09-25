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
	 * @param bool $throw Whether Mcpwp_Fake_Document::save() should throw.
	 */
	private function install_elementor( bool $throw ): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}
		if ( ! defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			define( 'ELEMENTOR_PRO_VERSION', '4.2.3' );
		}

		$GLOBALS['mcpwp_theme_test_throw']         = $throw;
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
}
