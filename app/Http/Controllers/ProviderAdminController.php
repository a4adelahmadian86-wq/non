<?php
namespace App\Http\Controllers;
use App\Models\SiteSetting;
use App\Models\VoiceProviderAccount;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;
class ProviderAdminController extends Controller {
 public function updateAi(Request $r){$d=$r->validate(['gemini_api_key'=>'nullable|string|max:500','gemini_model'=>'required|string|max:120','gemini_fallback_model'=>'nullable|string|max:120']);if(filled($d['gemini_api_key']??null))SiteSetting::write('gemini_api_key',trim($d['gemini_api_key']),true);SiteSetting::write('gemini_model',trim($d['gemini_model']));SiteSetting::write('gemini_fallback_model',trim((string)($d['gemini_fallback_model']??'')));app(AuditLogService::class)->record(auth()->id(),'provider.gemini.updated',request()->ip(),['model'=>$d['gemini_model'],'key_replaced'=>filled($d['gemini_api_key']??null)]);return back()->with('status','تنظیمات Gemini ذخیره شد.');}
 public function testAi(){
  $key=trim((string)SiteSetting::read('gemini_api_key',''));
  if($key==='')return response()->json(['ok'=>false,'configured'=>false,'message'=>'کلید Gemini تنظیم نشده است.'],422);

  $selectedModel=trim((string)SiteSetting::read('gemini_model','gemini-3.8-flash'));

  try{
   $response=Http::withHeaders([
    'x-goog-api-key'=>$key,
    'Accept'=>'application/json',
   ])->connectTimeout(5)->timeout(10)->get('https://generativelanguage.googleapis.com/v1beta/models');

   if(!$response->successful()){
    $status=$response->status();
    $message=match($status){
     401,403=>'کلید معتبر نیست یا دسترسی Gemini API برای این پروژه فعال نشده است.',
     429=>'محدودیت نرخ یا سهمیهٔ حساب Gemini مانع پاسخ‌گویی شد.',
     default=>'Gemini API پاسخ موفق نداد؛ تنظیمات پروژه و دسترسی API را بررسی کنید.',
    };
    return response()->json(['ok'=>false,'configured'=>true,'http_status'=>$status,'message'=>$message],$status===429?429:502);
   }

   $models=$response->json('models',[]);
   $models=is_array($models)?$models:[];
   $modelAvailable=collect($models)->contains(function($model)use($selectedModel){
    $name=(string)($model['name']??'');
    return $name===$selectedModel||str_ends_with($name,'/'.$selectedModel);
   });

   return response()->json([
    'ok'=>true,
    'configured'=>true,
    'model'=>$selectedModel,
    'model_available'=>$modelAvailable,
    'models_count'=>count($models),
    'message'=>$modelAvailable
      ?'اتصال Gemini برقرار است و مدل انتخاب‌شده در فهرست حساب دیده می‌شود.'
      :'اتصال Gemini برقرار است، اما مدل انتخاب‌شده در فهرست این حساب دیده نشد.',
   ]);
  }catch(Throwable $exception){
   Log::warning('farast.admin.gemini_test_failed',['error_class'=>get_class($exception)]);
   return response()->json(['ok'=>false,'configured'=>true,'message'=>'خطای داخلی هنگام بررسی اتصال Gemini رخ داد.'],502);
  }
 }
 public function testVoice(string $provider){
  $a=VoiceProviderAccount::where('provider',$provider)->firstOrFail();
  $configured=$a->credentialsConfigured();
  $verified=(bool)$a->last_success_at&&(!$a->last_failure_at||$a->last_success_at->gt($a->last_failure_at));
  $ready=$configured&&$a->isAvailable();
  return response()->json([
   'ok'=>$ready&&$verified,
   'provider'=>$a->provider,
   'configured'=>$configured,
   'enabled'=>$a->enabled,
   'ready'=>$ready,
   'healthy'=>$a->healthy,
   'connection_verified'=>$verified,
   'last_success_at'=>$a->last_success_at?->toIso8601String(),
   'last_failure_at'=>$a->last_failure_at?->toIso8601String(),
   'message'=>$verified
    ?($ready?'آخرین درخواست واقعی موفق بوده و سرویس در حال حاضر آماده است.':'آخرین درخواست موفق بوده، اما سرویس اکنون به‌دلیل وضعیت فعال‌سازی یا سهمیه آماده نیست.')
    :($a->last_failure_at?'آخرین درخواست واقعی ناموفق بوده است؛ وضعیت سرویس نیازمند بررسی است.':($ready?'تنظیمات آماده‌اند، اما هنوز درخواست موفقی برای تأیید اتصال ثبت نشده است.':'تنظیمات یا وضعیت عملیاتی این سرویس کامل نیست.')),
  ],$ready?200:503);
 }
 // Groq key is encrypted in SiteSetting and is shared by assistant chat and speech-to-text.
 public function updateGroq(Request $r){
  $d=$r->validate([
   'voice_groq_key'=>'nullable|string|max:500',
   'assistant_groq_model'=>['required','string','max:120','regex:/^[A-Za-z0-9._:\/-]+$/'],
  ]);
  if(filled($d['voice_groq_key']??null)){
   SiteSetting::write('voice_groq_key',trim($d['voice_groq_key']),true);
   app(AuditLogService::class)->record(auth()->id(),'provider.groq.updated',request()->ip(),['key_replaced'=>true,'model'=>$d['assistant_groq_model']]);
  }
  SiteSetting::write('assistant_groq_model',trim($d['assistant_groq_model']));
  return back()->with('status',filled($d['voice_groq_key']??null)?'کلید Groq و مدل دستیار ذخیره شد.':'مدل Groq ذخیره شد؛ کلید قبلی بدون تغییر باقی ماند.');
 }
 public function testGroq(){
  try{
   $key=trim((string)(SiteSetting::read('voice_groq_key')?:env('GROQ_API_KEY','')));
   if($key==='')return response()->json(['ok'=>false,'message'=>'کلید Groq وارد نشده است.']);
   $model=trim((string)SiteSetting::read('assistant_groq_model','openai/gpt-oss-120b'));
   $resp=\Illuminate\Support\Facades\Http::withHeaders(['Authorization'=>'Bearer '.$key])->timeout(20)->get('https://api.groq.com/openai/v1/models');
   if(!$resp->successful())return response()->json(['ok'=>false,'message'=>$resp->status()===401?'کلید Groq معتبر نیست.':'اتصال Groq برقرار نشد (HTTP '.$resp->status().').']);
   $ids=collect($resp->json('data',[]))->pluck('id')->map(fn($id)=>(string)$id);
   $chatAvailable=$ids->contains($model);
   $whisperAvailable=$ids->contains('whisper-large-v3')||$ids->contains('whisper-large-v3-turbo');
   return response()->json([
    'ok'=>$chatAvailable,
    'chat_model_available'=>$chatAvailable,
    'speech_model_available'=>$whisperAvailable,
    'message'=>$chatAvailable
      ?('اتصال Groq برقرار است؛ مدل گفت‌وگو '.$model.($whisperAvailable?' و مدل تشخیص گفتار نیز در دسترس است.':'. مدل تشخیص گفتار در فهرست فعلی دیده نشد.'))
      :'کلید پذیرفته شد، اما مدل گفت‌وگوی تنظیم‌شده در فهرست مجاز این حساب نیست. مدل را بررسی کنید.',
   ]);
  }catch(\Throwable $e){
   \Illuminate\Support\Facades\Log::warning('farast.admin.groq_test_failed',['error_class'=>get_class($e)]);
   return response()->json(['ok'=>false,'message'=>'خطای شبکه هنگام بررسی Groq؛ بعداً دوباره تلاش کنید.']);
  }
 }
}
