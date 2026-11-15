<?php
/**
 * #650 — checked-in tool counts must match the live registries.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/class-mcpwp-tool-count-snapshot.php';

final class ToolCountsSourceOfTruthTest extends TestCase {

	/** Repo-root manifest (mcpwp/tests → ../../.. is wrong; two levels up is the repo root). */
	private const MANIFEST = __DIR__ . '/../../docs/tool-counts.json';

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_fs']                = null;
		$GLOBALS['mcpwp_test_filters']           = array();
		$GLOBALS['mcpwp_test_options']           = array();
		$GLOBALS['mcpwp_test_dispatched_routes'] = array();
		Mcpwp_Integration::clear_cache();
	}

	public function test_manifest_matches_live_catalog(): void {
		$this->assertFileIsReadable( self::MANIFEST, 'Run scripts/gen-tool-counts.php to generate docs/tool-counts.json.' );

		$stored = json_decode( (string) file_get_contents( self::MANIFEST ), true );
		$this->assertIsArray( $stored );
		$this->assertArrayHasKey( 'counts', $stored );

		$live_pro  = Mcpwp_Tool_Count_Snapshot::compute( true );
		$live_free = Mcpwp_Tool_Count_Snapshot::compute( false );

		$this->assertSame( (int) $stored['counts']['tools_list_full_free'], (int) $live_free['tools_list_full'], 'tools_list_full_free drifted.' );
		$this->assertSame( (int) $stored['counts']['tools_list_full_pro'], (int) $live_pro['tools_list_full'], 'tools_list_full_pro drifted.' );
		$this->assertSame( (int) $stored['counts']['tools_list_core'], (int) $live_pro['tools_list_core'], 'tools_list_core drifted.' );
		$this->assertSame( (int) $stored['counts']['free_registry'], (int) $live_pro['free_registry'], 'free_registry drifted.' );
		$this->assertSame( (int) $stored['counts']['pro_registry'], (int) $live_pro['pro_registry'], 'pro_registry drifted.' );
		$this->assertSame( (int) $stored['counts']['pro_only_tools'], (int) $live_pro['pro_only_tools'], 'pro_only_tools drifted.' );
	}

	public function test_marketing_labels_are_conservative(): void {
		$stored = json_decode( (string) file_get_contents( self::MANIFEST ), true );
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

	/**
	 * @dataProvider public_copy_files
	 */
	public function test_public_copy_matches_manifest( string $path, string $needle ): void {
		$full = dirname( __DIR__, 2 ) . '/' . $path;
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
		if ( ! is_readable( self::MANIFEST ) ) {
			return array();
		}
		$stored = json_decode( (string) file_get_contents( self::MANIFEST ), true );
		$phrase = (string) ( $stored['marketing']['phrase_short'] ?? '' );
		if ( '' === $phrase ) {
			return array();
		}

		return array(
			'root readme'              => array( 'README.md', $phrase ),
			'launch directories index' => array( 'docs/launch/directories/README.md', $phrase ),
			'repo metadata guide'      => array( 'docs/launch/REPO-METADATA.md', $phrase ),
			'connect matrix'           => array( 'docs/launch/CONNECT-MATRIX.md', $phrase ),
		);
	}
}