<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ReelRouteAuthPolicyTest extends TestCase
{
    public function test_reel_sensitive_write_routes_require_authorize_user(): void
    {
        $sensitiveRoutes = [
            'uploadReel',
            'likeDislikeReel',
            'addReelComment',
            'editReelComment',
            'deleteReelComment',
            'likeDislikeReelComment',
            'deleteReel',
            'reportReel',
        ];

        foreach ($sensitiveRoutes as $uri) {
            $this->assertRouteHasMiddleware($uri, 'checkHeader');
            $this->assertRouteHasMiddleware($uri, 'authorizeUser');
        }
    }

    public function test_reel_write_routes_keep_rate_limits_when_expected(): void
    {
        $writeThrottledRoutes = [
            'uploadReel' => 'throttle:uploads',
            'likeDislikeReel' => 'throttle:writes',
            'addReelComment' => 'throttle:writes',
            'editReelComment' => 'throttle:writes',
            'deleteReelComment' => 'throttle:writes',
            'likeDislikeReelComment' => 'throttle:writes',
            'deleteReel' => 'throttle:writes',
            'reportReel' => 'throttle:writes',
        ];

        foreach ($writeThrottledRoutes as $uri => $throttleMiddleware) {
            $this->assertRouteHasMiddleware($uri, $throttleMiddleware);
        }
    }

    public function test_reel_engagement_routes_keep_engagement_rate_limits(): void
    {
        $this->assertRouteHasMiddleware('increaseReelViewCount', 'checkHeader');
        $this->assertRouteHasMiddleware('increaseReelViewCount', 'throttle:engagement');
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
