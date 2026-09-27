<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class EditorSession extends Model {
    protected $fillable=['user_id','token','last_seen_at','expires_at'];
    protected function casts(): array { return ['last_seen_at'=>'datetime','expires_at'=>'datetime']; }
}
