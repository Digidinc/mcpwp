<?php
/**
 * #1252 — MySQL strict mode rejects literal DEFAULT on TEXT/BLOB columns (error 1101).
 * Scans every CREATE TABLE source under mcpwp/includes and pins action_log_create_sql().
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-mcpwp-activator.php';

/**
 * Static checks for CREATE TABLE DDL in plugin schema sources.
 */
final class Mcpwp_Strict_Mysql_Text_Defaults_Checker {

	private const TEXT_BLOB_PATTERN = '/\b(tinytext|text|mediumtext|longtext|tinyblob|blob|mediumblob|longblob)\b/i';

	/** Literal DEFAULT on TEXT/BLOB — DEFAULT NULL is allowed (#1252). */
	private const LITERAL_DEFAULT_PATTERN = '/\b(?:tinytext|text|mediumtext|longtext|tinyblob|blob|mediumblob|longblob)\b[^,\n;]*\bDEFAULT\s+(?!NULL\b)/i';

	/**
	 * @return list<array{file:string,table:string,column:string,line:string}>
	 */
	public static function violations_in_includes_dir(): array {
		$dir  = dirname( __DIR__ ) . '/includes';
		$out  = array();
		$iter = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir ) );
		foreach ( $iter as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}
			$path = $file->getPathname();
			$src  = (string) file_get_contents( $path );
			foreach ( self::violations_in_source( $src, $path ) as $v ) {
				$out[] = $v;
			}
		}
		return $out;
	}

	/**
	 * @param string $src  PHP or DDL fragment.
	 * @param string $file Label for messages (path or synthetic).
	 * @return list<array{file:string,table:string,column:string,line:string}>
	 */
	public static function violations_in_source( string $src, string $file ): array {
		$violations = array();
		if ( ! preg_match_all( '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?([^\s(]+)\s*\(/is', $src, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $violations;
		}
		foreach ( $matches[0] as $i => $match ) {
			$table_token = trim( (string) $matches[1][ $i ][0], "`'\"}" );
			$start       = (int) $match[1];
			$body        = self::extract_paren_body( $src, $start + strlen( $match[0] ) - 1 );
			if ( null === $body ) {
				continue;
			}
			foreach ( preg_split( '/\R/', $body ) ?: array() as $line ) {
				$line = trim( $line );
				if ( '' === $line || 0 === strpos( $line, 'PRIMARY KEY' ) || 0 === strpos( $line, 'UNIQUE KEY' ) || 0 === strpos( $line, 'KEY ' ) ) {
					continue;
				}
				if ( ! preg_match( self::TEXT_BLOB_PATTERN, $line ) ) {
					continue;
				}
				if ( ! preg_match( self::LITERAL_DEFAULT_PATTERN, $line ) ) {
					continue;
				}
				$column = '';
				if ( preg_match( '/^\s*[`"]?(\w+)[`"]?/u', $line, $cm ) ) {
					$column = $cm[1];
				}
				$violations[] = array(
					'file'   => $file,
					'table'  => $table_token,
					'column' => $column,
					'line'   => $line,
				);
			}
		}
		return $violations;
	}

	private static function extract_paren_body( string $src, int $open_paren_at ): ?string {
		$depth = 0;
		$len   = strlen( $src );
		$start = null;
		for ( $i = $open_paren_at; $i < $len; $i++ ) {
			$c = $src[ $i ];
			if ( '(' === $c ) {
				if ( 0 === $depth ) {
					$start = $i + 1;
				}
				++$depth;
				continue;
			}
			if ( ')' === $c ) {
				--$depth;
				if ( 0 === $depth && null !== $start ) {
					return substr( $src, $start, $i - $start );
				}
			}
		}
		return null;
	}
}

final class StrictMysqlTextDefaults1252Test extends TestCase {

	public function test_includes_create_table_sources_have_no_text_blob_literal_defaults(): void {
		$violations = Mcpwp_Strict_Mysql_Text_Defaults_Checker::violations_in_includes_dir();
		if ( array() === $violations ) {
			$this->addToAssertionCount( 1 );
			return;
		}
		$messages = array();
		foreach ( $violations as $v ) {
			$messages[] = sprintf(
				'%s: table %s column %s declares a literal DEFAULT on TEXT/BLOB (MySQL strict error 1101): %s',
				$v['file'],
				$v['table'],
				$v['column'] !== '' ? $v['column'] : '(unknown)',
				$v['line']
			);
		}
		$this->fail( "TEXT/BLOB literal DEFAULT violations:\n" . implode( "\n", $messages ) );
	}

	public function test_checker_catches_literal_default_on_text_column(): void {
		$bad = "CREATE TABLE wp_example (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			arguments longtext NOT NULL DEFAULT '',
			PRIMARY KEY (id)
		)";
		$violations = Mcpwp_Strict_Mysql_Text_Defaults_Checker::violations_in_source( $bad, 'synthetic' );
		$this->assertCount( 1, $violations );
		$this->assertSame( 'wp_example', $violations[0]['table'] );
		$this->assertSame( 'arguments', $violations[0]['column'] );
	}

	public function test_checker_allows_default_null_on_longtext(): void {
		$ok = "CREATE TABLE wp_example (
			before_snap longtext DEFAULT NULL,
			PRIMARY KEY (id)
		)";
		$this->assertSame( array(), Mcpwp_Strict_Mysql_Text_Defaults_Checker::violations_in_source( $ok, 'synthetic' ) );
	}

	public function test_action_log_create_sql_has_no_literal_default_on_arguments(): void {
		$GLOBALS['mcpwp_test_dbdelta'] = array();

		$method = new ReflectionMethod( Mcpwp_Activator::class, 'action_log_create_sql' );
		$method->setAccessible( true );
		$sql = $method->invoke( null, 'wp_mcpwp_action_log', 'DEFAULT CHARSET=utf8mb4' );

		$this->assertIsString( $sql );
		$this->assertDoesNotMatchRegularExpression(
			'/arguments\s+longtext\s+NOT\s+NULL\s+DEFAULT\s+/i',
			$sql,
			'arguments must not declare DEFAULT — inserts always supply the column (#1252).'
		);
		$this->assertSame( array(), Mcpwp_Strict_Mysql_Text_Defaults_Checker::violations_in_source( $sql, 'action_log_create_sql()' ) );

		if ( function_exists( 'dbDelta' ) ) {
			dbDelta( $sql );
			$this->assertCount( 1, $GLOBALS['mcpwp_test_dbdelta'] );
			$passed = implode( "\n", $GLOBALS['mcpwp_test_dbdelta'] );
			$this->assertStringContainsString( 'arguments longtext NOT NULL', $passed );
			$this->assertDoesNotMatchRegularExpression( '/arguments\s+longtext\s+NOT\s+NULL\s+DEFAULT/i', $passed );
		}
	}
}