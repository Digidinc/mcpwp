<?php
use PHPUnit\Framework\TestCase;

final class BridgeMigrateTest extends TestCase {
	protected function setUp(): void { $GLOBALS['mcpwp_test_options'] = array(); $GLOBALS['mcpwp_test_current_user'] = 2; $GLOBALS['mcpwp_test_user_caps'][2] = array( 'manage_options' => true, 'mcpwp_manage_settings' => true ); update_option( 'mcpwp_legacy_migration_owner_id', 2 ); }
	public function test_migration_copies_only_retained_compatibility_state(): void {
		update_option( 'spai_api_key', 'legacy-hash' );
		update_option( 'spai_api_keys', array( array( 'id' => 'one', 'label' => 'Legacy', 'hash' => 'legacy-hash', 'scopes' => array( 'read' ), 'role' => 'administrator', 'rate_limits' => array( 'burst' => 1 ) ) ) );
		update_option( 'spai_rate_limit_settings', array( 'enabled' => true ) );
		update_option( 'spai_settings', array( 'enable_logging' => true, 'log_retention_days' => 14, 'removed_value' => 'ignored' ) );
		Mcpwp_Migrate::run();
		$this->assertSame( 'legacy-hash', get_option( 'mcpwp_api_key' ) );
		$keys = get_option( 'mcpwp_api_keys' );
		$this->assertSame( 2, $keys[0]['user_id'] );
		$this->assertArrayNotHasKey( 'role', $keys[0] );
		$this->assertArrayNotHasKey( 'rate_limits', $keys[0] );
		$this->assertSame( array( 'enabled' => true ), get_option( 'mcpwp_rate_limit_settings' ) );
		$this->assertSame( array( 'enable_logging' => true, 'log_retention_days' => 14 ), get_option( 'mcpwp_settings' ) );
	}
	public function test_migration_does_not_overwrite_retained_destination(): void {
		update_option( 'spai_api_key', 'old' ); update_option( 'mcpwp_api_key', 'current' ); Mcpwp_Migrate::run();
		$this->assertSame( 'current', get_option( 'mcpwp_api_key' ) );
	}
	public function test_migration_is_idempotent(): void {
		update_option( 'spai_api_key', 'first' ); Mcpwp_Migrate::run(); update_option( 'spai_api_key', 'second' ); Mcpwp_Migrate::run();
		$this->assertSame( 'first', get_option( 'mcpwp_api_key' ) );
	}
	public function test_upgrade_bootstrap_does_not_seed_or_mark_credentials_before_authenticated_activation_owner(): void {
		$GLOBALS['mcpwp_test_current_user'] = 0;
		delete_option( 'mcpwp_legacy_migration_owner_id' );
		update_option( 'spai_api_keys', array( array( 'id' => 'legacy', 'hash' => 'legacy-hash', 'scopes' => array( 'read' ) ) ) );

		Mcpwp_Migrate::run();
		Mcpwp_Activator::activate();

		$this->assertFalse( get_option( 'mcpwp_api_keys', false ) );
		$this->assertFalse( get_option( 'mcpwp_migrated_from_spai', false ) );

		$GLOBALS['mcpwp_test_current_user'] = 2;
		Mcpwp_Migrate::setup_owner( 2 );

		$this->assertSame( 2, get_option( 'mcpwp_api_keys' )[0]['user_id'] );
		$this->assertTrue( get_option( 'mcpwp_migrated_from_spai' ) );
	}
	public function test_explicit_setup_repairs_only_integer_zero_owner_records(): void {
		update_option( 'mcpwp_api_keys', array(
			array( 'id' => 'unbound', 'hash' => 'hash-one', 'user_id' => 0, 'scopes' => array( 'read' ), 'tool_categories' => array() ),
			array( 'id' => 'owned', 'hash' => 'hash-two', 'user_id' => 7, 'scopes' => array( 'read' ), 'tool_categories' => array() ),
			array( 'id' => 'malformed', 'hash' => 'hash-three', 'user_id' => '0', 'scopes' => array( 'read' ), 'tool_categories' => array() ),
		) );

		Mcpwp_Migrate::setup_owner( 2 );
		$keys = get_option( 'mcpwp_api_keys' );

		$this->assertSame( 2, $keys[0]['user_id'] );
		$this->assertSame( 7, $keys[1]['user_id'] );
		$this->assertSame( '0', $keys[2]['user_id'] );
	}
	public function test_normal_admin_migration_repairs_preexisting_integer_zero_owner_records(): void {
		update_option( 'mcpwp_api_keys', array( array( 'id' => 'legacy-zero', 'hash' => 'hash', 'user_id' => 0, 'scopes' => array( 'read' ), 'tool_categories' => array() ) ) );
		Mcpwp_Migrate::run();
		$this->assertSame( 2, get_option( 'mcpwp_api_keys' )[0]['user_id'] );
	}
}
