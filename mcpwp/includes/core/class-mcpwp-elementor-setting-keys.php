<?php
/**
 * Widget setting keys checked against Elementor's live control definitions (#824).
 *
 * Wrap, do not build. Elementor knows every setting a widget reads — it
 * registers each one as a control — so the check asks Elementor. MCPWP's
 * curated widget reference (Mcpwp_Elementor_Widgets) is used only when
 * Elementor cannot be asked, and the report says which of the two answered.
 *
 * What the save validator did before: it merged Elementor's control keys with
 * the curated reference and accepted anything in the union, skipped every key
 * starting with `_` or `__`, and never looked inside a repeater. So a curated
 * key Elementor does not have (counter `typography_font_size`; Elementor calls
 * it `typography_number_font_size`) was valid on every site, `post_info_type`
 * rows in a post-info repeater passed silently and all defaulted to `date`,
 * and a wrongly-prefixed group key drew a warning with a 15-key list and no
 * pointer to the one the caller meant.
 *
 * Facts this relies on, observed on WordPress 7.0.2 + Elementor 4.2.4 over a
 * REST request (2026-09-10):
 *   - Widget::get_controls() holds the widget's own controls plus the common
 *     Advanced-tab controls; group controls are expanded to one control per
 *     field (`typography_font_family`); responsive controls to one per active
 *     device (`align_tablet`); state controls are named controls of their own
 *     (`title_hover_color`), so no `_hover` rule is needed.
 *   - A repeater control carries `fields`, keyed by field name, `_id` included.
 *   - Elementor free registers Pro widget names (`form`, `posts`, `nav-menu`,
 *     `loop-grid`, ...) as Modules\Promotions\Widgets\Pro_Widget_Promotion:
 *     registered, no controls, renders nothing.
 *   - Core\Frontend\Performance::should_optimize_controls() is true outside
 *     REST, admin and preview requests, and then every style control is left
 *     out of get_controls() (heading: 176 controls under WP-CLI, 276 over
 *     REST). Those definitions are not a basis for calling a key unknown.
 *
 * Unknown keys warn. They are never removed and never refuse the save: the
 * caller may know something this check does not. A mistake repeated across a
 * page is folded into one warning with a count, and the warning list is
 * capped; the structured report lists every finding.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects setting-key findings across one element tree.
 */
final class Mcpwp_Elementor_Setting_Keys {

	/**
	 * A device suffix Elementor adds to a responsive control. Every breakpoint
	 * Elementor knows is accepted, active or not: a value stored for an
	 * inactive breakpoint is kept and applies when that breakpoint is enabled.
	 */
	const DEVICE_PATTERN = '/^(.+)_(widescreen|laptop|tablet_extra|tablet|mobile_extra|mobile)$/';

	/**
	 * Maps whose keys name controls of the same widget: global style
	 * references and dynamic tags. Their keys are checked; the maps are not
	 * controls themselves.
	 */
	const REFERENCE_MAPS = array( '__globals__', '__dynamic__' );

	/**
	 * Keys Elementor itself writes into settings without a control: the
	 * navigator label, and the Font Awesome 4 migration marker.
	 */
	const INTERNAL_KEYS = array( '_title', '__fa4_migrated' );

	/**
	 * Key families the static reference never lists. Skipped only when the
	 * static reference is what is being checked against.
	 */
	const STATIC_SKIP_PREFIXES = array( '_', 'motion_fx_', '__', 'hide_', 'responsive_', 'custom_css' );

	const MAX_WARNINGS = 25;
	const MAX_REPORTED = 200;

	/** @var array<string,array> Index per widget type. */
	private $indexes = array();

	/** @var bool */
	private $reason_known = false;

	/** @var string|null Why live controls are not used; null when they are. */
	private $reason = null;

	/** @var array<string,array> Findings keyed widget|container|key. */
	private $findings = array();

	/** @var array<string,array> */
	private $not_checked = array();

	/** @var array<string,array> */
	private $placeholders = array();

	/** @var array{live:int,static:int} */
	private $checked = array(
		'live'   => 0,
		'static' => 0,
	);

	/**
	 * Why Elementor's live controls cannot be trusted in this request.
	 *
	 * @return string|null Null when they can.
	 */
	public static function unavailable_reason() {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return 'Elementor is not loaded in this request';
		}

		if ( null === Mcpwp_Elementor_Registry::live_types() ) {
			return "Elementor's widget registry could not be read";
		}

		$performance = 'Elementor\Core\Frontend\Performance';

		if ( class_exists( $performance ) && method_exists( $performance, 'should_optimize_controls' ) ) {
			try {
				if ( call_user_func( array( $performance, 'should_optimize_controls' ) ) ) {
					return 'Elementor leaves style controls out of widget definitions outside REST, admin and preview requests, so its live controls are incomplete here';
				}
			} catch ( \Throwable $e ) {
				return 'Elementor could not say whether its widget controls are complete in this request';
			}
		}

		return null;
	}

	/**
	 * Check one widget's settings.
	 *
	 * @param string $path        Element path for messages.
	 * @param string $widget_type Widget type.
	 * @param array  $settings    Settings.
	 */
	public function check( $path, $widget_type, array $settings ) {
		$widget_type = (string) $widget_type;
		$index       = $this->index_for( $widget_type );

		if ( 'placeholder' === $index['status'] ) {
			if ( ! isset( $this->placeholders[ $widget_type ] ) ) {
				$this->placeholders[ $widget_type ] = array(
					'path'  => $path,
					'count' => 0,
				);
			}

			++$this->placeholders[ $widget_type ]['count'];
			$this->not_checked( $widget_type, $index['reason'] );

			return;
		}

		if ( 'none' === $index['status'] ) {
			$this->not_checked( $widget_type, $index['reason'] );

			return;
		}

		++$this->checked[ $index['source'] ];

		$this->walk( $path, $widget_type, $settings, $index, null );
	}

	/**
	 * Warnings for the save response: folded, capped.
	 *
	 * @return string[]
	 */
	public function warnings() {
		$out = array();

		foreach ( $this->placeholders as $type => $seen ) {
			$out[] = sprintf(
				"%s: widget '%s' is an Elementor Pro widget, but this site only has Elementor's promotion placeholder for it (Elementor Pro is not active) — it renders nothing, and its settings cannot be checked%s",
				$seen['path'],
				$type,
				$seen['count'] > 1 ? sprintf( ' (same widget in %d more places)', $seen['count'] - 1 ) : ''
			);
		}

		$shown = 0;

		foreach ( $this->findings as $finding ) {
			if ( $shown >= self::MAX_WARNINGS ) {
				break;
			}

			$out[] = $this->format( $finding );
			++$shown;
		}

		$hidden = count( $this->findings ) - $shown;

		if ( $hidden > 0 ) {
			$out[] = sprintf( '... and %d more unknown setting keys not listed here — settings_check.unknown_settings lists every one.', $hidden );
		}

		return $out;
	}

	/**
	 * Structured report: which source answered, what could not be checked,
	 * and every finding.
	 *
	 * @return array
	 */
	public function report() {
		$live   = $this->checked['live'];
		$static = $this->checked['static'];

		if ( $live > 0 ) {
			$source = 'live_registry';
		} elseif ( $static > 0 ) {
			$source = 'static_reference';
		} else {
			$source = 'none';
		}

		$findings = array();

		foreach ( $this->findings as $finding ) {
			unset( $finding['in_kind'] );
			$findings[] = $finding;
		}

		return array(
			'source'                  => $source,
			'live_unavailable_reason' => $this->reason(),
			'widgets_checked'         => $live + $static,
			'widgets_not_checked'     => array_values( $this->not_checked ),
			'unknown_settings'        => array_slice( $findings, 0, self::MAX_REPORTED ),
			'unknown_settings_total'  => count( $findings ),
		);
	}

	/**
	 * Key index of a control list: names, and a nested index per repeater.
	 *
	 * @param array $controls Controls as Elementor returns them.
	 * @return array{keys:array<string,bool>,repeaters:array<string,array>}
	 */
	public static function index_from_controls( array $controls ) {
		$keys      = array();
		$repeaters = array();

		foreach ( $controls as $name => $control ) {
			$name = ( is_array( $control ) && isset( $control['name'] ) && is_string( $control['name'] ) ) ? $control['name'] : (string) $name;

			$keys[ $name ] = true;

			if ( is_array( $control ) && isset( $control['type'] ) && 'repeater' === $control['type'] && isset( $control['fields'] ) && is_array( $control['fields'] ) ) {
				$repeaters[ $name ] = self::index_from_controls( $control['fields'] );
			}
		}

		return array(
			'keys'      => $keys,
			'repeaters' => $repeaters,
		);
	}

	/**
	 * Whether an index accepts a key, device suffixes included.
	 *
	 * @param array  $index From index_from_controls().
	 * @param string $key   Setting key.
	 * @return bool
	 */
	public static function index_has( array $index, $key ) {
		$key = (string) $key;

		if ( isset( $index['keys'][ $key ] ) ) {
			return true;
		}

		return 1 === preg_match( self::DEVICE_PATTERN, $key, $match ) && isset( $index['keys'][ $match[1] ] );
	}

	/**
	 * The valid key a caller most likely meant, when that is obvious.
	 *
	 * @param string $key        Unknown key.
	 * @param array  $candidates Valid keys.
	 * @return string|null
	 */
	public static function suggest( $key, array $candidates ) {
		$key = (string) $key;

		if ( '' === $key || empty( $candidates ) ) {
			return null;
		}

		$lookup = array_flip( $candidates );
		$parts  = explode( '_', $key );

		// A prefix too many — the reported shape: `title_typography_font_family`
		// on a heading whose typography group is `typography`, or
		// `post_info_type` in a repeater whose field is `type`.
		for ( $i = 1, $n = count( $parts ); $i < $n; $i++ ) {
			$tail = implode( '_', array_slice( $parts, $i ) );

			if ( '' !== $tail && isset( $lookup[ $tail ] ) ) {
				return $tail;
			}
		}

		// A prefix too few, when exactly one key fits.
		$longer = array();

		foreach ( $candidates as $candidate ) {
			$candidate = (string) $candidate;

			if ( strlen( $candidate ) > strlen( $key ) + 1 && substr( $candidate, -( strlen( $key ) + 1 ) ) === '_' . $key ) {
				$longer[] = $candidate;
			}
		}

		if ( 1 === count( $longer ) ) {
			return $longer[0];
		}

		// A near spelling, when one candidate is clearly nearest.
		if ( strlen( $key ) > 255 ) {
			return null;
		}

		$best     = null;
		$distance = PHP_INT_MAX;
		$tied     = false;
		$limit    = strlen( $key ) >= 12 ? 3 : 2;

		foreach ( $candidates as $candidate ) {
			$candidate = (string) $candidate;

			if ( strlen( $candidate ) > 255 ) {
				continue;
			}

			$d = levenshtein( $key, $candidate );

			if ( $d < $distance ) {
				$best     = $candidate;
				$distance = $d;
				$tied     = false;
			} elseif ( $d === $distance ) {
				$tied = true;
			}
		}

		return ( null !== $best && $distance <= $limit && ! $tied ) ? $best : null;
	}

	private function reason() {
		if ( ! $this->reason_known ) {
			$this->reason       = self::unavailable_reason();
			$this->reason_known = true;
		}

		return $this->reason;
	}

	private function index_for( $type ) {
		if ( ! isset( $this->indexes[ $type ] ) ) {
			$reason = $this->reason();

			$this->indexes[ $type ] = null === $reason ? self::live_index( $type ) : self::static_index( $type, $reason );
		}

		return $this->indexes[ $type ];
	}

	private static function live_index( $type ) {
		$widget = Mcpwp_Elementor_Registry::live_widget( $type );

		if ( null === $widget ) {
			return array(
				'status' => 'none',
				'reason' => "not registered in Elementor's live widget registry, so there are no controls to check against",
			);
		}

		if ( Mcpwp_Elementor_Registry::is_placeholder_widget( $widget ) ) {
			return array(
				'status' => 'placeholder',
				'reason' => "Elementor Pro promotion placeholder: this site has Elementor's placeholder for the Pro widget, not the widget (Elementor Pro is not active), so it has no controls and renders nothing",
			);
		}

		$controls = Mcpwp_Elementor_Registry::live_controls( $type );

		if ( ! is_array( $controls ) || empty( $controls ) ) {
			return array(
				'status' => 'none',
				'reason' => 'Elementor returned no controls for this widget',
			);
		}

		return array_merge(
			array(
				'status' => 'live',
				'source' => 'live',
			),
			self::index_from_controls( $controls )
		);
	}

	private static function static_index( $type, $reason ) {
		$keys = class_exists( 'Mcpwp_Elementor_Widgets' ) ? Mcpwp_Elementor_Widgets::get_valid_keys( $type ) : array();

		if ( empty( $keys ) ) {
			return array(
				'status' => 'none',
				'reason' => sprintf( "no live controls (%s) and no entry in MCPWP's static widget reference", $reason ),
			);
		}

		return array(
			'status'    => 'static',
			'source'    => 'static',
			'keys'      => array_fill_keys( $keys, true ),
			'repeaters' => array(),
		);
	}

	private function not_checked( $type, $reason ) {
		if ( ! isset( $this->not_checked[ $type ] ) ) {
			$this->not_checked[ $type ] = array(
				'widget_type' => $type,
				'reason'      => $reason,
				'count'       => 0,
			);
		}

		++$this->not_checked[ $type ]['count'];
	}

	/**
	 * @param string      $path      Path of the settings array.
	 * @param string      $type      Widget type.
	 * @param array       $settings  Settings, or one repeater row.
	 * @param array       $index     Index to check against.
	 * @param string|null $container Repeater name for a row; null at the top.
	 */
	private function walk( $path, $type, array $settings, array $index, $container ) {
		foreach ( $settings as $key => $value ) {
			$key = (string) $key;

			if ( self::index_has( $index, $key ) ) {
				if ( isset( $index['repeaters'][ $key ] ) && is_array( $value ) ) {
					foreach ( $value as $row => $item ) {
						if ( is_array( $item ) ) {
							$this->walk( "{$path}.{$key}[{$row}]", $type, $item, array_merge( $index['repeaters'][ $key ], array( 'source' => $index['source'] ) ), $key );
						}
					}
				}

				continue;
			}

			if ( in_array( $key, self::REFERENCE_MAPS, true ) ) {
				if ( 'live' === $index['source'] && is_array( $value ) ) {
					foreach ( array_keys( $value ) as $reference ) {
						if ( ! self::index_has( $index, (string) $reference ) ) {
							$this->record( "{$path}.{$key}", $type, (string) $reference, $index, $key, 'reference' );
						}
					}
				}

				continue;
			}

			if ( in_array( $key, self::INTERNAL_KEYS, true ) || ( null !== $container && '_id' === $key ) ) {
				continue;
			}

			if ( 'static' === $index['source'] && self::has_static_skip_prefix( $key ) ) {
				continue;
			}

			$this->record( $path, $type, $key, $index, $container, null === $container ? null : 'repeater' );
		}
	}

	private function record( $path, $type, $key, array $index, $container, $kind ) {
		$fold = $type . '|' . (string) $container . '|' . $key;

		if ( isset( $this->findings[ $fold ] ) ) {
			++$this->findings[ $fold ]['count'];

			return;
		}

		$this->findings[ $fold ] = array(
			'widget_type' => $type,
			'key'         => $key,
			'in'          => $container,
			'in_kind'     => $kind,
			'path'        => $path,
			'count'       => 1,
			'suggestion'  => self::suggest( $key, array_keys( $index['keys'] ) ),
			'source'      => 'live' === $index['source'] ? 'live_registry' : 'static_reference',
		);
	}

	private function format( array $finding ) {
		if ( 'static_reference' === $finding['source'] ) {
			$text = sprintf(
				"%s: unknown key '%s' on %s widget — not in MCPWP's static widget reference, which is all there was to check against (%s), so it may still be a real control",
				$finding['path'],
				$finding['key'],
				$finding['widget_type'],
				(string) $this->reason()
			);
		} elseif ( 'repeater' === $finding['in_kind'] ) {
			$text = sprintf(
				"%s: unknown field '%s' in repeater '%s' on %s widget — Elementor registers no such field for that repeater, so the value is stored but has no effect",
				$finding['path'],
				$finding['key'],
				$finding['in'],
				$finding['widget_type']
			);
		} elseif ( 'reference' === $finding['in_kind'] ) {
			$text = sprintf(
				"%s: unknown setting '%s' in %s on %s widget — it names no control of this widget, so the reference does nothing",
				$finding['path'],
				$finding['key'],
				$finding['in'],
				$finding['widget_type']
			);
		} else {
			$text = sprintf(
				"%s: unknown setting '%s' on %s widget — Elementor registers no such control for this widget, so the value is stored but has no effect",
				$finding['path'],
				$finding['key'],
				$finding['widget_type']
			);
		}

		if ( null !== $finding['suggestion'] ) {
			$text .= sprintf( " — did you mean '%s'?", $finding['suggestion'] );
		}

		if ( $finding['count'] > 1 ) {
			$text .= sprintf( ' (same key in %d more places)', $finding['count'] - 1 );
		}

		return $text;
	}

	private static function has_static_skip_prefix( $key ) {
		foreach ( self::STATIC_SKIP_PREFIXES as $prefix ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				return true;
			}
		}

		return false;
	}
}
