<?php
/** Minimal activation-test stand-in for WordPress's schema helper. */

if ( ! function_exists( 'dbDelta' ) ) {
	function dbDelta( $sql ) {
		$GLOBALS['mcpwp_test_dbdelta'][] = $sql;
		return array();
	}
}
