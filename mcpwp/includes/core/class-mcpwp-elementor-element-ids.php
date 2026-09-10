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
				'%1$d element id(s) are not exactly %2$d alphanumeric characters (sample: %3$s%4$s). No AJAX feature in this data uses them and they render fine, but Elementor Pro\'s AJAX widgets (Search live results; a Loop Grid refreshed by a Taxonomy Filter) reject them if one is ever used there. repair_ids=true on wp_patch_elementor (no operations needed) or wp_set_elementor rewrites them; custom CSS that names an old id must then be updated by hand.',
				$count,
				self::LENGTH,
				implode( ', ', $sample ),
				$more
			);
		}

		return $warnings;
	}

	/**
	 * Rewrite every non-conforming id to a fresh one and repoint references.
	 *
	 * @param array $elements Element tree.
	 * @return array{elements:array,report:array}
	 */
	public static function repair( array $elements ) {
		// Every id already present, so a new one can never collide with it.
		$taken = array();
		self::walk(
			$elements,
			'',
			function ( array &$el ) use ( &$taken ) {
				if ( isset( $el['id'] ) && ( is_string( $el['id'] ) || is_int( $el['id'] ) ) ) {
					$taken[ (string) $el['id'] ] = true;
				}
			}
		);

		$checked   = 0;
		$mapping   = array();
		$rewritten = array();

		self::walk(
			$elements,
			'',
			function ( array &$el, $path ) use ( &$taken, &$checked, &$mapping, &$rewritten ) {
				if ( ! array_key_exists( 'id', $el ) ) {
					return;
				}

				++$checked;

				if ( self::is_valid( $el['id'] ) ) {
					return;
				}

				$old          = self::printable( $el['id'] );
				$new          = self::generate( $taken );
				$taken[ $new ] = true;
				$el['id']     = $new;

				// First holder wins the mapping; a repeated old id (possible
				// when called outside the validator, which de-duplicates
				// first) still gets its own fresh id and its own row below.
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
			$token_patterns = self::token_patterns( array_keys( $mapping ) );

			self::walk(
				$elements,
				'',
				function ( array &$el, $path ) use ( $mapping, $token_patterns, &$repointed, &$possible ) {
					if ( empty( $el['settings'] ) || ! is_array( $el['settings'] ) ) {
						return;
					}

					foreach ( $el['settings'] as $key => &$value ) {
						if ( isset( self::REFERENCE_KEYS[ $key ] ) && is_string( $value ) && isset( $mapping[ $value ] ) ) {
							$repointed[] = array(
								'path' => $path,
								'key'  => (string) $key,
								'from' => $value,
								'to'   => $mapping[ $value ],
							);
							$value       = $mapping[ $value ];
							continue;
						}

						self::collect_mentions( $value, (string) $key, $path, $token_patterns, $possible );
					}
					unset( $value );
				}
			);
		}

		$count = count( $rewritten );

		$report = array(
			'requested'            => true,
			'checked'              => $checked,
			'mapping'              => $mapping,
			'rewritten'            => $rewritten,
			'references_repointed' => $repointed,
			'possible_references'  => $possible,
			'message'              => $count > 0
				? sprintf( 'Rewrote %1$d of %2$d element ids to %3$d alphanumeric characters.', $count, $checked, self::LENGTH )
				: sprintf( 'All %1$d element ids already are %2$d alphanumeric characters; nothing was rewritten.', $checked, self::LENGTH ),
			'caution'              => $count > 0
				? 'Element ids are also CSS selectors (.elementor-element-<id>). Only selected_element settings on this page were repointed. Update by hand anything listed in possible_references, and any custom CSS, Custom Code, kit CSS or other page that names an old id — this page\'s tree is all that was searched.'
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
	 * Report (never rewrite) setting values that mention an old id as a whole token.
	 *
	 * @param mixed  $value    Setting value; arrays are searched recursively.
	 * @param string $key      Dotted key path so far.
	 * @param string $path     Element path.
	 * @param array  $patterns Regexes from token_patterns().
	 * @param array  $found    Accumulator.
	 */
	private static function collect_mentions( $value, $key, $path, array $patterns, array &$found ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $sub_key => $sub_value ) {
				self::collect_mentions( $sub_value, $key . '.' . $sub_key, $path, $patterns, $found );
			}
			return;
		}

		if ( ! is_string( $value ) || '' === $value ) {
			return;
		}

		foreach ( $patterns as $pattern ) {
			if ( ! preg_match_all( $pattern, $value, $matches ) ) {
				continue;
			}
			foreach ( array_unique( $matches[0] ) as $id ) {
				$found[] = array(
					'path' => $path,
					'key'  => $key,
					'id'   => $id,
				);
			}
		}
	}

	/**
	 * Whole-token regexes for a set of ids, chunked to keep each pattern small.
	 *
	 * @param array $ids Old ids.
	 * @return string[]
	 */
	private static function token_patterns( array $ids ) {
		$patterns = array();

		foreach ( array_chunk( $ids, 100 ) as $chunk ) {
			$quoted = array();
			foreach ( $chunk as $id ) {
				$quoted[] = preg_quote( (string) $id, '/' );
			}
			$patterns[] = '/(?<![A-Za-z0-9])(?:' . implode( '|', $quoted ) . ')(?![A-Za-z0-9])/';
		}

		return $patterns;
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
