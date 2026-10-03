<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FarastPriceQuote extends Model
{
    protected $table='farast_price_quotes';
    protected $fillable=['quote_id','idempotency_key','actor_id','organization_id','capability','quantity','unit','currency','pricing_policy_version_id','subtotal','discount','fee','tax','total','status','expires_at','snapshot'];
    protected function casts():array{return ['quantity'=>'decimal:6','subtotal'=>'integer','discount'=>'integer','fee'=>'integer','tax'=>'integer','total'=>'integer','expires_at'=>'datetime','snapshot'=>'array'];}
}
