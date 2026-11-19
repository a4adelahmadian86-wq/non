<?php

namespace App\\Http\\Controllers;

use App\\Models\\StorePriceProposal;
use App\\Models\\StoreProduct;
use App\\Services\\StorePriceProposalService;
use Illuminate\\Http\\RedirectResponse;
use Illuminate\\Http\\Request;
use Illuminate\\View\\View;
use RuntimeException;

class StorePriceProposalController extends Controller
{
    public function adminIndex(StorePriceProposalService $service): View
    {
        $products = StoreProduct::query()
            ->with('category:id,name')
            ->where('price_rials', '>', 0)
            ->orderByDesc('updated_at')
            ->paginate(30);

        $previews = $products->getCollection()->mapWithKeys(
            fn (StoreProduct $product) => [$product->id => $service->preview($product)]
        );

        return view('admin.store.price-proposals', compact('products', 'previews'));
    }

    public function create(Request $request, StoreProduct $product, StorePriceProposalService $service): RedirectResponse
    {
        try {
            $proposal = $service->propose($product, $request->user());
        } catch (RuntimeException $exception) {
            $message = match ($exception->getMessage()) {
                'store_product_uploader_not_identified' => 'بارگذار این محصول مشخص نیست؛ ابتدا مالکیت بارگذاری را ثبت کنید.',
                'store_product_requires_positive_base_price' => 'برای این محصول ابتدا قیمت پایه معتبر ثبت کنید.',
                default => 'ایجاد پیشنهاد قیمت انجام نشد.',
            };

            return back()->withErrors(['pricing' => $message]);
        }

        return back()->with('status', 'پیشنهاد قیمت شماره '.$proposal->id.' برای بارگذار ارسال شد.');
    }

    public function index(Request $request, StorePriceProposalService $service): View
    {
        $proposals = $service->forUploader((int) $request->user()->id);

        return view('store.price-proposals.index', compact('proposals'));
    }

    public function accept(Request $request, StorePriceProposal $proposal, StorePriceProposalService $service): RedirectResponse
    {
        $accepted = $service->decide($proposal, $request->user(), 'accept');

        return $accepted
            ? back()->with('status', 'پیشنهاد قیمت پذیرفته و قیمت کمپین اعمال شد.')
            : back()->withErrors(['pricing' => 'قیمت یا حاشیه سود محصول پس از ارسال پیشنهاد تغییر کرده است؛ پیشنهاد قبلی منقضی شد و باید پیشنهاد تازه‌ای ثبت شود.']);
    }

    public function reject(Request $request, StorePriceProposal $proposal, StorePriceProposalService $service): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $accepted = $service->decide($proposal, $request->user(), 'reject', $data['note'] ?? null);

        return $accepted
            ? back()->with('status', 'پیشنهاد قیمت رد شد؛ قیمت محصول تغییر نکرد.')
            : back()->withErrors(['pricing' => 'پیشنهاد به دلیل تغییر اطلاعات قیمت‌گذاری منقضی شده است.']);
    }
}
