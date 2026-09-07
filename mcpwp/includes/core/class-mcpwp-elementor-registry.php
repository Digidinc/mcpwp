<?php
/**
 * The one answer to "does this widget exist on this site?".
 *
 * Elementor knows exactly which widget types are registered — it has to, it
 * renders them. This plugin used to ask that question twice, in two places,
 * and get two different answers, neither of them Elementor's:
 *
 *   - The save validator merged the live registry with two hardcoded lists and
 *     accepted anything in the union. `archive-title` is in the hardcoded Pro
 *     list (it was a real widget before Elementor Pro 3.0 renamed the theme
 *     widgets to a `theme-` prefix), so a page saved with `archive-title`
 *     validated clean, reported success with an empty warnings array, and
 *     rendered no heading at all. The hardcoded list was overriding live truth
 *     rather than standing in for its absence.
 *
 *   - wp_elementor_widget_help answered from the curated reference registry
 *     alone (~52 entries). Asked about `theme-archive-title` — which exists,
 *     renders, and is the correct spelling of the widget above — it returned
 *     `unknown_widget`, no suggestions, and a list of 52 names that did not
 *     contain the answer. It pointed away from the correct spelling.
 *
 * Both are the same bug: an unavailable source and a negative answer produced
 * the same output. This class keeps them apart. live_types() returns NULL when
 * Elementor cannot be asked and an array when it can, so callers can tell
 * "not registered here" from "could not look" and say which one they mean.
 *
 * The hardcoded lists keep their job — they are the fallback for when
 * Elementor is not loaded, which is most of the unit suite. They are no longer
 * a veto over what Elementor itself reports.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Live Elementor widget registry access.
 */
class Mcpwp_Elementor_Registry {

	/**
	 * Prefixes Elementor Pro added when it renamed its theme widgets.
	 *
	 * `archive-title` became `theme-archive-title` in Elementor Pro 3.0. The
	 * old names are still the ones people (and models trained on older docs)
	 * reach for, and Levenshtein cannot bridge the gap — `archive-title` to
	 * `theme-archive-title` is a distance of 6, well past any sane threshold,
	 * so the generic suggester silently offered nothing. Trying the prefix
	 * explicitly turns the single most common wrong widget name in this
	 * plugin's bug reports into a precise suggestion.
	 *
	 * @var array
	 */
	private static $rename_prefixes = array( 'theme-' );

	/**
	 * Widget types Elementor reports as registered on this site.
	 *
	 * @return array|null Type names, or null when Elementor cannot be asked.
	 */
	public static function live_types() {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return null;
		}

		$instance = \Elementor\Plugin::$instance;

		if ( empty( $instance ) || empty( $instance->widgets_manager ) ) {
			return null;
		}

		$manager = $instance->widgets_manager;

		if ( ! method_exists( $manager, 'get_widget_types' ) ) {
			return null;
		}

		try {
			$types = $manager->get_widget_types();
		} catch ( \Throwable $e ) {
			// A registry that threw is a registry we did not read. Saying
			// "no widgets are registered" here would turn every widget on
			// the page into a warning.
			return null;
		}

		if ( ! is_array( $types ) || empty( $types ) ) {
			// Elementor with zero widget types is Elementor mid-boot, not a
			// site where nothing is registered.
			return null;
		}

		return array_keys( $types );
	}

	/**
	 * Whether Elementor has this widget type registered.
	 *
	 * @param string $widget_type Widget type name.
	 * @return bool|null True or false when the registry could be read, null when it could not.
	 */
	public static function is_registered( $widget_type ) {
		$types = self::live_types();

		if ( null === $types ) {
			return null;
		}

		return in_array( (string) $widget_type, $types, true );
	}

	/**
	 * The live control keys for a registered widget.
	 *
	 * @param string $widget_type Widget type name.
	 * @return array|null Control key names, or null when unavailable.
	 */
	public static function live_control_keys( $widget_type ) {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return null;
		}

		$instance = \Elementor\Plugin::$instance;

		if ( empty( $instance ) || empty( $instance->widgets_manager ) ) {
			return null;
		}

		try {
			$types = $instance->widgets_manager->get_widget_types();
		} catch ( \Throwable $e ) {
			return null;
		}

		if ( ! is_array( $types ) || ! isset( $types[ $widget_type ] ) ) {
			return null;
		}

		$widget = $types[ $widget_type ];

		if ( ! is_object( $widget ) || ! method_exists( $widget, 'get_controls' ) ) {
			return null;
		}

		try {
			$controls = $widget->get_controls();
		} catch ( \Throwable $e ) {
			return null;
		}

		return is_array( $controls ) ? array_keys( $controls ) : null;
	}

	/**
	 * Human-facing title and category for a registered widget, from Elementor.
	 *
	 * @param string $widget_type Widget type name.
	 * @return array|null array{title:string,category:string}, or null.
	 */
	public static function live_widget_meta( $widget_type ) {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return null;
		}

		$instance = \Elementor\Plugin::$instance;

		if ( empty( $instance ) || empty( $instance->widgets_manager ) ) {
			return null;
		}

		try {
			$types = $instance->widgets_manager->get_widget_types();
		} catch ( \Throwable $e ) {
			return null;
		}

		if ( ! is_array( $types ) || ! isset( $types[ $widget_type ] ) || ! is_object( $types[ $widget_type ] ) ) {
			return null;
		}

		$widget     = $types[ $widget_type ];
		$title      = method_exists( $widget, 'get_title' ) ? (string) $widget->get_title() : '';
		$categories = method_exists( $widget, 'get_categories' ) ? $widget->get_categories() : array();

		return array(
			'title'    => $title,
			'category' => ( is_array( $categories ) && ! empty( $categories[0] ) ) ? (string) $categories[0] : '',
		);
	}

	/**
	 * Best correction for a widget type that is not registered.
	 *
	 * Tries the rename prefixes first — an exact hit there is a fact, not a
	 * guess, and it is the case that actually shows up — then falls back to
	 * the caller's fuzzy matcher over the candidate list.
	 *
	 * @param string        $widget_type Unknown widget type.
	 * @param array         $candidates  Names to search.
	 * @param callable|null $fuzzy       Optional fn( string $input, array $candidates ): ?string.
	 * @return string|null Suggested type, or null.
	 */
	public static function suggest( $widget_type, array $candidates, $fuzzy = null ) {
		$widget_type = (string) $widget_type;

		if ( '' === $widget_type || empty( $candidates ) ) {
			return null;
		}

		$lookup = array_flip( $candidates );

		foreach ( self::$rename_prefixes as $prefix ) {
			if ( 0 === strpos( $widget_type, $prefix ) ) {
				// Already prefixed — an unregistered `theme-foo` is not fixed
				// by making it `theme-theme-foo`.
				continue;
			}

			if ( isset( $lookup[ $prefix . $widget_type ] ) ) {
				return $prefix . $widget_type;
			}
		}

		// The reverse case: a `theme-` name on a site whose Elementor still
		// registers the pre-3.0 spelling.
		foreach ( self::$rename_prefixes as $prefix ) {
			if ( 0 !== strpos( $widget_type, $prefix ) ) {
				continue;
			}

			$stripped = substr( $widget_type, strlen( $prefix ) );

			if ( '' !== $stripped && isset( $lookup[ $stripped ] ) ) {
				return $stripped;
			}
		}

		if ( is_callable( $fuzzy ) ) {
			$match = call_user_func( $fuzzy, $widget_type, $candidates );

			return is_string( $match ) && '' !== $match ? $match : null;
		}

		return null;
	}
}
