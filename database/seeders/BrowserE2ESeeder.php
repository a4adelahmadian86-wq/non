<?php

namespace Database\\Seeders;

use App\\Models\\User;
use Illuminate\\Database\\Seeder;
use RuntimeException;

class BrowserE2ESeeder extends Seeder
{
    public function run(): void
    {
        $password = (string) env('E2E_EMPLOYEE_PASSWORD', '');
        if ($password === '') {
            throw new RuntimeException('E2E_EMPLOYEE_PASSWORD must be set for browser role tests.');
        }

        User::updateOrCreate(
            ['mobile' => '09150000001'],
            [
                'name' => 'کارمند آزمون رابط',
                'password' => $password,
                'role' => User::ROLE_EMPLOYEE,
                'is_verified' => true,
                'is_blocked' => false,
            ]
        );
    }
}
