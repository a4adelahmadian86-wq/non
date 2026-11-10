<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\OptionalAntivirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class OptionalAntivirusScannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_antivirus_is_not_required_or_run_when_not_requested(): void
    {
        config(['services.clamav.binary' => '']);
        $this->assertFalse(app(OptionalAntivirusScanner::class)->configured());
    }

    public function test_explicit_antivirus_request_fails_clearly_when_engine_is_unavailable(): void
    {
        config(['services.clamav.binary' => '']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('antivirus_scanner_unavailable');
        app(OptionalAntivirusScanner::class)->scan('/tmp/not-a-real-upload');
    }

    public function test_clamav_success_is_recorded_as_a_real_clean_scan(): void
    {
        $binary = tempnam(sys_get_temp_dir(), 'farast-clamav-');
        file_put_contents($binary, "#!/bin/sh\nif [ \"$1\" = \"--version\" ]; then echo 'ClamAV 1.4.0'; exit 0; fi\nexit 0\n");
        chmod($binary, 0700);
        config(['services.clamav.binary' => $binary]);
        $sample = tempnam(sys_get_temp_dir(), 'farast-sample-');
        file_put_contents($sample, 'sample');
        try {
            $result = app(OptionalAntivirusScanner::class)->scan($sample);
            $this->assertSame('clean', $result['status']);
            $this->assertSame('clamav', $result['engine']);
            $this->assertStringContainsString('ClamAV 1.4.0', $result['version']);
            $this->assertNotEmpty($result['scanned_at']);
        } finally {
            @unlink($binary);
            @unlink($sample);
        }
    }

    public function test_clamav_detection_is_never_reported_as_clean(): void
    {
        $binary = tempnam(sys_get_temp_dir(), 'farast-clamav-');
        file_put_contents($binary, "#!/bin/sh\nif [ \"$1\" = \"--version\" ]; then echo 'ClamAV 1.4.0'; exit 0; fi\nexit 1\n");
        chmod($binary, 0700);
        config(['services.clamav.binary' => $binary]);
        $sample = tempnam(sys_get_temp_dir(), 'farast-sample-');
        file_put_contents($sample, 'sample');
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('antivirus_malware_detected');
            app(OptionalAntivirusScanner::class)->scan($sample);
        } finally {
            @unlink($binary);
            @unlink($sample);
        }
    }

    public function test_only_admin_or_explicitly_granted_role_can_request_optional_scan(): void
    {
        $admin = User::create(['name' => 'Root', 'mobile' => '09120000001', 'password' => 'secret', 'role' => User::ROLE_ADMIN]);
        $employee = User::create(['name' => 'Employee', 'mobile' => '09120000002', 'password' => 'secret', 'role' => User::ROLE_EMPLOYEE]);
        $authorization = app(AuthorizationService::class);
        $this->assertTrue($authorization->allows($admin, 'store.security_scan'));
        $this->assertFalse($authorization->allows($employee, 'store.security_scan'));
    }
}
