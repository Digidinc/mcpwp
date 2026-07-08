<?php
/**
 * Feedback Core
 *
 * @package mumcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles feedback submission, listing, and optional GitHub issue creation.
 */
class Mcpwp_Feedback {

	/**
	 * Valid feedback types.
	 *
	 * @var array
	 */
	const TYPES = array( 'bug_report', 'feature_request', 'feedback' );

	/**
	 * Valid priorities.
	 *
	 * @var array
	 */
	const PRIORITIES = array( 'low', 'medium', 'high', 'critical' );

	/**
	 * Valid statuses.
	 *
	 * @var array
	 */
	const STATUSES = array( 'open', 'acknowledged', 'resolved', 'closed' );

	/**
	 * Map feedback type to GitHub issue label.
	 *
	 * @var array
	 */
	const GITHUB_LABELS = array(
		'bug_report'      => 'bug',
		'feature_request' => 'enhancement',
		'feedback'        => 'feedback',
	);

	/**
	 * Submit feedback.
	 *
	 * @param array $args {
	 *     @type string $type        Required. bug_report, feature_request, or feedback.
	 *     @type string $title       Required. Short summary.
	 *     @type string $description Required. Detailed description.
	 *     @type string $agent       Optional. AI model name.
	 *     @type string $priority    Optional. low, medium, high, critical.
	 *     @type array  $meta        Optional. Extra context (page_id, tool_name, error_message).
	 * }
	 * @return array|WP_Error Feedback entry on success.
	 */
	public static function submit( $args ) {
		global $wpdb;

		$type = isset( $args['type'] ) ? sanitize_text_field( $args['type'] ) : '';
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return new WP_Error( 'invalid_type', 'Type must be one of: ' . implode( ', ', self::TYPES ), array( 'status' => 400 ) );
		}

		$title = isset( $args['title'] ) ? sanitize_text_field( $args['title'] ) : '';
		if ( '' === $title ) {
			return new WP_Error( 'missing_title', 'Title is required.', array( 'status' => 400 ) );
		}

		$description = isset( $args['description'] ) ? sanitize_textarea_field( $args['description'] ) : '';
		if ( '' === $description ) {
			return new WP_Error( 'missing_description', 'Description is required.', array( 'status' => 400 ) );
		}

		$agent    = isset( $args['agent'] ) ? sanitize_text_field( $args['agent'] ) : '';
		$priority = isset( $args['priority'] ) && in_array( $args['priority'], self::PRIORITIES, true )
			? $args['priority']
			: 'medium';

		$meta = isset( $args['meta'] ) ? $args['meta'] : array();
		if ( is_string( $meta ) ) {
			$decoded = json_decode( $meta, true );
			$meta    = is_array( $decoded ) ? $decoded : array();
		}
		$meta_json = wp_json_encode( $meta );

		$table = $wpdb->prefix . 'mcpwp_feedback';
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			$table,
			array(
				'type'        => $type,
				'title'       => $title,
				'description' => $description,
				'agent'       => $agent,
				'priority'    => $priority,
				'status'      => 'open',
				'meta'        => $meta_json,
				'created_at'  => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'db_error', 'Failed to save feedback.', array( 'status' => 500 ) );
		}

		$feedback_id = (int) $wpdb->insert_id;

		// Attempt GitHub issue creation.
		$github_url = self::maybe_create_github_issue( $type, $title, $description, $agent, $priority, $meta );
		if ( ! empty( $github_url ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array( 'github_issue_url' => $github_url ),
				array( 'id' => $feedback_id ),
				array( '%s' ),
				array( '%d' )
			);
		}

		return array(
			'id'               => $feedback_id,
			'type'             => $type,
			'title'            => $title,
			'status'           => 'open',
			'priority'         => $priority,
			'github_issue_url' => $github_url ?: null,
			'created_at'       => $now,
		);
	}

	/**
	 * List feedback entries.
	 *
	 * @param array $args {
	 *     @type string $type   Optional. Filter by type.
	 *     @type string $status Optional. Filter by status. Default 'open'.
	 *     @type int    $limit  Optional. Max results. Default 20.
	 * }
	 * @return array Feedback entries.
	 */
	public static function list_entries( $args = array() ) {
		global $wpdb;

		$table  = $wpdb->prefix . 'mcpwp_feedback';
		$where  = array();
		$values = array();

		if ( ! empty( $args['type'] ) && in_array( $args['type'], self::TYPES, true ) ) {
			$where[]  = 'type = %s';
			$values[] = $args['type'];
		}

		$status = isset( $args['status'] ) ? $args['status'] : 'open';
		if ( 'all' !== $status && in_array( $status, self::STATUSES, true ) ) {
			$where[]  = 'status = %s';
			$values[] = $status;
		}

		$limit    = isset( $args['limit'] ) ? min( 100, max( 1, absint( $args['limit'] ) ) ) : 20;
		$where_sql = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';

		$query = "SELECT * FROM {$table} {$where_sql} ORDER BY created_at DESC LIMIT %d";
		$values[] = $limit;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $query built from safe table name and validated where clauses.
		$results = $wpdb->get_results( $wpdb->prepare( $query, $values ), ARRAY_A );

		if ( ! is_array( $results ) ) {
			return array();
		}

		foreach ( $results as &$row ) {
			$row['id']   = (int) $row['id'];
			$row['meta'] = json_decode( $row['meta'], true ) ?: array();
		}

		return $results;
	}

	/**
	 * Central feedback relay URL.
	 *
	 * All feedback is sent here so plugin authors receive it.
	 * The relay creates GitHub issues using a server-side token.
	 *
	 * @var string
	 */
	const RELAY_URL = 'https://mcpwp.net/wp-json/mcpwp/v1/feedback/relay';

	/**
	 * Route feedback to the central relay and optionally to a local GitHub repo.
	 *
	 * @param string $type        Feedback type.
	 * @param string $title       Feedback title.
	 * @param string $description Feedback description.
	 * @param string $agent       AI agent name.
	 * @param string $priority    Priority level.
	 * @param array  $meta        Extra context.
	 * @return string GitHub issue URL, or empty string.
	 */
	private static function maybe_create_github_issue( $type, $title, $description, $agent, $priority, $meta ) {
		// 1. Always phone home to central relay so plugin authors receive feedback.
		$github_url = self::send_to_relay( $type, $title, $description, $agent, $priority, $meta );

		// 2. Also create on local GitHub if the site owner configured their own repo.
		if ( empty( $github_url ) ) {
			$github_url = self::create_local_github_issue( $type, $title, $description, $agent, $priority, $meta );
		}

		return $github_url;
	}

	/**
	 * Send feedback to the central relay endpoint.
	 *
	 * @param string $type        Feedback type.
	 * @param string $title       Feedback title.
	 * @param string $description Feedback description.
	 * @param string $agent       AI agent name.
	 * @param string $priority    Priority level.
	 * @param array  $meta        Extra context.
	 * @return string GitHub issue URL from relay, or empty string.
	 */
	private static function send_to_relay( $type, $title, $description, $agent, $priority, $meta ) {
		// Skip relay if this IS the relay site (avoid infinite loop).
		$relay_host = wp_parse_url( self::RELAY_URL, PHP_URL_HOST );
		$site_host  = wp_parse_url( get_site_url(), PHP_URL_HOST );
		if ( $relay_host === $site_host ) {
			return '';
		}

		// Allow disabling phone-home via constant.
		if ( defined( 'MCPWP_DISABLE_FEEDBACK_RELAY' ) && MCPWP_DISABLE_FEEDBACK_RELAY ) {
			return '';
		}

		$payload = array(
			'type'           => $type,
			'title'          => $title,
			'description'    => $description,
			'agent'          => $agent,
			'priority'       => $priority,
			'meta'           => is_array( $meta ) ? $meta : array(),
			'site_url'       => get_site_url(),
			'site_name'      => get_bloginfo( 'name' ),
			'plugin_version' => defined( 'MCPWP_VERSION' ) ? MCPWP_VERSION : 'unknown',
		);

		$response = wp_remote_post(
			self::RELAY_URL,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
					'User-Agent'   => 'mumcp/' . ( defined( 'MCPWP_VERSION' ) ? MCPWP_VERSION : '0' ),
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 201 !== $code ) {
			return '';
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return isset( $data['github_issue_url'] ) ? esc_url_raw( $data['github_issue_url'] ) : '';
	}

	/**
	 * Create a GitHub issue using the local site's own github_token (optional).
	 *
	 * @param string $type        Feedback type.
	 * @param string $title       Feedback title.
	 * @param string $description Feedback description.
	 * @param string $agent       AI agent name.
	 * @param string $priority    Priority level.
	 * @param array  $meta        Extra context.
	 * @return string GitHub issue URL, or empty string.
	 */
	private static function create_local_github_issue( $type, $title, $description, $agent, $priority, $meta ) {
		$settings = get_option( 'mcpwp_settings', array() );
		$token    = isset( $settings['github_token'] ) ? $settings['github_token'] : '';
		$repo     = isset( $settings['github_repo'] ) ? $settings['github_repo'] : '';

		if ( empty( $token ) || empty( $repo ) ) {
			return '';
		}

		if ( ! preg_match( '/^[a-zA-Z0-9._-]+\/[a-zA-Z0-9._-]+$/', $repo ) ) {
			return '';
		}

		$body = self::build_github_issue_body( $type, $title, $description, $agent, $priority, $meta );
		$label = isset( self::GITHUB_LABELS[ $type ] ) ? self::GITHUB_LABELS[ $type ] : 'feedback';

		$response = wp_remote_post(
			"https://api.github.com/repos/{$repo}/issues",
			array(
				'headers' => array(
					'Authorization' => "Bearer {$token}",
					'Accept'        => 'application/vnd.github+json',
					'Content-Type'  => 'application/json',
					'User-Agent'    => 'mumcp/' . MCPWP_VERSION,
				),
				'body'    => wp_json_encode( array(
					'title'  => "[{$type}] {$title}",
					'body'   => $body,
					'labels' => array( $label, 'mcpwp' ),
				) ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 201 !== $code ) {
			return '';
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return isset( $data['html_url'] ) ? esc_url_raw( $data['html_url'] ) : '';
	}

	/**
	 * Build GitHub issue markdown body.
	 *
	 * @param string $type        Feedback type.
	 * @param string $title       Feedback title.
	 * @param string $description Feedback description.
	 * @param string $agent       AI agent name.
	 * @param string $priority    Priority level.
	 * @param array  $meta        Extra context.
	 * @param string $site_url    Optional site URL (for relay).
	 * @param string $site_name   Optional site name (for relay).
	 * @param string $version     Optional plugin version (for relay).
	 * @return string Markdown body.
	 */
	public static function build_github_issue_body( $type, $title, $description, $agent, $priority, $meta, $site_url = '', $site_name = '', $version = '' ) {
		$body = "## {$title}\n\n";
		$body .= "**Type:** {$type}\n";
		$body .= "**Priority:** {$priority}\n";
		if ( ! empty( $agent ) ) {
			$body .= "**AI Agent:** {$agent}\n";
		}
		if ( ! empty( $site_url ) ) {
			$body .= "**Site:** {$site_name} ({$site_url})\n";
		}
		if ( ! empty( $version ) ) {
			$body .= "**Plugin Version:** {$version}\n";
		}
		$body .= "\n### Description\n\n{$description}\n";

		if ( ! empty( $meta ) ) {
			$body .= "\n### Context\n\n";
			foreach ( $meta as $key => $value ) {
				$display_value = is_array( $value ) ? wp_json_encode( $value ) : (string) $value;
				$body .= "- **{$key}:** {$display_value}\n";
			}
		}

		$body .= "\n---\n*Submitted via mumcp feedback system*";

		return $body;
	}

	/**
	 * Create a GitHub issue from relay data (used by the relay endpoint).
	 *
	 * @param array $data Relay payload.
	 * @return array{success: bool, github_issue_url: string, message: string}
	 */
	public static function create_github_issue_from_relay( $data ) {
		$settings = get_option( 'mcpwp_settings', array() );
		$token    = isset( $settings['github_token'] ) ? $settings['github_token'] : '';
		$repo     = isset( $settings['github_repo'] ) ? $settings['github_repo'] : '';

		if ( empty( $token ) || empty( $repo ) ) {
			return array(
				'success'          => false,
				'github_issue_url' => '',
				'message'          => 'GitHub integration not configured on relay server.',
			);
		}

		$type        = isset( $data['type'] ) ? sanitize_text_field( $data['type'] ) : 'feedback';
		$title       = isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : 'Untitled feedback';
		$description = isset( $data['description'] ) ? sanitize_textarea_field( $data['description'] ) : '';
		$agent       = isset( $data['agent'] ) ? sanitize_text_field( $data['agent'] ) : '';
		$priority    = isset( $data['priority'] ) ? sanitize_text_field( $data['priority'] ) : 'medium';
		$meta        = isset( $data['meta'] ) && is_array( $data['meta'] ) ? $data['meta'] : array();
		$site_url    = isset( $data['site_url'] ) ? esc_url_raw( $data['site_url'] ) : '';
		$site_name   = isset( $data['site_name'] ) ? sanitize_text_field( $data['site_name'] ) : '';
		$version     = isset( $data['plugin_version'] ) ? sanitize_text_field( $data['plugin_version'] ) : '';

		$label = isset( self::GITHUB_LABELS[ $type ] ) ? self::GITHUB_LABELS[ $type ] : 'feedback';
		$body  = self::build_github_issue_body( $type, $title, $description, $agent, $priority, $meta, $site_url, $site_name, $version );

		// Check for duplicate: search open issues with the same title.
		$search_title = "[{$type}] {$title}";
		$search_url   = "https://api.github.com/search/issues?" . http_build_query( array(
			'q' => "repo:{$repo} is:issue is:open in:title " . $search_title,
		) );

		$search_response = wp_remote_get(
			$search_url,
			array(
				'headers' => array(
					'Authorization' => "Bearer {$token}",
					'Accept'        => 'application/vnd.github+json',
					'User-Agent'    => 'Mumega-MCP-Relay/' . MCPWP_VERSION,
				),
				'timeout' => 10,
			)
		);

		if ( ! is_wp_error( $search_response ) ) {
			$search_data = json_decode( wp_remote_retrieve_body( $search_response ), true );
			if ( isset( $search_data['total_count'] ) && $search_data['total_count'] > 0 ) {
				$existing_url = isset( $search_data['items'][0]['html_url'] ) ? $search_data['items'][0]['html_url'] : '';
				if ( ! empty( $existing_url ) ) {
					return array(
						'success'          => true,
						'github_issue_url' => esc_url_raw( $existing_url ),
						'message'          => 'Duplicate issue found, returning existing.',
					);
				}
			}
		}

		$response = wp_remote_post(
			"https://api.github.com/repos/{$repo}/issues",
			array(
				'headers' => array(
					'Authorization' => "Bearer {$token}",
					'Accept'        => 'application/vnd.github+json',
					'Content-Type'  => 'application/json',
					'User-Agent'    => 'Mumega-MCP-Relay/' . MCPWP_VERSION,
				),
				'body'    => wp_json_encode( array(
					'title'  => $search_title,
					'body'   => $body,
					'labels' => array( $label, 'mcpwp', 'user-feedback' ),
				) ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success'          => false,
				'github_issue_url' => '',
				'message'          => $response->get_error_message(),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$resp_data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 201 !== $code ) {
			return array(
				'success'          => false,
				'github_issue_url' => '',
				'message'          => isset( $resp_data['message'] ) ? $resp_data['message'] : "GitHub API returned HTTP {$code}",
			);
		}

		return array(
			'success'          => true,
			'github_issue_url' => isset( $resp_data['html_url'] ) ? esc_url_raw( $resp_data['html_url'] ) : '',
			'message'          => 'GitHub issue created.',
		);
	}
}
