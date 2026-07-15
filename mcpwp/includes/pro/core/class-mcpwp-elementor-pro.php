<?php
/**
 * Elementor Pro Handler
 *
 * @package MCPWP_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elementor Pro functionality.
 *
 * Provides advanced Elementor features including templates,
 * landing pages, cloning, and widget management.
 */
class Mcpwp_Elementor_Pro {
	/**
	 * Post meta key: marks an Elementor template as a reusable part.
	 *
	 * @var string
	 */
	private $part_flag_meta_key = '_mcpwp_is_part';

	/**
	 * Post meta key: reusable part intent/kind (hero, faq, cta, etc.).
	 *
	 * @var string
	 */
	private $part_kind_meta_key = '_mcpwp_part_kind';

	/**
	 * Post meta key: reusable part style/variant label.
	 *
	 * @var string
	 */
	private $part_style_meta_key = '_mcpwp_part_style';

	/**
	 * Post meta key: reusable part tags.
	 *
	 * @var string
	 */
	private $part_tags_meta_key = '_mcpwp_part_tags';

	/**
	 * Post meta key: normalized searchable tag index.
	 *
	 * @var string
	 */
	private $part_tags_index_meta_key = '_mcpwp_part_tags_index';

	/**
	 * Post meta key: source page ID for extracted parts.
	 *
	 * @var string
	 */
	private $part_source_page_meta_key = '_mcpwp_part_source_page_id';

	/**
	 * Post meta key: source element ID for extracted parts.
	 *
	 * @var string
	 */
	private $part_source_element_meta_key = '_mcpwp_part_source_element_id';

	/**
	 * Post meta key: marks an Elementor template as a reusable archetype.
	 *
	 * @var string
	 */
	private $archetype_flag_meta_key = '_mcpwp_is_archetype';

	/**
	 * Post meta key: archetype scope such as page or product.
	 *
	 * @var string
	 */
	private $archetype_scope_meta_key = '_mcpwp_archetype_scope';

	/**
	 * Post meta key: archetype class such as blog_post or service_page.
	 *
	 * @var string
	 */
	private $archetype_class_meta_key = '_mcpwp_archetype_class';

	/**
	 * Post meta key: archetype style/variant label.
	 *
	 * @var string
	 */
	private $archetype_style_meta_key = '_mcpwp_archetype_style';

	/**
	 * Post meta key: archetype-specific override brief.
	 *
	 * @var string
	 */
	private $archetype_brief_meta_key = '_mcpwp_archetype_brief';

	/**
	 * Shared basic Elementor handler.
	 *
	 * @var Mcpwp_Elementor_Basic|null
	 */
	private $basic_handler = null;

	/**
	 * Check if Elementor is active.
	 *
	 * @return bool
	 */
	public function is_elementor_active() {
		return did_action( 'elementor/loaded' );
	}

	/**
	 * Check if Elementor Pro is active.
	 *
	 * @return bool
	 */
	public function is_elementor_pro_active() {
		return defined( 'ELEMENTOR_PRO_VERSION' );
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
	 * Save Elementor data through the shared basic Elementor handler.
	 *
	 * @param int          $post_id  Post/page/template ID.
	 * @param array|string $elements Elementor elements array or JSON string.
	 * @return array|WP_Error Save result.
	 */
	private function save_elementor_data( $post_id, $elements ) {
		$payload = array();

		if ( is_array( $elements ) ) {
			$payload['elementor_data'] = $elements;
		} else {
			$payload['elementor_data'] = (string) $elements;
		}

		return $this->get_basic_handler()->set_elementor_data( $post_id, $payload );
	}

	/**
	 * Check if templates are supported.
	 *
	 * @return bool
	 */
	public function supports_templates() {
		return $this->is_elementor_active();
	}

	/**
	 * Check if landing pages are supported.
	 *
	 * @return bool
	 */
	public function supports_landing_pages() {
		return $this->is_elementor_pro_active();
	}

	/**
	 * Check if globals are supported.
	 *
	 * @return bool
	 */
	public function supports_globals() {
		return $this->is_elementor_pro_active();
	}

	/**
	 * Get available widgets, or full controls for a specific widget.
	 *
	 * @param string|null $widget_name Optional widget type name to get controls for.
	 * @return array|WP_Error List of widgets or single widget with controls.
	 */
	public function get_available_widgets( $widget_name = null ) {
		if ( ! $this->is_elementor_active() ) {
			return array();
		}

		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return array();
		}

		$widget_manager = \Elementor\Plugin::instance()->widgets_manager;
		if ( ! $widget_manager ) {
			return array();
		}

		// Single widget with controls.
		if ( $widget_name ) {
			$widget = $widget_manager->get_widget_types( $widget_name );
			if ( ! $widget ) {
				return new \WP_Error( 'widget_not_found', sprintf( 'Widget "%s" not found.', $widget_name ), array( 'status' => 404 ) );
			}

			$controls = $widget->get_controls();
			$formatted = array();
			foreach ( $controls as $key => $control ) {
				$item = array(
					'type'  => isset( $control['type'] ) ? $control['type'] : '',
					'label' => isset( $control['label'] ) ? $control['label'] : '',
				);
				if ( ! empty( $control['default'] ) ) {
					$item['default'] = $control['default'];
				}
				if ( ! empty( $control['options'] ) ) {
					$item['options'] = $control['options'];
				}
				if ( ! empty( $control['selectors'] ) ) {
					$item['selectors'] = $control['selectors'];
				}
				$formatted[ $key ] = $item;
			}

			return array(
				'name'     => $widget->get_name(),
				'title'    => $widget->get_title(),
				'icon'     => $widget->get_icon(),
				'category' => $widget->get_categories(),
				'controls' => $formatted,
			);
		}

		// List all widgets.
		$widgets    = array();
		$registered = $widget_manager->get_widget_types();
		foreach ( $registered as $widget ) {
			$widgets[] = array(
				'name'     => $widget->get_name(),
				'title'    => $widget->get_title(),
				'icon'     => $widget->get_icon(),
				'category' => $widget->get_categories(),
			);
		}

		return $widgets;
	}

	/**
	 * Get all Elementor templates.
	 *
	 * @param array $args Query arguments.
	 * @return array Templates list.
	 */
	public function get_templates( $args = array() ) {
		if ( ! $this->is_elementor_active() ) {
			return array();
		}

		$defaults = array(
			'post_type'      => 'elementor_library',
			'posts_per_page' => 50,
			'post_status'    => 'publish',
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		// Filter by template type if specified.
		if ( ! empty( $args['type'] ) ) {
			$defaults['meta_query'] = array(
				array(
					'key'   => '_elementor_template_type',
					'value' => sanitize_text_field( $args['type'] ),
				),
			);
		}

		$query_args = wp_parse_args( $args, $defaults );
		$templates  = get_posts( $query_args );

		$result = array();
		foreach ( $templates as $template ) {
			$result[] = $this->format_template( $template );
		}

		return $result;
	}

	/**
	 * List reusable Elementor parts.
	 *
	 * @param array $args Query arguments.
	 * @return array Parts list.
	 */
	public function get_parts( $args = array() ) {
		$args = is_array( $args ) ? $args : array();

		$meta_query = array(
			'relation' => 'AND',
			array(
				'key'   => $this->part_flag_meta_key,
				'value' => 'yes',
			),
		);

		if ( ! empty( $args['kind'] ) ) {
			$meta_query[] = array(
				'key'   => $this->part_kind_meta_key,
				'value' => sanitize_text_field( $args['kind'] ),
			);
		}

		if ( ! empty( $args['style'] ) ) {
			$meta_query[] = array(
				'key'   => $this->part_style_meta_key,
				'value' => sanitize_text_field( $args['style'] ),
			);
		}

		if ( ! empty( $args['tag'] ) ) {
			$meta_query[] = array(
				'relation' => 'OR',
				array(
					'key'     => $this->part_tags_index_meta_key,
					'value'   => sanitize_text_field( $args['tag'] ),
					'compare' => 'LIKE',
				),
				array(
					'key'     => $this->part_tags_meta_key,
					'value'   => sanitize_text_field( $args['tag'] ),
					'compare' => 'LIKE',
				),
			);
		}

		unset( $args['kind'], $args['style'], $args['tag'] );
		$args['meta_query'] = $meta_query;

		return $this->get_templates( $args );
	}

	/**
	 * List reusable Elementor archetypes.
	 *
	 * @param array $args Query arguments.
	 * @return array Archetypes list.
	 */
	public function get_archetypes( $args = array() ) {
		$args = is_array( $args ) ? $args : array();

		$meta_query = array(
			'relation' => 'AND',
			array(
				'key'   => $this->archetype_flag_meta_key,
				'value' => 'yes',
			),
		);

		if ( ! empty( $args['scope'] ) ) {
			$meta_query[] = array(
				'key'   => $this->archetype_scope_meta_key,
				'value' => sanitize_key( $args['scope'] ),
			);
		}

		if ( ! empty( $args['archetype_class'] ) ) {
			$meta_query[] = array(
				'key'   => $this->archetype_class_meta_key,
				'value' => sanitize_key( $args['archetype_class'] ),
			);
		}

		if ( ! empty( $args['style'] ) ) {
			$meta_query[] = array(
				'key'   => $this->archetype_style_meta_key,
				'value' => sanitize_text_field( $args['style'] ),
			);
		}

		unset( $args['scope'], $args['archetype_class'], $args['style'] );
		$args['meta_query'] = $meta_query;

		return $this->get_templates( $args );
	}

	/**
	 * Get single template.
	 *
	 * @param int $template_id Template ID.
	 * @return array|WP_Error Template data.
	 */
	public function get_template( $template_id ) {
		if ( ! $this->is_elementor_active() ) {
			return new WP_Error( 'elementor_inactive', __( 'Elementor is not active.', 'mcpwp' ) );
		}

		$template = get_post( $template_id );

		if ( ! $template || 'elementor_library' !== $template->post_type ) {
			return new WP_Error( 'not_found', __( 'Template not found.', 'mcpwp' ) );
		}

		return $this->format_template( $template, true );
	}

	/**
	 * Get a reusable Elementor part by ID.
	 *
	 * @param int $part_id Part/template ID.
	 * @return array|WP_Error Part data or error.
	 */
	public function get_part( $part_id ) {
		$part = $this->get_template( $part_id );
		if ( is_wp_error( $part ) ) {
			return $part;
		}

		if ( empty( $part['is_part'] ) ) {
			return new WP_Error( 'not_part', __( 'Template is not marked as a reusable part.', 'mcpwp' ) );
		}

		return $part;
	}

	/**
	 * Get a reusable Elementor archetype by ID.
	 *
	 * @param int $archetype_id Archetype/template ID.
	 * @return array|WP_Error Archetype data or error.
	 */
	public function get_archetype( $archetype_id ) {
		$archetype = $this->get_template( $archetype_id );
		if ( is_wp_error( $archetype ) ) {
			return $archetype;
		}

		if ( empty( $archetype['is_archetype'] ) ) {
			return new WP_Error( 'not_archetype', __( 'Template is not marked as an archetype.', 'mcpwp' ) );
		}

		return $archetype;
	}

	/**
	 * Format template for API response.
	 *
	 * @param WP_Post $template    Template post.
	 * @param bool    $include_data Include Elementor data.
	 * @return array Formatted template.
	 */
	private function format_template( $template, $include_data = false ) {
		$part_meta      = $this->get_part_metadata( $template->ID );
		$archetype_meta = $this->get_archetype_metadata( $template->ID );

		$data = array(
			'id'         => $template->ID,
			'title'      => $template->post_title,
			'slug'       => $template->post_name,
			'type'       => get_post_meta( $template->ID, '_elementor_template_type', true ),
			'created'    => $template->post_date,
			'modified'   => $template->post_modified,
			'edit_url'   => admin_url( 'post.php?post=' . $template->ID . '&action=elementor' ),
			'is_part'    => $part_meta['is_part'],
			'part_kind'  => $part_meta['part_kind'],
			'part_style' => $part_meta['part_style'],
			'part_tags'  => $part_meta['part_tags'],
			'is_archetype'   => $archetype_meta['is_archetype'],
			'archetype_scope' => $archetype_meta['archetype_scope'],
			'archetype_class' => $archetype_meta['archetype_class'],
			'archetype_style' => $archetype_meta['archetype_style'],
			'archetype_brief' => $archetype_meta['archetype_brief'],
		);

		if ( ! empty( $part_meta['source_page_id'] ) ) {
			$data['source_page_id'] = $part_meta['source_page_id'];
		}

		if ( ! empty( $part_meta['source_element_id'] ) ) {
			$data['source_element_id'] = $part_meta['source_element_id'];
		}

		if ( $include_data ) {
			// Parse into the same array/object shapes wp_get_elementor returns and
			// wp_create_elementor_*/wp_update_elementor_* accept — the raw JSON
			// string form broke direct read -> modify -> write round trips (#636).
			$raw_data          = get_post_meta( $template->ID, '_elementor_data', true );
			$raw_page_settings = get_post_meta( $template->ID, '_elementor_page_settings', true );

			$decoded_data           = $raw_data ? json_decode( $raw_data, true ) : array();
			$data['elementor_data'] = is_array( $decoded_data ) ? $decoded_data : array();

			if ( empty( $raw_page_settings ) ) {
				$data['page_settings'] = null;
			} else {
				$decoded_settings      = is_array( $raw_page_settings ) ? $raw_page_settings : json_decode( $raw_page_settings, true );
				$data['page_settings'] = is_array( $decoded_settings ) ? $decoded_settings : null;
			}
		}

		return $data;
	}

	/**
	 * Create a new template.
	 *
	 * @param array $data Template data.
	 * @return array|WP_Error Created template or error.
	 */
	public function create_template( $data ) {
		if ( ! $this->is_elementor_active() ) {
			return new WP_Error( 'elementor_inactive', __( 'Elementor is not active.', 'mcpwp' ) );
		}

		$title = ! empty( $data['title'] ) ? sanitize_text_field( $data['title'] ) : __( 'Untitled Template', 'mcpwp' );
		$type  = ! empty( $data['type'] ) ? sanitize_text_field( $data['type'] ) : 'page';

		// Valid template types.
		$valid_types = array( 'page', 'section', 'header', 'footer', 'single', 'archive', 'popup', 'loop-item' );
		if ( ! in_array( $type, $valid_types, true ) ) {
			$type = 'page';
		}

		$post_data = array(
			'post_title'  => $title,
			'post_status' => 'publish',
			'post_type'   => 'elementor_library',
		);

		$template_id = wp_insert_post( $post_data );

		if ( is_wp_error( $template_id ) ) {
			return $template_id;
		}

		// Set template type.
		update_post_meta( $template_id, '_elementor_template_type', $type );
		update_post_meta( $template_id, '_elementor_edit_mode', 'builder' );

		// Set Elementor data if provided.
		if ( ! empty( $data['elementor_data'] ) ) {
			$save_result = $this->save_elementor_data( $template_id, $data['elementor_data'] );
			if ( is_wp_error( $save_result ) ) {
				return $save_result;
			}
		}

		$this->save_part_metadata( $template_id, $data );
		$this->save_archetype_metadata( $template_id, $data );

		return $this->get_template( $template_id );
	}

	/**
	 * Update a template.
	 *
	 * @param int   $template_id Template ID.
	 * @param array $data        Update data.
	 * @return array|WP_Error Updated template or error.
	 */
	public function update_template( $template_id, $data ) {
		$template = get_post( $template_id );

		if ( ! $template || 'elementor_library' !== $template->post_type ) {
			return new WP_Error( 'not_found', __( 'Template not found.', 'mcpwp' ) );
		}

		// Update title if provided.
		if ( ! empty( $data['title'] ) ) {
			wp_update_post( array(
				'ID'         => $template_id,
				'post_title' => sanitize_text_field( $data['title'] ),
			) );
		}

		// Update Elementor data if provided.
		if ( ! empty( $data['elementor_data'] ) ) {
			$save_result = $this->save_elementor_data( $template_id, $data['elementor_data'] );
			if ( is_wp_error( $save_result ) ) {
				return $save_result;
			}
		}

		$this->save_part_metadata( $template_id, $data );
		$this->save_archetype_metadata( $template_id, $data );

		return $this->get_template( $template_id );
	}

	/**
	 * Create a reusable archetype directly from Elementor template data.
	 *
	 * @param array $data Archetype definition.
	 * @return array|WP_Error
	 */
	public function create_archetype( $data ) {
		$data['is_archetype'] = true;
		$data['type']         = ! empty( $data['type'] ) ? $data['type'] : 'page';

		return $this->create_template( $data );
	}

	/**
	 * Create a reusable part directly from Elementor template data.
	 *
	 * @param array $data Part definition.
	 * @return array|WP_Error
	 */
	public function create_part( $data ) {
		$data['is_part'] = true;
		$data['type']    = ! empty( $data['type'] ) ? $data['type'] : 'section';

		return $this->create_template( $data );
	}

	/**
	 * Create a reusable part by extracting a section from a live page.
	 *
	 * @param int   $page_id    Source page ID.
	 * @param string $element_id Source section/container ID.
	 * @param array $data       Part metadata.
	 * @return array|WP_Error
	 */
	public function create_part_from_section( $page_id, $element_id, $data = array() ) {
		$title = ! empty( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '';
		$result = $this->save_section_as_template( $page_id, $element_id, $title );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$update = array(
			'is_part'           => true,
			'part_kind'         => isset( $data['part_kind'] ) ? $data['part_kind'] : '',
			'part_style'        => isset( $data['part_style'] ) ? $data['part_style'] : '',
			'part_tags'         => isset( $data['part_tags'] ) ? $data['part_tags'] : array(),
			'source_page_id'    => $page_id,
			'source_element_id' => $element_id,
		);

		$this->save_part_metadata( $result['template_id'], $update );

		return $this->get_part( $result['template_id'] );
	}

	/**
	 * Apply a reusable part to a page.
	 *
	 * @param int $part_id Part/template ID.
	 * @param int $page_id Target page ID.
	 * @return bool|WP_Error
	 */
	public function apply_part_to_page( $part_id, $page_id ) {
		$part = $this->get_part( $part_id );
		if ( is_wp_error( $part ) ) {
			return $part;
		}

		return $this->apply_template_to_page( $page_id, $part_id );
	}

	/**
	 * Apply a reusable archetype to a page.
	 *
	 * @param int $archetype_id Archetype/template ID.
	 * @param int $page_id      Target page ID.
	 * @return bool|WP_Error
	 */
	public function apply_archetype_to_page( $archetype_id, $page_id ) {
		$archetype = $this->get_archetype( $archetype_id );
		if ( is_wp_error( $archetype ) ) {
			return $archetype;
		}

		return $this->apply_template_to_page( $page_id, $archetype_id );
	}

	/**
	 * Insert a reusable part into an existing page without replacing all content.
	 *
	 * @param int    $part_id   Part/template ID.
	 * @param int    $page_id   Target page ID.
	 * @param string $position  Insert position: start, end, before:{id}, after:{id}.
	 * @return array|WP_Error
	 */
	public function insert_part_into_page( $part_id, $page_id, $position = 'end' ) {
		$part = $this->get_part( $part_id );
		if ( is_wp_error( $part ) ) {
			return $part;
		}

		$elements = $part['elementor_data'];
		if ( is_string( $elements ) ) {
			$decoded = json_decode( $elements, true );
			$elements = is_array( $decoded ) ? $decoded : array();
		}

		if ( ! is_array( $elements ) || empty( $elements ) ) {
			return new WP_Error( 'empty_part', __( 'Reusable part has no Elementor elements.', 'mcpwp' ) );
		}

		$basic   = $this->get_basic_handler();
		$results = array();

		foreach ( array_values( $elements ) as $index => $element ) {
			$current_position = ( 0 === $index ) ? $position : 'end';
			$result           = $basic->add_section(
				$page_id,
				array(
					'element'  => $element,
					'position' => $current_position,
				)
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$results[] = $result;
		}

		return array(
			'inserted'        => true,
			'part_id'         => (int) $part_id,
			'page_id'         => (int) $page_id,
			'position'        => $position,
			'inserted_count'  => count( $results ),
			'inserted_ids'    => wp_list_pluck( $results, 'element_id' ),
			'last_operation'  => end( $results ),
		);
	}

	/**
	 * Save reusable part metadata.
	 *
	 * @param int   $template_id Template ID.
	 * @param array $data        Part metadata payload.
	 * @return void
	 */
	private function save_part_metadata( $template_id, $data ) {
		if ( array_key_exists( 'is_part', $data ) ) {
			$is_part = ! empty( $data['is_part'] ) ? 'yes' : '';
			if ( '' === $is_part ) {
				delete_post_meta( $template_id, $this->part_flag_meta_key );
			} else {
				update_post_meta( $template_id, $this->part_flag_meta_key, $is_part );
			}
		}

		$meta_map = array(
			'part_kind'         => $this->part_kind_meta_key,
			'part_style'        => $this->part_style_meta_key,
			'source_page_id'    => $this->part_source_page_meta_key,
			'source_element_id' => $this->part_source_element_meta_key,
		);

		foreach ( $meta_map as $input_key => $meta_key ) {
			if ( ! array_key_exists( $input_key, $data ) ) {
				continue;
			}

			$value = is_numeric( $data[ $input_key ] ) ? absint( $data[ $input_key ] ) : sanitize_text_field( (string) $data[ $input_key ] );

			if ( '' === $value || 0 === $value ) {
				delete_post_meta( $template_id, $meta_key );
			} else {
				update_post_meta( $template_id, $meta_key, $value );
			}
		}

		if ( array_key_exists( 'part_tags', $data ) ) {
			$tags = $this->normalize_part_tags( $data['part_tags'] );
			if ( empty( $tags ) ) {
				delete_post_meta( $template_id, $this->part_tags_meta_key );
				delete_post_meta( $template_id, $this->part_tags_index_meta_key );
			} else {
				update_post_meta( $template_id, $this->part_tags_meta_key, wp_json_encode( $tags ) );
				update_post_meta( $template_id, $this->part_tags_index_meta_key, implode( ' ', array_map( 'sanitize_title', $tags ) ) );
			}
		}
	}

	/**
	 * Read reusable part metadata.
	 *
	 * @param int $template_id Template ID.
	 * @return array
	 */
	private function get_part_metadata( $template_id ) {
		$raw_tags = get_post_meta( $template_id, $this->part_tags_meta_key, true );
		$tags     = array();

		if ( is_string( $raw_tags ) && '' !== $raw_tags ) {
			$decoded = json_decode( $raw_tags, true );
			if ( is_array( $decoded ) ) {
				$tags = $this->normalize_part_tags( $decoded );
			} else {
				$tags = $this->normalize_part_tags( explode( ',', $raw_tags ) );
			}
		}

		return array(
			'is_part'           => 'yes' === get_post_meta( $template_id, $this->part_flag_meta_key, true ),
			'part_kind'         => (string) get_post_meta( $template_id, $this->part_kind_meta_key, true ),
			'part_style'        => (string) get_post_meta( $template_id, $this->part_style_meta_key, true ),
			'part_tags'         => $tags,
			'source_page_id'    => absint( get_post_meta( $template_id, $this->part_source_page_meta_key, true ) ),
			'source_element_id' => (string) get_post_meta( $template_id, $this->part_source_element_meta_key, true ),
		);
	}

	/**
	 * Save archetype metadata.
	 *
	 * @param int   $template_id Template ID.
	 * @param array $data        Metadata payload.
	 * @return void
	 */
	private function save_archetype_metadata( $template_id, $data ) {
		if ( array_key_exists( 'is_archetype', $data ) ) {
			$is_archetype = ! empty( $data['is_archetype'] ) ? 'yes' : '';
			if ( '' === $is_archetype ) {
				delete_post_meta( $template_id, $this->archetype_flag_meta_key );
			} else {
				update_post_meta( $template_id, $this->archetype_flag_meta_key, $is_archetype );
			}
		}

		$meta_map = array(
			'archetype_scope' => $this->archetype_scope_meta_key,
			'archetype_class' => $this->archetype_class_meta_key,
			'archetype_style' => $this->archetype_style_meta_key,
			'archetype_brief' => $this->archetype_brief_meta_key,
		);

		foreach ( $meta_map as $input_key => $meta_key ) {
			if ( ! array_key_exists( $input_key, $data ) ) {
				continue;
			}

			$value = 'archetype_brief' === $input_key
				? sanitize_textarea_field( (string) $data[ $input_key ] )
				: sanitize_text_field( (string) $data[ $input_key ] );
			if ( in_array( $input_key, array( 'archetype_scope', 'archetype_class' ), true ) ) {
				$value = sanitize_key( $value );
			}

			if ( '' === $value ) {
				delete_post_meta( $template_id, $meta_key );
			} else {
				update_post_meta( $template_id, $meta_key, $value );
			}
		}
	}

	/**
	 * Read archetype metadata.
	 *
	 * @param int $template_id Template ID.
	 * @return array
	 */
	private function get_archetype_metadata( $template_id ) {
		return array(
			'is_archetype'    => 'yes' === get_post_meta( $template_id, $this->archetype_flag_meta_key, true ),
			'archetype_scope' => (string) get_post_meta( $template_id, $this->archetype_scope_meta_key, true ),
			'archetype_class' => (string) get_post_meta( $template_id, $this->archetype_class_meta_key, true ),
			'archetype_style' => (string) get_post_meta( $template_id, $this->archetype_style_meta_key, true ),
			'archetype_brief' => (string) get_post_meta( $template_id, $this->archetype_brief_meta_key, true ),
		);
	}

	/**
	 * Normalize part tags into a clean string array.
	 *
	 * @param mixed $tags Raw input.
	 * @return array
	 */
	private function normalize_part_tags( $tags ) {
		if ( is_string( $tags ) ) {
			$tags = explode( ',', $tags );
		}

		if ( ! is_array( $tags ) ) {
			return array();
		}

		$clean = array();
		foreach ( $tags as $tag ) {
			$tag = sanitize_text_field( (string) $tag );
			if ( '' !== $tag ) {
				$clean[] = $tag;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Delete a template.
	 *
	 * @param int  $template_id Template ID.
	 * @param bool $force       Force delete (bypass trash).
	 * @return bool|WP_Error True on success, error on failure.
	 */
	public function delete_template( $template_id, $force = false ) {
		$template = get_post( $template_id );

		if ( ! $template || 'elementor_library' !== $template->post_type ) {
			return new WP_Error( 'not_found', __( 'Template not found.', 'mcpwp' ) );
		}

		$result = wp_delete_post( $template_id, $force );

		if ( ! $result ) {
			return new WP_Error( 'delete_failed', __( 'Failed to delete template.', 'mcpwp' ) );
		}

		return true;
	}

	/**
	 * Clone a page with Elementor data.
	 *
	 * @param int   $source_id Source page/post ID.
	 * @param array $args      Clone arguments.
	 * @return array|WP_Error Cloned page data or error.
	 */
	public function clone_page( $source_id, $args = array() ) {
		$source = get_post( $source_id );

		if ( ! $source ) {
			return new WP_Error( 'not_found', __( 'Source page not found.', 'mcpwp' ) );
		}

		$title  = ! empty( $args['title'] ) ? sanitize_text_field( $args['title'] ) : $source->post_title . ' (Copy)';
		$status = ! empty( $args['status'] ) ? sanitize_text_field( $args['status'] ) : 'draft';

		// Create new post.
		$new_post = array(
			'post_title'   => $title,
			'post_content' => $source->post_content,
			'post_excerpt' => $source->post_excerpt,
			'post_status'  => $status,
			'post_type'    => $source->post_type,
			'post_author'  => get_current_user_id(),
		);

		if ( ! empty( $args['parent'] ) ) {
			$new_post['post_parent'] = absint( $args['parent'] );
		}

		$new_id = wp_insert_post( $new_post );

		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		// Copy all post meta.
		$meta = get_post_meta( $source_id );
		foreach ( $meta as $key => $values ) {
			foreach ( $values as $value ) {
				add_post_meta( $new_id, $key, maybe_unserialize( $value ) );
			}
		}

		// Copy taxonomies.
		$taxonomies = get_object_taxonomies( $source->post_type );
		foreach ( $taxonomies as $taxonomy ) {
			$terms = wp_get_object_terms( $source_id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				wp_set_object_terms( $new_id, $terms, $taxonomy );
			}
		}

		// Get the cloned page with Elementor data.
		$result = array(
			'id'             => $new_id,
			'title'          => $title,
			'status'         => $status,
			'source_id'      => $source_id,
			'url'            => get_permalink( $new_id ),
			'edit_url'       => admin_url( 'post.php?post=' . $new_id . '&action=elementor' ),
			'elementor_data' => get_post_meta( $new_id, '_elementor_data', true ),
		);

		return $result;
	}

	/**
	 * Create a landing page with Elementor.
	 *
	 * @param array $data Landing page data.
	 * @return array|WP_Error Created page data or error.
	 */
	public function create_landing_page( $data ) {
		if ( ! $this->is_elementor_active() ) {
			return new WP_Error( 'elementor_inactive', __( 'Elementor is not active.', 'mcpwp' ) );
		}

		$title = ! empty( $data['title'] ) ? sanitize_text_field( $data['title'] ) : __( 'Landing Page', 'mcpwp' );

		// Create the page.
		$page_data = array(
			'post_title'  => $title,
			'post_status' => ! empty( $data['status'] ) ? sanitize_text_field( $data['status'] ) : 'draft',
			'post_type'   => 'page',
			'post_author' => get_current_user_id(),
		);

		$page_id = wp_insert_post( $page_data );

		if ( is_wp_error( $page_id ) ) {
			return $page_id;
		}

		// Set Elementor template.
		update_post_meta( $page_id, '_wp_page_template', 'elementor_header_footer' );
		update_post_meta( $page_id, '_elementor_edit_mode', 'builder' );

		// Build Elementor structure if sections provided.
		if ( ! empty( $data['sections'] ) ) {
			$elementor_data = $this->build_landing_page_structure( $data['sections'] );
			$save_result = $this->save_elementor_data( $page_id, $elementor_data );
			if ( is_wp_error( $save_result ) ) {
				return $save_result;
			}
		} elseif ( ! empty( $data['elementor_data'] ) ) {
			$save_result = $this->save_elementor_data( $page_id, $data['elementor_data'] );
			if ( is_wp_error( $save_result ) ) {
				return $save_result;
			}
		}

		// Apply template if specified.
		if ( ! empty( $data['template_id'] ) ) {
			$this->apply_template_to_page( $page_id, absint( $data['template_id'] ) );
		}

		return array(
			'id'             => $page_id,
			'title'          => $title,
			'status'         => get_post_status( $page_id ),
			'url'            => get_permalink( $page_id ),
			'edit_url'       => admin_url( 'post.php?post=' . $page_id . '&action=elementor' ),
			'elementor_data' => get_post_meta( $page_id, '_elementor_data', true ),
		);
	}

	/**
	 * Build landing page structure from section definitions.
	 *
	 * @param array $sections Section definitions.
	 * @return array Elementor data structure.
	 */
	private function build_landing_page_structure( $sections ) {
		$data = array();

		foreach ( $sections as $section ) {
			$section_id = $this->generate_element_id();
			$section_data = array(
				'id'       => $section_id,
				'elType'   => 'section',
				'settings' => array(),
				'elements' => array(),
			);

			// Apply section settings.
			if ( ! empty( $section['settings'] ) ) {
				$section_data['settings'] = $section['settings'];
			}

			// Add columns.
			$columns = ! empty( $section['columns'] ) ? $section['columns'] : array( array() );
			foreach ( $columns as $column ) {
				$column_id   = $this->generate_element_id();
				$column_data = array(
					'id'       => $column_id,
					'elType'   => 'column',
					'settings' => ! empty( $column['settings'] ) ? $column['settings'] : array(
						'_column_size' => floor( 100 / count( $columns ) ),
					),
					'elements' => array(),
				);

				// Add widgets to column.
				if ( ! empty( $column['widgets'] ) ) {
					foreach ( $column['widgets'] as $widget ) {
						$widget_id   = $this->generate_element_id();
						$widget_data = array(
							'id'         => $widget_id,
							'elType'     => 'widget',
							'widgetType' => $widget['type'],
							'settings'   => ! empty( $widget['settings'] ) ? $widget['settings'] : array(),
						);
						$column_data['elements'][] = $widget_data;
					}
				}

				$section_data['elements'][] = $column_data;
			}

			$data[] = $section_data;
		}

		return $data;
	}

	/**
	 * Apply a template to a page.
	 *
	 * @param int $page_id     Target page ID.
	 * @param int $template_id Template ID.
	 * @return bool|WP_Error True on success, error on failure.
	 */
	public function apply_template_to_page( $page_id, $template_id ) {
		$template = get_post( $template_id );

		if ( ! $template || 'elementor_library' !== $template->post_type ) {
			return new WP_Error( 'not_found', __( 'Template not found.', 'mcpwp' ) );
		}

		// Get template data.
		$template_data = get_post_meta( $template_id, '_elementor_data', true );

		if ( empty( $template_data ) ) {
			return new WP_Error( 'empty_template', __( 'Template has no content.', 'mcpwp' ) );
		}

		// Set required meta for frontend rendering.
		update_post_meta( $page_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $page_id, '_wp_page_template', 'elementor_header_footer' );

		$post_type  = get_post_type( $page_id );
		$type_value = ( 'elementor_library' === $post_type ) ? 'section' : 'wp-page';
		update_post_meta( $page_id, '_elementor_template_type', $type_value );

		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			update_post_meta( $page_id, '_elementor_version', ELEMENTOR_VERSION );
		}
		if ( defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			update_post_meta( $page_id, '_elementor_pro_version', ELEMENTOR_PRO_VERSION );
		}

		// Save via Document::save() for proper frontend rendering.
		$save_result = $this->save_elementor_data( $page_id, $template_data );
		if ( is_wp_error( $save_result ) ) {
			return $save_result;
		}

		return true;
	}

	/**
	 * Get global colors and fonts (Elementor Pro).
	 *
	 * @return array|WP_Error Global settings or error.
	 */
	public function get_globals() {
		if ( ! $this->is_elementor_pro_active() ) {
			return new WP_Error( 'pro_required', __( 'Elementor Pro is required for global settings.', 'mcpwp' ) );
		}

		$globals = array(
			'colors' => array(
				'system_colors' => array(),
				'custom_colors' => array(),
			),
			'fonts'  => array(
				'system_typography' => array(),
				'custom_typography' => array(),
			),
		);

		// Get kit settings.
		if ( class_exists( '\Elementor\Plugin' ) ) {
			$kit = \Elementor\Plugin::instance()->kits_manager->get_active_kit();
			if ( $kit ) {
				$kit_settings = $kit->get_settings();

				// Extract global colors — both system and custom.
				if ( ! empty( $kit_settings['system_colors'] ) ) {
					$globals['colors']['system_colors'] = $kit_settings['system_colors'];
				}
				if ( ! empty( $kit_settings['custom_colors'] ) ) {
					$globals['colors']['custom_colors'] = $kit_settings['custom_colors'];
				}

				// Extract global fonts — both system and custom.
				if ( ! empty( $kit_settings['system_typography'] ) ) {
					$globals['fonts']['system_typography'] = $kit_settings['system_typography'];
				}
				if ( ! empty( $kit_settings['custom_typography'] ) ) {
					$globals['fonts']['custom_typography'] = $kit_settings['custom_typography'];
				}
			}
		}

		return $globals;
	}

	/**
	 * Save a section from a live page as a reusable Elementor library template.
	 *
	 * @param int    $page_id    Page containing the section.
	 * @param string $element_id Element ID to extract.
	 * @param string $title      Template title (optional).
	 * @return array|WP_Error Template info or error.
	 */
	public function save_section_as_template( $page_id, $element_id, $title = '' ) {
		$post = get_post( $page_id );
		if ( ! $post ) {
			return new WP_Error( 'invalid_page', 'Page not found.', array( 'status' => 404 ) );
		}

		$raw      = get_post_meta( $page_id, '_elementor_data', true );
		$elements = json_decode( $raw, true );
		if ( ! is_array( $elements ) ) {
			return new WP_Error( 'no_elementor_data', 'Page has no Elementor data.', array( 'status' => 400 ) );
		}

		// Recursively find element by ID.
		$found = $this->find_element_by_id( $elements, $element_id );
		if ( ! $found ) {
			return new WP_Error( 'element_not_found', sprintf( 'Element with ID "%s" not found on page %d.', $element_id, $page_id ), array( 'status' => 404 ) );
		}

		if ( empty( $title ) ) {
			$title = sprintf( 'Section from Page %d', $page_id );
		}

		// Determine template type based on elType.
		$template_type = 'section';
		if ( isset( $found['elType'] ) && 'container' === $found['elType'] ) {
			$template_type = 'container';
		}

		// Create the template post.
		$template_id = wp_insert_post( array(
			'post_title'  => sanitize_text_field( $title ),
			'post_status' => 'publish',
			'post_type'   => 'elementor_library',
		) );

		if ( is_wp_error( $template_id ) ) {
			return $template_id;
		}

		// Set template meta.
		update_post_meta( $template_id, '_elementor_template_type', $template_type );
		update_post_meta( $template_id, '_elementor_edit_mode', 'builder' );

		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			update_post_meta( $template_id, '_elementor_version', ELEMENTOR_VERSION );
		}

		// Save the element data (wrapped in array).
		$template_data = array( $found );
		$save_result = $this->save_elementor_data( $template_id, $template_data );
		if ( is_wp_error( $save_result ) ) {
			return $save_result;
		}

		// Set taxonomy term for Elementor library type.
		wp_set_object_terms( $template_id, $template_type, 'elementor_library_type' );

		return array(
			'template_id'   => $template_id,
			'title'         => $title,
			'template_type' => $template_type,
			'source_page'   => $page_id,
			'element_id'    => $element_id,
			'edit_url'      => admin_url( "post.php?post={$template_id}&action=elementor" ),
			'next_step'     => 'Use wp_list_elementor_templates to verify, or wp_apply_elementor_template to apply to another page.',
		);
	}

	/**
	 * Recursively find an element by ID in Elementor data.
	 *
	 * @param array  $elements   Elements array.
	 * @param string $element_id Target element ID.
	 * @return array|null Found element or null.
	 */
	private function find_element_by_id( $elements, $element_id ) {
		foreach ( $elements as $element ) {
			if ( isset( $element['id'] ) && $element['id'] === $element_id ) {
				return $element;
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$found = $this->find_element_by_id( $element['elements'], $element_id );
				if ( $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	/**
	 * Generate unique element ID.
	 *
	 * @return string Element ID.
	 */
	private function generate_element_id() {
		return substr( md5( uniqid( wp_rand(), true ) ), 0, 8 );
	}
}
