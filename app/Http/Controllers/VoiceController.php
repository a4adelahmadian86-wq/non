<?php

namespace App\Http\Controllers;

use App\Models\VoiceProviderAccount;
use App\Services\AI\AiPrivacyPolicy;
use App\Services\AI\AiQuotaService;
use App\Services\GoogleSpeechToTextService;
use App\Services\VoiceCorrectionService;
use App\Services\VoiceProviderRouter;
use App\Services\VoiceQuotaManager;
use App\Services\VoiceTranscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class VoiceController extends Controller
{
    public function streamToken(Request $request)
    {
        $request->validate(['locale'=>['required','string','in:fa-IR,en-US,ar-SA']]);
        $payload=['uid'=>(int)$request->user()->id,'locale'=>$request->string('locale')->toString(),'iat'=>time(),'exp'=>time()+120,'nonce'=>bin2hex(random_bytes(12))];
        return response()->json(['ok'=>true,'token'=>$this->signStreamPayload($payload),'websocket_url'=>rtrim((string)config('services.voice_stream.url',env('VOICE_STREAM_URL','ws://127.0.0.1:6002')),'/')]);
    }

    public function streamConfig(Request $request, VoiceProviderRouter $router)
    {
        $token=(string)$request->input('token','');
        $payload=$this->verifyStreamToken($token);
        abort_unless($payload,401);
        abort_unless(hash_equals($this->gatewaySignature($token),(string)$request->header('X-Farast-Voice-Gateway')),403);
        $exclude=$request->input('exclude_account_ids',[]);
        $exclude=is_array($exclude)?array_values(array_filter(array_map('intval',$exclude))):[];
        $account=$router->best((string)$payload['locale'],$exclude);
        abort_unless($account,503,'voice_provider_unavailable');
        return response()->json(['ok'=>true,'provider'=>$account->provider,'model'=>$account->model,'region'=>$account->metadata['region']??env('GOOGLE_SPEECH_REGION','us'),'credentials'=>$account->credentials_array,'account_id'=>$account->id,'quality_score'=>(int)$account->quality_score],200,['Cache-Control'=>'no-store']);
    }

    public function streamUsage(Request $request, VoiceQuotaManager $quota)
    {
        $token=(string)$request->input('token','');
        $payload=$this->verifyStreamToken($token);
        abort_unless($payload,401);
        abort_unless(hash_equals($this->gatewaySignature($token),(string)$request->header('X-Farast-Voice-Gateway')),403);
        $data=$request->validate(['account_id'=>['required','integer','exists:voice_provider_accounts,id'],'audio_seconds'=>['required','numeric','min:0','max:86400'],'input_bytes'=>['nullable','integer','min:0'],'output_bytes'=>['nullable','integer','min:0'],'success'=>['nullable','boolean'],'latency_ms'=>['nullable','integer','min:0','max:3600000']]);
        $account=VoiceProviderAccount::findOrFail($data['account_id']);
        $quota->consume($account,(float)$data['audio_seconds'],(int)($data['input_bytes']??0),(int)($data['output_bytes']??0),(bool)($data['success']??true));
        $quota->recordRequest($account,(bool)($data['success']??true),isset($data['latency_ms'])?(int)$data['latency_ms']:null);
        return response()->json(['ok'=>true]);
    }

    public function transcribe(Request $request,GoogleSpeechToTextService $googleSpeech,VoiceTranscriptionService $voice,VoiceCorrectionService $corrections,AiQuotaService $quota,AiPrivacyPolicy $privacy)
    {
        $quota->assertAllowed($request->user(),'can_voice');
        $data=$request->validate(['audio'=>['required','file','max:15360','mimetypes:audio/webm,video/webm,audio/ogg,audio/wav,audio/x-wav,audio/mpeg,audio/mp4,video/mp4'],'locale'=>['required','string','in:fa-IR,en-US,ar-SA'],'processing_mode'=>['nullable','string','in:automatic,local,server,external']]);
        $processingMode=$privacy->resolve($data['processing_mode']??null,['external']);
        $file=$data['audio'];$bytes=file_get_contents($file->getRealPath());abort_if($bytes===false||$bytes==='',422,'فایل صوتی قابل خواندن نیست.');
        $mime=$file->getMimeType()?:$file->getClientMimeType()?:'audio/webm';$mime=match($mime){ 'video/webm'=>'audio/webm','video/mp4','audio/mp4'=>'audio/m4a','audio/x-wav'=>'audio/wav',default=>$mime};
        $context=['user_id'=>$request->user()->id,'input_bytes'=>strlen($bytes),'processing_mode'=>$processingMode];
        try{$result=$googleSpeech->enabled()&&in_array($mime,['audio/webm','audio/ogg','audio/wav'],true)?$googleSpeech->transcribe($mime,$bytes,$data['locale'],$context):$voice->transcribe($mime,$bytes,$data['locale'],$context);$rawText=(string)($result['text']??'');$finalText=$corrections->apply($rawText,$data['locale'],$result['engine']??null,$request->user()->id);$result['raw_text']=$rawText;$result['text']=$finalText;$result['correction_applied']=$finalText!==$rawText;}catch(\Throwable $e){Log::warning('farast.voice.transcription_failed',['user_id'=>$request->user()->id,'google_speech_enabled'=>$googleSpeech->enabled(),'error_code'=>preg_match('/^[a-z0-9_.-]+$/i',$e->getMessage())?$e->getMessage():'voice_provider_failure']);return response()->json(['ok'=>false,'message'=>'رونویسی صوتی انجام نشد. دوباره تلاش کنید.'],502);}
        return response()->json(['ok'=>true,'text'=>$result['text'],'engine'=>$result['engine']??'gemini','interaction_id'=>$result['interaction_id']??null,'request_id'=>$result['request_id']??null,'ai'=>['request_id'=>$result['request_id']??null,'operation'=>'voice.transcribe','provider'=>$result['engine']??'gemini','model'=>$result['model']??null,'processing_mode'=>$processingMode,'status'=>'completed','result'=>['text'=>$result['text']],'suggestions'=>[],'warnings'=>[],'metadata'=>['interaction_id'=>$result['interaction_id']??null,'locale'=>$data['locale'],'correction_applied'=>(bool)($result['correction_applied']??false)],'usage'=>null,'error'=>null]]);
    }

    private function signStreamPayload(array $payload):string{$encoded=rtrim(strtr(base64_encode(json_encode($payload,JSON_UNESCAPED_SLASHES)),'+/','-_'),'=');return $encoded.'.'.hash_hmac('sha256',$encoded,(string)config('app.key'));}
    private function verifyStreamToken(string $token):?array{if($token===''||!str_contains($token,'.'))return null;[$payload,$signature]=array_pad(explode('.',$token,2),2,'');$expected=hash_hmac('sha256',$payload,(string)config('app.key'));if($signature===''||!hash_equals($expected,$signature))return null;$decoded=base64_decode(strtr($payload,'-_','+/').str_repeat('=',(4-strlen($payload)%4)%4),true);if($decoded===false)return null;$data=json_decode($decoded,true);if(!is_array($data)||empty($data['uid'])||empty($data['locale'])||(int)($data['exp']??0)<time())return null;return $data;}
    private function gatewaySignature(string $token):string{return hash_hmac('sha256',$token,(string)config('app.key'));}
}
