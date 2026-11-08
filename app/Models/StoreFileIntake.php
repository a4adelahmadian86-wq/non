<?php

namespace App\\Models;

use Illuminate\\Database\\Eloquent\\Model;
use Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;

class StoreFileIntake extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['id','product_id','user_id','disk','path','original_name','mime','extension','size_bytes','sha256','status','inspection_json','confirmed_file_id','expires_at'];
    protected $casts = ['size_bytes'=>'integer','inspection_json'=>'array','expires_at'=>'datetime'];
    public function product(): BelongsTo { return $this->belongsTo(StoreProduct::class, 'product_id'); }
    public function confirmedFile(): BelongsTo { return $this->belongsTo(StoreProductFile::class, 'confirmed_file_id'); }
}
