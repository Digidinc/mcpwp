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

	public function test_runtime_inventory_is_exclusive_for_source_and_zip(): void {
		$this->assertArrayHasKey( 'allowed_runtime_files', self::$policy );
		$violations = array();
		$this->scan_path( 'includes/api/class-renamed-equivalent.php', $violations );
		$this->scan_path( 'assets/runtime.php', $violations );
		$this->assertCount( 2, $violations );
	}

	public function test_executable_runtime_content_is_scanned_in_any_location(): void {
		$this->assertTrue( $this->source_content_is_scannable( 'assets/runtime.php' ) );
		$this->assertTrue( $this->source_content_is_scannable( 'runtime/custom/location.php' ) );
	}

	public function test_wildcard_hosts_and_updater_primitives_are_rejected(): void {
		foreach ( array(
			"fetch( 'https://relay-example.workers.dev/path' );",
			"fetch( 'https://abc-assets.i.posthog.com/bundle.js' );",
			"set_site_transient( 'anything', array() );",
			"delete_site_transient( 'anything' );",
			"update_plugins();",
		) as $contents ) {
			$this->assertNotEmpty( $this->scan_contents( 'assets/runtime.php', $contents ), $contents );
		}
	}

	public function test_lifecycle_cleanup_exception_requires_destructive_cleanup_context(): void {
		$this->assertSame( array(), $this->scan_contents( 'uninstall.php', "delete_option( 'mcpwp_license' );" ) );
		$this->assertNotEmpty( $this->scan_contents( 'uninstall.php', "update_option( 'mcpwp_license', true );" ) );
		$this->assertNotEmpty( $this->scan_contents( 'uninstall.php', "wp_schedule_event( time(), 'daily', 'mcpwp_webhook_retry' );" ) );
	}

	public function test_verifier_rejects_unexpected_runtime_file_and_map_drift(): void {
		$extra = $this->verify_mutated_zip( array( 'includes/api/class-renamed-equivalent.php' => "<?php class Mcpwp_REST_Renamed_Equivalent {}\n" ) );
		$this->assertNotSame( 0, $extra['status'], $extra['output'] );

		$tool_source = (string) shell_exec( 'unzip -p ' . escapeshellarg( self::$zip_path ) . ' ' . escapeshellarg( 'mumega-mcp/includes/mcp/class-mcpwp-mcp-free-tools.php' ) );
		$tool_drift  = str_replace( "'route' => '/site-info'", "'route' => '/site-details'", $tool_source );
		$tool = $this->verify_mutated_zip( array( 'includes/mcp/class-mcpwp-mcp-free-tools.php' => $tool_drift ) );
		$this->assertNotSame( 0, $tool['status'], $tool['output'] );

		$route_source = (string) shell_exec( 'unzip -p ' . escapeshellarg( self::$zip_path ) . ' ' . escapeshellarg( 'mumega-mcp/includes/api/class-mcpwp-rest-site-info.php' ) );
		$route_drift  = str_replace( "'/site-info'", "'/site-details'", $route_source );
		$route = $this->verify_mutated_zip( array( 'includes/api/class-mcpwp-rest-site-info.php' => $route_drift ) );
		$this->assertNotSame( 0, $route['status'], $route['output'] );
	}

	public function test_runtime_gate_marker_is_absent(): void {
		$this->assertStringNotContainsString( 'MCPWP_WPORG_BUILD', (string) file_get_contents( self::$plugin_dir . '/mcpwp.php' ) );
	}

	/** @param array<string,string> $replacements @return array{status:int,output:string} */
	private function verify_mutated_zip( $replacements ): array {
		$temp = sys_get_temp_dir() . '/mcpwp-policy-' . bin2hex( random_bytes( 6 ) );
		mkdir( $temp, 0777, true );
		exec( 'unzip -q ' . escapeshellarg( self::$zip_path ) . ' -d ' . escapeshellarg( $temp ), $unused, $unzip_status );
		if ( 0 !== $unzip_status ) { throw new RuntimeException( 'Could not unpack mutation fixture.' ); }
		foreach ( $replacements as $relative => $contents ) {
			$path = $temp . '/mumega-mcp/' . $relative;
			if ( ! is_dir( dirname( $path ) ) ) { mkdir( dirname( $path ), 0777, true ); }
			file_put_contents( $path, $contents );
		}
		$zip = $temp . '/mumega-mcp-3.4.3.zip';
		$command = 'cd ' . escapeshellarg( $temp ) . ' && find mumega-mcp -type f -print | LC_ALL=C sort | zip -q -X ' . escapeshellarg( $zip ) . ' -@';
		exec( $command, $zip_output, $zip_status );
		if ( 0 !== $zip_status ) { throw new RuntimeException( 'Could not build mutation fixture.' ); }
		exec( 'bash ' . escapeshellarg( self::$plugin_dir . '/scripts/verify-wporg-artifact.sh' ) . ' ' . escapeshellarg( $zip ) . ' 2>&1', $output, $status );
		exec( 'rm -rf ' . escapeshellarg( $temp ) );
		return array( 'status' => $status, 'output' => implode( "\n", $output ) );
	}

	/** @return string[] */
	private function scan_source(): array {
		$violations = array();
		$iterator   = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( self::$plugin_dir, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( self::$plugin_dir ) + 1 ) );
			$this->scan_path( $relative, $violations, false );
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
			$this->scan_path( $name, $violations, true );
			if ( '/' !== substr( $name, -1 ) && $this->zip_content_is_scannable( $name ) ) {
				$relative   = preg_replace( '#^[^/]+/#', '', $name );
				$contents   = shell_exec( 'unzip -p ' . escapeshellarg( self::$zip_path ) . ' ' . escapeshellarg( $name ) );
				$violations = array_merge( $violations, $this->scan_contents( $relative, (string) $contents ) );
			}
		}

		return array_slice( array_values( array_unique( $violations ) ), 0, 300 );
	}

	private function source_content_is_scannable( $relative ): bool {
		$relative = str_replace( '\\', '/', ltrim( (string) $relative, '/' ) );
		if ( 1 === preg_match( '#^(?:tests|scripts|vendor|docs|node_modules)/#i', $relative ) ) { return false; }
		if ( in_array( $relative, array( 'mcpwp.php', 'readme.txt', 'uninstall.php', 'languages/mumega-mcp.pot' ), true ) ) {
			return true;
		}
		return 1 === preg_match( '/\.(?:php|js|css)$/i', $relative );
	}

	private function zip_content_is_scannable( $name ): bool {
		return 1 === preg_match( '/\.(?:php|js|css|pot|txt)$/i', $name );
	}

	/** @param string[] $violations */
	private function scan_path( $path, &$violations, $is_zip = false ): void {
		$normalized = strtolower( str_replace( '\\', '/', $path ) );
		$parts      = array_values( array_filter( explode( '/', trim( $normalized, '/' ) ), 'strlen' ) );
		if ( $is_zip && ! empty( $parts ) && 'mumega-mcp' === $parts[0] ) { array_shift( $parts ); }
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
		$relative = implode( '/', $parts );
		if ( 'mumega-mcp.php' === $relative ) { $relative = 'mcpwp.php'; }
		$dev_path = 1 === preg_match( '#^(?:tests|scripts|vendor|docs|node_modules)/#', $relative );
		$looks_like_file = false !== strpos( $basename, '.' ) || in_array( $relative, array( 'license', 'version' ), true );
		$allowed = array_map( 'strtolower', self::$policy['allowed_runtime_files'] );
		$source_artwork = array_map( 'strtolower', self::$policy['source_only_artwork_files'] );
		$source_candidate = ! $dev_path && ( in_array( $relative, $allowed, true ) || in_array( $relative, $source_artwork, true ) || 1 === preg_match( '#^(?:admin|includes|languages|assets)/#', $relative ) || 1 === preg_match( '/\.(?:php|js|css|pot|txt)$/', $relative ) );
		if ( $looks_like_file && ( $is_zip || $source_candidate ) ) {
			if ( ! $is_zip ) { $allowed = array_merge( $allowed, $source_artwork ); }
			if ( ! in_array( $relative, $allowed, true ) ) { $violations[] = 'unexpected runtime file: ' . $path; }
		}
	}

	/** @return string[] */
	private function scan_contents( $relative, $contents ): array {
		$violations = array();
		$normalized = str_replace( '\\', '/', ltrim( (string) $relative, '/' ) );
		$contents = $this->mask_allowed_lifecycle_cleanup( $normalized, $contents );
		$lower_contents = strtolower( $contents );
		foreach ( self::$policy['forbidden_literals'] as $literal ) {
			if ( false !== strpos( $lower_contents, strtolower( $literal ) ) ) {
				$violations[] = 'forbidden literal [' . $literal . ']: ' . $normalized;
			}
		}
		foreach ( self::$policy['forbidden_patterns'] as $pattern ) {
			if ( 1 === preg_match( '/' . $pattern . '/i', $contents ) ) { $violations[] = 'forbidden pattern [' . $pattern . ']: ' . $normalized; }
		}
		return $violations;
	}

	private function mask_allowed_lifecycle_cleanup( $relative, $contents ) {
		$exceptions = self::$policy['lifecycle_cleanup_exceptions'][ $relative ] ?? array();
		foreach ( $exceptions as $quoted ) {
			$token = trim( $quoted, "'\"" );
			$escaped = preg_quote( $token, '/' );
			$functions = 'wp_clear_scheduled_hook';
			if ( 'uninstall.php' === $relative ) { $functions .= '|delete_option|delete_transient'; }
			$contents = preg_replace( '/\b(?:' . $functions . ')\s*\(\s*([\'\"])' . $escaped . '\1\s*\)/i', '__allowed_cleanup__', $contents );
			if ( 'uninstall.php' === $relative ) {
				$contents = preg_replace( '/DROP\s+TABLE\s+IF\s+EXISTS[^;\n]*' . $escaped . '/i', '__allowed_drop__', $contents );
			}
		}
		return $contents;
	}
}
