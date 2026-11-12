<?php
/**
 * Regression tests — #1041 fix (3): one-time migration marking existing
 * custom-role API keys with an empty category list as deny-all in storage.
 *
 * The RUNTIME fix (resolve_key_tool_categories() in trait-mcpwp-api-auth.php)
 * already closes the security hole unconditionally, regardless of what is
 * stored — see Regression1041EmptyCategoryKeyTest.php. This migration exists
 * so already-affected keys are self-documenting rather than showing a blank
 * category list, and as defense-in-depth for any future code that reads a
 * key's tool_categories directly. It must never revoke or delete a key, and
 * must never touch a key that is not custom-role-with-empty-categories.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-mcpwp-activator.php';

final class MigrateEmptyCustomCategoryKeysTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']       = array();
		// Backing store for the shared migration-lock $wpdb double (#1036) —
		// acquire_migration_lock()/release_migration_lock() CAS against this,
		// same convention as ActionLogMigrationLockTest.php.
		$GLOBALS['mcpwp_test_wpdb_options'] = array();
	}

	private function seed( string $option, array $keys ): void {
		update_option( $option, $keys );
	}

	public function test_marks_custom_keys_with_empty_categories_deny_all_without_deleting_them(): void {
		$this->seed(
			'mcpwp_api_keys',
			array(
				array( 'id' => 'a', 'role' => 'custom', 'tool_categories' => array(), 'hash' => 'h-a', 'label' => 'A' ),
				array( 'id' => 'b', 'role' => 'custom', 'label' => 'B (never had the field at all)', 'hash' => 'h-b' ),
			)
		);

		Mcpwp_Activator::migrate_empty_custom_category_keys();

		$keys = get_option( 'mcpwp_api_keys' );
		$this->assertCount( 2, $keys, 'no key may be deleted by this migration' );

		foreach ( $keys as $key ) {
			$this->assertSame( array( Mcpwp_Activator::deny_all_category_marker() ), $key['tool_categories'] );
			$this->assertArrayHasKey( 'hash', $key, 'the credential itself must be untouched' );
		}

		$this->assertSame( 1, get_option( 'mcpwp_deny_all_categories_migrated' ) );
	}

	public function test_leaves_a_custom_key_with_real_categories_untouched(): void {
		$this->seed(
			'mcpwp_api_keys',
			array(
				array( 'id' => 'c', 'role' => 'custom', 'tool_categories' => array( 'seo' ), 'hash' => 'h-c', 'label' => 'C' ),
			)
		);

		Mcpwp_Activator::migrate_empty_custom_category_keys();

		$keys = get_option( 'mcpwp_api_keys' );
		$this->assertSame( array( 'seo' ), $keys[0]['tool_categories'] );
	}

	public function test_leaves_non_custom_roles_untouched(): void {
		$this->seed(
			'mcpwp_api_keys',
			array(
				array( 'id' => 'd', 'role' => 'admin', 'tool_categories' => array(), 'hash' => 'h-d', 'label' => 'D' ),
				array( 'id' => 'e', 'role' => 'author', 'hash' => 'h-e', 'label' => 'E' ),
			)
		);

		Mcpwp_Activator::migrate_empty_custom_category_keys();

		$keys = get_option( 'mcpwp_api_keys' );
		$this->assertSame( array(), $keys[0]['tool_categories'], 'admin role is genuinely unrestricted — nothing to mark' );
		$this->assertArrayNotHasKey( 'tool_categories', $keys[1], 'a predefined role with no stored tool_categories must not gain one' );
	}

	/**
	 * #1041 NIT-2: the migration must decide "custom role" the same way
	 * normalize_api_key_record()/sanitize_key() does at runtime — not a raw
	 * `'custom' !== $key['role']` string-equality check, which missed a
	 * differently-cased or whitespace-padded stored value that the runtime
	 * auth path (which always normalizes first) already treats as 'custom'.
	 */
	public function test_marks_a_differently_cased_or_padded_custom_role(): void {
		$this->seed(
			'mcpwp_api_keys',
			array(
				array( 'id' => 'h', 'role' => 'Custom', 'tool_categories' => array(), 'hash' => 'h-h', 'label' => 'H' ),
				array( 'id' => 'i', 'role' => ' custom ', 'tool_categories' => array(), 'hash' => 'h-i', 'label' => 'I' ),
			)
		);

		Mcpwp_Activator::migrate_empty_custom_category_keys();

		$keys = get_option( 'mcpwp_api_keys' );
		foreach ( $keys as $key ) {
			$this->assertSame( array( Mcpwp_Activator::deny_all_category_marker() ), $key['tool_categories'], "key {$key['id']} (role '{$key['role']}') must be marked" );
		}
	}

	/**
	 * #1041 NIT-2: a legacy record whose tool_categories was stored as a bare
	 * string rather than an array — normalize_api_key_record()'s `(array)`
	 * cast turns 'content' into `['content']`, a real, non-empty, WORKING
	 * category at runtime. The old migration's `is_array(...)` check treated
	 * this as empty and overwrote a functioning key with deny-all; the fix
	 * must leave it alone.
	 */
	public function test_does_not_overwrite_a_raw_string_tool_categories_that_resolves_to_a_real_category(): void {
		$this->seed(
			'mcpwp_api_keys',
			array(
				array( 'id' => 'j', 'role' => 'custom', 'tool_categories' => 'content', 'hash' => 'h-j', 'label' => 'J' ),
			)
		);

		Mcpwp_Activator::migrate_empty_custom_category_keys();

		$keys = get_option( 'mcpwp_api_keys' );
		$this->assertSame( 'content', $keys[0]['tool_categories'], 'a key that actually resolves to a real category must not be rewritten to deny-all' );
	}

	public function test_covers_the_legacy_spai_api_keys_store_too(): void {
		$this->seed(
			'spai_api_keys',
			array(
				array( 'id' => 'legacy-custom', 'role' => 'custom', 'tool_categories' => array(), 'hash' => 'h-legacy', 'label' => 'pre-rename key' ),
			)
		);

		Mcpwp_Activator::migrate_empty_custom_category_keys();

		$keys = get_option( 'spai_api_keys' );
		$this->assertSame( array( Mcpwp_Activator::deny_all_category_marker() ), $keys[0]['tool_categories'] );
	}

	public function test_is_idempotent_after_flag_set(): void {
		$this->seed(
			'mcpwp_api_keys',
			array( array( 'id' => 'f', 'role' => 'custom', 'tool_categories' => array(), 'hash' => 'h-f', 'label' => 'F' ) )
		);
		Mcpwp_Activator::migrate_empty_custom_category_keys();
		$this->assertSame( array( Mcpwp_Activator::deny_all_category_marker() ), get_option( 'mcpwp_api_keys' )[0]['tool_categories'] );

		// A new custom key minted later with an empty list (e.g. hand-seeded,
		// bypassing create_scoped_api_key()'s own refusal) must NOT be rewritten
		// by a later run — the migration runs once only, same convention as
		// migrate_identity_less_approvals().
		$keys   = get_option( 'mcpwp_api_keys' );
		$keys[] = array( 'id' => 'g', 'role' => 'custom', 'tool_categories' => array(), 'hash' => 'h-g', 'label' => 'G' );
		update_option( 'mcpwp_api_keys', $keys );

		Mcpwp_Activator::migrate_empty_custom_category_keys();

		$after = get_option( 'mcpwp_api_keys' );
		$g     = null;
		foreach ( $after as $k ) {
			if ( 'g' === $k['id'] ) {
				$g = $k;
			}
		}
		$this->assertSame( array(), $g['tool_categories'], 'the migration must not re-run once its flag is set' );
	}

	/**
	 * #1041 NIT-3: the migration is locked (Mcpwp_Activator::
	 * acquire_migration_lock(), #1036) so two concurrent requests cannot each
	 * read-modify-write mcpwp_api_keys and lose one side's change. Simulate
	 * another process's run already in flight — this call must make zero
	 * progress, not run unprotected.
	 */
	public function test_second_run_is_refused_while_lock_is_held(): void {
		$this->seed(
			'mcpwp_api_keys',
			array( array( 'id' => 'k', 'role' => 'custom', 'tool_categories' => array(), 'hash' => 'h-k', 'label' => 'K' ) )
		);

		$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Activator::DENY_ALL_CATEGORIES_LOCK_OPTION ] = (string) ( time() + Mcpwp_Activator::DENY_ALL_CATEGORIES_LOCK_TTL );

		Mcpwp_Activator::migrate_empty_custom_category_keys();

		$this->assertFalse( get_option( 'mcpwp_deny_all_categories_migrated', false ) );
		$this->assertSame( array(), get_option( 'mcpwp_api_keys' )[0]['tool_categories'], 'must be untouched while the lock is held' );
	}

	public function test_run_proceeds_once_the_other_lock_is_gone(): void {
		$this->seed(
			'mcpwp_api_keys',
			array( array( 'id' => 'l', 'role' => 'custom', 'tool_categories' => array(), 'hash' => 'h-l', 'label' => 'L' ) )
		);

		$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Activator::DENY_ALL_CATEGORIES_LOCK_OPTION ] = (string) ( time() + Mcpwp_Activator::DENY_ALL_CATEGORIES_LOCK_TTL );
		Mcpwp_Activator::migrate_empty_custom_category_keys();
		$this->assertFalse( get_option( 'mcpwp_deny_all_categories_migrated', false ), 'still blocked while the other lock stands' );

		unset( $GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Activator::DENY_ALL_CATEGORIES_LOCK_OPTION ] );

		Mcpwp_Activator::migrate_empty_custom_category_keys();

		$this->assertSame( 1, get_option( 'mcpwp_deny_all_categories_migrated' ) );
		$this->assertSame( array( Mcpwp_Activator::deny_all_category_marker() ), get_option( 'mcpwp_api_keys' )[0]['tool_categories'] );
		$this->assertArrayNotHasKey( Mcpwp_Activator::DENY_ALL_CATEGORIES_LOCK_OPTION, $GLOBALS['mcpwp_test_wpdb_options'], 'this run must release its own lock' );
	}

	public function test_flag_is_not_set_when_nothing_needed_writing(): void {
		// No custom keys with empty categories at all — the loop finds
		// nothing to change for either option, so there is nothing to verify
		// a write for, and the flag is still set (a genuinely clean run).
		$this->seed( 'mcpwp_api_keys', array( array( 'id' => 'm', 'role' => 'admin', 'hash' => 'h-m', 'label' => 'M' ) ) );

		Mcpwp_Activator::migrate_empty_custom_category_keys();

		$this->assertSame( 1, get_option( 'mcpwp_deny_all_categories_migrated' ) );
	}

	/**
	 * #1041 NIT-3: the completion flag must be set only after a VERIFIED
	 * write — a genuine DB write failure must leave the flag unset so the
	 * migration retries on a later request, never silently declare success
	 * for a key that was never actually rewritten.
	 */
	public function test_flag_is_not_set_when_the_write_genuinely_fails(): void {
		$this->seed(
			'mcpwp_api_keys',
			array( array( 'id' => 'n', 'role' => 'custom', 'tool_categories' => array(), 'hash' => 'h-n', 'label' => 'N' ) )
		);
		// The key store is written by compare-and-swap on the row (#1096), so a
		// failed write is a failed row update.
		$GLOBALS['mcpwp_test_wpdb_update_fails'] = static function ( $name ) {
			return 'mcpwp_api_keys' === $name;
		};

		Mcpwp_Activator::migrate_empty_custom_category_keys();

		$this->assertFalse( get_option( 'mcpwp_deny_all_categories_migrated', false ), 'a failed write must not be declared done' );
		$this->assertSame( array(), get_option( 'mcpwp_api_keys' )[0]['tool_categories'], 'the failed write must not have partially applied' );

		// Once the write can succeed again, a later call finishes the job.
		$GLOBALS['mcpwp_test_wpdb_update_fails'] = false;
		Mcpwp_Activator::migrate_empty_custom_category_keys();

		$this->assertSame( 1, get_option( 'mcpwp_deny_all_categories_migrated' ) );
		$this->assertSame( array( Mcpwp_Activator::deny_all_category_marker() ), get_option( 'mcpwp_api_keys' )[0]['tool_categories'] );
	}
}
