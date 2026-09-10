<?php
/**
 * The one decision about which wp_options an API caller may read or write,
 * and what is masked on the way out (#800).
 *
 * The previous rule was a hardcoded prefix list plus a substring blocklist,
 * and it was wrong in both directions at once:
 *
 *   - Too strict: `megamenu_*`, `gtm4wp-options`, `wp_mail_smtp` — plugins
 *     the site plainly runs — were unreachable, so the header, the tag
 *     manager and the From address had to be set in a browser. And
 *     `woocommerce_registration_generate_password`, a boolean, was refused
 *     because its name contains "password".
 *
 *   - Too loose: `woocommerce_<gateway>_settings` is an allowed prefix and
 *     an array, so a read-only audit returned the merchant's live API
 *     passcode in plaintext.
 *
 * Three rules replace it:
 *
 *   1. Access is by allowlist — core keys, curated prefixes, a matrix keyed
 *      on the plugins that are actually active, and a per-site list an admin
 *      can grow — minus a short list of keys that are never reachable through
 *      the generic option API (entitlement, updater control, the plugin's
 *      own secret stores). The blocklist is exact keys and exact prefixes,
 *      not words.
 *   2. Secrets are redacted on read wherever they sit — a scalar option, or
 *      a leaf inside an array — by the field's name and the value's shape.
 *      Booleans and flags are never secrets, whatever their name.
 *   3. A refusal names the rule that refused it and how an admin lifts it.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option access policy.
 */
class Mcpwp_Option_Access {

	const MASK = '***';

	/**
	 * Keys and prefixes never reachable through the generic option API,
	 * whatever the allowlist says. Exact, not words.
	 *
	 * @var array
	 */
	private static $blocked_prefixes = array(
		'mcpwp_api_key',      // key store (mcpwp_api_key, mcpwp_api_keys).
		'spai_api_key',
		'mcpwp_settings',     // oauth secret hash, github token.
		'mcpwp_integrations', // encrypted provider blobs.
		'mcpwp_encryption',   // the key that decrypts them.
		'mcpwp_pro_license',  // entitlement (Warden 3.1.0 P0).
		'spai_pro_license',
		'mcpwp_trial_started',
		'spai_trial_started',
		'mcpwp_migrated_from',
		'mcpwp_update_info',  // updater control: write-scope → RCE/SSRF path.
		'mcpwp_version_url',
		'spai_update_info',
		'spai_version_url',
		'mcpwp_oauth_',
		'mcpwp_broker_',
		'mcpwp_idem_',
		'stripe_',
		'paypal_',
		'auth_key',
		'secure_auth_key',
		'logged_in_key',
		'nonce_key',
	);

	/**
	 * Substrings that mark an option as an internal session/secret store.
	 *
	 * @var array
	 */
	private static $blocked_fragments = array( '_session', 'encryption_key', 'private_key' );

	/**
	 * Field names (anywhere in an array, or the option name itself) whose
	 * string values are masked on read.
	 *
	 * @var string
	 */
	const SECRET_FIELD_PATTERN = '/(api_?key|passcode|password|passwd|secret|token|private_key|license_key|licence_key|credential|signing_key|access_key|client_secret|merchant_key|app_key|webhook_key)/i';

	/**
	 * Values that are flags, never secrets, whatever the field is called.
	 *
	 * @var array
	 */
	private static $flag_values = array( '', '0', '1', 'yes', 'no', 'true', 'false', 'on', 'off', 'enabled', 'disabled' );

	/**
	 * Option namespaces unlocked by an active plugin, keyed on the plugin
	 * directory name as it appears in active_plugins.
	 *
	 * @var array
	 */
	private static $plugin_matrix = array(
		'megamenu'                      => array( 'prefixes' => array( 'megamenu_' ) ),
		'megamenu-pro'                  => array( 'prefixes' => array( 'megamenu_' ) ),
		'duracelltomi-google-tag-manager' => array( 'keys' => array( 'gtm4wp-options' ) ),
		'wp-mail-smtp'                  => array( 'keys' => array( 'wp_mail_smtp' ), 'prefixes' => array( 'wp_mail_smtp_' ) ),
		'wp-mail-smtp-pro'              => array( 'keys' => array( 'wp_mail_smtp' ), 'prefixes' => array( 'wp_mail_smtp_' ) ),
		'complianz-gdpr'                => array( 'prefixes' => array( 'cmplz_', 'complianz_' ) ),
		'complianz-gdpr-premium'        => array( 'prefixes' => array( 'cmplz_', 'complianz_' ) ),
		'wpforms-lite'                  => array( 'keys' => array( 'wpforms_settings' ) ),
		'wpforms'                       => array( 'keys' => array( 'wpforms_settings' ) ),
		'contact-form-7'                => array( 'prefixes' => array( 'wpcf7' ) ),
		'gravityforms'                  => array( 'prefixes' => array( 'gform_', 'rg_gforms_' ) ),
		'learnpress'                    => array( 'prefixes' => array( 'learn_press_' ) ),
		'all-in-one-seo-pack'           => array( 'prefixes' => array( 'aioseo_' ) ),
		'wp-seopress'                   => array( 'prefixes' => array( 'seopress_' ) ),
		'the-events-calendar'           => array( 'prefixes' => array( 'tribe_' ) ),
		'wp-rocket'                     => array( 'keys' => array( 'wp_rocket_settings' ) ),
		'litespeed-cache'               => array( 'prefixes' => array( 'litespeed.' ) ),
		'autoptimize'                   => array( 'prefixes' => array( 'autoptimize_' ) ),
		'wp-super-cache'                => array( 'prefixes' => array( 'wpsupercache_' ) ),
		'w3-total-cache'                => array( 'prefixes' => array( 'w3tc_' ) ),
		'polylang'                      => array( 'keys' => array( 'polylang' ) ),
		'sitepress-multilingual-cms'    => array( 'prefixes' => array( 'icl_' ) ),
		'redirection'                   => array( 'prefixes' => array( 'redirection_' ) ),
		'google-site-kit'               => array( 'prefixes' => array( 'googlesitekit_' ) ),
	);

	/**
	 * Core keys always reachable.
	 *
	 * @var array
	 */
	private static $core_keys = array(
		'blogname', 'blogdescription', 'siteurl', 'home', 'admin_email', 'timezone_string', 'gmt_offset',
		'date_format', 'time_format', 'start_of_week', 'WPLANG', 'posts_per_page', 'posts_per_rss',
		'show_on_front', 'page_on_front', 'page_for_posts', 'blog_public', 'permalink_structure',
		'category_base', 'tag_base', 'default_category', 'default_post_format', 'default_comment_status',
		'default_ping_status', 'comment_registration', 'thumbnail_size_w', 'thumbnail_size_h',
		'medium_size_w', 'medium_size_h', 'large_size_w', 'large_size_h', 'site_icon',
		'wp_page_for_privacy_policy', 'uploads_use_yearmonth_folders', 'image_default_link_type',
		'astra-settings', 'generate_settings', 'mcpwp_site_context', 'mcpwp_site_context_updated',
		'elementor_pro_theme_builder_conditions',
	);

	/**
	 * Always-on prefixes (builders, SEO, themes, commerce).
	 *
	 * @var array
	 */
	private static $core_prefixes = array(
		'elementor_', 'wpseo_', 'rank_math_', 'astra_', 'ocean_', 'generate_', 'kadence_', 'blocksy_',
		'theme_mods_', 'widget_', 'nav_menu_', 'sidebars_', 'woocommerce_',
		// The plugin's own non-secret options; its secret stores are in
		// $blocked_prefixes, which is checked first and wins.
		'mcpwp_',
	);

	/**
	 * Decide whether a key may be reached.
	 *
	 * @param string $key Option name (case preserved).
	 * @return array {allowed: bool, rule: string, hint?: string}
	 */
	public static function decide( $key ) {
		$key = (string) $key;

		if ( '' === $key ) {
			return array( 'allowed' => false, 'rule' => 'empty_key' );
		}

		$lower = strtolower( $key );

		foreach ( self::$blocked_prefixes as $prefix ) {
			if ( 0 === strpos( $lower, strtolower( $prefix ) ) ) {
				return array(
					'allowed' => false,
					'rule'    => 'blocked:' . $prefix,
					'hint'    => 'This option is a credential, entitlement or updater control and is never reachable through the generic option API, for any scope. Use the dedicated tool for it (wp_update_plugin_settings, wp_configure_integration) if one exists.',
				);
			}
		}

		foreach ( self::$blocked_fragments as $fragment ) {
			if ( false !== strpos( $lower, $fragment ) ) {
				return array(
					'allowed' => false,
					'rule'    => 'blocked:*' . $fragment . '*',
					'hint'    => 'Session and key material is never reachable through the generic option API.',
				);
			}
		}

		if ( in_array( $key, self::$core_keys, true ) ) {
			return array( 'allowed' => true, 'rule' => 'core_key' );
		}

		foreach ( self::$core_prefixes as $prefix ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				return array( 'allowed' => true, 'rule' => 'prefix:' . $prefix );
			}
		}

		$plugin = self::plugin_rule_for( $key );
		if ( null !== $plugin ) {
			return array( 'allowed' => true, 'rule' => 'plugin:' . $plugin );
		}

		$site = self::site_rule_for( $key );
		if ( null !== $site ) {
			return array( 'allowed' => true, 'rule' => 'site_allowlist:' . $site );
		}

		return array(
			'allowed' => false,
			'rule'    => 'not_allowlisted',
			'hint'    => 'Not a core key, not under a known plugin namespace for a plugin that is active here, and not on this site\'s allowlist. An admin can add it: wp_update_plugin_settings {option_allowlist: ["' . $key . '"]} (or "prefix_*" for a namespace).',
		);
	}

	/**
	 * Mask secret-shaped values on the way out.
	 *
	 * @param string $key   Option name.
	 * @param mixed  $value Stored value.
	 * @return array {value: mixed, redacted: string[]} paths use dot notation; '' is the option itself.
	 */
	public static function redact( $key, $value ) {
		$redacted = array();

		if ( is_array( $value ) || is_object( $value ) ) {
			$value = self::redact_tree( (array) $value, '', $redacted );
		} elseif ( is_string( $value ) && self::is_secret_field( $key ) && self::looks_like_secret_value( $value ) ) {
			$value      = self::MASK;
			$redacted[] = '';
		}

		return array(
			'value'    => $value,
			'redacted' => $redacted,
		);
	}

	/**
	 * @param array  $tree     Array to walk.
	 * @param string $path     Path so far.
	 * @param array  $redacted Collected paths (by reference).
	 * @return array
	 */
	private static function redact_tree( array $tree, $path, array &$redacted ) {
		foreach ( $tree as $k => $v ) {
			$here = '' === $path ? (string) $k : $path . '.' . $k;

			if ( is_array( $v ) || is_object( $v ) ) {
				$tree[ $k ] = self::redact_tree( (array) $v, $here, $redacted );
				continue;
			}

			if ( is_string( $v ) && is_string( $k ) && self::is_secret_field( $k ) && self::looks_like_secret_value( $v ) ) {
				$tree[ $k ] = self::MASK;
				$redacted[] = $here;
			}
		}

		return $tree;
	}

	/**
	 * @param string $field Field or option name.
	 * @return bool
	 */
	public static function is_secret_field( $field ) {
		return (bool) preg_match( self::SECRET_FIELD_PATTERN, (string) $field );
	}

	/**
	 * A flag is not a secret however it is named:
	 * woocommerce_registration_generate_password = "yes".
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	public static function looks_like_secret_value( $value ) {
		$value = trim( (string) $value );

		if ( in_array( strtolower( $value ), self::$flag_values, true ) ) {
			return false;
		}

		return strlen( $value ) >= 4;
	}

	/**
	 * @param string $key Option name.
	 * @return string|null Plugin directory name that unlocks it.
	 */
	private static function plugin_rule_for( $key ) {
		$active = self::active_plugin_dirs();

		foreach ( self::$plugin_matrix as $dir => $rules ) {
			if ( ! in_array( $dir, $active, true ) ) {
				continue;
			}

			if ( ! empty( $rules['keys'] ) && in_array( $key, $rules['keys'], true ) ) {
				return $dir;
			}

			foreach ( isset( $rules['prefixes'] ) ? $rules['prefixes'] : array() as $prefix ) {
				if ( 0 === strpos( $key, $prefix ) ) {
					return $dir;
				}
			}
		}

		return null;
	}

	/**
	 * Directory names of active plugins (site and network).
	 *
	 * @return array
	 */
	public static function active_plugin_dirs() {
		$files = (array) get_option( 'active_plugins', array() );

		if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_site_option' ) ) {
			$network = get_site_option( 'active_sitewide_plugins', array() );
			if ( is_array( $network ) ) {
				$files = array_merge( $files, array_keys( $network ) );
			}
		}

		$dirs = array();
		foreach ( $files as $file ) {
			if ( ! is_string( $file ) ) {
				continue;
			}
			$dirs[] = dirname( $file ) === '.' ? basename( $file, '.php' ) : dirname( $file );
		}

		return array_values( array_unique( $dirs ) );
	}

	/**
	 * @param string $key Option name.
	 * @return string|null The matching allowlist entry.
	 */
	private static function site_rule_for( $key ) {
		foreach ( self::site_allowlist() as $entry ) {
			if ( '*' === substr( $entry, -1 ) ) {
				if ( 0 === strpos( $key, substr( $entry, 0, -1 ) ) ) {
					return $entry;
				}
			} elseif ( $entry === $key ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * The per-site allowlist an admin maintains through
	 * wp_update_plugin_settings {option_allowlist}.
	 *
	 * @return array
	 */
	public static function site_allowlist() {
		$settings = get_option( 'mcpwp_settings', array() );

		if ( ! is_array( $settings ) || empty( $settings['option_allowlist'] ) || ! is_array( $settings['option_allowlist'] ) ) {
			return array();
		}

		return array_values( array_filter( array_map( array( __CLASS__, 'sanitize_allowlist_entry' ), $settings['option_allowlist'] ) ) );
	}

	/**
	 * An allowlist entry is an exact key or a prefix ending in "*". A blocked
	 * key cannot be allowlisted; the blocklist wins in decide() anyway, but
	 * refusing it here tells the admin at write time.
	 *
	 * @param mixed $entry Submitted entry.
	 * @return string '' when unusable.
	 */
	public static function sanitize_allowlist_entry( $entry ) {
		if ( ! is_string( $entry ) ) {
			return '';
		}

		$entry = trim( $entry );

		if ( '' === $entry || strlen( $entry ) > 191 || ! preg_match( '/^[A-Za-z0-9_\-.:]+\*?$/', $entry ) || '*' === $entry ) {
			return '';
		}

		$probe = rtrim( $entry, '*' );
		$lower = strtolower( $probe );
		foreach ( self::$blocked_prefixes as $prefix ) {
			$p = strtolower( $prefix );
			if ( 0 === strpos( $lower, $p ) || 0 === strpos( $p, $lower ) ) {
				return '';
			}
		}

		return $entry;
	}

	/**
	 * Case-preserving key sanitiser. sanitize_key() lowercases, which made
	 * WPLANG (an allowed core key) unreachable.
	 *
	 * @param mixed $key Raw key.
	 * @return string
	 */
	public static function sanitize_option_name( $key ) {
		return preg_replace( '/[^A-Za-z0-9_\-.:]/', '', (string) $key );
	}
}
