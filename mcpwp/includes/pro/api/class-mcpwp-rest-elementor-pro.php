<?php
/**
 * Elementor Pro REST API Controller
 *
 * @package MCPWP_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__, 2 ) . '/class-mcpwp-activator.php';
require_once dirname( __DIR__, 2 ) . '/core/class-mcpwp-encryption.php';
require_once dirname( __DIR__, 2 ) . '/core/class-mcpwp-custom-code-cache-entry.php';

/**
 * REST API controller for Elementor Pro features.
 *
 * Provides endpoints for templates, landing pages, cloning, and widgets.
 */
class Mcpwp_REST_Elementor_Pro extends Mcpwp_REST_API {
	/**
	 * Elementor Pro Custom Code post type.
	 *
	 * Elementor Pro registers this CPT for Custom Code snippets.
	 *
	 * @var string
	 */
	private $custom_code_cpt = 'elementor_snippet';

	/** Quarantine reporting belongs only to authorized Custom Code responses. */
	private function custom_code_success_response( $data, $status = 200, $read_report = false ) {
		if ( is_array( $data ) && $this->custom_code_admin_reporting_allowed() ) {
			$data = Mcpwp_Custom_Code_Conditions::annotate_response( $data, $read_report );
		}
		return parent::success_response( $data, $status );
	}

	private function custom_code_admin_reporting_allowed() {
		$record = $this->get_current_api_key_record();
		return is_array( $record ) && $this->key_has_scope( $record, 'admin' );
	}

	/**
	 * Elementor Pro handler.
	 *
	 * @var Mcpwp_Elementor_Pro
	 */
	private $elementor_pro;

	/**
	 * Constructor.
	 *
	 * @param Mcpwp_Elementor_Pro $elementor_pro Elementor Pro handler.
	 */
	public function __construct( $elementor_pro ) {
		$this->elementor_pro = $elementor_pro;
	}

	/**
	 * Authenticate, then wire the #1032 template-reference gate into
	 * Mcpwp_Elementor_Pro. Mirrors Mcpwp_REST_Elementor::check_permission()
	 * — every save_elementor_data() call inside Mcpwp_Elementor_Pro
	 * (create/update template, apply template/archetype/part, landing page,
	 * save-section-as-template) and clone_page() (which bypasses that
	 * method entirely) share this ONE injected callable.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function check_permission( $request ) {
		$result = parent::check_permission( $request );

		if ( true === $result ) {
			$this->elementor_pro->set_template_ref_gate( $this->template_ref_gate() );
		}

		return $result;
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		// Templates.
		register_rest_route(
			$this->namespace,
			'/elementor/templates',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_templates' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_template' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Single template.
		register_rest_route(
			$this->namespace,
			'/elementor/templates/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_template' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_template' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_template' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Apply template to page.
		register_rest_route(
			$this->namespace,
			'/elementor/templates/(?P<id>\d+)/apply',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'apply_template' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/elementor/archetypes',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_archetypes' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_archetype' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/elementor/archetypes/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_archetype' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_archetype' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/elementor/archetypes/(?P<id>\d+)/apply',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'apply_archetype' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Reusable parts library.
		register_rest_route(
			$this->namespace,
			'/elementor/parts',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_parts' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_part' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/elementor/parts/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_part' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_part' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/elementor/parts/(?P<id>\d+)/apply',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'apply_part' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/elementor/parts/from-section',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_part_from_section' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Clone page.
		register_rest_route(
			$this->namespace,
			'/elementor/clone',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'clone_page' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => $this->get_status_targeting_write_args(),
			)
		);

		// Landing page.
		register_rest_route(
			$this->namespace,
			'/elementor/landing-page',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_landing_page' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => $this->get_status_targeting_write_args(),
			)
		);


		// Globals.
		register_rest_route(
			$this->namespace,
			'/elementor/globals',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_globals' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'set_globals' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		if ( ! defined( 'MCPWP_WPORG_BUILD' ) ) {
			// Elementor Pro Custom Code (snippets) — not available in WP.org build.
			register_rest_route(
				$this->namespace,
				'/elementor/custom-code',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this, 'list_custom_code' ),
						'permission_callback' => array( $this, 'check_permission' ),
						'args'                => array_merge(
							$this->get_pagination_args(),
							array(
								'status' => array(
									'description' => __( 'Filter by post status: publish, draft, or any.', 'mcpwp' ),
									'type'        => 'string',
									'default'     => 'any',
								),
								'search' => array(
									'description' => __( 'Search by snippet title/content.', 'mcpwp' ),
									'type'        => 'string',
								),
							)
						),
					),
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this, 'create_custom_code' ),
						'permission_callback' => array( $this, 'check_permission' ),
						'args'                => $this->get_custom_code_write_args( true ),
					),
				)
			);

			register_rest_route(
				$this->namespace,
				'/elementor/custom-code/(?P<id>\\d+)',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this, 'get_custom_code' ),
						'permission_callback' => array( $this, 'check_permission' ),
					),
					array(
						'methods'             => WP_REST_Server::EDITABLE,
						'callback'            => array( $this, 'update_custom_code' ),
						'permission_callback' => array( $this, 'check_permission' ),
						'args'                => $this->get_custom_code_write_args( false ),
					),
				)
			);

			register_rest_route(
				$this->namespace,
				'/elementor/custom-code/(?P<id>\\d+)/disable',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'disable_custom_code' ),
					'permission_callback' => array( $this, 'check_permission' ),
				)
			);

			register_rest_route(
				$this->namespace,
				'/elementor/custom-code/(?P<id>\\d+)/enable',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'enable_custom_code' ),
					'permission_callback' => array( $this, 'check_permission' ),
				)
			);

			register_rest_route(
				$this->namespace,
				'/elementor/custom-code/(?P<id>\\d+)/sanitize',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'sanitize_custom_code' ),
					'permission_callback' => array( $this, 'check_permission' ),
				)
			);
		} // end if ( ! defined( 'MCPWP_WPORG_BUILD' ) )

		// Build page from section blueprints.
		register_rest_route(
			$this->namespace,
			'/elementor/build-page',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'build_page' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => $this->get_build_page_write_args(),
			)
		);

		// Blueprint catalog.
		register_rest_route(
			$this->namespace,
			'/elementor/blueprints',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_blueprints' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Single blueprint builder.
		register_rest_route(
			$this->namespace,
			'/elementor/blueprints/build',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'get_blueprint' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Save section as template.
		register_rest_route(
			$this->namespace,
			'/elementor/save-section-as-template',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'save_section_as_template' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Get write arguments for a route whose handler sets post_status from a
	 * caller-supplied 'status' (clone_page(), create_landing_page()).
	 *
	 * RC 3.12.1 adversarial review, BLOCK: /elementor/clone and
	 * /elementor/landing-page registered NO 'args' schema at all, so
	 * WordPress's has_valid_params() never ran before the callback — the
	 * ONLY thing standing between a write-scoped key and a published page
	 * was get_required_scope_for_request()'s own string comparison in
	 * trait-mcpwp-api-auth.php, which a padded value like ' publish' could
	 * defeat (see param_value_targets_admin_scope()'s docblock there).
	 * Both handlers (Mcpwp_Elementor_Pro::clone_page() /
	 * create_landing_page()) normalize with sanitize_text_field() and
	 * default to 'draft'. Declaring 'enum' here makes WordPress itself
	 * reject an out-of-enum value with rest_invalid_param before the gate
	 * or the handler ever sees it — the same protection /posts, /pages, and
	 * WooCommerce products already had by construction. Mirrors the
	 * draft/pending/private/publish set those routes already declare.
	 *
	 * @return array
	 */
	private function get_status_targeting_write_args() {
		return array(
			'status' => array(
				'type'        => 'string',
				'enum'        => array( 'draft', 'pending', 'private', 'publish' ),
				'description' => __( 'Post status for the resulting page.', 'mcpwp' ),
			),
		);
	}

	/**
	 * Get write arguments for /elementor/build-page.
	 *
	 * RC 3.12.1 adversarial review, Requirement 1: Mcpwp_Page_Builder::build()
	 * is schema-less like clone_page()/create_landing_page() above, but its
	 * own status handling — a strict in_array( $status, [...], true ) with
	 * no trimming — happens to fall back to 'draft' for any value that is
	 * not an exact byte-for-byte 'draft'/'publish'/'private', so a padded
	 * value like ' publish' was never actually exploitable here (see the
	 * report's WARN note on this route). That safety is accidental, not
	 * structural: the absent schema is still the root cause this fix
	 * addresses network-wide, so this route gets the same 'enum' treatment
	 * as the four routes that WERE exploitable — matching the handler's own
	 * accepted set exactly (no 'pending'; Mcpwp_Page_Builder::build() does
	 * not recognize it).
	 *
	 * @return array
	 */
	private function get_build_page_write_args() {
		return array(
			'status' => array(
				'type'        => 'string',
				'enum'        => array( 'draft', 'private', 'publish' ),
				'description' => __( 'Post status for the built page.', 'mcpwp' ),
			),
		);
	}

	/**
	 * List Elementor Pro Custom Code snippets.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function list_custom_code( $request ) {
		$args = $this->sanitize_query_args(
			array(
				'per_page' => $request->get_param( 'per_page' ) ?: 50,
				'page'     => $request->get_param( 'page' ) ?: 1,
				'status'   => $request->get_param( 'status' ) ?: 'any',
				'search'   => $request->get_param( 'search' ),
			)
		);

		$args['post_type']      = $this->custom_code_cpt;
		$args['posts_per_page'] = isset( $args['posts_per_page'] ) ? $args['posts_per_page'] : 50;
		$args['paged']          = isset( $args['paged'] ) ? $args['paged'] : 1;

		$query = new WP_Query( $args );

		$snippets = array();
		foreach ( $query->posts as $post ) {
			$snippets[] = $this->format_custom_code_snippet( $post );
		}

		$this->log_activity( 'list_elementor_custom_code', $request, array( 'count' => count( $snippets ) ) );

		return $this->custom_code_success_response(
			array(
				'snippets'  => $snippets,
				'total'     => (int) $query->found_posts,
				'page'      => (int) $args['paged'],
				'per_page'  => (int) $args['posts_per_page'],
				'post_type' => $this->custom_code_cpt,
			),
			200,
			true
		);
	}

	/**
	 * Create an Elementor Pro Custom Code snippet.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function create_custom_code( $request ) {
		$prepared = $this->prepare_custom_code_payload( $request, true );
		if ( is_wp_error( $prepared ) ) {
			$this->log_activity( 'create_elementor_custom_code', $request, null, 400 );
			return $prepared;
		}

		if ( ! empty( $prepared['dry_run'] ) ) {
			return $this->custom_code_success_response(
				array(
					'dry_run'  => true,
					'valid'    => true,
					'payload'  => $this->redact_custom_code_payload( $prepared ),
					'message'  => __( 'Custom Code payload is valid. Nothing was saved because dry_run=true.', 'mcpwp' ),
				)
			);
		}

		return $this->create_custom_code_prepared( $request, $prepared );
	}

	private function create_custom_code_prepared( $request, $prepared ) {
		$post_id = wp_insert_post(
			array(
				'post_type'     => $this->custom_code_cpt,
				'post_title'    => $prepared['title'],
				'post_content'  => $prepared['code'],
				'post_status'   => 'draft',
				// Custom Code snippets never use a page template. Passing
				// 'default' explicitly (rather than leaving the key unset)
				// keeps wp_insert_post()'s own page-template validation a
				// guaranteed no-op — see the identical guard in
				// update_custom_code() and #965.
				'page_template' => 'default',
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			$status = $this->get_wp_error_status( $post_id );
			$this->log_activity( 'create_elementor_custom_code', $request, null, $status );
			return $this->error_response_from_wp_error( $post_id );
		}

		if ( class_exists( 'Mcpwp_Action_Log' ) ) {
			Mcpwp_Action_Log::capture_custom_code_create( $post_id );
		}
		$saved = $this->save_custom_code_meta( $post_id, $prepared );
		if ( is_wp_error( $saved ) ) {
			$this->log_activity( 'create_elementor_custom_code', $request, null, $this->get_wp_error_status( $saved ) );
			return $saved;
		}

		// Conditions and source must be ready before the snippet can run.
		if ( 'publish' === $prepared['status'] ) {
			$published = wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish', 'page_template' => 'default' ), true );
			if ( is_wp_error( $published ) ) {
				return $published;
			}
			$synced = $this->sync_custom_code_conditions_cache( $post_id );
			if ( is_wp_error( $synced ) ) {
				return $synced;
			}
		}

		$data = array(
			'id'      => (int) $post_id,
			'snippet' => $this->format_custom_code_snippet( get_post( $post_id ), true, $prepared['code'] ),
		);

		$this->log_activity( 'create_elementor_custom_code', $request, $this->redact_custom_code_payload( $data ) );

		return $this->custom_code_success_response( $data, 201 );
	}

	/**
	 * Get a single Elementor Pro Custom Code snippet with readback.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_custom_code( $request ) {
		$post = $this->get_custom_code_post( $request->get_param( 'id' ) );
		if ( is_wp_error( $post ) ) {
			$this->log_activity( 'get_elementor_custom_code', $request, null, 404 );
			return $post;
		}

		$data = array(
			'snippet' => $this->format_custom_code_snippet( $post, true ),
		);

		$this->log_activity( 'get_elementor_custom_code', $request, array( 'id' => (int) $post->ID ) );

		return $this->custom_code_success_response( $data, 200, true );
	}

	/**
	 * Update an Elementor Pro Custom Code snippet.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function update_custom_code( $request ) {
		$post = $this->get_custom_code_post( $request->get_param( 'id' ) );
		if ( is_wp_error( $post ) ) {
			$this->log_activity( 'update_elementor_custom_code', $request, null, 404 );
			return $post;
		}

		$prepared = $this->prepare_custom_code_payload( $request, false );
		if ( is_wp_error( $prepared ) ) {
			$this->log_activity( 'update_elementor_custom_code', $request, null, 400 );
			return $prepared;
		}

		if ( ! empty( $prepared['dry_run'] ) ) {
			// Report what the real save would actually apply, not the raw
			// input. status/location/priority/conditions are omitted from
			// $prepared entirely when the caller didn't send them (see
			// get_custom_code_write_args()), so without this the dry-run
			// payload could look like a change occurred (or, before #965,
			// misreport a fabricated "draft" default) when the real save
			// leaves that field untouched. conditions was the one field this
			// missed (#974): an update that omitted it reported no
			// `conditions` key at all instead of the snippet's real,
			// unchanged conditions, which is the same "looks like nothing
			// would happen here" gap the other three fields were fixed for.
			$effective = $prepared;
			if ( ! isset( $effective['status'] ) ) {
				$effective['status'] = $post->post_status;
			}
			if ( ! isset( $effective['location'] ) ) {
				$effective['location'] = $this->public_custom_code_location( (string) ( get_post_meta( $post->ID, '_elementor_location', true ) ?: 'elementor_head' ) );
			}
			if ( ! isset( $effective['priority'] ) ) {
				$effective['priority'] = (int) ( get_post_meta( $post->ID, '_elementor_priority', true ) ?: 1 );
			}
			if ( ! isset( $effective['conditions'] ) ) {
				$effective['conditions'] = $this->get_custom_code_conditions( $post->ID );
			}

			return $this->custom_code_success_response(
				array(
					'dry_run' => true,
					'valid'   => true,
					'id'      => (int) $post->ID,
					'payload' => $this->redact_custom_code_payload( $effective ),
					'message' => __( 'Custom Code update payload is valid. Nothing was saved because dry_run=true.', 'mcpwp' ),
				)
			);
		}

		return $this->update_custom_code_prepared( $request, $post, $prepared );
	}

	private function update_custom_code_prepared( $request, $post, $prepared ) {
		$post_update = array(
			'ID'            => (int) $post->ID,
			// wp_update_post() fetches the full existing post row and merges
			// it under the caller's array before delegating to
			// wp_insert_post() — and WP_Post exposes a virtual 'page_template'
			// property read straight from the _wp_page_template postmeta. A
			// title-only (or any partial) update therefore silently carries
			// forward whatever template slug is stored there, even though
			// this route never asks for one. If that slug isn't registered
			// by the active theme (stale from a prior theme, or simply never
			// meaningful for a snippet post), wp_insert_post()'s own
			// page-template validation rejects the ENTIRE update with
			// "Invalid page template." Custom Code snippets never use a page
			// template, so we always pin it to 'default' — the one value the
			// validation short-circuits as always-valid — which both
			// prevents the failure and self-heals a previously-stale value.
			// See #965.
			'page_template' => 'default',
		);
		if ( isset( $prepared['title'] ) ) {
			$post_update['post_title'] = $prepared['title'];
		}
		if ( array_key_exists( 'code', $prepared ) ) {
			$post_update['post_content'] = $prepared['code'];
		}
		if ( isset( $prepared['status'] ) ) {
			$post_update['post_status'] = $prepared['status'];
		}

		// Keep the existing post fields intact if Pro refuses conditions.
		$saved = $this->save_custom_code_meta( (int) $post->ID, $prepared );
		if ( is_wp_error( $saved ) ) {
			$this->log_activity( 'update_elementor_custom_code', $request, null, $this->get_wp_error_status( $saved ) );
			return $saved;
		}

		$updated = wp_update_post( $post_update, true );
		if ( is_wp_error( $updated ) ) {
			$status = $this->get_wp_error_status( $updated );
			$this->log_activity( 'update_elementor_custom_code', $request, null, $status );
			return $this->error_response_from_wp_error( $updated );
		}


		$synced = $this->sync_custom_code_conditions_cache( (int) $post->ID );
		if ( is_wp_error( $synced ) ) {
			return $synced;
		}

		$data = array(
			'id'      => (int) $post->ID,
			'snippet' => $this->format_custom_code_snippet(
				get_post( $post->ID ),
				true,
				array_key_exists( 'code', $prepared ) ? $prepared['code'] : null
			),
		);

		$this->log_activity( 'update_elementor_custom_code', $request, $this->redact_custom_code_payload( $data ) );

		return $this->custom_code_success_response( $data );
	}

	/**
	 * Disable a Custom Code snippet (set to draft).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function disable_custom_code( $request ) {
		return $this->set_custom_code_status( $request, 'draft' );
	}

	/**
	 * Enable a Custom Code snippet (set to publish).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function enable_custom_code( $request ) {
		return $this->set_custom_code_status( $request, 'publish' );
	}

	/**
	 * Sanitize a Custom Code snippet by stripping invalid wrapper tags.
	 *
	 * Scans all string post meta values and removes <html>/<head>/<body> tags.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function sanitize_custom_code( $request ) {
		$id   = absint( $request->get_param( 'id' ) );
		$post = get_post( $id );

		if ( ! $post || $this->custom_code_cpt !== $post->post_type ) {
			$this->log_activity( 'sanitize_elementor_custom_code', $request, null, 404 );
			return $this->error_response( 'not_found', __( 'Custom Code snippet not found.', 'mcpwp' ), 404 );
		}

		$meta    = get_post_meta( $id );
		$changed = 0;
		$matches = array();

		foreach ( $meta as $key => $values ) {
			if ( ! is_array( $values ) ) {
				continue;
			}

			foreach ( $values as $value ) {
				if ( ! is_string( $value ) || '' === $value ) {
					continue;
				}

				if ( ! $this->contains_wrapper_html_tags( $value ) ) {
					continue;
				}

				$sanitized = $this->strip_wrapper_html_tags( $value );
				if ( empty( $sanitized['changed'] ) ) {
					continue;
				}

				update_post_meta( $id, $key, $sanitized['content'], $value );
				$changed++;
				$matches[] = $key;
			}
		}

		$result = array(
			'id'                 => $id,
			'changed_meta_count' => $changed,
			'matching_meta_keys' => array_values( array_unique( $matches ) ),
			'snippet'            => $this->format_custom_code_snippet( get_post( $id ) ),
		);

		$this->log_activity( 'sanitize_elementor_custom_code', $request, $result );

		return $this->custom_code_success_response( $result );
	}

	/**
	 * Set the post status of a snippet.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @param string          $status Desired status.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	private function set_custom_code_status( $request, $status ) {
		$id   = absint( $request->get_param( 'id' ) );
		$post = get_post( $id );

		if ( ! $post || $this->custom_code_cpt !== $post->post_type ) {
			$this->log_activity( 'set_elementor_custom_code_status', $request, null, 404 );
			return $this->error_response( 'not_found', __( 'Custom Code snippet not found.', 'mcpwp' ), 404 );
		}

		$status = $this->normalize_custom_code_status( $status );
		if ( is_wp_error( $status ) ) {
			// Unreachable from either registered route today — both
			// enable_custom_code()/disable_custom_code() pass a hardcoded
			// literal — but this method takes $status as a parameter, and a
			// future caller passing something else through it must get the
			// same refusal prepare_custom_code_payload() gives, not a silent
			// coercion to draft (#974 N2).
			$this->log_activity( 'set_elementor_custom_code_status', $request, null, 400 );
			return $this->error_response(
				$status->get_error_code(),
				$status->get_error_message(),
				(int) ( $status->get_error_data()['status'] ?? 400 )
			);
		}

		$update = wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => $status,
			),
			true
		);

		if ( is_wp_error( $update ) ) {
			$status = $this->get_wp_error_status( $update );
			$this->log_activity( 'set_elementor_custom_code_status', $request, null, $status );
			return $this->error_response_from_wp_error( $update );
		}
		// #974/#976 review W1: enable_custom_code()/disable_custom_code()
		// are a status-only change that never runs through
		// save_custom_code_meta() at all — without this, a snippet created
		// as draft (whose cache entry save_custom_code_conditions()'s
		// status-filtered regenerate() never wrote in the first place) and
		// later enabled here would stay permanently absent from Elementor
		// Pro's real conditions cache and never render.
		$synced = $this->sync_custom_code_conditions_cache( $id );
		if ( is_wp_error( $synced ) ) {
			return $synced;
		}

		$data = array(
			'id'      => $id,
			'status'  => $status,
			'snippet' => $this->format_custom_code_snippet( get_post( $id ) ),
		);

		$this->log_activity( 'set_elementor_custom_code_status', $request, $data );

		return $this->custom_code_success_response( $data );
	}

	/**
	 * REST args for Custom Code create/update.
	 *
	 * @param bool $creating Whether this is the create route.
	 * @return array Args schema.
	 */
	private function get_custom_code_write_args( $creating ) {
		$args = array(
			'title'      => array(
				'description' => __( 'Snippet title.', 'mcpwp' ),
				'type'        => 'string',
				'required'    => (bool) $creating,
			),
			'code'       => array(
				'description' => __( 'Raw HTML, CSS, or JavaScript code. PHP is not supported by Elementor Custom Code.', 'mcpwp' ),
				'type'        => 'string',
				'required'    => (bool) $creating,
			),
			'content'    => array(
				'description' => __( 'Alias for code, for compatibility with generic post tools.', 'mcpwp' ),
				'type'        => 'string',
			),
			'location'   => array(
				'description' => __( 'Injection location: head, body_start, or body_end. Any other value is rejected — it will not be silently coerced.', 'mcpwp' ),
				'type'        => 'string',
			),
			'priority'   => array(
				'description' => __( 'Print order among snippets sharing a location, 1-10 (lower prints first). Matches Elementor Pro\'s own Custom Code priority field.', 'mcpwp' ),
				'type'        => 'integer',
			),
			'status'     => array(
				'description' => __( 'Post status: draft or publish.', 'mcpwp' ),
				'type'        => 'string',
			),
			'conditions' => array(
				'description' => __( 'Elementor display conditions. Default is include/general (entire site). Example: [{"type":"include","name":"general"}].', 'mcpwp' ),
				'type'        => 'array',
			),
			'dry_run'    => array(
				'description' => __( 'Validate and normalize without saving.', 'mcpwp' ),
				'type'        => 'boolean',
				'default'     => false,
			),
		);

		// Defaults belong to CREATE only. The REST dispatcher back-fills a
		// registered 'default' into the request whenever the caller omits
		// the param — on the update route that indistinguishably looks like
		// an explicit value, so status/location/priority were being reset
		// (e.g. status -> draft) on every update that didn't repeat them.
		// See #965.
		if ( $creating ) {
			$args['location']['default'] = 'head';
			$args['priority']['default']  = 1;
			$args['status']['default']    = 'draft';
		}

		return $args;
	}

	/**
	 * Prepare Custom Code request payload.
	 *
	 * @param WP_REST_Request $request  Request object.
	 * @param bool            $creating Whether this is a create request.
	 * @return array|WP_Error Prepared payload or error.
	 */
	private function prepare_custom_code_payload( $request, $creating ) {
		if ( function_exists( 'post_type_exists' ) && ! post_type_exists( $this->custom_code_cpt ) ) {
			return $this->error_response(
				'elementor_custom_code_unavailable',
				__( 'Elementor Pro Custom Code is unavailable because the elementor_snippet post type is not registered.', 'mcpwp' ),
				400
			);
		}

		$payload = array(
			'dry_run' => (bool) $request->get_param( 'dry_run' ),
		);

		$title = $request->get_param( 'title' );
		if ( null !== $title ) {
			$title = sanitize_text_field( $title );
			if ( '' !== $title ) {
				$payload['title'] = $title;
			}
		}

		if ( $creating && empty( $payload['title'] ) ) {
			return $this->error_response( 'missing_title', __( 'Custom Code title is required.', 'mcpwp' ), 400 );
		}

		$raw_code = $request->get_param( 'code' );
		if ( null === $raw_code ) {
			$raw_code = $request->get_param( 'content' );
		}

		if ( null !== $raw_code ) {
			// Before anything else touches it: a runtime cap standing in for
			// the `maxLength` this route's schema cannot actually enforce
			// (see CUSTOM_CODE_MAX_LENGTH). Checked on the raw string, ahead
			// of normalize_custom_code_content() and the wp_kses_post()
			// diff-check both, so an oversized payload never reaches either.
			if ( is_string( $raw_code ) && strlen( $raw_code ) > self::CUSTOM_CODE_MAX_LENGTH ) {
				return $this->error_response(
					'code_too_large',
					sprintf(
						/* translators: %d: maximum size in bytes */
						__( 'Custom Code is limited to %d bytes.', 'mcpwp' ),
						self::CUSTOM_CODE_MAX_LENGTH
					),
					400
				);
			}

			$code       = $this->normalize_custom_code_content( $raw_code );
			$payload['code'] = $code['content'];
			$payload['wrapper_tags_removed'] = (bool) $code['changed'];
		}

		if ( $creating && ( ! array_key_exists( 'code', $payload ) || '' === trim( $payload['code'] ) ) ) {
			return $this->error_response( 'missing_code', __( 'Custom Code raw code is required.', 'mcpwp' ), 400 );
		}

		if ( array_key_exists( 'code', $payload ) ) {
			$blocked = $this->custom_code_unfiltered_html_refusal( $payload['code'] );

			if ( is_wp_error( $blocked ) ) {
				return $this->error_response(
					$blocked->get_error_code(),
					$blocked->get_error_message(),
					(int) ( $blocked->get_error_data()['status'] ?? 403 )
				);
			}
		}

		if ( null !== $request->get_param( 'location' ) ) {
			$location = $this->normalize_custom_code_location( $request->get_param( 'location' ) );
			if ( is_wp_error( $location ) ) {
				return $this->error_response(
					$location->get_error_code(),
					$location->get_error_message(),
					(int) ( $location->get_error_data()['status'] ?? 400 )
				);
			}
			$payload['location'] = $location;
		} elseif ( $creating ) {
			$payload['location'] = 'head';
		}

		if ( null !== $request->get_param( 'priority' ) ) {
			// intval(), not absint() (#974 N3): absint() takes the absolute
			// value FIRST, so a negative priority like -5 became 5 — a
			// value inside the valid range, silently the opposite of what
			// clamping means. Casting with intval() keeps the sign so a
			// negative value clamps DOWN to the floor (1), matching what
			// "clamp to 1-10" actually promises.
			$payload['priority'] = max( 1, min( 10, (int) $request->get_param( 'priority' ) ) );
		} elseif ( $creating ) {
			$payload['priority'] = 1;
		}

		if ( null !== $request->get_param( 'status' ) ) {
			$status = $this->normalize_custom_code_status( $request->get_param( 'status' ) );
			if ( is_wp_error( $status ) ) {
				return $this->error_response(
					$status->get_error_code(),
					$status->get_error_message(),
					(int) ( $status->get_error_data()['status'] ?? 400 )
				);
			}
			$payload['status'] = $status;
		} elseif ( $creating ) {
			$payload['status'] = 'draft';
		}

		$conditions = $request->get_param( 'conditions' );
		if ( null !== $conditions ) {
			if ( ! is_array( $conditions ) ) {
				return $this->error_response( 'invalid_conditions', __( 'conditions must be an array.', 'mcpwp' ), 400 );
			}
			$converted = Mcpwp_Custom_Code_Conditions::convert( $conditions );
			if ( is_wp_error( $converted ) ) {
				return $this->error_response( 'invalid_conditions', __( 'Every display condition must be convertible without changing its scope.', 'mcpwp' ), 400 );
			}
			$payload['conditions'] = $this->normalize_custom_code_conditions( $converted );
		} elseif ( $creating ) {
			$payload['conditions'] = $this->normalize_custom_code_conditions( array() );
		}

		return $payload;
	}

	/**
	 * Normalize raw Custom Code content without stripping script/style tags.
	 *
	 * @param string $content Raw content.
	 * @return array Normalized content and change flag.
	 */
	private function normalize_custom_code_content( $content ) {
		$content = (string) wp_unslash( $content );
		if ( $this->contains_wrapper_html_tags( $content ) ) {
			return $this->strip_wrapper_html_tags( $content );
		}

		return array(
			'content' => $content,
			'changed' => false,
		);
	}

	/**
	 * Public Custom Code location value => Elementor Pro's real internal
	 * `_elementor_location` postmeta value.
	 *
	 * Verified against Elementor Pro's own source (4.2.x, elementor-snippet CPT):
	 * - modules/custom-code/custom-code-metabox.php: Custom_Code_Metabox::
	 *   OPTION_LOCATION_HEAD = 'elementor_head', OPTION_LOCATION_BODY_START =
	 *   'elementor_body_start', OPTION_LOCATION_BODY_END = 'elementor_body_end'.
	 * - modules/custom-code/module.php frontend_actions(): wp_head/wp_body_open/
	 *   wp_footer each call print_snippets() with one of those three literal
	 *   strings, and get_snippets_by_location() matches `_elementor_location`
	 *   postmeta against it with a plain meta_query equality check — no other
	 *   string, including our own short-form public values, is recognized.
	 *
	 * @return array<string,string> Public value => Elementor internal value.
	 */
	private function get_custom_code_location_map() {
		return array(
			'head'       => 'elementor_head',
			'body_start' => 'elementor_body_start',
			'body_end'   => 'elementor_body_end',
		);
	}

	/**
	 * Normalize + validate a Custom Code post status.
	 *
	 * The shared Mcpwp_Trait_Sanitization::validate_post_status() this used
	 * to call silently coerces anything not in the allowed list to 'draft' —
	 * fine for a caller that means "give me some safe default", wrong here:
	 * a typo like 'enabled' or 'Published' unpublished a live snippet with no
	 * error at all (#974 N2), the same class of defect #871 already fixed
	 * for location (see normalize_custom_code_location() above). Rejecting
	 * outright, the same way that method does, is the fix.
	 *
	 * Deliberately NOT `sanitize_key()` (#974/#976 review NIT-a): that
	 * function is built for slugs, so it strips ANY character outside
	 * a-z0-9_- with a regex — including internal punctuation and whitespace
	 * — before the allow-list comparison ever runs. `" publish!"` survives
	 * that as `"publish"` and would have been silently ACCEPTED, exactly
	 * the "looks clean after all" trap this method exists to close for the
	 * more obvious typos. Only `trim()` + `strtolower()` run before the
	 * exact match, so anything that is not byte-for-byte (case-insensitive)
	 * `publish` or `draft` once outer whitespace is gone is refused.
	 *
	 * @param string $status Raw status.
	 * @return string|WP_Error 'publish' or 'draft', or error for anything else.
	 */
	private function normalize_custom_code_status( $status ) {
		$allowed    = array( 'publish', 'draft' );
		$normalized = is_scalar( $status ) ? strtolower( trim( (string) $status ) ) : '';

		if ( in_array( $normalized, $allowed, true ) ) {
			return $normalized;
		}

		return new WP_Error(
			'invalid_status',
			sprintf(
				/* translators: 1: the invalid value received, 2: comma-separated list of valid status values. */
				__( 'Invalid Custom Code status "%1$s". Use one of: %2$s.', 'mcpwp' ),
				(string) $status,
				implode( ', ', $allowed )
			),
			array( 'status' => 400 )
		);
	}

	/**
	 * Normalize + validate a Custom Code injection location.
	 *
	 * Accepts either our public short-form values (head/body_start/body_end)
	 * or Elementor's own internal values directly, and always returns the
	 * public short-form value (the write path maps it to Elementor's internal
	 * value at the point of persistence; see save_custom_code_meta()).
	 *
	 * An unrecognized value used to be silently coerced to 'head', which let a
	 * typo through as a snippet that looked saved (status: publish, correct
	 * code, no warnings) but never rendered anywhere — the exact defect
	 * reported in GH #871. It is now rejected outright.
	 *
	 * @param string $location Raw location.
	 * @return string|WP_Error Public location value, or error for unknown input.
	 */
	private function normalize_custom_code_location( $location ) {
		$location = sanitize_key( (string) $location );
		$map      = $this->get_custom_code_location_map();

		if ( isset( $map[ $location ] ) ) {
			return $location;
		}

		$public = array_search( $location, $map, true );
		if ( false !== $public ) {
			return $public;
		}

		return new WP_Error(
			'invalid_location',
			sprintf(
				/* translators: 1: the invalid value received, 2: comma-separated list of valid location values. */
				__( 'Invalid Custom Code location "%1$s". Use one of: %2$s.', 'mcpwp' ),
				$location,
				implode( ', ', array_keys( $map ) )
			),
			array( 'status' => 400 )
		);
	}

	/**
	 * Map a stored `_elementor_location` value back to our public contract.
	 *
	 * Handles three cases: the correct internal Elementor value (maps back to
	 * the short-form public value); a pre-fix broken snippet whose meta holds
	 * the public value literally (GH #871 — passed through unchanged, since
	 * that IS its stored value, though the snippet itself will not be
	 * rendering; see the repair path in Mcpwp_Activator::
	 * repair_custom_code_locations()); and anything else (passed through
	 * as-is rather than guessed at).
	 *
	 * @param string $stored Raw `_elementor_location` meta value.
	 * @return string Public location value.
	 */
	private function public_custom_code_location( $stored ) {
		$map    = $this->get_custom_code_location_map();
		$public = array_search( $stored, $map, true );

		if ( false !== $public ) {
			return $public;
		}

		return $stored;
	}

	/**
	 * Normalize Elementor display conditions.
	 *
	 * @param array $conditions Raw conditions.
	 * @return array Normalized conditions.
	 */
	private function normalize_custom_code_conditions( $conditions ) {
		if ( empty( $conditions ) ) {
			return array(
				array(
					'type' => 'include',
					'name' => 'general',
				),
			);
		}

		$normalized = array();
		foreach ( $conditions as $condition ) {
			if ( is_string( $condition ) ) {
				list( $type, $name, $sub_name, $sub_id ) = array_pad( explode( '/', $condition, 4 ), 4, '' );
				$condition = compact( 'type', 'name', 'sub_name', 'sub_id' );
			}
			if ( ! is_array( $condition ) ) {
				continue;
			}

			$item = array(
				'type'     => isset( $condition['type'] ) && 'exclude' === $condition['type'] ? 'exclude' : 'include',
				'name'     => isset( $condition['name'] ) ? sanitize_text_field( $condition['name'] ) : 'general',
				'sub_name' => isset( $condition['sub_name'] ) ? sanitize_text_field( $condition['sub_name'] ) : '',
				'sub_id'   => isset( $condition['sub_id'] ) ? sanitize_text_field( $condition['sub_id'] ) : '',
			);

			if ( '' === $item['name'] ) {
				$item['name'] = 'general';
			}

			$normalized[] = array_filter(
				$item,
				function ( $value ) {
					return '' !== $value;
				}
			);
		}

		return empty( $normalized ) ? $this->normalize_custom_code_conditions( array() ) : $normalized;
	}

	/**
	 * How many Custom Code meta writes are in flight under the scoped grant.
	 *
	 * Depth-counted for the same reason as the Elementor writer's grant (#769):
	 * a nested call must not tear the filter out from under the call that
	 * installed it. Nothing nests today; an edit that starts to would otherwise
	 * silently reopen this with no failing test.
	 *
	 * @var int
	 */
	private static $custom_code_unfiltered_depth = 0;

	/**
	 * The one user the scoped Custom Code grant applies to.
	 *
	 * Captured when the grant is installed, so a capability check performed for
	 * some OTHER user inside the same call cannot pick it up.
	 *
	 * @var int
	 */
	private static $custom_code_unfiltered_user = 0;

	/**
	 * Whether cleanup_custom_code_unfiltered_html_grant_on_shutdown() has
	 * already been registered with register_shutdown_function() in this
	 * process.
	 *
	 * register_shutdown_function() has no unregister — the callback lives
	 * for the rest of the process either way — so this guard is what stops
	 * a long-lived worker (WP-CLI, a rest_do_request() loop; see the
	 * $mcpwp_verdicts precedent in trait-mcpwp-api-auth.php) from
	 * accumulating one registered shutdown callback per Custom Code write.
	 * It is registered at most once per process and is itself idempotent
	 * (a no-op once the grant is already clean), so calling it more than
	 * once would have been harmless — this exists purely to keep PHP's own
	 * shutdown-function list from growing unbounded.
	 *
	 * @var bool
	 */
	private static $custom_code_shutdown_guard_registered = false;

	/**
	 * Upper bound on `code` this controller will run through
	 * code_needs_unfiltered_html()'s wp_kses_post() diff-check.
	 *
	 * The REST arg schema for `code` (get_custom_code_write_args()) has no
	 * `maxLength`, and would not enforce one even if declared: these routes
	 * register their args as plain arrays with no `validate_callback`, so
	 * WP_REST_Request::sanitize_params() never runs rest_validate_request_arg()
	 * against them — a JSON Schema constraint here is documentation only
	 * (RC review LOW finding). Without an actual runtime cap, an arbitrarily
	 * large `code` string reaches wp_kses_post() unbounded on every write.
	 *
	 * `/elementor/custom-code` is admin-scope-gated already, so this is a
	 * footgun (an accidentally huge payload spending needless CPU on the
	 * diff-check) rather than an unauthenticated escalation path — hence a
	 * generous cap rather than a strict one. 2MB is far past any real
	 * Custom Code snippet (these are hand-authored HTML/CSS/JS, not
	 * generated content) while still bounding the diff-check's cost.
	 */
	const CUSTOM_CODE_MAX_LENGTH = 2097152; // 2 MiB.

	/**
	 * Whether this code would be altered by Elementor's own kses pass.
	 *
	 * Elementor registers `_elementor_code` with a sanitize_callback that runs
	 * wp_kses_post() unless the writing user holds `unfiltered_html`
	 * (elementor-pro/modules/custom-code/module.php). update_post_meta() honours
	 * registered sanitize callbacks, so that filter is what strips <script> from
	 * every snippet MCPWP writes — the whole reason GA4/GTM tags never worked.
	 *
	 * Asking whether the pass would CHANGE the content, rather than assuming it
	 * always matters, keeps the scoped grant off the majority of writes: a plain
	 * HTML or text snippet survives kses untouched and needs no capability at all.
	 *
	 * @param string $code Raw snippet code.
	 * @return bool Whether kses would alter it.
	 */
	private function code_needs_unfiltered_html( $code ) {
		if ( ! is_string( $code ) || '' === $code || ! function_exists( 'wp_kses_post' ) ) {
			return false;
		}

		return wp_kses_post( $code ) !== $code;
	}

	/**
	 * Whether this environment could ever grant `unfiltered_html` for a
	 * Custom Code write, regardless of who is asking.
	 *
	 * Two independent conditions make the scoped grant in
	 * write_custom_code_meta() structurally inert rather than merely
	 * user-specific: WordPress core's own `map_meta_cap()` substitutes the
	 * primitive capability actually checked with the sentinel
	 * `'do_not_allow'` in both cases, and no `user_has_cap` filter —
	 * including the one this class installs — can ever satisfy that:
	 *
	 *  - `DISALLOW_UNFILTERED_HTML`: core's comment on this branch says
	 *    "even admins and super admins." #974 BLOCK-1 (adversarial review
	 *    of #990) found this had no check at all: the grant applied,
	 *    Elementor's own sanitize_callback stripped `<script>` from
	 *    `_elementor_code` anyway, and the readback reported
	 *    `code_source:_elementor_code, code_may_be_altered:false` while
	 *    the front end printed bare JS text — a confident wrong answer.
	 *  - Multisite without super admin: the pre-existing check this method
	 *    already made (formerly custom_code_multisite_refusal()).
	 *
	 * @return bool True when a grant here could actually take effect.
	 */
	private function custom_code_environment_can_hold_unfiltered_html() {
		if ( defined( 'DISALLOW_UNFILTERED_HTML' ) && DISALLOW_UNFILTERED_HTML ) {
			return false;
		}

		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			return function_exists( 'is_super_admin' ) && is_super_admin( get_current_user_id() );
		}

		return true;
	}

	/**
	 * Refuse a Custom Code write this environment cannot actually honour.
	 *
	 * Formerly custom_code_multisite_refusal(), covering only multisite.
	 * #974 BLOCK-1 added the `DISALLOW_UNFILTERED_HTML` case: see
	 * custom_code_environment_can_hold_unfiltered_html()'s docblock for why
	 * that one needed its OWN refusal rather than falling through to the
	 * multisite message alone — it is structurally the same failure (a
	 * grant that can never take effect), reached a different way, and a
	 * caller told "multisite" on a single-site install with this constant
	 * set would have no idea what actually happened.
	 *
	 * The refusal is deliberately narrow: it fires only when the code would
	 * actually be altered by kses. A snippet that survives the pass unchanged
	 * needs no capability, so neither condition is an obstacle to writing it
	 * and we do not invent one.
	 *
	 * Refusing beats proceeding. Without a grant that can actually apply,
	 * the snippet would be stored with its <script> silently removed and
	 * would render as an empty comment block — a write reported as
	 * successful that cannot do the one thing it was asked to do. An honest
	 * 403 is the better failure.
	 *
	 * @param string $code Raw snippet code.
	 * @return true|WP_Error True when the write may proceed.
	 */
	private function custom_code_unfiltered_html_refusal( $code ) {
		if ( ! $this->code_needs_unfiltered_html( $code ) ) {
			return true;
		}

		if ( $this->custom_code_environment_can_hold_unfiltered_html() ) {
			return true;
		}

		if ( defined( 'DISALLOW_UNFILTERED_HTML' ) && DISALLOW_UNFILTERED_HTML ) {
			return new WP_Error(
				'unfiltered_html_disallowed',
				__( 'kses would alter this code (it contains markup, or characters such as & or > that would be entity-encoded) unless the writer holds unfiltered_html. This site defines DISALLOW_UNFILTERED_HTML, which WordPress enforces for every user without exception — no capability grant can override it, so MCPWP cannot promise this code will render as submitted. Remove DISALLOW_UNFILTERED_HTML, or use text that survives wp_kses_post() unchanged.', 'mcpwp' ),
				array( 'status' => 403 )
			);
		}

		return new WP_Error(
			'multisite_unfiltered_html_refused',
			__( 'kses would alter this code (it contains markup, or characters such as & or > that would be entity-encoded) unless the writer holds unfiltered_html. On multisite that capability belongs to super admins only, and MCPWP will not grant the API user more than a site administrator holds. Add this snippet as a super admin, or use text that survives wp_kses_post() unchanged.', 'mcpwp' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Grant `unfiltered_html` to the writing user for one Custom Code meta write.
	 *
	 * Narrow by construction: one capability, one user, one meta write, removed
	 * in a `finally` so a Throwable cannot leave it installed.
	 *
	 * @param array     $allcaps All capabilities of the user being tested.
	 * @param string[]  $caps    Required primitive capabilities.
	 * @param array     $args    Arguments of the check.
	 * @param \WP_User  $user    The user being tested.
	 * @return array Capabilities, possibly with the scoped grant added.
	 */
	public static function grant_custom_code_unfiltered_html_cap( $allcaps, $caps = array(), $args = array(), $user = null ) {
		if ( self::$custom_code_unfiltered_depth < 1 || ! is_array( $allcaps ) ) {
			return $allcaps;
		}

		$user_id = ( $user instanceof \WP_User ) ? (int) $user->ID : 0;

		if ( $user_id < 1 || $user_id !== self::$custom_code_unfiltered_user ) {
			return $allcaps;
		}

		$allcaps['unfiltered_html'] = true;

		return $allcaps;
	}

	/**
	 * Write `_elementor_code`, granting `unfiltered_html` only when kses would
	 * otherwise destroy the snippet.
	 *
	 * This is the single place the capability is granted, and it wraps exactly
	 * one update_post_meta() call. `post_content` is written separately by
	 * wp_insert_post()/wp_update_post() and stays under core's content_save_pre
	 * KSES gate — deliberately. Elementor's renderer reads `_elementor_code`
	 * (custom-code/document.php print_content()), never post_content, so the
	 * grant covers the copy that renders and leaves the copy that does not
	 * filtered. The two diverge for a script-bearing snippet, and that is the
	 * intended outcome rather than an oversight.
	 *
	 * What governs whether a caller may write raw markup at all remains the API
	 * key and its scope: `/elementor/custom-code` is gated at ADMIN scope in
	 * get_required_scope_for_request()'s $admin_routes, so a designer-, author-
	 * or editor-scoped key never reaches this method.
	 *
	 * PHP's `finally` does not run on `exit()`/`die()` (RC review WARN
	 * finding). If anything hooked into `update_post_meta()`/
	 * `updated_postmeta` synchronously terminates the request — a security
	 * or WAF plugin calling `wp_die()` on content it considers suspicious is
	 * the realistic case, and script-bearing content is exactly what would
	 * trigger one — the `finally` below never runs and the grant (plus the
	 * depth counter) stays installed. On ordinary PHP-FPM that dies with the
	 * process and is harmless; this codebase specifically does not assume
	 * that, because it also supports a long-lived worker reusing this same
	 * process across requests (see the `$mcpwp_verdicts` memoization note in
	 * trait-mcpwp-api-auth.php: "a long-lived worker (WP-CLI, a
	 * rest_do_request() loop) reuses this instance across requests"), and
	 * every authenticated caller maps onto the same `mcpwp_api_agent`
	 * account — so a leaked grant there would hand that account
	 * `unfiltered_html` for the rest of such a process's life.
	 * ensure_custom_code_shutdown_guard() registers a
	 * `register_shutdown_function()` safety net for exactly that case:
	 * `register_shutdown_function()`, unlike `finally`, does run on
	 * `exit()`/`die()`.
	 *
	 * @param int    $post_id Snippet post ID.
	 * @param string $code    Raw snippet code.
	 */
	private function write_custom_code_meta( $post_id, $code ) {
		$needs_grant = $this->code_needs_unfiltered_html( $code ) && get_current_user_id() > 0;

		if ( ! $needs_grant ) {
			update_post_meta( $post_id, '_elementor_code', $code );

			return;
		}

		self::ensure_custom_code_shutdown_guard();

		if ( 0 === self::$custom_code_unfiltered_depth ) {
			self::$custom_code_unfiltered_user = get_current_user_id();
			add_filter( 'user_has_cap', array( __CLASS__, 'grant_custom_code_unfiltered_html_cap' ), PHP_INT_MAX, 4 );
		}
		++self::$custom_code_unfiltered_depth;

		try {
			update_post_meta( $post_id, '_elementor_code', $code );
		} finally {
			--self::$custom_code_unfiltered_depth;

			if ( 0 === self::$custom_code_unfiltered_depth ) {
				remove_filter( 'user_has_cap', array( __CLASS__, 'grant_custom_code_unfiltered_html_cap' ), PHP_INT_MAX );
				self::$custom_code_unfiltered_user = 0;
			}
		}
	}

	/**
	 * Register the shutdown-time safety net for the Custom Code
	 * `unfiltered_html` grant, at most once per process.
	 *
	 * See write_custom_code_meta()'s docblock for why `finally` alone is not
	 * enough. Guarded by a static flag rather than called unconditionally,
	 * because register_shutdown_function() has no unregister: without the
	 * guard, a long-lived worker processing many Custom Code writes would
	 * register one more shutdown callback per write for the life of the
	 * process. The guard makes registration itself idempotent; the callback
	 * it registers is idempotent too (see
	 * cleanup_custom_code_unfiltered_html_grant_on_shutdown()), so nothing
	 * about calling this more than once — which never happens, but is not
	 * relied upon not to — would be unsafe either.
	 */
	private static function ensure_custom_code_shutdown_guard() {
		if ( self::$custom_code_shutdown_guard_registered || ! function_exists( 'register_shutdown_function' ) ) {
			return;
		}

		self::$custom_code_shutdown_guard_registered = true;

		register_shutdown_function( array( __CLASS__, 'cleanup_custom_code_unfiltered_html_grant_on_shutdown' ) );
	}

	/**
	 * Shutdown-time fallback for write_custom_code_meta()'s `finally`.
	 *
	 * A no-op — checked, not assumed — whenever the grant was already
	 * cleaned up normally (the overwhelmingly common case: `finally` runs on
	 * every ordinary return, error, or caught exception, so by the time
	 * PHP's shutdown sequence reaches this, depth is back to 0). It only
	 * does anything when a write was interrupted by `exit()`/`die()` inside
	 * the grant window and left the filter and depth counter installed.
	 *
	 * Idempotent with the `finally` block by construction: both paths only
	 * ever remove the filter and zero the counters, and remove_filter() on a
	 * filter that is not registered is itself a safe no-op.
	 */
	public static function cleanup_custom_code_unfiltered_html_grant_on_shutdown() {
		if ( self::$custom_code_unfiltered_depth <= 0 ) {
			return;
		}

		remove_filter( 'user_has_cap', array( __CLASS__, 'grant_custom_code_unfiltered_html_cap' ), PHP_INT_MAX );
		self::$custom_code_unfiltered_depth = 0;
		self::$custom_code_unfiltered_user  = 0;
	}

	/**
	 * Persist Elementor custom-code meta.
	 *
	 * Two metas here are load-bearing for Elementor Pro's own renderer, not
	 * just our API's readback, and both were missing before GH #871:
	 *
	 * - `_elementor_location` must hold Elementor's real internal value
	 *   (elementor_head/elementor_body_start/elementor_body_end) — see
	 *   get_custom_code_location_map(). Our public short-form value never
	 *   matched anything Elementor's wp_head/wp_body_open/wp_footer hooks
	 *   query for, so every snippet was created "successfully" and never
	 *   rendered.
	 * - `_elementor_code` is what ElementorPro\Modules\CustomCode\Document::
	 *   print_content() actually echoes (custom-code/document.php: `get_post_meta(
	 *   $this->post->ID, '_elementor_' . Custom_Code_Metabox::FIELD_CODE, true )`).
	 *   This class only ever wrote the code to `post_content`, which that
	 *   renderer never reads — so even a correctly-located snippet rendered
	 *   as an empty comment block. Verified end to end on the local rig
	 *   (wp-rig-wp-dev): the marker only appeared on the live page once both
	 *   `_elementor_location` and `_elementor_code` were corrected.
	 * - `_elementor_priority` matters for a subtler reason: Elementor Pro's
	 *   get_snippets_by_location() runs `WP_Query` with a top-level `meta_key`
	 *   (for `orderby => meta_value_num`) *alongside* an explicit `meta_query`
	 *   for location. WordPress ANDs the implicit clause from that top-level
	 *   `meta_key` with the explicit meta_query, which requires the priority
	 *   row to exist at all — a snippet without it is excluded from the query
	 *   outright, never merely mis-ordered. Always writing a priority avoids
	 *   depending on that WP_Query interaction to happen to still work.
	 *
	 * @param int   $post_id  Snippet post ID.
	 * @param array $payload  Prepared payload.
	 * @return true|WP_Error Verified metadata or a partial-write receipt.
	 */
	private function save_custom_code_meta( $post_id, $payload ) {
		if ( isset( $payload['conditions'] ) ) {
			$result = $this->save_custom_code_conditions( $post_id, $payload['conditions'] );
			update_post_meta(
				$post_id,
				'_mcpwp_custom_code_conditions_engine',
				is_wp_error( $result ) ? 'fallback_meta' : (string) $result
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( isset( $payload['location'] ) ) {
			$map               = $this->get_custom_code_location_map();
			$internal_location = isset( $map[ $payload['location'] ] ) ? $map[ $payload['location'] ] : $payload['location'];
			update_post_meta( $post_id, '_elementor_location', $internal_location );
		}

		if ( array_key_exists( 'code', $payload ) ) {
			$this->write_custom_code_meta( $post_id, $payload['code'] );
		}

		if ( isset( $payload['priority'] ) ) {
			update_post_meta( $post_id, '_elementor_priority', (int) $payload['priority'] );
		}

		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );

		if ( array_key_exists( 'wrapper_tags_removed', $payload ) ) {
			update_post_meta( $post_id, '_mcpwp_wrapper_tags_removed', (bool) $payload['wrapper_tags_removed'] ? '1' : '0' );
		}

		// The caller verifies the display cache after finalizing post status.
		return true;
	}

	/**
	 * Persist the canonical condition strings and verify only this document.
	 * Full-site Pro cache regeneration is reserved for operator rebuilds.
	 *
	 * @param int   $post_id Snippet ID.
	 * @param array $conditions Validated condition maps.
	 * @return string|WP_Error Save engine or recoverable failure receipt.
	 */
	private function save_custom_code_conditions( $post_id, $conditions ) {
		$previous = Mcpwp_Custom_Code_Conditions::raw_conditions( $post_id );
		try {
			$strings = Mcpwp_Custom_Code_Conditions::convert( $conditions );
			if ( is_wp_error( $strings ) ) {
				throw new RuntimeException( $strings->get_error_message() );
			}
			update_post_meta( $post_id, '_elementor_conditions', wp_slash( $strings ) );
			if ( Mcpwp_Custom_Code_Conditions::raw_conditions( $post_id ) !== $strings ) {
				throw new RuntimeException( 'Condition metadata was not persisted.' );
			}
			Mcpwp_Custom_Code_Conditions::clear_quarantine( $post_id );
			return class_exists( '\\ElementorPro\\Modules\\ThemeBuilder\\Module' ) && class_exists( '\\Elementor\\Plugin' ) ? 'mcpwp_conditions_cache_entry' : 'fallback_meta';
		} catch ( \Throwable $exception ) {
			try {
				update_post_meta( $post_id, '_elementor_conditions', wp_slash( $previous ) );
			} catch ( \Throwable $restore_error ) {
				// The partial-write receipt covers an unavailable metadata store.
			}
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'MCPWP Custom Code conditions save failed: ' . $exception->getMessage() );
			return new WP_Error( 'conditions_save_failed', __( 'Display conditions could not be saved. Read the snippet before retrying an update.', 'mcpwp' ), array( 'status' => 500, 'resource_id' => (int) $post_id, 'partial_write' => true ) );
		}
	}

	/**
	 * Rebuild and verify this snippet's cache entry without a global rebuild.
	 * The shared helper merges against the exact stored option bytes and
	 * retries conflicts at most three times with a short jitter.
	 *
	 * @param int $post_id Snippet ID.
	 * @return true|WP_Error Verified cache or partial-write receipt.
	 */
	private function sync_custom_code_conditions_cache( $post_id ) {
		$raw = Mcpwp_Custom_Code_Conditions::raw_conditions( $post_id );
		$conditions = $this->coerce_conditions_to_strings( $raw, $post_id );
		if ( is_wp_error( $conditions ) ) {
			Mcpwp_Custom_Code_Conditions::quarantine( $post_id, $raw );
			return true;
		}
		Mcpwp_Custom_Code_Conditions::clear_quarantine( $post_id );
		if ( ! class_exists( '\\ElementorPro\\Modules\\ThemeBuilder\\Module' ) || ! class_exists( '\\Elementor\\Plugin' ) ) {
			return true;
		}
		if ( 'publish' !== get_post_status( $post_id ) ) {
			return Mcpwp_Custom_Code_Cache_Entry::remove( $post_id );
		}
		$location = get_post_meta( $post_id, '_elementor_location', true );
		$location = is_string( $location ) && '' !== $location ? $location : 'elementor_head';
		return Mcpwp_Custom_Code_Cache_Entry::sync( $post_id, $location, $conditions );
	}

	/**
	 * Use the upgrade repair's serializer at the cache-write boundary too.
	 *
	 * @param array $conditions Stored conditions.
	 * @param int   $post_id    Snippet ID.
	 * @return string[]|WP_Error Complete conditions in Elementor's persisted format.
	 */
	private function coerce_conditions_to_strings( array $conditions, $post_id ) {
		return Mcpwp_Custom_Code_Conditions::convert( $conditions );
	}

	/**
	 * Get a Custom Code post or a typed error.
	 *
	 * @param int $id Post ID.
	 * @return WP_Post|WP_Error Post or error.
	 */
	private function get_custom_code_post( $id ) {
		$id   = absint( $id );
		$post = get_post( $id );

		if ( ! $post || $this->custom_code_cpt !== $post->post_type ) {
			return $this->error_response( 'not_found', __( 'Custom Code snippet not found.', 'mcpwp' ), 404 );
		}

		return $post;
	}

	/**
	 * Read Elementor display conditions for a Custom Code snippet.
	 *
	 * @param int $post_id Snippet post ID.
	 * @return array Conditions.
	 */
	private function get_custom_code_conditions( $post_id ) {
		return $this->normalize_custom_code_conditions( Mcpwp_Custom_Code_Conditions::raw_conditions( $post_id ) );
	}

	/**
	 * Redact raw code from activity logs and dry-run summaries.
	 *
	 * @param array $payload Payload.
	 * @return array Redacted payload.
	 */
	private function redact_custom_code_payload( $payload ) {
		if ( isset( $payload['code'] ) ) {
			$payload['code_length'] = strlen( (string) $payload['code'] );
			unset( $payload['code'] );
		}

		if ( isset( $payload['snippet']['code'] ) ) {
			$payload['snippet']['code_length'] = strlen( (string) $payload['snippet']['code'] );
			unset( $payload['snippet']['code'] );
		}

		return $payload;
	}

	/**
	 * Format a Custom Code snippet post for API response.
	 *
	 * @param WP_Post     $post           Post object.
	 * @param bool        $include_code   Include raw code.
	 * @param string|null $submitted_code The raw code THIS request just
	 *                                    submitted for storage (create/
	 *                                    update only, after normalization —
	 *                                    see prepare_custom_code_payload()),
	 *                                    or null for a pure read (GET/list/
	 *                                    enable/disable) with no submission
	 *                                    to compare against. Passed straight
	 *                                    through to read_custom_code_source()
	 *                                    — see its docblock for how this
	 *                                    changes what `code_may_be_altered`
	 *                                    means (#974 BLOCK-1).
	 * @return array Formatted snippet.
	 */
	private function format_custom_code_snippet( $post, $include_code = false, $submitted_code = null ) {
		$meta = get_post_meta( $post->ID );

		$matching_meta_keys = array();
		foreach ( $meta as $key => $values ) {
			if ( ! is_array( $values ) ) {
				continue;
			}

			foreach ( $values as $value ) {
				if ( is_string( $value ) && $this->contains_wrapper_html_tags( $value ) ) {
					$matching_meta_keys[] = $key;
					break;
				}
			}
		}

		$debug_meta = array();
		foreach ( $meta as $key => $values ) {
			if ( ! is_array( $values ) || empty( $values ) ) {
				continue;
			}

			if ( preg_match( '/(code|snippet|location|condition)/i', (string) $key ) ) {
				$first = $values[0];
				if ( is_string( $first ) ) {
					$debug_meta[ $key ] = substr( $first, 0, 200 );
				} else {
					$debug_meta[ $key ] = $first;
				}
			}
		}

		$code = $this->read_custom_code_source( $post, $submitted_code );

		$data = array(
			'id'                => (int) $post->ID,
			'title'             => (string) $post->post_title,
			'status'            => (string) $post->post_status,
			'modified_gmt'       => (string) $post->post_modified_gmt,
			'location'           => $this->public_custom_code_location( (string) ( get_post_meta( $post->ID, '_elementor_location', true ) ?: 'elementor_head' ) ),
			'priority'           => (int) ( get_post_meta( $post->ID, '_elementor_priority', true ) ?: 1 ),
			'conditions'         => $this->get_custom_code_conditions( $post->ID ),
			'conditions_engine'  => (string) ( get_post_meta( $post->ID, '_mcpwp_custom_code_conditions_engine', true ) ?: 'unknown' ),
			'code_length'        => strlen( $code['content'] ),
			'code_source'        => $code['source'],
			'code_may_be_altered' => $code['may_be_altered'],
			'wrapper_tags_removed' => '1' === (string) get_post_meta( $post->ID, '_mcpwp_wrapper_tags_removed', true ),
			'has_wrapper_tags'   => ! empty( $matching_meta_keys ),
			'matching_meta_keys' => array_values( array_unique( $matching_meta_keys ) ),
			'debug_meta_excerpt' => $debug_meta,
		);

		if ( $include_code ) {
			$data['code'] = $code['content'];
		}

		if ( $this->custom_code_admin_reporting_allowed() && is_wp_error( Mcpwp_Custom_Code_Conditions::convert( Mcpwp_Custom_Code_Conditions::raw_conditions( $post->ID ) ) ) ) {
			$data['quarantined'] = true;
			$data['quarantine_reason'] = 'conditions_not_convertible';
		}

		return $data;
	}

	/**
	 * The single source of truth for "what code does this snippet hold",
	 * shared by every readback path (create/update responses, GET, list).
	 *
	 * #974 N1 (adversarial review of #973/#965): for a writer without
	 * `unfiltered_html` (multisite site admins, or anyone under
	 * DISALLOW_UNFILTERED_HTML), wp_update_post()/wp_insert_post() re-filter
	 * `post_content` through core's own content_save_pre KSES gate and strip
	 * `<script>` from it. The code Elementor actually renders lives in
	 * `_elementor_code` (see write_custom_code_meta()'s docblock) and is
	 * untouched by that gate — but every readback path here used to return
	 * `post_content` regardless, so an agent that read, edited and wrote a
	 * snippet back saved the ALREADY-STRIPPED copy into `_elementor_code`,
	 * permanently destroying the one place the real code lived. Elementor
	 * Pro's own renderer never reads `post_content` at all (see the same
	 * docblock), so returning it was never "what was stored" in the first
	 * place — it is a copy of the input that a completely different KSES
	 * gate may have altered on the way in.
	 *
	 * `_elementor_code` is therefore always preferred. `post_content` is
	 * used only as a fallback for a snippet old enough to predate #871 (when
	 * this class only ever wrote `_elementor_code`... prior to that fix it
	 * only wrote `post_content`), and that fallback is never silently
	 * presented as the untouched source, satisfying the issue's "or says
	 * clearly that it was altered" alternative.
	 *
	 * `may_be_altered` for an `_elementor_code` source is evidence-based
	 * (#974 BLOCK-1, adversarial review of #990), not a bare `false` for
	 * that source alone. Round 1 of this fix assumed the scoped
	 * `unfiltered_html` grant in write_custom_code_meta() always either
	 * takes effect or the write is refused (custom_code_unfiltered_html_refusal())
	 * — true for multisite, but DISALLOW_UNFILTERED_HTML defeats the grant
	 * a THIRD way core's map_meta_cap() handles identically to multisite
	 * (substitutes the checked capability for the sentinel 'do_not_allow',
	 * which no `user_has_cap` filter can ever satisfy) — round 1 refused
	 * multisite but not this, so a write could still land with
	 * `_elementor_code` silently kses-stripped, and the readback said
	 * `code_source:_elementor_code, code_may_be_altered:false` while the
	 * front end printed bare JS text. custom_code_unfiltered_html_refusal()
	 * now refuses that write too (see its docblock), but existing data
	 * written before this shipped, or under a DISALLOW_UNFILTERED_HTML
	 * flip after the fact, needs an honest flag on READ as well as on
	 * WRITE:
	 *
	 *  - On a create/update response ($submitted_code given): direct
	 *    evidence — did what is actually in storage match what THIS
	 *    request just submitted?
	 *  - On a pure read (GET/list/enable/disable; $submitted_code is null,
	 *    there is nothing this request submitted to compare against):
	 *    the closest available evidence is whether a write of this exact
	 *    value, right now, in this environment, would survive — if the
	 *    environment cannot hold unfiltered code AND the stored value
	 *    still contains markup wp_kses_post() would strip, that is reason
	 *    to distrust it, whichever direction it happened.
	 *
	 * @param WP_Post     $post           Snippet post.
	 * @param string|null $submitted_code The code this same request just
	 *                                    asked to store (create/update),
	 *                                    or null for a pure read.
	 * @return array{content:string,source:string,may_be_altered:bool}
	 */
	private function read_custom_code_source( $post, $submitted_code = null ) {
		$elementor_code = get_post_meta( $post->ID, '_elementor_code', true );

		if ( is_string( $elementor_code ) && '' !== $elementor_code ) {
			if ( null !== $submitted_code ) {
				$may_be_altered = ( $elementor_code !== $submitted_code );
			} else {
				$may_be_altered = ! $this->custom_code_environment_can_hold_unfiltered_html()
					&& $this->code_needs_unfiltered_html( $elementor_code );
			}

			return array(
				'content'        => $elementor_code,
				'source'         => '_elementor_code',
				'may_be_altered' => $may_be_altered,
			);
		}

		// Legacy fallback only: a snippet with no _elementor_code at all —
		// pre-#871 (when this class only ever wrote post_content), OR
		// created through the generic /posts endpoint, which lists
		// elementor_snippet as a safe non-public type for read/list but —
		// until Mcpwp_Posts::create_post() started refusing it outright —
		// could create one with post_content set and none of
		// _elementor_code/_elementor_location/_elementor_priority at all
		// (#974/#976 review, NIT-c). Either way, post_content is not
		// necessarily what render uses and may have been through
		// WordPress's own KSES gate on save — say so plainly rather than
		// passing it off as verbatim stored content.
		$post_content = (string) $post->post_content;

		return array(
			'content'        => $post_content,
			'source'         => 'post_content_fallback',
			'may_be_altered' => '' !== $post_content,
		);
	}

	/**
	 * Get all templates.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function get_templates( $request ) {
		$args = array(
			'per_page' => $request->get_param( 'per_page' ) ?: 50,
			'page'     => $request->get_param( 'page' ) ?: 1,
			'type'     => $request->get_param( 'type' ),
		);

		$args['include_summary'] = (bool) $request->get_param( 'include_summary' );

		$page = $this->elementor_pro->get_templates_page( $args );

		$this->log_activity( 'get_templates', $request, $page['items'] );

		return $this->success_response( $this->paged_library_response( 'templates', $page ) );
	}

	/**
	 * Shape a paged library listing.
	 *
	 * `total` used to be count() of the rows just returned — the caller's own
	 * page size restated as if it were the size of the library. A client
	 * paginating on it stopped after the first page believing it had seen
	 * everything. It now comes from WP_Query's found_posts, and `pages` and
	 * `has_more` are stated so nobody has to infer them.
	 *
	 * @param string $key  Response key for the items.
	 * @param array  $page Envelope from the Pro layer.
	 * @return array
	 */
	private function paged_library_response( $key, array $page ) {
		return array(
			$key        => $page['items'],
			'total'     => $page['total'],
			'page'      => $page['page'],
			'per_page'  => $page['per_page'],
			'pages'     => $page['pages'],
			'returned'  => count( $page['items'] ),
			'has_more'  => $page['page'] < $page['pages'],
		);
	}

	/**
	 * Get single template.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_template( $request ) {
		$template_id = absint( $request->get_param( 'id' ) );
		$template    = $this->elementor_pro->get_template( $template_id );

		if ( is_wp_error( $template ) ) {
			$this->log_activity( 'get_template', $request, null, 404 );
			return $this->error_response( $template->get_error_code(), $template->get_error_message(), 404 );
		}

		$this->log_activity( 'get_template', $request, $template );

		return $this->success_response( $template );
	}

	/**
	 * Create a template.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function create_template( $request ) {
		$data = array(
			'title'          => $request->get_param( 'title' ),
			'type'           => $request->get_param( 'type' ),
			'elementor_data' => $this->get_elementor_payload_from_request( $request ),
		);

		$template = $this->elementor_pro->create_template( $data );

		if ( is_wp_error( $template ) ) {
			$status = $this->get_wp_error_status( $template );
			$this->log_activity( 'create_template', $request, null, $status );
			return $this->error_response_from_wp_error( $template );
		}

		$this->log_activity( 'create_template', $request, $template, 201 );

		return $this->success_response( $template, 201 );
	}

	/**
	 * Update a template.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function update_template( $request ) {
		$template_id = absint( $request->get_param( 'id' ) );
		$data        = array();

		if ( $request->has_param( 'title' ) ) {
			$data['title'] = $request->get_param( 'title' );
		}

		if ( $this->has_elementor_payload_in_request( $request ) ) {
			$data['elementor_data'] = $this->get_elementor_payload_from_request( $request );
		}

		$template = $this->elementor_pro->update_template( $template_id, $data );

		if ( is_wp_error( $template ) ) {
			$status = $this->get_wp_error_status( $template );
			$this->log_activity( 'update_template', $request, null, $status );
			return $this->error_response_from_wp_error( $template );
		}

		$this->log_activity( 'update_template', $request, $template );

		return $this->success_response( $template );
	}

	/**
	 * Delete a template.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function delete_template( $request ) {
		$template_id = absint( $request->get_param( 'id' ) );
		$force       = rest_sanitize_boolean( $request->get_param( 'force' ) );

		// A delete of a template has no undo whatever the flag: WordPress
		// never trashes elementor_library (#536 round 5). Approval by default.
		$template = class_exists( 'Mcpwp_Destructive_Gate' ) && Mcpwp_Destructive_Gate::should_gate( $this->get_current_api_key_record() ) ? get_post( $template_id ) : null;
		if ( $template && Mcpwp_Destructive_Gate::delete_is_permanent( 'elementor_library', $force, (string) $template->post_status ) ) {
			if ( 'elementor_library' !== $template->post_type ) {
				return $this->error_response( 'not_found', __( 'Template not found.', 'mcpwp' ), 404, array( 'id' => $template_id ) );
			}
			return $this->defer_destructive( 'delete_elementor_template', $request, array( 'type' => 'elementor_library', 'id' => $template_id, 'title' => (string) $template->post_title ), sprintf( 'Permanently delete Elementor template #%d', $template_id ) );
		}

		$result = $this->elementor_pro->delete_template( $template_id, $force );

		if ( is_wp_error( $result ) ) {
			$status = $this->get_wp_error_status( $result );
			$this->log_activity( 'delete_template', $request, null, $status );
			return $this->error_response_from_wp_error( $result );
		}

		$this->log_activity( 'delete_template', $request );

		return $this->success_response( array(
			'deleted' => true,
			'id'      => $template_id,
		) );
	}

	/**
	 * Apply template to page.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function apply_template( $request ) {
		$template_id = absint( $request->get_param( 'id' ) );
		$page_id     = absint( $request->get_param( 'page_id' ) );

		if ( ! $page_id ) {
			return $this->error_response( 'missing_page_id', __( 'Page ID is required.', 'mcpwp' ), 400 );
		}

		$result = $this->elementor_pro->apply_template_to_page(
			$page_id,
			$template_id,
			(string) ( $request->get_param( 'page_settings_mode' ) ?: 'preserve' )
		);

		if ( is_wp_error( $result ) ) {
			$status = $this->get_wp_error_status( $result );
			$this->log_activity( 'apply_template', $request, null, $status );
			return $this->error_response_from_wp_error( $result );
		}

		$this->log_activity( 'apply_template', $request );

		$response = array(
			'applied'     => true,
			'template_id' => $template_id,
			'page_id'     => $page_id,
			'edit_url'    => admin_url( 'post.php?post=' . $page_id . '&action=elementor' ),
		);

		return $this->success_response( $this->lift_render_status( $response, $result ) );
	}

	/**
	 * List reusable Elementor archetypes.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function get_archetypes( $request ) {
		$args = array(
			'per_page'        => $request->get_param( 'per_page' ) ?: 50,
			'page'            => $request->get_param( 'page' ) ?: 1,
			'scope'           => $request->get_param( 'scope' ),
			'archetype_class' => $request->get_param( 'archetype_class' ),
			'style'           => $request->get_param( 'style' ),
			'search'          => $request->get_param( 'search' ),
		);

		$args['include_summary'] = (bool) $request->get_param( 'include_summary' );

		$page = $this->elementor_pro->get_archetypes_page( $args );

		$this->log_activity( 'get_archetypes', $request, $page['items'] );

		return $this->success_response( $this->paged_library_response( 'archetypes', $page ) );
	}

	/**
	 * Get a reusable Elementor archetype.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_archetype( $request ) {
		$archetype_id = absint( $request->get_param( 'id' ) );
		$archetype    = $this->elementor_pro->get_archetype( $archetype_id );

		if ( is_wp_error( $archetype ) ) {
			$this->log_activity( 'get_archetype', $request, null, 404 );
			return $this->error_response( $archetype->get_error_code(), $archetype->get_error_message(), 404 );
		}

		$this->log_activity( 'get_archetype', $request, $archetype );

		return $this->success_response( $archetype );
	}

	/**
	 * Create a reusable Elementor archetype.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function create_archetype( $request ) {
		$payload_check = $this->require_elementor_payload( $request );
		if ( is_wp_error( $payload_check ) ) {
			$status = $this->get_wp_error_status( $payload_check );
			$this->log_activity( 'create_archetype', $request, null, $status );
			return $this->error_response_from_wp_error( $payload_check );
		}

		$data = array(
			'title'           => $request->get_param( 'title' ),
			'type'            => $request->get_param( 'type' ),
			'elementor_data'  => $this->get_elementor_payload_from_request( $request ),
			'archetype_scope' => $request->get_param( 'archetype_scope' ),
			'archetype_class' => $request->get_param( 'archetype_class' ),
			'archetype_style' => $request->get_param( 'archetype_style' ),
		);

		$archetype = $this->elementor_pro->create_archetype( $data );

		if ( is_wp_error( $archetype ) ) {
			$status = $this->get_wp_error_status( $archetype );
			$this->log_activity( 'create_archetype', $request, null, $status );
			return $this->error_response_from_wp_error( $archetype );
		}

		$this->log_activity( 'create_archetype', $request, $archetype, 201 );

		return $this->success_response( $archetype, 201 );
	}

	/**
	 * Update a reusable Elementor archetype.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function update_archetype( $request ) {
		$archetype_id = absint( $request->get_param( 'id' ) );
		$data         = array(
			'is_archetype' => true,
		);

		if ( $request->has_param( 'title' ) ) {
			$data['title'] = $request->get_param( 'title' );
		}

		if ( $this->has_elementor_payload_in_request( $request ) ) {
			$data['elementor_data'] = $this->get_elementor_payload_from_request( $request );
		}

		foreach ( array( 'archetype_scope', 'archetype_class', 'archetype_style' ) as $field ) {
			if ( $request->has_param( $field ) ) {
				$data[ $field ] = $request->get_param( $field );
			}
		}

		$archetype = $this->elementor_pro->update_template( $archetype_id, $data );

		if ( is_wp_error( $archetype ) ) {
			$status = $this->get_wp_error_status( $archetype );
			$this->log_activity( 'update_archetype', $request, null, $status );
			return $this->error_response_from_wp_error( $archetype );
		}

		$this->log_activity( 'update_archetype', $request, $archetype );

		return $this->success_response( $archetype );
	}

	/**
	 * Apply a reusable archetype to a page.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function apply_archetype( $request ) {
		$archetype_id = absint( $request->get_param( 'id' ) );
		$page_id      = absint( $request->get_param( 'page_id' ) );

		if ( ! $page_id ) {
			return $this->error_response( 'missing_page_id', __( 'Page ID is required.', 'mcpwp' ), 400 );
		}

		$result = $this->elementor_pro->apply_archetype_to_page(
			$archetype_id,
			$page_id,
			(string) ( $request->get_param( 'page_settings_mode' ) ?: 'preserve' )
		);

		if ( is_wp_error( $result ) ) {
			$status = $this->get_wp_error_status( $result );
			$this->log_activity( 'apply_archetype', $request, null, $status );
			return $this->error_response_from_wp_error( $result );
		}

		$this->log_activity( 'apply_archetype', $request );

		$response = array(
			'applied'      => true,
			'archetype_id' => $archetype_id,
			'page_id'      => $page_id,
			'edit_url'     => admin_url( 'post.php?post=' . $page_id . '&action=elementor' ),
		);

		return $this->success_response( $this->lift_render_status( $response, $result ) );
	}

	/**
	 * List reusable Elementor parts.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function get_parts( $request ) {
		// wp_create_elementor_part uses part_tags (array); the list filter is a
		// single tag. Fall back to the first entry when tag isn't given directly (#634).
		$tag = $request->get_param( 'tag' );
		if ( ! $tag ) {
			$part_tags = $request->get_param( 'part_tags' );
			if ( is_array( $part_tags ) && ! empty( $part_tags ) ) {
				$tag = reset( $part_tags );
			} elseif ( is_string( $part_tags ) && '' !== $part_tags ) {
				$tag = $part_tags;
			}
		}

		$args = array(
			'per_page' => $request->get_param( 'per_page' ) ?: 50,
			'page'     => $request->get_param( 'page' ) ?: 1,
			'kind'     => $request->get_param( 'kind' ),
			'style'    => $request->get_param( 'style' ),
			'tag'      => $tag,
			'search'   => $request->get_param( 'search' ),
		);

		$args['include_summary'] = (bool) $request->get_param( 'include_summary' );

		$page = $this->elementor_pro->get_parts_page( $args );

		$this->log_activity( 'get_parts', $request, $page['items'] );

		return $this->success_response( $this->paged_library_response( 'parts', $page ) );
	}

	/**
	 * Get a reusable Elementor part.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_part( $request ) {
		$part_id = absint( $request->get_param( 'id' ) );
		$part    = $this->elementor_pro->get_part( $part_id );

		if ( is_wp_error( $part ) ) {
			$this->log_activity( 'get_part', $request, null, 404 );
			return $this->error_response( $part->get_error_code(), $part->get_error_message(), 404 );
		}

		$this->log_activity( 'get_part', $request, $part );

		return $this->success_response( $part );
	}

	/**
	 * Create a reusable Elementor part.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function create_part( $request ) {
		$payload_check = $this->require_elementor_payload( $request );
		if ( is_wp_error( $payload_check ) ) {
			$status = $this->get_wp_error_status( $payload_check );
			$this->log_activity( 'create_part', $request, null, $status );
			return $this->error_response_from_wp_error( $payload_check );
		}

		$data = array(
			'title'          => $request->get_param( 'title' ),
			'type'           => $request->get_param( 'type' ),
			'elementor_data' => $this->get_elementor_payload_from_request( $request ),
			'part_kind'      => $request->get_param( 'part_kind' ),
			'part_style'     => $request->get_param( 'part_style' ),
			'part_tags'      => $request->get_param( 'part_tags' ),
		);

		$part = $this->elementor_pro->create_part( $data );

		if ( is_wp_error( $part ) ) {
			$status = $this->get_wp_error_status( $part );
			$this->log_activity( 'create_part', $request, null, $status );
			return $this->error_response_from_wp_error( $part );
		}

		$this->log_activity( 'create_part', $request, $part, 201 );

		return $this->success_response( $part, 201 );
	}

	/**
	 * Update a reusable Elementor part.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function update_part( $request ) {
		$part_id = absint( $request->get_param( 'id' ) );
		$data    = array(
			'is_part' => true,
		);

		if ( $request->has_param( 'title' ) ) {
			$data['title'] = $request->get_param( 'title' );
		}

		if ( $this->has_elementor_payload_in_request( $request ) ) {
			$data['elementor_data'] = $this->get_elementor_payload_from_request( $request );
		}

		foreach ( array( 'part_kind', 'part_style', 'part_tags' ) as $field ) {
			if ( $request->has_param( $field ) ) {
				$data[ $field ] = $request->get_param( $field );
			}
		}

		$part = $this->elementor_pro->update_template( $part_id, $data );

		if ( is_wp_error( $part ) ) {
			$status = $this->get_wp_error_status( $part );
			$this->log_activity( 'update_part', $request, null, $status );
			return $this->error_response_from_wp_error( $part );
		}

		$this->log_activity( 'update_part', $request, $part );

		return $this->success_response( $part );
	}

	/**
	 * Create a reusable part from a live section.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function create_part_from_section( $request ) {
		$page_id    = (int) $request->get_param( 'page_id' );
		$element_id = $request->get_param( 'element_id' );

		if ( ! $page_id || ! $element_id ) {
			return $this->error_response( 'missing_params', __( 'page_id and element_id are required.', 'mcpwp' ), 400 );
		}

		$data = array(
			'title'      => $request->get_param( 'title' ),
			'part_kind'  => $request->get_param( 'part_kind' ),
			'part_style' => $request->get_param( 'part_style' ),
			'part_tags'  => $request->get_param( 'part_tags' ),
		);

		$part = $this->elementor_pro->create_part_from_section( $page_id, $element_id, $data );

		if ( is_wp_error( $part ) ) {
			$status = $this->get_wp_error_status( $part );
			$this->log_activity( 'create_part_from_section', $request, null, $status );
			return $this->error_response_from_wp_error( $part );
		}

		$this->log_activity( 'create_part_from_section', $request, $part, 201 );

		return $this->success_response( $part, 201 );
	}

	/**
	 * Resolve Elementor payload from request params.
	 *
	 * Shared-host WAFs often reject large nested JSON POST bodies. For those
	 * environments we accept `elementor_data_base64` in addition to the normal
	 * `elementor_data` field so clients can safely submit form-encoded payloads.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array|string|null
	 */
	private function get_elementor_payload_from_request( $request ) {
		$base64 = $request->get_param( 'elementor_data_base64' );
		if ( is_string( $base64 ) && '' !== $base64 ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			$decoded = base64_decode( $base64, true );
			if ( false !== $decoded && '' !== $decoded ) {
				return $decoded;
			}
		}

		return $request->get_param( 'elementor_data' );
	}

	/**
	 * Check whether the request explicitly includes Elementor payload data.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool
	 */
	private function has_elementor_payload_in_request( $request ) {
		return $request->has_param( 'elementor_data_base64' ) || $request->has_param( 'elementor_data' );
	}

	/**
	 * Refuse to create a library entry (part or archetype) from an empty
	 * Elementor payload.
	 *
	 * `elementor_data` lost its `required: true` when `elementor_data_base64`
	 * was added alongside it (#831) so a caller could satisfy the schema
	 * with neither. Re-adding `required` to one of the two would wrongly
	 * reject a legitimate base64-only or array-only call — JSON Schema
	 * cannot express "exactly one of these" here — so this is enforced in
	 * the handler instead.
	 *
	 * Without this, `Mcpwp_Elementor_Pro::create_template()` treats an
	 * empty payload as "no data submitted" and simply skips the save step
	 * ( `! empty( $data['elementor_data'] )` ), so create_part()/
	 * create_archetype() previously returned 201 with a blank library
	 * entry instead of a clear error (RC review finding #1).
	 *
	 * create_template() itself is NOT gated by this: its own tool
	 * description documents an omitted payload as intentional ("If
	 * omitted, Elementor creates a blank template"), a real workflow for
	 * a Theme Builder template a human then finishes in the Elementor
	 * editor. A part or an archetype has no such "finish it in the
	 * editor" story — it exists purely to be reused/applied verbatim — so
	 * a blank one is never useful and is refused instead.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return true|WP_Error True when a usable payload is present.
	 */
	private function require_elementor_payload( $request ) {
		$payload = $this->get_elementor_payload_from_request( $request );

		if ( empty( $payload ) ) {
			return new WP_Error(
				'missing_elementor_data',
				__( 'One of elementor_data or elementor_data_base64 is required.', 'mcpwp' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Apply a reusable part to a page.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function apply_part( $request ) {
		$part_id = absint( $request->get_param( 'id' ) );
		$page_id = absint( $request->get_param( 'page_id' ) );
		// Default is insert (non-destructive) — omission must never silently erase
		// a page's existing content (#637). Callers opt into replace explicitly.
		$mode    = sanitize_key( (string) ( $request->get_param( 'mode' ) ?: 'insert' ) );
		$position = (string) ( $request->get_param( 'position' ) ?: 'end' );

		if ( ! $page_id ) {
			return $this->error_response( 'missing_page_id', __( 'Page ID is required.', 'mcpwp' ), 400 );
		}

		if ( 'insert' === $mode ) {
			$result = $this->elementor_pro->insert_part_into_page( $part_id, $page_id, $position );
		} else {
			$result = $this->elementor_pro->apply_part_to_page( $part_id, $page_id );
		}

		if ( is_wp_error( $result ) ) {
			$status = $this->get_wp_error_status( $result );
			$this->log_activity( 'apply_part', $request, null, $status );
			return $this->error_response_from_wp_error( $result );
		}

		$this->log_activity( 'apply_part', $request );

		$response = array(
			'applied'  => true,
			'part_id'  => $part_id,
			'page_id'  => $page_id,
			'mode'     => $mode,
			'position' => 'insert' === $mode ? $position : null,
			'details'  => $result,
			'edit_url' => admin_url( 'post.php?post=' . $page_id . '&action=elementor' ),
		);

		// `applied` only says the part's data reached the page. Whether Elementor
		// then rendered it is a separate outcome that used to be reachable only by
		// digging through nested debug, so a part that saved and a part that saved
		// and rendered returned the same top-level response (#765). Lift the
		// writer's verdict to where a caller will actually read it.
		$response = $this->lift_render_status( $response, $result );

		return $this->success_response( $response );
	}

	/**
	 * Surface a nested write's render verdict at the top level of a response.
	 *
	 * The writer records render_verified / render_errors on each save result. A
	 * part insert wraps one or more of those, so without this the verdict is
	 * buried under details.last_operation and an unusable page is indistinguishable
	 * from a delivered one.
	 *
	 * Absence of the field is not treated as success: it means the nested result
	 * predates this reporting or came from a path that does not save, so nothing
	 * is claimed either way and the response is returned untouched.
	 *
	 * @param array $response Response being built.
	 * @param mixed $details  Nested write result, possibly wrapping others.
	 * @return array Response with render_verified/render_errors when known.
	 */
	private function lift_render_status( array $response, $details ) {
		$verdicts = array();
		$errors   = array();

		$this->collect_nested_render_status( $details, $verdicts, $errors );

		if ( empty( $verdicts ) ) {
			return $response;
		}

		// Any one failed render makes the whole operation unverified.
		$response['render_verified'] = ! in_array( false, $verdicts, true );

		if ( ! $response['render_verified'] ) {
			$response['render_errors'] = array_values( array_unique( $errors ) );
			$response['message']       = __( 'The Elementor content was written to the page, but Elementor did not finish rendering it. The page may not display as an Elementor layout — verify it before treating this as delivered.', 'mcpwp' );
		}

		return $response;
	}

	/**
	 * Walk a nested write result, gathering every render verdict it contains.
	 *
	 * @param mixed $node     Result node.
	 * @param array $verdicts Collected render_verified booleans, by reference.
	 * @param array $errors   Collected render_errors strings, by reference.
	 * @return void
	 */
	private function collect_nested_render_status( $node, array &$verdicts, array &$errors ) {
		if ( ! is_array( $node ) ) {
			return;
		}

		if ( array_key_exists( 'render_verified', $node ) ) {
			$verdicts[] = (bool) $node['render_verified'];

			if ( ! empty( $node['render_errors'] ) && is_array( $node['render_errors'] ) ) {
				foreach ( $node['render_errors'] as $error ) {
					$errors[] = (string) $error;
				}
			}
		}

		foreach ( $node as $key => $child ) {
			if ( 'render_errors' === $key || ! is_array( $child ) ) {
				continue;
			}
			$this->collect_nested_render_status( $child, $verdicts, $errors );
		}
	}

	/**
	 * Clone a page.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function clone_page( $request ) {
		$source_id = absint( $request->get_param( 'source_id' ) );

		if ( ! $source_id ) {
			return $this->error_response( 'missing_source_id', __( 'Source ID is required.', 'mcpwp' ), 400 );
		}

		// Category gate (#1024 round-1 review, WARN-3): clone_page() accepts
		// ANY source post type with no category restriction — a designer key
		// could clone a WooCommerce product (including its _sku and other
		// meta) as a new product, or clone an Elementor template with
		// elementor-templates disabled site-wide.
		// #1030 round-2 review, BLOCK-C (regression): page/post are this
		// door's base case, exempt from the category check — same exemption
		// validate_post() and every other #1024 fix carries. Without it, a
		// designer key (holds elementor/elementor-build, never 'content')
		// cloning an ordinary PAGE was wrongly denied.
		// #1030 round-3 review, item 2: 'source_id' is a declared post-ref
		// param (Mcpwp_Api_Auth::get_post_ref_param_map()), so the chokepoint
		// already refuses a gated source before this handler runs. This stays
		// as defense-in-depth for a direct handler call that bypasses
		// check_permission(), and now answers with the SAME generic body the
		// chokepoint uses rather than this door's own former bespoke
		// "Source page not found." text, so the two paths cannot drift apart.
		$source = get_post( $source_id );
		if ( $source && ! in_array( $source->post_type, array( 'page', 'post' ), true ) && ! $this->caller_may_read_category( $this->category_for_post_type( $source->post_type ) ) ) {
			$category_denied = $this->post_ref_not_found_error( $request );
			$this->log_activity( 'clone_page', $request, null, $this->get_wp_error_status( $category_denied ) );
			return $this->error_response_from_wp_error( $category_denied );
		}

		$args = array(
			'title'  => $request->get_param( 'title' ),
			'status' => $request->get_param( 'status' ),
			'parent' => $request->get_param( 'parent' ),
		);

		$result = $this->elementor_pro->clone_page( $source_id, $args );

		if ( is_wp_error( $result ) ) {
			$status = $this->get_wp_error_status( $result );
			$this->log_activity( 'clone_page', $request, null, $status );
			return $this->error_response_from_wp_error( $result );
		}

		$this->log_activity( 'clone_page', $request, $result, 201 );

		return $this->success_response( $result, 201 );
	}

	/**
	 * Create a landing page.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function create_landing_page( $request ) {
		$template_id = absint( $request->get_param( 'template_id' ) );

		// Category gate (#1024 round-1 review, WARN-3): create_landing_page()
		// applies template_id's Elementor data to the new page
		// (apply_template_to_page()) with no category check of its own —
		// unlike the dedicated /elementor/templates/{id} route, which
		// already requires 'elementor-templates'. Checked BEFORE the page is
		// created: apply_template_to_page() runs after wp_insert_post()
		// inside create_landing_page(), too late to refuse without also
		// cleaning up a half-created page.
		if ( $template_id ) {
			$template = get_post( $template_id );
			if ( $template && ! $this->caller_may_read_category( $this->category_for_post_type( $template->post_type ) ) ) {
				return $this->error_response( 'not_found', __( 'Template not found.', 'mcpwp' ), 404 );
			}
		}

		$data = array(
			'title'          => $request->get_param( 'title' ),
			'status'         => $request->get_param( 'status' ),
			'template_id'    => $request->get_param( 'template_id' ),
			'sections'       => $request->get_param( 'sections' ),
			'elementor_data' => $request->get_param( 'elementor_data' ),
		);

		$result = $this->elementor_pro->create_landing_page( $data );

		if ( is_wp_error( $result ) ) {
			$status = $this->get_wp_error_status( $result );
			$this->log_activity( 'create_landing_page', $request, null, $status );
			return $this->error_response_from_wp_error( $result );
		}

		$this->log_activity( 'create_landing_page', $request, $result, 201 );

		return $this->success_response( $result, 201 );
	}

	/**
	 * Get available widgets.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function get_widgets( $request ) {
		// 'widget' is this route's own param name. 'type' also accepted
		// (#831) — the Free build's controller registers the same path with
		// a handler that reads 'type', and the MCP tool's canonical param is
		// now 'widget_type' (remapped to 'type'); this keeps whichever
		// controller answers /elementor/widgets on this install consistent.
		$widget_name = $request->get_param( 'widget' );
		if ( ! $widget_name ) {
			$widget_name = $request->get_param( 'type' );
		}
		$result      = $this->elementor_pro->get_available_widgets( $widget_name );

		if ( is_wp_error( $result ) ) {
			$this->log_activity( 'get_widgets', $request, null, 404 );
			return $this->error_response( $result->get_error_code(), $result->get_error_message(), 404 );
		}

		$this->log_activity( 'get_widgets', $request );

		// Single widget returns the widget object directly.
		if ( $widget_name ) {
			return $this->success_response( $result );
		}

		return $this->success_response( array(
			'widgets' => $result,
			'total'   => count( $result ),
		) );
	}

	/**
	 * Get global settings.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_globals( $request ) {
		$globals = $this->elementor_pro->get_globals();

		if ( is_wp_error( $globals ) ) {
			$status = $this->get_wp_error_status( $globals );
			$this->log_activity( 'get_globals', $request, null, $status );
			return $this->error_response_from_wp_error( $globals );
		}

		$this->log_activity( 'get_globals', $request, $globals );

		return $this->success_response( $globals );
	}

	/**
	 * Set Elementor global settings (colors, fonts, etc.).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function set_globals( $request ) {
		$this->log_activity( 'set_elementor_globals', $request );

		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return $this->error_response( 'elementor_not_active', __( 'Elementor is not active.', 'mcpwp' ), 400 );
		}

		// Read the JSON body first. The MCP tool and /batch dispatch pass their
		// arguments in the POST body bucket with no JSON content-type, so
		// get_json_params() is null on those paths — fall back to get_body_params()
		// (the POST bucket only; it excludes URL/query params, so the former
		// query-string smuggling vector stays closed).
		$params = $request->get_json_params();
		if ( empty( $params ) || ! is_array( $params ) ) {
			$params = $request->get_body_params();
		}
		$allowed = self::allowed_global_setting_types();

		if ( empty( $params ) || ! is_array( $params ) ) {
			return $this->error_response(
				'missing_params',
				__( 'Globals data is required: send a JSON object with one or more supported kit setting keys.', 'mcpwp' ),
				400,
				array( 'supported_keys' => array_keys( $allowed ) )
			);
		}

		// Get the active kit
		$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
		if ( ! $kit || ! $kit->get_id() ) {
			return $this->error_response( 'no_kit', __( 'No active Elementor kit found.', 'mcpwp' ), 500 );
		}

		$kit_id   = $kit->get_id();
		$existing = get_post_meta( $kit_id, '_elementor_page_settings', true );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		// custom_css renders sitewide, so writing it requires admin scope (the
		// route itself stays write-scope for the design-token surface). OAuth
		// callers carry a null key record → treated as non-admin (fails closed).
		$record   = $this->get_current_api_key_record();
		$is_admin = is_array( $record ) && $this->key_has_scope( $record, 'admin' );

		// Allowlist: only the documented kit-setting keys may be written. Every
		// other key is ignored, so a write-scoped key cannot mass-overwrite
		// arbitrary kit settings or corrupt the kit's shape with wrong-typed
		// values. Each allowed key fully replaces its prior value (the intended
		// "set" semantic) rather than being recursively deep-merged.
		$updated      = $existing;
		$applied      = array();
		$ignored      = array();
		$scope_denied = array();
		$warnings     = array();

		foreach ( $params as $key => $value ) {
			if ( ! is_string( $key ) || ! isset( $allowed[ $key ] ) ) {
				$ignored[] = is_string( $key ) ? $key : (string) $key;
				continue;
			}

			// Admin-gate custom_css.
			if ( 'custom_css' === $key && ! $is_admin ) {
				$scope_denied[] = $key;
				continue;
			}

			$expected = $allowed[ $key ];

			if ( 'array' === $expected ) {
				if ( ! is_array( $value ) ) {
					$warnings[] = sprintf( 'Ignored "%s": expected an array/object value.', $key );
					continue;
				}
			} elseif ( 'number' === $expected ) {
				if ( ! is_numeric( $value ) ) {
					$warnings[] = sprintf( 'Ignored "%s": expected a numeric value.', $key );
					continue;
				}
				$value = 0 + $value;
			} else { // 'string'
				$value = is_scalar( $value ) ? (string) $value : '';
			}

			if ( 'custom_css' === $key ) {
				$value = $this->sanitize_kit_custom_css( (string) $value, $warnings );
			} elseif ( 'string' === $expected ) {
				$value = sanitize_text_field( $value );
			}

			$updated[ $key ] = $value;
			$applied[]       = $key;
		}

		// Nothing applied, and the only rejected key was admin-gated → say so
		// plainly instead of a misleading "no supported keys".
		if ( empty( $applied ) && ! empty( $scope_denied ) ) {
			return $this->error_response(
				'insufficient_scope',
				__( 'Writing custom_css requires an admin-scoped API key.', 'mcpwp' ),
				403
			);
		}

		if ( empty( $applied ) ) {
			return $this->error_response(
				'no_supported_keys',
				__( 'No supported kit setting keys were provided.', 'mcpwp' ),
				400,
				array( 'supported_keys' => array_keys( $allowed ) )
			);
		}

		// Applied some keys but custom_css was admin-gated — save the rest, warn.
		if ( ! empty( $scope_denied ) ) {
			$warnings[] = __( 'custom_css was not applied: writing kit CSS requires an admin-scoped API key. Other settings were saved.', 'mcpwp' );
		}

		update_post_meta( $kit_id, '_elementor_page_settings', $updated );

		// Clear Elementor caches
		if ( method_exists( \Elementor\Plugin::$instance->files_manager, 'clear_cache' ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}

		if ( ! empty( $ignored ) ) {
			$warnings[] = sprintf(
				/* translators: 1: comma-separated ignored keys, 2: comma-separated supported keys. */
				__( 'Ignored unsupported field(s): %1$s. Supported kit settings: %2$s.', 'mcpwp' ),
				implode( ', ', $ignored ),
				implode( ', ', array_keys( $allowed ) )
			);
		}

		$response = array(
			'kit_id'   => $kit_id,
			'applied'  => $applied,
			'settings' => $updated,
			'message'  => __( 'Elementor globals updated.', 'mcpwp' ),
		);
		if ( ! empty( $ignored ) ) {
			$response['ignored'] = $ignored;
		}
		if ( ! empty( $scope_denied ) ) {
			$response['requires_admin'] = $scope_denied;
		}
		if ( ! empty( $warnings ) ) {
			$response['warnings'] = $warnings;
		}

		return $this->success_response( $response );
	}

	/**
	 * Allowlist of writable Elementor kit setting keys → expected value type.
	 *
	 * Only these documented keys may be written via set_globals(); any other key
	 * in the request body is ignored. This bounds a write-scoped key to the
	 * advertised design-token / layout surface and blocks arbitrary
	 * mass-assignment into the sitewide kit.
	 *
	 * @return array<string,string> key => 'array'|'string'|'number'.
	 */
	private static function allowed_global_setting_types() {
		return array(
			'system_colors'               => 'array',
			'custom_colors'               => 'array',
			'system_typography'           => 'array',
			'custom_typography'           => 'array',
			'custom_css'                  => 'string',
			'container_width'             => 'array',
			'space_between_widgets'       => 'array',
			'page_title_selector'         => 'string',
			'stretched_section_container' => 'string',
			'default_generic_fonts'       => 'string',
			'viewport_md'                 => 'number',
			'viewport_lg'                 => 'number',
		);
	}

	/**
	 * Sanitize kit-level custom CSS before it is stored and rendered sitewide.
	 *
	 * A write-scoped key can reach this endpoint, so the CSS cannot be trusted
	 * like admin-authored theme CSS. Strip HTML / style-context breakouts
	 * (e.g. `</style><script>`) and neutralize the CSS constructs that load or
	 * execute code (@import, expression(), behavior/-moz-binding, javascript:
	 * URLs). Ordinary styling — including background url() — is preserved.
	 * Unscoped bare-tag selectors (which bleed into header/footer templates) are
	 * reported as warnings.
	 *
	 * @param string $css      Raw CSS.
	 * @param array  $warnings Warnings collector (by reference).
	 * @return string Sanitized CSS.
	 */
	private function sanitize_kit_custom_css( $css, &$warnings ) {
		$css = (string) $css;

		// Remove HTML tag sequences (style/script-context breakouts).
		$css = preg_replace( '#</?[a-z][^>]*>#i', '', $css );
		$css = str_ireplace( array( '</style', '<style', '<script', '</script' ), '', (string) $css );

		// Neutralize dangerous CSS directives (case-insensitive).
		$patterns = array(
			'/@import\b/i'          => '/* import-removed */',
			'/\bexpression\s*\(/i'  => 'expression-removed(',
			'/\bbehavior\s*:/i'     => 'behavior-removed:',
			'/-moz-binding\s*:/i'   => '-moz-binding-removed:',
			'/javascript\s*:/i'     => 'javascript-removed:',
		);
		$before = (string) $css;
		$result = preg_replace( array_keys( $patterns ), array_values( $patterns ), $before );
		$css    = ( null === $result ) ? $before : $result;
		if ( $css !== $before ) {
			$warnings[] = __( 'Kit custom CSS was sanitized: @import / expression() / behavior / -moz-binding / javascript: constructs were neutralized.', 'mcpwp' );
		}

		// Warn about unscoped bare selectors that leak into header/footer templates (#205).
		$bare_selectors = array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'body', 'p', 'a', 'ul', 'ol', 'li', 'img', 'div', 'span', 'section', 'header', 'footer', 'nav', 'main' );
		foreach ( $bare_selectors as $sel ) {
			if ( preg_match( '/(?:^|\n)\s*' . preg_quote( $sel, '/' ) . '\s*\{/m', $css ) ) {
				$warnings[] = sprintf(
					'Kit CSS contains unscoped "%s" selector — this will affect header/footer templates. Scope it to .site-main or .elementor to avoid breaking theme builder templates.',
					$sel
				);
			}
		}

		return $css;
	}

	/**
	 * Build a page from section blueprints.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function build_page( $request ) {
		$this->log_activity( 'build_page', $request );

		$title    = $request->get_param( 'title' );
		$sections = $request->get_param( 'sections' );
		$status   = $request->get_param( 'status' ) ?: 'draft';

		$builder = new Mcpwp_Page_Builder();
		$builder->set_template_ref_gate( $this->template_ref_gate() );
		$result = $builder->build( $title, $sections, $status );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response( $result, 201 );
	}

	/**
	 * List available blueprint types with parameter schemas.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function list_blueprints( $request ) {
		$this->log_activity( 'list_blueprints', $request );

		$catalog = Mcpwp_Page_Builder::get_blueprint_catalog();

		return $this->success_response( array(
			'count'      => count( $catalog ),
			'blueprints' => $catalog,
		) );
	}

	/**
	 * Build a single section from a blueprint type.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public function get_blueprint( $request ) {
		$this->log_activity( 'get_blueprint', $request );

		$type   = $request->get_param( 'type' );
		$params = $request->get_params();

		if ( empty( $type ) ) {
			return new WP_Error( 'missing_type', 'Blueprint type is required.', array( 'status' => 400 ) );
		}

		$builder = new Mcpwp_Page_Builder();
		$result  = $builder->build_single_section( $type, $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response( $result );
	}

	/**
	 * Save a section from a live page as a reusable Elementor template.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public function save_section_as_template( $request ) {
		$this->log_activity( 'save_section_as_template', $request );

		$page_id    = (int) $request->get_param( 'page_id' );
		$element_id = $request->get_param( 'element_id' );
		$title      = $request->get_param( 'title' );

		if ( ! $page_id || ! $element_id ) {
			return new WP_Error( 'missing_params', 'page_id and element_id are required.', array( 'status' => 400 ) );
		}

		// Category gate (#1024 round-1 review, WARN-3): save_section_as_template()
		// reads Elementor data from ANY source post type with no category
		// check — a gutenberg-only key could otherwise read a WooCommerce
		// product's Elementor data (or a category-gated template's) and
		// re-save it as a new, fully-readable template. Same shape the
		// underlying call already uses for a genuinely missing source page.
		// page/post are exempt (#1030 round-2 review, BLOCK-C regression) —
		// same exemption every other #1024 fix carries; without it, a
		// designer key (holds gutenberg/elementor-templates, never
		// 'content') saving a section from an ordinary PAGE was wrongly
		// denied.
		$source = get_post( $page_id );
		if ( $source && ! in_array( $source->post_type, array( 'page', 'post' ), true ) && ! $this->caller_may_read_category( $this->category_for_post_type( $source->post_type ) ) ) {
			return new WP_Error( 'invalid_page', 'Page not found.', array( 'status' => 404 ) );
		}

		$result = $this->elementor_pro->save_section_as_template( $page_id, $element_id, $title );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response( $result, 201 );
	}
}
