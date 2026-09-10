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
	private static $blocked_prefixes = array( '_wp_', '_elementor', '_edit_', 'mcpwp_', '_mcpwp_', '_oembed_', '_menu_item_', '_transient', '_site_transient', '_customize_changeset', '_pingme', '_encloseme', '_downloadable_files' );

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
	 * Bound on one meta value, measured as its JSON encoding. MAX_META_KEYS
	 * counts top-level keys only; without this a single key could carry a
	 * request-body-sized blob through a one-slot write (#797 round 3).
	 */
	const MAX_META_VALUE_BYTES = 65536;

	/**
	 * Bound on the taxonomies one write may touch. Each must exist and be
	 * attached to the type, so this is belt-and-braces for MAX_TERMS_PER_TAX.
	 */
	const MAX_TAXONOMIES = 20;

	/**
	 * A meta key that names a credential. Post meta is not a secret store: it
	 * is world-readable to every plugin, exported with the post, and shown in
	 * the Custom Fields box. Refusing the write is the one place this can be
	 * held for both routes (#797 round 3, P1-A).
	 *
	 * Delegates to the plugin-wide detector when it is loaded so the meta
	 * surface and the option surface agree on what a secret is called.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public static function is_secret_shaped_key( $key ) {
		$key = (string) $key;

		if ( '' === $key ) {
			return false;
		}

		// Post meta keys have their own detector, deliberately NOT unioned
		// with the option-name detector: option names carry stems (key,
		// salt, nonce, pin, license, signature) that are ordinary in meta —
		// meta_key, cache_key, license_url, nonce_field, pin_code — and the
		// union refused all of them on a real install while the harness,
		// which never defined MCPWP_PLUGIN_DIR, never exercised it (#797
		// follow-up).
		// camelCase is split and every separator the key sanitiser admits
		// (- . :) reads as _, so apiKey, x-api-key, api.key and api:key all
		// match like api_key. A numeric suffix is still the same name
		// (apikey2, token2).
		$split = strtolower( preg_replace( '/([a-z0-9])([A-Z])/', '$1_$2', $key ) );
		$split = str_replace( array( '-', '.', ':', ' ' ), '_', $split );

		// Word-boundary stems: a credential NAMED, not merely mentioned.
		if ( preg_match( '/(^|_)(api_?key|apikey|access_?key(_id)?|secret_?key|sk|pass|passwd|password|passcode|pwd|passphrase|secret|secrets|token|tokens|credential|credentials|bearer|jwt|priv_?key|private_?key|privkey|session_?id|session_?token|session_?key|totp|otp|cvv|recovery_?codes?|seed_?phrase|dsn|licen[cs]e_?key|consumer_?key|encryption_?key|master_?key|signing_?key|kms_?key|ssh_?key|gpg_?key|htpasswd|keystore|account_?sid|activation_?code|p12|pem)\d*(_|$)/i', $split ) ) {
			return true;
		}

		// Substring stems that are credentials wherever they appear:
		// password, credential, secret, token, and auth (authorization,
		// oauth_*, reauth, authenticator) — but not author*, authoring,
		// authority. This is the set the read side always filtered; the
		// write side is never looser than it.
		return (bool) preg_match( '/(password|credential|secret|token|auth(?!or(?:s|ed|ing|ity|ship|_|$)))/i', $split );
	}


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

		if ( count( $terms ) > self::MAX_TAXONOMIES ) {
			return new WP_Error(
				'too_many_taxonomies',
				sprintf(
					/* translators: %d: cap */
					__( 'terms names more than %d taxonomies. Split the write.', 'mcpwp' ),
					self::MAX_TAXONOMIES
				),
				array( 'status' => 400 )
			);
		}

		// The overlap check looks at the NORMALISED taxonomy names — the
		// same sanitize_key() the loop below applies — so terms.Category or
		// terms["category."] cannot slip past it (#797 round 3, P2-A).
		$normalised = array();
		foreach ( array_keys( $terms ) as $raw ) {
			$clean = sanitize_key( (string) $raw );
			if ( isset( $normalised[ $clean ] ) ) {
				// Two spellings of one taxonomy: the later would silently win
				// (#797 round 4, P2-837a).
				return new WP_Error(
					'conflicting_terms',
					sprintf(
						/* translators: %s: taxonomy */
						__( 'terms names taxonomy "%s" more than once under different spellings. Send it once. Nothing was written.', 'mcpwp' ),
						$clean
					),
					array( 'status' => 400 )
				);
			}
			$normalised[ $clean ] = true;
		}

		// categories/tags and terms.category/terms.post_tag write the same
		// taxonomy; the later one would silently win. Refuse the overlap.
		foreach ( array( 'categories' => 'category', 'tags' => 'post_tag' ) as $field => $tax ) {
			if ( isset( $data[ $field ] ) && isset( $normalised[ $tax ] ) ) {
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
	public static function validate_meta( $meta, $allow_core_keys = false, $post_type = '' ) {
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

			// A key with a NUL or a bad byte is refused like a value with one;
			// the character class below would refuse it anyway, but by name.
			if ( '' !== $key && null !== self::first_invalid_utf8_path( $key ) ) {
				return new WP_Error(
					'invalid_meta_key',
					__( 'A meta key carries a NUL byte or invalid UTF-8. Nothing was written.', 'mcpwp' ),
					array( 'status' => 400 )
				);
			}

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

			$reason = ( $allow_core_keys && in_array( $key, self::$core_keys_by_request, true ) ) ? null : self::refusal_reason( $key, $allow_core_keys );
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

			if ( null !== $value ) {
				$bad = self::first_invalid_utf8_path( $value );
				if ( null !== $bad ) {
					return new WP_Error(
						'invalid_meta_value',
						sprintf(
							/* translators: 1: key 2: path */
							__( 'Meta "%1$s" carries invalid UTF-8 at %2$s. Nothing was written.', 'mcpwp' ),
							$key,
							'' === $bad ? '(value)' : $bad
						),
						array( 'status' => 400, 'key' => $key )
					);
				}
			}

			$encoded = null !== $value ? wp_json_encode( $value ) : '';
			if ( false === $encoded ) {
				return new WP_Error(
					'invalid_meta_value',
					sprintf(
						/* translators: %s: key */
						__( 'Meta "%s" cannot be encoded. Nothing was written.', 'mcpwp' ),
						$key
					),
					array( 'status' => 400, 'key' => $key )
				);
			}

			// Measured as sent AND as it would be stored — through the
			// registered sanitiser when there is one, through the fallback
			// reduction when there is not (#797 rounds 5–6). kses entity
			// normalisation can grow a value ~6x past the bound.
			$as_stored   = null;
			if ( null !== $value ) {
				$as_stored = ( '' !== $post_type && self::has_registered_sanitizer( $key, $post_type ) && function_exists( 'sanitize_meta' ) )
					? sanitize_meta( $key, $value, 'post', $post_type )
					: self::reduce_unregistered( $value );
			}
			$stored_size = null !== $as_stored ? strlen( (string) wp_json_encode( $as_stored ) ) : 0;
			if ( null !== $value && max( strlen( (string) $encoded ), $stored_size ) > self::MAX_META_VALUE_BYTES ) {
				return new WP_Error(
					'meta_value_too_large',
					sprintf(
						/* translators: 1: key 2: byte cap */
						__( 'Meta "%1$s" is larger than %2$d bytes. Store large data as an attachment or split it. Nothing was written.', 'mcpwp' ),
						$key,
						self::MAX_META_VALUE_BYTES
					),
					array( 'status' => 400, 'key' => $key )
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
	public static function apply_meta( $post_id, $post_type, array $meta, ?array &$deleted = null ) {
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
	 * Path of the first string in a value that is not valid UTF-8, or null.
	 * wp_check_invalid_utf8() turns such a string into '' — which would then
	 * be stored and reported as written (#797 round 4, P2-837c).
	 *
	 * @param mixed  $value Value.
	 * @param string $path  Path so far.
	 * @return string|null
	 */
	private static function first_invalid_utf8_path( $value, $path = '' ) {
		if ( is_string( $value ) ) {
			$valid = function_exists( 'mb_check_encoding' ) ? mb_check_encoding( $value, 'UTF-8' ) : ( false !== @iconv( 'UTF-8', 'UTF-8//IGNORE', $value ) && @iconv( 'UTF-8', 'UTF-8//IGNORE', $value ) === $value );
			// A raw NUL is valid UTF-8 and still not text anyone means to store.
			return $valid && false === strpos( $value, "\0" ) ? null : $path;
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$here = '' === $path ? (string) $k : $path . '.' . $k;
				// Keys are bytes too (#797 round 6).
				if ( is_string( $k ) && null !== self::first_invalid_utf8_path( $k ) ) {
					return $here;
				}
				$found = self::first_invalid_utf8_path( $v, $here );
				if ( null !== $found ) {
					return $found;
				}
			}
		}

		return null;
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
	private static function refusal_reason( $key, $explicit_meta_route = false ) {
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

		// The explicit meta route is the door for _yoast_wpseo_*, _price,
		// _sku, _acf_*: a leading underscore is WordPress's "not in the
		// Custom Fields box", not "not yours". A post write's meta field
		// keeps the rule, since it rides beside title/content and a stray
		// key there is far more likely an accident (#797 round 5).
		if ( ! $explicit_meta_route && function_exists( 'is_protected_meta' ) && is_protected_meta( $key, 'post' ) ) {
			return 'protected meta (leading underscore); use wp_set_post_meta';
		}

		if ( self::is_secret_shaped_key( $key ) ) {
			return 'credential-shaped key; post meta is not a secret store';
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
