<?php
/**
 * Regression tests for AIOSEO write persistence + readback verification (GH #672).
 *
 * wp_set_seo previously reported success while AIOSEO silently dropped the
 * per-post robots flags (gated behind robots_default) and callers could not
 * tell an echoed request from a stored update. These tests pin:
 *   - robots_noindex/nofollow are written as integer flags with robots_default=0
 *   - a successful write reads back the submitted values
 *   - a silent drop is surfaced as a seo_not_persisted WP_Error, not success
 *   - a hard DB failure surfaces as seo_write_failed
 *
 * @package SitePilotAI\Tests
 */

require_once dirname(__DIR__) . '/includes/pro/core/class-mcpwp-seo.php';

if (! defined('ARRAY_A')) {
	define('ARRAY_A', 'ARRAY_A');
}

/** Minimal in-memory $wpdb double for the aioseo_posts table. */
class Mcpwp_Test_Aioseo_Wpdb {
	public $prefix = 'wp_';
	public $last_error = '';
	/** @var array<int,array<string,mixed>> */
	public $rows = array();
	/** Columns the "integration" silently ignores on write (simulates AIOSEO gating). */
	public $drop_columns = array();
	/** When true, every write reports a hard failure. */
	public $fail_writes = false;
	private $last_args = array();

	public function prepare( $query, ...$args ) {
		$this->last_args = $args;
		return $query;
	}

	private function pid() {
		return isset( $this->last_args[0] ) ? (int) $this->last_args[0] : 0;
	}

	public function get_var( $query ) {
		$pid = $this->pid();
		if ( false !== stripos( $query, 'COUNT(' ) ) {
			return isset( $this->rows[ $pid ] ) ? 1 : 0;
		}
		if ( false !== stripos( $query, 'SELECT description' ) ) {
			return isset( $this->rows[ $pid ] ) ? ( $this->rows[ $pid ]['description'] ?? '' ) : null;
		}
		return null;
	}

	public function get_row( $query, $output = null ) {
		$pid = $this->pid();
		return isset( $this->rows[ $pid ] ) ? $this->rows[ $pid ] : null;
	}

	public function insert( $table, $data ) {
		return $this->write( $data, isset( $data['post_id'] ) ? (int) $data['post_id'] : 0 );
	}

	public function update( $table, $data, $where ) {
		return $this->write( $data, isset( $where['post_id'] ) ? (int) $where['post_id'] : 0 );
	}

	private function write( $data, $pid ) {
		if ( $this->fail_writes ) {
			$this->last_error = 'simulated failure';
			return false;
		}
		if ( ! isset( $this->rows[ $pid ] ) ) {
			$this->rows[ $pid ] = array( 'post_id' => $pid );
		}
		foreach ( $data as $col => $val ) {
			if ( in_array( $col, $this->drop_columns, true ) ) {
				continue; // integration silently ignores this column
			}
			$this->rows[ $pid ][ $col ] = $val;
		}
		return 1;
	}
}

class AioseoSeoPersistenceTest extends PHPUnit\Framework\TestCase {

	/** @var Mcpwp_SEO */
	private $seo;
	/** @var Mcpwp_Test_Aioseo_Wpdb */
	private $wpdb;
	private $post_id = 5781;

	protected function setUp(): void {
		$this->seo  = new Mcpwp_SEO();
		$this->wpdb = new Mcpwp_Test_Aioseo_Wpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$GLOBALS['_mcpwp_test_posts'] = array(
			$this->post_id => (object) array( 'ID' => $this->post_id, 'post_type' => 'page', 'post_status' => 'draft' ),
		);
	}

	private function set_seo( array $data ) {
		return $this->seo->update_post_seo( $this->post_id, $data, 'aioseo' );
	}

	public function test_robots_persist_with_robots_default_cleared() {
		$result = $this->set_seo( array(
			'description'     => 'A stored draft description.',
			'robots_noindex'  => true,
			'robots_nofollow' => true,
		) );

		$this->assertIsArray( $result, 'A persisted write should return the stored data, not an error.' );
		$this->assertSame( 1, $result['robots_noindex'] );
		$this->assertSame( 1, $result['robots_nofollow'] );

		$row = $this->wpdb->rows[ $this->post_id ];
		$this->assertSame( 0, $row['robots_default'], 'robots_default must be cleared so AIOSEO honors the per-post flags.' );
		$this->assertSame( 1, $row['robots_noindex'] );
		$this->assertSame( 'A stored draft description.', $row['description'] );
	}

	public function test_string_robots_values_are_cast_to_flags() {
		$this->set_seo( array( 'robots_noindex' => 'false', 'robots_nofollow' => '1' ) );
		$row = $this->wpdb->rows[ $this->post_id ];
		$this->assertSame( 0, $row['robots_noindex'] );
		$this->assertSame( 1, $row['robots_nofollow'] );
	}

	public function test_description_persists_and_reads_back() {
		$result = $this->set_seo( array( 'title' => 'T', 'description' => 'Hello world.' ) );
		$this->assertIsArray( $result );
		$this->assertSame( 'Hello world.', $result['description'] );
		$this->assertSame( 'T', $result['title'] );
	}

	public function test_silent_drop_is_reported_not_success() {
		// Simulate AIOSEO ignoring the robots column despite reporting a write.
		$this->wpdb->drop_columns = array( 'robots_noindex' );

		$result = $this->set_seo( array( 'robots_noindex' => true ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'seo_not_persisted', $result->get_error_code() );
	}

	public function test_noindex_alias_maps_to_robots_column() {
		$result = $this->set_seo( array( 'noindex' => true, 'nofollow' => false ) );

		$this->assertIsArray( $result );
		$row = $this->wpdb->rows[ $this->post_id ];
		$this->assertSame( 1, $row['robots_noindex'] );
		$this->assertSame( 0, $row['robots_nofollow'] );
		$this->assertSame( 0, $row['robots_default'] );
	}

	public function test_hard_write_failure_is_surfaced() {
		$this->wpdb->fail_writes = true;

		$result = $this->set_seo( array( 'description' => 'x' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'seo_write_failed', $result->get_error_code() );
	}
}
