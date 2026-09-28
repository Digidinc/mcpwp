<?php
/**
 * Plugin Activator
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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

		// Generate API key if not exists
		if ( ! get_option( 'mcpwp_api_key' ) ) {
			$api_key = self::generate_api_key();
			update_option( 'mcpwp_api_key', wp_hash_password( $api_key ) );
			// Store plain text temporarily so admin can see it on first visit
			set_transient( 'mcpwp_new_api_key', $api_key, HOUR_IN_SECONDS );
			// Flag first activation for welcome flow
			update_option( 'mcpwp_first_activation', true );
		}

		// Ensure scoped API key store exists for key-level revocation/scopes.
		self::ensure_scoped_api_keys();

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

		// One-time: purge identity-less pending approvals (see method docblock).
		self::migrate_identity_less_approvals();

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

		$option_name    = class_exists( 'Mcpwp_Approvals' ) ? Mcpwp_Approvals::OPTION_NAME : 'mcpwp_approval_requests';
		$items          = get_option( $option_name, array() );
		$actionable     = array( 'pending', 'approved' );

		if ( is_array( $items ) && ! empty( $items ) ) {
			$changed = false;
			foreach ( $items as $id => $record ) {
				$status    = is_array( $record ) && isset( $record['status'] ) ? (string) $record['status'] : '';
				$requester = is_array( $record ) && isset( $record['requested_by_key_id'] ) ? (string) $record['requested_by_key_id'] : '';
				if ( in_array( $status, $actionable, true ) && '' === $requester ) {
					unset( $items[ $id ] );
					$changed = true;
				}
			}
			if ( $changed ) {
				update_option( $option_name, $items, false );
			}
		}

		update_option( 'mcpwp_approvals_identity_migrated', 1, false );
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
	 */
	public static function migrate_empty_custom_category_keys() {
		if ( get_option( 'mcpwp_deny_all_categories_migrated' ) ) {
			return;
		}

		foreach ( array( 'mcpwp_api_keys', 'spai_api_keys' ) as $option_name ) {
			$keys = get_option( $option_name, array() );
			if ( ! is_array( $keys ) || empty( $keys ) ) {
				continue;
			}

			$changed = false;
			foreach ( $keys as &$key ) {
				if ( ! is_array( $key ) ) {
					continue;
				}

				$role = isset( $key['role'] ) ? (string) $key['role'] : '';
				if ( 'custom' !== $role ) {
					continue;
				}

				$categories = ( isset( $key['tool_categories'] ) && is_array( $key['tool_categories'] ) )
					? array_values( $key['tool_categories'] )
					: array();

				if ( empty( $categories ) ) {
					$key['tool_categories'] = array( self::deny_all_category_marker() );
					$changed                = true;
				}
			}
			unset( $key );

			if ( $changed ) {
				update_option( $option_name, $keys );
			}
		}

		update_option( 'mcpwp_deny_all_categories_migrated', 1, false );
	}

	/**
	 * Snippets repaired per plugins_loaded call, in addition to the time
	 * budget below — whichever limit is hit first ends the current run. Kept
	 * small enough that even a slow request (shared hosting, no object cache)
	 * comfortably clears one batch inside the budget, which is what
	 * guarantees forward progress on every call (see below).
	 */
	const CUSTOM_CODE_REPAIR_BATCH_SIZE = 200;

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
		$existing = get_option( 'mcpwp_api_keys', array() );
		if ( is_array( $existing ) && ! empty( $existing ) ) {
			return;
		}

		$legacy_key = get_option( 'mcpwp_api_key' );
		if ( empty( $legacy_key ) ) {
			return;
		}

		$legacy_hash = self::looks_like_password_hash( $legacy_key ) ? (string) $legacy_key : wp_hash_password( (string) $legacy_key );
		if ( $legacy_hash !== $legacy_key ) {
			update_option( 'mcpwp_api_key', $legacy_hash );
		}

		$key_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'mcpwp_', true );
		$record = array(
			'id'           => sanitize_key( (string) $key_id ),
			'label'        => __( 'Primary API Key', 'mcpwp' ),
			'hash'         => $legacy_hash,
			'scopes'       => array( 'read', 'write', 'admin' ),
			'created_at'   => current_time( 'mysql' ),
			'last_used_at' => null,
			'revoked_at'   => null,
		);

		update_option( 'mcpwp_api_keys', array( $record ) );
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
		$value = (string) $value;
		return '' !== $value && '$' === substr( $value, 0, 1 );
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
