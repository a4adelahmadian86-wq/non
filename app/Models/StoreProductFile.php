<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreProductFile extends Model
{
    protected $fillable = [
        'product_id','course_lesson_id','purpose','version','disk','path','original_name','mime',
        'size_bytes','sha256','is_primary','is_active','scan_status','scan_engine','scanned_at',
    ];
    protected $casts = [
        'size_bytes'=>'integer','is_primary'=>'boolean','is_active'=>'boolean','scanned_at'=>'datetime',
    ];

    public function product(): BelongsTo { return $this->belongsTo(StoreProduct::class, 'product_id'); }
    public function lesson(): BelongsTo { return $this->belongsTo(StoreCourseLesson::class, 'course_lesson_id'); }
}
