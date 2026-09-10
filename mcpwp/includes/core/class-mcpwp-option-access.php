<?php
/**
 * The one decision about which wp_options an API caller may read or write,
 * and what is masked on the way out (#800).
 *
 * The previous rule was a hardcoded prefix list plus a substring blocklist,
 * wrong in both directions at once: plugin namespaces the site plainly ran
 * were unreachable, a boolean named *_generate_password was refused for its
 * name, and a gateway settings array came back with the live passcode in it.
 *
 * Adversarial review of the first replacement found the harder truths, and
 * they shape this file:
 *
 *   - Redacting by the leaf's own name misses `smtp.pass`, a secret stored
 *     as the VALUE of a non-secret key (reCAPTCHA `[site_key => secret]`),
 *     and a list of keys under a numeric index. Redaction is by PATH — any
 *     ancestor's name counts — and by the value's own shape.
 *   - An option that stores its settings as a JSON string is a tree the
 *     walker never saw. Strings are decoded, walked and re-encoded.
 *   - A self-serve allowlist at the same scope as the gate it lifts is not a
 *     gate. There is a floor no entry can cross: salts, roles, active
 *     plugins, cron, transients, the plugin's own state.
 *   - "Writes may set secrets" was too generous: overwriting a live gateway
 *     credential is payment redirection. A write that would set a
 *     secret-shaped field needs `allow_secrets: true`, said aloud.
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
	 * The plugin's own options an API caller may reach. Everything else under
	 * mcpwp_/spai_ is state, credentials, entitlement or updater control and
	 * is closed — there is no blanket mcpwp_ allow.
	 *
	 * @var array
	 */
	private static $own_allowed_keys = array(
		'mcpwp_site_context',
		'mcpwp_site_context_updated',
		'mcpwp_design_references',
		'mcpwp_site_profile',
		'mcpwp_wc_product_archetypes',
		'mcpwp_action_log_retention_days',
	);

	/**
	 * Never reachable through the generic option API, for any scope, and
	 * never allowlistable. Matched as case-insensitive prefixes.
	 *
	 * @var array
	 */
	private static $blocked_prefixes = array(
		// The plugin's own state and credentials (own_allowed_keys wins first).
		'mcpwp_',
		'spai_',
		// WordPress security material and control surfaces.
		'auth_key',
		'secure_auth_key',
		'logged_in_key',
		'nonce_key',
		'auth_salt',
		'secure_auth_salt',
		'logged_in_salt',
		'nonce_salt',
		'wp_user_roles',
		'active_plugins',
		'active_sitewide_plugins',
		'recently_activated',
		'uninstall_plugins',
		'cron',
		'default_role',
		'users_can_register',
		'db_version',
		'initial_db_version',
		'_transient_',
		'_site_transient_',
		// Payment processors keyed at the top level.
		'stripe_',
		'paypal_',
		'square_',
		'braintree_',
	);

	/**
	 * Name fragments that mark key material or sessions wherever they sit
	 * in an option name. Also never allowlistable.
	 *
	 * @var array
	 */
	private static $blocked_fragments = array( '_session', 'encryption_key', 'private_key', '_salt' );

	/**
	 * A path segment (option name, array key, or any ancestor key) that marks
	 * everything beneath it as secret. Bounded by separators so `key` matches
	 * `consumer_key` and `site_key` but not `monkey`.
	 *
	 * @var string
	 */
	const SECRET_FIELD_PATTERN = '/(^|[_\-.\s])(api_?key|apikey|key|keys|pass|passwd|password|passcode|pwd|pswd|passphrase|secret|secrets|token|tokens|credential|credentials|signature|sig|salt|sid|serial|private|privatekey|license|licence|auth|bearer|jwt|refresh|access)([_\-.\s]|$)/i';

	/**
	 * Values that are flags or identifiers, never secrets, whatever the field
	 * is called.
	 *
	 * @var array
	 */
	private static $flag_values = array( '', '0', '1', 'yes', 'no', 'true', 'false', 'on', 'off', 'enabled', 'disabled', 'none', 'auto', 'default' );

	/**
	 * Option namespaces unlocked by an active plugin, keyed on the plugin
	 * directory name as it appears in active_plugins.
	 *
	 * @var array
	 */
	private static $plugin_matrix = array(
		'megamenu'                        => array( 'prefixes' => array( 'megamenu_' ) ),
		'megamenu-pro'                    => array( 'prefixes' => array( 'megamenu_' ) ),
		'duracelltomi-google-tag-manager' => array( 'keys' => array( 'gtm4wp-options' ) ),
		'wp-mail-smtp'                    => array( 'keys' => array( 'wp_mail_smtp' ), 'prefixes' => array( 'wp_mail_smtp_' ) ),
		'wp-mail-smtp-pro'                => array( 'keys' => array( 'wp_mail_smtp' ), 'prefixes' => array( 'wp_mail_smtp_' ) ),
		'complianz-gdpr'                  => array( 'prefixes' => array( 'cmplz_', 'complianz_' ) ),
		'complianz-gdpr-premium'          => array( 'prefixes' => array( 'cmplz_', 'complianz_' ) ),
		'wpforms-lite'                    => array( 'keys' => array( 'wpforms_settings' ) ),
		'wpforms'                         => array( 'keys' => array( 'wpforms_settings' ) ),
		'contact-form-7'                  => array( 'prefixes' => array( 'wpcf7' ) ),
		'gravityforms'                    => array( 'prefixes' => array( 'gform_', 'rg_gforms_' ) ),
		'learnpress'                      => array( 'prefixes' => array( 'learn_press_' ) ),
		'all-in-one-seo-pack'             => array( 'prefixes' => array( 'aioseo_' ) ),
		'wp-seopress'                     => array( 'prefixes' => array( 'seopress_' ) ),
		'the-events-calendar'             => array( 'prefixes' => array( 'tribe_' ) ),
		'wp-rocket'                       => array( 'keys' => array( 'wp_rocket_settings' ) ),
		'litespeed-cache'                 => array( 'prefixes' => array( 'litespeed.' ) ),
		'autoptimize'                     => array( 'prefixes' => array( 'autoptimize_' ) ),
		'wp-super-cache'                  => array( 'prefixes' => array( 'wpsupercache_' ) ),
		'w3-total-cache'                  => array( 'prefixes' => array( 'w3tc_' ) ),
		'polylang'                        => array( 'keys' => array( 'polylang' ) ),
		'sitepress-multilingual-cms'      => array( 'prefixes' => array( 'icl_' ) ),
		'redirection'                     => array( 'prefixes' => array( 'redirection_' ) ),
		'google-site-kit'                 => array( 'prefixes' => array( 'googlesitekit_' ) ),
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
		'astra-settings', 'generate_settings', 'elementor_pro_theme_builder_conditions',
	);

	/**
	 * Always-on prefixes (builders, SEO, themes, commerce).
	 *
	 * @var array
	 */
	private static $core_prefixes = array(
		'elementor_', 'wpseo_', 'rank_math_', 'astra_', 'ocean_', 'generate_', 'kadence_', 'blocksy_',
		'theme_mods_', 'widget_', 'nav_menu_', 'sidebars_', 'woocommerce_',
	);

	// ---------------------------------------------------------------
	// Access.
	// ---------------------------------------------------------------

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

		if ( in_array( $key, self::$own_allowed_keys, true ) ) {
			return array( 'allowed' => true, 'rule' => 'core_key' );
		}

		$blocked = self::blocked_rule( $key );
		if ( null !== $blocked ) {
			return array(
				'allowed' => false,
				'rule'    => $blocked,
				'hint'    => 'This option is security material, a control surface, or the plugin\'s own state, and is never reachable through the generic option API for any scope — nor can it be allowlisted. Use the dedicated tool for it if one exists (wp_update_plugin_settings, wp_configure_integration, wp_update_rate_limit).',
			);
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
			'hint'    => 'Not a core key, not under a known plugin namespace for a plugin that is active here, and not on this site\'s allowlist. A site administrator can add it in WP Admin or via wp_update_plugin_settings {option_allowlist: [...]}; security material and control surfaces cannot be added.',
		);
	}

	/**
	 * The blocked rule a key trips, or null.
	 *
	 * @param string $key Option name.
	 * @return string|null
	 */
	private static function blocked_rule( $key ) {
		$lower = strtolower( $key );

		foreach ( self::$blocked_prefixes as $prefix ) {
			if ( 0 === strpos( $lower, strtolower( $prefix ) ) ) {
				return 'blocked:' . $prefix;
			}
		}

		foreach ( self::$blocked_fragments as $fragment ) {
			if ( false !== strpos( $lower, $fragment ) ) {
				return 'blocked:*' . $fragment . '*';
			}
		}

		return null;
	}

	// ---------------------------------------------------------------
	// Redaction.
	// ---------------------------------------------------------------

	/**
	 * Mask secret-shaped values on the way out.
	 *
	 * @param string $key   Option name.
	 * @param mixed  $value Stored value.
	 * @return array {value: mixed, redacted: string[], decoded_from?: string} paths use dot notation; '' is the option itself.
	 */
	public static function redact( $key, $value ) {
		$redacted = array();
		$root     = self::is_secret_field( $key );
		$out      = array( 'value' => $value, 'redacted' => array() );

		// A JSON string is a tree the walker would otherwise never see.
		if ( is_string( $value ) ) {
			$decoded = self::decode_json_tree( $value );
			if ( null !== $decoded ) {
				$walked = self::redact_tree( $decoded, '', $root, $redacted );
				if ( ! empty( $redacted ) ) {
					$out['value']        = wp_json_encode( $walked );
					$out['decoded_from'] = 'json';
				}
				$out['redacted'] = $redacted;
				return $out;
			}

			// A serialized string is a tree too; it is unserialized without
			// classes and returned as a structure, never as an unwalked blob.
			if ( function_exists( 'is_serialized' ) && is_serialized( $value ) ) {
				$unserialized = @unserialize( $value, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- classes disallowed; read-only redaction.
				if ( is_array( $unserialized ) ) {
					$out['value']        = self::redact_tree( $unserialized, '', $root, $redacted );
					$out['decoded_from'] = 'serialized';
					$out['redacted']     = $redacted;
					return $out;
				}
			}

			if ( $root && self::looks_like_secret_value( $value ) || self::looks_like_credential( $value ) ) {
				$out['value']    = self::MASK;
				$out['redacted'] = array( '' );
			}

			return $out;
		}

		if ( is_array( $value ) || is_object( $value ) ) {
			$out['value']    = self::redact_tree( (array) $value, '', $root, $redacted );
			$out['redacted'] = $redacted;
		}

		return $out;
	}

	/**
	 * @param array  $tree          Array to walk.
	 * @param string $path          Path so far.
	 * @param bool   $under_secret  Whether an ancestor's name marked this subtree.
	 * @param array  $redacted      Collected paths (by reference).
	 * @return array
	 */
	private static function redact_tree( array $tree, $path, $under_secret, array &$redacted ) {
		foreach ( $tree as $k => $v ) {
			$here   = '' === $path ? (string) $k : $path . '.' . $k;
			$secret = $under_secret || ( is_string( $k ) && self::is_secret_field( $k ) );

			if ( is_array( $v ) || is_object( $v ) ) {
				$tree[ $k ] = self::redact_tree( (array) $v, $here, $secret, $redacted );
				continue;
			}

			if ( ! is_string( $v ) ) {
				continue;
			}

			// A nested JSON string inside the tree is walked as well.
			$nested = self::decode_json_tree( $v );
			if ( null !== $nested ) {
				$before = count( $redacted );
				$walked = self::redact_tree( $nested, $here, $secret, $redacted );
				if ( count( $redacted ) > $before ) {
					$tree[ $k ] = wp_json_encode( $walked );
				}
				continue;
			}

			if ( ( $secret && self::looks_like_secret_value( $v ) ) || self::looks_like_credential( $v ) ) {
				$tree[ $k ] = self::MASK;
				$redacted[] = $here;
			}
		}

		return $tree;
	}

	/**
	 * @param string $value Possibly-JSON string.
	 * @return array|null
	 */
	private static function decode_json_tree( $value ) {
		$trimmed = ltrim( $value );

		if ( '' === $trimmed || ( '{' !== $trimmed[0] && '[' !== $trimmed[0] ) ) {
			return null;
		}

		$decoded = json_decode( $value, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * @param string $field Field or option name.
	 * @return bool
	 */
	public static function is_secret_field( $field ) {
		// camelCase is split so licenseKey / siteKey / clientSecret count.
		$field = preg_replace( '/([a-z0-9])([A-Z])/', '$1_$2', (string) $field );

		return (bool) preg_match( self::SECRET_FIELD_PATTERN, $field );
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
	 * A value that is a credential by its own shape, whatever it is called:
	 * a known key prefix, a JWT, or a long opaque token.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	public static function looks_like_credential( $value ) {
		$value = trim( (string) $value );

		if ( strlen( $value ) < 16 || false !== strpos( $value, ' ' ) || false !== strpos( $value, '://' ) || false !== strpos( $value, '@' ) ) {
			return false;
		}

		if ( preg_match( '/^(sk|pk|rk|whsec|ghp|gho|ghu|glpat|xox[abpr]|AKIA|ASIA|mcpwp|spai|SG\.|key-|live_|test_)[A-Za-z0-9_\-.]{8,}$/', $value ) ) {
			return true;
		}

		if ( preg_match( '/^eyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]*$/', $value ) ) {
			return true;
		}

		// Long, opaque, mixed: hex/base64-ish with digits and letters and no
		// structure a human would type. Slugs, titles and URLs never look
		// like this.
		return strlen( $value ) >= 32
			&& (bool) preg_match( '/^[A-Za-z0-9+\/=_\-]+$/', $value )
			&& (bool) preg_match( '/[0-9]/', $value )
			&& (bool) preg_match( '/[A-Za-z]/', $value )
			&& ! preg_match( '/^[a-z0-9]+(-[a-z0-9]+)+$/', $value );
	}

	/**
	 * Paths in a submitted value that would set a secret-shaped field.
	 *
	 * @param string $key   Option name.
	 * @param mixed  $value Submitted value.
	 * @return array Paths ('' for the option itself).
	 */
	public static function secret_paths_in_write( $key, $value ) {
		$paths = array();
		$root  = self::is_secret_field( $key );

		if ( is_array( $value ) || is_object( $value ) ) {
			self::collect_secret_paths( (array) $value, '', $root, $paths );
			return $paths;
		}

		if ( is_string( $value ) ) {
			$decoded = self::decode_json_tree( $value );
			if ( null !== $decoded ) {
				self::collect_secret_paths( $decoded, '', $root, $paths );
				return $paths;
			}
			if ( ( $root && self::looks_like_secret_value( $value ) ) || self::looks_like_credential( $value ) ) {
				$paths[] = '';
			}
		}

		return $paths;
	}

	/**
	 * @param array  $tree         Tree.
	 * @param string $path         Path so far.
	 * @param bool   $under_secret Ancestor marked.
	 * @param array  $paths        Collected (by reference).
	 */
	private static function collect_secret_paths( array $tree, $path, $under_secret, array &$paths ) {
		foreach ( $tree as $k => $v ) {
			$here   = '' === $path ? (string) $k : $path . '.' . $k;
			$secret = $under_secret || ( is_string( $k ) && self::is_secret_field( $k ) );

			if ( is_array( $v ) || is_object( $v ) ) {
				self::collect_secret_paths( (array) $v, $here, $secret, $paths );
				continue;
			}

			if ( is_string( $v ) && ( ( $secret && self::looks_like_secret_value( $v ) ) || self::looks_like_credential( $v ) ) ) {
				$paths[] = $here;
			}
		}
	}

	// ---------------------------------------------------------------
	// Plugin matrix and per-site allowlist.
	// ---------------------------------------------------------------

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
	 * The per-site allowlist an admin maintains.
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
	 * Minimum characters before a wildcard: "w*" is not a namespace.
	 */
	const MIN_WILDCARD_PREFIX = 6;

	/**
	 * An allowlist entry is an exact key or a prefix of at least six
	 * characters ending in "*". Nothing the floor covers can be entered, in
	 * either direction: neither "auth_salt" nor "auth*".
	 *
	 * @param mixed $entry Submitted entry.
	 * @return string '' when unusable.
	 */
	public static function sanitize_allowlist_entry( $entry ) {
		if ( ! is_string( $entry ) ) {
			return '';
		}

		$entry = trim( $entry );

		if ( '' === $entry || strlen( $entry ) > 191 || ! preg_match( '/^[A-Za-z0-9_\-.:]+\*?$/', $entry ) ) {
			return '';
		}

		$wildcard = '*' === substr( $entry, -1 );
		$probe    = $wildcard ? substr( $entry, 0, -1 ) : $entry;

		if ( $wildcard && strlen( $probe ) < self::MIN_WILDCARD_PREFIX ) {
			return '';
		}

		if ( null !== self::blocked_rule( $probe ) ) {
			return '';
		}

		// A wildcard that could grow to cover a blocked name is refused too.
		if ( $wildcard ) {
			$lower = strtolower( $probe );
			foreach ( self::$blocked_prefixes as $prefix ) {
				if ( 0 === strpos( strtolower( $prefix ), $lower ) ) {
					return '';
				}
			}
		}

		return $entry;
	}

	/**
	 * Case-preserving key check. sanitize_key() lowercases, which made
	 * WPLANG (an allowed core key) unreachable. A name with characters
	 * outside the option charset is refused, not coerced onto a different
	 * option.
	 *
	 * @param mixed $key Raw key.
	 * @return string '' when unusable.
	 */
	public static function sanitize_option_name( $key ) {
		$key = trim( (string) $key );

		return preg_match( '/^[A-Za-z0-9_\-.:]{1,191}$/', $key ) ? $key : '';
	}
}
