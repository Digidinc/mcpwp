<?php
/**
 * WordPress.org source and artifact review-surface policy.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class WporgArtifactPolicyTest extends TestCase {

	/** @var string */
	private static $plugin_dir;

	/** @var array<string,mixed> */
	private static $policy;

	/** @var string */
	private static $zip_path;

	public static function setUpBeforeClass(): void {
		self::$plugin_dir = dirname( __DIR__ );
		$policy_json      = file_get_contents( self::$plugin_dir . '/scripts/wporg-artifact-policy.json' );
		if ( false === $policy_json ) {
			throw new RuntimeException( 'Could not read the WordPress.org artifact policy.' );
		}
		self::$policy = json_decode( $policy_json, true, 512, JSON_THROW_ON_ERROR );

		$provided = getenv( 'MCPWP_WPORG_ZIP' );
		if ( false !== $provided && '' !== $provided ) {
			self::$zip_path = $provided;
			return;
		}

		$main = file_get_contents( self::$plugin_dir . '/mcpwp.php' );
		if ( false === $main || 1 !== preg_match( '/^\s*\*\s*Version:\s*(\S+)\s*$/mi', $main, $match ) ) {
			throw new RuntimeException( 'Could not determine the plugin version.' );
		}
		self::$zip_path = self::$plugin_dir . '/scripts/mumega-mcp-' . $match[1] . '.zip';

		exec( 'bash ' . escapeshellarg( self::$plugin_dir . '/scripts/build-wporg.sh' ) . ' 2>&1', $output, $status );
		if ( 0 !== $status ) {
			throw new RuntimeException( "WordPress.org build failed:\n" . implode( "\n", $output ) );
		}
	}

	public function test_runtime_source_has_no_forbidden_paths_or_literals(): void {
		$violations = $this->scan_source();
		$this->assertSame( array(), $violations, implode( "\n", $violations ) );
	}

	public function test_exact_zip_has_no_forbidden_paths_or_literals(): void {
		$violations = $this->scan_zip();
		$this->assertSame( array(), $violations, implode( "\n", $violations ) );
	}

	public function test_retained_source_inventory_is_present(): void {
		foreach ( self::$policy['required_source_files'] as $relative ) {
			$this->assertFileExists( self::$plugin_dir . '/' . $relative, 'Missing retained source file: ' . $relative );
		}
	}

	public function test_free_registry_is_exactly_the_retained_inventory(): void {
		$registry = new Mcpwp_MCP_Free_Tools();
		$actual   = array();
		foreach ( $registry->get_tool_map() as $name => $mapping ) {
			$actual[ $name ] = array( strtoupper( $mapping['method'] ), $mapping['route'] );
		}
		ksort( $actual );
		$expected = self::$policy['retained_tool_map'];
		ksort( $expected );

		$this->assertSame( $expected, $actual );
	}

	public function test_rest_route_inventory_is_exactly_retained_surface(): void {
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		$controllers = array(
			new Mcpwp_REST_Site_Info(),
			new Mcpwp_REST_Site_Settings(),
			new Mcpwp_REST_API_Keys(),
			new Mcpwp_REST_Rate_Limit(),
			new Mcpwp_REST_Posts(),
			new Mcpwp_REST_Pages(),
			new Mcpwp_REST_Media(),
			new Mcpwp_REST_Site_Taxonomy(),
			new Mcpwp_REST_MCP(),
		);
		foreach ( $controllers as $controller ) {
			$controller->register_routes();
		}

		$actual = array();
		foreach ( $GLOBALS['_mcpwp_test_registered_routes'] as $registration ) {
			$this->assertSame( 'mcpwp/v1', $registration['namespace'] );
			$configurations = isset( $registration['args']['methods'] ) ? array( $registration['args'] ) : $registration['args'];
			foreach ( $configurations as $configuration ) {
				foreach ( explode( ',', (string) $configuration['methods'] ) as $method ) {
					$actual[ $registration['route'] ][] = strtoupper( trim( $method ) );
				}
			}
		}
		foreach ( $actual as &$methods ) {
			$methods = array_values( array_unique( $methods ) );
			sort( $methods );
		}
		unset( $methods );
		ksort( $actual );
		$expected = self::$policy['retained_rest_routes'];
		foreach ( $expected as &$methods ) {
			sort( $methods );
		}
		unset( $methods );
		ksort( $expected );

		$this->assertSame( $expected, $actual );
	}

	public function test_bootstrap_and_loader_dependencies_resolve_to_retained_files(): void {
		$main = (string) file_get_contents( self::$plugin_dir . '/mcpwp.php' );
		preg_match_all( "#['\"]((?:admin|includes)/[^'\"]+\.php)['\"]#", $main, $file_matches );
		foreach ( array_unique( $file_matches[1] ) as $relative ) {
			$this->assertFileExists( self::$plugin_dir . '/' . $relative, 'Bootstrap references missing file: ' . $relative );
		}

		$class_index = array();
		$iterator    = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( self::$plugin_dir . '/includes', FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}
			$contents = (string) file_get_contents( $file->getPathname() );
			preg_match_all( '/\b(?:class|trait)\s+(Mcpwp_[A-Za-z0-9_]+)/', $contents, $class_matches );
			foreach ( $class_matches[1] as $class_name ) {
				$class_index[ $class_name ] = $file->getPathname();
			}
		}

		$lifecycle = $main . "\n" . (string) file_get_contents( self::$plugin_dir . '/includes/class-mcpwp-loader.php' );
		preg_match_all( '/\bnew\s+(Mcpwp_[A-Za-z0-9_]+)/', $lifecycle, $new_matches );
		foreach ( array_unique( $new_matches[1] ) as $class_name ) {
			$this->assertArrayHasKey( $class_name, $class_index, 'Bootstrap/loader instantiates missing class: ' . $class_name );
		}
	}

	public function test_builder_does_not_delete_forbidden_runtime_to_manufacture_compliance(): void {
		$builder = strtolower( (string) file_get_contents( self::$plugin_dir . '/scripts/build-wporg.sh' ) );
		$this->assertDoesNotMatchRegularExpression( '/rm[^\n]*(?:freemius|includes\/pro|class-mcpwp-(?:updater|license)|freemius-init)/', $builder );
	}

	public function test_policy_exceptions_are_narrow(): void {
		$benign = "License: GPL-2.0-or-later\nLicense URI: https://www.gnu.org/licenses/gpl-2.0.html\n";
		$benign .= "get_option( 'mcpwp_settings' ); update_option( 'mcpwp_settings', array() );";
		$this->assertSame( array(), $this->scan_contents( 'mcpwp.php', $benign ) );

		$this->assertSame(
			array(),
			$this->scan_contents( 'uninstall.php', "delete_option( 'mcpwp_license' ); wp_clear_scheduled_hook( 'mcpwp_webhook_retry' );" )
		);
		$near_miss = $this->scan_contents( 'uninstall.php', "delete_option( 'mcpwp_license_copy' ); wp_get_option( 'siteurl' );" );
		$this->assertNotEmpty( $near_miss );
	}

	/** @return string[] */
	private function scan_source(): array {
		$violations = array();
		$iterator   = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( self::$plugin_dir, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( self::$plugin_dir ) + 1 ) );
			$this->scan_path( $relative, $violations );
			if ( $file->isFile() && $this->source_content_is_scannable( $relative ) ) {
				$violations = array_merge( $violations, $this->scan_contents( $relative, (string) file_get_contents( $file->getPathname() ) ) );
			}
		}

		return array_slice( array_values( array_unique( $violations ) ), 0, 300 );
	}

	/** @return string[] */
	private function scan_zip(): array {
		$violations = array();
		exec( 'unzip -Z1 ' . escapeshellarg( self::$zip_path ) . ' 2>&1', $names, $status );
		if ( 0 !== $status ) {
			throw new RuntimeException( 'Could not inspect candidate ZIP: ' . self::$zip_path );
		}
		foreach ( $names as $name ) {
			$this->scan_path( $name, $violations );
			if ( '/' !== substr( $name, -1 ) && $this->zip_content_is_scannable( $name ) ) {
				$relative   = preg_replace( '#^[^/]+/#', '', $name );
				$contents   = shell_exec( 'unzip -p ' . escapeshellarg( self::$zip_path ) . ' ' . escapeshellarg( $name ) );
				$violations = array_merge( $violations, $this->scan_contents( $relative, (string) $contents ) );
			}
		}

		return array_slice( array_values( array_unique( $violations ) ), 0, 300 );
	}

	private function source_content_is_scannable( $relative ): bool {
		if ( in_array( $relative, array( 'mcpwp.php', 'readme.txt', 'uninstall.php', 'languages/mumega-mcp.pot' ), true ) ) {
			return true;
		}
		return 1 === preg_match( '#^(?:admin|includes)/.*\.(?:php|js|css)$#i', $relative );
	}

	private function zip_content_is_scannable( $name ): bool {
		return 1 === preg_match( '/\.(?:php|js|css|pot|txt)$/i', $name );
	}

	/** @param string[] $violations */
	private function scan_path( $path, &$violations ): void {
		$normalized = strtolower( str_replace( '\\', '/', $path ) );
		$parts      = array_values( array_filter( explode( '/', trim( $normalized, '/' ) ), 'strlen' ) );
		$basename   = empty( $parts ) ? '' : end( $parts );
		foreach ( self::$policy['forbidden_directory_components'] as $component ) {
			if ( in_array( strtolower( $component ), array_slice( $parts, 0, -1 ), true ) ) {
				$violations[] = 'forbidden directory [' . $component . ']: ' . $path;
			}
		}
		if ( in_array( $basename, array_map( 'strtolower', self::$policy['forbidden_basenames'] ), true ) ) {
			$violations[] = 'forbidden basename: ' . $path;
		}
		foreach ( self::$policy['forbidden_basename_prefixes'] as $prefix ) {
			if ( 0 === strpos( $basename, strtolower( $prefix ) ) ) {
				$violations[] = 'forbidden basename prefix [' . $prefix . ']: ' . $path;
			}
		}
	}

	/** @return string[] */
	private function scan_contents( $relative, $contents ): array {
		$violations = array();
		$normalized = str_replace( '\\', '/', ltrim( (string) $relative, '/' ) );
		foreach ( self::$policy['lifecycle_cleanup_exceptions'][ $normalized ] ?? array() as $exception ) {
			$contents = str_replace( $exception, '', $contents );
		}
		$lower_contents = strtolower( $contents );
		foreach ( self::$policy['forbidden_literals'] as $literal ) {
			if ( false !== strpos( $lower_contents, strtolower( $literal ) ) ) {
				$violations[] = 'forbidden literal [' . $literal . ']: ' . $normalized;
			}
		}
		return $violations;
	}
}
