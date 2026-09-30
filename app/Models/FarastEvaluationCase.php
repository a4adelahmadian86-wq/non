<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class FarastEvaluationCase extends Model {
 protected $table='farast_evaluation_cases';
 protected $fillable=['dataset_id','tool_id','tool_version_id','input_payload','expected_payload','status','score','metadata'];
 protected function casts():array{return ['input_payload'=>'array','expected_payload'=>'array','score'=>'float','metadata'=>'array'];}
}