<?php

namespace App\Services;

use App\Models\StoreCart;
use App\Models\StoreCartItem;
use App\Models\StoreProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StoreCartService
{
    public function current(Request $request): StoreCart
    {
        $token = $this->sessionToken($request);
        if ($request->user()) {
            $cart = StoreCart::firstOrCreate(['user_id' => $request->user()->id], ['expires_at' => now()->addDays(14)]);
            $guest = StoreCart::whereNull('user_id')->where('session_token', $token)->first();
            if ($guest && $guest->id !== $cart->id) {
                DB::transaction(function () use ($guest, $cart) {
                    foreach ($guest->items as $item) {
                        $existing = $cart->items()->where('product_id', $item->product_id)->first();
                        if ($existing) $existing->update(['quantity' => min(99, $existing->quantity + $item->quantity)]);
                        else $cart->items()->create(['product_id'=>$item->product_id,'quantity'=>$item->quantity,'price_snapshot_rials'=>$item->price_snapshot_rials]);
                    }
                    $guest->delete();
                });
            }
            return $cart->load('items.product');
        }

        return StoreCart::firstOrCreate(['session_token' => $token], ['expires_at' => now()->addDays(7)])->load('items.product');
    }

    public function add(Request $request, StoreProduct $product, int $quantity = 1): StoreCart
    {
        abort_unless($product->status === 'published' && $product->published_at?->lte(now()), 404);
        $cart = $this->current($request);
        $item = $cart->items()->firstOrNew(['product_id'=>$product->id]);
        $item->quantity = min(99, max(1, (int)$item->quantity + $quantity));
        $item->price_snapshot_rials = (int)$product->price_rials;
        $item->save();
        return $cart->fresh('items.product');
    }

    public function update(Request $request, StoreProduct $product, int $quantity): StoreCart
    {
        $cart = $this->current($request);
        $item = $cart->items()->where('product_id',$product->id)->firstOrFail();
        if ($quantity <= 0) $item->delete();
        else $item->update(['quantity'=>min(99,$quantity),'price_snapshot_rials'=>(int)$product->price_rials]);
        return $cart->fresh('items.product');
    }

    public function remove(Request $request, StoreProduct $product): StoreCart
    {
        $cart = $this->current($request);
        $cart->items()->where('product_id',$product->id)->delete();
        return $cart->fresh('items.product');
    }

    public function payload(StoreCart $cart): array
    {
        $subtotal = 0;
        $items = [];
        foreach ($cart->items as $item) {
            if (!$item->product || $item->product->status !== 'published') continue;
            $unit = (int)$item->product->price_rials;
            $line = $unit * (int)$item->quantity;
            $subtotal += $line;
            $items[] = ['id'=>$item->id,'product_id'=>$item->product_id,'title'=>$item->product->title,'quantity'=>(int)$item->quantity,'unit_price_rials'=>$unit,'line_total_rials'=>$line];
        }
        return ['items'=>$items,'subtotal_rials'=>$subtotal,'discount_rials'=>0,'tax_rials'=>0,'total_rials'=>$subtotal];
    }

    private function sessionToken(Request $request): string
    {
        $token = $request->session()->get('store_cart_token');
        if (!$token) { $token = Str::random(64); $request->session()->put('store_cart_token',$token); }
        return $token;
    }
}
