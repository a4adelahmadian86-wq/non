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

    public function test_configured_scanner_marks_clean_upload_safe_without_shell_interpolation(): void
    {
        Storage::fake('private');
        $file = UploadedFile::fake()->image('clean.png', 2, 2);
        $path = $file->store('security-tests', 'private');
        $scanner = $this->fakeScanner('$file = $argv[2] ?? ""; if (($argv[1] ?? "") !== "--no-summary" || !is_file($file)) exit(2); exit(0);');

        try {
            $this->withScanner($scanner, function () use ($path): void {
                $result = app(UploadSecurityService::class)->inspect($path, 'image/png', 1024);

                $this->assertTrue($result['safe']);
                $this->assertSame('clean', $result['scan']);
            });
        } finally {
            @unlink($scanner);
        }
    }

    public function test_configured_scanner_rejects_infected_uploads(): void
    {
        Storage::fake('private');
        $file = UploadedFile::fake()->image('infected.png', 2, 2);
        $path = $file->store('security-tests', 'private');
        file_put_contents(Storage::disk('private')->path($path), 'EICAR-STANDARD-ANTIVIRUS-TEST-FILE');
        $scanner = $this->fakeScanner('$file = $argv[2] ?? ""; exit(is_file($file) && str_contains(file_get_contents($file), "EICAR") ? 1 : 0);');

        try {
            $this->withScanner($scanner, function () use ($path): void {
                try {
                    app(UploadSecurityService::class)->inspect($path, 'image/png', 1024);
                    $this->fail('An infected upload must be rejected.');
                } catch (\RuntimeException $exception) {
                    $this->assertSame('upload_malware_scan_failed', $exception->getMessage());
                }
            });
        } finally {
            @unlink($scanner);
        }
    }

    public function test_configured_scanner_timeout_fails_closed(): void
    {
        Storage::fake('private');
        $file = UploadedFile::fake()->image('slow-scan.png', 2, 2);
        $path = $file->store('security-tests', 'private');
        $scanner = $this->fakeScanner('sleep(2); exit(0);');
        config(['farast.security.clamscan_timeout' => 0.1]);

        try {
            $this->withScanner($scanner, function () use ($path): void {
                try {
                    app(UploadSecurityService::class)->inspect($path, 'image/png', 1024);
                    $this->fail('A timed-out malware scan must fail closed.');
                } catch (\RuntimeException $exception) {
                    $this->assertSame('upload_malware_scan_timeout', $exception->getMessage());
                }
            });
        } finally {
            @unlink($scanner);
        }
    }

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
    private function fakeScanner(string $script): string
    {
        $path = tempnam(sys_get_temp_dir(), 'farast-clamscan-');
        file_put_contents($path, "#!/usr/bin/env php\n<?php\n".$script."\n");
        chmod($path, 0755);

        return $path;
    }

    private function withScanner(string $binary, callable $callback): void
    {
        $previousEnv = getenv('FARAST_CLAMSCAN_BINARY');
        $previousServer = $_SERVER['FARAST_CLAMSCAN_BINARY'] ?? null;
        $previousSuperglobal = $_ENV['FARAST_CLAMSCAN_BINARY'] ?? null;

        try {
            putenv('FARAST_CLAMSCAN_BINARY='.$binary);
            $_SERVER['FARAST_CLAMSCAN_BINARY'] = $binary;
            $_ENV['FARAST_CLAMSCAN_BINARY'] = $binary;
            $callback();
        } finally {
            if ($previousEnv === false) putenv('FARAST_CLAMSCAN_BINARY');
            else putenv('FARAST_CLAMSCAN_BINARY='.$previousEnv);
            if ($previousServer === null) unset($_SERVER['FARAST_CLAMSCAN_BINARY']);
            else $_SERVER['FARAST_CLAMSCAN_BINARY'] = $previousServer;
            if ($previousSuperglobal === null) unset($_ENV['FARAST_CLAMSCAN_BINARY']);
            else $_ENV['FARAST_CLAMSCAN_BINARY'] = $previousSuperglobal;
        }
    }

}
