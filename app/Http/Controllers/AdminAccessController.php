<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AdminAccessController extends Controller
{
    public function __construct(private readonly AuthorizationService $authorization)
    {
    }

    public function index(): JsonResponse
    {
        $permissionsByRole = DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->select('role_permissions.role', 'permissions.key')
            ->get()
            ->groupBy('role')
            ->map(fn ($rows) => $rows->pluck('key')->values());

        $roles = DB::table('roles')->orderBy('key')->get()->map(function (object $role) use ($permissionsByRole): array {
            return [
                'id' => $role->id,
                'key' => $role->key,
                'name' => $role->name,
                'is_system' => (bool) $role->is_system,
                'is_active' => (bool) $role->is_active,
                'permissions' => $permissionsByRole->get($role->key, collect())->all(),
            ];
        });

        return response()->json([
            'roles' => $roles,
            'permissions' => Permission::query()->orderBy('group')->orderBy('key')->get(['key', 'label', 'group']),
            'scopes' => AuthorizationService::SCOPES,
        ]);
    }

    public function storeRole(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:160'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'exists:permissions,key'],
        ]);

        $role = $this->authorization->createRole(
            $request->user(),
            $data['key'],
            $data['name'],
            $data['permissions'] ?? [],
            $request,
        );

        return response()->json(['role' => $role], 201);
    }

    public function assignRole(Request $request, int $user): JsonResponse
    {
        $data = $request->validate([
            'role_key' => ['required', 'string', 'exists:roles,key'],
            'scope' => ['required', 'string', 'in:global,organization,team,own'],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:starts_at'],
        ]);

        $assignment = $this->authorization->assignRole(
            $request->user(),
            User::query()->findOrFail($user),
            $data['role_key'],
            $data['scope'],
            isset($data['organization_id']) ? (int) $data['organization_id'] : null,
            isset($data['team_id']) ? (int) $data['team_id'] : null,
            isset($data['starts_at']) ? Carbon::parse($data['starts_at']) : null,
            isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null,
            $request,
        );

        return response()->json(['assignment' => $assignment], 201);
    }

    public function revokeRole(Request $request, int $assignment): JsonResponse
    {
        $this->authorization->revokeRoleAssignment($request->user(), $assignment, $request);

        return response()->json(['revoked' => true]);
    }

    public function grantPermission(Request $request, int $user): JsonResponse
    {
        $data = $request->validate([
            'permission' => ['required', 'string', 'exists:permissions,key'],
            'effect' => ['required', 'string', 'in:allow,deny'],
            'scope' => ['required', 'string', 'in:global,organization,team,own'],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:starts_at'],
        ]);

        $grant = $this->authorization->grantDirectPermission(
            $request->user(),
            User::query()->findOrFail($user),
            $data['permission'],
            $data['effect'],
            $data['scope'],
            isset($data['organization_id']) ? (int) $data['organization_id'] : null,
            isset($data['team_id']) ? (int) $data['team_id'] : null,
            isset($data['starts_at']) ? Carbon::parse($data['starts_at']) : null,
            isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null,
            $request,
        );

        return response()->json(['grant' => $grant], 201);
    }

    public function revokePermission(Request $request, int $grant): JsonResponse
    {
        $this->authorization->revokeDirectPermission($request->user(), $grant, $request);

        return response()->json(['revoked' => true]);
    }
}
