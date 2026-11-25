<?php

namespace App\\Http\\Controllers;

use App\\Models\\User;
use App\\Services\\StaffLifecycleService;
use Illuminate\\Http\\JsonResponse;
use Illuminate\\Http\\Request;

class StaffLifecycleController extends Controller
{
    public function __construct(private readonly StaffLifecycleService $staff) {}

    public function primary(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'role_key' => ['required', 'string', 'max:100'],
            'title' => ['required', 'string', 'max:160'],
            'scope' => ['required', 'in:global,organization,team,own'],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
        ]);
        $assignment = $this->staff->setPrimaryResponsibility($request->user(), $user, $data['role_key'], $data['title'], $data['scope'], $data['organization_id'] ?? null, $data['team_id'] ?? null);
        return response()->json(['assignment' => $assignment], 201);
    }

    public function secondary(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'role_key' => ['required', 'string', 'max:100'],
            'title' => ['required', 'string', 'max:160'],
            'scope' => ['required', 'in:global,organization,team,own'],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
        ]);
        $assignment = $this->staff->addSecondaryResponsibility($request->user(), $user, $data['role_key'], $data['title'], $data['scope'], $data['organization_id'] ?? null, $data['team_id'] ?? null);
        return response()->json(['assignment' => $assignment], 201);
    }

    public function endResponsibility(Request $request, int $assignment): JsonResponse
    {
        $this->staff->endResponsibility($request->user(), $assignment);
        return response()->json(['ended' => true]);
    }

    public function department(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'member' => ['required', 'boolean'],
        ]);
        $this->staff->setDepartmentMembership($request->user(), $user, (int) $data['department_id'], (bool) $data['member']);
        return response()->json(['updated' => true]);
    }

    public function dismiss(Request $request, User $user): JsonResponse
    {
        $this->staff->dismissToCustomer($request->user(), $user);
        return response()->json(['dismissed' => true, 'customer_access' => true]);
    }
}
