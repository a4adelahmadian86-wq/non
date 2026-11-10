<?php

namespace App\Services;

use App\Models\UserFile;
use App\Services\Storage\StorageManager;
use Illuminate\Support\Facades\Storage;

class UserFileRetentionService
{
    public function __construct(private readonly StorageManager $storage) {}

    public function archiveExpiredLocalFiles(int $limit = 100): int
    {
        $count = 0;
        UserFile::query()
            ->where('status', 'local')
            ->whereNotNull('local_expires_at')
            ->where('local_expires_at', '<=', now())
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get()
            ->each(function (UserFile $file) use (&$count) {
                try {
                    $oldPath = $file->path;
                    $stored = $this->storage->adoptExistingPrivate(
                        $file->path,
                        $file->original_name,
                        (string) $file->mime,
                        (int) $file->size_bytes,
                        'editor',
                        UserFile::class,
                        (int) $file->id
                    );
                    $file->update([
                        'path' => $stored['path'],
                        'disk' => $stored['disk'],
                        'status' => $stored['volume_id'] ? 'remote' : 'pending_transfer',
                        'remote_path' => $stored['volume_id'] ? $stored['path'] : null,
                        'local_expires_at' => null,
                        'remote_expires_at' => null,
                        'transferred_at' => now(),
                    ]);
                    Storage::disk('private')->delete($oldPath);
                    $count++;
                } catch (\Throwable $exception) {
                    report($exception);
                }
            });
        return $count;
    }

    /**
     * User editor files are retained indefinitely. This legacy method remains
     * for scheduler compatibility but intentionally performs no remote deletion.
     */
    public function deleteExpiredRemoteFiles(int $limit = 100): int
    {
        UserFile::query()
            ->where('status', 'remote')
            ->whereNotNull('remote_expires_at')
            ->update(['remote_expires_at' => null]);
        return 0;
    }
}