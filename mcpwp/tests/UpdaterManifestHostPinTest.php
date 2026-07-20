<?php
/**
 * Regression test — the auto-updater manifest download_url is host-pinned.
 *
 * The caller-supplied package_url path is already restricted to an allowlist via
 * is_trusted_package_url(); the manifest-driven path (auto-update / no-package
 * trigger) must have parity so a hijacked or MITM'd manifest cannot point the
 * updater at an arbitrary zip. HTTPS alone is insufficient.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

if ( ! defined( 'MCPWP_PLUGIN_BASENAME' ) ) {
	define( 'MCPWP_PLUGIN_BASENAME', 'mcpwp/mcpwp.php' );
}
if ( ! defined( 'MCPWP_VERSION' ) ) {
	define( 'MCPWP_VERSION', '3.6.1' );
}

require_once dirname( __DIR__ ) . '/includes/class-mcpwp-security.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-updater.php';

final class UpdaterManifestHostPinTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_filters'] = array();
	}

	private function normalize( array $manifest ) {
		$updater = new Mcpwp_Updater();
		$method  = new ReflectionMethod( Mcpwp_Updater::class, 'normalize_manifest_data' );
		$method->setAccessible( true );
		return $method->invoke( $updater, $manifest, 'test' );
	}

	public function test_allowlisted_https_host_is_accepted(): void {
		foreach ( array( 'https://updates.mcpwp.net/mcpwp-latest.zip', 'https://mumega.com/mcp-updates/mcpwp.zip' ) as $url ) {
			$result = $this->normalize( array( 'version' => '9.9.9', 'download_url' => $url ) );
			$this->assertIsObject( $result, "Allowlisted host should be accepted: $url" );
			$this->assertSame( '9.9.9', $result->version );
		}
	}

	public function test_untrusted_host_is_rejected(): void {
		$result = $this->normalize( array( 'version' => '9.9.9', 'download_url' => 'https://evil.example/mcpwp.zip' ) );
		$this->assertFalse( $result, 'A non-allowlisted host must be rejected even over HTTPS.' );
	}

	public function test_plain_http_allowlisted_host_is_rejected(): void {
		$result = $this->normalize( array( 'version' => '9.9.9', 'download_url' => 'http://updates.mcpwp.net/mcpwp.zip' ) );
		$this->assertFalse( $result, 'Plain HTTP must be rejected.' );
	}

	public function test_self_hosted_channel_can_extend_allowlist_via_filter(): void {
		add_filter(
			'mcpwp_trusted_package_hosts',
			static function ( $hosts ) {
				$hosts[] = 'updates.myagency.example';
				return $hosts;
			}
		);

		$result = $this->normalize( array( 'version' => '9.9.9', 'download_url' => 'https://updates.myagency.example/mcpwp.zip' ) );
		$this->assertIsObject( $result, 'A filter-added host should be accepted.' );
	}
}
