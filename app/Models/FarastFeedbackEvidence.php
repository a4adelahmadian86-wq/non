<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class FarastFeedbackEvidence extends Model { protected $table='farast_feedback_evidence'; protected $fillable=['feedback_id','tool_id','tool_version_id','evidence_type','input_meta','output_meta','confidence','status','checksum']; protected function casts():array{return ['input_meta'=>'array','output_meta'=>'array','confidence'=>'float'];} }