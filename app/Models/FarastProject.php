<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FarastProject extends Model
{
    protected $table = 'farast_projects';

    protected $fillable = [
        'user_id','name','project_type','workflow','template_code','status',
        'context','billing_state','output_state','estimated_pages','used_pages',
        'estimated_price_rials','paid_rials','entitlement_snapshot','completed_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'billing_state' => 'array',
            'output_state' => 'array',
            'entitlement_snapshot' => 'array',
            'estimated_pages' => 'integer',
            'used_pages' => 'integer',
            'estimated_price_rials' => 'integer',
            'paid_rials' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function documents(): HasMany { return $this->hasMany(FarastDocument::class, 'project_id'); }
    public function typingDocuments(): HasMany { return $this->hasMany(TypingDocument::class, 'project_id'); }
}
