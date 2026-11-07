<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePrivateStorageDisk;
use Illuminate\Http\Request;
use Tests\TestCase;

class EnsurePrivateStorageDiskTest extends TestCase
{
    public function test_private_storage_is_forced_to_local_and_throws_on_write_failures(): void
    {
        config([
            'filesystems.disks.private' => [
                'driver' => 's3',
                'root' => '/tmp/untrusted-private-root',
                'throw' => false,
            ],
        ]);

        $response = (new EnsurePrivateStorageDisk())->handle(
            Request::create('/up', 'GET'),
            fn () => response('ok')
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('local', config('filesystems.disks.private.driver'));
        $this->assertSame(storage_path('app/private'), config('filesystems.disks.private.root'));
        $this->assertTrue(config('filesystems.disks.private.throw'));
    }

    public function test_existing_private_disk_is_also_upgraded_to_throw_on_failure(): void
    {
        config([
            'filesystems.disks.private' => [
                'driver' => 'local',
                'root' => storage_path('app/private'),
                'throw' => false,
            ],
        ]);

        (new EnsurePrivateStorageDisk())->handle(
            Request::create('/up', 'GET'),
            fn () => response('ok')
        );

        $this->assertTrue(config('filesystems.disks.private.throw'));
    }
}
