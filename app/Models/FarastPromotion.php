<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FarastPromotion extends Model
{
    protected $table='farast_promotions';
    protected $fillable=['code','name','discount_type','discount_value','max_discount','capability_code','starts_at','ends_at','usage_limit','active','metadata'];
    protected function casts():array{return ['discount_value'=>'integer','max_discount'=>'integer','usage_limit'=>'integer','starts_at'=>'datetime','ends_at'=>'datetime','active'=>'boolean','metadata'=>'array'];}
}