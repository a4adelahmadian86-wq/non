<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Tests\TestCase;

class PerformanceRateLimitCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_polling_and_mutations_have_explicit_rate_limits(): void
    {
        $this->assertRouteThrottle('platform.notifications.visit', 'throttle:60,1');
        $this->assertRouteThrottle('platform.notifications.readAll', 'throttle:30,1');
        $this->assertRouteThrottle('platform.notifications.read', 'throttle:60,1');
        $this->assertRouteThrottle('platform.notifications.archive', 'throttle:60,1');
        $this->assertRouteThrottle('platform.notifications.restore', 'throttle:60,1');
        $this->assertRouteThrottle('notifications.unread', 'throttle:120,1');
        $this->assertRouteThrottle('notifications.latest', 'throttle:120,1');
    }

    public function test_expensive_support_and_admin_store_routes_have_rate_limits(): void
    {
        $this->assertRouteThrottle('admin.support.report', 'throttle:60,1');
        $this->assertRouteThrottle('support.csat', 'throttle:5,10');
        $this->assertRouteThrottle('admin.store.update', 'throttle:120,1');
        $this->assertRouteThrottle('admin.store.course.sections.store', 'throttle:120,1');
        $this->assertRouteThrottle('admin.store.tags.store', 'throttle:120,1');
        $this->assertRouteMiddleware('admin.store.update', 'admin.section:store');
        $this->assertRouteMiddleware('admin.support.report', 'admin.section:support_admin');
    }

    public function test_notification_polling_is_actually_limited_after_the_configured_window_budget(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        for ($request = 0; $request < 120; $request++) {
            $this->getJson(route('notifications.unread'))->assertOk();
        }

        $this->getJson(route('notifications.unread'))->assertTooManyRequests();
    }

    private function assertRouteMiddleware(string $name, string $expected): void
    {
        $route = app('router')->getRoutes()->getByName($name);

        $this->assertInstanceOf(RoutingRoute::class, $route, 'Missing route: '.$name);
        $this->assertContains($expected, $route->middleware(), 'Missing '.$expected.' on '.$name);
    }

    private function assertRouteThrottle(string $name, string $expected): void
    {
        $route = app('router')->getRoutes()->getByName($name);

        $this->assertInstanceOf(RoutingRoute::class, $route, 'Missing route: '.$name);
        $this->assertContains($expected, $route->middleware(), 'Missing '.$expected.' on '.$name);
    }
}
