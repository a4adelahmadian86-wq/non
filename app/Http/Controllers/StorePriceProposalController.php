<?php

namespace App\Http\Controllers;

use App\Models\StorePriceProposal;
use App\Models\StoreProduct;
use App\Models\User;
use App\Services\StorePriceProposalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
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
        $uploaderNames = User::query()
            ->whereIn('id', $previews->pluck('uploader_id')->filter()->unique()->values())
            ->pluck('name', 'id');

        $previews = $previews->map(function (array $preview) use ($uploaderNames) {
            $preview['uploader_name'] = $preview['uploader_id']
                ? ($uploaderNames[$preview['uploader_id']] ?? null)
                : null;

            return $preview;
        });

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
                'store_product_has_no_safe_base_price' => 'با بهای تمام‌شده و حاشیه سود فعلی، قیمت امنی در محدوده مجاز قابل پیشنهاد نیست.',
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
        try {
            $accepted = $service->decide($proposal, $request->user(), 'accept');
        } catch (RuntimeException $exception) {
            return back()->withErrors(['pricing' => 'این پیشنهاد قبلاً تعیین تکلیف شده یا دیگر قابل پذیرش نیست.']);
        }

        if (! $accepted) {
            return back()->withErrors(['pricing' => 'قیمت یا حاشیه سود محصول پس از ارسال پیشنهاد تغییر کرده است؛ پیشنهاد قبلی منقضی شد و باید پیشنهاد تازه‌ای ثبت شود.']);
        }

        $previousPrice = (int) data_get($proposal->snapshot_before, 'price_rials', $proposal->base_price_rials);
        $message = $proposal->campaign_price_rials !== null
            ? 'پیشنهاد پذیرفته شد و قیمت کمپین اعمال شد.'
            : ((int) $proposal->base_price_rials > $previousPrice
                ? 'پیشنهاد پذیرفته شد و قیمت پایه امن اعمال شد.'
                : 'قیمت پایه فعلی تأیید شد؛ مبلغ محصول تغییر نکرد.');

        return back()->with('status', $message);
    }

    public function reject(Request $request, StorePriceProposal $proposal, StorePriceProposalService $service): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        try {
            $accepted = $service->decide($proposal, $request->user(), 'reject', $data['note'] ?? null);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['pricing' => 'این پیشنهاد قبلاً تعیین تکلیف شده یا دیگر قابل رد نیست.']);
        }

        return $accepted
            ? back()->with('status', 'پیشنهاد قیمت رد شد؛ قیمت محصول تغییر نکرد.')
            : back()->withErrors(['pricing' => 'پیشنهاد به دلیل تغییر اطلاعات قیمت‌گذاری منقضی شده است.']);
    }
}
