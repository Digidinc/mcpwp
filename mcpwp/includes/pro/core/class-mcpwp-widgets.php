<?php
/**
 * Widget & Sidebar Management Handler
 *
 * @package MCPWP_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Widget and sidebar management functionality.
 *
 * Handles widget CRUD operations and sidebar management.
 */
class Mcpwp_Widgets {

	/**
	 * Get all registered sidebars.
	 *
	 * @return array Sidebars list.
	 */
	public function get_sidebars() {
		global $wp_registered_sidebars;

		$sidebars = array();

		foreach ( $wp_registered_sidebars as $id => $sidebar ) {
			$sidebars[] = array(
				'id'            => $id,
				'name'          => $sidebar['name'],
				'description'   => $sidebar['description'],
				'class'         => $sidebar['class'],
				'before_widget' => $sidebar['before_widget'],
				'after_widget'  => $sidebar['after_widget'],
				'before_title'  => $sidebar['before_title'],
				'after_title'   => $sidebar['after_title'],
				'widget_count'  => $this->count_widgets_in_sidebar( $id ),
			);
		}

		return $sidebars;
	}

	/**
	 * Get single sidebar.
	 *
	 * @param string $sidebar_id Sidebar ID.
	 * @return array|WP_Error Sidebar data or error.
	 */
	public function get_sidebar( $sidebar_id ) {
		global $wp_registered_sidebars;

		if ( ! isset( $wp_registered_sidebars[ $sidebar_id ] ) ) {
			return new WP_Error( 'not_found', __( 'Sidebar not found.', 'mumcp' ) );
		}

		$sidebar = $wp_registered_sidebars[ $sidebar_id ];

		return array(
			'id'            => $sidebar_id,
			'name'          => $sidebar['name'],
			'description'   => $sidebar['description'],
			'class'         => $sidebar['class'],
			'before_widget' => $sidebar['before_widget'],
			'after_widget'  => $sidebar['after_widget'],
			'before_title'  => $sidebar['before_title'],
			'after_title'   => $sidebar['after_title'],
			'widgets'       => $this->get_sidebar_widgets( $sidebar_id ),
		);
	}

	/**
	 * Get widgets in a sidebar.
	 *
	 * @param string $sidebar_id Sidebar ID.
	 * @return array Widgets list.
	 */
	public function get_sidebar_widgets( $sidebar_id ) {
		$sidebars_widgets = get_option( 'sidebars_widgets', array() );

		if ( ! isset( $sidebars_widgets[ $sidebar_id ] ) || ! is_array( $sidebars_widgets[ $sidebar_id ] ) ) {
			return array();
		}

		$widgets = array();

		foreach ( $sidebars_widgets[ $sidebar_id ] as $widget_id ) {
			$widget_data = $this->get_widget( $widget_id );
			if ( ! is_wp_error( $widget_data ) ) {
				$widgets[] = $widget_data;
			}
		}

		return $widgets;
	}

	/**
	 * Get widget by ID.
	 *
	 * @param string $widget_id Widget ID (e.g., 'text-2').
	 * @return array|WP_Error Widget data or error.
	 */
	public function get_widget( $widget_id ) {
		global $wp_registered_widgets;

		if ( ! isset( $wp_registered_widgets[ $widget_id ] ) ) {
			return new WP_Error( 'not_found', __( 'Widget not found.', 'mumcp' ) );
		}

		$widget = $wp_registered_widgets[ $widget_id ];
		$parsed = $this->parse_widget_id( $widget_id );

		if ( ! $parsed ) {
			return new WP_Error( 'invalid_widget', __( 'Invalid widget ID format.', 'mumcp' ) );
		}

		$settings = $this->get_widget_settings( $parsed['type'], $parsed['number'] );
		$sidebar  = $this->get_widget_sidebar( $widget_id );

		return array(
			'id'          => $widget_id,
			'type'        => $parsed['type'],
			'number'      => $parsed['number'],
			'name'        => $widget['name'],
			'description' => isset( $widget['description'] ) ? $widget['description'] : '',
			'sidebar'     => $sidebar,
			'settings'    => $settings,
		);
	}

	/**
	 * Get all available widget types.
	 *
	 * @return array Widget types.
	 */
	public function get_widget_types() {
		global $wp_widget_factory;

		$types = array();

		foreach ( $wp_widget_factory->widgets as $class => $widget ) {
			$types[] = array(
				'id_base'     => $widget->id_base,
				'name'        => $widget->name,
				'description' => $widget->widget_options['description'] ?? '',
				'class'       => $class,
			);
		}

		return $types;
	}

	/**
	 * Add widget to sidebar.
	 *
	 * @param string $sidebar_id  Sidebar ID.
	 * @param string $widget_type Widget type (id_base).
	 * @param array  $settings    Widget settings.
	 * @param int    $position    Position in sidebar (optional).
	 * @return array|WP_Error Widget data or error.
	 */
	public function add_widget( $sidebar_id, $widget_type, $settings = array(), $position = null ) {
		global $wp_registered_sidebars, $wp_widget_factory;

		// Validate sidebar exists.
		if ( ! isset( $wp_registered_sidebars[ $sidebar_id ] ) ) {
			return new WP_Error( 'invalid_sidebar', __( 'Sidebar not found.', 'mumcp' ) );
		}

		// Validate widget type exists.
		$widget_class = null;
		foreach ( $wp_widget_factory->widgets as $class => $widget ) {
			if ( $widget->id_base === $widget_type ) {
				$widget_class = $widget;
				break;
			}
		}

		if ( ! $widget_class ) {
			return new WP_Error( 'invalid_widget_type', __( 'Widget type not found.', 'mumcp' ) );
		}

		// Get next widget number.
		$all_settings = get_option( 'widget_' . $widget_type, array() );
		$numbers      = array_keys( $all_settings );
		$numbers      = array_filter( $numbers, 'is_int' );
		$next_number  = empty( $numbers ) ? 1 : max( $numbers ) + 1;

		// Save widget settings — 'widget_<type>' is WordPress core's own option format.
		$all_settings[ $next_number ] = $settings;
		update_option( 'widget_' . $widget_type, $all_settings );

		// Add widget to sidebar.
		$widget_id        = $widget_type . '-' . $next_number;
		$sidebars_widgets = get_option( 'sidebars_widgets', array() );

		if ( ! isset( $sidebars_widgets[ $sidebar_id ] ) ) {
			$sidebars_widgets[ $sidebar_id ] = array();
		}

		if ( null !== $position && $position >= 0 ) {
			array_splice( $sidebars_widgets[ $sidebar_id ], $position, 0, array( $widget_id ) );
		} else {
			$sidebars_widgets[ $sidebar_id ][] = $widget_id;
		}

		update_option( 'sidebars_widgets', $sidebars_widgets );

		return $this->get_widget( $widget_id );
	}

	/**
	 * Update widget settings.
	 *
	 * @param string $widget_id Widget ID.
	 * @param array  $settings  New settings.
	 * @return array|WP_Error Widget data or error.
	 */
	public function update_widget( $widget_id, $settings ) {
		$parsed = $this->parse_widget_id( $widget_id );

		if ( ! $parsed ) {
			return new WP_Error( 'invalid_widget', __( 'Invalid widget ID format.', 'mumcp' ) );
		}

		$all_settings = get_option( 'widget_' . $parsed['type'], array() );

		if ( ! isset( $all_settings[ $parsed['number'] ] ) ) {
			return new WP_Error( 'not_found', __( 'Widget not found.', 'mumcp' ) );
		}

		// Merge existing settings with new settings.
		$all_settings[ $parsed['number'] ] = array_merge(
			$all_settings[ $parsed['number'] ],
			$settings
		);

		// 'widget_<type>' is WordPress core's own widget option format — not our option.
		update_option( 'widget_' . $parsed['type'], $all_settings );

		return $this->get_widget( $widget_id );
	}

	/**
	 * Delete widget.
	 *
	 * @param string $widget_id Widget ID.
	 * @return bool|WP_Error True on success or error.
	 */
	public function delete_widget( $widget_id ) {
		$parsed = $this->parse_widget_id( $widget_id );

		if ( ! $parsed ) {
			return new WP_Error( 'invalid_widget', __( 'Invalid widget ID format.', 'mumcp' ) );
		}

		// Remove from sidebar.
		$sidebars_widgets = get_option( 'sidebars_widgets', array() );

		foreach ( $sidebars_widgets as $sidebar_id => $widgets ) {
			if ( is_array( $widgets ) ) {
				$key = array_search( $widget_id, $widgets, true );
				if ( false !== $key ) {
					unset( $sidebars_widgets[ $sidebar_id ][ $key ] );
					$sidebars_widgets[ $sidebar_id ] = array_values( $sidebars_widgets[ $sidebar_id ] );
				}
			}
		}

		update_option( 'sidebars_widgets', $sidebars_widgets );

		// Remove widget settings.
		// 'widget_<type>' is WordPress core's own widget option format — not our option.
		$all_settings = get_option( 'widget_' . $parsed['type'], array() );
		if ( isset( $all_settings[ $parsed['number'] ] ) ) {
			unset( $all_settings[ $parsed['number'] ] );
			update_option( 'widget_' . $parsed['type'], $all_settings );
		}

		return true;
	}

	/**
	 * Move widget to different sidebar or position.
	 *
	 * @param string $widget_id  Widget ID.
	 * @param string $sidebar_id Target sidebar ID.
	 * @param int    $position   Position in sidebar.
	 * @return array|WP_Error Widget data or error.
	 */
	public function move_widget( $widget_id, $sidebar_id, $position = null ) {
		global $wp_registered_sidebars;

		// Validate sidebar exists.
		if ( ! isset( $wp_registered_sidebars[ $sidebar_id ] ) ) {
			return new WP_Error( 'invalid_sidebar', __( 'Sidebar not found.', 'mumcp' ) );
		}

		// Validate widget exists.
		$widget = $this->get_widget( $widget_id );
		if ( is_wp_error( $widget ) ) {
			return $widget;
		}

		// Remove from current sidebar.
		$sidebars_widgets = get_option( 'sidebars_widgets', array() );

		foreach ( $sidebars_widgets as $sid => $widgets ) {
			if ( is_array( $widgets ) ) {
				$key = array_search( $widget_id, $widgets, true );
				if ( false !== $key ) {
					unset( $sidebars_widgets[ $sid ][ $key ] );
					$sidebars_widgets[ $sid ] = array_values( $sidebars_widgets[ $sid ] );
				}
			}
		}

		// Add to new sidebar.
		if ( ! isset( $sidebars_widgets[ $sidebar_id ] ) ) {
			$sidebars_widgets[ $sidebar_id ] = array();
		}

		if ( null !== $position && $position >= 0 ) {
			array_splice( $sidebars_widgets[ $sidebar_id ], $position, 0, array( $widget_id ) );
		} else {
			$sidebars_widgets[ $sidebar_id ][] = $widget_id;
		}

		update_option( 'sidebars_widgets', $sidebars_widgets );

		return $this->get_widget( $widget_id );
	}

	/**
	 * Reorder widgets in sidebar.
	 *
	 * @param string $sidebar_id Sidebar ID.
	 * @param array  $widget_ids Ordered array of widget IDs.
	 * @return array|WP_Error Sidebar data or error.
	 */
	public function reorder_widgets( $sidebar_id, $widget_ids ) {
		global $wp_registered_sidebars;

		// Validate sidebar exists.
		if ( ! isset( $wp_registered_sidebars[ $sidebar_id ] ) ) {
			return new WP_Error( 'invalid_sidebar', __( 'Sidebar not found.', 'mumcp' ) );
		}

		$sidebars_widgets = get_option( 'sidebars_widgets', array() );

		// Validate all widget IDs are in the sidebar.
		$current_widgets = isset( $sidebars_widgets[ $sidebar_id ] ) ? $sidebars_widgets[ $sidebar_id ] : array();

		foreach ( $widget_ids as $widget_id ) {
			if ( ! in_array( $widget_id, $current_widgets, true ) ) {
				return new WP_Error(
					'invalid_widget',
					/* translators: %s: widget ID */
					sprintf( __( 'Widget %s is not in this sidebar.', 'mumcp' ), $widget_id )
				);
			}
		}

		// Update order.
		$sidebars_widgets[ $sidebar_id ] = $widget_ids;
		update_option( 'sidebars_widgets', $sidebars_widgets );

		return $this->get_sidebar( $sidebar_id );
	}

	/**
	 * Parse widget ID into type and number.
	 *
	 * @param string $widget_id Widget ID (e.g., 'text-2').
	 * @return array|false Array with 'type' and 'number' or false.
	 */
	private function parse_widget_id( $widget_id ) {
		if ( preg_match( '/^(.+)-(\d+)$/', $widget_id, $matches ) ) {
			return array(
				'type'   => $matches[1],
				'number' => (int) $matches[2],
			);
		}
		return false;
	}

	/**
	 * Get widget settings.
	 *
	 * @param string $widget_type   Widget type.
	 * @param int    $widget_number Widget number.
	 * @return array Widget settings.
	 */
	private function get_widget_settings( $widget_type, $widget_number ) {
		$all_settings = get_option( 'widget_' . $widget_type, array() );
		return isset( $all_settings[ $widget_number ] ) ? $all_settings[ $widget_number ] : array();
	}

	/**
	 * Get the sidebar a widget belongs to.
	 *
	 * @param string $widget_id Widget ID.
	 * @return string|null Sidebar ID or null.
	 */
	private function get_widget_sidebar( $widget_id ) {
		$sidebars_widgets = get_option( 'sidebars_widgets', array() );

		foreach ( $sidebars_widgets as $sidebar_id => $widgets ) {
			if ( is_array( $widgets ) && in_array( $widget_id, $widgets, true ) ) {
				return $sidebar_id;
			}
		}

		return null;
	}

	/**
	 * Count widgets in a sidebar.
	 *
	 * @param string $sidebar_id Sidebar ID.
	 * @return int Widget count.
	 */
	private function count_widgets_in_sidebar( $sidebar_id ) {
		$sidebars_widgets = get_option( 'sidebars_widgets', array() );

		if ( ! isset( $sidebars_widgets[ $sidebar_id ] ) || ! is_array( $sidebars_widgets[ $sidebar_id ] ) ) {
			return 0;
		}

		return count( $sidebars_widgets[ $sidebar_id ] );
	}
}
