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
 * Both actions are admin-post handlers carrying their own nonce and
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
				<?php esc_html_e( 'AI apps can now connect by signing in with WordPress; turn off under Setup → Advanced.', 'mcpwp' ); ?>
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
