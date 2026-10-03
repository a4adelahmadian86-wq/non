<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up():void
    {
        $this->alterRegistry(); $this->createTables(); $this->backfillStableIds(); $this->seedContracts();
    }
    private function alterRegistry():void
    {
        if(Schema::hasTable('farast_applications'))Schema::table('farast_applications',function(Blueprint $t){
            if(!Schema::hasColumn('farast_applications','stable_id'))$t->uuid('stable_id')->nullable();
            foreach(['workflow','project_types','tools','capabilities','templates','agent_profile','export_formats','entitlement_requirements'] as $c)if(!Schema::hasColumn('farast_applications',$c))$t->json($c)->nullable();
        });
        if(Schema::hasTable('farast_capabilities')&&!Schema::hasColumn('farast_capabilities','stable_id'))Schema::table('farast_capabilities',fn(Blueprint $t)=>$t->uuid('stable_id')->nullable());
        if(Schema::hasTable('farast_tools'))Schema::table('farast_tools',function(Blueprint $t){
            if(!Schema::hasColumn('farast_tools','stable_id'))$t->uuid('stable_id')->nullable();
            foreach(['input_schema','output_schema','permissions','entitlement_policy','provenance'] as $c)if(!Schema::hasColumn('farast_tools',$c))$t->json($c)->nullable();
            foreach(['reversible','transactional','audit_enabled'] as $c)if(!Schema::hasColumn('farast_tools',$c))$t->boolean($c)->default(true);
            if(!Schema::hasColumn('farast_tools','usage_meter'))$t->string('usage_meter',80)->nullable();
            if(!Schema::hasColumn('farast_tools','timeout_seconds'))$t->unsignedInteger('timeout_seconds')->default(120);
            if(!Schema::hasColumn('farast_tools','retry_count'))$t->unsignedInteger('retry_count')->default(0);
        });
        foreach(['farast_tool_versions','farast_entitlements','farast_feedback_evidence','farast_evaluation_datasets','farast_evaluation_cases','farast_approved_knowledge','farast_usage_events','farast_usage_reservations'] as $table)
            if(Schema::hasTable($table)&&!Schema::hasColumn($table,'stable_id'))Schema::table($table,fn(Blueprint $t)=>$t->uuid('stable_id')->nullable());
    }
    private function createTables():void
    {
        if(!Schema::hasTable('farast_tool_executions'))Schema::create('farast_tool_executions',function(Blueprint $t){
            $t->id();$t->uuid('execution_id')->unique();$t->foreignId('tool_id')->constrained('farast_tools');$t->foreignId('tool_version_id')->constrained('farast_tool_versions');
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();$t->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();$t->foreignId('project_id')->nullable()->constrained('farast_projects')->nullOnDelete();$t->foreignId('document_id')->nullable()->constrained('farast_documents')->nullOnDelete();
            $t->string('capability',100);$t->string('status',30)->default('pending');$t->string('idempotency_key',160)->unique();$t->uuid('correlation_id')->nullable();$t->uuid('request_id')->nullable();$t->uuid('transaction_id')->nullable();
            $t->json('input_meta')->nullable();$t->json('output_meta')->nullable();$t->json('provenance')->nullable();$t->string('output_checksum',64)->nullable();$t->string('error_code',100)->nullable();$t->text('error_message')->nullable();$t->unsignedBigInteger('usage_event_id')->nullable();$t->unsignedBigInteger('audit_event_id')->nullable();$t->timestamp('started_at')->nullable();$t->timestamp('finished_at')->nullable();$t->timestamps();
            $t->index(['tool_id','status','created_at']);$t->index(['actor_id','created_at']);$t->index('correlation_id');
        });
        if(!Schema::hasTable('farast_human_tasks'))Schema::create('farast_human_tasks',function(Blueprint $t){
            $t->id();$t->uuid('task_id')->unique();$t->string('capability',100);$t->foreignId('project_id')->nullable()->constrained('farast_projects')->nullOnDelete();$t->foreignId('requester_id')->nullable()->constrained('users')->nullOnDelete();$t->foreignId('specialist_id')->nullable()->constrained('users')->nullOnDelete();
            $t->json('sla')->nullable();$t->json('input')->nullable();$t->json('output')->nullable();$t->json('qa')->nullable();$t->json('acceptance')->nullable();$t->json('billing')->nullable();$t->json('provenance')->nullable();$t->string('status',30)->default('queued');$t->uuid('correlation_id')->nullable();$t->timestamps();$t->index(['capability','status','created_at']);
        });
        if(!Schema::hasTable('farast_storage_objects'))Schema::create('farast_storage_objects',function(Blueprint $t){
            $t->id();$t->uuid('object_id')->unique();$t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();$t->foreignId('project_id')->nullable()->constrained('farast_projects')->nullOnDelete();$t->foreignId('document_id')->nullable()->constrained('farast_documents')->nullOnDelete();$t->string('disk',60);$t->string('path',1000);$t->string('mime',160)->nullable();$t->unsignedBigInteger('size')->default(0);$t->string('checksum',64);$t->string('status',30)->default('private');$t->string('classification',40)->default('user_content');$t->unsignedInteger('version')->default(1);$t->unsignedInteger('revision')->default(1);$t->json('provenance')->nullable();$t->timestamps();$t->unique(['disk','path']);
        });
        if(!Schema::hasTable('farast_backup_manifests'))Schema::create('farast_backup_manifests',function(Blueprint $t){
            $t->id();$t->uuid('backup_id')->unique();$t->string('scope',80);$t->string('status',30)->default('started');$t->string('disk',60);$t->string('path',1000);$t->string('checksum',64)->nullable();$t->unsignedBigInteger('bytes')->default(0);$t->json('metadata')->nullable();$t->timestamp('started_at')->nullable();$t->timestamp('completed_at')->nullable();$t->timestamps();
        });
        if(!Schema::hasTable('farast_restore_verifications'))Schema::create('farast_restore_verifications',function(Blueprint $t){
            $t->id();$t->uuid('verification_id')->unique();$t->foreignId('backup_manifest_id')->constrained('farast_backup_manifests')->cascadeOnDelete();$t->string('status',30);$t->json('checks')->nullable();$t->text('notes')->nullable();$t->timestamp('verified_at')->nullable();$t->timestamps();
        });
        if(!Schema::hasTable('farast_templates'))Schema::create('farast_templates',function(Blueprint $t){
            $t->id();$t->uuid('template_id')->unique();$t->string('code',120)->unique();$t->foreignId('application_id')->nullable()->constrained('farast_applications')->nullOnDelete();$t->string('project_type',80)->nullable();$t->string('version',40)->default('1.0.0');$t->json('settings');$t->string('status',30)->default('active');$t->string('checksum',64);$t->json('provenance')->nullable();$t->timestamps();
        });
    }
    private function backfillStableIds():void
    {
        foreach(['farast_applications','farast_capabilities','farast_tools','farast_tool_versions','farast_entitlements','farast_feedback_evidence','farast_evaluation_datasets','farast_evaluation_cases','farast_approved_knowledge','farast_usage_events','farast_usage_reservations'] as $table){
            if(!Schema::hasTable($table)||!Schema::hasColumn($table,'stable_id'))continue;
            DB::table($table)->whereNull('stable_id')->orderBy('id')->cursor()->each(fn($row)=>DB::table($table)->where('id',$row->id)->update(['stable_id'=>(string)Str::uuid()]));
            try{Schema::table($table,fn(Blueprint $t)=>$t->unique('stable_id'));}catch(\Throwable){}
        }
    }
    private function seedContracts():void
    {
        $contracts=[
            'ai.editor'=>['capability'=>'ai.assistance','unit'=>'request','usage'=>'request','input'=>['type'=>'object','required'=>['operation','text'],'properties'=>['operation'=>['type'=>'string'],'text'=>['type'=>'string'],'project_id'=>['type'=>'integer']]],'output'=>['type'=>'object']],
            'ocr.document'=>['capability'=>'ocr','unit'=>'page','usage'=>'page','input'=>['type'=>'object','required'=>['mime','base64'],'properties'=>['mime'=>['type'=>'string'],'base64'=>['type'=>'string'],'source_name'=>['type'=>'string'],'source_hash'=>['type'=>'string']]],'output'=>['type'=>'object','required'=>['rejected','blocks']]],
            'ocr.handwriting'=>['capability'=>'handwriting.ocr','unit'=>'page','usage'=>'page','input'=>['type'=>'object','required'=>['mime','base64'],'properties'=>['mime'=>['type'=>'string'],'base64'=>['type'=>'string'],'source_name'=>['type'=>'string'],'source_hash'=>['type'=>'string']]],'output'=>['type'=>'object','required'=>['rejected','blocks']]],
            'speech.transcription'=>['capability'=>'speech.transcription','unit'=>'minute','usage'=>'minute','input'=>['type'=>'object','required'=>['mime','bytes_base64','locale'],'properties'=>['mime'=>['type'=>'string'],'bytes_base64'=>['type'=>'string'],'locale'=>['type'=>'string']]],'output'=>['type'=>'object','required'=>['text']]],
            'human.assistance'=>['capability'=>'human.assistance','unit'=>'operation','usage'=>'operation','input'=>['type'=>'object','required'=>['capability','input'],'properties'=>['capability'=>['type'=>'string'],'input'=>['type'=>'object'],'project_id'=>['type'=>'integer'],'sla'=>['type'=>'object']]],'output'=>['type'=>'object','required'=>['task_id','status']]],
        ];
        foreach($contracts as $code=>$c){
            $id=DB::table('farast_tools')->where('code',$code)->value('id');if(!$id)continue;
            DB::table('farast_tools')->where('id',$id)->update(['input_schema'=>json_encode($c['input'],JSON_UNESCAPED_UNICODE),'output_schema'=>json_encode($c['output'],JSON_UNESCAPED_UNICODE),'permissions'=>json_encode(['authenticated'=>true]),'entitlement_policy'=>json_encode(['capability'=>$c['capability'],'unit'=>$c['unit']]),'usage_meter'=>$c['usage'],'reversible'=>false,'transactional'=>true,'audit_enabled'=>true,'timeout_seconds'=>180,'retry_count'=>1,'provenance'=>json_encode(['source'=>'phase5','contract_version'=>'1.0.0']),'updated_at'=>now()]);
        }
        $apps=[
            'word_processor'=>['workflow'=>'project_to_document','project_types'=>['typing','editing','article','book','resume','exam','contract','forms','report','research_document'],'tools'=>['ai.editor','ocr.document','ocr.handwriting','speech.transcription','human.assistance'],'capabilities'=>['document.editing','ai.assistance','ocr','handwriting.ocr','speech.transcription','export.docx','export.pdf'],'templates'=>['simple_typing','academic_typing','official_document'],'agent_profile'=>'editor','exports'=>['docx','pdf']],
            'reader'=>['workflow'=>'ingest_to_read','project_types'=>['other'],'tools'=>['ai.editor','ocr.document','ocr.handwriting','human.assistance'],'capabilities'=>['document.intelligence','ocr','human.assistance'],'templates'=>[],'agent_profile'=>'reader','exports'=>['pdf']],
            'presentation'=>['workflow'=>'project_to_slides','project_types'=>['presentation'],'tools'=>['ai.editor','human.assistance'],'capabilities'=>['ai.assistance','human.assistance'],'templates'=>[],'agent_profile'=>'presentation','exports'=>['pdf']],
            'design'=>['workflow'=>'project_to_design','project_types'=>['design','poster_card'],'tools'=>['ai.editor','human.assistance'],'capabilities'=>['ai.assistance','human.assistance'],'templates'=>[],'agent_profile'=>'design','exports'=>['pdf']]
        ];
        foreach($apps as $code=>$d){$id=DB::table('farast_applications')->where('code',$code)->value('id');if(!$id)continue;DB::table('farast_applications')->where('id',$id)->update(['workflow'=>$d['workflow'],'project_types'=>json_encode($d['project_types']),'tools'=>json_encode($d['tools']),'capabilities'=>json_encode($d['capabilities']),'templates'=>json_encode($d['templates']),'agent_profile'=>$d['agent_profile'],'export_formats'=>json_encode($d['exports']),'entitlement_requirements'=>json_encode(array_map(fn($x)=>['capability'=>$x],$d['capabilities'])),'updated_at'=>now()]);}
        $templates=[
            'simple_typing'=>['project_type'=>'typing','settings'=>['paper'=>'A4','orientation'=>'portrait','margin_top'=>25,'margin_right'=>25,'margin_bottom'=>25,'margin_left'=>25,'direction'=>'rtl','font_family'=>'B Nazanin','font_size'=>16,'line_height'=>1.8,'page_border'=>true]],
            'academic_typing'=>['project_type'=>'research_document','settings'=>['paper'=>'A4','orientation'=>'portrait','margin_top'=>30,'margin_right'=>30,'margin_bottom'=>25,'margin_left'=>25,'direction'=>'rtl','font_family'=>'B Nazanin','font_size'=>16,'line_height'=>1.8,'page_border'=>false]],
            'official_document'=>['project_type'=>'contract','settings'=>['paper'=>'A4','orientation'=>'portrait','margin_top'=>25,'margin_right'=>25,'margin_bottom'=>25,'margin_left'=>25,'direction'=>'rtl','font_family'=>'B Nazanin','font_size'=>16,'line_height'=>1.8,'page_border'=>true]]
        ];
        $appId=DB::table('farast_applications')->where('code','word_processor')->value('id');
        foreach($templates as $code=>$t){if(DB::table('farast_templates')->where('code',$code)->exists())continue;$checksum=hash('sha256',json_encode($t['settings'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));DB::table('farast_templates')->insert(['template_id'=>(string)Str::uuid(),'code'=>$code,'application_id'=>$appId,'project_type'=>$t['project_type'],'version'=>'1.0.0','settings'=>json_encode($t['settings'],JSON_UNESCAPED_UNICODE),'checksum'=>$checksum,'provenance'=>json_encode(['source'=>'phase5']),'created_at'=>now(),'updated_at'=>now()]);}
    }
    public function down():void{foreach(['farast_restore_verifications','farast_backup_manifests','farast_storage_objects','farast_human_tasks','farast_tool_executions','farast_templates'] as $t)Schema::dropIfExists($t);}
};