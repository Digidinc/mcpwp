<?php
/**
 * Approved admin brand marks (header badge and constants).
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin brand mark helpers.
 */
class Mcpwp_Admin_Brand {

	/** @var string Relative path to the header badge SVG. */
	public const HEADER_MARK_SVG_REL = 'assets/mcpwp-mark-ochre.svg';

	/** @var string Relative path to the header badge PNG fallback. */
	public const HEADER_MARK_PNG_256_REL = 'assets/mcpwp-mark-ochre-256.png';

	/** @var string Freemius opt-in / connect / pricing icon (square PNG). */
	public const FREEMIUS_ICON_REL = 'freemius/assets/img/plugin-icon.png';

	/** @var string SHA-256 of the approved header SVG (byte-identical master). */
	public const HEADER_MARK_SVG_SHA256 = 'd104a414a9d8538536a61a9add8c72f6edb2ec0f4d2479f284a04fe3c512d42a';

	/** @var string SHA-256 of the approved 256px PNG. */
	public const HEADER_MARK_PNG_256_SHA256 = '1891372b32a9cef2a69f3f4398f9d0d70b246210b64a07e9733d2937e08173bf';

	/**
	 * Markup for the shared admin header badge image.
	 *
	 * @return string Escaped HTML for an img inside .mcpwp-logo.
	 */
	public static function get_header_logo_img_markup() {
		$src = esc_url( MCPWP_PLUGIN_URL . self::HEADER_MARK_SVG_REL );
		return '<img class="mcpwp-logo-mark" src="' . $src . '" alt="MCPWP logo" width="42" height="42" />';
	}
}