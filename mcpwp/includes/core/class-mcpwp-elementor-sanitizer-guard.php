<?php
/**
 * Elementor content-sanitizer suspend/restore — shared, single definition.
 *
 * Extracted out of Mcpwp_Elementor_Writer_Trait (#828 part 2 / #759 residual
 * gap) so any write path that hands its own element tree straight to
 * Elementor's Document::save() can neutralise the same upstream defect the
 * trait already neutralises for Mcpwp_Elementor_Basic, without copying the
 * logic.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Suspends and restores Elementor's own content-sanitizer module around a
 * single Document::save() call.
 *
 * Elementor's content-sanitizer (modules/content-sanitizer/module.php) hooks
 * elementor/document/save/data, so it runs INSIDE Document::save(). Its
 * is_target_widget() reads $element['widgetType'] unguarded while iterating
 * every element — sections, columns and containers have none, so each one
 * raises an "Undefined array key" warning, fatal wherever warnings are
 * promoted to exceptions. It also early-returns for anyone with
 * manage_options, so a browser admin never reaches it and an API-key (or any
 * non-admin) write always does — see #759 for the full reproduction and
 * #769 for the sibling data-corruption defect in the same callback.
 *
 * Suspending it changes nothing a visitor sees: the caller has already
 * written (or is about to write) the verified _elementor_data from its own
 * bytes, and Elementor's frontend renders from that meta. The sanitizer's
 * output only ever reaches the render cache being rebuilt.
 *
 * Usage:
 *
 *     $suspended = Mcpwp_Elementor_Sanitizer_Guard::suspend();
 *     try {
 *         $document->save( array( 'elements' => $elements ) );
 *     } finally {
 *         Mcpwp_Elementor_Sanitizer_Guard::restore( $suspended );
 *     }
 */
class Mcpwp_Elementor_Sanitizer_Guard {

	/**
	 * Unhook Elementor's content-sanitizer for the duration of one save.
	 *
	 * Returns the callback so the caller can hook it straight back on via
	 * restore(). Returns null when the module is not present or not hooked,
	 * in which case nothing was changed and restore() is a no-op.
	 *
	 * @return callable|null The removed callback, or null if nothing was removed.
	 */
	public static function suspend() {
		if ( ! class_exists( '\Elementor\Plugin' ) || empty( \Elementor\Plugin::$instance->modules_manager ) ) {
			return null;
		}

		try {
			$module = \Elementor\Plugin::$instance->modules_manager->get_modules( 'content-sanitizer' );
		} catch ( \Throwable $e ) {
			return null;
		}

		if ( ! is_object( $module ) || ! method_exists( $module, 'sanitize_content' ) ) {
			return null;
		}

		$callback = array( $module, 'sanitize_content' );

		if ( ! remove_filter( 'elementor/document/save/data', $callback, 10 ) ) {
			return null;
		}

		return $callback;
	}

	/**
	 * Re-hook the callback suspend() removed. Always call this from a
	 * `finally` block so a Throwable from the guarded save cannot leave the
	 * sanitizer permanently unhooked for the rest of the request.
	 *
	 * @param callable|null $callback The value suspend() returned.
	 */
	public static function restore( $callback ) {
		if ( null === $callback ) {
			return;
		}

		add_filter( 'elementor/document/save/data', $callback, 10, 2 );
	}
}
