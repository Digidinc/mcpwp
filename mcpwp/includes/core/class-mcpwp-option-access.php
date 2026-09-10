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

			// A numeric PIN/OTP/passcode is a secret too.
			if ( ( is_int( $v ) || is_float( $v ) ) && $secret ) {
				$tree[ $k ] = self::MASK;
				$redacted[] = $here;
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

		// A descriptive suffix exempts a name unless the name also carries a
		// hard secret stem: password_at is not a timestamp anyone should read.
		if ( preg_match( self::NOT_SECRET_SUFFIX_PATTERN, $field ) && ! preg_match( '/(^|[_\-.\s])(pass|passwd|password|passcode|secret|token|credential|private)([_\-.\s]|$)/i', $field ) ) {
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

		$old_secret_paths = array();
		self::collect_secret_paths_including_masked( $old_tree, array(), self::is_secret_field( $key ), $old_secret_paths );

		foreach ( $old_secret_paths as $segments ) {
			$old_leaf = self::path_get( $old_tree, $segments, $old_exists );
			$new_leaf = self::path_get( $new_tree, $segments, $exists );

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
				$new_tree    = self::path_set( $new_tree, $segments, $old_leaf );
				$preserved[] = implode( '.', $segments );
			}
		}

		// The stored value keeps its own form: a JSON-string option goes back
		// as a JSON string, a serialised one as the array WordPress serialises.
		$value = $new_tree;
		if ( 'json' === $old_form ) {
			$value = wp_json_encode( $new_tree );
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
			return (array) $value;
		}

		if ( ! is_string( $value ) ) {
			return null;
		}

		$decoded = self::decode_json_tree( $value );
		if ( null !== $decoded ) {
			$form = 'json';
			return $decoded;
		}

		if ( function_exists( 'is_serialized' ) && is_serialized( $value ) ) {
			$unserialized = @unserialize( $value, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- classes disallowed.
			if ( is_array( $unserialized ) ) {
				$form = 'serialized';
				return $unserialized;
			}
		}

		return null;
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
			return is_string( $value ) && self::MASK === trim( $value );
		}

		foreach ( $tree as $v ) {
			if ( is_array( $v ) || is_object( $v ) || ( is_string( $v ) && null !== self::decode_json_tree( $v ) ) ) {
				if ( self::contains_mask( $v ) ) {
					return true;
				}
				continue;
			}
			if ( is_string( $v ) && self::MASK === trim( $v ) ) {
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

		if ( is_array( $value ) || is_object( $value ) ) {
			self::collect_secret_paths( (array) $value, '', $root, $paths );
			return $paths;
		}

		if ( is_string( $value ) ) {
			$decoded = self::decode_json_tree( $value );
			if ( null === $decoded && function_exists( 'is_serialized' ) && is_serialized( $value ) ) {
				$unserialized = @unserialize( $value, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- classes disallowed; inspection only.
				$decoded      = is_array( $unserialized ) ? $unserialized : null;
			}
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
