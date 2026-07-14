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
		$advertised = array_column( $registry->get_tools(), 'name' );
		sort( $advertised );
		$this->assertSame( array_keys( $expected ), $advertised, 'Advertised tools must independently match the retained map.' );
		$this->assertCount( 17, $advertised );
		$this->assertArrayNotHasKey( 'wp_upload_media', $actual, 'Multipart upload must remain REST-only.' );
	}

	public function test_source_and_zip_structural_tool_definitions_are_exact(): void {
		$source_class = (string) file_get_contents( self::$plugin_dir . '/includes/mcp/class-mcpwp-mcp-free-tools.php' );
		$source_traits = '';
		foreach ( array( 'site', 'content', 'media', 'ops' ) as $trait ) {
			$source_traits .= "\n" . (string) file_get_contents( self::$plugin_dir . '/includes/mcp/traits/trait-mcpwp-free-tools-' . $trait . '.php' );
		}
		$source_inventory = $this->extract_structural_tool_inventory( $source_class, $source_traits );

		$zip_class = (string) shell_exec( 'unzip -p ' . escapeshellarg( self::$zip_path ) . ' ' . escapeshellarg( 'mumega-mcp/includes/mcp/class-mcpwp-mcp-free-tools.php' ) );
		$zip_traits = '';
		foreach ( array( 'site', 'content', 'media', 'ops' ) as $trait ) {
			$zip_traits .= "\n" . (string) shell_exec( 'unzip -p ' . escapeshellarg( self::$zip_path ) . ' ' . escapeshellarg( 'mumega-mcp/includes/mcp/traits/trait-mcpwp-free-tools-' . $trait . '.php' ) );
		}
		$zip_inventory = $this->extract_structural_tool_inventory( $zip_class, $zip_traits );

		$expected = array_keys( self::$policy['retained_tool_map'] );
		sort( $expected );
		foreach ( array( 'descriptions', 'traits', 'map' ) as $surface ) {
			$this->assertSame( $expected, $source_inventory[ $surface ], 'Source ' . $surface . ' inventory drifted.' );
			$this->assertSame( $expected, $zip_inventory[ $surface ], 'ZIP ' . $surface . ' inventory drifted.' );
		}
	}

	public function test_runtime_source_has_canonical_tool_registry_hashes(): void {
		$expected_paths = array(
			'includes/api/class-mcpwp-rest-api.php',
			'includes/api/class-mcpwp-rest-api-keys.php',
			'includes/api/class-mcpwp-rest-mcp.php',
			'includes/api/class-mcpwp-rest-media.php',
			'includes/api/class-mcpwp-rest-pages.php',
			'includes/api/class-mcpwp-rest-posts.php',
			'includes/api/class-mcpwp-rest-rate-limit.php',
			'includes/api/class-mcpwp-rest-site-info.php',
			'includes/api/class-mcpwp-rest-site-settings.php',
			'includes/api/class-mcpwp-rest-site-taxonomy.php',
			'includes/traits/trait-mcpwp-api-auth.php',
			'includes/mcp/class-mcpwp-mcp-tool-registry.php',
			'includes/mcp/class-mcpwp-mcp-free-tools.php',
			'includes/mcp/traits/trait-mcpwp-free-tools-site.php',
			'includes/mcp/traits/trait-mcpwp-free-tools-content.php',
			'includes/mcp/traits/trait-mcpwp-free-tools-media.php',
			'includes/mcp/traits/trait-mcpwp-free-tools-ops.php',
		);
		$this->assertSame( $expected_paths, array_keys( self::$policy['trusted_source_sha256'] ), 'Trusted source policy must cover the complete canonical registry.' );
		foreach ( self::$policy['trusted_source_sha256'] as $relative => $expected ) {
			$this->assertSame( $expected, hash_file( 'sha256', self::$plugin_dir . '/' . $relative ), 'Trusted source drifted: ' . $relative );
		}
	}

	public function test_trusted_registry_byte_mutations_rerun_and_fail_the_source_comparator(): void {
		foreach ( self::$policy['trusted_source_sha256'] as $relative => $expected ) {
			$contents = (string) file_get_contents( self::$plugin_dir . '/' . $relative ) . ' ';
			$violations = $this->scan_contents( $relative, $contents );
			$this->assertStringContainsString( 'trusted source hash mismatch', implode( "\n", $violations ), $relative );
		}
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

	public function test_source_and_zip_static_rest_registrations_are_exact(): void {
		$source_files = array();
		$zip_files = array();
		foreach ( self::$policy['allowed_runtime_files'] as $relative ) {
			if ( '.php' !== substr( $relative, -4 ) ) { continue; }
			$source_files[ $relative ] = (string) file_get_contents( self::$plugin_dir . '/' . $relative );
			$zip_relative = 'mcpwp.php' === $relative ? 'mumega-mcp.php' : $relative;
			$zip_files[ $relative ] = (string) shell_exec( 'unzip -p ' . escapeshellarg( self::$zip_path ) . ' ' . escapeshellarg( 'mumega-mcp/' . $zip_relative ) );
		}
		$expected = $this->normalize_route_inventory( self::$policy['retained_rest_routes'] );
		$this->assertSame( $expected, $this->extract_static_rest_routes( $source_files ), 'Source contains an unapproved static REST registration.' );
		$this->assertSame( $expected, $this->extract_static_rest_routes( $zip_files ), 'ZIP contains an unapproved static REST registration.' );
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
		$approved_disclosure = 'There are no plugin-initiated outbound requests, no telemetry, and no contact with Mumega.';
		$this->assertSame( array(), $this->scan_contents( 'readme.txt', $approved_disclosure ) );
		$this->assertNotEmpty( $this->scan_contents( 'readme.txt', 'There are no plugin-initiated outbound requests, no telemetry opt-out, and no contact with Mumega.' ) );
		$this->assertNotEmpty( $this->scan_contents( 'readme.txt', $approved_disclosure . ' Telemetry tracking is enabled.' ) );
		$this->assertNotEmpty( $this->scan_contents( 'includes/runtime.php', $approved_disclosure ) );
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
		$this->assertNotEmpty( $this->scan_contents( 'uninstall.php', 'DROP TABLE IF EXISTS {$wpdb->prefix}mcpwp_feedback_backup;' ) );
	}

	public function test_extensionless_runtime_files_are_rejected_in_source_and_zip(): void {
		$violations = array();
		$this->scan_path( 'includes/policy-probe', $violations );
		$this->assertNotEmpty( $violations, 'Source inventory must reject extensionless runtime files.' );

		$zip = $this->verify_mutated_zip( array( 'includes/policy-probe' => "runtime probe\n" ) );
		$this->assertNotSame( 0, $zip['status'], $zip['output'] );
	}

	public function test_top_level_and_arbitrary_location_extensionless_source_files_are_rejected(): void {
		$violations = array();
		$this->scan_path( 'policy-probe', $violations );
		$this->scan_path( 'runtime/custom/policy-probe', $violations );
		$this->assertCount( 2, $violations );
	}

	public function test_statically_composed_fixed_and_wildcard_hosts_are_rejected(): void {
		$probe = "<?php\n\$relay = 'relay-example.' . 'workers.dev';\n";
		$this->assertNotEmpty( $this->scan_contents( 'assets/index.php', $probe ) );
	}

	public function test_verifier_rejects_statically_composed_fixed_and_wildcard_hosts(): void {
		$probe = "<?php\n\$relay = 'relay-example.' . 'workers.dev';\n";
		$zip = $this->verify_mutated_zip( array( 'assets/index.php' => $probe ) );
		$this->assertNotSame( 0, $zip['status'], $zip['output'] );
	}

	public function test_comment_separated_static_host_concatenation_is_rejected_in_source(): void {
		$probe = "<?php\n\$relay = 'relay-example.' /* static join */ . 'workers.dev';\n";
		$this->assertNotEmpty( $this->scan_contents( 'assets/index.php', $probe ) );
	}

	public function test_verifier_rejects_comment_separated_static_host_concatenation(): void {
		$probe = "<?php\n\$relay = 'relay-example.' /* static join */ . 'workers.dev';\n";
		$zip = $this->verify_mutated_zip( array( 'assets/index.php' => $probe ) );
		$this->assertNotSame( 0, $zip['status'], $zip['output'] );
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

	public function test_verifier_rejects_mapless_advertised_tool(): void {
		$trait_source = (string) shell_exec( 'unzip -p ' . escapeshellarg( self::$zip_path ) . ' ' . escapeshellarg( 'mumega-mcp/includes/mcp/traits/trait-mcpwp-free-tools-site.php' ) );
		$trait_drift = str_replace( "array( 'wp_site_info' )", "array( 'wp_site_info', 'wp_policy_probe' )", $trait_source );
		$advertised = $this->verify_mutated_zip( array( 'includes/mcp/traits/trait-mcpwp-free-tools-site.php' => $trait_drift ) );
		$this->assertNotSame( 0, $advertised['status'], $advertised['output'] );
	}

	public function test_verifier_rejects_dynamic_inventory_and_description_advertisement(): void {
		$source = (string) shell_exec( 'unzip -p ' . escapeshellarg( self::$zip_path ) . ' ' . escapeshellarg( 'mumega-mcp/includes/mcp/class-mcpwp-mcp-free-tools.php' ) );
		$mutation = "\t\t\$inventory[] = 'wp_policy_probe';\n\t\t\$descriptions['wp_policy_probe'] = 'Policy probe.';";
		$source = preg_replace( '/(\$inventory\s*=\s*array_merge\([^;]+;)/', "$1\n" . $mutation, $source, 1 );
		$result = $this->verify_mutated_zip( array( 'includes/mcp/class-mcpwp-mcp-free-tools.php' => $source ) );
		$this->assertNotSame( 0, $result['status'], $result['output'] );
	}

	public function test_verifier_rejects_dynamic_map_assignment_after_literal_map(): void {
		$source = (string) shell_exec( 'unzip -p ' . escapeshellarg( self::$zip_path ) . ' ' . escapeshellarg( 'mumega-mcp/includes/mcp/class-mcpwp-mcp-free-tools.php' ) );
		$source = str_replace( "public function get_tool_map() {\n\t\treturn array(", "public function get_tool_map() {\n\t\t\$map = array(", $source );
		$source = str_replace(
			"\t\t);\n\t}\n\n\tpublic function get_tool_categories()",
			"\t\t);\n\t\t\$map['wp_policy_probe'] = array( 'method' => 'GET', 'route' => '/site-info' );\n\t\treturn \$map;\n\t}\n\n\tpublic function get_tool_categories()",
			$source
		);
		$result = $this->verify_mutated_zip( array( 'includes/mcp/class-mcpwp-mcp-free-tools.php' => $source ) );
		$this->assertNotSame( 0, $result['status'], $result['output'] );
	}

	public function test_source_policy_rejects_composed_tool_registry_mutations(): void {
		$relative = 'includes/mcp/class-mcpwp-mcp-free-tools.php';
		$source   = (string) file_get_contents( self::$plugin_dir . '/' . $relative );
		$mutations = array(
			str_replace(
				"\t\tforeach ( \$inventory as \$name ) {",
				"\t\t\$inventory = array_merge( \$inventory, json_decode( '[\"wp_policy_probe\"]', true ) );\n\t\tforeach ( \$inventory as \$name ) {",
				$source
			),
			str_replace(
				"\t\t\$tools = array();",
				"\t\t\$descriptions = array_merge( \$descriptions, json_decode( '{\"wp_policy_probe\":\"Policy probe.\"}', true ) );\n\n\t\t\$tools = array();",
				$source
			),
			$this->compose_tool_map_with_union( $source ),
		);

		$accepted = array();
		foreach ( $mutations as $index => $mutation ) {
			$this->assertNotSame( $source, $mutation, 'Mutation fixture must alter the canonical registry.' );
			if ( empty( $this->scan_contents( $relative, $mutation ) ) ) {
				$accepted[] = $index;
			}
		}
		$this->assertSame( array(), $accepted, 'Source policy accepted composed registry mutations at indexes: ' . implode( ', ', $accepted ) );
	}

	public function test_verifier_rejects_composed_tool_registry_mutations(): void {
		$relative = 'includes/mcp/class-mcpwp-mcp-free-tools.php';
		$source   = (string) shell_exec( 'unzip -p ' . escapeshellarg( self::$zip_path ) . ' ' . escapeshellarg( 'mumega-mcp/' . $relative ) );
		$mutations = array(
			str_replace(
				"\t\tforeach ( \$inventory as \$name ) {",
				"\t\t\$inventory = array_merge( \$inventory, json_decode( '[\"wp_policy_probe\"]', true ) );\n\t\tforeach ( \$inventory as \$name ) {",
				$source
			),
			str_replace(
				"\t\t\$tools = array();",
				"\t\t\$descriptions = array_merge( \$descriptions, json_decode( '{\"wp_policy_probe\":\"Policy probe.\"}', true ) );\n\n\t\t\$tools = array();",
				$source
			),
			$this->compose_tool_map_with_union( $source ),
		);

		$statuses = array();
		foreach ( $mutations as $mutation ) {
			$this->assertNotSame( $source, $mutation, 'Mutation fixture must alter the canonical registry.' );
			$result = $this->verify_mutated_zip( array( $relative => $mutation ) );
			$statuses[] = $result['status'];
		}
		$this->assertNotContains( 0, $statuses, 'Verifier accepted composed registry mutations: ' . implode( ', ', $statuses ) );
	}

	public function test_line_comment_separated_static_host_concatenation_is_rejected_in_source(): void {
		$probes = array(
			"<?php\n\$relay = 'relay-example.' // static join\n . 'workers.dev';\n",
			"<?php\n\$relay = 'relay-example.' . # static join\n 'workers.dev';\n",
		);
		$accepted = array();
		foreach ( $probes as $index => $probe ) {
			if ( empty( $this->scan_contents( 'assets/index.php', $probe ) ) ) {
				$accepted[] = $index;
			}
		}
		$this->assertSame( array(), $accepted, 'Source policy accepted line-comment host probes at indexes: ' . implode( ', ', $accepted ) );
	}

	public function test_verifier_rejects_line_comment_separated_static_host_concatenation(): void {
		$probes = array(
			"<?php\n\$relay = 'relay-example.' // static join\n . 'workers.dev';\n",
			"<?php\n\$relay = 'relay-example.' . # static join\n 'workers.dev';\n",
		);
		$statuses = array();
		foreach ( $probes as $probe ) {
			$result = $this->verify_mutated_zip( array( 'assets/index.php' => $probe ) );
			$statuses[] = $result['status'];
		}
		$this->assertNotContains( 0, $statuses, 'Verifier accepted line-comment host probes: ' . implode( ', ', $statuses ) );
	}

	public function test_verifier_rejects_short_array_map_alias(): void {
		$map_source = (string) shell_exec( 'unzip -p ' . escapeshellarg( self::$zip_path ) . ' ' . escapeshellarg( 'mumega-mcp/includes/mcp/class-mcpwp-mcp-free-tools.php' ) );
		$map_drift = str_replace(
			"public function get_tool_map() {\n\t\treturn array(",
			"public function get_tool_map() {\n\t\treturn array(\n\t\t\t'wp_policy_probe' => [ 'method' => 'GET', 'route' => '/site-info' ],",
			$map_source
		);
		$alias = $this->verify_mutated_zip( array( 'includes/mcp/class-mcpwp-mcp-free-tools.php' => $map_drift ) );
		$this->assertNotSame( 0, $alias['status'], $alias['output'] );
	}

	public function test_verifier_extracts_rest_registrations_from_every_runtime_php_file(): void {
		$main_source = (string) shell_exec( 'unzip -p ' . escapeshellarg( self::$zip_path ) . ' ' . escapeshellarg( 'mumega-mcp/mumega-mcp.php' ) );
		$main_source .= "\nregister_rest_route( 'mcpwp/v1', '/policy-probe', array( 'methods' => 'GET', 'callback' => '__return_true', 'permission_callback' => '__return_true' ) );\n";
		$result = $this->verify_mutated_zip( array( 'mumega-mcp.php' => $main_source ) );
		$this->assertNotSame( 0, $result['status'], $result['output'] );
	}

	public function test_verifier_rejects_cleanup_table_suffix_near_miss(): void {
		$uninstall = (string) shell_exec( 'unzip -p ' . escapeshellarg( self::$zip_path ) . ' ' . escapeshellarg( 'mumega-mcp/uninstall.php' ) );
		$uninstall .= "\n<?php \$wpdb->query( \"DROP TABLE IF EXISTS {\$wpdb->prefix}mcpwp_feedback_backup\" );\n";
		$result = $this->verify_mutated_zip( array( 'uninstall.php' => $uninstall ) );
		$this->assertNotSame( 0, $result['status'], $result['output'] );
	}

	public function test_cleanup_mask_does_not_hide_comma_separated_near_miss_before_allowed_table(): void {
		$contents = 'DROP TABLE IF EXISTS {$wpdb->prefix}mcpwp_feedback_backup, {$wpdb->prefix}mcpwp_feedback;';
		$this->assertNotEmpty( $this->scan_contents( 'uninstall.php', $contents ) );
	}

	public function test_verifier_rejects_comma_separated_cleanup_near_miss_before_allowed_table(): void {
		$uninstall = (string) shell_exec( 'unzip -p ' . escapeshellarg( self::$zip_path ) . ' ' . escapeshellarg( 'mumega-mcp/uninstall.php' ) );
		$uninstall .= "\n<?php \$wpdb->query( \"DROP TABLE IF EXISTS {\$wpdb->prefix}mcpwp_feedback_backup, {\$wpdb->prefix}mcpwp_feedback\" );\n";
		$result = $this->verify_mutated_zip( array( 'uninstall.php' => $uninstall ) );
		$this->assertNotSame( 0, $result['status'], $result['output'] );
	}

	public function test_task_two_oracle_and_readme_describe_the_exact_retained_surface(): void {
		$oracle_path = self::$plugin_dir . '/tests/fixtures/wporg-task-2-acceptance.json';
		$this->assertFileExists( $oracle_path );
		$oracle = json_decode( (string) file_get_contents( $oracle_path ), true, 512, JSON_THROW_ON_ERROR );
		$this->assertCount( 17, $oracle['mcp_tools'] );
		$this->assertSame( array_keys( self::$policy['retained_tool_map'] ), $oracle['mcp_tools'] );
		$this->assertNotContains( 'wp_upload_media', $oracle['mcp_tools'] );
		$this->assertSame( array( 'GET', 'POST' ), $oracle['rest_routes']['/media'] );
		$this->assertSame( $this->normalize_route_inventory( self::$policy['retained_rest_routes'] ), $this->normalize_route_inventory( $oracle['rest_routes'] ) );

		$readme = strtolower( (string) file_get_contents( self::$plugin_dir . '/readme.txt' ) );
		$this->assertStringContainsString( 'metadata-only activity logging', $readme );
		$this->assertStringNotContainsString( 'configurable retention and redaction', $readme );
	}

	public function test_runtime_gate_marker_is_absent(): void {
		$this->assertStringNotContainsString( 'MCPWP_WPORG_BUILD', (string) file_get_contents( self::$plugin_dir . '/mcpwp.php' ) );
	}

	/** @return array{descriptions:string[],traits:string[],map:string[]} */
	private function extract_structural_tool_inventory( $class_source, $trait_sources ): array {
		$inventory = array( 'descriptions' => array(), 'traits' => array(), 'map' => array() );
		if ( 1 === preg_match( '/\$descriptions\s*=\s*array\((.*?)\);\s*\$tools/s', $class_source, $descriptions ) ) {
			preg_match_all( "/'(wp_[a-z0-9_]+)'\s*=>/", $descriptions[1], $description_names );
			$inventory['descriptions'] = $description_names[1];
		}
		preg_match_all( "/'(wp_[a-z0-9_]+)'/", $trait_sources, $trait_names );
		$inventory['traits'] = $trait_names[1];
		preg_match_all(
			"/'(wp_[a-z0-9_]+)'\s*=>\s*(?:array\s*\(|\[)\s*'method'\s*=>\s*'[A-Z]+'\s*,\s*'route'\s*=>\s*'[^']+'/",
			$class_source,
			$map_names
		);
		$inventory['map'] = $map_names[1];
		foreach ( $inventory as &$names ) {
			$names = array_values( array_unique( $names ) );
			sort( $names );
		}
		unset( $names );
		return $inventory;
	}

	/** @param array<string,string> $files @return array<string,string[]> */
	private function extract_static_rest_routes( $files ): array {
		$routes = array();
		$constants = array(
			'WP_REST_Server::READABLE' => array( 'GET' ),
			'WP_REST_Server::CREATABLE' => array( 'POST' ),
			'WP_REST_Server::EDITABLE' => array( 'PATCH', 'POST', 'PUT' ),
			'WP_REST_Server::DELETABLE' => array( 'DELETE' ),
		);
		foreach ( $files as $contents ) {
			preg_match_all( '#register_rest_route\s*\(\s*[^,]+,\s*([\'\"])([^\'\"]+)\1\s*,(.*?)\)\s*;#s', $contents, $registrations, PREG_SET_ORDER );
			foreach ( $registrations as $registration ) {
				preg_match_all( "/['\"]methods['\"]\s*=>\s*(WP_REST_Server::[A-Z]+|'[A-Z,]+'|\"[A-Z,]+\")/", $registration[3], $method_values );
				preg_match_all( "/route_definition\s*\(\s*(WP_REST_Server::[A-Z]+|'[A-Z,]+'|\"[A-Z,]+\")/", $registration[3], $definition_values );
				$method_values[1] = array_merge( $method_values[1], $definition_values[1] );
				foreach ( $method_values[1] as $value ) {
					$methods = isset( $constants[ $value ] ) ? $constants[ $value ] : explode( ',', trim( $value, "'\"" ) );
					$routes[ $registration[2] ] = array_merge( $routes[ $registration[2] ] ?? array(), $methods );
				}
			}
		}
		return $this->normalize_route_inventory( $routes );
	}

	/** @param array<string,string[]> $routes @return array<string,string[]> */
	private function normalize_route_inventory( $routes ): array {
		foreach ( $routes as &$methods ) {
			$methods = array_values( array_unique( array_map( 'strtoupper', $methods ) ) );
			sort( $methods );
		}
		unset( $methods );
		ksort( $routes );
		return $routes;
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

	private function compose_tool_map_with_union( $source ) {
		$source = str_replace(
			"public function get_tool_map() {\n\t\treturn array(",
			"public function get_tool_map() {\n\t\t\$map = array(",
			$source
		);
		return str_replace(
			"\t\t);\n\t}\n\n\tpublic function get_tool_categories()",
			"\t\t);\n\t\t\$map += json_decode( '{\"wp_policy_probe\":{\"method\":\"GET\",\"route\":\"/site-info\"}}', true );\n\t\treturn \$map;\n\t}\n\n\tpublic function get_tool_categories()",
			$source
		);
	}

	/** @return string[] */
	private function scan_source(): array {
		$violations = array();
		$iterator   = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( self::$plugin_dir, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( self::$plugin_dir ) + 1 ) );
			$this->scan_path( $relative, $violations, false, $file->isFile() );
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
			$this->scan_path( $name, $violations, true, '/' !== substr( $name, -1 ) );
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
	private function scan_path( $path, &$violations, $is_zip = false, $is_file = true ): void {
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
		$allowed = array_map( 'strtolower', self::$policy['allowed_runtime_files'] );
		$source_artwork = array_map( 'strtolower', self::$policy['source_only_artwork_files'] );
		$extensionless = false === strpos( $basename, '.' );
		$source_candidate = ! $dev_path && ( $extensionless || in_array( $relative, $allowed, true ) || in_array( $relative, $source_artwork, true ) || 1 === preg_match( '#^(?:admin|includes|languages|assets)/#', $relative ) || 1 === preg_match( '/\.(?:php|js|css|pot|txt)$/', $relative ) );
		if ( $is_file && ( $is_zip || $source_candidate ) ) {
			if ( ! $is_zip ) { $allowed = array_merge( $allowed, $source_artwork ); }
			if ( ! in_array( $relative, $allowed, true ) ) { $violations[] = 'unexpected runtime file: ' . $path; }
		}
	}

	/** @return string[] */
	private function scan_contents( $relative, $contents ): array {
		$violations = array();
		$normalized = str_replace( '\\', '/', ltrim( (string) $relative, '/' ) );
		$trusted_hash = self::$policy['trusted_source_sha256'][ $normalized ] ?? null;
		if ( null !== $trusted_hash && ! hash_equals( $trusted_hash, hash( 'sha256', $contents ) ) ) {
			$violations[] = 'trusted source hash mismatch: ' . $normalized;
		}
		$contents = $this->mask_allowed_lifecycle_cleanup( $normalized, $contents );
		foreach ( self::$policy['documentation_negative_disclosures'][ $normalized ] ?? array() as $disclosure ) {
			$contents = str_ireplace( $disclosure, '__allowed_negative_disclosure__', $contents );
		}
		$contents .= "\n" . $this->normalize_static_string_expressions( $contents );
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
				$contents = preg_replace( '/DROP\s+TABLE\s+IF\s+EXISTS\s+(?:\{\$wpdb->prefix\})?' . $escaped . '(?=\s*(?:[\"\';\)]|$))/i', '__allowed_drop__', $contents );
			}
		}
		return $contents;
	}

	private function normalize_static_string_expressions( $contents ) {
		$comment = '(?:(?:\/\*[^*]*(?:\*(?!\/)[^*]*)*\*\/|\/\/[^\r\n]*(?:\r\n|\r|\n)|\#[^\r\n]*(?:\r\n|\r|\n))\s*)*';
		$pattern = '/([\'\"])([^\'\"\r\n]*)\1\s*' . $comment . '\.\s*' . $comment . '([\'\"])([^\'\"\r\n]*)\3/';
		do {
			$previous = $contents;
			$contents = preg_replace_callback(
				$pattern,
				static function ( $matches ) { return "'" . $matches[2] . $matches[4] . "'"; },
				$contents
			);
		} while ( $contents !== $previous );
		return $contents;
	}
}
