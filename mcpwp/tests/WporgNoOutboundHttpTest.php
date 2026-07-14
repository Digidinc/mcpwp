<?php
/**
 * Task 5 no-outbound and immutable-candidate gate contract.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class WporgNoOutboundHttpTest extends TestCase {

	public function test_clean_zip_harness_covers_every_required_zero_outbound_context(): void {
		$script = dirname( __DIR__ ) . '/scripts/test-wporg-clean-install.sh';
		$this->assertFileExists( $script, 'Task 5 requires an exact-ZIP clean-install harness.' );
		$source = (string) file_get_contents( $script );

		$this->assertStringContainsString( 'MCPWP_WPORG_ZIP', $source );
		$this->assertStringContainsString( 'pre_http_request', $source );
		$this->assertStringNotContainsString( '/wp-content/plugins/mumega-mcp:ro', $source, 'The Task 5 harness must not source-mount the plugin.' );
		foreach ( $this->required_contexts() as $context ) {
			$this->assertStringContainsString( $context, $source, 'Missing no-outbound context: ' . $context );
		}
	}

	public function test_release_workflow_fans_out_one_immutable_candidate(): void {
		$workflow = dirname( __DIR__, 2 ) . '/.github/workflows/wporg-approval.yml';
		$this->assertFileExists( $workflow );
		$source = (string) file_get_contents( $workflow );
		$this->assertStringContainsString( 'branches: [wporg]', $source );
		$this->assertStringContainsString( 'MCPWP_WPORG_ZIP', $source );
		$this->assertSame( 1, substr_count( $source, 'build-wporg.sh' ), 'CI must build the candidate once.' );
		$this->assertMatchesRegularExpression( '/uses:\s+[A-Za-z0-9_.\/-]+@[0-9a-f]{40}\b/', $source, 'Actions must be pinned to full commit SHAs.' );
	}

	public function test_release_workflow_locks_reviewed_wpcs_and_plugin_check_findings(): void {
		$workflow = dirname( __DIR__, 2 ) . '/.github/workflows/wporg-approval.yml';
		$source   = (string) file_get_contents( $workflow );

		$this->assertStringContainsString( '--report=json', $source );
		$this->assertStringContainsString( 'expected_wpcs_errors = 0', $source );
		$this->assertStringContainsString( 'expected_wpcs_warnings = 40', $source );
		foreach (
			array(
				'WordPress.DB.DirectDatabaseQuery.DirectQuery'                         => 10,
				'WordPress.DB.DirectDatabaseQuery.NoCaching'                           => 10,
				'WordPress.DB.DirectDatabaseQuery.SchemaChange'                        => 4,
				'Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed'       => 10,
				'Generic.CodeAnalysis.UnusedFunctionParameter.Found'                    => 1,
				'Generic.Formatting.MultipleStatementAlignment.NotSameWarning'          => 2,
				'WordPress.Security.NonceVerification.Recommended'                      => 2,
				'WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode'       => 1,
			) as $source_name => $count
		) {
			$this->assertStringContainsString( "'{$source_name}': {$count}", $source );
		}

		$this->assertStringContainsString( 'expected_plugin_check_errors = 0', $source );
		$this->assertStringContainsString( 'expected_plugin_check_warnings = 36', $source );
		foreach (
			array(
				'WordPress.Security.NonceVerification.Recommended'                  => 2,
				'PluginCheck.Security.DirectDB.UnescapedDBParameter'                => 3,
				'WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound' => 7,
				'WordPress.DB.DirectDatabaseQuery.DirectQuery'                     => 10,
				'WordPress.DB.DirectDatabaseQuery.NoCaching'                       => 10,
				'WordPress.DB.DirectDatabaseQuery.SchemaChange'                    => 4,
			) as $code => $count
		) {
			$this->assertStringContainsString( "'{$code}': {$count}", $source );
		}

		$this->assertStringContainsString( "raw == 'Success: Checks complete. No errors found.'", $source );
		$this->assertStringNotContainsString( "'Success: Checks complete. No errors found.' in raw", $source );
	}

	public function test_release_workflow_has_an_always_run_aggregate_gate(): void {
		$workflow = dirname( __DIR__, 2 ) . '/.github/workflows/wporg-approval.yml';
		$source   = (string) file_get_contents( $workflow );

		$this->assertStringContainsString( 'approval-gate:', $source );
		$this->assertStringContainsString( 'if: ${{ always() }}', $source );
		foreach (
			array(
				'candidate: ${{ needs.candidate.result }}',
				"artifact_policy: \${{ needs['artifact-policy'].result }}",
				"php_quality: \${{ needs['php-quality'].result }}",
				'wpcs: ${{ needs.wpcs.result }}',
				"plugin_check: \${{ needs['plugin-check'].result }}",
				"clean_install: \${{ needs['clean-install'].result }}",
			) as $result_contract
		) {
			$this->assertStringContainsString( $result_contract, $source );
		}
	}

	/** @return string[] */
	private function required_contexts(): array {
		return array(
			'activation', 'admin_setup', 'admin_tools', 'admin_settings', 'admin_activity',
			'key_create', 'key_list', 'key_revoke', 'site_info',
			'wp_site_info', 'wp_list_posts', 'wp_get_post', 'wp_create_post', 'wp_update_post',
			'wp_list_pages', 'wp_get_page', 'wp_create_page', 'wp_update_page', 'wp_list_drafts',
			'wp_delete_draft', 'wp_list_media', 'wp_get_media', 'wp_upload_media_b64',
			'wp_list_categories', 'wp_list_tags', 'wp_create_term',
			'denied_scope', 'denied_capability', 'deactivation', 'reactivation', 'uninstall',
		);
	}
}
