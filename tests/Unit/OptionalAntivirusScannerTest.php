<?php

namespace Tests\\Unit;

use App\\Models\\User;
use App\\Services\\AuthorizationService;
use App\\Services\\OptionalAntivirusScanner;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use RuntimeException;
use Tests\\TestCase;

class OptionalAntivirusScannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_antivirus_is_optional_and_disabled_by_default(): void
    {
        config(['services.clamav.binary' => '']);

        $this->assertFalse(app(OptionalAntivirusScanner::class)->configured());
    }

    public function test_requested_scan_fails_closed_when_clamav_is_unavailable(): void
    {
        config(['services.clamav.binary' => '']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('antivirus_scanner_unavailable');

        app(OptionalAntivirusScanner::class)->scan('/tmp/does-not-matter');
    }

    public function test_clamav_clean_exit_is_recorded_with_engine_and_version(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'farast-source-');
        $binary = tempnam(sys_get_temp_dir(), 'farast-clamav-');
        file_put_contents($source, 'sample');
        file_put_contents($binary, <<<'SH'
#!/bin/sh
if [ "$1" = "--version" ]; then echo 'ClamAV 1.4.0'; exit 0; fi
exit 0
SH
        );
        chmod($binary, 0700);
        config(['services.clamav.binary' => $binary]);

        try {
            $result = app(OptionalAntivirusScanner::class)->scan($source);
            $this->assertSame('clean', $result['status']);
            $this->assertSame('clamav', $result['engine']);
            $this->assertStringContainsString('ClamAV 1.4.0', $result['version']);
            $this->assertNotEmpty($result['scanned_at']);
        } finally {
            @unlink($source);
            @unlink($binary);
        }
    }

    public function test_clamav_detection_is_never_treated_as_clean(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'farast-source-');
        $binary = tempnam(sys_get_temp_dir(), 'farast-clamav-');
        file_put_contents($source, 'sample');
        file_put_contents($binary, <<<'SH'
#!/bin/sh
if [ "$1" = "--version" ]; then echo 'ClamAV 1.4.0'; exit 0; fi
exit 1
SH
        );
        chmod($binary, 0700);
        config(['services.clamav.binary' => $binary]);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('antivirus_malware_detected');
            app(OptionalAntivirusScanner::class)->scan($source);
        } finally {
            @unlink($source);
            @unlink($binary);
        }
    }

    public function test_admin_can_request_optional_scan_but_employee_cannot_by_default(): void
    {
        $admin = User::create([
            'name' => 'Root',
            'mobile' => '09120000001',
            'password' => 'secret',
            'role' => User::ROLE_ADMIN,
        ]);
        $employee = User::create([
            'name' => 'Employee',
            'mobile' => '09120000002',
            'password' => 'secret',
            'role' => User::ROLE_EMPLOYEE,
        ]);

        $authorization = app(AuthorizationService::class);
        $this->assertTrue($authorization->allows($admin, 'store.security_scan'));
        $this->assertFalse($authorization->allows($employee, 'store.security_scan'));
    }
}
