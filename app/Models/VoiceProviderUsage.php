<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoiceProviderUsage extends Model
{
    protected $fillable = [
        'voice_provider_account_id','usage_date','audio_seconds','requests',
        'successful_requests','failed_requests','input_bytes','output_bytes',
    ];

    protected $casts = ['usage_date' => 'date'];

    public function providerAccount(): BelongsTo
    {
        return $this->belongsTo(VoiceProviderAccount::class, 'voice_provider_account_id');
    }
}
