<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePrivateStorageDisk
{
    public function handle(Request $request, Closure $next): Response
    {
        $root = storage_path('app/private');
        $private = (array) config('filesystems.disks.private', []);

        $needsRepair = ($private['driver'] ?? null) !== 'local'
            || ($private['root'] ?? null) !== $root
            || ($private['throw'] ?? false) !== true;

        if ($needsRepair) {
            config([
                'filesystems.disks.private' => [
                    'driver' => 'local',
                    'root' => $root,
                    // Private file writes must fail loudly; a false return can otherwise
                    // leave database records pointing at files that were never persisted.
                    'throw' => true,
                ],
            ]);

            // Drop any resolved disk so this request uses the repaired configuration.
            app('filesystem')->forgetDisk('private');
        }

        if (! is_dir($root)) {
            @mkdir($root, 0775, true);
        }

        return $next($request);
    }
}
