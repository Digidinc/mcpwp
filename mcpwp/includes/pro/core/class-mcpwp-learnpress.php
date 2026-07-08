<?php
/**
 * LearnPress LMS Integration Handler
 *
 * Provides LearnPress operations for AI agents.
 *
 * @package MCPWP_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LearnPress handler class.
 */
class Mcpwp_LearnPress {

	/**
	 * Check if LearnPress is active.
	 *
	 * @return bool
	 */
	public function is_active() {
		return class_exists( 'LearnPress' ) || post_type_exists( 'lp_course' );
	}

	// =========================================================================
	// Courses
	// =========================================================================

	/**
	 * List courses.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public function list_courses( $args = array() ) {
		if ( ! $this->is_active() ) {
			return array( 'courses' => array(), 'total' => 0 );
		}

		$defaults = array(
			'per_page' => 50,
			'page'     => 1,
			'status'   => 'publish',
			'search'   => '',
			'category' => '',
			'orderby'  => 'date',
			'order'    => 'DESC',
		);

		$args = wp_parse_args( $args, $defaults );

		$query_args = array(
			'post_type'      => 'lp_course',
			'posts_per_page' => $args['per_page'],
			'paged'          => $args['page'],
			'post_status'    => $args['status'],
			'orderby'        => $args['orderby'],
			'order'          => $args['order'],
		);

		if ( ! empty( $args['search'] ) ) {
			$query_args['s'] = $args['search'];
		}

		if ( ! empty( $args['category'] ) ) {
			$query_args['tax_query'] = array(
				array(
					'taxonomy' => 'course_category',
					'field'    => is_numeric( $args['category'] ) ? 'term_id' : 'slug',
					'terms'    => $args['category'],
				),
			);
		}

		$query = new WP_Query( $query_args );
		$total = $query->found_posts;

		$courses = array();
		foreach ( $query->posts as $post ) {
			$courses[] = $this->format_course( $post );
		}

		return array(
			'courses'     => $courses,
			'total'       => $total,
			'page'        => $args['page'],
			'per_page'    => $args['per_page'],
			'total_pages' => ceil( $total / $args['per_page'] ),
		);
	}

	/**
	 * Get a single course.
	 *
	 * @param int $id Course ID.
	 * @return array|WP_Error
	 */
	public function get_course( $id ) {
		if ( ! $this->is_active() ) {
			return new WP_Error( 'lp_not_active', __( 'LearnPress is not active.', 'mumcp' ), array( 'status' => 400 ) );
		}

		$post = get_post( $id );
		if ( ! $post || 'lp_course' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Course not found.', 'mumcp' ), array( 'status' => 404 ) );
		}

		return $this->format_course( $post, true );
	}

	/**
	 * Create a course.
	 *
	 * @param array $data Course data.
	 * @return array|WP_Error
	 */
	public function create_course( $data ) {
		if ( ! $this->is_active() ) {
			return new WP_Error( 'lp_not_active', __( 'LearnPress is not active.', 'mumcp' ), array( 'status' => 400 ) );
		}

		$post_data = array(
			'post_type'   => 'lp_course',
			'post_status' => isset( $data['status'] ) ? sanitize_key( $data['status'] ) : 'draft',
			'post_title'  => isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '',
		);

		if ( isset( $data['content'] ) ) {
			$post_data['post_content'] = wp_kses_post( $data['content'] );
		}

		if ( isset( $data['excerpt'] ) ) {
			$post_data['post_excerpt'] = sanitize_textarea_field( $data['excerpt'] );
		}

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$this->set_course_meta( $post_id, $data );

		// Handle categories.
		if ( ! empty( $data['categories'] ) ) {
			$this->set_course_categories( $post_id, $data['categories'] );
		}

		return $this->get_course( $post_id );
	}

	/**
	 * Update a course.
	 *
	 * @param int   $id   Course ID.
	 * @param array $data Course data.
	 * @return array|WP_Error
	 */
	public function update_course( $id, $data ) {
		if ( ! $this->is_active() ) {
			return new WP_Error( 'lp_not_active', __( 'LearnPress is not active.', 'mumcp' ), array( 'status' => 400 ) );
		}

		$post = get_post( $id );
		if ( ! $post || 'lp_course' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Course not found.', 'mumcp' ), array( 'status' => 404 ) );
		}

		$post_data = array( 'ID' => $id );

		if ( isset( $data['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $data['title'] );
		}

		if ( isset( $data['content'] ) ) {
			$post_data['post_content'] = wp_kses_post( $data['content'] );
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

		$this->set_course_meta( $id, $data );

		// Handle categories.
		if ( isset( $data['categories'] ) ) {
			$this->set_course_categories( $id, $data['categories'] );
		}

		return $this->get_course( $id );
	}

	/**
	 * Get course curriculum.
	 *
	 * @param int $course_id Course ID.
	 * @return array|WP_Error
	 */
	public function get_curriculum( $course_id ) {
		global $wpdb;

		if ( ! $this->is_active() ) {
			return new WP_Error( 'lp_not_active', __( 'LearnPress is not active.', 'mumcp' ), array( 'status' => 400 ) );
		}

		$post = get_post( $course_id );
		if ( ! $post || 'lp_course' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Course not found.', 'mumcp' ), array( 'status' => 404 ) );
		}

		$sections_table = $wpdb->prefix . 'learnpress_sections';
		$items_table    = $wpdb->prefix . 'learnpress_section_items';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sections = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$sections_table} WHERE section_course_id = %d ORDER BY section_order ASC",
			$course_id
		) );

		$curriculum = array();
		foreach ( $sections as $section ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$items = $wpdb->get_results( $wpdb->prepare(
				"SELECT si.*, p.post_title, p.post_type
				FROM {$items_table} si
				LEFT JOIN {$wpdb->posts} p ON si.item_id = p.ID
				WHERE si.section_id = %d
				ORDER BY si.item_order ASC",
				$section->section_id
			) );

			$formatted_items = array();
			foreach ( $items as $item ) {
				$formatted_items[] = array(
					'id'    => (int) $item->item_id,
					'title' => $item->post_title,
					'type'  => $item->item_type,
					'order' => (int) $item->item_order,
				);
			}

			$curriculum[] = array(
				'id'          => (int) $section->section_id,
				'name'        => $section->section_name,
				'description' => $section->section_description,
				'order'       => (int) $section->section_order,
				'items'       => $formatted_items,
			);
		}

		return array(
			'course_id'  => $course_id,
			'sections'   => $curriculum,
		);
	}

	/**
	 * Set course curriculum.
	 *
	 * @param int   $course_id Course ID.
	 * @param array $sections  Sections data.
	 * @return array|WP_Error
	 */
	public function set_curriculum( $course_id, $sections ) {
		global $wpdb;

		if ( ! $this->is_active() ) {
			return new WP_Error( 'lp_not_active', __( 'LearnPress is not active.', 'mumcp' ), array( 'status' => 400 ) );
		}

		$post = get_post( $course_id );
		if ( ! $post || 'lp_course' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Course not found.', 'mumcp' ), array( 'status' => 404 ) );
		}

		$sections_table = $wpdb->prefix . 'learnpress_sections';
		$items_table    = $wpdb->prefix . 'learnpress_section_items';

		// Remove existing sections and items for this course.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing_sections = $wpdb->get_col( $wpdb->prepare(
			"SELECT section_id FROM {$sections_table} WHERE section_course_id = %d",
			$course_id
		) );

		if ( ! empty( $existing_sections ) ) {
			$ids_in = implode( ',', array_map( 'intval', $existing_sections ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$items_table} WHERE section_id IN ({$ids_in})" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $sections_table, array( 'section_course_id' => $course_id ), array( '%d' ) );
		}

		// Insert new sections and items.
		$section_order = 0;
		foreach ( $sections as $section_data ) {
			$section_order++;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$sections_table,
				array(
					'section_course_id'   => $course_id,
					'section_name'        => isset( $section_data['name'] ) ? sanitize_text_field( $section_data['name'] ) : '',
					'section_order'       => $section_order,
					'section_description' => isset( $section_data['description'] ) ? sanitize_textarea_field( $section_data['description'] ) : '',
				),
				array( '%d', '%s', '%d', '%s' )
			);

			$section_id = $wpdb->insert_id;

			if ( ! empty( $section_data['items'] ) ) {
				$item_order = 0;
				foreach ( $section_data['items'] as $item ) {
					$item_order++;

					$item_type = isset( $item['type'] ) ? sanitize_key( $item['type'] ) : 'lp_lesson';

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->insert(
						$items_table,
						array(
							'section_id' => $section_id,
							'item_id'    => (int) $item['id'],
							'item_order' => $item_order,
							'item_type'  => $item_type,
						),
						array( '%d', '%d', '%d', '%s' )
					);
				}
			}
		}

		return $this->get_curriculum( $course_id );
	}

	// =========================================================================
	// Lessons
	// =========================================================================

	/**
	 * List lessons.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public function list_lessons( $args = array() ) {
		if ( ! $this->is_active() ) {
			return array( 'lessons' => array(), 'total' => 0 );
		}

		$defaults = array(
			'per_page'  => 50,
			'page'      => 1,
			'status'    => 'publish',
			'course_id' => '',
			'search'    => '',
		);

		$args = wp_parse_args( $args, $defaults );

		$query_args = array(
			'post_type'      => 'lp_lesson',
			'posts_per_page' => $args['per_page'],
			'paged'          => $args['page'],
			'post_status'    => $args['status'],
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( ! empty( $args['search'] ) ) {
			$query_args['s'] = $args['search'];
		}

		// Filter by course if specified.
		if ( ! empty( $args['course_id'] ) ) {
			$lesson_ids = $this->get_course_item_ids( (int) $args['course_id'], 'lp_lesson' );
			if ( empty( $lesson_ids ) ) {
				return array( 'lessons' => array(), 'total' => 0, 'page' => $args['page'], 'per_page' => $args['per_page'], 'total_pages' => 0 );
			}
			$query_args['post__in'] = $lesson_ids;
		}

		$query = new WP_Query( $query_args );
		$total = $query->found_posts;

		$lessons = array();
		foreach ( $query->posts as $post ) {
			$lessons[] = $this->format_lesson( $post );
		}

		return array(
			'lessons'     => $lessons,
			'total'       => $total,
			'page'        => $args['page'],
			'per_page'    => $args['per_page'],
			'total_pages' => ceil( $total / $args['per_page'] ),
		);
	}

	/**
	 * Create a lesson.
	 *
	 * @param array $data Lesson data.
	 * @return array|WP_Error
	 */
	public function create_lesson( $data ) {
		if ( ! $this->is_active() ) {
			return new WP_Error( 'lp_not_active', __( 'LearnPress is not active.', 'mumcp' ), array( 'status' => 400 ) );
		}

		$post_data = array(
			'post_type'   => 'lp_lesson',
			'post_status' => isset( $data['status'] ) ? sanitize_key( $data['status'] ) : 'publish',
			'post_title'  => isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '',
		);

		if ( isset( $data['content'] ) ) {
			$post_data['post_content'] = wp_kses_post( $data['content'] );
		}

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Set lesson meta.
		if ( isset( $data['duration'] ) ) {
			update_post_meta( $post_id, '_lp_duration', sanitize_text_field( $data['duration'] ) );
		}

		if ( isset( $data['preview'] ) ) {
			update_post_meta( $post_id, '_lp_preview', $data['preview'] ? 'yes' : 'no' );
		}

		return $this->format_lesson( get_post( $post_id ) );
	}

	/**
	 * Update a lesson.
	 *
	 * @param int   $id   Lesson ID.
	 * @param array $data Lesson data.
	 * @return array|WP_Error
	 */
	public function update_lesson( $id, $data ) {
		if ( ! $this->is_active() ) {
			return new WP_Error( 'lp_not_active', __( 'LearnPress is not active.', 'mumcp' ), array( 'status' => 400 ) );
		}

		$post = get_post( $id );
		if ( ! $post || 'lp_lesson' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Lesson not found.', 'mumcp' ), array( 'status' => 404 ) );
		}

		$post_data = array( 'ID' => $id );

		if ( isset( $data['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $data['title'] );
		}

		if ( isset( $data['content'] ) ) {
			$post_data['post_content'] = wp_kses_post( $data['content'] );
		}

		if ( isset( $data['status'] ) ) {
			$post_data['post_status'] = sanitize_key( $data['status'] );
		}

		$result = wp_update_post( $post_data, true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Update lesson meta.
		if ( isset( $data['duration'] ) ) {
			update_post_meta( $id, '_lp_duration', sanitize_text_field( $data['duration'] ) );
		}

		if ( isset( $data['preview'] ) ) {
			update_post_meta( $id, '_lp_preview', $data['preview'] ? 'yes' : 'no' );
		}

		return $this->format_lesson( get_post( $id ) );
	}

	// =========================================================================
	// Quizzes
	// =========================================================================

	/**
	 * List quizzes.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public function list_quizzes( $args = array() ) {
		if ( ! $this->is_active() ) {
			return array( 'quizzes' => array(), 'total' => 0 );
		}

		$defaults = array(
			'per_page'  => 50,
			'page'      => 1,
			'status'    => 'publish',
			'course_id' => '',
			'search'    => '',
		);

		$args = wp_parse_args( $args, $defaults );

		$query_args = array(
			'post_type'      => 'lp_quiz',
			'posts_per_page' => $args['per_page'],
			'paged'          => $args['page'],
			'post_status'    => $args['status'],
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( ! empty( $args['search'] ) ) {
			$query_args['s'] = $args['search'];
		}

		if ( ! empty( $args['course_id'] ) ) {
			$quiz_ids = $this->get_course_item_ids( (int) $args['course_id'], 'lp_quiz' );
			if ( empty( $quiz_ids ) ) {
				return array( 'quizzes' => array(), 'total' => 0, 'page' => $args['page'], 'per_page' => $args['per_page'], 'total_pages' => 0 );
			}
			$query_args['post__in'] = $quiz_ids;
		}

		$query = new WP_Query( $query_args );
		$total = $query->found_posts;

		$quizzes = array();
		foreach ( $query->posts as $post ) {
			$quizzes[] = $this->format_quiz( $post );
		}

		return array(
			'quizzes'     => $quizzes,
			'total'       => $total,
			'page'        => $args['page'],
			'per_page'    => $args['per_page'],
			'total_pages' => ceil( $total / $args['per_page'] ),
		);
	}

	/**
	 * Create a quiz.
	 *
	 * @param array $data Quiz data.
	 * @return array|WP_Error
	 */
	public function create_quiz( $data ) {
		if ( ! $this->is_active() ) {
			return new WP_Error( 'lp_not_active', __( 'LearnPress is not active.', 'mumcp' ), array( 'status' => 400 ) );
		}

		$post_data = array(
			'post_type'   => 'lp_quiz',
			'post_status' => isset( $data['status'] ) ? sanitize_key( $data['status'] ) : 'publish',
			'post_title'  => isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '',
		);

		if ( isset( $data['content'] ) ) {
			$post_data['post_content'] = wp_kses_post( $data['content'] );
		}

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$this->set_quiz_meta( $post_id, $data );

		return $this->format_quiz( get_post( $post_id ) );
	}

	/**
	 * Update a quiz.
	 *
	 * @param int   $id   Quiz ID.
	 * @param array $data Quiz data.
	 * @return array|WP_Error
	 */
	public function update_quiz( $id, $data ) {
		if ( ! $this->is_active() ) {
			return new WP_Error( 'lp_not_active', __( 'LearnPress is not active.', 'mumcp' ), array( 'status' => 400 ) );
		}

		$post = get_post( $id );
		if ( ! $post || 'lp_quiz' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Quiz not found.', 'mumcp' ), array( 'status' => 404 ) );
		}

		$post_data = array( 'ID' => $id );

		if ( isset( $data['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $data['title'] );
		}

		if ( isset( $data['content'] ) ) {
			$post_data['post_content'] = wp_kses_post( $data['content'] );
		}

		if ( isset( $data['status'] ) ) {
			$post_data['post_status'] = sanitize_key( $data['status'] );
		}

		$result = wp_update_post( $post_data, true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->set_quiz_meta( $id, $data );

		return $this->format_quiz( get_post( $id ) );
	}

	/**
	 * Get quiz questions.
	 *
	 * @param int $quiz_id Quiz ID.
	 * @return array|WP_Error
	 */
	public function get_quiz_questions( $quiz_id ) {
		global $wpdb;

		if ( ! $this->is_active() ) {
			return new WP_Error( 'lp_not_active', __( 'LearnPress is not active.', 'mumcp' ), array( 'status' => 400 ) );
		}

		$post = get_post( $quiz_id );
		if ( ! $post || 'lp_quiz' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Quiz not found.', 'mumcp' ), array( 'status' => 404 ) );
		}

		$table = $wpdb->prefix . 'learnpress_quiz_questions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT qq.*, p.post_title, p.post_content
			FROM {$table} qq
			LEFT JOIN {$wpdb->posts} p ON qq.question_id = p.ID
			WHERE qq.quiz_id = %d
			ORDER BY qq.question_order ASC",
			$quiz_id
		) );

		$questions = array();
		foreach ( $rows as $row ) {
			$questions[] = array(
				'id'      => (int) $row->question_id,
				'title'   => $row->post_title,
				'content' => $row->post_content,
				'order'   => (int) $row->question_order,
			);
		}

		return array(
			'quiz_id'   => $quiz_id,
			'questions' => $questions,
		);
	}

	// =========================================================================
	// Course Categories
	// =========================================================================

	/**
	 * List course categories.
	 *
	 * @return array
	 */
	public function list_course_categories() {
		if ( ! $this->is_active() ) {
			return array();
		}

		$terms = get_terms( array(
			'taxonomy'   => 'course_category',
			'hide_empty' => false,
		) );

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$categories = array();
		foreach ( $terms as $term ) {
			$categories[] = array(
				'id'          => $term->term_id,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'description' => $term->description,
				'parent'      => $term->parent,
				'count'       => $term->count,
			);
		}

		return $categories;
	}

	/**
	 * Create a course category.
	 *
	 * @param array $data Category data.
	 * @return array|WP_Error
	 */
	public function create_course_category( $data ) {
		if ( ! $this->is_active() ) {
			return new WP_Error( 'lp_not_active', __( 'LearnPress is not active.', 'mumcp' ), array( 'status' => 400 ) );
		}

		$term_args = array();

		if ( isset( $data['description'] ) ) {
			$term_args['description'] = sanitize_textarea_field( $data['description'] );
		}

		if ( isset( $data['slug'] ) ) {
			$term_args['slug'] = sanitize_title( $data['slug'] );
		}

		if ( isset( $data['parent'] ) ) {
			$term_args['parent'] = (int) $data['parent'];
		}

		$name = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';

		$result = wp_insert_term( $name, 'course_category', $term_args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$term = get_term( $result['term_id'], 'course_category' );

		return array(
			'id'          => $term->term_id,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'description' => $term->description,
			'parent'      => $term->parent,
			'count'       => $term->count,
		);
	}

	/**
	 * Update a course category.
	 *
	 * @param int   $id   Term ID.
	 * @param array $data Category data.
	 * @return array|WP_Error
	 */
	public function update_course_category( $id, $data ) {
		if ( ! $this->is_active() ) {
			return new WP_Error( 'lp_not_active', __( 'LearnPress is not active.', 'mumcp' ), array( 'status' => 400 ) );
		}

		$term = get_term( $id, 'course_category' );
		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'not_found', __( 'Category not found.', 'mumcp' ), array( 'status' => 404 ) );
		}

		$term_args = array();

		if ( isset( $data['name'] ) ) {
			$term_args['name'] = sanitize_text_field( $data['name'] );
		}

		if ( isset( $data['description'] ) ) {
			$term_args['description'] = sanitize_textarea_field( $data['description'] );
		}

		if ( isset( $data['slug'] ) ) {
			$term_args['slug'] = sanitize_title( $data['slug'] );
		}

		if ( isset( $data['parent'] ) ) {
			$term_args['parent'] = (int) $data['parent'];
		}

		$result = wp_update_term( $id, 'course_category', $term_args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$term = get_term( $id, 'course_category' );

		return array(
			'id'          => $term->term_id,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'description' => $term->description,
			'parent'      => $term->parent,
			'count'       => $term->count,
		);
	}

	/**
	 * Delete a course category.
	 *
	 * @param int $id Term ID.
	 * @return bool|WP_Error
	 */
	public function delete_course_category( $id ) {
		if ( ! $this->is_active() ) {
			return new WP_Error( 'lp_not_active', __( 'LearnPress is not active.', 'mumcp' ), array( 'status' => 400 ) );
		}

		$term = get_term( $id, 'course_category' );
		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'not_found', __( 'Category not found.', 'mumcp' ), array( 'status' => 404 ) );
		}

		$result = wp_delete_term( $id, 'course_category' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	// =========================================================================
	// Stats
	// =========================================================================

	/**
	 * Get LMS statistics.
	 *
	 * @return array|WP_Error
	 */
	public function get_stats() {
		global $wpdb;

		if ( ! $this->is_active() ) {
			return new WP_Error( 'lp_not_active', __( 'LearnPress is not active.', 'mumcp' ), array( 'status' => 400 ) );
		}

		$course_counts = wp_count_posts( 'lp_course' );
		$lesson_counts = wp_count_posts( 'lp_lesson' );
		$quiz_counts   = wp_count_posts( 'lp_quiz' );

		$categories = get_terms( array(
			'taxonomy'   => 'course_category',
			'hide_empty' => false,
			'fields'     => 'count',
		) );

		$category_count = is_wp_error( $categories ) ? 0 : (int) $categories;

		// Get enrollment count.
		$user_items_table = $wpdb->prefix . 'learnpress_user_items';
		$enrollment_count = 0;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$enrollment_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$user_items_table} WHERE item_type = 'lp_course'"
		);

		// Get revenue summary from order items.
		$order_items_table = $wpdb->prefix . 'learnpress_order_items';
		$revenue           = '0.00';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$revenue_result = $wpdb->get_var(
			"SELECT SUM(oi.order_item_id)
			FROM {$order_items_table} oi
			LEFT JOIN {$wpdb->posts} p ON oi.order_id = p.ID
			WHERE p.post_status = 'lp-completed'"
		);

		// Alternative: get revenue from order post meta.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$revenue = $wpdb->get_var(
			"SELECT SUM(pm.meta_value)
			FROM {$wpdb->postmeta} pm
			LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
			WHERE p.post_type = 'lp_order'
			AND p.post_status = 'lp-completed'
			AND pm.meta_key = '_order_total'"
		);

		return array(
			'courses'     => array(
				'total'   => isset( $course_counts->publish ) ? (int) $course_counts->publish : 0,
				'draft'   => isset( $course_counts->draft ) ? (int) $course_counts->draft : 0,
			),
			'lessons'     => array(
				'total' => isset( $lesson_counts->publish ) ? (int) $lesson_counts->publish : 0,
			),
			'quizzes'     => array(
				'total' => isset( $quiz_counts->publish ) ? (int) $quiz_counts->publish : 0,
			),
			'categories'  => $category_count,
			'enrollments' => $enrollment_count,
			'revenue'     => $revenue ? number_format( (float) $revenue, 2, '.', '' ) : '0.00',
		);
	}

	// =========================================================================
	// Private Helpers
	// =========================================================================

	/**
	 * Set course meta fields.
	 *
	 * @param int   $post_id Course post ID.
	 * @param array $data    Data containing meta fields.
	 */
	private function set_course_meta( $post_id, $data ) {
		$meta_fields = array(
			'regular_price' => '_lp_regular_price',
			'sale_price'    => '_lp_sale_price',
			'price'         => '_lp_price',
			'duration'      => '_lp_duration',
			'level'         => '_lp_level',
		);

		foreach ( $meta_fields as $key => $meta_key ) {
			if ( isset( $data[ $key ] ) ) {
				update_post_meta( $post_id, $meta_key, sanitize_text_field( $data[ $key ] ) );
			}
		}

		// Serialized array fields.
		$array_fields = array(
			'requirements'     => '_lp_requirements',
			'target_audiences' => '_lp_target_audiences',
			'key_features'     => '_lp_key_features',
		);

		foreach ( $array_fields as $key => $meta_key ) {
			if ( isset( $data[ $key ] ) ) {
				$value = is_array( $data[ $key ] ) ? $data[ $key ] : array( $data[ $key ] );
				update_post_meta( $post_id, $meta_key, $value );
			}
		}

		// FAQs — array of [question, answer] pairs.
		if ( isset( $data['faqs'] ) ) {
			$faqs = array();
			foreach ( $data['faqs'] as $faq ) {
				if ( is_array( $faq ) && count( $faq ) >= 2 ) {
					$faqs[] = array( sanitize_text_field( $faq[0] ), wp_kses_post( $faq[1] ) );
				}
			}
			update_post_meta( $post_id, '_lp_faqs', $faqs );
		}

		// Featured review.
		if ( isset( $data['featured_review'] ) ) {
			update_post_meta( $post_id, '_lp_featured_review', sanitize_textarea_field( $data['featured_review'] ) );
		}
	}

	/**
	 * Set quiz meta fields.
	 *
	 * @param int   $post_id Quiz post ID.
	 * @param array $data    Data containing meta fields.
	 */
	private function set_quiz_meta( $post_id, $data ) {
		$meta_fields = array(
			'duration'             => '_lp_duration',
			'passing_grade'        => '_lp_passing_grade',
			'retake_count'         => '_lp_retake_count',
			'instant_check'        => '_lp_instant_check',
			'negative_marking'     => '_lp_negative_marking',
			'minus_skip_questions' => '_lp_minus_skip_questions',
			'review'               => '_lp_review',
			'show_correct_review'  => '_lp_show_correct_review',
			'pagination'           => '_lp_pagination',
			'preview'              => '_lp_preview',
		);

		foreach ( $meta_fields as $key => $meta_key ) {
			if ( isset( $data[ $key ] ) ) {
				update_post_meta( $post_id, $meta_key, sanitize_text_field( $data[ $key ] ) );
			}
		}
	}

	/**
	 * Set course categories.
	 *
	 * @param int   $post_id    Post ID.
	 * @param array $categories Category names or IDs.
	 */
	private function set_course_categories( $post_id, $categories ) {
		$term_ids = array();
		foreach ( $categories as $cat ) {
			if ( is_numeric( $cat ) ) {
				$term_ids[] = (int) $cat;
			} else {
				$term = get_term_by( 'name', $cat, 'course_category' );
				if ( $term ) {
					$term_ids[] = $term->term_id;
				} else {
					$result = wp_insert_term( $cat, 'course_category' );
					if ( ! is_wp_error( $result ) ) {
						$term_ids[] = $result['term_id'];
					}
				}
			}
		}
		wp_set_object_terms( $post_id, $term_ids, 'course_category' );
	}

	/**
	 * Get item IDs for a course from the curriculum tables.
	 *
	 * @param int    $course_id Course ID.
	 * @param string $item_type Item type (lp_lesson or lp_quiz).
	 * @return array
	 */
	private function get_course_item_ids( $course_id, $item_type ) {
		global $wpdb;

		$sections_table = $wpdb->prefix . 'learnpress_sections';
		$items_table    = $wpdb->prefix . 'learnpress_section_items';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT si.item_id
			FROM {$items_table} si
			INNER JOIN {$sections_table} s ON si.section_id = s.section_id
			WHERE s.section_course_id = %d AND si.item_type = %s",
			$course_id,
			$item_type
		) );

		return array_map( 'intval', $ids );
	}

	/**
	 * Format course for API response.
	 *
	 * @param WP_Post $post     Post object.
	 * @param bool    $detailed Include extra details.
	 * @return array
	 */
	private function format_course( $post, $detailed = false ) {
		$data = array(
			'id'         => $post->ID,
			'title'      => $post->post_title,
			'slug'       => $post->post_name,
			'status'     => $post->post_status,
			'permalink'  => get_permalink( $post->ID ),
			'price'      => get_post_meta( $post->ID, '_lp_price', true ),
			'regular_price' => get_post_meta( $post->ID, '_lp_regular_price', true ),
			'sale_price' => get_post_meta( $post->ID, '_lp_sale_price', true ),
			'duration'   => get_post_meta( $post->ID, '_lp_duration', true ),
			'level'      => get_post_meta( $post->ID, '_lp_level', true ),
			'categories' => $this->get_course_term_names( $post->ID ),
			'date_created'  => $post->post_date,
			'date_modified' => $post->post_modified,
		);

		// Count lessons and quizzes.
		$lesson_ids = $this->get_course_item_ids( $post->ID, 'lp_lesson' );
		$quiz_ids   = $this->get_course_item_ids( $post->ID, 'lp_quiz' );

		$data['lesson_count'] = count( $lesson_ids );
		$data['quiz_count']   = count( $quiz_ids );

		// Enrollment count.
		$data['enrollment_count'] = $this->get_course_enrollment_count( $post->ID );

		// Instructor.
		$author = get_userdata( $post->post_author );
		$data['instructor'] = $author ? $author->display_name : '';

		if ( $detailed ) {
			$data['content'] = $post->post_content;
			$data['excerpt'] = $post->post_excerpt;

			// Serialized array fields.
			$data['requirements']     = $this->maybe_unserialize_array( get_post_meta( $post->ID, '_lp_requirements', true ) );
			$data['target_audiences'] = $this->maybe_unserialize_array( get_post_meta( $post->ID, '_lp_target_audiences', true ) );
			$data['key_features']     = $this->maybe_unserialize_array( get_post_meta( $post->ID, '_lp_key_features', true ) );
			$data['faqs']             = $this->maybe_unserialize_array( get_post_meta( $post->ID, '_lp_faqs', true ) );
			$data['featured_review']  = get_post_meta( $post->ID, '_lp_featured_review', true );

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

	/**
	 * Format lesson for API response.
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	private function format_lesson( $post ) {
		return array(
			'id'       => $post->ID,
			'title'    => $post->post_title,
			'slug'     => $post->post_name,
			'status'   => $post->post_status,
			'content'  => $post->post_content,
			'duration' => get_post_meta( $post->ID, '_lp_duration', true ),
			'preview'  => get_post_meta( $post->ID, '_lp_preview', true ),
			'date_created'  => $post->post_date,
			'date_modified' => $post->post_modified,
		);
	}

	/**
	 * Format quiz for API response.
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	private function format_quiz( $post ) {
		return array(
			'id'              => $post->ID,
			'title'           => $post->post_title,
			'slug'            => $post->post_name,
			'status'          => $post->post_status,
			'content'         => $post->post_content,
			'duration'        => get_post_meta( $post->ID, '_lp_duration', true ),
			'passing_grade'   => get_post_meta( $post->ID, '_lp_passing_grade', true ),
			'retake_count'    => get_post_meta( $post->ID, '_lp_retake_count', true ),
			'instant_check'   => get_post_meta( $post->ID, '_lp_instant_check', true ),
			'review'          => get_post_meta( $post->ID, '_lp_review', true ),
			'date_created'    => $post->post_date,
			'date_modified'   => $post->post_modified,
		);
	}

	/**
	 * Get course category names.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	private function get_course_term_names( $post_id ) {
		$terms = get_the_terms( $post_id, 'course_category' );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return array();
		}
		return wp_list_pluck( $terms, 'name' );
	}

	/**
	 * Get course enrollment count.
	 *
	 * @param int $course_id Course ID.
	 * @return int
	 */
	private function get_course_enrollment_count( $course_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'learnpress_user_items';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE item_id = %d AND item_type = 'lp_course'",
			$course_id
		) );

		return $count;
	}

	/**
	 * Safely unserialize an array value.
	 *
	 * @param mixed $value Value that may be serialized.
	 * @return array
	 */
	private function maybe_unserialize_array( $value ) {
		if ( empty( $value ) ) {
			return array();
		}

		if ( is_string( $value ) ) {
			$value = maybe_unserialize( $value );
		}

		return is_array( $value ) ? $value : array();
	}
}
