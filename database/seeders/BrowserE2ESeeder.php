<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class BrowserE2ESeeder extends Seeder
{
    public function run(): void
    {
        $password = (string) env('E2E_EMPLOYEE_PASSWORD', '');
        if ($password === '') {
            throw new RuntimeException('E2E_EMPLOYEE_PASSWORD must be set for browser role tests.');
        }

        $organization = Organization::updateOrCreate(
            ['slug' => 'farast-browser-e2e'],
            ['name' => 'سازمان آزمون رابط فراست', 'status' => 'active']
        );

        $accounts = [
            ['09150000001', 'کارمند آزمون رابط', User::ROLE_EMPLOYEE],
            ['09150000002', 'مدیر سازمان آزمون رابط', User::ROLE_ORGANIZATION_MANAGER],
            ['09150000003', 'سرپرست تیم آزمون رابط', User::ROLE_TEAM_SUPERVISOR],
        ];

        foreach ($accounts as [$mobile, $name, $role]) {
            User::updateOrCreate(
                ['mobile' => $mobile],
                [
                    'name' => $name,
                    'password' => $password,
                    'role' => $role,
                    'organization_id' => $organization->id,
                    'is_verified' => true,
                    'is_blocked' => false,
                ]
            );
        }
    }
}
