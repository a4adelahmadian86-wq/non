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
            'gemini_api_key' => 'nullable|string|max:500',
            'gemini_model' => 'required|string|max:120',
            'gemini_fallback_model' => 'nullable|string|max:120',
        ]);

        if (filled($data['gemini_api_key'] ?? null)) {
            SiteSetting::write('gemini_api_key', trim($data['gemini_api_key']), true);
        }
        SiteSetting::write('gemini_model', trim($data['gemini_model']));
        SiteSetting::write('gemini_fallback_model', trim((string) ($data['gemini_fallback_model'] ?? '')));

        app(AuditLogService::class)->record(auth()->id(), 'provider.gemini.updated', $request->ip(), [
            'model' => $data['gemini_model'],
            'key_replaced' => filled($data['gemini_api_key'] ?? null),
        ]);

        return back()->with('status', 'تنظیمات Gemini ذخیره شد.');
    }

    public function testAi(ProviderConnectionHealth $health)
    {
        $key = trim((string) (SiteSetting::read('gemini_api_key') ?: config('services.gemini.key', '')));
        if ($key === '') {
            return response()->json([
                'ok' => false,
                'configured' => false,
                'api_reachable' => false,
                'connection_verified' => false,
                'ready' => false,
                'message' => 'کلید Gemini تنظیم نشده است.',
            ], 422);
        }

        $selectedModel = trim((string) (SiteSetting::read('gemini_model') ?: config('services.gemini.model', 'gemini-3.8-flash')));

        try {
            $response = Http::withHeaders([
                'x-goog-api-key' => $key,
                'Accept' => 'application/json',
            ])->connectTimeout(5)->timeout(10)->get('https://generativelanguage.googleapis.com/v1beta/models');

            if (! $response->successful()) {
                $status = $response->status();
                $health->recordFailure('gemini', $key, $selectedModel, 'http_'.$status);
                $message = match ($status) {
                    401, 403 => 'کلید معتبر نیست یا دسترسی Gemini API برای این پروژه فعال نشده است.',
                    429 => 'محدودیت نرخ یا سهمیهٔ حساب Gemini مانع پاسخ‌گویی شد.',
                    default => 'Gemini API پاسخ موفق نداد؛ تنظیمات پروژه و دسترسی API را بررسی کنید.',
                };

                return response()->json([
                    'ok' => false,
                    'configured' => true,
                    'api_reachable' => true,
                    'connection_verified' => false,
                    'ready' => false,
                    'http_status' => $status,
                    'message' => $message,
                ], $status === 429 ? 429 : 502);
            }

            $models = $response->json('models', []);
            $models = is_array($models) ? $models : [];
            $modelAvailable = collect($models)->contains(function ($model) use ($selectedModel): bool {
                $name = (string) ($model['name'] ?? '');

                return $name === $selectedModel || str_ends_with($name, '/'.$selectedModel);
            });

            $health->recordSuccess('gemini', $key, $selectedModel, ['text' => $modelAvailable]);

            return response()->json([
                'ok' => true,
                'configured' => true,
                'api_reachable' => true,
                'connection_verified' => true,
                'ready' => $modelAvailable,
                'model' => $selectedModel,
                'model_available' => $modelAvailable,
                'models_count' => count($models),
                'message' => $modelAvailable
                    ? 'اتصال Gemini برقرار است و مدل انتخاب‌شده در فهرست حساب دیده می‌شود.'
                    : 'API پاسخ‌گو است، اما مدل انتخاب‌شده در فهرست این حساب دیده نشد.',
            ]);
        } catch (ConnectionException) {
            $health->recordFailure('gemini', $key, $selectedModel, 'network_error');

            return response()->json([
                'ok' => false,
                'configured' => true,
                'api_reachable' => false,
                'connection_verified' => false,
                'ready' => false,
                'message' => 'ارتباط شبکه با Gemini برقرار نشد؛ دسترسی خروجی سرور را بررسی کنید.',
            ], 504);
        } catch (Throwable $exception) {
            $health->recordFailure('gemini', $key, $selectedModel, 'internal_error');
            Log::warning('farast.admin.gemini_test_failed', ['error_class' => get_class($exception)]);

            return response()->json([
                'ok' => false,
                'configured' => true,
                'api_reachable' => false,
                'connection_verified' => false,
                'ready' => false,
                'message' => 'خطای داخلی هنگام بررسی اتصال Gemini رخ داد.',
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

    // Groq credentials are shared by assistant chat and speech-to-text.
    public function updateGroq(Request $request)
    {
        $data = $request->validate([
            'voice_groq_key' => 'nullable|string|max:500',
            'assistant_groq_model' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:\/-]+$/'],
        ]);

        if (filled($data['voice_groq_key'] ?? null)) {
            SiteSetting::write('voice_groq_key', trim($data['voice_groq_key']), true);
            app(AuditLogService::class)->record(auth()->id(), 'provider.groq.updated', $request->ip(), [
                'key_replaced' => true,
                'model' => $data['assistant_groq_model'],
            ]);
        }
        SiteSetting::write('assistant_groq_model', trim($data['assistant_groq_model']));

        return back()->with(
            'status',
            filled($data['voice_groq_key'] ?? null)
                ? 'کلید Groq و مدل دستیار ذخیره شد.'
                : 'مدل Groq ذخیره شد؛ کلید قبلی بدون تغییر باقی ماند.'
        );
    }

    public function testGroq(ProviderConnectionHealth $health)
    {
        $key = trim((string) (SiteSetting::read('voice_groq_key') ?: config('services.groq.key', '')));
        if ($key === '') {
            return response()->json([
                'ok' => false,
                'configured' => false,
                'api_reachable' => false,
                'connection_verified' => false,
                'ready' => false,
                'message' => 'کلید Groq وارد نشده است.',
            ], 422);
        }

        $model = trim((string) (SiteSetting::read('assistant_groq_model') ?: 'openai/gpt-oss-120b'));

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$key,
                'Accept' => 'application/json',
            ])->connectTimeout(5)->timeout(10)->get('https://api.groq.com/openai/v1/models');

            if (! $response->successful()) {
                $status = $response->status();
                $health->recordFailure('groq', $key, $model, 'http_'.$status);

                return response()->json([
                    'ok' => false,
                    'configured' => true,
                    'api_reachable' => true,
                    'connection_verified' => false,
                    'ready' => false,
                    'http_status' => $status,
                    'message' => match ($status) {
                        401, 403 => 'کلید Groq معتبر نیست یا دسترسی این حساب محدود شده است.',
                        429 => 'محدودیت نرخ یا سهمیهٔ حساب Groq مانع پاسخ‌گویی شد.',
                        default => 'Groq API پاسخ موفق نداد؛ تنظیمات حساب و دسترسی API را بررسی کنید.',
                    },
                ], $status === 429 ? 429 : 502);
            }

            $ids = collect($response->json('data', []))
                ->pluck('id')
                ->map(fn ($id) => (string) $id);
            $chatAvailable = $ids->contains($model);
            $speechAvailable = $ids->contains('whisper-large-v3') || $ids->contains('whisper-large-v3-turbo');

            $health->recordSuccess('groq', $key, $model, [
                'chat' => $chatAvailable,
                'speech' => $speechAvailable,
            ]);

            return response()->json([
                'ok' => true,
                'configured' => true,
                'api_reachable' => true,
                'connection_verified' => true,
                'ready' => $chatAvailable,
                'chat_model_available' => $chatAvailable,
                'speech_model_available' => $speechAvailable,
                'model' => $model,
                'message' => $chatAvailable
                    ? 'اتصال Groq برقرار است و مدل گفت‌وگو در دسترس است.'.($speechAvailable ? ' مدل تشخیص گفتار نیز موجود است.' : ' مدل تشخیص گفتار در فهرست فعلی دیده نشد.')
                    : 'API پاسخ‌گو است، اما مدل گفت‌وگوی تنظیم‌شده در فهرست این حساب دیده نشد.',
            ]);
        } catch (ConnectionException) {
            $health->recordFailure('groq', $key, $model, 'network_error');

            return response()->json([
                'ok' => false,
                'configured' => true,
                'api_reachable' => false,
                'connection_verified' => false,
                'ready' => false,
                'message' => 'ارتباط شبکه با Groq برقرار نشد؛ دسترسی خروجی سرور را بررسی کنید.',
            ], 504);
        } catch (Throwable $exception) {
            $health->recordFailure('groq', $key, $model, 'internal_error');
            Log::warning('farast.admin.groq_test_failed', ['error_class' => get_class($exception)]);

            return response()->json([
                'ok' => false,
                'configured' => true,
                'api_reachable' => false,
                'connection_verified' => false,
                'ready' => false,
                'message' => 'خطای داخلی هنگام بررسی اتصال Groq رخ داد.',
            ], 502);
        }
    }
}
