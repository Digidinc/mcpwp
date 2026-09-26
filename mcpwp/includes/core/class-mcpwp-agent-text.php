<?php
/**
 * Agent-facing text normalization.
 *
 * WordPress stores blogname/post_title HTML-entity-encoded — correct for a
 * browser that echoes the value straight into markup (get_bloginfo()'s
 * default 'raw' filter returns the option verbatim; a post title saved
 * through wp_insert_post()/the title field is likewise stored with a
 * literal "&" already turned into "&amp;"). That convention exists so a
 * theme's `<?php bloginfo('name'); ?>` or `the_title()` never needs its own
 * escaping — nothing downstream unescapes it for a plain-text reader. An AI
 * agent calling the MCP API is exactly that plain-text reader: it sees the
 * literal entity text ("Crumb &amp; Co") instead of the name the site owner
 * typed (#903).
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decodes HTML entities in isolated title/name fields for agent-facing
 * output.
 *
 * Only ever call this on a title/name-shaped string — never on an HTML body
 * (post content, excerpts, kit CSS, etc.). '&amp;' can legitimately be
 * literal text an author wrote inside a body, and decoding it there would
 * corrupt the body instead of fixing a name. This class changes nothing
 * about what is stored (the DB keeps the entity-encoded form) or what a
 * visitor's browser renders (theme output is untouched) — it only decodes
 * the copy of the value handed back through the MCPWP REST/MCP surface.
 */
class Mcpwp_Agent_Text {

	/**
	 * Decode HTML entities in a title/name string.
	 *
	 * @param mixed $value Raw value. Non-strings pass through unchanged.
	 * @return mixed Decoded string, or the original value unchanged when it
	 *               is not a non-empty string.
	 */
	public static function decode( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}

		return html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
