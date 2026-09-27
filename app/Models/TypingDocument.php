<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class TypingDocument extends Model {
    protected $fillable=['user_id','title','content','source_path','source_hash','page_count','word_count','language_mix','status','price_rials','expires_at'];
    protected function casts(): array { return ['expires_at'=>'datetime','price_rials'=>'integer','page_count'=>'integer','word_count'=>'integer']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
