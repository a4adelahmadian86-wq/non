<?php
namespace App\Http\Controllers;
use App\Models\AiInteraction;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\Team;
use App\Models\User;
use App\Models\VoiceProviderAccount;
use App\Services\AdminConsoleService;
use App\Services\AuthorizationService;
use App\Services\AuditLogService;
use App\Services\CanvaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
class AdminPlatformController extends Controller {
 public function integrations(CanvaService $canva, \App\Services\ProviderRegistry $providers){return view('admin.integrations',['canvaConfigured'=>$canva->configured(),'canvaConnected'=>\App\Models\CanvaConnection::where('user_id',auth()->id())->exists(),'providers'=>$providers->catalog()]);}
 public function saveCanva(Request $r){$d=$r->validate(['canva_client_id'=>'nullable|string|max:255','canva_client_secret'=>'nullable|string|max:1000','canva_redirect_uri'=>'nullable|url|max:500','canva_scopes'=>'nullable|string|max:1000']);foreach(['canva_client_id'=>false,'canva_redirect_uri'=>false,'canva_scopes'=>false] as $k=>$secret)if(array_key_exists($k,$d)&&$d[$k]!==null&&$d[$k]!=='')\App\Models\SiteSetting::write($k,trim($d[$k]),$secret);if(!empty($d['canva_client_secret']))\App\Models\SiteSetting::write('canva_client_secret',trim($d['canva_client_secret']),true);app(AuditLogService::class)->record(auth()->id(),'integration.canva.settings',request()->ip(),['configured'=>true]);return back()->with('status','تنظیمات Canva ذخیره شد.');}
 public function access(AuthorizationService $authz){return view('admin.access',['permissions'=>Permission::orderBy('group')->orderBy('key')->get(),'roles'=>User::DASHBOARD_ROLES,'rolePermissions'=>RolePermission::with('permission')->get()]);}
 public function updateRole(Request $r,string $role,AuthorizationService $authz){
  abort_unless(in_array($role,User::DASHBOARD_ROLES,true)&&$role!=='admin',422);
  $data=$r->validate([
   'permissions'=>['sometimes','array','max:100'],
   'permissions.*'=>['required','string','distinct','exists:permissions,key'],
  ]);
  $keys=array_values(array_unique($data['permissions']??[]));
  $ids=Permission::whereIn('key',$keys)->pluck('id')->all();

  DB::transaction(function()use($role,$ids){
   RolePermission::where('role',$role)->delete();
   foreach($ids as $id)RolePermission::create(['role'=>$role,'permission_id'=>$id]);
  });

  app(AuditLogService::class)->record(auth()->id(),'rbac.role_permissions.updated',request()->ip(),['role'=>$role,'count'=>count($ids)]);
  return back()->with('status','مجوزهای نقش ذخیره شد.');
 }
 public function organizations(Request $request, AdminConsoleService $console){
  $query=Organization::withCount('users','teams')->latest();
  $visibleIds=$console->visibleOrganizationIds($request->user());
  if($visibleIds!==null)$query->whereIn('id',$visibleIds);
  return view('admin.organizations',['organizations'=>$query->paginate(20)]);
 }
 public function storeOrganization(Request $r, AdminConsoleService $console){
  abort_unless($console->sectionAllowed($r->user(),'access'),403,'ایجاد سازمان جدید فقط در دامنهٔ سراسری مجاز است.');
  abort_unless($r->user()?->isAdmin(),403,'ایجاد سازمان جدید فقط برای مدیر اصلی مجاز است.');
  $d=$r->validate(['name'=>'required|string|max:120','slug'=>'required|string|max:120|alpha_dash|unique:organizations,slug']);
  $o=Organization::create($d);
  app(AuditLogService::class)->record(auth()->id(),'organization.created',request()->ip(),['organization_id'=>$o->id]);
  return back()->with('status','سازمان ایجاد شد.');
 }
 public function analytics(Request $request, AdminConsoleService $console){
  $visibleIds=$console->visibleUserIds($request->user());
  $users=User::query();
  $activeUsers=User::query()->where('is_blocked',false);
  if($visibleIds!==null){
   $users->whereIn('id',$visibleIds);
   $activeUsers->whereIn('id',$visibleIds);
  }

  // Use the same canonical-document contract as the main admin overview:
  // active workspace documents are authoritative; legacy editor adapters are
  // included only when they are not linked to a canonical document.
  $documentCount=0;
  if(Schema::hasTable('farast_documents')){
   $canonical=DB::table('farast_documents');
   if(Schema::hasColumn('farast_documents','status'))$canonical->where('status','active');
   if(Schema::hasColumn('farast_documents','trashed_at'))$canonical->whereNull('trashed_at');
   if($visibleIds!==null){
    if(!Schema::hasColumn('farast_documents','user_id'))$canonical=null;
    else $canonical->whereIn('user_id',$visibleIds);
   }
   if($canonical!==null)$documentCount+=(int)$canonical->count();
  }
  if(Schema::hasTable('typing_documents')){
   $legacy=DB::table('typing_documents');
   if(Schema::hasColumn('typing_documents','farast_document_id'))$legacy->whereNull('farast_document_id');
   if($visibleIds!==null){
    if(!Schema::hasColumn('typing_documents','user_id'))$legacy=null;
    else $legacy->whereIn('user_id',$visibleIds);
   }
   if($legacy!==null)$documentCount+=(int)$legacy->count();
  }

  $aiTotal=0;
  $aiToday=0;
  if(Schema::hasTable('ai_interactions')
   &&($visibleIds===null||Schema::hasColumn('ai_interactions','user_id'))){
   $ai=DB::table('ai_interactions');
   if($visibleIds!==null)$ai->whereIn('user_id',$visibleIds);
   $aiTotal=(int)(clone $ai)->count();
   if(Schema::hasColumn('ai_interactions','created_at'))$aiToday=(int)(clone $ai)->whereDate('created_at',today())->count();
  }

  $revenue=0;
  if(Schema::hasTable('payments')
   &&Schema::hasColumn('payments','status')
   &&Schema::hasColumn('payments','amount_rials')){
   $payments=DB::table('payments')->where('payments.status','paid');
   if($visibleIds!==null){
    if(Schema::hasTable('orders')
     &&Schema::hasColumn('payments','order_id')
     &&Schema::hasColumn('orders','user_id')){
     $payments->join('orders','orders.id','=','payments.order_id')
      ->whereIn('orders.user_id',$visibleIds);
     $revenue=(int)$payments->sum('payments.amount_rials');
    }
   }else{
    $revenue=(int)$payments->sum('payments.amount_rials');
   }
  }

  $global=$visibleIds===null;
  $voiceSeconds=0;
  $voiceRequests=0;
  if($global&&Schema::hasTable('voice_provider_usage')){
   if(Schema::hasColumn('voice_provider_usage','audio_seconds'))$voiceSeconds=(int)DB::table('voice_provider_usage')->sum('audio_seconds');
   if(Schema::hasColumn('voice_provider_usage','requests'))$voiceRequests=(int)DB::table('voice_provider_usage')->sum('requests');
  }

  $stats=[
   'users'=>$users->count(),
   'active_users'=>$activeUsers->count(),
   'documents'=>$documentCount,
   'ai_total'=>$aiTotal,
   'ai_today'=>$aiToday,
   'voice_seconds'=>$voiceSeconds,
   'voice_requests'=>$voiceRequests,
   'revenue'=>$revenue,
  ];
  $providers=$global&&Schema::hasTable('voice_provider_accounts')
   ?VoiceProviderAccount::get(['provider','model','enabled','healthy','quota_used_seconds','quota_limit_seconds','last_latency_ms','last_success_at','last_failure_at'])
   :collect();

  return view('admin.analytics',['stats'=>$stats,'providers'=>$providers]);
 }
 public function audit(){return view('admin.audit',['logs'=>DB::table('audit_logs')->leftJoin('users','users.id','=','audit_logs.user_id')->select('audit_logs.*','users.name as actor_name','users.mobile as actor_mobile')->latest('audit_logs.id')->paginate(50)]);}
}
