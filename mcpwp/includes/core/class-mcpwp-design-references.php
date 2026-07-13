<?php
/**
 * Design Reference Library
 *
 * Stores uploaded design references that models can reuse when building
 * Elementor pages, page archetypes, and parts.
 *
 * @package mumcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Design reference manager.
 */
class Mcpwp_Design_References {

	use Mcpwp_Sanitization;

	/**
	 * Option key used for stored references.
	 *
	 * @var string
	 */
	private $option_key = 'mcpwp_design_references';

	/**
	 * Media handler.
	 *
	 * @var Mcpwp_Media
	 */
	private $media;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->media = new Mcpwp_Media();
	}

	/**
	 * List stored design references.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public function list_references( $args = array() ) {
		$items = array_values( $this->load_references() );

		$query           = isset( $args['query'] ) ? sanitize_text_field( (string) $args['query'] ) : '';
		$page_intent     = isset( $args['page_intent'] ) ? sanitize_key( (string) $args['page_intent'] ) : '';
		$archetype_class = isset( $args['archetype_class'] ) ? sanitize_key( (string) $args['archetype_class'] ) : '';
		$style           = isset( $args['style'] ) ? sanitize_text_field( (string) $args['style'] ) : '';
		$source_type     = isset( $args['source_type'] ) ? sanitize_key( (string) $args['source_type'] ) : '';
		$per_page        = max( 1, min( 100, absint( $args['per_page'] ?? 20 ) ) );
		$page            = max( 1, absint( $args['page'] ?? 1 ) );

		$items = array_values(
			array_filter(
				$items,
				function ( $item ) use ( $query, $page_intent, $archetype_class, $style, $source_type ) {
					if ( $page_intent && $page_intent !== ( $item['page_intent'] ?? '' ) ) {
						return false;
					}

					if ( $archetype_class && $archetype_class !== ( $item['archetype_class'] ?? '' ) ) {
						return false;
					}

					if ( $style && 0 !== strcasecmp( $style, (string) ( $item['style'] ?? '' ) ) ) {
						return false;
					}

					if ( $source_type && $source_type !== ( $item['source_type'] ?? '' ) ) {
						return false;
					}

					if ( '' === $query ) {
						return true;
					}

					$haystack = implode(
						' ',
						array(
							(string) ( $item['title'] ?? '' ),
							(string) ( $item['notes'] ?? '' ),
							(string) ( $item['analysis_summary'] ?? '' ),
							implode( ' ', $item['tags'] ?? array() ),
							implode( ' ', $item['must_keep'] ?? array() ),
							implode( ' ', $item['avoid'] ?? array() ),
						)
					);

					return false !== stripos( $haystack, $query );
				}
			)
		);

		usort(
			$items,
			function ( $a, $b ) {
				return strcmp( (string) ( $b['updated_at'] ?? '' ), (string) ( $a['updated_at'] ?? '' ) );
			}
		);

		$total      = count( $items );
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$offset     = ( $page - 1 ) * $per_page;

		return array(
			'references' => array_slice( $items, $offset, $per_page ),
			'total'      => $total,
			'pages'      => $total_pages,
			'page'       => $page,
			'per_page'   => $per_page,
		);
	}

	/**
	 * Get a single reference by ID.
	 *
	 * @param string $id Reference ID.
	 * @return array|WP_Error
	 */
	public function get_reference( $id ) {
		$id    = sanitize_key( (string) $id );
		$items = $this->load_references();

		if ( empty( $items[ $id ] ) ) {
			return new WP_Error( 'not_found', __( 'Design reference not found.', 'mumega-mcp' ), array( 'status' => 404 ) );
		}

		return $items[ $id ];
	}

	/**
	 * Create a reference from existing media, URL, or base64 image data.
	 *
	 * @param array $data Reference payload.
	 * @return array|WP_Error
	 */
	public function create_reference( $data ) {
		$media_result = $this->resolve_reference_media( $data );
		if ( is_wp_error( $media_result ) ) {
			return $media_result;
		}

		$title      = isset( $data['title'] ) ? sanitize_text_field( (string) $data['title'] ) : '';
		$created_at = current_time( 'mysql', true );
		$id         = 'dref_' . strtolower( wp_generate_password( 12, false, false ) );

		$item = array(
			'id'               => $id,
			'title'            => $title ? $title : ( $media_result['title'] ?? 'Design Reference' ),
			'media_id'         => (int) $media_result['id'],
			'image_url'        => $media_result['url'] ?? '',
			'mime_type'        => $media_result['mime_type'] ?? '',
			'width'            => isset( $media_result['width'] ) ? (int) $media_result['width'] : 0,
			'height'           => isset( $media_result['height'] ) ? (int) $media_result['height'] : 0,
			'source_type'      => $this->normalize_source_type( $data['source_type'] ?? $media_result['source_type'] ?? 'upload' ),
			'page_intent'      => sanitize_key( (string) ( $data['page_intent'] ?? '' ) ),
			'archetype_class'  => sanitize_key( (string) ( $data['archetype_class'] ?? '' ) ),
			'style'            => sanitize_text_field( (string) ( $data['style'] ?? '' ) ),
			'notes'            => sanitize_textarea_field( (string) ( $data['notes'] ?? '' ) ),
			'analysis_summary' => sanitize_textarea_field( (string) ( $data['analysis_summary'] ?? '' ) ),
			'tags'             => $this->sanitize_string_list( $data['tags'] ?? array() ),
			'must_keep'        => $this->sanitize_string_list( $data['must_keep'] ?? array() ),
			'avoid'            => $this->sanitize_string_list( $data['avoid'] ?? array() ),
			'section_outline'  => $this->sanitize_string_list( $data['section_outline'] ?? array() ),
			'linked_archetype_ids' => $this->sanitize_integer_list( $data['linked_archetype_ids'] ?? array() ),
			'linked_part_ids'      => $this->sanitize_integer_list( $data['linked_part_ids'] ?? array() ),
			'provenance'       => $this->sanitize_assoc_string_map( $data['provenance'] ?? array() ),
			'created_at'       => $created_at,
			'updated_at'       => $created_at,
			'created_by'       => get_current_user_id(),
		);

		$items         = $this->load_references();
		$items[ $id ]  = $item;
		$this->save_references( $items );

		return $item;
	}

	/**
	 * Update an existing design reference.
	 *
	 * @param string $id   Reference ID.
	 * @param array  $data Partial payload.
	 * @return array|WP_Error
	 */
	public function update_reference( $id, $data ) {
		$id    = sanitize_key( (string) $id );
		$items = $this->load_references();

		if ( empty( $items[ $id ] ) ) {
			return new WP_Error( 'not_found', __( 'Design reference not found.', 'mumega-mcp' ), array( 'status' => 404 ) );
		}

		$item = $items[ $id ];

		if ( isset( $data['media_id'] ) || isset( $data['image_url'] ) || isset( $data['image_base64'] ) ) {
			$media_result = $this->resolve_reference_media( $data );
			if ( is_wp_error( $media_result ) ) {
				return $media_result;
			}

			$item['media_id']    = (int) $media_result['id'];
			$item['image_url']   = $media_result['url'] ?? '';
			$item['mime_type']   = $media_result['mime_type'] ?? '';
			$item['width']       = isset( $media_result['width'] ) ? (int) $media_result['width'] : 0;
			$item['height']      = isset( $media_result['height'] ) ? (int) $media_result['height'] : 0;
			$item['source_type'] = $this->normalize_source_type( $data['source_type'] ?? $media_result['source_type'] ?? $item['source_type'] );
		} elseif ( isset( $data['source_type'] ) ) {
			$item['source_type'] = $this->normalize_source_type( $data['source_type'] );
		}

		$string_fields = array(
			'title'            => 'sanitize_text_field',
			'page_intent'      => 'sanitize_key',
			'archetype_class'  => 'sanitize_key',
			'style'            => 'sanitize_text_field',
			'notes'            => 'sanitize_textarea_field',
			'analysis_summary' => 'sanitize_textarea_field',
		);

		foreach ( $string_fields as $field => $sanitizer ) {
			if ( array_key_exists( $field, $data ) ) {
				$item[ $field ] = call_user_func( $sanitizer, (string) $data[ $field ] );
			}
		}

		if ( array_key_exists( 'tags', $data ) ) {
			$item['tags'] = $this->sanitize_string_list( $data['tags'] );
		}
		if ( array_key_exists( 'must_keep', $data ) ) {
			$item['must_keep'] = $this->sanitize_string_list( $data['must_keep'] );
		}
		if ( array_key_exists( 'avoid', $data ) ) {
			$item['avoid'] = $this->sanitize_string_list( $data['avoid'] );
		}
		if ( array_key_exists( 'section_outline', $data ) ) {
			$item['section_outline'] = $this->sanitize_string_list( $data['section_outline'] );
		}
		if ( array_key_exists( 'linked_archetype_ids', $data ) ) {
			$item['linked_archetype_ids'] = $this->sanitize_integer_list( $data['linked_archetype_ids'] );
		}
		if ( array_key_exists( 'linked_part_ids', $data ) ) {
			$item['linked_part_ids'] = $this->sanitize_integer_list( $data['linked_part_ids'] );
		}
		if ( array_key_exists( 'provenance', $data ) ) {
			$item['provenance'] = $this->sanitize_assoc_string_map( $data['provenance'] );
		}

		$item['updated_at'] = current_time( 'mysql', true );
		$items[ $id ]       = $item;
		$this->save_references( $items );

		return $item;
	}

	/**
	 * Load references from persistent storage.
	 *
	 * @return array
	 */
	private function load_references() {
		$items = get_option( $this->option_key, array() );
		return is_array( $items ) ? $items : array();
	}

	/**
	 * Persist references.
	 *
	 * @param array $items Reference list.
	 */
	private function save_references( $items ) {
		update_option( $this->option_key, $items, false );
	}

	/**
	 * Resolve the attachment used by a design reference.
	 *
	 * @param array $data Request payload.
	 * @return array|WP_Error
	 */
	private function resolve_reference_media( $data ) {
		if ( ! empty( $data['media_id'] ) ) {
			return $this->get_attachment_payload( (int) $data['media_id'], 'media' );
		}

		if ( ! empty( $data['image_url'] ) ) {
			$result = $this->media->upload_from_url(
				(string) $data['image_url'],
				array(
					'title'    => $data['title'] ?? '',
					'alt'      => $data['title'] ?? '',
					'filename' => $data['filename'] ?? '',
				)
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$result['source_type'] = 'url';
			return $result;
		}

		if ( ! empty( $data['image_base64'] ) ) {
			$filename = isset( $data['filename'] ) ? sanitize_file_name( (string) $data['filename'] ) : '';
			if ( '' === $filename ) {
				return new WP_Error( 'missing_filename', __( 'filename is required when uploading image_base64.', 'mumega-mcp' ), array( 'status' => 400 ) );
			}

			$result = $this->media->upload_from_base64(
				(string) $data['image_base64'],
				$filename,
				array(
					'title' => $data['title'] ?? '',
					'alt'   => $data['title'] ?? '',
				)
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$result['source_type'] = 'base64';
			return $result;
		}

		return new WP_Error(
			'missing_image',
			__( 'Provide media_id, image_url, or image_base64 to create a design reference. Retry tip: if image_url or image_base64 upload fails, upload the image first with wp_upload_media_from_url or wp_upload_media_b64, then call this again with the returned media_id.', 'mumega-mcp' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Build a lightweight attachment payload.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $source_type Source label.
	 * @return array|WP_Error
	 */
	private function get_attachment_payload( $attachment_id, $source_type ) {
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error( 'invalid_media', __( 'media_id must reference an existing attachment.', 'mumega-mcp' ), array( 'status' => 400 ) );
		}

		$mime = get_post_mime_type( $attachment_id );
		if ( 0 !== strpos( (string) $mime, 'image/' ) ) {
			return new WP_Error( 'invalid_media_type', __( 'Design references currently require an image attachment.', 'mumega-mcp' ), array( 'status' => 400 ) );
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );

		return array(
			'id'          => $attachment_id,
			'title'       => $attachment->post_title,
			'url'         => wp_get_attachment_url( $attachment_id ),
			'mime_type'   => $mime,
			'width'       => isset( $metadata['width'] ) ? (int) $metadata['width'] : 0,
			'height'      => isset( $metadata['height'] ) ? (int) $metadata['height'] : 0,
			'source_type' => $source_type,
		);
	}

	/**
	 * Sanitize source type.
	 *
	 * @param string $value Raw source type.
	 * @return string
	 */
	private function normalize_source_type( $value ) {
		$value   = sanitize_key( (string) $value );
		$allowed = array( 'upload', 'url', 'base64', 'media', 'figma', 'stitch', 'manual' );
		return in_array( $value, $allowed, true ) ? $value : 'upload';
	}

	/**
	 * Sanitize a list of strings.
	 *
	 * @param mixed $value Raw value.
	 * @return array
	 */
	private function sanitize_string_list( $value ) {
		if ( ! is_array( $value ) ) {
			$value = array_filter( array_map( 'trim', explode( "\n", (string) $value ) ) );
		}

		$value = array_map(
			function ( $item ) {
				return sanitize_text_field( (string) $item );
			},
			(array) $value
		);

		$value = array_values( array_filter( $value, 'strlen' ) );
		return array_values( array_unique( $value ) );
	}

	/**
	 * Sanitize a list of integers.
	 *
	 * @param mixed $value Raw value.
	 * @return array
	 */
	private function sanitize_integer_list( $value ) {
		if ( ! is_array( $value ) ) {
			$value = array( $value );
		}

		$value = array_map( 'absint', $value );
		$value = array_values( array_filter( $value ) );
		return array_values( array_unique( $value ) );
	}

	/**
	 * Sanitize an associative map of scalar strings.
	 *
	 * @param mixed $value Raw value.
	 * @return array
	 */
	private function sanitize_assoc_string_map( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$result = array();
		foreach ( $value as $key => $item ) {
			$clean_key = sanitize_key( (string) $key );
			if ( '' === $clean_key || is_array( $item ) || is_object( $item ) ) {
				continue;
			}

			$result[ $clean_key ] = sanitize_text_field( (string) $item );
		}

		return $result;
	}
}
