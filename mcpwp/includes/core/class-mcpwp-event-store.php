<?php
/**
 * AI-first event store and dispatcher.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores recent agent-relevant events and dispatches hooks/webhooks.
 */
class Mcpwp_Event_Store {

	/**
	 * Option name for recent events.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'mcpwp_recent_events';

	/**
	 * Maximum stored events.
	 *
	 * @var int
	 */
	const MAX_EVENTS = 200;

	/**
	 * Emit a normalized event.
	 *
	 * @param string $type    Event type.
	 * @param array  $payload Event payload.
	 * @param array  $meta    Event metadata.
	 * @return array Event record.
	 */
	public static function emit( $type, $payload = array(), $meta = array() ) {
		$type = self::sanitize_event_type( $type );
		$now  = gmdate( 'c' );

		$event = array(
			'id'                 => self::generate_event_id(),
			'type'               => $type,
			'hook'               => self::type_to_hook( $type ),
			'timestamp'          => $now,
			'site_url'           => get_site_url(),
			'actor'              => self::current_actor(),
			'resource'           => isset( $meta['resource'] ) && is_array( $meta['resource'] ) ? self::sanitize_array( $meta['resource'] ) : array(),
			'risk_level'         => isset( $meta['risk_level'] ) ? sanitize_key( (string) $meta['risk_level'] ) : 'low',
			'approval_state'     => isset( $meta['approval_state'] ) ? sanitize_key( (string) $meta['approval_state'] ) : '',
			'seo_state'          => isset( $meta['seo_state'] ) ? sanitize_key( (string) $meta['seo_state'] ) : '',
			'recommended_action' => isset( $meta['recommended_action'] ) ? sanitize_text_field( (string) $meta['recommended_action'] ) : '',
			'payload'            => self::sanitize_array( $payload ),
		);

		/**
		 * Filter a normalized MCPWP event before storage and dispatch.
		 *
		 * @param array  $event Event record.
		 * @param string $type  Event type.
		 */
		$event = apply_filters( 'mcpwp_event_payload', $event, $type );
		if ( ! is_array( $event ) ) {
			$event = array();
		}

		self::store( $event );

		/**
		 * Fires for every normalized MCPWP event.
		 *
		 * @param array $event Event record.
		 */
		do_action( 'mcpwp_event_emitted', $event );

		/**
		 * Fires for a specific normalized event hook, for example mcpwp_approval_created.
		 *
		 * @param array $event Event record.
		 */
		do_action( self::type_to_hook( $type ), $event );

		if ( class_exists( 'Mcpwp_Webhooks' ) ) {
			Mcpwp_Webhooks::get_instance()->trigger( $type, $event );
		}

		return $event;
	}

	/**
	 * List recent normalized events.
	 *
	 * @param array $args Query args.
	 * @return array Events and summary.
	 */
	public static function list_events( $args = array() ) {
		$events = array_values( get_option( self::OPTION_NAME, array() ) );
		$type   = isset( $args['type'] ) ? self::sanitize_event_type( $args['type'] ) : '';
		$limit  = isset( $args['limit'] ) ? min( 100, max( 1, absint( $args['limit'] ) ) ) : 50;

		if ( '' !== $type ) {
			$events = array_values(
				array_filter(
					$events,
					function ( $event ) use ( $type ) {
						return isset( $event['type'] ) && $type === $event['type'];
					}
				)
			);
		}

		return array(
			'events' => array_slice( $events, 0, $limit ),
			'total'  => count( $events ),
		);
	}

	/**
	 * Get event schema for agents and webhook subscribers.
	 *
	 * @return array Event schema.
	 */
	public static function get_schema() {
		return array(
			'approval.created' => array(
				'hook'        => 'mcpwp_approval_created',
				'description' => __( 'An approval request was created.', 'mcpwp' ),
			),
			'approval.approved' => array(
				'hook'        => 'mcpwp_approval_approved',
				'description' => __( 'An approval request was approved.', 'mcpwp' ),
			),
			'approval.rejected' => array(
				'hook'        => 'mcpwp_approval_rejected',
				'description' => __( 'An approval request was rejected.', 'mcpwp' ),
			),
			'approval.applied' => array(
				'hook'        => 'mcpwp_approval_applied',
				'description' => __( 'An approved change was applied.', 'mcpwp' ),
			),
			'approval.rolled_back' => array(
				'hook'        => 'mcpwp_approval_rolled_back',
				'description' => __( 'An applied change was rolled back.', 'mcpwp' ),
			),
			'seo.audit_completed' => array(
				'hook'        => 'mcpwp_seo_audit_completed',
				'description' => __( 'A stored SEO audit completed.', 'mcpwp' ),
			),
			'content.publish_spike' => array(
				'hook'        => 'mcpwp_content_publish_spike',
				'description' => __( 'Published posts+pages in the last 24h crossed both an absolute floor and 2x the trailing 30-day daily average — a possible scaled/automated publishing spike (#957).', 'mcpwp' ),
			),
		);
	}

	/**
	 * Store a recent event.
	 *
	 * @param array $event Event record.
	 * @return void
	 */
	private static function store( $event ) {
		$events = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $events ) ) {
			$events = array();
		}

		array_unshift( $events, $event );
		$events = array_slice( $events, 0, self::MAX_EVENTS );
		update_option( self::OPTION_NAME, $events, false );
	}

	/**
	 * Generate an event ID.
	 *
	 * @return string Event ID.
	 */
	private static function generate_event_id() {
		return 'evt_' . str_replace( '-', '', wp_generate_uuid4() );
	}

	/**
	 * Convert an event type to a WordPress hook name.
	 *
	 * @param string $type Event type.
	 * @return string Hook name.
	 */
	private static function type_to_hook( $type ) {
		return 'mcpwp_' . str_replace( '.', '_', self::sanitize_event_type( $type ) );
	}

	/**
	 * Sanitize event type.
	 *
	 * @param string $type Event type.
	 * @return string Sanitized type.
	 */
	private static function sanitize_event_type( $type ) {
		$type = strtolower( (string) $type );
		return preg_replace( '/[^a-z0-9_.-]/', '', $type );
	}

	/**
	 * Get current actor metadata.
	 *
	 * @return array Actor data.
	 */
	private static function current_actor() {
		$user_id = get_current_user_id();
		$user    = $user_id ? get_userdata( $user_id ) : null;

		return array(
			'user_id' => (int) $user_id,
			'login'   => $user ? sanitize_user( $user->user_login ) : '',
			'role'    => $user && ! empty( $user->roles ) ? sanitize_key( (string) $user->roles[0] ) : '',
		);
	}

	/**
	 * Sanitize nested event payload arrays.
	 *
	 * @param mixed $value Value.
	 * @return mixed Sanitized value.
	 */
	private static function sanitize_array( $value ) {
		if ( is_array( $value ) ) {
			$sanitized = array();
			foreach ( $value as $key => $item ) {
				$sanitized[ sanitize_key( (string) $key ) ] = self::sanitize_array( $item );
			}
			return $sanitized;
		}

		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}

		return sanitize_text_field( (string) $value );
	}
}
