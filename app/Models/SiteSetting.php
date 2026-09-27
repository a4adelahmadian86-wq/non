<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class SiteSetting extends Model
{
    protected $fillable = ['key', 'value', 'is_secret'];
    protected $casts = ['is_secret' => 'boolean'];

    public function getDecodedValueAttribute(): ?string
    {
        if ($this->value === null) return null;
        if (!$this->is_secret) return $this->value;
        try { return Crypt::decryptString($this->value); } catch (\Throwable) { return null; }
    }

    public static function read(string $key, mixed $default = null): mixed
    {
        $row = static::where('key', $key)->first();
        if (!$row) return $default;
        return $row->decoded_value ?? $default;
    }

    public static function write(string $key, mixed $value, bool $secret = false): void
    {
        $encoded = $secret ? Crypt::encryptString((string) $value) : (string) $value;
        static::updateOrCreate(['key' => $key], ['value' => $encoded, 'is_secret' => $secret]);
    }
}
