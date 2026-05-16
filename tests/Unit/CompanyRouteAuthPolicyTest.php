<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CompanyRouteAuthPolicyTest extends TestCase
{
    public function test_company_private_routes_require_authorize_user(): void
    {
        foreach ([
            'Company/editProfile',
            'Company/fetchProfile',
            'Company/fetchDashboard',
            'Company/createPost',
            'Company/followCompany',
            'Company/unfollowCompany',
            'Company/fetchFollowedCompanies',
        ] as $uri) {
            $this->assertRouteHasMiddleware($uri, 'checkHeader');
            $this->assertRouteHasMiddleware($uri, 'authorizeUser');
        }
    }

    public function test_company_public_profile_stays_open_with_api_key_only(): void
    {
        $this->assertRouteHasMiddleware('Company/publicProfile', 'checkHeader');
        $route = collect(Route::getRoutes()->getRoutes())->first(function ($route) {
            return trim($route->uri(), '/') === trim('api/Company/publicProfile', '/');
        });
        $this->assertNotNull($route);
        $this->assertNotContains('authorizeUser', $route->gatherMiddleware());
    }

    public function test_company_mutation_routes_keep_rate_limits(): void
    {
        $this->assertRouteHasMiddleware('Company/editProfile', 'throttle:uploads');
        $this->assertRouteHasMiddleware('Company/createPost', 'throttle:uploads');
        $this->assertRouteHasMiddleware('Company/followCompany', 'throttle:writes');
        $this->assertRouteHasMiddleware('Company/unfollowCompany', 'throttle:writes');
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
