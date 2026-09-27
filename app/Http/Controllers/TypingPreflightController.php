<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\CapabilityService;
use App\Services\DeclineMessageService;
use App\Services\FreeQuotaService;
use App\Services\PricingService;
use App\Services\UploadPreflightService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TypingPreflightController extends Controller
{
    public function estimate(
        Request $request,
        UploadPreflightService $preflight,
        PricingService $pricing,
        FreeQuotaService $free,
        CapabilityService $capabilities,
    ) {
        $user = $request->user();
        abort_unless($user, 401);

        $caps = $capabilities->forUser($user);
        abort_unless($caps['active'] && $caps['can_type'], 403, 'خدمات تایپ برای این حساب فعال نیست.');

        [$path, $mime, $name] = $this->resolveSource($request);
        $pages = $preflight->estimatePages($path, $mime);
        $quote = $pricing->estimateByPages($pages);
        $freePages = $caps['unlimited'] ? 0 : $free->availablePages($user, (int) ($caps['weekly_free_pages'] ?? 1));
        $freeApplied = $caps['unlimited'] ? 0 : min(1, $freePages, $pages);
        $subtotal = $caps['unlimited'] ? 0 : (int) $quote['price_rials'];
        $discount = $caps['unlimited'] ? $subtotal : min($subtotal, $freeApplied * (int) $quote['free_page_value_rials']);
        $payable = max(0, $subtotal - $discount);
        $depositPercent = $caps['unlimited'] ? 0 : $preflight->depositPercent($pages);
        $deposit = $depositPercent > 0 ? (int) ceil(($payable * $depositPercent / 100) / 1000) * 1000 : 0;
        $hash = $preflight->fileHash($path);

        $accepted = $request->session()->get('typing_preflight_accepted');
        $alreadyAccepted = is_array($accepted)
            && hash_equals((string) ($accepted['path'] ?? ''), $path)
            && hash_equals((string) ($accepted['hash'] ?? ''), $hash);

        $mode = 'confirm';
        if ($alreadyAccepted) $mode = 'accepted';
        elseif ($caps['unlimited'] || ($pages <= 1 && $freeApplied > 0 && $payable === 0)) $mode = 'free';
        elseif ($depositPercent > 0) $mode = 'deposit';

        $payload = [
            'path' => $path,
            'mime' => $mime,
            'name' => $name,
            'hash' => $hash,
            'pages' => $pages,
            'estimate_rials' => $subtotal,
            'discount_rials' => $discount,
            'payable_estimate_rials' => $payable,
            'free_pages_available' => $freePages,
            'free_page_applied' => $freeApplied,
            'deposit_percent' => $depositPercent,
            'deposit_rials' => $deposit,
            'mode' => $mode,
            'estimated' => true,
            'expires_at' => now()->addMinutes(30)->toIso8601String(),
        ];

        $request->session()->put('typing_preflight_quote', $payload);

        return response()->json(['ok' => true] + $payload);
    }

    public function accept(
        Request $request,
        UploadPreflightService $preflight,
        PricingService $pricing,
        FreeQuotaService $free,
        CapabilityService $capabilities,
    ) {
        $request->validate(['accept' => ['accepted']]);
        $quoted = $request->session()->get('typing_preflight_quote');
        abort_unless(is_array($quoted), 422, 'برآورد اولیه پیدا نشد. فایل را دوباره بررسی کنید.');

        $user = $request->user();
        $caps = $capabilities->forUser($user);
        abort_unless($caps['active'] && $caps['can_type'], 403);

        $path = (string) ($quoted['path'] ?? '');
        $mime = (string) ($quoted['mime'] ?? '');
        abort_unless(Str::startsWith($path, 'typing/'.$user->id.'/') && Storage::disk('private')->exists($path), 404);
        abort_unless(hash_equals((string) ($quoted['hash'] ?? ''), $preflight->fileHash($path)), 409, 'فایل پس از برآورد تغییر کرده است.');

        $pages = $preflight->estimatePages($path, $mime);
        $quote = $pricing->estimateByPages($pages);
        $freePages = $caps['unlimited'] ? 0 : $free->availablePages($user, (int) ($caps['weekly_free_pages'] ?? 1));
        $freeApplied = $caps['unlimited'] ? 0 : min(1, $freePages, $pages);
        $subtotal = $caps['unlimited'] ? 0 : (int) $quote['price_rials'];
        $discount = $caps['unlimited'] ? $subtotal : min($subtotal, $freeApplied * (int) $quote['free_page_value_rials']);
        $payable = max(0, $subtotal - $discount);
        $depositPercent = $caps['unlimited'] ? 0 : $preflight->depositPercent($pages);
        $deposit = $depositPercent > 0 ? (int) ceil(($payable * $depositPercent / 100) / 1000) * 1000 : 0;
        $hash = $preflight->fileHash($path);

        if ($depositPercent > 0 && $deposit > 0) {
            $existing = Order::where('user_id', $user->id)
                ->whereNull('document_id')
                ->whereIn('status', ['deposit_pending', 'deposit_paid'])
                ->latest()
                ->get()
                ->first(fn (Order $order) => ($order->pricing_snapshot['source_hash'] ?? null) === $hash);

            if ($existing && $existing->status === 'deposit_paid') {
                $request->session()->put('typing_preflight_deposit_order', $existing->id);
                $request->session()->put('typing_preflight_accepted', [
                    'path' => $path,
                    'hash' => $hash,
                    'pages' => $pages,
                    'accepted_at' => now()->toIso8601String(),
                    'deposit_order_id' => $existing->id,
                ]);

                return response()->json(['ok' => true, 'action' => 'editor', 'editor_url' => route('editor')]);
            }

            $order = $existing ?: Order::create([
                'user_id' => $user->id,
                'document_id' => null,
                'subtotal_rials' => $deposit,
                'discount_rials' => 0,
                'tax_rials' => 0,
                'total_rials' => $deposit,
                'status' => 'deposit_pending',
                'pricing_snapshot' => [
                    'kind' => 'typing_deposit',
                    'source_path' => $path,
                    'source_hash' => $hash,
                    'source_name' => $quoted['name'] ?? null,
                    'source_mime' => $mime,
                    'estimated_pages' => $pages,
                    'estimated_subtotal_rials' => $subtotal,
                    'estimated_discount_rials' => $discount,
                    'estimated_payable_rials' => $payable,
                    'free_page_preview' => $freeApplied,
                    'deposit_percent' => $depositPercent,
                    'deposit_credit_rials' => $deposit,
                    'deposit_tax_rials' => 0,
                    'deposit_gateway_fee_rials' => 0,
                ],
                'free_pages_applied' => 0,
                'terms_accepted_at' => now(),
            ]);

            $request->session()->put('typing_preflight_deposit_order', $order->id);

            return response()->json([
                'ok' => true,
                'action' => 'deposit',
                'order_id' => $order->id,
                'checkout_url' => route('checkout', $order),
            ]);
        }

        $request->session()->put('typing_preflight_accepted', [
            'path' => $path,
            'hash' => $hash,
            'pages' => $pages,
            'accepted_at' => now()->toIso8601String(),
        ]);

        return response()->json(['ok' => true, 'action' => 'editor', 'editor_url' => route('editor')]);
    }

    public function decline(Request $request, DeclineMessageService $messages)
    {
        $request->session()->forget(['typing_preflight_quote', 'typing_preflight_accepted']);

        return response()->json(['ok' => true, 'message' => $messages->next($request->session())]);
    }

    private function resolveSource(Request $request): array
    {
        $pending = $request->session()->get('pending_upload');
        abort_unless(is_array($pending), 422, 'فایل بارگذاری‌شده پیدا نشد.');

        $path = (string) ($pending['path'] ?? '');
        $mime = (string) ($pending['mime'] ?? '');
        $name = (string) ($pending['name'] ?? 'فایل');
        abort_unless($path !== '' && Storage::disk('private')->exists($path), 404);

        if (Str::startsWith($path, 'typing/pending/')) {
            $newPath = 'typing/'.$request->user()->id.'/'.basename($path);
            if ($path !== $newPath) {
                Storage::disk('private')->move($path, $newPath);
                $path = $newPath;
            }
            $pending['path'] = $path;
            $request->session()->put('pending_upload', $pending);
        }

        abort_unless(Str::startsWith($path, 'typing/'.$request->user()->id.'/'), 403);

        return [$path, $mime, $name];
    }
}
