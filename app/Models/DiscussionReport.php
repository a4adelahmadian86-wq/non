<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class DiscussionReport extends Model {
 protected $fillable=['discussion_id','reporter_id','reason','details','status','reviewed_by','reviewed_at'];
 protected $casts=['reviewed_at'=>'datetime'];
 public function discussion(): BelongsTo { return $this->belongsTo(ContentDiscussion::class,'discussion_id'); }
 public function reporter(): BelongsTo { return $this->belongsTo(User::class,'reporter_id'); }
 public function reviewer(): BelongsTo { return $this->belongsTo(User::class,'reviewed_by'); }
}
