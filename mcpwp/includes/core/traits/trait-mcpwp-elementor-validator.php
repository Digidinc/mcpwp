<?php
/**
 * Elementor engine — validator.
 *
 * Carved verbatim from Mcpwp_Elementor_Basic (G4 split). Mixed back via trait — same class, same $this.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Mcpwp_Elementor_Validator_Trait {

	/**
	 * The "not found or unsupported type" hint, keyed by post id.
	 *
	 * Single source of truth for validate_post()'s own missing/unsupported
	 * branch AND Mcpwp_REST_Elementor's guard_non_public_post() calls
	 * (get_elementor_data(), get_elementor_summary(), get_elementor_preview())
	 * — all four MUST produce byte-identical hints for the same id, or a
	 * non-public post (denied by the guard) becomes distinguishable from a
	 * genuinely missing/unsupported one purely by hint text (#1026).
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public function not_found_hint( $post_id ) {
		return sprintf(
			'Post ID %d not found or is not a supported type (page, post, elementor_library, elementor_snippet). Use wp_list_pages or wp_list_posts to find valid IDs.',
			absint( $post_id )
		);
	}

	/**
	 * Validate and return a post if it's a supported Elementor type.
	 *
	 * @param int $post_id Post ID.
	 * @return WP_Post|WP_Error Post object or error.
	 */
	public function validate_post( $post_id ) {
		$post    = get_post( absint( $post_id ) );
		$allowed = array( 'page', 'post', 'elementor_library', 'elementor_snippet' );

		if ( ! $post || ! in_array( $post->post_type, $allowed, true ) ) {
			return new WP_Error(
				'not_found',
				__( 'Post not found or unsupported type.', 'mcpwp' ),
				array(
					'status' => 404,
					'hint'   => $this->not_found_hint( $post_id ),
				)
			);
		}

		return $post;
	}

	/**
	 * Check if data has valid Elementor structure (array of elements).
	 *
	 * @param mixed $data Data to validate.
	 * @return bool True if valid structure.
	 */
	/**
	 * Attempt to decode a JSON string with recovery for common MCP corruption.
	 *
	 * Tries, in order:
	 * 1. Direct json_decode
	 * 2. Strip outer quotes (double-encoded JSON string containing JSON)
	 * 3. stripslashes then decode (WordPress magic quotes / MCP escaping)
	 * 4. utf8_encode then decode (encoding issues)
	 *
	 * @param string $raw_string The raw JSON string to decode.
	 * @return mixed|null The decoded value, or null if all attempts fail.
	 */
	private function try_json_decode( $raw_string ) {
		// 1. Direct decode.
		$decoded = json_decode( $raw_string, true );
		if ( null !== $decoded || json_last_error() === JSON_ERROR_NONE ) {
			return $decoded;
		}

		// 2. Strip outer quotes — double-encoded JSON string (e.g. '"[{...}]"').
		$trimmed = trim( $raw_string );
		if ( strlen( $trimmed ) >= 2 && '"' === $trimmed[0] && '"' === $trimmed[ strlen( $trimmed ) - 1 ] ) {
			$inner   = json_decode( $trimmed, true ); // decode outer string wrapper
			if ( is_string( $inner ) ) {
				$decoded = json_decode( $inner, true );
				if ( null !== $decoded || json_last_error() === JSON_ERROR_NONE ) {
					return $decoded;
				}
			}
		}

		// 3. stripslashes then decode (WordPress magic quotes or MCP escaping).
		$unslashed = stripslashes( $raw_string );
		if ( $unslashed !== $raw_string ) {
			$decoded = json_decode( $unslashed, true );
			if ( null !== $decoded || json_last_error() === JSON_ERROR_NONE ) {
				return $decoded;
			}
		}

		// 4. mb_convert_encoding for non-UTF-8 strings (requires mbstring, available PHP 7.0+).
		if ( function_exists( 'mb_detect_encoding' ) && function_exists( 'mb_convert_encoding' )
			&& ! mb_detect_encoding( $raw_string, 'UTF-8', true )
		) {
			$utf8    = mb_convert_encoding( $raw_string, 'UTF-8', 'ISO-8859-1' );
			$decoded = json_decode( $utf8, true );
			if ( null !== $decoded || json_last_error() === JSON_ERROR_NONE ) {
				return $decoded;
			}
		}

		return null;
	}

	private function is_valid_elementor_structure( $data ) {
		// Must be an array (can be empty for blank pages).
		if ( ! is_array( $data ) ) {
			return false;
		}

		// Empty array is valid (blank page).
		if ( empty( $data ) ) {
			return true;
		}

		// If indexed array, first element should be an array (element object).
		if ( isset( $data[0] ) && ! is_array( $data[0] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Check whether a setting key is a valid responsive variant of a base control.
	 *
	 * Elementor commonly expands responsive controls into suffixed keys such as
	 * `_tablet` and `_mobile` even when the base control name is the canonical one.
	 *
	 * @param string $key        Candidate setting key.
	 * @param array  $valid_keys Known valid base keys.
	 * @return bool True if the key is a recognized responsive variant.
	 */
	private function is_valid_responsive_setting_key( $key, $valid_keys ) {
		if ( ! preg_match( '/^(.+?)_(mobile|mobile_extra|tablet|tablet_extra|laptop|widescreen)$/', $key, $matches ) ) {
			return false;
		}

		return in_array( $matches[1], $valid_keys, true );
	}

	/**
	 * Validate and fix element tree.
	 *
	 * Performs 5 validation passes:
	 * 1. Auto-generate missing element IDs
	 * 2. Validate widget types against registered widgets
	 * 3. Rename known wrong control keys
	 * 4. Validate element structure and nesting
	 * 5. Flag suspicious/unknown control keys
	 * and, after the walk, flags element ids Elementor Pro's AJAX endpoints
	 * reject — rewriting them only when `repair_ids` is set (#842).
	 *
	 * Modifies $elementor_json in-place (reference).
	 *
	 * @param string &$elementor_json JSON string (modified in-place).
	 * @param array  $options {
	 *     @type bool   $repair_ids Rewrite ids that are not exactly 7 alphanumeric
	 *                              characters and repoint references to them.
	 *     @type string $id_seed    Makes generated and repaired ids deterministic
	 *                              for this document (the writer passes the post id).
	 * }
	 * @return array Associative array with 'warnings', 'fixes', and 'errors'
	 *               arrays, plus 'id_repair' when repair_ids was requested.
	 */
	private function validate_and_fix_elements( &$elementor_json, array $options = array() ) {
		$elements = json_decode( $elementor_json, true );
		if ( ! is_array( $elements ) ) {
			return array(
				'warnings' => array( 'Elementor data is not a valid array.' ),
				'fixes'    => array(),
				'errors'   => array(),
			);
		}

		$warnings          = array();
		$fixes             = array();
		$errors            = array();
		$changed           = false;
		$seen_ids          = array();

		// Ids a generated id must not collide with: every id already in the
		// tree — not only those the walk has passed, which let a new id equal
		// a later, conforming element's id and get that element renamed
		// (review r1 P3-8c) — plus each id generated so far. With a seed (the
		// writer passes the post id) generation is deterministic, so a dry run
		// and the write that follows it produce the same tree (#842).
		$all_ids = Mcpwp_Elementor_Element_Ids::ids( $elements );
		$id_seed = isset( $options['id_seed'] ) && '' !== (string) $options['id_seed'] ? (string) $options['id_seed'] : null;
		$new_id  = function ( $salt ) use ( &$all_ids, $id_seed ) {
			$id = null === $id_seed
				? Mcpwp_Elementor_Element_Ids::generate( $all_ids )
				: Mcpwp_Elementor_Element_Ids::derive( $id_seed, $salt, $all_ids );

			$all_ids[ $id ] = true;

			return $id;
		};
		// Elementor's own registry is the truth about what will render here.
		// The hardcoded lists stand in for it when Elementor cannot be asked;
		// they do not overrule it when it can. Merging the two, as this used
		// to, let `archive-title` — renamed to `theme-archive-title` in
		// Elementor Pro 3.0 and no longer registered anywhere — save clean
		// with an empty warnings array and render nothing (#785).
		$live       = Mcpwp_Elementor_Registry::live_types();
		$registered = null === $live ? $this->get_registered_widgets() : $live;

		$registered_lookup = array_flip( $registered );

		// Kept separate so the warning can say which of the two situations it
		// is: a name Elementor has never heard of, or a real Pro widget on a
		// site where Pro is not active. Those need different actions. (Same
		// class as the reader trait after the G4 split, so its reference lists
		// are reachable directly.)
		$known_pro_lookup = array_flip( self::$known_pro_widgets );

		// find_closest_widget() is private to this class; the registry helper
		// lives outside it, so it gets a closure rather than a method array,
		// which is_callable() would reject from that scope.
		$fuzzy = function ( $input, $candidates ) {
			return $this->find_closest_widget( $input, $candidates );
		};

		/**
		 * Walk a single element recursively.
		 *
		 * @param array  &$el   Element (modified in-place).
		 * @param string $path  Human-readable path for warnings.
		 * @param string $parent_type Parent elType for nesting validation.
		 */
		$walk = function ( &$el, $path = '', $parent_type = '' ) use (
			&$walk, &$warnings, &$fixes, &$errors, &$changed, &$seen_ids,
			$registered, $registered_lookup, $known_pro_lookup, $fuzzy, $new_id
		) {
			$el_type     = isset( $el['elType'] ) ? $el['elType'] : '';
			$widget_type = isset( $el['widgetType'] ) ? $el['widgetType'] : '';

			// --- 1. Auto-generate missing IDs ---
			if ( empty( $el['id'] ) ) {
				$el['id'] = $new_id( 'missing|' . $path );
				$fixes[]  = "{$path}: auto-generated missing ID";
				$changed  = true;
			}

			// --- 1b. Deduplicate IDs ---
			if ( isset( $seen_ids[ $el['id'] ] ) ) {
				$old_id   = $el['id'];
				// Unique by construction: $new_id() avoids every id in the tree.
				$el['id'] = $new_id( 'duplicate|' . $path . '|' . $old_id );
				$fixes[]  = "{$path}: reassigned duplicate ID '{$old_id}' → '{$el['id']}'";
				$changed  = true;
			}
			$seen_ids[ $el['id'] ] = true;

			// --- 2. Validate elType ---
			if ( '' === $el_type ) {
				$warnings[] = "{$path}: missing elType";
			} elseif ( ! in_array( $el_type, self::$valid_el_types, true ) ) {
				$warnings[] = "{$path}: unknown elType '{$el_type}'";
			}

			// --- 3. Validate nesting ---
			if ( '' !== $parent_type && '' !== $el_type && isset( self::$nesting_rules[ $parent_type ] ) ) {
				if ( ! in_array( $el_type, self::$nesting_rules[ $parent_type ], true ) ) {
					$warnings[] = "{$path}: '{$el_type}' should not be nested inside '{$parent_type}'";
				}
			}

			// --- 3b. Auto-set isInner on child containers ---
			if ( 'container' === $el_type && 'container' === $parent_type ) {
				if ( ! isset( $el['settings'] ) || ! is_array( $el['settings'] ) ) {
					$el['settings'] = array();
				}
				if ( empty( $el['settings']['isInner'] ) ) {
					$warnings[] = "{$path}: nested container missing isInner flag (renders as e-parent instead of e-child, breaking flex layout)";
					$el['settings']['isInner'] = true;
					$fixes[]  = "{$path}: auto-set isInner for child container";
					$changed  = true;
				}
			}

			// --- 4. Validate widget type ---
			if ( 'widget' === $el_type && '' !== $widget_type ) {
				if ( ! isset( $registered_lookup[ $widget_type ] ) ) {
					$suggestion = Mcpwp_Elementor_Registry::suggest(
						$widget_type,
						$registered,
						$fuzzy
					);

					if ( $suggestion ) {
						$warnings[] = "{$path}: unknown widget '{$widget_type}' (did you mean '{$suggestion}'?)";
					} elseif ( isset( $known_pro_lookup[ $widget_type ] ) ) {
						// A real Elementor Pro widget name that this site does
						// not register. Saying "unknown widget type" would send
						// the caller hunting for a typo that is not there.
						$warnings[] = "{$path}: widget '{$widget_type}' is an Elementor Pro widget and is not registered on this site — it will render nothing";
					} else {
						$warnings[] = "{$path}: unknown widget type '{$widget_type}'";
					}
				}
			} elseif ( 'widget' === $el_type && '' === $widget_type ) {
				$errors[] = "{$path}: widget element missing widgetType";
			}

			// --- 4b. Rename css_id → _element_id on ANY element (#678) ---
			//
			// Elementor renders the wrapper id from `_element_id`, in
			// Element_Base's shared render path:
			//
			//   if ( ! empty( $settings['_element_id'] ) ) {
			//       $this->add_render_attribute( '_wrapper', 'id', trim( ... ) );
			//   }
			//
			// Element_Base is the parent of Section, Column, Container and
			// Widget, so `_element_id` is the ID control for all of them.
			// `css_id` is not an Elementor control on any of them — it stores
			// and round-trips perfectly through get/set while the rendered
			// element carries no id at all, so hash links have no target.
			//
			// The rename below is deliberately NOT limited to widgets, unlike
			// the $control_renames pass: the reported case (#678) was a
			// top-level section, which that pass cannot reach because it keys
			// off widgetType.
			//
			// Note the asymmetry that makes `css_id` a natural mistake: the
			// CLASSES control really is `css_classes` on section/column/
			// container (and `_css_classes` on widgets), so `css_classes`
			// works. Renaming that too would break every element currently
			// styled correctly — it is left alone on purpose.
			// The rule itself lives in Mcpwp_Elementor_Element_Keys so the four
			// write paths that never reach this validator apply the identical
			// rename (#737). Only the per-element reporting is local.
			if ( isset( $el['settings'] ) && is_array( $el['settings'] ) && class_exists( 'Mcpwp_Elementor_Element_Keys' ) ) {
				$key_result = Mcpwp_Elementor_Element_Keys::rename_settings( $el['settings'] );

				if ( $el['settings'] !== $key_result['settings'] ) {
					$el['settings'] = $key_result['settings'];
					$changed        = true;
				}

				foreach ( $key_result['renamed'] as $wrong_key ) {
					$right_key = Mcpwp_Elementor_Element_Keys::SETTINGS_RENAMES[ $wrong_key ];
					$fixes[]   = "{$path}: renamed '{$wrong_key}' -> '{$right_key}' (Elementor renders the element id from _element_id; css_id is stored but never rendered)";
				}
			}

			// --- 5. Rename known wrong control keys ---
			if ( '' !== $widget_type && isset( self::$control_renames[ $widget_type ] ) ) {
				$renames = self::$control_renames[ $widget_type ];
				foreach ( $renames as $old_key => $new_key ) {
					if ( isset( $el['settings'][ $old_key ] ) && ! isset( $el['settings'][ $new_key ] ) ) {
						$el['settings'][ $new_key ] = $el['settings'][ $old_key ];
						unset( $el['settings'][ $old_key ] );
						$fixes[] = "{$path}: renamed '{$old_key}' -> '{$new_key}'";
						$changed = true;
					}
				}
			}

			// --- 6. Flag unknown settings keys using widget reference ---
			$is_atomic = in_array( $el_type, array( 'e-div-block', 'e-flexbox', 'e-grid', 'e-heading', 'e-paragraph', 'e-button', 'e-image', 'e-svg', 'e-divider', 'e-youtube', 'e-self-hosted-video' ), true );

			// (#618/#622) Atomic elements use props/styles, not the legacy
			// Controls_Manager settings model — validate against the real schema
			// from get_props_schema() (#621) instead of skipping validation
			// entirely, so a malformed atomic payload gets a warning instead of
			// silently writing something Elementor's own editor can't parse.
			if ( $is_atomic && ! empty( $el['settings'] ) && is_array( $el['settings'] ) && method_exists( $this, 'get_widget_schema' ) ) {
				$atomic_schema = $this->get_widget_schema( $el_type );
				if ( ! is_wp_error( $atomic_schema ) && ! empty( $atomic_schema['props'] ) && is_array( $atomic_schema['props'] ) ) {
					$valid_prop_keys = array_keys( $atomic_schema['props'] );
					foreach ( $el['settings'] as $key => $value ) {
						if ( ! in_array( $key, $valid_prop_keys, true ) ) {
							$valid_list = implode( ', ', array_slice( $valid_prop_keys, 0, 15 ) );
							$suffix     = count( $valid_prop_keys ) > 15 ? ', ...' : '';
							$warnings[] = "{$path}: unknown prop '{$key}' on atomic element '{$el_type}' — valid props: {$valid_list}{$suffix}";
							continue;
						}
						// Atomic prop values must be {"$$type": "<kind>", "value": <actual>}
						// (#618, confirmed against Elementor source) — a plain scalar here
						// is the single most common mistake and Elementor's own editor
						// will silently fail to parse it.
						if ( ! is_array( $value ) || ! array_key_exists( '$$type', $value ) || ! array_key_exists( 'value', $value ) ) {
							$warnings[] = "{$path}: prop '{$key}' on atomic element '{$el_type}' must be wrapped as {\"\$\$type\": \"<kind>\", \"value\": <actual>}, not a plain value — call wp_get_widget_schema('{$el_type}') to see the expected \"kind\" for this prop";
						}
					}
				}
			}

			if ( ! $is_atomic && 'widget' === $el_type && '' !== $widget_type && ! empty( $el['settings'] ) && is_array( $el['settings'] ) ) {
				$valid_keys = $this->get_valid_widget_keys( $widget_type );
				if ( ! empty( $valid_keys ) ) {
					// Common Elementor internal prefixes that should not trigger warnings.
					$internal_prefixes = array( '_', 'motion_fx_', '__', 'hide_', 'responsive_', 'custom_css' );
					foreach ( array_keys( $el['settings'] ) as $key ) {
						// Skip known valid keys.
						if ( in_array( $key, $valid_keys, true ) || $this->is_valid_responsive_setting_key( $key, $valid_keys ) ) {
							continue;
						}
						// Skip internal/advanced keys.
						$skip = false;
						foreach ( $internal_prefixes as $prefix ) {
							if ( 0 === strpos( $key, $prefix ) ) {
								$skip = true;
								break;
							}
						}
						if ( $skip ) {
							continue;
						}
						// Flag unknown key with valid alternatives.
						$valid_list = implode( ', ', array_slice( $valid_keys, 0, 15 ) );
						$suffix     = count( $valid_keys ) > 15 ? ', ...' : '';
						$warnings[] = "{$path}: unknown key '{$key}' on {$widget_type} widget — valid keys: {$valid_list}{$suffix}";
					}
				}
			}

			// --- 7. Validate elements array ---
			if ( isset( $el['elements'] ) && ! is_array( $el['elements'] ) ) {
				$warnings[]    = "{$path}: 'elements' must be an array";
				$el['elements'] = array();
				$changed        = true;
			}

			// Recurse into children.
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				foreach ( $el['elements'] as $idx => &$child ) {
					$child_path = $path . '.' . ( isset( $child['elType'] ) ? $child['elType'] : 'element' ) . "[{$idx}]";
					$walk( $child, $child_path, $el_type );
				}
				unset( $child );
			}
		};

		// Walk each top-level element.
		foreach ( $elements as $idx => &$el ) {
			$top_type = isset( $el['elType'] ) ? $el['elType'] : 'element';
			$path     = "{$top_type}[{$idx}]";
			$walk( $el, $path, '' );
		}
		unset( $el );

		// --- 8. Element ids Elementor Pro's AJAX endpoints will accept (#842) ---
		//
		// After the walk, so ids it generated or de-duplicated are already in
		// place. Rewriting is opt-in: ids are CSS selectors and other widgets
		// point at them, so the default is to say which ones are wrong and
		// leave them. The rule and the reasoning live in
		// Mcpwp_Elementor_Element_Ids.
		$id_repair = null;

		if ( ! empty( $options['repair_ids'] ) ) {
			$repaired  = Mcpwp_Elementor_Element_Ids::repair( $elements, null === $id_seed ? '' : $id_seed );
			$id_repair = $repaired['report'];

			if ( ! empty( $id_repair['rewritten'] ) ) {
				$elements = $repaired['elements'];
				$changed  = true;
			}

			foreach ( $id_repair['rewritten'] as $row ) {
				$fixes[] = "{$row['path']}: rewrote id '{$row['from']}' -> '{$row['to']}' (Elementor Pro's AJAX endpoints accept only " . Mcpwp_Elementor_Element_Ids::LENGTH . '-character alphanumeric ids)';
			}
			foreach ( $id_repair['references_repointed'] as $row ) {
				$fixes[] = "{$row['path']}: repointed {$row['key']} '{$row['from']}' -> '{$row['to']}'";
			}
		}

		$warnings = array_merge(
			$warnings,
			Mcpwp_Elementor_Element_Ids::warnings( Mcpwp_Elementor_Element_Ids::audit( $elements ) )
		);

		// Re-encode if any fixes were applied.
		if ( $changed ) {
			$elementor_json = wp_json_encode( $elements );
		}

		$result = array(
			'warnings' => $warnings,
			'fixes'    => $fixes,
			'errors'   => $errors,
		);

		// Present only when asked for, and then always — "asked, nothing to
		// rewrite" carries a report saying so, never an absent key.
		if ( null !== $id_repair ) {
			$result['id_repair'] = $id_repair;
		}

		return $result;
	}

	/**
	 * Backward-compatible wrapper for the old sanitize method.
	 *
	 * @param string &$elementor_json JSON string (modified in-place).
	 * @return array Warnings about renamed keys.
	 * @deprecated Use validate_and_fix_elements() instead.
	 */
	private function sanitize_element_settings( &$elementor_json ) {
		$result = $this->validate_and_fix_elements( $elementor_json );
		return array_merge( $result['warnings'], $result['fixes'] );
	}

	/**
	 * Check Elementor data for RTL-unfriendly patterns.
	 *
	 * Walks the element tree looking for hardcoded LTR alignment, floats,
	 * and one-sided margins/padding that may break on RTL sites.
	 * Returns informational warnings only — no auto-fixes.
	 *
	 * @param string $elementor_json JSON string.
	 * @return array Warning strings.
	 */
	private function check_rtl_issues( $elementor_json ) {
		$elements = json_decode( $elementor_json, true );
		if ( ! is_array( $elements ) ) {
			return array();
		}

		$warnings = array();

		$walk = function ( $el, $path = '' ) use ( &$walk, &$warnings ) {
			$settings = isset( $el['settings'] ) && is_array( $el['settings'] ) ? $el['settings'] : array();

			// Check hardcoded align: left (should use 'start' or 'right' for RTL).
			$align_keys = array( 'align', 'title_align', 'description_align', 'content_align', 'text_align' );
			foreach ( $align_keys as $key ) {
				if ( isset( $settings[ $key ] ) && 'left' === $settings[ $key ] ) {
					$warnings[] = "{$path}: '{$key}' is 'left' — consider 'start' or 'right' for RTL sites";
				}
			}

			// Check custom_css for float: left or one-sided margin/padding.
			if ( ! empty( $settings['custom_css'] ) && is_string( $settings['custom_css'] ) ) {
				$css = $settings['custom_css'];
				if ( preg_match( '/float\s*:\s*left/i', $css ) ) {
					$warnings[] = "{$path}: custom_css contains 'float: left' — may need 'float: right' for RTL";
				}
				if ( preg_match( '/margin-left\s*:/i', $css ) && ! preg_match( '/margin-right\s*:/i', $css ) ) {
					$warnings[] = "{$path}: custom_css has margin-left without margin-right — may need mirroring for RTL";
				}
				if ( preg_match( '/padding-left\s*:/i', $css ) && ! preg_match( '/padding-right\s*:/i', $css ) ) {
					$warnings[] = "{$path}: custom_css has padding-left without padding-right — may need mirroring for RTL";
				}
			}

			// Recurse into children.
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				foreach ( $el['elements'] as $idx => $child ) {
					$child_type = isset( $child['elType'] ) ? $child['elType'] : 'element';
					$walk( $child, $path . '.' . $child_type . "[{$idx}]" );
				}
			}
		};

		foreach ( $elements as $idx => $el ) {
			$top_type = isset( $el['elType'] ) ? $el['elType'] : 'element';
			$walk( $el, "{$top_type}[{$idx}]" );
		}

		return $warnings;
	}
}
