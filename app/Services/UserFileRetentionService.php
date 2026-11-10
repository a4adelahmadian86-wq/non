<?php

namespace App\Services;

class UserFileRetentionService
{
    public function archiveExpiredLocalFiles(int $limit = 100): int
    {
        // User files are retained indefinitely. Move legacy local files to the
        // configured foreign storage drive; do not create expiring ZIP archives.
        $result = app(StorageMigrationService::class)->migrateLegacy($limit);
        return (int) ($result['user_files'] ?? 0);
    }

    public function deleteExpiredRemoteFiles(int $limit = 100): int
    {
        // Historical remote archives are intentionally not deleted. The storage
        // policy for user files is indefinite retention unless an authorized
        // owner explicitly deletes a file.
        return 0;
    }
}
