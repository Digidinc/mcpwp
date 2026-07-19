<?php
/**
 * Guard — no PHP 8.0+ function calls under a "Requires PHP: 7.4" contract.
 *
 * `php -l` (the "PHP 7.4 Validation" CI job) only checks syntax, so a call to an
 * 8.0-only function like str_contains() / str_starts_with() / str_ends_with()
 * passes lint yet is an undefined-function risk on a bare 7.4 runtime (they
 * happen to be polyfilled by WordPress core ≥ 5.9, but the plugin should not
 * lean on that under its own 7.4 contract). This test scans the shipped source
 * for such calls so the regression is caught at test time, not in review.
 *
 * Extend $denied as needed; add a definition-aware exception if the plugin ever
 * ships its own polyfill.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class Php74CompatTest extends TestCase {

	/** PHP 8.0+ functions that `php -l` cannot flag on a 7.4 runtime. */
	private const DENIED = array(
		'str_contains',     // 8.0
		'str_starts_with',  // 8.0
		'str_ends_with',    // 8.0
		'array_is_list',    // 8.1
		'enum_exists',      // 8.1
	);

	/** @return string[] Absolute paths of shipped PHP source files. */
	private function source_files(): array {
		$root  = dirname( __DIR__ );
		$files = array( $root . '/mcpwp.php' );

		$dir = $root . '/includes';
		$it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
				$files[] = $file->getPathname();
			}
		}
		return $files;
	}

	public function test_no_php8_only_function_calls_in_shipped_source(): void {
		$pattern    = '/\b(' . implode( '|', array_map( 'preg_quote', self::DENIED ) ) . ')\s*\(/';
		$violations = array();

		foreach ( $this->source_files() as $path ) {
			$src = file_get_contents( $path );
			if ( false === $src ) {
				continue;
			}
			// Ignore a `function <name>(` definition (a shipped polyfill) — only
			// call-sites are a compat risk.
			$src = preg_replace( '/\bfunction\s+(' . implode( '|', array_map( 'preg_quote', self::DENIED ) ) . ')\b/', 'function _def_', $src );

			if ( preg_match_all( $pattern, $src, $m ) ) {
				$rel = ltrim( str_replace( dirname( __DIR__ ), '', $path ), '/' );
				foreach ( array_unique( $m[1] ) as $fn ) {
					$violations[] = "$rel: $fn()";
				}
			}
		}

		$this->assertSame(
			array(),
			$violations,
			"PHP 8.0+ function calls found under a 'Requires PHP: 7.4' contract "
				. "(php -l does not catch these). Use a 7.4-native equivalent:\n" . implode( "\n", $violations )
		);
	}
}
