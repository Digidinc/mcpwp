<?php

use PHPUnit\Framework\TestCase;

/**
 * #564 P2-D: pinning MCPWP_ENCRYPTION_KEY after secrets exist.
 *
 * Constants cannot be undefined, so every test here runs in its own process —
 * a constant defined in the main run would silently change every encryption
 * test that follows it (#757).
 */
final class EncryptionPinnedKeyTest extends TestCase
{
    private function freshState(): void
    {
        $GLOBALS['mcpwp_test_options']            = array();
        $GLOBALS['mcpwp_test_wpdb_options']       = array();
        $GLOBALS['mcpwp_test_wpdb_read_fails']    = false;
        $GLOBALS['mcpwp_test_wpdb_insert_fails']  = false;
        $GLOBALS['mcpwp_test_update_option_fail'] = array();
        $this->restartEncryption();
    }

    private function restartEncryption(): Mcpwp_Encryption
    {
        $ref = new ReflectionProperty(Mcpwp_Encryption::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);

        return Mcpwp_Encryption::get_instance();
    }

    private function requireCleanProcess(): void
    {
        if (! Mcpwp_Encryption::is_available()) {
            $this->markTestSkipped('sodium extension not available');
        }
        if (defined('MCPWP_ENCRYPTION_KEY')) {
            $this->markTestSkipped('MCPWP_ENCRYPTION_KEY already defined in this process');
        }
        $this->freshState();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_pinning_a_constant_after_secrets_exist_keeps_them_readable_with_a_warning(): void
    {
        $this->requireCleanProcess();

        $optionKey = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $GLOBALS['mcpwp_test_wpdb_options']['mcpwp_encryption_key'] = base64_encode($optionKey);
        $GLOBALS['mcpwp_test_options']['mcpwp_encryption_key']      = base64_encode($optionKey);

        $current = Mcpwp_Encryption::get_instance()->encrypt('written-under-the-option-key');
        $this->assertNotFalse($current);

        $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $legacy = base64_encode($nonce . sodium_crypto_secretbox('legacy-under-the-option-key', $nonce, $optionKey));

        define('MCPWP_ENCRYPTION_KEY', 'the operator pinned this after secrets already existed');
        $enc = $this->restartEncryption();

        $this->assertSame('written-under-the-option-key', $enc->decrypt($current));
        $this->assertSame('', $enc->get_last_error());
        $this->assertContains(Mcpwp_Encryption::WARNING_PINNED_KEY_DIFFERS, $enc->get_last_warnings());

        $this->assertSame('legacy-under-the-option-key', $enc->decrypt($legacy));
        $this->assertContains(Mcpwp_Encryption::WARNING_PINNED_KEY_DIFFERS, $enc->get_last_warnings());

        $this->assertArrayHasKey(
            Mcpwp_Encryption::WARNING_PINNED_KEY_DIFFERS,
            (array) get_option(Mcpwp_Encryption::ALERTS_OPTION, array()),
            'the mismatch must be visible to the admin, not only to the caller'
        );

        // New writes use the pinned key, and read back without a warning.
        $pinned = sodium_crypto_generichash(MCPWP_ENCRYPTION_KEY, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $fresh  = $enc->encrypt('written-after-pinning');
        $this->assertSame('written-after-pinning', $enc->decrypt_with_key($fresh, $pinned));
        $this->assertFalse($enc->decrypt_with_key($fresh, $optionKey));
        $this->assertSame('written-after-pinning', $enc->decrypt($fresh));
        $this->assertSame(array(), $enc->get_last_warnings());

        $this->assertSame(base64_encode($optionKey), $GLOBALS['mcpwp_test_wpdb_options']['mcpwp_encryption_key'], 'the option key is never touched');
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_with_a_pinned_constant_an_unreadable_option_key_is_not_reported_as_a_wrong_key(): void
    {
        $this->requireCleanProcess();

        $optionKey = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $GLOBALS['mcpwp_test_wpdb_options']['mcpwp_encryption_key'] = base64_encode($optionKey);
        $GLOBALS['mcpwp_test_options']['mcpwp_encryption_key']      = base64_encode($optionKey);
        $blob = Mcpwp_Encryption::get_instance()->encrypt('under-the-option-key');
        $this->assertNotFalse($blob);

        define('MCPWP_ENCRYPTION_KEY', substr(hash('sha256', 'a pinned 32-byte test key'), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $enc = $this->restartEncryption();

        // The fallback key cannot be read: that is "unavailable", not "wrong key".
        $GLOBALS['mcpwp_test_wpdb_read_fails'] = true;
        $this->assertFalse($enc->decrypt($blob));
        $this->assertSame(Mcpwp_Encryption::REASON_KEY_UNAVAILABLE, $enc->get_last_error());

        // A readable option key whose fingerprint matches neither key: wrong key.
        $GLOBALS['mcpwp_test_wpdb_read_fails'] = false;
        $GLOBALS['mcpwp_test_wpdb_options']['mcpwp_encryption_key'] = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $enc = $this->restartEncryption();
        $this->assertFalse($enc->decrypt($blob));
        $this->assertSame(Mcpwp_Encryption::REASON_WRONG_KEY, $enc->get_last_error());

        // The pinned key itself is always usable, even while the table is unreadable.
        $GLOBALS['mcpwp_test_wpdb_read_fails'] = true;
        $pinnedBlob = $enc->encrypt('under-the-pinned-key');
        $this->assertNotFalse($pinnedBlob);
        $this->assertSame('under-the-pinned-key', $enc->decrypt($pinnedBlob));
    }
}
