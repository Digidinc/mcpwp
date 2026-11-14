<?php
/**
 * get_current_screen() for a test that runs in its own process. Not loaded
 * by the bootstrap: code elsewhere treats a missing get_current_screen() as
 * "no screen", and defining it for the whole suite would change that.
 *
 * @package MCPWP
 */

if ( ! function_exists( 'get_current_screen' ) ) {
	function get_current_screen() {
		return (object) array( 'id' => isset( $GLOBALS['mcpwp_test_screen_id'] ) ? (string) $GLOBALS['mcpwp_test_screen_id'] : '' );
	}
}
