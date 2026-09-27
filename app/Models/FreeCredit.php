<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FreeCredit extends Model
{
    protected $fillable = ['mobile_hash','user_id','week_start','granted_pages','consumed_pages'];
    protected $casts = ['week_start'=>'date','granted_pages'=>'integer','consumed_pages'=>'integer'];
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function remaining(): int { return max(0, $this->granted_pages - $this->consumed_pages); }
}
