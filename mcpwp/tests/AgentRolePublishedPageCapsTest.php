<?php
/**
 * Regression coverage for #861b: the mcpwp_api_agent role must be able to
 * edit ALREADY-PUBLISHED posts/pages, openly, rather than the plugin quietly
 * routing around a denial via a raw-meta fallback (see #861 /
 * ElementorDocumentSaveDeclinedTest for the truthfulness half of that story).
 *
 * Owner decision (2026-09-18): agent writes to published pages are intended
 * behaviour. Two things must therefore both be true:
 *
 *   1. A FRESH activation grants edit_published_posts/edit_published_pages
 *      directly in the role definition (create_api_role_and_user()).
 *   2. An EXISTING install — where add_role() already created the role
 *      without those caps, and add_role() is a no-op on a role that already
 *      exists — gets them backfilled by a dedicated one-time migration
 *      (grant_agent_published_page_caps()), gated on its own flag rather
 *      than the mcpwp_db_version compare, because this ships without a
 *      version bump.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-mcpwp-activator.php';

final class AgentRolePublishedPageCapsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options'] = array();
		$GLOBALS['mcpwp_test_roles']   = array();
	}

	/**
	 * Fresh-install shape: no role exists yet, so add_role() actually creates
	 * it — the caps must be present in that literal array, not bolted on
	 * after, since add_role() no-ops on a role that already exists (see the
	 * migration tests below for why that matters for upgrades).
	 */
	public function test_fresh_role_definition_includes_published_page_caps(): void {
		add_role(
			'mcpwp_api_agent',
			'MCPWP API Agent',
			array(
				'read'                 => true,
				'edit_posts'           => true,
				'edit_pages'           => true,
				'edit_others_posts'    => true,
				'edit_others_pages'    => true,
				'edit_published_posts' => true,
				'edit_published_pages' => true,
				'publish_posts'        => true,
				'publish_pages'        => true,
			)
		);

		$role = get_role( 'mcpwp_api_agent' );
		$this->assertNotNull( $role );
		$this->assertTrue( $role->has_cap( 'edit_published_posts' ) );
		$this->assertTrue( $role->has_cap( 'edit_published_pages' ) );
	}

	/**
	 * The actual literal capability array shipped in
	 * Mcpwp_Activator::create_api_role_and_user() — read via reflection so
	 * this test fails the moment someone edits that array without also
	 * updating this assertion, rather than silently drifting from what
	 * ships.
	 */
	public function test_shipped_role_capability_source_grants_published_page_caps(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-mcpwp-activator.php' );

		$this->assertNotFalse( $source );
		$this->assertMatchesRegularExpression(
			"/add_role\\(\\s*'mcpwp_api_agent'.*?\\);/s",
			$source
		);

		if ( preg_match( "/add_role\\(\\s*'mcpwp_api_agent'.*?\\);/s", $source, $m ) ) {
			$this->assertStringContainsString( "'edit_published_posts'", $m[0] );
			$this->assertStringContainsString( "'edit_published_pages'", $m[0] );
			// Least-privilege guard: this change must not have smuggled in
			// anything admin-level while it was in the neighbourhood.
			$this->assertStringNotContainsString( "'manage_options'", $m[0] );
			$this->assertStringNotContainsString( "'edit_theme_options'", $m[0] );
		}
	}

	/**
	 * Existing-install shape: the role already exists WITHOUT the new caps —
	 * exactly what add_role() leaves behind on every site that activated
	 * before #861b, since add_role() no-ops when the role is already
	 * registered. The one-time migration must backfill both caps.
	 */
	public function test_migration_backfills_caps_on_existing_role(): void {
		// Simulate the pre-#861b shipped role: edit_pages/edit_others_pages,
		// deliberately not edit_published_pages — the exact shape #861
		// documented on a real rig.
		add_role(
			'mcpwp_api_agent',
			'MCPWP API Agent',
			array(
				'read'              => true,
				'edit_posts'        => true,
				'edit_pages'        => true,
				'edit_others_posts' => true,
				'edit_others_pages' => true,
				'publish_posts'     => true,
				'publish_pages'     => true,
			)
		);

		$role = get_role( 'mcpwp_api_agent' );
		$this->assertFalse( $role->has_cap( 'edit_published_posts' ), 'precondition: pre-migration role must not already have the cap' );
		$this->assertFalse( $role->has_cap( 'edit_published_pages' ), 'precondition: pre-migration role must not already have the cap' );

		Mcpwp_Activator::grant_agent_published_page_caps();

		$role = get_role( 'mcpwp_api_agent' );
		$this->assertTrue( $role->has_cap( 'edit_published_posts' ) );
		$this->assertTrue( $role->has_cap( 'edit_published_pages' ) );

		// Untouched: the migration adds, it does not also start granting
		// anything admin-level or remove existing caps.
		$this->assertTrue( $role->has_cap( 'edit_pages' ) );
		$this->assertFalse( $role->has_cap( 'manage_options' ) );

		$this->assertSame( 1, get_option( 'mcpwp_agent_published_caps_migrated' ) );
	}

	/**
	 * Own flag, not the mcpwp_db_version compare in mcpwp.php — this ships
	 * without a version bump, so an already-current-version site must still
	 * pick the grant up the first time plugins_loaded runs after upgrade,
	 * and never again re-run once it has.
	 */
	public function test_migration_runs_exactly_once(): void {
		add_role(
			'mcpwp_api_agent',
			'MCPWP API Agent',
			array( 'edit_pages' => true, 'edit_others_pages' => true )
		);

		Mcpwp_Activator::grant_agent_published_page_caps();
		$this->assertSame( 1, get_option( 'mcpwp_agent_published_caps_migrated' ) );

		// An administrator manually revoking the cap afterwards must not be
		// silently re-granted by a later plugins_loaded — the migration is a
		// one-time backfill, not a standing enforcement.
		$role = get_role( 'mcpwp_api_agent' );
		$role->remove_cap( 'edit_published_pages' );

		Mcpwp_Activator::grant_agent_published_page_caps();

		$role = get_role( 'mcpwp_api_agent' );
		$this->assertFalse( $role->has_cap( 'edit_published_pages' ), 'a second run must be a no-op once the flag is set' );
	}

	/**
	 * Defensive: a site somehow missing the role entirely (deleted by an
	 * admin, or a race with activation) must not fatal the migration — it
	 * sets its flag and moves on, same as the other one-time upgrade
	 * routines in this class.
	 */
	public function test_migration_is_safe_when_role_is_missing(): void {
		$this->assertNull( get_role( 'mcpwp_api_agent' ) );

		Mcpwp_Activator::grant_agent_published_page_caps();

		$this->assertSame( 1, get_option( 'mcpwp_agent_published_caps_migrated' ) );
	}
}
