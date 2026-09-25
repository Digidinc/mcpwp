<?php
/**
 * The one reader of an Elementor experiment's active/inactive state.
 *
 * Elementor 3.15+ ships the Container experiment (and later, Atomic
 * Elements / `e_atomic_elements`) *default-active* — a fresh install has
 * Containers on with no `elementor_experiment-<slug>` row in wp_options at
 * all. `get_option( 'elementor_experiment-container', '' )` then returns the
 * empty-string fallback, which every call site in this plugin used to read
 * as "inactive". On a fresh Elementor 4.x + WordPress install that reports
 * `elementor_layout_mode: "section"` while Elementor itself is building
 * Containers, and `wp_set_elementor` rejects container payloads with
 * "Container experiment is not active" — a false rejection, not a real
 * layout mismatch.
 *
 * Elementor's own Experiments_Manager already resolves this correctly: a
 * feature's default state is part of its registration
 * (`Experiments_Manager::add_feature()`), and `is_feature_active()` folds
 * the stored option ('active' / 'inactive' / 'default') together with that
 * registered default. Asking Elementor is the only way to get the right
 * answer without duplicating Elementor's own default table (which changes
 * across versions — Container went default-active in 3.15, Atomic Elements
 * may do the same on a future release).
 *
 * Every read of an `elementor_experiment-*` option in this plugin goes
 * through here, so "is this experiment on" has exactly one answer.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mcpwp_Elementor_Experiments {

	/**
	 * Container experiment slug (flexbox layout).
	 */
	const CONTAINER = 'container';

	/**
	 * Atomic Elements experiment slug (Editor V4).
	 */
	const ATOMIC = 'e_atomic_elements';

	/**
	 * Is the Container (flexbox) experiment active on this site?
	 *
	 * @return bool
	 */
	public static function is_container_active() {
		return self::is_active( self::CONTAINER );
	}

	/**
	 * Is the Atomic Elements (Editor V4) experiment active on this site?
	 *
	 * @return bool
	 */
	public static function is_atomic_active() {
		return self::is_active( self::ATOMIC );
	}

	/**
	 * Is the named Elementor experiment active?
	 *
	 * Prefers Elementor's own Experiments_Manager, which already folds a
	 * feature's registered default state together with whatever the site
	 * overrode in wp_options. Falls back to a raw option read only when the
	 * manager itself is unavailable (Elementor not loaded, or a version
	 * whose Experiments_Manager shape this plugin doesn't recognize) — that
	 * fallback cannot see a feature's true default and so under-reports a
	 * default-active-but-unset feature as inactive; it exists only so this
	 * never fatals, not because it is a correct substitute.
	 *
	 * @param string $slug Experiment slug, e.g. 'container', 'e_atomic_elements'.
	 * @return bool
	 */
	public static function is_active( $slug ) {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			return false;
		}

		$via_manager = self::is_active_via_manager( $slug );
		if ( null !== $via_manager ) {
			return $via_manager;
		}

		// Fallback: raw option. 'active' and the explicit 'default' choice
		// (the admin picked "Default" in the Experiments UI, which Elementor
		// then resolves per its own registered default) both count. An
		// entirely absent option — the common fresh-install case for a
		// default-active feature — cannot be distinguished from "inactive"
		// here; that gap is exactly why the manager path above is tried
		// first.
		$option = get_option( 'elementor_experiment-' . $slug, '' );
		return in_array( $option, array( 'active', 'default' ), true );
	}

	/**
	 * Ask Elementor's Experiments_Manager directly.
	 *
	 * @param string $slug Experiment slug.
	 * @return bool|null True/false when the manager answered, null when it
	 *                    could not be reached (class/instance/method missing
	 *                    or it threw), so the caller knows to fall back.
	 */
	private static function is_active_via_manager( $slug ) {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return null;
		}

		$plugin = \Elementor\Plugin::$instance;
		if ( empty( $plugin ) || empty( $plugin->experiments ) || ! is_object( $plugin->experiments )
			|| ! method_exists( $plugin->experiments, 'is_feature_active' ) ) {
			return null;
		}

		try {
			return (bool) $plugin->experiments->is_feature_active( $slug );
		} catch ( \Throwable $e ) {
			return null;
		}
	}
}
