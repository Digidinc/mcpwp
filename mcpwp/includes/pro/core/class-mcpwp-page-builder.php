<?php
/**
 * Page Builder — Semantic Section Blueprints
 *
 * Generates valid Elementor JSON from high-level section definitions.
 *
 * @package MCPWP_Pro
 * @since   1.1.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build pages from semantic section blueprints.
 */
class Mcpwp_Page_Builder {

	/**
	 * Shared basic Elementor handler.
	 *
	 * @var Mcpwp_Elementor_Basic|null
	 */
	private $basic_handler = null;

	/**
	 * Supported section types.
	 *
	 * @var array
	 */
	private static $supported_types = array(
		'hero', 'features', 'cta', 'pricing', 'faq',
		'testimonials', 'text', 'gallery',
		'contact_form', 'map', 'countdown', 'stats', 'logo_grid', 'video',
		'team', 'portfolio', 'blog_grid', 'services', 'about',
		'process_steps', 'social_proof', 'product_showcase', 'before_after', 'newsletter',
	);

	/**
	 * Inject the #1032 write-side gate, forwarded to the shared basic
	 * handler — see Mcpwp_Api_Auth::template_ref_gate(). Every blueprint
	 * section here is built server-side from a fixed catalog and never
	 * accepts a caller-supplied template_id/templateID, so this is
	 * defense-in-depth rather than a live path: build() still writes through
	 * set_elementor_data(), which runs the same check regardless.
	 *
	 * @param callable|null $callback function(int[] $ids): true|WP_Error.
	 * @return void
	 */
	public function set_template_ref_gate( $callback ) {
		$this->get_basic_handler()->set_template_ref_gate( $callback );
	}

	/**
	 * Get the shared basic Elementor handler.
	 *
	 * @return Mcpwp_Elementor_Basic
	 */
	private function get_basic_handler() {
		if ( null === $this->basic_handler ) {
			$this->basic_handler = new Mcpwp_Elementor_Basic();
		}

		return $this->basic_handler;
	}

	/**
	 * Build a page from section definitions.
	 *
	 * @param string $title    Page title.
	 * @param array  $sections Array of section definitions.
	 * @param string $status   Post status (default: draft).
	 * @return array|WP_Error Created page with Elementor data.
	 */
	public function build( $title, $sections, $status = 'draft' ) {
		if ( empty( $title ) ) {
			return new WP_Error( 'missing_title', __( 'Page title is required.', 'mcpwp' ) );
		}

		if ( empty( $sections ) || ! is_array( $sections ) ) {
			return new WP_Error( 'missing_sections', __( 'At least one section is required.', 'mcpwp' ) );
		}

		// Detect layout mode.
		$use_containers = $this->use_containers();

		// Build Elementor elements from section definitions.
		$elements = array();
		$warnings = array();

		foreach ( $sections as $i => $section ) {
			$type = isset( $section['type'] ) ? $section['type'] : '';
			if ( ! in_array( $type, self::$supported_types, true ) ) {
				$warnings[] = sprintf( 'Section %d: unknown type "%s". Supported: %s', $i, $type, implode( ', ', self::$supported_types ) );
				continue;
			}

			$method = 'build_' . $type;
			$result = $this->$method( $section, $use_containers );
			if ( $result ) {
				// Some builders return arrays of elements (features, pricing, testimonials).
				if ( isset( $result['id'] ) ) {
					$elements[] = $result;
				} elseif ( is_array( $result ) ) {
					foreach ( $result as $el ) {
						$elements[] = $el;
					}
				}
			}
		}

		if ( empty( $elements ) ) {
			return new WP_Error( 'no_valid_sections', __( 'No valid sections to build.', 'mcpwp' ) );
		}

		$effective_status = in_array( $status, array( 'draft', 'publish', 'private' ), true ) ? $status : 'draft';

		// Content guardrails (#957/#998): enforced centrally by
		// Mcpwp_Content_Guardrails::on_insert_post_data() — not here. The
		// built $elements are front-loaded into meta_input on THIS SAME
		// wp_insert_post() call — a real wp_insert_post() feature that
		// writes postmeta atomically alongside the post — the same
		// technique create_landing_page() and clone_elementor_page() already
		// use, so the guardrails gate judges thin/duplicate content against
		// what this page ACTUALLY contains. Without it, the page used to be
		// inserted with no Elementor data at all (written to postmeta only
		// afterward, below), so the gate always saw 0 words: correctly
		// harmless for the ramp/duplicate decision (thin content never
		// downgrades — see resolve_comparable_texts()'s docblock) but a
		// false "word_count: 0" reported back for a page with real,
		// multi-section visible text (#998).
		$page_id = wp_insert_post( array(
			'post_title'  => sanitize_text_field( $title ),
			'post_status' => $effective_status,
			'post_type'   => 'page',
			// Slashed, as update_post_meta() (which meta_input calls) expects (#829).
			'meta_input'  => array( '_elementor_data' => wp_slash( $elements ) ),
		) );

		if ( is_wp_error( $page_id ) ) {
			return $page_id;
		}

		// Initialize Elementor document meta so the editor recognizes the page.
		update_post_meta( $page_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $page_id, '_elementor_template_type', 'wp-page' );
		update_post_meta( $page_id, '_wp_page_template', 'elementor_header_footer' );

		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			update_post_meta( $page_id, '_elementor_version', ELEMENTOR_VERSION );
		}
		if ( defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			update_post_meta( $page_id, '_elementor_pro_version', ELEMENTOR_PRO_VERSION );
		}

		$save_result = $this->get_basic_handler()->set_elementor_data(
			$page_id,
			array(
				'elementor_data' => $elements,
			)
		);
		if ( is_wp_error( $save_result ) ) {
			return $save_result;
		}

		// Final verification: confirm data is in the database.
		wp_cache_delete( $page_id, 'post_meta' );
		$final_stored  = get_post_meta( $page_id, '_elementor_data', true );
		// In whatever form is stored (#845): a stale cache can still hold an array.
		$final_decoded = Mcpwp_Elementor_Stored_Tree::read( $final_stored )['elements'];
		$final_count   = is_array( $final_decoded ) ? count( $final_decoded ) : 0;
		$meta_verified = ( $final_count === count( $elements ) );

		$page = get_post( $page_id );

		// set_elementor_data() decorates its own result with render_verified
		// (and render_errors/renders_via_elementor when the render pipeline
		// didn't complete) via the SAME decorate_render_status() every
		// wp_set_elementor call goes through — pass it through here too so
		// a caller doesn't have to know build_page's response used to be
		// missing exactly the field its guide tells them to check (#998).
		$response = array(
			'id'              => $page_id,
			'title'           => $page->post_title,
			'status'          => $page->post_status,
			'link'            => get_permalink( $page_id ),
			'edit_url'        => admin_url( "post.php?post={$page_id}&action=elementor" ),
			'section_count'   => count( $elements ),
			'save_method'     => isset( $save_result['save_method'] ) ? $save_result['save_method'] : null,
			'meta_verified'   => $meta_verified,
			'sections_saved'  => $final_count,
			'render_verified' => isset( $save_result['render_verified'] ) ? (bool) $save_result['render_verified'] : true,
			'warnings'        => array_merge( $warnings, isset( $save_result['warnings'] ) ? (array) $save_result['warnings'] : array() ),
			'debug'           => isset( $save_result['debug'] ) ? $save_result['debug'] : array(),
		);

		if ( isset( $save_result['render_errors'] ) ) {
			$response['render_errors'] = $save_result['render_errors'];
		}
		if ( isset( $save_result['renders_via_elementor'] ) ) {
			$response['renders_via_elementor'] = $save_result['renders_via_elementor'];
		}

		return $response;
	}

	/**
	 * Check if site uses container (flexbox) layout.
	 *
	 * @return bool
	 */
	private function use_containers() {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			return false;
		}
		return Mcpwp_Elementor_Experiments::is_container_active();
	}

	/**
	 * Generate an element ID Elementor Pro's AJAX endpoints accept (#842).
	 *
	 * @return string Seven lowercase alphanumerics.
	 */
	private function id() {
		return Mcpwp_Elementor_Element_Ids::generate();
	}

	// ---------------------------------------------------------------
	// Blueprint Catalog + Single Section Builder
	// ---------------------------------------------------------------

	/**
	 * Get the blueprint catalog — all supported section types with parameter schemas.
	 *
	 * @return array Array of blueprint type definitions.
	 */
	public static function get_blueprint_catalog() {
		return array(
			'hero'         => array(
				'description' => 'Full-width hero banner with heading, subheading, CTA button, and background.',
				'params'      => array(
					'heading'    => array( 'type' => 'string', 'default' => 'Welcome' ),
					'subheading' => array( 'type' => 'string', 'default' => '' ),
					'cta_text'   => array( 'type' => 'string', 'default' => '' ),
					'cta_url'    => array( 'type' => 'string', 'default' => '#' ),
					'background' => array( 'type' => 'string', 'description' => 'Color hex (#1a1a2e), "gradient", or empty' ),
					'image_url'  => array( 'type' => 'string', 'default' => '' ),
				),
			),
			'features'     => array(
				'description' => 'Multi-column feature grid with icons, titles, and descriptions.',
				'params'      => array(
					'heading' => array( 'type' => 'string', 'default' => '' ),
					'columns' => array( 'type' => 'integer', 'default' => 3, 'min' => 2, 'max' => 4 ),
					'items'   => array( 'type' => 'array', 'description' => 'Array of {icon, title, desc}' ),
				),
			),
			'cta'          => array(
				'description' => 'Call-to-action banner with heading, subheading, and button.',
				'params'      => array(
					'heading'     => array( 'type' => 'string', 'default' => '' ),
					'subheading'  => array( 'type' => 'string', 'default' => '' ),
					'button_text' => array( 'type' => 'string', 'default' => 'Get Started' ),
					'button_url'  => array( 'type' => 'string', 'default' => '#' ),
					'background'  => array( 'type' => 'string', 'default' => '' ),
				),
			),
			'pricing'      => array(
				'description' => 'Pricing comparison table with plan columns.',
				'params'      => array(
					'heading' => array( 'type' => 'string', 'default' => '' ),
					'plans'   => array( 'type' => 'array', 'description' => 'Array of {title, price, period, features[], button_text, button_url}' ),
				),
			),
			'faq'          => array(
				'description' => 'FAQ section with question/answer pairs.',
				'params'      => array(
					'heading' => array( 'type' => 'string', 'default' => '' ),
					'items'   => array( 'type' => 'array', 'description' => 'Array of {question, answer}' ),
				),
			),
			'testimonials' => array(
				'description' => 'Testimonial cards with quotes, names, and optional images.',
				'params'      => array(
					'heading' => array( 'type' => 'string', 'default' => '' ),
					'items'   => array( 'type' => 'array', 'description' => 'Array of {text, name, title, image}' ),
				),
			),
			'text'         => array(
				'description' => 'Simple text content section with heading and rich text.',
				'params'      => array(
					'heading' => array( 'type' => 'string', 'default' => '' ),
					'content' => array( 'type' => 'string', 'description' => 'HTML content' ),
				),
			),
			'gallery'      => array(
				'description' => 'Image gallery grid.',
				'params'      => array(
					'heading' => array( 'type' => 'string', 'default' => '' ),
					'images'  => array( 'type' => 'array', 'description' => 'Array of image URLs' ),
					'columns' => array( 'type' => 'integer', 'default' => 3 ),
				),
			),
			'contact_form' => array(
				'description' => 'Contact form embed section.',
				'params'      => array(
					'heading'    => array( 'type' => 'string', 'default' => '' ),
					'subheading' => array( 'type' => 'string', 'default' => '' ),
					'form_id'    => array( 'type' => 'integer', 'description' => 'Form ID' ),
					'plugin'     => array( 'type' => 'string', 'description' => 'wpforms, cf7, or gravity' ),
				),
			),
			'map'          => array(
				'description' => 'Google Maps embed.',
				'params'      => array(
					'heading' => array( 'type' => 'string', 'default' => '' ),
					'address' => array( 'type' => 'string', 'required' => true ),
					'zoom'    => array( 'type' => 'integer', 'default' => 14, 'min' => 1, 'max' => 20 ),
					'height'  => array( 'type' => 'integer', 'default' => 300 ),
				),
			),
			'countdown'    => array(
				'description' => 'Countdown timer to a target date.',
				'params'      => array(
					'heading'    => array( 'type' => 'string', 'default' => '' ),
					'due_date'   => array( 'type' => 'string', 'description' => 'YYYY-MM-DD HH:MM', 'required' => true ),
					'subheading' => array( 'type' => 'string', 'default' => '' ),
				),
			),
			'stats'        => array(
				'description' => 'Statistics/counter section with animated numbers.',
				'params'      => array(
					'heading' => array( 'type' => 'string', 'default' => '' ),
					'columns' => array( 'type' => 'integer', 'default' => 3 ),
					'items'   => array( 'type' => 'array', 'description' => 'Array of {number, title, suffix}' ),
				),
			),
			'logo_grid'    => array(
				'description' => 'Logo/partner grid with optional links.',
				'params'      => array(
					'heading' => array( 'type' => 'string', 'default' => '' ),
					'columns' => array( 'type' => 'integer', 'default' => 4 ),
					'items'   => array( 'type' => 'array', 'description' => 'Array of {image, url}' ),
				),
			),
			'video'           => array(
				'description' => 'Video embed section (YouTube, Vimeo, or hosted MP4).',
				'params'      => array(
					'heading'    => array( 'type' => 'string', 'default' => '' ),
					'url'        => array( 'type' => 'string', 'required' => true, 'description' => 'YouTube/Vimeo/MP4 URL' ),
					'subheading' => array( 'type' => 'string', 'default' => '' ),
				),
			),
			'team'            => array(
				'description' => 'Team member grid with photos, names, titles, bios, and social links.',
				'params'      => array(
					'heading' => array( 'type' => 'string', 'default' => 'Meet the Team' ),
					'columns' => array( 'type' => 'integer', 'default' => 3, 'min' => 2, 'max' => 4 ),
					'items'   => array( 'type' => 'array', 'description' => 'Array of {name, title, image, bio, social_links}' ),
				),
			),
			'portfolio'       => array(
				'description' => 'Project/work showcase grid with image, title, category, and optional link.',
				'params'      => array(
					'heading' => array( 'type' => 'string', 'default' => 'Our Work' ),
					'columns' => array( 'type' => 'integer', 'default' => 3, 'min' => 2, 'max' => 4 ),
					'items'   => array( 'type' => 'array', 'description' => 'Array of {title, image, category, url}' ),
				),
			),
			'blog_grid'       => array(
				'description' => 'Blog post card grid using the Elementor posts widget.',
				'params'      => array(
					'heading' => array( 'type' => 'string', 'default' => 'Latest Posts' ),
					'columns' => array( 'type' => 'integer', 'default' => 3, 'min' => 2, 'max' => 3 ),
					'count'   => array( 'type' => 'integer', 'default' => 3, 'description' => 'Number of posts to show' ),
				),
			),
			'services'        => array(
				'description' => 'Service offerings with icons, descriptions, optional price, and CTA button.',
				'params'      => array(
					'heading' => array( 'type' => 'string', 'default' => 'Our Services' ),
					'columns' => array( 'type' => 'integer', 'default' => 3, 'min' => 2, 'max' => 3 ),
					'items'   => array( 'type' => 'array', 'description' => 'Array of {icon, title, desc, price, button_text, url}' ),
				),
			),
			'about'           => array(
				'description' => 'About section with image and text side by side.',
				'params'      => array(
					'heading'        => array( 'type' => 'string', 'default' => 'About Us' ),
					'text'           => array( 'type' => 'string', 'default' => '' ),
					'image_url'      => array( 'type' => 'string', 'default' => '' ),
					'image_position' => array( 'type' => 'string', 'default' => 'left', 'description' => 'left or right' ),
					'button_text'    => array( 'type' => 'string', 'default' => '' ),
					'button_url'     => array( 'type' => 'string', 'default' => '#' ),
				),
			),
			'process_steps'   => array(
				'description' => 'Numbered how-it-works steps displayed horizontally.',
				'params'      => array(
					'heading' => array( 'type' => 'string', 'default' => 'How It Works' ),
					'items'   => array( 'type' => 'array', 'description' => 'Array of {number, title, desc, icon}' ),
				),
			),
			'social_proof'    => array(
				'description' => 'Trust/social proof section with star-rated quote cards.',
				'params'      => array(
					'heading'    => array( 'type' => 'string', 'default' => 'What People Say' ),
					'subheading' => array( 'type' => 'string', 'default' => '' ),
					'items'      => array( 'type' => 'array', 'description' => 'Array of {text, author, rating}' ),
				),
			),
			'product_showcase' => array(
				'description' => 'Single product highlight with image on one side and details on the other.',
				'params'      => array(
					'heading'     => array( 'type' => 'string', 'default' => '' ),
					'image_url'   => array( 'type' => 'string', 'default' => '' ),
					'title'       => array( 'type' => 'string', 'default' => '' ),
					'desc'        => array( 'type' => 'string', 'default' => '' ),
					'price'       => array( 'type' => 'string', 'default' => '' ),
					'button_text' => array( 'type' => 'string', 'default' => 'Buy Now' ),
					'button_url'  => array( 'type' => 'string', 'default' => '#' ),
					'features'    => array( 'type' => 'array', 'description' => 'Array of feature strings' ),
				),
			),
			'before_after'    => array(
				'description' => 'Two-column before/after comparison section.',
				'params'      => array(
					'heading'        => array( 'type' => 'string', 'default' => 'Before & After' ),
					'before_heading' => array( 'type' => 'string', 'default' => 'Before' ),
					'after_heading'  => array( 'type' => 'string', 'default' => 'After' ),
					'before_items'   => array( 'type' => 'array', 'description' => 'Array of strings describing before state' ),
					'after_items'    => array( 'type' => 'array', 'description' => 'Array of strings describing after state' ),
				),
			),
			'newsletter'      => array(
				'description' => 'Email signup CTA with heading, subheading, and button.',
				'params'      => array(
					'heading'          => array( 'type' => 'string', 'default' => 'Stay in the Loop' ),
					'subheading'       => array( 'type' => 'string', 'default' => '' ),
					'button_text'      => array( 'type' => 'string', 'default' => 'Subscribe' ),
					'placeholder_text' => array( 'type' => 'string', 'default' => 'Enter your email' ),
					'background'       => array( 'type' => 'string', 'default' => '' ),
				),
			),
		);
	}

	/**
	 * Build a single section from a blueprint type and params.
	 *
	 * Returns the raw Elementor element JSON (not a page).
	 *
	 * @param string $type   Blueprint type (hero, features, cta, etc.).
	 * @param array  $params Section params.
	 * @return array|WP_Error Elementor element(s) or error.
	 */
	public function build_single_section( $type, $params = array() ) {
		if ( ! in_array( $type, self::$supported_types, true ) ) {
			return new WP_Error(
				'invalid_blueprint',
				sprintf( 'Unknown blueprint type "%s". Supported: %s', $type, implode( ', ', self::$supported_types ) ),
				array( 'status' => 400 )
			);
		}

		$use_containers = $this->use_containers();
		$params['type'] = $type;
		$method         = 'build_' . $type;
		$result         = $this->$method( $params, $use_containers );

		if ( ! $result ) {
			return new WP_Error( 'build_failed', 'Blueprint build returned empty result.', array( 'status' => 500 ) );
		}

		// Normalize: some builders return a single element, others return an array of elements.
		if ( isset( $result['id'] ) ) {
			return array( 'elements' => array( $result ) );
		}

		return array( 'elements' => $result );
	}

	// ---------------------------------------------------------------
	// Section Builders
	// ---------------------------------------------------------------

	/**
	 * Build a hero section.
	 *
	 * @param array $p              Section params.
	 * @param bool  $use_containers Use container layout.
	 * @return array Elementor element.
	 */
	private function build_hero( $p, $use_containers ) {
		$heading    = isset( $p['heading'] ) ? $p['heading'] : 'Welcome';
		$subheading = isset( $p['subheading'] ) ? $p['subheading'] : '';
		$cta_text   = isset( $p['cta_text'] ) ? $p['cta_text'] : '';
		$cta_url    = isset( $p['cta_url'] ) ? $p['cta_url'] : '#';
		$background = isset( $p['background'] ) ? $p['background'] : '';
		$image_url  = isset( $p['image_url'] ) ? $p['image_url'] : '';

		$widgets = array();

		// Heading.
		$widgets[] = $this->widget( 'heading', array(
			'title'       => $heading,
			'header_size' => 'h1',
			'align'       => 'center',
			'title_color' => '#FFFFFF',
			'typography_typography' => 'custom',
			'typography_font_size'  => array( 'size' => 48, 'unit' => 'px' ),
		) );

		// Subheading.
		if ( $subheading ) {
			$widgets[] = $this->widget( 'heading', array(
				'title'       => $subheading,
				'header_size' => 'h3',
				'align'       => 'center',
				'title_color' => '#E0E0E0',
				'typography_typography' => 'custom',
				'typography_font_size'  => array( 'size' => 20, 'unit' => 'px' ),
			) );
		}

		// CTA button.
		if ( $cta_text ) {
			$widgets[] = $this->widget( 'button', array(
				'text'  => $cta_text,
				'link'  => array( 'url' => $cta_url, 'is_external' => false ),
				'align' => 'center',
				'button_type' => 'default',
				'size'  => 'lg',
			) );
		}

		// Section settings.
		$settings = array(
			'background_background' => 'classic',
			'background_color'      => '#1a1a2e',
			'padding'               => array( 'top' => '100', 'bottom' => '100', 'left' => '20', 'right' => '20', 'unit' => 'px' ),
		);

		if ( 'gradient' === $background ) {
			$settings['background_background']    = 'gradient';
			$settings['background_color']         = '#1a1a2e';
			$settings['background_color_b']       = '#16213e';
			$settings['background_gradient_type']  = 'linear';
			$settings['background_gradient_angle'] = array( 'size' => 135, 'unit' => 'deg' );
		} elseif ( $image_url ) {
			$settings['background_image'] = array( 'url' => $image_url );
			$settings['background_overlay_background'] = 'classic';
			$settings['background_overlay_color']      = 'rgba(0,0,0,0.5)';
		} elseif ( $background && '#' === substr( $background, 0, 1 ) ) {
			$settings['background_color'] = $background;
		}

		return $this->wrap_section( $widgets, $settings, $use_containers );
	}

	/**
	 * Build a features section.
	 *
	 * @param array $p              Section params.
	 * @param bool  $use_containers Use container layout.
	 * @return array Elementor element.
	 */
	private function build_features( $p, $use_containers ) {
		$heading = isset( $p['heading'] ) ? $p['heading'] : '';
		$columns = isset( $p['columns'] ) ? min( max( (int) $p['columns'], 2 ), 4 ) : 3;
		$items   = isset( $p['items'] ) && is_array( $p['items'] ) ? $p['items'] : array();

		$all_widgets = array();

		// Optional heading above the grid.
		if ( $heading ) {
			$all_widgets[] = $this->wrap_section(
				array( $this->widget( 'heading', array(
					'title'       => $heading,
					'header_size' => 'h2',
					'align'       => 'center',
				) ) ),
				array( 'padding' => array( 'top' => '60', 'bottom' => '20', 'unit' => 'px' ) ),
				$use_containers
			);
		}

		// Card width per column count for flex-wrap grid (30% ≈ 3-col, 22% ≈ 4-col, 47% ≈ 2-col).
		$card_widths = array( 2 => 47, 3 => 30, 4 => 22 );
		$card_width  = isset( $card_widths[ $columns ] ) ? $card_widths[ $columns ] : 30;

		// Build columns with icon-boxes.
		if ( $use_containers ) {
			$inner_containers = array();
			foreach ( $items as $item ) {
				$card_elements = array(
					$this->widget( 'icon-box', array(
						'selected_icon'              => array( 'value' => isset( $item['icon'] ) ? $item['icon'] : 'fas fa-star', 'library' => 'fa-solid' ),
						'title_text'                 => isset( $item['title'] ) ? $item['title'] : '',
						'description_text'           => isset( $item['desc'] ) ? $item['desc'] : ( isset( $item['description'] ) ? $item['description'] : '' ),
						'position'                   => 'top',
						'align'                      => 'left', // Fix #3: left-align icon + text
						'title_typography_font_size' => array( 'size' => 18, 'unit' => 'px' ),
					) ),
				);

				// Fix #2: render button when item provides text or URL.
				$btn_text = isset( $item['button_text'] ) ? $item['button_text'] : ( isset( $item['cta'] ) ? $item['cta'] : '' );
				if ( $btn_text ) {
					$btn_url         = isset( $item['url'] ) ? $item['url'] : ( isset( $item['link'] ) ? $item['link'] : '#' );
					$card_elements[] = $this->widget( 'button', array(
						'text'                       => $btn_text,
						'link'                       => array( 'url' => $btn_url, 'is_external' => false, 'nofollow' => false ),
						'align'                      => 'left',
						'size'                       => 'sm',
						'background_color'           => '#0073aa',
						'button_text_color'          => '#FFFFFF',
						'border_radius'              => array( 'top_left' => '6', 'top_right' => '6', 'bottom_right' => '6', 'bottom_left' => '6', 'unit' => 'px', 'isLinked' => true ),
						'hover_animation'            => 'float',
					) );
				}

				$inner_containers[] = array(
					'id'       => $this->id(),
					'elType'   => 'container',
					'settings' => array(
						'content_width'              => 'full',
						'_element_width'             => 'initial',                                                   // Fix #1: explicit width so flex-wrap creates columns
						'width'                      => array( 'size' => $card_width, 'unit' => '%' ),              // Fix #1
						'background_background'      => 'classic',                                                   // Fix #4: card background
						'background_color'           => '#FFFFFF',                                                   // Fix #4
						'border_radius'              => array(                                                        // Fix #4: 12px corners
							'top_left'     => '12',
							'top_right'    => '12',
							'bottom_right' => '12',
							'bottom_left'  => '12',
							'unit'         => 'px',
							'isLinked'     => true,
						),
						'box_shadow_box_shadow_type' => 'yes',                                                       // Fix #4: shadow
						'box_shadow_box_shadow'      => array(                                                       // Fix #4
							'horizontal' => 0,
							'vertical'   => 4,
							'blur'       => 20,
							'spread'     => 0,
							'color'      => 'rgba(0,0,0,0.08)',
						),
						'padding'                    => array( 'top' => '30', 'bottom' => '30', 'left' => '30', 'right' => '30', 'unit' => 'px', 'isLinked' => true ),
						'custom_css'                 => 'selector { transition: box-shadow 0.3s ease, transform 0.3s ease; } selector:hover { box-shadow: 0 8px 30px rgba(0,0,0,0.15); transform: translateY(-4px); }', // Fix #4: hover
					),
					'elements' => $card_elements,
				);
			}

			$all_widgets[] = array(
				'id'       => $this->id(),
				'elType'   => 'container',
				'settings' => array(
					'flex_direction'     => 'row',
					'flex_wrap'          => 'wrap',
					'flex_gap'           => array( 'size' => 20, 'unit' => 'px' ),
					'content_width'      => 'boxed',
					'padding'            => array( 'top' => '40', 'bottom' => '60', 'left' => '20', 'right' => '20', 'unit' => 'px' ),
				),
				'elements' => $inner_containers,
			);
		} else {
			// Classic section + columns.
			$col_size  = (int) floor( 100 / $columns );
			$structure = array( 2 => '20', 3 => '30', 4 => '40' );

			$column_elements = array();
			foreach ( $items as $item ) {
				$column_elements[] = array(
					'id'       => $this->id(),
					'elType'   => 'column',
					'settings' => array( '_column_size' => $col_size ),
					'elements' => array( $this->widget( 'icon-box', array(
						'selected_icon' => array( 'value' => isset( $item['icon'] ) ? $item['icon'] : 'fas fa-star', 'library' => 'fa-solid' ),
						'title_text'    => isset( $item['title'] ) ? $item['title'] : '',
						'description_text' => isset( $item['desc'] ) ? $item['desc'] : ( isset( $item['description'] ) ? $item['description'] : '' ),
						'position'      => 'top',
						'title_typography_font_size' => array( 'size' => 18, 'unit' => 'px' ),
					) ) ),
				);
			}

			$all_widgets[] = array(
				'id'       => $this->id(),
				'elType'   => 'section',
				'settings' => array(
					'structure' => isset( $structure[ $columns ] ) ? $structure[ $columns ] : '30',
					'padding'   => array( 'top' => '40', 'bottom' => '60', 'left' => '20', 'right' => '20', 'unit' => 'px' ),
				),
				'elements' => $column_elements,
			);
		}

		return $all_widgets;
	}

	/**
	 * Build a CTA section.
	 *
	 * @param array $p              Section params.
	 * @param bool  $use_containers Use container layout.
	 * @return array Elementor element.
	 */
	private function build_cta( $p, $use_containers ) {
		$heading     = isset( $p['heading'] ) ? $p['heading'] : 'Ready to Get Started?';
		$subheading  = isset( $p['subheading'] ) ? $p['subheading'] : '';
		$button_text = isset( $p['button_text'] ) ? $p['button_text'] : 'Get Started';
		$button_url  = isset( $p['button_url'] ) ? $p['button_url'] : '#';
		$background  = isset( $p['background'] ) ? $p['background'] : '#0073aa';

		$widgets = array();

		$widgets[] = $this->widget( 'heading', array(
			'title'       => $heading,
			'header_size' => 'h2',
			'align'       => 'center',
			'title_color' => '#FFFFFF',
		) );

		if ( $subheading ) {
			$widgets[] = $this->widget( 'text-editor', array(
				'editor'        => '<p style="text-align:center;color:#E0E0E0;">' . esc_html( $subheading ) . '</p>',
				'align'         => 'center',
			) );
		}

		$widgets[] = $this->widget( 'button', array(
			'text'  => $button_text,
			'link'  => array( 'url' => $button_url, 'is_external' => false ),
			'align' => 'center',
			'size'  => 'lg',
		) );

		return $this->wrap_section( $widgets, array(
			'background_background' => 'classic',
			'background_color'      => $background,
			'padding'               => array( 'top' => '80', 'bottom' => '80', 'left' => '20', 'right' => '20', 'unit' => 'px' ),
		), $use_containers );
	}

	/**
	 * Build a pricing section.
	 *
	 * @param array $p              Section params.
	 * @param bool  $use_containers Use container layout.
	 * @return array Elementor element.
	 */
	private function build_pricing( $p, $use_containers ) {
		$heading = isset( $p['heading'] ) ? $p['heading'] : 'Pricing';
		$plans   = isset( $p['plans'] ) && is_array( $p['plans'] ) ? $p['plans'] : ( isset( $p['items'] ) ? $p['items'] : array() );

		$elements = array();

		// Heading.
		$elements[] = $this->wrap_section(
			array( $this->widget( 'heading', array(
				'title'       => $heading,
				'header_size' => 'h2',
				'align'       => 'center',
			) ) ),
			array( 'padding' => array( 'top' => '60', 'bottom' => '20', 'unit' => 'px' ) ),
			$use_containers
		);

		// Build pricing cards as price-table widgets.
		$card_widgets = array();
		foreach ( $plans as $plan ) {
			$features_list = array();
			$features = isset( $plan['features'] ) ? $plan['features'] : array();
			foreach ( $features as $feature ) {
				$features_list[] = array(
					'item_text' => is_string( $feature ) ? $feature : ( $feature['text'] ?? '' ),
				);
			}

			$card_widgets[] = $this->widget( 'price-table', array(
				'heading'           => isset( $plan['title'] ) ? $plan['title'] : ( isset( $plan['name'] ) ? $plan['name'] : 'Plan' ),
				'sub_heading'       => isset( $plan['subtitle'] ) ? $plan['subtitle'] : '',
				'price'             => isset( $plan['price'] ) ? $plan['price'] : '0',
				'period'            => isset( $plan['period'] ) ? $plan['period'] : '/mo',
				'features_list'     => $features_list,
				'button_text'       => isset( $plan['button_text'] ) ? $plan['button_text'] : 'Choose Plan',
				'link'              => array( 'url' => isset( $plan['button_url'] ) ? $plan['button_url'] : '#' ),
			) );
		}

		if ( ! empty( $card_widgets ) ) {
			$columns = min( count( $card_widgets ), 4 );
			if ( $use_containers ) {
				$inner = array();
				foreach ( $card_widgets as $w ) {
					$inner[] = array(
						'id'       => $this->id(),
						'elType'   => 'container',
						'settings' => array( 'content_width' => 'full' ),
						'elements' => array( $w ),
					);
				}
				$elements[] = array(
					'id'       => $this->id(),
					'elType'   => 'container',
					'settings' => array(
						'flex_direction' => 'row',
						'flex_wrap'      => 'wrap',
						'flex_gap'       => array( 'size' => 20, 'unit' => 'px' ),
						'content_width'  => 'boxed',
						'padding'        => array( 'top' => '20', 'bottom' => '60', 'left' => '20', 'right' => '20', 'unit' => 'px' ),
					),
					'elements' => $inner,
				);
			} else {
				$col_size  = (int) floor( 100 / $columns );
				$structure = array( 2 => '20', 3 => '30', 4 => '40' );
				$cols = array();
				foreach ( $card_widgets as $w ) {
					$cols[] = array(
						'id'       => $this->id(),
						'elType'   => 'column',
						'settings' => array( '_column_size' => $col_size ),
						'elements' => array( $w ),
					);
				}
				$elements[] = array(
					'id'       => $this->id(),
					'elType'   => 'section',
					'settings' => array(
						'structure' => isset( $structure[ $columns ] ) ? $structure[ $columns ] : '30',
						'padding'   => array( 'top' => '20', 'bottom' => '60', 'left' => '20', 'right' => '20', 'unit' => 'px' ),
					),
					'elements' => $cols,
				);
			}
		}

		return $elements;
	}

	/**
	 * Build an FAQ section.
	 *
	 * @param array $p              Section params.
	 * @param bool  $use_containers Use container layout.
	 * @return array Elementor element.
	 */
	private function build_faq( $p, $use_containers ) {
		$heading = isset( $p['heading'] ) ? $p['heading'] : 'Frequently Asked Questions';
		$items   = isset( $p['items'] ) && is_array( $p['items'] ) ? $p['items'] : array();

		$tabs = array();
		foreach ( $items as $item ) {
			$tabs[] = array(
				'tab_title'   => isset( $item['question'] ) ? $item['question'] : ( isset( $item['q'] ) ? $item['q'] : '' ),
				'tab_content' => isset( $item['answer'] ) ? $item['answer'] : ( isset( $item['a'] ) ? $item['a'] : '' ),
			);
		}

		$widgets = array();

		$widgets[] = $this->widget( 'heading', array(
			'title'       => $heading,
			'header_size' => 'h2',
			'align'       => 'center',
		) );

		if ( ! empty( $tabs ) ) {
			$widgets[] = $this->widget( 'accordion', array(
				'tabs' => $tabs,
			) );
		}

		return $this->wrap_section( $widgets, array(
			'padding'       => array( 'top' => '60', 'bottom' => '60', 'left' => '20', 'right' => '20', 'unit' => 'px' ),
			'content_width' => array( 'size' => 800, 'unit' => 'px' ),
		), $use_containers );
	}

	/**
	 * Build a testimonials section.
	 *
	 * @param array $p              Section params.
	 * @param bool  $use_containers Use container layout.
	 * @return array Elementor element.
	 */
	private function build_testimonials( $p, $use_containers ) {
		$heading = isset( $p['heading'] ) ? $p['heading'] : 'What Our Clients Say';
		$items   = isset( $p['items'] ) && is_array( $p['items'] ) ? $p['items'] : array();

		$elements = array();

		// Heading.
		$elements[] = $this->wrap_section(
			array( $this->widget( 'heading', array(
				'title'       => $heading,
				'header_size' => 'h2',
				'align'       => 'center',
			) ) ),
			array( 'padding' => array( 'top' => '60', 'bottom' => '20', 'unit' => 'px' ) ),
			$use_containers
		);

		// Testimonial cards.
		$card_widgets = array();
		foreach ( $items as $item ) {
			$card_widgets[] = $this->widget( 'testimonial', array(
				'testimonial_content' => isset( $item['text'] ) ? $item['text'] : ( isset( $item['content'] ) ? $item['content'] : '' ),
				'testimonial_name'    => isset( $item['name'] ) ? $item['name'] : '',
				'testimonial_job'     => isset( $item['title'] ) ? $item['title'] : ( isset( $item['job'] ) ? $item['job'] : '' ),
				'testimonial_image'   => isset( $item['image'] ) ? array( 'url' => $item['image'] ) : array(),
			) );
		}

		if ( ! empty( $card_widgets ) ) {
			$columns = min( count( $card_widgets ), 3 );
			if ( $use_containers ) {
				$inner = array();
				foreach ( $card_widgets as $w ) {
					$inner[] = array(
						'id'       => $this->id(),
						'elType'   => 'container',
						'settings' => array( 'content_width' => 'full' ),
						'elements' => array( $w ),
					);
				}
				$elements[] = array(
					'id'       => $this->id(),
					'elType'   => 'container',
					'settings' => array(
						'flex_direction' => 'row',
						'flex_wrap'      => 'wrap',
						'flex_gap'       => array( 'size' => 20, 'unit' => 'px' ),
						'content_width'  => 'boxed',
						'padding'        => array( 'top' => '20', 'bottom' => '60', 'left' => '20', 'right' => '20', 'unit' => 'px' ),
					),
					'elements' => $inner,
				);
			} else {
				$col_size  = (int) floor( 100 / $columns );
				$structure = array( 2 => '20', 3 => '30' );
				$cols = array();
				foreach ( $card_widgets as $w ) {
					$cols[] = array(
						'id'       => $this->id(),
						'elType'   => 'column',
						'settings' => array( '_column_size' => $col_size ),
						'elements' => array( $w ),
					);
				}
				$elements[] = array(
					'id'       => $this->id(),
					'elType'   => 'section',
					'settings' => array(
						'structure' => isset( $structure[ $columns ] ) ? $structure[ $columns ] : '30',
						'padding'   => array( 'top' => '20', 'bottom' => '60', 'left' => '20', 'right' => '20', 'unit' => 'px' ),
					),
					'elements' => $cols,
				);
			}
		}

		return $elements;
	}

	/**
	 * Build a text section.
	 *
	 * @param array $p              Section params.
	 * @param bool  $use_containers Use container layout.
	 * @return array Elementor element.
	 */
	private function build_text( $p, $use_containers ) {
		$heading = isset( $p['heading'] ) ? $p['heading'] : '';
		$content = isset( $p['content'] ) ? $p['content'] : ( isset( $p['text'] ) ? $p['text'] : '' );

		$widgets = array();

		if ( $heading ) {
			$widgets[] = $this->widget( 'heading', array(
				'title'       => $heading,
				'header_size' => isset( $p['header_size'] ) ? $p['header_size'] : 'h2',
				'align'       => isset( $p['align'] ) ? $p['align'] : 'left',
			) );
		}

		if ( $content ) {
			$widgets[] = $this->widget( 'text-editor', array(
				'editor' => $content,
			) );
		}

		return $this->wrap_section( $widgets, array(
			'padding' => array( 'top' => '40', 'bottom' => '40', 'left' => '20', 'right' => '20', 'unit' => 'px' ),
		), $use_containers );
	}

	/**
	 * Build a gallery section.
	 *
	 * @param array $p              Section params.
	 * @param bool  $use_containers Use container layout.
	 * @return array Elementor element.
	 */
	private function build_gallery( $p, $use_containers ) {
		$heading = isset( $p['heading'] ) ? $p['heading'] : '';
		$images  = isset( $p['images'] ) && is_array( $p['images'] ) ? $p['images'] : array();
		$columns = isset( $p['columns'] ) ? min( max( (int) $p['columns'], 2 ), 6 ) : 3;

		$gallery_items = array();
		foreach ( $images as $image ) {
			if ( is_string( $image ) ) {
				$gallery_items[] = array( 'url' => $image );
			} elseif ( is_array( $image ) && isset( $image['url'] ) ) {
				$gallery_items[] = $image;
			}
		}

		$widgets = array();

		if ( $heading ) {
			$widgets[] = $this->widget( 'heading', array(
				'title'       => $heading,
				'header_size' => 'h2',
				'align'       => 'center',
			) );
		}

		$widgets[] = $this->widget( 'image-gallery', array(
			'wp_gallery'     => $gallery_items,
			'gallery_columns' => $columns,
		) );

		return $this->wrap_section( $widgets, array(
			'padding' => array( 'top' => '40', 'bottom' => '40', 'left' => '20', 'right' => '20', 'unit' => 'px' ),
		), $use_containers );
	}

	/**
	 * Build a contact form section.
	 *
	 * @param array $p              Section params: heading, form_id, form_plugin (wpforms|cf7|gravity).
	 * @param bool  $use_containers Use container layout.
	 * @return array Elementor element.
	 */
	private function build_contact_form( $p, $use_containers ) {
		$heading     = isset( $p['heading'] ) ? $p['heading'] : '';
		$form_id     = isset( $p['form_id'] ) ? (int) $p['form_id'] : 0;
		$form_plugin = isset( $p['form_plugin'] ) ? $p['form_plugin'] : 'wpforms';

		$widgets = array();

		if ( $heading ) {
			$widgets[] = $this->widget( 'heading', array(
				'title'       => $heading,
				'header_size' => 'h2',
				'align'       => 'center',
			) );
		}

		// Map plugin name to Elementor widget type.
		$widget_map = array(
			'wpforms'  => 'wpforms',
			'cf7'      => 'shortcode',
			'gravity'  => 'shortcode',
		);

		$widget_type = isset( $widget_map[ $form_plugin ] ) ? $widget_map[ $form_plugin ] : 'shortcode';

		if ( 'wpforms' === $form_plugin && $form_id ) {
			$widgets[] = $this->widget( $widget_type, array( 'form_id' => (string) $form_id ) );
		} elseif ( 'cf7' === $form_plugin && $form_id ) {
			$widgets[] = $this->widget( 'shortcode', array( 'shortcode' => '[contact-form-7 id="' . $form_id . '"]' ) );
		} elseif ( 'gravity' === $form_plugin && $form_id ) {
			$widgets[] = $this->widget( 'shortcode', array( 'shortcode' => '[gravityform id="' . $form_id . '" ajax="true"]' ) );
		} else {
			$widgets[] = $this->widget( 'text-editor', array(
				'editor' => '<p style="text-align:center;color:#999;">Form placeholder — set form_id and form_plugin to embed a real form.</p>',
			) );
		}

		return $this->wrap_section( $widgets, array(
			'padding' => array( 'top' => '60', 'bottom' => '60', 'left' => '20', 'right' => '20', 'unit' => 'px' ),
		), $use_containers );
	}

	/**
	 * Build a Google Maps section.
	 *
	 * @param array $p              Section params: heading, address, zoom, height.
	 * @param bool  $use_containers Use container layout.
	 * @return array Elementor element.
	 */
	private function build_map( $p, $use_containers ) {
		$heading = isset( $p['heading'] ) ? $p['heading'] : '';
		$address = isset( $p['address'] ) ? $p['address'] : 'New York, NY';
		$zoom    = isset( $p['zoom'] ) ? min( max( (int) $p['zoom'], 1 ), 20 ) : 14;
		$height  = isset( $p['height'] ) ? min( max( (int) $p['height'], 100 ), 800 ) : 400;

		$widgets = array();

		if ( $heading ) {
			$widgets[] = $this->widget( 'heading', array(
				'title'       => $heading,
				'header_size' => 'h2',
				'align'       => 'center',
			) );
		}

		$widgets[] = $this->widget( 'google_maps', array(
			'address' => $address,
			'zoom'    => array( 'size' => $zoom, 'unit' => 'px' ),
			'height'  => array( 'size' => $height, 'unit' => 'px' ),
		) );

		return $this->wrap_section( $widgets, array(
			'padding' => array( 'top' => '40', 'bottom' => '40', 'left' => '0', 'right' => '0', 'unit' => 'px' ),
		), $use_containers );
	}

	/**
	 * Build a countdown section.
	 *
	 * @param array $p              Section params: heading, subheading, due_date (Y-m-d H:i).
	 * @param bool  $use_containers Use container layout.
	 * @return array Elementor element.
	 */
	private function build_countdown( $p, $use_containers ) {
		$heading    = isset( $p['heading'] ) ? $p['heading'] : '';
		$subheading = isset( $p['subheading'] ) ? $p['subheading'] : '';
		$due_date   = isset( $p['due_date'] ) ? $p['due_date'] : gmdate( 'Y-m-d H:i', strtotime( '+30 days' ) );

		$widgets = array();

		if ( $heading ) {
			$widgets[] = $this->widget( 'heading', array(
				'title'       => $heading,
				'header_size' => 'h2',
				'align'       => 'center',
			) );
		}

		if ( $subheading ) {
			$widgets[] = $this->widget( 'text-editor', array(
				'editor' => '<p style="text-align:center;">' . esc_html( $subheading ) . '</p>',
			) );
		}

		$widgets[] = $this->widget( 'countdown', array(
			'countdown_type' => 'due_date',
			'due_date'       => $due_date,
		) );

		return $this->wrap_section( $widgets, array(
			'padding'              => array( 'top' => '60', 'bottom' => '60', 'left' => '20', 'right' => '20', 'unit' => 'px' ),
			'background_background' => 'classic',
			'background_color'     => '#1a1a2e',
			'_element_custom_width' => array( 'size' => '', 'unit' => '%' ),
		), $use_containers );
	}

	/**
	 * Build a stats / numbers section.
	 *
	 * @param array $p              Section params: heading, items[{number, suffix, title, duration}].
	 * @param bool  $use_containers Use container layout.
	 * @return array Elementor element.
	 */
	private function build_stats( $p, $use_containers ) {
		$heading = isset( $p['heading'] ) ? $p['heading'] : '';
		$items   = isset( $p['items'] ) && is_array( $p['items'] ) ? $p['items'] : array();
		$columns = count( $items ) > 4 ? 4 : max( count( $items ), 2 );

		$all_widgets = array();

		if ( $heading ) {
			$all_widgets[] = $this->wrap_section(
				array( $this->widget( 'heading', array(
					'title'       => $heading,
					'header_size' => 'h2',
					'align'       => 'center',
				) ) ),
				array( 'padding' => array( 'top' => '60', 'bottom' => '20', 'unit' => 'px' ) ),
				$use_containers
			);
		}

		if ( $use_containers ) {
			$inner_containers = array();
			foreach ( $items as $item ) {
				$number_val = isset( $item['number'] ) ? $item['number'] : '';
				$label_val  = isset( $item['label'] ) ? $item['label'] : ( isset( $item['title'] ) ? $item['title'] : '' );
				$is_numeric = is_numeric( $number_val );

				if ( $is_numeric ) {
					// Use counter widget for numeric values (animated counting).
					$stat_widget = $this->widget( 'counter', array(
						'starting_number' => 0,
						'ending_number'   => (int) $number_val,
						'suffix'          => isset( $item['suffix'] ) ? $item['suffix'] : '',
						'title'           => $label_val,
						'duration'        => isset( $item['duration'] ) ? (int) $item['duration'] : 2000,
					) );
				} else {
					// Use heading for text values ("Free", "✓", etc.) — counter only accepts numbers.
					$stat_widget = $this->widget( 'heading', array(
						'title'       => (string) $number_val,
						'header_size' => 'h2',
						'align'       => 'center',
						'title_color' => '#111111',
					) );
				}

				$card_elements = array( $stat_widget );
				// Add label below for text stats (counter has its own title field).
				if ( ! $is_numeric && $label_val ) {
					$card_elements[] = $this->widget( 'heading', array(
						'title'       => $label_val,
						'header_size' => 'h5',
						'align'       => 'center',
						'title_color' => '#666666',
					) );
				}

				$inner_containers[] = array(
					'id'       => $this->id(),
					'elType'   => 'container',
					'settings' => array( 'content_width' => 'full' ),
					'elements' => $card_elements,
				);
			}

			$all_widgets[] = array(
				'id'       => $this->id(),
				'elType'   => 'container',
				'settings' => array(
					'flex_direction' => 'row',
					'flex_wrap'      => 'wrap',
					'flex_gap'       => array( 'size' => 20, 'unit' => 'px' ),
					'content_width'  => 'boxed',
					'padding'        => array( 'top' => '40', 'bottom' => '60', 'left' => '20', 'right' => '20', 'unit' => 'px' ),
				),
				'elements' => $inner_containers,
			);
		} else {
			$col_size  = (int) floor( 100 / $columns );
			$structure = array( 2 => '20', 3 => '30', 4 => '40' );

			$column_elements = array();
			foreach ( $items as $item ) {
				$column_elements[] = array(
					'id'       => $this->id(),
					'elType'   => 'column',
					'settings' => array( '_column_size' => $col_size ),
					'elements' => array( $this->widget( 'counter', array(
						'starting_number' => 0,
						'ending_number'   => isset( $item['number'] ) ? (int) $item['number'] : 0,
						'suffix'          => isset( $item['suffix'] ) ? $item['suffix'] : '',
						'title'           => isset( $item['title'] ) ? $item['title'] : '',
						'duration'        => isset( $item['duration'] ) ? (int) $item['duration'] : 2000,
					) ) ),
				);
			}

			$all_widgets[] = array(
				'id'       => $this->id(),
				'elType'   => 'section',
				'settings' => array(
					'structure' => isset( $structure[ $columns ] ) ? $structure[ $columns ] : '30',
					'padding'   => array( 'top' => '40', 'bottom' => '60', 'left' => '20', 'right' => '20', 'unit' => 'px' ),
				),
				'elements' => $column_elements,
			);
		}

		return $all_widgets;
	}

	/**
	 * Build a logo grid section.
	 *
	 * @param array $p              Section params: heading, logos[{url, alt, link}], columns.
	 * @param bool  $use_containers Use container layout.
	 * @return array Elementor element(s).
	 */
	private function build_logo_grid( $p, $use_containers ) {
		$heading = isset( $p['heading'] ) ? $p['heading'] : '';
		$logos   = isset( $p['logos'] ) && is_array( $p['logos'] ) ? $p['logos'] : array();
		$columns = isset( $p['columns'] ) ? min( max( (int) $p['columns'], 2 ), 6 ) : 4;

		$all_widgets = array();

		if ( $heading ) {
			$all_widgets[] = $this->wrap_section(
				array( $this->widget( 'heading', array(
					'title'       => $heading,
					'header_size' => 'h2',
					'align'       => 'center',
				) ) ),
				array( 'padding' => array( 'top' => '60', 'bottom' => '20', 'unit' => 'px' ) ),
				$use_containers
			);
		}

		if ( $use_containers ) {
			$inner_containers = array();
			foreach ( $logos as $logo ) {
				$img_url = is_string( $logo ) ? $logo : ( isset( $logo['url'] ) ? $logo['url'] : '' );
				$alt     = is_array( $logo ) && isset( $logo['alt'] ) ? $logo['alt'] : '';
				$link    = is_array( $logo ) && isset( $logo['link'] ) ? $logo['link'] : '';

				$settings = array(
					'image'      => array( 'url' => $img_url ),
					'image_size' => 'medium',
					'align'      => 'center',
					'caption_source' => 'none',
				);
				if ( $alt ) {
					$settings['image']['alt'] = $alt;
				}
				if ( $link ) {
					$settings['link_to'] = 'custom';
					$settings['link']    = array( 'url' => $link, 'is_external' => true );
				}

				$inner_containers[] = array(
					'id'       => $this->id(),
					'elType'   => 'container',
					'settings' => array( 'content_width' => 'full' ),
					'elements' => array( $this->widget( 'image', $settings ) ),
				);
			}

			$all_widgets[] = array(
				'id'       => $this->id(),
				'elType'   => 'container',
				'settings' => array(
					'flex_direction' => 'row',
					'flex_wrap'      => 'wrap',
					'flex_gap'       => array( 'size' => 30, 'unit' => 'px' ),
					'content_width'  => 'boxed',
					'padding'        => array( 'top' => '40', 'bottom' => '60', 'left' => '20', 'right' => '20', 'unit' => 'px' ),
				),
				'elements' => $inner_containers,
			);
		} else {
			$col_size  = (int) floor( 100 / $columns );
			$structure = array( 2 => '20', 3 => '30', 4 => '40' );

			$column_elements = array();
			foreach ( $logos as $logo ) {
				$img_url = is_string( $logo ) ? $logo : ( isset( $logo['url'] ) ? $logo['url'] : '' );
				$alt     = is_array( $logo ) && isset( $logo['alt'] ) ? $logo['alt'] : '';
				$link    = is_array( $logo ) && isset( $logo['link'] ) ? $logo['link'] : '';

				$settings = array(
					'image'      => array( 'url' => $img_url ),
					'image_size' => 'medium',
					'align'      => 'center',
					'caption_source' => 'none',
				);
				if ( $alt ) {
					$settings['image']['alt'] = $alt;
				}
				if ( $link ) {
					$settings['link_to'] = 'custom';
					$settings['link']    = array( 'url' => $link, 'is_external' => true );
				}

				$column_elements[] = array(
					'id'       => $this->id(),
					'elType'   => 'column',
					'settings' => array( '_column_size' => $col_size ),
					'elements' => array( $this->widget( 'image', $settings ) ),
				);
			}

			$all_widgets[] = array(
				'id'       => $this->id(),
				'elType'   => 'section',
				'settings' => array(
					'structure' => isset( $structure[ $columns ] ) ? $structure[ $columns ] : '40',
					'padding'   => array( 'top' => '40', 'bottom' => '60', 'left' => '20', 'right' => '20', 'unit' => 'px' ),
				),
				'elements' => $column_elements,
			);
		}

		return $all_widgets;
	}

	/**
	 * Build a video section.
	 *
	 * @param array $p              Section params: heading, subheading, video_url.
	 * @param bool  $use_containers Use container layout.
	 * @return array Elementor element.
	 */
	private function build_video( $p, $use_containers ) {
		$heading    = isset( $p['heading'] ) ? $p['heading'] : '';
		$subheading = isset( $p['subheading'] ) ? $p['subheading'] : '';
		$video_url  = isset( $p['video_url'] ) ? $p['video_url'] : '';

		$widgets = array();

		if ( $heading ) {
			$widgets[] = $this->widget( 'heading', array(
				'title'       => $heading,
				'header_size' => 'h2',
				'align'       => 'center',
			) );
		}

		if ( $subheading ) {
			$widgets[] = $this->widget( 'text-editor', array(
				'editor' => '<p style="text-align:center;">' . esc_html( $subheading ) . '</p>',
			) );
		}

		// Detect video type from URL.
		$video_type = 'youtube';
		$settings   = array();

		if ( false !== strpos( $video_url, 'vimeo.com' ) ) {
			$video_type = 'vimeo';
			$settings['vimeo_url'] = $video_url;
		} elseif ( false !== strpos( $video_url, 'youtube.com' ) || false !== strpos( $video_url, 'youtu.be' ) ) {
			$video_type = 'youtube';
			$settings['youtube_url'] = $video_url;
		} else {
			$video_type = 'hosted';
			$settings['hosted_url'] = array( 'url' => $video_url );
		}

		$settings['video_type'] = $video_type;

		$widgets[] = $this->widget( 'video', $settings );

		return $this->wrap_section( $widgets, array(
			'padding' => array( 'top' => '60', 'bottom' => '60', 'left' => '20', 'right' => '20', 'unit' => 'px' ),
		), $use_containers );
	}

	/**
	 * Build a team members section.
	 *
	 * @param array $p              Section params: heading, columns, items[{name, title, image, bio, social_links}].
	 * @param bool  $use_containers Use container layout.
	 * @return array Elementor element(s).
	 */
	private function build_team( $p, $use_containers ) {
		$heading = isset( $p['heading'] ) ? $p['heading'] : 'Meet the Team';
		$columns = isset( $p['columns'] ) ? min( max( (int) $p['columns'], 2 ), 4 ) : 3;
		$items   = isset( $p['items'] ) && is_array( $p['items'] ) ? $p['items'] : array();

		$all_widgets = array();

		if ( $heading ) {
			$all_widgets[] = $this->wrap_section(
				array( $this->widget( 'heading', array(
					'title'       => $heading,
					'header_size' => 'h2',
					'align'       => 'center',
				) ) ),
				array( 'padding' => array( 'top' => '60', 'bottom' => '20', 'unit' => 'px' ) ),
				$use_containers
			);
		}

		$card_widths = array( 2 => 47, 3 => 30, 4 => 22 );
		$card_width  = isset( $card_widths[ $columns ] ) ? $card_widths[ $columns ] : 30;

		if ( $use_containers ) {
			$inner_containers = array();
			foreach ( $items as $item ) {
				$image_url = isset( $item['image'] ) ? $item['image'] : '';
				$name      = isset( $item['name'] ) ? $item['name'] : '';
				$title     = isset( $item['title'] ) ? $item['title'] : '';
				$bio       = isset( $item['bio'] ) ? $item['bio'] : '';

				$card_elements = array(
					$this->widget( 'image-box', array(
						'image'       => array( 'url' => $image_url ),
						'title_text'  => $name,
						'description_text' => ( $title ? '<strong>' . esc_html( $title ) . '</strong>' : '' ) . ( $bio ? ( $title ? '<br>' : '' ) . esc_html( $bio ) : '' ),
						'position'    => 'top',
					) ),
				);

				// Social links as icon list if provided.
				$social_links = isset( $item['social_links'] ) && is_array( $item['social_links'] ) ? $item['social_links'] : array();
				if ( ! empty( $social_links ) ) {
					$icon_items = array();
					$icon_map   = array(
						'facebook'  => 'fab fa-facebook',
						'twitter'   => 'fab fa-twitter',
						'linkedin'  => 'fab fa-linkedin',
						'instagram' => 'fab fa-instagram',
						'github'    => 'fab fa-github',
					);
					foreach ( $social_links as $network => $url ) {
						$icon = isset( $icon_map[ $network ] ) ? $icon_map[ $network ] : 'fas fa-link';
						$icon_items[] = array(
							'icon'    => array( 'value' => $icon, 'library' => 'fa-brands' ),
							'link'    => array( 'url' => $url, 'is_external' => true ),
							'text'    => '',
						);
					}
					$card_elements[] = $this->widget( 'icon-list', array(
						'icon_list' => $icon_items,
						'layout'    => 'inline',
						'align'     => 'center',
					) );
				}

				$inner_containers[] = array(
					'id'       => $this->id(),
					'elType'   => 'container',
					'settings' => array(
						'content_width'              => 'full',
						'_element_width'             => 'initial',
						'width'                      => array( 'size' => $card_width, 'unit' => '%' ),
						'background_background'      => 'classic',
						'background_color'           => '#FFFFFF',
						'border_radius'              => array( 'top_left' => '12', 'top_right' => '12', 'bottom_right' => '12', 'bottom_left' => '12', 'unit' => 'px', 'isLinked' => true ),
						'box_shadow_box_shadow_type' => 'yes',
						'box_shadow_box_shadow'      => array( 'horizontal' => 0, 'vertical' => 4, 'blur' => 20, 'spread' => 0, 'color' => 'rgba(0,0,0,0.08)' ),
						'padding'                    => array( 'top' => '30', 'bottom' => '30', 'left' => '30', 'right' => '30', 'unit' => 'px', 'isLinked' => true ),
						'custom_css'                 => 'selector { transition: box-shadow 0.3s ease, transform 0.3s ease; } selector:hover { box-shadow: 0 8px 30px rgba(0,0,0,0.15); transform: translateY(-4px); }',
					),
					'elements' => $card_elements,
				);
			}

			$all_widgets[] = array(
				'id'       => $this->id(),
				'elType'   => 'container',
				'settings' => array(
					'flex_direction' => 'row',
					'flex_wrap'      => 'wrap',
					'flex_gap'       => array( 'size' => 20, 'unit' => 'px' ),
					'content_width'  => 'boxed',
					'padding'        => array( 'top' => '40', 'bottom' => '60', 'left' => '20', 'right' => '20', 'unit' => 'px' ),
				),
				'elements' => $inner_containers,
			);
		} else {
			$col_size  = (int) floor( 100 / $columns );
			$structure = array( 2 => '20', 3 => '30', 4 => '40' );

			$column_elements = array();
			foreach ( $items as $item ) {
				$image_url = isset( $item['image'] ) ? $item['image'] : '';
				$name      = isset( $item['name'] ) ? $item['name'] : '';
				$title     = isset( $item['title'] ) ? $item['title'] : '';
				$bio       = isset( $item['bio'] ) ? $item['bio'] : '';

				$column_elements[] = array(
					'id'       => $this->id(),
					'elType'   => 'column',
					'settings' => array( '_column_size' => $col_size ),
					'elements' => array( $this->widget( 'image-box', array(
						'image'            => array( 'url' => $image_url ),
						'title_text'       => $name,
						'description_text' => ( $title ? esc_html( $title ) : '' ) . ( $bio ? ' — ' . esc_html( $bio ) : '' ),
						'position'         => 'top',
					) ) ),
				);
			}

			$all_widgets[] = array(
				'id'       => $this->id(),
				'elType'   => 'section',
				'settings' => array(
					'structure' => isset( $structure[ $columns ] ) ? $structure[ $columns ] : '30',
					'padding'   => array( 'top' => '40', 'bottom' => '60', 'left' => '20', 'right' => '20', 'unit' => 'px' ),
				),
				'elements' => $column_elements,
			);
		}

		return $all_widgets;
	}

	/**
	 * Build a portfolio showcase grid.
	 *
	 * @param array $p              Section params: heading, columns, items[{title, image, category, url}].
	 * @param bool  $use_containers Use container layout.
	 * @return array Elementor element(s).
	 */
	private function build_portfolio( $p, $use_containers ) {
		$heading = isset( $p['heading'] ) ? $p['heading'] : 'Our Work';
		$columns = isset( $p['columns'] ) ? min( max( (int) $p['columns'], 2 ), 4 ) : 3;
		$items   = isset( $p['items'] ) && is_array( $p['items'] ) ? $p['items'] : array();

		$all_widgets = array();

		if ( $heading ) {
			$all_widgets[] = $this->wrap_section(
				array( $this->widget( 'heading', array(
					'title'       => $heading,
					'header_size' => 'h2',
					'align'       => 'center',
				) ) ),
				array( 'padding' => array( 'top' => '60', 'bottom' => '20', 'unit' => 'px' ) ),
				$use_containers
			);
		}

		$card_widths = array( 2 => 47, 3 => 30, 4 => 22 );
		$card_width  = isset( $card_widths[ $columns ] ) ? $card_widths[ $columns ] : 30;

		if ( $use_containers ) {
			$inner_containers = array();
			foreach ( $items as $item ) {
				$image_url = isset( $item['image'] ) ? $item['image'] : '';
				$title     = isset( $item['title'] ) ? $item['title'] : '';
				$category  = isset( $item['category'] ) ? $item['category'] : '';
				$url       = isset( $item['url'] ) ? $item['url'] : '';

				$desc = $category ? '<span style="font-size:12px;text-transform:uppercase;letter-spacing:1px;color:#888;">' . esc_html( $category ) . '</span>' : '';

				$img_settings = array(
					'image'            => array( 'url' => $image_url ),
					'title_text'       => $title,
					'description_text' => $desc,
					'position'         => 'bottom',
				);
				if ( $url ) {
					$img_settings['link']    = array( 'url' => $url, 'is_external' => false );
					$img_settings['open_lightbox'] = 'no';
				}

				$inner_containers[] = array(
					'id'       => $this->id(),
					'elType'   => 'container',
					'settings' => array(
						'content_width'              => 'full',
						'_element_width'             => 'initial',
						'width'                      => array( 'size' => $card_width, 'unit' => '%' ),
						'background_background'      => 'classic',
						'background_color'           => '#FFFFFF',
						'border_radius'              => array( 'top_left' => '12', 'top_right' => '12', 'bottom_right' => '12', 'bottom_left' => '12', 'unit' => 'px', 'isLinked' => true ),
						'box_shadow_box_shadow_type' => 'yes',
						'box_shadow_box_shadow'      => array( 'horizontal' => 0, 'vertical' => 4, 'blur' => 20, 'spread' => 0, 'color' => 'rgba(0,0,0,0.08)' ),
						'padding'                    => array( 'top' => '0', 'bottom' => '20', 'left' => '0', 'right' => '0', 'unit' => 'px', 'isLinked' => false ),
						'custom_css'                 => 'selector { transition: box-shadow 0.3s ease, transform 0.3s ease; overflow: hidden; } selector:hover { box-shadow: 0 8px 30px rgba(0,0,0,0.15); transform: translateY(-4px); }',
					),
					'elements' => array( $this->widget( 'image-box', $img_settings ) ),
				);
			}

			$all_widgets[] = array(
				'id'       => $this->id(),
				'elType'   => 'container',
				'settings' => array(
					'flex_direction' => 'row',
					'flex_wrap'      => 'wrap',
					'flex_gap'       => array( 'size' => 20, 'unit' => 'px' ),
					'content_width'  => 'boxed',
					'padding'        => array( 'top' => '40', 'bottom' => '60', 'left' => '20', 'right' => '20', 'unit' => 'px' ),
				),
				'elements' => $inner_containers,
			);
		} else {
			$col_size  = (int) floor( 100 / $columns );
			$structure = array( 2 => '20', 3 => '30', 4 => '40' );

			$column_elements = array();
			foreach ( $items as $item ) {
				$image_url = isset( $item['image'] ) ? $item['image'] : '';
				$title     = isset( $item['title'] ) ? $item['title'] : '';
				$category  = isset( $item['category'] ) ? $item['category'] : '';
				$url       = isset( $item['url'] ) ? $item['url'] : '';

				$img_settings = array(
					'image'            => array( 'url' => $image_url ),
					'title_text'       => $title,
					'description_text' => $category,
					'position'         => 'bottom',
				);
				if ( $url ) {
					$img_settings['link'] = array( 'url' => $url, 'is_external' => false );
				}

				$column_elements[] = array(
					'id'       => $this->id(),
					'elType'   => 'column',
					'settings' => array( '_column_size' => $col_size ),
					'elements' => array( $this->widget( 'image-box', $img_settings ) ),
				);
			}

			$all_widgets[] = array(
				'id'       => $this->id(),
				'elType'   => 'section',
				'settings' => array(
					'structure' => isset( $structure[ $columns ] ) ? $structure[ $columns ] : '30',
					'padding'   => array( 'top' => '40', 'bottom' => '60', 'left' => '20', 'right' => '20', 'unit' => 'px' ),
				),
				'elements' => $column_elements,
			);
		}

		return $all_widgets;
	}

	/**
	 * Build a blog post cards grid.
	 *
	 * @param array $p              Section params: heading, columns, count.
	 * @param bool  $use_containers Use container layout.
	 * @return array Elementor element(s).
	 */
	private function build_blog_grid( $p, $use_containers ) {
		$heading = isset( $p['heading'] ) ? $p['heading'] : 'Latest Posts';
		$columns = isset( $p['columns'] ) ? min( max( (int) $p['columns'], 2 ), 3 ) : 3;
		$count   = isset( $p['count'] ) ? max( (int) $p['count'], 1 ) : 3;

		$all_widgets = array();

		if ( $heading ) {
			$all_widgets[] = $this->wrap_section(
				array( $this->widget( 'heading', array(
					'title'       => $heading,
					'header_size' => 'h2',
					'align'       => 'center',
				) ) ),
				array( 'padding' => array( 'top' => '60', 'bottom' => '20', 'unit' => 'px' ) ),
				$use_containers
			);
		}

		$posts_widget = $this->widget( 'posts', array(
			'skin'         => 'classic',
			'posts_per_page' => $count,
			'columns'      => $columns,
			'show_image'   => 'yes',
			'show_title'   => 'yes',
			'show_excerpt' => 'yes',
			'show_read_more' => 'yes',
			'read_more_text' => 'Read More',
		) );

		$all_widgets[] = $this->wrap_section(
			array( $posts_widget ),
			array( 'padding' => array( 'top' => '20', 'bottom' => '60', 'left' => '20', 'right' => '20', 'unit' => 'px' ) ),
			$use_containers
		);

		return $all_widgets;
	}

	/**
	 * Build a services section.
	 *
	 * @param array $p              Section params: heading, columns, items[{icon, title, desc, price, button_text, url}].
	 * @param bool  $use_containers Use container layout.
	 * @return array Elementor element(s).
	 */
	private function build_services( $p, $use_containers ) {
		$heading = isset( $p['heading'] ) ? $p['heading'] : 'Our Services';
		$columns = isset( $p['columns'] ) ? min( max( (int) $p['columns'], 2 ), 3 ) : 3;
		$items   = isset( $p['items'] ) && is_array( $p['items'] ) ? $p['items'] : array();

		$all_widgets = array();

		if ( $heading ) {
			$all_widgets[] = $this->wrap_section(
				array( $this->widget( 'heading', array(
					'title'       => $heading,
					'header_size' => 'h2',
					'align'       => 'center',
				) ) ),
				array( 'padding' => array( 'top' => '60', 'bottom' => '20', 'unit' => 'px' ) ),
				$use_containers
			);
		}

		$card_widths = array( 2 => 47, 3 => 30 );
		$card_width  = isset( $card_widths[ $columns ] ) ? $card_widths[ $columns ] : 30;

		if ( $use_containers ) {
			$inner_containers = array();
			foreach ( $items as $item ) {
				$icon        = isset( $item['icon'] ) ? $item['icon'] : 'fas fa-concierge-bell';
				$title       = isset( $item['title'] ) ? $item['title'] : '';
				$desc        = isset( $item['desc'] ) ? $item['desc'] : ( isset( $item['description'] ) ? $item['description'] : '' );
				$price       = isset( $item['price'] ) ? $item['price'] : '';
				$button_text = isset( $item['button_text'] ) ? $item['button_text'] : '';
				$url         = isset( $item['url'] ) ? $item['url'] : '#';

				$full_desc = $desc;
				if ( $price ) {
					$full_desc .= '<br><br><strong style="font-size:1.2em;color:#0073aa;">' . esc_html( $price ) . '</strong>';
				}

				$card_elements = array(
					$this->widget( 'icon-box', array(
						'selected_icon'    => array( 'value' => $icon, 'library' => 'fa-solid' ),
						'title_text'       => $title,
						'description_text' => $full_desc,
						'position'         => 'top',
						'align'            => 'left',
					) ),
				);

				if ( $button_text ) {
					$card_elements[] = $this->widget( 'button', array(
						'text'            => $button_text,
						'link'            => array( 'url' => $url, 'is_external' => false ),
						'align'           => 'left',
						'size'            => 'sm',
						'background_color' => '#0073aa',
						'button_text_color' => '#FFFFFF',
						'border_radius'   => array( 'top_left' => '6', 'top_right' => '6', 'bottom_right' => '6', 'bottom_left' => '6', 'unit' => 'px', 'isLinked' => true ),
						'hover_animation' => 'float',
					) );
				}

				$inner_containers[] = array(
					'id'       => $this->id(),
					'elType'   => 'container',
					'settings' => array(
						'content_width'              => 'full',
						'_element_width'             => 'initial',
						'width'                      => array( 'size' => $card_width, 'unit' => '%' ),
						'background_background'      => 'classic',
						'background_color'           => '#FFFFFF',
						'border_radius'              => array( 'top_left' => '12', 'top_right' => '12', 'bottom_right' => '12', 'bottom_left' => '12', 'unit' => 'px', 'isLinked' => true ),
						'box_shadow_box_shadow_type' => 'yes',
						'box_shadow_box_shadow'      => array( 'horizontal' => 0, 'vertical' => 4, 'blur' => 20, 'spread' => 0, 'color' => 'rgba(0,0,0,0.08)' ),
						'padding'                    => array( 'top' => '30', 'bottom' => '30', 'left' => '30', 'right' => '30', 'unit' => 'px', 'isLinked' => true ),
						'custom_css'                 => 'selector { transition: box-shadow 0.3s ease, transform 0.3s ease; } selector:hover { box-shadow: 0 8px 30px rgba(0,0,0,0.15); transform: translateY(-4px); }',
					),
					'elements' => $card_elements,
				);
			}

			$all_widgets[] = array(
				'id'       => $this->id(),
				'elType'   => 'container',
				'settings' => array(
					'flex_direction' => 'row',
					'flex_wrap'      => 'wrap',
					'flex_gap'       => array( 'size' => 20, 'unit' => 'px' ),
					'content_width'  => 'boxed',
					'padding'        => array( 'top' => '40', 'bottom' => '60', 'left' => '20', 'right' => '20', 'unit' => 'px' ),
				),
				'elements' => $inner_containers,
			);
		} else {
			$col_size  = (int) floor( 100 / $columns );
			$structure = array( 2 => '20', 3 => '30' );

			$column_elements = array();
			foreach ( $items as $item ) {
				$icon  = isset( $item['icon'] ) ? $item['icon'] : 'fas fa-concierge-bell';
				$title = isset( $item['title'] ) ? $item['title'] : '';
				$desc  = isset( $item['desc'] ) ? $item['desc'] : ( isset( $item['description'] ) ? $item['description'] : '' );
				$price = isset( $item['price'] ) ? $item['price'] : '';

				$full_desc = $desc . ( $price ? "\n\n" . $price : '' );

				$column_elements[] = array(
					'id'       => $this->id(),
					'elType'   => 'column',
					'settings' => array( '_column_size' => $col_size ),
					'elements' => array( $this->widget( 'icon-box', array(
						'selected_icon'    => array( 'value' => $icon, 'library' => 'fa-solid' ),
						'title_text'       => $title,
						'description_text' => $full_desc,
						'position'         => 'top',
					) ) ),
				);
			}

			$all_widgets[] = array(
				'id'       => $this->id(),
				'elType'   => 'section',
				'settings' => array(
					'structure' => isset( $structure[ $columns ] ) ? $structure[ $columns ] : '30',
					'padding'   => array( 'top' => '40', 'bottom' => '60', 'left' => '20', 'right' => '20', 'unit' => 'px' ),
				),
				'elements' => $column_elements,
			);
		}

		return $all_widgets;
	}

	/**
	 * Build an about section with image + text side by side.
	 *
	 * @param array $p              Section params: heading, text, image_url, image_position, button_text, button_url.
	 * @param bool  $use_containers Use container layout.
	 * @return array Elementor element.
	 */
	private function build_about( $p, $use_containers ) {
		$heading        = isset( $p['heading'] ) ? $p['heading'] : 'About Us';
		$text           = isset( $p['text'] ) ? $p['text'] : '';
		$image_url      = isset( $p['image_url'] ) ? $p['image_url'] : '';
		$image_position = isset( $p['image_position'] ) && 'right' === $p['image_position'] ? 'right' : 'left';
		$button_text    = isset( $p['button_text'] ) ? $p['button_text'] : '';
		$button_url     = isset( $p['button_url'] ) ? $p['button_url'] : '#';

		$text_widgets = array();
		if ( $heading ) {
			$text_widgets[] = $this->widget( 'heading', array(
				'title'       => $heading,
				'header_size' => 'h2',
				'align'       => 'left',
			) );
		}
		if ( $text ) {
			$text_widgets[] = $this->widget( 'text-editor', array(
				'editor' => '<p>' . wp_kses_post( $text ) . '</p>',
			) );
		}
		if ( $button_text ) {
			$text_widgets[] = $this->widget( 'button', array(
				'text'  => $button_text,
				'link'  => array( 'url' => $button_url, 'is_external' => false ),
				'align' => 'left',
				'size'  => 'md',
			) );
		}

		$image_widget = $this->widget( 'image', array(
			'image'      => array( 'url' => $image_url ),
			'image_size' => 'large',
			'align'      => 'center',
		) );

		if ( $use_containers ) {
			$image_col = array(
				'id'       => $this->id(),
				'elType'   => 'container',
				'settings' => array(
					'content_width' => 'full',
					'width'         => array( 'size' => 47, 'unit' => '%' ),
					'padding'       => array( 'top' => '20', 'bottom' => '20', 'left' => '20', 'right' => '20', 'unit' => 'px', 'isLinked' => true ),
				),
				'elements' => array( $image_widget ),
			);

			$text_col = array(
				'id'       => $this->id(),
				'elType'   => 'container',
				'settings' => array(
					'content_width' => 'full',
					'width'         => array( 'size' => 47, 'unit' => '%' ),
					'padding'       => array( 'top' => '20', 'bottom' => '20', 'left' => '20', 'right' => '20', 'unit' => 'px', 'isLinked' => true ),
				),
				'elements' => $text_widgets,
			);

			$children = 'left' === $image_position ? array( $image_col, $text_col ) : array( $text_col, $image_col );

			return array(
				'id'       => $this->id(),
				'elType'   => 'container',
				'settings' => array(
					'flex_direction' => 'row',
					'flex_wrap'      => 'wrap',
					'flex_gap'       => array( 'size' => 40, 'unit' => 'px' ),
					'content_width'  => 'boxed',
					'padding'        => array( 'top' => '60', 'bottom' => '60', 'left' => '20', 'right' => '20', 'unit' => 'px' ),
				),
				'elements' => $children,
			);
		}

		// Classic: two-column section.
		$image_col_el = array(
			'id'       => $this->id(),
			'elType'   => 'column',
			'settings' => array( '_column_size' => 50 ),
			'elements' => array( $image_widget ),
		);
		$text_col_el = array(
			'id'       => $this->id(),
			'elType'   => 'column',
			'settings' => array( '_column_size' => 50 ),
			'elements' => $text_widgets,
		);

		$cols = 'left' === $image_position ? array( $image_col_el, $text_col_el ) : array( $text_col_el, $image_col_el );

		return array(
			'id'       => $this->id(),
			'elType'   => 'section',
			'settings' => array(
				'structure' => '20',
				'padding'   => array( 'top' => '60', 'bottom' => '60', 'left' => '20', 'right' => '20', 'unit' => 'px' ),
			),
			'elements' => $cols,
		);
	}

	/**
	 * Build a numbered process steps section.
	 *
	 * @param array $p              Section params: heading, items[{number, title, desc, icon}].
	 * @param bool  $use_containers Use container layout.
	 * @return array Elementor element(s).
	 */
	private function build_process_steps( $p, $use_containers ) {
		$heading = isset( $p['heading'] ) ? $p['heading'] : 'How It Works';
		$items   = isset( $p['items'] ) && is_array( $p['items'] ) ? $p['items'] : array();
		$columns = count( $items ) > 4 ? 4 : max( count( $items ), 2 );

		$all_widgets = array();

		if ( $heading ) {
			$all_widgets[] = $this->wrap_section(
				array( $this->widget( 'heading', array(
					'title'       => $heading,
					'header_size' => 'h2',
					'align'       => 'center',
				) ) ),
				array( 'padding' => array( 'top' => '60', 'bottom' => '20', 'unit' => 'px' ) ),
				$use_containers
			);
		}

		$card_widths = array( 2 => 47, 3 => 30, 4 => 22 );
		$card_width  = isset( $card_widths[ $columns ] ) ? $card_widths[ $columns ] : 30;

		if ( $use_containers ) {
			$inner_containers = array();
			foreach ( $items as $idx => $item ) {
				$number = isset( $item['number'] ) ? $item['number'] : ( $idx + 1 );
				$title  = isset( $item['title'] ) ? $item['title'] : '';
				$desc   = isset( $item['desc'] ) ? $item['desc'] : ( isset( $item['description'] ) ? $item['description'] : '' );
				$icon   = isset( $item['icon'] ) ? $item['icon'] : 'fas fa-circle';

				$inner_containers[] = array(
					'id'       => $this->id(),
					'elType'   => 'container',
					'settings' => array(
						'content_width'              => 'full',
						'_element_width'             => 'initial',
						'width'                      => array( 'size' => $card_width, 'unit' => '%' ),
						'background_background'      => 'classic',
						'background_color'           => '#FFFFFF',
						'border_radius'              => array( 'top_left' => '12', 'top_right' => '12', 'bottom_right' => '12', 'bottom_left' => '12', 'unit' => 'px', 'isLinked' => true ),
						'box_shadow_box_shadow_type' => 'yes',
						'box_shadow_box_shadow'      => array( 'horizontal' => 0, 'vertical' => 4, 'blur' => 20, 'spread' => 0, 'color' => 'rgba(0,0,0,0.08)' ),
						'padding'                    => array( 'top' => '30', 'bottom' => '30', 'left' => '30', 'right' => '30', 'unit' => 'px', 'isLinked' => true ),
						'custom_css'                 => 'selector { transition: box-shadow 0.3s ease, transform 0.3s ease; } selector:hover { box-shadow: 0 8px 30px rgba(0,0,0,0.15); transform: translateY(-4px); }',
					),
					'elements' => array(
						$this->widget( 'heading', array(
							'title'       => (string) $number,
							'header_size' => 'h3',
							'align'       => 'center',
							'title_color' => '#0073aa',
							'typography_typography' => 'custom',
							'typography_font_size'  => array( 'size' => 40, 'unit' => 'px' ),
							'typography_font_weight' => '700',
						) ),
						$this->widget( 'icon-box', array(
							'selected_icon'    => array( 'value' => $icon, 'library' => 'fa-solid' ),
							'title_text'       => $title,
							'description_text' => $desc,
							'position'         => 'top',
							'align'            => 'center',
						) ),
					),
				);
			}

			$all_widgets[] = array(
				'id'       => $this->id(),
				'elType'   => 'container',
				'settings' => array(
					'flex_direction' => 'row',
					'flex_wrap'      => 'wrap',
					'flex_gap'       => array( 'size' => 20, 'unit' => 'px' ),
					'content_width'  => 'boxed',
					'padding'        => array( 'top' => '40', 'bottom' => '60', 'left' => '20', 'right' => '20', 'unit' => 'px' ),
				),
				'elements' => $inner_containers,
			);
		} else {
			$col_size  = (int) floor( 100 / $columns );
			$structure = array( 2 => '20', 3 => '30', 4 => '40' );

			$column_elements = array();
			foreach ( $items as $idx => $item ) {
				$number = isset( $item['number'] ) ? $item['number'] : ( $idx + 1 );
				$title  = isset( $item['title'] ) ? $item['title'] : '';
				$desc   = isset( $item['desc'] ) ? $item['desc'] : ( isset( $item['description'] ) ? $item['description'] : '' );
				$icon   = isset( $item['icon'] ) ? $item['icon'] : 'fas fa-circle';

				$column_elements[] = array(
					'id'       => $this->id(),
					'elType'   => 'column',
					'settings' => array( '_column_size' => $col_size ),
					'elements' => array(
						$this->widget( 'heading', array(
							'title'       => (string) $number,
							'header_size' => 'h3',
							'align'       => 'center',
							'title_color' => '#0073aa',
						) ),
						$this->widget( 'icon-box', array(
							'selected_icon'    => array( 'value' => $icon, 'library' => 'fa-solid' ),
							'title_text'       => $title,
							'description_text' => $desc,
							'position'         => 'top',
						) ),
					),
				);
			}

			$all_widgets[] = array(
				'id'       => $this->id(),
				'elType'   => 'section',
				'settings' => array(
					'structure' => isset( $structure[ $columns ] ) ? $structure[ $columns ] : '30',
					'padding'   => array( 'top' => '40', 'bottom' => '60', 'left' => '20', 'right' => '20', 'unit' => 'px' ),
				),
				'elements' => $column_elements,
			);
		}

		return $all_widgets;
	}

	/**
	 * Build a social proof / trust section with star-rated quote cards.
	 *
	 * @param array $p              Section params: heading, subheading, items[{text, author, rating}].
	 * @param bool  $use_containers Use container layout.
	 * @return array Elementor element(s).
	 */
	private function build_social_proof( $p, $use_containers ) {
		$heading    = isset( $p['heading'] ) ? $p['heading'] : 'What People Say';
		$subheading = isset( $p['subheading'] ) ? $p['subheading'] : '';
		$items      = isset( $p['items'] ) && is_array( $p['items'] ) ? $p['items'] : array();

		$all_widgets = array();

		$header_widgets = array();
		if ( $heading ) {
			$header_widgets[] = $this->widget( 'heading', array(
				'title'       => $heading,
				'header_size' => 'h2',
				'align'       => 'center',
			) );
		}
		if ( $subheading ) {
			$header_widgets[] = $this->widget( 'text-editor', array(
				'editor' => '<p style="text-align:center;">' . esc_html( $subheading ) . '</p>',
			) );
		}
		if ( ! empty( $header_widgets ) ) {
			$all_widgets[] = $this->wrap_section(
				$header_widgets,
				array( 'padding' => array( 'top' => '60', 'bottom' => '20', 'unit' => 'px' ) ),
				$use_containers
			);
		}

		$columns = min( count( $items ), 3 );
		if ( $columns < 2 ) {
			$columns = 2;
		}
		$card_width = 3 === $columns ? 30 : 47;

		if ( $use_containers ) {
			$inner_containers = array();
			foreach ( $items as $item ) {
				$text   = isset( $item['text'] ) ? $item['text'] : '';
				$author = isset( $item['author'] ) ? $item['author'] : '';
				$rating = isset( $item['rating'] ) ? min( max( (int) $item['rating'], 1 ), 5 ) : 5;

				$stars = str_repeat( '&#9733;', $rating ) . str_repeat( '&#9734;', 5 - $rating );

				$card_html  = '<p style="font-style:italic;color:#333;">&ldquo;' . esc_html( $text ) . '&rdquo;</p>';
				$card_html .= '<p style="font-size:20px;color:#f5a623;margin:8px 0;">' . $stars . '</p>';
				if ( $author ) {
					$card_html .= '<p style="font-weight:600;color:#555;">— ' . esc_html( $author ) . '</p>';
				}

				$inner_containers[] = array(
					'id'       => $this->id(),
					'elType'   => 'container',
					'settings' => array(
						'content_width'              => 'full',
						'_element_width'             => 'initial',
						'width'                      => array( 'size' => $card_width, 'unit' => '%' ),
						'background_background'      => 'classic',
						'background_color'           => '#FFFFFF',
						'border_radius'              => array( 'top_left' => '12', 'top_right' => '12', 'bottom_right' => '12', 'bottom_left' => '12', 'unit' => 'px', 'isLinked' => true ),
						'box_shadow_box_shadow_type' => 'yes',
						'box_shadow_box_shadow'      => array( 'horizontal' => 0, 'vertical' => 4, 'blur' => 20, 'spread' => 0, 'color' => 'rgba(0,0,0,0.08)' ),
						'padding'                    => array( 'top' => '30', 'bottom' => '30', 'left' => '30', 'right' => '30', 'unit' => 'px', 'isLinked' => true ),
						'custom_css'                 => 'selector { transition: box-shadow 0.3s ease, transform 0.3s ease; } selector:hover { box-shadow: 0 8px 30px rgba(0,0,0,0.15); transform: translateY(-4px); }',
					),
					'elements' => array( $this->widget( 'text-editor', array( 'editor' => $card_html ) ) ),
				);
			}

			$all_widgets[] = array(
				'id'       => $this->id(),
				'elType'   => 'container',
				'settings' => array(
					'flex_direction' => 'row',
					'flex_wrap'      => 'wrap',
					'flex_gap'       => array( 'size' => 20, 'unit' => 'px' ),
					'content_width'  => 'boxed',
					'padding'        => array( 'top' => '20', 'bottom' => '60', 'left' => '20', 'right' => '20', 'unit' => 'px' ),
				),
				'elements' => $inner_containers,
			);
		} else {
			$col_size  = (int) floor( 100 / $columns );
			$structure = array( 2 => '20', 3 => '30' );

			$column_elements = array();
			foreach ( $items as $item ) {
				$text   = isset( $item['text'] ) ? $item['text'] : '';
				$author = isset( $item['author'] ) ? $item['author'] : '';
				$rating = isset( $item['rating'] ) ? min( max( (int) $item['rating'], 1 ), 5 ) : 5;

				$stars    = str_repeat( '&#9733;', $rating ) . str_repeat( '&#9734;', 5 - $rating );
				$card_html = '<p><em>&ldquo;' . esc_html( $text ) . '&rdquo;</em></p><p>' . $stars . '</p>' . ( $author ? '<p><strong>— ' . esc_html( $author ) . '</strong></p>' : '' );

				$column_elements[] = array(
					'id'       => $this->id(),
					'elType'   => 'column',
					'settings' => array( '_column_size' => $col_size ),
					'elements' => array( $this->widget( 'text-editor', array( 'editor' => $card_html ) ) ),
				);
			}

			$all_widgets[] = array(
				'id'       => $this->id(),
				'elType'   => 'section',
				'settings' => array(
					'structure' => isset( $structure[ $columns ] ) ? $structure[ $columns ] : '30',
					'padding'   => array( 'top' => '20', 'bottom' => '60', 'left' => '20', 'right' => '20', 'unit' => 'px' ),
				),
				'elements' => $column_elements,
			);
		}

		return $all_widgets;
	}

	/**
	 * Build a single product showcase section.
	 *
	 * @param array $p              Section params: heading, image_url, title, desc, price, button_text, button_url, features[].
	 * @param bool  $use_containers Use container layout.
	 * @return array Elementor element.
	 */
	private function build_product_showcase( $p, $use_containers ) {
		$heading     = isset( $p['heading'] ) ? $p['heading'] : '';
		$image_url   = isset( $p['image_url'] ) ? $p['image_url'] : '';
		$title       = isset( $p['title'] ) ? $p['title'] : '';
		$desc        = isset( $p['desc'] ) ? $p['desc'] : '';
		$price       = isset( $p['price'] ) ? $p['price'] : '';
		$button_text = isset( $p['button_text'] ) ? $p['button_text'] : 'Buy Now';
		$button_url  = isset( $p['button_url'] ) ? $p['button_url'] : '#';
		$features    = isset( $p['features'] ) && is_array( $p['features'] ) ? $p['features'] : array();

		$elements = array();

		// Optional section heading.
		if ( $heading ) {
			$elements[] = $this->wrap_section(
				array( $this->widget( 'heading', array(
					'title'       => $heading,
					'header_size' => 'h2',
					'align'       => 'center',
				) ) ),
				array( 'padding' => array( 'top' => '60', 'bottom' => '20', 'unit' => 'px' ) ),
				$use_containers
			);
		}

		// Details column widgets.
		$detail_widgets = array();
		if ( $title ) {
			$detail_widgets[] = $this->widget( 'heading', array(
				'title'       => $title,
				'header_size' => 'h3',
				'align'       => 'left',
			) );
		}
		if ( $desc ) {
			$detail_widgets[] = $this->widget( 'text-editor', array(
				'editor' => '<p>' . wp_kses_post( $desc ) . '</p>',
			) );
		}
		if ( ! empty( $features ) ) {
			$icon_items = array();
			foreach ( $features as $feature ) {
				$icon_items[] = array(
					'icon' => array( 'value' => 'fas fa-check', 'library' => 'fa-solid' ),
					'text' => is_string( $feature ) ? $feature : '',
				);
			}
			$detail_widgets[] = $this->widget( 'icon-list', array(
				'icon_list' => $icon_items,
			) );
		}
		if ( $price ) {
			$detail_widgets[] = $this->widget( 'heading', array(
				'title'       => $price,
				'header_size' => 'h2',
				'align'       => 'left',
				'title_color' => '#0073aa',
				'typography_typography' => 'custom',
				'typography_font_size'  => array( 'size' => 36, 'unit' => 'px' ),
				'typography_font_weight' => '700',
			) );
		}
		if ( $button_text ) {
			$detail_widgets[] = $this->widget( 'button', array(
				'text'  => $button_text,
				'link'  => array( 'url' => $button_url, 'is_external' => false ),
				'align' => 'left',
				'size'  => 'lg',
			) );
		}

		$image_widget = $this->widget( 'image', array(
			'image'      => array( 'url' => $image_url ),
			'image_size' => 'large',
			'align'      => 'center',
		) );

		if ( $use_containers ) {
			$img_col = array(
				'id'       => $this->id(),
				'elType'   => 'container',
				'settings' => array(
					'content_width' => 'full',
					'width'         => array( 'size' => 47, 'unit' => '%' ),
					'padding'       => array( 'top' => '20', 'bottom' => '20', 'left' => '20', 'right' => '20', 'unit' => 'px', 'isLinked' => true ),
				),
				'elements' => array( $image_widget ),
			);
			$detail_col = array(
				'id'       => $this->id(),
				'elType'   => 'container',
				'settings' => array(
					'content_width' => 'full',
					'width'         => array( 'size' => 47, 'unit' => '%' ),
					'padding'       => array( 'top' => '20', 'bottom' => '20', 'left' => '20', 'right' => '20', 'unit' => 'px', 'isLinked' => true ),
				),
				'elements' => $detail_widgets,
			);

			$elements[] = array(
				'id'       => $this->id(),
				'elType'   => 'container',
				'settings' => array(
					'flex_direction' => 'row',
					'flex_wrap'      => 'wrap',
					'flex_gap'       => array( 'size' => 40, 'unit' => 'px' ),
					'content_width'  => 'boxed',
					'padding'        => array( 'top' => '40', 'bottom' => '60', 'left' => '20', 'right' => '20', 'unit' => 'px' ),
				),
				'elements' => array( $img_col, $detail_col ),
			);
		} else {
			$img_col_el = array(
				'id'       => $this->id(),
				'elType'   => 'column',
				'settings' => array( '_column_size' => 50 ),
				'elements' => array( $image_widget ),
			);
			$detail_col_el = array(
				'id'       => $this->id(),
				'elType'   => 'column',
				'settings' => array( '_column_size' => 50 ),
				'elements' => $detail_widgets,
			);

			$elements[] = array(
				'id'       => $this->id(),
				'elType'   => 'section',
				'settings' => array(
					'structure' => '20',
					'padding'   => array( 'top' => '40', 'bottom' => '60', 'left' => '20', 'right' => '20', 'unit' => 'px' ),
				),
				'elements' => array( $img_col_el, $detail_col_el ),
			);
		}

		return $elements;
	}

	/**
	 * Build a before/after comparison section.
	 *
	 * @param array $p              Section params: heading, before_heading, after_heading, before_items[], after_items[].
	 * @param bool  $use_containers Use container layout.
	 * @return array Elementor element(s).
	 */
	private function build_before_after( $p, $use_containers ) {
		$heading        = isset( $p['heading'] ) ? $p['heading'] : 'Before & After';
		$before_heading = isset( $p['before_heading'] ) ? $p['before_heading'] : 'Before';
		$after_heading  = isset( $p['after_heading'] ) ? $p['after_heading'] : 'After';
		$before_items   = isset( $p['before_items'] ) && is_array( $p['before_items'] ) ? $p['before_items'] : array();
		$after_items    = isset( $p['after_items'] ) && is_array( $p['after_items'] ) ? $p['after_items'] : array();

		$elements = array();

		if ( $heading ) {
			$elements[] = $this->wrap_section(
				array( $this->widget( 'heading', array(
					'title'       => $heading,
					'header_size' => 'h2',
					'align'       => 'center',
				) ) ),
				array( 'padding' => array( 'top' => '60', 'bottom' => '20', 'unit' => 'px' ) ),
				$use_containers
			);
		}

		// Build before column.
		$before_widgets = array(
			$this->widget( 'heading', array(
				'title'       => $before_heading,
				'header_size' => 'h3',
				'align'       => 'center',
				'title_color' => '#c0392b',
			) ),
		);
		if ( ! empty( $before_items ) ) {
			$before_icon_list = array();
			foreach ( $before_items as $bi ) {
				$before_icon_list[] = array(
					'icon' => array( 'value' => 'fas fa-times-circle', 'library' => 'fa-solid' ),
					'text' => is_string( $bi ) ? $bi : '',
				);
			}
			$before_widgets[] = $this->widget( 'icon-list', array( 'icon_list' => $before_icon_list ) );
		}

		// Build after column.
		$after_widgets = array(
			$this->widget( 'heading', array(
				'title'       => $after_heading,
				'header_size' => 'h3',
				'align'       => 'center',
				'title_color' => '#27ae60',
			) ),
		);
		if ( ! empty( $after_items ) ) {
			$after_icon_list = array();
			foreach ( $after_items as $ai ) {
				$after_icon_list[] = array(
					'icon' => array( 'value' => 'fas fa-check-circle', 'library' => 'fa-solid' ),
					'text' => is_string( $ai ) ? $ai : '',
				);
			}
			$after_widgets[] = $this->widget( 'icon-list', array( 'icon_list' => $after_icon_list ) );
		}

		if ( $use_containers ) {
			$before_col = array(
				'id'       => $this->id(),
				'elType'   => 'container',
				'settings' => array(
					'content_width'         => 'full',
					'width'                 => array( 'size' => 47, 'unit' => '%' ),
					'background_background' => 'classic',
					'background_color'      => '#fff5f5',
					'border_radius'         => array( 'top_left' => '12', 'top_right' => '12', 'bottom_right' => '12', 'bottom_left' => '12', 'unit' => 'px', 'isLinked' => true ),
					'padding'               => array( 'top' => '30', 'bottom' => '30', 'left' => '30', 'right' => '30', 'unit' => 'px', 'isLinked' => true ),
				),
				'elements' => $before_widgets,
			);
			$after_col = array(
				'id'       => $this->id(),
				'elType'   => 'container',
				'settings' => array(
					'content_width'         => 'full',
					'width'                 => array( 'size' => 47, 'unit' => '%' ),
					'background_background' => 'classic',
					'background_color'      => '#f0fff4',
					'border_radius'         => array( 'top_left' => '12', 'top_right' => '12', 'bottom_right' => '12', 'bottom_left' => '12', 'unit' => 'px', 'isLinked' => true ),
					'padding'               => array( 'top' => '30', 'bottom' => '30', 'left' => '30', 'right' => '30', 'unit' => 'px', 'isLinked' => true ),
				),
				'elements' => $after_widgets,
			);

			$elements[] = array(
				'id'       => $this->id(),
				'elType'   => 'container',
				'settings' => array(
					'flex_direction' => 'row',
					'flex_wrap'      => 'wrap',
					'flex_gap'       => array( 'size' => 30, 'unit' => 'px' ),
					'content_width'  => 'boxed',
					'padding'        => array( 'top' => '40', 'bottom' => '60', 'left' => '20', 'right' => '20', 'unit' => 'px' ),
				),
				'elements' => array( $before_col, $after_col ),
			);
		} else {
			$before_col_el = array(
				'id'       => $this->id(),
				'elType'   => 'column',
				'settings' => array( '_column_size' => 50 ),
				'elements' => $before_widgets,
			);
			$after_col_el = array(
				'id'       => $this->id(),
				'elType'   => 'column',
				'settings' => array( '_column_size' => 50 ),
				'elements' => $after_widgets,
			);

			$elements[] = array(
				'id'       => $this->id(),
				'elType'   => 'section',
				'settings' => array(
					'structure' => '20',
					'padding'   => array( 'top' => '40', 'bottom' => '60', 'left' => '20', 'right' => '20', 'unit' => 'px' ),
				),
				'elements' => array( $before_col_el, $after_col_el ),
			);
		}

		return $elements;
	}

	/**
	 * Build a newsletter email signup CTA section.
	 *
	 * @param array $p              Section params: heading, subheading, button_text, placeholder_text, background.
	 * @param bool  $use_containers Use container layout.
	 * @return array Elementor element.
	 */
	private function build_newsletter( $p, $use_containers ) {
		$heading          = isset( $p['heading'] ) ? $p['heading'] : 'Stay in the Loop';
		$subheading       = isset( $p['subheading'] ) ? $p['subheading'] : '';
		$button_text      = isset( $p['button_text'] ) ? $p['button_text'] : 'Subscribe';
		$placeholder_text = isset( $p['placeholder_text'] ) ? $p['placeholder_text'] : 'Enter your email';
		$background       = isset( $p['background'] ) ? $p['background'] : '#0073aa';

		$widgets = array();

		$widgets[] = $this->widget( 'heading', array(
			'title'       => $heading,
			'header_size' => 'h2',
			'align'       => 'center',
			'title_color' => '#FFFFFF',
			'typography_typography' => 'custom',
			'typography_font_size'  => array( 'size' => 36, 'unit' => 'px' ),
		) );

		if ( $subheading ) {
			$widgets[] = $this->widget( 'text-editor', array(
				'editor' => '<p style="text-align:center;color:#e0e0e0;font-size:18px;">' . esc_html( $subheading ) . '</p>',
			) );
		}

		// Placeholder text hint displayed as muted paragraph before the button.
		$widgets[] = $this->widget( 'text-editor', array(
			'editor' => '<p style="text-align:center;color:rgba(255,255,255,0.6);font-size:14px;margin-bottom:4px;">' . esc_html( $placeholder_text ) . '</p>',
		) );

		$widgets[] = $this->widget( 'button', array(
			'text'                => $button_text,
			'link'                => array( 'url' => '#newsletter', 'is_external' => false ),
			'align'               => 'center',
			'size'                => 'lg',
			'background_color'    => '#FFFFFF',
			'button_text_color'   => $background,
			'border_radius'       => array( 'top_left' => '6', 'top_right' => '6', 'bottom_right' => '6', 'bottom_left' => '6', 'unit' => 'px', 'isLinked' => true ),
			'hover_animation'     => 'float',
		) );

		$section_settings = array(
			'background_background' => 'classic',
			'background_color'      => $background && '#' === substr( $background, 0, 1 ) ? $background : '#0073aa',
			'padding'               => array( 'top' => '80', 'bottom' => '80', 'left' => '20', 'right' => '20', 'unit' => 'px' ),
		);

		if ( 'gradient' === $background ) {
			$section_settings['background_background']    = 'gradient';
			$section_settings['background_color']         = '#0073aa';
			$section_settings['background_color_b']       = '#005177';
			$section_settings['background_gradient_type']  = 'linear';
			$section_settings['background_gradient_angle'] = array( 'size' => 135, 'unit' => 'deg' );
		}

		return $this->wrap_section( $widgets, $section_settings, $use_containers );
	}

	// ---------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------

	/**
	 * Create a widget element.
	 *
	 * @param string $type     Widget type.
	 * @param array  $settings Widget settings.
	 * @return array Widget element.
	 */
	private function widget( $type, $settings = array() ) {
		return array(
			'id'         => $this->id(),
			'elType'     => 'widget',
			'widgetType' => $type,
			'settings'   => $settings,
			'elements'   => array(),
		);
	}

	/**
	 * Wrap widgets in a section/container.
	 *
	 * @param array $widgets        Widget elements.
	 * @param array $settings       Section settings.
	 * @param bool  $use_containers Use container layout.
	 * @return array Section or container element.
	 */
	private function wrap_section( $widgets, $settings = array(), $use_containers = false ) {
		if ( $use_containers ) {
			return array(
				'id'       => $this->id(),
				'elType'   => 'container',
				'settings' => $settings,
				'elements' => $widgets,
			);
		}

		// Classic: section > column > widgets.
		return array(
			'id'       => $this->id(),
			'elType'   => 'section',
			'settings' => $settings,
			'elements' => array(
				array(
					'id'       => $this->id(),
					'elType'   => 'column',
					'settings' => array( '_column_size' => 100 ),
					'elements' => $widgets,
				),
			),
		);
	}
}
