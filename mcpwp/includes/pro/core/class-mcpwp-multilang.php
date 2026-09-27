<?php
/**
 * Multilanguage Integration Handler
 *
 * Supports WPML, Polylang, and TranslatePress.
 *
 * @package MCPWP_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Multilanguage handler class.
 */
class Mcpwp_Multilang {

	use Mcpwp_Sanitization;

	/**
	 * ISO 639-1 codes for right-to-left languages.
	 *
	 * @var array
	 */
	private static $rtl_codes = array( 'ar', 'he', 'fa', 'ur', 'ps', 'ku', 'sd', 'dv', 'yi', 'ug' );

	/**
	 * Detected plugin.
	 *
	 * @var string|null
	 */
	private $detected_plugin = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->detect_plugin();
	}

	/**
	 * Detect which multilingual plugin is active.
	 */
	private function detect_plugin() {
		if ( $this->is_wpml_active() ) {
			$this->detected_plugin = 'wpml';
		} elseif ( $this->is_polylang_active() ) {
			$this->detected_plugin = 'polylang';
		} elseif ( $this->is_translatepress_active() ) {
			$this->detected_plugin = 'translatepress';
		}
	}

	/**
	 * Check if any multilingual plugin is active.
	 *
	 * @return bool
	 */
	public function is_active() {
		return ! is_null( $this->detected_plugin );
	}

	/**
	 * Get detected plugin name.
	 *
	 * @return string|null
	 */
	public function get_plugin() {
		return $this->detected_plugin;
	}

	/**
	 * Check if WPML is active.
	 *
	 * @return bool
	 */
	public function is_wpml_active() {
		return defined( 'ICL_SITEPRESS_VERSION' ) && class_exists( 'SitePress' );
	}

	/**
	 * Check if Polylang is active.
	 *
	 * @return bool
	 */
	public function is_polylang_active() {
		return function_exists( 'pll_languages_list' ) || function_exists( 'PLL' );
	}

	/**
	 * Check if TranslatePress is active.
	 *
	 * @return bool
	 */
	public function is_translatepress_active() {
		return class_exists( 'TRP_Translate_Press' );
	}

	/**
	 * Get status information.
	 *
	 * @return array
	 */
	public function get_status() {
		return array(
			'active'           => $this->is_active(),
			'plugin'           => $this->detected_plugin,
			'default_language' => $this->get_default_language(),
			'current_language' => $this->get_current_language(),
			'languages_count'  => count( $this->get_languages() ),
		);
	}

	/**
	 * Get all available languages.
	 *
	 * @return array
	 */
	public function get_languages() {
		if ( ! $this->is_active() ) {
			return array();
		}

		switch ( $this->detected_plugin ) {
			case 'wpml':
				return $this->get_wpml_languages();
			case 'polylang':
				return $this->get_polylang_languages();
			case 'translatepress':
				return $this->get_translatepress_languages();
			default:
				return array();
		}
	}

	/**
	 * Get default language code.
	 *
	 * @return string|null
	 */
	public function get_default_language() {
		if ( ! $this->is_active() ) {
			return null;
		}

		switch ( $this->detected_plugin ) {
			case 'wpml':
				return $this->get_wpml_default_language();
			case 'polylang':
				return $this->get_polylang_default_language();
			case 'translatepress':
				return $this->get_translatepress_default_language();
			default:
				return null;
		}
	}

	/**
	 * Get current language code.
	 *
	 * @return string|null
	 */
	public function get_current_language() {
		if ( ! $this->is_active() ) {
			return null;
		}

		switch ( $this->detected_plugin ) {
			case 'wpml':
				return $this->get_wpml_current_language();
			case 'polylang':
				return $this->get_polylang_current_language();
			case 'translatepress':
				return $this->get_translatepress_current_language();
			default:
				return null;
		}
	}

	/**
	 * Get language of a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string|null Language code.
	 */
	public function get_post_language( $post_id ) {
		if ( ! $this->is_active() ) {
			return null;
		}

		switch ( $this->detected_plugin ) {
			case 'wpml':
				return $this->get_wpml_post_language( $post_id );
			case 'polylang':
				return $this->get_polylang_post_language( $post_id );
			case 'translatepress':
				// TranslatePress doesn't use separate posts for translations.
				return $this->get_default_language();
			default:
				return null;
		}
	}

	/**
	 * Get all translations of a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array Array of translations with language code => post ID.
	 */
	public function get_post_translations( $post_id ) {
		if ( ! $this->is_active() ) {
			return array();
		}

		switch ( $this->detected_plugin ) {
			case 'wpml':
				return $this->get_wpml_post_translations( $post_id );
			case 'polylang':
				return $this->get_polylang_post_translations( $post_id );
			case 'translatepress':
				// TranslatePress stores translations differently.
				return $this->get_translatepress_post_translations( $post_id );
			default:
				return array();
		}
	}

	/**
	 * Create a translation for a post.
	 *
	 * @param int    $post_id  Original post ID.
	 * @param string $language Target language code.
	 * @param array  $data     Translation data (title, content, excerpt, etc.).
	 * @return int|WP_Error New post ID or error.
	 */
	public function create_post_translation( $post_id, $language, $data ) {
		if ( ! $this->is_active() ) {
			return new WP_Error( 'no_multilang', __( 'No multilingual plugin is active.', 'mcpwp' ), array( 'status' => 400 ) );
		}

		// Validate language.
		$languages = wp_list_pluck( $this->get_languages(), 'code' );
		if ( ! in_array( $language, $languages, true ) ) {
			return new WP_Error( 'invalid_language', __( 'Invalid language code.', 'mcpwp' ), array( 'status' => 400 ) );
		}

		// Check if translation already exists.
		$existing = $this->get_post_translations( $post_id );
		if ( isset( $existing[ $language ] ) ) {
			return new WP_Error( 'translation_exists', __( 'Translation already exists for this language.', 'mcpwp' ), array( 'status' => 409 ) );
		}

		switch ( $this->detected_plugin ) {
			case 'wpml':
				return $this->create_wpml_translation( $post_id, $language, $data );
			case 'polylang':
				return $this->create_polylang_translation( $post_id, $language, $data );
			case 'translatepress':
				return new WP_Error( 'not_supported', __( 'TranslatePress uses inline translation, not separate posts.', 'mcpwp' ), array( 'status' => 400 ) );
			default:
				return new WP_Error( 'unknown_plugin', __( 'Unknown multilingual plugin.', 'mcpwp' ), array( 'status' => 500 ) );
		}
	}

	/**
	 * Set language filter for queries.
	 *
	 * @param string $language Language code.
	 */
	public function set_language_filter( $language ) {
		if ( ! $this->is_active() || empty( $language ) ) {
			return;
		}

		switch ( $this->detected_plugin ) {
			case 'wpml':
				$this->set_wpml_language( $language );
				break;
			case 'polylang':
				$this->set_polylang_language( $language );
				break;
			case 'translatepress':
				$this->set_translatepress_language( $language );
				break;
		}
	}

	/**
	 * Check if a language code is RTL.
	 *
	 * Matches the first two characters of the code against known RTL language codes.
	 *
	 * @param string $code Language code (e.g. 'ar', 'fa_IR', 'he').
	 * @return bool
	 */
	private function is_rtl_language( $code ) {
		$short = strtolower( substr( $code, 0, 2 ) );
		return in_array( $short, self::$rtl_codes, true );
	}

	// =========================================================================
	// WPML Methods
	// =========================================================================

	/**
	 * Get WPML languages.
	 *
	 * @return array
	 */
	private function get_wpml_languages() {
		$languages = array();

		if ( ! function_exists( 'icl_get_languages' ) ) {
			return $languages;
		}

		$wpml_languages = icl_get_languages( 'skip_missing=0' );

		foreach ( $wpml_languages as $lang ) {
			$languages[] = array(
				'code'        => $lang['code'],
				'name'        => $lang['english_name'],
				'native_name' => $lang['native_name'],
				'flag'        => isset( $lang['country_flag_url'] ) ? $lang['country_flag_url'] : null,
				'is_default'  => (bool) $lang['is_default_language'],
				'active'      => (bool) $lang['active'],
				'is_rtl'      => $this->is_rtl_language( $lang['code'] ),
			);
		}

		return $languages;
	}

	/**
	 * Get WPML default language.
	 *
	 * @return string|null
	 */
	private function get_wpml_default_language() {
		global $sitepress;
		if ( $sitepress && method_exists( $sitepress, 'get_default_language' ) ) {
			return $sitepress->get_default_language();
		}
		return apply_filters( 'wpml_default_language', null );
	}

	/**
	 * Get WPML current language.
	 *
	 * @return string|null
	 */
	private function get_wpml_current_language() {
		return apply_filters( 'wpml_current_language', null );
	}

	/**
	 * Get WPML post language.
	 *
	 * @param int $post_id Post ID.
	 * @return string|null
	 */
	private function get_wpml_post_language( $post_id ) {
		return apply_filters( 'wpml_post_language_details', null, $post_id )['language_code'] ?? null;
	}

	/**
	 * Get WPML post translations.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	private function get_wpml_post_translations( $post_id ) {
		$post_type = get_post_type( $post_id );
		$trid = apply_filters( 'wpml_element_trid', null, $post_id, 'post_' . $post_type );

		if ( ! $trid ) {
			return array();
		}

		$translations = apply_filters( 'wpml_get_element_translations', null, $trid, 'post_' . $post_type );
		$result = array();

		if ( is_array( $translations ) ) {
			foreach ( $translations as $lang => $trans ) {
				$result[ $lang ] = array(
					'post_id' => (int) $trans->element_id,
					'status'  => $trans->source_language_code ? 'translation' : 'original',
				);
			}
		}

		return $result;
	}

	/**
	 * Create WPML translation.
	 *
	 * @param int    $post_id  Original post ID.
	 * @param string $language Target language.
	 * @param array  $data     Translation data.
	 * @return int|WP_Error
	 */
	private function create_wpml_translation( $post_id, $language, $data ) {
		$original = get_post( $post_id );
		if ( ! $original ) {
			return new WP_Error( 'not_found', __( 'Original post not found.', 'mcpwp' ), array( 'status' => 404 ) );
		}

		$new_title   = isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : $original->post_title;
		$new_content = isset( $data['content'] ) ? $this->sanitize_content_body( $data['content'] )['content'] : $original->post_content;
		$new_status  = isset( $data['status'] ) ? sanitize_key( $data['status'] ) : 'draft';

		// Content guardrails (#957): a translation is a new published post
		// too, and still counts against the ramp cap / thin-content floor —
		// a machine-translated batch of hundreds of posts is exactly the
		// scaled-publishing pattern this guards against.
		if ( class_exists( 'Mcpwp_Content_Guardrails' ) ) {
			$new_status = Mcpwp_Content_Guardrails::evaluate(
				array(
					'post_type' => $original->post_type,
					'status'    => $new_status,
					'title'     => $new_title,
					'content'   => $new_content,
				)
			)['status'];
		}

		// Create the translated post.
		$new_post = array(
			'post_title'   => $new_title,
			'post_content' => $new_content,
			'post_excerpt' => isset( $data['excerpt'] ) ? sanitize_textarea_field( $data['excerpt'] ) : $original->post_excerpt,
			'post_status'  => $new_status,
			'post_type'    => $original->post_type,
			'post_author'  => $original->post_author,
		);

		$new_post_id = wp_insert_post( $new_post, true );

		if ( is_wp_error( $new_post_id ) ) {
			return $new_post_id;
		}

		// Get the trid of the original post.
		$trid = apply_filters( 'wpml_element_trid', null, $post_id, 'post_' . $original->post_type );

		// Link the translation.
		$source_lang = $this->get_wpml_post_language( $post_id );

		do_action( 'wpml_set_element_language_details', array(
			'element_id'           => $new_post_id,
			'element_type'         => 'post_' . $original->post_type,
			'trid'                 => $trid,
			'language_code'        => $language,
			'source_language_code' => $source_lang,
		) );

		return $new_post_id;
	}

	/**
	 * Set WPML language for queries.
	 *
	 * @param string $language Language code.
	 */
	private function set_wpml_language( $language ) {
		global $sitepress;
		if ( $sitepress && method_exists( $sitepress, 'switch_lang' ) ) {
			$sitepress->switch_lang( $language );
		}
	}

	// =========================================================================
	// Polylang Methods
	// =========================================================================

	/**
	 * Get Polylang languages.
	 *
	 * @return array
	 */
	private function get_polylang_languages() {
		$languages = array();

		if ( ! function_exists( 'pll_languages_list' ) || ! function_exists( 'PLL' ) ) {
			return $languages;
		}

		$pll_languages = PLL()->model->get_languages_list();

		foreach ( $pll_languages as $lang ) {
			$languages[] = array(
				'code'        => $lang->slug,
				'name'        => $lang->name,
				'native_name' => $lang->name, // Polylang doesn't separate these.
				'flag'        => $lang->flag_url,
				'is_default'  => (bool) $lang->is_default,
				'active'      => true,
				'locale'      => $lang->locale,
				'is_rtl'      => $this->is_rtl_language( $lang->slug ),
			);
		}

		return $languages;
	}

	/**
	 * Get Polylang default language.
	 *
	 * @return string|null
	 */
	private function get_polylang_default_language() {
		if ( function_exists( 'pll_default_language' ) ) {
			return pll_default_language();
		}
		return null;
	}

	/**
	 * Get Polylang current language.
	 *
	 * @return string|null
	 */
	private function get_polylang_current_language() {
		if ( function_exists( 'pll_current_language' ) ) {
			return pll_current_language();
		}
		return null;
	}

	/**
	 * Get Polylang post language.
	 *
	 * @param int $post_id Post ID.
	 * @return string|null
	 */
	private function get_polylang_post_language( $post_id ) {
		if ( function_exists( 'pll_get_post_language' ) ) {
			return pll_get_post_language( $post_id );
		}
		return null;
	}

	/**
	 * Get Polylang post translations.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	private function get_polylang_post_translations( $post_id ) {
		if ( ! function_exists( 'pll_get_post_translations' ) ) {
			return array();
		}

		$translations = pll_get_post_translations( $post_id );
		$original_lang = $this->get_polylang_post_language( $post_id );
		$result = array();

		foreach ( $translations as $lang => $trans_id ) {
			$result[ $lang ] = array(
				'post_id' => (int) $trans_id,
				'status'  => ( $lang === $original_lang ) ? 'original' : 'translation',
			);
		}

		return $result;
	}

	/**
	 * Create Polylang translation.
	 *
	 * @param int    $post_id  Original post ID.
	 * @param string $language Target language.
	 * @param array  $data     Translation data.
	 * @return int|WP_Error
	 */
	private function create_polylang_translation( $post_id, $language, $data ) {
		$original = get_post( $post_id );
		if ( ! $original ) {
			return new WP_Error( 'not_found', __( 'Original post not found.', 'mcpwp' ), array( 'status' => 404 ) );
		}

		$new_title   = isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : $original->post_title;
		$new_content = isset( $data['content'] ) ? $this->sanitize_content_body( $data['content'] )['content'] : $original->post_content;
		$new_status  = isset( $data['status'] ) ? sanitize_key( $data['status'] ) : 'draft';

		// Content guardrails (#957): see create_wpml_translation() above.
		if ( class_exists( 'Mcpwp_Content_Guardrails' ) ) {
			$new_status = Mcpwp_Content_Guardrails::evaluate(
				array(
					'post_type' => $original->post_type,
					'status'    => $new_status,
					'title'     => $new_title,
					'content'   => $new_content,
				)
			)['status'];
		}

		// Create the translated post.
		$new_post = array(
			'post_title'   => $new_title,
			'post_content' => $new_content,
			'post_excerpt' => isset( $data['excerpt'] ) ? sanitize_textarea_field( $data['excerpt'] ) : $original->post_excerpt,
			'post_status'  => $new_status,
			'post_type'    => $original->post_type,
			'post_author'  => $original->post_author,
		);

		$new_post_id = wp_insert_post( $new_post, true );

		if ( is_wp_error( $new_post_id ) ) {
			return $new_post_id;
		}

		// Set the language.
		if ( function_exists( 'pll_set_post_language' ) ) {
			pll_set_post_language( $new_post_id, $language );
		}

		// Link translations.
		if ( function_exists( 'pll_save_post_translations' ) ) {
			$translations = pll_get_post_translations( $post_id );
			$translations[ $language ] = $new_post_id;
			pll_save_post_translations( $translations );
		}

		return $new_post_id;
	}

	/**
	 * Set Polylang language for queries.
	 *
	 * @param string $language Language code.
	 */
	private function set_polylang_language( $language ) {
		if ( function_exists( 'PLL' ) && isset( PLL()->curlang ) ) {
			PLL()->curlang = PLL()->model->get_language( $language );
		}
	}

	// =========================================================================
	// TranslatePress Methods
	// =========================================================================

	/**
	 * Get TranslatePress languages.
	 *
	 * @return array
	 */
	private function get_translatepress_languages() {
		$languages = array();

		if ( ! class_exists( 'TRP_Translate_Press' ) ) {
			return $languages;
		}

		$trp = TRP_Translate_Press::get_trp_instance();
		$settings = $trp->get_component( 'settings' );
		$trp_settings = $settings->get_settings();

		if ( ! isset( $trp_settings['publish-languages'] ) ) {
			return $languages;
		}

		$trp_languages = $trp->get_component( 'languages' );
		$all_languages = $trp_languages->get_languages( 'all' );

		foreach ( $trp_settings['publish-languages'] as $lang_code ) {
			if ( isset( $all_languages[ $lang_code ] ) ) {
				$lang = $all_languages[ $lang_code ];
				$languages[] = array(
					'code'        => $lang_code,
					'name'        => $lang,
					'native_name' => $lang,
					'flag'        => null,
					'is_default'  => ( $lang_code === $trp_settings['default-language'] ),
					'active'      => true,
					'is_rtl'      => $this->is_rtl_language( $lang_code ),
				);
			}
		}

		return $languages;
	}

	/**
	 * Get TranslatePress default language.
	 *
	 * @return string|null
	 */
	private function get_translatepress_default_language() {
		if ( ! class_exists( 'TRP_Translate_Press' ) ) {
			return null;
		}

		$trp = TRP_Translate_Press::get_trp_instance();
		$settings = $trp->get_component( 'settings' );
		$trp_settings = $settings->get_settings();

		return $trp_settings['default-language'] ?? null;
	}

	/**
	 * Get TranslatePress current language.
	 *
	 * @return string|null
	 */
	private function get_translatepress_current_language() {
		global $TRP_LANGUAGE;
		return $TRP_LANGUAGE ?? $this->get_translatepress_default_language();
	}

	/**
	 * Get TranslatePress post translations.
	 *
	 * TranslatePress stores translations in a separate table, not as separate posts.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	private function get_translatepress_post_translations( $post_id ) {
		// TranslatePress uses the same post ID for all languages.
		// Translations are stored in trp_dictionary_* tables.
		$languages = $this->get_languages();
		$result = array();

		foreach ( $languages as $lang ) {
			$result[ $lang['code'] ] = array(
				'post_id' => $post_id,
				'status'  => $lang['is_default'] ? 'original' : 'inline_translation',
				'type'    => 'translatepress_inline',
			);
		}

		return $result;
	}

	/**
	 * Set TranslatePress language.
	 *
	 * @param string $language Language code.
	 */
	private function set_translatepress_language( $language ) {
		global $TRP_LANGUAGE;
		$TRP_LANGUAGE = $language;
	}

	// =========================================================================
	// Helper Methods
	// =========================================================================

	/**
	 * Get detailed translations for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public function get_detailed_translations( $post_id ) {
		$translations = $this->get_post_translations( $post_id );
		$detailed = array();

		foreach ( $translations as $lang => $trans ) {
			$trans_post = get_post( $trans['post_id'] );
			if ( $trans_post ) {
				$detailed[ $lang ] = array(
					'post_id'    => $trans['post_id'],
					'status'     => $trans['status'],
					'title'      => $trans_post->post_title,
					'post_status' => $trans_post->post_status,
					'permalink'  => get_permalink( $trans['post_id'] ),
					'modified'   => $trans_post->post_modified,
				);
			}
		}

		return $detailed;
	}

	/**
	 * Get language info endpoint response.
	 *
	 * @return array
	 */
	public function get_language_info() {
		return array(
			'plugin'           => $this->detected_plugin,
			'plugin_version'   => $this->get_plugin_version(),
			'default_language' => $this->get_default_language(),
			'current_language' => $this->get_current_language(),
			'languages'        => $this->get_languages(),
		);
	}

	/**
	 * Get plugin version.
	 *
	 * @return string|null
	 */
	private function get_plugin_version() {
		switch ( $this->detected_plugin ) {
			case 'wpml':
				return defined( 'ICL_SITEPRESS_VERSION' ) ? ICL_SITEPRESS_VERSION : null;
			case 'polylang':
				return defined( 'POLYLANG_VERSION' ) ? POLYLANG_VERSION : null;
			case 'translatepress':
				return defined( 'TRP_PLUGIN_VERSION' ) ? TRP_PLUGIN_VERSION : null;
			default:
				return null;
		}
	}
}
