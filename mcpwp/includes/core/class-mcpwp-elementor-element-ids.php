<?php
/**
 * The one place that knows what an Elementor element id must look like.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates, audits and (on request) repairs Elementor element ids (#842).
 *
 * Elementor Pro validates `widget_id` on its REST endpoints with
 * Refresh_Base::is_widget_id_valid() (core/data/endpoints/refresh-base.php):
 *
 *   preg_match( '/^[a-zA-Z0-9]+$/', $widget_id ) && strlen( $widget_id ) === 7
 *
 * Two endpoints use it. `refresh-search` receives the Search widget's own id
 * (live results). `refresh-loop` receives the id of the Loop Grid a Taxonomy
 * Filter's `selected_element` points at (the endpoint's permission check names
 * `loop-grid`, and the filter's editor control lists only loop grids). Any
 * other id answers 400 rest_invalid_param and the front end does nothing — the
 * page renders, the results panel stays empty, filter chips do not filter.
 *
 * Elementor's editor generates 7-character ids. Core's PHP helper
 * (Utils::generate_random_string) does not, and Pro rejects ids core itself
 * produced (elementor/elementor#25515). This plugin generated 8 and its
 * instructions told agents to use 8, so every widget built that way lost its
 * AJAX features silently.
 *
 * Why a non-conforming id is warned about and not rewritten on every write:
 * ids are referenced from CSS selectors (`.elementor-element-<id>`), from
 * custom CSS and Custom Code, and from other widgets' settings. Rewriting them
 * behind the caller's back would break those references to fix a problem most
 * elements do not have — an 8-character id on a heading renders exactly as
 * well as a 7-character one. Rewriting is therefore opt-in (`repair_ids`), and
 * reports every change it made and every place it could not follow.
 *
 * Two properties the repair keeps (adversarial review r1):
 *
 *  - New ids are derived from (seed, old id), with a deterministic collision
 *    step against the ids already in the tree. The writer seeds with the post
 *    id, so the same data on the same page always maps to the same ids — a
 *    dry run shows exactly what the write will apply.
 *  - References are followed in the forms Elementor stores them in: a plain
 *    `selected_element`, an Off-canvas dynamic tag (urlencoded JSON), and an
 *    `off_canvas` elementor-action link (base64 JSON, plain or URL-encoded).
 */
class Mcpwp_Elementor_Element_Ids {

	/**
	 * Exact id length Elementor Pro's REST endpoints accept.
	 */
	const LENGTH = 7;

	/**
	 * Characters generated ids are drawn from. Pro accepts upper case too;
	 * generating lower case only matches what Elementor's editor produces.
	 */
	const ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

	/**
	 * Widget types whose OWN id is sent to a validating Pro endpoint.
	 *
	 * Confirmed against Elementor Pro 4.1.1 source: only `search`
	 * (modules/search, refresh-search, for live results). A `loop-grid`'s id
	 * reaches refresh-loop only when a Taxonomy Filter targets it, so it is
	 * handled through REFERENCE_KEYS rather than listed here. `loop-carousel`
	 * is absent: the Taxonomy Filter cannot target it and it sends no
	 * widget_id to either endpoint. Loop Grid "load more" and AJAX pagination
	 * fetch the next page's URL, not a REST endpoint.
	 *
	 * @var array<string,array{endpoint:string,feature:string}>
	 */
	const OWN_ID_WIDGETS = array(
		'search' => array(
			'endpoint' => 'refresh-search',
			'feature'  => 'its live search results',
		),
	);

	/**
	 * How many folded ids the summary warning names as a sample.
	 */
	const SUMMARY_SAMPLE = 5;

	/**
	 * Settings keys whose value is another element's id, and which Elementor
	 * resolves as one. Repair repoints these; anything else that merely
	 * contains an old id is reported, never rewritten.
	 *
	 * `selected_element` is the Taxonomy Filter's Loop Grid reference
	 * (modules/loop-filter/widgets/taxonomy-filter.php); its value is sent to
	 * refresh-loop as widget_id and used as `.elementor-element-<id>`.
	 *
	 * @var array<string,array{endpoint:string,feature:string}>
	 */
	const REFERENCE_KEYS = array(
		'selected_element' => array(
			'endpoint' => 'refresh-loop',
			'feature'  => 'filtering',
		),
	);

	/**
	 * Generate a fresh id.
	 *
	 * @param array $taken Ids already in use, as id => true.
	 * @return string Seven lowercase alphanumerics not present in $taken.
	 */
	public static function generate( array $taken = array() ) {
		$max = strlen( self::ALPHABET ) - 1;

		do {
			$id = '';
			for ( $i = 0; $i < self::LENGTH; $i++ ) {
				$id .= self::ALPHABET[ wp_rand( 0, $max ) ];
			}
		} while ( isset( $taken[ $id ] ) );

		return $id;
	}

	/**
	 * Derive an id deterministically.
	 *
	 * The same seed, salt and taken set always yield the same id. Random ids
	 * made a dry-run mapping a guess: CSS updated from it named ids the write
	 * then did not use.
	 *
	 * @param string $seed  Per-document seed (the writer passes the post id).
	 * @param string $salt  What the id is for, e.g. the old id.
	 * @param array  $taken Ids already in use, as id => true.
	 * @return string Seven lowercase alphanumerics not present in $taken.
	 */
	public static function derive( $seed, $salt, array $taken ) {
		$size = strlen( self::ALPHABET );

		for ( $n = 0; ; $n++ ) {
			$hash = md5( $seed . '|' . $salt . '|' . $n );
			$id   = '';
			for ( $i = 0; $i < self::LENGTH; $i++ ) {
				$id .= self::ALPHABET[ hexdec( substr( $hash, $i * 2, 2 ) ) % $size ];
			}
			if ( ! isset( $taken[ $id ] ) ) {
				return $id;
			}
		}
	}

	/**
	 * Every id present in a tree.
	 *
	 * @param array $elements Element tree.
	 * @return array id => true.
	 */
	public static function ids( array $elements ) {
		$ids = array();

		self::walk(
			$elements,
			'',
			function ( array &$el ) use ( &$ids ) {
				if ( isset( $el['id'] ) && ( is_string( $el['id'] ) || is_int( $el['id'] ) ) ) {
					$ids[ (string) $el['id'] ] = true;
				}
			}
		);

		return $ids;
	}

	/**
	 * Whether Elementor Pro's endpoints would accept this id.
	 *
	 * An integer is accepted as its digits: it reaches the browser, and so the
	 * endpoint, as the data-id string.
	 *
	 * @param mixed $id Candidate id.
	 * @return bool
	 */
	public static function is_valid( $id ) {
		if ( is_int( $id ) ) {
			$id = (string) $id;
		}

		// D: `$` must not match before a trailing newline.
		return is_string( $id ) && 1 === preg_match( '/^[A-Za-z0-9]{' . self::LENGTH . '}$/D', $id );
	}

	/**
	 * Find non-conforming ids and what depends on them. Does not modify the tree.
	 *
	 * Paths use the validator's format — `section[0].column[0].widget[2]` — so
	 * these findings read the same as every other validation warning.
	 *
	 * @param array $elements Element tree.
	 * @return array{checked:int,ids:array,invalid:array,targets:array,references:array}
	 *         `ids` every id present (string => true); `targets` every
	 *         reference value => referrers; `references` referrers whose value
	 *         is itself not a valid id.
	 */
	public static function audit( array $elements ) {
		$checked    = 0;
		$ids        = array();
		$invalid    = array();
		$targets    = array();
		$references = array();

		self::walk(
			$elements,
			'',
			function ( array &$el, $path ) use ( &$checked, &$ids, &$invalid, &$targets, &$references ) {
				$widget_type = isset( $el['widgetType'] ) && is_string( $el['widgetType'] ) ? $el['widgetType'] : '';

				if ( array_key_exists( 'id', $el ) ) {
					++$checked;

					if ( is_string( $el['id'] ) || is_int( $el['id'] ) ) {
						$ids[ (string) $el['id'] ] = true;
					}

					if ( ! self::is_valid( $el['id'] ) ) {
						$invalid[] = array(
							'path'        => $path,
							'id'          => self::printable( $el['id'] ),
							'widget_type' => $widget_type,
						);
					}
				}

				if ( empty( $el['settings'] ) || ! is_array( $el['settings'] ) ) {
					return;
				}

				foreach ( self::REFERENCE_KEYS as $key => $unused ) {
					if ( ! isset( $el['settings'][ $key ] ) || ! is_string( $el['settings'][ $key ] ) || '' === $el['settings'][ $key ] ) {
						continue;
					}

					$referrer = array(
						'path'        => $path,
						'key'         => $key,
						'target'      => $el['settings'][ $key ],
						'widget_type' => $widget_type,
					);

					$targets[ $el['settings'][ $key ] ][] = $referrer;

					if ( ! self::is_valid( $el['settings'][ $key ] ) ) {
						$references[] = $referrer;
					}
				}
			}
		);

		return array(
			'checked'    => $checked,
			'ids'        => $ids,
			'invalid'    => $invalid,
			'targets'    => $targets,
			'references' => $references,
		);
	}

	/**
	 * Turn an audit into validation warnings.
	 *
	 * Its own line only where the id feeds a live AJAX feature: a Search
	 * widget, an element a Taxonomy Filter targets, and a filter whose target
	 * is a bad id not present in the data. Every other non-conforming id —
	 * which renders fine and breaks nothing today — is folded into ONE
	 * summary line with a count and a sample. A page built by an earlier
	 * version carries a bad id on every element; one line each would bury
	 * the warnings that matter and teach callers to ignore the array.
	 *
	 * @param array $audit Result of audit().
	 * @return string[]
	 */
	public static function warnings( array $audit ) {
		$warnings = array();
		$folded   = array();

		foreach ( $audit['invalid'] as $finding ) {
			$type = $finding['widget_type'];

			if ( isset( self::OWN_ID_WIDGETS[ $type ] ) ) {
				$rest       = self::OWN_ID_WIDGETS[ $type ];
				$warnings[] = sprintf(
					"%s: element id '%s' on this %s widget is not %d alphanumeric characters — Elementor Pro's %s endpoint rejects it (400 rest_invalid_param: widget_id), so %s will fail silently on the front end. Rewrite it with repair_ids=true on wp_patch_elementor or wp_set_elementor.",
					$finding['path'],
					$finding['id'],
					$type,
					self::LENGTH,
					$rest['endpoint'],
					$rest['feature']
				);
				continue;
			}

			if ( isset( $audit['targets'][ $finding['id'] ] ) ) {
				$referrer   = $audit['targets'][ $finding['id'] ][0];
				$rest       = self::REFERENCE_KEYS[ $referrer['key'] ];
				$warnings[] = sprintf(
					"%s: element id '%s' on this %s is not %d alphanumeric characters, and the %s at %s points at it through %s — Elementor Pro's %s endpoint rejects it (400 rest_invalid_param: widget_id), so %s of this element will fail silently on the front end. repair_ids=true on wp_patch_elementor or wp_set_elementor rewrites it and repoints %s.",
					$finding['path'],
					$finding['id'],
					'' !== $type ? $type . ' widget' : 'element',
					self::LENGTH,
					'' !== $referrer['widget_type'] ? $referrer['widget_type'] : 'element',
					$referrer['path'],
					$referrer['key'],
					$rest['endpoint'],
					$rest['feature'],
					$referrer['key']
				);
				continue;
			}

			$folded[] = $finding;
		}

		// A bad reference whose target IS in the data is already reported on
		// the target above. One that points nowhere still breaks the filter.
		foreach ( $audit['references'] as $reference ) {
			if ( isset( $audit['ids'][ $reference['target'] ] ) ) {
				continue;
			}

			$rest       = self::REFERENCE_KEYS[ $reference['key'] ];
			$warnings[] = sprintf(
				"%s: %s %s points at '%s', which is not a %d-character alphanumeric id and was not found in this data — Elementor Pro's %s endpoint rejects it, so %s will fail silently on the front end. Point it at an element whose id is %d alphanumeric characters.",
				$reference['path'],
				'' !== $reference['widget_type'] ? $reference['widget_type'] : 'element',
				$reference['key'],
				$reference['target'],
				self::LENGTH,
				$rest['endpoint'],
				$rest['feature'],
				self::LENGTH
			);
		}

		$count = count( $folded );
		if ( $count > 0 ) {
			$sample = array();
			foreach ( array_slice( $folded, 0, self::SUMMARY_SAMPLE ) as $finding ) {
				$sample[] = "{$finding['path']} '{$finding['id']}'";
			}
			$more = $count > self::SUMMARY_SAMPLE ? sprintf( '; and %d more', $count - self::SUMMARY_SAMPLE ) : '';

			$warnings[] = sprintf(
				'%1$d element id(s) are not exactly %2$d alphanumeric characters (sample: %3$s%4$s). No AJAX feature in this data uses them and they render fine, but Elementor Pro\'s AJAX widgets (Search live results; a Loop Grid refreshed by a Taxonomy Filter) reject them if one is ever used there. repair_ids=true on wp_patch_elementor (operations optional) or wp_set_elementor rewrites them and repoints the references Elementor resolves; custom CSS that names an old id must then be updated by hand.',
				$count,
				self::LENGTH,
				implode( ', ', $sample ),
				$more
			);
		}

		return $warnings;
	}

	/**
	 * Rewrite every non-conforming id and repoint the references to it.
	 *
	 * @param array  $elements Element tree.
	 * @param string $seed     Per-document seed; the same tree and seed always
	 *                         give the same mapping.
	 * @return array{elements:array,report:array}
	 */
	public static function repair( array $elements, $seed = '' ) {
		$seed      = (string) $seed;
		$taken     = self::ids( $elements );
		$checked   = 0;
		$mapping   = array();
		$rewritten = array();

		self::walk(
			$elements,
			'',
			function ( array &$el, $path ) use ( $seed, &$taken, &$checked, &$mapping, &$rewritten ) {
				if ( ! array_key_exists( 'id', $el ) ) {
					return;
				}

				++$checked;

				if ( self::is_valid( $el['id'] ) ) {
					return;
				}

				$old           = self::printable( $el['id'] );
				$new           = self::derive( $seed, $old, $taken );
				$taken[ $new ] = true;
				$el['id']      = $new;

				// First holder wins the mapping; a repeated old id (possible
				// when called outside the validator, which de-duplicates
				// first) still gets its own id — the collision step moves it
				// on — and its own row below.
				if ( ! isset( $mapping[ $old ] ) ) {
					$mapping[ $old ] = $new;
				}

				$rewritten[] = array(
					'path'        => $path,
					'from'        => $old,
					'to'          => $new,
					'widget_type' => isset( $el['widgetType'] ) && is_string( $el['widgetType'] ) ? $el['widgetType'] : '',
				);
			}
		);

		$repointed = array();
		$possible  = array();

		if ( ! empty( $mapping ) ) {
			$lookup = self::mention_lookup( array_keys( $mapping ) );

			self::walk(
				$elements,
				'',
				function ( array &$el, $path ) use ( $mapping, $lookup, &$repointed, &$possible ) {
					if ( empty( $el['settings'] ) || ! is_array( $el['settings'] ) ) {
						return;
					}

					foreach ( $el['settings'] as $key => &$value ) {
						$key = (string) $key;

						if ( isset( self::REFERENCE_KEYS[ $key ] ) && is_string( $value ) && isset( $mapping[ $value ] ) ) {
							$repointed[] = array(
								'path' => $path,
								'key'  => $key,
								'from' => $value,
								'to'   => $mapping[ $value ],
							);
							$value       = $mapping[ $value ];
							continue;
						}

						// Encoded references first, so a repointed one is not
						// also reported as a mention of the old id.
						$value = self::repoint_encoded( $value, $key, $path, $mapping, $repointed );
						self::collect_mentions( $value, $key, $path, $lookup, $possible );
					}
					unset( $value );
				}
			);
		}

		$count = count( $rewritten );

		$report = array(
			'requested'            => true,
			'checked'              => $checked,
			// An object in JSON whether empty or not: typed clients get one shape.
			'mapping'              => (object) $mapping,
			'rewritten'            => $rewritten,
			'references_repointed' => $repointed,
			'possible_references'  => $possible,
			'message'              => $count > 0
				? sprintf( 'Rewrote %1$d of %2$d element ids to %3$d alphanumeric characters.', $count, $checked, self::LENGTH )
				: sprintf( 'All %1$d element ids already are %2$d alphanumeric characters; nothing was rewritten.', $checked, self::LENGTH ),
			'caution'              => $count > 0
				? 'Element ids are also CSS selectors (.elementor-element-<id>). Repointed automatically: Taxonomy Filter selected_element settings, Off-canvas dynamic tags and off_canvas elementor-action links (plain or URL-encoded). Searched and listed in possible_references but not changed: every other setting value in this Elementor data, including URL-encoded text and base64 elementor-action settings — update those by hand. Not searched at all: page-level custom CSS, kit CSS, Custom Code, other pages, templates and popups — update any that name an old id. Purge page caches (wp_flush_cache) so visitors get the new data-id attributes; a cached page keeps sending old ids to Elementor Pro\'s endpoints.'
				: '',
		);

		return array(
			'elements' => $elements,
			'report'   => $report,
		);
	}

	/**
	 * Visit every element depth-first, with its validator-format path.
	 *
	 * @param array    $elements Elements (modified in place by $visit).
	 * @param string   $parent   Parent path, '' at the top.
	 * @param callable $visit    function( array &$el, string $path ).
	 */
	private static function walk( array &$elements, $parent, callable $visit ) {
		foreach ( $elements as $idx => &$el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}

			$type = isset( $el['elType'] ) && is_string( $el['elType'] ) && '' !== $el['elType'] ? $el['elType'] : 'element';
			$path = ( '' === $parent ? '' : $parent . '.' ) . "{$type}[{$idx}]";

			$visit( $el, $path );

			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				self::walk( $el['elements'], $path, $visit );
			}
		}
		unset( $el );
	}

	/**
	 * Repoint references stored in encoded forms, keeping each form.
	 *
	 * @param mixed  $value     Setting value; arrays are processed recursively.
	 * @param string $key       Dotted key path so far.
	 * @param string $path      Element path.
	 * @param array  $mapping   Old id => new id.
	 * @param array  $repointed Accumulator.
	 * @return mixed The value, rewritten where a reference was found.
	 */
	private static function repoint_encoded( $value, $key, $path, array $mapping, array &$repointed ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $sub_key => $sub_value ) {
				$value[ $sub_key ] = self::repoint_encoded( $sub_value, $key . '.' . $sub_key, $path, $mapping, $repointed );
			}
			return $value;
		}

		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}

		// Core's own tag pattern (Dynamic_Tags Manager::parse_tags_text).
		if ( false !== strpos( $value, '[elementor-tag' ) ) {
			$replaced = preg_replace_callback(
				'/\[elementor-tag.+?(?=\])\]/',
				function ( $match ) use ( $key, $path, $mapping, &$repointed ) {
					return self::repoint_tag( $match[0], $key, $path, $mapping, $repointed );
				},
				$value
			);
			if ( null !== $replaced ) {
				$value = $replaced;
			}
		}

		if ( false !== stripos( $value, 'elementor-action' ) ) {
			$replaced = preg_replace_callback(
				'/(?:#|%23)elementor-action(?::|%3A)[^\s"\'<>]*/i',
				function ( $match ) use ( $key, $path, $mapping, &$repointed ) {
					return self::repoint_action_hash( $match[0], $key, $path, $mapping, $repointed );
				},
				$value
			);
			if ( null !== $replaced ) {
				$value = $replaced;
			}
		}

		return $value;
	}

	/**
	 * An Off-canvas dynamic tag: `[elementor-tag id=".." name="off-canvas"
	 * settings="<urlencode(json)>"]`, re-encoded as Manager::tag_to_text does.
	 *
	 * @param string $tag       Tag text.
	 * @param string $key       Dotted key path.
	 * @param string $path      Element path.
	 * @param array  $mapping   Old id => new id.
	 * @param array  $repointed Accumulator.
	 * @return string
	 */
	private static function repoint_tag( $tag, $key, $path, array $mapping, array &$repointed ) {
		if ( ! preg_match( '/name="(.*?(?="))"/', $tag, $name ) || 'off-canvas' !== $name[1] ) {
			return $tag;
		}
		if ( ! preg_match( '/settings="(.*?(?="]))/', $tag, $encoded ) ) {
			return $tag;
		}

		$settings = json_decode( urldecode( $encoded[1] ), true );
		if ( ! is_array( $settings ) || ! isset( $settings['off_canvas'] ) || ! ( is_string( $settings['off_canvas'] ) || is_int( $settings['off_canvas'] ) ) ) {
			return $tag;
		}

		$old = (string) $settings['off_canvas'];
		if ( ! isset( $mapping[ $old ] ) ) {
			return $tag;
		}

		$settings['off_canvas'] = $mapping[ $old ];
		$repointed[]            = array(
			'path' => $path,
			'key'  => $key,
			'from' => $old,
			'to'   => $mapping[ $old ],
		);

		return str_replace(
			'settings="' . $encoded[1] . '"]',
			'settings="' . urlencode( wp_json_encode( $settings, JSON_FORCE_OBJECT ) ) . '"]',
			$tag
		);
	}

	/**
	 * An off_canvas elementor-action link, in any of the three forms Elementor
	 * produces or accepts: `#` + rawurlencode(body) (Frontend::
	 * create_action_hash), encodeURIComponent('#' + body) (frontend JS), and
	 * the plain body (hand-typed, possibly with &amp;). The body is
	 * `elementor-action:action=off_canvas:<action>&settings=<base64(json)>`.
	 *
	 * @param string $hash      Matched link text.
	 * @param string $key       Dotted key path.
	 * @param string $path      Element path.
	 * @param array  $mapping   Old id => new id.
	 * @param array  $repointed Accumulator.
	 * @return string
	 */
	private static function repoint_action_hash( $hash, $key, $path, array $mapping, array &$repointed ) {
		$js_encoded = 0 === stripos( $hash, '%23' );
		$body       = $js_encoded ? substr( $hash, 3 ) : substr( $hash, 1 );
		$encoded    = $js_encoded || 0 === stripos( $body, 'elementor-action%3A' );
		$decoded    = $encoded ? rawurldecode( $body ) : $body;
		$entities   = ! $encoded && false !== strpos( $decoded, '&amp;' );

		if ( $entities ) {
			$decoded = str_replace( '&amp;', '&', $decoded );
		}

		if ( ! preg_match( '/^elementor-action:action=(off_canvas:[A-Za-z_]+)&settings=([A-Za-z0-9+\/]+={0,2})$/D', $decoded, $parts ) ) {
			return $hash;
		}

		$settings = json_decode( (string) base64_decode( $parts[2], true ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( ! is_array( $settings ) || ! isset( $settings['id'] ) || ! ( is_string( $settings['id'] ) || is_int( $settings['id'] ) ) ) {
			return $hash;
		}

		$old = (string) $settings['id'];
		if ( ! isset( $mapping[ $old ] ) ) {
			return $hash;
		}

		$settings['id'] = $mapping[ $old ];
		$repointed[]    = array(
			'path' => $path,
			'key'  => $key,
			'from' => $old,
			'to'   => $mapping[ $old ],
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$new_body = 'elementor-action:action=' . $parts[1] . '&settings=' . base64_encode( wp_json_encode( $settings ) );

		if ( $js_encoded ) {
			return rawurlencode( '#' . $new_body );
		}
		if ( $encoded ) {
			return '#' . rawurlencode( $new_body );
		}
		return '#' . ( $entities ? str_replace( '&', '&amp;', $new_body ) : $new_body );
	}

	/**
	 * Report (never rewrite) setting values that mention an old id as a whole
	 * token — in the raw text, URL-decoded, or inside base64 `settings=`.
	 *
	 * @param mixed  $value  Setting value; arrays are searched recursively.
	 * @param string $key    Dotted key path so far.
	 * @param string $path   Element path.
	 * @param array  $lookup Result of mention_lookup().
	 * @param array  $found  Accumulator.
	 */
	private static function collect_mentions( $value, $key, $path, array $lookup, array &$found ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $sub_key => $sub_value ) {
				self::collect_mentions( $sub_value, $key . '.' . $sub_key, $path, $lookup, $found );
			}
			return;
		}

		if ( ! is_string( $value ) || '' === $value ) {
			return;
		}

		$ids = array();

		foreach ( self::decoded_forms( $value ) as $text ) {
			// One tokenising pass per string, then hash lookups: linear in the
			// text, not in text x ids (review r1 P3-7).
			if ( ! empty( $lookup['alnum'] ) && preg_match_all( '/[A-Za-z0-9]+/', $text, $tokens ) ) {
				foreach ( $tokens[0] as $token ) {
					if ( isset( $lookup['alnum'][ $token ] ) ) {
						$ids[ $token ] = true;
					}
				}
			}

			foreach ( $lookup['other'] as $id ) {
				if ( false !== strpos( $text, $id ) && preg_match( '/(?<![A-Za-z0-9])' . preg_quote( $id, '/' ) . '(?![A-Za-z0-9])/', $text ) ) {
					$ids[ $id ] = true;
				}
			}
		}

		foreach ( array_keys( $ids ) as $id ) {
			$found[] = array(
				'path' => $path,
				'key'  => $key,
				'id'   => (string) $id,
			);
		}
	}

	/**
	 * The text itself, its URL-decoded form, and any base64 `settings=`
	 * payload inside either.
	 *
	 * @param string $text Setting text.
	 * @return string[]
	 */
	private static function decoded_forms( $text ) {
		$forms = array( $text );

		if ( false !== strpos( $text, '%' ) ) {
			$forms[] = rawurldecode( $text );
		}

		$payloads = array();
		foreach ( $forms as $form ) {
			if ( preg_match_all( '/settings=([A-Za-z0-9+\/]+={0,2})/', $form, $matches ) ) {
				foreach ( $matches[1] as $candidate ) {
					$binary = base64_decode( $candidate, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
					if ( false !== $binary && '' !== $binary ) {
						$payloads[] = $binary;
					}
				}
			}
		}

		return array_merge( $forms, $payloads );
	}

	/**
	 * Split old ids into those a token lookup can find and the rest.
	 *
	 * @param array $ids Old ids.
	 * @return array{alnum:array,other:string[]}
	 */
	private static function mention_lookup( array $ids ) {
		$alnum = array();
		$other = array();

		foreach ( $ids as $id ) {
			$id = (string) $id;
			if ( preg_match( '/^[A-Za-z0-9]+$/D', $id ) ) {
				$alnum[ $id ] = true;
			} else {
				$other[] = $id;
			}
		}

		return array(
			'alnum' => $alnum,
			'other' => $other,
		);
	}

	/**
	 * A non-string id rendered for a message or a mapping key.
	 *
	 * @param mixed $id Id.
	 * @return string
	 */
	private static function printable( $id ) {
		if ( is_string( $id ) ) {
			return $id;
		}
		if ( is_scalar( $id ) ) {
			return var_export( $id, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
		}
		return gettype( $id );
	}
}
