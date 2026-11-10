<?php

namespace App\Http\Controllers;

use App\Models\StorageManagedFile;
use App\Services\Storage\StorageManager;
use Symfony\Component\HttpFoundation\Response;

class ManagedStoragePublicController extends Controller
{
    public function show(StorageManagedFile $file, StorageManager $storage): Response
    {
        abort_unless($file->visibility === 'public', 404);
        abort_unless(in_array($file->status, ['active', 'pending_transfer', 'transfer_error'], true), 404);
        abort_unless(in_array(strtolower((string) $file->mime), ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true), 404);

        $disk = (string) data_get(
            $file->metadata,
            'disk',
            $file->storage_volume_id ? 'storage_volume:'.$file->storage_volume_id : 'private'
        );
        abort_unless($storage->exists($disk, $file->file_key), 404);

        $contents = $storage->get($disk, $file->file_key);
        $etag = '"'.(string) ($file->checksum ?: hash('sha256', $contents)).'"';

        return response($contents, 200, [
            'Content-Type' => strtolower((string) $file->mime),
            'Content-Length' => (string) strlen($contents),
            'Content-Disposition' => 'inline; filename="asset-'.$file->id.'"',
            'Cache-Control' => 'public, max-age=3600, stale-while-revalidate=86400',
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
            'Cross-Origin-Resource-Policy' => 'cross-origin',
        ]);
    }
}
