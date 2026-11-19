<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class StorePriceProposal extends Model
{
    protected $fillable = [
        'product_id','uploader_id','proposed_by','base_price_rials','campaign_price_rials',
        'cost_price_rials','minimum_margin_rials','source_hash','snapshot_before','snapshot_after',
        'status','decided_by','decided_at','decision_note',
    ];

    protected $casts = [
        'base_price_rials' => 'integer',
        'campaign_price_rials' => 'integer',
        'cost_price_rials' => 'integer',
        'minimum_margin_rials' => 'integer',
        'snapshot_before' => 'array',
        'snapshot_after' => 'array',
        'decided_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $proposal): void {
            $dirty = array_keys($proposal->getDirty());
            $allowed = ['status','decided_by','decided_at','decision_note','snapshot_after','updated_at'];
            if (array_diff($dirty, $allowed)) {
                throw new LogicException('store_price_proposal_snapshot_is_immutable');
            }

            if ($proposal->getOriginal('status') !== 'pending') {
                throw new LogicException('store_price_proposal_decision_is_immutable');
            }

            if (! in_array($proposal->status, ['accepted','rejected','stale','superseded'], true)) {
                throw new LogicException('store_price_proposal_transition_invalid');
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(StoreProduct::class, 'product_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploader_id');
    }

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
