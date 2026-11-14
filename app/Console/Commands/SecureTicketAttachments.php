<?php

namespace App\Console\Commands;

use App\Models\TicketMessage;
use App\Services\Storage\StorageManager;
use App\Services\UploadSecurityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class SecureTicketAttachments extends Command
{
    protected $signature = 'farast:secure-ticket-attachments {--limit=500}';

    protected $description = 'Inspect, privatize and register legacy support attachments in managed storage';

    private function deleteLegacySource(StorageManager $storage, string $disk, string $path): void
    {
        if ($disk === 'public') {
            if (Storage::disk('public')->exists($path) && ! Storage::disk('public')->delete($path)) {
                throw new \RuntimeException('legacy_public_source_delete_failed');
            }

            return;
        }

        $storage->delete($disk, $path);
    }

    private function isReferencedByAnotherMessage(int $messageId, string $path): bool
    {
        // JSON encoders may persist forward slashes either literally or escaped.
        // Narrow candidates in SQL, then compare decoded attachment paths exactly.
        $patterns = array_unique([$path, str_replace('/', chr(92).'/', $path)]);
        $candidates = TicketMessage::query()
            ->where('id', '!=', $messageId)
            ->whereNotNull('attachments')
            ->where(function ($query) use ($patterns): void {
                foreach ($patterns as $pattern) {
                    $query->orWhere('attachments', 'like', '%'.$pattern.'%');
                }
            })
            ->get(['attachments']);

        return $candidates->contains(function (TicketMessage $candidate) use ($path): bool {
            foreach ((array) ($candidate->attachments ?? []) as $attachment) {
                if (is_array($attachment) && (string) ($attachment['path'] ?? '') === $path) {
                    return true;
                }
            }

            return false;
        });
    }

    public function handle(UploadSecurityService $security, StorageManager $storage): int
    {
        $limit = max(1, min((int) $this->option('limit'), 1000));
        $processedMessages = 0;
        $migratedAttachments = 0;
        $quarantinedAttachments = 0;
        $failedAttachments = 0;

        TicketMessage::query()
            ->whereNotNull('attachments')
            ->orderBy('id')
            ->chunkById(100, function ($messages) use (
                &$processedMessages,
                &$migratedAttachments,
                &$quarantinedAttachments,
                &$failedAttachments,
                $limit,
                $security,
                $storage
            ): bool {
                foreach ($messages as $message) {
                    if ($processedMessages >= $limit) {
                        return false;
                    }

                    $attachments = array_values((array) ($message->attachments ?? []));
                    $changed = false;
                    $createdManaged = [];
                    $createdQuarantine = [];
                    $createdStaging = [];
                    $sourceCleanup = [];

                    foreach ($attachments as $index => $attachment) {
                        if (! is_array($attachment)) {
                            continue;
                        }

                        // Retry cleanup of an old source if a previous batch
                        // saved the managed reference but could not remove the
                        // original file (or another message still referenced it).
                        if (! empty($attachment['legacy_source_path_b64'])) {
                            $legacyPath = base64_decode((string) $attachment['legacy_source_path_b64'], true);
                            $legacyDisk = (string) ($attachment['legacy_source_disk'] ?? 'public');
                            if (is_string($legacyPath) && $legacyPath !== '') {
                                $stillReferenced = $this->isReferencedByAnotherMessage((int) $message->id, $legacyPath);

                                if (! $stillReferenced) {
                                    try {
                                        $this->deleteLegacySource($storage, $legacyDisk, $legacyPath);
                                        unset($attachment['legacy_source_path_b64'], $attachment['legacy_source_disk']);
                                        $attachments[$index] = $attachment;
                                        $changed = true;
                                    } catch (Throwable $exception) {
                                        $failedAttachments++;
                                        $this->warn("Could not retry old attachment cleanup for ticket message {$message->id}.");
                                    }
                                }
                            }
                        }

                        if (! empty($attachment['managed_file_id']) || ! empty($attachment['quarantined'])) {
                            continue;
                        }

                        $sourceDisk = (string) ($attachment['disk'] ?? 'public');
                        $sourcePath = (string) ($attachment['path'] ?? '');
                        $isVolume = preg_match('/^storage_volume:[0-9]+$/', $sourceDisk) === 1;

                        if ($sourcePath === ''
                            || str_starts_with($sourcePath, '/')
                            || preg_match('~(^|/)\.\.?(/|$)~', $sourcePath) === 1
                            || (! in_array($sourceDisk, ['public', 'private', 'local'], true) && ! $isVolume)) {
                            continue;
                        }

                        try {
                            $exists = $isVolume
                                ? $storage->exists($sourceDisk, $sourcePath)
                                : Storage::disk($sourceDisk)->exists($sourcePath);
                        } catch (Throwable $exception) {
                            $failedAttachments++;
                            $this->warn("Could not inspect source for ticket message {$message->id}.");
                            continue;
                        }

                        if (! $exists) {
                            continue;
                        }

                        $sourceName = basename(str_replace('\\', '/', $sourcePath));
                        $originalName = basename(str_replace('\\', '/', (string) ($attachment['name'] ?? $sourceName)));
                        $originalName = preg_replace('/[\r\n"]+/', '_', $originalName) ?: 'attachment';
                        $stagingPath = 'ticket-attachments/migration/'.$message->id.'/'.Str::uuid().'-'.$sourceName;
                        $stagingCreated = false;

                        if ($sourceDisk === 'private') {
                            // Reuse the private source as the scan input. It is
                            // deleted only after the managed reference is saved.
                            $stagingPath = $sourcePath;
                        } else {
                            try {
                                Storage::disk('private')->makeDirectory(dirname($stagingPath));

                                if ($isVolume) {
                                    $storage->downloadTo(
                                        $sourceDisk,
                                        $sourcePath,
                                        Storage::disk('private')->path($stagingPath)
                                    );
                                } else {
                                    $stream = Storage::disk($sourceDisk)->readStream($sourcePath);
                                    if (! is_resource($stream)) {
                                        throw new \RuntimeException('legacy_attachment_read_failed');
                                    }

                                    try {
                                        if (! Storage::disk('private')->writeStream($stagingPath, $stream)) {
                                            throw new \RuntimeException('legacy_attachment_stage_failed');
                                        }
                                    } finally {
                                        if (is_resource($stream)) {
                                            fclose($stream);
                                        }
                                    }
                                }

                                $stagingCreated = true;
                                $createdStaging[] = $stagingPath;
                            } catch (Throwable $exception) {
                                $failedAttachments++;
                                $this->warn("Could not stage attachment for ticket message {$message->id}.");
                                if ($stagingCreated) {
                                    Storage::disk('private')->delete($stagingPath);
                                }
                                continue;
                            }
                        }

                        try {
                            $inspection = $security->inspect(
                                $stagingPath,
                                $attachment['mime'] ?? null,
                                8 * 1024 * 1024
                            );
                            if (($inspection['safe'] ?? false) !== true || ($inspection['risk_level'] ?? null) === 'blocked') {
                                throw new \RuntimeException('legacy_attachment_security_rejected');
                            }
                        } catch (Throwable $exception) {
                            $quarantinePath = 'quarantine/support/'.$message->ticket_id.'/'.Str::uuid().'-'.$sourceName;

                            try {
                                Storage::disk('private')->makeDirectory(dirname($quarantinePath));
                                $quarantineStored = $sourceDisk === 'private'
                                    ? Storage::disk('private')->copy($stagingPath, $quarantinePath)
                                    : Storage::disk('private')->move($stagingPath, $quarantinePath);
                                if (! $quarantineStored) {
                                    throw new \RuntimeException('legacy_attachment_quarantine_failed');
                                }

                                $createdQuarantine[] = $quarantinePath;
                                if ($stagingCreated && $sourceDisk !== 'private') {
                                    $createdStaging = array_values(array_diff($createdStaging, [$stagingPath]));
                                }
                                $attachments[$index] = array_merge($attachment, [
                                    'path' => $quarantinePath,
                                    'disk' => 'private',
                                    'quarantined' => true,
                                    'quarantine_reason' => 'security_inspection_failed',
                                    'legacy_source_disk' => $sourceDisk,
                                    'legacy_source_path_b64' => base64_encode($sourcePath),
                                ]);
                                $sourceCleanup[] = [$index, $sourceDisk, $sourcePath];
                                $changed = true;
                                $quarantinedAttachments++;
                            } catch (Throwable $quarantineException) {
                                $failedAttachments++;
                                $this->warn("Could not quarantine attachment for ticket message {$message->id}.");
                                if ($stagingCreated) {
                                    Storage::disk('private')->delete($stagingPath);
                                    $createdStaging = array_values(array_diff($createdStaging, [$stagingPath]));
                                }
                            }

                            continue;
                        }

                        $storageName = pathinfo($originalName, PATHINFO_FILENAME);
                        $sourceExtension = strtolower(pathinfo($sourceName, PATHINFO_EXTENSION));
                        if ($sourceExtension !== '') {
                            $storageName .= '.'.$sourceExtension;
                        }

                        $managed = null;
                        try {
                            $managed = $storage->adoptExistingPrivate(
                                $stagingPath,
                                $storageName,
                                (string) ($inspection['mime'] ?? 'application/octet-stream'),
                                (int) ($inspection['size'] ?? Storage::disk('private')->size($stagingPath)),
                                'support',
                                TicketMessage::class,
                                (int) $message->id,
                                null,
                                StorageManager::NON_CRITICAL
                            );
                            $storage->bindReference(
                                (int) $managed['managed_file_id'],
                                TicketMessage::class,
                                (int) $message->id
                            );

                            $attachments[$index] = array_merge($attachment, [
                                'path' => $managed['path'],
                                'disk' => $managed['disk'],
                                'managed_file_id' => $managed['managed_file_id'],
                                'name' => $originalName,
                                'size' => (int) $inspection['size'],
                                'mime' => (string) $inspection['mime'],
                                'sha256' => (string) $inspection['checksum'],
                                'quarantined' => false,
                                'legacy_source_disk' => $sourceDisk,
                                'legacy_source_path_b64' => base64_encode($sourcePath),
                            ]);

                            $createdManaged[] = [$managed['disk'], $managed['path']];
                            $sourceCleanup[] = [$index, $sourceDisk, $sourcePath];
                            $changed = true;
                            $migratedAttachments++;
                        } catch (Throwable $exception) {
                            $failedAttachments++;
                            if ($managed && ! empty($managed['disk']) && ! empty($managed['path'])) {
                                try {
                                    $storage->delete((string) $managed['disk'], (string) $managed['path']);
                                } catch (Throwable $cleanupException) {
                                    report($cleanupException);
                                }
                            }
                            $this->warn("Could not register managed attachment for ticket message {$message->id}.");
                        }
                    }

                    if (! $changed) {
                        foreach (array_unique($createdStaging) as $path) {
                            Storage::disk('private')->delete($path);
                        }
                        continue;
                    }

                    try {
                        DB::transaction(function () use ($message, $attachments): void {
                            $message->attachments = $attachments;
                            $message->save();
                        });
                    } catch (Throwable $exception) {
                        foreach ($createdManaged as [$disk, $path]) {
                            try {
                                $storage->delete((string) $disk, (string) $path);
                            } catch (Throwable $cleanupException) {
                                report($cleanupException);
                            }
                        }
                        foreach (array_unique(array_merge($createdQuarantine, $createdStaging)) as $path) {
                            Storage::disk('private')->delete($path);
                        }
                        $failedAttachments++;
                        $this->warn("Could not save migrated attachment references for ticket message {$message->id}.");
                        continue;
                    }

                    $cleanupMetadataChanged = false;
                    foreach ($sourceCleanup as [$index, $disk, $path]) {
                        try {
                            $stillReferenced = $this->isReferencedByAnotherMessage((int) $message->id, (string) $path);

                            if ($stillReferenced) {
                                continue;
                            }

                            $this->deleteLegacySource($storage, (string) $disk, (string) $path);
                            unset($attachments[$index]['legacy_source_disk'], $attachments[$index]['legacy_source_path_b64']);
                            $cleanupMetadataChanged = true;
                        } catch (Throwable $exception) {
                            $failedAttachments++;
                            $this->warn("Could not remove old attachment source for ticket message {$message->id}.");
                        }
                    }

                    if ($cleanupMetadataChanged) {
                        try {
                            $message->attachments = $attachments;
                            $message->save();
                        } catch (Throwable $exception) {
                            // The managed attachment is already committed. Keep
                            // its cleanup metadata so the next scheduled run retries.
                            $failedAttachments++;
                            $this->warn("Could not clear old-source cleanup metadata for ticket message {$message->id}.");
                        }
                    }

                    foreach (array_unique($createdStaging) as $path) {
                        Storage::disk('private')->delete($path);
                    }

                    $processedMessages++;
                }

                return $processedMessages < $limit;
            });

        $this->info(sprintf(
            'Support attachments: migrated=%d quarantined=%d failed=%d messages=%d%s',
            $migratedAttachments,
            $quarantinedAttachments,
            $failedAttachments,
            $processedMessages,
            $processedMessages >= $limit ? ' [batch limit reached]' : ''
        ));

        return $failedAttachments > 0 ? self::FAILURE : self::SUCCESS;
    }
}
