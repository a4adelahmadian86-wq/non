<?php

namespace App\Jobs;

use App\Models\StoreProductIntakeFile;
use App\Services\ProductSupervisorClient;
use App\Services\Storage\StorageManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class ReviewProductIntakeFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 600;

    public function __construct(public readonly int $intakeFileId)
    {
        $this->onQueue('documents');
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(ProductSupervisorClient $supervisor, StorageManager $storage): void
    {
        $file = StoreProductIntakeFile::query()->with('session')->find($this->intakeFileId);
        if (! $file || ! $file->session || $file->status !== 'uploaded') {
            return;
        }

        if ($file->size_bytes > 25 * 1024 * 1024) {
            $file->update([
                'supervisor_status' => 'manual_review_required',
                'supervisor_review_json' => ['security_gate' => 'manual_scan_required', 'reason' => 'file_exceeds_supervisor_limit', 'automated_publication' => false],
            ]);
            return;
        }

        $bytes = $storage->get((string) $file->managed_disk, (string) $file->managed_path);
        $stream = fopen('php://temp', 'w+b');
        if (! is_resource($stream)) {
            throw new RuntimeException('intake_supervisor_stream_unavailable');
        }

        try {
            fwrite($stream, $bytes);
            rewind($stream);
            $title = trim((string) pathinfo($file->original_name, PATHINFO_FILENAME));
            if (mb_strlen($title) < 2) {
                $title = 'فایل ورودی محصول';
            }

            $identity = 'intake-'.$file->intake_session_id.'-'.$file->id;
            $antivirusStatus = data_get($file->inspection_json, 'antivirus.status');
            $result = $supervisor->reviewFile($stream, $file->original_name, [
                'product_id' => mb_substr($identity, 0, 80),
                'product_title' => mb_substr($title, 0, 220),
                'product_type' => $file->session->product_type,
                'primary_scan_status' => $antivirusStatus === 'clean' ? 'clean' : 'structural_checks_passed',
                'allow_sample_generation' => false,
            ]);

            $file->update([
                'supervisor_status' => ($result['security_gate'] ?? null) === 'blocked' ? 'blocked' : 'completed',
                'supervisor_review_json' => $result,
                'supervisor_reviewed_at' => now(),
            ]);
        } finally {
            fclose($stream);
        }
    }

    public function failed(\Throwable $exception): void
    {
        StoreProductIntakeFile::query()->whereKey($this->intakeFileId)
            ->where('supervisor_status', 'pending')
            ->update([
                'supervisor_status' => 'manual_review_required',
                'supervisor_review_json' => ['security_gate' => 'manual_scan_required', 'reason' => 'supervisor_unavailable', 'error' => class_basename($exception), 'automated_publication' => false],
            ]);
        report($exception);
    }
}
