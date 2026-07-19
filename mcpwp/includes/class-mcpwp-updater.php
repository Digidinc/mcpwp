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
	 * @var string
	 */
	private $version_url = 'https://mumega.com/mcp-updates/version.json';

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
	 * Initialize the updater.
	 */
	public function __construct() {
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
			return false;
		}

		// Host-pin the package source for parity with the caller-supplied
		// package_url path (is_trusted_package_url). HTTPS alone let a
		// hijacked/injected/MITM'd manifest point the auto-updater at an
		// arbitrary zip; require an allowlisted host too. Applies to both the
		// option manifest and the remote manifest. Self-hosted update channels
		// extend the allowlist via the mcpwp_trusted_package_hosts filter.
		if ( ! Mcpwp_Security::is_trusted_package_url( $data['download_url'] ) ) {
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
	 * Check for plugin updates.
	 *
	 * Hooks into pre_set_site_transient_update_plugins.
	 *
	 * @param object $transient Update transient data.
	 * @return object Modified transient data.
	 */
	public function check_for_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$remote = $this->get_remote_data();

		if ( false === $remote ) {
			return $transient;
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
