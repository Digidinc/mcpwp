<?php
/**
 * Two installed copies of MCPWP: refuse the second, tell the admin.
 *
 * #660 made a second load a silent no-op — the first copy wins and records
 * the loser under `mcpwp_double_load`. That stopped the "plugin already
 * loaded" fatal. It did not stop the situation: a site can carry 3.10.7
 * active beside a 3.10.5 folder after a manual replace (#802), and because
 * the losing copy's main file returns before it registers a single hook,
 * WordPress activates it without a word. Which copy "wins" is then decided
 * by directory order, and a later update of the visible copy can flip it.
 *
 * Two jobs, both small:
 *
 *   - `activated_plugin`: when the thing just activated carries the MCPWP
 *     plugin header and is not this file, deactivate it again and say so.
 *   - `admin_notices`: surface a CURRENT double-load record, and a refusal.
 *     Current means seen in the last day; a duplicate that has been removed
 *     stops producing a notice by itself.
 *
 * Identity is the plugin header, not the folder name — mcpwp-premium/,
 * mcpwp-1/, "mcpwp (2)/" all carry the same header. A file that cannot be
 * read is not a duplicate: refusing on a guess would deactivate whatever
 * unrelated plugin the admin just enabled.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Duplicate-copy guard.
 */
class Mcpwp_Duplicate_Copy {

	/**
	 * Transient holding the last refused activation, for the admin notice.
	 */
	const REFUSED_TRANSIENT = 'mcpwp_duplicate_refused';

	/**
	 * How long a double-load record counts as current.
	 */
	const CURRENT_WINDOW = DAY_IN_SECONDS;

	/** @var string */
	private $own_basename;

	/** @var callable */
	private $deactivate;

	/** @var callable */
	private $read_main_file;

	/**
	 * @param string        $own_basename   This copy's plugin basename.
	 * @param callable|null $deactivate     fn( string $basename ): void — defaults to deactivate_plugins().
	 * @param callable|null $read_main_file fn( string $basename ): string|null — defaults to reading the file head.
	 */
	public function __construct( $own_basename, $deactivate = null, $read_main_file = null ) {
		$this->own_basename   = (string) $own_basename;
		$this->deactivate     = is_callable( $deactivate ) ? $deactivate : array( __CLASS__, 'deactivate_plugin' );
		$this->read_main_file = is_callable( $read_main_file ) ? $read_main_file : array( __CLASS__, 'read_plugin_head' );
	}

	/**
	 * Hook into WordPress.
	 */
	public static function register() {
		if ( ! defined( 'MCPWP_PLUGIN_BASENAME' ) ) {
			return;
		}

		$guard = new self( MCPWP_PLUGIN_BASENAME );

		add_action( 'activated_plugin', array( $guard, 'on_activated_plugin' ), 10, 1 );
		add_action( 'admin_notices', array( $guard, 'render_notices' ) );
	}

	// ---------------------------------------------------------------
	// Decisions (pure).
	// ---------------------------------------------------------------

	/**
	 * Whether a plugin main file's contents identify it as a copy of MCPWP.
	 *
	 * @param string|null $contents File head, or null when unreadable.
	 * @return bool
	 */
	public static function is_mcpwp_main_file( $contents ) {
		if ( ! is_string( $contents ) || '' === $contents ) {
			return false;
		}

		if ( ! preg_match( '/^[ \t\/*#@]*Plugin Name:\s*(.+?)\s*$/mi', $contents, $m ) ) {
			return false;
		}

		$name = trim( $m[1] );

		// "MCPWP" and "MCPWP Premium" are copies. "MCPWP Addon for Forms" is
		// an add-on that depends on us, not a second us.
		return (bool) preg_match( '/^MCPWP(\s+Premium)?$/i', $name );
	}

	/**
	 * @param string      $activated_basename What was just activated.
	 * @param string      $own_basename       This copy.
	 * @param string|null $contents           The activated file's head, or null.
	 * @return bool
	 */
	public static function should_refuse( $activated_basename, $own_basename, $contents ) {
		if ( (string) $activated_basename === (string) $own_basename ) {
			return false;
		}

		return self::is_mcpwp_main_file( $contents );
	}

	/**
	 * Notice text for a double-load record, or null when there is nothing
	 * current to say.
	 *
	 * @param mixed $record The `mcpwp_double_load` option value.
	 * @param int   $now    Current time.
	 * @return string|null
	 */
	public static function double_load_message( $record, $now ) {
		if ( ! is_array( $record ) || empty( $record['ignored_basename'] ) || empty( $record['last_seen'] ) ) {
			return null;
		}

		if ( (int) $now - (int) $record['last_seen'] > self::CURRENT_WINDOW ) {
			return null;
		}

		$active = isset( $record['active_version'] ) ? (string) $record['active_version'] : '';

		return sprintf(
			/* translators: 1: plugin basename of the ignored copy 2: version of the copy that is running */
			__( 'MCPWP is installed twice. The copy at %1$s is being ignored and MCPWP %2$s is the one running. Delete the extra folder from Plugins so an update cannot switch which copy runs.', 'mcpwp' ),
			$record['ignored_basename'],
			'' !== $active ? $active : __( '(unknown version)', 'mcpwp' )
		);
	}

	/**
	 * Notice text for a refused activation.
	 *
	 * @param array $refused {basename, version}.
	 * @return string
	 */
	public static function refused_message( array $refused ) {
		$basename = isset( $refused['basename'] ) ? (string) $refused['basename'] : '';
		$version  = isset( $refused['version'] ) && '' !== $refused['version'] ? (string) $refused['version'] : __( 'unknown version', 'mcpwp' );

		return sprintf(
			/* translators: 1: plugin basename 2: its version */
			__( 'A second copy of MCPWP (%1$s, %2$s) was activated and has been deactivated again: only one copy can run. Delete the extra folder from Plugins.', 'mcpwp' ),
			$basename,
			$version
		);
	}

	// ---------------------------------------------------------------
	// Hooks.
	// ---------------------------------------------------------------

	/**
	 * `activated_plugin` handler.
	 *
	 * @param string $basename The plugin that was just activated.
	 */
	public function on_activated_plugin( $basename ) {
		$basename = (string) $basename;
		$contents = call_user_func( $this->read_main_file, $basename );

		if ( ! self::should_refuse( $basename, $this->own_basename, $contents ) ) {
			return;
		}

		call_user_func( $this->deactivate, $basename );

		set_transient(
			self::REFUSED_TRANSIENT,
			array(
				'basename' => $basename,
				'version'  => self::header_version( $contents ),
				'at'       => time(),
			),
			self::CURRENT_WINDOW
		);
	}

	/**
	 * `admin_notices` handler.
	 */
	public function render_notices() {
		if ( function_exists( 'current_user_can' ) && ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$refused = get_transient( self::REFUSED_TRANSIENT );
		if ( is_array( $refused ) ) {
			delete_transient( self::REFUSED_TRANSIENT );
			self::print_notice( self::refused_message( $refused ) );
		}

		$message = self::double_load_message( get_option( 'mcpwp_double_load' ), time() );
		if ( null !== $message ) {
			self::print_notice( $message );
		}
	}

	// ---------------------------------------------------------------
	// Defaults for the injected collaborators.
	// ---------------------------------------------------------------

	/**
	 * @param string $basename Plugin basename.
	 */
	public static function deactivate_plugin( $basename ) {
		if ( ! function_exists( 'deactivate_plugins' ) && defined( 'ABSPATH' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( function_exists( 'deactivate_plugins' ) ) {
			deactivate_plugins( $basename );
		}
	}

	/**
	 * The first 8 KB of a plugin's main file — the header lives there — or
	 * null when it cannot be read.
	 *
	 * @param string $basename Plugin basename.
	 * @return string|null
	 */
	public static function read_plugin_head( $basename ) {
		if ( ! defined( 'WP_PLUGIN_DIR' ) || '' === $basename || false !== strpos( $basename, '..' ) ) {
			return null;
		}

		$path = WP_PLUGIN_DIR . '/' . ltrim( $basename, '/' );

		if ( ! is_readable( $path ) ) {
			return null;
		}

		$head = file_get_contents( $path, false, null, 0, 8192 ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- local plugin file header.

		return is_string( $head ) ? $head : null;
	}

	/**
	 * @param string|null $contents File head.
	 * @return string
	 */
	private static function header_version( $contents ) {
		if ( is_string( $contents ) && preg_match( '/^[ \t\/*#@]*Version:\s*(.+?)\s*$/mi', $contents, $m ) ) {
			return trim( $m[1] );
		}

		return '';
	}

	/**
	 * @param string $message Plain text.
	 */
	private static function print_notice( $message ) {
		echo '<div class="notice notice-error"><p><strong>MCPWP:</strong> ' . esc_html( $message ) . '</p></div>';
	}
}
