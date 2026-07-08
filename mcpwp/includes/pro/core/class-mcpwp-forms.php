<?php
/**
 * Forms Handler
 *
 * @package MCPWP_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Forms functionality.
 *
 * Provides unified interface for CF7, WPForms, Gravity Forms, and Ninja Forms.
 */
class Mcpwp_Forms {

	/**
	 * Check if Contact Form 7 is active.
	 *
	 * @return bool
	 */
	public function is_cf7_active() {
		return defined( 'WPCF7_VERSION' );
	}

	/**
	 * Check if WPForms is active.
	 *
	 * @return bool
	 */
	public function is_wpforms_active() {
		return defined( 'WPFORMS_VERSION' );
	}

	/**
	 * Check if Gravity Forms is active.
	 *
	 * @return bool
	 */
	public function is_gravityforms_active() {
		return class_exists( 'GFForms' );
	}

	/**
	 * Check if Ninja Forms is active.
	 *
	 * @return bool
	 */
	public function is_ninjaforms_active() {
		return class_exists( 'Ninja_Forms' );
	}

	/**
	 * Get forms status.
	 *
	 * @return array Status information.
	 */
	public function get_status() {
		return array(
			'plugins' => array(
				'cf7' => array(
					'active'  => $this->is_cf7_active(),
					'version' => $this->is_cf7_active() ? WPCF7_VERSION : null,
				),
				'wpforms' => array(
					'active'  => $this->is_wpforms_active(),
					'version' => $this->is_wpforms_active() ? WPFORMS_VERSION : null,
				),
				'gravityforms' => array(
					'active'  => $this->is_gravityforms_active(),
					'version' => $this->is_gravityforms_active() && method_exists( 'GFForms', 'version' ) ? GFForms::$version : null,
				),
				'ninjaforms' => array(
					'active'  => $this->is_ninjaforms_active(),
					'version' => $this->is_ninjaforms_active() && defined( 'Ninja_Forms::VERSION' ) ? Ninja_Forms::VERSION : null,
				),
			),
		);
	}

	/**
	 * Get all forms from all plugins.
	 *
	 * @param array $args Query arguments.
	 * @return array Forms list.
	 */
	public function get_all_forms( $args = array() ) {
		$forms = array();

		if ( $this->is_cf7_active() ) {
			$forms['cf7'] = $this->get_cf7_forms( $args );
		}

		if ( $this->is_wpforms_active() ) {
			$forms['wpforms'] = $this->get_wpforms_forms( $args );
		}

		if ( $this->is_gravityforms_active() ) {
			$forms['gravityforms'] = $this->get_gravityforms_forms( $args );
		}

		if ( $this->is_ninjaforms_active() ) {
			$forms['ninjaforms'] = $this->get_ninjaforms_forms( $args );
		}

		return $forms;
	}

	/**
	 * Get forms by plugin type.
	 *
	 * @param string $plugin Plugin identifier.
	 * @param array  $args   Query arguments.
	 * @return array|WP_Error Forms list or error.
	 */
	public function get_forms( $plugin, $args = array() ) {
		switch ( $plugin ) {
			case 'cf7':
				if ( ! $this->is_cf7_active() ) {
					return new WP_Error( 'plugin_inactive', __( 'Contact Form 7 is not active.', 'mumcp' ) );
				}
				return $this->get_cf7_forms( $args );

			case 'wpforms':
				if ( ! $this->is_wpforms_active() ) {
					return new WP_Error( 'plugin_inactive', __( 'WPForms is not active.', 'mumcp' ) );
				}
				return $this->get_wpforms_forms( $args );

			case 'gravityforms':
				if ( ! $this->is_gravityforms_active() ) {
					return new WP_Error( 'plugin_inactive', __( 'Gravity Forms is not active.', 'mumcp' ) );
				}
				return $this->get_gravityforms_forms( $args );

			case 'ninjaforms':
				if ( ! $this->is_ninjaforms_active() ) {
					return new WP_Error( 'plugin_inactive', __( 'Ninja Forms is not active.', 'mumcp' ) );
				}
				return $this->get_ninjaforms_forms( $args );

			default:
				return new WP_Error( 'invalid_plugin', __( 'Invalid forms plugin.', 'mumcp' ) );
		}
	}

	/**
	 * Get single form.
	 *
	 * @param string $plugin  Plugin identifier.
	 * @param int    $form_id Form ID.
	 * @return array|WP_Error Form data or error.
	 */
	public function get_form( $plugin, $form_id ) {
		switch ( $plugin ) {
			case 'cf7':
				return $this->get_cf7_form( $form_id );

			case 'wpforms':
				return $this->get_wpforms_form( $form_id );

			case 'gravityforms':
				return $this->get_gravityforms_form( $form_id );

			case 'ninjaforms':
				return $this->get_ninjaforms_form( $form_id );

			default:
				return new WP_Error( 'invalid_plugin', __( 'Invalid forms plugin.', 'mumcp' ) );
		}
	}

	/**
	 * Get form entries/submissions.
	 *
	 * @param string $plugin  Plugin identifier.
	 * @param int    $form_id Form ID.
	 * @param array  $args    Query arguments.
	 * @return array|WP_Error Entries or error.
	 */
	public function get_entries( $plugin, $form_id, $args = array() ) {
		switch ( $plugin ) {
			case 'cf7':
				return $this->get_cf7_entries( $form_id, $args );

			case 'wpforms':
				return $this->get_wpforms_entries( $form_id, $args );

			case 'gravityforms':
				return $this->get_gravityforms_entries( $form_id, $args );

			case 'ninjaforms':
				return $this->get_ninjaforms_entries( $form_id, $args );

			default:
				return new WP_Error( 'invalid_plugin', __( 'Invalid forms plugin.', 'mumcp' ) );
		}
	}

	// =========================================================================
	// Contact Form 7
	// =========================================================================

	/**
	 * Get CF7 forms.
	 *
	 * @param array $args Query arguments.
	 * @return array Forms list.
	 */
	private function get_cf7_forms( $args = array() ) {
		if ( ! $this->is_cf7_active() ) {
			return array();
		}

		$forms = array();
		$cf7_forms = WPCF7_ContactForm::find( array(
			'posts_per_page' => isset( $args['per_page'] ) ? absint( $args['per_page'] ) : 50,
		) );

		foreach ( $cf7_forms as $form ) {
			$forms[] = array(
				'id'        => $form->id(),
				'title'     => $form->title(),
				'shortcode' => $form->shortcode(),
				'locale'    => $form->locale(),
			);
		}

		return $forms;
	}

	/**
	 * Get single CF7 form.
	 *
	 * @param int $form_id Form ID.
	 * @return array|WP_Error Form data.
	 */
	private function get_cf7_form( $form_id ) {
		if ( ! $this->is_cf7_active() ) {
			return new WP_Error( 'plugin_inactive', __( 'Contact Form 7 is not active.', 'mumcp' ) );
		}

		$form = WPCF7_ContactForm::get_instance( $form_id );

		if ( ! $form ) {
			return new WP_Error( 'not_found', __( 'Form not found.', 'mumcp' ) );
		}

		$properties = $form->get_properties();

		return array(
			'id'           => $form->id(),
			'title'        => $form->title(),
			'shortcode'    => $form->shortcode(),
			'locale'       => $form->locale(),
			'form_content' => $properties['form'],
			'mail'         => $properties['mail'],
			'mail_2'       => $properties['mail_2'],
			'messages'     => $properties['messages'],
			'additional_settings' => $properties['additional_settings'],
		);
	}

	/**
	 * Get CF7 entries (requires Flamingo plugin).
	 *
	 * @param int   $form_id Form ID.
	 * @param array $args    Query arguments.
	 * @return array|WP_Error Entries or notice.
	 */
	private function get_cf7_entries( $form_id, $args = array() ) {
		// CF7 doesn't store entries by default - requires Flamingo plugin.
		if ( ! class_exists( 'Flamingo_Inbound_Message' ) ) {
			return array(
				'notice'  => __( 'Contact Form 7 requires the Flamingo plugin to store and retrieve form entries.', 'mumcp' ),
				'entries' => array(),
			);
		}

		$messages = Flamingo_Inbound_Message::find( array(
			'channel' => 'contact-form-7',
			'posts_per_page' => isset( $args['per_page'] ) ? absint( $args['per_page'] ) : 50,
		) );

		$entries = array();
		foreach ( $messages as $message ) {
			$entries[] = array(
				'id'        => $message->id(),
				'subject'   => $message->subject,
				'from'      => $message->from,
				'from_name' => $message->from_name,
				'from_email' => $message->from_email,
				'fields'    => $message->fields,
				'meta'      => $message->meta,
				'date'      => $message->date,
			);
		}

		return array(
			'entries' => $entries,
			'total'   => count( $entries ),
		);
	}

	// =========================================================================
	// WPForms
	// =========================================================================

	/**
	 * Get WPForms forms.
	 *
	 * @param array $args Query arguments.
	 * @return array Forms list.
	 */
	private function get_wpforms_forms( $args = array() ) {
		if ( ! $this->is_wpforms_active() ) {
			return array();
		}

		$forms = array();
		$wpforms = wpforms()->form->get( '', array(
			'posts_per_page' => isset( $args['per_page'] ) ? absint( $args['per_page'] ) : 50,
		) );

		if ( $wpforms ) {
			foreach ( $wpforms as $form ) {
				$forms[] = array(
					'id'        => $form->ID,
					'title'     => $form->post_title,
					'shortcode' => '[wpforms id="' . $form->ID . '"]',
					'created'   => $form->post_date,
					'modified'  => $form->post_modified,
				);
			}
		}

		return $forms;
	}

	/**
	 * Get single WPForms form.
	 *
	 * @param int $form_id Form ID.
	 * @return array|WP_Error Form data.
	 */
	private function get_wpforms_form( $form_id ) {
		if ( ! $this->is_wpforms_active() ) {
			return new WP_Error( 'plugin_inactive', __( 'WPForms is not active.', 'mumcp' ) );
		}

		$form = wpforms()->form->get( $form_id );

		if ( ! $form ) {
			return new WP_Error( 'not_found', __( 'Form not found.', 'mumcp' ) );
		}

		$form_data = wpforms_decode( $form->post_content );

		return array(
			'id'        => $form->ID,
			'title'     => $form->post_title,
			'shortcode' => '[wpforms id="' . $form->ID . '"]',
			'created'   => $form->post_date,
			'modified'  => $form->post_modified,
			'fields'    => isset( $form_data['fields'] ) ? $form_data['fields'] : array(),
			'settings'  => isset( $form_data['settings'] ) ? $form_data['settings'] : array(),
		);
	}

	/**
	 * Get WPForms entries.
	 *
	 * @param int   $form_id Form ID.
	 * @param array $args    Query arguments.
	 * @return array|WP_Error Entries.
	 */
	private function get_wpforms_entries( $form_id, $args = array() ) {
		if ( ! $this->is_wpforms_active() ) {
			return new WP_Error( 'plugin_inactive', __( 'WPForms is not active.', 'mumcp' ) );
		}

		// WPForms Lite doesn't have entry storage.
		if ( ! function_exists( 'wpforms' ) || ! method_exists( wpforms()->entry, 'get_entries' ) ) {
			return array(
				'notice'  => __( 'WPForms Lite does not store entries. Upgrade to Pro for entry management.', 'mumcp' ),
				'entries' => array(),
			);
		}

		$entries = wpforms()->entry->get_entries( array(
			'form_id'  => $form_id,
			'number'   => isset( $args['per_page'] ) ? absint( $args['per_page'] ) : 50,
			'offset'   => isset( $args['offset'] ) ? absint( $args['offset'] ) : 0,
		) );

		$result = array();
		if ( $entries ) {
			foreach ( $entries as $entry ) {
				$result[] = array(
					'id'        => $entry->entry_id,
					'form_id'   => $entry->form_id,
					'fields'    => json_decode( $entry->fields, true ),
					'meta'      => json_decode( $entry->meta, true ),
					'date'      => $entry->date,
					'status'    => $entry->status,
					'ip'        => $entry->ip_address,
					'user_agent' => $entry->user_agent,
				);
			}
		}

		return array(
			'entries' => $result,
			'total'   => count( $result ),
		);
	}

	// =========================================================================
	// Gravity Forms
	// =========================================================================

	/**
	 * Get Gravity Forms forms.
	 *
	 * @param array $args Query arguments.
	 * @return array Forms list.
	 */
	private function get_gravityforms_forms( $args = array() ) {
		if ( ! $this->is_gravityforms_active() || ! class_exists( 'GFAPI' ) ) {
			return array();
		}

		$forms = array();
		$gf_forms = GFAPI::get_forms();

		foreach ( $gf_forms as $form ) {
			$forms[] = array(
				'id'          => $form['id'],
				'title'       => $form['title'],
				'shortcode'   => '[gravityform id="' . $form['id'] . '"]',
				'is_active'   => $form['is_active'],
				'entry_count' => GFAPI::count_entries( $form['id'] ),
			);
		}

		return $forms;
	}

	/**
	 * Get single Gravity Forms form.
	 *
	 * @param int $form_id Form ID.
	 * @return array|WP_Error Form data.
	 */
	private function get_gravityforms_form( $form_id ) {
		if ( ! $this->is_gravityforms_active() || ! class_exists( 'GFAPI' ) ) {
			return new WP_Error( 'plugin_inactive', __( 'Gravity Forms is not active.', 'mumcp' ) );
		}

		$form = GFAPI::get_form( $form_id );

		if ( ! $form ) {
			return new WP_Error( 'not_found', __( 'Form not found.', 'mumcp' ) );
		}

		return array(
			'id'              => $form['id'],
			'title'           => $form['title'],
			'description'     => $form['description'] ?? '',
			'shortcode'       => '[gravityform id="' . $form['id'] . '"]',
			'is_active'       => $form['is_active'],
			'fields'          => $form['fields'],
			'confirmations'   => $form['confirmations'] ?? array(),
			'notifications'   => $form['notifications'] ?? array(),
			'entry_count'     => GFAPI::count_entries( $form_id ),
		);
	}

	/**
	 * Get Gravity Forms entries.
	 *
	 * @param int   $form_id Form ID.
	 * @param array $args    Query arguments.
	 * @return array|WP_Error Entries.
	 */
	private function get_gravityforms_entries( $form_id, $args = array() ) {
		if ( ! $this->is_gravityforms_active() || ! class_exists( 'GFAPI' ) ) {
			return new WP_Error( 'plugin_inactive', __( 'Gravity Forms is not active.', 'mumcp' ) );
		}

		$search_criteria = array();
		$sorting = array( 'key' => 'date_created', 'direction' => 'DESC' );
		$paging = array(
			'offset'    => isset( $args['offset'] ) ? absint( $args['offset'] ) : 0,
			'page_size' => isset( $args['per_page'] ) ? absint( $args['per_page'] ) : 50,
		);

		$total = GFAPI::count_entries( $form_id, $search_criteria );
		$entries = GFAPI::get_entries( $form_id, $search_criteria, $sorting, $paging );

		$result = array();
		foreach ( $entries as $entry ) {
			$result[] = array(
				'id'           => $entry['id'],
				'form_id'      => $entry['form_id'],
				'date_created' => $entry['date_created'],
				'is_starred'   => $entry['is_starred'],
				'is_read'      => $entry['is_read'],
				'ip'           => $entry['ip'],
				'source_url'   => $entry['source_url'],
				'user_agent'   => $entry['user_agent'],
				'status'       => $entry['status'],
				'fields'       => array_filter( $entry, function( $key ) {
					return is_numeric( $key );
				}, ARRAY_FILTER_USE_KEY ),
			);
		}

		return array(
			'entries' => $result,
			'total'   => $total,
		);
	}

	// =========================================================================
	// Ninja Forms
	// =========================================================================

	/**
	 * Get Ninja Forms forms.
	 *
	 * @param array $args Query arguments.
	 * @return array Forms list.
	 */
	private function get_ninjaforms_forms( $args = array() ) {
		if ( ! $this->is_ninjaforms_active() ) {
			return array();
		}

		$forms = array();
		$nf_forms = Ninja_Forms()->form()->get_forms();

		foreach ( $nf_forms as $form ) {
			$forms[] = array(
				'id'        => $form->get_id(),
				'title'     => $form->get_setting( 'title' ),
				'shortcode' => '[ninja_form id="' . $form->get_id() . '"]',
				'created'   => $form->get_setting( 'created_at' ),
			);
		}

		return $forms;
	}

	/**
	 * Get single Ninja Forms form.
	 *
	 * @param int $form_id Form ID.
	 * @return array|WP_Error Form data.
	 */
	private function get_ninjaforms_form( $form_id ) {
		if ( ! $this->is_ninjaforms_active() ) {
			return new WP_Error( 'plugin_inactive', __( 'Ninja Forms is not active.', 'mumcp' ) );
		}

		$form = Ninja_Forms()->form( $form_id )->get();

		if ( ! $form->get_id() ) {
			return new WP_Error( 'not_found', __( 'Form not found.', 'mumcp' ) );
		}

		$fields = Ninja_Forms()->form( $form_id )->get_fields();
		$field_data = array();

		foreach ( $fields as $field ) {
			$field_data[] = array(
				'id'       => $field->get_id(),
				'type'     => $field->get_setting( 'type' ),
				'label'    => $field->get_setting( 'label' ),
				'key'      => $field->get_setting( 'key' ),
				'required' => $field->get_setting( 'required' ),
			);
		}

		return array(
			'id'        => $form->get_id(),
			'title'     => $form->get_setting( 'title' ),
			'shortcode' => '[ninja_form id="' . $form->get_id() . '"]',
			'fields'    => $field_data,
			'settings'  => $form->get_settings(),
		);
	}

	/**
	 * Get Ninja Forms entries.
	 *
	 * @param int   $form_id Form ID.
	 * @param array $args    Query arguments.
	 * @return array|WP_Error Entries.
	 */
	private function get_ninjaforms_entries( $form_id, $args = array() ) {
		if ( ! $this->is_ninjaforms_active() ) {
			return new WP_Error( 'plugin_inactive', __( 'Ninja Forms is not active.', 'mumcp' ) );
		}

		$subs = Ninja_Forms()->form( $form_id )->get_subs();

		$result = array();
		$limit = isset( $args['per_page'] ) ? absint( $args['per_page'] ) : 50;
		$count = 0;

		foreach ( $subs as $sub ) {
			if ( $count >= $limit ) {
				break;
			}

			$result[] = array(
				'id'           => $sub->get_id(),
				'form_id'      => $form_id,
				'date_created' => $sub->get_sub_date(),
				'status'       => $sub->get_status(),
				'fields'       => $sub->get_field_values(),
			);

			$count++;
		}

		return array(
			'entries' => $result,
			'total'   => count( $subs ),
		);
	}
}
