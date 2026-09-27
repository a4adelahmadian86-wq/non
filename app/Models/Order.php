<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'user_id','document_id','subtotal_rials','discount_rials','tax_rials','total_rials','status',
        'pricing_snapshot','free_pages_applied','terms_accepted_at','paid_at','content_hash',
    ];

    protected $casts = [
        'pricing_snapshot'=>'array','free_pages_applied'=>'integer','subtotal_rials'=>'integer',
        'discount_rials'=>'integer','tax_rials'=>'integer','total_rials'=>'integer',
        'terms_accepted_at'=>'datetime','paid_at'=>'datetime',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function document(): BelongsTo { return $this->belongsTo(TypingDocument::class, 'document_id'); }
    public function payments(): HasMany { return $this->hasMany(Payment::class); }
    public function storeItems(): HasMany { return $this->hasMany(StoreOrderItem::class, 'order_id'); }
    public function isPaid(): bool { return $this->status === 'paid' && $this->paid_at !== null; }
    public function isDeposit(): bool { return ($this->pricing_snapshot['kind'] ?? null) === 'typing_deposit'; }
}
