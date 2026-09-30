<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class FarastEvaluationDataset extends Model { protected $table='farast_evaluation_datasets'; protected $fillable=['code','version','status','metadata']; protected function casts():array{return ['metadata'=>'array'];} }