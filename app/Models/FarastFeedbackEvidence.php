<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class FarastFeedbackEvidence extends Model { protected $table='farast_feedback_evidence'; protected $fillable=['feedback_id','user_id','project_id','source','tool_id','tool_version_id','evidence_type','input_meta','output_meta','confidence','frequency','independent_confirmations','status','reviewer_notes','checksum']; protected function casts():array{return ['input_meta'=>'array','output_meta'=>'array','confidence'=>'float','frequency'=>'integer','independent_confirmations'=>'integer'];} }