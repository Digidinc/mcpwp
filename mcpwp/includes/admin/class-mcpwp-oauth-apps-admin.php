<?php
/**
 * Connected AI apps (#1101 PR-3b): the admin "AI apps" list under Setup →
 * Advanced, and the "Connected AI apps" section on a user's profile.
 *
 * - Admin list (manage_options): every registered app, with a Delete that
 *   calls Mcpwp_OAuth_Clients::delete() — its tokens stop working at once.
 * - Profile: the apps holding live tokens for that user, each with Revoke
 *   (Mcpwp_OAuth_Revocation::revoke_user_client()); an administrator also
 *   gets "Disconnect all AI apps for this user" (revoke_user()).  A user may
 *   revoke their own apps; an administrator (edit_users + manage_options)
 *   anyone's; nobody else.
 *
 * Every action checks capability and a nonce bound to its target itself
 * (the ChatGPT connector's handle_request() pattern), so the guarantee does
 * not rest on the caller.  App names come from Dynamic Client Registration
 * — attacker-controlled — and are only ever printed escaped.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Connected AI apps admin surfaces.
 */
class Mcpwp_OAuth_Apps_Admin {

	/** admin-post action (and nonce action prefix) for deleting an app. */
	const DELETE_ACTION = 'mcpwp_oauth_client_delete';

	/** Nonce action prefix for the profile buttons. */
	const PROFILE_ACTION = 'mcpwp_oauth_apps_profile';

	/** Nonce field shared by both forms. */
	const NONCE_FIELD = 'mcpwp_oauth_apps_nonce';

	/** Button / field names. */
	const FIELD_CLIENT     = 'mcpwp_oauth_client_id';
	const FIELD_REVOKE     = 'mcpwp_oauth_revoke_client';
	const FIELD_REVOKE_ALL = 'mcpwp_oauth_revoke_all';

	/** One-shot notice for the acting user (60 s transient). */
	const NOTICE_PREFIX = 'mcpwp_oauth_apps_notice_';

	/** Capability for the app list. */
	const LIST_CAPABILITY = 'manage_options';

	// -------------------------------------------------------------------------
	// Who may do what
	// -------------------------------------------------------------------------

	/**
	 * An administrator for these purposes: edit_users AND manage_options.
	 *
	 * @return bool
	 */
	public static function is_apps_admin() {
		return current_user_can( 'edit_users' ) && current_user_can( 'manage_options' );
	}

	/**
	 * Whether the current user may see and revoke $user_id's apps.
	 *
	 * @param int $user_id Profile owner.
	 * @return bool
	 */
	public static function can_manage_apps_of( $user_id ) {
		$user_id = (int) $user_id;
		$current = (int) get_current_user_id();
		if ( $user_id <= 0 || $current <= 0 ) {
			return false;
		}
		return $current === $user_id || self::is_apps_admin();
	}

	// -------------------------------------------------------------------------
	// Admin list: delete
	// -------------------------------------------------------------------------

	/**
	 * Handle a Delete from the app list.
	 *
	 * @param array $post Unslashed request fields.
	 * @return array{type:string,message:string}|null Null when not a delete.
	 */
	public static function process_delete( array $post ) {
		if ( ! isset( $post[ self::FIELD_CLIENT ] ) ) {
			return null;
		}
		if ( ! current_user_can( self::LIST_CAPABILITY ) ) {
			return array( 'type' => 'error', 'message' => __( 'You are not allowed to remove AI apps.', 'mcpwp' ) );
		}
		$client_id = sanitize_key( (string) $post[ self::FIELD_CLIENT ] );
		$nonce     = isset( $post[ self::NONCE_FIELD ] ) ? (string) $post[ self::NONCE_FIELD ] : '';
		if ( '' === $client_id || ! wp_verify_nonce( $nonce, self::DELETE_ACTION . '|' . $client_id ) ) {
			return array( 'type' => 'error', 'message' => __( 'The form expired. Reload the page and try again.', 'mcpwp' ) );
		}
		if ( null === Mcpwp_OAuth_Clients::find( $client_id ) ) {
			return array( 'type' => 'error', 'message' => __( 'That app is no longer registered.', 'mcpwp' ) );
		}
		if ( ! Mcpwp_OAuth_Clients::delete( $client_id ) ) {
			return array( 'type' => 'error', 'message' => __( 'The app could not be removed because the site was busy. Nothing was changed; try again.', 'mcpwp' ) );
		}
		return array( 'type' => 'updated', 'message' => __( 'App removed. It lost access to this site immediately; to use it again, connect it again.', 'mcpwp' ) );
	}

	/**
	 * admin-post handler for Delete.
	 */
	public static function handle_delete() {
		if ( ! current_user_can( self::LIST_CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'mcpwp' ), '', array( 'response' => 403 ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in process_delete().
		$result = self::process_delete( wp_unslash( $_POST ) );
		if ( null !== $result ) {
			self::remember_notice( $result );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=mcpwp#mcpwp-oauth-apps' ) );
		exit;
	}

	/**
	 * The app list (Setup → Advanced).  Nothing for anyone without
	 * manage_options.
	 */
	public static function render_clients_list() {
		if ( ! current_user_can( self::LIST_CAPABILITY ) ) {
			return;
		}
		$clients = Mcpwp_OAuth_Clients::all();
		$counts  = Mcpwp_OAuth_Token_Store::active_counts_by_client();
		uasort(
			$clients,
			function ( $a, $b ) {
				return (int) $b['created_at'] <=> (int) $a['created_at'];
			}
		);
		?>
		<div id="mcpwp-oauth-apps">
			<h3><?php esc_html_e( 'AI apps', 'mcpwp' ); ?></h3>
			<?php self::render_notice(); ?>
			<p class="description"><?php esc_html_e( 'Apps that registered to connect with WordPress sign-in. Removing an app stops it at once for every user.', 'mcpwp' ); ?></p>
			<?php if ( empty( $clients ) ) : ?>
				<p><?php esc_html_e( 'No AI apps have registered yet.', 'mcpwp' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Name', 'mcpwp' ); ?></th>
							<th><?php esc_html_e( 'App ID', 'mcpwp' ); ?></th>
							<th><?php esc_html_e( 'Sends sign-ins to', 'mcpwp' ); ?></th>
							<th><?php esc_html_e( 'Registered', 'mcpwp' ); ?></th>
							<th><?php esc_html_e( 'Last used', 'mcpwp' ); ?></th>
							<th><?php esc_html_e( 'Active tokens', 'mcpwp' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $clients as $client_id => $client ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $client['client_name'] ); ?></td>
								<td><code><?php echo esc_html( substr( (string) $client_id, 0, 14 ) . '…' ); ?></code></td>
								<td><?php echo esc_html( implode( ', ', Mcpwp_OAuth_Clients::redirect_hosts( (array) $client['redirect_uris'] ) ) ); ?></td>
								<td><?php echo esc_html( self::format_time( (int) $client['created_at'] ) ); ?></td>
								<td><?php echo esc_html( self::format_time( (int) $client['last_used_at'] ) ); ?></td>
								<td><?php echo esc_html( (string) (int) ( $counts[ $client_id ] ?? 0 ) ); ?></td>
								<td>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
										<input type="hidden" name="action" value="<?php echo esc_attr( self::DELETE_ACTION ); ?>" />
										<input type="hidden" name="<?php echo esc_attr( self::FIELD_CLIENT ); ?>" value="<?php echo esc_attr( (string) $client_id ); ?>" />
										<?php wp_nonce_field( self::DELETE_ACTION . '|' . $client_id, self::NONCE_FIELD, false ); ?>
										<button type="submit" class="button button-small"><?php esc_html_e( 'Remove', 'mcpwp' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Profile: Connected AI apps
	// -------------------------------------------------------------------------

	/**
	 * Handle the profile buttons.  Called from the profile-update hooks with
	 * the profile owner's id (WordPress has already checked edit_user and its
	 * own nonce; this checks its own capability rule and nonce regardless).
	 *
	 * @param int   $user_id Profile owner.
	 * @param array $post    Unslashed request fields.
	 * @return array{type:string,message:string}|null Null when no button was pressed.
	 */
	public static function process_profile( $user_id, array $post ) {
		$user_id    = (int) $user_id;
		$revoke_all = ! empty( $post[ self::FIELD_REVOKE_ALL ] );
		$client_id  = isset( $post[ self::FIELD_REVOKE ] ) ? sanitize_key( (string) $post[ self::FIELD_REVOKE ] ) : '';
		if ( ! $revoke_all && '' === $client_id ) {
			return null;
		}
		if ( ! self::can_manage_apps_of( $user_id ) || ( $revoke_all && ! self::is_apps_admin() ) ) {
			return array( 'type' => 'error', 'message' => __( 'You are not allowed to change this user\'s AI apps.', 'mcpwp' ) );
		}
		$nonce = isset( $post[ self::NONCE_FIELD ] ) ? (string) $post[ self::NONCE_FIELD ] : '';
		if ( ! wp_verify_nonce( $nonce, self::PROFILE_ACTION . '|' . $user_id ) ) {
			return array( 'type' => 'error', 'message' => __( 'The form expired. Reload the page and try again.', 'mcpwp' ) );
		}

		if ( $revoke_all ) {
			if ( ! Mcpwp_OAuth_Revocation::revoke_user( $user_id ) ) {
				return array( 'type' => 'error', 'message' => __( 'The apps could not be disconnected because the site was busy. Try again.', 'mcpwp' ) );
			}
			return array( 'type' => 'updated', 'message' => __( 'All AI apps for this user were disconnected. They lost access immediately; each will need to be connected again.', 'mcpwp' ) );
		}

		$apps = Mcpwp_OAuth_Token_Store::live_apps_for_user( $user_id );
		$name = isset( $apps[ $client_id ] ) ? (string) $apps[ $client_id ]['client_name'] : $client_id;
		if ( ! Mcpwp_OAuth_Revocation::revoke_user_client( $user_id, $client_id ) ) {
			return array( 'type' => 'error', 'message' => __( 'The app could not be revoked because the site was busy. Try again.', 'mcpwp' ) );
		}
		return array(
			'type'    => 'updated',
			/* translators: %s: app name */
			'message' => sprintf( __( '%s lost access immediately. It will need to be connected again.', 'mcpwp' ), $name ),
		);
	}

	/**
	 * personal_options_update / edit_user_profile_update.
	 *
	 * @param int $user_id Profile owner.
	 */
	public static function handle_profile_update( $user_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in process_profile().
		$result = self::process_profile( (int) $user_id, wp_unslash( $_POST ) );
		if ( null !== $result ) {
			self::remember_notice( $result );
		}
	}

	/**
	 * show_user_profile / edit_user_profile.
	 *
	 * @param WP_User|object $user Profile owner.
	 */
	public static function render_profile_section( $user ) {
		$user_id = is_object( $user ) && isset( $user->ID ) ? (int) $user->ID : 0;
		if ( ! self::can_manage_apps_of( $user_id ) ) {
			return;
		}
		$apps = Mcpwp_OAuth_Token_Store::live_apps_for_user( $user_id );
		?>
		<h2 id="mcpwp-connected-apps"><?php esc_html_e( 'Connected AI apps', 'mcpwp' ); ?></h2>
		<?php self::render_notice(); ?>
		<p class="description"><?php esc_html_e( 'Apps such as ChatGPT or Claude that were allowed to use this site as this user. Revoke: the app loses access immediately; it will need to be connected again.', 'mcpwp' ); ?></p>
		<?php wp_nonce_field( self::PROFILE_ACTION . '|' . $user_id, self::NONCE_FIELD, false ); ?>
		<?php if ( empty( $apps ) ) : ?>
			<p><?php esc_html_e( 'No AI apps are connected.', 'mcpwp' ); ?></p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:900px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'App', 'mcpwp' ); ?></th>
						<th><?php esc_html_e( 'Access', 'mcpwp' ); ?></th>
						<th><?php esc_html_e( 'Connected since', 'mcpwp' ); ?></th>
						<th><?php esc_html_e( 'Last used', 'mcpwp' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $apps as $app ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $app['client_name'] ); ?></td>
							<td><?php echo esc_html( in_array( 'write', (array) $app['scopes'], true ) ? __( 'Read and write', 'mcpwp' ) : __( 'Read only', 'mcpwp' ) ); ?></td>
							<td><?php echo esc_html( self::format_time( (int) floor( (int) $app['issued_at'] / 1000000 ) ) ); ?></td>
							<td><?php echo esc_html( self::format_time( (int) $app['last_used_at'] ) ); ?></td>
							<td><button type="submit" class="button button-small" name="<?php echo esc_attr( self::FIELD_REVOKE ); ?>" value="<?php echo esc_attr( (string) $app['client_id'] ); ?>"><?php esc_html_e( 'Revoke', 'mcpwp' ); ?></button></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( self::is_apps_admin() ) : ?>
				<p><button type="submit" class="button" name="<?php echo esc_attr( self::FIELD_REVOKE_ALL ); ?>" value="1"><?php esc_html_e( 'Disconnect all AI apps for this user', 'mcpwp' ); ?></button></p>
			<?php endif; ?>
		<?php endif; ?>
		<?php
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * @param array $notice {type, message}.
	 */
	private static function remember_notice( array $notice ) {
		set_transient( self::NOTICE_PREFIX . (int) get_current_user_id(), $notice, 60 );
	}

	/**
	 * Print (and forget) the acting user's notice.  Plain text, escaped.
	 */
	private static function render_notice() {
		$key    = self::NOTICE_PREFIX . (int) get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}
		delete_transient( $key );
		$class = 'updated' === ( $notice['type'] ?? '' ) ? 'notice-success' : 'notice-error';
		?>
		<div class="notice <?php echo esc_attr( $class ); ?> inline"><p><?php echo esc_html( (string) $notice['message'] ); ?></p></div>
		<?php
	}

	/**
	 * @param int $timestamp Unix seconds; 0 = never.
	 * @return string
	 */
	private static function format_time( $timestamp ) {
		if ( $timestamp <= 0 ) {
			return __( 'Never', 'mcpwp' );
		}
		return function_exists( 'wp_date' ) ? (string) wp_date( 'Y-m-d H:i', $timestamp ) : gmdate( 'Y-m-d H:i', $timestamp );
	}
}
