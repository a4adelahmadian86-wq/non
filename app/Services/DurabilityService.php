<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class DurabilityService
{
    private array $tables=['farast_documents','farast_document_versions','farast_usage_events','farast_audit_events','farast_feedback_evidence','farast_evaluation_cases'];

    public function backup(string $scope='platform'):array
    {
        $backupId=(string)Str::uuid();$started=now();$disk=Storage::disk('farast_remote');$path='backups/'.$backupId.'.json';
        DB::table('farast_backup_manifests')->insert(['backup_id'=>$backupId,'scope'=>$scope,'status'=>'started','disk'=>'farast_remote','path'=>$path,'started_at'=>$started,'created_at'=>$started,'updated_at'=>$started]);
        $snapshot=['schema'=>1,'backup_id'=>$backupId,'scope'=>$scope,'created_at'=>$started->toIso8601String(),'tables'=>[]];
        foreach($this->tables as $table){if(!DB::getSchemaBuilder()->hasTable($table))continue;$snapshot['tables'][$table]=DB::table($table)->orderBy('id')->get()->map(fn($r)=>(array)$r)->all();}
        $json=json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$checksum=hash('sha256',$json);
        if(!$disk->put($path,$json))throw new RuntimeException('backup_write_failed');
        DB::table('farast_backup_manifests')->where('backup_id',$backupId)->update(['status'=>'completed','checksum'=>$checksum,'bytes'=>strlen($json),'metadata'=>json_encode(['tables'=>array_keys($snapshot['tables'])]),'completed_at'=>now(),'updated_at'=>now()]);
        return ['backup_id'=>$backupId,'path'=>$path,'checksum'=>$checksum,'bytes'=>strlen($json),'tables'=>array_keys($snapshot['tables'])];
    }

    public function verifyRestore(string $backupId):array
    {
        $manifest=DB::table('farast_backup_manifests')->where('backup_id',$backupId)->first();if(!$manifest)throw new RuntimeException('backup_not_found');
        $raw=Storage::disk($manifest->disk)->get($manifest->path);$checksum=hash('sha256',$raw);$snapshot=json_decode($raw,true);
        $checks=['object_checksum'=>hash_equals((string)$manifest->checksum,$checksum),'json_valid'=>is_array($snapshot),'tables'=>[]];
        if(!$checks['object_checksum']||!$checks['json_valid'])$status='failed';else{
            foreach(($snapshot['tables']??[]) as $table=>$rows){
                $exists=DB::getSchemaBuilder()->hasTable($table);$count=$exists?(int)DB::table($table)->count():0;
                $checks['tables'][$table]=['exists'=>$exists,'backup_rows'=>count($rows),'current_rows'=>$count];
            }
            $status=collect($checks['tables'])->every(fn($x)=>$x['exists']&&$x['current_rows']>=$x['backup_rows'])?'verified':'failed';
        }
        $id=(string)Str::uuid();DB::table('farast_restore_verifications')->insert(['verification_id'=>$id,'backup_manifest_id'=>$manifest->id,'status'=>$status,'checks'=>json_encode($checks,JSON_UNESCAPED_UNICODE),'notes'=>$status==='verified'?'Logical restore verification passed; isolated-destination restore remains an operational step.':'Restore verification failed.','verified_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
        return ['verification_id'=>$id,'status'=>$status,'checks'=>$checks];
    }
}