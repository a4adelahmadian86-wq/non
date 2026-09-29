<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
class AuditLogService {
 public function record(?int $userId,string $action,?string $ip,array $meta=[]):void{DB::table('audit_logs')->insert(['user_id'=>$userId,'action'=>$action,'ip'=>$ip,'meta'=>json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'created_at'=>now(),'updated_at'=>now()]);}
}
