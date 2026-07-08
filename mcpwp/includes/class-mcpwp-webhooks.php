<?php
/**
 * Webhooks Manager
 *
 * @package mumcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles webhook registration, storage, and delivery.
 */
class Mcpwp_Webhooks {

	/**
	 * Singleton instance.
	 *
	 * @var Mcpwp_Webhooks
	 */
	private static $instance = null;

	/**
	 * Table name.
	 *
	 * @var string
	 */
	private $table;

	/**
	 * Available webhook events.
	 *
	 * @var array
	 */
	private $events = array(
		'post.created',
		'post.updated',
		'post.deleted',
		'post.published',
		'page.created',
		'page.updated',
		'page.deleted',
		'page.published',
		'media.uploaded',
		'media.deleted',
		'user.created',
		'user.updated',
		'user.deleted',
		'comment.created',
		'comment.approved',
		'comment.deleted',
		'approval.created',
		'approval.approved',
		'approval.rejected',
		'approval.applied',
		'approval.rolled_back',
		'seo.audit_completed',
		'api.alert.5xx_spike',
		'api.alert.auth_spike',
	);

	/**
	 * Get singleton instance.
	 *
	 * @return Mcpwp_Webhooks
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'mcpwp_webhooks';

		// Ensure table exists
		$this->ensure_table();

		$this->init_hooks();
	}

	/**
	 * Ensure the webhooks table exists, creating it if necessary.
	 */
	private function ensure_table() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( "SHOW TABLES LIKE %s", $this->table )
		);
		if ( $table_exists !== $this->table ) {
			self::create_table();
		}
	}

	/**
	 * Initialize WordPress hooks.
	 */
	private function init_hooks() {
		// Post hooks
		add_action( 'wp_insert_post', array( $this, 'on_post_save' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'on_post_delete' ) );
		add_action( 'transition_post_status', array( $this, 'on_post_status_change' ), 10, 3 );

		// Media hooks
		add_action( 'add_attachment', array( $this, 'on_media_upload' ) );
		add_action( 'delete_attachment', array( $this, 'on_media_delete' ) );

		// User hooks
		add_action( 'user_register', array( $this, 'on_user_created' ) );
		add_action( 'profile_update', array( $this, 'on_user_updated' ), 10, 2 );
		add_action( 'delete_user', array( $this, 'on_user_deleted' ) );

		// Comment hooks
		add_action( 'wp_insert_comment', array( $this, 'on_comment_created' ), 10, 2 );
		add_action( 'comment_unapproved_to_approved', array( $this, 'on_comment_approved' ) );
		add_action( 'delete_comment', array( $this, 'on_comment_deleted' ) );
	}

	/**
	 * Create webhooks table.
	 */
	public static function create_table() {
		global $wpdb;
		$table = $wpdb->prefix . 'mcpwp_webhooks';

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL,
			url varchar(2048) NOT NULL,
			secret varchar(255) DEFAULT NULL,
			events text NOT NULL,
			status varchar(20) DEFAULT 'active',
			retry_count int(11) DEFAULT 0,
			last_triggered datetime DEFAULT NULL,
			last_status varchar(50) DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY status (status)
		) $charset_collate;";

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		dbDelta( $sql );

		// Create delivery log table
		$log_table = $wpdb->prefix . 'mcpwp_webhook_logs';
		$sql_log = "CREATE TABLE {$log_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			webhook_id bigint(20) unsigned NOT NULL,
			event varchar(100) NOT NULL,
			payload longtext NOT NULL,
			response_code int(11) DEFAULT NULL,
			response_body text DEFAULT NULL,
			duration float DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY webhook_id (webhook_id),
			KEY created_at (created_at)
		) $charset_collate;";

		dbDelta( $sql_log );
	}

	/**
	 * Get available events.
	 *
	 * @return array Events list.
	 */
	public function get_events() {
		return $this->events;
	}

	/**
	 * Register a webhook.
	 *
	 * @param array $data Webhook data.
	 * @return int|WP_Error Webhook ID or error.
	 */
	public function register( $data ) {
		global $wpdb;

		// Validate required fields
		if ( empty( $data['name'] ) || empty( $data['url'] ) || empty( $data['events'] ) ) {
			return new WP_Error( 'missing_required', __( 'Name, URL, and events are required.', 'mumcp' ) );
		}

		// Validate URL format.
		if ( ! filter_var( $data['url'], FILTER_VALIDATE_URL ) ) {
			return new WP_Error( 'invalid_url', __( 'Invalid webhook URL.', 'mumcp' ) );
		}

		// SSRF protection: block internal/private URLs.
		if ( class_exists( 'Mcpwp_Security' ) ) {
			$ssrf_check = Mcpwp_Security::validate_external_url( $data['url'] );
			if ( is_wp_error( $ssrf_check ) ) {
				return $ssrf_check;
			}
		}

		// Validate events
		$events = is_array( $data['events'] ) ? $data['events'] : array( $data['events'] );
		$invalid_events = array_diff( $events, $this->events );
		if ( ! empty( $invalid_events ) ) {
			return new WP_Error(
				'invalid_events',
				/* translators: %s: comma-separated list of invalid event names */
				sprintf( __( 'Invalid events: %s', 'mumcp' ), implode( ', ', $invalid_events ) )
			);
		}

		// Generate secret if not provided
		$secret = ! empty( $data['secret'] ) ? $data['secret'] : wp_generate_password( 32, false );

		$result = $wpdb->insert(
			$this->table,
			array(
				'name'   => sanitize_text_field( $data['name'] ),
				'url'    => esc_url_raw( $data['url'] ),
				'secret' => $secret,
				'events' => wp_json_encode( $events ),
				'status' => 'active',
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return new WP_Error( 'db_error', __( 'Failed to register webhook.', 'mumcp' ) );
		}

		return $wpdb->insert_id;
	}

	/**
	 * Get webhook by ID.
	 *
	 * @param int $id Webhook ID.
	 * @return array|null Webhook data or null.
	 */
	public function get( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $this->table.
		$webhook = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ),
			ARRAY_A
		);

		if ( $webhook ) {
			$webhook['events'] = json_decode( $webhook['events'], true );
		}

		return $webhook;
	}

	/**
	 * List webhooks.
	 *
	 * @param array $args Query args.
	 * @return array Webhooks list.
	 */
	public function list_webhooks( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'status'   => null,
			'per_page' => 50,
			'page'     => 1,
		);
		$args = wp_parse_args( $args, $defaults );

		$where = '1=1';
		if ( $args['status'] ) {
			$where .= $wpdb->prepare( ' AND status = %s', $args['status'] );
		}

		$offset = ( $args['page'] - 1 ) * $args['per_page'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/where from safe sources.
		$webhooks = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$args['per_page'],
				$offset
			),
			ARRAY_A
		);

		foreach ( $webhooks as &$webhook ) {
			$webhook['events'] = json_decode( $webhook['events'], true );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/where from safe sources.
		$total = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} WHERE {$where}" );

		return array(
			'webhooks' => $webhooks,
			'total'    => (int) $total,
			'pages'    => ceil( $total / $args['per_page'] ),
			'page'     => $args['page'],
		);
	}

	/**
	 * Update webhook.
	 *
	 * @param int   $id   Webhook ID.
	 * @param array $data Update data.
	 * @return bool|WP_Error Success or error.
	 */
	public function update( $id, $data ) {
		global $wpdb;

		$webhook = $this->get( $id );
		if ( ! $webhook ) {
			return new WP_Error( 'not_found', __( 'Webhook not found.', 'mumcp' ) );
		}

		$update = array();
		$format = array();

		if ( isset( $data['name'] ) ) {
			$update['name'] = sanitize_text_field( $data['name'] );
			$format[] = '%s';
		}

		if ( isset( $data['url'] ) ) {
			if ( ! filter_var( $data['url'], FILTER_VALIDATE_URL ) ) {
				return new WP_Error( 'invalid_url', __( 'Invalid webhook URL.', 'mumcp' ) );
			}
			// SSRF protection.
			if ( class_exists( 'Mcpwp_Security' ) ) {
				$ssrf_check = Mcpwp_Security::validate_external_url( $data['url'] );
				if ( is_wp_error( $ssrf_check ) ) {
					return $ssrf_check;
				}
			}
			$update['url'] = esc_url_raw( $data['url'] );
			$format[] = '%s';
		}

		if ( isset( $data['events'] ) ) {
			$events = is_array( $data['events'] ) ? $data['events'] : array( $data['events'] );
			$invalid_events = array_diff( $events, $this->events );
			if ( ! empty( $invalid_events ) ) {
				return new WP_Error(
					'invalid_events',
					/* translators: %s: comma-separated list of invalid event names */
					sprintf( __( 'Invalid events: %s', 'mumcp' ), implode( ', ', $invalid_events ) )
				);
			}
			$update['events'] = wp_json_encode( $events );
			$format[] = '%s';
		}

		if ( isset( $data['status'] ) ) {
			$update['status'] = sanitize_key( $data['status'] );
			$format[] = '%s';
		}

		if ( isset( $data['secret'] ) ) {
			$update['secret'] = $data['secret'];
			$format[] = '%s';
		}

		if ( empty( $update ) ) {
			return new WP_Error( 'no_changes', __( 'No valid fields to update.', 'mumcp' ) );
		}

		$result = $wpdb->update( $this->table, $update, array( 'id' => $id ), $format, array( '%d' ) );

		return false !== $result;
	}

	/**
	 * Delete webhook.
	 *
	 * @param int $id Webhook ID.
	 * @return bool|WP_Error Success or error.
	 */
	public function delete( $id ) {
		global $wpdb;

		$webhook = $this->get( $id );
		if ( ! $webhook ) {
			return new WP_Error( 'not_found', __( 'Webhook not found.', 'mumcp' ) );
		}

		$result = $wpdb->delete( $this->table, array( 'id' => $id ), array( '%d' ) );

		// Also delete logs
		$log_table = $wpdb->prefix . 'mcpwp_webhook_logs';
		$wpdb->delete( $log_table, array( 'webhook_id' => $id ), array( '%d' ) );

		return false !== $result;
	}

	/**
	 * Trigger webhooks for an event.
	 *
	 * @param string $event   Event name.
	 * @param array  $payload Event payload.
	 */
	public function trigger( $event, $payload ) {
		global $wpdb;

		// Find webhooks listening to this event.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $this->table.
		$webhooks = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE status = 'active' AND events LIKE %s",
				'%"' . $event . '"%'
			),
			ARRAY_A
		);

		if ( empty( $webhooks ) ) {
			return;
		}

		// Add common fields to payload
		$payload['event']     = $event;
		$payload['timestamp'] = current_time( 'c' );
		$payload['site_url']  = get_site_url();

		foreach ( $webhooks as $webhook ) {
			$this->deliver( $webhook, $event, $payload );
		}
	}

	/**
	 * Deliver webhook.
	 *
	 * @param array  $webhook Webhook data.
	 * @param string $event   Event name.
	 * @param array  $payload Payload data.
	 */
	private function deliver( $webhook, $event, $payload ) {
		global $wpdb;

		$body = wp_json_encode( $payload );
		$signature = hash_hmac( 'sha256', $body, $webhook['secret'] );

		$start_time = microtime( true );

		$response = wp_remote_post(
			$webhook['url'],
			array(
				'timeout'     => 15,
				'redirection' => 0, // Disable redirects to prevent SSRF via redirect chains.
				'httpversion' => '1.1',
				'blocking'    => true,
				'sslverify'   => true,
				'headers'     => array(
					'Content-Type'           => 'application/json',
					// X-SPAI-* kept for backward-compat; remove in a future major.
					'X-SPAI-Event'           => $event,
					'X-SPAI-Signature'       => $signature,
					'X-SPAI-Webhook-ID'      => $webhook['id'],
					'X-SPAI-Delivery-ID'     => wp_generate_uuid4(),
					// X-MCPWP-* aliases (new canonical names).
					'X-MCPWP-Event'          => $event,
					'X-MCPWP-Signature'      => $signature,
					'X-MCPWP-Webhook-ID'     => $webhook['id'],
				),
				'body'        => $body,
			)
		);

		$duration = microtime( true ) - $start_time;

		// Log the delivery
		$log_table = $wpdb->prefix . 'mcpwp_webhook_logs';

		$response_code = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
		$response_body = is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_body( $response );

		$wpdb->insert(
			$log_table,
			array(
				'webhook_id'    => $webhook['id'],
				'event'         => $event,
				'payload'       => $body,
				'response_code' => $response_code,
				'response_body' => substr( $response_body, 0, 65535 ),
				'duration'      => $duration,
			),
			array( '%d', '%s', '%s', '%d', '%s', '%f' )
		);

		// Update webhook last triggered info
		$status = ( $response_code >= 200 && $response_code < 300 ) ? 'success' : 'failed';

		$wpdb->update(
			$this->table,
			array(
				'last_triggered' => current_time( 'mysql' ),
				'last_status'    => $status,
				'retry_count'    => 'success' === $status ? 0 : $webhook['retry_count'] + 1,
			),
			array( 'id' => $webhook['id'] ),
			array( '%s', '%s', '%d' ),
			array( '%d' )
		);

		// Disable webhook after too many failures
		if ( $webhook['retry_count'] >= 10 && 'failed' === $status ) {
			$wpdb->update(
				$this->table,
				array( 'status' => 'disabled' ),
				array( 'id' => $webhook['id'] ),
				array( '%s' ),
				array( '%d' )
			);
		}
	}

	/**
	 * Get delivery logs for a webhook.
	 *
	 * @param int   $webhook_id Webhook ID.
	 * @param array $args       Query args.
	 * @return array Logs.
	 */
	public function get_logs( $webhook_id, $args = array() ) {
		global $wpdb;

		$defaults = array(
			'per_page' => 50,
			'page'     => 1,
		);
		$args = wp_parse_args( $args, $defaults );

		$log_table = $wpdb->prefix . 'mcpwp_webhook_logs';
		$offset = ( $args['page'] - 1 ) * $args['per_page'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$logs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$log_table} WHERE webhook_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$webhook_id,
				$args['per_page'],
				$offset
			),
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$total = $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$log_table} WHERE webhook_id = %d", $webhook_id )
		);

		return array(
			'logs'  => $logs,
			'total' => (int) $total,
			'pages' => ceil( $total / $args['per_page'] ),
			'page'  => $args['page'],
		);
	}

	/**
	 * Test webhook.
	 *
	 * @param int $id Webhook ID.
	 * @return array|WP_Error Test result or error.
	 */
	public function test( $id ) {
		$webhook = $this->get( $id );
		if ( ! $webhook ) {
			return new WP_Error( 'not_found', __( 'Webhook not found.', 'mumcp' ) );
		}

		// Re-validate URL at send time to defend against DNS changes and unsafe updates.
		if ( class_exists( 'Mcpwp_Security' ) ) {
			$ssrf_check = Mcpwp_Security::validate_external_url( $webhook['url'] );
			if ( is_wp_error( $ssrf_check ) ) {
				return $ssrf_check;
			}
		}

		$payload = array(
			'test'    => true,
			'message' => __( 'This is a test webhook delivery from mumcp.', 'mumcp' ),
		);

		$body = wp_json_encode( $payload );
		$signature = hash_hmac( 'sha256', $body, $webhook['secret'] );

		$start_time = microtime( true );

		$response = wp_remote_post(
			$webhook['url'],
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'httpversion' => '1.1',
				'blocking'    => true,
				'sslverify'   => true,
				'headers'     => array(
					'Content-Type'      => 'application/json',
					// X-SPAI-* kept for backward-compat; remove in a future major.
					'X-SPAI-Event'      => 'test',
					'X-SPAI-Signature'  => $signature,
					'X-SPAI-Webhook-ID' => $webhook['id'],
					'X-SPAI-Test'       => 'true',
					// X-MCPWP-* aliases (new canonical names).
					'X-MCPWP-Event'     => 'test',
					'X-MCPWP-Signature' => $signature,
					'X-MCPWP-Webhook-ID' => $webhook['id'],
				),
				'body'        => $body,
			)
		);

		$duration = microtime( true ) - $start_time;

		if ( is_wp_error( $response ) ) {
			return array(
				'success'  => false,
				'error'    => $response->get_error_message(),
				'duration' => round( $duration, 3 ),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );

		return array(
			'success'       => $code >= 200 && $code < 300,
			'response_code' => $code,
			'response_body' => wp_remote_retrieve_body( $response ),
			'duration'      => round( $duration, 3 ),
		);
	}

	// Event Handlers

	/**
	 * Handle post save.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an update.
	 */
	public function on_post_save( $post_id, $post, $update ) {
		// Skip revisions and autosaves
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// Skip non-standard post types
		if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return;
		}

		$event = $update ? "{$post->post_type}.updated" : "{$post->post_type}.created";

		$this->trigger( $event, array(
			'id'        => $post_id,
			'title'     => $post->post_title,
			'status'    => $post->post_status,
			'type'      => $post->post_type,
			'permalink' => get_permalink( $post_id ),
			'author'    => $post->post_author,
		) );
	}

	/**
	 * Handle post status change.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post object.
	 */
	public function on_post_status_change( $new_status, $old_status, $post ) {
		if ( 'publish' === $new_status && 'publish' !== $old_status ) {
			if ( in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
				$this->trigger( "{$post->post_type}.published", array(
					'id'        => $post->ID,
					'title'     => $post->post_title,
					'type'      => $post->post_type,
					'permalink' => get_permalink( $post->ID ),
				) );
			}
		}
	}

	/**
	 * Handle post delete.
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_post_delete( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return;
		}

		$this->trigger( "{$post->post_type}.deleted", array(
			'id'    => $post_id,
			'title' => $post->post_title,
			'type'  => $post->post_type,
		) );
	}

	/**
	 * Handle media upload.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function on_media_upload( $attachment_id ) {
		$this->trigger( 'media.uploaded', array(
			'id'    => $attachment_id,
			'url'   => wp_get_attachment_url( $attachment_id ),
			'title' => get_the_title( $attachment_id ),
			'type'  => get_post_mime_type( $attachment_id ),
		) );
	}

	/**
	 * Handle media delete.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function on_media_delete( $attachment_id ) {
		$this->trigger( 'media.deleted', array(
			'id'    => $attachment_id,
			'title' => get_the_title( $attachment_id ),
		) );
	}

	/**
	 * Handle user created.
	 *
	 * @param int $user_id User ID.
	 */
	public function on_user_created( $user_id ) {
		$user = get_userdata( $user_id );
		$this->trigger( 'user.created', array(
			'id'       => $user_id,
			'username' => $user->user_login,
			'email'    => $user->user_email,
			'role'     => implode( ', ', $user->roles ),
		) );
	}

	/**
	 * Handle user updated.
	 *
	 * @param int   $user_id       User ID.
	 * @param array $old_user_data Old user data.
	 */
	public function on_user_updated( $user_id, $old_user_data ) {
		$user = get_userdata( $user_id );
		$this->trigger( 'user.updated', array(
			'id'       => $user_id,
			'username' => $user->user_login,
			'email'    => $user->user_email,
			'role'     => implode( ', ', $user->roles ),
		) );
	}

	/**
	 * Handle user deleted.
	 *
	 * @param int $user_id User ID.
	 */
	public function on_user_deleted( $user_id ) {
		$this->trigger( 'user.deleted', array(
			'id' => $user_id,
		) );
	}

	/**
	 * Handle comment created.
	 *
	 * @param int        $comment_id Comment ID.
	 * @param WP_Comment $comment    Comment object.
	 */
	public function on_comment_created( $comment_id, $comment ) {
		$this->trigger( 'comment.created', array(
			'id'      => $comment_id,
			'post_id' => $comment->comment_post_ID,
			'author'  => $comment->comment_author,
			'status'  => wp_get_comment_status( $comment_id ),
		) );
	}

	/**
	 * Handle comment approved.
	 *
	 * @param WP_Comment $comment Comment object.
	 */
	public function on_comment_approved( $comment ) {
		$this->trigger( 'comment.approved', array(
			'id'      => $comment->comment_ID,
			'post_id' => $comment->comment_post_ID,
			'author'  => $comment->comment_author,
		) );
	}

	/**
	 * Handle comment deleted.
	 *
	 * @param int $comment_id Comment ID.
	 */
	public function on_comment_deleted( $comment_id ) {
		$this->trigger( 'comment.deleted', array(
			'id' => $comment_id,
		) );
	}
}
