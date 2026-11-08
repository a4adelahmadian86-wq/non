<?php

namespace App\Console\Commands;

use App\Http\Controllers\StoreController;
use App\Models\StoreProductPreview;
use App\Services\StoreXlsxPreviewRenderer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PrepareLegacyReaderPreviews extends Command
{
    protected $signature = 'farast:prepare-reader-previews {--product= : Limit preparation to one product ID} {--dry-run : List legacy previews without changing them}';
    protected $description = 'Prepare legacy DOCX/XLSX preview assets once so reader requests only serve stored artifacts';

    public function handle(): int
    {
        $query = StoreProductPreview::query()
            ->where('is_active', true)
            ->whereIn('kind', ['docx', 'xlsx'])
            ->orderBy('id');

        if ($this->option('product')) {
            $query->where('product_id', (int) $this->option('product'));
        }

        $previews = $query->get();
        if ($previews->isEmpty()) {
            $this->info('No legacy Office previews require preparation.');
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $previews->each(fn ($preview) => $this->line('#'.$preview->id.' product='.$preview->product_id.' kind='.$preview->kind.' path='.$preview->path));
            $this->info($previews->count().' legacy preview(s) found; no files changed.');
            return self::SUCCESS;
        }

        $disk = Storage::disk('private');
        $preparedByHash = [];
        $successes = 0;
        $failures = 0;

        foreach ($previews as $preview) {
            $sourceDisk = Storage::disk($preview->disk ?: 'private');
            if (! $sourceDisk->exists($preview->path)) {
                $this->error('Missing source for preview #'.$preview->id);
                $failures++;
                continue;
            }

            try {
                $bytes = $sourceDisk->get($preview->path);
                $hash = hash('sha256', $bytes);
                $cacheKey = $preview->kind.':'.$hash;
                $extension = $preview->kind === 'docx' ? 'pdf' : 'html';
                $newKind = $preview->kind === 'docx' ? 'pdf' : 'html';
                $newMime = $preview->kind === 'docx' ? 'application/pdf' : 'text/html';
                $newPath = 'store/previews/prepared/'.$preview->product_id.'/'.$hash.'.'.$extension;

                if (! isset($preparedByHash[$cacheKey])) {
                    if (! $disk->exists($newPath)) {
                        $prepared = $preview->kind === 'docx'
                            ? app(StoreController::class)->prepareDocxPreview($bytes)
                            : app(StoreXlsxPreviewRenderer::class)->render($bytes, 'FARAST_READER_IDENTITY_TOKEN');
                        if (! $disk->put($newPath, $prepared)) {
                            throw new \RuntimeException('Unable to persist prepared preview.');
                        }
                    }
                    $preparedByHash[$cacheKey] = [$newPath, $newKind, $newMime];
                }

                [$newPath, $newKind, $newMime] = $preparedByHash[$cacheKey];
                $oldDisk = $preview->disk ?: 'private';
                $oldPath = $preview->path;

                DB::transaction(function () use ($preview, $newPath, $newKind, $newMime) {
                    $locked = StoreProductPreview::query()->whereKey($preview->id)->lockForUpdate()->firstOrFail();
                    if (! in_array($locked->kind, ['docx', 'xlsx'], true)) {
                        return;
                    }
                    $locked->update(['disk' => 'private', 'path' => $newPath, 'kind' => $newKind, 'mime' => $newMime]);
                });

                if (! StoreProductPreview::query()->where('disk', $oldDisk)->where('path', $oldPath)->exists()) {
                    Storage::disk($oldDisk)->delete($oldPath);
                }

                $successes++;
            } catch (\Throwable $exception) {
                report($exception);
                $this->error('Failed to prepare preview #'.$preview->id.': '.$exception->getMessage());
                $failures++;
            }
        }

        $this->info($successes.' preview(s) prepared; '.$failures.' failure(s).');
        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
