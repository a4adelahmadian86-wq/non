<?php

namespace App\Http\Controllers;

use App\Services\EditorAiAssistService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class EditorAiAssistController extends Controller
{
    public function __invoke(Request $request, EditorAiAssistService $ai)
    {
        $data = $request->validate([
            'operation'=>['required','string','max:80'],'text'=>['required','string','max:100000'],'sentence'=>['nullable','string','max:4000'],
            'before_cursor'=>['nullable','string','max:12000'],'after_cursor'=>['nullable','string','max:12000'],'target_language'=>['nullable','string','max:120'],
            'tone'=>['nullable','string','max:120'],'instruction'=>['nullable','string','max:1000'],'processing_mode'=>['nullable','string','in:automatic,local,server,external'],
            'provider'=>['nullable','string','max:40','regex:/^[A-Za-z0-9._-]+$/'],
        ]);
        try {
            $result = $ai->assist($data['operation'],$data['text'],array_merge($data,['user'=>$request->user()]));
            return response()->json(array_merge(['ok'=>true],$result));
        } catch (ValidationException|HttpExceptionInterface $e) { throw $e; }
        catch (\InvalidArgumentException $e) { return response()->json(['ok'=>false,'message'=>$this->safeMessage($e->getMessage())],422); }
        catch (\Throwable $e) {
            $code = preg_match('/^ai_[a-z0-9_]+$/',$e->getMessage()) ? $e->getMessage() : 'ai_provider_failure';
            Log::warning('farast.editor.ai_assist_failed',['user_id'=>$request->user()->id,'operation'=>$data['operation'],'error_code'=>$code]);
            return response()->json(['ok'=>false,'error_code'=>$code,'message'=>$this->safeMessage($code)],502);
        }
    }

    private function safeMessage(string $code): string
    {
        return match ($code) {
            'ai_provider_not_configured' => 'اتصال هوش مصنوعی تنظیم نشده است. کلید Gemini را در تنظیمات مدیریت ثبت کنید یا GEMINI_API_KEY را در محیط سرور قرار دهید.',
            'ai_provider_auth_failed' => 'کلید Gemini معتبر نیست یا دسترسی آن رد شده است. تنظیمات کلید و مدل را بررسی کنید.',
            'ai_provider_rate_limited' => 'سرویس Gemini موقتاً سقف درخواست را اعلام کرده است. چند لحظه بعد دوباره تلاش کنید.',
            'ai_provider_service_unavailable' => 'سرویس Gemini موقتاً در دسترس نیست. متن شما به ارائه‌دهنده دیگری ارسال نشد.',
            'ai_provider_invalid_json', 'ai_provider_invalid_shape' => 'پاسخ هوش مصنوعی با قرارداد خروجی فراست سازگار نبود و برای جلوگیری از اعمال نتیجه ناقص رد شد.',
            'ai_provider_model_unavailable' => 'مدل هوش مصنوعی تنظیم نشده است.',
            'ai_provider_unavailable' => 'هیچ ارائه‌دهنده فعال برای این عملیات وجود ندارد و fallback خودکار انجام نشد.',
            'ai_provider_operation_unsupported' => 'ارائه‌دهنده انتخاب‌شده از این عملیات پشتیبانی نمی‌کند و ارائه‌دهنده دیگری جایگزین نشد.',
            default => 'پردازش هوش مصنوعی انجام نشد. جزئیات محرمانه سرویس به مرورگر نمایش داده نمی‌شود.',
        };
    }
}
