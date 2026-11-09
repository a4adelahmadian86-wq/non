<?php
namespace App\\Models;
use Illuminate\\Database\\Eloquent\\Model;
use Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;
class DiscussionModerationEvent extends Model {
 public $timestamps=false;
 protected $fillable=['discussion_id','moderator_id','from_status','to_status','note','created_at'];
 protected $casts=['created_at'=>'datetime'];
 public function discussion(): BelongsTo { return $this->belongsTo(ContentDiscussion::class,'discussion_id'); }
 public function moderator(): BelongsTo { return $this->belongsTo(User::class,'moderator_id'); }
}