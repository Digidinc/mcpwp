<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/core/class-mcpwp-figma.php';

/**
 * #845 (from the #564 review, P3-B): provider saves wrote back a stale copy
 * of the whole providers array.
 *
 * set_provider_key(), set_provider_config(), remove_provider_key() and every
 * test-status write read `mcpwp_integrations`, changed one provider, and wrote
 * the whole array back with update_option(). Two saves of different providers
 * that overlapped — or a save that overlapped the encryption migration's own
 * compare-and-swap — kept only the last writer's copy, so one provider's key
 * was silently dropped while both calls reported success.
 *
 * What this pins:
 *   - two interleaved saves of different providers both survive;
 *   - a save whose row changed between its read and its write re-reads and
 *     retries, keeping the other change;
 *   - a conflict that does not clear fails visibly and writes nothing;
 *   - a test-status write neither drops a concurrent save nor resurrects a
 *     provider removed meanwhile, and a lost one is reported;
 *   - the first save of all races the same way when the row does not exist.
 */
final class IntegrationManagerConcurrentSaveTest extends TestCase
{
    private const OPTION = 'mcpwp_integrations';

    protected function setUp(): void
    {
        if (! Mcpwp_Encryption::is_available()) {
            $this->markTestSkipped('sodium extension not available');
        }
        if (defined('MCPWP_ENCRYPTION_KEY') || defined('MCPWP_PRO') || defined('MCPWP_WPORG_BUILD')) {
            $this->markTestSkipped('A build-level constant is defined in this process.');
        }

        $this->resetState();

        // Resolve (and create) the key now, so no hook below ever sees the key
        // row's insert.
        $this->assertNotFalse(Mcpwp_Encryption::get_instance()->encrypt('warm-up'));
    }

    protected function tearDown(): void
    {
        $this->resetState();
    }

    private function resetState(): void
    {
        $GLOBALS['mcpwp_test_options']            = array();
        $GLOBALS['mcpwp_test_transients']         = array();
        $GLOBALS['mcpwp_test_update_option_fail'] = array();
        $GLOBALS['mcpwp_test_wpdb_read_fails']    = false;
        $GLOBALS['mcpwp_test_wpdb_insert_fails']  = false;
        $GLOBALS['mcpwp_test_wpdb_before_insert'] = null;
        $GLOBALS['mcpwp_test_wpdb_before_update'] = null;
        $GLOBALS['mcpwp_test_wpdb_after_update']  = null;
        $GLOBALS['mcpwp_test_wpdb_update_fails']  = false;
        $GLOBALS['mcpwp_test_option_store_hook']  = null;
        $GLOBALS['mcpwp_test_fs']                 = null;

        foreach (array('mcpwp_encryption_key', self::OPTION) as $name) {
            unset($GLOBALS['mcpwp_test_wpdb_options'][ $name ]);
        }

        $ref = new ReflectionProperty(Mcpwp_Encryption::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);

        $license = new ReflectionProperty(Mcpwp_License::class, 'instance');
        $license->setAccessible(true);
        $license->setValue(null, null);
    }

    private function record(string $blob): array
    {
        return array(
            'key_encrypted' => $blob,
            'configured_at' => '2026-09-01 00:00:00',
            'last_tested'   => null,
            'test_status'   => null,
        );
    }

    private function seed(array $records): void
    {
        $GLOBALS['mcpwp_test_options'][ self::OPTION ] = $records;
    }

    /**
     * The stored records, read the way the options table holds them: a raw
     * row when one was inserted behind the options API, else the option.
     */
    private function records(): array
    {
        if (array_key_exists(self::OPTION, $GLOBALS['mcpwp_test_wpdb_options'] ?? array())) {
            $value = maybe_unserialize($GLOBALS['mcpwp_test_wpdb_options'][ self::OPTION ]);
        } else {
            $value = $GLOBALS['mcpwp_test_options'][ self::OPTION ] ?? array();
        }

        return is_array($value) ? $value : array();
    }

    /**
     * Run $concurrent exactly once, between this call's read of the records
     * and its write, whichever way the write is made: through update_option()
     * or through a compare-and-swap on the row.
     */
    private function between_read_and_write(callable $concurrent): void
    {
        $fired = false;
        $once  = function () use (&$fired, $concurrent): void {
            if ($fired) {
                return;
            }
            $fired = true;
            $concurrent();
        };

        $GLOBALS['mcpwp_test_option_store_hook'] = function (string $name, $value) use ($once) {
            if (self::OPTION === $name) {
                $once();
            }
            return $value;
        };
        $GLOBALS['mcpwp_test_wpdb_before_update'] = function (string $name) use ($once): void {
            if (self::OPTION === $name) {
                $once();
            }
        };
        $GLOBALS['mcpwp_test_wpdb_before_insert'] = function (string $name) use ($once): void {
            if (self::OPTION === $name) {
                $once();
            }
        };
    }

    public function test_two_interleaved_saves_of_different_providers_both_survive(): void
    {
        $this->seed(array('pexels' => $this->record('v2:pexels-blob')));
        $manager = Mcpwp_Integration_Manager::get_instance();

        $inner = null;
        $this->between_read_and_write(function () use ($manager, &$inner): void {
            $inner = $manager->set_provider_key('gemini', 'g-key');
        });

        $outer = $manager->set_provider_key('openai', 'sk-key');

        $this->assertTrue($inner, 'the interleaved save reports success');
        $this->assertTrue($outer, 'the outer save reports success');

        $records = $this->records();
        $this->assertSame(array('gemini', 'openai', 'pexels'), $this->sorted_keys($records), 'neither save dropped the other');
        $this->assertSame('g-key', $manager->get_provider_key('gemini'));
        $this->assertSame('sk-key', $manager->get_provider_key('openai'));
        $this->assertSame('v2:pexels-blob', $records['pexels']['key_encrypted']);
    }

    public function test_a_save_whose_row_changed_since_its_read_retries_and_keeps_the_change(): void
    {
        $this->seed(array(
            'openai' => $this->record('v2:openai-blob'),
        ));

        $writes = 0;
        $GLOBALS['mcpwp_test_wpdb_update_fails'] = function (string $name) use (&$writes): bool {
            if (self::OPTION === $name) {
                $writes++;
            }
            return false;
        };

        // A test-status write lands between this save's read and its write.
        $this->between_read_and_write(function (): void {
            $GLOBALS['mcpwp_test_options'][ self::OPTION ]['openai']['test_status'] = 'ok';
            $GLOBALS['mcpwp_test_options'][ self::OPTION ]['openai']['last_tested'] = '2026-09-10 12:00:00';
        });

        $this->assertTrue(Mcpwp_Integration_Manager::get_instance()->set_provider_config('screenshot', array('url' => 'https://worker.example', 'token' => 't')));

        $records = $this->records();
        $this->assertSame('ok', $records['openai']['test_status'], 'the change made after the read was not reverted');
        $this->assertArrayHasKey('screenshot', $records);
        $this->assertSame(2, $writes, 'the first compare-and-swap missed and the retry landed');
    }

    public function test_a_conflict_that_never_clears_fails_visibly_and_writes_nothing(): void
    {
        $this->seed(array('pexels' => $this->record('v2:pexels-blob')));

        $writes = 0;
        $GLOBALS['mcpwp_test_wpdb_update_fails'] = function (string $name) use (&$writes): bool {
            if (self::OPTION === $name) {
                $writes++;
            }
            return false;
        };

        // Another writer changes the row before every attempt, forever.
        $bump = null;
        $bump = function (string $name = self::OPTION, $value = null) use (&$bump) {
            if (self::OPTION === $name) {
                $GLOBALS['mcpwp_test_options'][ self::OPTION ]['pexels']['configured_at'] = uniqid('', true);
            }
            $GLOBALS['mcpwp_test_wpdb_before_update'] = $bump;
            return $value;
        };
        $GLOBALS['mcpwp_test_wpdb_before_update'] = $bump;
        $GLOBALS['mcpwp_test_option_store_hook']  = function (string $name, $value) {
            return self::OPTION === $name ? null : $value; // the options-API write is refused just the same
        };

        $manager = Mcpwp_Integration_Manager::get_instance();

        $this->assertFalse($manager->set_provider_key('gemini', 'g-key'), 'a lost write is never reported as saved');
        $this->assertArrayNotHasKey('gemini', $this->records());
        $this->assertGreaterThan(1, $writes, 'it retried');
        $this->assertLessThanOrEqual(10, $writes, 'a bounded number of times');

        $this->assertFalse($manager->remove_provider_key('pexels'), 'a lost removal is not reported as done either');
        $this->assertArrayHasKey('pexels', $this->records());
    }

    public function test_a_test_status_write_keeps_concurrent_saves_and_never_resurrects_a_removed_provider(): void
    {
        $manager = Mcpwp_Integration_Manager::get_instance();

        // Figma on Free answers "Pro required" without a network call and
        // records the failed test on the provider's record.
        $this->seed(array(
            'figma'  => $this->record('v2:figma-blob'),
            'pexels' => $this->record('v2:pexels-blob'),
        ));
        $this->between_read_and_write(function (): void {
            $GLOBALS['mcpwp_test_options'][ self::OPTION ]['gemini'] = $this->record('v2:saved-meanwhile');
        });

        $result = $manager->test_provider('figma');

        $this->assertFalse($result['success']);
        $records = $this->records();
        $this->assertArrayHasKey('gemini', $records, 'the status write did not drop a provider saved meanwhile');
        $this->assertSame('failed', $records['figma']['test_status']);
        $this->assertTrue($result['test_status_saved'] ?? null, 'the recorded status is reported as recorded');

        // A provider removed while its test ran stays removed.
        $this->seed(array(
            'figma'  => $this->record('v2:figma-blob'),
            'pexels' => $this->record('v2:pexels-blob'),
        ));
        $this->between_read_and_write(function (): void {
            unset($GLOBALS['mcpwp_test_options'][ self::OPTION ]['figma']);
        });

        $manager->test_provider('figma');

        $this->assertArrayNotHasKey('figma', $this->records(), 'a stale copy must not bring a removed provider back');
    }

    public function test_a_lost_test_status_write_is_reported(): void
    {
        $this->seed(array('figma' => $this->record('v2:figma-blob')));

        $bump = null;
        $bump = function (string $name) use (&$bump): void {
            if (self::OPTION === $name) {
                $GLOBALS['mcpwp_test_options'][ self::OPTION ]['figma']['configured_at'] = uniqid('', true);
            }
            $GLOBALS['mcpwp_test_wpdb_before_update'] = $bump;
        };
        $GLOBALS['mcpwp_test_wpdb_before_update'] = $bump;
        $GLOBALS['mcpwp_test_update_option_fail'] = array(self::OPTION => true);

        $result = Mcpwp_Integration_Manager::get_instance()->test_provider('figma');

        $this->assertFalse($result['test_status_saved'] ?? null, 'the test ran; its recorded status did not, and the result says so');
        $this->assertNull($this->records()['figma']['test_status']);
    }

    public function test_the_first_save_races_a_concurrent_first_save_without_losing_either(): void
    {
        $this->assertSame(array(), $this->records(), 'no row yet');
        $manager = Mcpwp_Integration_Manager::get_instance();

        $inner = null;
        $this->between_read_and_write(function () use ($manager, &$inner): void {
            $inner = $manager->set_provider_key('gemini', 'g-key');
        });

        $this->assertTrue($manager->set_provider_key('openai', 'sk-key'));
        $this->assertTrue($inner);
        $this->assertSame(array('gemini', 'openai'), $this->sorted_keys($this->records()));
    }

    private function sorted_keys(array $records): array
    {
        $keys = array_keys($records);
        sort($keys);
        return $keys;
    }
}
