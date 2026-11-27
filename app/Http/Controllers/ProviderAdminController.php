<?php

namespace App\Http\Controllers;

use App\Models\SiteSetting;
use App\Models\VoiceProviderAccount;
use App\Services\AuditLogService;
use App\Services\ProviderConnectionHealth;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProviderAdminController extends Controller
{
    public function updateAi(Request $request)
    {
        $data = $request->validate([
            'ai_gateway_url' => 'nullable|url|max:255',
            'ai_gateway_token' => 'nullable|string|max:500',
            'ai_gateway_fallback_url' => 'nullable|url|max:255',
            'ai_gateway_fallback_token' => 'nullable|string|max:500',
        ]);

        foreach (['ai_gateway_url', 'ai_gateway_fallback_url'] as $key) {
            $value = trim((string) ($data[$key] ?? ''));
            if ($value !== '') {
                $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
                $host = strtolower((string) parse_url($value, PHP_URL_HOST));
                if ($scheme !== 'https' || in_array($host, [
                    'generativelanguage.googleapis.com',
                    'api.groq.com',
                    'api.openai.com',
                    'api.cloudflare.com',
                ], true)) {
                    return back()->withErrors([$key => 'فقط نشانی HTTPS درگاه FARAST مجاز است؛ نشانی مستقیم ارائه‌دهنده پذیرفته نمی‌شود.']);
                }
                SiteSetting::write($key, rtrim($value, '/'));
            }
        }

        foreach (['ai_gateway_token', 'ai_gateway_fallback_token'] as $key) {
            if (filled($data[$key] ?? null)) {
                SiteSetting::write($key, trim($data[$key]), true);
            }
        }

        app(AuditLogService::class)->record(auth()->id(), 'provider.farast_ai_gateway.updated', $request->ip(), [
            'primary_url_updated' => filled($data['ai_gateway_url'] ?? null),
            'primary_token_replaced' => filled($data['ai_gateway_token'] ?? null),
            'fallback_url_updated' => filled($data['ai_gateway_fallback_url'] ?? null),
            'fallback_token_replaced' => filled($data['ai_gateway_fallback_token'] ?? null),
        ]);

        return back()->with('status', 'تنظیمات درگاه هوش مصنوعی FARAST ذخیره شد.');
    }

    public function testAi(ProviderConnectionHealth $health)
    {
        $url = rtrim(trim((string) (SiteSetting::read('ai_gateway_url') ?: config('services.ai_gateway.url', ''))), '/');
        $token = trim((string) (SiteSetting::read('ai_gateway_token') ?: config('services.ai_gateway.token', '')));
        $model = '@cf/openai/gpt-oss-20b';

        if ($url === '' || $token === '') {
            return response()->json([
                'ok' => false,
                'configured' => false,
                'api_reachable' => false,
                'connection_verified' => false,
                'ready' => false,
                'message' => 'نشانی و توکن درگاه FARAST تنظیم نشده‌اند.',
            ], 422);
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(12)
                ->get($url.'/ready');

            if (! $response->successful()) {
                $status = $response->status();
                $health->recordFailure('farast-ai-gateway', $token, $model, 'http_'.$status, true);

                return response()->json([
                    'ok' => false,
                    'configured' => true,
                    'api_reachable' => true,
                    'connection_verified' => false,
                    'ready' => false,
                    'http_status' => $status,
                    'message' => $status === 401
                        ? 'توکن درگاه پذیرفته نشد.'
                        : 'درگاه FARAST پاسخ آماده‌به‌کار نداد؛ وضعیت Worker و سهمیه را بررسی کنید.',
                ], $status === 429 ? 429 : 502);
            }

            $models = $response->json('models', []);
            $required = ['text', 'review', 'vision', 'audio'];
            $ready = $response->json('ok') === true
                && is_array($models)
                && collect($required)->every(fn ($task) => filled($models[$task] ?? null));

            $health->recordSuccess('farast-ai-gateway', $token, $model, array_fill_keys($required, $ready));

            return response()->json([
                'ok' => $ready,
                'configured' => true,
                'api_reachable' => true,
                'connection_verified' => true,
                'ready' => $ready,
                'models' => $models,
                'message' => $ready
                    ? 'درگاه FARAST در دسترس است و مسیرهای متن، بازبینی، تصویر و صوت آماده‌اند.'
                    : 'درگاه پاسخ داد، اما همهٔ مسیرهای مدل آماده نیستند.',
            ], $ready ? 200 : 503);
        } catch (ConnectionException) {
            $health->recordFailure('farast-ai-gateway', $token, $model, 'network_error');

            return response()->json([
                'ok' => false,
                'configured' => true,
                'api_reachable' => false,
                'connection_verified' => false,
                'ready' => false,
                'message' => 'ارتباط با درگاه FARAST برقرار نشد؛ نشانی و دسترسی شبکه را بررسی کنید.',
            ], 504);
        } catch (Throwable $exception) {
            $health->recordFailure('farast-ai-gateway', $token, $model, 'internal_error');
            Log::warning('farast.admin.ai_gateway_test_failed', ['error_class' => get_class($exception)]);

            return response()->json([
                'ok' => false,
                'configured' => true,
                'api_reachable' => false,
                'connection_verified' => false,
                'ready' => false,
                'message' => 'خطای داخلی هنگام بررسی درگاه FARAST رخ داد.',
            ], 502);
        }
    }

    public function testVoice(string $provider)
    {
        $account = VoiceProviderAccount::where('provider', $provider)->firstOrFail();
        $configured = $account->credentialsConfigured();
        $verified = (bool) $account->last_success_at
            && (! $account->last_failure_at || $account->last_success_at->gt($account->last_failure_at));
        $ready = $configured && $account->isAvailable();

        return response()->json([
            'ok' => $ready && $verified,
            'provider' => $account->provider,
            'configured' => $configured,
            'enabled' => $account->enabled,
            'ready' => $ready,
            'healthy' => $account->healthy,
            'connection_verified' => $verified,
            'last_success_at' => $account->last_success_at?->toIso8601String(),
            'last_failure_at' => $account->last_failure_at?->toIso8601String(),
            'message' => $verified
                ? ($ready ? 'آخرین درخواست واقعی موفق بوده و سرویس در حال حاضر آماده است.' : 'آخرین درخواست موفق بوده، اما سرویس اکنون به‌دلیل وضعیت فعال‌سازی یا سهمیه آماده نیست.')
                : ($account->last_failure_at ? 'آخرین درخواست واقعی ناموفق بوده است؛ وضعیت سرویس نیازمند بررسی است.' : ($ready ? 'تنظیمات آماده‌اند، اما هنوز درخواست موفقی برای تأیید اتصال ثبت نشده است.' : 'تنظیمات یا وضعیت عملیاتی این سرویس کامل نیست.')),
        ], $ready ? 200 : 503);
    }

    // Direct Groq access is intentionally disabled; model calls must use the FARAST Worker.
    public function updateGroq(Request $request)
    {
        return back()->with('status', 'اتصال مستقیم Groq غیرفعال است؛ همهٔ درخواست‌های مدل از درگاه FARAST عبور می‌کنند.');
    }

    public function testGroq()
    {
        return response()->json([
            'ok' => false,
            'configured' => false,
            'disabled' => true,
            'message' => 'اتصال مستقیم Groq غیرفعال است؛ وضعیت مدل‌ها را از درگاه FARAST بررسی کنید.',
        ], 410);
    }

}
