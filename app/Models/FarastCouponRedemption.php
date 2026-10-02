<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FarastCouponRedemption extends Model
{
    protected $table='farast_coupon_redemptions';
    protected $fillable=['coupon_code','actor_id','charge_id','idempotency_key','discount_amount','currency'];
    protected function casts():array{return ['discount_amount'=>'integer'];}
}