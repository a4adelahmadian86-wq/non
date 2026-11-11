<?php

namespace Tests\Feature;

use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class NotificationRouteOwnershipTest extends TestCase
{
    public function test_notification_center_routes_have_one_canonical_controller_owner(): void
    {
        $index = Route::getRoutes()->getByName('platform.notifications');
        $read = Route::getRoutes()->getByName('platform.notifications.read');

        $this->assertNotNull($index);
        $this->assertNotNull($read);
        $this->assertSame(NotificationController::class.'@index', $index->getActionName());
        $this->assertSame(NotificationController::class.'@read', $read->getActionName());
    }

    public function test_runtime_route_names_are_unique(): void
    {
        $routes = Route::getRoutes()->getRoutes();
        $names = array_values(array_filter(array_map(
            static fn ($route) => $route->getName(),
            $routes
        )));

        $this->assertGreaterThan(300, count($routes));
        $this->assertCount(count($names), array_unique($names));
    }
}
