<?php

namespace App\Http\Controllers;

use App\Models\StoreCategory;
use App\Models\StoreCourse;
use App\Models\StoreProduct;
use App\Models\StoreProductFile;
use App\Models\StoreProductImage;
use App\Models\StoreProductPreview;
use App\Models\StoreTag;
use App\Services\LocalFileInspector;
use App\Services\ProductSupervisorSnapshotBuilder;
use App\Services\UploadSecurityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    public function edit(StoreProduct $product, ProductSupervisorSnapshotBuilder $snapshotBuilder)
    {
        $product->load(['files.documentRecord', 'previews', 'images', 'category', 'course.sections.lessons', 'tags', 'related']);
        $categories = StoreCategory::orderBy('sort_order')->get();
        $tags = StoreTag::query()->orderBy('name')->get();
        $relatedProducts = StoreProduct::query()->where('id', '!=', $product->id)->where('status', '!=', 'archived')->orderBy('title')->get(['id', 'title', 'sku', 'type']);

        $hasSafePrimaryFile = $product->files->contains(fn ($file) => $file->purpose === 'product_download'
            && $file->is_active && $file->is_primary && $file->scan_status === 'clean');
        $hasFullReaderAsset = $product->previews->contains(fn ($preview) => $preview->is_active && $preview->access_level === 'full');
        $contentReady = $product->type === 'course'
            ? ($product->course?->sections()->whereHas('lessons', fn ($query) => $query->publishable())->exists() ?? false)
            : ($hasSafePrimaryFile && (($product->download_policy ?? 'signed') !== 'reader_only' || $hasFullReaderAsset));

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

    public function update(Request $request, StoreProduct $product, ProductSupervisorSnapshotBuilder $snapshotBuilder)
    {
        $data = $this->validated($request, $product);
        $tagIds = $data['tag_ids'] ?? [];
        $relatedIds = $data['related_product_ids'] ?? [];
        unset($data['tag_ids'], $data['related_product_ids']);
        if (! empty($data['slug'])) {
            $data['slug'] = $this->uniqueSlug($data['title'], $data['slug'], $product->id);
        }

        DB::transaction(function () use ($product, $data, $tagIds, $relatedIds, $snapshotBuilder) {
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
                    if ($downloadPolicy === 'reader_only' && ! $lockedProduct->previews()->where('is_active', true)->where('access_level', 'full')->exists()) {
                        throw ValidationException::withMessages(['status' => 'برای انتشار محصول فقط‌خواندنی، ابتدا محتوای کامل را در بخش خوانشگر بارگذاری کنید.']);
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

    public function inspectFiles(Request $request, StoreProduct $product, LocalFileInspector $inspector)
    {
        abort_unless($product->type !== 'course', 422, 'برای دوره، منابع را در بخش جلسات بارگذاری کنید.');
        $request->validate([
            'files' => 'required|array|min:1|max:10',
            'files.*' => 'required|file|max:51200|mimes:pdf,zip,jpg,jpeg,png,webp,gif,docx,xlsx,pptx,txt,md,csv,mp3,m4a,wav,mp4,webm',
        ]);

        $createdPaths = [];
        $intakes = [];
        try {
            foreach ($request->file('files', []) as $upload) {
                $details = $inspector->inspect($upload);
                $id = (string) Str::uuid();
                $extension = preg_replace('/[^a-z0-9]+/i', '', (string) $details['extension']) ?: 'bin';
                $path = $upload->storeAs('store/quarantine/intakes/'.$product->id, $id.'.'.$extension, 'private');
                if (! $path) {
                    throw new \\RuntimeException('intake_stage_write_failed');
                }
                $createdPaths[] = $path;
                DB::table('store_file_intakes')->insert([
                    'id' => $id,
                    'product_id' => $product->id,
                    'user_id' => auth()->id(),
                    'disk' => 'private',
                    'path' => $path,
                    'original_name' => $details['original_name'],
                    'mime' => $details['mime'],
                    'extension' => $details['extension'],
                    'size_bytes' => $details['size_bytes'],
                    'sha256' => $details['sha256'],
                    'status' => 'awaiting_confirmation',
                    'inspection_json' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'expires_at' => now()->addMinutes(30),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $intakes[] = ['id' => $id, 'inspection' => $details];
            }
        } catch (\\Throwable $exception) {
            foreach ($createdPaths as $stagedPath) {
                Storage::disk('private')->delete($stagedPath);
            }
            throw $exception;
        }

        return response()->json([
            'ok' => true,
            'intakes' => $intakes,
            'message' => 'بررسی اولیه محلی انجام شد. هنوز هیچ فایلی در مخزن نهایی ثبت نشده است.',
        ]);
    }

    public function confirmFiles(Request $request, StoreProduct $product, UploadSecurityService $security)
    {
        abort_unless($product->type !== 'course', 422);
        $data = $request->validate([
            'intake_ids' => 'required|array|min:1|max:10',
            'intake_ids.*' => 'required|uuid',
            'is_primary' => 'nullable|boolean',
        ]);

        $intakes = DB::table('store_file_intakes')
            ->where('product_id', $product->id)
            ->where('user_id', auth()->id())
            ->whereIn('id', $data['intake_ids'])
            ->where('status', 'awaiting_confirmation')
            ->where('expires_at', '>', now())
            ->orderBy('created_at')
            ->get();

        abort_unless($intakes->count() === count(array_unique($data['intake_ids'])), 409, 'جلسه بررسی منقضی شده یا معتبر نیست.');

        $allowedMimes = [
            'application/pdf', 'application/zip', 'image/jpeg', 'image/png', 'image/webp', 'image/gif',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/plain', 'text/markdown', 'text/csv', 'audio/mpeg', 'audio/mp4', 'audio/wav',
            'video/mp4', 'video/webm',
        ];

        $prepared = [];
        foreach ($intakes as $intake) {
            $disk = Storage::disk($intake->disk);
            abort_unless($disk->exists($intake->path), 404, 'یکی از فایل‌های موقت دیگر موجود نیست.');
            $scan = $security->inspect($intake->path, $intake->mime, 50 * 1024 * 1024, $allowedMimes);
            abort_unless(($scan['safe'] ?? false) && hash_equals((string) $intake->sha256, (string) $scan['checksum']), 422, 'بررسی امنیتی یکی از فایل‌ها موفق نبود.');
            $prepared[] = ['intake' => $intake, 'scan' => $scan];
        }

        $moved = [];
        $records = [];
        try {
            foreach ($prepared as $item) {
                $intake = $item['intake'];
                $extension = preg_replace('/[^a-z0-9]+/i', '', (string) $intake->extension) ?: 'bin';
                $finalPath = 'store/products/'.$product->id.'/'.Str::random(40).'.'.$extension;
                if (! Storage::disk('private')->move($intake->path, $finalPath)) {
                    throw new \\RuntimeException('intake_final_storage_move_failed');
                }
                $moved[] = $finalPath;
                $records[] = ['intake' => $intake, 'scan' => $item['scan'], 'path' => $finalPath];
            }

            $saved = DB::transaction(function () use ($product, $records, $request) {
                $lockedProduct = StoreProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
                $primaryPending = $request->boolean('is_primary');
                $created = [];
                if ($primaryPending) {
                    $lockedProduct->files()->where('purpose', 'product_download')->update(['is_primary' => false]);
                }

                foreach ($records as $index => $item) {
                    $intake = $item['intake'];
                    $scan = $item['scan'];
                    $file = StoreProductFile::create([
                        'product_id' => $lockedProduct->id,
                        'purpose' => 'product_download',
                        'version' => $lockedProduct->version,
                        'disk' => 'private',
                        'path' => $item['path'],
                        'original_name' => $intake->original_name,
                        'mime' => $scan['mime'],
                        'size_bytes' => $scan['size'],
                        'sha256' => $scan['checksum'],
                        'is_primary' => $primaryPending && $index === 0,
                        'is_active' => true,
                        'scan_status' => 'clean',
                        'scan_engine' => $scan['scan_engine'] ?? 'farast-static-policy-v1',
                        'scanned_at' => now(),
                    ]);

                    $document = \\App\\Models\\StoreDocumentRecord::create([
                        'product_id' => $lockedProduct->id,
                        'file_id' => $file->id,
                        'source_sha256' => $file->sha256,
                        'detected_mime' => $file->mime,
                        'detected_type' => data_get(json_decode((string) $intake->inspection_json, true), 'detected_type'),
                        'status' => 'queued',
                        'content_version' => 1,
                        'knowledge_version' => 0,
                        'metadata' => [
                            'intake_id' => $intake->id,
                            'local_inspection' => json_decode((string) $intake->inspection_json, true),
                            'static_scan_engine' => $scan['scan_engine'] ?? 'farast-static-policy-v1',
                            'static_scan_findings' => $scan['scan_findings'] ?? [],
                            'static_scan_disclaimer' => $scan['scan_disclaimer'] ?? null,
                        ],
                    ]);

                    DB::table('store_file_intakes')->where('id', $intake->id)->update([
                        'status' => 'stored',
                        'confirmed_file_id' => $file->id,
                        'updated_at' => now(),
                    ]);
                    $created[] = ['id' => $file->id, 'document_id' => $document->id, 'name' => $file->original_name, 'status' => 'queued'];
                    $this->audit('store.product.file_uploaded', $lockedProduct, [
                        'file_id' => $file->id,
                        'mime' => $file->mime,
                        'size_bytes' => $file->size_bytes,
                        'sha256' => $file->sha256,
                    ]);
                }

                return $created;
            });
        } catch (\\Throwable $exception) {
            foreach ($moved as $finalPath) {
                Storage::disk('private')->delete($finalPath);
            }
            throw $exception;
        }

        foreach ($saved as $item) {
            \\App\\Jobs\\ProcessDocumentKnowledgeJob::dispatch((int) $item['document_id'])->onQueue('document-analysis');
        }

        return response()->json([
            'ok' => true,
            'files' => $saved,
            'message' => 'اسکن داخلی تکمیل شد و فایل‌ها در فضای ذخیره‌سازی امن ثبت شدند. پردازش محتوایی در صف قرار گرفت.',
        ]);
    }

    public function cancelFileIntakes(Request $request, StoreProduct $product)
    {
        $data = $request->validate([
            'intake_ids' => 'required|array|min:1|max:10',
            'intake_ids.*' => 'required|uuid',
        ]);

        $intakes = DB::table('store_file_intakes')
            ->where('product_id', $product->id)
            ->where('user_id', auth()->id())
            ->where('status', 'awaiting_confirmation')
            ->whereIn('id', $data['intake_ids'])
            ->get();

        foreach ($intakes as $intake) {
            Storage::disk($intake->disk)->delete($intake->path);
            DB::table('store_file_intakes')->where('id', $intake->id)->delete();
        }

        return response()->json(['ok' => true]);
    }

    public function uploadFile(Request $request, StoreProduct $product, UploadSecurityService $security)
    {
        abort_unless($product->type !== 'course', 422, 'برای دوره، فایل‌ها را در بخش منابع هر جلسه بارگذاری کنید.');
        $request->validate([
            'file' => 'required|file|max:51200|mimes:pdf,zip,jpg,jpeg,png,webp,gif,doc,docx,xls,xlsx,ppt,pptx,txt,mp3,mp4,webm,m4a',
            'is_primary' => 'nullable|boolean',
        ]);

        $upload = $request->file('file');
        $path = $upload->store('store/products/'.$product->id, 'private');
        try {
            $allowed = [
                'application/pdf', 'application/zip', 'image/jpeg', 'image/png', 'image/webp', 'image/gif',
                'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'text/plain', 'audio/mpeg', 'audio/mp4', 'video/mp4', 'video/webm',
            ];
            $inspection = $security->inspect($path, $upload->getMimeType(), 50 * 1024 * 1024, $allowed);
            abort_unless($inspection['safe'] ?? false, 503, 'اسکن ضدبدافزار آماده نیست؛ فایل تا تأیید امنیتی قابل انتشار نخواهد بود.');

            $primary = $request->boolean('is_primary');
            $fileRecord = DB::transaction(function () use ($product, $path, $upload, $inspection, $primary) {
                $lockedProduct = StoreProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
                if ($primary) {
                    $lockedProduct->files()->where('purpose', 'product_download')->update(['is_primary' => false]);
                }

                $record = StoreProductFile::create([
                    'product_id' => $lockedProduct->id,
                    'purpose' => 'product_download',
                    'version' => $product->version,
                    'disk' => 'private',
                    'path' => $path,
                    'original_name' => $upload->getClientOriginalName(),
                    'mime' => $inspection['mime'],
                    'size_bytes' => $inspection['size'],
                    'sha256' => $inspection['checksum'],
                    'is_primary' => $primary,
                    'is_active' => true,
                    'scan_status' => 'clean',
                    'scan_engine' => $inspection['scan'],
                    'scanned_at' => now(),
                ]);
                $this->audit('store.product.file_uploaded', $lockedProduct, ['file_id' => $record->id, 'mime' => $record->mime, 'size_bytes' => $record->size_bytes, 'sha256' => $record->sha256]);

                return $record;
            });
        } catch (Throwable $exception) {
            Storage::disk('private')->delete($path);
            if ($exception instanceof ValidationException) {
                throw $exception;
            }
            abort(503, 'بارگذاری به دلیل ناموفق بودن بررسی امنیتی متوقف شد.');
        }

        return back()->with('status', 'فایل اصلی پس از اسکن امنیتی ذخیره شد.');
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
        try {
            $inspection = $security->inspect($sourcePath, $upload->getMimeType(), 20 * 1024 * 1024, [
                'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf', 'application/zip',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]);
            abort_unless($inspection['safe'] ?? false, 503, 'اسکن ضدبدافزار آماده نیست؛ پیش‌نمایش ذخیره نشد.');

            $kind = match ($extension) {
                'pdf' => 'pdf',
                'docx' => 'pdf',
                'xlsx' => 'html',
                default => 'image',
            };
            $mime = $kind === 'pdf' ? 'application/pdf' : ($kind === 'html' ? 'text/html' : $inspection['mime']);
            $finalPath = $sourcePath;

            if ($extension === 'docx') {
                $preparedBytes = app(StoreController::class)->prepareDocxPreview(Storage::disk('private')->get($sourcePath));
                $preparedPath = 'store/previews/'.$product->id.'/'.Str::random(40).'.pdf';
                abort_unless(Storage::disk('private')->put($preparedPath, $preparedBytes), 503, 'ذخیره نسخه آماده PDF ممکن نشد.');
                $finalPath = $preparedPath;
            } elseif ($extension === 'xlsx') {
                $preparedBytes = app(\App\Services\StoreXlsxPreviewRenderer::class)->render(
                    Storage::disk('private')->get($sourcePath),
                    ''
                );
                $preparedPath = 'store/previews/'.$product->id.'/'.Str::random(40).'.html';
                abort_unless(Storage::disk('private')->put($preparedPath, $preparedBytes), 503, 'ذخیره نسخه آماده Excel ممکن نشد.');
                $finalPath = $preparedPath;
            } else {
                $directory = 'store/previews/'.$product->id;
                $filename = Str::random(40).'.'.$extension;
                $preparedPath = $directory.'/'.$filename;
                abort_unless(Storage::disk('private')->put($preparedPath, Storage::disk('private')->get($sourcePath)), 503, 'ذخیره فایل آماده ممکن نشد.');
                $finalPath = $preparedPath;
            }

            if ($finalPath !== $sourcePath) {
                Storage::disk('private')->delete($sourcePath);
            }

            DB::transaction(function () use ($product, $finalPath, $mime, $request, $kind, $accessLevel) {
                $lockedProduct = StoreProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
                $sort = (int) ($lockedProduct->previews()->max('sort_order') ?? 0) + 1;
                StoreProductPreview::create([
                    'product_id' => $lockedProduct->id,
                    'disk' => 'private',
                    'path' => $finalPath,
                    'mime' => $mime,
                    'page_number' => $request->input('page_number') ?: $sort,
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
            abort(503, 'آماده‌سازی پیش‌نمایش به دلیل ناموفق بودن بررسی امنیتی یا تبدیل فایل متوقف شد.');
        }

        return back()->with('status', 'پیش‌نمایش آماده و خصوصی ذخیره شد؛ خوانشگر فقط نسخهٔ آماده را نمایش می‌دهد.');
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
        [$disk, $path] = DB::transaction(function () use ($product, $preview) {
            $lockedProduct = StoreProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $lockedPreview = $lockedProduct->previews()->whereKey($preview->id)->lockForUpdate()->firstOrFail();
            $this->audit('store.product.preview_deleted', $lockedProduct, ['preview_id' => $lockedPreview->id]);
            $disk = $lockedPreview->disk ?: 'private';
            $path = $lockedPreview->path;
            $lockedPreview->delete();

            return [$disk, $path];
        });
        Storage::disk($disk)->delete($path);

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
