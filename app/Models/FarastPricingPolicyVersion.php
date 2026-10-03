<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FarastPricingPolicyVersion extends Model
{
    protected $table = 'farast_pricing_policy_versions';
    protected $fillable = ['policy_code','version','capability_code','unit','currency','unit_price','additional_unit_price','fee_amount','discount_basis_points','tax_basis_points','payg_multiplier_basis_points','included_quantity','region_code','effective_from','effective_until','metadata','checksum'];
    protected function casts(): array { return ['version'=>'integer','unit_price'=>'integer','additional_unit_price'=>'integer','fee_amount'=>'integer','discount_basis_points'=>'integer','tax_basis_points'=>'integer','payg_multiplier_basis_points'=>'integer','included_quantity'=>'decimal:6','effective_from'=>'datetime','effective_until'=>'datetime','metadata'=>'array']; }
    protected static function booted(): void
    {
        static::updating(function (self $model) {
            $dirty=array_keys($model->getDirty());
            if (array_diff($dirty,['effective_from','effective_until','updated_at'])) throw new \LogicException('pricing_policy_version_is_immutable');
        });
        static::deleting(function () { throw new \LogicException('pricing_policy_version_is_immutable'); });
    }
}
