<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiUploadRouteSizePolicyTest extends TestCase
{
    public function test_upload_routes_have_explicit_request_size_limits(): void
    {
        $routes = [
            'testupload' => 'requestSizeLimit:10',
            'editProfile' => 'requestSizeLimit:16',
            'uploadReel' => 'requestSizeLimit:120',
            'profileVerification' => 'requestSizeLimit:20',
            'addPost' => 'requestSizeLimit:80',
            'createStory' => 'requestSizeLimit:60',
            'uploadFile' => 'requestSizeLimit:32',
            'createRoom' => 'requestSizeLimit:12',
            'editRoom' => 'requestSizeLimit:12',
            'Company/editProfile' => 'requestSizeLimit:8',
            'Company/createPost' => 'requestSizeLimit:80',
            'Application/applyToJob' => 'requestSizeLimit:8',
        ];

        foreach ($routes as $uri => $middleware) {
            $this->assertRouteHasMiddleware($uri, $middleware);
        }
    }

    public function test_upload_routes_keep_abuse_rate_limits_when_expected(): void
    {
        $routes = [
            'testupload',
            'uploadReel',
            'profileVerification',
            'addPost',
            'createStory',
            'uploadFile',
            'Company/createPost',
        ];

        foreach ($routes as $uri) {
            $this->assertRouteHasMiddleware($uri, 'throttle:uploads');
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
