<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class FarastAgentTask extends Model{
 protected $table='farast_agent_tasks';
 protected $fillable=['task_id','user_id','project_id','document_id','status','prompt','intent','plan','selected_tools','approval','preview','execution','base_revision','result_revision','idempotency_key','error_code','metadata'];
 protected function casts():array{return ['intent'=>'array','plan'=>'array','selected_tools'=>'array','approval'=>'array','preview'=>'array','execution'=>'array','metadata'=>'array','base_revision'=>'integer','result_revision'=>'integer'];}
 public function user():BelongsTo{return $this->belongsTo(User::class);}
 public function project():BelongsTo{return $this->belongsTo(FarastProject::class,'project_id');}
 public function document():BelongsTo{return $this->belongsTo(FarastDocument::class,'document_id');}
}