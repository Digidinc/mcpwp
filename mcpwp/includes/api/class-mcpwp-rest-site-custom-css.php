<?php
/**
 * Customizer Additional CSS (non-WP.org builds).
 *
 * Carved from the original Mcpwp_REST_Site (G1 split). Behavior-identical.
 *
 * @package mumcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Customizer Additional CSS (non-WP.org builds).
 */
class Mcpwp_REST_Site_Custom_Css extends Mcpwp_REST_API {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		if ( ! defined( 'MCPWP_WPORG_BUILD' ) ) {
			register_rest_route(
				$this->namespace,
				'/custom-css',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this, 'get_custom_css' ),
						'permission_callback' => array( $this, 'check_permission' ),
					),
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this, 'set_custom_css' ),
						'permission_callback' => array( $this, 'check_permission' ),
						'args'                => array(
							'css'  => array(
								'description' => __( 'CSS code to set or append.', 'mumcp' ),
								'type'        => 'string',
								'required'    => true,
							),
							'mode' => array(
								'description' => __( 'How to apply: "replace" overwrites all CSS, "append" adds to existing.', 'mumcp' ),
								'type'        => 'string',
								'default'     => 'append',
								'enum'        => array( 'replace', 'append' ),
							),
						),
					),
					array(
						'methods'             => WP_REST_Server::DELETABLE,
						'callback'            => array( $this, 'delete_custom_css' ),
						'permission_callback' => array( $this, 'check_permission' ),
					),
				)
			);
			register_rest_route(
				$this->namespace,
				'/custom-css/length',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this, 'get_css_length' ),
						'permission_callback' => array( $this, 'check_permission' ),
					),
				)
			);
		} // MCPWP_WPORG_BUILD
	}

	public function get_custom_css( $request ) {
		$this->log_activity( 'get_custom_css', $request );

		$css = wp_get_custom_css();

		return $this->success_response(
			array(
				'css'    => $css,
				'length' => strlen( $css ),
			)
		);
	}

	public function set_custom_css( $request ) {
		if ( defined( 'MCPWP_WPORG_BUILD' ) ) {
			return $this->error_response( 'not_available', __( 'This endpoint is not available in this build.', 'mumcp' ), 403 );
		}
		$this->log_activity( 'set_custom_css', $request );

		$new_css = $request->get_param( 'css' );
		$mode    = $request->get_param( 'mode' ) ?: 'append';

		// Detect HTML-escaped operators that make CSS selectors invalid.
		$escaped_warnings = array();
		if ( false !== strpos( $new_css, '&gt;' ) ) {
			$escaped_warnings[] = 'CSS contains HTML-escaped ">" (&gt;) — child combinators will not work. Replace &gt; with > in selector strings.';
		}
		if ( false !== strpos( $new_css, '&lt;' ) ) {
			$escaped_warnings[] = 'CSS contains HTML-escaped "<" (&lt;) — this is not valid in CSS selectors.';
		}
		if ( false !== strpos( $new_css, '&amp;' ) ) {
			$escaped_warnings[] = 'CSS contains HTML-escaped "&" (&amp;) — replace with & in CSS.';
		}

		if ( 'append' === $mode ) {
			$existing = wp_get_custom_css();
			// Also scan existing CSS for escaped operators in append mode.
			if ( false !== strpos( $existing, '&gt;' ) ) {
				$escaped_warnings[] = 'Existing CSS contains HTML-escaped ">" (&gt;) — existing child combinators may not work.';
			}
			if ( false !== strpos( $existing, '&lt;' ) ) {
				$escaped_warnings[] = 'Existing CSS contains HTML-escaped "<" (&lt;) — existing CSS has invalid selectors.';
			}
			if ( false !== strpos( $existing, '&amp;' ) ) {
				$escaped_warnings[] = 'Existing CSS contains HTML-escaped "&" (&amp;) — existing CSS may have invalid selectors.';
			}
			$css = $existing . "\n\n" . $new_css;
		} else {
			$css = $new_css;
		}

		$result = wp_update_custom_css_post( $css );

		if ( is_wp_error( $result ) ) {
			return $this->error_response(
				'css_update_failed',
				$result->get_error_message(),
				500
			);
		}

		$css_preview = strlen( $css ) > 120 ? substr( $css, 0, 120 ) . '…' : $css;
		if ( 'replace' === $mode ) {
			$response = array(
				'length'  => strlen( $css ),
				'hash'    => md5( $css ),
				'preview' => $css_preview,
				'mode'    => $mode,
				'note'    => 'Full CSS not returned (use GET /custom-css to read). Hash confirms save.',
			);
		} else {
			$response = array(
				'length'          => strlen( $css ),
				'appended_length' => strlen( $new_css ),
				'hash'            => md5( $css ),
				'preview'         => $css_preview,
				'mode'            => $mode,
				'note'            => 'Full CSS not returned (use GET /custom-css to read). Hash confirms save.',
			);
		}

		if ( ! empty( $escaped_warnings ) ) {
			$response['selector_warnings'] = $escaped_warnings;
		}

		// Check for themes with known custom CSS systems that may override Customizer CSS.
		$theme = wp_get_theme();
		$theme_name = $theme->get( 'Name' );
		$theme_warning = null;

		// Eduma / ThimPress themes use their own CSS option — dual-write so CSS actually renders.
		$thim_css_option = get_option( 'thim_custom_css' );
		if ( false !== $thim_css_option || $this->is_eduma_theme() ) {
			update_option( 'thim_custom_css', $css );
			$response['thim_custom_css_synced'] = true;
			$theme_warning = sprintf(
				/* translators: %s: theme name */
				__( "Theme '%s' uses its own CSS system (thim_custom_css). CSS has been dual-written to both WordPress Customizer and Eduma's custom CSS option.", 'mumcp' ),
				$theme_name
			);
		} elseif ( false !== stripos( $theme_name, 'flavor' ) ) {
			$theme_warning = sprintf(
				/* translators: %s: theme name */
				__( "Theme '%s' may use its own CSS system. CSS saved via WordPress Customizer but may not render. Check theme settings.", 'mumcp' ),
				$theme_name
			);
		}

		if ( $theme_warning ) {
			$response['theme_warning'] = $theme_warning;
		}

		// Detect if the theme has removed the wp_custom_css_cb callback from wp_head.
		// This callback is what outputs the <style id="wp-custom-css"> tag on the frontend.
		$css_callback_hooked = has_action( 'wp_head', 'wp_custom_css_cb' );
		$response['wp_custom_css_cb_active'] = (bool) $css_callback_hooked;

		if ( ! $css_callback_hooked ) {
			$response['warning'] = __(
				'CSS saved but may not render on this theme. The active theme does not have the wp_custom_css_cb callback hooked to wp_head, which means WordPress Additional CSS will not be output. Consider using Elementor Custom CSS or a code snippets plugin as an alternative.',
				'mumcp'
			);
		}

		// CSS rendering verification via loopback.
		$verification = array( 'checked' => false );
		$loopback     = wp_remote_get(
			add_query_arg( 'nocache', wp_rand(), home_url( '/' ) ),
			array(
				'timeout'   => 5,
				'sslverify' => false,
			)
		);

		if ( is_wp_error( $loopback ) ) {
			$verification['reason'] = $loopback->get_error_message();
		} else {
			$body = wp_remote_retrieve_body( $loopback );
			$verification['checked']         = true;
			$verification['style_tag_found'] = false !== strpos( $body, '<style id="wp-custom-css">' );
			$snippet                         = substr( trim( $css ), 0, 50 );
			$verification['snippet_found']   = ! empty( $snippet ) && false !== strpos( $body, $snippet );
			$verification['verified']        = $verification['style_tag_found'] && $verification['snippet_found'];
			$verification['verified_means']  = 'snippet_found confirms the CSS text appears in the rendered page; it does NOT verify the rule wins in cascade over Elementor inline styles or later-loaded theme CSS.';

			if ( ! $verification['verified'] && $css_callback_hooked ) {
				$verification['warning'] = __( 'CSS was saved but could not be confirmed in the rendered page. It may be overridden by theme or caching.', 'mumcp' );
			} elseif ( ! $verification['verified'] && ! $css_callback_hooked ) {
				$verification['warning'] = __(
					'CSS saved but may not render on this theme. The active theme may not support WordPress Additional CSS (wp_custom_css_cb is not hooked). Consider using Elementor Custom CSS or a code snippets plugin as an alternative.',
					'mumcp'
				);
			}
		}

		// If CSS rendering could not be verified, provide actionable alternatives.
		// The most common cause (verified in production): child theme enqueues a static
		// CSS file AFTER wp-custom-css, so even with wp_custom_css_cb hooked the custom
		// CSS loses in the cascade. The reliable fix is Elementor Custom Code (elementor_snippet),
		// which injects a <style> block via Elementor's own output pipeline, bypassing theme CSS order.
		if ( ! empty( $verification['checked'] ) && empty( $verification['verified'] ) ) {
			$elementor_snippet_available = post_type_exists( 'elementor_snippet' );
			$response['css_not_rendering'] = true;
			$response['alternatives'] = array(
				array(
					'method'      => 'elementor_custom_code',
					'available'   => $elementor_snippet_available,
					'description' => 'Create an elementor_snippet post — injects a <style> block via Elementor output, bypasses theme CSS load order. Use: wp_create_post(post_type="elementor_snippet", title="Global CSS", content="<style>...<\/style>", location="head")',
				),
				array(
					'method'      => 'elementor_header_widget',
					'available'   => true,
					'description' => 'Inject a <style> block into an HTML widget inside the Elementor header template. Renders on every page via Elementor template system.',
				),
				array(
					'method'      => 'elementor_globals',
					'available'   => true,
					'description' => 'Use wp_set_elementor_globals to write to the Elementor kit CSS (global stylesheet). Affects the entire site.',
				),
				array(
					'method'      => 'page_custom_css',
					'available'   => true,
					'description' => 'Use wp_set_elementor with page_settings.custom_css for page-specific CSS (Elementor Pro required for some features).',
				),
			);
		}

		$response['verification'] = $verification;

		return $this->success_response( $response );
	}

	public function delete_custom_css( $request ) {
		if ( defined( 'MCPWP_WPORG_BUILD' ) ) {
			return $this->error_response( 'not_available', __( 'This endpoint is not available in this build.', 'mumcp' ), 403 );
		}
		$this->log_activity( 'delete_custom_css', $request );

		$previous_length = strlen( wp_get_custom_css() );
		$result          = wp_update_custom_css_post( '' );

		if ( is_wp_error( $result ) ) {
			return $this->error_response(
				'css_delete_failed',
				$result->get_error_message(),
				500
			);
		}

		return $this->success_response(
			array(
				'deleted'         => true,
				'previous_length' => $previous_length,
				'message'         => __( 'All custom CSS has been removed.', 'mumcp' ),
			)
		);
	}

	public function get_css_length( $request ) {
		$this->log_activity( 'get_css_length', $request );

		$css = wp_get_custom_css();

		return $this->success_response(
			array(
				'length'     => strlen( $css ),
				'line_count' => $css ? substr_count( $css, "\n" ) + 1 : 0,
				'has_css'    => strlen( $css ) > 0,
			)
		);
	}

	private function is_eduma_theme() {
		$theme    = wp_get_theme();
		$name     = strtolower( $theme->get( 'Name' ) );
		$template = strtolower( $theme->get_template() );

		return false !== strpos( $name, 'eduma' )
			|| false !== strpos( $template, 'eduma' )
			|| false !== strpos( $name, 'thimpress' )
			|| false !== strpos( $template, 'thim' );
	}

}
