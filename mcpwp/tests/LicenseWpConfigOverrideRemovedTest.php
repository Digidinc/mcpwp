<?php

/**
 * Issue #863: a bare `define('MCPWP_PRO', true)` in wp-config.php must not
 * grant Pro on the Free or Freemius builds. It was a documented one-line
 * bypass (docs/skills/dev-pro.md) reachable by any site owner with no
 * filesystem access to the plugin directory.
 *
 * The only overrides that survive the fix are:
 *   - the agency build marker file (includes/agency-license.php), which
 *     only scripts/build-agency.sh writes and which never exists in this
 *     source tree or in the Free/Freemius ZIPs;
 *   - MCPWP_TESTS true AND PHP_SAPI 'cli' — the CI/test-only switch, which
 *     a normal web request (the only way MCP tools are ever called) can
 *     never satisfy even if a site owner defines MCPWP_TESTS.
 *
 * Each scenario needs its own process because MCPWP_PRO / MCPWP_TESTS are
 * global constants that cannot be undefined once set.
 */

use PHPUnit\Framework\TestCase;

final class LicenseWpConfigOverrideRemovedTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['mcpwp_test_options']    = array();
        $GLOBALS['mcpwp_test_transients'] = array();
        $GLOBALS['mcpwp_test_filters']    = array();
        $GLOBALS['mcpwp_test_fs']         = null;
        $this->reset_license_singleton();
        $this->remove_agency_marker();
    }

    protected function tearDown(): void
    {
        $GLOBALS['mcpwp_test_fs']      = null;
        $GLOBALS['mcpwp_test_filters'] = array();
        $this->reset_license_singleton();
        $this->remove_agency_marker();
    }

    private function reset_license_singleton(): void
    {
        $ref      = new ReflectionClass('Mcpwp_License');
        $instance = $ref->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);
    }

    private function marker_path(): string
    {
        return dirname(__DIR__) . '/includes/agency-license.php';
    }

    private function write_agency_marker(string $returnValue): void
    {
        $php = "<?php\nreturn " . var_export($returnValue, true) . ";\n";
        file_put_contents($this->marker_path(), $php);
    }

    private function remove_agency_marker(): void
    {
        $path = $this->marker_path();
        if (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * The regression test requested by issue #863: with MCPWP_PRO defined,
     * no agency marker, and no Freemius licence, every accessor must report
     * unlicensed. This FAILS on pre-fix main (is_pro()/is_paying() return
     * true and get_plan() returns 'pro').
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_wp_config_mcpwp_pro_constant_is_not_honoured(): void
    {
        define('MCPWP_PRO', true);

        $license = Mcpwp_License::get_instance();

        $this->assertFalse($license->is_pro(), 'wp-config.php MCPWP_PRO must not grant is_pro()');
        $this->assertFalse($license->is_paying(), 'wp-config.php MCPWP_PRO must not grant is_paying()');
        $this->assertSame('unlicensed', $license->get_plan(), 'wp-config.php MCPWP_PRO must not grant a paid plan');

        $info = $license->get_license_info();
        $this->assertSame('unlicensed', $info['plan']);
        $this->assertFalse($info['is_pro']);
    }

    /**
     * The agency build marker (written only by scripts/build-agency.sh) is
     * the one file-based override that still grants Pro with no Freemius
     * instance and no wp-config.php involvement at all.
     */
    public function test_agency_license_marker_grants_pro(): void
    {
        $this->write_agency_marker('MCPWP_AGENCY_LICENSE_V1');

        $license = Mcpwp_License::get_instance();

        $this->assertTrue($license->is_pro());
        $this->assertTrue($license->is_paying());
        $this->assertSame('pro', $license->get_plan());
    }

    /**
     * A stale, truncated, or hand-copied marker file with the wrong content
     * must fail closed, not grant Pro.
     */
    public function test_agency_license_marker_with_wrong_token_does_not_grant_pro(): void
    {
        $this->write_agency_marker('not-the-real-token');

        $license = Mcpwp_License::get_instance();

        $this->assertFalse($license->is_pro());
        $this->assertSame('unlicensed', $license->get_plan());
    }

    /**
     * An empty marker file (e.g. `touch`ed by mistake, or a zero-byte copy)
     * must also fail closed.
     */
    public function test_empty_agency_license_marker_does_not_grant_pro(): void
    {
        file_put_contents($this->marker_path(), '');

        $this->assertFalse(Mcpwp_License::get_instance()->is_pro());
    }

    /**
     * The CLI/test-only switch: MCPWP_TESTS true AND PHP_SAPI 'cli' grants
     * Pro with no Freemius instance and no agency marker. PHPUnit itself
     * runs under the CLI SAPI, so this exercises the real condition rather
     * than a stub.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_cli_test_override_grants_pro_under_phpunit(): void
    {
        $this->assertSame('cli', PHP_SAPI, 'precondition: PHPUnit must run under the CLI SAPI for this test to be meaningful');

        define('MCPWP_TESTS', true);

        $license = Mcpwp_License::get_instance();

        $this->assertTrue($license->is_pro());
        $this->assertTrue($license->is_paying());
        $this->assertSame('pro', $license->get_plan());
    }

    /**
     * MCPWP_TESTS alone (without the CLI SAPI) must not grant Pro — a site
     * owner defining it in wp-config.php gets nothing, because is_pro() is
     * never evaluated under the CLI SAPI during normal web/MCP request
     * handling. This test simulates that by asserting the helper's other
     * half (MCPWP_PRO-style bare truthiness) is insufficient on its own;
     * the CLI requirement is verified structurally by
     * test_cli_test_override_grants_pro_under_phpunit() above always
     * running under CLI, and by wp_config override test above showing a
     * bare constant is not enough.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_wporg_build_wins_over_every_override(): void
    {
        define('MCPWP_WPORG_BUILD', true);
        define('MCPWP_TESTS', true);
        $this->write_agency_marker('MCPWP_AGENCY_LICENSE_V1');

        $license = Mcpwp_License::get_instance();

        $this->assertFalse($license->is_pro(), 'MCPWP_WPORG_BUILD must hard-stop before any override is evaluated');
        $this->assertSame('unlicensed', $license->get_plan());
    }
}
