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
 * relied on that must define it as `true`.
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
	}
}
