<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class ContentDiscussion extends Model {
 protected $fillable=['target_type','target_key','kind','parent_id','user_id','title','body','rating','status','moderator_id','moderated_at'];
 protected $casts=['rating'=>'integer','moderated_at'=>'datetime'];
 public function user(): BelongsTo { return $this->belongsTo(User::class); }
 public function moderator(): BelongsTo { return $this->belongsTo(User::class,'moderator_id'); }
 public function parent(): BelongsTo { return $this->belongsTo(self::class,'parent_id'); }
 public function replies(): HasMany { return $this->hasMany(self::class,'parent_id'); }
}