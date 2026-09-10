<?php
/**
 * Store Elementor meta exactly as MCPWP built it.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes `_elementor_data` and `_elementor_page_settings` past Elementor's REST
 * meta sanitizer (#829).
 *
 * Elementor 4.2.x registers both keys (modules/wp-rest/classes/
 * elementor-post-meta.php, on rest_api_init) with a sanitize_callback that
 * returns the value untouched for a user with `unfiltered_html` and runs
 * wp_kses_post() over every string otherwise. update_metadata() calls
 * sanitize_meta() on every write, so inside a REST request — every MCPWP call
 * is one — a write made as the API user (the mcpwp_api_agent role has no
 * `unfiltered_html`) stored `.a>.b` as `.a&gt;.b`. That is not HTML safety; it
 * is a different stylesheet, and the rule stops matching.
 *
 * Reproduced on WordPress 7.0.2 + Elementor 4.2.4 over HTTP: every Kit CSS
 * block write came back escaped, so the byte-for-byte repair added for #762
 * fired — and reported storage_repaired:true — on every write; and a section's
 * custom_css `selector .a > .b{}` and a heading title "A > B" were stored
 * escaped under a success response, with nothing comparing the bytes.
 *
 * Outside REST the callback is not registered. That is why update_post_meta()
 * called from wp-cli round-tripped cleanly and the #762 investigation did not
 * find the layer.
 *
 * The fix is the condition Elementor's own callback checks: the writing user is
 * treated as having `unfiltered_html` while — and only while — this key's
 * sanitize filters run for this one write. The grant opens at PHP_INT_MIN on
 * that filter and closes at PHP_INT_MAX, and every filter is removed in a
 * `finally`, so nothing else in the request sees the capability.
 *
 * Why that widens nothing:
 *  - Elementor's Document::save() already writes these same keys unfiltered for
 *    MCPWP, under the scoped grant attempt_elementor_call() installs (#769).
 *  - Kit CSS already reached the row byte-for-byte: a write that came back
 *    altered was rewritten directly through $wpdb (#762).
 *  - What may be written at all is decided by the API key's scope and each
 *    tool's own checks (Kit custom_css needs an admin-scoped key), not by a
 *    sanitizer that only ever ran for API writes and never in an admin's own
 *    editor.
 */
final class Mcpwp_Elementor_Meta_Write {

	/**
	 * Keys Elementor stores element trees and document settings under.
	 */
	const KEYS = array( '_elementor_data', '_elementor_page_settings' );

	/**
	 * Nesting depth of update(); filters are installed by the outermost call.
	 *
	 * @var int
	 */
	private static $depth = 0;

	/**
	 * The user the grant is for.
	 *
	 * @var int
	 */
	private static $user_id = 0;

	/**
	 * Whether the grant is live (inside this key's sanitize filters).
	 *
	 * @var bool
	 */
	private static $active = false;

	/**
	 * update_post_meta() for an Elementor key, storing the value as given.
	 *
	 * Any other key, or no logged-in user, is a plain update_post_meta().
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 * @param mixed  $value    Value, slashed as update_post_meta() expects.
	 * @return int|bool What update_post_meta() returned.
	 */
	public static function update( $post_id, $meta_key, $value ) {
		$post_id  = (int) $post_id;
		$meta_key = (string) $meta_key;
		$user_id  = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		if ( ! in_array( $meta_key, self::KEYS, true ) || $user_id < 1 || self::$depth > 0 ) {
			return update_post_meta( $post_id, $meta_key, $value );
		}

		// sanitize_meta() applies the subtype filter when one is hooked, the
		// generic one otherwise; cover both.
		$hooks     = array( "sanitize_post_meta_{$meta_key}" );
		$post_type = function_exists( 'get_post_type' ) ? get_post_type( $post_id ) : '';
		if ( is_string( $post_type ) && '' !== $post_type ) {
			$hooks[] = "sanitize_post_meta_{$meta_key}_for_{$post_type}";
		}

		self::$user_id = $user_id;
		foreach ( $hooks as $hook ) {
			add_filter( $hook, array( __CLASS__, 'open_grant' ), PHP_INT_MIN, 1 );
			add_filter( $hook, array( __CLASS__, 'close_grant' ), PHP_INT_MAX, 1 );
		}
		++self::$depth;

		try {
			return update_post_meta( $post_id, $meta_key, $value );
		} finally {
			--self::$depth;
			foreach ( $hooks as $hook ) {
				remove_filter( $hook, array( __CLASS__, 'open_grant' ), PHP_INT_MIN );
				remove_filter( $hook, array( __CLASS__, 'close_grant' ), PHP_INT_MAX );
			}
			self::close_grant( null );
			self::$user_id = 0;
		}
	}

	/**
	 * First filter on the key's sanitize hook: open the grant.
	 *
	 * @param mixed $value Meta value, passed through untouched.
	 * @return mixed
	 */
	public static function open_grant( $value ) {
		if ( ! self::$active && self::$user_id > 0 ) {
			self::$active = true;
			add_filter( 'map_meta_cap', array( __CLASS__, 'map_cap' ), PHP_INT_MAX, 4 );
			add_filter( 'user_has_cap', array( __CLASS__, 'grant_cap' ), PHP_INT_MAX, 4 );
		}

		return $value;
	}

	/**
	 * Last filter on the key's sanitize hook: close the grant.
	 *
	 * @param mixed $value Meta value, passed through untouched.
	 * @return mixed
	 */
	public static function close_grant( $value ) {
		if ( self::$active ) {
			remove_filter( 'map_meta_cap', array( __CLASS__, 'map_cap' ), PHP_INT_MAX );
			remove_filter( 'user_has_cap', array( __CLASS__, 'grant_cap' ), PHP_INT_MAX );
			self::$active = false;
		}

		return $value;
	}

	/**
	 * Keep `unfiltered_html` reachable while the grant is live.
	 *
	 * On multisite (and under DISALLOW_UNFILTERED_HTML) map_meta_cap() turns it
	 * into do_not_allow before user_has_cap is consulted, which would make the
	 * grant a silent no-op there — the same reasoning as the writer's #769
	 * grant.
	 *
	 * @param string[] $caps    Mapped primitive capabilities.
	 * @param string   $cap     Capability being checked.
	 * @param int      $user_id User the check is for.
	 * @param array    $args    Extra arguments.
	 * @return string[]
	 */
	public static function map_cap( $caps, $cap = '', $user_id = 0, $args = array() ) {
		if ( ! self::$active || 'unfiltered_html' !== $cap || (int) $user_id !== self::$user_id ) {
			return $caps;
		}

		return array( 'unfiltered_html' );
	}

	/**
	 * Grant `unfiltered_html` to the writing user while the grant is live.
	 *
	 * @param array    $allcaps All capabilities of the user being tested.
	 * @param string[] $caps    Required primitive capabilities.
	 * @param array    $args    Arguments of the check.
	 * @param \WP_User $user    The user being tested.
	 * @return array
	 */
	public static function grant_cap( $allcaps, $caps = array(), $args = array(), $user = null ) {
		if ( ! self::$active || ! is_array( $allcaps ) ) {
			return $allcaps;
		}

		$user_id = ( $user instanceof \WP_User ) ? (int) $user->ID : 0;
		if ( $user_id < 1 || $user_id !== self::$user_id ) {
			return $allcaps;
		}

		$allcaps['unfiltered_html'] = true;

		return $allcaps;
	}
}
