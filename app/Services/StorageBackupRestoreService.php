<?php

namespace App\Services;

use App\Models\StorageBackupRun;
use App\Models\StorageManagedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class StorageBackupRestoreService
{
    public function restoreFiles(int $runId, bool $overwrite = false): array
    {
        $run = StorageBackupRun::query()->with('volume')->whereKey($runId)->where('status', 'completed')->firstOrFail();
        abort_unless($run->volume && $run->object_key, 404, 'Backup destination is missing.');
        $ciphertext = data_get($run->metadata, 'password_ciphertext');
        abort_unless(is_string($ciphertext) && $ciphertext !== '', 409, 'Backup decryption key is not available.');

        $workspace = storage_path('app/restore-work/'.Str::uuid());
        if (! is_dir($workspace) && ! mkdir($workspace, 0700, true) && ! is_dir($workspace)) {
            throw new RuntimeException('Unable to create restore workspace.');
        }

        try {
            $archivePath = $workspace.'/backup.zip';
            $disk = app(StorageConnectionService::class)->disk($run->volume);
            $stream = $disk->readStream($run->object_key);
            if (! is_resource($stream)) {
                throw new RuntimeException('Unable to read backup archive.');
            }
            $output = fopen($archivePath, 'wb');
            try {
                if (! is_resource($output) || stream_copy_to_stream($stream, $output) === false) {
                    throw new RuntimeException('Unable to stage backup archive.');
                }
            } finally {
                fclose($stream);
                if (is_resource($output)) fclose($output);
            }

            if (! hash_equals((string) $run->checksum, (string) hash_file('sha256', $archivePath))) {
                throw new RuntimeException('Backup archive checksum mismatch.');
            }

            $zip = new ZipArchive();
            if ($zip->open($archivePath) !== true) {
                throw new RuntimeException('Backup archive cannot be opened.');
            }
            $password = Crypt::decryptString($ciphertext);
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if ($name === '' || str_starts_with($name, '/') || str_contains($name, '\') || in_array('..', explode('/', $name), true) || str_contains($name, "\0")) {
                    $zip->close();
                    throw new RuntimeException('Backup contains an unsafe archive entry.');
                }
            }
            $zip->setPassword($password);
            if (! $zip->extractTo($workspace)) {
                $zip->close();
                throw new RuntimeException('Backup decryption or extraction failed.');
            }
            $zip->close();

            $manifestPath = $workspace.'/manifest.json';
            if (! is_file($manifestPath)) {
                throw new RuntimeException('Backup manifest is missing.');
            }
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            $restored = ['managed' => 0, 'legacy' => 0, 'skipped' => 0];

            foreach ((array) ($manifest['managed_files'] ?? []) as $item) {
                $entry = (string) ($item['entry'] ?? '');
                $logicalPath = (string) ($item['logical_path'] ?? '');
                $source = $workspace.'/'.$entry;
                if (! str_starts_with($entry, 'managed/') || str_contains($entry, '..') || $logicalPath === '' || ! is_file($source) || ! hash_equals((string) ($item['sha256'] ?? ''), (string) hash_file('sha256', $source))) {
                    throw new RuntimeException('Managed backup entry failed integrity verification.');
                }

                $target = Storage::disk('managed');
                $existingRecord = StorageManagedFile::query()->where('logical_path', $logicalPath)->where('status', 'active')->first();
                if ($existingRecord && ! $overwrite) {
                    try {
                        $existingHash = hash('sha256', (string) $target->get($logicalPath));
                        if (hash_equals((string) $item['sha256'], $existingHash)) {
                            $restored['skipped']++;
                            continue;
                        }
                    } catch (\Throwable $exception) {
                        report($exception);
                    }
                    $restored['skipped']++;
                    continue;
                }

                $fileStream = fopen($source, 'rb');
                try {
                    $ok = $target->put($logicalPath, $fileStream, [
                        'farast_classification' => ($item['classification'] ?? (($item['drive_key'] ?? '') === 'foreign_primary' ? 'non_critical' : 'critical')),
                        'farast_source_type' => $item['source_type'] ?? 'restored_file',
                        'farast_original_name' => $item['original_name'] ?? basename($logicalPath),
                        'mimetype' => $item['mime'] ?? null,
                        'farast_source_id' => $item['source_id'] ?? null,
                        'farast_product_id' => $item['product_id'] ?? null,
                        'farast_owner_user_id' => $item['owner_user_id'] ?? null,
                        'farast_original_name' => basename($logicalPath),
                    ]);
                } finally {
                    fclose($fileStream);
                }
                if (! $ok) {
                    throw new RuntimeException('Unable to restore managed file: '.$logicalPath);
                }
                $restored['managed']++;
            }

            foreach ((array) ($manifest['legacy_files'] ?? []) as $item) {
                $diskName = (string) ($item['disk'] ?? '');
                $path = (string) ($item['path'] ?? '');
                $entry = (string) ($item['entry'] ?? '');
                $source = $workspace.'/'.$entry;
                if (! str_starts_with($entry, 'legacy/') || str_contains($entry, '..') || ! in_array($diskName, ['private', 'public', 'farast_remote'], true) || $path === '' || ! is_file($source)
                    || ! hash_equals((string) ($item['sha256'] ?? ''), (string) hash_file('sha256', $source))) {
                    throw new RuntimeException('Legacy backup entry failed integrity verification.');
                }
                $target = Storage::disk($diskName);
                if ($target->exists($path) && ! $overwrite) {
                    $restored['skipped']++;
                    continue;
                }
                $legacyStream = fopen($source, 'rb');
                if (! is_resource($legacyStream)) {
                    throw new RuntimeException('Unable to open legacy restore source: '.$path);
                }
                try {
                    $ok = $target->put($path, $legacyStream);
                } finally {
                    fclose($legacyStream);
                }
                if (! $ok) {
                    throw new RuntimeException('Unable to restore legacy file: '.$path);
                }
                $restored['legacy']++;
            }

            $databaseDump = $workspace.'/database/database.dump';
            if (! is_file($databaseDump) || ! hash_equals((string) data_get($manifest, 'database.sha256'), (string) hash_file('sha256', $databaseDump))) {
                throw new RuntimeException('Database backup failed integrity verification.');
            }
            $restoreDirectory = storage_path('app/restore-staging');
            if (! is_dir($restoreDirectory) && ! mkdir($restoreDirectory, 0700, true) && ! is_dir($restoreDirectory)) {
                throw new RuntimeException('Unable to create secure restore staging directory.');
            }
            @chmod($restoreDirectory, 0700);
            $databaseOutput = $restoreDirectory.'/backup-'.$run->id.'-database.dump';
            if (! copy($databaseDump, $databaseOutput)) {
                throw new RuntimeException('Unable to preserve database dump for manual restoration.');
            }

            @chmod($databaseOutput, 0600);
            $restored['database_dump'] = $databaseOutput;
            return $restored;
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function password(int $runId): string
    {
        $run = StorageBackupRun::query()->whereKey($runId)->where('status', 'completed')->firstOrFail();
        $ciphertext = data_get($run->metadata, 'password_ciphertext');
        abort_unless(is_string($ciphertext) && $ciphertext !== '', 404);
        return Crypt::decryptString($ciphertext);
    }

    private function removeDirectory(string $path): void
    {
        foreach (glob($path.'/*') ?: [] as $item) {
            if (is_dir($item)) $this->removeDirectory($item);
            else @unlink($item);
        }
        @rmdir($path);
    }
}