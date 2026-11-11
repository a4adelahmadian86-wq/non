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
        // Account, document, financial, administrative and audit access must be
        // granted through a reviewed role, never as an isolated user override.
        'access.manage', 'access.roles.manage', 'access.assign', 'access.override', 'access.audit',
        'settings.manage', 'billing.view', 'billing.manage', 'finance.view', 'finance.manage',
        'integrations.manage', 'voice.use', 'voice.manage', 'ai.use', 'ai.view', 'ai.manage', 'ocr.use',
        'system.audit', 'audit.view', 'reports.view', 'reports.users', 'reports.team',
        'reports.documents', 'reports.workflows', 'reports.ai', 'reports.resources',
        'users.view', 'users.create', 'users.edit', 'users.delete', 'users.suspend',
        'documents.view', 'documents.create', 'documents.edit', 'documents.delete',
        'documents.export', 'documents.share', 'projects.create',
        'support.manage', 'store.manage', 'organization.manage', 'team.view', 'team.manage',
        'workflows.manage', 'workflows.create', 'workflows.edit', 'workflows.delete', 'workflows.run', 'workflows.audit',
        'automation.manage', 'automation.run', 'automation.audit',
    ];

    private function ensurePlatformAdmin(Request $request): void
    {
        abort_unless($request->user()?->isAdmin(), 403, 'مدیریت ساختار نقش‌ها فقط در اختیار مدیر اصلی سامانه است.');
        foreach (['farast_roles', 'farast_role_permissions', 'farast_user_roles', 'farast_user_permission_overrides', 'farast_access_audit_logs'] as $table) {
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
            'rolePermissions' => DB::table('farast_role_permissions')->get(),
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
        $base = mb_substr(Str::slug($data['name']) ?: 'custom-role', 0, 90);
        // Random suffix avoids concurrent slug collisions without a check-then-insert race.
        $key = 'custom-'.$base.'-'.Str::lower(Str::random(8));

        DB::transaction(function () use ($request, $data, $scopeId, $key): void {
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

            $this->audit($request, 'role.created', null, $roleId, null, null, [
                'key' => $key, 'name' => $data['name'], 'scope' => $data['scope_type'], 'scope_id' => $scopeId,
            ]);
        });

        return back()->with('status', 'نقش سفارشی ایجاد شد؛ اکنون مجوزهای آن را تعیین کنید.');
    }
    public function updateRole(Request $request, int $role)
    {
        $this->ensurePlatformAdmin($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'scope_type' => ['required', Rule::in(self::SCOPES)],
            'scope_id' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['required', 'boolean'],
        ]);
        $scopeId = $this->validatedScopeId($data['scope_type'], $data['scope_id'] ?? null);

        DB::transaction(function () use ($request, $role, $data, $scopeId): void {
            $record = DB::table('farast_roles')->where('id', $role)->lockForUpdate()->first();
            abort_unless($record && ! $record->is_system, 404, 'نقش سیستمی قابل ویرایش نیست.');

            $hasAssignments = DB::table('farast_user_roles')->where('role_id', $role)->exists();
            $scopeChanged = $record->scope_type !== $data['scope_type']
                || ($record->scope_id === null ? null : (int) $record->scope_id) !== $scopeId;
            abort_if(
                $hasAssignments && $scopeChanged,
                409,
                'برای تغییر محدوده نقش، ابتدا همه انتساب‌های فعلی و تاریخی آن را لغو یا آرشیو کنید.'
            );

            DB::table('farast_roles')->where('id', $role)->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'scope_type' => $data['scope_type'],
                'scope_id' => $scopeId,
                'is_active' => (bool) $data['is_active'],
                'updated_at' => now(),
            ]);

            $this->audit($request, 'role.updated', null, $role, null, (array) $record, [
                ...$data,
                'scope_id' => $scopeId,
            ]);
        });

        app(AuthorizationService::class)->forgetUser();

        return back()->with('status', 'تنظیمات نقش ذخیره شد.');
    }
    public function destroyRole(Request $request, int $role)
    {
        $this->ensurePlatformAdmin($request);

        DB::transaction(function () use ($request, $role): void {
            $record = DB::table('farast_roles')->where('id', $role)->lockForUpdate()->first();
            abort_unless($record && ! $record->is_system, 404, 'نقش سیستمی قابل حذف نیست.');
            abort_if(
                DB::table('farast_user_roles')->where('role_id', $role)->exists(),
                409,
                'این نقش هنوز به کاربران اختصاص دارد؛ ابتدا همه انتساب‌های آن را لغو کنید.'
            );

            DB::table('farast_role_permissions')->where('role_id', $role)->delete();
            DB::table('farast_roles')->where('id', $role)->delete();
            $this->audit($request, 'role.deleted', null, $role, null, (array) $record, null);
        });

        app(AuthorizationService::class)->forgetUser();

        return back()->with('status', 'نقش سفارشی حذف شد.');
    }
    public function syncPermissions(Request $request, int $role)
    {
        $this->ensurePlatformAdmin($request);
        $data = $request->validate([
            'permissions' => ['sometimes', 'array', 'max:250'],
            'permissions.*' => ['required', 'string', 'distinct', 'exists:permissions,key'],
            'scope_type' => ['nullable', Rule::in(self::SCOPES)],
        ]);
        $keys = array_values(array_unique($data['permissions'] ?? []));

        DB::transaction(function () use ($request, $role, $data, $keys): void {
            $record = DB::table('farast_roles')->where('id', $role)->lockForUpdate()->first();
            abort_unless($record && ! $record->is_system, 404, 'نقش سیستمی قابل ویرایش نیست.');

            $scope = $data['scope_type'] ?? $record->scope_type;
            $before = DB::table('farast_role_permissions as rp')
                ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
                ->where('rp.role_id', $role)
                ->orderBy('p.key')
                ->pluck('p.key')
                ->all();
            $permissionIds = Permission::query()->whereIn('key', $keys)->pluck('id')->all();

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

            $this->audit($request, 'role.permissions.synced', null, $role, null, [
                'permissions' => $before,
            ], [
                'permissions' => $keys,
                'scope' => $scope,
            ]);
        });

        app(AuthorizationService::class)->forgetUser();

        return back()->with('status', 'مجوزهای نقش به‌روزرسانی شد.');
    }
    public function assignRole(Request $request, int $user)
    {
        $this->ensurePlatformAdmin($request);
        $data = $request->validate([
            'role_id' => ['required', 'integer', 'exists:farast_roles,id'],
            'scope_type' => ['required', Rule::in(self::SCOPES)],
            'scope_id' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:starts_at'],
        ]);

        $targetId = (int) $user;
        $assignmentId = DB::transaction(function () use ($request, $targetId, $data): int {
            // Lock the subject row to serialize concurrent assignments for this account.
            $target = User::query()->whereKey($targetId)->lockForUpdate()->firstOrFail();
            abort_if($target->isAdmin(), 422, 'نقش مدیر اصلی از این مسیر قابل انتساب نیست.');
            abort_if($target->is_blocked, 422, 'به حساب مسدود نمی‌توان نقش اختصاص داد.');

            $role = DB::table('farast_roles')->where('id', $data['role_id'])->lockForUpdate()->first();
            abort_unless($role && $role->is_active && ! $role->is_system, 422, 'فقط نقش سفارشی فعال قابل انتساب است.');
            abort_if(
                self::SCOPE_RANK[$data['scope_type']] < self::SCOPE_RANK[$role->scope_type],
                422,
                'محدوده انتساب نمی‌تواند از محدوده تعریف‌شده نقش گسترده‌تر باشد.'
            );

            $scopeId = $this->validatedScopeId($data['scope_type'], $data['scope_id'] ?? null);
            $this->assertRoleAssignmentWithinRoleScope($target, $role, $data['scope_type'], $scopeId);
            $this->assertUserBelongsToScope($target, $data['scope_type'], $scopeId);

            $existing = DB::table('farast_user_roles')->where('user_id', $target->id)
                ->where('role_id', $role->id)->where('scope_type', $data['scope_type'])
                ->where('scope_id', $scopeId)->where(function ($query) {
                    $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })->exists();
            abort_if($existing, 409, 'این نقش در همین محدوده قبلاً به کاربر اختصاص یافته است.');

            $id = DB::table('farast_user_roles')->insertGetId([
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

            $this->audit($request, 'user.role.assigned', $target->id, $role->id, null, null, [
                'assignment_id' => $id,
                'scope' => $data['scope_type'],
                'scope_id' => $scopeId,
                'starts_at' => $data['starts_at'] ?? null,
                'expires_at' => $data['expires_at'] ?? null,
            ]);

            return $id;
        });

        app(AuthorizationService::class)->forgetUser($targetId);

        return back()->with('status', 'نقش با محدوده و زمان اعتبار مشخص به کاربر اختصاص یافت.');
    }
    public function revokeRole(Request $request, int $user, int $assignment)
    {
        $this->ensurePlatformAdmin($request);
        $target = User::query()->findOrFail($user);

        DB::transaction(function () use ($request, $target, $assignment): void {
            $record = DB::table('farast_user_roles')
                ->where('id', $assignment)
                ->where('user_id', $target->id)
                ->lockForUpdate()
                ->first();
            abort_unless($record, 404);

            DB::table('farast_user_roles')->where('id', $assignment)->delete();
            $this->audit($request, 'user.role.revoked', $target->id, (int) $record->role_id, null, (array) $record, null);
        });

        app(AuthorizationService::class)->forgetUser($target->id);

        return back()->with('status', 'نقش از کاربر لغو شد.');
    }
    public function storeOverride(Request $request, int $user)
    {
        $this->ensurePlatformAdmin($request);
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

        $targetId = (int) $user;
        $permissionId = (int) Permission::query()->where('key', $data['permission'])->value('id');
        $overrideId = DB::transaction(function () use ($request, $targetId, $data, $permissionId): int {
            $target = User::query()->whereKey($targetId)->lockForUpdate()->firstOrFail();
            abort_if($target->isAdmin(), 422, 'برای مدیر اصلی مجوز مستقیم تعریف نمی‌شود.');
            abort_if($target->is_blocked, 422, 'برای حساب مسدود مجوز مستقیم تعریف نمی‌شود.');

            $scopeId = $this->validatedScopeId($data['scope_type'], $data['scope_id'] ?? null);
            $this->assertUserBelongsToScope($target, $data['scope_type'], $scopeId);

            $id = DB::table('farast_user_permission_overrides')->insertGetId([
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

            $this->audit($request, 'user.permission.override.created', $target->id, null, $permissionId, null, [
                'override_id' => $id,
                'effect' => $data['effect'],
                'scope' => $data['scope_type'],
                'scope_id' => $scopeId,
                'reason' => $data['reason'],
                'starts_at' => $data['starts_at'] ?? null,
                'expires_at' => $data['expires_at'] ?? null,
            ]);

            return $id;
        });

        app(AuthorizationService::class)->forgetUser($targetId);

        return back()->with('status', 'استثنای دسترسی کاربر ثبت شد.');
    }
    public function revokeOverride(Request $request, int $user, int $override)
    {
        $this->ensurePlatformAdmin($request);
        $target = User::query()->findOrFail($user);

        DB::transaction(function () use ($request, $target, $override): void {
            $record = DB::table('farast_user_permission_overrides')
                ->where('id', $override)
                ->where('user_id', $target->id)
                ->lockForUpdate()
                ->first();
            abort_unless($record, 404);

            DB::table('farast_user_permission_overrides')->where('id', $override)->delete();
            $this->audit($request, 'user.permission.override.revoked', $target->id, null, (int) $record->permission_id, (array) $record, null);
        });

        app(AuthorizationService::class)->forgetUser($target->id);

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

    /**
     * Ensure the selected assignment is contained by the role's declared scope.
     * A narrower assignment is valid only when the target and its referenced
     * team actually belong to the parent role scope.
     */
    private function assertRoleAssignmentWithinRoleScope(User $target, object $role, string $assignmentScope, ?int $assignmentScopeId): void
    {
        if ($role->scope_type === 'global') {
            return;
        }

        if ($role->scope_type === 'organization') {
            $organizationId = (int) $role->scope_id;
            abort_unless($target->organization_id && (int) $target->organization_id === $organizationId, 422, 'کاربر عضو سازمان محدوده نقش نیست.');

            if ($assignmentScope === 'organization') {
                abort_unless($assignmentScopeId === $organizationId, 422, 'محدوده انتساب باید با سازمان نقش یکسان باشد.');
            } elseif ($assignmentScope === 'team') {
                abort_unless(
                    $assignmentScopeId !== null
                    && Team::query()->whereKey($assignmentScopeId)->where('organization_id', $organizationId)->exists(),
                    422,
                    'تیم انتخاب‌شده خارج از سازمان تعریف‌شده برای نقش است.'
                );
            }

            return;
        }

        if ($role->scope_type === 'team') {
            $teamId = (int) $role->scope_id;
            $belongs = $target->teams()->where('teams.id', $teamId)->exists()
                || Team::query()->whereKey($teamId)->where('supervisor_id', $target->id)->exists();
            abort_unless($belongs, 422, 'کاربر عضو تیم محدوده نقش نیست.');

            if ($assignmentScope === 'team') {
                abort_unless($assignmentScopeId === $teamId, 422, 'محدوده انتساب باید با تیم تعریف‌شده برای نقش یکسان باشد.');
            }

            return;
        }

        abort_unless($role->scope_type === 'own' && $assignmentScope === 'own', 422, 'محدوده انتساب با نقش سازگار نیست.');
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
