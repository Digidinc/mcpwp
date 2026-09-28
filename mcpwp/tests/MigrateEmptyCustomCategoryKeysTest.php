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
		$GLOBALS['mcpwp_test_options'] = array();
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
}
