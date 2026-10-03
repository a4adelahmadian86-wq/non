<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FarastSubscription
{
    protected $table = 'farast_subscriptions';
    protected $fillable = ['subscription_id','user_id','organization_id','plan_id','status','starts_at','ends_at','cancelled_at','metadata'];
    protected function casts(): array { return ['starts_at'=>'datetime','ends_at'=>'datetime','cancelled_at'=>'datetime','metadata'=>'array']; }
}
