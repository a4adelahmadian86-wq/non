<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserKnowledge extends Model
{
    protected $table = 'farast_user_knowledge';

    protected $fillable = ['user_id','key','value','source','status','confidence','scope','expires_at'];

    protected function casts(): array
    {
        return ['value' => 'array','confidence' => 'float','expires_at' => 'datetime'];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
