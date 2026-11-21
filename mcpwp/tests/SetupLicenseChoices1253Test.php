<?php
/**
 * #1253 — the Setup page "Your plan" card, RENDERED under each license state.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

class Mcpwp_Test_Fs_1253 {
	public $f;
	public function __construct( $f ) { $this->f = $f; }
	public function can_use_premium_code() { return ! empty( $this->f['pro'] ); }
	public function is_paying() { return ! empty( $this->f['pro'] ); }
	public function is_trial() { return false; }
	public function is_anonymous() { return ! empty( $this->f['anon'] ); }
	public function is_pending_activation() { return false; }
	public function get_reconnect_url() { return 'https://site.test/wp-admin/admin.php?page=mcpwp-reconnect'; }
	public function get_upgrade_url() { return 'https://site.test/wp-admin/admin.php?page=mcpwp-pricing'; }
	public function get_account_url() { return 'https://site.test/wp-admin/admin.php?page=mcpwp-account'; }
}

class Mcpwp_Test_Fs_1253_Act extends Mcpwp_Test_Fs_1253 {
	public function get_activation_url( $params = array() ) {
		return 'https://site.test/wp-admin/admin.php?page=mcpwp&require_license=' . ( $params['require_license'] ?? '' );
	}
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

	private function card( string $html ): string {
		$start = strpos( $html, 'mcpwp-plan-card' );
		$this->assertNotFalse( $start, 'plan card renders' );
		$end = strpos( $html, 'class="mcpwp-setup-page"', $start );
		return html_entity_decode( substr( $html, $start, $end - $start ) );
	}

	private function sdk( array $flags = array(), bool $activation = true ) {
		return $activation ? new Mcpwp_Test_Fs_1253_Act( $flags ) : new Mcpwp_Test_Fs_1253( $flags );
	}

	public function test_direct_free_shows_three_choices_and_steps_without_key_field(): void {
		$html = $this->render();
		$card = $this->card( $html );
		$this->assertStringContainsString( 'Continue with Free', $card );
		$this->assertStringContainsString( 'Buy a license', $card );
		$this->assertStringContainsString( 'I already have a license', $card );
		$this->assertStringContainsString( '<details', $card );
		$this->assertStringContainsString( 'Replace current with uploaded', $card );
		$this->assertStringContainsString( 'Activate License', $card );
		$this->assertStringContainsString( 'href="https://customers.freemius.com/store/11467" target="_blank" rel="noopener noreferrer"', $card );
		$this->assertStringContainsString( 'href="https://mcpwp.net/pricing/" target="_blank" rel="noopener noreferrer"', $card );
		$this->assertStringNotContainsString( '<form', $card );
		$this->assertStringNotContainsString( '<input', $card );
		$this->assertStringNotContainsString( '<script', $card );
		$this->assertStringNotContainsString( 'Manage pricing and license', $html, 'no pricing-only link left in Direct Free' );
	}

	public function test_steps_cover_both_wordpress_prompts_and_never_promise_immediate_activation(): void {
		foreach ( array( $this->card( $this->render() ), $this->card( $this->render( array( 'fs_accounts' => 'a:1:{s:3:"x";s:5:"23824";}' ) ) ) ) as $card ) {
			$this->assertStringContainsString( 'If WordPress offers "Replace current with uploaded"', $card );
			$this->assertStringContainsString( 'If WordPress installs it as a separate plugin instead', $card );
			$this->assertStringContainsString( 'deactivate this copy first', $card );
			$this->assertStringContainsString( 'activate this one again', $card );
			$this->assertStringContainsString( 'Never click Delete on either copy', $card );
			$this->assertStringContainsString( 'After the paid package is installed and active', $card );
			$this->assertDoesNotMatchRegularExpression( '/activate(s|d)? (it )?(immediately|instantly)|instant activation|activate (here|now|from this page)/i', $card );
		}
	}

	public function test_recovery_leads_with_reinstall_and_never_offers_buy(): void {
		$html = $this->render( array( 'fs_accounts' => 'a:1:{s:3:"x";s:5:"23824";}' ) );
		$card = $this->card( $html );
		$this->assertStringContainsString( 'do not need to buy again', $card );
		$this->assertStringContainsString( 'Replace current with uploaded', $card );
		$this->assertStringContainsString( ' open', $card );
		$this->assertStringNotContainsString( 'Buy a license', $html );
		$this->assertStringNotContainsString( 'mcpwp.net/pricing', $html );
		$this->assertStringNotContainsString( '<input', $card );
	}

	public function test_white_label_hides_marketing_but_keeps_recovery_path(): void {
		$wl   = array( 'enabled' => true, 'agency_name' => 'Acme', 'hide_mcpwp_branding' => true );
		$html = $this->render( array( 'mcpwp_white_label' => $wl ) );
		$card = $this->card( $html );
		$this->assertStringNotContainsString( 'Buy a license', $html );
		$this->assertStringNotContainsString( 'mcpwp.net/pricing', $html );
		$this->assertStringContainsString( 'I already have a license', $card );
		$this->assertStringContainsString( 'Replace current with uploaded', $card );
		$this->assertStringContainsString( 'customers.freemius.com/store/11467', $card );
	}

	public function test_white_label_recovery_still_renders(): void {
		$wl   = array( 'enabled' => true, 'agency_name' => 'Acme', 'hide_mcpwp_branding' => true );
		$html = $this->render( array( 'mcpwp_white_label' => $wl, 'fs_accounts' => 'a:1:{s:3:"x";s:5:"23824";}' ) );
		$card = $this->card( $html );
		$this->assertStringContainsString( 'Replace current with uploaded', $card );
		$this->assertStringNotContainsString( 'Buy a license', $html );
	}

	public function test_licensed_sdk_keeps_manage_link_and_no_card(): void {
		$html = $this->render( array(), $this->sdk( array( 'pro' => true ) ) );
		$this->assertStringNotContainsString( 'mcpwp-plan-card', $html );
		$this->assertStringNotContainsString( 'Buy a license', $html );
		$this->assertStringContainsString( 'Manage pricing and license', $html );
	}

	public function test_sdk_anonymous_keeps_reconnect_and_adds_activate_existing(): void {
		$html = $this->render( array(), $this->sdk( array( 'anon' => true ) ) );
		$card = $this->card( $html );
		$this->assertStringContainsString( 'Activate existing license', $card );
		$this->assertStringContainsString( 'require_license=true', $card );
		$this->assertStringContainsString( 'page=mcpwp-reconnect', $html );
		$this->assertStringNotContainsString( '<input', $card );
	}

	public function test_sdk_without_activation_method_omits_activate_existing(): void {
		$html = $this->render( array(), $this->sdk( array( 'anon' => true ), false ) );
		$this->assertStringNotContainsString( 'Activate existing license', $html );
	}

	public function test_no_sdk_account_fallback_is_the_freemius_portal_not_a_404(): void {
		$this->render();
		$this->assertSame( 'https://customers.freemius.com/store/11467', Mcpwp_License::get_instance()->get_account_url() );
	}
}
