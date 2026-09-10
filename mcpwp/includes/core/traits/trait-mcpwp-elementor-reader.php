<?php
/**
 * Elementor engine — reader.
 *
 * Carved verbatim from Mcpwp_Elementor_Basic (G4 split). Mixed back via trait — same class, same $this.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Mcpwp_Elementor_Reader_Trait {
	public function is_active() {
		return defined( 'ELEMENTOR_VERSION' );
	}

	/**
	 * Check if Elementor Pro is active.
	 *
	 * @return bool True if Elementor Pro is active.
	 */
	public function is_pro_active() {
		return defined( 'ELEMENTOR_PRO_VERSION' );
	}

	/**
	 * Get Elementor status.
	 *
	 * @return array Elementor status.
	 */
	public function get_status() {
		return array(
			'active'  => $this->is_active(),
			'pro'     => $this->is_pro_active(),
			'version' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
			'pro_version' => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : null,
		);
	}

	/**
	 * Get Elementor data for a page.
	 *
	 * @param int $page_id Page ID.
	 * @return array|WP_Error Elementor data or error.
	 */
	public function get_elementor_data( $page_id, $data = array() ) {
		if ( ! $this->is_active() ) {
			return new WP_Error(
				'elementor_not_active',
				__( 'Elementor is not installed or active.', 'mcpwp' ),
				array(
					'status' => 400,
					'hint'   => 'Elementor is not installed or active on this site. Use wp_introspect to check available page builders. For content editing without Elementor, use wp_update_page with HTML content, or wp_set_blocks for Gutenberg.',
				)
			);
		}

		$page = $this->validate_post( $page_id );
		if ( is_wp_error( $page ) ) {
			return $page;
		}

		$elementor_data = get_post_meta( $page_id, '_elementor_data', true );
		$edit_mode = get_post_meta( $page_id, '_elementor_edit_mode', true );
		$template_type = get_post_meta( $page_id, '_elementor_template_type', true );

		$page_settings = get_post_meta( $page_id, '_elementor_page_settings', true );

		// Read in whatever form it is stored (#845): an array is the tree, and
		// data that cannot be a tree is reported as unreadable below — never
		// left to look like a page with no Elementor data.
		$stored  = Mcpwp_Elementor_Stored_Tree::read( $elementor_data );
		$decoded = empty( $elementor_data ) ? null : $stored['elements'];

		// Strip default widget settings to reduce payload size.
		if ( ! empty( $data['strip_defaults'] ) && is_array( $decoded ) ) {
			$this->strip_element_defaults( $decoded );
		}

		$response = array(
			'page_id'        => (int) $page_id,
			'title'          => $page->post_title,
			'has_elementor'  => ! empty( $elementor_data ),
			'edit_mode'      => $edit_mode ?: 'classic',
			'template_type'  => $template_type ?: null,
			'elementor_data' => $decoded,
			'page_settings'  => $page_settings ? ( is_array( $page_settings ) ? $page_settings : json_decode( $page_settings, true ) ) : null,
			'edit_url'       => admin_url( "post.php?post={$page_id}&action=elementor" ),
		);

		if ( Mcpwp_Elementor_Stored_Tree::is_unusable( $stored ) ) {
			$response['elementor_data_error'] = Mcpwp_Elementor_Stored_Tree::read_error( $stored );
		}

		// The raw JSON string duplicates elementor_data byte-for-byte and
		// doubles the payload on large pages — include only on request. It is
		// JSON text whatever the stored form: an array is encoded as stored.
		if ( ! empty( $data['include_raw'] ) ) {
			$response['elementor_json'] = empty( $elementor_data )
				? null
				: ( is_string( $elementor_data ) ? $elementor_data : wp_json_encode( $elementor_data ) );
		}

		return $response;
	}

	/**
	 * Get Elementor data for multiple pages in bulk.
	 *
	 * @param array $page_ids Array of page IDs.
	 * @return array Results with 'results', 'errors', and 'count' keys.
	 */
	public function get_elementor_data_bulk( $page_ids ) {
		$results = array();
		$errors  = array();

		foreach ( $page_ids as $page_id ) {
			$page_id = absint( $page_id );
			$data    = $this->get_elementor_data( $page_id );

			if ( is_wp_error( $data ) ) {
				$errors[ $page_id ] = $data->get_error_message();
			} else {
				$results[ $page_id ] = $data;
			}
		}

		return array(
			'results' => $results,
			'errors'  => $errors,
			'count'   => count( $results ),
		);
	}

	/**
	 * Get a lightweight structural summary of Elementor data for a page.
	 *
	 * Returns section/container structure with widget types and key display
	 * settings, typically <1K tokens vs 64K+ for full data.
	 *
	 * @param int $page_id Page ID.
	 * @return array|WP_Error Summary or error.
	 */
	public function get_elementor_summary( $page_id ) {
		if ( ! $this->is_active() ) {
			return new WP_Error(
				'elementor_not_active',
				__( 'Elementor is not installed or active.', 'mcpwp' ),
				array( 'status' => 400 )
			);
		}

		$page = $this->validate_post( $page_id );
		if ( is_wp_error( $page ) ) {
			return $page;
		}

		$elementor_data = get_post_meta( $page_id, '_elementor_data', true );
		if ( empty( $elementor_data ) ) {
			return array(
				'page_id'       => (int) $page_id,
				'title'         => $page->post_title,
				'has_elementor' => false,
				'sections'      => array(),
			);
		}

		// Read in whatever form it is stored (#845). Data that cannot be read
		// is refused: summarised as "no Elementor data" it looked like a page
		// with nothing on it.
		$stored = Mcpwp_Elementor_Stored_Tree::read( $elementor_data );
		if ( Mcpwp_Elementor_Stored_Tree::is_unusable( $stored ) ) {
			return Mcpwp_Elementor_Stored_Tree::unreadable( $stored );
		}
		$elements = $stored['elements'];

		$sections      = array();
		$widget_count  = 0;
		$section_count = 0;

		foreach ( $elements as $index => $element ) {
			$section_summary = $this->summarize_element( $element, $widget_count );
			if ( $section_summary ) {
				$section_summary['index'] = $index;
				$sections[] = $section_summary;
				$section_count++;
			}
		}

		return array(
			'page_id'       => (int) $page_id,
			'title'         => $page->post_title,
			'has_elementor' => true,
			'section_count' => $section_count,
			'widget_count'  => $widget_count,
			'sections'      => $sections,
		);
	}

	/**
	 * Summarize a single Elementor element recursively.
	 *
	 * @param array $element      The element.
	 * @param int   $widget_count Running widget count (by reference).
	 * @return array|null Summary or null.
	 */
	private function summarize_element( $element, &$widget_count ) {
		if ( ! is_array( $element ) || empty( $element['elType'] ) ) {
			return null;
		}

		$el_type = $element['elType'];
		$summary = array(
			'id'   => isset( $element['id'] ) ? $element['id'] : null,
			'type' => $el_type,
		);

		if ( ! empty( $element['widgetType'] ) ) {
			$summary['widget'] = $element['widgetType'];
			$widget_count++;

			// Extract key display settings based on widget type.
			$settings = isset( $element['settings'] ) ? $element['settings'] : array();
			$key_settings = array();

			$display_keys = $this->get_widget_display_keys( $element['widgetType'] );
			foreach ( $display_keys as $key ) {
				if ( isset( $settings[ $key ] ) && '' !== $settings[ $key ] ) {
					$value = $settings[ $key ];
					// Truncate long strings to keep summary compact.
					if ( is_string( $value ) && strlen( $value ) > 100 ) {
						$value = substr( $value, 0, 100 ) . '...';
					}
					$key_settings[ $key ] = $value;
				}
			}

			if ( ! empty( $key_settings ) ) {
				$summary['settings'] = $key_settings;
			}
		} elseif ( in_array( $el_type, self::$atomic_leaf_types, true ) ) {
			// (#618/#620) Atomic content elements (e-heading, e-button, ...) are
			// self-typed via elType directly — there's no widgetType wrapper like
			// legacy widgets, so the branch above never fires for them.
			$summary['widget'] = $el_type;
			$widget_count++;

			$settings     = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
			$key_settings = array();
			foreach ( $settings as $key => $value ) {
				$unwrapped = $this->unwrap_atomic_prop_value( $value );
				if ( null === $unwrapped || '' === $unwrapped ) {
					continue;
				}
				if ( is_string( $unwrapped ) && strlen( $unwrapped ) > 100 ) {
					$unwrapped = substr( $unwrapped, 0, 100 ) . '...';
				}
				$key_settings[ $key ] = $unwrapped;
			}

			if ( ! empty( $key_settings ) ) {
				$summary['settings'] = $key_settings;
			}
		}

		// Recurse into child elements.
		if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
			$children = array();
			foreach ( $element['elements'] as $child ) {
				$child_summary = $this->summarize_element( $child, $widget_count );
				if ( $child_summary ) {
					$children[] = $child_summary;
				}
			}
			if ( ! empty( $children ) ) {
				$summary['children'] = $children;
			}
		}

		return $summary;
	}

	/**
	 * Get key display setting names for a widget type.
	 *
	 * @param string $widget_type Widget type name.
	 * @return array Setting key names.
	 */
	private function get_widget_display_keys( $widget_type ) {
		$map = array(
			'heading'       => array( 'title', 'header_size', 'align' ),
			'text-editor'   => array( 'editor' ),
			'image'         => array( 'image' ),
			'button'        => array( 'text', 'link' ),
			'icon-box'      => array( 'title_text', 'description_text', 'selected_icon' ),
			'image-box'     => array( 'title_text', 'description_text' ),
			'icon-list'     => array(),
			'counter'       => array( 'starting_number', 'ending_number', 'title' ),
			'progress-bar'  => array( 'title', 'percent' ),
			'testimonial'   => array( 'testimonial_name', 'testimonial_job', 'testimonial_content' ),
			'tabs'          => array(),
			'accordion'     => array(),
			'toggle'        => array(),
			'social-icons'  => array(),
			'alert'         => array( 'alert_title', 'alert_description' ),
			'html'          => array(),
			'video'         => array( 'youtube_url', 'vimeo_url' ),
			'google-maps'   => array( 'address' ),
			'form'          => array( 'form_name' ),
			'nav-menu'      => array( 'menu' ),
			'sitemap'       => array(),
			'flip-box'      => array( 'title_text_a', 'title_text_b' ),
			'call-to-action' => array( 'title', 'description', 'button' ),
			'price-table'   => array( 'heading', 'sub_heading', 'price' ),
			'price-list'    => array(),
			'countdown'     => array( 'due_date' ),
			'share-buttons' => array(),
			'blockquote'    => array( 'blockquote_content' ),
			'template'      => array( 'template_id' ),
			'posts'         => array( 'posts_post_type', 'classic_posts_per_page', 'classic_columns' ),
			'loop-grid'     => array( 'query_post_type', 'template_id', 'columns', 'posts_per_page' ),
			'portfolio'     => array( 'posts_per_page', 'columns' ),
		);

		return isset( $map[ $widget_type ] ) ? $map[ $widget_type ] : array( 'title', 'text', 'heading' );
	}

	/**
	 * Unwrap an Elementor v4 Atomic prop value.
	 *
	 * Atomic props are stored as {"$$type": "<kind>", "value": <actual>} —
	 * confirmed directly against Elementor 4.1.3 source (has-atomic-base.php,
	 * div-block.php, flexbox.php). Some props (e.g. heading's `title`) nest an
	 * inner value one level deeper (Html_V3_Prop_Type wrapping a String_Prop_Type),
	 * so this recurses until it finds a scalar. This is a generic best-effort
	 * unwrap for summary display, not a full schema-aware read — see #621 for
	 * the real per-widget schema registry.
	 *
	 * @param mixed $value Raw prop value from element JSON.
	 * @return string|int|float|bool|null Unwrapped scalar, or null if not extractable.
	 */
	private function unwrap_atomic_prop_value( $value ) {
		if ( is_scalar( $value ) ) {
			return $value;
		}

		if ( is_array( $value ) && array_key_exists( 'value', $value ) && isset( $value['$$type'] ) ) {
			$inner = $value['value'];
			if ( is_scalar( $inner ) ) {
				return $inner;
			}
			if ( is_array( $inner ) ) {
				// Nested wrapper (e.g. Html_V3_Prop_Type => { content: {$$type,value} }).
				if ( isset( $inner['content'] ) ) {
					return $this->unwrap_atomic_prop_value( $inner['content'] );
				}
				if ( array_key_exists( 'value', $inner ) ) {
					return $this->unwrap_atomic_prop_value( $inner );
				}
			}
		}

		return null;
	}

	/**
	 * Known control key renames per widget type.
	 *
	 * Maps commonly-used wrong key names to their correct Elementor equivalents.
	 * These are auto-fixed during validation to prevent renderer crashes.
	 *
	 * @var array<string, array<string, string>>
	 */
	private static $control_renames = array(
		// Note: icon-box 'title_size' is a valid h-tag selector (h1-h6), not a font size.
		// Do NOT rename it to title_typography_font_size — that corrupts the value. (#190)
		'flip-box' => array(
			'front_title_text'       => 'title_text_a',
			'front_description_text' => 'description_text_a',
			'back_title_text'        => 'title_text_b',
			'back_description_text'  => 'description_text_b',
			'front_background_color' => 'background_color_a',
			'back_background_color'  => 'background_color_b',
		),
		'nav-menu' => array(
			'text_color'       => 'color_menu_item',
			'hover_color'      => 'color_menu_item_hover',
			'active_color'     => 'color_menu_item_active',
			'dropdown_color'   => 'color_dropdown_item',
			'dropdown_hover'   => 'color_dropdown_item_hover',
		),
		'button' => array(
			// Not a button control at all — it belongs to the `form` widget,
			// where it styles the submit button. On `button` it stored
			// cleanly, produced no CSS, and left every button falling through
			// to the global Kit accent colour (#789). Renamed rather than only
			// warned, because the caller's intent is unambiguous and the
			// correct key is known.
			'button_background_color' => 'background_color',
		),
		'posts' => array(
			'post_type'      => 'posts_post_type',
			'columns'        => 'classic_columns',
			'posts_per_page' => 'classic_posts_per_page',
			'show_title'     => 'classic_show_title',
			'show_excerpt'   => 'classic_show_excerpt',
			'excerpt_length' => 'classic_excerpt_length',
			'show_read_more' => 'classic_show_read_more',
			'read_more_text' => 'classic_read_more_text',
			'show_date'      => 'classic_show_date',
			'show_author'    => 'classic_show_author',
			'show_comments'  => 'classic_show_comments',
			'thumbnail_size' => 'classic_thumbnail_size_size',
			'orderby'        => 'posts_orderby',
			'order'          => 'posts_order',
		),
	);

	/**
	 * Valid top-level element types.
	 *
	 * @var array
	 */
	private static $valid_el_types = array(
		// Classic layout.
		'section', 'column', 'widget', 'container',
		// Elementor v4 Atomic elements (#211, #618).
		'e-div-block', 'e-flexbox', 'e-grid', 'e-heading', 'e-paragraph', 'e-button',
		'e-image', 'e-svg', 'e-divider', 'e-youtube', 'e-self-hosted-video',
	);

	/**
	 * Atomic content ("widget-equivalent") elTypes — self-typed leaf elements
	 * with no separate widgetType, unlike legacy widgets. (#618/#620)
	 *
	 * @var array
	 */
	private static $atomic_leaf_types = array(
		'e-heading', 'e-paragraph', 'e-button', 'e-image', 'e-svg',
		'e-divider', 'e-youtube', 'e-self-hosted-video',
	);

	/**
	 * Valid nesting rules: parent elType => allowed child elTypes.
	 *
	 * @var array<string, array<string>>
	 */
	private static $nesting_rules = array(
		// Classic layout.
		'section'     => array( 'column' ),
		'column'      => array( 'widget', 'section', 'container' ),
		'container'   => array( 'widget', 'container' ),
		// Elementor v4 Atomic layout (#211, #618) — layout elements can nest anything.
		'e-div-block' => array( 'e-div-block', 'e-flexbox', 'e-grid', 'e-heading', 'e-paragraph', 'e-button', 'e-image', 'e-svg', 'e-divider', 'e-youtube', 'e-self-hosted-video', 'widget', 'container' ),
		'e-flexbox'   => array( 'e-div-block', 'e-flexbox', 'e-grid', 'e-heading', 'e-paragraph', 'e-button', 'e-image', 'e-svg', 'e-divider', 'e-youtube', 'e-self-hosted-video', 'widget', 'container' ),
		'e-grid'      => array( 'e-div-block', 'e-flexbox', 'e-grid', 'e-heading', 'e-paragraph', 'e-button', 'e-image', 'e-svg', 'e-divider', 'e-youtube', 'e-self-hosted-video', 'widget', 'container' ),
	);

	/**
	 * Known Elementor free widget types.
	 *
	 * @var array
	 */
	private static $known_free_widgets = array(
		'heading', 'text-editor', 'image', 'video', 'button', 'divider',
		'spacer', 'google_maps', 'icon', 'image-box', 'icon-box', 'icon-list',
		'counter', 'progress', 'testimonial', 'tabs', 'accordion', 'toggle',
		'social-icons', 'alert', 'html', 'shortcode', 'menu-anchor',
		'sidebar', 'read-more', 'star-rating', 'basic-gallery', 'image-carousel',
		'wp-widget-pages', 'wp-widget-calendar', 'wp-widget-archives',
		'wp-widget-media_audio', 'wp-widget-media_image', 'wp-widget-media_gallery',
		'wp-widget-media_video', 'wp-widget-meta', 'wp-widget-search',
		'wp-widget-text', 'wp-widget-categories', 'wp-widget-recent-posts',
		'wp-widget-recent-comments', 'wp-widget-rss', 'wp-widget-tag_cloud',
		'wp-widget-nav_menu', 'wp-widget-custom_html', 'inner-section',
		'common', 'container', 'text-path', 'nested-tabs', 'nested-accordion',
		'nested-carousel', 'link-in-bio', 'off-canvas',
	);

	/**
	 * Known Elementor Pro widget types.
	 *
	 * @var array
	 */
	private static $known_pro_widgets = array(
		'posts', 'portfolio', 'gallery', 'form', 'login', 'slides',
		'nav-menu', 'animated-headline', 'price-list', 'price-table',
		'flip-box', 'call-to-action', 'media-carousel', 'testimonial-carousel',
		'reviews', 'table-of-contents', 'countdown', 'share-buttons',
		'blockquote', 'template', 'facebook-button', 'facebook-comments',
		'facebook-embed', 'facebook-page', 'search-form', 'post-navigation',
		'author-box', 'post-comments', 'post-info', 'post-title',
		'post-excerpt', 'post-content', 'post-featured-image', 'archive-title',
		'archive-posts', 'sitemap', 'lottie', 'hotspot', 'paypal-button',
		'stripe-button', 'progress-tracker', 'code-highlight',
		'video-playlist', 'mega-menu', 'loop-grid', 'loop-carousel',
		'taxonomy-filter',
		// Theme Builder widgets (theme- prefix).
		'theme-post-title', 'theme-post-content', 'theme-post-excerpt',
		'theme-post-featured-image', 'theme-post-info', 'theme-post-navigation',
		'theme-archive-title', 'theme-archive-posts', 'theme-site-logo',
		'theme-site-title', 'theme-page-title', 'theme-builder-comments',
		'theme-search-form', 'theme-author-box',
	);

	/**
	 * Get the list of registered widget type names from Elementor.
	 *
	 * Falls back to a hardcoded list if the widget manager is not available.
	 *
	 * @return array Widget type names.
	 */
	private function get_registered_widgets() {
		// Start with live registry if available.
		//
		// Read through the registry helper rather than reaching into
		// Elementor here: this call site used to invoke get_widget_types()
		// with no try/catch, so a third-party widget whose registration threw
		// took the whole save down with it. The helper turns that into "could
		// not read", which is what the merge below is already built to handle.
		$live    = Mcpwp_Elementor_Registry::live_types();
		$widgets = null === $live ? array() : $live;

		// Always merge both hardcoded lists so known widgets never trigger false warnings.
		// Pro widgets are harmless to allow — they simply won't render without Pro.
		$widgets = array_unique( array_merge( $widgets, self::$known_free_widgets, self::$known_pro_widgets ) );
		return $widgets;
	}

	/**
	 * Find closest match for a widget type using Levenshtein distance.
	 *
	 * @param string $input      Unknown widget type.
	 * @param array  $candidates Known widget types.
	 * @return string|null Closest match or null if none close enough.
	 */
	private function find_closest_widget( $input, $candidates ) {
		$best_match    = null;
		$best_distance = PHP_INT_MAX;

		foreach ( $candidates as $candidate ) {
			$distance = levenshtein( $input, $candidate );
			if ( $distance < $best_distance && $distance <= 3 ) {
				$best_distance = $distance;
				$best_match    = $candidate;
			}
		}

		return $best_match;
	}

	/**
	 * Get valid widget setting keys, preferring the live Elementor widget schema.
	 *
	 * Falls back to the static reference registry when live controls are unavailable.
	 *
	 * @param string $widget_type Widget type name.
	 * @return array Setting key names.
	 */
	private function get_valid_widget_keys( $widget_type ) {
		// Via the registry helper, which catches a throwing registry. This was
		// the second of two unguarded get_widget_types() calls on the save
		// path; either one turned a misbehaving third-party widget into a
		// fatal on every Elementor write.
		$live = Mcpwp_Elementor_Registry::live_control_keys( $widget_type );
		$keys = is_array( $live ) ? $live : array();

		// Always merge in our static reference schema — it may know about keys
		// that the live widget registry doesn't expose via get_controls() (e.g.
		// responsive base keys like 'align' on icon-box).
		$static_keys = Mcpwp_Elementor_Widgets::get_valid_keys( $widget_type );
		$keys = array_merge( $keys, $static_keys );

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Get the controls schema for a specific widget type.
	 *
	 * @param string $widget_type Widget type name (e.g. 'heading', 'image').
	 * @return array|WP_Error Schema data or error.
	 */
	public function get_widget_schema( $widget_type ) {
		if ( ! $this->is_active() ) {
			return new WP_Error(
				'elementor_not_active',
				__( 'Elementor is not installed or active.', 'mcpwp' ),
				array( 'status' => 400 )
			);
		}

		if ( ! class_exists( '\Elementor\Plugin' ) || empty( \Elementor\Plugin::$instance->widgets_manager ) ) {
			return new WP_Error(
				'elementor_not_loaded',
				__( 'Elementor widget manager not available.', 'mcpwp' ),
				array( 'status' => 500 )
			);
		}

		$manager = \Elementor\Plugin::$instance->widgets_manager;
		$types   = $manager->get_widget_types();

		if ( empty( $widget_type ) ) {
			// List all widget types, enriched with reference data.
			$list = array();
			foreach ( $types as $name => $widget ) {
				$entry = array(
					'name'  => $name,
					'title' => $widget->get_title(),
					'icon'  => $widget->get_icon(),
				);
				$ref = Mcpwp_Elementor_Widgets::get( $name );
				if ( $ref ) {
					$entry['description']   = $ref['description'];
					$entry['category']      = $ref['category'];
					$entry['has_reference'] = true;
				} else {
					$entry['has_reference'] = false;
				}
				$list[] = $entry;
			}
			return array(
				'widgets' => $list,
				'count'   => count( $list ),
				'tip'     => 'Use wp_elementor_widget_help(widget_type) for full offline reference with example JSON and common mistakes.',
			);
		}

		$widget = isset( $types[ $widget_type ] ) ? $types[ $widget_type ] : null;

		// (#618/#621) Atomic container elements (e-div-block, e-flexbox, e-grid,
		// tabs group) register via Elementor's separate elements_manager, not
		// widgets_manager — confirmed via elementor/modules/atomic-widgets/module.php.
		// Atomic leaf widgets (e-heading, e-button, ...) DO register in
		// widgets_manager alongside legacy widgets, so those are already found above.
		if ( ! $widget && ! empty( \Elementor\Plugin::$instance->elements_manager ) ) {
			$element = \Elementor\Plugin::$instance->elements_manager->get_element_types( $widget_type );
			if ( $element ) {
				$widget = $element;
			}
		}

			if ( ! $widget ) {
				$suggestion = $this->find_closest_widget( $widget_type, array_keys( $types ) );
				/* translators: %s: Elementor widget type */
				$msg        = sprintf( __( "Unknown widget type '%s'.", 'mcpwp' ), $widget_type );
				if ( $suggestion ) {
					/* translators: %s: suggested Elementor widget type */
					$msg .= sprintf( __( " Did you mean '%s'?", 'mcpwp' ), $suggestion );
				}
				return new WP_Error(
				'unknown_widget',
				$msg,
				array(
					'status' => 404,
					'hint'   => sprintf(
						'Widget type "%s" does not exist.%s Use wp_get_elementor_widgets to list all available widget types on this site.',
						$widget_type,
						$suggestion ? sprintf( ' Did you mean "%s"?', $suggestion ) : ''
					),
					'guide'  => 'wp_get_guide(topic=\'elementor_widgets\')',
				)
			);
		}

		// (#618/#621) Atomic elements expose a real, introspectable schema via
		// get_props_schema() (props/styles, not legacy Controls_Manager controls) —
		// confirmed directly against Elementor 4.1.3 source
		// (modules/atomic-widgets/elements/base/has-atomic-base.php:310). Use it
		// instead of get_controls(), which returns nothing useful for these.
		if ( method_exists( $widget, 'get_props_schema' ) ) {
			$schema       = $widget::get_props_schema();
			$props_plain  = json_decode( wp_json_encode( $schema ), true );

			$result = array(
				'widget'    => $widget_type,
				'title'     => $widget->get_title(),
				'icon'      => $widget->get_icon(),
				'is_atomic' => true,
				'props'     => is_array( $props_plain ) ? $props_plain : array(),
				'note'      => 'Atomic Elements (Editor V4) prop — set values as {"$$type": "<kind>", "value": <actual>} per the "kind"/"key" shown for each prop, not a plain scalar.',
			);

			$ref = Mcpwp_Elementor_Widgets::get( $widget_type );
			if ( $ref ) {
				$result['description']     = $ref['description'];
				$result['category']        = $ref['category'];
				$result['example']         = $ref['example'];
				$result['common_mistakes'] = $ref['common_mistakes'];
			}

			return $result;
		}

		$controls = $widget->get_controls();
		$grouped  = array();

		foreach ( $controls as $key => $control ) {
			// Skip internal/hidden controls.
			if ( ! empty( $control['is_internal'] ) ) {
				continue;
			}

			$tab = isset( $control['tab'] ) ? $control['tab'] : 'content';
			if ( ! isset( $grouped[ $tab ] ) ) {
				$grouped[ $tab ] = array();
			}

			$entry = array(
				'name'    => $key,
				'type'    => isset( $control['type'] ) ? $control['type'] : 'unknown',
				'label'   => isset( $control['label'] ) ? $control['label'] : $key,
			);

			if ( isset( $control['default'] ) && '' !== $control['default'] ) {
				$entry['default'] = $control['default'];
			}
			if ( ! empty( $control['options'] ) ) {
				$entry['options'] = $control['options'];
			}
			if ( ! empty( $control['selectors'] ) ) {
				$entry['selectors'] = $control['selectors'];
			}

			$grouped[ $tab ][] = $entry;
		}

		$result = array(
			'widget' => $widget_type,
			'title'  => $widget->get_title(),
			'icon'   => $widget->get_icon(),
			'tabs'   => $grouped,
		);

		// Enrich with offline reference data if available.
		$ref = Mcpwp_Elementor_Widgets::get( $widget_type );
		if ( $ref ) {
			$result['description']     = $ref['description'];
			$result['category']        = $ref['category'];
			$result['example']         = $ref['example'];
			$result['common_mistakes'] = $ref['common_mistakes'];
		}

		return $result;
	}

	/**
	 * Find an element by ID (read-only, returns copy).
	 *
	 * @param array  $elements Element tree.
	 * @param string $id       Target element ID.
	 * @return array|null Element copy or null.
	 */
	private function find_element_by_id_readonly( $elements, $id ) {
		foreach ( $elements as $el ) {
			if ( isset( $el['id'] ) && (string) $el['id'] === $id ) {
				return $el;
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$found = $this->find_element_by_id_readonly( $el['elements'], $id );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	/**
	 * Find an element by criteria (read-only, returns copy).
	 *
	 * @param array $elements Element tree.
	 * @param array $criteria Search criteria.
	 * @return array|null Element copy or null.
	 */
	private function find_element_by_criteria_readonly( $elements, $criteria ) {
		foreach ( $elements as $el ) {
			if ( $this->element_matches_criteria( $el, $criteria ) ) {
				return $el;
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$found = $this->find_element_by_criteria_readonly( $el['elements'], $criteria );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	/**
	 * Get rendered HTML content for an Elementor page.
	 *
	 * Uses Elementor's frontend builder to render the page content to HTML,
	 * without the full page template/header/footer.
	 *
	 * @since 1.1.22
	 *
	 * @param int $post_id Post ID.
	 * @return array|WP_Error Rendered content data or error.
	 */
	/**
	 * Get rendered HTML preview of Elementor content.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $format  Output format: "summary" (text + stats, no HTML), "text" (full text), "html" (full HTML).
	 * @return array|WP_Error Rendered content or error.
	 */
	public function get_rendered_content( $post_id, $format = 'summary' ) {
		if ( ! $this->is_active() ) {
			return new WP_Error(
				'elementor_not_active',
				__( 'Elementor is not installed or active.', 'mcpwp' ),
				array( 'status' => 400 )
			);
		}

		$post = $this->validate_post( $post_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return new WP_Error(
				'elementor_not_loaded',
				__( 'Elementor plugin class not available.', 'mcpwp' ),
				array( 'status' => 500 )
			);
		}

		// Check if the post has Elementor data.
		$elementor_data_raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( empty( $elementor_data_raw ) ) {
			return new WP_Error(
				'no_elementor_data',
				__( 'This page has no Elementor data.', 'mcpwp' ),
				array( 'status' => 404 )
			);
		}

		// Normalise format.
		$format = in_array( $format, array( 'html', 'text', 'summary' ), true ) ? $format : 'summary';

		// Build element statistics from the raw JSON structure.
		$stats = $this->compute_element_stats( $elementor_data_raw );

		// Preview URL.
		$preview_url = get_preview_post_link( $post_id );

		// Use Elementor's frontend to render the content.
		$frontend = \Elementor\Plugin::$instance->frontend;
		$html     = '';

		if ( $frontend ) {
			$html = $frontend->get_builder_content( $post_id, true );
		}

		// If rendering failed, build a fallback text from the raw JSON.
		if ( empty( $html ) ) {
			$fallback_text = $this->extract_text_from_elementor_json( $elementor_data_raw );

			$response = array(
				'id'          => $post_id,
				'title'       => get_the_title( $post_id ),
				'format'      => $format,
				'text'        => $fallback_text ? mb_substr( $fallback_text, 0, 500 ) : '',
				'stats'       => $stats,
				'preview_url' => $preview_url,
				'message'     => __( 'Elementor returned empty HTML. Text extracted from raw data instead. The page may need to be saved in the Elementor editor first.', 'mcpwp' ),
			);

			return $response;
		}

		// Extract plain text from rendered HTML.
		$full_text = $this->html_to_plain_text( $html );

		// Build response based on requested format.
		$response = array(
			'id'          => $post_id,
			'title'       => get_the_title( $post_id ),
			'format'      => $format,
			'stats'       => $stats,
			'preview_url' => $preview_url,
		);

		switch ( $format ) {
			case 'html':
				$response['html'] = $html;
				$response['text'] = $full_text;
				break;

			case 'text':
				$response['text'] = $full_text;
				break;

			case 'summary':
			default:
				// Truncate to ~500 chars for AI consumption.
				$response['text'] = mb_substr( $full_text, 0, 500 );
				if ( mb_strlen( $full_text ) > 500 ) {
					$response['text'] .= '...';
				}
				break;
		}

		return $response;
	}

	/**
	 * Compute element statistics from raw Elementor JSON.
	 *
	 * @param string $elementor_data_raw Raw JSON string.
	 * @return array Stats array with sections, columns, widgets, widget_types, word_count.
	 */
	private function compute_element_stats( $elementor_data_raw ) {
		// In whatever form it is stored (#845).
		$stored   = Mcpwp_Elementor_Stored_Tree::read( $elementor_data_raw );
		$elements = $stored['elements'];

		if ( Mcpwp_Elementor_Stored_Tree::is_unusable( $stored ) ) {
			// Zero counts for data that could not be read would describe an
			// empty page. Unknown is said as unknown.
			return array(
				'sections'     => null,
				'columns'      => null,
				'widgets'      => null,
				'widget_types' => null,
				'word_count'   => null,
				'unreadable'   => true,
				'stored_as'    => $stored['stored_as'],
			);
		}

		$stats = array(
			'sections'     => 0,
			'columns'      => 0,
			'widgets'      => 0,
			'widget_types' => array(),
			'word_count'   => 0,
		);

		$all_text = '';
		$this->walk_elements_for_stats( $elements, $stats, $all_text );

		$stats['widget_types'] = array_values( array_unique( $stats['widget_types'] ) );
		$stats['word_count']   = str_word_count( $all_text );

		return $stats;
	}

	/**
	 * Recursively walk elements to gather stats and text.
	 *
	 * @param array  $elements Elements array.
	 * @param array  $stats    Stats array (by reference).
	 * @param string $all_text Accumulated text (by reference).
	 */
	private function walk_elements_for_stats( $elements, &$stats, &$all_text ) {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) || empty( $element['elType'] ) ) {
				continue;
			}

			$el_type = $element['elType'];

			if ( 'section' === $el_type || 'container' === $el_type ) {
				$stats['sections']++;
			} elseif ( 'column' === $el_type ) {
				$stats['columns']++;
			} elseif ( in_array( $el_type, array( 'e-div-block', 'e-flexbox', 'e-grid' ), true ) ) {
				// (#618/#620) Atomic layout/container elements — same role as section/container.
				$stats['sections']++;
			} elseif ( 'widget' === $el_type ) {
				$stats['widgets']++;
				if ( ! empty( $element['widgetType'] ) ) {
					$stats['widget_types'][] = $element['widgetType'];
				}

				// Extract text from common settings.
				$settings  = isset( $element['settings'] ) ? $element['settings'] : array();
				$text_keys = array( 'title', 'editor', 'text', 'description', 'html', 'caption', 'content', 'inner_text', 'prefix', 'suffix', 'before_text', 'after_text', 'highlighted_text', 'rotating_text' );
				foreach ( $text_keys as $key ) {
					if ( ! empty( $settings[ $key ] ) && is_string( $settings[ $key ] ) ) {
						$all_text .= ' ' . wp_strip_all_tags( $settings[ $key ] );
					}
				}
			} elseif ( in_array( $el_type, self::$atomic_leaf_types, true ) ) {
				// (#618/#620) Atomic content elements — same role as legacy 'widget'.
				$stats['widgets']++;
				$stats['widget_types'][] = $el_type;

				$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
				foreach ( $settings as $value ) {
					$unwrapped = $this->unwrap_atomic_prop_value( $value );
					if ( is_string( $unwrapped ) && '' !== $unwrapped ) {
						$all_text .= ' ' . wp_strip_all_tags( $unwrapped );
					}
				}
			}

			// Recurse into children.
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$this->walk_elements_for_stats( $element['elements'], $stats, $all_text );
			}
		}
	}

	/**
	 * Convert HTML to plain text, collapsing whitespace.
	 *
	 * @param string $html HTML string.
	 * @return string Plain text.
	 */
	private function html_to_plain_text( $html ) {
		// Replace block-level tags with newlines for readability.
		$html = preg_replace( '/<\/(p|div|h[1-6]|li|tr|section|article|header|footer|blockquote)>/i', "\n", $html );
		$html = preg_replace( '/<br\s*\/?>/i', "\n", $html );

		// Strip remaining tags.
		$text = wp_strip_all_tags( $html );

		// Decode HTML entities.
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );

		// Collapse whitespace: multiple spaces to single, multiple newlines to double.
		$text = preg_replace( '/[^\S\n]+/', ' ', $text );
		$text = preg_replace( '/\n{3,}/', "\n\n", $text );

		return trim( $text );
	}

	/**
	 * Extract text content from raw Elementor JSON (fallback when rendering fails).
	 *
	 * @param string $elementor_data_raw Raw JSON string.
	 * @return string Extracted text.
	 */
	private function extract_text_from_elementor_json( $elementor_data_raw ) {
		// In whatever form it is stored (#845).
		$elements = Mcpwp_Elementor_Stored_Tree::read( $elementor_data_raw )['elements'];
		if ( ! is_array( $elements ) ) {
			return '';
		}

		$text = '';
		$this->walk_elements_for_text( $elements, $text );

		return trim( $text );
	}

	/**
	 * Recursively extract text from elements.
	 *
	 * @param array  $elements Elements array.
	 * @param string $text     Accumulated text (by reference).
	 */
	private function walk_elements_for_text( $elements, &$text ) {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$settings  = isset( $element['settings'] ) ? $element['settings'] : array();
			$text_keys = array( 'title', 'editor', 'text', 'description', 'html', 'caption', 'content', 'inner_text', 'prefix', 'suffix', 'before_text', 'after_text', 'highlighted_text', 'rotating_text' );

			foreach ( $text_keys as $key ) {
				if ( ! empty( $settings[ $key ] ) && is_string( $settings[ $key ] ) ) {
					$text .= ' ' . wp_strip_all_tags( $settings[ $key ] );
				}
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$this->walk_elements_for_text( $element['elements'], $text );
			}
		}
	}
}
