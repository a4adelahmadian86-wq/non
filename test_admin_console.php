<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;

// --- seed users for every role if missing ---
$roles = [
    User::ROLE_ADMIN => 'Root Admin',
    User::ROLE_ORGANIZATION_MANAGER => 'Org Manager',
    User::ROLE_TEAM_SUPERVISOR => 'Team Supervisor',
    User::ROLE_EMPLOYEE => 'Employee',
];

foreach ($roles as $role => $name) {
    if (! User::where('role', $role)->exists()) {
        $u = new User([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $role)).'@farast.local',
            'mobile' => '09'.random_int(100000000, 999999999),
            'role' => $role,
        ]);
        $u->password = bcrypt('password');
        $u->save();
        echo "created {$role} user #{$u->id}\n";
    }
}

function fetchPage(string $uri, User $user): array
{
    $app = app();
    Auth::login($user);
    $request = Request::create($uri, 'GET');
    $request->setLaravelSession($app['session']->driver());
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    try {
        $response = $kernel->handle($request);
        return [$response->getStatusCode(), strlen($response->getContent())];
    } catch (\Throwable $e) {
        return ['EXC: '.get_class($e).': '.$e->getMessage(), 0];
    }
}

echo "=== TEST 1: admin dashboard (global scope) ===\n";
$admin = User::where('role', User::ROLE_ADMIN)->first();
[$code, $len] = fetchPage('/admin', $admin);
echo "GET /admin -> {$code} ({$len} bytes)\n";

echo "\n=== TEST 2: users directory ===\n";
[$code, $len] = fetchPage('/admin/users', $admin);
echo "GET /admin/users -> {$code} ({$len} bytes)\n";

echo "\n=== TEST 3: finance ===\n";
[$code, $len] = fetchPage('/admin/finance', $admin);
echo "GET /admin/finance -> {$code} ({$len} bytes)\n";

echo "\n=== TEST 4: org manager sees restricted dashboard ===\n";
$org = User::where('role', User::ROLE_ORGANIZATION_MANAGER)->first();
[$code, $len] = fetchPage('/admin', $org);
echo "GET /admin -> {$code} ({$len} bytes)\n";

echo "\n=== TEST 5: employee (own scope) ===\n";
$emp = User::where('role', User::ROLE_EMPLOYEE)->first();
[$code, $len] = fetchPage('/admin', $emp);
echo "GET /admin -> {$code} ({$len} bytes)\n";

echo "\n=== TEST 6: audit page ===\n";
[$code, $len] = fetchPage('/admin/audit', $admin);
echo "GET /admin/audit -> {$code} ({$len} bytes)\n";

echo "\nDONE\n";
