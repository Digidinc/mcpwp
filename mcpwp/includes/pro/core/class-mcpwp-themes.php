<?php
/**
 * Popular Theme Integration Handler
 *
 * @package MCPWP_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Theme integration functionality.
 *
 * Provides unified access to popular theme settings.
 */
class Mcpwp_Themes {

	/**
	 * Supported themes and their settings keys.
	 *
	 * @var array
	 */
	private $supported_themes = array(
		'astra'         => array(
			'option_key'    => 'astra-settings',
			'settings_type' => 'custom_option',
			'name'          => 'Astra',
		),
		'generatepress' => array(
			'option_key'    => 'generate_settings',
			'settings_type' => 'custom_option',
			'name'          => 'GeneratePress',
		),
		'kadence'       => array(
			'option_key'    => 'theme_mods',
			'settings_type' => 'theme_mods',
			'name'          => 'Kadence',
		),
	);

	/**
	 * Get detected theme information.
	 *
	 * @return array Theme info.
	 */
	public function detect_theme() {
		$theme      = wp_get_theme();
		$theme_slug = strtolower( $theme->get_template() );

		$info = array(
			'name'             => $theme->get( 'Name' ),
			'slug'             => $theme_slug,
			'version'          => $theme->get( 'Version' ),
			'author'           => $theme->get( 'Author' ),
			'parent'           => $theme->parent() ? $theme->parent()->get( 'Name' ) : null,
			'is_block_theme'   => wp_is_block_theme(),
			'is_supported'     => isset( $this->supported_themes[ $theme_slug ] ),
			'settings_type'    => $this->get_settings_type( $theme_slug ),
			'customizer_url'   => admin_url( 'customize.php' ),
		);

		// Add child theme info if applicable.
		if ( is_child_theme() ) {
			$info['child_theme'] = array(
				'name'    => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ),
			);
		}

		return $info;
	}

	/**
	 * Get settings storage type for a theme.
	 *
	 * @param string $theme_slug Theme slug.
	 * @return string Settings type.
	 */
	private function get_settings_type( $theme_slug ) {
		if ( isset( $this->supported_themes[ $theme_slug ]['settings_type'] ) ) {
			return $this->supported_themes[ $theme_slug ]['settings_type'];
		}
		return 'theme_mods';
	}

	/**
	 * Get theme settings (auto-detected).
	 *
	 * @return array Theme settings.
	 */
	public function get_settings() {
		$theme_slug = strtolower( wp_get_theme()->get_template() );

		// Try theme-specific handler first.
		$method = 'get_' . str_replace( '-', '_', $theme_slug ) . '_settings';
		if ( method_exists( $this, $method ) ) {
			return $this->$method();
		}

		// Fall back to generic theme_mods.
		return $this->get_generic_settings();
	}

	/**
	 * Update theme settings.
	 *
	 * @param array $settings Settings to update.
	 * @return array|WP_Error Updated settings or error.
	 */
	public function update_settings( $settings ) {
		$theme_slug = strtolower( wp_get_theme()->get_template() );

		// Try theme-specific handler first.
		$method = 'update_' . str_replace( '-', '_', $theme_slug ) . '_settings';
		if ( method_exists( $this, $method ) ) {
			return $this->$method( $settings );
		}

		// Fall back to generic theme_mods update.
		return $this->update_generic_settings( $settings );
	}

	/**
	 * Get generic theme settings via theme_mods.
	 *
	 * @return array Settings.
	 */
	private function get_generic_settings() {
		$mods = get_theme_mods();

		return array(
			'type'     => 'theme_mods',
			'settings' => $mods ? $mods : array(),
		);
	}

	/**
	 * Update generic theme settings.
	 *
	 * @param array $settings Settings to update.
	 * @return array Updated settings.
	 */
	private function update_generic_settings( $settings ) {
		foreach ( $settings as $key => $value ) {
			set_theme_mod( $key, $value );
		}

		return $this->get_generic_settings();
	}

	// =========================================================================
	// ASTRA THEME
	// =========================================================================

	/**
	 * Get Astra theme settings.
	 *
	 * @return array Astra settings.
	 */
	private function get_astra_settings() {
		$settings = get_option( 'astra-settings', array() );

		// Organize settings by category.
		return array(
			'type'   => 'astra',
			'colors' => $this->get_astra_colors( $settings ),
			'typography' => $this->get_astra_typography( $settings ),
			'header' => $this->get_astra_header( $settings ),
			'footer' => $this->get_astra_footer( $settings ),
			'layout' => $this->get_astra_layout( $settings ),
			'buttons' => $this->get_astra_buttons( $settings ),
			'blog'   => $this->get_astra_blog( $settings ),
			'sidebar' => $this->get_astra_sidebar( $settings ),
			'raw'    => $settings, // Include raw for advanced use.
		);
	}

	/**
	 * Get Astra color settings.
	 *
	 * @param array $settings Full settings array.
	 * @return array Color settings.
	 */
	private function get_astra_colors( $settings ) {
		$colors = array();

		// Global color palette.
		for ( $i = 0; $i <= 8; $i++ ) {
			$key = 'global-color-palette';
			if ( isset( $settings[ $key ]['palette'][ $i ] ) ) {
				$colors[ "color_{$i}" ] = $settings[ $key ]['palette'][ $i ];
			}
		}

		// Theme colors.
		$color_keys = array(
			'theme-color',
			'link-color',
			'link-h-color',
			'text-color',
			'heading-base-color',
			'site-background-color',
			'content-bg-color',
		);

		foreach ( $color_keys as $key ) {
			if ( isset( $settings[ $key ] ) ) {
				$colors[ str_replace( '-', '_', $key ) ] = $settings[ $key ];
			}
		}

		return $colors;
	}

	/**
	 * Get Astra typography settings.
	 *
	 * @param array $settings Full settings array.
	 * @return array Typography settings.
	 */
	private function get_astra_typography( $settings ) {
		$typography = array();

		// Body typography.
		$body_keys = array(
			'body-font-family',
			'body-font-weight',
			'body-font-size',
			'body-line-height',
			'body-text-transform',
		);

		foreach ( $body_keys as $key ) {
			if ( isset( $settings[ $key ] ) ) {
				$typography['body'][ str_replace( 'body-', '', str_replace( '-', '_', $key ) ) ] = $settings[ $key ];
			}
		}

		// Heading typography.
		$headings = array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' );
		foreach ( $headings as $h ) {
			$prefix = "font-size-{$h}";
			if ( isset( $settings[ $prefix ] ) ) {
				$typography['headings'][ $h ] = array(
					'font_size' => $settings[ $prefix ],
				);
			}
		}

		// Headings font family.
		if ( isset( $settings['headings-font-family'] ) ) {
			$typography['headings']['font_family'] = $settings['headings-font-family'];
		}

		return $typography;
	}

	/**
	 * Get Astra header settings.
	 *
	 * @param array $settings Full settings array.
	 * @return array Header settings.
	 */
	private function get_astra_header( $settings ) {
		$header = array();

		$header_keys = array(
			'header-layouts',
			'header-main-layout',
			'header-main-menu-align',
			'header-main-rt-section',
			'header-main-rt-section-html',
			'different-mobile-header-logo',
			'mobile-header-logo',
			'header-display-width',
			'header-main-sep',
			'header-main-sep-color',
			'header-bg-color',
			'header-color-site-title',
			'header-color-h-site-title',
			'header-color-site-tagline',
			'sticky-header',
			'sticky-header-style',
			'sticky-header-bg-color',
		);

		foreach ( $header_keys as $key ) {
			if ( isset( $settings[ $key ] ) ) {
				$header[ str_replace( '-', '_', $key ) ] = $settings[ $key ];
			}
		}

		return $header;
	}

	/**
	 * Get Astra footer settings.
	 *
	 * @param array $settings Full settings array.
	 * @return array Footer settings.
	 */
	private function get_astra_footer( $settings ) {
		$footer = array();

		$footer_keys = array(
			'footer-layout',
			'footer-sml-layout',
			'footer-sml-section-1',
			'footer-sml-section-1-credit',
			'footer-sml-section-2',
			'footer-bar-bg-color',
			'footer-bar-text',
			'footer-bar-link',
			'footer-bar-link-hover',
			'footer-width',
			'footer-bg-color',
			'footer-text-color',
			'footer-link-color',
			'footer-link-h-color',
		);

		foreach ( $footer_keys as $key ) {
			if ( isset( $settings[ $key ] ) ) {
				$footer[ str_replace( '-', '_', $key ) ] = $settings[ $key ];
			}
		}

		return $footer;
	}

	/**
	 * Get Astra layout settings.
	 *
	 * @param array $settings Full settings array.
	 * @return array Layout settings.
	 */
	private function get_astra_layout( $settings ) {
		$layout = array();

		$layout_keys = array(
			'site-content-width',
			'site-layout',
			'site-content-layout',
			'single-post-content-layout',
			'archive-post-content-layout',
			'site-sidebar-layout',
			'single-page-sidebar-layout',
			'single-post-sidebar-layout',
			'archive-post-sidebar-layout',
		);

		foreach ( $layout_keys as $key ) {
			if ( isset( $settings[ $key ] ) ) {
				$layout[ str_replace( '-', '_', $key ) ] = $settings[ $key ];
			}
		}

		return $layout;
	}

	/**
	 * Get Astra button settings.
	 *
	 * @param array $settings Full settings array.
	 * @return array Button settings.
	 */
	private function get_astra_buttons( $settings ) {
		$buttons = array();

		$button_keys = array(
			'button-color',
			'button-h-color',
			'button-bg-color',
			'button-bg-h-color',
			'button-radius',
			'button-v-padding',
			'button-h-padding',
			'theme-btn-font-family',
			'theme-btn-font-size',
			'theme-btn-font-weight',
			'theme-btn-text-transform',
			'theme-btn-line-height',
			'theme-btn-letter-spacing',
		);

		foreach ( $button_keys as $key ) {
			if ( isset( $settings[ $key ] ) ) {
				$buttons[ str_replace( '-', '_', $key ) ] = $settings[ $key ];
			}
		}

		return $buttons;
	}

	/**
	 * Get Astra blog settings.
	 *
	 * @param array $settings Full settings array.
	 * @return array Blog settings.
	 */
	private function get_astra_blog( $settings ) {
		$blog = array();

		$blog_keys = array(
			'blog-post-structure',
			'blog-meta',
			'blog-single-post-structure',
			'blog-single-meta',
			'blog-width',
			'blog-max-width',
		);

		foreach ( $blog_keys as $key ) {
			if ( isset( $settings[ $key ] ) ) {
				$blog[ str_replace( '-', '_', $key ) ] = $settings[ $key ];
			}
		}

		return $blog;
	}

	/**
	 * Get Astra sidebar settings.
	 *
	 * @param array $settings Full settings array.
	 * @return array Sidebar settings.
	 */
	private function get_astra_sidebar( $settings ) {
		$sidebar = array();

		$sidebar_keys = array(
			'site-sidebar-width',
			'sidebar-style',
			'site-sidebar-layout',
		);

		foreach ( $sidebar_keys as $key ) {
			if ( isset( $settings[ $key ] ) ) {
				$sidebar[ str_replace( '-', '_', $key ) ] = $settings[ $key ];
			}
		}

		return $sidebar;
	}

	/**
	 * Update Astra theme settings.
	 *
	 * @param array $settings Settings to update.
	 * @return array|WP_Error Updated settings or error.
	 */
	private function update_astra_settings( $settings ) {
		$current = get_option( 'astra-settings', array() );

		// Merge settings (supports nested updates).
		$updated = $this->array_merge_recursive_distinct( $current, $settings );

		$result = update_option( 'astra-settings', $updated );

		if ( false === $result ) {
			// Check if value was actually the same.
			if ( get_option( 'astra-settings' ) === $updated ) {
				return $this->get_astra_settings();
			}
			return new WP_Error( 'update_failed', __( 'Failed to update Astra settings.', 'mumcp' ) );
		}

		// Clear Astra caches.
		if ( class_exists( 'Astra_Customizer' ) ) {
			delete_option( 'astra-settings-cached' );
		}

		return $this->get_astra_settings();
	}

	// =========================================================================
	// GENERATEPRESS THEME
	// =========================================================================

	/**
	 * Get GeneratePress theme settings.
	 *
	 * @return array GeneratePress settings.
	 */
	private function get_generatepress_settings() {
		$settings = get_option( 'generate_settings', array() );

		return array(
			'type'       => 'generatepress',
			'colors'     => $this->get_gp_colors( $settings ),
			'typography' => $this->get_gp_typography( $settings ),
			'layout'     => $this->get_gp_layout( $settings ),
			'raw'        => $settings,
		);
	}

	/**
	 * Get GeneratePress colors.
	 *
	 * @param array $settings Full settings.
	 * @return array Colors.
	 */
	private function get_gp_colors( $settings ) {
		$color_keys = array(
			'global_colors',
			'background_color',
			'text_color',
			'link_color',
			'link_color_hover',
		);

		$colors = array();
		foreach ( $color_keys as $key ) {
			if ( isset( $settings[ $key ] ) ) {
				$colors[ $key ] = $settings[ $key ];
			}
		}

		return $colors;
	}

	/**
	 * Get GeneratePress typography.
	 *
	 * @param array $settings Full settings.
	 * @return array Typography.
	 */
	private function get_gp_typography( $settings ) {
		$typo_keys = array(
			'font_body',
			'body_font_size',
			'body_line_height',
			'font_heading',
			'heading_font_weight',
		);

		$typography = array();
		foreach ( $typo_keys as $key ) {
			if ( isset( $settings[ $key ] ) ) {
				$typography[ $key ] = $settings[ $key ];
			}
		}

		return $typography;
	}

	/**
	 * Get GeneratePress layout.
	 *
	 * @param array $settings Full settings.
	 * @return array Layout.
	 */
	private function get_gp_layout( $settings ) {
		$layout_keys = array(
			'container_width',
			'content_layout_setting',
			'header_layout_setting',
			'footer_layout_setting',
			'sidebar_layout_setting',
		);

		$layout = array();
		foreach ( $layout_keys as $key ) {
			if ( isset( $settings[ $key ] ) ) {
				$layout[ $key ] = $settings[ $key ];
			}
		}

		return $layout;
	}

	/**
	 * Update GeneratePress settings.
	 *
	 * @param array $settings Settings to update.
	 * @return array Updated settings.
	 */
	private function update_generatepress_settings( $settings ) {
		$current = get_option( 'generate_settings', array() );
		$updated = $this->array_merge_recursive_distinct( $current, $settings );

		// This is GeneratePress theme's own option key — not ours to prefix.
		update_option( 'generate_settings', $updated );

		// Clear GP cache.
		delete_transient( 'generate_dynamic_css_output' );

		return $this->get_generatepress_settings();
	}

	// =========================================================================
	// KADENCE THEME
	// =========================================================================

	/**
	 * Get Kadence theme settings.
	 *
	 * @return array Kadence settings.
	 */
	private function get_kadence_settings() {
		// Kadence stores in theme_mods but uses a specific structure.
		$mods = get_theme_mods();

		return array(
			'type'       => 'kadence',
			'colors'     => $this->get_kadence_colors( $mods ),
			'typography' => $this->get_kadence_typography( $mods ),
			'layout'     => $this->get_kadence_layout( $mods ),
			'raw'        => $mods,
		);
	}

	/**
	 * Get Kadence colors.
	 *
	 * @param array $mods Theme mods.
	 * @return array Colors.
	 */
	private function get_kadence_colors( $mods ) {
		$colors = array();

		// Kadence uses a global palette.
		if ( isset( $mods['kadence_global_palette'] ) ) {
			$colors['palette'] = $mods['kadence_global_palette'];
		}

		$color_keys = array(
			'link_color',
			'base_font',
		);

		foreach ( $color_keys as $key ) {
			if ( isset( $mods[ $key ] ) ) {
				$colors[ $key ] = $mods[ $key ];
			}
		}

		return $colors;
	}

	/**
	 * Get Kadence typography.
	 *
	 * @param array $mods Theme mods.
	 * @return array Typography.
	 */
	private function get_kadence_typography( $mods ) {
		$typography = array();

		$typo_keys = array(
			'base_font',
			'heading_font',
			'h1_font',
			'h2_font',
			'h3_font',
			'h4_font',
			'h5_font',
			'h6_font',
		);

		foreach ( $typo_keys as $key ) {
			if ( isset( $mods[ $key ] ) ) {
				$typography[ $key ] = $mods[ $key ];
			}
		}

		return $typography;
	}

	/**
	 * Get Kadence layout.
	 *
	 * @param array $mods Theme mods.
	 * @return array Layout.
	 */
	private function get_kadence_layout( $mods ) {
		$layout = array();

		$layout_keys = array(
			'site_width',
			'content_width',
			'sidebar_width',
			'header_desktop_items',
			'header_mobile_items',
			'footer_items',
		);

		foreach ( $layout_keys as $key ) {
			if ( isset( $mods[ $key ] ) ) {
				$layout[ $key ] = $mods[ $key ];
			}
		}

		return $layout;
	}

	/**
	 * Update Kadence settings.
	 *
	 * @param array $settings Settings to update.
	 * @return array Updated settings.
	 */
	private function update_kadence_settings( $settings ) {
		foreach ( $settings as $key => $value ) {
			set_theme_mod( $key, $value );
		}

		// Clear Kadence cache.
		delete_transient( 'kadence_dynamic_css' );

		return $this->get_kadence_settings();
	}

	// =========================================================================
	// HELPERS
	// =========================================================================

	/**
	 * Recursively merge arrays without losing distinct values.
	 *
	 * @param array $array1 First array.
	 * @param array $array2 Second array (overrides).
	 * @return array Merged array.
	 */
	private function array_merge_recursive_distinct( array $array1, array $array2 ) {
		$merged = $array1;

		foreach ( $array2 as $key => $value ) {
			if ( is_array( $value ) && isset( $merged[ $key ] ) && is_array( $merged[ $key ] ) ) {
				$merged[ $key ] = $this->array_merge_recursive_distinct( $merged[ $key ], $value );
			} else {
				$merged[ $key ] = $value;
			}
		}

		return $merged;
	}

	/**
	 * Get list of supported themes.
	 *
	 * @return array Supported themes.
	 */
	public function get_supported_themes() {
		$themes = array();

		foreach ( $this->supported_themes as $slug => $data ) {
			$themes[] = array(
				'slug' => $slug,
				'name' => $data['name'],
			);
		}

		return $themes;
	}
}
