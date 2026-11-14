<?php
/**
 * Loads the real Mcpwp_Admin and renders admin/partials/mcpwp-setup-display.php
 * (SetupDisplayKeyState1098Test). Only ever required inside a separate
 * process: the function stubs below would otherwise leak into other tests.
 *
 * @package MCPWP
 */

$mcpwp_1098_dir = dirname( __DIR__, 2 );
require_once $mcpwp_1098_dir . '/includes/core/class-mcpwp-key-state.php';
foreach ( array( 'setup', 'control-room', 'chat', 'library', 'settings', 'activity', 'mupot' ) as $mcpwp_1098_trait ) {
	require_once $mcpwp_1098_dir . '/includes/admin/traits/trait-mcpwp-admin-' . $mcpwp_1098_trait . '.php';
}
foreach ( array( 'class-mcpwp-admin', 'class-mcpwp-oauth-local-admin', 'class-mcpwp-chatgpt-connector', 'class-mcpwp-oauth-apps-admin', 'class-mcpwp-tools-admin' ) as $mcpwp_1098_class ) {
	require_once $mcpwp_1098_dir . '/includes/admin/' . $mcpwp_1098_class . '.php';
}

// Admin template helpers the shared bootstrap does not define.
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return (string) $url;
	}
}
if ( ! function_exists( 'esc_js' ) ) {
	function esc_js( $text ) {
		return (string) $text;
	}
}
if ( ! function_exists( 'esc_attr_e' ) ) {
	function esc_attr_e( $text, $domain = 'default' ) {
		echo esc_attr( $text );
	}
}
if ( ! function_exists( 'settings_errors' ) ) {
	function settings_errors( ...$args ) {
	}
}
if ( ! function_exists( 'date_i18n' ) ) {
	function date_i18n( $format, $timestamp = false ) {
		return gmdate( (string) $format, (int) $timestamp );
	}
}
if ( ! function_exists( 'checked' ) ) {
	function checked( $a, $b = true, $echo = true ) {
		return '';
	}
}
if ( ! function_exists( 'selected' ) ) {
	function selected( $a, $b = true, $echo = true ) {
		return '';
	}
}

/**
 * Include the partial with the locals render_setup_page() provides.
 *
 * @param array       $scoped_keys list_scoped_api_keys( true ) rows.
 * @param string|null $new_key     Plaintext key just generated, if any.
 */
function mcpwp_1098_render_setup_partial( array $scoped_keys, $new_key ) {
	$new_scoped_key         = null;
	$chatgpt_connector      = null;
	$chatgpt_new_url        = '';
	$chatgpt_oauth_conflict = false;
	$local_oauth_enabled    = false;
	$chatgpt_active_key     = null;
	$broker_enrolled        = false;
	$broker_enrollment      = array();
	$broker_audience        = '';
	$broker_notice          = '';
	include dirname( __DIR__, 2 ) . '/admin/partials/mcpwp-setup-display.php';
}
