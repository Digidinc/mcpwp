<?php

use PHPUnit\Framework\TestCase;

/**
 * #564 residuals on the dedicated encryption key.
 *
 *   P1-B  A key is never generated while a key row exists, and never written
 *         over one. "Could not read the key" never behaves like "no key".
 *   P2-C  Concurrent first creation converges on one stored key; the
 *         AUTH_SALT migration runs under a lock and sets its flag only after
 *         the write verifies.
 *   P2-D  New blobs carry a version and a key fingerprint; wrong key and
 *         tampering are reported apart; legacy blobs keep decrypting.
 *   P3-8  A provider's migration failure clears when its key is re-entered.
 *
 * The key row lives in the $wpdb double's option rows
 * ($GLOBALS['mcpwp_test_wpdb_options']), because the key is read straight
 * from the options table rather than through get_option(). Where a test also
 * seeds $GLOBALS['mcpwp_test_options'] it does so to model a site where both
 * the cache and the table agree — so the unpatched code, which read only
 * get_option(), is judged on its behaviour and not on the double.
 */
final class EncryptionKeyIntegrityTest extends TestCase
{
    private const KEY_OPTION    = 'mcpwp_encryption_key';
    private const LOCK_OPTION   = 'mcpwp_encryption_migration_lock';
    private const ALERTS_OPTION = 'mcpwp_encryption_alerts';

    protected function setUp(): void
    {
        if (! Mcpwp_Encryption::is_available()) {
            $this->markTestSkipped('sodium extension not available');
        }
        if (defined('MCPWP_ENCRYPTION_KEY')) {
            $this->markTestSkipped('MCPWP_ENCRYPTION_KEY is defined in this process; these tests cover the option key.');
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
        $GLOBALS['mcpwp_test_option_store_hook']  = null;
        $GLOBALS['mcpwp_test_wpdb_update_fails']  = false;
        $GLOBALS['mcpwp_test_wpdb_before_update'] = null;
        $GLOBALS['mcpwp_test_wpdb_after_update']  = null;

        foreach (array(self::KEY_OPTION, self::LOCK_OPTION) as $name) {
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

    private function seedKeyRow(string $raw, bool $alsoInOptionCache = false): void
    {
        $GLOBALS['mcpwp_test_wpdb_options'][ self::KEY_OPTION ] = base64_encode($raw);
        if ($alsoInOptionCache) {
            $GLOBALS['mcpwp_test_options'][ self::KEY_OPTION ] = base64_encode($raw);
        }
    }

    private function seedRawKeyValue(string $value): void
    {
        $GLOBALS['mcpwp_test_wpdb_options'][ self::KEY_OPTION ] = $value;
        $GLOBALS['mcpwp_test_options'][ self::KEY_OPTION ]      = $value;
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

    private function seedIntegrations(array $blobs): void
    {
        $data = array();
        foreach ($blobs as $provider => $blob) {
            $data[ $provider ] = array(
                'key_encrypted' => $blob,
                'configured_at' => '2026-01-01 00:00:00',
                'last_tested'   => null,
                'test_status'   => null,
            );
        }
        update_option(Mcpwp_Integration_Manager::OPTION_NAME, $data);
    }

    // ── P1-B: a failed read never regenerates ─────────────────────────────

    public function test_failed_key_read_does_not_generate_or_overwrite_a_key(): void
    {
        $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $this->seedKeyRow($key);
        $blob = $this->legacyBlob('provider-secret', $key);

        // The row exists; the read of it fails (timeout, lost connection).
        $GLOBALS['mcpwp_test_wpdb_read_fails'] = true;

        $enc = Mcpwp_Encryption::get_instance();
        $this->assertFalse($enc->encrypt('new-secret'), 'encrypt must fail closed when the key cannot be read');
        $this->assertSame(Mcpwp_Encryption::REASON_KEY_UNAVAILABLE, $enc->get_last_error());

        $this->assertFalse($enc->decrypt($blob));
        $this->assertSame(Mcpwp_Encryption::REASON_KEY_UNAVAILABLE, $enc->get_last_error());

        $this->assertSame(base64_encode($key), $GLOBALS['mcpwp_test_wpdb_options'][ self::KEY_OPTION ], 'the stored key must be untouched');
        $this->assertArrayNotHasKey(self::KEY_OPTION, $GLOBALS['mcpwp_test_options'], 'no replacement key may be written through the options API');

        // Once the database answers again, the original key is still there and still works.
        $GLOBALS['mcpwp_test_wpdb_read_fails'] = false;
        $this->assertSame('provider-secret', $this->restartEncryption()->decrypt($blob));
    }

    public function test_a_read_that_returns_nothing_without_an_error_is_still_a_failure(): void
    {
        // wpdb returns null with an empty last_error when a reconnect fails
        // after template_redirect. That must not read as "no key yet".
        $GLOBALS['mcpwp_test_wpdb_read_fails'] = 'silent';

        $enc = Mcpwp_Encryption::get_instance();
        $this->assertFalse($enc->encrypt('secret'));
        $this->assertSame(Mcpwp_Encryption::REASON_KEY_UNAVAILABLE, $enc->get_last_error());
        $this->assertArrayNotHasKey(self::KEY_OPTION, $GLOBALS['mcpwp_test_wpdb_options'] ?? array());
        $this->assertArrayNotHasKey(self::KEY_OPTION, $GLOBALS['mcpwp_test_options']);
    }

    public function test_key_row_missing_from_the_option_cache_is_still_used(): void
    {
        // A stale notoptions entry (or a failed earlier get_option) hides the
        // row from the options API. The row itself is the authority.
        $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $this->seedKeyRow($key, false);
        $blob = $this->legacyBlob('still-mine', $key);

        $enc = Mcpwp_Encryption::get_instance();
        $this->assertSame('still-mine', $enc->decrypt($blob));
        $this->assertSame(base64_encode($key), $GLOBALS['mcpwp_test_wpdb_options'][ self::KEY_OPTION ]);
        $this->assertArrayNotHasKey(self::KEY_OPTION, $GLOBALS['mcpwp_test_options']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function corruptKeyValues(): array
    {
        return array(
            'not base64'       => array('%%% not a key %%%'),
            'truncated key'    => array(base64_encode(random_bytes(16))),
            'empty row'        => array(''),
            'overlong key'     => array(base64_encode(random_bytes(64))),
        );
    }

    /**
     * @dataProvider corruptKeyValues
     */
    public function test_corrupt_key_row_fails_closed_without_regenerating(string $corrupt): void
    {
        $this->seedRawKeyValue($corrupt);

        $enc = Mcpwp_Encryption::get_instance();
        $this->assertFalse($enc->encrypt('secret'), 'encrypt must refuse to replace a key row that exists');
        $this->assertSame(Mcpwp_Encryption::REASON_KEY_CORRUPT, $enc->get_last_error());

        $this->assertFalse($enc->decrypt($this->legacyBlob('x', random_bytes(32))));
        $this->assertSame(Mcpwp_Encryption::REASON_KEY_CORRUPT, $enc->get_last_error());

        $this->assertSame($corrupt, $GLOBALS['mcpwp_test_wpdb_options'][ self::KEY_OPTION ], 'the corrupt row is evidence; it must not be overwritten');
        $this->assertSame($corrupt, $GLOBALS['mcpwp_test_options'][ self::KEY_OPTION ]);

        $alerts = get_option(self::ALERTS_OPTION, array());
        $this->assertArrayHasKey(Mcpwp_Encryption::REASON_KEY_CORRUPT, $alerts, 'a corrupt key must leave a persistent signal');
    }

    public function test_corrupt_key_is_shown_to_site_managers_without_key_material(): void
    {
        $corrupt = base64_encode('not-a-32-byte-key');
        $this->seedRawKeyValue($corrupt);
        $this->assertFalse(Mcpwp_Encryption::get_instance()->encrypt('x'));

        $prevUser = $GLOBALS['mcpwp_test_current_user'];
        $prevCaps = $GLOBALS['mcpwp_test_user_caps'];
        try {
            $GLOBALS['mcpwp_test_user_caps'] = array();

            $GLOBALS['mcpwp_test_current_user'] = 0;
            ob_start();
            Mcpwp_Encryption::render_admin_notice();
            $this->assertSame('', ob_get_clean(), 'visitors without manage_options see nothing');

            $GLOBALS['mcpwp_test_current_user'] = 2;
            ob_start();
            Mcpwp_Encryption::render_admin_notice();
            $html = (string) ob_get_clean();
            $this->assertStringContainsString('mcpwp_encryption_key', $html);
            $this->assertStringNotContainsString($corrupt, $html, 'the notice must never echo the stored value');

            // The live row check shows the notice even when no alert was recorded.
            delete_option(self::ALERTS_OPTION);
            ob_start();
            Mcpwp_Encryption::render_admin_notice();
            $this->assertStringContainsString('mcpwp_encryption_key', (string) ob_get_clean());

            // Once the row is restored, the key-state alert goes away.
            $this->assertFalse($this->restartEncryption()->encrypt('x'));
            $this->seedKeyRow(random_bytes(32), true);
            ob_start();
            Mcpwp_Encryption::render_admin_notice();
            $this->assertSame('', (string) ob_get_clean());
            $this->assertArrayNotHasKey(Mcpwp_Encryption::REASON_KEY_CORRUPT, (array) get_option(self::ALERTS_OPTION, array()));
        } finally {
            $GLOBALS['mcpwp_test_current_user'] = $prevUser;
            $GLOBALS['mcpwp_test_user_caps']    = $prevCaps;
        }
    }

    public function test_decrypt_never_creates_a_key(): void
    {
        $enc = Mcpwp_Encryption::get_instance();

        $this->assertFalse($enc->decrypt($this->legacyBlob('orphan', random_bytes(32))));
        $this->assertSame(Mcpwp_Encryption::REASON_KEY_MISSING, $enc->get_last_error());
        $this->assertArrayNotHasKey(self::KEY_OPTION, $GLOBALS['mcpwp_test_wpdb_options'] ?? array());
        $this->assertArrayNotHasKey(self::KEY_OPTION, $GLOBALS['mcpwp_test_options']);
    }

    // ── P2-C: first creation converges ───────────────────────────────────

    public function test_concurrent_first_creation_converges_on_the_stored_key(): void
    {
        $winner = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

        // Between this request's "no key" read and its insert, another request stores its key.
        $GLOBALS['mcpwp_test_wpdb_before_insert'] = function (string $name) use ($winner): void {
            if (self::KEY_OPTION === $name) {
                $GLOBALS['mcpwp_test_wpdb_options'][ self::KEY_OPTION ] = base64_encode($winner);
            }
        };

        $enc  = Mcpwp_Encryption::get_instance();
        $blob = $enc->encrypt('written-by-the-loser');

        $this->assertNotFalse($blob);
        $this->assertSame(base64_encode($winner), $GLOBALS['mcpwp_test_wpdb_options'][ self::KEY_OPTION ], 'first writer wins; the row is never overwritten');
        $this->assertSame('written-by-the-loser', $enc->decrypt_with_key($blob, $winner), 'the loser must encrypt under the stored key, not its own');
        $this->assertSame('written-by-the-loser', $this->restartEncryption()->decrypt($blob));
    }

    public function test_key_creation_that_cannot_be_stored_fails_closed(): void
    {
        $GLOBALS['mcpwp_test_wpdb_insert_fails'] = true;

        $enc = Mcpwp_Encryption::get_instance();
        $this->assertFalse($enc->encrypt('secret'));
        $this->assertSame(Mcpwp_Encryption::REASON_KEY_WRITE_FAILED, $enc->get_last_error());
        $this->assertArrayNotHasKey(self::KEY_OPTION, $GLOBALS['mcpwp_test_wpdb_options'] ?? array());
    }

    // ── P2-D: versioned blobs ────────────────────────────────────────────

    public function test_v2_blob_reports_wrong_key_apart_from_tampering(): void
    {
        $first = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $this->seedKeyRow($first, true);

        $enc  = Mcpwp_Encryption::get_instance();
        $blob = $enc->encrypt('versioned-secret');

        $this->assertIsString($blob);
        $this->assertStringStartsWith('v2:', $blob);
        $this->assertFalse(base64_decode($blob, true), 'a v2 blob can never parse as a legacy blob');

        $binary = base64_decode(substr($blob, 3), true);
        $this->assertSame(2, ord($binary[0]), 'version byte');
        $this->assertSame(
            1 + 4 + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES + strlen('versioned-secret'),
            strlen($binary)
        );

        $this->assertSame('versioned-secret', $enc->decrypt($blob));
        $this->assertSame('', $enc->get_last_error());

        // Tamper: flip one ciphertext bit, keep the header.
        $tampered              = $binary;
        $last                  = strlen($tampered) - 1;
        $tampered[ $last ]     = chr(ord($tampered[ $last ]) ^ 0x01);
        $this->assertFalse($enc->decrypt('v2:' . base64_encode($tampered)));
        $this->assertSame(Mcpwp_Encryption::REASON_TAMPERED, $enc->get_last_error());

        // Wrong key: the stored key is now a different one.
        $this->seedKeyRow(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES), true);
        $enc = $this->restartEncryption();
        $this->assertFalse($enc->decrypt($blob));
        $this->assertSame(Mcpwp_Encryption::REASON_WRONG_KEY, $enc->get_last_error());
    }

    public function test_unknown_versions_and_malformed_blobs_are_named(): void
    {
        $this->seedKeyRow(random_bytes(32), true);
        $enc = Mcpwp_Encryption::get_instance();

        $this->assertFalse($enc->decrypt('v3:' . base64_encode(str_repeat('a', 60))));
        $this->assertSame(Mcpwp_Encryption::REASON_UNSUPPORTED_VERSION, $enc->get_last_error());

        $this->assertFalse($enc->decrypt('v2:***'));
        $this->assertSame(Mcpwp_Encryption::REASON_MALFORMED, $enc->get_last_error());

        $this->assertFalse($enc->decrypt('v2:' . base64_encode(chr(3) . str_repeat('a', 60))));
        $this->assertSame(Mcpwp_Encryption::REASON_UNSUPPORTED_VERSION, $enc->get_last_error());
    }

    public function test_legacy_blob_still_decrypts_and_new_writes_are_versioned(): void
    {
        $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $this->seedKeyRow($key, true);

        $enc = Mcpwp_Encryption::get_instance();
        $this->assertSame('written-by-3.11.0', $enc->decrypt($this->legacyBlob('written-by-3.11.0', $key)));
        $this->assertSame('', $enc->get_last_error());

        $this->assertFalse($enc->decrypt($this->legacyBlob('other-key', random_bytes(32))));
        $this->assertSame(Mcpwp_Encryption::REASON_UNDECRYPTABLE, $enc->get_last_error());

        $fresh = $enc->encrypt('written-by-3.11.1');
        $this->assertStringStartsWith('v2:', (string) $fresh);
        $this->assertSame('written-by-3.11.1', $enc->decrypt_with_key($fresh, $key));
    }

    // ── P2-C: migration lock and verified flag ───────────────────────────

    public function test_migration_does_not_run_while_a_live_lock_is_held(): void
    {
        $blob = $this->authSaltBlob('sk-legacy');
        $this->seedIntegrations(array('openai' => $blob));
        $held = (time() + 60) . '|another-request';
        $GLOBALS['mcpwp_test_wpdb_options'][ self::LOCK_OPTION ] = $held;

        Mcpwp_Encryption_Migration::run();

        $this->assertFalse(get_option(Mcpwp_Encryption_Migration::MIGRATED_FLAG), 'the holder finishes the migration, not us');
        $this->assertSame($blob, get_option(Mcpwp_Integration_Manager::OPTION_NAME)['openai']['key_encrypted']);
        $this->assertSame($held, $GLOBALS['mcpwp_test_wpdb_options'][ self::LOCK_OPTION ], 'another request\'s lock is never released by us');
    }

    public function test_migration_holds_the_lock_while_writing_and_releases_it(): void
    {
        $this->seedIntegrations(array('openai' => $this->authSaltBlob('sk-legacy')));

        $lockSeenAtWrite = null;
        $hook            = null;
        $hook            = function (string $name) use (&$lockSeenAtWrite, &$hook): void {
            if (Mcpwp_Integration_Manager::OPTION_NAME !== $name) {
                $GLOBALS['mcpwp_test_wpdb_before_update'] = $hook;
                return;
            }
            $lockSeenAtWrite = array_key_exists(self::LOCK_OPTION, $GLOBALS['mcpwp_test_wpdb_options'] ?? array());
        };
        $GLOBALS['mcpwp_test_wpdb_before_update'] = $hook;

        Mcpwp_Encryption_Migration::run();

        $this->assertTrue($lockSeenAtWrite, 'the integrations write must happen under the migration lock');
        $this->assertArrayNotHasKey(self::LOCK_OPTION, $GLOBALS['mcpwp_test_wpdb_options'] ?? array(), 'the lock is released after the run');
        $this->assertNotEmpty(get_option(Mcpwp_Encryption_Migration::MIGRATED_FLAG));
    }

    public function test_migration_takes_over_an_expired_lock(): void
    {
        $this->seedIntegrations(array('openai' => $this->authSaltBlob('sk-legacy')));
        $GLOBALS['mcpwp_test_wpdb_options'][ self::LOCK_OPTION ] = (time() - 5) . '|crashed-request';

        Mcpwp_Encryption_Migration::run();

        $this->assertNotEmpty(get_option(Mcpwp_Encryption_Migration::MIGRATED_FLAG), 'a crashed run must not wedge the migration');
        $this->assertArrayNotHasKey(self::LOCK_OPTION, $GLOBALS['mcpwp_test_wpdb_options'] ?? array());
        $stored = get_option(Mcpwp_Integration_Manager::OPTION_NAME)['openai']['key_encrypted'];
        $this->assertStringStartsWith('v2:', $stored);
        $this->assertSame('sk-legacy', $this->restartEncryption()->decrypt($stored));
    }

    public function test_migration_does_not_run_unprotected_when_the_lock_cannot_be_taken(): void
    {
        $blob = $this->authSaltBlob('sk-legacy');
        $this->seedIntegrations(array('openai' => $blob));
        $GLOBALS['mcpwp_test_wpdb_insert_fails'] = true;

        Mcpwp_Encryption_Migration::run();

        $this->assertFalse(get_option(Mcpwp_Encryption_Migration::MIGRATED_FLAG));
        $this->assertSame($blob, get_option(Mcpwp_Integration_Manager::OPTION_NAME)['openai']['key_encrypted']);
    }

    public function test_migration_flag_waits_for_a_verified_write(): void
    {
        $this->seedIntegrations(array('openai' => $this->authSaltBlob('sk-legacy')));

        // What is stored differs from what was written by the time it is read back.
        $hook = null;
        $hook = function (string $name) use (&$hook): void {
            if (Mcpwp_Integration_Manager::OPTION_NAME !== $name) {
                $GLOBALS['mcpwp_test_wpdb_after_update'] = $hook;
                return;
            }
            $GLOBALS['mcpwp_test_options'][ Mcpwp_Integration_Manager::OPTION_NAME ]['openai']['key_encrypted'] = 'altered-after-the-write';
        };
        $GLOBALS['mcpwp_test_wpdb_after_update'] = $hook;

        Mcpwp_Encryption_Migration::run();

        $this->assertFalse(get_option(Mcpwp_Encryption_Migration::MIGRATED_FLAG), 'an unverified write must not mark the migration done');
        $this->assertArrayNotHasKey(self::LOCK_OPTION, $GLOBALS['mcpwp_test_wpdb_options'] ?? array());
    }

    public function test_migration_aborts_without_a_flag_when_the_current_key_is_unreadable(): void
    {
        $this->seedRawKeyValue('%%% corrupt %%%');
        $blob = $this->authSaltBlob('sk-legacy');
        $this->seedIntegrations(array('openai' => $blob));

        Mcpwp_Encryption_Migration::run();

        $this->assertFalse(get_option(Mcpwp_Encryption_Migration::MIGRATED_FLAG), 'a key problem is retried, not recorded as done');
        $this->assertFalse(get_option(Mcpwp_Encryption_Migration::FAILURES_OPTION), 'the admin must not be told to re-enter keys that cannot be stored either');
        $this->assertSame($blob, get_option(Mcpwp_Integration_Manager::OPTION_NAME)['openai']['key_encrypted']);
        $this->assertSame('%%% corrupt %%%', $GLOBALS['mcpwp_test_wpdb_options'][ self::KEY_OPTION ]);
    }

    public function test_an_unfinished_migration_backs_off_instead_of_retrying_every_request(): void
    {
        $this->seedRawKeyValue('%%% corrupt %%%');
        $blob = $this->authSaltBlob('sk-legacy');
        $this->seedIntegrations(array('openai' => $blob));

        Mcpwp_Encryption_Migration::maybe_run();
        $this->assertFalse(get_option(Mcpwp_Encryption_Migration::MIGRATED_FLAG));

        // The key is repaired, but the next request inside the delay does not
        // even try the lock — no write per request while a site is broken.
        $this->seedKeyRow(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES), true);
        $this->restartEncryption();
        $inserts = array();
        $GLOBALS['mcpwp_test_wpdb_before_insert'] = function (string $name) use (&$inserts): void {
            $inserts[] = $name;
        };

        Mcpwp_Encryption_Migration::maybe_run();
        $this->assertSame(array(), $inserts, 'no lock insert inside the retry delay');
        $this->assertFalse(get_option(Mcpwp_Encryption_Migration::MIGRATED_FLAG));
        $this->assertSame($blob, get_option(Mcpwp_Integration_Manager::OPTION_NAME)['openai']['key_encrypted']);

        // Once the delay has passed, it runs.
        $GLOBALS['mcpwp_test_wpdb_before_insert'] = null;
        delete_transient(Mcpwp_Encryption_Migration::RETRY_TRANSIENT);
        Mcpwp_Encryption_Migration::maybe_run();
        $this->assertNotEmpty(get_option(Mcpwp_Encryption_Migration::MIGRATED_FLAG));
    }

    public function test_migration_does_not_count_blobs_already_under_the_current_key_as_failures(): void
    {
        $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $this->seedKeyRow($key, true);

        $legacyUnderCurrent = $this->legacyBlob('migrated-by-3.2.4', $key);
        $v2                 = Mcpwp_Encryption::get_instance()->encrypt('written-by-3.11.1');
        $this->seedIntegrations(array('openai' => $legacyUnderCurrent, 'gemini' => $v2));

        Mcpwp_Encryption_Migration::run();

        $this->assertNotEmpty(get_option(Mcpwp_Encryption_Migration::MIGRATED_FLAG));
        $this->assertSame(array(), (array) get_option(Mcpwp_Encryption_Migration::FAILURES_OPTION, array()));
        $data = get_option(Mcpwp_Integration_Manager::OPTION_NAME);
        $this->assertSame($legacyUnderCurrent, $data['openai']['key_encrypted']);
        $this->assertSame($v2, $data['gemini']['key_encrypted']);
    }

    // ── P3-8: failures clear ─────────────────────────────────────────────

    public function test_reentering_a_provider_key_clears_its_migration_failure(): void
    {
        $this->seedKeyRow(random_bytes(32), true);
        update_option(Mcpwp_Encryption_Migration::FAILURES_OPTION, array('openai', 'gemini'));

        $manager = Mcpwp_Integration_Manager::get_instance();

        $this->assertTrue($manager->set_provider_key('openai', 'sk-reentered'));
        $this->assertSame(array('gemini'), get_option(Mcpwp_Encryption_Migration::FAILURES_OPTION));

        $this->assertTrue($manager->set_provider_config('gemini', array('api_key' => 'g-reentered')));
        $this->assertFalse(get_option(Mcpwp_Encryption_Migration::FAILURES_OPTION), 'an empty failures list is deleted, so the notice stops');
    }

    public function test_a_failed_reentry_keeps_the_failure_and_removal_clears_it(): void
    {
        $this->seedRawKeyValue('%%% corrupt %%%');
        update_option(Mcpwp_Encryption_Migration::FAILURES_OPTION, array('openai', 'gemini'));

        $manager = Mcpwp_Integration_Manager::get_instance();
        $this->assertFalse($manager->set_provider_key('openai', 'sk-cannot-store'));
        $this->assertSame(array('openai', 'gemini'), get_option(Mcpwp_Encryption_Migration::FAILURES_OPTION), 'nothing was re-entered, so nothing clears');

        $this->seedIntegrations(array('gemini' => 'preserved-unreadable-blob'));
        $this->assertTrue($manager->remove_provider_key('gemini'));
        $this->assertSame(array('openai'), get_option(Mcpwp_Encryption_Migration::FAILURES_OPTION), 'a removed provider has nothing left to re-enter');
    }
}
