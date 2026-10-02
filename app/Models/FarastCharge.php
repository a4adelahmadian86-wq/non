<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FarastCharge extends Model
{
    protected $table = 'farast_charges';
    protected $fillable = [
        'charge_id','idempotency_key','actor_id','organization_id','usage_event_id','reservation_id',
        'order_id','invoice_id','capability','quantity','unit','unit_price','currency','subtotal',
        'discount','fee','tax','total','status','pricing_policy_version_id','snapshot',
    ];
    protected function casts(): array
    {
        return ['quantity'=>'decimal:6','unit_price'=>'integer','subtotal'=>'integer','discount'=>'integer','fee'=>'integer','tax'=>'integer','total'=>'integer','snapshot'=>'array'];
    }
    protected static function booted(): void
    {
        static::updating(function (self $model) {
            $allowed = ['invoice_id','status'];
            $changed = array_keys($model->getDirty());
            if (array_diff($changed, $allowed)) throw new \LogicException('charge_financial_snapshot_is_immutable');
        });
        static::deleting(function () { throw new \LogicException('charge_is_immutable'); });
    }

}