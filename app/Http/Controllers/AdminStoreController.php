<?php

namespace App\Http\Controllers;

use App\Models\StoreCategory;
use App\Models\StoreCourse;
use App\Models\StoreProduct;
use App\Models\StoreProductFile;
use App\Models\StoreProductIngestion;
use App\Jobs\ProcessProductDocument;
use App\Models\StoreProductImage;
use App\Models\StoreProductPreview;
use App\Models\StoreTag;
use App\Services\ProductSupervisorSnapshotBuilder;
use App\Services\UploadSecurityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminStoreController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $type = in_array($request->query('type'), ['digital_file', 'course'], true) ? $request->query('type') : '';
        $status = in_array($request->query('status'), ['draft', 'published', 'archived'], true) ? $request->query('status') : '';

        $products = StoreProduct::query()
            ->with(['category', 'files', 'images', 'tags'])
            ->when($q !== '', fn ($query) => $query->where(function ($inner) use ($q) {
                $inner->where('title', 'like', '%'.$q.'%')
                    ->orWhere('sku', 'like', '%'.$q.'%')
                    ->orWhereHas('tags', fn ($tagQuery) => $tagQuery->where('name', 'like', '%'.$q.'%'));
            }))
            ->when($type !== '', fn ($query) => $query->where('type', $type))
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        $stats = [
            'total' => StoreProduct::count(),
            'published' => StoreProduct::where('status', 'published')->count(),
            'drafts' => StoreProduct::where('status', 'draft')->count(),
            'courses' => StoreProduct::where('type', 'course')->count(),
            'files' => StoreProduct::where('type', 'digital_file')->count(),
        ];

        return view('admin.store.index', compact('products', 'q', 'type', 'status', 'stats'));
    }

    public function create()
    {
        $categories = StoreCategory::where('is_active', true)->orderBy('sort_order')->get();
        $tags = StoreTag::query()->orderBy('name')->get();
        $relatedProducts = StoreProduct::query()->where('status', '!=', 'archived')->orderBy('title')->get(['id', 'title', 'sku', 'type']);

        return view('admin.store.form', [
            'product' => new StoreProduct(['status' => 'draft', 'price_rials' => 0, 'preview_pages' => 3, 'type' => 'digital_file']),
            'categories' => $categories,
            'tags' => $tags,
            'relatedProducts' => $relatedProducts,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['slug'] = $this->uniqueSlug($data['title'], $data['slug'] ?? null);
        // A new catalog item must not become public before its protected payload/curriculum exists.
        $data['status'] = 'draft';
        $data['published_at'] = null;
        $tagIds = $data['tag_ids'] ?? [];
        $relatedIds = $data['related_product_ids'] ?? [];
        unset($data['tag_ids'], $data['related_product_ids']);
        $product = DB::transaction(function () use ($data, $tagIds, $relatedIds) {
            $product = StoreProduct::create($data);
            if ($product->type === 'course') {
                StoreCourse::firstOrCreate(['product_id' => $product->id]);
            }
            $product->tags()->sync($tagIds);
            $product->related()->sync($relatedIds);
            $this->audit('store.product.created', $product, ['type' => $product->type, 'sku' => $product->sku]);

            return $product;
        });

        if ($product->type === 'course') {
            return redirect()->route('admin.store.course.edit', $product)->with('status', 'محصول دوره ایجاد شد؛ اکنون ساختار آموزشی آن را تکمیل کنید.');
        }

        return redirect()->route('admin.store.edit', $product)->with('status', 'محصول ایجاد شد؛ فایل اصلی را بارگذاری کنید.');
    }

    public function edit(StoreProduct $product, ProductSupervisorSnapshotBuilder $snapshotBuilder, \App\Services\ProductPublicationReadiness $publicationReadiness)
    {
        $product->load(['files.document', 'previews', 'images', 'category', 'course.sections.lessons', 'tags', 'related']);
        $categories = StoreCategory::orderBy('sort_order')->get();
        $tags = StoreTag::query()->orderBy('name')->get();
        $relatedProducts = StoreProduct::query()->where('id', '!=', $product->id)->where('status', '!=', 'archived')->orderBy('title')->get(['id', 'title', 'sku', 'type']);

        $hasSafePrimaryFile = $product->files->contains(fn ($file) => $file->purpose === 'product_download'
            && $file->is_active && $file->is_primary && $file->scan_status === 'clean');
        $hasFullReaderAsset = $product->previews->contains(fn ($preview) => $preview->is_active && $preview->access_level === 'full');
        $hasReadyReaderDocument = ($product->download_policy ?? 'signed') === 'reader_only'
            && $publicationReadiness->hasReadyPrimaryReader($product);
        $contentReady = $product->type === 'course'
            ? ($product->course?->sections()->whereHas('lessons', fn ($query) => $query->publishable())->exists() ?? false)
            : ($hasSafePrimaryFile && (($product->download_policy ?? 'signed') !== 'reader_only' || ($hasFullReaderAsset && $hasReadyReaderDocument)));

        $supervisorSettings = (array) data_get($product->metadata, 'ai_supervisor', []);
        $supervisorEnabled = (bool) data_get($supervisorSettings, 'enabled', false);
        $storedSupervisorReview = data_get($supervisorSettings, 'last_review');
        $supervisorReviewFresh = false;
        if (is_array($storedSupervisorReview) && filled($storedSupervisorReview['snapshot_hash'] ?? null)) {
            $currentSnapshot = $snapshotBuilder->build($product, $supervisorSettings);
            $supervisorReviewFresh = hash_equals(
                (string) $storedSupervisorReview['snapshot_hash'],
                $snapshotBuilder->fingerprint($currentSnapshot)
            );
        }
        $readiness = [
            ['label' => 'مشخصات و دسته‌بندی', 'ready' => filled($product->title) && filled($product->sku) && $product->category !== null && (bool) $product->category?->is_active, 'required' => true],
            ['label' => 'قیمت‌گذاری', 'ready' => $product->price_rials !== null && $product->price_rials >= 0 && ($product->compare_at_price_rials === null || $product->compare_at_price_rials > $product->price_rials), 'required' => true],
            ['label' => 'معرفی محصول', 'ready' => filled($product->short_description) || filled($product->description), 'required' => false],
            ['label' => 'تصویر کاور', 'ready' => filled($product->cover_path), 'required' => false],
            ['label' => $product->type === 'course' ? 'حداقل یک جلسه معتبر و منتشرشده' : (($product->download_policy ?? 'signed') === 'reader_only' ? 'فایل اصلی سالم و محتوای کامل خوانشگر' : 'فایل اصلی سالم و فعال'), 'ready' => $contentReady, 'required' => true],
            ['label' => 'اطلاعات سئو', 'ready' => filled($product->seo_title) && filled($product->seo_description), 'required' => false],
            ['label' => 'سیاست ناظر هوشمند', 'ready' => ! $supervisorEnabled || filled(data_get($product->metadata, 'ai_supervisor.mode')), 'required' => false],
        ];
        $publicationReady = collect($readiness)->where('required', true)->every(fn ($item) => $item['ready']);

        return view('admin.store.form', compact('product', 'categories', 'tags', 'relatedProducts', 'readiness', 'publicationReady', 'supervisorReviewFresh'));
    }

    public function update(Request $request, StoreProduct $product, ProductSupervisorSnapshotBuilder $snapshotBuilder, \App\Services\ProductPublicationReadiness $publicationReadiness)
    {
        $data = $this->validated($request, $product);
        $tagIds = $data['tag_ids'] ?? [];
        $relatedIds = $data['related_product_ids'] ?? [];
        unset($data['tag_ids'], $data['related_product_ids']);
        if (! empty($data['slug'])) {
            $data['slug'] = $this->uniqueSlug($data['title'], $data['slug'], $product->id);
        }

        DB::transaction(function () use ($product, $data, $tagIds, $relatedIds, $snapshotBuilder, $publicationReadiness) {
            $lockedProduct = StoreProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            if ($data['type'] !== $lockedProduct->type) {
                throw ValidationException::withMessages([
                    'type' => 'نوع محصول پس از ایجاد قابل تغییر نیست؛ برای نوع دیگر یک محصول جدید بسازید تا فایل‌ها و دسترسی‌های قبلی مخلوط نشوند.',
                ]);
            }

            if (($data['status'] ?? '') === 'published') {
                $supervisorSettings = (array) data_get($data, 'metadata.ai_supervisor', []);
                if ((bool) data_get($supervisorSettings, 'enabled', false)
                    && data_get($supervisorSettings, 'mode', 'suggest') === 'review_gate') {
                    $lastReview = data_get($lockedProduct->metadata, 'ai_supervisor.last_review');
                    $snapshot = $snapshotBuilder->build($lockedProduct, $supervisorSettings);
                    $snapshotMatches = is_array($lastReview)
                        && filled($lastReview['snapshot_hash'] ?? null)
                        && hash_equals((string) $lastReview['snapshot_hash'], $snapshotBuilder->fingerprint($snapshot));
                    $reviewFields = ['category_id', 'title', 'sku', 'type', 'short_description', 'description', 'price_rials', 'compare_at_price_rials', 'seo_title', 'seo_description'];
                    $reviewInputsChanged = collect($reviewFields)->contains(
                        fn ($field) => array_key_exists($field, $data)
                            && (string) ($data[$field] ?? '') !== (string) ($lockedProduct->getAttribute($field) ?? '')
                    );
                    $currentTagIds = $lockedProduct->tags()->pluck('store_tags.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
                    $submittedTagIds = collect($tagIds)->map(fn ($id) => (int) $id)->sort()->values()->all();
                    if (! $snapshotMatches || $reviewInputsChanged || $currentTagIds !== $submittedTagIds || ($lastReview['verdict'] ?? null) !== 'approved') {
                        throw ValidationException::withMessages([
                            'status' => 'برای انتشار در حالت تأییدمحور، ابتدا اطلاعات و رسانه‌ها را ذخیره کنید و پس از اجرای یک بررسی تازه با نتیجه تأیید، دوباره انتشار را انجام دهید.',
                        ]);
                    }
                }
                if (! $lockedProduct->category()->where('is_active', true)->exists()) {
                    throw ValidationException::withMessages(['category_id' => 'برای انتشار محصول، دسته‌بندی آن باید فعال باشد.']);
                }
                if ($data['type'] === 'course') {
                    $hasPublishableLesson = $lockedProduct->course()
                        ->whereHas('sections.lessons', fn ($query) => $query->publishable())
                        ->exists();
                    if (! $hasPublishableLesson) {
                        throw ValidationException::withMessages(['status' => 'برای انتشار دوره، دست‌کم یک جلسه منتشرشده با محتوای معتبر یا منبع سالم لازم است.']);
                    }
                } else {
                    $hasSafePrimaryFile = $lockedProduct->files()
                        ->where('purpose', 'product_download')
                        ->where('is_active', true)
                        ->where('is_primary', true)
                        ->where('scan_status', 'clean')
                        ->exists();
                    if (! $hasSafePrimaryFile) {
                        throw ValidationException::withMessages(['status' => 'برای انتشار محصول، یک فایل اصلی فعال با اسکن امنیتی موفق لازم است.']);
                    }
                    $downloadPolicy = $data['download_policy'] ?? $lockedProduct->download_policy;
                    if ($downloadPolicy === 'reader_only') {
                        $hasFullReaderAsset = $lockedProduct->previews()
                            ->where('is_active', true)
                            ->where('access_level', 'full')
                            ->exists();
                        if (! $hasFullReaderAsset || ! $publicationReadiness->hasReadyPrimaryReader($lockedProduct)) {
                            throw ValidationException::withMessages([
                                'status' => 'برای انتشار محصول فقط‌خواندنی، فایل اصلی باید استخراج کامل شده و صفحات خوانشگر آن آماده باشند؛ همچنین نسخه کامل خصوصی خوانشگر باید فعال باشد.',
                            ]);
                        }
                    }
                }
                if (! $lockedProduct->published_at && empty($data['published_at'])) {
                    $data['published_at'] = now();
                }
            } else {
                $data['published_at'] = null;
            }

            // Only changes to reviewed inputs/policy invalidate the report; a status-only publish
            // transition may retain the exact fresh approval that authorized it.
            $metadata = $data['metadata'] ?? $lockedProduct->metadata ?? [];
            $previousSupervisorSettings = (array) data_get($lockedProduct->metadata, 'ai_supervisor', []);
            $nextSupervisorSettings = (array) data_get($metadata, 'ai_supervisor', []);
            $reviewFields = ['category_id', 'title', 'sku', 'type', 'short_description', 'description', 'price_rials', 'compare_at_price_rials', 'seo_title', 'seo_description'];
            $reviewInputsChanged = collect($reviewFields)->contains(
                fn ($field) => array_key_exists($field, $data)
                    && (string) ($data[$field] ?? '') !== (string) ($lockedProduct->getAttribute($field) ?? '')
            );
            $currentTagIds = $lockedProduct->tags()->pluck('store_tags.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            $submittedTagIds = collect($tagIds)->map(fn ($id) => (int) $id)->sort()->values()->all();
            $reviewPolicyChanged = collect(['enabled', 'mode', 'checks', 'instructions'])->contains(
                fn ($key) => ($previousSupervisorSettings[$key] ?? null) !== ($nextSupervisorSettings[$key] ?? null)
            );
            if (isset($metadata['ai_supervisor']) && is_array($metadata['ai_supervisor'])) {
                if ($reviewInputsChanged || $currentTagIds !== $submittedTagIds || $reviewPolicyChanged) {
                    unset(
                        $metadata['ai_supervisor']['last_review'],
                        $metadata['ai_supervisor']['last_reviewed_at'],
                        $metadata['ai_supervisor']['asset_reviews']
                    );
                } else {
                    $previousReview = data_get($lockedProduct->metadata, 'ai_supervisor.last_review');
                    $previousReviewedAt = data_get($lockedProduct->metadata, 'ai_supervisor.last_reviewed_at');
                    $previousAssetReviews = data_get($lockedProduct->metadata, 'ai_supervisor.asset_reviews', []);
                    if (is_array($previousReview)) {
                        $metadata['ai_supervisor']['last_review'] = $previousReview;
                        $metadata['ai_supervisor']['last_reviewed_at'] = $previousReviewedAt;
                    }
                    if (is_array($previousAssetReviews) && $previousAssetReviews !== []) {
                        $metadata['ai_supervisor']['asset_reviews'] = $previousAssetReviews;
                    }
                }
                $data['metadata'] = $metadata;
            }
            $lockedProduct->update($data);
            $lockedProduct->tags()->sync($tagIds);
            $lockedProduct->related()->sync($relatedIds);
            $this->audit('store.product.updated', $lockedProduct, [
                'type' => $lockedProduct->type,
                'status' => $lockedProduct->status,
                'price_rials' => $lockedProduct->price_rials,
            ]);
        });

        return back()->with('status', 'محصول به‌روزرسانی شد.');
    }

    public function destroy(StoreProduct $product)
    {
        if ($product->libraryItems()->exists() || $product->orderItems()->exists()) {
            $product->update(['status' => 'archived', 'published_at' => null]);
            $this->audit('store.product.archived', $product, ['reason' => 'historical_orders_or_entitlements']);

            return redirect()->route('admin.store.index')->with('status', 'این محصول سابقه سفارش یا دسترسی مشتری دارد؛ برای حفظ سوابق و حقوق خریداران بایگانی شد.');
        }

        $this->audit('store.product.deleted', $product, ['type' => $product->type]);
        $product->delete();

        return redirect()->route('admin.store.index')->with('status', 'محصول حذف شد.');
    }

    public function preflightFile(Request $request, StoreProduct $product, UploadSecurityService $security)
    {
        abort_unless($product->type !== 'course', 422, 'برای دوره، فایل‌ها را در بخش منابع هر جلسه بارگذاری کنید.');
        $request->validate(['file' => 'required|file|max:51200', 'is_primary' => 'nullable|boolean']);

        $upload = $request->file('file');
        $originalNameLower = strtolower(str_replace('\\', '/', $upload->getClientOriginalName()));
        $extension = str_ends_with($originalNameLower, '.tar.gz')
            ? 'tar.gz'
            : (str_ends_with($originalNameLower, '.tar.bz2') ? 'tar.bz2' : strtolower($upload->getClientOriginalExtension()));
        $allowedExtensions = ['pdf','zip','tar','tar.gz','tar.bz2','jpg','jpeg','png','webp','gif','docx','xlsx','pptx','txt','csv','md','mp3','wav','ogg','flac','m4a','mp4','webm','mov'];
        abort_unless(in_array($extension, $allowedExtensions, true), 422, 'این پسوند در فهرست فرمت‌های مجاز فراست نیست.');

        // Remove expired staging records opportunistically; no cron or Redis dependency is required.
        $expired = StoreProductIngestion::query()
            ->where('product_id', $product->id)
            ->where('status', 'inspected')
            ->where('expires_at', '<', now())
            ->limit(30)
            ->get();
        foreach ($expired as $old) {
            Storage::disk($old->staging_disk ?: 'private')->delete($old->staging_path);
            $old->delete();
        }

        $safeName = mb_substr(basename(str_replace('\\', '/', $upload->getClientOriginalName())), 0, 255);
        $path = $upload->storeAs(
            'store/quarantine/product-ingestions/'.$product->id,
            (string) Str::uuid().'.'.$extension,
            'private'
        );
        if (! $path) {
            return response()->json(['message' => 'ذخیره موقت فایل در قرنطینه ممکن نشد.'], 503);
        }

        try {
            $inspection = $security->inspect($path, $upload->getMimeType(), 50 * 1024 * 1024);
            abort_unless($inspection['safe'] ?? false, 422, 'فایل از بررسی ایستای داخلی عبور نکرده است.');
            $ingestion = StoreProductIngestion::create([
                'id' => (string) Str::uuid(),
                'product_id' => $product->id,
                'user_id' => auth()->id(),
                'status' => 'inspected',
                'staging_disk' => 'private',
                'staging_path' => $path,
                'original_name' => $safeName,
                'mime' => $inspection['mime'],
                'size_bytes' => $inspection['size'],
                'sha256' => $inspection['checksum'],
                'inspection_json' => $inspection,
                'is_primary' => $request->boolean('is_primary', true),
                'expires_at' => now()->addMinutes(30),
            ]);
        } catch (Throwable $exception) {
            Storage::disk('private')->delete($path);
            if ($exception instanceof ValidationException) {
                throw $exception;
            }
            return response()->json([
                'message' => 'بررسی اولیه فایل کامل نشد؛ فایل ذخیره نشده است.',
                'code' => class_basename($exception) === 'RuntimeException' ? $exception->getMessage() : 'inspection_failed',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'ingestion_id' => $ingestion->id,
            'expires_at' => $ingestion->expires_at?->toISOString(),
            'file' => [
                'name' => $ingestion->original_name,
                'mime' => $ingestion->mime,
                'size_bytes' => $ingestion->size_bytes,
                'sha256' => $ingestion->sha256,
            ],
            'inspection' => $inspection,
        ], 201);
    }

    public function confirmFileUpload(Request $request, StoreProduct $product, string $ingestionId, UploadSecurityService $security)
    {
        abort_unless($product->type !== 'course', 422, 'برای دوره، منابع را از مدیریت جلسات بارگذاری کنید.');
        $ingestion = StoreProductIngestion::query()
            ->whereKey($ingestionId)
            ->where('product_id', $product->id)
            ->where('user_id', auth()->id())
            ->where('status', 'inspected')
            ->where('expires_at', '>', now())
            ->firstOrFail();

        $disk = Storage::disk($ingestion->staging_disk ?: 'private');
        abort_unless($disk->exists($ingestion->staging_path), 410, 'مهلت نگهداری فایل موقت تمام شده است؛ فایل را دوباره انتخاب کنید.');

        $inspection = $security->inspect($ingestion->staging_path, $ingestion->mime, 50 * 1024 * 1024);
        abort_unless(hash_equals((string) $ingestion->sha256, (string) $inspection['checksum']), 409, 'فایل پس از بررسی اولیه تغییر کرده است؛ دوباره آن را بارگذاری کنید.');

        $originalNameLower = strtolower($ingestion->original_name);
        $finalExtension = str_ends_with($originalNameLower, '.tar.gz')
            ? 'tar.gz'
            : (str_ends_with($originalNameLower, '.tar.bz2') ? 'tar.bz2' : strtolower(pathinfo($ingestion->original_name, PATHINFO_EXTENSION)));
        $finalPath = 'store/products/'.$product->id.'/'.Str::uuid().'.'.$finalExtension;
        if (! $disk->move($ingestion->staging_path, $finalPath)) {
            throw new \RuntimeException('product_file_storage_move_failed');
        }

        try {
            $fileRecord = DB::transaction(function () use ($product, $ingestion, $inspection, $finalPath) {
                $lockedProduct = StoreProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
                $lockedIngestion = StoreProductIngestion::query()->whereKey($ingestion->id)->lockForUpdate()->firstOrFail();
                abort_unless($lockedIngestion->status === 'inspected' && $lockedIngestion->expires_at?->isFuture(), 409, 'این مرحله قبلاً تأیید یا منقضی شده است.');

                if ($lockedIngestion->is_primary) {
                    $lockedProduct->files()->where('purpose', 'product_download')->update(['is_primary' => false]);
                    StoreProductPreview::query()
                        ->where('product_id', $lockedProduct->id)
                        ->where('access_level', 'full')
                        ->update(['is_active' => false]);
                    StoreProductPreview::query()
                        ->where('product_id', $lockedProduct->id)
                        ->where('access_level', 'sample')
                        ->where('path', 'like', 'store/products/'.$lockedProduct->id.'/artifacts/%/sample-v1.pdf')
                        ->update(['is_active' => false]);
                }

                $record = StoreProductFile::create([
                    'product_id' => $lockedProduct->id,
                    'purpose' => 'product_download',
                    'version' => $lockedProduct->version,
                    'disk' => 'private',
                    'path' => $finalPath,
                    'original_name' => $lockedIngestion->original_name,
                    'mime' => $inspection['mime'],
                    'size_bytes' => $inspection['size'],
                    'sha256' => $inspection['checksum'],
                    'is_primary' => $lockedIngestion->is_primary,
                    'is_active' => true,
                    'scan_status' => 'clean',
                    'scan_engine' => 'farast_static_v1',
                    'scanned_at' => now(),
                ]);

                \Illuminate\Support\Facades\DB::table('store_product_documents')->insert([
                    'product_id' => $lockedProduct->id,
                    'product_file_id' => $record->id,
                    'source_sha256' => $inspection['checksum'],
                    'processing_status' => 'pending',
                    'detected_type' => $inspection['detected_type'] ?? null,
                    'page_count' => $inspection['page_count'] ?? null,
                    'character_count' => 0,
                    'word_count' => 0,
                    'structure_json' => json_encode(['preflight' => $inspection], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $lockedIngestion->update(['status' => 'uploaded', 'confirmed_at' => now(), 'staging_path' => $finalPath]);
                $this->audit('store.product.file_uploaded', $lockedProduct, [
                    'file_id' => $record->id,
                    'mime' => $record->mime,
                    'size_bytes' => $record->size_bytes,
                    'sha256' => $record->sha256,
                    'security_engine' => 'farast_static_v1',
                ]);

                return $record;
            });
        } catch (Throwable $exception) {
            $disk->delete($finalPath);
            throw $exception;
        }

        $documentId = (int) DB::table('store_product_documents')
            ->where('product_file_id', $fileRecord->id)
            ->value('id');

        try {
            if ($documentId > 0 && $fileRecord->size_bytes <= 25 * 1024 * 1024) {
                ProcessProductDocument::dispatch($documentId);
            } elseif ($documentId > 0) {
                DB::table('store_product_documents')->where('id', $documentId)->update([
                    'processing_status' => 'awaiting_provider',
                    'failure_code' => 'ai_processing_size_limit',
                    'updated_at' => now(),
                ]);
            }
        } catch (Throwable $exception) {
            DB::table('store_product_documents')->where('id', $documentId)->update([
                'processing_status' => 'awaiting_provider',
                'failure_code' => 'processing_dispatch_failed',
                'updated_at' => now(),
            ]);
            Log::warning('Product document processing could not be dispatched', [
                'document_id' => $documentId,
                'exception' => class_basename($exception),
            ]);
        }

        $processingStatus = $documentId > 0
            ? (DB::table('store_product_documents')->where('id', $documentId)->value('processing_status') ?: 'pending')
            : 'pending';

        return response()->json([
            'success' => true,
            'message' => 'فایل پس از تأیید شما در فضای خصوصی فروشگاه ذخیره شد.',
            'file' => [
                'id' => $fileRecord->id,
                'name' => $fileRecord->original_name,
                'size_bytes' => $fileRecord->size_bytes,
                'scan_status' => $fileRecord->scan_status,
                'processing_status' => $processingStatus,
            ],
        ]);
    }

    public function fileProcessingStatus(StoreProduct $product, StoreProductFile $file)
    {
        abort_unless(
            (int) $file->product_id === (int) $product->id
                && $file->purpose === 'product_download'
                && $file->is_active,
            404
        );

        $document = $file->document;
        if (! $document) {
            return response()->json([
                'file_id' => (int) $file->id,
                'processing_status' => 'pending',
                'sample_status' => 'pending',
                'audio_status' => 'pending',
            ])->header('Cache-Control', 'private, no-store');
        }

        $structure = (array) ($document->structure_json ?? []);
        $sample = (array) data_get($structure, 'sample', []);
        $audio = (array) data_get($structure, 'audio', []);
        $completeness = (array) data_get($structure, 'completeness', []);

        return response()->json([
            'file_id' => (int) $file->id,
            'processing_status' => (string) $document->processing_status,
            'failure_code' => $document->failure_code,
            'page_count' => $document->page_count,
            'word_count' => $document->word_count,
            'character_count' => $document->character_count,
            'completeness' => [
                'extraction_complete' => (bool) ($completeness['extraction_complete'] ?? false),
                'analysis_batches_complete' => (bool) ($completeness['analysis_batches_complete'] ?? false),
                'analysis_batch_count' => (int) ($completeness['analysis_batch_count'] ?? 0),
                'successful_analysis_batches' => (int) ($completeness['successful_analysis_batches'] ?? 0),
            ],
            'sample_status' => ! empty($sample['generated_once']) ? 'completed' : ($document->processing_status === 'completed' ? 'not_available' : 'pending'),
            'sample_pages' => array_values(array_filter((array) ($sample['selected_pages'] ?? []), 'is_int')),
            'audio_status' => (string) ($audio['status'] ?? ($document->processing_status === 'completed' ? 'pending' : 'not_started')),
            'audio_duration_ms' => (int) ($audio['duration_ms'] ?? 0),
            'audio_segment_count' => (int) ($audio['segment_count'] ?? 0),
            'fact_count' => $document->facts()->where('source_sha256', $document->source_sha256)->where('verification_status', 'evidence_backed')->count(),
            'reader_ready' => (bool) data_get($structure, 'reader.ready', false),
            'reader_page_count' => (int) data_get($structure, 'reader.page_count', 0),
            'updated_at' => $document->updated_at?->toISOString(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function cancelFilePreflight(Request $request, StoreProduct $product, string $ingestionId)
    {
        $ingestion = StoreProductIngestion::query()
            ->whereKey($ingestionId)
            ->where('product_id', $product->id)
            ->where('user_id', auth()->id())
            ->where('status', 'inspected')
            ->firstOrFail();

        Storage::disk($ingestion->staging_disk ?: 'private')->delete($ingestion->staging_path);
        $ingestion->update(['status' => 'rejected', 'staging_path' => 'cancelled']);

        return response()->json(['success' => true]);
    }

    public function uploadFile(Request $request, StoreProduct $product, UploadSecurityService $security)
    {
        abort(409, 'بارگذاری مستقیم غیرفعال است؛ ابتدا گزارش بررسی اولیه را مشاهده و سپس فایل را تأیید کنید.');
    }

    public function deleteFile(StoreProduct $product, StoreProductFile $file)
    {
        abort_unless($file->product_id === $product->id && $file->purpose === 'product_download', 404);

        [$disk, $path] = DB::transaction(function () use ($product, $file) {
            $lockedProduct = StoreProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $lockedFile = StoreProductFile::query()
                ->whereKey($file->id)
                ->where('product_id', $lockedProduct->id)
                ->where('purpose', 'product_download')
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedProduct->status === 'published' && $lockedFile->is_primary) {
                $hasReplacement = $lockedProduct->files()
                    ->where('purpose', 'product_download')
                    ->where('id', '!=', $lockedFile->id)
                    ->where('is_active', true)
                    ->where('is_primary', true)
                    ->where('scan_status', 'clean')
                    ->exists();
                if (! $hasReplacement) {
                    throw ValidationException::withMessages([
                        'file' => 'این فایل، محتوای اصلی محصول منتشرشده است. ابتدا فایل اصلی جایگزین را بارگذاری و فعال کنید یا محصول را از انتشار خارج کنید.',
                    ]);
                }
            }

            $this->audit('store.product.file_deleted', $lockedProduct, [
                'file_id' => $lockedFile->id,
                'purpose' => $lockedFile->purpose,
            ]);
            $disk = $lockedFile->disk ?: 'private';
            $path = $lockedFile->path;
            $lockedFile->delete();

            return [$disk, $path];
        });

        Storage::disk($disk)->delete($path);

        return back()->with('status', 'فایل حذف شد.');
    }

    public function uploadPreview(Request $request, StoreProduct $product, UploadSecurityService $security)
    {
        abort_unless($product->type !== 'course', 422, 'برای دوره، نمونه‌ها را در ساختار جلسات دوره تعریف کنید.');
        $request->validate([
            'preview' => 'required|file|mimes:jpg,jpeg,png,webp,gif,pdf,docx,xlsx|max:20480',
            'page_number' => 'nullable|integer|min:1|max:500',
            'access_level' => 'nullable|in:sample,full',
        ]);

        $upload = $request->file('preview');
        $extension = strtolower($upload->getClientOriginalExtension());
        $accessLevel = $request->input('access_level', 'sample');
        abort_if($accessLevel === 'full' && $product->download_policy !== 'reader_only', 422, 'محتوای کامل فقط برای محصول با سیاست فقط‌خواندنی قابل ثبت است.');
        $sourcePath = $upload->store('store/quarantine/previews/'.$product->id, 'private');
        $preparedPath = null;
        $sourcePageCount = null;
        $includedPageCount = null;

        try {
            $inspection = $security->inspect($sourcePath, $upload->getMimeType(), 20 * 1024 * 1024, [
                'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf', 'application/zip',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]);
            abort_unless($inspection['safe'] ?? false, 503, 'اسکن ضدبدافزار آماده نیست؛ پیش‌نمایش ذخیره نشد.');

            $kind = match ($extension) {
                'pdf', 'docx' => 'pdf',
                'xlsx' => 'html',
                default => 'image',
            };
            $mime = $kind === 'pdf' ? 'application/pdf' : ($kind === 'html' ? 'text/html' : $inspection['mime']);
            $sourceBytes = Storage::disk('private')->get($sourcePath);
            $preparedBytes = $sourceBytes;

            if ($extension === 'docx') {
                $preparedBytes = app(StoreController::class)->prepareDocxPreview($sourceBytes);
            }

            if (in_array($extension, ['pdf', 'docx'], true) && $accessLevel === 'sample') {
                $sample = app(\App\Services\StorePdfPreviewPreparer::class)->prepareSample(
                    $preparedBytes,
                    max(1, min(50, (int) ($product->preview_pages ?: 3)))
                );
                $preparedBytes = $sample['bytes'];
                $sourcePageCount = $sample['source_page_count'];
                $includedPageCount = $sample['included_page_count'];
            }

            if ($extension === 'xlsx') {
                $preparedBytes = app(\App\Services\StoreXlsxPreviewRenderer::class)->render(
                    $sourceBytes,
                    '__FARAST_READER_IDENTITY__',
                    $accessLevel === 'sample'
                );
            }

            $directory = 'store/previews/'.$product->id;
            $filename = Str::random(40).'.'.($kind === 'html' ? 'html' : ($kind === 'pdf' ? 'pdf' : $extension));
            $preparedPath = $directory.'/'.$filename;
            abort_unless(Storage::disk('private')->put($preparedPath, $preparedBytes), 503, 'ذخیره نسخه آماده پیش‌نمایش ممکن نشد.');

            Storage::disk('private')->delete($sourcePath);

            DB::transaction(function () use ($product, $preparedPath, $mime, $request, $kind, $accessLevel, $sourcePageCount, $includedPageCount) {
                $lockedProduct = StoreProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
                $sort = (int) ($lockedProduct->previews()->max('sort_order') ?? 0) + 1;
                StoreProductPreview::create([
                    'product_id' => $lockedProduct->id,
                    'disk' => 'private',
                    'path' => $preparedPath,
                    'mime' => $mime,
                    'page_number' => $request->input('page_number') ?: $sort,
                    'source_page_count' => $sourcePageCount,
                    'included_page_count' => $includedPageCount,
                    'kind' => $kind,
                    'access_level' => $accessLevel,
                    'watermarked' => true,
                    'is_active' => true,
                    'sort_order' => $sort,
                ]);
                $this->audit('store.product.preview_uploaded', $lockedProduct, [
                    'mime' => $mime,
                    'kind' => $kind,
                    'access_level' => $accessLevel,
                    'source_page_count' => $sourcePageCount,
                    'included_page_count' => $includedPageCount,
                    'sort_order' => $sort,
                ]);
            });
        } catch (Throwable $exception) {
            Storage::disk('private')->delete($sourcePath);
            if ($preparedPath) {
                Storage::disk('private')->delete($preparedPath);
            }
            if ($exception instanceof ValidationException) {
                throw $exception;
            }
            report($exception);
            abort(503, 'آماده‌سازی امن پیش‌نمایش ناموفق بود؛ فایل اصلی در خوانشگر منتشر نشد.');
        }

        $message = $includedPageCount !== null
            ? 'پیش‌نمایش آماده شد؛ فقط '.$includedPageCount.' صفحه از '.$sourcePageCount.' صفحهٔ سند در نسخهٔ نمونه ذخیره شد.'
            : 'پیش‌نمایش آماده و خصوصی ذخیره شد؛ خوانشگر فقط نسخهٔ آماده را نمایش می‌دهد.';

        return back()->with('status', $message);
    }

    public function uploadPreviewAudio(Request $request, StoreProduct $product, StoreProductPreview $preview, UploadSecurityService $security)
    {
        abort_unless((int) $preview->product_id === (int) $product->id, 404);
        $data = $request->validate([
            'audio' => ['required', 'file', 'max:102400', 'mimes:mp3,m4a,wav,ogg,webm'],
            'timings' => ['required', 'file', 'max:2048', 'mimes:json,txt'],
        ]);

        $decoded = json_decode(file_get_contents($data['timings']->getRealPath()), true);
        abort_unless(is_array($decoded) && array_is_list($decoded) && count($decoded) > 0 && count($decoded) <= 100000, 422, 'فایل زمان‌بندی باید آرایه‌ای از قطعه‌های متنی باشد.');

        $timings = [];
        $previousStart = -1.0;
        foreach ($decoded as $index => $item) {
            abort_unless(is_array($item)
                && isset($item['text'], $item['start'], $item['end'])
                && is_string($item['text'])
                && trim($item['text']) !== ''
                && is_numeric($item['start'])
                && is_numeric($item['end']), 422, 'ساختار زمان‌بندی در ردیف '.($index + 1).' معتبر نیست.');
            $start = (float) $item['start'];
            $end = (float) $item['end'];
            abort_unless($start >= 0 && $end > $start && $start >= $previousStart, 422, 'زمان‌های شروع و پایان باید معتبر و مرتب باشند.');
            $timings[] = [
                'text' => trim($item['text']),
                'start' => round($start, 3),
                'end' => round($end, 3),
                'page' => max(1, (int) ($item['page'] ?? 1)),
            ];
            $previousStart = $start;
        }

        $audio = $data['audio'];
        $extension = strtolower($audio->getClientOriginalExtension());
        $mime = $audio->getMimeType() ?: 'application/octet-stream';
        $quarantinePath = $audio->store('store/quarantine/reader-audio/'.$product->id, 'private');
        $path = 'store/reader-audio/'.$product->id.'/'.Str::random(40).'.'.$extension;
        $databaseCommitted = false;
        try {
            $inspection = $security->inspect($quarantinePath, $mime, 100 * 1024 * 1024, [
                'audio/mpeg', 'audio/mp4', 'audio/wav', 'audio/x-wav',
                'audio/ogg', 'audio/webm', 'application/ogg',
            ]);
            abort_unless($inspection['safe'] ?? false, 503, 'اسکن امنیتی صوت آماده نیست؛ فایل ذخیره نشد.');
            abort_unless(
                hash_equals((string) $inspection['checksum'], (string) hash_file('sha256', $audio->getRealPath())),
                409,
                'محتوای صوت هنگام انتقال تغییر کرده است.'
            );
            abort_unless(Storage::disk('private')->copy($quarantinePath, $path), 503, 'ذخیره فایل صوتی ممکن نشد.');

            [$oldDisk, $oldPath] = DB::transaction(function () use ($product, $preview, $path, $mime, $timings) {
                $lockedProduct = StoreProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
                $lockedPreview = StoreProductPreview::query()
                    ->whereKey($preview->id)
                    ->where('product_id', $lockedProduct->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $old = [$lockedPreview->audio_disk ?: 'private', $lockedPreview->audio_path];
                $lockedPreview->forceFill([
                    'audio_disk' => 'private',
                    'audio_path' => $path,
                    'audio_mime' => $mime,
                    'audio_timing' => $timings,
                ])->save();
                $this->audit('store.product.preview_audio_uploaded', $lockedProduct, [
                    'preview_id' => $lockedPreview->id,
                    'timing_segments' => count($timings),
                    'audio_mime' => $mime,
                ]);

                return $old;
            });
            $databaseCommitted = true;
            if ($oldPath) {
                Storage::disk($oldDisk)->delete($oldPath);
            }
        } catch (Throwable $exception) {
            if (! $databaseCommitted) {
                Storage::disk('private')->delete($path);
            }
            if ($exception instanceof ValidationException) {
                throw $exception;
            }
            throw $exception;
        } finally {
            Storage::disk('private')->delete($quarantinePath);
        }

        return back()->with('status', 'صوت و زمان‌بندی همگام‌سازی برای این پیش‌نمایش ذخیره شد.');
    }

    public function uploadCover(Request $request, StoreProduct $product, UploadSecurityService $security)
    {
        $request->validate(['cover' => 'required|image|mimes:jpg,jpeg,png,webp|max:8192']);
        $upload = $request->file('cover');
        $temporaryPath = $upload->store('store/quarantine/covers/'.$product->id, 'private');
        $storagePath = null;

        try {
            $inspection = $security->inspect($temporaryPath, $upload->getMimeType(), 8 * 1024 * 1024, [
                'image/jpeg', 'image/png', 'image/webp',
            ]);
            abort_unless($inspection['safe'] ?? false, 503, 'اسکن ضدبدافزار آماده نیست؛ کاور ذخیره نشد.');
            abort_unless(hash_equals($inspection['checksum'], (string) hash_file('sha256', $upload->getRealPath())), 409, 'محتوای کاور هنگام انتقال تغییر کرده است.');

            $extension = strtolower($upload->guessExtension() ?: 'jpg');
            $filename = Str::random(32).'.'.$extension;
            $storagePath = 'store/covers/'.$product->id.'/'.$filename;
            $publicPath = 'storage/'.$storagePath;
            $stored = Storage::disk('public')->putFileAs('store/covers/'.$product->id, $upload, $filename);
            if (! $stored) {
                throw new \RuntimeException('cover_public_storage_failed');
            }

            $oldPath = DB::transaction(function () use ($product, $publicPath, $inspection) {
                $lockedProduct = StoreProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
                $oldPath = (string) $lockedProduct->cover_path;
                $metadata = $lockedProduct->metadata ?? [];
                $supervisorMeta = (array) data_get($metadata, 'ai_supervisor', []);
                $assetReviews = (array) data_get($supervisorMeta, 'asset_reviews', []);
                unset($assetReviews['cover']);
                if ($assetReviews === []) {
                    unset($supervisorMeta['asset_reviews']);
                } else {
                    $supervisorMeta['asset_reviews'] = $assetReviews;
                }
                $metadata['ai_supervisor'] = $supervisorMeta;
                $lockedProduct->update(['cover_path' => $publicPath, 'metadata' => $metadata]);
                $this->audit('store.product.cover_updated', $lockedProduct, ['mime' => $inspection['mime'], 'sha256' => $inspection['checksum']]);

                return $oldPath;
            });
            if ($oldPath !== '' && Str::startsWith($oldPath, 'storage/store/covers/'.$product->id.'/')) {
                Storage::disk('public')->delete(Str::after($oldPath, 'storage/'));
            }
        } catch (Throwable $exception) {
            Storage::disk('private')->delete($temporaryPath);
            if ($storagePath) {
                Storage::disk('public')->delete($storagePath);
            }
            if ($exception instanceof ValidationException) {
                throw $exception;
            }
            abort(503, 'بارگذاری کاور به دلیل ناموفق بودن بررسی امنیتی یا ذخیره‌سازی متوقف شد.');
        }

        Storage::disk('private')->delete($temporaryPath);

        return back()->with('status', 'کاور محصول پس از اسکن امنیتی ذخیره شد.');
    }

    public function uploadImage(Request $request, StoreProduct $product, UploadSecurityService $security)
    {
        $data = $request->validate([
            'image' => 'required|image|mimes:jpg,jpeg,png,webp|max:8192',
            'alt' => 'nullable|string|max:180',
        ]);
        $upload = $request->file('image');
        $temporaryPath = $upload->store('store/quarantine/product-images/'.$product->id, 'private');
        $publicPath = null;

        try {
            $inspection = $security->inspect($temporaryPath, $upload->getMimeType(), 8 * 1024 * 1024, [
                'image/jpeg', 'image/png', 'image/webp',
            ]);
            abort_unless($inspection['safe'] ?? false, 503, 'اسکن ضدبدافزار آماده نیست؛ تصویر ذخیره نشد.');
            abort_unless(hash_equals($inspection['checksum'], (string) hash_file('sha256', $upload->getRealPath())), 409, 'محتوای تصویر هنگام انتقال تغییر کرده است.');

            $extension = strtolower($upload->guessExtension() ?: 'jpg');
            $filename = Str::random(32).'.'.$extension;
            $directory = 'store/product-images/'.$product->id;
            $stored = Storage::disk('public')->putFileAs($directory, $upload, $filename);
            if (! $stored) {
                throw new \RuntimeException('product_image_storage_failed');
            }
            $publicPath = 'storage/'.$directory.'/'.$filename;

            DB::transaction(function () use ($product, $data, $publicPath, $inspection) {
                $lockedProduct = StoreProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
                $sort = (int) ($lockedProduct->images()->max('sort_order') ?? 0) + 1;
                $image = $lockedProduct->images()->create([
                    'path' => $publicPath,
                    'alt' => $data['alt'] ?: $lockedProduct->title,
                    'kind' => 'image',
                    'sort_order' => $sort,
                    'is_active' => true,
                ]);
                $this->audit('store.product.image_uploaded', $lockedProduct, [
                    'image_id' => $image->id,
                    'mime' => $inspection['mime'],
                    'sha256' => $inspection['checksum'],
                ]);
            });
        } catch (Throwable $exception) {
            Storage::disk('private')->delete($temporaryPath);
            if ($publicPath) {
                Storage::disk('public')->delete(Str::after($publicPath, 'storage/'));
            }
            if ($exception instanceof ValidationException) {
                throw $exception;
            }
            abort(503, 'بارگذاری تصویر به دلیل ناموفق بودن بررسی امنیتی یا ذخیره‌سازی متوقف شد.');
        }

        Storage::disk('private')->delete($temporaryPath);

        return back()->with('status', 'تصویر گالری پس از اسکن امنیتی اضافه شد.');
    }

    public function deleteImage(StoreProduct $product, StoreProductImage $image)
    {
        abort_unless($image->product_id === $product->id, 404);

        $path = DB::transaction(function () use ($product, $image) {
            $lockedProduct = StoreProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $lockedImage = $lockedProduct->images()->whereKey($image->id)->lockForUpdate()->firstOrFail();
            $path = (string) $lockedImage->path;
            $this->audit('store.product.image_deleted', $lockedProduct, ['image_id' => $lockedImage->id]);
            $lockedImage->delete();

            return $path;
        });

        $ownedPrefix = 'storage/store/product-images/'.$product->id.'/';
        if (Str::startsWith($path, $ownedPrefix)) {
            Storage::disk('public')->delete(Str::after($path, 'storage/'));
        }

        return back()->with('status', 'تصویر گالری حذف شد.');
    }

    public function deletePreview(StoreProduct $product, StoreProductPreview $preview)
    {
        abort_unless($preview->product_id === $product->id, 404);
        [$disk, $path, $audioDisk, $audioPath] = DB::transaction(function () use ($product, $preview) {
            $lockedProduct = StoreProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $lockedPreview = $lockedProduct->previews()->whereKey($preview->id)->lockForUpdate()->firstOrFail();
            $this->audit('store.product.preview_deleted', $lockedProduct, ['preview_id' => $lockedPreview->id]);
            $disk = $lockedPreview->disk ?: 'private';
            $path = $lockedPreview->path;
            $audioDisk = $lockedPreview->audio_disk ?: 'private';
            $audioPath = $lockedPreview->audio_path;
            $lockedPreview->delete();

            return [$disk, $path, $audioDisk, $audioPath];
        });
        Storage::disk($disk)->delete($path);
        if ($audioPath) {
            Storage::disk($audioDisk)->delete($audioPath);
        }

        return back()->with('status', 'پیش‌نمایش حذف شد.');
    }

    public function storeTag(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'slug' => 'nullable|string|max:120',
        ]);
        $base = Str::slug($data['slug'] ?: $data['name']) ?: 'tag-'.Str::random(6);
        $slug = $base;
        $suffix = 1;
        while (StoreTag::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }
        $tag = StoreTag::firstOrCreate(
            ['slug' => $slug],
            ['name' => $data['name']]
        );

        if ($request->expectsJson()) {
            return response()->json(['id' => $tag->id, 'name' => $tag->name], 201);
        }

        return back()->with('status', 'برچسب ذخیره شد؛ اکنون می‌توانید آن را به محصول اختصاص دهید.');
    }

    public function storeCategory(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:160',
            'slug' => 'nullable|string|max:180',
        ]);
        $slug = Str::slug($data['slug'] ?: $data['name']) ?: 'cat-'.Str::random(6);
        $category = StoreCategory::firstOrCreate(
            ['slug' => $slug],
            ['name' => $data['name'], 'is_active' => true, 'sort_order' => 0]
        );

        if ($request->expectsJson()) {
            return response()->json(['id' => $category->id, 'name' => $category->name], 201);
        }

        return back()->with('status', 'دسته ذخیره شد.');
    }

    private function audit(string $eventType, StoreProduct $product, array $meta = []): void
    {
        app(\App\Services\AuditEventService::class)->record(
            $eventType,
            auth()->id(),
            null,
            'store_product',
            $product->id,
            $meta,
            'admin_console'
        );
    }

    private function validated(Request $request, ?StoreProduct $product = null): array
    {
        $data = $request->validate([
            'category_id' => 'required|exists:store_categories,id',
            'title' => 'required|string|max:220',
            'slug' => 'nullable|string|max:240',
            'sku' => 'required|string|max:80|unique:store_products,sku,'.($product?->id ?? 'NULL'),
            'type' => 'required|in:digital_file,course',
            'short_description' => 'nullable|string|max:2000',
            'description' => 'nullable|string|max:20000',
            'price_rials' => 'required|integer|min:0|max:999999999999',
            'compare_at_price_rials' => 'nullable|integer|min:0|gt:price_rials',
            'status' => 'required|in:draft,published,archived',
            'featured' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:999999',
            'preview_pages' => 'nullable|integer|min:0|max:50',
            'license_type' => 'nullable|string|max:60',
            'download_policy' => 'sometimes|in:signed,reader_only',
            'version' => 'nullable|string|max:60',
            'seo_title' => 'nullable|string|max:220',
            'seo_description' => 'nullable|string|max:320',
            'tag_ids' => 'sometimes|array|max:30',
            'tag_ids.*' => 'integer|distinct|exists:store_tags,id',
            'related_product_ids' => 'sometimes|array|max:12',
            'related_product_ids.*' => 'integer|distinct|exists:store_products,id',
            'ai_supervisor.enabled' => 'nullable|boolean',
            'ai_supervisor.mode' => 'nullable|in:suggest,review_gate',
            'ai_supervisor.checks.content' => 'nullable|boolean',
            'ai_supervisor.checks.pricing' => 'nullable|boolean',
            'ai_supervisor.checks.seo' => 'nullable|boolean',
            'ai_supervisor.checks.media' => 'nullable|boolean',
            'ai_supervisor.checks.policy' => 'nullable|boolean',
            'ai_supervisor.checks.course' => 'nullable|boolean',
            'ai_supervisor.instructions' => 'nullable|string|max:4000',
        ]);
        $existingMetadata = $product?->metadata ?? [];
        $data['metadata'] = $existingMetadata;
        if ($request->has('ai_supervisor')) {
            $supervisorChecks = [
                'content' => $request->boolean('ai_supervisor.checks.content'),
                'pricing' => $request->boolean('ai_supervisor.checks.pricing'),
                'seo' => $request->boolean('ai_supervisor.checks.seo'),
                'media' => $request->boolean('ai_supervisor.checks.media'),
                'policy' => $request->boolean('ai_supervisor.checks.policy'),
                'course' => $request->boolean('ai_supervisor.checks.course'),
            ];
            $data['metadata'] = array_merge($existingMetadata, [
                'ai_supervisor' => [
                    'enabled' => $request->boolean('ai_supervisor.enabled'),
                    'mode' => $request->input('ai_supervisor.mode', 'suggest'),
                    'checks' => $supervisorChecks,
                    'instructions' => trim((string) $request->input('ai_supervisor.instructions', '')),
                    'configuration_version' => 1,
                ],
            ]);
        }
        $data['tag_ids'] = $request->input('tag_ids', []);
        $data['related_product_ids'] = array_values(array_filter($request->input('related_product_ids', []), fn ($id) => ! $product || (int) $id !== (int) $product->id));
        $data['featured'] = $request->boolean('featured');
        $data['sort_order'] = $data['sort_order'] ?? 0;
        $data['preview_pages'] = $data['preview_pages'] ?? 3;

        return $data;
    }

    private function uniqueSlug(string $title, ?string $slug = null, ?int $ignoreId = null): string
    {
        $base = Str::slug($slug ?: $title) ?: 'product-'.Str::random(6);
        $candidate = $base;
        $i = 1;
        while (StoreProduct::where('slug', $candidate)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $candidate = $base.'-'.$i++;
        }

        return $candidate;
    }
}
