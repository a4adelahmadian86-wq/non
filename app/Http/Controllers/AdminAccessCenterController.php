<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Team;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminAccessCenterController extends Controller
{
    private const SCOPES = ['global', 'organization', 'team', 'own'];
    private const SCOPE_RANK = ['global' => 0, 'organization' => 1, 'team' => 2, 'own' => 3];
    private const SENSITIVE_DIRECT_GRANTS = [
        'access.manage', 'access.roles.manage', 'access.assign', 'access.override',
        'settings.manage', 'billing.manage', 'integrations.manage', 'voice.manage',
        'system.audit', 'automation.manage',
    ];

    private function ensurePlatformAdmin(Request $request): void
    {
        abort_unless($request->user()?->isAdmin(), 403, 'مدیریت ساختار نقش‌ها فقط در اختیار مدیر اصلی سامانه است.');
        foreach (['farast_roles', 'farast_role_permissions', 'farast_user_roles', 'farast_user_permission_overrides'] as $table) {
            abort_unless(Schema::hasTable($table), 503, 'زیرساخت مدیریت دسترسی هنوز نصب نشده است.');
        }
    }

    public function index(Request $request)
    {
        $this->ensurePlatformAdmin($request);

        $search = trim((string) $request->query('q', ''));
        $users = User::query()
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', '%'.$search.'%')
                        ->orWhere('mobile', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%');
                });
            })
            ->orderByDesc('id')
            ->limit(30)
            ->get(['id', 'name', 'mobile', 'email', 'role', 'organization_id']);

        $userIds = $users->pluck('id')->all();

        return view('admin.access-center', [
            'roles' => DB::table('farast_roles')->orderBy('scope_type')->orderBy('name')->get(),
            'permissions' => Permission::query()->orderBy('group')->orderBy('key')->get()->groupBy('group'),
            'rolePermissions' => DB::table('farast_role_permissions')->get()->groupBy('role_id'),
            'users' => $users,
            'assignments' => $userIds
                ? DB::table('farast_user_roles as ur')->join('farast_roles as r', 'r.id', '=', 'ur.role_id')
                    ->whereIn('ur.user_id', $userIds)
                    ->select('ur.*', 'r.name as role_name', 'r.key as role_key')
                    ->orderByDesc('ur.id')->limit(100)->get()
                : collect(),
            'overrides' => $userIds
                ? DB::table('farast_user_permission_overrides as uo')
                    ->join('permissions as p', 'p.id', '=', 'uo.permission_id')
                    ->whereIn('uo.user_id', $userIds)
                    ->select('uo.*', 'p.key as permission_key', 'p.label as permission_label')
                    ->orderByDesc('uo.id')->limit(100)->get()
                : collect(),
            'auditLogs' => DB::table('farast_access_audit_logs')->orderByDesc('id')->limit(40)->get(),
            'search' => $search,
            'scopeOptions' => self::SCOPES,
        ]);
    }

    public function storeRole(Request $request)
    {
        $this->ensurePlatformAdmin($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'scope_type' => ['required', Rule::in(self::SCOPES)],
            'scope_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $scopeId = $this->validatedScopeId($data['scope_type'], $data['scope_id'] ?? null);
        $base = Str::slug($data['name']) ?: 'custom-role';
        $key = 'custom-'.$base;
        if (DB::table('farast_roles')->where('key', $key)->exists()) {
            $key .= '-'.Str::lower(Str::random(6));
        }

        $roleId = DB::table('farast_roles')->insertGetId([
            'key' => $key,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'scope_type' => $data['scope_type'],
            'scope_id' => $scopeId,
            'is_system' => false,
            'is_active' => true,
            'created_by' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit($request, 'role.created', null, $roleId, null, null, ['key' => $key, 'name' => $data['name'], 'scope' => $data['scope_type']]);

        return back()->with('status', 'نقش سفارشی ایجاد شد؛ اکنون مجوزهای آن را تعیین کنید.');
    }

    public function updateRole(Request $request, int $role)
    {
        $this->ensurePlatformAdmin($request);
        $record = DB::table('farast_roles')->where('id', $role)->first();
        abort_unless($record && ! $record->is_system, 404, 'نقش سیستمی قابل ویرایش نیست.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'scope_type' => ['required', Rule::in(self::SCOPES)],
            'scope_id' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['required', 'boolean'],
        ]);
        $scopeId = $this->validatedScopeId($data['scope_type'], $data['scope_id'] ?? null);

        DB::table('farast_roles')->where('id', $role)->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'scope_type' => $data['scope_type'],
            'scope_id' => $scopeId,
            'is_active' => (bool) $data['is_active'],
            'updated_at' => now(),
        ]);

        $this->audit($request, 'role.updated', null, $role, null, (array) $record, $data);

        return back()->with('status', 'تنظیمات نقش ذخیره شد.');
    }

    public function syncPermissions(Request $request, int $role)
    {
        $this->ensurePlatformAdmin($request);
        $record = DB::table('farast_roles')->where('id', $role)->first();
        abort_unless($record && ! $record->is_system, 404, 'نقش سیستمی قابل ویرایش نیست.');

        $data = $request->validate([
            'permissions' => ['sometimes', 'array', 'max:250'],
            'permissions.*' => ['required', 'string', 'distinct', 'exists:permissions,key'],
            'scope_type' => ['nullable', Rule::in(self::SCOPES)],
        ]);
        $keys = array_values(array_unique($data['permissions'] ?? []));
        $permissionIds = Permission::query()->whereIn('key', $keys)->pluck('id')->all();
        $scope = $data['scope_type'] ?? $record->scope_type;

        DB::transaction(function () use ($role, $permissionIds, $scope): void {
            DB::table('farast_role_permissions')->where('role_id', $role)->delete();
            foreach ($permissionIds as $permissionId) {
                DB::table('farast_role_permissions')->insert([
                    'role_id' => $role,
                    'permission_id' => $permissionId,
                    'scope_type' => $scope,
                    'conditions' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        app(AuthorizationService::class)->forgetUser();
        $this->audit($request, 'role.permissions.synced', null, $role, null, null, ['permissions' => $keys, 'scope' => $scope]);

        return back()->with('status', 'مجوزهای نقش به‌روزرسانی شد.');
    }

    public function assignRole(Request $request, int $user)
    {
        $this->ensurePlatformAdmin($request);
        $target = User::query()->findOrFail($user);
        abort_if($target->isAdmin(), 422, 'نقش مدیر اصلی از این مسیر قابل انتساب نیست.');

        $data = $request->validate([
            'role_id' => ['required', 'integer', 'exists:farast_roles,id'],
            'scope_type' => ['required', Rule::in(self::SCOPES)],
            'scope_id' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:starts_at'],
        ]);

        $role = DB::table('farast_roles')->where('id', $data['role_id'])->where('is_active', true)->first();
        abort_unless($role && ! $role->is_system, 422, 'فقط نقش سفارشی فعال قابل انتساب است.');
        abort_if(self::SCOPE_RANK[$data['scope_type']] < self::SCOPE_RANK[$role->scope_type], 422, 'محدوده انتساب نمی‌تواند از محدوده تعریف‌شده نقش گسترده‌تر باشد.');

        $scopeId = $this->validatedScopeId($data['scope_type'], $data['scope_id'] ?? null);
        $this->assertUserBelongsToScope($target, $data['scope_type'], $scopeId);

        $existing = DB::table('farast_user_roles')->where('user_id', $target->id)
            ->where('role_id', $role->id)->where('scope_type', $data['scope_type'])
            ->where('scope_id', $scopeId)->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })->exists();
        abort_if($existing, 409, 'این نقش در همین محدوده قبلاً به کاربر اختصاص یافته است.');

        $assignmentId = DB::table('farast_user_roles')->insertGetId([
            'user_id' => $target->id,
            'role_id' => $role->id,
            'scope_type' => $data['scope_type'],
            'scope_id' => $scopeId,
            'starts_at' => $data['starts_at'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'assigned_by' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(AuthorizationService::class)->forgetUser($target->id);
        $this->audit($request, 'user.role.assigned', $target->id, $role->id, null, null, ['assignment_id' => $assignmentId, 'scope' => $data['scope_type'], 'scope_id' => $scopeId]);

        return back()->with('status', 'نقش با محدوده و زمان اعتبار مشخص به کاربر اختصاص یافت.');
    }

    public function revokeRole(Request $request, int $user, int $assignment)
    {
        $this->ensurePlatformAdmin($request);
        $target = User::query()->findOrFail($user);
        $record = DB::table('farast_user_roles')->where('id', $assignment)->where('user_id', $target->id)->first();
        abort_unless($record, 404);

        DB::table('farast_user_roles')->where('id', $assignment)->delete();
        app(AuthorizationService::class)->forgetUser($target->id);
        $this->audit($request, 'user.role.revoked', $target->id, (int) $record->role_id, null, (array) $record, null);

        return back()->with('status', 'نقش از کاربر لغو شد.');
    }

    public function storeOverride(Request $request, int $user)
    {
        $this->ensurePlatformAdmin($request);
        $target = User::query()->findOrFail($user);
        abort_if($target->isAdmin(), 422, 'برای مدیر اصلی مجوز مستقیم تعریف نمی‌شود.');

        $data = $request->validate([
            'permission' => ['required', 'string', 'exists:permissions,key'],
            'effect' => ['required', Rule::in(['allow', 'deny'])],
            'scope_type' => ['required', Rule::in(self::SCOPES)],
            'scope_id' => ['nullable', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:1000'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:starts_at'],
        ]);

        if ($data['effect'] === 'allow') {
            abort_if(in_array($data['permission'], self::SENSITIVE_DIRECT_GRANTS, true), 422, 'مجوزهای حساس باید از طریق نقش و با بازبینی کامل واگذار شوند.');
        }

        $permissionId = (int) Permission::query()->where('key', $data['permission'])->value('id');
        $scopeId = $this->validatedScopeId($data['scope_type'], $data['scope_id'] ?? null);
        $this->assertUserBelongsToScope($target, $data['scope_type'], $scopeId);

        $overrideId = DB::table('farast_user_permission_overrides')->insertGetId([
            'user_id' => $target->id,
            'permission_id' => $permissionId,
            'effect' => $data['effect'],
            'scope_type' => $data['scope_type'],
            'scope_id' => $scopeId,
            'conditions' => null,
            'reason' => $data['reason'],
            'starts_at' => $data['starts_at'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'created_by' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(AuthorizationService::class)->forgetUser($target->id);
        $this->audit($request, 'user.permission.override.created', $target->id, null, $permissionId, null, ['override_id' => $overrideId, 'effect' => $data['effect'], 'scope' => $data['scope_type'], 'reason' => $data['reason']]);

        return back()->with('status', 'استثنای دسترسی کاربر ثبت شد.');
    }

    public function revokeOverride(Request $request, int $user, int $override)
    {
        $this->ensurePlatformAdmin($request);
        $target = User::query()->findOrFail($user);
        $record = DB::table('farast_user_permission_overrides')->where('id', $override)->where('user_id', $target->id)->first();
        abort_unless($record, 404);

        DB::table('farast_user_permission_overrides')->where('id', $override)->delete();
        app(AuthorizationService::class)->forgetUser($target->id);
        $this->audit($request, 'user.permission.override.revoked', $target->id, null, (int) $record->permission_id, (array) $record, null);

        return back()->with('status', 'استثنای دسترسی لغو شد.');
    }

    public function explain(Request $request, int $user, string $permission, AuthorizationService $authorization)
    {
        $this->ensurePlatformAdmin($request);
        $target = User::query()->findOrFail($user);
        abort_unless(Permission::query()->where('key', $permission)->exists(), 404);

        return response()->json([
            'user_id' => $target->id,
            'permission' => $permission,
            'decision' => $authorization->explain($target, $permission),
        ]);
    }

    private function validatedScopeId(string $scope, mixed $scopeId): ?int
    {
        if (in_array($scope, ['global', 'own'], true)) {
            return null;
        }

        abort_unless(is_numeric($scopeId) && (int) $scopeId > 0, 422, 'برای محدوده سازمانی یا تیمی، شناسه محدوده الزامی است.');
        $scopeId = (int) $scopeId;

        if ($scope === 'organization') {
            abort_unless(DB::table('organizations')->where('id', $scopeId)->exists(), 422, 'سازمان انتخاب‌شده معتبر نیست.');
        } elseif ($scope === 'team') {
            abort_unless(Team::query()->whereKey($scopeId)->exists(), 422, 'تیم انتخاب‌شده معتبر نیست.');
        }

        return $scopeId;
    }

    private function assertUserBelongsToScope(User $user, string $scope, ?int $scopeId): void
    {
        if ($scope === 'organization') {
            abort_unless($user->organization_id && (int) $user->organization_id === $scopeId, 422, 'کاربر عضو سازمان انتخاب‌شده نیست.');
        } elseif ($scope === 'team') {
            $belongs = $user->teams()->where('teams.id', $scopeId)->exists()
                || Team::query()->where('id', $scopeId)->where('supervisor_id', $user->id)->exists();
            abort_unless($belongs, 422, 'کاربر عضو یا سرپرست تیم انتخاب‌شده نیست.');
        }
    }

    private function audit(Request $request, string $action, ?int $subjectUserId, ?int $roleId, ?int $permissionId, ?array $before, ?array $after): void
    {
        DB::table('farast_access_audit_logs')->insert([
            'actor_id' => $request->user()?->id,
            'subject_user_id' => $subjectUserId,
            'role_id' => $roleId,
            'permission_id' => $permissionId,
            'action' => $action,
            'before_state' => $before ? json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'after_state' => $after ? json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 2000),
            'created_at' => now(),
        ]);
    }
}
