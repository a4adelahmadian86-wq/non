<?php

namespace Tests\\Feature;

use App\\Services\\UploadSecurityService;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Illuminate\\Http\\UploadedFile;
use Illuminate\\Support\\Facades\\Storage;
use Tests\\TestCase;

class UploadSecurityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_static_policy_scanner_can_pass_a_valid_image_without_clamav(): void
    {
        Storage::fake('private');
        $file = UploadedFile::fake()->image('locally-checked.png', 2, 2);
        $path = $file->store('security-tests', 'private');

        $result = app(UploadSecurityService::class)->inspect($path, 'image/png', 1024);

        $this->assertTrue($result['safe']);
        $this->assertSame('clean', $result['scan']);
        $this->assertSame('farast-static-policy-v1', $result['scan_engine']);
        $this->assertTrue($result['sandbox_required']);
        $this->assertStringContainsString('not a signature-based antivirus', strtolower($result['scan_disclaimer']));
    }
}
