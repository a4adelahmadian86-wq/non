<?php

namespace App\Http\Controllers;

use App\Models\StoreCategory;
use App\Models\StoreProduct;
use App\Models\StoreProductFile;
use App\Models\StoreProductPreview;
use App\Services\UploadSecurityService;
use App\Services\StoreProductWorkspaceService;
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
            ->with(['category', 'files'])
            ->withCount([
                'orderItems as paid_order_items_count' => fn ($query) => $query->whereHas('order', fn ($order) => $order->where('status', 'paid')->whereNotNull('paid_at')),
                'libraryItems as active_access_count' => fn ($query) => $query->whereNull('revoked_at'),
            ])
            ->withSum(['orderItems as paid_sales_rials' => fn ($query) => $query->whereHas('order', fn ($order) => $order->where('status', 'paid')->whereNotNull('paid_at'))], 'total_rials')
            ->when($q !== '', fn ($query) => $query->where(function ($inner) use ($q) {
                $inner->where('title', 'like', '%'.$q.'%')->orWhere('sku', 'like', '%'.$q.'%');
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
            'paid_orders' => DB::table('store_order_items')->join('orders', 'orders.id', '=', 'store_order_items.order_id')->whereNotNull('store_order_items.product_id')->where('orders.status', 'paid')->whereNotNull('orders.paid_at')->distinct('orders.id')->count('orders.id'),
            'revenue_rials' => (int) DB::table('store_order_items')->join('orders', 'orders.id', '=', 'store_order_items.order_id')->whereNotNull('store_order_items.product_id')->where('orders.status', 'paid')->whereNotNull('orders.paid_at')->sum('store_order_items.total_rials'),
            'active_accesses' => DB::table('store_library_items')->whereNull('revoked_at')->count(),
        ];

        return view('admin.store.index', compact('products', 'q', 'type', 'status', 'stats'));
    }

    public function create()
    {
        $categories = StoreCategory::orderBy('sort_order')->get();

        return view('admin.store.form', [
            'product' => new StoreProduct(['status' => 'draft', 'price_rials' => 0, 'preview_pages' => 3, 'type' => 'digital_file']),
            'categories' => $categories,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['slug'] = $this->uniqueSlug($data['title'], $data['slug'] ?? null);
        // A new catalog item must not become public before its protected payload/curriculum exists.
        $data['status'] = 'draft';
        $data['published_at'] = null;
        $product = StoreProduct::create($data);
        $this->audit('store.product.created', $product, ['type' => $product->type, 'sku' => $product->sku]);

        if ($product->type === 'course') {
            return redirect()->route('admin.store.course.edit', $product)->with('status', 'محصول دوره ایجاد شد؛ اکنون ساختار آموزشی آن را تکمیل کنید.');
        }

        return redirect()->route('admin.store.edit', $product)->with('status', 'محصول ایجاد شد؛ فایل اصلی را بارگذاری کنید.');
    }

    public function edit(StoreProduct $product)
    {
        $product->load(['files', 'previews', 'category']);
        $categories = StoreCategory::orderBy('sort_order')->get();
        $workflow = app(StoreProductWorkspaceService::class)->inspect($product);

        return view('admin.store.form', compact('product', 'categories', 'workflow'));
    }

    public function update(Request $request, StoreProduct $product)
    {
        $data = $this->validated($request, $product);
        if ($data['type'] !== $product->type) {
            throw ValidationException::withMessages([
                'type' => 'نوع محصول پس از ایجاد قابل تغییر نیست؛ برای نوع دیگر یک محصول جدید بسازید تا فایل‌ها و دسترسی‌های قبلی مخلوط نشوند.',
            ]);
        }
        if (! empty($data['slug'])) {
            $data['slug'] = $this->uniqueSlug($data['title'], $data['slug'], $product->id);
        }

        if (($data['status'] ?? '') === 'published') {
            $readiness = app(StoreProductWorkspaceService::class)->inspect($product);
            if (! $readiness['can_publish'] && $product->status !== 'published') {
                $message = $data['type'] === 'course'
                    ? 'برای انتشار دوره، اطلاعات پایه، قیمت و دست‌کم یک جلسه منتشرشده لازم است.'
                    : 'برای انتشار محصول، اطلاعات پایه، قیمت و یک فایل اصلی فعال با اسکن امنیتی موفق لازم است.';
                throw ValidationException::withMessages(['status' => $message]);
            }
            if (! $product->published_at && empty($data['published_at'])) {
                $data['published_at'] = now();
            }
        } elseif (($data['status'] ?? '') !== 'published') {
            $data['published_at'] = null;
        }

        $product->update($data);
        $this->audit('store.product.updated', $product, ['type' => $product->type, 'status' => $product->status, 'price_rials' => $product->price_rials]);

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

            $primary = $request->boolean('is_primary', true);
            $fileRecord = DB::transaction(function () use ($product, $path, $upload, $inspection, $primary) {
                if ($primary) {
                    $product->files()->where('purpose', 'product_download')->update(['is_primary' => false]);
                }

                $record = StoreProductFile::create([
                    'product_id' => $product->id,
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
                $this->audit('store.product.file_uploaded', $product, ['file_id' => $record->id, 'mime' => $record->mime, 'size_bytes' => $record->size_bytes, 'sha256' => $record->sha256]);

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
        $this->audit('store.product.file_deleted', $product, ['file_id' => $file->id, 'purpose' => $file->purpose]);
        Storage::disk($file->disk ?: 'private')->delete($file->path);
        $file->delete();

        return back()->with('status', 'فایل حذف شد.');
    }

    public function uploadPreview(Request $request, StoreProduct $product, UploadSecurityService $security)
    {
        $request->validate([
            'preview' => 'required|file|mimes:jpg,jpeg,png,webp,gif,pdf|max:20480',
            'page_number' => 'nullable|integer|min:1|max:500',
        ]);

        $upload = $request->file('preview');
        $path = $upload->store('store/previews/'.$product->id, 'private');
        try {
            $inspection = $security->inspect($path, $upload->getMimeType(), 20 * 1024 * 1024, [
                'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf',
            ]);
            abort_unless($inspection['safe'] ?? false, 503, 'اسکن ضدبدافزار آماده نیست؛ پیش‌نمایش ذخیره نشد.');

            $sort = (int) ($product->previews()->max('sort_order') ?? 0) + 1;
            DB::transaction(function () use ($product, $path, $inspection, $request, $sort) {
                StoreProductPreview::create([
                    'product_id' => $product->id,
                    'disk' => 'private',
                    'path' => $path,
                    'mime' => $inspection['mime'],
                    'page_number' => $request->input('page_number') ?: $sort,
                    'kind' => $inspection['mime'] === 'application/pdf' ? 'pdf' : 'image',
                    'watermarked' => true,
                    'is_active' => true,
                    'sort_order' => $sort,
                ]);
                $this->audit('store.product.preview_uploaded', $product, ['mime' => $inspection['mime'], 'sort_order' => $sort]);
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

            $oldPath = (string) $product->cover_path;
            $product->update(['cover_path' => $publicPath]);
            $this->audit('store.product.cover_updated', $product, ['mime' => $inspection['mime'], 'sha256' => $inspection['checksum']]);
            if ($oldPath !== '' && Str::startsWith($oldPath, 'storage/store/covers/'.$product->id.'/')) {
                Storage::disk('public')->delete(Str::after($oldPath, 'storage/'));
            }
        } catch (Throwable $exception) {
            Storage::disk('private')->delete($temporaryPath);
            if ($exception instanceof ValidationException) {
                throw $exception;
            }
            abort(503, 'بارگذاری کاور به دلیل ناموفق بودن بررسی امنیتی یا ذخیره‌سازی متوقف شد.');
        }

        Storage::disk('private')->delete($temporaryPath);

        return back()->with('status', 'کاور محصول پس از اسکن امنیتی ذخیره شد.');
    }

    public function deletePreview(StoreProduct $product, StoreProductPreview $preview)
    {
        abort_unless($preview->product_id === $product->id, 404);
        $this->audit('store.product.preview_deleted', $product, ['preview_id' => $preview->id]);
        Storage::disk($preview->disk ?: 'private')->delete($preview->path);
        $preview->delete();

        return back()->with('status', 'پیش‌نمایش حذف شد.');
    }

    public function storeCategory(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:160',
            'slug' => 'nullable|string|max:180',
        ]);
        $slug = Str::slug($data['slug'] ?: $data['name']) ?: 'cat-'.Str::random(6);
        StoreCategory::firstOrCreate(
            ['slug' => $slug],
            ['name' => $data['name'], 'is_active' => true, 'sort_order' => 0]
        );

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
            'compare_at_price_rials' => 'nullable|integer|min:0|gte:price_rials',
            'status' => 'required|in:draft,published,archived',
            'featured' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:999999',
            'preview_pages' => 'nullable|integer|min:0|max:50',
            'license_type' => 'nullable|string|max:60',
            'version' => 'nullable|string|max:60',
            'cover_path' => 'nullable|string|max:500',
            'seo_title' => 'nullable|string|max:220',
            'seo_description' => 'nullable|string|max:320',
        ]);
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
