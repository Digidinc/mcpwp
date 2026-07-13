<?php
/**
 * Admin settings page methods.
 *
 * Carved verbatim from Mcpwp_Admin (G3 split). Mixed back via trait — same class, same $this.
 *
 * @package mumcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Mcpwp_Admin_Settings_Trait {

	/**
	 * Render the Settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mumega-mcp' ) );
		}

		if ( isset( $_POST['mcpwp_save_site_profile'] ) ) {
			check_admin_referer( 'mcpwp_site_profile_actions', 'mcpwp_site_profile_nonce' );
			$this->handle_save_site_profile();
		}

		$site_profile         = $this->get_site_profile();
		$site_context_preview = $this->get_site_context_preview();
		$llms_url             = $this->get_llms_url();
		$llms_preview         = $this->get_llms_preview();

		include MCPWP_PLUGIN_DIR . 'admin/partials/mcpwp-settings-display.php';
	}

	/**
	 * Save the guided site profile and regenerate canonical site context.
	 *
	 * @return void
	 */
	private function handle_save_site_profile() {
		$profile = $this->sanitize_site_profile_input(
			isset( $_POST['mcpwp_site_profile'] ) ? (array) wp_unslash( $_POST['mcpwp_site_profile'] ) : array()
		);

		update_option( 'mcpwp_site_profile', $profile );

		$context = $this->generate_site_context_from_profile( $profile );
		update_option( 'mcpwp_site_context', $context );
		update_option( 'mcpwp_site_context_updated', gmdate( 'Y-m-d H:i:s' ) );

		add_settings_error(
			'mcpwp_messages',
			'mcpwp_site_profile_saved',
			__( 'Structured site profile saved and site context regenerated.', 'mumega-mcp' ),
			'updated'
		);
	}

	/**
	 * Sanitize raw site profile input.
	 *
	 * @param array $input Raw profile fields.
	 * @return array
	 */
	private function sanitize_site_profile_input( $input ) {
		$fields = array(
			'brand_name',
			'brand_summary',
			'target_audience',
			'brand_voice',
			'visual_style',
			'primary_colors',
			'typography',
			'header_rules',
			'footer_rules',
			'core_page_patterns',
			'reusable_sections',
			'conversion_goals',
			'claims_to_avoid',
			'ai_instructions',
		);

		$profile = array();
		foreach ( $fields as $field ) {
			$value = isset( $input[ $field ] ) ? (string) $input[ $field ] : '';
			$value = sanitize_textarea_field( $value );
			$profile[ $field ] = trim( $value );
		}

		return $profile;
	}

	/**
	 * Build canonical Markdown site context from structured profile fields.
	 *
	 * @param array $profile Sanitized profile.
	 * @return string
	 */
	private function generate_site_context_from_profile( $profile ) {
		$profile = is_array( $profile ) ? $profile : array();
		$brand_name = ! empty( $profile['brand_name'] ) ? $profile['brand_name'] : get_bloginfo( 'name' );

		$lines   = array();
		$lines[] = '# ' . $brand_name . ' - Site Character';

		$mapping = array(
			'brand_summary'      => 'Brand Summary',
			'target_audience'    => 'Target Audience',
			'brand_voice'        => 'Brand Voice',
			'visual_style'       => 'Visual Style',
			'primary_colors'     => 'Colors',
			'typography'         => 'Typography',
			'header_rules'       => 'Header Rules',
			'footer_rules'       => 'Footer Rules',
			'core_page_patterns' => 'Core Page Patterns',
			'reusable_sections'  => 'Reusable Sections',
			'conversion_goals'   => 'Conversion Goals',
			'claims_to_avoid'    => 'Claims To Avoid',
			'ai_instructions'    => 'Instructions For AI Systems',
		);

		foreach ( $mapping as $key => $heading ) {
			if ( empty( $profile[ $key ] ) ) {
				continue;
			}
			$lines[] = '';
			$lines[] = '## ' . $heading;
			$lines[] = $this->normalize_profile_multiline_value( $profile[ $key ] );
		}

		return trim( implode( "\n", $lines ) );
	}

	/**
	 * Normalize profile values into Markdown-friendly blocks.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function normalize_profile_multiline_value( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		$lines = preg_split( "/\r\n|\r|\n/", $value );
		$lines = array_map( 'trim', $lines );
		$lines = array_values( array_filter( $lines, 'strlen' ) );

		if ( empty( $lines ) ) {
			return '';
		}

		$has_bullets = false;
		foreach ( $lines as $line ) {
			if ( preg_match( '/^[-*]\s+/', $line ) ) {
				$has_bullets = true;
				break;
			}
		}

		if ( 1 === count( $lines ) && ! $has_bullets ) {
			return $lines[0];
		}

		$formatted = array();
		foreach ( $lines as $line ) {
			if ( preg_match( '/^[-*]\s+/', $line ) ) {
				$formatted[] = preg_replace( '/^\*\s+/', '- ', $line );
			} else {
				$formatted[] = '- ' . $line;
			}
		}

		return implode( "\n", $formatted );
	}
}
