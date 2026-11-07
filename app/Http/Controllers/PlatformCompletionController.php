<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Services\EditorDocumentService;
use App\Models\Ticket;
use App\Services\AuthorizationService;

class PlatformCompletionController extends Controller {
 private function doc(Request $r,$id){$d=DB::table('farast_documents')->where('id',$id)->where('user_id',auth()->id())->where('status','active')->whereNull('trashed_at')->first();abort_unless($d,404);return $d;}
 private function assertFolderOwnership(?int $folderId):void{if($folderId===null)return;$owned=DB::table('document_folders')->where('id',$folderId)->where('user_id',auth()->id())->exists();abort_unless($owned,404);}
 private function authorizeDocumentAction(Request $r,string $permission):void{abort_unless(app(AuthorizationService::class)->allows($r->user(),$permission),403);}
 public function documents(Request $r){
  $this->authorizeDocumentAction($r,'documents.view');
  $userId=(int)$r->user()->id;
  $canShareDocuments=app(AuthorizationService::class)->allows($r->user(),'documents.share');
  $canCreateDocuments=app(AuthorizationService::class)->allows($r->user(),'documents.create');
  $canEditDocuments=app(AuthorizationService::class)->allows($r->user(),'documents.edit');
  $canDeleteDocuments=app(AuthorizationService::class)->allows($r->user(),'documents.delete');
  $folders=DB::table('document_folders')->where('user_id',$userId)->orderBy('name')->get();
  if($r->boolean('shared')){
   $this->authorizeDocumentAction($r,'documents.share');
   $shares=DB::table('farast_document_shares as shares')->join('farast_documents as docs','docs.id','=','shares.document_id')->where('shares.owner_id',$userId)->whereColumn('docs.user_id','shares.owner_id')->select('shares.id as share_id','shares.token','shares.permission','shares.expires_at','shares.created_at','docs.id as document_id','docs.title','docs.status as document_status','docs.trashed_at')->orderByDesc('shares.created_at')->paginate(24)->withQueryString();
   return view('platform.documents',['documents'=>collect(),'folders'=>$folders,'shares'=>$shares,'mode'=>'shared','trashed'=>false,'canShareDocuments'=>$canShareDocuments,'canCreateDocuments'=>$canCreateDocuments,'canEditDocuments'=>$canEditDocuments,'canDeleteDocuments'=>$canDeleteDocuments]);
  }
  $trashed=$r->boolean('trashed');
  $q=DB::table('farast_documents')->where('user_id',$userId);
  if($trashed){$q->where('status','trashed')->whereNotNull('trashed_at');}
  else{$q->where('status','active')->whereNull('trashed_at');}
  if($r->boolean('favorite'))$q->where('is_favorite',1);
  if($r->filled('folder')){
   $folderId=$r->integer('folder');
   abort_unless(DB::table('document_folders')->where('id',$folderId)->where('user_id',$userId)->exists(),404);
   $q->where('folder_id',$folderId);
  }
  return view('platform.documents',['documents'=>$q->orderByDesc('updated_at')->orderByDesc('id')->paginate(24)->withQueryString(),'folders'=>$folders,'shares'=>collect(),'mode'=>$trashed?'trashed':($r->boolean('favorite')?'favorites':'documents'),'trashed'=>$trashed,'canShareDocuments'=>$canShareDocuments,'canCreateDocuments'=>$canCreateDocuments,'canEditDocuments'=>$canEditDocuments,'canDeleteDocuments'=>$canDeleteDocuments]);
 }
 public function createDocument(Request $r, EditorDocumentService $documents){
  $this->authorizeDocumentAction($r,'documents.create');
  $d=$r->validate(['title'=>'required|string|max:255','content'=>'nullable|string','folder_id'=>'nullable|integer']);
  $this->assertFolderOwnership(isset($d['folder_id'])?(int)$d['folder_id']:null);
  $content=$d['content']??'';
  $model=$documents->normalizeHtml($content);
  DB::transaction(function()use($d,$content,$model,$documents){
   $id=DB::table('farast_documents')->insertGetId(['user_id'=>auth()->id(),'title'=>$d['title'],'content'=>$content,'content_json'=>json_encode($model,JSON_UNESCAPED_UNICODE),'document_format'=>'farast-v1','page_settings'=>json_encode($documents->defaultPageSettings(),JSON_UNESCAPED_UNICODE),'revision'=>1,'last_saved_at'=>now(),'folder_id'=>$d['folder_id']??null,'created_at'=>now(),'updated_at'=>now()]);
   DB::table('farast_document_versions')->insert(['document_id'=>$id,'user_id'=>auth()->id(),'content'=>$content,'label'=>'نسخه اولیه','created_at'=>now(),'updated_at'=>now()]);
  });
  return redirect()->route('platform.documents')->with('status','سند ایجاد شد.');
 }
 public function updateDocument(Request $r,$id, EditorDocumentService $documents){
  $this->authorizeDocumentAction($r,'documents.edit');
  $x=$r->validate(['title'=>'required|string|max:255','content'=>'nullable|string','folder_id'=>'nullable|integer']);
  $this->assertFolderOwnership(isset($x['folder_id'])?(int)$x['folder_id']:null);
  $content=$x['content']??'';
  $model=$documents->normalizeHtml($content);
  DB::transaction(function()use($r,$id,$x,$content,$model){
   $current=DB::table('farast_documents')->where('id',$id)->where('user_id',auth()->id())->where('status','active')->whereNull('trashed_at')->lockForUpdate()->first();
   abort_unless($current,404);
   $revision=((int)($current->revision??0))+1;
   $updated=DB::table('farast_documents')->where('id',$id)->where('user_id',auth()->id())->where('status','active')->whereNull('trashed_at')->update(['title'=>$x['title'],'content'=>$content,'content_json'=>json_encode($model,JSON_UNESCAPED_UNICODE),'revision'=>$revision,'last_saved_at'=>now(),'folder_id'=>$x['folder_id']??null,'updated_at'=>now()]);
   abort_unless($updated===1,404);
   DB::table('farast_document_versions')->insert(['document_id'=>$id,'user_id'=>auth()->id(),'content'=>$content,'label'=>$r->input('version_label'),'created_at'=>now(),'updated_at'=>now()]);
  });
  return back()->with('status','سند ذخیره شد.');
 }
 public function favorite(Request $r,$id){$this->authorizeDocumentAction($r,'documents.edit');$d=$this->doc($r,$id);$updated=DB::table('farast_documents')->where('id',$id)->where('user_id',auth()->id())->where('status','active')->whereNull('trashed_at')->update(['is_favorite'=>!$d->is_favorite,'updated_at'=>now()]);abort_unless($updated===1,404);return back();}
 public function trash(Request $r,$id){
  $this->authorizeDocumentAction($r,'documents.delete');
  $this->doc($r,$id);
  DB::transaction(function()use($id){
   $updated=DB::table('farast_documents')->where('id',$id)->where('user_id',auth()->id())->where('status','active')->whereNull('trashed_at')->update(['status'=>'trashed','trashed_at'=>now(),'updated_at'=>now()]);
   abort_unless($updated===1,404);
   DB::table('farast_document_shares')->where('document_id',$id)->where('owner_id',auth()->id())->update(['expires_at'=>now(),'updated_at'=>now()]);
  });
  return back()->with('status','سند به سطل زباله منتقل شد.');
 }
 public function restore(Request $r,$id){$this->authorizeDocumentAction($r,'documents.delete');$updated=DB::table('farast_documents')->where('id',$id)->where('user_id',auth()->id())->where('status','trashed')->whereNotNull('trashed_at')->update(['status'=>'active','trashed_at'=>null,'updated_at'=>now()]);abort_unless($updated,404);return back()->with('status','سند بازیابی شد.');}
 public function destroy(Request $r,$id){$this->authorizeDocumentAction($r,'documents.delete');$deleted=DB::table('farast_documents')->where('id',$id)->where('user_id',auth()->id())->where('status','trashed')->delete();abort_unless($deleted===1,404);return back()->with('status','سند برای همیشه حذف شد.');}
 public function versions(Request $r,$id){$this->authorizeDocumentAction($r,'documents.view');$this->doc($r,$id);return response()->json(DB::table('farast_document_versions')->where('document_id',$id)->orderByDesc('created_at')->get());}
 public function share(Request $r,$id){$this->authorizeDocumentAction($r,'documents.share');$this->doc($r,$id);$x=$r->validate(['permission'=>'nullable|in:view','expires_at'=>'nullable|date|after:now']);$token=Str::random(48);DB::table('farast_document_shares')->insert(['document_id'=>$id,'owner_id'=>auth()->id(),'token'=>$token,'permission'=>'view','expires_at'=>$x['expires_at']??null,'created_at'=>now(),'updated_at'=>now()]);return response()->json(['url'=>url('/shared/documents/'.$token),'permission'=>'view']);}
 public function revokeShare(Request $r,$id){$this->authorizeDocumentAction($r,'documents.share');$deleted=DB::table('farast_document_shares')->where('id',$id)->where('owner_id',auth()->id())->delete();abort_unless($deleted,404);return back()->with('status','لینک اشتراک لغو شد.');}
 public function shared($token){$s=DB::table('farast_document_shares')->where('token',$token)->where('permission','view')->first();abort_unless($s && (!$s->expires_at || now()->lt($s->expires_at)),404);$d=DB::table('farast_documents')->where('id',$s->document_id)->where('user_id',$s->owner_id)->where('status','active')->whereNull('trashed_at')->first();abort_unless($d,404);return view('platform.shared-document',compact('d','s'));}
 /** اعلان‌ها — واگذاری به کنترلر یکپارچه */
 public function notifications(Request $r){return app(NotificationController::class)->index($r);}
 public function readNotification(Request $r,$id){return app(NotificationController::class)->read($r,(int)$id);}
 public function workflow(Request $r){
  abort_unless(app(AuthorizationService::class)->allows($r->user(),'workflows.view'),403);
  $items=DB::table('farast_workflows')->where('user_id',$r->user()->id)->orderByDesc('updated_at')->get();
  return view('platform.workflows',compact('items'));
 }
 public function saveWorkflow(Request $r){
  abort_unless(app(AuthorizationService::class)->allows($r->user(),'workflows.manage'),403);
  $x=$r->validate([
   'id'=>'nullable|integer|min:1',
   'name'=>'required|string|max:160',
   'trigger_type'=>'required|in:document.saved,ticket.created,order.paid,order.deposit_paid',
   'steps'=>'required|array|min:1|max:20',
   'steps.*.action'=>'required|in:notify,create_follow_up',
   'steps.*.title'=>'nullable|string|max:180',
   'steps.*.message'=>'nullable|string|max:5000',
   'steps.*.description'=>'nullable|string|max:4000',
   'steps.*.priority'=>'nullable|in:low,normal,high',
   'steps.*.due_at'=>'nullable|date',
  ]);
  $payload=[
   'name'=>$x['name'],
   'trigger_type'=>$x['trigger_type'],
   'steps'=>json_encode($x['steps'],JSON_UNESCAPED_UNICODE),
   'enabled'=>$r->boolean('enabled',true),
   'updated_at'=>now(),
  ];
  DB::transaction(function()use($r,$x,$payload){
   if(!empty($x['id'])){
    $owned=DB::table('farast_workflows')->where('id',$x['id'])->where('user_id',$r->user()->id)->exists();
    abort_unless($owned,404);
    DB::table('farast_workflows')->where('id',$x['id'])->where('user_id',$r->user()->id)->update($payload);
    return;
   }
   $payload['user_id']=$r->user()->id;
   $payload['created_at']=now();
   DB::table('farast_workflows')->insert($payload);
  });
  return back()->with('status','گردش‌کار ذخیره شد.');
 }
 public function runWorkflow(Request $r,$id, \App\Services\WorkflowEngine $engine){
  abort_unless(app(AuthorizationService::class)->allows($r->user(),'workflows.manage'),403);
  $w=DB::table('farast_workflows')->where('id',$id)->where('user_id',$r->user()->id)->first();
  abort_unless($w,404);
  abort_unless((bool)$w->enabled,409,'گردش‌کار غیرفعال است.');
  $context=$r->validate(['context'=>'nullable|array'])['context']??[];
  $context['user_id']=$r->user()->id;
  $run=$engine->run((int)$id,$context);
  return response()->json(['run_id'=>$run,'status'=>'completed']);
 }
 public function security(Request $r){
  abort_unless(app(AuthorizationService::class)->allows($r->user(),'audit.view'),403);
  return view('platform.security',['events'=>DB::table('farast_security_events')->where('user_id',$r->user()->id)->latest()->limit(50)->get()]);
 }
 public function ocr(Request $r){
  abort_unless(app(AuthorizationService::class)->allows($r->user(),'ocr.use'),403);
  $r->validate(['source_path'=>'required|string|max:1000','provider'=>'nullable|string|max:50']);
  // There is currently no registered OCR provider or queue consumer in this
  // deployment. Do not create jobs that would remain queued forever.
  return response()->json([
   'ok'=>false,
   'status'=>'unavailable',
   'code'=>'ocr_worker_unavailable',
   'message'=>'پردازشگر OCR هنوز به صف کار متصل نشده است؛ هیچ کاری ثبت نشد.',
  ],503);
 }
 public function ocrStatus(Request $r,$id){abort_unless(app(AuthorizationService::class)->allows($r->user(),'ocr.use'),403);$j=DB::table('farast_ocr_jobs')->where('id',$id)->where('user_id',$r->user()->id)->first();abort_unless($j,404);return response()->json($j);}
 public function cms(Request $r){abort_unless(auth()->user()->isAdmin(),403);return view('admin.cms',['pages'=>DB::table('farast_cms_pages')->orderByDesc('updated_at')->get()]);}
 public function saveCms(Request $r){abort_unless(auth()->user()->isAdmin(),403);$x=$r->validate(['slug'=>'required|string|max:160','title'=>'required|string|max:255','body'=>'nullable|string','status'=>'required|in:draft,published']);DB::table('farast_cms_pages')->updateOrInsert(['slug'=>$x['slug']],['title'=>$x['title'],'body'=>$x['body']??'','status'=>$x['status'],'published_at'=>$x['status']==='published'?now():null,'updated_by'=>auth()->id(),'updated_at'=>now(),'created_at'=>now()]);return back()->with('status','صفحه CMS ذخیره شد.');}
 public function billing(Request $r){
  abort_unless(app(AuthorizationService::class)->allows($r->user(),'billing.view'),403);
  $plans=DB::table('farast_commercial_plans')->where('active',1)->orderBy('price')->orderBy('name')->get();
  $sub=DB::table('farast_subscriptions')
   ->join('farast_commercial_plans','farast_commercial_plans.id','=','farast_subscriptions.plan_id')
   ->where('farast_subscriptions.user_id',$r->user()->id)
   ->where('farast_subscriptions.status','active')
   ->where('farast_subscriptions.starts_at','<=',now())
   ->where(function($q){$q->whereNull('farast_subscriptions.ends_at')->orWhere('farast_subscriptions.ends_at','>',now());})
   ->select('farast_subscriptions.*','farast_commercial_plans.name','farast_commercial_plans.code','farast_commercial_plans.price','farast_commercial_plans.currency','farast_commercial_plans.quotas')
   ->orderByDesc('farast_subscriptions.starts_at')->first();
  return view('platform.billing',compact('plans','sub'));
 }
 public function subscribe(Request $r,$plan){
  abort_unless(app(AuthorizationService::class)->allows($r->user(),'billing.view'),403);
  $user=$r->user();
  $planId=(int)$plan;
  $activated=DB::transaction(function()use($user,$planId){
   $p=DB::table('farast_commercial_plans')->where('id',$planId)->where('active',1)->lockForUpdate()->first();
   abort_unless($p,404);
   // Paid plans must go through a verified payment flow. This endpoint only
   // activates free plans and cannot be raced against an admin price update.
   if((int)$p->price>0)return false;
   $starts=now();
   $ends=match($p->billing_interval){
    'monthly'=>$starts->copy()->addMonth(),
    'yearly'=>$starts->copy()->addYear(),
    default=>null,
   };
   DB::table('farast_subscriptions')->where('user_id',$user->id)->where('status','active')->lockForUpdate()->get();
   DB::table('farast_subscriptions')->where('user_id',$user->id)->where('status','active')->update(['status'=>'replaced','ends_at'=>$starts,'updated_at'=>$starts]);
   DB::table('farast_entitlements')->where('user_id',$user->id)->where('source_type','subscription')->where('status','active')->update(['status'=>'replaced','ends_at'=>$starts,'updated_at'=>$starts]);
   DB::table('farast_subscriptions')->insert([
    'subscription_id'=>(string)Str::uuid(),
    'user_id'=>$user->id,
    'organization_id'=>$user->organization_id,
    'plan_id'=>$p->id,
    'status'=>'active',
    'starts_at'=>$starts,
    'ends_at'=>$ends,
    'created_at'=>$starts,
    'updated_at'=>$starts,
   ]);
   app(\App\Services\SubscriptionEntitlementService::class)->sync($user,(int)$p->id);
   return true;
  });
  if(!$activated){
   return back()->withErrors(['plan'=>'این پلن پولی است؛ تا زمانی که پرداخت و تأیید مالی آن به اشتراک متصل نشده، فعال‌سازی مستقیم مجاز نیست.']);
  }
  return back()->with('status','پلن رایگان فعال شد و سهمیه‌های آن در سامانه ثبت شدند.');
 }
 public function adminPlans(){
  abort_unless(auth()->user()->isAdmin(),403);
  return view('admin.plans',['plans'=>DB::table('farast_commercial_plans')->orderBy('price')->orderBy('name')->get()]);
 }
 public function savePlan(Request $r){
  abort_unless($r->user()->isAdmin(),403);
  $x=$r->validate([
   'code'=>'required|string|max:120',
   'name'=>'required|string|max:180',
   'price'=>'required|integer|min:0',
   'currency'=>'required|string|max:8',
   'billing_interval'=>'nullable|in:monthly,yearly,one_time',
   'quotas'=>'nullable|array',
   'quotas.*'=>'nullable|integer|min:0',
   'allow_payg'=>'nullable|boolean',
   'allow_overage'=>'nullable|boolean',
   'postpaid'=>'nullable|boolean',
   'active'=>'nullable|boolean',
  ]);
  DB::table('farast_commercial_plans')->updateOrInsert(['code'=>$x['code']],[
   'name'=>$x['name'],
   'price'=>$x['price'],
   'currency'=>strtoupper($x['currency']),
   'billing_interval'=>$x['billing_interval']??null,
   'quotas'=>json_encode($x['quotas']??[],JSON_UNESCAPED_UNICODE),
   'allow_payg'=>$r->boolean('allow_payg',true),
   'allow_overage'=>$r->boolean('allow_overage',false),
   'postpaid'=>$r->boolean('postpaid',false),
   'active'=>$r->boolean('active',true),
   'updated_at'=>now(),
   'created_at'=>now(),
  ]);
  return back()->with('status','پلن تجاری در کاتالوگ اصلی ذخیره شد.');
 }
 public function adminSupport(Request $request){
  abort_unless(auth()->user()->isAdmin(),403);
  $query=Ticket::with(['messages'=>fn($q)=>$q->orderBy('created_at'),'user','relatedProduct']);
  if($request->filled('status')&&$request->status!=='all'){$query->where('status',$request->status);}
  if($request->filled('q')){$q=$request->string('q');$query->where(function($w)use($q){$w->where('subject','like','%'.$q.'%')->orWhere('id',$q)->orWhere('guest_email','like','%'.$q.'%')->orWhere('guest_name','like','%'.$q.'%');});}
  $tickets=$query->orderByDesc('updated_at')->paginate(30)->withQueryString();
  $active=null;
  if($request->filled('ticket')){$active=Ticket::with(['messages'=>fn($q)=>$q->orderBy('created_at'),'user','relatedProduct'])->find($request->integer('ticket'));}
  if(!$active){$active=$tickets->getCollection()->first();}
  $stats=['open'=>Ticket::whereIn('status',['open','user_replied'])->count(),'answered'=>Ticket::where('status','answered')->count(),'pending'=>Ticket::where('status','pending')->count(),'closed'=>Ticket::where('status','closed')->count()];
  return view('admin.support',compact('tickets','active','stats'));
 }
 public function usage(Request $r){abort_unless(app(AuthorizationService::class)->allows($r->user(),'reports.view'),403);return response()->json(DB::table('farast_usage_counters')->where('user_id',$r->user()->id)->orderByDesc('period_start')->get());}
 public function createFolder(Request $r){$this->authorizeDocumentAction($r,'documents.create');$x=$r->validate(['name'=>'required|string|max:120','parent_id'=>'nullable|integer']);$this->assertFolderOwnership(isset($x['parent_id'])?(int)$x['parent_id']:null);DB::table('document_folders')->insert(['user_id'=>auth()->id(),'name'=>$x['name'],'parent_id'=>$x['parent_id']??null,'created_at'=>now(),'updated_at'=>now()]);return back()->with('status','پوشه ایجاد شد.');}
 public function teamMembers(Request $r,$team){abort_unless(auth()->user()->isAdmin(),403);$t=DB::table('teams')->find($team);abort_unless($t,404);return response()->json(DB::table('team_user')->join('users','users.id','=','team_user.user_id')->where('team_id',$team)->select('users.id','users.name','users.email','team_user.team_role')->get());}
 public function addTeamMember(Request $r,$team){abort_unless(auth()->user()->isAdmin(),403);$x=$r->validate(['user_id'=>'required|integer','team_role'=>'required|in:owner,supervisor,employee']);DB::table('team_user')->updateOrInsert(['team_id'=>$team,'user_id'=>$x['user_id']],['team_role'=>$x['team_role'],'created_at'=>now(),'updated_at'=>now()]);return back()->with('status','عضو تیم به‌روزرسانی شد.');}
 public function removeTeamMember(Request $r,$team,$user){abort_unless(auth()->user()->isAdmin(),403);DB::table('team_user')->where('team_id',$team)->where('user_id',$user)->delete();return back();}
}
