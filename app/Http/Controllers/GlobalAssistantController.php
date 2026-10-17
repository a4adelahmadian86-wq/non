<?php

namespace App\Http\Controllers;

use App\Services\EditorAiAssistService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GlobalAssistantController extends Controller
{
    public function chat(Request $request, EditorAiAssistService $ai)
    {
        $data = $request->validate([
            'message' => ['required','string','max:1200'],
            'context' => ['nullable','array','max:10'],
            'context.*.role' => ['required','string','in:user,assistant'],
            'context.*.text' => ['required','string','max:1200'],
        ]);
        $user = $request->user();
        abort_unless($user, 401, 'برای استفاده از همراه فراست وارد حساب شوید.');
        $history = collect($data['context'] ?? [])->map(fn ($item) =>
            ($item['role'] === 'user' ? 'کاربر' : 'همراه') . ': ' . $item['text']
        )->implode("\n");
        $prompt = "تو همراه هوشمند وب‌سایت فارسی فراست هستی. به فارسی روان، گرم، حرفه‌ای و دقیق پاسخ بده. پاسخ را عملی و ترجیحاً کوتاه بنویس. درباره قابلیت‌هایی که مطمئن نیستی ادعا نکن. مسیرهای شناخته‌شده: فروشگاه /store، سبد /cart، تایپ و ویرایشگر /editor، قیمت‌ها /pricing، پشتیبانی /support، فضای کاربر /dashboard. سابقه اخیر گفتگو:\n"
            . $history . "\nکاربر: " . $data['message'];
        try {
            $result = $ai->assist('text.generate', $prompt, [
                'user' => $user,
                'processing_mode' => 'external',
                'instruction' => 'پاسخ مکالمه‌ای فارسی برای دستیار عمومی سایت؛ بدون ادعای قابلیت ناموجود.',
                'idempotency_key' => (string) ($request->header('Idempotency-Key') ?: ('global-assistant-'.$user->id.'-'.hash('sha256', $prompt.'|'.now()->timestamp))),
            ]);
            $reply = data_get($result, 'result.text')
                ?? data_get($result, 'text')
                ?? data_get($result, 'result')
                ?? data_get($result, 'output');
            if (is_array($reply)) $reply = $reply['text'] ?? $reply['content'] ?? json_encode($reply, JSON_UNESCAPED_UNICODE);
            if (! is_string($reply) || trim($reply) === '') throw new RuntimeException('ai_provider_invalid_shape');
            return response()->json(['ok' => true, 'reply' => trim($reply)], 200, ['Cache-Control' => 'no-store']);
        } catch (\Throwable $e) {
            $code = preg_match('/^ai_[a-z0-9_]+$/', $e->getMessage()) ? $e->getMessage() : 'assistant_unavailable';
            Log::warning('farast.global_assistant_failed', ['user_id' => $user->id, 'error_code' => $code]);
            return response()->json(['ok' => false, 'message' => 'سرویس گفت‌وگو فعلاً در دسترس نیست. تنظیمات هوش مصنوعی و دسترسی حساب را بررسی کنید.'], 503, ['Cache-Control' => 'no-store']);
        }
    }
}
