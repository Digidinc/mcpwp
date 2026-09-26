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

		$parsed               = self::format_conditions( (array) $conditions );
		$formatted_conditions = $parsed['conditions'];
		$warnings             = $parsed['warnings'];

		// ANY unusable condition refuses the whole call.
		//
		// Elementor's save_conditions() REPLACES a template's entire condition
		// set. So dropping one bad condition out of two does not bind one
		// fewer than asked — it replaces every existing binding with the
		// subset that happened to parse, and answers updated:true. The caller
		// asked for two and cannot see from the response that it got one.
		//
		// All-or-nothing is the only shape that matches what the storage
		// actually does. Warnings still say which condition was the problem.
		if ( ! empty( $warnings ) ) {
			return new WP_Error(
				'invalid_conditions',
				__( 'One or more display conditions could not be read, so the template\'s conditions were left unchanged.', 'mcpwp' ),
				array(
					'status'   => 400,
					'warnings' => $warnings,
					'expected' => 'Either {"type":"include","name":"singular","sub_name":"post"} or the string "include/singular/post".',
				)
			);
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

		// Always the same shape, warnings or not. A response that changes
		// structure depending on whether anything went wrong is the same trap
		// in a new place: the caller that only ever sees the happy shape never
		// writes the branch that reads the warnings.
		return array(
			'conditions' => $this->get_template_conditions( $template_id ),
			'warnings'   => $warnings,
		);
	}

	/**
	 * Normalise every accepted condition form into Elementor's stored shape.
	 *
	 * Split out of set_template_conditions() so the dispatch — which form did
	 * this input take? — is reachable without Elementor Pro loaded. The defect
	 * this fixes was in the dispatch, not in any one branch: a string satisfied
	 * a test written to recognise a positional array, so it was never routed to
	 * a parser at all.
	 *
	 * @param array $conditions Raw conditions as supplied by the caller.
	 * @return array{conditions:array,warnings:array}
	 */
	private static function format_conditions( array $conditions ) {
		// Accept object form: {"type":"include","name":"singular","sub_name":"product"},
		// positional array form: ["include", "singular", "product"] (#210),
		// and Elementor's own slash string form: "include/singular/post" (#785).
		$formatted_conditions = array();
		$warnings             = array();

		foreach ( $conditions as $condition ) {
			if ( is_string( $condition ) ) {
				// A string used to fall into the positional branch below,
				// because PHP answers isset($str[0]) with true (string offset)
				// and isset($str['type']) with false. So "include/singular/post"
				// was read one character at a time and stored as
				// {"type":"include","name":"n","sub_name":"c","sub_id":"l"} —
				// returned as updated:true, bound to nothing (#785).
				//
				// This is the format Elementor itself stores and parses, so it
				// is the natural thing for a caller to send.
				$formatted = self::parse_condition_string( $condition );

				if ( null === $formatted ) {
					$warnings[] = sprintf(
						'Ignored condition "%s": expected type/name[/sub_name[/sub_id]], e.g. "include/singular/post".',
						$condition
					);
					continue;
				}

				$formatted_conditions[] = $formatted;
				continue;
			}

			if ( ! is_array( $condition ) ) {
				$warnings[] = sprintf( 'Ignored condition of type %s: expected an object, an array or a "type/name" string.', gettype( $condition ) );
				continue;
			}

			if ( isset( $condition[0] ) && ! isset( $condition['type'] ) ) {
				// Positional array: [type, name, sub_name, sub_id].
				$parts = array(
					'type'     => isset( $condition[0] ) ? $condition[0] : '',
					'name'     => isset( $condition[1] ) ? $condition[1] : '',
					'sub_name' => isset( $condition[2] ) ? $condition[2] : '',
					'sub_id'   => isset( $condition[3] ) ? $condition[3] : '',
				);
			} else {
				// Object form: {type, name, sub_name, sub_id}.
				$parts = array(
					'type'     => isset( $condition['type'] ) ? $condition['type'] : '',
					'name'     => isset( $condition['name'] ) ? $condition['name'] : '',
					'sub_name' => isset( $condition['sub_name'] ) ? $condition['sub_name'] : '',
					'sub_id'   => isset( $condition['sub_id'] ) ? $condition['sub_id'] : '',
				);
			}

			// Both of these branches used to coerce rather than check, and
			// each coercion had a name for what it silently did:
			//
			//   type: anything that was not the literal 'exclude' became
			//   'include'. So {"type":"exlcude",...} — one transposed pair of
			//   letters — INVERTED the caller's intent and reported success.
			//
			//   name: a missing name became 'general', which is Elementor's
			//   ENTIRE SITE condition. save_conditions() replaces the whole
			//   set, so {"type":"include"} detached a header from everything
			//   it matched and rebound it site-wide, reported as updated:true.
			//
			// The string branch above was hardened for #785; leaving these two
			// coercing meant the fix covered one input form of three.
			$formatted = self::validate_condition_parts( $parts );

			if ( null === $formatted ) {
				$warnings[] = sprintf(
					'Ignored condition %s: type must be "include" or "exclude" and name must not be empty.',
					wp_json_encode( $condition )
				);
				continue;
			}

			$formatted_conditions[] = $formatted;
		}

		return array(
			'conditions' => $formatted_conditions,
			'warnings'   => $warnings,
		);
	}

	/**
	 * Check and normalise the four parts of a display condition.
	 *
	 * The single place that decides what a usable condition looks like, so the
	 * string, object and positional forms cannot disagree about it — they did,
	 * and the string form was the only one hardened by the first pass at #785.
	 *
	 * Returns null rather than a repaired guess. A condition nobody can read is
	 * better refused than stored: Elementor's save_conditions() replaces a
	 * template's entire set, so a silently repaired condition does not add a
	 * wrong binding, it REPLACES every right one.
	 *
	 * @param array $parts Raw type/name/sub_name/sub_id.
	 * @return array|null Normalised condition, or null when unusable.
	 */
	private static function validate_condition_parts( array $parts ) {
		$type = is_string( $parts['type'] ) ? trim( $parts['type'] ) : '';
		$name = is_string( $parts['name'] ) || is_numeric( $parts['name'] ) ? trim( (string) $parts['name'] ) : '';

		// An omitted type is the one coercion worth keeping: "include" is what
		// a caller listing conditions means, and there is no second reading of
		// silence. A type that is present but wrong has a second reading, and
		// guessing between them is what inverted intent.
		if ( '' === $type ) {
			$type = 'include';
		}

		if ( ! in_array( $type, array( 'include', 'exclude' ), true ) ) {
			return null;
		}

		if ( '' === $name ) {
			return null;
		}

		return array(
			'type'     => $type,
			'name'     => sanitize_text_field( $name ),
			'sub_name' => sanitize_text_field( (string) $parts['sub_name'] ),
			'sub_id'   => sanitize_text_field( (string) $parts['sub_id'] ),
		);
	}

	/**
	 * Parse Elementor's own slash-delimited condition string.
	 *
	 * `include/singular/post` — the format Elementor stores and parses
	 * internally, and the one a caller reaching for a compact form will write.
	 *
	 * Returns null rather than a partial guess when the string cannot be a
	 * condition. A condition nobody can read is better refused than stored: a
	 * malformed one binds the template to nothing while reporting success,
	 * which is exactly how this was found.
	 *
	 * @param string $condition Condition string.
	 * @return array|null Formatted condition, or null when unparseable.
	 */
	private static function parse_condition_string( $condition ) {
		$condition = trim( (string) $condition );

		if ( '' === $condition ) {
			return null;
		}

		$parts = array_map( 'trim', explode( '/', $condition ) );

		// A bare name is a condition Elementor understands ("general"), so a
		// single segment is allowed — but it must not be the type on its own,
		// which is the most likely way to write half a condition.
		if ( 1 === count( $parts ) ) {
			if ( in_array( $parts[0], array( 'include', 'exclude' ), true ) ) {
				return null;
			}

			$parts = array( 'include', $parts[0] );
		}

		list( $type, $name, $sub_name, $sub_id ) = array_pad( $parts, 4, '' );

		// A string that omits the type is ambiguous in a way an object is not
		// — "singular/post" could as easily be a name and sub_name pair — so
		// unlike the object form this one requires the type explicitly. It is
		// handled above by the single-segment branch and rejected here.
		if ( '' === $type ) {
			return null;
		}

		return self::validate_condition_parts(
			array(
				'type'     => $type,
				'name'     => $name,
				'sub_name' => $sub_name,
				'sub_id'   => $sub_id,
			)
		);
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

		// Create the template post as a DRAFT, not 'publish'. A header/footer
		// Theme Builder template goes live site-wide the instant it is both
		// published AND assigned to a location — so publishing here, before its
		// Elementor data is even attempted, meant a save that failed (a returned
		// WP_Error) or fatalled (a Throwable — #759's unguarded `widgetType`
		// read in Elementor's content-sanitizer, which runs for any non-admin
		// writer) left an empty header or footer live on every page of the site
		// (#828 part 2). Nothing below is allowed to publish until the
		// Elementor data has actually been written; any failure along the way
		// deletes this draft instead of leaving it live.
		$post_data = array(
			'post_title'  => $title,
			'post_status' => 'draft',
			'post_type'   => 'elementor_library',
		);

		$template_id = wp_insert_post( $post_data, true );
		if ( is_wp_error( $template_id ) ) {
			return $template_id;
		}

		$conditions_error = null;

		try {
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
				// Wrapped in the same content-sanitizer suspend used by the main
				// Elementor writer (Mcpwp_Elementor_Sanitizer_Guard, shared with
				// trait-mcpwp-elementor-writer.php — #759): this call carries our
				// own element tree to a non-admin writer's Document::save(), the
				// exact shape that trips Elementor's unguarded `widgetType` read.
				// A restore() in `finally` guarantees the sanitizer is re-hooked
				// whether the save throws or not.
				if ( $elementor_ok ) {
					wp_cache_delete( $template_id, 'post_meta' );
					clean_post_cache( $template_id );
					$document = \Elementor\Plugin::$instance->documents->get( $template_id, false );
					if ( $document && method_exists( $document, 'save' ) ) {
						$suspended_sanitizer = Mcpwp_Elementor_Sanitizer_Guard::suspend();
						try {
							$document->save( array( 'elements' => $elements ) );
						} finally {
							Mcpwp_Elementor_Sanitizer_Guard::restore( $suspended_sanitizer );
						}
					}
				}

				// Always overwrite raw meta — Document::save() may silently fail or
				// write to revision cache instead of post meta (#198).
				update_post_meta( $template_id, '_elementor_data', wp_slash( $json ) );

				// Clear post_content so Elementor owns the rendering.
				$content_cleared = wp_update_post(
					array(
						'ID'           => $template_id,
						'post_content' => '',
					),
					true
				);
				if ( is_wp_error( $content_cleared ) ) {
					throw new RuntimeException( 'clear_post_content_failed: ' . $content_cleared->get_error_message() );
				}

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

			// Publish now — after the Elementor data has been written (or there
			// was none to write) but BEFORE display conditions are assigned.
			// This is the one status transition in this method; every path
			// above either reaches here or throws into the catch block below.
			//
			// Conditions MUST be assigned AFTER this, not before (RC review
			// BLOCK, 2026-09-25): assign_to_location() -> set_template_conditions()
			// calls ElementorPro\Modules\ThemeBuilder\...\Conditions_Manager::
			// save_conditions() then ->get_cache()->regenerate(). regenerate()
			// runs a WP_Query with no post_status, which under a REST request
			// (not is_admin()) only matches PUBLIC statuses — a draft is
			// invisible to it. Elementor Pro also does not regenerate that
			// cache on transition_post_status/save_post (only on
			// wp_trash_post/untrashed_post/section save_settings), so
			// publishing AFTER assigning conditions never corrects a cache
			// entry that regenerate() built without this template in it. The
			// result was a template that reported `assigned:true` and was
			// genuinely published, bound and present in
			// elementor_pro_theme_builder_conditions, yet never rendered
			// anywhere — regenerate()'s own WP_Query silently dropped it, and
			// nothing after ever re-ran it. Publishing first means the query
			// that populates the cache can see this post at all.
			$published = wp_update_post(
				array(
					'ID'          => $template_id,
					'post_status' => 'publish',
				),
				true
			);
			if ( is_wp_error( $published ) ) {
				throw new RuntimeException( 'publish_failed: ' . $published->get_error_message() );
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
			// A conditions failure does NOT roll back the publish above: the
			// Elementor data (the thing #828/#759 could leave broken) is
			// already safely written, and a header/footer template that is
			// published but bound to no location renders nowhere on the site —
			// harmless, and reported via conditions_error below rather than
			// reverted to draft. Reverting it here would also re-open the very
			// ordering bug this block exists to fix, by pulling a status change
			// back after conditions have already been (successfully) written.
			$assign_result = $this->assign_to_location( $template_id, $type, $scope, $assign_options );
			if ( is_wp_error( $assign_result ) ) {
				$conditions_error = $assign_result->get_error_message();
			}
		} catch ( \Throwable $e ) {
			// Reaches here for: a WP_Error from clearing post_content or from
			// the publish transition itself (both surfaced as a throw above,
			// before conditions are ever touched), or a genuine Throwable
			// straight out of Elementor (#759). An ordinary WP_Error from
			// assign_to_location() — invalid scope/conditions — is handled
			// separately above via $conditions_error and never reaches here;
			// only an outright crash in Elementor Pro's own conditions code
			// would. Either way this must not leave a broken template live:
			// force delete, published or still a draft.
			wp_delete_post( $template_id, true );

			// #952: a crash straight out of Elementor Pro's conditions code
			// can land here AFTER assign_to_location() already wrote this
			// template into elementor_pro_theme_builder_conditions — the
			// throw happens later, e.g. in get_cache()->regenerate(). The
			// force delete above must not leave that binding behind.
			$this->cleanup_after_template_delete( $template_id );

			return new WP_Error(
				'theme_template_save_failed',
				sprintf(
					/* translators: %s: underlying error message */
					__( 'Theme template could not be created: %s. Nothing was published.', 'mcpwp' ),
					$e->getMessage()
				)
			);
		}

		$template = $this->get_template( $template_id );
		if ( is_wp_error( $template ) ) {
			return $template;
		}

		if ( null !== $conditions_error ) {
			$template['conditions_error'] = $conditions_error;
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
	 * Whether a template currently appears anywhere in Elementor Pro's real
	 * conditions cache/option.
	 *
	 * `elementor_pro_theme_builder_conditions` is Elementor Pro's own option
	 * (`ElementorPro\Modules\ThemeBuilder\Classes\Conditions_Cache::OPTION_NAME`),
	 * shaped `{ location => { template_id => [condition_strings] } }` —
	 * LOCATION first, then template ID, the opposite axis from
	 * update_global_conditions_index() above. That method's own writes to
	 * this same option are harmless in production only because they are
	 * always immediately followed by a real `regenerate()` call (inside
	 * Elementor's own save_conditions(), and again explicitly below) that
	 * rebuilds it correctly; verified reading Elementor Pro's actual source
	 * (`conditions-cache.php`) on a real install rather than re-deriving the
	 * shape from this file's own (pre-existing, unrelated) assumption.
	 *
	 * @param int $template_id Template ID.
	 * @return bool
	 */
	private function template_is_in_conditions_cache( $template_id ) {
		$all_conditions = get_option( 'elementor_pro_theme_builder_conditions', array() );
		if ( ! is_array( $all_conditions ) ) {
			return false;
		}

		$template_id = absint( $template_id );

		foreach ( $all_conditions as $templates_by_id ) {
			if ( is_array( $templates_by_id ) && array_key_exists( $template_id, $templates_by_id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Drop a location key left with no templates under it after a removal.
	 *
	 * Elementor Pro's own Conditions_Cache::remove() (called via
	 * purge_post_from_cache() below) unsets only the one template ID and
	 * leaves the location key behind as `[]` — real, shipped behaviour,
	 * left alone by every Elementor Pro write path, and only ever cleaned
	 * up incidentally by a later full regenerate() rebuild (which never
	 * adds a key for a location with nothing in it, so it just does not
	 * reappear). #952 asked for that tidiness on every delete, not just the
	 * next unrelated regenerate(), so this does it explicitly, only writing
	 * when a key actually goes away.
	 *
	 * @return void
	 */
	private function drop_empty_condition_locations() {
		$all_conditions = get_option( 'elementor_pro_theme_builder_conditions', array() );
		if ( ! is_array( $all_conditions ) ) {
			return;
		}

		$changed = false;

		foreach ( $all_conditions as $location => $templates_by_id ) {
			if ( is_array( $templates_by_id ) && empty( $templates_by_id ) ) {
				unset( $all_conditions[ $location ] );
				$changed = true;
			}
		}

		if ( $changed ) {
			update_option( 'elementor_pro_theme_builder_conditions', $all_conditions );
		}
	}

	/**
	 * Remove a deleted template's ID from Elementor Pro's real conditions
	 * cache/option (#952) — trash or force delete alike, since Elementor
	 * never does this itself: `wp_delete_post()` does not touch
	 * `elementor_pro_theme_builder_conditions`, and nothing else runs
	 * afterward that would.
	 *
	 * Delegates the actual removal to Elementor Pro's own purpose-built
	 * Conditions_Manager::purge_post_from_cache() rather than re-deriving
	 * the option's shape here a second time: a hand-rolled removal that
	 * disagreed with Elementor's real internal shape (or a future Elementor
	 * Pro release that changes it) would silently do nothing, which is a
	 * worse failure than doing nothing loudly. Falls back to a full
	 * get_cache()->regenerate() rebuild — slower, but always correct,
	 * because it re-queries every condition-bearing template fresh and the
	 * one just deleted is gone — for an Elementor Pro build old enough not
	 * to have the targeted method.
	 *
	 * Callers: Mcpwp_Elementor_Pro::delete_template() (trash and force),
	 * Mcpwp_REST_Content::delete_content() when post_type is
	 * elementor_library, and this class's own create_theme_template()
	 * rollback (assign_to_location() can register a template in the cache
	 * before a later step in that same method throws).
	 *
	 * @param int $template_id Template ID.
	 * @return bool True if the template was actually removed (and the
	 *              option written); false if it was never in the cache, or
	 *              Elementor Pro's Theme Builder is not available.
	 */
	public function cleanup_after_template_delete( $template_id ) {
		if ( ! $this->is_available() || ! $this->template_is_in_conditions_cache( $template_id ) ) {
			return false;
		}

		try {
			$conditions_manager = \ElementorPro\Modules\ThemeBuilder\Module::instance()->get_conditions_manager();

			if ( method_exists( $conditions_manager, 'purge_post_from_cache' ) ) {
				$conditions_manager->purge_post_from_cache( $template_id );
			} else {
				$conditions_manager->get_cache()->regenerate();
			}

			$this->drop_empty_condition_locations();
		} catch ( \Throwable $e ) {
			// Best-effort: a cache that fails to update here self-heals on
			// the next conditions write (set_template_conditions() always
			// calls regenerate()), and a delete must never fail on account
			// of it.
			return false;
		}

		return true;
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
