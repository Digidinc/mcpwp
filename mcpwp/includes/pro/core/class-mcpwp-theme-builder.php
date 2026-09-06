<?php
/**
 * Elementor Theme Builder Handler
 *
 * @package MCPWP_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Theme Builder functionality.
 *
 * Manages Elementor Theme Builder locations and display conditions.
 */
class Mcpwp_Theme_Builder {

	/**
	 * Check if Elementor Pro Theme Builder is available.
	 *
	 * @return bool
	 */
	public function is_available() {
		return defined( 'ELEMENTOR_PRO_VERSION' )
			&& class_exists( '\ElementorPro\Modules\ThemeBuilder\Module' );
	}

	/**
	 * Get Theme Builder status.
	 *
	 * @return array Status info.
	 */
	public function get_status() {
		return array(
			'available'        => $this->is_available(),
			'elementor_pro'    => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : null,
			'locations'        => $this->is_available() ? array_keys( $this->get_locations() ) : array(),
		);
	}

	/**
	 * Get all theme locations.
	 *
	 * @return array Locations with their active templates.
	 */
	public function get_locations() {
		if ( ! $this->is_available() ) {
			return array();
		}

		$locations_manager = \ElementorPro\Modules\ThemeBuilder\Module::instance()->get_locations_manager();
		$locations = $locations_manager->get_locations();

		$result = array();
		foreach ( $locations as $location => $settings ) {
			$result[ $location ] = array(
				'label'           => $settings['label'] ?? $location,
				'multiple'        => $settings['multiple'] ?? false,
				'edit_in_content' => $settings['edit_in_content'] ?? true,
				'active_template' => $this->get_location_template( $location ),
			);
		}

		return $result;
	}

	/**
	 * Get active template for a location.
	 *
	 * @param string $location Location name.
	 * @return array|null Template info or null.
	 */
	public function get_location_template( $location ) {
		if ( ! $this->is_available() ) {
			return null;
		}

		$conditions_manager = \ElementorPro\Modules\ThemeBuilder\Module::instance()->get_conditions_manager();
		$documents = $conditions_manager->get_documents_for_location( $location );

		if ( empty( $documents ) ) {
			return null;
		}

		// Get the first (highest priority) document.
		$document_id = reset( $documents );
		$document = \Elementor\Plugin::instance()->documents->get( $document_id );

		if ( ! $document ) {
			return null;
		}

		return array(
			'id'       => $document_id,
			'title'    => get_the_title( $document_id ),
			'edit_url' => $document->get_edit_url(),
		);
	}

	/**
	 * Get all Theme Builder templates.
	 *
	 * @param array $args Query arguments.
	 * @return array Templates list.
	 */
	public function get_templates( $args = array() ) {
		if ( ! $this->is_available() ) {
			return array();
		}

		$query_args = array(
			'post_type'      => 'elementor_library',
			'posts_per_page' => isset( $args['per_page'] ) ? absint( $args['per_page'] ) : 50,
			'post_status'    => 'publish',
			'meta_query'     => array(
				array(
					'key'     => '_elementor_template_type',
					'value'   => array( 'header', 'footer', 'single', 'single-post', 'single-page', 'archive', 'search-results', 'error-404', 'loop-item' ),
					'compare' => 'IN',
				),
			),
		);

		// Filter by type if specified.
		if ( ! empty( $args['type'] ) ) {
			$query_args['meta_query'] = array(
				array(
					'key'   => '_elementor_template_type',
					'value' => sanitize_text_field( $args['type'] ),
				),
			);
		}

		$templates = get_posts( $query_args );
		$result = array();

		foreach ( $templates as $template ) {
			$result[] = $this->format_template( $template );
		}

		return $result;
	}

	/**
	 * Get single template with conditions.
	 *
	 * @param int $template_id Template ID.
	 * @return array|WP_Error Template data.
	 */
	public function get_template( $template_id ) {
		if ( ! $this->is_available() ) {
			return new WP_Error( 'not_available', __( 'Elementor Pro Theme Builder is not available.', 'mcpwp' ) );
		}

		$template = get_post( $template_id );

		if ( ! $template || 'elementor_library' !== $template->post_type ) {
			return new WP_Error( 'not_found', __( 'Template not found.', 'mcpwp' ) );
		}

		$data = $this->format_template( $template );
		$data['conditions'] = $this->get_template_conditions( $template_id );

		return $data;
	}

	/**
	 * Format template for response.
	 *
	 * @param WP_Post $template Template post.
	 * @return array Formatted template.
	 */
	private function format_template( $template ) {
		$type = get_post_meta( $template->ID, '_elementor_template_type', true );
		$document = \Elementor\Plugin::instance()->documents->get( $template->ID );

		return array(
			'id'       => $template->ID,
			'title'    => $template->post_title,
			'type'     => $type,
			'location' => $this->get_location_for_type( $type ),
			'status'   => $template->post_status,
			'created'  => $template->post_date,
			'modified' => $template->post_modified,
			'edit_url' => $document ? $document->get_edit_url() : admin_url( 'post.php?post=' . $template->ID . '&action=elementor' ),
		);
	}

	/**
	 * Get location name for template type.
	 *
	 * @param string $type Template type.
	 * @return string Location name.
	 */
	private function get_location_for_type( $type ) {
		$map = array(
			'header'         => 'header',
			'footer'         => 'footer',
			'single'         => 'single',
			'single-post'    => 'single',
			'single-page'    => 'single',
			'archive'        => 'archive',
			'search-results' => 'archive',
			'error-404'      => 'single',
			'loop-item'      => 'loop-item',
		);

		return isset( $map[ $type ] ) ? $map[ $type ] : $type;
	}

	/**
	 * Get conditions for a template.
	 *
	 * @param int $template_id Template ID.
	 * @return array Conditions.
	 */
	public function get_template_conditions( $template_id ) {
		if ( ! $this->is_available() ) {
			return array();
		}

		$conditions_manager = \ElementorPro\Modules\ThemeBuilder\Module::instance()->get_conditions_manager();
		$document = \Elementor\Plugin::instance()->documents->get( $template_id );

		if ( ! $document ) {
			return array();
		}

		$conditions = $conditions_manager->get_document_conditions( $document );

		// Format conditions for API response.
		$formatted = array();
		foreach ( $conditions as $condition ) {
			$formatted[] = array(
				'type'        => $condition['type'] ?? 'include',
				'name'        => $condition['name'] ?? '',
				'sub_name'    => $condition['sub_name'] ?? '',
				'sub_id'      => $condition['sub_id'] ?? '',
			);
		}

		return $formatted;
	}

	/**
	 * Set conditions for a template.
	 *
	 * @param int   $template_id Template ID.
	 * @param array $conditions  Conditions to set.
	 * @return array|WP_Error Updated conditions or error.
	 */
	public function set_template_conditions( $template_id, $conditions ) {
		if ( ! $this->is_available() ) {
			return new WP_Error( 'not_available', __( 'Elementor Pro Theme Builder is not available.', 'mcpwp' ) );
		}

		$document = \Elementor\Plugin::instance()->documents->get( $template_id );

		if ( ! $document ) {
			return new WP_Error( 'not_found', __( 'Template not found.', 'mcpwp' ) );
		}

		// Validate and format conditions.
		// Accept both object form: {"type":"include","name":"singular","sub_name":"product"}
		// and positional array form: ["include", "singular", "product"] (#210).
		$formatted_conditions = array();
		foreach ( $conditions as $condition ) {
			if ( isset( $condition[0] ) && ! isset( $condition['type'] ) ) {
				// Positional array: [type, name, sub_name, sub_id].
				$formatted = array(
					'type'     => ( isset( $condition[0] ) && 'exclude' === $condition[0] ) ? 'exclude' : 'include',
					'name'     => isset( $condition[1] ) ? sanitize_text_field( $condition[1] ) : 'general',
					'sub_name' => isset( $condition[2] ) ? sanitize_text_field( $condition[2] ) : '',
					'sub_id'   => isset( $condition[3] ) ? sanitize_text_field( $condition[3] ) : '',
				);
			} else {
				// Object form: {type, name, sub_name, sub_id}.
				$formatted = array(
					'type'     => isset( $condition['type'] ) && 'exclude' === $condition['type'] ? 'exclude' : 'include',
					'name'     => isset( $condition['name'] ) ? sanitize_text_field( $condition['name'] ) : 'general',
					'sub_name' => isset( $condition['sub_name'] ) ? sanitize_text_field( $condition['sub_name'] ) : '',
					'sub_id'   => isset( $condition['sub_id'] ) ? sanitize_text_field( $condition['sub_id'] ) : '',
				);
			}
			$formatted_conditions[] = $formatted;
		}

		// Save conditions via Elementor's conditions manager.
		$conditions_manager = \ElementorPro\Modules\ThemeBuilder\Module::instance()->get_conditions_manager();
		$conditions_manager->save_conditions( $template_id, $formatted_conditions );

		// Ensure the global conditions index is updated.
		// Elementor Pro reads elementor_pro_theme_builder_conditions at runtime
		// to decide which templates to load. The per-post meta alone is not enough.
		$this->update_global_conditions_index( $template_id, $formatted_conditions );

		// Clear cache.
		$conditions_manager->get_cache()->regenerate();

		return $this->get_template_conditions( $template_id );
	}

	/**
	 * Assign template to location (shortcut for common conditions).
	 *
	 * @param int    $template_id Template ID.
	 * @param string $location    Location (header, footer, single, archive).
	 * @param string $scope       Scope (entire_site, singular, archive, specific).
	 * @param array  $options     Additional options (post_type, post_ids, etc.).
	 * @return array|WP_Error Result.
	 */
	public function assign_to_location( $template_id, $location, $scope = 'entire_site', $options = array() ) {
		$conditions = array();

		// Normalize scope aliases to canonical names.
		$scope_aliases = array(
			'all_singular'       => 'singular',
			'specific_post_type' => 'singular',
			'all_archive'        => 'archive',
			'specific_posts'     => 'specific',
		);
		if ( isset( $scope_aliases[ $scope ] ) ) {
			$scope = $scope_aliases[ $scope ];
		}

		switch ( $scope ) {
			case 'entire_site':
				$conditions[] = array(
					'type' => 'include',
					'name' => 'general',
				);
				break;

			case 'singular':
				$post_type = isset( $options['post_type'] ) ? $options['post_type'] : 'post';
				$conditions[] = array(
					'type'     => 'include',
					'name'     => 'singular',
					'sub_name' => $post_type,
				);
				break;

			case 'archive':
				$archive_type = isset( $options['archive_type'] ) ? $options['archive_type'] : '';
				$conditions[] = array(
					'type'     => 'include',
					'name'     => 'archive',
					'sub_name' => $archive_type,
				);
				break;

			case 'specific':
				// Specific posts/pages by ID.
				$post_ids = isset( $options['post_ids'] ) ? (array) $options['post_ids'] : array();
				foreach ( $post_ids as $post_id ) {
					$post = get_post( $post_id );
					if ( $post ) {
						$conditions[] = array(
							'type'     => 'include',
							'name'     => 'singular',
							'sub_name' => $post->post_type,
							'sub_id'   => (string) $post_id,
						);
					}
				}
				break;

			case 'front_page':
				$conditions[] = array(
					'type'     => 'include',
					'name'     => 'singular',
					'sub_name' => 'front_page',
				);
				break;

			case '404':
				$conditions[] = array(
					'type'     => 'include',
					'name'     => 'singular',
					'sub_name' => 'not_found404',
				);
				break;
		}

		if ( empty( $conditions ) ) {
			$valid_scopes = array( 'entire_site', 'singular', 'specific_post_type', 'archive', 'all_archive', 'specific', 'specific_posts', 'front_page', '404' );
			return new WP_Error(
				'invalid_scope',
				sprintf(
					/* translators: 1: provided scope 2: valid scopes */
					__( 'Invalid scope "%1$s". Valid scopes: %2$s', 'mcpwp' ),
					$scope,
					implode( ', ', $valid_scopes )
				)
			);
		}

		return $this->set_template_conditions( $template_id, $conditions );
	}

	/**
	 * Remove template from all locations.
	 *
	 * @param int $template_id Template ID.
	 * @return bool|WP_Error True on success.
	 */
	public function remove_from_locations( $template_id ) {
		return $this->set_template_conditions( $template_id, array() );
	}

	/**
	 * Create a Theme Builder template and assign it to a location in one step.
	 *
	 * @param array $data {
	 *     @type string $title          Template title (required).
	 *     @type string $type           Template type: header, footer, single, archive (required).
	 *     @type array  $elementor_data Elementor JSON data (optional).
	 *     @type string $scope          Display scope: entire_site, singular, archive, front_page, 404 (default: entire_site).
	 * }
	 * @return array|WP_Error Created template with conditions.
	 */
	public function create_theme_template( $data ) {
		if ( ! $this->is_available() ) {
			return new WP_Error( 'not_available', __( 'Elementor Pro Theme Builder is not available.', 'mcpwp' ) );
		}

		$dry_run = ! empty( $data['dry_run'] );
		$title   = ! empty( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '';
		$type    = ! empty( $data['type'] ) ? sanitize_text_field( $data['type'] ) : '';
		$scope   = ! empty( $data['scope'] ) ? sanitize_text_field( $data['scope'] ) : 'entire_site';

		if ( empty( $title ) ) {
			return new WP_Error( 'missing_title', __( 'Template title is required.', 'mcpwp' ) );
		}

		$valid_types = array( 'header', 'footer', 'single', 'archive' );
			if ( ! in_array( $type, $valid_types, true ) ) {
				return new WP_Error( 'invalid_type', sprintf(
					/* translators: %s: comma-separated list of valid template types */
					__( 'Invalid template type. Must be one of: %s', 'mcpwp' ),
					implode( ', ', $valid_types )
				) );
		}

		// Dry run: validate params without creating anything.
		if ( $dry_run ) {
			$result = array(
				'dry_run'  => true,
				'valid'    => true,
				'title'    => $title,
				'type'     => $type,
				'scope'    => $scope,
				'message'  => __( 'Validation passed — no template created.', 'mcpwp' ),
			);
			if ( ! empty( $data['elementor_data'] ) ) {
				$elements = $data['elementor_data'];
				if ( is_string( $elements ) ) {
					$elements = json_decode( $elements, true );
				}
				if ( ! is_array( $elements ) || empty( $elements ) ) {
					$result['valid']  = false;
					$result['errors'] = array( 'elementor_data must be a non-empty JSON array' );
				} else {
					$result['element_count'] = count( $elements );
				}
			}
			return $result;
		}

		// Create the template post.
		$post_data = array(
			'post_title'  => $title,
			'post_status' => 'publish',
			'post_type'   => 'elementor_library',
		);

		$template_id = wp_insert_post( $post_data );
		if ( is_wp_error( $template_id ) ) {
			return $template_id;
		}

		// Set all required Elementor meta for Theme Builder templates to render.
		update_post_meta( $template_id, '_elementor_template_type', $type );
		update_post_meta( $template_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $template_id, '_elementor_version', defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '' );
		update_post_meta( $template_id, '_wp_page_template', 'elementor_header_footer' );

		// Set Elementor data if provided — use document_save_with_meta_overwrite pattern.
		if ( ! empty( $data['elementor_data'] ) ) {
			$elements = $data['elementor_data'];
			if ( is_string( $elements ) ) {
				$elements = json_decode( $elements, true );
			}
			if ( ! is_array( $elements ) ) {
				$elements = array();
			}

			// Normalised before both the Document::save() below and the raw meta
			// write, so Elementor renders the same tree we store. This path
			// never reaches validate_and_fix_elements(), so a theme template
			// carrying `css_id` kept an id Elementor never renders (#737).
			if ( class_exists( 'Mcpwp_Elementor_Element_Keys' ) ) {
				$elements = Mcpwp_Elementor_Element_Keys::normalize_tree( $elements );
			}

			$json         = wp_json_encode( $elements );
			$elementor_ok = class_exists( '\Elementor\Plugin' ) && ! empty( \Elementor\Plugin::$instance->documents );

			// Try Document::save() first for proper Elementor initialization.
			if ( $elementor_ok ) {
				wp_cache_delete( $template_id, 'post_meta' );
				clean_post_cache( $template_id );
				$document = \Elementor\Plugin::$instance->documents->get( $template_id, false );
				if ( $document && method_exists( $document, 'save' ) ) {
					$document->save( array( 'elements' => $elements ) );
				}
			}

			// Always overwrite raw meta — Document::save() may silently fail or
			// write to revision cache instead of post meta (#198).
			update_post_meta( $template_id, '_elementor_data', wp_slash( $json ) );

			// Clear post_content so Elementor owns the rendering.
			wp_update_post( array(
				'ID'           => $template_id,
				'post_content' => '',
			) );

			// Flush caches so Elementor reads the new data.
			wp_cache_delete( $template_id, 'post_meta' );
			clean_post_cache( $template_id );
			if ( $elementor_ok ) {
				\Elementor\Plugin::$instance->documents->get( $template_id, false );
				if ( function_exists( 'wp_cache_flush_group' ) ) {
					wp_cache_flush_group( 'post_meta' );
				}
			}

			// Regenerate CSS.
			delete_post_meta( $template_id, '_elementor_css' );
			if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
				$css_file = \Elementor\Core\Files\CSS\Post::create( $template_id );
				$css_file->update();
			}
		}

		// Build options for assign_to_location.
		$assign_options = array();
		if ( ! empty( $data['post_type'] ) ) {
			$assign_options['post_type'] = sanitize_text_field( $data['post_type'] );
		}
		if ( ! empty( $data['post_ids'] ) ) {
			$assign_options['post_ids'] = array_map( 'absint', (array) $data['post_ids'] );
		}

		// Assign to location. The type maps to the Elementor location name.
		$result = $this->assign_to_location( $template_id, $type, $scope, $assign_options );
		if ( is_wp_error( $result ) ) {
			// Template was created but conditions failed — still return the template.
			$template = $this->get_template( $template_id );
			if ( is_wp_error( $template ) ) {
				return $template;
			}
			$template['conditions_error'] = $result->get_error_message();
			return $template;
		}

		$template = $this->get_template( $template_id );
		if ( is_wp_error( $template ) ) {
			return $template;
		}

		$template['assigned'] = true;
		return $template;
	}

	/**
	 * Update the global theme builder conditions index.
	 *
	 * Elementor Pro maintains a site-wide option `elementor_pro_theme_builder_conditions`
	 * that maps condition strings to template IDs. The frontend reads this option at
	 * runtime to decide which templates to load for each location. Without this,
	 * templates have per-post meta but are invisible to the rendering engine.
	 *
	 * @param int   $template_id Template ID.
	 * @param array $conditions  Formatted conditions array.
	 */
	private function update_global_conditions_index( $template_id, $conditions ) {
		$all_conditions = get_option( 'elementor_pro_theme_builder_conditions', array() );
		if ( ! is_array( $all_conditions ) ) {
			$all_conditions = array();
		}

		// Remove this template from all existing condition keys.
		foreach ( $all_conditions as $key => $template_ids ) {
			if ( is_array( $template_ids ) ) {
				$all_conditions[ $key ] = array_values( array_filter(
					$template_ids,
					function ( $id ) use ( $template_id ) {
						return absint( $id ) !== absint( $template_id );
					}
				) );
				if ( empty( $all_conditions[ $key ] ) ) {
					unset( $all_conditions[ $key ] );
				}
			}
		}

		// Add this template under each new condition key.
		foreach ( $conditions as $condition ) {
			$type     = isset( $condition['type'] ) ? $condition['type'] : 'include';
			$name     = isset( $condition['name'] ) ? $condition['name'] : 'general';
			$sub_name = isset( $condition['sub_name'] ) ? $condition['sub_name'] : '';
			$sub_id   = isset( $condition['sub_id'] ) ? $condition['sub_id'] : '';

			// Build the condition key in Elementor's format: "type/name[/sub_name[/sub_id]]"
			$key = $type . '/' . $name;
			if ( ! empty( $sub_name ) ) {
				$key .= '/' . $sub_name;
				if ( ! empty( $sub_id ) ) {
					$key .= '/' . $sub_id;
				}
			}

			if ( ! isset( $all_conditions[ $key ] ) ) {
				$all_conditions[ $key ] = array();
			}

			if ( ! in_array( absint( $template_id ), array_map( 'absint', $all_conditions[ $key ] ), true ) ) {
				$all_conditions[ $key ][] = absint( $template_id );
			}
		}

		update_option( 'elementor_pro_theme_builder_conditions', $all_conditions );
	}

	/**
	 * Get available condition options.
	 *
	 * @return array Available conditions.
	 */
	public function get_available_conditions() {
		return array(
			'scopes' => array(
				'entire_site' => __( 'Entire Site', 'mcpwp' ),
				'singular'    => __( 'Singular', 'mcpwp' ),
				'archive'     => __( 'Archive', 'mcpwp' ),
				'specific'    => __( 'Specific Pages/Posts', 'mcpwp' ),
				'front_page'  => __( 'Front Page', 'mcpwp' ),
				'404'         => __( '404 Page', 'mcpwp' ),
			),
			'post_types' => get_post_types( array( 'public' => true ), 'objects' ),
			'taxonomies' => get_taxonomies( array( 'public' => true ), 'objects' ),
		);
	}
}
