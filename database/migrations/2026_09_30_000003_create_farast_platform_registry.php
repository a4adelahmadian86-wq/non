<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
 public function up(): void {
  Schema::create('farast_applications',function(Blueprint $t){$t->id();$t->string('code',80)->unique();$t->string('name',160);$t->string('status',30)->default('active');$t->json('metadata')->nullable();$t->timestamps();});
  Schema::create('farast_capabilities',function(Blueprint $t){$t->id();$t->string('code',100)->unique();$t->string('name',160);$t->string('billing_mode',40)->default('included');$t->string('unit',40)->nullable();$t->string('status',30)->default('active');$t->json('metadata')->nullable();$t->timestamps();});
  Schema::create('farast_providers',function(Blueprint $t){$t->id();$t->string('code',80)->unique();$t->string('name',160);$t->string('kind',40);$t->string('status',30)->default('active');$t->json('metadata')->nullable();$t->timestamps();});
  Schema::create('farast_tools',function(Blueprint $t){$t->id();$t->string('code',100)->unique();$t->string('name',160);$t->foreignId('capability_id')->nullable()->constrained('farast_capabilities')->nullOnDelete();$t->foreignId('provider_id')->nullable()->constrained('farast_providers')->nullOnDelete();$t->string('status',30)->default('active');$t->string('risk_level',20)->default('low');$t->json('metadata')->nullable();$t->timestamps();$t->index(['capability_id','status']);});
  Schema::create('farast_tool_versions',function(Blueprint $t){$t->id();$t->foreignId('tool_id')->constrained('farast_tools')->cascadeOnDelete();$t->string('version',80);$t->string('status',30)->default('candidate');$t->string('evaluation_status',30)->default('pending');$t->string('review_status',30)->default('pending');$t->decimal('quality_score',6,3)->nullable();$t->json('configuration')->nullable();$t->timestamp('last_verified_at')->nullable();$t->timestamps();$t->unique(['tool_id','version']);});
  Schema::create('farast_tool_applications',function(Blueprint $t){$t->foreignId('tool_id')->constrained('farast_tools')->cascadeOnDelete();$t->foreignId('application_id')->constrained('farast_applications')->cascadeOnDelete();$t->primary(['tool_id','application_id']);});
  Schema::create('farast_entitlements',function(Blueprint $t){$t->id();$t->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();$t->foreignId('project_id')->nullable()->constrained('farast_projects')->cascadeOnDelete();$t->string('capability_code',100);$t->string('mode',40);$t->string('status',30)->default('active');$t->unsignedBigInteger('quantity')->nullable();$t->unsignedBigInteger('used_quantity')->default(0);$t->timestamp('starts_at')->nullable();$t->timestamp('ends_at')->nullable();$t->json('metadata')->nullable();$t->timestamps();$t->index(['capability_code','status']);$t->index(['user_id','project_id','capability_code']);});
  Schema::create('farast_pricing_policies',function(Blueprint $t){$t->id();$t->string('code',100)->unique();$t->string('capability_code',100)->nullable();$t->string('unit',40);$t->unsignedBigInteger('base_price_rials')->default(0);$t->unsignedBigInteger('subscription_allowance')->default(0);$t->unsignedBigInteger('additional_price_rials')->nullable();$t->unsignedInteger('payg_multiplier_percent')->default(100);$t->unsignedInteger('discount_percent')->default(0);$t->unsignedBigInteger('fee_rials')->default(0);$t->boolean('active')->default(true);$t->json('metadata')->nullable();$t->timestamps();$t->index(['capability_code','active']);});
  Schema::create('farast_feedback_evidence',function(Blueprint $t){$t->id();$t->foreignId('feedback_id')->nullable()->constrained('ai_feedback')->nullOnDelete();$t->foreignId('tool_id')->nullable()->constrained('farast_tools')->nullOnDelete();$t->foreignId('tool_version_id')->nullable()->constrained('farast_tool_versions')->nullOnDelete();$t->string('evidence_type',40)->default('user_correction');$t->json('input_meta')->nullable();$t->json('output_meta')->nullable();$t->decimal('confidence',6,3)->nullable();$t->string('status',30)->default('raw');$t->string('checksum',64)->nullable()->index();$t->timestamps();$t->index(['status','created_at']);});
  Schema::create('farast_review_cases',function(Blueprint $t){$t->id();$t->foreignId('evidence_id')->constrained('farast_feedback_evidence')->cascadeOnDelete();$t->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();$t->string('decision',30)->nullable();$t->text('notes')->nullable();$t->string('status',30)->default('queued');$t->timestamp('reviewed_at')->nullable();$t->json('metadata')->nullable();$t->timestamps();$t->index(['status','created_at']);});
  Schema::create('farast_approved_knowledge',function(Blueprint $t){$t->id();$t->foreignId('review_case_id')->constrained('farast_review_cases')->cascadeOnDelete();$t->string('capability_code',100);$t->string('knowledge_version',80);$t->json('content');$t->string('status',30)->default('approved');$t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();$t->timestamp('approved_at')->nullable();$t->string('checksum',64)->index();$t->timestamps();$t->unique(['capability_code','knowledge_version','checksum']);});
  Schema::create('farast_evaluation_datasets',function(Blueprint $t){$t->id();$t->string('code',120)->unique();$t->string('version',80);$t->string('status',30)->default('draft');$t->json('metadata')->nullable();$t->timestamps();});
  Schema::create('farast_evaluation_cases',function(Blueprint $t){$t->id();$t->foreignId('dataset_id')->constrained('farast_evaluation_datasets')->cascadeOnDelete();$t->foreignId('tool_id')->nullable()->constrained('farast_tools')->nullOnDelete();$t->foreignId('tool_version_id')->nullable()->constrained('farast_tool_versions')->nullOnDelete();$t->json('input_payload');$t->json('expected_payload');$t->string('status',30)->default('pending');$t->decimal('score',6,3)->nullable();$t->json('metadata')->nullable();$t->timestamps();$t->index(['dataset_id','status']);});

  $applications=[['code'=>'word_processor','name'=>'Word Processor'],['code'=>'reader','name'=>'Reader'],['code'=>'presentation','name'=>'Presentation Editor'],['code'=>'design','name'=>'Design Editor']];
  foreach($applications as $row) DB::table('farast_applications')->insert(array_merge($row,['status'=>'active','created_at'=>now(),'updated_at'=>now()]));
  $capabilities=[
   ['code'=>'document.editing','name'=>'ویرایش سند','billing_mode'=>'included','unit'=>'operation'],
   ['code'=>'ai.assistance','name'=>'دستیار هوش مصنوعی','billing_mode'=>'usage','unit'=>'request'],
   ['code'=>'ocr','name'=>'OCR','billing_mode'=>'usage','unit'=>'page'],
   ['code'=>'handwriting.ocr','name'=>'تشخیص دست‌نویس','billing_mode'=>'usage','unit'=>'page'],
   ['code'=>'speech.transcription','name'=>'تبدیل گفتار به متن','billing_mode'=>'usage','unit'=>'minute'],
   ['code'=>'translation','name'=>'ترجمه','billing_mode'=>'usage','unit'=>'request'],
   ['code'=>'document.intelligence','name'=>'هوشمندی سند','billing_mode'=>'usage','unit'=>'request'],
   ['code'=>'human.assistance','name'=>'مساعدت انسانی','billing_mode'=>'future','unit'=>'operation'],
   ['code'=>'feedback.submit','name'=>'ثبت بازخورد','billing_mode'=>'included','unit'=>'operation'],
   ['code'=>'export.docx','name'=>'خروجی DOCX','billing_mode'=>'usage','unit'=>'operation'],
   ['code'=>'export.pdf','name'=>'خروجی PDF','billing_mode'=>'usage','unit'=>'operation'],
  ];
  foreach($capabilities as $row) DB::table('farast_capabilities')->insert(array_merge($row,['status'=>'active','created_at'=>now(),'updated_at'=>now()]));
  $providers=[['code'=>'gemini','name'=>'Gemini','kind'=>'ai'],['code'=>'ocr_manager','name'=>'FARAST OCR Manager','kind'=>'ocr'],['code'=>'voice_router','name'=>'FARAST Voice Router','kind'=>'speech'],['code'=>'farast_core','name'=>'FARAST Core','kind'=>'internal'],['code'=>'human_service','name'=>'Human Service','kind'=>'human']];
  foreach($providers as $row) DB::table('farast_providers')->insert(array_merge($row,['status'=>'active','created_at'=>now(),'updated_at'=>now()]));
  $cap=function($code){return DB::table('farast_capabilities')->where('code',$code)->value('id');};
  $prov=function($code){return DB::table('farast_providers')->where('code',$code)->value('id');};
  $tools=[
   ['code'=>'ai.editor','name'=>'Shared AI Editor','capability_code'=>'ai.assistance','provider_code'=>'gemini','risk'=>'medium','version'=>'1.0.0'],
   ['code'=>'ocr.document','name'=>'Shared OCR','capability_code'=>'ocr','provider_code'=>'ocr_manager','risk'=>'medium','version'=>'1.0.0'],
   ['code'=>'ocr.handwriting','name'=>'Shared Handwriting OCR','capability_code'=>'handwriting.ocr','provider_code'=>'ocr_manager','risk'=>'high','version'=>'1.0.0'],
   ['code'=>'speech.transcription','name'=>'Shared Speech Transcription','capability_code'=>'speech.transcription','provider_code'=>'voice_router','risk'=>'medium','version'=>'1.0.0'],
   ['code'=>'human.assistance','name'=>'Human Assistance Boundary','capability_code'=>'human.assistance','provider_code'=>'human_service','risk'=>'high','version'=>'0.1.0'],
  ];
  foreach($tools as $row){$toolId=DB::table('farast_tools')->insertGetId(['code'=>$row['code'],'name'=>$row['name'],'capability_id'=>$cap($row['capability_code']),'provider_id'=>$prov($row['provider_code']),'status'=>'active','risk_level'=>$row['risk'],'metadata'=>json_encode(['implementation'=>'existing_service_boundary'],'JSON_UNESCAPED_UNICODE'),'created_at'=>now(),'updated_at'=>now()]);$versionId=DB::table('farast_tool_versions')->insertGetId(['tool_id'=>$toolId,'version'=>$row['version'],'status'=>$row['code']==='human.assistance'?'candidate':'production','evaluation_status'=>$row['code']==='human.assistance'?'pending':'approved','review_status'=>$row['code']==='human.assistance'?'pending':'approved','quality_score'=>$row['code']==='human.assistance'?null:1,'last_verified_at'=>$row['code']==='human.assistance'?null:now(),'created_at'=>now(),'updated_at'=>now()]);}
  $appIds=DB::table('farast_applications')->pluck('id','code');
  $toolIds=DB::table('farast_tools')->pluck('id','code');
  $links=[
   ['ai.editor',['word_processor','reader','presentation','design']],
   ['ocr.document',['word_processor','reader']],
   ['ocr.handwriting',['word_processor','reader']],
   ['speech.transcription',['word_processor']],
   ['human.assistance',['word_processor','reader','presentation','design']],
  ];
  foreach($links as [$tool,$apps]) foreach($apps as $app) DB::table('farast_tool_applications')->insert(['tool_id'=>$toolIds[$tool],'application_id'=>$appIds[$app]]);
  $unitMap=['typing_manual_page'=>'page','typing_voice_page'=>'page','typing_printed_page'=>'page','typing_handwritten_page'=>'page','typing_mixed_page'=>'page','typing_voice_minute'=>'minute','page_base'=>'page','formula_unit'=>'operation','english_multiplier'=>'page','arabic_multiplier'=>'page','mixed_multiplier'=>'page','dense_page_multiplier'=>'page'];
  foreach(DB::table('pricing_rules')->where('active',true)->get() as $rule){$existing=DB::table('farast_pricing_policies')->where('code',$rule->key)->first();if(!$existing) DB::table('farast_pricing_policies')->insert(['code'=>$rule->key,'capability_code'=>null,'unit'=>$unitMap[$rule->key]??'operation','base_price_rials'=>(int)$rule->value,'subscription_allowance'=>0,'additional_price_rials'=>(int)$rule->value,'payg_multiplier_percent'=>$rule->key==='typing_payg_multiplier'?(int)$rule->value:100,'discount_percent'=>0,'fee_rials'=>0,'active'=>true,'metadata'=>json_encode(['legacy_pricing_rule_id'=>$rule->id]),'created_at'=>now(),'updated_at'=>now()]);}
 }
 public function down(): void { foreach(['farast_evaluation_cases','farast_evaluation_datasets','farast_approved_knowledge','farast_review_cases','farast_feedback_evidence','farast_pricing_policies','farast_entitlements','farast_tool_applications','farast_tool_versions','farast_tools','farast_providers','farast_capabilities','farast_applications'] as $t) Schema::dropIfExists($t); }
};