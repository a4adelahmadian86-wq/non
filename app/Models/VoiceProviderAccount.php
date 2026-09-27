<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class VoiceProviderAccount extends Model
{
    protected $fillable = [
        'provider','name','model','credentials','capabilities','billing_mode',
        'quota_limit_seconds','quota_used_seconds','quota_period_started_at',
        'quota_period_ends_at','credit_remaining','credit_expires_at','quality_score',
        'reliability_score','priority','consecutive_failures','cooldown_until',
        'last_success_at','last_failure_at','last_latency_ms','enabled','healthy','metadata',
    ];

    protected $casts = [
        'capabilities' => 'array',
        'metadata' => 'array',
        'enabled' => 'boolean',
        'healthy' => 'boolean',
        'quota_period_started_at' => 'datetime',
        'quota_period_ends_at' => 'datetime',
        'credit_expires_at' => 'datetime',
        'cooldown_until' => 'datetime',
        'last_success_at' => 'datetime',
        'last_failure_at' => 'datetime',
        'credit_remaining' => 'decimal:4',
    ];

    protected $hidden = ['credentials'];

    public function getCredentialsArrayAttribute(): array
    {
        if (!$this->credentials) return [];
        try {
            $value = Crypt::decryptString($this->credentials);
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function setCredentialsArrayAttribute(array $value): void
    {
        $this->attributes['credentials'] = Crypt::encryptString(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function usage(): HasMany
    {
        return $this->hasMany(VoiceProviderUsage::class);
    }

    public function remainingSeconds(): ?int
    {
        if ($this->quota_limit_seconds === null) return null;
        return max(0, (int)$this->quota_limit_seconds - (int)$this->quota_used_seconds);
    }

    public function isAvailable(): bool
    {
        if (!$this->enabled || !$this->healthy) return false;
        if ($this->cooldown_until && $this->cooldown_until->isFuture()) return false;
        if ($this->quota_limit_seconds !== null && $this->remainingSeconds() <= 0) return false;
        if ($this->credit_expires_at && $this->credit_expires_at->isPast() && $this->billing_mode === 'trial') return false;
        return true;
    }
}
