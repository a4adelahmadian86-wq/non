<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreCourse extends Model
{
    protected $fillable = [
        'product_id', 'subtitle', 'instructor_name', 'level', 'language',
        'duration_minutes', 'access_duration_days', 'certificate_min_completion_percent',
        'certificate_enabled', 'drip_enabled', 'settings',
    ];

    protected $casts = [
        'duration_minutes' => 'integer',
        'access_duration_days' => 'integer',
        'certificate_min_completion_percent' => 'integer',
        'certificate_enabled' => 'boolean',
        'drip_enabled' => 'boolean',
        'settings' => 'array',
    ];

    public function product(): BelongsTo { return $this->belongsTo(StoreProduct::class, 'product_id'); }
    public function sections(): HasMany { return $this->hasMany(StoreCourseSection::class, 'course_id')->orderBy('sort_order')->orderBy('id'); }
}
