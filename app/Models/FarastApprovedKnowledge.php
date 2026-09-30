<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class FarastApprovedKnowledge extends Model { protected $table='farast_approved_knowledge'; protected $fillable=['review_case_id','capability_code','knowledge_version','content','status','approved_by','approved_at','checksum']; protected function casts():array{return ['content'=>'array','approved_at'=>'datetime'];} }