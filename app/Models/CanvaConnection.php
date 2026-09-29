<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
class CanvaConnection extends Model {
 protected $fillable=['user_id','access_token','refresh_token','expires_at','scopes','canva_user_id','status','last_checked_at','metadata'];
 protected $hidden=['access_token','refresh_token'];
 protected $casts=['expires_at'=>'datetime','last_checked_at'=>'datetime','scopes'=>'array','metadata'=>'array'];
 public function setAccessTokenAttribute($v){$this->attributes['access_token']=$v?Crypt::encryptString($v):null;}
 public function getAccessTokenAttribute($v){if(!$v)return null;try{return Crypt::decryptString($v);}catch(\Throwable){return null;}}
 public function setRefreshTokenAttribute($v){$this->attributes['refresh_token']=$v?Crypt::encryptString($v):null;}
 public function getRefreshTokenAttribute($v){if(!$v)return null;try{return Crypt::decryptString($v);}catch(\Throwable){return null;}}
}
