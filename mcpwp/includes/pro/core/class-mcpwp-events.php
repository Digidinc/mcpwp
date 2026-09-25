<?php
/**
 * ThimPress Events Integration Handler
 *
 * Provides TP Events operations for AI agents.
 *
 * @package MCPWP_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Events handler class.
 */
class Mcpwp_Events {

	use Mcpwp_Sanitization;

	/**
	 * Check if TP Events is active.
	 *
	 * @return bool
	 */
	public function is_active() {
		return post_type_exists( 'tp_event' );
	}

	/**
	 * List events.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public function list_events( $args = array() ) {
		if ( ! $this->is_active() ) {
			return array( 'events' => array(), 'total' => 0 );
		}

		$defaults = array(
			'per_page' => 50,
			'page'     => 1,
			'status'   => 'publish',
			'search'   => '',
			'orderby'  => 'date',
			'order'    => 'DESC',
		);

		$args = wp_parse_args( $args, $defaults );

		$query_args = array(
			'post_type'      => 'tp_event',
			'posts_per_page' => $args['per_page'],
			'paged'          => $args['page'],
			'post_status'    => $args['status'],
			'orderby'        => $args['orderby'],
			'order'          => $args['order'],
		);

		if ( ! empty( $args['search'] ) ) {
			$query_args['s'] = $args['search'];
		}

		$query = new WP_Query( $query_args );
		$total = $query->found_posts;

		$events = array();
		foreach ( $query->posts as $post ) {
			$events[] = $this->format_event( $post );
		}

		return array(
			'events'      => $events,
			'total'       => $total,
			'page'        => $args['page'],
			'per_page'    => $args['per_page'],
			'total_pages' => ceil( $total / $args['per_page'] ),
		);
	}

	/**
	 * Get a single event.
	 *
	 * @param int $id Event ID.
	 * @return array|WP_Error
	 */
	public function get_event( $id ) {
		if ( ! $this->is_active() ) {
			return new WP_Error( 'events_not_active', __( 'TP Events is not active.', 'mcpwp' ), array( 'status' => 400 ) );
		}

		$post = get_post( $id );
		if ( ! $post || 'tp_event' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Event not found.', 'mcpwp' ), array( 'status' => 404 ) );
		}

		return $this->format_event( $post, true );
	}

	/**
	 * Create an event.
	 *
	 * @param array $data Event data.
	 * @return array|WP_Error
	 */
	public function create_event( $data ) {
		if ( ! $this->is_active() ) {
			return new WP_Error( 'events_not_active', __( 'TP Events is not active.', 'mcpwp' ), array( 'status' => 400 ) );
		}

		$post_data = array(
			'post_type'   => 'tp_event',
			'post_status' => isset( $data['status'] ) ? sanitize_key( $data['status'] ) : 'draft',
			'post_title'  => isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '',
		);

		$sanitized_content = array( 'removed' => array() );
		if ( isset( $data['content'] ) ) {
			$sanitized_content          = $this->sanitize_content_body( $data['content'] );
			$post_data['post_content'] = $sanitized_content['content'];
		}

		if ( isset( $data['excerpt'] ) ) {
			$post_data['post_excerpt'] = sanitize_textarea_field( $data['excerpt'] );
		}

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$this->set_event_meta( $post_id, $data );

		$created = $this->get_event( $post_id );
		if ( is_array( $created ) && ! empty( $sanitized_content['removed'] ) ) {
			$created['sanitized'] = $sanitized_content['removed'];
		}

		return $created;
	}

	/**
	 * Update an event.
	 *
	 * @param int   $id   Event ID.
	 * @param array $data Event data.
	 * @return array|WP_Error
	 */
	public function update_event( $id, $data ) {
		if ( ! $this->is_active() ) {
			return new WP_Error( 'events_not_active', __( 'TP Events is not active.', 'mcpwp' ), array( 'status' => 400 ) );
		}

		$post = get_post( $id );
		if ( ! $post || 'tp_event' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Event not found.', 'mcpwp' ), array( 'status' => 404 ) );
		}

		$post_data         = array( 'ID' => $id );
		$sanitized_content = array( 'removed' => array() );

		if ( isset( $data['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $data['title'] );
		}

		if ( isset( $data['content'] ) ) {
			$sanitized_content         = $this->sanitize_content_body( $data['content'] );
			$post_data['post_content'] = $sanitized_content['content'];
		}

		if ( isset( $data['excerpt'] ) ) {
			$post_data['post_excerpt'] = sanitize_textarea_field( $data['excerpt'] );
		}

		if ( isset( $data['status'] ) ) {
			$post_data['post_status'] = sanitize_key( $data['status'] );
		}

		$result = wp_update_post( $post_data, true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->set_event_meta( $id, $data );

		$updated = $this->get_event( $id );
		if ( is_array( $updated ) && ! empty( $sanitized_content['removed'] ) ) {
			$updated['sanitized'] = $sanitized_content['removed'];
		}

		return $updated;
	}

	// =========================================================================
	// Private Helpers
	// =========================================================================

	/**
	 * Set event meta fields.
	 *
	 * @param int   $post_id Event post ID.
	 * @param array $data    Data containing meta fields.
	 */
	private function set_event_meta( $post_id, $data ) {
		$meta_fields = array(
			'date_start'            => 'tp_event_date_start',
			'time_start'            => 'tp_event_time_start',
			'date_end'              => 'tp_event_date_end',
			'time_end'              => 'tp_event_time_end',
			'registration_end_date' => 'tp_event_registration_end_date',
			'registration_end_time' => 'tp_event_registration_end_time',
			'location'              => 'tp_event_location',
			'price'                 => 'tp_event_price',
			'qty'                   => 'tp_event_qty',
			'event_status'          => 'tp_event_status',
			'iframe'                => 'tp_event_iframe',
		);

		foreach ( $meta_fields as $key => $meta_key ) {
			if ( isset( $data[ $key ] ) ) {
				update_post_meta( $post_id, $meta_key, sanitize_text_field( $data[ $key ] ) );
			}
		}
	}

	/**
	 * Format event for API response.
	 *
	 * @param WP_Post $post     Post object.
	 * @param bool    $detailed Include extra details.
	 * @return array
	 */
	private function format_event( $post, $detailed = false ) {
		$data = array(
			'id'         => $post->ID,
			'title'      => $post->post_title,
			'slug'       => $post->post_name,
			'status'     => $post->post_status,
			'permalink'  => get_permalink( $post->ID ),
			'date_start' => get_post_meta( $post->ID, 'tp_event_date_start', true ),
			'time_start' => get_post_meta( $post->ID, 'tp_event_time_start', true ),
			'date_end'   => get_post_meta( $post->ID, 'tp_event_date_end', true ),
			'time_end'   => get_post_meta( $post->ID, 'tp_event_time_end', true ),
			'location'   => get_post_meta( $post->ID, 'tp_event_location', true ),
			'price'      => get_post_meta( $post->ID, 'tp_event_price', true ),
			'event_status' => get_post_meta( $post->ID, 'tp_event_status', true ),
			'date_created'  => $post->post_date,
			'date_modified' => $post->post_modified,
		);

		if ( $detailed ) {
			$data['content'] = $post->post_content;
			$data['excerpt'] = $post->post_excerpt;
			$data['qty']     = get_post_meta( $post->ID, 'tp_event_qty', true );
			$data['registration_end_date'] = get_post_meta( $post->ID, 'tp_event_registration_end_date', true );
			$data['registration_end_time'] = get_post_meta( $post->ID, 'tp_event_registration_end_time', true );
			$data['iframe']  = get_post_meta( $post->ID, 'tp_event_iframe', true );

			// Featured image.
			$thumbnail_id = get_post_thumbnail_id( $post->ID );
			if ( $thumbnail_id ) {
				$data['featured_image'] = array(
					'id'  => $thumbnail_id,
					'url' => wp_get_attachment_url( $thumbnail_id ),
				);
			}
		}

		return $data;
	}
}
