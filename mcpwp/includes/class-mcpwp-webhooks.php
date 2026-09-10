<?php
/**
 * Webhooks Manager
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles webhook registration, storage, and delivery.
 */
class Mcpwp_Webhooks {

	/**
	 * Bytes the log's response_body column holds (MySQL TEXT).
	 */
	const LOG_COLUMN_BYTES = 65535;

	/**
	 * Appended to a response body this version cut to fit the column, so a
	 * reader can tell it from a row cut before 3.11.1, which cannot be
	 * redacted reliably.
	 */
	const LOG_TRUNCATION_MARK = "\n[truncated]";

	/**
	 * Shortest payload secret removed by value from a logged response body.
	 * Shorter values ("1", "ok", a year) would rewrite unrelated text across
	 * the whole body; they are still redacted by key in the payload column.
	 */
	const SECRET_ECHO_MIN_BYTES = 6;

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
			return new WP_Error( 'missing_required', __( 'Name, URL, and events are required.', 'mcpwp' ) );
		}

		// Validate URL format.
		if ( ! filter_var( $data['url'], FILTER_VALIDATE_URL ) ) {
			return new WP_Error( 'invalid_url', __( 'Invalid webhook URL.', 'mcpwp' ) );
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
				sprintf( __( 'Invalid events: %s', 'mcpwp' ), implode( ', ', $invalid_events ) )
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
			return new WP_Error( 'db_error', __( 'Failed to register webhook.', 'mcpwp' ) );
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
			return new WP_Error( 'not_found', __( 'Webhook not found.', 'mcpwp' ) );
		}

		$update = array();
		$format = array();

		if ( isset( $data['name'] ) ) {
			$update['name'] = sanitize_text_field( $data['name'] );
			$format[] = '%s';
		}

		if ( isset( $data['url'] ) ) {
			if ( ! filter_var( $data['url'], FILTER_VALIDATE_URL ) ) {
				return new WP_Error( 'invalid_url', __( 'Invalid webhook URL.', 'mcpwp' ) );
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
					sprintf( __( 'Invalid events: %s', 'mcpwp' ), implode( ', ', $invalid_events ) )
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
			return new WP_Error( 'no_changes', __( 'No valid fields to update.', 'mcpwp' ) );
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
			return new WP_Error( 'not_found', __( 'Webhook not found.', 'mcpwp' ) );
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

		// Re-validate the target at send time. register/update/test already run
		// validate_external_url, and redirection=0 blocks redirect-based SSRF, but
		// a webhook registered against a short-TTL host can DNS-rebind to an
		// internal/reserved address between registration and this delivery. Fail
		// closed and record it through the normal delivery-logging path below.
		$ssrf_check = class_exists( 'Mcpwp_Security' )
			? Mcpwp_Security::validate_external_url( $webhook['url'] )
			: true;

		if ( is_wp_error( $ssrf_check ) ) {
			$response = $ssrf_check;
		} else {
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
		}

		$duration = microtime( true ) - $start_time;

		// Log the delivery
		$log_table = $wpdb->prefix . 'mcpwp_webhook_logs';

		$response_code = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
		$response_body = is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_body( $response );

		// The receiver was sent the real payload above. The log keeps a
		// redacted copy of what went out and what came back, through the one
		// log redactor every other log uses. A receiver can echo the payload
		// in a body that redactor cannot open (an HTML debug page, form
		// encoding, XML, broken JSON), so the secret values it removed from the
		// payload are also removed from the response by value. All of it runs
		// before the cut to the column size.
		$log_payload = self::redact_log_copy( $body );
		$sent_values = self::replaced_values( $body, $log_payload );

		$wpdb->insert(
			$log_table,
			array(
				'webhook_id'    => $webhook['id'],
				'event'         => $event,
				'payload'       => $log_payload,
				'response_code' => $response_code,
				'response_body' => self::fit_log_column( self::scrub_values( self::redact_log_copy( (string) $response_body ), $sent_values ) ),
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
	 * The copy of a delivery body kept in mcpwp_webhook_logs.
	 *
	 * Mcpwp_Option_Access::redact_for_log() decodes a JSON (or serialised)
	 * body, redacts it by the site's log_redaction_keys and the plugin-wide
	 * secret detectors, and re-encodes it — byte-identical when nothing was
	 * secret; a body it cannot decode goes through its string handling (a
	 * credential-shaped string is redacted, plain text kept). When the
	 * redactor is not loaded the body is not logged and the row says so: an
	 * unredacted copy is never the fallback.
	 *
	 * @param string $text Body as sent or as received.
	 * @return string
	 */
	private static function redact_log_copy( $text ) {
		if ( ! class_exists( 'Mcpwp_Option_Access' ) || ! method_exists( 'Mcpwp_Option_Access', 'redact_for_log' ) ) {
			return '[not logged: log redaction unavailable]';
		}

		// A UTF-8 byte-order mark (some .NET and Windows receivers send one)
		// would hide a JSON body from the decoder.
		$text = (string) $text;
		if ( 0 === strncmp( $text, "\xEF\xBB\xBF", 3 ) ) {
			$text = (string) substr( $text, 3 );
		}

		$redacted = Mcpwp_Option_Access::redact_for_log( $text, Mcpwp_Option_Access::log_redaction_keys() );

		return is_string( $redacted ) ? $redacted : (string) wp_json_encode( $redacted );
	}

	/**
	 * The secret values redaction removed from a JSON payload.
	 *
	 * @param string $original The payload as sent.
	 * @param string $redacted Its log copy.
	 * @return array Values as keys.
	 */
	private static function replaced_values( $original, $redacted ) {
		$values = array();
		if ( ! is_string( $original ) || $original === $redacted ) {
			return $values;
		}

		$before = json_decode( $original, true );
		$after  = json_decode( (string) $redacted, true );
		if ( is_array( $before ) && is_array( $after ) ) {
			self::collect_replaced( $before, $after, $values, 0 );
		} else {
			// The whole copy was replaced (the redactor is unavailable).
			self::collect_leaves( $original, $values, 0 );
		}

		return $values;
	}

	/**
	 * Walk a payload and its redacted copy together; every leaf that differs
	 * was a secret.
	 *
	 * @param mixed $before Original node.
	 * @param mixed $after  Redacted node.
	 * @param array $values Collected by reference, values as keys.
	 * @param int   $depth  Recursion depth.
	 */
	private static function collect_replaced( $before, $after, array &$values, $depth ) {
		if ( $depth > 64 || $before === $after ) {
			return;
		}

		if ( is_array( $before ) && is_array( $after ) ) {
			foreach ( $before as $k => $v ) {
				if ( array_key_exists( $k, $after ) ) {
					self::collect_replaced( $v, $after[ $k ], $values, $depth + 1 );
				} else {
					self::collect_leaves( $v, $values, $depth + 1 );
				}
			}
			return;
		}

		// JSON inside a string is opened and re-encoded by the redactor.
		if ( is_string( $before ) && is_string( $after ) && '[redacted]' !== $after ) {
			$inner_before = json_decode( $before, true );
			$inner_after  = json_decode( $after, true );
			if ( is_array( $inner_before ) && is_array( $inner_after ) ) {
				self::collect_replaced( $inner_before, $inner_after, $values, $depth + 1 );
				return;
			}
		}

		self::collect_leaves( $before, $values, $depth + 1 );
	}

	/**
	 * Every scalar leaf of a node, long enough to scrub by value.
	 *
	 * @param mixed $node   Node.
	 * @param array $values Collected by reference, values as keys.
	 * @param int   $depth  Recursion depth.
	 */
	private static function collect_leaves( $node, array &$values, $depth ) {
		if ( $depth > 64 ) {
			return;
		}
		if ( is_array( $node ) ) {
			foreach ( $node as $v ) {
				self::collect_leaves( $v, $values, $depth + 1 );
			}
			return;
		}
		if ( is_string( $node ) || is_int( $node ) || is_float( $node ) ) {
			$node = (string) $node;
			if ( strlen( $node ) >= self::SECRET_ECHO_MIN_BYTES ) {
				$values[ $node ] = true;
			}
		}
	}

	/**
	 * Remove known secret values from text, in the encodings a receiver echoes
	 * them in (see echo_forms()). Base64, hex and other transforms are not
	 * covered.
	 *
	 * @param string $text   Text to scrub.
	 * @param array  $values Values as keys.
	 * @return string
	 */
	private static function scrub_values( $text, array $values ) {
		$text = (string) $text;
		if ( '' === $text || empty( $values ) ) {
			return $text;
		}

		$forms = array();
		foreach ( array_keys( $values ) as $value ) {
			foreach ( self::echo_forms( (string) $value ) as $candidate ) {
				if ( strlen( $candidate ) >= self::SECRET_ECHO_MIN_BYTES ) {
					$forms[ $candidate ] = strlen( $candidate );
				}
			}
		}

		// Longest first, so a value inside a longer one is not replaced first.
		arsort( $forms );

		return str_replace( array_map( 'strval', array_keys( $forms ) ), '[redacted]', $text );
	}

	/**
	 * The ways one value comes back inside a response body.
	 *
	 * - As sent, and URL-encoded (RFC 3986 and form encoding), with the hex
	 *   digits in upper case (PHP) and lower case (.NET HttpUtility.UrlEncode).
	 * - JSON string content: PHP's default; unescaped slashes and/or unicode;
	 *   PHP's fully HTML-safe form (JSON_HEX_*); each with \uXXXX in both
	 *   letter cases (PHP writes JSON_HEX_* escapes upper case, Go and Python
	 *   lower case — Python's ensure_ascii is the escaped-unicode,
	 *   unescaped-slash form).
	 * - Go's encoding/json and Rails' to_json, which no PHP flag set produces:
	 *   only < > & escaped (\u003c \u003e \u0026) plus U+2028/U+2029,
	 *   quotes as \", apostrophes and non-ASCII left as they are (review r3
	 *   R3-3); with and without escaped slashes.
	 * - Each JSON form escaped again (a JSON document carried in a string).
	 * - HTML: the value and every JSON form with & < > escaped, and quotes
	 *   left as they are or written as any common entity (ENT_QUOTES,
	 *   ENT_COMPAT, ENT_NOQUOTES; &quot; &#34; &#x22; &#039; &#39; &#x27; &apos;).
	 *
	 * @param string $value Secret value.
	 * @return string[]
	 */
	private static function echo_forms( $value ) {
		$value     = (string) $value;
		$json      = array();
		$hex_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
		$flag_sets = array( 0, JSON_UNESCAPED_SLASHES, JSON_UNESCAPED_UNICODE, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, $hex_flags, $hex_flags | JSON_UNESCAPED_SLASHES );

		$lower = static function ( $m ) {
			return '\\u' . strtolower( $m[1] );
		};
		$upper = static function ( $m ) {
			return '\\u' . strtoupper( $m[1] );
		};

		foreach ( $flag_sets as $flags ) {
			$encoded = wp_json_encode( $value, $flags );
			if ( ! is_string( $encoded ) || strlen( $encoded ) < 2 ) {
				continue;
			}
			$inner                 = substr( $encoded, 1, -1 );
			$json[ $inner ]        = true;
			$json[ (string) preg_replace_callback( '/\\\\u([0-9A-Fa-f]{4})/', $lower, $inner ) ] = true;
			$json[ (string) preg_replace_callback( '/\\\\u([0-9A-Fa-f]{4})/', $upper, $inner ) ] = true;
		}

		$plain_json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( is_string( $plain_json ) && strlen( $plain_json ) >= 2 ) {
			$go = str_replace( array( '<', '>', '&' ), array( '\\u003c', '\\u003e', '\\u0026' ), substr( $plain_json, 1, -1 ) );
			$go = str_replace( array( "\u{2028}", "\u{2029}" ), array( '\\u2028', '\\u2029' ), $go );
			foreach ( array( $go, str_replace( '/', '\\/', $go ) ) as $go_form ) {
				$json[ $go_form ] = true;
				$json[ (string) preg_replace_callback( '/\\\\u([0-9A-Fa-f]{4})/', $upper, $go_form ) ] = true;
			}
		}

		foreach ( array_keys( $json ) as $inner ) {
			foreach ( array( 0, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) as $flags ) {
				$twice = wp_json_encode( (string) $inner, $flags );
				if ( is_string( $twice ) && strlen( $twice ) >= 2 ) {
					$json[ substr( $twice, 1, -1 ) ] = true;
				}
			}
		}

		$forms = array( $value => true ) + $json;
		foreach ( array( rawurlencode( $value ), urlencode( $value ) ) as $url_form ) {
			$forms[ $url_form ] = true;
			$forms[ (string) preg_replace_callback(
				'/%[0-9A-F]{2}/',
				static function ( $m ) {
					return strtolower( $m[0] );
				},
				$url_form
			) ] = true;
		}

		foreach ( array_merge( array( $value ), array_map( 'strval', array_keys( $json ) ) ) as $plain ) {
			$base = htmlspecialchars( $plain, ENT_NOQUOTES, 'UTF-8' );
			if ( '' === $base ) {
				continue;
			}
			foreach ( array( '"', '&quot;', '&#34;', '&#x22;' ) as $double ) {
				foreach ( array( "'", '&#039;', '&#39;', '&#x27;', '&apos;' ) as $single ) {
					$forms[ str_replace( array( '"', "'" ), array( $double, $single ), $base ) ] = true;
				}
			}
		}

		return array_map( 'strval', array_keys( $forms ) );
	}

	/**
	 * Cut a response body to the column size, marking the cut.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function fit_log_column( $text ) {
		$text = (string) $text;
		if ( strlen( $text ) < self::LOG_COLUMN_BYTES ) {
			return $text;
		}

		$room = self::LOG_COLUMN_BYTES - strlen( self::LOG_TRUNCATION_MARK );
		$cut  = function_exists( 'mb_strcut' ) ? mb_strcut( $text, 0, $room, 'UTF-8' ) : substr( $text, 0, $room );

		return $cut . self::LOG_TRUNCATION_MARK;
	}

	/**
	 * Whether a stored response body was cut at the column size before
	 * 3.11.1: full length, and no mark. A cut JSON body cannot be opened, so
	 * a secret inside it is invisible to every redactor.
	 *
	 * @param string $text Stored body.
	 * @return bool
	 */
	private static function is_unmarked_cut( $text ) {
		$mark = self::LOG_TRUNCATION_MARK;
		return strlen( $text ) >= self::LOG_COLUMN_BYTES && substr( $text, -strlen( $mark ) ) !== $mark;
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

		// Rows written before delivery logs were redacted hold the raw bytes:
		// the same redaction and by-value scrub run on read, using the row's
		// own payload. Already-redacted rows come back unchanged.
		foreach ( (array) $logs as $i => $log ) {
			if ( ! is_array( $log ) ) {
				continue;
			}

			$raw_payload = isset( $log['payload'] ) ? (string) $log['payload'] : '';
			$log_payload = self::redact_log_copy( $raw_payload );
			$sent_values = self::replaced_values( $raw_payload, $log_payload );

			if ( isset( $log['payload'] ) ) {
				$logs[ $i ]['payload'] = $log_payload;
			}
			if ( isset( $log['response_body'] ) ) {
				$stored_body                 = (string) $log['response_body'];
				$logs[ $i ]['response_body'] = self::is_unmarked_cut( $stored_body )
					? '[not shown: this response was cut to fit the log before 3.11.1 and cannot be redacted reliably]'
					: self::scrub_values( self::redact_log_copy( $stored_body ), $sent_values );
			}
		}

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
			return new WP_Error( 'not_found', __( 'Webhook not found.', 'mcpwp' ) );
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
			'message' => __( 'This is a test webhook delivery from MCPWP.', 'mcpwp' ),
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

		// What the receiver answered goes back to the caller and, through the
		// REST layer, into the activity log: redacted like the delivery log.
		if ( is_wp_error( $response ) ) {
			return array(
				'success'  => false,
				'error'    => self::redact_log_copy( $response->get_error_message() ),
				'duration' => round( $duration, 3 ),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );

		return array(
			'success'       => $code >= 200 && $code < 300,
			'response_code' => $code,
			'response_body' => self::redact_log_copy( wp_remote_retrieve_body( $response ) ),
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
