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
