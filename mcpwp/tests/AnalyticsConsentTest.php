<?php
/**
 * Analytics consent: a stored opt-in only ever comes from an explicit choice.
 *
 * Review r1 P2-A. With the settings checkbox made presence-aware, a paying
 * site that had never touched analytics rendered the box checked (the plan
 * default), and any save of the settings form stored analytics_enabled=true.
 * That value outlived the plan: after a downgrade to Free — or a database
 * cloned onto a Free staging site — telemetry kept running on an install
 * whose owner never opted in.
 *
 * Pinned here:
 *   - the form posts the state it showed; a choice is stored only when the
 *     admin changed it, otherwise the key stays absent and the plan default
 *     keeps applying;
 *   - on Free, Mcpwp_Analytics::is_enabled() treats only a true recorded as
 *     an explicit choice (analytics_choice = explicit) as consent;
 *   - opt-ins stored before 3.11.1 prove nothing about who chose them (any API
 *     key could write them through the generic option tool until v3.4.2), so
 *     they are labelled legacy: off on Free until an administrator ticks the
 *     box (a notice asks), unchanged on a paying site (review r2 N1);
 *   - the admin pages load PostHog in the browser only on that same decision
 *     (review r2 N3).
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/admin/class-mcpwp-settings.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-analytics.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-activator.php';

final class AnalyticsConsentTest extends TestCase {

	/** @var string[] PostHog requests seen. */
	private $captures = array();

	/** @var mixed */
	private $previous_user;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options'] = array();
		$GLOBALS['mcpwp_test_filters'] = array();
		$GLOBALS['mcpwp_test_fs']      = null;
		unset( $GLOBALS['mcpwp_test_option_store_hook'] );
		$this->previous_user                = $GLOBALS['mcpwp_test_current_user'] ?? 0;
		$GLOBALS['mcpwp_test_current_user'] = 1;
		unset( $GLOBALS['mcpwp_test_user_caps'][1], $GLOBALS['mcpwp_test_user_caps'][2] );
		$this->captures = array();
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( false !== strpos( (string) $url, 'posthog' ) ) {
					$this->captures[] = (string) $url;
				}
				return array( 'response' => array( 'code' => 200 ), 'body' => '' );
			},
			10,
			3
		);
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_fs']           = null;
		$GLOBALS['mcpwp_test_filters']      = array();
		$GLOBALS['mcpwp_test_current_user'] = $this->previous_user;
		unset( $GLOBALS['mcpwp_test_option_store_hook'], $GLOBALS['mcpwp_test_user_caps'][1], $GLOBALS['mcpwp_test_user_caps'][2] );
	}

	private function configure_posthog(): void {
		require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-encryption.php';
		require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-integration-manager.php';
		$GLOBALS['mcpwp_test_options']['mcpwp_integrations'] = array(
			'posthog' => array(
				'key_encrypted' => Mcpwp_Encryption::get_instance()->encrypt( wp_json_encode( array( 'token' => 'posthog-test-project', 'host' => 'https://eu.i.posthog.com' ) ) ),
				'multi_field'   => true,
			),
		);
	}

	private function notice(): string {
		if ( ! function_exists( 'esc_url' ) ) {
			function esc_url( $url ) { return (string) $url; } // phpcs:ignore
		}
		ob_start();
		Mcpwp_Analytics::render_legacy_consent_notice();
		return (string) ob_get_clean();
	}

	private function paid(): void {
		$GLOBALS['mcpwp_test_fs'] = new Mcpwp_Test_Fs_Stub( true, true );
	}

	private function free(): void {
		$GLOBALS['mcpwp_test_fs'] = new Mcpwp_Test_Fs_Stub( false, false );
	}

	private function stored(): array {
		$settings = get_option( 'mcpwp_settings', array() );
		return is_array( $settings ) ? $settings : array();
	}

	private function render(): string {
		if ( ! function_exists( 'checked' ) ) {
			function checked( $checked, $current = true, $echo = true ) { return $checked == $current ? 'checked=\'checked\'' : ''; } // phpcs:ignore
		}
		if ( ! function_exists( 'esc_js' ) ) {
			function esc_js( $text ) { return str_replace( array( '\\', "'", "\r", "\n" ), array( '\\\\', "\\'", '', '\\n' ), (string) $text ); } // phpcs:ignore
		}
		ob_start();
		( new Mcpwp_Settings() )->render_analytics_enabled_field();
		return (string) ob_get_clean();
	}

	/**
	 * The state the page showed the box in. Read from the posted
	 * __rendered field; a renderer without one showed is_enabled().
	 * (checked() is a shared test double that does not echo, so the
	 * checkbox's own attribute is not a reliable signal here.)
	 */
	private function shown_checked( string $html ): bool {
		if ( preg_match( '/name="mcpwp_settings\[__rendered\]\[analytics_enabled\]" value="([01])"/', $html, $m ) ) {
			return '1' === $m[1];
		}
		return Mcpwp_Analytics::is_enabled();
	}

	/**
	 * Save the General Settings form as an admin would: the box as they left
	 * it, alongside the state the page showed it in.
	 */
	private function save_form( bool $checked, ?bool $shown = null ): void {
		if ( null === $shown ) {
			$shown = $this->shown_checked( $this->render() );
		}
		$input = array(
			'__present'          => array( 'analytics_enabled' ),
			'__rendered'         => array( 'analytics_enabled' => $shown ? '1' : '0' ),
			'log_retention_days' => 10,
		);
		if ( $checked ) {
			$input['analytics_enabled'] = '1';
		}
		update_option( 'mcpwp_settings', ( new Mcpwp_Settings() )->sanitize_settings( $input ) );
	}

	public function test_a_paid_site_saved_with_the_box_untouched_stores_no_choice_and_is_off_after_a_downgrade(): void {
		$this->paid();
		$this->assertTrue( Mcpwp_Analytics::is_enabled(), 'Precondition: the plan default is on for a paying site.' );
		$this->assertTrue( $this->shown_checked( $this->render() ), 'Precondition: the box shows the state in force.' );

		$this->save_form( true ); // shown checked, left checked

		$this->assertArrayNotHasKey( 'analytics_enabled', $this->stored(), 'Saving other settings is not an opt-in.' );
		$this->assertTrue( Mcpwp_Analytics::is_enabled(), 'Still the plan default while paying.' );

		$this->free();
		$this->assertFalse( Mcpwp_Analytics::is_enabled(), 'After a downgrade Free is opt-in, and nobody opted in.' );
	}

	public function test_an_explicit_uncheck_on_a_paid_site_stores_false(): void {
		$this->paid();
		$this->save_form( false ); // shown checked, unchecked by the admin

		$this->assertFalse( $this->stored()['analytics_enabled'] );
		$this->assertFalse( Mcpwp_Analytics::is_enabled() );
		$this->free();
		$this->assertFalse( Mcpwp_Analytics::is_enabled() );
	}

	public function test_an_explicit_check_on_free_is_recorded_as_consent(): void {
		$this->free();
		$this->assertFalse( $this->shown_checked( $this->render() ) );

		$this->save_form( true ); // shown unchecked, checked by the admin

		$this->assertTrue( $this->stored()['analytics_enabled'] );
		$this->assertSame( 'explicit', $this->stored()['analytics_choice'] ?? null );
		$this->assertTrue( Mcpwp_Analytics::is_enabled() );
	}

	public function test_an_untouched_free_save_stores_nothing(): void {
		$this->free();
		$this->save_form( false );

		$this->assertArrayNotHasKey( 'analytics_enabled', $this->stored() );
		$this->assertFalse( Mcpwp_Analytics::is_enabled() );
	}

	public function test_an_explicit_uncheck_after_opting_in_stores_false(): void {
		$this->free();
		update_option( 'mcpwp_settings', array( 'analytics_enabled' => true, 'analytics_choice' => 'explicit' ) );
		$this->save_form( false );

		$this->assertFalse( $this->stored()['analytics_enabled'] );
		$this->assertFalse( Mcpwp_Analytics::is_enabled() );
	}

	public function test_a_stored_choice_survives_an_untouched_save(): void {
		$this->free();
		update_option( 'mcpwp_settings', array( 'analytics_enabled' => true, 'analytics_choice' => 'explicit' ) );
		$this->save_form( true ); // shown checked, left checked

		$this->assertTrue( $this->stored()['analytics_enabled'] );
		$this->assertSame( 'explicit', $this->stored()['analytics_choice'] ?? null );
		$this->assertTrue( Mcpwp_Analytics::is_enabled() );
	}

	public function test_a_posted_check_without_the_shown_state_is_not_consent(): void {
		// A form that did not say what it showed cannot prove a change.
		$this->free();
		$out = ( new Mcpwp_Settings() )->sanitize_settings( array( '__present' => array( 'analytics_enabled' ), 'analytics_enabled' => '1' ) );
		update_option( 'mcpwp_settings', $out );

		$this->assertFalse( Mcpwp_Analytics::is_enabled() );
		$this->assertNotSame( 'explicit', $this->stored()['analytics_choice'] ?? null );
	}

	public function test_on_free_a_true_that_was_not_an_explicit_choice_is_not_consent(): void {
		update_option( 'mcpwp_settings', array( 'analytics_enabled' => true ) );
		$this->free();
		$this->assertFalse( Mcpwp_Analytics::is_enabled(), 'No provenance, no consent on Free.' );
		$this->paid();
		$this->assertTrue( Mcpwp_Analytics::is_enabled(), 'A paying site is on by default anyway.' );

		update_option( 'mcpwp_settings', array( 'analytics_enabled' => true, 'analytics_choice' => 'plan_default' ) );
		$this->free();
		$this->assertFalse( Mcpwp_Analytics::is_enabled(), 'Only "explicit" is consent.' );

		update_option( 'mcpwp_settings', array( 'analytics_enabled' => true, 'analytics_choice' => 'explicit' ) );
		$this->assertTrue( Mcpwp_Analytics::is_enabled() );
	}

	public function test_a_stored_false_wins_on_a_paid_site(): void {
		update_option( 'mcpwp_settings', array( 'analytics_enabled' => false ) );
		$this->paid();
		$this->assertFalse( Mcpwp_Analytics::is_enabled() );
	}

	public function test_a_programmatic_true_is_not_consent_on_free(): void {
		update_option( 'mcpwp_settings', ( new Mcpwp_Settings() )->sanitize_settings( array( 'analytics_enabled' => true ) ) );
		$this->free();
		$this->assertFalse( Mcpwp_Analytics::is_enabled() );
	}

	public function test_the_renderer_posts_the_state_it_showed(): void {
		$this->paid();
		$html = $this->render();
		$this->assertMatchesRegularExpression( '/name="mcpwp_settings\[__rendered\]\[analytics_enabled\]" value="1"/', $html );

		$this->free();
		$html = $this->render();
		$this->assertMatchesRegularExpression( '/name="mcpwp_settings\[__rendered\]\[analytics_enabled\]" value="0"/', $html );
	}

	public function test_wordpress_second_sanitize_pass_keeps_the_choice(): void {
		$this->free();
		$settings = new Mcpwp_Settings();
		$first    = $settings->sanitize_settings(
			array(
				'__present'         => array( 'analytics_enabled' ),
				'__rendered'        => array( 'analytics_enabled' => '0' ),
				'analytics_enabled' => '1',
			)
		);
		$second   = $settings->sanitize_settings( $first );

		$this->assertTrue( $second['analytics_enabled'] );
		$this->assertSame( 'explicit', $second['analytics_choice'] ?? null );
	}

	public function test_the_rest_plugin_settings_path_keeps_the_choice(): void {
		$settings = new Mcpwp_Settings();
		$GLOBALS['mcpwp_test_option_store_hook'] = function ( $name, $value ) use ( $settings ) {
			return 'mcpwp_settings' === $name ? $settings->sanitize_settings( $value ) : $value;
		};
		update_option( 'mcpwp_settings', array( 'analytics_enabled' => true, 'analytics_choice' => 'explicit', 'enable_logging' => true ) );

		( new Mcpwp_REST_Site_Settings() )->update_plugin_settings( new WP_REST_Request( 'POST', '/mcpwp/v1/plugin-settings', array(), array(), array( 'alerts_enabled' => true ) ) );

		$this->assertTrue( $this->stored()['alerts_enabled'] );
		$this->assertTrue( $this->stored()['analytics_enabled'] );
		$this->assertSame( 'explicit', $this->stored()['analytics_choice'] ?? null );
	}

	public function test_opt_ins_stored_before_the_choice_was_recorded_are_labelled_legacy_not_explicit(): void {
		// Review r2 N1: an API key could store this through the generic option
		// tool until v3.4.2; a stored true is not proof of an admin's choice.
		update_option( 'mcpwp_settings', array( 'analytics_enabled' => true, 'enable_logging' => true ) );
		delete_option( 'mcpwp_analytics_choice_stamped' );

		Mcpwp_Activator::stamp_analytics_choice();
		$this->assertSame( 'legacy', $this->stored()['analytics_choice'] ?? null );
		$this->assertTrue( $this->stored()['enable_logging'], 'Only the label is added.' );
		$this->free();
		$this->assertFalse( Mcpwp_Analytics::is_enabled(), 'A legacy true is not consent on Free.' );
		$this->paid();
		$this->assertTrue( Mcpwp_Analytics::is_enabled(), 'A paying site keeps its plan default.' );

		// Once: a true written afterwards without a recorded choice is left alone.
		update_option( 'mcpwp_settings', array( 'analytics_enabled' => true ) );
		Mcpwp_Activator::stamp_analytics_choice();
		$this->assertArrayNotHasKey( 'analytics_choice', $this->stored() );
	}

	public function test_a_stored_false_or_no_value_is_never_stamped(): void {
		update_option( 'mcpwp_settings', array( 'analytics_enabled' => false ) );
		delete_option( 'mcpwp_analytics_choice_stamped' );
		Mcpwp_Activator::stamp_analytics_choice();
		$this->assertArrayNotHasKey( 'analytics_choice', $this->stored() );
	}

	public function test_a_migrated_2_8_opt_in_is_labelled_legacy(): void {
		require_once dirname( __DIR__ ) . '/includes/class-mcpwp-migrate.php';
		$GLOBALS['mcpwp_test_transients'] = array();

		// Direct copy: no mcpwp_settings yet.
		Mcpwp_Migrate::reset_flag();
		update_option( 'spai_settings', array( 'enable_logging' => true, 'analytics_enabled' => true ) );
		update_option( 'mcpwp_analytics_choice_stamped', 1 ); // this site already ran the stamp
		Mcpwp_Migrate::run();
		Mcpwp_Activator::stamp_analytics_choice(); // runs after the migration on plugin load
		$this->assertTrue( $this->stored()['analytics_enabled'] );
		$this->assertSame( 'legacy', $this->stored()['analytics_choice'] ?? null, 'Direct copy of a 2.8 opt-in.' );

		// Deep merge over v3 defaults.
		$GLOBALS['mcpwp_test_options'] = array();
		Mcpwp_Migrate::reset_flag();
		update_option( 'mcpwp_settings', array( 'enable_logging' => true, 'analytics_enabled' => false ) );
		update_option( 'spai_settings', array( 'enable_logging' => true, 'analytics_enabled' => true ) );
		update_option( 'mcpwp_analytics_choice_stamped', 1 );
		Mcpwp_Migrate::run();
		Mcpwp_Activator::stamp_analytics_choice();
		$this->assertSame( 'legacy', $this->stored()['analytics_choice'] ?? null, 'Deep merge of a 2.8 opt-in.' );
		$this->free();
		$this->assertFalse( Mcpwp_Analytics::is_enabled() );
		$this->paid();
		$this->assertTrue( Mcpwp_Analytics::is_enabled() );
	}

	// -----------------------------------------------------------------
	// Review r2 N1 / N3: legacy values, capture everywhere, the notice.
	// -----------------------------------------------------------------

	public function test_a_legacy_true_on_free_sends_nothing_server_side_or_to_the_browser(): void {
		$this->configure_posthog();
		$this->free();
		update_option( 'mcpwp_settings', array( 'analytics_enabled' => true, 'analytics_choice' => 'legacy' ) );

		Mcpwp_Analytics::on_tool_called( 'wp_list_pages', 'content', 12, '' );
		Mcpwp_Analytics::capture( 'anything' );
		$this->assertSame( array(), $this->captures, 'No server-side capture.' );

		$browser = Mcpwp_Analytics::browser_config();
		$this->assertSame( '', $browser['token'], 'No PostHog token for the admin pages.' );
	}

	public function test_re_ticking_the_box_turns_legacy_into_explicit_consent_and_capture_sends(): void {
		$this->configure_posthog();
		$this->free();
		update_option( 'mcpwp_settings', array( 'analytics_enabled' => true, 'analytics_choice' => 'legacy' ) );
		$this->assertFalse( $this->shown_checked( $this->render() ), 'Shown unchecked: it is off.' );

		$this->save_form( true );

		$this->assertSame( 'explicit', $this->stored()['analytics_choice'] ?? null );
		Mcpwp_Analytics::on_tool_called( 'wp_list_pages', 'content', 12, '' );
		$this->assertCount( 1, $this->captures );
		$this->assertSame( 'posthog-test-project', Mcpwp_Analytics::browser_config()['token'] );
		$this->assertSame( '', $this->notice(), 'Confirmed: no more notice.' );
	}

	public function test_a_paid_site_with_a_legacy_true_is_unchanged(): void {
		$this->configure_posthog();
		$this->paid();
		update_option( 'mcpwp_settings', array( 'analytics_enabled' => true, 'analytics_choice' => 'legacy' ) );

		$this->assertTrue( Mcpwp_Analytics::is_enabled() );
		Mcpwp_Analytics::on_tool_called( 'wp_list_pages', 'content', 12, '' );
		$this->assertCount( 1, $this->captures );
		$this->assertSame( 'posthog-test-project', Mcpwp_Analytics::browser_config()['token'] );
		$this->assertSame( '', $this->notice(), 'No notice on a paying site.' );
	}

	public function test_the_browser_gets_a_token_only_on_the_server_side_decision(): void {
		$config = array( 'token' => 'posthog-test-project', 'host' => 'https://eu.i.posthog.com' );

		$this->free();
		$this->assertSame( '', Mcpwp_Analytics::browser_config( $config )['token'], 'Free, nothing stored.' );
		update_option( 'mcpwp_settings', array( 'analytics_enabled' => true ) );
		$this->assertSame( '', Mcpwp_Analytics::browser_config( $config )['token'], 'Free, a bare true.' );
		update_option( 'mcpwp_settings', array( 'analytics_enabled' => true, 'analytics_choice' => 'explicit' ) );
		$this->assertSame( 'posthog-test-project', Mcpwp_Analytics::browser_config( $config )['token'], 'Free, explicit.' );
		update_option( 'mcpwp_settings', array( 'analytics_enabled' => false ) );
		$this->paid();
		$this->assertSame( '', Mcpwp_Analytics::browser_config( $config )['token'], 'Paid, switched off.' );

		// The admin script takes its token from that function, not straight
		// from the integration config.
		$admin = file_get_contents( dirname( __DIR__ ) . '/includes/admin/class-mcpwp-admin.php' );
		$this->assertStringContainsString( 'Mcpwp_Analytics::browser_config()', $admin );
		$this->assertStringNotContainsString( 'get_posthog_config()', $admin, 'The admin never reads the raw integration config.' );
	}

	public function test_an_untouched_save_and_the_rest_path_keep_the_legacy_label(): void {
		$this->free();
		update_option( 'mcpwp_settings', array( 'analytics_enabled' => true, 'analytics_choice' => 'legacy' ) );
		$this->save_form( false ); // shown unchecked, left unchecked
		$this->assertTrue( $this->stored()['analytics_enabled'] );
		$this->assertSame( 'legacy', $this->stored()['analytics_choice'] ?? null );

		$settings = new Mcpwp_Settings();
		$GLOBALS['mcpwp_test_option_store_hook'] = function ( $name, $value ) use ( $settings ) {
			return 'mcpwp_settings' === $name ? $settings->sanitize_settings( $value ) : $value;
		};
		( new Mcpwp_REST_Site_Settings() )->update_plugin_settings( new WP_REST_Request( 'POST', '/mcpwp/v1/plugin-settings', array(), array(), array( 'alerts_enabled' => true ) ) );
		$this->assertSame( 'legacy', $this->stored()['analytics_choice'] ?? null );
		$this->assertFalse( Mcpwp_Analytics::is_enabled() );
	}

	public function test_the_legacy_notice_asks_an_administrator_on_free_and_carries_no_data(): void {
		$this->free();
		update_option( 'mcpwp_settings', array( 'analytics_enabled' => true, 'analytics_choice' => 'legacy' ) );
		update_option( Mcpwp_Analytics::UUID_OPTION, 'mcpwp-11111111-2222-3333-4444-555555555555' );
		$this->configure_posthog();

		$html = $this->notice();
		$this->assertStringContainsString( 'notice', $html );
		$this->assertStringContainsString( 'page=mcpwp-settings', $html, 'Points to Settings.' );
		$this->assertStringContainsString( 'mcpwp_dismiss_analytics_notice', $html, 'Can be dismissed.' );
		$this->assertStringNotContainsString( '11111111-2222', $html, 'No site id.' );
		$this->assertStringNotContainsString( 'posthog-test-project', $html, 'No token.' );

		// Not for someone who cannot manage options.
		$GLOBALS['mcpwp_test_current_user'] = 2;
		$GLOBALS['mcpwp_test_user_caps'][2] = array( 'manage_options' => false );
		$this->assertSame( '', $this->notice() );
		$this->assertFalse( Mcpwp_Analytics::dismiss_legacy_notice( 'test-nonce' ), 'Nor can they dismiss it.' );

		// Not with a bad nonce; once dismissed, never again.
		$GLOBALS['mcpwp_test_current_user'] = 1;
		$this->assertFalse( Mcpwp_Analytics::dismiss_legacy_notice( 'forged' ) );
		$this->assertNotSame( '', $this->notice() );
		$this->assertTrue( Mcpwp_Analytics::dismiss_legacy_notice( 'test-nonce' ) );
		$this->assertSame( '', $this->notice() );
		$this->assertFalse( Mcpwp_Analytics::is_enabled(), 'Dismissing is not consent.' );
	}

	public function test_no_legacy_notice_without_a_legacy_true(): void {
		$this->free();
		foreach ( array( array(), array( 'analytics_enabled' => false, 'analytics_choice' => 'legacy' ), array( 'analytics_enabled' => true, 'analytics_choice' => 'explicit' ), array( 'analytics_enabled' => true ) ) as $stored ) {
			update_option( 'mcpwp_settings', $stored );
			$this->assertSame( '', $this->notice(), wp_json_encode( $stored ) );
		}
	}
}
