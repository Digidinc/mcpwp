<?php
/**
 * Regression tests — the self-hosted update channel must not depend on
 * api.wordpress.org, and must say why when it declines to update.
 *
 * Reproduced before the fix (#716) against a live install: when core's request
 * to api.wordpress.org fails, wp_update_plugins() still fires
 * pre_set_site_transient_update_plugins but hands the filter a transient with no
 * `checked` property at all. check_for_update() bailed on `empty( $checked )`,
 * discarding a manifest it had already fetched successfully, and the site
 * reported update_available=false while sitting on 3.7.0 with 3.7.2 (a security
 * release) published on the channel.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

if ( ! defined( 'MCPWP_PLUGIN_BASENAME' ) ) {
	define( 'MCPWP_PLUGIN_BASENAME', 'mcpwp/mcpwp.php' );
}
if ( ! defined( 'MCPWP_VERSION' ) ) {
	define( 'MCPWP_VERSION', '3.7.0' );
}

require_once dirname( __DIR__, 2 ) . '/includes/class-mcpwp-security.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-mcpwp-updater.php';

final class UpdaterIndependentOfWpOrgTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']    = array();
		$GLOBALS['mcpwp_test_transients'] = array();
		$GLOBALS['mcpwp_test_filters']    = array();
		$GLOBALS['mcpwp_test_http']       = array();
	}

	/**
	 * Updater pinned to a given installed version, with the remote manifest stubbed.
	 *
	 * @param string     $installed Installed plugin version.
	 * @param array|null $manifest  Manifest the channel serves, or null for a fetch failure.
	 * @return Mcpwp_Updater Configured updater.
	 */
	private function updater( string $installed, ?array $manifest ) {
		$updater = new Mcpwp_Updater();

		$reflection = new ReflectionClass( Mcpwp_Updater::class );

		$current = $reflection->getProperty( 'current_version' );
		$current->setAccessible( true );
		$current->setValue( $updater, $installed );

		// Stub the network by seeding the manifest cache the fetch would fill.
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

		return $updater;
	}

	private function manifest( string $version ): array {
		return array(
			'version'      => $version,
			'download_url' => 'https://updates.mcpwp.net/mcpwp-latest.zip',
		);
	}

	/**
	 * The exact #716 shape: core failed, so there is no `checked` map at all.
	 */
	public function test_update_is_offered_when_core_never_populated_checked(): void {
		$updater = $this->updater( '3.7.0', $this->manifest( '3.7.2' ) );

		// No `checked` property — what core passes when api.wordpress.org fails.
		$transient           = new stdClass();
		$transient->response = array();

		$result = $updater->check_for_update( $transient );

		$this->assertArrayHasKey(
			MCPWP_PLUGIN_BASENAME,
			$result->response,
			'A self-hosted update must be offered even when core could not reach wordpress.org.'
		);
		$this->assertSame( '3.7.2', $result->response[ MCPWP_PLUGIN_BASENAME ]->new_version );
	}

	public function test_update_is_offered_when_checked_is_populated(): void {
		$updater = $this->updater( '3.7.0', $this->manifest( '3.7.2' ) );

		$transient            = new stdClass();
		$transient->checked   = array( MCPWP_PLUGIN_BASENAME => '3.7.0' );
		$transient->response  = array();
		$transient->no_update = array();

		$result = $updater->check_for_update( $transient );

		$this->assertSame( '3.7.2', $result->response[ MCPWP_PLUGIN_BASENAME ]->new_version );
	}

	/**
	 * Semantic comparison, not string comparison: 3.7.10 > 3.7.2, 3.7.2 !> 3.7.2.
	 *
	 * @dataProvider versionProvider
	 */
	public function test_version_comparison_is_semantic( string $installed, string $remote, bool $expected ): void {
		$state = $this->updater( $installed, $this->manifest( $remote ) )->get_update_state();

		$this->assertSame(
			$expected,
			$state['update_available'],
			sprintf( 'installed %s vs remote %s', $installed, $remote )
		);
	}

	public function versionProvider(): array {
		return array(
			'patch behind'          => array( '3.7.0', '3.7.2', true ),
			'double-digit patch'    => array( '3.7.2', '3.7.10', true ),
			'minor behind'          => array( '3.7.9', '3.8.0', true ),
			'identical'             => array( '3.7.2', '3.7.2', false ),
			'ahead of channel'      => array( '3.8.0', '3.7.2', false ),
			'prerelease is behind'  => array( '3.7.2-rc1', '3.7.2', true ),
		);
	}

	/**
	 * The reporting site had no mcpwp_update_info at all; a null option version
	 * must not suppress the remote answer.
	 */
	public function test_absent_option_manifest_does_not_block_the_remote_update(): void {
		$state = $this->updater( '3.7.0', $this->manifest( '3.7.2' ) )->get_update_state();

		$this->assertTrue( $state['update_available'] );
		$this->assertNull( $state['option_version'], 'No override was stored.' );
		$this->assertSame( '3.7.2', $state['remote_version'] );
		$this->assertSame( 'remote', $state['source'] );
		$this->assertSame( 'https://updates.mcpwp.net/mcpwp-latest.zip', $state['package'] );
	}

	public function test_up_to_date_state_reports_a_reason(): void {
		$state = $this->updater( '3.7.2', $this->manifest( '3.7.2' ) )->get_update_state();

		$this->assertFalse( $state['update_available'] );
		$this->assertSame( 'already_current', $state['reason'] );
	}

	/**
	 * A forced package_url install re-saves the transient, which re-enters the
	 * filter. Before #716 the `checked` guard stopped the overwrite by accident;
	 * now the flag has to do it deliberately.
	 */
	public function test_forced_package_entry_survives_the_filter(): void {
		$updater = $this->updater( '3.7.0', $this->manifest( '3.7.2' ) );

		$transient           = new stdClass();
		$transient->response = array(
			MCPWP_PLUGIN_BASENAME => $updater->build_forced_entry( 'https://mumega.com/mcp-updates/pinned.zip', '999.0.0' ),
		);

		$result = $updater->check_for_update( $transient );

		$this->assertSame(
			'999.0.0',
			$result->response[ MCPWP_PLUGIN_BASENAME ]->new_version,
			'A caller-forced install must not be reverted to the manifest version.'
		);
		$this->assertSame(
			'https://mumega.com/mcp-updates/pinned.zip',
			$result->response[ MCPWP_PLUGIN_BASENAME ]->package
		);
	}

	/**
	 * An ordinary (non-forced) entry left by core is still ours to update.
	 */
	public function test_unflagged_entry_is_replaced_by_the_manifest_version(): void {
		$updater = $this->updater( '3.7.0', $this->manifest( '3.7.2' ) );

		$stale              = new stdClass();
		$stale->slug        = 'mcpwp';
		$stale->plugin      = MCPWP_PLUGIN_BASENAME;
		$stale->new_version = '3.7.1';
		$stale->package     = 'https://updates.mcpwp.net/mcpwp-3.7.1.zip';

		$transient           = new stdClass();
		$transient->response = array( MCPWP_PLUGIN_BASENAME => $stale );

		$result = $updater->check_for_update( $transient );

		$this->assertSame( '3.7.2', $result->response[ MCPWP_PLUGIN_BASENAME ]->new_version );
	}

	public function test_current_install_is_recorded_as_no_update_not_dropped(): void {
		$updater = $this->updater( '3.7.2', $this->manifest( '3.7.2' ) );

		$transient = new stdClass();

		$result = $updater->check_for_update( $transient );

		$this->assertArrayHasKey(
			MCPWP_PLUGIN_BASENAME,
			$result->no_update,
			'An up-to-date plugin belongs in no_update so the Plugins screen says "up to date".'
		);
		$this->assertArrayNotHasKey( MCPWP_PLUGIN_BASENAME, $result->response );
	}

	public function test_non_object_transient_is_returned_untouched(): void {
		$updater = $this->updater( '3.7.0', $this->manifest( '3.7.2' ) );

		$this->assertFalse( $updater->check_for_update( false ) );
	}
}
