<?php
/**
 * Elementor engine — writer.
 *
 * Carved verbatim from Mcpwp_Elementor_Basic (G4 split). Mixed back via trait — same class, same $this.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Mcpwp_Elementor_Writer_Trait {

	/**
	 * Set Elementor data for a page.
	 *
	 * @param int   $page_id  Page ID.
	 * @param array $data     Elementor data.
	 * @param bool  $dry_run  If true, validate only — no DB writes, no cache clear.
	 * @return array|WP_Error Result or error.
	 */
	public function set_elementor_data( $page_id, $data, $dry_run = false ) {
		if ( ! $this->is_active() ) {
			return new WP_Error(
				'elementor_not_active',
				__( 'Elementor is not installed or active.', 'mumcp' ),
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

		// Determine layout mode for hints.
		$layout_hint = '';
		if ( class_exists( 'Mcpwp_Error_Hints' ) ) {
			$experiment   = get_option( 'elementor_experiment-container', '' );
			$is_flexbox   = in_array( $experiment, array( 'active', 'default' ), true );
			$layout_hint  = $is_flexbox
				? 'This site uses flexbox layout mode. Use "container" as the top-level elType.'
				: 'This site uses classic layout mode. Use "section" > "column" > widget structure.';
		}

		$structure_hint = 'Elementor data must be a JSON array of element objects. Each element needs: id (8-char alphanumeric), elType ("section"/"column"/"widget" or "container"), settings (object), elements (array). ' . $layout_hint . ' Use wp_get_elementor on an existing page to see the expected format.';

		// Validate and encode data
		$elementor_json = null;

		// --- Base64-encoded payload (bypass all quoting/escaping issues) ---
		if ( ! empty( $data['elementor_data_base64'] ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			$raw_decoded = base64_decode( $data['elementor_data_base64'], true );
			if ( false === $raw_decoded ) {
				return new WP_Error(
					'invalid_base64',
					__( 'Invalid base64 encoding in elementor_data_base64.', 'mumcp' ),
					array(
						'status' => 400,
						'hint'   => 'The elementor_data_base64 value is not valid base64. Encode your JSON string with base64_encode() or btoa() before sending.',
					)
				);
			}
			$decoded = json_decode( $raw_decoded, true );
			if ( null === $decoded && json_last_error() !== JSON_ERROR_NONE ) {
				return new WP_Error(
					'invalid_json',
					__( 'Base64-decoded data is not valid JSON.', 'mumcp' ),
					array(
						'status'          => 400,
						'decoded_length'  => strlen( $raw_decoded ),
						'hint'            => 'Decoded ' . strlen( $raw_decoded ) . ' bytes but JSON parse failed: ' . json_last_error_msg() . '. If the base64 string was LLM-generated, it may have been truncated mid-stream. Try wp_set_elementor with elementor_data (plain JSON) for payloads under 100KB, or split into sections with wp_add_section.',
						'guide'           => 'wp_get_guide(topic=\'elementor\')',
					)
				);
			}
			if ( ! is_array( $decoded ) ) {
				return new WP_Error(
					'invalid_structure',
					__( 'Elementor data must decode to an array.', 'mumcp' ),
					array(
						'status' => 400,
						'hint'   => $structure_hint,
						'guide'  => 'wp_get_guide(topic=\'elementor\')',
					)
				);
			}
			$elementor_json = $raw_decoded;
		} elseif ( isset( $data['elementor_data'] ) ) {
			// If array, validate structure and encode to JSON
			if ( is_array( $data['elementor_data'] ) ) {
				if ( ! $this->is_valid_elementor_structure( $data['elementor_data'] ) ) {
					return new WP_Error(
						'invalid_structure',
						__( 'Elementor data must be an array of element objects.', 'mumcp' ),
						array(
							'status' => 400,
							'hint'   => $structure_hint,
							'guide'  => 'wp_get_guide(topic=\'elementor\')',
						)
					);
				}
				$elementor_json = wp_json_encode( $data['elementor_data'] );
			} else {
				// Validate JSON string — with recovery for common MCP corruption.
				$decoded = $this->try_json_decode( $data['elementor_data'] );
				if ( null === $decoded ) {
					return new WP_Error(
						'invalid_json',
						__( 'Invalid Elementor JSON data.', 'mumcp' ),
						array(
							'status' => 400,
							'hint'   => 'The provided string is not valid JSON even after recovery attempts (stripslashes, double-encoding unwrap). Error: ' . json_last_error_msg() . '. Consider using elementor_data_base64 to avoid quoting issues — base64-encode your JSON before sending.',
							'guide'  => 'wp_get_guide(topic=\'elementor\')',
						)
					);
				}
				if ( ! is_array( $decoded ) ) {
					return new WP_Error(
						'invalid_structure',
						__( 'Elementor data must decode to an array.', 'mumcp' ),
						array(
							'status' => 400,
							'hint'   => $structure_hint,
							'guide'  => 'wp_get_guide(topic=\'elementor\')',
						)
					);
				}
				// Re-encode from decoded data to ensure clean JSON.
				$elementor_json = wp_json_encode( $decoded );
			}
		} elseif ( isset( $data['elementor_json'] ) ) {
			// Direct JSON string — with recovery.
			$decoded = $this->try_json_decode( $data['elementor_json'] );
			if ( null === $decoded ) {
				return new WP_Error(
					'invalid_json',
					__( 'Invalid Elementor JSON data.', 'mumcp' ),
					array(
						'status' => 400,
						'hint'   => 'The provided string is not valid JSON even after recovery attempts. Error: ' . json_last_error_msg() . '. Consider using elementor_data_base64 to avoid quoting issues.',
						'guide'  => 'wp_get_guide(topic=\'elementor\')',
					)
				);
			}
			if ( ! is_array( $decoded ) ) {
				return new WP_Error(
					'invalid_structure',
					__( 'Elementor data must decode to an array.', 'mumcp' ),
					array(
						'status' => 400,
						'hint'   => $structure_hint,
						'guide'  => 'wp_get_guide(topic=\'elementor\')',
					)
				);
			}
			// Re-encode from decoded data to ensure clean JSON.
			$elementor_json = wp_json_encode( $decoded );
		}

		// Validate JSON size and nesting depth (prevent DoS).
		if ( ! empty( $elementor_json ) && class_exists( 'Mcpwp_Security' ) ) {
			$size_check = Mcpwp_Security::validate_json_payload( $elementor_json, 5 * 1024 * 1024, 30 );
			if ( is_wp_error( $size_check ) ) {
				return $size_check;
			}
		}

		if ( empty( $elementor_json ) ) {
			return new WP_Error(
				'no_data',
				__( 'No Elementor data provided.', 'mumcp' ),
				array( 'status' => 400 )
			);
		}

		// --- Dry-run mode: validate only, no DB writes ---
		if ( $dry_run ) {
			$dry_json   = $elementor_json; // work on a copy
			$validation = $this->validate_and_fix_elements( $dry_json );

			if ( ! empty( $validation['errors'] ) ) {
				return new WP_Error(
					'invalid_elementor_structure',
					implode( ' | ', $validation['errors'] ),
					array(
						'status'            => 400,
						'validation_errors' => $validation['errors'],
					)
				);
			}

			$all_warnings = array_merge(
				$validation['warnings'],
				$validation['fixes']
			);

			// Check RTL issues if site is RTL.
			if ( function_exists( 'is_rtl' ) && is_rtl() ) {
				$rtl_warnings = $this->check_rtl_issues( $dry_json );
				$all_warnings = array_merge( $all_warnings, $rtl_warnings );
			}

			$result = array(
				'success'  => true,
				'dry_run'  => true,
				'page_id'  => (int) $page_id,
				'message'  => __( 'Validation complete — no changes saved.', 'mumcp' ),
			);

			if ( ! empty( $all_warnings ) ) {
				$result['warnings'] = $all_warnings;
			}

			$result['debug'] = array(
				'validation_fixes'    => $validation['fixes'],
				'validation_warnings' => $validation['warnings'],
			);

			return $result;
		}

		// Capture current structure for before/after diff.
		$existing_raw      = get_post_meta( $page_id, '_elementor_data', true );
		$existing_decoded  = $existing_raw ? json_decode( $existing_raw, true ) : null;
		$sections_before   = is_array( $existing_decoded ) ? count( $existing_decoded ) : 0;
		$widgets_before    = is_array( $existing_decoded ) ? $this->count_elementor_widgets( $existing_decoded ) : 0;

		$save_debug    = array();
		$elementor_ok  = class_exists( '\Elementor\Plugin' );

		// --- 1. Set ALL required Elementor meta keys ---

		// Page template.
		$current_template = get_post_meta( $page_id, '_wp_page_template', true );
		if ( ! $current_template || 'default' === $current_template ) {
			update_post_meta( $page_id, '_wp_page_template', 'elementor_header_footer' );
		}

		// Edit mode must be 'builder'.
		update_post_meta( $page_id, '_elementor_edit_mode', 'builder' );

		// Template type — required for frontend rendering.
		$template_type = get_post_meta( $page_id, '_elementor_template_type', true );
		if ( empty( $template_type ) ) {
			$post_type = get_post_type( $page_id );
			$type_value = ( 'elementor_library' === $post_type ) ? 'section' : 'wp-page';
			update_post_meta( $page_id, '_elementor_template_type', $type_value );
			$save_debug['set_template_type'] = $type_value;
		}

		// Elementor version — prevents unnecessary migrations.
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			update_post_meta( $page_id, '_elementor_version', ELEMENTOR_VERSION );
			$save_debug['elementor_version'] = ELEMENTOR_VERSION;
		}

		// Pro version — required for Pro widget rendering.
		if ( defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			update_post_meta( $page_id, '_elementor_pro_version', ELEMENTOR_PRO_VERSION );
			$save_debug['elementor_pro_version'] = ELEMENTOR_PRO_VERSION;
		}

		// --- 2. Validate and fix element tree BEFORE writing ---

		$input_count = count( json_decode( $elementor_json, true ) ?: array() );
		$save_debug['sections_submitted'] = $input_count;

		$validation = $this->validate_and_fix_elements( $elementor_json );
		if ( ! empty( $validation['errors'] ) ) {
			return new WP_Error(
				'invalid_elementor_structure',
				implode( ' | ', $validation['errors'] ),
				array(
					'status'            => 400,
					'validation_errors' => $validation['errors'],
				)
			);
		}
		if ( ! empty( $validation['fixes'] ) || ! empty( $validation['warnings'] ) ) {
			$save_debug['validation_fixes']    = $validation['fixes'];
			$save_debug['validation_warnings'] = $validation['warnings'];
		}

		// Safety check: ensure validation did not corrupt or truncate the data.
		$post_validation_decoded = json_decode( $elementor_json, true );
		if ( ! is_array( $post_validation_decoded ) ) {
			return new WP_Error(
				'validation_corrupted',
				__( 'Elementor data was corrupted during validation. Please retry with elementor_data_base64.', 'mumcp' ),
				array( 'status' => 500, 'sections_submitted' => $input_count )
			);
		}
		$post_validation_count = count( $post_validation_decoded );
		if ( $post_validation_count !== $input_count ) {
				return new WP_Error(
					'validation_data_loss',
					sprintf(
						/* translators: 1: submitted section count, 2: section count after validation */
						__( 'Validation changed section count from %1$d to %2$d. Aborting to prevent data loss.', 'mumcp' ),
						$input_count,
						$post_validation_count
					),
				array( 'status' => 500, 'sections_submitted' => $input_count, 'sections_after_validation' => $post_validation_count )
			);
		}

		// --- 3. Save via Elementor Document API (preferred) or meta fallback ---

		$save_method   = 'meta_direct';
		$document_saved = false;

		if ( $elementor_ok ) {
			// Try Document::save() — this updates _elementor_data, post_content, and CSS in one go.
			$documents_manager = \Elementor\Plugin::$instance->documents;
			if ( $documents_manager && method_exists( $documents_manager, 'get' ) ) {
				wp_cache_delete( $page_id, 'post_meta' );
				clean_post_cache( $page_id );

				$document = $documents_manager->get( $page_id, false );
				if ( $document && method_exists( $document, 'save' ) ) {
					$save_result = $document->save( array( 'elements' => $post_validation_decoded ) );

					if ( ! is_wp_error( $save_result ) ) {
						// (#198) Always overwrite raw meta after Document::save() to
						// prevent revision/cache divergence on subsequent reads.
						update_post_meta( $page_id, '_elementor_data', wp_slash( $elementor_json ) );

						wp_cache_delete( $page_id, 'post_meta' );
						clean_post_cache( $page_id );
						if ( function_exists( 'wp_cache_flush_group' ) ) {
							wp_cache_flush_group( 'post_meta' );
						}

						$stored_after_document = get_post_meta( $page_id, '_elementor_data', true );
						$decoded_after_document = json_decode( $stored_after_document, true );
						$stored_after_count     = is_array( $decoded_after_document ) ? count( $decoded_after_document ) : 0;

						if ( $stored_after_count === $input_count ) {
							$document_saved              = true;
							$save_method                 = 'document_save_with_meta_overwrite';
							$save_debug['document_save'] = true;
						} else {
							$save_debug['document_save_persist_mismatch'] = array(
								'sections_expected' => $input_count,
								'sections_saved'    => $stored_after_count,
							);
						}
					} else {
						$save_debug['document_save_error'] = $save_result->get_error_message();
					}
				}
			}
		}

		// Fallback: direct meta write if Document::save() is unavailable or failed.
		if ( ! $document_saved ) {
			update_post_meta( $page_id, '_elementor_data', wp_slash( $elementor_json ) );
			$save_debug['meta_written'] = true;

			// (#187) Update post_content so Elementor's front-end renderer doesn't
			// short-circuit on stale/empty post_content from a previous save attempt.
			wp_update_post( array(
				'ID'           => $page_id,
				'post_content' => '',
			) );

			// (#187) Flush Elementor's in-memory document cache. Document::save() may
			// have cached an empty elements array; force a fresh load from the DB so
			// subsequent CSS regeneration and any same-request renders see the new data.
			if ( $elementor_ok && ! empty( \Elementor\Plugin::$instance->documents ) ) {
				clean_post_cache( $page_id );
				wp_cache_delete( $page_id, 'post_meta' );
				\Elementor\Plugin::$instance->documents->get( $page_id, false );
				$save_debug['document_cache_flushed'] = true;
			}
		}

		// Verify data was stored correctly.
		wp_cache_delete( $page_id, 'post_meta' );
		$stored = get_post_meta( $page_id, '_elementor_data', true );
		$stored_decoded = json_decode( $stored, true );
		$stored_count   = is_array( $stored_decoded ) ? count( $stored_decoded ) : 0;
		$save_debug['meta_verified']    = ( $stored_count === $input_count );
		$save_debug['sections_saved']   = $stored_count;

		if ( $stored_count !== $input_count ) {
				return new WP_Error(
					'meta_write_truncated',
					sprintf(
						/* translators: 1: submitted section count, 2: stored section count */
						__( 'Data truncated during save: %1$d sections submitted but only %2$d stored. Try using elementor_data_base64 for large payloads.', 'mumcp' ),
						$input_count,
						$stored_count
					),
				array(
					'status'              => 500,
					'sections_submitted'  => $input_count,
					'sections_saved'      => $stored_count,
					'hint'                => 'Base64-encode your JSON and send via elementor_data_base64 parameter to bypass size limits.',
				)
			);
		}

		// --- 4. Rebuild CSS + HTML rendering cache ---
		// Always regenerate — meta overwrite may leave post_content (HTML cache) stale.

		if ( $elementor_ok ) {
			if ( ! empty( \Elementor\Plugin::$instance->files_manager ) ) {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
				$save_debug['cache_cleared'] = true;
			}

			delete_post_meta( $page_id, '_elementor_css' );

			// Force fresh document re-save to rebuild post_content (HTML rendering).
			if ( ! empty( \Elementor\Plugin::$instance->documents ) ) {
				$fresh_doc = \Elementor\Plugin::$instance->documents->get( $page_id, false );
				if ( $fresh_doc && method_exists( $fresh_doc, 'save' ) ) {
					$fresh_elements = json_decode( get_post_meta( $page_id, '_elementor_data', true ), true );
					if ( is_array( $fresh_elements ) && ! empty( $fresh_elements ) ) {
						$fresh_doc->save( array( 'elements' => $fresh_elements ) );
						$save_debug['html_cache_rebuilt'] = true;
					}
				}
			}

			if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
				$css_file = \Elementor\Core\Files\CSS\Post::create( $page_id );
				$css_file->update();
				$save_debug['css_regenerated'] = true;
			}
		}

		// Report CSS file size for debugging.
		if ( $elementor_ok ) {
			$upload_dir = wp_upload_dir();
			$css_path   = $upload_dir['basedir'] . '/elementor/css/post-' . $page_id . '.css';
			$css_size   = file_exists( $css_path ) ? filesize( $css_path ) : 0;
			$save_debug['css_file_size'] = $css_size;

			// If CSS is still empty/tiny, prime it via loopback request.
			if ( $css_size < 10 ) {
				delete_post_meta( $page_id, '_elementor_css' );
				$save_debug['css_deferred'] = true;
				$permalink = get_permalink( $page_id );
				if ( $permalink ) {
					wp_remote_get(
						add_query_arg( 'mcpwp_prime_css', wp_rand(), $permalink ),
						array(
							'timeout'   => 15,
							'sslverify' => ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
							'blocking'  => false,
						)
					);
					$save_debug['css_primed'] = true;
				}
			}

			// Also regenerate the global CSS (kit styles) if applicable.
			if ( ! $document_saved && class_exists( '\Elementor\Core\Files\CSS\Global_CSS' ) ) {
				$global_css = \Elementor\Core\Files\CSS\Global_CSS::create( 'global.css' );
				if ( $global_css ) {
					$global_css->update();
					$save_debug['global_css_regenerated'] = true;
				}
			}
		}

		// --- 5. Page-level settings (custom CSS, etc.) (#81) ---

		if ( ! empty( $data['page_settings'] ) && is_array( $data['page_settings'] ) ) {
			$allowed_keys = array( 'custom_css', 'background_background', 'background_color', 'padding', 'hide_title' );
			$page_settings = get_post_meta( $page_id, '_elementor_page_settings', true );
			if ( ! is_array( $page_settings ) ) {
				$page_settings = array();
			}
			foreach ( $data['page_settings'] as $key => $value ) {
				if ( in_array( $key, $allowed_keys, true ) ) {
					$page_settings[ $key ] = $value;
				}
			}
			update_post_meta( $page_id, '_elementor_page_settings', $page_settings );
			$save_debug['page_settings_updated'] = array_keys( $data['page_settings'] );
		}

		// --- 6. Purge page caches (#89) ---

		$this->purge_page_cache( $page_id );
		$save_debug['page_cache_purged'] = true;

		// --- 7. Check RTL issues if site is RTL ---

		if ( function_exists( 'is_rtl' ) && is_rtl() ) {
			$rtl_warnings = $this->check_rtl_issues( $elementor_json );
			if ( ! empty( $rtl_warnings ) ) {
				$save_debug['rtl_warnings'] = $rtl_warnings;
			}
		}

		// Build top-level warnings from validation results.
		$all_warnings = array();
		if ( ! empty( $save_debug['validation_warnings'] ) ) {
			$all_warnings = array_merge( $all_warnings, $save_debug['validation_warnings'] );
		}
		if ( ! empty( $save_debug['validation_fixes'] ) ) {
			$all_warnings = array_merge( $all_warnings, $save_debug['validation_fixes'] );
		}
		if ( ! empty( $save_debug['rtl_warnings'] ) ) {
			$all_warnings = array_merge( $all_warnings, $save_debug['rtl_warnings'] );
		}

		$widgets_after = $this->count_elementor_widgets( $post_validation_decoded );

		$result = array(
			'success'            => true,
			'page_id'            => (int) $page_id,
			'message'            => __( 'Elementor data updated.', 'mumcp' ),
			'sections_saved'     => $save_debug['sections_saved'],
			'sections_submitted' => $save_debug['sections_submitted'],
			'save_method'        => $save_method,
			'structural_changes' => array(
				'sections_before' => $sections_before,
				'sections_after'  => $save_debug['sections_saved'],
				'widgets_before'  => $widgets_before,
				'widgets_after'   => $widgets_after,
				'was_empty'       => 0 === $sections_before,
			),
			'preview_url'        => add_query_arg( 'elementor-preview', $page_id, get_permalink( $page_id ) ),
			'css_regenerated'    => ! empty( $save_debug['css_regenerated'] ),
			'debug'              => $save_debug,
			'edit_url'           => admin_url( "post.php?post={$page_id}&action=elementor" ),
			'next_step'          => sprintf( 'Call wp_get_elementor_summary(id=%d) to verify the page structure.', $page_id ),
		);

		if ( ! empty( $all_warnings ) ) {
			$result['warnings'] = $all_warnings;
		}

		return $result;
	}

	/**
	 * Create a simple page with Elementor enabled.
	 *
	 * @param array $data Page data.
	 * @return array|WP_Error Created page data or error.
	 */
	public function create_elementor_page( $data ) {
		if ( ! $this->is_active() ) {
			return new WP_Error(
				'elementor_not_active',
				__( 'Elementor is not installed or active.', 'mumcp' ),
				array( 'status' => 400 )
			);
		}

		// Create page
		$page_data = array(
			'post_type'    => 'page',
			'post_title'   => isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : __( 'New Page', 'mumcp' ),
			'post_status'  => isset( $data['status'] ) ? sanitize_key( $data['status'] ) : 'draft',
			'post_content' => '',
		);

		$page_id = wp_insert_post( $page_data, true );

		if ( is_wp_error( $page_id ) ) {
			return $page_id;
		}

		// Enable Elementor with all required meta keys (#88).
		update_post_meta( $page_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $page_id, '_wp_page_template', 'elementor_header_footer' );
		update_post_meta( $page_id, '_elementor_template_type', 'wp-page' );
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			update_post_meta( $page_id, '_elementor_version', ELEMENTOR_VERSION );
		}

		// Set initial Elementor data if provided
		if ( ! empty( $data['elementor_data'] ) || ! empty( $data['elementor_json'] ) ) {
			$result = $this->set_elementor_data( $page_id, $data );
			if ( is_wp_error( $result ) ) {
				// Clean up page on error
				wp_delete_post( $page_id, true );
				return $result;
			}
		} else {
			// Set empty Elementor data
			update_post_meta( $page_id, '_elementor_data', '[]' );
		}

		return array(
			'success'  => true,
			'page_id'  => (int) $page_id,
			'title'    => $page_data['post_title'],
			'status'   => $page_data['post_status'],
			'url'      => get_permalink( $page_id ),
			'edit_url' => admin_url( "post.php?post={$page_id}&action=elementor" ),
		);
	}

	/**
	 * Generate a random 8-character element ID matching Elementor's format.
	 *
	 * @return string Random ID.
	 */
	private function generate_element_id() {
		$chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
		$id    = '';
		for ( $i = 0; $i < 8; $i++ ) {
			$id .= $chars[ wp_rand( 0, 35 ) ];
		}
		return $id;
	}

	/**
	 * Surgical edit of a single Elementor element without full JSON round-trip.
	 *
	 * Finds an element by ID, section index, or search criteria, merges
	 * settings, saves back, and returns only the modified element.
	 *
	 * @param int   $page_id Page/post ID.
	 * @param array $args    {
	 *     @type string $element_id    Find element by its Elementor ID.
	 *     @type int    $section_index Find top-level section by 0-based index.
	 *     @type array  $find          Search criteria: {widgetType, settings.key => value}.
	 *     @type array  $settings      Settings to merge into the found element.
	 *     @type array  $delete_settings Setting keys to remove.
	 * }
	 * @return array|WP_Error Result with the modified element, or error.
	 */
	public function edit_section( $page_id, $args ) {
		if ( ! $this->is_active() ) {
			return new WP_Error(
				'elementor_not_active',
				__( 'Elementor is not installed or active.', 'mumcp' ),
				array( 'status' => 400 )
			);
		}

		$page = $this->validate_post( $page_id );
		if ( is_wp_error( $page ) ) {
			return $page;
		}

		$elementor_data = get_post_meta( $page_id, '_elementor_data', true );
		if ( empty( $elementor_data ) ) {
			return new WP_Error(
				'no_elementor_data',
				__( 'This page has no Elementor data.', 'mumcp' ),
				array( 'status' => 404 )
			);
		}

		$elements = json_decode( $elementor_data, true );
		if ( ! is_array( $elements ) ) {
			return new WP_Error(
				'invalid_elementor_data',
				__( 'Elementor data is not valid JSON.', 'mumcp' ),
				array( 'status' => 500 )
			);
		}

		// --- Locate the target element ---

		$element_id    = isset( $args['element_id'] ) ? (string) $args['element_id'] : '';
		$section_index = isset( $args['section_index'] ) ? (int) $args['section_index'] : -1;
		$find          = isset( $args['find'] ) && is_array( $args['find'] ) ? $args['find'] : array();

		$found    = null;
		$found_path = '';

		if ( '' !== $element_id ) {
			// Find by element ID (recursive).
			$found =& $this->find_element_by_id( $elements, $element_id, $found_path );
		} elseif ( $section_index >= 0 ) {
			// Find by top-level index.
			if ( isset( $elements[ $section_index ] ) ) {
				$found      =& $elements[ $section_index ];
				$found_path = ( isset( $found['elType'] ) ? $found['elType'] : 'element' ) . "[{$section_index}]";
			}
		} elseif ( ! empty( $find ) ) {
			// Find by search criteria.
			$found =& $this->find_element_by_criteria( $elements, $find, $found_path );
		} else {
			return new WP_Error(
				'no_selector',
				__( 'Provide element_id, section_index, or find criteria to locate the target element.', 'mumcp' ),
				array( 'status' => 400 )
			);
		}

		if ( null === $found ) {
			return new WP_Error(
				'element_not_found',
				__( 'No matching element found in the Elementor tree.', 'mumcp' ),
				array( 'status' => 404 )
			);
		}

		// --- Apply patches ---

		$changes = array();

		// Merge settings.
		if ( ! empty( $args['settings'] ) && is_array( $args['settings'] ) ) {
			if ( ! isset( $found['settings'] ) || ! is_array( $found['settings'] ) ) {
				$found['settings'] = array();
			}
			foreach ( $args['settings'] as $key => $value ) {
				$found['settings'][ $key ] = $value;
				$changes[] = "set {$key}";
			}
		}

		// Delete settings.
		if ( ! empty( $args['delete_settings'] ) && is_array( $args['delete_settings'] ) ) {
			foreach ( $args['delete_settings'] as $key ) {
				if ( isset( $found['settings'][ $key ] ) ) {
					unset( $found['settings'][ $key ] );
					$changes[] = "removed {$key}";
				}
			}
		}

		if ( empty( $changes ) ) {
			return new WP_Error(
				'no_changes',
				__( 'No settings or delete_settings provided — nothing to change.', 'mumcp' ),
				array( 'status' => 400 )
			);
		}

		$save_debug = $this->save_elements_to_page( $page_id, $elements );
		if ( is_wp_error( $save_debug ) ) {
			return $save_debug;
		}

		// Re-read the saved element to return its final state.
		$saved_data = get_post_meta( $page_id, '_elementor_data', true );
		$saved_elements = json_decode( $saved_data, true );
		$saved_path = '';
		$saved_element = null;

		if ( '' !== $element_id ) {
			$saved_element = $this->find_element_by_id_readonly( $saved_elements, $element_id );
		} elseif ( $section_index >= 0 && isset( $saved_elements[ $section_index ] ) ) {
			$saved_element = $saved_elements[ $section_index ];
		} elseif ( ! empty( $find ) ) {
			$saved_element = $this->find_element_by_criteria_readonly( $saved_elements, $find );
		}

		$result = array(
			'success'  => true,
			'page_id'  => (int) $page_id,
			'path'     => $found_path,
			'changes'  => $changes,
			'element'  => $saved_element ? $saved_element : $found,
			'edit_url' => admin_url( "post.php?post={$page_id}&action=elementor" ),
		);
		if ( ! empty( $save_debug ) ) {
			$result['css_debug'] = array_intersect_key(
				$save_debug,
				array(
					'css_regenerated' => true,
					'css_file_size'   => true,
					'save_method'     => true,
					'meta_verified'   => true,
					'sections_saved'  => true,
				)
			);
		}

		$all_warnings = array_merge(
			isset( $save_debug['validation_warnings'] ) ? $save_debug['validation_warnings'] : array(),
			isset( $save_debug['validation_fixes'] ) ? $save_debug['validation_fixes'] : array()
		);
		if ( ! empty( $all_warnings ) ) {
			$result['warnings'] = $all_warnings;
		}

		return $result;
	}

	/**
	 * Edit a single widget's settings by widget ID.
	 *
	 * Lightweight alternative to edit_section that targets widgets specifically.
	 * Loads current elementor_data, finds the widget by its 8-char ID, merges
	 * new settings, and saves the updated data.
	 *
	 * @param int    $page_id   Page/post ID.
	 * @param string $widget_id The 8-char Elementor element ID of the widget.
	 * @param array  $settings  Settings to merge into the widget.
	 * @param array  $delete_settings Optional setting keys to remove.
	 * @return array|WP_Error Result with the modified widget, or error.
	 */
	public function edit_widget( $page_id, $widget_id, $settings, $delete_settings = array() ) {
		if ( ! $this->is_active() ) {
			return new WP_Error(
				'elementor_not_active',
				__( 'Elementor is not installed or active.', 'mumcp' ),
				array( 'status' => 400 )
			);
		}

		$page = $this->validate_post( $page_id );
		if ( is_wp_error( $page ) ) {
			return $page;
		}

		if ( empty( $widget_id ) ) {
			return new WP_Error(
				'missing_widget_id',
				__( 'widget_id is required.', 'mumcp' ),
				array( 'status' => 400 )
			);
		}

		if ( empty( $settings ) && empty( $delete_settings ) ) {
			return new WP_Error(
				'no_changes',
				__( 'No settings or delete_settings provided.', 'mumcp' ),
				array( 'status' => 400 )
			);
		}

		$elementor_data = get_post_meta( $page_id, '_elementor_data', true );
		if ( empty( $elementor_data ) ) {
			return new WP_Error(
				'no_elementor_data',
				__( 'This page has no Elementor data.', 'mumcp' ),
				array( 'status' => 404 )
			);
		}

		$elements = json_decode( $elementor_data, true );
		if ( ! is_array( $elements ) ) {
			return new WP_Error(
				'invalid_elementor_data',
				__( 'Elementor data is not valid JSON.', 'mumcp' ),
				array( 'status' => 500 )
			);
		}

		// Find widget by ID.
		$found_path = '';
		$found      =& $this->find_element_by_id( $elements, (string) $widget_id, $found_path );

		if ( null === $found ) {
			return new WP_Error(
				'widget_not_found',
				sprintf(
					/* translators: %s: widget ID */
					__( 'No element with ID "%s" found in the Elementor tree.', 'mumcp' ),
					$widget_id
				),
				array( 'status' => 404 )
			);
		}

		// Verify it is actually a widget.
		$el_type = isset( $found['elType'] ) ? $found['elType'] : '';
		if ( 'widget' !== $el_type ) {
			return new WP_Error(
				'not_a_widget',
				sprintf(
					/* translators: 1: element ID, 2: actual elType */
					__( 'Element "%1$s" is a %2$s, not a widget. Use wp_edit_section for non-widget elements.', 'mumcp' ),
					$widget_id,
					$el_type
				),
				array( 'status' => 400 )
			);
		}

		// Apply settings merge.
		$changes = array();
		if ( ! isset( $found['settings'] ) || ! is_array( $found['settings'] ) ) {
			$found['settings'] = array();
		}

		if ( ! empty( $settings ) && is_array( $settings ) ) {
			foreach ( $settings as $key => $value ) {
				$found['settings'][ $key ] = $value;
				$changes[] = "set {$key}";
			}
		}

		// Delete settings.
		if ( ! empty( $delete_settings ) && is_array( $delete_settings ) ) {
			foreach ( $delete_settings as $key ) {
				if ( isset( $found['settings'][ $key ] ) ) {
					unset( $found['settings'][ $key ] );
					$changes[] = "removed {$key}";
				}
			}
		}

		$save_debug = $this->save_elements_to_page( $page_id, $elements );
		if ( is_wp_error( $save_debug ) ) {
			return $save_debug;
		}

		// Re-read the widget to return its final state.
		$saved_data     = get_post_meta( $page_id, '_elementor_data', true );
		$saved_elements = json_decode( $saved_data, true );
		$saved_widget   = $this->find_element_by_id_readonly( $saved_elements, (string) $widget_id );

		$result = array(
			'success'     => true,
			'page_id'     => (int) $page_id,
			'widget_id'   => $widget_id,
			'widget_type' => isset( $found['widgetType'] ) ? $found['widgetType'] : null,
			'path'        => $found_path,
			'changes'     => $changes,
			'widget'      => $saved_widget ? $saved_widget : $found,
			'edit_url'    => admin_url( "post.php?post={$page_id}&action=elementor" ),
		);
		if ( ! empty( $save_debug ) ) {
			$result['css_debug'] = array_intersect_key(
				$save_debug,
				array(
					'css_regenerated' => true,
					'css_file_size'   => true,
					'save_method'     => true,
					'meta_verified'   => true,
					'sections_saved'  => true,
				)
			);
		}

		$all_warnings = array_merge(
			isset( $save_debug['validation_warnings'] ) ? $save_debug['validation_warnings'] : array(),
			isset( $save_debug['validation_fixes'] ) ? $save_debug['validation_fixes'] : array()
		);
		if ( ! empty( $all_warnings ) ) {
			$result['warnings'] = $all_warnings;
		}

		return $result;
	}

	// -------------------------------------------------------------------------
	// Section-level operations: save helper, add, remove, replace, patch, strip
	// -------------------------------------------------------------------------

	/**
	 * Shared helper: save an element array to a page with validation, CSS regen, and cache clear.
	 *
	 * @param int   $page_id  Page/post ID.
	 * @param array $elements Full top-level element array.
	 * @param array $options  Reserved for future options.
	 * @return array|WP_Error Debug info array or WP_Error on failure.
	 */
	protected function save_elements_to_page( $page_id, $elements, $options = array() ) {
		$debug = array();
		$elementor_json = wp_json_encode( $elements );

		// Count input.
		$input_count = count( $elements );
		$debug['sections_submitted'] = $input_count;

		// Validate and fix (before write).
		$validation = $this->validate_and_fix_elements( $elementor_json );
		if ( ! empty( $validation['errors'] ) ) {
			return new WP_Error( 'invalid_elementor_structure', implode( ' | ', $validation['errors'] ), array( 'status' => 400, 'validation_errors' => $validation['errors'] ) );
		}
		$debug['validation_fixes']    = $validation['fixes'];
		$debug['validation_warnings'] = $validation['warnings'];

		// Safety check: validation didn't corrupt data.
		$post_val = json_decode( $elementor_json, true );
		if ( ! is_array( $post_val ) || count( $post_val ) !== $input_count ) {
			return new WP_Error( 'validation_corrupted', 'Data corrupted during validation.', array( 'status' => 500 ) );
		}

		// Save via Elementor Document API (preferred) or meta fallback.
		$document_saved = false;
		$elementor_ok   = class_exists( '\Elementor\Plugin' );

		if ( $elementor_ok ) {
			$documents_manager = \Elementor\Plugin::$instance->documents;
			if ( $documents_manager && method_exists( $documents_manager, 'get' ) ) {
				wp_cache_delete( $page_id, 'post_meta' );
				clean_post_cache( $page_id );

				$document = $documents_manager->get( $page_id, false );
				if ( $document && method_exists( $document, 'save' ) ) {
					$save_result = $document->save( array( 'elements' => $post_val ) );
					if ( ! is_wp_error( $save_result ) ) {
						// (#198) Always do a direct meta overwrite after Document::save()
						// to guarantee the raw _elementor_data meta matches what we sent.
						// Document::save() may create revisions or write to internal caches
						// that diverge from the post meta on subsequent reads.
						update_post_meta( $page_id, '_elementor_data', wp_slash( $elementor_json ) );

						wp_cache_delete( $page_id, 'post_meta' );
						clean_post_cache( $page_id );

						// Flush persistent object cache if available.
						if ( function_exists( 'wp_cache_flush_group' ) ) {
							wp_cache_flush_group( 'post_meta' );
						}

						$stored_after_document = get_post_meta( $page_id, '_elementor_data', true );
						$decoded_after_document = json_decode( $stored_after_document, true );
						$stored_after_count     = is_array( $decoded_after_document ) ? count( $decoded_after_document ) : 0;

						if ( $stored_after_count === $input_count ) {
							$document_saved       = true;
							$debug['save_method'] = 'document_save_with_meta_overwrite';
						} else {
							$debug['document_save_persist_mismatch'] = array(
								'sections_expected' => $input_count,
								'sections_saved'    => $stored_after_count,
							);
						}
					} else {
						$debug['document_save_error'] = $save_result->get_error_message();
					}
				}
			}
		}

		// Fallback: direct meta write.
		if ( ! $document_saved ) {
			update_post_meta( $page_id, '_elementor_data', wp_slash( $elementor_json ) );
			$debug['save_method'] = 'meta_direct';

			// (#187) Update post_content so front-end renderer doesn't short-circuit.
			wp_update_post( array(
				'ID'           => $page_id,
				'post_content' => '',
			) );

			// (#187) Flush Elementor's in-memory document cache so CSS regen
			// and same-request renders read the new data from DB.
			if ( $elementor_ok && ! empty( \Elementor\Plugin::$instance->documents ) ) {
				clean_post_cache( $page_id );
				wp_cache_delete( $page_id, 'post_meta' );
				\Elementor\Plugin::$instance->documents->get( $page_id, false );
				$debug['document_cache_flushed'] = true;
			}
		}

		$debug['meta_written'] = true;

		// Verify.
		wp_cache_delete( $page_id, 'post_meta' );
		$stored         = get_post_meta( $page_id, '_elementor_data', true );
		$stored_decoded = json_decode( $stored, true );
		$stored_count   = is_array( $stored_decoded ) ? count( $stored_decoded ) : 0;
		$debug['sections_saved'] = $stored_count;
		$debug['meta_verified']  = ( $stored_count === $input_count );

		// Rebuild Elementor's CSS + HTML rendering cache.
		// Always regenerate — Document::save() may have used stale data before
		// our meta overwrite, leaving post_content (HTML cache) stale.
		if ( $elementor_ok ) {
			if ( ! empty( \Elementor\Plugin::$instance->files_manager ) ) {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
			}
			delete_post_meta( $page_id, '_elementor_css' );

			// Force a fresh document load and re-save to rebuild post_content (HTML rendering).
			if ( ! empty( \Elementor\Plugin::$instance->documents ) ) {
				$fresh_doc = \Elementor\Plugin::$instance->documents->get( $page_id, false );
				if ( $fresh_doc && method_exists( $fresh_doc, 'save' ) ) {
					$fresh_elements = json_decode( get_post_meta( $page_id, '_elementor_data', true ), true );
					if ( is_array( $fresh_elements ) && ! empty( $fresh_elements ) ) {
						$fresh_doc->save( array( 'elements' => $fresh_elements ) );
						$debug['html_cache_rebuilt'] = true;
					}
				}
			}

			if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
				$css_file = \Elementor\Core\Files\CSS\Post::create( $page_id );
				$css_file->update();
				$debug['css_regenerated'] = true;
			}
		}

		// Prime CSS if still empty.
		if ( $elementor_ok ) {
			$upload_dir = wp_upload_dir();
			$css_path   = $upload_dir['basedir'] . '/elementor/css/post-' . $page_id . '.css';
			$css_size   = file_exists( $css_path ) ? filesize( $css_path ) : 0;
			$debug['css_file_size'] = $css_size;
			if ( $css_size < 10 ) {
				delete_post_meta( $page_id, '_elementor_css' );
				$permalink = get_permalink( $page_id );
				if ( $permalink ) {
					wp_remote_get(
						add_query_arg( 'mcpwp_prime_css', wp_rand(), $permalink ),
						array(
							'timeout'   => 15,
							'sslverify' => ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
							'blocking'  => false,
						)
					);
				}
			}
		}

		$this->purge_page_cache( $page_id );

		$debug['next_step'] = sprintf( 'Call wp_get_elementor_summary(id=%d) to verify the page structure.', $page_id );

		return $debug;
	}

	/**
	 * Add a section/container at a specific position on a page.
	 *
	 * @param int   $page_id Page/post ID.
	 * @param array $data    {
	 *     @type array  $element  The element to add (must have elType).
	 *     @type string $position Where to insert: 'start', 'end', 'before:{id}', 'after:{id}'.
	 * }
	 * @return array|WP_Error Result or error.
	 */
	public function add_section( $page_id, $data ) {
		// Validate page exists and has Elementor.
		$post = get_post( $page_id );
		if ( ! $post ) {
			return new WP_Error( 'invalid_page', 'Page not found.', array( 'status' => 404 ) );
		}

		// Get current elements.
		$raw      = get_post_meta( $page_id, '_elementor_data', true );
		$elements = json_decode( $raw, true );
		if ( ! is_array( $elements ) ) {
			$elements = array();
		}

		// Validate new element.
		$new_element = isset( $data['element'] ) ? $data['element'] : null;
		if ( ! is_array( $new_element ) || empty( $new_element['elType'] ) ) {
			return new WP_Error( 'invalid_element', 'element must be an object with elType.', array( 'status' => 400 ) );
		}

		// Auto-generate ID if missing.
		if ( empty( $new_element['id'] ) ) {
			$new_element['id'] = $this->generate_element_id();
		}

		// Parse position.
		$position = isset( $data['position'] ) ? $data['position'] : 'end';
		$inserted = false;

		if ( 'start' === $position ) {
			array_unshift( $elements, $new_element );
			$inserted = true;
		} elseif ( 'end' === $position ) {
			$elements[] = $new_element;
			$inserted = true;
		} elseif ( preg_match( '/^(before|after):(.+)$/', $position, $m ) ) {
			$rel    = $m[1];
			$ref_id = $m[2];
			foreach ( $elements as $idx => $el ) {
				if ( isset( $el['id'] ) && $el['id'] === $ref_id ) {
					$insert_at = ( 'after' === $rel ) ? $idx + 1 : $idx;
					array_splice( $elements, $insert_at, 0, array( $new_element ) );
					$inserted = true;
					break;
				}
			}
			if ( ! $inserted ) {
				return new WP_Error( 'ref_not_found', "Reference element '$ref_id' not found in top-level sections.", array( 'status' => 404 ) );
			}
		} else {
			return new WP_Error( 'invalid_position', "Invalid position '$position'. Use: start, end, before:{id}, after:{id}.", array( 'status' => 400 ) );
		}

		// Save.
		$debug = $this->save_elements_to_page( $page_id, $elements );
		if ( is_wp_error( $debug ) ) {
			return $debug;
		}

		$all_warnings = array_merge(
			isset( $debug['validation_warnings'] ) ? $debug['validation_warnings'] : array(),
			isset( $debug['validation_fixes'] ) ? $debug['validation_fixes'] : array()
		);

		$result = array(
			'success'       => true,
			'page_id'       => (int) $page_id,
			'element_id'    => $new_element['id'],
			'position'      => $position,
			'section_count' => count( $elements ),
			'debug'         => $debug,
		);
		if ( ! empty( $all_warnings ) ) {
			$result['warnings'] = $all_warnings;
		}

		return $result;
	}

	/**
	 * Remove a section/container/widget by element ID.
	 *
	 * Searches top-level first, then recursively in nested elements.
	 *
	 * @param int   $page_id Page/post ID.
	 * @param array $data    {
	 *     @type string $element_id The Elementor element ID to remove.
	 * }
	 * @return array|WP_Error Result or error.
	 */
	public function remove_section( $page_id, $data ) {
		$post = get_post( $page_id );
		if ( ! $post ) {
			return new WP_Error( 'invalid_page', 'Page not found.', array( 'status' => 404 ) );
		}

		$element_id = isset( $data['element_id'] ) ? $data['element_id'] : '';
		if ( empty( $element_id ) ) {
			return new WP_Error( 'missing_id', 'element_id is required.', array( 'status' => 400 ) );
		}

		$raw      = get_post_meta( $page_id, '_elementor_data', true );
		$elements = json_decode( $raw, true );
		if ( ! is_array( $elements ) ) {
			return new WP_Error( 'no_data', 'Page has no Elementor data.', array( 'status' => 404 ) );
		}

		$before_count = count( $elements );
		$removed      = null;

		// Search top-level first.
		foreach ( $elements as $idx => $el ) {
			if ( isset( $el['id'] ) && $el['id'] === $element_id ) {
				$removed = $el;
				array_splice( $elements, $idx, 1 );
				break;
			}
		}

		if ( ! $removed ) {
			// Search recursively in nested elements.
			$removed = $this->remove_nested_element( $elements, $element_id );
		}

		if ( ! $removed ) {
			return new WP_Error( 'not_found', "Element '$element_id' not found.", array( 'status' => 404 ) );
		}

		$debug = $this->save_elements_to_page( $page_id, $elements );
		if ( is_wp_error( $debug ) ) {
			return $debug;
		}

		$all_warnings = array_merge(
			isset( $debug['validation_warnings'] ) ? $debug['validation_warnings'] : array(),
			isset( $debug['validation_fixes'] ) ? $debug['validation_fixes'] : array()
		);

		$result = array(
			'success'       => true,
			'page_id'       => (int) $page_id,
			'removed_id'    => $element_id,
			'removed_type'  => isset( $removed['elType'] ) ? $removed['elType'] : 'unknown',
			'section_count' => count( $elements ),
			'debug'         => $debug,
		);
		if ( ! empty( $all_warnings ) ) {
			$result['warnings'] = $all_warnings;
		}

		return $result;
	}

	/**
	 * Recursively remove an element by ID from nested elements arrays.
	 *
	 * @param array  &$elements Element tree (by reference).
	 * @param string $target_id The element ID to remove.
	 * @return array|null The removed element, or null if not found.
	 */
	private function remove_nested_element( &$elements, $target_id ) {
		foreach ( $elements as $idx => &$el ) {
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				foreach ( $el['elements'] as $child_idx => $child ) {
					if ( isset( $child['id'] ) && $child['id'] === $target_id ) {
						$removed = $child;
						array_splice( $el['elements'], $child_idx, 1 );
						return $removed;
					}
				}
				$found = $this->remove_nested_element( $el['elements'], $target_id );
				if ( $found ) {
					return $found;
				}
			}
		}
		unset( $el );
		return null;
	}

	/**
	 * Replace a section/container/widget by element ID.
	 *
	 * Searches top-level first, then recursively in nested elements.
	 *
	 * @param int   $page_id Page/post ID.
	 * @param array $data    {
	 *     @type string $element_id The Elementor element ID to replace.
	 *     @type array  $element    The new element (must have elType).
	 * }
	 * @return array|WP_Error Result or error.
	 */
	public function replace_section( $page_id, $data ) {
		$post = get_post( $page_id );
		if ( ! $post ) {
			return new WP_Error( 'invalid_page', 'Page not found.', array( 'status' => 404 ) );
		}

		$element_id  = isset( $data['element_id'] ) ? $data['element_id'] : '';
		$new_element = isset( $data['element'] ) ? $data['element'] : null;

		if ( empty( $element_id ) ) {
			return new WP_Error( 'missing_id', 'element_id is required.', array( 'status' => 400 ) );
		}
		if ( ! is_array( $new_element ) || empty( $new_element['elType'] ) ) {
			return new WP_Error( 'invalid_element', 'element must be an object with elType.', array( 'status' => 400 ) );
		}

		// Preserve or generate ID.
		if ( empty( $new_element['id'] ) ) {
			$new_element['id'] = $element_id; // keep same ID by default.
		}

		$raw      = get_post_meta( $page_id, '_elementor_data', true );
		$elements = json_decode( $raw, true );
		if ( ! is_array( $elements ) ) {
			return new WP_Error( 'no_data', 'Page has no Elementor data.', array( 'status' => 404 ) );
		}

		$replaced = false;

		// Search top-level.
		foreach ( $elements as $idx => $el ) {
			if ( isset( $el['id'] ) && $el['id'] === $element_id ) {
				$elements[ $idx ] = $new_element;
				$replaced = true;
				break;
			}
		}

		if ( ! $replaced ) {
			// Search recursively.
			$replaced = $this->replace_nested_element( $elements, $element_id, $new_element );
		}

		if ( ! $replaced ) {
			return new WP_Error( 'not_found', "Element '$element_id' not found.", array( 'status' => 404 ) );
		}

		$debug = $this->save_elements_to_page( $page_id, $elements );
		if ( is_wp_error( $debug ) ) {
			return $debug;
		}

		$all_warnings = array_merge(
			isset( $debug['validation_warnings'] ) ? $debug['validation_warnings'] : array(),
			isset( $debug['validation_fixes'] ) ? $debug['validation_fixes'] : array()
		);

		$result = array(
			'success'       => true,
			'page_id'       => (int) $page_id,
			'replaced_id'   => $element_id,
			'new_id'        => $new_element['id'],
			'section_count' => count( $elements ),
			'debug'         => $debug,
		);
		if ( ! empty( $all_warnings ) ) {
			$result['warnings'] = $all_warnings;
		}

		return $result;
	}

	/**
	 * Recursively replace an element by ID in nested elements arrays.
	 *
	 * @param array  &$elements   Element tree (by reference).
	 * @param string $target_id   The element ID to replace.
	 * @param array  $new_element The replacement element.
	 * @return bool True if replaced, false if not found.
	 */
	private function replace_nested_element( &$elements, $target_id, $new_element ) {
		foreach ( $elements as $idx => &$el ) {
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				foreach ( $el['elements'] as $child_idx => $child ) {
					if ( isset( $child['id'] ) && $child['id'] === $target_id ) {
						$el['elements'][ $child_idx ] = $new_element;
						return true;
					}
				}
				if ( $this->replace_nested_element( $el['elements'], $target_id, $new_element ) ) {
					return true;
				}
			}
		}
		unset( $el );
		return false;
	}

	/**
	 * Batch patch Elementor data: add, remove, replace, or update settings in one request.
	 *
	 * Applies up to 20 operations sequentially, then saves once.
	 *
	 * @param int   $page_id Page/post ID.
	 * @param array $data    {
	 *     @type array $operations Array of operations, each with:
	 *         @type string $op             Operation type: 'add', 'remove', 'replace', 'settings'.
	 *         @type string $element_id     Target element ID (for remove, replace, settings).
	 *         @type array  $element        New element (for add, replace).
	 *         @type string $position       Insert position (for add): 'start', 'end', 'before:{id}', 'after:{id}'.
	 *         @type array  $settings       Settings to merge (for settings op).
	 *         @type array  $delete_settings Setting keys to remove (for settings op).
	 * }
	 * @return array|WP_Error Result with per-operation results, or error.
	 */
	public function patch_elementor( $page_id, $data ) {
		$post = get_post( $page_id );
		if ( ! $post ) {
			return new WP_Error( 'invalid_page', 'Page not found.', array( 'status' => 404 ) );
		}

		$operations = isset( $data['operations'] ) ? $data['operations'] : array();
		if ( empty( $operations ) || ! is_array( $operations ) ) {
			return new WP_Error( 'no_operations', 'operations array is required.', array( 'status' => 400 ) );
		}

		if ( count( $operations ) > 20 ) {
			return new WP_Error( 'too_many_ops', 'Maximum 20 operations per patch.', array( 'status' => 400 ) );
		}

		$raw      = get_post_meta( $page_id, '_elementor_data', true );
		$elements = json_decode( $raw, true );
		if ( ! is_array( $elements ) ) {
			$elements = array();
		}

		$results = array();

		foreach ( $operations as $i => $op ) {
			$type   = isset( $op['op'] ) ? $op['op'] : '';
			$el_id  = isset( $op['element_id'] ) ? $op['element_id'] : '';
			$result = array(
				'op'    => $type,
				'index' => $i,
			);

			switch ( $type ) {
				case 'add':
					$new_el = isset( $op['element'] ) ? $op['element'] : null;
					if ( ! is_array( $new_el ) ) {
						$result['error'] = 'element required';
						break;
					}
					if ( empty( $new_el['id'] ) ) {
						$new_el['id'] = $this->generate_element_id();
					}
					$position = isset( $op['position'] ) ? $op['position'] : 'end';
					if ( 'start' === $position ) {
						array_unshift( $elements, $new_el );
					} elseif ( 'end' === $position ) {
						$elements[] = $new_el;
					} elseif ( preg_match( '/^(before|after):(.+)$/', $position, $m ) ) {
						$found_ref = false;
						foreach ( $elements as $idx => $el ) {
							if ( isset( $el['id'] ) && $el['id'] === $m[2] ) {
								$insert_at = ( 'after' === $m[1] ) ? $idx + 1 : $idx;
								array_splice( $elements, $insert_at, 0, array( $new_el ) );
								$found_ref = true;
								break;
							}
						}
						if ( ! $found_ref ) {
							$result['error'] = "ref '{$m[2]}' not found";
							break;
						}
					}
					$result['success']    = true;
					$result['element_id'] = $new_el['id'];
					break;

				case 'remove':
					if ( empty( $el_id ) ) {
						$result['error'] = 'element_id required';
						break;
					}
					$removed = null;
					foreach ( $elements as $idx => $el ) {
						if ( isset( $el['id'] ) && $el['id'] === $el_id ) {
							$removed = $el;
							array_splice( $elements, $idx, 1 );
							break;
						}
					}
					if ( ! $removed ) {
						$removed = $this->remove_nested_element( $elements, $el_id );
					}
					if ( $removed ) {
						$result['success'] = true;
					} else {
						$result['error'] = "element '$el_id' not found";
					}
					break;

				case 'replace':
					if ( empty( $el_id ) ) {
						$result['error'] = 'element_id required';
						break;
					}
					$new_el = isset( $op['element'] ) ? $op['element'] : null;
					if ( ! is_array( $new_el ) ) {
						$result['error'] = 'element required';
						break;
					}
					if ( empty( $new_el['id'] ) ) {
						$new_el['id'] = $el_id;
					}
					$op_replaced = false;
					foreach ( $elements as $idx => $el ) {
						if ( isset( $el['id'] ) && $el['id'] === $el_id ) {
							$elements[ $idx ] = $new_el;
							$op_replaced = true;
							break;
						}
					}
					if ( ! $op_replaced ) {
						$op_replaced = $this->replace_nested_element( $elements, $el_id, $new_el );
					}
					$result['success'] = $op_replaced;
					if ( ! $op_replaced ) {
						$result['error'] = "element '$el_id' not found";
					}
					break;

				case 'settings':
					if ( empty( $el_id ) ) {
						$result['error'] = 'element_id required';
						break;
					}
					$dummy_path = '';
					$target     =& $this->find_element_by_id( $elements, $el_id, $dummy_path );
					if ( null === $target ) {
						$result['error'] = "element '$el_id' not found";
						break;
					}
					if ( ! isset( $target['settings'] ) || ! is_array( $target['settings'] ) ) {
						$target['settings'] = array();
					}
					if ( ! empty( $op['settings'] ) && is_array( $op['settings'] ) ) {
						foreach ( $op['settings'] as $k => $v ) {
							$target['settings'][ $k ] = $v;
						}
					}
					if ( ! empty( $op['delete_settings'] ) && is_array( $op['delete_settings'] ) ) {
						foreach ( $op['delete_settings'] as $k ) {
							unset( $target['settings'][ $k ] );
						}
					}
					unset( $target );
					$result['success'] = true;
					break;

				default:
					$result['error'] = "unknown op '$type' — use: add, remove, replace, settings";
					break;
			}

			$results[] = $result;
		}

		// Save once.
		$debug = $this->save_elements_to_page( $page_id, $elements );
		if ( is_wp_error( $debug ) ) {
			return $debug;
		}

		$all_warnings = array_merge(
			isset( $debug['validation_warnings'] ) ? $debug['validation_warnings'] : array(),
			isset( $debug['validation_fixes'] ) ? $debug['validation_fixes'] : array()
		);

		$result = array(
			'success'       => true,
			'page_id'       => (int) $page_id,
			'operations'    => $results,
			'section_count' => count( $elements ),
			'debug'         => $debug,
		);
		if ( ! empty( $all_warnings ) ) {
			$result['warnings'] = $all_warnings;
		}

		return $result;
	}

	/**
	 * Strip default widget settings from an element tree.
	 *
	 * Compares each widget's settings against the known schema defaults
	 * and removes any settings that match the default value, reducing payload size.
	 *
	 * @param array &$elements Element tree (by reference, modified in place).
	 */
	public function strip_element_defaults( &$elements ) {
		foreach ( $elements as &$el ) {
			if ( isset( $el['elType'] ) && 'widget' === $el['elType'] && ! empty( $el['widgetType'] ) ) {
				$schema = Mcpwp_Elementor_Widgets::get( $el['widgetType'] );
				if ( $schema && ! empty( $schema['settings'] ) && ! empty( $el['settings'] ) ) {
					foreach ( $schema['settings'] as $key => $def ) {
						if ( isset( $el['settings'][ $key ] ) && isset( $def['default'] )
							&& $el['settings'][ $key ] === $def['default'] ) {
							unset( $el['settings'][ $key ] );
						}
					}
				}
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$this->strip_element_defaults( $el['elements'] );
			}
		}
		unset( $el );
	}

	/**
	 * Find an element by ID recursively (returns reference).
	 *
	 * @param array  &$elements Element tree (by reference for modification).
	 * @param string $id        Target element ID.
	 * @param string &$path     Populated with the path to the found element.
	 * @return array|null Reference to the found element or null.
	 */
	private function &find_element_by_id( &$elements, $id, &$path ) {
		$null = null;
		foreach ( $elements as $idx => &$el ) {
			$el_type = isset( $el['elType'] ) ? $el['elType'] : 'element';
			$current_path = "{$el_type}[{$idx}]";

			if ( isset( $el['id'] ) && (string) $el['id'] === $id ) {
				$path = $current_path;
				return $el;
			}

			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$child_path = '';
				$found =& $this->find_element_by_id( $el['elements'], $id, $child_path );
				if ( null !== $found ) {
					$path = $current_path . '.' . $child_path;
					return $found;
				}
			}
		}
		unset( $el );
		return $null;
	}

	/**
	 * Find an element by search criteria recursively (returns reference).
	 *
	 * @param array  &$elements Element tree (by reference).
	 * @param array  $criteria  Search criteria (widgetType, settings.key => value).
	 * @param string &$path     Populated with the path to the found element.
	 * @return array|null Reference to the found element or null.
	 */
	private function &find_element_by_criteria( &$elements, $criteria, &$path ) {
		$null = null;
		foreach ( $elements as $idx => &$el ) {
			$el_type = isset( $el['elType'] ) ? $el['elType'] : 'element';
			$current_path = "{$el_type}[{$idx}]";

			if ( $this->element_matches_criteria( $el, $criteria ) ) {
				$path = $current_path;
				return $el;
			}

			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$child_path = '';
				$found =& $this->find_element_by_criteria( $el['elements'], $criteria, $child_path );
				if ( null !== $found ) {
					$path = $current_path . '.' . $child_path;
					return $found;
				}
			}
		}
		unset( $el );
		return $null;
	}

	/**
	 * Check if an element matches search criteria.
	 *
	 * @param array $element  The element to check.
	 * @param array $criteria Search criteria.
	 * @return bool True if all criteria match.
	 */
	private function element_matches_criteria( $element, $criteria ) {
		foreach ( $criteria as $key => $value ) {
			if ( 'widgetType' === $key ) {
				if ( ! isset( $element['widgetType'] ) || $element['widgetType'] !== $value ) {
					return false;
				}
			} elseif ( 'elType' === $key ) {
				if ( ! isset( $element['elType'] ) || $element['elType'] !== $value ) {
					return false;
				}
			} elseif ( 0 === strpos( $key, 'settings.' ) ) {
				$setting_key = substr( $key, 9 );
				$settings    = isset( $element['settings'] ) ? $element['settings'] : array();
				if ( ! isset( $settings[ $setting_key ] ) || $settings[ $setting_key ] !== $value ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Recursively count widget elements in an Elementor element tree.
	 *
	 * @param array $elements Element tree.
	 * @return int Widget count.
	 */
	private function count_elementor_widgets( array $elements ) {
		$count = 0;
		foreach ( $elements as $element ) {
			if ( isset( $element['elType'] ) && 'widget' === $element['elType'] ) {
				++$count;
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$count += $this->count_elementor_widgets( $element['elements'] );
			}
		}
		return $count;
	}
}
