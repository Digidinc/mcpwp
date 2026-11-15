#!/usr/bin/env php
<?php
/**
 * Regenerate docs/tool-counts.json from the live tool registries (#650).
 *
 * Usage (from repo root, inside php:8.3-cli with vendor mounted):
 *   php scripts/gen-tool-counts.php [--check]
 *
 * --check  Exit 1 when the file on disk differs (CI drift guard; ignores generated_at).
 */

declare(strict_types=1);

$root = realpath( __DIR__ . '/..' );
if ( false === $root ) {
	fwrite( STDERR, "gen-tool-counts: cannot resolve repo root.\n" );
	exit( 1 );
}
require_once $root . '/mcpwp/tests/bootstrap.php';
require_once $root . '/mcpwp/tests/support/class-mcpwp-tool-count-snapshot.php';

$out   = $root . '/docs/tool-counts.json';
$check = in_array( '--check', $argv, true );

try {
	$payload = Mcpwp_Tool_Count_Snapshot::build_manifest();
} catch ( RuntimeException $e ) {
	fwrite( STDERR, 'gen-tool-counts: ' . $e->getMessage() . "\n" );
	exit( 1 );
}

$json = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";

if ( $check ) {
	if ( ! is_readable( $out ) ) {
		fwrite( STDERR, "gen-tool-counts: missing $out — run without --check to create it.\n" );
		exit( 1 );
	}
	$existing = (string) file_get_contents( $out );
	if ( Mcpwp_Tool_Count_Snapshot::normalize_manifest_json( $existing ) !== Mcpwp_Tool_Count_Snapshot::normalize_manifest_json( $json ) ) {
		fwrite( STDERR, "gen-tool-counts: docs/tool-counts.json is out of date — run: php scripts/gen-tool-counts.php\n" );
		exit( 1 );
	}
	fwrite( STDOUT, "gen-tool-counts: docs/tool-counts.json is current.\n" );
	exit( 0 );
}

file_put_contents( $out, $json );
fwrite( STDOUT, "gen-tool-counts: wrote docs/tool-counts.json (" . $payload['counts']['tools_list_full_free'] . ' free / ' . $payload['counts']['tools_list_full_pro'] . " pro tools/list full).\n" );