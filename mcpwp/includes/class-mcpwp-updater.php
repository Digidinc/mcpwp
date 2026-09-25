<?php
/**
 * Self-hosted Plugin Updater
 *
 * Merges the optional site override (`mcpwp_update_info`) with the
 * worker-served manifest and prefers the newer valid release.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin updater class.
 */
class Mcpwp_Updater {

	/**
	 * Default URL to the version JSON file.
	 *
	 * This is only ever used as the `get_option( 'mcpwp_version_url', ... )`
	 * fallback — i.e. for a site that has never explicitly saved the option —
	 * so raising it to the canonical channel changes nothing for a site that
	 * already saved a value (including the legacy mumega.com manifest, which
	 * keeps working: both read the same R2 bucket, see CLAUDE.md "Deploy &
	 * Update System").
	 *
	 * @var string
	 */
	private $version_url = 'https://updates.mcpwp.net/version.json';

	/**
	 * Plugin basename (e.g. mcpwp/mcpwp.php).
	 *
	 * @var string
	 */
	private $plugin_basename;

	/**
	 * Plugin slug.
	 *
	 * @var string
	 */
	private $plugin_slug = 'mcpwp';

	/**
	 * Current plugin version.
	 *
	 * @var string
	 */
	private $current_version;

	/**
	 * Cached remote data.
	 *
	 * @var object|null
	 */
	private $remote_data = null;

	/**
	 * Cache key for the transient.
	 *
	 * @var string
	 */
	private $cache_key = 'mcpwp_update_check';

	/**
	 * Cache duration in seconds (12 hours).
	 *
	 * @var int
	 */
	private $cache_duration = 43200;

	/**
	 * Why the last manifest resolution failed, for operator-facing diagnostics.
	 *
	 * One of: version_url_not_https, manifest_fetch_failed, manifest_invalid,
	 * package_host_not_trusted. Null when the last resolution succeeded.
	 *
	 * @var string|null
	 */
	private $last_manifest_error = null;

	/**
	 * Shared instance.
	 *
	 * @var Mcpwp_Updater|null
	 */
	private static $instance = null;

	/**
	 * Shared updater instance.
	 *
	 * Callers that only want to read update state must use this rather than
	 * constructing their own — the constructor registers hooks, so a second
	 * instance would double-register them.
	 *
	 * @return Mcpwp_Updater
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize the updater.
	 */
	public function __construct() {
		if ( null === self::$instance ) {
			self::$instance = $this;
		}

		$this->plugin_basename = MCPWP_PLUGIN_BASENAME;
		$this->current_version = MCPWP_VERSION;

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache' ), 10, 2 );
		add_filter( 'plugin_row_meta', array( $this, 'plugin_row_meta' ), 10, 2 );
	}

	/**
	 * Normalize manifest data into a comparable object.
	 *
	 * @param mixed  $data   Raw manifest data.
	 * @param string $source Source label.
	 * @return object|false Normalized object or false.
	 */
	private function normalize_manifest_data( $data, $source ) {
		if ( empty( $data ) ) {
			return false;
		}

		if ( is_string( $data ) ) {
			$data = json_decode( $data, true );
		}

		if ( is_object( $data ) ) {
			$data = (array) $data;
		}

		if ( ! is_array( $data ) || empty( $data['version'] ) || empty( $data['download_url'] ) ) {
			$this->last_manifest_error = 'manifest_invalid';
			return false;
		}

		// Host-pin the package source for parity with the caller-supplied
		// package_url path (is_trusted_package_url). HTTPS alone let a
		// hijacked/injected/MITM'd manifest point the auto-updater at an
		// arbitrary zip; require an allowlisted host too. Applies to both the
		// option manifest and the remote manifest. Self-hosted update channels
		// extend the allowlist via the mcpwp_trusted_package_hosts filter.
		if ( ! Mcpwp_Security::is_trusted_package_url( $data['download_url'] ) ) {
			$this->last_manifest_error = 'package_host_not_trusted';
			return false;
		}

		$data['_source'] = $source;

		return (object) $data;
	}

	/**
	 * Fetch the site-level override manifest.
	 *
	 * @return object|false Override manifest or false.
	 */
	private function get_option_manifest() {
		$option_data = get_option( 'mcpwp_update_info' );
		$manifest    = $this->normalize_manifest_data( $option_data, 'option' );

		if ( false === $manifest ) {
			return false;
		}

		// Ignore stale overrides older than the installed plugin.
		if ( version_compare( $manifest->version, $this->current_version, '<' ) ) {
			return false;
		}

		return $manifest;
	}

	/**
	 * Fetch the worker/remote manifest.
	 *
	 * @return object|false Remote manifest or false.
	 */
	private function get_remote_manifest() {
		$url = get_option( 'mcpwp_version_url', $this->version_url );
		if ( empty( $url ) ) {
			$url = $this->version_url;
		}

		// Never fetch a manifest over plain HTTP. Custom (even private/LAN)
		// update servers keep working as long as they are HTTPS — the host is
		// intentionally not allowlisted here so legitimate custom servers work.
		if ( ! Mcpwp_Security::is_https_url( $url ) ) {
			$this->last_manifest_error = 'version_url_not_https';
			return false;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			$this->last_manifest_error = 'manifest_fetch_failed';
			return false;
		}

		$body     = wp_remote_retrieve_body( $response );
		$manifest = $this->normalize_manifest_data( $body, 'remote' );

		if ( false === $manifest ) {
			return false;
		}

		$manifest->_url = $url;

		return $manifest;
	}

	/**
	 * Select the best manifest candidate.
	 *
	 * @param object|false $option_manifest Option manifest.
	 * @param object|false $remote_manifest Remote manifest.
	 * @return object|false Selected manifest or false.
	 */
	private function select_manifest( $option_manifest, $remote_manifest ) {
		if ( false === $option_manifest && false === $remote_manifest ) {
			return false;
		}

		if ( false === $option_manifest ) {
			$remote_manifest->_option_version = null;
			$remote_manifest->_remote_version = $remote_manifest->version;
			return $remote_manifest;
		}

		if ( false === $remote_manifest ) {
			$option_manifest->_option_version = $option_manifest->version;
			$option_manifest->_remote_version = null;
			return $option_manifest;
		}

		$comparison = version_compare( $option_manifest->version, $remote_manifest->version );
		$selected   = $comparison > 0 ? $option_manifest : $remote_manifest;

		// Prefer the remote manifest when both versions are equal.
		if ( 0 === $comparison ) {
			$selected = $remote_manifest;
		}

		$selected->_option_version = $option_manifest->version;
		$selected->_remote_version = $remote_manifest->version;

		return $selected;
	}

	/**
	 * Fetch remote version data.
	 *
	 * @param bool $force_refresh Force a fresh check.
	 * @return object|false Remote data object or false on failure.
	 */
	private function get_remote_data( $force_refresh = false ) {
		if ( null !== $this->remote_data && ! $force_refresh ) {
			return $this->remote_data;
		}

		if ( ! $force_refresh ) {
			$cached = get_transient( $this->cache_key );
			if ( false !== $cached ) {
				$this->remote_data = $cached;
				return $this->remote_data;
			}
		}

		$option_manifest = $this->get_option_manifest();
		$remote_manifest = $this->get_remote_manifest();
		$data            = $this->select_manifest( $option_manifest, $remote_manifest );

		if ( empty( $data ) || empty( $data->version ) ) {
			return false;
		}

		$this->remote_data = $data;
		set_transient( $this->cache_key, $data, $this->cache_duration );

		return $this->remote_data;
	}

	/**
	 * Property marking a transient entry as a deliberate, caller-forced install.
	 *
	 * Entries carrying it are owned by the caller (a package_url install, or a
	 * manifest-driven injection made when core never populated the transient) and
	 * must survive check_for_update().
	 *
	 * @var string
	 */
	const FORCED_PACKAGE_FLAG = 'mcpwp_forced_package';

	/**
	 * Whether the transient already carries a forced entry for this plugin.
	 *
	 * @param object $transient Update transient data.
	 * @return bool True when a caller-owned entry is present.
	 */
	private function has_forced_package( $transient ) {
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			return false;
		}

		if ( ! isset( $transient->response[ $this->plugin_basename ] ) ) {
			return false;
		}

		$entry = $transient->response[ $this->plugin_basename ];

		return is_object( $entry ) && ! empty( $entry->{self::FORCED_PACKAGE_FLAG} );
	}

	/**
	 * Build a transient entry that installs a specific package.
	 *
	 * @param string $package     Package zip URL (already host-pinned by the caller).
	 * @param string $new_version Version the package delivers.
	 * @return object Update entry flagged as caller-owned.
	 */
	public function build_forced_entry( $package, $new_version ) {
		$entry              = new stdClass();
		$entry->slug        = $this->plugin_slug;
		$entry->plugin      = $this->plugin_basename;
		$entry->new_version = (string) $new_version;
		$entry->package     = $package;

		$entry->{self::FORCED_PACKAGE_FLAG} = true;

		return $entry;
	}

	/**
	 * Resolve the authoritative update state from this plugin's own channel.
	 *
	 * Single source of truth for "is there an update, and what would we install".
	 * Both /update endpoints read this instead of inferring the answer from
	 * WordPress's update_plugins transient, which is only populated when core's
	 * own request to api.wordpress.org succeeds — an unrelated dependency that
	 * used to strand sites on outdated releases (#716).
	 *
	 * @param bool $force_refresh Bypass the manifest cache.
	 * @return array{
	 *     current_version:string, selected_version:string|null, remote_version:string|null,
	 *     option_version:string|null, source:string|null, package:string|null,
	 *     update_available:bool, reason:string|null
	 * }
	 */
	/**
	 * Human-readable explanation of a get_update_state() reason code.
	 *
	 * Lives here rather than at each call site so the REST responses, the Site
	 * Health test and the admin notice cannot drift apart in what they claim a
	 * given reason means.
	 *
	 * @param string|null $reason Reason code from get_update_state().
	 * @return string Translated explanation.
	 */
	public static function describe_reason( $reason ) {
		switch ( $reason ) {
			case 'already_current':
				return __( 'Already running the latest version.', 'mcpwp' );

			case 'version_url_not_https':
				return __( 'The configured mcpwp_version_url is not HTTPS, so the update manifest was not fetched.', 'mcpwp' );

			case 'manifest_fetch_failed':
			case 'manifest_unavailable':
				return __( 'The update manifest could not be fetched. Check outbound HTTPS access from this site.', 'mcpwp' );

			case 'manifest_invalid':
				return __( 'The update manifest is missing a version or download_url.', 'mcpwp' );

			case 'package_host_not_trusted':
				return __( 'The manifest download_url host is not in the trusted update allowlist, so the package was rejected.', 'mcpwp' );

			case 'not_applicable':
				return __( 'This build does not use the self-hosted update channel.', 'mcpwp' );

			default:
				return __( 'No update available.', 'mcpwp' );
		}
	}

	public function get_update_state( $force_refresh = false ) {
		$state = array(
			'current_version'  => $this->current_version,
			'selected_version' => null,
			'remote_version'   => null,
			'option_version'   => null,
			'source'           => null,
			'package'          => null,
			'update_available' => false,
			'reason'           => null,
		);

		$this->last_manifest_error = null;

		$data = $this->get_remote_data( $force_refresh );

		if ( false === $data ) {
			$state['reason'] = null !== $this->last_manifest_error
				? $this->last_manifest_error
				: 'manifest_unavailable';
			return $state;
		}

		$state['selected_version'] = $data->version;
		$state['remote_version']   = isset( $data->_remote_version ) ? $data->_remote_version : null;
		$state['option_version']   = isset( $data->_option_version ) ? $data->_option_version : null;
		$state['source']           = isset( $data->_source ) ? $data->_source : null;
		$state['package']          = $data->download_url;

		if ( version_compare( $this->current_version, $data->version, '<' ) ) {
			$state['update_available'] = true;
		} else {
			$state['reason'] = 'already_current';
		}

		return $state;
	}

	/**
	 * Check for plugin updates.
	 *
	 * Hooks into pre_set_site_transient_update_plugins.
	 *
	 * @param object $transient Update transient data.
	 * @return object Modified transient data.
	 */
	public function check_for_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		// Deliberately NOT gated on $transient->checked. When core's request to
		// api.wordpress.org fails, wp_update_plugins() still fires this filter but
		// hands over a transient with no `checked` property at all. Gating on it
		// meant a self-hosted manifest we had already fetched successfully was
		// discarded, and the site reported "no update available" while sitting on
		// an outdated (in one case, security-patched) release. This plugin's
		// update channel does not depend on wordpress.org, so neither should the
		// decision. See #716.
		$remote = $this->get_remote_data();

		if ( false === $remote ) {
			return $transient;
		}

		// A caller-supplied package_url install injects its own response entry and
		// then re-saves the transient, which re-enters this filter. Leave that
		// entry alone or the forced install silently reverts to the manifest
		// version. (Before #716 the `checked` guard masked this by accident.)
		if ( $this->has_forced_package( $transient ) ) {
			return $transient;
		}

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
			$transient->no_update = array();
		}

		if ( version_compare( $this->current_version, $remote->version, '<' ) ) {
			$update              = new stdClass();
			$update->slug        = $this->plugin_slug;
			$update->plugin      = $this->plugin_basename;
			$update->new_version = $remote->version;
			$update->url         = isset( $remote->homepage ) ? $remote->homepage : 'https://mcpwp.net/';
			$update->package     = $remote->download_url;
			$update->tested      = isset( $remote->tested ) ? $remote->tested : '';
			$update->requires    = isset( $remote->requires ) ? $remote->requires : '';
			$update->requires_php = isset( $remote->requires_php ) ? $remote->requires_php : '';

			if ( isset( $remote->icons ) ) {
				$update->icons = (array) $remote->icons;
			}

			if ( isset( $remote->banners ) ) {
				$update->banners = (array) $remote->banners;
			}

			$transient->response[ $this->plugin_basename ] = $update;
		} else {
			// No update available — add to no_update to show "up to date".
			$item              = new stdClass();
			$item->slug        = $this->plugin_slug;
			$item->plugin      = $this->plugin_basename;
			$item->new_version = $this->current_version;
			$item->url         = isset( $remote->homepage ) ? $remote->homepage : '';
			$item->package     = '';

			if ( isset( $remote->icons ) ) {
				$item->icons = (array) $remote->icons;
			}

			if ( isset( $remote->banners ) ) {
				$item->banners = (array) $remote->banners;
			}

			$transient->no_update[ $this->plugin_basename ] = $item;
		}

		return $transient;
	}

	/**
	 * Provide plugin information for the "View Details" modal.
	 *
	 * Hooks into plugins_api.
	 *
	 * @param false|object|array $result The result object or array.
	 * @param string             $action The API action.
	 * @param object             $args   Plugin API arguments.
	 * @return false|object
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( $this->plugin_slug !== $args->slug ) {
			return $result;
		}

		$remote = $this->get_remote_data();

		if ( false === $remote ) {
			return $result;
		}

		$info                = new stdClass();
		$info->name          = isset( $remote->name ) ? $remote->name : 'MCPWP';
		$info->slug          = $this->plugin_slug;
		$info->version       = $remote->version;
		$info->author        = isset( $remote->author ) ? sprintf( '<a href="%s">%s</a>', esc_url( isset( $remote->author_homepage ) ? $remote->author_homepage : '' ), esc_html( $remote->author ) ) : '';
		$info->homepage      = isset( $remote->homepage ) ? $remote->homepage : '';
		$info->requires      = isset( $remote->requires ) ? $remote->requires : '';
		$info->tested        = isset( $remote->tested ) ? $remote->tested : '';
		$info->requires_php  = isset( $remote->requires_php ) ? $remote->requires_php : '';
		$info->download_link = $remote->download_url;

		if ( isset( $remote->sections ) ) {
			$info->sections = (array) $remote->sections;
		}

		if ( isset( $remote->banners ) ) {
			$info->banners = (array) $remote->banners;
		}

		if ( isset( $remote->icons ) ) {
			$info->icons = (array) $remote->icons;
		}

		return $info;
	}

	/**
	 * Clear update cache after upgrade.
	 *
	 * @param object $upgrader WP_Upgrader instance.
	 * @param array  $options  Upgrade options.
	 */
	public function clear_cache( $upgrader, $options ) {
		if ( 'update' === $options['action'] && 'plugin' === $options['type'] ) {
			delete_transient( $this->cache_key );
			$this->remote_data = null;
		}
	}

	/**
	 * Add "Check for updates" link to plugin row.
	 *
	 * @param array  $links Plugin row meta links.
	 * @param string $file  Plugin file path.
	 * @return array Modified links.
	 */
	public function plugin_row_meta( $links, $file ) {
		if ( $this->plugin_basename === $file ) {
			$links[] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( wp_nonce_url( admin_url( 'update-core.php?force-check=1' ), 'force-check' ) ),
				esc_html__( 'Check for updates', 'mcpwp' )
			);
		}
		return $links;
	}
}
