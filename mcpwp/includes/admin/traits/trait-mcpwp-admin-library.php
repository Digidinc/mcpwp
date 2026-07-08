<?php
/**
 * Admin library page methods.
 *
 * Carved verbatim from Mcpwp_Admin (G3 split). Mixed back via trait — same class, same $this.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Mcpwp_Admin_Library_Trait {

	/**
	 * Render the Library page.
	 */
	public function render_library_page() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mumcp' ) );
		}

		// Handle library form actions.
		if ( isset( $_POST['mcpwp_promote_template_archetype'] ) ) {
			check_admin_referer( 'mcpwp_library_actions', 'mcpwp_library_nonce' );
			$this->handle_promote_template_to_archetype();
		}

		if ( isset( $_POST['mcpwp_promote_template_part'] ) ) {
			check_admin_referer( 'mcpwp_library_actions', 'mcpwp_library_nonce' );
			$this->handle_promote_template_to_part();
		}

		if ( isset( $_POST['mcpwp_extract_section_part'] ) ) {
			check_admin_referer( 'mcpwp_library_actions', 'mcpwp_library_nonce' );
			$this->handle_extract_section_to_part();
		}

		if ( isset( $_POST['mcpwp_create_page_from_archetype'] ) ) {
			check_admin_referer( 'mcpwp_library_actions', 'mcpwp_library_nonce' );
			$this->handle_create_page_from_archetype();
		}

		if ( isset( $_POST['mcpwp_apply_part_to_page'] ) ) {
			check_admin_referer( 'mcpwp_library_actions', 'mcpwp_library_nonce' );
			$this->handle_apply_part_to_page();
		}

		if ( isset( $_POST['mcpwp_demote_archetype'] ) ) {
			check_admin_referer( 'mcpwp_library_actions', 'mcpwp_library_nonce' );
			$this->handle_demote_archetype();
		}

		if ( isset( $_POST['mcpwp_demote_part'] ) ) {
			check_admin_referer( 'mcpwp_library_actions', 'mcpwp_library_nonce' );
			$this->handle_demote_part();
		}

		if ( isset( $_POST['mcpwp_create_product_archetype'] ) ) {
			check_admin_referer( 'mcpwp_library_actions', 'mcpwp_library_nonce' );
			$this->handle_create_product_archetype();
		}

		if ( isset( $_POST['mcpwp_create_product_from_archetype'] ) ) {
			check_admin_referer( 'mcpwp_library_actions', 'mcpwp_library_nonce' );
			$this->handle_create_product_from_archetype();
		}

		if ( isset( $_POST['mcpwp_delete_product_archetype'] ) ) {
			check_admin_referer( 'mcpwp_library_actions', 'mcpwp_library_nonce' );
			$this->handle_delete_product_archetype();
		}

		if ( isset( $_POST['mcpwp_create_design_reference'] ) ) {
			check_admin_referer( 'mcpwp_library_actions', 'mcpwp_library_nonce' );
			$this->handle_create_design_reference();
		}

		if ( isset( $_POST['mcpwp_create_page_from_design_reference'] ) ) {
			check_admin_referer( 'mcpwp_library_actions', 'mcpwp_library_nonce' );
			$this->handle_create_page_from_design_reference();
		}

		$library_inventory      = $this->get_library_inventory();
		$library_filters        = $this->get_library_filters();
		$library_filter_options = $this->get_library_filter_options( $library_inventory );
		$library_inventory      = $this->filter_library_inventory( $library_inventory, $library_filters );

		include MCPWP_PLUGIN_DIR . 'admin/partials/mcpwp-library-display.php';
	}

	/**
	 * Handle promotion of an existing template into a page archetype.
	 *
	 * @return void
	 */
	private function handle_promote_template_to_archetype() {
		if ( ! class_exists( 'Mcpwp_Elementor_Pro' ) ) {
			add_settings_error(
				'mcpwp_messages',
				'mcpwp_library_archetype_missing',
				__( 'Elementor Pro handler is not available.', 'mumcp' ),
				'error'
			);
			return;
		}

		$template_id = isset( $_POST['mcpwp_archetype_template_id'] ) ? absint( wp_unslash( $_POST['mcpwp_archetype_template_id'] ) ) : 0;
		$title       = isset( $_POST['mcpwp_archetype_title'] ) ? sanitize_text_field( wp_unslash( $_POST['mcpwp_archetype_title'] ) ) : '';
		$scope       = isset( $_POST['mcpwp_archetype_scope'] ) ? sanitize_key( wp_unslash( $_POST['mcpwp_archetype_scope'] ) ) : 'page';
		$class       = isset( $_POST['mcpwp_archetype_class'] ) ? sanitize_key( wp_unslash( $_POST['mcpwp_archetype_class'] ) ) : '';
		$style       = isset( $_POST['mcpwp_archetype_style'] ) ? sanitize_text_field( wp_unslash( $_POST['mcpwp_archetype_style'] ) ) : '';
		$brief       = isset( $_POST['mcpwp_archetype_brief'] ) ? sanitize_textarea_field( wp_unslash( $_POST['mcpwp_archetype_brief'] ) ) : '';

		if ( $template_id <= 0 ) {
			add_settings_error(
				'mcpwp_messages',
				'mcpwp_library_archetype_invalid_id',
				__( 'Enter a valid Elementor template ID to promote as an archetype.', 'mumcp' ),
				'error'
			);
			return;
		}

		$elementor = new Mcpwp_Elementor_Pro();
		$result    = $elementor->update_template(
			$template_id,
			array(
				'title'           => $title,
				'is_archetype'    => true,
				'archetype_scope' => $scope ? $scope : 'page',
				'archetype_class' => $class,
				'archetype_style' => $style,
				'archetype_brief' => $brief,
			)
		);

		if ( is_wp_error( $result ) ) {
			add_settings_error(
				'mcpwp_messages',
				'mcpwp_library_archetype_failed',
				$result->get_error_message(),
				'error'
			);
			return;
		}

		add_settings_error(
			'mcpwp_messages',
			'mcpwp_library_archetype_saved',
			sprintf(
				/* translators: %s: template title */
				__( 'Saved archetype: %s', 'mumcp' ),
				isset( $result['title'] ) ? $result['title'] : (string) $template_id
			),
			'updated'
		);
	}

	/**
	 * Handle promotion of an existing template into a reusable part.
	 *
	 * @return void
	 */
	private function handle_promote_template_to_part() {
		if ( ! class_exists( 'Mcpwp_Elementor_Pro' ) ) {
			add_settings_error(
				'mcpwp_messages',
				'mcpwp_library_part_missing',
				__( 'Elementor Pro handler is not available.', 'mumcp' ),
				'error'
			);
			return;
		}

		$template_id = isset( $_POST['mcpwp_part_template_id'] ) ? absint( wp_unslash( $_POST['mcpwp_part_template_id'] ) ) : 0;
		$title       = isset( $_POST['mcpwp_part_title'] ) ? sanitize_text_field( wp_unslash( $_POST['mcpwp_part_title'] ) ) : '';
		$kind        = isset( $_POST['mcpwp_part_kind'] ) ? sanitize_key( wp_unslash( $_POST['mcpwp_part_kind'] ) ) : '';
		$style       = isset( $_POST['mcpwp_part_style'] ) ? sanitize_text_field( wp_unslash( $_POST['mcpwp_part_style'] ) ) : '';
		$tags_raw    = isset( $_POST['mcpwp_part_tags'] ) ? sanitize_text_field( wp_unslash( $_POST['mcpwp_part_tags'] ) ) : '';

		if ( $template_id <= 0 ) {
			add_settings_error(
				'mcpwp_messages',
				'mcpwp_library_part_invalid_id',
				__( 'Enter a valid Elementor template ID to promote as a reusable part.', 'mumcp' ),
				'error'
			);
			return;
		}

		$elementor = new Mcpwp_Elementor_Pro();
		$result    = $elementor->update_template(
			$template_id,
			array(
				'title'      => $title,
				'is_part'    => true,
				'part_kind'  => $kind,
				'part_style' => $style,
				'part_tags'  => $this->parse_tag_string( $tags_raw ),
			)
		);

		if ( is_wp_error( $result ) ) {
			add_settings_error(
				'mcpwp_messages',
				'mcpwp_library_part_failed',
				$result->get_error_message(),
				'error'
			);
			return;
		}

		add_settings_error(
			'mcpwp_messages',
			'mcpwp_library_part_saved',
			sprintf(
				/* translators: %s: template title */
				__( 'Saved reusable part: %s', 'mumcp' ),
				isset( $result['title'] ) ? $result['title'] : (string) $template_id
			),
			'updated'
		);
	}

	/**
	 * Handle extraction of a live Elementor section into a reusable part.
	 *
	 * @return void
	 */
	private function handle_extract_section_to_part() {
		if ( ! class_exists( 'Mcpwp_Elementor_Pro' ) ) {
			add_settings_error(
				'mcpwp_messages',
				'mcpwp_library_extract_missing',
				__( 'Elementor Pro handler is not available.', 'mumcp' ),
				'error'
			);
			return;
		}

		$page_id    = isset( $_POST['mcpwp_source_page_id'] ) ? absint( wp_unslash( $_POST['mcpwp_source_page_id'] ) ) : 0;
		$element_id = isset( $_POST['mcpwp_source_element_id'] ) ? sanitize_text_field( wp_unslash( $_POST['mcpwp_source_element_id'] ) ) : '';
		$title      = isset( $_POST['mcpwp_extract_part_title'] ) ? sanitize_text_field( wp_unslash( $_POST['mcpwp_extract_part_title'] ) ) : '';
		$kind       = isset( $_POST['mcpwp_extract_part_kind'] ) ? sanitize_key( wp_unslash( $_POST['mcpwp_extract_part_kind'] ) ) : '';
		$style      = isset( $_POST['mcpwp_extract_part_style'] ) ? sanitize_text_field( wp_unslash( $_POST['mcpwp_extract_part_style'] ) ) : '';
		$tags_raw   = isset( $_POST['mcpwp_extract_part_tags'] ) ? sanitize_text_field( wp_unslash( $_POST['mcpwp_extract_part_tags'] ) ) : '';

		if ( $page_id <= 0 || '' === $element_id ) {
			add_settings_error(
				'mcpwp_messages',
				'mcpwp_library_extract_invalid',
				__( 'Enter a valid source page ID and Elementor element ID to extract a reusable part.', 'mumcp' ),
				'error'
			);
			return;
		}

		$elementor = new Mcpwp_Elementor_Pro();
		$result    = $elementor->create_part_from_section(
			$page_id,
			$element_id,
			array(
				'title'      => $title,
				'part_kind'  => $kind,
				'part_style' => $style,
				'part_tags'  => $this->parse_tag_string( $tags_raw ),
			)
		);

		if ( is_wp_error( $result ) ) {
			add_settings_error(
				'mcpwp_messages',
				'mcpwp_library_extract_failed',
				$result->get_error_message(),
				'error'
			);
			return;
		}

		add_settings_error(
			'mcpwp_messages',
			'mcpwp_library_extract_saved',
			sprintf(
				/* translators: %s: part title */
				__( 'Extracted reusable part: %s', 'mumcp' ),
				isset( $result['title'] ) ? $result['title'] : ''
			),
			'updated'
		);
	}

	/**
	 * Parse a comma-separated tag string into a clean array.
	 *
	 * @param string $tags_raw Raw tag string.
	 * @return array
	 */
	private function parse_tag_string( $tags_raw ) {
		if ( '' === $tags_raw ) {
			return array();
		}

		$tags = array_map( 'trim', explode( ',', $tags_raw ) );
		$tags = array_filter( $tags, 'strlen' );

		return array_values( array_unique( $tags ) );
	}

	/**
	 * Parse a newline-separated list into a clean array.
	 *
	 * @param string $value Raw textarea value.
	 * @return array
	 */
	private function parse_line_list( $value ) {
		if ( '' === trim( $value ) ) {
			return array();
		}

		$lines = preg_split( '/\r\n|\r|\n/', (string) $value );
		$lines = array_map( 'trim', (array) $lines );
		$lines = array_filter( $lines, 'strlen' );

		return array_values( array_unique( $lines ) );
	}

	/**
	 * Create a new draft page from a saved archetype.
	 *
	 * @return void
	 */
	private function handle_create_page_from_archetype() {
		if ( ! class_exists( 'Mcpwp_Elementor_Pro' ) ) {
			$this->add_library_error_notice( 'Elementor Pro handler is not available.' );
			return;
		}

		$archetype_id = isset( $_POST['mcpwp_action_archetype_id'] ) ? absint( wp_unslash( $_POST['mcpwp_action_archetype_id'] ) ) : 0;
		if ( $archetype_id <= 0 ) {
			$this->add_library_error_notice( 'Enter a valid archetype ID.' );
			return;
		}

		$elementor = new Mcpwp_Elementor_Pro();
		$archetype = $elementor->get_archetype( $archetype_id );
		if ( is_wp_error( $archetype ) ) {
			$this->add_library_error_notice( $archetype->get_error_message() );
			return;
		}

		$title  = ! empty( $archetype['title'] ) ? $archetype['title'] . ' Draft' : 'Archetype Draft';
		$result = $elementor->create_landing_page(
			array(
				'title'       => $title,
				'status'      => 'draft',
				'template_id' => $archetype_id,
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->add_library_error_notice( $result->get_error_message() );
			return;
		}

		add_settings_error(
			'mcpwp_messages',
			'mcpwp_library_archetype_page_created',
			sprintf(
				/* translators: 1: page title 2: edit URL */
				__( 'Created draft page: %1$s. <a href="%2$s">Open in Elementor</a>.', 'mumcp' ),
				esc_html( isset( $result['title'] ) ? $result['title'] : '' ),
				esc_url( isset( $result['edit_url'] ) ? $result['edit_url'] : admin_url() )
			),
			'updated'
		);
	}

	/**
	 * Apply or insert a reusable part onto a target page.
	 *
	 * @return void
	 */
	private function handle_apply_part_to_page() {
		if ( ! class_exists( 'Mcpwp_Elementor_Pro' ) ) {
			$this->add_library_error_notice( 'Elementor Pro handler is not available.' );
			return;
		}

		$part_id  = isset( $_POST['mcpwp_action_part_id'] ) ? absint( wp_unslash( $_POST['mcpwp_action_part_id'] ) ) : 0;
		$page_id  = isset( $_POST['mcpwp_target_page_id'] ) ? absint( wp_unslash( $_POST['mcpwp_target_page_id'] ) ) : 0;
		$mode     = isset( $_POST['mcpwp_part_apply_mode'] ) ? sanitize_key( wp_unslash( $_POST['mcpwp_part_apply_mode'] ) ) : 'insert';
		$position = isset( $_POST['mcpwp_part_apply_position'] ) ? sanitize_text_field( wp_unslash( $_POST['mcpwp_part_apply_position'] ) ) : 'end';

		if ( $part_id <= 0 || $page_id <= 0 ) {
			$this->add_library_error_notice( 'Enter a valid reusable part ID and target page ID.' );
			return;
		}

		$elementor = new Mcpwp_Elementor_Pro();

		if ( 'replace' === $mode ) {
			$result = $elementor->apply_part_to_page( $part_id, $page_id );
		} else {
			if ( ! in_array( $position, array( 'start', 'end' ), true ) ) {
				$position = 'end';
			}
			$result = $elementor->insert_part_into_page( $part_id, $page_id, $position );
		}

		if ( is_wp_error( $result ) ) {
			$this->add_library_error_notice( $result->get_error_message() );
			return;
		}

		$page_title = get_the_title( $page_id );
		$page_edit  = admin_url( 'post.php?post=' . $page_id . '&action=edit' );

		add_settings_error(
			'mcpwp_messages',
			'mcpwp_library_part_applied',
			sprintf(
				/* translators: 1: page title 2: edit URL */
				__( 'Updated page: %1$s. <a href="%2$s">Open page</a>.', 'mumcp' ),
				esc_html( $page_title ? $page_title : '#' . $page_id ),
				esc_url( $page_edit )
			),
			'updated'
		);
	}

	/**
	 * Remove archetype metadata from a template.
	 *
	 * @return void
	 */
	private function handle_demote_archetype() {
		if ( ! class_exists( 'Mcpwp_Elementor_Pro' ) ) {
			$this->add_library_error_notice( 'Elementor Pro handler is not available.' );
			return;
		}

		$template_id = isset( $_POST['mcpwp_action_archetype_id'] ) ? absint( wp_unslash( $_POST['mcpwp_action_archetype_id'] ) ) : 0;
		if ( $template_id <= 0 ) {
			$this->add_library_error_notice( 'Enter a valid archetype ID.' );
			return;
		}

		$elementor = new Mcpwp_Elementor_Pro();
		$result    = $elementor->update_template(
			$template_id,
			array(
				'is_archetype'    => false,
				'archetype_scope' => '',
				'archetype_class' => '',
				'archetype_style' => '',
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->add_library_error_notice( $result->get_error_message() );
			return;
		}

		add_settings_error(
			'mcpwp_messages',
			'mcpwp_library_archetype_demoted',
			__( 'Archetype metadata removed from the template.', 'mumcp' ),
			'updated'
		);
	}

	/**
	 * Remove reusable part metadata from a template.
	 *
	 * @return void
	 */
	private function handle_demote_part() {
		if ( ! class_exists( 'Mcpwp_Elementor_Pro' ) ) {
			$this->add_library_error_notice( 'Elementor Pro handler is not available.' );
			return;
		}

		$template_id = isset( $_POST['mcpwp_action_part_id'] ) ? absint( wp_unslash( $_POST['mcpwp_action_part_id'] ) ) : 0;
		if ( $template_id <= 0 ) {
			$this->add_library_error_notice( 'Enter a valid part ID.' );
			return;
		}

		$elementor = new Mcpwp_Elementor_Pro();
		$result    = $elementor->update_template(
			$template_id,
			array(
				'is_part'           => false,
				'part_kind'         => '',
				'part_style'        => '',
				'part_tags'         => array(),
				'source_page_id'    => 0,
				'source_element_id' => '',
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->add_library_error_notice( $result->get_error_message() );
			return;
		}

		add_settings_error(
			'mcpwp_messages',
			'mcpwp_library_part_demoted',
			__( 'Reusable part metadata removed from the template.', 'mumcp' ),
			'updated'
		);
	}

	/**
	 * Create a new WooCommerce product archetype from admin.
	 *
	 * @return void
	 */
	private function handle_create_product_archetype() {
		$controller = $this->get_woocommerce_archetype_controller();
		if ( is_wp_error( $controller ) ) {
			$this->add_library_error_notice( $controller->get_error_message() );
			return;
		}

		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/woocommerce/archetypes' );
		$request->set_body_params(
			array(
				'name'              => isset( $_POST['mcpwp_product_archetype_name'] ) ? sanitize_text_field( wp_unslash( $_POST['mcpwp_product_archetype_name'] ) ) : '',
				'archetype_class'   => isset( $_POST['mcpwp_product_archetype_class'] ) ? sanitize_key( wp_unslash( $_POST['mcpwp_product_archetype_class'] ) ) : '',
				'archetype_style'   => isset( $_POST['mcpwp_product_archetype_style'] ) ? sanitize_text_field( wp_unslash( $_POST['mcpwp_product_archetype_style'] ) ) : '',
				'brief'             => isset( $_POST['mcpwp_product_archetype_brief'] ) ? sanitize_textarea_field( wp_unslash( $_POST['mcpwp_product_archetype_brief'] ) ) : '',
				'product_type'      => isset( $_POST['mcpwp_product_type'] ) ? sanitize_key( wp_unslash( $_POST['mcpwp_product_type'] ) ) : 'simple',
				'status'            => isset( $_POST['mcpwp_product_status'] ) ? sanitize_key( wp_unslash( $_POST['mcpwp_product_status'] ) ) : 'draft',
				'description'       => isset( $_POST['mcpwp_product_description'] ) ? wp_kses_post( wp_unslash( $_POST['mcpwp_product_description'] ) ) : '',
				'short_description' => isset( $_POST['mcpwp_product_short_description'] ) ? wp_kses_post( wp_unslash( $_POST['mcpwp_product_short_description'] ) ) : '',
				'regular_price'     => isset( $_POST['mcpwp_product_regular_price'] ) ? sanitize_text_field( wp_unslash( $_POST['mcpwp_product_regular_price'] ) ) : '',
				'sale_price'        => isset( $_POST['mcpwp_product_sale_price'] ) ? sanitize_text_field( wp_unslash( $_POST['mcpwp_product_sale_price'] ) ) : '',
				'stock_status'      => isset( $_POST['mcpwp_product_stock_status'] ) ? sanitize_key( wp_unslash( $_POST['mcpwp_product_stock_status'] ) ) : 'instock',
				'virtual'           => ! empty( $_POST['mcpwp_product_virtual'] ),
				'downloadable'      => ! empty( $_POST['mcpwp_product_downloadable'] ),
				'categories'        => $this->parse_tag_string( isset( $_POST['mcpwp_product_categories'] ) ? sanitize_text_field( wp_unslash( $_POST['mcpwp_product_categories'] ) ) : '' ),
				'tags'              => $this->parse_tag_string( isset( $_POST['mcpwp_product_tags'] ) ? sanitize_text_field( wp_unslash( $_POST['mcpwp_product_tags'] ) ) : '' ),
			)
		);

		$response = $controller->create_product_archetype( $request );
		if ( is_wp_error( $response ) ) {
			$this->add_library_error_notice( $response->get_error_message() );
			return;
		}

		$data = $response instanceof WP_REST_Response ? $response->get_data() : $response;

		add_settings_error(
			'mcpwp_messages',
			'mcpwp_library_product_archetype_created',
			sprintf(
				/* translators: %s: archetype name */
				__( 'Saved product archetype: %s', 'mumcp' ),
				isset( $data['name'] ) ? $data['name'] : __( 'Untitled archetype', 'mumcp' )
			),
			'updated'
		);
	}

	/**
	 * Create a draft WooCommerce product from a stored archetype.
	 *
	 * @return void
	 */
	private function handle_create_product_from_archetype() {
		$controller = $this->get_woocommerce_archetype_controller();
		if ( is_wp_error( $controller ) ) {
			$this->add_library_error_notice( $controller->get_error_message() );
			return;
		}

		$archetype_id = isset( $_POST['mcpwp_action_product_archetype_id'] ) ? absint( wp_unslash( $_POST['mcpwp_action_product_archetype_id'] ) ) : 0;
		$name         = isset( $_POST['mcpwp_product_name'] ) ? sanitize_text_field( wp_unslash( $_POST['mcpwp_product_name'] ) ) : '';
		if ( $archetype_id <= 0 ) {
			$this->add_library_error_notice( 'Enter a valid product archetype ID.' );
			return;
		}

		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/woocommerce/archetypes/' . $archetype_id . '/apply' );
		$request->set_param( 'id', $archetype_id );
		$request->set_body_params(
			array(
				'name'   => $name ? $name : 'Archetype Product Draft',
				'status' => 'draft',
			)
		);

		$response = $controller->apply_product_archetype( $request );
		if ( is_wp_error( $response ) ) {
			$this->add_library_error_notice( $response->get_error_message() );
			return;
		}

		$data       = $response instanceof WP_REST_Response ? $response->get_data() : $response;
		$product    = isset( $data['product'] ) && is_array( $data['product'] ) ? $data['product'] : array();
		$product_id = isset( $product['id'] ) ? absint( $product['id'] ) : 0;
		$edit_url   = $product_id ? admin_url( 'post.php?post=' . $product_id . '&action=edit' ) : admin_url( 'edit.php?post_type=product' );

		add_settings_error(
			'mcpwp_messages',
			'mcpwp_library_product_created',
			sprintf(
				/* translators: 1: product name 2: edit URL */
				__( 'Created draft product: %1$s. <a href="%2$s">Open product</a>.', 'mumcp' ),
				esc_html( isset( $product['name'] ) ? $product['name'] : __( 'New Product', 'mumcp' ) ),
				esc_url( $edit_url )
			),
			'updated'
		);
	}

	/**
	 * Delete a WooCommerce product archetype from the library.
	 *
	 * @return void
	 */
	private function handle_delete_product_archetype() {
		$controller = $this->get_woocommerce_archetype_controller();
		if ( is_wp_error( $controller ) ) {
			$this->add_library_error_notice( $controller->get_error_message() );
			return;
		}

		$archetype_id = isset( $_POST['mcpwp_action_product_archetype_id'] ) ? absint( wp_unslash( $_POST['mcpwp_action_product_archetype_id'] ) ) : 0;
		if ( $archetype_id <= 0 ) {
			$this->add_library_error_notice( 'Enter a valid product archetype ID.' );
			return;
		}

		$items = get_option( 'mcpwp_wc_product_archetypes', array() );
		if ( ! is_array( $items ) ) {
			$items = array();
		}

		$remaining = array_values(
			array_filter(
				$items,
				function ( $item ) use ( $archetype_id ) {
					return ! isset( $item['id'] ) || (int) $item['id'] !== $archetype_id;
				}
			)
		);

		update_option( 'mcpwp_wc_product_archetypes', $remaining, false );

		add_settings_error(
			'mcpwp_messages',
			'mcpwp_library_product_archetype_deleted',
			__( 'Product archetype removed from the library.', 'mumcp' ),
			'updated'
		);
	}

	/**
	 * Create a design reference from admin inputs.
	 *
	 * @return void
	 */
	private function handle_create_design_reference() {
		if ( ! class_exists( 'Mcpwp_Design_References' ) ) {
			$this->add_library_error_notice( 'Design reference library is not available.' );
			return;
		}

		$payload = array(
			'title'            => isset( $_POST['mcpwp_design_reference_title'] ) ? sanitize_text_field( wp_unslash( $_POST['mcpwp_design_reference_title'] ) ) : '',
			'image_url'        => isset( $_POST['mcpwp_design_reference_url'] ) ? esc_url_raw( wp_unslash( $_POST['mcpwp_design_reference_url'] ) ) : '',
			'media_id'         => isset( $_POST['mcpwp_design_reference_media_id'] ) ? absint( wp_unslash( $_POST['mcpwp_design_reference_media_id'] ) ) : 0,
			'page_intent'      => isset( $_POST['mcpwp_design_reference_intent'] ) ? sanitize_key( wp_unslash( $_POST['mcpwp_design_reference_intent'] ) ) : '',
			'archetype_class'  => isset( $_POST['mcpwp_design_reference_class'] ) ? sanitize_key( wp_unslash( $_POST['mcpwp_design_reference_class'] ) ) : '',
			'style'            => isset( $_POST['mcpwp_design_reference_style'] ) ? sanitize_text_field( wp_unslash( $_POST['mcpwp_design_reference_style'] ) ) : '',
			'notes'            => isset( $_POST['mcpwp_design_reference_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['mcpwp_design_reference_notes'] ) ) : '',
			'analysis_summary' => isset( $_POST['mcpwp_design_reference_summary'] ) ? sanitize_textarea_field( wp_unslash( $_POST['mcpwp_design_reference_summary'] ) ) : '',
			'tags'             => $this->parse_tag_string( isset( $_POST['mcpwp_design_reference_tags'] ) ? sanitize_text_field( wp_unslash( $_POST['mcpwp_design_reference_tags'] ) ) : '' ),
			'must_keep'        => $this->parse_line_list( isset( $_POST['mcpwp_design_reference_must_keep'] ) ? wp_unslash( $_POST['mcpwp_design_reference_must_keep'] ) : '' ),
			'avoid'            => $this->parse_line_list( isset( $_POST['mcpwp_design_reference_avoid'] ) ? wp_unslash( $_POST['mcpwp_design_reference_avoid'] ) : '' ),
			'section_outline'  => $this->parse_line_list( isset( $_POST['mcpwp_design_reference_outline'] ) ? wp_unslash( $_POST['mcpwp_design_reference_outline'] ) : '' ),
			'source_type'      => 'manual',
		);

		if ( ! empty( $_FILES['mcpwp_design_reference_file']['tmp_name'] ) ) {
			$media  = new Mcpwp_Media();
			$upload = $media->upload_file(
				$_FILES['mcpwp_design_reference_file'],
				array(
					'title' => $payload['title'],
					'alt'   => $payload['title'],
				)
			);

			if ( is_wp_error( $upload ) ) {
				$this->add_library_error_notice( $upload->get_error_message() );
				return;
			}

			$payload['media_id']    = isset( $upload['id'] ) ? absint( $upload['id'] ) : 0;
			$payload['image_url']   = '';
			$payload['source_type'] = 'upload';
		} elseif ( ! empty( $payload['image_url'] ) ) {
			$payload['source_type'] = 'url';
		} elseif ( ! empty( $payload['media_id'] ) ) {
			$payload['source_type'] = 'media';
		}

		$references = new Mcpwp_Design_References();
		$result     = $references->create_reference( $payload );

		if ( is_wp_error( $result ) ) {
			$this->add_library_error_notice( $result->get_error_message() );
			return;
		}

		add_settings_error(
			'mcpwp_messages',
			'mcpwp_library_design_reference_created',
			sprintf(
				/* translators: %s: design reference title */
				__( 'Saved design reference: %s', 'mumcp' ),
				isset( $result['title'] ) ? $result['title'] : __( 'Untitled reference', 'mumcp' )
			),
			'updated'
		);
	}

	/**
	 * Register a consistent library action error notice.
	 *
	 * @param string $message Error message.
	 * @return void
	 */
	private function add_library_error_notice( $message ) {
		add_settings_error(
			'mcpwp_messages',
			'mcpwp_library_action_failed',
			$message,
			'error'
		);
	}

	/**
	 * Create a new draft page from a saved design reference.
	 *
	 * @return void
	 */
	private function handle_create_page_from_design_reference() {
		if ( ! class_exists( 'Mcpwp_Design_References' ) ) {
			$this->add_library_error_notice( 'Design reference library is not available.' );
			return;
		}

		$reference_id = isset( $_POST['mcpwp_action_design_reference_id'] ) ? sanitize_key( wp_unslash( $_POST['mcpwp_action_design_reference_id'] ) ) : '';
		$page_title   = isset( $_POST['mcpwp_design_reference_page_title'] ) ? sanitize_text_field( wp_unslash( $_POST['mcpwp_design_reference_page_title'] ) ) : '';

		if ( '' === $reference_id ) {
			$this->add_library_error_notice( 'Enter a valid design reference ID.' );
			return;
		}

		$references = new Mcpwp_Design_References();
		$reference  = $references->get_reference( $reference_id );

		if ( is_wp_error( $reference ) ) {
			$this->add_library_error_notice( $reference->get_error_message() );
			return;
		}

		if ( '' === $page_title ) {
			$page_title = ! empty( $reference['title'] ) ? $reference['title'] . ' Draft' : 'Design Reference Draft';
		}

		$page_id  = 0;
		$edit_url = '';

		if ( class_exists( 'Mcpwp_Elementor_Pro' ) ) {
			$elementor = new Mcpwp_Elementor_Pro();
			$result    = $elementor->create_landing_page(
				array(
					'title'    => $page_title,
					'status'   => 'draft',
					'sections' => $this->build_design_reference_sections( $reference ),
				)
			);

			if ( ! is_wp_error( $result ) ) {
				$page_id  = isset( $result['id'] ) ? absint( $result['id'] ) : 0;
				$edit_url = isset( $result['edit_url'] ) ? (string) $result['edit_url'] : '';
			}
		}

		if ( $page_id <= 0 ) {
			$content_lines = array();
			if ( ! empty( $reference['analysis_summary'] ) ) {
				$content_lines[] = $reference['analysis_summary'];
			}
			if ( ! empty( $reference['section_outline'] ) && is_array( $reference['section_outline'] ) ) {
				$content_lines[] = '';
				$content_lines[] = 'Section outline:';
				foreach ( $reference['section_outline'] as $section ) {
					$content_lines[] = '- ' . $section;
				}
			}

			$page_id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'draft',
					'post_title'   => $page_title,
					'post_content' => implode( "\n", $content_lines ),
				),
				true
			);

			if ( is_wp_error( $page_id ) ) {
				$this->add_library_error_notice( $page_id->get_error_message() );
				return;
			}

			$page_id  = absint( $page_id );
			$edit_url = admin_url( 'post.php?post=' . $page_id . '&action=edit' );
		}

		update_post_meta( $page_id, '_mcpwp_design_reference_id', $reference_id );
		update_post_meta( $page_id, '_mcpwp_design_reference_title', isset( $reference['title'] ) ? (string) $reference['title'] : '' );
		update_post_meta( $page_id, '_mcpwp_design_reference_intent', isset( $reference['page_intent'] ) ? (string) $reference['page_intent'] : '' );
		update_post_meta( $page_id, '_mcpwp_design_reference_class', isset( $reference['archetype_class'] ) ? (string) $reference['archetype_class'] : '' );
		update_post_meta( $page_id, '_mcpwp_design_reference_style', isset( $reference['style'] ) ? (string) $reference['style'] : '' );
		update_post_meta( $page_id, '_mcpwp_design_reference_summary', isset( $reference['analysis_summary'] ) ? (string) $reference['analysis_summary'] : '' );
		update_post_meta( $page_id, '_mcpwp_design_reference_outline', isset( $reference['section_outline'] ) ? array_values( (array) $reference['section_outline'] ) : array() );
		update_post_meta( $page_id, '_mcpwp_design_reference_source_image', isset( $reference['image_url'] ) ? (string) $reference['image_url'] : '' );

		$linked_part_ids = $this->create_parts_from_design_reference_page( $page_id, $reference );
		if ( ! empty( $linked_part_ids ) ) {
			$existing_part_ids = isset( $reference['linked_part_ids'] ) && is_array( $reference['linked_part_ids'] ) ? $reference['linked_part_ids'] : array();
			$references->update_reference(
				$reference_id,
				array(
					'linked_part_ids' => array_values( array_unique( array_map( 'absint', array_merge( $existing_part_ids, $linked_part_ids ) ) ) ),
				)
			);
		}

		add_settings_error(
			'mcpwp_messages',
			'mcpwp_library_design_reference_page_created',
			sprintf(
				/* translators: 1: page title 2: edit URL 3: reusable part count */
				__( 'Created draft page from design reference: %1$s. <a href="%2$s">Open page</a>. Saved %3$d reusable parts.', 'mumcp' ),
				esc_html( get_the_title( $page_id ) ),
				esc_url( $edit_url ),
				count( $linked_part_ids )
			),
			'updated'
		);
	}

	/**
	 * Build starter Elementor section definitions from a design reference.
	 *
	 * @param array $reference Design reference payload.
	 * @return array
	 */
	private function build_design_reference_sections( $reference ) {
		$outline = isset( $reference['section_outline'] ) && is_array( $reference['section_outline'] ) && ! empty( $reference['section_outline'] )
			? array_values( $reference['section_outline'] )
			: array( 'hero', 'content', 'cta' );

		$summary      = isset( $reference['analysis_summary'] ) ? trim( (string) $reference['analysis_summary'] ) : '';
		$style        = isset( $reference['style'] ) ? trim( (string) $reference['style'] ) : '';
		$must_keep    = isset( $reference['must_keep'] ) && is_array( $reference['must_keep'] ) ? $reference['must_keep'] : array();
		$sections     = array();
		$total        = count( $outline );
		$primary_cta  = 'Get Started';

		foreach ( $outline as $index => $section_name ) {
			$label   = $this->humanize_design_reference_label( $section_name );
			$is_hero = ( 0 === $index ) || false !== strpos( strtolower( $section_name ), 'hero' );
			$is_cta  = false !== strpos( strtolower( $section_name ), 'cta' );

			$widgets = array();
			$widgets[] = array(
				'type'     => 'heading',
				'settings' => array(
					'title' => $label,
					'size'  => $is_hero ? 'xxl' : 'xl',
				),
			);

			$text_lines = array();
			if ( $is_hero && '' !== $summary ) {
				$text_lines[] = $summary;
			} else {
				$text_lines[] = sprintf( 'Starter block for %s based on the saved design reference.', strtolower( $label ) );
			}

			if ( ! empty( $must_keep ) ) {
				$text_lines[] = 'Keep: ' . implode( ', ', array_slice( $must_keep, 0, 3 ) );
			}

			if ( '' !== $style ) {
				$text_lines[] = 'Style: ' . $style;
			}

			$widgets[] = array(
				'type'     => 'text-editor',
				'settings' => array(
					'editor' => implode( "\n\n", array_filter( $text_lines, 'strlen' ) ),
				),
			);

			if ( $is_hero || $is_cta || ( $total - 1 ) === $index ) {
				$widgets[] = array(
					'type'     => 'button',
					'settings' => array(
						'text' => $is_cta ? 'Request Demo' : $primary_cta,
						'size' => 'md',
					),
				);
			}

			$sections[] = array(
				'settings' => array(
					'padding' => array(
						'unit'   => 'px',
						'top'    => $is_hero ? 96 : 72,
						'right'  => 24,
						'bottom' => $is_hero ? 96 : 72,
						'left'   => 24,
						'isLinked' => false,
					),
				),
				'columns'  => array(
					array(
						'widgets' => $widgets,
					),
				),
			);
		}

		return $sections;
	}

	/**
	 * Convert a design reference section label into a readable heading.
	 *
	 * @param string $label Raw label.
	 * @return string
	 */
	private function humanize_design_reference_label( $label ) {
		$label = str_replace( array( '_', '-' ), ' ', (string) $label );
		$label = preg_replace( '/\s+/', ' ', $label );
		$label = trim( $label );

		if ( '' === $label ) {
			return 'Section';
		}

		return ucwords( $label );
	}

	/**
	 * Save top-level generated sections back into the reusable parts library.
	 *
	 * @param int   $page_id    Draft page ID.
	 * @param array $reference  Design reference payload.
	 * @return array
	 */
	private function create_parts_from_design_reference_page( $page_id, $reference ) {
		if ( ! class_exists( 'Mcpwp_Elementor_Pro' ) ) {
			return array();
		}

		$raw_elements = get_post_meta( $page_id, '_elementor_data', true );
		$elements     = json_decode( $raw_elements, true );
		if ( ! is_array( $elements ) || empty( $elements ) ) {
			return array();
		}

		$outline   = isset( $reference['section_outline'] ) && is_array( $reference['section_outline'] ) ? array_values( $reference['section_outline'] ) : array();
		$style     = isset( $reference['style'] ) ? sanitize_text_field( (string) $reference['style'] ) : '';
		$base_tags = array_filter(
			array_merge(
				array( 'design-reference', 'starter' ),
				isset( $reference['tags'] ) && is_array( $reference['tags'] ) ? $reference['tags'] : array()
			),
			'strlen'
		);
		$reference_title = isset( $reference['title'] ) ? (string) $reference['title'] : 'Design Reference';
		$elementor       = new Mcpwp_Elementor_Pro();
		$part_ids        = array();

		foreach ( array_values( $elements ) as $index => $element ) {
			if ( empty( $element['id'] ) ) {
				continue;
			}

			$section_key = isset( $outline[ $index ] ) ? (string) $outline[ $index ] : 'section';
			$part_title  = sprintf(
				'%s / %s',
				$reference_title,
				$this->humanize_design_reference_label( $section_key )
			);

			$result = $elementor->create_part_from_section(
				$page_id,
				(string) $element['id'],
				array(
					'title'      => $part_title,
					'part_kind'  => sanitize_key( $section_key ),
					'part_style' => $style,
					'part_tags'  => array_values( array_unique( array_merge( $base_tags, array( sanitize_key( $section_key ) ) ) ) ),
				)
			);

			if ( is_wp_error( $result ) ) {
				continue;
			}

			if ( ! empty( $result['id'] ) ) {
				$part_ids[] = absint( $result['id'] );
			}
		}

		return array_values( array_unique( array_filter( $part_ids ) ) );
	}

	/**
	 * Get reusable library inventory for admin display.
	 *
	 * @return array
	 */
	public function get_library_inventory() {
		return array(
			'parts'              => $this->get_elementor_parts_inventory(),
			'page_archetypes'    => $this->get_elementor_archetypes_inventory( 'page' ),
			'product_archetypes' => $this->get_product_archetypes_inventory(),
			'design_references'  => $this->get_design_references_inventory(),
			'site_blueprints'    => class_exists( 'Mcpwp_Site_Blueprints' ) ? Mcpwp_Site_Blueprints::list_all() : array(),
		);
	}

	/**
	 * Get normalized library filter values from the request.
	 *
	 * @return array
	 */
	public function get_library_filters() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin filtering.
		$search = isset( $_GET['library_search'] ) ? sanitize_text_field( wp_unslash( $_GET['library_search'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin filtering.
		$asset_type = isset( $_GET['library_asset_type'] ) ? sanitize_key( wp_unslash( $_GET['library_asset_type'] ) ) : 'all';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin filtering.
		$class_filter = isset( $_GET['library_class'] ) ? sanitize_key( wp_unslash( $_GET['library_class'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin filtering.
		$style_filter = isset( $_GET['library_style'] ) ? sanitize_text_field( wp_unslash( $_GET['library_style'] ) ) : '';

		if ( ! in_array( $asset_type, array( 'all', 'archetypes', 'products', 'parts', 'references' ), true ) ) {
			$asset_type = 'all';
		}

		return array(
			'search'      => $search,
			'asset_type'  => $asset_type,
			'class'       => $class_filter,
			'style'       => $style_filter,
		);
	}

	/**
	 * Apply library filters to the inventory.
	 *
	 * @param array $inventory Inventory arrays.
	 * @param array $filters   Filter values.
	 * @return array
	 */
	public function filter_library_inventory( $inventory, $filters ) {
		$inventory = is_array( $inventory ) ? $inventory : array();
		$filters   = is_array( $filters ) ? $filters : array();

		foreach ( array( 'parts', 'page_archetypes', 'product_archetypes', 'design_references' ) as $key ) {
			$items = isset( $inventory[ $key ] ) && is_array( $inventory[ $key ] ) ? $inventory[ $key ] : array();
			$inventory[ $key ] = array_values(
				array_filter(
					$items,
					function ( $item ) use ( $key, $filters ) {
						return $this->library_item_matches_filters( $item, $key, $filters );
					}
				)
			);
		}

		if ( isset( $filters['asset_type'] ) ) {
			if ( 'archetypes' === $filters['asset_type'] ) {
				$inventory['product_archetypes'] = array();
				$inventory['parts']              = array();
				$inventory['design_references']  = array();
			} elseif ( 'products' === $filters['asset_type'] ) {
				$inventory['page_archetypes']   = array();
				$inventory['parts']             = array();
				$inventory['design_references'] = array();
			} elseif ( 'parts' === $filters['asset_type'] ) {
				$inventory['page_archetypes']    = array();
				$inventory['product_archetypes'] = array();
				$inventory['design_references']  = array();
			} elseif ( 'references' === $filters['asset_type'] ) {
				$inventory['page_archetypes']    = array();
				$inventory['product_archetypes'] = array();
				$inventory['parts']              = array();
			}
		}

		return $inventory;
	}

	/**
	 * Get unique filter options derived from current inventory.
	 *
	 * @param array $inventory Inventory arrays.
	 * @return array
	 */
	public function get_library_filter_options( $inventory ) {
		$options = array(
			'classes' => array(),
			'styles'  => array(),
		);

		foreach ( array( 'page_archetypes', 'product_archetypes', 'parts', 'design_references' ) as $bucket ) {
			$items = isset( $inventory[ $bucket ] ) && is_array( $inventory[ $bucket ] ) ? $inventory[ $bucket ] : array();
			foreach ( $items as $item ) {
				$class_value = '';
				if ( isset( $item['archetype_class'] ) ) {
					$class_value = (string) $item['archetype_class'];
				} elseif ( isset( $item['part_kind'] ) ) {
					$class_value = (string) $item['part_kind'];
				} elseif ( isset( $item['page_intent'] ) ) {
					$class_value = (string) $item['page_intent'];
				}

				$style_value = '';
				if ( isset( $item['archetype_style'] ) ) {
					$style_value = (string) $item['archetype_style'];
				} elseif ( isset( $item['part_style'] ) ) {
					$style_value = (string) $item['part_style'];
				} elseif ( isset( $item['style'] ) ) {
					$style_value = (string) $item['style'];
				}

				if ( '' !== $class_value ) {
					$options['classes'][ $class_value ] = $class_value;
				}

				if ( '' !== $style_value ) {
					$options['styles'][ $style_value ] = $style_value;
				}
			}
		}

		ksort( $options['classes'] );
		natcasesort( $options['styles'] );

		return $options;
	}

	/**
	 * Determine whether one library item matches the active filters.
	 *
	 * @param array  $item    Item data.
	 * @param string $bucket  Inventory bucket.
	 * @param array  $filters Filter values.
	 * @return bool
	 */
	private function library_item_matches_filters( $item, $bucket, $filters ) {
		if ( ! is_array( $item ) ) {
			return false;
		}

		$search = isset( $filters['search'] ) ? strtolower( (string) $filters['search'] ) : '';
		if ( '' !== $search ) {
			$haystack_parts = array();
			foreach ( array( 'title', 'name', 'archetype_class', 'archetype_style', 'part_kind', 'part_style', 'product_type', 'source_page_title', 'page_intent', 'style', 'source_type', 'analysis_summary', 'notes' ) as $field ) {
				if ( ! empty( $item[ $field ] ) ) {
					$haystack_parts[] = (string) $item[ $field ];
				}
			}
			if ( ! empty( $item['part_tags'] ) && is_array( $item['part_tags'] ) ) {
				$haystack_parts[] = implode( ' ', $item['part_tags'] );
			}
			if ( ! empty( $item['tags'] ) && is_array( $item['tags'] ) ) {
				$haystack_parts[] = implode( ' ', $item['tags'] );
			}
			if ( ! empty( $item['must_keep'] ) && is_array( $item['must_keep'] ) ) {
				$haystack_parts[] = implode( ' ', $item['must_keep'] );
			}
			if ( ! empty( $item['avoid'] ) && is_array( $item['avoid'] ) ) {
				$haystack_parts[] = implode( ' ', $item['avoid'] );
			}
			if ( ! empty( $item['section_outline'] ) && is_array( $item['section_outline'] ) ) {
				$haystack_parts[] = implode( ' ', $item['section_outline'] );
			}
			$haystack = strtolower( implode( ' ', $haystack_parts ) );
			if ( false === strpos( $haystack, $search ) ) {
				return false;
			}
		}

		$class_filter = isset( $filters['class'] ) ? (string) $filters['class'] : '';
		if ( '' !== $class_filter ) {
			$item_class = '';
			if ( 'parts' === $bucket ) {
				$item_class = isset( $item['part_kind'] ) ? (string) $item['part_kind'] : '';
			} elseif ( 'design_references' === $bucket ) {
				$item_class = isset( $item['archetype_class'] ) && '' !== (string) $item['archetype_class']
					? (string) $item['archetype_class']
					: ( isset( $item['page_intent'] ) ? (string) $item['page_intent'] : '' );
			} else {
				$item_class = isset( $item['archetype_class'] ) ? (string) $item['archetype_class'] : '';
			}
			if ( $class_filter !== $item_class ) {
				return false;
			}
		}

		$style_filter = isset( $filters['style'] ) ? (string) $filters['style'] : '';
		if ( '' !== $style_filter ) {
			$item_style = '';
			if ( 'parts' === $bucket ) {
				$item_style = isset( $item['part_style'] ) ? (string) $item['part_style'] : '';
			} elseif ( 'design_references' === $bucket ) {
				$item_style = isset( $item['style'] ) ? (string) $item['style'] : '';
			} else {
				$item_style = isset( $item['archetype_style'] ) ? (string) $item['archetype_style'] : '';
			}
			if ( $style_filter !== $item_style ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get Elementor reusable parts inventory.
	 *
	 * @return array
	 */
	private function get_elementor_parts_inventory() {
		if ( ! class_exists( 'Mcpwp_Elementor_Pro' ) ) {
			return array();
		}

		$elementor = new Mcpwp_Elementor_Pro();
		$parts     = $elementor->get_parts(
			array(
				'posts_per_page' => 100,
			)
		);

		if ( ! is_array( $parts ) ) {
			return array();
		}

		return array_map(
			array( $this, 'format_library_part_item' ),
			$parts
		);
	}

	/**
	 * Get Elementor archetype inventory filtered by scope.
	 *
	 * @param string $scope Archetype scope.
	 * @return array
	 */
	private function get_elementor_archetypes_inventory( $scope ) {
		if ( ! class_exists( 'Mcpwp_Elementor_Pro' ) ) {
			return array();
		}

		$elementor  = new Mcpwp_Elementor_Pro();
		$archetypes = $elementor->get_archetypes(
			array(
				'scope'          => $scope,
				'posts_per_page' => 100,
			)
		);

		if ( ! is_array( $archetypes ) ) {
			return array();
		}

		return array_map(
			array( $this, 'format_library_archetype_item' ),
			$archetypes
		);
	}

	/**
	 * Get WooCommerce product archetype inventory.
	 *
	 * @return array
	 */
	private function get_product_archetypes_inventory() {
		$archetypes = get_option( 'mcpwp_wc_product_archetypes', array() );
		if ( ! is_array( $archetypes ) ) {
			return array();
		}

		$result = array();
		foreach ( $archetypes as $archetype ) {
			if ( ! is_array( $archetype ) ) {
				continue;
			}

			$product_data = isset( $archetype['product_data'] ) && is_array( $archetype['product_data'] )
				? $archetype['product_data']
				: array();

			$result[] = array(
				'id'              => isset( $archetype['id'] ) ? absint( $archetype['id'] ) : 0,
				'name'            => isset( $archetype['name'] ) ? (string) $archetype['name'] : '',
				'archetype_class' => isset( $archetype['archetype_class'] ) ? (string) $archetype['archetype_class'] : '',
				'archetype_style' => isset( $archetype['archetype_style'] ) ? (string) $archetype['archetype_style'] : '',
				'brief'           => isset( $archetype['brief'] ) ? (string) $archetype['brief'] : '',
				'product_type'    => isset( $archetype['product_type'] ) ? (string) $archetype['product_type'] : '',
				'status'          => isset( $product_data['status'] ) ? (string) $product_data['status'] : '',
				'updated_at'      => isset( $archetype['updated_at'] ) ? (string) $archetype['updated_at'] : '',
			);
		}

		return $result;
	}

	/**
	 * Get design reference inventory.
	 *
	 * @return array
	 */
	private function get_design_references_inventory() {
		if ( ! class_exists( 'Mcpwp_Design_References' ) ) {
			return array();
		}

		$references = new Mcpwp_Design_References();
		$result     = $references->list_references(
			array(
				'per_page' => 100,
				'page'     => 1,
			)
		);

		$items = isset( $result['references'] ) && is_array( $result['references'] ) ? $result['references'] : array();

		return array_map(
			array( $this, 'format_library_design_reference_item' ),
			$items
		);
	}

	/**
	 * Format a reusable Elementor part for admin display.
	 *
	 * @param array $part Part data.
	 * @return array
	 */
	private function format_library_part_item( $part ) {
		$source_page_id = ! empty( $part['source_page_id'] ) ? absint( $part['source_page_id'] ) : 0;
		$part_id        = isset( $part['id'] ) ? absint( $part['id'] ) : 0;
		$linked_refs    = $part_id ? $this->get_design_reference_links_for_asset( $part_id, 'part' ) : array();

		return array(
			'id'                => $part_id,
			'title'             => isset( $part['title'] ) ? (string) $part['title'] : '',
			'type'              => isset( $part['type'] ) ? (string) $part['type'] : '',
			'part_kind'         => isset( $part['part_kind'] ) ? (string) $part['part_kind'] : '',
			'part_style'        => isset( $part['part_style'] ) ? (string) $part['part_style'] : '',
			'part_tags'         => isset( $part['part_tags'] ) && is_array( $part['part_tags'] ) ? $part['part_tags'] : array(),
			'source_page_id'    => $source_page_id,
			'source_page_title' => $source_page_id ? get_the_title( $source_page_id ) : '',
			'provenance_label'  => $source_page_id ? 'Elementor template from live page' : 'Elementor template',
			'reference_count'   => count( $linked_refs ),
			'linked_references' => $linked_refs,
			'edit_url'          => isset( $part['edit_url'] ) ? (string) $part['edit_url'] : '',
			'modified'          => isset( $part['modified'] ) ? (string) $part['modified'] : '',
		);
	}

	/**
	 * Format an Elementor archetype for admin display.
	 *
	 * @param array $archetype Archetype data.
	 * @return array
	 */
	private function format_library_archetype_item( $archetype ) {
		$archetype_id = isset( $archetype['id'] ) ? absint( $archetype['id'] ) : 0;
		$linked_refs  = $archetype_id ? $this->get_design_reference_links_for_asset( $archetype_id, 'archetype' ) : array();

		return array(
			'id'              => $archetype_id,
			'title'           => isset( $archetype['title'] ) ? (string) $archetype['title'] : '',
			'type'            => isset( $archetype['type'] ) ? (string) $archetype['type'] : '',
			'archetype_scope' => isset( $archetype['archetype_scope'] ) ? (string) $archetype['archetype_scope'] : '',
			'archetype_class' => isset( $archetype['archetype_class'] ) ? (string) $archetype['archetype_class'] : '',
			'archetype_style' => isset( $archetype['archetype_style'] ) ? (string) $archetype['archetype_style'] : '',
			'provenance_label'=> 'Elementor template with archetype metadata',
			'reference_count' => count( $linked_refs ),
			'linked_references' => $linked_refs,
			'edit_url'        => isset( $archetype['edit_url'] ) ? (string) $archetype['edit_url'] : '',
			'modified'        => isset( $archetype['modified'] ) ? (string) $archetype['modified'] : '',
		);
	}

	/**
	 * Format a design reference for admin display.
	 *
	 * @param array $reference Design reference data.
	 * @return array
	 */
	private function format_library_design_reference_item( $reference ) {
		$reference_id = isset( $reference['id'] ) ? (string) $reference['id'] : '';
		$linked_pages = $reference_id ? $this->get_pages_for_design_reference( $reference_id ) : array();

		return array(
			'id'                 => $reference_id,
			'title'              => isset( $reference['title'] ) ? (string) $reference['title'] : '',
			'media_id'           => isset( $reference['media_id'] ) ? absint( $reference['media_id'] ) : 0,
			'image_url'          => isset( $reference['image_url'] ) ? (string) $reference['image_url'] : '',
			'page_intent'        => isset( $reference['page_intent'] ) ? (string) $reference['page_intent'] : '',
			'archetype_class'    => isset( $reference['archetype_class'] ) ? (string) $reference['archetype_class'] : '',
			'style'              => isset( $reference['style'] ) ? (string) $reference['style'] : '',
			'source_type'        => isset( $reference['source_type'] ) ? (string) $reference['source_type'] : '',
			'notes'              => isset( $reference['notes'] ) ? (string) $reference['notes'] : '',
			'analysis_summary'   => isset( $reference['analysis_summary'] ) ? (string) $reference['analysis_summary'] : '',
			'tags'               => isset( $reference['tags'] ) && is_array( $reference['tags'] ) ? $reference['tags'] : array(),
			'must_keep'          => isset( $reference['must_keep'] ) && is_array( $reference['must_keep'] ) ? $reference['must_keep'] : array(),
			'avoid'              => isset( $reference['avoid'] ) && is_array( $reference['avoid'] ) ? $reference['avoid'] : array(),
			'section_outline'    => isset( $reference['section_outline'] ) && is_array( $reference['section_outline'] ) ? $reference['section_outline'] : array(),
			'linked_archetype_ids' => isset( $reference['linked_archetype_ids'] ) && is_array( $reference['linked_archetype_ids'] ) ? $reference['linked_archetype_ids'] : array(),
			'linked_part_ids'    => isset( $reference['linked_part_ids'] ) && is_array( $reference['linked_part_ids'] ) ? $reference['linked_part_ids'] : array(),
			'linked_archetype_count' => isset( $reference['linked_archetype_ids'] ) && is_array( $reference['linked_archetype_ids'] ) ? count( $reference['linked_archetype_ids'] ) : 0,
			'linked_part_count' => isset( $reference['linked_part_ids'] ) && is_array( $reference['linked_part_ids'] ) ? count( $reference['linked_part_ids'] ) : 0,
			'page_count'         => count( $linked_pages ),
			'linked_pages'       => $linked_pages,
			'updated_at'         => isset( $reference['updated_at'] ) ? (string) $reference['updated_at'] : '',
		);
	}

	/**
	 * Count draft/pages linked to a design reference.
	 *
	 * @param string $reference_id Reference ID.
	 * @return int
	 */
	private function count_pages_for_design_reference( $reference_id ) {
		global $wpdb;

		$reference_id = (string) $reference_id;
		if ( '' === $reference_id ) {
			return 0;
		}

		$postmeta = $wpdb->postmeta;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- lightweight admin inventory query.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$postmeta} WHERE meta_key = %s AND meta_value = %s",
				'_mcpwp_design_reference_id',
				$reference_id
			)
		);

		return absint( $count );
	}

	/**
	 * Get page links for a design reference.
	 *
	 * @param string $reference_id Reference ID.
	 * @return array
	 */
	private function get_pages_for_design_reference( $reference_id ) {
		global $wpdb;

		$reference_id = (string) $reference_id;
		if ( '' === $reference_id ) {
			return array();
		}

		$postmeta = $wpdb->postmeta;
		$posts    = $wpdb->posts;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- lightweight admin inventory query.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title
				FROM {$posts} p
				INNER JOIN {$postmeta} pm ON p.ID = pm.post_id
				WHERE pm.meta_key = %s AND pm.meta_value = %s
				ORDER BY p.ID DESC",
				'_mcpwp_design_reference_id',
				$reference_id
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map(
			static function ( $row ) {
				$page_id = isset( $row['ID'] ) ? absint( $row['ID'] ) : 0;
				return array(
					'id'    => $page_id,
					'title' => isset( $row['post_title'] ) ? (string) $row['post_title'] : '',
					'url'   => $page_id ? admin_url( 'post.php?post=' . $page_id . '&action=edit' ) : '',
				);
			},
			$rows
		);
	}

	/**
	 * Count design references linking to a given part or archetype.
	 *
	 * @param int    $asset_id Asset post ID.
	 * @param string $type     Asset type: part or archetype.
	 * @return int
	 */
	private function count_design_references_linking_asset( $asset_id, $type ) {
		return count( $this->get_design_reference_links_for_asset( $asset_id, $type ) );
	}

	/**
	 * Get design reference links for a given part or archetype.
	 *
	 * @param int    $asset_id Asset post ID.
	 * @param string $type     Asset type: part or archetype.
	 * @return array
	 */
	private function get_design_reference_links_for_asset( $asset_id, $type ) {
		$asset_id   = absint( $asset_id );
		$type       = 'archetype' === $type ? 'archetype' : 'part';
		$option_key = 'archetype' === $type ? 'linked_archetype_ids' : 'linked_part_ids';

		if ( $asset_id <= 0 ) {
			return array();
		}

		$references = get_option( 'mcpwp_design_references', array() );
		if ( ! is_array( $references ) ) {
			return array();
		}

		$results = array();
		foreach ( $references as $reference ) {
			$ids = isset( $reference[ $option_key ] ) && is_array( $reference[ $option_key ] ) ? array_map( 'absint', $reference[ $option_key ] ) : array();
			if ( in_array( $asset_id, $ids, true ) ) {
				$reference_id = isset( $reference['id'] ) ? (string) $reference['id'] : '';
				$results[]    = array(
					'id'    => $reference_id,
					'title' => isset( $reference['title'] ) ? (string) $reference['title'] : '',
					'url'   => admin_url( 'admin.php?page=' . self::LIBRARY_PAGE_SLUG . '&library_asset_type=references&library_search=' . rawurlencode( $reference_id ) ),
				);
			}
		}

		return $results;
	}
}
