<?php
/**
 * mupot Profile Fields
 *
 * Per-user "connect your mupot account" fields on the WP Profile screen.
 * Gated on mupot_enabled and current_user_can('activate_plugins') — the
 * same capability every other mcpwp admin surface already requires.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders and saves the Profile screen mupot fields.
 */
class Mcpwp_Mupot_Profile {

	/**
	 * Nonce action/field name for the profile save.
	 *
	 * @var string
	 */
	const NONCE_FIELD = 'mcpwp_mupot_profile_nonce';

	/**
	 * Whether the mupot connector is enabled site-wide.
	 *
	 * @return bool
	 */
	private function is_enabled() {
		$settings = get_option( 'mcpwp_settings', array() );
		return ! empty( $settings['mupot_enabled'] );
	}

	/**
	 * Render the token + squad fields on a user's Profile screen.
	 *
	 * @param WP_User|object $user The profile-owning user.
	 */
	public function render_fields( $user ) {
		if ( ! $this->is_enabled() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$connector      = new Mcpwp_Mupot_Connector();
		$user_id        = (int) $user->ID;
		$has_token      = $connector->has_token( $user_id );
		$squad          = $connector->get_squad( $user_id );
		$token_display  = $has_token ? '••••••••' : '';
		?>
		<h2><?php esc_html_e( 'mupot Connection', 'mcpwp' ); ?></h2>
		<table class="form-table">
			<tr>
				<th><label for="mcpwp_mupot_member_token"><?php esc_html_e( 'Your mupot member token', 'mcpwp' ); ?></label></th>
				<td>
					<input type="password" id="mcpwp_mupot_member_token" name="mcpwp_mupot_member_token" value="" class="regular-text" autocomplete="off" placeholder="<?php echo esc_attr( $token_display ); ?>" />
					<p class="description"><?php esc_html_e( 'Paste your personal mupot member token. Leave blank to keep your current connection.', 'mcpwp' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="mcpwp_mupot_squad_id"><?php esc_html_e( 'Your mupot squad', 'mcpwp' ); ?></label></th>
				<td>
					<input type="text" id="mcpwp_mupot_squad_id" name="mcpwp_mupot_squad_id" value="<?php echo esc_attr( $squad ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'The squad id or slug your mupot task board lives in.', 'mcpwp' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
		wp_nonce_field( 'mcpwp_mupot_profile_save', self::NONCE_FIELD );
	}

	/**
	 * Persist the token + squad fields when a profile is saved.
	 *
	 * @param int $user_id The profile-owning user's ID.
	 */
	public function save_fields( $user_id ) {
		if ( ! $this->is_enabled() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), 'mcpwp_mupot_profile_save' ) ) {
			return;
		}

		$connector = new Mcpwp_Mupot_Connector();

		$new_token = isset( $_POST['mcpwp_mupot_member_token'] ) ? trim( (string) wp_unslash( $_POST['mcpwp_mupot_member_token'] ) ) : '';
		if ( '' !== $new_token ) {
			$connector->save_token( (int) $user_id, $new_token );
		}

		if ( isset( $_POST['mcpwp_mupot_squad_id'] ) ) {
			$connector->save_squad( (int) $user_id, wp_unslash( $_POST['mcpwp_mupot_squad_id'] ) );
		}
	}
}
