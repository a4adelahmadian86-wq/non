<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FarastEntitlement extends Model
{
    protected $table = 'farast_entitlements';

    protected $fillable = [
        'user_id','organization_id','project_id','capability_code','mode','status','quantity',
        'used_quantity','unit','priority','source_type','source_id','starts_at','ends_at','metadata',
    ];

    protected function casts(): array
    {
        return [
            'quantity'=>'integer','used_quantity'=>'integer','priority'=>'integer',
            'starts_at'=>'datetime','ends_at'=>'datetime','metadata'=>'array',
        ];
    }
}