<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FarastCommercialPlan extends Model
{
    protected $table='farast_commercial_plans';
    protected $fillable=['code','name','product_code','billing_interval','price','currency','quotas','allow_payg','allow_overage','postpaid','active','metadata'];
    protected function casts():array{return ['price'=>'integer','quotas'=>'array','allow_payg'=>'boolean','allow_overage'=>'boolean','postpaid'=>'boolean','active'=>'boolean','metadata'=>'array'];}
}
