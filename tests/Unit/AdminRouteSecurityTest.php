<?php

namespace Tests\Unit;

use App\Http\Controllers\PostController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminRouteSecurityTest extends TestCase
{
    public function test_admin_ajax_read_routes_require_login()
    {
        $this->assertRouteHasMiddleware('fetchAllChartData', 'checkLogin');
        $this->assertRouteHasMiddleware('fetchCommentsInReelModal', 'checkLogin');
        $this->assertRouteHasMiddleware('moderationAuditLogs', 'checkLogin');
    }

    public function test_admin_destructive_room_route_requires_super_admin()
    {
        $this->assertRouteHasMiddleware('deleteThisRoom', 'checkLogin');
        $this->assertRouteHasMiddleware('deleteThisRoom', 'superAdmin');
    }

    public function test_story_cleanup_endpoint_rejects_missing_token()
    {
        $controller = new PostController();
        $request = Request::create('/deleteStoryFromWeb', 'GET');

        $response = $controller->deleteStoryFromWeb($request);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Unauthorized Access', $response->getData(true)['message']);
    }

    public function test_story_cleanup_endpoint_is_rate_limited()
    {
        $this->assertRouteHasMiddleware('deleteStoryFromWeb', 'throttle:6,1');
    }

    private function assertRouteHasMiddleware(string $routeName, string $middleware): void
    {
        $route = Route::getRoutes()->getByName($routeName);

        $this->assertNotNull($route, "Route [{$routeName}] should exist.");
        $this->assertContains($middleware, $route->gatherMiddleware());
    }
}
