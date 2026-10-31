<?php

namespace Database\Seeders;

use App\Models\VoiceProviderAccount;
use Illuminate\Database\Seeder;

class SherpaOnnxVoiceProviderSeeder extends Seeder
{
    public function run(): void
    {
        $account = VoiceProviderAccount::query()->firstOrNew(['provider' => 'sherpa-onnx']);
        $isNewAccount = ! $account->exists;
        $account->name = 'Sherpa ONNX Persian (local)';
        $account->model = env('FARAST_VOICE_LOCAL_MODEL', 'nemotron-asr-streaming-farsi-1120ms-int8');
        $account->credentials_array = [];
        $account->capabilities = [
            'locales' => ['fa-IR'],
            'capabilities' => ['speech.stt', 'speech.streaming', 'speech.local'],
        ];
        $account->billing_mode = 'monthly_free';
        $account->quota_limit_seconds = null;
        if ($isNewAccount) $account->quota_used_seconds = 0;
        $account->quality_score = 85;
        $account->reliability_score = 50;
        $account->priority = 0;
        $account->enabled = true;
        $account->healthy = true;
        $account->metadata = [
            'local' => true,
            'license' => 'OpenMDW-1.1',
            'locale' => 'fa-IR',
        ];
        $account->save();
    }
}
