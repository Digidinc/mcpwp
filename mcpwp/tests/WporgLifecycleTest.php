<?php
/**
 * Task 5 lifecycle, evidence, and WPCS release contract.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class WporgLifecycleTest extends TestCase {

	public function test_uninstall_removes_plugin_role_and_administrator_capability(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );
		$this->assertStringContainsString( "remove_role( 'mcpwp_api_agent' )", $source );
		$this->assertStringContainsString( "remove_cap( 'mcpwp_manage_settings' )", $source );
	}

	public function test_multisite_lifecycle_queries_are_explicitly_unbounded(): void {
		foreach ( array( 'mcpwp.php', 'uninstall.php', 'includes/class-mcpwp-deactivator.php' ) as $relative ) {
			$source = (string) file_get_contents( dirname( __DIR__ ) . '/' . $relative );
			$this->assertMatchesRegularExpression(
				"/get_sites\\s*\\(\\s*array\\s*\\([^)]*'fields'\\s*=>\\s*'ids'[^)]*'number'\\s*=>\\s*0/s",
				$source,
				'Multisite cleanup may not stop at the default site limit: ' . $relative
			);
		}
	}

	public function test_release_support_files_and_locked_wpcs_contract_exist(): void {
		$repository = dirname( __DIR__, 2 );
		foreach ( array(
			'docs/wporg/approval-evidence.md',
			'docs/wporg/mumega-mcp-manifest.txt',
			'mcpwp/phpcs.xml.dist',
		) as $relative ) {
			$this->assertFileExists( $repository . '/' . $relative, 'Missing Task 5 release file: ' . $relative );
		}

		$composer = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/composer.json' ), true );
		$this->assertArrayHasKey( 'wp-coding-standards/wpcs', $composer['require-dev'] ?? array() );
		$this->assertArrayHasKey( 'dealerdirect/phpcodesniffer-composer-installer', $composer['require-dev'] ?? array() );
		$this->assertTrue( $composer['config']['allow-plugins']['dealerdirect/phpcodesniffer-composer-installer'] ?? false );
	}

	public function test_task5_artifact_tests_require_an_explicit_frozen_zip_in_ci(): void {
		$workflow = dirname( __DIR__, 2 ) . '/.github/workflows/wporg-approval.yml';
		$this->assertFileExists( $workflow );
		$source = (string) file_get_contents( $workflow );
		$this->assertMatchesRegularExpression( '/MCPWP_WPORG_ZIP:\s*\S+mumega-mcp-3\.4\.3\.zip/', $source );
	}

	public function test_approval_workflow_ignores_inherited_composer_authentication(): void {
		$workflow = dirname( __DIR__, 2 ) . '/.github/workflows/wporg-approval.yml';
		$source   = (string) file_get_contents( $workflow );
		$this->assertSame( 2, substr_count( $source, "COMPOSER_AUTH: '{}'" ), 'Both Composer install lanes must not inherit a runner OAuth token.' );
	}
}
