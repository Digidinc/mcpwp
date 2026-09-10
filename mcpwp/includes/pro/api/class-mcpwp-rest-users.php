<?php
/**
 * Users REST API Controller
 *
 * @package MCPWP_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller for user management.
 */
class Mcpwp_REST_Users extends Mcpwp_REST_API {

	/**
	 * Users handler.
	 *
	 * @var Mcpwp_Users
	 */
	private $users;

	/**
	 * Constructor.
	 *
	 * @param Mcpwp_Users $users Users handler.
	 */
	public function __construct( $users ) {
		$this->users = $users;
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		// Users list.
		register_rest_route(
			$this->namespace,
			'/users',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_users' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_user' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// User stats.
		register_rest_route(
			$this->namespace,
			'/users/stats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_stats' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Roles.
		register_rest_route(
			$this->namespace,
			'/users/roles',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_roles' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Single user.
		register_rest_route(
			$this->namespace,
			'/users/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_user' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_user' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_user' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// User capabilities.
		register_rest_route(
			$this->namespace,
			'/users/(?P<id>\d+)/capabilities',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_capabilities' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Get users list.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function get_users( $request ) {
		$args = array(
			'per_page' => $request->get_param( 'per_page' ) ?: 50,
			'page'     => $request->get_param( 'page' ) ?: 1,
			'role'     => $request->get_param( 'role' ),
			'search'   => $request->get_param( 'search' ),
			'orderby'  => $request->get_param( 'orderby' ),
			'order'    => $request->get_param( 'order' ),
		);

		$result = $this->users->get_users( $args );

		$this->log_activity( 'get_users', $request, array( 'total' => $result['total'] ) );

		return $this->success_response( $result );
	}

	/**
	 * Get single user.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_user( $request ) {
		$user_id = absint( $request->get_param( 'id' ) );
		$user = $this->users->get_user( $user_id );

		if ( is_wp_error( $user ) ) {
			return $this->error_response( $user->get_error_code(), $user->get_error_message(), 404 );
		}

		$this->log_activity( 'get_user', $request, array( 'user_id' => $user_id ) );

		return $this->success_response( $user );
	}

	/**
	 * Create user.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function create_user( $request ) {
		$data = array(
			'username'          => $request->get_param( 'username' ),
			'email'             => $request->get_param( 'email' ),
			'password'          => $request->get_param( 'password' ),
			'display_name'      => $request->get_param( 'display_name' ),
			'first_name'        => $request->get_param( 'first_name' ),
			'last_name'         => $request->get_param( 'last_name' ),
			'nickname'          => $request->get_param( 'nickname' ),
			'bio'               => $request->get_param( 'bio' ),
			'url'               => $request->get_param( 'url' ),
			'role'              => $request->get_param( 'role' ),
			'meta'              => $request->get_param( 'meta' ),
			'send_notification' => $request->get_param( 'send_notification' ),
		);

		$user = $this->users->create_user( $data );

		if ( is_wp_error( $user ) ) {
			return $this->error_response( $user->get_error_code(), $user->get_error_message(), 400 );
		}

		$this->log_activity( 'create_user', $request, array( 'user_id' => $user['id'] ), 201 );

		return $this->success_response( $user, 201 );
	}

	/**
	 * Update user.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function update_user( $request ) {
		$user_id = absint( $request->get_param( 'id' ) );

		$data = array(
			'email'        => $request->get_param( 'email' ),
			'password'     => $request->get_param( 'password' ),
			'display_name' => $request->get_param( 'display_name' ),
			'first_name'   => $request->get_param( 'first_name' ),
			'last_name'    => $request->get_param( 'last_name' ),
			'nickname'     => $request->get_param( 'nickname' ),
			'bio'          => $request->get_param( 'bio' ),
			'url'          => $request->get_param( 'url' ),
			'role'         => $request->get_param( 'role' ),
			'meta'         => $request->get_param( 'meta' ),
		);

		// Remove null values.
		$data = array_filter( $data, function( $v ) {
			return $v !== null;
		} );

		$user = $this->users->update_user( $user_id, $data );

		if ( is_wp_error( $user ) ) {
			return $this->error_response( $user->get_error_code(), $user->get_error_message(), 400 );
		}

		$this->log_activity( 'update_user', $request, array( 'user_id' => $user_id ) );

		return $this->success_response( $user );
	}

	/**
	 * Delete user.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function delete_user( $request ) {
		$user_id = absint( $request->get_param( 'id' ) );
		$reassign = $request->get_param( 'reassign' );

		// Deleting a user destroys an identity and reassigns or destroys
		// everything they own: approval by default (#536 round 6).
		// The handler's own refusal comes FIRST: a principal that may not
		// delete this user gets the same 400 it always got, never a 202 an
		// administrator could later apply (#536 round 7).
		if ( class_exists( 'Mcpwp_Destructive_Gate' ) && Mcpwp_Destructive_Gate::should_gate( $this->get_current_api_key_record() ) ) {
			$user = function_exists( 'get_user_by' ) ? get_user_by( 'ID', $user_id ) : null;
			if ( ! $user ) {
				return $this->error_response( 'not_found', __( 'User not found.', 'mcpwp' ), 404, array( 'id' => $user_id ) );
			}
			if ( method_exists( $this->users, 'current_principal_may_delete' ) && ! $this->users->current_principal_may_delete( $user_id ) ) {
				return $this->error_response( 'cannot_delete', __( 'You do not have permission to delete this user.', 'mcpwp' ), 400 );
			}
			return $this->defer_destructive( 'delete_user', $request, array( 'type' => 'user', 'id' => $user_id, 'title' => (string) $user->user_login ), sprintf( 'Permanently delete user #%d (%s)', $user_id, (string) $user->user_login ) );
		}

		$result = $this->users->delete_user( $user_id, $reassign ? absint( $reassign ) : null );

		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result->get_error_code(), $result->get_error_message(), 400 );
		}

		$this->log_activity( 'delete_user', $request, array( 'user_id' => $user_id ) );

		return $this->success_response( array(
			'deleted' => true,
			'id'      => $user_id,
		) );
	}

	/**
	 * Get user capabilities.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_capabilities( $request ) {
		$user_id = absint( $request->get_param( 'id' ) );
		$capabilities = $this->users->get_user_capabilities( $user_id );

		if ( is_wp_error( $capabilities ) ) {
			return $this->error_response( $capabilities->get_error_code(), $capabilities->get_error_message(), 404 );
		}

		$this->log_activity( 'get_user_capabilities', $request, array( 'user_id' => $user_id ) );

		return $this->success_response( $capabilities );
	}

	/**
	 * Get all roles.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function get_roles( $request ) {
		$roles = $this->users->get_roles();

		$this->log_activity( 'get_roles', $request, array( 'count' => count( $roles ) ) );

		return $this->success_response( array(
			'roles' => $roles,
			'total' => count( $roles ),
		) );
	}

	/**
	 * Get user stats.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function get_stats( $request ) {
		$stats = $this->users->get_stats();

		$this->log_activity( 'get_user_stats', $request );

		return $this->success_response( $stats );
	}
}
