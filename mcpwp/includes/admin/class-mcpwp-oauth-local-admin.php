<?php
/**
 * Local OAuth — admin control and the one-time "now on by default" notice.
 *
 * PR-2 D10 turns this site's own OAuth authorization server on for every
 * site that never stored `oauth_enabled` — new installs and upgrades alike.
 * That is a change an administrator should hear about once, with the switch
 * one click away:
 *
 *  - a dismissible admin notice, shown only to users who can manage plugins
 *    (activate_plugins) and only while the default is what applies (the
 *    setting is absent — never when it is stored on or off);
 *  - an on/off control under Setup → Advanced.  Saving it stores an explicit
 *    value, so a site switched off stays off across upgrades.
 *
 * Round 2 adds:
 *  - a one-time, flag-guarded migration: a STORED `oauth_enabled = false`
 *    is removed so the new default applies.  Old versions wrote false on ANY
 *    settings save, under a label ("OAuth Token Endpoint … client
 *    credentials") that never described sign-in, so a stored false is not
 *    evidence of an opt-out.  Such sites get the notice too.
 *  - an "address changed" line on Setup: a permalink or home-URL change
 *    changes the MCP URL, which is every OAuth token's audience, so
 *    connected apps must reconnect.
 *
 * Every action is an admin-post handler carrying its own nonce and
 * capability check.  The process_* methods hold the logic and never exit,
 * so they are unit-testable; the handle_* wrappers redirect and exit.
 *
 * @package MCPWP
 * @since   3.14.11
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin surface for the local OAuth switch.
 */
class Mcpwp_OAuth_Local_Admin {

	/** admin-post action (and nonce action) for the Setup → Advanced switch. */
	const TOGGLE_ACTION = 'mcpwp_local_oauth_toggle';

	/** admin-post action (and nonce action) for dismissing the notice. */
	const DISMISS_ACTION = 'mcpwp_oauth_default_notice_dismiss';

	/** Nonce field name shared by both forms. */
	const NONCE_FIELD = 'mcpwp_local_oauth_nonce';

	/** User meta recording that this user dismissed the notice. */
	const DISMISSED_META = 'mcpwp_oauth_default_notice_dismissed';

	/** Capability required to see the notice and to change the switch. */
	const CAPABILITY = 'activate_plugins';

	/** Flag: the D10 migration has run (autoloaded — read on every load). */
	const MIGRATED_FLAG = 'mcpwp_oauth_default_migrated';

	/** The canonical resource this site was last authorized under. */
	const LAST_RESOURCE_OPTION = 'mcpwp_oauth_last_resource';

	/** admin-post action (and nonce action) for the address-changed line. */
	const ADDRESS_DISMISS_ACTION = 'mcpwp_oauth_address_notice_dismiss';

	/** Lock row for the D10 migration (the client store's lock pattern). */
	const MIGRATION_LOCK_OPTION = 'mcpwp_oauth_default_migration_lock';

	/**
	 * One-time migration (PR-2 D10, round 2).
	 *
	 * Runs on plugin load, before admin_init registers the settings sanitizer
	 * — which matters: that sanitizer keeps a stored checkbox value when the
	 * write does not carry it, and would put the false straight back.
	 *
	 * Stored false → key removed (absent = on).  Stored true and absent are
	 * left alone.  The flag is set last and checked first, so it runs once:
	 * a site switched off after this release stays off.
	 *
	 * #1101 (gate carry-over): the read-modify-write of mcpwp_settings runs
	 * under a lock (Mcpwp_OAuth_Clients::acquire_lock(), its own row), with
	 * the flag and the settings re-read from the database inside it.  Two
	 * first loads after an upgrade no longer both rewrite the settings, and
	 * the copy written back is the one read a moment before — not one read
	 * before another request's settings save landed.  A load that cannot get
	 * the lock skips; the next load runs the migration.
	 *
	 * @return bool True when it ran (flag was not set).
	 */
	public static function migrate_default_on() {
		if ( get_option( self::MIGRATED_FLAG ) ) {
			return false;
		}

		$token = Mcpwp_OAuth_Clients::acquire_lock( 0, self::MIGRATION_LOCK_OPTION );
		if ( false === $token ) {
			return false;
		}

		// Re-read from the database, not this process's caches: the flag and
		// the settings are autoloaded (alloptions), and a name found missing
		// earlier in this request sits in `notoptions` — without a
		// persistent cache that is where a stale "not migrated" would come
		// from (#1101 round 2).
		Mcpwp_OAuth_Clients::flush_option_cache( self::MIGRATED_FLAG );
		Mcpwp_OAuth_Clients::flush_option_cache( 'mcpwp_settings' );
		Mcpwp_OAuth_Clients::flush_option_cache( 'alloptions' );
		Mcpwp_OAuth_Clients::flush_option_cache( 'notoptions' );
		if ( get_option( self::MIGRATED_FLAG ) ) {
			Mcpwp_OAuth_Clients::release_lock( $token, self::MIGRATION_LOCK_OPTION );
			return false;
		}

		$settings = get_option( 'mcpwp_settings', array() );
		if ( is_array( $settings ) && array_key_exists( 'oauth_enabled', $settings ) && empty( $settings['oauth_enabled'] ) ) {
			unset( $settings['oauth_enabled'] );
			update_option( 'mcpwp_settings', $settings );
		}

		update_option( self::MIGRATED_FLAG, 1 );
		Mcpwp_OAuth_Clients::release_lock( $token, self::MIGRATION_LOCK_OPTION );
		return true;
	}

	/**
	 * Store the current canonical resource if none is stored yet.
	 *
	 * Called when a code is issued; the address-changed line compares against
	 * it.  Only the first authorization sets it — a later reconnection must
	 * not hide the fact that other apps still hold tokens for the old URL.
	 */
	public static function remember_resource_if_unset() {
		if ( '' === (string) get_option( self::LAST_RESOURCE_OPTION, '' ) ) {
			update_option( self::LAST_RESOURCE_OPTION, Mcpwp_OAuth_Well_Known::get_canonical_resource(), false );
		}
	}

	/**
	 * Whether Setup should say the site's address changed.
	 *
	 * True when a resource was recorded, it differs from today's canonical
	 * MCP URL, and at least one registered client has ever been authorized.
	 *
	 * @return bool
	 */
	public static function address_changed() {
		$last = (string) get_option( self::LAST_RESOURCE_OPTION, '' );
		if ( '' === $last ) {
			return false;
		}
		if ( Mcpwp_OAuth_Well_Known::normalize_resource( $last ) === Mcpwp_OAuth_Well_Known::normalize_resource( Mcpwp_OAuth_Well_Known::get_canonical_resource() ) ) {
			return false;
		}
		return Mcpwp_OAuth_Clients::any_client_authorized();
	}

	/**
	 * Dismiss the address-changed line: the current address becomes the one
	 * on record.
	 *
	 * @param array $post Unslashed request fields.
	 * @return bool True when dismissed.
	 */
	public static function process_address_dismiss( array $post ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return false;
		}
		$nonce = isset( $post[ self::NONCE_FIELD ] ) ? (string) $post[ self::NONCE_FIELD ] : '';
		if ( ! wp_verify_nonce( $nonce, self::ADDRESS_DISMISS_ACTION ) ) {
			return false;
		}
		update_option( self::LAST_RESOURCE_OPTION, Mcpwp_OAuth_Well_Known::get_canonical_resource(), false );
		return true;
	}

	/**
	 * admin-post handler for the address-changed dismiss.
	 */
	public static function handle_address_dismiss() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in process_address_dismiss().
		if ( ! self::process_address_dismiss( wp_unslash( $_POST ) ) ) {
			wp_die( esc_html__( 'The request expired or you are not allowed to do this.', 'mcpwp' ), '', array( 'response' => 403 ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=mcpwp' ) );
		exit;
	}

	/**
	 * Render the address-changed line (Setup page).
	 */
	public static function render_address_notice() {
		if ( ! current_user_can( self::CAPABILITY ) || ! self::address_changed() ) {
			return;
		}
		?>
		<div class="notice notice-warning inline" id="mcpwp-oauth-address-notice" style="margin:10px 0;">
			<p><?php esc_html_e( 'This site\'s address changed, so AI apps connected with WordPress sign-in must reconnect.', 'mcpwp' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0 0 8px;">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ADDRESS_DISMISS_ACTION ); ?>" />
				<?php wp_nonce_field( self::ADDRESS_DISMISS_ACTION, self::NONCE_FIELD ); ?>
				<button type="submit" class="button button-small"><?php esc_html_e( 'Dismiss', 'mcpwp' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * Whether the notice should be shown to the current user.
	 *
	 * @return bool
	 */
	public static function should_show_notice() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return false;
		}
		if ( ! Mcpwp_OAuth_Well_Known::oauth_enabled_by_default() ) {
			return false;
		}
		return ! get_user_meta( get_current_user_id(), self::DISMISSED_META, true );
	}

	/**
	 * admin_notices callback.
	 */
	public static function render_notice() {
		if ( ! self::should_show_notice() ) {
			return;
		}

		$setup_url = admin_url( 'admin.php?page=mcpwp#mcpwp-setup-advanced' );
		?>
		<div class="notice notice-info" id="mcpwp-oauth-default-notice">
			<p>
				<?php esc_html_e( 'This update turned on sign-in for AI apps: AI apps can now connect by signing in with WordPress; turn off under Setup → Advanced.', 'mcpwp' ); ?>
				<a href="<?php echo esc_url( $setup_url ); ?>"><?php esc_html_e( 'Open Setup → Advanced', 'mcpwp' ); ?></a>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0 0 8px;">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::DISMISS_ACTION ); ?>" />
				<?php wp_nonce_field( self::DISMISS_ACTION, self::NONCE_FIELD ); ?>
				<button type="submit" class="button button-small"><?php esc_html_e( 'Dismiss', 'mcpwp' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * Record a dismissal for the current user.
	 *
	 * @param array $post Unslashed request fields.
	 * @return bool True when the dismissal was stored.
	 */
	public static function process_dismiss( array $post ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return false;
		}
		$nonce = isset( $post[ self::NONCE_FIELD ] ) ? (string) $post[ self::NONCE_FIELD ] : '';
		if ( ! wp_verify_nonce( $nonce, self::DISMISS_ACTION ) ) {
			return false;
		}
		update_user_meta( get_current_user_id(), self::DISMISSED_META, 1 );
		return true;
	}

	/**
	 * Store the Setup → Advanced switch as an explicit value.
	 *
	 * Written through update_option(), so Mcpwp_Settings::sanitize_settings()
	 * runs exactly as for the REST plugin-settings path: the key is present in
	 * the input, so it is stored as given — true or false, never "absent".
	 *
	 * @param array $post Unslashed request fields.
	 * @return bool|null The stored value, or null when refused.
	 */
	public static function process_toggle( array $post ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return null;
		}
		$nonce = isset( $post[ self::NONCE_FIELD ] ) ? (string) $post[ self::NONCE_FIELD ] : '';
		if ( ! wp_verify_nonce( $nonce, self::TOGGLE_ACTION ) ) {
			return null;
		}

		$enabled  = isset( $post['mcpwp_local_oauth'] ) && '1' === (string) $post['mcpwp_local_oauth'];
		$settings = get_option( 'mcpwp_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();

		$settings['oauth_enabled'] = $enabled;
		update_option( 'mcpwp_settings', $settings );

		// P0b: the minimum role that may connect AI apps, in its own option
		// (the settings sanitizer would drop an unknown key).  Only a listed
		// choice is stored; anything else leaves the stored value alone.
		if ( isset( $post['mcpwp_local_oauth_min_role'] ) ) {
			$role = sanitize_key( (string) $post['mcpwp_local_oauth_min_role'] );
			if ( array_key_exists( $role, Mcpwp_OAuth_Well_Known::min_role_choices() ) ) {
				update_option( Mcpwp_OAuth_Well_Known::MIN_ROLE_OPTION, $role, false );
			}
		}

		return $enabled;
	}

	/**
	 * admin-post handler: dismiss the notice, then go back.
	 */
	public static function handle_dismiss() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in process_dismiss().
		if ( ! self::process_dismiss( wp_unslash( $_POST ) ) ) {
			wp_die( esc_html__( 'The request expired or you are not allowed to do this.', 'mcpwp' ), '', array( 'response' => 403 ) );
		}
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url() );
		exit;
	}

	/**
	 * admin-post handler: save the switch, then return to Setup → Advanced.
	 */
	public static function handle_toggle() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in process_toggle().
		$result = self::process_toggle( wp_unslash( $_POST ) );
		if ( null === $result ) {
			wp_die( esc_html__( 'The request expired or you are not allowed to do this.', 'mcpwp' ), '', array( 'response' => 403 ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=mcpwp&local_oauth=' . ( $result ? 'on' : 'off' ) . '#mcpwp-setup-advanced' ) );
		exit;
	}
}
