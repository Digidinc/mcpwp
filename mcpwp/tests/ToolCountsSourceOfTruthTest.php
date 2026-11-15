<?php
/**
 * #650 — checked-in tool counts must match the live registries.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/class-mcpwp-tool-count-snapshot.php';

final class ToolCountsSourceOfTruthTest extends TestCase {

	/**
	 * Resolve monorepo root (parent of `mcpwp/`). In CI the checkout layout matches;
	 * in the Docker harness set `MCPWP_REPO_ROOT` when only `mcpwp/` is bind-mounted.
	 */
	private static function repo_root(): string {
		$from_tests = dirname( __DIR__, 2 );
		if ( is_readable( $from_tests . '/docs/tool-counts.json' ) ) {
			return $from_tests;
		}
		$env = getenv( 'MCPWP_REPO_ROOT' );
		if ( is_string( $env ) && '' !== $env && is_readable( $env . '/docs/tool-counts.json' ) ) {
			return rtrim( $env, '/' );
		}
		return $from_tests;
	}

	private static function manifest_path(): string {
		return self::repo_root() . '/docs/tool-counts.json';
	}

	/** @var string|null */
	private $agency_marker_backup;

	/** @var string|null */
	private $agency_marker_temp;

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_fs']                = null;
		$GLOBALS['mcpwp_test_filters']           = array();
		$GLOBALS['mcpwp_test_options']           = array();
		$GLOBALS['mcpwp_test_dispatched_routes'] = array();
		Mcpwp_Integration::clear_cache();
		$this->restore_agency_marker();
		Mcpwp_Tool_Count_Snapshot::$agency_marker_path_override = null;
	}

	public function test_manifest_matches_live_catalog(): void {
		$manifest = self::manifest_path();
		$this->assertFileIsReadable( $manifest, 'Run scripts/gen-tool-counts.php to generate docs/tool-counts.json.' );

		$stored = json_decode( (string) file_get_contents( $manifest ), true );
		$this->assertIsArray( $stored );
		$this->assertArrayHasKey( 'counts', $stored );

		$live_pro  = Mcpwp_Tool_Count_Snapshot::compute( true );
		$live_free = Mcpwp_Tool_Count_Snapshot::compute( false );

		$this->assertSame( (int) $stored['counts']['tools_list_full_free'], (int) $live_free['tools_list_full'], 'tools_list_full_free drifted.' );
		$this->assertSame( (int) $stored['counts']['tools_list_full_pro'], (int) $live_pro['tools_list_full'], 'tools_list_full_pro drifted.' );
		$this->assertSame( (int) $stored['counts']['tools_list_core'], (int) $live_pro['tools_list_core'], 'tools_list_core drifted.' );
		$this->assertSame( (int) $stored['counts']['free_registry'], (int) $live_free['free_registry'], 'free_registry drifted.' );
		$this->assertSame( (int) $stored['counts']['pro_registry'], (int) $live_pro['pro_registry'], 'pro_registry drifted.' );
		$this->assertSame( (int) $stored['counts']['pro_staging_discovery'], (int) $live_free['pro_staging_discovery'], 'pro_staging_discovery drifted.' );
		$this->assertSame( (int) $stored['counts']['pro_only_tools'], (int) $live_pro['pro_only_tools'], 'pro_only_tools drifted.' );
	}

	public function test_marketing_labels_are_conservative(): void {
		$stored = json_decode( (string) file_get_contents( self::manifest_path() ), true );
		$this->assertIsArray( $stored );

		$free  = (int) $stored['counts']['tools_list_full_free'];
		$pro   = (int) $stored['counts']['tools_list_full_pro'];
		$label = static function ( int $n ): int {
			$floor = (int) ( floor( $n / 10 ) * 10 );
			if ( $floor >= $n ) {
				$floor = max( 0, $floor - 10 );
			}
			return $floor;
		};

		$this->assertSame( $label( $free ), (int) rtrim( (string) $stored['marketing']['free_tools'], '+' ) );
		$this->assertSame( $label( $pro ), (int) rtrim( (string) $stored['marketing']['pro_tools_total'], '+' ) );
		$this->assertStringContainsString( $stored['marketing']['free_tools'], (string) $stored['marketing']['phrase_short'] );
		$this->assertStringContainsString( $stored['marketing']['pro_tools_total'], (string) $stored['marketing']['phrase_short'] );
	}

	public function test_github_about_matches_repo_metadata_canon(): void {
		$stored = json_decode( (string) file_get_contents( self::manifest_path() ), true );
		$this->assertIsArray( $stored );
		$this->assertSame( Mcpwp_Tool_Count_Snapshot::GITHUB_ABOUT_SUGGESTED, $stored['github_about']['suggested'] );
		$this->assertLessThanOrEqual( 350, strlen( (string) $stored['github_about']['suggested'] ) );
	}

	public function test_gen_tool_counts_check_passes_on_clean_checkout(): void {
		$root = self::repo_root();
		$cmd  = 'php ' . escapeshellarg( $root . '/scripts/gen-tool-counts.php' ) . ' --check 2>&1';
		exec( $cmd, $out, $code );
		$this->assertSame( 0, $code, "gen-tool-counts --check failed:\n" . implode( "\n", $out ) );
	}

	public function test_refuses_when_agency_marker_present(): void {
		$scratch = getenv( 'HOME' ) ?: '/home';
		$this->agency_marker_temp = tempnam( $scratch, 'mcpwp-agency-' );
		Mcpwp_Tool_Count_Snapshot::$agency_marker_path_override = $this->agency_marker_temp;
		file_put_contents( $this->agency_marker_temp, "<?php\nreturn '" . Mcpwp_License::AGENCY_MARKER_TOKEN . "';\n" );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'agency-license.php' );
		Mcpwp_Tool_Count_Snapshot::build_manifest();
	}

	/**
	 * @dataProvider public_copy_files
	 */
	public function test_public_copy_matches_manifest( string $path, string $needle ): void {
		$full = self::repo_root() . '/' . $path;
		$this->assertFileIsReadable( $full, $path );
		$body = (string) file_get_contents( $full );
		$this->assertStringContainsString( $needle, $body, $path . ' must cite the checked-in marketing phrase.' );
		$this->assertDoesNotMatchRegularExpression(
			'/⚡\s*239\b|\b239\s+MCP\s+tools\b/i',
			$body,
			$path . ' still promotes the stale 239 count.'
		);
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public function public_copy_files(): array {
		$manifest = self::manifest_path();
		if ( ! is_readable( $manifest ) ) {
			return array();
		}
		$stored = json_decode( (string) file_get_contents( $manifest ), true );
		$phrase = (string) ( $stored['marketing']['phrase_short'] ?? '' );
		if ( '' === $phrase ) {
			return array();
		}

		return array(
			'root readme'              => array( 'README.md', $phrase ),
			'launch directories index' => array( 'docs/launch/directories/README.md', $phrase ),
			'repo metadata guide'      => array( 'docs/launch/REPO-METADATA.md', $phrase ),
			'connect matrix'           => array( 'docs/launch/CONNECT-MATRIX.md', $phrase ),
			'mcp-server manifest'        => array( 'mcp-server/manifest.json', '190+ tools free' ),
		);
	}

	public function test_docs_and_mcp_server_avoid_stale_tool_count_claims(): void {
		$stored = json_decode( (string) file_get_contents( self::manifest_path() ), true );
		$this->assertIsArray( $stored );
		$phrase = (string) ( $stored['marketing']['phrase_short'] ?? '' );
		$this->assertNotSame( '', $phrase );

		$root       = self::repo_root();
		$allow_exact = array(
			'docs/blog-launch-post.md',
			'docs/ph-maker-comment.md',
			'docs/PRODUCT_ROADMAP.md',
			'docs/plan/PLAN.md',
			'docs/ROADMAP-4.0.md',
			'docs/AGENCY-GATEWAY.md',
			'docs/MCP_REGISTRY_REFACTOR.md',
			'docs/API.md',
			'docs/launch/CONNECT-MATRIX.md',
		);
		$allow_prefix = array(
			'docs/website-gtm/',
			'docs/superpowers/',
		);

		$stale_patterns = array(
			'/⚡\s*239\b/',
			'/\b239\s+MCP\s+tools\b/i',
			'/\b250\+\s+MCP\s+tools\b/i',
			'/\b235\+\s+MCP\s+tools\b/i',
			'/\b200\+\s+tools\b/i',
			'/\b300\+\s+tools\b(?!.*with Pro)/i',
			'/\b300\+\s+MCP\s+tools\b(?!.*free)/i',
			'/\b100%\s+free\b/i',
		);

		$dirs = array( $root . '/docs', $root . '/mcp-server' );
		foreach ( $dirs as $dir ) {
			$inner = new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS );
			$filter = new RecursiveCallbackFilterIterator(
				$inner,
				static function ( $current ) {
					if ( $current->isDir() ) {
						$name = $current->getFilename();
						return 'node_modules' !== $name;
					}
					return true;
				}
			);
			$iter = new RecursiveIteratorIterator( $filter );
			foreach ( $iter as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}
				$path = $file->getPathname();
				if ( ! preg_match( '/\.(md|json|html)$/i', $path ) ) {
					continue;
				}
				$rel = ltrim( str_replace( $root . '/', '', $path ), '/' );
				if ( in_array( $rel, $allow_exact, true ) ) {
					continue;
				}
				$skip = false;
				foreach ( $allow_prefix as $prefix ) {
					if ( str_starts_with( $rel, $prefix ) ) {
						$skip = true;
						break;
					}
				}
				if ( $skip ) {
					continue;
				}
				$body = (string) file_get_contents( $path );
				foreach ( preg_split( "/\r\n|\n|\r/", $body ) as $lineno => $line ) {
					foreach ( $stale_patterns as $pattern ) {
						if ( preg_match( $pattern, $line ) ) {
							$this->fail( $rel . ':' . ( $lineno + 1 ) . ' still carries a stale tool-count claim: ' . trim( $line ) );
						}
					}
				}
			}
		}
	}

	private function restore_agency_marker(): void {
		if ( null !== $this->agency_marker_temp && is_file( $this->agency_marker_temp ) ) {
			unlink( $this->agency_marker_temp );
			$this->agency_marker_temp = null;
		}
		$this->agency_marker_backup = null;
	}
}