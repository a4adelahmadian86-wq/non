<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
class TypingDocument extends Model {
    protected $fillable=['user_id','project_id','farast_document_id','title','content','source_path','source_hash','page_count','word_count','language_mix','status','price_rials','expires_at'];
    protected function casts(): array { return ['expires_at'=>'datetime','price_rials'=>'integer','page_count'=>'integer','word_count'=>'integer','language_mix'=>'array']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function farastDocument(): HasOne { return $this->hasOne(FarastDocument::class, 'id', 'farast_document_id'); }
}
