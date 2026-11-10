<?php

namespace App\Http\Controllers;

use App\Services\MarketingSignalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MarketingPreferenceController extends Controller
{
    public function edit(Request $request)
    {
        return view('account.marketing-preferences', [
            'marketingConsent' => (bool) $request->user()->marketing_consent,
            'consentUpdatedAt' => $request->user()->marketing_consent_updated_at,
        ]);
    }

    public function update(Request $request, MarketingSignalService $signals)
    {
        $data = $request->validate(['marketing_consent' => ['required', 'boolean']]);
        $user = $request->user();
        $enabled = (bool) $data['marketing_consent'];
        $changed = (bool) $user->marketing_consent !== $enabled;

        DB::transaction(function () use ($user, $enabled, $changed, $signals): void {
            $user->forceFill([
                'marketing_consent' => $enabled,
                'marketing_consent_updated_at' => $changed ? now() : $user->marketing_consent_updated_at,
            ])->save();

            if (! $enabled) {
                $signals->withdraw($user);
            } elseif ($changed && Schema::hasTable('store_cart_items') && Schema::hasTable('store_carts')) {
                DB::table('store_cart_items')
                    ->whereIn('cart_id', DB::table('store_carts')->select('id')->where('user_id', $user->id))
                    ->update(['marketing_abandoned_at' => null]);
            }
        });

        return redirect()->route('account.marketing-preferences.edit')
            ->with('status', $enabled
                ? 'رضایت شما برای پیشنهادهای بازاریابی ثبت شد.'
                : 'پیشنهادهای بازاریابی غیرفعال شد؛ سیگنال‌های قبلی حذف و کدهای اختصاصی استفاده‌نشده باطل شدند.');
    }
}
