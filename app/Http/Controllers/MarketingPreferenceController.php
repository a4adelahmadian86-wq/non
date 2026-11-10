<?php

namespace App\Http\Controllers;

use App\Services\MarketingSignalService;
use Illuminate\Http\Request;

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

        $user->forceFill([
            'marketing_consent' => $enabled,
            'marketing_consent_updated_at' => $changed ? now() : $user->marketing_consent_updated_at,
        ])->save();

        if (! $enabled) {
            $signals->withdraw($user);
        }

        return redirect()->route('account.marketing-preferences.edit')
            ->with('status', $enabled
                ? 'رضایت شما برای پیشنهادهای بازاریابی ثبت شد.'
                : 'پیشنهادهای بازاریابی غیرفعال شد و سیگنال‌های بازاریابی قبلی این حساب حذف شدند.');
    }
}
