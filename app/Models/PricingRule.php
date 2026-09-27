<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PricingRule extends Model {
    protected $fillable=['key','value','label','active'];
    protected function casts(): array { return ['value'=>'integer','active'=>'boolean']; }
}
