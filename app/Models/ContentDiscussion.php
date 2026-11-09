<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class ContentDiscussion extends Model {
 protected $fillable=['target_type','target_key','kind','parent_id','user_id','title','body','rating','status','moderator_id','moderated_at','anchor_page','anchor_quote','anchor_start','anchor_end','anchor_context'];
 protected $casts=['rating'=>'integer','moderated_at'=>'datetime','anchor_page'=>'integer','anchor_start'=>'integer','anchor_end'=>'integer'];
 public function moderationEvents(): HasMany { return $this->hasMany(DiscussionModerationEvent::class,'discussion_id')->latest(); }
 public function reports(): HasMany { return $this->hasMany(DiscussionReport::class, 'discussion_id'); }
 public function reactions(): HasMany { return $this->hasMany(DiscussionReaction::class, 'discussion_id'); }
 public function user(): BelongsTo { return $this->belongsTo(User::class); }
 public function moderator(): BelongsTo { return $this->belongsTo(User::class,'moderator_id'); }
 public function parent(): BelongsTo { return $this->belongsTo(self::class,'parent_id'); }
 public function replies(): HasMany { return $this->hasMany(self::class,'parent_id'); }
 public function subscriptions(): HasMany { return $this->hasMany(DiscussionSubscription::class,'discussion_id'); }
}