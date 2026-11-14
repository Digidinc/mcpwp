<?php
/**
 * The one reading of MCPWP_TRUST_PROXY_HEADERS.
 *
 * Every place that may believe a proxy-supplied header (client IP for rate
 * limits, logs and feedback; X-Forwarded-Proto for the OAuth issuer and the
 * OAuth HTTPS rule) asks here, so they can never disagree about whether the
 * proxy is trusted.
 *
 * The constant counts only when it is exactly `true`. Earlier releases
 * accepted any truthy value (1, 'yes', even the string 'false'); a site that
 * relied on that must define it as `true`. Such a value is reported in Site
 * Health and in a notice on the MCPWP screens.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Mcpwp_Proxy_Trust' ) ) {

	class Mcpwp_Proxy_Trust {

		/**
		 * Whether proxy-supplied headers may be believed.
		 *
		 * @return bool
		 */
		public static function headers_trusted() {
			return defined( 'MCPWP_TRUST_PROXY_HEADERS' ) && true === constant( 'MCPWP_TRUST_PROXY_HEADERS' );
		}

		/**
		 * Whether the constant is defined to something other than exactly
		 * true or false, so it is (now) silently not honoured. `false` is a
		 * clear "no" and is not reported.
		 *
		 * @return bool
		 */
		public static function misconfigured() {
			if ( ! defined( 'MCPWP_TRUST_PROXY_HEADERS' ) ) {
				return false;
			}
			$value = constant( 'MCPWP_TRUST_PROXY_HEADERS' );
			return true !== $value && false !== $value;
		}

		/**
		 * @return string
		 */
		public static function misconfigured_message() {
			return __( 'MCPWP_TRUST_PROXY_HEADERS is defined but is not exactly true, so it is not honoured: MCPWP believes no proxy header. To trust your proxy, define it as true (define( \'MCPWP_TRUST_PROXY_HEADERS\', true );); otherwise remove it.', 'mcpwp' );
		}

		/**
		 * Register the Site Health test and the admin notice.
		 *
		 * @return void
		 */
		public static function init() {
			add_filter( 'site_status_tests', array( __CLASS__, 'register_site_status_test' ) );
			add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
		}

		/**
		 * @param array $tests Registered tests.
		 * @return array
		 */
		public static function register_site_status_test( $tests ) {
			if ( ! is_array( $tests ) ) {
				return $tests;
			}
			$tests['direct']['mcpwp_proxy_trust_constant'] = array(
				'label' => __( 'MCPWP proxy-trust setting is honoured', 'mcpwp' ),
				'test'  => array( __CLASS__, 'run_site_status_test' ),
			);
			return $tests;
		}

		/**
		 * @return array Site Health result.
		 */
		public static function run_site_status_test() {
			$result = array(
				'label'       => __( 'MCPWP proxy-trust setting is honoured', 'mcpwp' ),
				'status'      => 'good',
				'badge'       => array(
					'label' => __( 'Security', 'mcpwp' ),
					'color' => 'blue',
				),
				'description' => '<p>' . esc_html__( 'MCPWP_TRUST_PROXY_HEADERS is either not defined or defined as true or false.', 'mcpwp' ) . '</p>',
				'actions'     => '',
				'test'        => 'mcpwp_proxy_trust_constant',
			);
			if ( self::misconfigured() ) {
				$result['status']         = 'recommended';
				$result['label']          = __( 'MCPWP_TRUST_PROXY_HEADERS is not honoured', 'mcpwp' );
				$result['badge']['color'] = 'orange';
				$result['description']    = '<p>' . esc_html( self::misconfigured_message() ) . '</p>';
			}
			return $result;
		}

		/**
		 * Notice on the MCPWP screens and Plugins while misconfigured.
		 *
		 * @return void
		 */
		public static function render_notice() {
			if ( ! current_user_can( 'manage_options' ) || ! self::misconfigured() ) {
				return;
			}
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			$slug   = ( $screen && isset( $screen->id ) ) ? (string) $screen->id : '';
			if ( 'plugins' !== $slug && false === strpos( $slug, 'mcpwp' ) ) {
				return;
			}
			printf(
				'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'MCPWP_TRUST_PROXY_HEADERS is not honoured.', 'mcpwp' ),
				esc_html( self::misconfigured_message() )
			);
		}
	}
}
