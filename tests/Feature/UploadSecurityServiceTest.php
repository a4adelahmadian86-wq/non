<?php

namespace Tests\Feature;

use App\Services\UploadSecurityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UploadSecurityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_is_not_marked_safe_when_malware_scanner_is_not_configured(): void
    {
        Storage::fake('private');
        $file = UploadedFile::fake()->image('unscanned.png', 2, 2);
        $path = $file->store('security-tests', 'private');

        $previousEnv = getenv('FARAST_CLAMSCAN_BINARY');
        $previousServer = $_SERVER['FARAST_CLAMSCAN_BINARY'] ?? null;
        $previousSuperglobal = $_ENV['FARAST_CLAMSCAN_BINARY'] ?? null;

        try {
            putenv('FARAST_CLAMSCAN_BINARY=');
            $_SERVER['FARAST_CLAMSCAN_BINARY'] = '';
            $_ENV['FARAST_CLAMSCAN_BINARY'] = '';

            $result = app(UploadSecurityService::class)->inspect($path, 'image/png', 1024);

            $this->assertFalse($result['safe']);
            $this->assertSame('not_configured', $result['scan']);
            $this->assertTrue($result['sandbox_required']);
        } finally {
            if ($previousEnv === false) {
                putenv('FARAST_CLAMSCAN_BINARY');
            } else {
                putenv('FARAST_CLAMSCAN_BINARY='.$previousEnv);
            }
            if ($previousServer === null) unset($_SERVER['FARAST_CLAMSCAN_BINARY']);
            else $_SERVER['FARAST_CLAMSCAN_BINARY'] = $previousServer;
            if ($previousSuperglobal === null) unset($_ENV['FARAST_CLAMSCAN_BINARY']);
            else $_ENV['FARAST_CLAMSCAN_BINARY'] = $previousSuperglobal;
        }
    }
}
