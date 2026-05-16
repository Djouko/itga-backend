<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class UserSensitiveRouteAuthPolicyTest extends TestCase
{
    public function test_sensitive_user_routes_require_authorize_user(): void
    {
        $uris = [
            'deleteUser',
            'fetchBlockedUserList',
            'logOut',
            'UserBlockedByUser',
            'UserUnblockedByUser',
            'fetchUserNotification',
            'markNotificationsAsRead',
            'fetchUnreadNotificationCount',
            'profileVerification',
            'generateAgoraToken',
            'pushNotificationToSingleUser',
            'reportUser',
        ];

        foreach ($uris as $uri) {
            $this->assertRouteHasMiddleware($uri, 'checkHeader');
            $this->assertRouteHasMiddleware($uri, 'authorizeUser');
        }
    }

    public function test_sensitive_user_mutations_keep_rate_limits(): void
    {
        foreach ([
            'deleteUser',
            'logOut',
            'UserBlockedByUser',
            'UserUnblockedByUser',
            'markNotificationsAsRead',
            'generateAgoraToken',
            'pushNotificationToSingleUser',
            'reportUser',
        ] as $uri) {
            $this->assertRouteHasMiddleware($uri, 'throttle:writes');
        }

        $this->assertRouteHasMiddleware('profileVerification', 'throttle:uploads');
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
