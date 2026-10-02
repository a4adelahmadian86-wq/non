<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  if (!Schema::hasTable('farast_tools')) return;
  $app=DB::table('farast_applications')->where('code','word_processor')->value('id');
  $cap=DB::table('farast_capabilities')->where('code','document.editing')->value('id');
  $provider=DB::table('farast_providers')->where('code','farast_core')->value('id');
  if(!$app||!$cap||!$provider)return;
  $tool=DB::table('farast_tools')->where('code','editor.kernel')->first();
  $id=$tool?$tool->id:DB::table('farast_tools')->insertGetId(['code'=>'editor.kernel','name'=>'FARAST Editor Kernel','capability_id'=>$cap,'provider_id'=>$provider,'status'=>'active','risk_level'=>'low','metadata'=>json_encode(['execution'=>'client_command_registry']),'created_at'=>now(),'updated_at'=>now()]);
  if(!DB::table('farast_tool_versions')->where(['tool_id'=>$id,'version'=>'1.0.0'])->exists())DB::table('farast_tool_versions')->insert(['tool_id'=>$id,'version'=>'1.0.0','status'=>'production','evaluation_status'=>'approved','review_status'=>'approved','quality_score'=>1,'last_verified_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
  if(!DB::table('farast_tool_applications')->where(['tool_id'=>$id,'application_id'=>$app])->exists())DB::table('farast_tool_applications')->insert(['tool_id'=>$id,'application_id'=>$app]);
 }
 public function down(): void { if(Schema::hasTable('farast_tools')) DB::table('farast_tools')->where('code','editor.kernel')->delete(); }
};