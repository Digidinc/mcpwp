<?php
/**
 * #829: every MCPWP writer of `_elementor_data` / `_elementor_page_settings`
 * goes through Mcpwp_Elementor_Meta_Write.
 *
 * A pure source scan, kept apart from ElementorNoCollateralKsesTest: that
 * class's setUp() boots the chokepoint and defines ELEMENTOR_VERSION, which
 * must only ever happen in a separate process (#757). Run in the main
 * process, it left a wrapped sanitize_callback closure in the globals and
 * broke PHPUnit's global-state serialization for later isolated tests under
 * random order (#829 review round 3).
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class ElementorMetaWriteChokepointScanTest extends TestCase {

	/**
	 * Every MCPWP writer of these keys goes through the chokepoint. Scans whole
	 * statements (multi-line calls included) for: a meta write naming either
	 * key; a meta write whose key is a variable; a `meta_input` array naming
	 * either key; and a direct `$wpdb` write to postmeta. Each known-safe one
	 * is allowlisted below with the reason it is safe (review round 2, NIT).
	 */
	public function test_no_writer_of_these_keys_bypasses_the_chokepoint(): void {
		$allow = array(
			// Keys from a fixed map of non-Elementor keys in the same function.
			'pro/core/class-mcpwp-events.php|update_post_meta( $post_id, $meta_key,'                         => 'tp_event_* map',
			'pro/core/class-mcpwp-learnpress.php|update_post_meta( $post_id, $meta_key,'                     => '_lp_* maps',
			'pro/core/class-mcpwp-seo.php|update_post_meta( $post_id, $meta_map[ $key ],'                    => 'SEO plugin key maps',
			'pro/core/class-mcpwp-elementor-pro.php|update_post_meta( $template_id, $this->part_'            => 'part flag/tags keys',
			'pro/core/class-mcpwp-elementor-pro.php|update_post_meta( $template_id, $this->archetype_'       => 'archetype flag key',
			'pro/core/class-mcpwp-elementor-pro.php|update_post_meta( $template_id, $meta_key, $value )'     => 'part/archetype metadata maps',
			// Caller-named keys, refused for every `_elementor*` prefix by $blocked_prefixes.
			'core/class-mcpwp-post-fields.php|update_post_meta( $post_id, $key, $value )'                    => 'blocked _elementor prefix',
			// Elementor keys take the chokepoint branch just above this call.
			'pro/api/class-mcpwp-rest-elementor-pro.php|update_post_meta( $id, $key, $sanitized[\'content\'], $value )' => 'KEYS routed to update()',
			// Inside Mcpwp_Elementor_Meta_Write::as_writer( $new_id ).
			'pro/core/class-mcpwp-elementor-pro.php|add_post_meta( $new_id, $key,'                            => 'clone copy loop in as_writer',
			// meta_input arrays, each passed to a wp_insert_post() inside as_writer( 0 ).
			'pro/core/class-mcpwp-elementor-pro.php|$new_post[\'meta_input\'] = array( \'_elementor_data\''    => 'clone insert in as_writer',
			'pro/core/class-mcpwp-elementor-pro.php|$page_data[\'meta_input\'] = array( \'_elementor_data\''   => 'landing insert in as_writer',
			'pro/core/class-mcpwp-page-builder.php|\'meta_input\'  => array( \'_elementor_data\''              => 'page builder insert in as_writer',
			// The Kit CSS byte repair: writes the exact bytes the route already
			// validated (no `</style`), and only when the first write was altered.
			'api/class-mcpwp-rest-elementor.php|$wpdb->update(\n\t\t\t\t$wpdb->postmeta'                     => 'Kit CSS repair',
		);

		$root     = dirname( __DIR__ ) . '/includes';
		$offences = array();
		$files    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
		$keys     = "('_elementor_data'|'_elementor_page_settings'|self::META_KEY|Mcpwp_Elementor_Page_Settings::META_KEY)";

		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() || 'class-mcpwp-elementor-meta-write.php' === $file->getFilename() ) {
				continue;
			}
			$rel  = substr( $file->getPathname(), strlen( $root ) + 1 );
			$code = self::code_without_comments( (string) file_get_contents( $file->getPathname() ) );

			$patterns = array(
				// A meta write naming either key, or with a variable key.
				'#(?<!::)\b(update_post_meta|add_post_meta|update_metadata|add_metadata)\s*\((?:[^;]*?' . $keys . '|\s*[^,;]+,\s*\$)[^;]*;#s',
				// A meta_input array naming either key (an assignment, not a read).
				"#[^\\n;]*('meta_input'\\s*=>|\\['meta_input'\\]\\s*=(?!=))[^\\n;]*" . $keys . '[^\n;]*#',
				// A direct write to postmeta.
				'#\$wpdb->(update|insert|replace|query)\s*\([^;]*postmeta[^;]*;#s',
			);

			foreach ( $patterns as $pattern ) {
				if ( ! preg_match_all( $pattern, $code, $m, PREG_OFFSET_CAPTURE ) ) {
					continue;
				}
				foreach ( $m[0] as list( $statement, $offset ) ) {
					if ( false !== strpos( $statement, 'Mcpwp_Elementor_Meta_Write::' ) || false !== strpos( substr( $code, max( 0, $offset - 30 ), 30 ), 'Mcpwp_Elementor_Meta_Write::' ) ) {
						continue;
					}
					// Only the options/term/user meta of other plugins' tables are
					// out of scope: a postmeta $wpdb write must name one of our keys.
					if ( 0 === strpos( ltrim( $statement ), '$wpdb' ) && ! preg_match( '#_elementor_page_settings|_elementor_data|meta_key#', $statement ) ) {
						continue;
					}
					$allowed = false;
					foreach ( $allow as $sig => $why ) {
						list( $f, $needle ) = explode( '|', $sig, 2 );
						if ( $f === $rel && false !== strpos( $statement, stripcslashes( $needle ) ) ) {
							$allowed = true;
							break;
						}
					}
					if ( ! $allowed ) {
						$line       = substr_count( substr( $code, 0, $offset ), "\n" ) + 1;
						$offences[] = "{$rel}:{$line}: " . preg_replace( '#\s+#', ' ', trim( $statement ) );
					}
				}
			}
		}

		$this->assertSame( array(), $offences, 'Write these keys with Mcpwp_Elementor_Meta_Write::update() or inside as_writer() — or allowlist a proven-safe writer with its reason (#829).' );
	}

	/** PHP source with comments blanked (newlines kept, so line numbers hold). */
	private static function code_without_comments( string $source ): string {
		$out = '';
		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$out .= str_repeat( "\n", substr_count( $token[1], "\n" ) );
				continue;
			}
			$out .= is_array( $token ) ? $token[1] : $token;
		}

		return $out;
	}
}
