<?php
/**
 * Page template validation and reporting for MCP writes.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared page-template helpers for create/update paths (#1231).
 */
class Mcpwp_Page_Template {

	/**
	 * Elementor page-template slugs not listed by the theme.
	 *
	 * @var string[]
	 */
	private static $elementor_slugs = array(
		'elementor_canvas',
		'elementor_header_footer',
	);

	/**
	 * Normalize a stored _wp_page_template value for API responses.
	 *
	 * @param mixed $stored Raw meta value.
	 * @return string
	 */
	public static function response_slug( $stored ) {
		$stored = is_string( $stored ) ? $stored : '';
		if ( '' === $stored || 'default' === $stored ) {
			return 'default';
		}

		return $stored;
	}

	/**
	 * Allowed template slugs for explicit writes.
	 *
	 * @return string[]
	 */
	public static function allowed_slugs() {
		$slugs = array( 'default' );

		if ( function_exists( 'mcpwp_test_page_templates' ) ) {
			$theme_templates = mcpwp_test_page_templates();
		} else {
			$theme = wp_get_theme();
			$theme_templates = method_exists( $theme, 'get_page_templates' )
				? $theme->get_page_templates()
				: array();
		}
		foreach ( array_keys( $theme_templates ) as $file ) {
			$slugs[] = (string) $file;
		}

		if ( did_action( 'elementor/loaded' ) || class_exists( '\Elementor\Plugin' ) || defined( 'ELEMENTOR_VERSION' ) ) {
			$slugs = array_merge( $slugs, self::$elementor_slugs );
		}

		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Validate a caller-requested template slug.
	 *
	 * @param mixed $template Requested slug.
	 * @return string|WP_Error Sanitized slug on success.
	 */
	public static function validate_requested( $template ) {
		$template = sanitize_text_field( (string) $template );
		if ( '' === $template ) {
			return new WP_Error(
				'invalid_template',
				__( 'Page template slug is required when the template argument is sent.', 'mcpwp' ),
				array( 'status' => 400 )
			);
		}

		if ( ! in_array( $template, self::allowed_slugs(), true ) ) {
			return new WP_Error(
				'invalid_template',
				__( 'Invalid page template slug.', 'mcpwp' ),
				array(
					'status'            => 400,
					'allowed_templates' => self::allowed_slugs(),
					'hint'              => 'Use wp_list_page_templates to list valid slugs, or wp_update_page_template for template-only changes.',
				)
			);
		}

		return $template;
	}

	/**
	 * Persist a validated slug to _wp_page_template.
	 *
	 * @param int    $page_id  Post ID.
	 * @param string $template Validated slug (incl. default).
	 * @return void
	 */
	public static function write_slug( $page_id, $template ) {
		if ( 'default' === $template ) {
			delete_post_meta( $page_id, '_wp_page_template' );
			return;
		}

		update_post_meta( $page_id, '_wp_page_template', $template );
	}

	/**
	 * Apply an optional template on an existing page (update/save paths).
	 *
	 * @param int   $page_id  Post ID.
	 * @param array $data     Request payload (template key optional).
	 * @param bool  $dry_run  When true, report only — no meta write.
	 * @return array|WP_Error Template report {before, after, requested}.
	 */
	public static function apply_on_existing( $page_id, $data, $dry_run = false ) {
		$before = self::response_slug( get_post_meta( $page_id, '_wp_page_template', true ) );

		if ( ! array_key_exists( 'template', $data ) ) {
			return array(
				'before'    => $before,
				'after'     => $before,
				'requested' => false,
			);
		}

		$validated = self::validate_requested( $data['template'] );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$after = self::response_slug( $validated );

		if ( ! $dry_run ) {
			self::write_slug( $page_id, $validated );
			$after = self::response_slug( get_post_meta( $page_id, '_wp_page_template', true ) );
		}

		return array(
			'before'    => $before,
			'after'     => $after,
			'requested' => true,
		);
	}

	/**
	 * Apply an optional template when a new page is created.
	 *
	 * @param int   $page_id New post ID.
	 * @param array $data    Request payload.
	 * @return array|WP_Error Template report.
	 */
	public static function apply_on_create( $page_id, $data ) {
		$before = 'default';

		if ( ! array_key_exists( 'template', $data ) || '' === (string) $data['template'] ) {
			return array(
				'before'    => $before,
				'after'     => self::response_slug( get_post_meta( $page_id, '_wp_page_template', true ) ),
				'requested' => false,
			);
		}

		$validated = self::validate_requested( $data['template'] );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		self::write_slug( $page_id, $validated );

		return array(
			'before'    => $before,
			'after'     => self::response_slug( get_post_meta( $page_id, '_wp_page_template', true ) ),
			'requested' => true,
		);
	}

	/**
	 * Validate create payload before insert (returns invalid_template early).
	 *
	 * @param array $data Request payload.
	 * @return true|WP_Error
	 */
	public static function validate_create_payload( $data ) {
		if ( ! array_key_exists( 'template', $data ) || '' === (string) $data['template'] ) {
			return true;
		}

		$validated = self::validate_requested( $data['template'] );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		return true;
	}
}