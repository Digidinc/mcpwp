<?php
/**
 * The Freemius plugin icon must name a file the premium package ships.
 *
 * includes/freemius-init.php hands the SDK's `plugin_icon` filter a local
 * path, and Freemius::get_local_icon_url() turns that path into an <img> URL
 * on the opt-in, connect and pricing screens. Every build stripped
 * assets/icon-*.png, so the premium package shipped a broken image there.
 * The zip-level check is scripts/verify_plugin_assets.sh; this covers the
 * source side and the filter's fallback.
 *
 * @package MCPWP
 */
use PHPUnit\Framework\TestCase;

final class FreemiusPluginIconTest extends TestCase {

	private static function plugin_dir() {
		return dirname( __DIR__ ) . '/';
	}

	private static function bootstrap_source() {
		return (string) file_get_contents( self::plugin_dir() . 'includes/freemius-init.php' );
	}

	/**
	 * Every image path freemius-init.php builds from MCPWP_PLUGIN_DIR.
	 *
	 * @return string[]
	 */
	private static function referenced_icon_paths() {
		preg_match_all( "/MCPWP_PLUGIN_DIR\s*\.\s*'([^']+\.(?:png|svg|jpe?g|gif|webp))'/", self::bootstrap_source(), $m );
		return array_values( array_unique( $m[1] ) );
	}

	/**
	 * Load mcpwp_fs_custom_icon() on its own, with MCPWP_PLUGIN_DIR pointed at
	 * $plugin_dir, without booting the Freemius SDK the rest of the file needs.
	 */
	private static function lift_icon_filter( $plugin_dir ) {
		$src = self::bootstrap_source();
		if ( 1 !== preg_match( '/\nfunction mcpwp_fs_custom_icon\(.*?\n\}\n/s', $src, $m ) ) {
			self::fail( 'mcpwp_fs_custom_icon() not found in includes/freemius-init.php' );
		}
		$fn     = str_replace( 'MCPWP_PLUGIN_DIR', var_export( $plugin_dir, true ), $m[0] );
		$lifted = tempnam( sys_get_temp_dir(), 'mcpwp-fs-icon' );
		file_put_contents( $lifted, "<?php\n" . $fn );
		include $lifted;
		unlink( $lifted );
	}

	public function test_the_bootstrap_references_exactly_the_icon_the_freemius_build_keeps() {
		$refs = self::referenced_icon_paths();
		$this->assertSame( array( 'assets/icon-128x128.png' ), $refs );

		$build = (string) file_get_contents( self::plugin_dir() . 'scripts/build-freemius.sh' );
		$this->assertStringContainsString( 'FREEMIUS_ICON="' . $refs[0] . '"', $build, 'build-freemius.sh must keep the icon freemius-init.php references' );
		$this->assertStringNotContainsString( 'rm -f "$DEST"/assets/icon-*.png', $build, 'build-freemius.sh strips every icon again' );
		$this->assertStringContainsString( '[[ "$pattern" == "/assets/*.png" ]] && continue', $build, 'build-freemius.sh re-applies the .distignore /assets/*.png exclusion' );
	}

	public function test_the_referenced_icon_exists_in_the_source_tree_and_is_a_png() {
		foreach ( self::referenced_icon_paths() as $rel ) {
			$path = self::plugin_dir() . $rel;
			$this->assertFileExists( $path );
			$this->assertSame( "\x89PNG", substr( (string) file_get_contents( $path ), 0, 4 ), $rel . ' is not a PNG' );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_filter_returns_the_shipped_icon_when_present() {
		self::lift_icon_filter( self::plugin_dir() );
		$this->assertSame( self::plugin_dir() . 'assets/icon-128x128.png', mcpwp_fs_custom_icon( false ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_missing_icon_falls_back_to_the_sdk_default_icon() {
		$empty = sys_get_temp_dir() . '/mcpwp-fs-icon-' . uniqid() . '/';
		mkdir( $empty );
		define( 'WP_FS__DIR_IMG', self::plugin_dir() . 'freemius/assets/img' );
		self::lift_icon_filter( $empty );

		$this->assertFileExists( WP_FS__DIR_IMG . '/plugin-icon.png' );
		$this->assertSame( WP_FS__DIR_IMG . '/plugin-icon.png', mcpwp_fs_custom_icon( false ) );
		rmdir( $empty );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_missing_icon_without_the_sdk_hands_back_the_default_value() {
		$empty = sys_get_temp_dir() . '/mcpwp-fs-icon-' . uniqid() . '/';
		mkdir( $empty );
		self::lift_icon_filter( $empty );

		// false tells Freemius to run its own icon lookup instead of a dead path.
		$this->assertFalse( mcpwp_fs_custom_icon( false ) );
		rmdir( $empty );
	}
}
