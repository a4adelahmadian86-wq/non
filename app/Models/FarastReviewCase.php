<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class FarastReviewCase extends Model { protected $table='farast_review_cases'; protected $fillable=['evidence_id','reviewer_id','decision','notes','status','reviewed_at','metadata']; protected function casts():array{return ['reviewed_at'=>'datetime','metadata'=>'array'];} }