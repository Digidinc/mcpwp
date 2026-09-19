<?php
/**
 * Regression coverage for GH #669 — backport of the wp.org audit's finding
 * #4: the low-privilege mcpwp_api_agent role was granted mcpwp_manage_settings,
 * which every API key authenticates as (see set_api_user_context() and the
 * #874 docblock on get_required_scope_for_request() in
 * trait-mcpwp-api-auth.php) regardless of that key's own declared scope. That
 * made current_user_can('mcpwp_manage_settings') trivially true for ANY
 * authenticated key, collapsing Mcpwp_REST_Site_Updates::can_manage_api_keys()
 * — the check guarding /api-keys, /rate-limit, and the plugin self-update
 * trigger — into a no-op: only the key's own admin scope, already checked in
 * verify_api_key(), actually stood between an agent-bound key and those
 * routes.
 *
 * Two things must both be true, mirroring the shape #861b established for
 * grant_agent_published_page_caps():
 *
 *   1. A FRESH activation's role literal in create_api_role_and_user() never
 *      grants mcpwp_manage_settings.
 *   2. An EXISTING install — where add_role() already created the role WITH
 *      the capability, and add_role() no-ops on a role that already exists —
 *      gets it stripped by a dedicated one-time migration
 *      (strip_agent_manage_settings_capability()), gated on its own flag
 *      rather than the mcpwp_db_version compare, because this ships without
 *      a version bump.
 *
 * Also covers the reverse of #875 L1: the migration cannot tell "never
 * stripped yet" apart from "an admin deliberately granted it back" — it can
 * only ever run its removal logic once (never re-entrant, verified below).
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-mcpwp-activator.php';

final class AgentRoleManageSettingsCapabilityTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options'] = array();
		$GLOBALS['mcpwp_test_roles']   = array();
	}

	/**
	 * Fresh-install shape: add_role() actually creates the role, so the
	 * literal array must not list mcpwp_manage_settings.
	 */
	public function test_fresh_role_definition_excludes_manage_settings(): void {
		add_role(
			'mcpwp_api_agent',
			'MCPWP API Agent',
			array(
				'read'                  => true,
				'edit_posts'            => true,
				'mcpwp_manage_webhooks' => true,
			)
		);

		$role = get_role( 'mcpwp_api_agent' );
		$this->assertNotNull( $role );
		$this->assertFalse( $role->has_cap( 'mcpwp_manage_settings' ) );
	}

	/**
	 * The actual literal capability array shipped in
	 * Mcpwp_Activator::create_api_role_and_user() — read via reflection so
	 * this test fails the moment someone edits that array back without also
	 * updating this assertion.
	 */
	public function test_shipped_role_capability_source_excludes_manage_settings(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-mcpwp-activator.php' );

		$this->assertNotFalse( $source );
		$this->assertMatchesRegularExpression(
			"/add_role\\(\\s*'mcpwp_api_agent'.*?\\);/s",
			$source
		);

		if ( preg_match( "/add_role\\(\\s*'mcpwp_api_agent'.*?\\);/s", $source, $m ) ) {
			$this->assertStringNotContainsString( "'mcpwp_manage_settings'", $m[0] );
			// The legacy manage_options migration block just below must not
			// re-add it either.
			$legacy_block_start = strpos( $source, "has_cap( 'manage_options' )" );
			$this->assertNotFalse( $legacy_block_start );
			$legacy_block = substr( $source, $legacy_block_start, 400 );
			$this->assertStringNotContainsString( "add_cap( 'mcpwp_manage_settings' )", $legacy_block );
		}
	}

	/**
	 * Existing-install shape: the role already exists WITH the capability —
	 * exactly what add_role() leaves behind on every site that activated
	 * before #669's array-literal removal, since add_role() no-ops when the
	 * role is already registered. The one-time migration must strip it.
	 */
	public function test_migration_removes_capability_on_existing_install(): void {
		add_role(
			'mcpwp_api_agent',
			'MCPWP API Agent',
			array(
				'read'                  => true,
				'edit_posts'            => true,
				'mcpwp_manage_settings' => true,
				'mcpwp_manage_webhooks' => true,
			)
		);

		$role = get_role( 'mcpwp_api_agent' );
		$this->assertTrue( $role->has_cap( 'mcpwp_manage_settings' ), 'precondition: pre-migration role must already have the cap' );

		Mcpwp_Activator::strip_agent_manage_settings_capability();

		$role = get_role( 'mcpwp_api_agent' );
		$this->assertFalse( $role->has_cap( 'mcpwp_manage_settings' ) );

		// Untouched: the migration removes exactly this one capability.
		$this->assertTrue( $role->has_cap( 'read' ) );
		$this->assertTrue( $role->has_cap( 'edit_posts' ) );
		$this->assertTrue( $role->has_cap( 'mcpwp_manage_webhooks' ) );

		$this->assertSame( 1, get_option( 'mcpwp_agent_manage_settings_capability_migrated' ) );
	}

	/**
	 * The companion grant: administrator gets mcpwp_manage_settings so the
	 * capability stays reachable somewhere, instead of becoming dead on
	 * every install.
	 */
	public function test_migration_grants_capability_to_administrator(): void {
		add_role( 'mcpwp_api_agent', 'MCPWP API Agent', array( 'mcpwp_manage_settings' => true ) );
		add_role( 'administrator', 'Administrator', array( 'manage_options' => true ) );

		$this->assertFalse( get_role( 'administrator' )->has_cap( 'mcpwp_manage_settings' ), 'precondition' );

		Mcpwp_Activator::strip_agent_manage_settings_capability();

		$this->assertTrue( get_role( 'administrator' )->has_cap( 'mcpwp_manage_settings' ) );
	}

	/**
	 * Fresh installs never get the capability on the agent role — running
	 * the migration on a role that never had it is a harmless no-op.
	 */
	public function test_migration_is_a_no_op_when_agent_role_never_had_the_capability(): void {
		add_role( 'mcpwp_api_agent', 'MCPWP API Agent', array( 'read' => true ) );
		add_role( 'administrator', 'Administrator', array( 'manage_options' => true ) );

		Mcpwp_Activator::strip_agent_manage_settings_capability();

		$this->assertFalse( get_role( 'mcpwp_api_agent' )->has_cap( 'mcpwp_manage_settings' ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( 'mcpwp_manage_settings' ), 'administrator still gets the companion grant' );
		$this->assertSame( 1, get_option( 'mcpwp_agent_manage_settings_capability_migrated' ) );
	}

	/**
	 * Idempotent: calling it twice in the same request (mcpwp.php currently
	 * does, at two historical call sites merged from separate branches — see
	 * #861b's own duplicate-call comment) must not error or change the
	 * outcome.
	 */
	public function test_migration_is_idempotent(): void {
		add_role( 'mcpwp_api_agent', 'MCPWP API Agent', array( 'mcpwp_manage_settings' => true ) );
		add_role( 'administrator', 'Administrator', array( 'manage_options' => true ) );

		Mcpwp_Activator::strip_agent_manage_settings_capability();
		Mcpwp_Activator::strip_agent_manage_settings_capability();

		$this->assertFalse( get_role( 'mcpwp_api_agent' )->has_cap( 'mcpwp_manage_settings' ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( 'mcpwp_manage_settings' ) );
		$this->assertSame( 1, get_option( 'mcpwp_agent_manage_settings_capability_migrated' ) );
	}

	/**
	 * Reverse of #875 L1: the migration cannot distinguish "never stripped
	 * yet" from "an admin deliberately granted it back" (custom code, a
	 * compatibility shim, informed risk acceptance) — it can only ever run
	 * once. A second call, after the flag is set, must be a total no-op even
	 * if the role now holds the capability again.
	 */
	public function test_migration_does_not_re_strip_a_deliberately_restored_capability(): void {
		add_role( 'mcpwp_api_agent', 'MCPWP API Agent', array( 'mcpwp_manage_settings' => true ) );
		add_role( 'administrator', 'Administrator', array( 'manage_options' => true ) );

		Mcpwp_Activator::strip_agent_manage_settings_capability();
		$this->assertSame( 1, get_option( 'mcpwp_agent_manage_settings_capability_migrated' ) );

		// An administrator (or their own code) deliberately re-adds it.
		$role = get_role( 'mcpwp_api_agent' );
		$role->add_cap( 'mcpwp_manage_settings' );

		Mcpwp_Activator::strip_agent_manage_settings_capability();

		$role = get_role( 'mcpwp_api_agent' );
		$this->assertTrue( $role->has_cap( 'mcpwp_manage_settings' ), 'a second run must be a no-op once the flag is set, even against a deliberate re-grant' );
	}

	/**
	 * Defensive: a site somehow missing either role must not fatal.
	 */
	public function test_migration_is_safe_when_roles_are_missing(): void {
		$this->assertNull( get_role( 'mcpwp_api_agent' ) );
		$this->assertNull( get_role( 'administrator' ) );

		Mcpwp_Activator::strip_agent_manage_settings_capability();

		$this->assertSame( 1, get_option( 'mcpwp_agent_manage_settings_capability_migrated' ) );
	}
}
