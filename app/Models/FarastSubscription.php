<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FarastSubscription extends Model
{
    protected $table='farast_subscriptions';
    protected $fillable=['user_id','plan_id','status','starts_at','ends_at'];
    protected function casts():array{return ['starts_at'=>'datetime','ends_at'=>'datetime'];}
}
