<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminModerationRouteAuthPolicyTest extends TestCase
{
    public function test_admin_moderation_routes_require_admin_headers_and_throttle(): void
    {
        $protectedRoutes = [
            'AdminModeration/fetchAuditLogs',
            'AdminModeration/exportAuditLogs',
            'AdminModeration/fetchAuditAppeals',
            'AdminModeration/reviewAuditAppeal',
        ];

        foreach ($protectedRoutes as $uri) {
            $this->assertRouteHasMiddleware($uri, 'checkHeader');
            $this->assertRouteHasMiddleware($uri, 'checkAdminHeader');
            $this->assertRouteHasMiddleware($uri, 'throttle:adminWrites');
        }
    }

    public function test_admin_job_routes_require_admin_headers_and_throttle(): void
    {
        $protectedRoutes = [
            'AdminJob/fetchAllJobs',
            'AdminJob/moderateJob',
            'AdminJob/fetchCompanies',
            'AdminJob/toggleSuspendCompany',
            'AdminJob/fetchJobKPIs',
        ];

        foreach ($protectedRoutes as $uri) {
            $this->assertRouteHasMiddleware($uri, 'checkHeader');
            $this->assertRouteHasMiddleware($uri, 'checkAdminHeader');
            $this->assertRouteHasMiddleware($uri, 'throttle:adminWrites');
        }
    }

    private function assertRouteHasMiddleware(string $uri, string $middleware): void
    {
        $route = collect(Route::getRoutes()->getRoutes())->first(function ($route) use ($uri) {
            return trim($route->uri(), '/') === trim('api/' . $uri, '/');
        });

        $this->assertNotNull($route, "Route [{$uri}] should exist.");
        $this->assertContains($middleware, $route->gatherMiddleware(), "Route [{$uri}] must include middleware [{$middleware}].");
    }
}
