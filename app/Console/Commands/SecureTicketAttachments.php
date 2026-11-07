<?php

namespace App\Console\Commands;

use App\Models\TicketMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SecureTicketAttachments extends Command
{
    protected $signature = 'farast:secure-ticket-attachments {--limit=100}';

    protected $description = 'Move legacy public ticket attachments into private storage';

    public function handle(): int
    {
        $limit = max(1, min((int) $this->option('limit'), 1000));
        $migrated = 0;

        TicketMessage::query()
            ->whereNotNull('attachments')
            ->orderBy('id')
            ->chunkById(100, function ($messages) use (&$migrated, $limit): bool {
                foreach ($messages as $message) {
                    if ($migrated >= $limit) {
                        return false;
                    }

                    $attachments = array_values((array) ($message->attachments ?? []));
                    $changed = false;
                    $oldPaths = [];

                    foreach ($attachments as $index => $attachment) {
                        if (! is_array($attachment) || ($attachment['disk'] ?? 'public') !== 'public') {
                            continue;
                        }

                        $oldPath = (string) ($attachment['path'] ?? '');
                        if ($oldPath === '' || ! Storage::disk('public')->exists($oldPath)) {
                            continue;
                        }

                        $newPath = 'ticket-attachments/'.$message->ticket_id.'/'.Str::uuid().'-'.basename($oldPath);
                        $stream = Storage::disk('public')->readStream($oldPath);
                        if (! is_resource($stream)) {
                            $this->warn("Could not read legacy attachment {$oldPath}.");
                            continue;
                        }

                        try {
                            $written = Storage::disk('private')->writeStream($newPath, $stream);
                        } finally {
                            if (is_resource($stream)) {
                                fclose($stream);
                            }
                        }

                        if (! $written || ! Storage::disk('private')->exists($newPath)) {
                            Storage::disk('private')->delete($newPath);
                            $this->warn("Could not verify private copy for {$oldPath}.");
                            continue;
                        }

                        $attachments[$index]['path'] = $newPath;
                        $attachments[$index]['disk'] = 'private';
                        $oldPaths[] = $oldPath;
                        $changed = true;
                    }

                    if (! $changed) {
                        continue;
                    }

                    $message->attachments = $attachments;
                    $message->save();
                    $migrated++;

                    foreach (array_unique($oldPaths) as $oldPath) {
                        $stillReferenced = TicketMessage::query()
                            ->where('id', '!=', $message->id)
                            ->where('attachments', 'like', '%'.$oldPath.'%')
                            ->exists();

                        if (! $stillReferenced) {
                            Storage::disk('public')->delete($oldPath);
                        }
                    }
                }

                return $migrated < $limit;
            });

        $this->info("Secured {$migrated} ticket message attachment record(s).");

        return self::SUCCESS;
    }
}
