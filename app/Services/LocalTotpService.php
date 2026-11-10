<?php

namespace App\\Services;

use RuntimeException;

class LocalTotpService
{
    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(int $bytes = 20): string
    {
        $raw = random_bytes(max(16, $bytes));
        $bits = '';
        foreach (str_split($raw) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $secret = '';
        foreach (str_split($bits, 5) as $chunk) {
            if (strlen($chunk) === 5) {
                $secret .= self::BASE32[bindec($chunk)];
            }
        }

        return $secret;
    }

    public function provisioningUri(string $secret, string $account, string $issuer = 'FARAST'): string
    {
        $label = rawurlencode($issuer.':'.$account);

        return 'otpauth://totp/'.$label.'?secret='.rawurlencode($secret)
            .'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits=6&period=30';
    }

    public function verify(string $secret, string $code, ?int $timestamp = null, int $window = 1): bool
    {
        $code = preg_replace('/\\s+/', '', $code);
        if (! preg_match('/^\\d{6}$/', $code)) {
            return false;
        }

        $timestamp ??= time();
        $counter = intdiv($timestamp, 30);

        for ($offset = -max(0, $window); $offset <= max(0, $window); $offset++) {
            $expected = $this->codeAtCounter($secret, $counter + $offset);
            if (hash_equals($expected, $code)) {
                return true;
            }
        }

        return false;
    }

    public function codeAtCounter(string $secret, int $counter): string
    {
        $key = $this->decodeBase32($secret);
        $high = ($counter >> 32) & 0xffffffff;
        $low = $counter & 0xffffffff;
        $hash = hash_hmac('sha1', pack('N2', $high, $low), $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $binary = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);

        return str_pad((string) ($binary % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public function generateRecoveryCodes(int $count = 10): array
    {
        $codes = [];
        for ($i = 0; $i < max(1, min(20, $count)); $i++) {
            $hex = strtoupper(bin2hex(random_bytes(6)));
            $codes[] = implode('-', str_split($hex, 4));
        }

        return $codes;
    }

    public function normalizeRecoveryCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    private function decodeBase32(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret));
        if ($secret === '') {
            throw new RuntimeException('Invalid TOTP secret.');
        }

        $bits = '';
        foreach (str_split($secret) as $char) {
            $position = strpos(self::BASE32, $char);
            if ($position === false) {
                throw new RuntimeException('Invalid TOTP secret.');
            }
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $binary = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $binary .= chr(bindec($byte));
            }
        }

        if (strlen($binary) < 10) {
            throw new RuntimeException('TOTP secret is too short.');
        }

        return $binary;
    }
}
