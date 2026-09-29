<?php

namespace App\Http\Controllers;

use App\Models\StoreProduct;
use App\Models\StoreWishlist;
use Illuminate\Http\Request;

class StoreWishlistController extends Controller
{
    public function index(Request $request)
    {
        $type = $request->query('type', 'wishlist');
        if (! in_array($type, ['wishlist', 'later'], true)) {
            $type = 'wishlist';
        }

        $items = StoreWishlist::query()
            ->with(['product.category'])
            ->where('user_id', $request->user()->id)
            ->where('list_type', $type)
            ->latest()
            ->paginate(24);

        return view('store.wishlist', compact('items', 'type'));
    }

    public function toggle(Request $request, StoreProduct $product)
    {
        abort_unless($product->status === 'published', 404);
        $data = $request->validate(['list_type' => 'nullable|in:wishlist,later']);
        $type = $data['list_type'] ?? 'wishlist';

        $existing = StoreWishlist::where([
            'user_id' => $request->user()->id,
            'product_id' => $product->id,
            'list_type' => $type,
        ])->first();

        if ($existing) {
            $existing->delete();
            $active = false;
        } else {
            StoreWishlist::create([
                'user_id' => $request->user()->id,
                'product_id' => $product->id,
                'list_type' => $type,
            ]);
            $active = true;
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'active' => $active, 'list_type' => $type]);
        }

        return back()->with('status', $active ? 'به لیست اضافه شد.' : 'از لیست حذف شد.');
    }

    public function destroy(Request $request, StoreProduct $product)
    {
        $type = $request->input('list_type', 'wishlist');
        StoreWishlist::where([
            'user_id' => $request->user()->id,
            'product_id' => $product->id,
            'list_type' => $type,
        ])->delete();

        return back()->with('status', 'حذف شد.');
    }
}
