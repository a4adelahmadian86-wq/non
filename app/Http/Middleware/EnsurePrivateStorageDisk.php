<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class EnsurePrivateStorageDisk
{
    public function handle(Request $request, Closure $next): Response
    {
        $root = storage_path('app/private');
        $private = (array) config('filesystems.disks.private', []);

        $needsRepair = ($private['driver'] ?? null) !== 'local' || ($private['root'] ?? null) !== $root;
        if ($needsRepair) {
            config([
                'filesystems.disks.private' => [
                    'driver' => 'local',
                    'root' => $root,
                    'throw' => false,
                ],
            ]);

            // Laravel caches resolved disks in FilesystemManager. Forget the stale
            // instance so a previously cached configuration cannot survive this request.
            try {
                app('filesystem')->forgetDisk('private');
            } catch (\Throwable) {
                // The config above is still authoritative when the manager has no forgetDisk method.
            }
        }

        if (! is_dir($root)) {
            @mkdir($root, 0775, true);
        }

        return $next($request);
    }
}
