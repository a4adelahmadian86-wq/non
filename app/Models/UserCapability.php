<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserCapability extends Model
{
    protected $fillable = ['user_id','active','can_type','can_ai','can_voice','can_export_docx','can_export_pdf','can_feedback','can_support','weekly_free_pages','max_file_mb','daily_ai_requests'];
    protected $casts = ['active'=>'boolean','can_type'=>'boolean','can_ai'=>'boolean','can_voice'=>'boolean','can_export_docx'=>'boolean','can_export_pdf'=>'boolean','can_feedback'=>'boolean','can_support'=>'boolean'];
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
