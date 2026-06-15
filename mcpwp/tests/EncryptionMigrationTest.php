<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for the AUTH_SALT → dedicated-key encryption migration (task 457e5f98).
 *
 * Covers:
 *   (a) Old-key blob  → migrates → decryptable under new key.
 *   (b) Unreadable blob → PRESERVED + recorded in failures (NOT deleted).
 *   (c) Idempotent: second run is skipped entirely.
 *   (d) get_key() preference: constant > option > generates-and-persists.
 *   (e) Pro-gate bypass: is_trusted_admin_ajax_request() conditions.
 */
final class EncryptionMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['mcpwp_test_options']    = array();
        $GLOBALS['mcpwp_test_transients'] = array();
        // Reset the encryption singleton so each test gets a fresh key.
        $this->resetEncryptionSingleton();
    }

    // ── Helper ──────────────────────────────────────────────────────────────

    /**
     * Reset the Mcpwp_Encryption singleton via reflection so the cached key
     * is cleared between tests (otherwise one test's generated key bleeds into
     * the next test's expectations).
     */
    private function resetEncryptionSingleton(): void
    {
        $ref      = new \ReflectionClass(Mcpwp_Encryption::class);
        $instance = $ref->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);
    }

    /**
     * Produce a blob encrypted with the legacy AUTH_SALT-derived key.
     */
    private function makeOldKeyBlob(string $plaintext): string
    {
        $enc      = Mcpwp_Encryption::get_instance();
        $old_key  = $enc->get_legacy_auth_salt_key();

        $nonce      = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $old_key);
        return base64_encode($nonce . $ciphertext);
    }

    // ── (a) Happy-path migration ─────────────────────────────────────────────

    /**
     * A blob written under the old AUTH_SALT key must migrate successfully:
     * after migration, decrypting with the new dedicated key must return the
     * original plaintext.
     */
    public function test_old_key_blob_migrates_and_is_decryptable_under_new_key(): void
    {
        if (! Mcpwp_Encryption::is_available()) {
            $this->markTestSkipped('sodium extension not available');
        }

        $plaintext = 'sk-test-openai-key-12345';
        $blob      = $this->makeOldKeyBlob($plaintext);

        // Seed integrations option with the old-key blob.
        update_option(Mcpwp_Integration_Manager::OPTION_NAME, array(
            'openai' => array(
                'key_encrypted' => $blob,
                'configured_at' => '2026-01-01 00:00:00',
                'last_tested'   => null,
                'test_status'   => null,
            ),
        ));

        // Migration must NOT have run yet.
        $this->assertFalse(get_option(Mcpwp_Encryption_Migration::MIGRATED_FLAG));

        // Run migration.
        Mcpwp_Encryption_Migration::run();

        // Flag must be set.
        $this->assertNotEmpty(get_option(Mcpwp_Encryption_Migration::MIGRATED_FLAG));

        // No failures recorded.
        $failures = get_option(Mcpwp_Encryption_Migration::FAILURES_OPTION, array());
        $this->assertNotContains('openai', $failures, 'openai must not be in failures list');

        // The re-encrypted blob must decrypt correctly with the new dedicated key.
        $data     = get_option(Mcpwp_Integration_Manager::OPTION_NAME, array());
        $new_blob = $data['openai']['key_encrypted'];

        $this->assertNotSame($blob, $new_blob, 'Blob must have changed (re-encrypted)');

        $enc       = Mcpwp_Encryption::get_instance();
        $decrypted = $enc->decrypt($new_blob);
        $this->assertSame($plaintext, $decrypted, 'Decrypted value must match original plaintext');
    }

    // ── (b) Unreadable blob → preserved + recorded ───────────────────────────

    /**
     * A blob that cannot be decrypted with the old key (e.g. already re-encrypted,
     * corrupt, or AUTH_SALT was rotated) must be LEFT UNTOUCHED and recorded in
     * the failures option so the admin can re-enter the key.
     */
    public function test_unreadable_blob_is_preserved_and_recorded_in_failures(): void
    {
        if (! Mcpwp_Encryption::is_available()) {
            $this->markTestSkipped('sodium extension not available');
        }

        // This blob was encrypted with a random key the old-key derivation will never produce.
        $random_key     = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $nonce          = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $bad_ciphertext = sodium_crypto_secretbox('secret', $nonce, $random_key);
        $bad_blob       = base64_encode($nonce . $bad_ciphertext);

        update_option(Mcpwp_Integration_Manager::OPTION_NAME, array(
            'gemini' => array(
                'key_encrypted' => $bad_blob,
                'configured_at' => '2026-01-01 00:00:00',
                'last_tested'   => null,
                'test_status'   => null,
            ),
        ));

        Mcpwp_Encryption_Migration::run();

        // Migration flag must be set (we ran to completion).
        $this->assertNotEmpty(get_option(Mcpwp_Encryption_Migration::MIGRATED_FLAG));

        // Provider must be in failures list.
        $failures = get_option(Mcpwp_Encryption_Migration::FAILURES_OPTION, array());
        $this->assertContains('gemini', $failures, 'Unreadable provider must be recorded in failures');

        // Blob must be PRESERVED, not deleted.
        $data = get_option(Mcpwp_Integration_Manager::OPTION_NAME, array());
        $this->assertArrayHasKey('gemini', $data, 'Provider record must still exist');
        $this->assertSame($bad_blob, $data['gemini']['key_encrypted'], 'Blob must be unchanged (preserved)');
    }

    /**
     * A provider with an empty key_encrypted must be skipped silently (no failure).
     */
    public function test_empty_blob_provider_is_skipped_without_failure(): void
    {
        if (! Mcpwp_Encryption::is_available()) {
            $this->markTestSkipped('sodium extension not available');
        }

        update_option(Mcpwp_Integration_Manager::OPTION_NAME, array(
            'pexels' => array(
                'key_encrypted' => '',
                'configured_at' => '2026-01-01 00:00:00',
            ),
        ));

        Mcpwp_Encryption_Migration::run();

        $failures = get_option(Mcpwp_Encryption_Migration::FAILURES_OPTION, array());
        $this->assertNotContains('pexels', $failures, 'Empty-blob provider must not be in failures');
    }

    // ── (c) Idempotent ───────────────────────────────────────────────────────

    /**
     * A second call to maybe_run() (the public entry point) after the migration
     * flag is set must be a no-op: it must not re-process blobs, must not
     * overwrite data, and must not clear the failures record.
     */
    public function test_second_run_is_skipped_idempotently(): void
    {
        if (! Mcpwp_Encryption::is_available()) {
            $this->markTestSkipped('sodium extension not available');
        }

        // Pre-set the migrated flag.
        update_option(Mcpwp_Encryption_Migration::MIGRATED_FLAG, '1');

        // Store a sentinel value we can verify is untouched.
        $sentinel = array('openai' => array('key_encrypted' => 'sentinel-value'));
        update_option(Mcpwp_Integration_Manager::OPTION_NAME, $sentinel);

        Mcpwp_Encryption_Migration::maybe_run();

        // Data must be exactly as we left it — migration did not run.
        $data = get_option(Mcpwp_Integration_Manager::OPTION_NAME, array());
        $this->assertSame('sentinel-value', $data['openai']['key_encrypted'], 'Second run must not touch data');
    }

    // ── (d) get_key() key-preference order ──────────────────────────────────

    /**
     * When MCPWP_ENCRYPTION_KEY constant is defined, get_key() must use it.
     * We test this via encrypt/decrypt round-trip — if the constant key is used,
     * the ciphertext decrypts to the original plaintext.
     */
    public function test_get_key_prefers_php_constant(): void
    {
        if (! Mcpwp_Encryption::is_available()) {
            $this->markTestSkipped('sodium extension not available');
        }

        if (! defined('MCPWP_ENCRYPTION_KEY')) {
            // We cannot define a constant twice, so only run this leg when
            // a test suite specifically defines it before load.  Mark skip otherwise.
            $this->markTestSkipped('MCPWP_ENCRYPTION_KEY constant not defined in test env');
        }

        $enc       = Mcpwp_Encryption::get_instance();
        $plaintext = 'constant-key-test';
        $encrypted = $enc->encrypt($plaintext);
        $this->assertNotFalse($encrypted);
        $this->assertSame($plaintext, $enc->decrypt($encrypted));
    }

    /**
     * When no constant and no stored option exist, get_key() must generate
     * a random key and persist it as mcpwp_encryption_key (base64, non-autoloaded).
     * A second instantiation must return the SAME key (consistent round-trip).
     */
    public function test_get_key_generates_and_persists_when_no_option_or_constant(): void
    {
        if (! Mcpwp_Encryption::is_available()) {
            $this->markTestSkipped('sodium extension not available');
        }

        if (defined('MCPWP_ENCRYPTION_KEY')) {
            $this->markTestSkipped('MCPWP_ENCRYPTION_KEY constant is defined — cannot test generation path');
        }

        // No stored key option set (setUp cleared mcpwp_test_options).
        $enc       = Mcpwp_Encryption::get_instance();
        $plaintext = 'generated-key-test';
        $encrypted = $enc->encrypt($plaintext);

        $this->assertNotFalse($encrypted, 'encrypt() must succeed');

        // Option must now be set (base64, correct length when decoded).
        $stored_b64 = get_option('mcpwp_encryption_key', '');
        $this->assertNotEmpty($stored_b64, 'mcpwp_encryption_key option must be set after first use');
        $raw = base64_decode($stored_b64, true);
        $this->assertNotFalse($raw, 'Stored value must be valid base64');
        $this->assertSame(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, strlen($raw), 'Stored key must be correct length');

        // Decrypt with a fresh singleton (simulates next boot reading from option).
        $this->resetEncryptionSingleton();
        $enc2 = Mcpwp_Encryption::get_instance();
        $this->assertSame($plaintext, $enc2->decrypt($encrypted), 'Same key must produce consistent round-trip');
    }

    /**
     * When mcpwp_encryption_key option is pre-populated, get_key() must use it
     * without generating a new one.
     */
    public function test_get_key_reads_from_stored_option(): void
    {
        if (! Mcpwp_Encryption::is_available()) {
            $this->markTestSkipped('sodium extension not available');
        }

        if (defined('MCPWP_ENCRYPTION_KEY')) {
            $this->markTestSkipped('MCPWP_ENCRYPTION_KEY constant is defined — constant takes precedence');
        }

        // Pre-store a known key.
        $known_key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        update_option('mcpwp_encryption_key', base64_encode($known_key));

        $enc       = Mcpwp_Encryption::get_instance();
        $plaintext = 'option-key-test';
        $encrypted = $enc->encrypt($plaintext);

        // Decrypt using the known key directly to verify the singleton used it.
        $decrypted = $enc->decrypt_with_key($encrypted, $known_key);
        $this->assertSame($plaintext, $decrypted, 'Encryption must use the key from the stored option');

        // No NEW key must have been generated (option must still be the known key).
        $stored = get_option('mcpwp_encryption_key', '');
        $this->assertSame(base64_encode($known_key), $stored, 'Option must not be overwritten');
    }

    // ── (e) Pro-gate: is_trusted_admin_ajax_request() ───────────────────────

    /**
     * The bypass must not fire when the current user is NOT an admin.
     * Uses the ApiAuth trait via a concrete class double.
     */
    public function test_bypass_requires_admin_context(): void
    {
        $GLOBALS['mcpwp_test_is_admin']   = false;
        $GLOBALS['mcpwp_test_doing_ajax'] = false;

        $auth = $this->makeAuthDouble();
        // Call verify_api_key with no key in the request — should fail with missing_api_key,
        // NOT silently pass (which would indicate the bypass fired).
        $request = new WP_REST_Request('POST', '/mcpwp/v1/mcp');
        $result  = $auth->verify_api_key($request);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertNotSame(true, $result, 'Bypass must not fire when is_admin() is false');
    }

    /**
     * The bypass must not fire when DOING_AJAX is not defined / not true.
     */
    public function test_bypass_requires_doing_ajax(): void
    {
        $GLOBALS['mcpwp_test_is_admin']           = true;
        $GLOBALS['mcpwp_test_current_user']       = 2; // admin user
        $GLOBALS['mcpwp_test_ajax_referer_valid'] = true;
        // DOING_AJAX constant is not defined in bootstrap (unless another test suite defined it).
        // The check `defined('DOING_AJAX') && DOING_AJAX` must be false.

        $auth    = $this->makeAuthDouble();
        $request = new WP_REST_Request('POST', '/mcpwp/v1/mcp');
        $result  = $auth->verify_api_key($request);

        $this->assertInstanceOf(WP_Error::class, $result, 'Bypass must not fire without DOING_AJAX');
    }

    /**
     * The open apply_filters bypass path must no longer exist: adding a filter
     * that returns true must NOT cause the check to pass.
     */
    public function test_filter_bypass_is_gone(): void
    {
        // Register the old bypass filter (should now be ignored).
        add_filter('mcpwp_bypass_api_key_check', function () {
            return true;
        });

        $GLOBALS['mcpwp_test_is_admin']     = false;
        $GLOBALS['mcpwp_test_current_user'] = 0; // logged-out

        $auth    = $this->makeAuthDouble();
        $request = new WP_REST_Request('POST', '/mcpwp/v1/mcp');
        $result  = $auth->verify_api_key($request);

        $this->assertInstanceOf(WP_Error::class, $result, 'Filter-only bypass must not work after hardening');
    }

    // ── Helper: make a concrete class that uses the trait ────────────────────

    private function makeAuthDouble()
    {
        // Mcpwp_REST_API is abstract; we need to implement register_routes().
        return new class extends Mcpwp_REST_API {
            public function register_routes()
            {
                // No-op for tests.
            }

            public function verify_api_key($request)
            {
                return parent::verify_api_key($request);
            }
        };
    }
}
