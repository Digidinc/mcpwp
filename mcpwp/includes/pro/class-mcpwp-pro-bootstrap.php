<?php
/**
 * Pro Module Bootstrap
 *
 * Loads additional endpoints/features for licensed paid plans and trials.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pro bootstrap class.
 */
class Mcpwp_Pro_Bootstrap {

	/**
	 * Register hooks for pro features.
	 */
	public static function init() {
		add_action( 'mcpwp_register_rest_routes', array( __CLASS__, 'register_routes' ) );
		add_filter( 'mcpwp_site_capabilities', array( __CLASS__, 'add_pro_capabilities' ) );

		// #952: before_delete_post must fire for EVERY elementor_library
		// delete — wp-admin's post-delete screen, WP-CLI, another plugin —
		// not only the ones reachable through this plugin's own REST
		// routes. register_routes() below only runs while WordPress is
		// actually serving a /wp-json/ request: mcpwp_register_rest_routes
		// is fired from Mcpwp_Loader::register_rest_routes(), which is
		// itself wired to the REST-only rest_api_init action (fired lazily
		// from rest_get_server(), never on a plain admin/CLI/front-end
		// request). init() itself runs on plugins_loaded, which fires on
		// every request, so the hook has to go here instead.
		require_once MCPWP_PLUGIN_DIR . 'includes/pro/core/class-mcpwp-theme-builder.php';
		add_action( 'before_delete_post', array( new Mcpwp_Theme_Builder(), 'handle_before_delete_post' ), 10, 2 );
	}

	/**
	 * Register Pro REST API routes.
	 */
	public static function register_routes() {
		if ( ! class_exists( 'Mcpwp_REST_API' ) ) {
			return;
		}

		// Core handlers.
		require_once MCPWP_PLUGIN_DIR . 'includes/pro/core/class-mcpwp-elementor-pro.php';
		require_once MCPWP_PLUGIN_DIR . 'includes/pro/core/class-mcpwp-seo.php';
		require_once MCPWP_PLUGIN_DIR . 'includes/pro/core/class-mcpwp-forms.php';
		require_once MCPWP_PLUGIN_DIR . 'includes/pro/core/class-mcpwp-site-manager.php';
		require_once MCPWP_PLUGIN_DIR . 'includes/pro/core/class-mcpwp-theme-builder.php';
		require_once MCPWP_PLUGIN_DIR . 'includes/pro/core/class-mcpwp-users.php';
		require_once MCPWP_PLUGIN_DIR . 'includes/pro/core/class-mcpwp-widgets.php';
		require_once MCPWP_PLUGIN_DIR . 'includes/pro/core/class-mcpwp-themes.php';
		require_once MCPWP_PLUGIN_DIR . 'includes/pro/core/class-mcpwp-woocommerce.php';
		require_once MCPWP_PLUGIN_DIR . 'includes/pro/core/class-mcpwp-multilang.php';
		require_once MCPWP_PLUGIN_DIR . 'includes/pro/core/class-mcpwp-page-builder.php';
		require_once MCPWP_PLUGIN_DIR . 'includes/pro/core/class-mcpwp-google-indexing.php';
		require_once MCPWP_PLUGIN_DIR . 'includes/pro/core/class-mcpwp-learnpress.php';
		require_once MCPWP_PLUGIN_DIR . 'includes/pro/core/class-mcpwp-events.php';
		require_once MCPWP_PLUGIN_DIR . 'includes/pro/core/class-mcpwp-staging.php';

		// REST controllers.
		require_once MCPWP_PLUGIN_DIR . 'includes/pro/api/class-mcpwp-rest-elementor-pro.php';
		require_once MCPWP_PLUGIN_DIR . 'includes/pro/api/class-mcpwp-rest-seo.php';
		require_once MCPWP_PLUGIN_DIR . 'includes/pro/api/class-mcpwp-rest-forms.php';
		require_once MCPWP_PLUGIN_DIR . 'includes/pro/api/class-mcpwp-rest-site-manager.php';
		require_once MCPWP_PLUGIN_DIR . 'includes/pro/api/class-mcpwp-rest-theme-builder.php';
		require_once MCPWP_PLUGIN_DIR . 'includes/pro/api/class-mcpwp-rest-users.php';
		require_once MCPWP_PLUGIN_DIR . 'includes/pro/api/class-mcpwp-rest-widgets.php';
		require_once MCPWP_PLUGIN_DIR . 'includes/pro/api/class-mcpwp-rest-themes.php';
		require_once MCPWP_PLUGIN_DIR . 'includes/pro/api/class-mcpwp-rest-woocommerce.php';
		require_once MCPWP_PLUGIN_DIR . 'includes/pro/api/class-mcpwp-rest-multilang.php';
		require_once MCPWP_PLUGIN_DIR . 'includes/pro/api/class-mcpwp-rest-google-indexing.php';
		require_once MCPWP_PLUGIN_DIR . 'includes/pro/api/class-mcpwp-rest-learnpress.php';
		require_once MCPWP_PLUGIN_DIR . 'includes/pro/api/class-mcpwp-rest-events.php';
		require_once MCPWP_PLUGIN_DIR . 'includes/pro/api/class-mcpwp-rest-staging.php';

		$elementor_pro = new Mcpwp_Elementor_Pro();
		$seo           = new Mcpwp_SEO();
		$forms         = new Mcpwp_Forms();
		$site_manager  = new Mcpwp_Site_Manager();
		$theme_builder = new Mcpwp_Theme_Builder();
		$users         = new Mcpwp_Users();
		$widgets       = new Mcpwp_Widgets();
		$themes        = new Mcpwp_Themes();
		$woocommerce   = new Mcpwp_WooCommerce();
		$multilang     = new Mcpwp_Multilang();
		$google_indexing = new Mcpwp_Google_Indexing();
		$learnpress      = new Mcpwp_LearnPress();
		$events          = new Mcpwp_Events();
		$staging         = new Mcpwp_Staging();

		// #952's before_delete_post hook is registered in init() above,
		// not here — see the comment there for why.

		( new Mcpwp_REST_Elementor_Pro( $elementor_pro ) )->register_routes();
		( new Mcpwp_REST_SEO( $seo ) )->register_routes();
		( new Mcpwp_REST_Forms( $forms ) )->register_routes();
		( new Mcpwp_REST_Site_Manager( $site_manager ) )->register_routes();
		( new Mcpwp_REST_Theme_Builder( $theme_builder ) )->register_routes();
		( new Mcpwp_REST_Users( $users ) )->register_routes();
		( new Mcpwp_REST_Widgets( $widgets ) )->register_routes();
		( new Mcpwp_REST_Themes( $themes ) )->register_routes();
		( new Mcpwp_REST_WooCommerce( $woocommerce ) )->register_routes();
		( new Mcpwp_REST_Multilang( $multilang ) )->register_routes();
		( new Mcpwp_REST_Google_Indexing( $google_indexing ) )->register_routes();

		// LearnPress LMS — only register if LearnPress is active.
		if ( class_exists( 'LearnPress' ) || post_type_exists( 'lp_course' ) ) {
			( new Mcpwp_REST_LearnPress( $learnpress ) )->register_routes();
		}

		// TP Events — only register if tp_event post type exists.
		if ( post_type_exists( 'tp_event' ) ) {
			( new Mcpwp_REST_Events( $events ) )->register_routes();
		}

		( new Mcpwp_REST_Staging( $staging ) )->register_routes();
	}

	/**
	 * Add pro-module-only capability flags.
	 *
	 * Note: plan / pro_active are intentionally NOT set here. They are derived
	 * from the canonical Mcpwp_License::get_license_info() in Mcpwp_Core after
	 * this filter runs, so the entitlement state has a single source of truth.
	 * Pro bootstrap only loads when licensed, so it must not define plan/pro_active.
	 *
	 * @param array $capabilities Capabilities array.
	 * @return array
	 */
	public static function add_pro_capabilities( $capabilities ) {
		$capabilities['learnpress']  = class_exists( 'LearnPress' ) || post_type_exists( 'lp_course' );
		$capabilities['tp_events']   = post_type_exists( 'tp_event' );
		return $capabilities;
	}
}
