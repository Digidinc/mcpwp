<?php
use PHPUnit\Framework\TestCase;

final class BridgeMigrateTest extends TestCase {
	protected function setUp(): void { $GLOBALS['mcpwp_test_options'] = array(); }
	public function test_migration_copies_only_retained_compatibility_state(): void {
		update_option( 'spai_api_key', 'legacy-hash' );
		update_option( 'spai_api_keys', array( array( 'id' => 'one' ) ) );
		update_option( 'spai_rate_limit_settings', array( 'enabled' => true ) );
		update_option( 'spai_settings', array( 'enable_logging' => true, 'log_retention_days' => 14, 'removed_value' => 'ignored' ) );
		Mcpwp_Migrate::run();
		$this->assertSame( 'legacy-hash', get_option( 'mcpwp_api_key' ) );
		$this->assertSame( array( array( 'id' => 'one' ) ), get_option( 'mcpwp_api_keys' ) );
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
}
