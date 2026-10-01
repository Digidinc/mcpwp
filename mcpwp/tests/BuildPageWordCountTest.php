<?php
/**
 * Regression coverage for #998 — wp_build_page reported
 * guardrails.thin_content.word_count: 0 for a page with real, visible text.
 *
 * Root cause: Mcpwp_Page_Builder::build() used to insert the page with NO
 * Elementor data at all (wp_insert_post() with only title/status), then
 * write the built elements to _elementor_data postmeta separately,
 * afterward. Mcpwp_Content_Guardrails::on_insert_post_data() — the ONE
 * place thin/duplicate content is judged (#957) — runs inside that first
 * wp_insert_post() call, before the second write ever happens, so it always
 * scored an empty page: word_count 0, no matter how much text the built
 * sections actually contained. Harmless for the ramp/duplicate decision
 * (thin content only ever warns, never downgrades — see
 * resolve_comparable_texts()'s docblock and
 * test_elementor_first_page_with_zero_words_at_create_still_publishes_with_warning
 * in ContentGuardrailsTest.php) but a flatly false number reported back to
 * every caller of wp_build_page.
 *
 * The fix: front-load the built $elements into meta_input on the SAME
 * wp_insert_post() call, exactly like create_landing_page() and
 * clone_elementor_page() already do — so the guardrails gate reads the
 * page's real content the first time it looks.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-encryption.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-content-guardrails.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-seo-audit-store.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-integration-manager.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-approvals.php';
require_once dirname( __DIR__ ) . '/includes/pro/core/class-mcpwp-page-builder.php';

final class BuildPageWordCountTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_wpdb_options']  = array();
		$GLOBALS['mcpwp_test_transients']    = array();
		$GLOBALS['_mcpwp_test_posts']        = array();
		$GLOBALS['_mcpwp_test_meta']         = array();
		$GLOBALS['mcpwp_test_current_user']  = 2;
		$GLOBALS['mcpwp_test_actions']       = array();
		$GLOBALS['mcpwp_test_filters']       = array();
		$GLOBALS['mcpwp_test_is_admin']      = false;
		$GLOBALS['mcpwp_test_doing_ajax']    = false;
		Mcpwp_Content_Guardrails::reset_context_for_tests();
		Mcpwp_Content_Guardrails::register_hooks();
	}

	protected function tearDown(): void {
		$GLOBALS['_mcpwp_test_posts'] = array();
		$GLOBALS['_mcpwp_test_meta']  = array();
		Mcpwp_Content_Guardrails::reset_context_for_tests();
	}

	/**
	 * Satisfy Mcpwp_Elementor_Basic::is_active(), which is just
	 * `defined( 'ELEMENTOR_VERSION' )`. Every test that calls this must run
	 * in its own process (see ElementorBuilderFlagTest.php's own note:
	 * defining it in the shared run changes every Elementor-sensitive test
	 * that follows, #757).
	 */
	private function pretend_elementor_is_installed(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}
	}

	private function words( $count, $prefix = 'word' ) {
		$out = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$out[] = $prefix . $i;
		}
		return implode( ' ', $out );
	}

	/**
	 * The exact case reported in #998: a heading + paragraph text section,
	 * about 13 visible words total, built via wp_build_page.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_build_page_with_heading_and_paragraph_reports_the_real_word_count(): void {
		$this->pretend_elementor_is_installed();
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 150, 'daily_publish_cap' => 1000 ) );

		$heading = $this->words( 3, 'head' );   // 3 words.
		$content = $this->words( 10, 'body' );  // 10 words.

		$builder = new Mcpwp_Page_Builder();
		$result  = $builder->build(
			'Thin Content Regression',
			array(
				array(
					'type'    => 'text',
					'heading' => $heading,
					'content' => $content,
				),
			),
			'publish'
		);

		$this->assertIsArray( $result, 'build() must not fail: ' . ( $result instanceof WP_Error ? $result->get_error_message() : '' ) );
		$page_id = $result['id'];

		$response = array( 'id' => $page_id );
		Mcpwp_Content_Guardrails::attach_notices_to_response( $response );

		$this->assertArrayHasKey( 'guardrails', $response, 'the create-time guardrails notice must be attached for this page' );
		$this->assertSame(
			13,
			$response['guardrails']['thin_content']['word_count'],
			'word_count must reflect the 13 real words the built Elementor sections contain, not 0'
		);
		$this->assertTrue( $response['guardrails']['thin_content']['is_thin'], '13 words is still below the 150-word floor set above' );
		// Thin content is warning-only — it must never downgrade the write.
		$this->assertFalse( $response['guardrails']['downgraded'] );
	}

	/**
	 * A genuinely thin build (~3 words, no paragraph) must still warn —
	 * the fix must not suppress the real warning, only the false "0" one.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_build_page_with_three_words_still_warns_thin(): void {
		$this->pretend_elementor_is_installed();
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 150, 'daily_publish_cap' => 1000 ) );

		$builder = new Mcpwp_Page_Builder();
		$result  = $builder->build(
			'Genuinely Thin Page',
			array(
				array(
					'type'    => 'text',
					'heading' => $this->words( 3, 'tiny' ),
				),
			),
			'publish'
		);

		$this->assertIsArray( $result, 'build() must not fail: ' . ( $result instanceof WP_Error ? $result->get_error_message() : '' ) );
		$page_id = $result['id'];

		$response = array( 'id' => $page_id );
		Mcpwp_Content_Guardrails::attach_notices_to_response( $response );

		$this->assertSame( 3, $response['guardrails']['thin_content']['word_count'] );
		$this->assertTrue( $response['guardrails']['thin_content']['is_thin'] );
		$this->assertFalse( $response['guardrails']['downgraded'], 'thin content only ever warns, never downgrades' );
		$this->assertNotEmpty( $response['warnings'], 'a genuinely thin build must still carry a thin-content warning' );
	}

	/**
	 * wp_build_page's response was missing render_verified entirely — every
	 * OTHER Elementor write tool (wp_set_elementor, wp_apply_elementor_part,
	 * ...) reports it via the shared decorate_render_status() path. Build's
	 * hand-assembled response array dropped it on the floor, so a caller had
	 * no way to know the field even existed for this tool.
	 *
	 * This test double has no real \Elementor\Plugin loaded, so
	 * decorate_render_status() correctly reports render_verified:false here
	 * ("no completed Elementor render call was observed") — the same thing
	 * ElementorBuilderFlagTest's own set_elementor_data() tests see in this
	 * environment. What #998 actually broke is the KEY being present at all
	 * on wp_build_page's response; this pins parity with wp_set_elementor,
	 * not a particular verdict.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_build_page_response_reports_render_verified(): void {
		$this->pretend_elementor_is_installed();
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 0, 'daily_publish_cap' => 1000 ) );

		$builder = new Mcpwp_Page_Builder();
		$result  = $builder->build(
			'Render Verified Probe',
			array(
				array(
					'type'    => 'text',
					'heading' => 'Hello World',
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'render_verified', $result, 'wp_build_page must report render_verified like wp_set_elementor does' );
		$this->assertIsBool( $result['render_verified'] );
		$this->assertArrayHasKey( 'renders_via_elementor', $result, 'the flag-write outcome should pass through too, same as wp_set_elementor' );
		$this->assertTrue( $result['renders_via_elementor'], 'the builder flag write itself succeeds in this environment' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_failed_elementor_data_write_leaves_no_built_page(): void {
		$this->pretend_elementor_is_installed();
		$status_during_write = null;
		add_filter( 'add_post_metadata', static function ( $check, $post_id, $key ) use ( &$status_during_write ) {
			if ( '_elementor_data' === $key ) {
				$status_during_write = get_post_status( $post_id );
				return false;
			}
			return $check;
		}, 1, 3 );

		$result = ( new Mcpwp_Page_Builder() )->build( 'Failed build', array( array( 'type' => 'text', 'heading' => 'Heading' ) ), 'publish' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'draft', $status_during_write, 'The built page must stay private until its tree is verified.' );
		$this->assertEmpty( $GLOBALS['_mcpwp_test_posts'], 'The incomplete page must not remain published or draft.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_publish_hook_replacing_the_tree_with_the_same_count_is_refused(): void {
		$this->pretend_elementor_is_installed();
		add_action( 'post_updated', static function ( $post_id, $post ) {
			if ( 'Mutation build' === $post->post_title && 'publish' === $post->post_status ) {
				$GLOBALS['_mcpwp_test_meta'][ $post_id ]['_elementor_data'] = '[{"id":"mut0001","elType":"section","settings":{},"elements":[]}]';
			}
		}, 10, 2 );

		$result = ( new Mcpwp_Page_Builder() )->build( 'Mutation build', array( array( 'type' => 'text', 'heading' => 'Safe heading' ) ), 'publish' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEmpty( $GLOBALS['_mcpwp_test_posts'] );
	}
}
