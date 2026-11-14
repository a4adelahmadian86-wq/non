<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

class Order extends Model
{
    protected static function booted(): void
    {
        static::creating(function (Order $order): void {
            if (blank($order->idempotency_key)) {
                $order->idempotency_key = (string) Str::uuid();
            }
        });

        static::updating(function (Order $order): void {
            if ($order->isDirty(['user_id', 'document_id', 'idempotency_key'])) {
                throw new LogicException('order_identity_is_immutable');
            }

            $wasPaid = $order->getOriginal('paid_at') !== null
                || in_array($order->getOriginal('status'), ['paid', 'deposit_paid'], true);

            if (! $wasPaid) {
                return;
            }

            $immutableFields = [
                'subtotal_rials',
                'discount_rials',
                'tax_rials',
                'total_rials',
                'free_pages_applied',
                'terms_accepted_at',
                'paid_at',
                'content_hash',
            ];

            if ($order->isDirty($immutableFields)) {
                throw new LogicException('paid_order_financial_snapshot_is_immutable');
            }

            $originalSnapshot = (array) ($order->getOriginal('pricing_snapshot') ?? []);
            $currentSnapshot = (array) ($order->pricing_snapshot ?? []);
            foreach (['coupon_redemption_pending', 'coupon_redeemed_at'] as $mutableCouponField) {
                unset($originalSnapshot[$mutableCouponField], $currentSnapshot[$mutableCouponField]);
            }

            if ($originalSnapshot !== $currentSnapshot) {
                throw new LogicException('paid_order_pricing_snapshot_is_immutable');
            }
        });
    }

    protected $fillable = [
        'user_id','document_id','subtotal_rials','discount_rials','tax_rials','total_rials','status',
        'pricing_snapshot','free_pages_applied','terms_accepted_at','paid_at','content_hash','idempotency_key',
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
