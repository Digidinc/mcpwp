<?php
/**
 * #1253 — the two real hops behind "Activate existing license".
 *
 * Hop 1: the URL built by the REAL Freemius::get_reconnect_url() /
 * get_activation_url() passes the plugin's REAL connect_url filter
 * (mcpwp_fs_soften_connect_gate, extracted from includes/freemius-init.php)
 * with require_license still true.
 * Hop 2: the follow-up redirect Freemius::connect_again() builds is run through
 * the same filter with the REAL reconnect-intent marker function.
 *
 * Not runnable without a live SDK: rendering connect.php (the screen the
 * redirect lands on), and connect_again() itself (reads storage and redirects).
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

if ( ! class_exists( 'Mcpwp_Test_Menu_1253' ) ) {
	class Mcpwp_Test_Menu_1253 {
		public function get_slug( $page = '' ) { return 'mcpwp' . ( '' === $page ? '' : '-' . $page ); }
		public function is_top_level() { return true; }
		public function is_cpt() { return false; }
	}
	class Mcpwp_Test_Logger_1253 {
		public function entrance( ...$a ) {}
	}
}

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SetupLicenseSdkHops1253Test extends TestCase {

	private function fs() {
		$dir = dirname( __DIR__ );
		if ( ! defined( 'WP_FS__MODULE_TYPE_PLUGIN' ) ) {
			define( 'WP_FS__MODULE_TYPE_PLUGIN', 'plugin' );
		}
		require_once $dir . '/freemius/includes/class-freemius-abstract.php';
		require_once $dir . '/freemius/includes/class-freemius.php';
		if ( ! class_exists( 'Mcpwp_Test_Real_Fs_1253', false ) ) {
			eval( 'class Mcpwp_Test_Real_Fs_1253 extends Freemius {
				public function has_settings_menu() { return true; }
				public function show_opt_in_on_themes_page() { return false; }
				public function is_activation_mode( $and_on = true ) { return true; }
			}' );
		}
		// fs_apply_filter exactly as the SDK defines it (fs-core-functions.php).
		if ( ! function_exists( 'fs_apply_filter' ) ) {
			eval( 'function fs_apply_filter( $module_unique_affix, $tag, $value ) {
				$args = func_get_args();
				return call_user_func_array( "apply_filters", array_merge( array( "fs_{$tag}_{$module_unique_affix}" ), array_slice( $args, 2 ) ) );
			}' );
		}
		$this->load_real_function( 'mcpwp_fs_soften_connect_gate' );
		$this->load_real_function( 'mcpwp_fs_mark_reconnect_intent' );
		// The plugin registers it with $fs->add_filter( 'connect_url', ... ), which is fs_{tag}_{affix}.
		add_filter( 'fs_connect_url_mcpwp', 'mcpwp_fs_soften_connect_gate' );

		// The SDK's class constants/statics read constants its start.php defines; supply only the
		// ones the class asks for (start.php cannot be loaded without WordPress itself).
		$fs = null;
		for ( $i = 0; $i < 40 && null === $fs; $i++ ) {
			try {
				$fs = ( new ReflectionClass( 'Mcpwp_Test_Real_Fs_1253' ) )->newInstanceWithoutConstructor();
			} catch ( Error $e ) {
				if ( 1 !== preg_match( '/Undefined constant "(\w+)"/', $e->getMessage(), $mm ) ) {
					throw $e;
				}
				define( $mm[1], 'WP_FS__SDK_VERSION' === $mm[1] ? '2.0.0' : '' );
			}
		}
		$this->assertNotNull( $fs );
		$set = function ( $prop, $value ) use ( $fs ) {
			$p = new ReflectionProperty( 'Freemius', $prop );
			$p->setAccessible( true );
			$p->setValue( $fs, $value );
		};
		$set( '_slug', 'mcpwp' );
		$set( '_module_type', 'plugin' );
		$set( '_is_network_active', false );
		$set( '_menu', new Mcpwp_Test_Menu_1253() );
		$set( '_logger', new Mcpwp_Test_Logger_1253() );
		return $fs;
	}

	/** Pull a function's real source out of freemius-init.php (it cannot be included without the SDK). */
	private function load_real_function( string $name ): void {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/freemius-init.php' );
		$this->assertSame( 1, preg_match( '/^function ' . preg_quote( $name, '/' ) . '\(.*?^}$/ms', $src, $m ), "$name is in freemius-init.php" );
		eval( $m[0] );
	}

	public function test_hop1_real_reconnect_url_survives_the_real_filter_with_require_license_true(): void {
		$fs  = $this->fs();
		$url = $fs->get_reconnect_url( array( 'require_license' => 'true' ) );
		$this->assertStringContainsString( 'fs_action=reset_anonymous_mode', $url );
		$this->assertStringContainsString( 'require_license=true', $url );
		$this->assertStringNotContainsString( 'require_license=false', $url );
		$this->assertStringContainsString( 'page=mcpwp', $url );
	}

	public function test_hop1_contrast_plain_activation_url_is_softened_by_the_real_filter(): void {
		$fs  = $this->fs();
		$url = $fs->get_activation_url( array( 'require_license' => 'true' ) );
		$this->assertStringContainsString( 'require_license=false', $url, 'This is why get_activation_url() was the wrong call.' );
	}

	public function test_hop2_follow_up_redirect_is_not_softened_while_reconnect_intent_is_set(): void {
		$fs = $this->fs();
		$_GET['fs_action'] = 'reset_anonymous_mode';
		mcpwp_fs_mark_reconnect_intent();
		// connect_again() redirects to get_activation_url( array() ) (class-freemius.php, connect_again()).
		$redirect = $fs->get_activation_url( array() );
		$this->assertStringNotContainsString( 'require_license=false', $redirect );
		unset( $_GET['fs_action'] );
		delete_transient( 'mcpwp_fs_reconnect_intent' );
		$this->assertStringContainsString( 'require_license=false', $fs->get_activation_url( array() ), 'Without the marker it is softened.' );
	}
}
