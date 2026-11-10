<?php

namespace Tests\Unit;

use App\Services\LocalTotpService;
use PHPUnit\Framework\TestCase;

class LocalTotpServiceTest extends TestCase
{
    public function test_totp_matches_rfc_6238_sha1_test_vector(): void
    {
        $service = new LocalTotpService();
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

        $this->assertSame('287082', $service->codeAtCounter($secret, 1));
        $this->assertTrue($service->verify($secret, '287082', 59, 0));
        $this->assertFalse($service->verify($secret, '287083', 59, 0));
    }

    public function test_recovery_codes_are_normalized_for_single_use_hashing(): void
    {
        $service = new LocalTotpService();
        $codes = $service->generateRecoveryCodes(10);

        $this->assertCount(10, $codes);
        $this->assertSame(12, strlen($service->normalizeRecoveryCode($codes[0])));
        $this->assertMatchesRegularExpression('/^[A-F0-9]{4}(-[A-F0-9]{4}){2}$/', $codes[0]);
    }
}
