<?php
/**
 * Basic Elementor handler (FREE tier)
 *
 * @package MCPWP
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
	 * Operations accepted by one wp_patch_elementor call.
	 *
	 * Declared on the class rather than in the writer trait that enforces it,
	 * because trait constants are PHP 8.2+ and this plugin supports 7.4.
	 *
	 * Named at all so the enforcement and the tool schema that advertises it
	 * read the same number. A caller was surprised by this limit (#785); a
	 * limit only discoverable by exceeding it is one the client cannot check
	 * before spending the round trip.
	 */
	const MAX_PATCH_OPERATIONS = 20;

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
