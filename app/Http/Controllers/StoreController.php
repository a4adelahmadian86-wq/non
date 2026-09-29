<?php

namespace App\Http\Controllers;

use App\Models\StoreCategory;
use App\Models\StoreProduct;
use App\Models\StoreProductPreview;
use App\Models\StoreWishlist;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StoreController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $category = $request->query('category');
        $sort = (string) $request->query('sort', 'newest');
        $price = (string) $request->query('price', 'all');

        $base = StoreProduct::query()->with(['category', 'images'])->published();

        $productsQuery = (clone $base)
            ->when($q !== '', fn ($query) => $query->where(function ($inner) use ($q) {
                $inner->where('title', 'like', '%'.$q.'%')
                    ->orWhere('short_description', 'like', '%'.$q.'%')
                    ->orWhere('description', 'like', '%'.$q.'%');
            }))
            ->when($category, fn ($query) => $query->whereHas('category', fn ($cat) => $cat->where('slug', $category)))
            ->when($price === 'free', fn ($query) => $query->where('price_rials', 0))
            ->when($price === 'paid', fn ($query) => $query->where('price_rials', '>', 0));

        $productsQuery = match ($sort) {
            'price_asc' => $productsQuery->orderBy('price_rials')->orderBy('title'),
            'price_desc' => $productsQuery->orderByDesc('price_rials')->orderBy('title'),
            'title' => $productsQuery->orderBy('title'),
            'featured' => $productsQuery->orderByDesc('featured')->orderBy('sort_order'),
            default => $productsQuery->orderByDesc('featured')->orderBy('sort_order')->latest('published_at'),
        };

        $products = $productsQuery->paginate(24)->withQueryString();

        $categories = StoreCategory::query()->where('is_active', true)->orderBy('sort_order')->get();
        $featured = (clone $base)->where('featured', true)->orderBy('sort_order')->limit(12)->get();
        $free = (clone $base)->where('price_rials', 0)->orderBy('sort_order')->limit(12)->get();
        $latest = (clone $base)->latest('published_at')->limit(12)->get();

        $recentIds = collect($request->session()->get('store_recent_ids', []))->take(12)->all();
        $recent = $recentIds
            ? StoreProduct::with('category')->published()->whereIn('id', $recentIds)->get()->sortBy(fn ($p) => array_search($p->id, $recentIds))->values()
            : collect();

        $isBrowsing = $q !== '' || $category || $price !== 'all' || $sort !== 'newest';

        return view('store.index', compact(
            'products', 'categories', 'q', 'category', 'featured', 'free', 'latest', 'recent', 'isBrowsing', 'sort', 'price'
        ));
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

        $related = $product->related->isNotEmpty()
            ? $product->related->take(8)
            : StoreProduct::with('category')
                ->published()
                ->where('id', '!=', $product->id)
                ->when($product->category_id, fn ($q) => $q->where('category_id', $product->category_id))
                ->orderByDesc('featured')
                ->limit(8)
                ->get();

        $alsoViewed = StoreProduct::with('category')
            ->published()
            ->where('id', '!=', $product->id)
            ->whereIn('id', collect($ids)->reject(fn ($id) => (int) $id === (int) $product->id)->take(8))
            ->get();

        $inWishlist = false;
        $inLater = false;
        if ($request->user()) {
            $lists = StoreWishlist::where('user_id', $request->user()->id)
                ->where('product_id', $product->id)
                ->pluck('list_type');
            $inWishlist = $lists->contains('wishlist');
            $inLater = $lists->contains('later');
        }

        $hasPreview = $product->previews->where('is_active', true)->isNotEmpty();

        return view('store.product', compact('product', 'related', 'alsoViewed', 'inWishlist', 'inLater', 'hasPreview'));
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
