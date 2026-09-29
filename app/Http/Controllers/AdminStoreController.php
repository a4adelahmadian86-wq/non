<?php

namespace App\Http\Controllers;

use App\Models\StoreCategory;
use App\Models\StoreProduct;
use App\Models\StoreProductFile;
use App\Models\StoreProductPreview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminStoreController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $products = StoreProduct::query()
            ->with(['category', 'files'])
            ->when($q !== '', fn ($query) => $query->where('title', 'like', '%'.$q.'%')->orWhere('sku', 'like', '%'.$q.'%'))
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('admin.store.index', compact('products', 'q'));
    }

    public function create()
    {
        $categories = StoreCategory::orderBy('sort_order')->get();

        return view('admin.store.form', ['product' => new StoreProduct(['status' => 'draft', 'price_rials' => 0, 'preview_pages' => 3]), 'categories' => $categories]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['slug'] = $this->uniqueSlug($data['title'], $data['slug'] ?? null);
        if (($data['status'] ?? '') === 'published' && empty($data['published_at'])) {
            $data['published_at'] = now();
        }
        $product = StoreProduct::create($data);

        return redirect()->route('admin.store.edit', $product)->with('status', 'محصول ایجاد شد.');
    }

    public function edit(StoreProduct $product)
    {
        $product->load(['files', 'previews', 'category']);
        $categories = StoreCategory::orderBy('sort_order')->get();

        return view('admin.store.form', compact('product', 'categories'));
    }

    public function update(Request $request, StoreProduct $product)
    {
        $data = $this->validated($request, $product);
        if (! empty($data['slug'])) {
            $data['slug'] = $this->uniqueSlug($data['title'], $data['slug'], $product->id);
        }
        if (($data['status'] ?? '') === 'published' && ! $product->published_at && empty($data['published_at'])) {
            $data['published_at'] = now();
        }
        $product->update($data);

        return back()->with('status', 'محصول به‌روزرسانی شد.');
    }

    public function destroy(StoreProduct $product)
    {
        $product->delete();

        return redirect()->route('admin.store.index')->with('status', 'محصول حذف شد.');
    }

    public function uploadFile(Request $request, StoreProduct $product)
    {
        $request->validate([
            'file' => 'required|file|max:51200',
            'is_primary' => 'nullable|boolean',
        ]);

        $upload = $request->file('file');
        $path = $upload->store('store/products/'.$product->id, 'private');
        $primary = $request->boolean('is_primary', true);
        if ($primary) {
            $product->files()->update(['is_primary' => false]);
        }

        StoreProductFile::create([
            'product_id' => $product->id,
            'version' => $product->version,
            'disk' => 'private',
            'path' => $path,
            'original_name' => $upload->getClientOriginalName(),
            'mime' => $upload->getClientMimeType(),
            'size_bytes' => $upload->getSize() ?: 0,
            'sha256' => hash_file('sha256', $upload->getRealPath()),
            'is_primary' => $primary,
            'is_active' => true,
        ]);

        return back()->with('status', 'فایل محصول ذخیره شد.');
    }

    public function deleteFile(StoreProduct $product, StoreProductFile $file)
    {
        abort_unless($file->product_id === $product->id, 404);
        Storage::disk($file->disk ?: 'private')->delete($file->path);
        $file->delete();

        return back()->with('status', 'فایل حذف شد.');
    }

    public function uploadPreview(Request $request, StoreProduct $product)
    {
        $request->validate([
            'preview' => 'required|file|mimes:jpg,jpeg,png,webp,gif,pdf|max:20480',
            'page_number' => 'nullable|integer|min:1|max:500',
        ]);

        $upload = $request->file('preview');
        $path = $upload->store('store/previews/'.$product->id, 'private');
        $sort = (int) ($product->previews()->max('sort_order') ?? 0) + 1;

        StoreProductPreview::create([
            'product_id' => $product->id,
            'disk' => 'private',
            'path' => $path,
            'mime' => $upload->getClientMimeType(),
            'page_number' => $request->input('page_number') ?: $sort,
            'kind' => str_contains((string) $upload->getClientMimeType(), 'pdf') ? 'pdf' : 'image',
            'watermarked' => true,
            'is_active' => true,
            'sort_order' => $sort,
        ]);

        return back()->with('status', 'پیش‌نمایش اضافه شد.');
    }

    public function deletePreview(StoreProduct $product, StoreProductPreview $preview)
    {
        abort_unless($preview->product_id === $product->id, 404);
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

    private function validated(Request $request, ?StoreProduct $product = null): array
    {
        $data = $request->validate([
            'category_id' => 'required|exists:store_categories,id',
            'title' => 'required|string|max:220',
            'slug' => 'nullable|string|max:240',
            'sku' => 'required|string|max:80|unique:store_products,sku,'.($product?->id ?? 'NULL'),
            'type' => 'nullable|string|max:40',
            'short_description' => 'nullable|string|max:2000',
            'description' => 'nullable|string|max:20000',
            'price_rials' => 'required|integer|min:0|max:999999999999',
            'compare_at_price_rials' => 'nullable|integer|min:0',
            'status' => 'required|in:draft,published,archived',
            'featured' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:999999',
            'preview_pages' => 'nullable|integer|min:0|max:50',
            'license_type' => 'nullable|string|max:60',
            'version' => 'nullable|string|max:60',
            'cover_path' => 'nullable|string|max:500',
        ]);
        $data['featured'] = $request->boolean('featured');
        $data['type'] = $data['type'] ?? 'digital_file';
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
