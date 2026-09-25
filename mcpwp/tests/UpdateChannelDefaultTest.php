<?php
/**
 * Regression test — an unconfigured site defaults to the canonical
 * updates.mcpwp.net channel, not the legacy mumega.com manifest.
 *
 * `get_option( 'mcpwp_version_url', $fallback )` only ever returns $fallback
 * when the option row does not exist at all, so raising these fallbacks is
 * safe for every site that already saved a value (including the legacy
 * manifest — both read the same R2 bucket per CLAUDE.md "Deploy & Update
 * System"). This test pins the two fallback constants (Mcpwp_Updater and the
 * admin Setup page trait) plus the Setup page copy, source-content style
 * (like AdminChatToolExecutionAuthTest's loader-fixture check) so the
 * assertion holds regardless of PHPUnit's process-shared global/constant
 * state from other tests in the suite.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

if ( ! defined( 'MCPWP_PLUGIN_BASENAME' ) ) {
	define( 'MCPWP_PLUGIN_BASENAME', 'mcpwp/mcpwp.php' );
}
if ( ! defined( 'MCPWP_VERSION' ) ) {
	define( 'MCPWP_VERSION', '3.13.0' );
}

require_once dirname( __DIR__ ) . '/includes/class-mcpwp-security.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-updater.php';

final class UpdateChannelDefaultTest extends TestCase {

	public function test_updater_default_version_url_is_canonical(): void {
		$updater = new Mcpwp_Updater();

		$property = ( new ReflectionClass( Mcpwp_Updater::class ) )->getProperty( 'version_url' );
		$property->setAccessible( true );

		$this->assertSame( 'https://updates.mcpwp.net/version.json', $property->getValue( $updater ) );
	}

	public function test_admin_setup_trait_defaults_to_canonical_manifest(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/includes/admin/traits/trait-mcpwp-admin-setup.php' );

		$this->assertStringContainsString(
			"get_option( 'mcpwp_version_url', 'https://updates.mcpwp.net/version.json' )",
			$source,
			'An unconfigured site must default to the canonical update manifest.'
		);
		$this->assertStringNotContainsString(
			"get_option( 'mcpwp_version_url', 'https://mumega.com/mcp-updates/version.json' )",
			$source,
			'The legacy mumega.com manifest must not be the default for a new/unconfigured site.'
		);
	}

	public function test_admin_setup_trait_default_download_url_is_canonical(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/includes/admin/traits/trait-mcpwp-admin-setup.php' );

		$this->assertStringContainsString(
			"\$download_url    = 'https://updates.mcpwp.net/mcpwp-latest.zip';",
			$source
		);
	}

	public function test_setup_page_copy_no_longer_points_at_mumega_only(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/admin/partials/mcpwp-setup-display.php' );

		$this->assertStringNotContainsString(
			'It should point to the mumega.com manifest.',
			$source,
			'The Setup page must not tell a first-run user the legacy host is the canonical channel.'
		);
		$this->assertStringContainsString( 'updates.mcpwp.net', $source );
	}

	/**
	 * A site that already saved its own mcpwp_version_url (legacy or otherwise)
	 * must keep it: get_option()'s default argument is never consulted once
	 * the option row exists, which this pins as the mechanism, not an assumption.
	 */
	public function test_existing_saved_option_is_not_overridden_by_the_new_default(): void {
		$GLOBALS['mcpwp_test_options'] = array(
			'mcpwp_version_url' => 'https://mumega.com/mcp-updates/version.json',
		);

		$this->assertSame(
			'https://mumega.com/mcp-updates/version.json',
			get_option( 'mcpwp_version_url', 'https://updates.mcpwp.net/version.json' )
		);
	}
}
