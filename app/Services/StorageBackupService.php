<?php

namespace App\Services;

use App\Models\StorageBackupRun;
use App\Models\StorageManagedFile;
use App\Models\StorageVolume;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

class StorageBackupService
{
    public function __construct(private readonly StorageConnectionService $connections)
    {
    }

    public function runWeekly(): StorageBackupRun
    {
        $target = StorageVolume::query()
            ->where('drive_key', 'foreign_backup')
            ->where('is_active', true)
            ->where('status', 'healthy')
            ->where('driver', '!=', 'local')
            ->orderBy('priority')
            ->first();

        $run = StorageBackupRun::create([
            'storage_volume_id' => $target?->id,
            'backup_type' => 'weekly',
            'source_name' => config('app.name', 'FARAST'),
            'status' => $target ? 'running' : 'failed',
            'started_at' => now(),
            'error_message' => $target ? null : 'No healthy external backup volume is configured; off-site backup was not attempted.',
        ]);

        if (! $target) {
            return $run;
        }

        $workspace = storage_path('app/backup-work/'.Str::uuid());
        $archivePath = $workspace.'/farast-weekly-backup.zip';
        $password = Str::random(64);
        $zip = null;
        $uploadedObjectKey = null;

        try {
            if (! class_exists(ZipArchive::class) || ! defined(ZipArchive::class.'::EM_AES_256')) {
                throw new \RuntimeException('Encrypted ZIP (AES-256) support is required for backups.');
            }
            if (! is_dir($workspace) && ! mkdir($workspace, 0700, true) && ! is_dir($workspace)) {
                throw new \RuntimeException('Unable to create backup workspace.');
            }

            $manifest = [
                'application' => config('app.name'),
                'created_at' => now()->toIso8601String(),
                'backup_run_id' => $run->id,
                'database' => null,
                'managed_files' => [],
                'legacy_files' => [],
            ];

            $zip = new ZipArchive();
            if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Unable to create backup archive.');
            }
            if (! $zip->setPassword($password)) {
                throw new \RuntimeException('Unable to enable archive encryption.');
            }

            $databaseFile = $workspace.'/database.dump';
            $this->dumpDatabase($databaseFile);
            $this->addEncryptedFile($zip, $databaseFile, 'database/database.dump');
            $manifest['database'] = [
                'file' => 'database/database.dump',
                'sha256' => hash_file('sha256', $databaseFile),
                'size_bytes' => filesize($databaseFile),
            ];

            StorageManagedFile::query()->where('status', 'active')->with('volume')->orderBy('id')->chunkById(100, function ($records) use ($zip, $workspace, &$manifest) {
                foreach ($records as $record) {
                    if (! $record->volume) {
                        continue;
                    }
                    $disk = $this->connections->disk($record->volume);
                    if (! $disk->exists($record->file_key)) {
                        throw new \RuntimeException('Managed source object is missing: '.$record->id);
                    }
                    $stream = $disk->readStream($record->file_key);
                    if (! is_resource($stream)) {
                        throw new \RuntimeException('Unable to read managed source object: '.$record->id);
                    }
                    $local = $workspace.'/object-'.$record->id;
                    $output = fopen($local, 'wb');
                    try {
                        if (! is_resource($output) || stream_copy_to_stream($stream, $output) === false) {
                            throw new \RuntimeException('Unable to stage managed source object: '.$record->id);
                        }
                    } finally {
                        fclose($stream);
                        if (is_resource($output)) fclose($output);
                    }
                    $checksum = hash_file('sha256', $local);
                    if ($record->checksum && ! hash_equals($record->checksum, $checksum)) {
                        throw new \RuntimeException('Managed source checksum mismatch: '.$record->id);
                    }
                    $entry = 'managed/'.$record->id.'/'.basename($record->logical_path ?: $record->file_key);
                    $this->addEncryptedFile($zip, $local, $entry);
                    $manifest['managed_files'][] = [
                        'id' => $record->id,
                        'logical_path' => $record->logical_path,
                        'entry' => $entry,
                        'sha256' => $checksum,
                        'size_bytes' => filesize($local),
                        'drive_key' => $record->drive_key,
                        'source_module' => $record->source_module,
                        'source_type' => $record->source_type,
                        'source_id' => $record->source_id,
                        'product_id' => $record->product_id,
                    ];
                    @unlink($local);
                }
            });

            foreach (['private', 'public'] as $diskName) {
                $disk = Storage::disk($diskName);
                foreach ($disk->allFiles() as $legacyPath) {
                    $local = $workspace.'/legacy-'.hash('sha256', $diskName.'/'.$legacyPath);
                    $stream = $disk->readStream($legacyPath);
                    if (! is_resource($stream)) continue;
                    $output = fopen($local, 'wb');
                    try {
                        if (is_resource($output)) stream_copy_to_stream($stream, $output);
                    } finally {
                        fclose($stream);
                        if (is_resource($output)) fclose($output);
                    }
                    $entry = 'legacy/'.$diskName.'/'.$legacyPath;
                    $this->addEncryptedFile($zip, $local, $entry);
                    $manifest['legacy_files'][] = [
                        'disk' => $diskName,
                        'path' => $legacyPath,
                        'entry' => $entry,
                        'sha256' => hash_file('sha256', $local),
                        'size_bytes' => filesize($local),
                    ];
                    @unlink($local);
                }
            }

            $manifestBytes = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (! $zip->addFromString('manifest.json', $manifestBytes) || ! $zip->setEncryptionName('manifest.json', ZipArchive::EM_AES_256)) {
                throw new \RuntimeException('Unable to add encrypted backup manifest.');
            }
            if (! $zip->close()) {
                throw new \RuntimeException('Unable to finalize backup archive.');
            }
            $zip = null;

            $size = (int) filesize($archivePath);
            $checksum = hash_file('sha256', $archivePath);
            $objectKey = 'backups/weekly/'.now()->format('Y/m/d').'/farast-'.$run->id.'-'.Str::random(12).'.zip';
            $uploadedObjectKey = $objectKey;
            $destination = $this->connections->disk($target);
            $stream = fopen($archivePath, 'rb');
            if (! is_resource($stream)) {
                throw new \RuntimeException('Unable to open backup archive for transfer.');
            }
            try {
                $destination->writeStream($objectKey, $stream, ['visibility' => 'private', 'mimetype' => 'application/zip']);
            } finally {
                fclose($stream);
            }

            if ((int) $destination->size($objectKey) !== $size) {
                throw new \RuntimeException('Backup destination size verification failed.');
            }
            $remoteStream = $destination->readStream($objectKey);
            if (! is_resource($remoteStream)) {
                throw new \RuntimeException('Backup destination cannot be read back for verification.');
            }
            $remoteHash = hash_init('sha256');
            try {
                hash_update_stream($remoteHash, $remoteStream);
            } finally {
                fclose($remoteStream);
            }
            if (! hash_equals($checksum, hash_final($remoteHash))) {
                throw new \RuntimeException('Backup destination checksum verification failed.');
            }

            $run->update([
                'status' => 'completed',
                'object_key' => $objectKey,
                'size_bytes' => $size,
                'checksum' => $checksum,
                'completed_at' => now(),
                'metadata' => [
                    'encryption' => 'zip-aes-256',
                    'password_ciphertext' => Crypt::encryptString($password),
                    'manifest_sha256' => hash('sha256', $manifestBytes),
                    'managed_file_count' => count($manifest['managed_files']),
                    'legacy_file_count' => count($manifest['legacy_files']),
                    'database_included' => true,
                ],
            ]);

            $this->pruneOldBackups($target);
        } catch (Throwable $exception) {
            if ($uploadedObjectKey && $target) {
                try { $this->connections->disk($target)->delete($uploadedObjectKey); } catch (Throwable $cleanupException) { report($cleanupException); }
            }
            if ($zip instanceof ZipArchive) {
                $zip->close();
            }
            $run->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_message' => mb_substr($exception->getMessage(), 0, 2000),
            ]);
            report($exception);
        } finally {
            if (is_dir($workspace)) {
                $this->removeDirectory($workspace);
            }
        }

        return $run->fresh();
    }

    private function dumpDatabase(string $destination): void
    {
        $connection = (string) config('database.default');
        $config = (array) config('database.connections.'.$connection, []);

        if ($connection === 'sqlite') {
            $source = (string) ($config['database'] ?? '');
            if ($source === '' || $source === ':memory:' || ! is_file($source)) {
                throw new \RuntimeException('SQLite database file is not available for backup.');
            }
            if (! copy($source, $destination)) {
                throw new \RuntimeException('Unable to copy SQLite database.');
            }
            return;
        }

        if ($connection === 'mysql' || $connection === 'mariadb') {
            $binary = (string) env('FARAST_MYSQLDUMP_BINARY', 'mysqldump');
            $process = new Process([
                $binary, '--single-transaction', '--quick', '--routines', '--triggers',
                '--host='.(string) ($config['host'] ?? '127.0.0.1'),
                '--port='.(string) ($config['port'] ?? 3306),
                '--user='.(string) ($config['username'] ?? ''),
                (string) ($config['database'] ?? ''),
                '--result-file='.$destination,
            ], base_path(), ['MYSQL_PWD' => (string) ($config['password'] ?? '')], null, 3600);
            $process->run();
            if (! $process->isSuccessful() || ! is_file($destination) || filesize($destination) < 1) {
                throw new \RuntimeException('MySQL database dump failed: '.mb_substr($process->getErrorOutput(), 0, 1000));
            }
            return;
        }

        if ($connection === 'pgsql') {
            $binary = (string) env('FARAST_PG_DUMP_BINARY', 'pg_dump');
            $process = new Process([
                $binary, '--format=custom', '--no-owner', '--no-acl',
                '--host='.(string) ($config['host'] ?? '127.0.0.1'),
                '--port='.(string) ($config['port'] ?? 5432),
                '--username='.(string) ($config['username'] ?? ''),
                '--file='.$destination,
                (string) ($config['database'] ?? ''),
            ], base_path(), ['PGPASSWORD' => (string) ($config['password'] ?? '')], null, 3600);
            $process->run();
            if (! $process->isSuccessful() || ! is_file($destination) || filesize($destination) < 1) {
                throw new \RuntimeException('PostgreSQL database dump failed: '.mb_substr($process->getErrorOutput(), 0, 1000));
            }
            return;
        }

        throw new \RuntimeException('Database driver is not supported by the backup engine: '.$connection);
    }

    private function addEncryptedFile(ZipArchive $zip, string $source, string $entry): void
    {
        if (! $zip->addFile($source, $entry) || ! $zip->setEncryptionName($entry, ZipArchive::EM_AES_256)) {
            throw new \RuntimeException('Unable to encrypt backup entry: '.$entry);
        }
    }

    private function pruneOldBackups(StorageVolume $volume): void
    {
        $keep = max(1, min(52, (int) config('storage.backup.retention_count', 8)));
        $oldRuns = StorageBackupRun::query()
            ->where('storage_volume_id', $volume->id)
            ->where('status', 'completed')
            ->latest('completed_at')
            ->skip($keep)
            ->take(500)
            ->get();

        $disk = $this->connections->disk($volume);
        foreach ($oldRuns as $old) {
            if ($old->object_key) {
                $disk->delete($old->object_key);
            }
            $old->delete();
        }
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