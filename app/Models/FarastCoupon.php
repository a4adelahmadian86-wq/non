<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FarastCoupon extends Model
{
    protected $table='farast_coupons';
    protected $fillable=['code','promotion_code','discount_type','discount_value','max_discount','usage_limit','per_actor_limit','starts_at','ends_at','active','metadata'];
    protected function casts():array{return ['discount_value'=>'integer','max_discount'=>'integer','usage_limit'=>'integer','per_actor_limit'=>'integer','starts_at'=>'datetime','ends_at'=>'datetime','active'=>'boolean','metadata'=>'array'];}
}