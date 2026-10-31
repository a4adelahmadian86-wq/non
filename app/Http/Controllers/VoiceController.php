<?php

namespace App\Http\Controllers;

use App\Models\VoiceProviderAccount;
use App\Models\TypingDocument;
use App\Services\ToolExecutionService;
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
    public function voiceStatus(Request $request)
    {
        if (! $request->user()?->id) {
            return response()->json(['ok' => true, 'mode' => 'none', 'reason' => 'auth'], 200, ['Cache-Control' => 'no-store']);
        }

        $wsUrl = rtrim((string) config('services.voice_stream.url', ''), '/');
        $gatewaySecret = trim((string) config('services.voice_stream.gateway_secret', ''));
        $gatewayMode = (string) config('services.voice_stream.mode', 'self_hosted');
        $gatewayReady = false;

        if ($gatewayMode === 'self_hosted') {
            $gatewayReady = $wsUrl !== '' && $gatewaySecret !== '';
        } elseif ($gatewayMode === 'router') {
            try {
                $account = app(VoiceProviderRouter::class)->best('fa-IR');
                $gatewayReady = $gatewaySecret !== '' && $account !== null && $wsUrl !== '';
            } catch (\\Throwable) {
                $gatewayReady = false;
            }
        }

        if ($gatewayReady) {
            return response()->json([
                'ok' => true,
                'mode' => 'gateway',
                'chunked' => false,
                'gateway' => true,
                'websocket_url' => preg_replace('#^http#', 'ws', $wsUrl),
                'reason' => null,
            ], 200, ['Cache-Control' => 'no-store']);
        }

        $asrUrl = trim((string) config('services.voice_asr.url', ''));
        $asrToken = trim((string) config('services.voice_asr.token', ''));
        if ($asrUrl !== '') {
            if ($asrToken === '') {
                return response()->json([
                    'ok' => true,
                    'mode' => 'none',
                    'reason' => 'self_hosted_token_missing',
                    'self_hosted' => true,
                    'chunked' => false,
                    'gateway' => false,
                    'websocket_url' => '',
                ], 200, ['Cache-Control' => 'no-store']);
            }

            return response()->json([
                'ok' => true,
                'mode' => 'chunked',
                'chunked' => true,
                'gateway' => false,
                'websocket_url' => '',
                'engines' => ['self-hosted-asr'],
                'self_hosted' => true,
            ], 200, ['Cache-Control' => 'no-store']);
        }

        return response()->json([
            'ok' => true,
            'mode' => 'none',
            'reason' => 'self_hosted_service_not_configured',
            'self_hosted' => true,
            'chunked' => false,
            'gateway' => false,
            'websocket_url' => '',
        ], 200, ['Cache-Control' => 'no-store']);
    }

    public function streamToken(Request $request)
    {
        $request->validate(['locale'=>['required','string','in:fa-IR,en-US,ar-SA']]);
        $payload=['uid'=>(int)$request->user()->id,'locale'=>$request->string('locale')->toString(),'iat'=>time(),'exp'=>time()+120,'nonce'=>bin2hex(random_bytes(12))];
        return response()->json(['ok'=>true,'session_id'=>$payload['nonce'],'token'=>$this->signStreamPayload($payload),'expires_at'=>date(DATE_ATOM,$payload['exp']),'websocket_url'=>rtrim((string)config('services.voice_stream.url',''),'/')]);
    }

    public function streamConfig(Request $request, \App\Services\CommerceAuthorizationService $commerce)
    {
        $token=(string)$request->input('token','');
        $payload=$this->verifyStreamToken($token);
        abort_unless($payload,401);
        $gatewaySecret=(string)config('services.voice_stream.gateway_secret','');
        abort_unless($gatewaySecret!=='' && hash_equals($this->gatewaySignature($token),(string)$request->header('X-Farast-Voice-Gateway')),403);
        $reservationId = null;
        if ((string) config('services.voice_stream.mode', 'router') === 'self_hosted') {
            try {
                $user = \App\Models\User::findOrFail((int) $payload['uid']);
                $reservation = $commerce->reserve($user, 'speech.transcription', 1, [], [
                    'policy_code' => 'speech.transcription',
                    'unit' => 'request',
                    'allow_payg' => true,
                    'reservation_ttl_minutes' => 45,
                    'idempotency_key' => 'voice-live-'.$payload['uid'].'-'.$payload['nonce'],
                ]);
                $reservationId = $reservation->reservation_id;
            } catch (\Throwable $e) {
                Log::warning('farast.voice.live_reservation_failed', ['user_id' => (int) $payload['uid'], 'error_code' => preg_match('/^[a-z0-9_.-]+$/i', $e->getMessage()) ? $e->getMessage() : 'reservation_failed']);
                return response()->json(['ok' => false, 'message' => 'voice_quota_unavailable'], 402, ['Cache-Control' => 'no-store']);
            }
        }
        return response()->json(['ok'=>true,'gateway'=>true,'uid'=>(int)$payload['uid'],'locale'=>$payload['locale'],'reservation_id'=>$reservationId],200,['Cache-Control'=>'no-store']);
    }

    public function streamProviderConfig(Request $request, VoiceProviderRouter $router)
    {
        $token=(string)$request->input('token','');
        $payload=$this->verifyStreamToken($token);
        abort_unless($payload,401);
        abort_unless(hash_equals($this->gatewaySignature($token),(string)$request->header('X-Farast-Voice-Gateway')),403);
        $exclude=$request->input('exclude_account_ids',[]);
        $exclude=is_array($exclude)?array_values(array_filter(array_map('intval',$exclude))):[];
        $account=$router->best((string)$payload['locale'],$exclude);
        abort_unless($account,503,'voice_provider_unavailable');
        $gatewaySecret=(string)config('services.voice_stream.gateway_secret','');
        abort_unless($gatewaySecret!=='' && hash_equals(hash_hmac('sha256',$token,$gatewaySecret),(string)$request->header('X-Farast-Voice-Secret')),403);
        return response()->json(['ok'=>true,'provider'=>$account->provider,'model'=>$account->model,'region'=>$account->metadata['region']??env('GOOGLE_SPEECH_REGION','us'),'credentials'=>$account->credentials_array,'account_id'=>$account->id,'quality_score'=>(int)$account->quality_score,'capabilities'=>$account->capabilities],200,['Cache-Control'=>'no-store']);
    }

    public function streamUsage(Request $request, VoiceQuotaManager $quota, \App\Services\CommerceAuthorizationService $commerce)
    {
        $token=(string)$request->input('token','');
        $payload=$this->verifyStreamToken($token, true);
        abort_unless($payload,401);
        abort_unless(hash_equals($this->gatewaySignature($token),(string)$request->header('X-Farast-Voice-Gateway')),403);
        if ((string) config('services.voice_stream.mode', 'router') === 'self_hosted') {
            $data = $request->validate([
                'reservation_id' => ['required', 'string', 'max:100'],
                'audio_seconds' => ['required', 'numeric', 'min:0', 'max:1800'],
                'input_bytes' => ['nullable', 'integer', 'min:0'],
                'success' => ['required', 'boolean'],
                'latency_ms' => ['nullable', 'integer', 'min:0', 'max:3600000'],
            ]);
            // A signed token is user-scoped; bind the reservation to that same user
            // and capability before allowing the gateway to commit or release it.
            \App\Models\FarastUsageReservation::query()
                ->where('reservation_id', $data['reservation_id'])
                ->where('actor_id', (int) $payload['uid'])
                ->where('capability', 'speech.transcription')
                ->where('idempotency_key', 'voice-live-'.$payload['uid'].'-'.$payload['nonce'])
                ->firstOrFail();

            if ($data['success'] && (float) $data['audio_seconds'] > 0) {
                $commerce->commit($data['reservation_id'], [
                    'cost_metadata' => ['provider' => 'self-hosted-whisper', 'audio_seconds' => (float) $data['audio_seconds'], 'input_bytes' => (int) ($data['input_bytes'] ?? 0)],
                    'metadata' => ['operation' => 'voice.live_stream', 'latency_ms' => (int) ($data['latency_ms'] ?? 0)],
                ]);
            } else {
                $commerce->release($data['reservation_id']);
            }
            return response()->json(['ok' => true]);
        }
        $data=$request->validate(['account_id'=>['required','integer','exists:voice_provider_accounts,id'],'audio_seconds'=>['required','numeric','min:0','max:86400'],'input_bytes'=>['nullable','integer','min:0'],'output_bytes'=>['nullable','integer','min:0'],'success'=>['nullable','boolean'],'latency_ms'=>['nullable','integer','min:0','max:3600000']]);
        $account=VoiceProviderAccount::findOrFail($data['account_id']);
        $quota->consume($account,(float)$data['audio_seconds'],(int)($data['input_bytes']??0),(int)($data['output_bytes']??0),(bool)($data['success']??true));
        $quota->recordRequest($account,(bool)($data['success']??true),isset($data['latency_ms'])?(int)$data['latency_ms']:null);
        return response()->json(['ok'=>true]);
    }

    public function transcribe(
        Request $request,
        VoiceCorrectionService $corrections,
        AiQuotaService $quota,
        AiPrivacyPolicy $privacy,
        \\App\\Services\\CommerceAuthorizationService $commerce
    ) {
        $quota->assertAllowed($request->user(), 'can_voice');
        $data = $request->validate([
            'audio' => ['required', 'file', 'max:15360', 'mimetypes:audio/webm,video/webm,audio/ogg,audio/wav,audio/x-wav,audio/mpeg,audio/mp4,video/mp4'],
            'locale' => ['required', 'string', 'in:fa-IR,en-US,ar-SA'],
            'processing_mode' => ['nullable', 'string', 'in:automatic,local,server,external'],
        ]);

        $asrUrl = trim((string) config('services.voice_asr.url', ''));
        $asrToken = trim((string) config('services.voice_asr.token', ''));
        if ($asrUrl === '' || $asrToken === '') {
            return response()->json([
                'ok' => false,
                'message' => 'موتور گفتار خودمیزبان پیکربندی نشده است؛ هیچ ارائه‌دهندهٔ ابری به‌صورت خودکار جایگزین نمی‌شود.',
            ], 503, ['Cache-Control' => 'no-store']);
        }

        $processingMode = $privacy->resolve($data['processing_mode'] ?? null, ['server']);
        $file = $data['audio'];
        $bytes = file_get_contents($file->getRealPath());
        abort_if($bytes === false || $bytes === '', 422, 'فایل صوتی قابل خواندن نیست.');
        $mime = $file->getMimeType() ?: $file->getClientMimeType() ?: 'audio/webm';
        $mime = match ($mime) {
            'video/webm' => 'audio/webm',
            'video/mp4', 'audio/mp4' => 'audio/m4a',
            'audio/x-wav' => 'audio/wav',
            default => $mime,
        };
        $extension = strtolower($file->getClientOriginalExtension() ?: 'webm');
        if (! in_array($extension, ['webm', 'ogg', 'wav', 'mp3', 'm4a', 'mp4'], true)) $extension = 'webm';

        $voiceReservation = null;
        try {
            $response = \\Illuminate\\Support\\Facades\\Http::withToken($asrToken)->acceptJson()->connectTimeout(5)->timeout(60)
                ->attach('audio', $bytes, 'farast-voice.'.$extension, ['Content-Type' => $mime])
                ->post($asrUrl, ['locale' => $data['locale']]);
            if (! $response->successful()) throw new \\RuntimeException('voice_self_hosted_http_'.$response->status());

            $rawText = trim((string) $response->json('text', ''));
            if ($rawText === '') {
                return response()->json(['ok' => true, 'text' => '', 'engine' => 'farast-self-hosted-asr', 'empty' => true], 200, ['Cache-Control' => 'no-store']);
            }

            $requestKey = trim((string) ($request->header('Idempotency-Key') ?: $request->header('X-Farast-Voice-Request', '')));
            $idempotencyKey = 'voice-'.$request->user()->id.'-'.hash('sha256', $requestKey !== '' ? $requestKey : (string) \\Illuminate\\Support\\Str::uuid());
            $voiceReservation = $commerce->reserve($request->user(), 'speech.transcription', 1, [], [
                'policy_code' => 'speech.transcription',
                'unit' => 'request',
                'allow_payg' => true,
                'idempotency_key' => $idempotencyKey,
            ]);
            $finalText = $corrections->apply($rawText, $data['locale'], 'farast-self-hosted-asr', $request->user()->id);
        } catch (\\Throwable $e) {
            if ($voiceReservation) {
                try { $commerce->release($voiceReservation); } catch (\\Throwable) {}
            }
            $errorCode = (string) $e->getMessage();
            Log::warning('farast.voice.transcription_failed', [
                'user_id' => $request->user()->id,
                'processing_mode' => $processingMode,
                'error_code' => preg_match('/^[a-z0-9_.-]+$/i', $errorCode) ? $errorCode : 'voice_provider_failure',
            ]);
            $message = match (true) {
                str_starts_with($errorCode, 'voice_self_hosted_http_401'), str_starts_with($errorCode, 'voice_self_hosted_http_403') => 'احراز هویت موتور گفتار خودمیزبان ناموفق است؛ توکن سرویس و FARAST_VOICE_ASR_TOKEN باید یکسان باشند.',
                str_starts_with($errorCode, 'voice_self_hosted_http_429') => 'موتور گفتار خودمیزبان موقتاً محدودیت درخواست دارد؛ کمی بعد دوباره تلاش کنید.',
                str_starts_with($errorCode, 'voice_self_hosted_http_503') => 'موتور گفتار خودمیزبان هنوز آماده نیست؛ وضعیت سرویس را بررسی کنید.',
                default => 'رونویسی صوتی انجام نشد. دوباره تلاش کنید.',
            };
            return response()->json(['ok' => false, 'message' => $message], 502, ['Cache-Control' => 'no-store']);
        }

        $commerce->commit($voiceReservation, [
            'cost_metadata' => ['provider' => 'farast-self-hosted-asr', 'input_bytes' => strlen($bytes)],
            'metadata' => ['locale' => $data['locale'], 'correction_applied' => $finalText !== $rawText],
        ]);
        return response()->json([
            'ok' => true,
            'text' => $finalText,
            'engine' => 'farast-self-hosted-asr',
            'correction_applied' => $finalText !== $rawText,
        ], 200, ['Cache-Control' => 'no-store']);
    }

    public function insertTranscript(Request $request, ToolExecutionService $tools)
    {
        $data=$request->validate([
            'document_id'=>['required','integer'],
            'base_revision'=>['required','integer','min:0'],
            'text'=>['required','string','max:20000'],
            'selection'=>['required','array'],
            'selection.start'=>['required','array'],
            'selection.end'=>['required','array'],
            'selection.start.blockId'=>['required','string','max:160'],
            'selection.end.blockId'=>['required','string','max:160'],
            'selection.start.offset'=>['required','integer','min:0'],
            'selection.end.offset'=>['required','integer','min:0'],
            'selection.start.itemId'=>['nullable','string','max:160'],
            'selection.end.itemId'=>['nullable','string','max:160'],
            'selection.start.cellId'=>['nullable','string','max:160'],
            'selection.end.cellId'=>['nullable','string','max:160'],
            'idempotency_key'=>['nullable','string','max:180'],
        ]);
        $legacy=TypingDocument::whereKey((int)$data['document_id'])->where('user_id',$request->user()->id)->firstOrFail();
        abort_unless($legacy->farast_document_id,404);
        $document=$legacy->farastDocument()->where('user_id',$request->user()->id)->firstOrFail();
        // Voice is allowed to reconcile a stale source revision through the kernel's semantic anchor rebase.
        try{
            $result=$tools->execute($request->user(),'editor.kernel',[
                'document_id'=>(int)$document->id,
                'base_revision'=>(int)$data['base_revision'],
                'command'=>['name'=>'InsertText','input'=>['text'=>$data['text'],'range'=>$data['selection'],'anchor'=>$data['anchor']??null]],
            ],[
                'application'=>'word_processor',
                'source'=>'voice',
                'project_id'=>$document->project_id,
                'document_id'=>$document->id,
                'idempotency_key'=>$data['idempotency_key']??('voice-kernel-'.$request->user()->id.'-'.$document->id.'-'.hash('sha256',$data['text'].'|'.$data['base_revision'].'|'.json_encode($data['selection'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))),
                'quantity'=>1,
                'correlation_id'=>(string)($request->header('X-Farast-Voice-Request') ?: ''),
            ]);
            return response()->json(['ok'=>true,'revision'=>$result['output']['revision']??null,'transaction_id'=>$result['output']['transaction_id']??null,'effects'=>$result['output']['effects']??[],'document_model'=>$result['output']['document_model']??null]);
        }catch(\Throwable $e){
            $code=preg_match('/^[a-z0-9_.-]{3,100}$/i',$e->getMessage())?$e->getMessage():'voice_kernel_failure';
            $status=in_array($code,['document_revision_conflict','revision_conflict'],true)?409:422;
            return response()->json(['ok'=>false,'error_code'=>$code,'message'=>'درج متن صوتی انجام نشد.'],$status);
        }
    }

    private function signStreamPayload(array $payload):string{$encoded=rtrim(strtr(base64_encode(json_encode($payload,JSON_UNESCAPED_SLASHES)),'+/','-_'),'=');return $encoded.'.'.hash_hmac('sha256',$encoded,(string)config('app.key'));}
    private function verifyStreamToken(string $token, bool $allowExpiredForUsage = false):?array{if($token===''||!str_contains($token,'.'))return null;[$payload,$signature]=array_pad(explode('.',$token,2),2,'');$expected=hash_hmac('sha256',$payload,(string)config('app.key'));if($signature===''||!hash_equals($expected,$signature))return null;$decoded=base64_decode(strtr($payload,'-_','+/').str_repeat('=',(4-strlen($payload)%4)%4),true);if($decoded===false)return null;$data=json_decode($decoded,true);if(!is_array($data)||empty($data['uid'])||empty($data['locale']))return null;$expires=(int)($data['exp']??0);if(!$allowExpiredForUsage&&$expires<time())return null;if($allowExpiredForUsage&&$expires<time()-86400)return null;return $data;}
    private function gatewaySignature(string $token):string{return hash_hmac('sha256',$token,(string)config('services.voice_stream.gateway_secret',''));}
}
