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
        remove_filter('sanitize_option_' . self::OPTION, array(Mcpwp_Integration_Manager::class, 'reject_raw_option_write'));
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

        if (is_array($value)) { unset($value[Mcpwp_Integration_Manager::GENERATIONS_KEY]); }
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

    public function test_legacy_import_cannot_replace_a_concurrent_configure(): void
    {
        require_once dirname(__DIR__) . '/includes/class-mcpwp-migrate.php';
        update_option('spai_settings', array());
        update_option('spai_integrations', array('openai' => $this->record('legacy-fixture')));
        $manager = Mcpwp_Integration_Manager::get_instance();
        $fired = false;
        $configure = function () use ($manager, &$fired): void {
            if (!$fired) {
                $fired = true;
                $this->assertTrue($manager->set_provider_config('figma', array('access_token' => 'new-fixture')));
            }
        };
        $GLOBALS['mcpwp_test_option_store_hook'] = function ($name, $value) use ($configure) {
            if (self::OPTION === $name) { $configure(); }
            return $value;
        };
        $GLOBALS['mcpwp_test_wpdb_before_insert'] = function ($name) use ($configure): void {
            if (self::OPTION === $name) { $configure(); }
        };
        $this->assertTrue(Mcpwp_Migrate::run());
        $this->assertTrue($fired);
        $config = $manager->get_provider_config('figma');
        $this->assertTrue(is_array($config) && 'new-fixture' === ($config['access_token'] ?? null), 'concurrent configure wins over legacy import');
        $this->assertArrayNotHasKey('openai', $this->records());
    }

    public function test_raw_backup_replacement_cannot_replay_a_live_oauth_version(): void
    {
        $manager = Mcpwp_Integration_Manager::get_instance();
        $this->assertTrue($manager->set_provider_config('figma', array('access_token' => 'old-fixture')));
        $snapshot = $manager->get_provider_snapshot('figma');
        $backup = $this->records();
        $this->assertTrue($manager->set_provider_config('figma', array('access_token' => 'new-fixture')));
        Mcpwp_Integration_Manager::register_option_guard();
        $blocked = false;
        try {
            update_option(self::OPTION, $backup);
        } catch (RuntimeException $error) {
            $blocked = true;
        }
        $this->assertTrue($blocked, 'raw option restoration is refused; use the CAS restore API');
        $this->assertFalse($manager->set_provider_config('figma', array('access_token' => 'late-fixture'), $snapshot), 'backup replacement must not make an old snapshot eligible');
    }

    public function test_explicit_restore_mints_an_incarnation_and_advances_the_live_counter(): void
    {
        $manager = Mcpwp_Integration_Manager::get_instance();
        $this->assertTrue($manager->set_provider_config('figma', array('access_token' => 'old-fixture')));
        $snapshot = $manager->get_provider_snapshot('figma');
        $backup = $this->records();
        $this->assertTrue($manager->set_provider_config('figma', array('access_token' => 'new-fixture')));
        $live = $manager->get_provider_snapshot('figma');
        $this->assertTrue($manager->restore_provider_records($backup));
        $restored = $manager->get_provider_snapshot('figma');
        $this->assertGreaterThan($live['generation'], $restored['generation']);
        $this->assertNotSame($snapshot['record_id'], $restored['record_id']);
        $this->assertFalse($manager->set_provider_config('figma', array('access_token' => 'late-fixture'), $snapshot));
        $this->assertTrue('old-fixture' === $manager->get_provider_config('figma')['access_token']);
    }

    public function test_restore_after_removal_keeps_the_live_high_water_counter(): void
    {
        $manager = Mcpwp_Integration_Manager::get_instance();
        $this->assertTrue($manager->set_provider_config('figma', array('access_token' => 'old-fixture')));
        $backup = $this->records();
        $this->assertTrue($manager->set_provider_config('figma', array('access_token' => 'new-fixture')));
        $live = $manager->get_provider_snapshot('figma');
        $this->assertSame(Mcpwp_Integration_Manager::WRITE_STORED, $manager->remove_provider_record('figma'));
        $backup[Mcpwp_Integration_Manager::GENERATIONS_KEY] = array('figma' => PHP_INT_MAX);
        $this->assertTrue($manager->restore_provider_records($backup));
        $restored = $manager->get_provider_snapshot('figma');
        $this->assertSame($live['generation'] + 2, $restored['generation'], 'removal and restore both advance the live counter; backup map is ignored');
        $this->assertNotSame($live['record_id'], $restored['record_id']);
    }

    public function test_raw_option_add_cannot_recreate_a_saved_snapshot(): void
    {
        $manager = Mcpwp_Integration_Manager::get_instance();
        $this->assertTrue($manager->set_provider_config('figma', array('access_token' => 'old-fixture')));
        $snapshot = $manager->get_provider_snapshot('figma');
        $backup = $this->records();
        unset($GLOBALS['mcpwp_test_wpdb_options'][self::OPTION], $GLOBALS['mcpwp_test_options'][self::OPTION]);
        Mcpwp_Integration_Manager::register_option_guard();
        $blocked = false;
        try {
            add_option(self::OPTION, $backup);
        } catch (RuntimeException $error) {
            $blocked = true;
        }
        $this->assertTrue($blocked);
        $this->assertFalse($manager->set_provider_config('figma', array('access_token' => 'late-fixture'), $snapshot));
    }

    public function test_action_log_provider_restore_uses_the_versioned_restore_api(): void
    {
        require_once dirname(__DIR__) . '/includes/core/class-mcpwp-action-log.php';
        $manager = Mcpwp_Integration_Manager::get_instance();
        $this->assertTrue($manager->set_provider_config('figma', array('access_token' => 'old-fixture')));
        $backup = $this->records();
        $snapshot = $manager->get_provider_snapshot('figma');
        Mcpwp_Integration_Manager::register_option_guard();
        $apply = new ReflectionMethod(Mcpwp_Action_Log::class, 'apply_snapshot');
        $apply->setAccessible(true);
        $this->assertTrue($apply->invoke(null, 'option', array('option_key' => self::OPTION, 'value' => $backup)));
        $after = $manager->get_provider_snapshot('figma');
        $this->assertSame($snapshot['generation'] + 1, $after['generation']);
        $this->assertNotSame($snapshot['record_id'], $after['record_id']);
    }

    /** @dataProvider versionConflictCases */
    public function test_version_is_rechecked_inside_every_cas_attempt(string $mutation): void
    {
        $manager = Mcpwp_Integration_Manager::get_instance();
        $this->assertTrue($manager->set_provider_config('figma', array('access_token' => 'initial-fixture')));
        $snapshot = $manager->get_provider_snapshot('figma');
        $this->assertSame(1, $snapshot['generation']);
        $this->assertNotSame('', $snapshot['record_id']);
        $this->between_read_and_write(function () use ($manager, $mutation): void {
            if ('unrelated' === $mutation) {
                $this->assertTrue($manager->set_provider_key('openai', 'other-fixture'));
            } elseif ('status' === $mutation) {
                $status = new ReflectionMethod(Mcpwp_Integration_Manager::class, 'record_test_status');
                $status->setAccessible(true);
                $this->assertTrue($status->invoke($manager, 'figma', true));
            } elseif ('remove' === $mutation || 'recreate' === $mutation) {
                $this->assertSame(Mcpwp_Integration_Manager::WRITE_STORED, $manager->remove_provider_record('figma'));
                if ('recreate' === $mutation) {
                    $this->assertTrue($manager->set_provider_config('figma', array('access_token' => 'recreated-fixture')));
                }
            } else {
                $this->assertTrue($manager->set_provider_config('figma', array('access_token' => 'rotated-fixture')));
            }
        });
        $saved = $manager->set_provider_config('figma', array('access_token' => 'late-fixture'), $snapshot);
        if ('unrelated' === $mutation) {
            $this->assertTrue($saved, 'unrelated writes may retry');
            $after = $manager->get_provider_snapshot('figma');
            $this->assertSame(2, $after['generation']);
            $this->assertSame($snapshot['record_id'], $after['record_id']);
            $this->assertTrue($manager->has_provider_key('openai'));
        } else {
            $this->assertFalse($saved, 'same-provider changes invalidate the captured version');
            $this->assertSame('generation_conflict', $manager->get_last_save_error());
            $after = $manager->get_provider_snapshot('figma');
            if ('remove' === $mutation) {
                $this->assertFalse($after);
            } else {
                $expected = 'recreate' === $mutation ? 'recreated-fixture' : ('status' === $mutation ? 'initial-fixture' : 'rotated-fixture');
                $this->assertTrue($expected === $after['config']['access_token'], 'late credentials never land');
                if ('recreate' === $mutation) {
                    $this->assertGreaterThan($snapshot['generation'], $after['generation'], 'deletion retains a counter high-water mark');
                    $this->assertNotSame($snapshot['record_id'], $after['record_id']);
                }
            }
        }
    }

    public function versionConflictCases(): array
    {
        return array(array('rotation'), array('remove'), array('recreate'), array('unrelated'), array('status'));
    }

    public function test_legacy_generation_zero_and_key_writes_use_the_same_version_rules(): void
    {
        $manager = Mcpwp_Integration_Manager::get_instance();
        $blob = Mcpwp_Encryption::get_instance()->encrypt(wp_json_encode(array('access_token' => 'legacy-fixture')));
        $this->seed(array('figma' => $this->record($blob) + array('multi_field' => true)));
        $snapshot = $manager->get_provider_snapshot('figma');
        $this->assertSame(0, $snapshot['generation']);
        $this->assertTrue($manager->set_provider_config('figma', array('access_token' => 'new-fixture'), $snapshot));
        $after = $manager->get_provider_snapshot('figma');
        $this->assertSame(1, $after['generation']);
        $this->assertNotSame('', $after['record_id']);
        $this->assertFalse($manager->set_provider_config('figma', array('access_token' => 'late-fixture'), $snapshot));
        $this->assertTrue($manager->set_provider_key('openai', 'first-fixture'));
        $key = $manager->get_provider_snapshot('openai');
        $this->assertTrue($manager->set_provider_key('openai', 'second-fixture'));
        $next = $manager->get_provider_snapshot('openai');
        $this->assertSame($key['generation'] + 1, $next['generation']);
        $this->assertSame($key['record_id'], $next['record_id']);
    }

    public function test_a_removed_legacy_generation_zero_cannot_be_recreated_by_completion(): void
    {
        $manager = Mcpwp_Integration_Manager::get_instance();
        $blob = Mcpwp_Encryption::get_instance()->encrypt(wp_json_encode(array('access_token' => 'legacy-fixture')));
        $this->seed(array('figma' => $this->record($blob) + array('multi_field' => true)));
        $snapshot = $manager->get_provider_snapshot('figma');
        $this->assertSame(0, $snapshot['generation']);
        $this->assertSame('', $snapshot['record_id']);
        $this->assertSame(Mcpwp_Integration_Manager::WRITE_STORED, $manager->remove_provider_record('figma'));
        $this->assertFalse($manager->set_provider_config('figma', array('access_token' => 'late-fixture'), $snapshot));
        $this->assertSame('generation_conflict', $manager->get_last_save_error());
        $this->assertFalse($manager->has_provider_key('figma'));
    }

    /** #1004: a key-only write must never replace a structured record. */
    public function test_key_only_writes_refuse_every_multi_field_provider(): void
    {
        $manager = Mcpwp_Integration_Manager::get_instance();
        foreach (Mcpwp_Integration_Manager::PROVIDERS as $provider => $definition) {
            if (!isset($definition['fields'])) {
                continue;
            }
            $this->assertTrue($manager->set_provider_config($provider, array('token' => 'fixture-token')));
            $before = $this->records();
            $this->assertFalse($manager->set_provider_key($provider, 'replacement'), $provider);
            $this->assertSame('invalid_config', $manager->get_last_save_error());
            $this->assertTrue($before === $this->records(), 'refused write leaves ciphertext unchanged');
        }
    }

    public function test_partial_config_saves_preserve_omitted_fields_and_allow_explicit_clearing(): void
    {
        $manager = Mcpwp_Integration_Manager::get_instance();
        $this->assertTrue($manager->set_provider_config('screenshot', array('url' => 'https://worker.example', 'token' => 'fixture-token')));
        $this->assertTrue($manager->set_provider_config('screenshot', array('url' => 'https://new.example')));
        $config = $manager->get_provider_config('screenshot');
        $this->assertTrue('fixture-token' === ($config['token'] ?? null), 'omitted token survives');
        $this->assertSame('https://new.example', $config['url']);
        $this->assertTrue($manager->set_provider_config('screenshot', array('token' => '')));
        $config = $manager->get_provider_config('screenshot');
        $this->assertSame('', $config['token']);
        $this->assertSame('https://new.example', $config['url']);
    }

    public function test_partial_config_merge_is_reapplied_after_a_conflict(): void
    {
        $manager = Mcpwp_Integration_Manager::get_instance();
        $this->assertTrue($manager->set_provider_config('screenshot', array('url' => 'https://old.example', 'token' => 'old-fixture')));
        $this->between_read_and_write(function () use ($manager): void {
            $this->assertTrue($manager->set_provider_config('screenshot', array('token' => 'new-fixture')));
        });
        $this->assertTrue($manager->set_provider_config('screenshot', array('url' => 'https://new.example')));
        $config = $manager->get_provider_config('screenshot');
        $this->assertTrue('new-fixture' === ($config['token'] ?? null), 'concurrent field update survives retry');
        $this->assertSame('https://new.example', $config['url']);
    }

    public function test_single_field_provider_config_keeps_its_existing_save_behavior(): void
    {
        $manager = Mcpwp_Integration_Manager::get_instance();
        $this->assertTrue($manager->set_provider_key('gemini', 'old-fixture'));
        $this->assertTrue($manager->set_provider_config('gemini', array('api_key' => 'new-fixture')));
        $config = $manager->get_provider_config('gemini');
        $this->assertTrue('new-fixture' === ($config['api_key'] ?? null), 'single-field config saves remain supported');
    }

    public function test_partial_jev_config_is_validated_after_merging_and_stays_encrypted(): void
    {
        $manager = Mcpwp_Integration_Manager::get_instance();
        $this->assertTrue($manager->set_provider_config('jev', array('api_key' => 'jev-fixture-token', 'transport' => 'off')));
        $this->assertTrue($manager->set_provider_config('jev', array('endpoint' => 'https://jev.example')));
        $config = $manager->get_provider_config('jev');
        $this->assertSame('off', $config['transport']);
        $this->assertTrue('jev-fixture-token' === ($config['api_key'] ?? null), 'omitted Jev key survives');
        $before = $this->records();
        $this->assertFalse(str_contains(serialize($before), 'jev-fixture-token'), 'stored record contains no plaintext key');
        $this->assertFalse($manager->set_provider_config('jev', array('transport' => 'invalid')));
        $this->assertSame('invalid_config', $manager->get_last_save_error());
        $this->assertTrue($before === $this->records(), 'validation failure preserves ciphertext');
    }

    public function test_partial_config_refuses_unreadable_stored_ciphertext(): void
    {
        $manager = Mcpwp_Integration_Manager::get_instance();
        $this->seed(array('screenshot' => $this->record('v2:invalid-ciphertext') + array('multi_field' => true)));
        $before = $this->records();
        $this->assertFalse($manager->set_provider_config('screenshot', array('url' => 'https://new.example')));
        $this->assertTrue($before === $this->records(), 'unreadable credentials are never discarded');
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

    /**
     * Review round 2, P3: WHERE option_value = %s compares with the column's
     * collation, which is case-insensitive and ignores trailing spaces on
     * WordPress tables. A concurrent change that differs from the value this
     * save read only in letter case matched it, and the stale copy overwrote
     * the change. The swap must compare bytes.
     */
    public function test_a_concurrent_change_that_differs_only_in_case_is_not_overwritten(): void
    {
        $this->seed(array('openai' => $this->record('v2:keyblob-abc')));

        $writes = 0;
        $GLOBALS['mcpwp_test_wpdb_update_fails'] = function (string $name) use (&$writes): bool {
            if (self::OPTION === $name) {
                $writes++;
            }
            return false;
        };
        $this->between_read_and_write(function (): void {
            $GLOBALS['mcpwp_test_options'][ self::OPTION ]['openai']['key_encrypted'] = 'v2:KEYBLOB-ABC';
        });

        $this->assertTrue(Mcpwp_Integration_Manager::get_instance()->set_provider_key('gemini', 'g-key'));

        $records = $this->records();
        $this->assertSame('v2:KEYBLOB-ABC', $records['openai']['key_encrypted'], 'the concurrent change survived a case-insensitive column compare');
        $this->assertArrayHasKey('gemini', $records);
        $this->assertSame(2, $writes, 'the first swap missed on bytes and the retry landed');
    }

    /**
     * Review round 2, P3: provider records are written behind the options
     * API, so the cached copies — the option itself and the autoloaded
     * alloptions entry — must be dropped, or get_option() keeps answering the
     * records from before the write. Removing that invalidation passed every
     * test, because nothing could observe it.
     */
    public function test_a_raw_write_drops_the_cached_copies_of_the_records(): void
    {
        foreach (array('existing row' => true, 'first save' => false) as $label => $row_exists) {
            $this->resetState();
            $this->assertNotFalse(Mcpwp_Encryption::get_instance()->encrypt('warm-up'));
            if ($row_exists) {
                $this->seed(array('pexels' => $this->record('v2:pexels-blob')));
            }

            $GLOBALS['mcpwp_test_object_cache'] = array();
            $this->assertTrue(wp_cache_add(self::OPTION, array('stale' => $this->record('v2:stale')), 'options'));
            $this->assertTrue(wp_cache_add('alloptions', array(self::OPTION => 'stale'), 'options'));

            $this->assertTrue(Mcpwp_Integration_Manager::get_instance()->set_provider_key('openai', 'sk-key'), $label);

            $this->assertFalse(wp_cache_get(self::OPTION, 'options'), "{$label}: the cached option is dropped");
            $this->assertFalse(wp_cache_get('alloptions', 'options'), "{$label}: the cached autoloaded options are dropped");
        }
    }

    private function sorted_keys(array $records): array
    {
        $keys = array_keys($records);
        sort($keys);
        return $keys;
    }
}
