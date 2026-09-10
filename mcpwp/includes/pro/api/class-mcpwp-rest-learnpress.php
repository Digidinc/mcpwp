<?php
/**
 * LearnPress REST API Controller
 *
 * @package MCPWP_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LearnPress REST controller class.
 */
class Mcpwp_REST_LearnPress extends Mcpwp_REST_API {

	/**
	 * LearnPress handler instance.
	 *
	 * @var Mcpwp_LearnPress
	 */
	private $handler;

	/**
	 * Constructor.
	 *
	 * @param Mcpwp_LearnPress $handler Handler instance.
	 */
	public function __construct( $handler ) {
		$this->handler = $handler;
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		// Courses.
		register_rest_route(
			$this->namespace,
			'/learnpress/courses',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_courses' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $this->get_courses_args(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_course' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/learnpress/courses/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_course' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_course' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Curriculum.
		register_rest_route(
			$this->namespace,
			'/learnpress/courses/(?P<id>\d+)/curriculum',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_curriculum' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'set_curriculum' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Lessons.
		register_rest_route(
			$this->namespace,
			'/learnpress/lessons',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_lessons' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $this->get_lessons_args(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_lesson' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/learnpress/lessons/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_lesson' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Quizzes.
		register_rest_route(
			$this->namespace,
			'/learnpress/quizzes',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_quizzes' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $this->get_quizzes_args(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_quiz' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/learnpress/quizzes/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_quiz' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/learnpress/quizzes/(?P<id>\d+)/questions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_quiz_questions' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Categories.
		register_rest_route(
			$this->namespace,
			'/learnpress/categories',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_categories' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_category' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/learnpress/categories/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_category' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_category' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Stats.
		register_rest_route(
			$this->namespace,
			'/learnpress/stats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_stats' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	// =========================================================================
	// Route Arguments
	// =========================================================================

	/**
	 * Get courses query arguments.
	 *
	 * @return array
	 */
	private function get_courses_args() {
		return array(
			'per_page' => array(
				'type'        => 'integer',
				'default'     => 50,
				'minimum'     => 1,
				'maximum'     => 100,
				'description' => __( 'Items per page.', 'mcpwp' ),
			),
			'page'     => array(
				'type'        => 'integer',
				'default'     => 1,
				'minimum'     => 1,
				'description' => __( 'Page number.', 'mcpwp' ),
			),
			'status'   => array(
				'type'        => 'string',
				'default'     => 'publish',
				'enum'        => array( 'publish', 'draft', 'pending', 'private', 'any' ),
				'description' => __( 'Course status.', 'mcpwp' ),
			),
			'search'   => array(
				'type'        => 'string',
				'description' => __( 'Search term.', 'mcpwp' ),
			),
			'category' => array(
				'type'        => 'string',
				'description' => __( 'Category slug or ID.', 'mcpwp' ),
			),
		);
	}

	/**
	 * Get lessons query arguments.
	 *
	 * @return array
	 */
	private function get_lessons_args() {
		return array(
			'per_page'  => array(
				'type'        => 'integer',
				'default'     => 50,
				'minimum'     => 1,
				'maximum'     => 100,
				'description' => __( 'Items per page.', 'mcpwp' ),
			),
			'page'      => array(
				'type'        => 'integer',
				'default'     => 1,
				'minimum'     => 1,
				'description' => __( 'Page number.', 'mcpwp' ),
			),
			'course_id' => array(
				'type'        => 'integer',
				'description' => __( 'Filter by course ID.', 'mcpwp' ),
			),
			'search'    => array(
				'type'        => 'string',
				'description' => __( 'Search term.', 'mcpwp' ),
			),
		);
	}

	/**
	 * Get quizzes query arguments.
	 *
	 * @return array
	 */
	private function get_quizzes_args() {
		return array(
			'per_page'  => array(
				'type'        => 'integer',
				'default'     => 50,
				'minimum'     => 1,
				'maximum'     => 100,
				'description' => __( 'Items per page.', 'mcpwp' ),
			),
			'page'      => array(
				'type'        => 'integer',
				'default'     => 1,
				'minimum'     => 1,
				'description' => __( 'Page number.', 'mcpwp' ),
			),
			'course_id' => array(
				'type'        => 'integer',
				'description' => __( 'Filter by course ID.', 'mcpwp' ),
			),
			'search'    => array(
				'type'        => 'string',
				'description' => __( 'Search term.', 'mcpwp' ),
			),
		);
	}

	// =========================================================================
	// Route Callbacks
	// =========================================================================

	/**
	 * List courses.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function list_courses( $request ) {
		// Security clamp: a read-only key may only list published courses. The
		// model uses this status as post_status directly, so a caller-supplied
		// draft/private/any would otherwise enumerate unpublished course titles.
		$status = $request->get_param( 'status' );
		if ( ! $this->requester_can_see_non_public() ) {
			$status = 'publish';
		}

		$args = array(
			'per_page' => $request->get_param( 'per_page' ),
			'page'     => $request->get_param( 'page' ),
			'status'   => $status,
			'search'   => $request->get_param( 'search' ),
			'category' => $request->get_param( 'category' ),
		);

		return rest_ensure_response( $this->handler->list_courses( $args ) );
	}

	/**
	 * Get a single course.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_course( $request ) {
		$result = $this->handler->get_course( $request->get_param( 'id' ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Create a course.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_course( $request ) {
		$data = $request->get_params();

		$result = $this->handler->create_course( $data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Update a course.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_course( $request ) {
		$id   = $request->get_param( 'id' );
		$data = $request->get_params();

		$result = $this->handler->update_course( $id, $data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Get course curriculum.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_curriculum( $request ) {
		$result = $this->handler->get_curriculum( $request->get_param( 'id' ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Set course curriculum.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function set_curriculum( $request ) {
		$id   = $request->get_param( 'id' );
		$data = $request->get_params();

		$sections = isset( $data['sections'] ) ? $data['sections'] : array();

		$result = $this->handler->set_curriculum( $id, $sections );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * List lessons.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function list_lessons( $request ) {
		$args = array(
			'per_page'  => $request->get_param( 'per_page' ),
			'page'      => $request->get_param( 'page' ),
			'course_id' => $request->get_param( 'course_id' ),
			'search'    => $request->get_param( 'search' ),
		);

		return rest_ensure_response( $this->handler->list_lessons( $args ) );
	}

	/**
	 * Create a lesson.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_lesson( $request ) {
		$data = $request->get_params();

		$result = $this->handler->create_lesson( $data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Update a lesson.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_lesson( $request ) {
		$id   = $request->get_param( 'id' );
		$data = $request->get_params();

		$result = $this->handler->update_lesson( $id, $data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * List quizzes.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function list_quizzes( $request ) {
		$args = array(
			'per_page'  => $request->get_param( 'per_page' ),
			'page'      => $request->get_param( 'page' ),
			'course_id' => $request->get_param( 'course_id' ),
			'search'    => $request->get_param( 'search' ),
		);

		return rest_ensure_response( $this->handler->list_quizzes( $args ) );
	}

	/**
	 * Create a quiz.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_quiz( $request ) {
		$data = $request->get_params();

		$result = $this->handler->create_quiz( $data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Update a quiz.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_quiz( $request ) {
		$id   = $request->get_param( 'id' );
		$data = $request->get_params();

		$result = $this->handler->update_quiz( $id, $data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Get quiz questions.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_quiz_questions( $request ) {
		$result = $this->handler->get_quiz_questions( $request->get_param( 'id' ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * List course categories.
	 *
	 * @return WP_REST_Response
	 */
	public function list_categories() {
		return rest_ensure_response( $this->handler->list_course_categories() );
	}

	/**
	 * Create a course category.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_category( $request ) {
		$data = $request->get_params();

		$result = $this->handler->create_course_category( $data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Update a course category.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_category( $request ) {
		$id   = $request->get_param( 'id' );
		$data = $request->get_params();

		$result = $this->handler->update_course_category( $id, $data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Delete a course category.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_category( $request ) {
		$id = absint( $request->get_param( 'id' ) );

		// wp_delete_term() has no undo: approval by default (#536 round 2).
		if ( class_exists( 'Mcpwp_Destructive_Gate' ) && Mcpwp_Destructive_Gate::should_gate( $this->get_current_api_key_record() ) ) {
			$term = function_exists( 'get_term' ) ? get_term( $id, 'course_category' ) : null;
			if ( ! $term || is_wp_error( $term ) ) {
				return $this->error_response( 'not_found', __( 'Category not found.', 'mcpwp' ), 404, array( 'id' => $id ) );
			}
			return $this->defer_destructive( 'delete_course_category', $request, array( 'type' => 'term', 'id' => $id, 'taxonomy' => 'course_category', 'title' => (string) $term->name ), sprintf( 'Permanently delete course category #%d', $id ) );
		}

		$result = $this->handler->delete_course_category( $id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array(
			'deleted' => true,
			'id'      => $request->get_param( 'id' ),
		) );
	}

	/**
	 * Get LMS stats.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_stats() {
		$result = $this->handler->get_stats();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}
}
