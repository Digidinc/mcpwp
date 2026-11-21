<?php
/**
 * #1253 — the Setup page "Your plan" card, RENDERED under each license state.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

/** Anonymous SDK that has no get_reconnect_url(): the card must offer no link and not fatal (M14). */
class Mcpwp_Test_Fs_1253_NoReconnect {
	public function can_use_premium_code() { return false; }
	public function is_paying() { return false; }
	public function is_trial() { return false; }
	public function is_anonymous() { return true; }
	public function is_pending_activation() { return false; }
	public function get_upgrade_url() { return 'https://site.test/wp-admin/admin.php?page=mcpwp-pricing'; }
	public function get_account_url() { return 'https://site.test/wp-admin/admin.php?page=mcpwp-account'; }
}

class Mcpwp_Test_Fs_1253 {
	public $f;
	public function __construct( $f ) { $this->f = $f; }
	public function can_use_premium_code() { return ! empty( $this->f['pro'] ); }
	public function is_paying() { return ! empty( $this->f['pro'] ); }
	public function is_trial() { return false; }
	public function is_anonymous() { return ! empty( $this->f['anon'] ); }
	public function is_pending_activation() { return false; }
	public function get_reconnect_url( $params = array() ) { return 'https://site.test/wp-admin/admin.php?page=mcpwp-reconnect&fs_action=reset_anonymous_mode&require_license=' . ( $params['require_license'] ?? '' ); }
	public function get_upgrade_url() { return 'https://site.test/wp-admin/admin.php?page=mcpwp-pricing'; }
	public function get_account_url() { return 'https://site.test/wp-admin/admin.php?page=mcpwp-account'; }
}

/** Has both routes: the card must prefer get_reconnect_url() over get_activation_url() (M7b). */
class Mcpwp_Test_Fs_1253_Both extends Mcpwp_Test_Fs_1253 {
	public function get_activation_url( $params = array() ) { return 'https://site.test/WRONG-activation-route?require_license=' . ( $params['require_license'] ?? '' ); }
}


/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SetupLicenseChoices1253Test extends TestCase {

	private function render( array $opts = array(), $fs = null ): string {
		require_once dirname( __DIR__ ) . '/tests/fixtures/setup-display-stubs-1098.php';
		require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-white-label.php';
		$GLOBALS['mcpwp_test_options']['date_format'] = 'Y-m-d';
		$GLOBALS['mcpwp_test_options']['time_format'] = 'H:i';
		foreach ( $opts as $k => $v ) {
			$GLOBALS['mcpwp_test_options'][ $k ] = $v;
		}
		$GLOBALS['mcpwp_test_fs'] = $fs;
		ob_start();
		try {
			mcpwp_1098_render_setup_partial( array(), null );
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	private const WL = array( 'enabled' => true, 'agency_name' => 'Acme', 'hide_mcpwp_branding' => true );

	/** fs_accounts shaped like the SDK's: the product id is registered on every startup, a site entry only once connected. */
	private function accounts( array $site = null ): array {
		$a = array( 'id_slug_type_path_map' => array( '23824' => array( 'slug' => 'mcpwp', 'type' => 'plugin', 'path' => 'mcpwp/mcpwp.php' ) ) );
		if ( null !== $site ) {
			$a['sites'] = array( 'mcpwp' => (object) $site );
		}
		return $a;
	}

	private function had_license(): array {
		return array( 'fs_accounts' => $this->accounts( array( 'id' => '777', 'user_id' => '55', 'license_id' => '9001' ) ) );
	}

	private function card( string $html ): string {
		$start = strpos( $html, 'mcpwp-plan-card' );
		$this->assertNotFalse( $start, 'plan card renders' );
		$end = strpos( $html, 'class="mcpwp-setup-page"', $start );
		return html_entity_decode( substr( $html, $start, $end - $start ) );
	}

	private function sdk( array $flags = array() ) {
		return new Mcpwp_Test_Fs_1253( $flags );
	}

	private const PORTAL = 'https://customers.freemius.com/store/11467';

	public function test_direct_free_shows_three_choices_and_steps_without_key_field(): void {
		$html = $this->render();
		$card = $this->card( $html );
		$this->assertStringContainsString( 'Continue with Free', $card );
		$this->assertStringContainsString( 'Buy a license', $card );
		$this->assertStringContainsString( 'I already have a license', $card );
		$this->assertStringContainsString( 'If you bought a license', $card );
		$this->assertStringContainsString( '<details', $card );
		$this->assertStringContainsString( 'Replace current with uploaded', $card );
		$this->assertStringContainsString( 'href="' . self::PORTAL . '" target="_blank" rel="noopener noreferrer"', $card );
		$this->assertStringContainsString( '(we cannot install it for you from this page)', $card );
		$this->assertStringContainsString( 'href="https://mcpwp.net/pricing/" target="_blank" rel="noopener noreferrer"', $card );
		$this->assertStringNotContainsString( 'users.freemius.com', $html );
		$this->assertStringNotContainsString( '<form', $card );
		$this->assertStringNotContainsString( '<input', $card );
		$this->assertStringNotContainsString( '<script', $card );
		$this->assertStringNotContainsString( 'Manage pricing and license', $html, 'no pricing-only link left in Direct Free' );
		$this->assertStringNotContainsString( 'check your existing license before buying again', $card, 'no recovery claim without license evidence' );
	}

	public function test_a_startup_only_fs_accounts_does_not_trigger_recovery(): void {
		foreach ( array(
			array( 'fs_accounts' => $this->accounts() ),
			array( 'fs_accounts' => $this->accounts( array( 'id' => '777', 'user_id' => '55' ) ) ), // Free-registered: a user, no license.
			array( 'fs_accounts' => $this->accounts( array( 'id' => '777', 'user_id' => '55', 'license_id' => null ) ) ),
			array( 'fs_accounts' => 'a:1:{s:3:"x";s:5:"23824";}' ), // product id substring only.
		) as $opts ) {
			$html = $this->render( $opts );
			$card = $this->card( $html );
			$this->assertStringContainsString( 'Buy a license', $card );
			$this->assertStringNotContainsString( 'check your existing license before buying again', $card );
		}
	}

	public function test_recovery_leads_with_reinstall_and_never_offers_buy(): void {
		$html = $this->render( $this->had_license() );
		$card = $this->card( $html );
		$this->assertStringContainsString( 'check your existing license before buying again', $card );
		$this->assertStringContainsString( 'Replace current with uploaded', $card );
		$this->assertStringContainsString( '<details class="mcpwp-plan-steps" open>', $card );
		$this->assertStringNotContainsString( 'Buy a license', $html );
		$this->assertStringNotContainsString( 'mcpwp.net/pricing', $html );
		$this->assertStringNotContainsString( 'Manage pricing and license', $html );
		$this->assertStringNotContainsString( '<input', $card );
		$this->assertStringContainsString( 'href="' . self::PORTAL . '"', $card );
	}

	public function test_license_evidence_survives_an_incomplete_class_site_entry(): void {
		// The SDK class is absent in a Direct build, so core hands back __PHP_Incomplete_Class.
		$site = unserialize( 'O:7:"FS_Site":2:{s:2:"id";s:3:"777";s:10:"license_id";s:4:"9001";}' );
		$html = $this->render( array( 'fs_accounts' => array( 'sites' => array( 'mcpwp' => $site ) ) ) );
		$this->assertStringContainsString( 'check your existing license before buying again', $this->card( $html ) );
	}

	public function test_serialized_string_accounts_are_read_without_instantiating_classes(): void {
		$raw  = serialize( array( 'sites' => array( 'mcpwp' => array( 'license_id' => '12' ) ) ) );
		$html = $this->render( array( 'fs_accounts' => $raw ) );
		$this->assertStringContainsString( 'check your existing license before buying again', $this->card( $html ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wporg_build_with_license_evidence_still_gets_recovery(): void {
		define( 'MCPWP_WPORG_BUILD', true );
		$html = $this->render( $this->had_license() );
		$this->assertStringContainsString( 'check your existing license before buying again', $this->card( $html ) );
		$this->assertStringNotContainsString( 'Buy a license', $html );
	}

	public function test_steps_cover_both_wordpress_prompts_and_never_promise_immediate_activation(): void {
		foreach ( array( $this->render(), $this->render( $this->had_license() ) ) as $html ) {
			$card = $this->card( $html );
			$this->assertStringContainsString( 'If WordPress offers "Replace current with uploaded"', $card );
			$this->assertStringContainsString( 'If WordPress installs it as a separate plugin instead', $card );
			$this->assertStringContainsString( 'deactivate this copy first', $card );
			$this->assertStringContainsString( 'MCPWP permits only one active copy', $card );
			$this->assertStringNotContainsString( 'WordPress refuses', $card );
			$this->assertStringContainsString( 'activate this one again', $card );
			$this->assertStringContainsString( 'Leave the copy you are not using inactive, and do not delete either copy.', $card );
			// No cleanup step of any kind: the only delete/remove/uninstall words are that warning.
			$this->assertStringNotContainsString( 'remove the extra folder', $card );
			$this->assertStringNotContainsString( 'uninstall', strtolower( $card ) );
			$this->assertSame( 0, preg_match( '/\b(remove|clean ?up)\b/i', $card ), 'no remove/cleanup guidance' );
			$this->assertSame( 1, preg_match_all( '/\bdelet/i', $card ), 'delete appears only in the never-delete warning' );
			$this->assertStringContainsString( 'open MCPWP. If Freemius shows its opt-in screen, choose "Have a license key? Activate License"', $card );
			$this->assertStringContainsString( 'mcpwp-plan-cases', $card );
			$this->assertDoesNotMatchRegularExpression( '/activate(s|d)? (it )?(immediately|instantly)|instant activation|activate (license )?(here|now|from this page)|activate license now/i', $card );
		}
	}

	public function test_white_label_hides_marketing_but_keeps_recovery_path(): void {
		$html = $this->render( array( 'mcpwp_white_label' => self::WL ) );
		$card = $this->card( $html );
		$this->assertStringNotContainsString( 'Buy a license', $html );
		$this->assertStringNotContainsString( 'mcpwp.net/pricing', $html );
		$this->assertStringContainsString( 'I already have a license', $card );
		$this->assertStringContainsString( 'Replace current with uploaded', $card );
		$this->assertStringContainsString( self::PORTAL, $card );
	}

	public function test_white_label_recovery_still_renders(): void {
		$html = $this->render( array( 'mcpwp_white_label' => self::WL ) + $this->had_license() );
		$card = $this->card( $html );
		$this->assertStringContainsString( 'Replace current with uploaded', $card );
		$this->assertStringNotContainsString( 'Buy a license', $html );
		$this->assertStringNotContainsString( 'mcpwp.net/pricing', $html );
	}

	/** M17: Mcpwp_White_Label::is_enabled() needs an agency name; branding-hidden alone is not white-label. */
	public function test_white_label_flag_without_agency_name_is_not_neutral(): void {
		$html = $this->render( array( 'mcpwp_white_label' => array( 'enabled' => true, 'agency_name' => '', 'hide_mcpwp_branding' => true ) ) );
		$this->assertStringContainsString( 'Buy a license', $this->card( $html ) );
		$html = $this->render( array( 'mcpwp_white_label' => array( 'enabled' => false, 'agency_name' => 'Acme', 'hide_mcpwp_branding' => true ) ) );
		$this->assertStringContainsString( 'Buy a license', $this->card( $html ) );
		$html = $this->render( array( 'mcpwp_white_label' => array( 'enabled' => true, 'agency_name' => 'Acme', 'hide_mcpwp_branding' => false ) ) );
		$this->assertStringContainsString( 'Buy a license', $this->card( $html ) );
	}

	public function test_licensed_sdk_keeps_manage_link_and_no_card(): void {
		$html = $this->render( array(), $this->sdk( array( 'pro' => true ) ) );
		$this->assertStringNotContainsString( 'mcpwp-plan-card', $html );
		$this->assertStringNotContainsString( 'Buy a license', $html );
		$this->assertStringContainsString( 'Manage pricing and license', $html );
		$this->assertStringContainsString( 'page=mcpwp-account', $html );
	}

	public function test_sdk_registered_free_has_no_card_and_no_invented_activate_link(): void {
		$html = $this->render( array(), $this->sdk() );
		$this->assertStringNotContainsString( 'mcpwp-plan-card', $html );
		$this->assertStringNotContainsString( 'Activate existing license', $html );
		$this->assertStringNotContainsString( 'require_license', $html );
		$this->assertStringContainsString( 'Manage pricing and license', $html );
	}

	public function test_sdk_anonymous_links_the_reconnect_route_once_and_no_manage_link_in_card(): void {
		$html = $this->render( array(), $this->sdk( array( 'anon' => true ) ) );
		$card = $this->card( $html );
		$this->assertStringContainsString( 'Activate existing license', $card );
		$this->assertStringContainsString( "Opens Freemius's license screen. Enter your license key there.", html_entity_decode( $card, ENT_QUOTES ) );
		$this->assertStringNotContainsString( 'Have a license key? Activate License" there', $card );
		$this->assertStringContainsString( 'fs_action=reset_anonymous_mode', $card );
		$this->assertStringContainsString( 'require_license=true', $card );
		$this->assertStringNotContainsString( 'Manage pricing and license', $card );
		$this->assertSame( 1, substr_count( $html, 'Manage pricing and license' ), 'only the banner carries it' );
		$this->assertStringNotContainsString( '<input', $card );
	}

	public function test_anonymous_sdk_with_both_routes_prefers_reconnect(): void {
		$card = $this->card( $this->render( array(), new Mcpwp_Test_Fs_1253_Both( array( 'anon' => true ) ) ) );
		$this->assertStringContainsString( 'fs_action=reset_anonymous_mode', $card );
		$this->assertStringNotContainsString( 'WRONG-activation-route', $card );
	}

	public function test_anonymous_sdk_without_reconnect_method_offers_no_link_and_does_not_fatal(): void {
		$html = $this->render( array(), new Mcpwp_Test_Fs_1253_NoReconnect() );
		$this->assertStringNotContainsString( 'Activate existing license', $html );
		$this->assertStringNotContainsString( 'mcpwp-plan-card', $html );
		$this->assertStringContainsString( 'Manage pricing and license', $html );
	}

	public function test_no_sdk_account_fallback_is_the_freemius_portal_not_a_404(): void {
		$this->render();
		$this->assertSame( self::PORTAL, Mcpwp_License::get_instance()->get_account_url() );
		$this->assertSame( self::PORTAL, Mcpwp_License::CUSTOMER_PORTAL_URL );
	}

	/** M12: everything the card echoes is escaped (static check of the partial). */
	public function test_card_markup_escapes_every_echo(): void {
		$src   = (string) file_get_contents( dirname( __DIR__ ) . '/admin/partials/mcpwp-setup-display.php' );
		$start = strpos( $src, 'id="mcpwp-plan-card"' );
		$end   = strpos( $src, "<?php settings_errors", $start );
		$this->assertNotFalse( $start );
		$section = substr( $src, $start, $end - $start );
		$this->assertGreaterThanOrEqual( 4, preg_match_all( '/<\?php\s+echo\b[^;]*;/', $section, $echoes ) );
		foreach ( $echoes[0] as $echo ) {
			if ( false !== strpos( $echo, "'recovery' === \$license_state ? ' open'" ) ) {
				continue; // Literal attribute, no variable.
			}
			$this->assertMatchesRegularExpression( '/esc_(url|html|attr)\(|esc_html__\(/', $echo, "Unescaped echo: $echo" );
		}
		$this->assertDoesNotMatchRegularExpression( '/<\?=|\bprint\b/', $section );
		$this->assertStringNotContainsString( '<script', $section );
		$this->assertStringNotContainsString( '<input', $section );
		$this->assertStringNotContainsString( '<form', $section );
	}
}
