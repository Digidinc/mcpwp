<?php
/**
 * Core functionality
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core class with shared functionality.
 */
class Mcpwp_Core {

	use Mcpwp_Api_Auth;
	use Mcpwp_Sanitization;
	use Mcpwp_Logging;

	/**
	 * Get site information.
	 *
	 * @return array Site info.
	 */
	public function get_site_info() {
		global $wp_version;

		$theme = wp_get_theme();

		$info = array(
			'name'         => get_bloginfo( 'name' ),
			'description'  => get_bloginfo( 'description' ),
			'url'          => home_url(),
			'admin_url'    => admin_url(),
			'wp_version'   => $wp_version,
			'php_version'  => PHP_VERSION,
			'theme'        => array(
				'name'    => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ),
			),
			'timezone'     => wp_timezone_string(),
			'language'     => get_locale(),
			'is_rtl'       => function_exists( 'is_rtl' ) ? is_rtl() : false,
			'text_direction' => ( function_exists( 'is_rtl' ) && is_rtl() ) ? 'rtl' : 'ltr',
			'capabilities' => $this->get_capabilities(),
			'plugin'       => array(
				'name'    => 'MCPWP',
				'version' => MCPWP_VERSION,
			),
		);

		$license_info = class_exists( 'Mcpwp_License' )
			? Mcpwp_License::get_instance()->get_license_info()
			: array(
				'plan'   => 'unlicensed',
				'is_pro' => false,
			);

		$info['license'] = array(
			'plan'   => $license_info['plan'],
			'is_pro' => $license_info['is_pro'],
		);

		return $info;
	}

	/**
	 * Get site capabilities (detected plugins).
	 *
	 * @return array Capabilities.
	 */
	public function get_capabilities() {
		$cached = get_transient( 'mcpwp_capabilities_cache' );
		if ( false !== $cached ) {
			if ( $this->capabilities_cache_matches_license( $cached ) ) {
				return $cached;
			}

			delete_transient( 'mcpwp_capabilities_cache' );
		}

		$rankmath_active = defined( 'RANK_MATH_VERSION' )
			|| defined( 'RANK_MATH_FILE' )
			|| class_exists( 'RankMath\\Helper' )
			|| class_exists( 'RankMath\\Loader' );

		// Detect Elementor layout mode (section / container / atomic).
		// (#619) Atomic (Editor V4, elementor/modules/atomic-widgets, experiment
		// slug 'e_atomic_elements') coexists with legacy section/container content
		// on the same site — it's additive, not a replacement. Report it as the
		// primary mode when active (agents should build atomic on a V4 site), but
		// also expose a standalone flag so callers that only care about legacy
		// section-vs-container detection aren't broken by a new string value.
		$elementor_layout        = 'section';
		$elementor_atomic_active = false;
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			// Elementor 3.15+ uses containers by default; check experiment setting.
			$experiments = get_option( 'elementor_experiment-container', '' );
			if ( 'active' === $experiments || 'default' === $experiments ) {
				$elementor_layout = 'container';
			}

			$atomic_experiment = get_option( 'elementor_experiment-e_atomic_elements', '' );
			if ( 'active' === $atomic_experiment || 'default' === $atomic_experiment ) {
				$elementor_atomic_active = true;
				$elementor_layout        = 'atomic';
			}
		}

		// Detect Gutenberg (block editor) availability.
		// Gutenberg is built into WP 5.0+. It's unavailable only when the
		// Classic Editor plugin forces classic mode for all post types.
		$gutenberg_active = function_exists( 'register_block_type' );
		if ( $gutenberg_active ) {
			$classic_option = get_option( 'classic-editor-replace', 'no-replace' );
			if ( 'replace' === $classic_option ) {
				$gutenberg_active = false;
			}
		}

		$capabilities = array(
			'gutenberg'             => $gutenberg_active,
			'elementor'             => defined( 'ELEMENTOR_VERSION' ),
			'elementor_pro'         => defined( 'ELEMENTOR_PRO_VERSION' ),
			'elementor_layout_mode' => $elementor_layout,
			'elementor_atomic_active' => $elementor_atomic_active,
			'woocommerce'    => class_exists( 'WooCommerce' ),
			'yoast'          => defined( 'WPSEO_VERSION' ),
			'rankmath'       => $rankmath_active,
			'aioseo'         => defined( 'AIOSEO_VERSION' ),
			'seopress'       => defined( 'SEOPRESS_VERSION' ),
			'cf7'            => class_exists( 'WPCF7' ),
			'wpforms'        => class_exists( 'WPForms' ),
			'gravityforms'   => class_exists( 'GFForms' ),
			'ninjaforms'     => class_exists( 'Ninja_Forms' ),
			'learnpress'          => defined( 'LEARNPRESS_VERSION' ) || class_exists( 'LP' ),
			'is_multisite'        => is_multisite(),
			'network_site_count'  => is_multisite() ? get_blog_count() : null,
		);

		// Allow premium package to extend capabilities (e.g., pro-module-only flags).
		if ( function_exists( 'apply_filters' ) ) {
			$capabilities = apply_filters( 'mcpwp_site_capabilities', $capabilities );
		}

		// Single source of truth for plan / pro_active. Derive these from the
		// canonical license accessor AFTER the filter so they cannot be made
		// inconsistent by other consumers.
		if ( class_exists( 'Mcpwp_License' ) ) {
			$license_info               = Mcpwp_License::get_instance()->get_license_info();
			$capabilities['plan']       = $license_info['plan'];
			$capabilities['pro_active'] = $license_info['is_pro'];
		} else {
			$capabilities['plan']       = 'unlicensed';
			$capabilities['pro_active'] = false;
		}

		// Merge capabilities from third-party integrations.
		if ( class_exists( 'Mcpwp_Integration' ) ) {
			foreach ( Mcpwp_Integration::resolve_all() as $integration ) {
				$capabilities = array_merge( $capabilities, $integration->get_capabilities() );
			}
		}

		// Cache for 1 hour
		set_transient( 'mcpwp_capabilities_cache', $capabilities, HOUR_IN_SECONDS );

		return $capabilities;
	}

	/**
	 * Clear the cached capabilities.
	 *
	 * Hooked to activated_plugin / deactivated_plugin so that enabling or
	 * disabling an integration (WooCommerce, LearnPress, an SEO plugin, …) is
	 * reflected in the capabilities response immediately, instead of being
	 * masked by the 1-hour transient TTL. Without this, an agent reading
	 * `capabilities` after a plugin is activated sees stale flags and is blind
	 * to tools that are actually available.
	 *
	 * @return void
	 */
	public static function clear_capabilities_cache() {
		delete_transient( 'mcpwp_capabilities_cache' );
	}

	/**
	 * Check whether cached capabilities still match the current license state.
	 *
	 * @param mixed $cached Cached capabilities.
	 * @return bool True when cache can be reused.
	 */
	private function capabilities_cache_matches_license( $cached ) {
		if ( ! is_array( $cached ) || ! class_exists( 'Mcpwp_License' ) ) {
			return true;
		}

		$license = Mcpwp_License::get_instance();
		if ( ! $license ) {
			return true;
		}

		$license_info   = $license->get_license_info();
		$current_plan   = $license_info['plan'];
		$current_is_pro = $license_info['is_pro'];

		if ( isset( $cached['plan'] ) && $cached['plan'] !== $current_plan ) {
			return false;
		}

		if ( isset( $cached['pro_active'] ) && (bool) $cached['pro_active'] !== (bool) $current_is_pro ) {
			return false;
		}

		return true;
	}

	/**
	 * Get analytics data.
	 *
	 * @param int $days Number of days.
	 * @return array Analytics data.
	 */
	public function get_analytics( $days = 30 ) {
		global $wpdb;

		$table = $wpdb->prefix . 'mcpwp_activity_log';
		$since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$total_requests = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $table WHERE created_at >= %s",
				$since
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$by_action = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT action, COUNT(*) as count FROM $table WHERE created_at >= %s GROUP BY action ORDER BY count DESC LIMIT 10",
				$since
			),
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$by_day = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) as date, COUNT(*) as count FROM $table WHERE created_at >= %s GROUP BY DATE(created_at) ORDER BY date DESC",
				$since
			),
			ARRAY_A
		);

		// wpdb returns aggregate columns as strings — cast so typed JSON
		// clients get numbers (#535).
		foreach ( $by_action as &$row ) {
			$row['count'] = (int) ( $row['count'] ?? 0 );
		}
		unset( $row );
		foreach ( $by_day as &$row ) {
			$row['count'] = (int) ( $row['count'] ?? 0 );
		}
		unset( $row );

		return array(
			'period_days'    => $days,
			'total_requests' => (int) $total_requests,
			'by_action'      => $by_action,
			'by_day'         => $by_day,
		);
	}

	/**
	 * Detect installed plugins with capabilities.
	 *
	 * @return array Plugin info.
	 */
	public function detect_plugins() {
		$plugins = array();
		$capabilities = $this->get_capabilities();

		// Elementor
		if ( $capabilities['elementor'] ) {
			$plugins['elementor'] = array(
				'name'    => 'Elementor',
				'version' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : 'unknown',
				'pro'     => $capabilities['elementor_pro'],
			);
		}

		// SEO plugins
		$seo_plugins = array(
			'yoast'    => array( 'name' => 'Yoast SEO', 'const' => 'WPSEO_VERSION' ),
			'rankmath' => array( 'name' => 'RankMath', 'const' => 'RANK_MATH_VERSION' ),
			'aioseo'   => array( 'name' => 'All in One SEO', 'const' => 'AIOSEO_VERSION' ),
			'seopress' => array( 'name' => 'SEOPress', 'const' => 'SEOPRESS_VERSION' ),
		);

		foreach ( $seo_plugins as $key => $info ) {
			if ( $capabilities[ $key ] ) {
				$version = 'unknown';
				if ( isset( $info['const'] ) && defined( $info['const'] ) ) {
					$version = constant( $info['const'] );
				}
				$plugins['seo'] = array(
					'name'    => $info['name'],
					'version' => $version,
					'slug'    => $key,
				);
				break;
			}
		}

		// Form plugins
		$form_plugins = array(
			'cf7'          => array( 'name' => 'Contact Form 7', 'const' => 'WPCF7_VERSION' ),
			'wpforms'      => array( 'name' => 'WPForms', 'const' => 'WPFORMS_VERSION' ),
			'gravityforms' => array( 'name' => 'Gravity Forms', 'class' => 'GFForms' ),
			'ninjaforms'   => array( 'name' => 'Ninja Forms', 'const' => 'NINJA_FORMS_VERSION' ),
		);

		$plugins['forms'] = array();
		foreach ( $form_plugins as $key => $info ) {
			if ( $capabilities[ $key ] ) {
				$version = 'unknown';
				if ( isset( $info['const'] ) && defined( $info['const'] ) ) {
					$version = constant( $info['const'] );
				}
				$plugins['forms'][] = array(
					'name'    => $info['name'],
					'version' => $version,
					'slug'    => $key,
				);
			}
		}

		// WooCommerce
		if ( $capabilities['woocommerce'] ) {
			$plugins['woocommerce'] = array(
				'name'    => 'WooCommerce',
				'version' => defined( 'WC_VERSION' ) ? WC_VERSION : 'unknown',
			);
		}

		// LearnPress
		if ( ! empty( $capabilities['learnpress'] ) ) {
			$plugins['learnpress'] = array(
				'name'    => 'LearnPress',
				'version' => defined( 'LEARNPRESS_VERSION' ) ? LEARNPRESS_VERSION : 'unknown',
			);
		}

		return $plugins;
	}
}
