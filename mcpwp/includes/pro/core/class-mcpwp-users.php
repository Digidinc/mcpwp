<?php
/**
 * User Management Handler
 *
 * @package MCPWP_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * User management functionality.
 *
 * Handles user CRUD operations and role management.
 */
class Mcpwp_Users {

	/**
	 * Get all users.
	 *
	 * @param array $args Query arguments.
	 * @return array Users list.
	 */
	public function get_users( $args = array() ) {
		$defaults = array(
			'number'  => isset( $args['per_page'] ) ? absint( $args['per_page'] ) : 50,
			'paged'   => isset( $args['page'] ) ? absint( $args['page'] ) : 1,
			'orderby' => isset( $args['orderby'] ) ? sanitize_key( $args['orderby'] ) : 'registered',
			'order'   => isset( $args['order'] ) && 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC',
		);

		// Filter by role.
		if ( ! empty( $args['role'] ) ) {
			$defaults['role'] = sanitize_text_field( $args['role'] );
		}

		// Search.
		if ( ! empty( $args['search'] ) ) {
			$defaults['search'] = '*' . sanitize_text_field( $args['search'] ) . '*';
			$defaults['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
		}

		$user_query = new WP_User_Query( $defaults );
		$users = $user_query->get_results();

		$result = array();
		foreach ( $users as $user ) {
			$result[] = $this->format_user( $user );
		}

		return array(
			'users' => $result,
			'total' => $user_query->get_total(),
			'pages' => ceil( $user_query->get_total() / $defaults['number'] ),
			'page'  => $defaults['paged'],
		);
	}

	/**
	 * Get single user.
	 *
	 * @param int $user_id User ID.
	 * @return array|WP_Error User data.
	 */
	public function get_user( $user_id ) {
		$user = get_user_by( 'ID', $user_id );

		if ( ! $user ) {
			return new WP_Error( 'not_found', __( 'User not found.', 'mumcp' ) );
		}

		return $this->format_user( $user, true );
	}

	/**
	 * Create a new user.
	 *
	 * @param array $data User data.
	 * @return array|WP_Error Created user or error.
	 */
	public function create_user( $data ) {
		// Validate required fields.
		if ( empty( $data['username'] ) ) {
			return new WP_Error( 'missing_username', __( 'Username is required.', 'mumcp' ) );
		}

		if ( empty( $data['email'] ) ) {
			return new WP_Error( 'missing_email', __( 'Email is required.', 'mumcp' ) );
		}

		// Check if username exists.
		if ( username_exists( $data['username'] ) ) {
			return new WP_Error( 'username_exists', __( 'Username already exists.', 'mumcp' ) );
		}

		// Check if email exists.
		if ( email_exists( $data['email'] ) ) {
			return new WP_Error( 'email_exists', __( 'Email already exists.', 'mumcp' ) );
		}

		$userdata = array(
			'user_login'   => sanitize_user( $data['username'] ),
			'user_email'   => sanitize_email( $data['email'] ),
			'user_pass'    => ! empty( $data['password'] ) ? $data['password'] : wp_generate_password( 16, true, true ),
			'display_name' => ! empty( $data['display_name'] ) ? sanitize_text_field( $data['display_name'] ) : $data['username'],
			'first_name'   => ! empty( $data['first_name'] ) ? sanitize_text_field( $data['first_name'] ) : '',
			'last_name'    => ! empty( $data['last_name'] ) ? sanitize_text_field( $data['last_name'] ) : '',
			'nickname'     => ! empty( $data['nickname'] ) ? sanitize_text_field( $data['nickname'] ) : $data['username'],
			'description'  => ! empty( $data['bio'] ) ? sanitize_textarea_field( $data['bio'] ) : '',
			'user_url'     => ! empty( $data['url'] ) ? esc_url_raw( $data['url'] ) : '',
			'role'         => ! empty( $data['role'] ) ? sanitize_text_field( $data['role'] ) : 'subscriber',
		);

		// Validate role.
		$valid_roles = array_keys( wp_roles()->roles );
		if ( ! in_array( $userdata['role'], $valid_roles, true ) ) {
			$userdata['role'] = 'subscriber';
		}

		// Prevent creating administrators via API unless current user is admin.
		if ( 'administrator' === $userdata['role'] && ! current_user_can( 'create_users' ) ) {
			return new WP_Error( 'cannot_create_admin', __( 'Cannot create administrator users.', 'mumcp' ) );
		}

		$user_id = wp_insert_user( $userdata );

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		// Set additional meta.
		if ( ! empty( $data['meta'] ) && is_array( $data['meta'] ) ) {
			foreach ( $data['meta'] as $key => $value ) {
				$meta_key = sanitize_key( $key );
				// Never let API-supplied meta write privilege-defining keys
				// (capabilities / user level / session tokens) — that is a role
				// escalation vector.
				if ( self::is_protected_user_meta_key( $meta_key ) ) {
					continue;
				}
				update_user_meta( $user_id, $meta_key, $value );
			}
		}

		// Send notification if requested.
		if ( ! empty( $data['send_notification'] ) ) {
			wp_new_user_notification( $user_id, null, 'both' );
		}

		return $this->get_user( $user_id );
	}

	/**
	 * Update a user.
	 *
	 * @param int   $user_id User ID.
	 * @param array $data    Update data.
	 * @return array|WP_Error Updated user or error.
	 */
	public function update_user( $user_id, $data ) {
		$user = get_user_by( 'ID', $user_id );

		if ( ! $user ) {
			return new WP_Error( 'not_found', __( 'User not found.', 'mumcp' ) );
		}

		// Prevent modifying users with higher privileges.
		if ( ! $this->can_modify_user( $user ) ) {
			return new WP_Error( 'cannot_modify', __( 'Cannot modify this user.', 'mumcp' ) );
		}

		$userdata = array( 'ID' => $user_id );

		if ( isset( $data['email'] ) ) {
			$email = sanitize_email( $data['email'] );
			// Check if email exists for another user.
			$existing = email_exists( $email );
			if ( $existing && $existing !== $user_id ) {
				return new WP_Error( 'email_exists', __( 'Email already exists.', 'mumcp' ) );
			}
			$userdata['user_email'] = $email;
		}

		if ( isset( $data['display_name'] ) ) {
			$userdata['display_name'] = sanitize_text_field( $data['display_name'] );
		}

		if ( isset( $data['first_name'] ) ) {
			$userdata['first_name'] = sanitize_text_field( $data['first_name'] );
		}

		if ( isset( $data['last_name'] ) ) {
			$userdata['last_name'] = sanitize_text_field( $data['last_name'] );
		}

		if ( isset( $data['nickname'] ) ) {
			$userdata['nickname'] = sanitize_text_field( $data['nickname'] );
		}

		if ( isset( $data['bio'] ) ) {
			$userdata['description'] = sanitize_textarea_field( $data['bio'] );
		}

		if ( isset( $data['url'] ) ) {
			$userdata['user_url'] = esc_url_raw( $data['url'] );
		}

		if ( isset( $data['password'] ) && ! empty( $data['password'] ) ) {
			$userdata['user_pass'] = $data['password'];
		}

		// Role change.
		if ( isset( $data['role'] ) ) {
			$new_role = sanitize_text_field( $data['role'] );
			$valid_roles = array_keys( wp_roles()->roles );

			if ( in_array( $new_role, $valid_roles, true ) ) {
				// Prevent promoting to admin.
				if ( 'administrator' === $new_role && ! current_user_can( 'promote_users' ) ) {
					return new WP_Error( 'cannot_promote_admin', __( 'Cannot promote to administrator.', 'mumcp' ) );
				}
				$userdata['role'] = $new_role;
			}
		}

		$result = wp_update_user( $userdata );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Update additional meta.
		if ( ! empty( $data['meta'] ) && is_array( $data['meta'] ) ) {
			foreach ( $data['meta'] as $key => $value ) {
				$meta_key = sanitize_key( $key );
				// Same privilege-escalation guard as create_user().
				if ( self::is_protected_user_meta_key( $meta_key ) ) {
					continue;
				}
				update_user_meta( $user_id, $meta_key, $value );
			}
		}

		return $this->get_user( $user_id );
	}

	/**
	 * Whether a user-meta key is privilege-defining and must never be writable
	 * from API-supplied data.
	 *
	 * Blocks capabilities / user-level keys — including multisite-prefixed
	 * variants such as wp_2_capabilities and wp_2_user_level — and the session
	 * token store, closing the meta.wp_capabilities role-escalation vector.
	 *
	 * @param string $meta_key Already-sanitized (sanitize_key) meta key.
	 * @return bool True when the key is protected and must be skipped.
	 */
	protected static function is_protected_user_meta_key( $meta_key ) {
		if ( 'session_tokens' === $meta_key ) {
			return true;
		}
		return (bool) preg_match( '/(^|_)(capabilities|user_level)$/', (string) $meta_key );
	}

	/**
	 * Delete a user.
	 *
	 * @param int $user_id  User ID.
	 * @param int $reassign Reassign posts to this user ID.
	 * @return bool|WP_Error True on success.
	 */
	public function delete_user( $user_id, $reassign = null ) {
		$user = get_user_by( 'ID', $user_id );

		if ( ! $user ) {
			return new WP_Error( 'not_found', __( 'User not found.', 'mumcp' ) );
		}

		// Prevent deleting self.
		if ( get_current_user_id() === $user_id ) {
			return new WP_Error( 'cannot_delete_self', __( 'Cannot delete yourself.', 'mumcp' ) );
		}

		// Prevent deleting users with higher privileges.
		if ( ! $this->can_modify_user( $user ) ) {
			return new WP_Error( 'cannot_delete', __( 'Cannot delete this user.', 'mumcp' ) );
		}

		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		$result = wp_delete_user( $user_id, $reassign );

		if ( ! $result ) {
			return new WP_Error( 'delete_failed', __( 'Failed to delete user.', 'mumcp' ) );
		}

		return true;
	}

	/**
	 * Get all roles.
	 *
	 * @return array Roles list.
	 */
	public function get_roles() {
		$wp_roles = wp_roles();
		$roles = array();

		foreach ( $wp_roles->roles as $role => $details ) {
			$roles[] = array(
				'role'         => $role,
				'name'         => translate_user_role( $details['name'] ),
				'capabilities' => array_keys( array_filter( $details['capabilities'] ) ),
				'user_count'   => $this->count_users_by_role( $role ),
			);
		}

		return $roles;
	}

	/**
	 * Get user capabilities.
	 *
	 * @param int $user_id User ID.
	 * @return array|WP_Error Capabilities.
	 */
	public function get_user_capabilities( $user_id ) {
		$user = get_user_by( 'ID', $user_id );

		if ( ! $user ) {
			return new WP_Error( 'not_found', __( 'User not found.', 'mumcp' ) );
		}

		return array(
			'user_id'      => $user_id,
			'roles'        => $user->roles,
			'capabilities' => array_keys( array_filter( $user->allcaps ) ),
		);
	}

	/**
	 * Format user for API response.
	 *
	 * @param WP_User $user         User object.
	 * @param bool    $include_meta Include additional meta.
	 * @return array Formatted user.
	 */
	private function format_user( $user, $include_meta = false ) {
		$data = array(
			'id'           => $user->ID,
			'username'     => $user->user_login,
			'email'        => $user->user_email,
			'display_name' => $user->display_name,
			'first_name'   => $user->first_name,
			'last_name'    => $user->last_name,
			'nickname'     => $user->nickname,
			'url'          => $user->user_url,
			'registered'   => $user->user_registered,
			'roles'        => $user->roles,
			'avatar'       => get_avatar_url( $user->ID, array( 'size' => 96 ) ),
		);

		if ( $include_meta ) {
			$data['bio'] = $user->description;
			$data['capabilities'] = array_keys( array_filter( $user->allcaps ) );
			$data['post_count'] = count_user_posts( $user->ID );
		}

		return $data;
	}

	/**
	 * Check if current user can modify target user.
	 *
	 * @param WP_User $user Target user.
	 * @return bool
	 */
	private function can_modify_user( $user ) {
		// Can't modify if user has admin role and current user is not admin.
		if ( in_array( 'administrator', $user->roles, true ) ) {
			return current_user_can( 'manage_options' );
		}

		return current_user_can( 'edit_users' );
	}

	/**
	 * Count users by role.
	 *
	 * @param string $role Role name.
	 * @return int User count.
	 */
	private function count_users_by_role( $role ) {
		$count = count_users();
		return isset( $count['avail_roles'][ $role ] ) ? $count['avail_roles'][ $role ] : 0;
	}

	/**
	 * Get user stats.
	 *
	 * @return array User statistics.
	 */
	public function get_stats() {
		$count = count_users();

		return array(
			'total'    => $count['total_users'],
			'by_role'  => $count['avail_roles'],
		);
	}
}
