<?php
namespace App\Http\Controllers;
use App\Models\AiInteraction;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\Team;
use App\Models\User;
use App\Models\VoiceProviderAccount;
use App\Services\AuthorizationService;
use App\Services\AuditLogService;
use App\Services\CanvaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class AdminPlatformController extends Controller {
 public function integrations(CanvaService $canva, \App\Services\ProviderRegistry $providers){return view('admin.integrations',['canvaConfigured'=>$canva->configured(),'canvaConnected'=>\App\Models\CanvaConnection::where('user_id',auth()->id())->exists(),'providers'=>$providers->catalog()]);}
 public function saveCanva(Request $r){$d=$r->validate(['canva_client_id'=>'nullable|string|max:255','canva_client_secret'=>'nullable|string|max:1000','canva_redirect_uri'=>'nullable|url|max:500','canva_scopes'=>'nullable|string|max:1000']);foreach(['canva_client_id'=>false,'canva_redirect_uri'=>false,'canva_scopes'=>false] as $k=>$secret)if(array_key_exists($k,$d)&&$d[$k]!==null&&$d[$k]!=='')\App\Models\SiteSetting::write($k,trim($d[$k]),$secret);if(!empty($d['canva_client_secret']))\App\Models\SiteSetting::write('canva_client_secret',trim($d['canva_client_secret']),true);app(AuditLogService::class)->record(auth()->id(),'integration.canva.settings',request()->ip(),['configured'=>true]);return back()->with('status','تنظیمات Canva ذخیره شد.');}
 public function access(AuthorizationService $authz){return view('admin.access',['permissions'=>Permission::orderBy('group')->orderBy('key')->get(),'roles'=>User::DASHBOARD_ROLES,'rolePermissions'=>RolePermission::with('permission')->get()]);}
 public function updateRole(Request $r,string $role,AuthorizationService $authz){abort_unless(in_array($role,User::DASHBOARD_ROLES,true)&&$role!=='admin',422);$keys=$r->input('permissions',[]);$ids=Permission::whereIn('key',$keys)->pluck('id')->all();RolePermission::where('role',$role)->delete();foreach($ids as $id)RolePermission::create(['role'=>$role,'permission_id'=>$id]);app(AuditLogService::class)->record(auth()->id(),'rbac.role_permissions.updated',request()->ip(),['role'=>$role,'count'=>count($ids)]);return back()->with('status','مجوزهای نقش ذخیره شد.');}
 public function organizations(){return view('admin.organizations',['organizations'=>Organization::withCount('users','teams')->latest()->paginate(20)]);}
 public function storeOrganization(Request $r){$d=$r->validate(['name'=>'required|string|max:120','slug'=>'required|string|max:120|alpha_dash|unique:organizations,slug']);$o=Organization::create($d);app(AuditLogService::class)->record(auth()->id(),'organization.created',request()->ip(),['organization_id'=>$o->id]);return back()->with('status','سازمان ایجاد شد.');}
 public function analytics(){return view('admin.analytics',['stats'=>['users'=>User::count(),'active_users'=>User::where('is_blocked',false)->count(),'documents'=>\App\Models\TypingDocument::count(),'ai_total'=>AiInteraction::count(),'ai_today'=>AiInteraction::whereDate('created_at',today())->count(),'voice_seconds'=>DB::table('voice_provider_usage')->sum('audio_seconds'),'voice_requests'=>DB::table('voice_provider_usage')->sum('requests'),'revenue'=>DB::table('payments')->where('status','paid')->sum('amount')],'providers'=>VoiceProviderAccount::get(['provider','model','enabled','healthy','quota_used_seconds','quota_limit_seconds','last_latency_ms','last_success_at','last_failure_at'])]);}
 public function audit(){return view('admin.audit',['logs'=>DB::table('audit_logs')->leftJoin('users','users.id','=','audit_logs.user_id')->select('audit_logs.*','users.name as actor_name','users.mobile as actor_mobile')->latest('audit_logs.id')->paginate(50)]);}
}
