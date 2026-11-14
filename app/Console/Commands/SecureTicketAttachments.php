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
                    $quarantineRollback = [];
                    $sourceCleanup = [];

                    foreach ($attachments as $index => $attachment) {
                        if (! is_array($attachment)
                            || ! empty($attachment['managed_file_id'])
                            || ! empty($attachment['quarantined'])) {
                            continue;
                        }

                        $sourceDisk = (string) ($attachment['disk'] ?? 'public');
                        $sourcePath = (string) ($attachment['path'] ?? '');
                        $isVolume = preg_match('/^storage_volume:[0-9]+$/', $sourceDisk) === 1;

                        if ($sourcePath === ''
                            || str_starts_with($sourcePath, '/')
                            || preg_match('~(^|/)\.\.?(/|$)~', $sourcePath) === 1
                            || (! in_array($sourceDisk, ['public', 'private'], true) && ! $isVolume)) {
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
                        } catch (Throwable $exception) {
                            $quarantinePath = 'quarantine/support/'.$message->ticket_id.'/'.Str::uuid().'-'.$sourceName;

                            try {
                                Storage::disk('private')->makeDirectory(dirname($quarantinePath));
                                if (! Storage::disk('private')->move($stagingPath, $quarantinePath)) {
                                    throw new \RuntimeException('legacy_attachment_quarantine_failed');
                                }

                                $createdQuarantine[] = $quarantinePath;
                                if ($stagingCreated) {
                                    $createdStaging = array_values(array_diff($createdStaging, [$stagingPath]));
                                }
                                $attachments[$index] = array_merge($attachment, [
                                    'path' => $quarantinePath,
                                    'disk' => 'private',
                                    'quarantined' => true,
                                    'quarantine_reason' => 'security_inspection_failed',
                                ]);
                                $quarantineRollback[] = [$sourceDisk, $sourcePath, $quarantinePath];
                                $sourceCleanup[] = [$sourceDisk, $sourcePath];
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

                        $managed = null;
                        try {
                            $managed = $storage->adoptExistingPrivate(
                                $stagingPath,
                                $originalName,
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
                            ]);

                            $createdManaged[] = [$managed['disk'], $managed['path']];
                            $sourceCleanup[] = [$sourceDisk, $sourcePath];
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
                        foreach ($quarantineRollback as [$sourceDisk, $sourcePath, $quarantinePath]) {
                            if ($sourceDisk === 'private' && Storage::disk('private')->exists($quarantinePath)) {
                                Storage::disk('private')->move($quarantinePath, $sourcePath);
                                $createdQuarantine = array_values(array_diff($createdQuarantine, [$quarantinePath]));
                            }
                        }
                        foreach (array_unique(array_merge($createdQuarantine, $createdStaging)) as $path) {
                            Storage::disk('private')->delete($path);
                        }
                        $failedAttachments++;
                        $this->warn("Could not save migrated attachment references for ticket message {$message->id}.");
                        continue;
                    }

                    foreach ($sourceCleanup as [$disk, $path]) {
                        try {
                            $stillReferenced = TicketMessage::query()
                                ->where('id', '!=', $message->id)
                                ->where('attachments', 'like', '%'.$path.'%')
                                ->exists();

                            if (! $stillReferenced) {
                                if ($disk === 'public') {
                                    Storage::disk('public')->delete((string) $path);
                                } else {
                                    $storage->delete((string) $disk, (string) $path);
                                }
                            }
                        } catch (Throwable $exception) {
                            $failedAttachments++;
                            $this->warn("Could not remove old attachment source for ticket message {$message->id}.");
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
