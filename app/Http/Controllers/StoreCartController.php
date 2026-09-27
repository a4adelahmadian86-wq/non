<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\SiteSetting;
use App\Models\StoreOrderItem;
use App\Models\StoreProduct;
use App\Services\StoreCartService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StoreCartController extends Controller
{
    public function index(Request $request, StoreCartService $cart)
    {
        $current = $cart->current($request);
        return view('store.cart', ['cart'=>$cart->payload($current)]);
    }

    public function add(Request $request, StoreCartService $cart, StoreProduct $product)
    {
        $data = $request->validate(['quantity'=>'nullable|integer|min:1|max:99']);
        $current = $cart->add($request, $product, (int)($data['quantity'] ?? 1));
        return response()->json(['ok'=>true]+$cart->payload($current));
    }

    public function update(Request $request, StoreCartService $cart, StoreProduct $product)
    {
        $data = $request->validate(['quantity'=>'required|integer|min:0|max:99']);
        $current = $cart->update($request, $product, (int)$data['quantity']);
        return response()->json(['ok'=>true]+$cart->payload($current));
    }

    public function remove(Request $request, StoreCartService $cart, StoreProduct $product)
    {
        $current = $cart->remove($request, $product);
        return response()->json(['ok'=>true]+$cart->payload($current));
    }

    public function checkout(Request $request, StoreCartService $cart)
    {
        $request->validate(['accept_terms'=>['accepted']]);
        abort_unless($request->user(), 401);
        $current = $cart->current($request)->load('items.product');
        $payload = $cart->payload($current);
        abort_if(empty($payload['items']), 422, 'سبد خرید خالی است.');

        $order = DB::transaction(function () use ($request, $current, $payload) {
            $taxRate = max(0, (float)SiteSetting::read('tax_rate_percent', 10));
            $taxEnabled = filter_var(SiteSetting::read('tax_enabled', true), FILTER_VALIDATE_BOOLEAN);
            $tax = $taxEnabled ? (int)round($payload['subtotal_rials'] * $taxRate / 100) : 0;
            $total = $payload['subtotal_rials'] + $tax;
            $order = Order::create([
                'user_id'=>$request->user()->id,'document_id'=>null,'subtotal_rials'=>$payload['subtotal_rials'],
                'discount_rials'=>0,'tax_rials'=>$tax,'total_rials'=>$total,'status'=>'pending',
                'pricing_snapshot'=>['kind'=>'store','tax_rate'=>$taxRate,'cart_items'=>$payload['items']],
                'free_pages_applied'=>0,'terms_accepted_at'=>now(),
            ]);
            foreach ($current->items as $item) {
                $product=$item->product;
                if (!$product || $product->status!=='published' || !$product->files()->where('is_active',true)->exists()) throw new \RuntimeException('یکی از محصولات دیگر قابل خرید نیست.');
                $unit=(int)$product->price_rials;$line=$unit*(int)$item->quantity;
                StoreOrderItem::create(['order_id'=>$order->id,'product_id'=>$product->id,'title_snapshot'=>$product->title,'sku_snapshot'=>$product->sku,'quantity'=>$item->quantity,'unit_price_rials'=>$unit,'discount_rials'=>0,'tax_rials'=>0,'total_rials'=>$line,'product_snapshot'=>['title'=>$product->title,'sku'=>$product->sku,'version'=>$product->version]]);
            }
            $current->delete();
            return $order;
        });

        return redirect()->route('checkout',$order);
    }
}
