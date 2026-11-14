<?php
/**
 * Plugin Activator
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/core/class-mcpwp-custom-code-conditions.php';

// mcpwp_activate() (mcpwp.php) requires this file directly, without going
// through plugins_loaded's own require order — so this file cannot assume
// the trait is already loaded the way most of the codebase can. Idempotent.
require_once __DIR__ . '/traits/trait-mcpwp-api-auth.php';

/**
 * Fired during plugin activation.
 */
class Mcpwp_Activator {

	use Mcpwp_Api_Auth;

	/**
	 * Activate the plugin.
	 *
	 * Generate API key if not exists, set up options.
	 */
	public static function activate() {
		// Create API role and user
		self::create_api_role_and_user();

		self::ensure_api_keys();

		// Set default options
		if ( false === get_option( 'mcpwp_settings' ) ) {
			$defaults = array(
				'enable_logging'     => true,
				'log_retention_days' => 30,
				'log_store_response_data' => true,
				'log_redaction_keys' => array(
					'api_key',
					'x-api-key',
					'authorization',
					'password',
					'secret',
					'token',
					'access_token',
					'refresh_token',
					'client_secret',
				),
				'allowed_origins'    => '',
				'alerts_enabled'          => false,
				'alerts_window_minutes'   => 5,
				'alerts_cooldown_minutes' => 15,
				'alerts_5xx_threshold'    => 5,
				'alerts_auth_threshold'   => 10,
			);
			update_option( 'mcpwp_settings', $defaults );
		}

		// Create activity log table
		self::create_tables();

		// Ensure periodic log cleanup is scheduled.
		self::schedule_log_cleanup();

		// The approvals table itself is created by create_tables() above.
		// Activation NEVER migrates into it or derives anything (#1042
		// review round 4): an activation request runs without the plugin's
		// REST classes loaded (plugins_loaded ran before it was active), so
		// visibility categories derived here would be wrong and permanent.
		// The first request's wp_loaded does the migration and the identity
		// purge (mcpwp.php).

		// One-time: mark existing custom-role API keys with an empty category
		// list as deny-all in storage (#1041, see method docblock).
		self::migrate_empty_custom_category_keys();

		// Set version
		update_option( 'mcpwp_version', MCPWP_VERSION );

		// Flush rewrite rules
		flush_rewrite_rules();
	}

	/**
	 * One-time purge of pending approvals that carry no requester identity.
	 *
	 * Before per-request identity stamping, an approval created through an OAuth
	 * connector was stored with an empty `requested_by_key_id`. After the fix,
	 * that grant reconnects as a stably-identified principal that would sail
	 * through the `empty( requested_by_key_id )` branch of check_self_approval()
	 * and approve/apply the very request it created — reopening the self-approval
	 * bypass for records that predate the fix. Unlike the token case this never
	 * self-heals, because a pending approval persists until actioned.
	 *
	 * The safe, non-runtime-semantic remediation is to drop those unattributed
	 * still-actionable records at upgrade so they must be recreated with proper
	 * attribution. Gated by a flag so it runs once (a later upgrade must not keep
	 * deleting legitimate admin-created empty-requester approvals). Both PENDING
	 * and APPROVED (approved-but-not-yet-applied) records are exploitable — the
	 * approve and apply paths both run check_self_approval(), and the old bypass
	 * could have already self-approved a request, so purging pending alone would
	 * leave an approved record applyable. Terminal records (applied, rejected,
	 * rolled_back) are left untouched — they can't be re-actioned.
	 */
	/**
	 * One-time reset of the destructive-approval switch (#536 round 2).
	 *
	 * An early build of the gate exposed destructive_requires_approval
	 * through the settings API; a principal that flipped it off then would
	 * keep the gate off after upgrade, silently. A stored false is removed
	 * once (the default is on); an administrator can switch it off again
	 * from wp-admin, which is the only place it is settable now.
	 */
	public static function reset_destructive_switch() {
		if ( get_option( 'mcpwp_destructive_switch_reset' ) ) {
			return;
		}

		$settings = get_option( 'mcpwp_settings', array() );
		if ( is_array( $settings ) && array_key_exists( 'destructive_requires_approval', $settings ) && empty( $settings['destructive_requires_approval'] ) ) {
			unset( $settings['destructive_requires_approval'] );
			update_option( 'mcpwp_settings', $settings );
		}

		update_option( 'mcpwp_destructive_switch_reset', 1, false );
	}

	/**
	 * Label analytics opt-ins stored before 3.11.1, once.
	 *
	 * A true stored before 3.11.1 proves nothing about who chose it: the
	 * settings form wrote it, but so could any API key — the generic option
	 * tool accepted spai_settings in 2.8.x and mcpwp_settings until v3.4.2
	 * (review r2 N1). Such a value is labelled analytics_choice = legacy.
	 * Mcpwp_Analytics counts only "explicit" as consent on Free, so a legacy
	 * true is off there until an administrator ticks the box in Settings (a
	 * notice asks); a paying site keeps its plan default. Own flag: a true
	 * written later without a recorded choice is never labelled.
	 */
	public static function stamp_analytics_choice() {
		if ( get_option( 'mcpwp_analytics_choice_stamped' ) ) {
			return;
		}

		$settings = get_option( 'mcpwp_settings', array() );
		if ( is_array( $settings ) && array_key_exists( 'analytics_enabled', $settings )
			&& in_array( $settings['analytics_enabled'], array( true, 1, '1' ), true )
			&& ! isset( $settings['analytics_choice'] ) ) {
			$settings['analytics_choice'] = 'legacy';
			update_option( 'mcpwp_settings', $settings );
		}

		update_option( 'mcpwp_analytics_choice_stamped', 1, false );
	}

	/**
	 * Option holding a durable, admin-visible record of what
	 * grant_agent_published_page_caps() actually did the one time it ran.
	 */
	const AGENT_PUBLISHED_CAPS_GRANT_RECORD_OPTION = 'mcpwp_agent_published_caps_grant_record';

	/** Whether an admin has dismissed the one-time grant notice below. */
	const AGENT_PUBLISHED_CAPS_NOTICE_DISMISSED_OPTION = 'mcpwp_agent_published_caps_notice_dismissed';

	/** admin-post action name for dismissing the notice below. */
	const AGENT_PUBLISHED_CAPS_NOTICE_ACTION = 'mcpwp_dismiss_agent_published_caps_notice';

	/**
	 * One-time grant of edit_published_posts/edit_published_pages to the
	 * mcpwp_api_agent role, for sites that already had the role created (#861b).
	 *
	 * add_role() is a no-op when the role already exists — it does NOT merge
	 * in newly-added capabilities on an existing install — so bumping the
	 * array literal in create_api_role_and_user() above only reaches sites
	 * that activate the plugin fresh from here on. Every already-installed
	 * site keeps the role exactly as it was created, which is the whole
	 * problem: that role has edit_pages/edit_others_pages but not
	 * edit_published_pages, so WordPress denies edit_post on a published page
	 * and Elementor's Document::save() silently declines (bool false, no
	 * exception, no WP_Error) while MCPWP's raw-meta fallback writes the
	 * content anyway with no capability check, no revision, and no Elementor
	 * pipeline (#861).
	 *
	 * Deliberately NOT gated on the mcpwp_db_version compare in mcpwp.php:
	 * this ships without a version bump (constraint of #861b), so a site
	 * already on the current version never re-enters that branch. Own flag,
	 * same pattern as reset_destructive_switch()/stamp_analytics_choice()
	 * above — runs once on plugins_loaded, idempotent, safe to call on a
	 * fresh install too (get_role() returns the just-created role with the
	 * caps already present, so add_cap() is a harmless no-op there).
	 *
	 * NOT RE-ENTRANT BY DESIGN (#875 review finding L1). A forced capability
	 * grant cannot tell "this role never had the cap" apart from "an admin
	 * deliberately removed it" — has_cap()/add_cap() see only current state,
	 * never intent. An admin who, on an older version, manually stripped
	 * edit_published_pages as a stopgap for #861 (a reasonable reaction
	 * before this fix existed) must not have that choice silently re-applied
	 * on every future upgrade just because the site still lacks the cap.
	 * That is why the guard above is a one-time flag and not a `has_cap()`
	 * check on its own: once `mcpwp_agent_published_caps_migrated` is set,
	 * this method returns immediately on every later call, forever,
	 * regardless of what the role's capabilities look like by then — an
	 * admin's later, deliberate removal is never overwritten again.
	 *
	 * The one time this DOES run, though, it can still silently override an
	 * existing stopgap with no trace — has_cap()/add_cap() alone leave none.
	 * record_published_caps_grant() below makes that single event durable
	 * and admin-visible (an options-table record plus an admin_notices
	 * surface, shown only when a capability was actually added — i.e. only
	 * when something could have been overridden, never on a fresh install
	 * where the role already had both caps).
	 */
	public static function grant_agent_published_page_caps() {
		if ( get_option( 'mcpwp_agent_published_caps_migrated' ) ) {
			return;
		}

		$role  = get_role( 'mcpwp_api_agent' );
		$added = array();

		if ( $role ) {
			if ( ! $role->has_cap( 'edit_published_posts' ) ) {
				$role->add_cap( 'edit_published_posts' );
				$added[] = 'edit_published_posts';
			}
			if ( ! $role->has_cap( 'edit_published_pages' ) ) {
				$role->add_cap( 'edit_published_pages' );
				$added[] = 'edit_published_pages';
			}
		}

		self::record_published_caps_grant( (bool) $role, $added );

		update_option( 'mcpwp_agent_published_caps_migrated', 1, false );
	}

	/**
	 * Persist what the one-time grant above actually did, so it is auditable
	 * after the fact instead of leaving only its side effect on the role.
	 *
	 * @param bool     $role_existed Whether mcpwp_api_agent existed at all.
	 * @param string[] $caps_added   Capabilities that were actually missing
	 *                                and got added. Empty means the role
	 *                                already had both — nothing was
	 *                                overridden (fresh install, or a site
	 *                                that never touched these caps).
	 */
	private static function record_published_caps_grant( $role_existed, array $caps_added ) {
		update_option(
			self::AGENT_PUBLISHED_CAPS_GRANT_RECORD_OPTION,
			array(
				'granted_at'   => gmdate( 'c' ),
				'role_existed' => $role_existed,
				'caps_added'   => $caps_added,
			),
			false
		);
	}

	/**
	 * admin_notices: surface the one-time grant above, but only when it
	 * actually added a capability the role was missing — the case where an
	 * admin's own prior choice (deliberate revocation, or a custom/legacy
	 * role) could have just been overridden without them doing anything.
	 * Silent (no notice) on a fresh install or a site where the role already
	 * had both caps, since nothing there could have been overridden.
	 */
	public static function maybe_render_published_caps_grant_notice() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( get_option( self::AGENT_PUBLISHED_CAPS_NOTICE_DISMISSED_OPTION ) ) {
			return;
		}

		$record = get_option( self::AGENT_PUBLISHED_CAPS_GRANT_RECORD_OPTION );
		if ( ! is_array( $record ) || empty( $record['caps_added'] ) ) {
			return;
		}

		$dismiss_url = add_query_arg(
			array(
				'action'   => self::AGENT_PUBLISHED_CAPS_NOTICE_ACTION,
				'_wpnonce' => wp_create_nonce( self::AGENT_PUBLISHED_CAPS_NOTICE_ACTION ),
			),
			admin_url( 'admin-post.php' )
		);

		printf(
			'<div class="notice notice-warning"><p>%1$s</p><p><a class="button" href="%2$s">%3$s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %s: comma-separated list of WordPress capability names, e.g. "edit_published_posts, edit_published_pages" */
					__( 'MCPWP granted the MCPWP API Agent role the following capabilities so it can save changes to already-published pages through Elementor\'s normal pipeline instead of a raw fallback: %s. If you had deliberately removed one of these, you will need to remove it again — this is a one-time upgrade step and will not repeat.', 'mcpwp' ),
					implode( ', ', array_map( 'sanitize_key', $record['caps_added'] ) )
				)
			),
			esc_url( $dismiss_url ),
			esc_html__( 'Dismiss', 'mcpwp' )
		);
	}

	/**
	 * Dismiss the one-time grant notice for this site.
	 *
	 * @param string $nonce Nonce for AGENT_PUBLISHED_CAPS_NOTICE_ACTION.
	 * @return bool Whether it was dismissed.
	 */
	public static function dismiss_published_caps_grant_notice( $nonce ) {
		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( (string) $nonce, self::AGENT_PUBLISHED_CAPS_NOTICE_ACTION ) ) {
			return false;
		}
		update_option( self::AGENT_PUBLISHED_CAPS_NOTICE_DISMISSED_OPTION, 1, false );
		return true;
	}

	/**
	 * admin-post handler for the notice's Dismiss link.
	 */
	public static function handle_dismiss_published_caps_grant_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified in dismiss_published_caps_grant_notice().
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! self::dismiss_published_caps_grant_notice( $nonce ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'mcpwp' ), '', array( 'response' => 403 ) );
		}
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url( 'admin.php?page=mcpwp-settings' ) );
		exit;
	}

	/**
	 * One-time removal of mcpwp_manage_settings from the mcpwp_api_agent role,
	 * for sites where it was already granted (GH #669 — backport of the
	 * wp.org audit's finding #4: "the low-privilege mcpwp_api_agent role is
	 * granted mcpwp_manage_settings, collapsing admin-route capability gates
	 * to scope-only").
	 *
	 * add_role() is a no-op when the role already exists — it does NOT strip
	 * capabilities the array literal in create_api_role_and_user() above no
	 * longer lists — so removing 'mcpwp_manage_settings' from that array only
	 * reaches a fresh activation. Every already-installed site keeps the
	 * capability exactly as it was granted, which is the whole problem: every
	 * API key — whatever scope it was created with — authenticates as this
	 * SAME shared mcpwp_api_agent WordPress user (see set_api_user_context()
	 * and the #874 docblock on get_required_scope_for_request() in
	 * trait-mcpwp-api-auth.php), so current_user_can('mcpwp_manage_settings')
	 * was trivially true for every authenticated key regardless of its own
	 * declared scope. That reduced Mcpwp_REST_Site_Updates::
	 * can_manage_api_keys() — the second, supposedly-independent check
	 * guarding /api-keys, /rate-limit, and the plugin self-update trigger —
	 * to a no-op: only the key's own admin scope, checked earlier in
	 * verify_api_key(), actually stood between an agent-bound key and those
	 * routes.
	 *
	 * Deliberately NOT gated on the mcpwp_db_version compare in mcpwp.php:
	 * this ships without a version bump, so a site already on the current
	 * version would otherwise never re-enter that branch and would keep the
	 * over-grant forever. Own flag, same pattern as
	 * grant_agent_published_page_caps() above — runs once on plugins_loaded,
	 * idempotent, safe to call on a fresh install too (the array literal
	 * above never grants the capability there, so remove_cap() is a harmless
	 * no-op).
	 *
	 * NOT RE-ENTRANT BY DESIGN, mirroring grant_agent_published_page_caps()'s
	 * #875 L1 reasoning — inverted. That migration will not silently
	 * re-apply a capability grant an admin deliberately removed afterward,
	 * because has_cap()/add_cap() see only current state, never intent. The
	 * same limitation applies here in reverse: has_cap()/remove_cap() cannot
	 * tell "never removed yet" apart from "an admin deliberately granted it
	 * back" (custom code, a compatibility shim, or an informed acceptance of
	 * the risk). If this method re-checked current capability state on every
	 * plugins_loaded instead of a one-time flag, it would silently overwrite
	 * that deliberate choice forever, turning a one-time security backfill
	 * into a standing enforcement nobody can opt out of on their own site.
	 * The asymmetry with L1 (there, respecting a REMOVAL that lowers
	 * privilege; here, respecting a RE-GRANT that raises it back) is
	 * accepted: nothing in MCPWP's own API surface can call add_cap() on a
	 * WordPress role — there is no REST/MCP tool that edits roles or
	 * capabilities — so the only way this capability could reappear on
	 * mcpwp_api_agent post-migration is direct PHP/database access on the
	 * site, i.e. a principal who already has complete control over the site
	 * regardless of this one capability.
	 *
	 * The companion administrator grant in create_api_role_and_user() above
	 * is NOT gated the same way — see that comment for why it is safe to
	 * (re)ensure on every activation instead.
	 */
	public static function strip_agent_manage_settings_capability() {
		if ( get_option( 'mcpwp_agent_manage_settings_capability_migrated' ) ) {
			return;
		}

		$role = get_role( 'mcpwp_api_agent' );
		if ( $role && $role->has_cap( 'mcpwp_manage_settings' ) ) {
			$role->remove_cap( 'mcpwp_manage_settings' );
		}

		// Belt-and-suspenders companion to the standing ensure-grant in
		// create_api_role_and_user(): a site that upgrades without ever
		// re-running activate() (no deactivate/reactivate cycle) would
		// otherwise not pick up the administrator grant either.
		$admin_role = get_role( 'administrator' );
		if ( $admin_role && ! $admin_role->has_cap( 'mcpwp_manage_settings' ) ) {
			$admin_role->add_cap( 'mcpwp_manage_settings' );
		}

		update_option( 'mcpwp_agent_manage_settings_capability_migrated', 1, false );
	}

	public static function migrate_identity_less_approvals() {
		if ( get_option( 'mcpwp_approvals_identity_migrated' ) ) {
			return;
		}

		// #1042: the store is a table now. The purge runs against it once
		// the one-time copy out of the legacy option has finished; until
		// then the flag below stays unset and this is retried next load.
		$migrated = self::migrate_approvals_to_table();
		if ( is_wp_error( $migrated ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'mcpwp_approvals';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name is prefix + constant.
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE status IN (%s, %s) AND requested_by_key_id = %s", 'pending', 'approved', '' ) );
		if ( false === $deleted ) {
			return; // Retried on the next load: the flag below is not set.
		}

		update_option( 'mcpwp_approvals_identity_migrated', 1, false );
	}

	/**
	 * The approvals table's CREATE TABLE SQL (#1042) — factored like
	 * action_log_create_sql() so create_tables() and
	 * migrate_approvals_to_table() dbDelta() the same definition.
	 *
	 * One row per approval. `record` holds the whole record JSON-encoded,
	 * exactly as the old option held it; status, action,
	 * requested_by_key_id, created_at, updated_at, triage_rank, cap_exempt
	 * (whether the requester was exempt from the pending caps) and
	 * quota_bytes (the record's unescaped JSON size, what the per-requester
	 * byte quota sums) and visibility_categories (a small JSON array, so
	 * visibility is decided without decoding `record`) are copies for filtering, ordering and counting,
	 * derived only by
	 * Mcpwp_Approvals::row_from_record(). `version` is the optimistic-
	 * concurrency counter every update checks. `seq` is insertion order:
	 * requests created in the same second list in the order they were
	 * created, as they did in the option (the migration inserts in option
	 * order).
	 *
	 * @param string $table           Prefixed table name.
	 * @param string $charset_collate $wpdb->get_charset_collate().
	 * @return string
	 */
	private static function approvals_create_sql( $table, $charset_collate ) {
		// No `IF NOT EXISTS` — see action_log_create_sql().
		return "CREATE TABLE $table (
			id varchar(64) NOT NULL,
			seq bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			status varchar(20) NOT NULL DEFAULT '',
			action varchar(64) NOT NULL DEFAULT '',
			requested_by_key_id varchar(191) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			triage_rank tinyint(4) NOT NULL DEFAULT -1,
			cap_exempt tinyint(1) NOT NULL DEFAULT 0,
			quota_bytes int(10) unsigned NOT NULL DEFAULT 0,
			visibility_categories varchar(1000) NOT NULL DEFAULT '',
			categories_pending tinyint(1) NOT NULL DEFAULT 0,
			rederive_attempted_at datetime DEFAULT NULL,
			version bigint(20) unsigned NOT NULL DEFAULT 0,
			record longtext NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY seq (seq),
			KEY status (status),
			KEY requested_by_key_id (requested_by_key_id),
			KEY created_at (created_at),
			KEY updated_at (updated_at),
			KEY categories_pending (categories_pending)
		) $charset_collate;";
	}

	/**
	 * Lock guarding migrate_approvals_to_table(). The copy is bounded (the
	 * option held at most a few hundred records) but a cold dbDelta() plus
	 * those INSERTs on slow hosting deserves a generous ceiling.
	 */
	const APPROVALS_TABLE_LOCK_OPTION = 'mcpwp_approvals_table_migration_lock';
	const APPROVALS_TABLE_LOCK_TTL    = 60;

	/**
	 * One-time move of the approvals store from the serialized option
	 * `mcpwp_approval_requests` to `{prefix}mcpwp_approvals` (#1042), plus
	 * the table's schema upgrades and the "late import" of a recreated
	 * option.
	 *
	 * Gated on its OWN flag (`mcpwp_approvals_table_migrated`), not only on
	 * the mcpwp_db_version compare in mcpwp.php — same reasoning as every
	 * other flag-gated migration in this file: a same-version deployment
	 * never re-enters that branch. Also called lazily by the approvals store
	 * itself, so no read or write can ever reach an empty table while the
	 * option still holds the queue.
	 *
	 * Locked with acquire_migration_lock(). While another request holds it,
	 * this answers approvals_busy (503, retry_after 2) and so does every
	 * approval read and write — there is no dual-read path.
	 *
	 * Steps, each safe to re-run after a crash: dbDelta() the table (and,
	 * below schema Mcpwp_Approvals::SCHEMA_VERSION, backfill any column it
	 * added); copy every record of the option with INSERT IGNORE by id
	 * (idempotent; an id already in the table wins); skip — and list in
	 * `mcpwp_approvals_migration_skipped` — a record that is malformed or
	 * cannot be encoded, rather than block the store forever; verify the
	 * table holds at least as many rows as were copyable; only then merge the
	 * option into `mcpwp_approval_requests_pre_3149` (autoload off; ids
	 * already in the backup win) — kept, not deleted, so a downgrade or a bad
	 * migration can be recovered by hand; cleanup_approvals_backup() (#1053)
	 * removes it later, fail closed —
	 * delete the option and set the flag.
	 *
	 * Late import (#1042 review round 1, W3): if the option exists again
	 * AFTER the flag is set — a worker still running the old code during the
	 * upgrade, or time spent downgraded to 3.14.8 — the same copy runs again
	 * (lazily, on the next approvals read/write, or from the hourly cron),
	 * so those records reach the table instead of being stranded. Only ids
	 * never imported before enter the table (the backup is the tombstone),
	 * and identity-less pending/approved ones are dropped.
	 *
	 * Held under 3.14.8's own store lock as well as the migration lock, from
	 * reading the option to deleting it.
	 *
	 * @return true|WP_Error
	 */
	public static function migrate_approvals_to_table() {
		$flag = 'mcpwp_approvals_table_migrated';
		if ( ! class_exists( 'Mcpwp_Approvals' ) ) {
			require_once __DIR__ . '/core/class-mcpwp-approvals.php';
		}
		if ( self::approvals_store_current() ) {
			return true;
		}

		// Every copy and backfill derives visibility categories, which needs
		// every post type registered. Before `init` (a plugin's product or
		// template type does not exist yet) nothing is derived or written
		// (#1042 review round 3); mcpwp.php runs this on wp_loaded.
		if ( ! Mcpwp_Approvals::post_types_ready() ) {
			return Mcpwp_Approvals::busy_error();
		}

		$lock_token = self::acquire_migration_lock( self::APPROVALS_TABLE_LOCK_OPTION, self::APPROVALS_TABLE_LOCK_TTL );
		if ( false === $lock_token ) {
			return Mcpwp_Approvals::busy_error();
		}

		try {
			// Another request may have finished while we waited for the lock.
			if ( function_exists( 'wp_cache_delete' ) ) {
				foreach ( array( $flag, Mcpwp_Approvals::SCHEMA_OPTION, Mcpwp_Approvals::OPTION_NAME, Mcpwp_Approvals::LEGACY_BACKUP_OPTION, 'alloptions', 'notoptions' ) as $cached ) {
					wp_cache_delete( $cached, 'options' );
				}
			}
			if ( self::approvals_store_current() ) {
				return true;
			}

			global $wpdb;
			$migrated = (bool) get_option( $flag );

			if ( (int) get_option( Mcpwp_Approvals::SCHEMA_OPTION, 0 ) < Mcpwp_Approvals::SCHEMA_VERSION ) {
				if ( ! function_exists( 'dbDelta' ) ) {
					require_once ABSPATH . 'wp-admin/includes/upgrade.php';
				}
				dbDelta( self::approvals_create_sql( $wpdb->prefix . 'mcpwp_approvals', $wpdb->get_charset_collate() ) );
				// A table created by an earlier schema gets its new columns
				// filled from the records themselves.
				$backfilled = Mcpwp_Approvals::backfill_derived_columns( (int) get_option( Mcpwp_Approvals::SCHEMA_OPTION, 0 ) );
				if ( is_wp_error( $backfilled ) ) {
					return $backfilled;
				}
			}

			// 3.14.8 wrote the option under its own named lock. Hold that
			// lock too from reading the option to deleting it, so an old-code
			// worker still running mid-upgrade cannot write into the gap
			// (#1042 review round 2, WARN 2).
			$old_lock = substr( 'mcpwp_approvals_' . $wpdb->prefix, 0, 64 );
			if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $old_lock, 5 ) ) ) {
				return Mcpwp_Approvals::busy_error();
			}
			try {
				if ( function_exists( 'wp_cache_delete' ) ) {
					wp_cache_delete( Mcpwp_Approvals::OPTION_NAME, 'options' );
				}
				$items = get_option( Mcpwp_Approvals::OPTION_NAME, false );

				// A 2.8.x site whose queue is still under spai_approval_requests
				// (the spai_ → mcpwp_ bridge has not copied it — not run yet, or
				// stopped on an unrelated table): take that one option directly,
				// the same mapping the bridge uses, rather than wait on the
				// whole bridge (#1042 review round 3). Once the table is
				// migrated, the bridge no longer copies it (Mcpwp_Migrate).
				if ( ! $migrated && false === $items ) {
					$spai = get_option( 'spai_approval_requests', false );
					if ( false !== $spai ) {
						$items = $spai;
					}
				}

				if ( false !== $items ) {
					$backup = get_option( Mcpwp_Approvals::LEGACY_BACKUP_OPTION, array() );
					$backup = is_array( $backup ) ? $backup : array();
					$copy   = is_array( $items ) ? $items : array();

					if ( $migrated ) {
						// Late import. An id already in the backup was imported
						// before — it may since have been decided, purged or
						// deleted; re-inserting it would resurrect it (#1042
						// review round 2, WARN 1). The backup is the tombstone:
						// only genuinely new ids enter the table. And a new
						// record gets the same identity check the first upgrade
						// ran: an identity-less pending/approved one is dropped
						// (migrate_identity_less_approvals()).
						foreach ( $copy as $id => $record ) {
							$status    = is_array( $record ) && isset( $record['status'] ) ? (string) $record['status'] : '';
							$requester = is_array( $record ) && isset( $record['requested_by_key_id'] ) ? (string) $record['requested_by_key_id'] : '';
							if ( array_key_exists( $id, $backup ) || ( in_array( $status, array( 'pending', 'approved' ), true ) && '' === $requester ) ) {
								unset( $copy[ $id ] );
							}
						}
					}

					$copied = Mcpwp_Approvals::import_legacy_items( $copy );
					if ( is_wp_error( $copied ) ) {
						return $copied;
					}

					if ( ! empty( $copied['skipped'] ) ) {
						$known = get_option( Mcpwp_Approvals::SKIPPED_OPTION, array() );
						$known = is_array( $known ) ? $known : array();
						update_option( Mcpwp_Approvals::SKIPPED_OPTION, array_values( array_unique( array_merge( $known, $copied['skipped'] ) ) ), false );
						if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
							// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
							error_log( 'MCPWP approvals migration: skipped records it could not copy (kept in mcpwp_approval_requests_pre_3149): ' . implode( ', ', $copied['skipped'] ) );
						}
					}

					// Merge into the backup; an id already there wins, so a late
					// import never overwrites what the first migration kept.
					$merged = is_array( $items ) ? $backup + $items : ( empty( $backup ) ? $items : $backup );
					update_option( Mcpwp_Approvals::LEGACY_BACKUP_OPTION, $merged, false );
					if ( function_exists( 'wp_cache_delete' ) ) {
						wp_cache_delete( Mcpwp_Approvals::LEGACY_BACKUP_OPTION, 'options' );
					}
					if ( get_option( Mcpwp_Approvals::LEGACY_BACKUP_OPTION, null ) !== $merged ) {
						return Mcpwp_Approvals::store_failed_error();
					}
					delete_option( Mcpwp_Approvals::OPTION_NAME );
				}
			} finally {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $old_lock ) );
			}

			update_option( Mcpwp_Approvals::SCHEMA_OPTION, Mcpwp_Approvals::SCHEMA_VERSION );
			// The flag holds the time of the FIRST migration (the backup's age
			// gate reads it); every other reader only tests truthiness. A
			// late import must not move it.
			if ( ! get_option( $flag ) ) {
				update_option( $flag, time() );
			}
			return true;
		} finally {
			self::release_migration_lock( self::APPROVALS_TABLE_LOCK_OPTION, $lock_token );
		}
	}

	/**
	 * Transient (a day) holding the last outcome of the backup shrink, so
	 * "why is it still full" has an answer (#1053). A transient, not an
	 * option: nothing for the uninstaller to chase.
	 */
	const APPROVALS_BACKUP_CLEANUP_STATUS = 'mcpwp_approvals_backup_cleanup_status';

	/** Ids checked per `WHERE id IN (...)` query by the backup shrink. */
	const APPROVALS_BACKUP_CHECK_CHUNK = 200;

	/**
	 * The hourly late-import cron (Mcpwp_Approvals::LATE_IMPORT_CRON_HOOK):
	 * the migration/late import, then the backup shrink — but only when the
	 * migration answered cleanly, so a failed run never shrinks a backup it
	 * could not reconcile.
	 *
	 * @return true|WP_Error The migration's answer.
	 */
	public static function run_approvals_late_import_tick() {
		$migrated = self::migrate_approvals_to_table();
		if ( ! is_wp_error( $migrated ) ) {
			self::cleanup_approvals_backup();
		}
		return $migrated;
	}

	/**
	 * Shrink `mcpwp_approval_requests_pre_3149` (#1053), the recovery copy
	 * migrate_approvals_to_table() keeps of the old option.
	 *
	 * What it does: once the backup is old enough and the table demonstrably
	 * holds (or legitimately no longer holds) every record in it, each full
	 * record is replaced by `true`, leaving an `id => true` tombstone map.
	 * The late import still needs that map: array_key_exists() on it is what
	 * stops an already-decided id from being resurrected when an old-code
	 * worker or a downgrade recreates `mcpwp_approval_requests`, and
	 * `$backup + $items` keeps working. The map is only ids, so almost all of
	 * the size goes away. The option itself is deleted only when it is an
	 * empty array. The tombstone map is never age-deleted: deleting it would
	 * re-open the resurrection hole for good, for a few KB.
	 *
	 * What it is NOT: one-time. A late import re-merges recovered records
	 * into the backup, and the next tick shrinks those the same way (they
	 * were verified present first; the recovery copy of late-imported
	 * records is therefore short-lived).
	 *
	 * Gates, in order. Fail closed — each refusal leaves the option alone
	 * and records its reason in APPROVALS_BACKUP_CLEANUP_STATUS:
	 *  - `backup_unreadable`: the row exists but get_option() cannot read it
	 *    (corrupt serialization).
	 *  - `not_migrated`: the table flag is unset.
	 *  - `too_recent`: the flag holds the time the site migrated (a `1` from
	 *    an older release is stamped with now on this first pass), and the
	 *    backup is younger than Mcpwp_Approvals::CLOSED_RETENTION_DAYS days —
	 *    the rollback window for a site that just updated.
	 *  - `busy`: the migration lock is held.
	 *  - `late_import_pending` (legacy option present), `skipped_records`
	 *    (the copy skipped a record, so the backup holds its only copy).
	 *  - `backup_unrecognised`: not an array, or a record that is not an
	 *    array.
	 *  - `table_unreadable`, `rows_missing`: every full record id must be in
	 *    the table, or legitimately gone (see approvals_backup_record_is_gone()).
	 *    A table dropped and recreated with one new approval fails this.
	 *
	 * Per site (options and table are per blog; the hourly cron runs once per
	 * site). Cron or admin context only, never a public request hook.
	 *
	 * @return string Outcome: shrunk | deleted | nothing_to_clean | the
	 *                refusal codes above | delete_failed | shrink_failed.
	 */
	public static function cleanup_approvals_backup() {
		global $wpdb;
		if ( ! class_exists( 'Mcpwp_Approvals' ) ) {
			require_once __DIR__ . '/core/class-mcpwp-approvals.php';
		}
		$backup_option = Mcpwp_Approvals::LEGACY_BACKUP_OPTION;
		$backup        = get_option( $backup_option, false );
		if ( false === $backup ) {
			// Gone, or present but unreadable (corrupt serialization makes
			// get_option() answer false too): look at the row itself.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", $backup_option ) );
			return (int) $row > 0 ? self::approvals_backup_refused( 'backup_unreadable' ) : 'nothing_to_clean';
		}
		if ( ! get_option( Mcpwp_Approvals::MIGRATED_FLAG ) ) {
			return self::approvals_backup_refused( 'not_migrated' );
		}

		$lock_token = self::acquire_migration_lock( self::APPROVALS_TABLE_LOCK_OPTION, self::APPROVALS_TABLE_LOCK_TTL );
		if ( false === $lock_token ) {
			return self::approvals_backup_refused( 'busy' );
		}

		try {
			if ( function_exists( 'wp_cache_delete' ) ) {
				foreach ( array( $backup_option, Mcpwp_Approvals::OPTION_NAME, Mcpwp_Approvals::SKIPPED_OPTION, Mcpwp_Approvals::MIGRATED_FLAG, 'alloptions', 'notoptions' ) as $cached ) {
					wp_cache_delete( $cached, 'options' );
				}
			}
			$backup = get_option( $backup_option, false );
			if ( false === $backup ) {
				return 'nothing_to_clean';
			}

			// Age gate. The flag holds the migration time; a bare `1` (set by
			// a release before this one) is stamped with now, so the window
			// starts at the first pass rather than never.
			$migrated_at = (int) get_option( Mcpwp_Approvals::MIGRATED_FLAG );
			if ( $migrated_at <= 1 ) {
				update_option( Mcpwp_Approvals::MIGRATED_FLAG, time() );
				return self::approvals_backup_refused( 'too_recent' );
			}
			if ( time() - $migrated_at < (int) Mcpwp_Approvals::CLOSED_RETENTION_DAYS * DAY_IN_SECONDS ) {
				return self::approvals_backup_refused( 'too_recent' );
			}

			if ( false !== get_option( Mcpwp_Approvals::OPTION_NAME, false ) ) {
				return self::approvals_backup_refused( 'late_import_pending' );
			}
			if ( ! empty( get_option( Mcpwp_Approvals::SKIPPED_OPTION, array() ) ) ) {
				// A skipped record exists nowhere but the backup.
				return self::approvals_backup_refused( 'skipped_records' );
			}
			if ( ! is_array( $backup ) ) {
				return self::approvals_backup_refused( 'backup_unrecognised' );
			}
			if ( array() === $backup ) {
				delete_option( $backup_option );
				if ( function_exists( 'wp_cache_delete' ) ) {
					wp_cache_delete( $backup_option, 'options' );
				}
				if ( false !== get_option( $backup_option, false ) ) {
					return self::approvals_backup_refused( 'delete_failed' );
				}
				return self::approvals_backup_done( 'deleted' );
			}

			$records = array();
			foreach ( $backup as $id => $record ) {
				if ( true === $record ) {
					continue; // Already a tombstone.
				}
				if ( ! is_array( $record ) ) {
					return self::approvals_backup_refused( 'backup_unrecognised' );
				}
				$records[ (string) $id ] = $record;
			}
			if ( empty( $records ) ) {
				return 'nothing_to_clean';
			}

			$present = self::approvals_present_ids( array_keys( $records ) );
			if ( null === $present ) {
				return self::approvals_backup_refused( 'table_unreadable' );
			}
			foreach ( $records as $id => $record ) {
				if ( ! isset( $present[ $id ] ) && ! self::approvals_backup_record_is_gone( $id, $record ) ) {
					return self::approvals_backup_refused( 'rows_missing' );
				}
			}

			$tombstones = array();
			foreach ( $backup as $id => $record ) {
				$tombstones[ $id ] = true;
			}
			update_option( $backup_option, $tombstones, false );
			if ( function_exists( 'wp_cache_delete' ) ) {
				wp_cache_delete( $backup_option, 'options' );
			}
			if ( get_option( $backup_option, null ) !== $tombstones ) {
				return self::approvals_backup_refused( 'shrink_failed' );
			}
			return self::approvals_backup_done( 'shrunk' );
		} finally {
			self::release_migration_lock( self::APPROVALS_TABLE_LOCK_OPTION, $lock_token );
		}
	}

	/**
	 * Which of $ids are rows of the approvals table.
	 *
	 * @param array<int,string> $ids Approval ids.
	 * @return array<string,true>|null Present ids, or null when the table cannot be read.
	 */
	private static function approvals_present_ids( array $ids ) {
		global $wpdb;
		$table   = Mcpwp_Approvals::table_name();
		$present = array();
		foreach ( array_chunk( $ids, self::APPROVALS_BACKUP_CHECK_CHUNK ) as $chunk ) {
			$wpdb->last_error = '';
			$in               = implode( ', ', array_fill( 0, count( $chunk ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name is prefix + constant; ids are bound.
			$found = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE id IN ({$in})", $chunk ) );
			if ( ! is_array( $found ) || '' !== (string) $wpdb->last_error ) {
				return null;
			}
			foreach ( $found as $id ) {
				$present[ (string) $id ] = true;
			}
		}
		return $present;
	}

	/**
	 * A backup record that is not in the table and should not be. Three
	 * things really remove a record, and only these count:
	 *  - the migration's purge of an identity-less pending/approved record
	 *    (also dropped by the late import) — no audit row, so it is matched
	 *    by its shape;
	 *  - retention and the closed-record purge, both through
	 *    Mcpwp_Approvals::audited_delete(): the approval_record_removed audit
	 *    row is written BEFORE the DELETE, so its presence proves the removal
	 *    (checked for every record, open or closed);
	 *  - for a record that was CLOSED in the backup, being older than the
	 *    retention window, in case the audit row was pruned from the log.
	 * An OPEN record (pending, approved, applying, rolling_back, apply_failed)
	 * is never removed any other way — not even an expired-looking pending
	 * post_content_update, which expire_stale_one() only re-labels `expired`
	 * in place — so one missing from the table without an audit row is a
	 * record the table lost. An unreadable audit log counts as "not logged"
	 * (fail closed). A record with no usable date is never "gone" by age.
	 *
	 * @param string $id     Approval id.
	 * @param array  $record A backup record.
	 * @return bool
	 */
	private static function approvals_backup_record_is_gone( $id, array $record ) {
		$status = isset( $record['status'] ) ? (string) $record['status'] : '';
		$open   = in_array( $status, Mcpwp_Approvals::open_statuses(), true );
		if ( in_array( $status, array( 'pending', 'approved' ), true ) && ( ! isset( $record['requested_by_key_id'] ) || '' === (string) $record['requested_by_key_id'] ) ) {
			return true;
		}
		if ( ! class_exists( 'Mcpwp_Action_Log' ) ) {
			$log_file = __DIR__ . '/core/class-mcpwp-action-log.php';
			if ( is_readable( $log_file ) ) {
				require_once $log_file;
			}
		}
		if ( class_exists( 'Mcpwp_Action_Log' ) && method_exists( 'Mcpwp_Action_Log', 'approval_removal_logged' ) && true === Mcpwp_Action_Log::approval_removal_logged( $id ) ) {
			return true;
		}
		if ( $open ) {
			return false;
		}
		$days = (int) apply_filters( 'mcpwp_approvals_closed_retention_days', Mcpwp_Approvals::CLOSED_RETENTION_DAYS );
		if ( $days < 1 ) {
			return false; // Retention is off: nothing is ever deleted.
		}
		$last = 0;
		foreach ( array( 'created_at', 'updated_at', 'decided_at', 'approved_at', 'rejected_at', 'applied_at', 'expired_at', 'rolled_back_at' ) as $key ) {
			if ( ! empty( $record[ $key ] ) ) {
				$time = strtotime( (string) $record[ $key ] );
				$last = $time && $time > $last ? $time : $last;
			}
		}
		if ( 0 === $last ) {
			return false;
		}
		return $last < time() - $days * DAY_IN_SECONDS;
	}

	/**
	 * Record a refusal.
	 *
	 * @param string $reason Outcome code.
	 * @return string The same code.
	 */
	private static function approvals_backup_refused( $reason ) {
		set_transient( self::APPROVALS_BACKUP_CLEANUP_STATUS, $reason, DAY_IN_SECONDS );
		if ( 'not_migrated' !== $reason && 'too_recent' !== $reason && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'MCPWP approvals backup cleanup: left mcpwp_approval_requests_pre_3149 alone (' . $reason . ')' );
		}
		return $reason;
	}

	/**
	 * Record a success.
	 *
	 * @param string $outcome Outcome code.
	 * @return string The same code.
	 */
	private static function approvals_backup_done( $outcome ) {
		set_transient( self::APPROVALS_BACKUP_CLEANUP_STATUS, $outcome, DAY_IN_SECONDS );
		return $outcome;
	}

	/**
	 * The store needs nothing: migrated, schema current, and the legacy
	 * option has not reappeared.
	 *
	 * @return bool
	 */
	private static function approvals_store_current() {
		return get_option( 'mcpwp_approvals_table_migrated' )
			&& (int) get_option( Mcpwp_Approvals::SCHEMA_OPTION, 0 ) >= Mcpwp_Approvals::SCHEMA_VERSION
			&& false === get_option( Mcpwp_Approvals::OPTION_NAME, false );
	}

	/**
	 * One-time migration (#1041): mark every existing custom-role API key
	 * whose stored tool_categories is empty (never selected, or every
	 * selected slug had already failed sanitisation) as deny-all in storage.
	 *
	 * resolve_key_tool_categories() now resolves an empty custom-role
	 * category list to the deny-all sentinel at RUNTIME regardless of what is
	 * stored — see that method — so this migration is not what closes the
	 * security hole; the runtime fix already does that unconditionally, for
	 * every key, pre-existing or newly created. This migration exists so
	 * already-affected keys are self-documenting in storage rather than
	 * showing a blank category list that reads like a display bug (WP Admin
	 * > mcpwp > Setup renders the sentinel as "None (all denied)"), and as
	 * defense-in-depth for any future code that reads a key's tool_categories
	 * directly without going through resolve_key_tool_categories(). It never
	 * revokes or deletes a key — an admin who wants one gone still has to
	 * revoke it explicitly.
	 *
	 * Covers both the current (mcpwp_api_keys) and legacy (spai_api_keys)
	 * scoped-key stores — find_legacy_spai_key() still authenticates against
	 * spai_api_keys directly on a site that has not been through
	 * Mcpwp_Migrate::run() yet, so a custom-role key minted before the
	 * spai_ → mcpwp_ rename is exposed to exactly the same #1041 bug.
	 *
	 * Gated on its OWN flag, not the mcpwp_db_version check in mcpwp.php's
	 * plugins_loaded — a same-version deployment never re-enters that branch,
	 * and this is a security-relevant data fix, same reasoning as
	 * migrate_identity_less_approvals() above.
	 *
	 * #1041 round-2 adversarial review of PR #1043:
	 * - NIT-2: "is this key custom-role with nothing granted" is now decided
	 *   by running the SAME normalize_api_key_record() +
	 *   resolve_key_tool_categories() pair the runtime auth path uses —
	 *   instead of a raw `'custom' !== $key['role']` string-equality check
	 *   (which missed a differently-cased/whitespaced stored value the
	 *   runtime's own sanitize_key() would still treat as 'custom') and a raw
	 *   `is_array($key['tool_categories'])` check (which treated a legacy
	 *   record whose tool_categories was stored as a bare string, e.g.
	 *   `'content'`, as EMPTY and overwrote a key that actually resolves —
	 *   and works — as `['content']` at runtime, via normalize_api_key_record()'s
	 *   `(array)` cast). Using the real resolver end to end means this
	 *   migration can never mark a key that runtime auth does not also
	 *   consider deny-all, or vice versa.
	 * - NIT-3: locked with the shared migration mutex (#1036,
	 *   acquire_migration_lock()/release_migration_lock()) so two concurrent
	 *   MIGRATION runs cannot each read-modify-write mcpwp_api_keys and lose
	 *   one side's change; the option is RE-READ inside the lock rather than
	 *   reusing an earlier, now possibly-stale read; and the completion flag
	 *   is set only once every store that needed a write has a VERIFIED one
	 *   (update_option() returning true, or the value already matching what
	 *   this run would have written — see the loop below) — never
	 *   unconditionally, which used to mark the migration done even when the
	 *   write itself silently failed. NIT-B: the lock only serialises against
	 *   ANOTHER migration run — create_scoped_api_key() and
	 *   touch_api_key_last_used() still read-modify-write mcpwp_api_keys
	 *   without taking it, so a key created or touched while this migration
	 *   holds the lock can still race it; that pre-existing race is not fixed
	 *   here.
	 */
	public static function migrate_empty_custom_category_keys() {
		if ( get_option( 'mcpwp_deny_all_categories_migrated' ) ) {
			return;
		}

		$lock_token = self::acquire_migration_lock( self::DENY_ALL_CATEGORIES_LOCK_OPTION, self::DENY_ALL_CATEGORIES_LOCK_TTL );
		if ( false === $lock_token ) {
			// Another request already owns this; skip — the holder (or, if it
			// died, the next call once the lock's TTL lapses) makes progress.
			return;
		}

		try {
			// Re-check the flag now that the lock is held: another request may
			// have finished the whole migration between our first check above
			// and acquiring the lock.
			if ( get_option( 'mcpwp_deny_all_categories_migrated' ) ) {
				return;
			}

			$auth   = new self();
			$all_ok = true;

			// The transformation, as a pure function of the keys it is given.
			$transform = static function ( array $keys ) use ( $auth ) {
				$changed = false;
				foreach ( $keys as &$key ) {
					if ( ! is_array( $key ) ) {
						continue;
					}

					$normalized = $auth->normalize_api_key_record( $key );
					if ( 'custom' !== $normalized['role'] ) {
						continue;
					}

					$is_deny_all = self::is_deny_all_categories( $auth->resolve_key_tool_categories( $normalized ) );
					$already_marked = array( self::deny_all_category_marker() ) === array_values( (array) ( $key['tool_categories'] ?? array() ) );

					if ( $is_deny_all && ! $already_marked ) {
						$key['tool_categories'] = array( self::deny_all_category_marker() );
						$changed                = true;
					}
				}
				unset( $key );
				return array( $changed ? $keys : null, $changed );
			};

			// mcpwp_api_keys goes through Mcpwp_Key_Store (#1096): this runs on
			// the request path, and a plain read-modify-write here could
			// overwrite a key created or revoked in the meantime. The read
			// happens INSIDE the store lock, from the database. A busy store
			// leaves the migration unfinished and it is retried.
			if ( ! class_exists( 'Mcpwp_Key_Store' ) ) {
				require_once __DIR__ . '/core/class-mcpwp-key-store.php';
			}
			$store = Mcpwp_Key_Store::mutate(
				static function ( array $keys ) use ( $transform ) {
					return empty( $keys ) ? array( null, false ) : $transform( $keys );
				},
				false
			);
			if ( ! $store['written'] ) {
				$all_ok = false;
			}

			// The legacy 2.8 store is not contended by any request path.
			$option_name = 'spai_api_keys';
			{
				// Re-read INSIDE the lock — never reuse a read from before the
				// lock was acquired, which could already be stale relative to a
				// write another request made while we were waiting.
				$keys = get_option( $option_name, array() );
				if ( is_array( $keys ) && ! empty( $keys ) ) {
					list( $new_keys, $changed ) = $transform( $keys );
					if ( $changed ) {
						$keys    = $new_keys;
						$written = update_option( $option_name, $keys );
						if ( ! $written ) {
							// update_option() returns false both on a genuine
							// write failure AND when the new value is
							// byte-identical to what is already stored; verify
							// by re-reading rather than assuming either.
							$verify = get_option( $option_name, array() );
							if ( $verify !== $keys ) {
								$all_ok = false;
							}
						}
					}
				}
			}

			// Only declare done once every store that needed a write has a
			// verified one (#1041 NIT-3) — an unverified partial run must be
			// retried on the next request, not marked complete.
			if ( $all_ok ) {
				update_option( 'mcpwp_deny_all_categories_migrated', 1, false );
			}
		} finally {
			self::release_migration_lock( self::DENY_ALL_CATEGORIES_LOCK_OPTION, $lock_token );
		}
	}

	/**
	 * Lock guarding migrate_empty_custom_category_keys(). TTL is short — the
	 * work is a handful of in-memory option reads/writes, no per-row DB
	 * queries — but comfortably above a slow worst case, same margin
	 * ACTION_LOG_COLUMN_LOCK_TTL uses for the same reason.
	 */
	const DENY_ALL_CATEGORIES_LOCK_OPTION = 'mcpwp_deny_all_categories_migration_lock';
	const DENY_ALL_CATEGORIES_LOCK_TTL    = 15;

	/** Set once every 2.8 spai credential is in the scoped store or dead (#1098). Autoloaded: read on every request. */
	const SPAI_ADOPTION_FLAG = 'mcpwp_spai_credentials_adopted';

	/** Marker (short-lived transient) that an attempt failed; the next one waits. */
	const SPAI_ADOPTION_BACKOFF     = 'mcpwp_spai_adoption_backoff';
	const SPAI_ADOPTION_BACKOFF_TTL = 60;

	/** Label of an adopted record; the Setup page explains what it can do. */
	const SPAI_ADOPTED_LABEL = 'Migrated 2.8 key';

	/** The id the bridge gives the single spai_api_key; kept, because approvals compare key ids. */
	const SPAI_SINGLE_KEY_ID = 'spai_legacy_single';

	/**
	 * One-time adoption of the 2.8 spai credentials into the revocable store
	 * (#1098), then deletion of the originals.
	 *
	 * On the normal upgrade path activate() mints mcpwp_api_key before
	 * Mcpwp_Migrate::run(), so a single spai_api_key is never copied (the copy
	 * skips a target that already has a value). That key then authenticated as
	 * admin through find_legacy_spai_key() for good, and no screen could revoke
	 * it. Here every live spai credential becomes an ordinary scoped record
	 * that Active Keys lists and Revoke kills, and the spai options are
	 * deleted so the bridge has nothing left to honour.
	 *
	 * One Mcpwp_Key_Store::mutate() call (#1166): the store lock, a database
	 * read, a compare-and-swap. The mutator is a pure function of the keys it
	 * is handed, so a revoke that lands first is simply read and kept:
	 *  - a hash already in the store, active OR revoked, is never added again;
	 *  - a REVOKED spai record is written as a revoked placeholder, so the
	 *    revocation outlives the deletion of the spai options;
	 *  - an adopted record keeps exactly what the bridge granted it (scopes,
	 *    role, tool_categories, rate_limits, allow_destructive) and its id —
	 *    the single key keeps 'spai_legacy_single': the approvals
	 *    self-approval check compares key ids, so renaming an identity would
	 *    let a key approve its own earlier request;
	 *  - on a site whose store is empty, the legacy mcpwp_api_key is honoured
	 *    only while the store stays empty, so it moves into the store in the
	 *    same write.
	 *
	 * Fails closed: an unreadable store, or a refused write (unreadable,
	 * unsupported_characters, unwritable, busy), changes nothing and leaves the
	 * flag unset. Nothing is built on a store that merely READS as empty.
	 *
	 * The originals are deleted only after the write reports written, the
	 * single key first (deleting spai_api_keys first would leave a revoked twin
	 * of spai_api_key as a live admin record), and the flag is set only after
	 * both deletes are verified. A failed delete keeps the originals; the
	 * revoked placeholders keep the bridge refusing their hashes. Nothing here
	 * logs or prints a key or a hash.
	 *
	 * @return bool True when adoption is complete (flag set).
	 */
	public static function adopt_spai_credentials() {
		if ( get_option( self::SPAI_ADOPTION_FLAG ) ) {
			return true;
		}

		require_once __DIR__ . '/core/class-mcpwp-key-state.php';
		if ( Mcpwp_Key_State::store_is_unreadable() ) {
			return false; // Before any lock or query; a cached read, so cheap on every load.
		}

		$spai_keys   = get_option( 'spai_api_keys', false );
		$spai_single = get_option( 'spai_api_key', false );
		$has_options = false !== $spai_keys || false !== $spai_single;

		if ( $has_options ) {
			// A refused or half-finished attempt is not repeated on every load:
			// it would hash a plaintext key and verify against every stored hash
			// each time (#1178 review).
			if ( false !== get_transient( self::SPAI_ADOPTION_BACKOFF ) ) {
				return false;
			}
			if ( ! class_exists( 'Mcpwp_Key_Store' ) ) {
				require_once __DIR__ . '/core/class-mcpwp-key-store.php';
			}
			$stored = Mcpwp_Key_Store::mutate(
				self::spai_credential_mutator( $spai_keys, $spai_single, false ),
				false
			);
			if ( ! $stored['written'] || ! self::delete_spai_options() ) {
				set_transient( self::SPAI_ADOPTION_BACKOFF, 1, self::SPAI_ADOPTION_BACKOFF_TTL );
				return false;
			}
			delete_transient( self::SPAI_ADOPTION_BACKOFF );
		}

		update_option( self::SPAI_ADOPTION_FLAG, 1, true );
		return true;
	}

	/**
	 * End every 2.8 spai credential: "regenerate revokes every key" (#1098).
	 *
	 * Called by regenerate_api_key() once the new key exists. The spai hashes
	 * are first written as revoked placeholders, and any adopted record still
	 * active is revoked (an adoption that landed between the rotation's write
	 * and this one), so the revocation holds even when the delete below fails.
	 *
	 * @return bool True when both spai options are gone.
	 */
	public static function end_spai_credentials() {
		$spai_keys   = get_option( 'spai_api_keys', false );
		$spai_single = get_option( 'spai_api_key', false );
		if ( false === $spai_keys && false === $spai_single ) {
			return true;
		}
		require_once __DIR__ . '/core/class-mcpwp-key-state.php';
		if ( ! class_exists( 'Mcpwp_Key_Store' ) ) {
			require_once __DIR__ . '/core/class-mcpwp-key-store.php';
		}
		Mcpwp_Key_Store::mutate( self::spai_credential_mutator( $spai_keys, $spai_single, true ), true );
		return self::delete_spai_options();
	}

	/**
	 * Delete the spai credential options: the single key FIRST, then the store.
	 *
	 * @return bool True only when both are verified gone.
	 */
	private static function delete_spai_options() {
		$absent = '__mcpwp_row_absent__';
		foreach ( array( 'spai_api_key', 'spai_api_keys' ) as $option ) {
			if ( $absent !== get_option( $option, $absent ) ) {
				delete_option( $option );
			}
			if ( $absent !== get_option( $option, $absent ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The Mcpwp_Key_Store::mutate() callback for adopt (or, with $revoke, end)
	 * the spai credentials. A pure function of ($keys): it may run again after
	 * a swap miss, and then sees whatever landed in between.
	 *
	 * @param mixed $spai_keys   spai_api_keys value (false when absent).
	 * @param mixed $spai_single spai_api_key value (false when absent).
	 * @param bool  $revoke      Write every spai credential as revoked.
	 * @return callable
	 */
	public static function spai_credential_mutator( $spai_keys, $spai_single, $revoke ) {
		$spai_keys = is_array( $spai_keys ) ? $spai_keys : array();
		$single    = ( is_string( $spai_single ) && '' !== $spai_single ) ? $spai_single : '';
		$now       = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );

		// Hashed once, outside the mutator: a salted hash differs on every call.
		$single_is_hash = '' !== $single && Mcpwp_Key_State::is_password_hash_shaped( $single );
		$single_hash    = '' === $single ? '' : ( $single_is_hash ? $single : wp_hash_password( $single ) );

		$legacy       = (string) get_option( 'mcpwp_api_key', '' );
		$legacy_hash  = '' === $legacy ? '' : ( self::looks_like_password_hash( $legacy ) ? $legacy : wp_hash_password( $legacy ) );
		// A plaintext legacy key gets a fresh salted hash that no stored hash
		// equals, so it is also compared by verification.
		$legacy_plain = ( '' !== $legacy && ! self::looks_like_password_hash( $legacy ) ) ? $legacy : '';

		return static function ( array $keys ) use ( $spai_keys, $single, $single_is_hash, $single_hash, $legacy_hash, $legacy_plain, $now, $revoke ) {
			$present = array();
			foreach ( $keys as $record ) {
				if ( is_array( $record ) && isset( $record['hash'] ) && '' !== (string) $record['hash'] ) {
					$present[ (string) $record['hash'] ] = true; // Active OR revoked: never add it again.
				}
			}
			// The legacy single key is honoured only while the store is empty.
			$legacy_in_play = ! $revoke && empty( $keys ) && '' !== $legacy_hash;
			// Whether a stored hash is the legacy key's credential.
			$is_legacy = static function ( $hash ) use ( $legacy_hash, $legacy_plain ) {
				return (string) $hash === $legacy_hash || ( '' !== $legacy_plain && wp_check_password( $legacy_plain, (string) $hash ) );
			};

			$changed = false;
			if ( $revoke ) {
				// An adopted record still active is revoked with the rest.
				foreach ( $keys as &$record ) {
					if ( is_array( $record ) && 'spai' === ( $record['migrated_from'] ?? '' ) && empty( $record['revoked_at'] ) ) {
						$record['revoked_at'] = $now;
						$changed              = true;
					}
				}
				unset( $record );
			}

			$adds = array();

			// 1. Revoked placeholders — every revoked spai record (all of them
			// when ending), whatever its hash looks like: the bridge compares
			// hashes exactly to refuse a revoked twin.
			foreach ( $spai_keys as $key ) {
				if ( ! is_array( $key ) || ( ! $revoke && empty( $key['revoked_at'] ) ) ) {
					continue;
				}
				$hash = isset( $key['hash'] ) ? (string) $key['hash'] : '';
				if ( '' === $hash || isset( $present[ $hash ] ) ) {
					continue;
				}
				$id     = isset( $key['id'] ) ? sanitize_key( (string) $key['id'] ) : '';
				$adds[] = array(
					'id'            => '' !== $id ? $id : 'spai_revoked_' . substr( md5( $hash ), 0, 12 ),
					'label'         => self::SPAI_ADOPTED_LABEL,
					'hash'          => $hash,
					'created_at'    => ! empty( $key['created_at'] ) ? (string) $key['created_at'] : $now,
					'last_used_at'  => null,
					'revoked_at'    => ! empty( $key['revoked_at'] ) ? (string) $key['revoked_at'] : $now,
					'migrated_from' => 'spai',
				);
				$present[ $hash ] = true;
			}

			// Revoked in spai: a placeholder is this credential.
			$legacy_dead = false;
			if ( $legacy_in_play ) {
				foreach ( array_keys( $present ) as $hash ) {
					if ( $is_legacy( $hash ) ) {
						$legacy_dead = true;
						break;
					}
				}
			}

			// 2. Live spai_api_keys records, as the bridge granted them.
			if ( ! $revoke ) {
				foreach ( $spai_keys as $key ) {
					if ( ! is_array( $key ) || ! empty( $key['revoked_at'] ) ) {
						continue;
					}
					$hash = isset( $key['hash'] ) ? (string) $key['hash'] : '';
					$id   = isset( $key['id'] ) ? sanitize_key( (string) $key['id'] ) : '';
					// Not a real hash, or no id: the bridge refuses it (no plaintext
					// match for a store record, no principal without an id), so it
					// is already dead and is not adopted.
					if ( '' === $hash || '' === $id || isset( $present[ $hash ] ) || ! Mcpwp_Key_State::is_password_hash_shaped( $hash ) ) {
						continue;
					}
					if ( $legacy_in_play && $is_legacy( $hash ) ) {
						continue; // The legacy record below carries this credential.
					}
					$record = array(
						'id'            => $id,
						'label'         => self::SPAI_ADOPTED_LABEL,
						'hash'          => $hash,
						'created_at'    => ! empty( $key['created_at'] ) ? (string) $key['created_at'] : $now,
						'last_used_at'  => ! empty( $key['last_used_at'] ) ? (string) $key['last_used_at'] : null,
						'revoked_at'    => null,
						'migrated_from' => 'spai',
					);
					// Exactly what normalize_api_key_record() reads: no more, no less.
					foreach ( array( 'scopes', 'role', 'tool_categories', 'rate_limits', 'allow_destructive' ) as $field ) {
						if ( array_key_exists( $field, $key ) ) {
							$record[ $field ] = $key[ $field ];
						}
					}
					$adds[]           = $record;
					$present[ $hash ] = true;
				}
			}

			// 3. The single spai_api_key. A pre-1.0.14 value is PLAINTEXT, which
			// no string compare can match to a stored hash: it is checked
			// against every hash, and refused when it matches one.
			if ( '' !== $single_hash ) {
				$dup = isset( $present[ $single_hash ] ) || ( $legacy_in_play && $is_legacy( $single_hash ) );
				if ( ! $dup && ! $single_is_hash ) {
					$known = array_merge( array_keys( $present ), $legacy_in_play ? array( $legacy_hash ) : array() );
					foreach ( $known as $hash ) {
						if ( wp_check_password( $single, (string) $hash ) ) {
							$dup = true;
							break;
						}
					}
				}
				if ( ! $dup ) {
					$adds[] = array(
						'id'            => self::SPAI_SINGLE_KEY_ID,
						'label'         => self::SPAI_ADOPTED_LABEL,
						'hash'          => $single_hash,
						// As the bridge synthesised it: admin.
						'scopes'        => array( 'read', 'write', 'admin' ),
						'role'          => 'admin',
						'created_at'    => $now,
						'last_used_at'  => null,
						'revoked_at'    => $revoke ? $now : null,
						'migrated_from' => 'spai',
					);
					$present[ $single_hash ] = true;
				}
			}

			// An empty store honours the legacy mcpwp_api_key; the first record
			// switches that off, so the legacy key moves into the store with it.
			if ( ! empty( $adds ) && $legacy_in_play && ! $legacy_dead ) {
				array_unshift(
					$adds,
					array(
						'id'           => sanitize_key( function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'mcpwp_', true ) ),
						'label'        => 'Primary API Key',
						'hash'         => $legacy_hash,
						'scopes'       => array( 'read', 'write', 'admin' ),
						'created_at'   => $now,
						'last_used_at' => null,
						'revoked_at'   => null,
					)
				);
			}

			if ( empty( $adds ) && ! $changed ) {
				return array( null, true );
			}
			return array( array_merge( $keys, $adds ), true );
		};
	}

	/**
	 * Snippets repaired per plugins_loaded call, in addition to the time
	 * budget below — whichever limit is hit first ends the current run. Kept
	 * small enough that even a slow request (shared hosting, no object cache)
	 * comfortably clears one batch inside the budget, which is what
	 * guarantees forward progress on every call (see below).
	 */
	const CUSTOM_CODE_REPAIR_BATCH_SIZE = 200;

	const CUSTOM_CODE_CONDITIONS_REPAIR_CURSOR_OPTION = 'mcpwp_custom_code_conditions_repair_cursor';
	const CUSTOM_CODE_CONDITIONS_REPAIR_LOCK_OPTION   = 'mcpwp_custom_code_conditions_repair_lock';
	const CUSTOM_CODE_CONDITIONS_REPAIR_LOCK_TTL      = 30;
	const CUSTOM_CODE_CACHE_BACKFILL_DONE_OPTION = 'mcpwp_custom_code_cache_backfill_v1_done';
	const CUSTOM_CODE_CACHE_BACKFILL_STATE_OPTION = 'mcpwp_custom_code_cache_backfill_state';
	const CUSTOM_CODE_CACHE_REPAIR_FAILED_OPTION = 'mcpwp_custom_code_cache_repair_failed_ids';
	const CUSTOM_CODE_CACHE_REPAIR_FAILURE_STATE_OPTION = 'mcpwp_custom_code_cache_repair_failure_state';
	const CUSTOM_CODE_CACHE_REPAIR_MAX_ATTEMPTS = 5;

	/**
	 * Durable scan position for repair_custom_code_locations(): the highest
	 * `elementor_snippet` post ID already repaired. Not autoloaded — like the
	 * completion flag, it has no reason to load on a request that isn't
	 * already mid-migration.
	 */
	const CUSTOM_CODE_REPAIR_CURSOR_OPTION = 'mcpwp_custom_code_locations_repair_cursor';

	/**
	 * Mutex for repair_custom_code_locations(): guards the read-cursor ->
	 * process-batch -> write-cursor cycle so two overlapping `plugins_loaded`
	 * calls (two admin tabs, or an admin page load racing a REST or cron
	 * request) cannot both read the same stale cursor and have whichever
	 * writes last move it backward (RC review finding #2). Every per-row
	 * operation in this method is already idempotent, so a lost race never
	 * corrupts data — the lock exists purely to stop the redundant
	 * reprocessing (and, under sustained concurrent load, delayed
	 * convergence) that a backward-moving cursor causes.
	 *
	 * Implemented as a row in `wp_options` rather than a WordPress transient
	 * or `wp_cache_add()`: a transient's own read-then-write is exactly the
	 * unlocked race this is meant to close, and `wp_cache_add()` is only
	 * atomic when an external, atomic object cache (Memcached/Redis) is
	 * configured — on the default DB-backed object cache it is not.
	 * `option_name` carries a UNIQUE KEY at the schema level, so a plain
	 * `$wpdb->insert()` against it is genuinely atomic at the MySQL layer on
	 * every WordPress install, object cache or not: of two concurrent
	 * inserts, only one can land the row, the other fails on the unique-key
	 * violation. This is the same primitive `Mcpwp_Idempotency_Claim` (#802)
	 * already established for request-level claims — reused here at the row
	 * level via direct `$wpdb` calls (`insert()`/`update()`/`delete()`,
	 * never `add_option()`, which uses `ON DUPLICATE KEY UPDATE` and would
	 * let two concurrent "claims" both "succeed") rather than pulled in as a
	 * dependency, since that class's day-long sweep window and stored
	 * `<time>|<hash>` shape are built for deduplicating tool calls, not for
	 * a short-lived batch-loop mutex.
	 *
	 * The stored value is the lock's own expiry (a Unix timestamp), not a
	 * boolean, so a holder that dies mid-run (fatal error, worker recycle,
	 * `kill -9`) cannot block the migration forever: the next call whose
	 * insert loses the race reads the existing row, and if its expiry has
	 * passed, steals it with a compare-and-swap update
	 * (`WHERE option_value = <the stale value just read>`) rather than an
	 * unconditional one, so two callers racing to steal the same stale lock
	 * cannot both believe they hold it. Release is the same CAS shape:
	 * delete only the row that still holds the value THIS call wrote, so a
	 * run that overran its own TTL and had its lock legitimately stolen
	 * never deletes the new holder's lock out from under it. A run that
	 * cannot acquire the lock simply returns — it does not wait or retry
	 * inline — and picks up the migration on a later request instead.
	 */
	const CUSTOM_CODE_REPAIR_LOCK_OPTION = 'mcpwp_custom_code_locations_repair_lock';

	/**
	 * Seconds a lock holder is trusted before another call may steal it.
	 * Comfortably above migration_time_budget()'s 15s ceiling so a live run
	 * is never pre-empted by a false "stale" read; short enough that a dead
	 * holder's lock self-heals within a couple of requests.
	 */
	const CUSTOM_CODE_REPAIR_LOCK_TTL = 30;

	/**
	 * Upper bound on rows has_unrepaired_custom_code_snippets() will look at
	 * when proving completion. Absurdly far past any realistic
	 * `elementor_snippet` count (these are hand-authored Custom Code
	 * snippets, not user content) — it exists only so the one-time
	 * verification query is bounded the same way every batch query already
	 * is, not because a real site is expected to approach it.
	 */
	const CUSTOM_CODE_REPAIR_VERIFY_LIMIT = 1000000;

	/**
	 * Convert legacy Custom Code condition maps to Elementor Pro's string
	 * form without widening a condition.
	 *
	 * @param array $conditions Stored condition values.
	 * @return string[]|WP_Error
	 */
	public static function coerce_custom_code_conditions_to_strings( array $conditions ) {
		return Mcpwp_Custom_Code_Conditions::convert( $conditions );
	}

	/**
	 * Repair legacy condition maps in bounded, checkpointed batches.
	 *
	 * @param float|null $time_budget Per-request budget, used by tests.
	 * @return true|WP_Error
	 */
	public static function repair_custom_code_conditions( ?float $time_budget = null, $rescan = false ) {
		$done = get_option( self::CUSTOM_CODE_CACHE_BACKFILL_DONE_OPTION );
		$lock_token = self::acquire_migration_lock( self::CUSTOM_CODE_CONDITIONS_REPAIR_LOCK_OPTION, self::CUSTOM_CODE_CONDITIONS_REPAIR_LOCK_TTL );
		if ( false === $lock_token ) {
			return true;
		}
		try {
			$start  = microtime( true );
			$budget = null !== $time_budget ? $time_budget : self::migration_time_budget();
			self::retry_due_custom_code_cache_failures_in_budget( $start, $budget );

			if ( $done && ! $rescan ) {
				return true;
			}
			if ( $done && $rescan ) {
				delete_option( self::CUSTOM_CODE_CACHE_BACKFILL_DONE_OPTION );
				delete_option( self::CUSTOM_CODE_CONDITIONS_REPAIR_CURSOR_OPTION );
			}
			return self::run_custom_code_conditions_repair_batches( $time_budget );
		} finally {
			self::release_migration_lock( self::CUSTOM_CODE_CONDITIONS_REPAIR_LOCK_OPTION, $lock_token );
		}
	}

	/** Cheap maintenance gate; cache backfill never runs on an API write. */
	public static function custom_code_cache_maintenance_due() {
		return self::custom_code_cache_full_rescan_due() || self::custom_code_cache_retry_due();
	}

	/** A full scan is independent from the failure retry queue. */
	public static function custom_code_cache_full_rescan_due() {
		if ( ! get_option( self::CUSTOM_CODE_CACHE_BACKFILL_DONE_OPTION ) ) {
			return true;
		}
		$state = get_option( self::CUSTOM_CODE_CACHE_BACKFILL_STATE_OPTION, array() );
		return ! is_array( $state ) || ! isset( $state['marker'], $state['completed_at'] )
			|| (string) $state['marker'] !== (string) get_option( Mcpwp_Custom_Code_Conditions::REPORT_MARKER, '' )
			|| (int) $state['completed_at'] + Mcpwp_Custom_Code_Conditions::REPORT_TTL <= time();
	}

	/** True when at least one failed document has reached its retry time. */
	public static function custom_code_cache_retry_due() {
		foreach ( self::custom_code_cache_failure_state() as $state ) {
			if ( isset( $state['next_retry'] ) && (int) $state['next_retry'] <= time() ) {
				return true;
			}
		}
		return false;
	}

	/** Schedule a corrupt cache row for bounded admin repair without a read-side write. */
	public static function observe_custom_code_cache_entry_failure( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return;
		}
		$states = self::custom_code_cache_failure_state();
		if ( ! isset( $states[ $post_id ] ) ) {
			$states[ $post_id ] = array( 'attempts' => 0, 'next_retry' => 0, 'parked' => false );
			update_option( self::CUSTOM_CODE_CACHE_REPAIR_FAILURE_STATE_OPTION, $states, false );
		}
		$failed = array_map( 'intval', (array) get_option( self::CUSTOM_CODE_CACHE_REPAIR_FAILED_OPTION, array() ) );
		if ( ! in_array( $post_id, $failed, true ) ) {
			$failed[] = $post_id;
			sort( $failed );
			update_option( self::CUSTOM_CODE_CACHE_REPAIR_FAILED_OPTION, $failed, false );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'MCPWP Custom Code cache repair failed for snippet ' . $post_id . ': cache_entry_invalid' );
		}
	}

	/** Reset a parked document when its source metadata changes. */
	public static function reset_custom_code_cache_repair_failure( $post_id ) {
		$post_id = (int) $post_id;
		$states  = self::custom_code_cache_failure_state();
		if ( isset( $states[ $post_id ] ) ) {
			unset( $states[ $post_id ] );
			update_option( self::CUSTOM_CODE_CACHE_REPAIR_FAILURE_STATE_OPTION, $states, false );
			self::remove_custom_code_cache_failure_id( $post_id );
		}
	}

	/**
	 * Guarantee legacy condition maps cannot reach Elementor Pro's global
	 * cache rebuild. It intentionally scans even after the one-time flag:
	 * old data may be imported after the upgrade completed.
	 *
	 * @return true|WP_Error
	 */
	public static function ensure_custom_code_conditions_repaired() {
		// Request guards are idempotent per row and do not contend with the
		// upgrade cursor lock. One administrator's repair cannot refuse a save.
		$cursor = 0;
		do {
			$snippet_ids = self::custom_code_conditions_batch( $cursor, self::CUSTOM_CODE_REPAIR_BATCH_SIZE );
			if ( is_wp_error( $snippet_ids ) ) {
				return $snippet_ids;
			}
			foreach ( $snippet_ids as $post_id ) {
				self::repair_one_custom_code_conditions( (int) $post_id );
				$cursor = (int) $post_id;
			}
		} while ( count( $snippet_ids ) === self::CUSTOM_CODE_REPAIR_BATCH_SIZE );
		return true;
	}

	/** @return true|WP_Error */
	private static function run_custom_code_conditions_repair_batches( ?float $time_budget ) {
		$start  = microtime( true );
		$budget = null !== $time_budget ? $time_budget : self::migration_time_budget();
		$cursor = (int) get_option( self::CUSTOM_CODE_CONDITIONS_REPAIR_CURSOR_OPTION, 0 );

		do {
			$snippet_ids = self::custom_code_conditions_batch( $cursor, self::CUSTOM_CODE_REPAIR_BATCH_SIZE );
			if ( is_wp_error( $snippet_ids ) ) {
				return $snippet_ids;
			}
			if ( empty( $snippet_ids ) ) {
				return self::finish_custom_code_conditions_repair();
			}

			if ( function_exists( '_prime_post_caches' ) ) {
				_prime_post_caches( array_map( 'intval', $snippet_ids ), false, false );
			}
			foreach ( $snippet_ids as $post_id ) {
				$result = self::repair_one_custom_code_conditions( (int) $post_id );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$cursor = (int) $post_id;
				if ( $budget > 0 && microtime( true ) - $start >= $budget ) {
					update_option( self::CUSTOM_CODE_CONDITIONS_REPAIR_CURSOR_OPTION, $cursor, false );
					return true;
				}
			}
			update_option( self::CUSTOM_CODE_CONDITIONS_REPAIR_CURSOR_OPTION, $cursor, false );

			if ( count( $snippet_ids ) < self::CUSTOM_CODE_REPAIR_BATCH_SIZE ) {
				return self::finish_custom_code_conditions_repair();
			}
		} while ( ( microtime( true ) - $start ) <= $budget );

		return true;
	}

	/** @return true|WP_Error */
	private static function finish_custom_code_conditions_repair() {
		$unrepaired = self::has_unrepaired_custom_code_conditions();
		if ( is_wp_error( $unrepaired ) ) {
			return $unrepaired;
		}
		if ( $unrepaired ) {
			update_option( self::CUSTOM_CODE_CONDITIONS_REPAIR_CURSOR_OPTION, 0, false );
			return true;
		}
		delete_option( self::CUSTOM_CODE_CONDITIONS_REPAIR_CURSOR_OPTION );
		update_option( 'mcpwp_custom_code_conditions_repaired', 1, false );
		update_option( self::CUSTOM_CODE_CACHE_BACKFILL_DONE_OPTION, 1, false );
		update_option( self::CUSTOM_CODE_CACHE_BACKFILL_STATE_OPTION, array(
			'marker' => (string) get_option( Mcpwp_Custom_Code_Conditions::REPORT_MARKER, '' ),
			'completed_at' => time(),
		), false );
		return true;
	}

	/** @return int[]|WP_Error */
	private static function custom_code_conditions_batch( $cursor, $limit ) {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_col' ) ) {
			return new WP_Error( 'conditions_repair_failed', __( 'Custom Code conditions could not be repaired.', 'mcpwp' ) );
		}

		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- keyset pagination over core tables.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND ID > %d ORDER BY ID ASC LIMIT %d",
				'elementor_snippet',
				(int) $cursor,
				(int) $limit
			)
		);

		if ( ! empty( $wpdb->last_error ) ) {
			return new WP_Error( 'conditions_repair_failed', __( 'Custom Code conditions could not be repaired.', 'mcpwp' ) );
		}

		return is_array( $ids ) ? array_map( 'intval', $ids ) : new WP_Error( 'conditions_repair_failed', __( 'Custom Code conditions could not be repaired.', 'mcpwp' ) );
	}

	/** @return true|WP_Error */
	private static function repair_one_custom_code_conditions( $post_id ) {
		$inspection = Mcpwp_Custom_Code_Conditions::inspect( $post_id );
		if ( is_wp_error( $inspection ) ) {
			Mcpwp_Custom_Code_Conditions::quarantine_inspection( $post_id, $inspection );
			self::record_custom_code_cache_repair_result( $post_id, true );
			return true;
		}
		$raw     = $inspection['raw'];
		$strings = $inspection['conditions'];
		if ( $raw !== $strings ) {
			// A concurrent editor wins. Re-read before deciding whether this
			// record can enter the cache; never reject another snippet's save.
			update_post_meta( $post_id, '_elementor_conditions', wp_slash( $strings ), $raw );
			Mcpwp_Custom_Code_Conditions::invalidate_cache_view( $post_id );
			$current = Mcpwp_Custom_Code_Conditions::inspect( $post_id );
			if ( is_wp_error( $current ) ) {
				Mcpwp_Custom_Code_Conditions::quarantine_inspection( $post_id, $current );
				return true;
			}
			$strings = $current['conditions'];
		}
		Mcpwp_Custom_Code_Conditions::clear_quarantine( $post_id );
		self::repair_custom_code_conditions_cache_entry( $post_id );
		return true;
	}

	/** @return true */
	private static function repair_custom_code_conditions_cache_entry( $post_id ) {
		if ( 'publish' !== get_post_status( $post_id ) ) {
			self::record_custom_code_cache_repair_result( $post_id, true );
			return true;
		}
		require_once __DIR__ . '/core/class-mcpwp-encryption.php';
		require_once __DIR__ . '/core/class-mcpwp-custom-code-cache-entry.php';
		$inspection = Mcpwp_Custom_Code_Conditions::inspect( $post_id );
		if ( is_wp_error( $inspection ) ) {
			return true;
		}
		$strings = $inspection['conditions'];
		$failures = self::custom_code_cache_failure_state();
		if ( isset( $failures[ $post_id ] ) && (int) $failures[ $post_id ]['next_retry'] > time() ) {
			return true;
		}
		$location = get_post_meta( $post_id, '_elementor_location', true );
		$location = is_string( $location ) && '' !== $location ? $location : 'elementor_head';
		$result = Mcpwp_Custom_Code_Cache_Entry::sync( $post_id, $location, $strings );
		self::record_custom_code_cache_repair_result( $post_id, $result );
		return true;
	}

	/** Retain failed IDs for a later bounded admin retry without aborting peers. */
	private static function record_custom_code_cache_repair_result( $post_id, $result ) {
		$post_id = (int) $post_id;
		$failed  = array_values( array_unique( array_map( 'intval', (array) get_option( self::CUSTOM_CODE_CACHE_REPAIR_FAILED_OPTION, array() ) ) ) );
		$states  = self::custom_code_cache_failure_state();
		if ( is_wp_error( $result ) ) {
			$prior    = isset( $states[ $post_id ]['attempts'] ) ? (int) $states[ $post_id ]['attempts'] : 0;
			$attempts = $prior + 1;
			$parked   = $attempts >= self::CUSTOM_CODE_CACHE_REPAIR_MAX_ATTEMPTS;
			$delay    = $parked
				? Mcpwp_Custom_Code_Conditions::REPORT_TTL
				: min( Mcpwp_Custom_Code_Conditions::REPORT_TTL, 5 * ( 2 ** max( 0, $attempts - 1 ) ) );
			$states[ $post_id ] = array(
				'attempts'   => $attempts,
				'next_retry' => time() + $delay,
				'parked'     => $parked,
			);
			update_option( self::CUSTOM_CODE_CACHE_REPAIR_FAILURE_STATE_OPTION, $states, false );
			if ( ! in_array( $post_id, $failed, true ) ) {
				$failed[] = $post_id;
				sort( $failed );
				update_option( self::CUSTOM_CODE_CACHE_REPAIR_FAILED_OPTION, $failed, false );
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'MCPWP Custom Code cache repair failed for snippet ' . (int) $post_id . ': ' . $result->get_error_code() );
			}
		} elseif ( isset( $states[ $post_id ] ) || in_array( $post_id, $failed, true ) ) {
			unset( $states[ $post_id ] );
			update_option( self::CUSTOM_CODE_CACHE_REPAIR_FAILURE_STATE_OPTION, $states, false );
			self::remove_custom_code_cache_failure_id( $post_id );
		}
	}

	/** @return array<int,array{attempts:int,next_retry:int,parked:bool}> */
	private static function custom_code_cache_failure_state() {
		$stored = get_option( self::CUSTOM_CODE_CACHE_REPAIR_FAILURE_STATE_OPTION, array() );
		$out    = array();
		foreach ( is_array( $stored ) ? $stored : array() as $post_id => $state ) {
			if ( is_array( $state ) && (int) $post_id > 0 ) {
				$out[ (int) $post_id ] = array(
					'attempts'   => max( 0, (int) ( $state['attempts'] ?? 0 ) ),
					'next_retry' => max( 0, (int) ( $state['next_retry'] ?? 0 ) ),
					'parked'     => ! empty( $state['parked'] ),
				);
			}
		}
		return $out;
	}

	private static function remove_custom_code_cache_failure_id( $post_id ) {
		$failed = array_values( array_diff( array_map( 'intval', (array) get_option( self::CUSTOM_CODE_CACHE_REPAIR_FAILED_OPTION, array() ) ), array( (int) $post_id ) ) );
		update_option( self::CUSTOM_CODE_CACHE_REPAIR_FAILED_OPTION, $failed, false );
	}

	/** Retry only failures whose backoff window has elapsed. */
	public static function retry_due_custom_code_cache_failures( ?float $time_budget = null ) {
		$budget = null !== $time_budget ? $time_budget : self::migration_time_budget();
		self::retry_due_custom_code_cache_failures_in_budget( microtime( true ), $budget );
	}

	private static function retry_due_custom_code_cache_failures_in_budget( $start, $budget ) {
		foreach ( self::custom_code_cache_failure_state() as $post_id => $state ) {
			if ( (int) $state['next_retry'] > time() ) {
				continue;
			}
			self::repair_one_custom_code_conditions( (int) $post_id );
			if ( $budget > 0 && microtime( true ) - $start >= $budget ) {
				return;
			}
		}
	}

	/** @return bool|WP_Error */
	private static function has_unrepaired_custom_code_conditions() {
		$cursor = 0;
		do {
			$snippet_ids = self::custom_code_conditions_batch( $cursor, self::CUSTOM_CODE_REPAIR_VERIFY_LIMIT );
			if ( is_wp_error( $snippet_ids ) ) {
				return $snippet_ids;
			}
			foreach ( $snippet_ids as $post_id ) {
				$inspection = Mcpwp_Custom_Code_Conditions::inspect( (int) $post_id );
				if ( is_wp_error( $inspection ) ) {
					Mcpwp_Custom_Code_Conditions::quarantine_inspection( (int) $post_id, $inspection );
				}
				$cursor = (int) $post_id;
			}
		} while ( count( $snippet_ids ) === self::CUSTOM_CODE_REPAIR_VERIFY_LIMIT );

		return false;
	}

	/**
	 * Repair Elementor Custom Code snippets written under GH #871, once.
	 *
	 * Before this fix, `wp_create_elementor_custom_code` / `_update_elementor_
	 * custom_code` stored our public location value ('head' / 'body_start' /
	 * 'body_end') literally in `_elementor_location`, instead of Elementor
	 * Pro's real internal value ('elementor_head' / 'elementor_body_start' /
	 * 'elementor_body_end'). They also never wrote `_elementor_code` —
	 * Elementor Pro's own renderer (ElementorPro\Modules\CustomCode\Document::
	 * print_content()) reads that meta key, not `post_content` — and never
	 * wrote `_elementor_priority`, which Elementor's location query needs
	 * present (not just correct) to include the post at all. Any snippet
	 * created before this release is silently dead on arrival regardless of
	 * how correct everything else about it looks.
	 *
	 * This repairs every `elementor_snippet` post in place:
	 *  - `_elementor_location` in {head, body_start, body_end} is rewritten to
	 *    the matching Elementor internal value. A location already holding an
	 *    internal value, or something unrecognized, is left untouched — we
	 *    only fix the one specific mistake this bug made.
	 *  - `_elementor_code` is backfilled from `post_content` when absent, so a
	 *    pre-fix snippet's actual code (which round-trips fine through our own
	 *    API today) becomes visible to Elementor's renderer too.
	 *  - `_elementor_priority` is backfilled to 1 (this class's own default)
	 *    when absent.
	 *
	 * BATCHED AND CHECKPOINTED (#875 review finding W3). The original version
	 * of this method did `get_posts( posts_per_page => -1 )` and looped every
	 * `elementor_snippet` in one pass, on `plugins_loaded`, with up to 3 meta
	 * reads/writes plus a `get_post()` per row, writing the completion flag
	 * only after the whole loop finished. A site with enough snippets to
	 * exceed `max_execution_time` timed out mid-loop, never set the flag, and
	 * re-ran the entire scan from post 0 on EVERY subsequent request — a
	 * permanent slow/failing-request loop for that install.
	 *
	 * Follows the time-budgeted pattern already established by
	 * Mcpwp_Signals::compute() / request_time_budget() rather than inventing
	 * a new one: a bounded time budget (5-15s, derived from
	 * max_execution_time — see migration_time_budget()), at least one unit of
	 * work always completed per call regardless of budget (there, one signal
	 * type; here, one batch of posts), and a persisted marker so a call that
	 * runs out of time resumes instead of restarting. The one addition beyond
	 * that pattern is the cursor itself: Signals' units (signal types) are
	 * independent and stateless between calls, but a snippet scan needs an
	 * explicit position to resume from, since "everything already computed"
	 * isn't a concept that applies to one big ID range.
	 *
	 * The cursor is the highest post ID repaired so far
	 * (CUSTOM_CODE_REPAIR_CURSOR_OPTION), scanned in ascending ID order via a
	 * direct `WHERE ID > $cursor` query — not `get_posts()` with an offset,
	 * which would re-scan and skip already-visited rows on every batch as the
	 * table grows, and would silently drop or repeat rows if a snippet is
	 * created or trashed mid-migration. An ID-keyset cursor is immune to
	 * that: new snippets always get higher IDs than the cursor (so they're
	 * naturally picked up before completion, or by definition don't need
	 * repair if created after this ships), and a deletion behind the cursor
	 * changes nothing about what's ahead of it.
	 *
	 * Idempotent at every level: a post already holding an internal location
	 * value, non-empty `_elementor_code`, or a set `_elementor_priority` is
	 * left alone, so re-entering this method after a partial run (or even
	 * after completion, in principle) never double-applies a fix. Safe to
	 * run on a site with no Elementor Pro / no snippets: the first query
	 * simply returns nothing and the migration completes immediately.
	 *
	 * @param float|null $time_budget Override for the per-call time budget,
	 *                                in seconds. Tests use this to force an
	 *                                interrupted run deterministically
	 *                                instead of waiting on a real timeout.
	 *                                Production callers omit it and get
	 *                                migration_time_budget().
	 */
	public static function repair_custom_code_locations( ?float $time_budget = null ) {
		if ( get_option( 'mcpwp_custom_code_locations_repaired' ) ) {
			return;
		}

		$lock_token = self::acquire_custom_code_repair_lock();
		if ( false === $lock_token ) {
			// Another call already owns this run. Nothing to do this
			// request — the holder (or, if it died, the next call once the
			// lock's TTL lapses) makes progress instead.
			return;
		}

		try {
			self::run_custom_code_repair_batches( $time_budget );
		} finally {
			self::release_custom_code_repair_lock( $lock_token );
		}
	}

	/**
	 * Attempt to acquire the repair mutex. See CUSTOM_CODE_REPAIR_LOCK_OPTION
	 * above for the atomicity argument.
	 *
	 * @return string|false The expiry token to pass to release_...(), or
	 *                       false when another call already holds the lock.
	 */
	private static function acquire_custom_code_repair_lock() {
		return self::acquire_migration_lock( self::CUSTOM_CODE_REPAIR_LOCK_OPTION, self::CUSTOM_CODE_REPAIR_LOCK_TTL );
	}

	/**
	 * Release the repair mutex acquired by acquire_custom_code_repair_lock().
	 *
	 * @param string $token The expiry value that call returned.
	 */
	private static function release_custom_code_repair_lock( $token ) {
		self::release_migration_lock( self::CUSTOM_CODE_REPAIR_LOCK_OPTION, $token );
	}

	/**
	 * Generic non-blocking CAS mutex over a wp_options row (#1031 round 3,
	 * adversarial review WARN-1) — the same atomic-claim primitive
	 * acquire_custom_code_repair_lock() pioneered (see
	 * CUSTOM_CODE_REPAIR_LOCK_OPTION's own docblock for the atomicity
	 * argument: a plain INSERT against the UNIQUE key on option_name,
	 * stale-lock steal via a compare-and-swap UPDATE, release via a
	 * compare-and-swap DELETE), generalised so a SECOND, unrelated one-time
	 * migration pair (the action-log column add + its backfill) does not
	 * need its own copy of the same ~80 lines. That method and its
	 * `release_...()` twin are now thin wrappers over this.
	 *
	 * Non-blocking: a caller that cannot acquire returns immediately rather
	 * than waiting or retrying inline, and picks the work up on a later
	 * request instead — see each call site's own "another call already owns
	 * this" comment.
	 *
	 * @param string $lock_option wp_options row name — one per protected
	 *                            operation, never shared between two
	 *                            unrelated migrations, so one's hold time
	 *                            cannot block the other's.
	 * @param int    $ttl         Seconds a holder is trusted before another
	 *                            caller may steal it as abandoned. Pick this
	 *                            comfortably above the protected operation's
	 *                            own worst-case run time (see each caller).
	 * @return string|false The expiry token to pass to release_migration_lock(),
	 *                       or false when another caller already holds it.
	 */
	private static function acquire_migration_lock( $lock_option, $ttl ) {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'insert' ) ) {
			// No $wpdb to arbitrate with — refuse rather than run unlocked.
			return false;
		}

		$now        = time();
		$expires_at = (string) ( $now + (int) $ttl );

		// A plain INSERT against the UNIQUE KEY on option_name is the atomic
		// claim primitive — the same one Mcpwp_Idempotency_Claim::acquire()
		// uses for #802, applied directly here via $wpdb rather than through
		// that class.
		$suppressed = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;

		$inserted = $wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => $lock_option,
				'option_value' => $expires_at,
				'autoload'     => 'no',
			),
			array( '%s', '%s', '%s' )
		);

		if ( null !== $suppressed ) {
			$wpdb->suppress_errors( $suppressed );
		}

		if ( 1 === (int) $inserted ) {
			return $expires_at;
		}

		// Someone else holds the row. Only take it over if its expiry has
		// already passed, and only via a compare-and-swap update so two
		// callers racing to steal the same stale lock cannot both succeed.
		if ( ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'update' ) ) {
			return false;
		}

		$current = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				$lock_option
			)
		);

		if ( null === $current || '' === $current || (int) $current >= $now ) {
			return false;
		}

		$stolen = $wpdb->update(
			$wpdb->options,
			array( 'option_value' => $expires_at ),
			array(
				'option_name'  => $lock_option,
				'option_value' => $current,
			),
			array( '%s' ),
			array( '%s', '%s' )
		);

		return 1 === (int) $stolen ? $expires_at : false;
	}

	/**
	 * Release a mutex acquired by acquire_migration_lock().
	 *
	 * @param string $lock_option Same option name passed to acquire.
	 * @param string $token       The expiry value that acquire call returned.
	 */
	private static function release_migration_lock( $lock_option, $token ) {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'delete' ) ) {
			return;
		}

		// CAS delete: only remove the row if it still holds the value THIS
		// call wrote. If our own run overran its TTL and another call
		// already stole the lock, that row belongs to them now and must not
		// be deleted out from under them.
		$wpdb->delete(
			$wpdb->options,
			array(
				'option_name'  => $lock_option,
				'option_value' => $token,
			),
			array( '%s', '%s' )
		);
	}

	/**
	 * Whether any elementor_snippet post still shows the unrepaired
	 * signature repair_custom_code_locations() fixes, checked directly
	 * against post/postmeta content rather than through the (potentially
	 * corrupted) cursor.
	 *
	 * A cursor corrupted UPWARD — set above the true max elementor_snippet
	 * ID by something outside this migration — makes `WHERE ID > $cursor`
	 * return zero rows on the very first call, before a single row has ever
	 * been repaired, which looks identical to genuine completion (RC review
	 * finding #3). Comparing the cursor to MAX(ID) cannot catch this either:
	 * an inflated cursor is, by construction, >= any real ID, so that
	 * comparison agrees with the corruption. Proving completion means
	 * looking at content, not position.
	 *
	 * This runs at most once per site, ever: only the call about to flip
	 * the completion flag reaches it, and every call after that returns at
	 * the top of repair_custom_code_locations() before getting here. It is
	 * also not a full `wp_posts` table scan: it reuses the exact same
	 * keyset query the batch loop above already runs — `post_type` is
	 * indexed, so it is bounded to this site's (typically small)
	 * `elementor_snippet` count, not the whole table — just started at 0
	 * with CUSTOM_CODE_REPAIR_VERIFY_LIMIT instead of the cursor and the
	 * per-request batch size, since proving completion means looking at
	 * every real row regardless of what the cursor claims.
	 *
	 * @return bool
	 */
	private static function has_unrepaired_custom_code_snippets(): bool {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_col' ) ) {
			// No way to verify — never claim completion on an answer that
			// could not actually be checked.
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- same shape/reasoning as the batch query above.
		$snippet_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND ID > %d ORDER BY ID ASC LIMIT %d",
				'elementor_snippet',
				0,
				self::CUSTOM_CODE_REPAIR_VERIFY_LIMIT
			)
		);

		foreach ( $snippet_ids as $post_id ) {
			$post_id  = (int) $post_id;
			$location = get_post_meta( $post_id, '_elementor_location', true );

			if ( in_array( $location, array( 'head', 'body_start', 'body_end' ), true ) ) {
				return true;
			}
			if ( '' === (string) get_post_meta( $post_id, '_elementor_code', true ) ) {
				return true;
			}
			if ( '' === (string) get_post_meta( $post_id, '_elementor_priority', true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The actual batched scan/repair loop, run only while
	 * repair_custom_code_locations() holds CUSTOM_CODE_REPAIR_LOCK_OPTION.
	 *
	 * @param float|null $time_budget Same meaning as on the public method.
	 */
	private static function run_custom_code_repair_batches( ?float $time_budget ) {
		global $wpdb;

		$location_map = array(
			'head'       => 'elementor_head',
			'body_start' => 'elementor_body_start',
			'body_end'   => 'elementor_body_end',
		);

		$start  = microtime( true );
		$budget = null !== $time_budget ? $time_budget : self::migration_time_budget();
		$cursor = (int) get_option( self::CUSTOM_CODE_REPAIR_CURSOR_OPTION, 0 );

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- uses only core $wpdb table names; values are all placeholder-bound. Keyset (ID > cursor) pagination has no get_posts()/WP_Query primitive; see docblock above for why offset pagination is the wrong tool here.
			$snippet_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND ID > %d ORDER BY ID ASC LIMIT %d",
					'elementor_snippet',
					$cursor,
					self::CUSTOM_CODE_REPAIR_BATCH_SIZE
				)
			);

			if ( empty( $snippet_ids ) ) {
				// Scan reached the end of the table (or there was never
				// anything to repair) — or the cursor is corrupted upward
				// and only LOOKS like it did (RC review finding #3). Prove
				// it against content before trusting it; see
				// has_unrepaired_custom_code_snippets() for why.
				if ( self::has_unrepaired_custom_code_snippets() ) {
					// The cursor lied. Reset it to a value we can trust and
					// let the next call rescan for real; every fix above is
					// idempotent, so rescanning already-correct rows costs
					// a no-op update, not a double-apply.
					update_option( self::CUSTOM_CODE_REPAIR_CURSOR_OPTION, 0, false );
					return;
				}

				delete_option( self::CUSTOM_CODE_REPAIR_CURSOR_OPTION );
				update_option( 'mcpwp_custom_code_locations_repaired', 1, false );
				return;
			}

			foreach ( $snippet_ids as $post_id ) {
				$post_id = (int) $post_id;

				$location = get_post_meta( $post_id, '_elementor_location', true );
				if ( isset( $location_map[ $location ] ) ) {
					update_post_meta( $post_id, '_elementor_location', $location_map[ $location ] );
				}

				$code = get_post_meta( $post_id, '_elementor_code', true );
				if ( '' === (string) $code ) {
					$post = get_post( $post_id );
					if ( $post && '' !== (string) $post->post_content ) {
						update_post_meta( $post_id, '_elementor_code', $post->post_content );
					}
				}

				$priority = get_post_meta( $post_id, '_elementor_priority', true );
				if ( '' === (string) $priority ) {
					update_post_meta( $post_id, '_elementor_priority', 1 );
				}

				$cursor = $post_id;
			}

			// Checkpoint after every batch, not only at the very end — this
			// is the durable progress marker a re-entrant plugins_loaded call
			// resumes from if the request ends (time limit, worker recycle,
			// fatal elsewhere in the request) before the loop below decides
			// to stop on its own.
			update_option( self::CUSTOM_CODE_REPAIR_CURSOR_OPTION, $cursor, false );

			if ( count( $snippet_ids ) < self::CUSTOM_CODE_REPAIR_BATCH_SIZE ) {
				// That was the last (partial) page.
				delete_option( self::CUSTOM_CODE_REPAIR_CURSOR_OPTION );
				update_option( 'mcpwp_custom_code_locations_repaired', 1, false );
				return;
			}
		} while ( ( microtime( true ) - $start ) <= $budget );

		// Budget exhausted mid-scan. The completion flag is still unset and
		// the cursor is already checkpointed above, so the next
		// plugins_loaded call — ordinarily the very next request, since
		// nothing here throws — resumes at $cursor instead of rescanning from
		// the start. The budget is only checked BETWEEN batches (never mid-
		// batch), so a call always completes at least one full batch no
		// matter how small $time_budget is: a site can't get stuck making
		// zero progress.
	}

	/**
	 * Seconds of work a one-time plugins_loaded migration may safely spend
	 * inside a web request before checkpointing and returning control.
	 *
	 * Same bounds and reasoning as Mcpwp_Signals::request_time_budget()
	 * (5-15s, derived from max_execution_time with headroom for the rest of
	 * the request) — duplicated here rather than called directly, because
	 * this file is require_once'd straight from mcpwp.php's plugins_loaded
	 * handler before the rest of the plugin, Mcpwp_Signals included, is
	 * guaranteed to have been loaded yet.
	 */
	private static function migration_time_budget(): float {
		$max = (int) ini_get( 'max_execution_time' );
		if ( $max <= 0 ) {
			return 15.0;
		}
		return max( 5.0, min( 15.0, (float) $max - 5.0 ) );
	}

	/**
	 * Create API role and user.
	 */
	private static function create_api_role_and_user() {
		// Add Role — principle of least privilege: no manage_options or edit_theme_options.
		//
		// edit_published_posts / edit_published_pages (#861b): agent writes to
		// ALREADY-PUBLISHED posts/pages are intended behaviour (owner decision,
		// 2026-09-18) — the plugin's whole value proposition is an agent that can
		// edit a live site, not just drafts. Without these, WordPress's
		// map_meta_cap() denies edit_post on a published item even though
		// edit_pages/edit_others_pages are granted, so
		// \Elementor\Core\Base\Document::save() returns bare bool(false)
		// (is_editable_by_current_user() fails) with no exception and no
		// WP_Error. MCPWP's raw-meta fallback then force-wrote _elementor_data
		// via update_post_meta() — which performs no capability check at all —
		// so the content landed anyway, just outside Elementor's real pipeline:
		// no revision, no CSS regeneration, none of Document::save()'s
		// after_save hooks (#861). That was a silent bypass of a denial the
		// role itself no longer intends, not a safety boundary. Granting the
		// capability openly means the write goes through the real pipeline
		// instead of routing around a decline. An already-installed site gets
		// the same two caps backfilled once by
		// grant_agent_published_page_caps() below, since add_role() is a no-op
		// on a role that already exists.
		// mcpwp_manage_settings is deliberately NOT in this array (GH #669,
		// backport of the wp.org audit's finding #4). Every API key — whatever
		// scope it carries — authenticates as this SAME shared
		// mcpwp_api_agent WordPress user (see set_api_user_context() and the
		// #874 docblock on get_required_scope_for_request() in
		// trait-mcpwp-api-auth.php); a WordPress capability granted to this
		// role is therefore held by every key regardless of its own declared
		// scope. Granting mcpwp_manage_settings here made
		// current_user_can('mcpwp_manage_settings') trivially true for ANY
		// authenticated key, collapsing Mcpwp_REST_Site_Updates::
		// can_manage_api_keys() into a no-op — only the key's own admin scope
		// actually stood between an agent-bound key and /api-keys,
		// /rate-limit, and the plugin self-update trigger. The capability
		// stays administrator-only (see the get_role('administrator') grant
		// below); can_manage_api_keys() now checks the key's own scope
		// directly for an API-key-authenticated request instead of relying on
		// this capability, so an admin-scoped key still reaches those routes
		// — see that method's docblock.
		add_role( 'mcpwp_api_agent', 'MCPWP API Agent', array(
			'read'               => true,
			'edit_posts'         => true,
			'edit_pages'         => true,
			'edit_others_posts'  => true,
			'edit_others_pages'  => true,
			'edit_published_posts' => true,
			'edit_published_pages' => true,
			'publish_posts'      => true,
			'publish_pages'      => true,
			'delete_posts'       => true,
			'delete_pages'       => true,
			'delete_others_posts' => true,
			'delete_others_pages' => true,
			'upload_files'       => true,
			'list_users'         => true,
			// Custom MCPWP capability (NOT admin-level; mcpwp_manage_settings
			// deliberately excluded — see comment above, GH #669).
			'mcpwp_manage_webhooks' => true,
		) );

		// Update existing role if it already has manage_options (migration).
		$role = get_role( 'mcpwp_api_agent' );
		if ( $role && $role->has_cap( 'manage_options' ) ) {
			$role->remove_cap( 'manage_options' );
			$role->remove_cap( 'edit_theme_options' );
			$role->add_cap( 'delete_others_posts' );
			$role->add_cap( 'delete_others_pages' );
			// mcpwp_manage_settings intentionally NOT re-added here (GH #669) —
			// see the comment above add_role() above.
			$role->add_cap( 'mcpwp_manage_webhooks' );
		}

		// mcpwp_manage_settings is administrator-only (GH #669). WordPress's
		// built-in 'administrator' role is never created by add_role() and
		// never receives arbitrary plugin capabilities automatically, so it
		// must be granted explicitly, the same way the agent role's own
		// custom capabilities are. This is the site's own top role (not a
		// capability handed to an untrusted third party), so — unlike the
		// removal above — it is safe to (re)ensure on every activation rather
		// than one-time-gated; add_cap() is a harmless no-op once granted.
		$admin_role = get_role( 'administrator' );
		if ( $admin_role && ! $admin_role->has_cap( 'mcpwp_manage_settings' ) ) {
			$admin_role->add_cap( 'mcpwp_manage_settings' );
		}

		// Create User
		$user = get_user_by( 'login', 'mcpwp_bot' );
		if ( ! $user ) {
			wp_insert_user( array(
				'user_login'   => 'mcpwp_bot',
				'user_pass'    => wp_generate_password( 64 ),
				'role'         => 'mcpwp_api_agent',
				'display_name' => 'MCPWP',
				'description'  => 'Service account for MCPWP API',
			) );
		}
	}

	/**
	 * The key part of activation, split out so it can run on its own: mint a
	 * first key on a genuinely fresh site, and make sure the scoped store exists.
	 *
	 * Runs on the request path at every plugin update (mcpwp.php calls
	 * activate() whenever mcpwp_db_version is behind), so it must never write on
	 * the strength of a store it could not read (#1096).
	 *
	 * @return void
	 */
	public static function ensure_api_keys() {
		// Generate a first API key only on a genuinely fresh site (both key
		// stores empty). On any other site a minted legacy key would be refused
		// at auth (#1097) and shown to the admin as a key that never works.
		require_once __DIR__ . '/core/class-mcpwp-key-state.php';
		if ( Mcpwp_Key_State::is_fresh_site() ) {
			$api_key = self::generate_api_key();
			update_option( 'mcpwp_api_key', wp_hash_password( $api_key ) );
			// Store plain text temporarily so admin can see it on first visit
			set_transient( 'mcpwp_new_api_key', $api_key, HOUR_IN_SECONDS );
			// Flag first activation for welcome flow
			update_option( 'mcpwp_first_activation', true );
		}

		// Ensure scoped API key store exists for key-level revocation/scopes.
		self::ensure_scoped_api_keys();
	}

	/**
	 * Generate a secure API key.
	 *
	 * @return string API key.
	 */
	private static function generate_api_key() {
		return 'mcpwp_' . bin2hex( random_bytes( 24 ) );
	}

	/**
	 * Ensure scoped API key option exists and migrate legacy single-key storage.
	 */
	private static function ensure_scoped_api_keys() {
		require_once __DIR__ . '/core/class-mcpwp-key-state.php';
		if ( Mcpwp_Key_State::store_is_unreadable() ) {
			// #1096: a store that exists but cannot be read is NOT an empty one.
			// Overwriting it would drop every revocation record and let the legacy
			// key and the spai_ copies of revoked keys authenticate again. Leave
			// it exactly as it is; Mcpwp_Key_Store_Health reports it to an admin.
			return;
		}

		$legacy_key = get_option( 'mcpwp_api_key' );
		if ( empty( $legacy_key ) ) {
			return;
		}

		$legacy_hash = self::looks_like_password_hash( $legacy_key ) ? (string) $legacy_key : wp_hash_password( (string) $legacy_key );

		$key_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'mcpwp_', true );
		$record = array(
			'id'           => sanitize_key( (string) $key_id ),
			'label'        => 'Primary API Key',
			'hash'         => $legacy_hash,
			'scopes'       => array( 'read', 'write', 'admin' ),
			'created_at'   => current_time( 'mysql' ),
			'last_used_at' => null,
			'revoked_at'   => null,
		);

		// Through the key-store write path, on the store as it is under the lock
		// (this runs on the request path at every plugin update).
		if ( ! class_exists( 'Mcpwp_Key_Store' ) ) {
			require_once __DIR__ . '/core/class-mcpwp-key-store.php';
		}
		$stored = Mcpwp_Key_Store::mutate(
			static function ( array $keys ) use ( $record ) {
				return empty( $keys ) ? array( array( $record ), true ) : array( null, false );
			},
			false
		);

		// 'unavailable' (no database to arbitrate with) writes nothing and fails
		// closed: the legacy key keeps working through the request-path migration,
		// which goes through the same write path and retries on its own.
		if ( $stored['written'] && true === $stored['result'] && $legacy_hash !== $legacy_key ) {
			update_option( 'mcpwp_api_key', $legacy_hash );
		}
	}

	/**
	 * Ensure activity-log cleanup cron is scheduled.
	 */
	private static function schedule_log_cleanup() {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
			return;
		}

		if ( wp_next_scheduled( 'mcpwp_cleanup_logs' ) ) {
			return;
		}

		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'mcpwp_cleanup_logs' );
	}

	/**
	 * Check whether a stored key value appears to be a password hash.
	 *
	 * @param string $value Stored key value.
	 * @return bool True when value looks hashed.
	 */
	private static function looks_like_password_hash( $value ) {
		require_once __DIR__ . '/core/class-mcpwp-key-state.php';
		return Mcpwp_Key_State::is_password_hash_shaped( (string) $value ); // The one predicate (#1178).
	}

	/**
	 * Create database tables.
	 */
	private static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		// Activity log table
		$table_name = $wpdb->prefix . 'mcpwp_activity_log';
		$sql = "CREATE TABLE IF NOT EXISTS $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			action varchar(100) NOT NULL,
			endpoint varchar(255) NOT NULL,
			method varchar(10) NOT NULL,
			status_code int(3) DEFAULT NULL,
			ip_address varchar(45) DEFAULT NULL,
			user_agent text DEFAULT NULL,
			request_data longtext DEFAULT NULL,
			response_data longtext DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY action (action),
			KEY created_at (created_at)
		) $charset_collate;";
		dbDelta( $sql );

		// Webhooks table
		$webhooks_table = $wpdb->prefix . 'mcpwp_webhooks';
		$sql_webhooks = "CREATE TABLE IF NOT EXISTS $webhooks_table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL,
			url varchar(2048) NOT NULL,
			secret varchar(255) DEFAULT NULL,
			events text NOT NULL,
			status varchar(20) DEFAULT 'active',
			retry_count int(11) DEFAULT 0,
			last_triggered datetime DEFAULT NULL,
			last_status varchar(50) DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY status (status)
		) $charset_collate;";
		dbDelta( $sql_webhooks );

		// Webhook logs table
		$logs_table = $wpdb->prefix . 'mcpwp_webhook_logs';
		$sql_logs = "CREATE TABLE IF NOT EXISTS $logs_table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			webhook_id bigint(20) unsigned NOT NULL,
			event varchar(100) NOT NULL,
			payload longtext NOT NULL,
			response_code int(11) DEFAULT NULL,
			response_body text DEFAULT NULL,
			duration float DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY webhook_id (webhook_id),
			KEY created_at (created_at)
		) $charset_collate;";
		dbDelta( $sql_logs );

		// Feedback table
		$feedback_table = $wpdb->prefix . 'mcpwp_feedback';
		$sql_feedback = "CREATE TABLE IF NOT EXISTS $feedback_table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(20) NOT NULL DEFAULT 'feedback',
			title varchar(255) NOT NULL,
			description text NOT NULL,
			agent varchar(100) DEFAULT '',
			priority varchar(20) DEFAULT 'medium',
			status varchar(20) DEFAULT 'open',
			github_issue_url varchar(500) DEFAULT '',
			meta longtext DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY (id),
			KEY type_status (type, status)
		) $charset_collate;";
		dbDelta( $sql_feedback );

		// Action log table — immutable per-tool audit trail (EU AI Act compliance)
		$action_log_table = $wpdb->prefix . 'mcpwp_action_log';
		dbDelta( self::action_log_create_sql( $action_log_table, $charset_collate ) );

		// Approvals table (#1042).
		dbDelta( self::approvals_create_sql( $wpdb->prefix . 'mcpwp_approvals', $charset_collate ) );

		// OAuth token table (#1101 PR-3b) — on its own schema option; this
		// file cannot assume the plugin's require order (see top of file).
		if ( ! class_exists( 'Mcpwp_OAuth_Revocation' ) ) {
			require_once __DIR__ . '/core/class-mcpwp-oauth-revocation.php';
		}
		if ( ! class_exists( 'Mcpwp_OAuth_Token_Store' ) ) {
			require_once __DIR__ . '/core/class-mcpwp-oauth-token-store.php';
		}
		Mcpwp_OAuth_Token_Store::ensure_table();

		self::ensure_graph_tables();
	}

	/**
	 * The action-log table's CREATE TABLE SQL — factored out of create_tables()
	 * so ensure_action_log_resource_post_type_column() (#1031 round 2) can
	 * dbDelta() the SAME definition on a site that will not re-enter
	 * create_tables() until its next version bump (see that method's own
	 * docblock for why it runs on its own flag instead of waiting for one).
	 * A single copy means the two can never drift into two different ideas
	 * of what this table looks like.
	 *
	 * `resource_post_type` and `snapshot_skipped` (#1031 round 2, DESIGN
	 * items 1 and 6) are both intentionally small, cheap-to-index columns —
	 * see Mcpwp_Action_Log::list_entries()'s own docblock for why the
	 * filtered read path must never SELECT the (potentially multi-MB)
	 * before_snap/after_snap columns at all.
	 *
	 * @param string $table            Prefixed table name.
	 * @param string $charset_collate  $wpdb->get_charset_collate().
	 * @return string
	 */
	private static function action_log_create_sql( $table, $charset_collate ) {
		// No `IF NOT EXISTS` (see ensure_graph_tables()'s own docblock, W6):
		// dbDelta() diffs and ALTERs an existing table by parsing this exact
		// string, and `IF NOT EXISTS` was found to make a real MySQL server
		// silently skip the ALTER path dbDelta needed — exactly the path
		// ensure_action_log_resource_post_type_column() below depends on to
		// add a column to an ALREADY-EXISTING table outside the normal
		// version-bump activate() flow. A plain `CREATE TABLE` still errors
		// safely when the table already exists in a shape dbDelta considers
		// final; dbDelta() itself decides CREATE vs. ALTER, this string is
		// only its diffing input.
		return "CREATE TABLE $table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			log_id varchar(44) NOT NULL,
			timestamp datetime NOT NULL,
			tool_name varchar(100) NOT NULL,
			category varchar(50) NOT NULL DEFAULT '',
			api_key_id varchar(100) NOT NULL DEFAULT '',
			arguments longtext NOT NULL DEFAULT '',
			before_snap longtext DEFAULT NULL,
			after_snap longtext DEFAULT NULL,
			resource_type varchar(50) NOT NULL DEFAULT '',
			resource_id varchar(255) NOT NULL DEFAULT '',
			resource_post_type varchar(40) NOT NULL DEFAULT '',
			snapshot_skipped varchar(20) NOT NULL DEFAULT '',
			duration_ms int(11) DEFAULT NULL,
			success tinyint(1) NOT NULL DEFAULT 0,
			error_code varchar(50) NOT NULL DEFAULT '',
			rollback_supported tinyint(1) NOT NULL DEFAULT 0,
			rolled_back tinyint(1) NOT NULL DEFAULT 0,
			rolled_back_at datetime DEFAULT NULL,
			rolled_back_by varchar(44) DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY log_id (log_id),
			KEY timestamp (timestamp),
			KEY tool_name (tool_name),
			KEY resource (resource_type(50), resource_id(100)),
			KEY success (success),
			KEY category_type (category, resource_post_type)
		) $charset_collate;";
	}

	/**
	 * #1031 round 2 (adversarial review, DESIGN item 1): add
	 * resource_post_type/snapshot_skipped to an action-log table created
	 * before this column existed. Own flag, not the mcpwp_db_version
	 * compare in mcpwp.php — a site already on the current version when
	 * this ships would otherwise never re-enter create_tables() at all
	 * (same reasoning as every other "ships without a version bump"
	 * migration in this file). Idempotent: dbDelta() on an unchanged
	 * definition is a no-op, so a repeat call (or one racing another
	 * process's) costs nothing beyond the flag already being set.
	 *
	 * Locked (#1031 round 3, adversarial review WARN-1): before the flag is
	 * set, EVERY concurrent request re-enters this on plugins_loaded — a
	 * cold first dbDelta() run against a real table can take several
	 * seconds, during which every other request in flight would otherwise
	 * also call dbDelta() against the same ALTER, in parallel. A
	 * non-blocking mutex (acquire_migration_lock()) means only one request
	 * does the work; every other one simply skips this call for its own
	 * request and finds the flag already set on the next one.
	 *
	 * @return void
	 */
	public static function ensure_action_log_resource_post_type_column() {
		if ( get_option( 'mcpwp_action_log_resource_post_type_column' ) ) {
			return;
		}

		$lock_token = self::acquire_migration_lock( self::ACTION_LOG_COLUMN_LOCK_OPTION, self::ACTION_LOG_COLUMN_LOCK_TTL );
		if ( false === $lock_token ) {
			// Another request already owns this; skip — the holder (or, if
			// it died, the next call once the lock's TTL lapses) makes
			// progress instead.
			return;
		}

		try {
			global $wpdb;

			if ( ! function_exists( 'dbDelta' ) ) {
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			}

			$table = $wpdb->prefix . 'mcpwp_action_log';
			dbDelta( self::action_log_create_sql( $table, $wpdb->get_charset_collate() ) );

			update_option( 'mcpwp_action_log_resource_post_type_column', 1, false );
		} finally {
			self::release_migration_lock( self::ACTION_LOG_COLUMN_LOCK_OPTION, $lock_token );
		}
	}

	/**
	 * Lock guarding ensure_action_log_resource_post_type_column(). TTL is
	 * short — one dbDelta() ALTER on this table is fast even cold — but
	 * comfortably above a slow worst case, matching the reasoning
	 * CUSTOM_CODE_REPAIR_LOCK_TTL's own docblock gives for its ceiling.
	 */
	const ACTION_LOG_COLUMN_LOCK_OPTION = 'mcpwp_action_log_column_migration_lock';
	const ACTION_LOG_COLUMN_LOCK_TTL    = 15;

	/**
	 * Rows written before resource_post_type existed default to '' — treated
	 * by Mcpwp_Action_Log::is_entry_visible_to() as "no post-type check
	 * beyond the tool's own category" until backfilled here, so a
	 * category-restricted key can (briefly, until this finishes) see a
	 * pre-upgrade product/template entry it should not. #1031 round 2
	 * (DESIGN item 1). Batched + checkpointed, same time-budgeted,
	 * ID-keyset-cursor pattern as repair_custom_code_locations() above —
	 * see that method's docblock for why a single unbounded pass over a
	 * potentially large table is unsafe. Simpler than that migration in one
	 * respect: every write here is independently idempotent (deriving
	 * resource_post_type from data that does not change), so — unlike the
	 * snippet repair — this does not take out its own mutex; two requests
	 * racing into it at once redo, at worst, one batch's work rather than
	 * corrupting anything.
	 *
	 * Preference order per row, matching the DESIGN's own: (1) the row's
	 * OWN before_snap.post_type, when present — captured at write time, so
	 * correct even if the post has since been deleted or changed type
	 * (snapshot_post() has always stored this field); (2) a live get_post()
	 * lookup, for a row whose snapshot never carried post_type at all
	 * ('elementor' rows: snapshot_elementor()'s shape is only
	 * {post_id, elementor_data}); (3) '' — the same value every
	 * not-yet-backfilled row already has, so a row this can't resolve
	 * either way is left exactly as before.
	 *
	 * Locked (#1031 round 3, adversarial review WARN-1), same reasoning as
	 * ensure_action_log_resource_post_type_column(): without a mutex, every
	 * concurrent request re-enters this on plugins_loaded before the
	 * completion flag is set, running parallel batch scans against the same
	 * table. Non-blocking — a request that cannot acquire it skips the
	 * backfill for itself; the holder (or, if it died mid-run, the next
	 * request once the lock's TTL lapses) makes progress instead. Unlike
	 * ensure_action_log_resource_post_type_column()'s short lock, this one's
	 * TTL must clear the full per-call time budget (5-15s via
	 * migration_time_budget()), not just a fast ALTER.
	 *
	 * @param float|null $time_budget Override for the per-call time budget,
	 *                                in seconds. Tests use this to force an
	 *                                interrupted run deterministically.
	 *                                Production callers omit it.
	 * @return void
	 */
	public static function backfill_action_log_resource_post_type( ?float $time_budget = null ) {
		if ( get_option( 'mcpwp_action_log_post_type_backfilled' ) ) {
			return;
		}

		$lock_token = self::acquire_migration_lock( self::ACTION_LOG_BACKFILL_LOCK_OPTION, self::ACTION_LOG_BACKFILL_LOCK_TTL );
		if ( false === $lock_token ) {
			return;
		}

		try {
			self::run_action_log_backfill_batches( $time_budget );
		} finally {
			self::release_migration_lock( self::ACTION_LOG_BACKFILL_LOCK_OPTION, $lock_token );
		}
	}

	/**
	 * Lock guarding backfill_action_log_resource_post_type(). TTL is
	 * comfortably above migration_time_budget()'s 15s ceiling — the same
	 * margin CUSTOM_CODE_REPAIR_LOCK_TTL uses for the same reason.
	 */
	const ACTION_LOG_BACKFILL_LOCK_OPTION = 'mcpwp_action_log_backfill_lock';
	const ACTION_LOG_BACKFILL_LOCK_TTL    = 30;

	/**
	 * The actual batched scan/backfill loop, run only while
	 * backfill_action_log_resource_post_type() holds
	 * ACTION_LOG_BACKFILL_LOCK_OPTION.
	 *
	 * @param float|null $time_budget Same meaning as on the public method.
	 * @return void
	 */
	private static function run_action_log_backfill_batches( ?float $time_budget ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {
			return;
		}

		$table  = $wpdb->prefix . 'mcpwp_action_log';
		$start  = microtime( true );
		$budget = null !== $time_budget ? $time_budget : self::migration_time_budget();
		$cursor = (int) get_option( self::ACTION_LOG_BACKFILL_CURSOR_OPTION, 0 );

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- table name only; values placeholder-bound. before_snap is selected for exactly this bounded batch, never the whole table (#1031 round 2's own B1 finding is precisely what this pattern avoids).
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, resource_type, resource_id, before_snap FROM $table WHERE id > %d AND resource_post_type = '' ORDER BY id ASC LIMIT %d",
					$cursor,
					self::ACTION_LOG_BACKFILL_BATCH_SIZE
				),
				ARRAY_A
			);

			if ( empty( $rows ) ) {
				delete_option( self::ACTION_LOG_BACKFILL_CURSOR_OPTION );
				update_option( 'mcpwp_action_log_post_type_backfilled', 1, false );
				return;
			}

			foreach ( $rows as $row ) {
				$row_id        = (int) $row['id'];
				$resource_type = isset( $row['resource_type'] ) ? (string) $row['resource_type'] : '';
				$post_type     = '';

				if ( in_array( $resource_type, array( 'post', 'elementor' ), true ) ) {
					$snap = ! empty( $row['before_snap'] ) ? json_decode( (string) $row['before_snap'], true ) : null;
					if ( is_array( $snap ) && ! empty( $snap['post_type'] ) ) {
						$post_type = sanitize_key( (string) $snap['post_type'] );
					} else {
						$post_id = isset( $row['resource_id'] ) ? (int) $row['resource_id'] : 0;
						if ( $post_id > 0 ) {
							$post = get_post( $post_id );
							if ( $post instanceof WP_Post ) {
								$post_type = (string) $post->post_type;
							}
						}
					}
				}

				if ( '' !== $post_type ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->update(
						$table,
						array( 'resource_post_type' => $post_type ),
						array( 'id' => $row_id ),
						array( '%s' ),
						array( '%d' )
					);
				}

				$cursor = $row_id;
			}

			// Checkpoint after every batch — the durable resume point a
			// re-entrant call picks up from instead of rescanning already
			// -visited rows (which, since resource_post_type = '' is part
			// of the WHERE, would otherwise re-select any row this pass
			// could not resolve either, on every single call, forever).
			update_option( self::ACTION_LOG_BACKFILL_CURSOR_OPTION, $cursor, false );

			if ( count( $rows ) < self::ACTION_LOG_BACKFILL_BATCH_SIZE ) {
				delete_option( self::ACTION_LOG_BACKFILL_CURSOR_OPTION );
				update_option( 'mcpwp_action_log_post_type_backfilled', 1, false );
				return;
			}
		} while ( ( microtime( true ) - $start ) <= $budget );

		// Budget exhausted mid-scan; cursor is already checkpointed above,
		// so the next plugins_loaded call resumes at $cursor. At least one
		// full batch always completes regardless of how small $time_budget
		// is, for the same reason run_custom_code_repair_batches() gives.
	}

	/**
	 * Batch size for backfill_action_log_resource_post_type() — same value
	 * as CUSTOM_CODE_REPAIR_BATCH_SIZE, for the same reason (#1031 round 2).
	 */
	const ACTION_LOG_BACKFILL_BATCH_SIZE = 200;

	/**
	 * Cursor option for backfill_action_log_resource_post_type().
	 */
	const ACTION_LOG_BACKFILL_CURSOR_OPTION = 'mcpwp_action_log_post_type_backfill_cursor';

	/**
	 * Site graph — nodes, edges, signals, queue (#943 S1, revised post PR
	 * #993 review). Called from create_tables() on every activate()/upgrade,
	 * and again by the daily READY_RETRY_CRON_HOOK while readiness has not
	 * yet been confirmed (W1/W6) — a restricted DB user that could not
	 * CREATE TABLE at install time gets a second chance every day instead of
	 * being stuck forever.
	 *
	 * No `IF NOT EXISTS` on these 4 CREATE TABLE statements (W6): dbDelta()
	 * diffs and ALTERs an existing table by parsing the CREATE TABLE text
	 * itself, and `IF NOT EXISTS` made a real MySQL server silently skip
	 * that path on a table dbDelta needed to alter, on a later release that
	 * adds a column here. Plain `CREATE TABLE` still errors safely (caught
	 * below) when the table already exists in a shape dbDelta considers
	 * final.
	 *
	 * No `DEFAULT CURRENT_TIMESTAMP` on any timestamp column (W6): every
	 * writer sets its own timestamp in PHP (current_time('mysql')) via
	 * Mcpwp_Graph_Builder's suppressed-query helpers, so the column default
	 * never has to be reconciled by a later dbDelta ALTER either.
	 */
	public static function ensure_graph_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$graph_nodes_table = $wpdb->prefix . 'mcpwp_graph_nodes';
		$sql_graph_nodes   = "CREATE TABLE $graph_nodes_table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			node_key varchar(191) NOT NULL,
			kind varchar(32) NOT NULL,
			ref_id bigint(20) unsigned DEFAULT NULL,
			label varchar(255) DEFAULT '',
			url varchar(2048) DEFAULT '',
			status varchar(20) DEFAULT '',
			content_hash char(40) DEFAULT '',
			word_count int(10) unsigned DEFAULT 0,
			props longtext DEFAULT NULL,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY node_key (node_key),
			KEY kind_status (kind, status),
			KEY ref (kind, ref_id)
		) $charset_collate;";
		dbDelta( $sql_graph_nodes );

		$graph_edges_table = $wpdb->prefix . 'mcpwp_graph_edges';
		$sql_graph_edges   = "CREATE TABLE $graph_edges_table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			from_key varchar(191) NOT NULL,
			to_key varchar(191) NOT NULL,
			type varchar(32) NOT NULL,
			weight double DEFAULT 0,
			source varchar(32) DEFAULT 'builder',
			props text DEFAULT NULL,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY edge (from_key(120), to_key(120), type),
			KEY to_type (to_key(120), type)
		) $charset_collate;";
		dbDelta( $sql_graph_edges );

		$graph_signals_table = $wpdb->prefix . 'mcpwp_graph_signals';
		$sql_graph_signals   = "CREATE TABLE $graph_signals_table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			node_key varchar(191) NOT NULL,
			metric varchar(64) NOT NULL,
			value double DEFAULT 0,
			period_start date DEFAULT NULL,
			period_end date DEFAULT NULL,
			source varchar(32) NOT NULL,
			fetched_at datetime DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY sig (node_key(120), metric, source, period_start, period_end),
			KEY metric_period (metric, period_start)
		) $charset_collate;";
		dbDelta( $sql_graph_signals );

		// Queue table (replaces the mcpwp_graph_dirty option — BLOCK-4/W2/W5):
		// mark() does one INSERT IGNORE, which is atomic (no read-modify-write
		// race between two concurrent saves) and, being keyed on $wpdb->prefix
		// at call time, naturally per-blog under switch_to_blog() with no
		// separate multisite branch.
		$graph_queue_table = $wpdb->prefix . 'mcpwp_graph_queue';
		$sql_graph_queue   = "CREATE TABLE $graph_queue_table (
			node_key varchar(191) NOT NULL,
			reason varchar(32) DEFAULT '',
			queued_at datetime DEFAULT NULL,
			PRIMARY KEY (node_key)
		) $charset_collate;";
		dbDelta( $sql_graph_queue );

		self::verify_graph_tables_ready();
	}

	/**
	 * SHOW TABLES LIKE for each of the 4 graph tables; sets READY_OPTION
	 * (autoloaded, so ready() is a free read) only when every one exists.
	 * Never throws — a restricted DB user that could not CREATE TABLE above
	 * simply leaves readiness false, and every graph hook stays a no-op
	 * until the daily retry cron (or a manual re-activation) succeeds.
	 */
	private static function verify_graph_tables_ready() {
		global $wpdb;

		$tables = array(
			$wpdb->prefix . 'mcpwp_graph_nodes',
			$wpdb->prefix . 'mcpwp_graph_edges',
			$wpdb->prefix . 'mcpwp_graph_signals',
			$wpdb->prefix . 'mcpwp_graph_queue',
		);

		$prev = $wpdb->suppress_errors( true );
		$present = 0;
		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- esc_like() escapes the LIKE metacharacters; the value itself is prepared.
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( $found ) {
				$present++;
			}
		}
		$wpdb->suppress_errors( $prev );

		if ( count( $tables ) === $present ) {
			update_option( 'mcpwp_graph_ready', true, true );
		} else {
			update_option( 'mcpwp_graph_ready', false, true );
		}
	}

	/**
	 * Daily retry cron (#943 S1 review round 2, N3): re-verifies the 4 graph
	 * tables EVERY run, even when already marked ready — not only while not
	 * yet ready. A table can disappear after being confirmed present (a
	 * restored-from-backup DB missing one, manual surgery, a botched
	 * migration on the host), and the previous version of this method would
	 * never notice: it early-returned the moment `ready()` was true once,
	 * for good. `ensure_graph_tables()` is a cheap `SHOW TABLES LIKE` per
	 * table plus a `CREATE TABLE` for dbDelta to no-op or fill in whatever
	 * is missing — running it daily regardless of the current flag is what
	 * makes readiness self-healing instead of a one-shot check.
	 */
	public static function retry_graph_tables_cron() {
		self::ensure_graph_tables();
		if ( class_exists( 'Mcpwp_Graph_Builder' ) && Mcpwp_Graph_Builder::ready() ) {
			Mcpwp_Graph_Builder::on_upgrade_schedule_backfill();
		}
	}
}
