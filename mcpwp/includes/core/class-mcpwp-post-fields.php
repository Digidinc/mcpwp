<?php
/**
 * `terms` and `meta` on create and update — the fields that turn a custom
 * post type from creatable into fillable (#797, #803 item 4).
 *
 * Before this, a post could be created but not classified or filled: only
 * `categories` and `tags` were accepted, so a custom taxonomy had no write
 * path at all, and every meta field cost a separate call through a route
 * that ran values through sanitize_text_field() — which strips the `%xx`
 * octets out of a URL and the ampersand out of a job title.
 *
 * Two rules, both from the house failure mode:
 *
 *   - Validate everything, then write. An unknown taxonomy, a taxonomy the
 *     type does not have, an unknown term, a refused meta key: each is a 400
 *     before the post itself is touched. A 200 with a silent skip is the bug
 *     this exists to remove.
 *
 *   - Report what landed, resolved. Terms come back as {id, slug, name};
 *     meta comes back as the keys written.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Terms and meta on post writes.
 */
class Mcpwp_Post_Fields {

	/**
	 * Meta key prefixes never writable through a post write.
	 *
	 * @var array
	 */
	private static $blocked_prefixes = array( '_wp_', '_elementor', '_edit_', 'mcpwp_', '_mcpwp_', '_oembed_' );

	/**
	 * Meta keys never writable through a post write, whatever the prefix rule
	 * says. `_thumbnail_id` has its own route with its own validation.
	 *
	 * @var array
	 */
	private static $blocked_keys = array( '_thumbnail_id', '_wp_page_template', '_edit_lock', '_edit_last' );

	/**
	 * Core keys the dedicated meta route may set on request (it has always
	 * allowed these two). A post write uses featured_image / template instead.
	 *
	 * @var array
	 */
	private static $core_keys_by_request = array( '_thumbnail_id', '_wp_page_template' );

	// ---------------------------------------------------------------
	// Terms.
	// ---------------------------------------------------------------

	/**
	 * Largest lists one write accepts. One request is one rate-limit slot;
	 * a 2,000-key meta map or a 5,000-term list must not ride on it.
	 */
	const MAX_META_KEYS       = 100;
	const MAX_TERMS_PER_TAX   = 100;

	/**
	 * Resolve `terms` against the registry without writing anything.
	 *
	 * @param mixed  $terms     Submitted {taxonomy: [ids|slugs|names]}.
	 * @param string $post_type Post type the terms are for.
	 * @param array  $data      The whole submitted field set, to refuse a
	 *                          taxonomy named twice (categories + terms.category).
	 * @return array|WP_Error {taxonomy: [term ids]} or the first refusal.
	 */
	public static function resolve_terms( $terms, $post_type, array $data = array() ) {
		if ( null === $terms ) {
			return array();
		}

		if ( ! is_array( $terms ) ) {
			return new WP_Error(
				'invalid_terms',
				__( 'terms must be an object of {taxonomy: [term ids, slugs or names]}.', 'mcpwp' ),
				array( 'status' => 400 )
			);
		}

		$resolved = array();

		// categories/tags and terms.category/terms.post_tag write the same
		// taxonomy; the later one would silently win. Refuse the overlap.
		foreach ( array( 'categories' => 'category', 'tags' => 'post_tag' ) as $field => $tax ) {
			if ( isset( $data[ $field ] ) && isset( $terms[ $tax ] ) ) {
				return new WP_Error(
					'conflicting_terms',
					sprintf(
						/* translators: 1: field 2: taxonomy */
						__( '%1$s and terms.%2$s both set the same taxonomy. Send one of them. Nothing was written.', 'mcpwp' ),
						$field,
						$tax
					),
					array( 'status' => 400 )
				);
			}
		}

		foreach ( $terms as $taxonomy => $wanted ) {
			$taxonomy = sanitize_key( (string) $taxonomy );

			if ( is_array( $wanted ) && count( $wanted ) > self::MAX_TERMS_PER_TAX ) {
				return new WP_Error(
					'too_many_terms',
					sprintf(
						/* translators: 1: taxonomy 2: cap */
						__( 'terms.%1$s lists more than %2$d entries. Split the write.', 'mcpwp' ),
						$taxonomy,
						self::MAX_TERMS_PER_TAX
					),
					array( 'status' => 400 )
				);
			}

			if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
				return new WP_Error(
					'unknown_taxonomy',
					sprintf(
						/* translators: %s: taxonomy name */
						__( 'Taxonomy "%s" is not registered. Nothing was written.', 'mcpwp' ),
						$taxonomy
					),
					array(
						'status'     => 400,
						'taxonomy'   => $taxonomy,
						'registered' => function_exists( 'get_object_taxonomies' ) ? array_values( (array) get_object_taxonomies( $post_type ) ) : array(),
					)
				);
			}

			if ( ! is_object_in_taxonomy( $post_type, $taxonomy ) ) {
				return new WP_Error(
					'taxonomy_not_for_post_type',
					sprintf(
						/* translators: 1: taxonomy 2: post type */
						__( 'Taxonomy "%1$s" is not attached to post type "%2$s". Nothing was written.', 'mcpwp' ),
						$taxonomy,
						$post_type
					),
					array(
						'status'     => 400,
						'taxonomy'   => $taxonomy,
						'post_type'  => $post_type,
						'registered' => array_values( (array) get_object_taxonomies( $post_type ) ),
					)
				);
			}

			$ids     = array();
			$unknown = array();

			foreach ( (array) $wanted as $ref ) {
				$term = self::find_term( $ref, $taxonomy );

				if ( ! $term ) {
					$unknown[] = $ref;
					continue;
				}

				$ids[] = (int) $term->term_id;
			}

			if ( ! empty( $unknown ) ) {
				return new WP_Error(
					'unknown_term',
					sprintf(
						/* translators: 1: taxonomy 2: the references that did not resolve */
						__( 'No term in "%1$s" matches: %2$s. Nothing was written.', 'mcpwp' ),
						$taxonomy,
						implode( ', ', array_map( 'strval', $unknown ) )
					),
					array(
						'status'   => 400,
						'taxonomy' => $taxonomy,
						'unknown'  => array_values( $unknown ),
						'hint'     => 'An id must be a JSON number; a quoted value is always a slug or a name (so "2024" is the slug "2024", never term id 2024). Use wp_list_categories / wp_list_tags / the taxonomy listing tools to find them, or wp_create_term to create one.',
					)
				);
			}

			$resolved[ $taxonomy ] = array_values( array_unique( $ids ) );
		}

		return $resolved;
	}

	/**
	 * Write already-resolved terms. Replaces each taxonomy's set, like
	 * categories and tags do.
	 *
	 * @param int   $post_id  Post.
	 * @param array $resolved From resolve_terms().
	 * @return array {taxonomy: [{id, slug, name}]} as stored.
	 */
	public static function apply_terms( $post_id, array $resolved ) {
		$applied = array();

		foreach ( $resolved as $taxonomy => $ids ) {
			wp_set_object_terms( $post_id, $ids, $taxonomy, false );

			$applied[ $taxonomy ] = self::describe_terms( $post_id, $taxonomy );
		}

		return $applied;
	}

	/**
	 * @param int    $post_id  Post.
	 * @param string $taxonomy Taxonomy.
	 * @return array [{id, slug, name}]
	 */
	public static function describe_terms( $post_id, $taxonomy ) {
		$terms = wp_get_object_terms( $post_id, $taxonomy );
		$out   = array();

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return $out;
		}

		foreach ( $terms as $term ) {
			$out[] = array(
				'id'   => (int) $term->term_id,
				'slug' => (string) $term->slug,
				'name' => (string) $term->name,
			);
		}

		return $out;
	}

	/**
	 * @param mixed  $ref      Id, slug or name.
	 * @param string $taxonomy Taxonomy.
	 * @return object|null
	 */
	private static function find_term( $ref, $taxonomy ) {
		// An integer is an id. A string is a slug or a name — even when it
		// is all digits: a "2024" year slug must not silently resolve to
		// whichever term happens to carry id 2024.
		if ( is_int( $ref ) ) {
			$term = get_term( $ref, $taxonomy );
			return ( $term && ! is_wp_error( $term ) ) ? $term : null;
		}

		if ( ! is_string( $ref ) || '' === $ref ) {
			return null;
		}

		$term = get_term_by( 'slug', $ref, $taxonomy );
		if ( ! $term ) {
			$term = get_term_by( 'name', $ref, $taxonomy );
		}

		return $term ? $term : null;
	}

	// ---------------------------------------------------------------
	// Meta.
	// ---------------------------------------------------------------

	/**
	 * Check `meta` without writing anything.
	 *
	 * @param mixed $meta Submitted {key: value}.
	 * @return array|WP_Error Cleaned {key: value} or the first refusal.
	 */
	public static function validate_meta( $meta, $allow_core_keys = false ) {
		if ( null === $meta ) {
			return array();
		}

		if ( ! is_array( $meta ) ) {
			return new WP_Error(
				'invalid_meta',
				__( 'meta must be an object of {key: value}.', 'mcpwp' ),
				array( 'status' => 400 )
			);
		}

		if ( count( $meta ) > self::MAX_META_KEYS ) {
			return new WP_Error(
				'too_many_meta_keys',
				sprintf(
					/* translators: %d: cap */
					__( 'meta carries more than %d keys. Split the write.', 'mcpwp' ),
					self::MAX_META_KEYS
				),
				array( 'status' => 400 )
			);
		}

		$clean = array();

		foreach ( $meta as $key => $value ) {
			$key = is_string( $key ) ? trim( $key ) : '';

			if ( '' === $key || strlen( $key ) > 255 || ! preg_match( '/^[A-Za-z0-9_\-.:]+$/', $key ) ) {
				return new WP_Error(
					'invalid_meta_key',
					sprintf(
						/* translators: %s: the key */
						__( 'Meta key "%s" is not usable. Nothing was written.', 'mcpwp' ),
						$key
					),
					array( 'status' => 400 )
				);
			}

			$reason = ( $allow_core_keys && in_array( $key, self::$core_keys_by_request, true ) ) ? null : self::refusal_reason( $key );
			if ( null !== $reason ) {
				return new WP_Error(
					'forbidden_meta_key',
					sprintf(
						/* translators: 1: key 2: reason */
						__( 'Meta key "%1$s" cannot be written through a post write: %2$s. Nothing was written.', 'mcpwp' ),
						$key,
						$reason
					),
					array(
						'status' => 400,
						'key'    => $key,
					)
				);
			}

			if ( ! is_scalar( $value ) && ! is_array( $value ) && null !== $value ) {
				return new WP_Error(
					'invalid_meta_value',
					sprintf(
						/* translators: %s: key */
						__( 'Meta "%s" must be a scalar or an array/object.', 'mcpwp' ),
						$key
					),
					array( 'status' => 400 )
				);
			}

			$clean[ $key ] = $value;
		}

		return $clean;
	}

	/**
	 * Write validated meta.
	 *
	 * Registered meta is handed to WordPress as sent: update_post_meta() runs
	 * the key's registered sanitize_callback, which is what the plugin that
	 * registered it asked for. Unregistered strings are reduced to what a
	 * post body may carry (wp_kses_post) — never sanitize_text_field(),
	 * which strips %xx octets and eats ampersands.
	 *
	 * @param int    $post_id   Post.
	 * @param string $post_type Post type (registered meta is per subtype).
	 * @param array  $meta      From validate_meta().
	 * @param array  $deleted   Keys removed (null values), by reference.
	 * @return array Keys written, in order.
	 */
	public static function apply_meta( $post_id, $post_type, array $meta, array &$deleted = null ) {
		$written = array();
		$deleted = array();

		foreach ( $meta as $key => $value ) {
			// "Registered" only buys raw storage when the registration brought
			// its own sanitize_callback: that is the plugin asking to own the
			// value. A key registered for show_in_rest with no callback would
			// otherwise store <script> verbatim while post_content in the same
			// request is kses'd.
			if ( ! self::has_registered_sanitizer( $key, $post_type ) ) {
				$value = self::reduce_unregistered( $value );
			}

			if ( null === $value ) {
				delete_post_meta( $post_id, $key );
				$deleted[] = $key;
				continue;
			}

			update_post_meta( $post_id, $key, $value );
			$written[] = $key;
		}

		return $written;
	}

	/**
	 * Case-preserving key sanitiser: registered meta keys are case-sensitive.
	 *
	 * @param mixed $key Raw key.
	 * @return string
	 */
	public static function sanitize_meta_key( $key ) {
		return trim( preg_replace( '/[^A-Za-z0-9_\-.:]/', '', (string) $key ) );
	}

	/**
	 * @param string $key       Meta key.
	 * @param string $post_type Post type (registrations are per subtype).
	 * @return bool
	 */
	public static function has_registered_sanitizer( $key, $post_type ) {
		if ( ! function_exists( 'get_registered_meta_keys' ) ) {
			return false;
		}

		foreach ( array( (string) $post_type, '' ) as $subtype ) {
			$keys = get_registered_meta_keys( 'post', $subtype );
			if ( is_array( $keys ) && isset( $keys[ $key ] ) && is_array( $keys[ $key ] ) && ! empty( $keys[ $key ]['sanitize_callback'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string $key Meta key.
	 * @return string|null Reason, or null when writable.
	 */
	private static function refusal_reason( $key ) {
		if ( in_array( $key, self::$blocked_keys, true ) ) {
			return '_thumbnail_id' === $key ? 'use featured_image' : 'reserved by WordPress';
		}

		$lower = strtolower( $key );
		foreach ( self::$blocked_prefixes as $prefix ) {
			// Case-insensitive: wp_postmeta.meta_key collates case-insensitively,
			// so MCPWP_x and mcpwp_x are one row.
			if ( 0 === strpos( $lower, strtolower( $prefix ) ) ) {
				if ( '_elementor' === $prefix ) {
					return 'use the Elementor tools';
				}
				return 'reserved';
			}
		}

		if ( function_exists( 'is_protected_meta' ) && is_protected_meta( $key, 'post' ) ) {
			return 'protected meta (leading underscore)';
		}

		return null;
	}

	/**
	 * @param mixed $value Value for an unregistered key.
	 * @return mixed
	 */
	private static function reduce_unregistered( $value ) {
		if ( is_string( $value ) ) {
			// Plain text is stored as sent. wp_kses_post() on "Founder & CEO"
			// yields "Founder &amp; CEO" — an entity-normalised copy of a
			// string that never contained markup. Only strings that carry
			// tags are reduced.
			if ( false === strpos( $value, '<' ) ) {
				return function_exists( 'wp_check_invalid_utf8' ) ? wp_check_invalid_utf8( $value ) : $value;
			}
			return wp_kses_post( $value );
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = self::reduce_unregistered( $v );
			}
		}

		return $value;
	}
}
