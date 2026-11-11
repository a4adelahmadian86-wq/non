<?php

namespace Tests\\Feature;

use App\\Http\\Controllers\\NotificationController;
use Illuminate\\Support\\Facades\\Route;
use Tests\\TestCase;

class NotificationRouteOwnershipTest extends TestCase
{
    public function test_all_notification_endpoints_have_one_canonical_controller_owner(): void
    {
        $expected = [
            'platform.notifications' => 'index',
            'platform.notifications.visit' => 'visit',
            'platform.notifications.readAll' => 'readAll',
            'platform.notifications.read' => 'read',
            'platform.notifications.archive' => 'archive',
            'platform.notifications.restore' => 'restore',
            'notifications.unread' => 'unreadCount',
            'notifications.latest' => 'latest',
        ];

        foreach ($expected as $routeName => $method) {
            $route = Route::getRoutes()->getByName($routeName);

            $this->assertNotNull($route, "Missing notification route [{$routeName}].");
            $this->assertSame(
                NotificationController::class.'@'.$method,
                $route->getActionName(),
                "Notification route [{$routeName}] has a non-canonical action."
            );
        }
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
