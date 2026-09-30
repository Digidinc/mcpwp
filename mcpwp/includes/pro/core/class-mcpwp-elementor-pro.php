<?php
/**
 * Elementor Pro Handler
 *
 * @package MCPWP_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elementor Pro functionality.
 *
 * Provides advanced Elementor features including templates,
 * landing pages, cloning, and widget management.
 */
class Mcpwp_Elementor_Pro {
	/**
	 * Post meta key: marks an Elementor template as a reusable part.
	 *
	 * @var string
	 */
	private $part_flag_meta_key = '_mcpwp_is_part';

	/**
	 * Post meta key: reusable part intent/kind (hero, faq, cta, etc.).
	 *
	 * @var string
	 */
	private $part_kind_meta_key = '_mcpwp_part_kind';

	/**
	 * Post meta key: reusable part style/variant label.
	 *
	 * @var string
	 */
	private $part_style_meta_key = '_mcpwp_part_style';

	/**
	 * Post meta key: reusable part tags.
	 *
	 * @var string
	 */
	private $part_tags_meta_key = '_mcpwp_part_tags';

	/**
	 * Post meta key: normalized searchable tag index.
	 *
	 * @var string
	 */
	private $part_tags_index_meta_key = '_mcpwp_part_tags_index';

	/**
	 * Post meta key: source page ID for extracted parts.
	 *
	 * @var string
	 */
	private $part_source_page_meta_key = '_mcpwp_part_source_page_id';

	/**
	 * Post meta key: source element ID for extracted parts.
	 *
	 * @var string
	 */
	private $part_source_element_meta_key = '_mcpwp_part_source_element_id';

	/**
	 * Post meta key: marks an Elementor template as a reusable archetype.
	 *
	 * @var string
	 */
	private $archetype_flag_meta_key = '_mcpwp_is_archetype';

	/**
	 * Post meta key: archetype scope such as page or product.
	 *
	 * @var string
	 */
	private $archetype_scope_meta_key = '_mcpwp_archetype_scope';

	/**
	 * Post meta key: archetype class such as blog_post or service_page.
	 *
	 * @var string
	 */
	private $archetype_class_meta_key = '_mcpwp_archetype_class';

	/**
	 * Post meta key: archetype style/variant label.
	 *
	 * @var string
	 */
	private $archetype_style_meta_key = '_mcpwp_archetype_style';

	/**
	 * Post meta key: archetype-specific override brief.
	 *
	 * @var string
	 */
	private $archetype_brief_meta_key = '_mcpwp_archetype_brief';

	/**
	 * Shared basic Elementor handler.
	 *
	 * @var Mcpwp_Elementor_Basic|null
	 */
	private $basic_handler = null;

	/**
	 * Gate injected by Mcpwp_REST_Elementor_Pro::check_permission() (#1032):
	 * refuses a write that embeds a Template Library reference this caller
	 * cannot read. Forwarded to the shared basic handler for every
	 * save_elementor_data() call below (create/update template, apply
	 * template/archetype/part, landing page, save-section-as-template), and
	 * kept here too because clone_page() copies `_elementor_data` as a raw
	 * postmeta value and never reaches that handler at all.
	 *
	 * @var callable|null
	 */
	private $template_ref_gate = null;

	/**
	 * Inject the #1032 write-side gate — see
	 * Mcpwp_Api_Auth::template_ref_gate()'s docblock for what the callable
	 * does.
	 *
	 * @param callable|null $callback function(int[] $ids): true|WP_Error.
	 * @return void
	 */
	public function set_template_ref_gate( $callback ) {
		$this->template_ref_gate = is_callable( $callback ) ? $callback : null;
		$this->get_basic_handler()->set_template_ref_gate( $callback );
	}

	/**
	 * Shared #1032 check for the two write paths in this class that never
	 * reach the basic handler's own check_template_references() at all:
	 * clone_page() (copies `_elementor_data` as a raw postmeta value) and
	 * create_landing_page() (writes the SAME way via `meta_input`, atomically
	 * with wp_insert_post() — before save_elementor_data() runs at all, so
	 * that later call's own refusal is too late to stop an orphan page from
	 * existing with the gated reference already on it; adversarial review
	 * round 1, W1).
	 *
	 * @param array $elements Element tree about to be written.
	 * @return true|WP_Error
	 */
	private function check_template_references( array $elements ) {
		if ( null === $this->template_ref_gate || ! class_exists( 'Mcpwp_Elementor_Template_Refs' ) ) {
			return true;
		}

		$ids = Mcpwp_Elementor_Template_Refs::find_ids( $elements );
		if ( empty( $ids ) ) {
			return true;
		}

		return call_user_func( $this->template_ref_gate, $ids );
	}

	/**
	 * Check if Elementor is active.
	 *
	 * @return bool
	 */
	public function is_elementor_active() {
		return did_action( 'elementor/loaded' );
	}

	/**
	 * Check if Elementor Pro is active.
	 *
	 * @return bool
	 */
	public function is_elementor_pro_active() {
		return defined( 'ELEMENTOR_PRO_VERSION' );
	}

	/**
	 * Get the shared basic Elementor handler.
	 *
	 * @return Mcpwp_Elementor_Basic
	 */
	private function get_basic_handler() {
		if ( null === $this->basic_handler ) {
			$this->basic_handler = new Mcpwp_Elementor_Basic();
		}

		return $this->basic_handler;
	}

	/**
	 * Save Elementor data through the shared basic Elementor handler.
	 *
	 * @param int          $post_id  Post/page/template ID.
	 * @param array|string $elements Elementor elements array or JSON string.
	 * @return array|WP_Error Save result.
	 */
	private function save_elementor_data( $post_id, $elements ) {
		$payload = array();

		if ( is_array( $elements ) ) {
			$payload['elementor_data'] = $elements;
		} else {
			$payload['elementor_data'] = (string) $elements;
		}

		return $this->get_basic_handler()->set_elementor_data( $post_id, $payload );
	}

	/**
	 * Check if templates are supported.
	 *
	 * @return bool
	 */
	public function supports_templates() {
		return $this->is_elementor_active();
	}

	/**
	 * Check if landing pages are supported.
	 *
	 * @return bool
	 */
	public function supports_landing_pages() {
		return $this->is_elementor_pro_active();
	}

	/**
	 * Check if globals are supported.
	 *
	 * @return bool
	 */
	public function supports_globals() {
		return $this->is_elementor_pro_active();
	}

	/**
	 * Get available widgets, or full controls for a specific widget.
	 *
	 * @param string|null $widget_name Optional widget type name to get controls for.
	 * @return array|WP_Error List of widgets or single widget with controls.
	 */
	public function get_available_widgets( $widget_name = null ) {
		if ( ! $this->is_elementor_active() ) {
			return array();
		}

		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return array();
		}

		$widget_manager = \Elementor\Plugin::instance()->widgets_manager;
		if ( ! $widget_manager ) {
			return array();
		}

		// Single widget with controls.
		if ( $widget_name ) {
			$widget = $widget_manager->get_widget_types( $widget_name );
			if ( ! $widget ) {
				return new \WP_Error( 'widget_not_found', sprintf( 'Widget "%s" not found.', $widget_name ), array( 'status' => 404 ) );
			}

			$controls = $widget->get_controls();
			$formatted = array();
			foreach ( $controls as $key => $control ) {
				$item = array(
					'type'  => isset( $control['type'] ) ? $control['type'] : '',
					'label' => isset( $control['label'] ) ? $control['label'] : '',
				);
				if ( ! empty( $control['default'] ) ) {
					$item['default'] = $control['default'];
				}
				if ( ! empty( $control['options'] ) ) {
					$item['options'] = $control['options'];
				}
				if ( ! empty( $control['selectors'] ) ) {
					$item['selectors'] = $control['selectors'];
				}
				$formatted[ $key ] = $item;
			}

			return array(
				'name'     => $widget->get_name(),
				'title'    => $widget->get_title(),
				'icon'     => $widget->get_icon(),
				'category' => $widget->get_categories(),
				'controls' => $formatted,
			);
		}

		// List all widgets.
		$widgets    = array();
		$registered = $widget_manager->get_widget_types();
		foreach ( $registered as $widget ) {
			$widgets[] = array(
				'name'     => $widget->get_name(),
				'title'    => $widget->get_title(),
				'icon'     => $widget->get_icon(),
				'category' => $widget->get_categories(),
			);
		}

		return $widgets;
	}

	/**
	 * Get all Elementor templates.
	 *
	 * @param array $args Query arguments.
	 * @return array Templates list.
	 */
	public function get_templates( $args = array() ) {
		$page = $this->get_templates_page( $args );

		// Still a plain list. The paged envelope is a separate method so the
		// admin library screens and every existing caller keep the shape they
		// were written against — changing a return shape under callers is how
		// wp_assign_template started answering nonsense earlier in this
		// release.
		return $page['items'];
	}

	/**
	 * List templates with real pagination and an honest total.
	 *
	 * Two things were wrong before this existed, and both looked like they
	 * worked:
	 *
	 *   - The REST layer accepted `per_page` and `page` and passed them
	 *     straight through to get_posts(), which reads `posts_per_page` and
	 *     `paged`. The parameters were advertised, accepted, and inert: every
	 *     request returned the same first 50 rows.
	 *
	 *   - `total` was `count( $templates )` — the number of rows on the page
	 *     just returned, not the number that exist. With 60 templates and
	 *     per_page 25 it reported 25, so a caller paginating on it stopped
	 *     after page one believing it had seen everything.
	 *
	 * WP_Query is used rather than get_posts() precisely for `found_posts`,
	 * which is the only source of a total that is not a restatement of the
	 * caller's own page size.
	 *
	 * @param array $args Query arguments; accepts page/per_page and include_summary.
	 * @return array{items:array,total:int,page:int,per_page:int,pages:int}
	 */
	public function get_templates_page( $args = array() ) {
		$empty = array(
			'items'    => array(),
			'total'    => 0,
			'page'     => 1,
			'per_page' => 0,
			'pages'    => 0,
		);

		if ( ! $this->is_elementor_active() ) {
			return $empty;
		}

		$args = is_array( $args ) ? $args : array();

		$include_summary = ! empty( $args['include_summary'] );
		unset( $args['include_summary'] );

		// Accept both spellings. `per_page`/`page` is what the REST and MCP
		// surfaces speak; `posts_per_page`/`paged` is what WP_Query speaks and
		// what internal callers already pass.
		$per_page = 50;

		if ( isset( $args['per_page'] ) ) {
			$per_page = (int) $args['per_page'];
		} elseif ( isset( $args['posts_per_page'] ) ) {
			$per_page = (int) $args['posts_per_page'];
		}

		// -1 means "everything", which internal callers rely on. Otherwise
		// clamp: an unbounded per_page from a caller is how a list response
		// grows past what anything can read.
		if ( -1 !== $per_page ) {
			$per_page = max( 1, min( 100, $per_page ) );
		}

		$page = 1;

		if ( isset( $args['page'] ) ) {
			$page = (int) $args['page'];
		} elseif ( isset( $args['paged'] ) ) {
			$page = (int) $args['paged'];
		}

		$page = max( 1, $page );

		unset( $args['per_page'], $args['page'] );

		$defaults = array(
			'post_type'   => 'elementor_library',
			'post_status' => 'publish',
			'orderby'     => 'title',
			'order'       => 'ASC',
		);

		if ( ! empty( $args['type'] ) ) {
			$defaults['meta_query'] = array(
				array(
					'key'   => '_elementor_template_type',
					'value' => sanitize_text_field( $args['type'] ),
				),
			);
		}

		$query_args                   = wp_parse_args( $args, $defaults );
		$query_args['posts_per_page'] = $per_page;
		$query_args['paged']          = $page;
		$query_args['no_found_rows']  = false;

		$query = new WP_Query( $query_args );

		$items = array();

		foreach ( $query->posts as $template ) {
			$item = $this->format_template( $template );

			if ( $include_summary ) {
				$item['summary'] = $this->summarize_template_data( $template->ID );
			}

			$items[] = $item;
		}

		return array(
			'items'    => $items,
			'total'    => (int) $query->found_posts,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => (int) $query->max_num_pages,
		);
	}

	/**
	 * Structural summary of a template, without returning its data.
	 *
	 * Verifying a 13-item library previously meant listing it and then making
	 * 13 separate get calls, decoding each one's Elementor JSON and counting
	 * widgets by hand — an N+1 that grows with the library. These are the
	 * numbers that answer "which one do I want" without transferring the
	 * layouts to find out.
	 *
	 * @param int $template_id Template ID.
	 * @return array
	 */
	private function summarize_template_data( $template_id ) {
		$raw = get_post_meta( (int) $template_id, '_elementor_data', true );

		$summary = array(
			'section_count' => 0,
			'widget_count'  => 0,
			'top_level_ids' => array(),
			// An array-stored template's bytes are those of the JSON it is written as.
			'data_bytes'    => is_string( $raw ) ? strlen( $raw ) : ( is_array( $raw ) ? strlen( (string) wp_json_encode( $raw ) ) : 0 ),
		);

		if ( '' === $raw || null === $raw || false === $raw || array() === $raw ) {
			// No data at all is a fact worth stating: a template that stores
			// nothing and one whose data could not be read are different
			// problems.
			$summary['has_data'] = false;

			return $summary;
		}

		// In whatever form it is stored (#845): an array-stored template
		// stores data, and data that cannot be a tree is unreadable.
		$decoded = Mcpwp_Elementor_Stored_Tree::read( $raw )['elements'];

		if ( ! is_array( $decoded ) ) {
			$summary['has_data']    = true;
			$summary['unreadable']  = true;
			$summary['note']        = 'The stored Elementor data could not be decoded, so the counts below are unknown rather than zero.';

			return $summary;
		}

		$summary['has_data']      = true;
		$summary['section_count'] = count( $decoded );

		foreach ( $decoded as $element ) {
			if ( is_array( $element ) && ! empty( $element['id'] ) ) {
				$summary['top_level_ids'][] = (string) $element['id'];
			}
		}

		$summary['widget_count'] = $this->count_widgets_recursive( $decoded );

		return $summary;
	}

	/**
	 * Count widget elements anywhere in a tree.
	 *
	 * @param array $elements Elements.
	 * @return int
	 */
	private function count_widgets_recursive( $elements ) {
		$count = 0;

		foreach ( (array) $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( isset( $element['elType'] ) && 'widget' === $element['elType'] ) {
				$count++;
			}

			if ( ! empty( $element['elements'] ) ) {
				$count += $this->count_widgets_recursive( $element['elements'] );
			}
		}

		return $count;
	}

	/**
	 * List reusable Elementor parts.
	 *
	 * @param array $args Query arguments.
	 * @return array Parts list.
	 */
	private function build_parts_query_args( $args = array() ) {
		$args = is_array( $args ) ? $args : array();

		$meta_query = array(
			'relation' => 'AND',
			array(
				'key'   => $this->part_flag_meta_key,
				'value' => 'yes',
			),
		);

		if ( ! empty( $args['kind'] ) ) {
			$meta_query[] = array(
				'key'   => $this->part_kind_meta_key,
				'value' => sanitize_text_field( $args['kind'] ),
			);
		}

		if ( ! empty( $args['style'] ) ) {
			$meta_query[] = array(
				'key'   => $this->part_style_meta_key,
				'value' => sanitize_text_field( $args['style'] ),
			);
		}

		if ( ! empty( $args['tag'] ) ) {
			$meta_query[] = array(
				'relation' => 'OR',
				array(
					'key'     => $this->part_tags_index_meta_key,
					'value'   => sanitize_text_field( $args['tag'] ),
					'compare' => 'LIKE',
				),
				array(
					'key'     => $this->part_tags_meta_key,
					'value'   => sanitize_text_field( $args['tag'] ),
					'compare' => 'LIKE',
				),
			);
		}

		unset( $args['kind'], $args['style'], $args['tag'] );
		$args['meta_query'] = $meta_query;

		return $args;
	}

	/**
	 * List reusable Elementor parts.
	 *
	 * @param array $args Query arguments.
	 * @return array Parts list.
	 */
	public function get_parts( $args = array() ) {
		return $this->get_parts_page( $args )['items'];
	}

	/**
	 * List reusable Elementor parts, with pagination and an honest total.
	 *
	 * @param array $args Query arguments.
	 * @return array{items:array,total:int,page:int,per_page:int,pages:int}
	 */
	public function get_parts_page( $args = array() ) {
		return $this->get_templates_page( $this->build_parts_query_args( $args ) );
	}

	/**
	 * List reusable Elementor archetypes.
	 *
	 * @param array $args Query arguments.
	 * @return array Archetypes list.
	 */
	private function build_archetypes_query_args( $args = array() ) {
		$args = is_array( $args ) ? $args : array();

		$meta_query = array(
			'relation' => 'AND',
			array(
				'key'   => $this->archetype_flag_meta_key,
				'value' => 'yes',
			),
		);

		if ( ! empty( $args['scope'] ) ) {
			$meta_query[] = array(
				'key'   => $this->archetype_scope_meta_key,
				'value' => sanitize_key( $args['scope'] ),
			);
		}

		if ( ! empty( $args['archetype_class'] ) ) {
			$meta_query[] = array(
				'key'   => $this->archetype_class_meta_key,
				'value' => sanitize_key( $args['archetype_class'] ),
			);
		}

		if ( ! empty( $args['style'] ) ) {
			$meta_query[] = array(
				'key'   => $this->archetype_style_meta_key,
				'value' => sanitize_text_field( $args['style'] ),
			);
		}

		unset( $args['scope'], $args['archetype_class'], $args['style'] );
		$args['meta_query'] = $meta_query;

		return $args;
	}

	/**
	 * List reusable Elementor archetypes.
	 *
	 * @param array $args Query arguments.
	 * @return array Archetypes list.
	 */
	public function get_archetypes( $args = array() ) {
		return $this->get_archetypes_page( $args )['items'];
	}

	/**
	 * List archetypes, with pagination and an honest total.
	 *
	 * @param array $args Query arguments.
	 * @return array{items:array,total:int,page:int,per_page:int,pages:int}
	 */
	public function get_archetypes_page( $args = array() ) {
		return $this->get_templates_page( $this->build_archetypes_query_args( $args ) );
	}

	/**
	 * Get single template.
	 *
	 * @param int $template_id Template ID.
	 * @return array|WP_Error Template data.
	 */
	public function get_template( $template_id ) {
		if ( ! $this->is_elementor_active() ) {
			return new WP_Error( 'elementor_inactive', __( 'Elementor is not active.', 'mcpwp' ) );
		}

		$template = get_post( $template_id );

		if ( ! $template || 'elementor_library' !== $template->post_type ) {
			return new WP_Error( 'not_found', __( 'Template not found.', 'mcpwp' ) );
		}

		return $this->format_template( $template, true );
	}

	/**
	 * Get a reusable Elementor part by ID.
	 *
	 * @param int $part_id Part/template ID.
	 * @return array|WP_Error Part data or error.
	 */
	public function get_part( $part_id ) {
		$part = $this->get_template( $part_id );
		if ( is_wp_error( $part ) ) {
			return $part;
		}

		if ( empty( $part['is_part'] ) ) {
			return new WP_Error( 'not_part', __( 'Template is not marked as a reusable part.', 'mcpwp' ) );
		}

		return $part;
	}

	/**
	 * Get a reusable Elementor archetype by ID.
	 *
	 * @param int $archetype_id Archetype/template ID.
	 * @return array|WP_Error Archetype data or error.
	 */
	public function get_archetype( $archetype_id ) {
		$archetype = $this->get_template( $archetype_id );
		if ( is_wp_error( $archetype ) ) {
			return $archetype;
		}

		if ( empty( $archetype['is_archetype'] ) ) {
			return new WP_Error( 'not_archetype', __( 'Template is not marked as an archetype.', 'mcpwp' ) );
		}

		return $archetype;
	}

	/**
	 * Format template for API response.
	 *
	 * @param WP_Post $template    Template post.
	 * @param bool    $include_data Include Elementor data.
	 * @return array Formatted template.
	 */
	private function format_template( $template, $include_data = false ) {
		$part_meta      = $this->get_part_metadata( $template->ID );
		$archetype_meta = $this->get_archetype_metadata( $template->ID );

		$data = array(
			'id'         => $template->ID,
			'title'      => $template->post_title,
			'slug'       => $template->post_name,
			'type'       => get_post_meta( $template->ID, '_elementor_template_type', true ),
			'created'    => $template->post_date,
			'modified'   => $template->post_modified,
			'edit_url'   => admin_url( 'post.php?post=' . $template->ID . '&action=elementor' ),
			'is_part'    => $part_meta['is_part'],
			'part_kind'  => $part_meta['part_kind'],
			'part_style' => $part_meta['part_style'],
			'part_tags'  => $part_meta['part_tags'],
			'is_archetype'   => $archetype_meta['is_archetype'],
			'archetype_scope' => $archetype_meta['archetype_scope'],
			'archetype_class' => $archetype_meta['archetype_class'],
			'archetype_style' => $archetype_meta['archetype_style'],
			'archetype_brief' => $archetype_meta['archetype_brief'],
		);

		if ( ! empty( $part_meta['source_page_id'] ) ) {
			$data['source_page_id'] = $part_meta['source_page_id'];
		}

		if ( ! empty( $part_meta['source_element_id'] ) ) {
			$data['source_element_id'] = $part_meta['source_element_id'];
		}

		if ( $include_data ) {
			// Parse into the same array/object shapes wp_get_elementor returns and
			// wp_create_elementor_*/wp_update_elementor_* accept — the raw JSON
			// string form broke direct read -> modify -> write round trips (#636).
			$raw_page_settings = get_post_meta( $template->ID, '_elementor_page_settings', true );

			// In whatever form it is stored (#845). Data that cannot be read
			// is null with the reason beside it: [] claimed an empty template.
			$stored_tree            = Mcpwp_Elementor_Stored_Tree::of_post( $template->ID );
			$data['elementor_data'] = $stored_tree['elements'];
			if ( Mcpwp_Elementor_Stored_Tree::is_unusable( $stored_tree ) ) {
				$data['elementor_data_error'] = Mcpwp_Elementor_Stored_Tree::read_error( $stored_tree );
			}

			if ( empty( $raw_page_settings ) ) {
				$data['page_settings'] = null;
			} else {
				$decoded_settings      = is_array( $raw_page_settings ) ? $raw_page_settings : json_decode( $raw_page_settings, true );
				$data['page_settings'] = is_array( $decoded_settings ) ? $decoded_settings : null;
			}
		}

		return $data;
	}

	/**
	 * Create a new template.
	 *
	 * @param array $data Template data.
	 * @return array|WP_Error Created template or error.
	 */
	public function create_template( $data ) {
		if ( ! $this->is_elementor_active() ) {
			return new WP_Error( 'elementor_inactive', __( 'Elementor is not active.', 'mcpwp' ) );
		}

		$title = ! empty( $data['title'] ) ? sanitize_text_field( $data['title'] ) : __( 'Untitled Template', 'mcpwp' );

		// An omitted type keeps its long-standing default; a type that is
		// PRESENT but wrong is refused, not guessed at (#827).
		$type_given = isset( $data['type'] ) && '' !== $data['type'];
		$type       = $type_given ? sanitize_text_field( (string) $data['type'] ) : 'page';

		$valid_types = $this->get_library_template_types();

		// An empty registry means Elementor's document manager could not be
		// asked (should not happen once is_elementor_active() passed, but a
		// call site that got here with a genuinely blank list is one whose
		// answer nobody can trust) — refuse rather than let ANY type through
		// unchecked, including the default. Checked before the type-mismatch
		// branch below so "the registry is unreadable" and "the registry
		// answered and this type is not in it" are never reported as the
		// same error.
		if ( empty( $valid_types ) ) {
			return new WP_Error(
				'template_types_unavailable',
				__( 'Elementor\'s template type registry could not be read, so the requested type could not be validated. Nothing was created.', 'mcpwp' ),
				array( 'status' => 500 )
			);
		}

		if ( $type_given && ! in_array( $type, $valid_types, true ) ) {
			// This used to fall back to 'page' silently: type:"single-post"
			// created a PAGE-type template that could never be bound to a
			// post condition, and the tool reported success (#827). A page
			// template and a single-post template are both real, different
			// Elementor documents — there is no safe default between them,
			// so a wrong type is refused, not repaired.
			return new WP_Error(
				'invalid_template_type',
				sprintf(
					/* translators: 1: the invalid type, 2: comma-separated list of valid types */
					__( 'Invalid template type "%1$s". Must be one of: %2$s. Nothing was created.', 'mcpwp' ),
					$type,
					implode( ', ', $valid_types )
				),
				array(
					'status'      => 400,
					'valid_types' => $valid_types,
				)
			);
		}

		$post_data = array(
			'post_title'  => $title,
			'post_status' => 'publish',
			'post_type'   => 'elementor_library',
		);

		$template_id = wp_insert_post( $post_data );

		if ( is_wp_error( $template_id ) ) {
			return $template_id;
		}

		// Set template type.
		update_post_meta( $template_id, '_elementor_template_type', $type );
		update_post_meta( $template_id, '_elementor_edit_mode', 'builder' );

		// Set Elementor data if provided.
		if ( ! empty( $data['elementor_data'] ) ) {
			$save_result = $this->save_elementor_data( $template_id, $data['elementor_data'] );
			if ( is_wp_error( $save_result ) ) {
				return $save_result;
			}
		}

		// Page settings — the document half of a full-page template. Without
		// this a "full-page" template carried the layout and lost the frame:
		// canvas choice, hide-title, body background, page CSS (#638).
		$settings_report = $this->store_template_page_settings( $template_id, $data );

		$this->save_part_metadata( $template_id, $data );
		$this->save_archetype_metadata( $template_id, $data );

		$created = $this->get_template( $template_id );

		if ( is_array( $created ) && null !== $settings_report ) {
			$created['page_settings_result'] = $settings_report;
		}

		return $created;
	}

	/**
	 * The template types Elementor's own "Add New Template" screen offers —
	 * asked of Documents_Manager live, not a list frozen at the time this was
	 * written (#827).
	 *
	 * A hardcoded eight-type list (page, section, header, footer, single,
	 * archive, popup, loop-item) went stale the moment Elementor Pro shipped
	 * single-post, single-page, search-results, error-404, product and
	 * product-archive as their own document types — each a distinct,
	 * independently bindable Elementor document, not a spelling of one of the
	 * eight. `single-post` failing this list is what silently produced a
	 * `page`-type template that could never be bound to a post (#827): the
	 * list, not the caller, was wrong.
	 *
	 * Elementor's own new-template dialog builds its dropdown the same way
	 * (`includes/admin-templates/new-template.php`): every registered document
	 * type whose `show_in_library` property is true. Types that exist but are
	 * not offered there (`post`, `wp-page`, `wp-post`, `kit`, `widget`, …) are
	 * excluded the same way Elementor excludes them from its own UI.
	 *
	 * @return string[] Sorted type slugs, e.g. "archive", "page", "single-post". Empty when the registry cannot be read.
	 */
	private function get_library_template_types() {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return array();
		}

		$plugin = \Elementor\Plugin::instance();
		if ( empty( $plugin ) || empty( $plugin->documents ) || ! method_exists( $plugin->documents, 'get_document_types' ) ) {
			return array();
		}

		return self::filter_library_types( $plugin->documents->get_document_types() );
	}

	/**
	 * Reduce a Documents_Manager type => class map to the slugs Elementor's
	 * own template picker would show. Split out of get_library_template_types()
	 * so the filtering rule is testable without a live Elementor instance.
	 *
	 * @param array $document_types Type slug => document class name.
	 * @return string[] Sorted type slugs with show_in_library true.
	 */
	private static function filter_library_types( array $document_types ) {
		$types = array();

		foreach ( $document_types as $type => $document_class ) {
			if ( ! is_string( $document_class ) || ! method_exists( $document_class, 'get_property' ) ) {
				continue;
			}

			if ( $document_class::get_property( 'show_in_library' ) ) {
				$types[] = (string) $type;
			}
		}

		sort( $types );

		return $types;
	}

	/**
	 * Store submitted page settings on a template, reporting what was kept.
	 *
	 * Returns null when the caller sent none, so "no settings submitted" and
	 * "settings submitted and all refused" do not read the same.
	 *
	 * @param int   $template_id Template ID.
	 * @param array $data        Submitted payload.
	 * @return array|null Report, or null when nothing was submitted.
	 */
	private function store_template_page_settings( $template_id, $data ) {
		if ( ! isset( $data['page_settings'] ) || ! is_array( $data['page_settings'] ) || empty( $data['page_settings'] ) ) {
			return null;
		}

		$normalized = Mcpwp_Elementor_Page_Settings::normalize( $data['page_settings'] );
		$written    = Mcpwp_Elementor_Page_Settings::write( $template_id, $normalized['settings'] );

		$report = array(
			// The keys actually stored, not the keys submitted. The previous
			// page-settings write reported the caller's own list back to them,
			// so a dropped key was indistinguishable from an applied one.
			'applied' => array_keys( $normalized['settings'] ),
			'skipped' => $normalized['skipped'],
			'written' => $written,
		);

		if ( ! empty( $normalized['skipped'] ) ) {
			$report['note'] = 'Skipped settings are not recognised Elementor document settings and were not stored.';
		}

		if ( ! $written ) {
			$report['warning'] = 'The page settings could not be stored on this template.';
		}

		return $report;
	}

	/**
	 * Update a template.
	 *
	 * @param int   $template_id Template ID.
	 * @param array $data        Update data.
	 * @return array|WP_Error Updated template or error.
	 */
	public function update_template( $template_id, $data ) {
		$template = get_post( $template_id );

		if ( ! $template || 'elementor_library' !== $template->post_type ) {
			return new WP_Error( 'not_found', __( 'Template not found.', 'mcpwp' ) );
		}

		// Update title if provided.
		if ( ! empty( $data['title'] ) ) {
			wp_update_post( array(
				'ID'         => $template_id,
				'post_title' => sanitize_text_field( $data['title'] ),
			) );
		}

		// Update Elementor data if provided.
		if ( ! empty( $data['elementor_data'] ) ) {
			$save_result = $this->save_elementor_data( $template_id, $data['elementor_data'] );
			if ( is_wp_error( $save_result ) ) {
				return $save_result;
			}
		}

		$settings_report = $this->store_template_page_settings( $template_id, $data );

		$this->save_part_metadata( $template_id, $data );
		$this->save_archetype_metadata( $template_id, $data );

		$updated = $this->get_template( $template_id );

		if ( is_array( $updated ) && null !== $settings_report ) {
			$updated['page_settings_result'] = $settings_report;
		}

		return $updated;
	}

	/**
	 * Create a reusable archetype directly from Elementor template data.
	 *
	 * @param array $data Archetype definition.
	 * @return array|WP_Error
	 */
	public function create_archetype( $data ) {
		$data['is_archetype'] = true;
		$data['type']         = ! empty( $data['type'] ) ? $data['type'] : 'page';

		return $this->create_template( $data );
	}

	/**
	 * Create a reusable part directly from Elementor template data.
	 *
	 * @param array $data Part definition.
	 * @return array|WP_Error
	 */
	public function create_part( $data ) {
		$data['is_part'] = true;
		$data['type']    = ! empty( $data['type'] ) ? $data['type'] : 'section';

		return $this->create_template( $data );
	}

	/**
	 * Create a reusable part by extracting a section from a live page.
	 *
	 * @param int   $page_id    Source page ID.
	 * @param string $element_id Source section/container ID.
	 * @param array $data       Part metadata.
	 * @return array|WP_Error
	 */
	public function create_part_from_section( $page_id, $element_id, $data = array() ) {
		$title = ! empty( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '';
		$result = $this->save_section_as_template( $page_id, $element_id, $title );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$update = array(
			'is_part'           => true,
			'part_kind'         => isset( $data['part_kind'] ) ? $data['part_kind'] : '',
			'part_style'        => isset( $data['part_style'] ) ? $data['part_style'] : '',
			'part_tags'         => isset( $data['part_tags'] ) ? $data['part_tags'] : array(),
			'source_page_id'    => $page_id,
			'source_element_id' => $element_id,
		);

		$this->save_part_metadata( $result['template_id'], $update );

		return $this->get_part( $result['template_id'] );
	}

	/**
	 * Apply a reusable part to a page.
	 *
	 * @param int $part_id Part/template ID.
	 * @param int $page_id Target page ID.
	 * @return bool|WP_Error
	 */
	public function apply_part_to_page( $part_id, $page_id ) {
		$part = $this->get_part( $part_id );
		if ( is_wp_error( $part ) ) {
			return $part;
		}

		return $this->apply_template_to_page( $page_id, $part_id );
	}

	/**
	 * Apply a reusable archetype to a page.
	 *
	 * @param int $archetype_id Archetype/template ID.
	 * @param int $page_id      Target page ID.
	 * @return bool|WP_Error
	 */
	public function apply_archetype_to_page( $archetype_id, $page_id, $page_settings_mode = 'preserve' ) {
		$archetype = $this->get_archetype( $archetype_id );
		if ( is_wp_error( $archetype ) ) {
			return $archetype;
		}

		return $this->apply_template_to_page( $page_id, $archetype_id, $page_settings_mode );
	}

	/**
	 * Insert a reusable part into an existing page without replacing all content.
	 *
	 * @param int    $part_id   Part/template ID.
	 * @param int    $page_id   Target page ID.
	 * @param string $position  Insert position: start, end, before:{id}, after:{id}.
	 * @return array|WP_Error
	 */
	public function insert_part_into_page( $part_id, $page_id, $position = 'end' ) {
		$part = $this->get_part( $part_id );
		if ( is_wp_error( $part ) ) {
			return $part;
		}

		// A part whose data cannot be read is refused as unreadable (#845):
		// "has no Elementor elements" described a different problem.
		if ( ! empty( $part['elementor_data_error'] ) ) {
			return new WP_Error(
				'invalid_elementor_data',
				$part['elementor_data_error']['message'],
				array(
					'status'    => 422,
					'stored_as' => $part['elementor_data_error']['stored_as'],
				)
			);
		}

		$elements = $part['elementor_data'];
		if ( is_string( $elements ) ) {
			$decoded = json_decode( $elements, true );
			$elements = is_array( $decoded ) ? $decoded : array();
		}

		if ( ! is_array( $elements ) || empty( $elements ) ) {
			return new WP_Error( 'empty_part', __( 'Reusable part has no Elementor elements.', 'mcpwp' ) );
		}

		$basic   = $this->get_basic_handler();
		$results = array();

		foreach ( array_values( $elements ) as $index => $element ) {
			$current_position = ( 0 === $index ) ? $position : 'end';
			$result           = $basic->add_section(
				$page_id,
				array(
					'element'  => $element,
					'position' => $current_position,
				)
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$results[] = $result;
		}

		return array(
			'inserted'        => true,
			'part_id'         => (int) $part_id,
			'page_id'         => (int) $page_id,
			'position'        => $position,
			'inserted_count'  => count( $results ),
			'inserted_ids'    => wp_list_pluck( $results, 'element_id' ),
			'operations'      => $results,
			'last_operation'  => end( $results ),
		);
	}

	/**
	 * Save reusable part metadata.
	 *
	 * @param int   $template_id Template ID.
	 * @param array $data        Part metadata payload.
	 * @return void
	 */
	private function save_part_metadata( $template_id, $data ) {
		if ( array_key_exists( 'is_part', $data ) ) {
			$is_part = ! empty( $data['is_part'] ) ? 'yes' : '';
			if ( '' === $is_part ) {
				delete_post_meta( $template_id, $this->part_flag_meta_key );
			} else {
				update_post_meta( $template_id, $this->part_flag_meta_key, $is_part );
			}
		}

		$meta_map = array(
			'part_kind'         => $this->part_kind_meta_key,
			'part_style'        => $this->part_style_meta_key,
			'source_page_id'    => $this->part_source_page_meta_key,
			'source_element_id' => $this->part_source_element_meta_key,
		);

		foreach ( $meta_map as $input_key => $meta_key ) {
			if ( ! array_key_exists( $input_key, $data ) ) {
				continue;
			}

			$value = is_numeric( $data[ $input_key ] ) ? absint( $data[ $input_key ] ) : sanitize_text_field( (string) $data[ $input_key ] );

			if ( '' === $value || 0 === $value ) {
				delete_post_meta( $template_id, $meta_key );
			} else {
				update_post_meta( $template_id, $meta_key, $value );
			}
		}

		if ( array_key_exists( 'part_tags', $data ) ) {
			$tags = $this->normalize_part_tags( $data['part_tags'] );
			if ( empty( $tags ) ) {
				delete_post_meta( $template_id, $this->part_tags_meta_key );
				delete_post_meta( $template_id, $this->part_tags_index_meta_key );
			} else {
				update_post_meta( $template_id, $this->part_tags_meta_key, wp_json_encode( $tags ) );
				update_post_meta( $template_id, $this->part_tags_index_meta_key, implode( ' ', array_map( 'sanitize_title', $tags ) ) );
			}
		}
	}

	/**
	 * Read reusable part metadata.
	 *
	 * @param int $template_id Template ID.
	 * @return array
	 */
	private function get_part_metadata( $template_id ) {
		$raw_tags = get_post_meta( $template_id, $this->part_tags_meta_key, true );
		$tags     = array();

		if ( is_string( $raw_tags ) && '' !== $raw_tags ) {
			$decoded = json_decode( $raw_tags, true );
			if ( is_array( $decoded ) ) {
				$tags = $this->normalize_part_tags( $decoded );
			} else {
				$tags = $this->normalize_part_tags( explode( ',', $raw_tags ) );
			}
		}

		return array(
			'is_part'           => 'yes' === get_post_meta( $template_id, $this->part_flag_meta_key, true ),
			'part_kind'         => (string) get_post_meta( $template_id, $this->part_kind_meta_key, true ),
			'part_style'        => (string) get_post_meta( $template_id, $this->part_style_meta_key, true ),
			'part_tags'         => $tags,
			'source_page_id'    => absint( get_post_meta( $template_id, $this->part_source_page_meta_key, true ) ),
			'source_element_id' => (string) get_post_meta( $template_id, $this->part_source_element_meta_key, true ),
		);
	}

	/**
	 * Save archetype metadata.
	 *
	 * @param int   $template_id Template ID.
	 * @param array $data        Metadata payload.
	 * @return void
	 */
	private function save_archetype_metadata( $template_id, $data ) {
		if ( array_key_exists( 'is_archetype', $data ) ) {
			$is_archetype = ! empty( $data['is_archetype'] ) ? 'yes' : '';
			if ( '' === $is_archetype ) {
				delete_post_meta( $template_id, $this->archetype_flag_meta_key );
			} else {
				update_post_meta( $template_id, $this->archetype_flag_meta_key, $is_archetype );
			}
		}

		$meta_map = array(
			'archetype_scope' => $this->archetype_scope_meta_key,
			'archetype_class' => $this->archetype_class_meta_key,
			'archetype_style' => $this->archetype_style_meta_key,
			'archetype_brief' => $this->archetype_brief_meta_key,
		);

		foreach ( $meta_map as $input_key => $meta_key ) {
			if ( ! array_key_exists( $input_key, $data ) ) {
				continue;
			}

			$value = 'archetype_brief' === $input_key
				? sanitize_textarea_field( (string) $data[ $input_key ] )
				: sanitize_text_field( (string) $data[ $input_key ] );
			if ( in_array( $input_key, array( 'archetype_scope', 'archetype_class' ), true ) ) {
				$value = sanitize_key( $value );
			}

			if ( '' === $value ) {
				delete_post_meta( $template_id, $meta_key );
			} else {
				update_post_meta( $template_id, $meta_key, $value );
			}
		}
	}

	/**
	 * Read archetype metadata.
	 *
	 * @param int $template_id Template ID.
	 * @return array
	 */
	private function get_archetype_metadata( $template_id ) {
		return array(
			'is_archetype'    => 'yes' === get_post_meta( $template_id, $this->archetype_flag_meta_key, true ),
			'archetype_scope' => (string) get_post_meta( $template_id, $this->archetype_scope_meta_key, true ),
			'archetype_class' => (string) get_post_meta( $template_id, $this->archetype_class_meta_key, true ),
			'archetype_style' => (string) get_post_meta( $template_id, $this->archetype_style_meta_key, true ),
			'archetype_brief' => (string) get_post_meta( $template_id, $this->archetype_brief_meta_key, true ),
		);
	}

	/**
	 * Normalize part tags into a clean string array.
	 *
	 * @param mixed $tags Raw input.
	 * @return array
	 */
	private function normalize_part_tags( $tags ) {
		if ( is_string( $tags ) ) {
			$tags = explode( ',', $tags );
		}

		if ( ! is_array( $tags ) ) {
			return array();
		}

		$clean = array();
		foreach ( $tags as $tag ) {
			$tag = sanitize_text_field( (string) $tag );
			if ( '' !== $tag ) {
				$clean[] = $tag;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Delete a template.
	 *
	 * @param int  $template_id Template ID.
	 * @param bool $force       Force delete (bypass trash).
	 * @return bool|WP_Error True on success, error on failure.
	 */
	public function delete_template( $template_id, $force = false ) {
		$template = get_post( $template_id );

		if ( ! $template || 'elementor_library' !== $template->post_type ) {
			return new WP_Error( 'not_found', __( 'Template not found.', 'mcpwp' ) );
		}

		$result = wp_delete_post( $template_id, $force );

		if ( ! $result ) {
			return new WP_Error( 'delete_failed', __( 'Failed to delete template.', 'mcpwp' ) );
		}

		// #952: elementor_pro_theme_builder_conditions cleanup happens via
		// Mcpwp_Theme_Builder::handle_before_delete_post(), hooked on
		// WordPress's own before_delete_post — it fires for wp_delete_post()
		// itself, so it also catches this template ever being deleted
		// through a path this class does not own (wp-admin, WP-CLI, another
		// plugin, or MCPWP's own generic /posts and /content routes).
		return true;
	}

	/**
	 * Clone a page with Elementor data.
	 *
	 * @param int   $source_id Source page/post ID.
	 * @param array $args      Clone arguments.
	 * @return array|WP_Error Cloned page data or error.
	 */
	public function clone_page( $source_id, $args = array() ) {
		$source = get_post( $source_id );

		if ( ! $source ) {
			return new WP_Error( 'not_found', __( 'Source page not found.', 'mcpwp' ) );
		}

		// A Custom Code snippet must not be cloned by copying its meta raw: the
		// copy skips the unfiltered_html grant (Elementor's sanitize_callback
		// then kses-strips _elementor_code) and the conditions-cache sync, and
		// this route is reachable by non-admin keys while every Custom Code
		// route is admin-only (#974/#976 adversarial review, round 3).
		if ( 'elementor_snippet' === $source->post_type ) {
			return new WP_Error(
				'use_custom_code_tool',
				__( 'Elementor Custom Code snippets cannot be cloned. Read the snippet and create a new one with wp_create_elementor_custom_code.', 'mcpwp' ),
				array( 'status' => 400 )
			);
		}

		$title  = ! empty( $args['title'] ) ? sanitize_text_field( $args['title'] ) : $source->post_title . ' (Copy)';
		$status = ! empty( $args['status'] ) ? sanitize_text_field( $args['status'] ) : 'draft';

		// Create new post. _elementor_data is front-loaded into meta_input
		// (rather than copied in the loop below, after insert) specifically
		// so a clone that publishes immediately — exactly the duplicate-
		// content pattern rule 3 exists for, e.g. the classic per-city
		// landing page cloned and republished with only its title changed —
		// is duplicate-checked against its REAL content by
		// Mcpwp_Content_Guardrails::on_insert_post_data() (#957), which has
		// no post ID to look postmeta up by otherwise. See that method's
		// docblock.
		$elementor_data = get_post_meta( $source_id, '_elementor_data', true );

		// #1032: a clone copies _elementor_data verbatim, bypassing
		// save_elementor_data()/validate_and_fix_elements() entirely — so a
		// Template/Global widget or Loop Grid/Carousel referencing a
		// template this caller cannot read would otherwise ride along into
		// a brand-new post the caller DOES fully control (and can then read
		// back through /elementor/{id}/preview). Same gate, same refusal, as
		// every other write path — also scans the raw post_content string
		// itself for an `[elementor-template]` shortcode (#1032 B3), which
		// find_ids() alone would not see.
		if ( ! empty( $elementor_data ) && class_exists( 'Mcpwp_Elementor_Stored_Tree' ) ) {
			$source_elements = Mcpwp_Elementor_Stored_Tree::read( $elementor_data )['elements'];
			if ( is_array( $source_elements ) ) {
				$ref_check = $this->check_template_references( $source_elements );
				if ( is_wp_error( $ref_check ) ) {
					return $ref_check;
				}
			}
		}
		if ( null !== $this->template_ref_gate && is_string( $source->post_content ) && '' !== $source->post_content ) {
			$ids = class_exists( 'Mcpwp_Elementor_Template_Refs' ) ? Mcpwp_Elementor_Template_Refs::ids_in_text( $source->post_content ) : array();
			if ( ! empty( $ids ) ) {
				$ref_check = call_user_func( $this->template_ref_gate, $ids );
				if ( is_wp_error( $ref_check ) ) {
					return $ref_check;
				}
			}
		}

		$new_post = array(
			'post_title'   => $title,
			'post_content' => $source->post_content,
			'post_excerpt' => $source->post_excerpt,
			'post_status'  => $status,
			'post_type'    => $source->post_type,
			'post_author'  => get_current_user_id(),
		);
		if ( ! empty( $elementor_data ) ) {
			// Slashed, as update_post_meta() (which meta_input calls) expects:
			// unslashed, every `\"` in the JSON lost its backslash and the
			// clone's data could not be decoded (#829).
			$new_post['meta_input'] = array( '_elementor_data' => wp_slash( $elementor_data ) );
		}

		if ( ! empty( $args['parent'] ) ) {
			$new_post['post_parent'] = absint( $args['parent'] );
		}

		// MCPWP writes the copy's Elementor meta (#829): a copy made by a
		// principal without unfiltered_html is a sanitized copy, whatever the
		// post type — nothing is "known" on a post that did not exist.
		$new_id = Mcpwp_Elementor_Meta_Write::as_writer(
			0,
			static function () use ( $new_post ) {
				return wp_insert_post( $new_post );
			}
		);

		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		// Copy every OTHER post meta — _elementor_data was already written
		// above, atomically with the insert.
		$meta = get_post_meta( $source_id );
		Mcpwp_Elementor_Meta_Write::as_writer(
			(int) $new_id,
			static function () use ( $meta, $new_id ) {
				foreach ( $meta as $key => $values ) {
					if ( '_elementor_data' === $key ) {
						continue;
					}
					foreach ( $values as $value ) {
						// Slashed: add_post_meta() unslashes, which stripped every
						// backslash from the copy (#829 review round 2).
						add_post_meta( $new_id, $key, wp_slash( maybe_unserialize( $value ) ) );
					}
				}
			}
		);

		// The guardrails filter above may have downgraded post_status —
		// read back what actually landed, not what was requested.
		$status = get_post_status( $new_id );

		// Copy taxonomies.
		$taxonomies = get_object_taxonomies( $source->post_type );
		foreach ( $taxonomies as $taxonomy ) {
			$terms = wp_get_object_terms( $source_id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				wp_set_object_terms( $new_id, $terms, $taxonomy );
			}
		}

		// Get the cloned page with Elementor data.
		$result = array(
			'id'             => $new_id,
			'title'          => $title,
			'status'         => $status,
			'source_id'      => $source_id,
			'url'            => get_permalink( $new_id ),
			'edit_url'       => admin_url( 'post.php?post=' . $new_id . '&action=elementor' ),
			'elementor_data' => get_post_meta( $new_id, '_elementor_data', true ),
		);

		return $result;
	}

	/**
	 * Create a landing page with Elementor.
	 *
	 * @param array $data Landing page data.
	 * @return array|WP_Error Created page data or error.
	 */
	public function create_landing_page( $data ) {
		if ( ! $this->is_elementor_active() ) {
			return new WP_Error( 'elementor_inactive', __( 'Elementor is not active.', 'mcpwp' ) );
		}

		$title  = ! empty( $data['title'] ) ? sanitize_text_field( $data['title'] ) : __( 'Landing Page', 'mcpwp' );
		$status = ! empty( $data['status'] ) ? sanitize_text_field( $data['status'] ) : 'draft';

		// Build the Elementor structure before insert (not after) and pass
		// it via meta_input — a real wp_insert_post() feature that writes
		// postmeta atomically alongside the post — so it is visible to
		// Mcpwp_Content_Guardrails::on_insert_post_data() (#957) even though
		// the page has no ID yet at that point. Content guardrails are
		// enforced entirely there now, not here.
		$landing_elementor_data = null;
		if ( ! empty( $data['sections'] ) ) {
			$landing_elementor_data = $this->build_landing_page_structure( $data['sections'] );
		} elseif ( ! empty( $data['elementor_data'] ) ) {
			$landing_elementor_data = $data['elementor_data'];
		}

		// #1032 W1 (adversarial review round 1): $landing_elementor_data is
		// written into meta_input BELOW, atomically with wp_insert_post() —
		// BEFORE save_elementor_data() runs at all (it only runs afterward,
		// on an already-existing page). That later call's own refusal
		// (check_template_references(), wired via the basic handler) is too
		// late: the page already exists, with the gated reference already on
		// it, when it fires — an orphan draft the caller can then read via
		// /elementor/{id}/preview. Gated here, before any post exists.
		if ( null !== $landing_elementor_data ) {
			$elements_for_ref_check = is_string( $landing_elementor_data )
				? json_decode( $landing_elementor_data, true )
				: $landing_elementor_data;
			if ( is_array( $elements_for_ref_check ) ) {
				$ref_check = $this->check_template_references( $elements_for_ref_check );
				if ( is_wp_error( $ref_check ) ) {
					return $ref_check;
				}
			}
		}

		// Create the page.
		$page_data = array(
			'post_title'  => $title,
			'post_status' => $status,
			'post_type'   => 'page',
			'post_author' => get_current_user_id(),
		);
		if ( null !== $landing_elementor_data ) {
			// Slashed, as update_post_meta() (which meta_input calls) expects (#829).
			$page_data['meta_input'] = array( '_elementor_data' => wp_slash( $landing_elementor_data ) );
		}

		// meta_input is an MCPWP write of _elementor_data (#829).
		$page_id = Mcpwp_Elementor_Meta_Write::as_writer(
			0,
			static function () use ( $page_data ) {
				return wp_insert_post( $page_data );
			}
		);

		if ( is_wp_error( $page_id ) ) {
			return $page_id;
		}

		// Set Elementor template.
		update_post_meta( $page_id, '_wp_page_template', 'elementor_header_footer' );
		update_post_meta( $page_id, '_elementor_edit_mode', 'builder' );

		if ( null !== $landing_elementor_data ) {
			$save_result = $this->save_elementor_data( $page_id, $landing_elementor_data );
			if ( is_wp_error( $save_result ) ) {
				return $save_result;
			}
		}

		// Apply template if specified. #1024/#1030 round-2 review, WARN-B:
		// this used to ignore apply_template_to_page()'s return value
		// entirely — a missing OR category-gated template_id still
		// answered 201 with a real, published page, just silently without
		// the template applied. Refuse the same way apply_template_to_page()
		// itself would, cleaning up the page this call already created
		// rather than leaving an orphan behind.
		if ( ! empty( $data['template_id'] ) ) {
			$template_result = $this->apply_template_to_page( $page_id, absint( $data['template_id'] ) );
			if ( is_wp_error( $template_result ) ) {
				wp_delete_post( $page_id, true );
				return $template_result;
			}
		}

		$result = array(
			'id'             => $page_id,
			'title'          => $title,
			'status'         => get_post_status( $page_id ),
			'url'            => get_permalink( $page_id ),
			'edit_url'       => admin_url( 'post.php?post=' . $page_id . '&action=elementor' ),
			'elementor_data' => get_post_meta( $page_id, '_elementor_data', true ),
		);

		return $result;
	}

	/**
	 * Build landing page structure from section definitions.
	 *
	 * @param array $sections Section definitions.
	 * @return array Elementor data structure.
	 */
	private function build_landing_page_structure( $sections ) {
		$data = array();

		foreach ( $sections as $section ) {
			$section_id = $this->generate_element_id();
			$section_data = array(
				'id'       => $section_id,
				'elType'   => 'section',
				'settings' => array(),
				'elements' => array(),
			);

			// Apply section settings.
			if ( ! empty( $section['settings'] ) ) {
				$section_data['settings'] = $section['settings'];
			}

			// Add columns.
			$columns = ! empty( $section['columns'] ) ? $section['columns'] : array( array() );
			foreach ( $columns as $column ) {
				$column_id   = $this->generate_element_id();
				$column_data = array(
					'id'       => $column_id,
					'elType'   => 'column',
					'settings' => ! empty( $column['settings'] ) ? $column['settings'] : array(
						'_column_size' => floor( 100 / count( $columns ) ),
					),
					'elements' => array(),
				);

				// Add widgets to column.
				if ( ! empty( $column['widgets'] ) ) {
					foreach ( $column['widgets'] as $widget ) {
						$widget_id   = $this->generate_element_id();
						$widget_data = array(
							'id'         => $widget_id,
							'elType'     => 'widget',
							'widgetType' => $widget['type'],
							'settings'   => ! empty( $widget['settings'] ) ? $widget['settings'] : array(),
						);
						$column_data['elements'][] = $widget_data;
					}
				}

				$section_data['elements'][] = $column_data;
			}

			$data[] = $section_data;
		}

		return $data;
	}

	/**
	 * Apply a template to a page.
	 *
	 * @param int $page_id     Target page ID.
	 * @param int $template_id Template ID.
	 * @return array|WP_Error Save result, or error on failure.
	 */
	public function apply_template_to_page( $page_id, $template_id, $page_settings_mode = 'preserve' ) {
		// #1024/#1030 round-2 review, BLOCK-B: this used to write
		// _elementor_edit_mode/_wp_page_template/_elementor_template_type to
		// $page_id (below) BEFORE validating it at all — a caller reaching
		// this with a WooCommerce product id (the #1030 round-2 review's
		// PoC) flipped the product into Elementor canvas mode before
		// anything refused. The REST-layer chokepoint
		// (Mcpwp_Api_Auth::check_post_naming_params_category()) now denies a
		// category-gated page_id before this method is even called, but a
		// product/order/etc. is not gated BY TYPE for an ordinary key — it
		// is simply never a valid Elementor target — so this checks the
		// type here too, unconditionally, before any write.
		$target = get_post( $page_id );
		if ( ! $target || ! in_array( $target->post_type, array( 'page', 'post', 'elementor_library', 'elementor_snippet' ), true ) ) {
			return new WP_Error( 'not_found', __( 'Target post not found or is not a supported type.', 'mcpwp' ), array( 'status' => 404 ) );
		}

		$template = get_post( $template_id );

		if ( ! $template || 'elementor_library' !== $template->post_type ) {
			return new WP_Error( 'not_found', __( 'Template not found.', 'mcpwp' ) );
		}

		// Get template data, in whatever form it is stored (#845).
		$template_data = get_post_meta( $template_id, '_elementor_data', true );

		if ( empty( $template_data ) ) {
			return new WP_Error( 'empty_template', __( 'Template has no content.', 'mcpwp' ) );
		}

		// Data that cannot be a tree is refused before the page is switched
		// to Elementor below.
		$template_tree = Mcpwp_Elementor_Stored_Tree::read( $template_data );
		if ( Mcpwp_Elementor_Stored_Tree::is_unusable( $template_tree ) ) {
			return Mcpwp_Elementor_Stored_Tree::refusal( $template_tree, "The template's Elementor data" );
		}

		// Text is handed on as stored, exactly as before; an array-stored tree
		// is handed on as the list it normalises to.
		if ( is_array( $template_data ) ) {
			$template_data = $template_tree['elements'];
		}

		// Set required meta for frontend rendering.
		update_post_meta( $page_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $page_id, '_wp_page_template', 'elementor_header_footer' );

		$post_type  = get_post_type( $page_id );
		$type_value = ( 'elementor_library' === $post_type ) ? 'section' : 'wp-page';
		update_post_meta( $page_id, '_elementor_template_type', $type_value );

		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			update_post_meta( $page_id, '_elementor_version', ELEMENTOR_VERSION );
		}
		if ( defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			update_post_meta( $page_id, '_elementor_pro_version', ELEMENTOR_PRO_VERSION );
		}

		// Save via Document::save() for proper frontend rendering.
		$save_result = $this->save_elementor_data( $page_id, $template_data );
		if ( is_wp_error( $save_result ) ) {
			return $save_result;
		}

		// Carry the template's document settings too, under an explicit mode.
		// Applying the layout without them left a full-page template producing
		// the right sections inside the wrong frame (#638).
		//
		// `preserve` is the default on purpose: applying a template should not
		// silently restyle a page's canvas, title visibility or page CSS. A
		// caller who does want the whole document says so.
		$settings_report = Mcpwp_Elementor_Page_Settings::apply(
			$page_id,
			Mcpwp_Elementor_Page_Settings::read( $template_id ),
			$page_settings_mode
		);

		if ( is_array( $save_result ) ) {
			$save_result['page_settings'] = $settings_report;
		}

		return $save_result;
	}

	/**
	 * Get global colors and fonts (Elementor Pro).
	 *
	 * @return array|WP_Error Global settings or error.
	 */
	public function get_globals() {
		if ( ! $this->is_elementor_pro_active() ) {
			return new WP_Error( 'pro_required', __( 'Elementor Pro is required for global settings.', 'mcpwp' ) );
		}

		$globals = array(
			'colors' => array(
				'system_colors' => array(),
				'custom_colors' => array(),
			),
			'fonts'  => array(
				'system_typography' => array(),
				'custom_typography' => array(),
			),
		);

		// Get kit settings.
		if ( class_exists( '\Elementor\Plugin' ) ) {
			$kit = \Elementor\Plugin::instance()->kits_manager->get_active_kit();
			if ( $kit ) {
				$kit_settings = $kit->get_settings();

				// Extract global colors — both system and custom.
				if ( ! empty( $kit_settings['system_colors'] ) ) {
					$globals['colors']['system_colors'] = $kit_settings['system_colors'];
				}
				if ( ! empty( $kit_settings['custom_colors'] ) ) {
					$globals['colors']['custom_colors'] = $kit_settings['custom_colors'];
				}

				// Extract global fonts — both system and custom.
				if ( ! empty( $kit_settings['system_typography'] ) ) {
					$globals['fonts']['system_typography'] = $kit_settings['system_typography'];
				}
				if ( ! empty( $kit_settings['custom_typography'] ) ) {
					$globals['fonts']['custom_typography'] = $kit_settings['custom_typography'];
				}
			}
		}

		return $globals;
	}

	/**
	 * Save a section from a live page as a reusable Elementor library template.
	 *
	 * @param int    $page_id    Page containing the section.
	 * @param string $element_id Element ID to extract.
	 * @param string $title      Template title (optional).
	 * @return array|WP_Error Template info or error.
	 */
	public function save_section_as_template( $page_id, $element_id, $title = '' ) {
		$post = get_post( $page_id );
		// #1024/#1030 round-2 review, BLOCK-B: this core method has no type
		// check of its own — a WooCommerce product's Elementor content
		// could be read and copied into a brand-new, fully-readable
		// template. The REST-layer chokepoint and this class's own
		// save_section_as_template REST handler both gate the direct
		// /elementor/save-section-as-template route already; this covers
		// create_part_from_section() too, which calls this SAME core
		// method for /elementor/parts/from-section.
		if ( ! $post || ! in_array( $post->post_type, array( 'page', 'post', 'elementor_library', 'elementor_snippet' ), true ) ) {
			return new WP_Error( 'invalid_page', 'Page not found.', array( 'status' => 404 ) );
		}

		// In whatever form it is stored (#845). Data that cannot be read is
		// refused as unreadable, not reported as a page with no data.
		$stored = Mcpwp_Elementor_Stored_Tree::of_post( $page_id );
		if ( Mcpwp_Elementor_Stored_Tree::is_unusable( $stored ) ) {
			return Mcpwp_Elementor_Stored_Tree::unreadable( $stored );
		}
		$elements = $stored['elements'];
		if ( empty( $elements ) ) {
			return new WP_Error( 'no_elementor_data', 'Page has no Elementor data.', array( 'status' => 400 ) );
		}

		// Recursively find element by ID.
		$found = $this->find_element_by_id( $elements, $element_id );
		if ( ! $found ) {
			return new WP_Error( 'element_not_found', sprintf( 'Element with ID "%s" not found on page %d.', $element_id, $page_id ), array( 'status' => 404 ) );
		}

		if ( empty( $title ) ) {
			$title = sprintf( 'Section from Page %d', $page_id );
		}

		// Determine template type based on elType.
		$template_type = 'section';
		if ( isset( $found['elType'] ) && 'container' === $found['elType'] ) {
			$template_type = 'container';
		}

		// Create the template post.
		$template_id = wp_insert_post( array(
			'post_title'  => sanitize_text_field( $title ),
			'post_status' => 'publish',
			'post_type'   => 'elementor_library',
		) );

		if ( is_wp_error( $template_id ) ) {
			return $template_id;
		}

		// Set template meta.
		update_post_meta( $template_id, '_elementor_template_type', $template_type );
		update_post_meta( $template_id, '_elementor_edit_mode', 'builder' );

		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			update_post_meta( $template_id, '_elementor_version', ELEMENTOR_VERSION );
		}

		// Save the element data (wrapped in array).
		$template_data = array( $found );
		$save_result   = $this->save_elementor_data( $template_id, $template_data );
		if ( is_wp_error( $save_result ) ) {
			return $save_result;
		}

		// Set taxonomy term for Elementor library type.
		wp_set_object_terms( $template_id, $template_type, 'elementor_library_type' );

		return array(
			'template_id'   => $template_id,
			'title'         => $title,
			'template_type' => $template_type,
			'source_page'   => $page_id,
			'element_id'    => $element_id,
			'edit_url'      => admin_url( "post.php?post={$template_id}&action=elementor" ),
			'next_step'     => 'Use wp_list_elementor_templates to verify, or wp_apply_elementor_template to apply to another page.',
		);
	}

	/**
	 * Recursively find an element by ID in Elementor data.
	 *
	 * @param array  $elements   Elements array.
	 * @param string $element_id Target element ID.
	 * @return array|null Found element or null.
	 */
	private function find_element_by_id( $elements, $element_id ) {
		foreach ( $elements as $element ) {
			if ( isset( $element['id'] ) && $element['id'] === $element_id ) {
				return $element;
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$found = $this->find_element_by_id( $element['elements'], $element_id );
				if ( $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	/**
	 * Generate an element ID Elementor Pro's AJAX endpoints accept (#842).
	 *
	 * @return string Seven lowercase alphanumerics.
	 */
	private function generate_element_id() {
		return Mcpwp_Elementor_Element_Ids::generate();
	}
}
