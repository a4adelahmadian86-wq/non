<?php

namespace App\Services\Storage;

use RuntimeException;

class StorageBackupObjectCipher
{
    private const MAGIC = 'FARASTSS1';
    private const CHUNK_BYTES = 1024 * 1024;

    public function encryptFile(string $sourcePath, string $targetPath, string $masterKey, string $purpose = 'backup'): array
    {
        $this->assertAvailable($masterKey);
        $size = @filesize($sourcePath);
        if (! is_int($size) || $size < 0) {
            throw new RuntimeException('اندازه فایل برای رمزنگاری بکاپ قابل خواندن نیست.');
        }

        $input = @fopen($sourcePath, 'rb');
        $output = @fopen($targetPath, 'wb');
        if (! is_resource($input) || ! is_resource($output)) {
            if (is_resource($input)) fclose($input);
            if (is_resource($output)) fclose($output);
            throw new RuntimeException('فایل موقت رمزنگاری بکاپ قابل ایجاد نیست.');
        }

        $key = $this->deriveKey($masterKey, $purpose);
        $completed = false;
        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            $this->writeAll($output, self::MAGIC.$header);

            $remaining = $size;
            if ($remaining === 0) {
                $ciphertext = sodium_crypto_secretstream_xchacha20poly1305_push(
                    $state,
                    '',
                    '',
                    SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                );
                $this->writeAll($output, $ciphertext);
            }

            while ($remaining > 0) {
                $length = min(self::CHUNK_BYTES, $remaining);
                $plaintext = $this->readExact($input, $length);
                $remaining -= strlen($plaintext);
                $tag = $remaining === 0
                    ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                    : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
                $ciphertext = sodium_crypto_secretstream_xchacha20poly1305_push($state, $plaintext, '', $tag);
                $this->writeAll($output, $ciphertext);
                sodium_memzero($plaintext);
            }

            fflush($output);
            if (function_exists('fsync')) @fsync($output);
            $completed = true;
        } finally {
            sodium_memzero($key);
            fclose($input);
            fclose($output);
            if (! $completed) @unlink($targetPath);
        }

        $encryptedSize = @filesize($targetPath);
        $checksum = @hash_file('sha256', $sourcePath);
        if (! is_int($encryptedSize) || ! is_string($checksum)) {
            throw new RuntimeException('اعتبارسنجی فایل رمزنگاری‌شده بکاپ ناموفق بود.');
        }

        @chmod($targetPath, 0600);

        return ['size_bytes' => $size, 'encrypted_size_bytes' => $encryptedSize, 'sha256' => $checksum];
    }

    public function decryptFile(
        string $sourcePath,
        string $targetPath,
        string $masterKey,
        int $expectedSize,
        string $expectedSha256,
        string $purpose = 'backup'
    ): void {
        $this->assertAvailable($masterKey);
        if ($expectedSize < 0 || ! preg_match('/^[a-f0-9]{64}$/i', $expectedSha256)) {
            throw new RuntimeException('مشخصات فایل رمزگشایی بکاپ نامعتبر است.');
        }

        $input = @fopen($sourcePath, 'rb');
        $output = @fopen($targetPath, 'wb');
        if (! is_resource($input) || ! is_resource($output)) {
            if (is_resource($input)) fclose($input);
            if (is_resource($output)) fclose($output);
            throw new RuntimeException('فایل موقت رمزگشایی بکاپ قابل ایجاد نیست.');
        }

        $key = $this->deriveKey($masterKey, $purpose);
        $completed = false;
        $hash = hash_init('sha256');
        $bytesWritten = 0;
        try {
            $magic = $this->readExact($input, strlen(self::MAGIC));
            if (! hash_equals(self::MAGIC, $magic)) {
                throw new RuntimeException('شناسه قالب فایل رمزنگاری‌شده بکاپ معتبر نیست.');
            }
            $header = $this->readExact($input, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
            if ($state === false) {
                throw new RuntimeException('سرآیند رمزنگاری بکاپ قابل اعتبارسنجی نیست.');
            }

            $remaining = $expectedSize;
            if ($remaining === 0) {
                $this->pullFrame($input, $output, $state, 0, true, $hash, $bytesWritten);
            }

            while ($remaining > 0) {
                $length = min(self::CHUNK_BYTES, $remaining);
                $this->pullFrame(
                    $input,
                    $output,
                    $state,
                    $length,
                    $remaining === $length,
                    $hash,
                    $bytesWritten
                );
                $remaining -= $length;
            }

            if (fread($input, 1) !== '') {
                throw new RuntimeException('داده اضافی در انتهای فایل رمزنگاری‌شده بکاپ وجود دارد.');
            }

            $actualSha256 = hash_final($hash);
            if ($bytesWritten !== $expectedSize || ! hash_equals(strtolower($expectedSha256), $actualSha256)) {
                throw new RuntimeException('اندازه یا SHA-256 فایل رمزگشایی‌شده با Manifest بکاپ مطابقت ندارد.');
            }
            fflush($output);
            if (function_exists('fsync')) @fsync($output);
            $completed = true;
        } finally {
            sodium_memzero($key);
            fclose($input);
            fclose($output);
            if (! $completed) @unlink($targetPath);
        }

        @chmod($targetPath, 0600);
    }

    private function pullFrame($input, $output, &$state, int $plainLength, bool $final, $hash, int &$bytesWritten): void
    {
        $ciphertext = $this->readExact(
            $input,
            $plainLength + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES
        );
        $pulled = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $ciphertext);
        if ($pulled === false) {
            throw new RuntimeException('اعتبارسنجی رمزنگاری یکی از بخش‌های بکاپ ناموفق بود.');
        }

        [$plaintext, $tag] = $pulled;
        $expectedTag = $final
            ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
            : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
        if ($tag !== $expectedTag || strlen($plaintext) !== $plainLength) {
            sodium_memzero($plaintext);
            throw new RuntimeException('ترتیب یا اندازه بخش‌های فایل رمزنگاری‌شده بکاپ معتبر نیست.');
        }

        $this->writeAll($output, $plaintext);
        hash_update($hash, $plaintext);
        $bytesWritten += strlen($plaintext);
        sodium_memzero($plaintext);
    }

    private function assertAvailable(string $masterKey): void
    {
        if (! function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push')
            || ! function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_pull')) {
            throw new RuntimeException('افزونه Sodium برای رمزنگاری امن و جریانی بکاپ در دسترس نیست.');
        }
        if (strlen($masterKey) < 32) {
            throw new RuntimeException('کلید رمزنگاری بکاپ باید حداقل ۳۲ بایت باشد.');
        }
    }

    private function deriveKey(string $masterKey, string $purpose): string
    {
        if (! in_array($purpose, ['backup', 'knowledge'], true)) {
            throw new RuntimeException('دامنه کلید رمزنگاری ذخیره‌سازی نامعتبر است.');
        }

        return hash_hmac('sha256', 'farast-'.$purpose.'-object-encryption-v1', $masterKey, true);
    }

    private function readExact($stream, int $length): string
    {
        $buffer = '';
        while (strlen($buffer) < $length) {
            $chunk = fread($stream, $length - strlen($buffer));
            if (! is_string($chunk) || $chunk === '') {
                throw new RuntimeException('خواندن کامل فایل رمزنگاری‌شده بکاپ ناموفق بود.');
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }

    private function writeAll($stream, string $bytes): void
    {
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $written = fwrite($stream, substr($bytes, $offset));
            if (! is_int($written) || $written < 1) {
                throw new RuntimeException('نوشتن کامل فایل رمزنگاری‌شده بکاپ ناموفق بود.');
            }
            $offset += $written;
        }
    }
}
