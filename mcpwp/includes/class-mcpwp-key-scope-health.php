<?php
/**
 * Surface stored API keys that carried admin without write (#1210).
 *
 * Create/update refuse that shape. A legacy or hand-seeded record that still
 * holds it is normalised at read time (admin dropped, write never added). An
 * administrator sees the labels so they can revoke or recreate the key —
 * never hashes or secrets.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mcpwp_Key_Scope_Health {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
	}

	/**
	 * Labels of active keys whose stored scopes list admin without write.
	 *
	 * Inspects the raw option rows (before sanitize_scopes drops admin) so the
	 * notice still fires after effective scopes have been narrowed.
	 *
	 * @return string[]
	 */
	public static function labels_of_admin_without_write_keys() {
		$raw = get_option( 'mcpwp_api_keys', array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$labels = array();
		foreach ( $raw as $record ) {
			if ( ! is_array( $record ) || ! empty( $record['revoked_at'] ) ) {
				continue;
			}
			$scopes = isset( $record['scopes'] ) ? array_map( 'sanitize_key', (array) $record['scopes'] ) : array();
			if ( in_array( 'admin', $scopes, true ) && ! in_array( 'write', $scopes, true ) ) {
				$label = isset( $record['label'] ) ? sanitize_text_field( (string) $record['label'] ) : '';
				if ( '' === $label ) {
					$label = __( 'API Key', 'mcpwp' );
				}
				$labels[] = $label;
			}
		}

		return array_values( $labels );
	}

	/**
	 * Admin notice listing only labels of demoted keys.
	 *
	 * @return void
	 */
	public static function render_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$labels = self::labels_of_admin_without_write_keys();
		if ( empty( $labels ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s %s</p></div>',
			esc_html__( 'MCPWP API key scopes adjusted.', 'mcpwp' ),
			esc_html__( 'These keys stored Admin ops without Write; Admin ops is no longer effective for them (read access remains). Recreate each key with Write if Admin ops is required:', 'mcpwp' ),
			esc_html( implode( ', ', $labels ) )
		);
	}
}
