<?php

namespace App\Jobs;

use App\Models\StoreProductIngestion;
use App\Models\StoreProductIntakeSession;
use App\Services\ProductSupervisorClient;
use App\Services\Storage\StorageManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReviewProductIntakeFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 600;
    public array $backoff = [60, 180];

    public function __construct(public string $ingestionId)
    {
        $this->onQueue('product-supervisor');
    }

    public function handle(ProductSupervisorClient $supervisor, StorageManager $storage): void
    {
        $ingestion = StoreProductIngestion::query()
            ->with('intakeSession')
            ->whereKey($this->ingestionId)
            ->whereNull('product_id')
            ->whereNotNull('intake_session_id')
            ->where('status', 'uploaded')
            ->first();

        if (! $ingestion || ! $ingestion->intakeSession) {
            return;
        }

        $session = $ingestion->intakeSession;
        if ($session->expires_at?->isPast() || in_array($session->status, ['cancelled', 'expired', 'submitted', 'completed'], true)) {
            return;
        }

        if ((int) $ingestion->size_bytes > 25 * 1024 * 1024) {
            $this->storeResult($ingestion, $session, 'manual_review_required', [
                'status' => 'manual_review_required',
                'reason' => 'supervisor_file_size_limit',
                'size_bytes' => (int) $ingestion->size_bytes,
                'reviewed_at' => now()->toISOString(),
                'automated_publication' => false,
            ]);
            return;
        }

        if (! $supervisor->configured()) {
            $this->storeResult($ingestion, $session, 'manual_review_required', [
                'status' => 'manual_review_required',
                'reason' => 'supervisor_not_configured',
                'reviewed_at' => now()->toISOString(),
                'automated_publication' => false,
            ]);
            return;
        }

        $disk = (string) ($ingestion->storage_disk ?: 'private');
        $path = (string) $ingestion->storage_path;
        if ($path === '' || ! $storage->exists($disk, $path)) {
            throw new \RuntimeException('intake_managed_file_missing');
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'farast-intake-review-');
        if (! is_string($temporaryPath) || $temporaryPath === '') {
            throw new \RuntimeException('intake_review_temp_unavailable');
        }

        $stream = null;
        try {
            $storage->downloadTo($disk, $path, $temporaryPath);
            $actualChecksum = hash_file('sha256', $temporaryPath);
            if (! is_string($actualChecksum) || ! hash_equals((string) $ingestion->sha256, $actualChecksum)) {
                throw new \RuntimeException('intake_review_checksum_mismatch');
            }

            $stream = fopen($temporaryPath, 'rb');
            if (! is_resource($stream)) {
                throw new \RuntimeException('intake_review_stream_unavailable');
            }

            $this->storeResult($ingestion, $session, 'reviewing', [
                'status' => 'reviewing',
                'started_at' => now()->toISOString(),
                'sha256' => $actualChecksum,
            ]);

            $review = $supervisor->reviewFile($stream, $ingestion->original_name, [
                // The UUID is a correlation identifier for this intake session, not a catalog product.
                'product_id' => (string) $session->id,
                'product_type' => (string) $session->product_type,
                'primary_scan_status' => $ingestion->antivirus_status === 'clean'
                    ? 'clean'
                    : 'structural_checks_passed',
                'antivirus_status' => (string) $ingestion->antivirus_status,
            ]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            @unlink($temporaryPath);
        }

        $providerStatus = (array) ($review['provider_status'] ?? []);
        $securityGate = (string) ($review['security_gate'] ?? 'manual_scan_required');
        $aiAnalysisComplete = ($providerStatus['groq'] ?? null) === 'completed';
        $status = match (true) {
            $securityGate === 'blocked' => 'blocked',
            $securityGate === 'manual_scan_required' => 'manual_review_required',
            ! $aiAnalysisComplete => 'manual_review_required',
            default => 'completed',
        };

        $inspection = (array) ($review['inspection'] ?? []);
        $safeReview = [
            'status' => $status,
            'review_id' => (string) ($review['review_id'] ?? ''),
            'summary' => mb_substr((string) ($review['summary'] ?? ''), 0, 700),
            'findings' => array_slice(is_array($review['findings'] ?? null) ? $review['findings'] : [], 0, 12),
            'warnings' => array_map(
                static fn ($warning) => mb_substr((string) $warning, 0, 400),
                array_slice(is_array($review['warnings'] ?? null) ? $review['warnings'] : [], 0, 12)
            ),
            'provider_status' => $providerStatus,
            'security_gate' => $securityGate,
            'inspection' => [
                'detected_type' => (string) ($inspection['detected_type'] ?? 'unknown'),
                'extracted_characters' => (int) ($inspection['extracted_characters'] ?? 0),
                'antivirus_status' => (string) ($inspection['antivirus_status'] ?? 'not_configured'),
                'upstream_scan_status' => (string) ($inspection['upstream_scan_status'] ?? 'not_provided'),
            ],
            'sha256' => (string) $ingestion->sha256,
            'reviewed_at' => now()->toISOString(),
            'publication_authorized' => false,
            'automated_publication' => false,
        ];

        $this->storeResult($ingestion, $session, $status, $safeReview);
    }

    public function failed(Throwable $exception): void
    {
        $ingestion = StoreProductIngestion::query()->with('intakeSession')->find($this->ingestionId);
        if (! $ingestion || ! $ingestion->intakeSession) {
            return;
        }

        Log::warning('Product intake file review failed', [
            'ingestion_id' => $this->ingestionId,
            'intake_session_id' => $ingestion->intake_session_id,
            'exception' => class_basename($exception),
        ]);

        $this->storeResult($ingestion, $ingestion->intakeSession, 'failed', [
            'status' => 'failed',
            'reason' => 'supervisor_review_failed',
            'reviewed_at' => now()->toISOString(),
            'automated_publication' => false,
        ]);
    }

    private function storeResult(StoreProductIngestion $ingestion, StoreProductIntakeSession $session, string $status, array $review): void
    {
        DB::transaction(function () use ($ingestion, $session, $status, $review) {
            $lockedIngestion = StoreProductIngestion::query()->whereKey($ingestion->id)->lockForUpdate()->first();
            if (! $lockedIngestion
                || $lockedIngestion->product_id !== null
                || $lockedIngestion->status !== 'uploaded'
                || ($lockedIngestion->supervisor_status === 'completed' && $lockedIngestion->supervisor_reviewed_at !== null)) {
                return;
            }

            $lockedIngestion->update([
                'supervisor_status' => $status,
                'supervisor_review_json' => $review,
                'supervisor_reviewed_at' => in_array($status, ['completed', 'blocked', 'manual_review_required', 'failed'], true) ? now() : null,
            ]);

            $lockedSession = StoreProductIntakeSession::query()->whereKey($session->id)->lockForUpdate()->first();
            if (! $lockedSession || in_array($lockedSession->status, ['cancelled', 'expired', 'submitted', 'completed'], true)) {
                return;
            }

            $statuses = $lockedSession->ingestions()
                ->where('status', 'uploaded')
                ->pluck('supervisor_status');
            $nextStatus = match (true) {
                $statuses->isEmpty() => 'selecting_files',
                $statuses->contains(fn ($value) => in_array($value, ['blocked', 'failed', 'manual_review_required'], true)) => 'review_attention',
                $statuses->every(fn ($value) => $value === 'completed') => 'files_reviewed',
                default => 'review_pending',
            };
            $lockedSession->update(['status' => $nextStatus]);
        }, 3);
    }
}
