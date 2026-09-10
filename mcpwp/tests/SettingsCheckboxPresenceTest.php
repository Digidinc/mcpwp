<?php
/**
 * Every checkbox in mcpwp_settings changes only when something set it.
 *
 * sanitize_settings() runs on EVERY update_option('mcpwp_settings'), not only
 * on a form post. destructive_requires_approval already used a presence
 * marker (#536 round 4); every other boolean still read "absent from the
 * input" as "unchecked", so a partial write — a plugin, a migration, the
 * settings API, WordPress re-running the sanitizer — silently switched off
 * logging, response-data capture, OAuth, alerts, analytics and the mupot
 * connector. Pinned here, for every boolean the sanitizer writes:
 *
 *   - the form, with the box unchecked (its marker posted), turns it off;
 *   - a write that neither posts the form nor names the key keeps the
 *     stored value;
 *   - with nothing stored, the documented default applies (analytics has
 *     none to write: its default depends on the plan);
 *   - an explicit value in a programmatic write is honoured, so the REST
 *     /plugin-settings path keeps working with the sanitizer attached.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/admin/class-mcpwp-settings.php';

final class SettingsCheckboxPresenceTest extends TestCase {

	/**
	 * Checkbox => documented default. null: no default is written, because
	 * Mcpwp_Analytics::is_enabled() reads "not set" as "the plan decides".
	 */
	private const FLAGS = array(
		'enable_logging'          => true,
		'log_store_response_data' => true,
		'oauth_enabled'           => false,
		'alerts_enabled'          => false,
		'mupot_enabled'           => false,
		'analytics_enabled'       => null,
	);

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options'] = array();
		$GLOBALS['mcpwp_test_filters'] = array();
		unset( $GLOBALS['mcpwp_test_option_store_hook'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['mcpwp_test_option_store_hook'] );
	}

	private function settings(): Mcpwp_Settings {
		return new Mcpwp_Settings();
	}

	private function store( array $values ): void {
		$GLOBALS['mcpwp_test_options']['mcpwp_settings'] = $values;
	}

	private function all( $value ): array {
		return array_fill_keys( array_keys( self::FLAGS ), $value );
	}

	public function test_the_list_covers_every_boolean_the_sanitizer_writes(): void {
		$input = array( '__present' => array_merge( array_keys( self::FLAGS ), array( 'destructive_requires_approval' ) ) );
		foreach ( array_keys( self::FLAGS ) as $flag ) {
			$input[ $flag ] = '1';
		}
		$input['destructive_requires_approval'] = '1';

		$booleans = array_keys( array_filter( $this->settings()->sanitize_settings( $input ), 'is_bool' ) );
		sort( $booleans );
		$expected = array_merge( array_keys( self::FLAGS ), array( 'destructive_requires_approval' ) );
		sort( $expected );

		$this->assertSame( $expected, $booleans, 'A new boolean setting needs the presence rule and a row in FLAGS.' );
	}

	public function test_a_form_post_with_the_box_unchecked_turns_it_off(): void {
		foreach ( array_keys( self::FLAGS ) as $flag ) {
			$this->store( $this->all( true ) );
			$out = $this->settings()->sanitize_settings( array( '__present' => array( $flag ) ) );
			$this->assertFalse( $out[ $flag ], "$flag: unchecked in the form (marker posted) = off." );
		}
	}

	public function test_a_form_post_with_the_box_checked_turns_it_on(): void {
		foreach ( array_keys( self::FLAGS ) as $flag ) {
			$this->store( $this->all( false ) );
			$out = $this->settings()->sanitize_settings( array( '__present' => array( $flag ), $flag => '1' ) );
			$this->assertTrue( $out[ $flag ], "$flag: checked in the form = on." );
		}
	}

	public function test_a_partial_programmatic_update_keeps_the_stored_value(): void {
		foreach ( array_keys( self::FLAGS ) as $flag ) {
			foreach ( array( true, false ) as $stored ) {
				$this->store( array( $flag => $stored ) );
				$out = $this->settings()->sanitize_settings( array( 'log_retention_days' => 10 ) );
				$this->assertArrayHasKey( $flag, $out, "$flag: a stored choice is never dropped." );
				$this->assertSame( $stored, $out[ $flag ], "$flag: a write that does not name it keeps the stored value." );
			}
		}
	}

	public function test_first_save_with_nothing_stored_uses_the_documented_default(): void {
		$out = $this->settings()->sanitize_settings( array( 'log_retention_days' => 10 ) );

		foreach ( self::FLAGS as $flag => $default ) {
			if ( null === $default ) {
				$this->assertArrayNotHasKey( $flag, $out, "$flag: nothing stored and nothing posted writes no value, so the plan default stands." );
				continue;
			}
			$this->assertSame( $default, $out[ $flag ], "$flag: documented default." );
			$this->assertSame( $this->settings()->get_defaults()[ $flag ], $out[ $flag ], "$flag: matches get_defaults()." );
		}
	}

	public function test_the_form_is_authoritative_on_first_save(): void {
		$out = $this->settings()->sanitize_settings( array( '__present' => array_keys( self::FLAGS ) ) );

		foreach ( array_keys( self::FLAGS ) as $flag ) {
			$this->assertFalse( $out[ $flag ], "$flag: an unchecked box on the first form save is off." );
		}
	}

	public function test_an_explicit_value_in_a_programmatic_write_is_honoured(): void {
		foreach ( array_keys( self::FLAGS ) as $flag ) {
			$this->store( array( $flag => true ) );
			$this->assertFalse( $this->settings()->sanitize_settings( array( $flag => false ) )[ $flag ], "$flag: explicit false." );
			$this->store( array( $flag => false ) );
			$this->assertTrue( $this->settings()->sanitize_settings( array( $flag => true ) )[ $flag ], "$flag: explicit true." );
		}
	}

	public function test_sanitizing_the_sanitizers_own_output_changes_no_checkbox(): void {
		// WordPress runs the sanitize callback twice on an option's first
		// save (update_option → add_option). The second pass sees the first
		// pass's output: no markers, every key named.
		$this->store( $this->all( true ) );
		$first  = $this->settings()->sanitize_settings( array( '__present' => array_keys( self::FLAGS ), 'alerts_enabled' => '1' ) );
		$second = $this->settings()->sanitize_settings( $first );

		foreach ( array_keys( self::FLAGS ) as $flag ) {
			$this->assertSame( $first[ $flag ], $second[ $flag ], "$flag: stable across a second pass." );
		}
	}

	public function test_the_form_renders_a_presence_marker_for_every_checkbox(): void {
		if ( ! function_exists( 'checked' ) ) {
			function checked( $checked, $current = true, $echo = true ) { return $checked == $current ? 'checked=\'checked\'' : ''; } // phpcs:ignore
		}
		if ( ! function_exists( 'esc_js' ) ) {
			function esc_js( $text ) { return str_replace( array( '\\', "'", "\r", "\n" ), array( '\\\\', "\\'", '', '\\n' ), (string) $text ); } // phpcs:ignore
		}
		$settings = $this->settings();

		foreach ( array_keys( self::FLAGS ) as $flag ) {
			ob_start();
			if ( 'analytics_enabled' === $flag ) {
				$settings->render_analytics_enabled_field();
			} else {
				$settings->render_checkbox_field( array( 'id' => $flag, 'description' => 'x' ) );
			}
			$html = ob_get_clean();
			$this->assertStringContainsString( 'name="mcpwp_settings[__present][]" value="' . $flag . '"', $html, "$flag: the form proves it rendered the box." );
		}

		// Every checkbox the General Settings form registers uses a renderer
		// that emits the marker.
		$source = file_get_contents( dirname( __DIR__ ) . '/includes/admin/class-mcpwp-settings.php' );
		preg_match_all( "/add_settings_field\\(\\s*'([a-z_]+)',[^;]*?array\\( \\\$this, '(render_[a-z_]+)' \\),\\s*'mcpwp_settings',/s", $source, $m, PREG_SET_ORDER );
		$rendered = array();
		foreach ( $m as $row ) {
			if ( in_array( $row[2], array( 'render_checkbox_field', 'render_analytics_enabled_field' ), true ) ) {
				$rendered[] = $row[1];
			}
		}
		sort( $rendered );
		$expected = array_merge( array_keys( self::FLAGS ), array( 'destructive_requires_approval' ) );
		sort( $expected );
		$this->assertSame( $expected, $rendered );
	}

	public function test_the_rest_plugin_settings_update_still_works_with_the_sanitizer_attached(): void {
		// In wp-admin (the admin chat dispatches REST internally) the
		// sanitize callback is registered, so update_plugin_settings()'s
		// update_option() goes through it. That path writes the whole stored
		// array with one key changed and no markers.
		$settings = $this->settings();
		$GLOBALS['mcpwp_test_option_store_hook'] = function ( $name, $value ) use ( $settings ) {
			return 'mcpwp_settings' === $name ? $settings->sanitize_settings( $value ) : $value;
		};
		$this->store(
			array(
				'enable_logging'          => true,
				'log_store_response_data' => false,
				'oauth_enabled'           => false,
				'alerts_enabled'          => true,
			)
		);

		$rest = new Mcpwp_REST_Site_Settings();
		$rest->update_plugin_settings( new WP_REST_Request( 'POST', '/mcpwp/v1/plugin-settings', array(), array(), array( 'oauth_enabled' => true ) ) );
		$stored = get_option( 'mcpwp_settings' );
		$this->assertTrue( $stored['oauth_enabled'], 'The REST write lands.' );
		$this->assertTrue( $stored['enable_logging'] );
		$this->assertFalse( $stored['log_store_response_data'] );
		$this->assertTrue( $stored['alerts_enabled'] );

		$rest->update_plugin_settings( new WP_REST_Request( 'POST', '/mcpwp/v1/plugin-settings', array(), array(), array( 'enable_logging' => false ) ) );
		$stored = get_option( 'mcpwp_settings' );
		$this->assertFalse( $stored['enable_logging'], 'A REST false lands too.' );
		$this->assertTrue( $stored['oauth_enabled'], 'The earlier REST write is kept.' );
		$this->assertTrue( $stored['alerts_enabled'] );
	}
}
