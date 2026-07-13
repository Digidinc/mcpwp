<?php
/** Local site information core. @package mumcp */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Mcpwp_Core {
	public function get_site_info() {
		return array( 'name' => get_bloginfo( 'name' ), 'url' => get_site_url(), 'language' => get_bloginfo( 'language' ), 'timezone' => wp_timezone_string(), 'plugin' => array( 'name' => 'Mumega MCP', 'version' => MCPWP_VERSION ) );
	}
}
