<?php
/**
 * Regression tests — generic-host environment detection (#710).
 *
 * The generic adapter used to hard-code `'type' => 'production'` for the one
 * environment it reports, so a path-based staging clone
 * (https://example.com/staging/5707) was announced to agents as production.
 *
 * The gate this must not break: Mcpwp_Staging::is_production_env() is what
 * decides whether a push requires a human approval
 * (class-mcpwp-rest-staging.php:176). Detecting staging more eagerly must
 * never *remove* that gate on a guess — only an explicit, operator-set signal
 * may lower it.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/pro/core/class-mcpwp-staging.php';
require_once dirname( __DIR__, 2 ) . '/includes/admin/class-mcpwp-settings.php';

final class StagingEnvironmentDetectionTest extends TestCase {

	private Mcpwp_Staging_Adapter_Generic $adapter;
	private Mcpwp_Staging $staging;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']  = array();
		$GLOBALS['mcpwp_test_site_url'] = 'https://example.com';
		putenv( 'WP_ENVIRONMENT_TYPE' );

		$this->adapter = new Mcpwp_Staging_Adapter_Generic();
		$this->staging = new Mcpwp_Staging();
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_site_url'] = 'https://example.com';
		putenv( 'WP_ENVIRONMENT_TYPE' );
	}

	/** @return array<string,mixed> */
	private function currentEnvironment(): array {
		$environments = $this->adapter->list_environments();

		return $environments[0];
	}

	// ── Detection ────────────────────────────────────────────────────────

	public function test_path_based_staging_clone_is_not_reported_as_production(): void {
		// The exact URL from the bug report.
		$GLOBALS['mcpwp_test_site_url'] = 'https://digitalmarketingexperts.ca/staging/5707';

		$env = $this->currentEnvironment();

		$this->assertSame( 'staging', $env['type'] );
		$this->assertSame( 'url_heuristic', $env['type_source'] );
		$this->assertNotEmpty( $env['warning'], 'A guessed environment type must say so.' );
	}

	public function test_subdomain_staging_clone_is_detected(): void {
		$GLOBALS['mcpwp_test_site_url'] = 'https://staging.example.com';

		$this->assertSame( 'staging', $this->currentEnvironment()['type'] );
	}

	public function test_ordinary_production_url_stays_production(): void {
		$GLOBALS['mcpwp_test_site_url'] = 'https://example.com';

		$env = $this->currentEnvironment();

		$this->assertSame( 'production', $env['type'] );
		$this->assertArrayNotHasKey( 'warning', $env );
	}

	public function test_marker_must_match_a_whole_url_segment(): void {
		// "developers" contains "dev" but is not a dev environment; likewise a
		// company called "Teststrom". Substring matching would flag both. The
		// third is a real product URL — splitting path segments on dashes as well
		// as slashes would read "dev" out of it and mislabel a live shop.
		foreach (
			array(
				'https://developers.example.com',
				'https://teststrom.example.com/about',
				'https://shop.example.com/dev-tools-category/hammers',
			) as $url
		) {
			$GLOBALS['mcpwp_test_site_url'] = $url;

			$this->assertSame( 'production', $this->currentEnvironment()['type'], $url . ' must not be flagged.' );
		}
	}

	public function test_wp_environment_type_is_authoritative(): void {
		putenv( 'WP_ENVIRONMENT_TYPE=staging' );

		$env = $this->currentEnvironment();

		$this->assertSame( 'staging', $env['type'] );
		$this->assertSame( 'wp_environment_type', $env['type_source'] );
		$this->assertTrue( $env['type_authoritative'] );
	}

	public function test_operator_override_beats_the_url_heuristic(): void {
		// A live site that genuinely lives under /staging/ — the operator says so.
		$GLOBALS['mcpwp_test_site_url']            = 'https://example.com/staging/5707';
		$GLOBALS['mcpwp_test_options']['mcpwp_settings'] = array( 'environment_type' => 'production' );

		$env = $this->currentEnvironment();

		$this->assertSame( 'production', $env['type'] );
		$this->assertSame( 'setting_override', $env['type_source'] );
	}

	public function test_granular_wp_environment_value_is_preserved(): void {
		putenv( 'WP_ENVIRONMENT_TYPE=development' );

		$env = $this->currentEnvironment();

		$this->assertSame( 'staging', $env['type'], 'type stays in the production|staging vocabulary.' );
		$this->assertSame( 'development', $env['environment_type'], 'the precise value is still reported.' );
	}

	// ── The approval gate must not be weakened ───────────────────────────

	public function test_guessed_staging_still_requires_push_approval(): void {
		$GLOBALS['mcpwp_test_site_url'] = 'https://example.com/staging/5707';

		$environments = $this->adapter->list_environments();

		$this->assertSame( 'staging', $environments[0]['type'] );
		$this->assertTrue(
			$this->staging->is_production_env( $environments, 'current' ),
			'A URL guess must never silently drop the human approval gate on a push.'
		);
	}

	public function test_explicitly_declared_staging_does_not_require_push_approval(): void {
		putenv( 'WP_ENVIRONMENT_TYPE=staging' );

		$environments = $this->adapter->list_environments();

		$this->assertFalse(
			$this->staging->is_production_env( $environments, 'current' ),
			'An operator-declared staging environment may be pushed to without approval.'
		);
	}

	public function test_unknown_environment_id_is_treated_as_production(): void {
		$environments = $this->adapter->list_environments();

		$this->assertTrue(
			$this->staging->is_production_env( $environments, 'no-such-env' ),
			'An unrecognised destination must fail closed, not skip the gate.'
		);
	}

	// ── The setting that makes a declaration authoritative ───────────────

	public function test_environment_type_setting_round_trips(): void {
		$settings = new Mcpwp_Settings();

		$this->assertSame( 'auto', $settings->get_defaults()['environment_type'] );
		$this->assertSame( 'staging', $settings->sanitize_settings( array( 'environment_type' => 'staging' ) )['environment_type'] );
	}

	public function test_unknown_environment_type_falls_back_to_auto(): void {
		$settings = new Mcpwp_Settings();

		$this->assertSame( 'auto', $settings->sanitize_settings( array( 'environment_type' => 'prod-ish' ) )['environment_type'] );
	}

	public function test_absent_environment_type_keeps_the_stored_declaration(): void {
		// A partial write must not silently revert an operator's declaration —
		// that would put an environment back behind (or in front of) the gate.
		$GLOBALS['mcpwp_test_options']['mcpwp_settings'] = array( 'environment_type' => 'staging' );

		$settings = new Mcpwp_Settings();

		$this->assertSame( 'staging', $settings->sanitize_settings( array() )['environment_type'] );
	}

	public function test_production_environments_from_other_hosts_are_unaffected(): void {
		$environments = array(
			array( 'id' => 'live', 'name' => 'Live', 'type' => 'production', 'url' => 'https://example.com' ),
			array( 'id' => 'stage', 'name' => 'Staging', 'type' => 'staging', 'url' => 'https://stg.example.com' ),
		);

		$this->assertTrue( $this->staging->is_production_env( $environments, 'live' ) );
		$this->assertFalse( $this->staging->is_production_env( $environments, 'stage' ) );
	}
}
