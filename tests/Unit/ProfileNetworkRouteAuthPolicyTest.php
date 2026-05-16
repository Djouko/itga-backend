<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ProfileNetworkRouteAuthPolicyTest extends TestCase
{
    public function test_profile_and_network_actor_routes_require_authorize_user(): void
    {
        $this->assertRouteHasMiddleware('editProfile', 'checkHeader');
        $this->assertRouteHasMiddleware('editProfile', 'authorizeUser');

        $this->assertRouteHasMiddleware('followUser', 'checkHeader');
        $this->assertRouteHasMiddleware('followUser', 'authorizeUser');

        $this->assertRouteHasMiddleware('fetchFollowingList', 'checkHeader');
        $this->assertRouteHasMiddleware('fetchFollowingList', 'authorizeUser');

        $this->assertRouteHasMiddleware('unfollowUser', 'checkHeader');
        $this->assertRouteHasMiddleware('unfollowUser', 'authorizeUser');

        $this->assertRouteHasMiddleware('fetchRandomProfile', 'checkHeader');
        $this->assertRouteHasMiddleware('fetchRandomProfile', 'authorizeUser');

        $this->assertRouteHasMiddleware('fetchProfile', 'checkHeader');
        $this->assertRouteHasMiddleware('fetchProfile', 'authorizeUser');

        $this->assertRouteHasMiddleware('searchProfile', 'checkHeader');
        $this->assertRouteHasMiddleware('searchProfile', 'authorizeUser');
    }

    public function test_profile_and_network_mutations_keep_rate_limits(): void
    {
        $this->assertRouteHasMiddleware('editProfile', 'throttle:uploads');
        $this->assertRouteHasMiddleware('followUser', 'throttle:writes');
        $this->assertRouteHasMiddleware('unfollowUser', 'throttle:writes');
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
