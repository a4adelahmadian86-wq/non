<?php
namespace App\Http\Controllers;
use App\Models\SiteSetting;
use App\Models\VoiceProviderAccount;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
class ProviderAdminController extends Controller {
 public function updateAi(Request $r){$d=$r->validate(['gemini_api_key'=>'nullable|string|max:500','gemini_model'=>'required|string|max:120']);if(filled($d['gemini_api_key']??null))SiteSetting::write('gemini_api_key',trim($d['gemini_api_key']),true);SiteSetting::write('gemini_model',trim($d['gemini_model']));app(AuditLogService::class)->record(auth()->id(),'provider.gemini.updated',request()->ip(),['model'=>$d['gemini_model'],'key_replaced'=>filled($d['gemini_api_key']??null)]);return back()->with('status','تنظیمات Gemini ذخیره شد.');}
 public function testVoice(string $provider){$a=VoiceProviderAccount::where('provider',$provider)->firstOrFail();return response()->json(['ok'=>true,'provider'=>$a->provider,'configured'=>filled($a->credentials_array),'enabled'=>$a->enabled,'healthy'=>$a->healthy,'message'=>'اعتبارسنجی شبکه‌ای provider از محیط اجرا و credential واقعی انجام می‌شود؛ این endpoint مقدار secret را برنمی‌گرداند.']);}
}
