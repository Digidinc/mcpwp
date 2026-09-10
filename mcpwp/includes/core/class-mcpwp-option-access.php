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
	private static $blocked_fragments = array( '_session', 'encryption_key', 'private_key', '_salt', 'user_roles' );

	/**
	 * Readable but never writable through the generic option API: a write
	 * here is a site takeover, a lockout or a file-write location, and none
	 * of them is a "setting" an agent edits. Also never allowlistable.
	 *
	 * @var array
	 */
	private static $write_protected = array(
		'siteurl', 'home', 'admin_email', 'new_admin_email', 'template', 'stylesheet', 'upload_path',
		'upload_url_path', 'rewrite_rules', 'mailserver_login', 'mailserver_pass', 'mailserver_url',
		'mailserver_port', 'ping_sites', 'theme_switched', 'wp_force_deactivated_plugins',
		'https_detection_errors', 'recovery_keys', 'fresh_site',
	);

	/**
	 * Write-protected prefixes.
	 *
	 * @var array
	 */
	private static $write_protected_prefixes = array( 'auto_update_' );

	/**
	 * Field-name suffixes that describe a secret without being one: a
	 * licence STATUS, an access LEVEL, an auth TYPE. Bounded matching on
	 * `license`/`access`/`auth` would otherwise mask 'valid' and 'login'.
	 *
	 * @var string
	 */
	const NOT_SECRET_SUFFIX_PATTERN = '/(_status|_expires|_expiry|_expiration|_level|_type|_interval|_label|_note|_display|_mode|_count|_enabled|_date|_at|_id|_name|_title|_version|_ttl|_length|_format|_placement)$/i';

	/**
	 * A path segment (option name, array key, or any ancestor key) that marks
	 * everything beneath it as secret. Bounded by separators so `key` matches
	 * `consumer_key` and `site_key` but not `monkey`.
	 *
	 * @var string
	 */
	const SECRET_FIELD_PATTERN = '/(^|[_\-.\s])(api_?key|apikey|key|keys|pass|passwd|password|passcode|pwd|pw|pswd|passphrase|pin|otp|cvv|secret|secrets|token|tokens|credential|credentials|signature|sig|salt|sid|serial|private|privatekey|license|licence|auth|bearer|jwt|refresh|access|dsn|nonce|webhook|webhook_url|cert|pem)([_\-.\s]|$)/i';

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
	public static function decide( $key, $mode = 'read' ) {
		$key = (string) $key;

		if ( '' === $key ) {
			return array( 'allowed' => false, 'rule' => 'empty_key' );
		}

		if ( 'write' === $mode ) {
			$protected = self::write_protected_rule( $key );
			if ( null !== $protected ) {
				return array(
					'allowed' => false,
					'rule'    => $protected,
					'hint'    => 'This option is readable but a write to it is a site takeover, a lockout or a file-write location, so the generic option API never writes it. Change it in WP Admin.',
				);
			}
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
	 * @param string $key Option name.
	 * @return string|null
	 */
	private static function write_protected_rule( $key ) {
		$lower = strtolower( $key );

		if ( in_array( $lower, self::$write_protected, true ) ) {
			return 'write_protected:' . $lower;
		}

		foreach ( self::$write_protected_prefixes as $prefix ) {
			if ( 0 === strpos( $lower, $prefix ) ) {
				return 'write_protected:' . $prefix;
			}
		}

		return null;
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

		$form = 'native';
		$tree = self::as_tree( $value, $form );

		if ( null === $tree ) {
			if ( is_string( $value ) && ( ( $root && self::looks_like_secret_value( $value ) ) || self::looks_like_credential( $value ) ) ) {
				$out['value']    = self::MASK;
				$out['redacted'] = array( '' );
			}
			return $out;
		}

		// Every encoded string leaf — JSON or serialised, at any depth — is
		// opened before the walk and closed after it, so the walker only
		// ever sees plain trees. That is the one fix for the family of
		// "correct one encoding level deep" defects (#800 round 4).
		$forms    = array();
		$expanded = self::expand( $tree, array(), $forms, 'native' !== $form );
		$paths    = array();
		$walked   = self::redact_tree( $expanded, array(), $root, $paths );

		foreach ( $paths as $segments ) {
			$redacted[] = implode( '.', $segments );
		}

		$closed = self::collapse( $walked, array(), $forms );
		$out['redacted'] = $redacted;

		// Opaque blobs are reported: "not walked" must never read as "clean".
		$opaque = array();
		self::collect_opaque_paths( $walked, array(), $opaque );
		if ( ! empty( $opaque ) ) {
			$out['unwalked'] = $opaque;
		}

		if ( 'json' === $form ) {
			$out['value']        = empty( $redacted ) ? $value : wp_json_encode( self::root_is_object( $value ) ? (object) $closed : $closed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			$out['decoded_from'] = 'json';
		} elseif ( 'serialized' === $form ) {
			// Returned as a structure, never as an unwalked blob.
			$out['value']        = $closed;
			$out['decoded_from'] = 'serialized';
		} else {
			$out['value'] = is_object( $value ) ? (object) $closed : $closed;
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
	private static function redact_tree( array $tree, array $path, $under_secret, array &$redacted ) {
		foreach ( $tree as $k => $v ) {
			$here   = array_merge( $path, array( $k ) );
			$secret = $under_secret || ( is_string( $k ) && self::is_secret_field( $k ) );

			if ( is_array( $v ) || is_object( $v ) ) {
				$tree[ $k ] = self::redact_tree( (array) $v, $here, $secret, $redacted );
				continue;
			}

			// A numeric PIN/OTP/passcode is a secret too.
			if ( ( is_int( $v ) || is_float( $v ) ) && $secret ) {
				$tree[ $k ] = self::MASK;
				$redacted[] = $here;
				continue;
			}

			if ( is_string( $v ) && ( ( $secret && ( self::looks_like_secret_value( $v ) || self::is_opaque_serialized( $v ) ) ) || self::looks_like_credential( $v ) ) ) {
				$tree[ $k ] = self::MASK;
				$redacted[] = $here;
			}
		}

		return $tree;
	}

	/**
	 * Paths of serialised blobs the walker refused to open.
	 *
	 * @param array $tree  Expanded tree.
	 * @param array $path  Segments.
	 * @param array $paths Collected dotted paths (by reference).
	 */
	private static function collect_opaque_paths( array $tree, array $path, array &$paths ) {
		foreach ( $tree as $k => $v ) {
			$here = array_merge( $path, array( $k ) );
			if ( is_array( $v ) ) {
				self::collect_opaque_paths( $v, $here, $paths );
			} elseif ( self::is_opaque_serialized( $v ) ) {
				$paths[] = implode( '.', $here );
			}
		}
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
	 * Decode a string that carries a tree: a JSON object/array, or a PHP
	 * serialised array (classes never allowed).
	 *
	 * @param mixed       $value Value.
	 * @param string|null $form  Detected form by reference: json|serialized, or null.
	 * @return array|null
	 */
	private static function decode_leaf( $value, &$form ) {
		$form = null;

		if ( ! is_string( $value ) ) {
			return null;
		}

		$trimmed = ltrim( $value );
		if ( '' !== $trimmed && ( '{' === $trimmed[0] || '[' === $trimmed[0] ) ) {
			// Decoded as OBJECTS so a {} with numeric keys, or an empty {},
			// is remembered as an object and closes as one (#800 round 5).
			$decoded = json_decode( $value );
			if ( is_array( $decoded ) || $decoded instanceof stdClass ) {
				$form = 'json';
				return $decoded;
			}
			return null;
		}

		if ( function_exists( 'is_serialized' ) && is_serialized( $value ) ) {
			$unserialized = @unserialize( $value, array( 'allowed_classes' => array( 'stdClass' ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- only stdClass is ever revived.
			if ( ( is_array( $unserialized ) || $unserialized instanceof stdClass ) && ! self::has_incomplete_class( $unserialized ) ) {
				$form = 'serialized';
				return $unserialized;
			}
		}

		return null;
	}

	/**
	 * A serialised string that revives a class this plugin will not
	 * instantiate is opaque: never walked, never re-encoded.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function has_incomplete_class( $value ) {
		if ( $value instanceof __PHP_Incomplete_Class ) {
			return true;
		}
		if ( is_object( $value ) ) {
			$value = (array) $value;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $v ) {
				if ( self::has_incomplete_class( $v ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Whether a string is a serialised value this plugin refuses to open —
	 * an object of a class other than stdClass. Such a blob may hold a
	 * credential; every gate treats it as one.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function is_opaque_serialized( $value ) {
		if ( ! is_string( $value ) || ! function_exists( 'is_serialized' ) || ! is_serialized( $value ) ) {
			return false;
		}
		$form = null;
		return null === self::decode_leaf( $value, $form ) && false !== strpos( $value, 'O:' );
	}

	/**
	 * @param array  $tree Tree.
	 * @param string $form json|serialized.
	 * @return string
	 */
	private static function encode_leaf( $tree, $form ) {
		return 'serialized' === $form ? serialize( $tree ) : wp_json_encode( $tree, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- the value was stored serialised; it goes back the same way.
	}

	/**
	 * Open every encoded string leaf in a tree, at any depth, so a walker
	 * only ever sees plain arrays. The forms and the original strings are
	 * kept, keyed by path, for collapse().
	 *
	 * @param array $tree  Tree.
	 * @param array $path  Segments so far.
	 * @param array $forms Collected by reference: json-path => {form, raw}.
	 * @return array
	 */
	private static function expand( array $tree, array $path, array &$forms, $inside_encoded = false ) {
		foreach ( $tree as $k => $v ) {
			$here = array_merge( $path, array( $k ) );

			if ( $v instanceof stdClass ) {
				// An object is remembered as one only where its shape is
				// bytes — inside an encoded leaf or an encoded root. In a
				// native option tree WordPress code reads arrays.
				if ( $inside_encoded ) {
					$forms[ wp_json_encode( $here ) ] = array( 'form' => 'object' );
				}
				$v = (array) $v;
			} elseif ( is_object( $v ) ) {
				// Not something this walker opens; left exactly as it is.
				continue;
			}

			if ( is_array( $v ) ) {
				$tree[ $k ] = self::expand( $v, $here, $forms, $inside_encoded );
				continue;
			}

			$form    = null;
			$decoded = self::decode_leaf( $v, $form );
			if ( null !== $decoded ) {
				$id = wp_json_encode( $here );
				if ( $decoded instanceof stdClass ) {
					$decoded = (array) $decoded;
					$root_object = true;
				} else {
					$root_object = false;
				}
				$opened       = self::expand( $decoded, $here, $forms, true );
				$forms[ $id ] = array( 'form' => $form, 'raw' => $v, 'tree' => $opened, 'object' => $root_object );
				$tree[ $k ]   = $opened;
			}
		}

		return $tree;
	}

	/**
	 * Close what expand() opened. A subtree nothing touched goes back as the
	 * exact string it came from; one that was changed is re-encoded in the
	 * form it had.
	 *
	 * @param array $tree    Expanded (and possibly modified) tree.
	 * @param array $path    Segments so far.
	 * @param array $forms   From expand().
	 * @param array $touched Segment arrays of every leaf that was changed.
	 * @return array|string
	 */
	private static function collapse( array $tree, array $path, array $forms, array $touched = array() ) {
		// What the walker saw here, before anything below is closed: this is
		// what an untouched leaf is compared against.
		$plain = $tree;

		foreach ( $tree as $k => $v ) {
			if ( is_array( $v ) ) {
				$tree[ $k ] = self::collapse( $v, array_merge( $path, array( $k ) ), $forms );
			}
		}

		$id   = wp_json_encode( $path );
		$info = isset( $forms[ $id ] ) ? $forms[ $id ] : null;

		if ( null === $info ) {
			return $tree;
		}

		if ( 'object' === $info['form'] ) {
			return (object) $tree;
		}

		// Byte-identical when nothing under it changed; otherwise re-encoded
		// in the form it had, as the object or list it was (#800 round 5:
		// "touched" meant "a secret was preserved here", and every ordinary
		// edit inside an encoded leaf was thrown away).
		if ( $plain === $info['tree'] ) {
			return $info['raw'];
		}

		return self::encode_leaf( ! empty( $info['object'] ) ? (object) $tree : $tree, $info['form'] );
	}

	/**
	 * Redact a log payload: exact key names from the site's list, the
	 * {key: '<secret option>', value: …} rule, and the plugin-wide name and
	 * shape detectors — through every encoding level, so a credential inside
	 * a JSON string is not "a string" to the log (#800 round 4, P0-2).
	 *
	 * @param mixed $data         Payload.
	 * @param array $keys         Lower-cased exact key names always redacted.
	 * @param bool  $under_secret An ancestor was secret-named.
	 * @return mixed
	 */
	public static function redact_for_log( $data, array $keys, $under_secret = false, $depth = 0 ) {
		if ( is_object( $data ) ) {
			$data = (array) $data;
		}

		if ( is_string( $data ) ) {
			$form    = null;
			$decoded = self::decode_leaf( $data, $form );
			if ( null === $decoded ) {
				return ( $under_secret && self::looks_like_secret_value( $data ) ) || self::looks_like_credential( $data ) || self::is_opaque_serialized( $data ) ? '[redacted]' : $data;
			}
			$decoded = $decoded instanceof stdClass ? (array) $decoded : $decoded;
			$walked  = self::redact_for_log( $decoded, $keys, $under_secret, $depth + 1 );
			return $walked === $decoded ? $data : self::encode_leaf( $walked, $form );
		}

		if ( ! is_array( $data ) ) {
			return $data;
		}

		// {key: 'woocommerce_api_key', value: 'Hunter2!!'} — the option name
		// says the value is a secret even when the value's own shape does not.
		$value_is_secret = isset( $data['key'] ) && is_string( $data['key'] ) && self::is_secret_field( $data['key'] );

		// In the TOP row of a request, `key` is the option name — the thing
		// an audit needs to see. Deeper down, a field called `key` is a
		// field called key (#800 round 5, P1-1).
		$names_an_option = 0 === $depth && isset( $data['key'] ) && is_string( $data['key'] ) && array_key_exists( 'value', $data ) && ! self::looks_like_credential( $data['key'] );

		$out = array();
		foreach ( $data as $k => $v ) {
			if ( $value_is_secret && 'value' === $k ) {
				$out[ $k ] = is_array( $v ) || is_object( $v ) ? self::redact_for_log( $v, $keys, true, $depth + 1 ) : '[redacted]';
				continue;
			}

			$k_norm = is_string( $k ) ? strtolower( $k ) : '';
			if ( '' !== $k_norm && in_array( $k_norm, $keys, true ) ) {
				$out[ $k ] = '[redacted]';
				continue;
			}

			if ( $names_an_option && 'key' === $k ) {
				$out[ $k ] = $v;
				continue;
			}

			$secret = $under_secret || ( is_string( $k ) && self::is_secret_field( $k ) );

			if ( ( is_int( $v ) || is_float( $v ) ) && $secret ) {
				$out[ $k ] = '[redacted]';
				continue;
			}

			$out[ $k ] = self::redact_for_log( $v, $keys, $secret, $depth + 1 );
		}

		return $out;
	}

	/**
	 * Apply a callback to every string leaf of a value, through every
	 * encoding level, and hand the value back in the encodings it arrived
	 * in — byte-identical wherever the callback changed nothing. The
	 * handler's sanitiser uses this so GET → POST is idempotent (#800
	 * round 5, P2-3).
	 *
	 * @param mixed    $value    Value.
	 * @param callable $callback string → string.
	 * @return mixed
	 */
	public static function map_string_leaves( $value, $callback ) {
		if ( is_int( $value ) || is_float( $value ) || is_bool( $value ) || null === $value ) {
			return $value;
		}

		$form = 'native';
		$tree = self::as_tree( $value, $form );

		if ( null === $tree ) {
			return is_string( $value ) ? call_user_func( $callback, $value ) : $value;
		}

		$forms  = array();
		$opened = self::expand( $tree, array(), $forms, 'native' !== $form );
		$mapped = self::map_leaves_in( $opened, $callback );
		$closed = self::collapse( $mapped, array(), $forms );

		if ( 'json' === $form ) {
			return $mapped === $opened ? $value : wp_json_encode( self::root_is_object( $value ) ? (object) $closed : $closed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}
		if ( 'serialized' === $form ) {
			return $mapped === $opened ? $value : serialize( self::root_is_object( $value ) ? (object) $closed : $closed ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- same form it arrived in.
		}

		return is_object( $value ) ? (object) $closed : $closed;
	}

	/**
	 * @param array    $tree     Expanded tree.
	 * @param callable $callback string → string.
	 * @return array
	 */
	private static function map_leaves_in( array $tree, $callback ) {
		foreach ( $tree as $k => $v ) {
			if ( is_array( $v ) ) {
				$tree[ $k ] = self::map_leaves_in( $v, $callback );
			} elseif ( is_string( $v ) && ! self::is_opaque_serialized( $v ) ) {
				$tree[ $k ] = call_user_func( $callback, $v );
			}
		}
		return $tree;
	}

	/**
	 * Whether a path (segment array) is inside a list of paths.
	 *
	 * @param array $needle Segments.
	 * @param array $paths  Segment arrays.
	 * @return bool
	 */
	private static function path_in( array $needle, array $paths ) {
		foreach ( $paths as $p ) {
			if ( $p === $needle ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string $field Field or option name.
	 * @return bool
	 */
	public static function is_secret_field( $field ) {
		// camelCase is split so licenseKey / siteKey / clientSecret count.
		$field = preg_replace( '/([a-z0-9])([A-Z])/', '$1_$2', (string) $field );

		// A descriptive suffix exempts a name unless the name also carries a
		// hard secret stem: password_at is not a timestamp anyone should read.
		if ( preg_match( self::NOT_SECRET_SUFFIX_PATTERN, $field ) && ! preg_match( '/(^|[_\-.\s])(pass|passwd|password|passcode|secret|token|credential|private|api_?key|apikey|key|salt|pin|otp|cvv|signature|jwt|bearer)([_\-.\s]|$)/i', $field ) ) {
			return false;
		}

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

		// A URL that carries its credential IS the credential: user:pass@host,
		// a Sentry DSN, a Slack/Discord webhook, ?key=…/&token=….
		if ( false !== strpos( $value, '://' ) ) {
			return (bool) preg_match( '#://[^/@\s]*:[^/@\s]+@#', $value )
				|| (bool) preg_match( '#://[0-9a-f]{16,}@#i', $value )
				|| (bool) preg_match( '#hooks\.slack\.com/services/|discord(app)?\.com/api/webhooks/#i', $value )
				|| (bool) preg_match( '#[?&][A-Za-z0-9_\-]*(api_?key|apikey|key|token|secret|sig|signature|access_token|auth|password|passwd)=[^&]{6,}#i', $value );
		}

		if ( strlen( $value ) < 16 || false !== strpos( $value, ' ' ) || false !== strpos( $value, '@' ) ) {
			return false;
		}

		// A UUID in any case is an identifier, not a credential.
		if ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value ) ) {
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
	 * Merge a submitted value over the stored one so no secret is destroyed
	 * by accident (#800 rounds 2–3).
	 *
	 * Two ways a credential died silently before this: the caller echoed a
	 * redacted read back (`passcode: "***"`), or edited one setting and sent
	 * the tree without the credential field, which a whole-value replace
	 * then dropped. A third, found in round 3: the option is stored as a JSON
	 * or serialised STRING — the very form redact() hands back — and a string
	 * walked past the merge untouched. Both sides are decoded here, merged as
	 * trees, and re-encoded in the stored value's own form.
	 *
	 * A stored secret is replaced only by a real replacement: a value that is
	 * itself secret-shaped. MASK, an empty string, 'no', a stray character —
	 * none of those is a credential. Clearing a credential on purpose is
	 * `allow_secrets=true` with an explicit empty value.
	 *
	 * Paths are carried as segment arrays, never dot-joined strings, so a
	 * key containing '.' and an object ancestor both round-trip.
	 *
	 * @param string $key           Option name.
	 * @param mixed  $old           Stored value.
	 * @param mixed  $new           Submitted value.
	 * @param bool   $allow_secrets The caller confirmed it means to change credentials.
	 * @return array {value: mixed, preserved: string[], form: string}
	 */
	public static function merge_preserving_secrets( $key, $old, $new, $allow_secrets = false ) {
		$preserved = array();

		if ( is_string( $new ) && self::MASK === trim( $new ) ) {
			return array( 'value' => $old, 'preserved' => array( '' ), 'form' => 'scalar' );
		}

		$old_form = 'native';
		$old_tree = self::as_tree( $old, $old_form );
		$new_form = 'native';
		$new_tree = self::as_tree( $new, $new_form );

		// Scalar secret option replaced by a non-secret-shaped value. Only
		// when what is stored IS a secret: a flag under a secret-ish name
		// (…_generate_password = "no") may be flipped.
		if ( null === $old_tree && null === $new_tree ) {
			if ( is_string( $old ) && self::is_secret_field( $key ) && ! $allow_secrets && ( self::looks_like_secret_value( $old ) || self::looks_like_credential( $old ) ) ) {
				$candidate = is_scalar( $new ) ? (string) $new : '';
				if ( ! self::looks_like_secret_value( $candidate ) && ! self::looks_like_credential( $candidate ) ) {
					return array( 'value' => $old, 'preserved' => array( '' ), 'form' => 'scalar' );
				}
			}
			return array( 'value' => $new, 'preserved' => array(), 'form' => 'scalar' );
		}

		if ( null === $old_tree || null === $new_tree ) {
			return array( 'value' => $new, 'preserved' => array(), 'form' => $new_form );
		}

		// Both sides fully opened: a credential inside a JSON string inside
		// a serialised array is a leaf like any other from here on.
		$old_forms = array();
		$new_forms = array();
		$old_x     = self::expand( $old_tree, array(), $old_forms, 'native' !== $old_form );
		$new_x     = self::expand( $new_tree, array(), $new_forms, 'native' !== $old_form );

		$old_secret_paths = array();
		self::collect_secret_paths_including_masked( $old_x, array(), self::is_secret_field( $key ), $old_secret_paths );

		foreach ( $old_secret_paths as $segments ) {
			$old_leaf = self::path_get( $old_x, $segments, $old_exists );
			$new_leaf = self::path_get( $new_x, $segments, $exists );

			if ( ! $old_exists ) {
				continue;
			}

			$is_mask    = is_string( $new_leaf ) && self::MASK === trim( $new_leaf );
			$is_replace = ( is_string( $new_leaf ) && ( self::looks_like_secret_value( $new_leaf ) || self::looks_like_credential( $new_leaf ) ) )
				|| is_int( $new_leaf ) || is_float( $new_leaf );

			// The stored leaf is a flag, not a secret: nothing to preserve.
			if ( ! $is_mask && is_string( $old_leaf ) && ! self::looks_like_secret_value( $old_leaf ) && ! self::looks_like_credential( $old_leaf ) ) {
				continue;
			}

			// Missing, masked, or not a credential-shaped replacement: keep
			// the stored secret — unless the caller said allow_secrets, in
			// which case an explicit non-mask value (including '') is an
			// intentional clear.
			if ( ! $exists || $is_mask || ( ! $is_replace && ! $allow_secrets ) ) {
				$new_x       = self::path_set( $new_x, $segments, $old_leaf );
				$preserved[] = implode( '.', $segments );
			}
		}

		// Encoded leaves close in the form the SUBMISSION gave them — the
		// caller's bytes are the ones that must land — and a leaf only the
		// stored value had (restored by a preserve) closes in the stored
		// form. An untouched leaf comes back byte-identical to what was
		// submitted, a changed one is re-encoded (#800 round 5).
		$forms = $old_forms;
		foreach ( $new_forms as $id => $info ) {
			$forms[ $id ] = $info;
		}
		$value = self::collapse( $new_x, array(), $forms );

		// The stored value keeps its own top-level form: a JSON-string option
		// goes back as a JSON string, a serialised-string option as the
		// serialised string get_option() handed out (WordPress serialises a
		// serialised string a second time, so what was read as a string is
		// written as a string). An object root stays an object.
		$object_root = self::root_is_object( $old ) || self::root_is_object( $new );
		if ( 'json' === $old_form ) {
			$value = wp_json_encode( $object_root ? (object) $value : $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		} elseif ( 'serialized' === $old_form ) {
			$value = serialize( $object_root ? (object) $value : $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- stored form.
		} elseif ( is_object( $old ) ) {
			$value = (object) $value;
		}

		return array( 'value' => $value, 'preserved' => $preserved, 'form' => $old_form );
	}

	/**
	 * A value as a tree, whatever form it was stored or submitted in.
	 *
	 * @param mixed  $value Value.
	 * @param string $form  Detected form, by reference: native|json|serialized.
	 * @return array|null Null when the value is not a tree.
	 */
	private static function as_tree( $value, &$form ) {
		$form = 'native';

		if ( is_array( $value ) ) {
			return $value;
		}

		if ( is_object( $value ) ) {
			return $value instanceof stdClass ? (array) $value : null;
		}

		if ( ! is_string( $value ) ) {
			return null;
		}

		$leaf_form = null;
		$decoded   = self::decode_leaf( $value, $leaf_form );
		if ( null === $decoded ) {
			return null;
		}

		$form = $leaf_form;
		return $decoded instanceof stdClass ? (array) $decoded : $decoded;
	}

	/**
	 * Whether a value's root was an object (a JSON {} or a serialised
	 * stdClass) rather than a list — kept so it closes as one.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function root_is_object( $value ) {
		if ( is_object( $value ) ) {
			return true;
		}
		if ( ! is_string( $value ) ) {
			return false;
		}
		$form    = null;
		$decoded = self::decode_leaf( $value, $form );
		return $decoded instanceof stdClass;
	}

	/**
	 * Whether any leaf of a value, in any encoding, is the mask. Such a value
	 * must never be stored: it is a redacted read, not data.
	 *
	 * @param mixed $value Value about to be written.
	 * @return bool
	 */
	public static function contains_mask( $value ) {
		$form = 'native';
		$tree = self::as_tree( $value, $form );

		if ( null === $tree ) {
			return is_string( $value ) && ( self::MASK === trim( $value ) || ( self::is_opaque_serialized( $value ) && false !== strpos( $value, '"' . self::MASK . '"' ) ) );
		}

		$forms = array();
		return self::tree_has_mask( self::expand( $tree, array(), $forms ) );
	}

	/**
	 * @param array $tree Expanded tree.
	 * @return bool
	 */
	private static function tree_has_mask( array $tree ) {
		foreach ( $tree as $v ) {
			if ( is_array( $v ) || is_object( $v ) ) {
				if ( self::tree_has_mask( (array) $v ) ) {
					return true;
				}
				continue;
			}
			if ( is_string( $v ) && ( self::MASK === trim( $v ) || ( self::is_opaque_serialized( $v ) && false !== strpos( $v, '"' . self::MASK . '"' ) ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Like collect_secret_paths() but treats any leaf under a secret-named
	 * path as a secret whatever its shape — the stored value may already be
	 * short, and it still must not be dropped. Paths are segment arrays.
	 *
	 * @param array $tree         Tree.
	 * @param array $path         Segments so far.
	 * @param bool  $under_secret Ancestor marked.
	 * @param array $paths        Collected (by reference).
	 */
	private static function collect_secret_paths_including_masked( array $tree, array $path, $under_secret, array &$paths ) {
		foreach ( $tree as $k => $v ) {
			$here   = array_merge( $path, array( $k ) );
			$secret = $under_secret || ( is_string( $k ) && self::is_secret_field( $k ) );

			if ( is_array( $v ) || is_object( $v ) ) {
				self::collect_secret_paths_including_masked( (array) $v, $here, $secret, $paths );
				continue;
			}

			if ( ( is_string( $v ) && '' !== $v && ( $secret || self::looks_like_credential( $v ) ) ) || ( ( is_int( $v ) || is_float( $v ) ) && $secret ) ) {
				$paths[] = $here;
			}
		}
	}

	/**
	 * @param array $tree     Tree.
	 * @param array $segments Path segments.
	 * @param bool  $exists   Whether the path exists (by reference).
	 * @return mixed
	 */
	private static function path_get( array $tree, array $segments, &$exists = null ) {
		$node   = $tree;
		$exists = true;
		foreach ( $segments as $segment ) {
			if ( is_object( $node ) ) {
				$node = (array) $node;
			}
			if ( ! is_array( $node ) || ! array_key_exists( $segment, $node ) ) {
				$exists = false;
				return null;
			}
			$node = $node[ $segment ];
		}
		return $node;
	}

	/**
	 * @param array $tree     Tree.
	 * @param array $segments Path segments.
	 * @param mixed $value    Value to set.
	 * @return array
	 */
	private static function path_set( array $tree, array $segments, $value ) {
		$segment = array_shift( $segments );
		if ( empty( $segments ) ) {
			$tree[ $segment ] = $value;
			return $tree;
		}
		$child = isset( $tree[ $segment ] ) ? $tree[ $segment ] : array();
		if ( is_object( $child ) ) {
			$child = (array) $child;
		}
		if ( ! is_array( $child ) ) {
			$child = array();
		}
		$tree[ $segment ] = self::path_set( $child, $segments, $value );
		return $tree;
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

		$form = 'native';
		$tree = self::as_tree( $value, $form );

		if ( null !== $tree ) {
			$forms    = array();
			$expanded = self::expand( $tree, array(), $forms );
			$found    = array();
			self::collect_secret_paths( $expanded, array(), $root, $found );
			foreach ( $found as $segments ) {
				$paths[] = implode( '.', $segments );
			}
			return $paths;
		}

		if ( is_string( $value ) && ( ( $root && self::looks_like_secret_value( $value ) ) || self::looks_like_credential( $value ) || self::is_opaque_serialized( $value ) ) ) {
			$paths[] = '';
		}

		return $paths;
	}

	/**
	 * @param array  $tree         Tree.
	 * @param string $path         Path so far.
	 * @param bool   $under_secret Ancestor marked.
	 * @param array  $paths        Collected (by reference).
	 */
	private static function collect_secret_paths( array $tree, array $path, $under_secret, array &$paths ) {
		foreach ( $tree as $k => $v ) {
			$here   = array_merge( $path, array( $k ) );
			$secret = $under_secret || ( is_string( $k ) && self::is_secret_field( $k ) );

			if ( is_array( $v ) || is_object( $v ) ) {
				self::collect_secret_paths( (array) $v, $here, $secret, $paths );
				continue;
			}

			if ( is_string( $v ) && ( ( $secret && self::looks_like_secret_value( $v ) ) || self::looks_like_credential( $v ) || self::is_opaque_serialized( $v ) ) ) {
				$paths[] = $here;
			} elseif ( ( is_int( $v ) || is_float( $v ) ) && $secret ) {
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

		if ( null !== self::blocked_rule( $probe ) || null !== self::write_protected_rule( $probe ) ) {
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
