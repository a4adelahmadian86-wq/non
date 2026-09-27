<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailOtpChallenge extends Model
{
    protected $fillable = ['email', 'code_hash', 'attempts', 'expires_at', 'consumed_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'consumed_at' => 'datetime', 'attempts' => 'integer'];
    }
}
