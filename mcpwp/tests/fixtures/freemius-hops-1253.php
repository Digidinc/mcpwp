<?php
/**
 * Fixtures for SetupLicenseSdkHops1253Test. Needs the real Freemius SDK class loaded first.
 *
 * The two gate functions below are VERBATIM copies of the ones in includes/freemius-init.php,
 * which cannot be included without a live SDK. The test asserts the copies are identical to the
 * originals, so this file cannot drift from what ships.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Minimal stand-ins for the SDK's menu manager and logger. */
class Mcpwp_Test_Menu_1253 {
	public function get_slug( $page = '' ) {
		return 'mcpwp' . ( '' === $page ? '' : '-' . $page );
	}
	public function is_top_level() {
		return true;
	}
	public function is_cpt() {
		return false;
	}
}

class Mcpwp_Test_Logger_1253 {
	public function entrance( ...$args ) {
	}
}

/** The REAL Freemius class; only the plumbing that needs a live install is overridden. */
class Mcpwp_Test_Real_Fs_1253 extends Freemius {
	public function has_settings_menu() {
		return true;
	}
	public function show_opt_in_on_themes_page() {
		return false;
	}
	public function is_activation_mode( $and_on = true ) {
		return true;
	}
}

// fs_apply_filter exactly as the SDK defines it (freemius/includes/fs-core-functions.php).
if ( ! function_exists( 'fs_apply_filter' ) ) {
	function fs_apply_filter( $module_unique_affix, $tag, $value ) {
		$args = func_get_args();

		return call_user_func_array( 'apply_filters', array_merge(
				array( "fs_{$tag}_{$module_unique_affix}" ),
				array_slice( $args, 2 ) )
		);
	}
}

function mcpwp_fs_soften_connect_gate( $url ) {
	if ( false !== strpos( $url, 'fs_action=reset_anonymous_mode' )
		|| get_transient( 'mcpwp_fs_reconnect_intent' )
	) {
		return $url;
	}
	return add_query_arg( 'require_license', 'false', $url );
}

function mcpwp_fs_mark_reconnect_intent() {
	if ( isset( $_GET['fs_action'] ) && 'reset_anonymous_mode' === $_GET['fs_action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check, mirrors Freemius's own unauthenticated dispatch check.
		set_transient( 'mcpwp_fs_reconnect_intent', true, 60 );
	}
}
