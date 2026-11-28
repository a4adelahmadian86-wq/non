<?php

namespace Tests\Unit\Storage;

use App\Services\Storage\StorageBackupObjectCipher;
use RuntimeException;
use Tests\TestCase;

class StorageBackupObjectCipherTest extends TestCase
{
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_it_stream_encrypts_and_decrypts_empty_and_multi_chunk_files(): void
    {
        if (! function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push')) {
            $this->markTestSkipped('Sodium secretstream is not available in this PHP runtime.');
        }

        $cipher = new StorageBackupObjectCipher();
        $key = str_repeat('K', 64);

        foreach (['', random_bytes(2 * 1024 * 1024 + 137)] as $contents) {
            $source = $this->temporaryFile($contents);
            $encrypted = $this->temporaryFile('');
            $restored = $this->temporaryFile('');

            $metadata = $cipher->encryptFile($source, $encrypted, $key);
            $this->assertSame(strlen($contents), $metadata['size_bytes']);
            $this->assertSame(hash('sha256', $contents), $metadata['sha256']);
            $this->assertGreaterThan(strlen($contents), $metadata['encrypted_size_bytes']);

            $cipher->decryptFile($encrypted, $restored, $key, strlen($contents), hash('sha256', $contents));
            $this->assertSame($contents, file_get_contents($restored));
        }
    }

    public function test_it_rejects_wrong_keys_and_modified_ciphertext(): void
    {
        if (! function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push')) {
            $this->markTestSkipped('Sodium secretstream is not available in this PHP runtime.');
        }

        $cipher = new StorageBackupObjectCipher();
        $contents = random_bytes(1024 * 1024 + 19);
        $source = $this->temporaryFile($contents);
        $encrypted = $this->temporaryFile('');
        $restored = $this->temporaryFile('');

        $cipher->encryptFile($source, $encrypted, str_repeat('A', 64));

        try {
            $cipher->decryptFile($encrypted, $restored, str_repeat('B', 64), strlen($contents), hash('sha256', $contents));
            $this->fail('A wrong encryption key must never decrypt a backup object.');
        } catch (RuntimeException) {
            $this->assertFileDoesNotExist($restored);
        }

        $bytes = file_get_contents($encrypted);
        $this->assertIsString($bytes);
        $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 0x01);
        file_put_contents($encrypted, $bytes);

        try {
            $cipher->decryptFile($encrypted, $restored, str_repeat('A', 64), strlen($contents), hash('sha256', $contents));
            $this->fail('Modified ciphertext must never be accepted.');
        } catch (RuntimeException) {
            $this->assertFileDoesNotExist($restored);
        }
    }

    public function test_it_rejects_short_master_keys(): void
    {
        if (! function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push')) {
            $this->markTestSkipped('Sodium secretstream is not available in this PHP runtime.');
        }

        $source = $this->temporaryFile('payload');
        $encrypted = $this->temporaryFile('');

        $this->expectException(RuntimeException::class);
        (new StorageBackupObjectCipher())->encryptFile($source, $encrypted, 'too-short');
    }

    private function temporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'farast-backup-cipher-');
        if (! is_string($path)) {
            throw new RuntimeException('Could not create temporary cipher test file.');
        }

        $this->temporaryFiles[] = $path;
        file_put_contents($path, $contents);

        return $path;
    }

    public function test_encryption_purposes_derive_independent_keys(): void
    {
        if (! function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push')) {
            $this->markTestSkipped('Sodium secretstream is not available in this PHP runtime.');
        }

        $source = $this->temporaryFile('purpose-separated payload');
        $encrypted = $this->temporaryFile('');
        $wrongPurposePlain = $this->temporaryFile('');
        $key = str_repeat('purpose-key-', 8);
        $cipher = new StorageBackupObjectCipher();
        $metadata = $cipher->encryptFile($source, $encrypted, $key, 'backup');

        try {
            $cipher->decryptFile($encrypted, $wrongPurposePlain, $key, $metadata['size_bytes'], $metadata['sha256'], 'knowledge');
            $this->fail('Backup and knowledge encryption purposes must derive different keys.');
        } catch (\Throwable $exception) {
            $this->assertFileDoesNotExist($wrongPurposePlain);
        }

        $restored = $this->temporaryFile('');
        $cipher->decryptFile($encrypted, $restored, $key, $metadata['size_bytes'], $metadata['sha256'], 'backup');
        $this->assertSame('purpose-separated payload', file_get_contents($restored));
    }

}
