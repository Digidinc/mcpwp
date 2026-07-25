<?php
/**
 * The update channel must announce its own failure.
 *
 * #716 was invisible because a channel that cannot deliver looks exactly like
 * a site that is already up to date: both report "no update available" and
 * nothing else. These tests pin the distinction — a broken channel is
 * critical, a current site is good, and the two never collapse into one
 * another.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

if ( ! defined( 'MCPWP_PLUGIN_BASENAME' ) ) {
	define( 'MCPWP_PLUGIN_BASENAME', 'mcpwp/mcpwp.php' );
}

require_once dirname( __DIR__, 2 ) . '/includes/class-mcpwp-security.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-mcpwp-updater.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-mcpwp-update-channel-health.php';

final class UpdateChannelHealthTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']    = array();
		$GLOBALS['mcpwp_test_transients'] = array();
		$GLOBALS['mcpwp_test_filters']    = array();
		$GLOBALS['mcpwp_test_http']       = array();

		$this->reset_updater_singleton();
	}

	protected function tearDown(): void {
		$this->reset_updater_singleton();
	}

	private function reset_updater_singleton(): void {
		$instance = new ReflectionProperty( Mcpwp_Updater::class, 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );
	}

	/**
	 * Install an updater whose channel answer is fixed for the test.
	 *
	 * @param string     $installed Installed plugin version.
	 * @param array|null $manifest  Manifest the channel serves, or null to simulate an unreachable channel.
	 * @return void
	 */
	private function given_channel( string $installed, ?array $manifest ): void {
		$updater    = new Mcpwp_Updater();
		$reflection = new ReflectionClass( Mcpwp_Updater::class );

		$current = $reflection->getProperty( 'current_version' );
		$current->setAccessible( true );
		$current->setValue( $updater, $installed );

		$remote = $reflection->getProperty( 'remote_data' );
		$remote->setAccessible( true );

		if ( null === $manifest ) {
			$remote->setValue( $updater, null );
		} else {
			$data                  = (object) $manifest;
			$data->_source         = 'remote';
			$data->_remote_version = $manifest['version'];
			$data->_option_version = null;
			$remote->setValue( $updater, $data );
		}

		$instance = $reflection->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, $updater );
	}

	private function manifest( string $version ): array {
		return array(
			'version'      => $version,
			'download_url' => 'https://updates.mcpwp.net/mcpwp-latest.zip',
		);
	}

	public function test_unreachable_channel_is_not_healthy(): void {
		$this->given_channel( '3.7.0', null );

		$status = Mcpwp_Update_Channel_Health::get_status();

		$this->assertFalse( $status['ok'], 'A channel that cannot answer is not healthy.' );
		$this->assertNotSame(
			'already_current',
			$status['reason'],
			'An unreachable channel must never be reported as up to date.'
		);
	}

	public function test_unreachable_channel_is_critical_in_site_health(): void {
		$this->given_channel( '3.7.0', null );

		$result = Mcpwp_Update_Channel_Health::run_site_status_test();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( 'cannot receive updates', $result['label'] );
		$this->assertStringContainsString( 'security releases', $result['description'] );
	}

	public function test_current_site_is_healthy_and_good(): void {
		$this->given_channel( '3.7.2', $this->manifest( '3.7.2' ) );

		$status = Mcpwp_Update_Channel_Health::get_status();
		$this->assertTrue( $status['ok'] );
		$this->assertSame( 'already_current', $status['reason'] );
		$this->assertNull( $status['available_version'] );

		Mcpwp_Update_Channel_Health::flush();
		$result = Mcpwp_Update_Channel_Health::run_site_status_test();
		$this->assertSame( 'good', $result['status'] );
	}

	public function test_available_update_is_healthy_and_names_the_version(): void {
		$this->given_channel( '3.7.0', $this->manifest( '3.7.2' ) );

		$status = Mcpwp_Update_Channel_Health::get_status();

		$this->assertTrue( $status['ok'], 'An update waiting to install is a working channel.' );
		$this->assertSame( '3.7.2', $status['available_version'] );
		$this->assertStringContainsString( '3.7.2', $status['message'] );
	}

	/**
	 * Every reason the updater emits for a channel-level failure must map to
	 * unhealthy. A new reason code added without updating BROKEN_REASONS would
	 * silently be treated as "fine".
	 *
	 * @dataProvider brokenReasonProvider
	 */
	public function test_channel_level_reasons_are_unhealthy( string $reason ): void {
		$this->assertContains(
			$reason,
			Mcpwp_Update_Channel_Health::BROKEN_REASONS,
			sprintf( 'Reason %s means the channel is broken and must be reported as such.', $reason )
		);
	}

	public function brokenReasonProvider(): array {
		return array(
			'fetch failed'       => array( 'manifest_fetch_failed' ),
			'invalid manifest'   => array( 'manifest_invalid' ),
			'untrusted host'     => array( 'package_host_not_trusted' ),
			'insecure url'       => array( 'version_url_not_https' ),
			'nothing resolved'   => array( 'manifest_unavailable' ),
		);
	}

	public function test_every_broken_reason_has_its_own_explanation(): void {
		$generic = Mcpwp_Updater::describe_reason( null );

		foreach ( Mcpwp_Update_Channel_Health::BROKEN_REASONS as $reason ) {
			$this->assertNotSame(
				$generic,
				Mcpwp_Updater::describe_reason( $reason ),
				sprintf( 'Reason %s falls through to the generic message — the failure would be undiagnosable.', $reason )
			);
		}
	}

	/**
	 * A broken channel must not mean an outbound HTTPS request on every admin
	 * page load: the updater does not cache failed fetches, so this layer has
	 * to.
	 */
	public function test_failures_are_cached_more_briefly_than_successes(): void {
		$this->given_channel( '3.7.0', null );
		Mcpwp_Update_Channel_Health::get_status();
		$failure_expiry = $this->cached_expiry();

		$this->reset_updater_singleton();
		$GLOBALS['mcpwp_test_transients'] = array();
		$this->given_channel( '3.7.2', $this->manifest( '3.7.2' ) );
		Mcpwp_Update_Channel_Health::get_status();
		$success_expiry = $this->cached_expiry();

		// Both were written within the same second, so comparing absolute
		// expiries compares the TTLs they were given.
		$this->assertGreaterThan(
			time(),
			$failure_expiry,
			'A failure must still be cached — otherwise every admin page load re-hits the network.'
		);
		$this->assertGreaterThan(
			$failure_expiry,
			$success_expiry,
			'A failure should be re-checked sooner than a healthy result.'
		);
	}

	/**
	 * Absolute expiry timestamp the status was cached with.
	 *
	 * @return int Unix timestamp.
	 */
	private function cached_expiry(): int {
		$entry = $GLOBALS['mcpwp_test_transients'][ Mcpwp_Update_Channel_Health::CACHE_KEY ] ?? null;

		$this->assertIsArray( $entry, 'Status was not cached at all.' );
		$this->assertArrayHasKey( 'expires', $entry, 'Transient stub exposes no expiry to assert on.' );

		return (int) $entry['expires'];
	}

	public function test_cached_status_is_reused_without_re_resolving(): void {
		$this->given_channel( '3.7.0', $this->manifest( '3.7.2' ) );
		$first = Mcpwp_Update_Channel_Health::get_status();

		// Swap in a channel that would answer differently. A cached read must
		// not notice.
		$this->reset_updater_singleton();
		$this->given_channel( '3.7.0', null );

		$second = Mcpwp_Update_Channel_Health::get_status();

		$this->assertSame( $first, $second );
		$this->assertTrue( $second['ok'] );
	}

	public function test_flush_forces_a_fresh_answer(): void {
		$this->given_channel( '3.7.0', $this->manifest( '3.7.2' ) );
		Mcpwp_Update_Channel_Health::get_status();

		Mcpwp_Update_Channel_Health::flush();
		$this->reset_updater_singleton();
		$this->given_channel( '3.7.0', null );

		$this->assertFalse(
			Mcpwp_Update_Channel_Health::get_status()['ok'],
			'After a flush the next read must re-resolve the channel.'
		);
	}
}
