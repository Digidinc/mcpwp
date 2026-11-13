<?php

use PHPUnit\Framework\TestCase;

final class CustomCodeViewRecoveryTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options'] = array();
		$GLOBALS['mcpwp_test_wpdb_options'] = array();
		$GLOBALS['_mcpwp_test_posts'] = array();
		$GLOBALS['_mcpwp_test_meta'] = array();
		Mcpwp_Custom_Code_Conditions::register_hooks();
		foreach ( array( 15, 16 ) as $id ) {
			$GLOBALS['_mcpwp_test_posts'][ $id ] = new WP_Post( array( 'ID' => $id, 'post_type' => 'elementor_snippet', 'post_status' => 'publish' ) );
			update_post_meta( $id, '_elementor_conditions', 15 === $id ? array( 'include/general' ) : array( array( 'type' => 'include', 'name' => 'singular', 'sub_id' => '5' ) ) );
			update_post_meta( $id, '_elementor_location', 'elementor_head' );
		}
		update_option( 'elementor_pro_theme_builder_conditions', array( 'elementor_head' => array( 15 => array( 'include/general' ), 16 => array( 'include/general' ) ) ) );
		$this->new_request();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['mcpwp_test_wpdb_intercept'] );
	}

	private function new_request(): void {
		foreach ( array( 'entry_overlay' => null, 'inspection' => array(), 'condition_lengths' => array(), 'filtered_views' => array(), 'pre_view_read' => null, 'view_dirty' => false, 'active_view' => null ) as $name => $value ) {
			$property = new ReflectionProperty( Mcpwp_Custom_Code_Conditions::class, $name );
			$property->setAccessible( true );
			$property->setValue( null, $value );
		}
	}

	public function test_first_cold_read_stores_a_view_and_the_next_request_uses_it(): void {
		$cache = get_option( 'elementor_pro_theme_builder_conditions' );
		$this->assertSame( array( 'include/general' ), $cache['elementor_head'][15] );
		$this->assertTrue( Mcpwp_Custom_Code_Cache_View::read()['hit'], 'A read must heal the missing acceleration row.' );
		$this->new_request();
		$GLOBALS['mcpwp_test_wpdb_intercept'] = static function ( $method, $sql ) {
			if ( 'get_results' === $method && false !== strpos( $sql, 'option_name LIKE' ) ) {
				throw new Error( 'Warm reads must not rebuild the overlay.' );
			}
			return MCPWP_TEST_WPDB_PASS;
		};
		$this->assertSame( $cache, get_option( 'elementor_pro_theme_builder_conditions' ) );
	}

	/** @dataProvider thrown_reads */
	public function test_thrown_reads_do_not_escape_or_pass_an_unvalidated_snippet( $fault ): void {
		$GLOBALS['mcpwp_test_wpdb_intercept'] = static function ( $method, $sql ) use ( $fault ) {
			if ( ( 'overlay' === $fault && 'get_results' === $method && false !== strpos( $sql, 'option_name LIKE' ) )
				|| ( 'inspection' === $fault && 'get_results' === $method && false !== strpos( $sql, 'OCTET_LENGTH(meta_value)' ) )
				|| ( 'snapshot' === $fault && 'get_row' === $method && false !== strpos( $sql, 'snapshot_bytes' ) ) ) {
				throw new Error( 'Injected ' . $fault . ' fault' );
			}
			return MCPWP_TEST_WPDB_PASS;
		};
		$cache = get_option( 'elementor_pro_theme_builder_conditions' );
		$this->assertSame( array( 'include/general' ), $cache['elementor_head'][15] );
		$this->assertArrayNotHasKey( 16, $cache['elementor_head'] );
		$this->assertSame( 'healthy', Mcpwp_Custom_Code_Conditions::annotate_response( array( 'title' => 'healthy' ) )['title'], 'Diagnostic enrichment must not undo successful read recovery.' );
	}

	public function test_overlay_failure_uses_the_last_good_snapshot_even_after_revision_change(): void {
		get_option( 'elementor_pro_theme_builder_conditions' );
		$this->assertTrue( Mcpwp_Custom_Code_Cache_View::read()['hit'] );
		Mcpwp_Custom_Code_Cache_View::invalidate();
		$GLOBALS['mcpwp_test_options']['elementor_pro_theme_builder_conditions'] = array();
		$this->new_request();
		$GLOBALS['mcpwp_test_wpdb_intercept'] = static function ( $method, $sql ) {
			if ( 'get_results' === $method && false !== strpos( $sql, 'option_name LIKE' ) ) {
				throw new Error( 'Persistent overlay failure' );
			}
			return MCPWP_TEST_WPDB_PASS;
		};
		$cache = get_option( 'elementor_pro_theme_builder_conditions' );
		$this->assertSame( array( 'include/general' ), $cache['elementor_head'][15] );
		$this->assertArrayNotHasKey( 16, $cache['elementor_head'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_last_good_recovery_cannot_print_a_newly_excluded_snippet(): void {
		ini_set( 'error_log', '/dev/null' );
		require_once __DIR__ . '/fixtures/custom-code-print-document.php';
		get_option( 'elementor_pro_theme_builder_conditions' );
		$GLOBALS['_mcpwp_test_meta'][15]['_elementor_conditions'] = array( 'exclude/general' );
		wp_cache_delete( 15, 'post_meta' );
		Mcpwp_Custom_Code_Cache_View::invalidate();
		$this->new_request();
		$GLOBALS['mcpwp_test_wpdb_intercept'] = static function ( $method, $sql ) {
			if ( 'get_results' === $method && false !== strpos( $sql, 'option_name LIKE' ) ) {
				throw new Error( 'Persistent overlay failure' );
			}
			return MCPWP_TEST_WPDB_PASS;
		};
		get_option( 'elementor_pro_theme_builder_conditions' );
		$this->assertSame( array( '' ), \ElementorPro\Modules\CustomCode\Document::print_content( 15 ) );
	}

	public static function thrown_reads(): array {
		return array( array( 'overlay' ), array( 'inspection' ), array( 'snapshot' ) );
	}

	public function test_a_corrupt_snapshot_is_rebuilt_without_losing_a_healthy_neighbour(): void {
		$GLOBALS['mcpwp_test_options'][ Mcpwp_Custom_Code_Cache_View::OPTION ] = 'not a snapshot';
		$cache = get_option( 'elementor_pro_theme_builder_conditions' );
		$this->assertSame( array( 'include/general' ), $cache['elementor_head'][15] );
		$this->assertArrayNotHasKey( 16, $cache['elementor_head'] );
	}
}
