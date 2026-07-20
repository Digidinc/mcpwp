<?php
/**
 * Plugin Activator
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fired during plugin activation.
 */
class Mcpwp_Activator {

	/**
	 * Activate the plugin.
	 *
	 * Generate API key if not exists, set up options.
	 */
	public static function activate() {
		// Create API role and user
		self::create_api_role_and_user();

		// Generate API key if not exists
		if ( ! get_option( 'mcpwp_api_key' ) ) {
			$api_key = self::generate_api_key();
			update_option( 'mcpwp_api_key', wp_hash_password( $api_key ) );
			// Store plain text temporarily so admin can see it on first visit
			set_transient( 'mcpwp_new_api_key', $api_key, HOUR_IN_SECONDS );
			// Flag first activation for welcome flow
			update_option( 'mcpwp_first_activation', true );
		}

		// Ensure scoped API key store exists for key-level revocation/scopes.
		self::ensure_scoped_api_keys();

		// Set default options
		if ( false === get_option( 'mcpwp_settings' ) ) {
			$defaults = array(
				'enable_logging'     => true,
				'log_retention_days' => 30,
				'log_store_response_data' => true,
				'log_redaction_keys' => array(
					'api_key',
					'x-api-key',
					'authorization',
					'password',
					'secret',
					'token',
					'access_token',
					'refresh_token',
					'client_secret',
				),
				'allowed_origins'    => '',
				'alerts_enabled'          => false,
				'alerts_window_minutes'   => 5,
				'alerts_cooldown_minutes' => 15,
				'alerts_5xx_threshold'    => 5,
				'alerts_auth_threshold'   => 10,
			);
			update_option( 'mcpwp_settings', $defaults );
		}

		// Create activity log table
		self::create_tables();

		// Ensure periodic log cleanup is scheduled.
		self::schedule_log_cleanup();

		// One-time: purge identity-less pending approvals (see method docblock).
		self::migrate_identity_less_approvals();

		// Set version
		update_option( 'mcpwp_version', MCPWP_VERSION );

		// Flush rewrite rules
		flush_rewrite_rules();
	}

	/**
	 * One-time purge of pending approvals that carry no requester identity.
	 *
	 * Before per-request identity stamping, an approval created through an OAuth
	 * connector was stored with an empty `requested_by_key_id`. After the fix,
	 * that grant reconnects as a stably-identified principal that would sail
	 * through the `empty( requested_by_key_id )` branch of check_self_approval()
	 * and approve/apply the very request it created — reopening the self-approval
	 * bypass for records that predate the fix. Unlike the token case this never
	 * self-heals, because a pending approval persists until actioned.
	 *
	 * The safe, non-runtime-semantic remediation is to drop those unattributed
	 * still-actionable records at upgrade so they must be recreated with proper
	 * attribution. Gated by a flag so it runs once (a later upgrade must not keep
	 * deleting legitimate admin-created empty-requester approvals). Both PENDING
	 * and APPROVED (approved-but-not-yet-applied) records are exploitable — the
	 * approve and apply paths both run check_self_approval(), and the old bypass
	 * could have already self-approved a request, so purging pending alone would
	 * leave an approved record applyable. Terminal records (applied, rejected,
	 * rolled_back) are left untouched — they can't be re-actioned.
	 */
	public static function migrate_identity_less_approvals() {
		if ( get_option( 'mcpwp_approvals_identity_migrated' ) ) {
			return;
		}

		$option_name    = class_exists( 'Mcpwp_Approvals' ) ? Mcpwp_Approvals::OPTION_NAME : 'mcpwp_approval_requests';
		$items          = get_option( $option_name, array() );
		$actionable     = array( 'pending', 'approved' );

		if ( is_array( $items ) && ! empty( $items ) ) {
			$changed = false;
			foreach ( $items as $id => $record ) {
				$status    = is_array( $record ) && isset( $record['status'] ) ? (string) $record['status'] : '';
				$requester = is_array( $record ) && isset( $record['requested_by_key_id'] ) ? (string) $record['requested_by_key_id'] : '';
				if ( in_array( $status, $actionable, true ) && '' === $requester ) {
					unset( $items[ $id ] );
					$changed = true;
				}
			}
			if ( $changed ) {
				update_option( $option_name, $items, false );
			}
		}

		update_option( 'mcpwp_approvals_identity_migrated', 1, false );
	}

	/**
	 * Create API role and user.
	 */
	private static function create_api_role_and_user() {
		// Add Role — principle of least privilege: no manage_options or edit_theme_options.
		add_role( 'mcpwp_api_agent', 'MCPWP API Agent', array(
			'read'               => true,
			'edit_posts'         => true,
			'edit_pages'         => true,
			'edit_others_posts'  => true,
			'edit_others_pages'  => true,
			'publish_posts'      => true,
			'publish_pages'      => true,
			'delete_posts'       => true,
			'delete_pages'       => true,
			'delete_others_posts' => true,
			'delete_others_pages' => true,
			'upload_files'       => true,
			'list_users'         => true,
			// Custom MCPWP capabilities (NOT admin-level).
			'mcpwp_manage_settings' => true,
			'mcpwp_manage_webhooks' => true,
		) );

		// Update existing role if it already has manage_options (migration).
		$role = get_role( 'mcpwp_api_agent' );
		if ( $role && $role->has_cap( 'manage_options' ) ) {
			$role->remove_cap( 'manage_options' );
			$role->remove_cap( 'edit_theme_options' );
			$role->add_cap( 'delete_others_posts' );
			$role->add_cap( 'delete_others_pages' );
			$role->add_cap( 'mcpwp_manage_settings' );
			$role->add_cap( 'mcpwp_manage_webhooks' );
		}

		// Create User
		$user = get_user_by( 'login', 'mcpwp_bot' );
		if ( ! $user ) {
			wp_insert_user( array(
				'user_login'   => 'mcpwp_bot',
				'user_pass'    => wp_generate_password( 64 ),
				'role'         => 'mcpwp_api_agent',
				'display_name' => 'MCPWP',
				'description'  => 'Service account for MCPWP API',
			) );
		}
	}

	/**
	 * Generate a secure API key.
	 *
	 * @return string API key.
	 */
	private static function generate_api_key() {
		return 'mcpwp_' . bin2hex( random_bytes( 24 ) );
	}

	/**
	 * Ensure scoped API key option exists and migrate legacy single-key storage.
	 */
	private static function ensure_scoped_api_keys() {
		$existing = get_option( 'mcpwp_api_keys', array() );
		if ( is_array( $existing ) && ! empty( $existing ) ) {
			return;
		}

		$legacy_key = get_option( 'mcpwp_api_key' );
		if ( empty( $legacy_key ) ) {
			return;
		}

		$legacy_hash = self::looks_like_password_hash( $legacy_key ) ? (string) $legacy_key : wp_hash_password( (string) $legacy_key );
		if ( $legacy_hash !== $legacy_key ) {
			update_option( 'mcpwp_api_key', $legacy_hash );
		}

		$key_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'mcpwp_', true );
		$record = array(
			'id'           => sanitize_key( (string) $key_id ),
			'label'        => __( 'Primary API Key', 'mcpwp' ),
			'hash'         => $legacy_hash,
			'scopes'       => array( 'read', 'write', 'admin' ),
			'created_at'   => current_time( 'mysql' ),
			'last_used_at' => null,
			'revoked_at'   => null,
		);

		update_option( 'mcpwp_api_keys', array( $record ) );
	}

	/**
	 * Ensure activity-log cleanup cron is scheduled.
	 */
	private static function schedule_log_cleanup() {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
			return;
		}

		if ( wp_next_scheduled( 'mcpwp_cleanup_logs' ) ) {
			return;
		}

		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'mcpwp_cleanup_logs' );
	}

	/**
	 * Check whether a stored key value appears to be a password hash.
	 *
	 * @param string $value Stored key value.
	 * @return bool True when value looks hashed.
	 */
	private static function looks_like_password_hash( $value ) {
		$value = (string) $value;
		return '' !== $value && '$' === substr( $value, 0, 1 );
	}

	/**
	 * Create database tables.
	 */
	private static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		// Activity log table
		$table_name = $wpdb->prefix . 'mcpwp_activity_log';
		$sql = "CREATE TABLE IF NOT EXISTS $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			action varchar(100) NOT NULL,
			endpoint varchar(255) NOT NULL,
			method varchar(10) NOT NULL,
			status_code int(3) DEFAULT NULL,
			ip_address varchar(45) DEFAULT NULL,
			user_agent text DEFAULT NULL,
			request_data longtext DEFAULT NULL,
			response_data longtext DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY action (action),
			KEY created_at (created_at)
		) $charset_collate;";
		dbDelta( $sql );

		// Webhooks table
		$webhooks_table = $wpdb->prefix . 'mcpwp_webhooks';
		$sql_webhooks = "CREATE TABLE IF NOT EXISTS $webhooks_table (
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
		dbDelta( $sql_webhooks );

		// Webhook logs table
		$logs_table = $wpdb->prefix . 'mcpwp_webhook_logs';
		$sql_logs = "CREATE TABLE IF NOT EXISTS $logs_table (
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
		dbDelta( $sql_logs );

		// Feedback table
		$feedback_table = $wpdb->prefix . 'mcpwp_feedback';
		$sql_feedback = "CREATE TABLE IF NOT EXISTS $feedback_table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(20) NOT NULL DEFAULT 'feedback',
			title varchar(255) NOT NULL,
			description text NOT NULL,
			agent varchar(100) DEFAULT '',
			priority varchar(20) DEFAULT 'medium',
			status varchar(20) DEFAULT 'open',
			github_issue_url varchar(500) DEFAULT '',
			meta longtext DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY (id),
			KEY type_status (type, status)
		) $charset_collate;";
		dbDelta( $sql_feedback );

		// Action log table — immutable per-tool audit trail (EU AI Act compliance)
		$action_log_table = $wpdb->prefix . 'mcpwp_action_log';
		$sql_action_log   = "CREATE TABLE IF NOT EXISTS $action_log_table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			log_id varchar(44) NOT NULL,
			timestamp datetime NOT NULL,
			tool_name varchar(100) NOT NULL,
			category varchar(50) NOT NULL DEFAULT '',
			api_key_id varchar(100) NOT NULL DEFAULT '',
			arguments longtext NOT NULL DEFAULT '',
			before_snap longtext DEFAULT NULL,
			after_snap longtext DEFAULT NULL,
			resource_type varchar(50) NOT NULL DEFAULT '',
			resource_id varchar(255) NOT NULL DEFAULT '',
			duration_ms int(11) DEFAULT NULL,
			success tinyint(1) NOT NULL DEFAULT 0,
			error_code varchar(50) NOT NULL DEFAULT '',
			rollback_supported tinyint(1) NOT NULL DEFAULT 0,
			rolled_back tinyint(1) NOT NULL DEFAULT 0,
			rolled_back_at datetime DEFAULT NULL,
			rolled_back_by varchar(44) DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY log_id (log_id),
			KEY timestamp (timestamp),
			KEY tool_name (tool_name),
			KEY resource (resource_type(50), resource_id(100)),
			KEY success (success)
		) $charset_collate;";
		dbDelta( $sql_action_log );
	}
}
