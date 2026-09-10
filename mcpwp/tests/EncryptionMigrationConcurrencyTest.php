<?php

use PHPUnit\Framework\TestCase;

/**
 * #564 review r1: pin the migration's concurrency guards and the key-row probe.
 *
 *   M2   an expired lock is taken over by compare-and-swap: two takeovers, one runs.
 *   M3   release is compare-and-delete: it never removes a lock someone else holds.
 *   M4   the read-back verifies under the key the site stores now, not a cached one.
 *   M9   a key read that fails mid-run is not recorded as a blob failure.
 *   M12  under the lock, a flag stored by the previous holder means: write nothing.
 *   P3-3 a provider saved or removed during the run is not reverted by it.
 *   P3-5 a rotated-salt upgrade with no key row raises no "key missing" alert.
 *   P3-6 the row probe reads the first inserted row even without a UNIQUE index.
 *
 * Review r2:
 *   P3-A a failed read of the provider records is never "empty"; the write is a
 *        compare-and-swap, so a change after the re-read is not reverted.
 *   M16  failures drop providers removed while the run was in flight.
 */
final class EncryptionMigrationConcurrencyTest extends TestCase
{
    private const KEY_OPTION    = 'mcpwp_encryption_key';
    private const LOCK_OPTION   = 'mcpwp_encryption_migration_lock';
    private const FLAG_OPTION   = 'mcpwp_encryption_migrated';
    private const ALERTS_OPTION = 'mcpwp_encryption_alerts';

    protected function setUp(): void
    {
        if (! Mcpwp_Encryption::is_available()) {
            $this->markTestSkipped('sodium extension not available');
        }
        if (defined('MCPWP_ENCRYPTION_KEY')) {
            $this->markTestSkipped('MCPWP_ENCRYPTION_KEY is defined in this process.');
        }

        $this->resetState();
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

        foreach (array(self::KEY_OPTION, self::LOCK_OPTION, self::FLAG_OPTION) as $name) {
            unset($GLOBALS['mcpwp_test_wpdb_options'][ $name ]);
        }

        $this->restartEncryption();
    }

    private function restartEncryption(): Mcpwp_Encryption
    {
        $ref = new ReflectionProperty(Mcpwp_Encryption::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);

        return Mcpwp_Encryption::get_instance();
    }

    private function legacyBlob(string $plaintext, string $key): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $key));
    }

    private function authSaltBlob(string $plaintext): string
    {
        return $this->legacyBlob($plaintext, Mcpwp_Encryption::get_instance()->get_legacy_auth_salt_key());
    }

    private function record(string $blob): array
    {
        return array(
            'key_encrypted' => $blob,
            'configured_at' => '2026-01-01 00:00:00',
            'last_tested'   => null,
            'test_status'   => null,
        );
    }

    private function seedIntegrations(array $blobs): void
    {
        update_option(Mcpwp_Integration_Manager::OPTION_NAME, array_map(array($this, 'record'), $blobs));
    }

    private function integrations(): array
    {
        return (array) get_option(Mcpwp_Integration_Manager::OPTION_NAME, array());
    }

    /**
     * Arms a before-update hook that fires $then when the row $name is about
     * to be updated, re-arming itself past updates of other rows.
     */
    private function beforeUpdateOf(string $name, callable $then): void
    {
        $hook = null;
        $hook = function (string $updated) use (&$hook, $name, $then): void {
            if ($name !== $updated) {
                $GLOBALS['mcpwp_test_wpdb_before_update'] = $hook;
                return;
            }
            $then();
        };
        $GLOBALS['mcpwp_test_wpdb_before_update'] = $hook;
    }

    // ── M2 ───────────────────────────────────────────────────────────────

    public function test_two_takeovers_of_one_stale_lock_let_only_one_run(): void
    {
        $blob = $this->authSaltBlob('sk-legacy');
        $this->seedIntegrations(array('openai' => $blob));
        $GLOBALS['mcpwp_test_wpdb_options'][ self::LOCK_OPTION ] = (time() - 5) . '|crashed-request';

        // Between this request's read of the stale lock and its takeover, another request takes it over.
        $winner = (time() + 100) . '|the-other-request';
        $GLOBALS['mcpwp_test_wpdb_before_update'] = function (string $name) use ($winner): void {
            if (self::LOCK_OPTION === $name) {
                $GLOBALS['mcpwp_test_wpdb_options'][ self::LOCK_OPTION ] = $winner;
            }
        };

        Mcpwp_Encryption_Migration::run();

        $this->assertFalse(get_option(self::FLAG_OPTION), 'the loser of the takeover must not run');
        $this->assertSame($blob, $this->integrations()['openai']['key_encrypted']);
        $this->assertSame($winner, $GLOBALS['mcpwp_test_wpdb_options'][ self::LOCK_OPTION ]);
        $this->assertArrayNotHasKey(self::KEY_OPTION, $GLOBALS['mcpwp_test_wpdb_options']);
    }

    // ── M3 ───────────────────────────────────────────────────────────────

    public function test_release_never_removes_a_lock_another_request_took_over(): void
    {
        $this->seedIntegrations(array('openai' => $this->authSaltBlob('sk-legacy')));

        $other = (time() + 100) . '|took-over-while-we-ran';
        $this->beforeUpdateOf(Mcpwp_Integration_Manager::OPTION_NAME, function () use ($other): void {
            $GLOBALS['mcpwp_test_wpdb_options'][ self::LOCK_OPTION ] = $other;
        });

        Mcpwp_Encryption_Migration::run();

        $this->assertSame($other, $GLOBALS['mcpwp_test_wpdb_options'][ self::LOCK_OPTION ] ?? null, 'only our own lock value may be deleted');
    }

    // ── M4 ───────────────────────────────────────────────────────────────

    public function test_verification_uses_the_key_the_site_stores_now(): void
    {
        $this->seedIntegrations(array('openai' => $this->authSaltBlob('sk-legacy')));

        // The stored key changes after this request cached its key (a restore, say).
        $this->beforeUpdateOf(Mcpwp_Integration_Manager::OPTION_NAME, function (): void {
            $GLOBALS['mcpwp_test_wpdb_options'][ self::KEY_OPTION ] = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        });

        Mcpwp_Encryption_Migration::run();

        $this->assertFalse(get_option(self::FLAG_OPTION), 'blobs the stored key cannot open must not be marked migrated');
    }

    // ── M9 ───────────────────────────────────────────────────────────────

    public function test_a_key_read_that_fails_mid_run_is_not_recorded_as_a_blob_failure(): void
    {
        $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $GLOBALS['mcpwp_test_wpdb_options'][ self::KEY_OPTION ] = base64_encode($key);
        $GLOBALS['mcpwp_test_options'][ self::KEY_OPTION ]      = base64_encode($key);

        // Written by 3.2.4 – 3.11.0: legacy format, current key.
        $blob = $this->legacyBlob('sk-current', $key);
        $this->seedIntegrations(array('openai' => $blob));

        // The first read of the key row answers; every later one fails.
        $reads = 0;
        $GLOBALS['mcpwp_test_wpdb_read_fails'] = function (string $name) use (&$reads): bool {
            if (self::KEY_OPTION !== $name) {
                return false;
            }
            $reads++;
            return $reads > 1;
        };

        Mcpwp_Encryption_Migration::run();

        $this->assertFalse(get_option(self::FLAG_OPTION));
        $this->assertFalse(get_option(Mcpwp_Encryption_Migration::FAILURES_OPTION), 'an unreadable key is not a blob failure');
        $this->assertSame($blob, $this->integrations()['openai']['key_encrypted']);
    }

    // ── M12 ──────────────────────────────────────────────────────────────

    public function test_a_run_that_finds_the_flag_already_stored_writes_nothing(): void
    {
        $blob = $this->authSaltBlob('sk-legacy');
        $this->seedIntegrations(array('openai' => $blob));

        // The previous lock holder finished; this request's option cache has not seen it.
        $GLOBALS['mcpwp_test_wpdb_options'][ self::FLAG_OPTION ] = '1';

        Mcpwp_Encryption_Migration::run();

        $this->assertSame($blob, $this->integrations()['openai']['key_encrypted']);
        $this->assertArrayNotHasKey(self::KEY_OPTION, $GLOBALS['mcpwp_test_wpdb_options'], 'no key is created by a run with nothing to do');
        $this->assertArrayNotHasKey(self::LOCK_OPTION, $GLOBALS['mcpwp_test_wpdb_options']);
    }

    // ── P3-3 ─────────────────────────────────────────────────────────────

    /**
     * Arms a before-insert hook that fires $onKeyInsert when the key row is
     * created mid-run (after the migration read the provider records),
     * re-arming itself past the lock insert.
     */
    private function duringKeyCreation(callable $onKeyInsert): void
    {
        $hook = null;
        $hook = function (string $name) use (&$hook, $onKeyInsert): void {
            if (self::KEY_OPTION !== $name) {
                $GLOBALS['mcpwp_test_wpdb_before_insert'] = $hook;
                return;
            }
            $onKeyInsert();
        };
        $GLOBALS['mcpwp_test_wpdb_before_insert'] = $hook;
    }

    public function test_a_provider_saved_during_the_run_is_not_reverted(): void
    {
        $this->seedIntegrations(array('openai' => $this->authSaltBlob('sk-legacy')));

        $this->duringKeyCreation(function (): void {
            $GLOBALS['mcpwp_test_options'][ Mcpwp_Integration_Manager::OPTION_NAME ]['gemini'] = $this->record('v2:saved-by-an-admin-meanwhile');
        });

        Mcpwp_Encryption_Migration::run();

        $data = $this->integrations();
        $this->assertArrayHasKey('gemini', $data, 'the concurrent save must survive the migration write');
        $this->assertSame('v2:saved-by-an-admin-meanwhile', $data['gemini']['key_encrypted']);
        $this->assertStringStartsWith('v2:', $data['openai']['key_encrypted']);
        $this->assertSame('sk-legacy', $this->restartEncryption()->decrypt($data['openai']['key_encrypted']));
        $this->assertNotEmpty(get_option(self::FLAG_OPTION));
    }

    public function test_a_provider_changed_or_removed_during_the_run_keeps_that_change(): void
    {
        $this->seedIntegrations(array(
            'openai' => $this->authSaltBlob('sk-openai'),
            'gemini' => $this->authSaltBlob('sk-gemini'),
            'pexels' => $this->authSaltBlob('sk-pexels'),
        ));

        $this->duringKeyCreation(function (): void {
            $GLOBALS['mcpwp_test_options'][ Mcpwp_Integration_Manager::OPTION_NAME ]['openai']['key_encrypted'] = 'v2:re-entered-meanwhile';
            unset($GLOBALS['mcpwp_test_options'][ Mcpwp_Integration_Manager::OPTION_NAME ]['gemini']);
        });

        Mcpwp_Encryption_Migration::run();

        $data = $this->integrations();
        $this->assertSame('v2:re-entered-meanwhile', $data['openai']['key_encrypted'], 'a newer value is never overwritten by the migration snapshot');
        $this->assertArrayNotHasKey('gemini', $data, 'a removed provider must not reappear');
        $this->assertSame('sk-pexels', $this->restartEncryption()->decrypt($data['pexels']['key_encrypted']));
        $this->assertNotEmpty(get_option(self::FLAG_OPTION));
    }

    // ── M16 ──────────────────────────────────────────────────────────────

    public function test_failures_drop_a_provider_removed_during_the_run(): void
    {
        $this->seedIntegrations(array(
            'openai' => $this->authSaltBlob('sk-openai'),
            'gemini' => $this->legacyBlob('rotated-salt', random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
        ));

        $this->duringKeyCreation(function (): void {
            unset($GLOBALS['mcpwp_test_options'][ Mcpwp_Integration_Manager::OPTION_NAME ]['gemini']);
        });

        Mcpwp_Encryption_Migration::run();

        $this->assertNotEmpty(get_option(self::FLAG_OPTION));
        $this->assertFalse(
            get_option(Mcpwp_Encryption_Migration::FAILURES_OPTION),
            'a provider removed meanwhile has nothing left to re-enter; the notice must not name it'
        );
    }

    // ── P3-A (review r2) ─────────────────────────────────────────────────

    public function test_a_failed_read_of_the_provider_records_is_not_an_empty_store(): void
    {
        $blob = $this->authSaltBlob('sk-legacy');
        $this->seedIntegrations(array('openai' => $blob));

        $GLOBALS['mcpwp_test_wpdb_read_fails'] = static function (string $name): bool {
            return Mcpwp_Integration_Manager::OPTION_NAME === $name;
        };

        Mcpwp_Encryption_Migration::run();

        $this->assertFalse(get_option(self::FLAG_OPTION), 'an unreadable store must not be taken for "nothing to migrate"');
        $this->assertArrayNotHasKey(self::KEY_OPTION, $GLOBALS['mcpwp_test_wpdb_options'], 'the run stops at the failed read, before any re-encryption');
        $this->assertFalse(get_option(Mcpwp_Encryption_Migration::FAILURES_OPTION));
        $this->assertSame($blob, $this->integrations()['openai']['key_encrypted']);
        $this->assertNotFalse(get_transient(Mcpwp_Encryption_Migration::RETRY_TRANSIENT), 'the run is retried later');
    }

    public function test_a_failed_re_read_before_writing_defers_the_run(): void
    {
        $blob = $this->authSaltBlob('sk-legacy');
        $this->seedIntegrations(array('openai' => $blob));

        // The first read of the provider records answers; the re-read before writing fails.
        $reads = 0;
        $GLOBALS['mcpwp_test_wpdb_read_fails'] = function (string $name) use (&$reads): bool {
            if (Mcpwp_Integration_Manager::OPTION_NAME !== $name) {
                return false;
            }
            $reads++;
            return $reads > 1;
        };

        Mcpwp_Encryption_Migration::run();

        $this->assertFalse(get_option(self::FLAG_OPTION), 'a failed re-read is not "every provider was removed"');
        $this->assertFalse(get_option(Mcpwp_Encryption_Migration::FAILURES_OPTION));
        $this->assertSame($blob, $this->integrations()['openai']['key_encrypted']);
    }

    public function test_a_change_after_the_re_read_is_not_reverted_and_the_run_retries(): void
    {
        $this->seedIntegrations(array('openai' => $this->authSaltBlob('sk-legacy')));

        // An admin saves another provider in the gap between the re-read and the write.
        $this->beforeUpdateOf(Mcpwp_Integration_Manager::OPTION_NAME, function (): void {
            $GLOBALS['mcpwp_test_options'][ Mcpwp_Integration_Manager::OPTION_NAME ]['gemini'] = $this->record('v2:saved-in-the-gap');
        });

        Mcpwp_Encryption_Migration::run();

        $data = $this->integrations();
        $this->assertSame('v2:saved-in-the-gap', $data['gemini']['key_encrypted'] ?? null, 'the compare-and-swap must miss, not overwrite');
        $this->assertFalse(Mcpwp_Encryption::is_versioned_blob($data['openai']['key_encrypted']), 'nothing was written by the run that missed');
        $this->assertFalse(get_option(self::FLAG_OPTION));

        // After the backoff the next run migrates on top of the newer records.
        delete_transient(Mcpwp_Encryption_Migration::RETRY_TRANSIENT);
        $this->restartEncryption();
        Mcpwp_Encryption_Migration::run();

        $data = $this->integrations();
        $this->assertSame('v2:saved-in-the-gap', $data['gemini']['key_encrypted']);
        $this->assertSame('sk-legacy', $this->restartEncryption()->decrypt($data['openai']['key_encrypted']));
        $this->assertNotEmpty(get_option(self::FLAG_OPTION));
    }

    // ── P3-5 ─────────────────────────────────────────────────────────────

    public function test_a_rotated_salt_upgrade_without_a_key_raises_no_key_missing_alert(): void
    {
        // A <=3.2.3 site whose AUTH_SALT was rotated: no key row ever existed.
        $this->seedIntegrations(array('gemini' => $this->legacyBlob('unreadable', random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES))));

        Mcpwp_Encryption_Migration::run();

        $this->assertSame(array('gemini'), get_option(Mcpwp_Encryption_Migration::FAILURES_OPTION));
        $this->assertNotEmpty(get_option(self::FLAG_OPTION));
        $this->assertArrayNotHasKey(
            Mcpwp_Encryption::REASON_KEY_MISSING,
            (array) get_option(self::ALERTS_OPTION, array()),
            'there is no key to restore from a backup; the failure notice already says what to do'
        );
    }

    // ── P3-6 ─────────────────────────────────────────────────────────────

    public function test_the_row_probe_reads_the_first_inserted_row(): void
    {
        $spy = new class () extends Mcpwp_Test_WPDB {
            /** @var string[] */
            public $row_queries = array();

            public function get_row($query = '', $output = OBJECT, $y = 0)
            {
                $this->row_queries[] = (string) $query;
                return parent::get_row($query, $output, $y);
            }
        };

        $previous        = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = $spy;
        try {
            $GLOBALS['mcpwp_test_wpdb_options'][ self::KEY_OPTION ] = 'stored';
            $row = Mcpwp_Encryption::read_option_row(self::KEY_OPTION);
        } finally {
            $GLOBALS['wpdb'] = $previous;
        }

        $this->assertSame(Mcpwp_Encryption::ROW_PRESENT, $row['state']);
        $this->assertCount(1, $spy->row_queries);
        $this->assertStringContainsString(
            'ORDER BY option_id ASC LIMIT 1',
            $spy->row_queries[0],
            'without the UNIQUE index a name can have two rows; the first inserted one must always win'
        );
    }
}
