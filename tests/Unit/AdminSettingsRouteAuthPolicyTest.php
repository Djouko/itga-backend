<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminSettingsRouteAuthPolicyTest extends TestCase
{
    public function test_admin_settings_write_routes_require_super_admin(): void
    {
        $protectedRoutes = [
            'setting',
            'updateSettings',
            'androidDeepLinking',
            'iOSDeepLinking',
            'admobAndroid',
            'admobiOS',
        ];

        foreach ($protectedRoutes as $uri) {
            $this->assertRouteHasMiddleware($uri, 'POST', 'checkLogin');
            $this->assertRouteHasMiddleware($uri, 'POST', 'superAdmin');
        }
    }

    public function test_admin_settings_read_route_keeps_read_only_access(): void
    {
        $route = $this->findRoute('setting', 'GET');

        $this->assertNotNull($route, 'Route [GET setting] should exist.');
        $this->assertContains('checkLogin', $route->gatherMiddleware());
        $this->assertNotContains('superAdmin', $route->gatherMiddleware());
    }

    private function assertRouteHasMiddleware(string $uri, string $method, string $middleware): void
    {
        $route = $this->findRoute($uri, $method);

        $this->assertNotNull($route, "Route [{$method} {$uri}] should exist.");
        $this->assertContains(
            $middleware,
            $route->gatherMiddleware(),
            "Route [{$method} {$uri}] must include middleware [{$middleware}]."
        );
    }

    private function findRoute(string $uri, string $method)
    {
        return collect(Route::getRoutes()->getRoutes())->first(function ($route) use ($uri, $method) {
            return trim($route->uri(), '/') === trim($uri, '/')
                && in_array(strtoupper($method), $route->methods(), true);
        });
    }
}
