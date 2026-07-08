<?php
/**
 * Basic Elementor handler (FREE tier)
 *
 * @package mumcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handle basic Elementor operations.
 *
 * FREE tier includes:
 * - Get Elementor data for a page
 * - Set Elementor data for a page
 * - Check Elementor status
 *
 * PRO tier includes (in separate plugin):
 * - Templates
 * - Landing pages
 * - Widgets
 * - Globals
 * - Clone pages
 */
class Mcpwp_Elementor_Basic {

	/**
	 * Check if Elementor is active.
	 *
	 * @return bool True if Elementor is active.
	 */
	use Mcpwp_Elementor_Reader_Trait;
	use Mcpwp_Elementor_Writer_Trait;
	use Mcpwp_Elementor_Validator_Trait;
	use Mcpwp_Elementor_Css_Trait;

}
