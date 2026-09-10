<?php

use PHPUnit\Framework\TestCase;

/**
 * A weak MCPWP_ENCRYPTION_KEY must never become the key.
 *
 * The constant is hashed to the key length when it is not 32 bytes, so an
 * empty, blank or short constant derives a key anyone can compute (the empty
 * string's BLAKE2b is public). Such a constant fails closed with its own
 * reason — no fallback to the option key, no derived key — and is surfaced in
 * the encryption admin notice.
 *
 * Constants cannot be undefined: every test runs in its own process (#757).
 */
final class EncryptionWeakPinnedKeyTest extends TestCase
{
    private function restartEncryption(): Mcpwp_Encryption
    {
        $ref = new ReflectionProperty(Mcpwp_Encryption::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);

        return Mcpwp_Encryption::get_instance();
    }

    private function cleanProcess(): void
    {
        if (! Mcpwp_Encryption::is_available()) {
            $this->markTestSkipped('sodium extension not available');
        }
        if (defined('MCPWP_ENCRYPTION_KEY')) {
            $this->markTestSkipped('MCPWP_ENCRYPTION_KEY already defined in this process');
        }

        $GLOBALS['mcpwp_test_options']            = array();
        $GLOBALS['mcpwp_test_wpdb_options']       = array();
        $GLOBALS['mcpwp_test_wpdb_read_fails']    = false;
        $GLOBALS['mcpwp_test_wpdb_insert_fails']  = false;
        $GLOBALS['mcpwp_test_update_option_fail'] = array();
        $GLOBALS['mcpwp_test_user_caps']          = array();
        $this->restartEncryption();
    }

    private function legacyBlob(string $plaintext, string $key): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $key));
    }

    private function noticeHtml(): string
    {
        $GLOBALS['mcpwp_test_current_user'] = 2;
        ob_start();
        Mcpwp_Encryption::render_admin_notice();

        return (string) ob_get_clean();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function weakConstants(): array
    {
        return array(
            'empty string'                  => array(''),
            'whitespace only'               => array(" \t\n  "),
            'short passphrase'              => array('hunter2-pass'),
            '15 bytes inside whitespace'    => array('   fifteen-bytes!!   '),
            'one repeated character'        => array(str_repeat('a', 32)),
            'all zero bytes'                => array(str_repeat("\0", 32)),
        );
    }

    /**
     * @dataProvider weakConstants
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_a_weak_pinned_constant_fails_closed_and_is_surfaced(string $constant): void
    {
        $this->cleanProcess();

        // Secrets already exist under the option key.
        $optionKey = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $GLOBALS['mcpwp_test_wpdb_options']['mcpwp_encryption_key'] = base64_encode($optionKey);
        $GLOBALS['mcpwp_test_options']['mcpwp_encryption_key']      = base64_encode($optionKey);
        $underOption = Mcpwp_Encryption::get_instance()->encrypt('under-the-option-key');
        $this->assertNotFalse($underOption);

        define('MCPWP_ENCRYPTION_KEY', $constant);
        $enc = $this->restartEncryption();

        $this->assertFalse($enc->encrypt('new-secret'), 'nothing may be written under a guessable key');
        $this->assertSame(Mcpwp_Encryption::REASON_PINNED_KEY_WEAK, $enc->get_last_error());

        $this->assertFalse($enc->decrypt($underOption), 'a weak pin is a misconfiguration, not a reason to fall back');
        $this->assertSame(Mcpwp_Encryption::REASON_PINNED_KEY_WEAK, $enc->get_last_error());
        $this->assertSame(array(), $enc->get_last_warnings());

        // A blob under the publicly derivable key is not honoured either.
        $public = SODIUM_CRYPTO_SECRETBOX_KEYBYTES === strlen($constant)
            ? $constant
            : sodium_crypto_generichash($constant, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $this->assertFalse($enc->decrypt($this->legacyBlob('under-the-public-key', $public)));
        $this->assertSame(Mcpwp_Encryption::REASON_PINNED_KEY_WEAK, $enc->get_last_error());

        $this->assertSame(base64_encode($optionKey), $GLOBALS['mcpwp_test_wpdb_options']['mcpwp_encryption_key'], 'the option key is untouched');

        $this->assertArrayHasKey(
            Mcpwp_Encryption::REASON_PINNED_KEY_WEAK,
            (array) get_option(Mcpwp_Encryption::ALERTS_OPTION, array())
        );

        // The notice shows it from the constant itself, even with no recorded alert.
        delete_option(Mcpwp_Encryption::ALERTS_OPTION);
        $html = $this->noticeHtml();
        $this->assertStringContainsString('MCPWP_ENCRYPTION_KEY', $html);
        if ('' !== trim($constant)) {
            $this->assertStringNotContainsString($constant, $html, 'the notice never echoes the constant');
        }

        $GLOBALS['mcpwp_test_current_user'] = 0;
        ob_start();
        Mcpwp_Encryption::render_admin_notice();
        $this->assertSame('', (string) ob_get_clean());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_a_sixteen_byte_passphrase_is_accepted(): void
    {
        $this->cleanProcess();

        define('MCPWP_ENCRYPTION_KEY', 'sixteen-byte-key');
        $enc = $this->restartEncryption();

        $blob = $enc->encrypt('pinned-secret');
        $this->assertNotFalse($blob);
        $this->assertSame('pinned-secret', $enc->decrypt($blob));
        $this->assertSame('', $enc->get_last_error());
        $this->assertSame('', $this->noticeHtml());
    }
}
