<?php

namespace App\Http\Controllers;

use App\Models\StoreCategory;
use App\Models\StoreCourse;
use App\Models\StoreProduct;
use App\Models\StoreProductFile;
use App\Models\StoreProductImage;
use App\Models\StoreProductPreview;
use App\Models\StoreTag;
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

    public function edit(StoreProduct $product)
    {
        $product->load(['files', 'previews', 'images', 'category', 'course.sections.lessons', 'tags', 'related']);
        $categories = StoreCategory::orderBy('sort_order')->get();
        $tags = StoreTag::query()->orderBy('name')->get();
        $relatedProducts = StoreProduct::query()->where('id', '!=', $product->id)->where('status', '!=', 'archived')->orderBy('title')->get(['id', 'title', 'sku', 'type']);

        $contentReady = $product->type === 'course'
            ? ($product->course?->sections()->whereHas('lessons', fn ($query) => $query->publishable())->exists() ?? false)
            : $product->files->contains(fn ($file) => $file->purpose === 'product_download'
                && $file->is_active && $file->is_primary && $file->scan_status === 'clean');

        $supervisorEnabled = (bool) data_get($product->metadata, 'ai_supervisor.enabled', false);
        $readiness = [
            ['label' => 'مشخصات و دسته‌بندی', 'ready' => filled($product->title) && filled($product->sku) && $product->category !== null && (bool) $product->category?->is_active, 'required' => true],
            ['label' => 'قیمت‌گذاری', 'ready' => $product->price_rials !== null && $product->price_rials >= 0 && ($product->compare_at_price_rials === null || $product->compare_at_price_rials > $product->price_rials), 'required' => true],
            ['label' => 'معرفی محصول', 'ready' => filled($product->short_description) || filled($product->description), 'required' => false],
            ['label' => 'تصویر کاور', 'ready' => filled($product->cover_path), 'required' => false],
            ['label' => $product->type === 'course' ? 'حداقل یک جلسه معتبر و منتشرشده' : 'فایل اصلی سالم و فعال', 'ready' => $contentReady, 'required' => true],
            ['label' => 'اطلاعات سئو', 'ready' => filled($product->seo_title) && filled($product->seo_description), 'required' => false],
            ['label' => 'سیاست ناظر هوشمند', 'ready' => ! $supervisorEnabled || filled(data_get($product->metadata, 'ai_supervisor.mode')), 'required' => false],
        ];
        $publicationReady = collect($readiness)->where('required', true)->every(fn ($item) => $item['ready']);

        return view('admin.store.form', compact('product', 'categories', 'tags', 'relatedProducts', 'readiness', 'publicationReady'));
    }

    public function update(Request $request, StoreProduct $product)
    {
        $data = $this->validated($request, $product);
        $tagIds = $data['tag_ids'] ?? [];
        $relatedIds = $data['related_product_ids'] ?? [];
        unset($data['tag_ids'], $data['related_product_ids']);
        if (! empty($data['slug'])) {
            $data['slug'] = $this->uniqueSlug($data['title'], $data['slug'], $product->id);
        }

        DB::transaction(function () use ($product, $data, $tagIds, $relatedIds) {
            $lockedProduct = StoreProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            if ($data['type'] !== $lockedProduct->type) {
                throw ValidationException::withMessages([
                    'type' => 'نوع محصول پس از ایجاد قابل تغییر نیست؛ برای نوع دیگر یک محصول جدید بسازید تا فایل‌ها و دسترسی‌های قبلی مخلوط نشوند.',
                ]);
            }

            if (($data['status'] ?? '') === 'published') {
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
                }
                if (! $lockedProduct->published_at && empty($data['published_at'])) {
                    $data['published_at'] = now();
                }
            } else {
                $data['published_at'] = null;
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
        ]);

        $upload = $request->file('preview');
        $extension = strtolower($upload->getClientOriginalExtension());
        $path = $upload->store('store/previews/'.$product->id, 'private');
        try {
            $inspection = $security->inspect($path, $upload->getMimeType(), 20 * 1024 * 1024, [
                'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf', 'application/zip',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]);
            abort_unless($inspection['safe'] ?? false, 503, 'اسکن ضدبدافزار آماده نیست؛ پیش‌نمایش ذخیره نشد.');

            $kind = match ($extension) {
                'pdf' => 'pdf',
                'docx' => 'docx',
                'xlsx' => 'xlsx',
                default => 'image',
            };
            DB::transaction(function () use ($product, $path, $inspection, $request, $kind) {
                $lockedProduct = StoreProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
                $sort = (int) ($lockedProduct->previews()->max('sort_order') ?? 0) + 1;
                StoreProductPreview::create([
                    'product_id' => $lockedProduct->id,
                    'disk' => 'private',
                    'path' => $path,
                    'mime' => $inspection['mime'],
                    'page_number' => $request->input('page_number') ?: $sort,
                    'kind' => $kind,
                    'watermarked' => true,
                    'is_active' => true,
                    'sort_order' => $sort,
                ]);
                $this->audit('store.product.preview_uploaded', $lockedProduct, [
                    'mime' => $inspection['mime'],
                    'kind' => $kind,
                    'sort_order' => $sort,
                ]);
            });
        } catch (Throwable $exception) {
            Storage::disk('private')->delete($path);
            if ($exception instanceof ValidationException) {
                throw $exception;
            }
            abort(503, 'بارگذاری پیش‌نمایش به دلیل ناموفق بودن بررسی امنیتی متوقف شد.');
        }

        return back()->with('status', 'پیش‌نمایش پس از اسکن امنیتی اضافه شد.');
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
                $lockedProduct->update(['cover_path' => $publicPath]);
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
