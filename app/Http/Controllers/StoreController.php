<?php

namespace App\Http\Controllers;

use App\Models\StoreCategory;
use App\Models\StoreProduct;
use App\Models\StoreProductPreview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StoreController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $category = $request->query('category');
        $products = StoreProduct::query()
            ->with(['category', 'images'])
            ->published()
            ->when($q !== '', fn ($query) => $query->where(function ($inner) use ($q) {
                $inner->where('title', 'like', '%'.$q.'%')
                    ->orWhere('short_description', 'like', '%'.$q.'%')
                    ->orWhere('description', 'like', '%'.$q.'%');
            }))
            ->when($category, fn ($query) => $query->whereHas('category', fn ($cat) => $cat->where('slug', $category)))
            ->orderByDesc('featured')
            ->orderBy('sort_order')
            ->latest('published_at')
            ->paginate(24)
            ->withQueryString();

        $categories = StoreCategory::query()->where('is_active', true)->orderBy('sort_order')->get();
        $featured = StoreProduct::query()->with('category')->published()->where('featured', true)->orderBy('sort_order')->limit(8)->get();

        return view('store.index', compact('products', 'categories', 'q', 'category', 'featured'));
    }

    public function product(Request $request, string $slug)
    {
        $product = StoreProduct::with(['category', 'images', 'previews', 'tags', 'related'])
            ->published()
            ->where('slug', $slug)
            ->firstOrFail();

        $ids = collect($request->session()->get('store_recent_ids', []))
            ->reject(fn ($id) => (int) $id === (int) $product->id)
            ->prepend($product->id)
            ->unique()
            ->take(12)
            ->values()
            ->all();
        $request->session()->put('store_recent_ids', $ids);

        return view('store.product', compact('product'));
    }

    public function category(string $slug)
    {
        $cat = StoreCategory::where('slug', $slug)->where('is_active', true)->firstOrFail();

        return redirect()->route('store', ['category' => $cat->slug]);
    }

    public function preview(StoreProductPreview $preview)
    {
        $preview->load('product');
        abort_unless($preview->is_active && $preview->product && $preview->product->status === 'published', 404);
        $disk = Storage::disk($preview->disk ?: 'private');
        abort_unless($disk->exists($preview->path), 404);

        return $disk->response($preview->path, null, [
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
        ]);
    }

    public function reader(string $slug)
    {
        $product = StoreProduct::with(['previews' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')])
            ->published()
            ->where('slug', $slug)
            ->firstOrFail();

        $previews = $product->previews;
        abort_if($previews->isEmpty(), 404, 'پیش‌نمایش برای این محصول موجود نیست.');

        return view('store.reader', compact('product', 'previews'));
    }
}
