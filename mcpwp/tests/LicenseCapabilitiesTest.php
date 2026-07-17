<?php

/**
 * Tests for the canonical license/entitlement state.
 *
 * Verifies that plan and pro_active are always mutually consistent across the
 * single source of truth (Mcpwp_License::get_license_info) and the capabilities
 * builder (Mcpwp_Core::get_capabilities), for every plan state.
 *
 * Freemius is the single source of truth, so every state is simulated by
 * stubbing the Freemius instance (mcpwp_get_fs_instance()) rather than any
 * local option store.
 */

use PHPUnit\Framework\TestCase;

final class LicenseCapabilitiesTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['mcpwp_test_options']    = array();
        $GLOBALS['mcpwp_test_transients'] = array();
        $GLOBALS['mcpwp_test_filters']    = array();
        $GLOBALS['mcpwp_test_fs']         = null;

        $this->reset_license_singleton();
    }

    protected function tearDown(): void
    {
        $GLOBALS['mcpwp_test_fs']      = null;
        $GLOBALS['mcpwp_test_filters'] = array();
        $this->reset_license_singleton();
    }

    /**
     * Reset the Mcpwp_License singleton so each test starts from a clean
     * entitlement state.
     */
    private function reset_license_singleton(): void
    {
        $ref      = new ReflectionClass('Mcpwp_License');
        $instance = $ref->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);
    }

    /**
     * Install a Freemius double reporting a paying plan.
     *
     * @param string $plan Plan slug Freemius would report ('pro', 'agency', ...).
     */
    private function stub_paying(string $plan): void
    {
        $GLOBALS['mcpwp_test_fs'] = new Mcpwp_Test_Fs_Stub(
            true,   // can_use_premium_code
            true,   // is_paying
            false,  // is_trial
            $plan
        );
    }

    /**
     * Install a Freemius double reporting an active trial (not paying).
     */
    private function stub_trial(): void
    {
        $GLOBALS['mcpwp_test_fs'] = new Mcpwp_Test_Fs_Stub(
            true,   // can_use_premium_code
            false,  // is_paying
            true,   // is_trial
            ''
        );
    }

    /**
     * Install a Freemius double reporting no entitlement (free / expired).
     */
    private function stub_unlicensed(): void
    {
        $GLOBALS['mcpwp_test_fs'] = new Mcpwp_Test_Fs_Stub(false, false, false, '');
    }

    /**
     * Assert that a license_info / capabilities pair is internally consistent.
     *
     * @param array $info Result of get_license_info() or capabilities.
     */
    private function assertConsistent(array $info): void
    {
        $plan = $info['plan'];
        $pro  = (bool) ($info['pro_active'] ?? $info['is_pro']);

        if ($pro) {
            $this->assertNotContains(
                $plan,
                array('unlicensed', 'free', ''),
                'pro_active=true must never report a free/unlicensed plan.'
            );
        } else {
            $this->assertContains(
                $plan,
                array('unlicensed', 'free'),
                'pro_active=false must report unlicensed/free plan.'
            );
        }
    }

    private function capabilities(): array
    {
        $core = new Mcpwp_Core();
        return $core->get_capabilities();
    }

    // -- get_license_info() matrix -------------------------------------------

    public function test_unlicensed_is_consistent(): void
    {
        // No Freemius instance at all.
        $info = Mcpwp_License::get_instance()->get_license_info();
        $this->assertSame('unlicensed', $info['plan']);
        $this->assertFalse($info['is_pro']);
        $this->assertFalse($info['is_paying']);
        $this->assertFalse($info['is_agency']);
        $this->assertConsistent($info);
    }

    public function test_trial_is_consistent(): void
    {
        $this->stub_trial();

        $info = Mcpwp_License::get_instance()->get_license_info();
        $this->assertSame('trial', $info['plan']);
        $this->assertTrue($info['is_pro']);
        $this->assertFalse($info['is_paying']);
        $this->assertFalse($info['is_agency']);
        $this->assertConsistent($info);
    }

    public function test_pro_is_consistent(): void
    {
        $this->stub_paying('pro');

        $info = Mcpwp_License::get_instance()->get_license_info();
        $this->assertSame('pro', $info['plan']);
        $this->assertTrue($info['is_pro']);
        $this->assertTrue($info['is_paying']);
        $this->assertFalse($info['is_agency']);
        $this->assertConsistent($info);
    }

    public function test_agency_is_consistent(): void
    {
        $this->stub_paying('agency');

        $info = Mcpwp_License::get_instance()->get_license_info();
        $this->assertSame('agency', $info['plan']);
        $this->assertTrue($info['is_pro']);
        $this->assertTrue($info['is_paying']);
        $this->assertTrue($info['is_agency']);
        $this->assertConsistent($info);
    }

    public function test_expired_license_is_consistent(): void
    {
        // "Expired" means Freemius reports neither paying nor trial.
        $this->stub_unlicensed();

        $info = Mcpwp_License::get_instance()->get_license_info();
        $this->assertSame('unlicensed', $info['plan']);
        $this->assertFalse($info['is_pro']);
        $this->assertFalse($info['is_paying']);
        $this->assertFalse($info['is_agency']);
        $this->assertConsistent($info);
    }

    public function test_partial_license_does_not_report_free_plan(): void
    {
        // A paying Freemius entitlement that reports a missing/free plan slug.
        // The accessor must coerce this to a sane paid plan rather than
        // reporting a contradiction.
        $this->stub_paying('free');

        $info = Mcpwp_License::get_instance()->get_license_info();
        $this->assertTrue($info['is_pro']);
        $this->assertSame('pro', $info['plan'], 'A paying entitlement must not collapse to a free plan.');
        $this->assertConsistent($info);
    }

    // -- capabilities() matrix (full pipeline incl. pro bootstrap filter) ----

    public function test_capabilities_unlicensed_is_consistent(): void
    {
        $caps = $this->capabilities();
        $this->assertSame('unlicensed', $caps['plan']);
        $this->assertFalse($caps['pro_active']);
        $this->assertConsistent($caps);
    }

    public function test_capabilities_pro_is_consistent(): void
    {
        $this->stub_paying('pro');
        $caps = $this->capabilities();
        $this->assertSame('pro', $caps['plan']);
        $this->assertTrue($caps['pro_active']);
        $this->assertConsistent($caps);
    }

    public function test_capabilities_agency_is_consistent(): void
    {
        $this->stub_paying('agency');
        $caps = $this->capabilities();
        $this->assertSame('agency', $caps['plan']);
        $this->assertTrue($caps['pro_active']);
        $this->assertConsistent($caps);
    }

    public function test_capabilities_trial_is_consistent(): void
    {
        $this->stub_trial();
        $caps = $this->capabilities();
        $this->assertSame('trial', $caps['plan']);
        $this->assertTrue($caps['pro_active']);
        $this->assertConsistent($caps);
    }

    public function test_capabilities_expired_is_consistent(): void
    {
        $this->stub_unlicensed();
        $caps = $this->capabilities();
        $this->assertSame('unlicensed', $caps['plan']);
        $this->assertFalse($caps['pro_active']);
        $this->assertConsistent($caps);
    }

    /**
     * The pro bootstrap filter (which only loads when licensed) must NOT be the
     * thing that defines plan/pro_active. Even if it runs while an agency
     * license is active, the canonical accessor wins and stays consistent.
     */
    public function test_pro_bootstrap_filter_does_not_override_canonical_plan(): void
    {
        $this->stub_paying('agency');
        add_filter('mcpwp_site_capabilities', array('Mcpwp_Pro_Bootstrap', 'add_pro_capabilities'));

        $caps = $this->capabilities();

        // Pro-module-only flags are still added by the filter.
        $this->assertArrayHasKey('learnpress', $caps);
        $this->assertArrayHasKey('tp_events', $caps);

        // Canonical plan/pro_active remain consistent and reflect the license.
        $this->assertSame('agency', $caps['plan']);
        $this->assertTrue($caps['pro_active']);
        $this->assertConsistent($caps);
    }

    /**
     * WP.org build: licensing is always off, so plan/pro_active must report the
     * free/unlicensed state consistently regardless of any Freemius state.
     *
     * Runs in a separate process because MCPWP_WPORG_BUILD is a constant.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_wporg_build_is_unlicensed_and_consistent(): void
    {
        define('MCPWP_WPORG_BUILD', true);

        // Even with a paying Freemius entitlement, the WP.org build reports free.
        $GLOBALS['mcpwp_test_fs'] = new Mcpwp_Test_Fs_Stub(true, true, false, 'agency');

        $info = Mcpwp_License::get_instance()->get_license_info();
        $this->assertFalse($info['is_pro']);
        $this->assertSame('unlicensed', $info['plan']);
        $this->assertConsistent($info);

        $core = new Mcpwp_Core();
        $caps = $core->get_capabilities();
        $this->assertFalse($caps['pro_active']);
        $this->assertSame('unlicensed', $caps['plan']);
        $this->assertConsistent($caps);
    }

    // -- get_unlicensed_reason() (#656: diagnose unlicensed without exposing the key) --

    public function test_reason_is_empty_string_when_pro(): void
    {
        $this->stub_paying('pro');
        $info = Mcpwp_License::get_instance()->get_license_info();
        $this->assertSame('', $info['reason']);
        $this->assertSame('', Mcpwp_License::get_instance()->get_unlicensed_reason());
    }

    public function test_reason_is_no_freemius_sdk_when_no_fs_instance(): void
    {
        // No Freemius instance at all — the self-hosted package (no SDK
        // bundled) reports this same state, which was the likely root cause
        // in #656: a previously-Freemius-licensed site loses Pro silently if
        // a self-hosted update ever gets installed over it.
        $info = Mcpwp_License::get_instance()->get_license_info();
        $this->assertSame('no_freemius_sdk', $info['reason']);
    }

    public function test_reason_is_no_active_license_when_fs_reports_unlicensed(): void
    {
        // Freemius instance present and reachable, but no paying/trial/premium
        // entitlement — a genuinely unlicensed connected site.
        $this->stub_unlicensed();
        $info = Mcpwp_License::get_instance()->get_license_info();
        $this->assertSame('no_active_license', $info['reason']);
    }

    public function test_reason_is_freemius_removed_when_residual_account_data_present(): void
    {
        // No Freemius SDK loaded (same as the plain no_freemius_sdk case), but
        // wp_options still holds Freemius's own site-wide account record
        // mentioning our product ID — evidence this site WAS Freemius-licensed
        // before the SDK-less (self-hosted) package got installed over it.
        update_option('fs_accounts', array(
            'sites' => array(
                Mcpwp_License::FREEMIUS_PRODUCT_ID => array( 'id' => '999' ),
            ),
        ));

        $info = Mcpwp_License::get_instance()->get_license_info();
        $this->assertSame('freemius_removed_had_license', $info['reason']);
    }

    public function test_reason_stays_no_freemius_sdk_when_account_data_is_for_other_product(): void
    {
        // A DIFFERENT Freemius-integrated plugin's account data must not be
        // mistaken for evidence that THIS product was ever licensed.
        update_option('fs_accounts', array(
            'sites' => array( '99999' => array( 'id' => '1' ) ),
        ));

        $info = Mcpwp_License::get_instance()->get_license_info();
        $this->assertSame('no_freemius_sdk', $info['reason']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_reason_is_wporg_build_regardless_of_fs_state(): void
    {
        define('MCPWP_WPORG_BUILD', true);
        $this->stub_paying('agency');

        $info = Mcpwp_License::get_instance()->get_license_info();
        $this->assertSame('wporg_build', $info['reason']);
    }
}
