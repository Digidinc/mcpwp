<?php
/**
 * #1031 round 3 (adversarial review WARN-1).
 *
 * The action-log schema-add-column and backfill migrations
 * (Mcpwp_Activator::ensure_action_log_resource_post_type_column() /
 * ::backfill_action_log_resource_post_type()) are both called from
 * mcpwp.php's plugins_loaded handler, gated only on their own completion
 * flag. Before that flag is set, EVERY concurrent request re-enters them —
 * a cold first dbDelta() ALTER, or a large backfill batch, could otherwise
 * run in parallel across every in-flight request (parallel ALTERs; a
 * backfill batch double-scanned). Both are now guarded by the same
 * non-blocking CAS mutex repair_custom_code_locations() already uses
 * (Mcpwp_Activator::acquire_migration_lock()/release_migration_lock(),
 * generalised from that migration's own lock pair) — a request that finds
 * the lock held simply skips the work for itself rather than waiting or
 * running unprotected.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-mcpwp-activator.php';

/**
 * Minimal in-memory $wpdb double: the options row the lock lives in (same
 * $GLOBALS['mcpwp_test_wpdb_options'] convention
 * CustomCodeLocationRepairMigrationTest.php already uses for the same kind
 * of lock), plus a tiny action-log row store for the backfill's own
 * SELECT/UPDATE.
 */
class Mcpwp_Test_Action_Log_Migration_Wpdb {
	public $prefix     = 'wp_';
	public $options    = 'wp_options';
	public $last_error = '';

	/** @var array<int,array<string,mixed>> Keyed by numeric row id. */
	public $rows = array();

	private $last_args = array();

	public function suppress_errors( $suppress = true ) {
		return false;
	}

	public function prepare( $query, ...$args ) {
		if ( isset( $args[0] ) && is_array( $args[0] ) && 1 === count( $args ) ) {
			$args = $args[0];
		}
		$this->last_args = $args;
		$i                = 0;
		return preg_replace_callback(
			'/%[ds]/',
			function ( $m ) use ( &$i ) {
				$v = $this->last_args[ $i++ ] ?? '';
				return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
			},
			$query
		);
	}

	public function insert( $table, $data, $format = null ) {
		if ( $table === $this->options ) {
			$name = (string) $data['option_name'];
			if ( array_key_exists( $name, $GLOBALS['mcpwp_test_wpdb_options'] ?? array() ) ) {
				$this->last_error = 'Duplicate entry';
				return false;
			}
			$GLOBALS['mcpwp_test_wpdb_options'][ $name ] = (string) $data['option_value'];
			return 1;
		}
		return 1;
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		if ( $table === $this->options && isset( $where['option_name'] ) ) {
			$name    = (string) $where['option_name'];
			$current = $GLOBALS['mcpwp_test_wpdb_options'][ $name ] ?? null;
			if ( isset( $where['option_value'] ) && (string) $where['option_value'] !== (string) $current ) {
				return 0; // CAS miss.
			}
			$GLOBALS['mcpwp_test_wpdb_options'][ $name ] = (string) $data['option_value'];
			return 1;
		}

		$id = (int) ( $where['id'] ?? 0 );
		if ( isset( $this->rows[ $id ] ) ) {
			foreach ( $data as $k => $v ) {
				$this->rows[ $id ][ $k ] = $v;
			}
			return 1;
		}
		return 0;
	}

	public function delete( $table, $where, $where_format = null ) {
		if ( $table === $this->options && isset( $where['option_name'] ) ) {
			$name = (string) $where['option_name'];
			if ( array_key_exists( $name, $GLOBALS['mcpwp_test_wpdb_options'] ?? array() )
				&& ( ! isset( $where['option_value'] ) || (string) $where['option_value'] === (string) $GLOBALS['mcpwp_test_wpdb_options'][ $name ] ) ) {
				unset( $GLOBALS['mcpwp_test_wpdb_options'][ $name ] );
				return 1;
			}
			return 0;
		}
		return 0;
	}

	public function get_var( $query ) {
		if ( false !== stripos( $query, 'FROM wp_options' ) && preg_match( "/option_name = '([^']*)'/", $query, $m ) ) {
			return $GLOBALS['mcpwp_test_wpdb_options'][ $m[1] ] ?? null;
		}
		return null;
	}

	public function get_results( $query, $output = ARRAY_A ) {
		if ( ! preg_match( "/WHERE id > (\d+) AND resource_post_type = '' ORDER BY id ASC LIMIT (\d+)/", $query, $m ) ) {
			return array();
		}
		$cursor = (int) $m[1];
		$limit  = (int) $m[2];
		$rows   = array_values(
			array_filter(
				$this->rows,
				fn( $r ) => $r['id'] > $cursor && '' === $r['resource_post_type']
			)
		);
		usort( $rows, fn( $a, $b ) => $a['id'] <=> $b['id'] );
		return array_slice( $rows, 0, $limit );
	}
}

final class ActionLogMigrationLockTest extends TestCase {

	/** @var Mcpwp_Test_Action_Log_Migration_Wpdb */
	private $wpdb;
	private $previous_wpdb;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_wpdb_options'] = array();
		$GLOBALS['_mcpwp_test_posts']       = array();

		$this->wpdb          = new Mcpwp_Test_Action_Log_Migration_Wpdb();
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']     = $this->wpdb;
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->previous_wpdb;
	}

	// ── backfill_action_log_resource_post_type() ─────────────────────────────

	public function test_backfill_second_call_skips_while_locked(): void {
		$this->wpdb->rows = array(
			10 => array( 'id' => 10, 'resource_type' => 'post', 'resource_id' => '5', 'before_snap' => null, 'resource_post_type' => '' ),
		);

		// Simulate another process's run already in flight, well inside its TTL.
		$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Activator::ACTION_LOG_BACKFILL_LOCK_OPTION ] = (string) ( time() + Mcpwp_Activator::ACTION_LOG_BACKFILL_LOCK_TTL );

		Mcpwp_Activator::backfill_action_log_resource_post_type( 5.0 );

		$this->assertFalse( get_option( 'mcpwp_action_log_post_type_backfilled', false ) );
		$this->assertSame( '', $this->wpdb->rows[10]['resource_post_type'], 'the row must be untouched while the lock is held' );
	}

	public function test_backfill_proceeds_once_lock_is_free(): void {
		$GLOBALS['_mcpwp_test_posts'][5] = new WP_Post( array( 'ID' => 5, 'post_type' => 'post', 'post_status' => 'publish' ) );
		$this->wpdb->rows                = array(
			10 => array( 'id' => 10, 'resource_type' => 'post', 'resource_id' => '5', 'before_snap' => null, 'resource_post_type' => '' ),
		);

		Mcpwp_Activator::backfill_action_log_resource_post_type( 5.0 );

		$this->assertSame( 1, get_option( 'mcpwp_action_log_post_type_backfilled' ) );
		$this->assertSame( 'post', $this->wpdb->rows[10]['resource_post_type'] );
		$this->assertArrayNotHasKey( Mcpwp_Activator::ACTION_LOG_BACKFILL_LOCK_OPTION, $GLOBALS['mcpwp_test_wpdb_options'], 'the lock must be released once this run finishes' );
	}

	public function test_expired_backfill_lock_from_a_dead_holder_is_taken_over(): void {
		$GLOBALS['_mcpwp_test_posts'][5] = new WP_Post( array( 'ID' => 5, 'post_type' => 'post', 'post_status' => 'publish' ) );
		$this->wpdb->rows                = array(
			10 => array( 'id' => 10, 'resource_type' => 'post', 'resource_id' => '5', 'before_snap' => null, 'resource_post_type' => '' ),
		);

		// A lock whose expiry is already in the past — a holder that never
		// came back to release it (crash, worker recycle mid-batch).
		$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Activator::ACTION_LOG_BACKFILL_LOCK_OPTION ] = (string) ( time() - 5 );

		Mcpwp_Activator::backfill_action_log_resource_post_type( 5.0 );

		$this->assertSame( 1, get_option( 'mcpwp_action_log_post_type_backfilled' ), 'a stale lock must not block the migration forever' );
		$this->assertSame( 'post', $this->wpdb->rows[10]['resource_post_type'] );
	}

	// ── ensure_action_log_resource_post_type_column() ────────────────────────

	public function test_ensure_column_second_call_skips_while_locked(): void {
		// Simulate another process's dbDelta() run already in flight.
		$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Activator::ACTION_LOG_COLUMN_LOCK_OPTION ] = (string) ( time() + Mcpwp_Activator::ACTION_LOG_COLUMN_LOCK_TTL );

		// Never reaches dbDelta() (which needs wp-admin/includes/upgrade.php,
		// unavailable here) — the lock check returns first, which is exactly
		// the behavior under test.
		Mcpwp_Activator::ensure_action_log_resource_post_type_column();

		$this->assertFalse( get_option( 'mcpwp_action_log_resource_post_type_column', false ) );
	}
}
