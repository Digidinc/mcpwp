<?php
/**
 * #989: the Theme Builder templates assigned to a post are read from Elementor
 * Pro's own condition matching, with the global query pointed at that post.
 * The query and post are restored afterwards, and Elementor Pro's per-request
 * location cache is cleared before and after, so one post's answer is never
 * served for another.
 *
 * Each test runs in a separate process because the fixture declares
 * ElementorPro classes.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class EffectiveTemplateThemeBuilderTest extends TestCase {

	private function install(): void {
		require_once __DIR__ . '/fixtures/elementor-pro-conditions-doubles.php';

		$GLOBALS['mcpwp_test_tb_clears']      = 0;
		$GLOBALS['mcpwp_test_tb_throw']       = false;
		$GLOBALS['mcpwp_test_tb_assignments'] = array(
			10 => array(
				'header' => array( 399280 ),
				'single' => array( 500 ),
			),
			20 => array( 'footer' => array( 600 ) ),
		);
		$GLOBALS['_mcpwp_test_posts']         = array(
			10 => new WP_Post( array( 'ID' => 10, 'post_type' => 'page', 'post_status' => 'draft' ) ),
			20 => new WP_Post( array( 'ID' => 20, 'post_type' => 'post', 'post_status' => 'publish' ) ),
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_assigned_locations_come_from_conditions_for_that_post(): void {
		$this->install();
		$original_query      = new stdClass();
		$GLOBALS['wp_query'] = $original_query;
		$GLOBALS['post']     = $GLOBALS['_mcpwp_test_posts'][20];
		unset( $GLOBALS['wp_the_query'] );

		$assigned = Mcpwp_Effective_Template::assigned_for_post( $GLOBALS['_mcpwp_test_posts'][10] );

		$this->assertTrue( $assigned['theme_builder']['evaluated'] );
		$this->assertSame(
			array(
				'header' => array( 399280 ),
				'single' => array( 500 ),
			),
			$assigned['theme_builder']['locations']
		);

		$this->assertSame( $original_query, $GLOBALS['wp_query'], 'The global query is restored.' );
		$this->assertSame( 20, $GLOBALS['post']->ID, 'The global post is restored.' );
		$this->assertArrayNotHasKey( 'wp_the_query', $GLOBALS, 'A global that did not exist before is not left behind.' );
		$this->assertSame( 2, $GLOBALS['mcpwp_test_tb_clears'], 'The location cache is cleared before and after.' );

		// A second post in the same request gets its own answer, not the
		// first post's cached one.
		$second = Mcpwp_Effective_Template::assigned_for_post( $GLOBALS['_mcpwp_test_posts'][20] );
		$this->assertSame( array( 'footer' => array( 600 ) ), $second['theme_builder']['locations'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_throwing_evaluation_reports_and_restores(): void {
		$this->install();
		$GLOBALS['mcpwp_test_tb_throw'] = true;
		unset( $GLOBALS['post'] );

		$assigned = Mcpwp_Effective_Template::assigned_for_post( $GLOBALS['_mcpwp_test_posts'][10] );

		$this->assertFalse( $assigned['theme_builder']['evaluated'] );
		$this->assertSame( 'evaluation_failed', $assigned['theme_builder']['reason'] );
		$this->assertArrayNotHasKey( 'error', $assigned['theme_builder'], 'Only a reason code: no exception class or message reaches the caller.' );
		$this->assertStringNotContainsString( 'conditions exploded', wp_json_encode( $assigned ) );
		$this->assertArrayNotHasKey( 'post', $GLOBALS );
		$this->assertSame( 2, $GLOBALS['mcpwp_test_tb_clears'] );
	}
}
