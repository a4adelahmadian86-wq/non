<?php

namespace App\Console\Commands;

use App\Models\FarastDocument;
use App\Models\FarastDocumentVersion;
use App\Models\FarastStorageObject;
use App\Services\Storage\StorageManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class MigrateLegacyEditorAssets extends Command
{
    protected $signature = 'storage:migrate-legacy-editor-assets {--limit=100}';
    protected $description = 'Move existing editor image assets from local private storage to the foreign managed drive';

    public function handle(StorageManager $storage): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $migrated = 0;
        $failed = 0;

        FarastStorageObject::query()
            ->where('classification', 'editor_asset')
            ->where('disk', 'private')
            ->whereIn('status', ['approved', 'quarantined'])
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (FarastStorageObject $object) use ($storage, &$migrated, &$failed): void {
                try {
                    if ($this->migrateObject($object, $storage)) $migrated++;
                } catch (\Throwable $exception) {
                    $failed++;
                    report($exception);
                    $this->warn('دارایی ویرایشگر #'.$object->id.' منتقل نشد: '.$exception->getMessage());
                }
            });

        $this->info("Migrated: {$migrated}; failed: {$failed}. Run again to continue remaining editor assets.");
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function migrateObject(FarastStorageObject $object, StorageManager $storage): bool
    {
        $oldPath = (string) $object->path;
        $private = Storage::disk('private');
        if (! $private->exists($oldPath)) throw new RuntimeException('فایل محلی دارایی ویرایشگر پیدا نشد.');

        $document = $object->document_id ? FarastDocument::find($object->document_id) : null;
        $resource = collect((array) data_get($document?->content_json, 'resources', []))
            ->first(fn ($row) => is_array($row) && (string) ($row['storage_path'] ?? '') === $oldPath);
        $originalName = is_array($resource) && ! empty($resource['name']) ? (string) $resource['name'] : basename($oldPath);

        $managed = $storage->adoptExistingPrivate(
            $oldPath,
            $originalName,
            (string) $object->mime,
            (int) $object->size,
            'editor',
            FarastStorageObject::class,
            (int) $object->id,
            null,
            StorageManager::NON_CRITICAL
        );

        try {
            DB::transaction(function () use ($object, $oldPath, $managed, $storage): void {
                $locked = FarastStorageObject::query()->whereKey($object->id)->lockForUpdate()->firstOrFail();
                if ($locked->disk !== 'private' || (string) $locked->path !== $oldPath) {
                    throw new RuntimeException('دارایی ویرایشگر هم‌زمان تغییر کرده است.');
                }

                $newPath = (string) $managed['path'];
                if ($locked->document_id) {
                    $document = FarastDocument::query()->whereKey($locked->document_id)->lockForUpdate()->first();
                    if ($document) {
                        $document->content_json = $this->replaceResourcePath((array) $document->content_json, $oldPath, $newPath);
                        $document->save();

                        FarastDocumentVersion::query()->where('document_id', $document->id)->get()->each(function (FarastDocumentVersion $version) use ($oldPath, $newPath): void {
                            $version->content_json = $this->replaceResourcePath((array) $version->content_json, $oldPath, $newPath);
                            $version->save();
                        });
                    }
                }

                $provenance = (array) $locked->provenance;
                $provenance['managed_file_id'] = $managed['managed_file_id'];
                $provenance['migrated_from_disk'] = 'private';
                $provenance['migrated_at'] = now()->toIso8601String();
                $locked->update([
                    'disk' => $managed['disk'],
                    'path' => $newPath,
                    'provenance' => $provenance,
                ]);
                $storage->bindReference((int) $managed['managed_file_id'], FarastStorageObject::class, (int) $locked->id);
            });
        } catch (\Throwable $exception) {
            $storage->delete((string) $managed['disk'], (string) $managed['path']);
            throw $exception;
        }

        $private->delete($oldPath);
        return true;
    }

    private function replaceResourcePath(array $content, string $oldPath, string $newPath): array
    {
        $resources = is_array($content['resources'] ?? null) ? $content['resources'] : [];
        foreach ($resources as $index => $resource) {
            if (is_array($resource) && (string) ($resource['storage_path'] ?? '') === $oldPath) {
                $resources[$index]['storage_path'] = $newPath;
            }
        }
        if ($resources !== []) $content['resources'] = $resources;
        return $content;
    }
}
