<?php
/**
 * Lightweight, always-loaded detection of the four SEO plugins MCPWP
 * coexists with (Yoast, Rank Math, AIOSEO, SEOPress).
 *
 * `Mcpwp_SEO` (includes/pro/core/class-mcpwp-seo.php) already has near-
 * identical checks, but it is a Pro-only class loaded by
 * Mcpwp_Pro_Bootstrap. The AI-presence surface (llms.txt, robots.txt, the
 * AI crawler policy, IndexNow) runs on every tier, so detection needs a
 * copy that is always available. Kept intentionally tiny — presence-only,
 * no meta reading.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static presence checks for the SEO plugins MCPWP coexists with.
 */
class Mcpwp_SEO_Plugin_Detect {

	/**
	 * The `mcpwp_seo_plugin_active_override` filter value, once, or null
	 * when unset. Shared by every is_X_active() check below AND by
	 * active_plugin_slug() so a test (or a site correcting a detection
	 * edge case) has exactly one lever, consistently honored everywhere —
	 * not just by the coarse any_active()/active_plugin_slug() pair. See
	 * active_plugin_slug()'s docblock for why this exists instead of
	 * defining/undefining a real plugin constant.
	 *
	 * @return string|null
	 */
	private static function override() {
		return apply_filters( 'mcpwp_seo_plugin_active_override', null );
	}

	/**
	 * @return bool
	 */
	public static function is_yoast_active() {
		$override = self::override();
		if ( null !== $override ) {
			return 'yoast' === $override;
		}
		return defined( 'WPSEO_VERSION' );
	}

	/**
	 * @return bool
	 */
	public static function is_rankmath_active() {
		$override = self::override();
		if ( null !== $override ) {
			return 'rankmath' === $override;
		}
		return class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' );
	}

	/**
	 * @return bool
	 */
	public static function is_aioseo_active() {
		$override = self::override();
		if ( null !== $override ) {
			return 'aioseo' === $override;
		}
		return defined( 'AIOSEO_VERSION' );
	}

	/**
	 * @return bool
	 */
	public static function is_seopress_active() {
		$override = self::override();
		if ( null !== $override ) {
			return 'seopress' === $override;
		}
		return defined( 'SEOPRESS_VERSION' );
	}

	/**
	 * True if any of the four coexistence-partner SEO plugins is active.
	 *
	 * @return bool
	 */
	public static function any_active() {
		return '' !== self::active_plugin_slug();
	}

	/**
	 * The active plugin's slug, or '' when none is active. When more than
	 * one is somehow active (misconfiguration), the first match in this
	 * fixed order wins — callers only need "is someone else already
	 * handling this", not a ranking.
	 *
	 * `mcpwp_seo_plugin_active_override` lets a test (or a site with a
	 * detection edge case a `defined()` check can't see) force the answer
	 * without touching a real plugin constant, which — being a PHP
	 * constant — can never be un-defined once set for the rest of the
	 * process. Return '' to force "none active", a slug to force that one,
	 * or null (default) to run the real detection below.
	 *
	 * @return string
	 */
	public static function active_plugin_slug() {
		$override = self::override();
		if ( null !== $override ) {
			return (string) $override;
		}

		if ( self::is_yoast_active() ) {
			return 'yoast';
		}
		if ( self::is_rankmath_active() ) {
			return 'rankmath';
		}
		if ( self::is_aioseo_active() ) {
			return 'aioseo';
		}
		if ( self::is_seopress_active() ) {
			return 'seopress';
		}
		return '';
	}
}
