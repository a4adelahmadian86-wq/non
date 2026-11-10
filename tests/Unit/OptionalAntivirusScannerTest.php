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

        $scanner = app(OptionalAntivirusScanner::class);

        $this->assertFalse($scanner->configured());
    }

    public function test_explicit_antivirus_request_fails_clearly_when_engine_is_unavailable(): void
    {
        config(['services.clamav.binary' => '']);

        try {
            app(OptionalAntivirusScanner::class)->scan('/tmp/not-a-real-upload');
            $this->fail('A requested antivirus scan must never be reported as successful without an engine.');
        } catch (RuntimeException $exception) {
            $this->assertSame('antivirus_scanner_unavailable', $exception->getMessage());
        }
    }

    public function test_only_admin_or_explicitly_granted_role_can_request_optional_scan(): void
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
