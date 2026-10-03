<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FarastCommercialProduct extends Model
{
    protected $table='farast_commercial_products';
    protected $fillable=['code','name','capability_code','status','currency','metadata'];
    protected function casts():array{return ['metadata'=>'array'];}
}
